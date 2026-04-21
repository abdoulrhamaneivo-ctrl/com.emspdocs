<?php
include_once __DIR__ . '/includes/bootstrap.php';
include_once __DIR__ . '/includes/flash.php';

$alreadyAuth = !empty($_SESSION['auth']) || !empty($_SESSION['auth_user']['id']);
if ($alreadyAuth) {
    header('Location: dashboard.php');
    exit;
}

// Redirection normale avec JS
if (!isset($_GET['noscript'])) {
    if (isset($_GET['timeout']) && $_GET['timeout'] === '1') {
        flash_set(
            'warning',
            'Session expirée',
            'Reconnecte-toi pour continuer dans ton espace EMSP Docs.'
        );
    }

    if (!empty($_SESSION['message']) && is_string($_SESSION['message'])) {
        flash_set('info', 'Connexion requise', trim($_SESSION['message']));
        unset($_SESSION['message']);
    }

    header('Location: index.php?open_login=1');
    exit;
}

// Fallback si ?noscript=1 : afficher un formulaire HTML pur
$page_title = 'Connexion';
include __DIR__ . '/includes/header.php';
?>
<section class="section-pad">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-5">
                <div class="card shadow-sm">
                    <div class="card-body p-4">
                        <h1 class="h4 fw-bold mb-4 text-center">Connexion EMSP Docs</h1>
                        <form action="logincode.php" method="post">
                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Email</label>
                                <input type="email" name="email" class="form-control" required autocomplete="email">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Mot de passe</label>
                                <input type="password" name="password" class="form-control" required autocomplete="current-password">
                            </div>
                            <button type="submit" class="btn btn-primary w-100 fw-bold">
                                Se connecter
                            </button>
                            <p class="text-center mt-3 mb-0">
                                <a href="forgot-password.php">Mot de passe oublié ?</a>
                                · <a href="register.php">S'inscrire</a>
                            </p>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<?php include __DIR__ . '/includes/footer.php'; ?>
