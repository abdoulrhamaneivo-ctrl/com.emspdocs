<?php
include_once __DIR__ . '/includes/bootstrap.php';
include_once __DIR__ . '/includes/csrf.php';
include_once __DIR__ . '/includes/notif-helper.php';
if (empty($_SESSION['auth'])) {
    $_SESSION['redirect_after_login'] = 'dashboard.php';
    header('Location: login.php'); exit(0);
}

include_once __DIR__ . '/admin/config/dbcon.php';

$uid    = intval($_SESSION['auth_user']['id']);
$prenom = htmlspecialchars($_SESSION['auth_user']['first_name'] ?? '');
$badge  = $_SESSION['auth_user']['badge_level'] ?? 'none';

// -- Mes documents --
$docs = mysqli_prepare($con,
    "SELECT d.id, d.title, d.doc_type, d.status, d.created_at,
            d.download_count, d.like_count, d.rejection_reason,
            COALESCE(cc.nb_comments, 0) AS nb_comments
     FROM documents d
     LEFT JOIN (
         SELECT document_id, COUNT(*) AS nb_comments
         FROM comments
         WHERE status='visible'
         GROUP BY document_id
     ) cc ON cc.document_id = d.id
     WHERE d.uploader_id = ?
     ORDER BY d.created_at DESC");
mysqli_stmt_bind_param($docs, 'i', $uid);
mysqli_stmt_execute($docs);
$mes_docs = emsp_stmt_fetch_all($docs);
mysqli_stmt_close($docs);
foreach ($mes_docs as &$docRow) {
    foreach (['title', 'rejection_reason'] as $field) {
        if (isset($docRow[$field]) && is_string($docRow[$field])) {
            $docRow[$field] = emsp_fix_mojibake($docRow[$field]);
        }
    }
}
unset($docRow);

// -- Statistiques rapides --
$stats_s = mysqli_prepare($con,
    "SELECT
        COUNT(*) AS total,
        SUM(status='pending')  AS pending,
        SUM(status='approved') AS approved,
        SUM(status='rejected') AS rejected,
        COALESCE(SUM(download_count),0) AS total_dl,
        COALESCE(SUM(like_count),0)     AS total_likes
     FROM documents WHERE uploader_id=?");
mysqli_stmt_bind_param($stats_s, 'i', $uid);
mysqli_stmt_execute($stats_s);
$stats = emsp_stmt_fetch_assoc($stats_s);
mysqli_stmt_close($stats_s);

// -- Notifications non lues --
$notifs_s = mysqli_prepare($con,
    "SELECT n.*,
            COALESCE(d.title, d2.title) AS doc_title,
            COALESCE(d.id, d2.id, 0) AS doc_id_resolved,
            fu.first_name AS from_first, fu.last_name AS from_last
     FROM notifications n
     LEFT JOIN documents d   ON d.id = n.document_id
     LEFT JOIN comments c    ON c.id = n.comment_id
     LEFT JOIN documents d2  ON d2.id = c.document_id
     LEFT JOIN users     fu  ON fu.id = n.from_user_id
     WHERE n.user_id = ?
     ORDER BY n.created_at DESC
     LIMIT 20");
mysqli_stmt_bind_param($notifs_s, 'i', $uid);
mysqli_stmt_execute($notifs_s);
$notifs_res = emsp_stmt_fetch_all($notifs_s);
mysqli_stmt_close($notifs_s);
foreach ($notifs_res as &$notifRow) {
    foreach (['message', 'doc_title', 'from_first', 'from_last'] as $field) {
        if (isset($notifRow[$field]) && is_string($notifRow[$field])) {
            $notifRow[$field] = emsp_fix_mojibake($notifRow[$field]);
        }
    }
}
unset($notifRow);

$nb_unread_s = mysqli_prepare($con,
    "SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
mysqli_stmt_bind_param($nb_unread_s, 'i', $uid);
mysqli_stmt_execute($nb_unread_s);
mysqli_stmt_bind_result($nb_unread_s, $nb_unread);
mysqli_stmt_fetch($nb_unread_s);
mysqli_stmt_close($nb_unread_s);
emsp_session_set_notif_count((int) $nb_unread);
emsp_session_set_notif_sections(emsp_unread_notification_sections($con, $uid));
$_SESSION['emsp_notif_sections_fetched_at'] = time();

$recent_activity = [];

$uploads_s = mysqli_prepare(
    $con,
    "SELECT title, created_at
     FROM documents
     WHERE uploader_id = ?
     ORDER BY created_at DESC
     LIMIT 5"
);
if ($uploads_s) {
    mysqli_stmt_bind_param($uploads_s, 'i', $uid);
    mysqli_stmt_execute($uploads_s);
    $upload_rows = emsp_stmt_fetch_all($uploads_s);
    mysqli_stmt_close($uploads_s);
    foreach ($upload_rows as $row) {
        $recent_activity[] = [
            'action_type' => 'upload',
            'title' => emsp_fix_mojibake((string) ($row['title'] ?? 'Document')),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }
}

$favorites_s = mysqli_prepare(
    $con,
    "SELECT d.title, f.created_at
     FROM favorites f
     JOIN documents d ON d.id = f.document_id
     WHERE f.user_id = ?
     ORDER BY f.created_at DESC
     LIMIT 5"
);
if ($favorites_s) {
    mysqli_stmt_bind_param($favorites_s, 'i', $uid);
    mysqli_stmt_execute($favorites_s);
    $favorite_rows = emsp_stmt_fetch_all($favorites_s);
    mysqli_stmt_close($favorites_s);
    foreach ($favorite_rows as $row) {
        $recent_activity[] = [
            'action_type' => 'favori',
            'title' => emsp_fix_mojibake((string) ($row['title'] ?? 'Document')),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }
}

usort($recent_activity, static function (array $a, array $b): int {
    return strtotime((string) ($b['created_at'] ?? '')) <=> strtotime((string) ($a['created_at'] ?? ''));
});
$recent_activity = array_slice($recent_activity, 0, 5);

$badge_labels = [
    'or'     => ['label' => 'OR', 'class' => 'bg-warning text-dark'],
    'argent' => ['label' => 'ARG', 'class' => 'bg-secondary text-white'],
    'bronze' => ['label' => 'BR', 'class' => 'bg-danger text-white'],
];
$badge_html = '';
if (isset($badge_labels[$badge])) {
    $b = $badge_labels[$badge];
    $badge_html = '<span class="badge ' . $b['class'] . ' me-2 align-middle">' . $b['label'] . '</span>';
}
$type_colors = [
    'cours'      => 'bg-primary',
    'td'         => 'bg-success',
    'correction' => 'bg-info text-dark',
    'concours'   => 'bg-warning text-dark',
    'examen'     => 'bg-danger',
];

$page_title = 'Mon espace';
include __DIR__ . '/includes/header.php';
?>

<section class="page-header">
    <div class="container">
        <h1 class="mb-1">
            <?= $badge_html ?>
            Bonjour, <?= $prenom ?> !
        </h1>
        <p class="mb-0 text-white-50">Gérez vos documents et suivez vos notifications</p>
    </div>
</section>

<section class="section-pad">
<div class="container">
<?php if (!empty($_SESSION['message'])): ?>
    <div class="alert alert-info alert-dismissible fade show">
        <?= h($_SESSION['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['message']); ?>
<?php endif; ?>

<!-- Cartes stats -->
<div class="emsp-mini-grid mb-4">
    <div class="emsp-mini-card">
        <div class="value text-primary"><?= $stats['total'] ?></div>
        <div class="label">Documents déposés</div>
    </div>
    <div class="emsp-mini-card">
        <div class="value text-warning"><?= $stats['pending'] ?></div>
        <div class="label">En attente</div>
    </div>
    <div class="emsp-mini-card">
        <div class="value text-success"><?= $stats['approved'] ?></div>
        <div class="label">Approuvés</div>
    </div>
    <div class="emsp-mini-card">
        <div class="value text-danger"><?= $stats['rejected'] ?></div>
        <div class="label">Rejetés</div>
    </div>
</div>

<div class="emsp-panel mb-4">
    <div class="emsp-panel-header">
        <i class="bi bi-activity text-primary"></i>Activité récente
    </div>
    <div class="emsp-panel-body">
        <?php if (empty($recent_activity)): ?>
            <div class="text-center text-muted py-3">
                <i class="bi bi-clock-history fs-2 d-block mb-2"></i>
                Aucune activité récente pour le moment.
            </div>
        <?php else: ?>
            <div class="d-flex flex-column gap-3">
                <?php foreach ($recent_activity as $activity): ?>
                    <?php
                    $isUpload = $activity['action_type'] === 'upload';
                    $iconClass = $isUpload ? 'bi-cloud-arrow-up-fill text-primary' : 'bi-star-fill text-warning';
                    $activityIconStateClass = $isUpload ? 'is-upload' : 'is-favorite';
                    $label = $isUpload ? 'Document déposé' : 'Ajouté aux favoris';
                    ?>
                    <div class="emsp-activity-item d-flex gap-3 align-items-start">
                        <div class="emsp-activity-item-icon flex-shrink-0 <?= h($activityIconStateClass) ?>">
                            <i class="bi <?= $iconClass ?>"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="fw-semibold"><?= htmlspecialchars($label) ?></div>
                            <div class="text-muted small"><?= htmlspecialchars($activity['title']) ?></div>
                        </div>
                        <div class="text-muted small text-nowrap">
                            <?= date('d/m/Y H:i', strtotime($activity['created_at'])) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4">

<!-- Colonne gauche : mes documents -->
<div class="col-lg-8">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold mb-0">
            <i class="bi bi-files me-2 text-primary"></i>Mes documents
        </h5>
        <a href="upload.php" class="btn btn-sm btn-primary">
            <i class="bi bi-cloud-upload me-1"></i>Déposer un document
        </a>
    </div>

    <?php if (count($mes_docs) === 0): ?>
        <div class="emsp-panel">
            <div class="emsp-panel-body text-center py-5 text-muted">
                <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                Vous n'avez pas encore déposé de document.<br>
                <a href="upload.php" class="btn btn-primary mt-3">
                    <i class="bi bi-cloud-upload me-1"></i>Déposer mon premier document
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="d-flex flex-column gap-3">
        <?php foreach ($mes_docs as $d): ?>

            <?php
            $status_class = [
                'pending'  => 'border-warning',
                'approved' => 'border-success',
                'rejected' => 'border-danger',
            ][$d['status']] ?? '';
            $status_label = [
                'pending'  => '<span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split me-1"></i>En attente de validation</span>',
                'approved' => '<span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Approuvé</span>',
                'rejected' => '<span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Rejeté</span>',
            ][$d['status']] ?? '';
            ?>

            <div class="emsp-doc-row <?= $status_class ?>">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                                <span class="badge <?= $type_colors[$d['doc_type']] ?? 'bg-secondary' ?>">
                                    <?= ucfirst($d['doc_type']) ?>
                                </span>
                                <?= $status_label ?>
                                <span class="text-muted small">
                                    <?= date('d/m/Y', strtotime($d['created_at'])) ?>
                                </span>
                            </div>
                            <div class="doc-title mb-1">
                                <?php if ($d['status'] === 'approved'): ?>
                                    <a href="document.php?id=<?= $d['id'] ?>" class="text-decoration-none text-dark">
                                        <?= htmlspecialchars($d['title']) ?>
                                    </a>
                                <?php else: ?>
                                    <?= htmlspecialchars($d['title']) ?>
                                <?php endif; ?>
                            </div>

                            <?php if ($d['status'] === 'pending'): ?>
                                <p class="mb-0 small text-warning-emphasis">
                                    <i class="bi bi-clock me-1"></i>
                                    Votre document est en cours de vérification par un modérateur. Vous serez notifié(e) dès qu'une décision sera prise.
                                </p>
                            <?php elseif ($d['status'] === 'rejected' && $d['rejection_reason']): ?>
                                <div class="alert alert-danger py-1 px-2 mb-0 small mt-1">
                                    <i class="bi bi-exclamation-triangle me-1"></i>
                                    <strong>Motif du rejet :</strong> <?= htmlspecialchars($d['rejection_reason']) ?>
                                </div>
                            <?php elseif ($d['status'] === 'approved'): ?>
                                <div class="doc-meta d-flex gap-3 mt-1">
                                    <span><i class="bi bi-download me-1"></i><?= $d['download_count'] ?> télécharg.</span>
                                    <span><i class="bi bi-heart me-1"></i><?= $d['like_count'] ?> likes</span>
                                    <span><i class="bi bi-chat me-1"></i><?= $d['nb_comments'] ?> commentaires</span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($d['status'] === 'approved'): ?>
                        <a href="document.php?id=<?= $d['id'] ?>" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-eye me-1"></i>Voir
                        </a>
                        <?php endif; ?>
                    </div>
            </div>

        <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<!-- Colonne droite : notifications -->
<div class="col-lg-4" id="notifications">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold mb-0">
            <i class="bi bi-bell me-2 text-primary"></i>Notifications
            <?php if ($nb_unread > 0): ?>
                <span class="badge bg-danger ms-1"><?= $nb_unread ?></span>
            <?php endif; ?>
        </h5>
        <?php if ($nb_unread > 0): ?>
        <form method="POST" action="notif-marquer-lues.php" class="m-0">
            <?php csrf_input(); ?>
            <button type="submit" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-check-all me-1"></i>Tout lire
            </button>
        </form>
        <?php endif; ?>
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-0">
        <?php
        $notif_icons = [
            'doc_approved'     => ['icon'=>'bi-check-circle-fill', 'color'=>'text-success'],
            'doc_rejected'     => ['icon'=>'bi-x-circle-fill',     'color'=>'text-danger'],
            'new_comment'      => ['icon'=>'bi-chat-dots-fill',    'color'=>'text-primary'],
            'comment_reply'    => ['icon'=>'bi-reply-fill',        'color'=>'text-info'],
            'doc_liked'        => ['icon'=>'bi-heart-fill',        'color'=>'text-danger'],
            'comment_reacted'  => ['icon'=>'bi-emoji-smile-fill',  'color'=>'text-warning'],
            'journal_published'=> ['icon'=>'bi-megaphone-fill',    'color'=>'text-primary'],
            'journal_liked'    => ['icon'=>'bi-heart-fill',        'color'=>'text-danger'],
            'journal_commented'=> ['icon'=>'bi-chat-dots-fill',    'color'=>'text-primary'],
            'media_published'  => ['icon'=>'bi-images',            'color'=>'text-success'],
        ];
        $has_notif = !empty($notifs_res);
        foreach ($notifs_res as $n):
            $icon = $notif_icons[$n['type']] ?? ['icon'=>'bi-bell-fill','color'=>'text-secondary'];
            $notifPayload = $n;
            if (!empty($n['doc_id_resolved'])) {
                $notifPayload['document_id'] = (int) $n['doc_id_resolved'];
            }
            $notifLink = emsp_notification_target($notifPayload, 'dashboard.php');
            $isJournalNotif = in_array($n['type'], ['journal_published','journal_liked','journal_commented'], true);
        ?>
            <form method="POST" action="notif-marquer-lues.php" class="m-0">
                <?php csrf_input(); ?>
                <input type="hidden" name="notification_id" value="<?= (int) $n['id'] ?>">
                <input type="hidden" name="redirect_to" value="<?= h($notifLink) ?>">
                <button type="submit"
                        class="notif-row d-flex gap-3 w-100 text-start border-0 bg-transparent p-0"
                        aria-label="Voir la notification">
                    <div class="notif-item d-flex gap-3 p-3 border-bottom w-100 <?= !$n['is_read'] ? 'bg-light is-unread' : '' ?>">
                        <div class="flex-shrink-0 pt-1">
                            <i class="bi <?= $icon['icon'] ?> <?= $icon['color'] ?> fs-5"></i>
                        </div>
                        <div class="flex-grow-1">
                            <p class="mb-0 small <?= !$n['is_read'] ? 'fw-semibold' : '' ?>">
                                <?= h($n['message']) ?>
                            </p>
                            <?php if (!$isJournalNotif && !empty($n['doc_title'])): ?>
                                <span class="text-muted notif-doc-title">
                                    <?= h($n['doc_title']) ?>
                                </span>
                            <?php endif; ?>
                            <div class="text-muted mt-1 notif-time">
                                <?= date('d/m/Y à H:i', strtotime($n['created_at'])) ?>
                            </div>
                        </div>
                        <div class="align-self-center text-muted notif-arrow">
                            <i class="bi bi-arrow-right"></i>
                        </div>
                    </div>
                </button>
            </form>
        <?php endforeach; ?>

        <?php if (!$has_notif): ?>
            <div class="text-center text-muted py-4 small">
                <i class="bi bi-bell-slash fs-3 d-block mb-2"></i>
                Aucune notification pour l'instant.
            </div>
        <?php endif; ?>
        </div>
    </div>
</div><!-- /col notifs -->
</div><!-- /row -->

</div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>










