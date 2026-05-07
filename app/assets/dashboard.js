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

  function setHref(id, path) { var e = el(id); if (e) e.href = path + qs; }
  setHref('dash-view-issues', 'hygiene');
  setHref('dash-view-campaigns', 'campaigns');
  setHref('quick-bulk-edit', 'bulk-edit');
  setHref('quick-campaign', 'campaigns');

  function healthClass(score) {
    if (score == null) return 'siq-health';
    if (score >= 80) return 'siq-health siq-health--green';
    if (score >= 50) return 'siq-health siq-health--amber';
    return 'siq-health siq-health--red';
  }

  function renderHealth(d) {
    var box = el('dash-health'); if (!box) return;
    if (d.health_score == null) {
      box.innerHTML = '<div class="siq-empty">No scan yet. Click <b>Scan store health</b> to begin.</div>';
      return;
    }
    var f = d.open_flags || {};
    box.innerHTML =
      '<div class="' + healthClass(d.health_score) + '">' + d.health_score + '</div>' +
      '<div class="siq-mt-16 siq-flex" style="justify-content:center;">' +
        '<span class="siq-badge siq-badge--failed">' + (f.critical || 0) + ' critical</span>' +
        '<span class="siq-badge siq-badge--cancelled">' + (f.warning || 0) + ' warnings</span>' +
        '<span class="siq-badge siq-badge--running">' + (f.info || 0) + ' info</span>' +
      '</div>';
  }

  function renderUpcoming(d) {
    var box = el('dash-upcoming'); if (!box) return;
    var rows = d.upcoming_campaigns || [];
    if (!rows.length) { box.innerHTML = '<div class="siq-empty">No campaigns scheduled.</div>'; return; }
    box.innerHTML = rows.map(function (c) {
      return '<div class="siq-flex-between" style="padding:8px 0;border-bottom:1px solid #f3f4f6;">' +
        '<div><b>' + escape(c.name) + '</b><div class="siq-muted" style="font-size:12px;">starts ' + escape(c.start_at) + '</div></div>' +
        '<span class="siq-muted">' + (c.total_products || 0) + ' products</span>' +
      '</div>';
    }).join('');
  }

  function renderActivity(d) {
    var box = el('dash-activity'); if (!box) return;
    var rows = d.recent_activity || [];
    if (!rows.length) { box.innerHTML = '<div class="siq-empty">No recent activity.</div>'; return; }
    box.innerHTML = '<table class="siq-table"><thead><tr><th>When</th><th>Action</th><th>Summary</th></tr></thead><tbody>' +
      rows.map(function (a) {
        return '<tr><td class="siq-muted">' + escape(a.created_at) + '</td>' +
               '<td>' + escape(a.action) + '</td>' +
               '<td>' + escape(a.summary || '') + '</td></tr>';
      }).join('') + '</tbody></table>';
  }

  function renderActiveJobs(d) {
    var box = el('dash-active-jobs'); if (!box) return;
    var n = d.active_jobs || 0;
    box.innerHTML = n
      ? ('<div style="font-size:32px;font-weight:700;color:#1d4ed8;">' + n + '</div><div class="siq-muted">running or queued</div>')
      : '<div class="siq-empty">No background jobs running.</div>';
  }

  function renderPromo(d) {
    var card = el('dash-promo-card'); if (!card) return;
    if (!d.has_promo || !d.promo) return;
    card.style.display = '';
    var body = el('dash-promo-body');
    if (body) {
      body.innerHTML =
        '<b>' + escape(d.promo.headline || 'You unlocked a perk') + '</b>' +
        '<div class="siq-muted siq-mt-16">' + escape(d.promo.description || '') + '</div>' +
        (d.promo.code
          ? '<div class="siq-mt-16">Your code: <code style="background:#fff;padding:4px 8px;border-radius:4px;border:1px solid #e5e7eb;">' + escape(d.promo.code) + '</code></div>'
          : '<div class="siq-mt-16"><a class="btn btn-primary btn-sm" href="settings' + qs + '">' + escape(d.promo.cta_label || 'Reveal code') + '</a></div>');
    }
  }

  function load() {
    fetch('api/dashboard.php' + qs, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || d.error) { return; }
        renderHealth(d);
        renderUpcoming(d);
        renderActivity(d);
        renderActiveJobs(d);
        renderPromo(d);
      })
      .catch(function (e) { if (window.console) console.error('dashboard load failed', e); });
  }

  var scanBtn = el('dash-scan-now');
  if (scanBtn) {
    scanBtn.addEventListener('click', function () {
      scanBtn.disabled = true;
      scanBtn.textContent = 'Queuing scan…';
      fetch('api/hygiene/scan.php' + qs, { method: 'POST', credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function () {
          scanBtn.textContent = 'Scan queued';
          setTimeout(function () { scanBtn.disabled = false; scanBtn.textContent = 'Scan store health'; load(); }, 2500);
        })
        .catch(function () {
          scanBtn.disabled = false;
          scanBtn.textContent = 'Scan store health';
        });
    });
  }

  document.addEventListener('DOMContentLoaded', load);
  if (document.readyState !== 'loading') load();
})();
