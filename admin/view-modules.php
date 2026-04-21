<?php
ob_start();
include_once __DIR__ . '/bootstrap.php';
include_once __DIR__ . '/config/dbcon.php';
include_once __DIR__ . '/authentication.php';
include_once __DIR__ . '/../includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    verify_csrf_token();
    $id = intval($_POST['id']);
    if ($id > 0) {
        $s = mysqli_prepare($con, "UPDATE modules SET status = IF(status='active','inactive','active') WHERE id=?");
        mysqli_stmt_bind_param($s, 'i', $id);
        mysqli_stmt_execute($s);
        mysqli_stmt_close($s);
    }
    $_SESSION['message'] = 'Statut mis a jour.';
    header('Location: view-modules.php');
    exit;
}

$itemsResult = mysqli_query($con, "SELECT m.id, m.name, m.status, l.name AS licence_name FROM modules m LEFT JOIN licences l ON l.id = m.licence_id ORDER BY m.name");
$items = [];
if ($itemsResult) {
    while ($row = mysqli_fetch_assoc($itemsResult)) {
        $items[] = $row;
    }
}

$page_title = 'Modules';
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
    <h5 class="mb-0 fw-bold"><i class="bi bi-grid me-2 text-primary"></i>Modules</h5>
    <a href="add-module.php" class="btn-sb-primary"><i class="bi bi-plus-lg me-1"></i>Ajouter</a>
</div>

<div class="sb-card">
    <div class="sb-card-body p-0 d-none d-md-block">
        <table class="sb-table">
            <thead>
                <tr>
                    <th class="ps-3">#</th>
                    <th>Nom</th>
                    <th>Niveau</th>
                    <th>Statut</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($items)): ?>
                <tr><td colspan="5" class="text-center text-muted py-4">Aucun module enregistre.</td></tr>
            <?php else: foreach ($items as $i): ?>
                <tr>
                    <td class="ps-3 text-muted small"><?= (int) $i['id'] ?></td>
                    <td class="fw-medium"><?= htmlspecialchars($i['name']) ?></td>
                    <td class="text-muted small"><?= htmlspecialchars($i['licence_name'] ?? 'Non renseigne') ?></td>
                    <td><span class="sb-badge <?= $i['status']==='active' ? 'sb-badge-green' : 'sb-badge-red' ?>">
                        <?= $i['status']==='active' ? 'Actif' : 'Inactif' ?></span></td>
                    <td class="text-center">
                        <div class="d-flex gap-1 justify-content-center">
                            <a href="add-module.php?id=<?= (int) $i['id'] ?>" class="btn-sb-icon" title="Modifier"><i class="bi bi-pencil"></i></a>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int) $i['id'] ?>">
                                <button type="submit" class="btn-sb-icon" title="Activer/Desactiver" data-emsp-confirm-auto="1" data-confirm="Changer le statut du module ?" data-confirm-detail="Le module restera disponible en administration avec son nouvel etat." data-confirm-type="warning" data-confirm-ok="Oui, continuer">
                                    <i class="bi <?= $i['status']==='active' ? 'bi-toggle-on text-warning' : 'bi-toggle-off text-success' ?>"></i>
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
            <div class="emsp-admin-mobile-empty">Aucun module enregistre.</div>
        <?php else: ?>
            <div class="emsp-admin-mobile-list">
                <?php foreach ($items as $i): ?>
                    <div class="emsp-admin-mobile-card">
                        <div class="emsp-admin-mobile-card-header">
                            <div>
                                <h3 class="emsp-admin-mobile-card-title mb-0"><?= htmlspecialchars($i['name']) ?></h3>
                                <div class="emsp-admin-mobile-card-subtitle">Module #<?= (int) $i['id'] ?></div>
                            </div>
                            <span class="sb-badge <?= $i['status']==='active' ? 'sb-badge-green' : 'sb-badge-red' ?>">
                                <?= $i['status']==='active' ? 'Actif' : 'Inactif' ?>
                            </span>
                        </div>
                        <div class="emsp-admin-mobile-meta">
                            <div class="emsp-admin-mobile-meta-item">
                                <span class="emsp-admin-mobile-meta-label">Niveau</span>
                                <span class="emsp-admin-mobile-meta-value"><?= htmlspecialchars($i['licence_name'] ?? 'Non renseigne') ?></span>
                            </div>
                        </div>
                        <div class="emsp-admin-mobile-actions">
                            <a href="add-module.php?id=<?= (int) $i['id'] ?>" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-pencil me-1"></i>Modifier
                            </a>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= (int) $i['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-warning" data-emsp-confirm-auto="1" data-confirm="Changer le statut du module ?" data-confirm-detail="Le module restera disponible en administration avec son nouvel etat." data-confirm-type="warning" data-confirm-ok="Oui, continuer">
                                    <i class="bi <?= $i['status']==='active' ? 'bi-toggle-on' : 'bi-toggle-off' ?> me-1"></i>Basculer
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


