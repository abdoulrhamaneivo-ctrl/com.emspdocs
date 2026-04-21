<?php

declare(strict_types=1);

if (!function_exists('emsp_thumb_column_exists')) {
    function emsp_thumb_column_exists(mysqli $con): bool {
        $res = mysqli_query($con, "SHOW COLUMNS FROM documents LIKE 'thumb_path'");
        if (!$res) return false;
        $ok = mysqli_num_rows($res) > 0;
        mysqli_free_result($res);
        return $ok;
    }
}

if (!function_exists('emsp_ensure_thumb_column')) {
    function emsp_ensure_thumb_column(mysqli $con): void {
        if (emsp_thumb_column_exists($con)) return;
        mysqli_query($con, "ALTER TABLE documents ADD COLUMN thumb_path VARCHAR(255) NULL AFTER file_path");
    }
}

if (!function_exists('emsp_doc_thumb_src')) {
    function emsp_doc_thumb_src(array $doc): string {
        $thumb = trim((string)($doc['thumb_path'] ?? ''));
        if ($thumb !== '') return 'uploads/documents/' . ltrim($thumb, '/');
        $mime = strtolower((string)($doc['mime_type'] ?? ''));
        $file = trim((string)($doc['file_path'] ?? ''));
        if (strpos($mime, 'image/') === 0 && $file !== '') return 'uploads/documents/' . ltrim($file, '/');
        return '';
    }
}