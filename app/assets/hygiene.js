(function () {
  'use strict';
  var API_BASE = (window.SIQ_API_BASE || (window.SIQ_BASE_URL ? window.SIQ_BASE_URL + '/api' : 'api'));
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
      if (name === 'rules') loadRules();
    });
  });

  function healthClass(score) {
    if (score >= 80) return 'siq-health siq-health--green';
    if (score >= 50) return 'siq-health siq-health--amber';
    return 'siq-health siq-health--red';
  }

  function loadFlags() {
    var box = el('hyg-flags'); var circle = el('hyg-health-circle'); var last = el('hyg-last-scan');
    fetch(API_BASE + '/hygiene/flags.php' + qs).then(function (r) { return r.json(); }).then(function (d) {
      if (d.health_score == null) {
        circle.innerHTML = '<div class="siq-empty">No scan yet.</div>';
      } else {
        circle.innerHTML = '<div class="' + healthClass(d.health_score) + '">' + d.health_score + '</div>';
      }
      if (d.last_scan_at) last.textContent = 'Last scan: ' + d.last_scan_at;
      var groups = d.flags || {};
      var sevs = ['critical', 'warning', 'info'];
      var anyFlags = sevs.some(function (s) { return (groups[s] || []).length > 0; });
      if (!anyFlags) { box.innerHTML = '<div class="siq-empty">All clear — no open issues.</div>'; return; }
      box.innerHTML = sevs.map(function (sev) {
        var rows = groups[sev] || [];
        if (!rows.length) return '';
        return '<h4>' + sev.charAt(0).toUpperCase() + sev.slice(1) + ' (' + rows.length + ')</h4>' +
          '<table class="siq-table"><tbody>' + rows.map(function (f) {
            return '<tr><td>' + escape(f.entity_title || f.shopify_entity_id) + '</td>' +
                   '<td class="siq-muted">' + escape(f.rule_name) + '</td>' +
                   '<td><button class="btn btn-ghost btn-sm" data-dismiss="' + f.id + '" type="button">Dismiss</button></td></tr>';
          }).join('') + '</tbody></table>';
      }).join('');
      box.querySelectorAll('[data-dismiss]').forEach(function (b) {
        b.addEventListener('click', function () {
          fetch(API_BASE + '/hygiene/dismiss.php' + qs, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ flag_id: parseInt(b.getAttribute('data-dismiss'), 10) })
          }).then(function () { loadFlags(); });
        });
      });
    });
  }

  function loadRules() {
    var box = el('hyg-rules'); if (!box) return;
    box.textContent = 'Loading…';
    fetch(API_BASE + '/hygiene/rules.php' + qs).then(function (r) { return r.json(); }).then(function (d) {
      var groups = d.groups || {};
      var html = '';
      Object.keys(groups).forEach(function (cat) {
        html += '<h4>' + escape(cat) + '</h4><table class="siq-table"><tbody>' +
          groups[cat].map(function (r) {
            var lock = r.locked ? ' 🔒' : '';
            return '<tr><td><b>' + escape(r.name) + '</b>' + lock + '<div class="siq-muted" style="font-size:12px;">' + escape(r.description || '') + '</div></td>' +
                   '<td><span class="siq-badge siq-badge--' + escape(r.severity) + '">' + escape(r.severity) + '</span></td>' +
                   '<td>' + (r.locked
                     ? '<span class="siq-muted">Plan upgrade required</span>'
                     : '<label class="siq-flex"><input type="checkbox" data-rule-id="' + r.id + '" ' + (parseInt(r.is_enabled, 10) ? 'checked' : '') + '> <span class="siq-muted">Enabled</span></label>') + '</td></tr>';
          }).join('') + '</tbody></table>';
      });
      box.innerHTML = html;
      box.querySelectorAll('input[data-rule-id]').forEach(function (cb) {
        cb.addEventListener('change', function () {
          fetch(API_BASE + '/hygiene/rules.php' + qs, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ rule_id: parseInt(cb.getAttribute('data-rule-id'), 10), enabled: cb.checked })
          });
        });
      });
    });
  }

  if (el('hyg-scan-btn')) {
    el('hyg-scan-btn').addEventListener('click', function () {
      var b = el('hyg-scan-btn'); b.disabled = true; b.textContent = 'Queuing…';
      fetch(API_BASE + '/hygiene/scan.php' + qs, { method: 'POST' }).then(function (r) { return r.json(); }).then(function () {
        b.disabled = false; b.textContent = 'Run scan now';
        setTimeout(loadFlags, 1500);
      });
    });
  }

  (window.__siqReady || Promise.resolve()).then(loadFlags);
})();
