(function (window, document) {
    'use strict';

    function prefersReducedMotion() {
        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    function onReady(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
            return;
        }
        callback();
    }

    function markTopbarOnScroll() {
        var topbar = document.querySelector('.navbar-glass');
        if (!topbar) return;
        var update = function () {
            topbar.classList.toggle('is-scrolled', window.scrollY > 16);
        };
        update();
        window.addEventListener('scroll', update, { passive: true });
    }

    function enhanceTables() {
        document.querySelectorAll('#main-content table').forEach(function (table) {
            if (!table.parentElement || table.parentElement.classList.contains('table-responsive')) {
                return;
            }
            var wrapper = document.createElement('div');
            wrapper.className = 'table-responsive';
            table.parentElement.insertBefore(wrapper, table);
            wrapper.appendChild(table);
        });
    }

    function enhancePagination() {
        document.querySelectorAll('.pagination .page-link').forEach(function (link) {
            var txt = (link.textContent || '').trim();
            if (!link.hasAttribute('aria-label') && txt !== '') {
                link.setAttribute('aria-label', 'Aller a la page ' + txt);
            }
        });
    }

    function revealCards() {
        var nodes = document.querySelectorAll(
            '#main-content .card, #main-content .dark-card, #main-content .sb-card, #main-content .stat-card, #main-content .emsp-admin-mobile-card'
        );
        if (!nodes.length) return;

        if (prefersReducedMotion() || !('IntersectionObserver' in window)) {
            nodes.forEach(function (node) {
                node.classList.add('admin-reveal', 'is-visible');
            });
            return;
        }

        nodes.forEach(function (node) {
            node.classList.add('admin-reveal');
        });

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('is-visible');
                observer.unobserve(entry.target);
            });
        }, { threshold: 0.12, rootMargin: '0px 0px -10% 0px' });

        nodes.forEach(function (node) {
            observer.observe(node);
        });
    }

    function enhanceSidebarNavigation() {
        var sidebar = document.getElementById('miniSidebar');
        if (!sidebar) return;

        var groups = Array.prototype.slice.call(sidebar.querySelectorAll('[data-admin-nav-group]'));
        var toggles = Array.prototype.slice.call(sidebar.querySelectorAll('[data-admin-nav-toggle]'));
        var searchInput = document.getElementById('admin-nav-search');

        function setGroupExpanded(group, expanded) {
            if (!group) return;
            group.classList.toggle('is-open', !!expanded);
            var toggle = group.querySelector('[data-admin-nav-toggle]');
            if (toggle) {
                toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            }
        }

        toggles.forEach(function (toggle) {
            toggle.addEventListener('click', function () {
                var group = toggle.closest('[data-admin-nav-group]');
                if (!group) return;
                var willOpen = !group.classList.contains('is-open');
                setGroupExpanded(group, willOpen);
            });
        });

        if (!searchInput) {
            return;
        }

        function applySearch(query) {
            var normalized = (query || '').trim().toLowerCase();

            groups.forEach(function (group) {
                var items = Array.prototype.slice.call(group.querySelectorAll('[data-admin-nav-item]'));
                var visibleCount = 0;

                items.forEach(function (item) {
                    var label = (item.getAttribute('data-admin-nav-label') || item.textContent || '').toLowerCase();
                    var match = normalized === '' || label.indexOf(normalized) !== -1;
                    item.style.display = match ? '' : 'none';
                    if (match) visibleCount += 1;
                });

                var hasVisibleItems = visibleCount > 0;
                group.style.display = hasVisibleItems ? '' : 'none';

                if (normalized !== '') {
                    setGroupExpanded(group, hasVisibleItems);
                }
            });
        }

        searchInput.addEventListener('input', function () {
            applySearch(searchInput.value);
        });

        searchInput.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                searchInput.value = '';
                applySearch('');
            }
        });
    }

    onReady(function () {
        if (!document.documentElement.classList.contains('theme-harvard')) return;
        markTopbarOnScroll();
        enhanceTables();
        enhancePagination();
        revealCards();
        enhanceSidebarNavigation();
    });
})(window, document);


