<?php
ob_start();
include_once __DIR__ . '/bootstrap.php';
include_once __DIR__ . '/config/dbcon.php';
include_once __DIR__ . '/authentication.php';

$markers = ['Ã', 'Â', 'â€™', 'â€œ', 'â€', 'ï¿½'];
$targets = [
    ['journal', 'title'],
    ['journal', 'content'],
    ['journal_options', 'label'],
    ['institution_content', 'label'],
    ['institution_content', 'valeur'],
    ['media', 'title'],
    ['media', 'description'],
    ['media', 'category'],
    ['documents', 'title'],
    ['documents', 'description'],
    ['documents', 'semester'],
    ['documents', 'exam_section'],
    ['comments', 'content'],
    ['comment_replies', 'content'],
    ['notifications', 'message'],
    ['filieres', 'name'],
    ['licences', 'name'],
    ['modules', 'name'],
    ['matieres', 'name'],
    ['users', 'first_name'],
    ['users', 'last_name'],
];

function emsp_build_mojibake_where(mysqli $con, string $column, array $markers): string
{
    $parts = [];
    foreach ($markers as $marker) {
        $parts[] = sprintf(
            "`%s` LIKE '%%%s%%'",
            $column,
            mysqli_real_escape_string($con, $marker)
        );
    }
    return '(' . implode(' OR ', $parts) . ')';
}

function emsp_count_mojibake(mysqli $con, string $table, string $column, array $markers): int
{
    $where = emsp_build_mojibake_where($con, $column, $markers);
    $sql = "SELECT COUNT(*) AS cnt FROM `$table` WHERE `$column` IS NOT NULL AND $where";
    $res = mysqli_query($con, $sql);
    if (!$res) {
        return 0;
    }
    $row = mysqli_fetch_assoc($res);
    mysqli_free_result($res);
    return (int) ($row['cnt'] ?? 0);
}

function emsp_mojibake_samples(mysqli $con, string $table, string $column, array $markers, int $limit = 3): array
{
    $where = emsp_build_mojibake_where($con, $column, $markers);
    $sql = "SELECT id, LEFT(`$column`, 180) AS sample
            FROM `$table`
            WHERE `$column` IS NOT NULL AND $where
            ORDER BY id DESC
            LIMIT " . max(1, $limit);
    $res = mysqli_query($con, $sql);
    if (!$res) {
        return [];
    }

    $rows = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $sample = (string) ($row['sample'] ?? '');
        $rows[] = [
            'id' => (int) ($row['id'] ?? 0),
            'sample' => $sample,
            'fixed' => function_exists('emsp_fix_mojibake') ? emsp_fix_mojibake($sample) : $sample,
        ];
    }
    mysqli_free_result($res);

    return $rows;
}

$results = [];
$total_to_fix = 0;
foreach ($targets as [$table, $column]) {
    $count = emsp_count_mojibake($con, $table, $column, $markers);
    $samples = $count > 0 ? emsp_mojibake_samples($con, $table, $column, $markers) : [];
    $results[] = [
        'table' => $table,
        'column' => $column,
        'count' => $count,
        'samples' => $samples,
    ];
    $total_to_fix += $count;
}

$page_title = 'Audit encodage';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
include __DIR__ . '/includes/navbar-top.php';
?>
<div id="admin-content">
    <div id="main-content" class="container-fluid">
        <div class="sb-card mb-4">
            <div class="sb-card-header">
                <div class="sb-card-title">
                    <i class="bi bi-type me-2"></i>Audit des caractères cassés
                </div>
            </div>
            <div class="sb-card-body">
                <div class="alert alert-warning mb-3" role="alert">
                    <strong>Mode audit uniquement.</strong>
                    Cette page ne modifie rien dans la base. Elle compte les valeurs suspectes
                    et affiche quelques exemples avant une éventuelle correction manuelle.
                </div>

                <div class="d-flex flex-wrap gap-2 mb-3">
                    <?php foreach ($markers as $marker): ?>
                        <span class="badge bg-light text-dark border"><?= h($marker) ?></span>
                    <?php endforeach; ?>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <div class="dark-card h-100">
                            <div class="small text-muted">Valeurs suspectes</div>
                            <div class="display-6 fw-bold mb-0"><?= (int) $total_to_fix ?></div>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <div class="dark-card h-100">
                            <div class="small text-muted mb-2">Correction SQL a preparer plus tard</div>
                            <code>UPDATE table_name SET column_name = CONVERT(BINARY CONVERT(column_name USING latin1) USING utf8mb4) WHERE ...;</code>
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Table</th>
                                <th>Colonne</th>
                                <th class="text-end">Occurrences</th>
                                <th>Exemples</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($results as $row): ?>
                            <tr>
                                <td><?= h($row['table']) ?></td>
                                <td><?= h($row['column']) ?></td>
                                <td class="text-end fw-semibold"><?= (int) $row['count'] ?></td>
                                <td>
                                    <?php if (empty($row['samples'])): ?>
                                        <span class="text-muted small">Aucun exemple</span>
                                    <?php else: ?>
                                        <?php foreach ($row['samples'] as $sample): ?>
                                            <div class="small mb-2">
                                                <div><strong>#<?= (int) $sample['id'] ?></strong> <?= h($sample['sample']) ?></div>
                                                <div class="text-success-emphasis">Apercu corrige: <?= h($sample['fixed']) ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
