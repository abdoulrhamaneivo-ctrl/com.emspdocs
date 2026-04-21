<?php
ob_start();
include_once __DIR__ . '/bootstrap.php';
include_once __DIR__ . '/config/dbcon.php';
include_once __DIR__ . '/authentication.php';
include_once __DIR__ . '/../includes/csrf.php';
include_once __DIR__ . '/../includes/formations-helpers.php';

function emsp_handle_filiere_cover_upload(string $field): array
{
    if (empty($_FILES[$field]) || !is_array($_FILES[$field])) {
        return ['path' => '', 'error' => ''];
    }

    $file = $_FILES[$field];
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['path' => '', 'error' => ''];
    }
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['path' => '', 'error' => 'Erreur lors de l upload du visuel.'];
    }

    $maxSize = emsp_max_upload_size(defined('MAX_UPLOAD_SIZE') ? MAX_UPLOAD_SIZE : (6 * 1024 * 1024));
    if ((int) ($file['size'] ?? 0) > $maxSize) {
        return ['path' => '', 'error' => 'Visuel trop lourd (max ' . round($maxSize / 1024 / 1024, 1) . ' Mo).'];
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    $mime = emsp_detect_mime($tmpPath);
    $mimeToExt = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    if (!isset($mimeToExt[$mime])) {
        return ['path' => '', 'error' => 'Format non autorise. Utilisez JPG, PNG, WEBP ou GIF.'];
    }

    $dir = __DIR__ . '/../uploads/formations/covers';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return ['path' => '', 'error' => 'Impossible de creer le dossier des visuels.'];
    }

    $realDir = realpath($dir);
    if ($realDir === false || !is_writable($realDir)) {
        return ['path' => '', 'error' => 'Le dossier des visuels n est pas accessible en ecriture.'];
    }

    $filename = 'filiere-' . bin2hex(random_bytes(12)) . '.' . $mimeToExt[$mime];
    $destPath = $realDir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($tmpPath, $destPath)) {
        return ['path' => '', 'error' => 'Impossible d enregistrer le visuel.'];
    }

    return ['path' => 'uploads/formations/covers/' . $filename, 'error' => ''];
}

function emsp_delete_local_filiere_asset(?string $path): void
{
    $path = trim((string) $path);
    if ($path === '' || preg_match('#^https?://#i', $path)) {
        return;
    }

    $path = ltrim(str_replace('\\', '/', $path), '/');
    if (strpos($path, 'uploads/formations/') !== 0) {
        return;
    }

    $absolute = realpath(__DIR__ . '/../' . $path);
    $base = realpath(__DIR__ . '/../uploads/formations');
    if ($absolute !== false && $base !== false && strpos($absolute, $base) === 0 && is_file($absolute)) {
        @unlink($absolute);
    }
}

$editorialEnabled = emsp_formations_editorial_columns_present($con);
$id = intval($_GET['id'] ?? ($_POST['id'] ?? 0));
$editing = $id > 0;
$item = [
    'name' => '',
    'code' => '',
    'status' => 'active',
    'summary' => '',
    'description_html' => '',
    'cover_image_path' => '',
];

if ($editing) {
    $sql = $editorialEnabled
        ? "SELECT id, name, code, status, summary, description_html, cover_image_path FROM filieres WHERE id=? LIMIT 1"
        : "SELECT id, name, code, status FROM filieres WHERE id=? LIMIT 1";
    $s = mysqli_prepare($con, $sql);
    if ($s) {
        mysqli_stmt_bind_param($s, 'i', $id);
        mysqli_stmt_execute($s);
        $row = emsp_stmt_fetch_assoc($s);
        mysqli_stmt_close($s);
        if ($row) {
            $item = array_merge($item, $row);
        } else {
            $_SESSION['message'] = 'Filiere introuvable.';
            header('Location: view-filieres.php');
            exit;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();
    $name = trim((string) ($_POST['name'] ?? ''));
    $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
    $status = (string) ($_POST['status'] ?? 'active');
    $summary = trim((string) ($_POST['summary'] ?? ''));
    $descriptionHtml = trim((string) ($_POST['description_html'] ?? ''));
    $currentCoverPath = trim((string) ($_POST['current_cover_image_path'] ?? ($item['cover_image_path'] ?? '')));
    $removeCover = !empty($_POST['remove_cover']);
    $coverImagePath = $currentCoverPath;

    if (!in_array($status, ['active', 'inactive'], true)) {
        $status = 'active';
    }

    if ($editorialEnabled) {
        $upload = emsp_handle_filiere_cover_upload('cover_image');
        if ($upload['error'] !== '') {
            $_SESSION['message'] = $upload['error'];
            $item = [
                'name' => $name,
                'code' => $code,
                'status' => $status,
                'summary' => $summary,
                'description_html' => $descriptionHtml,
                'cover_image_path' => $currentCoverPath,
            ];
        } elseif ($upload['path'] !== '') {
            if ($currentCoverPath !== '' && $currentCoverPath !== $upload['path']) {
                emsp_delete_local_filiere_asset($currentCoverPath);
            }
            $coverImagePath = $upload['path'];
        } elseif ($removeCover) {
            emsp_delete_local_filiere_asset($currentCoverPath);
            $coverImagePath = '';
        }
    }

    if (empty($_SESSION['message']) && ($name === '' || $code === '')) {
        $_SESSION['message'] = 'Nom et code obligatoires.';
    }

    if (empty($_SESSION['message'])) {
        if ($editing) {
            $chk = mysqli_prepare($con, "SELECT id FROM filieres WHERE code=? AND id<>? LIMIT 1");
            mysqli_stmt_bind_param($chk, 'si', $code, $id);
        } else {
            $chk = mysqli_prepare($con, "SELECT id FROM filieres WHERE code=? LIMIT 1");
            mysqli_stmt_bind_param($chk, 's', $code);
        }
        mysqli_stmt_execute($chk);
        mysqli_stmt_store_result($chk);
        $exists = mysqli_stmt_num_rows($chk) > 0;
        mysqli_stmt_close($chk);

        if ($exists) {
            $_SESSION['message'] = 'Ce code existe deja. Choisissez un code unique.';
        }
    }

    if (!empty($_SESSION['message'])) {
        $item = [
            'name' => $name,
            'code' => $code,
            'status' => $status,
            'summary' => $summary,
            'description_html' => $descriptionHtml,
            'cover_image_path' => $coverImagePath,
        ];
    } else {
        if ($editing) {
            if ($editorialEnabled) {
                $s = mysqli_prepare(
                    $con,
                    "UPDATE filieres
                     SET name=?, code=?, status=?, summary=?, description_html=?, cover_image_path=?
                     WHERE id=?"
                );
                mysqli_stmt_bind_param($s, 'ssssssi', $name, $code, $status, $summary, $descriptionHtml, $coverImagePath, $id);
            } else {
                $s = mysqli_prepare($con, "UPDATE filieres SET name=?, code=?, status=? WHERE id=?");
                mysqli_stmt_bind_param($s, 'sssi', $name, $code, $status, $id);
            }
        } else {
            if ($editorialEnabled) {
                $s = mysqli_prepare(
                    $con,
                    "INSERT INTO filieres (name, code, status, summary, description_html, cover_image_path)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );
                mysqli_stmt_bind_param($s, 'ssssss', $name, $code, $status, $summary, $descriptionHtml, $coverImagePath);
            } else {
                $s = mysqli_prepare($con, "INSERT INTO filieres (name, code, status) VALUES (?, ?, ?)");
                mysqli_stmt_bind_param($s, 'sss', $name, $code, $status);
            }
        }

        mysqli_stmt_execute($s);
        mysqli_stmt_close($s);
        $_SESSION['message'] = $editing ? 'Filiere modifiee.' : 'Filiere ajoutee.';
        header('Location: view-filieres.php');
        exit;
    }
}

$page_title = $editing ? 'Modifier la filiere' : 'Ajouter une filiere';
$coverSrc = emsp_formation_image_src((string) ($item['cover_image_path'] ?? ''));
$csrfToken = generate_csrf_token();
$initialDescription = json_encode((string) ($item['description_html'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$csrfJson = json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
include __DIR__ . '/includes/navbar-top.php';
?>
<div id="admin-content">
<div id="main-content" class="container-fluid">
<?php if (!empty($_SESSION['message'])): ?>
    <div class="alert alert-info alert-dismissible fade show">
        <?= htmlspecialchars($_SESSION['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['message']); ?>
<?php endif; ?>

<?php if (!$editorialEnabled): ?>
    <div class="alert alert-warning">
        Les champs Ã©ditoriaux des filiÃ¨res ne sont pas encore disponibles. Applique d abord la migration SQL correspondante, puis recharge cette page.
    </div>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-xl-9 col-lg-10">
        <div class="sb-card">
            <div class="sb-card-header">
                <div class="sb-card-title"><i class="bi bi-diagram-3"></i><?= $page_title ?></div>
            </div>
            <div class="sb-card-body">
                <form method="POST" enctype="multipart/form-data" id="filiere-form">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="id" value="<?= $editing ? $id : 0 ?>">
                    <input type="hidden" name="current_cover_image_path" value="<?= h((string) ($item['cover_image_path'] ?? '')) ?>">
                    <input type="hidden" name="description_html" id="filiere-description-hidden">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="sb-label">Nom <span class="text-danger">*</span></label>
                            <input class="sb-input" type="text" name="name" required value="<?= h((string) ($item['name'] ?? '')) ?>" placeholder="Ex : Logistique Numerique">
                        </div>
                        <div class="col-md-3">
                            <label class="sb-label">Code <span class="text-danger">*</span></label>
                            <input class="sb-input text-uppercase" type="text" name="code" required maxlength="20"
                                   value="<?= h((string) ($item['code'] ?? '')) ?>"
                                   placeholder="Ex : LOGI">
                        </div>
                        <div class="col-md-3">
                            <label class="sb-label">Statut</label>
                            <select name="status" class="sb-select">
                                <option value="active" <?= (($item['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>Actif</option>
                                <option value="inactive" <?= (($item['status'] ?? 'active') === 'inactive') ? 'selected' : '' ?>>Inactif</option>
                            </select>
                        </div>
                    </div>

                    <?php if ($editorialEnabled): ?>
                        <hr class="my-4">
                        <div class="row g-4 align-items-start">
                            <div class="col-lg-5">
                                <label class="sb-label">Visuel de couverture</label>
                                <input type="file" class="form-control" name="cover_image" id="cover_image" accept="image/jpeg,image/png,image/webp,image/gif">
                                <div class="form-text">Image dynamique affichÃ©e sur la page Formations. Formats : JPG, PNG, WEBP, GIF.</div>

                                <div class="form-check mt-3">
                                    <input class="form-check-input" type="checkbox" value="1" name="remove_cover" id="remove_cover">
                                    <label class="form-check-label" for="remove_cover">Supprimer le visuel actuel</label>
                                </div>

                                <div class="mt-3 <?= $coverSrc !== '' ? '' : 'd-none' ?>" id="cover-preview-wrap">
                                    <div class="small text-muted mb-2">AperÃ§u</div>
                                    <img src="<?= h($coverSrc !== '' && !preg_match('#^https?://#i', $coverSrc) ? '../' . ltrim($coverSrc, '/') : $coverSrc) ?>" alt="Couverture filiÃ¨re" class="img-fluid rounded-4 border" id="cover-preview">
                                </div>
                            </div>

                            <div class="col-lg-7">
                                <div class="mb-3">
                                    <label class="sb-label">RÃ©sumÃ© court</label>
                                    <textarea class="form-control" name="summary" rows="4" placeholder="Une prÃ©sentation courte qui sera visible sur la carte de la filiÃ¨re."><?= h((string) ($item['summary'] ?? '')) ?></textarea>
                                </div>

                                <div class="mb-2">
                                    <label class="sb-label">PrÃ©sentation dÃ©taillÃ©e</label>
                                    <div class="small text-muted mb-2">Tu peux insÃ©rer tes propres images, les redimensionner, les aligner Ã  gauche, au centre ou Ã  droite, puis les dÃ©placer plus facilement dans le flux.</div>
                                </div>
                                <div id="filiere-editor" class="emsp-editor-min-260"></div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn-sb-primary"><i class="bi bi-check-lg"></i> Enregistrer</button>
                        <a href="view-filieres.php" class="btn-sb-outline">Annuler</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
</div>
</div>
<?php
$page_scripts = <<<HTML
<script src="../assets/js/quill.min.js"></script>
<script src="../assets/js/emsp-quill-image-tools.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('filiere-form');
    var hiddenInput = document.getElementById('filiere-description-hidden');
    var coverInput = document.getElementById('cover_image');
    var coverWrap = document.getElementById('cover-preview-wrap');
    var coverPreview = document.getElementById('cover-preview');
    var removeCover = document.getElementById('remove_cover');

    if (coverInput && coverPreview && coverWrap) {
        coverInput.addEventListener('change', function () {
            var file = coverInput.files && coverInput.files[0] ? coverInput.files[0] : null;
            if (!file) {
                return;
            }
            var reader = new FileReader();
            reader.onload = function (event) {
                coverPreview.src = event.target && event.target.result ? event.target.result : '';
                coverWrap.classList.remove('d-none');
                if (removeCover) {
                    removeCover.checked = false;
                }
            };
            reader.readAsDataURL(file);
        });
    }

    var editorRoot = document.getElementById('filiere-editor');
    if (!editorRoot || !hiddenInput) {
        return;
    }

    var quill = new Quill(editorRoot, {
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

    var initialContent = {$initialDescription};
    if (initialContent) {
        quill.root.innerHTML = initialContent;
    }

    if (typeof window.emspAttachQuillImageTools === 'function') {
        window.emspAttachQuillImageTools(quill, {
            uploadUrl: '../admin/upload-filiere-image.php',
            uploadPrefix: '../',
            csrfToken: {$csrfJson}
        });
    }

    if (form) {
        form.addEventListener('submit', function () {
            hiddenInput.value = quill.root.innerHTML;
        });
    }
});
</script>
HTML;
include __DIR__ . '/includes/footer.php';
?>


