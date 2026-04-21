<?php
include_once __DIR__ . '/bootstrap.php';
include_once __DIR__ . '/config/dbcon.php';
include_once __DIR__ . '/authentication.php';
include_once __DIR__ . '/../includes/csrf.php';
include_once __DIR__ . '/../includes/content-helpers.php';

if (!function_exists('emsp_media_local_poster_path')) {
    function emsp_media_local_poster_path(string $filePath): ?string
    {
        $trimmed = trim($filePath);
        if ($trimmed === '' || preg_match('#^https?://#i', $trimmed)) {
            return null;
        }

        $projectRoot = dirname(__DIR__);
        $clean = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($trimmed, '/\\'));
        $candidate = $projectRoot . DIRECTORY_SEPARATOR . $clean;
        $real = realpath($candidate);
        if ($real === false || !is_file($real)) {
            return null;
        }

        $uploadsMediaRoot = realpath($projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'media');
        if ($uploadsMediaRoot !== false && strpos($real, $uploadsMediaRoot) === 0) {
            return $real;
        }

        return null;
    }
}

if (empty($_SESSION['auth']) || !in_array($_SESSION['auth_role'] ?? '', ['admin', 'moderateur'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Acces refuse.']);
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Methode invalide.']);
    exit;
}

verify_csrf_token();

$mediaId = (int) ($_POST['media_id'] ?? 0);
if ($mediaId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Media invalide.']);
    exit;
}

$file = $_FILES['poster_file'] ?? null;
if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Fichier miniature manquant.']);
    exit;
}

$tmp = (string) ($file['tmp_name'] ?? '');
$size = (int) ($file['size'] ?? 0);
if ($tmp === '' || $size <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Fichier miniature invalide.']);
    exit;
}

$mime = emsp_detect_mime($tmp);
$allowed = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];
if (!isset($allowed[$mime])) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => 'Format de miniature non supporte.']);
    exit;
}

if ($size > 2 * 1024 * 1024) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'message' => 'Miniature trop lourde (max 2 Mo).']);
    exit;
}

$targetDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . 'posters';
if (!is_dir($targetDir)) {
    if (!@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Impossible de creer le dossier posters.']);
        exit;
    }
}

$stmt = mysqli_prepare($con, "SELECT poster_path FROM media WHERE id=? LIMIT 1");
$oldPoster = '';
if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'i', $mediaId);
    mysqli_stmt_execute($stmt);
    $row = emsp_stmt_fetch_assoc($stmt);
    mysqli_stmt_close($stmt);
    $oldPoster = (string) ($row['poster_path'] ?? '');
}

$filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
$dest = $targetDir . DIRECTORY_SEPARATOR . $filename;
if (!move_uploaded_file($tmp, $dest)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Impossible de sauvegarder la miniature.']);
    exit;
}

$posterPath = 'uploads/media/posters/' . $filename;
$upd = mysqli_prepare($con, "UPDATE media SET poster_path=? WHERE id=? LIMIT 1");
if ($upd) {
    mysqli_stmt_bind_param($upd, 'si', $posterPath, $mediaId);
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);
}

if ($oldPoster !== '') {
    $oldLocal = emsp_media_local_poster_path($oldPoster);
    if ($oldLocal && is_file($oldLocal)) {
        @unlink($oldLocal);
    }
}

header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['ok' => true, 'poster_path' => $posterPath], JSON_UNESCAPED_UNICODE);
exit;


