<?php
ob_start();
include_once __DIR__ . '/bootstrap.php';
include_once __DIR__ . '/config/dbcon.php';
include_once __DIR__ . '/authentication.php';
include_once __DIR__ . '/../includes/csrf.php';
include_once __DIR__ . '/../includes/flash.php';

$canAssignRoles = emsp_can_assign_user_roles($auth_user ?? []);

// Actions rapides (suspend / activate / delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['user_id'])) {
    verify_csrf_token();
    $uid    = intval($_POST['user_id']);
    $action = $_POST['action'];

    // EmpÃªcher l'admin de se suspendre lui-mÃªme
    if ($uid === intval($auth_user['id'])) {
        flash_set(
            'warning',
            'Action impossible',
            'Vous ne pouvez pas modifier votre propre compte ici.'
        );
        header('Location: view-users.php'); exit(0);
    }

    $targetStmt = mysqli_prepare($con, "SELECT id, role FROM users WHERE id=? LIMIT 1");
    $targetUser = null;
    if ($targetStmt) {
        mysqli_stmt_bind_param($targetStmt, 'i', $uid);
        mysqli_stmt_execute($targetStmt);
        $targetUser = emsp_stmt_fetch_assoc($targetStmt);
        mysqli_stmt_close($targetStmt);
    }
    if (!$targetUser || !emsp_can_manage_user_account($auth_user, $targetUser)) {
        flash_set(
            'warning',
            'Action reservee',
            'Seul un administrateur peut modifier un compte admin ou toucher aux roles.'
        );
        header('Location: view-users.php'); exit(0);
    }

    $map = [
        'activate'  => 'active',
        'suspend'   => 'suspended',
        'reject'    => 'rejected',
    ];

    if (isset($map[$action])) {
        $new_status = $map[$action];
        $s = mysqli_prepare($con, "UPDATE users SET status=? WHERE id=?");
        if ($s) {
            mysqli_stmt_bind_param($s, 'si', $new_status, $uid);
            mysqli_stmt_execute($s);
            mysqli_stmt_close($s);

            $audit_actions = [
                'activate' => 'account_activated',
                'suspend' => 'account_suspended',
                'reject' => 'account_rejected',
            ];
            log_audit($con, (int) $auth_user['id'], $audit_actions[$action] ?? 'account_updated', 'user', $uid, $new_status);
            flash_set(
                'info',
                'Statut mis Ã  jour',
                'Le statut de lâ€™utilisateur a Ã©tÃ© modifiÃ©.'
            );
        }
    }
    header('Location: view-users.php'); exit(0);
}

// Filtres
$filter_role   = $_GET['role']   ?? '';
$filter_status = $_GET['status'] ?? '';
$search        = trim($_GET['q'] ?? '');
$per_page      = 25;
$page_num      = max(1, intval($_GET['page'] ?? 1));

$where = "WHERE 1=1";
$params = [];
$types  = '';

if ($filter_role !== '') {
    $where   .= " AND u.role = ?";
    $params[] = $filter_role;
    $types   .= 's';
}
if ($filter_status !== '') {
    $where   .= " AND u.status = ?";
    $params[] = $filter_status;
    $types   .= 's';
}
if ($search !== '') {
    $like     = '%' . $search . '%';
    $where   .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types   .= 'sss';
}

$count_sql = "SELECT COUNT(*) FROM users u LEFT JOIN filieres f ON f.id = u.filiere_id $where";
$count_stmt = mysqli_prepare($con, $count_sql);
$total_users = 0;
if ($count_stmt) {
    if ($params) {
        mysqli_stmt_bind_param($count_stmt, $types, ...$params);
    }
    mysqli_stmt_execute($count_stmt);
    mysqli_stmt_bind_result($count_stmt, $total_users);
    mysqli_stmt_fetch($count_stmt);
    mysqli_stmt_close($count_stmt);
}

$total_pages = max(1, (int) ceil($total_users / $per_page));
$page_num = min($page_num, $total_pages);
$offset = ($page_num - 1) * $per_page;

$sql = "SELECT u.id, u.first_name, u.last_name, u.email, u.role, u.status,
               u.badge_level, u.upload_count, u.created_at,
               u.registration_method, f.name AS filiere_name
        FROM users u
        LEFT JOIN filieres f ON f.id = u.filiere_id
        $where
        ORDER BY u.created_at DESC
        LIMIT ? OFFSET ?";

$stmt = mysqli_prepare($con, $sql);
$users = [];
if ($stmt) {
    $query_params = $params;
    $query_params[] = $per_page;
    $query_params[] = $offset;
    $query_types = $types . 'ii';
    mysqli_stmt_bind_param($stmt, $query_types, ...$query_params);
    mysqli_stmt_execute($stmt);
    $users = emsp_stmt_fetch_all($stmt);
    mysqli_stmt_close($stmt);
}
$users_count = count($users);

function user_url(int $page): string
{
    $query = $_GET;
    $query['page'] = $page;
    return 'view-users.php?' . http_build_query($query);
}

$page_title = 'Utilisateurs';
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
            <i class="bi bi-people text-primary"></i>
            Utilisateurs
            <span class="badge rounded-pill bg-body-tertiary text-body-secondary border ms-1"><?= (int) $total_users ?></span>
        </h1>
        <p class="text-body-secondary small mb-0">Gerez les comptes etudiants, moderateurs et administrateurs.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="pending-users.php" class="btn btn-outline-warning btn-sm">
            <i class="bi bi-person-check me-1"></i>Validation
        </a>
        <a href="add-user.php" class="btn btn-primary btn-sm">
            <i class="bi bi-person-plus me-1"></i>Ajouter un utilisateur
        </a>
    </div>
</div>

<!-- Filtres -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-5">
                <label class="form-label small text-body-secondary mb-1">Rechercher</label>
                <div class="input-group">
                    <span class="input-group-text bg-body"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control" name="q"
                           placeholder="Nom, prenom ou email..."
                           value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>
            <div class="col-md-2 col-6">
                <label class="form-label small text-body-secondary mb-1">Role</label>
                <select class="form-select" name="role">
                    <option value="">Tous</option>
                    <option value="etudiant"   <?= $filter_role==='etudiant'   ? 'selected':'' ?>>Etudiant</option>
                    <option value="moderateur" <?= $filter_role==='moderateur' ? 'selected':'' ?>>Moderateur</option>
                    <option value="admin"      <?= $filter_role==='admin'      ? 'selected':'' ?>>Admin</option>
                </select>
            </div>
            <div class="col-md-2 col-6">
                <label class="form-label small text-body-secondary mb-1">Statut</label>
                <select class="form-select" name="status">
                    <option value="">Tous</option>
                    <option value="active"    <?= $filter_status==='active'    ? 'selected':'' ?>>Actif</option>
                    <option value="pending"   <?= $filter_status==='pending'   ? 'selected':'' ?>>En attente</option>
                    <option value="suspended" <?= $filter_status==='suspended' ? 'selected':'' ?>>Suspendu</option>
                    <option value="rejected"  <?= $filter_status==='rejected'  ? 'selected':'' ?>>Rejete</option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1">
                    <i class="bi bi-funnel me-1"></i>Filtrer
                </button>
                <a href="view-users.php" class="btn btn-outline-secondary" title="Reinitialiser">
                    <i class="bi bi-arrow-clockwise"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-list-ul text-body-secondary"></i>
            <span class="fw-semibold">Liste des utilisateurs</span>
            <?php if ($search !== '' || $filter_role !== '' || $filter_status !== ''): ?>
                <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">Filtre actif</span>
            <?php endif; ?>
        </div>
        <div class="text-body-secondary small">
            <?php
            $shown_from = $users_count > 0 ? $offset + 1 : 0;
            $shown_to = $offset + $users_count;
            ?>
            <?= $shown_from ?>&ndash;<?= $shown_to ?> sur <?= (int) $total_users ?>
        </div>
    </div>
    <div class="card-body p-0 d-none d-md-block">
        <table class="table table-admin table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">#</th>
                    <th>Nom</th>
                    <th>Email</th>
                    <th>RÃ´le</th>
                    <th>FiliÃ¨re</th>
                    <th>Badge</th>
                    <th>Docs</th>
                    <th>Statut</th>
                    <th>Inscrit le</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($users_count === 0): ?>
                <tr><td colspan="10" class="text-center text-muted py-4">Aucun utilisateur trouvÃ©.</td></tr>
            <?php else: ?>
            <?php foreach ($users as $u): ?>
                <?php $canManageTarget = emsp_can_manage_user_account($auth_user, $u); ?>
                <tr>
                    <td class="ps-3 text-muted small"><?= $u['id'] ?></td>
                    <td class="fw-medium emsp-nowrap">
                        <?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?>
                    </td>
                    <td class="text-muted small"><?= htmlspecialchars($u['email']) ?></td>
                    <td>
                        <?php
                        $role_badge = [
                            'admin'      => 'bg-danger',
                            'moderateur' => 'bg-warning text-dark',
                            'etudiant'   => 'bg-secondary',
                        ][$u['role']] ?? 'bg-secondary';
                        ?>
                        <span class="badge <?= $role_badge ?>">
                            <?= ucfirst($u['role']) ?>
                        </span>
                    </td>
                    <td class="text-muted small">
                        <?= $u['filiere_name'] ? htmlspecialchars($u['filiere_name']) : 'â€”' ?>
                    </td>
                    <td>
                        <?php
                        $badge_icons = [
                            'or'     => '<span class="badge bg-warning text-dark">OR</span>',
                            'argent' => '<span class="badge bg-secondary text-white">ARG</span>',
                            'bronze' => '<span class="badge bg-danger text-white">BR</span>',
                            'none'   => '-',
                        ];
                        echo $badge_icons[$u['badge_level']] ?? '-';
                        ?>
                    </td>
                    <td class="text-center small"><?= $u['upload_count'] ?></td>
                    <td>
                        <?php
                        $sb = ['active'=>'badge-active','pending'=>'badge-pending','suspended'=>'badge-rejected','rejected'=>'badge-rejected'];
                        $sl = ['active'=>'Actif','pending'=>'En attente','suspended'=>'Suspendu','rejected'=>'RejetÃ©'];
                        ?>
                        <span class="badge <?= $sb[$u['status']] ?? 'bg-secondary' ?>">
                            <?= $sl[$u['status']] ?? $u['status'] ?>
                        </span>
                    </td>
                    <td class="text-muted small">
                        <?= date('d/m/Y', strtotime($u['created_at'])) ?>
                    </td>
                    <td class="text-center">
                        <div class="d-flex gap-1 justify-content-center">
                            <?php if ($canManageTarget): ?>
                                <a href="edit-user.php?id=<?= $u['id'] ?>"
                                   class="btn btn-sm btn-outline-primary" title="Modifier">
                                    <i class="bi bi-pencil"></i>
                                </a>
                            <?php endif; ?>
                            <?php if ($canManageTarget && $u['status'] === 'suspended'): ?>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    <input type="hidden" name="action" value="activate">
                                    <button type="submit"
                                            class="btn btn-sm btn-outline-success"
                                            title="RÃ©activer"
                                            data-confirm="RÃ©activer ce compte ?"
                                            data-confirm-detail="Lâ€™utilisateur pourra Ã  nouveau se connecter."
                                            data-confirm-type="info"
                                            data-confirm-ok="Oui, rÃ©activer"
                                            data-emsp-confirm-auto="1">
                                        <i class="bi bi-check-lg"></i>
                                    </button>
                                </form>
                            <?php elseif ($canManageTarget && $u['status'] === 'active' && $u['id'] != $auth_user['id']): ?>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                    <input type="hidden" name="action" value="suspend">
                                    <button type="submit"
                                            class="btn btn-sm btn-outline-warning"
                                            title="Suspendre"
                                            data-confirm="Suspendre ce compte ?"
                                            data-confirm-detail="Lâ€™utilisateur ne pourra plus se connecter."
                                            data-confirm-type="warning"
                                            data-confirm-ok="Oui, suspendre"
                                            data-emsp-confirm-auto="1">
                                        <i class="bi bi-pause-circle"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-body d-md-none">
        <?php if ($users_count === 0): ?>
            <div class="emsp-admin-mobile-empty">Aucun utilisateur trouve.</div>
        <?php else: ?>
            <div class="emsp-admin-mobile-list">
                <?php foreach ($users as $u): ?>
                    <?php
                    $canManageTarget = emsp_can_manage_user_account($auth_user, $u);
                    $role_badge = [
                        'admin'      => 'bg-danger',
                        'moderateur' => 'bg-warning text-dark',
                        'etudiant'   => 'bg-secondary',
                    ][$u['role']] ?? 'bg-secondary';
                    $sb = ['active'=>'badge-active','pending'=>'badge-pending','suspended'=>'badge-rejected','rejected'=>'badge-rejected'];
                    $sl = ['active'=>'Actif','pending'=>'En attente','suspended'=>'Suspendu','rejected'=>'Rejete'];
                    $badge_icons = [
                        'or'     => '<span class="badge bg-warning text-dark">OR</span>',
                        'argent' => '<span class="badge bg-secondary text-white">ARG</span>',
                        'bronze' => '<span class="badge bg-danger text-white">BR</span>',
                        'none'   => '<span class="text-muted">Aucun</span>',
                    ];
                    ?>
                    <div class="emsp-admin-mobile-card">
                        <div class="emsp-admin-mobile-card-header">
                            <div>
                                <h3 class="emsp-admin-mobile-card-title mb-0"><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></h3>
                                <div class="emsp-admin-mobile-card-subtitle"><?= htmlspecialchars($u['email']) ?></div>
                            </div>
                            <span class="badge <?= $sb[$u['status']] ?? 'bg-secondary' ?>">
                                <?= $sl[$u['status']] ?? $u['status'] ?>
                            </span>
                        </div>
                        <div class="emsp-admin-mobile-meta">
                            <div class="emsp-admin-mobile-meta-item">
                                <span class="emsp-admin-mobile-meta-label">Role</span>
                                <span class="emsp-admin-mobile-meta-value"><span class="badge <?= $role_badge ?>"><?= ucfirst($u['role']) ?></span></span>
                            </div>
                            <div class="emsp-admin-mobile-meta-item">
                                <span class="emsp-admin-mobile-meta-label">Filiere</span>
                                <span class="emsp-admin-mobile-meta-value"><?= $u['filiere_name'] ? htmlspecialchars($u['filiere_name']) : 'Non renseignee' ?></span>
                            </div>
                            <div class="emsp-admin-mobile-meta-item">
                                <span class="emsp-admin-mobile-meta-label">Badge / Documents</span>
                                <span class="emsp-admin-mobile-meta-value"><?= $badge_icons[$u['badge_level']] ?? '<span class="text-muted">Aucun</span>' ?> <span class="ms-2"><?= (int) $u['upload_count'] ?> document(s)</span></span>
                            </div>
                            <div class="emsp-admin-mobile-meta-item">
                                <span class="emsp-admin-mobile-meta-label">Inscrit le</span>
                                <span class="emsp-admin-mobile-meta-value"><?= date('d/m/Y', strtotime($u['created_at'])) ?></span>
                            </div>
                        </div>
                        <div class="emsp-admin-mobile-actions">
                            <?php if ($canManageTarget): ?>
                                <a href="edit-user.php?id=<?= (int) $u['id'] ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-pencil me-1"></i>Modifier
                                </a>
                            <?php endif; ?>
                            <?php if ($canManageTarget && $u['status'] === 'suspended'): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                    <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                    <input type="hidden" name="action" value="activate">
                                    <button type="submit"
                                            class="btn btn-sm btn-outline-success"
                                            data-confirm="Reactiver ce compte ?"
                                            data-confirm-detail="L utilisateur pourra a nouveau se connecter."
                                            data-confirm-type="info"
                                            data-confirm-ok="Oui, reactiver"
                                            data-emsp-confirm-auto="1">
                                        <i class="bi bi-check-lg me-1"></i>Reactiver
                                    </button>
                                </form>
                            <?php elseif ($canManageTarget && $u['status'] === 'active' && $u['id'] != $auth_user['id']): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                    <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                                    <input type="hidden" name="action" value="suspend">
                                    <button type="submit"
                                            class="btn btn-sm btn-outline-warning"
                                            data-confirm="Suspendre ce compte ?"
                                            data-confirm-detail="L utilisateur ne pourra plus se connecter."
                                            data-confirm-type="warning"
                                            data-confirm-ok="Oui, suspendre"
                                            data-emsp-confirm-auto="1">
                                        <i class="bi bi-pause-circle me-1"></i>Suspendre
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php if ($total_pages > 1): ?>
    <div class="card-footer d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2">
        <span class="text-body-secondary small">
            Page <strong><?= $page_num ?></strong> sur <?= $total_pages ?>
        </span>
        <nav aria-label="Pagination utilisateurs">
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item <?= $page_num <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= user_url(max(1, $page_num - 1)) ?>" aria-label="Precedent">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
                <?php
                $window = 2;
                $start_p = max(1, $page_num - $window);
                $end_p = min($total_pages, $page_num + $window);
                if ($start_p > 1): ?>
                    <li class="page-item"><a class="page-link" href="<?= user_url(1) ?>">1</a></li>
                    <?php if ($start_p > 2): ?>
                        <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                    <?php endif; ?>
                <?php endif;
                for ($p = $start_p; $p <= $end_p; $p++): ?>
                    <li class="page-item <?= $p === $page_num ? 'active' : '' ?>">
                        <a class="page-link" href="<?= user_url($p) ?>"><?= $p ?></a>
                    </li>
                <?php endfor;
                if ($end_p < $total_pages): ?>
                    <?php if ($end_p < $total_pages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                    <?php endif; ?>
                    <li class="page-item"><a class="page-link" href="<?= user_url($total_pages) ?>"><?= $total_pages ?></a></li>
                <?php endif; ?>
                <li class="page-item <?= $page_num >= $total_pages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= user_url(min($total_pages, $page_num + 1)) ?>" aria-label="Suivant">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

</div><!-- /#main-content -->
</div><!-- /#admin-content -->
<?php include __DIR__ . '/includes/footer.php'; ?>


