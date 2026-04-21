<?php
ob_start();
include_once __DIR__ . '/bootstrap.php';
include_once __DIR__ . '/config/dbcon.php';
include_once __DIR__ . '/authentication.php';
include_once __DIR__ . '/../includes/csrf.php';
include_once __DIR__ . '/../includes/flash.php';
include_once dirname(__DIR__) . '/includes/content-helpers.php';

$allowedTypes   = ['image', 'video', 'lien'];
$allowedStatus  = ['published', 'archived'];
$serverMaxUpload = emsp_max_upload_size(70 * 1024 * 1024);
$serverMaxUploadMb = round($serverMaxUpload / 1024 / 1024, 1);
$imgLimitBytes = min(8 * 1024 * 1024, $serverMaxUpload);
$vidLimitBytes = min(70 * 1024 * 1024, $serverMaxUpload);
$imgLimitMb = round($imgLimitBytes / 1024 / 1024, 1);
$vidLimitMb = round($vidLimitBytes / 1024 / 1024, 1);
$mediaMimeToExt = [
    'image/jpeg'       => ['type' => 'image', 'ext' => 'jpg',  'max' => 8 * 1024 * 1024],
    'image/png'        => ['type' => 'image', 'ext' => 'png',  'max' => 8 * 1024 * 1024],
    'image/webp'       => ['type' => 'image', 'ext' => 'webp', 'max' => 8 * 1024 * 1024],
    'image/gif'        => ['type' => 'image', 'ext' => 'gif',  'max' => 8 * 1024 * 1024],
    'video/mp4'        => ['type' => 'video', 'ext' => 'mp4',  'max' => 70 * 1024 * 1024],
    'video/webm'       => ['type' => 'video', 'ext' => 'webm', 'max' => 70 * 1024 * 1024],
    'video/ogg'        => ['type' => 'video', 'ext' => 'ogv',  'max' => 70 * 1024 * 1024],
    'video/quicktime'  => ['type' => 'video', 'ext' => 'mp4',  'max' => 70 * 1024 * 1024],
    'video/x-msvideo'  => ['type' => 'video', 'ext' => 'mp4',  'max' => 70 * 1024 * 1024],
    'video/x-matroska' => ['type' => 'video', 'ext' => 'mp4',  'max' => 70 * 1024 * 1024],
];

// Liste dynamique des catÃ©gories existantes
$categories = [];
$catQuery = mysqli_query($con, "SELECT id, name FROM media_categories ORDER BY name");
while ($row = mysqli_fetch_assoc($catQuery)) {
    $categories[] = $row;
}

// Helpers
function emsp_add_media_location(int $id): string
{
    return 'add-media.php' . ($id > 0 ? '?id=' . $id : '');
}

function emsp_add_media_load_item(mysqli $con, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    $s = mysqli_prepare($con, "SELECT * FROM media WHERE id=? LIMIT 1");
    if (!$s) {
        return null;
    }

    mysqli_stmt_bind_param($s, 'i', $id);
    mysqli_stmt_execute($s);
    $item = emsp_stmt_fetch_assoc($s);
    mysqli_stmt_close($s);

    return $item ?: null;
}

function emsp_media_dir(string $type): string
{
    return $type === 'video'
        ? 'uploads/media/videos/'
        : 'uploads/media/images/';
}

function emsp_random_filename(string $ext): string
{
    return bin2hex(random_bytes(16)) . '.' . $ext;
}

function emsp_delete_tmp(string $path): void
{
    if (is_file($path)) {
        @unlink($path);
    }
}

if (($_GET['ajax'] ?? '') === 'youtube_title') {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json; charset=UTF-8');

    if (empty($_SESSION['auth']) || !in_array($_SESSION['auth_role'] ?? '', ['admin', 'moderateur'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'title' => '', 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
        exit(0);
    }

    $url = trim((string) ($_GET['url'] ?? ''));
    $title = $url !== '' ? emsp_youtube_title($url) : '';

    echo json_encode([
        'ok' => $title !== '',
        'title' => $title,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();

    $id          = intval($_POST['id'] ?? 0);
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $type        = trim($_POST['type'] ?? 'image');
    $categoryId  = intval($_POST['category_id'] ?? 0);
    $categoryRaw = trim($_POST['category'] ?? '');
    $is_public   = isset($_POST['is_public']) ? 1 : 0;
    $status      = trim($_POST['status'] ?? 'published');
    $file_mode   = $_POST['file_mode'] ?? 'upload'; // upload | link
    $link_path   = trim($_POST['file_path'] ?? '');
    $redirectSelf = emsp_add_media_location($id);
    $existingItem = $id > 0 ? emsp_add_media_load_item($con, $id) : null;

    if ($id > 0 && !$existingItem) {
        flash_set('error', 'Media introuvable', 'Le media demande est introuvable.');
        header('Location: mediatheque.php');
        exit(0);
    }

    if ($title === '') {
        flash_set('error', 'Titre obligatoire', 'Veuillez renseigner un titre pour ce mÃ©dia.');
        header('Location: ' . $redirectSelf);
        exit(0);
    }
    if (!in_array($type, $allowedTypes, true)) {
        flash_set('error', 'Type invalide', 'Choisissez un type valide (image, vidÃ©o ou lien).');
        header('Location: ' . $redirectSelf);
        exit(0);
    }
    if (!in_array($status, $allowedStatus, true)) {
        flash_set('error', 'Statut invalide', 'Le statut sÃ©lectionnÃ© est incorrect.');
        header('Location: ' . $redirectSelf);
        exit(0);
    }

    $final_path = '';

    if ($file_mode === 'link' || $type === 'lien') {
        if ($link_path === '') {
            flash_set('error', 'Lien manquant', 'Ajoutez un lien ou un chemin pour ce mÃ©dia.');
            header('Location: ' . $redirectSelf);
            exit(0);
        }
        $final_path = $link_path;
        $type = 'lien';
    } else {
        $uploadErr = $_FILES['media_file']['error'] ?? UPLOAD_ERR_NO_FILE;
        $uploadName = (string) ($_FILES['media_file']['name'] ?? '');
        $existingUploadPath = trim((string) ($existingItem['file_path'] ?? ''));
        $normalizedExistingPath = ltrim(str_replace('\\', '/', $existingUploadPath), '/');
        $canReuseExistingUpload = $id > 0
            && $existingItem
            && !empty($existingUploadPath)
            && strpos($normalizedExistingPath, 'uploads/') === 0
            && (string) ($existingItem['type'] ?? '') === $type;

        if ($uploadErr !== UPLOAD_ERR_OK || $uploadName === '') {
            if ($canReuseExistingUpload) {
                $final_path = $existingUploadPath;
            } else {
                $postMax = emsp_parse_size_to_bytes((string) ini_get('post_max_size'));
                $postMaxMb = $postMax > 0 ? round($postMax / 1024 / 1024, 1) : $serverMaxUploadMb;
                $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

                $titleMsg = 'Fichier requis';
                $detailMsg = 'Veuillez sÃ©lectionner un fichier Ã  envoyer.';

                if ($contentLength > 0 && $postMax > 0 && $contentLength > $postMax) {
                    $titleMsg = 'Fichier trop lourd';
                    $detailMsg = 'Le fichier dÃ©passe la limite serveur post_max_size (' . $postMaxMb . ' Mo).';
                } elseif ($uploadErr === UPLOAD_ERR_INI_SIZE || $uploadErr === UPLOAD_ERR_FORM_SIZE) {
                    $titleMsg = 'Fichier trop lourd';
                    $detailMsg = 'Le fichier dÃ©passe la limite serveur (' . $serverMaxUploadMb . ' Mo).';
                } elseif ($uploadErr === UPLOAD_ERR_PARTIAL) {
                    $titleMsg = 'Upload incomplet';
                    $detailMsg = 'Le fichier nâ€™a pas Ã©tÃ© entiÃ¨rement transfÃ©rÃ©. RÃ©essayez.';
                } elseif (in_array($uploadErr, [UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION], true)) {
                    $titleMsg = 'Erreur serveur';
                    $detailMsg = 'Le serveur nâ€™a pas pu enregistrer le fichier. Contactez lâ€™administrateur.';
                }
                flash_set('error', $titleMsg, $detailMsg);
                header('Location: ' . $redirectSelf);
                exit(0);
            }
        } else {
            $tmpPath = $_FILES['media_file']['tmp_name'];
            $size = (int) ($_FILES['media_file']['size'] ?? 0);
            $extLower = strtolower(pathinfo($uploadName, PATHINFO_EXTENSION));

            $mime = emsp_detect_mime($tmpPath);
            if (!isset($mediaMimeToExt[$mime])) {
                $extFallback = [
                    'mp4'  => 'video/mp4',
                    'mov'  => 'video/quicktime',
                    'webm' => 'video/webm',
                    'avi'  => 'video/x-msvideo',
                    'mkv'  => 'video/x-matroska',
                    'ogv'  => 'video/ogg',
                ];
                if (isset($extFallback[$extLower])) {
                    $mime = $extFallback[$extLower];
                }
            }

            if (!isset($mediaMimeToExt[$mime])) {
                emsp_delete_tmp($tmpPath);
                flash_set('error', 'Format non autorise', 'Formats acceptes : JPG, PNG, WEBP, GIF pour les images ; MP4, WEBM, OGG, MOV, AVI et MKV pour les videos.');
                header('Location: ' . $redirectSelf);
                exit(0);
            }

            $detected = $mediaMimeToExt[$mime];
            $typeMatches = $type === $detected['type']
                || ($type === 'video' && $detected['type'] === 'video');
            if (!$typeMatches) {
                emsp_delete_tmp($tmpPath);
                flash_set('error', 'Type incompatible', 'Le type choisi ne correspond pas au fichier fourni. SÃ©lectionnez le bon type.');
                header('Location: ' . $redirectSelf);
                exit(0);
            }

            $limit = min($detected['max'], $serverMaxUpload);
            if ($size <= 0 || $size > $limit) {
                emsp_delete_tmp($tmpPath);
                $limitMb = round($limit / 1024 / 1024, 1);
                $msg = $detected['type'] === 'image'
                    ? 'Image trop lourde (max ' . $limitMb . ' Mo).'
                    : 'Video trop lourde (max ' . $limitMb . ' Mo).';
                flash_set('error', 'Fichier trop lourd', $msg);
                header('Location: ' . $redirectSelf);
                exit(0);
            }

            $destDir = emsp_media_dir($detected['type']);
            if (!is_dir('../' . $destDir) && !@mkdir('../' . $destDir, 0755, true) && !is_dir('../' . $destDir)) {
                flash_set('error', 'Dossier manquant', 'Impossible de creer le dossier de destination. Creez-le manuellement puis reessayez.');
                header('Location: ' . $redirectSelf);
                exit(0);
            }

            $filename  = emsp_random_filename($detected['ext']);
            $final_path = $destDir . $filename;

            if (!move_uploaded_file($tmpPath, '../' . $final_path)) {
                flash_set('error', 'Echec de l upload', 'Impossible de copier le fichier. Reessayez.');
                header('Location: ' . $redirectSelf);
                exit(0);
            }
        }
    }

    $categoryName = null;
    if ($categoryId > 0) {
        $cs = mysqli_prepare($con, "SELECT name FROM media_categories WHERE id=? LIMIT 1");
        mysqli_stmt_bind_param($cs, 'i', $categoryId);
        mysqli_stmt_execute($cs);
        mysqli_stmt_bind_result($cs, $categoryName);
        mysqli_stmt_fetch($cs);
        mysqli_stmt_close($cs);
    }
    $categoryIdDb = null;
    if ($categoryId > 0 && $categoryName) {
        $categoryIdDb = $categoryId;
    }
    $category = $categoryName ? $categoryName : ($categoryRaw !== '' ? $categoryRaw : null);

    if ($id > 0) {
        $s = mysqli_prepare(
            $con,
            "UPDATE media
             SET title=?, description=?, type=?, file_path=?, category=?, category_id=?, is_public=?, status=?
             WHERE id=? LIMIT 1"
        );
        mysqli_stmt_bind_param($s, 'sssssiisi', $title, $description, $type, $final_path, $category, $categoryIdDb, $is_public, $status, $id);
        mysqli_stmt_execute($s);
        mysqli_stmt_close($s);
        flash_set('success', 'MÃ©dia mis Ã  jour', 'Les modifications ont Ã©tÃ© enregistrÃ©es.');
    } else {
        $created_by = intval($auth_user['id']);
        $s = mysqli_prepare(
            $con,
            "INSERT INTO media
             (title, description, type, file_path, category, category_id, is_public, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        mysqli_stmt_bind_param($s, 'sssssiisi', $title, $description, $type, $final_path, $category, $categoryIdDb, $is_public, $status, $created_by);
        mysqli_stmt_execute($s);
        mysqli_stmt_close($s);
        flash_set('success', 'MÃ©dia ajoutÃ©', 'Le mÃ©dia a Ã©tÃ© ajoutÃ© avec succÃ¨s.');
    }

    header('Location: mediatheque.php');
    exit(0);
}

$id = intval($_GET['id'] ?? 0);
$item = emsp_add_media_load_item($con, $id);
if ($id > 0 && !$item) {
    flash_set('error', 'MÃ©dia introuvable', 'Le mÃ©dia demandÃ© est introuvable.');
    header('Location: mediatheque.php');
    exit(0);
}

$selectedCat = (int) ($item['category_id'] ?? 0);
$customCategoryValue = $selectedCat > 0 ? '' : (string) ($item['category'] ?? '');
$currentMode = ($item && !preg_match('#^https?://#i', $item['file_path'] ?? '') && strpos((string) $item['file_path'], 'uploads/') === 0) ? 'upload' : 'link';
$currentFileSrc = $item && !empty($item['file_path']) ? emsp_media_src((string) $item['file_path']) : '';
$currentFileHref = '';
if ($currentFileSrc !== '') {
    $currentFileHref = preg_match('#^https?://#i', $currentFileSrc)
        ? $currentFileSrc
        : '../' . ltrim($currentFileSrc, '/');
}

$page_title = $id > 0 ? 'Modifier un media' : 'Ajouter un media';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<?php include __DIR__ . '/includes/navbar-top.php'; ?>
<div id="admin-content">
    <div id="main-content" class="container-fluid">

<div class="row justify-content-center">
<div class="col-lg-8">
<div class="card shadow-sm">
    <div class="card-header bg-white fw-semibold">
        <i class="bi bi-images me-2 text-primary"></i><?= htmlspecialchars($page_title) ?>
    </div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="id" value="<?= (int) $id ?>">

            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label fw-semibold">Titre <span class="text-danger">*</span></label>
                    <input
                        class="form-control"
                        type="text"
                        name="title"
                        maxlength="200"
                        required
                        value="<?= htmlspecialchars($item['title'] ?? '') ?>"
                        placeholder="Ex: Ceremonie de rentree academique">
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Type <span class="text-danger">*</span></label>
                    <select class="form-select" name="type" required>
                        <?php foreach ($allowedTypes as $type): ?>
                            <option value="<?= htmlspecialchars($type) ?>" <?= (($item['type'] ?? 'image') === $type) ? 'selected' : '' ?>>
                                <?= htmlspecialchars(ucfirst($type)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-12">
                    <label class="form-label fw-semibold">Mode de fichier</label>
                    <div class="d-flex gap-3">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="file_mode" id="mode_upload" value="upload" <?= $currentMode === 'upload' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="mode_upload">Upload</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="file_mode" id="mode_link" value="link" <?= $currentMode === 'link' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="mode_link">Lien / chemin existant</label>
                        </div>
                    </div>
                </div>

                <div class="col-md-12" id="upload_block">
                    <label class="form-label fw-semibold">Fichier (image JPG/PNG/WEBP/GIF ou video MP4/WEBM/OGV/MOV/AVI/MKV)</label>
                    <input class="form-control" type="file" name="media_file" accept=".jpg,.jpeg,.png,.webp,.gif,.mp4,.webm,.ogv,.mov,.avi,.mkv,image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm,video/ogg,video/quicktime,video/x-msvideo,video/x-matroska">
                    <div class="form-text">Images max <?= $imgLimitMb ?> Mo, videos max <?= $vidLimitMb ?> Mo. Limite serveur detectee : <?= $serverMaxUploadMb ?> Mo.</div>
                    <?php if ($item && $currentMode === 'upload' && !empty($item['file_path'])): ?>
                        <p class="text-muted small mt-1">
                            Fichier actuel: <?= htmlspecialchars($item['file_path']) ?>
                            <?php if ($currentFileHref !== ''): ?>
                                <a href="<?= htmlspecialchars($currentFileHref) ?>" target="_blank" rel="noopener">Voir le fichier</a>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="col-md-12" id="link_block">
                    <label class="form-label fw-semibold">Lien ou chemin (assets/..., uploads/... ou URL)</label>
                    <input
                        class="form-control"
                        type="text"
                        name="file_path"
                        value="<?= htmlspecialchars($item['file_path'] ?? '') ?>"
                        placeholder="Ex: assets/images/campus-1.jpg ou https://youtube.com/...">
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Categorie</label>
                    <select class="form-select" name="category_id">
                        <option value="">-- Sans categorie --</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= (int)$cat['id'] ?>" <?= $selectedCat === (int)$cat['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($cat['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input
                        class="form-control mt-2"
                        type="text"
                        name="category"
                        maxlength="100"
                        value="<?= htmlspecialchars($customCategoryValue) ?>"
                        placeholder="Nouvelle categorie (optionnel)">
                    <div class="small text-muted mt-2">Selectionne une categorie existante ou saisis un nouveau nom.</div>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">Statut</label>
                    <select class="form-select" name="status" required>
                        <?php foreach ($allowedStatus as $status): ?>
                            <option value="<?= htmlspecialchars($status) ?>" <?= (($item['status'] ?? 'published') === $status) ? 'selected' : '' ?>>
                                <?= htmlspecialchars(ucfirst($status)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3 d-flex align-items-end">
                    <div class="form-check mb-2">
                        <?php $isPublicChecked = (int) ($item['is_public'] ?? 1) === 1; ?>
                        <input class="form-check-input" type="checkbox" name="is_public" id="is_public" value="1" <?= $isPublicChecked ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_public">Visible publiquement</label>
                    </div>
                </div>

                <div class="col-md-12">
                    <label class="form-label fw-semibold">Description</label>
                    <textarea class="form-control" name="description" rows="5" placeholder="Description courte du media..."><?= htmlspecialchars($item['description'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i>Enregistrer
                </button>
                <a href="mediatheque.php" class="btn btn-outline-secondary">Annuler</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>

</div><!-- /#main-content -->
</div><!-- /#admin-content -->
<?php
$page_scripts = <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function () {
    const modeUpload = document.getElementById('mode_upload');
    const modeLink   = document.getElementById('mode_link');
    const blockUpload = document.getElementById('upload_block');
    const blockLink   = document.getElementById('link_block');
    const typeSelect  = document.querySelector('select[name="type"]');
    const filePathInput = document.querySelector('input[name="file_path"]');
    const titleInput = document.querySelector('input[name="title"]');

    function toggleMode() {
        const isUpload = modeUpload.checked && (!typeSelect || typeSelect.value !== 'lien');
        blockUpload.style.display = isUpload ? 'block' : 'none';
        blockLink.style.display   = isUpload ? 'none'  : 'block';
    }
    modeUpload.addEventListener('change', toggleMode);
    modeLink.addEventListener('change', toggleMode);
    if (typeSelect) {
        typeSelect.addEventListener('change', function () {
            if (this.value === 'lien') {
                modeLink.checked = true;
            } else if (modeLink.checked) {
                modeUpload.checked = true;
            }
            toggleMode();
        });
    }

    function isYoutubeUrl(value) {
        return /(?:youtube\.com|youtu\.be)/i.test(value || '');
    }

    async function autofillYoutubeTitle() {
        if (!filePathInput || !titleInput || !typeSelect) return;
        const url = filePathInput.value.trim();
        const titleValue = titleInput.value.trim();
        const canAutofill = titleValue === '' || titleInput.dataset.autofilled === '1';

        if (typeSelect.value !== 'lien' || !modeLink.checked || !isYoutubeUrl(url) || !canAutofill) {
            return;
        }

        try {
            const response = await fetch('add-media.php?ajax=youtube_title&url=' + encodeURIComponent(url), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!response.ok) return;
            const data = await response.json();
            if (data && data.title && (titleInput.value.trim() === '' || titleInput.dataset.autofilled === '1')) {
                titleInput.value = data.title;
                titleInput.dataset.autofilled = '1';
            }
        } catch (e) {
            // silencieux sur hÃ©bergement mutualisÃ©
        }
    }

    if (titleInput) {
        titleInput.addEventListener('input', function () {
            this.dataset.autofilled = '0';
        });
    }
    if (filePathInput) {
        filePathInput.addEventListener('blur', autofillYoutubeTitle);
        filePathInput.addEventListener('change', autofillYoutubeTitle);
    }
    if (typeSelect) {
        typeSelect.addEventListener('change', autofillYoutubeTitle);
    }
    if (modeLink) {
        modeLink.addEventListener('change', autofillYoutubeTitle);
    }
    toggleMode();
});
</script>
HTML;
include __DIR__ . '/includes/footer.php';
?>



