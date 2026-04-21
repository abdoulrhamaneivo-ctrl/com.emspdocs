<?php
ob_start();
include_once __DIR__ . '/bootstrap.php';
include_once __DIR__ . '/config/dbcon.php';
include_once __DIR__ . '/authentication.php';
include_once __DIR__ . '/../includes/csrf.php';
include_once __DIR__ . '/../includes/flash.php';

$statusMap = ['visible' => 'visible', 'hidden' => 'hidden', 'delete' => 'deleted'];
$sourceTableMap = [
    'document_comment' => 'comments',
    'document_reply' => 'comment_replies',
    'journal_comment' => 'journal_comments',
];
$sourceLabels = [
    'document_comment' => 'Commentaires documents',
    'document_reply' => 'Reponses documents',
    'journal_comment' => 'Commentaires journal',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['item_id'], $_POST['source_type'])) {
    verify_csrf_token();
    $itemId = (int) ($_POST['item_id'] ?? 0);
    $sourceType = trim((string) ($_POST['source_type'] ?? ''));
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($itemId > 0 && isset($sourceTableMap[$sourceType], $statusMap[$action])) {
        $table = $sourceTableMap[$sourceType];
        $newStatus = $statusMap[$action];
        $stmt = mysqli_prepare($con, "UPDATE {$table} SET status=? WHERE id=?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'si', $newStatus, $itemId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            flash_set('info', 'Moderation mise a jour', 'Le statut de l element a ete mis a jour.');
        } else {
            flash_set('error', 'Erreur technique', 'Impossible de mettre a jour cet element.');
        }
    }

    header('Location: comments-moderation.php?' . http_build_query([
        'status' => (string) ($_GET['status'] ?? 'visible'),
        'source' => (string) ($_GET['source'] ?? ''),
        'page' => (string) ($_GET['page'] ?? '1'),
    ]));
    exit(0);
}

$allowedStatuses = ['visible', 'hidden', 'pending', 'deleted'];
$fStatus = trim((string) ($_GET['status'] ?? 'visible'));
if ($fStatus !== '' && !in_array($fStatus, $allowedStatuses, true)) {
    $fStatus = 'visible';
}
$fSource = trim((string) ($_GET['source'] ?? ''));
if ($fSource !== '' && !isset($sourceLabels[$fSource])) {
    $fSource = '';
}
$pageNum = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;

$unionSql = "
    SELECT
        c.id,
        'document_comment' AS source_type,
        'Commentaire document' AS source_label,
        c.content,
        c.status,
        c.created_at,
        c.document_id AS target_id,
        d.title AS target_title,
        CONCAT('../document.php?id=', c.document_id, '#comment-', c.id) AS target_url,
        COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.email, 'Utilisateur') AS author_name
    FROM comments c
    INNER JOIN users u ON u.id = c.user_id
    INNER JOIN documents d ON d.id = c.document_id

    UNION ALL

    SELECT
        r.id,
        'document_reply' AS source_type,
        'Reponse document' AS source_label,
        r.content,
        r.status,
        r.created_at,
        d.id AS target_id,
        d.title AS target_title,
        CONCAT('../document.php?id=', d.id, '#comment-', c.id) AS target_url,
        COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.email, 'Utilisateur') AS author_name
    FROM comment_replies r
    INNER JOIN users u ON u.id = r.user_id
    INNER JOIN comments c ON c.id = r.comment_id
    INNER JOIN documents d ON d.id = c.document_id

    UNION ALL

    SELECT
        jc.id,
        'journal_comment' AS source_type,
        'Commentaire journal' AS source_label,
        jc.content,
        jc.status,
        jc.created_at,
        j.id AS target_id,
        j.title AS target_title,
        CONCAT('../news-article.php?id=', j.id) AS target_url,
        COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.email, 'Utilisateur') AS author_name
    FROM journal_comments jc
    INNER JOIN users u ON u.id = jc.user_id
    INNER JOIN journal j ON j.id = jc.journal_id
";

$where = ["1=1"];
$params = [];
$types = '';
if ($fStatus !== '') {
    $where[] = 'moderation.status = ?';
    $params[] = $fStatus;
    $types .= 's';
}
if ($fSource !== '') {
    $where[] = 'moderation.source_type = ?';
    $params[] = $fSource;
    $types .= 's';
}

$countSql = "SELECT COUNT(*) AS total FROM ({$unionSql}) moderation WHERE " . implode(' AND ', $where);
$countStmt = mysqli_prepare($con, $countSql);
$total = 0;
if ($countStmt) {
    if ($params) {
        mysqli_stmt_bind_param($countStmt, $types, ...$params);
    }
    mysqli_stmt_execute($countStmt);
    $row = emsp_stmt_fetch_assoc($countStmt);
    mysqli_stmt_close($countStmt);
    $total = (int) ($row['total'] ?? 0);
}

$sourceCounts = [];
$sourceCountSql = "SELECT moderation.source_type, COUNT(*) AS total FROM ({$unionSql}) moderation GROUP BY moderation.source_type";
$sourceCountStmt = mysqli_prepare($con, $sourceCountSql);
if ($sourceCountStmt) {
    mysqli_stmt_execute($sourceCountStmt);
    $rows = emsp_stmt_fetch_all($sourceCountStmt);
    mysqli_stmt_close($sourceCountStmt);
    foreach ($rows as $row) {
        $sourceCounts[(string) ($row['source_type'] ?? '')] = (int) ($row['total'] ?? 0);
    }
}

$totalPages = max(1, (int) ceil($total / $perPage));
$pageNum = min($pageNum, $totalPages);
$offset = ($pageNum - 1) * $perPage;

$listSql = "SELECT * FROM ({$unionSql}) moderation WHERE " . implode(' AND ', $where) . " ORDER BY moderation.created_at DESC LIMIT ? OFFSET ?";
$listStmt = mysqli_prepare($con, $listSql);
$items = [];
if ($listStmt) {
    $listParams = $params;
    $listTypes = $types . 'ii';
    $listParams[] = $perPage;
    $listParams[] = $offset;
    mysqli_stmt_bind_param($listStmt, $listTypes, ...$listParams);
    mysqli_stmt_execute($listStmt);
    $items = emsp_stmt_fetch_all($listStmt);
    mysqli_stmt_close($listStmt);
}

foreach ($items as &$item) {
    foreach (['content', 'target_title', 'author_name', 'source_label'] as $field) {
        if (isset($item[$field]) && is_string($item[$field])) {
            $item[$field] = emsp_fix_mojibake($item[$field]);
        }
    }
}
unset($item);

function emsp_comments_moderation_url(int $page): string
{
    $query = [
        'status' => (string) ($_GET['status'] ?? 'visible'),
        'source' => (string) ($_GET['source'] ?? ''),
        'page' => $page,
    ];
    return 'comments-moderation.php?' . http_build_query($query);
}

$statusClasses = [
    'visible' => 'badge-approved',
    'hidden' => 'badge-pending',
    'pending' => 'badge bg-secondary',
    'deleted' => 'badge-rejected',
];
$statusLabels = [
    'visible' => 'Visible',
    'hidden' => 'Masque',
    'pending' => 'En attente',
    'deleted' => 'Supprime',
];

$page_title = 'Moderation commentaires';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
include __DIR__ . '/includes/navbar-top.php';
?>
<div id="admin-content">
    <div id="main-content" class="container-fluid">

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0 fw-bold">
                <i class="bi bi-chat-dots me-2 text-primary"></i>Moderation des commentaires
                <span class="badge bg-secondary ms-2"><?= $total ?></span>
            </h5>
        </div>

        <div class="d-flex flex-wrap gap-2 mb-3">
            <?php foreach (['visible' => 'Visibles', 'hidden' => 'Masques', 'pending' => 'En attente', 'deleted' => 'Supprimes', '' => 'Tous'] as $value => $label): ?>
                <a href="comments-moderation.php?<?= http_build_query(['status' => $value, 'source' => $fSource]) ?>"
                   class="btn btn-sm <?= $fStatus === $value ? 'btn-primary' : 'btn-outline-secondary' ?>">
                    <?= h($label) ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="d-flex flex-wrap gap-2 mb-4">
            <a href="comments-moderation.php?<?= http_build_query(['status' => $fStatus, 'source' => '']) ?>"
               class="btn btn-sm <?= $fSource === '' ? 'btn-dark' : 'btn-outline-dark' ?>">
                Tous
            </a>
            <?php foreach ($sourceLabels as $sourceKey => $label): ?>
                <a href="comments-moderation.php?<?= http_build_query(['status' => $fStatus, 'source' => $sourceKey]) ?>"
                   class="btn btn-sm <?= $fSource === $sourceKey ? 'btn-emsp' : 'btn-outline-primary' ?>">
                    <?= h($label) ?>
                    <span class="ms-1 badge text-bg-light"><?= $sourceCounts[$sourceKey] ?? 0 ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="card shadow-sm">
            <div class="card-body p-0 d-none d-md-block">
                <div class="table-responsive">
                    <table class="table table-admin table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="ps-3">Source</th>
                                <th>Auteur</th>
                                <th>Commentaire</th>
                                <th>Cible</th>
                                <th>Statut</th>
                                <th>Date</th>
                                <th class="text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($items)): ?>
                            <tr><td colspan="7" class="text-center text-muted py-4">Aucun element a moderer.</td></tr>
                        <?php else: ?>
                            <?php foreach ($items as $item): ?>
                                <tr>
                                    <td class="ps-3 small">
                                        <span class="badge bg-light text-dark border"><?= h($item['source_label']) ?></span>
                                    </td>
                                    <td class="small fw-medium"><?= h($item['author_name']) ?></td>
                                    <td class="emsp-table-cell-320">
                                        <span class="d-block small" title="<?= h($item['content']) ?>">
                                            <?= h(mb_strlen((string) $item['content']) > 180 ? (mb_substr((string) $item['content'], 0, 180) . '...') : (string) $item['content']) ?>
                                        </span>
                                    </td>
                                    <td class="emsp-table-cell-220">
                                        <a href="<?= h((string) ($item['target_url'] ?? '#')) ?>"
                                           class="text-truncate d-block small text-decoration-none"
                                           title="<?= h((string) ($item['target_title'] ?? '')) ?>"
                                           target="_blank" rel="noopener">
                                            <?= h((string) ($item['target_title'] ?? 'Element cible')) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="<?= h($statusClasses[(string) ($item['status'] ?? '')] ?? 'badge bg-secondary') ?>">
                                            <?= h($statusLabels[(string) ($item['status'] ?? '')] ?? (string) ($item['status'] ?? '')) ?>
                                        </span>
                                    </td>
                                    <td class="text-muted small"><?= date('d/m/Y H:i', strtotime((string) ($item['created_at'] ?? 'now'))) ?></td>
                                    <td class="text-center">
                                        <div class="d-flex gap-1 justify-content-center">
                                            <?php if (($item['status'] ?? '') !== 'visible'): ?>
                                                <form method="POST">
                                                    <?php csrf_input(); ?>
                                                    <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                                    <input type="hidden" name="source_type" value="<?= h((string) $item['source_type']) ?>">
                                                    <input type="hidden" name="action" value="visible">
                                                    <button type="submit" class="btn btn-sm btn-outline-success" title="Rendre visible">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if (($item['status'] ?? '') === 'visible'): ?>
                                                <form method="POST">
                                                    <?php csrf_input(); ?>
                                                    <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                                    <input type="hidden" name="source_type" value="<?= h((string) $item['source_type']) ?>">
                                                    <input type="hidden" name="action" value="hidden">
                                                    <button type="submit" class="btn btn-sm btn-outline-warning" title="Masquer">
                                                        <i class="bi bi-eye-slash"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if (($item['status'] ?? '') !== 'deleted'): ?>
                                                <form method="POST">
                                                    <?php csrf_input(); ?>
                                                    <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                                    <input type="hidden" name="source_type" value="<?= h((string) $item['source_type']) ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <button type="submit"
                                                            class="btn btn-sm btn-outline-danger"
                                                            title="Supprimer"
                                                            data-confirm="Supprimer cet element ?"
                                                            data-confirm-detail="Cette action est irreversible."
                                                            data-confirm-type="danger"
                                                            data-confirm-ok="Oui, supprimer"
                                                            data-emsp-confirm-auto="1">
                                                        <i class="bi bi-trash"></i>
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
            </div>
            <div class="card-body d-md-none">
                <?php if (empty($items)): ?>
                    <div class="emsp-admin-mobile-empty">Aucun element a moderer.</div>
                <?php else: ?>
                    <div class="emsp-admin-mobile-list">
                        <?php foreach ($items as $item): ?>
                            <div class="emsp-admin-mobile-card">
                                <div class="emsp-admin-mobile-card-header">
                                    <div>
                                        <h3 class="emsp-admin-mobile-card-title mb-0"><?= h((string) ($item['target_title'] ?? 'Element cible')) ?></h3>
                                        <div class="emsp-admin-mobile-card-subtitle"><?= h((string) ($item['author_name'] ?? 'Utilisateur')) ?></div>
                                    </div>
                                    <span class="<?= h($statusClasses[(string) ($item['status'] ?? '')] ?? 'badge bg-secondary') ?>">
                                        <?= h($statusLabels[(string) ($item['status'] ?? '')] ?? (string) ($item['status'] ?? '')) ?>
                                    </span>
                                </div>
                                <div class="emsp-admin-mobile-meta">
                                    <div class="emsp-admin-mobile-meta-item">
                                        <span class="emsp-admin-mobile-meta-label">Source</span>
                                        <span class="emsp-admin-mobile-meta-value"><?= h((string) ($item['source_label'] ?? '')) ?></span>
                                    </div>
                                    <div class="emsp-admin-mobile-meta-item">
                                        <span class="emsp-admin-mobile-meta-label">Date</span>
                                        <span class="emsp-admin-mobile-meta-value"><?= date('d/m/Y H:i', strtotime((string) ($item['created_at'] ?? 'now'))) ?></span>
                                    </div>
                                    <div class="emsp-admin-mobile-meta-item">
                                        <span class="emsp-admin-mobile-meta-label">Commentaire</span>
                                        <span class="emsp-admin-mobile-meta-value"><?= h(mb_strlen((string) ($item['content'] ?? '')) > 220 ? (mb_substr((string) ($item['content'] ?? ''), 0, 220) . '...') : (string) ($item['content'] ?? '')) ?></span>
                                    </div>
                                </div>
                                <div class="emsp-admin-mobile-actions">
                                    <a href="<?= h((string) ($item['target_url'] ?? '#')) ?>" class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener">
                                        <i class="bi bi-box-arrow-up-right me-1"></i>Ouvrir
                                    </a>
                                    <?php if (($item['status'] ?? '') !== 'visible'): ?>
                                        <form method="POST" class="d-inline">
                                            <?php csrf_input(); ?>
                                            <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                            <input type="hidden" name="source_type" value="<?= h((string) $item['source_type']) ?>">
                                            <input type="hidden" name="action" value="visible">
                                            <button type="submit" class="btn btn-sm btn-outline-success">
                                                <i class="bi bi-eye me-1"></i>Visible
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if (($item['status'] ?? '') === 'visible'): ?>
                                        <form method="POST" class="d-inline">
                                            <?php csrf_input(); ?>
                                            <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                            <input type="hidden" name="source_type" value="<?= h((string) $item['source_type']) ?>">
                                            <input type="hidden" name="action" value="hidden">
                                            <button type="submit" class="btn btn-sm btn-outline-warning">
                                                <i class="bi bi-eye-slash me-1"></i>Masquer
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if (($item['status'] ?? '') !== 'deleted'): ?>
                                        <form method="POST" class="d-inline">
                                            <?php csrf_input(); ?>
                                            <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                                            <input type="hidden" name="source_type" value="<?= h((string) $item['source_type']) ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <button type="submit"
                                                    class="btn btn-sm btn-outline-danger"
                                                    data-confirm="Supprimer cet element ?"
                                                    data-confirm-detail="Cette action est irreversible."
                                                    data-confirm-type="danger"
                                                    data-confirm-ok="Oui, supprimer"
                                                    data-emsp-confirm-auto="1">
                                                <i class="bi bi-trash me-1"></i>Supprimer
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="card-footer bg-white">
                    <nav>
                        <ul class="pagination pagination-sm justify-content-center mb-0">
                            <li class="page-item <?= $pageNum <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= h(emsp_comments_moderation_url($pageNum - 1)) ?>"><i class="bi bi-chevron-left"></i></a>
                            </li>
                            <?php for ($page = 1; $page <= $totalPages; $page++): ?>
                                <li class="page-item <?= $page === $pageNum ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= h(emsp_comments_moderation_url($page)) ?>"><?= $page ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?= $pageNum >= $totalPages ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= h(emsp_comments_moderation_url($pageNum + 1)) ?>"><i class="bi bi-chevron-right"></i></a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>


