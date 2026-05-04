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
<header class="header header-sticky p-0 mb-4">
  <div class="container-fluid border-bottom px-4">
    <button class="header-toggler" type="button" onclick="coreui.Sidebar.getInstance(document.querySelector('#sidebar')).toggle()" style="margin-inline-start: -14px">
      <i class="bi bi-list fs-3"></i>
    </button>
    
    <div class="d-none d-md-flex ms-3">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb my-0">
                <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none">Admin</a></li>
                <li class="breadcrumb-item active"><span><?= h($page_title ?: 'Dashboard') ?></span></li>
            </ol>
        </nav>
    </div>

    <ul class="header-nav ms-auto gap-2">
      <li class="nav-item">
        <a class="nav-link position-relative" href="pending-documents.php" title="Documents en attente">
          <i class="bi bi-file-earmark-check fs-5"></i>
          <?php if ($pending_docs > 0): ?>
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.6rem;"><?= $pending_docs ?></span>
          <?php endif; ?>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link position-relative" href="pending-users.php" title="Comptes en attente">
          <i class="bi bi-person-check fs-5"></i>
          <?php if ($pending_users > 0): ?>
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-warning text-dark" style="font-size: 0.6rem;"><?= $pending_users ?></span>
          <?php endif; ?>
        </a>
      </li>
    </ul>

    <ul class="header-nav ms-3">
      <li class="nav-item dropdown">
        <a class="nav-link py-0 pe-0 d-flex align-items-center gap-2" data-coreui-toggle="dropdown" href="#" role="button" aria-haspopup="true" aria-expanded="false">
          <div class="admin-avatar shadow-sm" style="width: 36px; height: 36px;">
            <?php if ($photo_src): ?>
              <img src="<?= h($photo_src) ?>" class="rounded-circle w-100 h-100 object-fit-cover" alt="User">
            <?php else: ?>
              <span style="font-size: 0.8rem;"><?= h($initials) ?></span>
            <?php endif; ?>
          </div>
          <span class="d-none d-lg-block fw-semibold small"><?= h($auth_user['first_name'] ?? 'Admin') ?></span>
        </a>
        <div class="dropdown-menu dropdown-menu-end pt-0 shadow-lg border-0" style="min-width: 200px; border-radius: 1rem;">
          <div class="dropdown-header bg-light fw-bold rounded-top mb-2">Compte</div>
          <a class="dropdown-item py-2" href="../mon-profil.php"><i class="bi bi-person me-2"></i> Mon profil</a>
          <a class="dropdown-item py-2" href="../dashboard.php"><i class="bi bi-grid me-2"></i> Espace étudiant</a>
          <?php if ($is_admin): ?>
            <a class="dropdown-item py-2" href="settings.php"><i class="bi bi-gear me-2"></i> Paramètres</a>
          <?php endif; ?>
          <div class="dropdown-divider"></div>
          <a class="dropdown-item py-2 text-danger" href="../logout.php"><i class="bi bi-box-arrow-right me-2"></i> Déconnexion</a>
        </div>
      </li>
    </ul>
  </div>
</header>

<div class="body flex-grow-1">
  <div class="container-lg px-4">


