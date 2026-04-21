<?php
include_once __DIR__ . '/includes/bootstrap.php';
include_once __DIR__ . '/admin/config/dbcon.php';
include_once __DIR__ . '/includes/csrf.php';
include_once __DIR__ . '/includes/flash.php';
include_once __DIR__ . '/includes/rate_limit.php';

$token = trim((string) ($_GET['token'] ?? ($_POST['token'] ?? '')));
$error = '';
$ip = emsp_client_ip();
$validTokenUserId = 0;

if ($token === '') {
    flash_set(
        'error',
        'Lien expire',
        'Ce lien de reinitialisation n est pas valide. Fais une nouvelle demande.',
        'forgot-password.php',
        'Nouvelle demande'
    );
    header('Location: forgot-password.php');
    exit;
}

$tokenCheck = mysqli_prepare(
    $con,
    "SELECT id
     FROM users
     WHERE reset_token=? AND reset_token_expires_at > NOW()
     LIMIT 1"
);
if ($tokenCheck) {
    mysqli_stmt_bind_param($tokenCheck, 's', $token);
    mysqli_stmt_execute($tokenCheck);
    $tokenRow = emsp_stmt_fetch_assoc($tokenCheck);
    mysqli_stmt_close($tokenCheck);
    $validTokenUserId = (int) ($tokenRow['id'] ?? 0);
}

if ($validTokenUserId <= 0) {
    flash_set(
        'error',
        'Lien expire',
        'Ce lien de reinitialisation n est plus valide. Il expire apres 1 heure.',
        'forgot-password.php',
        'Nouvelle demande'
    );
    header('Location: forgot-password.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === '') {
    verify_csrf_token();
    $rate = rate_limit_check($ip, $con);
    if (!empty($rate['blocked'])) {
        $retry_in = (int) ($rate['retry_in'] ?? 0);
        if ($retry_in > 0) {
            $mins = (int) ceil($retry_in / 60);
            $wait = ($retry_in >= 60)
                ? ($mins . ' minute' . ($mins > 1 ? 's' : ''))
                : ($retry_in . ' seconde' . ($retry_in > 1 ? 's' : ''));
            $error = 'Pour votre securite, reessayez dans ' . $wait . '.';
        } else {
            $error = 'Pour votre securite, reessayez plus tard.';
        }
    }

    $pwd = (string) ($_POST['password'] ?? '');
    $pwd2 = (string) ($_POST['password_confirm'] ?? '');
    if ($error === '' && (strlen($pwd) < 8 || $pwd !== $pwd2)) {
        rate_limit_record_failure($ip, $con);
        $error = 'Le mot de passe est invalide ou la confirmation ne correspond pas.';
    } elseif ($error === '') {
        mysqli_begin_transaction($con);
        try {
            $s = mysqli_prepare($con, "SELECT id FROM users WHERE reset_token=? AND reset_token_expires_at > NOW() LIMIT 1 FOR UPDATE");
            if (!$s) {
                throw new RuntimeException('prepare reset token failed');
            }
            mysqli_stmt_bind_param($s, 's', $token);
            if (!mysqli_stmt_execute($s)) {
                mysqli_stmt_close($s);
                throw new RuntimeException('execute reset token failed');
            }
            $u = emsp_stmt_fetch_assoc($s);
            mysqli_stmt_close($s);

            if (!$u) {
                mysqli_rollback($con);
                rate_limit_record_failure($ip, $con);
                flash_set(
                    'error',
                    'Lien expire',
                    'Ce lien de reinitialisation n est plus valide. Il expire apres 1 heure.',
                    'forgot-password.php',
                    'Nouvelle demande'
                );
                header('Location: forgot-password.php');
                exit;
            }

            $hash = password_hash($pwd, PASSWORD_DEFAULT);
            $upd = mysqli_prepare($con, "UPDATE users SET password_hash=?, reset_token=NULL, reset_token_expires_at=NULL WHERE id=?");
            if (!$upd) {
                throw new RuntimeException('prepare password update failed');
            }
            mysqli_stmt_bind_param($upd, 'si', $hash, $u['id']);
            if (!mysqli_stmt_execute($upd)) {
                mysqli_stmt_close($upd);
                throw new RuntimeException('execute password update failed');
            }
            mysqli_stmt_close($upd);

            $verifyStmt = mysqli_prepare($con, "SELECT password_hash FROM users WHERE id=? LIMIT 1");
            if (!$verifyStmt) {
                throw new RuntimeException('prepare password verify failed');
            }
            mysqli_stmt_bind_param($verifyStmt, 'i', $u['id']);
            if (!mysqli_stmt_execute($verifyStmt)) {
                mysqli_stmt_close($verifyStmt);
                throw new RuntimeException('execute password verify failed');
            }
            $stored = emsp_stmt_fetch_assoc($verifyStmt);
            mysqli_stmt_close($verifyStmt);
            if (!$stored || !password_verify($pwd, (string) ($stored['password_hash'] ?? ''))) {
                throw new RuntimeException('stored password verify failed');
            }

            mysqli_commit($con);

            rate_limit_clear($ip, $con);
            flash_set(
                'success',
                'Mot de passe modifie',
                'Ton nouveau mot de passe est actif. Tu peux maintenant te connecter.',
                'login.php',
                'Se connecter'
            );
            header('Location: login.php');
            exit;
        } catch (Throwable $e) {
            mysqli_rollback($con);
            $error = 'Une erreur est survenue. Reessaie dans quelques minutes.';
        }
    }
}

$page_title = 'Reinitialiser le mot de passe';
include __DIR__ . '/includes/header.php';
?>

<section class="section-pad emsp-reset-shell">
    <div class="container">
        <div class="row g-4 align-items-stretch">
            <div class="col-lg-5">
                <div class="emsp-reset-side h-100">
                    <span class="badge text-bg-light text-primary fw-semibold mb-3">Securite du compte</span>
                    <h1 class="h2 fw-bold mb-3">Choisis un nouveau mot de passe pour ton espace EMSP Docs</h1>
                    <p class="mb-4 text-white-50">
                        Prends un mot de passe d au moins 8 caracteres et garde-le prive.
                    </p>

                    <div class="emsp-reset-highlights">
                        <span class="emsp-reset-highlight"><i class="bi bi-shield-lock"></i>Mot de passe prive</span>
                        <span class="emsp-reset-highlight"><i class="bi bi-hourglass-split"></i>Lien temporaire</span>
                    </div>

                    <div class="emsp-reset-bullets">
                        <div class="emsp-reset-bullet">
                            <span class="emsp-reset-bullet-icon"><i class="bi bi-asterisk"></i></span>
                            <div>
                                <strong class="d-block mb-1">Minimum 8 caracteres</strong>
                                <span class="text-white-50">Choisis une combinaison simple a retenir mais difficile a deviner.</span>
                            </div>
                        </div>
                        <div class="emsp-reset-bullet">
                            <span class="emsp-reset-bullet-icon"><i class="bi bi-link-45deg"></i></span>
                            <div>
                                <strong class="d-block mb-1">Lien temporaire</strong>
                                <span class="text-white-50">Ce formulaire n est utilisable qu avec un lien encore valide.</span>
                            </div>
                        </div>
                        <div class="emsp-reset-bullet">
                            <span class="emsp-reset-bullet-icon"><i class="bi bi-check2-shield"></i></span>
                            <div>
                                <strong class="d-block mb-1">Validation immediate</strong>
                                <span class="text-white-50">Une fois confirme, ton nouveau mot de passe devient actif sans etape supplementaire.</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="emsp-reset-panel">
                    <div class="mb-4">
                        <h2 class="h3 fw-bold mb-2">Nouveau mot de passe</h2>
                        <p class="text-muted mb-0">Renseigne ton nouveau mot de passe puis confirme-le.</p>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></div>
                    <?php endif; ?>

                    <div class="emsp-reset-trust">
                        <span class="emsp-reset-trust-pill"><i class="bi bi-shield-lock"></i>Formulaire securise</span>
                        <span class="emsp-reset-trust-pill"><i class="bi bi-key"></i>Confirmation requise</span>
                    </div>

                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label fw-semibold" for="reset-password">Nouveau mot de passe</label>
                                <div class="emsp-reset-password-wrap">
                                    <input type="password" class="form-control form-control-lg" id="reset-password" name="password" required autocomplete="new-password">
                                    <button class="emsp-reset-password-toggle" type="button" data-password-target="reset-password" aria-label="Afficher le mot de passe" aria-pressed="false">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold" for="reset-password-confirm">Confirmer le mot de passe</label>
                                <div class="emsp-reset-password-wrap">
                                    <input type="password" class="form-control form-control-lg" id="reset-password-confirm" name="password_confirm" required autocomplete="new-password">
                                    <button class="emsp-reset-password-toggle" type="button" data-password-target="reset-password-confirm" aria-label="Afficher le mot de passe" aria-pressed="false">
                                        <i class="bi bi-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <button class="btn btn-emsp btn-lg w-100 mt-4" type="submit">
                            <i class="bi bi-shield-lock-fill me-2"></i>Mettre a jour le mot de passe
                        </button>
                    </form>

                    <div class="emsp-reset-note">
                        <strong class="d-block mb-1">Retour a la connexion</strong>
                        <span class="text-muted">Si tout est deja bon pour toi,</span>
                        <a class="fw-semibold text-decoration-none ms-1" href="login.php">retourne a la connexion</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var toggles = document.querySelectorAll('.emsp-reset-password-toggle');
    toggles.forEach(function (toggle) {
        toggle.addEventListener('click', function () {
            var targetId = this.getAttribute('data-password-target');
            var input = targetId ? document.getElementById(targetId) : null;
            if (!input) {
                return;
            }
            var reveal = input.type === 'password';
            input.type = reveal ? 'text' : 'password';
            this.setAttribute('aria-pressed', reveal ? 'true' : 'false');
            this.setAttribute('aria-label', reveal ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
            this.innerHTML = reveal ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
        });
    });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>


