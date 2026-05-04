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
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($page_title ?? 'Admin EMSP') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Lato:wght@400;600;700;900&family=Playfair+Display:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $asset ?>css/emsp-fonts.css">
    <link rel="stylesheet" href="<?= $asset ?>css/emsp-theme.css">
    <link rel="stylesheet" href="<?= $asset ?>vendor/sweetalert2/sweetalert2.min.css">
    <link rel="stylesheet" href="<?= $asset ?>vendor/select2/select2.min.css">
    <link rel="stylesheet" href="<?= $admin_asset ?>css/dasher-ui.css">
    <link rel="stylesheet" href="<?= $asset ?>css/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= $asset ?>css/emsp-fixes.css">
    <style>
    html, body { height: 100%; }
    #admin-wrapper { display: flex; min-height: 100vh; }
    #admin-content { margin-left: 232px; padding-top: 68px; width: 100%; }
    #main-content { padding: .9rem; }
    :root {
        --gradient-2: linear-gradient(135deg, #dbe9f8, #003087);
        --gradient-3: linear-gradient(135deg, #e6f1fb, #0055CC);
        --gradient-4: linear-gradient(135deg, #eef6ea, #1A7F37);
        --gradient-5: linear-gradient(135deg, #faeedb, #BA7517);
    }
    .dark-card {
        background: #fff;
        border: 1px solid rgba(0,0,0,.08);
        border-radius: .7rem;
        padding: .9rem;
        box-shadow: 0 2px 10px rgba(0,0,0,.04);
    }
    .dark-card-header { display:flex; align-items:center; justify-content:space-between; gap:.75rem; flex-wrap:wrap; }
    .dark-card-title { font-weight:700; color:#1f2937; font-size:.95rem; }
    .stat-card {
        position: relative;
        background: #fff;
        border: 1px solid rgba(0,0,0,.08);
        border-radius: .7rem;
        padding: .82rem;
        box-shadow: 0 1px 6px rgba(0,0,0,.04);
        min-height: 112px;
        overflow: hidden;
    }
    .stat-card::before {
        content:'';
        position:absolute; left:0; right:0; top:0; height:6px;
        background: var(--stat-accent, var(--gradient-3));
    }
    .stat-icon-wrap, .stat-icon {
        width: 34px; height: 34px; border-radius: 9px;
        display:flex; align-items:center; justify-content:center;
    }
    .stat-icon { background:#eaf1fa; color:var(--emsp-primary); }
    .stat-value { font-size:1.35rem; font-weight:800; color:#111827; margin-top:.32rem; }
    .stat-label { font-size:.76rem; color:#6b7280; font-weight:600; }
    .stat-trend { font-size:.72rem; color:#6b7280; }
    .stat-trend.up { color:#16a34a; }
    .stat-trend.down { color:#dc2626; }
    .admin-hero { color:#fff; }
    .admin-hero .text-muted { color: rgba(255,255,255,.75) !important; }
    .admin-hero .text-uppercase { color: rgba(255,255,255,.7) !important; }
    .admin-hero .fw-bold { color:#fff; }
    .admin-hero .progress { background: rgba(255,255,255,.2); }
    .table-emsp-dark thead th {
        background: #f8fafc;
        color: #6b7280;
        font-size: .76rem;
        text-transform: none;
        letter-spacing: .01em;
        border-bottom: 1px solid rgba(0,0,0,.06);
    }
    .table-emsp-dark td { border-color: rgba(0,0,0,.06); }
    .card, .sb-card, .dark-card { overflow: hidden; word-break: break-word; }
    .card-title, .doc-title {
        overflow: hidden;
        text-overflow: ellipsis;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
    }
    /* Boutons outline - rendre les actions bien visibles */
    .btn-outline-secondary { color:#475569; border-color:#cbd5e1; background:#fff; }
    .btn-outline-secondary:hover { background:#e2e8f0; color:#1f2937; }
    .btn-outline-success { color:#15803d; border-color:#86efac; background:#fff; }
    .btn-outline-success:hover { background:#dcfce7; color:#14532d; }
    .btn-outline-warning { color:#b45309; border-color:#fcd34d; background:#fff; }
    .btn-outline-warning:hover { background:#fef3c7; color:#92400e; }
    .btn-outline-danger { color:#004d2a; border-color:rgba(0,107,60,.32); background:#fff; }
    .btn-outline-danger:hover { background:#e8f5ee; color:#004d2a; }
    .btn-outline-primary { color:#1d4ed8; border-color:#93c5fd; background:#fff; }
    .btn-outline-primary:hover { background:#dbeafe; color:#1e3a8a; }
    .btn-sm { min-height: 30px; min-width: 30px; }
    #admin-content .btn.btn-primary { background:#10b981; border-color:#10b981; color:#fff; }
    #admin-content .btn.btn-success { background:#16a34a; border-color:#16a34a; color:#fff; }
    #admin-content .btn.btn-warning { background:#f59e0b; border-color:#f59e0b; color:#1f2937; }
    #admin-content .btn.btn-danger  { background:#f5a800; border-color:#f5a800; color:#1a1a1a; }
    #admin-content .btn.btn-info    { background:#0ea5e9; border-color:#0ea5e9; color:#fff; }
    #admin-content .btn i { line-height: 1; }
    .emsp-admin-mobile-list {
        display: grid;
        gap: .85rem;
    }
    .emsp-admin-mobile-card {
        background: #fff;
        border: 1px solid rgba(10,22,43,.08);
        border-radius: var(--radius-lg);
        padding: .95rem;
        box-shadow: var(--shadow-sm), var(--shadow-md);
    }
    .emsp-admin-mobile-card-header {
        display:flex;
        align-items:flex-start;
        justify-content:space-between;
        gap:.75rem;
        margin-bottom:.75rem;
    }
    .emsp-admin-mobile-card-title {
        font-size:.98rem;
        font-weight:800;
        color:#10274a;
        line-height:1.35;
        margin:0;
    }
    .emsp-admin-mobile-card-subtitle {
        margin-top:.18rem;
        color:#667085;
        font-size:.82rem;
        line-height:1.45;
    }
    .emsp-admin-mobile-meta {
        display:grid;
        gap:.55rem;
        margin-bottom:.85rem;
    }
    .emsp-admin-mobile-meta-item {
        display:grid;
        gap:.18rem;
    }
    .emsp-admin-mobile-meta-label {
        font-size:.72rem;
        font-weight:800;
        letter-spacing:.01em;
        text-transform:none;
        color:#667085;
    }
    .emsp-admin-mobile-meta-value {
        color:#18253b;
        font-size:.92rem;
        line-height:1.5;
        word-break:break-word;
    }
    .emsp-admin-mobile-actions {
        display:flex;
        flex-wrap:wrap;
        gap:.5rem;
    }
    .emsp-admin-mobile-actions .btn,
    .emsp-admin-mobile-actions .btn-sb-icon,
    .emsp-admin-mobile-actions .btn-sb-primary {
        min-height:38px;
    }
    .emsp-admin-mobile-inline-form {
        display:grid;
        gap:.55rem;
    }
    .emsp-admin-mobile-inline-form .form-control,
    .emsp-admin-mobile-inline-form .form-select {
        min-height: 40px;
    }
    .emsp-admin-mobile-card .badge,
    .emsp-admin-mobile-card .sb-badge {
        width: fit-content;
        max-width: 100%;
    }
    .emsp-admin-mobile-empty {
        text-align:center;
        color:#6b7280;
        padding:1.5rem 1rem;
        border:1px dashed rgba(10,22,43,.12);
        border-radius:18px;
        background:rgba(255,255,255,.72);
    }
    @media (min-width: 1536px) {
        #main-content { max-width: 1480px; margin: 0 auto; padding: 1.2rem; }
    }
    html.collapsed #admin-content { margin-left: 58px; }
    html.expanded #miniSidebar { width: 232px; }
    html.collapsed #miniSidebar { width: 58px; }
    html.expanded .navbar-glass { width: calc(100% - 232px); margin-left: 232px; }
    html.collapsed .navbar-glass { width: calc(100% - 58px); margin-left: 58px; }
    html.expanded .collapse-mini { display: inline-flex; }
    html.expanded .collapse-expanded { display: none; }
    html.collapsed .collapse-mini { display: none; }
    html.collapsed .collapse-expanded { display: inline-flex; }
    @media (max-width: 991px) {
        #admin-content { margin-left: 0; padding-top: 72px; }
        #main-content { padding: .78rem; }
        .navbar-glass { width: 100% !important; margin-left: 0 !important; }
        html.expanded #miniSidebar,
        html.collapsed #miniSidebar,
        #miniSidebar {
            display: block !important;
            position: fixed;
            left: 0 !important;
            top: 0 !important;
            bottom: 0 !important;
            width: 85vw !important;
            max-width: 85vw !important;
            transform: translateX(-105%) !important;
            transition: transform .25s ease !important;
            z-index: 1045 !important;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }
        body.sidebar-open #miniSidebar { transform: translateX(0) !important; }
    }
    @media (min-width: 768px) and (max-width: 1199.98px) {
        #main-content { padding: .95rem; }
        .admin-topbar-copy {
            max-width: min(48vw, 360px);
        }
        .admin-topbar-copy .fw-semibold {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
    }
    #emsp-progress-bar {
        position: fixed;
        top: var(--profreehost-banner-height, 0px);
        left: 0;
        width: 0%;
        height: 3px;
        background: linear-gradient(90deg, var(--emsp-primary), var(--emsp-accent));
        z-index: 99999;
        transition: width .2s ease;
        pointer-events: none;
    }
    :root {
        --emsp-admin-navy: var(--emsp-primary);
        --emsp-admin-green: var(--emsp-success);
        --emsp-admin-green-dark: #15692f;
        --emsp-admin-orange: var(--emsp-warning);
        --emsp-admin-surface: var(--emsp-surface-2);
    }
    body.bg-body {
        background: var(--emsp-admin-surface) !important;
        font-family: "Inter", "Segoe UI", sans-serif;
    }
    #miniSidebar {
        background:
            linear-gradient(180deg, rgba(10,22,43,.98) 0%, rgba(0,48,135,.98) 58%, rgba(0,85,204,.92) 100%),
            radial-gradient(circle at top right, rgba(255,255,255,.08), transparent 28%);
        color: #eef5ff;
        box-shadow: 18px 0 40px rgba(10,22,43,.18);
    }
    .admin-brand-shell {
        padding: .05rem 0 .55rem;
    }
    .admin-brand-link {
        display: flex;
        align-items: center;
        gap: .7rem;
        text-decoration: none;
    }
    .admin-brand-logo {
        width: 34px;
        height: 34px;
        object-fit: contain;
        background: #fff;
        border-radius: 11px;
        padding: .24rem;
        box-shadow: 0 10px 22px rgba(0,0,0,.16);
    }
    .site-logo-text {
        font-size: .98rem !important;
    }
    .admin-brand-copy {
        display: flex;
        flex-direction: column;
        min-width: 0;
    }
    .admin-brand-copy .site-logo-text,
    .admin-brand-subtitle {
        color: #fff;
    }
    .admin-brand-subtitle {
        font-size: .64rem;
        opacity: .72;
    }
    .admin-user-card {
        display: flex;
        align-items: center;
        gap: .8rem;
        padding: .7rem;
        border-radius: 16px;
        background: linear-gradient(180deg, rgba(255,255,255,.12) 0%, rgba(255,255,255,.08) 100%);
        border: 1px solid rgba(255,255,255,.1);
        box-shadow: 0 10px 18px rgba(4, 18, 38, .1);
        color: #eef5ff;
    }
    .admin-user-avatar,
    .admin-user-fallback {
        width: 42px;
        height: 42px;
        border-radius: 50%;
        object-fit: cover;
        flex: 0 0 auto;
    }
    .admin-user-fallback {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, var(--emsp-primary), var(--emsp-accent));
        color: #fff;
        font-weight: 800;
    }
    .admin-user-card .text-muted {
        color: rgba(255,255,255,.72) !important;
    }
    .admin-user-meta {
        min-width: 0;
    }
    .admin-user-name {
        font-size: .89rem;
        font-weight: 800;
        line-height: 1.2;
        color: #fff;
        word-break: break-word;
    }
    .admin-user-meta .text-muted {
        margin-top: .15rem;
        color: rgba(255,255,255,.78) !important;
        font-size: .76rem;
        font-weight: 600;
    }
    html.collapsed .admin-brand-link {
        justify-content: center;
    }
    html.collapsed .admin-brand-copy,
    html.collapsed .admin-user-meta {
        display: none;
    }
    html.collapsed .admin-user-card {
        justify-content: center;
        padding: .6rem;
    }
    .navbar-glass {
        background: rgba(255,255,255,.92) !important;
        backdrop-filter: blur(14px);
        border-bottom: 1px solid rgba(26,60,110,.08);
        box-shadow: 0 8px 22px rgba(26,60,110,.07);
    }
    .admin-topbar-btn {
        width: 34px;
        height: 34px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 11px;
        border: 1px solid rgba(26,60,110,.12);
        background: #fff;
        color: var(--emsp-admin-navy);
        box-shadow: 0 4px 14px rgba(26,60,110,.05);
    }
    .admin-topbar-copy {
        min-width: 0;
    }
    .admin-topbar-actions {
        flex-shrink: 0;
    }
    .admin-topbar-kicker {
        letter-spacing: .08em;
        color: #5e6f89;
        font-size: .68rem;
    }
    .admin-avatar-btn {
        width: 38px;
        height: 38px;
        padding: 0;
        border-radius: 50%;
        overflow: hidden;
        border: 1px solid rgba(26,60,110,.12);
        background: #fff;
    }
    .admin-avatar-img,
    .admin-avatar-fallback {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    .admin-avatar-fallback {
        background: linear-gradient(135deg, var(--emsp-primary), var(--emsp-accent));
        color: #fff;
        font-weight: 800;
    }
    .nav-heading {
        color: rgba(255,255,255,.72) !important;
        font-weight: 700;
        letter-spacing: .03em;
        text-transform: none;
        font-size: .72rem;
    }
    .nav-line {
        border-color: rgba(255,255,255,.1) !important;
    }
    #miniSidebar .nav-link {
        border-radius: 14px;
        color: rgba(238,245,255,.88);
        margin: .12rem .8rem;
        padding: .58rem .8rem;
        font-size: .93rem;
        transition: background .2s ease, color .2s ease, transform .2s ease;
    }
    #miniSidebar .nav-link:hover,
    #miniSidebar .nav-link.active {
        background: rgba(0,85,204,.22);
        color: #fff;
        box-shadow: inset 0 1px 0 rgba(255,255,255,.08);
    }
    #miniSidebar .nav-link.text-danger {
        color: rgba(255,255,255,.9) !important;
    }
    /* Maintenance: keep the back-office shell visually denser than the public site. */
    .dark-card,
    .stat-card,
    .card,
    .sb-card {
        border-radius: 12px;
        border: 1px solid rgba(26,60,110,.08);
        box-shadow: 0 10px 22px rgba(26,60,110,.055);
    }
    .sb-card-header,
    .sb-card-body {
        padding: .85rem .9rem !important;
    }
    .sb-table th,
    .sb-table td {
        padding: .68rem .78rem !important;
        font-size: .88rem;
        vertical-align: middle;
    }
    .sb-input,
    .sb-select,
    #admin-content .form-control,
    #admin-content .form-select {
        min-height: 40px;
        font-size: .92rem;
    }
    .btn-sb-primary,
    .btn-sb-outline,
    #admin-content .btn {
        min-height: 38px;
        font-size: .89rem;
    }
    .stat-card::before {
        background: linear-gradient(90deg, var(--emsp-admin-navy), var(--emsp-accent)) !important;
    }
    #admin-content .btn.btn-primary {
        background: var(--emsp-primary);
        border-color: var(--emsp-primary);
    }
    #admin-content .btn.btn-primary:hover {
        background: var(--emsp-accent);
        border-color: var(--emsp-accent);
    }
    #admin-content .btn.btn-success {
        background: var(--emsp-success);
        border-color: var(--emsp-success);
    }
    #admin-content .btn.btn-success:hover {
        background: var(--emsp-admin-green-dark);
        border-color: var(--emsp-admin-green-dark);
    }
    #admin-content .btn.btn-warning {
        background: var(--emsp-admin-orange);
        border-color: var(--emsp-admin-orange);
        color: #fff;
    }
    #admin-content .btn.btn-info {
        background: var(--emsp-accent);
        border-color: var(--emsp-accent);
        color: #fff;
    }
    .dropdown-menu {
        border-radius: 14px;
        border: 1px solid rgba(26,60,110,.08);
        box-shadow: 0 14px 28px rgba(26,60,110,.1);
    }
    @media (max-width: 1199.98px) {
        #main-content {
            padding: .78rem;
        }
        .admin-topbar-kicker {
            display: none;
        }
        .admin-topbar-copy .fw-semibold {
            font-size: .96rem;
            line-height: 1.2;
        }
        .admin-topbar-btn {
            width: 32px;
            height: 32px;
            border-radius: 10px;
        }
    }
    @media (max-width: 991.98px) {
        body.sidebar-open {
            overflow: hidden;
        }
        #sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .5);
            backdrop-filter: blur(2px);
            z-index: 1040;
        }
        #sidebar-overlay.active {
            display: block;
        }
        .navbar-glass .container-fluid {
            gap: .75rem;
        }
        .admin-topbar-actions {
            gap: .4rem !important;
        }
        .site-logo-text {
            font-size: .88rem !important;
        }
        .admin-brand-subtitle {
            display: none;
        }
        .admin-user-card {
            gap: .65rem;
            padding: .58rem;
            border-radius: 14px;
        }
        .admin-user-avatar,
        .admin-user-fallback {
            width: 38px;
            height: 38px;
        }
        .admin-user-name {
            font-size: .82rem;
        }
        .admin-user-meta .text-muted {
            font-size: .72rem;
        }
        .admin-topbar-copy {
            max-width: calc(100vw - 210px);
        }
        .admin-topbar-copy .fw-semibold {
            font-size: .92rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        #miniSidebar .nav-link {
            margin: .08rem .65rem;
            padding: .52rem .72rem;
            font-size: .88rem;
        }
    }
    @media (max-width: 767.98px) {
        #admin-content {
            padding-top: 68px;
        }
        #main-content {
            padding: .68rem;
        }
        .navbar-glass .container-fluid {
            padding-left: .75rem;
            padding-right: .75rem;
        }
        .admin-topbar-copy {
            max-width: calc(100vw - 188px);
        }
        .admin-topbar-kicker {
            display: none;
        }
        .admin-topbar-copy .fw-semibold {
            font-size: .9rem;
            line-height: 1.15;
        }
    }
    </style>
    <?php if ($harvardThemeEnabled): ?>
    <link rel="stylesheet" href="<?= $admin_asset ?>css/admin-theme-harvard.css">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= $admin_asset ?>css/admin-phase-pages.css">
    <link rel="stylesheet" href="<?= $admin_asset ?>css/admin-palette-guard.css">
    <link rel="stylesheet" href="<?= $admin_asset ?>css/admin-experience-upgrade.css">
    <link rel="stylesheet" href="<?= $admin_asset ?>css/admin-variant2-ultra.css">
    <!-- Loaded LAST so it wins the cascade and modernises the whole admin shell -->
    <link rel="stylesheet" href="<?= $admin_asset ?>css/admin-shell-modern.css">
</head>
<body class="<?= h($bodyClasses) ?>" data-role="<?= h($_SESSION['auth_role'] ?? 'guest') ?>">
<a class="emsp-skip-link" href="#main-content">Aller au contenu principal</a>
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


