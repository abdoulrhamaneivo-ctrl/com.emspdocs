<?php
include_once __DIR__ . '/includes/bootstrap.php';

include_once __DIR__ . '/admin/config/dbcon.php';
include_once __DIR__ . '/includes/notif-helper.php';

if (!empty($_SESSION['auth_user']['id'])) {
    emsp_mark_notifications_seen_for_section($con, (int) $_SESSION['auth_user']['id'], 'journal');
}

function emsp_clean_journal_html(string $html): string
{
    $html = emsp_fix_mojibake($html);
    $decoded = htmlspecialchars_decode($html, ENT_QUOTES);
    $decoded = str_replace(
        ['../uploads/', '..\\uploads\\', '../assets/', '..\\assets\\'],
        ['uploads/', 'uploads/', 'assets/', 'assets/'],
        $decoded
    );
    // MODIF 1: whitelist reduite aux balises Quill.js (retrait iframe/canvas/style/form/etc.).
    $allowed = '<p><br><h1><h2><h3><h4><h5><h6><ul><ol><li><strong><em><b><i><u><a><img><blockquote><pre><code><table><thead><tbody><tr><th><td><div><span><figure><figcaption><hr><sup><sub><small><mark><del><ins>';
    $clean = strip_tags($decoded, $allowed);
    // MODIF 2: suppression des href dangereux (javascript:, data:).
    $clean = preg_replace_callback('~<a\\b[^>]*>~i', function ($m) {
        $tag = $m[0];
        if (preg_match('/\\bhref\\s*=\\s*(?:\"([^\"]*)\"|\\\'([^\\\']*)\\\'|([^\\s>]+))/i', $tag, $hm)) {
            $href = $hm[1] !== '' ? $hm[1] : ($hm[2] !== '' ? $hm[2] : ($hm[3] ?? ''));
            $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('/^\\s*(javascript:|data:)/i', $href)) {
                $tag = preg_replace('/\\s*href\\s*=\\s*(?:\"[^\"]*\"|\\\'[^\\\']*\\\'|[^\\s>]+)/i', '', $tag);
            }
        }
        return $tag;
    }, $clean);
    // MODIF 3: suppression des images data: trop lourdes (> 100 Ko).
    $clean = preg_replace_callback('~<img\\b[^>]*>~i', function ($m) {
        $tag = $m[0];
        if (preg_match('/\\bsrc\\s*=\\s*(?:\"([^\"]*)\"|\\\'([^\\\']*)\\\'|([^\\s>]+))/i', $tag, $hm)) {
            $src = $hm[1] !== '' ? $hm[1] : ($hm[2] !== '' ? $hm[2] : ($hm[3] ?? ''));
            $src = html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('/^\\s*data:/i', $src)) {
                if (strlen($src) > (1024 * 100)) {
                    $tag = preg_replace('/\\s*src\\s*=\\s*(?:\"[^\"]*\"|\\\'[^\\\']*\\\'|[^\\s>]+)/i', '', $tag);
                }
            }
        }
        return $tag;
    }, $clean);
    return $clean;
}

function emsp_relative_date(?string $date): string
{
    if (!$date) return '';
    try {
        $dt = new DateTimeImmutable($date, new DateTimeZone('UTC'));
    } catch (Exception $e) {
        return '';
    }
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $diff = $now->getTimestamp() - $dt->getTimestamp();
    if ($diff < 60) return 'il y a moins d\'une minute';
    if ($diff < 3600) return 'il y a ' . intval($diff / 60) . ' min';
    if ($diff < 86400) return 'il y a ' . intval($diff / 3600) . ' h';
    if ($diff < 7 * 86400) return 'il y a ' . intval($diff / 86400) . ' jours';
    return 'le ' . $dt->format('d/m/Y');
}

function emsp_newsblog_excerpt(string $text, int $max = 160): string
{
    $clean = trim(strip_tags(emsp_fix_mojibake($text)));
    if (mb_strlen($clean) <= $max) return $clean;
    return rtrim(mb_substr($clean, 0, $max - 1)) . '...';
}

function emsp_stmt_bind_params(mysqli_stmt $stmt, string $types, array $values): void
{
    $bind = [];
    $bind[] = $types;
    foreach ($values as $k => $val) {
        $bind[] = &$values[$k];
    }
    mysqli_stmt_bind_param($stmt, ...$bind);
}

function emsp_newsblog_journal_type_meta(string $type): array
{
    $map = [
        'annonce' => ['label' => 'Annonce', 'color' => '#004D2A', 'class' => 'journal-card-annonce'],
        'defi' => ['label' => 'Défi', 'color' => '#D4900A', 'class' => 'journal-card-defi'],
        'sondage' => ['label' => 'Sondage', 'color' => '#006B3C', 'class' => 'journal-card-sondage'],
    ];

    $type = strtolower(trim($type));
    return $map[$type] ?? ['label' => ucfirst($type !== '' ? $type : 'Article'), 'color' => '#004D2A', 'class' => 'journal-card-annonce'];
}

function emsp_newsblog_type_key(string $type): string
{
    $type = strtolower(trim($type));
    if (!in_array($type, ['annonce', 'defi', 'sondage'], true)) {
        return 'annonce';
    }
    return $type;
}

function emsp_newsblog_journal_cover_src(string $html): string
{
    $decoded = htmlspecialchars_decode(emsp_fix_mojibake($html), ENT_QUOTES | ENT_HTML5);
    $decoded = str_replace(
        ['../uploads/', '..\\uploads\\', '../assets/', '..\\assets\\'],
        ['uploads/', 'uploads/', 'assets/', 'assets/'],
        $decoded
    );

    if (!preg_match('/<img\b[^>]*\bsrc\s*=\s*(?:"([^"]+)"|\'([^\']+)\'|([^\s>]+))/i', $decoded, $match)) {
        return '';
    }

    $src = trim((string) ($match[1] ?: ($match[2] ?: ($match[3] ?? ''))));
    if ($src === '' || preg_match('#^(?:javascript:|data:)#i', $src)) {
        return '';
    }

    if (preg_match('#^https?://#i', $src)) {
        return $src;
    }

    $src = ltrim(str_replace('\\', '/', $src), '/');
    if (strpos($src, 'uploads/') === 0 || strpos($src, 'assets/') === 0) {
        return $src;
    }

    return '';
}

function emsp_newsblog_journal_cover_html(array $item, string $variant = 'feature'): string
{
    $title = trim((string) ($item['title'] ?? 'Article'));
    $meta = emsp_newsblog_journal_type_meta((string) ($item['type'] ?? ''));
    $typeKey = emsp_newsblog_type_key((string) ($item['type'] ?? ''));
    $cover = emsp_newsblog_journal_cover_src((string) ($item['content'] ?? ''));
    $classes = 'journal-cover journal-cover-' . $variant;

    if ($cover !== '') {
        return '<div class="' . $classes . '">'
            . '<img src="' . htmlspecialchars($cover) . '" alt="' . htmlspecialchars($title) . '" class="journal-cover-img">'
            . '</div>';
    }

    $seed = $title !== '' ? $title : $meta['label'];
    $initial = mb_strtoupper(mb_substr($seed, 0, 1, 'UTF-8'), 'UTF-8');

    return '<div class="' . $classes . ' journal-cover-placeholder journal-cover-' . htmlspecialchars($typeKey, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">'
        . '<span class="journal-cover-type">' . htmlspecialchars($meta['label']) . '</span>'
        . '<span class="journal-cover-initial">' . htmlspecialchars($initial) . '</span>'
        . '</div>';
}

$journalItems = [];
$pollOptions = [];
$pollTotals = [];
$userVotes = [];
$defiTotals = [];
$userDefis = [];

$s = mysqli_prepare(
    $con,
    "SELECT id, type, title, content, created_at
     FROM journal
     WHERE status='published'
     ORDER BY created_at DESC
     LIMIT 8"
);
if ($s) {
    mysqli_stmt_execute($s);
    $r = emsp_stmt_fetch_all($s);
    foreach ($r as $row) {
        $row['title'] = emsp_fix_mojibake((string) ($row['title'] ?? ''));
        $row['content'] = emsp_fix_mojibake((string) ($row['content'] ?? ''));
        $journalItems[] = $row;
    }
    mysqli_stmt_close($s);
}

$journalIds = array_column($journalItems, 'id');
if (!empty($journalIds)) {
    $placeholders = implode(',', array_fill(0, count($journalIds), '?'));
    $types = str_repeat('i', count($journalIds));

    // Options + votes
    $sql = "SELECT jo.journal_id, jo.id, jo.label, COUNT(jv.id) AS votes
            FROM journal_options jo
            LEFT JOIN journal_votes jv ON jv.option_id = jo.id
            WHERE jo.journal_id IN ($placeholders)
            GROUP BY jo.id, jo.journal_id, jo.label
            ORDER BY jo.id";
    $stmt = mysqli_prepare($con, $sql);
    if ($stmt) {
        emsp_stmt_bind_params($stmt, $types, $journalIds);
        mysqli_stmt_execute($stmt);
        $rows = emsp_stmt_fetch_all($stmt);
        mysqli_stmt_close($stmt);
        foreach ($rows as $row) {
            $jid = intval($row['journal_id']);
            $opt = [
                'id' => intval($row['id']),
                'label' => emsp_fix_mojibake((string) ($row['label'] ?? '')),
                'votes' => intval($row['votes'])
            ];
            $pollOptions[$jid][] = $opt;
            $pollTotals[$jid] = ($pollTotals[$jid] ?? 0) + $opt['votes'];
        }
    }

    // Votes utilisateur
    if (!empty($_SESSION['auth_user']['id'])) {
        $uid = intval($_SESSION['auth_user']['id']);
        $values = array_merge([$uid], $journalIds);
        $types2 = 'i' . $types;

        $sql = "SELECT journal_id, option_id FROM journal_votes WHERE user_id=? AND journal_id IN ($placeholders)";
        $stmt = mysqli_prepare($con, $sql);
        if ($stmt) {
            emsp_stmt_bind_params($stmt, $types2, $values);
            mysqli_stmt_execute($stmt);
            $rows = emsp_stmt_fetch_all($stmt);
            mysqli_stmt_close($stmt);
            foreach ($rows as $row) {
                $userVotes[intval($row['journal_id'])] = intval($row['option_id']);
            }
        }

        $sql = "SELECT journal_id FROM journal_defis WHERE user_id=? AND journal_id IN ($placeholders)";
        $stmt = mysqli_prepare($con, $sql);
        if ($stmt) {
            emsp_stmt_bind_params($stmt, $types2, $values);
            mysqli_stmt_execute($stmt);
            $rows = emsp_stmt_fetch_all($stmt);
            mysqli_stmt_close($stmt);
            foreach ($rows as $row) {
                $userDefis[intval($row['journal_id'])] = true;
            }
        }
    }

    // Totaux defis
    $sql = "SELECT journal_id, COUNT(*) AS nb FROM journal_defis WHERE journal_id IN ($placeholders) GROUP BY journal_id";
    $stmt = mysqli_prepare($con, $sql);
    if ($stmt) {
        emsp_stmt_bind_params($stmt, $types, $journalIds);
        mysqli_stmt_execute($stmt);
        $rows = emsp_stmt_fetch_all($stmt);
        mysqli_stmt_close($stmt);
        foreach ($rows as $row) {
            $defiTotals[intval($row['journal_id'])] = intval($row['nb']);
        }
    }
}

$featured = $journalItems[0] ?? null;
$sidebar = array_slice($journalItems, 1, 3);
$recentJournal = array_slice($journalItems, 4);
$latestJournalId = (int) ($featured['id'] ?? 0);

$page_title = 'Journal EMSP';
$extra_head_tags = implode("\n", [
    '<meta property="og:title" content="' . htmlspecialchars('Journal EMSP Docs', ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">',
    '<meta property="og:description" content="' . htmlspecialchars('Actualités, annonces, défis et sondages publiés pour la communauté EMSP.', ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">',
    '<meta property="og:url" content="' . htmlspecialchars('https://emspdocs.unaux.com/news-blog.php', ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">',
    '<meta property="og:type" content="website">',
    '<meta property="og:site_name" content="EMSP Docs">',
]);
include __DIR__ . '/includes/header.php';
?>

<style>
.journal-shell {
    background:
        radial-gradient(circle at top right, rgba(0,85,204,.06), transparent 26%),
        radial-gradient(circle at top left, rgba(0,48,135,.06), transparent 22%),
        linear-gradient(180deg, #f8fafc 0%, #ffffff 22%, #f6f8fb 100%);
}
.journal-shell-header {
    border-radius: 22px;
    background:
        linear-gradient(120deg, rgba(8,21,44,.82) 0%, rgba(26,60,110,.78) 44%, rgba(8,97,54,.5) 100%),
        url('assets/images/emsp-campus-ceremony.jpg') center 24% / cover no-repeat;
    box-shadow: var(--emsp-panel-shadow);
}
.journal-shell-header::after {
    background: linear-gradient(90deg, rgba(8,19,38,.84) 0%, rgba(8,19,38,.56) 54%, rgba(8,19,38,.16) 100%);
}
.journal-card-annonce { border-left: 4px solid #004D2A; }
.journal-card-defi { border-left: 4px solid #D4900A; }
.journal-card-sondage { border-left: 4px solid #006B3C; }
.journal-card {
    overflow: hidden;
    border-radius: 18px;
    border: 1px solid rgba(0,48,135,.12);
    box-shadow: 0 10px 28px rgba(0,0,0,.05) !important;
    transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
}
.journal-card {
    background: #fff;
}
.journal-panel {
    border: 1px solid rgba(0,48,135,.1);
    border-radius: 18px;
    background: #fff;
    box-shadow: 0 10px 26px rgba(0,0,0,.04);
    padding: 1.1rem 1.2rem;
}
.journal-panel h3 {
    font-size: 1.05rem;
    font-weight: 700;
    margin-bottom: .55rem;
    color: #102f50;
}
.journal-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 16px 34px rgba(0,0,0,.08) !important;
}
.journal-card .card-body {
    padding: 1.25rem;
}
.journal-shell .alert {
    border-radius: 14px;
    border: 1px solid rgba(0,48,135,.12);
    background: #f7f9fc;
}
.journal-type-badge {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    padding: .35rem .75rem;
    border-radius: 999px;
    background: rgba(26,60,110,.08);
    color: #004D2A;
    font-weight: 700;
    font-size: .78rem;
}
.journal-cover {
    position: relative;
    overflow: hidden;
    background: #eef3f8;
}
.journal-cover-feature {
    min-height: 250px;
    max-height: 360px;
}
.journal-cover-feature .journal-cover-img {
    width: 100%;
    height: 100%;
    min-height: 250px;
    max-height: 360px;
    object-fit: cover;
    display: block;
}
.journal-cover-side,
.journal-cover-grid {
    height: 100%;
    min-height: 140px;
}
.journal-cover-side .journal-cover-img,
.journal-cover-grid .journal-cover-img {
    width: 100%;
    height: 100%;
    min-height: 140px;
    object-fit: cover;
    display: block;
}
.journal-cover-placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 180px;
    padding: 1rem;
    background:
        radial-gradient(circle at top right, rgba(255,255,255,.16), transparent 25%),
        linear-gradient(135deg, var(--journal-type-color), #0f172a);
    color: #fff;
}
.journal-cover-side.journal-cover-placeholder,
.journal-cover-grid.journal-cover-placeholder {
    min-height: 140px;
}
.journal-cover-type {
    position: absolute;
    top: 12px;
    left: 12px;
    padding: .3rem .7rem;
    border-radius: 999px;
    background: rgba(255,255,255,.16);
    border: 1px solid rgba(255,255,255,.24);
    font-size: .72rem;
    font-weight: 700;
    letter-spacing: .03em;
}
.journal-cover-initial {
    font-size: clamp(3rem, 8vw, 5rem);
    font-weight: 900;
    line-height: 1;
    opacity: .92;
}
.journal-sidebar-card {
    display: grid;
    grid-template-columns: 118px minmax(0, 1fr);
    gap: 1rem;
}
.journal-sidebar-card .journal-cover-side {
    border-radius: 12px;
}
.journal-card-title {
    color: #142b4f;
    line-height: 1.3;
}
.journal-card-excerpt {
    color: #6b7a99;
}
.journal-card-actions {
    display: flex;
    flex-wrap: wrap;
    gap: .75rem;
    align-items: center;
    margin-top: 1rem;
}
.journal-card-link {
    color: #004D2A;
    font-weight: 700;
    text-decoration: none;
}
.journal-card-link:hover {
    color: #10274a;
}
.journal-feature-card {
    box-shadow: var(--emsp-panel-shadow) !important;
}
.journal-section-title {
    color: #163154;
    font-weight: 900;
    letter-spacing: .04em;
}
@media (max-width: 767.98px) {
    .journal-sidebar-card {
        grid-template-columns: 1fr;
    }
    .journal-sidebar-card .journal-cover-side {
        min-height: 180px;
    }
}
.btn-read-modal { cursor:pointer; }
#jm-content { overflow-y: auto; max-height: 70vh; }
.journal-rich-content h1,
.journal-rich-content h2,
.journal-rich-content h3 { margin-top: 1rem; }
.journal-rich-content img {
    max-width: 100%; height: auto;
    border-radius: 8px; margin: 0.5rem 0; }
.journal-rich-content blockquote {
    border-left: 4px solid #006B3C;
    padding-left: 1rem; color: #64748b; }
.journal-rich-content ul,
.journal-rich-content ol { padding-left: 1.5rem; }
.journal-rich-content p { margin-bottom: 0.75rem; }
.journal-rich-content a { color: #006B3C; }
</style>

<section class="page-header journal-shell-header">
    <div class="container">
        <h1>Journal EMSP</h1>
        <p class="mb-0">Actualités, annonces, défis et ressources récentes pour la communauté EMSP.</p>
    </div>
</section>

<section class="section-pad journal-shell">
    <div class="container">
        <div class="alert alert-info d-none" id="journal-new-badge">
            <strong>Nouvelle publication du journal.</strong>
            <span class="ms-1">Ouvrez la lecture rapide ou l article complet pour la voir.</span>
        </div>
        <?php if ($featured): ?>
        <div class="row g-3 mb-4">
            <div class="col-lg-8">
                <?php $featuredMeta = emsp_newsblog_journal_type_meta((string) ($featured['type'] ?? '')); ?>
                <article class="card shadow-sm border-0 h-100 journal-card journal-feature-card <?= htmlspecialchars($featuredMeta['class']) ?>">
                    <?= emsp_newsblog_journal_cover_html($featured, 'feature') ?>
                    <div class="card-body">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                            <span class="journal-type-badge journal-type-<?= h(emsp_newsblog_type_key((string) ($featured['type'] ?? ''))) ?>">
                                <?= htmlspecialchars($featuredMeta['label']) ?>
                            </span>
                            <span class="small text-muted"><?= htmlspecialchars(emsp_relative_date($featured['created_at'])) ?></span>
                        </div>
                        <h2 class="h4 fw-bold journal-card-title"><?= htmlspecialchars($featured['title']) ?></h2>
                        <p class="mb-0 journal-card-excerpt"><?= htmlspecialchars(emsp_newsblog_excerpt((string) $featured['content'], 260)) ?></p>
                        <div class="journal-card-actions">
                            <button type="button"
                                    class="btn btn-emsp btn-sm btn-read-modal"
                                    data-title="<?= htmlspecialchars($featured['title']) ?>"
                                    data-date="<?= htmlspecialchars(emsp_relative_date($featured['created_at'])) ?>"
                                    data-content-html="<?= base64_encode($featured['content']) ?>"
                                    data-journal-id="<?= intval($featured['id']) ?>">
                                Lire rapidement
                            </button>
                            <a class="journal-card-link" href="news-article.php?id=<?= intval($featured['id']) ?>">
                                Ouvrir l'article <i class="bi bi-arrow-right-short"></i>
                            </a>
                        </div>
                    </div>
                </article>
            </div>
            <div class="col-lg-4">
                <div class="d-flex flex-column gap-3">
                    <?php foreach ($sidebar as $i => $item): ?>
                    <?php $sideMeta = emsp_newsblog_journal_type_meta((string) ($item['type'] ?? '')); ?>
                    <article class="card shadow-sm border-0 journal-card <?= htmlspecialchars($sideMeta['class']) ?>">
                        <div class="card-body">
                            <div class="journal-sidebar-card">
                                <?= emsp_newsblog_journal_cover_html($item, 'side') ?>
                                <div>
                                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                        <span class="journal-type-badge journal-type-<?= h(emsp_newsblog_type_key((string) ($item['type'] ?? ''))) ?>">
                                            <?= htmlspecialchars($sideMeta['label']) ?>
                                        </span>
                                        <span class="small text-muted"><?= htmlspecialchars(emsp_relative_date($item['created_at'])) ?></span>
                                    </div>
                                    <h3 class="h6 fw-bold mb-2 journal-card-title"><?= htmlspecialchars($item['title']) ?></h3>
                                    <p class="small journal-card-excerpt mb-2"><?= htmlspecialchars(emsp_newsblog_excerpt((string) $item['content'], 105)) ?></p>
                                    <div class="journal-card-actions mt-0">
                                        <button type="button"
                                                class="btn btn-link p-0 small text-decoration-none btn-read-modal"
                                                data-title="<?= htmlspecialchars($item['title']) ?>"
                                                data-date="<?= htmlspecialchars(emsp_relative_date($item['created_at'])) ?>"
                                                data-content-html="<?= base64_encode($item['content']) ?>"
                                                data-journal-id="<?= intval($item['id']) ?>">
                                            Lire
                                        </button>
                                        <a class="journal-card-link small" href="news-article.php?id=<?= intval($item['id']) ?>">Article</a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="journal-panel mb-4">
            <h3>Aucun article publié</h3>
            <p class="mb-0 text-muted">Publie un article dans <code>admin/journal.php</code>.</p>
        </div>
        <?php endif; ?>

        <div class="journal-actualites-wrap mb-5">
            <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
                <h2 class="journal-section-title h4 mb-0">Actualites recentes</h2>
                <span class="small text-muted">Defilement editorial immersif</span>
            </div>
            <div class="row g-3 journal-actualites-carousel"
                 data-emsp-carousel="1"
                 data-emsp-carousel-style="immersive"
                 data-emsp-carousel-title="Actualites du journal EMSP"
                 data-emsp-per-view-desktop="2"
                 data-emsp-per-view-tablet="2"
                 data-emsp-per-view-mobile="1">
            <?php if (empty($recentJournal)): ?>
            <div class="col-12">
                <div class="journal-panel">
                    <h3>Pas d'autres actualités</h3>
                    <p class="mb-0 text-muted">Ajoute des contenus supplémentaires dans le journal.</p>
                </div>
            </div>
            <?php else: ?>
            <?php foreach ($recentJournal as $item): ?>
            <?php $recentMeta = emsp_newsblog_journal_type_meta((string) ($item['type'] ?? '')); ?>
            <div class="col-md-6" id="news-<?= intval($item['id']) ?>">
                <article class="card shadow-sm border-0 h-100 journal-card <?= htmlspecialchars($recentMeta['class']) ?>">
                    <?= emsp_newsblog_journal_cover_html($item, 'grid') ?>
                    <div class="card-body">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                            <span class="journal-type-badge journal-type-<?= h(emsp_newsblog_type_key((string) ($item['type'] ?? ''))) ?>">
                                <?= htmlspecialchars($recentMeta['label']) ?>
                            </span>
                            <span class="small text-muted"><?= htmlspecialchars(emsp_relative_date($item['created_at'])) ?></span>
                        </div>
                        <h3 class="h5 fw-bold journal-card-title"><?= htmlspecialchars($item['title']) ?></h3>
                        <p class="mb-0 journal-card-excerpt"><?= htmlspecialchars(emsp_newsblog_excerpt((string) $item['content'], 240)) ?></p>
                        <div class="journal-card-actions">
                            <button type="button"
                                    class="btn btn-sm btn-emsp-outline btn-read-modal"
                                    data-title="<?= htmlspecialchars($item['title']) ?>"
                                    data-date="<?= htmlspecialchars(emsp_relative_date($item['created_at'])) ?>"
                                    data-content-html="<?= base64_encode($item['content']) ?>"
                                    data-journal-id="<?= intval($item['id']) ?>">
                                Lire rapidement
                            </button>
                            <a class="journal-card-link" href="news-article.php?id=<?= intval($item['id']) ?>">Ouvrir l'article</a>
                        </div>
                    </div>
                </article>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<!-- Modal lecture journal -->
<div class="modal fade" id="journalModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
            <div class="small text-muted" id="jm-date"></div>
            <h5 class="modal-title" id="jm-title">Lecture rapide</h5>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>
<div class="modal-body">
        <div class="journal-quick-state" id="jm-state"></div>
        <div id="jm-content" class="journal-rich-content mb-4"></div>
        <div id="jm-interaction"></div>
        <div id="jm-social" class="mt-4"></div>
      </div>
      <div class="modal-footer justify-content-between">
        <span class="small text-muted" id="jm-footer-note"></span>
        <a class="btn btn-emsp" id="jm-open-link" href="news-blog.php">Voir l article complet</a>
      </div>
    </div>
  </div>
</div>
<input type="hidden" id="journal-modal-csrf" value="<?= htmlspecialchars(generate_csrf_token(), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">

<?php
// Maintenance: the quick-read modal script is injected through the shared footer pipeline
// so it keeps the same JS stack as the rest of the public shell.
$isAuthenticatedJson = !empty($_SESSION['auth_user']['id']) ? 'true' : 'false';
$latestJournalIdJson = json_encode($latestJournalId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$page_scripts = <<<HTML
<script>
(function () {
    var modalElement = document.getElementById('journalModal');
    if (!modalElement || typeof bootstrap === 'undefined') {
        return;
    }

    var isAuthenticated = {$isAuthenticatedJson};
    var modal = new bootstrap.Modal(modalElement);
    var csrfInput = document.getElementById('journal-modal-csrf');
    var csrfToken = csrfInput ? csrfInput.value : '';
    var titleEl = document.getElementById('jm-title');
    var dateEl = document.getElementById('jm-date');
    var stateEl = document.getElementById('jm-state');
    var contentEl = document.getElementById('jm-content');
    var interactionEl = document.getElementById('jm-interaction');
    var socialEl = document.getElementById('jm-social');
    var footerNoteEl = document.getElementById('jm-footer-note');
    var openLinkEl = document.getElementById('jm-open-link');
    var currentSummary = null;
    var latestJournalId = {$latestJournalIdJson};
    var newBadge = document.getElementById('journal-new-badge');
    var lastSeenKey = 'emsp-journal-last-seen';

    function markLastSeen(id) {
        if (!id || !window.localStorage) {
            return;
        }
        try {
            window.localStorage.setItem(lastSeenKey, String(id));
        } catch (e) {}
    }

    function refreshBadge() {
        if (!newBadge || !latestJournalId || !window.localStorage) {
            return;
        }
        var lastSeen = 0;
        try {
            lastSeen = parseInt(window.localStorage.getItem(lastSeenKey) || '0', 10);
        } catch (e) {
            lastSeen = 0;
        }
        if (latestJournalId > lastSeen) {
            newBadge.classList.remove('d-none');
        } else {
            newBadge.classList.add('d-none');
        }
    }

    function esc(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function renderState(summary) {
        var html = '';
        if (summary.type_meta) {
            var typeClass = String(summary.type || 'annonce').toLowerCase();
            if (['annonce', 'defi', 'sondage'].indexOf(typeClass) === -1) {
                typeClass = 'annonce';
            }
            html += '<span class="journal-type-badge journal-type-' + typeClass + '">' + esc(summary.type_meta.label || 'Article') + '</span>';
        }
        if (summary.state) {
            html += '<span class="journal-state-badge">' + esc(summary.state.label || 'Etat') + '</span>';
            if (summary.state.starts_at_label) {
                html += '<span class="small text-muted">Debut : ' + esc(summary.state.starts_at_label) + '</span>';
            }
            if (summary.state.ends_at_label) {
                html += '<span class="small text-muted">Fin : ' + esc(summary.state.ends_at_label) + '</span>';
            }
            if (summary.state.closed_at_label) {
                html += '<span class="small text-muted">Clos : ' + esc(summary.state.closed_at_label) + '</span>';
            }
        }
        stateEl.innerHTML = html;
    }

    function renderPoll(summary) {
        var poll = summary.poll || { options: [], total_votes: 0, my_option: 0 };
        if (!poll.options.length) {
            interactionEl.innerHTML = '<div class="alert alert-warning mb-0">Aucune option configuree pour ce sondage.</div>';
            return;
        }
        var total = parseInt(poll.total_votes || 0, 10);
        var controls = poll.options.map(function (option) {
            var votes = parseInt(option.votes || 0, 10);
            var percent = total > 0 ? Math.round((votes * 100) / total) : 0;
            var isMine = parseInt(poll.my_option || 0, 10) === parseInt(option.id || 0, 10);
            var disabled = (!summary.state || !summary.state.is_open || !isAuthenticated) ? 'disabled' : '';
            return '<div class="journal-poll-option' + (isMine ? ' active' : '') + ' mb-3" data-poll-card data-option-id="' + esc(option.id) + '">'
                + '<div class="d-flex justify-content-between align-items-center gap-3 mb-2">'
                + '<button type="button" class="btn btn-sm ' + (isMine ? 'btn-emsp' : 'btn-emsp-outline') + '" data-modal-poll-option data-option-id="' + esc(option.id) + '" ' + disabled + '>' + esc(option.label) + '</button>'
                + '<strong>' + votes + ' vote(s)</strong>'
                + '</div>'
                + '<div class="journal-poll-progress"><span class="journal-poll-progress-fill" data-emsp-width="' + percent + '"></span></div>'
                + '<div class="small text-muted mt-2">' + percent + '% des votes</div>'
                + '</div>';
        }).join('');
        var pollNote = summary.state && !summary.state.is_open
            ? 'Resultat final du sondage.'
            : 'Les resultats restent visibles publiquement.';
        interactionEl.innerHTML = '<div class="d-flex justify-content-between align-items-center mb-3"><strong>' + total + ' vote(s)</strong><span class="small text-muted">' + pollNote + '</span></div>' + controls;
    }

    function renderDefi(summary) {
        var defi = summary.defi || { participant_total: 0, participated: false, note: '' };
        var disabled = (!summary.state || !summary.state.is_open || !isAuthenticated) ? 'disabled' : '';
        var loginNotice = isAuthenticated ? '' : '<div class="alert alert-info">Connectez-vous pour participer a ce defi.</div>';
        interactionEl.innerHTML = '<div class="d-flex justify-content-between align-items-center mb-3"><strong>' + parseInt(defi.participant_total || 0, 10) + ' participation(s)</strong><span class="small text-muted">Les notes restent visibles seulement cote admin.</span></div>'
            + loginNotice
            + (isAuthenticated ? '<div class="mb-3"><label class="form-label fw-semibold" for="jm-defi-note">Votre note</label><textarea class="form-control" id="jm-defi-note" rows="5" ' + disabled + '>' + esc(defi.note || '') + '</textarea></div><div class="d-flex flex-wrap gap-3 align-items-center"><button type="button" class="btn btn-emsp" id="jm-defi-submit" ' + disabled + '>' + (defi.participated ? 'Mettre a jour ma participation' : 'Je participe') + '</button><span class="small text-muted" id="jm-defi-feedback">' + (defi.participated ? 'Votre participation est deja enregistree.' : 'Cliquez pour participer.') + '</span></div>' : '');
    }

    function renderAnnouncement() {
        interactionEl.innerHTML = '';
    }

    function renderSocial(summary) {
        if (!socialEl) {
            return;
        }
        var likeCount = parseInt(summary.like_count || 0, 10);
        var commentCount = parseInt(summary.comment_count || 0, 10);
        var liked = !!summary.liked;
        var likeLabel = liked ? 'Aime' : 'J aime';
        var likeClass = liked ? 'btn-emsp' : 'btn-emsp-outline';
        var disabled = isAuthenticated ? '' : 'disabled';
        var comments = Array.isArray(summary.comments) ? summary.comments : [];
        var commentsHtml = comments.length
            ? comments.map(function (comment) {
                var avatar = comment.photo_src
                    ? '<img src="' + esc(comment.photo_src) + '" alt="" class="rounded-circle journal-comment-avatar-img">'
                    : '<span class="rounded-circle d-inline-flex align-items-center justify-content-center fw-bold journal-comment-avatar-fallback">' + esc(comment.initials || 'EM') + '</span>';
                return ''
                    + '<div class="d-flex gap-3 py-3 border-bottom">'
                    + '<div>' + avatar + '</div>'
                    + '<div class="flex-grow-1">'
                    + '<div class="small text-muted mb-1"><strong>' + esc(comment.display_name || 'Utilisateur') + '</strong> · ' + esc(comment.relative_date || '') + '</div>'
                    + '<div>' + esc(comment.content || '').replace(/\\n/g, '<br>') + '</div>'
                    + '</div>'
                    + '</div>';
            }).join('')
            : '<div class="small text-muted">Aucun commentaire pour le moment.</div>';
        socialEl.innerHTML = ''
            + '<div class="d-flex flex-wrap align-items-center gap-3">'
            + '<button type="button" class="btn btn-sm ' + likeClass + '" id="jm-like-btn" ' + disabled + '>'
            + '<i class="bi ' + (liked ? 'bi-heart-fill' : 'bi-heart') + ' me-1"></i>' + likeLabel
            + '</button>'
            + '<span class="small text-muted" id="jm-like-count">' + likeCount + ' like(s)</span>'
            + '<span class="small text-muted">' + commentCount + ' commentaire(s)</span>'
            + (isAuthenticated ? '' : '<span class="small text-muted">Connectez-vous pour aimer ou commenter.</span>')
            + '</div>';
        socialEl.innerHTML += '<div class="mt-3" id="jm-comments">' + commentsHtml + '</div>';
        if (isAuthenticated) {
            socialEl.innerHTML += ''
                + '<div class="mt-3">'
                + '<label class="form-label fw-semibold" for="jm-comment-input">Ajouter un commentaire</label>'
                + '<textarea class="form-control" id="jm-comment-input" rows="3" placeholder="Votre commentaire..."></textarea>'
                + '<div class="d-flex flex-wrap align-items-center gap-3 mt-2">'
                + '<button type="button" class="btn btn-emsp btn-sm" id="jm-comment-submit"><i class="bi bi-send-fill me-1"></i>Publier</button>'
                + '<span class="small text-danger journal-comment-error" id="jm-comment-error"></span>'
                + '</div>'
                + '</div>';
        }
    }

    function renderSummary(summary) {
        currentSummary = summary;
        titleEl.textContent = summary.title || 'Lecture rapide';
        dateEl.textContent = summary.relative_date || '';
        contentEl.innerHTML = summary.content_html || '';
        openLinkEl.href = 'news-article.php?id=' + encodeURIComponent(summary.id || '');
        footerNoteEl.textContent = summary.state && summary.state.is_open ? 'Contenu actuellement ouvert.' : 'Contenu non interactif pour le moment.';
        renderState(summary);
        if (summary.type === 'sondage') {
            renderPoll(summary);
        } else if (summary.type === 'defi') {
            renderDefi(summary);
        } else {
            renderAnnouncement();
        }
        renderSocial(summary);
        markLastSeen(summary.id);
        refreshBadge();
    }

    function loadSummary(id) {
        titleEl.textContent = 'Chargement...';
        dateEl.textContent = '';
        stateEl.innerHTML = '';
        contentEl.innerHTML = '<div class="text-muted">Chargement du contenu...</div>';
        interactionEl.innerHTML = '';
        footerNoteEl.textContent = '';
        openLinkEl.href = '#';
        modal.show();

        fetch('journal-action.php?action=summary&journal_id=' + encodeURIComponent(id), {
            credentials: 'include'
        })
            .then(function (response) { return response.json(); })
            .then(function (payload) {
                if (!payload || !payload.ok || !payload.summary) {
                    throw new Error('summary');
                }
                renderSummary(payload.summary);
            })
            .catch(function () {
                contentEl.innerHTML = '<div class="alert alert-danger mb-0">Impossible de charger cette lecture rapide pour le moment.</div>';
            });
    }

    document.querySelectorAll('.btn-read-modal').forEach(function (button) {
        button.addEventListener('click', function () {
            loadSummary(this.getAttribute('data-journal-id'));
        });
    });

    document.querySelectorAll('a[href^="news-article.php?id="]').forEach(function (link) {
        link.addEventListener('click', function () {
            var url = new URL(this.getAttribute('href'), window.location.href);
            var id = parseInt(url.searchParams.get('id') || '0', 10);
            if (id > 0) {
                markLastSeen(id);
                refreshBadge();
            }
        });
    });

    refreshBadge();

    interactionEl.addEventListener('click', function (event) {
        var pollButton = event.target.closest('[data-modal-poll-option]');
        if (pollButton && currentSummary) {
            var formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('action', 'vote');
            formData.append('journal_id', currentSummary.id);
            formData.append('option_id', pollButton.getAttribute('data-option-id'));
            fetch('journal-action.php', {
                method: 'POST',
                body: formData,
                credentials: 'include'
            })
                .then(function (response) { return response.json(); })
                .then(function (payload) {
                    if (!payload || !payload.ok || !payload.summary) {
                        throw payload || new Error('vote');
                    }
                    renderSummary(payload.summary);
                })
                .catch(function (payload) {
                    if (payload && payload.summary) {
                        renderSummary(payload.summary);
                    }
                    var msg = payload && payload.message ? payload.message : 'Impossible d enregistrer votre vote pour le moment.';
                    if (window.emspUI && typeof window.emspUI.showError === 'function') {
                        window.emspUI.showError('Vote indisponible', msg);
                    } else {
                        console.error(msg);
                    }
                });
            return;
        }

        var defiButton = event.target.closest('#jm-defi-submit');
        if (defiButton && currentSummary) {
            var note = document.getElementById('jm-defi-note');
            var formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('action', 'defi');
            formData.append('journal_id', currentSummary.id);
            formData.append('note', note ? note.value : '');
            fetch('journal-action.php', {
                method: 'POST',
                body: formData,
                credentials: 'include'
            })
                .then(function (response) { return response.json(); })
                .then(function (payload) {
                    if (!payload || !payload.ok || !payload.summary) {
                        throw payload || new Error('defi');
                    }
                    renderSummary(payload.summary);
                })
                .catch(function (payload) {
                    if (payload && payload.summary) {
                        renderSummary(payload.summary);
                    }
                    var feedback = document.getElementById('jm-defi-feedback');
                    if (feedback) {
                        feedback.textContent = payload && payload.message
                            ? payload.message
                            : 'Impossible d enregistrer la participation pour le moment.';
                    }
                });
        }
    });

    if (socialEl) {
        socialEl.addEventListener('click', function (event) {
            var likeButton = event.target.closest('#jm-like-btn');
            if (!likeButton || !currentSummary) {
                return;
            }
            if (!csrfToken || !currentSummary.id) {
                return;
            }
            var fd = new FormData();
            fd.append('csrf_token', csrfToken);
            fd.append('action', 'like');
            fd.append('journal_id', currentSummary.id);
            fetch('journal-action.php', {
                method: 'POST',
                body: fd,
                credentials: 'include'
            })
                .then(function (response) { return response.json(); })
                .then(function (payload) {
                    if (!payload || !payload.ok) {
                        throw payload || new Error('like');
                    }
                    if (payload.summary) {
                        renderSummary(payload.summary);
                        return;
                    }
                    var liked = !!payload.liked;
                    likeButton.classList.toggle('btn-emsp', liked);
                    likeButton.classList.toggle('btn-emsp-outline', !liked);
                    likeButton.innerHTML = '<i class="bi ' + (liked ? 'bi-heart-fill' : 'bi-heart') + ' me-1"></i>' + (liked ? 'Aime' : 'J aime');
                    var count = document.getElementById('jm-like-count');
                    if (count) { count.textContent = (payload.like_count || 0) + ' like(s)'; }
                })
                .catch(function (payload) {
                    var msg = payload && payload.message ? payload.message : 'Impossible d enregistrer le like.';
                    if (window.emspUI && typeof window.emspUI.showError === 'function') {
                        window.emspUI.showError('Like indisponible', msg);
                    } else {
                        console.error(msg);
                    }
                });
        });

        socialEl.addEventListener('click', function (event) {
            var commentButton = event.target.closest('#jm-comment-submit');
            if (!commentButton || !currentSummary) {
                return;
            }
            var commentInput = document.getElementById('jm-comment-input');
            var commentError = document.getElementById('jm-comment-error');
            var content = commentInput ? String(commentInput.value || '').trim() : '';
            if (commentError) {
                commentError.textContent = '';
                commentError.style.display = 'none';
            }
            if (content === '') {
                if (commentError) {
                    commentError.textContent = 'Le commentaire est vide.';
                    commentError.style.display = 'inline';
                }
                return;
            }
            var commentFd = new FormData();
            commentFd.append('csrf_token', csrfToken);
            commentFd.append('action', 'comment');
            commentFd.append('journal_id', currentSummary.id);
            commentFd.append('content', content);
            fetch('journal-action.php', {
                method: 'POST',
                body: commentFd,
                credentials: 'include'
            })
                .then(function (response) { return response.json(); })
                .then(function (payload) {
                    if (!payload || !payload.ok || !payload.summary) {
                        throw payload || new Error('comment');
                    }
                    if (commentInput) {
                        commentInput.value = '';
                    }
                    renderSummary(payload.summary);
                })
                .catch(function (payload) {
                    if (commentError) {
                        commentError.textContent = payload && payload.message ? payload.message : 'Impossible d enregistrer le commentaire.';
                        commentError.style.display = 'inline';
                    }
                });
        });
    }
})();
</script>
HTML;
include __DIR__ . '/includes/footer.php';
?>






