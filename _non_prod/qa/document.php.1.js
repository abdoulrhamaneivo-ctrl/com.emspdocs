
(function(){
'use strict';

document.addEventListener('DOMContentLoaded', function(){
    var mime = 1;
    var ext = 1;
    var previewUrl = 'telecharger.php?id=1&preview=1';
    var rawUrl = 'telecharger.php?id=1&raw=1';

    var spinner = document.getElementById('doc-preview-spinner');
    var content = document.getElementById('doc-preview-content');
    var error = document.getElementById('doc-preview-error');

    if (!content) { return; }

    function showSpinner(show) {
        if (spinner) { spinner.style.display = show ? 'block' : 'none'; }
    }
    function clearError() {
        if (error) { error.style.display = 'none'; error.textContent = ''; }
    }
    function setError(msg) {
        if (error) {
            error.textContent = msg || 'AperÃ§u indisponible pour ce format.';
            error.style.display = 'block';
        }
    }
    function setContent(node) {
        content.innerHTML = '';
        if (node) { content.appendChild(node); }
    }
    function renderLimitedPreview(message) {
        clearError();
        showSpinner(false);

        var card = document.createElement('div');
        card.className = 'alert alert-light border rounded-4 p-4 m-3 text-start';
        card.innerHTML = ''
            + '<div class="d-flex align-items-start gap-3">'
            + '  <div class="fs-1 text-secondary"><i class="bi bi-file-earmark-lock"></i></div>'
            + '  <div>'
            + '    <h5 class="mb-2">AperÃ§u limitÃ©</h5>'
            + '    <p class="mb-2 text-muted">' + (message || 'Ce format ne peut pas Ãªtre affichÃ© directement dans le navigateur.') + '</p>'
            + '    <div class="small text-muted">Type : ' + (ext ? ext.toUpperCase() : 'FICHIER') + ' Â· Taille : 1</div>'
            + '  </div>'
            + '</div>';
        setContent(card);
    }

    var isPdf = (mime === 'application/pdf');
    var isDocx = (mime.indexOf('wordprocessingml') !== -1 || ext === 'docx');
    var isXlsx = (mime.indexOf('spreadsheetml') !== -1 || ext === 'xlsx');
    var isText = (mime.indexOf('text/') === 0
        || ['txt', 'csv', 'md', 'log', 'json', 'xml'].indexOf(ext) !== -1
        || ['application/json', 'application/xml'].indexOf(mime) !== -1);
    var isImage = (mime.indexOf('image/') === 0);
    var isMobileViewport = !!(window.matchMedia && window.matchMedia('(max-width: 767.98px)').matches);
    var shouldDeferHeavyPreview = isMobileViewport && (isPdf || isDocx || isXlsx);
    var scriptPromises = {};

    // Masquer spinner par defaut
    showSpinner(false);

    function loadScriptOnce(src) {
        if (scriptPromises[src]) {
            return scriptPromises[src];
        }

        scriptPromises[src] = new Promise(function(resolve, reject) {
            var selector = 'script[data-emsp-src="' + src + '"]';
            var existing = document.querySelector(selector);
            if (existing) {
                if (existing.dataset.loaded === '1') {
                    resolve();
                    return;
                }
                existing.addEventListener('load', function(){ resolve(); }, {once: true});
                existing.addEventListener('error', function(){ reject(new Error('script load failed')); }, {once: true});
                return;
            }

            var script = document.createElement('script');
            script.src = src;
            script.async = true;
            script.dataset.emspSrc = src;
            script.onload = function() {
                script.dataset.loaded = '1';
                resolve();
            };
            script.onerror = function() {
                reject(new Error('script load failed'));
            };
            document.body.appendChild(script);
        });

        return scriptPromises[src];
    }

    function ensureDocxPreviewLibs() {
        var tasks = [];
        if (typeof mammoth === 'undefined') {
            tasks.push(loadScriptOnce('assets/js/mammoth.browser.min.js'));
        }
        if (!window.DOMPurify) {
            tasks.push(loadScriptOnce('assets/js/purify.min.js'));
        }
        return Promise.all(tasks);
    }

    function ensureXlsxPreviewLib() {
        if (typeof XLSX !== 'undefined') {
            return Promise.resolve();
        }
        return loadScriptOnce('assets/js/xlsx.full.min.js');
    }

    function renderPreviewLauncher(options) {
        clearError();
        showSpinner(false);

        var wrap = document.createElement('div');
        wrap.className = 'text-center py-4 px-3';

        var icon = document.createElement('i');
        icon.className = 'bi ' + (options.icon || 'bi-file-earmark-text') + ' fs-2 d-block mb-3';
        icon.style.color = options.color || '#1A3C6E';

        var title = document.createElement('strong');
        title.className = 'd-block mb-2';
        title.textContent = options.title || 'PrÃ©visualisation';

        var detail = document.createElement('p');
        detail.className = 'text-muted small mb-3';
        detail.textContent = options.detail || '';

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-outline-primary';
        button.textContent = options.buttonLabel || 'Afficher lâ€™aperÃ§u';
        button.addEventListener('click', function() {
            options.onLaunch(button);
        });

        wrap.appendChild(icon);
        wrap.appendChild(title);
        if (detail.textContent !== '') {
            wrap.appendChild(detail);
        }
        wrap.appendChild(button);
        setContent(wrap);
        return button;
    }

    // Fallback sans telechargement automatique
    function showPdfFallback() {
        showSpinner(false);
        clearError();
        var msg = document.createElement('div');
        msg.className = 'alert alert-info text-center py-4';
        msg.innerHTML = '<i class="bi bi-file-earmark-pdf fs-2 d-block mb-2"></i>'
            + '<strong>AperÃ§u PDF non disponible.</strong><br>'
            + 'Votre navigateur ou une extension bloque la prÃ©visualisation.<br>'
            + '<small class="text-muted">Utilisez le bouton TÃ©lÃ©charger ci-dessous.</small>';
        setContent(msg);
    }

    function loadPdfPreview() {
        clearError();
        showSpinner(true);

        var viewer = document.createElement('iframe');
        var pdfUrl = new URL(previewUrl, window.location.href).toString();
        viewer.src = 'assets/js/pdfjs/web/viewer.html?file=' + encodeURIComponent(pdfUrl);
        viewer.title = 'AperÃ§u PDF';
        viewer.loading = 'lazy';
        viewer.style.width = '100%';
        viewer.style.minHeight = '78vh';
        viewer.style.border = '0';
        viewer.style.borderRadius = '14px';
        viewer.style.background = '#f8fafc';
        viewer.allow = 'fullscreen';
        viewer.onload = function() {
            showSpinner(false);
        };
        viewer.onerror = function() {
            showPdfFallback();
        };
        setContent(viewer);
    }

    if (isPdf) {
        if (shouldDeferHeavyPreview) {
            renderPreviewLauncher({
                icon: 'bi-file-earmark-pdf',
                color: '#dc2626',
                title: 'PrÃ©visualisation PDF Ã  la demande',
                detail: 'Sur mobile, lâ€™aperÃ§u complet est chargÃ© uniquement quand vous le demandez pour Ã©conomiser les donnÃ©es.',
                buttonLabel: 'Afficher lâ€™aperÃ§u PDF',
                onLaunch: function() { loadPdfPreview(); }
            });
            return;
        }

        loadPdfPreview();
        return;
    }

    if (isImage) {
        clearError();
        showSpinner(true);
        var img = document.createElement('img');
        img.src = previewUrl;
        img.alt = 1;
        img.style.maxWidth = '100%';
        img.style.maxHeight = '600px';
        img.style.display = 'block';
        img.style.margin = '0 auto';
        img.onload = function(){ showSpinner(false); };
        img.onerror = function(){
            showSpinner(false);
            setError("Impossible d'afficher l'image.");
        };
        setContent(img);
        return;
    }

    if (isDocx) {
        renderPreviewLauncher({
            icon: 'bi-file-earmark-word',
            color: '#2563eb',
            title: 'AperÃ§u Word Ã  la demande',
            detail: shouldDeferHeavyPreview
                ? 'Sur mobile, le document Word nâ€™est chargÃ© que lorsque vous le demandez.'
                : 'Chargez lâ€™aperÃ§u Word uniquement si vous en avez besoin.',
            buttonLabel: 'Charger lâ€™aperÃ§u Word',
            onLaunch: function(button) {
                clearError();
                showSpinner(true);
                if (button) { button.disabled = true; }

                ensureDocxPreviewLibs()
                    .then(function(){
                        return fetch(rawUrl, {credentials: 'include'});
                    })
                    .then(function(r){ if (!r.ok) throw new Error('HTTP ' + r.status); return r.arrayBuffer(); })
                    .then(function(ab){ return mammoth.convertToHtml({arrayBuffer: ab}); })
                    .then(function(res){
                        var html = (res && res.value) ? res.value : '';
                        if (window.DOMPurify) {
                            html = DOMPurify.sanitize(html);
                        }
                        var box = document.createElement('div');
                        box.style.maxHeight = '600px';
                        box.style.overflow = 'auto';
                        box.innerHTML = html || '<p class="text-muted mb-0">Aucun contenu.</p>';
                        setContent(box);
                        showSpinner(false);
                    })
                    .catch(function(){
                        showSpinner(false);
                        if (button) { button.disabled = false; }
                        setError('AperÃ§u non disponible pour ce format.');
                    });
            }
        });
        return;
    }

    if (isXlsx) {
        renderPreviewLauncher({
            icon: 'bi-file-earmark-excel',
            color: '#16a34a',
            title: 'AperÃ§u tableau Ã  la demande',
            detail: shouldDeferHeavyPreview
                ? 'Sur mobile, le tableau nâ€™est chargÃ© que lorsque vous le demandez.'
                : 'Chargez lâ€™aperÃ§u du tableau uniquement si vous en avez besoin.',
            buttonLabel: 'Charger lâ€™aperÃ§u tableau',
            onLaunch: function(button) {
                clearError();
                showSpinner(true);
                if (button) { button.disabled = true; }

                ensureXlsxPreviewLib()
                    .then(function(){
                        return fetch(rawUrl, {credentials: 'include'});
                    })
                    .then(function(r){ if (!r.ok) throw new Error('HTTP ' + r.status); return r.arrayBuffer(); })
                    .then(function(ab){
                        var data = new Uint8Array(ab);
                        var wb = XLSX.read(data, {type: 'array'});
                        var sheetName = (wb.SheetNames && wb.SheetNames[0]) ? wb.SheetNames[0] : null;
                        if (!sheetName) { throw new Error('No sheet'); }
                        var ws = wb.Sheets[sheetName];
                        var range = null;
                        if (ws && ws['!ref']) {
                            range = XLSX.utils.decode_range(ws['!ref']);
                            range.e.r = Math.min(range.e.r, 99);
                        }
                        var html = XLSX.utils.sheet_to_html(ws, range ? {range: range} : undefined);
                        var wrap = document.createElement('div');
                        wrap.style.overflowX = 'auto';
                        wrap.innerHTML = html;
                        var table = wrap.querySelector('table');
                        if (table) { table.className = 'table table-sm table-bordered mb-0'; }
                        setContent(wrap);
                        showSpinner(false);
                    })
                    .catch(function(){
                        showSpinner(false);
                        if (button) { button.disabled = false; }
                        setError('AperÃ§u non disponible pour ce format.');
                    });
            }
        });
        return;
    }

    if (isText) {
        clearError();
        showSpinner(true);
        fetch(rawUrl, {credentials: 'include'})
            .then(function(r){ if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
            .then(function(txt){
                var pre = document.createElement('pre');
                pre.style.maxHeight = '500px';
                pre.style.overflow = 'auto';
                pre.style.whiteSpace = 'pre-wrap';
                pre.textContent = txt || '';
                setContent(pre);
                showSpinner(false);
            })
            .catch(function(){
                renderLimitedPreview('Le contenu texte nâ€™a pas pu Ãªtre chargÃ© dans lâ€™aperÃ§u.');
            });
        return;
    }

    renderLimitedPreview('Ce format reste tÃ©lÃ©chargeable explicitement, mais nâ€™est pas rendu inline pour Ã©viter tout comportement de tÃ©lÃ©chargement implicite.');
});

// Like AJAX
var btnLike = document.getElementById('btn-like');
if (btnLike) {
    btnLike.addEventListener('click', function(){
        var errEl = document.getElementById('lk-error');
        if (errEl) { errEl.style.display = 'none'; errEl.textContent = ''; }
        var fd = new FormData();
        fd.append('document_id', this.dataset.doc);
        fd.append('csrf_token', '1');
        fetch('like-handler.php', {method: 'POST', body: fd, credentials: 'include'})
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (d && d.error) {
                    if (errEl) {
                        errEl.textContent = d.message || 'Action impossible.';
                        errEl.style.display = 'inline-flex';
                    }
                    if (window.emspUI && typeof window.emspUI.showError === 'function') {
                        window.emspUI.showError('Like indisponible', d.message || 'Action impossible.');
                    }
                    return;
                }
                document.getElementById('lk-count').textContent = d.like_count;
                document.getElementById('lk-label').textContent = d.like_count == 1 ? 'like' : 'likes';
                var icon = btnLike.querySelector('i');
                if (d.liked) {
                    btnLike.classList.add('liked');
                    icon.className = 'bi bi-heart-fill';
                } else {
                    btnLike.classList.remove('liked');
                    icon.className = 'bi bi-heart';
                }
            })
            .catch(function(){
                if (errEl) {
                    errEl.textContent = 'Erreur reseau.';
                    errEl.style.display = 'inline-flex';
                }
                if (window.emspUI && typeof window.emspUI.showError === 'function') {
                    window.emspUI.showError('Erreur rÃ©seau', 'Impossible de contacter le serveur pour le like.');
                }
            });
    });
}

// Favori AJAX
var btnFav = document.getElementById('btn-fav');
if (btnFav) {
    btnFav.addEventListener('click', function(){
        if (btnFav.disabled) { return; }
        btnFav.disabled = true;
        var fd = new FormData();
        fd.append('document_id', btnFav.dataset.docId || '');
        fd.append('csrf_token', '1');
        fetch('ajax/toggle-favori.php', {method: 'POST', body: fd, credentials: 'include'})
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (!d || !d.ok) {
                    if (window.emspUI && typeof window.emspUI.showError === 'function') {
                        window.emspUI.showError('Favori indisponible', d && d.message ? d.message : 'Impossible de mettre Ã  jour vos favoris.');
                    }
                    return;
                }
                var added = d.action === 'added';
                btnFav.dataset.fav = added ? '1' : '0';
                btnFav.setAttribute('aria-pressed', added ? 'true' : 'false');
                btnFav.classList.toggle('is-fav', added);
                btnFav.classList.toggle('not-fav', !added);
                var icon = btnFav.querySelector('i');
                if (icon) { icon.className = added ? 'bi bi-star-fill' : 'bi bi-star'; }
                var label = btnFav.querySelector('.btn-fav-label');
                if (label) { label.textContent = added ? 'Retirer des favoris' : 'Ajouter aux favoris'; }
            })
            .catch(function(){
                if (window.emspUI && typeof window.emspUI.showError === 'function') {
                    window.emspUI.showError('Erreur rÃ©seau', 'Impossible de mettre Ã  jour vos favoris pour le moment.');
                }
            })
            .then(function(){ btnFav.disabled = false; });
    });
}

// Reactions commentaires
document.querySelectorAll('.react-btn').forEach(function(btn){
    btn.addEventListener('click', function(){
        var commentId = btn.dataset.comment || '';
        var reaction = btn.dataset.reaction || '';
        if (!commentId || !reaction) { return; }
        var errorBox = btn.closest('.cmt-item') ? btn.closest('.cmt-item').querySelector('.cmt-react-error') : null;
        if (errorBox) { errorBox.textContent = ''; errorBox.style.display = 'none'; }
        var fd = new FormData();
        fd.append('comment_id', commentId);
        fd.append('reaction', reaction);
        fd.append('csrf_token', '1');
        fetch('reaction-handler.php', {method: 'POST', body: fd, credentials: 'include'})
            .then(function(r){
                return r.text().then(function(text){
                    var payload = {};
                    try {
                        payload = text ? JSON.parse(text) : {};
                    } catch (error) {
                        payload = { error: 'invalid_json', message: 'Reponse serveur invalide.' };
                    }
                    if (!r.ok && !payload.error) {
                        payload.error = 'http_' + r.status;
                        payload.message = 'Le serveur a refuse la reaction (' + r.status + ').';
                    }
                    return payload;
                });
            })
            .then(function(d){
                if (!d || d.error) {
                    if (errorBox) {
                        errorBox.textContent = (d && d.message) ? d.message : 'Impossible de reagir pour le moment.';
                        errorBox.style.display = 'block';
                    }
                    if (window.emspUI && typeof window.emspUI.showError === 'function') {
                        window.emspUI.showError('RÃ©action indisponible', (d && d.message) ? d.message : 'Impossible de rÃ©agir pour le moment.');
                    }
                    return;
                }
                var wrap = btn.closest('.cmt-reactions');
                if (!wrap) { return; }
                wrap.querySelectorAll('.react-btn').forEach(function(b){
                    var key = b.dataset.reaction || '';
                    var countEl = b.querySelector('.react-count');
                    var count = (d.counts && key && d.counts[key] !== undefined) ? d.counts[key] : 0;
                    if (countEl) { countEl.textContent = count; }
                    b.classList.toggle('active', d.my_reaction === key);
                });
            })
            .catch(function(){
                if (errorBox) {
                    errorBox.textContent = 'Erreur reseau. Verifiez votre connexion puis reessayez.';
                    errorBox.style.display = 'block';
                }
                if (window.emspUI && typeof window.emspUI.showError === 'function') {
                    window.emspUI.showError('Erreur rÃ©seau', 'VÃ©rifiez votre connexion puis rÃ©essayez.');
                }
            });
    });
});

// Toggle reponse
document.querySelectorAll('.toggle-reply').forEach(function(btn){
    btn.addEventListener('click', function(){
        var f = document.getElementById('rep-form-' + this.dataset.comment);
        if (!f) return;
        var v = f.style.display !== 'none';
        f.style.display = v ? 'none' : 'block';
        if (!v) { var ta = f.querySelector('textarea'); if (ta) ta.focus(); }
    });
});

})();


