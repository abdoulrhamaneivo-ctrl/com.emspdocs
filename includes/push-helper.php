<?php

function emsp_ensure_web_push_table(mysqli $con): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    $sql = "CREATE TABLE IF NOT EXISTS web_push_subscriptions (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL,
        endpoint TEXT NOT NULL,
        p256dh VARCHAR(255) NOT NULL,
        auth VARCHAR(255) NOT NULL,
        device_label VARCHAR(120) DEFAULT NULL,
        user_agent VARCHAR(255) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_push_endpoint (endpoint(191)),
        KEY idx_push_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $ready = mysqli_query($con, $sql) === true;
    return $ready;
}

function emsp_push_get_keys(mysqli $con): array
{
    $keys = [
        'public' => getenv('VAPID_PUBLIC_KEY') ?: '',
        'private' => getenv('VAPID_PRIVATE_KEY') ?: '',
    ];

    $res = mysqli_query($con, "SELECT skey, svalue FROM app_settings WHERE skey IN ('push_vapid_public','push_vapid_private')");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            if (!empty($row['skey']) && isset($row['svalue'])) {
                if ($row['skey'] === 'push_vapid_public') {
                    $keys['public'] = trim((string) $row['svalue']);
                }
                if ($row['skey'] === 'push_vapid_private') {
                    $keys['private'] = trim((string) $row['svalue']);
                }
            }
        }
    }

    return $keys;
}

function emsp_push_bootstrap_openssl_conf(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $current = getenv('OPENSSL_CONF');
    if (is_string($current) && $current !== '' && is_file($current)) {
        return;
    }

    $candidates = [
        'C:\\xampp\\php\\extras\\ssl\\openssl.cnf',
        'C:\\xampp\\apache\\conf\\openssl.cnf',
        '/etc/ssl/openssl.cnf',
        '/usr/lib/ssl/openssl.cnf',
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            putenv('OPENSSL_CONF=' . $candidate);
            $_ENV['OPENSSL_CONF'] = $candidate;
            $_SERVER['OPENSSL_CONF'] = $candidate;
            return;
        }
    }
}

function emsp_push_public_key(mysqli $con): string
{
    $keys = emsp_push_get_keys($con);
    return (string) ($keys['public'] ?? '');
}

function emsp_push_enabled(mysqli $con): bool
{
    $keys = emsp_push_get_keys($con);
    if (empty($keys['public']) || empty($keys['private'])) {
        return false;
    }
    return class_exists('\\Minishlink\\WebPush\\WebPush')
        && class_exists('\\Minishlink\\WebPush\\Subscription');
}

function emsp_push_subject(): string
{
    $email = '';
    if (defined('BREVO_FROM_EMAIL')) {
        $email = trim((string) BREVO_FROM_EMAIL);
    }

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'mailto:' . $email;
    }

    if (defined('APP_URL') && trim((string) APP_URL) !== '') {
        return trim((string) APP_URL);
    }

    return 'mailto:contact@emsp.int';
}

function emsp_push_generate_vapid_keys(): ?array
{
    if (!class_exists('\\Minishlink\\WebPush\\VAPID')) {
        return null;
    }

    $lastError = null;
    try {
        emsp_push_bootstrap_openssl_conf();
        return \Minishlink\WebPush\VAPID::createVapidKeys();
    } catch (\Throwable $e) {
        $lastError = $e->getMessage();
    }

    $tempPem = tempnam(sys_get_temp_dir(), 'emsp-vapid-');
    if ($tempPem === false) {
        if ($lastError !== null) {
            error_log('EMSP push VAPID generation failed: ' . $lastError);
        }
        return null;
    }

    $opensslBins = [
        'C:\\xampp\\apache\\bin\\openssl.exe',
        'openssl',
    ];
    $generated = false;

    foreach ($opensslBins as $bin) {
        $command = escapeshellarg($bin) . ' ecparam -name prime256v1 -genkey -noout -out ' . escapeshellarg($tempPem) . ' 2>&1';
        $output = [];
        $code = 1;
        @exec($command, $output, $code);
        if ($code === 0 && is_file($tempPem) && filesize($tempPem) > 0) {
            $generated = true;
            break;
        }
    }

    if (!$generated) {
        @unlink($tempPem);
        if ($lastError !== null) {
            error_log('EMSP push VAPID generation failed: ' . $lastError);
        }
        return null;
    }

    try {
        $validated = \Minishlink\WebPush\VAPID::validate([
            'subject' => emsp_push_subject(),
            'pemFile' => $tempPem,
        ]);

        return [
            'publicKey' => $validated['publicKey'],
            'privateKey' => $validated['privateKey'],
        ];
    } catch (\Throwable $e) {
        error_log('EMSP push PEM fallback failed: ' . $e->getMessage());
        if ($lastError !== null) {
            error_log('EMSP push VAPID generation failed: ' . $lastError);
        }
        return null;
    } finally {
        @unlink($tempPem);
    }
}

function emsp_push_absolute_url(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '/';
    }

    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    $base = '';
    if (defined('APP_URL')) {
        $base = rtrim((string) APP_URL, '/');
    }

    if ($base === '') {
        return $path;
    }

    if ($path[0] !== '/') {
        $path = '/' . $path;
    }

    return $base . $path;
}

function emsp_push_send_to_user(mysqli $con, int $userId, array $payload): int
{
    if ($userId <= 0 || !emsp_push_enabled($con)) {
        return 0;
    }
    if (!emsp_ensure_web_push_table($con)) {
        return 0;
    }
    emsp_push_bootstrap_openssl_conf();

    $keys = emsp_push_get_keys($con);
    $publicKey = $keys['public'];
    $privateKey = $keys['private'];

    $stmt = mysqli_prepare(
        $con,
        "SELECT id, endpoint, p256dh, auth FROM web_push_subscriptions WHERE user_id=?"
    );
    if (!$stmt) {
        return 0;
    }
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $subs = emsp_stmt_fetch_all($stmt);
    mysqli_stmt_close($stmt);
    if (empty($subs)) {
        return 0;
    }

    $webPush = new \Minishlink\WebPush\WebPush([
        'VAPID' => [
            'subject' => emsp_push_subject(),
            'publicKey' => $publicKey,
            'privateKey' => $privateKey,
        ]
    ]);
    $webPush->setReuseVAPIDHeaders(true);

    $sent = 0;
    foreach ($subs as $sub) {
        $subscription = \Minishlink\WebPush\Subscription::create([
            'endpoint' => $sub['endpoint'],
            'publicKey' => $sub['p256dh'],
            'authToken' => $sub['auth'],
        ]);
        $webPush->queueNotification($subscription, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    foreach ($webPush->flush() as $report) {
        if ($report->isSuccess()) {
            $sent++;
            continue;
        }

        $endpoint = '';
        try {
            $endpoint = (string) $report->getRequest()->getUri();
        } catch (\Throwable $e) {
            $endpoint = '';
        }

        if ($endpoint !== '' && method_exists($report, 'isSubscriptionExpired') && $report->isSubscriptionExpired()) {
            $cleanup = mysqli_prepare($con, "DELETE FROM web_push_subscriptions WHERE endpoint=?");
            if ($cleanup) {
                mysqli_stmt_bind_param($cleanup, 's', $endpoint);
                mysqli_stmt_execute($cleanup);
                mysqli_stmt_close($cleanup);
            }
        }
    }

    return $sent;
}

function emsp_push_payload(string $title, string $body, string $url = '/'): array
{
    return [
        'title' => $title,
        'body' => $body,
        'url' => emsp_push_absolute_url($url),
        'icon' => emsp_push_absolute_url('/assets/images/logo-emsp.png'),
        'badge' => emsp_push_absolute_url('/assets/images/logo-emsp.png'),
    ];
}


