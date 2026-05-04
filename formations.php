<?php
include_once __DIR__ . '/includes/bootstrap.php';
include_once __DIR__ . '/admin/config/dbcon.php';
include_once __DIR__ . '/includes/formations-helpers.php';

function emsp_formations_fix_text(string $text): string
{
    return function_exists('emsp_fix_mojibake') ? emsp_fix_mojibake($text) : $text;
}

function emsp_formation_key(string $name): string
{
    $value = mb_strtolower(trim(emsp_formations_fix_text($name)));
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) {
            $value = $converted;
        }
    }
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
    $value = preg_replace('/\s+/', ' ', (string) $value);
    return trim((string) $value);
}

$editorialEnabled = emsp_formations_editorial_columns_present($con);
$query = $editorialEnabled
    ? "SELECT id, name, summary, description_html, cover_image_path FROM filieres WHERE status='active' ORDER BY name"
    : "SELECT id, name FROM filieres WHERE status='active' ORDER BY name";

$filieres = [];
if ($stmt = mysqli_prepare($con, $query)) {
    mysqli_stmt_execute($stmt);
    $filieres = emsp_stmt_fetch_all($stmt);
    mysqli_stmt_close($stmt);
}

$filieresMain = [];
$troncCommuns = [];
foreach ($filieres as $filiere) {
    $normalized = emsp_formation_key((string) ($filiere['name'] ?? ''));
    if (preg_match('/\btronc?\s+commun\b/u', $normalized)) {
        $troncCommuns[] = $filiere;
    } else {
        $filieresMain[] = $filiere;
    }
}

$licences = [];
if ($stmt = mysqli_prepare($con, "SELECT id, name FROM licences WHERE status='active' ORDER BY name")) {
    mysqli_stmt_execute($stmt);
    $licences = emsp_stmt_fetch_all($stmt);
    mysqli_stmt_close($stmt);
}

$licenceMap = [];
if ($stmt = mysqli_prepare(
    $con,
    "SELECT lf.filiere_id, l.name
     FROM licence_filieres lf
     JOIN licences l ON l.id = lf.licence_id
     WHERE l.status='active'
     ORDER BY l.name"
)) {
    mysqli_stmt_execute($stmt);
    foreach (emsp_stmt_fetch_all($stmt) as $row) {
        $filiereId = (int) ($row['filiere_id'] ?? 0);
        $licenceName = trim((string) ($row['name'] ?? ''));
        if ($filiereId > 0 && $licenceName !== '') {
            $licenceMap[$filiereId][] = $licenceName;
        }
    }
    mysqli_stmt_close($stmt);
}

$page_title = 'Formations';
include __DIR__ . '/includes/header.php';
?>

<style>
.formations-shell {
    padding-block: 2.2rem 1rem;
}
.formations-hero {
    border: 1px solid rgba(0,48,135,.12);
    border-radius: 22px;
    padding: 2rem;
    background:
        radial-gradient(circle at top right, rgba(8, 97, 54, 0.12), transparent 24%),
        linear-gradient(135deg, #004D2A 0%, #006B3C 60%, #008F52 100%);
    color: #fff;
}
.formations-hero h1 {
    margin-bottom: .8rem;
    font-size: clamp(2rem, 4vw, 3.2rem);
}
.formations-hero p {
    max-width: 50rem;
    margin: 0;
    color: rgba(255, 255, 255, 0.82);
    font-size: 1.02rem;
    line-height: 1.8;
}
.formations-hero-meta,
.formations-licence-pills,
.formations-tronc-list {
    display: flex;
    flex-wrap: wrap;
    gap: .75rem;
}
.formations-hero-meta {
    margin-top: 1.35rem;
}
.formations-hero-pill,
.formations-licence-pill,
.formations-tronc-link {
    display: inline-flex;
    align-items: center;
    gap: .45rem;
    padding: .7rem 1rem;
    border-radius: 999px;
    text-decoration: none;
}
.formations-hero-pill {
    background: rgba(255, 255, 255, 0.12);
    border: 1px solid rgba(255, 255, 255, 0.22);
    color: #fff;
}
.formations-panel {
    margin-top: 1.4rem;
    border: 1px solid rgba(0,48,135,.1);
    border-radius: 20px;
    background: #fff;
    padding: 1.4rem;
    box-shadow: 0 14px 30px rgba(0,0,0,.04);
}
.formations-section-head {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: end;
    gap: 1rem;
    margin-bottom: 1.2rem;
}
.formations-section-head h2 {
    margin: 0;
    color: #102f50;
    font-size: 1.55rem;
}
.formations-section-head p {
    margin: .35rem 0 0;
    color: #5a6c81;
}
.formations-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1.25rem;
    align-items: start;
}
.formation-card {
    display: flex;
    flex-direction: column;
    border: 1px solid rgba(0,48,135,.1);
    border-radius: 18px;
    background: #fff;
    overflow: visible;


    box-shadow: 0 8px 22px rgba(0,0,0,.04);
}
.formation-card-media {
    border-radius: 18px 18px 0 0;
    overflow: hidden;
    position: relative;
    min-height: 220px;
    background: linear-gradient(135deg, #f2f6fb, #e7eff8);
}
.formation-card-media img {
    width: 100%;

    object-fit: cover;
}
.formation-card-placeholder {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: .6rem;
    color: #24507f;
    text-align: center;
    padding: 1.25rem;
}
.formation-card-placeholder i {
    font-size: 2rem;
}
.formation-card-body {
    display: flex;
    flex-direction: column;
    gap: .9rem;
    padding: 1.2rem 1.2rem 1.3rem;
    flex: 1;
}
.formation-card h3 {
    margin: 0;
    color: #102f50;
    font-size: 1.28rem;
}
.formation-card-summary {
    margin: 0;
    color: #55657a;
    line-height: 1.7;
    min-height: 3.2rem;
}
.formation-card-summary,
.formation-card-details,
.formations-rich-content {
    text-wrap: pretty;
}
.formation-card-licences {
    display: flex;
    flex-wrap: wrap;
    gap: .5rem;
}
.formation-card-licence {
    display: inline-flex;
    align-items: center;
    padding: .38rem .7rem;
    border-radius: 999px;
    background: #f3f8fd;
    border: 1px solid #dbe8f5;
    color: #163f6a;
    font-size: .82rem;
    font-weight: 700;
}
.formation-card-actions {
    display: flex;
    flex-wrap: wrap;
    gap: .7rem;
    margin-top: auto;
}
.formation-card-actions .btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 46px;
    padding: .72rem 1rem;
    margin-top: 0;
    font-size: .95rem;
    font-weight: 700;
    text-transform: none;
    text-decoration: none;
}
.formation-card-actions .btn.btn-emsp-outline {
    border-color: rgba(0,48,135,.25);
}
.formation-card-actions .btn.btn-emsp,
.formation-card-actions .btn.btn-emsp:hover,
.formation-card-actions .btn.btn-emsp:focus {
    color: #fff;
}
.formation-card-actions .btn.btn-emsp-outline,
.formation-card-actions .btn.btn-emsp-outline:focus {
    color: #0a5b34;
}
.formation-card-actions .btn.btn-emsp-outline:hover {
    color: #fff;
}
.formation-card-details {
    margin-top: .1rem;
    padding-top: 1rem;
    border-top: 1px solid #e5edf5;
}
.formations-rich-content {
    color: #41576f;
    line-height: 1.75;
}
.formations-rich-content::after {
    content: '';
    display: block;
    clear: both;
}
.formations-rich-content p:last-child {
    margin-bottom: 0;
}
.formations-licence-pills {
    margin-top: .8rem;
}
.formations-licence-pill {
    background: #f8fbff;
    border: 1px solid #dde7f1;
    color: #183d66;
    font-weight: 700;
}
.formations-tronc-list {
    margin-top: .6rem;
}
.formations-tronc-link {
    background: #f6fbff;
    border: 1px solid #dbe8f3;
    color: #0a5b34;
    font-weight: 700;
}
@media (max-width: 1199.98px) {
    .formations-grid {
        grid-template-columns: 1fr;
    }
}
@media (max-width: 767.98px) {
    .formations-shell {
        padding-top: 1.5rem;
    }
    .formations-hero,
    .formations-panel {
        border-radius: 20px;
        padding: 1.15rem;
    }
    .formation-card-media {
    border-radius: 18px 18px 0 0;
    overflow: hidden;
        min-height: 180px;
    }
}
</style>

<section class="formations-shell">
    <div class="container">
        <div class="formations-hero">
            <h1>Formations EMSP</h1>
            <p>
                Retrouve ici les filières actives, leur présentation et les ressources associées. Cette page reste reliée à tes données réelles : les visuels et les contenus peuvent être alimentés depuis l'administration.
            </p>
            <div class="formations-hero-meta">
                <span class="formations-hero-pill"><i class="bi bi-diagram-3"></i><?= count($filieresMain) ?> filière(s) active(s)</span>
                <span class="formations-hero-pill"><i class="bi bi-layers"></i><?= count($licences) ?> niveau(x) disponible(s)</span>
                <a class="formations-hero-pill" href="bibliotheque.php"><i class="bi bi-collection"></i>Voir les ressources</a>
            </div>
        </div>

        <?php if (!empty($troncCommuns)): ?>
            <div class="formations-panel">
                <div class="formations-section-head">
                    <div>
                        <h2>Tronc commun</h2>
                        <p>Une base partagée avant la spécialisation.</p>
                    </div>
                </div>
                <div class="formations-tronc-list">
                    <?php foreach ($troncCommuns as $tronc): ?>
                        <a class="formations-tronc-link" href="bibliotheque.php?filiere=<?= (int) ($tronc['id'] ?? 0) ?>">
                            <i class="bi bi-arrow-up-right-circle-fill"></i>
                            <span><?= h((string) ($tronc['name'] ?? 'Tronc commun')) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="formations-panel">
            <div class="formations-section-head">
                <div>
                    <h2>Filières</h2>
                    <p>Une lecture simple, pilotée par tes filières et non par du contenu figé en dur.</p>
                </div>
            </div>

            <?php if (empty($filieresMain)): ?>
                <div class="alert alert-info mb-0">Aucune filière active pour le moment.</div>
            <?php else: ?>
                <div class="formations-grid">
                    <?php foreach ($filieresMain as $filiere): ?>
                        <?php
                        $filiereId = (int) ($filiere['id'] ?? 0);
                        $imageSrc = emsp_formation_image_src((string) ($filiere['cover_image_path'] ?? ''));
                        $summary = emsp_formation_summary($filiere);
                        $hasDetails = emsp_formation_has_details($filiere);
                        $detailId = 'formation-details-' . $filiereId;
                        $licenceLabels = $licenceMap[$filiereId] ?? [];
                        ?>
                        <article class="formation-card">
                            <div class="formation-card-media">
                                <?php if ($imageSrc !== ''): ?>
                                    <img src="<?= h($imageSrc) ?>" alt="<?= h((string) ($filiere['name'] ?? 'Filière EMSP')) ?>">
                                <?php else: ?>
                                    <div class="formation-card-placeholder">
                                        <i class="bi bi-image"></i>
                                        <strong>Visuel à ajouter</strong>
                                        <span>Ajoute ton image depuis l'administration de la filière.</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="formation-card-body">
                                <div>
                                    <h3><?= h((string) ($filiere['name'] ?? 'Filière')) ?></h3>
                                </div>

                                <?php if (!empty($licenceLabels)): ?>
                                    <div class="formation-card-licences">
                                        <?php foreach ($licenceLabels as $licenceLabel): ?>
                                            <span class="formation-card-licence"><?= h($licenceLabel) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <p class="formation-card-summary"><?= h($summary) ?></p>

                                <div class="formation-card-actions">
                                    <a href="bibliotheque.php?filiere=<?= $filiereId ?>" class="btn btn-emsp">Explorer les documents</a>
                                    <?php if ($hasDetails): ?>
                                        <button class="btn btn-emsp-outline" type="button" data-bs-toggle="collapse" data-bs-target="#<?= h($detailId) ?>" aria-expanded="false" aria-controls="<?= h($detailId) ?>">
                                            Présentation
                                        </button>
                                    <?php endif; ?>
                                </div>

                                <?php if ($hasDetails): ?>
                                    <div class="collapse formation-card-details" id="<?= h($detailId) ?>">
                                        <div class="formations-rich-content">
                                            <?= (string) ($filiere['description_html'] ?? '') ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($licences)): ?>
            <div class="formations-panel">
                <div class="formations-section-head">
                    <div>
                        <h2>Niveaux / licences</h2>
                        <p>Repères académiques actuellement disponibles sur la plateforme.</p>
                    </div>
                </div>
                <div class="formations-licence-pills">
                    <?php foreach ($licences as $licence): ?>
                        <span class="formations-licence-pill">
                            <i class="bi bi-mortarboard"></i>
                            <?= h((string) ($licence['name'] ?? 'Niveau')) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>

