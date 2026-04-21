<?php
include_once __DIR__ . '/config.php';
include_once __DIR__ . '/../../includes/encoding.php';
include_once __DIR__ . '/../../includes/helpers.php';

$db_host = $_ENV['DB_HOST'] ?? 'localhost';
$db_name = $_ENV['DB_NAME'] ?? '';
$db_user = $_ENV['DB_USER'] ?? 'root';
$db_pass = $_ENV['DB_PASS'] ?? '';

mysqli_report(MYSQLI_REPORT_OFF);
$con = @mysqli_connect($db_host, $db_user, $db_pass, $db_name);
if (!$con) {
    // Maintenance: never leak raw mysqli warnings into HTML responses.
    error_log('EMSP DB connection failed: ' . mysqli_connect_error());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }
    die("Erreur de connexion a la base de donnees. Contactez l'administrateur.");
}
mysqli_set_charset($con, 'utf8mb4');
date_default_timezone_set('Africa/Abidjan');
mysqli_query($con, "SET NAMES 'utf8mb4'");
mysqli_query($con, "SET CHARACTER SET utf8mb4");
mysqli_query($con, "SET collation_connection = 'utf8mb4_unicode_ci'");
@mysqli_query($con, "SET time_zone = '+00:00'");

if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['message']) && is_string($_SESSION['message'])) {
    $_SESSION['message'] = emsp_fix_mojibake($_SESSION['message']);
}

if (!function_exists('emsp_detect_mime')) {
    function emsp_detect_mime(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                // finfo resources/objects are released automatically; keeping the
                // flow simple here avoids deprecated cleanup calls on newer PHP.
                $mime = finfo_file($finfo, $path);
                if ($mime) {
                    return $mime;
                }
            }
        }
        if (function_exists('mime_content_type')) {
            $mime = @mime_content_type($path);
            if ($mime) {
                return $mime;
            }
        }
        return '';
    }
}

if (!function_exists('emsp_parse_size_to_bytes')) {
    function emsp_parse_size_to_bytes(string $val): int
    {
        $val = trim($val);
        if ($val === '') {
            return 0;
        }
        $unit = strtolower(substr($val, -1));
        $num = (int) $val;
        if (in_array($unit, ['g', 'm', 'k'], true)) {
            $num = (int) substr($val, 0, -1);
        }
        switch ($unit) {
            case 'g':
                return $num * 1024 * 1024 * 1024;
            case 'm':
                return $num * 1024 * 1024;
            case 'k':
                return $num * 1024;
            default:
                return (int) $val;
        }
    }
}

if (!function_exists('emsp_max_upload_size')) {
    function emsp_max_upload_size(int $cap = 73400320): int
    {
        $u = emsp_parse_size_to_bytes((string) ini_get('upload_max_filesize'));
        $p = emsp_parse_size_to_bytes((string) ini_get('post_max_size'));
        if ($u <= 0) {
            $u = $cap;
        }
        if ($p <= 0) {
            $p = $cap;
        }
        return min($u, $p, $cap);
    }
}

if (!function_exists('emsp_get_school_domains')) {
    function emsp_get_school_domains(mysqli $con): array
    {
        $domains = [];
        // Maintenance: query the business table directly and fall back cleanly if the schema is not deployed yet.
        $stmt = mysqli_prepare(
            $con,
            "SELECT domain FROM school_email_domains WHERE status='active' ORDER BY domain"
        );
        if ($stmt) {
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            while ($row = mysqli_fetch_assoc($result)) {
                $domain = strtolower(trim((string) ($row['domain'] ?? '')));
                if ($domain === '') {
                    continue;
                }
                if ($domain[0] !== '@') {
                    $pos = strpos($domain, '@');
                    $domain = ($pos !== false) ? substr($domain, $pos) : ('@' . $domain);
                }
                $domains[$domain] = true;
            }
            mysqli_stmt_close($stmt);
        }

        if (empty($domains) && defined('SCHOOL_EMAIL_DOMAIN')) {
            $domain = strtolower(trim((string) SCHOOL_EMAIL_DOMAIN));
            if ($domain !== '') {
                if ($domain[0] !== '@') {
                    $pos = strpos($domain, '@');
                    $domain = ($pos !== false) ? substr($domain, $pos) : ('@' . $domain);
                }
                $domains[$domain] = true;
            }
        }

        return array_keys($domains);
    }
}

if (!function_exists('emsp_is_school_email')) {
    function emsp_is_school_email(string $email, array $domains): bool
    {
        $email = strtolower(trim($email));
        if ($email === '' || empty($domains)) {
            return false;
        }
        foreach ($domains as $domain) {
            $domain = strtolower(trim((string) $domain));
            if ($domain === '') {
                continue;
            }
            if ($domain[0] !== '@') {
                $pos = strpos($domain, '@');
                $domain = ($pos !== false) ? substr($domain, $pos) : ('@' . $domain);
            }
            if (str_ends_with($email, $domain)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('emsp_stmt_fetch_assoc')) {
    function emsp_stmt_fetch_assoc(mysqli_stmt $stmt): ?array
    {
        if (function_exists('mysqli_stmt_get_result')) {
            $result = mysqli_stmt_get_result($stmt);
            if (!$result) {
                return null;
            }
            $row = mysqli_fetch_assoc($result);
            mysqli_free_result($result);
            return $row ?: null;
        }

        $meta = mysqli_stmt_result_metadata($stmt);
        if (!$meta) {
            return null;
        }
        $fields = mysqli_fetch_fields($meta);
        $row = [];
        $bind = [];
        foreach ($fields as $field) {
            $row[$field->name] = null;
            $bind[] = &$row[$field->name];
        }
        mysqli_stmt_bind_result($stmt, ...$bind);
        if (!mysqli_stmt_fetch($stmt)) {
            return null;
        }
        $out = [];
        foreach ($row as $key => $value) {
            $out[$key] = $value;
        }
        return $out;
    }
}

if (!function_exists('emsp_stmt_fetch_all')) {
    function emsp_stmt_fetch_all(mysqli_stmt $stmt): array
    {
        $meta = mysqli_stmt_result_metadata($stmt);
        if (!$meta) {
            return [];
        }
        $fields = mysqli_fetch_fields($meta);
        $bind = [];
        $row = [];
        foreach ($fields as $field) {
            $row[$field->name] = null;
            $bind[] = &$row[$field->name];
        }
        mysqli_stmt_bind_result($stmt, ...$bind);
        $rows = [];
        while (mysqli_stmt_fetch($stmt)) {
            $item = [];
            foreach ($row as $key => $value) {
                $item[$key] = $value;
            }
            $rows[] = $item;
        }
        return $rows;
    }
}

// Guard: block non-active student accounts outside their onboarding pages.
if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['auth']) && !empty($_SESSION['auth_user']['id'])) {
    $role = strtolower(trim((string) ($_SESSION['auth_role'] ?? ($_SESSION['auth_user']['role'] ?? ''))));
    if (!in_array($role, ['admin', 'moderateur'], true)) {
        $current = basename($_SERVER['PHP_SELF'] ?? '');
        $allow = [
            'pending-status.php',
            'verify-email.php',
            'resend-verification.php',
            'logout.php',
            'login.php',
            'register.php',
            'forgot-password.php',
            'reset-password.php',
        ];
        if (!in_array($current, $allow, true)) {
            $uid = intval($_SESSION['auth_user']['id']);
            if ($uid > 0) {
                $stmt = mysqli_prepare($con, "SELECT status FROM users WHERE id=? LIMIT 1");
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 'i', $uid);
                    mysqli_stmt_execute($stmt);
                    $row = emsp_stmt_fetch_assoc($stmt);
                    mysqli_stmt_close($stmt);
                    if ($row && in_array($row['status'], ['pending', 'rejected', 'suspended'], true)) {
                        if ($row['status'] === 'pending') {
                            $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
                            if ($base === '/' || $base === '\\') {
                                $base = '';
                            }
                            if (substr($base, -6) === '/admin') {
                                $base = substr($base, 0, -6);
                            }
                            header('Location: ' . ($base !== '' ? $base : '') . '/pending-status.php');
                            exit;
                        }
                        $msg = $row['status'] === 'rejected'
                            ? 'Votre compte a ete refuse.'
                            : 'Votre compte est suspendu.';
                        unset($_SESSION['auth'], $_SESSION['auth_user'], $_SESSION['auth_role']);
                        $_SESSION['message'] = $msg;
                        $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
                        if ($base === '/' || $base === '\\') {
                            $base = '';
                        }
                        if (substr($base, -6) === '/admin') {
                            $base = substr($base, 0, -6);
                        }
                        header('Location: ' . ($base !== '' ? $base : '') . '/login.php');
                        exit;
                    }
                }
            }
        }
    }
}
?>


