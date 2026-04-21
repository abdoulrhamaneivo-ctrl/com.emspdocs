<?php
include_once __DIR__ . '/includes/bootstrap.php';
session_unset();
session_destroy();

// DÃ©marrer une nouvelle session propre pour le message flash
emsp_bootstrap_session();
$_SESSION['message'] = 'Vous avez Ã©tÃ© dÃ©connectÃ© avec succÃ¨s.';
header('Location: login.php');
exit(0);


