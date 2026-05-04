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
  <div class="container-fluid border-bottom px-4 d-flex align-items-center">
    <button class="header-toggler" type="button"
            onclick="coreui.Sidebar.getInstance(document.querySelector('#sidebar')).toggle()"
            style="margin-inline-start:-14px;" aria-label="Ouvrir le menu">
      <i class="bi bi-list fs-3"></i>
    </button>

    <nav class="d-none d-md-flex ms-3" aria-label="breadcrumb">
      <ol class="breadcrumb my-0">
        <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none text-body-secondary">Admin</a></li>
        <li class="breadcrumb-item active" aria-current="page"><?= h($page_title ?: 'Dashboard') ?></li>
      </ol>
    </nav>

    <ul class="header-nav ms-auto gap-1 align-items-center">
      <li class="nav-item d-none d-md-block">
        <a class="nav-link position-relative" href="pending-documents.php" title="Documents en attente">
          <i class="bi bi-file-earmark-check fs-5"></i>
          <?php if ($pending_docs > 0): ?>
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:.6rem;"><?= $pending_docs ?></span>
          <?php endif; ?>
        </a>
      </li>
      <li class="nav-item d-none d-md-block">
        <a class="nav-link position-relative" href="pending-users.php" title="Comptes en attente">
          <i class="bi bi-person-check fs-5"></i>
          <?php if ($pending_users > 0): ?>
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-warning text-dark" style="font-size:.6rem;"><?= $pending_users ?></span>
          <?php endif; ?>
        </a>
      </li>
    </ul>

    <ul class="header-nav">
      <li class="nav-item py-1 d-none d-md-flex align-items-center">
        <div class="vr h-75 mx-2 text-body text-opacity-25"></div>
      </li>
      <li class="nav-item dropdown">
        <button class="btn btn-link nav-link py-2 px-2 d-flex align-items-center" type="button"
                aria-label="Theme" data-coreui-toggle="dropdown" aria-expanded="false">
          <i class="bi bi-circle-half fs-5"></i>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="--cui-dropdown-min-width: 9rem;">
          <li>
            <button type="button" class="dropdown-item d-flex align-items-center gap-2" data-emsp-theme-value="light">
              <i class="bi bi-sun-fill"></i> Clair
            </button>
          </li>
          <li>
            <button type="button" class="dropdown-item d-flex align-items-center gap-2" data-emsp-theme-value="dark">
              <i class="bi bi-moon-stars-fill"></i> Sombre
            </button>
          </li>
          <li>
            <button type="button" class="dropdown-item d-flex align-items-center gap-2 active" data-emsp-theme-value="auto">
              <i class="bi bi-circle-half"></i> Auto
            </button>
          </li>
        </ul>
      </li>
      <li class="nav-item py-1 d-flex align-items-center">
        <div class="vr h-75 mx-2 text-body text-opacity-25"></div>
      </li>
    </ul>

    <ul class="header-nav">
      <li class="nav-item dropdown">
        <a class="nav-link py-0 pe-0 d-flex align-items-center gap-2" data-coreui-toggle="dropdown" href="#"
           role="button" aria-haspopup="true" aria-expanded="false">
          <div class="avatar avatar-md">
            <?php if ($photo_src): ?>
              <img src="<?= h($photo_src) ?>" class="avatar-img rounded-circle" alt="">
            <?php else: ?>
              <span class="admin-avatar-circle"><?= h($initials) ?></span>
            <?php endif; ?>
          </div>
          <span class="d-none d-lg-block fw-semibold small text-body"><?= h($auth_user['first_name'] ?? 'Admin') ?></span>
        </a>
        <div class="dropdown-menu dropdown-menu-end pt-0 shadow-lg border-0" style="min-width:220px;border-radius:.75rem;">
          <div class="dropdown-header bg-body-tertiary text-body-secondary fw-semibold rounded-top mb-2">
            Compte
          </div>
          <a class="dropdown-item py-2" href="../mon-profil.php"><i class="bi bi-person me-2"></i> Mon profil</a>
          <a class="dropdown-item py-2" href="../dashboard.php"><i class="bi bi-grid me-2"></i> Espace etudiant</a>
          <?php if ($is_admin): ?>
            <a class="dropdown-item py-2" href="settings.php"><i class="bi bi-gear me-2"></i> Parametres</a>
          <?php endif; ?>
          <div class="dropdown-divider"></div>
          <a class="dropdown-item py-2 text-danger" href="../logout.php">
            <i class="bi bi-box-arrow-right me-2"></i> Deconnexion
          </a>
        </div>
      </li>
    </ul>
  </div>
</header>

<div class="body flex-grow-1">
  <div class="container-lg px-4">

<script>
(function () {
    var STORAGE_KEY = 'coreui-free-theme';
    var html = document.documentElement;

    function applyTheme(theme) {
        var resolved = theme;
        if (theme === 'auto') {
            resolved = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        html.dataset.coreuiTheme = resolved;
        document.querySelectorAll('[data-emsp-theme-value]').forEach(function (el) {
            el.classList.toggle('active', el.getAttribute('data-emsp-theme-value') === theme);
        });
    }

    var initial = (function () {
        try { return localStorage.getItem(STORAGE_KEY) || 'auto'; } catch (e) { return 'auto'; }
    })();
    applyTheme(initial);

    document.querySelectorAll('[data-emsp-theme-value]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var v = btn.getAttribute('data-emsp-theme-value') || 'auto';
            try { localStorage.setItem(STORAGE_KEY, v); } catch (e) {}
            applyTheme(v);
        });
    });

    if (window.matchMedia) {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
            var stored = 'auto';
            try { stored = localStorage.getItem(STORAGE_KEY) || 'auto'; } catch (e) {}
            if (stored === 'auto') applyTheme('auto');
        });
    }
})();
</script>
