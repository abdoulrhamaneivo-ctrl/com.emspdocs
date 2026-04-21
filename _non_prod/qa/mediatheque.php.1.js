
(function () {
  var video = document.getElementById('player-video');
  var placeholder = document.getElementById('player-local-placeholder');
  var iframe = document.getElementById('player-iframe');
  var mainPlayer = document.getElementById('main-player');
  var posterCache = new Map();
  if (!video || !placeholder || !mainPlayer) return;

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function getPosterUrl(container) {
    if (!container) return '';
    var dataPoster = container.getAttribute('data-video-poster') || '';
    if (dataPoster) return dataPoster;
    var img = container.querySelector('.video-thumb-poster, .video-player-poster');
    return img ? (img.getAttribute('src') || '') : '';
  }

  function getCacheKey(container, fallbackSrc) {
    if (!container) {
      return fallbackSrc || '';
    }
    return container.getAttribute('data-video-cache-key') || fallbackSrc || '';
  }

  function readStoredPoster(cacheKey) {
    return '';
  }

  function storePoster(cacheKey, posterUrl) {
    return;
  }

  function applyPoster(container, posterUrl) {
    if (!container || !posterUrl) return;
    var img = container.querySelector('.video-thumb-poster, .video-player-poster');
    if (!img) return;
    img.setAttribute('src', posterUrl);
    container.classList.add('has-poster');
  }

  function cleanupPosterLoader(loader) {
    if (!loader) return;
    try {
      loader.pause();
      loader.removeAttribute('src');
      loader.load();
    } catch (error) {
      // no-op
    }
    if (loader.parentNode) {
      loader.parentNode.removeChild(loader);
    }
  }

  function ensureVideoPoster(src, cacheKey) {
    if (!src) {
      return Promise.resolve('');
    }
    if (posterCache.has(src)) {
      return Promise.resolve(posterCache.get(src));
    }
    return Promise.resolve('');
  }

  function renderLocalPlaceholder(letter, label, src, posterUrl, cacheKey) {
    var safeLetter = escapeHtml(letter);
    var safeLabel = escapeHtml(label || 'Video EMSP');
    var safeSrc = escapeHtml(src);
    var safePoster = escapeHtml(posterUrl || '');
    var safeCacheKey = escapeHtml(cacheKey || '');
    var hasPosterClass = safePoster !== '' ? ' has-poster' : '';
    var posterMarkup = '<img class="video-player-poster" alt="' + safeLabel + '"' + (safePoster !== '' ? ' src="' + safePoster + '"' : '') + '>';

    return ''
      + '<button type="button" class="video-player-local-card' + hasPosterClass + '" data-local-player-trigger data-video-src="' + safeSrc + '" data-video-title="' + safeLabel + '" data-video-initial="' + safeLetter + '" data-video-cache-key="' + safeCacheKey + '" data-video-poster="' + safePoster + '">'
      + posterMarkup
      + '<span class="video-player-type">Video EMSP</span>'
      + '<span class="video-player-initial">' + safeLetter + '</span>'
      + '<div class="video-player-play"><i class="bi bi-play-circle-fill"></i></div>'
      + '<div class="video-player-title">' + safeLabel + '</div>'
      + '</button>';
  }

  function showLocalPlaceholder() {
    video.style.display = 'none';
    if (placeholder.innerHTML.trim() !== '') {
      placeholder.style.display = 'flex';
    }
  }

  function revealVideo() {
    if (iframe && iframe.style.display === 'block') {
      return;
    }
    video.style.display = 'block';
    placeholder.style.display = 'none';
    var trigger = placeholder.querySelector('[data-local-player-trigger]');
    if (trigger) {
      trigger.classList.remove('is-loading');
    }
  }

  function hideYoutubePlayer() {
    if (!iframe) return;
    iframe.style.display = 'none';
    iframe.src = '';
  }

  function stopLocalPlayer() {
    try {
      video.pause();
      video.currentTime = 0;
    } catch (error) {
      // no-op
    }
    video.removeAttribute('src');
    video.removeAttribute('poster');
    video.load();
    video.style.display = 'none';
  }

  function bindLocalPlayerTrigger() {
    var trigger = placeholder.querySelector('[data-local-player-trigger]');
    if (!trigger || trigger.dataset.bound === '1') {
      return;
    }
    trigger.dataset.bound = '1';
    function startLocalPlayback() {
      var src = trigger.dataset.videoSrc || '';
      var cacheKey = trigger.dataset.videoCacheKey || src;
      if (!src) return;

      var posterUrl = getPosterUrl(trigger);
      trigger.classList.add('is-loading');
      hideYoutubePlayer();
      stopLocalPlayer();

      if (posterUrl) {
        video.setAttribute('poster', posterUrl);
        video.style.display = 'block';
        placeholder.style.display = 'none';
      } else {
        video.removeAttribute('poster');
        showLocalPlaceholder();
      }

      video.src = src;
      video.load();
      var playPromise = video.play();
      if (playPromise && typeof playPromise.catch === 'function') {
        playPromise.catch(function () {
          trigger.classList.remove('is-loading');
          showLocalPlaceholder();
        });
      }
    }

    trigger.addEventListener('click', startLocalPlayback);
    trigger.addEventListener('keydown', function (event) {
      if (event.key !== 'Enter' && event.key !== ' ') {
        return;
      }
      event.preventDefault();
      startLocalPlayback();
    });

    var triggerPoster = getPosterUrl(trigger);
    if (triggerPoster) {
      applyPoster(trigger, triggerPoster);
    }
  }

  function hydrateLocalThumb(card) {
    if (!card) return;
    var src = card.dataset.videoSrc || '';
    var cacheKey = card.dataset.videoCacheKey || src;
    if (!src) return;

    var cachedPoster = posterCache.get(src) || getPosterUrl(card) || '';
    if (cachedPoster) {
      posterCache.set(src, cachedPoster);
      applyPoster(card, cachedPoster);
    }
  }

  function bootstrapThumbHydration() {
    var thumbCards = document.querySelectorAll('[data-local-thumb]');
    if ('IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          hydrateLocalThumb(entry.target);
          io.unobserve(entry.target);
        });
      }, { rootMargin: '160px 0px' });
      thumbCards.forEach(function (card) {
        io.observe(card);
      });
    } else {
      thumbCards.forEach(hydrateLocalThumb);
    }
  }

  function activatePlaylistItem(item) {
    if (!item) {
      return;
    }
    document.querySelectorAll('.playlist-item').forEach(function (el) {
      el.classList.remove('active');
    });
    item.classList.add('active');

    var src = item.dataset.src || '';
    var type = item.dataset.type || 'video';
    var title = item.dataset.title || '';
    var desc = item.dataset.desc || '';
    var initial = item.dataset.initial || 'V';
    var cacheKey = item.dataset.cacheKey || src;

    document.getElementById('player-title').textContent = title;
    document.getElementById('player-desc').textContent = desc;

    if (type === 'youtube') {
      stopLocalPlayer();
      placeholder.style.display = 'none';
      placeholder.innerHTML = '';
      if (iframe) {
        iframe.src = src;
        iframe.style.display = 'block';
      }
    } else {
      var posterUrl = getPosterUrl(item);
      hideYoutubePlayer();
      stopLocalPlayer();
      placeholder.innerHTML = renderLocalPlaceholder(initial, title || 'Video EMSP', src, posterUrl, cacheKey);
      placeholder.style.display = 'flex';
      bindLocalPlayerTrigger();

    }

    mainPlayer.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function bindPlaylistItems() {
    document.querySelectorAll('.playlist-item').forEach(function (item) {
      if (item.dataset.bound === '1') {
        return;
      }
      item.dataset.bound = '1';
      item.addEventListener('click', function () {
        activatePlaylistItem(item);
      });
      item.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' && event.key !== ' ') {
          return;
        }
        event.preventDefault();
        activatePlaylistItem(item);
      });
    });
  }

  window.switchVideo = activatePlaylistItem;

  video.addEventListener('loadeddata', revealVideo);
  video.addEventListener('canplay', revealVideo);
  video.addEventListener('error', function () {
    showLocalPlaceholder();
    var titleEl = document.getElementById('player-title');
    var descEl = document.getElementById('player-desc');
    if (descEl && descEl.textContent.trim() === '') {
      descEl.textContent = 'La video n a pas pu etre lue pour le moment.';
    }
    if (titleEl && titleEl.textContent.trim() === '') {
      titleEl.textContent = 'Lecture indisponible';
    }
  });

  bindLocalPlayerTrigger();
  bindPlaylistItems();
  bootstrapThumbHydration();

  var initialTrigger = placeholder.querySelector('[data-local-player-trigger]');
  if (initialTrigger) {
    hydrateLocalThumb(initialTrigger);
  }
})();

// Lightbox photos
(function(){
    const photos = 1;
    if (!photos.length) return;
    let lbIndex = 0;
    const lbModal = new bootstrap.Modal(document.getElementById('photoLightbox'));
    const lbImage = document.getElementById('lbImage');
    const lbCaption = document.getElementById('lbCaption');
    function show(i){
        lbIndex = (i + photos.length) % photos.length;
        lbImage.src = photos[lbIndex].src;
        lbCaption.textContent = photos[lbIndex].title || '';
    }
    document.querySelectorAll('.photo-item').forEach((el, idx)=>{
        el.addEventListener('click', ()=>{
            show(idx); lbModal.show();
        });
    });
    document.getElementById('lbPrev').addEventListener('click', ()=>show(lbIndex-1));
    document.getElementById('lbNext').addEventListener('click', ()=>show(lbIndex+1));
    document.addEventListener('keydown', (e)=>{
        if (!document.getElementById('photoLightbox').classList.contains('show')) return;
        if (e.key === 'ArrowLeft') show(lbIndex-1);
        if (e.key === 'ArrowRight') show(lbIndex+1);
    });
})();

// Ouverture d'un album en modal avec defilement
(function(){
    if (typeof bootstrap === 'undefined') {
        return;
    }
    const modal = new bootstrap.Modal(document.getElementById('albumModal'));
    const inner = document.getElementById('albumCarouselInner');
    const title = document.getElementById('albumModalTitle');
    const prevBtn = document.getElementById('albumPrevBtn');
    const nextBtn = document.getElementById('albumNextBtn');
    const carouselEl = document.getElementById('albumCarousel');
    const carousel = carouselEl ? bootstrap.Carousel.getOrCreateInstance(carouselEl, { interval: false }) : null;
    if (prevBtn) {
        prevBtn.addEventListener('click', () => { if (carousel) { carousel.prev(); } });
    }
    if (nextBtn) {
        nextBtn.addEventListener('click', () => { if (carousel) { carousel.next(); } });
    }
    document.querySelectorAll('.album-open-trigger').forEach(link => {
        link.addEventListener('click', function(e){
            const href = this.getAttribute('href') || '';
            e.preventDefault();
            const cat = this.dataset.category || '';
            const albumId = this.dataset.albumId || '';
            const label = this.dataset.categoryLabel || cat;
            title.textContent = label !== '' ? ('Album â€” ' + label) : 'Album (sans titre)';
            inner.innerHTML = '<div class="carousel-item active text-center py-5" id="albumLoader"><div class="spinner-border text-success"></div></div>';
            modal.show();
            if (prevBtn) prevBtn.style.display = 'none';
            if (nextBtn) nextBtn.style.display = 'none';
            const params = new URLSearchParams();
            params.set('ajax', 'album');
            if (albumId !== '') {
                params.set('album_id', albumId);
            }
            params.set('category', cat);
            fetch('mediatheque.php?' + params.toString(), { credentials: 'include' })
                .then(r => r.json())
                .then(data => {
                    const photos = data.photos || [];
                    if (!photos.length) {
                        inner.innerHTML = '<div class="carousel-item active text-center py-5"><p class="text-muted mb-0">Aucune photo dans cet album.</p></div>';
                        return;
                    }
                    inner.innerHTML = '';
                    photos.forEach((p, idx) => {
                        const item = document.createElement('div');
                        item.className = 'carousel-item' + (idx === 0 ? ' active' : '');
                        item.innerHTML = `
                            <div class="d-flex justify-content-center">
                                <img src="${p.src}" alt="${p.title || ''}" class="d-block" style="max-height:70vh; max-width:100%; object-fit:contain; background:#000;">
                            </div>
                            ${p.title ? `<div class="text-center mt-2 text-muted">${p.title}</div>` : ''}
                        `;
                        inner.appendChild(item);
                    });
                    if (prevBtn && nextBtn) {
                        const showNav = photos.length > 1;
                        prevBtn.style.display = showNav ? 'flex' : 'none';
                        nextBtn.style.display = showNav ? 'flex' : 'none';
                    }
                })
                .catch(()=>{
                    if (href !== '') {
                        window.location.href = href;
                        return;
                    }
                    inner.innerHTML = '<div class="carousel-item active text-center py-5"><p class="text-muted mb-0">Impossible de charger cet album.</p></div>';
                });
        });
    });
})();



