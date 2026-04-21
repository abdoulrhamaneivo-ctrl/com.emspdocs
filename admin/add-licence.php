<?php
ob_start();
include_once __DIR__ . '/bootstrap.php';
include_once __DIR__ . '/config/dbcon.php';
include_once __DIR__ . '/authentication.php';
include_once __DIR__ . '/../includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $name        = trim($_POST['name'] ?? '');
    $status      = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
    $filiere_ids = $_POST['filiere_ids'] ?? [];
    if (!is_array($filiere_ids)) { $filiere_ids = []; }
    $filiere_ids = array_map('intval', $filiere_ids);
    $id          = intval($_POST['id'] ?? 0);

    if ($name === '') {
        $_SESSION['message'] = 'Nom obligatoire.';
        header('Location: '.$_SERVER['PHP_SELF'].($id ? '?id='.$id : '')); exit;
    }

    mysqli_begin_transaction($con);
    try {
        if ($id > 0) {
            $s = mysqli_prepare($con, "UPDATE licences SET name=?, status=? WHERE id=?");
            if (!$s) { throw new Exception('Erreur prepare update'); }
            mysqli_stmt_bind_param($s, 'ssi', $name, $status, $id);
            if (!mysqli_stmt_execute($s)) { mysqli_stmt_close($s); throw new Exception('Erreur update'); }
            mysqli_stmt_close($s);

            $del = mysqli_prepare($con, "DELETE FROM licence_filieres WHERE licence_id=?");
            if (!$del) { throw new Exception('Erreur prepare delete'); }
            mysqli_stmt_bind_param($del, 'i', $id);
            if (!mysqli_stmt_execute($del)) { mysqli_stmt_close($del); throw new Exception('Erreur delete'); }
            mysqli_stmt_close($del);

            $licenceId = $id;
        } else {
            $s = mysqli_prepare($con, "INSERT INTO licences (name, status) VALUES (?, ?)");
            if (!$s) { throw new Exception('Erreur prepare insert'); }
            mysqli_stmt_bind_param($s, 'ss', $name, $status);
            if (!mysqli_stmt_execute($s)) { mysqli_stmt_close($s); throw new Exception('Erreur insert'); }
            $licenceId = mysqli_insert_id($con);
            mysqli_stmt_close($s);
        }

        if (!empty($filiere_ids)) {
            $ins = mysqli_prepare($con, "INSERT IGNORE INTO licence_filieres (licence_id, filiere_id) VALUES (?, ?)");
            if (!$ins) { throw new Exception('Erreur prepare insert filieres'); }
            foreach ($filiere_ids as $fid) {
                if ($fid > 0) {
                    mysqli_stmt_bind_param($ins, 'ii', $licenceId, $fid);
                    if (!mysqli_stmt_execute($ins)) { mysqli_stmt_close($ins); throw new Exception('Erreur insert filieres'); }
                }
            }
            mysqli_stmt_close($ins);
        }

        mysqli_commit($con);
    } catch (Exception $e) {
        mysqli_rollback($con);
        $_SESSION['message'] = 'Erreur lors de la sauvegarde. RÃ©essayez.';
        header('Location: ' . $_SERVER['PHP_SELF'] . ($id ? '?id='.$id : ''));
        exit;
    }
    $_SESSION['message'] = $id > 0 ? 'Niveau modifiÃ©.' : 'Niveau ajoutÃ©.';
    header('Location: view-licences.php'); exit;
}

$id = intval($_GET['id'] ?? 0); $item = null; $selected_filieres = [];
if ($id > 0) {
    $s = mysqli_prepare($con,"SELECT * FROM licences WHERE id=? LIMIT 1");
    mysqli_stmt_bind_param($s,'i',$id); mysqli_stmt_execute($s);
    $item = emsp_stmt_fetch_assoc($s);
    mysqli_stmt_close($s);
    $sf = mysqli_query($con, "SELECT filiere_id FROM licence_filieres WHERE licence_id=" . intval($id));
    while ($row = mysqli_fetch_assoc($sf)) { $selected_filieres[] = (int)$row['filiere_id']; }
}
$filieres = mysqli_query($con, "SELECT id, name FROM filieres WHERE status='active' ORDER BY name");
$page_title = $id > 0 ? 'Modifier le niveau' : 'Ajouter un niveau';
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
            <div class="sb-card-title"><i class="bi bi-mortarboard"></i><?= $page_title ?></div>
        </div>
        <div class="sb-card-body">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="id" value="<?= $id ?>">

                <div class="mb-3">
                    <label class="sb-label">Nom <span class="text-danger">*</span></label>
                    <input class="sb-input" type="text" name="name" required value="<?= htmlspecialchars($item['name'] ?? '') ?>" placeholder="Ex : Licence 1, Master 2...">
                </div>

                <div class="mb-3">
                    <label class="sb-label">Statut</label>
                    <select name="status" class="sb-select">
                        <option value="active"   <?= ($item['status'] ?? 'active')==='active'?'selected':'' ?>>Actif</option>
                        <option value="inactive" <?= ($item['status'] ?? 'active')==='inactive'?'selected':'' ?>>Inactif</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="sb-label">FiliÃ¨res associÃ©es</label>
                    <select class="sb-select" name="filiere_ids[]" multiple size="7">
                        <?php while ($f = mysqli_fetch_assoc($filieres)): ?>
                            <option value="<?= $f['id'] ?>" <?= in_array((int)$f['id'], $selected_filieres, true) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($f['name']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                    <div class="sb-hint">SÃ©lectionne une ou plusieurs filiÃ¨res concernÃ©es (Ctrl/Cmd + clic).</div>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn-sb-primary"><i class="bi bi-check-lg"></i> Enregistrer</button>
                    <a href="view-licences.php" class="btn-sb-outline">Annuler</a>
                </div>
            </form>
        </div>
    </div>
</div></div>
</div><!-- /#main-content -->
</div><!-- /#admin-content -->
<?php include __DIR__ . '/includes/footer.php'; ?>



