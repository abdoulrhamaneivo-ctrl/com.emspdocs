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
$page_title = 'CatÃ©gories mÃ©dias';
// Auto-create table if missing
$hasTable = false;
$chk = mysqli_query($con, "SHOW TABLES LIKE 'media_categories'");
if ($chk && mysqli_num_rows($chk) > 0) {
    $hasTable = true;
}
if (!$hasTable) {
    $createSql = "
        CREATE TABLE IF NOT EXISTS media_categories (
          id INT(11) NOT NULL AUTO_INCREMENT,
          name VARCHAR(100) NOT NULL,
          description VARCHAR(255) DEFAULT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (mysqli_query($con, $createSql)) {
        $hasTable = true;
    } else {
        flash_set('error', 'Table manquante', 'Impossible de crÃ©er la table media_categories. Importez la BDD.');
    }
}

// CRUD basique
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $action = $_POST['action'] ?? '';
    $name = trim((string)($_POST['name'] ?? ''));
    $desc = trim((string)($_POST['description'] ?? ''));

    if ($action === 'create' && $name !== '') {
        $s = mysqli_prepare($con, "INSERT INTO media_categories (name, description) VALUES (?, ?)");
        if ($s) {
            mysqli_stmt_bind_param($s, 'ss', $name, $desc);
            $ok = mysqli_stmt_execute($s);
            $err = mysqli_stmt_errno($s);
            mysqli_stmt_close($s);
            if ($ok) {
                flash_set('success', 'CatÃ©gorie ajoutÃ©e', 'La catÃ©gorie a Ã©tÃ© crÃ©Ã©e avec succÃ¨s.');
            } elseif ($err === 1062) {
                flash_set('warning', 'CatÃ©gorie dÃ©jÃ  existante', 'Une catÃ©gorie avec ce nom existe dÃ©jÃ .');
            } else {
                flash_set('error', 'Erreur ajout', 'Impossible d\'ajouter la catÃ©gorie. RÃ©essayez.');
            }
        }
    } elseif ($action === 'update') {
        $id = intval($_POST['id'] ?? 0);
        if ($id>0 && $name!=='') {
            $s = mysqli_prepare($con, "UPDATE media_categories SET name=?, description=? WHERE id=?");
            mysqli_stmt_bind_param($s, 'ssi', $name, $desc, $id);
            $ok = mysqli_stmt_execute($s);
            $err = mysqli_stmt_errno($s);
            mysqli_stmt_close($s);
            if ($ok) {
                flash_set('success', 'CatÃ©gorie mise Ã  jour', 'Les informations ont Ã©tÃ© mises Ã  jour.');
            } elseif ($err === 1062) {
                flash_set('warning', 'Nom dÃ©jÃ  utilisÃ©', 'Choisissez un autre nom pour cette catÃ©gorie.');
            } else {
                flash_set('error', 'Erreur mise Ã  jour', 'Impossible de mettre Ã  jour la catÃ©gorie.');
            }
        }
    } elseif ($action === 'delete') {
        $id = intval($_POST['id'] ?? 0);
        if ($id>0) {
            $s = mysqli_prepare($con, "UPDATE media SET category_id=NULL WHERE category_id=?");
            mysqli_stmt_bind_param($s, 'i', $id); mysqli_stmt_execute($s); mysqli_stmt_close($s);
            $s = mysqli_prepare($con, "DELETE FROM media_categories WHERE id=?");
            mysqli_stmt_bind_param($s, 'i', $id); mysqli_stmt_execute($s); mysqli_stmt_close($s);
            flash_set('info', 'CatÃ©gorie supprimÃ©e', 'La catÃ©gorie a Ã©tÃ© supprimÃ©e.');
        }
    }
    header('Location: media-categories.php'); exit;
}

$catsResult = mysqli_query($con, "SELECT * FROM media_categories ORDER BY created_at DESC");
$cats = [];
if ($catsResult) {
    while ($row = mysqli_fetch_assoc($catsResult)) {
        foreach (['name','description'] as $f) {
            if (isset($row[$f]) && is_string($row[$f])) {
                $row[$f] = emsp_fix_mojibake($row[$f]);
            }
        }
        $cats[] = $row;
    }
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
include __DIR__ . '/includes/navbar-top.php';
?>

<div id="admin-content">
  <div id="main-content" class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold"><i class="bi bi-collection me-2 text-primary"></i>CatÃ©gories mÃ©dias</h5>
    <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#formCat"><i class="bi bi-plus-lg me-1"></i>Ajouter</button>
  </div>

  <div id="formCat" class="collapse mb-3">
    <div class="card shadow-sm">
      <div class="card-body">
        <form method="post" class="row g-2">
          <?php csrf_input(); ?>
          <input type="hidden" name="action" value="create">
          <div class="col-md-4"><input name="name" class="form-control" placeholder="Nom de la catÃ©gorie" required></div>
          <div class="col-md-6"><input name="description" class="form-control" placeholder="Description (optionnel)"></div>
          <div class="col-md-2 d-grid"><button class="btn btn-success" type="submit">Enregistrer</button></div>
        </form>
      </div>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="table-responsive d-none d-md-block">
      <table class="table align-middle mb-0">
        <thead class="table-light">
          <tr><th>Nom</th><th>Description</th><th>CrÃ©Ã©e le</th><th class="text-end">Actions</th></tr>
        </thead>
        <tbody>
        <?php if (empty($cats)): ?>
          <tr><td colspan="4" class="text-center text-muted py-4">Aucune categorie enregistree.</td></tr>
        <?php else: foreach($cats as $c): ?>
          <tr>
            <td><?= h($c['name']); ?></td>
            <td class="text-muted small"><?= h($c['description']); ?></td>
            <td class="text-muted small"><?= h($c['created_at']); ?></td>
            <td class="text-end d-flex gap-2 justify-content-end">
              <form method="post" class="d-flex gap-2 align-items-center">
                <?php csrf_input(); ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int)$c['id']; ?>">
                <input name="name" class="form-control form-control-sm" value="<?= h($c['name']); ?>" required>
                <input name="description" class="form-control form-control-sm" value="<?= h($c['description']); ?>">
                <button class="btn btn-sm btn-outline-primary" type="submit">Mettre Ã  jour</button>
              </form>
              <form method="post">
                <?php csrf_input(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$c['id']; ?>">
                <button class="btn btn-sm btn-outline-danger" type="submit"
                        data-confirm="Supprimer cette catÃ©gorie ?"
                        data-confirm-detail="Les mÃ©dias associÃ©s seront dÃ©tachÃ©s de cette catÃ©gorie."
                        data-confirm-type="danger"
                        data-confirm-ok="Oui, supprimer"
                        data-emsp-confirm-auto="1">
                    <i class="bi bi-trash"></i>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <div class="card-body d-md-none">
      <?php if (empty($cats)): ?>
        <div class="emsp-admin-mobile-empty">Aucune categorie enregistree.</div>
      <?php else: ?>
        <div class="emsp-admin-mobile-list">
          <?php foreach ($cats as $c): ?>
            <div class="emsp-admin-mobile-card">
              <div class="emsp-admin-mobile-card-header">
                <div>
                  <h3 class="emsp-admin-mobile-card-title mb-0"><?= h($c['name']); ?></h3>
                  <div class="emsp-admin-mobile-card-subtitle">Creee le <?= h($c['created_at']); ?></div>
                </div>
              </div>
              <div class="emsp-admin-mobile-meta">
                <div class="emsp-admin-mobile-meta-item">
                  <span class="emsp-admin-mobile-meta-label">Description</span>
                  <span class="emsp-admin-mobile-meta-value"><?= $c['description'] !== '' ? h($c['description']) : 'Aucune description' ?></span>
                </div>
              </div>
              <form method="post" class="emsp-admin-mobile-inline-form mb-2">
                <?php csrf_input(); ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int)$c['id']; ?>">
                <input name="name" class="form-control form-control-sm" value="<?= h($c['name']); ?>" required>
                <input name="description" class="form-control form-control-sm" value="<?= h($c['description']); ?>" placeholder="Description (optionnel)">
                <button class="btn btn-sm btn-outline-primary" type="submit">
                  <i class="bi bi-check2 me-1"></i>Mettre a jour
                </button>
              </form>
              <div class="emsp-admin-mobile-actions">
                <form method="post" class="d-inline">
                  <?php csrf_input(); ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$c['id']; ?>">
                  <button class="btn btn-sm btn-outline-danger" type="submit"
                          data-confirm="Supprimer cette categorie ?"
                          data-confirm-detail="Les medias associes seront detaches de cette categorie."
                          data-confirm-type="danger"
                          data-confirm-ok="Oui, supprimer"
                          data-emsp-confirm-auto="1">
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


