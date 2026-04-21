(function (window, document) {
    'use strict';

    var html = document.documentElement;

    function hasHarvardTheme() {
        var body = document.body;
        return html.classList.contains('theme-harvard')
            || (body && body.classList && body.classList.contains('theme-harvard'));
    }

    function prefersReducedMotion() {
        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    function forEachNode(nodes, callback) {
        Array.prototype.forEach.call(nodes || [], callback);
    }

    function isHidden(el) {
        if (!el) {
            return true;
        }
        if (el.closest('.modal, .offcanvas, .dropdown-menu, .toast, .swal2-container')) {
            return true;
        }
        if (el.getAttribute('aria-hidden') === 'true') {
            return true;
        }
        var style = window.getComputedStyle(el);
        return style.display === 'none' || style.visibility === 'hidden';
    }

    function assignLineAnimations(scope) {
        var selector = [
            '.site-main a:not(.btn):not(.dropdown-item):not(.nav-link):not(.emsp-mobile-bottom-nav__item)',
            '#main-content a:not(.btn):not(.dropdown-item):not(.nav-link)',
            '.home-doc-link',
            '.journal-card-link',
            '.formations-tronc-link',
            '.admin-panel-link'
        ].join(',');
        var links = (scope || document).querySelectorAll(selector);

        forEachNode(links, function (link) {
            if (link.classList.contains('a-line-animation') || link.classList.contains('a-line-animation-active')) {
                return;
            }
            link.classList.add('a-line-animation');
        });
    }

    function collectRevealTargets(scope) {
        var selector = [
            '.site-main .home-step-card',
            '.site-main .home-quick-card',
            '.site-main .home-doc-card',
            '.site-main .home-cta-panel',
            '.site-main .doc-card',
            '.site-main .journal-card',
            '.site-main .formation-card',
            '.site-main .institution-panel',
            '.site-main .library-filter-card',
            '.site-main .library-empty-card',
            '.site-main .concours-empty-card',
            '.site-main .institution-stat',
            '.site-main .institution-quote',
            '.site-main .emsp-login-panel',
            '.site-main .emsp-register-card',
            '.site-main .emsp-status-card',
            '.site-main .card',
            '#main-content .admin-kpi-card',
            '#main-content .admin-panel',
            '#main-content .admin-stats-card',
            '#main-content .admin-stats-panel',
            '#main-content .admin-list-row',
            '#main-content .admin-stats-list-row',
            '#main-content .admin-stats-type-row',
            '#main-content .admin-stats-badge-row',
            '#main-content .admin-stats-month-row',
            '#main-content .card',
            '#main-content .dark-card',
            '#main-content .sb-card',
            '#main-content .stat-card',
            '#main-content .emsp-admin-mobile-card'
        ].join(',');

        return (scope || document).querySelectorAll(selector);
    }

    function markVisible(el) {
        el.classList.add('an-on-viewport');
        el.classList.add('an-was-on-viewport');
        el.classList.add('an-on-viewport-mid');
    }

    function registerTargets(scope, observer, reduced) {
        var targets = collectRevealTargets(scope);
        forEachNode(targets, function (el) {
            if (!el || el.dataset.hvMotionBound === '1' || isHidden(el)) {
                return;
            }

            el.dataset.hvMotionBound = '1';

            if (reduced) {
                el.classList.remove('an-will-animate');
                markVisible(el);
                return;
            }

            el.classList.add('an-will-animate');

            if (observer) {
                observer.observe(el);
            } else {
                markVisible(el);
            }
        });
    }

    function initViewportAnimations() {
        var reduced = prefersReducedMotion();
        var observer = null;

        if (!reduced && 'IntersectionObserver' in window) {
            observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    var el = entry.target;

                    if (entry.intersectionRatio > 0) {
                        el.classList.add('an-on-viewport');
                        el.classList.add('an-was-on-viewport');
                        if (entry.intersectionRatio === 1) {
                            el.classList.add('an-on-viewport-full');
                        }
                        if (entry.intersectionRatio >= 0.3) {
                            el.classList.add('an-on-viewport-mid');
                        }
                    } else {
                        el.classList.remove('an-on-viewport');
                        el.classList.remove('an-on-viewport-full');
                        el.classList.remove('an-on-viewport-mid');
                    }
                });
            }, {
                threshold: [0, 0.3, 1],
                rootMargin: '30px'
            });
        }

        registerTargets(document, observer, reduced);
        assignLineAnimations(document);

        if (!('MutationObserver' in window)) {
            return;
        }

        var scheduled = false;
        var mutationObserver = new MutationObserver(function () {
            if (scheduled) {
                return;
            }
            scheduled = true;
            window.requestAnimationFrame(function () {
                scheduled = false;
                registerTargets(document, observer, reduced);
                assignLineAnimations(document);
            });
        });

        mutationObserver.observe(document.body, {
            childList: true,
            subtree: true
        });
    }

    function init() {
        if (!hasHarvardTheme()) {
            return;
        }
        initViewportAnimations();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window, document);



