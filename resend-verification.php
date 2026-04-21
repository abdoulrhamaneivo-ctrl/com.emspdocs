<?php
include_once __DIR__ . '/includes/bootstrap.php';
include_once __DIR__ . '/admin/config/dbcon.php';
include_once __DIR__ . '/includes/csrf.php';
include_once __DIR__ . '/includes/brevo.php';
include_once __DIR__ . '/includes/flash.php';

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Location: pending-status.php');
    exit;
}

verify_csrf_token();

if (empty($_SESSION['auth']) || empty($_SESSION['auth_user']['id'])) {
    flash_set('warning', 'Connexion requise', 'Veuillez vous connecter pour renvoyer lâ€™email.');
    header('Location: login.php'); exit;
}
$uid = intval($_SESSION['auth_user']['id']);

$s = mysqli_prepare($con, "SELECT id, first_name, last_name, email, status, email_verified_at, verification_token, created_at FROM users WHERE id=? LIMIT 1");
mysqli_stmt_bind_param($s,'i',$uid);
mysqli_stmt_execute($s);
$user = emsp_stmt_fetch_assoc($s);
mysqli_stmt_close($s);

$email_confirmed = $user ? (!empty($user['email_verified_at']) || empty($user['verification_token'])) : false;
if (!$user || $user['status']!=='pending' || $email_confirmed) {
    header('Location: pending-status.php'); exit;
}

// Cooldown rÃ©el basÃ© sur les envois dÃ©jÃ  tracÃ©s
if (brevo_recent_email_exists($con, $uid, 'verification', 5)) {
    flash_set(
        'warning',
        'Patiente un peu',
        'Veuillez patienter 5 minutes avant de renvoyer un email de confirmation.'
    );
    header('Location: pending-status.php'); exit;
}

$token = bin2hex(random_bytes(32));
$u = mysqli_prepare($con, "UPDATE users SET verification_token=? WHERE id=?");
mysqli_stmt_bind_param($u,'si',$token,$uid);
mysqli_stmt_execute($u); mysqli_stmt_close($u);

if (brevo_send_verification($uid, $user['email'], $user['first_name'], $user['last_name'], $token)) {
    flash_set(
        'success',
        'Email renvoyÃ©',
        'Un nouvel email de confirmation vient dâ€™Ãªtre envoyÃ©. VÃ©rifiez votre boÃ®te mail et vos spams.'
    );
} else {
    flash_set(
        'error',
        'Envoi impossible',
        'Le mail de confirmation nâ€™a pas pu Ãªtre envoyÃ© pour le moment. RÃ©essayez dans quelques minutes.'
    );
}
header('Location: pending-status.php'); exit;



