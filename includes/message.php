<?php
if (!empty($_SESSION['message'])) {
    include_once __DIR__ . '/flash.php';
    $msg = (string) $_SESSION['message'];
    if (function_exists('emsp_fix_mojibake')) {
        $msg = emsp_fix_mojibake($msg);
    }
    flash_set('info', 'Information', $msg);
    unset($_SESSION['message']);
    flash_render();
}
?>


