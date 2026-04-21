<?php
function generate_csrf_token(): string
{
    include_once __DIR__ . '/bootstrap.php';

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf_token(): void
{
    include_once __DIR__ . '/bootstrap.php';

    $sessionToken = $_SESSION['csrf_token'] ?? '';
    $postToken = $_POST['csrf_token'] ?? '';

    if ($sessionToken === '' || $postToken === '' || !hash_equals($sessionToken, $postToken)) {
        $_SESSION['message'] = 'Action non autorisee (CSRF).';
        header('Location: index.php');
        exit;
    }
}

function csrf_input(): void
{
    $token = generate_csrf_token();
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">';
}


