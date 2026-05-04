/**
 * EMSP Fixes - Global fixes for ProFreeHost
 * Load after all other scripts
 */
(function () {
    'use strict';

    function isVisible(el) {
        if (!el) return false;
        return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
    }

    function px(n) {
        var v = Math.round(Number(n) || 0);
        return (v > 0 ? v : 0) + 'px';
    }

    function findHostBanner() {
        var selectors = [
            '#adcenter', '#ads_banner', '#banner_ad', '#ads',
            '.profreehost-ad', '.host-ad', '.ad-top', '.ad-banner',
            'body > div:first-child > iframe',
            'body > iframe:first-child',
            'body > center:first-child',
            'body > table:first-child'
        ];

        var bestEl = null;
        var bestH = 0;

        selectors.forEach(function (s) {
            var el = null;
            try { el = document.querySelector(s); } catch (e) { el = null; }
            if (!el || !isVisible(el)) return;

            var h = 0;
            try { h = el.getBoundingClientRect().height || 0; } catch (e2) { h = 0; }
            h = Math.round(h);
            if (h > bestH) {
                bestH = h;
                bestEl = el;
            }
        });

        if (!bestEl || bestH <= 10) return null;

        var pos = '';
        var top = 9999;
        try {
            var st = window.getComputedStyle(bestEl);
            pos = st.position || '';
            top = bestEl.getBoundingClientRect().top;
        } catch (e3) {}

        var fixedLike = (pos === 'fixed' || pos === 'sticky') && top <= 1;
        return { el: bestEl, height: bestH, fixed: fixedLike };
    }

    function updateTopOffsets() {
        var root = document.documentElement;

        // Only compensate when the injected banner is FIXED on top (overlaying the UI).
        var banner = findHostBanner();
        var bannerH = (banner && banner.fixed) ? banner.height : 0;

        root.style.setProperty('--profreehost-banner-height', px(bannerH));
        root.classList.toggle('emsp-host-banner-fixed', bannerH > 10);

        // Update navbar height for anchor scroll + flash positioning.
        var nav = document.querySelector('.navbar-glass, #emsp-navbar, .emsp-navbar, nav.navbar');
        var navH = 0;
        if (nav && isVisible(nav)) {
            try { navH = Math.round(nav.getBoundingClientRect().height || 0); } catch (e) { navH = 0; }
        }
        if (navH > 0) {
            root.style.setProperty('--navbar-height', px(navH));
        } else {
            root.style.removeProperty('--navbar-height');
        }
    }

    function autoHideFlashMessages() {
        var messages = document.querySelectorAll(
            '[data-auto-hide], .auto-dismiss, .flash-message, .alert-floating'
        );
        messages.forEach(function (el) {
            var delay = parseInt(el.dataset.autoHide, 10) || 4000;
            setTimeout(function () {
                el.style.transition = 'opacity .4s ease, max-height .4s ease, margin .4s ease, padding .4s ease';
                el.style.opacity = '0';
                el.style.maxHeight = '0';
                el.style.overflow = 'hidden';
                el.style.margin = '0';
                el.style.padding = '0';
                setTimeout(function () {
                    if (el.parentNode) el.parentNode.removeChild(el);
                }, 420);
            }, delay);
        });
    }

    function initSidebarToggle() {
        var toggle = document.getElementById('sidebar-toggle');
        var sidebar = document.getElementById('emsp-sidebar');
        var overlay = document.getElementById('sidebar-overlay');
        if (!toggle || !sidebar) return;

        function closeSidebar() {
            sidebar.classList.remove('open');
            if (overlay) overlay.classList.remove('active');
            document.body.style.overflow = '';
        }
        function openSidebar() {
            sidebar.classList.add('open');
            if (overlay) overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            if (sidebar.classList.contains('open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });
        if (overlay) {
            overlay.addEventListener('click', closeSidebar);
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeSidebar();
        });
    }

    function initImageFallbacks() {
        // Handle data-fallback attribute
        document.querySelectorAll('img[data-fallback]').forEach(function (img) {
            img.addEventListener('error', function () {
                if (this.src !== this.dataset.fallback) {
                    this.src = this.dataset.fallback;
                }
            });
        });
        // Handle data-fallback-src attribute (used in template HTML)
        document.querySelectorAll('img[data-fallback-src]').forEach(function (img) {
            if (img.dataset.emspFallbackBound) return;
            img.dataset.emspFallbackBound = '1';
            img.addEventListener('error', function () {
                var fallbackSrc = this.dataset.fallbackSrc || '';
                if (fallbackSrc && this.src.indexOf(fallbackSrc) === -1) {
                    this.src = fallbackSrc;
                }
            });
        });
        // Universal broken image handler for all content images
        document.querySelectorAll('img:not([data-emsp-error-bound])').forEach(function (img) {
            img.dataset.emspErrorBound = '1';
            img.addEventListener('error', function () {
                // Skip if already handled by fallback attributes
                if (this.dataset.fallback || this.dataset.fallbackSrc) return;
                // For user avatars, show initials
                if (this.classList.contains('user-avatar')) {
                    this.style.display = 'none';
                    var parent = this.parentNode;
                    if (parent && !parent.querySelector('.avatar-initiales')) {
                        var av = document.createElement('div');
                        av.className = 'avatar-initiales';
                        av.textContent = ((this.dataset.initials || '?')[0] || '?').toUpperCase();
                        parent.appendChild(av);
                    }
                    return;
                }
                // For content images, add a broken-image visual indicator
                this.classList.add('emsp-img-broken');
                this.style.minHeight = '60px';
                this.style.backgroundColor = 'var(--bg-subtle, #f0f0f0)';
                this.style.objectFit = 'contain';
            });
        });
        // Re-trigger load for lazy images that might have been deferred by browser intervention
        document.querySelectorAll('img[loading="lazy"]').forEach(function (img) {
            if (img.complete && img.naturalWidth === 0 && img.src) {
                var originalSrc = img.src;
                img.removeAttribute('loading');
                img.src = '';
                img.src = originalSrc;
            }
        });
    }

    function parseCssLengthToPx(value, basePx) {
        if (!value) return NaN;
        var raw = String(value).trim().toLowerCase();
        var num = parseFloat(raw);
        if (!isFinite(num)) return NaN;
        if (raw.endsWith('px')) return num;
        if (raw.endsWith('rem') || raw.endsWith('em')) return num * basePx;
        if (raw.endsWith('%')) return (num / 100) * basePx;
        return num;
    }

    function enforceMinimumInlineFontSize(scope) {
        var root = scope && scope.querySelectorAll ? scope : document;
        var basePx = parseFloat(getComputedStyle(document.documentElement).fontSize || '16') || 16;
        root.querySelectorAll('[style*="font-size"]').forEach(function (el) {
            if (!el || !el.style) return;
            var pxSize = parseCssLengthToPx(el.style.fontSize, basePx);
            if (!isFinite(pxSize)) return;
            if (pxSize > 0 && pxSize < 13) {
                el.style.fontSize = '13px';
            }
        });
    }

    function clampPercent(value) {
        var numeric = parseFloat(String(value || '').replace(',', '.'));
        if (!isFinite(numeric)) return 0;
        return Math.max(0, Math.min(100, numeric));
    }

    function mojibakeScore(value) {
        var text = String(value || '');
        var score = 0;
        var markers = ['\u00C3', '\u00C2', '\u00E2', 'Ã', 'Â', 'â€', '�'];
        for (var i = 0; i < markers.length; i += 1) {
            var marker = markers[i];
            if (!marker) continue;
            var match = text.split(marker).length - 1;
            if (match > 0) score += match;
        }
        return score;
    }

    function decodeLatin1AsUtf8(raw) {
        if (!window.TextDecoder || !raw) {
            return String(raw || '');
        }
        try {
            var input = String(raw);
            var bytes = new Uint8Array(input.length);
            for (var i = 0; i < input.length; i += 1) {
                bytes[i] = input.charCodeAt(i) & 0xff;
            }
            return new TextDecoder('utf-8', { fatal: false }).decode(bytes);
        } catch (error) {
            return String(raw || '');
        }
    }

    function repairMojibakeString(value) {
        if (!value) return value;
        var raw = String(value);
        var candidates = [raw];

        if (raw.indexOf('\u00C3') !== -1 || raw.indexOf('\u00C2') !== -1 || raw.indexOf('\u00E2') !== -1 || raw.indexOf('Ã') !== -1 || raw.indexOf('Â') !== -1 || raw.indexOf('â€') !== -1) {
            try {
                candidates.push(decodeURIComponent(escape(raw)));
            } catch (error) {}
            candidates.push(decodeLatin1AsUtf8(raw));
        }

        var best = raw;
        var bestScore = mojibakeScore(raw);
        for (var i = 0; i < candidates.length; i += 1) {
            var current = String(candidates[i] || '');
            var currentScore = mojibakeScore(current);
            if (currentScore < bestScore || (currentScore === bestScore && current.length >= best.length)) {
                best = current;
                bestScore = currentScore;
            }
        }

        best = best
            .split('\u00E2\u20AC\u2122').join("'")
            .split('\u00E2\u20AC\u02DC').join("'")
            .split('\u00E2\u20AC\u0153').join('"')
            .split('\u00E2\u20AC\u009D').join('"')
            .split('\u00E2\u20AC\u201C').join('-')
            .split('\u00E2\u20AC\u201D').join('-')
            .split('\u00E2\u20AC\u00A6').join('...')
            .split('\u00C2\u00A0').join(' ')
            .split('â€™').join('’')
            .split('â€œ').join('“')
            .split('â€').join('”')
            .split('â€“').join('–')
            .split('â€”').join('—')
            .split('â€¦').join('…')
            .split('Â·').join('·');

        return best;
    }

    function repairMojibakeTextNodes(scope) {
        var root = scope || document.body || document;
        if (!root || !document.createTreeWalker) return;

        var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
        var node = walker.nextNode();
        while (node) {
            var original = node.nodeValue || '';
            if (original && (original.indexOf('\u00C3') !== -1 || original.indexOf('\u00C2') !== -1 || original.indexOf('\u00E2') !== -1)) {
                var repaired = repairMojibakeString(original);
                if (repaired !== original) {
                    node.nodeValue = repaired;
                }
            }
            node = walker.nextNode();
        }
    }

    function repairMojibakeAttributes(scope) {
        var root = scope && scope.querySelectorAll ? scope : document;
        var attrs = ['title', 'placeholder', 'aria-label', 'alt', 'value'];
        root.querySelectorAll('*').forEach(function (el) {
            if (!el || !el.getAttribute) return;
            attrs.forEach(function (attr) {
                if (!el.hasAttribute(attr)) return;
                var original = el.getAttribute(attr) || '';
                if (!original) return;
                if (original.indexOf('\u00C3') === -1 && original.indexOf('\u00C2') === -1 && original.indexOf('\u00E2') === -1 && original.indexOf('Ã') === -1 && original.indexOf('Â') === -1 && original.indexOf('â€') === -1) {
                    return;
                }
                var fixed = repairMojibakeString(original);
                if (fixed !== original) {
                    el.setAttribute(attr, fixed);
                }
            });
        });
    }

    function applyDataDrivenStyles(scope) {
        var root = scope && scope.querySelectorAll ? scope : document;

        root.querySelectorAll('[data-emsp-anim-delay]').forEach(function (el) {
            var raw = (el.getAttribute('data-emsp-anim-delay') || '').trim();
            var value = parseFloat(raw.replace(',', '.'));
            if (!isFinite(value)) {
                el.style.removeProperty('--emsp-anim-delay');
                return;
            }
            var normalized = Math.max(0, value);
            el.style.setProperty('--emsp-anim-delay', normalized + 's');
        });

        root.querySelectorAll('[data-emsp-width]').forEach(function (el) {
            var width = clampPercent(el.getAttribute('data-emsp-width'));
            el.style.setProperty('--emsp-width', width + '%');
        });

        root.querySelectorAll('[data-emsp-bg]').forEach(function (el) {
            var color = String(el.getAttribute('data-emsp-bg') || '').trim();
            if (!color) {
                el.style.removeProperty('--emsp-bg');
                return;
            }
            el.style.setProperty('--emsp-bg', color);
        });

        root.querySelectorAll('[data-emsp-border-style]').forEach(function (el) {
            var style = String(el.getAttribute('data-emsp-border-style') || '').trim().toLowerCase();
            if (['solid', 'dashed', 'dotted', 'double'].indexOf(style) === -1) {
                return;
            }
            el.style.borderTopStyle = style;
        });

        root.querySelectorAll('[data-emsp-border-color]').forEach(function (el) {
            var color = String(el.getAttribute('data-emsp-border-color') || '').trim();
            if (!color) return;
            el.style.borderTopColor = color;
        });

        root.querySelectorAll('[data-emsp-border-width]').forEach(function (el) {
            var width = String(el.getAttribute('data-emsp-border-width') || '').trim();
            if (!width) return;
            el.style.borderTopWidth = width;
        });

        root.querySelectorAll('[data-emsp-display]').forEach(function (el) {
            var display = String(el.getAttribute('data-emsp-display') || '').trim().toLowerCase();
            if (!display) return;
            if (['none', 'block', 'inline', 'inline-block', 'inline-flex', 'flex', 'grid'].indexOf(display) === -1) {
                return;
            }
            el.style.display = display;
        });
    }

    function stripInlineStyleProperties(el, properties) {
        if (!el || !el.getAttribute) return;
        var styleAttr = el.getAttribute('style');
        if (!styleAttr) return;

        var lowerProps = properties.map(function (p) { return String(p).toLowerCase(); });
        var rules = styleAttr.split(';').map(function (rule) { return rule.trim(); }).filter(Boolean);
        var kept = rules.filter(function (rule) {
            var normalized = rule.toLowerCase();
            return !lowerProps.some(function (prop) {
                return normalized.indexOf(prop + ':') === 0;
            });
        });

        if (kept.length) {
            el.setAttribute('style', kept.join('; '));
        } else {
            el.removeAttribute('style');
        }
    }

    function detectThumbType(thumb) {
        if (!thumb || !thumb.querySelector) return 'default';
        if (thumb.querySelector('.bi-file-earmark-pdf-fill')) return 'pdf';
        if (thumb.querySelector('.bi-file-word-fill')) return 'word';
        if (thumb.querySelector('.bi-file-excel-fill')) return 'excel';
        if (thumb.querySelector('.bi-file-ppt-fill')) return 'ppt';
        if (thumb.querySelector('.bi-file-zip-fill')) return 'zip';
        if (thumb.querySelector('.bi-file-text-fill')) return 'text';
        if (thumb.querySelector('.bi-file-earmark-fill')) return 'default';

        var extLabel = thumb.querySelector('.ext-label');
        var extText = extLabel ? String(extLabel.textContent || '').trim().toLowerCase() : '';
        if (extText.indexOf('pdf') !== -1) return 'pdf';
        if (extText.indexOf('doc') !== -1) return 'word';
        if (extText.indexOf('xls') !== -1) return 'excel';
        if (extText.indexOf('ppt') !== -1) return 'ppt';
        if (extText.indexOf('zip') !== -1) return 'zip';
        return 'default';
    }

    function harmonizeLegacyThumbIllustrations(scope) {
        var body = document.body;
        if (!body) return;
        var needsHarmonization =
            body.classList.contains('emsp-route-bibliotheque') ||
            body.classList.contains('emsp-route-concours') ||
            body.classList.contains('emsp-route-mes-favoris');
        if (!needsHarmonization) return;

        var root = scope && scope.querySelectorAll ? scope : document;
        root.querySelectorAll('.thumb-illus').forEach(function (thumb) {
            if (!thumb.classList.contains('emsp-thumb-illus')) {
                thumb.classList.add('emsp-thumb-illus');
            }

            var type = detectThumbType(thumb);
            ['pdf', 'word', 'excel', 'ppt', 'zip', 'text', 'default'].forEach(function (name) {
                thumb.classList.remove('emsp-thumb-type-' + name);
            });
            thumb.classList.add('emsp-thumb-type-' + type);

            stripInlineStyleProperties(thumb, ['background', 'background-color', 'color']);

            thumb.querySelectorAll('[style]').forEach(function (node) {
                stripInlineStyleProperties(node, ['background', 'background-color', 'color']);
            });

            thumb.querySelectorAll('span').forEach(function (span) {
                var text = String(span.textContent || '').trim().toUpperCase();
                if (text === 'SLIDE') {
                    span.classList.add('emsp-thumb-slide-label');
                    var parent = span.parentElement;
                    if (parent) {
                        parent.classList.add('emsp-thumb-slide-box');
                    }
                }
            });

            thumb.querySelectorAll('.doc-lines').forEach(function (lines) {
                lines.classList.add('emsp-thumb-lines');
            });

            thumb.querySelectorAll('div').forEach(function (block) {
                var miniFiles = block.querySelectorAll('.bi-file-earmark');
                if (miniFiles.length >= 2) {
                    block.classList.add('emsp-thumb-stack');
                    miniFiles.forEach(function (icon) {
                        icon.classList.add('emsp-thumb-stack-file');
                    });
                }
            });
        });
    }

    function ensureImageAltAttributes(scope) {
        var root = scope && scope.querySelectorAll ? scope : document;
        root.querySelectorAll('img:not([alt])').forEach(function (img) {
            // Accessibility baseline: never render an <img> without alt.
            img.setAttribute('alt', '');
        });
    }

    function initImageAltObserver() {
        ensureImageAltAttributes(document);
        if (!window.MutationObserver || !document.body) return;

        var observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (node && node.nodeType === Node.TEXT_NODE) {
                        var raw = node.nodeValue || '';
                        if (raw.indexOf('\u00C3') !== -1 || raw.indexOf('\u00C2') !== -1 || raw.indexOf('\u00E2') !== -1) {
                            node.nodeValue = repairMojibakeString(raw);
                        }
                        return;
                    }
                    if (!(node instanceof Element)) return;
                    if (node.tagName && node.tagName.toLowerCase() === 'img' && !node.hasAttribute('alt')) {
                        node.setAttribute('alt', '');
                    } else if (node.querySelectorAll) {
                        ensureImageAltAttributes(node);
                    }
                    repairMojibakeTextNodes(node);
                    repairMojibakeAttributes(node);
                    harmonizeLegacyThumbIllustrations(node);
                    enforceMinimumInlineFontSize(node);
                    applyDataDrivenStyles(node);
                });
            });
        });

        observer.observe(document.body, { childList: true, subtree: true });
    }

    function wrapTablesResponsive() {
        document.querySelectorAll('table').forEach(function (table) {
            var parent = table.parentNode;
            if (parent && parent.classList &&
                (parent.classList.contains('table-responsive') ||
                 parent.classList.contains('table-responsive-auto'))) {
                return;
            }
            var wrapper = document.createElement('div');
            wrapper.className = 'table-responsive-auto';
            wrapper.style.overflowX = 'auto';
            wrapper.style.webkitOverflowScrolling = 'touch';
            parent.insertBefore(wrapper, table);
            wrapper.appendChild(table);
        });
    }

    function fixModalsPosition() {
        var bannerH = parseInt(
            getComputedStyle(document.documentElement)
                .getPropertyValue('--profreehost-banner-height'),
            10
        ) || 0;
        if (bannerH <= 0) return;

        document.querySelectorAll('.modal').forEach(function (modal) {
            modal.addEventListener('show.bs.modal', function () {
                var dialog = this.querySelector('.modal-dialog');
                if (dialog) {
                    var currentMargin = parseInt(
                        window.getComputedStyle(dialog).marginTop, 10
                    ) || 28;
                    dialog.style.marginTop = Math.max(currentMargin, bannerH + 10) + 'px';
                }
            });
        });
    }

    function fixAnchorScroll() {
        document.querySelectorAll('a[href^=\"#\"]').forEach(function (link) {
            var href = link.getAttribute('href') || '';
            if (href === '#' || href === '#!' || href.length < 2) return;
            if (link.hasAttribute('data-bs-toggle') || link.hasAttribute('data-bs-target')) return;

            link.addEventListener('click', function (e) {
                var h = this.getAttribute('href') || '';
                if (h === '#' || h === '#!' || h.length < 2) return;

                var target = null;
                try { target = document.querySelector(h); } catch (err) { target = null; }
                if (!target) return;

                e.preventDefault();
                var offset = parseInt(
                    getComputedStyle(document.documentElement)
                        .getPropertyValue('--total-top-offset'), 10
                ) || 70;
                var top = target.getBoundingClientRect().top + window.pageYOffset - offset - 10;
                window.scrollTo({ top: top, behavior: 'smooth' });
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        updateTopOffsets();
        autoHideFlashMessages();
        initSidebarToggle();
        initImageFallbacks();
        repairMojibakeTextNodes(document.body || document);
        repairMojibakeAttributes(document.body || document);
        initImageAltObserver();
        harmonizeLegacyThumbIllustrations(document);
        enforceMinimumInlineFontSize(document);
        applyDataDrivenStyles(document);
        wrapTablesResponsive();
        fixModalsPosition();
        fixAnchorScroll();
    });

    window.addEventListener('load', function () {
        updateTopOffsets();
    });

    // Re-check after a short delay (some hosts inject banners late).
    setTimeout(updateTopOffsets, 700);

    // Keep offsets correct on resize/orientation changes.
    var resizeTimer = null;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(updateTopOffsets, 150);
    });
})();


