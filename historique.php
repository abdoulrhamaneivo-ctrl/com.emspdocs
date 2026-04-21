<?php
include_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['auth'])) {
    $_SESSION['message'] = 'Connectez-vous pour voir votre historique.';
    header('Location: login.php'); exit(0);
}

include_once __DIR__ . '/admin/config/dbcon.php';
include_once __DIR__ . '/includes/pagination.php';

$uid      = intval($_SESSION['auth_user']['id']);
$filter   = trim($_GET['action'] ?? '');
$page_num = max(1, intval($_GET['page'] ?? 1));
$per_page = 15;

$where  = "WHERE h.user_id = ?";
$params = [$uid];
$types  = 'i';

if ($filter === 'view' || $filter === 'download') {
    $where   .= " AND h.action = ?";
    $params[] = $filter;
    $types   .= 's';
}

// Total
$cs = mysqli_prepare($con,
    "SELECT COUNT(DISTINCT h.document_id, h.action)
     FROM history h $where");
mysqli_stmt_bind_param($cs, $types, ...$params);
mysqli_stmt_execute($cs);
mysqli_stmt_bind_result($cs, $total);
mysqli_stmt_fetch($cs); mysqli_stmt_close($cs);

$total_pages = ceil($total / $per_page);
$offset      = ($page_num - 1) * $per_page;

$p2 = $params; $t2 = $types;
$p2[] = $per_page; $p2[] = $offset; $t2 .= 'ii';

$stmt = mysqli_prepare($con,
    "SELECT h.action, MAX(h.created_at) AS last_date,
            d.id AS doc_id, d.title, d.doc_type, d.status,
            u.first_name, u.last_name
     FROM history h
     JOIN documents d ON d.id = h.document_id
     JOIN users u ON u.id = d.uploader_id
     $where
     GROUP BY h.document_id, h.action
     ORDER BY last_date DESC
     LIMIT ? OFFSET ?");
mysqli_stmt_bind_param($stmt, $t2, ...$p2);
mysqli_stmt_execute($stmt);
$history = emsp_stmt_fetch_all($stmt);
mysqli_stmt_close($stmt);
 $history_count = count($history);

function hist_url($page) {
    $q = $_GET; $q['page'] = $page;
    return 'historique.php?' . http_build_query($q);
}

$page_title = 'Mon Historique';
include __DIR__ . '/includes/header.php';
?>
<style>
@media (max-width: 767.98px) {
    .history-mobile-list {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .history-mobile-card {
        border-radius: 1.05rem;
        border: 1px solid rgba(26, 60, 110, 0.08);
        box-shadow: 0 14px 28px rgba(26, 60, 110, 0.06);
    }

    .history-mobile-title {
        font-size: 1rem;
        font-weight: 800;
        color: #163560;
        line-height: 1.35;
    }

    .history-mobile-meta {
        display: flex;
        flex-wrap: wrap;
        gap: .5rem;
    }

    .history-mobile-date {
        color: #66758f;
        font-size: .84rem;
    }
}
</style>

<section class="page-header">
    <div class="container">
        <h1>Mon Historique</h1>
        <p class="mb-0">Documents consultÃ©s et tÃ©lÃ©chargÃ©s</p>
    </div>
</section>

<section class="section-pad">
<div class="container">

    <?php include __DIR__ . '/message.php'; ?>

    <!-- Filtres -->
    <div class="d-flex gap-2 mb-4">
        <a href="historique.php"
           class="btn btn-sm <?= $filter==='' ? 'btn-primary' : 'btn-outline-secondary' ?>">
            Tout
        </a>
        <a href="historique.php?action=view"
           class="btn btn-sm <?= $filter==='view' ? 'btn-primary' : 'btn-outline-secondary' ?>">
            <i class="bi bi-eye me-1"></i>ConsultÃ©s
        </a>
        <a href="historique.php?action=download"
           class="btn btn-sm <?= $filter==='download' ? 'btn-primary' : 'btn-outline-secondary' ?>">
            <i class="bi bi-download me-1"></i>TÃ©lÃ©chargÃ©s
        </a>
    </div>

    <?php if ($history_count === 0): ?>
        <div class="card shadow-sm">
            <div class="card-body text-center py-5 text-muted">
                <i class="bi bi-clock-history fs-1 d-block mb-2"></i>
                Aucune activitÃ© enregistrÃ©e pour le moment.
            </div>
        </div>
    <?php else: ?>

        <div class="d-md-none history-mobile-list">
            <?php foreach ($history as $h): ?>
                <?php
                $tc = ['cours'=>'bg-primary','td'=>'bg-success',
                       'correction'=>'bg-info text-dark',
                       'concours'=>'bg-warning text-dark','examen'=>'bg-danger'];
                ?>
                <div class="card shadow-sm history-mobile-card">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between gap-3 align-items-start">
                            <div class="min-w-0 flex-grow-1">
                                <div class="history-mobile-title mb-1"><?= htmlspecialchars($h['title']) ?></div>
                                <div class="text-muted small mb-2">
                                    <i class="bi bi-person me-1"></i><?= htmlspecialchars($h['first_name'] . ' ' . $h['last_name']) ?>
                                </div>
                            </div>
                            <?php if ($h['status'] === 'approved'): ?>
                                <a href="document.php?id=<?= $h['doc_id'] ?>" class="btn btn-sm btn-outline-primary flex-shrink-0" aria-label="Ouvrir le document">
                                    <i class="bi bi-arrow-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                        <div class="history-mobile-meta mb-2">
                            <span class="badge <?= $tc[$h['doc_type']] ?? 'bg-secondary' ?>">
                                <?= ucfirst($h['doc_type']) ?>
                            </span>
                            <?php if ($h['action'] === 'download'): ?>
                                <span class="badge emsp-history-action-download">
                                    <i class="bi bi-download me-1"></i>TÃ©lÃ©chargÃ©
                                </span>
                            <?php else: ?>
                                <span class="badge emsp-history-action-view">
                                    <i class="bi bi-eye me-1"></i>ConsultÃ©
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="history-mobile-date">
                            <i class="bi bi-calendar-event me-1"></i><?= date('d/m/Y H:i', strtotime($h['last_date'])) ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="card shadow-sm d-none d-md-block">
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="emsp-history-table-head">
                        <tr>
                            <th class="ps-3">Document</th>
                            <th>Auteur</th>
                            <th>Type</th>
                            <th>Action</th>
                            <th>Date</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($history as $h): ?>
                        <tr>
                            <td class="ps-3 fw-medium emsp-history-title-cell">
                                <span class="text-truncate d-block"
                                      title="<?= htmlspecialchars($h['title']) ?>">
                                    <?= htmlspecialchars($h['title']) ?>
                                </span>
                            </td>
                            <td class="text-muted small">
                                <?= htmlspecialchars($h['first_name'] . ' ' . $h['last_name']) ?>
                            </td>
                            <td>
                                <?php
                                $tc = ['cours'=>'bg-primary','td'=>'bg-success',
                                       'correction'=>'bg-info text-dark',
                                       'concours'=>'bg-warning text-dark','examen'=>'bg-danger'];
                                ?>
                                <span class="badge <?= $tc[$h['doc_type']] ?? 'bg-secondary' ?>">
                                    <?= ucfirst($h['doc_type']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($h['action'] === 'download'): ?>
                                    <span class="badge emsp-history-action-download">
                                        <i class="bi bi-download me-1"></i>TÃ©lÃ©chargÃ©
                                    </span>
                                <?php else: ?>
                                    <span class="badge emsp-history-action-view">
                                        <i class="bi bi-eye me-1"></i>ConsultÃ©
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted small">
                                <?= date('d/m/Y H:i', strtotime($h['last_date'])) ?>
                            </td>
                            <td>
                                <?php if ($h['status'] === 'approved'): ?>
                                    <a href="document.php?id=<?= $h['doc_id'] ?>"
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-arrow-right"></i>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <nav class="mt-3">
            <ul class="pagination justify-content-center">
                <li class="page-item <?= $page_num<=1 ? 'disabled':'' ?>">
                    <a class="page-link" href="<?= hist_url($page_num-1) ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
                <?php for ($p=1; $p<=$total_pages; $p++): ?>
                    <li class="page-item <?= $p===$page_num ? 'active':'' ?>">
                        <a class="page-link" href="<?= hist_url($p) ?>"><?= $p ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?= $page_num>=$total_pages ? 'disabled':'' ?>">
                    <a class="page-link" href="<?= hist_url($page_num+1) ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>

    <?php endif; ?>

    <div class="mt-3">
        <a href="mon-profil.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Retour au profil
        </a>
    </div>

</div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>



