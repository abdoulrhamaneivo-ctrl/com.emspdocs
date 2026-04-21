<?php
include_once __DIR__ . '/includes/bootstrap.php';
include_once __DIR__ . '/admin/config/dbcon.php';
include_once __DIR__ . '/includes/csrf.php';
include_once __DIR__ . '/includes/brevo.php';

function emsp_mask_email(string $email): string
{
    $email = trim($email);
    if ($email === '' || strpos($email, '@') === false) {
        return '';
    }

    [$local, $domain] = explode('@', $email, 2);
    $local = trim($local);
    $domain = trim($domain);

    if ($local === '' || $domain === '') {
        return '';
    }

    if (mb_strlen($local) <= 2) {
        $maskedLocal = mb_substr($local, 0, 1) . str_repeat('*', max(1, mb_strlen($local) - 1));
    } else {
        $maskedLocal = mb_substr($local, 0, 1)
            . str_repeat('*', max(2, mb_strlen($local) - 2))
            . mb_substr($local, -1);
    }

    return $maskedLocal . '@' . $domain;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token();

    $email = trim((string) ($_POST['email'] ?? ''));
    $emailMasked = emsp_mask_email($email);

    if ($email !== '') {
        $stmt = mysqli_prepare(
            $con,
            "SELECT id, first_name, last_name
             FROM users
             WHERE email=?
             LIMIT 1"
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $email);
            mysqli_stmt_execute($stmt);
            $user = emsp_stmt_fetch_assoc($stmt);
            mysqli_stmt_close($stmt);

            usleep(random_int(200000, 400000));

            if ($user) {
                $token = bin2hex(random_bytes(32));
                $update = mysqli_prepare(
                    $con,
                    "UPDATE users
                     SET reset_token=?, reset_token_expires_at=DATE_ADD(NOW(), INTERVAL 1 HOUR)
                     WHERE id=?"
                );
                if ($update) {
                    mysqli_stmt_bind_param($update, 'si', $token, $user['id']);
                    mysqli_stmt_execute($update);
                    mysqli_stmt_close($update);
                    $sent = brevo_send_password_reset(
                        (int) $user['id'],
                        $email,
                        (string) ($user['first_name'] ?? ''),
                        (string) ($user['last_name'] ?? ''),
                        $token
                    );
                    if (!$sent) {
                        error_log('EMSP forgot-password: Brevo send failed for user_id=' . (int) $user['id']);
                    }
                }
            }
        }
    }

    $_SESSION['forgot_password_notice'] = [
        'email_masked' => $emailMasked,
    ];

    header('Location: forgot-password.php?sent=1');
    exit;
}

$notice = $_SESSION['forgot_password_notice'] ?? null;
if (!empty($_GET['sent'])) {
    $_SESSION['forgot_password_notice'] = $notice;
} else {
    unset($_SESSION['forgot_password_notice']);
    $notice = null;
}

$page_title = 'Mot de passe oublie';
include __DIR__ . '/includes/header.php';
?>

<section class="section-pad emsp-auth-shell">
    <div class="container">
        <div class="row g-4 align-items-stretch">
            <div class="col-lg-5">
                <div class="emsp-auth-side h-100">
                    <span class="badge text-bg-light text-primary fw-semibold mb-3">Recuperation du compte</span>
                    <h1 class="h2 fw-bold mb-3">Recupere rapidement l acces a ton espace EMSP Docs</h1>
                    <p class="mb-4 text-white-50">
                        Entre simplement ton adresse email. Si un compte existe, nous t enverrons un lien
                        securise pour choisir un nouveau mot de passe.
                    </p>

                    <div class="emsp-auth-highlights">
                        <span class="emsp-auth-highlight"><i class="bi bi-envelope-paper"></i>Lien envoye par email</span>
                        <span class="emsp-auth-highlight"><i class="bi bi-shield-lock"></i>Token securise</span>
                    </div>

                    <div class="emsp-auth-bullets">
                        <div class="emsp-auth-bullet">
                            <span class="emsp-auth-bullet-icon"><i class="bi bi-link-45deg"></i></span>
                            <div>
                                <strong class="d-block mb-1">Lien securise</strong>
                                <span class="text-white-50">Le lien de reinitialisation expire automatiquement apres 1 heure.</span>
                            </div>
                        </div>
                        <div class="emsp-auth-bullet">
                            <span class="emsp-auth-bullet-icon"><i class="bi bi-incognito"></i></span>
                            <div>
                                <strong class="d-block mb-1">Confidentialite preservee</strong>
                                <span class="text-white-50">La plateforme ne revele jamais publiquement si une adresse email existe deja.</span>
                            </div>
                        </div>
                        <div class="emsp-auth-bullet">
                            <span class="emsp-auth-bullet-icon"><i class="bi bi-inboxes"></i></span>
                            <div>
                                <strong class="d-block mb-1">Boite mail et spams</strong>
                                <span class="text-white-50">Pense aussi a verifier tes courriers indesirables si l email tarde a arriver.</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="emsp-auth-panel">
                    <?php if (!empty($_GET['sent']) && is_array($notice)): ?>
                        <div class="mb-4">
                            <h2 class="h3 fw-bold mb-2">Email de reinitialisation envoye</h2>
                            <p class="text-muted mb-0">
                                Si un compte correspond, un lien vient d etre envoye
                                <?php if (!empty($notice['email_masked'])): ?>
                                    a <strong><?= htmlspecialchars((string) $notice['email_masked'], ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></strong>
                                <?php endif; ?>.
                            </p>
                        </div>

                        <div class="emsp-auth-success">
                            <div class="emsp-auth-success-step">
                                <span class="emsp-auth-success-index">1</span>
                                <div>
                                    <strong class="d-block mb-1">Ouvre ta boite mail</strong>
                                    <span class="text-muted">Cherche un email envoye par EMSP Docs.</span>
                                </div>
                            </div>
                            <div class="emsp-auth-success-step">
                                <span class="emsp-auth-success-index">2</span>
                                <div>
                                    <strong class="d-block mb-1">Clique sur le lien securise</strong>
                                    <span class="text-muted">Le lien reste valable pendant 1 heure pour choisir un nouveau mot de passe.</span>
                                </div>
                            </div>
                            <div class="emsp-auth-success-step">
                                <span class="emsp-auth-success-index">3</span>
                                <div>
                                    <strong class="d-block mb-1">Tu ne vois rien ?</strong>
                                    <span class="text-muted">Verifie aussi tes spams, puis refais une demande si necessaire.</span>
                                </div>
                            </div>
                        </div>

                        <div class="emsp-auth-note">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                                <div>
                                    <strong class="d-block mb-1">Suite rapide</strong>
                                    <span class="text-muted">Tu peux revenir a la connexion ou renvoyer un nouveau lien.</span>
                                </div>
                                <div class="d-flex flex-wrap gap-2">
                                    <a class="btn btn-outline-primary" href="forgot-password.php">Renvoyer un lien</a>
                                    <a class="btn btn-emsp" href="login.php">Retour a la connexion</a>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="mb-4">
                            <h2 class="h3 fw-bold mb-2">Recevoir un lien de reinitialisation</h2>
                            <p class="text-muted mb-0">Indique l email de ton compte pour recevoir les instructions.</p>
                        </div>

                        <div class="emsp-auth-trust">
                            <span class="emsp-auth-trust-pill"><i class="bi bi-shield-lock"></i>Demande securisee</span>
                            <span class="emsp-auth-trust-pill"><i class="bi bi-clock-history"></i>Lien valable 1 heure</span>
                        </div>

                        <form method="post" data-emsp-submit="1">
                            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                            <div class="mb-3">
                                <label class="form-label fw-semibold" for="forgot-email">Email</label>
                                <input type="email" class="form-control form-control-lg" id="forgot-email" name="email" required autocomplete="email">
                            </div>
                            <button class="btn btn-emsp btn-lg w-100" type="submit" data-loading-text="Envoi du lien...">
                                <i class="bi bi-envelope-paper-fill me-2"></i>Envoyer le lien
                            </button>
                        </form>

                        <div class="emsp-auth-note">
                            <strong class="d-block mb-1">Retour rapide</strong>
                            <span class="text-muted">Tu te souviens de ton mot de passe ?</span>
                            <a class="fw-semibold text-decoration-none ms-1" href="login.php">Revenir a la connexion</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>


