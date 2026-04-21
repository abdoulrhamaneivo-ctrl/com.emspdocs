<?php
include_once __DIR__ . '/../bootstrap.php';
include_once __DIR__ . '/../../includes/helpers.php';

if (empty($_SESSION['auth']) || !in_array($_SESSION['auth_role'] ?? '', ['admin', 'moderateur'], true)) {
    header('Location: ../index.php?open_login=1');
    exit;
}

$auth_user = $_SESSION['auth_user'] ?? [];
$is_admin = strtolower((string) ($_SESSION['auth_role'] ?? '')) === 'admin';
$page_title = $page_title ?? '';
$pending_docs = $pending_users = 0;
if (isset($con)) {
    if ($s = mysqli_prepare($con, "SELECT COUNT(*) FROM documents WHERE status='pending'")) {
        mysqli_stmt_execute($s);
        mysqli_stmt_bind_result($s, $pending_docs);
        mysqli_stmt_fetch($s);
        mysqli_stmt_close($s);
    }
    if ($s = mysqli_prepare($con, "SELECT COUNT(*) FROM users WHERE status='pending'")) {
        mysqli_stmt_execute($s);
        mysqli_stmt_bind_result($s, $pending_users);
        mysqli_stmt_fetch($s);
        mysqli_stmt_close($s);
    }
}

$initials = strtoupper(mb_substr($auth_user['first_name'] ?? 'A', 0, 1) . mb_substr($auth_user['last_name'] ?? '', 0, 1));
$photo_src = '';
if (!empty($auth_user['photo_path'])) {
    $photo_src = emsp_user_photo_src((string) $auth_user['photo_path']);
    if ($photo_src !== '' && !preg_match('#^https?://#i', $photo_src)) {
        $photo_src = '../' . ltrim($photo_src, '/');
    }
}
?>
<nav class="navbar navbar-glass px-0 px-lg-4" id="admin-topbar">
    <div class="container-fluid px-lg-0">
        <div class="d-flex align-items-center gap-3">
            <button class="btn admin-topbar-btn d-lg-none" id="sidebar-toggle" type="button" aria-label="Ouvrir le menu admin" aria-controls="miniSidebar" aria-expanded="false">
                <i class="bi bi-list"></i>
            </button>
            <button class="sidebar-toggle d-none d-lg-flex align-items-center gap-2 p-2 admin-topbar-btn" id="sidebar-collapse-toggle" type="button" aria-label="Réduire ou étendre la barre latérale">
                <span class="collapse-mini"><i class="bi bi-arrow-left"></i></span>
                <span class="collapse-expanded"><i class="bi bi-arrow-right"></i></span>
            </button>
            <div class="admin-topbar-copy">
                <div class="small text-uppercase admin-topbar-kicker">Administration EMSP</div>
                <div class="fw-semibold text-dark"><?= htmlspecialchars($page_title ?: 'Tableau de bord') ?></div>
                <div class="admin-topbar-subtext"><?= $is_admin ? 'Vue administrateur' : 'Vue moderateur' ?></div>
            </div>
        </div>

        <ul class="list-unstyled d-flex align-items-center mb-0 gap-2 admin-topbar-actions">
            <li><a class="btn admin-topbar-btn" href="../index.php" title="Aller sur le site"><i class="bi bi-globe2"></i></a></li>
            <li>
                <a class="btn admin-topbar-btn position-relative" href="pending-documents.php" title="Documents en attente">
                    <i class="bi bi-file-earmark-check"></i>
                    <?php if ($pending_docs > 0): ?><span class="position-absolute top-0 start-100 translate-middle badge rounded-pill emsp-admin-pill-count"><?= $pending_docs ?></span><?php endif; ?>
                </a>
            </li>
            <li>
                <a class="btn admin-topbar-btn position-relative" href="pending-users.php" title="Comptes en attente">
                    <i class="bi bi-person-check"></i>
                    <?php if ($pending_users > 0): ?><span class="position-absolute top-0 start-100 translate-middle badge rounded-pill emsp-admin-pill-count"><?= $pending_users ?></span><?php endif; ?>
                </a>
            </li>
            <li class="dropdown">
                <button class="btn admin-avatar-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <?php if ($photo_src): ?>
                        <img src="<?= htmlspecialchars($photo_src) ?>" alt="Avatar" class="admin-avatar-img">
                    <?php else: ?>
                        <span class="admin-avatar-fallback"><?= $initials ?></span>
                    <?php endif; ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="../mon-profil.php"><i class="bi bi-person me-2"></i>Mon profil</a></li>
                    <li><a class="dropdown-item" href="../dashboard.php"><i class="bi bi-grid me-2"></i>Espace étudiant</a></li>
                    <?php if ($is_admin): ?><li><a class="dropdown-item" href="settings.php"><i class="bi bi-gear me-2"></i>Paramètres</a></li><?php endif; ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="../logout.php"><i class="bi bi-box-arrow-right me-2"></i>Déconnexion</a></li>
                </ul>
            </li>
        </ul>
    </div>
</nav>


