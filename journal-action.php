<?php
include_once __DIR__ . '/includes/bootstrap.php';
include_once __DIR__ . '/admin/config/dbcon.php';
include_once __DIR__ . '/includes/journal_helpers.php';
include_once __DIR__ . '/includes/notif-helper.php';

function emsp_journal_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$userId = (int) ($_SESSION['auth_user']['id'] ?? 0);
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = strtolower(trim((string) ($_REQUEST['action'] ?? '')));
$journalId = (int) ($_REQUEST['journal_id'] ?? 0);

if ($method === 'GET' && $action === 'summary') {
    if ($journalId <= 0) {
        emsp_journal_json(['ok' => false, 'error' => 'id'], 422);
    }
    $summary = emsp_journal_fetch_public_summary($con, $journalId, $userId);
    if (!$summary) {
        emsp_journal_json(['ok' => false, 'error' => 'notfound'], 404);
    }
    emsp_journal_json(['ok' => true, 'summary' => $summary]);
}

if ($method !== 'POST') {
    emsp_journal_json(['ok' => false, 'error' => 'method'], 405);
}

$sessionToken = (string) ($_SESSION['csrf_token'] ?? '');
$postToken = (string) ($_POST['csrf_token'] ?? '');
if ($sessionToken === '' || $postToken === '' || !hash_equals($sessionToken, $postToken)) {
    emsp_journal_json(['ok' => false, 'error' => 'csrf', 'message' => 'Jeton de securite invalide.'], 403);
}
if ($userId <= 0) {
    emsp_journal_json(['ok' => false, 'error' => 'auth', 'message' => 'Connexion requise.'], 401);
}
if ($journalId <= 0) {
    emsp_journal_json(['ok' => false, 'error' => 'id', 'message' => 'Identifiant invalide.'], 422);
}

$summary = emsp_journal_fetch_public_summary($con, $journalId, $userId);
if (!$summary) {
    emsp_journal_json(['ok' => false, 'error' => 'notfound', 'message' => 'Contenu introuvable.'], 404);
}
if (empty($summary['state']['is_open'])) {
    emsp_journal_json([
        'ok' => false,
        'error' => 'closed',
        'message' => 'Ce contenu est ferme ou expire.',
        'state' => $summary['state'],
        'summary' => $summary,
    ], 409);
}

if ($action === 'vote' && ($summary['type'] ?? '') === 'sondage') {
    $optionId = (int) ($_POST['option_id'] ?? 0);
    if ($optionId <= 0) {
        emsp_journal_json(['ok' => false, 'error' => 'option', 'message' => 'Option invalide.'], 422);
    }

    $stmt = mysqli_prepare($con, 'SELECT 1 FROM journal_options WHERE id=? AND journal_id=? LIMIT 1');
    if (!$stmt) {
        emsp_journal_json(['ok' => false, 'error' => 'sql', 'message' => 'Erreur serveur.'], 500);
    }
    mysqli_stmt_bind_param($stmt, 'ii', $optionId, $journalId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    $validOption = mysqli_stmt_num_rows($stmt) > 0;
    mysqli_stmt_close($stmt);
    if (!$validOption) {
        emsp_journal_json(['ok' => false, 'error' => 'bad_option', 'message' => 'Option invalide.'], 422);
    }

    mysqli_begin_transaction($con);
    try {
        $stmt = mysqli_prepare($con, 'DELETE FROM journal_votes WHERE journal_id=? AND user_id=?');
        mysqli_stmt_bind_param($stmt, 'ii', $journalId, $userId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $stmt = mysqli_prepare($con, 'INSERT INTO journal_votes (journal_id, option_id, user_id) VALUES (?, ?, ?)');
        mysqli_stmt_bind_param($stmt, 'iii', $journalId, $optionId, $userId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        mysqli_commit($con);
    } catch (Throwable $e) {
        mysqli_rollback($con);
        emsp_journal_json(['ok' => false, 'error' => 'save', 'message' => 'Impossible d enregistrer le vote.'], 500);
    }

    $summary = emsp_journal_fetch_public_summary($con, $journalId, $userId);
    emsp_journal_json([
        'ok' => true,
        'summary' => $summary,
        'poll' => $summary['poll'] ?? null,
        'state' => $summary['state'] ?? null,
    ]);
}

if ($action === 'defi' && ($summary['type'] ?? '') === 'defi') {
    $note = trim((string) ($_POST['note'] ?? ''));

    mysqli_begin_transaction($con);
    try {
        $stmt = mysqli_prepare($con, 'DELETE FROM journal_defis WHERE journal_id=? AND user_id=?');
        mysqli_stmt_bind_param($stmt, 'ii', $journalId, $userId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        $stmt = mysqli_prepare($con, 'INSERT INTO journal_defis (journal_id, user_id, note) VALUES (?, ?, ?)');
        mysqli_stmt_bind_param($stmt, 'iis', $journalId, $userId, $note);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        mysqli_commit($con);
    } catch (Throwable $e) {
        mysqli_rollback($con);
        emsp_journal_json(['ok' => false, 'error' => 'save', 'message' => 'Impossible d enregistrer la participation.'], 500);
    }

    $summary = emsp_journal_fetch_public_summary($con, $journalId, $userId);
    emsp_journal_json([
        'ok' => true,
        'summary' => $summary,
        'defi' => $summary['defi'] ?? null,
        'state' => $summary['state'] ?? null,
    ]);
}

if ($action === 'like') {
    $stmt = mysqli_prepare($con, "SELECT id, title, admin_id, author_id FROM journal WHERE id=? LIMIT 1");
    if (!$stmt) {
        emsp_journal_json(['ok' => false, 'error' => 'sql', 'message' => 'Erreur serveur.'], 500);
    }
    mysqli_stmt_bind_param($stmt, 'i', $journalId);
    mysqli_stmt_execute($stmt);
    $row = emsp_stmt_fetch_assoc($stmt);
    mysqli_stmt_close($stmt);
    if (!$row) {
        emsp_journal_json(['ok' => false, 'error' => 'notfound', 'message' => 'Contenu introuvable.'], 404);
    }
    $ownerId = (int) ($row['admin_id'] ?? $row['author_id'] ?? 0);

    $exist = mysqli_prepare($con, "SELECT 1 FROM journal_likes WHERE journal_id=? AND user_id=? LIMIT 1");
    if (!$exist) {
        emsp_journal_json(['ok' => false, 'error' => 'sql', 'message' => 'Erreur serveur.'], 500);
    }
    mysqli_stmt_bind_param($exist, 'ii', $journalId, $userId);
    mysqli_stmt_execute($exist);
    mysqli_stmt_store_result($exist);
    $liked = mysqli_stmt_num_rows($exist) > 0;
    mysqli_stmt_close($exist);

    if ($liked) {
        $del = mysqli_prepare($con, "DELETE FROM journal_likes WHERE journal_id=? AND user_id=?");
        if ($del) {
            mysqli_stmt_bind_param($del, 'ii', $journalId, $userId);
            mysqli_stmt_execute($del);
            mysqli_stmt_close($del);
        }
        $liked = false;
    } else {
        $ins = mysqli_prepare($con, "INSERT IGNORE INTO journal_likes (journal_id, user_id) VALUES (?, ?)");
        if ($ins) {
            mysqli_stmt_bind_param($ins, 'ii', $journalId, $userId);
            mysqli_stmt_execute($ins);
            mysqli_stmt_close($ins);
        }
        $liked = true;
        notify_journal_liked($con, $ownerId, $journalId, (string) ($row['title'] ?? ''), $userId);
    }

    $count = 0;
    $cnt = mysqli_prepare($con, "SELECT COUNT(*) AS nb FROM journal_likes WHERE journal_id=?");
    if ($cnt) {
        mysqli_stmt_bind_param($cnt, 'i', $journalId);
        mysqli_stmt_execute($cnt);
        $c = emsp_stmt_fetch_assoc($cnt);
        mysqli_stmt_close($cnt);
        $count = (int) ($c['nb'] ?? 0);
    }

    emsp_journal_json([
        'ok' => true,
        'liked' => $liked,
        'like_count' => $count,
        'summary' => emsp_journal_fetch_public_summary($con, $journalId, $userId),
    ]);
}

if ($action === 'comment') {
    $content = trim((string) ($_POST['content'] ?? ''));
    if ($content === '') {
        emsp_journal_json(['ok' => false, 'error' => 'content', 'message' => 'Le commentaire est vide.'], 422);
    }
    $stmt = mysqli_prepare($con, "SELECT id, title, admin_id, author_id FROM journal WHERE id=? LIMIT 1");
    if (!$stmt) {
        emsp_journal_json(['ok' => false, 'error' => 'sql', 'message' => 'Erreur serveur.'], 500);
    }
    mysqli_stmt_bind_param($stmt, 'i', $journalId);
    mysqli_stmt_execute($stmt);
    $row = emsp_stmt_fetch_assoc($stmt);
    mysqli_stmt_close($stmt);
    if (!$row) {
        emsp_journal_json(['ok' => false, 'error' => 'notfound', 'message' => 'Contenu introuvable.'], 404);
    }
    $ownerId = (int) ($row['admin_id'] ?? $row['author_id'] ?? 0);

    $ins = mysqli_prepare($con, "INSERT INTO journal_comments (journal_id, user_id, content) VALUES (?, ?, ?)");
    if (!$ins) {
        emsp_journal_json(['ok' => false, 'error' => 'sql', 'message' => 'Erreur serveur.'], 500);
    }
    mysqli_stmt_bind_param($ins, 'iis', $journalId, $userId, $content);
    mysqli_stmt_execute($ins);
    $commentId = (int) mysqli_insert_id($con);
    mysqli_stmt_close($ins);

    notify_journal_commented($con, $ownerId, $journalId, (string) ($row['title'] ?? ''), $userId);
    $summary = emsp_journal_fetch_public_summary($con, $journalId, $userId);

    emsp_journal_json([
        'ok' => true,
        'comment_id' => $commentId,
        'summary' => $summary,
    ]);
}

emsp_journal_json(['ok' => false, 'error' => 'action', 'message' => 'Action invalide.'], 422);


