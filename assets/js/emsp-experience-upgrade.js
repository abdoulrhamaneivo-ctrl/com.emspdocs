(function (window, document) {
    'use strict';

    function isThemeHarvard() {
        var html = document.documentElement;
        var body = document.body;
        return !!(html && html.classList.contains('theme-harvard')) || !!(body && body.classList.contains('theme-harvard'));
    }

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

    function initNavbarScrollState() {
        var header = document.querySelector('.site-header');
        if (!header) return;

        var update = function () {
            header.classList.toggle('is-scrolled', window.scrollY > 20);
        };

        update();
        window.addEventListener('scroll', update, { passive: true });
    }

    function initRevealAnimations() {
        var reduce = prefersReducedMotion();
        var nodes = document.querySelectorAll(
            '.site-main section, .site-main .card, .site-main .home-step-card, .site-main .home-doc-card, .site-main .album-card, .site-main .playlist-item, .site-main .emsp-login-panel, .site-main .emsp-register-card'
        );

        if (!nodes.length) return;

        var targets = Array.prototype.filter.call(nodes, function (el) {
            if (!el || !el.classList) return false;
            if (el.classList.contains('home-hero-shell')) return false;
            if (el.closest('.offcanvas, .dropdown-menu, .modal')) return false;
            return true;
        });

        if (!targets.length) return;

        if (reduce || !('IntersectionObserver' in window)) {
            targets.forEach(function (el) {
                el.classList.add('emsp-reveal', 'is-visible');
            });
            return;
        }

        targets.forEach(function (el) {
            el.classList.add('emsp-reveal');
        });

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                var el = entry.target;
                if (el.classList.contains('is-visible')) {
                    observer.unobserve(el);
                    return;
                }
                var siblings = el.parentElement ? Array.prototype.filter.call(el.parentElement.children, function (n) {
                    return n.classList && n.classList.contains('emsp-reveal');
                }) : [];
                var index = siblings.indexOf(el);
                var delay = Math.max(0, index) * 70;
                window.setTimeout(function () {
                    el.classList.add('is-visible');
                }, delay);
                observer.unobserve(el);
            });
        }, {
            rootMargin: '0px 0px -12% 0px',
            threshold: 0.12
        });

        targets.forEach(function (el) {
            observer.observe(el);
        });
    }

    function enhancePagination() {
        var route = document.body ? (document.body.getAttribute('data-route') || '') : '';
        if (route === 'mediatheque.php') {
            return;
        }
        document.querySelectorAll('.pagination').forEach(function (pagination) {
            if (!pagination) return;
            pagination.classList.add('emsp-pagination-enhanced');
            pagination.querySelectorAll('.page-link').forEach(function (link) {
                if (!link.hasAttribute('aria-label')) {
                    link.setAttribute('aria-label', 'Aller a la page ' + (link.textContent || '').trim());
                }
                link.addEventListener('click', function () {
                    if (prefersReducedMotion()) return;
                    document.body.classList.add('emsp-page-loading');
                    window.setTimeout(function () {
                        document.body.classList.remove('emsp-page-loading');
                    }, 420);
                });
            });
        });
    }

    function perViewFromWidth(width, container) {
        if (container && container.dataset && container.dataset.emspPerViewMobile && width < 576) {
            return Math.max(1, parseInt(container.dataset.emspPerViewMobile, 10) || 1);
        }
        if (container && container.dataset && container.dataset.emspPerViewTablet && width < 992) {
            return Math.max(1, parseInt(container.dataset.emspPerViewTablet, 10) || 2);
        }
        if (container && container.dataset && container.dataset.emspPerViewDesktop) {
            return Math.max(1, parseInt(container.dataset.emspPerViewDesktop, 10) || 3);
        }
        if (width < 576) return 1;
        if (width < 992) return 2;
        return 3;
    }

    function createCarousel(container) {
        if (!container || container.dataset.emspCarouselReady === '1') return;

        var items = Array.prototype.filter.call(container.children, function (child) {
            return child && child.nodeType === 1 && child.tagName !== 'SCRIPT' && child.tagName !== 'STYLE';
        });

        var minItems = parseInt((container.dataset && container.dataset.emspCarouselMinItems) ? container.dataset.emspCarouselMinItems : '3', 10);
        if (!Number.isFinite(minItems) || minItems < 1) {
            minItems = 3;
        }
        if (items.length < minItems) return;

        container.dataset.emspCarouselReady = '1';

        var wrapper = document.createElement('div');
        wrapper.className = 'emsp-carousel';
        wrapper.setAttribute('role', 'region');
        var carouselTitle = (container.dataset && container.dataset.emspCarouselTitle) ? container.dataset.emspCarouselTitle : 'Carrousel de contenus';
        wrapper.setAttribute('aria-label', carouselTitle);

        var carouselStyle = (container.dataset && container.dataset.emspCarouselStyle)
            ? String(container.dataset.emspCarouselStyle).trim().toLowerCase()
            : '';
        if (carouselStyle) {
            var cleanStyle = carouselStyle.replace(/[^a-z0-9_-]/g, '');
            if (cleanStyle) {
                wrapper.classList.add('emsp-carousel-' + cleanStyle);
            }
        }
        var showControls = !(container.dataset && container.dataset.emspCarouselControls === '0');
        var showDots = !(container.dataset && container.dataset.emspCarouselDots === '0');

        var viewport = document.createElement('div');
        viewport.className = 'emsp-carousel-viewport';

        var track = document.createElement('div');
        track.className = 'emsp-carousel-track';

        items.forEach(function (item) {
            item.classList.add('emsp-carousel-slide');
            track.appendChild(item);
        });

        viewport.appendChild(track);
        wrapper.appendChild(viewport);

        var controls = null;
        var prevButton = null;
        var nextButton = null;
        if (showControls) {
            controls = document.createElement('div');
            controls.className = 'emsp-carousel-controls';

            prevButton = document.createElement('button');
            prevButton.type = 'button';
            prevButton.className = 'emsp-carousel-btn emsp-carousel-btn-prev';
            prevButton.setAttribute('aria-label', 'Slide precedent');
            prevButton.innerHTML = '<i class="bi bi-chevron-left" aria-hidden="true"></i>';

            nextButton = document.createElement('button');
            nextButton.type = 'button';
            nextButton.className = 'emsp-carousel-btn emsp-carousel-btn-next';
            nextButton.setAttribute('aria-label', 'Slide suivant');
            nextButton.innerHTML = '<i class="bi bi-chevron-right" aria-hidden="true"></i>';

            controls.appendChild(prevButton);
            controls.appendChild(nextButton);
            wrapper.appendChild(controls);
        }

        var dots = null;
        if (showDots) {
            dots = document.createElement('div');
            dots.className = 'emsp-carousel-dots';
            wrapper.appendChild(dots);
        }

        container.parentNode.replaceChild(wrapper, container);

        var state = {
            index: 0,
            perView: 1,
            maxIndex: 0,
            slideWidth: 0,
            gap: 0,
            timer: null,
            startX: null,
            dragging: false
        };
        var autoInterval = parseInt((container.dataset && container.dataset.emspCarouselInterval) ? container.dataset.emspCarouselInterval : '6200', 10);
        if ((!container.dataset || !container.dataset.emspCarouselInterval) && carouselStyle === 'steps') {
            autoInterval = 3800;
        }
        if (!Number.isFinite(autoInterval) || autoInterval < 2200) {
            autoInterval = 6200;
        }

        function stopAuto() {
            if (!state.timer) return;
            window.clearInterval(state.timer);
            state.timer = null;
        }

        function startAuto() {
            stopAuto();
            if (prefersReducedMotion()) return;
            if (items.length <= state.perView) return;
            state.timer = window.setInterval(function () {
                if (state.index >= state.maxIndex) {
                    goTo(0, false);
                    return;
                }
                goTo(state.index + 1, false);
            }, autoInterval);
        }

        function renderDots() {
            if (!dots) return;
            dots.innerHTML = '';
            var dotCount = state.maxIndex + 1;
            for (var i = 0; i < dotCount; i += 1) {
                var dot = document.createElement('button');
                dot.type = 'button';
                dot.className = 'emsp-carousel-dot' + (i === state.index ? ' is-active' : '');
                dot.setAttribute('aria-label', 'Aller au slide ' + (i + 1));
                dot.dataset.index = String(i);
                dots.appendChild(dot);
            }
        }

        function measure() {
            state.perView = perViewFromWidth(window.innerWidth, container);
            wrapper.style.setProperty('--emsp-carousel-per-view', String(state.perView));
            state.maxIndex = Math.max(0, items.length - state.perView);
            if (state.index > state.maxIndex) state.index = state.maxIndex;

            if (items[0]) {
                state.slideWidth = items[0].getBoundingClientRect().width;
            } else {
                state.slideWidth = 0;
            }

            var styles = window.getComputedStyle(track);
            state.gap = parseFloat(styles.columnGap || styles.gap || '0') || 0;
            renderDots();
        }

        function updateControls() {
            if (prevButton) {
                prevButton.disabled = state.index <= 0;
            }
            if (nextButton) {
                nextButton.disabled = state.index >= state.maxIndex;
            }
            wrapper.classList.toggle('is-static', state.maxIndex <= 0);
            if (dots) {
                dots.querySelectorAll('.emsp-carousel-dot').forEach(function (dot, i) {
                    dot.classList.toggle('is-active', i === state.index);
                });
            }
        }

        function goTo(nextIndex, instant) {
            state.index = Math.max(0, Math.min(state.maxIndex, nextIndex));
            var offset = (state.slideWidth + state.gap) * state.index;
            if (instant) {
                track.style.transition = 'none';
            } else {
                track.style.transition = '';
            }
            track.style.transform = 'translate3d(' + (-offset) + 'px, 0, 0)';
            if (instant) {
                window.requestAnimationFrame(function () {
                    track.style.transition = '';
                });
            }
            updateControls();
        }

        if (prevButton) {
            prevButton.addEventListener('click', function () {
                goTo(state.index - 1, false);
                startAuto();
            });
        }

        if (nextButton) {
            nextButton.addEventListener('click', function () {
                goTo(state.index + 1, false);
                startAuto();
            });
        }

        if (dots) {
            dots.addEventListener('click', function (event) {
                var button = event.target.closest('.emsp-carousel-dot');
                if (!button) return;
                var next = parseInt(button.dataset.index || '0', 10) || 0;
                goTo(next, false);
                startAuto();
            });
        }

        viewport.addEventListener('pointerdown', function (event) {
            state.startX = event.clientX;
            state.dragging = true;
            stopAuto();
        });

        viewport.addEventListener('pointerup', function (event) {
            if (!state.dragging || state.startX === null) {
                state.dragging = false;
                state.startX = null;
                startAuto();
                return;
            }
            var delta = event.clientX - state.startX;
            if (Math.abs(delta) > 46) {
                if (delta > 0) {
                    goTo(state.index - 1, false);
                } else {
                    goTo(state.index + 1, false);
                }
            }
            state.dragging = false;
            state.startX = null;
            startAuto();
        });

        viewport.addEventListener('pointercancel', function () {
            state.dragging = false;
            state.startX = null;
            startAuto();
        });

        wrapper.addEventListener('mouseenter', stopAuto);
        wrapper.addEventListener('mouseleave', startAuto);
        wrapper.addEventListener('focusin', stopAuto);
        wrapper.addEventListener('focusout', startAuto);

        window.addEventListener('resize', function () {
            measure();
            goTo(state.index, true);
        });

        measure();
        goTo(0, true);
        startAuto();
    }

    function initCarousels() {
        var autoSelectors = [
            'body.emsp-route-index .home-section .row.g-4',
            'body.emsp-route-index .home-quick-grid',
            'body.emsp-route-mediatheque .albums-grid',
            'body.emsp-route-news-blog .journal-actualites-carousel'
        ];

        autoSelectors.forEach(function (selector) {
            document.querySelectorAll(selector).forEach(function (node) {
                if (!node.hasAttribute('data-emsp-carousel')) {
                    node.setAttribute('data-emsp-carousel', '1');
                }
            });
        });

        document.querySelectorAll('[data-emsp-carousel]').forEach(function (container) {
            createCarousel(container);
        });
    }

    function initHeroParallax() {
        if (prefersReducedMotion()) return;
        var hero = document.querySelector('.home-hero-shell, .multimedia-hero-shell');
        if (!hero) return;
        var raf = null;
        var targetX = 0;
        var targetY = 0;

        function render() {
            raf = null;
            hero.style.transform = 'translate3d(' + targetX.toFixed(2) + 'px, ' + targetY.toFixed(2) + 'px, 0)';
        }

        function schedule() {
            if (raf) return;
            raf = window.requestAnimationFrame(render);
        }

        hero.addEventListener('mousemove', function (event) {
            var rect = hero.getBoundingClientRect();
            var cx = rect.left + rect.width / 2;
            var cy = rect.top + rect.height / 2;
            targetX = ((event.clientX - cx) / rect.width) * 6;
            targetY = ((event.clientY - cy) / rect.height) * 4;
            schedule();
        });

        hero.addEventListener('mouseleave', function () {
            targetX = 0;
            targetY = 0;
            schedule();
        });
    }

    function initScrollDynamics() {
        if (prefersReducedMotion()) return;

        var targets = document.querySelectorAll(
            '.home-hero-shell, .multimedia-hero-shell, .journal-shell-header, .emsp-login-side, .emsp-register-banner, .emsp-auth-side, .emsp-reset-side, [data-emsp-parallax-bg]'
        );
        if (!targets.length) return;

        var ticking = false;

        function update() {
            ticking = false;
            var scrollY = window.scrollY || window.pageYOffset || 0;
            document.documentElement.style.setProperty('--emsp-scroll-y', String(scrollY));

            targets.forEach(function (target) {
                var rect = target.getBoundingClientRect();
                var viewportMiddle = window.innerHeight * 0.5;
                var elementMiddle = rect.top + (rect.height * 0.5);
                var ratio = (viewportMiddle - elementMiddle) / Math.max(window.innerHeight, 1);
                var shift = Math.max(-30, Math.min(30, ratio * 42));
                target.style.setProperty('--emsp-parallax-y', shift.toFixed(2) + 'px');

                if (target.hasAttribute('data-emsp-parallax-bg')) {
                    var bgShift = Math.max(-20, Math.min(20, shift * 0.78));
                    target.style.setProperty('--emsp-bg-shift', bgShift.toFixed(2) + 'px');
                }
            });
        }

        function requestUpdate() {
            if (ticking) return;
            ticking = true;
            window.requestAnimationFrame(update);
        }

        update();
        window.addEventListener('scroll', requestUpdate, { passive: true });
        window.addEventListener('resize', requestUpdate);
    }

    function initHomeIndicators() {
        if (prefersReducedMotion()) return;

        var counters = document.querySelectorAll('.home-summary-stat strong');
        if (!counters.length) return;

        function animateCounter(node) {
            if (!node || node.dataset.counterAnimated === '1') return;

            var raw = (node.textContent || '').trim();
            var match = raw.match(/^([0-9 ]+)(.*)$/);
            if (!match) return;

            var numericPart = parseInt(match[1].replace(/\s+/g, ''), 10);
            if (!Number.isFinite(numericPart) || numericPart <= 0) return;

            var suffix = match[2] || '';
            var duration = 900;
            var start = null;
            node.dataset.counterAnimated = '1';

            function step(timestamp) {
                if (start === null) start = timestamp;
                var progress = Math.min(1, (timestamp - start) / duration);
                var eased = 1 - Math.pow(1 - progress, 3);
                var value = Math.round(numericPart * eased);
                node.textContent = value.toLocaleString('fr-FR').replace(/,/g, ' ') + suffix;
                if (progress < 1) {
                    window.requestAnimationFrame(step);
                }
            }

            window.requestAnimationFrame(step);
        }

        if (!('IntersectionObserver' in window)) {
            counters.forEach(animateCounter);
            return;
        }

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                animateCounter(entry.target);
                observer.unobserve(entry.target);
            });
        }, {
            threshold: 0.35
        });

        counters.forEach(function (counter) {
            observer.observe(counter);
        });
    }

    function initTopbarRotator() {
        var reduce = prefersReducedMotion();
        document.querySelectorAll('[data-emsp-topbar-rotator]').forEach(function (rotator) {
            if (rotator.closest('.emsp-topbar-track--static')) return;
            var items = Array.prototype.slice.call(rotator.querySelectorAll('.emsp-topbar-rotator-item'));
            if (items.length <= 1) return;

            var index = 0;
            var timer = null;

            function activate(nextIndex) {
                index = ((nextIndex % items.length) + items.length) % items.length;
                items.forEach(function (item, itemIndex) {
                    item.classList.toggle('is-active', itemIndex === index);
                });
            }

            function stop() {
                if (!timer) return;
                window.clearInterval(timer);
                timer = null;
            }

            function start() {
                stop();
                if (reduce) return;
                timer = window.setInterval(function () {
                    activate(index + 1);
                }, 4200);
            }

            activate(0);
            start();
            rotator.addEventListener('mouseenter', stop);
            rotator.addEventListener('mouseleave', start);
            rotator.addEventListener('focusin', stop);
            rotator.addEventListener('focusout', start);
        });
    }

    function initHowItWorksSpotlight() {
        if (!document.body || document.body.getAttribute('data-route') !== 'index.php') return;

        var cards = Array.prototype.slice.call(document.querySelectorAll('.home-step-card'));
        if (!cards.length) return;

        var reduce = prefersReducedMotion();
        var index = 0;
        var timer = null;

        function highlight(nextIndex) {
            index = ((nextIndex % cards.length) + cards.length) % cards.length;
            cards.forEach(function (card, cardIndex) {
                card.classList.toggle('is-spotlight', cardIndex === index);
            });
        }

        function stop() {
            if (!timer) return;
            window.clearInterval(timer);
            timer = null;
        }

        function start() {
            stop();
            if (reduce) return;
            timer = window.setInterval(function () {
                highlight(index + 1);
            }, 3200);
        }

        highlight(0);
        start();

        cards.forEach(function (card, cardIndex) {
            card.addEventListener('mouseenter', function () {
                stop();
                highlight(cardIndex);
            });
            card.addEventListener('mouseleave', start);
            card.addEventListener('focusin', function () {
                stop();
                highlight(cardIndex);
            });
            card.addEventListener('focusout', start);
        });
    }

    function initImageFallbacks() {
        var defaultFallback = 'assets/images/video-placeholder.jpg';
        var images = document.querySelectorAll(
            'img[data-fallback-src], .album-cover img, .playlist-thumb img, .thumb-img, .journal-cover-img'
        );

        if (!images.length) return;

        images.forEach(function (image) {
            if (!image || image.dataset.fallbackBound === '1') return;
            image.dataset.fallbackBound = '1';

            var fallbackSrc = image.getAttribute('data-fallback-src') || defaultFallback;
            if (!fallbackSrc) return;

            function applyFallback() {
                if (image.dataset.fallbackApplied === '1') return;
                image.dataset.fallbackApplied = '1';
                image.src = fallbackSrc;
            }

            if (image.complete && image.naturalWidth === 0) {
                applyFallback();
            }

            image.addEventListener('error', applyFallback, { once: true });
        });
    }

    onReady(function () {
        if (!isThemeHarvard()) return;
        document.body.classList.add('emsp-experience-upgraded');
        initNavbarScrollState();
        initRevealAnimations();
        enhancePagination();
        initCarousels();
        initHeroParallax();
        initScrollDynamics();
        initHomeIndicators();
        initHowItWorksSpotlight();
        initImageFallbacks();
    });
})(window, document);

