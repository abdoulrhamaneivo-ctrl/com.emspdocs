<?php
ob_start();
include_once __DIR__ . '/includes/bootstrap.php';
include_once __DIR__ . '/admin/config/dbcon.php';
include_once __DIR__ . '/includes/csrf.php';
include_once __DIR__ . '/includes/brevo.php';
include_once __DIR__ . '/includes/flash.php';
include_once __DIR__ . '/includes/rate_limit.php';

verify_csrf_token();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: register.php'); exit;
}

$ip = emsp_client_ip();

$rate = rate_limit_check($ip, $con);
if (!empty($rate['blocked'])) {
    $retry_in = (int) ($rate['retry_in'] ?? 0);
    if ($retry_in > 0) {
        $mins = (int) ceil($retry_in / 60);
        $wait = ($retry_in >= 60)
            ? ($mins . ' minute' . ($mins > 1 ? 's' : ''))
            : ($retry_in . ' seconde' . ($retry_in > 1 ? 's' : ''));
        $msg = 'Pour votre securite, reessayez dans ' . $wait . '.';
    } else {
        $msg = 'Pour votre securite, reessayez plus tard.';
    }
    flash_set('warning', 'Trop de tentatives', $msg);
    header('Location: register.php'); exit;
}

$first_name = trim($_POST['first_name'] ?? '');
$last_name  = trim($_POST['last_name'] ?? '');
$email      = trim($_POST['email'] ?? '');
$password   = (string)($_POST['password'] ?? '');
$password_confirm = (string)($_POST['password_confirm'] ?? '');
$registration_method = trim($_POST['registration_method'] ?? '');
$filiere_id = intval($_POST['filiere_id'] ?? 0);
$licence_id = intval($_POST['licence_id'] ?? 0);

$errors = [];
$old = [
    'first_name' => $first_name,
    'last_name' => $last_name,
    'email' => $email,
    'registration_method' => $registration_method,
    'filiere_id' => $filiere_id,
    'licence_id' => $licence_id,
];

if ($first_name === '') {
    $errors['first_name'] = "Le prÃ©nom est obligatoire.";
}
if ($last_name === '') {
    $errors['last_name'] = "Le nom est obligatoire.";
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = "Adresse email invalide. Ex : prenom.nom@emsp.int";
}
if (strlen($password) < 8) {
    $errors['password'] = "Le mot de passe doit contenir au moins 8 caractÃ¨res.";
}
if ($password !== $password_confirm) {
    $errors['password_confirm'] = "Les deux mots de passe ne correspondent pas.";
}
if (!in_array($registration_method, ['school_email','manual_card'], true)) {
    $errors['registration_method'] = "Choisissez d'abord une methode d'inscription.";
}
if ($filiere_id <= 0) {
    $errors['filiere_id'] = "Veuillez choisir une filiÃ¨re.";
}
if ($licence_id <= 0) {
    $errors['licence_id'] = "Veuillez choisir un niveau.";
}
if ($registration_method === 'school_email') {
    $domains = emsp_get_school_domains($con);
    if (!emsp_is_school_email($email, $domains)) {
        $hint = !empty($domains) ? implode(', ', $domains) : (defined('SCHOOL_EMAIL_DOMAIN') ? SCHOOL_EMAIL_DOMAIN : '');
        $errors['email'] = "Email ecole requis : utilisez une adresse finissant par " . htmlspecialchars($hint, ENT_QUOTES | ENT_HTML5) . ".";
    }
}

if (!empty($errors)) {
    rate_limit_record_failure($ip, $con);
    $_SESSION['form_errors'] = $errors;
    $_SESSION['form_old'] = $old;
    header('Location: register.php'); exit;
}

$s = mysqli_prepare($con, "SELECT COUNT(*) FROM filieres WHERE id=? AND status='active'");
mysqli_stmt_bind_param($s,'i',$filiere_id); mysqli_stmt_execute($s); mysqli_stmt_bind_result($s,$okF); mysqli_stmt_fetch($s); mysqli_stmt_close($s);
$s = mysqli_prepare($con, "SELECT COUNT(*) FROM licences WHERE id=? AND status='active'");
mysqli_stmt_bind_param($s,'i',$licence_id); mysqli_stmt_execute($s); mysqli_stmt_bind_result($s,$okL); mysqli_stmt_fetch($s); mysqli_stmt_close($s);
if (!$okF || !$okL) {
    if (!$okF) { $errors['filiere_id'] = "Cette filiÃ¨re n'est pas disponible."; }
    if (!$okL) { $errors['licence_id'] = "Ce niveau n'est pas disponible."; }
    $_SESSION['form_errors'] = $errors;
    $_SESSION['form_old'] = $old;
    header('Location: register.php'); exit;
}

$s = mysqli_prepare($con, "SELECT id FROM users WHERE email=? LIMIT 1");
mysqli_stmt_bind_param($s,'s',$email); mysqli_stmt_execute($s); mysqli_stmt_store_result($s);
if (mysqli_stmt_num_rows($s)>0){
    mysqli_stmt_close($s);
    $errors['email'] = "Cette adresse email est dÃ©jÃ  associÃ©e Ã  un compte. Essayez de vous <a href='login.php'>connecter</a> ou de <a href='forgot-password.php'>rÃ©initialiser votre mot de passe</a>.";
    $_SESSION['form_errors'] = $errors;
    $_SESSION['form_old'] = $old;
    header('Location: register.php'); exit;
}
mysqli_stmt_close($s);

$student_card_path = null;
if ($registration_method === 'manual_card') {
    if (empty($_FILES['student_card']['name'])) {
        $errors['student_card'] = "Carte Ã©tudiante obligatoire.";
    } else {
        $mime = emsp_detect_mime($_FILES['student_card']['tmp_name']);
        if (!in_array($mime, ['image/jpeg','image/png','application/pdf'], true)) {
            $errors['student_card'] = "Carte: JPG, PNG ou PDF uniquement.";
        }
        $maxUpload = emsp_max_upload_size(defined('MAX_UPLOAD_SIZE') ? MAX_UPLOAD_SIZE : (5 * 1024 * 1024));
        if (($_FILES['student_card']['size'] ?? 0) > $maxUpload) {
            $errors['student_card'] = "Carte trop lourde (max " . round($maxUpload / 1024 / 1024, 1) . " Mo).";
        }
    }
    if (!empty($errors)) {
        rate_limit_record_failure($ip, $con);
        $_SESSION['form_errors'] = $errors;
        $_SESSION['form_old'] = $old;
        header('Location: register.php'); exit;
    }

    $ext = strtolower(pathinfo($_FILES['student_card']['name'], PATHINFO_EXTENSION));
    $cardsDir = __DIR__ . '/uploads/student-cards';
    if (!is_dir($cardsDir)) {
        @mkdir($cardsDir, 0755, true);
    }
    $student_card_path = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = $cardsDir . '/' . $student_card_path;
    if (!move_uploaded_file($_FILES['student_card']['tmp_name'], $dest)) {
        $errors['student_card'] = "Erreur lors de l'envoi de la carte Ã©tudiante. RÃ©essayez.";
        $_SESSION['form_errors'] = $errors;
        $_SESSION['form_old'] = $old;
        header('Location: register.php'); exit;
    }
}

$status = 'pending';
$password_hash = password_hash($password, PASSWORD_DEFAULT);
$token = bin2hex(random_bytes(32));
$email_verified_at = null;
$status_updated_at = null;

$filiere_id = $filiere_id ?: null;
$licence_id = $licence_id ?: null;

$ins = mysqli_prepare($con,"INSERT INTO users
 (first_name,last_name,email,password_hash,role,status,
  registration_method,student_card_path,filiere_id,licence_id,verification_token,email_verified_at,status_updated_at)
 VALUES (?,?,?,?, 'etudiant', ?, ?, ?, ?, ?, ?, ?, ?)");
mysqli_stmt_bind_param($ins,'sssssssiisss',
    $first_name,$last_name,$email,$password_hash,
    $status,$registration_method,$student_card_path,$filiere_id,$licence_id,$token,$email_verified_at,$status_updated_at);

if (!mysqli_stmt_execute($ins)) {
    mysqli_stmt_close($ins);
    $_SESSION['form_old'] = $old;
    flash_set(
        'error',
        "Erreur lors de l'inscription",
        "Une erreur technique est survenue. RÃ©essayez dans quelques minutes."
    );
    header('Location: register.php'); exit;
}
$new_user_id = mysqli_insert_id($con);
mysqli_stmt_close($ins);

rate_limit_clear($ip, $con);

$sent = brevo_send_verification($new_user_id, $email, $first_name, $last_name, $token);
if (!$sent) {
    flash_set(
        'warning',
        "Inscription enregistrÃ©e, mais email non envoyÃ©",
        "Votre compte est crÃ©Ã©, mais l'email de confirmation n'a pas pu Ãªtre envoyÃ©. "
        . "Vous pourrez le renvoyer depuis la page de suivi."
    );
} else {
    if ($registration_method === 'school_email') {
        flash_set(
            'success',
            "Inscription rÃ©ussie ! ðŸŽ‰",
            "Un email de confirmation a Ã©tÃ© envoyÃ© Ã  " . $email . ". "
            . "Cliquez sur le lien dans cet email pour activer votre compte. "
            . "Vous ne le trouvez pas ? VÃ©rifiez vos spams ou courriers indÃ©sirables.",
            'login.php',
            'Aller Ã  la connexion'
        );
    } else {
        flash_set(
            'success',
            "Inscription rÃ©ussie ! ðŸŽ‰",
            "Un email de confirmation a Ã©tÃ© envoyÃ© Ã  " . $email . ". "
            . "AprÃ¨s confirmation, votre demande sera vÃ©rifiÃ©e par un administrateur. "
            . "VÃ©rifiez vos spams si vous ne trouvez pas l'email.",
            'login.php',
            'Aller Ã  la connexion'
        );
    }
}
header('Location: login.php'); exit;

/* ANCIEN FLUX (laisser inactif)
if ($status === 'pending') {
    $sent = brevo_send_verification($new_user_id, $email, $first_name, $last_name, $token);
    if (!$sent) {
        flash_set(
            'warning',
            "Inscription enregistrÃ©e, mais email non envoyÃ©",
            "Votre compte est crÃ©Ã©, mais l'email de confirmation n'a pas pu Ãªtre envoyÃ©. "
            . "Vous pourrez le renvoyer depuis la page de suivi."
        );
    } else {
        flash_set(
            'success',
            "Inscription rÃ©ussie ! ðŸŽ‰",
            "Un email de confirmation a Ã©tÃ© envoyÃ© Ã  " . $email . ". "
            . "Cliquez sur le lien dans cet email pour activer votre compte. "
            . "Vous ne le trouvez pas ? VÃ©rifiez vos spams ou courriers indÃ©sirables.",
            'login.php',
            'Aller Ã  la connexion'
        );
    }
    header('Location: login.php'); exit;
}

flash_set(
    'success',
    "Bienvenue sur EMSP Docs ! ðŸŽ“",
    "Votre compte a Ã©tÃ© crÃ©Ã© et activÃ© automatiquement car vous utilisez une adresse email de l'Ã©cole. "
    . "Vous pouvez maintenant vous connecter.",
    'login.php',
    'Se connecter maintenant'
);
header('Location: login.php'); exit;
*/




