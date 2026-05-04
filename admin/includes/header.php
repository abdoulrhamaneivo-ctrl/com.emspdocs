<?php
include_once __DIR__ . '/../../includes/bootstrap.php';

if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
}
if (empty($_SESSION['auth']) || !in_array($_SESSION['auth_role'] ?? '', ['admin','moderateur'])) {
    header('Location: ../index.php?open_login=1');
    exit;
}

// Affiche une erreur explicite en admin si un Fatal Error survient
// (ProFreeHost coupe souvent display_errors => page blanche).
if (!function_exists('emsp_admin_fatal_notice')) {
    function emsp_admin_fatal_notice(): void
    {
        $e = error_get_last();
        if (!$e || !is_array($e)) {
            return;
        }
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array((int) ($e['type'] ?? 0), $fatalTypes, true)) {
            return;
        }

        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
        }

        $fatalCss = '<style>'
            . '.emsp-admin-fatal{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;padding:16px;margin:16px;border-radius:12px;max-width:1200px;}'
            . '.emsp-admin-fatal--warn{border:1px solid rgba(245,158,11,.35);background:#fffbeb;color:#92400e;}'
            . '.emsp-admin-fatal--error{border:1px solid rgba(220,38,38,.35);background:#fef2f2;color:#7f1d1d;}'
            . '.emsp-admin-fatal-title{font-weight:800;}'
            . '.emsp-admin-fatal-body{margin-top:6px;}'
            . '.emsp-admin-fatal-meta{margin-top:8px;font-size:13px;opacity:.85;}'
            . '</style>';

        if (defined('APP_ENV') && APP_ENV !== 'development') {
            error_log('EMSP FATAL: ' . ($e['message'] ?? '') . ' in ' . ($e['file'] ?? '') . ':' . ($e['line'] ?? 0));
            echo $fatalCss
               . '<div class="emsp-admin-fatal emsp-admin-fatal--warn">'
               . 'Une erreur est survenue. Contactez l\'administrateur.'
               . '</div>';
            return;
        }

        $msg  = htmlspecialchars((string) ($e['message'] ?? 'Erreur inconnue'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $file = htmlspecialchars(basename((string) ($e['file'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $line = (int) ($e['line'] ?? 0);

        echo $fatalCss
           . '<div class="emsp-admin-fatal emsp-admin-fatal--error">'
           . '<div class="emsp-admin-fatal-title">Erreur PHP (Admin)</div>'
           . '<div class="emsp-admin-fatal-body">' . $msg . '</div>'
           . '<div class="emsp-admin-fatal-meta">' . $file . ':' . $line . '</div>'
           . '</div>';
    }
    register_shutdown_function('emsp_admin_fatal_notice');
}
include_once __DIR__ . '/../config/dbcon.php';
include_once __DIR__ . '/../authentication.php';
include_once __DIR__ . '/../../includes/csrf.php';
include_once __DIR__ . '/../../includes/flash.php';

// Fix mojibake globally in HTML output (handles hardcoded strings too).
if (function_exists('emsp_fix_mojibake')) {
    if (!function_exists('emsp_ob_fix_mojibake')) {
        function emsp_ob_fix_mojibake(string $buffer): string
        {
            return function_exists('emsp_fix_mojibake') ? emsp_fix_mojibake($buffer) : $buffer;
        }
    }
    if (!isset($GLOBALS['emsp_mojibake_ob_started'])) {
        $GLOBALS['emsp_mojibake_ob_started'] = true;
        ob_start('emsp_ob_fix_mojibake');
    }
}

$asset = '../assets/';
$admin_asset = 'assets/';
$harvardThemeEnabled = true;
$htmlClasses = trim('expanded' . ($harvardThemeEnabled ? ' theme-harvard' : ''));
$currentAdminBodyPath = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
if ($currentAdminBodyPath === '') {
    $currentAdminBodyPath = 'index.php';
}
$adminRouteClass = 'admin-route-' . preg_replace('/[^a-z0-9]+/i', '-', str_replace('.php', '', $currentAdminBodyPath));
$bodyClasses = trim('bg-body ' . $adminRouteClass . ($harvardThemeEnabled ? ' theme-harvard' : ''));
?>
<!doctype html>
<html lang="fr" class="<?= h($htmlClasses) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($page_title ?? 'Admin EMSP') ?></title>
    
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@600;800&display=swap" rel="stylesheet">
    
    <!-- CoreUI 5 -->
    <link href="https://cdn.jsdelivr.net/npm/@coreui/coreui@5.0.2/dist/css/coreui.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <!-- Third Party Assets -->
    <link rel="stylesheet" href="<?= $asset ?>vendor/sweetalert2/sweetalert2.min.css">
    <link rel="stylesheet" href="<?= $asset ?>vendor/select2/select2.min.css">
    
    <!-- EMSP Premium Modernization Layer -->
    <style>
    :root {
        --emsp-primary: #006B3C;
        --emsp-primary-rgb: 0, 107, 60;
        --emsp-accent: #F5A800;
        --emsp-accent-rgb: 245, 168, 0;
        --cui-sidebar-bg: #002D19;
        --cui-sidebar-color: rgba(255, 255, 255, 0.8);
        --cui-sidebar-nav-link-color: rgba(255, 255, 255, 0.7);
        --cui-sidebar-nav-link-active-bg: rgba(255, 255, 255, 0.1);
        --cui-sidebar-nav-link-hover-bg: rgba(255, 255, 255, 0.05);
    }

    body {
        font-family: 'Inter', sans-serif;
        background-color: #f8fafc;
        color: #1e293b;
    }

    h1, h2, h3, h4, .sidebar-brand {
        font-family: 'Outfit', sans-serif;
    }

    /* Sidebar EMSP Styling */
    .sidebar {
        border-right: 1px solid rgba(0,0,0,0.05);
        box-shadow: 10px 0 30px rgba(0,0,0,0.03);
    }

    .sidebar-brand {
        background-color: var(--emsp-primary);
        color: #fff;
        padding: 1.5rem 1rem;
        font-weight: 800;
        letter-spacing: -0.5px;
    }

    .sidebar-nav .nav-link {
        font-weight: 500;
        padding: 0.75rem 1.25rem;
        border-radius: 0.5rem;
        margin: 0.125rem 0.75rem;
        transition: all 0.2s ease;
    }

    .sidebar-nav .nav-link.active {
        color: #fff;
        background-color: rgba(245, 168, 0, 0.15) !important;
        border-left: 4px solid var(--emsp-accent);
    }

    .sidebar-nav .nav-icon {
        color: var(--emsp-accent);
        font-size: 1.1rem;
    }

    /* Top Header Glassmorphism */
    .header {
        background: rgba(255, 255, 255, 0.8) !important;
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        border-bottom: 1px solid rgba(0,0,0,0.05) !important;
        padding: 0.75rem 1.5rem;
    }

    /* Cards Modernization */
    .card {
        border: none;
        border-radius: 1rem;
        box-shadow: 0 10px 25px rgba(0,0,0,0.03);
        transition: transform 0.2s ease;
    }

    .card:hover {
        transform: translateY(-2px);
    }

    .card-header {
        background: transparent;
        border-bottom: 1px solid rgba(0,0,0,0.05);
        padding: 1.25rem;
        font-weight: 700;
        color: #0f172a;
    }

    /* Buttons */
    .btn-primary { background-color: var(--emsp-primary); border-color: var(--emsp-primary); color: #fff; }
    .btn-primary:hover { background-color: #005630; border-color: #005630; }
    .btn-warning { background-color: var(--emsp-accent); border-color: var(--emsp-accent); color: #fff; }

    /* Custom Admin components */
    .admin-user-info {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 1rem 1.25rem;
        border-bottom: 1px solid rgba(255,255,255,0.05);
    }

    .admin-avatar-circle {
        width: 38px; height: 38px; border-radius: 50%;
        background: linear-gradient(135deg, var(--emsp-primary), var(--emsp-accent));
        color: #fff; display: flex; align-items: center; justify-content: center;
        font-weight: 800; font-size: 0.8rem;
    }

    #emsp-progress-bar {
        position: fixed; top: 0; left: 0; width: 0%; height: 3px;
        background: linear-gradient(90deg, var(--emsp-primary), var(--emsp-accent));
        z-index: 9999; transition: width .2s ease; pointer-events: none;
    }
    </style>
</head>
<body class="c-app">
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
<div id="sidebar-overlay"></div>
<div id="admin-wrapper">


