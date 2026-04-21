<?php
include_once __DIR__ . '/includes/bootstrap.php';
include_once __DIR__ . '/includes/csrf.php';

if (empty($_SESSION['auth'])) {
    header('Location: login.php');
    exit(0);
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Location: dashboard.php');
    exit(0);
}

verify_csrf_token();

include_once __DIR__ . '/admin/config/dbcon.php';
include_once __DIR__ . '/includes/notif-helper.php';

function emsp_safe_local_redirect(string $target, string $default = 'dashboard.php'): string
{
    $target = trim($target);
    if ($target === '') {
        return $default;
    }

    $target = str_replace('\\', '/', $target);
    if (preg_match('#^(?:[a-z]+:)?//#i', $target)) {
        return $default;
    }

    if (str_starts_with($target, '/')) {
        $target = ltrim($target, '/');
    }

    if ($target === '' || preg_match('/[\r\n]/', $target)) {
        return $default;
    }

    return $target;
}

$uid = (int) ($_SESSION['auth_user']['id'] ?? 0);
$notificationId = (int) ($_POST['notification_id'] ?? 0);
$redirectRaw = (string) ($_POST['redirect_to'] ?? '');
$redirectTo = emsp_safe_local_redirect($redirectRaw, 'dashboard.php');

if ($notificationId > 0) {
    $notificationType = '';
    $notifRow = [];
    $typeStmt = mysqli_prepare(
        $con,
        'SELECT type, document_id, comment_id, reply_id FROM notifications WHERE user_id=? AND id=? LIMIT 1'
    );
    if ($typeStmt) {
        mysqli_stmt_bind_param($typeStmt, 'ii', $uid, $notificationId);
        mysqli_stmt_execute($typeStmt);
        $notifRow = emsp_stmt_fetch_assoc($typeStmt) ?: [];
        mysqli_stmt_close($typeStmt);
        $notificationType = (string) ($notifRow['type'] ?? '');
    }
    if ($redirectRaw === '') {
        $redirectTo = emsp_notification_target($notifRow, 'dashboard.php');
    }
    $s = mysqli_prepare(
        $con,
        'UPDATE notifications SET is_read=1 WHERE user_id=? AND id=? AND is_read=0'
    );
    if ($s) {
        mysqli_stmt_bind_param($s, 'ii', $uid, $notificationId);
        mysqli_stmt_execute($s);
        $affected = mysqli_stmt_affected_rows($s);
        mysqli_stmt_close($s);
        if ($affected > 0) {
            emsp_session_adjust_notif_count(-1);
            $section = emsp_notification_section($notificationType);
            if ($section !== null) {
                emsp_session_adjust_notif_section($section, -1);
            }
            $_SESSION['emsp_notif_sections_fetched_at'] = time();
        }
    }
} else {
    $s = mysqli_prepare($con, 'UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0');
    if ($s) {
        mysqli_stmt_bind_param($s, 'i', $uid);
        mysqli_stmt_execute($s);
        mysqli_stmt_close($s);
    }
    emsp_session_set_notif_count(0);
    emsp_session_set_notif_sections(['journal' => 0, 'media' => 0]);
    $_SESSION['emsp_notif_sections_fetched_at'] = time();
}

header('Location: ' . $redirectTo);
exit(0);


