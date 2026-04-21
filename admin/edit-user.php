<?php
ob_start();
include_once __DIR__ . '/bootstrap.php';
include_once __DIR__ . '/config/dbcon.php';
include_once __DIR__ . '/authentication.php';
include_once __DIR__ . '/../includes/csrf.php';

$id = intval($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: view-users.php');
    exit(0);
}

$canAssignRoles = emsp_can_assign_user_roles($auth_user ?? []);

$targetRoleStmt = mysqli_prepare($con, "SELECT role FROM users WHERE id=? LIMIT 1");
$currentTargetRole = '';
if ($targetRoleStmt) {
    mysqli_stmt_bind_param($targetRoleStmt, 'i', $id);
    mysqli_stmt_execute($targetRoleStmt);
    $targetRoleRow = emsp_stmt_fetch_assoc($targetRoleStmt);
    mysqli_stmt_close($targetRoleStmt);
    $currentTargetRole = strtolower((string) ($targetRoleRow['role'] ?? ''));
}

if ($currentTargetRole === '') {
    header('Location: view-users.php');
    exit(0);
}

if (!emsp_can_manage_user_account($auth_user, ['role' => $currentTargetRole])) {
    $_SESSION['message'] = 'Seul un administrateur peut modifier un compte admin ou changer les roles.';
    header('Location: view-users.php');
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();

    $role = $canAssignRoles ? trim((string) ($_POST['role'] ?? '')) : $currentTargetRole;
    $status = trim((string) ($_POST['status'] ?? ''));

    if (!in_array($role, ['etudiant', 'moderateur', 'admin'], true)
        || !in_array($status, ['active', 'pending', 'suspended', 'rejected'], true)) {
        $_SESSION['message'] = 'Valeurs invalides.';
        header("Location: edit-user.php?id={$id}");
        exit(0);
    }

    $updateStmt = mysqli_prepare($con, "UPDATE users SET role=?, status=? WHERE id=?");
    if ($updateStmt) {
        mysqli_stmt_bind_param($updateStmt, 'ssi', $role, $status, $id);
        mysqli_stmt_execute($updateStmt);
        mysqli_stmt_close($updateStmt);
    }

    $_SESSION['message'] = 'Utilisateur mis a jour.';
    header('Location: view-users.php');
    exit(0);
}

$userStmt = mysqli_prepare(
    $con,
    "SELECT u.*, f.name AS filiere_name, l.name AS licence_name
     FROM users u
     LEFT JOIN filieres f ON f.id = u.filiere_id
     LEFT JOIN licences l ON l.id = u.licence_id
     WHERE u.id = ? LIMIT 1"
);
$user = null;
if ($userStmt) {
    mysqli_stmt_bind_param($userStmt, 'i', $id);
    mysqli_stmt_execute($userStmt);
    $user = emsp_stmt_fetch_assoc($userStmt);
    mysqli_stmt_close($userStmt);
}

if (!$user) {
    header('Location: view-users.php');
    exit(0);
}

foreach (['first_name', 'last_name', 'email', 'filiere_name', 'licence_name'] as $field) {
    if (isset($user[$field]) && is_string($user[$field])) {
        $user[$field] = emsp_fix_mojibake($user[$field]);
    }
}

$createdLabel = '-';
if (!empty($user['created_at'])) {
    $timestamp = strtotime((string) $user['created_at']);
    $createdLabel = $timestamp ? date('d/m/Y', $timestamp) : (string) $user['created_at'];
}

$jsonFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
$jsPrenom = json_encode((string) ($user['first_name'] ?? ''), $jsonFlags);
$jsNom = json_encode((string) ($user['last_name'] ?? ''), $jsonFlags);
$jsEmail = json_encode((string) ($user['email'] ?? ''), $jsonFlags);
$jsFiliere = json_encode((string) ($user['filiere_name'] ?? '-'), $jsonFlags);
$jsLicence = json_encode((string) ($user['licence_name'] ?? '-'), $jsonFlags);
$jsDate = json_encode($createdLabel, $jsonFlags);
$hasCard = !empty($user['student_card_path']);

$page_title = 'Modifier ' . ($user['first_name'] ?? 'utilisateur');
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
include __DIR__ . '/includes/navbar-top.php';
?>
<div id="admin-content">
    <div id="main-content" class="container-fluid">

<?php if (!empty($_SESSION['message'])): ?>
    <div class="alert alert-info alert-dismissible fade show">
        <?= htmlspecialchars((string) $_SESSION['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['message']); ?>
<?php endif; ?>

<div class="row justify-content-center">
<div class="col-lg-8">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h5 class="mb-0 fw-bold">
            <i class="bi bi-person-gear me-2 text-primary"></i>Modifier utilisateur
        </h5>
        <a href="view-users.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Retour
        </a>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="small text-muted">Nom complet</div>
                    <div class="fw-semibold"><?= htmlspecialchars((string) (($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))) ?></div>
                </div>
                <div class="col-md-6">
                    <div class="small text-muted">Email</div>
                    <div class="fw-semibold emsp-w-break-all"><?= htmlspecialchars((string) ($user['email'] ?? '')) ?></div>
                </div>
                <div class="col-md-6">
                    <div class="small text-muted">Filiere</div>
                    <div class="fw-semibold"><?= htmlspecialchars((string) ($user['filiere_name'] ?? '-')) ?></div>
                </div>
                <div class="col-md-6">
                    <div class="small text-muted">Niveau</div>
                    <div class="fw-semibold"><?= htmlspecialchars((string) ($user['licence_name'] ?? '-')) ?></div>
                </div>
            </div>

            <hr class="my-3">

            <form method="POST" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="id" value="<?= (int) $id ?>">

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Role</label>
                    <?php if ($canAssignRoles): ?>
                        <select name="role" class="form-select" required>
                            <?php foreach (['etudiant' => 'Etudiant', 'moderateur' => 'Moderateur', 'admin' => 'Admin'] as $value => $label): ?>
                                <option value="<?= $value ?>" <?= (($user['role'] ?? '') === $value) ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="text" class="form-control" value="<?= htmlspecialchars(ucfirst((string) ($user['role'] ?? ''))) ?>" readonly>
                        <div class="form-text">Seul un administrateur peut modifier le role d'un utilisateur.</div>
                    <?php endif; ?>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Statut</label>
                    <select name="status" class="form-select" required>
                        <?php foreach (['active' => 'Actif', 'pending' => 'En attente', 'suspended' => 'Suspendu', 'rejected' => 'Rejete'] as $value => $label): ?>
                            <option value="<?= $value ?>" <?= (($user['status'] ?? '') === $value) ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 d-flex gap-2 flex-column flex-sm-row">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Enregistrer
                    </button>
                    <a href="voir-carte.php?user_id=<?= (int) ($user['id'] ?? 0) ?>"
                       class="btn btn-outline-primary <?= $hasCard ? '' : 'disabled' ?>"
                       <?= $hasCard ? 'target="_blank" rel="noopener"' : 'tabindex="-1" aria-disabled="true"' ?>
                       onclick="<?= $hasCard ? "return ouvrirCarte(" . (int) ($user['id'] ?? 0) . ', ' . $jsPrenom . ', ' . $jsNom . ', ' . $jsEmail . ', ' . $jsFiliere . ', ' . $jsLicence . ', ' . $jsDate . ')' : 'return false;' ?>">
                        <i class="bi bi-card-image me-1"></i>Voir carte etudiante
                    </a>
                </div>

                <?php if (!$hasCard): ?>
                    <div class="col-12">
                        <div class="small text-muted">Aucune carte etudiante fournie pour ce compte.</div>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="modal fade" id="modalCarte" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content border-0 shadow">
                <div class="modal-header border-bottom">
                    <h5 class="modal-title fw-bold">
                        <i class="bi bi-card-image me-2 text-primary"></i>
                        Carte etudiante
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body p-4">
                    <div class="row g-4">
                        <div class="col-lg-7">
                            <p class="text-muted fw-semibold small text-uppercase mb-2 emsp-letterwide">
                                <i class="bi bi-image me-1"></i>Carte fournie par l'etudiant
                            </p>
                            <div class="border rounded bg-light d-flex align-items-center justify-content-center p-2 emsp-preview-box">
                                <img id="modal-carte-img"
                                     src=""
                                     alt="Carte etudiante"
                                     class="img-fluid rounded shadow-sm emsp-preview-img"
                                     onerror="this.style.display='none'; document.getElementById('carte-fallback').style.display='flex'">
                                <div id="carte-fallback" class="emsp-preview-fallback">
                                    <i class="bi bi-file-earmark-pdf text-danger emsp-preview-fallback-icon"></i>
                                    <a id="carte-pdf-link" href="#" target="_blank" rel="noopener" class="btn btn-outline-danger">
                                        <i class="bi bi-eye me-2"></i>Ouvrir (PDF)
                                    </a>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-5 d-flex flex-column">
                            <p class="text-muted fw-semibold small text-uppercase mb-2 emsp-letterwide">
                                <i class="bi bi-person-lines-fill me-1"></i>Informations declarees
                            </p>

                            <table class="table table-sm table-bordered mb-3 d-none d-md-table">
                                <tbody>
                                    <tr class="emsp-zebra-row">
                                        <td class="fw-semibold text-muted ps-3 emsp-label-cell">Prenom</td>
                                        <td class="ps-3 fw-medium" id="info-prenom">-</td>
                                    </tr>
                                    <tr>
                                        <td class="fw-semibold text-muted ps-3">Nom</td>
                                        <td class="ps-3 fw-medium" id="info-nom">-</td>
                                    </tr>
                                    <tr class="emsp-zebra-row">
                                        <td class="fw-semibold text-muted ps-3">Email</td>
                                        <td class="ps-3 small emsp-w-break-all" id="info-email">-</td>
                                    </tr>
                                    <tr>
                                        <td class="fw-semibold text-muted ps-3">Filiere</td>
                                        <td class="ps-3" id="info-filiere">-</td>
                                    </tr>
                                    <tr class="emsp-zebra-row">
                                        <td class="fw-semibold text-muted ps-3">Niveau</td>
                                        <td class="ps-3" id="info-licence">-</td>
                                    </tr>
                                    <tr>
                                        <td class="fw-semibold text-muted ps-3">Inscrit le</td>
                                        <td class="ps-3 text-muted small" id="info-date">-</td>
                                    </tr>
                                </tbody>
                            </table>

                            <div class="emsp-admin-mobile-meta d-md-none mb-3">
                                <div class="emsp-admin-mobile-meta-item">
                                    <span class="emsp-admin-mobile-meta-label">Prenom</span>
                                    <span class="emsp-admin-mobile-meta-value" id="info-prenom-mobile">-</span>
                                </div>
                                <div class="emsp-admin-mobile-meta-item">
                                    <span class="emsp-admin-mobile-meta-label">Nom</span>
                                    <span class="emsp-admin-mobile-meta-value" id="info-nom-mobile">-</span>
                                </div>
                                <div class="emsp-admin-mobile-meta-item">
                                    <span class="emsp-admin-mobile-meta-label">Email</span>
                                    <span class="emsp-admin-mobile-meta-value" id="info-email-mobile">-</span>
                                </div>
                                <div class="emsp-admin-mobile-meta-item">
                                    <span class="emsp-admin-mobile-meta-label">Filiere</span>
                                    <span class="emsp-admin-mobile-meta-value" id="info-filiere-mobile">-</span>
                                </div>
                                <div class="emsp-admin-mobile-meta-item">
                                    <span class="emsp-admin-mobile-meta-label">Niveau</span>
                                    <span class="emsp-admin-mobile-meta-value" id="info-licence-mobile">-</span>
                                </div>
                                <div class="emsp-admin-mobile-meta-item">
                                    <span class="emsp-admin-mobile-meta-label">Inscrit le</span>
                                    <span class="emsp-admin-mobile-meta-value" id="info-date-mobile">-</span>
                                </div>
                            </div>

                            <div class="alert alert-info small py-2">
                                <i class="bi bi-info-circle me-2"></i>
                                Verifiez que le <strong>nom</strong>, le <strong>prenom</strong> et la <strong>filiere</strong> correspondent a la carte.
                            </div>

                            <button type="button" class="btn btn-outline-secondary mt-auto" data-bs-dismiss="modal">
                                <i class="bi bi-x me-1"></i>Fermer
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>
</div>

</div><!-- /#main-content -->
</div><!-- /#admin-content -->
<?php
$page_scripts = <<<HTML
<script>
function ouvrirCarte(userId, prenom, nom, email, filiere, licence, date) {
    document.getElementById('info-prenom').textContent = prenom;
    document.getElementById('info-nom').textContent = nom;
    document.getElementById('info-email').textContent = email;
    document.getElementById('info-filiere').textContent = filiere;
    document.getElementById('info-licence').textContent = licence;
    document.getElementById('info-date').textContent = date;
    document.getElementById('info-prenom-mobile').textContent = prenom;
    document.getElementById('info-nom-mobile').textContent = nom;
    document.getElementById('info-email-mobile').textContent = email;
    document.getElementById('info-filiere-mobile').textContent = filiere;
    document.getElementById('info-licence-mobile').textContent = licence;
    document.getElementById('info-date-mobile').textContent = date;

    const url = 'voir-carte.php?user_id=' + userId;
    const img = document.getElementById('modal-carte-img');
    const fallback = document.getElementById('carte-fallback');

    img.style.display = 'block';
    fallback.style.display = 'none';
    img.src = url;
    document.getElementById('carte-pdf-link').href = url;

    if (!window.bootstrap || !bootstrap.Modal) {
        return true;
    }

    new bootstrap.Modal(document.getElementById('modalCarte')).show();
    return false;
}
</script>
HTML;
include __DIR__ . '/includes/footer.php';
?>


