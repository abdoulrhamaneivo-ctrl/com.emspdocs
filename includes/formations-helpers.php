<?php
include_once __DIR__ . '/helpers.php';
include_once __DIR__ . '/content-helpers.php';

if (!function_exists('emsp_formations_editorial_columns_present')) {
    function emsp_formations_editorial_columns_present(mysqli $con): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }

        $required = ['summary', 'description_html', 'cover_image_path'];
        foreach ($required as $column) {
            $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
            $result = @mysqli_query($con, "SHOW COLUMNS FROM `filieres` LIKE '" . mysqli_real_escape_string($con, $safe) . "'");
            if (!$result || mysqli_num_rows($result) === 0) {
                if ($result instanceof mysqli_result) {
                    mysqli_free_result($result);
                }
                $ready = false;
                return false;
            }
            mysqli_free_result($result);
        }

        $ready = true;
        return true;
    }
}

if (!function_exists('emsp_formation_image_src')) {
    function emsp_formation_image_src(?string $path): string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return '';
        }

        if (emsp_is_external_url($path)) {
            return $path;
        }

        $path = ltrim(str_replace('\\', '/', $path), '/');
        $src = (strpos($path, 'assets/') === 0 || strpos($path, 'uploads/') === 0)
            ? $path
            : 'uploads/formations/' . basename($path);

        $localPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $src);

        return is_file($localPath) ? $src : '';
    }
}

if (!function_exists('emsp_formation_summary')) {
    function emsp_formation_summary(array $row, int $max = 180): string
    {
        $summary = trim((string) ($row['summary'] ?? ''));
        if ($summary !== '') {
            return function_exists('emsp_fix_mojibake') ? emsp_fix_mojibake($summary) : $summary;
        }

        $description = trim((string) ($row['description_html'] ?? ''));
        if ($description !== '') {
            return function_exists('emsp_excerpt') ? emsp_excerpt($description, $max) : trim(strip_tags($description));
        }

        return 'Découvre les ressources liées à cette filière dans la bibliothèque EMSP.';
    }
}

if (!function_exists('emsp_formation_has_details')) {
    function emsp_formation_has_details(array $row): bool
    {
        return trim((string) ($row['description_html'] ?? '')) !== '';
    }
}
