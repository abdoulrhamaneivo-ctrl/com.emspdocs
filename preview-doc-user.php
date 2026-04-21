<?php
declare(strict_types=1);

include_once __DIR__ . '/includes/bootstrap.php';

$docId = (int) ($_GET['id'] ?? 0);
if ($docId <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'ID document invalide';
    exit;
}

header('Location: document.php?id=' . $docId, true, 302);
exit;


