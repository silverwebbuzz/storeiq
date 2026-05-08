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
    });
  });

  function loadList() {
    var box = el('cmp-list'); if (!box) return;
    box.textContent = 'Loading…';
    fetch(API_BASE + '/campaigns/list.php' + qs).then(function (r) { return r.json(); }).then(function (d) {
      var rows = d.campaigns || [];
      if (!rows.length) { box.innerHTML = '<div class="siq-empty">No campaigns yet.</div>'; return; }
      box.innerHTML = '<table class="siq-table"><thead><tr><th>Name</th><th>Status</th><th>Start</th><th>End</th><th>Auto-revert</th><th></th></tr></thead><tbody>' +
        rows.map(function (c) {
          return '<tr><td>' + escape(c.name) + '</td>' +
            '<td><span class="siq-badge siq-badge--' + c.status + '">' + c.status + '</span></td>' +
            '<td class="siq-muted">' + escape(c.start_at || '') + '</td>' +
            '<td class="siq-muted">' + escape(c.end_at || '') + '</td>' +
            '<td>' + (c.auto_revert ? 'Yes' : 'No') + '</td>' +
            '<td>' + ((c.status === 'scheduled') ? '<button class="btn btn-ghost btn-sm" data-cancel="' + c.id + '" type="button">Cancel</button>' : '') + '</td>' +
            '</tr>';
        }).join('') + '</tbody></table>';
      box.querySelectorAll('[data-cancel]').forEach(function (b) {
        b.addEventListener('click', function () {
          if (!confirm('Cancel this scheduled campaign?')) return;
          fetch(API_BASE + '/campaigns/cancel.php' + qs, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ campaign_id: parseInt(b.getAttribute('data-cancel'), 10) })
          }).then(function () { loadList(); });
        });
      });
    });
  }

  // Calendar
  var calMonth = new Date();
  function renderCalendar() {
    var grid = el('cal-grid'); if (!grid) return;
    var label = el('cal-month-label');
    var y = calMonth.getFullYear(), m = calMonth.getMonth();
    label.textContent = calMonth.toLocaleString(undefined, { month: 'long', year: 'numeric' });
    var first = new Date(y, m, 1);
    var startDay = first.getDay();
    var daysInMonth = new Date(y, m + 1, 0).getDate();
    var today = new Date();
    var html = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].map(function (d) {
      return '<div class="siq-muted" style="text-align:center;font-size:11px;font-weight:600;">' + d + '</div>';
    }).join('');
    for (var i = 0; i < startDay; i++) html += '<div></div>';
    for (var d = 1; d <= daysInMonth; d++) {
      var isToday = (today.getFullYear() === y && today.getMonth() === m && today.getDate() === d);
      html += '<div class="siq-cal-cell ' + (isToday ? 'siq-cal-cell--today' : '') + '" data-day="' + d + '">' +
                '<div style="font-weight:600;">' + d + '</div>' +
              '</div>';
    }
    grid.innerHTML = html;

    fetch(API_BASE + '/campaigns/list.php' + qs).then(function (r) { return r.json(); }).then(function (data) {
      (data.campaigns || []).forEach(function (c) {
        if (!c.start_at) return;
        var dt = new Date(c.start_at.replace(' ', 'T'));
        if (dt.getFullYear() === y && dt.getMonth() === m) {
          var cell = grid.querySelector('[data-day="' + dt.getDate() + '"]');
          if (cell) {
            var ev = document.createElement('span');
            ev.className = 'siq-cal-event';
            ev.title = c.name;
            ev.textContent = c.name;
            cell.appendChild(ev);
          }
        }
      });
    });
  }
  if (el('cal-prev')) el('cal-prev').addEventListener('click', function () { calMonth.setMonth(calMonth.getMonth() - 1); renderCalendar(); });
  if (el('cal-next')) el('cal-next').addEventListener('click', function () { calMonth.setMonth(calMonth.getMonth() + 1); renderCalendar(); });

  // New campaign — minimal 3-step inline flow
  var modal = el('cmp-modal');
  var modalBody = el('cmp-modal-body');
  function closeModal() { modal.classList.remove('is-open'); modal.setAttribute('aria-hidden', 'true'); }
  if (el('cmp-modal-close')) el('cmp-modal-close').addEventListener('click', closeModal);
  if (modal) modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });

  var draft = { name: '', start_at: '', end_at: '', auto_revert: true, filter: {}, actions: [], template_id: null };

  function openNew() {
    modal.classList.add('is-open'); modal.setAttribute('aria-hidden', 'false');
    el('cmp-modal-step').textContent = 'Step 1 of 3 — pick a template';
    modalBody.textContent = 'Loading…';
    fetch(API_BASE + '/campaigns/templates.php' + qs).then(function (r) { return r.json(); }).then(function (d) {
      var groups = d.groups || {};
      var html = '<div class="siq-grid-3">';
      Object.keys(groups).forEach(function (cat) {
        groups[cat].forEach(function (t) {
          html += '<div class="siq-card" style="margin:0;padding:14px;">' +
            '<div class="siq-muted" style="font-size:11px;text-transform:uppercase;">' + escape(cat) + '</div>' +
            '<b>' + escape(t.name) + '</b>' +
            '<div class="siq-muted siq-mt-16" style="font-size:12px;">' + escape(t.description || '') + '</div>' +
            '<button class="btn btn-primary btn-sm siq-mt-16" data-tpl-id="' + t.id + '" data-tpl="' + escape(t.actions_json) + '" data-name="' + escape(t.name) + '" data-duration="' + (t.suggested_duration_days || 3) + '" type="button">Use this</button>' +
          '</div>';
        });
      });
      html += '</div><div class="siq-mt-24"><button class="btn btn-ghost btn-sm" id="cmp-from-scratch" type="button">Start from scratch instead</button></div>';
      modalBody.innerHTML = html;
      modalBody.querySelectorAll('[data-tpl-id]').forEach(function (b) {
        b.addEventListener('click', function () {
          draft.template_id = parseInt(b.getAttribute('data-tpl-id'), 10);
          draft.name = b.getAttribute('data-name');
          try { draft.actions = JSON.parse(b.getAttribute('data-tpl') || '[]'); } catch (e) { draft.actions = []; }
          var dur = parseInt(b.getAttribute('data-duration'), 10) || 3;
          var start = new Date(); start.setDate(start.getDate() + 1); start.setHours(0,0,0,0);
          var end = new Date(start); end.setDate(end.getDate() + dur);
          draft.start_at = formatDt(start); draft.end_at = formatDt(end);
          step2();
        });
      });
      var fs = el('cmp-from-scratch');
      if (fs) fs.addEventListener('click', function () {
        draft = { name: '', start_at: '', end_at: '', auto_revert: true, filter: {}, actions: [], template_id: null };
        step2();
      });
    });
  }
  function formatDt(d) {
    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':00';
  }
  function step2() {
    el('cmp-modal-step').textContent = 'Step 2 of 3 — configure';
    modalBody.innerHTML =
      '<div class="siq-form-row"><label class="siq-label">Name</label><input class="siq-input" id="c-name" value="' + escape(draft.name) + '"></div>' +
      '<div class="siq-grid-2">' +
        '<div class="siq-form-row"><label class="siq-label">Start at</label><input class="siq-input" id="c-start" value="' + escape(draft.start_at) + '" placeholder="YYYY-MM-DD HH:MM:SS"></div>' +
        '<div class="siq-form-row"><label class="siq-label">End at</label><input class="siq-input" id="c-end" value="' + escape(draft.end_at) + '" placeholder="YYYY-MM-DD HH:MM:SS"></div>' +
      '</div>' +
      '<label class="siq-flex"><input type="checkbox" id="c-revert" ' + (draft.auto_revert ? 'checked' : '') + '> <span>Auto-revert at end</span></label>' +
      '<div class="siq-mt-24"><button class="btn btn-primary" id="c-step3" type="button">Review</button></div>';
    el('c-step3').addEventListener('click', function () {
      draft.name = el('c-name').value.trim();
      draft.start_at = el('c-start').value.trim();
      draft.end_at = el('c-end').value.trim();
      draft.auto_revert = !!el('c-revert').checked;
      step3();
    });
  }
  function step3() {
    el('cmp-modal-step').textContent = 'Step 3 of 3 — review';
    modalBody.innerHTML =
      '<div class="siq-card" style="margin:0;">' +
        '<b>' + escape(draft.name) + '</b>' +
        '<div class="siq-muted siq-mt-16">Starts ' + escape(draft.start_at) + (draft.end_at ? ' · ends ' + escape(draft.end_at) : '') + '</div>' +
        '<div class="siq-mt-16">Auto-revert: <b>' + (draft.auto_revert ? 'Yes' : 'No') + '</b></div>' +
        '<div class="siq-mt-16">Actions: <code>' + escape(JSON.stringify(draft.actions)) + '</code></div>' +
      '</div>' +
      '<div class="siq-mt-24"><button class="btn btn-primary" id="c-submit" type="button">Schedule campaign</button></div>';
    el('c-submit').addEventListener('click', function () {
      fetch(API_BASE + '/campaigns/create.php' + qs, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(draft)
      }).then(function (r) { return r.json(); }).then(function (d) {
        if (d.success) { closeModal(); loadList(); renderCalendar(); }
        else alert('Error: ' + (d.error || 'unknown'));
      });
    });
  }

  if (el('new-campaign-btn')) el('new-campaign-btn').addEventListener('click', openNew);

  (window.__siqReady || Promise.resolve()).then(function () {
    loadList();
    renderCalendar();
  });
})();
