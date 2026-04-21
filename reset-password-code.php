<?php
include_once __DIR__ . '/includes/bootstrap.php';
include_once __DIR__ . '/includes/flash.php';

$token = trim((string) ($_REQUEST['token'] ?? ''));

if ($token === '') {
    flash_set(
        'warning',
        'Ancien lien de rÃ©initialisation',
        'Ce point dâ€™entrÃ©e a Ã©tÃ© remplacÃ©. Utilisez le formulaire de rÃ©initialisation actuel.',
        'forgot-password.php',
        'Nouvelle demande'
    );
    header('Location: forgot-password.php');
    exit(0);
}

flash_set(
    'info',
    'Formulaire mis Ã  jour',
    'Nous avons redirigÃ© votre ancienne demande vers le formulaire sÃ©curisÃ© actuel. Revalidez simplement votre nouveau mot de passe.',
    'reset-password.php?token=' . urlencode($token),
    'Continuer'
);
header('Location: reset-password.php?token=' . urlencode($token));
exit(0);


