<?php
// ------------------------------------------------------------
// Configuration Brevo (centralisÃ©e)
// ------------------------------------------------------------
include_once __DIR__ . '/../admin/config/config.php';
include_once __DIR__ . '/helpers.php';

if (!defined('BREVO_API_KEY')) {
    define('BREVO_API_KEY', '');
}
if (!defined('BREVO_FROM_EMAIL')) {
    define('BREVO_FROM_EMAIL', 'noreply@emsp.int');
}
if (!defined('BREVO_FROM_NAME')) {
    define('BREVO_FROM_NAME', 'EMSP Docs');
}
if (!defined('BREVO_SENDER_EMAIL')) {
    define('BREVO_SENDER_EMAIL', BREVO_FROM_EMAIL);
}
if (!defined('BREVO_SENDER_NAME')) {
    define('BREVO_SENDER_NAME', BREVO_FROM_NAME);
}
if (!defined('APP_URL')) {
    define('APP_URL', defined('BASE_URL') ? BASE_URL : '');
}
if (!defined('APP_NAME')) {
    define('APP_NAME', 'EMSP Docs');
}
if (!defined('SCHOOL_EMAIL_DOMAIN')) {
    define('SCHOOL_EMAIL_DOMAIN', '@emsp.int');
}
if (!defined('APP_ENV')) {
    define('APP_ENV', 'development');
}

if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        if ($needle === '') { return true; }
        $len = strlen($needle);
        return substr($haystack, -$len) === $needle;
    }
}

function brevo_ensure_email_log_table($con): bool {
    static $checked = false;
    if ($checked || !$con) {
        return (bool) $con;
    }
    $checked = true;

    $res = @mysqli_query($con, "SHOW TABLES LIKE 'email_log'");
    if ($res && mysqli_num_rows($res) > 0) {
        return true;
    }

    $sql = "CREATE TABLE IF NOT EXISTS email_log (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NULL,
        email_to VARCHAR(190) NOT NULL,
        subject VARCHAR(190) NOT NULL,
        template_name VARCHAR(64) NOT NULL,
        status VARCHAR(20) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_user_id (user_id),
        KEY idx_status (status),
        KEY idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    @mysqli_query($con, $sql);
    return true;
}

function brevo_recent_email_exists(?mysqli $con, int $user_id, string $template_name, int $minutes = 5): bool
{
    if (!$con || $user_id <= 0 || $template_name === '') {
        return false;
    }
    if (!brevo_ensure_email_log_table($con)) {
        return false;
    }

    $stmt = mysqli_prepare(
        $con,
        "SELECT 1
         FROM email_log
         WHERE user_id = ?
           AND template_name = ?
           AND status = 'sent'
           AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
         LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'isi', $user_id, $template_name, $minutes);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_store_result($stmt);
    $exists = mysqli_stmt_num_rows($stmt) > 0;
    mysqli_stmt_close($stmt);
    return $exists;
}

function brevo_app_url(): string {
    $app = rtrim((string) APP_URL, '/');
    if ($app !== '') {
        return $app;
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if (stripos($host, 'localhost') !== false || stripos($host, '127.0.0.1') !== false) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
        if ($base === '/' || $base === '\\') { $base = ''; }
        if (substr($base, -6) === '/admin') { $base = substr($base, 0, -6); }
        return $scheme . '://' . $host . $base;
    }

    error_log('EMSP WARNING: APP_URL non dÃ©fini en production');
    return '';
}

// Styles communs pour les templates
function brevo_base_styles(): string {
    return 'body{margin:0;padding:24px 12px;background:linear-gradient(180deg,#f5f8fc 0%,#eef4f8 100%);font-family:Inter,Arial,sans-serif;color:#1f2a37;}'
        . '.wrap{max-width:560px;margin:0 auto;background:#fff;border-radius:22px;overflow:hidden;border:1px solid #dbe5f0;box-shadow:0 18px 42px rgba(18,39,74,.08);}'
        . '.header{padding:1.85rem 2rem;text-align:center;background:linear-gradient(135deg,#10274a 0%,#1a3c6e 58%,#086136 100%);}'
        . '.header-logo{color:#fff;font-size:1.32rem;font-weight:800;letter-spacing:-.02em;}'
        . '.eyebrow{display:inline-flex;align-items:center;gap:.35rem;padding:.35rem .8rem;border-radius:999px;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.18);color:#fff;font-size:.76rem;font-weight:700;margin-bottom:.8rem;}'
        . '.body{padding:2rem;}'
        . 'h2{color:#10274a;font-size:1.28rem;font-weight:800;margin:0 0 .85rem;}'
        . 'p{color:#52637a;font-size:.95rem;line-height:1.75;margin:0 0 1rem;}'
        . '.panel{padding:1rem 1.05rem;border-radius:16px;background:#f7fbff;border:1px solid #dbe5f0;margin:1rem 0;}'
        . '.panel-copy{margin:0;}'
        . '.btn{display:inline-block;background:#086136;color:#fff;padding:.78rem 1.5rem;border-radius:12px;font-weight:800;text-decoration:none;font-size:.92rem;margin:1rem 0 0;box-shadow:0 14px 28px rgba(8,97,54,.18);}'
        . '.btn-secondary{background:#e65100;box-shadow:0 14px 28px rgba(230,81,0,.16);}'
        . '.footer{background:#f8fbfe;padding:1rem 2rem;text-align:center;font-size:.75rem;color:#7d8ea8;border-top:1px solid #e8edf2;}';
}

// ------------------------------------------------------------
// Envoi via API Brevo (Silencieux pour Ã©viter de bloquer l'inscription)
// ------------------------------------------------------------
function brevo_send_email(string $to_email, string $to_name, string $subject, string $html_body, ?int $user_id = null, string $tpl_name = 'custom'): bool {
    $api_key = trim((string) BREVO_API_KEY);
    $senderEmail = trim((string) BREVO_SENDER_EMAIL);
    $senderName = function_exists('emsp_fix_mojibake') ? emsp_fix_mojibake((string) BREVO_SENDER_NAME) : (string) BREVO_SENDER_NAME;
    $to_name = function_exists('emsp_fix_mojibake') ? emsp_fix_mojibake($to_name) : $to_name;
    $subject = function_exists('emsp_fix_mojibake') ? emsp_fix_mojibake($subject) : $subject;
    $html_body = function_exists('emsp_fix_mojibake') ? emsp_fix_mojibake($html_body) : $html_body;
    if (
        $api_key === ''
        || !function_exists('curl_init')
        || !filter_var($senderEmail, FILTER_VALIDATE_EMAIL)
        || !filter_var($to_email, FILTER_VALIDATE_EMAIL)
    ) {
        error_log('EMSP BREVO: configuration invalide or cURL unavailable for template ' . $tpl_name);
        return false;
    }

    $payload = json_encode([
        'sender'      => ['email' => $senderEmail, 'name' => $senderName],
        'to'          => [['email' => $to_email, 'name' => $to_name]],
        'subject'     => $subject,
        'htmlContent' => $html_body,
    ]);

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    if ($ch === false) {
        error_log('EMSP BREVO: curl_init failed for template ' . $tpl_name);
        return false;
    }
    $ssl_verify = (APP_ENV === 'production');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => $ssl_verify,
        CURLOPT_SSL_VERIFYHOST => $ssl_verify ? 2 : 0,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'api-key: ' . $api_key,
        ],
    ]);

    $resp = curl_exec($ch);
    $curl_err = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // --- CRÃ‰ATION DU LOG D'ERREUR DANS UN FICHIER TEXTE ---
    // Ce fichier 'debug_email.txt' apparaÃ®tra dans ton FTP
    $log_data = date('[Y-m-d H:i:s]') . " | To: $to_email | HTTP: $http_code | Err: $curl_err | Resp: $resp\n";
    if (EMSP_DEBUG_EMAIL) {
        $log_file = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'emsp_email.log';
        @file_put_contents($log_file, $log_data, FILE_APPEND);
    }

    $ok = ($http_code >= 200 && $http_code < 300);

    // Enregistrement en base de donnÃ©es
    global $con;
    if ($con && $user_id && brevo_ensure_email_log_table($con)) {
        $status = $ok ? 'sent' : 'failed';
        $stmt = mysqli_prepare($con, "INSERT INTO email_log (user_id, email_to, subject, template_name, status) VALUES (?,?,?,?,?)");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'issss', $user_id, $to_email, $subject, $tpl_name, $status);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }

    return $ok;
}

// ------------------------------------------------------------
// Templates & Raccourcis
// ------------------------------------------------------------

function brevo_tpl_verification($prenom, $nom, $url) {
    $css = brevo_base_styles();
    $prenom = h((string) $prenom);
    $url = h((string) $url);
    return "<html><head><meta charset='UTF-8'><style>$css</style></head><body><div class='wrap'><div class='header'><div class='eyebrow'>AccÃ¨s Ã©tudiant EMSP</div><div class='header-logo'>EMSP Docs</div></div><div class='body'><h2>VÃ©rifiez votre email</h2><p>Bonjour $prenom,</p><p>Votre inscription avance bien. Il ne reste plus qu'une Ã©tape : confirmer votre adresse email pour sÃ©curiser votre compte.</p><div class='panel'><p class='panel-copy'>Cliquez sur le bouton ci-dessous pour confirmer votre inscription.</p></div><a href='$url' class='btn'>Confirmer mon email</a></div><div class='footer'>EMSP Docs - Plateforme acadÃ©mique Ã©tudiante</div></div></body></html>";
}

function brevo_send_verification($user_id, $email, $prenom, $nom, $token) {
    $verify_url = brevo_app_url() . '/verify-email.php?token=' . urlencode($token);
    $html = brevo_tpl_verification($prenom, $nom, $verify_url);
    return brevo_send_email($email, "$prenom $nom", "VÃ©rifiez votre email", $html, $user_id, 'verification');
}

function brevo_tpl_password_reset($prenom, $url) {
    $css = brevo_base_styles();
    $prenom = h((string) $prenom);
    $url = h((string) $url);
    return "<html><head><meta charset='UTF-8'><style>$css</style></head><body><div class='wrap'><div class='header'><div class='eyebrow'>SÃ©curitÃ© EMSP Docs</div><div class='header-logo'>EMSP Docs</div></div><div class='body'><h2>RÃ©initialiser votre mot de passe</h2><p>Bonjour $prenom,</p><p>Vous avez demandÃ© la rÃ©initialisation de votre mot de passe. Le lien ci-dessous reste valable pendant 1 heure.</p><div class='panel'><p class='panel-copy'>Si vous n'Ãªtes pas Ã  l'origine de cette demande, ignorez simplement cet email.</p></div><a href='$url' class='btn'>Choisir un nouveau mot de passe</a></div><div class='footer'>EMSP Docs - Ce message a Ã©tÃ© envoyÃ© automatiquement</div></div></body></html>";
}

function brevo_send_password_reset($user_id, $email, $prenom, $nom, $token) {
    $reset_url = brevo_app_url() . '/reset-password.php?token=' . urlencode($token);
    $html = brevo_tpl_password_reset($prenom, $reset_url);
    return brevo_send_email($email, "$prenom $nom", "RÃ©initialiser votre mot de passe", $html, $user_id, 'password_reset');
}

function brevo_tpl_account_approved($prenom) {
    $css = brevo_base_styles();
    $prenom = h((string) $prenom);
    return "<html><head><meta charset='UTF-8'><style>$css</style></head><body><div class='wrap'><div class='header'><div class='eyebrow'>Validation de compte</div><div class='header-logo'>EMSP Docs</div></div><div class='body'><h2>Compte activÃ©</h2><p>Bonjour $prenom,</p><p>Votre compte a Ã©tÃ© validÃ© par l'administration. Vous pouvez maintenant vous connecter et accÃ©der Ã  la plateforme.</p><a href='" . brevo_app_url() . "/login.php' class='btn'>Se connecter</a></div><div class='footer'>EMSP Docs - AccÃ¨s validÃ©</div></div></body></html>";
}

function brevo_send_account_approved($user_id, $email, $prenom, $nom) {
    $html = brevo_tpl_account_approved($prenom);
    return brevo_send_email($email, "$prenom $nom", "Votre compte EMSP Docs est activÃ©", $html, $user_id, 'account_approved');
}

function brevo_tpl_account_rejected($prenom, $motif) {
    $css = brevo_base_styles();
    $prenom = h((string) $prenom);
    $motif = ($motif !== null && $motif !== '') ? h((string) $motif) : '';
    $motif_html = $motif ? "<p><strong>Motif :</strong> " . $motif . "</p>" : "";
    return "<html><head><meta charset='UTF-8'><style>$css</style></head><body><div class='wrap'><div class='header'><div class='eyebrow'>Suivi d'inscription</div><div class='header-logo'>EMSP Docs</div></div><div class='body'><h2>Demande refusÃ©e</h2><p>Bonjour $prenom,</p><p>Votre demande d'inscription n'a pas Ã©tÃ© validÃ©e.</p>$motif_html<p>Si vous pensez qu'il s'agit d'une erreur, contactez l'administration.</p></div><div class='footer'>EMSP Docs - Information de compte</div></div></body></html>";
}

function brevo_send_account_rejected($user_id, $email, $prenom, $nom, $motif) {
    $html = brevo_tpl_account_rejected($prenom, $motif);
    return brevo_send_email($email, "$prenom $nom", "Demande d'inscription refusÃ©e", $html, $user_id, 'account_rejected');
}

function brevo_tpl_doc_approved($prenom, $doc_title) {
    $css = brevo_base_styles();
    $prenom = h((string) $prenom);
    $doc_html = h((string) $doc_title);
    return "<html><head><meta charset='UTF-8'><style>$css</style></head><body><div class='wrap'><div class='header'><div class='eyebrow'>DÃ©pÃ´t validÃ©</div><div class='header-logo'>EMSP Docs</div></div><div class='body'><h2>Document approuvÃ©</h2><p>Bonjour $prenom,</p><p>Votre document <strong>$doc_html</strong> a Ã©tÃ© approuvÃ© et est maintenant visible dans la bibliothÃ¨que.</p><a href='" . brevo_app_url() . "/bibliotheque.php' class='btn'>Voir la bibliothÃ¨que</a></div><div class='footer'>EMSP Docs - BibliothÃ¨que Ã©tudiante</div></div></body></html>";
}

function brevo_send_doc_approved($user_id, $email, $prenom, $nom, $doc_title) {
    $html = brevo_tpl_doc_approved($prenom, $doc_title);
    return brevo_send_email($email, "$prenom $nom", "Votre document a Ã©tÃ© approuvÃ©", $html, $user_id, 'doc_approved');
}

function brevo_tpl_doc_rejected($prenom, $doc_title, $motif) {
    $css = brevo_base_styles();
    $prenom = h((string) $prenom);
    $doc_html = h((string) $doc_title);
    $motif = ($motif !== null && $motif !== '') ? h((string) $motif) : '';
    $motif_html = $motif ? "<p><strong>Motif :</strong> " . $motif . "</p>" : "";
    return "<html><head><meta charset='UTF-8'><style>$css</style></head><body><div class='wrap'><div class='header'><div class='eyebrow'>DÃ©pÃ´t Ã  corriger</div><div class='header-logo'>EMSP Docs</div></div><div class='body'><h2>Document refusÃ©</h2><p>Bonjour $prenom,</p><p>Votre document <strong>$doc_html</strong> a Ã©tÃ© refusÃ©.</p>$motif_html<p>Vous pouvez le corriger puis le soumettre Ã  nouveau.</p></div><div class='footer'>EMSP Docs - RÃ©vision de dÃ©pÃ´t</div></div></body></html>";
}

function brevo_send_doc_rejected($user_id, $email, $prenom, $nom, $doc_title, $motif) {
    $html = brevo_tpl_doc_rejected($prenom, $doc_title, $motif);
    return brevo_send_email($email, "$prenom $nom", "Votre document a Ã©tÃ© refusÃ©", $html, $user_id, 'doc_rejected');
}


