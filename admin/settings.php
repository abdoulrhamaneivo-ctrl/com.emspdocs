<?php
ob_start();
include_once __DIR__ . '/bootstrap.php';
include_once __DIR__ . '/config/dbcon.php';
include_once __DIR__ . '/authentication.php';
include_once __DIR__ . '/../includes/csrf.php';
include_once __DIR__ . '/../includes/push-helper.php';

if (($auth_user['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit(0);
}

@mysqli_query(
    $con,
    "CREATE TABLE IF NOT EXISTS app_settings (
        skey VARCHAR(80) NOT NULL PRIMARY KEY,
        svalue TEXT NULL
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();

    $seuil = max(1, intval($_POST['or_badge_min_approved_docs'] ?? 20));
    $bannerActive = isset($_POST['banner_active']) ? '1' : '0';
    $bannerType = trim((string) ($_POST['banner_type'] ?? 'info'));
    $bannerMessage = trim((string) ($_POST['banner_message'] ?? ''));
    $pushPublic = trim((string) ($_POST['push_vapid_public'] ?? ''));
    $pushPrivate = trim((string) ($_POST['push_vapid_private'] ?? ''));

    if (($_POST['action'] ?? '') === 'generate_push_keys') {
        $generatedKeys = emsp_push_generate_vapid_keys();
        if ($generatedKeys) {
            $pushPublic = trim((string) ($generatedKeys['publicKey'] ?? ''));
            $pushPrivate = trim((string) ($generatedKeys['privateKey'] ?? ''));
            $_SESSION['message'] = 'Cles push generees et enregistrees.';
        } else {
            $_SESSION['message'] = 'Impossible de generer les cles push sur ce serveur.';
        }
    }

    $thresholdStmt = mysqli_prepare(
        $con,
        "INSERT INTO app_settings (skey, svalue)
         VALUES ('or_badge_min_approved_docs', ?)
         ON DUPLICATE KEY UPDATE svalue=?"
    );
    if ($thresholdStmt) {
        $seuilValue = (string) $seuil;
        mysqli_stmt_bind_param($thresholdStmt, 'ss', $seuilValue, $seuilValue);
        mysqli_stmt_execute($thresholdStmt);
        mysqli_stmt_close($thresholdStmt);
    }

    foreach (
        [
            ['banner_active', $bannerActive],
            ['banner_type', $bannerType],
            ['banner_message', $bannerMessage],
            ['push_vapid_public', $pushPublic],
            ['push_vapid_private', $pushPrivate],
        ] as [$key, $value]
    ) {
        $settingStmt = mysqli_prepare(
            $con,
            "INSERT INTO app_settings (skey, svalue)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE svalue=?"
        );
        if ($settingStmt) {
            mysqli_stmt_bind_param($settingStmt, 'sss', $key, $value, $value);
            mysqli_stmt_execute($settingStmt);
            mysqli_stmt_close($settingStmt);
        }
    }

    if (empty($_SESSION['message'])) {
        $_SESSION['message'] = 'Parametres mis a jour.';
    }

    header('Location: settings.php');
    exit(0);
}

$settings = [];
$settingsResult = mysqli_query($con, "SELECT skey, svalue FROM app_settings");
if ($settingsResult) {
    while ($row = mysqli_fetch_assoc($settingsResult)) {
        $settings[(string) $row['skey']] = (string) ($row['svalue'] ?? '');
    }
}

$seuilActuel = intval($settings['or_badge_min_approved_docs'] ?? 20);
$bannerActive = $settings['banner_active'] ?? '0';
$bannerType = $settings['banner_type'] ?? 'info';
$bannerMessage = $settings['banner_message'] ?? '';
$pushVapidPublic = $settings['push_vapid_public'] ?? '';
$pushVapidPrivate = $settings['push_vapid_private'] ?? '';

$stats = [
    'Utilisateurs actifs' => "SELECT COUNT(*) FROM users WHERE status='active'",
    'Documents approuves' => "SELECT COUNT(*) FROM documents WHERE status='approved'",
    'Documents en attente' => "SELECT COUNT(*) FROM documents WHERE status='pending'",
    'Total telechargements' => "SELECT SUM(download_count) FROM documents",
    'Commentaires visibles' => "SELECT COUNT(*) FROM comments WHERE status='visible'",
];
$statsRows = [];
foreach ($stats as $label => $query) {
    $value = 0;
    $result = mysqli_query($con, $query);
    if ($result) {
        $row = mysqli_fetch_row($result);
        $value = $row[0] ?? 0;
    }
    $statsRows[] = [
        'label' => $label,
        'value' => number_format((float) $value, 0, ',', ' '),
    ];
}

$page_title = 'Parametres';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
include __DIR__ . '/includes/navbar-top.php';
?>
<div id="admin-content">
<div id="main-content" class="container-fluid">

<?php if (!empty($_SESSION['message'])): ?>
    <div class="alert alert-info alert-dismissible fade show">
        <?= htmlspecialchars((string) $_SESSION['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['message']); ?>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h5 class="mb-0 fw-bold">
        <i class="bi bi-gear me-2 text-primary"></i>Parametres de la plateforme
    </h5>
</div>

<div class="row justify-content-center">
<div class="col-md-8">

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">
                Badge Or
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Seuil minimum de documents approuves</label>
                    <div class="input-group emsp-input-group-max-220">
                        <input class="form-control"
                               type="number"
                               name="or_badge_min_approved_docs"
                               value="<?= $seuilActuel ?>"
                               min="1"
                               max="999"
                               required>
                        <span class="input-group-text">docs</span>
                    </div>
                    <div class="form-text">
                        Actuellement : <strong><?= $seuilActuel ?> documents approuves</strong>. Attribution toujours <strong>manuelle</strong> par un admin.
                    </div>
                </div>
                <a href="badge-or-batch.php" class="btn btn-sm btn-outline-warning">
                    <i class="bi bi-people me-1"></i>Voir les eligibles
                </a>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-megaphone me-2 text-warning"></i>Banniere d'information (site public)
            </div>
            <div class="card-body">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" name="banner_active" id="banner_active" value="1" <?= $bannerActive === '1' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="banner_active">Afficher la banniere</label>
                </div>
                <div class="row g-3">
                    <div class="col-sm-4">
                        <label class="form-label fw-semibold">Type</label>
                        <select class="form-select" name="banner_type">
                            <?php foreach (['info', 'success', 'warning', 'danger'] as $type): ?>
                                <option value="<?= $type ?>" <?= $bannerType === $type ? 'selected' : '' ?>><?= ucfirst($type) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-8">
                        <label class="form-label fw-semibold">Message</label>
                        <textarea class="form-control" name="banner_message" rows="2" placeholder="Texte affiche sur toutes les pages publiques"><?= htmlspecialchars($bannerMessage) ?></textarea>
                    </div>
                </div>
                <div class="form-text mt-3">
                    Une banniere active avec un message non vide s'affiche automatiquement sur le front.
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-bell me-2 text-primary"></i>Notifications push web
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Configurez les cles VAPID pour permettre aux utilisateurs connectes d'activer les notifications navigateur.
                </p>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-semibold">Cle publique VAPID</label>
                        <textarea class="form-control" name="push_vapid_public" rows="3" placeholder="Cle publique VAPID"><?= htmlspecialchars($pushVapidPublic) ?></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Cle privee VAPID</label>
                        <textarea class="form-control" name="push_vapid_private" rows="3" placeholder="Cle privee VAPID"><?= htmlspecialchars($pushVapidPrivate) ?></textarea>
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2 mt-3">
                    <button type="submit" name="action" value="generate_push_keys" class="btn btn-outline-primary">
                        <i class="bi bi-magic me-1"></i>Generer les cles
                    </button>
                    <span class="small text-muted align-self-center">
                        Une fois les cles enregistrees, les utilisateurs pourront activer ou couper les push depuis leur profil.
                    </span>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-info-circle me-2"></i>Informations plateforme
            </div>
            <div class="card-body p-0 d-none d-md-block">
                <table class="table table-sm mb-0">
                    <?php foreach ($statsRows as $row): ?>
                        <tr>
                            <td class="text-muted small ps-3"><?= htmlspecialchars($row['label']) ?></td>
                            <td class="small fw-semibold"><?= htmlspecialchars($row['value']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <div class="card-body d-md-none">
                <div class="emsp-admin-mobile-list">
                    <?php foreach ($statsRows as $row): ?>
                        <div class="emsp-admin-mobile-card">
                            <div class="emsp-admin-mobile-card-header">
                                <div>
                                    <h3 class="emsp-admin-mobile-card-title mb-0"><?= htmlspecialchars($row['label']) ?></h3>
                                </div>
                                <span class="badge bg-light text-dark border"><?= htmlspecialchars($row['value']) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg me-1"></i>Enregistrer les parametres
        </button>
    </form>

</div>
</div>
</div><!-- /#main-content -->
</div><!-- /#admin-content -->
<?php include __DIR__ . '/includes/footer.php'; ?>


