<?php
ob_start();
include_once __DIR__ . '/bootstrap.php';
if (empty($_SESSION['auth']) || !in_array($_SESSION['auth_role'] ?? '', ['admin','moderateur'], true)) {
    header('Location: ../index.php?open_login=1'); exit;
}

include_once __DIR__ . '/config/dbcon.php';
include_once __DIR__ . '/authentication.php';
include_once __DIR__ . '/../includes/csrf.php';
include_once __DIR__ . '/../includes/flash.php';

if (!emsp_can_assign_user_roles($auth_user ?? [])) {
    flash_set('warning', 'Acces reserve', 'Seul un administrateur peut gerer les domaines email.');
    header('Location: index.php');
    exit(0);
}

// Ensure table exists (safe on shared hosting)
mysqli_query($con, "CREATE TABLE IF NOT EXISTS school_email_domains (
  id INT AUTO_INCREMENT PRIMARY KEY,
  domain VARCHAR(100) NOT NULL UNIQUE,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

function emsp_normalize_domain(string $input): string
{
    $d = strtolower(trim($input));
    if ($d === '') { return ''; }
    // keep only part after @ if user typed a full email
    $pos = strpos($d, '@');
    if ($pos !== false) {
        $d = substr($d, $pos);
    } else {
        $d = '@' . $d;
    }
    $d = preg_replace('/\s+/', '', $d);
    return $d;
}

function emsp_valid_domain(string $domain): bool
{
    return (bool) preg_match('/^@[a-z0-9.-]+\.[a-z]{2,}$/', $domain);
}

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf_token();
    $action = $_POST['action'];

    if ($action === 'add') {
        $domain = emsp_normalize_domain($_POST['domain'] ?? '');
        if ($domain === '' || !emsp_valid_domain($domain)) {
            flash_set('warning', 'Domaine invalide', 'Exemple attendu : @emsp.int');
            header('Location: school-domains.php'); exit;
        }
        $s = mysqli_prepare($con, "INSERT INTO school_email_domains (domain, status) VALUES (?, 'active')");
        if ($s) {
            mysqli_stmt_bind_param($s, 's', $domain);
            mysqli_stmt_execute($s);
            mysqli_stmt_close($s);
        }
        flash_set('success', 'Domaine ajoute', 'Le domaine est maintenant autorise.');
        header('Location: school-domains.php'); exit;
    }

    if ($action === 'update' && isset($_POST['id'])) {
        $id = intval($_POST['id']);
        $domain = emsp_normalize_domain($_POST['domain'] ?? '');
        if ($id <= 0 || $domain === '' || !emsp_valid_domain($domain)) {
            flash_set('warning', 'Domaine invalide', 'Exemple attendu : @emsp.int');
            header('Location: school-domains.php'); exit;
        }
        $s = mysqli_prepare($con, "UPDATE school_email_domains SET domain=? WHERE id=?");
        if ($s) {
            mysqli_stmt_bind_param($s, 'si', $domain, $id);
            mysqli_stmt_execute($s);
            mysqli_stmt_close($s);
        }
        flash_set('success', 'Domaine modifie', 'Le domaine a ete mis a jour.');
        header('Location: school-domains.php'); exit;
    }

    if ($action === 'toggle' && isset($_POST['id'])) {
        $id = intval($_POST['id']);
        if ($id > 0) {
            $s = mysqli_prepare($con, "UPDATE school_email_domains SET status = IF(status='active','inactive','active') WHERE id=?");
            if ($s) {
                mysqli_stmt_bind_param($s, 'i', $id);
                mysqli_stmt_execute($s);
                mysqli_stmt_close($s);
            }
        }
        header('Location: school-domains.php'); exit;
    }

    if ($action === 'delete' && isset($_POST['id'])) {
        $id = intval($_POST['id']);
        if ($id > 0) {
            $s = mysqli_prepare($con, "DELETE FROM school_email_domains WHERE id=? LIMIT 1");
            if ($s) {
                mysqli_stmt_bind_param($s, 'i', $id);
                mysqli_stmt_execute($s);
                mysqli_stmt_close($s);
            }
        }
        flash_set('info', 'Domaine supprime', 'Le domaine a ete retire.');
        header('Location: school-domains.php'); exit;
    }
}

$domains = [];
$r = mysqli_query($con, "SELECT * FROM school_email_domains ORDER BY status DESC, domain ASC");
while ($row = mysqli_fetch_assoc($r)) { $domains[] = $row; }

$page_title = 'Domaines email ecole';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
include __DIR__ . '/includes/navbar-top.php';
?>

<div id="admin-content">
    <div id="main-content" class="container-fluid">

<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="mb-0 fw-bold"><i class="bi bi-envelope-at me-2 text-primary"></i> Domaines email ecole</h5>
</div>

<div class="card shadow-sm mb-4">
  <div class="card-body">
    <form method="post" class="row g-2 align-items-end">
      <?php csrf_input(); ?>
      <input type="hidden" name="action" value="add">
      <div class="col-md-6">
        <label class="form-label">Nouveau domaine</label>
        <input type="text" name="domain" class="form-control" placeholder="@emsp.int" required>
        <div class="form-text">Exemple : @emsp.int, @emsp.edu</div>
      </div>
      <div class="col-md-3">
        <button class="btn btn-primary w-100" type="submit">
          <i class="bi bi-plus-lg me-1"></i>Ajouter
        </button>
      </div>
    </form>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-body p-0 d-none d-md-block">
    <div class="table-responsive">
      <table class="table mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>Domaine</th>
            <th>Statut</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($domains)): ?>
          <tr><td colspan="3" class="text-center text-muted py-4">Aucun domaine configure.</td></tr>
        <?php else: ?>
          <?php foreach ($domains as $d): ?>
            <tr>
              <td>
                <form method="post" class="d-flex gap-2 align-items-center">
                  <?php csrf_input(); ?>
                  <input type="hidden" name="action" value="update">
                  <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                  <input type="text" name="domain" class="form-control form-control-sm" value="<?= htmlspecialchars($d['domain']) ?>" required>
                  <button class="btn btn-sm btn-outline-primary" type="submit">Enregistrer</button>
                </form>
              </td>
              <td>
                <?php if (($d['status'] ?? '') === 'active'): ?>
                  <span class="badge bg-success">Actif</span>
                <?php else: ?>
                  <span class="badge bg-secondary">Inactif</span>
                <?php endif; ?>
              </td>
              <td class="d-flex gap-2">
                <form method="post" class="m-0">
                  <?php csrf_input(); ?>
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                  <button class="btn btn-sm btn-outline-warning" type="submit">Basculer</button>
                </form>
                <form method="post" class="m-0">
                  <?php csrf_input(); ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" data-confirm="Supprimer ce domaine ?" data-confirm-detail="Cette action est irreversible." data-confirm-type="danger" data-confirm-ok="Oui, supprimer" data-emsp-confirm-auto="1">Supprimer</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card-body d-md-none">
    <?php if (empty($domains)): ?>
      <div class="emsp-admin-mobile-empty">Aucun domaine configure.</div>
    <?php else: ?>
      <div class="emsp-admin-mobile-list">
        <?php foreach ($domains as $d): ?>
          <div class="emsp-admin-mobile-card">
            <div class="emsp-admin-mobile-card-header">
              <div>
                <h3 class="emsp-admin-mobile-card-title mb-0"><?= htmlspecialchars($d['domain']) ?></h3>
                <div class="emsp-admin-mobile-card-subtitle">Domaine autorise a l inscription</div>
              </div>
              <?php if (($d['status'] ?? '') === 'active'): ?>
                <span class="badge bg-success">Actif</span>
              <?php else: ?>
                <span class="badge bg-secondary">Inactif</span>
              <?php endif; ?>
            </div>
            <form method="post" class="emsp-admin-mobile-inline-form mb-2">
              <?php csrf_input(); ?>
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
              <input type="text" name="domain" class="form-control form-control-sm" value="<?= htmlspecialchars($d['domain']) ?>" required>
              <button class="btn btn-sm btn-outline-primary" type="submit">
                <i class="bi bi-check2 me-1"></i>Enregistrer
              </button>
            </form>
            <div class="emsp-admin-mobile-actions">
              <form method="post" class="d-inline">
                <?php csrf_input(); ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <button class="btn btn-sm btn-outline-warning" type="submit">
                  <i class="bi bi-arrow-repeat me-1"></i>Basculer
                </button>
              </form>
              <form method="post" class="d-inline">
                <?php csrf_input(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" data-confirm="Supprimer ce domaine ?" data-confirm-detail="Cette action est irreversible." data-confirm-type="danger" data-confirm-ok="Oui, supprimer" data-emsp-confirm-auto="1">
                  <i class="bi bi-trash me-1"></i>Supprimer
                </button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>


</div><!-- /#main-content -->
</div><!-- /#admin-content -->
<?php include __DIR__ . '/includes/footer.php'; ?>


