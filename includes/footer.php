<?php
$base = $base ?? '';
$asset = $asset ?? ($base . 'assets/');
$isAuth = !empty($_SESSION['auth']) || !empty($_SESSION['auth_user']['id']);
$currentRoute = str_replace('.php', '', (string) ($currentBodyPath ?? ''));
?>
</main>
<footer class="emsp-footer" role="contentinfo">
    <div class="emsp-footer-band" aria-hidden="true"></div>
    <div class="container emsp-footer-grid-wrap">
        <div class="row g-4 g-lg-5 emsp-footer-grid">
            <div class="col-12 col-lg-4">
                <a class="emsp-footer-brand" href="<?= $base ?>index.php">
                    <img src="<?= $asset ?>images/logo-emsp.png" alt="Logo EMSP" class="emsp-footer-logo" loading="lazy">
                    <span class="emsp-footer-brand-copy">
                        <span class="emsp-footer-title">EMSP Docs</span>
                        <span class="emsp-footer-copy">Plateforme académique pour les cours, TD, examens, concours et documents partagés de la communauté EMSP.</span>
                    </span>
                </a>
                <div class="emsp-footer-socials" aria-label="Réseaux sociaux EMSP">
                    <a href="https://www.facebook.com/emspabidjan/?locale=fr_FR" target="_blank" rel="noopener" aria-label="EMSP sur Facebook" title="Facebook EMSP"><i class="bi bi-facebook" aria-hidden="true"></i></a>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-lg-2">
                <h5 class="emsp-footer-heading">Liens rapides</h5>
                <ul class="emsp-footer-links">
                    <li><a href="<?= $base ?>index.php">Accueil</a></li>
                    <li><a href="<?= $base ?>formations.php">Cours</a></li>
                    <li><a href="<?= $base ?>bibliotheque.php">Documents</a></li>
                    <li><a href="<?= $base ?>institution.php">À propos</a></li>
                </ul>
            </div>
            <div class="col-12 col-sm-6 col-lg-3">
                <h5 class="emsp-footer-heading">Ressources</h5>
                <ul class="emsp-footer-links">
                    <li><a href="<?= $base ?>faq.php">Aide</a></li>
                    <li><a href="<?= $base ?>message.php">Contact</a></li>
                    <li><a href="<?= $base ?>faq.php#faq-list">FAQ</a></li>
                    <li><a href="<?= $base ?>pending-status.php">Suivi d'inscription</a></li>
                </ul>
            </div>
            <div class="col-12 col-lg-3">
                <h5 class="emsp-footer-heading">Contact</h5>
                <ul class="emsp-footer-links emsp-footer-contact">
                    <li><i class="bi bi-geo-alt-fill" aria-hidden="true"></i><span>Abidjan, Treichville, Zone 3, Km4</span></li>
                    <li><i class="bi bi-envelope-fill" aria-hidden="true"></i><span>contact@emsp.int</span></li>
                    <li><i class="bi bi-telephone-fill" aria-hidden="true"></i><span>+225 27 21 21 45 60 / 61</span></li>
                </ul>
                <p class="emsp-footer-context mb-0"><?= $isAuth ? 'Espace étudiant actif: consulte tes ressources et notifications en temps réel.' : 'Inscris-toi pour accéder à la bibliothèque académique complète EMSP.' ?></p>
            </div>
        </div>
    </div>
    <div class="emsp-footer-bottom">
        <div class="container d-flex flex-wrap justify-content-between gap-2">
            <span>&copy; 2026 EMSP Docs. Tous droits réservés.</span>
            <span class="emsp-footer-legal">
                <a href="<?= $base ?>faq.php">Mentions</a>
                <span aria-hidden="true">|</span>
                <a href="<?= $base ?>faq.php">Politique</a>
                <span aria-hidden="true">|</span>
                <a href="<?= $base ?>message.php">Contact</a>
            </span>
        </div>
    </div>
</footer>
<div class="emsp-install-banner" data-emsp-install-banner>
    <div class="emsp-install-banner-content">
        <strong>Installer EMSP Docs</strong>
        <span>Acces rapide depuis l ecran d accueil, meme hors connexion.</span>
    </div>
    <div class="emsp-install-banner-actions">
        <button type="button" class="btn btn-sm btn-primary" data-emsp-install-btn>Installer</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-emsp-install-close>Plus tard</button>
    </div>
</div>

<?php if ($isAuth): ?>
<nav class="pwa-bottom-nav" aria-label="Navigation rapide">
    <a href="<?= $base ?>index.php" class="pwa-bottom-nav-link">
        <i class="bi bi-house-door"></i>
        <span>Accueil</span>
    </a>
    <a href="<?= $base ?>bibliotheque.php" class="pwa-bottom-nav-link">
        <i class="bi bi-collection"></i>
        <span>Docs</span>
    </a>
    <a href="<?= $base ?>upload.php" class="pwa-bottom-nav-link pwa-nav-upload">
        <i class="bi bi-cloud-arrow-up"></i>
        <span>Depot</span>
    </a>
    <a href="<?= $base ?>dashboard.php" class="pwa-bottom-nav-link">
        <i class="bi bi-speedometer2"></i>
        <span>Espace</span>
    </a>
</nav>
<?php endif; ?>

<script src="<?= $asset ?>js/bootstrap5.bundle.min.js"></script>
<script src="<?= $asset ?>js/jquery.min.js"></script>
<script src="<?= $asset ?>vendor/sweetalert2/sweetalert2.all.min.js"></script>
<script src="<?= $asset ?>vendor/select2/select2.full.min.js"></script>
<script src="<?= $asset ?>js/emsp-ui.js"></script>
<script src="<?= $asset ?>js/emsp-fixes.js"></script>
<script src="<?= $asset ?>js/emsp-harvard-motion.js"></script>
<script src="<?= $asset ?>js/emsp-experience-upgrade.js"></script>
<script src="<?= $asset ?>js/pwa.js"></script>
<?php
$pagesWithPdf = ['document', 'dashboard', 'mon-profil'];
if (in_array($currentRoute, $pagesWithPdf, true)):
?>
<script src="<?= $asset ?>js/jspdf.umd.min.js"></script>
<?php endif; ?>
<script src="<?= $asset ?>js/emsp-scanner.js"></script>
<?php if (strpos((string) ($currentBodyPath ?? ''), 'bibliotheque') !== false || strpos((string) ($currentBodyPath ?? ''), 'mes-favoris') !== false): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script>
(function() {
    if (window.__emspPdfThumbInitDone) return;
    window.__emspPdfThumbInitDone = true;
    if (typeof pdfjsLib === 'undefined') return;
    pdfjsLib.GlobalWorkerOptions.workerSrc =
        'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

    var canvases = document.querySelectorAll('.pdf-thumb-canvas[data-pdf-url], .pdf-thumb-canvas[data-pdf-src]');
    if (!canvases.length) return;

    canvases.forEach(function(canvas) {
        var url = canvas.dataset.pdfUrl || canvas.dataset.pdfSrc;
        if (!url) return;
        pdfjsLib.getDocument(url).promise.then(function(pdf) {
            return pdf.getPage(1);
        }).then(function(page) {
            var vp = page.getViewport({ scale: 0.6 });
            canvas.width = vp.width;
            canvas.height = vp.height;
            return page.render({
                canvasContext: canvas.getContext('2d'),
                viewport: vp
            }).promise;
        }).catch(function() {
            var wrap = canvas.closest('.thumb-pdf-canvas-wrap');
            if (wrap) wrap.classList.add('pdf-failed');
        });
    });
})();
</script>
<?php endif; ?>
<script>
(function () {
    function initTooltips() {
        if (!window.bootstrap || !bootstrap.Tooltip) {
            return;
        }
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
            if (!bootstrap.Tooltip.getInstance(el)) {
                new bootstrap.Tooltip(el);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initTooltips);
    } else {
        initTooltips();
    }
})();
</script>
<?php if (!empty($page_scripts)) { echo $page_scripts; } ?>
</body>
</html>
