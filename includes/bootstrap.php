<?php

include_once __DIR__ . '/encoding.php';
include_once __DIR__ . '/helpers.php';

if (!function_exists('emsp_bootstrap_session')) {
    function emsp_bootstrap_session(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            if (!headers_sent()) {
                session_set_cookie_params([
                    'lifetime' => 0,
                    'path' => '/',
                    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
            }
            session_start();
        }
    }
}

emsp_bootstrap_session();

if (!function_exists('emsp_session_sync_auth_user')) {
    function emsp_session_sync_auth_user(array $user): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $current = $_SESSION['auth_user'] ?? [];
        $_SESSION['auth'] = true;
        $_SESSION['auth_role'] = (string) ($user['role'] ?? ($current['role'] ?? ''));
        $_SESSION['auth_user'] = array_merge($current, [
            'id' => (int) ($user['id'] ?? ($current['id'] ?? 0)),
            'first_name' => (string) ($user['first_name'] ?? ($current['first_name'] ?? '')),
            'last_name' => (string) ($user['last_name'] ?? ($current['last_name'] ?? '')),
            'email' => (string) ($user['email'] ?? ($current['email'] ?? '')),
            'role' => (string) ($user['role'] ?? ($current['role'] ?? '')),
            'status' => (string) ($user['status'] ?? ($current['status'] ?? '')),
            'badge_level' => (string) ($user['badge_level'] ?? ($current['badge_level'] ?? 'none')),
            'photo_path' => (string) ($user['photo_path'] ?? ($current['photo_path'] ?? '')),
        ]);
    }
}

if (!function_exists('emsp_session_set_notif_count')) {
    function emsp_session_set_notif_count(int $count): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION['notif_count'] = max(0, $count);
    }
}

if (!function_exists('emsp_session_get_notif_count')) {
    function emsp_session_get_notif_count(): int
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return 0;
        }
        return max(0, (int) ($_SESSION['notif_count'] ?? 0));
    }
}

if (!function_exists('emsp_session_adjust_notif_count')) {
    function emsp_session_adjust_notif_count(int $delta): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $current = emsp_session_get_notif_count();
        emsp_session_set_notif_count($current + $delta);
    }
}

if (!function_exists('emsp_session_set_notif_sections')) {
    function emsp_session_set_notif_sections(array $counts): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION['notif_sections'] = [
            'journal' => max(0, (int) ($counts['journal'] ?? 0)),
            'media' => max(0, (int) ($counts['media'] ?? 0)),
        ];
    }
}

if (!function_exists('emsp_session_get_notif_sections')) {
    function emsp_session_get_notif_sections(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return ['journal' => 0, 'media' => 0];
        }

        $counts = $_SESSION['notif_sections'] ?? [];
        return [
            'journal' => max(0, (int) ($counts['journal'] ?? 0)),
            'media' => max(0, (int) ($counts['media'] ?? 0)),
        ];
    }
}

if (!function_exists('emsp_session_adjust_notif_section')) {
    function emsp_session_adjust_notif_section(string $section, int $delta): void
    {
        $section = strtolower(trim($section));
        if (!in_array($section, ['journal', 'media'], true)) {
            return;
        }

        $counts = emsp_session_get_notif_sections();
        $counts[$section] = max(0, (int) ($counts[$section] ?? 0) + $delta);
        emsp_session_set_notif_sections($counts);
    }
}


