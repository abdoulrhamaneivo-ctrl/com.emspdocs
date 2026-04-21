<?php
include_once __DIR__ . '/includes/bootstrap.php';
include_once __DIR__ . '/admin/config/dbcon.php';
include_once __DIR__ . '/includes/csrf.php';
include_once __DIR__ . '/includes/flash.php';
include_once __DIR__ . '/includes/rate_limit.php';
include_once __DIR__ . '/includes/notif-helper.php';

verify_csrf_token();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php'); exit;
}

$email    = trim($_POST['email']    ?? '');
$password = (string) ($_POST['password'] ?? '');
$ip       = emsp_client_ip();

$rate = rate_limit_check($ip, $con);
if (!empty($rate['blocked'])) {
    $retry_in = (int) ($rate['retry_in'] ?? 0);
    if ($retry_in > 0) {
        $mins = (int) ceil($retry_in / 60);
        $wait = ($retry_in >= 60)
            ? ($mins . ' minute' . ($mins > 1 ? 's' : ''))
            : ($retry_in . ' seconde' . ($retry_in > 1 ? 's' : ''));
        $msg = 'Pour votre sÃ©curitÃ©, rÃ©essayez dans ' . $wait . '.';
    } else {
        $msg = 'Pour votre sÃ©curitÃ©, rÃ©essayez plus tard.';
    }
    flash_set('warning', 'Trop de tentatives', $msg);
    header('Location: login.php'); exit;
}

if ($email === '' || $password === '') {
    rate_limit_record_failure($ip, $con);
    flash_set(
        'error',
        'Champs manquants',
        'Email et mot de passe sont obligatoires pour se connecter.'
    );
    header('Location: login.php'); exit;
}

// RÃ©cupÃ©ration utilisateur
$stmt = mysqli_prepare($con, "SELECT id, first_name, last_name, email, password_hash, role, status, badge_level, email_verified_at, rejection_reason, photo_path FROM users WHERE email=? LIMIT 1");
if (!$stmt) {
    flash_set('error', 'Erreur technique', 'Impossible de vÃ©rifier votre compte pour le moment.');
    header('Location: login.php'); exit;
}
mysqli_stmt_bind_param($stmt, 's', $email);
mysqli_stmt_execute($stmt);
$user = emsp_stmt_fetch_assoc($stmt);
mysqli_stmt_close($stmt);

// VÃ©rification mot de passe
if (!$user || !password_verify($password, $user['password_hash'])) {
    rate_limit_record_failure($ip, $con);
    flash_set(
        'error',
        'Adresse email ou mot de passe incorrect',
        "VÃ©rifiez votre saisie. Si vous avez oubliÃ© votre mot de passe, vous pouvez le rÃ©initialiser.",
        'forgot-password.php',
        'RÃ©initialiser le mot de passe'
    );
    header('Location: login.php'); exit;
}

rate_limit_clear($ip, $con);

// Gestion des statuts
if ($user['status'] === 'pending') {
    session_regenerate_id(true);
    emsp_session_sync_auth_user($user);
    emsp_session_set_notif_count(0);
    emsp_session_set_notif_sections(['journal' => 0, 'media' => 0]);
    header('Location: pending-status.php'); exit;
}

if ($user['status'] === 'rejected') {
    $reason = $user['rejection_reason'] ? ' Motif : ' . $user['rejection_reason'] : '';
    flash_set(
        'error',
        "Demande d'inscription refusÃ©e",
        "Votre demande a Ã©tÃ© refusÃ©e." . $reason . " Si vous pensez qu'il s'agit d'une erreur, contactez l'administration."
    );
    header('Location: login.php'); exit;
}

if ($user['status'] === 'suspended') {
    flash_set(
        'error',
        "Compte suspendu",
        "Votre compte a Ã©tÃ© suspendu. Contactez l'administration pour plus d'informations."
    );
    header('Location: login.php'); exit;
}

if ($user['status'] !== 'active') {
    flash_set(
        'warning',
    'Compte inactif',
    "Votre compte n'est pas actif pour le moment. Contactez l'administration si besoin."
    );
    header('Location: login.php'); exit;
}

// Connexion rÃ©ussie
session_regenerate_id(true);
emsp_session_sync_auth_user($user);

$upd = mysqli_prepare($con, "UPDATE users SET last_login_at=NOW() WHERE id=?");
if ($upd) {
    mysqli_stmt_bind_param($upd, 'i', $user['id']);
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);
}

$notifStmt = mysqli_prepare($con, "SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
if ($notifStmt) {
    mysqli_stmt_bind_param($notifStmt, 'i', $user['id']);
    mysqli_stmt_execute($notifStmt);
    mysqli_stmt_bind_result($notifStmt, $notifCount);
    mysqli_stmt_fetch($notifStmt);
    mysqli_stmt_close($notifStmt);
    emsp_session_set_notif_count((int) $notifCount);
    emsp_session_set_notif_sections(emsp_unread_notification_sections($con, (int) $user['id']));
    $_SESSION['emsp_notif_sections_fetched_at'] = time();
} else {
    emsp_session_set_notif_count(0);
    emsp_session_set_notif_sections(['journal' => 0, 'media' => 0]);
    $_SESSION['emsp_notif_sections_fetched_at'] = time();
}

flash_set(
    'success',
    'Bon retour, ' . $user['first_name'] . ' !'
);

if (!empty($_SESSION['redirect_after_login'])) {
    $redirect = $_SESSION['redirect_after_login'];
    unset($_SESSION['redirect_after_login']);
    header('Location: ' . $redirect); exit;
}

if (in_array($user['role'], ['admin','moderateur'], true)) {
    header('Location: admin/index.php');
} else {
    header('Location: dashboard.php');
}
exit;



