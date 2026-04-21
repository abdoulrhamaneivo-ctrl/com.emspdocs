    <?php $asset = $asset ?? '../assets/'; ?>
    <script src="<?= $asset ?>js/jquery.min.js"></script>
    <script src="<?= $asset ?>js/bootstrap5.bundle.min.js"></script>
    <script src="<?= $asset ?>vendor/sweetalert2/sweetalert2.all.min.js"></script>
    <script src="<?= $asset ?>vendor/select2/select2.full.min.js"></script>
    <script src="<?= $asset ?>js/emsp-ui.js"></script>
    <script src="<?= $asset ?>js/emsp-harvard-motion.js"></script>
    <script src="<?= $asset ?>js/emsp-admin-experience-upgrade.js"></script>
    <script>
    (function () {
        var html = document.documentElement;
        html.classList.remove('expanded', 'collapsed');
        html.classList.add('expanded');

        var collapseBtn = document.getElementById('sidebar-collapse-toggle');
        if (collapseBtn) {
            collapseBtn.addEventListener('click', function (e) {
                e.preventDefault();
                var next = html.classList.contains('expanded') ? 'collapsed' : 'expanded';
                html.classList.remove('expanded', 'collapsed');
                html.classList.add(next);
            });
        }

        var mobileBtn = document.getElementById('sidebar-toggle');
        var overlay = document.getElementById('sidebar-overlay');
        var sidebar = document.getElementById('miniSidebar');

        function setSidebarState(open) {
            document.body.classList.toggle('sidebar-open', !!open);
            if (overlay) {
                overlay.classList.toggle('active', !!open);
            }
            if (mobileBtn) {
                mobileBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            }
            if (sidebar && window.innerWidth < 992) {
                sidebar.style.display = 'block';
                sidebar.style.position = 'fixed';
                sidebar.style.left = '0';
                sidebar.style.top = '0';
                sidebar.style.bottom = '0';
                sidebar.style.width = '85vw';
                sidebar.style.maxWidth = '85vw';
                sidebar.style.zIndex = '1045';
                sidebar.style.transform = open ? 'translateX(0)' : 'translateX(-105%)';
            } else if (sidebar) {
                sidebar.style.removeProperty('display');
                sidebar.style.removeProperty('position');
                sidebar.style.removeProperty('left');
                sidebar.style.removeProperty('top');
                sidebar.style.removeProperty('bottom');
                sidebar.style.removeProperty('width');
                sidebar.style.removeProperty('max-width');
                sidebar.style.removeProperty('z-index');
                sidebar.style.removeProperty('transform');
            }
        }

        if (mobileBtn) {
            mobileBtn.addEventListener('click', function () {
                setSidebarState(!document.body.classList.contains('sidebar-open'));
            });
        }

        if (overlay) {
            overlay.addEventListener('click', function () {
                setSidebarState(false);
            });
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && document.body.classList.contains('sidebar-open')) {
                setSidebarState(false);
            }
        });

        document.addEventListener('click', function (e) {
            var btn = document.getElementById('sidebar-toggle');
            if (window.innerWidth < 992 && document.body.classList.contains('sidebar-open')) {
                if (sidebar && !sidebar.contains(e.target) && btn && !btn.contains(e.target)) {
                    setSidebarState(false);
                }
            }
        });

        if (sidebar) {
            sidebar.querySelectorAll('a[href]').forEach(function (link) {
                link.addEventListener('click', function () {
                    if (window.innerWidth < 992) {
                        setSidebarState(false);
                    }
                });
            });
        }

        window.addEventListener('resize', function () {
            if (window.innerWidth >= 992) {
                setSidebarState(false);
            }
        });
    })();

    // Auto-hide flash messages
    setTimeout(function () {
        document.querySelectorAll('.alert, .sb-notice').forEach(function (el) {
            el.style.transition = 'opacity .3s ease';
            el.style.opacity = '0';
            setTimeout(function () { el.remove(); }, 350);
        });
    }, 3200);
    </script>


