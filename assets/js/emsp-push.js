window.emspPush = (function () {
  var publicKey = null;
  var swReady = null;
  var csrfToken = null;
  var endpoints = {
    subscribe: '/push-subscribe.php',
    unsubscribe: '/push-unsubscribe.php'
  };
  var serviceWorkerUrl = '/sw.js';
  var serviceWorkerScope = '/';

  function urlBase64ToUint8Array(base64String) {
    var padding = '='.repeat((4 - base64String.length % 4) % 4);
    var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    var raw = window.atob(base64);
    var output = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; ++i) {
      output[i] = raw.charCodeAt(i);
    }
    return output;
  }

  function ensureServiceWorker() {
    if (!('serviceWorker' in navigator)) {
      return Promise.reject(new Error('no-sw'));
    }
    if (!swReady) {
      swReady = navigator.serviceWorker.register(serviceWorkerUrl, {
        scope: serviceWorkerScope
      }).then(function () {
        return navigator.serviceWorker.ready;
      });
    }
    return swReady;
  }

  function setPublicKey(key) {
    publicKey = key;
  }

  function setCsrfToken(token) {
    csrfToken = token;
  }

  function setEndpoints(opts) {
    if (!opts) return;
    if (opts.subscribe) endpoints.subscribe = opts.subscribe;
    if (opts.unsubscribe) endpoints.unsubscribe = opts.unsubscribe;
    if (opts.serviceWorkerUrl) serviceWorkerUrl = opts.serviceWorkerUrl;
    if (opts.serviceWorkerScope) serviceWorkerScope = opts.serviceWorkerScope;
  }

  function getPermission() {
    return Notification.permission;
  }

  function postForm(url, payload) {
    var body = new FormData();
    Object.keys(payload).forEach(function (key) {
      body.append(key, payload[key]);
    });
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      body: body
    }).then(function (res) {
      if (!res.ok) {
        throw new Error('http-' + res.status);
      }
      return res.json();
    });
  }

  function saveSubscription(subscription, deviceLabel) {
    if (!subscription) {
      return Promise.reject(new Error('missing-subscription'));
    }
    if (!csrfToken) {
      return Promise.reject(new Error('missing-csrf'));
    }

    var json = subscription.toJSON();
    return postForm(endpoints.subscribe, {
      csrf_token: csrfToken,
      endpoint: json.endpoint,
      p256dh: (json.keys && json.keys.p256dh) ? json.keys.p256dh : '',
      auth: (json.keys && json.keys.auth) ? json.keys.auth : '',
      device_label: deviceLabel || '',
      user_agent: navigator.userAgent || ''
    });
  }

  function removeSubscription(subscription) {
    if (!subscription) {
      return Promise.resolve({ ok: true });
    }
    if (!csrfToken) {
      return Promise.reject(new Error('missing-csrf'));
    }

    var json = subscription.toJSON();
    return postForm(endpoints.unsubscribe, {
      csrf_token: csrfToken,
      endpoint: json.endpoint
    });
  }

  function subscribe() {
    if (!publicKey) {
      return Promise.reject(new Error('missing-key'));
    }
    return ensureServiceWorker().then(function (registration) {
      return registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(publicKey)
      });
    });
  }

  function getSubscription() {
    return ensureServiceWorker().then(function (registration) {
      return registration.pushManager.getSubscription();
    });
  }

  function unsubscribe() {
    return getSubscription().then(function (sub) {
      if (!sub) return null;
      return sub.unsubscribe().then(function () {
        return removeSubscription(sub).catch(function () { return null; });
      });
    });
  }

  return {
    setPublicKey: setPublicKey,
    setCsrfToken: setCsrfToken,
    setEndpoints: setEndpoints,
    subscribe: subscribe,
    getSubscription: getSubscription,
    saveSubscription: saveSubscription,
    removeSubscription: removeSubscription,
    unsubscribe: unsubscribe,
    getPermission: getPermission
  };
})();


