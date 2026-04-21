<?php
// concours.php - page publique, pas de connexion obligatoire
include_once __DIR__ . '/includes/bootstrap.php';
include_once __DIR__ . '/admin/config/dbcon.php';

$yearFilter = intval($_GET['annee'] ?? 0);
$search     = trim($_GET['q'] ?? '');

$sql = "SELECT d.id, d.title, d.description, d.semester, d.download_count,
               d.file_size_bytes, d.created_at, d.file_path, d.mime_type, d.exam_year,
               u.first_name, u.last_name,
               f.name AS filiere_name, ma.name AS matiere_name
        FROM documents d
        JOIN users u ON u.id = d.uploader_id
        LEFT JOIN filieres f  ON f.id  = d.filiere_id
        LEFT JOIN matieres ma ON ma.id = d.matiere_id
        WHERE d.doc_type = 'concours'
          AND d.status   = 'approved'
          AND d.is_public = 1";

$types = '';
$params = [];
if ($yearFilter > 0) {
    $sql .= " AND d.exam_year = ?";
    $types .= 'i';
    $params[] = $yearFilter;
}
if ($search !== '') {
    $sql .= " AND d.title LIKE ?";
    $types .= 's';
    $params[] = '%'.$search.'%';
}
$sql .= " ORDER BY d.exam_year DESC, d.created_at DESC";

$docs = mysqli_prepare($con, $sql);
if ($types !== '') {
    mysqli_stmt_bind_param($docs, $types, ...$params);
}
mysqli_stmt_execute($docs);
$docs_res = emsp_stmt_fetch_all($docs);

// Liste des annees disponibles
$years = [];
$yq = mysqli_query($con, "SELECT DISTINCT exam_year FROM documents WHERE doc_type='concours' AND exam_year IS NOT NULL ORDER BY exam_year DESC");
while ($row = mysqli_fetch_row($yq)) { if ($row[0]) $years[] = (int)$row[0]; }

$page_title = 'Concours';
include __DIR__ . '/includes/header.php';
?>

<style>
.concours-shell {
    background:
        radial-gradient(circle at top right, rgba(0,85,204,.06), transparent 24%),
        linear-gradient(180deg, #f8fafd 0%, #ffffff 18%, #f8fafd 100%);
}
.concours-header {
    border-radius: 24px;
    background:
        linear-gradient(120deg, rgba(0,29,86,.92) 0%, rgba(0,48,135,.88) 52%, rgba(0,85,204,.62) 100%),
        url('assets/images/emsp-ivoire-tech-forum-2025.jpg') center 26% / cover no-repeat;
    box-shadow: var(--emsp-panel-shadow);
}
.concours-header::after {
    background: linear-gradient(90deg, rgba(8,19,38,.84) 0%, rgba(8,19,38,.56) 52%, rgba(8,19,38,.16) 100%);
}
.concours-filter-bar,
.concours-empty-card {
    border: 1px solid #dbe5f0;
    border-radius: 16px;
    background: #fff;
    box-shadow: var(--shadow-sm), var(--shadow-md);
}
.concours-filter-bar {
    padding: 1.15rem;
}
.doc-card {
    position: relative;
    border-radius: 16px;
    overflow: hidden;
    transition: transform .18s, box-shadow .18s;
    cursor: pointer;
    border: 1px solid #dbe5f0;
    background: #fff;
    box-shadow: 0 16px 38px rgba(18,39,74,.06);
}
.doc-card::before {
    content: '';
    position: absolute;
    inset: 0 0 auto;
    height: 4px;
    background: linear-gradient(90deg, var(--emsp-primary) 0%, rgba(0,85,204,.38) 100%);
    z-index: 2;
}
.doc-card:hover { transform: translateY(-4px); box-shadow: 0 18px 34px rgba(20,40,100,.12) !important; border-color: rgba(0,85,204,.16); }
.doc-card-wrap { opacity: 0; transform: translateY(24px); transition: opacity .45s ease, transform .45s ease; }
.doc-card-wrap.in-view { opacity: 1; transform: translateY(0); }
.doc-thumb { position: relative; height: 210px; overflow: hidden; display:flex; align-items:center; justify-content:center; background:#eef4fb; }
.thumb-media { width:100%; height:100%; position:relative; transition: transform .35s ease; will-change: transform; }
.doc-card:hover .thumb-media { transform: scale(1.06); }
.doc-thumb canvas, .doc-thumb .thumb-img { width:100%; height:100%; object-fit:cover; display:block; }
.thumb-illus { width:100%; height:100%; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:.5rem; }
.thumb-illus i { font-size:3rem; }
.thumb-illus .ext-label { font-size:.75rem; font-weight:800; letter-spacing:.08em; opacity:.8; }
.doc-lines { width:70%; }
.doc-lines span { display:block; height:4px; border-radius:2px; background:currentColor; opacity:.2; margin:5px 0; }
.doc-lines span:nth-child(2) { width:85%; } .doc-lines span:nth-child(3) { width:60%; }
.doc-grid-sim { display:grid; grid-template-columns:repeat(3,1fr); gap:2px; width:65%; opacity:.3; }
.doc-grid-sim div { height:14px; background:currentColor; border-radius:1px; }
.thumb-skeleton { position:absolute; inset:0; background:linear-gradient(90deg,#f0f4fa 25%,#dde4f0 50%,#f0f4fa 75%); background-size:200% 100%; animation: shimmer 1.4s infinite linear; }
.thumb-overlay { position:absolute; inset:0; background:linear-gradient(to top, rgba(8,19,38,.88) 0%, rgba(8,19,38,.52) 42%, rgba(8,19,38,.14) 100%); display:flex; flex-direction:column; justify-content:flex-end; padding:.8rem; transform:translateY(100%); transition:transform .3s ease-out; color:#fff; }
.doc-card:hover .thumb-overlay { transform:translateY(0); }
.overlay-title { font-size:.82rem; font-weight:700; text-transform:none; letter-spacing:.01em; line-height:1.3; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; margin-bottom:.35rem; opacity:0; transform:translateY(8px); transition:opacity .25s .1s, transform .25s .1s; }
.overlay-meta { font-size:.72rem; opacity:0; transform:translateY(8px); transition:opacity .2s .18s, transform .2s .18s; display:flex; justify-content:space-between; align-items:center; gap:.4rem; }
.overlay-btn { font-size:.72rem; font-weight:700; padding:.25rem .8rem; border-radius:999px; background:#fff; color:var(--emsp-primary); text-decoration:none; opacity:0; transform:translateY(8px); transition:opacity .2s .22s, transform .2s .22s; border:none; cursor:pointer; }
.doc-card:hover .overlay-title, .doc-card:hover .overlay-meta, .doc-card:hover .overlay-btn { opacity:1; transform:translateY(0); }
.ribbon-wrap { position:absolute; top:0; left:0; width:90px; height:90px; overflow:hidden; z-index:10; pointer-events:none; }
.ribbon { position:absolute; top:18px; left:-28px; width:110px; text-align:center; font-size:.6rem; font-weight:800; letter-spacing:.02em; text-transform:none; color:#fff; padding:.3em 0; transform:rotate(-45deg); box-shadow:0 2px 8px rgba(0,0,0,.2); background:#72243E; }
.thumb-badge-sem { position:absolute; top:10px; right:10px; font-size:.65rem; font-weight:600; padding:.22em .55em; border-radius:20px; background:rgba(255,255,255,.9); color:#4a5568; border:1px solid rgba(255,255,255,.5); }
.doc-card-body { padding:.9rem 1rem 1rem; }
.doc-card-title { font-size:.88rem; font-weight:700; text-transform:none; letter-spacing:-.01em; color:#163154; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; margin-bottom:.3rem; line-height:1.3; }
.doc-card-desc { font-size:.77rem; color:#6b7a99; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; margin-bottom:.5rem; }
.doc-card-meta { display:flex; justify-content:space-between; align-items:center; font-size:.73rem; color:#8a99b3; border-top:1px solid #f0f2f7; padding-top:.5rem; margin-top:.4rem; gap:.4rem; }
.doc-card-meta .author { font-weight:600; color:#4a5568; display:flex; align-items:center; gap:.3rem; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.doc-card-meta .stats { display:flex; gap:.6rem; flex-shrink:0; }
.btn-dl { display:flex; align-items:center; justify-content:center; gap:.4rem; width:100%; margin-top:.7rem; padding:.55rem; font-size:.8rem; font-weight:700; border-radius:12px; background:var(--emsp-primary); color:#fff; border:none; text-decoration:none; transition:background .15s, transform .15s; }
.btn-dl:hover { background:var(--emsp-accent); color:#fff; transform:translateY(-1px); }
@keyframes shimmer { 0% {background-position:200% 0;} 100% {background-position:-200% 0;} }
@media (prefers-reduced-motion: reduce) {
    .doc-card-wrap, .thumb-media, .thumb-overlay, .overlay-title, .overlay-meta, .overlay-btn, .thumb-skeleton {
        animation:none !important; transition:none !important; opacity:1 !important; transform:none !important;
    }
}
</style>

<section class="page-header concours-header">
    <div class="container">
        <h1>Annales de concours</h1>
        <p class="mb-0">Documents de concours partages par les etudiants EMSP.</p>
    </div>
</section>

<section class="section-pad concours-shell">
<div class="container">

    <?php include __DIR__ . '/message.php'; ?>

    <form class="row g-2 align-items-end mb-4 concours-filter-bar">
        <div class="col-md-4">
            <label class="form-label fw-semibold">Annee</label>
            <select class="form-select" name="annee" onchange="this.form.submit()">
                <option value="">Toutes</option>
                <?php foreach ($years as $y): ?>
                    <option value="<?= $y ?>" <?= $yearFilter === $y ? 'selected' : '' ?>><?= $y ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label fw-semibold">Recherche titre</label>
            <input type="text" class="form-control" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Ex : Math concours 2024">
        </div>
        <div class="col-md-2 d-grid">
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel me-1"></i>Filtrer</button>
        </div>
    </form>

    <?php if (count($docs_res) === 0): ?>
        <div class="card shadow-sm concours-empty-card">
            <div class="card-body text-center py-5 text-muted">
                <i class="bi bi-journals fs-1 d-block mb-2"></i>
                <h5>Aucun document de concours disponible pour le moment.</h5>
                <?php if (!empty($_SESSION['auth'])): ?>
                    <a href="upload.php" class="btn btn-primary mt-2">
                        <i class="bi bi-cloud-upload me-2"></i>Partager un document
                    </a>
                <?php else: ?>
                    <a href="register.php" class="btn btn-primary mt-2">S'inscrire pour partager</a>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>

        <div class="row g-4">
        <?php $idx=0; foreach ($docs_res as $d):
            $ext = strtolower(pathinfo($d['file_path'] ?? '', PATHINFO_EXTENSION));
            $mime = strtolower($d['mime_type'] ?? '');
            $thumb_type = 'other';
            if ($mime === 'application/pdf' || $ext === 'pdf') $thumb_type = 'pdf';
            elseif (in_array($ext, ['jpg','jpeg','png','gif','webp']) || strpos($mime, 'image/') === 0) $thumb_type = 'image';
            elseif (in_array($ext, ['doc','docx']) || strpos($mime, 'word') !== false || strpos($mime, 'officedocument.wordprocessingml') !== false) $thumb_type = 'word';
            elseif (in_array($ext, ['xls','xlsx']) || strpos($mime, 'excel') !== false || strpos($mime, 'spreadsheetml') !== false) $thumb_type = 'excel';
            elseif (in_array($ext, ['ppt','pptx']) || strpos($mime, 'powerpoint') !== false || strpos($mime, 'presentationml') !== false) $thumb_type = 'ppt';
            elseif (in_array($ext, ['zip','rar','7z','tar','gz'])) $thumb_type = 'zip';
            elseif (in_array($ext, ['txt','md','csv'])) $thumb_type = 'txt';
            $preview_url = 'telecharger.php?id=' . intval($d['id']) . '&preview=1';
            $size_kb = $d['file_size_bytes'] ? round($d['file_size_bytes'] / 1024) : 0;
            $delay = min($idx * 0.07, 0.5);
            $type_labels = [
                'cours' => 'Cours',
                'td' => 'TD',
                'correction' => 'Correction',
                'concours' => 'Concours',
                'examen' => 'Examen',
            ];
            $type_icons = [
                'cours' => 'bi-journal-bookmark-fill',
                'td' => 'bi-pencil-square',
                'correction' => 'bi-check2-square',
                'concours' => 'bi-trophy-fill',
                'examen' => 'bi-patch-question-fill',
            ];
            $type_class_map = [
                'cours' => 'primary',
                'td' => 'success',
                'correction' => 'info',
                'concours' => 'warning',
                'examen' => 'danger',
            ];
            $mobileLabel = $type_labels[$d['doc_type']] ?? ucfirst((string) $d['doc_type']);
            $mobileIcon = $type_icons[$d['doc_type']] ?? 'bi-file-earmark-text-fill';
            $mobileThumbClass = $type_class_map[$d['doc_type']] ?? 'neutral';
            $mobileAuthor = trim((string) ($d['first_name'] ?? ''));
            $mobileAuthor .= !empty($d['last_name']) ? ' ' . mb_substr((string) $d['last_name'], 0, 1) . '.' : '';
            $mobileContextParts = array_values(array_filter([
                trim((string) ($d['filiere_name'] ?? '')),
                trim((string) ($d['matiere_name'] ?? '')),
            ], static function ($value): bool {
                return $value !== '';
            }));
            $mobileContext = !empty($mobileContextParts) ? implode(' - ', $mobileContextParts) : $mobileLabel;
        ?>
            <div class="col-sm-6 col-xl-4 doc-card-wrap emsp-doc-delay" data-emsp-anim-delay="<?= h((string) $delay) ?>">
                <a class="emsp-doc-row-mobile d-md-none" href="document.php?id=<?= $d['id'] ?>">
                    <span class="emsp-doc-row-thumb emsp-doc-row-thumb--<?= h($mobileThumbClass) ?>">
                        <i class="bi <?= h($mobileIcon) ?>"></i>
                        <span class="emsp-doc-row-thumb-label"><?= h($mobileLabel) ?></span>
                    </span>
                    <span class="emsp-doc-row-body">
                        <span class="emsp-doc-row-title"><?= htmlspecialchars($d['title']) ?></span>
                        <span class="emsp-doc-row-context"><?= h($mobileContext) ?></span>
                        <span class="emsp-doc-row-meta">
                            <span><i class="bi bi-person"></i><?= h(trim($mobileAuthor) !== '' ? trim($mobileAuthor) : 'EMSP Docs') ?></span>
                            <span><i class="bi bi-file-earmark"></i><?= $size_kb ?> Ko</span>
                            <span><i class="bi bi-download"></i><?= intval($d['download_count']) ?></span>
                        </span>
                    </span>
                    <span class="emsp-doc-row-side">
                        <span class="emsp-doc-row-chevron"><i class="bi bi-chevron-right"></i></span>
                    </span>
                </a>

                <div class="doc-card h-100 d-none d-md-block">
                    <div class="doc-thumb">
                        <div class="ribbon-wrap"><div class="ribbon">Concours</div></div>
                        <?php if (!empty($d['semester'])): ?>
                        <span class="thumb-badge-sem"><?= htmlspecialchars($d['semester']) ?></span>
                        <?php endif; ?>
                        <div class="thumb-media">
                            <?php if ($thumb_type === 'pdf'): ?>
                                <div class="thumb-illus">
                                    <i class="bi bi-file-earmark-pdf-fill"></i>
                                    <div class="doc-lines">
                                        <span></span><span></span><span></span>
                                    </div>
                                    <span class="ext-label">.PDF</span>
                                </div>
                            <?php elseif ($thumb_type === 'image'): ?>
                                <img class="thumb-img" src="<?= htmlspecialchars($preview_url) ?>" alt="<?= htmlspecialchars($d['title']) ?>" loading="lazy" onerror="this.style.display='none';">
                            <?php elseif ($thumb_type === 'word'): ?>
                                <div class="thumb-illus">
                                    <i class="bi bi-file-word-fill"></i>
                                    <div class="doc-lines"><span></span><span></span><span></span></div>
                                    <span class="ext-label">.<?= strtoupper($ext) ?></span>
                                </div>
                            <?php elseif ($thumb_type === 'excel'): ?>
                                <div class="thumb-illus">
                                    <i class="bi bi-file-excel-fill"></i>
                                    <div class="doc-grid-sim"><div></div><div></div><div></div><div></div><div></div><div></div><div></div><div></div><div></div></div>
                                    <span class="ext-label">.<?= strtoupper($ext) ?></span>
                                </div>
                            <?php elseif ($thumb_type === 'ppt'): ?>
                                <div class="thumb-illus">
                                    <i class="bi bi-file-ppt-fill"></i>
                                    <div>
                                        <span>SLIDE</span>
                                    </div>
                                    <span class="ext-label">.<?= strtoupper($ext) ?></span>
                                </div>
                            <?php elseif ($thumb_type === 'zip'): ?>
                                <div class="thumb-illus">
                                    <i class="bi bi-file-zip-fill"></i>
                                    <div>
                                        <i class="bi bi-file-earmark"></i>
                                        <i class="bi bi-file-earmark"></i>
                                        <i class="bi bi-file-earmark"></i>
                                    </div>
                                    <span class="ext-label">.<?= strtoupper($ext) ?></span>
                                </div>
                            <?php elseif ($thumb_type === 'txt'): ?>
                                <div class="thumb-illus">
                                    <i class="bi bi-file-text-fill"></i>
                                    <div class="doc-lines"><span></span><span></span><span></span></div>
                                    <span class="ext-label">.<?= strtoupper($ext) ?></span>
                                </div>
                            <?php else: ?>
                                <div class="thumb-illus">
                                    <i class="bi bi-file-earmark-fill"></i>
                                    <span class="ext-label"><?= $ext ? '.' . strtoupper($ext) : 'FICHIER' ?></span>
                                </div>
                            <?php endif; ?>
                            <div class="thumb-overlay">
                                <div class="overlay-title"><?= htmlspecialchars($d['title']) ?></div>
                                <div class="overlay-meta">
                                    <span><i class="bi bi-person me-1"></i><?= htmlspecialchars($d['first_name']) ?></span>
                                    <span><i class="bi bi-download me-1"></i><?= intval($d['download_count']) ?></span>
                                </div>
                                <a class="overlay-btn text-decoration-none" href="document.php?id=<?= $d['id'] ?>">Voir</a>
                            </div>
                        </div><!-- thumb-media -->
                    </div>
                    <div class="doc-card-body">
                        <div class="doc-card-title"><?= htmlspecialchars($d['title']) ?></div>
                        <?php if (!empty($d['description'])): ?>
                        <div class="doc-card-desc"><?= htmlspecialchars($d['description']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($d['filiere_name']) || !empty($d['matiere_name'])): ?>
                            <div class="text-muted">
                                <i class="bi bi-diagram-3 me-1"></i>
                                <?= htmlspecialchars($d['filiere_name'] ?? '') ?>
                                <?php if (!empty($d['matiere_name'])): ?> - <?= htmlspecialchars($d['matiere_name']) ?><?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div class="doc-card-meta">
                            <div class="author"><i class="bi bi-person"></i><span class="text-truncate"><?= htmlspecialchars($d['first_name']) ?> <?= htmlspecialchars(mb_substr($d['last_name'],0,1)) ?>.</span></div>
                            <div class="stats">
                                <span><i class="bi bi-download me-1"></i><?= $d['download_count'] ?></span>
                                <span><i class="bi bi-file-earmark me-1"></i><?= $size_kb ?> Ko</span>
                            </div>
                        </div>
                        <form method="post"
                              action="telecharger.php?id=<?= $d['id'] ?>&public=1&download=1"
                              class="m-0"
                              onsubmit="event.stopPropagation();">
                            <?php csrf_input(); ?>
                            <button type="submit" class="btn-dl" onclick="event.stopPropagation();">
                            <i class="bi bi-download"></i>Telecharger
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        <?php $idx++; endforeach; ?>
        </div>

    <?php endif; ?>

</div>
</section>

<script>
(function () {
    const cards = document.querySelectorAll('.doc-card-wrap');
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('in-view');
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });
    cards.forEach(el => observer.observe(el));

})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>



