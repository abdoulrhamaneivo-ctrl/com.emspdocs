<?php
ob_start();
include_once __DIR__ . '/bootstrap.php';
include_once __DIR__ . '/config/dbcon.php';
include_once __DIR__ . '/authentication.php';

// Seul admin et modÃ©rateur peuvent voir les cartes
if (!in_array($auth_user['role'], ['admin','moderateur'])) {
    http_response_code(403); exit(0);
}

$user_id = intval($_GET['user_id'] ?? 0);
if ($user_id <= 0) {
    http_response_code(400); exit(0);
}

// RÃ©cupÃ©rer le chemin de la carte
$stmt = mysqli_prepare($con,
    "SELECT student_card_path FROM users WHERE id=? LIMIT 1");
if (!$stmt) {
    http_response_code(500); exit(0);
}
mysqli_stmt_bind_param($stmt, 'i', $user_id);
mysqli_stmt_execute($stmt);
$row = emsp_stmt_fetch_assoc($stmt);
mysqli_stmt_close($stmt);

if (!$row || empty($row['student_card_path'])) {
    http_response_code(404); exit(0);
}

$base_dir = realpath(__DIR__ . '/../uploads/student-cards');
$raw_path = str_replace('\\', '/', trim((string) ($row['student_card_path'] ?? '')));
if ($base_dir === false || $raw_path === '') {
    http_response_code(404); exit(0);
}

$prefix = 'uploads/student-cards/';
$prefixPos = strpos($raw_path, $prefix);
if ($prefixPos !== false) {
    $raw_path = substr($raw_path, $prefixPos + strlen($prefix));
}
$raw_path = ltrim($raw_path, '/');

$file_path = realpath($base_dir . DIRECTORY_SEPARATOR . $raw_path);
if (!$file_path || !is_file($file_path) || strpos($file_path, rtrim($base_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0) {
    $file_path = realpath($base_dir . DIRECTORY_SEPARATOR . basename($raw_path));
}
if (!$file_path || !is_file($file_path) || strpos($file_path, rtrim($base_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(404); exit(0);
}

// DÃ©tecter le MIME
$mime  = emsp_detect_mime($file_path);

// Servir le fichier
header('Content-Type: ' . $mime);
$ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
$safeName = 'carte_' . $user_id . ($ext !== '' ? '.' . $ext : '');
header('Content-Disposition: inline; filename="' . $safeName . '"');
header('Cache-Control: private, no-cache');
header('X-Content-Type-Options: nosniff');

readfile($file_path);
exit(0);



