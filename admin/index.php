<?php
ob_start();
include_once __DIR__ . '/bootstrap.php';
if (empty($_SESSION['auth']) || !in_array($_SESSION['auth_role'] ?? '', ['admin','moderateur'])) {
    header('Location: ../index.php?open_login=1'); exit(0);
}
include_once __DIR__ . '/config/dbcon.php';
include_once __DIR__ . '/authentication.php';

// Totaux principaux
$docs_approved = $docs_pending = $users_active = $users_pending = 0;
$total_downloads = $media_published = 0;

if ($counts = mysqli_query(
    $con,
    "SELECT
        (SELECT COALESCE(SUM(status='approved'), 0) FROM documents) AS docs_approved,
        (SELECT COALESCE(SUM(status='pending'), 0) FROM documents) AS docs_pending,
        (SELECT COALESCE(SUM(status='active' AND role='etudiant'), 0) FROM users) AS users_active,
        (SELECT COALESCE(SUM(status='pending' AND role='etudiant'), 0) FROM users) AS users_pending,
        (SELECT COALESCE(SUM(download_count), 0) FROM documents) AS total_downloads,
        (SELECT COUNT(*) FROM media WHERE status='published') AS media_published"
)) {
    $row = mysqli_fetch_assoc($counts);
    $docs_approved = (int) ($row['docs_approved'] ?? 0);
    $docs_pending = (int) ($row['docs_pending'] ?? 0);
    $users_active = (int) ($row['users_active'] ?? 0);
    $users_pending = (int) ($row['users_pending'] ?? 0);
    $total_downloads = (int) ($row['total_downloads'] ?? 0);
    $media_published = (int) ($row['media_published'] ?? 0);
}
$actions_required = $docs_pending + $users_pending;
$approval_rate = $docs_approved + $docs_pending > 0 ? round(($docs_approved * 100) / max(1, $docs_approved + $docs_pending)) : 0;
$accounts_ready_rate = $users_active + $users_pending > 0 ? round(($users_active * 100) / max(1, $users_active + $users_pending)) : 0;

// Sparklines 6 derniers mois (downloads)
$spark_labels = $spark_values = [];
$q = mysqli_query($con,
    "SELECT DATE_FORMAT(approved_at,'%b') AS m, SUM(download_count) AS total
     FROM documents
     WHERE approved_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
     GROUP BY DATE_FORMAT(approved_at,'%Y-%m')
     ORDER BY DATE_FORMAT(approved_at,'%Y-%m')");
while ($row = mysqli_fetch_assoc($q)) {
    $spark_labels[] = $row['m'];
    $spark_values[] = (int) $row['total'];
}

// Uploads / Approuves 12 mois
function monthsData($con, $field) {
    $labels = $values = [];
    $q = mysqli_query($con,
        "SELECT DATE_FORMAT($field,'%b %Y') AS mois, COUNT(*) AS nb
         FROM documents
         WHERE $field >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
         GROUP BY DATE_FORMAT($field,'%Y-%m')
         ORDER BY DATE_FORMAT($field,'%Y-%m')");
    while ($row = mysqli_fetch_assoc($q)) {
        $labels[] = $row['mois'];
        $values[] = (int) $row['nb'];
    }
    return [$labels, $values];
}
[$labels_uploaded, $vals_uploaded] = monthsData($con, 'created_at');
[$labels_approved, $vals_approved] = monthsData($con, 'approved_at');
$line_labels = $labels_uploaded ?: $labels_approved;

// Types docs
$doc_types = [];
$q = mysqli_query($con,
    "SELECT doc_type, COUNT(*) AS nb FROM documents
     WHERE status='approved' GROUP BY doc_type");
while ($row = mysqli_fetch_assoc($q)) {
    $doc_types[] = $row;
}

// Pending docs tableau
$pending_docs_rows = [];
$p = mysqli_prepare($con,
    "SELECT d.id, d.title, d.doc_type, d.file_size_bytes, d.created_at,
            u.first_name, u.last_name
     FROM documents d JOIN users u ON u.id=d.uploader_id
     WHERE d.status='pending'
     ORDER BY d.created_at DESC LIMIT 8");
mysqli_stmt_execute($p);
$pending_docs_rows = emsp_stmt_fetch_all($p);
mysqli_stmt_close($p);

// Derniers utilisateurs
$recent_users = [];
$q = mysqli_query($con,
    "SELECT id, first_name, last_name, created_at, badge_level
     FROM users WHERE role='etudiant'
     ORDER BY created_at DESC LIMIT 6");
while ($row = mysqli_fetch_assoc($q)) {
    $recent_users[] = $row;
}

// Badges stats
$badge_stats = ['or' => 0, 'argent' => 0, 'bronze' => 0, 'none' => 0];
$q = mysqli_query($con,
    "SELECT badge_level, COUNT(*) AS nb FROM users
     WHERE role='etudiant' GROUP BY badge_level");
while ($row = mysqli_fetch_assoc($q)) {
    $badge_stats[$row['badge_level'] ?? 'none'] = (int) $row['nb'];
}
$badge_total = array_sum($badge_stats);

$page_title = 'Tableau de bord';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
include __DIR__ . '/includes/navbar-top.php';
?>
<div id="admin-content">
    <div id="main-content" class="container-fluid">
        <div class="admin-dashboard">
            <section class="admin-overview-hero">
                <div class="row g-0 align-items-stretch">
                    <div class="col-xl-7">
                        <div class="admin-overview-copy">
                            <span class="admin-overview-kicker"><i class="bi bi-shield-check"></i>Administration EMSP</span>
                            <h1 class="admin-overview-title">Bonjour, <?= htmlspecialchars($_SESSION['auth_user']['first_name'] ?? 'Admin') ?></h1>
                            <p class="admin-overview-text">Pilotez la plateforme depuis un tableau de bord plus clair, avec vos priorités, vos validations et vos indicateurs essentiels en un seul coup d'oeil.</p>
                            <div class="admin-overview-metrics">
                                <div class="admin-overview-metric">
                                    <span>Documents en attente</span>
                                    <strong><?= number_format($docs_pending, 0, ',', ' ') ?></strong>
                                    <small><?= $approval_rate ?>% des documents sont déjà validés</small>
                                </div>
                                <div class="admin-overview-metric">
                                    <span>Comptes étudiants en attente</span>
                                    <strong><?= number_format($users_pending, 0, ',', ' ') ?></strong>
                                    <small><?= $accounts_ready_rate ?>% des comptes sont déjà actifs</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-5">
                        <div class="admin-overview-side h-100">
                            <div class="admin-overview-panel">
                                <h2>Actions rapides</h2>
                                <p>Accédez directement aux tâches les plus utiles de la journée.</p>
                                <div class="admin-overview-actions">
                                    <a class="admin-overview-action" href="pending-documents.php">
                                        <div>
                                            <strong>Valider les documents</strong>
                                            <small><?= $docs_pending ?> en attente de modération</small>
                                        </div>
                                        <i class="bi bi-file-earmark-check"></i>
                                    </a>
                                    <a class="admin-overview-action" href="pending-users.php">
                                        <div>
                                            <strong>Vérifier les comptes</strong>
                                            <small><?= $users_pending ?> étudiant(s) à traiter</small>
                                        </div>
                                        <i class="bi bi-people"></i>
                                    </a>
                                    <a class="admin-overview-action" href="stats.php">
                                        <div>
                                            <strong>Voir les statistiques</strong>
                                            <small><?= number_format($total_downloads, 0, ',', ' ') ?> téléchargements suivis</small>
                                        </div>
                                        <i class="bi bi-graph-up-arrow"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <div class="row g-3">
                <div class="col-xl-3 col-md-6">
                    <div class="admin-kpi-card kpi-theme-1">
                        <div class="admin-kpi-top">
                            <div class="admin-kpi-icon"><i class="bi bi-files"></i></div>
                            <span class="admin-kpi-chip">Modération</span>
                        </div>
                        <div class="stat-value admin-kpi-value" data-value="<?= $docs_approved ?>"><?= number_format($docs_approved, 0, ',', ' ') ?></div>
                        <div class="admin-kpi-label">Documents approuvés</div>
                        <div class="admin-kpi-meta">
                            <span class="up">Total validé</span>
                            <span>Total publié</span>
                        </div>
                        <canvas class="stat-sparkline" id="spark1" width="80" height="40"></canvas>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="admin-kpi-card kpi-theme-2">
                        <div class="admin-kpi-top">
                            <div class="admin-kpi-icon"><i class="bi bi-people"></i></div>
                            <span class="admin-kpi-chip">Communauté</span>
                        </div>
                        <div class="stat-value admin-kpi-value" data-value="<?= $users_active ?>"><?= number_format($users_active, 0, ',', ' ') ?></div>
                        <div class="admin-kpi-label">Étudiants actifs</div>
                        <div class="admin-kpi-meta">
                            <span class="up">Base active</span>
                            <span>Comptes approuvés</span>
                        </div>
                        <canvas class="stat-sparkline" id="spark2" width="80" height="40"></canvas>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="admin-kpi-card kpi-theme-3">
                        <div class="admin-kpi-top">
                            <div class="admin-kpi-icon"><i class="bi bi-download"></i></div>
                            <span class="admin-kpi-chip">Usage</span>
                        </div>
                        <div class="stat-value admin-kpi-value" data-value="<?= $total_downloads ?>"><?= number_format($total_downloads, 0, ',', ' ') ?></div>
                        <div class="admin-kpi-label">Téléchargements</div>
                        <div class="admin-kpi-meta">
                            <span class="up">Bibliothèque complète</span>
                            <span><?= number_format($media_published, 0, ',', ' ') ?> médias publiés</span>
                        </div>
                        <canvas class="stat-sparkline" id="spark3" width="80" height="40"></canvas>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="admin-kpi-card kpi-theme-4">
                        <div class="admin-kpi-top">
                            <div class="admin-kpi-icon"><i class="bi bi-hourglass-split"></i></div>
                            <span class="admin-kpi-chip">Priorité</span>
                        </div>
                        <div class="stat-value admin-kpi-value" data-value="<?= $actions_required ?>"><?= number_format($actions_required, 0, ',', ' ') ?></div>
                        <div class="admin-kpi-label">Actions requises</div>
                        <div class="admin-kpi-meta">
                            <span class="down"><?= $docs_pending ?> docs</span>
                            <span class="down"><?= $users_pending ?> comptes étudiants</span>
                        </div>
                        <canvas class="stat-sparkline" id="spark4" width="80" height="40"></canvas>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-lg-8">
                    <div class="dark-card admin-panel">
                        <div class="admin-panel-header">
                            <div>
                                <h3>Activité de la plateforme</h3>
                                <p>Uploads et documents approuvés sur les 12 derniers mois.</p>
                            </div>
                        </div>
                        <div class="chart-container">
                            <canvas id="chartLine"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="dark-card admin-panel h-100">
                        <div class="admin-panel-header">
                            <div>
                                <h3>Types de documents</h3>
                                <p>Répartition du contenu déjà validé.</p>
                            </div>
                        </div>
                        <div class="chart-container">
                            <canvas id="chartDoughnut"></canvas>
                        </div>
                        <div class="mt-3">
                            <?php
                            $totalTypes = array_sum(array_column($doc_types, 'nb')) ?: 1;
                            foreach ($doc_types as $t):
                                $pct = round($t['nb'] * 100 / $totalTypes);
                                $typeDotClass = 'admin-type-dot--' . preg_replace('/[^a-z0-9_-]/i', '', strtolower((string) ($t['doc_type'] ?? 'default')));
                                if ($typeDotClass === 'admin-type-dot--') {
                                    $typeDotClass = 'admin-type-dot--default';
                                }
                            ?>
                            <div class="d-flex align-items-center justify-content-between small text-muted mb-1">
                                <div><span class="admin-type-dot <?= h($typeDotClass) ?>"></span><?= htmlspecialchars($t['doc_type']) ?></div>
                                <div><?= $t['nb'] ?> (<?= $pct ?>%)</div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="dark-card admin-panel">
                <div class="admin-panel-header">
                    <div>
                        <h3>Documents récents en attente</h3>
                        <p>Les soumissions qui demandent encore une validation.</p>
                    </div>
                    <a class="admin-panel-link" href="pending-documents.php">Voir tout <i class="bi bi-arrow-right"></i></a>
                </div>
                <div class="table-responsive admin-table-shell d-none d-md-block">
                    <table class="table table-emsp-dark align-middle">
                        <thead>
                            <tr><th>#</th><th>Titre</th><th>Type</th><th>Auteur</th><th>Taille</th><th>Date</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($pending_docs_rows)): ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">Aucun document en attente.</td></tr>
                            <?php else: foreach ($pending_docs_rows as $r): ?>
                                <tr>
                                    <td class="text-muted small"><?= $r['id'] ?></td>
                                    <td class="fw-semibold"><?= htmlspecialchars($r['title']) ?></td>
                                    <td><span class="badge bg-warning text-dark"><?= htmlspecialchars($r['doc_type']) ?></span></td>
                                    <td class="small text-muted"><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></td>
                                    <td class="small"><?= $r['file_size_bytes'] ? round($r['file_size_bytes'] / 1024) . ' Ko' : '—' ?></td>
                                    <td class="small text-muted"><?= date('d/m/Y', strtotime($r['created_at'])) ?></td>
                                    <td><a class="btn btn-sm btn-outline-primary" href="pending-documents.php?id=<?= $r['id'] ?>">Voir</a></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="d-md-none admin-list">
                    <?php if (empty($pending_docs_rows)): ?>
                        <div class="emsp-admin-mobile-empty">Aucun document en attente.</div>
                    <?php else: ?>
                        <?php foreach ($pending_docs_rows as $r): ?>
                            <div class="emsp-admin-mobile-card">
                                <div class="emsp-admin-mobile-card-header">
                                    <div>
                                        <h3 class="emsp-admin-mobile-card-title mb-0"><?= htmlspecialchars((string) $r['title']) ?></h3>
                                        <div class="emsp-admin-mobile-card-subtitle"><?= htmlspecialchars((string) ($r['first_name'] . ' ' . $r['last_name'])) ?></div>
                                    </div>
                                    <span class="badge bg-warning text-dark"><?= htmlspecialchars((string) $r['doc_type']) ?></span>
                                </div>
                                <div class="emsp-admin-mobile-meta">
                                    <div class="emsp-admin-mobile-meta-item">
                                        <span class="emsp-admin-mobile-meta-label">Taille</span>
                                        <span class="emsp-admin-mobile-meta-value"><?= !empty($r['file_size_bytes']) ? round(((int) $r['file_size_bytes']) / 1024) . ' Ko' : '-' ?></span>
                                    </div>
                                    <div class="emsp-admin-mobile-meta-item">
                                        <span class="emsp-admin-mobile-meta-label">Date</span>
                                        <span class="emsp-admin-mobile-meta-value"><?= date('d/m/Y', strtotime((string) $r['created_at'])) ?></span>
                                    </div>
                                </div>
                                <div class="emsp-admin-mobile-actions">
                                    <a class="btn btn-sm btn-outline-primary" href="pending-documents.php?id=<?= (int) $r['id'] ?>">
                                        <i class="bi bi-eye me-1"></i>Voir
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-lg-6">
                    <div class="dark-card admin-panel h-100">
                        <div class="admin-panel-header">
                            <div>
                                <h3>Derniers inscrits</h3>
                                <p>Les étudiants arrivés récemment sur la plateforme.</p>
                            </div>
                            <a class="admin-panel-link" href="view-users.php">Voir la liste <i class="bi bi-arrow-right"></i></a>
                        </div>
                        <?php if (empty($recent_users)): ?>
                            <div class="text-muted small">Aucun utilisateur.</div>
                        <?php else: ?>
                            <div class="admin-list">
                                <?php foreach ($recent_users as $u):
                                    $initials = strtoupper(mb_substr($u['first_name'], 0, 1) . mb_substr($u['last_name'], 0, 1));
                                    $badge_label = ['or' => 'OR', 'argent' => 'ARG', 'bronze' => 'BR', 'none' => ''][(string) $u['badge_level']] ?? '';
                                ?>
                                <div class="admin-list-row">
                                    <div class="admin-list-user">
                                        <div class="avatar-grad avatar-grad-sm"><?= $initials ?></div>
                                        <div class="admin-list-copy">
                                            <strong>
                                                <?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?>
                                                <?php if ($badge_label !== ''): ?>
                                                    <span class="badge bg-light text-dark ms-2"><?= $badge_label ?></span>
                                                <?php endif; ?>
                                            </strong>
                                            <span class="small text-muted">Inscrit le <?= date('d/m/Y', strtotime($u['created_at'])) ?></span>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="dark-card admin-panel h-100">
                        <div class="admin-panel-header">
                            <div>
                                <h3>Badges utilisateurs</h3>
                                <p>Distribution actuelle des niveaux de contribution.</p>
                            </div>
                        </div>
                        <div class="admin-badge-grid">
                            <?php foreach (['or' => 'Or', 'argent' => 'Argent', 'bronze' => 'Bronze', 'none' => 'Sans badge'] as $k => $label):
                                $nb = $badge_stats[$k] ?? 0;
                                $pct = $badge_total ? round($nb * 100 / $badge_total) : 0;
                            ?>
                            <div class="admin-badge-row">
                                <div class="admin-badge-head">
                                    <span><?= $label ?></span><span><?= $nb ?> (<?= $pct ?>%)</span>
                                </div>
                                <div class="admin-badge-track">
                                    <div class="progress-bar admin-progress-init admin-progress-fill-<?= h($k) ?>" role="progressbar"
                                         data-target="<?= $pct ?>"></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div><!-- /#main-content -->
</div><!-- /#admin-content -->

<?php
$spark_labels_json = json_encode($spark_labels);
$spark_values_json = json_encode($spark_values);
$line_labels_json  = json_encode($line_labels);
$vals_uploaded_json = json_encode($vals_uploaded);
$vals_approved_json = json_encode($vals_approved);
$doc_type_counts_json = json_encode(array_column($doc_types, 'nb'));
$doc_type_labels_json = json_encode(array_column($doc_types, 'doc_type'));

$page_scripts = <<<HTML
<script src="../assets/js/chart.min.js"></script>
<script>
function initDashboardCounters(){
    function animCounter(el){
        const target=parseInt(el.dataset.value||'0',10);
        if (!Number.isFinite(target)) { return; }
        const dur=1200; const start=performance.now();
        function tick(now){
            const p=Math.min((now-start)/dur,1);
            const ease=1-Math.pow(1-p,3);
            el.textContent=Math.floor(target*ease).toLocaleString('fr-FR');
            if(p<1) requestAnimationFrame(tick);
        }
        tick(start);
    }
    document.querySelectorAll('.stat-value[data-value]').forEach(animCounter);

    document.querySelectorAll('.progress-bar[data-target]').forEach(function (bar) {
        requestAnimationFrame(function () {
            bar.style.width = (bar.dataset.target||0)+'%';
        });
    });
}
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initDashboardCounters);
} else {
    initDashboardCounters();
}

function sparkline(id, labels, data, color){
    const ctx=document.getElementById(id);
    if(!ctx||!window.Chart) return;
    new Chart(ctx,{
        type:'line',
        data:{
            labels:labels,
            datasets:[{
                data:data,
                borderColor:color,
                backgroundColor:'transparent',
                tension:0.4,
                pointRadius:0,
                borderWidth:2
            }]
        },
        options:{
            responsive:false,
            plugins:{legend:{display:false}},
            scales:{x:{display:false},y:{display:false}}
        }
    });
}
sparkline('spark1', $spark_labels_json, $spark_values_json, '#006B3C');
sparkline('spark2', $spark_labels_json, $spark_values_json, '#F5A800');
sparkline('spark3', $spark_labels_json, $spark_values_json, '#004D2A');
sparkline('spark4', $spark_labels_json, $spark_values_json, '#D4900A');

(function(){
    const ctx=document.getElementById('chartLine'); if(!ctx||!window.Chart) return;
    const lbls = $line_labels_json;
    const up   = $vals_uploaded_json;
    const ap   = $vals_approved_json;
    var emspColors = {
        primary: '#006B3C',
        accent: '#F5A800',
        light: 'rgba(0, 107, 60, 0.15)',
        border: '#E0E0E0'
    };
    new Chart(ctx,{
        type:'line',
        data:{labels:lbls, datasets:[
            {label:'Uploadés', data:up, borderColor:emspColors.primary, backgroundColor:emspColors.light, tension:.4, fill:true, pointRadius:2},
            {label:'Approuvés', data:ap, borderColor:emspColors.accent, backgroundColor:'rgba(245, 168, 0, 0.1)', tension:.4, fill:false, pointRadius:2},
        ]},
        options:{
            responsive:true,
            maintainAspectRatio:false,
            plugins:{legend:{labels:{color:'#334155'}}},
            scales:{
                x:{grid:{color:'rgba(15,23,42,.08)'}, ticks:{color:'#64748b'}},
                y:{grid:{color:'rgba(15,23,42,.08)'}, ticks:{color:'#64748b'}, beginAtZero:true}
            }
        }
    });
})();

(function(){
    const ctx=document.getElementById('chartDoughnut'); if(!ctx||!window.Chart) return;
    const data = $doc_type_counts_json;
    const labels = $doc_type_labels_json;
    const colors = labels.map(l=>({cours:'#006B3C',td:'#F5A800',correction:'#004D2A',examen:'#6B6B6B',concours:'#1A1A1A'}[l]||'#E0E0E0'));
    new Chart(ctx,{
        type:'doughnut',
        data:{labels:labels, datasets:[{data:data, backgroundColor:colors, borderWidth:1, borderColor:'rgba(255,255,255,.05)'}]},
        options:{
            responsive:true,
            maintainAspectRatio:false,
            cutout:'68%',
            plugins:{legend:{display:false}}
        }
    });
})();
</script>
HTML;
include __DIR__ . '/includes/footer.php';
?>
