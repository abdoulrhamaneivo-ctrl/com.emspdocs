<?php
include_once __DIR__ . '/includes/bootstrap.php';
include_once __DIR__ . '/admin/config/dbcon.php';
include_once __DIR__ . '/includes/csrf.php';

header('Content-Type: application/json; charset=UTF-8');

function emsp_like_fail(string $error, string $message, int $status = 400): void
{
    emsp_json_response([
        'ok' => false,
        'error' => $error,
        'message' => $message,
    ], $status);
}

function emsp_like_success(bool $liked, int $count): void
{
    emsp_json_response([
        'ok' => true,
        'liked' => $liked,
        'like_count' => $count,
        'message' => $liked ? 'Document ajoute a vos likes.' : 'Like retire.',
    ]);
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    emsp_like_fail('method', 'Methode non autorisee.', 405);
}

if (empty($_SESSION['auth'])) {
    emsp_like_fail('non_connecte', 'Connectez-vous pour liker ce document.', 401);
}

$sessionToken = (string) ($_SESSION['csrf_token'] ?? '');
$postToken = (string) ($_POST['csrf_token'] ?? '');
if ($sessionToken === '' || $postToken === '' || !hash_equals($sessionToken, $postToken)) {
    emsp_like_fail('csrf', 'Jeton de securite invalide.', 403);
}

$uid = intval($_SESSION['auth_user']['id'] ?? 0);
$docId = intval($_POST['document_id'] ?? 0);
if ($uid <= 0 || $docId <= 0) {
    emsp_like_fail('invalid', 'Document invalide.');
}

// Maintenance: rely on the schema contract instead of probing information_schema on each request.
$docStmt = mysqli_prepare($con, "SELECT id, uploader_id, title FROM documents WHERE id=? AND status='approved' LIMIT 1");
if (!$docStmt) {
    emsp_like_fail('sql', 'Impossible de charger le document.', 500);
}
mysqli_stmt_bind_param($docStmt, 'i', $docId);
mysqli_stmt_execute($docStmt);
$doc = emsp_stmt_fetch_assoc($docStmt);
mysqli_stmt_close($docStmt);

if (!$doc) {
    emsp_like_fail('doc_not_found', 'Document introuvable ou non approuve.', 404);
}

$existsStmt = mysqli_prepare($con, "SELECT 1 FROM document_likes WHERE user_id=? AND document_id=? LIMIT 1");
if (!$existsStmt) {
    emsp_like_fail('sql', 'Impossible de verifier le like actuel.', 500);
}
mysqli_stmt_bind_param($existsStmt, 'ii', $uid, $docId);
mysqli_stmt_execute($existsStmt);
mysqli_stmt_store_result($existsStmt);
$alreadyLiked = mysqli_stmt_num_rows($existsStmt) > 0;
mysqli_stmt_close($existsStmt);

if ($alreadyLiked) {
    $deleteStmt = mysqli_prepare($con, "DELETE FROM document_likes WHERE user_id=? AND document_id=?");
    if (!$deleteStmt) {
        emsp_like_fail('sql', 'Impossible de retirer le like.', 500);
    }
    mysqli_stmt_bind_param($deleteStmt, 'ii', $uid, $docId);
    mysqli_stmt_execute($deleteStmt);
    mysqli_stmt_close($deleteStmt);

    $updateStmt = mysqli_prepare($con, "UPDATE documents SET like_count = GREATEST(like_count - 1, 0) WHERE id=?");
    if (!$updateStmt) {
        emsp_like_fail('sql', 'Impossible de recalculer le compteur de likes.', 500);
    }
    mysqli_stmt_bind_param($updateStmt, 'i', $docId);
    mysqli_stmt_execute($updateStmt);
    mysqli_stmt_close($updateStmt);

    $liked = false;
} else {
    $insertStmt = mysqli_prepare($con, "INSERT IGNORE INTO document_likes (user_id, document_id) VALUES (?, ?)");
    if (!$insertStmt) {
        emsp_like_fail('sql', 'Impossible d enregistrer le like.', 500);
    }
    mysqli_stmt_bind_param($insertStmt, 'ii', $uid, $docId);
    mysqli_stmt_execute($insertStmt);
    mysqli_stmt_close($insertStmt);

    $updateStmt = mysqli_prepare($con, "UPDATE documents SET like_count = like_count + 1 WHERE id=?");
    if (!$updateStmt) {
        emsp_like_fail('sql', 'Impossible de recalculer le compteur de likes.', 500);
    }
    mysqli_stmt_bind_param($updateStmt, 'i', $docId);
    mysqli_stmt_execute($updateStmt);
    mysqli_stmt_close($updateStmt);

    $liked = true;

    if ((int) ($doc['uploader_id'] ?? 0) !== $uid) {
        $me = trim((string) ($_SESSION['auth_user']['first_name'] ?? '') . ' ' . (string) ($_SESSION['auth_user']['last_name'] ?? ''));
        $title = emsp_fix_mojibake((string) ($doc['title'] ?? ''));
        $message = emsp_fix_mojibake($me) . ' a aime votre document "' . mb_substr($title, 0, 60) . '"';

        $notifStmt = mysqli_prepare(
            $con,
            "INSERT INTO notifications (user_id, type, document_id, from_user_id, message)
             VALUES (?, 'doc_liked', ?, ?, ?)"
        );
        if ($notifStmt) {
            $ownerId = (int) ($doc['uploader_id'] ?? 0);
            mysqli_stmt_bind_param($notifStmt, 'iiis', $ownerId, $docId, $uid, $message);
            mysqli_stmt_execute($notifStmt);
            mysqli_stmt_close($notifStmt);
        }
    }
}

$countStmt = mysqli_prepare($con, "SELECT like_count FROM documents WHERE id=? LIMIT 1");
if (!$countStmt) {
    emsp_like_fail('sql', 'Impossible de relire le compteur de likes.', 500);
}
mysqli_stmt_bind_param($countStmt, 'i', $docId);
mysqli_stmt_execute($countStmt);
$countRow = emsp_stmt_fetch_assoc($countStmt);
mysqli_stmt_close($countStmt);

emsp_like_success($liked, (int) ($countRow['like_count'] ?? 0));


