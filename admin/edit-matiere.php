<?php
ob_start();
include_once __DIR__ . '/bootstrap.php';
include_once __DIR__ . '/config/dbcon.php';
include_once __DIR__ . '/authentication.php';
include_once __DIR__ . '/../includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $name = trim($_POST['name'] ?? '');
    $module_id = intval($_POST['module_id'] ?? 0) ?: null;
    $id = intval($_POST['id'] ?? 0);
    if ($name === '') { $_SESSION['message'] = 'Nom obligatoire.'; header('Location: '.$_SERVER['PHP_SELF'].($id?"?id=$id":'')); exit(0); }
    if ($id > 0) { $s = mysqli_prepare($con,"UPDATE matieres SET name=?, module_id=? WHERE id=?"); mysqli_stmt_bind_param($s,'sii',$name,$module_id,$id); }
    else { $s = mysqli_prepare($con,"INSERT INTO matieres (name, module_id) VALUES (?, ?)"); mysqli_stmt_bind_param($s,'si',$name,$module_id); }
    mysqli_stmt_execute($s); mysqli_stmt_close($s);
    $_SESSION['message'] = $id > 0 ? 'MatiÃ¨re modifiÃ©e.' : 'MatiÃ¨re ajoutÃ©e.';
    header('Location: view-matieres.php'); exit(0);
}
$id = intval($_GET['id'] ?? 0); $item = null;
if ($id > 0) { $s = mysqli_prepare($con,"SELECT * FROM matieres WHERE id=? LIMIT 1"); mysqli_stmt_bind_param($s,'i',$id); mysqli_stmt_execute($s); $item = emsp_stmt_fetch_assoc($s); mysqli_stmt_close($s); }
$modules = mysqli_query($con,"SELECT id, name FROM modules WHERE status='active' ORDER BY name");
$page_title = $id > 0 ? 'Modifier la matiÃ¨re' : 'Ajouter une matiÃ¨re';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<?php include __DIR__ . '/includes/navbar-top.php'; ?>
<div id="admin-content">
    <div id="main-content" class="container-fluid">
<?php if (!empty($_SESSION['message'])): ?><div class="alert alert-info alert-dismissible fade show"><?= htmlspecialchars($_SESSION['message']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php unset($_SESSION['message']); endif; ?>
<div class="row justify-content-center"><div class="col-md-6"><div class="card shadow-sm">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-journal-text me-2 text-primary"></i><?= $page_title ?></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="id" value="<?= $id ?>">
            <div class="mb-3"><label class="form-label fw-semibold">Nom <span class="text-danger">*</span></label>
                <input class="form-control" type="text" name="name" required value="<?= htmlspecialchars($item['name'] ?? '') ?>" placeholder="Ex : Algorithmique, Bases de donnÃ©es..."></div>
            <div class="mb-3"><label class="form-label fw-semibold">Module associÃ©</label>
                <select class="form-select" name="module_id">
                    <option value="">â€” Tous modules â€”</option>
                    <?php while ($m = mysqli_fetch_assoc($modules)): ?>
                        <option value="<?= $m['id'] ?>" <?= ($item['module_id'] ?? 0) == $m['id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['name']) ?></option>
                    <?php endwhile; ?>
                </select></div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Enregistrer</button>
                <a href="view-matieres.php" class="btn btn-outline-secondary">Annuler</a>
            </div>
        </form>
    </div>
</div></div></div>
</div><!-- /#main-content -->
</div><!-- /#admin-content -->
<?php include __DIR__ . '/includes/footer.php'; ?>



