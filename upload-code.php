<?php
include_once __DIR__ . '/includes/bootstrap.php';

if (empty($_SESSION['auth'])) {
    include_once __DIR__ . '/includes/flash.php';
    flash_set(
        'warning',
        'Connexion requise',
        'Vous devez être connecté pour déposer un document.',
        'login.php',
        'Se connecter'
    );
    header('Location: login.php');
    exit(0);
}

include_once __DIR__ . '/admin/config/dbcon.php';
include_once __DIR__ . '/includes/document-taxonomy.php';
include_once __DIR__ . '/includes/csrf.php';
include_once __DIR__ . '/includes/flash.php';

verify_csrf_token();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: upload.php');
    exit(0);
}

$uid = intval($_SESSION['auth_user']['id'] ?? 0);
if ($uid <= 0) {
    flash_set(
        'error',
        'Session invalide',
        'Votre session a expiré. Merci de vous reconnecter.',
        'login.php',
        'Se reconnecter'
    );
    header('Location: login.php');
    exit(0);
}

$title       = trim($_POST['title'] ?? '');
$description = trim($_POST['description'] ?? '');
$description = $description === '' ? null : $description;
if (function_exists('emsp_fix_mojibake')) {
    $title = emsp_fix_mojibake($title);
    if ($description !== null) {
        $description = emsp_fix_mojibake($description);
    }
}
$doc_type    = trim($_POST['doc_type'] ?? '');
$semester    = trim($_POST['semester'] ?? '');
$semester    = $semester === '' ? null : $semester;

$licence_id  = intval($_POST['licence_id'] ?? 0);
$matiere_id  = intval($_POST['matiere_id'] ?? 0);
$matiere_free_text = trim((string) ($_POST['matiere_free_text'] ?? ''));
$filiere_ids = emsp_collect_int_ids($_POST['filiere_ids'] ?? []);

$filiere_id  = !empty($filiere_ids) ? (int) $filiere_ids[0] : null;
$licence_id  = $licence_id > 0 ? $licence_id : null;
$module_id   = null;
$matiere_id  = $matiere_id > 0 ? $matiere_id : null;

$is_public   = isset($_POST['is_public']) ? 1 : 0;

$exam_session = null;
$exam_section = null;
$exam_year    = null;

if ($title === '') {
    flash_set(
        'error',
        'Titre manquant',
        'Le titre est obligatoire pour soumettre un document.'
    );
    header('Location: upload.php');
    exit(0);
}

if (empty($filiere_ids)) {
    flash_set(
        'error',
        'Filière obligatoire',
        'Sélectionnez au moins une filière avant d’envoyer le document.'
    );
    header('Location: upload.php');
    exit(0);
}

if ($matiere_id === null && $matiere_free_text === '') {
    flash_set(
        'error',
        'Matière obligatoire',
        'Choisissez une matière existante ou saisissez une nouvelle matière.'
    );
    header('Location: upload.php');
    exit(0);
}

if ($matiere_free_text !== '') {
    $matiere_id = null;
    if (!emsp_pending_matiere_enabled($con)) {
        flash_set(
            'error',
            'Migration SQL manquante',
            'La matière libre nécessite la migration SQL document_filieres + matiere_label_pending.'
        );
        header('Location: upload.php');
        exit(0);
    }
}

if (!in_array($doc_type, ['cours', 'td', 'correction', 'concours', 'examen'], true)) {
    flash_set(
        'error',
        'Type de document invalide',
        'Choisissez un type de document pour continuer.'
    );
    header('Location: upload.php');
    exit(0);
}

if ($doc_type === 'examen') {
    $exam_session = trim($_POST['exam_session'] ?? '');
    $exam_section = trim($_POST['exam_section'] ?? '');
    $exam_year    = intval($_POST['exam_year'] ?? 0);

    if ($exam_session === '' || $exam_section === '' || $exam_year < 2000) {
        flash_set(
            'error',
            'Informations examen incomplètes',
            "Session, section et année sont obligatoires pour un examen."
        );
        header('Location: upload.php');
        exit(0);
    }
}

if (empty($_FILES['document']['name']) || ($_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    flash_set(
        'error',
        'Fichier manquant',
        'Veuillez sélectionner un fichier valide avant d’envoyer.'
    );
    header('Location: upload.php');
    exit(0);
}

$maxBytes = emsp_max_upload_size(defined('MAX_UPLOAD_SIZE') ? MAX_UPLOAD_SIZE : (5 * 1024 * 1024));
$size = intval($_FILES['document']['size'] ?? 0);
if ($size <= 0 || $size > $maxBytes) {
    flash_set(
        'error',
        'Fichier trop lourd',
        'Votre fichier dépasse la limite de ' . round($maxBytes / 1024 / 1024, 1) . ' Mo. Essayez de le compresser puis réessayez.'
    );
    header('Location: upload.php');
    exit(0);
}

$real_mime = emsp_detect_mime($_FILES['document']['tmp_name']);

$allowed_mime_ext = [
    'application/pdf' => ['pdf'],
    'application/msword' => ['doc'],
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
    'application/vnd.ms-powerpoint' => ['ppt'],
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
    'application/vnd.ms-excel' => ['xls'],
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
    'text/plain' => ['txt'],
    'application/zip' => ['zip'],
    'application/x-zip-compressed' => ['zip'],
    'application/x-rar-compressed' => ['rar'],
    'application/vnd.rar' => ['rar'],
    'image/jpeg' => ['jpg','jpeg'],
    'image/png' => ['png'],
    'image/webp' => ['webp'],
];

if (!isset($allowed_mime_ext[$real_mime])) {
    flash_set(
        'error',
        'Fichier non valide',
        'Le format réel du fichier ne correspond pas à un format accepté.'
    );
    header('Location: upload.php');
    exit(0);
}

$file_hash = hash_file('sha256', $_FILES['document']['tmp_name']);
$ext = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
$mime_exts = $allowed_mime_ext[$real_mime];
if (!in_array($ext, $mime_exts, true)) {
    $ext = $mime_exts[0];
}

$file_name = bin2hex(random_bytes(16)) . '.' . $ext;
$destDir = __DIR__ . '/uploads/documents';
if (!is_dir($destDir)) {
    mkdir($destDir, 0755, true);
}
$destPath = $destDir . '/' . $file_name;

mysqli_begin_transaction($con);

$chk = mysqli_prepare($con, 'SELECT id FROM documents WHERE file_hash=? LIMIT 1 FOR UPDATE');
if (!$chk) {
    mysqli_rollback($con);
    flash_set('error', 'Erreur technique', 'Impossible de vérifier les doublons pour le moment.');
    header('Location: upload.php');
    exit(0);
}
mysqli_stmt_bind_param($chk, 's', $file_hash);
mysqli_stmt_execute($chk);
mysqli_stmt_store_result($chk);
$doublon = mysqli_stmt_num_rows($chk) > 0;
mysqli_stmt_close($chk);

if ($doublon) {
    mysqli_rollback($con);
    flash_set(
        'warning',
        'Document déjà présent',
        'Ce fichier exact existe déjà dans la bibliothèque. '
        . 'Si vous pensez qu\'il s\'agit d\'une mise à jour, ajoutez la version dans le titre (ex: "Cours_v2.pdf").'
    );
    header('Location: upload.php');
    exit(0);
}

if (!move_uploaded_file($_FILES['document']['tmp_name'], $destPath)) {
    mysqli_rollback($con);
    flash_set(
        'error',
        'Upload échoué',
        'Le fichier n’a pas pu être enregistré. Réessayez dans quelques minutes.'
    );
    header('Location: upload.php');
    exit(0);
}

$hasPendingMatiere = emsp_pending_matiere_enabled($con);
$sql = "INSERT INTO documents
        (uploader_id, title, description, doc_type, semester,
         filiere_id, licence_id, module_id, matiere_id"
        . ($hasPendingMatiere ? ", matiere_label_pending" : "") . ",
         exam_session, exam_section, exam_year,
         file_path, mime_type, file_size_bytes, is_public, file_hash, status)
        VALUES
        (?, ?, ?, ?, ?,
         ?, ?, ?, ?"
        . ($hasPendingMatiere ? ", ?" : "") . ",
         ?, ?, ?,
         ?, ?, ?, ?, ?, ?)";

$stmt = mysqli_prepare($con, $sql);
if (!$stmt) {
    mysqli_rollback($con);
    @unlink($destPath);
    flash_set(
        'error',
        'Erreur technique',
        'Une erreur est survenue lors de la préparation de l’enregistrement.'
    );
    header('Location: upload.php');
    exit(0);
}

$status = 'pending';
if ($hasPendingMatiere) {
    mysqli_stmt_bind_param(
        $stmt,
        'issssiiiisssissiiss',
        $uid,
        $title,
        $description,
        $doc_type,
        $semester,
        $filiere_id,
        $licence_id,
        $module_id,
        $matiere_id,
        $matiere_free_text,
        $exam_session,
        $exam_section,
        $exam_year,
        $file_name,
        $real_mime,
        $size,
        $is_public,
        $file_hash,
        $status
    );
} else {
    mysqli_stmt_bind_param(
        $stmt,
        'issssiiiississiiss',
        $uid,
        $title,
        $description,
        $doc_type,
        $semester,
        $filiere_id,
        $licence_id,
        $module_id,
        $matiere_id,
        $exam_session,
        $exam_section,
        $exam_year,
        $file_name,
        $real_mime,
        $size,
        $is_public,
        $file_hash,
        $status
    );
}

if (!mysqli_stmt_execute($stmt)) {
    $stmt_errno = mysqli_stmt_errno($stmt);
    mysqli_stmt_close($stmt);
    mysqli_rollback($con);
    @unlink($destPath);
    if ($stmt_errno === 1062) {
        flash_set(
            'warning',
            'Document déjà présent',
            'Ce fichier exact existe déjà dans la bibliothèque. '
            . 'Si vous pensez qu\'il s\'agit d\'une mise à jour, ajoutez la version dans le titre (ex: "Cours_v2.pdf").'
        );
        header('Location: upload.php');
        exit(0);
    }
    flash_set(
        'error',
        'Erreur technique',
        'Une erreur est survenue lors de l’enregistrement du document.'
    );
    header('Location: upload.php');
    exit(0);
}

mysqli_stmt_close($stmt);
$document_id = (int) mysqli_insert_id($con);
if ($document_id > 0) {
    emsp_sync_document_filieres($con, $document_id, $filiere_ids);
}
mysqli_commit($con);

flash_set(
    'success',
    'Document soumis avec succès ! 📄',
    'Votre document "' . $title . '" a été reçu et est en attente de validation par un modérateur. '
    . 'Vous serez notifié par email dès qu’il sera approuvé et disponible dans la bibliothèque.',
    'mon-profil.php',
    'Voir mes documents'
);
header('Location: dashboard.php');
exit(0);
?>

