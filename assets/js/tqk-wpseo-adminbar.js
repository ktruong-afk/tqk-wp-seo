'use strict';
/* TQK WP SEO — "Audit this page" from the WordPress toolbar (front end).
 *
 * Same engine as Live Audit and the editor Scan: the page is opened in the
 * visible stage at 4 screen sizes, snapshots are taken, the result is stored
 * as the page's report (posts and pages), then the action plan opens in a
 * side drawer. CSS fixes can be copied here; the other fixes open the editor.
 */
(function () {
  var C = window.tqkWpseoBar;
  if (!C || !window.TQKResponsive || !window.TQKPlan) return;
  var T = C.t || {};
  // wp_localize_script sends numbers as strings: "0" must mean "no post".
  C.postId = parseInt(C.postId, 10) || 0;

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }
  function fmt(s) {
    var a = Array.prototype.slice.call(arguments, 1), i = 0;
    return String(s || '')
      .replace(/%(\d)\$[sd]/g, function (m, n) { return a[n - 1]; })
      .replace(/%[sd]/g, function () { return a[i++]; });
  }
  function post(action, data) {
    var body = new FormData();
    body.append('action', action);
    body.append('_ajax_nonce', C.nonce || '');
    body.append('post_id', String(C.postId || 0));
    Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
    return fetch(C.ajax, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json(); });
  }

  var toastEl = null, toastTimer = null;
  function toast(msg, tone) {
    if (!toastEl) {
      toastEl = document.createElement('div');
      toastEl.setAttribute('role', 'status');
      document.body.appendChild(toastEl);
    }
    toastEl.className = 'tqk-toast is-' + (tone || 'good');
    toastEl.innerHTML = '<span class="tqk-toast-ico">' + (tone === 'bad' ? '✕' : (tone === 'warn' ? '!' : '✓')) + '</span><span class="tqk-toast-msg"></span>';
    toastEl.querySelector('.tqk-toast-msg').textContent = msg;
    requestAnimationFrame(function () { toastEl.classList.add('is-on'); });
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { toastEl.classList.remove('is-on'); }, tone === 'good' ? 3200 : 6000);
  }
  function copyText(txt) {
    if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(txt);
    return new Promise(function (ok, no) {
      var ta = document.createElement('textarea');
      ta.value = txt;
      ta.style.cssText = 'position:fixed;left:-9999px;top:0';
      document.body.appendChild(ta);
      ta.select();
      var done = false;
      try { done = document.execCommand('copy'); } catch (e) { done = false; }
      document.body.removeChild(ta);
      if (done) ok(); else no();
    });
  }

  /* ---------- side drawer with the plan ---------- */
  var drawer = null;
  function openDrawer(html) {
    if (!drawer) {
      drawer = document.createElement('aside');
      drawer.className = 'tqk-drawer';
      drawer.setAttribute('aria-label', T.barTitle || 'TQK SEO');
      drawer.innerHTML = '<div class="tqk-drawer-head"><strong>' + esc(T.barTitle) + '</strong>'
        + (C.editUrl ? '<a class="tqk-drawer-edit" href="' + esc(C.editUrl) + '">' + esc(T.barOpenEditor) + '</a>' : '')
        + '<button type="button" class="tqk-drawer-close" aria-label="' + esc(T.barClose) + '">×</button></div>'
        + '<div class="tqk-drawer-body"></div>';
      document.body.appendChild(drawer);
      drawer.querySelector('.tqk-drawer-close').addEventListener('click', function () { drawer.classList.remove('is-open'); });
      drawer.addEventListener('click', function (e) {
        var fix = e.target.closest ? e.target.closest('.tqk-plan-fix') : null;
        if (!fix) return;
        if (fix.getAttribute('data-fix') === 'css') {
          copyText(fix.getAttribute('data-css') || '').then(function () { toast(T.toastCss || 'Copied', 'good'); }, function () { toast(T.toastCssFail || '', 'warn'); });
        } else if (C.editUrl) {
          window.open(C.editUrl, '_blank');
        }
      });
    }
    drawer.querySelector('.tqk-drawer-body').innerHTML = html;
    requestAnimationFrame(function () { drawer.classList.add('is-open'); });
  }

  function screens(rep) {
    return '<div class="tqk-report-row"><strong>' + esc(T.scanScreens) + '</strong><span class="tqk-report-vps">'
      + (rep.viewports || []).map(function (v) {
        var tone = v.error ? 'bad' : (v.score >= 90 ? 'good' : (v.score >= 70 ? 'warn' : 'bad'));
        return '<span class="tqk-scan-vp is-' + tone + '">' + esc(v.label) + ' · ' + (v.error ? '×' : '<b>' + esc(v.score) + '</b>/100') + '</span>';
      }).join('') + '</span></div>';
  }
  function shotsHtml(map) {
    var h = '';
    Object.keys(map || {}).forEach(function (k) {
      h += '<a class="tqk-shot" href="' + esc(map[k]) + '" target="_blank" rel="noopener"><img src="' + esc(map[k]) + '" alt=""><span>' + esc(k) + '</span></a>';
    });
    return h ? '<div class="tqk-report-block"><div class="tqk-shots">' + h + '</div></div>' : '';
  }
  function render(rep, srv, sec, note) {
    var plan = window.TQKPlan.build(rep, { images: srv ? srv.images : null, server: srv ? srv.server : null, security: sec }, T.plan || {});
    openDrawer('<div class="tqk-wpseo-metabox tqk-drawer-inner">'
      + (note ? '<p class="description tqk-drawer-note">' + esc(note) + '</p>' : '')
      + window.TQKPlan.html(plan, T.plan || {})
      + screens(rep)
      + shotsHtml(srv ? srv.shots : null)
      + '</div>');
  }

  /* ---------- the audit ---------- */
  var stage = null, running = null;
  function mainHtml(doc) {
    var copy = doc.documentElement.cloneNode(true);
    Array.prototype.forEach.call(copy.querySelectorAll('header, footer, nav, aside, script, style, noscript, template, svg, iframe, #wpadminbar'), function (n) {
      if (n.parentNode) n.parentNode.removeChild(n);
    });
    var pick = copy.querySelector('main') || copy.querySelector('article') || copy.querySelector('#content') || copy.querySelector('body') || copy;
    var html = pick.outerHTML;
    if (html.length > 390000) html = html.replace(/\s(style|class|data-[\w-]+)="[^"]*"/g, '');
    return html.slice(0, 390000);
  }

  function audit() {
    if (running) return;
    if (!stage && window.TQKStage) {
      stage = window.TQKStage.create({ t: T.stage || {} });
      stage.onStop(function () {
        if (running) { running.cancel(); running = null; }
        stage.close();
        toast(T.scanStopped || '', 'warn');
      });
    }
    var url = location.href.split('#')[0];
    var html = '';
    if (stage) { stage.label(document.title); stage.open(); stage.panel(false); }
    running = window.TQKResponsive.run(url, {
      stage: stage ? stage.view : null,
      scrollDelay: 300,
      shots: window.TQKSnapshot ? ['desktop', 'laptop', 'tablet', 'mobile'] : [],
      t: T.seo || {},
      timeout: 30,
      onDoc: function (win, doc, key) { if (key === 'desktop') html = mainHtml(doc); return null; }
    }, function (step) {
      if (!stage) return;
      if (step.phase === 'load') stage.status(fmt(T.scanLoad || '%s', step.viewport.label) + ' (' + (step.index + 1) + '/' + step.total + ')');
      if (step.phase === 'snapshot') stage.status(fmt(T.scanShot || '%s', step.viewport.label));
    }, function (rep) {
      running = null;
      if (stage) stage.close();
      if (!html) { toast(fmt(T.scanFail || '%s', rep.error || '?'), 'bad'); return; }
      if (!C.postId) {
        render(rep, null, null, T.barNotStored);
        return;
      }
      render(rep, null, null, T.scanSaving);
      var ok = (rep.viewports || []).filter(function (v) { return !v.error; });
      var data = {
        html: html,
        images: JSON.stringify(window.TQKResponsive.mergeImages ? window.TQKResponsive.mergeImages(ok) : []),
        report: JSON.stringify(window.TQKPlan.agentReport(rep, { source: 'toolbar' }, T.plan || {}))
      };
      Object.keys(rep.shots || {}).forEach(function (k) { data['shot_' + k] = rep.shots[k]; });
      post('tqk_wpseo_scan_result', data).then(function (res) {
        if (!res || !res.success) throw new Error((res && res.data && res.data.message) || 'error');
        var srv = res.data;
        var badge = document.querySelector('#wp-admin-bar-tqk-wpseo .tqk-ab-score');
        if (badge) {
          badge.textContent = srv.score;
          badge.className = 'tqk-ab-score ' + (srv.score >= 80 ? 'is-good' : (srv.score >= 50 ? 'is-ok' : 'is-bad'));
        }
        render(rep, srv, null, T.secRunning);
        return post('tqk_wpseo_page_security', {}).then(function (r2) {
          render(rep, srv, r2 && r2.success ? r2.data : null, T.barSaved);
          toast(T.barSaved || '', 'good');
        }, function () { render(rep, srv, null, T.barSaved); });
      }).catch(function (e) {
        toast(fmt(T.scanFail || '%s', e.message), 'bad');
      });
    });
  }

  document.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('#wp-admin-bar-tqk-wpseo-audit a, #wp-admin-bar-tqk-wpseo-last a') : null;
    if (!a) return;
    e.preventDefault();
    if (a.closest('#wp-admin-bar-tqk-wpseo-audit')) { audit(); return; }
    if (C.last) {
      openDrawer('<div class="tqk-wpseo-metabox tqk-drawer-inner">' + window.TQKPlan.stored(C.last, T.plan || {}) + shotsHtml(C.last.shots) + '</div>');
    }
  });
})();
