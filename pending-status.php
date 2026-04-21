<?php
include_once __DIR__ . '/includes/bootstrap.php';
include_once __DIR__ . '/admin/config/dbcon.php';
include_once __DIR__ . '/includes/flash.php';
include_once __DIR__ . '/includes/csrf.php';

if (empty($_SESSION['auth']) || empty($_SESSION['auth_user']['id'])) {
    flash_set(
        'warning',
        'Connexion requise',
        'Vous devez Ãªtre connectÃ© pour consulter le statut de votre demande.',
        'login.php',
        'Se connecter'
    );
    header('Location: login.php');
    exit;
}

$uid = (int) ($_SESSION['auth_user']['id'] ?? 0);
$stmt = mysqli_prepare(
    $con,
    "SELECT id, first_name, last_name, email, status, email_verified_at, verification_token, created_at, rejection_reason
     FROM users
     WHERE id=?
     LIMIT 1"
);
mysqli_stmt_bind_param($stmt, 'i', $uid);
mysqli_stmt_execute($stmt);
$user = emsp_stmt_fetch_assoc($stmt);
mysqli_stmt_close($stmt);

if (!$user) {
    session_destroy();
    flash_set(
        'error',
        'Session expirÃ©e',
        'Veuillez vous reconnecter pour continuer.'
    );
    header('Location: login.php');
    exit;
}

$emailConfirmed = !empty($user['email_verified_at']) || empty($user['verification_token']);
$status = strtolower(trim((string) ($user['status'] ?? 'pending')));
$firstName = trim((string) ($user['first_name'] ?? ''));

if ($status === 'active') {
    header('Location: dashboard.php');
    exit;
}

$page_title = 'Suivi de votre inscription';
include __DIR__ . '/includes/header.php';
?>

<style>
.emsp-status-shell {
    background:
        radial-gradient(circle at top left, rgba(8,97,54,.1), transparent 28%),
        linear-gradient(180deg, #f6f9fd 0%, #ffffff 100%);
}
.emsp-status-panel,
.emsp-status-side,
.emsp-status-note {
    border-radius: 24px;
    border: 1px solid #dbe5f0;
    background: #fff;
    box-shadow: 0 18px 40px rgba(18,39,74,.07);
}
.emsp-status-panel { padding: 2rem; }
.emsp-status-side {
    padding: 2rem;
    color: #fff;
    background:
        radial-gradient(circle at top right, rgba(8,97,54,.18), transparent 28%),
        linear-gradient(135deg, #10274a 0%, #1a3c6e 56%, #254f90 100%);
}
.emsp-status-side .badge {
    border-radius: 999px;
    padding: .5rem .8rem;
}
.emsp-status-note {
    margin-top: 1.2rem;
    padding: 1.1rem 1.2rem;
    background: #f7fbff;
}
.emsp-status-card {
    border-radius: 22px;
    border: 1px solid rgba(26,60,110,.08);
    background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
    padding: 1.2rem 1.25rem;
}
.emsp-status-step {
    display: flex;
    gap: .9rem;
    align-items: flex-start;
    padding: .95rem 0;
    border-bottom: 1px solid rgba(26,60,110,.08);
}
.emsp-status-step:last-child {
    border-bottom: 0;
    padding-bottom: 0;
}
.emsp-status-step-index {
    width: 2rem;
    height: 2rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    background: rgba(26,60,110,.08);
    color: #1a3c6e;
    font-weight: 800;
    flex-shrink: 0;
}
.emsp-status-step.is-done .emsp-status-step-index {
    background: rgba(8,97,54,.14);
    color: #086136;
}
.emsp-status-step.is-warning .emsp-status-step-index {
    background: rgba(230,81,0,.14);
    color: #e65100;
}
</style>

<section class="section-pad emsp-status-shell">
    <div class="container">
        <div class="row g-4 align-items-stretch">
            <div class="col-lg-5">
                <div class="emsp-status-side h-100">
                    <span class="badge text-bg-light text-primary fw-semibold mb-3">Suivi du compte</span>
                    <h1 class="h2 fw-bold mb-3">Voici exactement oÃ¹ en est votre inscription EMSP Docs</h1>
                    <p class="mb-4 text-white-50">
                        Nous vous montrons clairement lâ€™Ã©tape en cours pour Ã©viter toute confusion :
                        confirmation email, validation admin ou Ã©ventuel refus.
                    </p>
                    <div class="d-grid gap-3">
                        <div class="rounded-4 p-3 bg-white bg-opacity-10 border border-white border-opacity-10">
                            <strong class="d-block mb-1">Ã‰tape 1</strong>
                            <span class="text-white-50">Confirmer votre adresse email pour sÃ©curiser votre compte.</span>
                        </div>
                        <div class="rounded-4 p-3 bg-white bg-opacity-10 border border-white border-opacity-10">
                            <strong class="d-block mb-1">Ã‰tape 2</strong>
                            <span class="text-white-50">Attendre la validation de lâ€™administration si nÃ©cessaire.</span>
                        </div>
                        <div class="rounded-4 p-3 bg-white bg-opacity-10 border border-white border-opacity-10">
                            <strong class="d-block mb-1">Ã‰tape 3</strong>
                            <span class="text-white-50">Recevoir un email final puis accÃ©der Ã  lâ€™espace Ã©tudiant.</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="emsp-status-panel">
                    <?php if (!$emailConfirmed): ?>
                        <div class="mb-4">
                            <h2 class="h3 fw-bold mb-2">Confirmez votre adresse email</h2>
                            <p class="text-muted mb-0">
                                Nous avons envoyÃ© un email de confirmation Ã 
                                <strong><?= h((string) ($user['email'] ?? '')) ?></strong>.
                            </p>
                        </div>

                        <div class="emsp-status-card">
                            <div class="emsp-status-step is-done">
                                <span class="emsp-status-step-index">1</span>
                                <div>
                                    <strong class="d-block mb-1">Ouvrez votre boÃ®te mail</strong>
                                    <span class="text-muted">Cherchez un email envoyÃ© par EMSP Docs.</span>
                                </div>
                            </div>
                            <div class="emsp-status-step is-done">
                                <span class="emsp-status-step-index">2</span>
                                <div>
                                    <strong class="d-block mb-1">Cliquez sur le lien de confirmation</strong>
                                    <span class="text-muted">Votre inscription continue dÃ¨s que lâ€™adresse email est confirmÃ©e.</span>
                                </div>
                            </div>
                            <div class="emsp-status-step is-warning">
                                <span class="emsp-status-step-index">3</span>
                                <div>
                                    <strong class="d-block mb-1">Vous ne trouvez pas lâ€™email ?</strong>
                                    <span class="text-muted">VÃ©rifiez vos spams ou renvoyez un nouvel email de confirmation.</span>
                                </div>
                            </div>
                        </div>

                        <div class="emsp-status-note">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                                <div>
                                    <strong class="d-block mb-1">Besoin dâ€™un nouvel email ?</strong>
                                    <span class="text-muted">Le lien prÃ©cÃ©dent devient obsolÃ¨te dÃ¨s quâ€™un nouveau mail est gÃ©nÃ©rÃ©.</span>
                                </div>
                                <form method="post" action="resend-verification.php" class="m-0">
                                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                    <button type="submit" class="btn btn-outline-primary">
                                        <i class="bi bi-envelope-arrow-up me-2"></i>Renvoyer lâ€™email
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php elseif ($status === 'rejected'): ?>
                        <div class="mb-4">
                            <h2 class="h3 fw-bold mb-2">Demande dâ€™inscription refusÃ©e</h2>
                            <p class="text-muted mb-0">Votre demande nâ€™a pas Ã©tÃ© validÃ©e par lâ€™administration.</p>
                        </div>

                        <div class="alert alert-danger">
                            <i class="bi bi-x-circle-fill me-2"></i>
                            Votre inscription ne peut pas Ãªtre activÃ©e dans son Ã©tat actuel.
                        </div>

                        <?php if (!empty($user['rejection_reason'])): ?>
                            <div class="emsp-status-card mb-3">
                                <strong class="d-block mb-2">Motif communiquÃ©</strong>
                                <span class="text-muted"><?= h((string) $user['rejection_reason']) ?></span>
                            </div>
                        <?php endif; ?>

                        <div class="emsp-status-note">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                                <div>
                                    <strong class="d-block mb-1">Que faire ensuite ?</strong>
                                    <span class="text-muted">Si vous pensez quâ€™il sâ€™agit dâ€™une erreur, contactez lâ€™administration ou recommencez une inscription correcte.</span>
                                </div>
                                <a href="register.php" class="btn btn-emsp">
                                    <i class="bi bi-arrow-repeat me-2"></i>CrÃ©er un nouveau compte
                                </a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="mb-4">
                            <h2 class="h3 fw-bold mb-2">Demande en cours dâ€™examen</h2>
                            <p class="text-muted mb-0">
                                Bonjour <strong><?= h($firstName !== '' ? $firstName : 'Ã©tudiant') ?></strong>,
                                votre email est maintenant confirmÃ©.
                            </p>
                        </div>

                        <div class="emsp-status-card">
                            <div class="emsp-status-step is-done">
                                <span class="emsp-status-step-index"><i class="bi bi-check-lg"></i></span>
                                <div>
                                    <strong class="d-block mb-1">Adresse email confirmÃ©e</strong>
                                    <span class="text-muted">Votre compte est bien identifiÃ© et sÃ©curisÃ©.</span>
                                </div>
                            </div>
                            <div class="emsp-status-step is-warning">
                                <span class="emsp-status-step-index">2</span>
                                <div>
                                    <strong class="d-block mb-1">Validation administrateur en attente</strong>
                                    <span class="text-muted">Un administrateur EMSP doit encore examiner votre demande.</span>
                                </div>
                            </div>
                            <div class="emsp-status-step">
                                <span class="emsp-status-step-index">3</span>
                                <div>
                                    <strong class="d-block mb-1">Notification finale</strong>
                                    <span class="text-muted">Vous recevrez un email dÃ¨s que votre compte sera activÃ©.</span>
                                </div>
                            </div>
                        </div>

                        <div class="emsp-status-note">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                                <div>
                                    <strong class="d-block mb-1">DÃ©lais habituels</strong>
                                    <span class="text-muted">
                                        Le traitement prend gÃ©nÃ©ralement <strong>24 Ã  48 heures ouvrables</strong>.
                                        Pensez Ã  vÃ©rifier vos spams si vous nâ€™avez pas encore reÃ§u de mise Ã  jour.
                                    </span>
                                </div>
                                <span class="badge text-bg-light border text-dark">
                                    Demande soumise le <?= date('d/m/Y Ã  H:i', strtotime((string) ($user['created_at'] ?? 'now'))) ?>
                                </span>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>


