<?php
ob_start();
include_once __DIR__ . '/bootstrap.php';
include_once __DIR__ . '/config/dbcon.php';
include_once __DIR__ . '/authentication.php';
include_once __DIR__ . '/../includes/csrf.php';

$canAssignRoles = emsp_can_assign_user_roles($auth_user ?? []);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();

    $first_name  = trim($_POST['first_name']  ?? '');
    $last_name   = trim($_POST['last_name']   ?? '');
    $email       = trim($_POST['email']       ?? '');
    $password    = (string) ($_POST['password']    ?? '');
    $role        = $canAssignRoles ? trim($_POST['role'] ?? 'etudiant') : 'etudiant';
    $status      = trim($_POST['status']      ?? 'active');
    $filiere_id  = intval($_POST['filiere_id'] ?? 0) ?: null;
    $licence_id  = intval($_POST['licence_id'] ?? 0) ?: null;

    // Validations
    if ($first_name === '' || $last_name === '' || $email === '' || $password === '') {
        $_SESSION['message'] = 'Tous les champs obligatoires doivent Ãªtre remplis.';
        header('Location: add-user.php'); exit(0);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['message'] = 'Adresse email invalide.';
        header('Location: add-user.php'); exit(0);
    }
    if (strlen($password) < 8) {
        $_SESSION['message'] = 'Le mot de passe doit contenir au moins 8 caractÃ¨res.';
        header('Location: add-user.php'); exit(0);
    }
    if (!in_array($role, ['etudiant','moderateur','admin'])) {
        $_SESSION['message'] = 'RÃ´le invalide.'; header('Location: add-user.php'); exit(0);
    }
    if (!in_array($status, ['active','pending','suspended'])) {
        $_SESSION['message'] = 'Statut invalide.'; header('Location: add-user.php'); exit(0);
    }

    // Email unique
    $chk = mysqli_prepare($con, "SELECT id FROM users WHERE email=? LIMIT 1");
    mysqli_stmt_bind_param($chk, 's', $email);
    mysqli_stmt_execute($chk);
    mysqli_stmt_store_result($chk);
    if (mysqli_stmt_num_rows($chk) > 0) {
        mysqli_stmt_close($chk);
        $_SESSION['message'] = 'Cette adresse email est dÃ©jÃ  utilisÃ©e.';
        header('Location: add-user.php'); exit(0);
    }
    mysqli_stmt_close($chk);

    $password_hash = password_hash($password, PASSWORD_DEFAULT);

    $ins = mysqli_prepare($con,
        "INSERT INTO users
         (first_name, last_name, email, password_hash, role, status,
          registration_method, filiere_id, licence_id)
         VALUES (?, ?, ?, ?, ?, ?, 'school_email', ?, ?)");
    mysqli_stmt_bind_param($ins, 'ssssssii',
        $first_name, $last_name, $email, $password_hash,
        $role, $status, $filiere_id, $licence_id);
    mysqli_stmt_execute($ins);
    mysqli_stmt_close($ins);

    $_SESSION['message'] = 'Utilisateur crÃ©Ã© avec succÃ¨s.';
    header('Location: view-users.php'); exit(0);
}

$filieres = mysqli_query($con, "SELECT id, name FROM filieres WHERE status='active' ORDER BY name");
$licences = mysqli_query($con, "SELECT id, name FROM licences WHERE status='active' ORDER BY name");

$page_title = 'Ajouter un utilisateur';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<?php include __DIR__ . '/includes/navbar-top.php'; ?>
<div id="admin-content">
    <div id="main-content" class="container-fluid">

<!-- Page header -->
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-4">
    <div>
        <h1 class="h4 mb-1 d-flex align-items-center gap-2">
            <i class="bi bi-person-plus text-primary"></i>
            Ajouter un utilisateur
        </h1>
        <p class="text-body-secondary small mb-0">Creez un nouveau compte etudiant, moderateur ou administrateur.</p>
    </div>
    <a href="view-users.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Retour a la liste
    </a>
</div>

<?php if (!empty($_SESSION['message'])): ?>
    <div class="alert alert-warning alert-dismissible fade show d-flex align-items-center gap-2 border-0 shadow-sm">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <span><?= htmlspecialchars($_SESSION['message']) ?></span>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Fermer"></button>
    </div>
    <?php unset($_SESSION['message']); ?>
<?php endif; ?>

<div class="row g-4 justify-content-center">
    <div class="col-xl-8 col-lg-10">
        <form method="POST" data-emsp-submit="1" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">

            <!-- Bloc 1 : identite -->
            <div class="card mb-4">
                <div class="card-header d-flex align-items-center gap-2">
                    <i class="bi bi-person-badge text-primary"></i>
                    <span class="fw-semibold">Identite</span>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Prenom <span class="text-danger">*</span></label>
                            <input class="form-control" type="text" name="first_name" required autocomplete="given-name"
                                   placeholder="Ex : Amadou">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Nom <span class="text-danger">*</span></label>
                            <input class="form-control" type="text" name="last_name" required autocomplete="family-name"
                                   placeholder="Ex : Diallo">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Email <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-body"><i class="bi bi-envelope"></i></span>
                                <input class="form-control" type="email" name="email" required autocomplete="email"
                                       placeholder="Ex : amadou.diallo@emsp.int">
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Mot de passe <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-body"><i class="bi bi-key"></i></span>
                                <input class="form-control" type="password" name="password" required autocomplete="new-password"
                                       minlength="8" placeholder="Minimum 8 caracteres">
                                <button class="btn btn-outline-secondary" type="button"
                                        onclick="(function(b){var i=b.previousElementSibling;i.type=i.type==='password'?'text':'password';b.querySelector('i').className=i.type==='password'?'bi bi-eye':'bi bi-eye-slash';})(this)"
                                        aria-label="Afficher / masquer le mot de passe">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                            <div class="form-text">L'utilisateur pourra le modifier depuis son profil.</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bloc 2 : acces et role -->
            <div class="card mb-4">
                <div class="card-header d-flex align-items-center gap-2">
                    <i class="bi bi-shield-lock text-primary"></i>
                    <span class="fw-semibold">Acces et role</span>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Role</label>
                            <?php if ($canAssignRoles): ?>
                                <select class="form-select" name="role">
                                    <option value="etudiant">Etudiant</option>
                                    <option value="moderateur">Moderateur</option>
                                    <option value="admin">Administrateur</option>
                                </select>
                            <?php else: ?>
                                <input type="hidden" name="role" value="etudiant">
                                <input class="form-control" type="text" value="Etudiant" readonly>
                                <div class="form-text">Seul un administrateur peut attribuer un role staff ou admin.</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Statut initial</label>
                            <select class="form-select" name="status">
                                <option value="active">Actif</option>
                                <option value="pending">En attente</option>
                                <option value="suspended">Suspendu</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bloc 3 : rattachement academique -->
            <div class="card mb-4">
                <div class="card-header d-flex align-items-center gap-2">
                    <i class="bi bi-mortarboard text-primary"></i>
                    <span class="fw-semibold">Rattachement academique</span>
                    <span class="badge bg-body-tertiary text-body-secondary border ms-auto">Optionnel</span>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Filiere</label>
                            <select class="form-select" name="filiere_id" data-emsp-select2="1" data-emsp-select2-placeholder="Aucune">
                                <option value="">Aucune</option>
                                <?php while ($f = mysqli_fetch_assoc($filieres)): ?>
                                    <option value="<?= $f['id'] ?>"><?= htmlspecialchars($f['name']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Niveau</label>
                            <select class="form-select" name="licence_id" data-emsp-select2="1" data-emsp-select2-placeholder="Aucun">
                                <option value="">Aucun</option>
                                <?php while ($l = mysqli_fetch_assoc($licences)): ?>
                                    <option value="<?= $l['id'] ?>"><?= htmlspecialchars($l['name']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="d-flex flex-column flex-md-row gap-2 justify-content-end mb-4">
                <a href="view-users.php" class="btn btn-outline-secondary order-md-1 order-2">
                    Annuler
                </a>
                <button type="submit" class="btn btn-primary order-md-2 order-1" data-loading-text="Creation du compte...">
                    <i class="bi bi-check-lg me-1"></i>Creer le compte
                </button>
            </div>
        </form>
    </div>
</div>

</div><!-- /#main-content -->
</div><!-- /#admin-content -->
<?php include __DIR__ . '/includes/footer.php'; ?>


