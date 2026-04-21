<?php
include_once __DIR__ . '/includes/bootstrap.php';
include_once __DIR__ . '/admin/config/dbcon.php';
include_once __DIR__ . '/includes/csrf.php';
include_once __DIR__ . '/includes/document-taxonomy.php';
include_once __DIR__ . '/includes/content-helpers.php';
include_once __DIR__ . '/includes/notif-helper.php';

function fmt_size(int $bytes): string
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' Mo';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024) . ' Ko';
    }
    return $bytes . ' o';
}

function time_ago(string $dt): string
{
    $ts = strtotime($dt);
    if (!$ts) {
        return '';
    }
    $diff = time() - $ts;
    if ($diff < 60) {
        return "A l'instant";
    }
    if ($diff < 3600) {
        return (int) ($diff / 60) . ' min';
    }
    if ($diff < 86400) {
        return (int) ($diff / 3600) . 'h';
    }
    if ($diff < 604800) {
        return (int) ($diff / 86400) . 'j';
    }
    return date('d/m/Y', $ts);
}

function emsp_strim(string $text, int $max, string $suffix = '...'): string
{
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $max, $suffix, 'UTF-8');
    }
    if (function_exists('mb_substr') && function_exists('mb_strlen')) {
        if (mb_strlen($text, 'UTF-8') > $max) {
            return mb_substr($text, 0, max(0, $max - strlen($suffix)), 'UTF-8') . $suffix;
        }
        return $text;
    }
    if (strlen($text) > $max) {
        return substr($text, 0, max(0, $max - strlen($suffix))) . $suffix;
    }
    return $text;
}

$doc_id = intval($_GET['id'] ?? 0);
if ($doc_id <= 0) {
    header('Location: bibliotheque.php');
    exit(0);
}

$isAuth = !empty($_SESSION['auth']);
$uid = $isAuth ? (int) ($_SESSION['auth_user']['id'] ?? 0) : 0;
$role = $isAuth ? (string) ($_SESSION['auth_role'] ?? ($_SESSION['auth_user']['role'] ?? 'etudiant')) : 'etudiant';
$is_staff = in_array($role, ['admin', 'moderateur'], true);

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST' && !$isAuth) {
    $_SESSION['redirect_after_login'] = 'document.php?id=' . $doc_id;
    header('Location: login.php');
    exit(0);
}

$stmt = mysqli_prepare($con,
    "SELECT d.*,
            u.first_name, u.last_name, u.badge_level, u.photo_path,
            f.name  AS filiere_name,
            l.name  AS licence_name,
            ma.name AS matiere_name,"
            . (emsp_pending_matiere_enabled($con) ? " d.matiere_label_pending," : " NULL AS matiere_label_pending,") . "
            ab.first_name AS approver_first, ab.last_name AS approver_last
     FROM documents d
     JOIN users u  ON u.id  = d.uploader_id
     LEFT JOIN filieres f  ON f.id  = d.filiere_id
     LEFT JOIN licences l  ON l.id  = d.licence_id
     LEFT JOIN matieres ma ON ma.id = d.matiere_id
     LEFT JOIN users ab    ON ab.id = d.approved_by
     WHERE d.id = ? LIMIT 1");
if (!$stmt) {
    header('Location: bibliotheque.php');
    exit(0);
}
mysqli_stmt_bind_param($stmt, 'i', $doc_id);
mysqli_stmt_execute($stmt);
$doc = emsp_stmt_fetch_assoc($stmt);
mysqli_stmt_close($stmt);

if (!$doc) {
    header('Location: bibliotheque.php');
    exit(0);
}

if (empty($_SESSION['auth']) && (($doc['doc_type'] ?? '') !== 'concours')) {
    header('Location: concours.php');
    exit(0);
}

$fix_fields = [
    'title', 'description', 'first_name', 'last_name', 'badge_level',
    'photo_path', 'filiere_name', 'licence_name',
    'matiere_name', 'approver_first', 'approver_last',
    'exam_session', 'rejection_reason', 'matiere_label_pending'
];
foreach ($fix_fields as $f) {
    if (isset($doc[$f]) && is_string($doc[$f])) {
        $doc[$f] = emsp_fix_mojibake($doc[$f]);
    }
}

$docFiliereLabels = emsp_fetch_document_filiere_labels($con, [$doc_id]);
$doc['filiere_labels'] = $docFiliereLabels[$doc_id] ?? [];
$doc['filiere_label_display'] = !empty($doc['filiere_labels'])
    ? implode(', ', $doc['filiere_labels'])
    : trim((string) ($doc['filiere_name'] ?? ''));
$doc['matiere_display'] = trim((string) ($doc['matiere_name'] ?? '')) !== ''
    ? trim((string) $doc['matiere_name'])
    : trim((string) ($doc['matiere_label_pending'] ?? ''));

$is_owner = ($uid > 0 && $uid === intval($doc['uploader_id']));
$is_public_document = ((int) ($doc['is_public'] ?? 0) === 1);
$is_approved = ((string) ($doc['status'] ?? '') === 'approved');
if (!$is_approved && !$is_owner && !$is_staff) {
    if (!$isAuth) {
        $_SESSION['redirect_after_login'] = 'document.php?id=' . $doc_id;
        header('Location: login.php');
        exit(0);
    }
    header('Location: bibliotheque.php');
    exit(0);
}
if (!$is_public_document && !$is_owner && !$is_staff) {
    if (!$isAuth) {
        $_SESSION['redirect_after_login'] = 'document.php?id=' . $doc_id;
        header('Location: login.php');
        exit(0);
    }
    header('Location: bibliotheque.php');
    exit(0);
}

if ($isAuth && $uid > 0) {
    emsp_mark_notifications_seen_for_document($con, $uid, $doc_id);
}

$is_fav = false;
if ($isAuth) {
    $fav_s = mysqli_prepare($con, "SELECT id FROM favorites WHERE user_id=? AND document_id=? LIMIT 1");
    if ($fav_s) {
        mysqli_stmt_bind_param($fav_s, 'ii', $uid, $doc_id);
        mysqli_stmt_execute($fav_s);
        mysqli_stmt_store_result($fav_s);
        $is_fav = mysqli_stmt_num_rows($fav_s) > 0;
        mysqli_stmt_close($fav_s);
    }
}

$is_liked = false;
if ($isAuth) {
    $like_s = mysqli_prepare($con, "SELECT 1 FROM document_likes WHERE user_id=? AND document_id=? LIMIT 1");
    if ($like_s) {
        mysqli_stmt_bind_param($like_s, 'ii', $uid, $doc_id);
        mysqli_stmt_execute($like_s);
        mysqli_stmt_store_result($like_s);
        $is_liked = mysqli_stmt_num_rows($like_s) > 0;
        mysqli_stmt_close($like_s);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fav_action'])) {
    verify_csrf_token();
    if ($_POST['fav_action'] === 'add') {
        $s = mysqli_prepare($con, "INSERT IGNORE INTO favorites (user_id, document_id) VALUES (?, ?)");
        if ($s) {
            mysqli_stmt_bind_param($s, 'ii', $uid, $doc_id);
            mysqli_stmt_execute($s);
            mysqli_stmt_close($s);
        }
    } else {
        $s = mysqli_prepare($con, "DELETE FROM favorites WHERE user_id=? AND document_id=?");
        if ($s) {
            mysqli_stmt_bind_param($s, 'ii', $uid, $doc_id);
            mysqli_stmt_execute($s);
            mysqli_stmt_close($s);
        }
    }
    header("Location: document.php?id=$doc_id");
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comment_content'])) {
    verify_csrf_token();
    $content = trim($_POST['comment_content'] ?? '');
    if ($content !== '' && $doc['status'] === 'approved') {
        $ins = mysqli_prepare($con,
            "INSERT INTO comments (user_id, document_id, content) VALUES (?, ?, ?)");
        if ($ins) {
            mysqli_stmt_bind_param($ins, 'iis', $uid, $doc_id, $content);
            mysqli_stmt_execute($ins);
            $new_cid = mysqli_insert_id($con);
            mysqli_stmt_close($ins);

            if (intval($doc['uploader_id']) !== $uid) {
                $me  = trim(($_SESSION['auth_user']['first_name'] ?? '') . ' ' . ($_SESSION['auth_user']['last_name'] ?? ''));
                $msg = $me . ' a commente votre document "' . mb_substr($doc['title'], 0, 50) . '"';
                $notif = mysqli_prepare($con,
                    "INSERT INTO notifications (user_id, type, document_id, comment_id, from_user_id, message)
                     VALUES (?, 'new_comment', ?, ?, ?, ?)");
                if ($notif) {
                    $owner = intval($doc['uploader_id']);
                    mysqli_stmt_bind_param($notif, 'iiiis', $owner, $doc_id, $new_cid, $uid, $msg);
                    mysqli_stmt_execute($notif);
                    mysqli_stmt_close($notif);
                }
            }
        }
        header("Location: document.php?id=$doc_id#commentaires");
        exit(0);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reply_content'], $_POST['reply_comment_id'])) {
    verify_csrf_token();
    $parent_id = intval($_POST['reply_comment_id']);
    $rcontent = trim($_POST['reply_content'] ?? '');
    if ($rcontent !== '' && $parent_id > 0 && $doc['status'] === 'approved') {
        $ins = mysqli_prepare($con,
            "INSERT INTO comment_replies (comment_id, user_id, content) VALUES (?, ?, ?)");
        if ($ins) {
            mysqli_stmt_bind_param($ins, 'iis', $parent_id, $uid, $rcontent);
            mysqli_stmt_execute($ins);
            $reply_id = mysqli_insert_id($con);
            mysqli_stmt_close($ins);

            $ps = mysqli_prepare($con, "SELECT user_id FROM comments WHERE id=? LIMIT 1");
            $par_author = 0;
            if ($ps) {
                mysqli_stmt_bind_param($ps, 'i', $parent_id);
                mysqli_stmt_execute($ps);
                $row = emsp_stmt_fetch_assoc($ps);
                mysqli_stmt_close($ps);
                $par_author = $row ? intval($row['user_id']) : 0;
            }

            if ($par_author && $par_author !== $uid) {
                $me  = trim(($_SESSION['auth_user']['first_name'] ?? '') . ' ' . ($_SESSION['auth_user']['last_name'] ?? ''));
                $msg = $me . ' a repondu a votre commentaire sur "' . mb_substr($doc['title'], 0, 50) . '"';
                $notif = mysqli_prepare($con,
                    "INSERT INTO notifications (user_id, type, document_id, comment_id, reply_id, from_user_id, message)
                     VALUES (?, 'comment_reply', ?, ?, ?, ?, ?)");
                if ($notif) {
                    mysqli_stmt_bind_param($notif, 'iiiiis', $par_author, $doc_id, $parent_id, $reply_id, $uid, $msg);
                    mysqli_stmt_execute($notif);
                    mysqli_stmt_close($notif);
                }
            }
        }
        header("Location: document.php?id=$doc_id#commentaires");
        exit(0);
    }
}

$cmt_s = mysqli_prepare($con,
    "SELECT c.id, c.content, c.created_at,
            u.first_name, u.last_name, u.badge_level, u.photo_path, u.id AS author_id
     FROM comments c
     JOIN users u ON u.id = c.user_id
     WHERE c.document_id=? AND c.status='visible'
     ORDER BY c.created_at ASC");
$comments = [];
if ($cmt_s) {
    mysqli_stmt_bind_param($cmt_s, 'i', $doc_id);
    mysqli_stmt_execute($cmt_s);
    $comments = emsp_stmt_fetch_all($cmt_s);
    mysqli_stmt_close($cmt_s);
}
foreach ($comments as &$c) {
    foreach (['first_name', 'last_name', 'content', 'photo_path'] as $f) {
        if (isset($c[$f]) && is_string($c[$f])) {
            $c[$f] = emsp_fix_mojibake($c[$f]);
        }
    }
}
unset($c);
$cmt_count = count($comments);

$rep_s = mysqli_prepare($con,
    "SELECT r.*, u.id AS author_id, u.first_name, u.last_name, u.badge_level, u.photo_path
     FROM comment_replies r
     JOIN users u ON u.id = r.user_id
     JOIN comments c ON c.id = r.comment_id
     WHERE c.document_id=? AND r.status='visible'
     ORDER BY r.created_at ASC");
$replies = [];
if ($rep_s) {
    mysqli_stmt_bind_param($rep_s, 'i', $doc_id);
    mysqli_stmt_execute($rep_s);
    $replies = emsp_stmt_fetch_all($rep_s);
    mysqli_stmt_close($rep_s);
}
foreach ($replies as &$r) {
    foreach (['first_name', 'last_name', 'content', 'photo_path'] as $f) {
        if (isset($r[$f]) && is_string($r[$f])) {
            $r[$f] = emsp_fix_mojibake($r[$f]);
        }
    }
}
unset($r);

$replies_by_cmt = [];
foreach ($replies as $r) {
    $cid = intval($r['comment_id'] ?? 0);
    if ($cid > 0) {
        $replies_by_cmt[$cid][] = $r;
    }
}

// Reactions sur commentaires
$reaction_labels = [
    'like' => 'Like',
    'love' => 'Love',
    'haha' => 'Haha',
    'wow'  => 'Wow',
    'sad'  => 'Sad',
    'angry'=> 'Angry',
];
$reaction_counts = [];
$my_reactions = [];
$comment_ids = [];
foreach ($comments as $c) {
    $comment_ids[] = intval($c['id'] ?? 0);
}
$comment_ids = array_values(array_filter($comment_ids, function ($v) { return $v > 0; }));
if (!empty($comment_ids)) {
    $ids = implode(',', $comment_ids);
    $qr = mysqli_query($con,
        "SELECT comment_id, reaction, COUNT(*) AS nb
         FROM comment_reactions
         WHERE comment_id IN ($ids)
         GROUP BY comment_id, reaction");
    if ($qr) {
        while ($row = mysqli_fetch_assoc($qr)) {
            $cid = intval($row['comment_id'] ?? 0);
            $rk = (string) ($row['reaction'] ?? '');
            if ($cid > 0 && isset($reaction_labels[$rk])) {
                $reaction_counts[$cid][$rk] = intval($row['nb'] ?? 0);
            }
        }
    }
    if ($uid > 0) {
        $qr = mysqli_query($con,
            "SELECT comment_id, reaction
             FROM comment_reactions
             WHERE user_id=" . $uid . " AND comment_id IN ($ids)");
        if ($qr) {
            while ($row = mysqli_fetch_assoc($qr)) {
                $cid = intval($row['comment_id'] ?? 0);
                $rk = (string) ($row['reaction'] ?? '');
                if ($cid > 0 && isset($reaction_labels[$rk])) {
                    $my_reactions[$cid] = $rk;
                }
            }
        }
    }
}

$badge_icons = [
    'or' => '&#x1F947;',
    'argent' => '&#x1F948;',
    'bronze' => '&#x1F949;',
    'none' => ''
];
$type_labels = [
    'cours' => 'Cours',
    'td' => 'TD',
    'correction' => 'Correction',
    'concours' => 'Concours',
    'examen' => 'Examen',
];
$type_colors = [
    'cours' => '#004D2A',
    'td' => '#16783a',
    'correction' => '#0d9488',
    'concours' => '#d97706',
    'examen' => '#C0392B',
];

$tc = $type_colors[$doc['doc_type']] ?? '#6B6B6B';
$tl = $type_labels[$doc['doc_type']] ?? ucfirst((string) ($doc['doc_type'] ?? ''));
$doc_type_key = strtolower(trim((string) ($doc['doc_type'] ?? '')));
if (!array_key_exists($doc_type_key, $type_labels)) {
    $doc_type_key = 'other';
}

$mime = strtolower((string) ($doc['mime_type'] ?? ''));
$ext = strtolower(pathinfo((string) ($doc['file_path'] ?? ''), PATHINFO_EXTENSION));
$is_pdf = ($mime === 'application/pdf');
$is_image = (str_starts_with($mime, 'image/') || in_array($ext, ['jpg','jpeg','png','gif','webp','bmp','svg'], true));
$is_docx = ($mime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' || $ext === 'docx');
$is_xlsx = ($mime === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' || $ext === 'xlsx');
$is_text = (
    str_starts_with($mime, 'text/')
    || in_array($ext, ['txt', 'csv', 'md', 'log', 'json', 'xml'], true)
    || in_array($mime, ['application/json', 'application/xml'], true)
);
$can_view = ($doc['status'] === 'approved' || $is_owner || $is_staff);

$hero_summary = trim((string) ($doc['description'] ?? ''));
if ($hero_summary === '') {
    $hero_bits = array_values(array_filter([
        trim((string) ($doc['filiere_label_display'] ?? '')),
        trim((string) ($doc['licence_name'] ?? '')),
        trim((string) ($doc['matiere_display'] ?? '')),
    ], static function ($value): bool {
        return $value !== '';
    }));
    if (!empty($hero_bits)) {
        $hero_summary = 'Ressource academique partagee par la communaute EMSP pour ' . implode(' - ', $hero_bits) . '.';
    } else {
        $hero_summary = 'Ressource academique partagee sur EMSP Docs.';
    }
}
$hero_summary = trim((string) preg_replace('/\s+/', ' ', $hero_summary));
$hero_summary = emsp_strim($hero_summary, 170, '...');

$doc_icon = 'bi-file-earmark-text';
$doc_icon_color = '#004D2A';
$doc_icon_kind = 'default';
if ($is_pdf) {
    $doc_icon = 'bi-file-earmark-pdf-fill';
    $doc_icon_color = '#C0392B';
    $doc_icon_kind = 'pdf';
} elseif ($is_image) {
    $doc_icon = 'bi-image-fill';
    $doc_icon_color = '#D4900A';
    $doc_icon_kind = 'image';
} elseif ($is_docx) {
    $doc_icon = 'bi-file-earmark-word-fill';
    $doc_icon_color = '#004D2A';
    $doc_icon_kind = 'word';
} elseif ($is_xlsx) {
    $doc_icon = 'bi-file-earmark-excel-fill';
    $doc_icon_color = '#006B3C';
    $doc_icon_kind = 'excel';
} elseif ($is_text) {
    $doc_icon = 'bi-file-earmark-text-fill';
    $doc_icon_color = '#006B3C';
    $doc_icon_kind = 'text';
}

$status_label = '';
$status_color = '#6B6B6B';
$status_key = 'other';
if (($doc['status'] ?? '') === 'approved') {
    $status_label = 'Approuve';
    $status_color = '#006B3C';
    $status_key = 'approved';
} elseif (($doc['status'] ?? '') === 'pending') {
    $status_label = 'En attente';
    $status_color = '#D4900A';
    $status_key = 'pending';
} elseif (($doc['status'] ?? '') === 'rejected') {
    $status_label = 'Rejete';
    $status_color = '#C0392B';
    $status_key = 'rejected';
}

$preview_label = 'Document';
if ($is_pdf) {
    $preview_label = 'Apercu PDF';
} elseif ($is_image) {
    $preview_label = 'Apercu image';
} elseif ($is_docx) {
    $preview_label = 'Apercu DOCX';
} elseif ($is_xlsx) {
    $preview_label = 'Apercu Excel';
} elseif ($is_text) {
    $preview_label = 'Apercu texte';
}

$share_url = '';
if (defined('APP_URL') && trim((string) APP_URL) !== '') {
    $share_url = rtrim((string) APP_URL, '/') . '/document.php?id=' . $doc_id;
} else {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host !== '') {
        $share_url = $scheme . '://' . $host . '/document.php?id=' . $doc_id;
    } else {
        $share_url = 'document.php?id=' . $doc_id;
    }
}

$csrf_token = generate_csrf_token();

$page_title = h($doc['title']);
include __DIR__ . '/includes/header.php';
?>
<style>
/* --- Hero --- */
.doc-hero {
    background:
        linear-gradient(130deg, rgba(0,50,28,.96) 0%, rgba(0,107,60,.88) 55%, rgba(0,143,82,.65) 100%),
        url('assets/images/emsp-campus-ceremony.jpg') center 28% / cover no-repeat;
    padding: 2.5rem 0 2rem;
    color: #fff;
    position: relative;
    overflow: hidden;
    box-shadow: inset 0 -1px 0 rgba(255,255,255,.08);
}
.doc-hero::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(90deg, rgba(0,30,15,.82) 0%, rgba(0,60,30,.55) 52%, rgba(0,80,40,.18) 100%);
}
.doc-hero::after {
    content: '';
    position: absolute;
    inset: auto -70px -90px auto;
    width: 260px;
    height: 260px;
    border-radius: 50%;
    background: rgba(255,255,255,.08);
}
.doc-hero > .container { position: relative; z-index: 1; }
.doc-hero-bc a { color: rgba(255,255,255,.55); font-size: .78rem; text-decoration: none; }
.doc-hero-bc a:hover { color: #fff; }
.doc-hero-bc .sep { color: rgba(255,255,255,.3); margin: 0 .4rem; }
.doc-hero-bc .cur { color: rgba(255,255,255,.45); font-size: .78rem; }
.doc-hero h1 {
    font-family: var(--font-display,'Plus Jakarta Sans',sans-serif);
    font-weight: 800;
    font-size: clamp(1.25rem,3.2vw,2rem);
    line-height: 1.2;
    margin: .65rem 0 .9rem;
    word-break: break-word;
}
.doc-hero-summary {
    max-width: 48rem;
    color: rgba(255,255,255,.82);
    font-size: .95rem;
    line-height: 1.6;
}
.type-pill {
    display: inline-flex; align-items: center; gap: .35rem;
    padding: .28rem .85rem; border-radius: 999px;
    font-size: .73rem; font-weight: 700;
    letter-spacing: .06em; text-transform: uppercase;
    color: #fff;
}
.hero-meta {
    display: flex; flex-wrap: wrap; gap: .6rem 1.4rem;
    margin-top: .85rem; font-size: .8rem;
    color: rgba(255,255,255,.65);
}
.hero-meta span { display: flex; align-items: center; gap: .3rem; }

/* --- Body --- */
.doc-body {
    background:
        radial-gradient(circle at top right, rgba(0,85,204,.08), transparent 24%),
        linear-gradient(180deg, #f6f9fd 0%, #f5f7fb 24%, #f7f9fc 100%);
    padding: 2rem 0 3rem;
}

/* --- Viewer card --- */
.viewer-card {
    background: #fff; border-radius: 22px;
    box-shadow: 0 18px 42px rgba(0,48,135,.08);
    border: 1px solid rgba(0,48,135,.08);
    overflow: hidden; margin-bottom: 1.2rem;
}
.viewer-toolbar {
    display: flex; align-items: center;
    justify-content: space-between;
    flex-wrap: wrap; gap: .5rem;
    padding: .7rem 1rem;
    background: linear-gradient(180deg, #ffffff 0%, #f6f9ff 100%);
    border-bottom: 1px solid rgba(0,48,135,.08);
}
.viewer-toolbar .vt-title {
    display: flex; align-items: center; gap: .4rem;
    font-size: .8rem; font-weight: 600; color: #475569;
}
.legacy-preview-label {
    display: none;
}
.pdf-wrap {
    background: #525659;
    min-height: 520px;
    display: flex; flex-direction: column;
    align-items: center; padding: 1.2rem; gap: .85rem;
}
.pdf-canvas { max-width: 100%; border-radius: 4px; box-shadow: 0 4px 24px rgba(0,0,0,.4); }
.pdf-nav {
    display: flex; align-items: center; gap: .4rem; flex-wrap: wrap;
    justify-content: center;
    background: rgba(0,0,0,.35);
    padding: .35rem .85rem; border-radius: 999px;
    color: #fff; font-size: .78rem;
}
.pdf-nav button {
    background: none; border: none; color: #fff; cursor: pointer;
    padding: .15rem .45rem; border-radius: 6px;
    transition: background .15s;
}
.pdf-nav button:hover { background: rgba(255,255,255,.15); }
.pdf-nav button:disabled { opacity: .35; cursor: not-allowed; }
.pdf-sep { width: 1px; height: 16px; background: rgba(255,255,255,.2); margin: 0 .2rem; }
.pdf-loading {
    color: rgba(255,255,255,.65); font-size: .85rem;
    display: flex; align-items: center; gap: .5rem;
    padding: 3rem 1rem;
}
.pdf-err {
    background: rgba(220,38,38,.15);
    border: 1px solid rgba(220,38,38,.3);
    color: #fca5a5; border-radius: 10px;
    padding: 1.25rem; text-align: center;
    margin: 1rem;
}
.img-wrap {
    padding: 1.5rem; background: #f0f4f8;
    text-align: center; min-height: 280px;
    display: flex; align-items: center; justify-content: center;
}
.img-wrap img { max-width: 100%; max-height: 580px; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,.12); }
.viewer-placeholder {
    text-align: center; padding: 3rem 1.5rem; color: #6B6B6B;
}
.viewer-placeholder .fi { font-size: 3.5rem; display: block; margin-bottom: 1rem; }
.viewer-placeholder h5 { color: #475569; font-weight: 700; margin-bottom: .4rem; }
.viewer-loading {
    color: #6B6B6B; font-size: .85rem;
    display: flex; align-items: center; justify-content: center; gap: .5rem;
    padding: 2.5rem 1rem;
}
.viewer-err {
    background: #fff7ed;
    border: 1px solid rgba(245, 158, 11, .35);
    color: #9a3412;
    border-radius: 10px;
    padding: 1rem;
    text-align: center;
    margin: 1rem;
    font-size: .85rem;
}
.docx-wrap, .xlsx-wrap, .text-wrap {
    background: #fff;
    min-height: 520px;
    padding: 1.2rem;
}
.docx-content {
    max-width: 100%;
    overflow: auto;
    color: #0f172a;
    font-size: .9rem;
    line-height: 1.7;
}
.docx-content,
.docx-content * {
    max-width: 100%;
    box-sizing: border-box;
}
.docx-content p,
.docx-content li,
.docx-content td,
.docx-content th,
.docx-content span,
.docx-content div,
.docx-content a {
    overflow-wrap: anywhere;
    word-break: break-word;
}
.docx-content img,
.docx-content svg,
.docx-content canvas,
.docx-content iframe,
.docx-content video { max-width: 100%; height: auto; }
.xlsx-content { overflow: auto; }
.xlsx-content table { border-collapse: collapse; width: max-content; min-width: 100%; }
.xlsx-content td, .xlsx-content th { border: 1px solid #e2e8f0; padding: .35rem .5rem; font-size: .82rem; }
.xlsx-content th { background: #f8fafc; }
.text-content {
    white-space: pre-wrap;
    word-break: break-word;
    margin: 0;
    color: #0f172a;
    font-size: .85rem;
    line-height: 1.6;
}

/* --- Description --- */
.desc-card {
    background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%); border-radius: 22px;
    box-shadow: 0 18px 42px rgba(0,48,135,.08);
    border: 1px solid rgba(0,48,135,.08);
    padding: 1.2rem 1.4rem; margin-bottom: 1.2rem;
}
.desc-card .dc-label {
    font-size: .75rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .07em;
    color: #6B6B6B; margin-bottom: .6rem;
}
.desc-card p {
    color: #334155; font-size: .88rem;
    line-height: 1.7; margin: 0;
    white-space: pre-wrap; word-break: break-word;
}

/* --- Stats bar --- */
.stats-bar {
    background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%); border-radius: 22px;
    box-shadow: 0 18px 42px rgba(0,48,135,.08);
    border: 1px solid rgba(0,48,135,.08);
    padding: .8rem 1.2rem; margin-bottom: 1.2rem;
    display: flex; align-items: center; gap: 1.4rem;
    flex-wrap: wrap;
}
.stat-item {
    display: inline-flex; align-items: center; gap: .4rem;
    padding: .28rem .9rem; border-radius: 999px;
    font-size: .82rem; font-weight: 600;
    border: 1.5px solid #006B3C; color: #006B3C;
    background: transparent;
}
.stat-item.stat-comments {
    border-color: #6B6B6B;
    color: #6B6B6B;
}
.btn-like {
    display: inline-flex; align-items: center; gap: .35rem;
    padding: .28rem .9rem; border-radius: 999px;
    font-size: .82rem; font-weight: 700;
    border: 2px solid #F5A800; color: #F5A800;
    background: transparent; cursor: pointer;
    transition: all .2s ease;
    min-height: 44px; min-width: 44px;
}
.btn-like:hover, .btn-like.liked {
    background: #F5A800;
    color: #1A1A1A;
    border-color: #F5A800;
}

/* --- Commentaires --- */
.cmt-card {
    background: #fff; border-radius: 22px;
    box-shadow: 0 18px 42px rgba(0,48,135,.08);
    border: 1px solid rgba(0,48,135,.08);
    overflow: hidden; margin-bottom: 1.2rem;
}
.cmt-header {
    padding: .9rem 1.4rem; border-bottom: 1px solid #f1f5f9;
    display: flex; align-items: center; gap: .45rem;
    font-weight: 700; font-size: .88rem; color: #1e293b;
}
.cmt-count {
    background: linear-gradient(135deg, var(--emsp-primary) 0%, var(--emsp-accent) 100%); color: #fff;
    border-radius: 999px; padding: .12rem .55rem;
    font-size: .7rem; font-weight: 700;
}
.cmt-list { padding: 1.2rem 1.4rem; }
.cmt-item {
    display: flex; gap: .8rem;
    padding-bottom: 1.2rem;
    border-bottom: 1px solid #f1f5f9;
    margin-bottom: 1.2rem;
}
.cmt-item:last-child { border-bottom: none; margin-bottom: 0; }
.c-av {
    width: 36px; height: 36px; border-radius: 50%;
    background: linear-gradient(135deg,#004D2A,#006B3C);
    color: #fff; font-size: .76rem; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; overflow: hidden;
}
.c-av img { width: 100%; height: 100%; object-fit: cover; }
.c-av-sm { width: 28px; height: 28px; font-size: .66rem; }
.c-name { font-size: .8rem; font-weight: 700; color: #1e293b; }
.c-ago  { font-size: .7rem; color: #6B6B6B; margin-left: .35rem; }
.c-text {
    font-size: .86rem; color: #334155;
    line-height: 1.65; margin: .2rem 0 .45rem;
    word-break: break-word;
}
.c-rep-btn {
    background: none; border: none; font-size: .73rem;
    color: #6B6B6B; cursor: pointer; padding: 0;
    display: inline-flex; align-items: center; gap: .2rem;
    transition: color .15s;
}
.c-rep-btn:hover { color: var(--emsp-primary); }
.cmt-reactions {
    display: flex;
    flex-wrap: wrap;
    gap: .35rem;
    margin: .25rem 0 .4rem;
}
.react-btn {
    display: inline-flex; align-items: center; gap: .35rem;
    padding: .22rem .55rem;
    border-radius: 999px;
    border: 1px solid #e2e8f0;
    background: #f8fafc;
    color: #334155;
    font-size: .72rem;
    cursor: pointer;
    transition: all .15s ease;
}
.react-btn:hover { border-color: #6B6B6B; }
.react-btn.active {
    background: linear-gradient(135deg, var(--emsp-primary) 0%, var(--emsp-accent) 100%);
    border-color: var(--emsp-accent);
    color: #fff;
}
.react-count { font-weight: 700; }
.replies-wrap {
    margin-top: .7rem; padding-left: .8rem;
    border-left: 2px solid #e2e8f0;
}
.rep-item { display: flex; gap: .6rem; margin-bottom: .8rem; }
.rep-form-wrap { margin-top: .7rem; padding-left: .8rem; }
.no-cmt { text-align: center; padding: 1.8rem 1rem; color: #6B6B6B; }
.no-cmt i { font-size: 1.8rem; display: block; margin-bottom: .4rem; }
.cmt-form-wrap {
    padding: 1.2rem 1.4rem;
    border-top: 1px solid #f1f5f9;
    background: linear-gradient(180deg, #f8fbf9 0%, #f8fafc 100%);
}
.cmt-form-wrap .cf-label {
    font-size: .75rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .07em;
    color: #6B6B6B; margin-bottom: .7rem;
}
.cmt-form-wrap textarea {
    border: 1.5px solid #e2e8f0; border-radius: 10px;
    font-size: .86rem; resize: vertical;
    transition: border-color .2s;
}
.cmt-form-wrap textarea:focus {
    border-color: var(--emsp-accent);
    box-shadow: 0 0 0 3px rgba(0,85,204,.10);
    outline: none;
}

/* --- Sidebar cards --- */
.side-card {
    background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%); border-radius: 22px;
    box-shadow: 0 18px 42px rgba(0,48,135,.08);
    border: 1px solid rgba(0,48,135,.08);
    overflow: hidden; margin-bottom: 1.2rem;
    transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
}
.side-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 22px 48px rgba(0,48,135,.12);
    border-color: rgba(0,85,204,.22);
}
.side-card-header {
    padding: .8rem 1.2rem; border-bottom: 1px solid rgba(0,48,135,.08);
    font-size: .75rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .07em;
    color: var(--emsp-primary); display: flex; align-items: center; gap: .4rem;
    background: linear-gradient(180deg, #ffffff 0%, #f5f9ff 100%);
}
.btn-dl-main {
    display: flex; align-items: center; justify-content: center; gap: .45rem;
    width: 100%; padding: .85rem 1rem;
    background: linear-gradient(135deg,var(--emsp-primary),var(--emsp-accent));
    color: #fff; font-weight: 700; font-size: .9rem;
    border: none; border-radius: 0;
    text-decoration: none; transition: filter .2s;
}
.btn-dl-main:hover { filter: brightness(1.1); color: #fff; }
.btn-dl-sub {
    padding: .55rem 1.2rem; font-size: .78rem;
    color: #6B6B6B; text-align: center;
    border-top: 1px solid #f1f5f9; background: linear-gradient(180deg, #f8fbf9 0%, #f8fafc 100%);
}
.btn-fav-wrap { padding: 1rem 1.2rem; }
.btn-fav {
    display: flex; align-items: center; justify-content: center; gap: .45rem;
    width: 100%; padding: .7rem 1rem;
    font-size: .86rem; font-weight: 600;
    border-radius: 10px; cursor: pointer;
    transition: all .2s;
}
.btn-fav.is-fav {
    background: #eaf2ff; border: 1.5px solid rgba(0,48,135,.22); color: var(--emsp-primary);
}
.btn-fav.not-fav {
    background: #fff; border: 1.5px solid #e2e8f0; color: #6B6B6B;
}
.btn-fav.not-fav:hover { background: #eef5ff; border-color: rgba(0,48,135,.22); color: var(--emsp-primary); }
.meta-tbl { width: 100%; }
.meta-tbl tr:not(:last-child) td { border-bottom: 1px solid #f1f5f9; }
.meta-tbl td { padding: .55rem 1.2rem; font-size: .8rem; vertical-align: top; }
.meta-tbl td:first-child { color: #6B6B6B; white-space: nowrap; width: 38%; }
.meta-tbl td:last-child  { color: #334155; font-weight: 500; }
.author-body {
    padding: 1.2rem; display: flex; align-items: center; gap: .8rem;
}
.auth-av {
    width: 44px; height: 44px; border-radius: 50%;
    background: linear-gradient(135deg,var(--emsp-primary),var(--emsp-accent));
    color: #fff; font-size: .88rem; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; overflow: hidden;
}
.auth-av img { width: 100%; height: 100%; object-fit: cover; }
.auth-name { font-weight: 700; font-size: .88rem; color: #1e293b; }
.author-link {
    color: var(--emsp-primary);
    font-size: .76rem;
    font-weight: 600;
    text-decoration: none;
}
.author-link:hover {
    text-decoration: underline;
}
.st-alert {
    border-radius: 12px; padding: .8rem 1rem;
    font-size: .83rem; display: flex;
    align-items: flex-start; gap: .55rem;
    margin-bottom: 1.2rem;
}
.st-alert i { font-size: 1.05rem; flex-shrink: 0; margin-top: .05rem; }
.st-warn { background: #fff3d6; color: #8a5a11; border: 1px solid rgba(186,117,23,.28); }
.st-err  { background: #fde7e6; color: #8f2524; border: 1px solid rgba(226,75,74,.28); }

.share-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .45rem;
    width: 100%;
    padding: .65rem .9rem;
    border-radius: 10px;
    background: #fff;
    border: 1.5px dashed rgba(0,48,135,.24);
    color: var(--emsp-primary);
    font-size: .82rem;
    font-weight: 700;
    cursor: pointer;
    transition: all .18s ease;
}
.share-btn:hover {
    background: #eef5ff;
    border-color: rgba(0,48,135,.4);
}
.share-hint {
    margin-top: .5rem;
    font-size: .75rem;
    color: #6B6B6B;
    text-align: center;
}
.reply-submit-btn,
.comment-submit-btn,
.moderate-doc-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .4rem;
    border: none;
    border-radius: 10px;
    font-weight: 700;
    text-decoration: none;
    transition: filter .18s ease, transform .18s ease;
}
.reply-submit-btn,
.comment-submit-btn {
    background: linear-gradient(135deg, var(--emsp-primary), var(--emsp-accent));
    color: #fff;
}
.reply-submit-btn:hover,
.comment-submit-btn:hover,
.moderate-doc-link:hover {
    filter: brightness(1.05);
    transform: translateY(-1px);
}
.reply-submit-btn {
    padding: .45rem .7rem;
}
.comment-submit-btn {
    padding: .55rem 1.25rem;
    font-size: .86rem;
}
.moderate-doc-link {
    width: 100%;
    padding: .78rem 1rem;
    background: #D4900A;
    color: #fff;
    border-radius: 12px;
    font-size: .86rem;
}

@media (max-width: 767px) {
    .doc-hero { padding: 1.4rem 0 1.1rem; }
    .doc-hero h1 { font-size: 1.15rem; }
    .hero-meta { gap: .45rem .9rem; font-size: .76rem; }
    .doc-body { padding: 1.1rem 0 2rem; }
    .pdf-wrap { padding: .65rem; min-height: 340px; }
    .cmt-list, .cmt-form-wrap { padding: .9rem 1rem; }
    .cmt-list { max-height: 60vh; overflow-y: auto; }
}
@media (min-width: 1200px) {
    .doc-sidebar-col {
        position: sticky;
        top: calc(var(--total-top-offset, 0px) + 1.5rem);
        align-self: flex-start;
    }
}
</style>
<!-- HERO -->
<section class="doc-hero">
<div class="container">
    <nav class="doc-hero-bc">
        <a href="index.php">Accueil</a>
        <span class="sep">/</span>
        <a href="bibliotheque.php">Bibliotheque</a>
        <?php if (!empty($doc['filiere_label_display'])): ?>
            <span class="sep">/</span>
            <a href="bibliotheque.php?filiere=<?= intval($doc['filiere_id'] ?? 0) ?>"><?= h(emsp_strim($doc['filiere_label_display'], 28, '...')) ?></a>
        <?php endif; ?>
        <?php if (!empty($doc['licence_name'])): ?>
            <span class="sep">/</span>
            <a href="bibliotheque.php?licence=<?= intval($doc['licence_id'] ?? 0) ?>"><?= h($doc['licence_name']) ?></a>
        <?php endif; ?>
        <span class="sep">/</span>
        <span class="cur"><?= h(emsp_strim($doc['title'], 48, '...')) ?></span>
    </nav>
    <h1><?= h($doc['title']) ?></h1>
    <p class="doc-hero-summary mb-0"><?= h($hero_summary) ?></p>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="type-pill type-pill-doc-<?= h($doc_type_key) ?>"><?= h($tl) ?></span>
        <?php if (!empty($doc['exam_session'])): ?>
            <span class="type-pill type-pill-ghost"><?= h($doc['exam_session']) ?></span>
        <?php endif; ?>
        <?php if ($status_label !== '' && $doc['status'] !== 'approved'): ?>
            <span class="type-pill type-pill-status-<?= h($status_key) ?>"><?= h($status_label) ?></span>
        <?php endif; ?>
    </div>
    <div class="hero-meta">
        <span><i class="bi bi-person"></i><?= h($doc['first_name'] . ' ' . $doc['last_name']) ?> <?= $badge_icons[$doc['badge_level']] ?? '' ?></span>
        <span><i class="bi bi-calendar3"></i><?= date('d/m/Y', strtotime($doc['created_at'])) ?></span>
        <?php if (!empty($doc['filiere_label_display'])): ?><span><i class="bi bi-diagram-3"></i><?= h($doc['filiere_label_display']) ?></span><?php endif; ?>
        <?php if (!empty($doc['licence_name'])): ?><span><i class="bi bi-mortarboard"></i><?= h($doc['licence_name']) ?></span><?php endif; ?>
        <span><i class="bi bi-file-earmark"></i><?= fmt_size(intval($doc['file_size_bytes'])) ?></span>
    </div>
</div>
</section>

<!-- BODY -->
<section class="doc-body">
<div class="container">

<?php if ($doc['status'] === 'pending' && $is_owner): ?>
<div class="st-alert st-warn">
    <i class="bi bi-hourglass-split"></i>
    <div><strong>En attente de validation</strong> - Visible apres approbation par un moderateur.
        <a href="dashboard.php" class="st-alert-link"> Voir mes documents -&gt;</a>
    </div>
</div>
<?php endif; ?>
<?php if ($doc['status'] === 'rejected' && ($is_owner || $is_staff)): ?>
<div class="st-alert st-err">
    <i class="bi bi-x-circle-fill"></i>
    <div><strong>Document rejete</strong>
        <?php if (!empty($doc['rejection_reason'])): ?> - <?= h($doc['rejection_reason']) ?><?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="row g-4">

<!-- === COLONNE PRINCIPALE === -->
<div class="col-lg-8">

    <!-- Viewer -->
    <div class="viewer-card">
        <div class="viewer-toolbar">
            <span class="vt-title">
                <i class="bi <?= h($doc_icon) ?> doc-preview-icon-<?= h($doc_icon_kind) ?>"></i> <?= h($preview_label) ?>
                <span class="legacy-preview-label">
                <?php if ($is_pdf): ?>
                    <i class="bi bi-file-earmark-pdf legacy-file-icon-pdf"></i> Apercu PDF
                <?php elseif ($is_image): ?>
                    <i class="bi bi-image legacy-file-icon-image"></i> Apercu image
                <?php elseif ($is_docx): ?>
                    <i class="bi bi-file-earmark-word legacy-file-icon-word"></i> Apercu DOCX
                <?php elseif ($is_xlsx): ?>
                    <i class="bi bi-file-earmark-excel legacy-file-icon-excel"></i> Apercu Excel
                <?php elseif ($is_text): ?>
                    <i class="bi bi-file-earmark-text legacy-file-icon-text"></i> Apercu texte
                <?php else: ?>
                    <i class="bi bi-file-earmark-text legacy-file-icon-default"></i> Document
                <?php endif; ?>
                </span>
            </span>
            <?php if ($can_view): ?>
            <span class="small text-muted">Telechargement via le bouton principal</span>
            <?php endif; ?>
        </div>

        <div id="doc-preview-zone">
            <div id="doc-preview-spinner" class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Chargement...</span>
                </div>
            </div>
            <div id="doc-preview-content"></div>
            <div id="doc-preview-error" class="alert alert-warning mt-3 emsp-hidden"></div>
        </div>

        <?php if ($can_view): ?>
        <div class="mt-3">
            <form method="POST"
                  action="telecharger.php?id=<?= intval($doc['id']) ?>&download=1"
                  class="d-inline">
                <input type="hidden"
                       name="csrf_token"
                       value="<?= generate_csrf_token() ?>">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-download me-1"></i>Telecharger
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div><!-- /viewer-card -->

    <!-- Description -->
    <?php if (!empty($doc['description'])): ?>
    <div class="desc-card">
        <div class="dc-label"><i class="bi bi-text-paragraph me-1"></i>Description</div>
        <p><?= h($doc['description']) ?></p>
    </div>
    <?php endif; ?>

    <!-- Stats + like -->
    <?php if ($doc['status'] === 'approved'): ?>
    <div class="stats-bar">
        <?php if ($isAuth): ?>
        <button class="btn-like <?= $is_liked ? 'liked' : 'not-liked' ?>" type="button"
                id="btn-like"
                data-doc="<?= $doc_id ?>"
                data-liked="<?= $is_liked ? '1' : '0' ?>">
            <i class="bi <?= $is_liked ? 'bi-heart-fill' : 'bi-heart' ?>"></i>
            <span id="lk-count"><?= intval($doc['like_count']) ?></span>
            <span id="lk-label"><?= intval($doc['like_count']) == 1 ? 'like' : 'likes' ?></span>
        </button>
        <?php else: ?>
        <a class="btn btn-outline-primary btn-sm" href="login.php" title="Connectez-vous pour aimer ce document">
            <i class="bi bi-box-arrow-in-right me-1"></i>Connexion pour reagir
        </a>
        <?php endif; ?>
        <span id="lk-error" class="stat-item text-danger emsp-hidden"></span>
        <span class="stat-item">
            <i class="bi bi-download"></i>
            <?= intval($doc['download_count']) ?> téléchargement<?= intval($doc['download_count']) > 1 ? 's' : '' ?>
        </span>
        <span class="stat-item stat-comments">
            <i class="bi bi-chat"></i>
            <?= $cmt_count ?> commentaire<?= $cmt_count > 1 ? 's' : '' ?>
        </span>
    </div>
    <?php endif; ?>

    <!-- Commentaires -->
    <div class="cmt-card" id="commentaires">
        <div class="cmt-header">
            <i class="bi bi-chat-dots"></i>
            Commentaires
            <span class="cmt-count"><?= $cmt_count ?></span>
        </div>

        <div class="cmt-list">
        <?php if ($cmt_count === 0): ?>
            <div class="no-cmt">
                <i class="bi bi-chat-square-text"></i>
                <p class="mb-0 small">Aucun commentaire.<br>Soyez le premier !</p>
            </div>
        <?php else: ?>
            <?php foreach ($comments as $c): ?>
            <div class="cmt-item" id="comment-<?= intval($c['id']) ?>">
                <div class="c-av">
                    <?php
                    $initials = strtoupper(substr($c['first_name'], 0, 1) . substr($c['last_name'], 0, 1));
                    ?>
                    <?php $cPhoto = emsp_user_photo_src((string) ($c['photo_path'] ?? '')); ?>
                    <?php if ($cPhoto !== ''): ?>
                        <img src="<?= h($cPhoto) ?>" alt=""
                             onerror="this.style.display='none';this.parentNode.textContent='<?= h($initials) ?>'">
                    <?php else: ?>
                        <?= h($initials) ?>
                    <?php endif; ?>
                </div>
                <div class="flex-grow-1 emsp-min-w-0">
                    <div>
                        <a class="c-name text-decoration-none" href="profil-public.php?id=<?= intval($c['author_id']) ?>">
                            <?= h($c['first_name'] . ' ' . $c['last_name']) ?>
                        </a>
                        <?= $badge_icons[$c['badge_level']] ?? '' ?>
                        <span class="c-ago"><?= time_ago($c['created_at']) ?></span>
                    </div>
                    <div class="c-text"><?= nl2br(h($c['content'])) ?></div>
                    <?php if ($doc['status'] === 'approved' && $isAuth): ?>
                    <button class="c-rep-btn toggle-reply" type="button" data-comment="<?= intval($c['id']) ?>">
                        <i class="bi bi-reply"></i>Repondre
                    </button>
                    <?php endif; ?>

                    <?php if ($doc['status'] === 'approved' && $isAuth): ?>
                    <div class="cmt-reactions" data-comment="<?= intval($c['id']) ?>">
                        <?php
                        $cid = intval($c['id']);
                        $my_reac = $my_reactions[$cid] ?? '';
                        $counts = $reaction_counts[$cid] ?? [];
                        foreach ($reaction_labels as $rk => $label):
                            $cnt = intval($counts[$rk] ?? 0);
                            $active = ($my_reac === $rk);
                        ?>
                        <button type="button"
                                class="react-btn <?= $active ? 'active' : '' ?>"
                                data-comment="<?= $cid ?>"
                                data-reaction="<?= h($rk) ?>">
                            <span class="react-label"><?= h($label) ?></span>
                            <span class="react-count"><?= $cnt ?></span>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <div class="text-danger small mt-2 cmt-react-error emsp-hidden"></div>
                    <?php endif; ?>

                    <!-- Reponses -->
                    <?php if (!empty($replies_by_cmt[$c['id']])): ?>
                    <div class="replies-wrap">
                        <?php foreach ($replies_by_cmt[$c['id']] as $rep): ?>
                        <div class="rep-item" id="reply-<?= intval($rep['id'] ?? 0) ?>">
                            <div class="c-av c-av-sm">
                                <?php
                                $rep_initials = strtoupper(substr($rep['first_name'], 0, 1) . substr($rep['last_name'], 0, 1));
                                ?>
                                <?php $repPhoto = emsp_user_photo_src((string) ($rep['photo_path'] ?? '')); ?>
                                <?php if ($repPhoto !== ''): ?>
                                    <img src="<?= h($repPhoto) ?>" alt=""
                                         onerror="this.style.display='none';this.parentNode.textContent='<?= h($rep_initials) ?>'">
                                <?php else: ?>
                                    <?= h($rep_initials) ?>
                                <?php endif; ?>
                            </div>
                            <div class="emsp-min-w-0">
                                <div>
                                    <a class="c-name text-decoration-none" href="profil-public.php?id=<?= intval($rep['author_id'] ?? 0) ?>"
                                       class="emsp-fs-13">
                                        <?= h($rep['first_name'] . ' ' . $rep['last_name']) ?>
                                    </a>
                                    <?= $badge_icons[$rep['badge_level']] ?? '' ?>
                                    <span class="c-ago"><?= time_ago($rep['created_at']) ?></span>
                                </div>
                                <div class="c-text emsp-fs-13">
                                    <?= nl2br(h($rep['content'])) ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Formulaire reponse -->
                    <?php if ($doc['status'] === 'approved' && $isAuth): ?>
                    <div class="rep-form-wrap emsp-hidden" id="rep-form-<?= intval($c['id']) ?>">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                            <input type="hidden" name="reply_comment_id" value="<?= intval($c['id']) ?>">
                            <div class="reply-form-row">
                                <textarea class="form-control form-control-sm reply-form-textarea"
                                          name="reply_content" rows="2"
                                          placeholder="Votre reponse..." required></textarea>
                                <button type="submit" class="reply-submit-btn">
                                    <i class="bi bi-send-fill"></i>
                                </button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
        </div>

        <!-- Nouveau commentaire -->
        <?php if ($doc['status'] === 'approved' && $isAuth): ?>
        <div class="cmt-form-wrap">
            <div class="cf-label"><i class="bi bi-pencil-square me-1"></i>Ajouter un commentaire</div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                <textarea class="form-control mb-2"
                          name="comment_content" rows="3"
                          placeholder="Partagez votre avis sur ce document..." required></textarea>
                <button type="submit" class="comment-submit-btn">
                    <i class="bi bi-send-fill"></i>Publier
                </button>
            </form>
        </div>
        <?php elseif ($doc['status'] === 'approved'): ?>
        <div class="alert alert-light border mb-0">
            <i class="bi bi-lock me-2"></i>
            <a href="login.php" class="fw-semibold text-decoration-none">Connectez-vous</a>
            pour commenter, reagir et enregistrer ce document dans vos favoris.
        </div>
        <?php endif; ?>
    </div><!-- /cmt-card -->

</div><!-- /col-lg-8 -->

<!-- === SIDEBAR === -->
<div class="col-lg-4 doc-sidebar-col">

    <div class="side-card">
        <div class="side-card-header"><i class="bi <?= h($doc_icon) ?>"></i>Document</div>
        <div class="author-body">
            <div class="auth-av auth-av-doctype-<?= h($doc_icon_kind) ?>">
                <i class="bi <?= h($doc_icon) ?>"></i>
            </div>
            <div class="min-w-0">
                <div class="auth-name"><?= h(emsp_strim($doc['title'], 54, '...')) ?></div>
                <div class="small text-muted mt-1 emsp-min-w-0">
                    <?= h($tl) ?><?php if (!empty($doc['matiere_display'])): ?> - <?= h($doc['matiere_display']) ?><?php endif; ?>
                </div>
                <?php if ($status_label !== ''): ?>
                    <div class="mt-2">
                        <span class="type-pill type-pill-status-<?= h($status_key) ?> emsp-fs-13"><?= h($status_label) ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Telechargement -->
    <?php if ($can_view): ?>
    <div class="side-card">
        <form method="POST"
              action="telecharger.php?id=<?= intval($doc['id']) ?>&download=1"
              class="d-inline">
            <input type="hidden"
                   name="csrf_token"
                   value="<?= generate_csrf_token() ?>">
            <button type="submit" class="btn-dl-main">
                <i class="bi bi-download emsp-icon-18"></i>
                Telecharger le document
            </button>
        </form>
        <div class="btn-dl-sub">
            <i class="bi bi-file-earmark me-1"></i><?= fmt_size(intval($doc['file_size_bytes'])) ?>
            &nbsp;&middot;&nbsp;
            <i class="bi bi-arrow-down-circle me-1"></i><?= intval($doc['download_count']) ?> telechargement<?= intval($doc['download_count']) > 1 ? 's' : '' ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Favori -->
    <div class="side-card">
        <div class="btn-fav-wrap">
            <?php if ($isAuth): ?>
            <button type="button"
                    id="btn-fav"
                    class="btn-fav <?= $is_fav ? 'is-fav' : 'not-fav' ?>"
                    data-doc-id="<?= (int) $doc['id'] ?>"
                    data-fav="<?= $is_fav ? '1' : '0' ?>"
                    aria-pressed="<?= $is_fav ? 'true' : 'false' ?>">
                <i class="bi <?= $is_fav ? 'bi-star-fill' : 'bi-star' ?> emsp-icon-15"></i>
                <span class="btn-fav-label"><?= $is_fav ? 'Retirer des favoris' : 'Ajouter aux favoris' ?></span>
            </button>
            <noscript>
                <form method="POST" class="mt-2">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
                    <input type="hidden" name="fav_action" value="<?= $is_fav ? 'remove' : 'add' ?>">
                    <button type="submit" class="btn-fav <?= $is_fav ? 'is-fav' : 'not-fav' ?>">
                        <i class="bi <?= $is_fav ? 'bi-star-fill' : 'bi-star' ?> emsp-icon-15"></i>
                        <?= $is_fav ? 'Retirer des favoris' : 'Ajouter aux favoris' ?>
                    </button>
                </form>
            </noscript>
            <?php else: ?>
            <a href="login.php" class="btn-fav not-fav text-decoration-none justify-content-center">
                <i class="bi bi-box-arrow-in-right emsp-icon-15"></i>
                <span class="btn-fav-label">Connexion pour ajouter aux favoris</span>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="side-card">
        <div class="side-card-header"><i class="bi bi-share"></i>Partager</div>
        <div class="btn-fav-wrap">
            <button type="button"
                    id="btn-share-document"
                    class="share-btn"
                    data-share-url="<?= h($share_url) ?>">
                <i class="bi bi-link-45deg"></i>
                Copier le lien du document
            </button>
            <div class="share-hint" id="share-feedback">Partage rapide vers un camarade ou un enseignant.</div>
        </div>
    </div>

    <!-- Auteur -->
    <div class="side-card">
        <div class="side-card-header"><i class="bi bi-person-circle"></i>Auteur</div>
        <div class="author-body">
            <div class="auth-av">
                <?php
                $auth_initials = strtoupper(substr($doc['first_name'], 0, 1) . substr($doc['last_name'], 0, 1));
                ?>
                <?php $authorPhoto = emsp_user_photo_src((string) ($doc['photo_path'] ?? '')); ?>
                <?php if ($authorPhoto !== ''): ?>
                    <img src="<?= h($authorPhoto) ?>" alt=""
                         onerror="this.style.display='none';this.parentNode.textContent='<?= h($auth_initials) ?>'">
                <?php else: ?>
                    <?= h($auth_initials) ?>
                <?php endif; ?>
            </div>
            <div>
                <div class="auth-name">
                    <?= h($doc['first_name'] . ' ' . $doc['last_name']) ?>
                    <?= $badge_icons[$doc['badge_level']] ?? '' ?>
                </div>
                <a href="profil-public.php?id=<?= intval($doc['uploader_id']) ?>" class="author-link">
                    Voir le profil -&gt;
                </a>
            </div>
        </div>
    </div>

    <!-- Metadonnees -->
    <div class="side-card">
        <div class="side-card-header"><i class="bi bi-info-circle"></i>Informations</div>
        <table class="meta-tbl">
            <tr>
                <td>Statut</td>
                <td><span class="doc-meta-pill doc-meta-pill-status-<?= h($status_key) ?>"><?= h($status_label !== '' ? $status_label : 'Document') ?></span></td>
            </tr>
            <tr>
                <td>Format</td>
                <td><?= h($ext !== '' ? strtoupper($ext) : strtoupper((string) ($doc['mime_type'] ?? 'fichier'))) ?></td>
            </tr>
            <tr>
                <td>Type</td>
                <td><span class="doc-meta-pill doc-meta-pill-doc-<?= h($doc_type_key) ?>">
                    <?= h($tl) ?></span></td>
            </tr>
            <?php if (!empty($doc['filiere_label_display'])): ?><tr><td>Filieres</td><td><?= h($doc['filiere_label_display']) ?></td></tr><?php endif; ?>
            <?php if (!empty($doc['licence_name'])): ?><tr><td>Niveau</td><td><?= h($doc['licence_name']) ?></td></tr><?php endif; ?>
            <?php if (!empty($doc['matiere_display'])): ?><tr><td>Matiere</td><td><?= h($doc['matiere_display']) ?></td></tr><?php endif; ?>
            <?php if (!empty($doc['exam_session'])): ?><tr><td>Session</td><td><?= h($doc['exam_session']) ?></td></tr><?php endif; ?>
            <?php if (!empty($doc['exam_year'])): ?><tr><td>Annee</td><td><?= h((string) $doc['exam_year']) ?></td></tr><?php endif; ?>
            <tr><td>Depose le</td><td><?= date('d/m/Y', strtotime($doc['created_at'])) ?></td></tr>
            <?php if (!empty($doc['approver_first'])): ?>
            <tr><td>Valide par</td><td><?= h($doc['approver_first'] . ' ' . $doc['approver_last']) ?></td></tr>
            <?php endif; ?>
        </table>
    </div>

    <!-- Bouton moderation admin -->
    <?php if ($is_staff && $doc['status'] === 'pending'): ?>
    <a href="admin/pending-documents.php" class="moderate-doc-link">
        <i class="bi bi-shield-check"></i>Moderer ce document
    </a>
    <?php endif; ?>

</div><!-- /sidebar -->
</div><!-- /row -->
</div><!-- /container -->
</section>

<!-- Librairies de previsualisation (local) -->
<script>
(function(){
'use strict';

document.addEventListener('DOMContentLoaded', function(){
    var mime = <?= json_encode($mime) ?>;
    var ext = <?= json_encode($ext) ?>;
    var previewUrl = 'telecharger.php?id=<?= $doc_id ?>&preview=1';
    var rawUrl = 'telecharger.php?id=<?= $doc_id ?>&raw=1';
    var normalizedDownloadLabel = '<?= intval($doc["download_count"]) ?> telechargement<?= intval($doc["download_count"]) > 1 ? "s" : "" ?>';

    var spinner = document.getElementById('doc-preview-spinner');
    var content = document.getElementById('doc-preview-content');
    var error = document.getElementById('doc-preview-error');

    if (!content) { return; }

    document.querySelectorAll('.stats-bar .stat-item').forEach(function(item) {
        if (item.querySelector('.bi-download')) {
            item.innerHTML = '<i class="bi bi-download"></i>' + normalizedDownloadLabel;
        }
    });

    function showSpinner(show) {
        if (spinner) { spinner.style.display = show ? 'block' : 'none'; }
    }
    function clearError() {
        if (error) {
            error.classList.add('emsp-hidden');
            error.style.display = 'none';
            error.textContent = '';
        }
    }
    function setError(msg) {
        if (error) {
            error.textContent = msg || 'Apercu indisponible pour ce format.';
            error.classList.remove('emsp-hidden');
            error.style.display = 'block';
        }
    }
    function setContent(node) {
        content.innerHTML = '';
        if (node) { content.appendChild(node); }
    }
    function renderLimitedPreview(message) {
        clearError();
        showSpinner(false);

        var card = document.createElement('div');
        card.className = 'alert alert-light border rounded-4 p-4 m-3 text-start';
        card.innerHTML = ''
            + '<div class="d-flex align-items-start gap-3">'
            + '  <div class="fs-1 text-secondary"><i class="bi bi-file-earmark-lock"></i></div>'
            + '  <div>'
            + '    <h5 class="mb-2">Apercu limite</h5>'
            + '    <p class="mb-2 text-muted">' + (message || 'Ce format ne peut pas etre affiche directement dans le navigateur.') + '</p>'
            + '    <div class="small text-muted">Type : ' + (ext ? ext.toUpperCase() : 'FICHIER') + ' - Taille : <?= h(fmt_size((int) ($doc['file_size_bytes'] ?? 0))) ?></div>'
            + '  </div>'
            + '</div>';
        setContent(card);
    }

    var isPdf = (mime === 'application/pdf');
    var isDocx = (mime.indexOf('wordprocessingml') !== -1 || ext === 'docx');
    var isXlsx = (mime.indexOf('spreadsheetml') !== -1 || ext === 'xlsx');
    var isText = (mime.indexOf('text/') === 0
        || ['txt', 'csv', 'md', 'log', 'json', 'xml'].indexOf(ext) !== -1
        || ['application/json', 'application/xml'].indexOf(mime) !== -1);
    var isImage = (mime.indexOf('image/') === 0);
    var isMobileViewport = !!(window.matchMedia && window.matchMedia('(max-width: 767.98px)').matches);
    var shouldDeferHeavyPreview = isMobileViewport && (isDocx || isXlsx);
    var scriptPromises = {};

    // Masquer spinner par defaut
    showSpinner(false);

    function loadScriptOnce(src) {
        if (scriptPromises[src]) {
            return scriptPromises[src];
        }

        scriptPromises[src] = new Promise(function(resolve, reject) {
            var selector = 'script[data-emsp-src="' + src + '"]';
            var existing = document.querySelector(selector);
            if (existing) {
                if (existing.dataset.loaded === '1') {
                    resolve();
                    return;
                }
                existing.addEventListener('load', function(){ resolve(); }, {once: true});
                existing.addEventListener('error', function(){ reject(new Error('script load failed')); }, {once: true});
                return;
            }

            var script = document.createElement('script');
            script.src = src;
            script.async = true;
            script.dataset.emspSrc = src;
            script.onload = function() {
                script.dataset.loaded = '1';
                resolve();
            };
            script.onerror = function() {
                reject(new Error('script load failed'));
            };
            document.body.appendChild(script);
        });

        return scriptPromises[src];
    }

    function ensureDocxPreviewLibs() {
        var tasks = [];
        if (typeof mammoth === 'undefined') {
            tasks.push(loadScriptOnce('assets/js/mammoth.browser.min.js'));
        }
        if (!window.DOMPurify) {
            tasks.push(loadScriptOnce('assets/js/purify.min.js'));
        }
        return Promise.all(tasks);
    }

    function ensureXlsxPreviewLib() {
        if (typeof XLSX !== 'undefined') {
            return Promise.resolve();
        }
        return loadScriptOnce('assets/js/xlsx.full.min.js');
    }

    function renderPreviewLauncher(options) {
        clearError();
        showSpinner(false);

        var wrap = document.createElement('div');
        wrap.className = 'text-center py-4 px-3';

        var icon = document.createElement('i');
        icon.className = 'bi ' + (options.icon || 'bi-file-earmark-text') + ' fs-2 d-block mb-3';
        icon.style.color = options.color || '#004D2A';

        var title = document.createElement('strong');
        title.className = 'd-block mb-2';
        title.textContent = options.title || 'Previsualisation';

        var detail = document.createElement('p');
        detail.className = 'text-muted small mb-3';
        detail.textContent = options.detail || '';

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-outline-primary';
        button.textContent = options.buttonLabel || 'Afficher l apercu';
        button.addEventListener('click', function() {
            options.onLaunch(button);
        });

        wrap.appendChild(icon);
        wrap.appendChild(title);
        if (detail.textContent !== '') {
            wrap.appendChild(detail);
        }
        wrap.appendChild(button);
        setContent(wrap);
        return button;
    }

    // Fallback sans telechargement automatique
    function showPdfFallback(viewerUrl) {
        showSpinner(false);
        clearError();
        renderPreviewLauncher({
            icon: 'bi-file-earmark-pdf',
            color: '#C0392B',
            title: 'Lecteur PDF indisponible',
            detail: 'Le lecteur PDF integre n a pas pu se charger. Ouvrez le lecteur PDF dans un nouvel onglet pour continuer la lecture.',
            buttonLabel: 'Ouvrir le lecteur PDF',
            onLaunch: function() {
                var opened = null;
                try {
                    opened = window.open(viewerUrl, '_blank', 'noopener');
                } catch (error) {
                    opened = null;
                }
                if (!opened) {
                    window.location.href = viewerUrl;
                }
            }
        });
        return;
        var msg = document.createElement('div');
        msg.className = 'alert alert-info text-center py-4';
        msg.innerHTML = '<i class="bi bi-file-earmark-pdf fs-2 d-block mb-2"></i>'
            + '<strong>Apercu PDF non disponible.</strong><br>'
            + 'Votre navigateur ou une extension bloque la previsualisation.<br>'
            + '<small class="text-muted">Utilisez le bouton Telecharger ci-dessous.</small>';
        setContent(msg);
    }

    function loadPdfPreview() {
        clearError();
        showSpinner(true);

        var viewer = document.createElement('iframe');
        var pdfUrl = new URL(previewUrl, window.location.href).toString();
        var viewerUrl = isMobileViewport
            ? new URL('assets/js/pdfjs/web/viewer.html?file=' + encodeURIComponent(pdfUrl), window.location.href).toString()
            : (pdfUrl + '#toolbar=1&navpanes=0&scrollbar=1&view=FitH');
        viewer.src = viewerUrl;
        viewer.title = 'Apercu PDF';
        viewer.loading = 'lazy';
        viewer.style.width = '100%';
        viewer.style.minHeight = isMobileViewport ? '82vh' : '78vh';
        viewer.style.border = '0';
        viewer.style.borderRadius = '14px';
        viewer.style.background = '#ffffff';
        viewer.allow = 'fullscreen';
        viewer.onload = function() {
            showSpinner(false);
        };
        viewer.onerror = function() {
            showPdfFallback(viewerUrl);
        };
        setContent(viewer);
    }

    if (isPdf) {
        loadPdfPreview();
        return;
    }

    if (isImage) {
        clearError();
        showSpinner(true);
        var img = document.createElement('img');
        img.src = previewUrl;
        img.alt = <?= json_encode($doc['title'] ?? '') ?>;
        img.style.maxWidth = '100%';
        img.style.maxHeight = '600px';
        img.style.display = 'block';
        img.style.margin = '0 auto';
        img.onload = function(){ showSpinner(false); };
        img.onerror = function(){
            showSpinner(false);
            setError("Impossible d'afficher l'image.");
        };
        setContent(img);
        return;
    }

    if (isDocx) {
        renderPreviewLauncher({
            icon: 'bi-file-earmark-word',
            color: '#004D2A',
            title: 'Apercu Word a la demande',
            detail: shouldDeferHeavyPreview
                ? 'Sur mobile, le document Word est charge uniquement lorsque vous le demandez.'
                : 'Chargez l apercu Word uniquement si vous en avez besoin.',
            buttonLabel: 'Charger l apercu Word',
            onLaunch: function(button) {
                clearError();
                showSpinner(true);
                if (button) { button.disabled = true; }

                ensureDocxPreviewLibs()
                    .then(function(){
                        return fetch(rawUrl, {credentials: 'include'});
                    })
                    .then(function(r){ if (!r.ok) throw new Error('HTTP ' + r.status); return r.arrayBuffer(); })
                    .then(function(ab){ return mammoth.convertToHtml({arrayBuffer: ab}); })
                    .then(function(res){
                        var html = (res && res.value) ? res.value : '';
                        if (window.DOMPurify) {
                            html = DOMPurify.sanitize(html);
                        }
                        var box = document.createElement('div');
                        box.style.maxHeight = '600px';
                        box.style.overflow = 'auto';
                        box.innerHTML = html || '<p class="text-muted mb-0">Aucun contenu.</p>';
                        setContent(box);
                        showSpinner(false);
                    })
                    .catch(function(){
                        showSpinner(false);
                        if (button) { button.disabled = false; }
                        setError('Apercu non disponible pour ce format.');
                    });
            }
        });
        return;
    }

    if (isXlsx) {
        renderPreviewLauncher({
            icon: 'bi-file-earmark-excel',
            color: '#006B3C',
            title: 'Apercu tableau a la demande',
            detail: shouldDeferHeavyPreview
                ? 'Sur mobile, le tableau est charge uniquement lorsque vous le demandez.'
                : 'Chargez l apercu du tableau uniquement si vous en avez besoin.',
            buttonLabel: 'Charger l apercu tableau',
            onLaunch: function(button) {
                clearError();
                showSpinner(true);
                if (button) { button.disabled = true; }

                ensureXlsxPreviewLib()
                    .then(function(){
                        return fetch(rawUrl, {credentials: 'include'});
                    })
                    .then(function(r){ if (!r.ok) throw new Error('HTTP ' + r.status); return r.arrayBuffer(); })
                    .then(function(ab){
                        var data = new Uint8Array(ab);
                        var wb = XLSX.read(data, {type: 'array'});
                        var sheetName = (wb.SheetNames && wb.SheetNames[0]) ? wb.SheetNames[0] : null;
                        if (!sheetName) { throw new Error('No sheet'); }
                        var ws = wb.Sheets[sheetName];
                        var range = null;
                        if (ws && ws['!ref']) {
                            range = XLSX.utils.decode_range(ws['!ref']);
                            range.e.r = Math.min(range.e.r, 99);
                        }
                        var html = XLSX.utils.sheet_to_html(ws, range ? {range: range} : undefined);
                        var wrap = document.createElement('div');
                        wrap.style.overflowX = 'auto';
                        wrap.innerHTML = html;
                        var table = wrap.querySelector('table');
                        if (table) { table.className = 'table table-sm table-bordered mb-0'; }
                        setContent(wrap);
                        showSpinner(false);
                    })
                    .catch(function(){
                        showSpinner(false);
                        if (button) { button.disabled = false; }
                        setError('Apercu non disponible pour ce format.');
                    });
            }
        });
        return;
    }

    if (isText) {
        clearError();
        showSpinner(true);
        fetch(rawUrl, {credentials: 'include'})
            .then(function(r){ if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
            .then(function(txt){
                var pre = document.createElement('pre');
                pre.style.maxHeight = '500px';
                pre.style.overflow = 'auto';
                pre.style.whiteSpace = 'pre-wrap';
                pre.textContent = txt || '';
                setContent(pre);
                showSpinner(false);
            })
            .catch(function(){
                renderLimitedPreview('Le contenu texte n a pas pu etre charge dans l apercu.');
            });
        return;
    }

    renderLimitedPreview('Ce format reste telechargeable explicitement, mais n est pas rendu inline pour eviter tout comportement de telechargement implicite.');
});

// Like AJAX
var btnLike = document.getElementById('btn-like');
if (btnLike) {
    btnLike.addEventListener('click', function(){
        var errEl = document.getElementById('lk-error');
        if (errEl) {
            errEl.classList.add('emsp-hidden');
            errEl.style.display = 'none';
            errEl.textContent = '';
        }
        var fd = new FormData();
        fd.append('document_id', this.dataset.doc);
        fd.append('csrf_token', '<?= h($csrf_token) ?>');
        fetch('like-handler.php', {method: 'POST', body: fd, credentials: 'include'})
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (d && d.error) {
                    if (errEl) {
                        errEl.textContent = d.message || 'Action impossible.';
                        errEl.classList.remove('emsp-hidden');
                        errEl.style.display = 'inline-flex';
                    }
                    if (window.emspUI && typeof window.emspUI.showError === 'function') {
                        window.emspUI.showError('Like indisponible', d.message || 'Action impossible.');
                    }
                    return;
                }
                document.getElementById('lk-count').textContent = d.like_count;
                document.getElementById('lk-label').textContent = d.like_count == 1 ? 'like' : 'likes';
                var icon = btnLike.querySelector('i');
                if (d.liked) {
                    btnLike.classList.add('liked');
                    icon.className = 'bi bi-heart-fill';
                } else {
                    btnLike.classList.remove('liked');
                    icon.className = 'bi bi-heart';
                }
            })
            .catch(function(){
                if (errEl) {
                    errEl.textContent = 'Erreur reseau.';
                    errEl.classList.remove('emsp-hidden');
                    errEl.style.display = 'inline-flex';
                }
                if (window.emspUI && typeof window.emspUI.showError === 'function') {
                    window.emspUI.showError('Erreur reseau', 'Impossible de contacter le serveur pour le like.');
                }
            });
    });
}

// Favori AJAX
var btnFav = document.getElementById('btn-fav');
if (btnFav) {
    btnFav.addEventListener('click', function(){
        if (btnFav.disabled) { return; }
        btnFav.disabled = true;
        var fd = new FormData();
        fd.append('document_id', btnFav.dataset.docId || '');
        fd.append('csrf_token', '<?= h($csrf_token) ?>');
        fetch('ajax/toggle-favori.php', {method: 'POST', body: fd, credentials: 'include'})
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (!d || !d.ok) {
                    if (window.emspUI && typeof window.emspUI.showError === 'function') {
                        window.emspUI.showError('Favori indisponible', d && d.message ? d.message : 'Impossible de mettre a jour vos favoris.');
                    }
                    return;
                }
                var added = d.action === 'added';
                btnFav.dataset.fav = added ? '1' : '0';
                btnFav.setAttribute('aria-pressed', added ? 'true' : 'false');
                btnFav.classList.toggle('is-fav', added);
                btnFav.classList.toggle('not-fav', !added);
                var icon = btnFav.querySelector('i');
                if (icon) { icon.className = added ? 'bi bi-star-fill' : 'bi bi-star'; }
                var label = btnFav.querySelector('.btn-fav-label');
                if (label) { label.textContent = added ? 'Retirer des favoris' : 'Ajouter aux favoris'; }
            })
            .catch(function(){
                if (window.emspUI && typeof window.emspUI.showError === 'function') {
                    window.emspUI.showError('Erreur reseau', 'Impossible de mettre a jour vos favoris pour le moment.');
                }
            })
            .then(function(){ btnFav.disabled = false; });
    });
}

var btnShare = document.getElementById('btn-share-document');
if (btnShare) {
    btnShare.addEventListener('click', function(){
        var shareUrl = btnShare.getAttribute('data-share-url') || window.location.href;
        var feedback = document.getElementById('share-feedback');

        function setShareFeedback(message) {
            if (feedback) {
                feedback.textContent = message;
            }
        }

        if (navigator.share) {
            navigator.share({
                title: <?= json_encode($doc['title'] ?? 'Document EMSP Docs') ?>,
                text: 'Document partage depuis EMSP Docs',
                url: shareUrl
            }).then(function(){
                setShareFeedback('Lien partage avec succes.');
            }).catch(function(){});
            return;
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(shareUrl)
                .then(function(){
                    setShareFeedback('Lien copie dans le presse-papiers.');
                })
                .catch(function(){
                    window.prompt('Copiez ce lien :', shareUrl);
                });
            return;
        }

        window.prompt('Copiez ce lien :', shareUrl);
    });
}

// Reactions commentaires
document.querySelectorAll('.react-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
        var commentId = btn.dataset.comment || '';
        var reaction = btn.dataset.reaction || '';
        if (!commentId || !reaction) { return; }
        var errorBox = btn.closest('.cmt-item') ? btn.closest('.cmt-item').querySelector('.cmt-react-error') : null;
        if (errorBox) {
            errorBox.textContent = '';
            errorBox.classList.add('emsp-hidden');
            errorBox.style.display = 'none';
        }
        var fd = new FormData();
        fd.append('comment_id', commentId);
        fd.append('reaction', reaction);
        fd.append('csrf_token', '<?= h($csrf_token) ?>');
        fetch('reaction-handler.php', {method: 'POST', body: fd, credentials: 'include'})
            .then(function(r){
                return r.text().then(function(text){
                    var payload = {};
                    try {
                        payload = text ? JSON.parse(text) : {};
                    } catch (error) {
                        payload = { error: 'invalid_json', message: 'Reponse serveur invalide.' };
                    }
                    if (!r.ok && !payload.error) {
                        payload.error = 'http_' + r.status;
                        payload.message = 'Le serveur a refuse la reaction (' + r.status + ').';
                    }
                    return payload;
                });
            })
            .then(function(d){
                if (!d || d.error) {
                    if (errorBox) {
                        errorBox.textContent = (d && d.message) ? d.message : 'Impossible de reagir pour le moment.';
                        errorBox.classList.remove('emsp-hidden');
                        errorBox.style.display = 'block';
                    }
                    if (window.emspUI && typeof window.emspUI.showError === 'function') {
                        window.emspUI.showError('Reaction indisponible', (d && d.message) ? d.message : 'Impossible de reagir pour le moment.');
                    }
                    return;
                }
                var wrap = btn.closest('.cmt-reactions');
                if (!wrap) { return; }
                wrap.querySelectorAll('.react-btn').forEach(function(b){
                    var key = b.dataset.reaction || '';
                    var countEl = b.querySelector('.react-count');
                    var count = (d.counts && key && d.counts[key] !== undefined) ? d.counts[key] : 0;
                    if (countEl) { countEl.textContent = count; }
                    b.classList.toggle('active', d.my_reaction === key);
                });
            })
            .catch(function(){
                if (errorBox) {
                    errorBox.textContent = 'Erreur reseau. Verifiez votre connexion puis reessayez.';
                    errorBox.classList.remove('emsp-hidden');
                    errorBox.style.display = 'block';
                }
                if (window.emspUI && typeof window.emspUI.showError === 'function') {
                    window.emspUI.showError('Erreur reseau', 'Verifiez votre connexion puis reessayez.');
                }
            });
    });
});

// Toggle reponse
document.querySelectorAll('.toggle-reply').forEach(function(btn){
    btn.addEventListener('click', function(){
        var f = document.getElementById('rep-form-' + this.dataset.comment);
        if (!f) return;
        var opening = f.classList.contains('emsp-hidden');
        f.classList.toggle('emsp-hidden');
        f.style.display = opening ? 'block' : 'none';
        if (opening) {
            var ta = f.querySelector('textarea');
            if (ta) ta.focus();
        }
    });
});

})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>




