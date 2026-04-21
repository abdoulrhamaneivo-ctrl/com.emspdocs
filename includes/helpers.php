<?php

if (!function_exists('h')) {
    function h($s): string
    {
        $val = (string) $s;
        if (function_exists('emsp_fix_mojibake')) {
            $val = emsp_fix_mojibake($val);
        }
        return htmlspecialchars($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

// Shared shells (front navbar, admin chrome, profile badges) rely on these
// path helpers, so they must live in the common helper layer, not in an
// optional content-only include.
if (!function_exists('emsp_is_external_url')) {
    function emsp_is_external_url(string $path): bool
    {
        return (bool) preg_match('#^https?://#i', $path);
    }
}

if (!function_exists('emsp_media_src')) {
    function emsp_media_src(string $filePath): string
    {
        $path = trim($filePath);
        if ($path === '') {
            return '';
        }

        if (emsp_is_external_url($path)) {
            return $path;
        }

        $clean = ltrim(str_replace('\\', '/', $path), '/');
        if (strpos($clean, 'uploads/') === 0 || strpos($clean, 'assets/') === 0) {
            return $clean;
        }

        return 'uploads/media/' . $clean;
    }
}

if (!function_exists('emsp_media_path')) {
    function emsp_media_path(?string $path): string
    {
        return emsp_media_src((string) $path);
    }
}

if (!function_exists('emsp_user_photo_src')) {
    function emsp_user_photo_src(?string $photoPath): string
    {
        $path = trim((string) $photoPath);
        if ($path === '') {
            return '';
        }

        if (emsp_is_external_url($path)) {
            return $path;
        }

        $path = ltrim(str_replace('\\', '/', $path), '/');
        if (strpos($path, 'profiles/') === 0) {
            $path = 'uploads/' . $path;
        } elseif (strpos($path, 'uploads/profiles/') !== 0 && strpos($path, 'assets/') !== 0) {
            $path = 'uploads/profiles/' . basename($path);
        }

        $projectRoot = dirname(__DIR__);
        $localCandidate = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (is_file($localCandidate)) {
            return $path;
        }

        return '';
    }
}

if (!function_exists('emsp_user_initials')) {
    function emsp_user_initials(?string $firstName, ?string $lastName, string $fallback = 'EM'): string
    {
        $firstName = trim((string) $firstName);
        $lastName = trim((string) $lastName);
        $initials = '';

        if ($firstName !== '') {
            $initials .= mb_strtoupper(mb_substr($firstName, 0, 1));
        }
        if ($lastName !== '') {
            $initials .= mb_strtoupper(mb_substr($lastName, 0, 1));
        }

        return $initials !== '' ? $initials : $fallback;
    }
}

if (!function_exists('emsp_is_admin_role')) {
    function emsp_is_admin_role(?string $role): bool
    {
        return strtolower(trim((string) $role)) === 'admin';
    }
}

if (!function_exists('emsp_can_assign_user_roles')) {
    function emsp_can_assign_user_roles(array $actor): bool
    {
        return emsp_is_admin_role((string) ($actor['role'] ?? ''));
    }
}

if (!function_exists('emsp_can_manage_user_account')) {
    function emsp_can_manage_user_account(array $actor, array $target): bool
    {
        if (emsp_can_assign_user_roles($actor)) {
            return true;
        }

        // Moderators can manage student/moderator accounts, but never admin accounts.
        return !emsp_is_admin_role((string) ($target['role'] ?? ''));
    }
}

if (!function_exists('emsp_is_ajax_request')) {
    function emsp_is_ajax_request(): bool
    {
        return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    }
}

if (!function_exists('emsp_json_response')) {
    function emsp_json_response(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit(0);
    }
}

if (!function_exists('emsp_ensure_audit_log_table')) {
    function emsp_ensure_audit_log_table(mysqli $con): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }

        $sql = "CREATE TABLE IF NOT EXISTS audit_log (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            admin_id INT NOT NULL,
            action VARCHAR(100) NOT NULL,
            target_type VARCHAR(50) NOT NULL,
            target_id INT NOT NULL DEFAULT 0,
            details TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_audit_admin (admin_id),
            KEY idx_audit_target (target_type, target_id),
            KEY idx_audit_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $ready = mysqli_query($con, $sql) === true;
        return $ready;
    }
}

if (!function_exists('log_audit')) {
    function log_audit(
        mysqli $con,
        int $admin_id,
        string $action,
        string $target_type,
        int $target_id = 0,
        ?string $details = null
    ): void {
        if ($admin_id <= 0 || !emsp_ensure_audit_log_table($con)) {
            return;
        }

        $stmt = mysqli_prepare(
            $con,
            "INSERT INTO audit_log (admin_id, action, target_type, target_id, details) VALUES (?,?,?,?,?)"
        );
        if (!$stmt) {
            return;
        }

        mysqli_stmt_bind_param($stmt, 'issis', $admin_id, $action, $target_type, $target_id, $details);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}


