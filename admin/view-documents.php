<?php
ob_start();
include_once __DIR__ . '/bootstrap.php';
include_once __DIR__ . '/config/dbcon.php';
include_once __DIR__ . '/authentication.php';
include_once __DIR__ . '/../includes/csrf.php';
include_once __DIR__ . '/../includes/flash.php';

// â€” Action rapide : changer statut â€”
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['doc_id'])) {
    verify_csrf_token();
    $doc_id = intval($_POST['doc_id']);
    $action = $_POST['action'];

    if ($action === 'toggle_public') {
        $s = mysqli_prepare($con,
            "UPDATE documents SET is_public = IF(is_public=1,0,1) WHERE id=?");
        mysqli_stmt_bind_param($s, 'i', $doc_id);
        mysqli_stmt_execute($s); mysqli_stmt_close($s);
        flash_set(
            'info',
            'VisibilitÃ© mise Ã  jour',
            'La visibilitÃ© publique du document a Ã©tÃ© modifiÃ©e.'
        );
    } elseif ($action === 'delete') {
        // RÃ©cupÃ©rer le chemin avant suppression
        $s = mysqli_prepare($con, "SELECT file_path, title FROM documents WHERE id=? LIMIT 1");
        mysqli_stmt_bind_param($s, 'i', $doc_id);
        mysqli_stmt_execute($s);
        $r = emsp_stmt_fetch_assoc($s);
        mysqli_stmt_close($s);
        if ($r && !empty($r['file_path'])) {
            $real = __DIR__ . '/../uploads/documents/' . basename((string) $r['file_path']);
            if (is_file($real)) {
                @unlink($real);
            }
        }
        $s = mysqli_prepare($con, "DELETE FROM documents WHERE id=?");
        mysqli_stmt_bind_param($s, 'i', $doc_id);
        mysqli_stmt_execute($s); mysqli_stmt_close($s);
        log_audit($con, (int) $auth_user['id'], 'document_deleted', 'document', $doc_id, (string) ($r['title'] ?? ''));
        $title = $r['title'] ?? 'Document';
        flash_set(
            'info',
            'Document supprimÃ©',
            'Le document "' . $title . '" a Ã©tÃ© supprimÃ© dÃ©finitivement. Cette action est irrÃ©versible.'
        );
    }
    header('Location: view-documents.php'); exit(0);
}

// â€” Filtres â€”
$f_status  = trim($_GET['status']   ?? '');
$f_type    = trim($_GET['doc_type'] ?? '');
$search    = trim($_GET['q']        ?? '');
$page_num  = max(1, intval($_GET['page'] ?? 1));
$per_page  = 20;

$where  = "WHERE 1=1";
$params = []; $types = '';

if ($f_status !== '') { $where .= " AND d.status=?";   $params[] = $f_status; $types .= 's'; }
if ($f_type   !== '') { $where .= " AND d.doc_type=?"; $params[] = $f_type;   $types .= 's'; }
if ($search   !== '') {
    $like = '%'.$search.'%';
    $where .= " AND (d.title LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types .= 'sss';
}

// Total
$cs = mysqli_prepare($con,
    "SELECT COUNT(*) FROM documents d JOIN users u ON u.id=d.uploader_id $where");
if ($params) mysqli_stmt_bind_param($cs, $types, ...$params);
mysqli_stmt_execute($cs);
mysqli_stmt_bind_result($cs, $total);
mysqli_stmt_fetch($cs); mysqli_stmt_close($cs);

$total_pages = ceil($total / $per_page);
$offset = ($page_num - 1) * $per_page;

$p2 = $params; $t2 = $types;
$p2[] = $per_page; $p2[] = $offset; $t2 .= 'ii';

$stmt = mysqli_prepare($con,
    "SELECT d.id, d.title, d.doc_type, d.status, d.is_public,
            d.file_size_bytes, d.download_count, d.created_at,
            d.rejection_reason,
            u.first_name, u.last_name
     FROM documents d
     JOIN users u ON u.id = d.uploader_id
     $where
     ORDER BY d.created_at DESC
     LIMIT ? OFFSET ?");
mysqli_stmt_bind_param($stmt, $t2, ...$p2);
mysqli_stmt_execute($stmt);
$docs = emsp_stmt_fetch_all($stmt);
mysqli_stmt_close($stmt);
$docs_count = count($docs);
foreach ($docs as &$d) {
    foreach (['title','first_name','last_name','rejection_reason'] as $f) {
        if (isset($d[$f]) && is_string($d[$f])) {
            $d[$f] = emsp_fix_mojibake($d[$f]);
        }
    }
}
unset($d);

function doc_url($p) {
    $q = $_GET; $q['page'] = $p;
    return 'view-documents.php?' . http_build_query($q);
}

$docTypeClasses = [
    'cours' => 'bg-primary',
    'td' => 'bg-success',
    'correction' => 'bg-info text-dark',
    'concours' => 'bg-warning text-dark',
    'examen' => 'bg-danger',
];
$docTypeLabels = [
    'cours' => 'Cours',
    'td' => 'TD',
    'correction' => 'Correction',
    'concours' => 'Concours',
    'examen' => 'Examen',
];
$docTypeIcons = [
    'cours' => 'bi-journal-bookmark-fill',
    'td' => 'bi-pencil-square',
    'correction' => 'bi-check2-square',
    'concours' => 'bi-trophy-fill',
    'examen' => 'bi-patch-question-fill',
];
$docThumbClasses = [
    'cours' => 'primary',
    'td' => 'success',
    'correction' => 'info',
    'concours' => 'warning',
    'examen' => 'danger',
];
$statusClasses = ['pending'=>'badge-pending','approved'=>'badge-approved','rejected'=>'badge-rejected'];
$statusLabels = ['pending'=>'En attente','approved'=>'Approuve','rejected'=>'Rejete'];

$page_title = 'Tous les documents';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<?php include __DIR__ . '/includes/navbar-top.php'; ?>
<div id="admin-content">
<div id="main-content" class="container-fluid">

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0 fw-bold">
        <i class="bi bi-files me-2 text-primary"></i>Tous les documents
        <span class="badge bg-secondary ms-2"><?= $total ?></span>
    </h5>
</div>

<!-- Filtres -->
<div class="card shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <input type="text" class="form-control form-control-sm" name="q"
                       placeholder="Titre ou auteur..."
                       value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-md-2">
                <select class="form-select form-select-sm" name="status">
                    <option value="">Tous statuts</option>
                    <option value="pending"  <?= $f_status==='pending'  ? 'selected':'' ?>>En attente</option>
                    <option value="approved" <?= $f_status==='approved' ? 'selected':'' ?>>ApprouvÃ©</option>
                    <option value="rejected" <?= $f_status==='rejected' ? 'selected':'' ?>>RejetÃ©</option>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select form-select-sm" name="doc_type">
                    <option value="">Tous types</option>
                    <?php foreach (['cours','td','correction','concours','examen'] as $t): ?>
                        <option value="<?= $t ?>" <?= $f_type===$t ? 'selected':'' ?>><?= ucfirst($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-sm btn-primary w-100">
                    <i class="bi bi-search me-1"></i>Filtrer
                </button>
            </div>
            <div class="col-md-2">
                <a href="view-documents.php" class="btn btn-sm btn-outline-secondary w-100">RÃ©initialiser</a>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <?php if ($docs_count === 0): ?>
    <div class="card-body text-center text-muted py-4">
        Aucun document trouve.
    </div>
    <?php else: ?>
    <div class="d-md-none emsp-doc-admin-mobile-list">
        <?php foreach ($docs as $d): ?>
            <?php
            $typeLabel = $docTypeLabels[$d['doc_type']] ?? ucfirst((string) $d['doc_type']);
            $typeClass = $docTypeClasses[$d['doc_type']] ?? 'bg-secondary';
            $typeIcon = $docTypeIcons[$d['doc_type']] ?? 'bi-file-earmark-text-fill';
            $thumbClass = $docThumbClasses[$d['doc_type']] ?? 'neutral';
            ?>
            <article class="emsp-doc-admin-mobile-item">
                <div class="emsp-doc-admin-mobile-head">
                    <span class="emsp-doc-row-thumb emsp-doc-row-thumb--<?= htmlspecialchars($thumbClass) ?>">
                        <i class="bi <?= htmlspecialchars($typeIcon) ?>"></i>
                        <span class="emsp-doc-row-thumb-label"><?= htmlspecialchars($typeLabel) ?></span>
                    </span>
                    <div class="emsp-doc-admin-mobile-body">
                        <div class="emsp-doc-admin-mobile-title"><?= htmlspecialchars($d['title']) ?></div>
                        <div class="emsp-doc-admin-mobile-subtitle"><?= htmlspecialchars($d['first_name'] . ' ' . $d['last_name']) ?></div>
                        <div class="emsp-doc-admin-mobile-badges">
                            <span class="badge <?= $typeClass ?>"><?= htmlspecialchars($typeLabel) ?></span>
                            <span class="badge <?= $statusClasses[$d['status']] ?? 'bg-secondary' ?>"><?= htmlspecialchars($statusLabels[$d['status']] ?? (string) $d['status']) ?></span>
                            <span class="badge <?= !empty($d['is_public']) ? 'bg-success-subtle text-success-emphasis' : 'bg-secondary-subtle text-secondary-emphasis' ?>">
                                <?= !empty($d['is_public']) ? 'Public' : 'Prive' ?>
                            </span>
                        </div>
                        <div class="emsp-doc-admin-mobile-meta">
                            <span><?= round($d['file_size_bytes'] / 1024) ?> Ko â€¢ <?= (int) $d['download_count'] ?> telechargements</span>
                            <span><?= date('d/m/Y', strtotime($d['created_at'])) ?></span>
                            <?php if ($d['rejection_reason']): ?>
                                <span class="text-danger">Motif : <?= htmlspecialchars($d['rejection_reason']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="emsp-doc-admin-mobile-actions">
                    <a href="preview-doc.php?id=<?= $d['id'] ?>"
                       class="btn btn-sm btn-outline-secondary" title="Previsualiser" target="_blank" rel="noopener">
                        <i class="bi bi-eye"></i>
                    </a>
                    <form method="post"
                          action="../telecharger.php?id=<?= $d['id'] ?>&download=1"
                          target="_blank"
                          class="d-inline m-0">
                        <?php csrf_input(); ?>
                        <button type="submit" class="btn btn-sm btn-outline-primary" title="Telecharger">
                            <i class="bi bi-download"></i>
                        </button>
                    </form>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <input type="hidden" name="doc_id" value="<?= $d['id'] ?>">
                        <input type="hidden" name="action" value="toggle_public">
                        <button type="submit"
                                class="btn btn-sm <?= $d['is_public'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                title="<?= $d['is_public'] ? 'Rendre prive' : 'Rendre public' ?>"
                                data-emsp-confirm-auto="1"
                                data-confirm="Changer la visibilite publique ?"
                                data-confirm-detail="Le document basculera entre le mode public et prive."
                                data-confirm-type="warning"
                                data-confirm-ok="Confirmer">
                            <i class="bi <?= $d['is_public'] ? 'bi-lock' : 'bi-globe' ?>"></i>
                        </button>
                    </form>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <input type="hidden" name="doc_id" value="<?= $d['id'] ?>">
                        <input type="hidden" name="action" value="delete">
                        <button type="submit"
                                class="btn btn-sm btn-outline-danger"
                                title="Supprimer"
                                data-confirm="Supprimer ce document ?"
                                data-confirm-detail="Le fichier sera supprime definitivement. Cette action est irreversible."
                                data-confirm-type="danger"
                                data-confirm-ok="Oui, supprimer"
                                data-emsp-confirm-auto="1">
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <div class="card-body p-0 d-none d-md-block">
        <table class="table table-admin table-hover mb-0">
            <thead>
                <tr>
                    <th class="ps-3">#</th>
                    <th>Titre</th>
                    <th>Auteur</th>
                    <th>Type</th>
                    <th>Statut</th>
                    <th>Public</th>
                    <th>Taille</th>
                    <th><i class="bi bi-download"></i></th>
                    <th>Date</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (false): ?>
                <tr><td colspan="10" class="text-center text-muted py-4">Aucun document trouvÃ©.</td></tr>
            <?php endif; ?>
            <?php foreach ($docs as $d): ?>
                <tr>
                    <td class="ps-3 text-muted small"><?= $d['id'] ?></td>
                    <td class="emsp-table-cell-180">
                        <span class="d-block text-truncate fw-medium"
                              title="<?= htmlspecialchars($d['title']) ?>">
                            <?= htmlspecialchars($d['title']) ?>
                        </span>
                        <?php if ($d['rejection_reason']): ?>
                            <small class="text-danger" title="<?= htmlspecialchars($d['rejection_reason']) ?>">
                                <i class="bi bi-exclamation-circle me-1"></i>RejetÃ©
                            </small>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted small emsp-nowrap">
                        <?= htmlspecialchars($d['first_name'] . ' ' . $d['last_name']) ?>
                    </td>
                    <td>
                        <?php
                        $tc = ['cours'=>'bg-primary','td'=>'bg-success','correction'=>'bg-info text-dark',
                               'concours'=>'bg-warning text-dark','examen'=>'bg-danger'];
                        ?>
                        <span class="badge <?= $tc[$d['doc_type']] ?? 'bg-secondary' ?>">
                            <?= ucfirst($d['doc_type']) ?>
                        </span>
                    </td>
                    <td>
                        <?php
                        $sc = ['pending'=>'badge-pending','approved'=>'badge-approved','rejected'=>'badge-rejected'];
                        $sl = ['pending'=>'En attente','approved'=>'ApprouvÃ©','rejected'=>'RejetÃ©'];
                        ?>
                        <span class="badge <?= $sc[$d['status']] ?? 'bg-secondary' ?>">
                            <?= $sl[$d['status']] ?? $d['status'] ?>
                        </span>
                    </td>
                    <td class="text-center">
                        <?php if ($d['is_public']): ?>
                            <i class="bi bi-globe text-success" title="Public"></i>
                        <?php else: ?>
                            <i class="bi bi-lock text-muted" title="PrivÃ©"></i>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted small">
                        <?= round($d['file_size_bytes'] / 1024) ?> Ko
                    </td>
                    <td class="text-muted small text-center"><?= $d['download_count'] ?></td>
                    <td class="text-muted small">
                        <?= date('d/m/Y', strtotime($d['created_at'])) ?>
                    </td>
                    <td class="text-center">
                        <div class="d-flex gap-1 justify-content-center">
                            <!-- TÃ©lÃ©charger -->
                            <a href="preview-doc.php?id=<?= $d['id'] ?>"
                               class="btn btn-sm btn-outline-secondary" title="Previsualiser" target="_blank" rel="noopener">
                                <i class="bi bi-eye"></i>
                            </a>
                            <form method="post"
                                  action="../telecharger.php?id=<?= $d['id'] ?>&download=1"
                                  target="_blank"
                                  class="d-inline m-0">
                                <?php csrf_input(); ?>
                                <button type="submit"
                                        class="btn btn-sm btn-outline-primary"
                                        title="Telecharger">
                                    <i class="bi bi-download"></i>
                                </button>
                            </form>
                            <!-- Toggle public -->
                            <form method="POST"
                                  >
                                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                <input type="hidden" name="doc_id" value="<?= $d['id'] ?>">
                                <input type="hidden" name="action" value="toggle_public">
                                <button type="submit"
                                        class="btn btn-sm <?= $d['is_public'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                        title="<?= $d['is_public'] ? 'Rendre privÃ©' : 'Rendre public' ?>"
                                        data-emsp-confirm-auto="1"
                                        data-confirm="Changer la visibilite publique ?"
                                        data-confirm-detail="Le document basculera entre le mode public et prive."
                                        data-confirm-type="warning"
                                        data-confirm-ok="Confirmer">
                                    <i class="bi <?= $d['is_public'] ? 'bi-lock' : 'bi-globe' ?>"></i>
                                </button>
                            </form>
                            <!-- Supprimer -->
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                <input type="hidden" name="doc_id" value="<?= $d['id'] ?>">
                                <input type="hidden" name="action" value="delete">
                                <button type="submit"
                                        class="btn btn-sm btn-outline-danger"
                                        title="Supprimer"
                                        data-confirm="Supprimer ce document ?"
                                        data-confirm-detail="Le fichier sera supprimÃ© dÃ©finitivement. Cette action est irrÃ©versible."
                                        data-confirm-type="danger"
                                        data-confirm-ok="Oui, supprimer"
                                        data-emsp-confirm-auto="1">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="card-footer bg-white">
        <nav><ul class="pagination pagination-sm justify-content-center mb-0">
            <li class="page-item <?= $page_num<=1 ? 'disabled':'' ?>">
                <a class="page-link" href="<?= doc_url($page_num-1) ?>">
                    <i class="bi bi-chevron-left"></i>
                </a>
            </li>
            <?php for ($p=1; $p<=$total_pages; $p++): ?>
                <li class="page-item <?= $p===$page_num ? 'active':'' ?>">
                    <a class="page-link" href="<?= doc_url($p) ?>"><?= $p ?></a>
                </li>
            <?php endfor; ?>
            <li class="page-item <?= $page_num>=$total_pages ? 'disabled':'' ?>">
                <a class="page-link" href="<?= doc_url($page_num+1) ?>">
                    <i class="bi bi-chevron-right"></i>
                </a>
            </li>
        </ul></nav>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

</div><!-- /#main-content -->
</div><!-- /#admin-content -->
<?php include __DIR__ . '/includes/footer.php'; ?>


