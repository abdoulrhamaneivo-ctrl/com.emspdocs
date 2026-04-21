<?php
ob_start();
include_once __DIR__ . '/bootstrap.php';

include_once __DIR__ . '/config/dbcon.php';
include_once __DIR__ . '/authentication.php';
include_once __DIR__ . '/../includes/csrf.php';
include_once __DIR__ . '/../includes/flash.php';
include_once __DIR__ . '/../includes/journal_helpers.php';
include_once __DIR__ . '/../includes/notif-helper.php';

$allowedTypes = ['annonce', 'defi', 'sondage'];
$allowedStatus = ['published', 'draft'];
$allowedStates = ['open', 'scheduled', 'closed', 'expired', 'draft'];
$hasAdmin = mysqli_fetch_assoc(mysqli_query($con, "SHOW COLUMNS FROM journal LIKE 'admin_id'")) ? true : false;
$hasAuthor = mysqli_fetch_assoc(mysqli_query($con, "SHOW COLUMNS FROM journal LIKE 'author_id'")) ? true : false;
$authorColumn = $hasAdmin ? 'admin_id' : ($hasAuthor ? 'author_id' : '');
$hasStartsAt = emsp_journal_has_column($con, 'starts_at');
$hasEndsAt = emsp_journal_has_column($con, 'ends_at');
$hasClosedAt = emsp_journal_has_column($con, 'closed_at');
$hasClosedBy = emsp_journal_has_column($con, 'closed_by');
$hasLifecycle = $hasStartsAt && $hasEndsAt;

function emsp_journal_redirect_with_filters(): void
{
    $qs = [];
    foreach (['type', 'status', 'state'] as $key) {
        if (!empty($_GET[$key])) {
            $qs[$key] = (string) $_GET[$key];
        }
    }
    $redirect = 'journal.php';
    if (!empty($qs)) {
        $redirect .= '?' . http_build_query($qs);
    }
    header('Location: ' . $redirect);
    exit;
}

if (isset($_GET['details_id']) && strtolower((string) ($_GET['format'] ?? '')) === 'json') {
    $detailsId = (int) ($_GET['details_id'] ?? 0);
    $details = $detailsId > 0 ? emsp_journal_fetch_admin_details($con, $detailsId) : null;
    header('Content-Type: application/json; charset=UTF-8');
    if (!$details) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'notfound']);
        exit;
    }
    $details['article']['content_html'] = emsp_journal_clean_html((string) ($details['article']['content'] ?? ''));
    echo json_encode(['ok' => true, 'details' => $details], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (isset($_GET['export_defi_id'])) {
    $exportId = (int) ($_GET['export_defi_id'] ?? 0);
    $details = $exportId > 0 ? emsp_journal_fetch_admin_details($con, $exportId) : null;
    if (!$details || ($details['type'] ?? '') !== 'defi') {
        flash_set('danger', 'Export impossible', 'Le defi demande est introuvable.');
        emsp_journal_redirect_with_filters();
    }

    $safeName = preg_replace('/[^a-z0-9]+/i', '-', strtolower((string) ($details['article']['title'] ?? 'defi')));
    $safeName = trim($safeName, '-');
    if ($safeName === '') {
        $safeName = 'defi';
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="participants-' . $safeName . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Nom', 'Email', 'Note', 'Date']);
    foreach (($details['defi']['participants'] ?? []) as $participant) {
        fputcsv($out, [
            (string) ($participant['name'] ?? ''),
            (string) ($participant['email'] ?? ''),
            (string) ($participant['note'] ?? ''),
            (string) ($participant['created_at_label'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'], $_POST['new_status'])) {
    verify_csrf_token();
    $id = (int) ($_POST['toggle_id'] ?? 0);
    $newStatus = strtolower(trim((string) ($_POST['new_status'] ?? '')));
    if ($id > 0 && in_array($newStatus, $allowedStatus, true)) {
        $shouldNotify = false;
        $title = '';
        if ($newStatus === 'published') {
            $check = mysqli_prepare($con, "SELECT status, title FROM journal WHERE id=? LIMIT 1");
            if ($check) {
                mysqli_stmt_bind_param($check, 'i', $id);
                mysqli_stmt_execute($check);
                $row = emsp_stmt_fetch_assoc($check);
                mysqli_stmt_close($check);
                if ($row) {
                    $oldStatus = (string) ($row['status'] ?? '');
                    $title = emsp_fix_mojibake((string) ($row['title'] ?? ''));
                    $shouldNotify = $oldStatus !== 'published';
                }
            }
        }
        $stmt = mysqli_prepare($con, 'UPDATE journal SET status=? WHERE id=? LIMIT 1');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'si', $newStatus, $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            flash_set('info', 'Statut mis a jour', 'Le statut de publication a ete modifie.');
        }
        if ($shouldNotify) {
            notify_journal_published_all($con, $id, $title, (int) ($_SESSION['auth_user']['id'] ?? 0));
        }
    }
    emsp_journal_redirect_with_filters();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_id']) && $hasClosedAt && $hasClosedBy) {
    verify_csrf_token();
    $id = (int) ($_POST['close_id'] ?? 0);
    $adminId = (int) ($_SESSION['auth_user']['id'] ?? 0);
    if ($id > 0) {
        $stmt = mysqli_prepare($con, "UPDATE journal SET closed_at=NOW(), closed_by=? WHERE id=? AND type IN ('sondage','defi') LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'ii', $adminId, $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            flash_set('info', 'Contenu cloture', 'Le sondage ou le defi a ete ferme manuellement.');
        }
    }
    emsp_journal_redirect_with_filters();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reopen_id']) && $hasClosedAt && $hasClosedBy) {
    verify_csrf_token();
    $id = (int) ($_POST['reopen_id'] ?? 0);
    if ($id > 0) {
        $stmt = mysqli_prepare($con, "UPDATE journal SET closed_at=NULL, closed_by=NULL WHERE id=? AND type IN ('sondage','defi') LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            flash_set('info', 'Contenu reouvert', 'Le sondage ou le defi redevient interactif si sa date de fin n est pas depassee.');
        }
    }
    emsp_journal_redirect_with_filters();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    verify_csrf_token();
    $id = (int) ($_POST['delete_id'] ?? 0);
    if ($id > 0) {
        $stmt = mysqli_prepare($con, 'DELETE FROM journal WHERE id=? LIMIT 1');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            flash_set('info', 'Article supprime', 'L article a ete supprime definitivement.');
        }
    }
    emsp_journal_redirect_with_filters();
}

$typeFilter = strtolower(trim((string) ($_GET['type'] ?? '')));
$statusFilter = strtolower(trim((string) ($_GET['status'] ?? '')));
$stateFilter = strtolower(trim((string) ($_GET['state'] ?? '')));
if (!in_array($typeFilter, $allowedTypes, true)) {
    $typeFilter = '';
}
if (!in_array($statusFilter, $allowedStatus, true)) {
    $statusFilter = '';
}
if (!in_array($stateFilter, $allowedStates, true)) {
    $stateFilter = '';
}

$selectTiming = $hasLifecycle ? 'j.starts_at, j.ends_at' : 'NULL AS starts_at, NULL AS ends_at';
$selectClosed = $hasClosedAt ? 'j.closed_at' : 'NULL AS closed_at';
$selectAuthor = $authorColumn !== '' ? ', u.first_name, u.last_name' : '';
$joinAuthor = $authorColumn !== '' ? "LEFT JOIN users u ON u.id = j.{$authorColumn}" : '';

$sql = "SELECT j.id, j.type, j.title, j.content, j.status, j.created_at, {$selectTiming}, {$selectClosed}{$selectAuthor}
        FROM journal j
        {$joinAuthor}";
$where = [];
$params = [];
$bindTypes = '';
if ($typeFilter !== '') {
    $where[] = 'j.type=?';
    $params[] = $typeFilter;
    $bindTypes .= 's';
}
if ($statusFilter !== '') {
    $where[] = 'j.status=?';
    $params[] = $statusFilter;
    $bindTypes .= 's';
}
if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY j.created_at DESC, j.id DESC';

$articles = [];
$stmt = mysqli_prepare($con, $sql);
if ($stmt) {
    if ($bindTypes === 's') {
        mysqli_stmt_bind_param($stmt, 's', $params[0]);
    } elseif ($bindTypes === 'ss') {
        mysqli_stmt_bind_param($stmt, 'ss', $params[0], $params[1]);
    }
    mysqli_stmt_execute($stmt);
    $rows = emsp_stmt_fetch_all($stmt);
    mysqli_stmt_close($stmt);
    foreach ($rows as $row) {
        foreach (['title', 'content', 'first_name', 'last_name'] as $field) {
            if (isset($row[$field]) && is_string($row[$field])) {
                $row[$field] = emsp_fix_mojibake($row[$field]);
            }
        }
        $row['state'] = emsp_journal_state($row);
        $row['excerpt'] = emsp_journal_excerpt((string) ($row['content'] ?? ''), 120);
        $row['author_label'] = $authorColumn !== ''
            ? trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['last_name'] ?? '')))
            : '';
        if ($stateFilter !== '' && (($row['state']['code'] ?? '') !== $stateFilter)) {
            continue;
        }
        $articles[] = $row;
    }
}

$totalArticles = count($articles);
$totalPublished = 0;
$totalDraft = 0;
$totalOpen = 0;
$totalClosed = 0;
$totalExpired = 0;
foreach ($articles as $article) {
    if (($article['status'] ?? '') === 'published') {
        $totalPublished++;
    }
    if (($article['status'] ?? '') === 'draft') {
        $totalDraft++;
    }
    if (($article['state']['code'] ?? '') === 'open') {
        $totalOpen++;
    }
    if (($article['state']['code'] ?? '') === 'closed') {
        $totalClosed++;
    }
    if (($article['state']['code'] ?? '') === 'expired') {
        $totalExpired++;
    }
}

$typeBadge = [
    'annonce' => 'bg-primary-subtle text-primary-emphasis',
    'defi' => 'bg-warning-subtle text-warning-emphasis',
    'sondage' => 'bg-success-subtle text-success-emphasis',
];
$typeLabel = [
    'annonce' => 'Annonce',
    'defi' => 'Defi',
    'sondage' => 'Sondage',
];
$stateBadge = [
    'draft' => 'bg-secondary-subtle text-secondary-emphasis',
    'open' => 'bg-success-subtle text-success-emphasis',
    'scheduled' => 'bg-info-subtle text-info-emphasis',
    'closed' => 'bg-dark-subtle text-dark-emphasis',
    'expired' => 'bg-warning-subtle text-warning-emphasis',
];

$page_title = 'Journal';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
include __DIR__ . '/includes/navbar-top.php';
?>
<div id="admin-content">
<div id="main-content" class="container-fluid">
    <div class="row g-3 mb-4">
        <div class="col-md-6 col-xl-2">
            <div class="card shadow-sm border-0 h-100"><div class="card-body text-center"><div class="fs-3 fw-bold"><?= $totalArticles ?></div><div class="small text-muted">Articles filtres</div></div></div>
        </div>
        <div class="col-md-6 col-xl-2">
            <div class="card shadow-sm border-0 h-100"><div class="card-body text-center"><div class="fs-3 fw-bold text-success"><?= $totalPublished ?></div><div class="small text-muted">Publies</div></div></div>
        </div>
        <div class="col-md-6 col-xl-2">
            <div class="card shadow-sm border-0 h-100"><div class="card-body text-center"><div class="fs-3 fw-bold text-secondary"><?= $totalDraft ?></div><div class="small text-muted">Brouillons</div></div></div>
        </div>
        <div class="col-md-6 col-xl-2">
            <div class="card shadow-sm border-0 h-100"><div class="card-body text-center"><div class="fs-3 fw-bold text-info"><?= $totalOpen ?></div><div class="small text-muted">Ouverts</div></div></div>
        </div>
        <div class="col-md-6 col-xl-2">
            <div class="card shadow-sm border-0 h-100"><div class="card-body text-center"><div class="fs-3 fw-bold text-dark"><?= $totalClosed ?></div><div class="small text-muted">Clos</div></div></div>
        </div>
        <div class="col-md-6 col-xl-2">
            <a href="add-journal.php" class="btn btn-emsp w-100 h-100 d-flex align-items-center justify-content-center shadow-sm rounded-4">
                <i class="bi bi-plus-circle me-2"></i>Nouveau contenu
            </a>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <h2 class="h5 fw-bold mb-1"><i class="bi bi-newspaper me-2 text-primary"></i>Pilotage du journal</h2>
                <div class="small text-muted">Suivi des contenus, de leur etat et de leurs resultats.</div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-sm <?= ($typeFilter === '' && $statusFilter === '' && $stateFilter === '') ? 'btn-dark' : 'btn-outline-dark' ?>" href="journal.php">Tous</a>
                <a class="btn btn-sm <?= $typeFilter === 'annonce' ? 'btn-primary' : 'btn-outline-primary' ?>" href="journal.php?type=annonce">Annonces</a>
                <a class="btn btn-sm <?= $typeFilter === 'sondage' ? 'btn-success' : 'btn-outline-success' ?>" href="journal.php?type=sondage">Sondages</a>
                <a class="btn btn-sm <?= $typeFilter === 'defi' ? 'btn-warning' : 'btn-outline-warning' ?>" href="journal.php?type=defi">Defis</a>
                <a class="btn btn-sm <?= $stateFilter === 'open' ? 'btn-info' : 'btn-outline-info' ?>" href="journal.php?state=open">Ouverts</a>
                <a class="btn btn-sm <?= $stateFilter === 'closed' ? 'btn-secondary' : 'btn-outline-secondary' ?>" href="journal.php?state=closed">Clos</a>
                <a class="btn btn-sm <?= $stateFilter === 'expired' ? 'btn-warning' : 'btn-outline-warning' ?>" href="journal.php?state=expired">Expires</a>
            </div>
        </div>

        <div class="table-responsive d-none d-md-block">
            <table class="table align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Titre</th>
                        <th>Type</th>
                        <th>Etat</th>
                        <th>Dates</th>
                        <th>Auteur</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($articles)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">
                                <i class="bi bi-journal-x fs-2 d-block mb-2"></i>
                                Aucun contenu ne correspond a ces filtres.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($articles as $article): ?>
                            <?php
                                $state = $article['state'];
                                $type = (string) ($article['type'] ?? 'annonce');
                                $isPublished = (string) ($article['status'] ?? '') === 'published';
                                $queryParts = [];
                                if ($typeFilter !== '') { $queryParts['type'] = $typeFilter; }
                                if ($statusFilter !== '') { $queryParts['status'] = $statusFilter; }
                                if ($stateFilter !== '') { $queryParts['state'] = $stateFilter; }
                                $querySuffix = !empty($queryParts) ? '?' . http_build_query($queryParts) : '';
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= htmlspecialchars((string) ($article['title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></div>
                                    <div class="small text-muted"><?= htmlspecialchars((string) ($article['excerpt'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></div>
                                </td>
                                <td>
                                    <span class="badge rounded-pill <?= $typeBadge[$type] ?? 'bg-secondary-subtle text-secondary-emphasis' ?>">
                                        <?= htmlspecialchars($typeLabel[$type] ?? ucfirst($type), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="d-flex flex-column gap-1">
                                        <span class="badge rounded-pill <?= $stateBadge[$state['code']] ?? 'bg-secondary-subtle text-secondary-emphasis' ?>">
                                            <?= htmlspecialchars((string) ($state['label'] ?? 'Etat'), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>
                                        </span>
                                        <span class="small text-muted"><?= $isPublished ? 'Publie' : 'Brouillon' ?></span>
                                    </div>
                                </td>
                                <td>
                                    <div class="small">
                                        <div><strong>Cree :</strong> <?= htmlspecialchars(emsp_journal_format_datetime((string) ($article['created_at'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></div>
                                        <?php if (($state['starts_at_label'] ?? '') !== ''): ?><div><strong>Debut :</strong> <?= htmlspecialchars((string) $state['starts_at_label'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></div><?php endif; ?>
                                        <?php if (($state['ends_at_label'] ?? '') !== ''): ?><div><strong>Fin :</strong> <?= htmlspecialchars((string) $state['ends_at_label'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></div><?php endif; ?>
                                    </div>
                                </td>
                                <td><?= $article['author_label'] !== '' ? htmlspecialchars((string) $article['author_label'], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '<span class="text-muted">-</span>' ?></td>
                                <td>
                                    <div class="d-flex flex-wrap gap-1">
                                        <a href="add-journal.php?id=<?= (int) $article['id'] ?>" class="btn btn-sm btn-outline-primary" title="Modifier"><i class="bi bi-pencil-square"></i></a>
                                        <?php if (in_array($type, ['sondage', 'defi'], true)): ?>
                                            <button type="button" class="btn btn-sm btn-outline-dark" data-results-id="<?= (int) $article['id'] ?>" title="Resultats / Gerer">
                                                <i class="bi bi-bar-chart"></i>
                                            </button>
                                            <?php if ($hasClosedAt && $hasClosedBy && $isPublished): ?>
                                                <?php if (($state['code'] ?? '') === 'closed'): ?>
                                                    <form method="post" class="d-inline" action="journal.php<?= htmlspecialchars($querySuffix, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
                                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                        <input type="hidden" name="reopen_id" value="<?= (int) $article['id'] ?>">
                                                        <button class="btn btn-sm btn-outline-success" type="submit" title="Reouvrir"
                                                            data-emsp-confirm-auto="1"
                                                            data-confirm="Réouvrir ce contenu ?"
                                                            data-confirm-detail="Le sondage ou le défi redeviendra interactif si sa date de fin n'est pas dépassée."
                                                            data-confirm-type="info"
                                                            data-confirm-ok="Oui, réouvrir"><i class="bi bi-arrow-clockwise"></i></button>
                                                    </form>
                                                <?php else: ?>
                                                    <form method="post" class="d-inline" action="journal.php<?= htmlspecialchars($querySuffix, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
                                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                        <input type="hidden" name="close_id" value="<?= (int) $article['id'] ?>">
                                                        <button class="btn btn-sm btn-outline-warning" type="submit" title="Cloturer"
                                                            data-emsp-confirm-auto="1"
                                                            data-confirm="Clôturer ce contenu ?"
                                                            data-confirm-detail="Les votes ou participations seront bloqués immédiatement."
                                                            data-confirm-type="warning"
                                                            data-confirm-ok="Oui, clôturer"><i class="bi bi-lock"></i></button>
                                                    </form>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <?php if ($type === 'defi'): ?>
                                                <a href="journal.php?export_defi_id=<?= (int) $article['id'] ?>" class="btn btn-sm btn-outline-info" title="Exporter CSV"><i class="bi bi-download"></i></a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <form method="post" class="d-inline" action="journal.php<?= htmlspecialchars($querySuffix, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
                                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                            <input type="hidden" name="toggle_id" value="<?= (int) $article['id'] ?>">
                                            <input type="hidden" name="new_status" value="<?= $isPublished ? 'draft' : 'published' ?>">
                                            <button class="btn btn-sm <?= $isPublished ? 'btn-success' : 'btn-outline-secondary' ?>" type="submit" title="Publier / passer en brouillon"
                                                data-emsp-confirm-auto="1"
                                                data-confirm="<?= $isPublished ? 'Passer ce contenu en brouillon ?' : 'Publier ce contenu ?' ?>"
                                                data-confirm-detail="<?= $isPublished ? 'Le contenu ne sera plus visible publiquement.' : 'Le contenu deviendra visible publiquement.' ?>"
                                                data-confirm-type="<?= $isPublished ? 'warning' : 'success' ?>"
                                                data-confirm-ok="<?= $isPublished ? 'Oui, passer en brouillon' : 'Oui, publier' ?>">
                                                <i class="bi <?= $isPublished ? 'bi-eye-fill' : 'bi-eye-slash' ?>"></i>
                                            </button>
                                        </form>
                                        <form method="post" class="d-inline" action="journal.php<?= htmlspecialchars($querySuffix, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
                                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                            <input type="hidden" name="delete_id" value="<?= (int) $article['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger" type="submit" title="Supprimer"
                                                data-confirm="Supprimer ce contenu ?"
                                                data-confirm-detail="Cette action est irreversible."
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
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="card-body d-md-none">
            <?php if (empty($articles)): ?>
                <div class="emsp-admin-mobile-empty">
                    <i class="bi bi-journal-x fs-3 d-block mb-2"></i>
                    Aucun contenu ne correspond a ces filtres.
                </div>
            <?php else: ?>
                <div class="emsp-admin-mobile-list">
                    <?php foreach ($articles as $article): ?>
                        <?php
                            $state = $article['state'];
                            $type = (string) ($article['type'] ?? 'annonce');
                            $isPublished = (string) ($article['status'] ?? '') === 'published';
                            $queryParts = [];
                            if ($typeFilter !== '') { $queryParts['type'] = $typeFilter; }
                            if ($statusFilter !== '') { $queryParts['status'] = $statusFilter; }
                            if ($stateFilter !== '') { $queryParts['state'] = $stateFilter; }
                            $querySuffix = !empty($queryParts) ? '?' . http_build_query($queryParts) : '';
                        ?>
                        <div class="emsp-admin-mobile-card">
                            <div class="emsp-admin-mobile-card-header">
                                <div>
                                    <h3 class="emsp-admin-mobile-card-title mb-0"><?= htmlspecialchars((string) ($article['title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></h3>
                                    <div class="emsp-admin-mobile-card-subtitle"><?= htmlspecialchars((string) ($article['excerpt'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></div>
                                </div>
                                <span class="badge rounded-pill <?= $typeBadge[$type] ?? 'bg-secondary-subtle text-secondary-emphasis' ?>">
                                    <?= htmlspecialchars($typeLabel[$type] ?? ucfirst($type), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>
                                </span>
                            </div>
                            <div class="emsp-admin-mobile-meta">
                                <div class="emsp-admin-mobile-meta-item">
                                    <span class="emsp-admin-mobile-meta-label">Etat</span>
                                    <span class="emsp-admin-mobile-meta-value">
                                        <span class="badge rounded-pill <?= $stateBadge[$state['code']] ?? 'bg-secondary-subtle text-secondary-emphasis' ?>">
                                            <?= htmlspecialchars((string) ($state['label'] ?? 'Etat'), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>
                                        </span>
                                        <span class="ms-2 text-muted"><?= $isPublished ? 'Publie' : 'Brouillon' ?></span>
                                    </span>
                                </div>
                                <div class="emsp-admin-mobile-meta-item">
                                    <span class="emsp-admin-mobile-meta-label">Dates</span>
                                    <span class="emsp-admin-mobile-meta-value">
                                        Cree : <?= htmlspecialchars(emsp_journal_format_datetime((string) ($article['created_at'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>
                                        <?php if (($state['starts_at_label'] ?? '') !== ''): ?><br>Debut : <?= htmlspecialchars((string) $state['starts_at_label'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?><?php endif; ?>
                                        <?php if (($state['ends_at_label'] ?? '') !== ''): ?><br>Fin : <?= htmlspecialchars((string) $state['ends_at_label'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?><?php endif; ?>
                                    </span>
                                </div>
                                <div class="emsp-admin-mobile-meta-item">
                                    <span class="emsp-admin-mobile-meta-label">Auteur</span>
                                    <span class="emsp-admin-mobile-meta-value"><?= $article['author_label'] !== '' ? htmlspecialchars((string) $article['author_label'], ENT_QUOTES | ENT_HTML5, 'UTF-8') : 'Non renseigne' ?></span>
                                </div>
                            </div>
                            <div class="emsp-admin-mobile-actions">
                                <a href="add-journal.php?id=<?= (int) $article['id'] ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-pencil-square me-1"></i>Modifier
                                </a>
                                <?php if (in_array($type, ['sondage', 'defi'], true)): ?>
                                    <button type="button" class="btn btn-sm btn-outline-dark" data-results-id="<?= (int) $article['id'] ?>">
                                        <i class="bi bi-bar-chart me-1"></i>Resultats
                                    </button>
                                    <?php if ($hasClosedAt && $hasClosedBy && $isPublished): ?>
                                        <?php if (($state['code'] ?? '') === 'closed'): ?>
                                            <form method="post" class="d-inline" action="journal.php<?= htmlspecialchars($querySuffix, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
                                                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                <input type="hidden" name="reopen_id" value="<?= (int) $article['id'] ?>">
                                                <button class="btn btn-sm btn-outline-success" type="submit"
                                                    data-emsp-confirm-auto="1"
                                                    data-confirm="Reouvrir ce contenu ?"
                                                    data-confirm-detail="Le sondage ou le defi redeviendra interactif si sa date de fin n est pas depassee."
                                                    data-confirm-type="info"
                                                    data-confirm-ok="Oui, reouvrir"><i class="bi bi-arrow-clockwise me-1"></i>Reouvrir</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="post" class="d-inline" action="journal.php<?= htmlspecialchars($querySuffix, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
                                                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                                <input type="hidden" name="close_id" value="<?= (int) $article['id'] ?>">
                                                <button class="btn btn-sm btn-outline-warning" type="submit"
                                                    data-emsp-confirm-auto="1"
                                                    data-confirm="Cloturer ce contenu ?"
                                                    data-confirm-detail="Les votes ou participations seront bloques immediatement."
                                                    data-confirm-type="warning"
                                                    data-confirm-ok="Oui, cloturer"><i class="bi bi-lock me-1"></i>Cloturer</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($type === 'defi'): ?>
                                        <a href="journal.php?export_defi_id=<?= (int) $article['id'] ?>" class="btn btn-sm btn-outline-info">
                                            <i class="bi bi-download me-1"></i>Exporter
                                        </a>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <form method="post" class="d-inline" action="journal.php<?= htmlspecialchars($querySuffix, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
                                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                    <input type="hidden" name="toggle_id" value="<?= (int) $article['id'] ?>">
                                    <input type="hidden" name="new_status" value="<?= $isPublished ? 'draft' : 'published' ?>">
                                    <button class="btn btn-sm <?= $isPublished ? 'btn-success' : 'btn-outline-secondary' ?>" type="submit"
                                        data-emsp-confirm-auto="1"
                                        data-confirm="<?= $isPublished ? 'Passer ce contenu en brouillon ?' : 'Publier ce contenu ?' ?>"
                                        data-confirm-detail="<?= $isPublished ? 'Le contenu ne sera plus visible publiquement.' : 'Le contenu deviendra visible publiquement.' ?>"
                                        data-confirm-type="<?= $isPublished ? 'warning' : 'success' ?>"
                                        data-confirm-ok="<?= $isPublished ? 'Oui, passer en brouillon' : 'Oui, publier' ?>">
                                        <i class="bi <?= $isPublished ? 'bi-eye-fill' : 'bi-eye-slash' ?> me-1"></i><?= $isPublished ? 'Brouillon' : 'Publier' ?>
                                    </button>
                                </form>
                                <form method="post" class="d-inline" action="journal.php<?= htmlspecialchars($querySuffix, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
                                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                    <input type="hidden" name="delete_id" value="<?= (int) $article['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" type="submit"
                                        data-confirm="Supprimer ce contenu ?"
                                        data-confirm-detail="Cette action est irreversible."
                                        data-confirm-type="danger"
                                        data-confirm-ok="Oui, supprimer"
                                        data-emsp-confirm-auto="1">
                                        <i class="bi bi-trash me-1"></i>Supprimer
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</div>

<div class="modal fade" id="journalManageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <div class="small text-muted" id="journal-manage-state"></div>
                    <h2 class="modal-title h5 mb-0" id="journal-manage-title">Resultats du journal</h2>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">
                <div id="journal-manage-body" class="small text-muted">Chargement...</div>
            </div>
        </div>
    </div>
</div>
<?php
$page_scripts = <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function () {
    var modalElement = document.getElementById('journalManageModal');
    if (!modalElement || typeof bootstrap === 'undefined') {
        return;
    }

    var modal = new bootstrap.Modal(modalElement);
    var modalTitle = document.getElementById('journal-manage-title');
    var modalState = document.getElementById('journal-manage-state');
    var modalBody = document.getElementById('journal-manage-body');

    function esc(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function renderPoll(details) {
        var poll = details.poll || { options: [], total_votes: 0 };
        if (!poll.options.length) {
            return '<div class="alert alert-warning mb-0">Aucune option configuree pour ce sondage.</div>';
        }
        var rows = poll.options.map(function (option) {
            var votes = parseInt(option.votes || 0, 10);
            var total = Math.max(1, parseInt(poll.total_votes || 0, 10));
            var percent = poll.total_votes > 0 ? Math.round((votes * 100) / total) : 0;
            return '<div class="border rounded-4 p-3 mb-3">'
                + '<div class="d-flex justify-content-between align-items-center gap-3 mb-2">'
                + '<strong>' + esc(option.label) + '</strong>'
                + '<span class="badge text-bg-light border">' + votes + ' vote(s)</span>'
                + '</div>'
                + '<div class="progress emsp-progress-track-10"><div class="progress-bar bg-success" data-emsp-width="' + percent + '"></div></div>'
                + '<div class="small text-muted mt-2">' + percent + '% du total</div>'
                + '</div>';
        }).join('');
        return '<div class="mb-3"><strong>Total :</strong> ' + parseInt(poll.total_votes || 0, 10) + ' vote(s)</div>' + rows;
    }

    function renderDefi(details) {
        var defi = details.defi || { participants: [], participant_total: 0 };
        var header = '<div class="d-flex justify-content-between align-items-center mb-3">'
            + '<strong>' + parseInt(defi.participant_total || 0, 10) + ' participation(s)</strong>'
            + '<a class="btn btn-sm btn-outline-info" href="journal.php?export_defi_id=' + parseInt(details.article.id || 0, 10) + '">Exporter CSV</a>'
            + '</div>';
        if (!defi.participants.length) {
            return header + '<div class="alert alert-warning mb-0">Aucun participant enregistre pour le moment.</div>';
        }
        var rows = defi.participants.map(function (participant) {
            return '<div class="border rounded-4 p-3 mb-3">'
                + '<div class="d-flex flex-wrap justify-content-between gap-2 mb-2">'
                + '<div><strong>' + esc(participant.name || participant.email || 'Participant') + '</strong><div class="text-muted small">' + esc(participant.email || '') + '</div></div>'
                + '<span class="badge text-bg-light border">' + esc(participant.created_at_label || '') + '</span>'
                + '</div>'
                + '<div class="small admin-prewrap">' + esc(participant.note || '') + '</div>'
                + '</div>';
        }).join('');
        return header + rows;
    }

    function renderBody(details) {
        var state = details.state || {};
        var timing = [];
        if (state.starts_at_label) {
            timing.push('<div><strong>Debut :</strong> ' + esc(state.starts_at_label) + '</div>');
        }
        if (state.ends_at_label) {
            timing.push('<div><strong>Fin :</strong> ' + esc(state.ends_at_label) + '</div>');
        }
        if (state.closed_at_label) {
            timing.push('<div><strong>Cloture :</strong> ' + esc(state.closed_at_label) + '</div>');
        }

        var intro = '<div class="mb-4">'
            + '<div class="small text-muted mb-2">' + timing.join('') + '</div>'
            + '<div class="journal-rich-content">' + (details.article.content_html || '') + '</div>'
            + '</div>';

        if (details.type === 'sondage') {
            return intro + renderPoll(details);
        }
        if (details.type === 'defi') {
            return intro + renderDefi(details);
        }
        return intro;
    }

    document.querySelectorAll('[data-results-id]').forEach(function (button) {
        button.addEventListener('click', function () {
            var id = this.getAttribute('data-results-id');
            modalTitle.textContent = 'Chargement...';
            modalState.textContent = '';
            modalBody.innerHTML = '<div class="text-muted">Chargement des details...</div>';
            modal.show();

            fetch('journal.php?details_id=' + encodeURIComponent(id) + '&format=json', {
                credentials: 'include'
            })
                .then(function (response) { return response.json(); })
                .then(function (payload) {
                    if (!payload || !payload.ok || !payload.details) {
                        throw new Error('details');
                    }
                    var details = payload.details;
                    modalTitle.textContent = details.article.title || 'Resultats';
                    modalState.textContent = (details.type_meta && details.type_meta.label ? details.type_meta.label + ' - ' : '') + (details.state && details.state.label ? details.state.label : '');
                    modalBody.innerHTML = renderBody(details);
                })
                .catch(function () {
                    modalBody.innerHTML = '<div class="alert alert-danger mb-0">Impossible de charger les details pour le moment.</div>';
                });
        });
    });
});
</script>
HTML;
include __DIR__ . '/includes/footer.php';
?>


