<?php
include_once __DIR__ . '/includes/bootstrap.php';

if (empty($_SESSION['auth'])) {
    include_once __DIR__ . '/includes/flash.php';
    flash_set(
        'warning',
        'Connexion requise',
        'Vous devez être connecté pour déposer un document.',
        'login.php',
        'Se connecter'
    );
    header('Location: login.php');
    exit(0);
}

include_once __DIR__ . '/admin/config/dbcon.php';
include_once __DIR__ . '/includes/document-taxonomy.php';

function emsp_fetch_options(mysqli $con, string $table): array
{
    // FIX: limiter strictement les tables autorisées avant interpolation dans la requête.
    $allowed = ['filieres', 'licences', 'matieres'];
    if (!in_array($table, $allowed, true)) {
        return [];
    }

    $rows = [];
    $sql = "SELECT id, name FROM {$table} WHERE status='active' ORDER BY name";
    $s = mysqli_prepare($con, $sql);
    if (!$s) {
        return $rows;
    }
    mysqli_stmt_execute($s);
    $rows = emsp_stmt_fetch_all($s);
    mysqli_stmt_close($s);
    return $rows;
}

$filieres = emsp_fetch_options($con, 'filieres');
$licences = emsp_fetch_options($con, 'licences');
$matieres = emsp_fetch_options($con, 'matieres');

$page_title = 'Depot de document';
include __DIR__ . '/includes/header.php';
?>

<section class="page-header">
    <div class="container">
        <h1>Deposer un document</h1>
        <p class="mb-0">Partage tes supports de cours avec la communaute EMSP.</p>
    </div>
</section>

<section class="section-pad">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-xl-9">
                <form action="upload-code.php" method="post" enctype="multipart/form-data" id="uploadForm">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token(); ?>">
                    <input type="hidden" name="doc_type" id="doc_type" value="">

                    <div class="card shadow-sm mb-4">
                        <div class="card-body">
                            <h5 class="fw-bold mb-3"><i class="bi bi-file-earmark-arrow-up me-2 text-primary"></i>Fichier</h5>
                            <div class="upload-drop-zone" id="dropZone">
                                <input type="file" name="document" id="documentInput" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.rar,.txt,.jpg,.jpeg,.png,.webp" required>
                                <i class="bi bi-file-earmark-arrow-up fs-1 text-secondary"></i>
                                <p class="mb-1 mt-2 fw-semibold">Glisse ton document ici ou clique pour choisir</p>
                                <p class="mb-2 text-muted small">Documents et images acceptés — max 5 Mo</p>
                                <div class="upload-format-chips" aria-hidden="true">
                                    <span class="upload-format-chip">PDF</span>
                                    <span class="upload-format-chip">Word</span>
                                    <span class="upload-format-chip">Excel</span>
                                    <span class="upload-format-chip">PowerPoint</span>
                                    <span class="upload-format-chip">ZIP/RAR</span>
                                    <span class="upload-format-chip">JPG/PNG</span>
                                </div>
                                <div class="upload-mobile-hint mt-2">
                                    Sur mobile : si le sélecteur propose surtout des photos, appuie sur
                                    <strong>Fichiers</strong> pour choisir un PDF/Word/Excel.
                                </div>
                                <div class="upload-scan-hint mt-3">
                                    Sur mobile, l'icône <strong>nuage</strong> ouvre cette page de dépôt et l'icône
                                    <strong>caméra</strong> permet de scanner plusieurs pages avant d'ajouter le PDF au formulaire.
                                </div>
                            </div>
                            <div class="invalid-feedback d-block mt-2 d-none" id="fileError"></div>
                            <div class="file-preview" id="filePreview">
                                <div class="file-icon fi-default" id="fileIcon">DOC</div>
                                <div class="flex-grow-1">
                                    <div class="fw-semibold text-truncate" id="fileName">fichier.pdf</div>
                                    <div class="text-muted small" id="fileMeta">0 Ko</div>
                                </div>
                                <button class="btn btn-sm btn-outline-secondary" type="button" id="clearFileBtn" aria-label="Supprimer le fichier sélectionné">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="card shadow-sm mb-4">
                        <div class="card-body">
                            <h5 class="fw-bold mb-3"><i class="bi bi-tags me-2 text-primary"></i>Type de document</h5>
                            <div class="doc-type-grid">
                                <button type="button" class="doc-type-btn" data-type="cours">Cours</button>
                                <button type="button" class="doc-type-btn" data-type="td">TD</button>
                                <button type="button" class="doc-type-btn" data-type="correction">Correction</button>
                                <button type="button" class="doc-type-btn" data-type="examen">Examen</button>
                                <button type="button" class="doc-type-btn" data-type="concours">Concours</button>
                            </div>
                            <div class="text-danger small mt-2 d-none" id="docTypeError">Choisis un type de document.</div>
                        </div>
                    </div>

                    <div class="card shadow-sm mb-4">
                        <div class="card-body">
                            <h5 class="fw-bold mb-3"><i class="bi bi-card-text me-2 text-primary"></i>Informations</h5>
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label fw-semibold">Titre</label>
                                    <input type="text" class="form-control" id="titleInput" name="title" maxlength="150" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold">Description</label>
                                    <textarea class="form-control" name="description" rows="3"></textarea>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold">Filières concernées</label>
                                    <div class="filiere-check-grid">
                                        <?php foreach ($filieres as $row): ?>
                                            <label class="filiere-check">
                                                <input type="checkbox" class="form-check-input" name="filiere_ids[]" value="<?= intval($row['id']) ?>">
                                                <span><?= htmlspecialchars($row['name']) ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="form-text">Sélectionne une ou plusieurs filières pour ce document.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Licence</label>
                                    <select class="form-select" name="licence_id" data-emsp-select2="1" data-emsp-select2-placeholder="Sélectionner un niveau">
                                        <option value="">-- Selectionner --</option>
                                        <?php foreach ($licences as $row): ?>
                                            <option value="<?= intval($row['id']) ?>"><?= htmlspecialchars($row['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Matière</label>
                                    <select class="form-select" name="matiere_id" id="matiereSelect" data-emsp-select2="1" data-emsp-select2-placeholder="Choisir une matière existante">
                                        <option value="">-- Choisir une matière existante --</option>
                                        <?php foreach ($matieres as $row): ?>
                                            <option value="<?= intval($row['id']) ?>"><?= htmlspecialchars($row['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="matiere-hint mt-2">Choisis une matière existante ou saisis-en une nouvelle juste à droite.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Nouvelle matière</label>
                                    <input type="text" class="form-control" name="matiere_free_text" id="matiereFreeText" maxlength="120" placeholder="Ex : Comptabilité analytique">
                                    <div class="form-text">La nouvelle matière sera relue et validée par l’admin lors de l’approbation.</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Semestre</label>
                                    <select class="form-select" name="semester">
                                        <option value="">-- --</option>
                                        <option value="S1">S1</option>
                                        <option value="S2">S2</option>
                                        <option value="S3">S3</option>
                                        <option value="S4">S4</option>
                                        <option value="S5">S5</option>
                                        <option value="S6">S6</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Session examen</label>
                                    <select class="form-select" name="exam_session" id="examSessionInput" disabled>
                                        <option value="">-- --</option>
                                        <option value="normale">Normale</option>
                                        <option value="rattrapage">Rattrapage</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">Annee</label>
                                    <input type="number" class="form-control" name="exam_year" id="examYearInput" min="2000" max="2099" disabled>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-semibold">Section (si examen)</label>
                                    <input type="text" class="form-control" name="exam_section" id="examSectionInput" maxlength="120" disabled>
                                </div>
                                <div class="col-12">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="isPublicSwitch" name="is_public" value="1" checked>
                                        <label class="form-check-label" for="isPublicSwitch">Rendre le document public</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end">
                        <button class="btn btn-emsp px-4" type="submit"><i class="bi bi-cloud-upload me-2"></i>Envoyer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>

<script>
const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('documentInput');
const preview = document.getElementById('filePreview');
const fileName = document.getElementById('fileName');
const fileMeta = document.getElementById('fileMeta');
const fileIcon = document.getElementById('fileIcon');
const clearBtn = document.getElementById('clearFileBtn');
const titleInput = document.getElementById('titleInput');
const docTypeInput = document.getElementById('doc_type');
const docTypeError = document.getElementById('docTypeError');
const fileError = document.getElementById('fileError');
const examSessionInput = document.getElementById('examSessionInput');
const examYearInput = document.getElementById('examYearInput');
const examSectionInput = document.getElementById('examSectionInput');
const matiereSelect = document.getElementById('matiereSelect');
const matiereFreeText = document.getElementById('matiereFreeText');

const iconMap = {
    pdf: ['fi-pdf', 'PDF'],
    doc: ['fi-doc', 'DOC'],
    docx: ['fi-doc', 'DOCX'],
    xls: ['fi-xls', 'XLS'],
    xlsx: ['fi-xls', 'XLSX'],
    ppt: ['fi-ppt', 'PPT'],
    pptx: ['fi-ppt', 'PPTX'],
    zip: ['fi-zip', 'ZIP'],
    rar: ['fi-zip', 'RAR'],
    txt: ['fi-txt', 'TXT'],
    jpg: ['fi-default', 'JPG'],
    jpeg: ['fi-default', 'JPEG'],
    png: ['fi-default', 'PNG'],
    webp: ['fi-default', 'WEBP']
};

function bytesToHuman(bytes) {
    if (bytes < 1024) return bytes + ' o';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' Ko';
    return (bytes / (1024 * 1024)).toFixed(1) + ' Mo';
}

function showPreview(file) {
    const ext = (file.name.split('.').pop() || '').toLowerCase();
    const info = iconMap[ext] || ['fi-default', ext.toUpperCase() || 'FILE'];
    fileIcon.className = 'file-icon ' + info[0];
    fileIcon.textContent = info[1];
    fileName.textContent = file.name;
    fileMeta.textContent = bytesToHuman(file.size);
    preview.classList.add('show');
    if (titleInput.value.trim() === '') {
        titleInput.value = file.name.replace(/\.[^/.]+$/, '').replace(/[_-]+/g, ' ');
    }
}

fileInput.addEventListener('change', function () {
    if (this.files.length > 0) {
        fileInput.classList.remove('is-invalid');
        fileError.classList.add('d-none');
        showPreview(this.files[0]);
    }
});

clearBtn.addEventListener('click', function () {
    fileInput.value = '';
    preview.classList.remove('show');
    fileError.classList.add('d-none');
});

dropZone.addEventListener('dragover', function (e) {
    e.preventDefault();
    dropZone.classList.add('dragover');
});
dropZone.addEventListener('dragleave', function () {
    dropZone.classList.remove('dragover');
});
dropZone.addEventListener('drop', function (e) {
    e.preventDefault();
    dropZone.classList.remove('dragover');
    if (e.dataTransfer.files.length > 0) {
        fileInput.files = e.dataTransfer.files;
        showPreview(e.dataTransfer.files[0]);
    }
});

document.querySelectorAll('.doc-type-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        document.querySelectorAll('.doc-type-btn').forEach(function (b) { b.classList.remove('active'); });
        this.classList.add('active');
        docTypeInput.value = this.dataset.type;
        docTypeError.classList.add('d-none');

        const isExam = this.dataset.type === 'examen';
        examSessionInput.disabled = !isExam;
        examYearInput.disabled = !isExam;
        examSectionInput.disabled = !isExam;
        examSessionInput.required = isExam;
        examYearInput.required = isExam;
        examSectionInput.required = isExam;
    });
});

if (matiereSelect && matiereFreeText) {
    matiereSelect.addEventListener('change', function () {
        if (this.value !== '') {
            matiereFreeText.value = '';
        }
    });
    matiereFreeText.addEventListener('input', function () {
        if (this.value.trim() !== '') {
            matiereSelect.value = '';
        }
    });
}

document.getElementById('uploadForm').addEventListener('submit', function (e) {
    const file = fileInput.files[0];
    if (!file) {
        e.preventDefault();
        showInlineError('documentInput', 'Veuillez sélectionner un fichier à uploader.');
        return;
    }
    const maxSize = 5 * 1024 * 1024;
    if (file.size > maxSize) {
        e.preventDefault();
        showInlineError(
            'documentInput',
            'Votre fichier fait ' + (file.size/1024/1024).toFixed(1)
            + ' Mo mais la limite est 5 Mo. '
            + 'Compressez votre PDF sur ilovepdf.com puis réessayez.'
        );
        return;
    }
    const allowed = ['pdf','doc','docx','xls','xlsx','ppt','pptx','zip','rar','txt','jpg','jpeg','png','webp'];
    const ext = (file.name.split('.').pop() || '').toLowerCase();
    if (!allowed.includes(ext)) {
        e.preventDefault();
        showInlineError(
            'documentInput',
            'Format ".' + ext + '" non accepté. Formats autorisés : PDF, Word, Excel, PowerPoint, ZIP/RAR, images (JPG, PNG, WEBP).'
        );
        return;
    }
    if (docTypeInput.value === '') {
        e.preventDefault();
        docTypeError.classList.remove('d-none');
        return;
    }
    if (!document.querySelector('input[name="filiere_ids[]"]:checked')) {
        e.preventDefault();
        showInlineError(
            'documentInput',
            'Sélectionnez au moins une filière avant d’envoyer le document.'
        );
        return;
    }
    if (matiereSelect && matiereFreeText && matiereSelect.value === '' && matiereFreeText.value.trim() === '') {
        e.preventDefault();
        showInlineError(
            'documentInput',
            'Choisissez une matière existante ou saisissez une nouvelle matière.'
        );
        return;
    }
    showUploadProgress();
});

function showInlineError(inputId, message) {
    const input = document.getElementById(inputId);
    input.classList.add('is-invalid');
    fileError.textContent = message;
    fileError.classList.remove('d-none');
}

function showUploadProgress() {
    const btn = document.querySelector('#uploadForm [type="submit"]');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>'
                      + 'Upload en cours... Ne fermez pas cette page.';
    }
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>


