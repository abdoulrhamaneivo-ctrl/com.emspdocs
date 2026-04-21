self.addEventListener('install', function (event) {
  var CACHE = 'emsp-shell-v1';
  var SHELL = [
    '/index.php',
    '/bibliotheque.php',
    '/formations.php',
    '/assets/css/emsp-theme.css',
    '/assets/css/emsp-fixes.css',
    '/assets/js/bootstrap5.bundle.min.js',
    '/offline.php'
  ];

  event.waitUntil(
    caches.open(CACHE).then(function (cache) {
      return cache.addAll(SHELL);
    }).then(function () {
      return self.skipWaiting();
    })
  );
});

self.addEventListener('activate', function (event) {
  var CACHE = 'emsp-shell-v1';
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(
        keys.filter(function (key) {
          return key !== CACHE;
        }).map(function (key) {
          return caches.delete(key);
        })
      );
    }).then(function () {
      return self.clients.claim();
    })
  );
});

self.addEventListener('fetch', function (event) {
  if (event.request.method !== 'GET') {
    return;
  }
  var url = new URL(event.request.url);
  if (url.pathname.indexOf('/admin/') !== -1 || url.pathname.indexOf('/telecharger.php') !== -1) {
    return;
  }

  event.respondWith(
    fetch(event.request).then(function (response) {
      var copy = response.clone();
      caches.open('emsp-shell-v1').then(function (cache) {
        cache.put(event.request, copy);
      });
      return response;
    }).catch(function () {
      return caches.match(event.request).then(function (cached) {
        if (cached) {
          return cached;
        }
        return caches.match('/offline.php');
      });
    })
  );
});

self.addEventListener('push', function (event) {
  var data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch (e) {
    data = { title: 'EMSP Docs', body: event.data ? event.data.text() : '' };
  }

  var title = data.title || 'EMSP Docs';
  var options = {
    body: data.body || '',
    icon: data.icon || 'assets/images/logo-emsp.png',
    badge: data.badge || 'assets/images/logo-emsp.png',
    data: {
      url: data.url || './index.php'
    }
  };

  event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var target = (event.notification.data && event.notification.data.url) ? event.notification.data.url : '/index.php';
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
      for (var i = 0; i < clientList.length; i++) {
        var client = clientList[i];
        if (client.url === target && 'focus' in client) {
          return client.focus();
        }
      }
      if (clients.openWindow) {
        return clients.openWindow(target);
      }
    })
  );
});
