'use strict';
/* TQK WP SEO — scanner progress: start -> poll step -> inject report, no reload. */
(function () {
  var box = document.getElementById('tqk-wpseo-scan');
  if (!box || typeof ajaxurl === 'undefined') return;
  var btn = document.getElementById('tqk-wpseo-scan-start');
  var fill = document.getElementById('tqk-wpseo-scan-fill');
  var label = document.getElementById('tqk-wpseo-scan-label');
  var latest = document.getElementById('tqk-wpseo-latest');
  var nonce = box.getAttribute('data-nonce') || '';
  var adminNonce = (window.tqkWpseoAjax && window.tqkWpseoAjax.nonce) || '';

  function call(action, fields) {
    var body = new FormData();
    Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
    return fetch(ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); });
  }
  function scanCall(op, extra) {
    var body = new FormData();
    body.append('action', 'tqk_wpseo_scan');
    body.append('op', op);
    body.append('_ajax_nonce', nonce);
    Object.keys(extra || {}).forEach(function (k) { body.append(k, extra[k]); });
    return fetch(ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); });
  }
  function paint(job) {
    var total = job.total || 0;
    var done = job.done || 0;
    var pct = total > 0 ? Math.round((100 * done) / total) : 0;
    fill.style.width = pct + '%';
    label.textContent = done + ' / ' + total;
  }
  function finish() {
    var body = new FormData();
    body.append('action', 'tqk_wpseo_report');
    body.append('tqk_action', 'report');
    body.append('_ajax_nonce', adminNonce);
    fetch(ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res && res.success && latest) latest.innerHTML = (res.data && res.data.html) || '';
        btn.disabled = false;
      })
      .catch(function () { btn.disabled = false; });
  }
  function loop() {
    scanCall('step').then(function (res) {
      var job = (res && res.data) || {};
      paint(job);
      if (job.status === 'running') {
        loop();
      } else {
        finish();
      }
    }).catch(function () {
      label.textContent = 'error';
    });
  }
  btn.addEventListener('click', function () {
    btn.disabled = true;
    var mode = 'full';
    var picked = document.querySelector('input[name="tqk-scan-mode"]:checked');
    if (picked) mode = picked.value === 'sample' ? 'sample' : 'full';
    scanCall('start', { mode: mode }).then(function (res) {
      paint((res && res.data) || {});
      loop();
    });
  });
  if (box.getAttribute('data-running') === '1') {
    btn.disabled = true;
    loop();
  }

  /* ---------- page audit: fetch bot view, inject report ---------- */
  var auditBtn = document.getElementById('tqk-audit-run');
  var auditPick = document.getElementById('tqk-audit-pick');
  var auditRes = document.getElementById('tqk-audit-result');
  if (auditBtn && auditPick && auditRes) {
    auditBtn.addEventListener('click', function () {
      auditBtn.disabled = true;
      auditRes.innerHTML = '<p>…</p>';
      scanCall('audit', { url: auditPick.value }).then(function (res) {
        auditBtn.disabled = false;
        if (res && res.success) {
          auditRes.innerHTML = (res.data && res.data.html) || '';
        } else {
          auditRes.innerHTML = '<div class="notice notice-error"><p>' + ((res && res.data && res.data.message) || 'error') + '</p></div>';
        }
      }).catch(function () {
        auditBtn.disabled = false;
        auditRes.innerHTML = '<div class="notice notice-error"><p>error</p></div>';
      });
    });
  }
})();
