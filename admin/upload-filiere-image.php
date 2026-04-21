<?php
ob_start();
include_once __DIR__ . '/bootstrap.php';

if (empty($_SESSION['auth']) || !in_array($_SESSION['auth_role'] ?? '', ['admin', 'moderateur'], true)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => 'Acces refuse']);
    exit;
}

include_once __DIR__ . '/config/dbcon.php';
include_once __DIR__ . '/../includes/csrf.php';

header('Content-Type: application/json; charset=UTF-8');

$csrf = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$sessionToken = $_SESSION['csrf_token'] ?? '';
if ($sessionToken === '' || $csrf === '' || !hash_equals($sessionToken, $csrf)) {
    http_response_code(403);
    echo json_encode(['error' => 'Token CSRF invalide']);
    exit;
}

if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['error' => 'Aucun fichier recu ou erreur upload']);
    exit;
}

$maxSize = emsp_max_upload_size(defined('MAX_UPLOAD_SIZE') ? MAX_UPLOAD_SIZE : (5 * 1024 * 1024));
if ($_FILES['image']['size'] > $maxSize) {
    http_response_code(413);
    echo json_encode(['error' => 'Fichier trop lourd (max ' . round($maxSize / 1024 / 1024, 1) . ' Mo)']);
    exit;
}

if (!function_exists('finfo_open')) {
    http_response_code(500);
    echo json_encode(['error' => 'finfo_file indisponible']);
    exit;
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $_FILES['image']['tmp_name']);

$allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
$mimeToExt = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
];

if (!in_array($mime, $allowedMimes, true)) {
    http_response_code(415);
    echo json_encode(['error' => 'Type de fichier non autorise']);
    exit;
}

$ext = $mimeToExt[$mime];
$hash = bin2hex(random_bytes(16));
$destDir = __DIR__ . '/../uploads/formations/editor';
if (!is_dir($destDir) && !mkdir($destDir, 0755, true)) {
    http_response_code(500);
    echo json_encode(['error' => 'Impossible de creer le dossier d upload']);
    exit;
}

$realDir = realpath($destDir);
if ($realDir === false || !is_writable($realDir)) {
    http_response_code(500);
    echo json_encode(['error' => 'Dossier upload non accessible en ecriture']);
    exit;
}

$filename = $hash . '.' . $ext;
$destPath = $realDir . DIRECTORY_SEPARATOR . $filename;
if (!move_uploaded_file($_FILES['image']['tmp_name'], $destPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Echec sauvegarde fichier']);
    exit;
}

echo json_encode(['url' => 'uploads/formations/editor/' . $filename]);
exit;


