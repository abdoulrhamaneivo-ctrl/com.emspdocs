<?php
ob_start();
include_once __DIR__ . '/includes/bootstrap.php';
include_once __DIR__ . '/admin/config/dbcon.php';
include_once __DIR__ . '/includes/csrf.php';
include_once __DIR__ . '/includes/flash.php';
include_once __DIR__ . '/includes/push-helper.php';

if (empty($_SESSION['auth'])) {
    flash_set(
        'warning',
        'Connexion requise',
        'Connectez-vous pour accéder à votre profil.',
        'login.php',
        'Se connecter'
    );
    header('Location: login.php'); exit(0);
}

$uid = intval($_SESSION['auth_user']['id']);

// Traitement modification profil
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf_token();

    if ($_POST['action'] === 'update_profile') {
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name  = trim($_POST['last_name']  ?? '');

        if ($first_name === '' || $last_name === '') {
            flash_set('error', 'Champs obligatoires', 'Prénom et nom sont obligatoires.');
            header('Location: mon-profil.php'); exit(0);
        }

        $photo_path = null;
        if (!empty($_FILES['photo']['name'])) {
            $real_mime = emsp_detect_mime($_FILES['photo']['tmp_name']);

            if (!in_array($real_mime, ['image/jpeg','image/png'], true)) {
                flash_set('error', 'Photo invalide', 'Seuls les fichiers JPG ou PNG sont acceptés.');
                header('Location: mon-profil.php'); exit(0);
            }
            $maxUpload = emsp_max_upload_size(defined('MAX_UPLOAD_SIZE') ? MAX_UPLOAD_SIZE : (5 * 1024 * 1024));
            $limit = min($maxUpload, 2 * 1024 * 1024);
            if (($_FILES['photo']['size'] ?? 0) > $limit) {
                flash_set('error', 'Photo trop lourde', 'La photo ne doit pas dépasser ' . round($limit / 1024 / 1024, 1) . ' Mo.');
                header('Location: mon-profil.php'); exit(0);
            }

            $profilesDir = __DIR__ . '/uploads/profiles';
            if (!is_dir($profilesDir)) {
                @mkdir($profilesDir, 0755, true);
            }
            // FIX: déduire l'extension depuis le MIME réel plutôt que depuis le nom fourni par le client.
            $mimeToExt = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
            $ext = $mimeToExt[$real_mime] ?? 'jpg';
            $photo_path = bin2hex(random_bytes(16)) . '.' . $ext;
            $dest = $profilesDir . '/' . $photo_path;
            if (!move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) {
                flash_set('error', 'Upload échoué', 'Impossible d\'enregistrer la photo sur le serveur.');
                header('Location: mon-profil.php'); exit(0);
            }

            $upd = mysqli_prepare($con, "UPDATE users SET photo_path=? WHERE id=?");
            mysqli_stmt_bind_param($upd, 'si', $photo_path, $uid);
            mysqli_stmt_execute($upd); mysqli_stmt_close($upd);
        }

        $upd = mysqli_prepare($con, "UPDATE users SET first_name=?, last_name=? WHERE id=?");
        mysqli_stmt_bind_param($upd, 'ssi', $first_name, $last_name, $uid);
        mysqli_stmt_execute($upd); mysqli_stmt_close($upd);

        $_SESSION['auth_user']['first_name'] = $first_name;
        $_SESSION['auth_user']['last_name']  = $last_name;
        if ($photo_path) {
            $_SESSION['auth_user']['photo_path'] = $photo_path;
        }

        if ($photo_path) {
            flash_set('success', 'Photo mise à jour', 'Votre nouvelle photo de profil est visible immédiatement.');
        } else {
            flash_set('success', 'Profil mis à jour', 'Vos informations ont été enregistrées avec succès.');
        }
        header('Location: mon-profil.php'); exit(0);
    }

    if ($_POST['action'] === 'change_password') {
        $current  = (string) ($_POST['current_password']  ?? '');
        $new_pwd  = (string) ($_POST['new_password']      ?? '');
        $confirm  = (string) ($_POST['confirm_password']  ?? '');

        if (strlen($new_pwd) < 8) {
            flash_set('error', 'Mot de passe trop court', 'Le nouveau mot de passe doit contenir au moins 8 caractÃ¨res.');
            header('Location: mon-profil.php'); exit(0);
        }
        if ($new_pwd !== $confirm) {
            flash_set('error', 'Les mots de passe ne correspondent pas', 'VÃ©rifiez votre saisie puis rÃ©essayez.');
            header('Location: mon-profil.php'); exit(0);
        }

        $s = mysqli_prepare($con, "SELECT password_hash FROM users WHERE id=? LIMIT 1");
        mysqli_stmt_bind_param($s, 'i', $uid);
        mysqli_stmt_execute($s);
        $r = emsp_stmt_fetch_assoc($s);
        mysqli_stmt_close($s);

        if (!$r || !password_verify($current, $r['password_hash'])) {
            flash_set('error', 'Mot de passe actuel incorrect', 'VÃ©rifiez votre mot de passe actuel puis rÃ©essayez.');
            header('Location: mon-profil.php'); exit(0);
        }

        $new_hash = password_hash($new_pwd, PASSWORD_DEFAULT);
        $upd = mysqli_prepare($con, "UPDATE users SET password_hash=? WHERE id=?");
        mysqli_stmt_bind_param($upd, 'si', $new_hash, $uid);
        mysqli_stmt_execute($upd); mysqli_stmt_close($upd);

        flash_set('success', 'Mot de passe modifiÃ©', 'Votre mot de passe a Ã©tÃ© mis Ã  jour avec succÃ¨s.');
        header('Location: mon-profil.php'); exit(0);
    }
}

// RÃ©cupÃ©rer les donnÃ©es du profil
$stmt = mysqli_prepare($con,
    "SELECT u.*, f.name AS filiere_name, l.name AS licence_name
     FROM users u
     LEFT JOIN filieres f ON f.id = u.filiere_id
     LEFT JOIN licences l ON l.id = u.licence_id
     WHERE u.id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $uid);
mysqli_stmt_execute($stmt);
$user = emsp_stmt_fetch_assoc($stmt);
mysqli_stmt_close($stmt);

if (!empty($user['badge_level']) && ($user['badge_level'] !== ($_SESSION['auth_user']['badge_level'] ?? ''))) {
    $_SESSION['new_badge'] = $user['badge_level'];
}
emsp_session_sync_auth_user($user);

$r = mysqli_query($con, "SELECT COUNT(*) FROM documents WHERE uploader_id=$uid AND status='approved'");
$docs_approved = mysqli_fetch_row($r)[0];

$r = mysqli_query($con, "SELECT COUNT(*) FROM favorites WHERE user_id=$uid");
$fav_count = mysqli_fetch_row($r)[0];

$r = mysqli_query($con, "SELECT COUNT(*) FROM history WHERE user_id=$uid AND action='download'");
$dl_count = mysqli_fetch_row($r)[0];

$badge_labels = [
    'or'     => 'Badge Or',
    'argent' => 'Badge Argent',
    'bronze' => 'Badge Bronze',
    'none'   => 'Aucun badge'
];
$badge_colors = ['or'=>'#F5A800','argent'=>'#6B6B6B','bronze'=>'#D4900A','none'=>'#E0E0E0'];
$badge_class_map = [
    'or' => 'emsp-profile-badge-level-or',
    'argent' => 'emsp-profile-badge-level-argent',
    'bronze' => 'emsp-profile-badge-level-bronze',
    'none' => 'emsp-profile-badge-level-none',
];

$push_public_key = emsp_push_public_key($con);
$app_scope = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
if ($app_scope === '/' || $app_scope === '.') {
    $app_scope = '';
}
$app_scope = rtrim($app_scope, '/') . '/';
$push_sw_url = $app_scope . 'sw.js';
$push_subscribe_url = $app_scope . 'push-subscribe.php';
$push_unsubscribe_url = $app_scope . 'push-unsubscribe.php';

$page_title = 'Mon Profil';
include __DIR__ . '/includes/header.php';
?>

<section class="page-header">
    <div class="container">
        <h1>Mon Profil</h1>
    </div>
</section>

<section class="section-pad">
<div class="container">

    <div class="row g-4">

        <div class="col-lg-4">
            <div class="card shadow-sm text-center mb-3">
                <div class="card-body py-4">

                    <?php if (!empty($user['photo_path'])): ?>
                        <?php $profilePhotoSrc = emsp_user_photo_src((string) ($user['photo_path'] ?? '')); ?>
                        <img src="<?= h($profilePhotoSrc !== '' ? $profilePhotoSrc : 'assets/images/logo-emsp.png') ?>"
                             class="rounded-circle mb-3 border emsp-profile-avatar-img"
                             onerror="this.src='assets/images/logo-emsp.png';this.onerror=null;"
                             alt="Photo profil">
                    <?php else: ?>
                        <div class="rounded-circle bg-primary d-flex align-items-center
                                    justify-content-center text-white fw-bold mx-auto mb-3 emsp-profile-avatar-fallback">
                            <?= h(emsp_user_initials((string) ($user['first_name'] ?? ''), (string) ($user['last_name'] ?? ''))) ?>
                        </div>
                    <?php endif; ?>

                    <h5 class="fw-bold mb-0">
                        <?= h($user['first_name'] . ' ' . $user['last_name']) ?>
                    </h5>
                    <p class="text-muted small mb-2"><?= h($user['email']) ?></p>

                    <div class="d-inline-block px-3 py-1 rounded-pill mb-3 emsp-profile-badge <?= h($badge_class_map[$user['badge_level']] ?? 'emsp-profile-badge-level-none') ?>">
                        <?= $badge_labels[$user['badge_level']] ?>
                    </div>

                    <div class="row g-2 text-center border-top pt-3">
                        <div class="col-4">
                            <div class="fw-bold fs-5"><?= $docs_approved ?></div>
                            <div class="text-muted emsp-profile-stat-label">Docs</div>
                        </div>
                        <div class="col-4">
                            <div class="fw-bold fs-5"><?= $fav_count ?></div>
                            <div class="text-muted emsp-profile-stat-label">Favoris</div>
                        </div>
                        <div class="col-4">
                            <div class="fw-bold fs-5"><?= $dl_count ?></div>
                            <div class="text-muted emsp-profile-stat-label">TÃ©lÃ©ch.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-header bg-white fw-semibold small">
                    <i class="bi bi-mortarboard me-2"></i>Informations acadÃ©miques
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <tr>
                            <td class="text-muted small ps-3">FiliÃ¨re</td>
                            <td class="small"><?= h($user['filiere_name'] ?? 'â€”') ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted small ps-3">Niveau</td>
                            <td class="small"><?= h($user['licence_name'] ?? 'â€”') ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted small ps-3">Inscription</td>
                            <td class="small">
                                <?= $user['registration_method'] === 'school_email' ? 'Email Ã©cole' : 'Carte Ã©tudiante' ?>
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted small ps-3">Membre depuis</td>
                            <td class="small"><?= date('d/m/Y', strtotime($user['created_at'])) ?></td>
                        </tr>
                        <?php if (!empty($user['last_login_at'])): ?>
                        <tr>
                            <td class="text-muted small ps-3">DerniÃ¨re connexion</td>
                            <td class="small"><?= date('d/m/Y H:i', strtotime($user['last_login_at'])) ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-person-gear me-2 text-primary"></i>Modifier mon profil
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <input type="hidden" name="action" value="update_profile">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">PrÃ©nom</label>
                                <input class="form-control" type="text" name="first_name" autocomplete="given-name"
                                       value="<?= h($user['first_name']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Nom</label>
                                <input class="form-control" type="text" name="last_name" autocomplete="family-name"
                                       value="<?= h($user['last_name']) ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">Photo de profil</label>
                                <input class="form-control" type="file" name="photo" accept=".jpg,.jpeg,.png" capture="environment">
                                <div class="form-text">JPG ou PNG, max 2 Mo.</div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary mt-3">
                            <i class="bi bi-check-lg me-1"></i>Enregistrer
                        </button>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-lock me-2 text-warning"></i>Changer mon mot de passe
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                        <input type="hidden" name="action" value="change_password">

                        <div class="row g-3">
                            <div class="col-12">
                                <label class="form-label fw-semibold">Mot de passe actuel</label>
                                <input class="form-control" type="password" name="current_password" required autocomplete="current-password">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Nouveau mot de passe</label>
                                <input class="form-control" type="password" name="new_password" minlength="8" required autocomplete="new-password">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Confirmer</label>
                                <input class="form-control" type="password" name="confirm_password" minlength="8" required autocomplete="new-password">
                            </div>
                        </div>

                        <button type="submit" class="btn btn-warning mt-3">
                            <i class="bi bi-lock me-1"></i>Modifier le mot de passe
                        </button>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-bell me-2 text-primary"></i>Notifications navigateur
                </div>
                <div class="card-body">
                    <p class="text-muted mb-3">
                        Activez les push web pour recevoir les nouvelles notifications EMSP Docs meme lorsque l'onglet est ferme.
                    </p>
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                        <span id="pushStatusBadge" class="badge text-bg-secondary">Indisponible</span>
                        <span id="pushStatusText" class="small text-muted">Verification de la compatibilite en cours...</span>
                    </div>
                    <?php if ($push_public_key === ''): ?>
                        <div class="alert alert-warning small mb-3">
                            Les cles push ne sont pas encore configurees dans l'administration.
                        </div>
                    <?php endif; ?>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" id="enablePushBtn" class="btn btn-primary" <?= $push_public_key === '' ? 'disabled' : '' ?>>
                            <i class="bi bi-bell-fill me-1"></i>Activer les notifications
                        </button>
                        <button type="button" id="disablePushBtn" class="btn btn-outline-secondary" disabled>
                            <i class="bi bi-bell-slash me-1"></i>Desactiver
                        </button>
                    </div>
                    <div id="pushFeedback" class="small mt-3 text-muted"></div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-md-4">
                    <a href="historique.php" class="card shadow-sm text-decoration-none text-dark h-100">
                        <div class="card-body text-center py-3">
                            <i class="bi bi-clock-history fs-3 text-primary d-block mb-1"></i>
                            <span class="small fw-semibold">Mon historique</span>
                        </div>
                    </a>
                </div>
                <div class="col-md-4">
                    <a href="mes-favoris.php" class="card shadow-sm text-decoration-none text-dark h-100">
                        <div class="card-body text-center py-3">
                            <i class="bi bi-star-fill fs-3 text-warning d-block mb-1"></i>
                            <span class="small fw-semibold">Mes favoris</span>
                        </div>
                    </a>
                </div>
                <div class="col-md-4">
                    <a href="upload.php" class="card shadow-sm text-decoration-none text-dark h-100">
                        <div class="card-body text-center py-3">
                            <i class="bi bi-cloud-upload fs-3 text-success d-block mb-1"></i>
                            <span class="small fw-semibold">DÃ©poser un doc</span>
                        </div>
                    </a>
                </div>
            </div>

        </div>
    </div>
</div>
</section>

<?php
$page_scripts = '<script>
(function () {
    document.addEventListener("DOMContentLoaded", function () {
        var enableBtn = document.getElementById("enablePushBtn");
        var disableBtn = document.getElementById("disablePushBtn");
        var badge = document.getElementById("pushStatusBadge");
        var status = document.getElementById("pushStatusText");
        var feedback = document.getElementById("pushFeedback");
        var publicKey = ' . json_encode($push_public_key) . ';
        var csrfToken = ' . json_encode(generate_csrf_token()) . ';
        var subscribeUrl = ' . json_encode($push_subscribe_url) . ';
        var unsubscribeUrl = ' . json_encode($push_unsubscribe_url) . ';
        var swUrl = ' . json_encode($push_sw_url) . ';
        var swScope = ' . json_encode($app_scope) . ';

        function setBadge(label, className) {
            if (!badge) return;
            badge.textContent = label;
            badge.className = "badge " + className;
        }

        function setText(text) {
            if (status) {
                status.textContent = text;
            }
        }

        function setFeedback(text, tone) {
            if (!feedback) return;
            feedback.className = "small mt-3 " + (tone || "text-muted");
            feedback.textContent = text || "";
        }

        function setButtons(canEnable, canDisable, busy) {
            if (enableBtn) enableBtn.disabled = !!busy || !canEnable;
            if (disableBtn) disableBtn.disabled = !!busy || !canDisable;
        }

        function unsupported(message) {
            setBadge("Indisponible", "text-bg-secondary");
            setText(message);
            setButtons(false, false, false);
        }

        if (!window.emspPush) {
            unsupported("Le module push n est pas charge sur cette page.");
            return;
        }

        if (!("Notification" in window) || !("serviceWorker" in navigator)) {
            unsupported("Votre navigateur ne prend pas en charge les notifications push.");
            return;
        }

        if (!window.isSecureContext) {
            unsupported("Les notifications push exigent HTTPS ou localhost.");
            return;
        }

        if (!publicKey) {
            unsupported("Les cles push ne sont pas encore configurees par l administration.");
            return;
        }

        window.emspPush.setPublicKey(publicKey);
        window.emspPush.setCsrfToken(csrfToken);
        window.emspPush.setEndpoints({
            subscribe: subscribeUrl,
            unsubscribe: unsubscribeUrl,
            serviceWorkerUrl: swUrl,
            serviceWorkerScope: swScope
        });

        function refreshState() {
            setButtons(false, false, true);
            return window.emspPush.getSubscription().then(function (subscription) {
                var permission = window.emspPush.getPermission();

                if (permission === "denied") {
                    setBadge("Bloque", "text-bg-danger");
                    setText("Les notifications sont bloquees par le navigateur.");
                    setFeedback("Autorisez les notifications depuis les reglages du navigateur pour les reactiver.", "text-danger");
                    setButtons(false, !!subscription, false);
                    return;
                }

                if (subscription) {
                    setBadge("Actives", "text-bg-success");
                    setText("Les notifications push sont actives sur cet appareil.");
                    setFeedback("Vous recevrez les nouvelles notifications EMSP Docs directement dans le navigateur.", "text-success");
                    setButtons(false, true, false);
                    return;
                }

                if (permission === "granted") {
                    setBadge("Pret", "text-bg-primary");
                    setText("Le navigateur est autorise, mais cet appareil n est pas encore abonne.");
                } else {
                    setBadge("En attente", "text-bg-warning");
                    setText("Activez les notifications pour recevoir les alertes EMSP Docs.");
                }
                setFeedback("", "text-muted");
                setButtons(true, false, false);
            }).catch(function () {
                unsupported("Impossible de verifier l etat actuel des notifications.");
            });
        }

        if (enableBtn) {
            enableBtn.addEventListener("click", function () {
                setFeedback("Activation en cours...", "text-muted");
                setButtons(false, false, true);

                Promise.resolve().then(function () {
                    if (window.emspPush.getPermission() === "granted") {
                        return "granted";
                    }
                    return Notification.requestPermission();
                }).then(function (permission) {
                    if (permission !== "granted") {
                        throw new Error("permission-denied");
                    }
                    return window.emspPush.getSubscription().then(function (existing) {
                        return existing || window.emspPush.subscribe();
                    });
                }).then(function (subscription) {
                    return window.emspPush.saveSubscription(subscription, "Navigateur personnel");
                }).then(function () {
                    setFeedback("Notifications activees avec succes.", "text-success");
                    return refreshState();
                }).catch(function (error) {
                    if (error && error.message === "permission-denied") {
                        setFeedback("Le navigateur a refuse l autorisation de notification.", "text-danger");
                    } else {
                        setFeedback("Activation impossible pour le moment. Reessayez dans un instant.", "text-danger");
                    }
                    refreshState();
                });
            });
        }

        if (disableBtn) {
            disableBtn.addEventListener("click", function () {
                setFeedback("Desactivation en cours...", "text-muted");
                setButtons(false, false, true);
                window.emspPush.unsubscribe().then(function () {
                    setFeedback("Notifications desactivees pour cet appareil.", "text-muted");
                    return refreshState();
                }).catch(function () {
                    setFeedback("La desactivation a echoue. Reessayez dans un instant.", "text-danger");
                    refreshState();
                });
            });
        }

        refreshState();
    });
})();
</script>';
?>

<?php if (!empty($_SESSION['new_badge'])): ?>
<?php $badge = $_SESSION['new_badge']; unset($_SESSION['new_badge']); ?>
<div class="modal fade" id="badgeModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content text-center p-4">
      <div class="badge-celebration mb-3">
        <?php if ($badge === 'bronze'): ?>
          <div class="emsp-badge-emoji">ðŸ¥‰</div>
          <h3>Badge Bronze dÃ©bloquÃ© !</h3>
          <p>FÃ©licitations ! Votre premier document a Ã©tÃ© approuvÃ©. Continuez Ã  contribuer pour obtenir le badge Argent.</p>
        <?php elseif ($badge === 'argent'): ?>
          <div class="emsp-badge-emoji">ðŸ¥ˆ</div>
          <h3>Badge Argent dÃ©bloquÃ© !</h3>
          <p>Excellent ! Vous avez 5 documents approuvÃ©s. Encore 5 pour atteindre le badge Or !</p>
        <?php elseif ($badge === 'or'): ?>
          <div class="emsp-badge-emoji">ðŸ¥‡</div>
          <h3>Badge Or obtenu !</h3>
          <p>FÃ©licitations ! L'administration vous a rÃ©compensÃ© du badge Or pour votre contribution exceptionnelle.</p>
        <?php endif; ?>
      </div>
      <button class="btn btn-primary" type="button" data-bs-dismiss="modal">
        Super, merci ! ðŸŽ‰
      </button>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var m = new bootstrap.Modal(document.getElementById('badgeModal'));
    m.show();
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>

