<?php
ob_start();
include_once __DIR__ . '/bootstrap.php';
if (empty($_SESSION['auth']) || !in_array($_SESSION['auth_role'] ?? '', ['admin','moderateur'], true)) {
    header('Location: ../index.php?open_login=1'); exit;
}

include_once __DIR__ . '/config/dbcon.php';
include_once __DIR__ . '/authentication.php';
include_once __DIR__ . '/../includes/csrf.php';

function emsp_is_ajax(): bool
{
    return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
}

function emsp_json(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data);
    exit;
}

function emsp_check_csrf(string $token): bool
{
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    return $sessionToken !== '' && $token !== '' && hash_equals($sessionToken, $token);
}

function emsp_slugify(string $text): string
{
    $text = trim($text);
    if ($text === '') return '';
    $slug = $text;
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);
        if ($converted !== false) {
            $slug = $converted;
        }
    }
    $slug = strtolower($slug);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    if ($slug === '') {
        $slug = 'section-' . bin2hex(random_bytes(4));
    }
    return substr($slug, 0, 80);
}

function emsp_upload_image(string $field, string $destSubdir): array
{
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Aucun fichier reÃ§u ou erreur upload'];
    }
    $maxSize = 5 * 1024 * 1024;
    if ($_FILES[$field]['size'] > $maxSize) {
        return ['ok' => false, 'error' => 'Fichier trop lourd (max 5 Mo)'];
    }
    if (!function_exists('finfo_open')) {
        return ['ok' => false, 'error' => 'finfo_file indisponible'];
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $_FILES[$field]['tmp_name']);

    $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $mimeToExt = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    if (!in_array($mime, $allowedMimes, true)) {
        return ['ok' => false, 'error' => 'Type de fichier non autorisÃ©'];
    }

    $ext = $mimeToExt[$mime];
    $hash = bin2hex(random_bytes(16));
    $destDir = __DIR__ . '/../uploads/media/' . $destSubdir;
    if (!is_dir($destDir)) {
        if (!mkdir($destDir, 0755, true)) {
            return ['ok' => false, 'error' => 'Impossible de crÃ©er le dossier d upload'];
        }
    }
    $realDir = realpath($destDir);
    if ($realDir === false) {
        return ['ok' => false, 'error' => 'Dossier de destination introuvable'];
    }
    // chmod dÃ©sactivÃ© sur hÃ©bergement mutualisÃ© (ProFreeHost gÃ¨re les permissions).
    if (!is_writable($realDir)) {
        return ['ok' => false, 'error' => 'Dossier upload non accessible en Ã©criture'];
    }

    $filename = $hash . '.' . $ext;
    $destPath = $realDir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $destPath)) {
        return ['ok' => false, 'error' => 'Ã‰chec sauvegarde fichier'];
    }
    return ['ok' => true, 'url' => 'uploads/media/' . $destSubdir . '/' . $filename];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = trim((string)$_POST['action']);
    $csrf = (string)($_POST['csrf_token'] ?? '');

    if (!emsp_check_csrf($csrf)) {
        emsp_json(['ok' => false, 'error' => 'Token CSRF invalide'], 403);
    }

    if ($action === 'upload_bloc_image') {
        $upload = emsp_upload_image('image', 'institution');
        if (!$upload['ok']) {
            emsp_json(['ok' => false, 'error' => $upload['error']], 400);
        }
        emsp_json(['ok' => true, 'url' => $upload['url']]);
    }

    if ($action === 'save_bloc') {
        $bloc_id = intval($_POST['bloc_id'] ?? 0);
        $bloc_type = trim((string)($_POST['bloc_type'] ?? 'texte'));
        $section_label = trim((string)($_POST['section_label'] ?? ''));
        $section_key = trim((string)($_POST['section_key'] ?? ''));
        $contenu = (string)($_POST['contenu'] ?? '');
        $image_path = trim((string)($_POST['image_path'] ?? ''));
        $config_json = (string)($_POST['config_json'] ?? '');
        $ordre = intval($_POST['ordre'] ?? 0);
        $visible = isset($_POST['visible']) ? intval($_POST['visible']) : 1;
        $visible = $visible ? 1 : 0;

        $allowedTypes = ['texte','image','galerie','stats','citation','colonnes','separateur'];
        if ($section_label === '') {
            emsp_json(['ok' => false, 'error' => 'Le titre de section est obligatoire']);
        }
        if (!in_array($bloc_type, $allowedTypes, true)) {
            emsp_json(['ok' => false, 'error' => 'Type de bloc invalide']);
        }
        if ($section_key === '') {
            $section_key = emsp_slugify($section_label);
        }

        if ($bloc_id <= 0 && $ordre <= 0) {
            $res = mysqli_query($con, "SELECT COALESCE(MAX(ordre),0) FROM institution_blocs");
            if ($res) {
                $row = mysqli_fetch_row($res);
                $ordre = intval($row[0] ?? 0) + 1;
            } else {
                $ordre = 1;
            }
        }

        if ($bloc_id > 0) {
            $s = mysqli_prepare($con, "UPDATE institution_blocs SET section_key=?, section_label=?, bloc_type=?, contenu=?, image_path=?, config_json=?, ordre=?, visible=? WHERE id=? LIMIT 1");
            if ($s) {
                mysqli_stmt_bind_param($s, 'ssssssiii', $section_key, $section_label, $bloc_type, $contenu, $image_path, $config_json, $ordre, $visible, $bloc_id);
                mysqli_stmt_execute($s);
                mysqli_stmt_close($s);
            }
            emsp_json(['ok' => true, 'id' => $bloc_id]);
        } else {
            $s = mysqli_prepare($con, "INSERT INTO institution_blocs (section_key, section_label, bloc_type, contenu, image_path, config_json, ordre, visible) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            if ($s) {
                mysqli_stmt_bind_param($s, 'ssssssii', $section_key, $section_label, $bloc_type, $contenu, $image_path, $config_json, $ordre, $visible);
                mysqli_stmt_execute($s);
                $newId = mysqli_insert_id($con);
                mysqli_stmt_close($s);
                emsp_json(['ok' => true, 'id' => $newId]);
            }
            emsp_json(['ok' => false, 'error' => 'Erreur lors de la sauvegarde'], 500);
        }
    }

    if ($action === 'delete_bloc') {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) emsp_json(['ok' => false, 'error' => 'ID invalide']);
        $s = mysqli_prepare($con, "DELETE FROM institution_blocs WHERE id=? LIMIT 1");
        if ($s) {
            mysqli_stmt_bind_param($s, 'i', $id);
            mysqli_stmt_execute($s);
            mysqli_stmt_close($s);
        }
        emsp_json(['ok' => true]);
    }

    if ($action === 'toggle_bloc') {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) emsp_json(['ok' => false, 'error' => 'ID invalide']);
        $s = mysqli_prepare($con, "UPDATE institution_blocs SET visible = 1 - visible WHERE id=? LIMIT 1");
        if ($s) {
            mysqli_stmt_bind_param($s, 'i', $id);
            mysqli_stmt_execute($s);
            mysqli_stmt_close($s);
        }
        emsp_json(['ok' => true]);
    }

    if ($action === 'reorder_blocs') {
        $orderJson = (string)($_POST['order'] ?? '[]');
        $ids = json_decode($orderJson, true);
        if (!is_array($ids)) {
            emsp_json(['ok' => false, 'error' => 'Ordre invalide']);
        }
        $s = mysqli_prepare($con, "UPDATE institution_blocs SET ordre=? WHERE id=?");
        if ($s) {
            $pos = 1;
            foreach ($ids as $id) {
                $id = intval($id);
                if ($id <= 0) continue;
                mysqli_stmt_bind_param($s, 'ii', $pos, $id);
                mysqli_stmt_execute($s);
                $pos++;
            }
            mysqli_stmt_close($s);
        }
        emsp_json(['ok' => true]);
    }

    emsp_json(['ok' => false, 'error' => 'Action inconnue'], 400);
}

function emsp_admin_institution_fix_text(string $text): string
{
    return function_exists('emsp_fix_mojibake') ? emsp_fix_mojibake($text) : $text;
}

// Charger les blocs
$blocs = [];
$blocMap = [];
$tableExists = false;
$check = mysqli_query($con, "SHOW TABLES LIKE 'institution_blocs'");
if ($check && mysqli_num_rows($check) > 0) {
    $tableExists = true;
}

if (!$tableExists) {
    die('Erreur : table institution_blocs manquante. ExÃ©cutez les migrations : vendor/bin/phinx migrate');
}

if ($tableExists) {
    $q = mysqli_query($con, "SELECT * FROM institution_blocs ORDER BY ordre ASC, id ASC");
    if ($q) {
        while ($row = mysqli_fetch_assoc($q)) {
            $blocs[] = $row;
            $id = (int)($row['id'] ?? 0);
            if ($id > 0) {
                $blocMap[$id] = [
                    'id' => $id,
                    'section_key' => (string)($row['section_key'] ?? ''),
                    'section_label' => emsp_admin_institution_fix_text((string)($row['section_label'] ?? '')),
                    'bloc_type' => (string)($row['bloc_type'] ?? 'texte'),
                    'contenu' => emsp_admin_institution_fix_text((string)($row['contenu'] ?? '')),
                    'image_path' => (string)($row['image_path'] ?? ''),
                    'config_json' => emsp_admin_institution_fix_text((string)($row['config_json'] ?? '')),
                    'ordre' => (int)($row['ordre'] ?? 0),
                    'visible' => (int)($row['visible'] ?? 1),
                ];
            }
        }
    }
}

function emsp_bloc_preview(array $bloc): string
{
    $type = (string)($bloc['bloc_type'] ?? '');
    $label = emsp_admin_institution_fix_text((string)($bloc['section_label'] ?? ''));
    $text = '';
    if ($type === 'texte' || $type === 'colonnes') {
        $text = strip_tags((string)($bloc['contenu'] ?? ''));
    } elseif ($type === 'citation') {
        $cfg = json_decode((string)($bloc['config_json'] ?? ''), true);
        $text = is_array($cfg) ? (string)($cfg['texte'] ?? '') : '';
    } elseif ($type === 'stats') {
        $cfg = json_decode((string)($bloc['config_json'] ?? ''), true);
        $items = is_array($cfg['items'] ?? null) ? count($cfg['items']) : 0;
        $text = $items > 0 ? $items . ' stats' : '';
    } elseif ($type === 'galerie') {
        $cfg = json_decode((string)($bloc['config_json'] ?? ''), true);
        $imgs = is_array($cfg['images'] ?? null) ? count($cfg['images']) : 0;
        $text = $imgs > 0 ? $imgs . ' images' : '';
    } elseif ($type === 'image') {
        $text = (string)($bloc['image_path'] ?? '');
    } elseif ($type === 'separateur') {
        $text = 'SÃ©parateur';
    }
    $text = trim($text) !== '' ? $text : $label;
    if ($text === '') $text = 'Bloc sans aperÃ§u';
    $text = preg_replace('/\\s+/', ' ', $text);
    return mb_substr($text, 0, 120);
}

$page_title = 'Gestion de la page Institution';
$csrfToken = generate_csrf_token();
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
include __DIR__ . '/includes/navbar-top.php';
?>

<!-- Quill CSS â€” local -->
<link rel="stylesheet" href="../assets/css/quill.snow.css">

<div id="admin-content">
<div id="main-content" class="container-fluid">

    <div class="d-flex flex-wrap align-items-center justify-content-between mb-4">
        <div>
            <h2 class="h4 fw-bold mb-1">Gestion de la page Institution</h2>
            <p class="text-muted mb-0">CrÃ©ez, rÃ©ordonnez et publiez des blocs dynamiques.</p>
        </div>
        <button class="btn btn-primary" id="btn-add-bloc" type="button">
            <i class="bi bi-plus-lg me-1"></i>Ajouter un bloc
        </button>
    </div>

    <?php if (!$tableExists): ?>
        <div class="alert alert-warning">
            La table <strong>institution_blocs</strong> n'existe pas.
            Importez <code>admin/migration_institution_blocs.sql</code> puis rechargez la page.
        </div>
    <?php endif; ?>

    <div id="blocs-list" class="row g-3">
        <?php if (empty($blocs)): ?>
            <div class="col-12">
                <div class="alert alert-info mb-0">Aucun bloc pour le moment.</div>
            </div>
        <?php endif; ?>

        <?php foreach ($blocs as $bloc): ?>
            <?php $id = (int)($bloc['id'] ?? 0); ?>
            <div class="col-12 bloc-item" data-id="<?= $id ?>">
                <div class="card shadow-sm border-0">
                    <div class="card-body d-flex flex-wrap align-items-center gap-3">
                        <div class="drag-handle text-muted" title="DÃ©placer">
                            <i class="bi bi-grip-vertical fs-5"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                <span class="badge bg-primary text-uppercase"><?= htmlspecialchars((string)($bloc['bloc_type'] ?? '')) ?></span>
                                <strong><?= htmlspecialchars(emsp_admin_institution_fix_text((string)($bloc['section_label'] ?? ''))) ?></strong>
                                <?php if ((int)($bloc['visible'] ?? 1) === 0): ?>
                                    <span class="badge bg-secondary">MasquÃ©</span>
                                <?php endif; ?>
                            </div>
                            <div class="text-muted small"><?= htmlspecialchars(emsp_bloc_preview($bloc)) ?></div>
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-sm btn-outline-primary js-edit" data-id="<?= $id ?>" type="button">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-secondary js-toggle" data-id="<?= $id ?>" type="button">
                                <i class="bi bi-eye<?= ((int)($bloc['visible'] ?? 1) === 1) ? '-slash' : '' ?>"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger js-delete" data-id="<?= $id ?>" type="button">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
</div>

<!-- Modal bloc -->
<div class="modal fade" id="blocModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Ã‰diter un bloc</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>
      <div class="modal-body">
        <div id="bloc-error" class="alert alert-danger d-none"></div>
        <input type="hidden" id="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
        <input type="hidden" id="bloc_id" value="0">
        <input type="hidden" id="section_key" value="">

        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-semibold">Titre de section</label>
            <input type="text" class="form-control" id="section_label" placeholder="Ex: A propos, Mission...">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Type de bloc</label>
            <select class="form-select" id="bloc_type">
              <option value="texte">Texte riche</option>
              <option value="image">Image</option>
              <option value="galerie">Galerie de photos</option>
              <option value="stats">Stats / chiffres cles</option>
              <option value="citation">Citation</option>
              <option value="colonnes">Colonnes (2 ou 3)</option>
              <option value="separateur">Separateur</option>
            </select>
          </div>
        </div>

        <div class="form-check form-switch mt-3">
          <input class="form-check-input" type="checkbox" id="bloc_visible" checked>
          <label class="form-check-label" for="bloc_visible">Bloc visible sur le site</label>
        </div>

        <!-- Texte -->
        <div class="bloc-fields mt-3" data-bloc="texte">
          <label class="form-label fw-semibold">Contenu</label>
          <div id="bloc-editor" class="emsp-editor-min-280"></div>
        </div>

        <!-- Image -->
        <div class="bloc-fields mt-3 emsp-hidden" data-bloc="image">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Image</label>
              <input type="file" class="form-control" id="image_file" accept="image/jpeg,image/png,image/webp,image/gif">
              <div class="form-text">JPG/PNG/WEBP/GIF, max 5 Mo.</div>
              <input type="hidden" id="image_path">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Alignement</label>
              <select class="form-select" id="image_align">
                <option value="full">Pleine largeur</option>
                <option value="center">Centre</option>
                <option value="left">Gauche</option>
                <option value="right">Droite</option>
              </select>
              <label class="form-label fw-semibold mt-3">Legende</label>
              <input type="text" class="form-control" id="image_legende" placeholder="Texte sous l'image">
            </div>
          </div>
          <div class="mt-3 emsp-hidden" id="image_preview_wrap">
            <img id="image_preview" src="" class="img-fluid rounded">
          </div>
        </div>

        <!-- Galerie -->
        <div class="bloc-fields mt-3 emsp-hidden" data-bloc="galerie">
          <label class="form-label fw-semibold">Images (max 10)</label>
          <input type="file" class="form-control" id="gallery_files" accept="image/jpeg,image/png,image/webp,image/gif" multiple>
          <div class="form-text">Les images sont uploadees des la selection.</div>
          <div class="row g-2 mt-2" id="gallery_preview"></div>
          <div class="mt-3">
            <label class="form-label fw-semibold">Colonnes</label>
            <select class="form-select" id="gallery_cols">
              <option value="2">2 colonnes</option>
              <option value="3" selected>3 colonnes</option>
              <option value="4">4 colonnes</option>
            </select>
          </div>
        </div>

        <!-- Stats -->
        <div class="bloc-fields mt-3 emsp-hidden" data-bloc="stats">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <label class="form-label fw-semibold mb-0">Statistiques</label>
            <button class="btn btn-sm btn-outline-primary" type="button" id="btn-add-stat">
              <i class="bi bi-plus-lg"></i> Ajouter une stat
            </button>
          </div>
          <div id="stats-list" class="vstack gap-2"></div>
        </div>

        <!-- Citation -->
        <div class="bloc-fields mt-3 emsp-hidden" data-bloc="citation">
          <label class="form-label fw-semibold">Texte de la citation</label>
          <textarea class="form-control" rows="4" id="quote_text"></textarea>
          <label class="form-label fw-semibold mt-2">Auteur (optionnel)</label>
          <input type="text" class="form-control" id="quote_author" placeholder="Nom de l'auteur">
        </div>

        <!-- Colonnes -->
        <div class="bloc-fields mt-3 emsp-hidden" data-bloc="colonnes">
          <label class="form-label fw-semibold">Nombre de colonnes</label>
          <select class="form-select" id="cols_count">
            <option value="2">2 colonnes</option>
            <option value="3">3 colonnes</option>
          </select>
          <div class="row g-3 mt-2">
            <div class="col-md-6">
              <label class="form-label small text-muted">Colonne 1</label>
              <div id="col-editor-1" class="emsp-editor-min-200"></div>
            </div>
            <div class="col-md-6">
              <label class="form-label small text-muted">Colonne 2</label>
              <div id="col-editor-2" class="emsp-editor-min-200"></div>
            </div>
            <div class="col-md-6 emsp-hidden" id="col-3-wrap">
              <label class="form-label small text-muted">Colonne 3</label>
              <div id="col-editor-3" class="emsp-editor-min-200"></div>
            </div>
          </div>
        </div>

        <!-- Separateur -->
        <div class="bloc-fields mt-3 emsp-hidden" data-bloc="separateur">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Style</label>
              <select class="form-select" id="sep_style">
                <option value="solid">Solide</option>
                <option value="dashed">Tirets</option>
                <option value="dotted">Pointille</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Couleur</label>
              <input type="color" class="form-control form-control-color" id="sep_color" value="#e2e8f0">
            </div>
          </div>
        </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button>
        <button type="button" class="btn btn-primary" id="btn-save-bloc">Enregistrer</button>
      </div>
    </div>
  </div>
</div>

</div><!-- /#main-content -->
</div><!-- /#admin-content -->

<?php
$jsonFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;
$blocJson = json_encode($blocMap, $jsonFlags);
if ($blocJson === false) { $blocJson = '{}'; }
$page_scripts = <<<HTML
<!-- Quill JS â€” local -->
<script src="../assets/js/quill.min.js"></script>
<script src="../assets/js/emsp-quill-image-tools.js"></script>
<!-- Sortable.js â€” local -->
<script src="../assets/js/sortable.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var csrfToken = document.getElementById('csrf_token').value || '';
    var blocData = window.INSTITUTION_BLOCS || {};
    var modalEl = document.getElementById('blocModal');
    var modal = new bootstrap.Modal(modalEl);
    var currentId = 0;
    var galleryImages = [];
    var emspUi = window.emspUI || null;
    var saveButton = document.getElementById('btn-save-bloc');

    function showUiError(title, message) {
        if (emspUi && typeof emspUi.showError === 'function') {
            emspUi.showError(title, message);
            return;
        }
        window.alert((title ? title + ' - ' : '') + (message || 'Une erreur est survenue.'));
    }

    function showUiSuccess(title, message) {
        if (emspUi && typeof emspUi.showSuccess === 'function') {
            emspUi.showSuccess(title, message);
        }
    }

    var toolbarOptions = [
        [{ 'header': [1, 2, 3, false] }],
        ['bold', 'italic', 'underline', 'strike'],
        [{ 'color': [] }, { 'background': [] }],
        [{ 'align': [] }],
        ['blockquote', 'code-block'],
        [{ 'list': 'ordered'}, { 'list': 'bullet' }],
        ['link', 'image'],
        ['clean']
    ];

    var quillMain = new Quill('#bloc-editor', { theme: 'snow', modules: { toolbar: toolbarOptions } });
    var quillCol1 = new Quill('#col-editor-1', { theme: 'snow', modules: { toolbar: toolbarOptions } });
    var quillCol2 = new Quill('#col-editor-2', { theme: 'snow', modules: { toolbar: toolbarOptions } });
    var quillCol3 = new Quill('#col-editor-3', { theme: 'snow', modules: { toolbar: toolbarOptions } });

    // Maintenance: institution editors reuse the same image tools as the journal so alignment, resize and move-in-flow stay consistent everywhere.
    [quillMain, quillCol1, quillCol2, quillCol3].forEach(function (quill) {
        if (typeof window.emspAttachQuillImageTools === 'function') {
            window.emspAttachQuillImageTools(quill, {
                uploadUrl: '../admin/upload-institution-image.php',
                uploadPrefix: '../',
                csrfToken: csrfToken
            });
        }
    });

    function setEditorHtml(quill, html) {
        quill.root.innerHTML = html || '';
    }

    function showFields(type) {
        document.querySelectorAll('.bloc-fields').forEach(function (el) {
            el.style.display = (el.dataset.bloc === type) ? 'block' : 'none';
        });
    }

    function resetForm() {
        document.getElementById('bloc_id').value = '0';
        document.getElementById('section_label').value = '';
        document.getElementById('section_key').value = '';
        document.getElementById('bloc_type').value = 'texte';
        document.getElementById('bloc_visible').checked = true;
        document.getElementById('image_path').value = '';
        document.getElementById('image_align').value = 'full';
        document.getElementById('image_legende').value = '';
        document.getElementById('image_preview_wrap').style.display = 'none';
        document.getElementById('image_preview').src = '';
        document.getElementById('quote_text').value = '';
        document.getElementById('quote_author').value = '';
        document.getElementById('cols_count').value = '2';
        document.getElementById('col-3-wrap').style.display = 'none';
        document.getElementById('sep_style').value = 'solid';
        document.getElementById('sep_color').value = '#e2e8f0';
        document.getElementById('gallery_preview').innerHTML = '';
        document.getElementById('gallery_cols').value = '3';
        galleryImages = [];
        setEditorHtml(quillMain, '');
        setEditorHtml(quillCol1, '');
        setEditorHtml(quillCol2, '');
        setEditorHtml(quillCol3, '');
        document.getElementById('stats-list').innerHTML = '';
        showFields('texte');
        document.getElementById('bloc-error').classList.add('d-none');
        document.getElementById('bloc-error').textContent = '';
    }

    function renderGallery() {
        var wrap = document.getElementById('gallery_preview');
        wrap.innerHTML = '';
        galleryImages.forEach(function (url, idx) {
            var col = document.createElement('div');
            col.className = 'col-6 col-md-3';
            col.innerHTML = '<div class="position-relative">' +
                '<img src="../' + url + '" class="img-fluid rounded">' +
                '<button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0 m-1" data-idx="' + idx + '">&times;</button>' +
                '</div>';
            col.querySelector('button').addEventListener('click', function () {
                var i = parseInt(this.getAttribute('data-idx'), 10);
                galleryImages.splice(i, 1);
                renderGallery();
            });
            wrap.appendChild(col);
        });
    }

    function addStatRow(number, label) {
        var row = document.createElement('div');
        row.className = 'd-flex gap-2 align-items-center';
        row.innerHTML = '<input type="text" class="form-control" placeholder="Chiffre" value="' + (number || '') + '">' +
                        '<input type="text" class="form-control" placeholder="Label" value="' + (label || '') + '">' +
                        '<button class="btn btn-outline-danger btn-sm" type="button"><i class="bi bi-x-lg"></i></button>';
        row.querySelector('button').addEventListener('click', function () {
            row.remove();
        });
        document.getElementById('stats-list').appendChild(row);
    }

    function uploadBlocImage(file, cb) {
        var formData = new FormData();
        formData.append('action', 'upload_bloc_image');
        formData.append('csrf_token', csrfToken);
        formData.append('image', file);
        fetch('edit-institution.php', {
            method: 'POST',
            credentials: 'include',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.url) cb(null, data.url);
            else cb(data.error || 'Erreur upload');
        })
        .catch(function () { cb('Erreur upload'); });
    }

    document.getElementById('gallery_files').addEventListener('change', function () {
        var files = Array.from(this.files || []);
        if (!files.length) return;
        var remaining = 10 - galleryImages.length;
        if (files.length > remaining) {
            showUiError('Galerie limitÃ©e', 'Maximum 10 images par galerie.');
            files = files.slice(0, remaining);
        }
        files.forEach(function (file) {
            uploadBlocImage(file, function (err, url) {
                if (!err && url) {
                    galleryImages.push(url);
                    renderGallery();
                } else if (err) {
                    showUiError('Upload galerie', err);
                }
            });
        });
        this.value = '';
    });

    document.getElementById('image_file').addEventListener('change', function () {
        var file = this.files[0];
        if (!file) return;
        uploadBlocImage(file, function (err, url) {
            if (err) {
                showUiError('Upload image', err);
                return;
            }
            document.getElementById('image_path').value = url;
            var img = document.getElementById('image_preview');
            img.src = '../' + url;
            document.getElementById('image_preview_wrap').style.display = 'block';
        });
        this.value = '';
    });

    document.getElementById('btn-add-stat').addEventListener('click', function () {
        addStatRow('', '');
    });

    document.getElementById('bloc_type').addEventListener('change', function () {
        showFields(this.value);
    });

    document.getElementById('cols_count').addEventListener('change', function () {
        var n = parseInt(this.value, 10);
        document.getElementById('col-3-wrap').style.display = (n === 3) ? 'block' : 'none';
    });

    document.getElementById('btn-add-bloc').addEventListener('click', function () {
        currentId = 0;
        resetForm();
        modal.show();
    });

    document.querySelectorAll('.js-edit').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = parseInt(this.getAttribute('data-id'), 10);
            if (!id || !blocData[id]) return;
            var bloc = blocData[id];
            currentId = id;
            resetForm();
            document.getElementById('bloc_id').value = id;
            document.getElementById('section_label').value = bloc.section_label || '';
            document.getElementById('section_key').value = bloc.section_key || '';
            document.getElementById('bloc_type').value = bloc.bloc_type || 'texte';
            document.getElementById('bloc_visible').checked = (parseInt(bloc.visible || 1, 10) === 1);
            showFields(bloc.bloc_type || 'texte');

            var cfg = {};
            if (bloc.config_json) {
                try { cfg = JSON.parse(bloc.config_json); } catch (e) { cfg = {}; }
            }

            if (bloc.bloc_type === 'texte') {
                setEditorHtml(quillMain, bloc.contenu || '');
            } else if (bloc.bloc_type === 'image') {
                document.getElementById('image_path').value = bloc.image_path || '';
                document.getElementById('image_align').value = cfg.align || 'full';
                document.getElementById('image_legende').value = cfg.legende || '';
                if (bloc.image_path) {
                    document.getElementById('image_preview').src = '../' + bloc.image_path;
                    document.getElementById('image_preview_wrap').style.display = 'block';
                }
            } else if (bloc.bloc_type === 'galerie') {
                galleryImages = Array.isArray(cfg.images) ? cfg.images : [];
                document.getElementById('gallery_cols').value = String(cfg.cols || 3);
                renderGallery();
            } else if (bloc.bloc_type === 'stats') {
                document.getElementById('stats-list').innerHTML = '';
                if (Array.isArray(cfg.items)) {
                    cfg.items.forEach(function (it) { addStatRow(it.number || '', it.label || ''); });
                }
            } else if (bloc.bloc_type === 'citation') {
                document.getElementById('quote_text').value = cfg.texte || '';
                document.getElementById('quote_author').value = cfg.auteur || '';
            } else if (bloc.bloc_type === 'colonnes') {
                var cols = parseInt(cfg.cols || 2, 10);
                document.getElementById('cols_count').value = String(cols);
                document.getElementById('col-3-wrap').style.display = (cols === 3) ? 'block' : 'none';
                var colsHtml = [];
                if (bloc.contenu) {
                    try { colsHtml = JSON.parse(bloc.contenu); } catch (e) { colsHtml = []; }
                }
                setEditorHtml(quillCol1, colsHtml[0] || '');
                setEditorHtml(quillCol2, colsHtml[1] || '');
                setEditorHtml(quillCol3, colsHtml[2] || '');
            } else if (bloc.bloc_type === 'separateur') {
                document.getElementById('sep_style').value = cfg.style || 'solid';
                document.getElementById('sep_color').value = cfg.color || '#e2e8f0';
            }
            modal.show();
        });
    });

    document.querySelectorAll('.js-delete').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = parseInt(this.getAttribute('data-id'), 10);
            if (!id) return;
            var proceed = function () {
                var body = 'action=delete_bloc&id=' + encodeURIComponent(id) + '&csrf_token=' + encodeURIComponent(csrfToken);
                fetch('edit-institution.php', {
                    method: 'POST',
                    credentials: 'include',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With':'XMLHttpRequest'},
                    body: body
                })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.ok) {
                        throw data || new Error('delete');
                    }
                    showUiSuccess('Bloc supprimÃ©', 'Le bloc a Ã©tÃ© retirÃ© de la page institution.');
                    window.location.reload();
                })
                .catch(function (payload) {
                    showUiError('Suppression impossible', payload && (payload.message || payload.error) ? (payload.message || payload.error) : 'Impossible de supprimer ce bloc.');
                });
            };
            if (emspUi && typeof emspUi.confirm === 'function') {
                emspUi.confirm({
                    getAttribute: function (name) {
                        var attrs = {
                            'data-confirm': 'Supprimer ce bloc ?',
                            'data-confirm-detail': 'Cette action est irrÃ©versible.',
                            'data-confirm-type': 'danger',
                            'data-confirm-ok': 'Oui, supprimer'
                        };
                        return attrs[name] || '';
                    }
                }).then(function (confirmed) {
                    if (confirmed) { proceed(); }
                });
                return;
            }
            if (window.confirm('Supprimer ce bloc ?')) {
                proceed();
            }
        });
    });

    document.querySelectorAll('.js-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = parseInt(this.getAttribute('data-id'), 10);
            if (!id) return;
            var body = 'action=toggle_bloc&id=' + encodeURIComponent(id) + '&csrf_token=' + encodeURIComponent(csrfToken);
            fetch('edit-institution.php', {
                method: 'POST',
                credentials: 'include',
                headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With':'XMLHttpRequest'},
                body: body
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok) {
                    throw data || new Error('toggle');
                }
                showUiSuccess('VisibilitÃ© mise Ã  jour', 'Le bloc a Ã©tÃ© mis Ã  jour.');
                window.location.reload();
            })
            .catch(function (payload) {
                showUiError('Mise Ã  jour impossible', payload && (payload.message || payload.error) ? (payload.message || payload.error) : 'Impossible de modifier la visibilitÃ© de ce bloc.');
            });
        });
    });

    document.getElementById('btn-save-bloc').addEventListener('click', function () {
        var type = document.getElementById('bloc_type').value;
        var label = document.getElementById('section_label').value.trim();
        if (!label) {
            var err = document.getElementById('bloc-error');
            err.textContent = 'Le titre de section est obligatoire.';
            err.classList.remove('d-none');
            return;
        }
        var payload = new URLSearchParams();
        payload.append('action', 'save_bloc');
        payload.append('csrf_token', csrfToken);
        payload.append('bloc_id', document.getElementById('bloc_id').value || '0');
        payload.append('bloc_type', type);
        payload.append('section_label', label);
        payload.append('section_key', document.getElementById('section_key').value || '');
        payload.append('visible', document.getElementById('bloc_visible').checked ? '1' : '0');

        var contenu = '';
        var imagePath = '';
        var cfg = {};

        if (type === 'texte') {
            contenu = quillMain.root.innerHTML;
        } else if (type === 'image') {
            imagePath = document.getElementById('image_path').value;
            cfg = {
                align: document.getElementById('image_align').value,
                legende: document.getElementById('image_legende').value
            };
        } else if (type === 'galerie') {
            cfg = {
                cols: parseInt(document.getElementById('gallery_cols').value || '3', 10),
                images: galleryImages
            };
        } else if (type === 'stats') {
            var items = [];
            document.querySelectorAll('#stats-list > div').forEach(function (row) {
                var inputs = row.querySelectorAll('input');
                if (!inputs.length) return;
                var number = inputs[0].value.trim();
                var labelStat = inputs[1].value.trim();
                if (number !== '' || labelStat !== '') {
                    items.push({ number: number, label: labelStat });
                }
            });
            cfg = { items: items };
        } else if (type === 'citation') {
            cfg = { texte: document.getElementById('quote_text').value, auteur: document.getElementById('quote_author').value };
        } else if (type === 'colonnes') {
            var cols = parseInt(document.getElementById('cols_count').value || '2', 10);
            cfg = { cols: cols };
            var colsHtml = [
                quillCol1.root.innerHTML,
                quillCol2.root.innerHTML
            ];
            if (cols === 3) colsHtml.push(quillCol3.root.innerHTML);
            contenu = JSON.stringify(colsHtml);
        } else if (type === 'separateur') {
            cfg = { style: document.getElementById('sep_style').value, color: document.getElementById('sep_color').value };
        }

        payload.append('contenu', contenu);
        payload.append('image_path', imagePath);
        payload.append('config_json', JSON.stringify(cfg));

        if (emspUi) {
            emspUi.setButtonLoading(saveButton, true, { text: 'Enregistrement...' });
        }

        fetch('edit-institution.php', {
            method: 'POST',
            credentials: 'include',
            headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With':'XMLHttpRequest'},
            body: payload.toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (emspUi) {
                emspUi.setButtonLoading(saveButton, false);
            }
            if (data.ok) {
                showUiSuccess('Bloc enregistrÃ©', 'Les modifications ont Ã©tÃ© sauvegardÃ©es avec succÃ¨s.');
                window.location.reload();
            } else {
                var err = document.getElementById('bloc-error');
                err.textContent = data.message || data.error || 'Erreur lors de la sauvegarde';
                err.classList.remove('d-none');
                showUiError('Enregistrement impossible', data.message || data.error || 'Erreur lors de la sauvegarde');
            }
        })
        .catch(function () {
            if (emspUi) {
                emspUi.setButtonLoading(saveButton, false);
            }
            var err = document.getElementById('bloc-error');
            err.textContent = 'Erreur lors de la sauvegarde';
            err.classList.remove('d-none');
            showUiError('Enregistrement impossible', 'Erreur lors de la sauvegarde');
        });
    });

    if (typeof Sortable !== 'undefined') {
        Sortable.create(document.getElementById('blocs-list'), {
            handle: '.drag-handle',
            animation: 150,
            forceFallback: false,
            touchStartThreshold: 5,
            onEnd: function () {
                var ids = [];
                document.querySelectorAll('#blocs-list .bloc-item').forEach(function (el) {
                    ids.push(el.getAttribute('data-id'));
                });
                var body = 'action=reorder_blocs&order=' + encodeURIComponent(JSON.stringify(ids)) +
                    '&csrf_token=' + encodeURIComponent(csrfToken);
                fetch('edit-institution.php', {
                    method: 'POST',
                    credentials: 'include',
                    headers: {'Content-Type':'application/x-www-form-urlencoded', 'X-Requested-With':'XMLHttpRequest'},
                    body: body
                });
            }
        });
    }
});
</script>
HTML;
$page_scripts .= "<script>window.INSTITUTION_BLOCS = " . $blocJson . ";</script>";
include __DIR__ . '/includes/footer.php';
?>


