<?php
include_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['auth'])) {
    $_SESSION['message'] = 'Connectez-vous pour voir vos favoris.';
    header('Location: login.php'); exit(0);
}

include_once __DIR__ . '/admin/config/dbcon.php';
include_once __DIR__ . '/includes/csrf.php';
include_once __DIR__ . '/includes/pagination.php';

$uid = intval($_SESSION['auth_user']['id']);
$page_num = max(1, intval($_GET['page'] ?? 1));
$per_page = 12;

// Retrait favori
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['doc_id'])) {
    verify_csrf_token();
    $doc_id = intval($_POST['doc_id']);
    $del = mysqli_prepare($con,
        "DELETE FROM favorites WHERE user_id=? AND document_id=?");
    mysqli_stmt_bind_param($del, 'ii', $uid, $doc_id);
    mysqli_stmt_execute($del); mysqli_stmt_close($del);
    $_SESSION['message'] = 'Document retire de vos favoris.';
    header('Location: mes-favoris.php'); exit(0);
}

// Recuperer les favoris
$cs = mysqli_prepare($con,
    "SELECT COUNT(*)
     FROM favorites f
     JOIN documents d ON d.id = f.document_id
     WHERE f.user_id = ? AND d.status = 'approved'");
mysqli_stmt_bind_param($cs, 'i', $uid);
mysqli_stmt_execute($cs);
mysqli_stmt_bind_result($cs, $total);
mysqli_stmt_fetch($cs);
mysqli_stmt_close($cs);

$total = (int) ($total ?? 0);
$total_pages = max(1, (int) ceil($total / $per_page));
$page_num = min($page_num, $total_pages);
$offset = ($page_num - 1) * $per_page;

$stmt = mysqli_prepare($con,
    "SELECT d.id, d.title, d.doc_type, d.semester, d.download_count,
            d.file_size_bytes, d.file_path, d.mime_type, d.created_at AS doc_created_at,
            f.created_at AS fav_date,
            u.first_name, u.last_name,
            fi.name AS filiere_name, ma.name AS matiere_name
     FROM favorites f
     JOIN documents d ON d.id = f.document_id
     JOIN users u ON u.id = d.uploader_id
     LEFT JOIN filieres fi ON fi.id = d.filiere_id
     LEFT JOIN matieres ma ON ma.id = d.matiere_id
     WHERE f.user_id = ? AND d.status = 'approved'
     ORDER BY f.created_at DESC
     LIMIT ? OFFSET ?");
mysqli_stmt_bind_param($stmt, 'iii', $uid, $per_page, $offset);
mysqli_stmt_execute($stmt);
$favs = emsp_stmt_fetch_all($stmt);
mysqli_stmt_close($stmt);
$favs_count = count($favs);

$type_colors = ['cours'=>'bg-primary','td'=>'bg-success','correction'=>'bg-info text-dark',
                'concours'=>'bg-warning text-dark','examen'=>'bg-danger'];

$page_title = 'Mes Favoris';
include __DIR__ . '/includes/header.php';
?>

<style>
.doc-card { border-radius: 14px; overflow: hidden; transition: transform .18s, box-shadow .18s; cursor: pointer; border: 1px solid #e8ecf4; background: #fff; }
.doc-card:hover { transform: translateY(-4px); box-shadow: 0 10px 30px rgba(20,40,100,.12) !important; }
.doc-card-wrap { opacity: 0; transform: translateY(24px); transition: opacity .45s ease, transform .45s ease; }
.doc-card-wrap.in-view { opacity: 1; transform: translateY(0); }
.doc-thumb { position: relative; height: 210px; overflow: hidden; display:flex; align-items:center; justify-content:center; background:#f0f4fa; }
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
.thumb-overlay { position:absolute; inset:0; background:linear-gradient(to top, rgba(10,20,50,.85) 0%, rgba(10,20,50,.3) 60%, transparent 100%); display:flex; flex-direction:column; justify-content:flex-end; padding:.8rem; transform:translateY(100%); transition:transform .3s ease-out; color:#fff; }
.doc-card:hover .thumb-overlay { transform:translateY(0); }
.overlay-title { font-size:.82rem; font-weight:700; text-transform:uppercase; letter-spacing:.03em; line-height:1.3; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; margin-bottom:.35rem; opacity:0; transform:translateY(8px); transition:opacity .25s .1s, transform .25s .1s; }
.overlay-meta { font-size:.72rem; opacity:0; transform:translateY(8px); transition:opacity .2s .18s, transform .2s .18s; display:flex; justify-content:space-between; align-items:center; gap:.4rem; }
.overlay-btn { font-size:.72rem; font-weight:700; padding:.25rem .8rem; border-radius:20px; background:#fff; color:#004D2A; text-decoration:none; opacity:0; transform:translateY(8px); transition:opacity .2s .22s, transform .2s .22s; border:none; cursor:pointer; }
.doc-card:hover .overlay-title, .doc-card:hover .overlay-meta, .doc-card:hover .overlay-btn { opacity:1; transform:translateY(0); }
.thumb-badge-sem { position:absolute; top:10px; right:10px; font-size:.65rem; font-weight:600; padding:.22em .55em; border-radius:20px; background:rgba(255,255,255,.9); color:#4a5568; border:1px solid rgba(255,255,255,.5); }
.badge-new { position:absolute; top:10px; left:10px; background:#e53935; color:#fff; font-size:.62rem; font-weight:800; padding:.22em .6em; border-radius:20px; letter-spacing:.05em; animation:pulse-new 1.8s ease-in-out infinite; box-shadow:0 0 0 0 rgba(229,57,53,.6); }
@keyframes pulse-new { 0%{box-shadow:0 0 0 0 rgba(229,57,53,.6);} 60%{box-shadow:0 0 0 8px rgba(229,57,53,.0);} 100%{box-shadow:0 0 0 0 rgba(229,57,53,.0);} }
.doc-card-body { padding:.9rem 1rem 1rem; }
.doc-card-title { font-size:.9rem; font-weight:700; text-transform:uppercase; letter-spacing:.025em; color:#1a2540; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; margin-bottom:.3rem; line-height:1.3; }
.doc-card-desc { font-size:.77rem; color:#6b7a99; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; margin-bottom:.5rem; }
.doc-card-meta { display:flex; justify-content:space-between; align-items:center; font-size:.73rem; color:#8a99b3; border-top:1px solid #f0f2f7; padding-top:.5rem; margin-top:.4rem; gap:.4rem; }
.doc-card-meta .author { font-weight:600; color:#4a5568; display:flex; align-items:center; gap:.3rem; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.doc-card-meta .stats { display:flex; gap:.6rem; flex-shrink:0; }
.btn-dl { display:flex; align-items:center; justify-content:center; gap:.4rem; width:100%; margin-top:.7rem; padding:.45rem; font-size:.8rem; font-weight:600; border-radius:8px; background:#004D2A; color:#fff; border:none; text-decoration:none; transition:background .15s; }
.btn-dl:hover { background:#0f2548; color:#fff; }
@keyframes shimmer { 0% {background-position:200% 0;} 100% {background-position:-200% 0;} }
@media (prefers-reduced-motion: reduce) {
    .doc-card-wrap, .thumb-media, .thumb-overlay, .overlay-title, .overlay-meta, .overlay-btn, .thumb-skeleton, .badge-new {
        animation:none !important; transition:none !important; opacity:1 !important; transform:none !important;
    }
}
</style>

<section class="page-header">
    <div class="container">
        <h1>Mes Favoris</h1>
        <p class="mb-0">Documents que vous avez mis en favori</p>
    </div>
</section>

<section class="section-pad">
<div class="container">

    <?php include __DIR__ . '/message.php'; ?>

    <?php if (count($favs) === 0): ?>
        <div class="card shadow-sm">
            <div class="card-body text-center py-5">
                <i class="bi bi-star fs-1 text-warning d-block mb-3"></i>
                <h5 class="fw-semibold">Aucun favori pour le moment</h5>
                <p class="text-muted">
                    Ajoutez des documents a vos favoris depuis la bibliotheque.
                </p>
                <a href="bibliotheque.php" class="btn btn-primary">
                    <i class="bi bi-book me-2"></i>Parcourir la bibliotheque
                </a>
            </div>
        </div>
    <?php else: ?>

        <div class="row g-4">
        <?php $idx=0; foreach ($favs as $d):
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
            $is_new = (time() - strtotime((string)$d['doc_created_at'])) < 7*86400;
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
            $mobileContext = !empty($mobileContextParts) ? implode(' • ', $mobileContextParts) : $mobileLabel;
        ?>
            <div class="col-md-6 col-xl-4 doc-card-wrap emsp-doc-delay" data-emsp-anim-delay="<?= h((string) $delay) ?>">
                <div class="emsp-doc-row-shell d-md-none">
                    <a class="emsp-doc-row-mobile" href="document.php?id=<?= $d['id'] ?>">
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
                            <span class="emsp-doc-row-note">Ajoute le <?= date('d/m/Y', strtotime((string)$d['fav_date'])) ?></span>
                        </span>
                        <span class="emsp-doc-row-side">
                            <?php if ($is_new): ?>
                                <span class="emsp-doc-row-badge">Nouveau</span>
                            <?php endif; ?>
                            <span class="emsp-doc-row-chevron"><i class="bi bi-chevron-right"></i></span>
                        </span>
                    </a>
                    <form method="POST" class="emsp-doc-row-inline-action">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <input type="hidden" name="doc_id" value="<?= $d['id'] ?>">
                        <button type="submit"
                                class="btn btn-outline-danger"
                                title="Retirer des favoris"
                                data-emsp-confirm-auto="1"
                                data-confirm="Retirer ce document de vos favoris ?"
                                data-confirm-detail="Le document restera disponible dans la bibliotheque."
                                data-confirm-type="warning"
                                data-confirm-ok="Oui, retirer">
                            <i class="bi bi-star-fill text-warning"></i>
                        </button>
                    </form>
                </div>

                <div class="doc-card h-100 d-none d-md-block">
                    <div class="doc-thumb">
                        <?php if ($is_new): ?><span class="badge-new">Nouveau</span><?php endif; ?>
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
                        </div>
                    </div>
                    <div class="doc-card-body">
                        <div class="doc-card-title"><?= htmlspecialchars($d['title']) ?></div>
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
                                <span><i class="bi bi-download me-1"></i><?= intval($d['download_count']) ?></span>
                                <span><i class="bi bi-file-earmark me-1"></i><?= $size_kb ?> Ko</span>
                            </div>
                        </div>
                        <div class="d-flex gap-2 mt-2">
                            <a href="document.php?id=<?= $d['id'] ?>" class="btn btn-sm btn-outline-primary flex-grow-1" onclick="event.stopPropagation();">
                                <i class="bi bi-eye me-1"></i>Voir
                            </a>
                            <form method="post"
                                  action="telecharger.php?id=<?= $d['id'] ?>&download=1"
                                  class="m-0"
                                  onsubmit="event.stopPropagation();">
                                <?php csrf_input(); ?>
                                <button type="submit" class="btn btn-sm btn-primary" onclick="event.stopPropagation();" title="Telecharger">
                                    <i class="bi bi-download"></i>
                                </button>
                            </form>
                            <form method="POST" onsubmit="event.stopPropagation();">
                                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                <input type="hidden" name="doc_id" value="<?= $d['id'] ?>">
                                <button type="submit"
                                        class="btn btn-sm btn-outline-danger"
                                        title="Retirer des favoris"
                                        data-emsp-confirm-auto="1"
                                        data-confirm="Retirer ce document de vos favoris ?"
                                        data-confirm-detail="Le document restera disponible dans la bibliotheque."
                                        data-confirm-type="warning"
                                        data-confirm-ok="Oui, retirer">
                                    <i class="bi bi-star-fill text-warning"></i>
                                </button>
                            </form>
                        </div>
                        <div class="text-muted mt-2">
                            Ajoute le <?= date('d/m/Y', strtotime((string)$d['fav_date'])) ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php $idx++; endforeach; ?>
        </div>

    <?php endif; ?>

    <?php if ($favs_count > 0 && $total_pages > 1): ?>
        <nav class="mt-4">
            <ul class="pagination justify-content-center">
                <li class="page-item <?= $page_num <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= 'mes-favoris.php?page=' . max(1, $page_num - 1) ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
                <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                    <li class="page-item <?= $p === $page_num ? 'active' : '' ?>">
                        <a class="page-link" href="<?= 'mes-favoris.php?page=' . $p ?>"><?= $p ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?= $page_num >= $total_pages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= 'mes-favoris.php?page=' . min($total_pages, $page_num + 1) ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
            </ul>
        </nav>
    <?php endif; ?>

    <div class="mt-4">
        <a href="mon-profil.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Retour au profil
        </a>
    </div>

</div>
</section>

<?php
$page_scripts = <<<HTML
<script>
(function () {
    // Reveal cards on scroll + animate download counters
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
HTML;
include __DIR__ . '/includes/footer.php';
?>




