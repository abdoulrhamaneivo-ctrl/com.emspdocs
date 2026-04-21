<?php
/**
 * Banniere publique pilotee depuis admin/settings.php.
 * A inclure une seule fois depuis includes/header.php.
 */
if (!isset($con) || !$con instanceof mysqli) {
    return;
}

$bannerSettings = [
    'banner_active' => '0',
    'banner_type' => 'info',
    'banner_message' => '',
];

$stmt = mysqli_prepare(
    $con,
    "SELECT skey, svalue
     FROM app_settings
     WHERE skey IN ('banner_active', 'banner_type', 'banner_message')"
);

if (!$stmt) {
    return;
}

mysqli_stmt_execute($stmt);
$rows = function_exists('emsp_stmt_fetch_all') ? emsp_stmt_fetch_all($stmt) : [];
mysqli_stmt_close($stmt);

foreach ($rows as $row) {
    $key = (string) ($row['skey'] ?? '');
    $value = trim((string) ($row['svalue'] ?? ''));
    if (array_key_exists($key, $bannerSettings)) {
        $bannerSettings[$key] = $value;
    }
}

if ($bannerSettings['banner_active'] !== '1') {
    return;
}

$bannerMessage = trim($bannerSettings['banner_message']);
if ($bannerMessage === '') {
    return;
}

$bannerType = in_array($bannerSettings['banner_type'], ['info', 'warning', 'danger', 'success'], true)
    ? $bannerSettings['banner_type']
    : 'info';

$bannerMeta = [
    'info' => ['icon' => 'bi-megaphone-fill', 'label' => 'Annonce EMSP'],
    'warning' => ['icon' => 'bi-exclamation-triangle-fill', 'label' => 'Information importante'],
    'danger' => ['icon' => 'bi-shield-exclamation', 'label' => 'Alerte plateforme'],
    'success' => ['icon' => 'bi-check-circle-fill', 'label' => 'Nouveaute'],
];

$bannerLabel = $bannerMeta[$bannerType]['label'] ?? 'Annonce EMSP';
$bannerIcon = $bannerMeta[$bannerType]['icon'] ?? 'bi-megaphone-fill';
?>
<div id="site-banner"
     class="emsp-editorial-banner"
     role="region"
     aria-label="Annonce de la plateforme"
     data-banner-type="<?= h($bannerType) ?>">
    <div class="container emsp-editorial-banner__inner">
        <span class="emsp-editorial-banner__kicker">
            <i class="bi <?= h($bannerIcon) ?>" aria-hidden="true"></i>
            <span><?= h($bannerLabel) ?></span>
        </span>
        <p class="emsp-editorial-banner__message mb-0"><?= h($bannerMessage) ?></p>
        <button type="button"
                class="emsp-editorial-banner__close"
                aria-label="Fermer la banniere">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
    </div>
</div>
<script>
(function () {
    var banner = document.getElementById('site-banner');
    if (!banner) {
        return;
    }

    var closeButton = banner.querySelector('.emsp-editorial-banner__close');
    if (!closeButton) {
        return;
    }

    closeButton.addEventListener('click', function () {
        banner.classList.add('is-closing');
        window.setTimeout(function () {
            banner.remove();
        }, 220);
    });
})();
</script>


