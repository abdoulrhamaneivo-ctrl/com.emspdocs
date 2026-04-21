<?php

// Maintenance: keep the navbar section badges and dashboard counters aligned on the same
// notification taxonomy so Journal/Mediatheque never drift between server and UI layers.
function emsp_notification_section(string $type): ?string
{
    $type = strtolower(trim($type));
    if ($type === 'journal_published') {
        return 'journal';
    }
    if ($type === 'media_published') {
        return 'media';
    }
    return null;
}

function emsp_unread_notification_sections(mysqli $con, int $userId): array
{
    $counts = ['journal' => 0, 'media' => 0];
    if ($userId <= 0) {
        return $counts;
    }

    $stmt = mysqli_prepare(
        $con,
        "SELECT
            SUM(CASE WHEN type = 'journal_published' AND is_read=0 THEN 1 ELSE 0 END) AS journal_unread,
            SUM(CASE WHEN type = 'media_published' AND is_read=0 THEN 1 ELSE 0 END) AS media_unread
         FROM notifications
         WHERE user_id=?"
    );
    if (!$stmt) {
        return $counts;
    }

    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $row = emsp_stmt_fetch_assoc($stmt);
    mysqli_stmt_close($stmt);

    return [
        'journal' => max(0, (int) ($row['journal_unread'] ?? 0)),
        'media' => max(0, (int) ($row['media_unread'] ?? 0)),
    ];
}

function emsp_sync_notification_counters(mysqli $con, int $userId): void
{
    if ($userId <= 0) {
        emsp_session_set_notif_count(0);
        emsp_session_set_notif_sections(['journal' => 0, 'media' => 0]);
        return;
    }

    $notifCount = 0;
    $countStmt = mysqli_prepare($con, "SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
    if ($countStmt) {
        mysqli_stmt_bind_param($countStmt, 'i', $userId);
        mysqli_stmt_execute($countStmt);
        mysqli_stmt_bind_result($countStmt, $notifCountRaw);
        mysqli_stmt_fetch($countStmt);
        mysqli_stmt_close($countStmt);
        $notifCount = max(0, (int) $notifCountRaw);
    }

    emsp_session_set_notif_count($notifCount);
    emsp_session_set_notif_sections(emsp_unread_notification_sections($con, $userId));
    $_SESSION['emsp_notif_sections_fetched_at'] = time();
}

function emsp_mark_notifications_seen_for_section(mysqli $con, int $userId, string $section): int
{
    $section = strtolower(trim($section));
    $types = match ($section) {
        'journal' => ['journal_published'],
        'media' => ['media_published'],
        default => [],
    };

    if ($userId <= 0 || empty($types)) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($types), '?'));
    $sql = "UPDATE notifications
            SET is_read=1
            WHERE user_id=?
              AND is_read=0
              AND type IN ($placeholders)";
    $stmt = mysqli_prepare($con, $sql);
    if (!$stmt) {
        return 0;
    }

    $typesDef = 'i' . str_repeat('s', count($types));
    $bindValues = array_merge([$userId], $types);
    $bindParams = [$typesDef];
    foreach ($bindValues as $index => $value) {
        $bindParams[] = &$bindValues[$index];
    }

    mysqli_stmt_bind_param($stmt, ...$bindParams);
    mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);

    if ($affected > 0) {
        emsp_sync_notification_counters($con, $userId);
    }

    return max(0, (int) $affected);
}

function emsp_mark_notifications_seen_for_journal(mysqli $con, int $userId, int $journalId): int
{
    if ($userId <= 0 || $journalId <= 0) {
        return 0;
    }

    $types = ['journal_published', 'journal_liked', 'journal_commented'];
    $placeholders = implode(',', array_fill(0, count($types), '?'));
    $sql = "UPDATE notifications
            SET is_read=1
            WHERE user_id=?
              AND document_id=?
              AND is_read=0
              AND type IN ($placeholders)";
    $stmt = mysqli_prepare($con, $sql);
    if (!$stmt) {
        return 0;
    }

    $bindValues = array_merge([$userId, $journalId], $types);
    $bindParams = ['ii' . str_repeat('s', count($types))];
    foreach ($bindValues as $index => $value) {
        $bindParams[] = &$bindValues[$index];
    }

    mysqli_stmt_bind_param($stmt, ...$bindParams);
    mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);

    if ($affected > 0) {
        emsp_sync_notification_counters($con, $userId);
    }

    return max(0, (int) $affected);
}

function emsp_mark_notifications_seen_for_document(mysqli $con, int $userId, int $documentId): int
{
    if ($userId <= 0 || $documentId <= 0) {
        return 0;
    }

    $types = ['doc_approved', 'doc_rejected', 'new_comment', 'comment_reply', 'doc_liked', 'comment_reacted'];
    $placeholders = implode(',', array_fill(0, count($types), '?'));
    $sql = "UPDATE notifications
            SET is_read=1
            WHERE user_id=?
              AND document_id=?
              AND is_read=0
              AND type IN ($placeholders)";
    $stmt = mysqli_prepare($con, $sql);
    if (!$stmt) {
        return 0;
    }

    $bindValues = array_merge([$userId, $documentId], $types);
    $bindParams = ['ii' . str_repeat('s', count($types))];
    foreach ($bindValues as $index => $value) {
        $bindParams[] = &$bindValues[$index];
    }

    mysqli_stmt_bind_param($stmt, ...$bindParams);
    mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);

    if ($affected > 0) {
        emsp_sync_notification_counters($con, $userId);
    }

    return max(0, (int) $affected);
}

function emsp_notif_excerpt(string $text, int $max = 80): string
{
    $clean = trim($text);
    if ($clean === '') {
        return '';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($clean) > $max ? (mb_substr($clean, 0, $max) . '...') : $clean;
    }
    return strlen($clean) > $max ? (substr($clean, 0, $max) . '...') : $clean;
}

function emsp_notification_target(array $notification, string $default = 'dashboard.php'): string
{
    $type = strtolower(trim((string) ($notification['type'] ?? '')));
    $docId = (int) ($notification['document_id'] ?? $notification['notif_document_id'] ?? 0);
    $commentId = (int) ($notification['comment_id'] ?? 0);
    $replyId = (int) ($notification['reply_id'] ?? 0);

    if ($type === 'media_published') {
        return 'mediatheque.php';
    }

    if (in_array($type, ['journal_published', 'journal_liked', 'journal_commented'], true)) {
        return $docId > 0 ? ('news-article.php?id=' . $docId) : 'news-blog.php';
    }

    if ($docId <= 0) {
        return $default;
    }

    $url = 'document.php?id=' . $docId;
    if ($type === 'comment_reply') {
        if ($replyId > 0) {
            return $url . '#reply-' . $replyId;
        }
        if ($commentId > 0) {
            return $url . '#comment-' . $commentId;
        }
        return $url . '#commentaires';
    }

    if (in_array($type, ['new_comment', 'comment_reacted'], true) && $commentId > 0) {
        return $url . '#comment-' . $commentId;
    }

    return $url;
}

function send_notification(
    mysqli $con,
    int $user_id,
    string $type,
    string $message,
    ?int $document_id = null,
    ?int $comment_id = null,
    ?int $reply_id = null,
    ?int $from_user_id = null
): bool {
    if ($user_id <= 0 || trim($type) === '' || trim($message) === '') {
        return false;
    }

    $doc = $document_id !== null ? intval($document_id) : 0;
    $com = $comment_id !== null ? intval($comment_id) : 0;
    $rep = $reply_id !== null ? intval($reply_id) : 0;
    $from = $from_user_id !== null ? intval($from_user_id) : 0;

    $s = mysqli_prepare(
        $con,
        "INSERT INTO notifications (user_id, type, document_id, comment_id, reply_id, from_user_id, message)
         VALUES (?, ?, NULLIF(?,0), NULLIF(?,0), NULLIF(?,0), NULLIF(?,0), ?)"
    );
    if (!$s) {
        return false;
    }

    mysqli_stmt_bind_param($s, 'isiiiis', $user_id, $type, $doc, $com, $rep, $from, $message);
    $ok = mysqli_stmt_execute($s);
    mysqli_stmt_close($s);

    if ($ok) {
        include_once __DIR__ . '/push-helper.php';
        if (function_exists('emsp_push_payload')) {
            $url = '/' . ltrim(emsp_notification_target([
                'type' => $type,
                'document_id' => $doc,
                'comment_id' => $com,
                'reply_id' => $rep,
            ], 'dashboard.php'), '/');
            $payload = emsp_push_payload('EMSP Docs', $message, $url);
            emsp_push_send_to_user($con, $user_id, $payload);
        }
    }

    return $ok;
}

function notify_journal_published_all(mysqli $con, int $journal_id, string $title, int $from_user_id = 0): int
{
    $msg = 'Nouveau journal publie : "' . emsp_notif_excerpt($title, 60) . '"';
    $stmt = mysqli_prepare(
        $con,
        "INSERT INTO notifications (user_id, type, document_id, comment_id, reply_id, from_user_id, message)
         SELECT id, 'journal_published', ?, NULL, NULL, NULLIF(?,0), ?
         FROM users
         WHERE status='active'"
    );
    if (!$stmt) {
        return 0;
    }
    mysqli_stmt_bind_param($stmt, 'iis', $journal_id, $from_user_id, $msg);
    mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    return max(0, (int) $affected);
}

function notify_journal_liked(mysqli $con, int $owner_id, int $journal_id, string $title, int $from_user_id): bool
{
    if ($owner_id <= 0 || $owner_id === $from_user_id) {
        return false;
    }
    $msg = 'Quelqu un a aime votre publication journal "' . emsp_notif_excerpt($title, 60) . '"';
    return send_notification($con, $owner_id, 'journal_liked', $msg, $journal_id, null, null, $from_user_id);
}

function notify_journal_commented(mysqli $con, int $owner_id, int $journal_id, string $title, int $from_user_id): bool
{
    if ($owner_id <= 0 || $owner_id === $from_user_id) {
        return false;
    }
    $msg = 'Nouveau commentaire sur votre publication journal "' . emsp_notif_excerpt($title, 60) . '"';
    return send_notification($con, $owner_id, 'journal_commented', $msg, $journal_id, null, null, $from_user_id);
}

function notify_media_published_all(mysqli $con, int $mediaId, string $title, int $from_user_id = 0): int
{
    $msg = 'Nouveau media publie : "' . emsp_notif_excerpt($title, 60) . '"';
    $stmt = mysqli_prepare(
        $con,
        "INSERT INTO notifications (user_id, type, document_id, comment_id, reply_id, from_user_id, message)
         SELECT id, 'media_published', NULLIF(?,0), NULL, NULL, NULLIF(?,0), ?
         FROM users
         WHERE status='active'"
    );
    if (!$stmt) {
        return 0;
    }
    mysqli_stmt_bind_param($stmt, 'iis', $mediaId, $from_user_id, $msg);
    mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);
    return max(0, (int) $affected);
}

function notify_doc_approved(mysqli $con, int $uploader_id, int $doc_id, string $doc_title, int $approver_id): bool
{
    $message = 'Votre document "' . emsp_notif_excerpt($doc_title, 60) . '" a ete approuve et publie.';
    return send_notification($con, $uploader_id, 'doc_approved', $message, $doc_id, null, null, $approver_id);
}

function notify_doc_rejected(
    mysqli $con,
    int $uploader_id,
    int $doc_id,
    string $doc_title,
    string $reason,
    int $approver_id
): bool {
    $message = 'Votre document "' . emsp_notif_excerpt($doc_title, 55) . '" a ete rejete.';
    $reasonClean = trim($reason);
    if ($reasonClean !== '') {
        $message .= ' Motif: ' . emsp_notif_excerpt($reasonClean, 80);
    }
    return send_notification($con, $uploader_id, 'doc_rejected', $message, $doc_id, null, null, $approver_id);
}


