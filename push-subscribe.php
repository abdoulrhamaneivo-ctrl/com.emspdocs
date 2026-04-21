<?php
include_once __DIR__ . '/includes/bootstrap.php';
include_once __DIR__ . '/admin/config/dbcon.php';
include_once __DIR__ . '/includes/csrf.php';
include_once __DIR__ . '/includes/push-helper.php';

header('Content-Type: application/json; charset=UTF-8');

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}

if (empty($_SESSION['auth_user']['id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth-required']);
    exit;
}

$sessionToken = (string)($_SESSION['csrf_token'] ?? '');
$postToken = (string)($_POST['csrf_token'] ?? '');
if ($sessionToken === '' || $postToken === '' || !hash_equals($sessionToken, $postToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'csrf']);
    exit;
}

$endpoint = trim((string)($_POST['endpoint'] ?? ''));
$p256dh = trim((string)($_POST['p256dh'] ?? ''));
$auth = trim((string)($_POST['auth'] ?? ''));
$deviceLabel = trim((string)($_POST['device_label'] ?? ''));
$userAgent = trim((string)($_POST['user_agent'] ?? ''));

if ($endpoint === '' || $p256dh === '' || $auth === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid']);
    exit;
}

$uid = (int) $_SESSION['auth_user']['id'];
if (!emsp_ensure_web_push_table($con)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'schema']);
    exit;
}

$stmt = mysqli_prepare(
    $con,
    "INSERT INTO web_push_subscriptions (user_id, endpoint, p256dh, auth, device_label, user_agent)
     VALUES (?, ?, ?, ?, NULLIF(?,''), NULLIF(?, ''))
     ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), p256dh=VALUES(p256dh), auth=VALUES(auth),
                             device_label=VALUES(device_label), user_agent=VALUES(user_agent)"
);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db']);
    exit;
}
mysqli_stmt_bind_param($stmt, 'isssss', $uid, $endpoint, $p256dh, $auth, $deviceLabel, $userAgent);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

echo json_encode(['ok' => true]);
exit;


