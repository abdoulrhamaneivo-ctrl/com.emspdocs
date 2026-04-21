<?php
// admin/authentication.php
// Inclure ce fichier apres bootstrap + dbcon pour verifier l'acces admin.

if (empty($_SESSION['auth'])) {
    $_SESSION['message'] = 'Connectez-vous pour acceder a l administration.';
    header('Location: ../index.php?open_login=1');
    exit(0);
}

$session_lifetime = 3600;
if (isset($_SESSION['last_activity']) && (time() - (int) $_SESSION['last_activity']) > $session_lifetime) {
    session_unset();
    session_destroy();
    header('Location: ../index.php?open_login=1&timeout=1');
    exit;
}
$_SESSION['last_activity'] = time();

$authUser = $_SESSION['auth_user'] ?? [];
$uid = (int) ($authUser['id'] ?? 0);
if ($uid <= 0 || !isset($con) || !($con instanceof mysqli)) {
    session_unset();
    session_destroy();
    header('Location: ../index.php?open_login=1');
    exit;
}

$syncStmt = mysqli_prepare(
    $con,
    "SELECT id, first_name, last_name, email, role, status, badge_level, photo_path
     FROM users
     WHERE id=?
     LIMIT 1"
);
if (!$syncStmt) {
    header('Location: ../index.php?open_login=1');
    exit;
}

mysqli_stmt_bind_param($syncStmt, 'i', $uid);
mysqli_stmt_execute($syncStmt);
$freshUser = emsp_stmt_fetch_assoc($syncStmt);
mysqli_stmt_close($syncStmt);

if (!$freshUser) {
    session_unset();
    session_destroy();
    header('Location: ../index.php?open_login=1');
    exit;
}

emsp_session_sync_auth_user($freshUser);

$auth_role = strtolower((string) ($_SESSION['auth_role'] ?? ''));
$auth_status = strtolower((string) ($_SESSION['auth_user']['status'] ?? ''));

if (!in_array($auth_role, ['admin', 'moderateur'], true)) {
    header('Location: ../dashboard.php');
    exit(0);
}

if ($auth_status === 'pending') {
    header('Location: ../pending-status.php');
    exit(0);
}

if (in_array($auth_status, ['rejected', 'suspended'], true)) {
    unset($_SESSION['auth'], $_SESSION['auth_user'], $_SESSION['auth_role'], $_SESSION['notif_count'], $_SESSION['notif_sections'], $_SESSION['emsp_notif_sections_fetched_at']);
    $_SESSION['message'] = $auth_status === 'rejected'
        ? 'Votre compte a ete refuse.'
        : 'Votre compte est suspendu.';
    header('Location: ../index.php?open_login=1');
    exit(0);
}

$auth_user = $_SESSION['auth_user'];


