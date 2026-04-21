<?php
include_once __DIR__ . '/includes/bootstrap.php';
include_once __DIR__ . '/admin/config/dbcon.php';
include_once __DIR__ . '/includes/notif-helper.php';

header('Content-Type: application/json; charset=utf-8');

function emsp_reaction_fail(string $error, string $message, int $status = 400): void
{
    emsp_json_response([
        'ok' => false,
        'error' => $error,
        'message' => $message,
    ], $status);
}

function emsp_reaction_success(string $action, array $counts, ?string $myReaction): void
{
    emsp_json_response([
        'ok' => true,
        'action' => $action,
        'counts' => $counts,
        'my_reaction' => $myReaction,
        'total' => array_sum($counts),
        'message' => $action === 'removed'
            ? 'Reaction retiree.'
            : ($action === 'changed' ? 'Reaction mise a jour.' : 'Reaction enregistree.'),
    ]);
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    emsp_reaction_fail('method', 'Methode non autorisee.', 405);
}

if (empty($_SESSION['auth']) || empty($_SESSION['auth_user']['id'])) {
    emsp_reaction_fail('non_connecte', 'Connectez-vous pour reagir a ce commentaire.', 401);
}

$sessionToken = (string) ($_SESSION['csrf_token'] ?? '');
$postToken = (string) ($_POST['csrf_token'] ?? '');
if ($sessionToken === '' || $postToken === '' || !hash_equals($sessionToken, $postToken)) {
    emsp_reaction_fail('csrf', 'Jeton de securite invalide. Rechargez la page puis reessayez.', 403);
}

$uid = intval($_SESSION['auth_user']['id']);
$commentId = intval($_POST['comment_id'] ?? 0);
$reaction = trim((string) ($_POST['reaction'] ?? ''));
$allowed = ['like', 'love', 'haha', 'wow', 'sad', 'angry'];

if ($commentId <= 0 || !in_array($reaction, $allowed, true)) {
    emsp_reaction_fail('invalid', 'Reaction invalide.');
}

$s = mysqli_prepare($con, "SELECT id, user_id FROM comments WHERE id=? AND status='visible' LIMIT 1");
if (!$s) {
    emsp_reaction_fail('sql', 'Impossible de charger ce commentaire.', 500);
}
mysqli_stmt_bind_param($s, 'i', $commentId);
mysqli_stmt_execute($s);
$comment = emsp_stmt_fetch_assoc($s);
mysqli_stmt_close($s);

if (!$comment) {
    emsp_reaction_fail('comment_not_found', 'Commentaire introuvable ou non visible.', 404);
}

$s = mysqli_prepare($con, "SELECT reaction FROM comment_reactions WHERE comment_id=? AND user_id=? LIMIT 1");
if (!$s) {
    emsp_reaction_fail(
        'schema_missing',
        'Le module de reactions n est pas disponible. Importez la mise a jour SQL correspondante.',
        500
    );
}
mysqli_stmt_bind_param($s, 'ii', $commentId, $uid);
mysqli_stmt_execute($s);
$existing = emsp_stmt_fetch_assoc($s);
mysqli_stmt_close($s);

$action = 'added';
$commentOwner = (int) ($comment['user_id'] ?? 0);

if ($existing) {
    if ((string) ($existing['reaction'] ?? '') === $reaction) {
        $s = mysqli_prepare($con, "DELETE FROM comment_reactions WHERE comment_id=? AND user_id=?");
        if (!$s) {
            emsp_reaction_fail('sql', 'Impossible de retirer la reaction pour le moment.', 500);
        }
        mysqli_stmt_bind_param($s, 'ii', $commentId, $uid);
        mysqli_stmt_execute($s);
        mysqli_stmt_close($s);
        $action = 'removed';
    } else {
        $s = mysqli_prepare($con, "UPDATE comment_reactions SET reaction=?, created_at=NOW() WHERE comment_id=? AND user_id=?");
        if (!$s) {
            emsp_reaction_fail('sql', 'Impossible de mettre a jour la reaction pour le moment.', 500);
        }
        mysqli_stmt_bind_param($s, 'sii', $reaction, $commentId, $uid);
        mysqli_stmt_execute($s);
        mysqli_stmt_close($s);
        $action = 'changed';
    }
} else {
    $s = mysqli_prepare($con, "INSERT INTO comment_reactions (comment_id, user_id, reaction) VALUES (?, ?, ?)");
    if (!$s) {
        emsp_reaction_fail(
            'schema_missing',
            'Le module de reactions n est pas disponible. Importez la mise a jour SQL correspondante.',
            500
        );
    }
    mysqli_stmt_bind_param($s, 'iis', $commentId, $uid, $reaction);
    mysqli_stmt_execute($s);
    mysqli_stmt_close($s);
}

if ($commentOwner > 0 && $commentOwner !== $uid && in_array($action, ['added', 'changed'], true)) {
    $me = trim((string) ($_SESSION['auth_user']['first_name'] ?? '') . ' ' . (string) ($_SESSION['auth_user']['last_name'] ?? ''));
    $actor = $me !== '' ? $me : 'Quelqu un';
    $commentText = '';
    $docId = 0;
    $docTitle = '';

    $stmt = mysqli_prepare(
        $con,
        "SELECT c.content, c.document_id, d.title
         FROM comments c
         LEFT JOIN documents d ON d.id = c.document_id
         WHERE c.id=? LIMIT 1"
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $commentId);
        mysqli_stmt_execute($stmt);
        $row = emsp_stmt_fetch_assoc($stmt);
        mysqli_stmt_close($stmt);
        $commentText = emsp_notif_excerpt((string) ($row['content'] ?? ''), 80);
        $docId = (int) ($row['document_id'] ?? 0);
        $docTitle = emsp_notif_excerpt((string) ($row['title'] ?? ''), 60);
    }

    $msg = $actor . ' a reagi a votre commentaire';
    if ($docTitle !== '') {
        $msg .= ' sur "' . $docTitle . '"';
    }
    if ($commentText !== '') {
        $msg .= ' : "' . $commentText . '"';
    }

    send_notification($con, $commentOwner, 'comment_reacted', $msg, $docId, $commentId, null, $uid);
}

$counts = ['like' => 0, 'love' => 0, 'haha' => 0, 'wow' => 0, 'sad' => 0, 'angry' => 0];
$s = mysqli_prepare($con, "SELECT reaction, COUNT(*) AS nb FROM comment_reactions WHERE comment_id=? GROUP BY reaction");
if (!$s) {
    emsp_reaction_fail('sql', 'La reaction a ete enregistree mais le compteur n a pas pu etre recharge.', 500);
}
mysqli_stmt_bind_param($s, 'i', $commentId);
mysqli_stmt_execute($s);
$result = emsp_stmt_fetch_all($s);
foreach ($result as $row) {
    $key = (string) ($row['reaction'] ?? '');
    if (isset($counts[$key])) {
        $counts[$key] = intval($row['nb'] ?? 0);
    }
}
mysqli_stmt_close($s);

$myReaction = null;
$s = mysqli_prepare($con, "SELECT reaction FROM comment_reactions WHERE comment_id=? AND user_id=? LIMIT 1");
if (!$s) {
    emsp_reaction_fail('sql', 'La reaction a ete enregistree, mais l etat utilisateur n a pas pu etre relu.', 500);
}
mysqli_stmt_bind_param($s, 'ii', $commentId, $uid);
mysqli_stmt_execute($s);
$myRow = emsp_stmt_fetch_assoc($s);
if ($myRow) {
    $myReaction = (string) ($myRow['reaction'] ?? '');
}
mysqli_stmt_close($s);

emsp_reaction_success($action, $counts, $myReaction);


