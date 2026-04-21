<?php
include_once __DIR__ . '/includes/bootstrap.php';

// Visiteurs acceptés, mais avec affichage limité
$isAuthViewer = !empty($_SESSION['auth']);
// Les visiteurs peuvent voir les profils publics
// (incitation à rejoindre la communauté)

include_once __DIR__ . '/admin/config/dbcon.php';

$profil_id = intval($_GET['id'] ?? 0);
if ($profil_id <= 0) {
    header('Location: bibliotheque.php');
    exit(0);
}

// Récupérer le profil
$stmt = mysqli_prepare($con,
    "SELECT u.id, u.first_name, u.last_name, u.photo_path,
            u.badge_level, u.upload_count, u.created_at,
            f.name AS filiere_name, l.name AS licence_name
     FROM users u
     LEFT JOIN filieres f ON f.id = u.filiere_id
     LEFT JOIN licences l ON l.id = u.licence_id
     WHERE u.id=? AND u.status='active' LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $profil_id);
mysqli_stmt_execute($stmt);
$profil = emsp_stmt_fetch_assoc($stmt);
mysqli_stmt_close($stmt);

if (!$profil) {
    header('Location: errors/404.php');
    exit(0);
}

// Documents approuvés de cet étudiant
$docs = mysqli_prepare($con,
    "SELECT d.id, d.title, d.doc_type, d.semester, d.download_count, d.created_at,
            fi.name AS filiere_name
     FROM documents d
     LEFT JOIN filieres fi ON fi.id = d.filiere_id
     WHERE d.uploader_id=? AND d.status='approved'
     ORDER BY d.created_at DESC LIMIT 12");
mysqli_stmt_bind_param($docs, 'i', $profil_id);
mysqli_stmt_execute($docs);
$docs_result = emsp_stmt_fetch_all($docs);
mysqli_stmt_close($docs);
$docs_count = count($docs_result);

$badge_labels = [
    'or' => 'Badge Or',
    'argent' => 'Badge Argent',
    'bronze' => 'Badge Bronze',
    'none' => ''
];
$badge_class_map = [
    'or' => 'emsp-profile-badge-level-or',
    'argent' => 'emsp-profile-badge-level-argent',
    'bronze' => 'emsp-profile-badge-level-bronze',
    'none' => 'emsp-profile-badge-level-none',
];
$type_colors = [
    'cours' => 'bg-primary',
    'td' => 'bg-success',
    'correction' => 'bg-info text-dark',
    'concours' => 'bg-warning text-dark',
    'examen' => 'bg-danger'
];

$page_title = $profil['first_name'] . ' ' . $profil['last_name'];
include __DIR__ . '/includes/header.php';
?>

<section class="page-header">
    <div class="container">
        <h1>Profil étudiant</h1>
    </div>
</section>

<section class="section-pad">
<div class="container">
    <?php if (!$isAuthViewer): ?>
    <div class="alert border-0 mb-4"
         style="background:linear-gradient(135deg,#004D2A,#006B3C);color:#fff;
                border-radius:12px;padding:20px 24px">
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <div>
                <strong style="font-size:16px">Rejoins la communauté EMSP Docs</strong>
                <p class="mb-0 mt-1" style="font-size:14px;opacity:.85">
                    Accède à tous les documents, partage tes ressources et rejoins
                    <?= number_format($docs_count) ?> documents déjà partagés.
                </p>
            </div>
            <div class="d-flex gap-2 flex-shrink-0">
                <a href="register.php" class="btn btn-sm fw-bold"
                   style="background:#F5A800;color:#1A1A1A;border:none">
                    S'inscrire
                </a>
                <a href="index.php?open_login=1"
                   class="btn btn-sm btn-outline-light">
                    Se connecter
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <style>
    .profile-shell {
        display: grid;
        grid-template-columns: 340px minmax(0, 1fr);
        gap: 1.5rem;
    }
    .profile-panel {
        border: 1px solid rgba(0,48,135,.1);
        border-radius: 18px;
        background: #fff;
        box-shadow: 0 10px 26px rgba(0,0,0,.04);
        padding: 1.4rem;
    }
    .profile-badge {
        border-radius: 999px;
        padding: .35rem .85rem;
        font-weight: 700;
        font-size: .8rem;
        display: inline-flex;
        align-items: center;
        gap: .4rem;
    }
    .profile-meta {
        color: #6b7a90;
        font-size: .85rem;
    }
    .profile-doc-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1rem;
    }
    .profile-doc-card {
        border: 1px solid rgba(0,48,135,.1);
        border-radius: 16px;
        background: #fff;
        padding: 1rem;
        box-shadow: 0 8px 20px rgba(0,0,0,.04);
        height: 100%;
    }
    .profile-doc-card h6 {
        margin: .4rem 0 .3rem;
        font-weight: 700;
        color: #102f50;
    }
    .profile-doc-meta {
        color: #6b7a90;
        font-size: .8rem;
        display: flex;
        justify-content: space-between;
        gap: .5rem;
    }
    @media (max-width: 991px) {
        .profile-shell { grid-template-columns: 1fr; }
        .profile-doc-grid { grid-template-columns: 1fr; }
    }
    </style>

    <div class="profile-shell">
        <div class="profile-panel text-center">
            <?php $publicProfilePhoto = emsp_user_photo_src((string) ($profil['photo_path'] ?? '')); ?>
            <?php if ($publicProfilePhoto !== ''): ?>
                <img src="<?= h($publicProfilePhoto) ?>"
                     class="rounded-circle mb-3 border emsp-profile-avatar-img" alt="Photo">
            <?php else: ?>
                <div class="rounded-circle bg-primary d-flex align-items-center
                            justify-content-center text-white fw-bold mx-auto mb-3 emsp-profile-avatar-fallback">
                    <?= h(emsp_user_initials((string) ($profil['first_name'] ?? ''), (string) ($profil['last_name'] ?? ''))) ?>
                </div>
            <?php endif; ?>

            <h5 class="fw-bold mb-0">
                <?= htmlspecialchars($profil['first_name'] . ' ' . $profil['last_name']) ?>
            </h5>

            <?php if ($profil['filiere_name']): ?>
                <p class="profile-meta mb-2">
                    <?= htmlspecialchars($profil['filiere_name']) ?>
                    <?php if ($profil['licence_name']): ?>
                        — <?= htmlspecialchars($profil['licence_name']) ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <?php if ($profil['badge_level'] !== 'none'): ?>
                <div class="profile-badge mb-3 emsp-profile-badge <?= h($badge_class_map[$profil['badge_level']] ?? 'emsp-profile-badge-level-none') ?>">
                    <?= $badge_labels[$profil['badge_level']] ?>
                </div>
            <?php endif; ?>

            <div class="border-top pt-3">
                <div class="fs-4 fw-bold"><?= $profil['upload_count'] ?></div>
                <div class="profile-meta">document<?= $profil['upload_count'] > 1 ? 's' : '' ?> partagé<?= $profil['upload_count'] > 1 ? 's' : '' ?></div>
            </div>

            <div class="mt-2 profile-meta emsp-profile-meta-caption">
                Membre depuis <?= date('d/m/Y', strtotime($profil['created_at'])) ?>
            </div>
        </div>

        <div>
            <h5 class="fw-bold mb-3">
                <i class="bi bi-files me-2"></i>
                Documents partagés (<?= $docs_count ?>)
            </h5>

            <?php if ($docs_count === 0): ?>
                <div class="profile-panel text-center py-4 text-muted">
                    Aucun document partagé pour le moment.
                </div>
            <?php else: ?>
                <div class="profile-doc-grid">
                <?php foreach ($docs_result as $d): ?>
                    <a href="document.php?id=<?= $d['id'] ?>" class="text-decoration-none text-reset d-block h-100">
                        <div class="profile-doc-card">
                            <div class="d-flex justify-content-between mb-2">
                                <span class="badge <?= $type_colors[$d['doc_type']] ?? 'bg-secondary' ?>">
                                    <?= ucfirst($d['doc_type']) ?>
                                </span>
                                <?php if ($d['semester']): ?>
                                    <span class="badge bg-light text-muted border">
                                        <?= htmlspecialchars($d['semester']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <h6 class="fw-semibold mb-1 emsp-line-clamp-2">
                                <?= htmlspecialchars($d['title']) ?>
                            </h6>
                            <div class="profile-doc-meta mt-2">
                                <span><?= date('d/m/Y', strtotime($d['created_at'])) ?></span>
                                <span><i class="bi bi-download me-1"></i><?= $d['download_count'] ?></span>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="mt-4">
        <a href="bibliotheque.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Retour à la bibliothèque
        </a>
    </div>
</div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
