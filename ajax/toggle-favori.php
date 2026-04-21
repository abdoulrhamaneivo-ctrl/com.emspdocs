<?php
include_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'method', 'message' => 'MÃ©thode non autorisÃ©e.']);
    exit(0);
}

if (empty($_SESSION['auth']) || empty($_SESSION['auth_user']['id'])) {
    echo json_encode(['ok' => false, 'error' => 'auth', 'message' => 'Connectez-vous pour gÃ©rer vos favoris.']);
    exit(0);
}

include_once __DIR__ . '/../admin/config/dbcon.php';

$sessionToken = $_SESSION['csrf_token'] ?? '';
$postToken = $_POST['csrf_token'] ?? '';
if ($sessionToken === '' || $postToken === '' || !hash_equals($sessionToken, $postToken)) {
    echo json_encode(['ok' => false, 'error' => 'csrf', 'message' => 'Jeton de sÃ©curitÃ© invalide. Rechargez la page puis rÃ©essayez.']);
    exit(0);
}

$uid = intval($_SESSION['auth_user']['id']);
$doc_id = intval($_POST['document_id'] ?? 0);
if ($doc_id <= 0) {
    echo json_encode(['ok' => false, 'error' => 'invalid_document', 'message' => 'Document invalide.']);
    exit(0);
}

// Securite: document approuve uniquement
$chk = mysqli_prepare($con, "SELECT id FROM documents WHERE id=? AND status='approved' LIMIT 1");
if (!$chk) {
    echo json_encode(['ok' => false, 'error' => 'sql', 'message' => 'Impossible de vÃ©rifier le document demandÃ©.']);
    exit(0);
}
mysqli_stmt_bind_param($chk, 'i', $doc_id);
mysqli_stmt_execute($chk);
mysqli_stmt_store_result($chk);
$doc_ok = mysqli_stmt_num_rows($chk) > 0;
mysqli_stmt_close($chk);

if (!$doc_ok) {
    echo json_encode(['ok' => false, 'error' => 'doc_not_found', 'message' => 'Document introuvable ou non approuvÃ©.']);
    exit(0);
}

// Toggle favori
$existing = false;
$s = mysqli_prepare($con, "SELECT id FROM favorites WHERE user_id=? AND document_id=? LIMIT 1");
if ($s) {
    mysqli_stmt_bind_param($s, 'ii', $uid, $doc_id);
    mysqli_stmt_execute($s);
    mysqli_stmt_store_result($s);
    $existing = mysqli_stmt_num_rows($s) > 0;
    mysqli_stmt_close($s);
}

if ($existing) {
    $del = mysqli_prepare($con, "DELETE FROM favorites WHERE user_id=? AND document_id=?");
    if ($del) {
        mysqli_stmt_bind_param($del, 'ii', $uid, $doc_id);
        mysqli_stmt_execute($del);
        mysqli_stmt_close($del);
    }
    echo json_encode(['ok' => true, 'action' => 'removed', 'message' => 'Le document a Ã©tÃ© retirÃ© de vos favoris.']);
    exit(0);
}

$ins = mysqli_prepare($con, "INSERT IGNORE INTO favorites (user_id, document_id) VALUES (?, ?)");
if ($ins) {
    mysqli_stmt_bind_param($ins, 'ii', $uid, $doc_id);
    mysqli_stmt_execute($ins);
    mysqli_stmt_close($ins);
}

echo json_encode(['ok' => true, 'action' => 'added', 'message' => 'Le document a Ã©tÃ© ajoutÃ© Ã  vos favoris.']);
exit(0);


