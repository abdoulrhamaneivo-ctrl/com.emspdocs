<?php
ob_start();
include_once __DIR__ . '/bootstrap.php';
include_once __DIR__ . '/config/dbcon.php';
include_once __DIR__ . '/authentication.php';
include_once __DIR__ . '/../includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $name      = trim($_POST['name'] ?? '');
    $module_id = intval($_POST['module_id'] ?? 0) ?: null;
    $status    = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
    $id        = intval($_POST['id'] ?? 0);

    if ($name === '') {
        $_SESSION['message'] = 'Nom obligatoire.';
        header('Location: '.$_SERVER['PHP_SELF'].($id ? '?id='.$id : '')); exit;
    }

    if ($id > 0) {
        $s = mysqli_prepare($con,"UPDATE matieres SET name=?, module_id=?, status=? WHERE id=?");
        mysqli_stmt_bind_param($s,'sisi',$name,$module_id,$status,$id);
    } else {
        $s = mysqli_prepare($con,"INSERT INTO matieres (name, module_id, status) VALUES (?, ?, ?)");
        mysqli_stmt_bind_param($s,'sis',$name,$module_id,$status);
    }
    mysqli_stmt_execute($s); mysqli_stmt_close($s);
    $_SESSION['message'] = $id > 0 ? 'MatiÃ¨re modifiÃ©e.' : 'MatiÃ¨re ajoutÃ©e.';
    header('Location: view-matieres.php'); exit;
}

$id = intval($_GET['id'] ?? 0); $item = null;
if ($id > 0) {
    $s = mysqli_prepare($con,"SELECT * FROM matieres WHERE id=? LIMIT 1");
    mysqli_stmt_bind_param($s,'i',$id); mysqli_stmt_execute($s);
    $item = emsp_stmt_fetch_assoc($s);
    mysqli_stmt_close($s);
}
$modules = mysqli_query($con,"SELECT m.id, m.name, l.name AS licence_name FROM modules m LEFT JOIN licences l ON l.id=m.licence_id WHERE m.status='active' ORDER BY m.name");
$page_title = $id > 0 ? 'Modifier la matiÃ¨re' : 'Ajouter une matiÃ¨re';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
include __DIR__ . '/includes/navbar-top.php';
?>
<div id="admin-content">
<div id="main-content" class="container-fluid">
<?php if (!empty($_SESSION['message'])): ?><div class="alert alert-info alert-dismissible fade show"><?= htmlspecialchars($_SESSION['message']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php unset($_SESSION['message']); endif; ?>
<div class="row justify-content-center"><div class="col-lg-6">
    <div class="sb-card">
        <div class="sb-card-header">
            <div class="sb-card-title"><i class="bi bi-book"></i><?= $page_title ?></div>
        </div>
        <div class="sb-card-body">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="id" value="<?= $id ?>">

                <div class="mb-3">
                    <label class="sb-label">Nom <span class="text-danger">*</span></label>
                    <input class="sb-input" type="text" name="name" required value="<?= htmlspecialchars($item['name'] ?? '') ?>" placeholder="Ex : TCP/IP">
                </div>

                <div class="mb-3">
                    <label class="sb-label">Module associÃ©</label>
                    <select class="sb-select" name="module_id">
                        <option value="">â€” Tous modules â€”</option>
                        <?php while ($m = mysqli_fetch_assoc($modules)): ?>
                            <option value="<?= $m['id'] ?>" <?= ($item['module_id'] ?? 0) == $m['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($m['name']) ?><?= $m['licence_name'] ? ' Â· '.htmlspecialchars($m['licence_name']) : '' ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="sb-label">Statut</label>
                    <select name="status" class="sb-select">
                        <option value="active"   <?= ($item['status'] ?? 'active')==='active'?'selected':'' ?>>Actif</option>
                        <option value="inactive" <?= ($item['status'] ?? 'active')==='inactive'?'selected':'' ?>>Inactif</option>
                    </select>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn-sb-primary"><i class="bi bi-check-lg"></i> Enregistrer</button>
                    <a href="view-matieres.php" class="btn-sb-outline">Annuler</a>
                </div>
            </form>
        </div>
    </div>
</div></div>
</div><!-- /#main-content -->
</div><!-- /#admin-content -->
<?php include __DIR__ . '/includes/footer.php'; ?>



