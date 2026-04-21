
(function () {
    function loadScriptOnce(src) {
        return new Promise((resolve, reject) => {
            const existing = document.querySelector('script[data-emsp-src="' + src + '"]');
            if (existing) {
                if (existing.dataset.loaded === '1') {
                    resolve();
                    return;
                }
                existing.addEventListener('load', () => resolve(), { once: true });
                existing.addEventListener('error', () => reject(new Error('load failed')), { once: true });
                return;
            }
            const script = document.createElement('script');
            script.src = src;
            script.async = true;
            script.dataset.emspSrc = src;
            script.onload = () => {
                script.dataset.loaded = '1';
                resolve();
            };
            script.onerror = () => reject(new Error('load failed'));
            document.head.appendChild(script);
        });
    }

    const pdfThumbs = Array.from(document.querySelectorAll('.pdf-thumb-canvas'));
    const renderedThumbs = new WeakSet();

    async function renderPdfThumb(canvas) {
        if (!canvas || renderedThumbs.has(canvas)) return;
        renderedThumbs.add(canvas);

        const src = canvas.dataset.pdfSrc || '';
        if (!src) return;

        try {
            if (typeof window.pdfjsLib === 'undefined') {
                await loadScriptOnce('assets/js/pdf.min.js');
            }
            if (typeof window.pdfjsLib === 'undefined') {
                return;
            }
            if (!window.pdfjsLib.GlobalWorkerOptions.workerSrc) {
                window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'assets/js/pdf.worker.min.js';
            }

            const pdf = await window.pdfjsLib.getDocument(src).promise;
            const page = await pdf.getPage(1);
            const viewport = page.getViewport({ scale: 1 });
            const scale = Math.max(0.6, 240 / Math.max(viewport.width, 1));
            const scaledViewport = page.getViewport({ scale });
            const ratio = window.devicePixelRatio || 1;
            const ctx = canvas.getContext('2d');
            if (!ctx) return;

            canvas.width = scaledViewport.width * ratio;
            canvas.height = scaledViewport.height * ratio;
            canvas.style.width = '100%';
            canvas.style.height = '100%';
            ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, scaledViewport.width, scaledViewport.height);

            await page.render({
                canvasContext: ctx,
                viewport: scaledViewport
            }).promise;
        } catch (error) {
            canvas.remove();
        }
    }

    if ('IntersectionObserver' in window && pdfThumbs.length > 0) {
        const pdfObserver = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                renderPdfThumb(entry.target);
                pdfObserver.unobserve(entry.target);
            });
        }, { rootMargin: '160px 0px' });
        pdfThumbs.forEach((canvas) => pdfObserver.observe(canvas));
    } else {
        pdfThumbs.forEach((canvas) => renderPdfThumb(canvas));
    }

    // Reveal + counters on view
    const cards = document.querySelectorAll('.doc-card-wrap');
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('in-view');
                const counter = entry.target.querySelector('.dl-counter');
                if (counter && !counter.dataset.animated) {
                    counter.dataset.animated = '1';
                    const target = parseInt(counter.dataset.target) || 0;
                    const duration = 900;
                    const start = performance.now();
                    function tick(now) {
                        const p = Math.min((now - start) / duration, 1);
                        counter.textContent = Math.floor(target * p);
                        if (p < 1) requestAnimationFrame(tick);
                    }
                    requestAnimationFrame(tick);
                }
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });
    cards.forEach(el => observer.observe(el));

    document.querySelectorAll('.form-download').forEach(form => {
        form.addEventListener('submit', function() {
            const card = this.closest('.doc-card');
            if (!card) return;
            card.querySelectorAll('.dl-counter').forEach(counter => {
                const current = parseInt(counter.textContent, 10) || 0;
                counter.textContent = current + 1;
                counter.style.color = '#086136';
                counter.style.fontWeight = '700';
                counter.dataset.animated = '1';
            });
        });
    });
})();


