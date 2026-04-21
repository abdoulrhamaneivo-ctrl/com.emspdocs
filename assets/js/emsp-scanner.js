const EMSPScan = (function () {
    'use strict';

    const state = {
        open: false,
        pages: [],
        stream: null,
        busy: false,
    };

    const selectors = {
        docInput: '#documentInput',
        uploadForm: '#uploadForm',
        titleInput: '#titleInput',
    };

    const css = `
.emsp-fab-stack{position:fixed;right:14px;bottom:calc(96px + env(safe-area-inset-bottom));z-index:9999;display:flex;flex-direction:column;gap:10px;align-items:flex-end}
.emsp-scan-fab,.emsp-upload-fab{width:46px;height:46px;border-radius:50%;color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.05rem;box-shadow:0 14px 26px rgba(26,60,110,.22);border:1px solid rgba(255,255,255,.2)}
.emsp-scan-fab{position:relative;background:#1a3c6e}
.emsp-upload-fab{background:#0b6b3a}
.emsp-scan-fab .badge{position:absolute;top:-6px;right:-6px;background:#e63946;color:#fff;border-radius:999px;font-size:.65rem;padding:.1rem .35rem;font-weight:700}
.emsp-scan-overlay{position:fixed;inset:0;background:rgba(8,20,40,.9);color:#fff;z-index:10000;display:none;flex-direction:column}
.emsp-scan-overlay.show{display:flex}
.emsp-scan-header{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid rgba(255,255,255,.12)}
.emsp-scan-title{font-weight:700}
.emsp-scan-body{flex:1;display:flex;flex-direction:column;gap:12px;padding:14px}
.emsp-scan-video-wrap{flex:1;display:flex;align-items:center;justify-content:center;background:#000;border-radius:16px;overflow:hidden;position:relative}
.emsp-scan-video{width:100%;height:100%;object-fit:cover}
.emsp-scan-actions{display:flex;gap:10px;flex-wrap:wrap}
.emsp-scan-btn{border:1px solid rgba(255,255,255,.2);background:rgba(255,255,255,.08);color:#fff;border-radius:10px;padding:.6rem .9rem;font-weight:600}
.emsp-scan-btn.primary{background:#35a368;border-color:#35a368}
.emsp-scan-btn.warn{background:#f4a261;border-color:#f4a261}
.emsp-scan-pages{display:none;flex-direction:column;gap:10px;max-height:45vh;overflow:auto}
.emsp-scan-pages.show{display:flex}
.emsp-scan-page{display:flex;align-items:center;gap:10px;background:rgba(255,255,255,.06);padding:8px;border-radius:10px}
.emsp-scan-thumb{width:48px;height:64px;background:#fff;border-radius:6px;overflow:hidden;flex-shrink:0}
.emsp-scan-thumb img{width:100%;height:100%;object-fit:cover}
.emsp-scan-meta{flex:1;font-size:.85rem}
.emsp-scan-inline{font-size:.8rem;opacity:.8}
.emsp-scan-close{background:transparent;border:0;color:#fff;font-size:1.2rem}
.emsp-scan-fallback{display:none;align-items:center;justify-content:center;gap:8px;color:#fff;font-size:.85rem}
.emsp-scan-fallback.show{display:flex}
.emsp-scan-steps{font-size:.85rem;opacity:.85}
.emsp-scan-steps strong{color:#fff}
.emsp-upload-highlight{outline:2px solid rgba(53,163,104,.6);outline-offset:4px;border-radius:14px;transition:box-shadow .25s ease}
@media (min-width:992px){.emsp-fab-stack{display:none}}
`;

    function injectCss() {
        if (document.getElementById('emsp-scan-css')) return;
        const style = document.createElement('style');
        style.id = 'emsp-scan-css';
        style.textContent = css;
        document.head.appendChild(style);
    }

    function isCompactViewport() {
        return window.matchMedia('(max-width: 991.98px)').matches;
    }

    function canUseFloatingActions() {
        const isAuthenticated = document.body.dataset.authenticated === '1';
        return isAuthenticated;
    }

    function isUploadPage() {
        return (document.body.dataset.route || '') === 'upload.php' || !!document.querySelector(selectors.docInput);
    }

    function shouldAutoOpenScan() {
        try {
            return new URLSearchParams(window.location.search).get('scan') === '1';
        } catch (err) {
            return false;
        }
    }

    function cleanupAutoOpenParam() {
        try {
            const url = new URL(window.location.href);
            if (!url.searchParams.has('scan')) return;
            url.searchParams.delete('scan');
            window.history.replaceState({}, document.title, url.toString());
        } catch (err) {
            // Ignore URL cleanup failures.
        }
    }

    function goToUpload(scanFirst) {
        const target = scanFirst ? 'upload.php?scan=1' : 'upload.php';
        window.location.href = target;
    }

    function createFabStack() {
        if (document.getElementById('emsp-fab-stack')) return;
        const stack = document.createElement('div');
        stack.id = 'emsp-fab-stack';
        stack.className = 'emsp-fab-stack';

        const fab = document.createElement('button');
        fab.type = 'button';
        fab.id = 'emsp-scan-fab';
        fab.className = 'emsp-scan-fab';
        fab.innerHTML = '<i class="bi bi-camera"></i><span class="badge" style="display:none">0</span>';
        fab.setAttribute('aria-label', 'Scanner un document');
        fab.title = 'Scanner un document';
        fab.addEventListener('click', function () {
            if (isUploadPage()) {
                openScanner();
            } else {
                goToUpload(true);
            }
        });

        const uploadFab = document.createElement('a');
        uploadFab.id = 'emsp-upload-fab';
        uploadFab.className = 'emsp-upload-fab';
        uploadFab.href = 'upload.php';
        uploadFab.setAttribute('aria-label', 'Ouvrir la page de dépôt');
        uploadFab.title = 'Déposer un document';
        uploadFab.innerHTML = '<i class="bi bi-cloud-arrow-up"></i>';

        stack.appendChild(uploadFab);
        stack.appendChild(fab);
        document.body.appendChild(stack);
        syncFabVisibility();
    }

    function updateFabBadge() {
        const badge = document.querySelector('#emsp-scan-fab .badge');
        if (!badge) return;
        const count = state.pages.length;
        if (count > 0) {
            badge.style.display = 'inline-flex';
            badge.textContent = String(count);
        } else {
            badge.style.display = 'none';
        }
    }

    function ensureOverlay() {
        if (document.getElementById('emsp-scan-overlay')) return;
        const overlay = document.createElement('div');
        overlay.id = 'emsp-scan-overlay';
        overlay.className = 'emsp-scan-overlay';
        overlay.innerHTML = `
        <div class="emsp-scan-header">
            <div class="emsp-scan-title">Scanner un document</div>
            <button class="emsp-scan-close" type="button" aria-label="Fermer">&times;</button>
        </div>
        <div class="emsp-scan-body">
            <div class="emsp-scan-steps">
                <strong>Étapes:</strong> 1) Capture les pages. 2) Vérifie si besoin. 3) Ajoute le PDF au formulaire pour continuer le dépôt.
            </div>
            <div class="emsp-scan-video-wrap">
                <video class="emsp-scan-video" autoplay playsinline></video>
            </div>
            <div class="emsp-scan-fallback">
                <span>Caméra bloquée. Utilise le bouton ci‑dessous.</span>
            </div>
            <div class="emsp-scan-actions">
                <button class="emsp-scan-btn primary" type="button" data-action="capture">Capturer</button>
                <button class="emsp-scan-btn" type="button" data-action="pages">Voir les pages</button>
                <button class="emsp-scan-btn warn" type="button" data-action="pdf">Ajouter le PDF au dépôt</button>
                <label class="emsp-scan-btn" style="margin:0;">
                    <input type="file" accept="image/*" capture="environment" style="display:none" />
                    Utiliser l’appareil photo
                </label>
            </div>
            <div class="emsp-scan-pages" id="emsp-scan-pages"></div>
            <div class="emsp-scan-inline">Conseil: après ajout du PDF, complète le formulaire juste en dessous pour publier le document.</div>
        </div>`;
        document.body.appendChild(overlay);

        overlay.querySelector('.emsp-scan-close').addEventListener('click', closeScanner);
        overlay.querySelector('[data-action="capture"]').addEventListener('click', captureFrame);
        overlay.querySelector('[data-action="pages"]').addEventListener('click', togglePages);
        overlay.querySelector('[data-action="pdf"]').addEventListener('click', generatePdf);
        const input = overlay.querySelector('input[type="file"]');
        input.addEventListener('change', (e) => {
            if (e.target.files && e.target.files[0]) {
                addPageFromFile(e.target.files[0]);
            }
        });
    }

    async function openScanner() {
        if (!isCompactViewport() || !canUseFloatingActions() || !isUploadPage()) return;
        injectCss();
        ensureOverlay();
        const overlay = document.getElementById('emsp-scan-overlay');
        overlay.classList.add('show');
        state.open = true;
        updateFabBadge();
        await startCamera();
    }

    function closeScanner() {
        const overlay = document.getElementById('emsp-scan-overlay');
        if (overlay) overlay.classList.remove('show');
        stopCamera();
        state.open = false;
    }

    function clearPages() {
        state.pages.forEach((page) => {
            if (page && page.url) {
                URL.revokeObjectURL(page.url);
            }
        });
        state.pages = [];
        updatePages();
        updateFabBadge();
    }

    async function startCamera() {
        const overlay = document.getElementById('emsp-scan-overlay');
        if (!overlay) return;
        const video = overlay.querySelector('video');
        const fallback = overlay.querySelector('.emsp-scan-fallback');
        try {
            state.stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: { ideal: 'environment' } },
                audio: false,
            });
            video.srcObject = state.stream;
            fallback.classList.remove('show');
        } catch (err) {
            fallback.classList.add('show');
        }
    }

    function stopCamera() {
        if (state.stream) {
            state.stream.getTracks().forEach(t => t.stop());
            state.stream = null;
        }
    }

    function captureFrame() {
        if (state.busy) return;
        const overlay = document.getElementById('emsp-scan-overlay');
        const video = overlay.querySelector('video');
        if (!video || !video.videoWidth) return;
        state.busy = true;
        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        canvas.toBlob((blob) => {
            if (blob) {
                addPageBlob(blob);
            }
            state.busy = false;
        }, 'image/jpeg', 0.92);
    }

    function addPageBlob(blob) {
        const url = URL.createObjectURL(blob);
        state.pages.push({ blob, url });
        updatePages();
        updateFabBadge();
        const wrap = document.getElementById('emsp-scan-pages');
        if (wrap) wrap.classList.add('show');
    }

    function addPageFromFile(file) {
        const reader = new FileReader();
        reader.onload = function (e) {
            const blob = dataUrlToBlob(e.target.result);
            addPageBlob(blob);
        };
        reader.readAsDataURL(file);
    }

    function updatePages() {
        const wrap = document.getElementById('emsp-scan-pages');
        if (!wrap) return;
        wrap.innerHTML = '';
        state.pages.forEach((p, idx) => {
            const row = document.createElement('div');
            row.className = 'emsp-scan-page';
            row.innerHTML = `
                <div class="emsp-scan-thumb"><img src="${p.url}" alt="Page ${idx + 1}"></div>
                <div class="emsp-scan-meta">Page ${idx + 1}</div>
                <button class="emsp-scan-btn" type="button" data-remove="${idx}">Supprimer</button>
            `;
            row.querySelector('[data-remove]').addEventListener('click', () => {
                URL.revokeObjectURL(p.url);
                state.pages.splice(idx, 1);
                updatePages();
                updateFabBadge();
            });
            wrap.appendChild(row);
        });
    }

    function togglePages() {
        const wrap = document.getElementById('emsp-scan-pages');
        if (!wrap) return;
        wrap.classList.toggle('show');
    }

    async function generatePdf() {
        if (state.pages.length === 0) return;
        const jspdf = window.jspdf && window.jspdf.jsPDF;
        if (!jspdf) {
            alert('Le module PDF n’est pas chargé.');
            return;
        }
        const doc = new jspdf({ orientation: 'portrait', unit: 'pt', format: 'a4' });
        for (let i = 0; i < state.pages.length; i++) {
            const imgData = await blobToDataUrl(state.pages[i].blob);
            const pageWidth = doc.internal.pageSize.getWidth();
            const pageHeight = doc.internal.pageSize.getHeight();
            const img = new Image();
            await new Promise(res => { img.onload = res; img.src = imgData; });
            const ratio = Math.min(pageWidth / img.width, pageHeight / img.height);
            const w = img.width * ratio;
            const h = img.height * ratio;
            const x = (pageWidth - w) / 2;
            const y = (pageHeight - h) / 2;
            if (i > 0) doc.addPage();
            doc.addImage(imgData, 'JPEG', x, y, w, h, undefined, 'FAST');
        }
        const pdfBlob = doc.output('blob');
        const file = new File([pdfBlob], `scan_${Date.now()}.pdf`, { type: 'application/pdf' });
        injectIntoUpload(file);
        clearPages();
        closeScanner();
    }

    function injectIntoUpload(file) {
        const input = document.querySelector(selectors.docInput);
        if (input) {
            const dt = new DataTransfer();
            dt.items.add(file);
            input.files = dt.files;
            const changeEvent = new Event('change', { bubbles: true });
            input.dispatchEvent(changeEvent);
            const form = document.querySelector(selectors.uploadForm) || input.closest('form');
            if (form) {
                form.classList.add('emsp-upload-highlight');
                form.scrollIntoView({ behavior: 'smooth', block: 'start' });
                setTimeout(() => form.classList.remove('emsp-upload-highlight'), 2200);
            }
            const titleInput = document.querySelector(selectors.titleInput);
            if (titleInput) {
                setTimeout(() => titleInput.focus(), 250);
            }
            if (window.Swal && typeof window.Swal.fire === 'function') {
                window.Swal.fire({
                    icon: 'success',
                    title: 'PDF ajouté',
                    text: 'Le fichier est prêt. Complète maintenant les informations puis valide le dépôt.',
                    timer: 2200,
                    showConfirmButton: false
                });
            } else {
                alert('Le fichier est prêt. Complète maintenant les informations puis valide le dépôt.');
            }
            return;
        }
        const url = URL.createObjectURL(file);
        const a = document.createElement('a');
        a.href = url;
        a.download = file.name;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    }

    function syncFabVisibility() {
        const stack = document.getElementById('emsp-fab-stack');
        if (!stack) return;
        const uploadFab = document.getElementById('emsp-upload-fab');
        stack.style.display = (isCompactViewport() && canUseFloatingActions()) ? 'flex' : 'none';
        if (uploadFab) {
            uploadFab.style.display = isUploadPage() ? 'none' : 'flex';
        }
    }

    function ensureFloatingActions() {
        if (!canUseFloatingActions()) {
            syncFabVisibility();
            return;
        }
        injectCss();
        createFabStack();
        syncFabVisibility();
    }

    function dataUrlToBlob(dataUrl) {
        const arr = dataUrl.split(',');
        const mime = arr[0].match(/:(.*?);/)[1];
        const bstr = atob(arr[1]);
        let n = bstr.length;
        const u8arr = new Uint8Array(n);
        while (n--) u8arr[n] = bstr.charCodeAt(n);
        return new Blob([u8arr], { type: mime });
    }

    function blobToDataUrl(blob) {
        return new Promise((resolve) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result);
            reader.readAsDataURL(blob);
        });
    }

    function init() {
        ensureFloatingActions();
        if (isUploadPage() && shouldAutoOpenScan()) {
            cleanupAutoOpenParam();
            window.setTimeout(openScanner, 250);
        }
    }

    window.addEventListener('DOMContentLoaded', init);
    window.addEventListener('resize', () => {
        ensureFloatingActions();
    });
    window.addEventListener('pageshow', function () {
        ensureFloatingActions();
    });
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') {
            ensureFloatingActions();
        }
    });

    return { open: openScanner };
})();


