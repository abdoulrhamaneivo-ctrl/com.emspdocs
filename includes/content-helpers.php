<?php
include_once __DIR__ . '/helpers.php';

if (!function_exists('emsp_excerpt')) {
    function emsp_excerpt(?string $text, int $max = 140): string
    {
        $clean = trim(strip_tags((string) $text));
        if ($clean === '') {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($clean, 'UTF-8') <= $max) {
                return $clean;
            }
            return rtrim(mb_substr($clean, 0, $max - 1, 'UTF-8')) . '...';
        }

        if (strlen($clean) <= $max) {
            return $clean;
        }
        return rtrim(substr($clean, 0, $max - 1)) . '...';
    }
}

if (!function_exists('emsp_format_date')) {
    function emsp_format_date(?string $date): string
    {
        if (empty($date)) {
            return '';
        }
        $ts = strtotime($date);
        if ($ts === false) {
            return '';
        }
        return date('d/m/Y', $ts);
    }
}

if (!function_exists('emsp_youtube_title')) {
    function emsp_youtube_title(string $url): string
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#(?:youtube\.com|youtu\.be)#i', $url)) {
            return '';
        }

        $api = 'https://www.youtube.com/oembed?url=' . urlencode($url) . '&format=json';
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 3,
                'ignore_errors' => true,
                'user_agent' => 'EMSPDocs/1.0',
            ],
        ]);

        $json = @file_get_contents($api, false, $ctx);
        if (!is_string($json) || trim($json) === '') {
            return '';
        }

        $data = json_decode($json, true);
        $title = trim((string) ($data['title'] ?? ''));

        return function_exists('emsp_fix_mojibake') ? emsp_fix_mojibake($title) : $title;
    }
}


