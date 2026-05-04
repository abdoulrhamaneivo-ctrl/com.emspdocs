<?php
include_once __DIR__ . '/includes/bootstrap.php';
include_once __DIR__ . '/admin/config/dbcon.php';
include_once __DIR__ . '/includes/content-helpers.php';

function emsp_institution_fix_text(string $text): string
{
    return function_exists('emsp_fix_mojibake') ? emsp_fix_mojibake($text) : $text;
}

function emsp_sanitize_html(string $html): string
{
    $html = emsp_institution_fix_text($html);
    $decoded = htmlspecialchars_decode($html, ENT_QUOTES);
    $decoded = str_replace(
        ['../uploads/', '..\\uploads\\', '../assets/', '..\\assets\\'],
        ['uploads/', 'uploads/', 'assets/', 'assets/'],
        $decoded
    );
    $decoded = preg_replace_callback('/<img\b[^>]*\bsrc=(["\'])(.*?)\1[^>]*>/i', function (array $matches): string {
        $src = html_entity_decode((string) ($matches[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return emsp_institution_media_src($src) !== '' ? $matches[0] : '';
    }, $decoded) ?? $decoded;
    $allowed = '<p><br><h1><h2><h3><h4><h5><h6>'
             . '<ul><ol><li><strong><em><b><i><u><a>'
             . '<img><div><span><figure><figcaption>'
             . '<blockquote><hr><pre><code>'
             . '<table><thead><tbody><tr><th><td>'
             . '<sup><sub><small><mark>';
    return strip_tags($decoded, $allowed);
}

function emsp_institution_media_src(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }

    if (emsp_is_external_url($path) || str_starts_with($path, 'data:') || str_starts_with($path, 'blob:')) {
        return $path;
    }

    $src = ltrim(str_replace('\\', '/', $path), '/');
    $localPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $src);

    return is_file($localPath) ? $src : '';
}

// 1) Blocs dynamiques
$blocs = [];
$use_legacy = true;
$tableExists = false;
$check = mysqli_query($con, "SHOW TABLES LIKE 'institution_blocs'");
if ($check && mysqli_num_rows($check) > 0) {
    $tableExists = true;
}

if ($tableExists) {
    $r = mysqli_query($con, "SELECT * FROM institution_blocs WHERE visible=1 ORDER BY ordre ASC, id ASC");
    while ($row = mysqli_fetch_assoc($r)) {
        $blocs[] = $row;
    }
    if (!empty($blocs)) {
        $use_legacy = false;
    }
}

// 2) Fallback legacy
$contentRows = [];
$bySection = [];
$byKey = [];
if ($use_legacy) {
    $s = mysqli_prepare($con, "SELECT cle, section, label, valeur, ordre FROM institution_content ORDER BY section, ordre, cle");
    if ($s) {
        mysqli_stmt_execute($s);
        $contentRows = emsp_stmt_fetch_all($s);
        foreach ($contentRows as $row) {
        $section = trim((string) ($row['section'] ?? ''));
        $key = trim((string) ($row['cle'] ?? ''));
            if ($section !== '') {
                if (!isset($bySection[$section])) {
                    $bySection[$section] = [];
                }
                $bySection[$section][] = $row;
            }
            if ($key !== '') {
                $byKey[$key] = $row;
            }
        }
        mysqli_stmt_close($s);
    }
}

function emsp_section_text(array $rows): string
{
    $parts = [];
    foreach ($rows as $row) {
        $val = trim(emsp_institution_fix_text((string) ($row['valeur'] ?? '')));
        if ($val !== '') {
            $parts[] = $val;
        }
    }
    return implode("\n\n", $parts);
}

function emsp_value_by_keys(array $byKey, array $keys): string
{
    foreach ($keys as $k) {
        if (!isset($byKey[$k])) continue;
        $val = trim(emsp_institution_fix_text((string) ($byKey[$k]['valeur'] ?? '')));
        if ($val !== '') return $val;
    }
    return '';
}

$aboutText = emsp_section_text($bySection['a-propos'] ?? $bySection['a_propos'] ?? []);
$missionText = emsp_section_text($bySection['mission'] ?? []);
$visionText = emsp_section_text($bySection['vision'] ?? []);
$valuesText = emsp_section_text($bySection['valeurs'] ?? $bySection['valeur'] ?? []);
$perspectivesText = emsp_section_text($bySection['perspectives'] ?? []);
$motDGText = emsp_section_text($bySection['mot-dg'] ?? $bySection['mot_dg'] ?? []);
$motDEText = emsp_section_text($bySection['mot-de'] ?? $bySection['mot_de'] ?? []);

$motDGName = emsp_value_by_keys($byKey, ['mot_dg_nom', 'mot-dg-nom', 'directeur_general_nom']);
$motDEName = emsp_value_by_keys($byKey, ['mot_de_nom', 'mot-de-nom', 'directeur_etudes_nom']);
$motDGPhoto = emsp_value_by_keys($byKey, ['mot_dg_photo', 'directeur_photo', 'dg_photo']);
$motDEPhoto = emsp_value_by_keys($byKey, ['mot_de_photo', 'directeur_etudes_photo', 'de_photo']);
$motDGTitre = emsp_value_by_keys($byKey, ['mot_dg_titre', 'dg_titre']) ?: 'Directeur GÃ©nÃ©ral';
$motDETitre = emsp_value_by_keys($byKey, ['mot_de_titre', 'de_titre']) ?: 'Directeur des Ã‰tudes';

$activeStudents = 0;
$approvedDocs = 0;
$countries = null;

$s = mysqli_prepare($con, "SELECT COUNT(*) FROM users WHERE role='etudiant' AND status='active'");
if ($s) {
    mysqli_stmt_execute($s);
    mysqli_stmt_bind_result($s, $activeStudents);
    mysqli_stmt_fetch($s);
    mysqli_stmt_close($s);
}

$s = mysqli_prepare($con, "SELECT COUNT(*) FROM documents WHERE status='approved'");
if ($s) {
    mysqli_stmt_execute($s);
    mysqli_stmt_bind_result($s, $approvedDocs);
    mysqli_stmt_fetch($s);
    mysqli_stmt_close($s);
}

$countriesRaw = emsp_value_by_keys($byKey, ['stats_pays', 'nombre_pays', 'pays_membres']);
if ($countriesRaw !== '' && is_numeric($countriesRaw)) {
    $countries = intval($countriesRaw);
}

$page_title = "L Institution";
include __DIR__ . '/includes/header.php';
?>

<style>
.reveal-init {
    opacity: 0;
    transform: translateY(28px);
    transition: opacity .6s ease, transform .6s ease;
}
.reveal-init.is-visible {
    opacity: 1;
    transform: translateY(0);
}
.institution-stat {
    border-radius: 16px;
    border: 1px solid rgba(0,48,135,.1);
    background: #fff;
    box-shadow: 0 8px 24px rgba(0,0,0,.04);
    padding: 1.1rem;
    text-align: center;
}
.institution-stat .stat-number {
    font-size: 2rem;
    font-weight: 800;
    color: #003087;
}
.institution-stat .stat-label {
    color: #6b7a90;
    font-size: .85rem;
}
.institution-image--full img {
    width: 100%; max-height: 500px; object-fit: cover;
}
.institution-image--center { text-align: center; }
.institution-image--center img { max-width: 600px; width: 100%; }
.institution-image--left img { float: left; margin: 0 1.5rem 1rem 0; max-width: 45%; }
.institution-image--right img { float: right; margin: 0 0 1rem 1.5rem; max-width: 45%; }
.institution-image figcaption {
    font-size: .85rem; color: #64748b;
    text-align: center; margin-top: .5rem; font-style: italic;
}
.institution-quote {
    border: 1px solid rgba(0,48,135,.12);
    padding: 1.1rem 1.4rem;
    background: #f8f9fc;
    border-radius: 16px;
    margin: 1.6rem 0;
}
.institution-quote p {
    font-size: 1.15rem; font-style: italic; margin-bottom: .5rem;
}
.institution-stat { background: #fff; border-radius: 16px; }
.institution-stat .stat-number { line-height: 1.1; }
.clearfix::after { content:''; display:table; clear:both; }
.card-dg { border-left: 4px solid #003087 !important; border-radius: 18px; }
.card-de { border-left: 4px solid #1A7F37 !important; border-radius: 18px; }
.dg-photo,
.de-photo {
    width: 120px;
    height: 120px;
    object-fit: cover;
    border: 4px solid #003087;
}
.de-photo {
    border-color: #1A7F37;
}
.dg-avatar-placeholder,
.de-avatar-placeholder {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto;
    font-size: 3rem;
    color: rgba(255,255,255,0.7);
}
.dg-avatar-placeholder {
    background: linear-gradient(135deg, #003087, #1A7F37);
}
.de-avatar-placeholder {
    background: linear-gradient(135deg, #1A7F37, #0f6a30);
}
.dg-quote-mark,
.de-quote-mark {
    font-size: 5rem;
    line-height: 0.5;
    opacity: 0.15;
    font-family: Georgia, serif;
    margin-bottom: 0.5rem;
}
.dg-quote-mark { color: #003087; }
.de-quote-mark { color: #1A7F37; }
.dg-text,
.de-text {
    font-style: italic;
    line-height: 1.8;
    color: #2d3748;
}
.text-emsp { color: #003087; }
.text-emsp-green { color: #1A7F37; }

.institution-panel-grid {
    display: grid;
    grid-template-columns: repeat(12, minmax(0, 1fr));
    gap: 1rem;
    margin-bottom: 2rem;
}
.institution-panel {
    border: 1px solid rgba(0,48,135,.1);
    border-radius: 18px;
    background: #fff;
    box-shadow: 0 10px 26px rgba(0,0,0,.04);
    padding: 1.1rem 1.2rem;
    min-height: 100%;
}
.institution-panel h3 {
    font-size: 1.05rem;
    font-weight: 700;
    margin-bottom: .6rem;
}
.institution-panel p {
    color: #526178;
    line-height: 1.6;
}
.institution-panel--wide { grid-column: span 7; }
.institution-panel--mid { grid-column: span 5; }
.institution-panel--half { grid-column: span 6; }
.institution-panel--full { grid-column: span 12; }
.institution-panel--accent {
    background: linear-gradient(160deg, rgba(0,48,135,.08), rgba(0,85,204,.04));
}
@media (max-width: 991px) {
    .institution-panel--wide,
    .institution-panel--mid,
    .institution-panel--half,
    .institution-panel--full {
        grid-column: span 12;
    }
}
</style>

<section class="page-header">
    <div class="container">
        <h1>L Institution</h1>
        <p class="mb-0">Presentation dynamique de l EMSP.</p>
        <?php if (!empty($_SESSION['auth_role']) && $_SESSION['auth_role'] === 'admin'): ?>
        <a class="btn btn-emsp btn-sm mt-3" href="admin/edit-institution.php">
            <i class="bi bi-pencil-square me-1"></i>Modifier le contenu institutionnel
        </a>
        <?php endif; ?>
    </div>
</section>

<section class="section-pad">
    <div class="container">
        <?php if (!$use_legacy): ?>
            <?php foreach ($blocs as $bloc): ?>
                <?php
                $cfg = [];
                if (!empty($bloc['config_json'])) {
                    $cfg = json_decode(emsp_institution_fix_text((string)$bloc['config_json']), true);
                    if (!is_array($cfg)) { $cfg = []; }
                }
                $type = (string)($bloc['bloc_type'] ?? 'texte');
                ?>

                <?php if ($type === 'texte'): ?>
                    <div class="reveal-init mb-5">
                        <?= emsp_sanitize_html((string)($bloc['contenu'] ?? '')) ?>
                    </div>

                <?php elseif ($type === 'image'): ?>
                    <?php
                    $align = $cfg['align'] ?? 'full';
                    $align = in_array($align, ['full','center','left','right'], true) ? $align : 'full';
                    $legende = emsp_institution_fix_text((string)($cfg['legende'] ?? ''));
                    $img = emsp_institution_media_src((string)($bloc['image_path'] ?? ''));
                    ?>
                    <?php if ($img !== ''): ?>
                    <div class="reveal-init mb-4 clearfix">
                        <figure class="institution-image institution-image--<?= htmlspecialchars($align) ?>">
                            <img src="<?= htmlspecialchars($img) ?>" alt="<?= htmlspecialchars($legende) ?>" class="img-fluid">
                            <?php if ($legende !== ''): ?>
                            <figcaption><?= htmlspecialchars($legende) ?></figcaption>
                            <?php endif; ?>
                        </figure>
                    </div>
                    <?php endif; ?>

                <?php elseif ($type === 'galerie'): ?>
                    <?php
                    $cols = intval($cfg['cols'] ?? 3);
                    if (!in_array($cols, [2,3,4], true)) { $cols = 3; }
                    $colClass = 'col-' . (12 / $cols);
                    $images = is_array($cfg['images'] ?? null) ? $cfg['images'] : [];
                    ?>
                    <?php if (!empty($images)): ?>
                    <div class="reveal-init row g-2 mb-4">
                        <?php foreach ($images as $img): ?>
                        <?php $img = emsp_institution_media_src((string) $img); ?>
                        <?php if ($img === '') { continue; } ?>
                        <div class="<?= $colClass ?>">
                            <img src="<?= htmlspecialchars((string)$img) ?>" class="img-fluid rounded w-100 emsp-institution-media" alt="">
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                <?php elseif ($type === 'stats'): ?>
                    <?php $items = is_array($cfg['items'] ?? null) ? $cfg['items'] : []; ?>
                    <?php if (!empty($items)): ?>
                    <div class="row g-3 mb-5 reveal-init">
                        <?php foreach ($items as $item): ?>
                        <?php
                        $numRaw = (string)($item['number'] ?? '');
                        $numVal = (int)preg_replace('/[^0-9]/', '', $numRaw);
                        ?>
                        <div class="col-md-4">
                            <div class="institution-stat text-center p-3">
                                <div class="stat-number display-4 fw-bold text-primary" data-counter="<?= $numVal ?>">0</div>
                                <div class="stat-label text-muted"><?= htmlspecialchars((string)($item['label'] ?? '')) ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                <?php elseif ($type === 'citation'): ?>
                    <?php $texte = emsp_institution_fix_text((string)($cfg['texte'] ?? '')); ?>
                    <?php $auteur = emsp_institution_fix_text((string)($cfg['auteur'] ?? '')); ?>
                    <?php if ($texte !== ''): ?>
                    <blockquote class="reveal-init institution-quote mb-4">
                        <p><?= htmlspecialchars($texte) ?></p>
                        <?php if ($auteur !== ''): ?>
                        <footer class="blockquote-footer"><?= htmlspecialchars($auteur) ?></footer>
                        <?php endif; ?>
                    </blockquote>
                    <?php endif; ?>

                <?php elseif ($type === 'colonnes'): ?>
                    <?php
                    $nbCols = intval($cfg['cols'] ?? 2);
                    if ($nbCols !== 3) { $nbCols = 2; }
                    $colClass2 = $nbCols === 3 ? 'col-md-4' : 'col-md-6';
                    $contenuCols = json_decode(emsp_institution_fix_text((string)($bloc['contenu'] ?? '')), true);
                    if (!is_array($contenuCols)) { $contenuCols = []; }
                    ?>
                    <div class="reveal-init row g-4 mb-4">
                        <?php for ($i=0; $i<$nbCols; $i++): ?>
                        <div class="<?= $colClass2 ?>">
                            <?= emsp_sanitize_html((string)($contenuCols[$i] ?? '')) ?>
                        </div>
                        <?php endfor; ?>
                    </div>

                <?php elseif ($type === 'separateur'): ?>
                    <?php
                    $sepStyle = in_array($cfg['style'] ?? '', ['solid','dashed','dotted'], true) ? $cfg['style'] : 'solid';
                    $sepColor = preg_replace('/[^#a-fA-F0-9]/', '', (string)($cfg['color'] ?? '#e2e8f0'));
                    if ($sepColor === '') { $sepColor = '#e2e8f0'; }
                    ?>
                    <hr class="reveal-init my-5 emsp-dynamic-separator"
                        data-emsp-border-style="<?= htmlspecialchars($sepStyle, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>"
                        data-emsp-border-color="<?= htmlspecialchars($sepColor, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>"
                        data-emsp-border-width="2px">
                <?php endif; ?>
            <?php endforeach; ?>
        <?php else: ?>
            <?php if ($aboutText !== ''): ?>
            <div class="reveal-init mb-5" id="a-propos">
                <h2 class="section-title text-uppercase">A propos</h2>
                <div class="mb-0"><?= emsp_sanitize_html($aboutText) ?></div>
            </div>
            <?php endif; ?>

            <div class="row g-3 mb-5 reveal-init">
                <div class="col-md-4">
                    <div class="institution-stat">
                        <div class="stat-number" data-counter="<?= max($activeStudents, 0) ?>">0</div>
                        <div class="stat-label">Etudiants actifs</div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="institution-stat">
                        <div class="stat-number" data-counter="<?= max($approvedDocs, 0) ?>">0</div>
                        <div class="stat-label">Documents approuves</div>
                    </div>
                </div>
                <?php if ($countries !== null): ?>
                <div class="col-md-4">
                    <div class="institution-stat">
                        <div class="stat-number" data-counter="<?= max($countries, 0) ?>">0</div>
                        <div class="stat-label">Pays membres</div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="institution-panel-grid reveal-init">
                <?php if ($missionText !== ''): ?>
                <div class="institution-panel institution-panel--wide" id="mission">
                    <h3>Mission</h3>
                    <div class="mb-0"><?= emsp_sanitize_html($missionText) ?></div>
                </div>
                <?php endif; ?>
                <?php if ($visionText !== ''): ?>
                <div class="institution-panel institution-panel--mid institution-panel--accent" id="vision">
                    <h3>Vision</h3>
                    <div class="mb-0"><?= emsp_sanitize_html($visionText) ?></div>
                </div>
                <?php endif; ?>
                <?php if ($valuesText !== ''): ?>
                <div class="institution-panel institution-panel--half" id="valeurs">
                    <h3>Valeurs</h3>
                    <div class="mb-0"><?= emsp_sanitize_html($valuesText) ?></div>
                </div>
                <?php endif; ?>
                <?php if ($perspectivesText !== ''): ?>
                <div class="institution-panel institution-panel--half" id="perspectives">
                    <h3>Perspectives</h3>
                    <div class="mb-0"><?= emsp_sanitize_html($perspectivesText) ?></div>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($motDGText !== ''): ?>
            <div class="card shadow-sm border-0 mb-4 reveal-init card-dg" id="mot-dg">
                <div class="card-body p-4">
                    <div class="row align-items-center g-4">
                        <div class="col-md-3 text-center">
                            <?php if (!empty($motDGPhoto)): ?>
                                <img src="<?= htmlspecialchars(emsp_media_src($motDGPhoto)) ?>"
                                     alt="<?= htmlspecialchars($motDGName !== '' ? $motDGName : 'Directeur GÃ©nÃ©ral') ?>"
                                     class="dg-photo rounded-circle shadow">
                            <?php else: ?>
                                <div class="dg-avatar-placeholder">
                                    <i class="bi bi-person-fill"></i>
                                </div>
                            <?php endif; ?>
                            <div class="mt-2 fw-bold text-emsp"><?= htmlspecialchars($motDGName !== '' ? $motDGName : 'Direction GÃ©nÃ©rale') ?></div>
                            <div class="text-muted small"><?= htmlspecialchars($motDGTitre) ?></div>
                        </div>
                        <div class="col-md-9">
                            <div class="dg-quote-mark">"</div>
                            <div class="dg-text"><?= emsp_sanitize_html($motDGText) ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($motDEText !== ''): ?>
            <div class="card shadow-sm border-0 mb-4 reveal-init card-de" id="mot-de">
                <div class="card-body p-4">
                    <div class="row align-items-center g-4">
                        <div class="col-md-3 text-center">
                            <?php if (!empty($motDEPhoto)): ?>
                                <img src="<?= htmlspecialchars(emsp_media_src($motDEPhoto)) ?>"
                                     alt="<?= htmlspecialchars($motDEName !== '' ? $motDEName : 'Directeur des Ã‰tudes') ?>"
                                     class="de-photo rounded-circle shadow">
                            <?php else: ?>
                                <div class="de-avatar-placeholder">
                                    <i class="bi bi-person-fill"></i>
                                </div>
                            <?php endif; ?>
                            <div class="mt-2 fw-bold text-emsp-green"><?= htmlspecialchars($motDEName !== '' ? $motDEName : 'Direction des Ã‰tudes') ?></div>
                            <div class="text-muted small"><?= htmlspecialchars($motDETitre) ?></div>
                        </div>
                        <div class="col-md-9">
                            <div class="de-quote-mark">"</div>
                            <div class="de-text"><?= emsp_sanitize_html($motDEText) ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($perspectivesText !== '' && $valuesText === '' && $missionText === '' && $visionText === ''): ?>
            <div class="institution-panel institution-panel--full reveal-init" id="perspectives">
                <h3>Perspectives</h3>
                <div class="mb-0"><?= emsp_sanitize_html($perspectivesText) ?></div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<script>
const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
        if (entry.isIntersecting) {
            entry.target.classList.add('is-visible');
        }
    });
}, { threshold: 0.15 });

document.querySelectorAll('.reveal-init').forEach((el) => observer.observe(el));

function animateCounter(el) {
    const target = parseInt(el.dataset.counter || '0', 10);
    const duration = 1200;
    const start = performance.now();
    function tick(now) {
        const progress = Math.min((now - start) / duration, 1);
        const value = Math.floor(target * progress);
        el.textContent = value.toLocaleString('fr-FR');
        if (progress < 1) requestAnimationFrame(tick);
    }
    requestAnimationFrame(tick);
}

const counterObserver = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
        if (entry.isIntersecting && !entry.target.dataset.animated) {
            entry.target.dataset.animated = '1';
            animateCounter(entry.target);
        }
    });
}, { threshold: 0.4 });

document.querySelectorAll('[data-counter]').forEach((el) => counterObserver.observe(el));
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>

