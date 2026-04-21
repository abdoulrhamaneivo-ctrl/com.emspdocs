<?php

function emsp_trusted_proxy_ips(): array
{
    static $trusted = null;
    if ($trusted !== null) {
        return $trusted;
    }

    $raw = '';
    if (defined('TRUSTED_PROXY_IPS')) {
        $raw = (string) TRUSTED_PROXY_IPS;
    } elseif (isset($_ENV['TRUSTED_PROXY_IPS'])) {
        $raw = (string) $_ENV['TRUSTED_PROXY_IPS'];
    }

    $trusted = [];
    foreach (explode(',', $raw) as $ip) {
        $ip = trim($ip);
        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
            $trusted[] = $ip;
        }
    }

    return array_values(array_unique($trusted));
}

function emsp_client_ip(): string
{
    $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($remote === '' || !filter_var($remote, FILTER_VALIDATE_IP)) {
        $remote = '0.0.0.0';
    }

    // FIX: sur mutualise, on ignore X-Forwarded-For tant que le proxy n'est pas explicitement de confiance.
    if (!in_array($remote, emsp_trusted_proxy_ips(), true)) {
        return $remote;
    }

    $forwarded = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($forwarded !== '') {
        foreach (explode(',', $forwarded) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }
    }

    return $remote;
}

function rate_limit_check(string $ip, object $con): array
{
    $blocked = false;
    $retry_in = 0;

    if ($ip === '') {
        return ['blocked' => false, 'retry_in' => 0];
    }

    $stmt = mysqli_prepare($con, "SELECT attempts, UNIX_TIMESTAMP(last_attempt) FROM login_attempts WHERE ip=? LIMIT 1");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $ip);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $attempts, $last_ts);
        if (mysqli_stmt_fetch($stmt)) {
            $attempts = (int) $attempts;
            $last_ts = (int) $last_ts;
            if ($attempts >= 5) {
                $delta = time() - $last_ts;
                if ($delta < 900) {
                    $blocked = true;
                    $retry_in = 900 - $delta;
                }
            }
        }
        mysqli_stmt_close($stmt);
    }

    return ['blocked' => $blocked, 'retry_in' => max(0, (int) $retry_in)];
}

function rate_limit_record_failure(string $ip, object $con): void
{
    if ($ip === '') {
        return;
    }

    $stmt = mysqli_prepare(
        $con,
        "INSERT INTO login_attempts (ip, attempts) VALUES (?, 1)
         ON DUPLICATE KEY UPDATE attempts = attempts + 1"
    );
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $ip);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

function rate_limit_clear(string $ip, object $con): void
{
    if ($ip === '') {
        return;
    }

    $stmt = mysqli_prepare($con, "DELETE FROM login_attempts WHERE ip=?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $ip);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}


