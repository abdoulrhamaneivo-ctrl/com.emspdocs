<?php
ob_start();
include_once __DIR__ . '/bootstrap.php';
if (empty($_SESSION['auth']) || !in_array($_SESSION['auth_role'] ?? '', ['admin', 'moderateur'], true)) {
    header('Location: ../index.php?open_login=1');
    exit;
}

include_once __DIR__ . '/config/dbcon.php';
include_once __DIR__ . '/authentication.php';
include_once __DIR__ . '/../includes/csrf.php';
include_once __DIR__ . '/../includes/brevo.php';
include_once __DIR__ . '/../includes/flash.php';
include_once __DIR__ . '/../includes/document-taxonomy.php';

$pendingMatiereEnabled = emsp_pending_matiere_enabled($con);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['doc_id'])) {
    verify_csrf_token();

    $doc_id = (int) ($_POST['doc_id'] ?? 0);
    $action = (string) ($_POST['action'] ?? '');
    $motif = trim((string) ($_POST['motif'] ?? ''));
    $reviewMatiereId = (int) ($_POST['review_matiere_id'] ?? 0);
    $reviewMatiereLabel = trim((string) ($_POST['review_matiere_label'] ?? ''));

    if (!in_array($action, ['approve', 'reject'], true)) {
        flash_set('error', 'Action invalide', 'L action demandee est invalide.');
        header('Location: pending-documents.php');
        exit;
    }

    if ($action === 'reject' && $motif === '') {
        flash_set('warning', 'Motif obligatoire', 'Veuillez indiquer un motif clair pour refuser ce document.');
        header('Location: pending-documents.php');
        exit;
    }

    mysqli_begin_transaction($con);
    try {
        $selectSql = "SELECT d.id, d.title, d.status, d.uploader_id, d.filiere_id, d.matiere_id, "
            . ($pendingMatiereEnabled ? "d.matiere_label_pending, " : "NULL AS matiere_label_pending, ")
            . "u.first_name, u.last_name, u.email
               FROM documents d
               JOIN users u ON u.id = d.uploader_id
               WHERE d.id=?
               LIMIT 1
               FOR UPDATE";
        $s = mysqli_prepare($con, $selectSql);
        if (!$s) {
            throw new RuntimeException('prepare document lock failed');
        }
        mysqli_stmt_bind_param($s, 'i', $doc_id);
        if (!mysqli_stmt_execute($s)) {
            mysqli_stmt_close($s);
            throw new RuntimeException('execute document lock failed');
        }
        $doc = emsp_stmt_fetch_assoc($s);
        mysqli_stmt_close($s);

        if ($doc) {
            foreach (['title', 'status', 'first_name', 'last_name', 'email', 'matiere_label_pending'] as $field) {
                if (isset($doc[$field]) && is_string($doc[$field])) {
                    $doc[$field] = emsp_fix_mojibake($doc[$field]);
                }
            }
        }

        if (!$doc) {
            mysqli_rollback($con);
            flash_set('error', 'Document introuvable', 'Le document selectionne n existe plus ou a deja ete traite.');
            header('Location: pending-documents.php');
            exit;
        }

        if (($doc['status'] ?? '') !== 'pending') {
            mysqli_rollback($con);
            flash_set('warning', 'Document deja traite', 'Ce document n est plus en attente. Rechargez la page pour voir son etat actuel.');
            header('Location: pending-documents.php');
            exit;
        }

        $docFiliereMap = emsp_fetch_document_filieres_map($con, [$doc_id]);
        $currentFiliereIds = array_map(
            static function (array $row): int { return (int) ($row['id'] ?? 0); },
            $docFiliereMap[$doc_id] ?? []
        );
        $currentFiliereIds = array_values(array_filter($currentFiliereIds, static function (int $id): bool { return $id > 0; }));
        if (empty($currentFiliereIds) && !empty($doc['filiere_id'])) {
            $currentFiliereIds = [(int) $doc['filiere_id']];
        }

        $audit_action = '';
        $audit_details = '';

        if ($action === 'approve') {
            $resolvedMatiereId = (int) ($doc['matiere_id'] ?? 0);
            $pendingLabel = trim((string) ($doc['matiere_label_pending'] ?? ''));

            if ($pendingLabel !== '') {
                if ($reviewMatiereId <= 0 && $reviewMatiereLabel === '') {
                    mysqli_rollback($con);
                    flash_set(
                        'warning',
                        'Matiere a valider',
                        'Choisissez une matiere existante ou corrigez le libelle de la nouvelle matiere avant d approuver.'
                    );
                    header('Location: pending-documents.php');
                    exit;
                }

                $matiereError = null;
                $resolvedMatiereId = emsp_resolve_matiere_id(
                    $con,
                    $reviewMatiereId > 0 ? $reviewMatiereId : null,
                    $reviewMatiereLabel !== '' ? $reviewMatiereLabel : $pendingLabel,
                    $matiereError
                );
                if ($resolvedMatiereId <= 0) {
                    mysqli_rollback($con);
                    flash_set(
                        'error',
                        'Matiere invalide',
                        $matiereError ?: 'Impossible de valider la matiere pour ce document.'
                    );
                    header('Location: pending-documents.php');
                    exit;
                }
            }

            $approveSql = "UPDATE documents
                           SET status='approved',
                               approved_by=?,
                               approved_at=NOW(),
                               rejection_reason=NULL,
                               matiere_id=?"
                . ($pendingMatiereEnabled ? ", matiere_label_pending=NULL" : "")
                . " WHERE id=? AND status='pending'";
            $upd = mysqli_prepare($con, $approveSql);
            if (!$upd) {
                throw new RuntimeException('prepare approve failed');
            }
            mysqli_stmt_bind_param($upd, 'iii', $auth_user['id'], $resolvedMatiereId, $doc_id);
            if (!mysqli_stmt_execute($upd)) {
                mysqli_stmt_close($upd);
                throw new RuntimeException('execute approve failed');
            }
            $affected = mysqli_stmt_affected_rows($upd);
            mysqli_stmt_close($upd);

            if ($affected !== 1) {
                mysqli_rollback($con);
                flash_set('warning', 'Document deja traite', 'Ce document a ete traite avant votre action. Aucun email supplementaire n a ete envoye.');
                header('Location: pending-documents.php');
                exit;
            }

            emsp_sync_document_filieres($con, $doc_id, $currentFiliereIds);

            $cnt = mysqli_prepare($con, "UPDATE users SET upload_count = upload_count + 1 WHERE id=?");
            if (!$cnt) {
                throw new RuntimeException('prepare upload_count failed');
            }
            mysqli_stmt_bind_param($cnt, 'i', $doc['uploader_id']);
            if (!mysqli_stmt_execute($cnt)) {
                mysqli_stmt_close($cnt);
                throw new RuntimeException('execute upload_count failed');
            }
            mysqli_stmt_close($cnt);

            $uc = mysqli_prepare($con, "SELECT upload_count, badge_level FROM users WHERE id=? LIMIT 1 FOR UPDATE");
            if (!$uc) {
                throw new RuntimeException('prepare badge load failed');
            }
            mysqli_stmt_bind_param($uc, 'i', $doc['uploader_id']);
            if (!mysqli_stmt_execute($uc)) {
                mysqli_stmt_close($uc);
                throw new RuntimeException('execute badge load failed');
            }
            $ur = emsp_stmt_fetch_assoc($uc);
            mysqli_stmt_close($uc);

            if ($ur) {
                $current_badge = (string) ($ur['badge_level'] ?? 'none');
                $upload_count = (int) ($ur['upload_count'] ?? 0);
                $new_badge = $current_badge;
                if ($upload_count >= 5) {
                    $new_badge = 'argent';
                } elseif ($upload_count >= 1) {
                    $new_badge = 'bronze';
                }
                if ($new_badge !== $current_badge) {
                    $ub = mysqli_prepare($con, "UPDATE users SET badge_level=? WHERE id=?");
                    if (!$ub) {
                        throw new RuntimeException('prepare badge update failed');
                    }
                    mysqli_stmt_bind_param($ub, 'si', $new_badge, $doc['uploader_id']);
                    if (!mysqli_stmt_execute($ub)) {
                        mysqli_stmt_close($ub);
                        throw new RuntimeException('execute badge update failed');
                    }
                    mysqli_stmt_close($ub);
                }
            }

            $audit_action = 'doc_approved';
            $audit_details = (string) ($doc['title'] ?? '');
        } else {
            $upd = mysqli_prepare(
                $con,
                "UPDATE documents
                 SET status='rejected', approved_by=?, approved_at=NOW(), rejection_reason=?
                 WHERE id=? AND status='pending'"
            );
            if (!$upd) {
                throw new RuntimeException('prepare reject failed');
            }
            mysqli_stmt_bind_param($upd, 'isi', $auth_user['id'], $motif, $doc_id);
            if (!mysqli_stmt_execute($upd)) {
                mysqli_stmt_close($upd);
                throw new RuntimeException('execute reject failed');
            }
            $affected = mysqli_stmt_affected_rows($upd);
            mysqli_stmt_close($upd);

            if ($affected !== 1) {
                mysqli_rollback($con);
                flash_set('warning', 'Document deja traite', 'Ce document a ete traite avant votre action. Aucun email supplementaire n a ete envoye.');
                header('Location: pending-documents.php');
                exit;
            }

            $audit_action = 'doc_rejected';
            $audit_details = $motif;
        }

        mysqli_commit($con);
    } catch (Throwable $e) {
        mysqli_rollback($con);
        flash_set('error', 'Erreur technique', 'Le traitement du document a echoue. Reessayez dans quelques instants.');
        header('Location: pending-documents.php');
        exit;
    }

    if ($action === 'approve') {
        $email_sent = brevo_send_doc_approved($doc['uploader_id'], $doc['email'], $doc['first_name'], $doc['last_name'], $doc['title']);
        log_audit($con, (int) $auth_user['id'], $audit_action, 'document', $doc_id, $audit_details);
        $uploader_name = trim(($doc['first_name'] ?? '') . ' ' . ($doc['last_name'] ?? ''));

        if ($email_sent) {
            flash_set(
                'success',
                'Document approuve',
                'Le document "' . $doc['title'] . '" est maintenant visible dans la bibliotheque. '
                . 'L etudiant ' . $uploader_name . ' a ete notifie par email.'
            );
        } else {
            flash_set(
                'warning',
                'Document approuve, email non envoye',
                'Le document "' . $doc['title'] . '" est visible dans la bibliotheque, mais l email de notification n a pas pu etre envoye.'
            );
        }
    } else {
        $email_sent = brevo_send_doc_rejected($doc['uploader_id'], $doc['email'], $doc['first_name'], $doc['last_name'], $doc['title'], $motif);
        log_audit($con, (int) $auth_user['id'], $audit_action, 'document', $doc_id, $audit_details);

        if ($email_sent) {
            flash_set('warning', 'Document refuse', 'Le document a ete refuse. L etudiant a ete notifie avec le motif fourni.');
        } else {
            flash_set('warning', 'Document refuse, email non envoye', 'Le document a ete refuse, mais l email de notification n a pas pu etre envoye.');
        }
    }

    header('Location: pending-documents.php');
    exit;
}

$listSql = "SELECT d.id, d.title, d.description, d.doc_type, d.file_size_bytes, d.created_at,
                   d.is_public, d.semester, d.matiere_id, "
    . ($pendingMatiereEnabled ? "d.matiere_label_pending," : "NULL AS matiere_label_pending,")
    . " u.first_name, u.last_name, u.email,
        l.name AS licence_name, ma.name AS matiere_name
    FROM documents d
    JOIN users u ON u.id = d.uploader_id
    LEFT JOIN licences l ON l.id = d.licence_id
    LEFT JOIN matieres ma ON ma.id = d.matiere_id
    WHERE d.status='pending'
    ORDER BY d.created_at ASC";
$result = mysqli_query($con, $listSql);

$docs = [];
while ($row = mysqli_fetch_assoc($result)) {
    foreach (['title', 'description', 'first_name', 'last_name', 'email', 'licence_name', 'matiere_name', 'matiere_label_pending'] as $field) {
        if (isset($row[$field]) && is_string($row[$field])) {
            $row[$field] = emsp_fix_mojibake($row[$field]);
        }
    }
    $docs[] = $row;
}

$docIds = array_map(static function (array $row): int { return (int) ($row['id'] ?? 0); }, $docs);
$filiereLabels = emsp_fetch_document_filiere_labels($con, $docIds);
$matieresRows = mysqli_query($con, "SELECT id, name FROM matieres WHERE status='active' ORDER BY name");
$availableMatieres = [];
if ($matieresRows) {
    while ($row = mysqli_fetch_assoc($matieresRows)) {
        $row['name'] = emsp_fix_mojibake((string) ($row['name'] ?? ''));
        $availableMatieres[] = $row;
    }
}

foreach ($docs as &$docRow) {
    $labels = $filiereLabels[(int) $docRow['id']] ?? [];
    $docRow['filiere_label'] = !empty($labels) ? implode(', ', $labels) : 'Non renseignee';
    $docRow['matiere_display'] = trim((string) ($docRow['matiere_name'] ?? '')) !== ''
        ? (string) $docRow['matiere_name']
        : ((string) ($docRow['matiere_label_pending'] ?? '') !== '' ? (string) $docRow['matiere_label_pending'] . ' (a valider)' : 'A valider');
}
unset($docRow);

$pendingDocTypeLabels = [
    'cours' => 'Cours',
    'td' => 'TD',
    'correction' => 'Correction',
    'concours' => 'Concours',
    'examen' => 'Examen',
];
$pendingDocTypeIcons = [
    'cours' => 'bi-journal-bookmark-fill',
    'td' => 'bi-pencil-square',
    'correction' => 'bi-check2-square',
    'concours' => 'bi-trophy-fill',
    'examen' => 'bi-patch-question-fill',
];
$pendingDocThumbClasses = [
    'cours' => 'primary',
    'td' => 'success',
    'correction' => 'info',
    'concours' => 'warning',
    'examen' => 'danger',
];

$page_title = 'Documents en attente';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
include __DIR__ . '/includes/navbar-top.php';
?>

<div id="admin-content">
    <div id="main-content" class="container-fluid">

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0 fw-bold"><i class="bi bi-hourglass-split me-2 text-warning"></i>Documents en attente
                <span class="badge bg-warning text-dark ms-2"><?= count($docs); ?></span>
            </h5>
        </div>

        <?php if (count($docs) === 0): ?>
            <div class="card shadow-sm">
                <div class="card-body text-center py-5 text-muted">
                    <i class="bi bi-check-circle fs-1 text-success d-block mb-2"></i>
                    Aucun document en attente. Tout est a jour !
                </div>
            </div>
        <?php else: ?>
            <div class="card shadow-sm">
                <div class="d-md-none emsp-doc-admin-mobile-list">
                    <?php foreach ($docs as $d): ?>
                        <?php
                        $typeLabel = $pendingDocTypeLabels[$d['doc_type']] ?? ucfirst((string) ($d['doc_type'] ?? 'document'));
                        $typeIcon = $pendingDocTypeIcons[$d['doc_type']] ?? 'bi-file-earmark-text-fill';
                        $thumbClass = $pendingDocThumbClasses[$d['doc_type']] ?? 'neutral';
                        $docAuthor = trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? ''));
                        ?>
                        <article class="emsp-doc-admin-mobile-item">
                            <div class="emsp-doc-admin-mobile-head">
                                <span class="emsp-doc-row-thumb emsp-doc-row-thumb--<?= h($thumbClass) ?>">
                                    <i class="bi <?= h($typeIcon) ?>"></i>
                                    <span class="emsp-doc-row-thumb-label"><?= h($typeLabel) ?></span>
                                </span>
                                <div class="emsp-doc-admin-mobile-body">
                                    <div class="emsp-doc-admin-mobile-title"><?= h($d['title']); ?></div>
                                    <div class="emsp-doc-admin-mobile-subtitle"><?= h($docAuthor); ?> - <?= h($d['email']); ?></div>
                                    <div class="emsp-doc-admin-mobile-badges">
                                        <span class="badge bg-warning text-dark">En attente</span>
                                        <?php if (!empty($d['is_public'])): ?>
                                            <span class="badge bg-info text-dark">Public</span>
                                        <?php endif; ?>
                                        <?php if (!empty($d['semester'])): ?>
                                            <span class="badge bg-light text-dark"><?= h($d['semester']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="emsp-doc-admin-mobile-meta">
                                        <span><?= h($d['filiere_label']); ?></span>
                                        <span><?= h((string) ($d['licence_name'] ?? 'Non renseignee')); ?></span>
                                        <span><?= h($d['matiere_display']); ?></span>
                                        <span><?= max(1, (int) round(((int) ($d['file_size_bytes'] ?? 0)) / 1024)) ?> Ko - <?= date('d/m/Y H:i', strtotime((string) ($d['created_at'] ?? 'now'))); ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="emsp-doc-admin-mobile-actions">
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary review-doc-btn"
                                    data-bs-toggle="modal"
                                    data-bs-target="#reviewDocModal"
                                    data-doc-id="<?= (int) $d['id']; ?>"
                                    data-doc-title="<?= h($d['title']); ?>"
                                    data-doc-author="<?= h($docAuthor); ?>"
                                    data-doc-email="<?= h($d['email']); ?>"
                                    data-doc-filiere="<?= h($d['filiere_label']); ?>"
                                    data-doc-licence="<?= h((string) ($d['licence_name'] ?? 'Non renseignee')); ?>"
                                    data-doc-matiere-id="<?= (int) ($d['matiere_id'] ?? 0); ?>"
                                    data-doc-matiere="<?= h((string) ($d['matiere_name'] ?? '')); ?>"
                                    data-doc-matiere-pending="<?= h((string) ($d['matiere_label_pending'] ?? '')); ?>"
                                    data-doc-preview="<?= h('preview-doc.php?id=' . (int) $d['id']); ?>"
                                    data-doc-created="<?= h(date('d/m/Y H:i', strtotime((string) ($d['created_at'] ?? 'now')))); ?>"
                                    data-doc-size="<?= h(max(1, (int) round(((int) ($d['file_size_bytes'] ?? 0)) / 1024)) . ' Ko'); ?>"
                                    data-doc-public="<?= !empty($d['is_public']) ? 'Public' : 'Prive'; ?>"
                                >
                                    <i class="bi bi-search me-1"></i>Examiner
                                </button>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div class="table-responsive d-none d-md-block">
                    <table class="table align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Document</th>
                                <th>Auteur</th>
                                <th>Filieres / Niveau</th>
                                <th>Matiere</th>
                                <th>Taille</th>
                                <th>Soumis le</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($docs as $d): ?>
                                <tr>
                                    <td class="text-muted small"><?= (int) $d['id']; ?></td>
                                    <td>
                                        <div class="fw-semibold"><?= h($d['title']); ?></div>
                                        <?php if (!empty($d['semester'])): ?><div class="text-muted small"><?= h($d['semester']); ?></div><?php endif; ?>
                                        <?php if (!empty($d['is_public'])): ?><span class="badge bg-info text-dark">Public</span><?php endif; ?>
                                    </td>
                                    <td>
                                        <div><?= h(trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? ''))); ?></div>
                                        <small class="text-muted"><?= h($d['email']); ?></small>
                                    </td>
                                    <td class="text-muted small">
                                        <div><?= h($d['filiere_label']); ?></div>
                                        <div><?= h((string) ($d['licence_name'] ?? 'Non renseignee')); ?></div>
                                    </td>
                                    <td class="text-muted small"><?= h($d['matiere_display']); ?></td>
                                    <td class="text-muted small"><?= max(1, (int) round(((int) ($d['file_size_bytes'] ?? 0)) / 1024)) ?> Ko</td>
                                    <td class="text-muted small"><?= date('d/m/Y H:i', strtotime((string) ($d['created_at'] ?? 'now'))); ?></td>
                                    <td>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-secondary review-doc-btn"
                                            data-bs-toggle="modal"
                                            data-bs-target="#reviewDocModal"
                                            data-doc-id="<?= (int) $d['id']; ?>"
                                            data-doc-title="<?= h($d['title']); ?>"
                                            data-doc-author="<?= h(trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? ''))); ?>"
                                            data-doc-email="<?= h($d['email']); ?>"
                                            data-doc-filiere="<?= h($d['filiere_label']); ?>"
                                            data-doc-licence="<?= h((string) ($d['licence_name'] ?? 'Non renseignee')); ?>"
                                            data-doc-matiere-id="<?= (int) ($d['matiere_id'] ?? 0); ?>"
                                            data-doc-matiere="<?= h((string) ($d['matiere_name'] ?? '')); ?>"
                                            data-doc-matiere-pending="<?= h((string) ($d['matiere_label_pending'] ?? '')); ?>"
                                            data-doc-preview="<?= h('preview-doc.php?id=' . (int) $d['id']); ?>"
                                            data-doc-created="<?= h(date('d/m/Y H:i', strtotime((string) ($d['created_at'] ?? 'now')))); ?>"
                                            data-doc-size="<?= h(max(1, (int) round(((int) ($d['file_size_bytes'] ?? 0)) / 1024)) . ' Ko'); ?>"
                                            data-doc-public="<?= !empty($d['is_public']) ? 'Public' : 'Prive'; ?>"
                                        >
                                            <i class="bi bi-search me-1"></i>Examiner
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="reviewDocModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title fw-bold mb-1">Examiner le document</h5>
                    <div class="small text-muted" id="reviewDocMetaLine"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body">
                <div class="row g-4">
                    <div class="col-lg-7">
                        <iframe id="reviewDocFrame" title="Apercu document" class="w-100 border rounded-4 bg-white emsp-review-frame"></iframe>
                        <div id="reviewDocFrameStatus" class="alert alert-light border rounded-4 mt-3 d-none"></div>
                    </div>
                    <div class="col-lg-5">
                        <div class="border rounded-4 p-3 bg-light-subtle mb-3">
                            <div class="fw-semibold mb-2" id="reviewDocTitle"></div>
                            <div class="small text-muted mb-1" id="reviewDocAuthor"></div>
                            <div class="small text-muted mb-1"><strong>Filieres :</strong> <span id="reviewDocFiliere"></span></div>
                            <div class="small text-muted mb-1"><strong>Licence :</strong> <span id="reviewDocLicence"></span></div>
                            <div class="small text-muted mb-1"><strong>Matiere actuelle :</strong> <span id="reviewDocMatiere"></span></div>
                            <div class="small text-muted mb-1"><strong>Visibilite :</strong> <span id="reviewDocPublic"></span></div>
                            <div class="small text-muted mb-0"><strong>Taille :</strong> <span id="reviewDocSize"></span></div>
                        </div>

                        <form method="post" id="reviewDocForm">
                            <?php csrf_input(); ?>
                            <input type="hidden" name="doc_id" id="reviewDocId">
                            <input type="hidden" name="action" id="reviewDocAction">

                            <div class="mb-3">
                                <label class="form-label fw-semibold" for="review_matiere_id">Matiere existante</label>
                                <select class="form-select" name="review_matiere_id" id="review_matiere_id">
                                    <option value="">-- Choisir une matiere existante --</option>
                                    <?php foreach ($availableMatieres as $matiere): ?>
                                        <option value="<?= (int) $matiere['id'] ?>"><?= h($matiere['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3" id="reviewPendingMatterWrap">
                                <label class="form-label fw-semibold" for="review_matiere_label">Corriger ou creer la matiere</label>
                                <input type="text" class="form-control" name="review_matiere_label" id="review_matiere_label" maxlength="120">
                                <div class="form-text">Si la matiere n existe pas, le libelle corrige sera cree lors de l'approbation.</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold" for="reviewMotif">Motif de rejet</label>
                                <textarea class="form-control" name="motif" id="reviewMotif" rows="3" placeholder="Obligatoire uniquement si vous refusez le document."></textarea>
                            </div>

                            <div class="d-flex flex-wrap gap-2">
                                <button type="button" class="btn btn-success" id="approveDocBtn">Approuver</button>
                                <button type="button" class="btn btn-outline-danger" id="rejectDocBtn">Rejeter</button>
                            </div>
                        </form>

                        <form method="post" id="reviewDownloadForm" class="mt-3" target="reviewDownloadSink">
                            <?php csrf_input(); ?>
                            <button type="submit" class="btn btn-outline-primary w-100">
                                <i class="bi bi-download me-1"></i>Telecharger explicitement
                            </button>
                        </form>
                        <button type="button" class="btn btn-outline-secondary w-100 mt-2" id="reloadPreviewBtn">
                            <i class="bi bi-arrow-clockwise me-1"></i>Recharger l apercu
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<iframe name="reviewDownloadSink" class="d-none" title="Telechargement document" aria-hidden="true"></iframe>

<script>
(function () {
    var modal = document.getElementById('reviewDocModal');
    if (!modal) return;

    var frame = document.getElementById('reviewDocFrame');
    var form = document.getElementById('reviewDocForm');
    var actionInput = document.getElementById('reviewDocAction');
    var docIdInput = document.getElementById('reviewDocId');
    var matiereSelect = document.getElementById('review_matiere_id');
    var matiereLabel = document.getElementById('review_matiere_label');
    var pendingWrap = document.getElementById('reviewPendingMatterWrap');
    var motifInput = document.getElementById('reviewMotif');
    var downloadForm = document.getElementById('reviewDownloadForm');
    var statusBox = document.getElementById('reviewDocFrameStatus');
    var reloadButton = document.getElementById('reloadPreviewBtn');
    var loadTimer = null;
    var currentPreviewSrc = '';
    var previewWindow = null;

    function setStatus(kind, message) {
        if (!statusBox) {
            return;
        }
        var classMap = {
            loading: 'alert-light',
            ready: 'alert-success',
            limited: 'alert-warning',
            error: 'alert-danger'
        };
        statusBox.classList.remove('d-none', 'alert-light', 'alert-success', 'alert-warning', 'alert-danger');
        statusBox.classList.add(classMap[kind] || 'alert-light');
        statusBox.textContent = message || '';
        if (kind === 'ready') {
            window.setTimeout(function () {
                statusBox.classList.add('d-none');
            }, 1200);
        }
    }

    function loadPreview() {
        if (!frame || !currentPreviewSrc) {
            return;
        }
        previewWindow = null;
        setStatus('loading', 'Chargement de l apercu...');
        if (loadTimer) {
            clearTimeout(loadTimer);
        }
        frame.src = currentPreviewSrc + (currentPreviewSrc.indexOf('?') >= 0 ? '&' : '?') + 't=' + Date.now();
        loadTimer = setTimeout(function () {
            setStatus('limited', 'Chargement long. Cliquez sur Recharger l apercu ou utilisez le telechargement explicite si necessaire.');
        }, 8000);
    }

    modal.addEventListener('show.bs.modal', function (event) {
        var button = event.relatedTarget;
        if (!button) return;

        var docId = button.getAttribute('data-doc-id') || '';
        var previewSrc = button.getAttribute('data-doc-preview') || '';
        var matiereId = button.getAttribute('data-doc-matiere-id') || '';
        var matiereName = button.getAttribute('data-doc-matiere') || '';
        var pendingLabel = button.getAttribute('data-doc-matiere-pending') || '';

        document.getElementById('reviewDocTitle').textContent = button.getAttribute('data-doc-title') || '';
        document.getElementById('reviewDocAuthor').textContent = (button.getAttribute('data-doc-author') || '') + ' - ' + (button.getAttribute('data-doc-email') || '');
        document.getElementById('reviewDocFiliere').textContent = button.getAttribute('data-doc-filiere') || '';
        document.getElementById('reviewDocLicence').textContent = button.getAttribute('data-doc-licence') || '';
        document.getElementById('reviewDocMatiere').textContent = pendingLabel !== '' ? pendingLabel + ' (a valider)' : (matiereName || 'A valider');
        document.getElementById('reviewDocPublic').textContent = button.getAttribute('data-doc-public') || '';
        document.getElementById('reviewDocSize').textContent = button.getAttribute('data-doc-size') || '';
        document.getElementById('reviewDocMetaLine').textContent = 'Soumis le ' + (button.getAttribute('data-doc-created') || '');

        docIdInput.value = docId;
        actionInput.value = '';
        motifInput.value = '';
        matiereSelect.value = matiereId !== '0' ? matiereId : '';
        matiereLabel.value = pendingLabel;
        pendingWrap.style.display = pendingLabel !== '' ? 'block' : 'none';
        currentPreviewSrc = previewSrc;
        frame.onload = function () {
            previewWindow = frame.contentWindow || null;
        };
        frame.onerror = function () {
            if (loadTimer) {
                clearTimeout(loadTimer);
            }
            setStatus('error', 'Impossible d afficher l apercu. Utilisez le bouton de telechargement explicite.');
        };
        downloadForm.action = '../telecharger.php?id=' + encodeURIComponent(docId) + '&download=1';
        loadPreview();
    });

    modal.addEventListener('hidden.bs.modal', function () {
        currentPreviewSrc = '';
        previewWindow = null;
        frame.src = 'about:blank';
        if (loadTimer) {
            clearTimeout(loadTimer);
            loadTimer = null;
        }
        if (statusBox) {
            statusBox.classList.add('d-none');
        }
    });

    window.addEventListener('message', function (event) {
        if (!frame || !frame.contentWindow || event.source !== frame.contentWindow) {
            return;
        }
        if (!event.data || event.data.type !== 'emsp-preview-status') {
            return;
        }
        if (loadTimer) {
            clearTimeout(loadTimer);
            loadTimer = null;
        }
        if (event.data.status === 'ready') {
            setStatus('ready', event.data.message || 'Apercu charge.');
            return;
        }
        if (event.data.status === 'limited') {
            setStatus('limited', event.data.message || 'Apercu limite.');
            return;
        }
        if (event.data.status === 'error') {
            setStatus('error', event.data.message || 'Impossible d afficher l apercu.');
            return;
        }
        setStatus('loading', event.data.message || 'Chargement de l apercu...');
    });

    document.getElementById('approveDocBtn').addEventListener('click', function () {
        actionInput.value = 'approve';
        form.submit();
    });

    document.getElementById('rejectDocBtn').addEventListener('click', function () {
        if (motifInput.value.trim() === '') {
            motifInput.focus();
            return;
        }
        actionInput.value = 'reject';
        form.submit();
    });

    matiereSelect.addEventListener('change', function () {
        if (this.value !== '') {
            matiereLabel.value = '';
        }
    });
    matiereLabel.addEventListener('input', function () {
        if (this.value.trim() !== '') {
            matiereSelect.value = '';
        }
    });
    if (reloadButton) {
        reloadButton.addEventListener('click', loadPreview);
    }
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>




