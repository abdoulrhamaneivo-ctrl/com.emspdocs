<?php
include_once __DIR__ . '/includes/bootstrap.php';
include_once __DIR__ . '/admin/config/config.php';

if (isset($_GET['debug']) && $_GET['debug'] === '1' && (!defined('APP_ENV') || APP_ENV !== 'production')) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
}

$alreadyAuth = !empty($_SESSION['auth']) || !empty($_SESSION['auth_user']['id']);
if ($alreadyAuth) {
    header('Location: dashboard.php');
    exit;
}

$page_title = 'Inscription';
include_once __DIR__ . '/admin/config/dbcon.php';
include_once __DIR__ . '/includes/csrf.php';
include_once __DIR__ . '/includes/flash.php';

$errors = $_SESSION['form_errors'] ?? [];
$old = $_SESSION['form_old'] ?? [];
$global_error = '';
unset($_SESSION['form_errors'], $_SESSION['form_old']);

// FIX: escapement centralisÃ© des messages, avec allowlist stricte pour les liens d'erreur email.
function emsp_register_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function emsp_register_error_html($message, bool $allow_links = false): string
{
    $message = (string) $message;
    if ($message === '') {
        return '';
    }

    if (!$allow_links || stripos($message, '<a') === false) {
        return emsp_register_escape($message);
    }

    $allowed_hrefs = ['login.php', 'index.php?open_login=1', 'forgot-password.php'];
    $placeholders = [];
    $index = 0;

    $message = preg_replace_callback(
        '~<a\b[^>]*href\s*=\s*(["\'])([^"\']+)\1[^>]*>(.*?)</a>~is',
        function (array $matches) use ($allowed_hrefs, &$placeholders, &$index): string {
            $href = trim(html_entity_decode((string) ($matches[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $label = trim(strip_tags((string) ($matches[3] ?? '')));

            if ($label === '' || !in_array($href, $allowed_hrefs, true)) {
                return $label;
            }

            $key = '__EMSP_REGISTER_LINK_' . $index++ . '__';
            $placeholders[$key] = '<a href="' . emsp_register_escape($href) . '">' . emsp_register_escape($label) . '</a>';
            return $key;
        },
        $message
    ) ?? $message;

    $message = strip_tags($message);
    $message = emsp_register_escape($message);

    return strtr($message, $placeholders);
}

$filieres = [];
$licences = [];
$studentCount = 0;
$studentCountAvailable = false;
$has_db = (isset($con) && $con instanceof mysqli);
if ($has_db) {
    $res = mysqli_query($con, "SELECT id, name FROM filieres WHERE status='active' ORDER BY name");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $filieres[] = $row;
        }
    } else {
        $global_error = 'RÃ©fÃ©rentiels indisponibles. VÃ©rifiez la base de donnÃ©es.';
    }

    $res = mysqli_query($con, "SELECT id, name FROM licences WHERE status='active' ORDER BY name");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $licences[] = $row;
        }
    } else {
        $global_error = $global_error ?: 'RÃ©fÃ©rentiels indisponibles. VÃ©rifiez la base de donnÃ©es.';
    }

    $res = mysqli_query($con, "SELECT COUNT(*) AS total FROM users WHERE role='etudiant' AND status IN ('active','pending')");
    if ($res) {
        $studentCount = (int) ((mysqli_fetch_assoc($res)['total'] ?? 0));
        $studentCountAvailable = true;
    }
} else {
    $global_error = 'Connexion base de donnÃ©es indisponible.';
}

$registration_unavailable = !$has_db || empty($filieres) || empty($licences);
if ($registration_unavailable && $global_error === '') {
    $global_error = "Inscription temporairement indisponible. Les rÃ©fÃ©rentiels nÃ©cessaires ne sont pas encore configurÃ©s.";
}

$school_domains = ($has_db && function_exists('emsp_get_school_domains'))
    ? emsp_get_school_domains($con)
    : [];
$school_domains_label = !empty($school_domains)
    ? implode(', ', $school_domains)
    : (defined('SCHOOL_EMAIL_DOMAIN') ? SCHOOL_EMAIL_DOMAIN : '@emsp.int');
$selected_registration_method = (string) ($old['registration_method'] ?? '');
if (!in_array($selected_registration_method, ['school_email', 'manual_card'], true)) {
    $selected_registration_method = '';
}
$registration_method_ui = [
    'school_email' => [
        'summary_title' => 'Inscription par email ecole',
        'summary_copy' => 'Utilise ton adresse academique pour debloquer plus vite ton acces etudiant.',
        'hint_title' => 'Adresse academique attendue',
        'hint_copy' => 'Ton email doit se terminer par ' . $school_domains_label . '.',
        'email_help' => 'Utilise ton adresse se terminant par ' . $school_domains_label . '.',
        'submit_label' => 'Creer mon compte',
        'icon' => 'bi-envelope-check',
        'preview_badge' => 'Mode rapide',
        'preview_title' => 'Activation acceleree via email academique',
        'preview_copy' => 'Ce parcours valide ton compte plus vite avec un domaine officiel EMSP.',
        'preview_points' => [
            'Verification automatique apres confirmation email',
            'Depots et favoris disponibles rapidement',
            'Parcours recommande pour les etudiants actifs',
        ],
    ],
    'manual_card' => [
        'summary_title' => 'Inscription avec carte etudiante',
        'summary_copy' => 'Prepare une photo ou un PDF lisible de ta carte pour la verification admin.',
        'hint_title' => 'Carte etudiante obligatoire',
        'hint_copy' => 'Ajoute une carte en JPG, PNG ou PDF. Le fichier devient requis avec cette methode.',
        'email_help' => 'Utilise une adresse active : la verification passera par ta carte et l email de confirmation.',
        'submit_label' => 'Envoyer ma demande',
        'icon' => 'bi-person-vcard',
        'preview_badge' => 'Mode verification',
        'preview_title' => 'Validation humaine avec carte etudiante',
        'preview_copy' => 'Choisis cette voie si ton adresse academique n est pas encore active.',
        'preview_points' => [
            'Carte etudiante lisible requise (photo ou PDF)',
            'Controle de securite par un moderateur',
            'Acces active des validation terminee',
        ],
    ],
];
$selected_registration_ui = $selected_registration_method !== '' && isset($registration_method_ui[$selected_registration_method])
    ? $registration_method_ui[$selected_registration_method]
    : null;
$login_modal_href = 'index.php?open_login=1';

include __DIR__ . '/includes/header.php';
?>



<section class="section-pad emsp-register-shell">
    <div class="container">
        <div class="emsp-register-banner mb-4">
            <div class="row g-4 align-items-center">
                <div class="col-lg-8">
                    <span class="emsp-register-stat mb-3">
                        <i class="bi bi-people-fill"></i>
                        <?php if ($studentCountAvailable): ?>
                            Rejoins +<?= number_format($studentCount, 0, ',', ' ') ?> Ã©tudiants EMSP sur la plateforme
                        <?php else: ?>
                            Rejoins la communautÃ© EMSP sur la plateforme
                        <?php endif; ?>
                    </span>
                    <h1 class="h2 fw-bold mb-3">CrÃ©e ton compte EMSP Docs et accÃ¨de Ã  la bibliothÃ¨que Ã©tudiante</h1>
                    <p class="mb-0 text-white-50">
                        Inscris-toi pour consulter les documents partagÃ©s, enregistrer tes favoris
                        et contribuer Ã  ton tour.
                    </p>
                    <div class="emsp-register-banner-points">
                        <span class="emsp-register-stat"><i class="bi bi-journal-richtext"></i>Ressources triees par filiere</span>
                        <span class="emsp-register-stat"><i class="bi bi-shield-check"></i>Validation securisee</span>
                        <span class="emsp-register-stat"><i class="bi bi-cloud-upload"></i>Depots et favoris</span>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="text-lg-end">
                        <span class="d-block text-white-50 mb-2">DÃ©jÃ  inscrit ?</span>
                        <a class="btn btn-outline-light emsp-open-login-modal"
                           href="<?= htmlspecialchars($login_modal_href) ?>"
                           data-bs-toggle="modal"
                           data-bs-target="#emspQuickLoginModal"
                           data-emsp-modal-link="1">
                            <i class="bi bi-box-arrow-in-right me-2"></i>Se connecter
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 align-items-start">
            <div class="col-xl-8">
                <div class="emsp-register-card">
                    <div class="mb-4">
                        <h2 class="h3 fw-bold mb-2">Inscription EMSP Docs</h2>
                        <p class="text-muted mb-0">Choisis d abord ta methode, puis complete le formulaire pour debloquer ton acces etudiant.</p>
                    </div>
                    <div class="emsp-register-progress" id="register-progress" aria-hidden="true">
                        <span class="is-active" data-register-step-indicator="1"><strong>1</strong><small>Choix du mode</small></span>
                        <span data-register-step-indicator="2"><strong>2</strong><small>Informations</small></span>
                        <span data-register-step-indicator="3"><strong>3</strong><small>Validation</small></span>
                    </div>

                    <?php if ($global_error): ?>
                        <div class="alert alert-warning"><?= emsp_register_error_html($global_error) ?></div>
                    <?php endif; ?>

                    <?php if ($registration_unavailable): ?>
                        <div class="alert alert-danger">
                            Le parcours reste visible, mais lâ€™inscription est momentanÃ©ment dÃ©sactivÃ©e jusquâ€™au retour des donnÃ©es requises.
                        </div>
                    <?php endif; ?>

                    <div class="emsp-register-method-step<?= $selected_registration_method !== '' ? ' d-none' : '' ?>" id="register-method-step">
                        <span class="emsp-register-method-eyebrow">Etape 1</span>
                        <h3 class="h4 fw-bold mt-3 mb-2">Choisis ta methode d inscription</h3>
                        <p class="text-muted mb-0">Le formulaire complet s ouvre uniquement apres cette selection.</p>

                        <div class="emsp-register-method-grid">
                            <button type="button"
                                    class="emsp-register-method-card<?= $selected_registration_method === 'school_email' ? ' is-active' : '' ?>"
                                    data-method-choice="school_email"
                                    aria-pressed="<?= $selected_registration_method === 'school_email' ? 'true' : 'false' ?>">
                                <span class="emsp-register-method-card-icon">
                                    <i class="bi bi-envelope-check"></i>
                                </span>
                                <span class="emsp-register-method-card-body">
                                    <strong>Email ecole</strong>
                                    <span>Confirme ton inscription avec ton adresse officielle EMSP pour activer plus vite ton acces.</span>
                                    <span class="emsp-register-method-card-meta">
                                        <span class="emsp-register-method-card-pill is-fast"><i class="bi bi-lightning-charge-fill"></i>Activation rapide</span>
                                        <span class="emsp-register-method-card-pill is-neutral"><i class="bi bi-at"></i>Domaine EMSP requis</span>
                                    </span>
                                    <span class="emsp-register-method-card-list">
                                        <span><i class="bi bi-check2-circle"></i>Adresse academique EMSP requise</span>
                                        <span><i class="bi bi-check2-circle"></i>Verification par email de confirmation</span>
                                    </span>
                                </span>
                            </button>

                            <button type="button"
                                    class="emsp-register-method-card<?= $selected_registration_method === 'manual_card' ? ' is-active' : '' ?>"
                                    data-method-choice="manual_card"
                                    aria-pressed="<?= $selected_registration_method === 'manual_card' ? 'true' : 'false' ?>">
                                <span class="emsp-register-method-card-icon">
                                    <i class="bi bi-person-vcard"></i>
                                </span>
                                <span class="emsp-register-method-card-body">
                                    <strong>Validation carte etudiante</strong>
                                    <span>Utilise cette voie si tu dois faire verifier ta carte avant activation du compte.</span>
                                    <span class="emsp-register-method-card-meta">
                                        <span class="emsp-register-method-card-pill is-manual"><i class="bi bi-hourglass-split"></i>Validation 24-48h</span>
                                        <span class="emsp-register-method-card-pill is-neutral"><i class="bi bi-file-earmark"></i>JPG, PNG ou PDF</span>
                                    </span>
                                    <span class="emsp-register-method-card-list">
                                        <span><i class="bi bi-check2-circle"></i>Carte lisible avec nom, photo et promo</span>
                                        <span><i class="bi bi-check2-circle"></i>Validation manuelle par un admin</span>
                                    </span>
                                </span>
                            </button>
                        </div>

                        <div class="invalid-feedback mt-3<?= isset($errors['registration_method']) ? ' d-block' : ' d-none' ?>" id="registration-method-feedback"><?= isset($errors['registration_method']) ? emsp_register_error_html($errors['registration_method']) : '' ?></div>

                        <div class="emsp-register-live-preview" id="register-live-preview" aria-live="polite">
                            <div class="emsp-register-live-preview-head">
                                <span class="emsp-register-live-preview-badge" id="register-live-preview-badge"><?= htmlspecialchars((string) ($selected_registration_ui['preview_badge'] ?? 'Parcours inscription')) ?></span>
                                <span class="emsp-register-live-preview-icon" id="register-live-preview-icon"><i class="bi <?= htmlspecialchars((string) ($selected_registration_ui['icon'] ?? 'bi-stars')) ?>"></i></span>
                            </div>
                            <h4 id="register-live-preview-title"><?= htmlspecialchars((string) ($selected_registration_ui['preview_title'] ?? 'Selectionne une methode pour voir le parcours detaille')) ?></h4>
                            <p id="register-live-preview-copy"><?= htmlspecialchars((string) ($selected_registration_ui['preview_copy'] ?? 'Choisis ton mode d inscription pour debloquer le formulaire et visualiser les prochaines etapes.')) ?></p>
                            <ul id="register-live-preview-points">
                                <?php if (!empty($selected_registration_ui['preview_points']) && is_array($selected_registration_ui['preview_points'])): ?>
                                    <?php foreach ($selected_registration_ui['preview_points'] as $previewPoint): ?>
                                        <li><i class="bi bi-check2-circle"></i><span><?= htmlspecialchars((string) $previewPoint) ?></span></li>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <li><i class="bi bi-check2-circle"></i><span>Choisis une methode pour afficher un parcours personnalise.</span></li>
                                    <li><i class="bi bi-check2-circle"></i><span>Le formulaire devient interactif juste apres la selection.</span></li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>

                    <form action="registercode.php" method="post" enctype="multipart/form-data" data-emsp-submit="1">
                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token(); ?>">
                        <input type="hidden" name="registration_method" id="registration_method" value="<?= htmlspecialchars($selected_registration_method) ?>">

                        <div id="register-form-stage" class="emsp-register-form-stage<?= $selected_registration_method === '' ? ' d-none' : '' ?>">
                        <div class="emsp-register-method-summary mb-4">
                            <div>
                                <span class="emsp-register-method-eyebrow">Etape 2</span>
                                <span class="emsp-register-method-summary-title" id="register-method-summary-title"><?= htmlspecialchars((string) ($selected_registration_ui['summary_title'] ?? '')) ?></span>
                                <p class="text-muted small mb-0 mt-2" id="register-method-summary-copy"><?= htmlspecialchars((string) ($selected_registration_ui['summary_copy'] ?? '')) ?></p>
                            </div>
                            <button type="button" class="btn btn-link p-0 emsp-register-change-method" id="change-registration-method">Changer de methode</button>
                        </div>

                        <div class="emsp-register-method-hint mb-4<?= $selected_registration_ui ? '' : ' d-none' ?>" id="register-method-hint">
                            <span class="emsp-register-method-hint-icon" id="register-method-hint-icon">
                                <i class="bi <?= htmlspecialchars((string) ($selected_registration_ui['icon'] ?? 'bi-info-circle')) ?>"></i>
                            </span>
                            <div>
                                <strong id="register-method-hint-title"><?= htmlspecialchars((string) ($selected_registration_ui['hint_title'] ?? '')) ?></strong>
                                <div class="text-muted small" id="register-method-hint-copy"><?= htmlspecialchars((string) ($selected_registration_ui['hint_copy'] ?? '')) ?></div>
                            </div>
                        </div>

                        <fieldset<?= $registration_unavailable ? ' disabled' : '' ?>>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold" for="first_name">PrÃ©nom</label>
                                <input class="form-control <?= isset($errors['first_name']) ? 'is-invalid' : '' ?>" type="text" id="first_name" name="first_name" value="<?= htmlspecialchars($old['first_name'] ?? '') ?>" required autocomplete="given-name">
                                <?php if (isset($errors['first_name'])): ?>
                                    <div class="invalid-feedback"><?= emsp_register_error_html($errors['first_name']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold" for="last_name">Nom</label>
                                <input class="form-control <?= isset($errors['last_name']) ? 'is-invalid' : '' ?>" type="text" id="last_name" name="last_name" value="<?= htmlspecialchars($old['last_name'] ?? '') ?>" required autocomplete="family-name">
                                <?php if (isset($errors['last_name'])): ?>
                                    <div class="invalid-feedback"><?= emsp_register_error_html($errors['last_name']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold" for="email">Email</label>
                                <input class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>" type="email" id="email" name="email" value="<?= htmlspecialchars($old['email'] ?? '') ?>" required autocomplete="email">
                                <?php if (isset($errors['email'])): ?>
                                    <div class="invalid-feedback"><?= emsp_register_error_html($errors['email'], true) ?></div>
                                <?php endif; ?>
                                <div id="email-live-feedback" class="invalid-feedback d-none"></div>
                                <div id="email-method-help" class="form-text<?= empty($selected_registration_ui['email_help']) ? ' d-none' : '' ?>"><?= htmlspecialchars((string) ($selected_registration_ui['email_help'] ?? '')) ?></div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold" for="filiere_id">FiliÃ¨re</label>
                                    <select class="form-select <?= isset($errors['filiere_id']) ? 'is-invalid emsp-select2-invalid' : '' ?>" id="filiere_id" name="filiere_id" data-emsp-select2="1" data-emsp-select2-placeholder="Choisir une filiÃ¨re">
                                        <option value="">Choisir une filiÃ¨re</option>
                                        <?php foreach ($filieres as $f): ?>
                                            <option value="<?= $f['id'] ?>" <?= ((int) ($old['filiere_id'] ?? 0) === (int) $f['id']) ? 'selected' : '' ?>><?= htmlspecialchars($f['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="invalid-feedback<?= isset($errors['filiere_id']) ? ' d-block' : '' ?>" id="filiere-feedback"><?= isset($errors['filiere_id']) ? emsp_register_error_html($errors['filiere_id']) : '' ?></div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold" for="licence_id">Niveau / Licence</label>
                                    <select class="form-select <?= isset($errors['licence_id']) ? 'is-invalid emsp-select2-invalid' : '' ?>" id="licence_id" name="licence_id" data-emsp-select2="1" data-emsp-select2-placeholder="Choisir un niveau">
                                        <option value="">Choisir un niveau</option>
                                        <?php foreach ($licences as $l): ?>
                                            <option value="<?= $l['id'] ?>" <?= ((int) ($old['licence_id'] ?? 0) === (int) $l['id']) ? 'selected' : '' ?>><?= htmlspecialchars($l['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">SÃ©lectionne le niveau correspondant Ã  ta filiÃ¨re.</div>
                                    <div class="invalid-feedback<?= isset($errors['licence_id']) ? ' d-block' : '' ?>" id="licence-feedback"><?= isset($errors['licence_id']) ? emsp_register_error_html($errors['licence_id']) : '' ?></div>
                                </div>
                            <div class="col-12 d-none" id="student_card_block">
                                <label class="form-label fw-semibold" for="student_card">Carte Ã©tudiante (JPG/PNG/PDF, max 5 Mo)</label>
                                <div class="emsp-register-card-upload">
                                    <input class="form-control <?= isset($errors['student_card']) ? 'is-invalid' : '' ?>" type="file" id="student_card" name="student_card" accept=".jpg,.jpeg,.png,.pdf">
                                    <div class="emsp-register-card-upload-note">Ajoute une carte lisible : nom, photo et promo doivent rester visibles.</div>
                                    <?php if (isset($errors['student_card'])): ?>
                                        <div class="invalid-feedback d-block"><?= emsp_register_error_html($errors['student_card']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold" for="password">Mot de passe</label>
                                <input class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>" type="password" id="password" name="password" required autocomplete="new-password">
                                <?php if (isset($errors['password'])): ?>
                                    <div class="invalid-feedback"><?= emsp_register_error_html($errors['password']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold" for="password_confirm">Confirmer le mot de passe</label>
                                <input class="form-control <?= isset($errors['password_confirm']) ? 'is-invalid' : '' ?>" type="password" id="password_confirm" name="password_confirm" required autocomplete="new-password">
                                <?php if (isset($errors['password_confirm'])): ?>
                                    <div class="invalid-feedback"><?= emsp_register_error_html($errors['password_confirm']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <button class="btn btn-emsp btn-lg w-100 mt-4" id="register-submit-button" type="submit" data-loading-text="Envoi de l'inscription..."<?= $registration_unavailable ? ' disabled aria-disabled="true"' : '' ?>>
                            <i class="bi bi-send-check-fill me-2"></i><span id="register-submit-label"><?= htmlspecialchars((string) ($selected_registration_ui['submit_label'] ?? 'Envoyer l inscription')) ?></span>
                        </button>
                        </fieldset>
                        </div>
                    </form>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="emsp-register-aside">
                    <h3 class="h5 fw-bold mb-3">Ce que ton compte debloque</h3>
                    <div class="d-grid gap-3">
                        <div class="rounded-4 border p-3">
                            <div class="d-flex align-items-start gap-3">
                                <span class="emsp-register-aside-icon"><i class="bi bi-journal-bookmark"></i></span>
                                <div>
                                    <strong class="d-block mb-1">Bibliotheque complete</strong>
                                    <span class="text-muted">Retrouve rapidement cours, TD, corrections, examens et concours classes par filiere.</span>
                                </div>
                            </div>
                        </div>
                        <div class="rounded-4 border p-3">
                            <div class="d-flex align-items-start gap-3">
                                <span class="emsp-register-aside-icon"><i class="bi bi-shield-check"></i></span>
                                <div>
                                    <strong class="d-block mb-1">Parcours clair</strong>
                                    <span class="text-muted">Choisis entre email EMSP et verification par carte avant d ouvrir le reste du formulaire.</span>
                                </div>
                            </div>
                        </div>
                        <div class="rounded-4 border p-3">
                            <div class="d-flex align-items-start gap-3">
                                <span class="emsp-register-aside-icon"><i class="bi bi-cloud-arrow-up"></i></span>
                                <div>
                                    <strong class="d-block mb-1">Contribution utile</strong>
                                    <span class="text-muted">Depose tes propres documents une fois ton compte actif et suis leur validation.</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <hr class="my-4">

                    <strong class="d-block mb-2">Avant d envoyer ta demande</strong>
                    <div class="emsp-register-aside-list">
                        <div class="emsp-register-aside-item"><i class="bi bi-check2-circle"></i><span>Verifie que ton email est accessible : un message de confirmation sera envoye.</span></div>
                        <div class="emsp-register-aside-item"><i class="bi bi-check2-circle"></i><span>Si tu choisis la carte etudiante, utilise une image nette ou un PDF lisible.</span></div>
                        <div class="emsp-register-aside-item"><i class="bi bi-check2-circle"></i><span>Une fois actif, tu pourras telecharger, enregistrer et deposer des ressources.</span></div>
                    </div>

                    <hr class="my-4">

                    <strong class="d-block mb-2">Deja inscrit ?</strong>
                    <p class="text-muted mb-0">Reviens directement dans ton espace etudiant pour consulter la bibliotheque. <a class="fw-semibold text-decoration-none emsp-open-login-modal" href="<?= htmlspecialchars($login_modal_href) ?>" data-bs-toggle="modal" data-bs-target="#emspQuickLoginModal" data-emsp-modal-link="1">Se connecter</a></p>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var methodInput = document.getElementById('registration_method');
    var methodGrid = document.querySelector('.emsp-register-method-grid');
    var methodStep = document.getElementById('register-method-step');
    var formStage = document.getElementById('register-form-stage');
    var methodCards = document.querySelectorAll('[data-method-choice]');
    var changeMethodButton = document.getElementById('change-registration-method');
    var summaryTitle = document.getElementById('register-method-summary-title');
    var summaryCopy = document.getElementById('register-method-summary-copy');
    var hintBox = document.getElementById('register-method-hint');
    var hintIcon = document.getElementById('register-method-hint-icon');
    var hintTitle = document.getElementById('register-method-hint-title');
    var hintCopy = document.getElementById('register-method-hint-copy');
    var emailMethodHelp = document.getElementById('email-method-help');
    var cardBlock = document.getElementById('student_card_block');
    var cardInput = document.getElementById('student_card');
    var emailInput = document.getElementById('email');
    var emailLiveFeedback = document.getElementById('email-live-feedback');
    var emailHadServerError = emailInput ? emailInput.classList.contains('is-invalid') : false;
    var firstNameInput = document.getElementById('first_name');
    var form = document.querySelector('form[action="registercode.php"]');
    var methodFeedback = document.getElementById('registration-method-feedback');
    var filiereSelect = document.getElementById('filiere_id');
    var licenceSelect = document.getElementById('licence_id');
    var filiereFeedback = document.getElementById('filiere-feedback');
    var licenceFeedback = document.getElementById('licence-feedback');
    var submitLabel = document.getElementById('register-submit-label');
    var progressSteps = document.querySelectorAll('[data-register-step-indicator]');
    var livePreview = document.getElementById('register-live-preview');
    var livePreviewBadge = document.getElementById('register-live-preview-badge');
    var livePreviewIcon = document.getElementById('register-live-preview-icon');
    var livePreviewTitle = document.getElementById('register-live-preview-title');
    var livePreviewCopy = document.getElementById('register-live-preview-copy');
    var livePreviewPoints = document.getElementById('register-live-preview-points');
    var methodConfig = <?= json_encode($registration_method_ui, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    var methodPreviewTimer = null;
    var methodPreviewIndex = 0;
    var methodPreviewLocked = false;

    function currentMethod() {
        return methodInput ? methodInput.value : '';
    }

    function getSelect2Container(field) {
        if (!field || !field.nextElementSibling) {
            return null;
        }
        return field.nextElementSibling.classList.contains('select2-container') ? field.nextElementSibling : null;
    }

    function clearChoiceFeedback() {
        if (!methodFeedback) {
            return;
        }
        methodFeedback.textContent = '';
        methodFeedback.classList.add('d-none');
        methodFeedback.classList.remove('d-block');
    }

    function showChoiceFeedback(message) {
        if (!methodFeedback) {
            return;
        }
        methodFeedback.textContent = message;
        methodFeedback.classList.remove('d-none');
        methodFeedback.classList.add('d-block');
    }

    function clearSelectValidation(field, feedback) {
        if (!field) {
            return;
        }
        field.classList.remove('is-invalid', 'emsp-select2-invalid');
        var select2 = getSelect2Container(field);
        if (select2) {
            select2.classList.remove('emsp-select2-invalid');
        }
        if (feedback) {
            feedback.classList.remove('d-block');
            if (!feedback.dataset.serverMessage) {
                feedback.textContent = '';
            }
        }
    }

    function invalidateSelect(field, feedback, message) {
        if (!field) {
            return;
        }
        field.classList.add('is-invalid', 'emsp-select2-invalid');
        var select2 = getSelect2Container(field);
        if (select2) {
            select2.classList.add('emsp-select2-invalid');
        }
        if (feedback) {
            feedback.textContent = message;
            feedback.classList.add('d-block');
        }
    }

    function toggleCardField() {
        if (!cardBlock || !cardInput) {
            return;
        }
        var manual = currentMethod() === 'manual_card';
        cardBlock.classList.toggle('d-none', !manual);
        cardInput.required = manual;
        if (!manual) {
            cardInput.value = '';
        }
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function renderPreviewPoints(points) {
        if (!livePreviewPoints) {
            return;
        }
        var safePoints = Array.isArray(points) && points.length ? points : [
            'Selectionne une methode pour voir les etapes en direct.',
            'Le formulaire se debloque automatiquement apres ton choix.'
        ];
        livePreviewPoints.innerHTML = safePoints.map(function (point) {
            return '<li><i class="bi bi-check2-circle"></i><span>' + escapeHtml(point) + '</span></li>';
        }).join('');
    }

    function syncProgress(method, submitted) {
        if (!progressSteps.length) {
            return;
        }
        progressSteps.forEach(function (step) {
            step.classList.remove('is-active', 'is-done');
            var stepNumber = step.getAttribute('data-register-step-indicator');
            if (stepNumber === '1') {
                if (method) {
                    step.classList.add('is-done');
                } else {
                    step.classList.add('is-active');
                }
            }
            if (stepNumber === '2') {
                if (method && !submitted) {
                    step.classList.add('is-active');
                }
                if (submitted) {
                    step.classList.add('is-done');
                }
            }
            if (stepNumber === '3') {
                if (submitted) {
                    step.classList.add('is-active');
                }
            }
        });
    }

    function renderMethodState() {
        var method = currentMethod();
        var config = methodConfig[method] || null;

        methodCards.forEach(function (card) {
            var active = card.getAttribute('data-method-choice') === method;
            card.classList.toggle('is-active', active);
            card.classList.remove('is-preview-focus');
            card.setAttribute('aria-pressed', active ? 'true' : 'false');
        });

        if (methodStep) {
            methodStep.classList.toggle('d-none', method !== '');
        }
        if (formStage) {
            formStage.classList.toggle('d-none', method === '');
        }
        if (summaryTitle) {
            summaryTitle.textContent = config ? config.summary_title : '';
        }
        if (summaryCopy) {
            summaryCopy.textContent = config ? config.summary_copy : '';
        }
        if (hintTitle) {
            hintTitle.textContent = config ? config.hint_title : '';
        }
        if (hintCopy) {
            hintCopy.textContent = config ? config.hint_copy : '';
        }
        if (hintIcon) {
            hintIcon.innerHTML = config ? '<i class="bi ' + config.icon + '"></i>' : '<i class="bi bi-info-circle"></i>';
        }
        if (hintBox) {
            hintBox.classList.toggle('d-none', !config);
            hintBox.style.display = config ? '' : 'none';
        }
        if (emailMethodHelp) {
            emailMethodHelp.textContent = config ? config.email_help : '';
            emailMethodHelp.classList.toggle('d-none', !config || !config.email_help);
        }
        if (submitLabel) {
            submitLabel.textContent = config ? config.submit_label : 'Envoyer l inscription';
        }
        if (livePreviewBadge) {
            livePreviewBadge.textContent = config ? config.preview_badge : 'Parcours inscription';
        }
        if (livePreviewIcon) {
            livePreviewIcon.innerHTML = config ? '<i class="bi ' + config.icon + '"></i>' : '<i class="bi bi-stars"></i>';
        }
        if (livePreviewTitle) {
            livePreviewTitle.textContent = config
                ? config.preview_title
                : 'Selectionne une methode pour voir le parcours detaille';
        }
        if (livePreviewCopy) {
            livePreviewCopy.textContent = config
                ? config.preview_copy
                : 'Choisis ton mode d inscription pour debloquer le formulaire et visualiser les prochaines etapes.';
        }
        renderPreviewPoints(config ? config.preview_points : []);
        syncProgress(method, false);
        if (livePreview) {
            livePreview.classList.remove('is-flash');
            window.requestAnimationFrame(function () {
                livePreview.classList.add('is-flash');
            });
        }

        clearChoiceFeedback();
        toggleCardField();

        if (method) {
            stopMethodPreview(false);
            var activeCard = document.querySelector('.emsp-register-method-card.is-active');
            if (activeCard) {
                scrollCardIntoView(activeCard);
            }
        } else if (!methodPreviewLocked) {
            startMethodPreview();
        }
    }

    function scrollCardIntoView(card) {
        if (!card || !methodGrid || window.innerWidth >= 992) {
            return;
        }
        try {
            card.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
        } catch (error) {
            card.scrollIntoView();
        }
    }

    function highlightPreviewCard(index) {
        if (!methodCards.length || currentMethod()) {
            return;
        }
        methodCards.forEach(function (card, i) {
            card.classList.toggle('is-preview-focus', i === index);
        });
        scrollCardIntoView(methodCards[index] || null);
    }

    function stopMethodPreview(lock) {
        if (methodPreviewTimer) {
            window.clearInterval(methodPreviewTimer);
            methodPreviewTimer = null;
        }
        methodCards.forEach(function (card) {
            card.classList.remove('is-preview-focus');
        });
        if (lock) {
            methodPreviewLocked = true;
        }
    }

    function startMethodPreview() {
        if (methodPreviewLocked || methodCards.length < 2 || currentMethod()) {
            return;
        }
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }
        stopMethodPreview(false);
        highlightPreviewCard(methodPreviewIndex % methodCards.length);
        methodPreviewTimer = window.setInterval(function () {
            if (currentMethod()) {
                stopMethodPreview(false);
                return;
            }
            methodPreviewIndex = (methodPreviewIndex + 1) % methodCards.length;
            highlightPreviewCard(methodPreviewIndex);
        }, 3000);
    }

    function selectMethod(method) {
        if (!methodInput) {
            return;
        }
        methodInput.value = method;
        renderMethodState();
        if (formStage && !formStage.classList.contains('d-none') && typeof formStage.scrollIntoView === 'function') {
            formStage.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        if (firstNameInput) {
            firstNameInput.focus();
        }
    }

    function showEmailApiError(message) {
        if (!emailInput || !emailLiveFeedback) return;
        emailInput.classList.add('is-invalid');
        emailInput.dataset.apiInvalid = '1';
        emailLiveFeedback.textContent = message;
        emailLiveFeedback.classList.remove('d-none');
        emailLiveFeedback.style.display = 'block';
    }

    function clearEmailApiError() {
        if (!emailInput || !emailLiveFeedback) return;
        emailInput.dataset.apiInvalid = '0';
        emailLiveFeedback.textContent = '';
        emailLiveFeedback.classList.add('d-none');
        emailLiveFeedback.style.display = 'none';
        if (!emailHadServerError) {
            emailInput.classList.remove('is-invalid');
        }
    }

    if (methodCards.length > 0) {
        methodCards.forEach(function (card) {
            card.addEventListener('click', function () {
                stopMethodPreview(true);
                selectMethod(this.getAttribute('data-method-choice') || '');
            });
        });
    }

    if (methodGrid) {
        methodGrid.addEventListener('pointerdown', function () {
            stopMethodPreview(true);
        });
        methodGrid.addEventListener('wheel', function () {
            stopMethodPreview(true);
        }, { passive: true });
    }

    if (changeMethodButton) {
        changeMethodButton.addEventListener('click', function () {
            if (methodInput) {
                methodInput.value = '';
            }
            methodPreviewLocked = false;
            methodPreviewIndex = 0;
            clearEmailApiError();
            renderMethodState();
            startMethodPreview();
            if (methodCards.length > 0) {
                methodCards[0].focus();
            }
        });
    }

    if (filiereFeedback && filiereFeedback.textContent.trim() !== '') {
        filiereFeedback.dataset.serverMessage = '1';
    }
    if (licenceFeedback && licenceFeedback.textContent.trim() !== '') {
        licenceFeedback.dataset.serverMessage = '1';
    }
    if (methodFeedback && methodFeedback.textContent.trim() !== '') {
        methodFeedback.dataset.serverMessage = '1';
    }

    if (filiereSelect) {
        filiereSelect.addEventListener('change', function () {
            if (this.value) {
                clearSelectValidation(this, filiereFeedback);
            }
        });
    }

    if (licenceSelect) {
        licenceSelect.addEventListener('change', function () {
            if (this.value) {
                clearSelectValidation(this, licenceFeedback);
            }
        });
    }

    if (form) {
        form.addEventListener('submit', function (event) {
            var hasErrors = false;

            clearChoiceFeedback();
            clearSelectValidation(filiereSelect, filiereFeedback);
            clearSelectValidation(licenceSelect, licenceFeedback);
            syncProgress(currentMethod(), true);

            if (!currentMethod()) {
                hasErrors = true;
                showChoiceFeedback("Choisis d'abord une methode d'inscription.");
                if (methodStep) {
                    methodStep.classList.remove('d-none');
                }
                if (formStage) {
                    formStage.classList.add('d-none');
                }
            }

            if (filiereSelect && !String(filiereSelect.value || '').trim()) {
                hasErrors = true;
                invalidateSelect(filiereSelect, filiereFeedback, 'Veuillez choisir une filiere.');
            }

            if (licenceSelect && !String(licenceSelect.value || '').trim()) {
                hasErrors = true;
                invalidateSelect(licenceSelect, licenceFeedback, 'Veuillez choisir un niveau.');
            }

            if (hasErrors) {
                event.preventDefault();
                syncProgress(currentMethod(), false);
                var firstError = methodFeedback && !methodFeedback.classList.contains('d-none')
                    ? methodStep
                    : (filiereSelect && filiereSelect.classList.contains('is-invalid') ? (getSelect2Container(filiereSelect) || filiereSelect)
                    : (licenceSelect && licenceSelect.classList.contains('is-invalid') ? (getSelect2Container(licenceSelect) || licenceSelect) : null));
                if (firstError && typeof firstError.scrollIntoView === 'function') {
                    firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                return;
            }
        });
    }

    renderMethodState();
    startMethodPreview();

    if (emailInput) {
        emailInput.addEventListener('input', function () {
            clearEmailApiError();
        });

        emailInput.addEventListener('blur', async function () {
            var email = this.value.trim();
            if (!email.includes('@')) {
                clearEmailApiError();
                return;
            }

            try {
                var res = await fetch('https://www.disify.com/api/email/' + encodeURIComponent(email));
                if (!res.ok) {
                    return;
                }
                var data = await res.json();
                if (data && data.disposable) {
                    showEmailApiError('Les emails jetables ne sont pas acceptÃ©s.');
                } else {
                    clearEmailApiError();
                }
            } catch (e) {
                // silencieux si l'API est indisponible
            }
        });
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>

