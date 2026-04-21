<?php
include_once __DIR__ . '/../bootstrap.php';
include_once __DIR__ . '/../../includes/helpers.php';

$auth_user = $_SESSION['auth_user'] ?? [];
$auth_role = strtolower((string) ($_SESSION['auth_role'] ?? ''));
$is_admin = $auth_role === 'admin';
$role_label = $is_admin ? 'Administrateur' : 'Moderateur';
$initials = strtoupper(mb_substr((string) ($auth_user['first_name'] ?? 'A'), 0, 1) . mb_substr((string) ($auth_user['last_name'] ?? ''), 0, 1));
$photo_src = '';
if (!empty($auth_user['photo_path'])) {
    $photo_src = emsp_user_photo_src((string) $auth_user['photo_path']);
    if ($photo_src !== '' && !preg_match('#^https?://#i', $photo_src)) {
        $photo_src = '../' . ltrim($photo_src, '/');
    }
}

$current = basename((string) ($_SERVER['PHP_SELF'] ?? ''));
$pending_docs = 0;
$pending_users = 0;
if (isset($con) && $con instanceof mysqli) {
    if ($stmt = mysqli_prepare($con, "SELECT COUNT(*) FROM documents WHERE status='pending'")) {
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $pending_docs);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);
    }
    if ($stmt = mysqli_prepare($con, "SELECT COUNT(*) FROM users WHERE status='pending'")) {
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $pending_users);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);
    }
}

$nav_sections = [
    [
        'label' => 'Principal',
        'items' => [
            ['href' => 'index.php', 'icon' => 'speedometer2', 'label' => 'Dashboard'],
        ],
    ],
    [
        'label' => 'Documents',
        'items' => [
            ['href' => 'pending-documents.php', 'icon' => 'check2-square', 'label' => 'Validation docs', 'badge' => $pending_docs],
            ['href' => 'view-documents.php', 'icon' => 'folder2-open', 'label' => 'Tous les documents'],
        ],
    ],
    [
        'label' => 'Utilisateurs',
        'items' => [
            ['href' => 'pending-users.php', 'icon' => 'person-check', 'label' => 'Validation comptes', 'badge' => $pending_users],
            ['href' => 'view-users.php', 'icon' => 'people', 'label' => 'Tous les utilisateurs'],
            ['href' => 'badge-or-batch.php', 'icon' => 'trophy', 'label' => 'Badge Or', 'only_admin' => true],
        ],
    ],
    [
        'label' => 'Contenu',
        'items' => [
            ['href' => 'journal.php', 'icon' => 'newspaper', 'label' => 'Journal / News'],
            ['href' => 'mediatheque.php', 'icon' => 'collection-play', 'label' => 'Mediatheque'],
            ['href' => 'media-categories.php', 'icon' => 'tags', 'label' => 'Categories medias'],
            ['href' => 'comments-moderation.php', 'icon' => 'chat-dots', 'label' => 'Commentaires'],
            ['href' => 'edit-institution.php', 'icon' => 'building', 'label' => 'Institution'],
        ],
    ],
    [
        'label' => 'Referentiels',
        'items' => [
            ['href' => 'view-filieres.php', 'icon' => 'diagram-3', 'label' => 'Filieres'],
            ['href' => 'view-licences.php', 'icon' => 'layers', 'label' => 'Niveaux / Licences'],
            ['href' => 'view-modules.php', 'icon' => 'grid', 'label' => 'Modules'],
            ['href' => 'view-matieres.php', 'icon' => 'book', 'label' => 'Matieres'],
        ],
    ],
    [
        'label' => 'Systeme',
        'items' => [
            ['href' => 'school-domains.php', 'icon' => 'envelope-at', 'label' => 'Domaines email', 'only_admin' => true],
            ['href' => 'stats.php', 'icon' => 'graph-up', 'label' => 'Statistiques'],
            ['href' => 'settings.php', 'icon' => 'gear', 'label' => 'Parametres', 'only_admin' => true],
        ],
    ],
];
?>
<div id="miniSidebar">
    <div class="admin-sidebar-shell">
        <div class="brand-logo admin-brand-shell">
            <a href="index.php" class="admin-brand-link" aria-label="Retour au tableau de bord admin">
                <img src="../assets/images/logo-emsp.png" alt="Logo EMSP" class="admin-brand-logo">
                <span class="admin-brand-copy">
                    <span class="fw-bold fs-5 site-logo-text">EMSP Admin</span>
                    <small class="admin-brand-subtitle">Pilotage institutionnel</small>
                </span>
            </a>
        </div>

        <div class="px-3 pb-2">
            <div class="admin-user-card">
                <?php if ($photo_src): ?>
                    <img src="<?= h($photo_src) ?>" alt="Avatar" class="admin-user-avatar">
                <?php else: ?>
                    <div class="admin-user-fallback"><?= h($initials) ?></div>
                <?php endif; ?>
                <div class="admin-user-meta">
                    <div class="admin-user-name"><?= h(trim((string) (($auth_user['first_name'] ?? '') . ' ' . ($auth_user['last_name'] ?? '')))) ?></div>
                    <div class="text-muted"><?= h($role_label) ?></div>
                </div>
                <span class="admin-role-pill"><?= $is_admin ? 'ADMIN' : 'MOD' ?></span>
            </div>
        </div>

        <div class="px-3 pb-3">
            <label class="admin-sidebar-search" for="admin-nav-search">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input id="admin-nav-search" type="search" placeholder="Rechercher un menu..." aria-label="Rechercher dans le menu admin" autocomplete="off">
            </label>
        </div>

        <nav class="admin-sidebar-nav" aria-label="Navigation administration">
            <?php foreach ($nav_sections as $sectionIndex => $section): ?>
                <?php
                $sectionId = 'admin-nav-section-' . $sectionIndex;
                $groupActive = false;
                foreach ($section['items'] as $item) {
                    if (!empty($item['only_admin']) && !$is_admin) {
                        continue;
                    }
                    if (($item['href'] ?? '') === $current) {
                        $groupActive = true;
                        break;
                    }
                }
                ?>
                <section class="admin-nav-section<?= $groupActive ? ' is-open' : '' ?>" data-admin-nav-group>
                    <button
                        type="button"
                        class="admin-nav-section-toggle"
                        data-admin-nav-toggle
                        aria-expanded="<?= $groupActive ? 'true' : 'false' ?>"
                        aria-controls="<?= h($sectionId) ?>"
                    >
                        <span class="nav-heading"><?= h((string) $section['label']) ?></span>
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                    </button>
                    <div class="admin-nav-section-body" id="<?= h($sectionId) ?>">
                        <?php foreach ($section['items'] as $item): ?>
                            <?php
                            if (!empty($item['only_admin']) && !$is_admin) {
                                continue;
                            }
                            $isActiveItem = ($item['href'] ?? '') === $current;
                            $itemBadge = max(0, (int) ($item['badge'] ?? 0));
                            ?>
                            <a
                                class="nav-link<?= $isActiveItem ? ' active' : '' ?>"
                                href="<?= h((string) ($item['href'] ?? '#')) ?>"
                                data-admin-nav-item
                                data-admin-nav-label="<?= h(strtolower((string) ($item['label'] ?? ''))) ?>"
                            >
                                <i class="bi bi-<?= h((string) ($item['icon'] ?? 'dot')) ?> icon-size" aria-hidden="true"></i>
                                <span class="text"><?= h((string) ($item['label'] ?? '')) ?></span>
                                <?php if ($itemBadge > 0): ?>
                                    <span class="emsp-admin-count ms-auto"><?= $itemBadge > 99 ? '99+' : $itemBadge ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </nav>

        <div class="admin-sidebar-footer px-3 pb-3">
            <a class="nav-link" href="../logout.php">
                <i class="bi bi-box-arrow-right icon-size" aria-hidden="true"></i>
                <span class="text">Deconnexion</span>
            </a>
        </div>
    </div>
</div>


