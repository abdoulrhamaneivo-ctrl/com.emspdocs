
    (function () {
        var previewContent = document.getElementById('previewContent');
        var previewUrl = null;
        var rawUrl = null;
        var mime = null;
        var ext = null;

        function notifyParent(status, message) {
            if (!window.parent || window.parent === window) {
                return;
            }
            try {
                window.parent.postMessage({
                    type: 'emsp-preview-status',
                    status: status,
                    message: message || ''
                }, '*');
            } catch (error) {
                // no-op
            }
        }

        notifyParent('loading', 'Chargement de l apercu...');
        var loadingFallback = setTimeout(function () {
            if (!previewContent) return;
            if (previewContent.textContent && previewContent.textContent.toLowerCase().indexOf('chargement') !== -1) {
                previewContent.innerHTML = '<div class="viewer-empty"><strong>Chargement long</strong><span>L apercu met plus de temps que prevu. Utilisez le bouton de telechargement explicite si necessaire.</span></div>';
                notifyParent('limited', 'Chargement long. L apercu reste disponible mais prend plus de temps que prevu.');
            }
        }, 8000);

        function setHtml(html) {
            if (loadingFallback) {
                clearTimeout(loadingFallback);
                loadingFallback = null;
            }
            previewContent.innerHTML = html;
        }

        function loadScriptOnce(src) {
            return new Promise(function (resolve, reject) {
                var existing = document.querySelector('script[data-emsp-src="' + src + '"]');
                if (existing) {
                    if (existing.dataset.loaded === '1') {
                        resolve();
                        return;
                    }
                    existing.addEventListener('load', function () { resolve(); }, { once: true });
                    existing.addEventListener('error', function () { reject(new Error('load failed')); }, { once: true });
                    return;
                }
                var script = document.createElement('script');
                script.src = src;
                script.async = true;
                script.dataset.emspSrc = src;
                script.onload = function () {
                    script.dataset.loaded = '1';
                    resolve();
                };
                script.onerror = function () {
                    reject(new Error('load failed'));
                };
                document.body.appendChild(script);
            });
        }

        function renderUnavailable(message) {
            setHtml(
                '<div class="viewer-empty">'
                + '<strong>Apercu limite</strong>'
                + '<span>' + message + '</span>'
                + '</div>'
            );
            notifyParent('limited', message);
        }

        // Keep the admin moderation preview aligned with the public document page:
        // full render when possible, limited preview otherwise, never implicit download.
        if (mime === 'application/pdf' || ext === 'pdf') {
            setHtml('<iframe class="viewer-frame" src="../assets/js/pdfjs/web/viewer.html?file=' + encodeURIComponent(new URL(previewUrl, window.location.href).toString()) + '" title="Apercu PDF"></iframe>');
            notifyParent('ready', 'Apercu PDF charge.');
            return;
        }

        if ((mime || '').indexOf('image/') === 0 || ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'].indexOf(ext) !== -1) {
            setHtml('<div class="viewer-box"><img class="viewer-image" src="' + previewUrl + '" alt="Apercu image"></div>');
            notifyParent('ready', 'Apercu image charge.');
            return;
        }

        if ((mime || '').indexOf('wordprocessingml') !== -1 || ext === 'docx') {
            loadScriptOnce('../assets/js/mammoth.browser.min.js')
                .then(function () { return loadScriptOnce('../assets/js/purify.min.js'); })
                .then(function () { return fetch(rawUrl, { credentials: 'include' }); })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }
                    return response.arrayBuffer();
                })
                .then(function (buffer) { return mammoth.convertToHtml({ arrayBuffer: buffer }); })
                .then(function (result) {
                    var html = result && result.value ? result.value : '';
                    if (window.DOMPurify) {
                        html = DOMPurify.sanitize(html);
                    }
                    setHtml('<div class="viewer-box">' + (html || '<p class="text-muted">Aucun contenu lisible.</p>') + '</div>');
                    notifyParent('ready', 'Apercu Word charge.');
                })
                .catch(function () {
                    renderUnavailable('Le document Word ne peut pas etre rendu ici pour le moment.');
                });
            return;
        }

        if ((mime || '').indexOf('spreadsheetml') !== -1 || ext === 'xlsx') {
            loadScriptOnce('../assets/js/xlsx.full.min.js')
                .then(function () { return fetch(rawUrl, { credentials: 'include' }); })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }
                    return response.arrayBuffer();
                })
                .then(function (buffer) {
                    var workbook = XLSX.read(buffer, { type: 'array' });
                    var sheetName = workbook.SheetNames[0];
                    if (!sheetName) {
                        throw new Error('empty workbook');
                    }
                    var rows = XLSX.utils.sheet_to_json(workbook.Sheets[sheetName], { header: 1, blankrows: false });
                    var tableHtml = rows.map(function (row) {
                        var cells = (row || []).map(function (cell) {
                            return '<td>' + String(cell == null ? '' : cell)
                                .replace(/&/g, '&amp;')
                                .replace(/</g, '&lt;')
                                .replace(/>/g, '&gt;') + '</td>';
                        }).join('');
                        return '<tr>' + cells + '</tr>';
                    }).join('');
                    setHtml('<div class="viewer-box viewer-table-wrap"><table class="viewer-table">' + tableHtml + '</table></div>');
                    notifyParent('ready', 'Apercu Excel charge.');
                })
                .catch(function () {
                    renderUnavailable('Le classeur Excel ne peut pas etre rendu ici pour le moment.');
                });
            return;
        }

        if ((mime || '').indexOf('text/') === 0 || ['txt', 'csv', 'md', 'json', 'xml', 'log'].indexOf(ext) !== -1) {
            fetch(rawUrl, { credentials: 'include' })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }
                    return response.text();
                })
                .then(function (text) {
                    var safeText = text
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;');
                    setHtml('<div class="viewer-box"><pre>' + safeText + '</pre></div>');
                    notifyParent('ready', 'Apercu texte charge.');
                })
                .catch(function () {
                    renderUnavailable('Le fichier texte ne peut pas etre rendu ici pour le moment.');
                });
            return;
        }

        renderUnavailable("Ce format ne propose pas d'apercu integre. Utilisez le bouton de telechargement explicite si necessaire.");
    })();
    


