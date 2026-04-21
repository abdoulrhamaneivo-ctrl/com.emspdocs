<?php
include_once __DIR__ . '/includes/bootstrap.php';
include_once __DIR__ . '/admin/config/dbcon.php';
include_once __DIR__ . '/includes/brevo.php';
include_once __DIR__ . '/includes/flash.php';

$token = trim($_GET['token'] ?? '');
if ($token === '') {
    flash_set('error', 'Lien invalide', 'Ce lien de vÃ©rification est invalide ou expirÃ©.');
    header('Location: login.php'); exit;
}

$s = mysqli_prepare($con, "SELECT id, first_name, last_name, email, status, email_verified_at FROM users WHERE verification_token=? LIMIT 1");
mysqli_stmt_bind_param($s,'s',$token);
mysqli_stmt_execute($s);
$user = emsp_stmt_fetch_assoc($s);
mysqli_stmt_close($s);

if (!$user) {
    flash_set('error', 'Lien invalide', 'Ce lien de vÃ©rification est invalide ou expirÃ©.');
    header('Location: login.php'); exit;
}

if (!empty($user['email_verified_at'])) {
    flash_set('info', 'Email dÃ©jÃ  vÃ©rifiÃ©', 'Ton adresse email a dÃ©jÃ  Ã©tÃ© confirmÃ©e.');
    header('Location: login.php');
    exit;
}

$uid = intval($user['id']);
$email = $user['email'];
$prenom = $user['first_name'];
$nom = $user['last_name'];
$loggedForUser = !empty($_SESSION['auth_user']['id']) && (int) $_SESSION['auth_user']['id'] === $uid;

// Marquer comme vÃ©rifiÃ©
$updated = false;
$s = mysqli_prepare($con, "UPDATE users SET email_verified_at=NOW(), verification_token=NULL WHERE id=?");
if ($s) {
    mysqli_stmt_bind_param($s,'i',$uid);
    if (mysqli_stmt_execute($s)) {
        $updated = (mysqli_stmt_affected_rows($s) > 0);
    }
    mysqli_stmt_close($s);
}
if (!$updated) {
    $s = mysqli_prepare($con, "UPDATE users SET verification_token=NULL WHERE id=?");
    if ($s) {
        mysqli_stmt_bind_param($s,'i',$uid);
        mysqli_stmt_execute($s);
        mysqli_stmt_close($s);
    }
    error_log('EMSP verify-email: email_verified_at update failed for user_id=' . $uid . ' | ' . mysqli_error($con));
}

// Si email Ã©cole -> activer direct
$domains = emsp_get_school_domains($con);
if (emsp_is_school_email($email, $domains)) {
    $s = mysqli_prepare($con, "UPDATE users SET status='active', status_updated_at=NOW() WHERE id=?");
    mysqli_stmt_bind_param($s,'i',$uid); mysqli_stmt_execute($s); mysqli_stmt_close($s);
    brevo_send_account_approved($uid, $email, $prenom, $nom);
    flash_set('success', 'Email vÃ©rifiÃ©', 'Ton compte a Ã©tÃ© activÃ©. Tu peux maintenant te connecter.');
    header('Location: login.php'); exit;
}

// Sinon : reste pending (examen admin)
flash_set('success', 'Email vÃ©rifiÃ©', 'Ton email est confirmÃ©. Un administrateur doit maintenant valider ton compte.');
header('Location: ' . ($loggedForUser ? 'pending-status.php?verified=1' : 'login.php'));
exit;



