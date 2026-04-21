<?php
ob_start();
include_once __DIR__ . '/bootstrap.php';
include_once __DIR__ . '/config/dbcon.php';
include_once __DIR__ . '/authentication.php';

$stats = [
    'users_total'       => 0,
    'users_active'      => 0,
    'users_pending'     => 0,
    'docs_total'        => 0,
    'docs_approved'     => 0,
    'docs_pending'      => 0,
    'docs_rejected'     => 0,
    'total_downloads'   => 0,
    'total_favorites'   => 0,
    'total_comments'    => 0,
    'badge_or'          => 0,
    'badge_argent'      => 0,
    'badge_bronze'      => 0,
];

// FIX: regrouper les mÃ©triques globales en un seul aller-retour SQL.
$statsSql = "
    SELECT
        (SELECT COUNT(*) FROM users WHERE role='etudiant') AS users_total,
        (SELECT COUNT(*) FROM users WHERE status='active' AND role='etudiant') AS users_active,
        (SELECT COUNT(*) FROM users WHERE status='pending' AND role='etudiant') AS users_pending,
        (SELECT COUNT(*) FROM documents) AS docs_total,
        (SELECT COUNT(*) FROM documents WHERE status='approved') AS docs_approved,
        (SELECT COUNT(*) FROM documents WHERE status='pending') AS docs_pending,
        (SELECT COUNT(*) FROM documents WHERE status='rejected') AS docs_rejected,
        (SELECT COALESCE(SUM(download_count), 0) FROM documents) AS total_downloads,
        (SELECT COUNT(*) FROM favorites) AS total_favorites,
        (SELECT COUNT(*) FROM comments WHERE status='visible') AS total_comments,
        (SELECT COUNT(*) FROM users WHERE badge_level='or' AND role='etudiant') AS badge_or,
        (SELECT COUNT(*) FROM users WHERE badge_level='argent' AND role='etudiant') AS badge_argent,
        (SELECT COUNT(*) FROM users WHERE badge_level='bronze' AND role='etudiant') AS badge_bronze
";

if ($statsRes = mysqli_query($con, $statsSql)) {
    $statsRow = mysqli_fetch_assoc($statsRes) ?: [];
    foreach (array_keys($stats) as $key) {
        $stats[$key] = (int) ($statsRow[$key] ?? 0);
    }
}

$actions_required = $stats['docs_pending'] + $stats['users_pending'];
$approval_rate = $stats['docs_total'] > 0 ? round(($stats['docs_approved'] * 100) / max(1, $stats['docs_total'])) : 0;
$activity_rate = $stats['users_total'] > 0 ? round(($stats['users_active'] * 100) / max(1, $stats['users_total'])) : 0;

// Docs par type
$types_data = [];
$types_res = mysqli_query(
    $con,
    "SELECT doc_type, COUNT(*) AS cnt
     FROM documents WHERE status='approved'
     GROUP BY doc_type ORDER BY cnt DESC"
);
while ($r = mysqli_fetch_assoc($types_res)) {
    $types_data[$r['doc_type']] = (int) $r['cnt'];
}

// Top 5 tÃ©lÃ©chargeurs
$top_uploaders_rows = [];
$top_uploaders = mysqli_query(
    $con,
    "SELECT u.first_name, u.last_name, u.upload_count, u.badge_level
     FROM users u WHERE u.role='etudiant' AND u.status='active'
     ORDER BY u.upload_count DESC LIMIT 5"
);
while ($row = mysqli_fetch_assoc($top_uploaders)) {
    $top_uploaders_rows[] = $row;
}

// Top 5 documents les plus tÃ©lÃ©chargÃ©s
$top_docs_rows = [];
$top_docs = mysqli_query(
    $con,
    "SELECT d.id, d.title, d.doc_type, d.download_count,
            u.first_name, u.last_name
     FROM documents d JOIN users u ON u.id=d.uploader_id
     WHERE d.status='approved'
     ORDER BY d.download_count DESC LIMIT 5"
);
while ($row = mysqli_fetch_assoc($top_docs)) {
    $top_docs_rows[] = $row;
}

// Uploads par mois (6 derniers mois)
$uploads_monthly_rows = [];
$uploads_monthly = mysqli_query(
    $con,
    "SELECT DATE_FORMAT(created_at,'%Y-%m') AS mois, COUNT(*) AS cnt
     FROM documents WHERE status='approved'
       AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
     GROUP BY mois ORDER BY mois ASC"
);
while ($row = mysqli_fetch_assoc($uploads_monthly)) {
    $uploads_monthly_rows[] = $row;
}

$page_title = 'Statistiques';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
include __DIR__ . '/includes/navbar-top.php';
?>

<div id="admin-content">
    <div id="main-content" class="container-fluid">
        <div class="admin-stats-shell">
            <section class="admin-stats-hero">
                <div class="row align-items-stretch">
                    <div class="col-xl-7">
                        <span class="admin-stats-kicker"><i class="bi bi-bar-chart-line-fill"></i>Vue analytique</span>
                        <h1 class="admin-stats-title">Statistiques EMSP</h1>
                        <p class="admin-stats-text">Retrouvez les grands indicateurs de la plateforme, la dynamique des documents, l'activitÃ© des Ã©tudiants et les prioritÃ©s de modÃ©ration dans une lecture plus claire.</p>
                        <div class="admin-stats-hero-grid">
                            <div class="admin-stats-hero-card">
                                <span>Ã‰tudiants actifs</span>
                                <strong><?= number_format($stats['users_active'], 0, ',', ' ') ?></strong>
                                <small><?= $activity_rate ?>% des comptes Ã©tudiants sont actifs</small>
                            </div>
                            <div class="admin-stats-hero-card">
                                <span>Validation documentaire</span>
                                <strong><?= $approval_rate ?>%</strong>
                                <small><?= number_format($stats['docs_approved'], 0, ',', ' ') ?> documents dÃ©jÃ  approuvÃ©s</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-5">
                        <div class="admin-stats-side h-100">
                            <div class="admin-stats-side-panel">
                                <h2>RepÃ¨res rapides</h2>
                                <p>Les tendances les plus utiles pour superviser la plateforme.</p>
                                <div class="admin-stats-side-grid">
                                    <div class="admin-stats-side-item">
                                        <div>
                                            <strong><?= number_format($stats['total_favorites'], 0, ',', ' ') ?> favoris</strong>
                                            <small>Engagement total enregistrÃ©</small>
                                        </div>
                                        <i class="bi bi-star-fill"></i>
                                    </div>
                                    <div class="admin-stats-side-item">
                                        <div>
                                            <strong><?= number_format($stats['total_comments'], 0, ',', ' ') ?> commentaires</strong>
                                            <small>Interactions visibles publiÃ©es</small>
                                        </div>
                                        <i class="bi bi-chat-left-text"></i>
                                    </div>
                                    <div class="admin-stats-side-item">
                                        <div>
                                            <strong><?= number_format($actions_required, 0, ',', ' ') ?> actions</strong>
                                            <small>Documents et comptes Ã  traiter</small>
                                        </div>
                                        <i class="bi bi-lightning-charge-fill"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <div class="row g-3">
                <div class="col-xl-3 col-md-6">
                    <div class="admin-stats-card stat-theme-1">
                        <div class="admin-stats-card-top">
                            <div class="admin-stats-card-icon"><i class="bi bi-people"></i></div>
                            <span class="admin-stats-card-chip">Comptes</span>
                        </div>
                        <div class="admin-stats-card-value"><?= number_format($stats['users_active'], 0, ',', ' ') ?></div>
                        <div class="admin-stats-card-label">Ã‰tudiants actifs</div>
                        <div class="admin-stats-card-meta"><?= $stats['users_active'] ?> actifs â€¢ <?= $stats['users_pending'] ?> en attente</div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="admin-stats-card stat-theme-2">
                        <div class="admin-stats-card-top">
                            <div class="admin-stats-card-icon"><i class="bi bi-files"></i></div>
                            <span class="admin-stats-card-chip">BibliothÃ¨que</span>
                        </div>
                        <div class="admin-stats-card-value"><?= number_format($stats['docs_approved'], 0, ',', ' ') ?></div>
                        <div class="admin-stats-card-label">Documents approuvÃ©s</div>
                        <div class="admin-stats-card-meta"><?= $stats['docs_approved'] ?> approuvÃ©s â€¢ <?= $stats['docs_pending'] ?> en attente</div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="admin-stats-card stat-theme-3">
                        <div class="admin-stats-card-top">
                            <div class="admin-stats-card-icon"><i class="bi bi-download"></i></div>
                            <span class="admin-stats-card-chip">Audience</span>
                        </div>
                        <div class="admin-stats-card-value"><?= number_format($stats['total_downloads'], 0, ',', ' ') ?></div>
                        <div class="admin-stats-card-label">TÃ©lÃ©chargements</div>
                        <div class="admin-stats-card-meta">Toutes ressources confondues</div>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="admin-stats-card stat-theme-4">
                        <div class="admin-stats-card-top">
                            <div class="admin-stats-card-icon"><i class="bi bi-hourglass-split"></i></div>
                            <span class="admin-stats-card-chip">PrioritÃ©s</span>
                        </div>
                        <div class="admin-stats-card-value"><?= number_format($actions_required, 0, ',', ' ') ?></div>
                        <div class="admin-stats-card-label">Actions requises</div>
                        <div class="admin-stats-card-meta"><?= $stats['docs_pending'] ?> docs â€¢ <?= $stats['users_pending'] ?> comptes</div>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-lg-4">
                    <div class="dark-card admin-stats-panel h-100">
                        <div class="admin-stats-panel-header">
                            <div>
                                <h3>Badges dÃ©cernÃ©s</h3>
                                <p>RÃ©partition des contributeurs rÃ©compensÃ©s.</p>
                            </div>
                            <span class="admin-stats-pill"><i class="bi bi-award"></i>CommunautÃ©</span>
                        </div>
                        <div class="admin-stats-grid-tight">
                            <?php foreach ([
                                ['label' => 'Badge Or', 'count' => $stats['badge_or'], 'color' => '#F5A800'],
                                ['label' => 'Badge Argent', 'count' => $stats['badge_argent'], 'color' => '#6B6B6B'],
                                ['label' => 'Badge Bronze', 'count' => $stats['badge_bronze'], 'color' => '#D4900A'],
                            ] as $badge): ?>
                                <div class="admin-stats-badge-row">
                                    <div class="admin-stats-badge-head">
                                        <span><?= $badge['label'] ?></span>
                                        <strong><?= number_format($badge['count'], 0, ',', ' ') ?></strong>
                                    </div>
                                    <div class="admin-stats-track">
                                        <span class="admin-track-fill"
                                              data-emsp-width="<?= max(8, min(100, $stats['users_total'] > 0 ? round(($badge['count'] * 100) / $stats['users_total']) : 8)) ?>"
                                              data-emsp-bg="<?= htmlspecialchars((string) $badge['color'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>"></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="dark-card admin-stats-panel h-100">
                        <div class="admin-stats-panel-header">
                            <div>
                                <h3>Documents par type</h3>
                                <p>Poids de chaque catÃ©gorie validÃ©e.</p>
                            </div>
                        </div>
                        <div class="admin-stats-grid-tight">
                            <?php
                            $typeColors = [
                                'cours' => '#006B3C',
                                'td' => '#F5A800',
                                'correction' => '#004D2A',
                                'concours' => '#1A1A1A',
                                'examen' => '#6B6B6B',
                            ];
                            foreach ($types_data as $type => $cnt):
                                $pct = $stats['docs_approved'] > 0 ? round(($cnt * 100) / $stats['docs_approved']) : 0;
                            ?>
                                <div class="admin-stats-type-row">
                                    <div class="admin-stats-type-head">
                                        <span><?= ucfirst($type) ?></span>
                                        <strong><?= $cnt ?> (<?= $pct ?>%)</strong>
                                    </div>
                                    <div class="admin-stats-track">
                                        <span class="admin-track-fill"
                                              data-emsp-width="<?= (int) $pct ?>"
                                              data-emsp-bg="<?= htmlspecialchars((string) ($typeColors[$type] ?? '#E0E0E0'), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>"></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="dark-card admin-stats-panel h-100">
                        <div class="admin-stats-panel-header">
                            <div>
                                <h3>Uploads rÃ©cents</h3>
                                <p>Six derniers mois de publications approuvÃ©es.</p>
                            </div>
                        </div>
                        <div class="admin-stats-grid-tight">
                            <?php foreach ($uploads_monthly_rows as $m): ?>
                                <div class="admin-stats-month-row">
                                    <div class="admin-stats-month-head">
                                        <span><?= date('M Y', strtotime($m['mois'] . '-01')) ?></span>
                                        <strong><?= $m['cnt'] ?></strong>
                                    </div>
                                    <div class="admin-stats-track">
                                        <span class="admin-track-fill admin-track-fill-gradient"
                                              data-emsp-width="<?= max(8, min(100, $stats['docs_approved'] > 0 ? round(($m['cnt'] * 100) / $stats['docs_approved']) : 8)) ?>"></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-lg-6">
                    <div class="dark-card admin-stats-panel h-100">
                        <div class="admin-stats-panel-header">
                            <div>
                                <h3>Top 5 contributeurs</h3>
                                <p>Ã‰tudiants les plus actifs en dÃ©pÃ´ts de documents.</p>
                            </div>
                            <a class="admin-stats-pill text-decoration-none" href="view-users.php"><i class="bi bi-people"></i>Voir les comptes</a>
                        </div>
                        <?php if (empty($top_uploaders_rows)): ?>
                            <div class="text-muted small">Aucune donnÃ©e disponible.</div>
                        <?php else: ?>
                            <div class="admin-stats-list">
                                <?php foreach ($top_uploaders_rows as $index => $u): ?>
                                    <div class="admin-stats-list-row">
                                        <div class="admin-stats-list-main">
                                            <strong>#<?= $index + 1 ?> <?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></strong>
                                            <small>Badge <?= ['or' => 'OR', 'argent' => 'ARG', 'bronze' => 'BR', 'none' => '-'][$u['badge_level']] ?? '-' ?></small>
                                        </div>
                                        <span class="badge bg-success fs-6"><?= (int) $u['upload_count'] ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="dark-card admin-stats-panel h-100">
                        <div class="admin-stats-panel-header">
                            <div>
                                <h3>Top 5 documents tÃ©lÃ©chargÃ©s</h3>
                                <p>Les ressources les plus consultÃ©es par les Ã©tudiants.</p>
                            </div>
                            <a class="admin-stats-pill text-decoration-none" href="view-documents.php"><i class="bi bi-files"></i>Voir les documents</a>
                        </div>
                        <?php if (empty($top_docs_rows)): ?>
                            <div class="text-muted small">Aucune donnÃ©e disponible.</div>
                        <?php else: ?>
                            <div class="admin-stats-list">
                                <?php
                                $docBadges = [
                                    'cours' => 'bg-primary',
                                    'td' => 'bg-success',
                                    'correction' => 'bg-info text-dark',
                                    'concours' => 'bg-warning text-dark',
                                    'examen' => 'bg-danger',
                                ];
                                foreach ($top_docs_rows as $index => $d):
                                ?>
                                    <div class="admin-stats-list-row">
                                        <div class="admin-stats-list-main">
                                            <strong>
                                                #<?= $index + 1 ?>
                                                <a href="../document.php?id=<?= $d['id'] ?>" class="admin-doc-link" target="_blank" rel="noopener">
                                                    <?= htmlspecialchars($d['title']) ?>
                                                </a>
                                            </strong>
                                            <small>Par <?= htmlspecialchars($d['first_name'] . ' ' . $d['last_name']) ?></small>
                                        </div>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="badge <?= $docBadges[$d['doc_type']] ?? 'bg-secondary' ?>"><?= ucfirst($d['doc_type']) ?></span>
                                            <span class="badge bg-primary"><?= (int) $d['download_count'] ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div><!-- /#main-content -->
</div><!-- /#admin-content -->
<?php include __DIR__ . '/includes/footer.php'; ?>


