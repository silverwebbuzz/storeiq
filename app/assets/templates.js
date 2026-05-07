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

  document.querySelectorAll('.siq-tab').forEach(function (t) {
    t.addEventListener('click', function () {
      document.querySelectorAll('.siq-tab').forEach(function (x) { x.classList.remove('active'); });
      t.classList.add('active');
      var name = t.getAttribute('data-tab');
      document.querySelectorAll('[data-tab-pane]').forEach(function (p) {
        p.style.display = (p.getAttribute('data-tab-pane') === name) ? '' : 'none';
      });
    });
  });

  function renderGroups(boxId, d, basePage) {
    var box = el(boxId); if (!box) return;
    var groups = d.groups || {};
    var html = '';
    Object.keys(groups).forEach(function (cat) {
      html += '<h4>' + escape(cat) + '</h4><div class="siq-grid-3">' +
        groups[cat].map(function (t) {
          return '<div class="siq-card" style="margin:0;padding:14px;">' +
            '<b>' + escape(t.name) + '</b>' +
            '<div class="siq-muted siq-mt-16" style="font-size:12px;">' + escape(t.description || '') + '</div>' +
            '<a class="btn btn-primary btn-sm siq-mt-16" href="' + basePage + qs + '&template_id=' + t.id + '">Use this</a>' +
          '</div>';
        }).join('') + '</div>';
    });
    box.innerHTML = html || '<div class="siq-empty">No templates available.</div>';
  }

  fetch('api/templates/bulk.php' + qs).then(function (r) { return r.json(); }).then(function (d) {
    renderGroups('tpl-bulk', d, 'bulk-edit');
  });
  fetch('api/templates/campaigns.php' + qs).then(function (r) { return r.json(); }).then(function (d) {
    renderGroups('tpl-campaign', d, 'campaigns');
  });
})();
