<?php
ob_start();
include_once __DIR__ . '/bootstrap.php';
if (empty($_SESSION['auth']) || !in_array($_SESSION['auth_role'] ?? '', ['admin','moderateur'], true)) {
    header('Location: ../index.php?open_login=1'); exit;
}

include_once __DIR__ . '/config/dbcon.php';
include_once __DIR__ . '/authentication.php';
include_once __DIR__ . '/../includes/csrf.php';
include_once __DIR__ . '/../includes/brevo.php';
include_once __DIR__ . '/../includes/flash.php';

// ------------------------------------------------------------------
// Traitement actions
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['user_id'])) {
    verify_csrf_token();
    $user_id = intval($_POST['user_id']);
    $action  = $_POST['action'];
    $motif   = trim((string)($_POST['motif'] ?? ''));

    // Recuperer l'utilisateur
    $u = mysqli_prepare($con, "SELECT id, first_name, last_name, email, status, email_verified_at, verification_token FROM users WHERE id=? LIMIT 1");
    mysqli_stmt_bind_param($u,'i',$user_id);
    mysqli_stmt_execute($u);
    $user = emsp_stmt_fetch_assoc($u);
    mysqli_stmt_close($u);
    if ($user) {
        foreach (['first_name','last_name','email'] as $f) {
            if (isset($user[$f]) && is_string($user[$f])) {
                $user[$f] = emsp_fix_mojibake($user[$f]);
            }
        }
    }

    if (!$user || $user['status'] !== 'pending') {
        flash_set(
            'warning',
            'Utilisateur introuvable',
            "Ce compte a deja ete traite ou n'existe plus."
        );
        header('Location: pending-users.php'); exit;
    }
    $email_confirmed = !empty($user['email_verified_at']) || empty($user['verification_token']);
    if (!$email_confirmed) {
        flash_set(
            'warning',
            'Email non verifie',
            "L'etudiant doit confirmer son adresse email avant validation."
        );
        header('Location: pending-users.php'); exit;
    }

    if ($action === 'approve') {
        $upd = mysqli_prepare($con, "UPDATE users SET status='active', status_updated_at=NOW(), rejection_reason=NULL WHERE id=? AND status='pending'");
        mysqli_stmt_bind_param($upd,'i',$user_id);
        mysqli_stmt_execute($upd); mysqli_stmt_close($upd);
        brevo_send_account_approved($user['id'], $user['email'], $user['first_name'], $user['last_name']);
        log_audit($con, (int) $auth_user['id'], 'account_activated', 'user', $user_id, (string) ($user['email'] ?? ''));
        $student_name = trim($user['first_name'] . ' ' . $user['last_name']);
        flash_set(
            'success',
            'Compte active.',
            $student_name . ' peut maintenant se connecter et acceder a la plateforme. Un email de bienvenue lui a ete envoye.'
        );
    } elseif ($action === 'reject') {
        if ($motif === '') {
            flash_set(
                'warning',
                'Motif obligatoire',
                'Veuillez indiquer un motif clair pour refuser cette inscription.'
            );
            header('Location: pending-users.php'); exit;
        }
        $upd = mysqli_prepare($con, "UPDATE users SET status='rejected', rejection_reason=?, status_updated_at=NOW() WHERE id=? AND status='pending'");
        mysqli_stmt_bind_param($upd,'si',$motif,$user_id);
        mysqli_stmt_execute($upd); mysqli_stmt_close($upd);
        brevo_send_account_rejected($user['id'], $user['email'], $user['first_name'], $user['last_name'], $motif);
        log_audit($con, (int) $auth_user['id'], 'account_rejected', 'user', $user_id, $motif);
        $student_name = trim($user['first_name'] . ' ' . $user['last_name']);
        flash_set(
            'warning',
            'Inscription refusee',
            'La demande de ' . $student_name . ' a ete refusee avec le motif fourni. L\'etudiant a ete notifie par email.'
        );
    }
    header('Location: pending-users.php'); exit;
}

// ------------------------------------------------------------------
// Liste des comptes
// ------------------------------------------------------------------
$pending_verified = mysqli_query($con, "
    SELECT u.id, u.first_name, u.last_name, u.email,
           u.created_at, u.email_verified_at, u.registration_method, u.verification_token,
           u.student_card_path,
           f.name AS filiere_name, l.name AS licence_name
    FROM users u
    LEFT JOIN filieres f ON f.id=u.filiere_id
    LEFT JOIN licences l ON l.id=u.licence_id
    WHERE u.status='pending' AND (u.email_verified_at IS NOT NULL OR u.verification_token IS NULL OR u.verification_token = '')
    ORDER BY u.created_at ASC");

$pending_unverified = mysqli_query($con, "
    SELECT id, first_name, last_name, email, created_at, registration_method
    FROM users
    WHERE status='pending' AND (email_verified_at IS NULL AND (verification_token IS NOT NULL AND verification_token <> ''))
    ORDER BY created_at ASC");

$pendingVerifiedUsers = [];
if ($pending_verified) {
    while ($u = mysqli_fetch_assoc($pending_verified)) {
        foreach (['first_name','last_name','email','filiere_name','licence_name'] as $f) {
            if (isset($u[$f]) && is_string($u[$f])) {
                $u[$f] = emsp_fix_mojibake($u[$f]);
            }
        }
        $created_label = '';
        if (!empty($u['created_at'])) {
            $ts = strtotime((string) $u['created_at']);
            $created_label = $ts ? date('d/m/Y', $ts) : (string) $u['created_at'];
        }
        $u['created_label'] = $created_label;
        $u['verified_label'] = !empty($u['email_verified_at']) ? (string) $u['email_verified_at'] : 'Confirme';
        $pendingVerifiedUsers[] = $u;
    }
}

$pendingUnverifiedUsers = [];
if ($pending_unverified) {
    while ($u = mysqli_fetch_assoc($pending_unverified)) {
        foreach (['first_name','last_name','email','registration_method'] as $f) {
            if (isset($u[$f]) && is_string($u[$f])) {
                $u[$f] = emsp_fix_mojibake($u[$f]);
            }
        }
        $pendingUnverifiedUsers[] = $u;
    }
}

$page_title = 'Comptes en attente';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
include __DIR__ . '/includes/navbar-top.php';
?>

<div id="admin-content">
    <div id="main-content" class="container-fluid">

<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="mb-0 fw-bold"><i class="bi bi-person-check me-2 text-warning"></i> Comptes en attente (email verifie)</h5>
</div>

<?php if (empty($pendingVerifiedUsers)): ?>
  <div class="card shadow-sm mb-4"><div class="card-body text-center text-muted py-4">
    <i class="bi bi-check-circle text-success fs-2 d-block mb-2"></i>
    Aucun compte en attente apres verification email.
  </div></div>
<?php else: ?>
  <div class="table-responsive card shadow-sm mb-4 d-none d-md-block">
    <table class="table mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>Nom</th><th>Email</th><th>Filiere / Niveau</th><th>Inscrit le</th><th>Email verifie</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($pendingVerifiedUsers as $u): ?>
        <tr>
          <td><?= h($u['first_name'].' '.$u['last_name']); ?></td>
          <td><?= h($u['email']); ?></td>
          <td><?= h($u['filiere_name'] ?? '-'); ?> / <?= h($u['licence_name'] ?? '-'); ?></td>
          <td><?= h($u['created_at']); ?></td>
          <td><span class="badge bg-success-subtle text-success"><i class="bi bi-check-circle-fill me-1"></i><?= h($u['verified_label']); ?></span></td>
          <td class="d-flex gap-2 flex-wrap">
            <form method="post" class="m-0">
              <?php csrf_input(); ?>
              <input type="hidden" name="user_id" value="<?= (int)$u['id']; ?>">
              <input type="hidden" name="action" value="approve">
              <button class="btn btn-sm btn-success" type="submit">Approuver</button>
            </form>
            <form method="post" class="m-0">
              <?php csrf_input(); ?>
              <input type="hidden" name="user_id" value="<?= (int)$u['id']; ?>">
              <input type="hidden" name="action" value="reject">
              <input type="text" name="motif" class="form-control form-control-sm" placeholder="Motif" required>
              <button class="btn btn-sm btn-outline-danger mt-1" type="submit">Rejeter</button>
            </form>
            <?php if (($u['registration_method'] ?? '') === 'manual_card' && !empty($u['student_card_path'])): ?>
              <button type="button"
                      class="btn btn-sm btn-outline-primary js-carte-btn"
                      data-user-id="<?= (int)$u['id']; ?>"
                      data-prenom="<?= h($u['first_name'] ?? ''); ?>"
                      data-nom="<?= h($u['last_name'] ?? ''); ?>"
                      data-email="<?= h($u['email'] ?? ''); ?>"
                      data-filiere="<?= h($u['filiere_name'] ?? '-'); ?>"
                      data-licence="<?= h($u['licence_name'] ?? '-'); ?>"
                      data-date="<?= h($u['created_label'] !== '' ? $u['created_label'] : '-'); ?>">
                Voir carte
              </button>
            <?php else: ?>
              <span class="text-muted small">Pas de carte</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card shadow-sm mb-4 d-md-none">
    <div class="card-body">
      <div class="emsp-admin-mobile-list">
        <?php foreach ($pendingVerifiedUsers as $u): ?>
          <div class="emsp-admin-mobile-card">
            <div class="emsp-admin-mobile-card-header">
              <div>
                <h3 class="emsp-admin-mobile-card-title mb-0"><?= h($u['first_name'].' '.$u['last_name']); ?></h3>
                <div class="emsp-admin-mobile-card-subtitle"><?= h($u['email']); ?></div>
              </div>
              <span class="badge bg-success-subtle text-success"><i class="bi bi-check-circle-fill me-1"></i><?= h($u['verified_label']); ?></span>
            </div>
            <div class="emsp-admin-mobile-meta">
              <div class="emsp-admin-mobile-meta-item">
                <span class="emsp-admin-mobile-meta-label">Filiere / Niveau</span>
                <span class="emsp-admin-mobile-meta-value"><?= h($u['filiere_name'] ?? '-'); ?> / <?= h($u['licence_name'] ?? '-'); ?></span>
              </div>
              <div class="emsp-admin-mobile-meta-item">
                <span class="emsp-admin-mobile-meta-label">Inscription</span>
                <span class="emsp-admin-mobile-meta-value"><?= h($u['created_label'] !== '' ? $u['created_label'] : (string) $u['created_at']); ?></span>
              </div>
              <div class="emsp-admin-mobile-meta-item">
                <span class="emsp-admin-mobile-meta-label">Methode</span>
                <span class="emsp-admin-mobile-meta-value"><?= h((string) ($u['registration_method'] ?? '')); ?></span>
              </div>
            </div>
            <div class="emsp-admin-mobile-actions">
              <form method="post" class="d-inline">
                <?php csrf_input(); ?>
                <input type="hidden" name="user_id" value="<?= (int)$u['id']; ?>">
                <input type="hidden" name="action" value="approve">
                <button class="btn btn-sm btn-success" type="submit"><i class="bi bi-check-lg me-1"></i>Approuver</button>
              </form>
              <?php if (($u['registration_method'] ?? '') === 'manual_card' && !empty($u['student_card_path'])): ?>
                <button type="button"
                        class="btn btn-sm btn-outline-primary js-carte-btn"
                        data-user-id="<?= (int)$u['id']; ?>"
                        data-prenom="<?= h($u['first_name'] ?? ''); ?>"
                        data-nom="<?= h($u['last_name'] ?? ''); ?>"
                        data-email="<?= h($u['email'] ?? ''); ?>"
                        data-filiere="<?= h($u['filiere_name'] ?? '-'); ?>"
                        data-licence="<?= h($u['licence_name'] ?? '-'); ?>"
                        data-date="<?= h($u['created_label'] !== '' ? $u['created_label'] : '-'); ?>">
                  <i class="bi bi-card-image me-1"></i>Voir carte
                </button>
              <?php else: ?>
                <span class="btn btn-sm btn-outline-secondary disabled" aria-disabled="true">Pas de carte</span>
              <?php endif; ?>
            </div>
            <form method="post" class="emsp-admin-mobile-inline-form mt-2">
              <?php csrf_input(); ?>
              <input type="hidden" name="user_id" value="<?= (int)$u['id']; ?>">
              <input type="hidden" name="action" value="reject">
              <input type="text" name="motif" class="form-control form-control-sm" placeholder="Motif du refus" required>
              <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-x-circle me-1"></i>Rejeter</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="mb-0 fw-bold"><i class="bi bi-envelope-paper me-2 text-secondary"></i> Emails non verifies</h5>
</div>

<?php if (empty($pendingUnverifiedUsers)): ?>
  <div class="card shadow-sm mb-4"><div class="card-body text-center text-muted py-4">
    <i class="bi bi-inbox text-secondary fs-2 d-block mb-2"></i>
    Aucun compte en attente de verification email.
  </div></div>
<?php else: ?>
  <div class="table-responsive card shadow-sm d-none d-md-block">
    <table class="table mb-0 align-middle">
      <thead class="table-light">
        <tr><th>Nom</th><th>Email</th><th>Methode</th><th>Inscrit le</th></tr>
      </thead>
      <tbody>
      <?php foreach($pendingUnverifiedUsers as $u): ?>
        <tr>
          <td><?= h($u['first_name'].' '.$u['last_name']); ?></td>
          <td><?= h($u['email']); ?></td>
          <td><?= h($u['registration_method']); ?></td>
          <td><?= h($u['created_at']); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card shadow-sm d-md-none">
    <div class="card-body">
      <div class="emsp-admin-mobile-list">
        <?php foreach ($pendingUnverifiedUsers as $u): ?>
          <div class="emsp-admin-mobile-card">
            <div class="emsp-admin-mobile-card-header">
              <div>
                <h3 class="emsp-admin-mobile-card-title mb-0"><?= h($u['first_name'].' '.$u['last_name']); ?></h3>
                <div class="emsp-admin-mobile-card-subtitle"><?= h($u['email']); ?></div>
              </div>
              <span class="badge bg-secondary-subtle text-secondary-emphasis">Email non verifie</span>
            </div>
            <div class="emsp-admin-mobile-meta">
              <div class="emsp-admin-mobile-meta-item">
                <span class="emsp-admin-mobile-meta-label">Methode</span>
                <span class="emsp-admin-mobile-meta-value"><?= h($u['registration_method']); ?></span>
              </div>
              <div class="emsp-admin-mobile-meta-item">
                <span class="emsp-admin-mobile-meta-label">Inscrit le</span>
                <span class="emsp-admin-mobile-meta-value"><?= h($u['created_at']); ?></span>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
<?php endif; ?>


    <!-- Modale carte etudiante -->
    <div class="modal fade" id="modalCarte" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow">
          <div class="modal-header border-bottom">
            <h5 class="modal-title fw-bold">
              <i class="bi bi-card-image me-2 text-primary"></i>Carte etudiante
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body p-4">
            <div class="row g-4">
              <div class="col-lg-7">
                <p class="text-muted fw-semibold small text-uppercase mb-2 emsp-letterwide">
                  <i class="bi bi-image me-1"></i>Carte fournie par l'etudiant
                </p>
                <div class="border rounded bg-light d-flex align-items-center justify-content-center p-2 emsp-preview-box">
                  <img id="modal-carte-img"
                       src="" alt="Carte etudiante"
                       class="img-fluid rounded shadow-sm emsp-preview-img"
                       onerror="this.style.display='none';document.getElementById('carte-fallback').style.display='flex'">
                  <div id="carte-fallback"
                       class="emsp-preview-fallback">
                    <i class="bi bi-file-earmark-pdf text-danger emsp-preview-fallback-icon"></i>
                    <a id="carte-pdf-link" href="#" target="_blank" rel="noopener"
                       class="btn btn-outline-danger">
                      <i class="bi bi-eye me-2"></i>Ouvrir (PDF)
                    </a>
                  </div>
                </div>
              </div>
              <div class="col-lg-5 d-flex flex-column">
                <p class="text-muted fw-semibold small text-uppercase mb-2 emsp-letterwide">
                  <i class="bi bi-person-lines-fill me-1"></i>Informations declarees
                </p>
                <table class="table table-sm table-bordered mb-3">
                  <tbody>
                    <tr class="emsp-zebra-row">
                      <td class="fw-semibold text-muted ps-3 emsp-label-cell">Prenom</td>
                      <td class="ps-3 fw-medium" id="info-prenom">-</td>
                    </tr>
                    <tr>
                      <td class="fw-semibold text-muted ps-3">Nom</td>
                      <td class="ps-3 fw-medium" id="info-nom">-</td>
                    </tr>
                    <tr class="emsp-zebra-row">
                      <td class="fw-semibold text-muted ps-3">Email</td>
                      <td class="ps-3 small emsp-w-break-all" id="info-email">-</td>
                    </tr>
                    <tr>
                      <td class="fw-semibold text-muted ps-3">Filiere</td>
                      <td class="ps-3" id="info-filiere">-</td>
                    </tr>
                    <tr class="emsp-zebra-row">
                      <td class="fw-semibold text-muted ps-3">Niveau</td>
                      <td class="ps-3" id="info-licence">-</td>
                    </tr>
                    <tr>
                      <td class="fw-semibold text-muted ps-3">Inscrit le</td>
                      <td class="ps-3 text-muted small" id="info-date">-</td>
                    </tr>
                  </tbody>
                </table>
                <div class="alert alert-info small py-2">
                  <i class="bi bi-info-circle me-2"></i>
                  Verifiez que le <strong>nom</strong>, le <strong>prenom</strong>
                  et la <strong>filiere</strong> correspondent a la carte.
                </div>
                <form method="post" id="modal-approve-form" class="mt-auto d-flex gap-2 flex-wrap">
                  <?php csrf_input(); ?>
                  <input type="hidden" name="user_id" id="modal-user-id" value="">
                  <input type="hidden" name="action" value="approve">
                  <button type="submit" class="btn btn-success">
                    <i class="bi bi-check-lg me-1"></i>Approuver
                  </button>
                  <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x me-1"></i>Fermer
                  </button>
                </form>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

</div><!-- /#main-content -->
</div><!-- /#admin-content -->
<?php
$page_scripts = <<<HTML
<script>
function ouvrirCarte(userId, prenom, nom, email, filiere, licence, date) {
    document.getElementById('info-prenom').textContent  = prenom;
    document.getElementById('info-nom').textContent     = nom;
    document.getElementById('info-email').textContent   = email;
    document.getElementById('info-filiere').textContent = filiere;
    document.getElementById('info-licence').textContent = licence;
    document.getElementById('info-date').textContent    = date;
    var idInput = document.getElementById('modal-user-id');
    if (idInput) { idInput.value = userId; }

    const url = 'voir-carte.php?user_id=' + userId;
    const img      = document.getElementById('modal-carte-img');
    const fallback = document.getElementById('carte-fallback');

    img.style.display      = 'block';
    fallback.style.display = 'none';
    img.src = url;

    document.getElementById('carte-pdf-link').href = url;

    if (!window.bootstrap || !bootstrap.Modal) {
        window.open(url, '_blank');
        return;
    }
    new bootstrap.Modal(document.getElementById('modalCarte')).show();
}

document.querySelectorAll('.js-carte-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var userId = parseInt(btn.getAttribute('data-user-id') || '0', 10) || 0;
        var prenom = btn.getAttribute('data-prenom') || '';
        var nom = btn.getAttribute('data-nom') || '';
        var email = btn.getAttribute('data-email') || '';
        var filiere = btn.getAttribute('data-filiere') || '-';
        var licence = btn.getAttribute('data-licence') || '-';
        var date = btn.getAttribute('data-date') || '-';
        ouvrirCarte(userId, prenom, nom, email, filiere, licence, date);
    });
});
</script>
HTML;
include __DIR__ . '/includes/footer.php';
?>


