/**
 * StoreIQ boot script — runs on every embedded page load.
 *
 * Purpose: exchange the App Bridge session JWT for an offline access token
 * via /app/auth/token_exchange.php. Without this, every Shopify Admin API
 * call from our backend fails with HTTP 403 ("non-expiring tokens rejected").
 *
 * Self-contained so it works even if nav.php's script block fails.
 */
(function () {
  'use strict';

  // Exposed for page JS to await.
  window.__siqReady = new Promise(function (resolve) {

    // Hard timeout — never block the page longer than 8 seconds.
    var resolved = false;
    function done(v) {
      if (resolved) return;
      resolved = true;
      resolve(v);
      if (window.console) console.log('[StoreIQ boot]', v && v.ok ? 'ready ✓' : 'ready ✗', v);
    }
    setTimeout(function () { if (!resolved) done({ ok: false, error: 'timeout_8s' }); }, 8000);

    // Fetch the session JWT from App Bridge (window.shopify is auto-injected by app-bridge.js).
    function getJwt(retries) {
      retries = retries || 0;
      try {
        if (window.shopify && typeof window.shopify.idToken === 'function') {
          return window.shopify.idToken();
        }
      } catch (e) { /* ignore */ }
      if (retries >= 30) {
        // Couldn't find App Bridge after ~3 seconds. Probably not running embedded.
        return Promise.resolve(null);
      }
      return new Promise(function (r) { setTimeout(r, 100); }).then(function () { return getJwt(retries + 1); });
    }

    var BASE = (window.SIQ_BASE_URL || '');
    if (!BASE) {
      if (window.console) console.error('[StoreIQ boot] SIQ_BASE_URL not set — header.php may be stale');
      done({ ok: false, error: 'no_base_url' });
      return;
    }

    getJwt().then(function (jwt) {
      if (!jwt) {
        if (window.console) console.warn('[StoreIQ boot] no JWT — App Bridge not ready or page is not embedded');
        done({ ok: false, error: 'no_session_token' });
        return;
      }
      if (window.console) console.log('[StoreIQ boot] got JWT, calling token_exchange.php');

      return fetch(BASE + '/auth/token_exchange.php', {
        method: 'POST',
        headers: { 'Authorization': 'Bearer ' + jwt },
        credentials: 'same-origin'
      }).then(function (resp) {
        return resp.text().then(function (txt) {
          var data = null;
          try { data = JSON.parse(txt); } catch (e) { data = null; }
          if (resp.ok && data && data.ok) {
            done({ ok: true, data: data });
          } else {
            if (window.console) console.error('[StoreIQ boot] token_exchange failed', resp.status, txt);
            done({ ok: false, status: resp.status, body: txt });
          }
        });
      });
    }).catch(function (e) {
      if (window.console) console.error('[StoreIQ boot] error', e);
      done({ ok: false, error: String(e) });
    });
  });
})();
