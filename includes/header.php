<?php
include_once __DIR__ . '/bootstrap.php';
include_once __DIR__ . '/../admin/config/dbcon.php';
include_once __DIR__ . '/helpers.php';
include_once __DIR__ . '/csrf.php';
include_once __DIR__ . '/flash.php';

if (empty($GLOBALS['csp_nonce'])) {
    $GLOBALS['csp_nonce'] = base64_encode(random_bytes(16));
}
$csp_nonce = (string) $GLOBALS['csp_nonce'];

if (!function_exists('emsp_output_filter')) {
    function emsp_output_filter(string $buffer): string
    {
        if (function_exists('emsp_fix_mojibake')) {
            $buffer = emsp_fix_mojibake($buffer);
        }

        $nonce = (string) ($GLOBALS['csp_nonce'] ?? '');
        if ($nonce !== '') {
            $replacement = '<script nonce="' . htmlspecialchars($nonce, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"$1>';
            $buffer = preg_replace('/<script(?![^>]*\bnonce=)([^>]*)>/i', $replacement, $buffer) ?? $buffer;
        }

        return $buffer;
    }
}

if (!isset($GLOBALS['emsp_output_filter_started'])) {
    $GLOBALS['emsp_output_filter_started'] = true;
    ob_start('emsp_output_filter');
}

if (!headers_sent()) {
    // Charset HTML
    header('Content-Type: text/html; charset=UTF-8');
    // Clickjacking protection
    header('X-Frame-Options: SAMEORIGIN');
    // MIME sniffing protection
    header('X-Content-Type-Options: nosniff');
    // Referrer policy
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // Permissions policy
    header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
    // CSP â€” compatible ProFreeHost (banniÃ¨re injectÃ©e) + PDF.js (blob: workers) + Bootstrap Icons local
    header("Content-Security-Policy: default-src 'self' https://profreehost.com https://*.profreehost.com https://ezyro.com https://*.ezyro.com https://*.unaux.com; script-src 'self' 'nonce-{$csp_nonce}' blob: https://cdnjs.cloudflare.com https://profreehost.com https://*.profreehost.com https://*.ezyro.com https://*.unaux.com; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com https://profreehost.com https://*.profreehost.com https://*.ezyro.com; font-src 'self' data: https://fonts.gstatic.com https://profreehost.com https://*.profreehost.com https://*.ezyro.com; img-src 'self' data: blob: https://img.youtube.com https://i.ytimg.com https://api.dicebear.com https://profreehost.com https://*.profreehost.com https://*.ezyro.com https://*.unaux.com; frame-src 'self' blob: https://www.youtube.com https://youtube.com https://www.youtube-nocookie.com https://profreehost.com https://*.profreehost.com; worker-src 'self' blob:; connect-src 'self' blob: https://www.disify.com https://disify.com https://profreehost.com https://*.profreehost.com https://*.ezyro.com https://*.unaux.com;");
}

$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$scriptDir = rtrim(dirname($scriptName), '/');
if ($scriptDir === '.' || $scriptDir === '\\') {
    $scriptDir = '';
}
$base = $scriptDir === '' ? '/' : ($scriptDir . '/');
$asset = $base . 'assets/';
$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$canExposeManifest = $host === ''
    || (
        strpos($host, '.unaux.com') === false
        && strpos($host, '.ezyro.com') === false
        && strpos($host, '.profreehost.com') === false
    );
$faviconPath = __DIR__ . '/../assets/images/favicon.png';
$faviconHref = $asset . 'images/favicon.png';

$page_title = $page_title ?? 'Plateforme EMSP Docs';
$currentBodyPath = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
if ($currentBodyPath === '') {
    $currentBodyPath = 'index.php';
}
$harvardThemeEnabled = true;
$htmlClasses = $harvardThemeEnabled ? 'theme-harvard' : '';
$bodyClasses = ['emsp-route-' . preg_replace('/[^a-z0-9]+/i', '-', str_replace('.php', '', $currentBodyPath))];
if ($harvardThemeEnabled) {
    $bodyClasses[] = 'theme-harvard';
}
$bodyRole = (string) ($_SESSION['auth_role'] ?? ($_SESSION['auth_user']['role'] ?? 'guest'));
$bodyIsAuthenticated = !empty($_SESSION['auth']) || !empty($_SESSION['auth_user']['id']);
?>
<!doctype html>
<html lang="fr" class="<?= h($htmlClasses) ?>">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php if ($currentBodyPath === 'login.php' || (isset($_GET['open_login']) && $_GET['open_login'] === '1')): ?>
    <noscript>
        <meta http-equiv="refresh" content="0;url=login.php?noscript=1">
    </noscript>
    <?php endif; ?>
    <title><?= h($page_title) ?> &mdash; EMSP Docs</title>
    <?php if (is_file($faviconPath)): ?>
    <link rel="icon" href="<?= h($faviconHref) ?>" type="image/png">
    <link rel="shortcut icon" href="<?= h($faviconHref) ?>" type="image/png">
    <link rel="apple-touch-icon" href="<?= h($faviconHref) ?>">
    <?php endif; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Lato:wght@400;600;700;900&family=Playfair+Display:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $asset ?>css/emsp-fonts.css">
    <!-- Bootstrap local -->
    <link rel="stylesheet" href="<?= $asset ?>css/bootstrap5.min.css">
    <link rel="stylesheet" href="<?= $asset ?>css/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= $asset ?>vendor/sweetalert2/sweetalert2.min.css">
    <link rel="stylesheet" href="<?= $asset ?>vendor/select2/select2.min.css">
    <link rel="stylesheet" href="<?= $asset ?>css/emsp-theme.css">
    <link rel="stylesheet" href="<?= $asset ?>css/emsp-fixes.css">
    <?php if ($canExposeManifest): ?>
    <link rel="manifest" href="<?= $base ?>manifest.json">
    <?php endif; ?>
    <?php if (!empty($extra_head_tags)) { echo $extra_head_tags; } ?>
    <style>
    #emsp-progress-bar {
        position: fixed;
        top: var(--profreehost-banner-height, 0px);
        left: 0;
        width: 0%;
        height: 3px;
        background: linear-gradient(90deg, var(--color-primary, #006B3C), var(--color-accent, #F5A800));
        z-index: 99999;
        transition: width .2s ease;
        pointer-events: none;
    }
    </style>
    <?php if ($harvardThemeEnabled): ?>
    <link rel="stylesheet" href="<?= $asset ?>css/emsp-theme-harvard.css">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= $asset ?>css/emsp-phase-pages.css">
    <link rel="stylesheet" href="<?= $asset ?>css/emsp-palette-guard.css">
    <link rel="stylesheet" href="<?= $asset ?>css/emsp-variant2-ultra.css">
</head>
<body class="<?= h(trim(implode(' ', $bodyClasses))) ?>" data-role="<?= h($bodyRole) ?>" data-authenticated="<?= $bodyIsAuthenticated ? '1' : '0' ?>" data-route="<?= h($currentBodyPath) ?>">
<a class="emsp-skip-link" href="#main-content-anchor">Aller au contenu principal</a>
<div id="emsp-progress-bar"></div>
<script>
(function() {
    var bar = document.getElementById('emsp-progress-bar');
    if (!bar) return;
    var w = 0;
    var iv = setInterval(function() {
        w += Math.random() * 15;
        if (w > 85) { clearInterval(iv); w = 85; }
        bar.style.width = w + '%';
    }, 100);
    window.addEventListener('load', function() {
        clearInterval(iv);
        bar.style.width = '100%';
        setTimeout(function() {
            bar.style.opacity = '0';
            setTimeout(function() { bar.style.width = '0%'; bar.style.opacity = '1'; }, 300);
        }, 300);
    });
})();
</script>
<?php if (!empty($_SESSION['auth_user']['id'])): ?>
<script src="<?= $asset ?>js/emsp-push.js"></script>
<?php endif; ?>
<script>
(function () {
    function isVisible(el) {
        return !!(el && (el.offsetWidth || el.offsetHeight || el.getClientRects().length));
    }
    function detect() {
        var selectors = [
            '#adcenter', '#ads_banner', '#banner_ad',
            '.profreehost-ad', '.host-ad', '.ad-top', '.ad-banner',
            'body > div:first-child > iframe',
            'body > iframe:first-child',
            'body > center:first-child',
            'body > table:first-child'
        ];
        var bestH = 0;
        var bestEl = null;
        selectors.forEach(function (sel) {
            var el = null;
            try { el = document.querySelector(sel); } catch (e) { el = null; }
            if (!isVisible(el)) return;
            var h = 0;
            try { h = Math.round(el.getBoundingClientRect().height || 0); } catch (e2) { h = 0; }
            if (h > bestH) { bestH = h; bestEl = el; }
        });
        if (!bestEl || bestH <= 10) {
            document.documentElement.classList.remove('emsp-host-banner-fixed');
            document.documentElement.style.setProperty('--profreehost-banner-height', '0px');
            return;
        }
        var pos = '';
        var top = 9999;
        try {
            var st = getComputedStyle(bestEl);
            pos = st.position || '';
            top = bestEl.getBoundingClientRect().top;
        } catch (e3) {}

        if ((pos === 'fixed' || pos === 'sticky') && top <= 1) {
            document.documentElement.classList.add('emsp-host-banner-fixed');
            document.documentElement.style.setProperty('--profreehost-banner-height', bestH + 'px');
        } else {
            document.documentElement.classList.remove('emsp-host-banner-fixed');
            document.documentElement.style.setProperty('--profreehost-banner-height', '0px');
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', detect);
    } else {
        detect();
    }
    window.addEventListener('load', detect);
})();
</script>
<?php flash_render(); ?>
<?php include __DIR__ . '/navbar.php'; ?>
<?php include __DIR__ . '/banner.php'; ?>
<main id="main-content-anchor" class="site-main" tabindex="-1">

