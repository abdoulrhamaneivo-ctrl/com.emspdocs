<?php
ob_start();
include_once __DIR__ . '/bootstrap.php';
include_once __DIR__ . '/config/dbcon.php';
include_once __DIR__ . '/authentication.php';
include_once __DIR__ . '/../includes/csrf.php';
include_once __DIR__ . '/../includes/formations-helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    verify_csrf_token();
    $id = intval($_POST['id'] ?? 0);
    if ($id > 0) {
        $s = mysqli_prepare($con, "UPDATE filieres SET status = IF(status='active','inactive','active') WHERE id=?");
        mysqli_stmt_bind_param($s, 'i', $id);
        mysqli_stmt_execute($s);
        mysqli_stmt_close($s);
    }
    $_SESSION['message'] = 'Statut mis a jour.';
    header('Location: view-filieres.php');
    exit;
}

$editorialEnabled = emsp_formations_editorial_columns_present($con);
$itemsResult = mysqli_query(
    $con,
    $editorialEnabled
        ? "SELECT id, name, status, summary, description_html, cover_image_path FROM filieres ORDER BY name"
        : "SELECT id, name, status FROM filieres ORDER BY name"
);
$items = [];
if ($itemsResult) {
    while ($row = mysqli_fetch_assoc($itemsResult)) {
        $items[] = $row;
    }
}

$page_title = 'Filieres';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
include __DIR__ . '/includes/navbar-top.php';
?>
<div id="admin-content">
<div id="main-content" class="container-fluid">

<?php if (!empty($_SESSION['message'])): ?>
    <div class="alert alert-info alert-dismissible fade show">
        <?= htmlspecialchars($_SESSION['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['message']); ?>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold"><i class="bi bi-diagram-3 me-2 text-primary"></i>Filieres</h5>
    <a href="manage-filiere.php" class="btn-sb-primary"><i class="bi bi-plus-lg me-1"></i>Ajouter</a>
</div>

<div class="sb-card">
    <div class="sb-card-body p-0 d-none d-md-block">
        <table class="sb-table">
            <thead>
                <tr>
                    <th class="ps-3">#</th>
                    <th>Nom</th>
                    <?php if ($editorialEnabled): ?><th>Visuel</th><?php endif; ?>
                    <?php if ($editorialEnabled): ?><th>Presentation</th><?php endif; ?>
                    <th>Statut</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($items)): ?>
                <tr><td colspan="<?= $editorialEnabled ? '6' : '4' ?>" class="text-center text-muted py-4">Aucune filiere enregistree.</td></tr>
            <?php else: foreach ($items as $i): ?>
                <tr>
                    <td class="ps-3 text-muted small"><?= (int) ($i['id'] ?? 0) ?></td>
                    <td class="fw-medium"><?= htmlspecialchars((string) ($i['name'] ?? '')) ?></td>
                    <?php if ($editorialEnabled): ?>
                        <td>
                            <?php if (!empty($i['cover_image_path'])): ?>
                                <span class="sb-badge sb-badge-green">Ajoute</span>
                            <?php else: ?>
                                <span class="sb-badge sb-badge-red">Manquant</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small"><?= htmlspecialchars(emsp_formation_summary($i, 90)) ?></td>
                    <?php endif; ?>
                    <td>
                        <span class="sb-badge <?= (($i['status'] ?? '') === 'active') ? 'sb-badge-green' : 'sb-badge-red' ?>">
                            <?= (($i['status'] ?? '') === 'active') ? 'Actif' : 'Inactif' ?>
                        </span>
                    </td>
                    <td class="text-center">
                        <div class="d-flex gap-1 justify-content-center">
                            <a href="manage-filiere.php?id=<?= (int) ($i['id'] ?? 0) ?>" class="btn-sb-icon" title="Modifier"><i class="bi bi-pencil"></i></a>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int) ($i['id'] ?? 0) ?>">
                                <button type="submit" class="btn-sb-icon" title="Activer ou desactiver" data-emsp-confirm-auto="1" data-confirm="Changer le statut de la filiere ?" data-confirm-detail="La filiere restera disponible en administration avec son nouvel etat." data-confirm-type="warning" data-confirm-ok="Oui, continuer">
                                    <i class="bi <?= (($i['status'] ?? '') === 'active') ? 'bi-toggle-on text-warning' : 'bi-toggle-off text-success' ?>"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <div class="sb-card-body d-md-none">
        <?php if (empty($items)): ?>
            <div class="emsp-admin-mobile-empty">Aucune filiere enregistree.</div>
        <?php else: ?>
            <div class="emsp-admin-mobile-list">
                <?php foreach ($items as $i): ?>
                    <div class="emsp-admin-mobile-card">
                        <div class="emsp-admin-mobile-card-header">
                            <div>
                                <h3 class="emsp-admin-mobile-card-title mb-0"><?= htmlspecialchars((string) ($i['name'] ?? '')) ?></h3>
                                <div class="emsp-admin-mobile-card-subtitle">Filiere #<?= (int) ($i['id'] ?? 0) ?></div>
                            </div>
                            <span class="sb-badge <?= (($i['status'] ?? '') === 'active') ? 'sb-badge-green' : 'sb-badge-red' ?>">
                                <?= (($i['status'] ?? '') === 'active') ? 'Actif' : 'Inactif' ?>
                            </span>
                        </div>
                        <div class="emsp-admin-mobile-meta">
                            <?php if ($editorialEnabled): ?>
                                <div class="emsp-admin-mobile-meta-item">
                                    <span class="emsp-admin-mobile-meta-label">Visuel</span>
                                    <span class="emsp-admin-mobile-meta-value"><?= !empty($i['cover_image_path']) ? 'Ajoute' : 'Manquant' ?></span>
                                </div>
                                <div class="emsp-admin-mobile-meta-item">
                                    <span class="emsp-admin-mobile-meta-label">Presentation</span>
                                    <span class="emsp-admin-mobile-meta-value"><?= htmlspecialchars(emsp_formation_summary($i, 120)) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="emsp-admin-mobile-actions">
                            <a href="manage-filiere.php?id=<?= (int) ($i['id'] ?? 0) ?>" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-pencil me-1"></i>Modifier
                            </a>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int) ($i['id'] ?? 0) ?>">
                                <button type="submit" class="btn btn-sm btn-outline-warning" data-emsp-confirm-auto="1" data-confirm="Changer le statut de la filiere ?" data-confirm-detail="La filiere restera disponible en administration avec son nouvel etat." data-confirm-type="warning" data-confirm-ok="Oui, continuer">
                                    <i class="bi <?= (($i['status'] ?? '') === 'active') ? 'bi-toggle-on' : 'bi-toggle-off' ?> me-1"></i>Basculer
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
</div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>


