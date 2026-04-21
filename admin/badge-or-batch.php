<?php
ob_start();
include_once __DIR__ . '/bootstrap.php';
include_once __DIR__ . '/config/dbcon.php';
include_once __DIR__ . '/authentication.php';
include_once __DIR__ . '/../includes/csrf.php';

if (!emsp_can_assign_user_roles($auth_user ?? [])) {
    $_SESSION['message'] = 'Cette section est reservee aux administrateurs.';
    header('Location: index.php');
    exit(0);
}

// â”€â”€ Attribution / Revocation badge Or â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['user_id'])) {
    verify_csrf_token();

    $target_id = intval($_POST['user_id']);
    $action    = $_POST['action']; // 'grant' ou 'revoke'

    if (!in_array($action, ['grant', 'revoke'])) {
        header('Location: badge-or-batch.php'); exit(0);
    }

    $new_badge = $action === 'grant' ? 'or' : 'argent'; // retrograder a argent si revocation

    // Mettre a jour le badge
    $upd = mysqli_prepare($con, "UPDATE users SET badge_level=? WHERE id=?");
    mysqli_stmt_bind_param($upd, 'si', $new_badge, $target_id);
    mysqli_stmt_execute($upd); mysqli_stmt_close($upd);

    // Tracer dans badge_or_assignments
    $admin_id = intval($auth_user['id']);
    $ins = mysqli_prepare($con,
        "INSERT INTO badge_or_assignments (user_id, assigned_by, action)
         VALUES (?, ?, ?)");
    mysqli_stmt_bind_param($ins, 'iis', $target_id, $admin_id, $action);
    mysqli_stmt_execute($ins); mysqli_stmt_close($ins);
    log_audit($con, $admin_id, $action === 'grant' ? 'badge_or_granted' : 'badge_or_revoked', 'user', $target_id, $new_badge);

    $msg = $action === 'grant' ? 'Badge Or attribue.' : 'Badge Or revoque.';
    $_SESSION['message'] = $msg;
    header('Location: badge-or-batch.php'); exit(0);
}

// â”€â”€ Recuperer le seuil depuis app_settings â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$seuil_row = mysqli_query($con,
    "SELECT svalue FROM app_settings WHERE skey='or_badge_min_approved_docs' LIMIT 1");
$seuil = intval(mysqli_fetch_row($seuil_row)[0] ?? 20);

// â”€â”€ Etudiants eligibles (upload_count >= seuil, pas encore Or) â”€
$eligiblesResult = mysqli_query($con,
    "SELECT u.id, u.first_name, u.last_name, u.email,
            u.upload_count, u.badge_level,
            f.name AS filiere_name
     FROM users u
     LEFT JOIN filieres f ON f.id = u.filiere_id
     WHERE u.role='etudiant'
       AND u.status='active'
     AND u.upload_count >= $seuil
     AND u.badge_level != 'or'
     ORDER BY u.upload_count DESC");
$eligibles = [];
if ($eligiblesResult) {
    while ($row = mysqli_fetch_assoc($eligiblesResult)) {
        $eligibles[] = $row;
    }
}

// â”€â”€ Etudiants ayant deja le badge Or â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
$orHoldersResult = mysqli_query($con,
    "SELECT u.id, u.first_name, u.last_name, u.email,
            u.upload_count, f.name AS filiere_name,
            (SELECT MAX(ba.created_at) FROM badge_or_assignments ba
             WHERE ba.user_id=u.id AND ba.action='grant') AS granted_at,
            (SELECT ab.first_name FROM badge_or_assignments ba
             JOIN users ab ON ab.id=ba.assigned_by
             WHERE ba.user_id=u.id AND ba.action='grant'
             ORDER BY ba.created_at DESC LIMIT 1) AS granted_by
     FROM users u
     LEFT JOIN filieres f ON f.id = u.filiere_id
     WHERE u.badge_level='or' AND u.role='etudiant'
     ORDER BY u.upload_count DESC");
$or_holders = [];
if ($orHoldersResult) {
    while ($row = mysqli_fetch_assoc($orHoldersResult)) {
        $or_holders[] = $row;
    }
}

$page_title = 'Badge Or';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<?php include __DIR__ . '/includes/navbar-top.php'; ?>
<div id="admin-content">
    <div id="main-content" class="container-fluid">

<?php if (!empty($_SESSION['message'])): ?>
    <div class="alert alert-info alert-dismissible fade show">
        <?= htmlspecialchars($_SESSION['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['message']); ?>
<?php endif; ?>

<!-- En-tete -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold">
        Gestion Badge Or
        <small class="text-muted fw-normal fs-6 ms-2">Seuil actuel : <?= $seuil ?> docs approuves</small>
    </h5>
    <a href="settings.php" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-gear me-1"></i>Modifier le seuil
    </a>
</div>

<!-- Rappel regle metier -->
<div class="alert alert-warning d-flex gap-2 align-items-start mb-4">
    <i class="bi bi-exclamation-triangle-fill fs-5 mt-1"></i>
    <div>
        <strong>Regle stricte :</strong> Le badge Or n'est <u>jamais automatique</u>.
        Chaque attribution doit etre decidee manuellement par un administrateur
        et est tracee dans le journal.
    </div>
</div>

<!-- â”€â”€ Etudiants eligibles â”€â”€ -->
<h6 class="fw-semibold mb-2">
    <i class="bi bi-person-check me-2 text-success"></i>
    Etudiants eligibles (<?= count($eligibles) ?>)
</h6>

<div class="card shadow-sm mb-4">
    <div class="card-body p-0 d-none d-md-block">
        <table class="table table-admin table-hover mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Etudiant</th>
                    <th>Email</th>
                    <th>Filiere</th>
                    <th>Docs approuves</th>
                    <th>Badge actuel</th>
                    <th class="text-center">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($eligibles)): ?>
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">
                        Aucun etudiant eligible pour le moment.
                    </td>
                </tr>
            <?php else: ?>
            <?php foreach ($eligibles as $u): ?>
                <?php
                foreach (['first_name','last_name','email','filiere_name'] as $f) {
                    if (isset($u[$f]) && is_string($u[$f])) {
                        $u[$f] = emsp_fix_mojibake($u[$f]);
                    }
                }
                ?>
                <tr>
                    <td class="ps-3 fw-medium">
                        <?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?>
                    </td>
                    <td class="text-muted small"><?= htmlspecialchars($u['email']) ?></td>
                    <td class="text-muted small">
                        <?= htmlspecialchars($u['filiere_name'] ?? '-') ?>
                    </td>
                    <td>
                        <span class="badge bg-success"><?= $u['upload_count'] ?></span>
                    </td>
                    <td>
                        <?= ['argent'=>'Argent','bronze'=>'Bronze','none'=>'-'][$u['badge_level']] ?? '-' ?>
                    </td>
                    <td class="text-center">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <input type="hidden" name="action" value="grant">
                            <button type="submit" class="btn btn-sm btn-warning fw-semibold" data-emsp-confirm-auto="1" data-confirm="Attribuer le badge Or ?" data-confirm-detail="Cette attribution restera tracee dans le journal admin pour <?= htmlspecialchars($u['first_name']) ?>." data-confirm-type="warning" data-confirm-ok="Oui, attribuer">
                                Attribuer Badge Or
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-body d-md-none">
        <?php if (empty($eligibles)): ?>
            <div class="emsp-admin-mobile-empty">Aucun etudiant eligible pour le moment.</div>
        <?php else: ?>
            <div class="emsp-admin-mobile-list">
                <?php foreach ($eligibles as $u): ?>
                    <?php
                    foreach (['first_name','last_name','email','filiere_name'] as $f) {
                        if (isset($u[$f]) && is_string($u[$f])) {
                            $u[$f] = emsp_fix_mojibake($u[$f]);
                        }
                    }
                    ?>
                    <div class="emsp-admin-mobile-card">
                        <div class="emsp-admin-mobile-card-header">
                            <div>
                                <h3 class="emsp-admin-mobile-card-title mb-0"><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></h3>
                                <div class="emsp-admin-mobile-card-subtitle"><?= htmlspecialchars($u['email']) ?></div>
                            </div>
                            <span class="sb-badge sb-badge-green"><?= (int) $u['upload_count'] ?> docs</span>
                        </div>
                        <div class="emsp-admin-mobile-meta">
                            <div class="emsp-admin-mobile-meta-item">
                                <span class="emsp-admin-mobile-meta-label">Filiere</span>
                                <span class="emsp-admin-mobile-meta-value"><?= htmlspecialchars($u['filiere_name'] ?? '-') ?></span>
                            </div>
                            <div class="emsp-admin-mobile-meta-item">
                                <span class="emsp-admin-mobile-meta-label">Badge actuel</span>
                                <span class="emsp-admin-mobile-meta-value"><?= ['argent'=>'Argent','bronze'=>'Bronze','none'=>'-'][$u['badge_level']] ?? '-' ?></span>
                            </div>
                        </div>
                        <div class="emsp-admin-mobile-actions">
                            <form method="POST" class="w-100">
                                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                <input type="hidden" name="action" value="grant">
                                <button type="submit" class="btn btn-warning w-100 fw-semibold" data-emsp-confirm-auto="1" data-confirm="Attribuer le badge Or ?" data-confirm-detail="Cette attribution restera tracee dans le journal admin pour <?= htmlspecialchars($u['first_name']) ?>." data-confirm-type="warning" data-confirm-ok="Oui, attribuer">
                                    Attribuer Badge Or
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- â”€â”€ Detenteurs actuels du badge Or â”€â”€ -->
<h6 class="fw-semibold mb-2">
    <i class="bi bi-award-fill me-2 text-warning"></i>
    Detenteurs actuels du badge Or (<?= count($or_holders) ?>)
</h6>

<div class="card shadow-sm">
    <div class="card-body p-0 d-none d-md-block">
        <table class="table table-admin table-hover mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Etudiant</th>
                    <th>Email</th>
                    <th>Filiere</th>
                    <th>Docs approuves</th>
                    <th>Attribue le</th>
                    <th>Par</th>
                    <th class="text-center">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($or_holders)): ?>
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">
                        Aucun etudiant avec le badge Or.
                    </td>
                </tr>
            <?php else: ?>
            <?php foreach ($or_holders as $u): ?>
                <?php
                foreach (['first_name','last_name','email','filiere_name','granted_by'] as $f) {
                    if (isset($u[$f]) && is_string($u[$f])) {
                        $u[$f] = emsp_fix_mojibake($u[$f]);
                    }
                }
                ?>
                <tr>
                    <td class="ps-3 fw-medium">
                        <span class="badge bg-warning text-dark me-2">OR</span> <?=  htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?>
                    </td>
                    <td class="text-muted small"><?= htmlspecialchars($u['email']) ?></td>
                    <td class="text-muted small">
                        <?= htmlspecialchars($u['filiere_name'] ?? '-') ?>
                    </td>
                    <td>
                        <span class="badge bg-success"><?= $u['upload_count'] ?></span>
                    </td>
                    <td class="text-muted small">
                        <?= $u['granted_at'] ? date('d/m/Y', strtotime($u['granted_at'])) : '-' ?>
                    </td>
                    <td class="text-muted small">
                        <?= htmlspecialchars($u['granted_by'] ?? '-') ?>
                    </td>
                    <td class="text-center">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <input type="hidden" name="action" value="revoke">
                            <button type="submit" class="btn btn-sm btn-outline-danger" data-emsp-confirm-auto="1" data-confirm="Revoquer le badge Or ?" data-confirm-detail="L'utilisateur reviendra au niveau Argent et l'action restera tracee pour <?= htmlspecialchars($u['first_name']) ?>." data-confirm-type="danger" data-confirm-ok="Oui, revoquer">
                                <i class="bi bi-x-lg me-1"></i>Revoquer
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-body d-md-none">
        <?php if (empty($or_holders)): ?>
            <div class="emsp-admin-mobile-empty">Aucun etudiant avec le badge Or.</div>
        <?php else: ?>
            <div class="emsp-admin-mobile-list">
                <?php foreach ($or_holders as $u): ?>
                    <?php
                    foreach (['first_name','last_name','email','filiere_name','granted_by'] as $f) {
                        if (isset($u[$f]) && is_string($u[$f])) {
                            $u[$f] = emsp_fix_mojibake($u[$f]);
                        }
                    }
                    ?>
                    <div class="emsp-admin-mobile-card">
                        <div class="emsp-admin-mobile-card-header">
                            <div>
                                <h3 class="emsp-admin-mobile-card-title mb-0"><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></h3>
                                <div class="emsp-admin-mobile-card-subtitle"><?= htmlspecialchars($u['email']) ?></div>
                            </div>
                            <span class="badge bg-warning text-dark">Badge Or</span>
                        </div>
                        <div class="emsp-admin-mobile-meta">
                            <div class="emsp-admin-mobile-meta-item">
                                <span class="emsp-admin-mobile-meta-label">Filiere</span>
                                <span class="emsp-admin-mobile-meta-value"><?= htmlspecialchars($u['filiere_name'] ?? '-') ?></span>
                            </div>
                            <div class="emsp-admin-mobile-meta-item">
                                <span class="emsp-admin-mobile-meta-label">Docs approuves</span>
                                <span class="emsp-admin-mobile-meta-value"><?= (int) $u['upload_count'] ?></span>
                            </div>
                            <div class="emsp-admin-mobile-meta-item">
                                <span class="emsp-admin-mobile-meta-label">Attribue le</span>
                                <span class="emsp-admin-mobile-meta-value"><?= !empty($u['granted_at']) ? date('d/m/Y', strtotime($u['granted_at'])) : '-' ?></span>
                            </div>
                            <div class="emsp-admin-mobile-meta-item">
                                <span class="emsp-admin-mobile-meta-label">Par</span>
                                <span class="emsp-admin-mobile-meta-value"><?= htmlspecialchars($u['granted_by'] ?? '-') ?></span>
                            </div>
                        </div>
                        <div class="emsp-admin-mobile-actions">
                            <form method="POST" class="w-100">
                                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                <input type="hidden" name="action" value="revoke">
                                <button type="submit" class="btn btn-outline-danger w-100" data-emsp-confirm-auto="1" data-confirm="Revoquer le badge Or ?" data-confirm-detail="L'utilisateur reviendra au niveau Argent et l'action restera tracee pour <?= htmlspecialchars($u['first_name']) ?>." data-confirm-type="danger" data-confirm-ok="Oui, revoquer">
                                    <i class="bi bi-x-lg me-1"></i>Revoquer
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


