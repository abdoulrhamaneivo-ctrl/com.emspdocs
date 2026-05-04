<?php
include_once __DIR__ . '/includes/bootstrap.php';
include_once __DIR__ . '/admin/config/dbcon.php';
include_once __DIR__ . '/includes/content-helpers.php';

$page_title = 'Accueil';

$authUser = $_SESSION['auth_user'] ?? [];
$isAuth = !empty($_SESSION['auth']) || !empty($authUser['id']);
$firstName = emsp_fix_mojibake((string) ($authUser['first_name'] ?? ''));
$uid = (int) ($authUser['id'] ?? 0);

$totalApprovedDocs = 0;
$activeFiliereCount = 0;
$activeLicenceCount = 0;
$downloadsCount = 0;
$favoritesCount = 0;
$notificationsCount = 0;
$journalHighlights = [];
$mediaHighlights = [];
$concoursHighlights = [];
$featuredDocs = [];
$guestCountFilter = $isAuth ? '' : " AND is_public=1";
$guestConcoursFilter = $isAuth ? '' : " AND d.is_public=1";

if (!function_exists('emsp_home_local_asset_exists')) {
    function emsp_home_local_asset_exists(string $src): bool
    {
        $src = trim($src);
        if ($src === '' || emsp_is_external_url($src) || str_starts_with($src, 'data:') || str_starts_with($src, 'blob:')) {
            return $src !== '';
        }

        $path = strtok($src, '?') ?: $src;
        $path = ltrim(str_replace('\\', '/', $path), '/');

        return is_file(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path));
    }
}

if (!function_exists('emsp_home_media_cover_src')) {
    function emsp_home_media_cover_src(string $path): string
    {
        $src = emsp_media_src($path);

        return emsp_home_local_asset_exists($src) ? $src : 'assets/images/video-placeholder.jpg';
    }
}

$countResult = mysqli_query($con, "SELECT COUNT(*) AS total FROM documents WHERE status='approved'" . $guestCountFilter);
if ($countResult) {
    $countRow = mysqli_fetch_assoc($countResult);
    $totalApprovedDocs = (int) ($countRow['total'] ?? 0);
}

$filiereCountResult = mysqli_query($con, "SELECT COUNT(*) AS total FROM filieres WHERE status='active'");
if ($filiereCountResult) {
    $filiereCountRow = mysqli_fetch_assoc($filiereCountResult);
    $activeFiliereCount = (int) ($filiereCountRow['total'] ?? 0);
}

$licenceCountResult = mysqli_query($con, "SELECT COUNT(*) AS total FROM licences WHERE status='active'");
if ($licenceCountResult) {
    $licenceCountRow = mysqli_fetch_assoc($licenceCountResult);
    $activeLicenceCount = (int) ($licenceCountRow['total'] ?? 0);
}

$journalStmt = mysqli_prepare(
    $con,
    "SELECT id, type, title, content, created_at
     FROM journal
     WHERE status='published'
     ORDER BY created_at DESC
     LIMIT 8"
);
if ($journalStmt) {
    mysqli_stmt_execute($journalStmt);
    $journalHighlights = emsp_stmt_fetch_all($journalStmt);
    mysqli_stmt_close($journalStmt);
}

foreach ($journalHighlights as &$journalItem) {
    foreach (['type', 'title', 'content'] as $field) {
        if (isset($journalItem[$field]) && is_string($journalItem[$field])) {
            $journalItem[$field] = emsp_fix_mojibake($journalItem[$field]);
        }
    }
}
unset($journalItem);

$mediaStmt = mysqli_prepare(
    $con,
    "SELECT
        COALESCE(TRIM(REPLACE(REPLACE(category, CHAR(160), ' '), '\t', ' ')),'') AS category,
        COUNT(*) AS media_count,
        MAX(created_at) AS last_date,
        (SELECT file_path
         FROM media m2
         WHERE COALESCE(TRIM(REPLACE(REPLACE(m2.category, CHAR(160), ' '), '\t', ' ')),'')
               = COALESCE(TRIM(REPLACE(REPLACE(m.category, CHAR(160), ' '), '\t', ' ')),'')
           AND m2.type = 'image'
           AND m2.is_public = 1
           AND m2.status = 'published'
         ORDER BY m2.created_at DESC
         LIMIT 1) AS cover_path
     FROM media m
     WHERE m.type='image'
       AND m.is_public=1
       AND m.status='published'
     GROUP BY COALESCE(TRIM(REPLACE(REPLACE(category, CHAR(160), ' '), '\t', ' ')),'')
     ORDER BY last_date DESC
     LIMIT 8"
);
if ($mediaStmt) {
    mysqli_stmt_execute($mediaStmt);
    $mediaHighlights = emsp_stmt_fetch_all($mediaStmt);
    mysqli_stmt_close($mediaStmt);
}

foreach ($mediaHighlights as &$mediaItem) {
    $categoryRaw = (string) ($mediaItem['category'] ?? '');
    $mediaItem['category_raw'] = $categoryRaw;
    $mediaItem['category'] = emsp_fix_mojibake($categoryRaw);
    $mediaItem['category_label'] = trim($mediaItem['category']) !== '' ? $mediaItem['category'] : 'Album EMSP';
    $coverPath = trim((string) ($mediaItem['cover_path'] ?? ''));
    $mediaItem['cover_src'] = $coverPath !== '' ? emsp_home_media_cover_src($coverPath) : 'assets/images/video-placeholder.jpg';
}
unset($mediaItem);

$concoursStmt = mysqli_prepare(
    $con,
    "SELECT d.id, d.title, d.description, d.created_at, d.download_count,
            u.first_name, u.last_name
     FROM documents d
     JOIN users u ON u.id = d.uploader_id
     WHERE d.status='approved'
       AND d.doc_type='concours'" . $guestConcoursFilter . "
     ORDER BY COALESCE(d.approved_at, d.created_at) DESC, d.created_at DESC
     LIMIT 6"
);
if ($concoursStmt) {
    mysqli_stmt_execute($concoursStmt);
    $concoursHighlights = emsp_stmt_fetch_all($concoursStmt);
    mysqli_stmt_close($concoursStmt);
}

foreach ($concoursHighlights as &$concoursItem) {
    foreach (['title', 'description', 'first_name', 'last_name'] as $field) {
        if (isset($concoursItem[$field]) && is_string($concoursItem[$field])) {
            $concoursItem[$field] = emsp_fix_mojibake($concoursItem[$field]);
        }
    }
}
unset($concoursItem);

$featuredDocsStmt = mysqli_prepare(
    $con,
    "SELECT d.id, d.title, d.doc_type, d.download_count,
            d.like_count, d.file_size_bytes, d.created_at,
            u.first_name, u.last_name,
            f.name AS filiere_name
     FROM documents d
     JOIN users u ON u.id = d.uploader_id
     LEFT JOIN filieres f ON f.id = d.filiere_id
     WHERE d.status='approved'" . $guestCountFilter . "
     ORDER BY d.download_count DESC
     LIMIT 4"
);
if ($featuredDocsStmt) {
    mysqli_stmt_execute($featuredDocsStmt);
    $featuredDocs = emsp_stmt_fetch_all($featuredDocsStmt);
    mysqli_stmt_close($featuredDocsStmt);
}

foreach ($featuredDocs as &$featuredDoc) {
    foreach (['title', 'doc_type', 'first_name', 'last_name', 'filiere_name'] as $field) {
        if (isset($featuredDoc[$field]) && is_string($featuredDoc[$field])) {
            $featuredDoc[$field] = emsp_fix_mojibake($featuredDoc[$field]);
        }
    }
}
unset($featuredDoc);

if ($isAuth && $uid > 0) {
    $downloadsStmt = mysqli_prepare($con, "SELECT COUNT(*) FROM history WHERE user_id=? AND action='download'");
    if ($downloadsStmt) {
        mysqli_stmt_bind_param($downloadsStmt, 'i', $uid);
        mysqli_stmt_execute($downloadsStmt);
        mysqli_stmt_bind_result($downloadsStmt, $downloadsCount);
        mysqli_stmt_fetch($downloadsStmt);
        mysqli_stmt_close($downloadsStmt);
    }

    $favoritesStmt = mysqli_prepare($con, "SELECT COUNT(*) FROM favorites WHERE user_id=?");
    if ($favoritesStmt) {
        mysqli_stmt_bind_param($favoritesStmt, 'i', $uid);
        mysqli_stmt_execute($favoritesStmt);
        mysqli_stmt_bind_result($favoritesStmt, $favoritesCount);
        mysqli_stmt_fetch($favoritesStmt);
        mysqli_stmt_close($favoritesStmt);
    }

    $notificationsStmt = mysqli_prepare($con, "SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
    if ($notificationsStmt) {
        mysqli_stmt_bind_param($notificationsStmt, 'i', $uid);
        mysqli_stmt_execute($notificationsStmt);
        mysqli_stmt_bind_result($notificationsStmt, $notificationsCount);
        mysqli_stmt_fetch($notificationsStmt);
        mysqli_stmt_close($notificationsStmt);
    }
}

$heroDocumentCount = number_format($totalApprovedDocs, 0, ',', ' ');
$heroFiliereCount = number_format($activeFiliereCount, 0, ',', ' ');
$heroLicenceCount = number_format($activeLicenceCount, 0, ',', ' ');

$documentTypeMeta = [
    'cours' => ['label' => 'Cours', 'badge' => 'bg-success'],
    'td' => ['label' => 'TD', 'badge' => 'bg-warning text-dark'],
    'correction' => ['label' => 'Correction', 'badge' => 'bg-primary'],
    'concours' => ['label' => 'Concours', 'badge' => 'bg-dark'],
    'examen' => ['label' => 'Examen', 'badge' => 'bg-secondary'],
];

if (!function_exists('emsp_home_excerpt')) {
    function emsp_home_excerpt(string $value, int $max = 160): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        if ($value === '') {
            return 'Document academique partage par la communaute EMSP.';
        }
        if (mb_strlen($value) <= $max) {
            return $value;
        }
        return rtrim(mb_substr($value, 0, $max - 1)) . '...';
    }
}

if (!function_exists('emsp_home_journal_cover_src')) {
    function emsp_home_journal_cover_src(string $html): string
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
}

$featuredJournalData = null;
$journalCardItems = array_slice($journalHighlights, 0, 6);
$journalVisitorItems = array_slice($journalHighlights, 0, 6);

if (!empty($journalHighlights)) {
    $featured = $journalHighlights[0];
    $featuredType = strtolower(trim((string) ($featured['type'] ?? 'annonce')));
    if (!in_array($featuredType, ['annonce', 'defi', 'sondage'], true)) {
        $featuredType = 'annonce';
    }

    $featuredCover = emsp_home_journal_cover_src((string) ($featured['content'] ?? ''));
    if ($featuredCover === '') {
        $featuredCover = 'assets/images/emsp-ivoire-tech-forum-2025.jpg';
    }

    $featuredJournalData = [
        'id' => (int) ($featured['id'] ?? 0),
        'title' => trim((string) ($featured['title'] ?? 'Actualite EMSP')),
        'excerpt' => emsp_home_excerpt(strip_tags((string) ($featured['content'] ?? '')), 220),
        'type' => $featuredType,
        'type_label' => ucfirst($featuredType),
        'cover' => $featuredCover,
        'date' => (string) ($featured['created_at'] ?? 'now'),
    ];

    if (count($journalHighlights) > 1) {
        $journalCardItems = array_slice($journalHighlights, 1, 6);
    }
}

include __DIR__ . '/includes/header.php';
?>

<section class="home-hero">
    <div class="home-hero-shell">
        <div class="container">
            <div class="row align-items-center g-4">
                <div class="col-lg-8">
                    <span class="home-eyebrow">Bienvenue sur la plateforme de l'EMSP</span>
                    <h1 class="home-hero-title--wide">Une bibliotheque academique claire, serieuse et orientee reussite.</h1>
                    <p class="lead">Retrouve les cours, TD, corrections, examens et concours partages par la communaute EMSP avec une experience moderne et institutionnelle.</p>
                    <div class="home-hero-actions">
                        <?php if ($isAuth): ?>
                            <a href="dashboard.php" class="home-btn-primary">Acceder a mon espace</a>
                            <a href="bibliotheque.php" class="home-btn-secondary">Explorer la bibliotheque</a>
                        <?php else: ?>
                            <a href="register.php" class="home-btn-primary">Creer un compte</a>
                            <a href="login.php" class="home-btn-secondary emsp-open-login-modal" data-bs-toggle="modal" data-bs-target="#emspQuickLoginModal" data-emsp-modal-link="1">Se connecter</a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="home-summary-panel">
                        <h3>Indicateurs de la plateforme</h3>
                        <p class="home-summary-copy">Une base documentaire vivante pour accompagner les etudiants EMSP.</p>
                        <div class="home-summary-grid">
                            <div class="home-summary-stat">
                                <div>
                                    <strong><?= $heroDocumentCount ?></strong>
                                    <span>documents valides</span>
                                </div>
                                <i class="bi bi-journal-text"></i>
                            </div>
                            <div class="home-summary-stat">
                                <div>
                                    <strong><?= $heroFiliereCount ?></strong>
                                    <span>filieres actives</span>
                                </div>
                                <i class="bi bi-diagram-3"></i>
                            </div>
                            <div class="home-summary-stat">
                                <div>
                                    <strong><?= $heroLicenceCount ?></strong>
                                    <span>niveaux de licence</span>
                                </div>
                                <i class="bi bi-mortarboard"></i>
                            </div>
                        </div>
                        <?php if ($isAuth): ?>
                            <div class="home-summary-note"><i class="bi bi-bell"></i><?= (int) $notificationsCount ?> notifications non lues</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php if (!$isAuth): ?>
<section class="home-section home-guest-headline-section" data-emsp-parallax-bg="0.03">
    <div class="container">
        <header class="home-guest-headline-head">
            <h2>A la une</h2>
        </header>

        <?php if (empty($journalVisitorItems)): ?>
            <article class="card home-vitrine-card home-vitrine-card--empty">
                <div class="card-body py-5 text-center text-muted">
                    <i class="bi bi-newspaper fs-2 d-block mb-2"></i>
                    Aucune actualite n'est disponible pour le moment.
                </div>
            </article>
        <?php else: ?>
            <div class="home-guest-headline-grid">
                <?php foreach ($journalVisitorItems as $idx => $journalItem): ?>
                    <?php
                    $journalTitle = trim((string) ($journalItem['title'] ?? 'Actualite EMSP'));
                    $journalExcerpt = emsp_home_excerpt(strip_tags((string) ($journalItem['content'] ?? '')), 170);
                    $journalCover = emsp_home_journal_cover_src((string) ($journalItem['content'] ?? ''));
                    if ($journalCover === '') {
                        $journalCover = $asset . 'images/emsp-ivoire-tech-forum-2025.jpg';
                    }
                    $headlineCardClass = $idx === 1 ? ' is-featured' : '';
                    ?>
                    <article class="home-guest-headline-card<?= $headlineCardClass ?>">
                        <a class="home-guest-headline-media" href="news-article.php?id=<?= (int) ($journalItem['id'] ?? 0) ?>" aria-label="Lire l actualite <?= htmlspecialchars($journalTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
                            <img src="<?= htmlspecialchars($journalCover, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>" alt="<?= htmlspecialchars($journalTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>" loading="lazy" data-fallback-src="<?= $asset ?>images/emsp-ivoire-tech-forum-2025.jpg">
                        </a>
                        <div class="home-guest-headline-body">
                            <h3><?= htmlspecialchars($journalTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></h3>
                            <p><?= htmlspecialchars($journalExcerpt, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></p>
                            <a class="home-guest-headline-link" href="news-article.php?id=<?= (int) ($journalItem['id'] ?? 0) ?>">Lire la suite</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <div class="home-guest-headline-more">
                <a href="news-blog.php" class="btn btn-primary">Plus d'articles</a>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($isAuth && !empty($featuredJournalData)): ?>
<section class="home-section home-journal-feature" data-emsp-parallax-bg="0.1">
    <div class="container">
        <article class="home-journal-feature-shell">
            <a class="home-journal-feature-media" href="news-article.php?id=<?= (int) ($featuredJournalData['id'] ?? 0) ?>" aria-label="Lire l actualite a la une">
                <img src="<?= htmlspecialchars((string) ($featuredJournalData['cover'] ?? ($asset . 'images/emsp-ivoire-tech-forum-2025.jpg')), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>" alt="<?= htmlspecialchars((string) ($featuredJournalData['title'] ?? 'Actualite a la une'), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>" loading="lazy" data-fallback-src="<?= $asset ?>images/emsp-ivoire-tech-forum-2025.jpg">
            </a>
            <div class="home-journal-feature-panel">
                <span class="home-journal-feature-kicker">A la une du Journal</span>
                <h2><?= htmlspecialchars((string) ($featuredJournalData['title'] ?? 'Actualite EMSP'), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></h2>
                <p><?= htmlspecialchars((string) ($featuredJournalData['excerpt'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></p>
                <div class="home-journal-feature-meta">
                    <span><i class="bi bi-calendar3" aria-hidden="true"></i><?= date('d/m/Y', strtotime((string) ($featuredJournalData['date'] ?? 'now'))) ?></span>
                    <span><i class="bi bi-bookmark-star" aria-hidden="true"></i><?= htmlspecialchars((string) ($featuredJournalData['type_label'] ?? 'Annonce'), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></span>
                </div>
                <div class="home-journal-feature-actions">
                    <a href="news-article.php?id=<?= (int) ($featuredJournalData['id'] ?? 0) ?>" class="btn btn-primary">Lire l actualite</a>
                    <a href="news-blog.php" class="btn btn-outline-light">Voir le journal</a>
                </div>
            </div>
        </article>
    </div>
</section>
<?php endif; ?>

<?php if (!empty($featuredDocs)): ?>
<section class="section-pad emsp-featured-section">
    <div class="container">
        <div class="d-flex align-items-center justify-content-between mb-4">
            <div>
                <span class="section-chip">⭐ Documents du moment</span>
                <h2 class="h3 fw-bold mb-0" style="font-family:var(--font-serif,'Playfair Display',serif)">
                    Les plus téléchargés
                </h2>
            </div>
            <a href="bibliotheque.php"
               class="btn btn-sm fw-semibold"
               style="border:1.5px solid #006B3C;color:#006B3C;border-radius:8px;
                      padding:6px 18px;transition:all .2s ease;"
               onmouseover="this.style.background='#006B3C';this.style.color='#fff'"
               onmouseout="this.style.background='transparent';this.style.color='#006B3C'">
                Voir tout <i class="bi bi-arrow-right ms-1"></i>
            </a>
        </div>
        <div class="row g-3"
             data-emsp-carousel="1"
             data-emsp-carousel-style="immersive"
             data-emsp-carousel-title="Documents du moment"
             data-emsp-carousel-interval="4800"
             data-emsp-carousel-min-items="2"
             data-emsp-per-view-desktop="4"
             data-emsp-per-view-tablet="2"
             data-emsp-per-view-mobile="1">
            <?php foreach ($featuredDocs as $fd): ?>
            <div class="col-12 col-sm-6 col-lg-3">
                <a href="document.php?id=<?= (int)$fd['id'] ?>"
                   class="card h-100 text-decoration-none emsp-featured-card"
                   style="border-radius:12px;border:1px solid #E0E0E0;overflow:hidden;
                          transition:transform .2s,box-shadow .2s;color:inherit;">
                    <div class="card-body d-flex flex-column p-3">
                        <span class="badge mb-2 text-uppercase"
                              style="width:fit-content;font-size:10px;letter-spacing:.5px;
                                     background:<?= match($fd['doc_type'] ?? '') {
                                         'concours'   => '#C0392B',
                                         'examen'     => '#8B0000',
                                         'cours'      => '#006B3C',
                                         'td'         => '#004D2A',
                                         'correction' => '#D4900A',
                                         default      => '#006B3C'
                                     } ?>;
                                     color:#fff">
                            <?= htmlspecialchars((string) $fd['doc_type'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                        <h3 class="h6 fw-bold mb-2" style="font-size:14px;line-height:1.4;
                                   display:-webkit-box;-webkit-line-clamp:2;
                                   -webkit-box-orient:vertical;overflow:hidden;
                                   color:#1A1A1A;">
                            <?= htmlspecialchars((string) $fd['title'], ENT_QUOTES, 'UTF-8') ?>
                        </h3>
                        <?php if (!empty($fd['filiere_name'])): ?>
                        <span class="text-muted" style="font-size:12px">
                            <?= htmlspecialchars((string) $fd['filiere_name'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                        <?php endif; ?>
                        <div class="mt-auto pt-2 d-flex align-items-center gap-2"
                             style="font-size:12px;color:#6B6B6B">
                            <i class="bi bi-download"></i>
                            <?= number_format((int) $fd['download_count'], 0, ',', ' ') ?>
                            <i class="bi bi-heart ms-2"></i>
                            <?= (int) $fd['like_count'] ?>
                        </div>
                    </div>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<style>
.emsp-featured-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(0,0,0,.12) !important;
}
</style>
<?php endif; ?>

<?php if ($isAuth): ?>
<section class="home-section home-vitrine-section home-vitrine-journal" data-emsp-parallax-bg="0.08">
    <div class="container">
        <div class="home-section-head">
            <div>
                <h2>Actualites du Journal</h2>
                <p>Annonces, defis et infos institutionnelles mises en avant comme une veritable vitrine editoriale.</p>
            </div>
            <a href="news-blog.php" class="btn btn-outline-primary">Voir le Journal</a>
        </div>

        <div class="row g-4" data-emsp-carousel="1" data-emsp-carousel-style="immersive" data-emsp-carousel-title="Actualites Journal EMSP" data-emsp-per-view-desktop="3" data-emsp-per-view-tablet="2" data-emsp-per-view-mobile="1" data-emsp-carousel-interval="5600">
            <?php if (empty($journalCardItems)): ?>
                <div class="col-12">
                    <article class="card home-vitrine-card home-vitrine-card--empty">
                        <div class="card-body py-5 text-center text-muted">
                            <i class="bi bi-newspaper fs-2 d-block mb-2"></i>
                            Aucune actualite n'est disponible pour le moment.
                        </div>
                    </article>
                </div>
            <?php else: ?>
                <?php foreach ($journalCardItems as $journalItem): ?>
                    <?php
                    $journalType = strtolower(trim((string) ($journalItem['type'] ?? 'annonce')));
                    if (!in_array($journalType, ['annonce', 'defi', 'sondage'], true)) {
                        $journalType = 'annonce';
                    }
                    $journalTypeLabel = ucfirst($journalType);
                    $journalTitle = trim((string) ($journalItem['title'] ?? 'Actualite EMSP'));
                    $journalExcerpt = emsp_home_excerpt(strip_tags((string) ($journalItem['content'] ?? '')), 150);
                    $journalCover = emsp_home_journal_cover_src((string) ($journalItem['content'] ?? ''));
                    if ($journalCover === '') {
                        $journalCover = $asset . 'images/emsp-ivoire-tech-forum-2025.jpg';
                    }
                    ?>
                    <div class="col-sm-6 col-xl-4">
                        <article class="card home-vitrine-card h-100">
                            <a class="home-vitrine-media-link" href="news-article.php?id=<?= (int) ($journalItem['id'] ?? 0) ?>" aria-label="Lire l actualite <?= htmlspecialchars($journalTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
                                <div class="home-vitrine-media">
                                    <img src="<?= htmlspecialchars($journalCover, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>" alt="<?= htmlspecialchars($journalTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>" loading="lazy">
                                </div>
                            </a>
                            <div class="card-body">
                                <span class="home-vitrine-kicker home-vitrine-kicker--<?= h($journalType) ?>"><?= h($journalTypeLabel) ?></span>
                                <h3><?= htmlspecialchars($journalTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></h3>
                                <p><?= htmlspecialchars($journalExcerpt, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></p>
                            </div>
                            <div class="card-footer bg-transparent d-flex justify-content-between align-items-center">
                                <small><?= date('d/m/Y', strtotime((string) ($journalItem['created_at'] ?? 'now'))) ?></small>
                                <a href="news-article.php?id=<?= (int) ($journalItem['id'] ?? 0) ?>" class="home-vitrine-link">
                                    Lire <i class="bi bi-arrow-right"></i>
                                </a>
                            </div>
                        </article>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="home-section home-vitrine-section home-vitrine-media" data-emsp-parallax-bg="0.06">
    <div class="container">
        <div class="home-section-head">
            <div>
                <h2>Mediatheque en lumiere</h2>
                <p>Albums et reportages visuels pour donner une experience immersive des activites EMSP.</p>
            </div>
            <a href="mediatheque.php" class="btn btn-outline-primary">Explorer la mediatheque</a>
        </div>

        <div class="row g-4" data-emsp-carousel="1" data-emsp-carousel-style="immersive" data-emsp-carousel-title="Mediatheque EMSP" data-emsp-per-view-desktop="3" data-emsp-per-view-tablet="2" data-emsp-per-view-mobile="1" data-emsp-carousel-interval="5200">
            <?php if (empty($mediaHighlights)): ?>
                <div class="col-12">
                    <article class="card home-vitrine-card home-vitrine-card--empty">
                        <div class="card-body py-5 text-center text-muted">
                            <i class="bi bi-images fs-2 d-block mb-2"></i>
                            Aucun album n'est disponible pour le moment.
                        </div>
                    </article>
                </div>
            <?php else: ?>
                <?php foreach (array_slice($mediaHighlights, 0, 6) as $mediaItem): ?>
                    <?php
                    $albumTitle = trim((string) ($mediaItem['category_label'] ?? 'Album EMSP'));
                    $albumHref = 'mediatheque.php?album=' . urlencode((string) ($mediaItem['category_raw'] ?? '')) . '#phototheque';
                    ?>
                    <div class="col-sm-6 col-xl-4">
                        <article class="card home-vitrine-card h-100">
                            <a class="home-vitrine-media-link" href="<?= htmlspecialchars($albumHref, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>" aria-label="Voir l album <?= htmlspecialchars($albumTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
                                <div class="home-vitrine-media">
                                    <img src="<?= htmlspecialchars((string) ($mediaItem['cover_src'] ?? ($asset . 'images/video-placeholder.jpg')), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>" alt="<?= htmlspecialchars($albumTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>" loading="lazy" data-fallback-src="<?= $asset ?>images/video-placeholder.jpg">
                                </div>
                            </a>
                            <div class="card-body">
                                <span class="home-vitrine-kicker home-vitrine-kicker--media">Mediatheque</span>
                                <h3><?= htmlspecialchars($albumTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></h3>
                                <p><?= (int) ($mediaItem['media_count'] ?? 0) ?> element(s) publie(s) dans cet album.</p>
                            </div>
                            <div class="card-footer bg-transparent d-flex justify-content-between align-items-center">
                                <small><?= date('d/m/Y', strtotime((string) ($mediaItem['last_date'] ?? 'now'))) ?></small>
                                <a href="<?= htmlspecialchars($albumHref, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>" class="home-vitrine-link">
                                    Voir <i class="bi bi-arrow-right"></i>
                                </a>
                            </div>
                        </article>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="home-section home-vitrine-section home-vitrine-concours" data-emsp-parallax-bg="0.04">
    <div class="container">
        <div class="home-section-head">
            <div>
                <h2>Concours a la une</h2>
                <p>Les concours restent visibles en vitrine pour orienter les etudiants vers les opportunites prioritaires.</p>
            </div>
            <a href="concours.php" class="btn btn-outline-primary">Voir les concours</a>
        </div>

        <div class="row g-4" data-emsp-carousel="1" data-emsp-carousel-title="Concours EMSP" data-emsp-per-view-desktop="3" data-emsp-per-view-tablet="2" data-emsp-per-view-mobile="1" data-emsp-carousel-interval="6000">
            <?php if (empty($concoursHighlights)): ?>
                <div class="col-12">
                    <article class="card home-vitrine-card home-vitrine-card--empty">
                        <div class="card-body py-5 text-center text-muted">
                            <i class="bi bi-trophy fs-2 d-block mb-2"></i>
                            Aucun concours public n'est disponible pour le moment.
                        </div>
                    </article>
                </div>
            <?php else: ?>
                <?php foreach ($concoursHighlights as $concoursItem): ?>
                    <?php
                    $concoursTitle = trim((string) ($concoursItem['title'] ?? 'Concours EMSP'));
                    $concoursExcerpt = emsp_home_excerpt((string) ($concoursItem['description'] ?? ''), 130);
                    $concoursAuthor = trim((string) ($concoursItem['first_name'] ?? '') . ' ' . (string) ($concoursItem['last_name'] ?? ''));
                    if ($concoursAuthor === '') {
                        $concoursAuthor = 'Equipe EMSP';
                    }
                    ?>
                    <div class="col-sm-6 col-xl-4">
                        <article class="card home-vitrine-card home-vitrine-card--concours h-100">
                            <div class="card-body">
                                <span class="home-vitrine-kicker home-vitrine-kicker--concours">Concours</span>
                                <h3><?= htmlspecialchars($concoursTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></h3>
                                <p><?= htmlspecialchars($concoursExcerpt, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></p>
                            </div>
                            <div class="card-footer bg-transparent d-flex justify-content-between align-items-center">
                                <small><?= date('d/m/Y', strtotime((string) ($concoursItem['created_at'] ?? 'now'))) ?> - <?= htmlspecialchars($concoursAuthor, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></small>
                                <a href="document.php?id=<?= (int) ($concoursItem['id'] ?? 0) ?>" class="home-vitrine-link">
                                    Ouvrir <i class="bi bi-arrow-right"></i>
                                </a>
                            </div>
                        </article>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php if (!$isAuth): ?>
<section class="home-section home-quick-section">
    <div class="container">
        <div class="home-section-head">
            <div>
                <h2>Comment ca marche</h2>
                <p>Un parcours simple pour rejoindre rapidement l'espace academique EMSP.</p>
            </div>
        </div>
        <div class="row g-3 home-quick-grid"
             data-emsp-carousel="1"
             data-emsp-carousel-style="steps"
             data-emsp-carousel-title="Parcours Comment ca marche"
             data-emsp-carousel-interval="3600"
             data-emsp-carousel-controls="0"
             data-emsp-carousel-dots="0"
             data-emsp-per-view-desktop="3"
             data-emsp-per-view-tablet="2"
             data-emsp-per-view-mobile="1">
            <div class="col-md-4">
                <div class="home-step-card">
                    <div class="home-step-number">1</div>
                    <h3>S'inscrire</h3>
                    <p>Cree ton compte etudiant en quelques minutes.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="home-step-card">
                    <div class="home-step-number">2</div>
                    <h3>Consulter</h3>
                    <p>Accede aux ressources triees par filiere, niveau et matiere.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="home-step-card">
                    <div class="home-step-number">3</div>
                    <h3>Contribuer</h3>
                    <p>Depose tes propres documents pour enrichir la bibliotheque.</p>
                </div>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
