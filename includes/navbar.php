<?php
include_once __DIR__ . '/bootstrap.php';
include_once __DIR__ . '/helpers.php';
include_once __DIR__ . '/notif-helper.php';

$isAuth = !empty($_SESSION['auth']) || !empty($_SESSION['auth_user']['id']);
$authRole = strtolower(trim((string) ($_SESSION['auth_role'] ?? ($_SESSION['auth_user']['role'] ?? ''))));
$authUser = $_SESSION['auth_user'] ?? [];
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
$currentPath = basename($requestPath);
if ($currentPath === '') {
    $currentPath = 'index.php';
}

$accountStatus = strtolower(trim((string) ($authUser['status'] ?? '')));
$isStaff = in_array($authRole, ['admin', 'moderateur'], true);
$isActiveAccount = $isAuth && ($accountStatus === '' || $accountStatus === 'active');
$canAccessAdmin = $isActiveAccount && $isStaff;
$canSearchDocuments = $isActiveAccount;
$notifCount = $isAuth ? emsp_session_get_notif_count() : 0;
$notifSections = $isAuth ? emsp_session_get_notif_sections() : ['journal' => 0, 'media' => 0];
$navLatestSections = ['journal' => 0, 'media' => 0];

if (isset($con) && $con instanceof mysqli) {
    $cache = $_SESSION['emsp_nav_markers'] ?? null;
    $cacheFresh = is_array($cache) && ((int) ($cache['fetched_at'] ?? 0) + 300) >= time();

    if ($cacheFresh) {
        $navLatestSections['journal'] = max(0, (int) ($cache['journal'] ?? 0));
        $navLatestSections['media'] = max(0, (int) ($cache['media'] ?? 0));
    } else {
        $metaStmt = mysqli_prepare(
            $con,
            "SELECT
                (SELECT COALESCE(MAX(id), 0) FROM journal WHERE status='published') AS latest_journal_id,
                (SELECT COALESCE(MAX(id), 0) FROM media WHERE is_public=1 AND status='published') AS latest_media_id"
        );
        if ($metaStmt) {
            mysqli_stmt_execute($metaStmt);
            $metaRow = emsp_stmt_fetch_assoc($metaStmt);
            mysqli_stmt_close($metaStmt);
            $navLatestSections['journal'] = max(0, (int) ($metaRow['latest_journal_id'] ?? 0));
            $navLatestSections['media'] = max(0, (int) ($metaRow['latest_media_id'] ?? 0));
            $_SESSION['emsp_nav_markers'] = [
                'journal' => $navLatestSections['journal'],
                'media' => $navLatestSections['media'],
                'fetched_at' => time(),
            ];
        }
    }

    if ($isAuth) {
        $authUserId = (int) ($authUser['id'] ?? 0);
        if ($authUserId > 0) {
            // Maintenance: refresh unread counters directly in the shared navbar so desktop/mobile
            // badges always reflect fresh publication activity without waiting for dashboard visits.
            $notifCountStmt = mysqli_prepare($con, "SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
            if ($notifCountStmt) {
                mysqli_stmt_bind_param($notifCountStmt, 'i', $authUserId);
                mysqli_stmt_execute($notifCountStmt);
                mysqli_stmt_bind_result($notifCountStmt, $freshNotifCount);
                mysqli_stmt_fetch($notifCountStmt);
                mysqli_stmt_close($notifCountStmt);
                $notifCount = max(0, (int) $freshNotifCount);
                emsp_session_set_notif_count($notifCount);
            }

            if (function_exists('emsp_unread_notification_sections')) {
                $notifSections = emsp_unread_notification_sections($con, $authUserId);
                emsp_session_set_notif_sections($notifSections);
                $_SESSION['emsp_notif_sections_fetched_at'] = time();
            }
        }
    }
}

$firstName = emsp_fix_mojibake((string) ($authUser['first_name'] ?? ''));
$lastName = emsp_fix_mojibake((string) ($authUser['last_name'] ?? ''));
$initials = '';
if ($firstName !== '') {
    $initials .= mb_strtoupper(mb_substr($firstName, 0, 1));
}
if ($lastName !== '') {
    $initials .= mb_strtoupper(mb_substr($lastName, 0, 1));
}
if ($initials === '') {
    $initials = emsp_user_initials($firstName, $lastName);
}

$userLabel = trim($firstName . ($lastName !== '' ? ' ' . mb_strtoupper(mb_substr($lastName, 0, 1)) . '.' : ''));
$userBadge = [
    'none' => '',
    'bronze' => 'Bronze',
    'argent' => 'Argent',
    'or' => 'Or',
][$authUser['badge_level'] ?? 'none'] ?? '';
$statusLabel = [
    'pending' => 'Compte en attente',
    'rejected' => 'Compte refuse',
    'suspended' => 'Compte suspendu',
][$accountStatus] ?? ($userBadge !== '' ? $userBadge : 'Espace etudiant');

$photoSrc = emsp_user_photo_src((string) ($authUser['photo_path'] ?? ''));
if ($photoSrc !== '' && !preg_match('#^https?://#i', $photoSrc)) {
    $photoSrc = $base . ltrim($photoSrc, '/');
}

$routeLabels = [
    'index.php' => 'Accueil',
    'formations.php' => 'Formations',
    'mediatheque.php' => 'Mediatheque',
    'news-blog.php' => 'Journal',
    'news-article.php' => 'Article',
    'concours.php' => 'Concours',
    'faq.php' => 'FAQ',
    'login.php' => 'Connexion',
    'register.php' => 'Inscription',
    'forgot-password.php' => 'Mot de passe oublie',
    'reset-password-code.php' => 'Reinitialisation',
    'reset-password.php' => 'Reinitialisation',
    'bibliotheque.php' => 'Bibliotheque',
    'dashboard.php' => 'Espace etudiant',
    'upload.php' => 'Deposer un document',
    'mes-favoris.php' => 'Mes favoris',
    'historique.php' => 'Historique',
    'mon-profil.php' => 'Mon profil',
    'pending-status.php' => 'Statut du compte',
    'verify-email.php' => 'Verification email',
    'institution.php' => 'Institution',
    'document.php' => 'Document',
];
$breadcrumbLabel = trim((string) ($routeLabels[$currentPath] ?? ($page_title ?? '')));
if ($breadcrumbLabel === '' || $breadcrumbLabel === 'Plateforme EMSP Docs') {
    $breadcrumbLabel = 'Page';
}
$showBreadcrumb = !in_array($currentPath, ['index.php', ''], true);

$visitorLinks = [
    ['label' => 'Accueil', 'href' => 'index.php', 'paths' => ['index.php', '']],
    ['label' => 'Institution', 'href' => 'institution.php', 'paths' => ['institution.php']],
    ['label' => 'Formations', 'href' => 'formations.php', 'paths' => ['formations.php']],
    ['label' => 'Mediatheque', 'href' => 'mediatheque.php', 'paths' => ['mediatheque.php'], 'badge_section' => 'media'],
    ['label' => 'Journal', 'href' => 'news-blog.php', 'paths' => ['news-blog.php', 'news-article.php'], 'badge_section' => 'journal'],
    ['label' => 'Concours', 'href' => 'concours.php', 'paths' => ['concours.php']],
    ['label' => 'FAQ', 'href' => 'faq.php', 'paths' => ['faq.php']],
];
$memberDiscoverLinks = [
    ['label' => 'Institution', 'href' => 'institution.php', 'paths' => ['institution.php']],
    ['label' => 'Formations', 'href' => 'formations.php', 'paths' => ['formations.php']],
    ['label' => 'FAQ', 'href' => 'faq.php', 'paths' => ['faq.php']],
];
$memberLinks = [
    ['label' => 'Accueil', 'href' => 'index.php', 'paths' => ['index.php', '']],
    ['label' => 'Bibliotheque', 'href' => 'bibliotheque.php', 'paths' => ['bibliotheque.php', 'document.php']],
    ['label' => 'Mediatheque', 'href' => 'mediatheque.php', 'paths' => ['mediatheque.php'], 'badge_section' => 'media'],
    ['label' => 'Concours', 'href' => 'concours.php', 'paths' => ['concours.php']],
    ['label' => 'Journal', 'href' => 'news-blog.php', 'paths' => ['news-blog.php', 'news-article.php'], 'badge_section' => 'journal'],
];
$memberDesktopLinks = [
    ['label' => 'Accueil', 'href' => 'index.php', 'paths' => ['index.php', '']],
    ['label' => 'Bibliotheque', 'href' => 'bibliotheque.php', 'paths' => ['bibliotheque.php', 'document.php']],
    ['label' => 'Decouvrir', 'paths' => ['institution.php', 'formations.php', 'faq.php'], 'children' => $memberDiscoverLinks],
    ['label' => 'Mediatheque', 'href' => 'mediatheque.php', 'paths' => ['mediatheque.php'], 'badge_section' => 'media'],
    ['label' => 'Concours', 'href' => 'concours.php', 'paths' => ['concours.php']],
    ['label' => 'Journal', 'href' => 'news-blog.php', 'paths' => ['news-blog.php', 'news-article.php'], 'badge_section' => 'journal'],
];
$visitorOffcanvasLinks = [
    ['label' => 'Accueil', 'href' => 'index.php', 'paths' => ['index.php', '']],
    ['label' => 'Decouvrir', 'paths' => ['institution.php', 'formations.php', 'faq.php'], 'children' => $memberDiscoverLinks],
    ['label' => 'Mediatheque', 'href' => 'mediatheque.php', 'paths' => ['mediatheque.php'], 'badge_section' => 'media'],
    ['label' => 'Journal', 'href' => 'news-blog.php', 'paths' => ['news-blog.php', 'news-article.php'], 'badge_section' => 'journal'],
    ['label' => 'Concours', 'href' => 'concours.php', 'paths' => ['concours.php']],
];
$memberOffcanvasLinks = [
    ['label' => 'Accueil', 'href' => 'index.php', 'paths' => ['index.php', '']],
    ['label' => 'Bibliotheque', 'href' => 'bibliotheque.php', 'paths' => ['bibliotheque.php', 'document.php']],
    ['label' => 'Decouvrir', 'paths' => ['institution.php', 'formations.php', 'faq.php'], 'children' => $memberDiscoverLinks],
    ['label' => 'Mediatheque', 'href' => 'mediatheque.php', 'paths' => ['mediatheque.php'], 'badge_section' => 'media'],
    ['label' => 'Journal', 'href' => 'news-blog.php', 'paths' => ['news-blog.php', 'news-article.php'], 'badge_section' => 'journal'],
    ['label' => 'Concours', 'href' => 'concours.php', 'paths' => ['concours.php']],
];
$inactiveLinks = [
    ['label' => 'Accueil', 'href' => 'index.php', 'paths' => ['index.php', '']],
    ['label' => 'Institution', 'href' => 'institution.php', 'paths' => ['institution.php']],
    ['label' => 'Mediatheque', 'href' => 'mediatheque.php', 'paths' => ['mediatheque.php'], 'badge_section' => 'media'],
    ['label' => 'Journal', 'href' => 'news-blog.php', 'paths' => ['news-blog.php', 'news-article.php'], 'badge_section' => 'journal'],
    ['label' => 'Concours', 'href' => 'concours.php', 'paths' => ['concours.php']],
    ['label' => 'Suivi du compte', 'href' => 'pending-status.php', 'paths' => ['pending-status.php', 'verify-email.php']],
];

// Maintenance: keep role/status routing centralized here so mobile/desktop navs never drift apart.
$navLinks = !$isAuth ? $visitorLinks : ($isActiveAccount ? $memberLinks : $inactiveLinks);
$desktopNavLinks = !$isAuth ? $visitorLinks : ($isActiveAccount ? $memberDesktopLinks : $inactiveLinks);
$offcanvasNavLinks = !$isAuth ? $visitorOffcanvasLinks : ($isActiveAccount ? $memberOffcanvasLinks : $inactiveLinks);
$accountHomeHref = $isActiveAccount ? 'dashboard.php' : 'pending-status.php';
$accountHomeLabel = $isActiveAccount ? 'Mon espace' : 'Suivi du compte';
$mobileAccountShortcutHref = $canAccessAdmin ? 'admin/index.php' : $accountHomeHref;
$mobileAccountShortcutLabel = $canAccessAdmin ? 'Administration' : $accountHomeLabel;
$mobileAccountShortcutIcon = $canAccessAdmin ? 'speedometer2' : 'grid-1x2-fill';

$mobileChromeHiddenRoutes = [
    'login.php',
    'register.php',
    'forgot-password.php',
    'reset-password.php',
    'reset-password-code.php',
    'resend-verification.php',
];
$authLandingRoutes = [
    'login.php',
    'register.php',
    'forgot-password.php',
    'reset-password.php',
    'reset-password-code.php',
    'resend-verification.php',
    'pending-status.php',
];
$showMobileSearch = $canSearchDocuments && !in_array($currentPath, $mobileChromeHiddenRoutes, true);
$showMobileBottomNav = false;
$hideGuestAuthButtons = in_array($currentPath, $authLandingRoutes, true);
$showGuestLoginButton = !$hideGuestAuthButtons && $currentPath !== 'login.php';
$showGuestRegisterButton = !$hideGuestAuthButtons && $currentPath !== 'register.php';
$loginModalEntryHref = $base . 'index.php?open_login=1';
$currentFeedSection = in_array($currentPath, ['news-blog.php', 'news-article.php'], true)
    ? 'journal'
    : (in_array($currentPath, ['mediatheque.php'], true) ? 'media' : '');

if (!function_exists('emsp_nav_active')) {
    function emsp_nav_active(array $paths, string $currentPath): string
    {
        return in_array($currentPath, $paths, true) ? 'active' : '';
    }
}

if (!function_exists('emsp_nav_badge_payload')) {
    function emsp_nav_badge_payload(?string $section, bool $isAuth, array $counts, array $latest): array
    {
        $section = strtolower(trim((string) $section));
        if (!in_array($section, ['journal', 'media'], true)) {
            return ['section' => '', 'count' => 0, 'latest' => 0, 'show' => false];
        }

        $count = $isAuth ? max(0, (int) ($counts[$section] ?? 0)) : 0;
        $latestId = max(0, (int) ($latest[$section] ?? 0));

        return [
            'section' => $section,
            'count' => $count,
            'latest' => $latestId,
            'show' => $count > 0,
        ];
    }
}

$guestBottomNav = [
    ['label' => 'Accueil', 'href' => 'index.php', 'icon' => 'house-door-fill', 'paths' => ['index.php', '']],
    ['label' => 'Institution', 'href' => 'institution.php', 'icon' => 'building', 'paths' => ['institution.php']],
    ['label' => 'Mediatheque', 'href' => 'mediatheque.php', 'icon' => 'images', 'paths' => ['mediatheque.php'], 'badge_section' => 'media'],
    ['label' => 'Journal', 'href' => 'news-blog.php', 'icon' => 'newspaper', 'paths' => ['news-blog.php', 'news-article.php'], 'badge_section' => 'journal'],
    ['label' => 'Concours', 'href' => 'concours.php', 'icon' => 'trophy', 'paths' => ['concours.php']],
];
$pendingBottomNav = [
    ['label' => 'Accueil', 'href' => 'index.php', 'icon' => 'house-door-fill', 'paths' => ['index.php', '']],
    ['label' => 'Institution', 'href' => 'institution.php', 'icon' => 'building', 'paths' => ['institution.php']],
    ['label' => 'Journal', 'href' => 'news-blog.php', 'icon' => 'newspaper', 'paths' => ['news-blog.php', 'news-article.php'], 'badge_section' => 'journal'],
    ['label' => 'Concours', 'href' => 'concours.php', 'icon' => 'trophy', 'paths' => ['concours.php']],
    ['label' => 'Suivi', 'href' => 'pending-status.php', 'icon' => 'hourglass-split', 'paths' => ['pending-status.php', 'verify-email.php', 'resend-verification.php']],
];
$studentBottomNav = [
    ['label' => 'Accueil', 'href' => 'index.php', 'icon' => 'house-door-fill', 'paths' => ['index.php', '']],
    ['label' => 'Bibliotheque', 'href' => 'bibliotheque.php', 'icon' => 'collection', 'paths' => ['bibliotheque.php', 'document.php']],
    ['label' => 'Deposer', 'href' => 'upload.php', 'icon' => 'cloud-arrow-up-fill', 'paths' => ['upload.php']],
    ['label' => 'Notifications', 'href' => 'dashboard.php#notifications', 'icon' => 'bell-fill', 'paths' => ['dashboard.php']],
    ['label' => 'Profil', 'href' => 'mon-profil.php', 'icon' => 'person-circle', 'paths' => ['mon-profil.php', 'mes-favoris.php', 'historique.php']],
];
$staffBottomNav = [
    ['label' => 'Accueil', 'href' => 'index.php', 'icon' => 'house-door-fill', 'paths' => ['index.php', '']],
    ['label' => 'Bibliotheque', 'href' => 'bibliotheque.php', 'icon' => 'collection', 'paths' => ['bibliotheque.php', 'document.php']],
    ['label' => 'Journal', 'href' => 'news-blog.php', 'icon' => 'newspaper', 'paths' => ['news-blog.php', 'news-article.php'], 'badge_section' => 'journal'],
    ['label' => 'Notifications', 'href' => 'dashboard.php#notifications', 'icon' => 'bell-fill', 'paths' => ['dashboard.php']],
    ['label' => 'Admin', 'href' => 'admin/index.php', 'icon' => 'speedometer2', 'paths' => []],
];
// Maintenance: connected students use a journal-first bottom nav on mobile while staff keeps the admin-oriented variant below.
if (!$canAccessAdmin) {
    $studentBottomNav = [
        ['label' => 'Accueil', 'href' => 'index.php', 'icon' => 'house-door-fill', 'paths' => ['index.php', '']],
        ['label' => 'Bibliotheque', 'href' => 'bibliotheque.php', 'icon' => 'collection', 'paths' => ['bibliotheque.php', 'document.php']],
        ['label' => 'Journal', 'href' => 'news-blog.php', 'icon' => 'newspaper', 'paths' => ['news-blog.php', 'news-article.php'], 'badge_section' => 'journal'],
        ['label' => 'Notifications', 'href' => 'dashboard.php#notifications', 'icon' => 'bell-fill', 'paths' => ['dashboard.php']],
        ['label' => 'Profil', 'href' => 'mon-profil.php', 'icon' => 'person-circle', 'paths' => ['mon-profil.php', 'mes-favoris.php', 'historique.php']],
    ];
}

$mobileBottomNavItems = !$isAuth
    ? $guestBottomNav
    : ($isActiveAccount
        ? ($canAccessAdmin ? $staffBottomNav : $studentBottomNav)
        : $pendingBottomNav);

$topbarStaticMessage = !$isAuth
    ? 'Ressources, journal, médiathèque et concours EMSP.'
    : ($isActiveAccount
        ? 'Bibliothèque, favoris, notifications et médias en un clic.'
        : "Confirme ton email pour finaliser l'activation de ton espace EMSP Docs.");
?>

<header class="site-header">
    <div class="emsp-topbar">
        <div class="container emsp-topbar-track emsp-topbar-track--static" data-emsp-topbar>
            <span class="emsp-topbar-badge">
                <i class="bi bi-mortarboard-fill" aria-hidden="true"></i>
                Accès gratuit pour les étudiants EMSP
            </span>
            <span class="emsp-topbar-message"><?= h($topbarStaticMessage) ?></span>
        </div>
    </div>

    <div class="emsp-navbar-wrap">
        <nav class="navbar navbar-expand-xl emsp-navbar">
            <div class="container">
                <a class="emsp-brand" href="<?= $base ?>index.php">
                    <img src="<?= $asset ?>images/logo-emsp.png" alt="Logo EMSP" class="emsp-brand-logo">
                    <div class="emsp-brand-label">
                        <p class="emsp-brand-title">EMSP Docs</p>
                        <p class="emsp-brand-subtitle">Bibliothèque EMSP</p>
                    </div>
                </a>

                <div class="emsp-nav-desktop d-none d-xl-flex order-xl-2">
                    <ul class="navbar-nav flex-row flex-nowrap">
                        <?php foreach ($desktopNavLinks as $link): ?>
                            <?php $linkBadge = emsp_nav_badge_payload($link['badge_section'] ?? '', $isAuth, $notifSections, $navLatestSections); ?>
                            <li class="nav-item">
                                <?php if (!empty($link['children']) && is_array($link['children'])): ?>
                                    <div class="dropdown emsp-nav-dropdown">
                                        <button class="nav-link dropdown-toggle emsp-nav-dropdown-toggle <?= emsp_nav_active($link['paths'], $currentPath) ?>" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <span class="emsp-nav-link-label"><?= h($link['label']) ?></span>
                                        </button>
                                        <ul class="dropdown-menu emsp-nav-dropdown-menu">
                                            <?php foreach ($link['children'] as $child): ?>
                                                <li>
                                                    <a class="dropdown-item <?= emsp_nav_active($child['paths'], $currentPath) ?>" href="<?= $base . $child['href'] ?>">
                                                        <?= h($child['label']) ?>
                                                    </a>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php else: ?>
                                    <a class="nav-link <?= emsp_nav_active($link['paths'], $currentPath) ?>" href="<?= $base . $link['href'] ?>">
                                        <span class="emsp-nav-link-label"><?= h($link['label']) ?></span>
                                        <?php if ($linkBadge['section'] !== ''): ?>
                                            <?php // Public badges signal fresh content only; personal unread counts stay reserved for authenticated notification UI. ?>
                                            <span
                                                class="emsp-nav-link-badge<?= $linkBadge['show'] ? '' : ' is-hidden' ?>"
                                                data-emsp-section-badge
                                                data-section="<?= h($linkBadge['section']) ?>"
                                                data-latest-id="<?= (int) $linkBadge['latest'] ?>"
                                                data-authenticated="<?= $isAuth ? '1' : '0' ?>"
                                            >
                                                <span class="emsp-nav-link-badge__dot"></span>
                                                <span class="emsp-nav-link-badge__count"><?= $linkBadge['count'] > 99 ? '99+' : $linkBadge['count'] ?></span>
                                            </span>
                                        <?php endif; ?>
                                    </a>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($canSearchDocuments): ?>
                        <form action="<?= $base ?>bibliotheque.php" method="GET" class="emsp-nav-search" role="search">
                            <div class="input-group input-group-sm">
                                <label class="visually-hidden" for="emsp-desktop-nav-search">Rechercher un document</label>
                                <input type="search" id="emsp-desktop-nav-search" name="q" class="form-control" placeholder="Rechercher un cours, un TD..." aria-label="Rechercher un cours, un TD ou un examen" value="<?= h((string) ($_GET['q'] ?? '')) ?>">
                                <button type="submit" class="btn" aria-label="Rechercher"><i class="bi bi-search"></i></button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="emsp-nav-quick order-xl-3">
                    <?php if (!$isAuth): ?>
                        <?php if ($showGuestLoginButton): ?>
                            <a class="btn btn-sm emsp-btn-outline d-none d-xl-inline-flex emsp-open-login-modal"
                               href="<?= h($loginModalEntryHref) ?>"
                               data-bs-toggle="modal"
                               data-bs-target="#emspQuickLoginModal"
                               data-emsp-modal-link="1">
                                <span class="emsp-btn-label-full">Connexion</span>
                                <span class="emsp-btn-label-compact">Connexion</span>
                            </a>
                        <?php endif; ?>
                        <?php if ($showGuestRegisterButton): ?>
                            <a class="btn btn-sm emsp-btn-signup d-none d-xl-inline-flex" href="<?= $base ?>register.php">
                                <span class="emsp-btn-label-full">S'inscrire</span>
                                <span class="emsp-btn-label-compact">Inscription</span>
                            </a>
                        <?php endif; ?>
                        <?php if (!$hideGuestAuthButtons): ?>
                            <?php if ($currentPath !== 'login.php'): ?>
                                <a class="btn btn-sm emsp-icon-btn emsp-mobile-auth-shortcut d-inline-flex d-xl-none"
                                   href="<?= h($loginModalEntryHref) ?>"
                                   data-bs-toggle="modal"
                                   data-bs-target="#emspQuickLoginModal"
                                   data-emsp-modal-link="1"
                                   aria-label="Connexion rapide"
                                   title="Connexion">
                                    <i class="bi bi-box-arrow-in-right"></i>
                                </a>
                            <?php endif; ?>
                            <?php if ($currentPath !== 'register.php'): ?>
                                <a class="btn btn-sm emsp-icon-btn emsp-mobile-auth-shortcut emsp-mobile-register-shortcut d-inline-flex d-xl-none"
                                   href="<?= $base ?>register.php"
                                   aria-label="Inscription rapide"
                                   title="Inscription">
                                    <i class="bi bi-person-plus-fill"></i>
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if ($canAccessAdmin): ?>
                            <a class="btn btn-sm emsp-btn-admin emsp-admin-shortcut d-none d-xl-inline-flex" href="<?= $base ?>admin/index.php">
                                <span class="emsp-btn-label-full">Administration</span>
                                <span class="emsp-btn-label-compact">Admin</span>
                            </a>
                        <?php endif; ?>
                        <?php if ($isActiveAccount): ?>
                            <!-- Maintenance: mobile keeps this shortcut icon-only so the header stays light on small screens. -->
                            <a class="btn btn-sm emsp-icon-btn emsp-mobile-account-shortcut d-inline-flex d-lg-none" href="<?= $base . $mobileAccountShortcutHref ?>" aria-label="<?= h($mobileAccountShortcutLabel) ?>" title="<?= h($mobileAccountShortcutLabel) ?>">
                                <i class="bi bi-<?= h($mobileAccountShortcutIcon) ?>"></i>
                            </a>
                            <a class="btn btn-sm emsp-btn-deposit d-none d-lg-inline-flex" href="<?= $base ?>upload.php">
                                <i class="bi bi-cloud-arrow-up-fill me-1"></i>
                                <span class="emsp-btn-label-full">Deposer un document</span>
                                <span class="emsp-btn-label-compact">Deposer</span>
                            </a>
                            <a class="btn btn-sm emsp-icon-btn" href="<?= $base ?>dashboard.php#notifications" data-bs-toggle="tooltip" data-bs-placement="bottom" title="Notifications" aria-label="Notifications">
                                <i class="bi bi-bell-fill"></i>
                                <?php if ($notifCount > 0): ?>
                                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= $notifCount > 99 ? '99+' : $notifCount ?></span>
                                <?php endif; ?>
                            </a>
                        <?php else: ?>
                            <a class="btn btn-sm emsp-btn-outline" href="<?= $base ?>pending-status.php">
                                <i class="bi bi-hourglass-split me-1"></i>Suivi du compte
                            </a>
                        <?php endif; ?>

                        <div class="dropdown d-none d-md-block">
                            <button class="btn emsp-avatar-btn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <?php if ($photoSrc !== ''): ?>
                                    <img src="<?= h($photoSrc) ?>" alt="Profil" class="emsp-avatar">
                                <?php else: ?>
                                    <span class="emsp-avatar-fallback"><?= h($initials) ?></span>
                                <?php endif; ?>
                                <span class="text-start emsp-avatar-copy">
                                    <span class="d-block lh-1 emsp-avatar-name"><?= h($userLabel !== '' ? $userLabel : 'Mon compte') ?></span>
                                    <small class="text-muted fw-semibold emsp-avatar-status"><?= h($statusLabel) ?></small>
                                </span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 p-2">
                                <li><a class="dropdown-item rounded-3" href="<?= $base . $accountHomeHref ?>"><i class="bi bi-grid me-2"></i><?= h($accountHomeLabel) ?></a></li>
                                <li><a class="dropdown-item rounded-3" href="<?= $base ?>mon-profil.php"><i class="bi bi-person me-2"></i>Mon profil</a></li>
                                <?php if ($isActiveAccount): ?>
                                    <li><a class="dropdown-item rounded-3" href="<?= $base ?>mes-favoris.php"><i class="bi bi-star me-2"></i>Mes favoris</a></li>
                                    <li><a class="dropdown-item rounded-3" href="<?= $base ?>historique.php"><i class="bi bi-clock-history me-2"></i>Historique</a></li>
                                    <li><a class="dropdown-item rounded-3" href="<?= $base ?>upload.php"><i class="bi bi-cloud-arrow-up me-2"></i>Deposer un document</a></li>
                                <?php endif; ?>
                                <?php if ($canAccessAdmin): ?>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item rounded-3 fw-semibold" href="<?= $base ?>admin/index.php"><i class="bi bi-speedometer2 me-2"></i>Administration</a></li>
                                <?php endif; ?>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <a class="dropdown-item rounded-3" href="<?= $base ?>logout.php" data-href="<?= $base ?>logout.php" data-confirm="Te deconnecter ?" data-confirm-detail="Tu reviendras a l'accueil public de la plateforme." data-confirm-type="warning" data-confirm-ok="Oui, me deconnecter" data-emsp-confirm-auto="1">
                                        <i class="bi bi-box-arrow-right me-2"></i>Deconnexion
                                    </a>
                                </li>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <button class="navbar-toggler d-xl-none emsp-navbar-toggler" type="button" data-bs-toggle="offcanvas" data-bs-target="#emspMainOffcanvas" aria-controls="emspMainOffcanvas" aria-label="Ouvrir le menu principal" aria-expanded="false">
                        <span class="emsp-hamburger" aria-hidden="true">
                            <span></span><span></span><span></span>
                        </span>
                    </button>
                </div>
            </div>
        </nav>

        <?php if ($showMobileSearch): ?>
            <!-- Maintenance: mobile search lives outside the offcanvas to avoid duplicated entry points on touch devices. -->
            <div class="emsp-nav-search-mobile-wrap d-lg-none">
                <div class="container">
                    <form action="<?= $base ?>bibliotheque.php" method="GET" class="emsp-nav-search-mobile" role="search">
                        <div class="input-group">
                            <label class="visually-hidden" for="emsp-mobile-inline-search">Rechercher un document</label>
                            <input type="search" id="emsp-mobile-inline-search" name="q" class="form-control" placeholder="Rechercher un cours, un TD..." aria-label="Rechercher un cours, un TD ou un examen" value="<?= h((string) ($_GET['q'] ?? '')) ?>">
                            <button type="submit" class="btn" aria-label="Rechercher"><i class="bi bi-search"></i></button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($showBreadcrumb): ?>
            <div class="emsp-nav-breadcrumb">
                <div class="container">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="<?= $base ?>index.php">Accueil</a></li>
                            <li class="breadcrumb-item active" aria-current="page"><?= h($breadcrumbLabel) ?></li>
                        </ol>
                    </nav>
                </div>
            </div>
        <?php endif; ?>
    </div>
</header>

<div class="offcanvas offcanvas-start emsp-navbar-offcanvas d-xl-none" tabindex="-1" id="emspMainOffcanvas" aria-labelledby="emspMainOffcanvasLabel">
    <div class="offcanvas-header">
        <div class="emsp-offcanvas-brand" id="emspMainOffcanvasLabel">
            <img src="<?= $asset ?>images/logo-emsp.png" alt="Logo EMSP">
            <div>
                <strong>EMSP Docs</strong>
                <small>Navigation et acces rapides</small>
            </div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Fermer le menu"></button>
    </div>
    <div class="offcanvas-body">
        <div class="emsp-offcanvas-nav">
            <?php foreach ($offcanvasNavLinks as $index => $link): ?>
                <?php $linkBadge = emsp_nav_badge_payload($link['badge_section'] ?? '', $isAuth, $notifSections, $navLatestSections); ?>
                <?php if (!empty($link['children']) && is_array($link['children'])): ?>
                    <?php
                    $collapseId = 'emspOffcanvasSubnav' . $index;
                    $isChildOpen = emsp_nav_active($link['paths'], $currentPath) !== '';
                    ?>
                    <button
                        class="emsp-offcanvas-accordion-toggle<?= $isChildOpen ? ' is-open' : '' ?>"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#<?= h($collapseId) ?>"
                        aria-controls="<?= h($collapseId) ?>"
                        aria-expanded="<?= $isChildOpen ? 'true' : 'false' ?>"
                    >
                        <span><?= h($link['label']) ?></span>
                        <i class="bi bi-chevron-down emsp-offcanvas-chevron" aria-hidden="true"></i>
                    </button>
                    <div id="<?= h($collapseId) ?>" class="collapse emsp-offcanvas-accordion-panel<?= $isChildOpen ? ' show' : '' ?>" role="region" aria-label="<?= h($link['label']) ?>">
                        <?php foreach ($link['children'] as $child): ?>
                            <a class="nav-link emsp-offcanvas-subnav-link <?= emsp_nav_active($child['paths'], $currentPath) ?>" href="<?= $base . $child['href'] ?>">
                                <span class="emsp-nav-link-label"><?= h($child['label']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <a class="nav-link <?= emsp_nav_active($link['paths'], $currentPath) ?>" href="<?= $base . $link['href'] ?>">
                        <span class="emsp-nav-link-label"><?= h($link['label']) ?></span>
                        <?php if ($linkBadge['section'] !== ''): ?>
                            <span
                                class="emsp-nav-link-badge<?= $linkBadge['show'] ? '' : ' is-hidden' ?>"
                                data-emsp-section-badge
                                data-section="<?= h($linkBadge['section']) ?>"
                                data-latest-id="<?= (int) $linkBadge['latest'] ?>"
                                data-authenticated="<?= $isAuth ? '1' : '0' ?>"
                            >
                                <span class="emsp-nav-link-badge__dot"></span>
                                <span class="emsp-nav-link-badge__count"><?= $linkBadge['count'] > 99 ? '99+' : $linkBadge['count'] ?></span>
                            </span>
                        <?php endif; ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <div class="emsp-offcanvas-meta">
            <?php if (!$isAuth): ?>
                <div class="emsp-mobile-card">
                    <div class="fw-bold mb-2">Commence ici</div>
                    <p class="small text-white-50 mb-3">Cree ton compte ou connecte-toi pour acceder a tes espaces reserves et suivre l'actualite EMSP.</p>
                    <div class="d-grid gap-2">
                        <a class="btn emsp-btn-signup" href="<?= $base ?>register.php">S'inscrire</a>
                    </div>
                    <a class="d-inline-flex align-items-center gap-2 small fw-semibold text-white text-decoration-none mt-3 emsp-open-login-modal"
                       href="<?= h($loginModalEntryHref) ?>"
                       data-bs-toggle="modal"
                       data-bs-target="#emspQuickLoginModal"
                       data-emsp-modal-link="1">
                        <i class="bi bi-box-arrow-in-right"></i>Deja inscrit ? Se connecter
                    </a>
                </div>
            <?php else: ?>
                <div class="emsp-mobile-account">
                    <div class="emsp-mobile-card">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <?php if ($photoSrc !== ''): ?>
                                <img src="<?= h($photoSrc) ?>" alt="Profil" class="emsp-avatar">
                            <?php else: ?>
                                <span class="emsp-avatar-fallback"><?= h($initials) ?></span>
                            <?php endif; ?>
                            <div>
                                <div class="fw-bold text-white"><?= h($userLabel !== '' ? $userLabel : 'Mon compte') ?></div>
                                <div class="small text-white-50"><?= h($statusLabel) ?></div>
                            </div>
                        </div>
                        <div class="emsp-mobile-links">
                            <a href="<?= $base . $accountHomeHref ?>"><i class="bi bi-house-door"></i><?= h($accountHomeLabel) ?></a>
                            <a href="<?= $base ?>mon-profil.php"><i class="bi bi-person"></i>Mon profil</a>
                            <?php if ($isActiveAccount): ?>
                                <a href="<?= $base ?>mes-favoris.php"><i class="bi bi-star"></i>Mes favoris</a>
                                <a href="<?= $base ?>historique.php"><i class="bi bi-clock-history"></i>Historique</a>
                                <a href="<?= $base ?>upload.php"><i class="bi bi-cloud-arrow-up"></i>Deposer un document</a>
                            <?php endif; ?>
                            <?php if ($canAccessAdmin): ?>
                                <a href="<?= $base ?>admin/index.php"><i class="bi bi-speedometer2"></i>Administration</a>
                            <?php endif; ?>
                            <a href="<?= $base ?>logout.php" data-href="<?= $base ?>logout.php" data-confirm="Te deconnecter ?" data-confirm-detail="Tu reviendras a l'accueil public de la plateforme." data-confirm-type="warning" data-confirm-ok="Oui, me deconnecter" data-emsp-confirm-auto="1">
                                <i class="bi bi-box-arrow-right"></i>Deconnexion
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!$isAuth): ?>
<div class="modal fade emsp-login-modal" id="emspQuickLoginModal" tabindex="-1" aria-labelledby="emspQuickLoginModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <button type="button" class="btn-close emsp-login-modal-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            <div class="emsp-login-modal-scene">
                <div class="emsp-login-modal-card">
                    <div class="emsp-login-modal-card-head">
                        <span class="emsp-login-modal-kicker">Bienvenue</span>
                        <h2 id="emspQuickLoginModalLabel">Se connecter</h2>
                        <p>Accede rapidement a ton espace EMSP Docs.</p>
                    </div>
                    <div class="emsp-login-modal-card-brand">
                        <img src="<?= $asset ?>images/logo-emsp.png" alt="Logo EMSP" loading="lazy">
                    </div>
                    <div class="emsp-login-modal-form-wrap">
                        <form action="<?= $base ?>logincode.php" method="post" class="emsp-login-modal-form" data-emsp-submit="1">
                        <?php csrf_input(); ?>
                        <label for="emspQuickLoginEmail" class="form-label">Email</label>
                        <input type="email" id="emspQuickLoginEmail" name="email" class="form-control" required autocomplete="email" placeholder="nom@ecole.com">

                        <label for="emspQuickLoginPassword" class="form-label">Mot de passe</label>
                        <div class="emsp-password-wrap">
                            <input type="password" id="emspQuickLoginPassword" name="password" class="form-control" required autocomplete="current-password" placeholder="Mot de passe">
                            <button class="emsp-password-toggle" type="button" id="toggle-quick-login-password" aria-label="Afficher le mot de passe" aria-pressed="false">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>

                        <button class="btn btn-emsp w-100 mt-3" type="submit" data-loading-text="Connexion en cours...">
                            <i class="bi bi-box-arrow-in-right me-2"></i>Se connecter
                        </button>
                        </form>
                        <div class="emsp-login-modal-links">
                            <a href="<?= $base ?>forgot-password.php">Mot de passe oublie ?</a>
                            <a href="<?= $base ?>register.php">Creer un compte</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    var offcanvas = document.getElementById('emspMainOffcanvas');
    var navbarToggler = document.querySelector('.emsp-navbar-toggler');

    function syncHamburgerState(isOpen) {
        if (!navbarToggler) {
            return;
        }
        navbarToggler.classList.toggle('is-open', !!isOpen);
        navbarToggler.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }

    if (offcanvas) {
        offcanvas.addEventListener('show.bs.offcanvas', function () {
            syncHamburgerState(true);
        });
        offcanvas.addEventListener('shown.bs.offcanvas', function () {
            syncHamburgerState(true);
        });
        offcanvas.addEventListener('hide.bs.offcanvas', function () {
            syncHamburgerState(false);
        });
        offcanvas.addEventListener('hidden.bs.offcanvas', function () {
            syncHamburgerState(false);
        });
    }

    if (navbarToggler) {
        navbarToggler.addEventListener('click', function () {
            syncHamburgerState(true);
        });
    }

    document.querySelectorAll('.emsp-offcanvas-accordion-toggle').forEach(function (toggle) {
        var targetId = toggle.getAttribute('data-bs-target');
        var panel = targetId ? document.querySelector(targetId) : null;
        if (!panel) {
            return;
        }

        panel.addEventListener('show.bs.collapse', function () {
            toggle.classList.add('is-open');
            toggle.setAttribute('aria-expanded', 'true');
        });

        panel.addEventListener('hide.bs.collapse', function () {
            toggle.classList.remove('is-open');
            toggle.setAttribute('aria-expanded', 'false');
        });
    });

    var badges = document.querySelectorAll('[data-emsp-section-badge]');
    if (badges.length && window.localStorage) {
        var isAuthenticated = <?= $isAuth ? 'true' : 'false' ?>;
        var latestSections = <?= json_encode($navLatestSections, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        var currentSection = <?= json_encode($currentFeedSection, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

        function storageKey(section) {
            return 'emsp-section-last-seen:' + section;
        }

        function updateBadge(badge, count) {
            var countNode = badge.querySelector('.emsp-nav-link-badge__count');
            if (countNode) {
                countNode.textContent = count > 99 ? '99+' : String(count);
            }
            badge.classList.toggle('is-hidden', count <= 0);
        }

        if (!isAuthenticated && currentSection && latestSections[currentSection]) {
            try {
                window.localStorage.setItem(storageKey(currentSection), String(latestSections[currentSection]));
            } catch (error) {
                // Ignore quota/storage failures and keep navigation usable.
            }
        }

        badges.forEach(function (badge) {
            if (badge.getAttribute('data-authenticated') === '1') {
                return;
            }
            var section = badge.getAttribute('data-section') || '';
            var latestId = parseInt(badge.getAttribute('data-latest-id') || '0', 10);
            var lastSeen = 0;
            try {
                lastSeen = parseInt(window.localStorage.getItem(storageKey(section)) || '0', 10);
            } catch (error) {
                lastSeen = 0;
            }
            updateBadge(badge, latestId > lastSeen ? 1 : 0);
        });
    }

    document.querySelectorAll('[data-emsp-modal-link="1"]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            if (!window.bootstrap || !bootstrap.Modal) {
                return;
            }
            var targetSelector = link.getAttribute('data-bs-target');
            if (!targetSelector) {
                return;
            }
            var modalElement = document.querySelector(targetSelector);
            if (!modalElement) {
                return;
            }
            event.preventDefault();
            bootstrap.Modal.getOrCreateInstance(modalElement).show();
        });
    });

    var quickLoginModal = document.getElementById('emspQuickLoginModal');
    function tryOpenQuickLoginFromUrl() {
        if (!quickLoginModal || !window.bootstrap || !bootstrap.Modal) {
            return false;
        }
        var params = new URLSearchParams(window.location.search || '');
        var shouldOpenLoginModal = params.get('open_login') === '1' || params.get('login') === '1';
        if (shouldOpenLoginModal) {
            bootstrap.Modal.getOrCreateInstance(quickLoginModal).show();
            params.delete('open_login');
            params.delete('login');
            var nextQuery = params.toString();
            var nextUrl = window.location.pathname + (nextQuery ? ('?' + nextQuery) : '') + window.location.hash;
            if (window.history && typeof window.history.replaceState === 'function') {
                window.history.replaceState({}, document.title, nextUrl);
            }
        }
        return true;
    }
    if (quickLoginModal && !tryOpenQuickLoginFromUrl()) {
        window.addEventListener('load', tryOpenQuickLoginFromUrl, { once: true });
    }

    var quickPasswordInput = document.getElementById('emspQuickLoginPassword');
    var quickPasswordToggle = document.getElementById('toggle-quick-login-password');
    if (quickPasswordInput && quickPasswordToggle) {
        quickPasswordToggle.addEventListener('click', function () {
            var reveal = quickPasswordInput.type === 'password';
            quickPasswordInput.type = reveal ? 'text' : 'password';
            quickPasswordToggle.setAttribute('aria-pressed', reveal ? 'true' : 'false');
            quickPasswordToggle.setAttribute('aria-label', reveal ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
            quickPasswordToggle.innerHTML = reveal ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
        });
    }
})();
</script>

