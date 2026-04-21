(function () {
  let deferredPrompt = null;
  let installBanner = null;
  let installBtn = null;
  let closeBtn = null;

  function bindInstallUi() {
    installBanner = document.querySelector('[data-emsp-install-banner]');
    installBtn = document.querySelector('[data-emsp-install-btn]');
    closeBtn = document.querySelector('[data-emsp-install-close]');

    if (installBtn && !installBtn.dataset.emspBound) {
      installBtn.dataset.emspBound = '1';
      installBtn.addEventListener('click', async () => {
        if (!deferredPrompt) return;
        deferredPrompt.prompt();
        await deferredPrompt.userChoice;
        deferredPrompt = null;
        if (installBanner) installBanner.classList.remove('is-visible');
      });
    }

    if (closeBtn && !closeBtn.dataset.emspBound) {
      closeBtn.dataset.emspBound = '1';
      closeBtn.addEventListener('click', () => {
        try {
          localStorage.setItem('emsp_pwa_banner_dismissed', '1');
        } catch (e) {}
        if (installBanner) installBanner.classList.remove('is-visible');
      });
    }

    var activePath = (window.location.pathname || '').split('/').pop();
    document.querySelectorAll('.pwa-bottom-nav-link').forEach((link) => {
      var href = link.getAttribute('href') || '';
      if (activePath && href.indexOf(activePath) !== -1) {
        link.classList.add('is-active');
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindInstallUi);
  } else {
    bindInstallUi();
  }

  var isStandalone = window.matchMedia && window.matchMedia('(display-mode: standalone)').matches;
  if (isStandalone && installBanner) {
    installBanner.classList.remove('is-visible');
  }

  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    if (isStandalone) return;
    try {
      if (localStorage.getItem('emsp_pwa_banner_dismissed') === '1') return;
    } catch (err) {}
    if (installBanner) installBanner.classList.add('is-visible');
  });

  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
      navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function(e) {
        console.warn('[PWA] SW registration failed:', e);
      });
    });
  }
})();
