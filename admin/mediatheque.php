<?php
ob_start();
include_once __DIR__ . '/bootstrap.php';

include_once __DIR__ . '/config/dbcon.php';
include_once __DIR__ . '/authentication.php';
include_once __DIR__ . '/../includes/csrf.php';
include_once __DIR__ . '/../includes/flash.php';
include_once __DIR__ . '/../includes/notif-helper.php';
include_once dirname(__DIR__) . '/includes/content-helpers.php';

// Auto-create media table if missing (safe for first deploy)
$hasMediaTable = false;
$chkMedia = mysqli_query($con, "SHOW TABLES LIKE 'media'");
if ($chkMedia && mysqli_num_rows($chkMedia) > 0) {
    $hasMediaTable = true;
}
if (!$hasMediaTable) {
    $createMediaSql = "
        CREATE TABLE IF NOT EXISTS media (
          id INT(11) NOT NULL AUTO_INCREMENT,
          title VARCHAR(200) NOT NULL,
          description TEXT DEFAULT NULL,
          type ENUM('image','video','lien') NOT NULL,
          file_path VARCHAR(255) NOT NULL,
          poster_path VARCHAR(255) DEFAULT NULL,
          category VARCHAR(100) DEFAULT NULL,
          category_id INT(11) DEFAULT NULL,
          is_public TINYINT(1) NOT NULL DEFAULT 1,
          status ENUM('published','archived') DEFAULT 'published',
          created_by INT(11) NOT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (mysqli_query($con, $createMediaSql)) {
        $hasMediaTable = true;
    } else {
        flash_set('error', 'Table manquante', 'Impossible de crÃ©er la table media. Importez la base de donnÃ©es.');
    }
}
if ($hasMediaTable) {
    $colCheck = mysqli_query($con, "SHOW COLUMNS FROM media LIKE 'poster_path'");
    if (!$colCheck || mysqli_num_rows($colCheck) === 0) {
        @mysqli_query($con, "ALTER TABLE media ADD COLUMN poster_path VARCHAR(255) DEFAULT NULL AFTER file_path");
    }
}

function emsp_media_resolve_local_path(string $filePath): ?string
{
    $trimmed = trim($filePath);
    if ($trimmed === '' || preg_match('#^https?://#i', $trimmed)) {
        return null;
    }

    $projectRoot = dirname(__DIR__);
    $candidates = [];

    if (strpos($trimmed, 'uploads/') === 0 || strpos($trimmed, 'assets/') === 0) {
        $candidates[] = $projectRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $trimmed);
    } else {
        $base = basename($trimmed);
        $candidates[] = $projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . $base;
        $candidates[] = $projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . $base;
        $candidates[] = $projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'media' . DIRECTORY_SEPARATOR . 'videos' . DIRECTORY_SEPARATOR . $base;
    }

    foreach ($candidates as $candidate) {
        $real = realpath($candidate);
        if ($real === false || !is_file($real)) {
            continue;
        }
        $uploadsMediaRoot = realpath($projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'media');
        if ($uploadsMediaRoot !== false && strpos($real, $uploadsMediaRoot) === 0) {
            return $real;
        }
    }

    return null;
}

function emsp_media_allowed_statuses(mysqli $con): array
{
    $allowed = ['published','archived'];
    $res = mysqli_query($con, "SHOW COLUMNS FROM media LIKE 'status'");
    if ($res) {
        $row = mysqli_fetch_assoc($res);
        if (!empty($row['Type']) && preg_match_all("/'([^']+)'/", $row['Type'], $m)) {
            if (!empty($m[1])) {
                $allowed = $m[1];
            }
        }
    }
    return $allowed;
}

function emsp_media_admin_is_ajax_request(): bool
{
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

function emsp_media_admin_json_response(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function emsp_media_admin_fail(string $filter, string $title, string $message, int $status = 422): void
{
    if (emsp_media_admin_is_ajax_request()) {
        emsp_media_admin_json_response($status, [
            'ok' => false,
            'title' => $title,
            'message' => $message,
        ]);
    }

    flash_set('error', $title, $message);
    header('Location: mediatheque.php?filter=' . rawurlencode($filter));
    exit(0);
}

function emsp_media_admin_success(string $filter, string $title, string $message, array $extra = []): void
{
    $globalExtra = [];
    if (isset($GLOBALS['emsp_media_admin_extra']) && is_array($GLOBALS['emsp_media_admin_extra'])) {
        $globalExtra = $GLOBALS['emsp_media_admin_extra'];
    }
    if (!empty($globalExtra)) {
        $extra = array_merge($extra, $globalExtra);
    }
    flash_set('success', $title, $message);

    if (emsp_media_admin_is_ajax_request()) {
        emsp_media_admin_json_response(200, array_merge([
            'ok' => true,
            'title' => $title,
            'message' => $message,
            'redirect' => 'mediatheque.php?filter=' . rawurlencode($filter),
        ], $extra));
    }

    header('Location: mediatheque.php?filter=' . rawurlencode($filter));
    exit(0);
}

function emsp_media_admin_href(string $filePath): string
{
    $src = emsp_media_src($filePath);
    if ($src === '' || preg_match('#^https?://#i', $src)) {
        return $src;
    }
    return '../' . ltrim($src, '/');
}

$allowedFilters = ['all', 'image', 'video', 'public', 'private'];
$filter = strtolower(trim((string) ($_GET['filter'] ?? 'all')));
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}
$statusOptions = emsp_media_allowed_statuses($con);
$serverMaxUpload = emsp_max_upload_size(70 * 1024 * 1024);
$serverMaxUploadMb = round($serverMaxUpload / 1024 / 1024, 1);
$postMaxBytes = emsp_parse_size_to_bytes((string) ini_get('post_max_size'));
$imgLimitBytes = min(8 * 1024 * 1024, $serverMaxUpload);
$vidLimitBytes = min(70 * 1024 * 1024, $serverMaxUpload);
$imgLimitMb = round($imgLimitBytes / 1024 / 1024, 1);
$vidLimitMb = round($vidLimitBytes / 1024 / 1024, 1);

$mediaMimeToExt = [
    'image/jpeg'       => ['type' => 'image', 'ext' => 'jpg',  'max' => 8 * 1024 * 1024],
    'image/png'        => ['type' => 'image', 'ext' => 'png',  'max' => 8 * 1024 * 1024],
    'image/webp'       => ['type' => 'image', 'ext' => 'webp', 'max' => 8 * 1024 * 1024],
    'image/gif'        => ['type' => 'image', 'ext' => 'gif',  'max' => 8 * 1024 * 1024],
    'video/mp4'        => ['type' => 'video', 'ext' => 'mp4',  'max' => 70 * 1024 * 1024],
    'video/webm'       => ['type' => 'video', 'ext' => 'webm', 'max' => 70 * 1024 * 1024],
    'video/ogg'        => ['type' => 'video', 'ext' => 'ogv',  'max' => 70 * 1024 * 1024],
    'video/quicktime'  => ['type' => 'video', 'ext' => 'mp4',  'max' => 70 * 1024 * 1024],
    'video/x-msvideo'  => ['type' => 'video', 'ext' => 'mp4',  'max' => 70 * 1024 * 1024],
    'video/x-matroska' => ['type' => 'video', 'ext' => 'mp4',  'max' => 70 * 1024 * 1024],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > 0 && $postMaxBytes > 0 && $contentLength > $postMaxBytes) {
        $limitMb = round($postMaxBytes / 1024 / 1024, 1);
        emsp_media_admin_fail(
            $filter,
            'Fichier trop lourd',
            'Le fichier depasse la limite serveur post_max_size (' . $limitMb . ' Mo).',
            413
        );
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_visibility'])) {
    verify_csrf_token();
    $mediaId = intval($_POST['toggle_visibility']);

    if ($mediaId > 0) {
        $currentMedia = null;
        $checkStmt = mysqli_prepare($con, "SELECT title, is_public, status FROM media WHERE id=? LIMIT 1");
        if ($checkStmt) {
            mysqli_stmt_bind_param($checkStmt, 'i', $mediaId);
            mysqli_stmt_execute($checkStmt);
            $currentMedia = emsp_stmt_fetch_assoc($checkStmt);
            mysqli_stmt_close($checkStmt);
        }

        $s = mysqli_prepare($con, "UPDATE media SET is_public = IF(is_public=1, 0, 1) WHERE id=? LIMIT 1");
        if ($s) {
            mysqli_stmt_bind_param($s, 'i', $mediaId);
            mysqli_stmt_execute($s);
            mysqli_stmt_close($s);
            if ($currentMedia && (int) ($currentMedia['is_public'] ?? 0) === 0 && (string) ($currentMedia['status'] ?? '') === 'published') {
                notify_media_published_all($con, $mediaId, (string) ($currentMedia['title'] ?? 'Nouveau media'), (int) ($auth_user['id'] ?? 0));
            }
            flash_set(
                'info',
                'VisibilitÃ© mise Ã  jour',
                'La visibilitÃ© du mÃ©dia a Ã©tÃ© modifiÃ©e.'
            );
        }
    }

    header('Location: mediatheque.php?filter=' . rawurlencode($filter));
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_media'])) {
    verify_csrf_token();
    $mediaId = intval($_POST['delete_media']);

    if ($mediaId > 0) {
        $filePath = '';
            $s = mysqli_prepare($con, "SELECT file_path FROM media WHERE id=? LIMIT 1");
            if ($s) {
                mysqli_stmt_bind_param($s, 'i', $mediaId);
                mysqli_stmt_execute($s);
                $row = emsp_stmt_fetch_assoc($s);
                mysqli_stmt_close($s);
                if ($row) {
                    $filePath = (string) ($row['file_path'] ?? '');
                }
            }

        $s = mysqli_prepare($con, "DELETE FROM media WHERE id=? LIMIT 1");
        if ($s) {
            mysqli_stmt_bind_param($s, 'i', $mediaId);
            mysqli_stmt_execute($s);
            mysqli_stmt_close($s);
        }

        $localPath = emsp_media_resolve_local_path($filePath);
        if ($localPath !== null) {
            @unlink($localPath);
        }

        log_audit($con, (int) $auth_user['id'], 'media_deleted', 'media', $mediaId, $filePath);
        flash_set(
            'info',
            'MÃ©dia supprimÃ©',
            'Le mÃ©dia a Ã©tÃ© supprimÃ© dÃ©finitivement. Cette action est irrÃ©versible.'
        );
    }

    header('Location: mediatheque.php?filter=' . rawurlencode($filter));
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_media'])) {
    verify_csrf_token();

    $title = trim((string) ($_POST['title'] ?? ''));
    $descriptionRaw = trim((string) ($_POST['description'] ?? ''));
    $categoryId = intval($_POST['category_id'] ?? 0);
    $categoryRaw = trim((string) ($_POST['category'] ?? ''));
    $type = strtolower(trim((string) ($_POST['type'] ?? 'image')));
    $isPublic = isset($_POST['is_public']) ? 1 : 0;
    $status = strtolower(trim((string) ($_POST['status'] ?? 'published')));
    $mode = strtolower(trim((string) ($_POST['upload_mode'] ?? 'file')));
    $externalPath = trim((string) ($_POST['external_path'] ?? ''));
    $createdBy = intval($_SESSION['auth_user']['id'] ?? 0);

    $description = $descriptionRaw !== '' ? $descriptionRaw : null;
    $uploadedLocalPath = null;
    $categoryName = null;
    if ($categoryId > 0) {
        if ($cs = mysqli_prepare($con, "SELECT name FROM media_categories WHERE id=? LIMIT 1")) {
            mysqli_stmt_bind_param($cs, 'i', $categoryId);
            mysqli_stmt_execute($cs);
            mysqli_stmt_bind_result($cs, $categoryName);
            mysqli_stmt_fetch($cs);
            mysqli_stmt_close($cs);
        }
    }
    $categoryIdDb = null;
    if ($categoryId > 0 && $categoryName) {
        $categoryIdDb = $categoryId;
    }
    $category = $categoryName ? $categoryName : ($categoryRaw !== '' ? $categoryRaw : null);

    $allowedTypes = ['image', 'video', 'lien'];
    $allowedStatus = emsp_media_allowed_statuses($con);

    if ($title === '') {
        emsp_media_admin_fail($filter, 'Titre obligatoire', 'Veuillez renseigner un titre pour ce mÃ©dia.');
    }
    if (!in_array($type, $allowedTypes, true)) {
        emsp_media_admin_fail($filter, 'Type de mÃ©dia invalide', 'Choisissez un type valide (image, vidÃ©o ou lien).');
    }
    if (!in_array($status, $allowedStatus, true)) {
        $status = $allowedStatus[0] ?? 'published';
    }

    $filePath = null;

    if ($type === 'lien' || $mode === 'link') {
        if ($externalPath === '') {
            emsp_media_admin_fail($filter, 'Lien manquant', 'Ajoutez un lien ou un chemin pour ce mÃ©dia.');
        }
        $filePath = $externalPath;
        $type = 'lien';
    } else {
        $uploadErr = $_FILES['media_file']['error'] ?? UPLOAD_ERR_NO_FILE;
        $uploadName = (string) ($_FILES['media_file']['name'] ?? '');
        if ($uploadErr !== UPLOAD_ERR_OK || $uploadName === '') {
            $postMax = emsp_parse_size_to_bytes((string) ini_get('post_max_size'));
            $postMaxMb = $postMax > 0 ? round($postMax / 1024 / 1024, 1) : $serverMaxUploadMb;
            $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

            $titleMsg = 'Fichier requis';
            $detailMsg = 'Veuillez sÃ©lectionner un fichier mÃ©dia avant dâ€™envoyer.';

            if ($contentLength > 0 && $postMax > 0 && $contentLength > $postMax) {
                $titleMsg = 'Fichier trop lourd';
                $detailMsg = 'Le fichier dÃ©passe la limite serveur post_max_size (' . $postMaxMb . ' Mo).';
            } elseif ($uploadErr === UPLOAD_ERR_INI_SIZE || $uploadErr === UPLOAD_ERR_FORM_SIZE) {
                $titleMsg = 'Fichier trop lourd';
                $detailMsg = 'Le fichier dÃ©passe la limite serveur (' . $serverMaxUploadMb . ' Mo).';
            } elseif ($uploadErr === UPLOAD_ERR_PARTIAL) {
                $titleMsg = 'Upload incomplet';
                $detailMsg = 'Le fichier nâ€™a pas Ã©tÃ© entiÃ¨rement transfÃ©rÃ©. RÃ©essayez.';
            } elseif (in_array($uploadErr, [UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION], true)) {
                $titleMsg = 'Erreur serveur';
                $detailMsg = 'Le serveur nâ€™a pas pu enregistrer le fichier. Contactez lâ€™administrateur.';
            }
            emsp_media_admin_fail($filter, $titleMsg, $detailMsg, $titleMsg === 'Fichier trop lourd' ? 413 : 422);
        }

        $tmpName = (string) $_FILES['media_file']['tmp_name'];
        $fileSize = intval($_FILES['media_file']['size'] ?? 0);
        $extLower = strtolower(pathinfo($uploadName, PATHINFO_EXTENSION));

        $mime = emsp_detect_mime($tmpName);
        if (!isset($mediaMimeToExt[$mime])) {
            $extFallback = [
                'mp4'  => 'video/mp4',
                'mov'  => 'video/quicktime',
                'webm' => 'video/webm',
                'avi'  => 'video/x-msvideo',
                'mkv'  => 'video/x-matroska',
                'ogv'  => 'video/ogg',
            ];
            if (isset($extFallback[$extLower])) {
                $mime = $extFallback[$extLower];
            }
        }

        if (!isset($mediaMimeToExt[$mime])) {
            emsp_media_admin_fail(
                $filter,
                'Format non autorisÃ©',
                'Formats acceptÃ©s : JPG, PNG, WEBP, GIF pour les images ; MP4, WEBM, OGG, MOV, AVI et MKV pour les vidÃ©os.'
            );
        }

        $detected = $mediaMimeToExt[$mime];
        $typeMatches = $type === $detected['type']
            || ($type === 'video' && $detected['type'] === 'video');
        if (!$typeMatches) {
            emsp_media_admin_fail(
                $filter,
                'Type incompatible',
                'Le type choisi ne correspond pas au fichier fourni. SÃ©lectionnez le bon type.'
            );
        }

        $limit = min($detected['max'], $serverMaxUpload);
        if ($fileSize <= 0 || $fileSize > $limit) {
            $limitMb = round($limit / 1024 / 1024, 1);
            $msg = $detected['type'] === 'image'
                ? 'Image trop lourde (max ' . $limitMb . ' Mo).'
                : 'VidÃ©o trop lourde (max ' . $limitMb . ' Mo).';
            emsp_media_admin_fail($filter, 'Fichier trop lourd', $msg, 413);
        }

        $targetDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'media';
        if (!is_dir($targetDir)) {
            if (!@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                emsp_media_admin_fail(
                    $filter,
                    'Dossier manquant',
                    "Impossible de crÃ©er le dossier uploads/media/. CrÃ©ez-le manuellement via cPanel (public_html/uploads/media/) puis rÃ©essayez.",
                    500
                );
            }
        }

        $generated = bin2hex(random_bytes(16)) . '.' . $detected['ext'];
        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $generated;
        if (!move_uploaded_file($tmpName, $targetPath)) {
            emsp_media_admin_fail(
                $filter,
                'Ã‰chec de lâ€™upload',
                'Impossible dâ€™enregistrer le fichier mÃ©dia. RÃ©essayez dans quelques minutes.',
                500
            );
        }

        $filePath = 'uploads/media/' . $generated;
        $uploadedLocalPath = $targetPath;
    }

    $s = mysqli_prepare(
        $con,
        "INSERT INTO media (title, description, type, file_path, poster_path, category, category_id, is_public, status, created_by, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
    );

    if ($s) {
        $posterPath = null;
        mysqli_stmt_bind_param($s, 'ssssssiisi', $title, $description, $type, $filePath, $posterPath, $category, $categoryIdDb, $isPublic, $status, $createdBy);
        $ok = mysqli_stmt_execute($s);
        $err = mysqli_stmt_error($s);
        $newMediaId = (int) mysqli_insert_id($con);
        mysqli_stmt_close($s);
        if ($ok) {
            if ($isPublic === 1 && $status === 'published') {
                notify_media_published_all($con, $newMediaId, $title, (int) ($auth_user['id'] ?? 0));
            }
            $GLOBALS['emsp_media_admin_extra'] = [
                'media_id' => $newMediaId,
                'media_type' => $type,
                'file_path' => $filePath,
            ];
            emsp_media_admin_success($filter, 'MÃ©dia ajoutÃ©', 'Le mÃ©dia a Ã©tÃ© ajoutÃ© avec succÃ¨s.');
        } else {
            if ($uploadedLocalPath && is_file($uploadedLocalPath)) {
                @unlink($uploadedLocalPath);
            }
            emsp_media_admin_fail(
                $filter,
                'Ã‰chec de lâ€™ajout',
                'Impossible dâ€™enregistrer le mÃ©dia. ' . ($err ? 'DÃ©tail: ' . $err : 'RÃ©essayez.'),
                500
            );
        }
    } else {
        if ($uploadedLocalPath && is_file($uploadedLocalPath)) {
            @unlink($uploadedLocalPath);
        }
        emsp_media_admin_fail(
            $filter,
            'Ã‰chec de lâ€™ajout',
            'Impossible de prÃ©parer la requÃªte dâ€™enregistrement. RÃ©essayez.',
            500
        );
    }
}

$total = 0;
$images = 0;
$videos = 0;
$publics = 0;

$s = mysqli_prepare($con, "SELECT COUNT(*) FROM media");
if ($s) {
    mysqli_stmt_execute($s);
    mysqli_stmt_bind_result($s, $total);
    mysqli_stmt_fetch($s);
    mysqli_stmt_close($s);
}

$s = mysqli_prepare($con, "SELECT COUNT(*) FROM media WHERE type='image'");
if ($s) {
    mysqli_stmt_execute($s);
    mysqli_stmt_bind_result($s, $images);
    mysqli_stmt_fetch($s);
    mysqli_stmt_close($s);
}

$s = mysqli_prepare($con, "SELECT COUNT(*) FROM media WHERE type='video'");
if ($s) {
    mysqli_stmt_execute($s);
    mysqli_stmt_bind_result($s, $videos);
    mysqli_stmt_fetch($s);
    mysqli_stmt_close($s);
}

$s = mysqli_prepare($con, "SELECT COUNT(*) FROM media WHERE is_public=1");
if ($s) {
    mysqli_stmt_execute($s);
    mysqli_stmt_bind_result($s, $publics);
    mysqli_stmt_fetch($s);
    mysqli_stmt_close($s);
}

$catList = [];
$cqr = mysqli_query($con, "SELECT id, name FROM media_categories ORDER BY name ASC");
while ($cqr && $row = mysqli_fetch_assoc($cqr)) {
    if (isset($row['name']) && is_string($row['name'])) {
        $row['name'] = emsp_fix_mojibake($row['name']);
    }
    $catList[] = $row;
}

$sql = "SELECT m.id, m.title, m.description, m.type, m.file_path, m.poster_path, m.category, m.category_id, m.is_public, m.status, m.created_at,
               u.first_name, u.last_name, mc.name AS cat_name
        FROM media m
        LEFT JOIN users u ON u.id = m.created_by
        LEFT JOIN media_categories mc ON mc.id = m.category_id";

$where = '';
$paramType = '';
$paramValue = null;

if ($filter === 'image' || $filter === 'video') {
    $where = ' WHERE m.type=?';
    $paramType = 's';
    $paramValue = $filter;
} elseif ($filter === 'public' || $filter === 'private') {
    $where = ' WHERE m.is_public=?';
    $paramType = 'i';
    $paramValue = $filter === 'public' ? 1 : 0;
}

$sql .= $where . ' ORDER BY m.created_at DESC, m.id DESC';
$rows = [];
$s = mysqli_prepare($con, $sql);
if ($s) {
    if ($paramType === 's') {
        mysqli_stmt_bind_param($s, 's', $paramValue);
    } elseif ($paramType === 'i') {
        mysqli_stmt_bind_param($s, 'i', $paramValue);
    }
    mysqli_stmt_execute($s);
    $rows = emsp_stmt_fetch_all($s);
    mysqli_stmt_close($s);
}
foreach ($rows as &$row) {
    foreach (['title','description','category','cat_name','first_name','last_name'] as $f) {
        if (isset($row[$f]) && is_string($row[$f])) {
            $row[$f] = emsp_fix_mojibake($row[$f]);
        }
    }
}
unset($row);

$typeBadge = [
    'image' => 'bg-success',
    'video' => 'bg-primary',
    'lien' => 'bg-secondary',
];

$page_title = 'MÃ©diathÃ¨que';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
include __DIR__ . '/includes/navbar-top.php';
?>

<style>
.emsp-media-card {
    border-radius: 1.5rem;
    overflow: hidden;
}

.emsp-media-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}

.emsp-media-filters {
    display: flex;
    flex-wrap: wrap;
    gap: .75rem;
}

.emsp-media-table-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.emsp-media-table {
    min-width: 980px;
    table-layout: auto;
}

.emsp-media-table th {
    white-space: nowrap;
    word-break: normal;
    overflow-wrap: normal;
    font-weight: 700;
}

.emsp-media-table td {
    vertical-align: middle;
    word-break: normal;
    overflow-wrap: break-word;
}

.emsp-media-mode-switch {
    display: flex;
    flex-wrap: wrap;
    gap: .75rem;
}

.emsp-media-mode-switch .form-check {
    margin: 0;
}

.emsp-media-col-preview { width: 92px; min-width: 92px; }
.emsp-media-col-title { min-width: 260px; }
.emsp-media-col-type { width: 110px; min-width: 110px; }
.emsp-media-col-category { min-width: 150px; }
.emsp-media-col-visibility { width: 130px; min-width: 130px; }
.emsp-media-col-date { min-width: 160px; }
.emsp-media-col-actions { width: 120px; min-width: 120px; }

.emsp-media-thumb {
    width: 60px;
    height: 50px;
    object-fit: cover;
    border-radius: 10px;
    display: block;
}

.emsp-media-title {
    font-weight: 700;
    color: #172b4d;
    max-width: 280px;
    overflow-wrap: anywhere;
}

.emsp-media-desc,
.emsp-media-category,
.emsp-media-owner {
    max-width: 220px;
    overflow-wrap: anywhere;
}

.emsp-media-visibility .btn,
.emsp-media-actions {
    white-space: nowrap;
}

.emsp-upload-card {
    position: sticky;
    top: 96px;
}

@media (max-width: 1399.98px) {
    .emsp-media-table { min-width: 920px; }
}

@media (max-width: 1199.98px) {
    .emsp-upload-card { position: static; }
}

@media (max-width: 767.98px) {
    .emsp-media-filters {
        flex-wrap: nowrap;
        overflow-x: auto;
        padding-bottom: .25rem;
        -webkit-overflow-scrolling: touch;
    }

    .emsp-media-filters::-webkit-scrollbar {
        display: none;
    }

    .emsp-media-card .card-header,
    .emsp-upload-card .card-header {
        padding: .95rem 1rem;
    }

    .emsp-upload-card .card-body {
        padding: 1rem;
    }

    .emsp-media-mode-switch {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: .75rem;
    }

    .emsp-media-mode-switch .form-check {
        position: relative;
        min-height: 100%;
        padding: .8rem .85rem .8rem 2.2rem;
        border: 1px solid rgba(26, 60, 110, .12);
        border-radius: 14px;
        background: #f8fbff;
    }

    .emsp-media-mode-switch .form-check-input {
        margin-top: .1rem;
        margin-left: -1.4rem;
    }

    #dropZone {
        padding: 1rem !important;
        border-radius: 16px !important;
    }

    #dropZone .fs-3 {
        font-size: 1.6rem !important;
    }

    #dropZone .small + .small {
        margin-top: .2rem;
    }

    #filePreview {
        padding: .85rem;
        border: 1px solid rgba(26, 60, 110, .08);
        border-radius: 14px;
        background: #f8fbff;
    }

    #mediaUploadForm .row.g-2 {
        --bs-gutter-y: .75rem;
    }

    #mediaUploadForm .col-6 {
        width: 100%;
    }

    #mediaUploadForm .btn.w-100 {
        min-height: 46px;
    }
}
</style>

<div id="admin-content">
    <div id="main-content" class="container-fluid">
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body text-center">
                    <div class="fs-3 fw-bold text-dark"><?= intval($total) ?></div>
                    <div class="small text-muted">Total medias</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body text-center">
                    <div class="fs-3 fw-bold text-success"><?= intval($images) ?></div>
                    <div class="small text-muted">Images</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body text-center">
                    <div class="fs-3 fw-bold text-primary"><?= intval($videos) ?></div>
                    <div class="small text-muted">Videos</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body text-center">
                    <div class="fs-3 fw-bold text-warning"><?= intval($publics) ?></div>
                    <div class="small text-muted">Publics</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 align-items-start">
        <div class="col-xl-8">
            <div class="card shadow-sm border-0 emsp-media-card">
                <div class="card-header bg-white emsp-media-toolbar">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-images me-2 text-primary"></i>Medias</h6>
                    <div class="emsp-media-filters">
                        <a class="btn btn-sm <?= $filter === 'all' ? 'btn-dark' : 'btn-outline-dark' ?>" href="mediatheque.php?filter=all">Tous</a>
                        <a class="btn btn-sm <?= $filter === 'image' ? 'btn-success' : 'btn-outline-success' ?>" href="mediatheque.php?filter=image">Images</a>
                        <a class="btn btn-sm <?= $filter === 'video' ? 'btn-primary' : 'btn-outline-primary' ?>" href="mediatheque.php?filter=video">Videos</a>
                        <a class="btn btn-sm <?= $filter === 'public' ? 'btn-warning text-dark' : 'btn-outline-warning' ?>" href="mediatheque.php?filter=public">Publics</a>
                        <a class="btn btn-sm <?= $filter === 'private' ? 'btn-secondary' : 'btn-outline-secondary' ?>" href="mediatheque.php?filter=private">Prives</a>
                    </div>
                </div>
                <div class="table-responsive emsp-media-table-wrap d-none d-md-block">
                    <table class="table table-hover align-middle mb-0 emsp-media-table">
                        <thead class="table-light">
                            <tr>
                                <th class="emsp-media-col-preview">Apercu</th>
                                <th class="emsp-media-col-title">Titre</th>
                                <th class="emsp-media-col-type">Type</th>
                                <th class="emsp-media-col-category">Categorie</th>
                                <th class="emsp-media-col-visibility">Visibilite</th>
                                <th class="emsp-media-col-date">Date</th>
                                <th class="emsp-media-col-actions">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-5 text-muted">
                                        <i class="bi bi-images fs-2 d-block mb-2"></i>
                                        Aucun media trouve pour ce filtre.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($rows as $row): ?>
                                    <?php
                                        $src = emsp_media_admin_href((string) ($row['file_path'] ?? ''));
                                        $isImage = (string) $row['type'] === 'image' && $src !== '';
                                        $displayName = trim((string) (($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')));
                                        $linkedCategory = trim((string) ($row['cat_name'] ?? ''));
                                        $freeCategory = trim((string) ($row['category'] ?? ''));
                                        $categoryLabel = $linkedCategory !== '' ? $linkedCategory : $freeCategory;
                                    ?>
                                    <tr>
                                        <td class="emsp-media-col-preview">
                                            <?php if ($isImage): ?>
                                                <img src="<?= htmlspecialchars($src) ?>" alt="Apercu" class="emsp-media-thumb">
                                            <?php elseif ((string) $row['type'] === 'video'): ?>
                                                <div class="d-inline-flex align-items-center justify-content-center rounded bg-primary-subtle text-primary emsp-media-thumb">
                                                    <i class="bi bi-camera-reels"></i>
                                                </div>
                                            <?php else: ?>
                                                <div class="d-inline-flex align-items-center justify-content-center rounded bg-secondary-subtle text-secondary emsp-media-thumb">
                                                    <i class="bi bi-link-45deg"></i>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="emsp-media-col-title">
                                            <div class="emsp-media-title"><?= htmlspecialchars((string) $row['title']) ?></div>
                                            <?php if (!empty($row['description'])): ?>
                                                <div class="small text-muted emsp-media-desc"><?= htmlspecialchars(mb_substr((string) $row['description'], 0, 90)) ?><?= mb_strlen((string) $row['description']) > 90 ? '...' : '' ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="emsp-media-col-type">
                                            <span class="badge <?= $typeBadge[(string) $row['type']] ?? 'bg-secondary' ?>">
                                                <?= htmlspecialchars(ucfirst((string) $row['type'])) ?>
                                            </span>
                                        </td>
                                        <td class="emsp-media-col-category">
                                            <div class="emsp-media-category">
                                                <?= $categoryLabel !== '' ? htmlspecialchars($categoryLabel) : '<span class="text-muted">-</span>' ?>
                                            </div>
                                        </td>
                                        <td class="emsp-media-col-visibility emsp-media-visibility">
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                <input type="hidden" name="toggle_visibility" value="<?= intval($row['id']) ?>">
                                                <button type="submit" class="btn btn-sm <?= intval($row['is_public']) === 1 ? 'btn-warning text-dark' : 'btn-outline-secondary' ?>">
                                                    <i class="bi <?= intval($row['is_public']) === 1 ? 'bi-eye-fill' : 'bi-eye-slash' ?> me-1"></i>
                                                    <?= intval($row['is_public']) === 1 ? 'Public' : 'Prive' ?>
                                                </button>
                                            </form>
                                        </td>
                                        <td class="emsp-media-col-date">
                                            <div class="small"><?= date('d/m/Y', strtotime((string) $row['created_at'])) ?></div>
                                            <?php if ($displayName !== ''): ?>
                                                <div class="small text-muted emsp-media-owner"><?= htmlspecialchars($displayName) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="emsp-media-col-actions">
                                            <div class="d-flex gap-1 emsp-media-actions">
                                                <?php if ($src !== ''): ?>
                                                    <a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener" href="<?= htmlspecialchars($src) ?>" title="Voir">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                <?php endif; ?>
                                                  <form method="post" class="d-inline">
                                                      <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                      <input type="hidden" name="delete_media" value="<?= intval($row['id']) ?>">
                                                      <button type="submit"
                                                              class="btn btn-sm btn-outline-danger"
                                                              title="Supprimer"
                                                              data-confirm="Supprimer ce mÃ©dia ?"
                                                              data-confirm-detail="Cette action est irrÃ©versible."
                                                              data-confirm-type="danger"
                                                              data-confirm-ok="Oui, supprimer"
                                                              data-emsp-confirm-auto="1">
                                                          <i class="bi bi-trash"></i>
                                                      </button>
                                                  </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-body d-md-none">
                    <?php if (empty($rows)): ?>
                        <div class="emsp-admin-mobile-empty">Aucun media trouve pour ce filtre.</div>
                    <?php else: ?>
                        <div class="emsp-admin-mobile-list">
                            <?php foreach ($rows as $row): ?>
                                <?php
                                    $src = emsp_media_admin_href((string) ($row['file_path'] ?? ''));
                                    $isImage = (string) $row['type'] === 'image' && $src !== '';
                                    $displayName = trim((string) (($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')));
                                    $linkedCategory = trim((string) ($row['cat_name'] ?? ''));
                                    $freeCategory = trim((string) ($row['category'] ?? ''));
                                    $categoryLabel = $linkedCategory !== '' ? $linkedCategory : $freeCategory;
                                ?>
                                <div class="emsp-admin-mobile-card">
                                    <div class="emsp-admin-mobile-card-header">
                                        <div class="d-flex align-items-center gap-3">
                                            <?php if ($isImage): ?>
                                                <img src="<?= htmlspecialchars($src) ?>" alt="Apercu" class="emsp-media-thumb">
                                            <?php elseif ((string) $row['type'] === 'video'): ?>
                                                <div class="d-inline-flex align-items-center justify-content-center rounded bg-primary-subtle text-primary emsp-media-thumb">
                                                    <i class="bi bi-camera-reels"></i>
                                                </div>
                                            <?php else: ?>
                                                <div class="d-inline-flex align-items-center justify-content-center rounded bg-secondary-subtle text-secondary emsp-media-thumb">
                                                    <i class="bi bi-link-45deg"></i>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <h3 class="emsp-admin-mobile-card-title mb-0"><?= htmlspecialchars((string) $row['title']) ?></h3>
                                                <div class="emsp-admin-mobile-card-subtitle"><?= htmlspecialchars(date('d/m/Y', strtotime((string) $row['created_at']))) ?></div>
                                            </div>
                                        </div>
                                        <span class="badge <?= $typeBadge[(string) $row['type']] ?? 'bg-secondary' ?>">
                                            <?= htmlspecialchars(ucfirst((string) $row['type'])) ?>
                                        </span>
                                    </div>
                                    <div class="emsp-admin-mobile-meta">
                                        <div class="emsp-admin-mobile-meta-item">
                                            <span class="emsp-admin-mobile-meta-label">Categorie</span>
                                            <span class="emsp-admin-mobile-meta-value"><?= $categoryLabel !== '' ? htmlspecialchars($categoryLabel) : 'Non renseignee' ?></span>
                                        </div>
                                        <div class="emsp-admin-mobile-meta-item">
                                            <span class="emsp-admin-mobile-meta-label">Visibilite</span>
                                            <span class="emsp-admin-mobile-meta-value"><?= intval($row['is_public']) === 1 ? 'Public' : 'Prive' ?></span>
                                        </div>
                                        <?php if ($displayName !== ''): ?>
                                            <div class="emsp-admin-mobile-meta-item">
                                                <span class="emsp-admin-mobile-meta-label">Auteur</span>
                                                <span class="emsp-admin-mobile-meta-value"><?= htmlspecialchars($displayName) ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($row['description'])): ?>
                                            <div class="emsp-admin-mobile-meta-item">
                                                <span class="emsp-admin-mobile-meta-label">Description</span>
                                                <span class="emsp-admin-mobile-meta-value"><?= htmlspecialchars(mb_strlen((string) $row['description']) > 140 ? (mb_substr((string) $row['description'], 0, 140) . '...') : (string) $row['description']) ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="emsp-admin-mobile-actions">
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                            <input type="hidden" name="toggle_visibility" value="<?= intval($row['id']) ?>">
                                            <button type="submit" class="btn btn-sm <?= intval($row['is_public']) === 1 ? 'btn-warning text-dark' : 'btn-outline-secondary' ?>">
                                                <i class="bi <?= intval($row['is_public']) === 1 ? 'bi-eye-fill' : 'bi-eye-slash' ?> me-1"></i>
                                                <?= intval($row['is_public']) === 1 ? 'Public' : 'Prive' ?>
                                            </button>
                                        </form>
                                        <?php if ($src !== ''): ?>
                                            <a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener" href="<?= htmlspecialchars($src) ?>">
                                                <i class="bi bi-eye me-1"></i>Voir
                                            </a>
                                        <?php endif; ?>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                            <input type="hidden" name="delete_media" value="<?= intval($row['id']) ?>">
                                            <button type="submit"
                                                    class="btn btn-sm btn-outline-danger"
                                                    data-confirm="Supprimer ce media ?"
                                                    data-confirm-detail="Cette action est irreversible."
                                                    data-confirm-type="danger"
                                                    data-confirm-ok="Oui, supprimer"
                                                    data-emsp-confirm-auto="1">
                                                <i class="bi bi-trash me-1"></i>Supprimer
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card shadow-sm border-0 emsp-upload-card">
                <div class="card-header bg-white">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-cloud-arrow-up me-2 text-primary"></i>Ajouter un media</h6>
                </div>
                <div class="card-body">
                    <form method="post" enctype="multipart/form-data" id="mediaUploadForm">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <input type="hidden" name="upload_media" value="1">

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Mode</label>
                            <div class="emsp-media-mode-switch">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="upload_mode" id="mode_file" value="file" checked>
                                    <label class="form-check-label" for="mode_file">Upload fichier</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="upload_mode" id="mode_link" value="link">
                                    <label class="form-check-label" for="mode_link">Lien</label>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3" id="dropWrapper">
                            <label class="form-label fw-semibold">Fichier</label>
                            <div id="dropZone" class="border border-2 rounded p-3 text-center emsp-dropzone">
                                <i class="bi bi-cloud-upload fs-3 d-block text-muted"></i>
                                <div class="small">Glisser-deposer ou cliquer</div>
                                <div class="small text-muted">JPG PNG WEBP GIF MP4 WEBM OGV MOV AVI MKV</div>
                                <div class="small text-muted">Images max <?= $imgLimitMb ?> Mo Â· Videos max <?= $vidLimitMb ?> Mo</div>
                                <div class="small text-muted">(limite serveur detectee : <?= $serverMaxUploadMb ?> Mo)</div>
                                <input class="d-none" type="file" name="media_file" id="mediaFileInput" accept=".jpg,.jpeg,.png,.webp,.gif,.mp4,.webm,.ogv,.mov,.avi,.mkv,image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm,video/ogg,video/quicktime,video/x-msvideo,video/x-matroska">
                            </div>
                            <div id="fileTypeError" class="alert alert-danger small py-2 mt-2 d-none"></div>
                            <div id="filePreview" class="mt-2 d-none">
                                <img id="previewImage" class="img-fluid rounded d-none emsp-preview-image-max150" alt="preview">
                                <div id="previewText" class="small text-muted"></div>
                            </div>
                        </div>

                        <?php if ($vidLimitMb < 10): ?>
                            <div class="alert alert-warning small py-2">
                                Le serveur limite les uploads a <?= $vidLimitMb ?> Mo.
                                Pour uploader des videos lourdes, contactez l'administrateur
                                pour augmenter `upload_max_filesize` dans `php.ini` ou `.htaccess`.
                            </div>
                        <?php endif; ?>

                        <div class="mb-3 d-none" id="linkWrapper">
                            <label class="form-label fw-semibold">Lien ou chemin</label>
                            <input type="text" class="form-control" name="external_path" placeholder="https://... ou uploads/media/...">
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Titre</label>
                            <input type="text" class="form-control" name="title" id="mediaTitleInput" maxlength="200" required>
                        </div>

                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label fw-semibold">Type</label>
                                <select class="form-select" name="type" id="mediaTypeSelect">
                                    <option value="image">Image</option>
                                    <option value="video">Video</option>
                                    <option value="lien">Lien</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold">Categorie</label>
                                <select class="form-select" name="category_id" data-emsp-select2="1" data-emsp-select2-placeholder="Aucune">
                                    <option value="0">Aucune</option>
                                    <?php foreach ($catList as $c): ?>
                                    <option value="<?= intval($c['id']) ?>"><?= htmlspecialchars((string)$c['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Ou prÃ©ciser ci-dessous :</small>
                                <input type="text" class="form-control mt-1" name="category" maxlength="100" placeholder="Nouvelle catÃ©gorie">
                            </div>
                        </div>

                        <div class="mt-3">
                            <label class="form-label fw-semibold">Description</label>
                            <textarea class="form-control" name="description" rows="3" placeholder="Description du media"></textarea>
                        </div>

                        <div class="row g-2 mt-1">
                            <div class="col-6">
                                <label class="form-label fw-semibold">Statut</label>
                                <select class="form-select" name="status">
                                    <?php foreach ($statusOptions as $opt): ?>
                                        <?php
                                            $label = $opt === 'published' ? 'Publie' : ($opt === 'archived' ? 'Archive' : ucfirst($opt));
                                        ?>
                                        <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6 d-flex align-items-end">
                                <div class="form-check form-switch mb-2">
                                    <input class="form-check-input" type="checkbox" name="is_public" id="isPublicSwitch" value="1" checked>
                                    <label class="form-check-label" for="isPublicSwitch">Public</label>
                                </div>
                            </div>
                        </div>

                        <button class="btn btn-primary w-100 mt-3" type="submit">
                            <i class="bi bi-upload me-1"></i>Publier le media
                        </button>

                        <div id="uploadProgressWrap" class="mt-3 emsp-hidden">
                            <div class="d-flex justify-content-between small text-muted mb-1">
                                <span id="uploadStatusText">Preparation de l'envoi...</span>
                                <span id="uploadPercent">0%</span>
                            </div>
                            <div class="progress emsp-progress-track-10">
                                <div id="uploadProgressBar"
                                     class="progress-bar progress-bar-striped progress-bar-animated bg-primary emsp-progress-bar-start"
                                     role="progressbar"></div>
                            </div>
                            <div id="uploadSpeedInfo" class="small text-muted mt-1"></div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

</div><!-- /#main-content -->
</div><!-- /#admin-content -->
<?php
$page_scripts = <<<HTML
<script>
(function () {
    const serverMaxBytes = {$serverMaxUpload};
    const imageMaxBytes = {$imgLimitBytes};
    const videoMaxBytes = {$vidLimitBytes};
    const modeFile = document.getElementById('mode_file');
    const modeLink = document.getElementById('mode_link');
    const dropWrapper = document.getElementById('dropWrapper');
    const linkWrapper = document.getElementById('linkWrapper');
    const dropZone = document.getElementById('dropZone');
    const fileInput = document.getElementById('mediaFileInput');
    const filePreview = document.getElementById('filePreview');
    const previewImage = document.getElementById('previewImage');
    const previewText = document.getElementById('previewText');
    const titleInput = document.getElementById('mediaTitleInput');
    const typeSelect = document.getElementById('mediaTypeSelect');
    const errorDiv = document.getElementById('fileTypeError');
    const form = document.getElementById('mediaUploadForm');
    const progressWrap = document.getElementById('uploadProgressWrap');
    const progressBar = document.getElementById('uploadProgressBar');
    const progressPercent = document.getElementById('uploadPercent');
    const progressStatus = document.getElementById('uploadStatusText');
    const progressSpeed = document.getElementById('uploadSpeedInfo');
    const submitBtn = form ? form.querySelector('[type="submit"]') : null;
    const originalBtnHtml = submitBtn ? submitBtn.innerHTML : '';
    const imageExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    const videoExt = ['mp4', 'webm', 'ogg', 'ogv', 'mov', 'avi', 'mkv'];
    const allowedExt = imageExt.concat(videoExt);
    const posterEndpoint = 'media-poster.php';
    const emspUi = window.emspUI || null;

    function showUiError(title, message) {
        if (emspUi && typeof emspUi.showError === 'function') {
            emspUi.showError(title, message);
            return;
        }
        window.alert((title ? title + ' - ' : '') + message);
    }

    function showUiSuccess(title, message) {
        if (emspUi && typeof emspUi.showSuccess === 'function') {
            emspUi.showSuccess(title, message);
        }
    }

    function capturePosterFromFile(file) {
        return new Promise(function (resolve) {
            if (!file) {
                resolve(null);
                return;
            }
            const objectUrl = URL.createObjectURL(file);
            const video = document.createElement('video');
            let settled = false;

            function cleanup() {
                if (settled) {
                    return;
                }
                settled = true;
                try { URL.revokeObjectURL(objectUrl); } catch (e) {}
                video.removeAttribute('src');
                video.load();
            }

            function finish(blob) {
                cleanup();
                resolve(blob || null);
            }

            video.preload = 'metadata';
            video.muted = true;
            video.playsInline = true;
            video.addEventListener('loadedmetadata', function () {
                if (isFinite(video.duration) && video.duration > 0.4) {
                    video.currentTime = Math.min(0.4, Math.max(video.duration * 0.08, 0.15));
                }
            }, { once: true });

            video.addEventListener('seeked', function () {
                try {
                    const canvas = document.createElement('canvas');
                    canvas.width = video.videoWidth || 640;
                    canvas.height = video.videoHeight || 360;
                    const ctx = canvas.getContext('2d');
                    if (!ctx) {
                        finish(null);
                        return;
                    }
                    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                    canvas.toBlob(function (blob) {
                        finish(blob);
                    }, 'image/jpeg', 0.85);
                } catch (e) {
                    finish(null);
                }
            }, { once: true });

            video.addEventListener('error', function () {
                finish(null);
            }, { once: true });

            video.src = objectUrl;
            video.load();
            setTimeout(function () {
                finish(null);
            }, 10000);
        });
    }

    function uploadPoster(mediaId, posterBlob) {
        if (!posterBlob || !mediaId) {
            return Promise.resolve(false);
        }
        const csrfField = form ? form.querySelector('input[name="csrf_token"]') : null;
        const csrfToken = csrfField ? csrfField.value : '';
        const fd = new FormData();
        fd.append('csrf_token', csrfToken);
        fd.append('media_id', String(mediaId));
        fd.append('poster_file', posterBlob, 'poster.jpg');
        return fetch(posterEndpoint, {
            method: 'POST',
            body: fd,
            credentials: 'include'
        })
            .then(function (r) { return r.json(); })
            .then(function (payload) { return !!(payload && payload.ok); })
            .catch(function () { return false; });
    }

    function showFileError(message) {
        if (!errorDiv) {
            return;
        }
        errorDiv.textContent = message;
        errorDiv.classList.remove('d-none');
        errorDiv.style.display = 'block';
        errorDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function clearFileError() {
        if (!errorDiv) {
            return;
        }
        errorDiv.textContent = '';
        errorDiv.classList.add('d-none');
        errorDiv.style.display = 'none';
    }

    function resetPreview() {
        filePreview.classList.add('d-none');
        previewImage.classList.add('d-none');
        previewImage.removeAttribute('src');
        previewText.textContent = '';
    }

    function toggleMode() {
        const linkMode = modeLink.checked || typeSelect.value === 'lien';
        dropWrapper.classList.toggle('d-none', linkMode);
        linkWrapper.classList.toggle('d-none', !linkMode);
        if (linkMode) {
            clearFileError();
        }
    }

    function setTitleFromFile(name) {
        if (titleInput.value.trim() !== '') {
            return;
        }
        titleInput.value = name.replace(/\.[^/.]+$/, '').replace(/[_-]+/g, ' ').trim();
    }

    function validateSelectedFile(file) {
        if (!file) {
            return true;
        }

        const ext = (file.name.split('.').pop() || '').toLowerCase();
        const isVideo = videoExt.includes(ext);
        const maxBytes = isVideo ? videoMaxBytes : imageMaxBytes;

        clearFileError();

        if (!allowedExt.includes(ext)) {
            showFileError('Format .' + ext + ' non supporte. Acceptes : MP4, WEBM, OGV, MOV, AVI, MKV, JPG, PNG, WEBP, GIF.');
            fileInput.value = '';
            resetPreview();
            return false;
        }

        if (file.size > maxBytes) {
            const maxMb = (maxBytes / 1024 / 1024).toFixed(1);
            const fileMb = (file.size / 1024 / 1024).toFixed(1);
            showFileError('Fichier trop lourd : ' + fileMb + ' Mo. Limite serveur : ' + maxMb + ' Mo.');
            fileInput.value = '';
            resetPreview();
            return false;
        }

        typeSelect.value = isVideo ? 'video' : 'image';
        return true;
    }

    function onFileSelected(file) {
        if (!file || !validateSelectedFile(file)) {
            return;
        }

        const ext = (file.name.split('.').pop() || '').toLowerCase();
        const isVideo = videoExt.includes(ext);

        setTitleFromFile(file.name);
        filePreview.classList.remove('d-none');
        previewText.textContent = file.name + ' - ' + Math.round(file.size / 1024) + ' Ko';

        if (!isVideo) {
            const reader = new FileReader();
            reader.onload = function (e) {
                previewImage.src = e.target.result;
                previewImage.classList.remove('d-none');
            };
            reader.readAsDataURL(file);
        } else {
            previewImage.classList.add('d-none');
            previewImage.removeAttribute('src');
        }

        toggleMode();
    }

    if (dropZone) {
        dropZone.addEventListener('click', function () {
            fileInput.click();
        });

        dropZone.addEventListener('dragover', function (e) {
            e.preventDefault();
            dropZone.classList.add('bg-light');
        });

        dropZone.addEventListener('dragleave', function () {
            dropZone.classList.remove('bg-light');
        });

        dropZone.addEventListener('drop', function (e) {
            e.preventDefault();
            dropZone.classList.remove('bg-light');
            const file = e.dataTransfer.files && e.dataTransfer.files[0];
            if (file) {
                fileInput.files = e.dataTransfer.files;
                onFileSelected(file);
            }
        });
    }

    fileInput.addEventListener('change', function () {
        onFileSelected(this.files[0]);
    });

    modeFile.addEventListener('change', function () {
        clearFileError();
        toggleMode();
    });
    modeLink.addEventListener('change', function () {
        typeSelect.value = 'lien';
        clearFileError();
        toggleMode();
    });
    typeSelect.addEventListener('change', function () {
        if (this.value === 'lien') {
            modeLink.checked = true;
        } else if (modeLink.checked) {
            modeFile.checked = true;
        }
        toggleMode();
    });

    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();

            const linkMode = modeLink.checked || typeSelect.value === 'lien';
            if (!linkMode) {
                const selectedFile = fileInput.files && fileInput.files[0];
                if (!selectedFile) {
                    showFileError('Veuillez selectionner un fichier avant l\'envoi.');
                    return;
                }
                if (!validateSelectedFile(selectedFile)) {
                    return;
                }
            } else {
                clearFileError();
            }

            const fd = new FormData(form);
            const xhr = new XMLHttpRequest();
            const startTime = Date.now();

            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = 'Envoi en cours...';
            }

            progressWrap.style.display = 'block';
            progressBar.style.width = '0%';
            progressBar.classList.remove('bg-danger', 'bg-success');
            progressBar.classList.add('bg-primary', 'progress-bar-animated', 'progress-bar-striped');
            progressPercent.textContent = '0%';
            progressStatus.textContent = 'Preparation de l\'envoi...';
            progressSpeed.textContent = '';
            progressWrap.scrollIntoView({ behavior: 'smooth', block: 'center' });

            xhr.timeout = 180000;

            xhr.upload.addEventListener('progress', function (ev) {
                if (!ev.lengthComputable) return;
                const p = Math.round((ev.loaded / ev.total) * 100);
                progressBar.style.width = p + '%';
                progressPercent.textContent = p + '%';

                const elapsed = (Date.now() - startTime) / 1000;
                if (elapsed > 0.5) {
                    const speed = ev.loaded / elapsed;
                    const remaining = speed > 0 ? (ev.total - ev.loaded) / speed : 0;
                    const mbps = (speed / 1024 / 1024).toFixed(1);
                    const remStr = remaining > 60
                        ? Math.round(remaining / 60) + ' min'
                        : Math.round(remaining) + 's';
                    progressSpeed.textContent = mbps + ' Mo/s - Reste ~' + remStr;
                }

                if (p >= 100) {
                    progressStatus.textContent = 'Traitement du fichier...';
                    progressBar.classList.remove('progress-bar-animated');
                }
            });

            xhr.addEventListener('load', function () {
                let data = null;
                try {
                    data = JSON.parse(xhr.responseText || '{}');
                } catch (err) {
                    data = null;
                }

                if (xhr.status >= 200 && xhr.status < 400 && data && data.ok) {
                    progressStatus.textContent = 'Media enregistre !';
                    progressBar.classList.remove('bg-primary', 'bg-danger');
                    progressBar.classList.add('bg-success');
                    progressPercent.textContent = '100%';
                    showUiSuccess(data.title || 'MÃ©dia ajoutÃ©', data.message || 'Le mÃ©dia a Ã©tÃ© enregistrÃ© avec succÃ¨s.');
                    const selectedFile = fileInput.files && fileInput.files[0];
                    const isVideo = selectedFile && videoExt.includes((selectedFile.name.split('.').pop() || '').toLowerCase());
                    const shouldCapturePoster = isVideo && data.media_type === 'video' && data.media_id;
                    if (shouldCapturePoster) {
                        progressStatus.textContent = 'Generation de la miniature video...';
                        capturePosterFromFile(selectedFile)
                            .then(function (blob) { return uploadPoster(data.media_id, blob); })
                            .then(function () {
                                setTimeout(function () {
                                    window.location.reload();
                                }, 400);
                            });
                    } else {
                        setTimeout(function () {
                            window.location.reload();
                        }, 800);
                    }
                    return;
                }

                let serverMessage = data && data.message
                    ? data.message
                    : ('Erreur serveur (' + xhr.status + ')');

                if (!data && typeof xhr.responseText === 'string' && xhr.responseText.trim().startsWith('<')) {
                    serverMessage = 'Le serveur a renvoye une page inattendue. Verifiez la session admin ou la configuration PHP d\\'upload.';
                }

                progressStatus.textContent = serverMessage;
                progressBar.classList.remove('bg-primary', 'bg-success');
                progressBar.classList.add('bg-danger');
                showFileError(serverMessage);
                showUiError(data && data.title ? data.title : 'Upload impossible', serverMessage);
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalBtnHtml || 'Reessayer';
                }
            });

            xhr.addEventListener('error', function () {
                progressStatus.textContent = 'Erreur reseau. Verifiez votre connexion.';
                progressBar.classList.remove('bg-primary', 'bg-success');
                progressBar.classList.add('bg-danger');
                showFileError('Erreur reseau. Verifiez votre connexion.');
                showUiError('Erreur rÃ©seau', 'VÃ©rifiez votre connexion puis rÃ©essayez.');
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalBtnHtml || 'Reessayer';
                }
            });

            xhr.addEventListener('timeout', function () {
                progressStatus.textContent = 'Upload trop long. Le serveur n\\'a pas repondu a temps.';
                progressBar.classList.remove('bg-primary', 'bg-success');
                progressBar.classList.add('bg-danger');
                showFileError('Upload trop long. Reessayez avec une video plus legere ou verifiez la limite serveur.');
                showUiError('Upload trop long', 'Le serveur n a pas rÃ©pondu Ã  temps. RÃ©essayez avec un mÃ©dia plus lÃ©ger.');
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalBtnHtml || 'Reessayer';
                }
            });

            xhr.open('POST', window.location.href);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.send(fd);
        });
    }

    toggleMode();
})();
</script>
HTML;
include __DIR__ . '/includes/footer.php';
?>



