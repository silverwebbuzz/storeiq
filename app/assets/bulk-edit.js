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

  // Tabs
  document.querySelectorAll('.siq-tab').forEach(function (t) {
    t.addEventListener('click', function () {
      document.querySelectorAll('.siq-tab').forEach(function (x) { x.classList.remove('active'); });
      t.classList.add('active');
      var name = t.getAttribute('data-tab');
      document.querySelectorAll('[data-tab-pane]').forEach(function (p) {
        p.style.display = (p.getAttribute('data-tab-pane') === name) ? '' : 'none';
      });
      if (name === 'history') loadHistory();
    });
  });

  var actions = [];
  function renderActions() {
    var box = el('be-actions-list');
    if (!actions.length) { box.innerHTML = '<div class="siq-empty">No actions yet.</div>'; return; }
    box.innerHTML = actions.map(function (a, i) {
      return '<div class="siq-flex-between" style="padding:8px 0;border-bottom:1px solid #f3f4f6;">' +
        '<div><b>' + escape(a.action_type) + '</b> <span class="siq-muted">' + escape(JSON.stringify(a.params || {})) + '</span></div>' +
        '<button class="btn btn-ghost btn-sm" data-rm-action="' + i + '" type="button">Remove</button>' +
      '</div>';
    }).join('');
    box.querySelectorAll('[data-rm-action]').forEach(function (b) {
      b.addEventListener('click', function () {
        actions.splice(parseInt(b.getAttribute('data-rm-action'), 10), 1);
        renderActions();
      });
    });
  }

  // Preview
  var previewBtn = el('be-preview-btn');
  if (previewBtn) {
    previewBtn.addEventListener('click', function () {
      var scope = el('be-filter-scope').value;
      var value = el('be-filter-value').value;
      el('be-preview-result').textContent = 'Counting…';
      fetch('api/bulk-edit/preview.php' + qs + '&scope=' + encodeURIComponent(scope) + '&value=' + encodeURIComponent(value))
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d.error) { el('be-preview-result').textContent = 'Error: ' + d.error; return; }
          el('be-preview-result').innerHTML =
            '<b>' + (d.count || 0) + '</b> products match.' +
            (d.exceeds_limit ? ' <span style="color:#b91c1c;">Exceeds plan limit (' + d.plan_limit + ').</span>' : '');
        });
    });
  }

  // Add action — opens template picker
  var addBtn = el('be-add-action');
  var modal = el('be-template-modal');
  var modalList = el('be-template-list');
  function closeModal() { modal.classList.remove('is-open'); modal.setAttribute('aria-hidden', 'true'); }
  if (el('be-template-close')) el('be-template-close').addEventListener('click', closeModal);
  if (modal) modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
  if (addBtn) {
    addBtn.addEventListener('click', function () {
      modal.classList.add('is-open'); modal.setAttribute('aria-hidden', 'false');
      modalList.textContent = 'Loading…';
      fetch('api/bulk-edit/templates.php' + qs)
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d.groups) { modalList.textContent = 'No templates available.'; return; }
          modalList.innerHTML = Object.keys(d.groups).map(function (cat) {
            return '<h4>' + escape(cat) + '</h4>' +
              '<div class="siq-grid-3">' + d.groups[cat].map(function (t) {
                return '<div class="siq-card" style="margin:0;padding:14px;">' +
                  '<b>' + escape(t.name) + '</b>' +
                  '<div class="siq-muted siq-mt-16" style="font-size:12px;">' + escape(t.description || '') + '</div>' +
                  '<button class="btn btn-primary btn-sm siq-mt-16" data-tpl="' + escape(t.actions_json) + '" type="button">Use this</button>' +
                '</div>';
              }).join('') + '</div>';
          }).join('');
          modalList.querySelectorAll('[data-tpl]').forEach(function (b) {
            b.addEventListener('click', function () {
              try {
                var arr = JSON.parse(b.getAttribute('data-tpl') || '[]');
                if (Array.isArray(arr)) {
                  arr.forEach(function (a) { actions.push({ action_type: a.action_type, params: a.params || {} }); });
                  renderActions();
                }
              } catch (e) {}
              closeModal();
            });
          });
        });
    });
  }

  // Run now
  var runBtn = el('be-run-now');
  if (runBtn) {
    runBtn.addEventListener('click', function () {
      var name = el('be-job-name').value.trim();
      if (!name || !actions.length) { alert('Add a name and at least one action.'); return; }
      var filter = {
        scope: el('be-filter-scope').value,
        value: el('be-filter-value').value
      };
      runBtn.disabled = true;
      fetch('api/bulk-edit/create.php' + qs, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name: name, filter: filter, actions: actions })
      }).then(function (r) { return r.json(); })
        .then(function (d) {
          runBtn.disabled = false;
          if (d.success) {
            alert('Job queued (#' + d.job_id + ')');
            actions = []; renderActions(); el('be-job-name').value = '';
          } else {
            alert('Error: ' + (d.error || 'unknown'));
          }
        })
        .catch(function () { runBtn.disabled = false; });
    });
  }

  // History
  function loadHistory() {
    var box = el('be-history');
    box.textContent = 'Loading…';
    fetch('api/bulk-edit/list.php' + qs)
      .then(function (r) { return r.json(); })
      .then(function (d) {
        var rows = d.jobs || [];
        if (!rows.length) { box.innerHTML = '<div class="siq-empty">No jobs yet.</div>'; return; }
        box.innerHTML = '<table class="siq-table"><thead><tr><th>Name</th><th>Status</th><th>Products</th><th>Created</th><th></th></tr></thead><tbody>' +
          rows.map(function (j) {
            var status = '<span class="siq-badge siq-badge--' + j.status + '">' + j.status + '</span>';
            return '<tr><td>' + escape(j.name) + '</td><td>' + status + '</td>' +
                   '<td>' + (j.processed || 0) + '/' + (j.total_products || 0) + '</td>' +
                   '<td class="siq-muted">' + escape(j.created_at) + '</td>' +
                   '<td>' + (j.status === 'completed' ? '<button class="btn btn-ghost btn-sm" data-undo="' + j.id + '" type="button">Undo</button>' : '') + '</td>' +
                   '</tr>';
          }).join('') + '</tbody></table>';
        box.querySelectorAll('[data-undo]').forEach(function (b) {
          b.addEventListener('click', function () {
            if (!confirm('Roll back this bulk edit?')) return;
            fetch('api/bulk-edit/undo.php' + qs, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ job_id: parseInt(b.getAttribute('data-undo'), 10) })
            }).then(function (r) { return r.json(); }).then(function (d) {
              alert(d.success ? 'Rollback queued.' : 'Error: ' + (d.error || 'unknown'));
              loadHistory();
            });
          });
        });
      });
  }
})();
