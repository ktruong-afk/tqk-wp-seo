'use strict';
/* TQK WP SEO — Tools runners: Yoast / Rank Math importer and security scan.
 * Both are step loops over admin-ajax: the server does one small batch per
 * request, so shared hosting never hits a timeout. */
(function () {
  var NONCE = (window.tqkWpseoAjax && window.tqkWpseoAjax.nonce) || '';
  var AJAX = window.ajaxurl || '/wp-admin/admin-ajax.php';

  function post(action, data) {
    var fd = new FormData();
    fd.append('action', 'tqk_wpseo_' + action);
    fd.append('tqk_action', action);
    fd.append('_ajax_nonce', NONCE);
    Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(AJAX, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  /* ---------- importer ---------- */
  var imp = document.getElementById('tqk-import');
  if (imp) {
    var T = JSON.parse(imp.getAttribute('data-t') || '{}');
    var src = imp.querySelector('[name=tqk-import-source]');
    var out = imp.querySelector('.tqk-import-out');
    var status = imp.querySelector('.tqk-import-status');
    var fill = imp.querySelector('.tqk-import-fill');
    var runBtn = imp.querySelector('[data-import=run]');
    var total = 0;

    imp.querySelector('[data-import=preview]').addEventListener('click', function () {
      status.textContent = '…';
      post('import', { op: 'preview', source: src.value }).then(function (res) {
        if (!res.success) { status.textContent = (res.data && res.data.message) || 'Error'; return; }
        total = res.data.count;
        status.textContent = T.found.replace('%1$d', res.data.count).replace('%2$d', res.data.redirects).replace('%3$d', res.data.redirects_skipped)
          + ' ' + T.terms.replace('%d', res.data.terms || 0);
        var h = '<table class="tqk-wpseo-table"><thead><tr><th>' + esc(T.post) + '</th><th>' + esc(T.values) + '</th></tr></thead><tbody>';
        res.data.rows.forEach(function (r) {
          var vals = Object.keys(r.values).map(function (k) {
            return '<code>' + esc(k.replace('_tqk_wpseo_', '')) + '</code> ' + esc(r.values[k]);
          }).join('<br>');
          h += '<tr><td>#' + r.id + ' ' + esc(r.title) + '</td><td><small>' + (vals || '—') + '</small></td></tr>';
        });
        out.innerHTML = h + '</tbody></table>';
        runBtn.disabled = total === 0 && res.data.redirects === 0 && !res.data.terms;
      });
    });

    runBtn.addEventListener('click', function () {
      runBtn.disabled = true;
      var sum = { posts: 0, written: 0, kept: 0, lossy: 0 };
      var overwrite = imp.querySelector('[name=tqk-import-overwrite]').checked ? '1' : '';
      var redirects = imp.querySelector('[name=tqk-import-redirects]').checked ? '1' : '';
      (function batch(offset) {
        post('import', { op: 'batch', source: src.value, offset: offset, overwrite: overwrite, redirects: redirects }).then(function (res) {
          if (!res.success) { status.textContent = (res.data && res.data.message) || 'Error'; runBtn.disabled = false; return; }
          var d = res.data;
          ['posts', 'written', 'kept', 'lossy'].forEach(function (k) { sum[k] += d[k] || 0; });
          fill.style.width = (total ? Math.min(100, Math.round(100 * d.next / total)) : 100) + '%';
          status.textContent = T.progress.replace('%1$d', sum.posts).replace('%2$d', total);
          if (!d.done) { batch(d.next); return; }
          var msg = T.done.replace('%1$d', sum.posts).replace('%2$d', sum.written).replace('%3$d', sum.kept);
          if (sum.lossy) msg += ' ' + T.lossy.replace('%d', sum.lossy);
          if (d.terms && d.terms.terms) msg += ' ' + T.termsDone.replace('%1$d', d.terms.terms).replace('%2$d', d.terms.written);
          if (d.redirects) msg += ' ' + T.redirects.replace('%1$d', d.redirects.imported).replace('%2$d', d.redirects.skipped);
          status.textContent = msg + ' ' + T.next;
          runBtn.disabled = false;
        }, function () { status.textContent = 'Network error — press Import again, filled fields are kept.'; runBtn.disabled = false; });
      })(0);
    });
  }

  /* ---------- sitemap: submit to Google Search Console + Bing ---------- */
  var smBtn = document.querySelector('[data-tqk-sitemap-submit]');
  if (smBtn) {
    smBtn.addEventListener('click', function () {
      var st = document.getElementById('tqk-sitemap-submit-status');
      var last = document.getElementById('tqk-sitemap-submit-last');
      smBtn.disabled = true;
      st.textContent = '…';
      post('sitemap_submit', {}).then(function (r) {
        smBtn.disabled = false;
        if (!r.success) { st.textContent = (r.data && r.data.message) || 'Error'; return; }
        st.textContent = r.data.message;
        last.innerHTML = '<ul class="tqk-live-findings">' + ['google', 'bing'].map(function (k) {
          var x = r.data[k] || {};
          return '<li class="' + (x.ok ? 'is-pass' : 'is-warn') + '">' + esc((k === 'google' ? 'Google' : 'Bing') + ': ' + (x.message || '')) + '</li>';
        }).join('') + '</ul>';
      }, function () { smBtn.disabled = false; st.textContent = 'Network error'; });
    });
  }

  /* ---------- license ---------- */
  var lic = document.getElementById('tqk-license');
  if (lic) {
    lic.addEventListener('click', function (e) {
      var b = e.target.closest ? e.target.closest('[data-license]') : null;
      if (!b) return;
      e.preventDefault();
      var msg = lic.querySelector('[data-license-msg]');
      b.disabled = true;
      post('license', { op: b.getAttribute('data-license'), key: (lic.querySelector('[data-license-key]') || {}).value || '' }).then(function (r) {
        b.disabled = false;
        msg.textContent = (r.data && r.data.message) || '';
        if (r.success) setTimeout(function () { location.reload(); }, 900);
      }, function () { b.disabled = false; });
    });
  }

  /* ---------- security scan ---------- */
  var sec = document.getElementById('tqk-security');
  if (sec) {
    var btn = sec.querySelector('[data-sec=start]');
    var st = sec.querySelector('.tqk-sec-status');
    var bar = sec.querySelector('.tqk-sec-fill');
    var res = sec.querySelector('.tqk-sec-result');
    var ST = JSON.parse(sec.getAttribute('data-t') || '{}');
    btn.addEventListener('click', function () {
      btn.disabled = true;
      res.innerHTML = '';
      st.textContent = ST.starting;
      (function step(op) {
        post('security', { op: op }).then(function (r) {
          if (!r.success) { st.textContent = (r.data && r.data.message) || 'Error'; btn.disabled = false; return; }
          var d = r.data;
          bar.style.width = (d.total ? Math.round(100 * d.i / d.total) : 0) + '%';
          st.textContent = ST.step.replace('%1$d', d.i).replace('%2$d', d.total) + ' — ' + d.task.replace(/^(\w+):/, '$1 · ');
          if (d.done) { st.textContent = ST.done; res.innerHTML = d.html; btn.disabled = false; return; }
          step('step');
        }, function () { setTimeout(function () { step('step'); }, 5000); });
      })('start');
    });
  }
})();

/* Copy buttons (dashboard donate card): the value goes to the clipboard,
   the button says so for a moment. */
(function () {
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('.tqk-copy[data-copy]') : null;
    if (!b) return;
    var txt = b.getAttribute('data-copy') || '';
    var done = function (ok) {
      var old = b.textContent;
      b.textContent = ok ? '✓' : '✗';
      setTimeout(function () { b.textContent = old; }, 1400);
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(txt).then(function () { done(true); }, function () { done(false); });
      return;
    }
    var ta = document.createElement('textarea');
    ta.value = txt;
    ta.style.cssText = 'position:fixed;left:-9999px;top:0';
    document.body.appendChild(ta);
    ta.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (err) { ok = false; }
    document.body.removeChild(ta);
    done(ok);
  });
})();
