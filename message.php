<?php
// Affichage du message flash et suppression immÃ©diate
if (!empty($_SESSION['message'])): ?>
    <div class="alert alert-info alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($_SESSION['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php
    unset($_SESSION['message']);
endif;


