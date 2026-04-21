<?php
ob_start();
include_once __DIR__ . '/bootstrap.php';

include_once __DIR__ . '/config/dbcon.php';
include_once __DIR__ . '/authentication.php';
include_once __DIR__ . '/../includes/csrf.php';
include_once __DIR__ . '/../includes/journal_helpers.php';
include_once __DIR__ . '/../includes/notif-helper.php';

$allowedTypes = ['annonce', 'defi', 'sondage'];
$hasAdmin = mysqli_fetch_assoc(mysqli_query($con, "SHOW COLUMNS FROM journal LIKE 'admin_id'")) ? true : false;
$hasAuthor = mysqli_fetch_assoc(mysqli_query($con, "SHOW COLUMNS FROM journal LIKE 'author_id'")) ? true : false;
$authorColumn = $hasAdmin ? 'admin_id' : ($hasAuthor ? 'author_id' : '');
$hasStartsAt = emsp_journal_has_column($con, 'starts_at');
$hasEndsAt = emsp_journal_has_column($con, 'ends_at');
$hasClosedAt = emsp_journal_has_column($con, 'closed_at');
$hasClosedBy = emsp_journal_has_column($con, 'closed_by');
$hasLifecycle = $hasStartsAt && $hasEndsAt;

$articleId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$editing = $articleId > 0;

$form = [
    'title' => '',
    'content' => '',
    'type' => 'annonce',
    'status' => 'draft',
    'starts_at' => '',
    'ends_at' => '',
];
$pollOptionsRaw = '';
$currentType = '';
$currentState = null;
$existingStatus = '';
$existingVoteCount = 0;
$existingDefiCount = 0;

if ($editing) {
    $selectFields = 'id, title, content, type, status';
    if ($hasLifecycle) {
        $selectFields .= ', starts_at, ends_at';
    }
    if ($hasClosedAt) {
        $selectFields .= ', closed_at';
    }

    $stmt = mysqli_prepare($con, "SELECT {$selectFields} FROM journal WHERE id=? LIMIT 1");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $articleId);
        mysqli_stmt_execute($stmt);
        $row = emsp_stmt_fetch_assoc($stmt);
        mysqli_stmt_close($stmt);
        if (!$row) {
            $_SESSION['message'] = 'Article introuvable.';
            header('Location: journal.php');
            exit;
        }

        $currentType = (string) ($row['type'] ?? '');
        $existingStatus = (string) ($row['status'] ?? '');
        $form['title'] = emsp_fix_mojibake((string) ($row['title'] ?? ''));
        $form['content'] = emsp_fix_mojibake((string) ($row['content'] ?? ''));
        $form['type'] = $currentType;
        $form['status'] = (string) ($row['status'] ?? 'draft');
        if ($hasLifecycle) {
            $form['starts_at'] = emsp_journal_datetime_local_value((string) ($row['starts_at'] ?? ''));
            $form['ends_at'] = emsp_journal_datetime_local_value((string) ($row['ends_at'] ?? ''));
        }
        $currentState = emsp_journal_state($row);
    }

    if ($currentType === 'sondage') {
        $stmt = mysqli_prepare($con, "SELECT label FROM journal_options WHERE journal_id=? ORDER BY id");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $articleId);
            mysqli_stmt_execute($stmt);
            $rows = emsp_stmt_fetch_all($stmt);
            mysqli_stmt_close($stmt);
            $options = [];
            foreach ($rows as $option) {
                $options[] = emsp_fix_mojibake((string) ($option['label'] ?? ''));
            }
            $pollOptionsRaw = implode("\n", $options);
        }

        $stmt = mysqli_prepare($con, "SELECT COUNT(*) AS nb FROM journal_votes WHERE journal_id=?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $articleId);
            mysqli_stmt_execute($stmt);
            $row = emsp_stmt_fetch_assoc($stmt);
            mysqli_stmt_close($stmt);
            $existingVoteCount = (int) ($row['nb'] ?? 0);
        }
    }

    if ($currentType === 'defi') {
        $stmt = mysqli_prepare($con, "SELECT COUNT(*) AS nb FROM journal_defis WHERE journal_id=?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $articleId);
            mysqli_stmt_execute($stmt);
            $row = emsp_stmt_fetch_assoc($stmt);
            mysqli_stmt_close($stmt);
            $existingDefiCount = (int) ($row['nb'] ?? 0);
        }
    }
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_article'])) {
    verify_csrf_token();

    $title = trim((string) ($_POST['title'] ?? ''));
    $contentRaw = (string) ($_POST['content'] ?? '');
    $type = strtolower(trim((string) ($_POST['type'] ?? 'annonce')));
    $submitAction = strtolower(trim((string) ($_POST['submit_action'] ?? 'draft')));
    $status = $submitAction === 'publish' ? 'published' : 'draft';
    $pollOptionsRaw = trim((string) ($_POST['poll_options'] ?? ''));
    $confirmResetResults = !empty($_POST['confirm_reset_results']);
    $startsAt = $hasLifecycle && in_array($type, ['sondage', 'defi'], true)
        ? emsp_journal_parse_datetime_input((string) ($_POST['starts_at'] ?? ''))
        : null;
    $endsAt = $hasLifecycle && in_array($type, ['sondage', 'defi'], true)
        ? emsp_journal_parse_datetime_input((string) ($_POST['ends_at'] ?? ''))
        : null;

    $pollOptions = [];
    if ($type === 'sondage' && $pollOptionsRaw !== '') {
        foreach (preg_split('/\r\n|\n|\r/', $pollOptionsRaw) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $pollOptions[] = $line;
            }
        }
    }

    $contentClean = emsp_journal_clean_html($contentRaw);
    $contentPlain = trim(strip_tags($contentClean));

    if ($title === '') {
        $errors[] = 'Le titre est obligatoire.';
    }
    if ($contentPlain === '') {
        $errors[] = 'Le contenu est obligatoire.';
    }
    if (mb_strlen($contentClean) > 65535) {
        $errors[] = 'Le contenu est trop long (max 65535 caracteres).';
    }
    if (!in_array($type, $allowedTypes, true)) {
        $errors[] = 'Type invalide.';
    }
    if ($type === 'sondage' && count($pollOptions) < 2) {
        $errors[] = 'Le sondage doit avoir au moins 2 options.';
    }
    if ($hasLifecycle && $startsAt !== null && $endsAt !== null && strtotime($endsAt) <= strtotime($startsAt)) {
        $errors[] = 'La date de fin doit etre posterieure a la date de debut.';
    }

    $form['title'] = $title;
    $form['content'] = $contentClean;
    $form['type'] = $type;
    $form['status'] = $status;
    $form['starts_at'] = emsp_journal_datetime_local_value($startsAt);
    $form['ends_at'] = emsp_journal_datetime_local_value($endsAt);

    $typeChanged = false;
    $optionsChanged = false;
    $currentOptions = [];
    $willResetVotes = false;
    $willResetDefis = false;

    if ($editing) {
        if ($currentType === 'sondage') {
            $stmt = mysqli_prepare($con, "SELECT label FROM journal_options WHERE journal_id=? ORDER BY id");
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'i', $articleId);
                mysqli_stmt_execute($stmt);
                $rows = emsp_stmt_fetch_all($stmt);
                mysqli_stmt_close($stmt);
                foreach ($rows as $option) {
                    $currentOptions[] = trim(emsp_fix_mojibake((string) ($option['label'] ?? '')));
                }
            }
        }

        $typeChanged = $currentType !== '' && $currentType !== $type;
        if ($type === 'sondage' || $currentType === 'sondage') {
            $optionsChanged = array_map('trim', $currentOptions) !== array_map('trim', $pollOptions);
        }

        $willResetVotes = $currentType === 'sondage' && ($type !== 'sondage' || $optionsChanged);
        $willResetDefis = $currentType === 'defi' && $type !== 'defi';

        if ($willResetVotes && $existingVoteCount > 0 && !$confirmResetResults) {
            $errors[] = 'Confirmez la reinitialisation des votes avant de modifier ce sondage.';
        }
        if ($willResetDefis && $existingDefiCount > 0 && !$confirmResetResults) {
            $errors[] = 'Confirmez la reinitialisation des participations avant de modifier ce defi.';
        }
    }

    if (empty($errors)) {
        mysqli_begin_transaction($con);
        try {
            if ($editing) {
                if ($hasLifecycle) {
                    if ($type === 'annonce') {
                        if ($hasClosedAt && $hasClosedBy) {
                            $stmt = mysqli_prepare($con, "UPDATE journal SET title=?, content=?, type=?, status=?, starts_at=NULL, ends_at=NULL, closed_at=NULL, closed_by=NULL WHERE id=? LIMIT 1");
                            mysqli_stmt_bind_param($stmt, 'ssssi', $title, $contentClean, $type, $status, $articleId);
                        } else {
                            $stmt = mysqli_prepare($con, "UPDATE journal SET title=?, content=?, type=?, status=?, starts_at=NULL, ends_at=NULL WHERE id=? LIMIT 1");
                            mysqli_stmt_bind_param($stmt, 'ssssi', $title, $contentClean, $type, $status, $articleId);
                        }
                    } elseif ($typeChanged && $hasClosedAt && $hasClosedBy) {
                        $stmt = mysqli_prepare($con, "UPDATE journal SET title=?, content=?, type=?, status=?, starts_at=?, ends_at=?, closed_at=NULL, closed_by=NULL WHERE id=? LIMIT 1");
                        mysqli_stmt_bind_param($stmt, 'ssssssi', $title, $contentClean, $type, $status, $startsAt, $endsAt, $articleId);
                    } else {
                        $stmt = mysqli_prepare($con, "UPDATE journal SET title=?, content=?, type=?, status=?, starts_at=?, ends_at=? WHERE id=? LIMIT 1");
                        mysqli_stmt_bind_param($stmt, 'ssssssi', $title, $contentClean, $type, $status, $startsAt, $endsAt, $articleId);
                    }
                } else {
                    $stmt = mysqli_prepare($con, "UPDATE journal SET title=?, content=?, type=?, status=? WHERE id=? LIMIT 1");
                    mysqli_stmt_bind_param($stmt, 'ssssi', $title, $contentClean, $type, $status, $articleId);
                }
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                $currentId = $articleId;
            } else {
                if ($authorColumn !== '') {
                    $authorId = (int) ($_SESSION['auth_user']['id'] ?? 0);
                    if ($hasLifecycle) {
                        $stmt = mysqli_prepare($con, "INSERT INTO journal (type, title, content, status, {$authorColumn}, starts_at, ends_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                        $insertStartsAt = $type === 'annonce' ? null : $startsAt;
                        $insertEndsAt = $type === 'annonce' ? null : $endsAt;
                        mysqli_stmt_bind_param($stmt, 'ssssiss', $type, $title, $contentClean, $status, $authorId, $insertStartsAt, $insertEndsAt);
                    } else {
                        $stmt = mysqli_prepare($con, "INSERT INTO journal (type, title, content, status, {$authorColumn}, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                        mysqli_stmt_bind_param($stmt, 'ssssi', $type, $title, $contentClean, $status, $authorId);
                    }
                } else {
                    if ($hasLifecycle) {
                        $stmt = mysqli_prepare($con, "INSERT INTO journal (type, title, content, status, starts_at, ends_at, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                        $insertStartsAt = $type === 'annonce' ? null : $startsAt;
                        $insertEndsAt = $type === 'annonce' ? null : $endsAt;
                        mysqli_stmt_bind_param($stmt, 'ssssss', $type, $title, $contentClean, $status, $insertStartsAt, $insertEndsAt);
                    } else {
                        $stmt = mysqli_prepare($con, "INSERT INTO journal (type, title, content, status, created_at) VALUES (?, ?, ?, ?, NOW())");
                        mysqli_stmt_bind_param($stmt, 'ssss', $type, $title, $contentClean, $status);
                    }
                }
                mysqli_stmt_execute($stmt);
                $currentId = (int) mysqli_insert_id($con);
                mysqli_stmt_close($stmt);
            }

            if ($editing && $willResetVotes) {
                $stmt = mysqli_prepare($con, "DELETE FROM journal_votes WHERE journal_id=?");
                mysqli_stmt_bind_param($stmt, 'i', $currentId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                $stmt = mysqli_prepare($con, "DELETE FROM journal_options WHERE journal_id=?");
                mysqli_stmt_bind_param($stmt, 'i', $currentId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }

            if ($editing && $willResetDefis) {
                $stmt = mysqli_prepare($con, "DELETE FROM journal_defis WHERE journal_id=?");
                mysqli_stmt_bind_param($stmt, 'i', $currentId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }

            if ($type === 'sondage') {
                $refreshOptions = !$editing || $currentType !== 'sondage' || $optionsChanged;
                if ($refreshOptions) {
                    if ($editing && !$willResetVotes) {
                        $stmt = mysqli_prepare($con, "DELETE FROM journal_options WHERE journal_id=?");
                        mysqli_stmt_bind_param($stmt, 'i', $currentId);
                        mysqli_stmt_execute($stmt);
                        mysqli_stmt_close($stmt);
                    }

                    $stmt = mysqli_prepare($con, "INSERT INTO journal_options (journal_id, label) VALUES (?, ?)");
                    foreach ($pollOptions as $optionLabel) {
                        mysqli_stmt_bind_param($stmt, 'is', $currentId, $optionLabel);
                        mysqli_stmt_execute($stmt);
                    }
                    mysqli_stmt_close($stmt);
                }
            } elseif ($editing && $currentType === 'sondage') {
                $stmt = mysqli_prepare($con, "DELETE FROM journal_options WHERE journal_id=?");
                mysqli_stmt_bind_param($stmt, 'i', $currentId);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }

            mysqli_commit($con);
            $shouldNotify = $status === 'published' && (!$editing || $existingStatus !== 'published');
            if ($shouldNotify) {
                notify_journal_published_all($con, $currentId, (string) $title, (int) ($_SESSION['auth_user']['id'] ?? 0));
            }
            $_SESSION['message'] = $status === 'published'
                ? ($editing ? 'Article mis a jour et publie.' : 'Article publie avec succes.')
                : 'Brouillon enregistre.';
            header('Location: journal.php');
            exit;
        } catch (Throwable $e) {
            mysqli_rollback($con);
            $errors[] = 'Impossible d enregistrer le contenu pour le moment.';
        }
    }
}

$pageTitle = $editing ? 'Modifier un contenu du journal' : 'Nouveau contenu du journal';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
include __DIR__ . '/includes/navbar-top.php';
?>
<link rel="stylesheet" href="../assets/css/quill.snow.css">

<div id="admin-content">
<div id="main-content" class="container-fluid">
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger" role="alert">
            <ul class="mb-0 ps-3">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-lg-8">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body p-4">
                    <div class="d-flex flex-wrap justify-content-between gap-3 align-items-start">
                        <div>
                            <span class="badge rounded-pill text-bg-light border mb-2">Journal EMSP</span>
                            <h1 class="h3 fw-bold mb-2"><?= htmlspecialchars($pageTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></h1>
                            <p class="text-muted mb-0">Crée une annonce, un défi ou un sondage avec une période d ouverture optionnelle et un contenu éditorial plus propre.</p>
                        </div>
                        <?php if ($currentState): ?>
                            <div class="text-lg-end">
                                <div class="small text-uppercase text-muted fw-semibold">Etat actuel</div>
                                <div class="badge fs-6 rounded-pill text-bg-light border"><?= htmlspecialchars($currentState['label'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></div>
                                <?php if ($currentState['starts_at_label'] !== '' || $currentState['ends_at_label'] !== ''): ?>
                                    <div class="small text-muted mt-2">
                                        <?php if ($currentState['starts_at_label'] !== ''): ?>
                                            <div>Debut : <?= htmlspecialchars($currentState['starts_at_label'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                        <?php if ($currentState['ends_at_label'] !== ''): ?>
                                            <div>Fin : <?= htmlspecialchars($currentState['ends_at_label'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body p-4">
                    <div class="small text-uppercase text-muted fw-semibold mb-2">Impact actuel</div>
                    <div class="d-flex flex-column gap-2">
                        <div class="d-flex justify-content-between align-items-center rounded-4 border px-3 py-2">
                            <span>Votes sondage</span>
                            <strong><?= (int) $existingVoteCount ?></strong>
                        </div>
                        <div class="d-flex justify-content-between align-items-center rounded-4 border px-3 py-2">
                            <span>Participants défi</span>
                            <strong><?= (int) $existingDefiCount ?></strong>
                        </div>
                        <?php if (!$hasLifecycle): ?>
                            <div class="alert alert-warning mb-0 mt-2 small">La migration du cycle de vie du journal n est pas encore appliquee. Les champs de duree resteront masques tant que les colonnes SQL n existent pas.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-body p-4 p-lg-5">
            <form method="POST" id="journal-form" data-emsp-submit="1">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="id" value="<?= (int) $articleId ?>">
                <input type="hidden" name="save_article" value="1">

                <div class="row g-4">
                    <div class="col-lg-8">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Titre</label>
                            <input type="text" name="title" class="form-control form-control-lg" required value="<?= htmlspecialchars($form['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-5">
                                <label class="form-label fw-semibold">Type</label>
                                <select name="type" class="form-select" id="field-type">
                                    <option value="annonce" <?= $form['type'] === 'annonce' ? 'selected' : '' ?>>Annonce</option>
                                    <option value="defi" <?= $form['type'] === 'defi' ? 'selected' : '' ?>>Defi</option>
                                    <option value="sondage" <?= $form['type'] === 'sondage' ? 'selected' : '' ?>>Sondage</option>
                                </select>
                            </div>
                            <div class="col-md-7 d-flex align-items-end">
                                <div class="rounded-4 border bg-light px-3 py-2 w-100 small text-muted" id="journal-type-help">
                                    Les annonces sont immediates. Les sondages et defis peuvent recevoir une date de debut et une date de fin.
                                </div>
                            </div>
                        </div>

                        <div class="row g-3 mb-3<?= ($hasLifecycle && in_array($form['type'], ['sondage', 'defi'], true)) ? '' : ' emsp-hidden' ?>" id="lifecycle-block">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Debut (optionnel)</label>
                                <input type="datetime-local" name="starts_at" class="form-control" value="<?= htmlspecialchars($form['starts_at'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Fin (optionnelle)</label>
                                <input type="datetime-local" name="ends_at" class="form-control" value="<?= htmlspecialchars($form['ends_at'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
                            </div>
                            <div class="col-12">
                                <div class="small text-muted">Si aucune date n est renseignee, le contenu reste ouvert tant qu il est publie et non cloture manuellement.</div>
                            </div>
                        </div>

                        <div class="mb-3<?= $form['type'] === 'sondage' ? '' : ' emsp-hidden' ?>" id="poll-block">
                            <label class="form-label fw-semibold">Options du sondage</label>
                            <textarea name="poll_options" class="form-control" rows="6" placeholder="Option 1&#10;Option 2&#10;Option 3"><?= htmlspecialchars($pollOptionsRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></textarea>
                            <div class="small text-muted mt-2">Une ligne = une option. Modifier les options d un sondage deja vote reinitialise les votes si tu confirmes cette action.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Contenu</label>
                            <div class="border rounded-4 overflow-hidden bg-white">
                                <div id="journal-editor" class="emsp-editor-min-360 emsp-editor-scroll"></div>
                            </div>
                            <input type="hidden" name="content" id="journal-content-hidden" value="<?= htmlspecialchars($form['content'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
                            <input type="hidden" id="initial-content" value="<?= htmlspecialchars($form['content'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
                            <div class="small text-muted mt-2">Tu peux televerser une image, la redimensionner, l aligner a gauche, au centre ou a droite, puis la monter ou la descendre plus facilement dans le flux.</div>
                        </div>
                    </div>

                    <div class="col-lg-4">
                        <div class="card border-0 bg-light h-100">
                            <div class="card-body p-4">
                                <h2 class="h5 fw-bold mb-3">Validation editoriale</h2>
                                <div class="d-flex flex-column gap-3 small text-muted">
                                    <div class="rounded-4 border bg-white px-3 py-3">
                                        <strong class="d-block text-dark mb-1">Publication</strong>
                                        <span>Le brouillon reste invisible. Publier rend le contenu disponible selon son etat ouvert, planifie, clos ou expire.</span>
                                    </div>
                                    <div class="rounded-4 border bg-white px-3 py-3">
                                        <strong class="d-block text-dark mb-1">Sondages</strong>
                                        <span>Les resultats resteront publics. Un vote par utilisateur, modifiable tant que le sondage est ouvert.</span>
                                    </div>
                                    <div class="rounded-4 border bg-white px-3 py-3">
                                        <strong class="d-block text-dark mb-1">Defis</strong>
                                        <span>La participation reste unique par utilisateur et la note peut etre mise a jour tant que le defi est ouvert.</span>
                                    </div>
                                </div>

                                <?php if ($editing && ($existingVoteCount > 0 || $existingDefiCount > 0)): ?>
                                    <div class="alert alert-warning mt-4 mb-0">
                                        <div class="fw-semibold mb-2">Attention aux reinitialisations</div>
                                        <p class="small mb-2">Si tu modifies le type ou les options d un contenu deja utilise, les votes ou participations existants seront supprimes.</p>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" value="1" id="confirm-reset-results" name="confirm_reset_results" <?= !empty($_POST['confirm_reset_results']) ? 'checked' : '' ?>>
                                            <label class="form-check-label small" for="confirm-reset-results">
                                                J ai compris et j autorise la reinitialisation des resultats si cette modification l exige.
                                            </label>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2 mt-4">
                    <button type="submit" name="submit_action" value="draft" class="btn btn-outline-secondary" data-loading-text="Enregistrement du brouillon...">Enregistrer en brouillon</button>
                    <button type="submit" name="submit_action" value="publish" class="btn btn-emsp" data-loading-text="Publication en cours...">
                        <i class="bi bi-send-check me-2"></i>Publier
                    </button>
                    <a href="journal.php" class="btn btn-outline-dark">Retour au journal</a>
                </div>
            </form>
        </div>
    </div>
</div>
</div>
<?php
$initialContentJson = json_encode((string) $form['content'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$csrfTokenJson = json_encode(generate_csrf_token(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$typeJson = json_encode((string) $form['type'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$page_scripts = <<<HTML
<script src="../assets/js/quill.min.js"></script>
<script src="../assets/js/emsp-quill-image-tools.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var quill = new Quill('#journal-editor', {
        theme: 'snow',
        modules: {
            toolbar: [
                [{ header: [1, 2, 3, false] }],
                ['bold', 'italic', 'underline', 'strike'],
                [{ color: [] }, { background: [] }],
                [{ align: [] }],
                ['blockquote', 'code-block'],
                [{ list: 'ordered' }, { list: 'bullet' }],
                ['link', 'image'],
                ['clean']
            ]
        }
    });

    var initialContent = {$initialContentJson};
    if (initialContent) {
        quill.root.innerHTML = initialContent;
    }

    if (typeof window.emspAttachQuillImageTools === 'function') {
        window.emspAttachQuillImageTools(quill, {
            uploadUrl: '../admin/upload-journal-image.php',
            uploadPrefix: '../',
            csrfToken: {$csrfTokenJson}
        });
    }

    var form = document.getElementById('journal-form');
    var hiddenContent = document.getElementById('journal-content-hidden');
    if (form && hiddenContent) {
        form.addEventListener('submit', function () {
            hiddenContent.value = quill.root.innerHTML;
        });
    }

    var typeField = document.getElementById('field-type');
    var pollBlock = document.getElementById('poll-block');
    var lifecycleBlock = document.getElementById('lifecycle-block');
    var typeHelp = document.getElementById('journal-type-help');

    function syncTypeUi() {
        if (!typeField) {
            return;
        }
        var value = typeField.value;
        if (pollBlock) {
            var showPoll = value === 'sondage';
            pollBlock.classList.toggle('emsp-hidden', !showPoll);
            pollBlock.style.display = showPoll ? 'block' : 'none';
        }
        if (lifecycleBlock) {
            var showLifecycle = (value === 'sondage' || value === 'defi');
            lifecycleBlock.classList.toggle('emsp-hidden', !showLifecycle);
            lifecycleBlock.style.display = showLifecycle ? 'flex' : 'none';
        }
        if (typeHelp) {
            if (value === 'sondage') {
                typeHelp.textContent = 'Le sondage accepte une date de debut, une date de fin et des resultats publics toujours visibles.';
            } else if (value === 'defi') {
                typeHelp.textContent = 'Le defi accepte une date de debut, une date de fin et des participations modifiables tant qu il reste ouvert.';
            } else {
                typeHelp.textContent = 'Les annonces sont immediates. Les sondages et defis peuvent recevoir une periode d ouverture optionnelle.';
            }
        }
    }

    if (typeField) {
        typeField.addEventListener('change', syncTypeUi);
    }
    syncTypeUi();
});
</script>
HTML;
include __DIR__ . '/includes/footer.php';
?>


