<?php
include_once __DIR__ . '/includes/bootstrap.php';
$page_title = 'Bibliotheque';
if (empty($_SESSION['auth'])) {
    $_SESSION['redirect_after_login'] = 'bibliotheque.php';
    include __DIR__ . '/includes/header.php';
    ?>
    <style>
    .emsp-library-gate {
        background:
            radial-gradient(circle at top right, rgba(8,97,54,.12), transparent 28%),
            linear-gradient(180deg, #f6f9fd 0%, #ffffff 100%);
    }
    .emsp-library-gate-hero,
    .emsp-library-gate-card {
        border-radius: 28px;
        border: 1px solid #dbe5f0;
        box-shadow: 0 20px 48px rgba(18,39,74,.08);
    }
    .emsp-library-gate-hero {
        position: relative;
        overflow: hidden;
        padding: 2.5rem;
        color: #fff;
        background:
            linear-gradient(120deg, rgba(9,27,49,.86) 0%, rgba(18,41,74,.82) 48%, rgba(8,97,54,.56) 100%),
            url('assets/images/emsp-campus-ceremony.jpg') center 30% / cover no-repeat;
    }
    .emsp-library-gate-hero h1,
    .emsp-library-gate-hero h2,
    .emsp-library-gate-hero h3,
    .emsp-library-gate-hero p {
        color: #fff !important;
    }
    .emsp-library-gate-hero .h2 {
        color: #fff !important;
        font-size: clamp(1.6rem, 4vw, 2.4rem);
        font-family: var(--font-serif, 'Playfair Display', Georgia, serif);
    }
    .emsp-library-gate-hero::before {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(90deg, rgba(8,19,38,.86) 0%, rgba(8,19,38,.62) 48%, rgba(8,19,38,.18) 100%);
        pointer-events: none;
    }
    .emsp-library-gate-hero > * {
        position: relative;
        z-index: 1;
    }
    .emsp-library-gate-card {
        background: #fff;
        padding: 1.5rem;
    }
    </style>

    <section class="section-pad emsp-library-gate">
        <div class="container">
            <div class="row g-4 align-items-stretch">
                <div class="col-lg-7">
                    <div class="emsp-library-gate-hero h-100">
                        <span class="badge text-bg-light text-primary fw-semibold mb-3">Bibliotheque EMSP</span>
                        <h1 class="h2 fw-bold mb-3">Connecte-toi pour acceder a la bibliotheque</h1>
                        <p class="mb-4 text-white-50">
                            La bibliotheque regroupe les cours, TD, examens, corrections et concours
                            partages par la communaute EMSP. Une fois connecte, tu peux aussi enregistrer
                            tes favoris et retrouver ton historique.
                        </p>
                        <div class="d-flex flex-wrap gap-3">
                            <a class="btn btn-light btn-lg emsp-open-login-modal"
                               href="login.php"
                               data-bs-toggle="modal"
                               data-bs-target="#emspQuickLoginModal"
                               data-emsp-modal-link="1">
                                <i class="bi bi-box-arrow-in-right me-2"></i>Se connecter
                            </a>
                            <a class="btn btn-outline-light btn-lg" href="register.php">
                                <i class="bi bi-person-plus-fill me-2"></i>S'inscrire
                            </a>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="emsp-library-gate-card h-100">
                        <h2 class="h4 fw-bold mb-3">Ce que tu trouveras ici</h2>
                        <div class="d-grid gap-3">
                            <div class="rounded-4 border p-3">
                                <strong class="d-block mb-1">Cours et TD</strong>
                                <span class="text-muted">Retrouve les ressources utiles par filiere, niveau et matiere.</span>
                            </div>
                            <div class="rounded-4 border p-3">
                                <strong class="d-block mb-1">Examens et concours</strong>
                                <span class="text-muted">Revise avec les documents valides les plus recents.</span>
                            </div>
                            <div class="rounded-4 border p-3">
                                <strong class="d-block mb-1">Favoris et historique</strong>
                                <span class="text-muted">Sauvegarde ce qui compte et reprends facilement la ou tu t'es arrete.</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

$isAuth = !empty($_SESSION['auth']);
$currentUserId = $isAuth ? (int) ($_SESSION['auth_user']['id'] ?? 0) : 0;
$currentRole = strtolower(trim((string) ($_SESSION['auth_role'] ?? ($_SESSION['auth_user']['role'] ?? ''))));
$isStaff = in_array($currentRole, ['admin', 'moderateur'], true);
if (!$isAuth) {
    $f_type = 'concours';
}

include_once __DIR__ . '/admin/config/dbcon.php';
include_once __DIR__ . '/includes/content-helpers.php';
include_once __DIR__ . '/includes/document-taxonomy.php';

// Filtres et recherche
$search     = trim($_GET['q'] ?? '');
$f_filiere  = intval($_GET['filiere']  ?? 0);
$f_licence  = intval($_GET['licence']  ?? 0);
$f_matiere  = intval($_GET['matiere']  ?? 0);
$f_type     = trim($_GET['doc_type']   ?? '');
$f_semester = trim($_GET['semester']   ?? '');
$page_num   = max(1, intval($_GET['page'] ?? 1));
$per_page   = 12;
$hasActiveFilters = $search !== '' || $f_filiere > 0 || $f_licence > 0 || $f_matiere > 0 || $f_type !== '' || $f_semester !== '';
$activeFilterCount = 0;
foreach ([
    $search !== '',
    $f_filiere > 0,
    $f_licence > 0,
    $f_matiere > 0,
    $f_type !== '',
    $f_semester !== '',
] as $filterIsActive) {
    if ($filterIsActive) {
        $activeFilterCount++;
    }
}

// Construction du WHERE
$params = [];
$types  = '';
if ($isStaff) {
    $where = 'WHERE 1=1';
} elseif ($isAuth) {
    $where = "WHERE ((d.status = 'approved' AND d.is_public = 1) OR d.uploader_id = ?)";
    $params[] = $currentUserId;
    $types .= 'i';
} else {
    $f_filiere = 0;
    $f_licence = 0;
    $f_matiere = 0;
    $f_semester = '';
    $f_type = 'concours';
    $hasActiveFilters = $search !== '' || $f_type !== '';
    $where = "WHERE d.status = 'approved' AND d.is_public = 1";
    $where .= " AND d.doc_type = 'concours'";
}

if ($search !== '') {
    $like = '%' . $search . '%';
    $where .= " AND (d.title LIKE ? OR d.description LIKE ?)";
    $params[] = $like; $params[] = $like;
    $types   .= 'ss';
}
if ($f_filiere > 0)  {
    if (emsp_document_filieres_enabled($con)) {
        $where .= " AND EXISTS (SELECT 1 FROM document_filieres df WHERE df.document_id = d.id AND df.filiere_id = ?)";
    } else {
        $where .= " AND d.filiere_id = ?";
    }
    $params[] = $f_filiere;
    $types .= 'i';
}
if ($f_licence > 0)  { $where .= " AND d.licence_id = ?";  $params[] = $f_licence;  $types .= 'i'; }
if ($f_matiere > 0)  { $where .= " AND d.matiere_id = ?";  $params[] = $f_matiere;  $types .= 'i'; }
if ($f_type !== '')  { $where .= " AND d.doc_type = ?";    $params[] = $f_type;     $types .= 's'; }
if ($f_semester !== '') { $where .= " AND d.semester = ?"; $params[] = $f_semester; $types .= 's'; }

// Compte total
$count_sql = "SELECT COUNT(*) FROM documents d $where";
$cs = mysqli_prepare($con, $count_sql);
if ($params) {
    mysqli_stmt_bind_param($cs, $types, ...$params);
}
mysqli_stmt_execute($cs);
mysqli_stmt_bind_result($cs, $total);
mysqli_stmt_fetch($cs);
mysqli_stmt_close($cs);

$total_pages = max(1, (int) ceil($total / $per_page));
$page_num    = min($page_num, $total_pages);
$offset      = ($page_num - 1) * $per_page;

// Requete principale
$sql = "SELECT d.id, d.title, d.description, d.doc_type, d.semester,
               d.file_path, d.mime_type, d.file_size_bytes,
               d.download_count, d.like_count, d.created_at,
               d.status, d.is_public, d.uploader_id,
               u.first_name, u.last_name,
               f.name AS filiere_name, ma.name AS matiere_name
        FROM documents d
        JOIN users u ON u.id = d.uploader_id
        LEFT JOIN filieres f  ON f.id  = d.filiere_id
        LEFT JOIN matieres ma ON ma.id = d.matiere_id
        $where
        ORDER BY d.created_at DESC
        LIMIT ? OFFSET ?";

$params_with_limits = $params;
$types_with_limits  = $types . 'ii';
$params_with_limits[] = $per_page;
$params_with_limits[] = $offset;

$stmt = mysqli_prepare($con, $sql);
mysqli_stmt_bind_param($stmt, $types_with_limits, ...$params_with_limits);
mysqli_stmt_execute($stmt);
$docs = emsp_stmt_fetch_all($stmt);
mysqli_stmt_close($stmt);
foreach ($docs as &$d) {
    foreach (['title','description','first_name','last_name','filiere_name','matiere_name'] as $f) {
        if (isset($d[$f]) && is_string($d[$f])) {
            $d[$f] = emsp_fix_mojibake($d[$f]);
        }
    }
}
unset($d);

$docIds = array_map(static function (array $row): int { return (int) ($row['id'] ?? 0); }, $docs);
$filiereLabelMap = emsp_fetch_document_filiere_labels($con, $docIds);
foreach ($docs as &$d) {
    $labels = $filiereLabelMap[(int) ($d['id'] ?? 0)] ?? [];
    $d['filiere_labels'] = $labels;
    $d['filiere_label_display'] = !empty($labels) ? implode(', ', $labels) : trim((string) ($d['filiere_name'] ?? ''));
}
unset($d);

// Referentiels pour filtres
$filieres = mysqli_query($con, "SELECT id, name FROM filieres WHERE status='active' ORDER BY name");
$licences = mysqli_query($con, "SELECT id, name FROM licences WHERE status='active' ORDER BY name");
$matieres = mysqli_query($con, "SELECT id, name FROM matieres WHERE status='active' ORDER BY name");

function pagination_url($page) {
    $params = $_GET;
    $params['page'] = $page;
    return 'bibliotheque.php?' . http_build_query($params);
}

function bibliotheque_query_url(array $changes = []): string
{
    $params = $_GET;
    foreach ($changes as $key => $value) {
        if ($value === null) {
            unset($params[$key]);
            continue;
        }
        $params[$key] = $value;
    }
    $params['page'] = 1;
    return 'bibliotheque.php?' . http_build_query($params);
}

function emsp_dicebear_avatar(string $seed, int $size = 32): string
{
    $seed = trim($seed) !== '' ? trim($seed) : 'EMSP';
    $parts = preg_split('/\s+/', $seed) ?: [];
    $initials = '';
    foreach ($parts as $part) {
        $part = trim((string) $part);
        if ($part === '') {
            continue;
        }
        $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        if (mb_strlen($initials) >= 2) {
            break;
        }
    }
    if ($initials === '') {
        $initials = 'E';
    }

    $toneIndex = (abs((int) crc32($seed)) % 6) + 1;
    $sizeClass = 'emsp-inline-avatar--32';
    if ($size <= 26) {
        $sizeClass = 'emsp-inline-avatar--26';
    } elseif ($size <= 30) {
        $sizeClass = 'emsp-inline-avatar--30';
    }

    return '<span class="emsp-inline-avatar ' . $sizeClass . ' emsp-inline-avatar--tone-' . $toneIndex . '"'
        . ' aria-label="' . htmlspecialchars($seed, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"'
        . ' role="img">'
        . htmlspecialchars($initials, ENT_QUOTES | ENT_HTML5, 'UTF-8')
        . '</span>';
}

include __DIR__ . '/includes/header.php';
?>

<style>
.doc-card {
    border-radius: 14px;
    overflow: hidden;
    transition: transform .18s, box-shadow .18s;
    cursor: pointer;
    border: 1px solid #e8ecf4;
    background: #fff;
}
.doc-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 10px 30px rgba(20,40,100,.16) !important;
}
.doc-card-wrap {
    opacity: 0;
    transform: translateY(24px);
    transition: opacity .45s ease, transform .45s ease;
}
.doc-card-wrap.in-view {
    opacity: 1;
    transform: translateY(0);
}
.doc-thumb {
    position: relative;
    height: 210px;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f0f4fa;
}
.thumb-media {
    width: 100%;
    height: 100%;
    position: relative;
    transition: transform .35s ease;
    will-change: transform;
}
.doc-card:hover .thumb-media { transform: scale(1.06); }
.doc-card:hover .thumb-badge-type {
    transform: scale(calc(1 / 1.06));
    transform-origin: top left;
}
.doc-card:hover .thumb-badge-sem {
    transform: scale(calc(1 / 1.06));
    transform-origin: top right;
}
.doc-thumb canvas,
.doc-thumb .thumb-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.thumb-pdf-canvas-wrap {
    position: relative;
}
.pdf-thumb-canvas {
    width: 100%;
    height: 100%;
    display: block;
    background: #fff;
}
.thumb-pdf-canvas-wrap .doc-lines,
.thumb-pdf-canvas-wrap .ext-label {
    position: absolute;
    left: 14%;
    right: 14%;
}
.thumb-pdf-canvas-wrap .doc-lines {
    bottom: 18%;
}
.thumb-pdf-canvas-wrap .ext-label {
    left: auto;
    right: 12px;
    bottom: 12px;
    background: rgba(255,255,255,.86);
    padding: .18rem .46rem;
    border-radius: 999px;
}
.doc-thumb .thumb-illus {
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: .5rem;
}
.doc-thumb .thumb-illus i { font-size: 3rem; }
.doc-thumb .thumb-illus .ext-label {
    font-size: .75rem;
    font-weight: 800;
    letter-spacing: .08em;
    opacity: .8;
}
.doc-lines { width: 70%; }
.doc-lines span {
    display: block;
    height: 4px;
    border-radius: 2px;
    background: currentColor;
    opacity: .2;
    margin: 5px 0;
}
.doc-lines span:nth-child(2) { width: 85%; }
.doc-lines span:nth-child(3) { width: 60%; }
.doc-grid-sim {
    display: grid;
    grid-template-columns: repeat(3,1fr);
    gap: 2px;
    width: 65%;
    opacity: .3;
}
.doc-grid-sim div {
    height: 14px;
    background: currentColor;
    border-radius: 1px;
}
.thumb-badge-type {
    position: absolute;
    top: 10px; left: 10px;
    font-size: .68rem;
    font-weight: 700;
    padding: .25em .65em;
    border-radius: 20px;
    text-transform: uppercase;
    letter-spacing: .03em;
    box-shadow: 0 2px 6px rgba(0,0,0,.12);
}
.thumb-badge-sem {
    position: absolute;
    top: 10px; right: 10px;
    font-size: .65rem;
    font-weight: 600;
    padding: .22em .55em;
    border-radius: 20px;
    background: rgba(255,255,255,.88);
    color: #4a5568;
    border: 1px solid rgba(255,255,255,.5);
}
.thumb-skeleton {
    position: absolute;
    inset: 0;
    background: linear-gradient(90deg,#f0f4fa 25%,#dde4f0 50%,#f0f4fa 75%);
    background-size: 200% 100%;
    animation: shimmer 1.4s infinite linear;
}
.thumb-overlay {
    position: absolute;
    inset: 0;
    background: linear-gradient(to top, rgba(10,20,50,.85) 0%, rgba(10,20,50,.3) 60%, transparent 100%);
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    padding: .8rem;
    transform: translateY(100%);
    transition: transform .3s ease-out;
    color: #fff;
}
.doc-card:hover .thumb-overlay { transform: translateY(0); }
.overlay-title {
    font-size: .82rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .03em;
    line-height: 1.3;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    margin-bottom: .35rem;
    opacity: 0;
    transform: translateY(8px);
    transition: opacity .25s .1s, transform .25s .1s;
}
.overlay-meta {
    font-size: .72rem;
    opacity: 0;
    transform: translateY(8px);
    transition: opacity .2s .18s, transform .2s .18s;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: .4rem;
}
.overlay-btn {
    font-size: .72rem;
    font-weight: 700;
    padding: .25rem .8rem;
    border-radius: 20px;
    background: #fff;
    color: #004D2A;
    text-decoration: none;
    opacity: 0;
    transform: translateY(8px);
    transition: opacity .2s .22s, transform .2s .22s;
    border: none;
    cursor: pointer;
}
.doc-card:hover .overlay-title,
.doc-card:hover .overlay-meta,
.doc-card:hover .overlay-btn {
    opacity: 1;
    transform: translateY(0);
}
.badge-new {
    position: absolute;
    top: 10px;
    right: 10px;
    background: #e53935;
    color: #fff;
    font-size: .62rem;
    font-weight: 800;
    padding: .22em .6em;
    border-radius: 20px;
    letter-spacing: .05em;
    animation: pulse-new 1.8s ease-in-out infinite;
    box-shadow: 0 0 0 0 rgba(229,57,53,.6);
    z-index: 11;
}
.ribbon-wrap {
    position: absolute;
    top: 0; left: 0;
    width: 90px; height: 90px;
    overflow: hidden;
    z-index: 10;
    pointer-events: none;
}
.ribbon {
    position: absolute;
    top: 18px; left: -28px;
    width: 110px;
    text-align: center;
    font-size: .6rem;
    font-weight: 800;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: #fff;
    padding: .3em 0;
    transform: rotate(-45deg);
    box-shadow: 0 2px 8px rgba(0,0,0,.2);
}
.ribbon-cours   { background: #006B3C; }
.ribbon-td      { background: #006B3C; }
.ribbon-correction { background: #00695c; }
.ribbon-concours { background: #D4900A; }
.ribbon-examen  { background: #b71c1c; }
.doc-card-body { padding: .9rem 1rem 1rem; }
.doc-card-title {
    font-size: .88rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .025em;
    color: #1a2540;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    margin-bottom: .3rem;
    line-height: 1.3;
}
.doc-card-desc {
    font-size: .77rem;
    color: #6b7a99;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    margin-bottom: .5rem;
}
.doc-card-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: .73rem;
    color: #8a99b3;
    border-top: 1px solid #f0f2f7;
    padding-top: .5rem;
    margin-top: .4rem;
    gap: .4rem;
}
.doc-card-meta .author {
    font-weight: 600;
    color: #4a5568;
    display: flex;
    align-items: center;
    gap: .3rem;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.doc-card-meta .stats {
    display: flex;
    gap: .6rem;
    flex-shrink: 0;
}
.btn-dl {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: .4rem;
    width: 100%;
    margin-top: .7rem;
    padding: .45rem;
    font-size: .8rem;
    font-weight: 600;
    border-radius: 8px;
    background: #004D2A;
    color: #fff;
    border: none;
    text-decoration: none;
    transition: background .15s;
}
.btn-dl:hover { background: #0f2548; color: #fff; }
.thumb-badge-type {
    position: absolute;
    top: 10px; left: 10px;
    font-size: .68rem;
    font-weight: 700;
    padding: .25em .65em;
    border-radius: 20px;
    text-transform: uppercase;
    letter-spacing: .03em;
    box-shadow: 0 2px 6px rgba(0,0,0,.12);
}
.thumb-badge-sem {
    position: absolute;
    top: 10px; right: 10px;
    font-size: .65rem;
    font-weight: 600;
    padding: .22em .55em;
    border-radius: 20px;
    background: rgba(255,255,255,.88);
    color: #4a5568;
    border: 1px solid rgba(255,255,255,.5);
}
.thumb-skeleton {
    position: absolute;
    inset: 0;
    background: linear-gradient(90deg,#f0f4fa 25%,#dde4f0 50%,#f0f4fa 75%);
    background-size: 200% 100%;
    animation: shimmer 1.4s infinite linear;
}
.thumb-overlay {
    position: absolute;
    inset: 0;
    background: linear-gradient(to top, rgba(10,20,50,.85) 0%, rgba(10,20,50,.3) 60%, transparent 100%);
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    padding: .8rem;
    transform: translateY(100%);
    transition: transform .3s ease-out;
    color: #fff;
}
.doc-card:hover .thumb-overlay { transform: translateY(0); }
.overlay-title {
    font-size: .82rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .03em;
    line-height: 1.3;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    margin-bottom: .35rem;
    opacity: 0;
    transform: translateY(8px);
    transition: opacity .25s .1s, transform .25s .1s;
}
.overlay-meta {
    font-size: .72rem;
    opacity: 0;
    transform: translateY(8px);
    transition: opacity .2s .18s, transform .2s .18s;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: .4rem;
}
.overlay-btn {
    font-size: .72rem;
    font-weight: 700;
    padding: .25rem .8rem;
    border-radius: 20px;
    background: #fff;
    color: #004D2A;
    text-decoration: none;
    opacity: 0;
    transform: translateY(8px);
    transition: opacity .2s .22s, transform .2s .22s;
    border: none;
    cursor: pointer;
}
.doc-card:hover .overlay-title,
.doc-card:hover .overlay-meta,
.doc-card:hover .overlay-btn {
    opacity: 1;
    transform: translateY(0);
}
.badge-new {
    position: absolute;
    top: 10px;
    right: 10px;
    background: #e53935;
    color: #fff;
    font-size: .62rem;
    font-weight: 800;
    padding: .22em .6em;
    border-radius: 20px;
    letter-spacing: .05em;
    animation: pulse-new 1.8s ease-in-out infinite;
    box-shadow: 0 0 0 0 rgba(229,57,53,.6);
    z-index: 11;
}
.ribbon-wrap {
    position: absolute;
    top: 0; left: 0;
    width: 90px; height: 90px;
    overflow: hidden;
    z-index: 10;
    pointer-events: none;
}
.ribbon {
    position: absolute;
    top: 18px; left: -28px;
    width: 110px;
    text-align: center;
    font-size: .6rem;
    font-weight: 800;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: #fff;
    padding: .3em 0;
    transform: rotate(-45deg);
    box-shadow: 0 2px 8px rgba(0,0,0,.2);
}
.ribbon-cours   { background: #006B3C; }
.ribbon-td      { background: #006B3C; }
.ribbon-correction { background: #00695c; }
.ribbon-concours { background: #D4900A; }
.ribbon-examen  { background: #b71c1c; }
.doc-card-body { padding: .9rem 1rem 1rem; }
.doc-card-title {
    font-size: .88rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .025em;
    color: #1a2540;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    margin-bottom: .3rem;
    line-height: 1.3;
}
.doc-card-desc {
    font-size: .77rem;
    color: #6b7a99;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    margin-bottom: .5rem;
}
.doc-card-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: .73rem;
    color: #8a99b3;
    border-top: 1px solid #f0f2f7;
    padding-top: .5rem;
    margin-top: .4rem;
    gap: .4rem;
}
.doc-card-meta .author {
    font-weight: 600;
    color: #4a5568;
    display: flex;
    align-items: center;
    gap: .3rem;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.doc-card-meta .stats {
    display: flex;
    gap: .6rem;
    flex-shrink: 0;
}
.btn-dl {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: .4rem;
    width: 100%;
    margin-top: .7rem;
    padding: .45rem;
    font-size: .8rem;
    font-weight: 600;
    border-radius: 8px;
    background: #004D2A;
    color: #fff;
    border: none;
    text-decoration: none;
    transition: background .15s;
}
.btn-dl:hover { background: #0f2548; color: #fff; }
@keyframes shimmer {
    0%   { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}
@keyframes pulse-new {
    0%   { box-shadow: 0 0 0 0 rgba(229,57,53,.6); }
    60%  { box-shadow: 0 0 0 8px rgba(229,57,53,.0); }
    100% { box-shadow: 0 0 0 0 rgba(229,57,53,.0); }
}
@media (prefers-reduced-motion: reduce) {
    .doc-card-wrap,
    .thumb-media,
    .thumb-overlay,
    .overlay-title,
    .overlay-meta,
    .overlay-btn,
    .badge-new,
    .thumb-skeleton {
        animation: none !important;
        transition: none !important;
        opacity: 1 !important;
        transform: none !important;
    }
}
.doc-type-pills .btn {
    border-radius: 999px;
    font-weight: 700;
}
.doc-author-inline {
    display: inline-flex;
    align-items: center;
    min-width: 0;
    gap: .35rem;
}
.library-shell {
    background:
        radial-gradient(circle at top right, rgba(8,97,54,.08), transparent 24%),
        linear-gradient(180deg, #f6faf8 0%, #ffffff 18%, #f5f8fc 100%);
}
.library-shell-header {
    margin-bottom: 1.75rem;
    border-radius: 30px;
    background:
        linear-gradient(120deg, rgba(8,25,48,.84) 0%, rgba(18,41,74,.8) 44%, rgba(8,97,54,.52) 100%),
        url('assets/images/emsp-ivoire-tech-forum-2025.jpg') center 24% / cover no-repeat;
    box-shadow: var(--emsp-panel-shadow);
}
.library-shell-header::after {
    background: linear-gradient(90deg, rgba(8,19,38,.82) 0%, rgba(8,19,38,.58) 52%, rgba(8,19,38,.18) 100%);
}
.library-filter-card,
.library-empty-card {
    border: 1px solid #dbe5f0;
    border-radius: 24px;
    box-shadow: 0 18px 42px rgba(18,39,74,.07) !important;
    overflow: hidden;
}
.library-filter-card .card-header {
    background: linear-gradient(180deg, #ffffff 0%, #f5faf7 100%) !important;
    border-bottom: 1px solid rgba(8,97,54,.12);
    color: #11335e;
}
.library-filter-card .card-body,
.library-empty-card .card-body {
    background: linear-gradient(180deg, #ffffff 0%, #fbfdfc 100%);
}
.library-filter-card .btn-primary,
.library-filter-card .input-group .btn.btn-primary,
.library-empty-card .btn-primary {
    background: var(--emsp-primary);
    border-color: var(--emsp-primary);
}
.library-filter-card .btn-primary:hover,
.library-empty-card .btn-primary:hover {
    background: var(--emsp-accent);
    border-color: var(--emsp-accent);
}
.library-filter-card .form-select:focus,
.library-filter-card .form-control:focus {
    border-color: rgba(0,85,204,.32);
    box-shadow: 0 0 0 .2rem rgba(0,85,204,.10);
}
.library-filter-card .btn-outline-secondary:hover {
    background: #edf4ff;
    border-color: rgba(0,48,135,.18);
    color: var(--emsp-primary);
}
.doc-card {
    position: relative;
    border-radius: 22px;
    border: 1px solid #dbe5f0;
    box-shadow: 0 16px 38px rgba(18,39,74,.06);
}
.doc-card::before {
    content: '';
    position: absolute;
    inset: 0 0 auto;
    height: 4px;
    background: linear-gradient(90deg, var(--emsp-primary) 0%, rgba(0,85,204,.34) 100%);
    z-index: 2;
}
.doc-card:hover {
    border-color: rgba(0,85,204,.16);
    box-shadow: 0 22px 50px rgba(18,39,74,.12) !important;
}
.doc-thumb {
    background: #eef4fb;
}
.thumb-overlay {
    background: linear-gradient(to top, rgba(8,19,38,.88) 0%, rgba(8,19,38,.52) 42%, rgba(8,19,38,.14) 100%);
}
.overlay-btn {
    background: #fff;
    color: var(--emsp-primary);
}
.doc-card-title {
    color: #163154;
}
.btn-dl {
    background: var(--emsp-primary);
    border-radius: 12px;
}
.btn-dl:hover {
    background: var(--emsp-accent);
}
.pagination .page-link {
    border-radius: 12px;
    color: #163154;
    border-color: #dbe5f0;
}
.pagination .page-item.active .page-link {
    background: var(--emsp-primary);
    border-color: var(--emsp-primary);
}
@media (min-width: 1200px) {
    .library-filter-col {
        position: sticky;
        top: calc(var(--total-top-offset, 0px) + 1.5rem);
        align-self: flex-start;
    }
}

/* EMSP Docs v2 library refinement */
.library-shell {
    background: #f8fafd;
}
.library-shell-header {
    margin-bottom: 1.6rem;
    border-radius: 24px;
    background:
        linear-gradient(118deg, rgba(0,29,86,.94) 0%, rgba(0,48,135,.90) 54%, rgba(0,85,204,.68) 100%),
        url('assets/images/emsp-campus-ceremony.jpg') center 26% / cover no-repeat;
    box-shadow: 0 20px 44px rgba(0,48,135,.10);
}
.library-shell-header::after {
    background: linear-gradient(90deg, rgba(6,16,32,.84) 0%, rgba(6,16,32,.60) 52%, rgba(6,16,32,.18) 100%);
}
.library-shell-header h1 {
    margin-bottom: .6rem;
    font-size: clamp(2rem, 3vw, 2.9rem);
    letter-spacing: -.03em;
}
.library-shell-header p {
    max-width: 40rem;
    color: rgba(255,255,255,.82);
    font-size: .98rem;
}
.library-filter-card,
.library-empty-card {
    border: 1px solid rgba(0,48,135,.08);
    border-radius: 16px;
    box-shadow: var(--shadow-sm), var(--shadow-md) !important;
}
.library-filter-card .card-header {
    background: #fff !important;
    border-bottom: 1px solid rgba(0,48,135,.08);
    color: #102f50;
    padding: 1rem 1.15rem;
}
.library-filter-card .card-body,
.library-empty-card .card-body {
    background: #fff;
}
.library-filter-card .btn-primary,
.library-filter-card .input-group .btn.btn-primary,
.library-empty-card .btn-primary {
    background: var(--emsp-primary);
    border-color: var(--emsp-primary);
}
.library-filter-card .btn-primary:hover,
.library-empty-card .btn-primary:hover {
    background: var(--emsp-accent);
    border-color: var(--emsp-accent);
}
.library-filter-card .form-select:focus,
.library-filter-card .form-control:focus {
    border-color: rgba(0,85,204,.32);
    box-shadow: 0 0 0 .2rem rgba(0,85,204,.10);
}
.library-filter-card .btn-outline-secondary:hover {
    background: #edf4ff;
    border-color: rgba(0,48,135,.18);
    color: var(--emsp-primary);
}
.library-results-bar {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1rem;
    padding: 1rem 1.15rem;
    border: 1px solid rgba(0,48,135,.08);
    border-radius: 16px;
    background: #fff;
    box-shadow: var(--shadow-sm), var(--shadow-md);
}
.library-results-kicker {
    display: inline-flex;
    align-items: center;
    gap: .42rem;
    margin-bottom: .35rem;
    padding: .34rem .72rem;
    border-radius: 999px;
    background: rgba(0,85,204,.08);
    color: var(--emsp-primary);
    font-size: .74rem;
    font-weight: 800;
    letter-spacing: .01em;
    text-transform: none;
}
.library-results-title {
    margin: 0;
    color: #102f50;
    font-size: 1.22rem;
    font-weight: 800;
}
.library-results-text {
    margin: .18rem 0 0;
    color: #64758b;
    font-size: .92rem;
}
.library-active-chip {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    padding: .52rem .8rem;
    border-radius: 999px;
    background: #f5f8fc;
    border: 1px solid rgba(0,48,135,.08);
    color: #4f6278;
    font-size: .84rem;
    font-weight: 700;
}
.library-active-chip strong {
    color: var(--emsp-primary);
}
.doc-type-pills .btn {
    border-radius: 999px;
    font-weight: 700;
    padding: .42rem .78rem;
}
.doc-type-pills .btn-dark,
.doc-type-pills .btn-outline-dark {
    background: #f3f6fb;
    border-color: rgba(0,48,135,.1);
    color: var(--emsp-primary);
}
.doc-type-pills .btn-dark {
    background: var(--emsp-primary);
    border-color: var(--emsp-primary);
    color: #fff;
}
.doc-type-pills .btn-primary,
.doc-type-pills .btn-outline-primary {
    border-color: rgba(24,95,165,.18);
    color: #006B3C;
    background: #fff;
}
.doc-type-pills .btn-primary { background: #E6F1FB; }
.doc-type-pills .btn-success,
.doc-type-pills .btn-outline-success {
    border-color: rgba(59,109,17,.18);
    color: #3B6D11;
    background: #fff;
}
.doc-type-pills .btn-success { background: #EAF3DE; }
.doc-type-pills .btn-info,
.doc-type-pills .btn-outline-info {
    border-color: rgba(99,56,6,.18);
    color: #D4900A;
    background: #fff;
}
.doc-type-pills .btn-info { background: #FAEEDA; }
.doc-type-pills .btn-warning,
.doc-type-pills .btn-outline-warning {
    border-color: rgba(114,36,62,.18);
    color: #72243E;
    background: #fff;
}
.doc-type-pills .btn-warning { background: #FBEAF0; }
.doc-type-pills .btn-danger,
.doc-type-pills .btn-outline-danger {
    border-color: rgba(68,68,65,.16);
    color: #444441;
    background: #fff;
}
.doc-type-pills .btn-danger { background: #F1EFE8; }
.ribbon {
    letter-spacing: .02em;
    text-transform: none;
}
.ribbon-cours { background: #006B3C; }
.ribbon-td { background: #3B6D11; }
.ribbon-correction { background: #D4900A; }
.ribbon-concours { background: #72243E; }
.ribbon-examen { background: #444441; }
.doc-card {
    position: relative;
    border-radius: 16px;
    border: 1px solid rgba(0,48,135,.08);
    box-shadow: var(--shadow-sm), var(--shadow-md);
    background: #fff;
}
.doc-card::before {
    content: '';
    position: absolute;
    inset: 0 0 auto;
    height: 4px;
    background: linear-gradient(90deg, rgba(0,48,135,.92) 0%, rgba(0,85,204,.34) 100%);
}
.doc-card:hover {
    border-color: rgba(0,85,204,.16);
    box-shadow: 0 14px 28px rgba(0,48,135,.08), 0 20px 32px rgba(15,17,23,.04) !important;
}
.doc-thumb {
    height: 196px;
    background: #eef4fb;
}
.thumb-badge-sem {
    background: rgba(255,255,255,.92);
    color: #55657a;
}
.thumb-overlay {
    background: linear-gradient(to top, rgba(8,18,36,.88) 0%, rgba(8,18,36,.34) 60%, transparent 100%);
}
.overlay-btn {
    background: #fff;
    color: var(--emsp-primary);
    border-radius: 999px;
    font-weight: 800;
}
.doc-card-body {
    padding: 1rem 1rem 1.05rem;
}
.doc-card-title {
    color: #102f50;
    font-size: .98rem;
    text-transform: none;
    letter-spacing: -.01em;
    line-height: 1.3;
}
.doc-card-desc {
    font-size: .82rem;
    color: #66778c;
    line-height: 1.55;
}
.doc-card-context {
    display: flex;
    flex-wrap: wrap;
    gap: .4rem;
    margin-top: .1rem;
}
.doc-card-context span {
    display: inline-flex;
    align-items: center;
    gap: .32rem;
    padding: .28rem .58rem;
    border-radius: 999px;
    background: #f5f8fc;
    border: 1px solid rgba(0,48,135,.08);
    color: #627389;
    font-size: .74rem;
}
.doc-card-meta {
    margin-top: .85rem;
    padding-top: .8rem;
    border-top: 1px solid #edf2f7;
}
.doc-card-meta .author {
    color: #465970;
}
.doc-card-meta .stats {
    color: #66778c;
}
.library-card-actions {
    margin-top: .8rem;
}
.library-card-actions .btn {
    min-height: 40px;
    border-radius: 12px;
    font-weight: 700;
}
.pagination .page-link {
    min-width: 40px;
    min-height: 40px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 12px;
    color: #163154;
    border-color: #dbe5f0;
}
.pagination .page-item.active .page-link {
    background: var(--emsp-primary);
    border-color: var(--emsp-primary);
}
@media (max-width: 991.98px) {
    .library-shell-header {
        border-radius: 20px;
    }
    .library-results-bar {
        padding: .95rem 1rem;
        border-radius: 16px;
    }
}
@media (max-width: 575.98px) {
    .library-results-bar {
        align-items: stretch;
    }
    .library-active-chip {
        width: 100%;
        justify-content: center;
    }
}
</style>

<section class="page-header library-shell-header">
    <div class="container">
        <h1>Ressources academiques</h1>
        <p class="mb-0">
            Explore les documents valides de la communaute EMSP, classes par filiere, niveau et matiere.
        </p>
    </div>
</section>

<section class="section-pad library-shell">
<div class="container">

    <?php include __DIR__ . '/message.php'; ?>

    <div class="row g-4">
        <div class="col-lg-3 library-filter-col">
            <div class="card shadow-sm library-filter-card">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-funnel me-2 text-primary"></i>Trouver un document
                </div>
                <div class="card-body">
                    <form method="GET" action="bibliotheque.php">
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Recherche</label>
                            <div class="input-group input-group-sm">
                                <input type="text" class="form-control" name="q"
                                       value="<?= htmlspecialchars($search) ?>"
                                       placeholder="Rechercher un cours, un TD...">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-search"></i>
                                </button>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold d-block">Type de document</label>
                            <div class="d-flex flex-wrap gap-2 doc-type-pills">
                            <?php if ($isAuth): ?>
                                <a href="<?= htmlspecialchars(bibliotheque_query_url(['doc_type' => ''])) ?>" class="btn btn-sm <?= $f_type === '' ? 'btn-dark' : 'btn-outline-dark' ?>">Tous</a>
                                <a href="<?= htmlspecialchars(bibliotheque_query_url(['doc_type' => 'cours'])) ?>" class="btn btn-sm <?= $f_type === 'cours' ? 'btn-primary' : 'btn-outline-primary' ?>"><i class="bi bi-book me-1"></i>Cours</a>
                                <a href="<?= htmlspecialchars(bibliotheque_query_url(['doc_type' => 'td'])) ?>" class="btn btn-sm <?= $f_type === 'td' ? 'btn-success' : 'btn-outline-success' ?>"><i class="bi bi-journal-text me-1"></i>TD</a>
                                <a href="<?= htmlspecialchars(bibliotheque_query_url(['doc_type' => 'correction'])) ?>" class="btn btn-sm <?= $f_type === 'correction' ? 'btn-info text-dark' : 'btn-outline-info' ?>"><i class="bi bi-check2-square me-1"></i>Correction</a>
                                <a href="<?= htmlspecialchars(bibliotheque_query_url(['doc_type' => 'examen'])) ?>" class="btn btn-sm <?= $f_type === 'examen' ? 'btn-danger' : 'btn-outline-danger' ?>"><i class="bi bi-patch-exclamation me-1"></i>Examen</a>
                            <?php endif; ?>
                            <a href="<?= htmlspecialchars(bibliotheque_query_url(['doc_type' => 'concours'])) ?>" class="btn btn-sm <?= $f_type === 'concours' ? 'btn-warning text-dark' : 'btn-outline-warning' ?>"><i class="bi bi-trophy me-1"></i>Concours</a>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Filiere</label>
                            <select class="form-select form-select-sm" name="filiere">
                                <option value="">Toutes</option>
                                <?php while ($f = mysqli_fetch_assoc($filieres)): ?>
                                    <option value="<?= $f['id'] ?>" <?= $f_filiere==$f['id'] ? 'selected':'' ?>>
                                        <?= htmlspecialchars($f['name']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Niveau</label>
                            <select class="form-select form-select-sm" name="licence">
                                <option value="">Tous</option>
                                <?php while ($l = mysqli_fetch_assoc($licences)): ?>
                                    <option value="<?= $l['id'] ?>" <?= $f_licence==$l['id'] ? 'selected':'' ?>>
                                        <?= htmlspecialchars($l['name']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Matiere</label>
                            <select class="form-select form-select-sm" name="matiere">
                                <option value="">Toutes</option>
                                <?php while ($ma = mysqli_fetch_assoc($matieres)): ?>
                                    <option value="<?= $ma['id'] ?>" <?= $f_matiere==$ma['id'] ? 'selected':'' ?>>
                                        <?= htmlspecialchars($ma['name']) ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Semestre</label>
                            <select class="form-select form-select-sm" name="semester">
                                <option value="">Tous</option>
                                <?php foreach (['S1','S2','S3','S4','S5','S6'] as $s): ?>
                                    <option value="<?= $s ?>" <?= $f_semester===$s ? 'selected':'' ?>>
                                        <?= $s ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm w-100 mb-2">
                            <i class="bi bi-funnel me-1"></i>Appliquer
                        </button>
                        <a href="bibliotheque.php" class="btn btn-outline-secondary btn-sm w-100">
                            Reinitialiser
                        </a>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-9">
            <div class="library-results-bar">
                <div>
                    <span class="library-results-kicker"><i class="bi bi-collection"></i>Bibliotheque EMSP</span>
                    <h2 class="library-results-title"><?= $total ?> document<?= $total > 1 ? 's' : '' ?> trouve<?= $total > 1 ? 's' : '' ?></h2>
                    <p class="library-results-text">
                        <?= $hasActiveFilters
                            ? 'Les resultats affichent uniquement les ressources correspondant a tes filtres actuels.'
                            : 'Les ressources les plus recentes et valides sont affichees ici.' ?>
                    </p>
                </div>
                <span class="library-active-chip">
                    <?php if ($hasActiveFilters): ?>
                        <i class="bi bi-sliders"></i>
                        <strong><?= $activeFilterCount ?></strong> filtre<?= $activeFilterCount > 1 ? 's' : '' ?> actif<?= $activeFilterCount > 1 ? 's' : '' ?>
                    <?php else: ?>
                        <i class="bi bi-grid"></i>Vue generale
                    <?php endif; ?>
                </span>
            </div>

            <?php if ($total === 0): ?>
                <div class="card shadow-sm library-empty-card">
                    <div class="card-body text-center py-5 px-4">
                        <i class="bi bi-search fs-1 d-block mb-3 text-primary"></i>
                        <h2 class="h4 fw-bold mb-2">Aucun document trouve</h2>
                        <p class="text-muted mb-4">
                            <?= $hasActiveFilters
                                ? 'Essaie d elargir tes filtres ou de relancer une recherche plus simple pour retrouver des resultats.'
                                : 'La bibliotheque ne contient pas encore de document correspondant a cette vue.' ?>
                        </p>
                        <div class="d-flex flex-wrap justify-content-center gap-2">
                            <a href="bibliotheque.php" class="btn btn-primary">
                                <i class="bi bi-arrow-counterclockwise me-2"></i>Reinitialiser les filtres
                            </a>
                            <a href="upload.php" class="btn btn-outline-secondary">
                                <i class="bi bi-cloud-arrow-up me-2"></i>Deposer un document
                            </a>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="row g-3">
                <?php $i = 0; foreach ($docs as $d):
                    $uploaderSeed = trim((string) ($d['first_name'] ?? '') . ' ' . (string) ($d['last_name'] ?? ''));
                    $ext = strtolower(pathinfo($d['file_path'] ?? '', PATHINFO_EXTENSION));
                    $mime = strtolower($d['mime_type'] ?? '');
                    $thumb_type = 'other';
                    if ($mime === 'application/pdf' || $ext === 'pdf') {
                        $thumb_type = 'pdf';
                    } elseif (in_array($ext, ['jpg','jpeg','png','gif','webp']) || strpos($mime, 'image/') === 0) {
                        $thumb_type = 'image';
                    } elseif (in_array($ext, ['doc','docx']) || strpos($mime, 'word') !== false || strpos($mime, 'officedocument.wordprocessingml') !== false) {
                        $thumb_type = 'word';
                    } elseif (in_array($ext, ['xls','xlsx']) || strpos($mime, 'excel') !== false || strpos($mime, 'spreadsheetml') !== false) {
                        $thumb_type = 'excel';
                    } elseif (in_array($ext, ['ppt','pptx']) || strpos($mime, 'powerpoint') !== false || strpos($mime, 'presentationml') !== false) {
                        $thumb_type = 'ppt';
                    } elseif (in_array($ext, ['zip','rar','7z','tar','gz'])) {
                        $thumb_type = 'zip';
                    } elseif (in_array($ext, ['txt','md','csv'])) {
                        $thumb_type = 'txt';
                    }
                    $preview_url = 'telecharger.php?id=' . intval($d['id']) . '&preview=1';
                    $size_kb = $d['file_size_bytes'] ? round($d['file_size_bytes'] / 1024) : 0;
                    $type_styles = [
                        'cours'      => ['bg'=>'#E6F1FB','color'=>'#006B3C','label'=>'Cours'],
                        'td'         => ['bg'=>'#EAF3DE','color'=>'#3B6D11','label'=>'TD'],
                        'correction' => ['bg'=>'#FAEEDA','color'=>'#D4900A','label'=>'Correction'],
                        'concours'   => ['bg'=>'#FBEAF0','color'=>'#72243E','label'=>'Concours'],
                        'examen'     => ['bg'=>'#F1EFE8','color'=>'#444441','label'=>'Examen'],
                    ];
                    $tc = $type_styles[$d['doc_type']] ?? ['bg'=>'#eceff1','color'=>'#546e7a','label'=>ucfirst($d['doc_type'])];
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
                    $mobileIcon = $type_icons[$d['doc_type']] ?? 'bi-file-earmark-text-fill';
                    $mobileThumbClass = $type_class_map[$d['doc_type']] ?? 'neutral';
                    $docTypeClass = strtolower(trim((string) ($d['doc_type'] ?? 'other')));
                    if (!preg_match('/^[a-z0-9_-]+$/', $docTypeClass)) {
                        $docTypeClass = 'other';
                    }
                    $is_new = (time() - strtotime($d['created_at'])) < (7 * 86400);
                    $delay = min($i * 0.07, 0.5);
                    $mobileAuthor = trim((string) ($d['first_name'] ?? ''));
                    $mobileAuthor .= !empty($d['last_name']) ? ' ' . htmlspecialchars_decode(mb_substr((string) $d['last_name'], 0, 1)) . '.' : '';
                    $mobileContextParts = array_values(array_filter([
                        trim((string) ($d['filiere_label_display'] ?? '')),
                        trim((string) ($d['matiere_name'] ?? '')),
                    ], static function ($value): bool {
                        return $value !== '';
                    }));
                    $mobileContext = !empty($mobileContextParts) ? implode(' - ', $mobileContextParts) : $tc['label'];
                ?>
                    <div class="col-12 col-md-6 col-xl-4 doc-card-wrap emsp-doc-delay" data-emsp-anim-delay="<?= h((string) $delay) ?>">
                        <a class="emsp-doc-row-mobile d-md-none" href="document.php?id=<?= $d['id'] ?>">
                            <span class="emsp-doc-row-thumb emsp-doc-row-thumb--<?= h($mobileThumbClass) ?>">
                                <?php if ($thumb_type === 'pdf'): ?>
                                    <span class="emsp-doc-row-thumb-media emsp-doc-row-thumb-media--pdf">
                                        <canvas
                                            class="pdf-thumb-canvas emsp-doc-row-pdf-canvas"
                                            data-pdf-src="<?= htmlspecialchars($preview_url, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>"
                                            data-doc-title="<?= htmlspecialchars($d['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>"
                                            aria-label="Apercu PDF mobile"
                                        ></canvas>
                                    </span>
                                <?php elseif ($thumb_type === 'image'): ?>
                                    <span class="emsp-doc-row-thumb-media emsp-doc-row-thumb-media--image">
                                        <img
                                            src="<?= htmlspecialchars($preview_url, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>"
                                            alt="<?= htmlspecialchars($d['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>"
                                            loading="lazy"
                                            data-fallback-src="assets/images/video-placeholder.jpg"
                                        >
                                    </span>
                                <?php else: ?>
                                    <span class="emsp-doc-row-thumb-media emsp-doc-row-thumb-media--page">
                                        <span class="emsp-doc-row-thumb-page-line"></span>
                                        <span class="emsp-doc-row-thumb-page-line"></span>
                                        <span class="emsp-doc-row-thumb-page-line"></span>
                                        <span class="emsp-doc-row-thumb-ext"><?= h(strtoupper($ext !== '' ? $ext : 'doc')) ?></span>
                                    </span>
                                <?php endif; ?>
                                <span class="emsp-doc-row-thumb-label"><?= h($tc['label']) ?></span>
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
                                <?php if ($is_new): ?>
                                    <span class="emsp-doc-row-badge">Nouveau</span>
                                <?php endif; ?>
                                <span class="emsp-doc-row-chevron"><i class="bi bi-chevron-right"></i></span>
                            </span>
                        </a>

                        <div class="doc-card h-100 d-none d-md-block" data-doc-id="<?= $d['id'] ?>">
                            <div class="doc-thumb">
                                <?php if (in_array($d['doc_type'], ['concours','examen'], true)): ?>
                                <div class="ribbon-wrap">
                                    <div class="ribbon ribbon-<?= htmlspecialchars($d['doc_type']) ?>">
                                        <?= $tc['label'] ?>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <?php if ($is_new): ?>
                                    <span class="badge-new">NOUVEAU</span>
                                <?php endif; ?>
                                <div class="thumb-media">
                                <?php if ($thumb_type === 'pdf'): ?>
                                    <div class="thumb-illus thumb-pdf-canvas-wrap"
                                        >
                                        <canvas
                                            class="pdf-thumb-canvas"
                                            data-pdf-src="<?= htmlspecialchars($preview_url, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>"
                                            data-doc-title="<?= htmlspecialchars($d['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>"
                                            aria-label="Apercu PDF"
                                        ></canvas>
                                        <div class="doc-lines">
                                            <span></span><span></span><span></span>
                                        </div>
                                        <span class="ext-label">.PDF</span>
                                    </div>
                                <?php elseif ($thumb_type === 'image'): ?>
                                    <img class="thumb-img"
                                         src="<?= htmlspecialchars($preview_url) ?>"
                                         alt="<?= htmlspecialchars($d['title']) ?>"
                                         loading="lazy"
                                         data-fallback-src="assets/images/video-placeholder.jpg">
                                <?php elseif ($thumb_type === 'word'): ?>
                                    <div class="thumb-illus"
                                        >
                                        <i class="bi bi-file-word-fill"></i>
                                        <div class="doc-lines">
                                            <span></span><span></span><span></span>
                                        </div>
                                        <span class="ext-label">
                                            .<?= strtoupper($ext) ?>
                                        </span>
                                    </div>
                                <?php elseif ($thumb_type === 'excel'): ?>
                                    <div class="thumb-illus"
                                        >
                                        <i class="bi bi-file-excel-fill"></i>
                                        <div class="doc-grid-sim">
                                            <div></div><div></div><div></div>
                                            <div></div><div></div><div></div>
                                            <div></div><div></div><div></div>
                                        </div>
                                        <span class="ext-label">
                                            .<?= strtoupper($ext) ?>
                                        </span>
                                    </div>
                                <?php elseif ($thumb_type === 'ppt'): ?>
                                    <div class="thumb-illus"
                                        >
                                        <i class="bi bi-file-ppt-fill"></i>
                                        <div>
                                            <span>SLIDE</span>
                                        </div>
                                        <span class="ext-label">
                                            .<?= strtoupper($ext) ?>
                                        </span>
                                    </div>
                                <?php elseif ($thumb_type === 'zip'): ?>
                                    <div class="thumb-illus"
                                        >
                                        <i class="bi bi-file-zip-fill"></i>
                                        <div>
                                            <i class="bi bi-file-earmark"></i>
                                            <i class="bi bi-file-earmark"></i>
                                            <i class="bi bi-file-earmark"></i>
                                        </div>
                                        <span class="ext-label">
                                            .<?= strtoupper($ext) ?>
                                        </span>
                                    </div>
                                <?php elseif ($thumb_type === 'txt'): ?>
                                    <div class="thumb-illus"
                                        >
                                        <i class="bi bi-file-text-fill"></i>
                                        <div class="doc-lines">
                                            <span></span><span></span><span></span>
                                        </div>
                                        <span class="ext-label">
                                            .<?= strtoupper($ext) ?>
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <div class="thumb-illus"
                                        >
                                        <i class="bi bi-file-earmark-fill"></i>
                                        <span class="ext-label">
                                            <?= $ext !== '' ? '.' . strtoupper($ext) : 'FICHIER' ?>
                                        </span>
                                    </div>
                                <?php endif; ?>

                                <?php if (!in_array($d['doc_type'], ['concours','examen'], true)): ?>
                                <span class="thumb-badge-type thumb-badge-type-<?= h($docTypeClass) ?>">
                                    <?= $tc['label'] ?>
                                </span>
                                <?php endif; ?>
                                <?php if (!empty($d['semester'])): ?>
                                <span class="thumb-badge-sem"><?= htmlspecialchars($d['semester']) ?></span>
                                <?php endif; ?>

                                <div class="thumb-overlay">
                                    <div class="overlay-title"><?= htmlspecialchars($d['title']) ?></div>
                                    <div class="overlay-meta">
                                        <span class="doc-author-inline"><?= emsp_dicebear_avatar($uploaderSeed, 26) ?><span><?= htmlspecialchars($d['first_name']) ?></span></span>
                                        <span><i class="bi bi-download me-1"></i><?= intval($d['download_count']) ?></span>
                                    </div>
                                    <a class="overlay-btn text-decoration-none" href="document.php?id=<?= $d['id'] ?>">Ouvrir</a>
                                </div>
                                </div><!-- thumb-media -->
                            </div>

                            <div class="doc-card-body">
                                <div class="doc-card-title"><?= htmlspecialchars($d['title']) ?></div>
                                <?php if (!empty($d['description'])): ?>
                                    <div class="doc-card-desc"><?= htmlspecialchars($d['description']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($d['filiere_label_display']) || !empty($d['matiere_name'])): ?>
                                    <div class="doc-card-context">
                                        <?php if (!empty($d['filiere_label_display'])): ?>
                                            <span><i class="bi bi-diagram-3"></i><?= htmlspecialchars($d['filiere_label_display'] ?? '') ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($d['matiere_name'])): ?>
                                            <span><i class="bi bi-journal-text"></i><?= htmlspecialchars($d['matiere_name']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="doc-card-meta">
                                    <div class="author">
                                        <?= emsp_dicebear_avatar($uploaderSeed, 30) ?>
                                        <span class="text-truncate"><?= htmlspecialchars($d['first_name']) ?> <?= htmlspecialchars(mb_substr($d['last_name'],0,1)) ?>.</span>
                                    </div>
                                    <div class="stats">
                                        <span><i class="bi bi-download me-1"></i><?= intval($d['download_count']) ?></span>
                                        <span><i class="bi bi-file-earmark me-1"></i><?= $size_kb ?> Ko</span>
                                    </div>
                                </div>
                                <div class="d-flex gap-2 mt-2 library-card-actions">
                                    <a href="document.php?id=<?= $d['id'] ?>" class="btn btn-sm btn-outline-primary flex-grow-1">
                                        <i class="bi bi-eye me-1"></i>Ouvrir
                                    </a>
                                    <form method="post"
                                      action="telecharger.php?id=<?= $d['id'] ?>&download=1"
                                      class="m-0 form-download">
                                        <?php csrf_input(); ?>
                                        <button type="submit" class="btn btn-sm btn-primary" title="Telecharger">
                                            <i class="bi bi-download"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php $i++; endforeach; ?>
                </div>

                <?php if ($total_pages > 1): ?>
                <nav class="mt-4">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?= $page_num <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= pagination_url($page_num - 1) ?>">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        </li>
                        <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                            <li class="page-item <?= $p === $page_num ? 'active' : '' ?>">
                                <a class="page-link" href="<?= pagination_url($p) ?>"><?= $p ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $page_num >= $total_pages ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= pagination_url($page_num + 1) ?>">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                    </ul>
                </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
</section>

<script>
(function () {
    function loadScriptOnce(src) {
        return new Promise((resolve, reject) => {
            const existing = document.querySelector('script[data-emsp-src="' + src + '"]');
            if (existing) {
                if (existing.dataset.loaded === '1') {
                    resolve();
                    return;
                }
                existing.addEventListener('load', () => resolve(), { once: true });
                existing.addEventListener('error', () => reject(new Error('load failed')), { once: true });
                return;
            }
            const script = document.createElement('script');
            script.src = src;
            script.async = true;
            script.dataset.emspSrc = src;
            script.onload = () => {
                script.dataset.loaded = '1';
                resolve();
            };
            script.onerror = () => reject(new Error('load failed'));
            document.head.appendChild(script);
        });
    }

    const pdfThumbs = Array.from(document.querySelectorAll('.pdf-thumb-canvas'));
    const renderedThumbs = new WeakSet();

    async function renderPdfThumb(canvas) {
        if (!canvas || renderedThumbs.has(canvas)) return;
        renderedThumbs.add(canvas);

        const src = canvas.dataset.pdfSrc || '';
        if (!src) return;

        try {
            if (typeof window.pdfjsLib === 'undefined') {
                await loadScriptOnce('assets/js/pdf.min.js');
            }
            if (typeof window.pdfjsLib === 'undefined') {
                return;
            }
            if (!window.pdfjsLib.GlobalWorkerOptions.workerSrc) {
                window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'assets/js/pdf.worker.min.js';
            }

            const pdf = await window.pdfjsLib.getDocument(src).promise;
            const page = await pdf.getPage(1);
            const viewport = page.getViewport({ scale: 1 });
            const scale = Math.max(0.6, 240 / Math.max(viewport.width, 1));
            const scaledViewport = page.getViewport({ scale });
            const ratio = window.devicePixelRatio || 1;
            const ctx = canvas.getContext('2d');
            if (!ctx) return;

            canvas.width = scaledViewport.width * ratio;
            canvas.height = scaledViewport.height * ratio;
            canvas.style.width = '100%';
            canvas.style.height = '100%';
            ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, scaledViewport.width, scaledViewport.height);

            await page.render({
                canvasContext: ctx,
                viewport: scaledViewport
            }).promise;
        } catch (error) {
            canvas.remove();
        }
    }

    if ('IntersectionObserver' in window && pdfThumbs.length > 0) {
        const pdfObserver = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                renderPdfThumb(entry.target);
                pdfObserver.unobserve(entry.target);
            });
        }, { rootMargin: '160px 0px' });
        pdfThumbs.forEach((canvas) => pdfObserver.observe(canvas));
    } else {
        pdfThumbs.forEach((canvas) => renderPdfThumb(canvas));
    }

    // Reveal + counters on view
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


