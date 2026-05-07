(function () {
  'use strict';
  var params = new URLSearchParams(window.location.search);
  var shop = params.get('shop') || '';
  var host = params.get('host') || '';
  var qs = '?shop=' + encodeURIComponent(shop) + '&host=' + encodeURIComponent(host);
  function el(id) { return document.getElementById(id); }
  function escape(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
    return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c];
  }); }

  var promoBody = el('settings-promo-body');
  if (promoBody) {
    fetch('api/promo/claim.php' + qs, { method: 'POST' }).then(function (r) { return r.json(); }).then(function (d) {
      if (d.error) { promoBody.textContent = ''; var card = el('settings-promo-card'); if (card) card.style.display = 'none'; return; }
      promoBody.innerHTML =
        '<b>' + escape(d.headline || 'You unlocked a SalesBoost AI perk') + '</b>' +
        '<div class="siq-muted siq-mt-16">' + escape(d.description || '') + '</div>' +
        '<div class="siq-mt-16">Code: <code style="background:#fff;padding:4px 8px;border-radius:4px;border:1px solid #e5e7eb;">' + escape(d.code) + '</code> ' +
        '<button class="btn btn-ghost btn-sm" id="copy-promo" type="button">Copy</button></div>';
      var btn = el('copy-promo');
      if (btn) btn.addEventListener('click', function () {
        navigator.clipboard && navigator.clipboard.writeText(d.code);
        btn.textContent = 'Copied';
        setTimeout(function () { btn.textContent = 'Copy'; }, 1500);
      });
    });
  }
})();
