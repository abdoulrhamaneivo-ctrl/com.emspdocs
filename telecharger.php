<?php
// ============================================================
// telecharger.php - livraison securisee des documents
// Modes :
//   ?preview=1  -> affichage inline (PDF/images)
//   ?raw=1      -> bytes pour Mammoth.js / SheetJS
//   ?download=1 -> telechargement force (POST + CSRF)
// ============================================================

declare(strict_types=1);

ob_start();

if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}

include_once __DIR__ . '/includes/bootstrap.php';

include_once __DIR__ . '/admin/config/dbcon.php';
include_once __DIR__ . '/includes/csrf.php';
include_once __DIR__ . '/includes/flash.php';

$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);
$is_download = (isset($_GET['download']) && $_GET['download'] === '1');
$is_preview  = (isset($_GET['preview']) && $_GET['preview'] === '1');
$is_raw      = (isset($_GET['raw']) && $_GET['raw'] === '1');
$is_stream   = ($is_download || $is_preview || $is_raw);

function emsp_telecharger_error(
    string $msg,
    int $code,
    bool $is_stream,
    bool $is_ajax,
    string $redirect = 'bibliotheque.php',
    string $title = 'Erreur',
    string $type = 'error'
): void {
    if ($is_stream || $is_ajax) {
        http_response_code($code);
        header('Content-Type: text/plain; charset=UTF-8');
        echo $msg;
    } else {
        flash_set($type, $title, $msg, $redirect, 'Retour');
        header('Location: ' . $redirect);
    }
    exit;
}

// 1) Recuperation id
$doc_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($doc_id <= 0) {
    emsp_telecharger_error(
        'ID document invalide',
        400,
        $is_stream,
        $is_ajax,
        'bibliotheque.php',
        'Document introuvable'
    );
}

// Sans mode explicite : ne jamais streamer un fichier.
if (!$is_stream) {
    header('Location: document.php?id=' . $doc_id);
    exit;
}

// 2) download=1 uniquement en POST + CSRF
if ($is_download) {
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'POST') {
        http_response_code(405);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Method Not Allowed';
        exit;
    }

    $sessionToken = (string)($_SESSION['csrf_token'] ?? '');
    $postToken = (string)($_POST['csrf_token'] ?? '');
    if ($sessionToken === '' || $postToken === '' || !hash_equals($sessionToken, $postToken)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Action non autorisee (CSRF).';
        exit;
    }
}

// 3) Lecture BDD
$stmt = mysqli_prepare(
    $con,
    "SELECT id, title, file_path, mime_type, status, is_public, uploader_id, doc_type
     FROM documents WHERE id=? LIMIT 1"
);
if (!$stmt) {
    emsp_telecharger_error(
        'Erreur serveur. Reessayez dans quelques minutes.',
        500,
        $is_stream,
        $is_ajax,
        'bibliotheque.php',
        'Erreur serveur'
    );
}
mysqli_stmt_bind_param($stmt, 'i', $doc_id);
mysqli_stmt_execute($stmt);

$doc = null;
if (function_exists('emsp_stmt_fetch_assoc')) {
    $doc = emsp_stmt_fetch_assoc($stmt);
} elseif (function_exists('mysqli_stmt_get_result')) {
    $res = mysqli_stmt_get_result($stmt);
    $doc = $res ? mysqli_fetch_assoc($res) : null;
} else {
    $id = null;
    $title = null;
    $file_path_db = null;
    $mime_type_db = null;
    $status = null;
    $is_public = null;
    $uploader_id = null;
    $doc_type = null;
    if (mysqli_stmt_bind_result($stmt, $id, $title, $file_path_db, $mime_type_db, $status, $is_public, $uploader_id, $doc_type)) {
        if (mysqli_stmt_fetch($stmt)) {
            $doc = [
                'id' => $id,
                'title' => $title,
                'file_path' => $file_path_db,
                'mime_type' => $mime_type_db,
                'status' => $status,
                'is_public' => $is_public,
                'uploader_id' => $uploader_id,
                'doc_type' => $doc_type ?? null,
            ];
        }
    }
}
mysqli_stmt_close($stmt);

if (!$doc) {
    emsp_telecharger_error(
        'Document introuvable',
        404,
        $is_stream,
        $is_ajax,
        'bibliotheque.php',
        'Fichier introuvable'
    );
}

// 4) Status : autoriser preview pour staff (admin/moderateur)
$is_authenticated = !empty($_SESSION['auth']);
$uid = $is_authenticated ? (int) ($_SESSION['auth_user']['id'] ?? 0) : 0;
$role = strtolower(trim((string)($_SESSION['auth_role'] ?? ($_SESSION['auth_user']['role'] ?? ''))));
$is_staff = in_array($role, ['admin','moderateur'], true);
$is_owner = $uid > 0 && $uid === (int) ($doc['uploader_id'] ?? 0);
$is_approved = ((string) ($doc['status'] ?? '') === 'approved');
$is_public_document = ((int) ($doc['is_public'] ?? 0) === 1);
$is_concours = ((string) ($doc['doc_type'] ?? '') === 'concours');

if (!$is_approved && !$is_owner && !$is_staff) {
    emsp_telecharger_error(
        'Document introuvable',
        404,
        $is_stream,
        $is_ajax,
        'bibliotheque.php',
        'Fichier introuvable'
    );
}

if (!$is_public_document && !$is_owner && !$is_staff) {
    if (!$is_authenticated) {
        emsp_telecharger_error(
            'Connexion requise',
            403,
            $is_stream,
            $is_ajax,
            'login.php',
            'Connexion requise',
            'warning'
        );
    }

    emsp_telecharger_error(
        'Acces non autorise a ce document.',
        403,
        $is_stream,
        $is_ajax,
        'bibliotheque.php',
        'Acces refuse',
        'warning'
    );
}

if (!$is_authenticated && !$is_concours) {
    emsp_telecharger_error(
        'Connexion requise pour acceder a ce document.',
        403,
        $is_stream,
        $is_ajax,
        'concours.php',
        'Acces reserve',
        'warning'
    );
}

// 6) Chemin reel securise (anti path traversal)
$uploads_root = realpath(__DIR__ . '/uploads/documents');
if ($uploads_root === false) {
    $uploads_root = realpath(__DIR__ . '/../uploads/documents');
}
if ($uploads_root === false) {
    emsp_telecharger_error(
        'Erreur serveur.',
        500,
        $is_stream,
        $is_ajax,
        'bibliotheque.php',
        'Erreur serveur'
    );
}

$raw_path = trim((string)($doc['file_path'] ?? ''));
if ($raw_path === '') {
    emsp_telecharger_error(
        'Fichier introuvable',
        404,
        $is_stream,
        $is_ajax,
        'bibliotheque.php',
        'Fichier introuvable'
    );
}

if (preg_match('#^https?://#i', trim($raw_path))) {
    emsp_telecharger_error('Fichier non local', 403, $is_stream, $is_ajax, 'bibliotheque.php', 'Erreur');
}

$raw_path = str_replace('\\', '/', $raw_path);
$pos = strpos($raw_path, 'uploads/documents/');
if ($pos !== false) {
    $raw_path = substr($raw_path, $pos + strlen('uploads/documents/'));
} elseif (strpos($raw_path, 'documents/') === 0) {
    $raw_path = substr($raw_path, strlen('documents/'));
}
$raw_path = ltrim($raw_path, '/');

$file_path = realpath($uploads_root . DIRECTORY_SEPARATOR . $raw_path);
$root_prefix = rtrim($uploads_root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

if (!$file_path || !is_file($file_path)) {
    emsp_telecharger_error(
        'Le fichier demande n existe plus sur le serveur.',
        404,
        $is_stream,
        $is_ajax,
        'bibliotheque.php',
        'Fichier introuvable'
    );
}
if (strpos($file_path, $root_prefix) !== 0) {
    emsp_telecharger_error(
        'Acces fichier non autorise.',
        403,
        $is_stream,
        $is_ajax,
        'bibliotheque.php',
        'Acces non autorise'
    );
}

// 7) MIME reel
$real_mime = '';
if (function_exists('emsp_detect_mime')) {
    $real_mime = (string) emsp_detect_mime($file_path);
}
if ($real_mime === '' && function_exists('finfo_open')) {
    $fi = finfo_open(FILEINFO_MIME_TYPE);
    if ($fi) {
        // The finfo handle is cleaned up automatically; no explicit close needed here.
        $real_mime = (string) finfo_file($fi, $file_path);
    }
}
if ($real_mime === '') {
    $real_mime = (string)($doc['mime_type'] ?? '');
}
if ($real_mime === '') {
    $real_mime = 'application/octet-stream';
}

// 7b) Normaliser le MIME par extension si necessaire (evite les downloads auto)
$ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
$ext_mime_map = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'bmp'  => 'image/bmp',
    'svg'  => 'image/svg+xml',
    'txt'  => 'text/plain',
    'csv'  => 'text/csv',
    'md'   => 'text/plain',
    'json' => 'application/json',
    'xml'  => 'application/xml',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
];
if ($real_mime === 'application/octet-stream' && isset($ext_mime_map[$ext])) {
    $real_mime = $ext_mime_map[$ext];
}

// 7c) Refuser preview/raw pour formats non supportes (evite telechargements auto)
if ($is_preview) {
    $is_ok = ($real_mime === 'application/pdf' || str_starts_with($real_mime, 'image/'));
    if (!$is_ok) {
        emsp_telecharger_error(
            'Apercu non disponible pour ce format.',
            415,
            $is_stream,
            $is_ajax,
            'document.php?id=' . $doc_id,
            'Apercu indisponible',
            'warning'
        );
    }
}
if ($is_raw) {
    $is_text = str_starts_with($real_mime, 'text/')
        || in_array($real_mime, ['application/json', 'application/xml'], true);
    $is_ok = $is_text
        || $real_mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        || $real_mime === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    if (!$is_ok) {
        emsp_telecharger_error(
            'Apercu non disponible pour ce format.',
            415,
            $is_stream,
            $is_ajax,
            'document.php?id=' . $doc_id,
            'Apercu indisponible',
            'warning'
        );
    }
}

// 8) Liberer la session + nettoyer buffer
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
while (ob_get_level() > 0) {
    ob_end_clean();
}

// 9) Headers
$file_size = @filesize($file_path);
if ($file_size === false || $file_size < 0) {
    emsp_telecharger_error(
        'Erreur de lecture du fichier. Reessayez dans quelques minutes.',
        500,
        $is_stream,
        $is_ajax,
        'bibliotheque.php',
        'Erreur serveur'
    );
}
$file_size = (int) $file_size;
// Construire le nom de fichier depuis le titre + extension du fichier reel
$title_base = ($doc['title'] ?? '') !== '' ? (string)$doc['title'] : 'document';
$real_ext   = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
$safe_title = preg_replace('/[^a-zA-Z0-9._-]/', '_', $title_base);
$safe_title = trim($safe_title, '_');
if ($safe_title === '') { $safe_title = 'document'; }
// Ajouter l'extension si elle n'est pas deja dans le titre
$current_ext = strtolower(pathinfo($safe_title, PATHINFO_EXTENSION));
if ($real_ext !== '' && $current_ext !== $real_ext) {
    $safe_name = $safe_title . '.' . $real_ext;
} else {
    $safe_name = $safe_title;
}

header('Content-Type: ' . $real_mime);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Accept-Ranges: bytes');

if ($is_download) {
    header('Content-Disposition: attachment; filename="' . $safe_name . '"');
} else {
    header('Content-Disposition: inline');
}

// Support HTTP Range (utile pour PDF.js)
$range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
$start = 0;
$end = max(0, $file_size - 1);
$is_partial = false;
if ($range !== '' && preg_match('/bytes\\s*=\\s*(\\d*)-(\\d*)/i', $range, $m)) {
    $rStart = $m[1] !== '' ? (int) $m[1] : null;
    $rEnd   = $m[2] !== '' ? (int) $m[2] : null;

    if ($rStart === null && $rEnd !== null) {
        $suffix = $rEnd;
        if ($suffix > 0) {
            $start = max(0, $file_size - $suffix);
            $end = $file_size - 1;
            $is_partial = true;
        }
    } else {
        if ($rStart !== null) {
            $start = $rStart;
            $is_partial = true;
        }
        if ($rEnd !== null) {
            $end = $rEnd;
            $is_partial = true;
        }
    }

    if ($is_partial) {
        if ($start < 0) { $start = 0; }
        if ($end >= $file_size) { $end = $file_size - 1; }
        if ($start > $end || $start >= $file_size) {
            http_response_code(416);
            header('Content-Range: bytes */' . $file_size);
            exit;
        }

        http_response_code(206);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $file_size);
        header('Content-Length: ' . (string) (($end - $start) + 1));
    }
}
if (!$is_partial) {
    header('Content-Length: ' . (string) $file_size);
}

// HEAD : headers uniquement
$req_method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($req_method === 'HEAD') {
    exit;
}

// 10) Enregistrer les stats avant le flux, car certains navigateurs / hebergeurs
// coupent le script des qu'un telechargement est lance cote client.
if ($is_download) {
    $stmt2 = mysqli_prepare($con, "UPDATE documents SET download_count = download_count + 1 WHERE id=?");
    if ($stmt2) {
        mysqli_stmt_bind_param($stmt2, 'i', $doc_id);
        mysqli_stmt_execute($stmt2);
        mysqli_stmt_close($stmt2);
    }
}

$action = $is_download ? 'download' : 'view';
if ($is_authenticated && $uid > 0) {
    $h = mysqli_prepare($con, "INSERT INTO history (user_id, document_id, action) VALUES (?,?,?) ON DUPLICATE KEY UPDATE created_at=NOW()");
    if ($h) {
        mysqli_stmt_bind_param($h, 'iis', $uid, $doc_id, $action);
        mysqli_stmt_execute($h);
        mysqli_stmt_close($h);
    }
}

ignore_user_abort(true);

// 11) Envoi fichier par chunks
$chunk = 8192;
$handle = fopen($file_path, 'rb');
if ($handle === false) {
    emsp_telecharger_error(
        'Erreur de lecture du fichier. Reessayez dans quelques minutes.',
        500,
        $is_stream,
        $is_ajax,
        'bibliotheque.php',
        'Erreur serveur'
    );
}

if ($is_partial) {
    fseek($handle, $start);
    $remaining = ($end - $start) + 1;
    while ($remaining > 0 && !feof($handle)) {
        $read = ($remaining > $chunk) ? $chunk : $remaining;
        $buf = fread($handle, $read);
        if ($buf === false || $buf === '') {
            break;
        }
        echo $buf;
        $remaining -= strlen($buf);
        flush();
    }
} else {
    while (!feof($handle)) {
        echo fread($handle, $chunk);
        flush();
    }
}
fclose($handle);

exit;




