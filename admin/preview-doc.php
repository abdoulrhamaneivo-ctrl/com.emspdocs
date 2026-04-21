<?php
ob_start();
include_once __DIR__ . '/bootstrap.php';
include_once __DIR__ . '/config/dbcon.php';
include_once __DIR__ . '/../includes/csrf.php';
include_once __DIR__ . '/../includes/document-taxonomy.php';

if (empty($_SESSION['auth']) || !in_array($_SESSION['auth_role'] ?? '', ['admin', 'moderateur'], true)) {
    header('Location: ../index.php?open_login=1');
    exit(0);
}

$doc_id = (int) ($_GET['id'] ?? 0);
if ($doc_id <= 0) {
    http_response_code(404);
    echo 'Document introuvable.';
    exit(0);
}

$stmt = mysqli_prepare(
    $con,
    "SELECT d.id, d.title, d.description, d.doc_type, d.semester, d.file_path, d.mime_type,
            d.status, d.is_public, d.created_at, d.exam_session, d.exam_section, d.exam_year,
            d.matiere_id, " . (emsp_pending_matiere_enabled($con) ? "d.matiere_label_pending," : "NULL AS matiere_label_pending,") . "
            u.first_name, u.last_name,
            l.name AS licence_name,
            ma.name AS matiere_name
     FROM documents d
     JOIN users u ON u.id = d.uploader_id
     LEFT JOIN licences l ON l.id = d.licence_id
     LEFT JOIN matieres ma ON ma.id = d.matiere_id
     WHERE d.id = ?
     LIMIT 1"
);
if (!$stmt) {
    http_response_code(500);
    echo 'Preparation impossible.';
    exit(0);
}
mysqli_stmt_bind_param($stmt, 'i', $doc_id);
mysqli_stmt_execute($stmt);
$doc = emsp_stmt_fetch_assoc($stmt);
mysqli_stmt_close($stmt);

if (!$doc) {
    http_response_code(404);
    echo 'Document introuvable.';
    exit(0);
}

foreach ([
    'title', 'description', 'first_name', 'last_name', 'licence_name',
    'matiere_name', 'matiere_label_pending', 'exam_session', 'exam_section'
] as $field) {
    if (isset($doc[$field]) && is_string($doc[$field])) {
        $doc[$field] = emsp_fix_mojibake($doc[$field]);
    }
}

$filiereLabels = emsp_fetch_document_filiere_labels($con, [$doc_id]);
$filiereNames = $filiereLabels[$doc_id] ?? [];

$mime = strtolower((string) ($doc['mime_type'] ?? ''));
$ext = strtolower(pathinfo((string) ($doc['file_path'] ?? ''), PATHINFO_EXTENSION));
$isPdf = ($mime === 'application/pdf' || $ext === 'pdf');
$isImage = (str_starts_with($mime, 'image/') || in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true));
$isDocx = (strpos($mime, 'wordprocessingml') !== false || $ext === 'docx');
$isXlsx = (strpos($mime, 'spreadsheetml') !== false || $ext === 'xlsx');
$isText = str_starts_with($mime, 'text/')
    || in_array($ext, ['txt', 'csv', 'md', 'json', 'xml', 'log'], true)
    || in_array($mime, ['application/json', 'application/xml'], true);

$previewUrl = '../telecharger.php?id=' . $doc_id . '&preview=1';
$rawUrl = '../telecharger.php?id=' . $doc_id . '&raw=1';
$downloadAction = '../telecharger.php?id=' . $doc_id . '&download=1';
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($doc['title'] ?? 'Apercu document') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Lato:wght@400;600;700;900&family=Playfair+Display:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --color-primary: #006B3C;
            --color-primary-light: #E8F5EE;
            --color-accent: #F5A800;
            --color-accent-dark: #D4900A;
            --color-bg-alt: #F4F4F4;
            --color-text: #1A1A1A;
            --color-text-secondary: #6B6B6B;
            --color-border: #E0E0E0;
            --font-serif: 'Playfair Display', Georgia, serif;
            --font-sans: 'Lato', 'Helvetica Neue', sans-serif;
            --font-mono: 'JetBrains Mono', monospace;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: var(--font-sans);
            background: var(--color-bg-alt);
            color: var(--color-text);
        }
        .preview-shell {
            padding: 1rem;
            display: grid;
            gap: 1rem;
        }
        .preview-meta,
        .preview-viewer {
            background: #fff;
            border: 1px solid var(--color-border);
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
        }
        .preview-meta {
            padding: 1rem 1.1rem;
            display: grid;
            gap: .85rem;
        }
        .preview-title {
            font-size: 1.05rem;
            font-weight: 800;
            font-family: var(--font-serif);
            color: var(--color-text);
            margin: 0;
        }
        .preview-badges {
            display: flex;
            flex-wrap: wrap;
            gap: .45rem;
        }
        .preview-badge {
            display: inline-flex;
            align-items: center;
            padding: .28rem .7rem;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 700;
            background: var(--color-primary-light);
            color: var(--color-primary);
            border: 1px solid rgba(0, 107, 60, .24);
            text-transform: uppercase;
            letter-spacing: .5px;
        }
        .preview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: .75rem;
        }
        .emsp-grid-full {
            grid-column: 1 / -1;
        }
        .preview-field {
            border: 1px solid var(--color-border);
            border-radius: 8px;
            padding: .7rem .8rem;
            background: #f9f9f9;
        }
        .preview-field small {
            display: block;
            color: var(--color-text-secondary);
            margin-bottom: .25rem;
            font-size: 13px;
        }
        .preview-viewer {
            overflow: hidden;
        }
        .preview-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: .7rem;
            padding: .8rem 1rem;
            border-bottom: 1px solid var(--color-border);
            background: #fff;
        }
        .preview-toolbar h2 {
            margin: 0;
            font-size: 15px;
            font-weight: 800;
            color: var(--color-primary);
        }
        .preview-download {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            padding: .55rem .9rem;
            border-radius: 4px;
            border: 1px solid var(--color-accent);
            background: var(--color-accent);
            color: var(--color-text);
            font-weight: 700;
            cursor: pointer;
        }
        .preview-download:hover {
            background: var(--color-accent-dark);
            border-color: var(--color-accent-dark);
        }
        .viewer-content {
            min-height: 460px;
            background: #f9faf9;
        }
        .viewer-frame {
            width: 100%;
            min-height: 70vh;
            border: 0;
            display: block;
            background: #fff;
        }
        .viewer-image {
            display: block;
            max-width: 100%;
            max-height: 72vh;
            margin: 0 auto;
            border-radius: 14px;
            box-shadow: 0 14px 24px rgba(15, 23, 42, .08);
        }
        .viewer-box {
            padding: 1.25rem;
            overflow: auto;
        }
        /* Maintenance: converted DOCX/XLSX markup can contain wide tables, long URLs or pasted inline widths.
           Keep every rich preview inside the moderation panel instead of letting it spill out of the viewport. */
        .viewer-box,
        .viewer-box * {
            max-width: 100%;
            box-sizing: border-box;
        }
        .viewer-box p,
        .viewer-box li,
        .viewer-box td,
        .viewer-box th,
        .viewer-box span,
        .viewer-box div,
        .viewer-box a {
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        .viewer-box img,
        .viewer-box svg,
        .viewer-box canvas,
        .viewer-box iframe,
        .viewer-box video {
            max-width: 100%;
            height: auto;
        }
        .viewer-box table {
            display: block;
            width: max-content;
            min-width: 100%;
            border-collapse: collapse;
            overflow-x: auto;
        }
        .viewer-box pre {
            margin: 0;
            white-space: pre-wrap;
            word-break: break-word;
            font: 14px/1.6 var(--font-mono);
            color: var(--color-text-secondary);
        }
        .viewer-table-wrap {
            max-height: 68vh;
            overflow: auto;
        }
        .viewer-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .9rem;
        }
        .viewer-table td {
            border: 1px solid var(--color-border);
            padding: .45rem .55rem;
            vertical-align: top;
        }
        .viewer-empty {
            padding: 2.2rem 1.25rem;
            text-align: center;
            color: var(--color-text-secondary);
        }
        .viewer-empty strong {
            display: block;
            color: var(--color-text);
            margin-bottom: .4rem;
        }
        @media (max-width: 991.98px) {
            .preview-shell {
                padding: .85rem;
            }
            .preview-grid {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            }
            .viewer-content {
                min-height: 360px;
            }
            .viewer-frame {
                min-height: 58vh;
            }
            .viewer-table-wrap {
                max-height: 58vh;
            }
        }
        @media (max-width: 767.98px) {
            .preview-shell {
                padding: .75rem;
                gap: .85rem;
            }
            .preview-meta {
                padding: .9rem;
            }
            .preview-title {
                font-size: 1rem;
                line-height: 1.35;
            }
            .preview-grid {
                grid-template-columns: 1fr;
            }
            .preview-toolbar {
                align-items: stretch;
                padding: .85rem .9rem;
            }
            .preview-toolbar h2 {
                font-size: .88rem;
            }
            .preview-download {
                justify-content: center;
                width: 100%;
            }
            .viewer-content {
                min-height: 300px;
            }
            .viewer-frame {
                min-height: 52vh;
            }
            .viewer-image {
                max-height: 54vh;
            }
            .viewer-box {
                padding: .9rem;
            }
            .viewer-table {
                font-size: .84rem;
            }
            .viewer-empty {
                padding: 1.6rem 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="preview-shell">
        <section class="preview-meta">
            <h1 class="preview-title"><?= h($doc['title']) ?></h1>
            <div class="preview-badges">
                <span class="preview-badge"><?= h(ucfirst((string) ($doc['doc_type'] ?? 'document'))) ?></span>
                <span class="preview-badge"><?= (int) ($doc['is_public'] ?? 0) === 1 ? 'Public' : 'Prive' ?></span>
                <span class="preview-badge"><?= h((string) ($doc['status'] ?? 'pending')) ?></span>
            </div>
            <div class="preview-grid">
                <div class="preview-field">
                    <small>Auteur</small>
                    <strong><?= h(trim(($doc['first_name'] ?? '') . ' ' . ($doc['last_name'] ?? ''))) ?></strong>
                </div>
                <div class="preview-field">
                    <small>Filieres</small>
                    <strong><?= h(!empty($filiereNames) ? implode(', ', $filiereNames) : 'Non renseignee') ?></strong>
                </div>
                <div class="preview-field">
                    <small>Licence</small>
                    <strong><?= h((string) ($doc['licence_name'] ?? 'Non renseignee')) ?></strong>
                </div>
                <div class="preview-field">
                    <small>Matiere</small>
                    <strong><?= h((string) (($doc['matiere_name'] ?: $doc['matiere_label_pending']) ?: 'A valider')) ?></strong>
                </div>
                <?php if (!empty($doc['semester'])): ?>
                    <div class="preview-field">
                        <small>Semestre</small>
                        <strong><?= h($doc['semester']) ?></strong>
                    </div>
                <?php endif; ?>
                <?php if (!empty($doc['description'])): ?>
                    <div class="preview-field emsp-grid-full">
                        <small>Description</small>
                        <strong><?= nl2br(h($doc['description'])) ?></strong>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="preview-viewer">
            <div class="preview-toolbar">
                <h2>Apercu admin avant validation</h2>
                <form method="post" action="<?= h($downloadAction) ?>" target="previewDownloadSink">
                    <?php csrf_input(); ?>
                    <button type="submit" class="preview-download">Telecharger explicitement</button>
                </form>
            </div>
            <div class="viewer-content" id="previewContent">
                <div class="viewer-empty">
                    <strong>Chargement de l'apercu...</strong>
                    Merci de patienter un instant.
                </div>
            </div>
        </section>
        <iframe name="previewDownloadSink" class="d-none" title="Telechargement document" aria-hidden="true"></iframe>
    </div>

    <script>
    (function () {
        var previewContent = document.getElementById('previewContent');
        var previewUrl = <?= json_encode($previewUrl) ?>;
        var rawUrl = <?= json_encode($rawUrl) ?>;
        var mime = <?= json_encode($mime) ?>;
        var ext = <?= json_encode($ext) ?>;
        var isMobilePreview = !!(window.matchMedia && window.matchMedia('(max-width: 767.98px)').matches);

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
        // prefer the browser native PDF preview for sharper rendering, especially on phones.
        if (mime === 'application/pdf' || ext === 'pdf') {
            if (isMobilePreview) {
                var mobilePdfUrl = new URL(previewUrl, window.location.href).toString();
                var mobileViewerUrl = new URL('../assets/js/pdfjs/web/viewer.html?file=' + encodeURIComponent(mobilePdfUrl), window.location.href).toString();
                setHtml(
                    '<iframe class="viewer-frame" src="' + mobileViewerUrl + '" title="Apercu PDF mobile"></iframe>'
                );
                notifyParent('ready', 'Apercu PDF mobile charge.');
                return;
            }
            setHtml('<iframe class="viewer-frame" src="' + previewUrl + '#toolbar=1&navpanes=0&scrollbar=1&view=FitH" title="Apercu PDF"></iframe>');
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
    </script>
</body>
</html>



