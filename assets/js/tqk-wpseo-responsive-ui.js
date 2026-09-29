'use strict';
/* TQK WP SEO — Live Audit runner UI.
 *
 * The server holds the full list and a done/failed mark per page; this file
 * only walks it: take a batch of unfinished pages, audit each one in the
 * stage iframe (window.TQKResponsive), save it (report + snapshots), repeat.
 * Every page is saved the moment it finishes, so closing the tab, a crash or
 * a dropped connection loses at most the page that was in progress.
 */
(function () {
  var root = document.getElementById('tqk-la');
  var dataEl = document.getElementById('tqk-la-data');
  if (!root || !dataEl) return;

  var DATA;
  try { DATA = JSON.parse(dataEl.textContent || '{}'); } catch (e) { DATA = {}; }
  var T = DATA.t || {};
  var state = DATA.state || null;

  var $ = function (id) { return document.getElementById(id); };
  var startBtn = $('tqk-la-start');
  var contBtn = $('tqk-la-continue');
  var retryBtn = $('tqk-la-retry');
  var stopBtn = $('tqk-la-stop');
  var snapChk = $('tqk-la-snap');
  var secChk = $('tqk-la-sec');
  var hint = $('tqk-la-hint');
  var fill = $('tqk-la-fill');
  var status = $('tqk-la-status');
  // Shared visible stage (full screen or mini), see tqk-wpseo-stage.js.
  var stage = window.TQKStage ? window.TQKStage.create({ t: T.stage || {} }) : null;
  if (stage) stage.onStop(function () { stopAsked = true; stage.status(T.stopping || '…'); });
  var rows = $('tqk-la-rows');
  var pager = $('tqk-la-pager');
  var fmt = (window.TQKResponsive && window.TQKResponsive.fmt) || function (s) { return s; };

  var running = false;
  var stopAsked = false;
  var current = null;
  var wakeLock = null;
  var fatal = '';

  /* Scope table: pages (all or none) + per post type skip / 1 sample / all. */
  var pagesChk = $('tqk-la-pages');
  function scopeInputs() { return Array.prototype.slice.call(root.querySelectorAll('.tqk-la-scope input')); }
  function scope() {
    var c = {};
    root.querySelectorAll('.tqk-la-scope input[type="radio"]:checked').forEach(function (r) { c[r.getAttribute('data-type')] = r.value; });
    return c;
  }
  function total() {
    var n = pagesChk && pagesChk.checked ? parseInt(pagesChk.getAttribute('data-count') || '0', 10) : 0;
    root.querySelectorAll('.tqk-la-scope input[type="radio"]:checked').forEach(function (r) { n += parseInt(r.getAttribute('data-count') || '0', 10); });
    return n;
  }
  function refreshHint() {
    scopeInputs().forEach(function (i) { if (!i.closest('.is-locked')) i.disabled = running; });
    var n = total();
    // About 35 s per page for 4 screens, + 10 s with the malware check.
    var sec = n * (35 + (secChk && secChk.checked ? 10 : 0));
    var dur = sec < 3600 ? Math.max(1, Math.round(sec / 60)) + ' min' : (sec / 3600).toFixed(1) + ' h';
    hint.textContent = n > 30 ? fmt(T.scopeSlow || '%1$d · %2$s', n, dur) : fmt(T.scopeHint || '%d', n);
  }
  function scopeChanged() {
    // The malware check adds ~10 s a page: on for small runs, off for big ones.
    if (secChk) secChk.checked = total() <= 30;
    refreshHint();
  }
  function progressText(s) {
    return fmt(T.progress || '%1$d / %2$d', s.done + s.failed, s.total, s.failed, s.pending);
  }
  function paint() {
    var s = state;
    startBtn.disabled = running;
    snapChk.disabled = running;
    if (secChk) secChk.disabled = running;
    stopBtn.hidden = !running;
    contBtn.hidden = running || !s || s.pending === 0;
    retryBtn.hidden = running || !s || s.failed === 0;
    if (stage) { if (running) stage.open(); else stage.close(); }
    refreshHint();
    if (!s) return;
    var pct = s.total ? Math.round(((s.done + s.failed) / s.total) * 100) : 0;
    fill.style.width = pct + '%';
    if (!running) {
      status.textContent = s.pending === 0 ? fmt(T.finished || '%1$d %2$d', s.done, s.failed) : progressText(s) + ' — ' + (T.paused || '');
    }
  }

  /* POST with endless patient retry on network/5xx errors: a run of many
   * hours will meet a flaky connection or a server restart. A JSON error
   * answer from the plugin is final and is returned to the caller. */
  function post(op, extra) {
    var attempt = 0;
    function once() {
      var fd = new FormData();
      fd.append('action', 'tqk_wpseo_live_audit');
      fd.append('tqk_action', 'live_audit');
      fd.append('_ajax_nonce', DATA.nonce || '');
      fd.append('op', op);
      if (state && state.id) fd.append('run', state.id);
      Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });
      return fetch(DATA.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) {
          if (r.status >= 500) throw new Error('http ' + r.status);
          return r.json();
        })
        .then(function (j) {
          // WordPress answers -1 / 0 when the nonce or login expired: final.
          if (typeof j !== 'object' || j === null) {
            return { success: false, fatal: true, data: { message: T.expired || 'Session expired.' } };
          }
          return j;
        })
        .catch(function (e) {
          if (op !== 'save' && op !== 'next' && op !== 'retry') throw e;
          attempt++;
          var wait = Math.min(60, 5 * Math.pow(2, attempt - 1));
          status.textContent = fmt(T.netRetry || '%d', wait);
          return new Promise(function (res) { setTimeout(res, wait * 1000); }).then(once);
        });
    }
    return once();
  }

  function payload(rep) {
    return {
      seo: rep.seo || { score: 0, findings: [], schema: [] },
      error: rep.error ? (rep.error === 'blocked' ? T.blocked : (rep.error === 'timeout' ? fmt(T.noLoad || '%d', 30) : rep.error)) : '',
      vps: rep.viewports.map(function (m) {
        return {
          label: m.label, score: m.score, error: m.error || m.snapError || '',
          overflow: m.overflowX, tiny: m.tinyText.length, tap: m.smallTap.length,
          dims: m.missingDims.length, over: m.oversized.length
        };
      }),
      groups: rep.groups.slice(0, 8).map(function (g) {
        return { root: g.root, reason: g.reason, count: g.count, vps: (g.viewports || []).join(', ') };
      }),
      images: mergeImages(rep.viewports)
    };
  }

  /* One entry per image source, with its box at every viewport. */
  function mergeImages(vps) {
    return window.TQKResponsive.mergeImages(vps);
  }

  /* Audit one page; resolves with the raw engine report. A watchdog makes
   * sure one broken page can never freeze a long run. */
  function auditOne(item) {
    return new Promise(function (resolve) {
      var done = false;
      var watchdog = setTimeout(function () {
        if (done) return;
        done = true;
        if (current) current.cancel();
        resolve({ url: item.url, viewports: [], groups: [], seo: null, shots: {}, error: 'watchdog' });
      }, 5 * 60 * 1000);
      if (stage) stage.label(item.title || item.url);
      current = window.TQKResponsive.run(item.url, {
        stage: stage ? stage.view : null,
        scrollDelay: 220, // visible: scroll like a person reading
        shots: state.snap ? ['desktop', 'mobile'] : [],
        t: T.seo,
        timeout: 30
      }, function (step) {
        var name = item.title || item.url;
        if (step.phase === 'load') status.textContent = progressText(state) + ' — ' + fmt(T.auditing || '%1$s %2$s', name, step.viewport.label);
        if (step.phase === 'snapshot') status.textContent = progressText(state) + ' — ' + fmt(T.snapshot || '%s', step.viewport.label);
        if (stage) stage.status(status.textContent);
      }, function (rep) {
        if (done) return;
        done = true;
        clearTimeout(watchdog);
        resolve(rep);
      });
    });
  }

  function saveOne(item, rep) {
    var extra = {
      index: String(item.index),
      data: JSON.stringify(payload(rep)),
      failed: rep.error ? '1' : ''
    };
    if (!rep.error) {
      Object.keys(rep.shots || {}).forEach(function (k) { extra['shot_' + k] = rep.shots[k]; });
      // Same page report as the editor's Scan (shown there, read over MCP).
      if (window.TQKPlan) extra.report = JSON.stringify(window.TQKPlan.agentReport(rep, { source: 'site audit' }, T.plan || {}));
    }
    status.textContent = progressText(state) + ' — ' + (T.saving || '…');
    return post('save', extra).then(function (res) {
      if (res && res.fatal) { stopAsked = true; fatal = res.data.message; return; }
      if (res && res.data && res.data.state) state = res.data.state;
      if (res && res.success && res.data.row) rows.insertAdjacentHTML('afterbegin', res.data.row);
      paint();
      if (!rep.error && secChk && secChk.checked && item.id > 0) return security(item);
    });
  }

  /* Malware check of the page (server fetches it as visitor / from Google /
     as Googlebot). Kept in the page report; never blocks the run. */
  function security(item) {
    status.textContent = progressText(state) + ' — ' + (T.security || '…');
    if (stage) stage.status(status.textContent);
    var fd = new FormData();
    fd.append('action', 'tqk_wpseo_page_security');
    fd.append('_ajax_nonce', DATA.nonce || '');
    fd.append('post_id', String(item.id));
    return fetch(DATA.ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function () {}, function () {});
  }

  function walk(items) {
    var i = 0;
    function step() {
      if (stopAsked) return end(true);
      if (i >= items.length) {
        return post('next').then(function (res) {
          if (!res || !res.success) return end(false, res);
          state = res.data.state;
          if (!res.data.items.length) return end(false);
          return walk(res.data.items);
        });
      }
      var item = items[i++];
      return auditOne(item).then(function (rep) { return saveOne(item, rep); }).then(step);
    }
    return step();
  }

  function begin(op, extra) {
    if (running || !window.TQKResponsive) return;
    running = true;
    stopAsked = false;
    fatal = '';
    paint();
    status.textContent = T.start || '…';
    if (navigator.wakeLock && navigator.wakeLock.request) {
      navigator.wakeLock.request('screen').then(function (l) { wakeLock = l; }, function () { /* denied */ });
    }
    post(op, extra).then(function (res) {
      if (!res || !res.success) return end(false, res);
      state = res.data.state;
      if (op === 'start') rows.innerHTML = '';
      paint();
      if (!res.data.items.length) return end(false);
      return walk(res.data.items);
    }).catch(function (e) {
      end(false, { data: { message: String(e && e.message || e) } });
    });
  }

  function end(paused, res) {
    running = false;
    current = null;
    if (wakeLock) { try { wakeLock.release(); } catch (e) { /* ignore */ } wakeLock = null; }
    if (stage) stage.close();
    var after = paused ? post('pause').then(function (r) { if (r && r.success) state = r.data.state; }, function () {}) : Promise.resolve();
    return after.then(function () {
      paint();
      if (res && res.data && res.data.message) status.textContent = res.data.message;
      if (fatal) status.textContent = fatal;
    });
  }

  startBtn.addEventListener('click', function () {
    if (!total()) { status.textContent = T.needType || ''; return; }
    if (state && state.total && !window.confirm(T.confirmNew || 'Start a new run?')) return;
    begin('start', {
      mode: 'plan',
      plan: JSON.stringify(scope()),
      pages: pagesChk && pagesChk.checked ? '1' : '0',
      snap: snapChk.checked ? '1' : ''
    });
  });
  contBtn.addEventListener('click', function () { begin('next'); });
  retryBtn.addEventListener('click', function () { begin('retry'); });
  stopBtn.addEventListener('click', function () {
    stopAsked = true;
    stopBtn.disabled = true;
    status.textContent = T.stopping || '…';
    setTimeout(function () { stopBtn.disabled = false; }, 1500);
  });
  scopeInputs().forEach(function (i) { i.addEventListener('change', scopeChanged); });
  if (!running) scopeChanged();
  if (secChk) secChk.addEventListener('change', refreshHint);

  // Hidden tabs are throttled hard by the browser (timers, rendering, lazy
  // loading all pause): say it plainly instead of letting the run crawl.
  document.addEventListener('visibilitychange', function () {
    if (running && document.hidden) {
      document.title = '⏸ ' + document.title.replace(/^⏸ /, '');
      status.textContent = T.hiddenTab || '';
    } else {
      document.title = document.title.replace(/^⏸ /, '');
    }
  });
  window.addEventListener('beforeunload', function (e) {
    if (!running) return undefined;
    e.preventDefault();
    e.returnValue = T.leave || '';
    return T.leave || '';
  });

  // Report pager (server-sorted: failed first, then worst score).
  var pages = parseInt(pager.getAttribute('data-pages') || '0', 10);
  function renderPager(page, total) {
    if (total <= 1) { pager.innerHTML = ''; return; }
    var h = '';
    for (var p = 1; p <= total; p++) {
      h += '<button type="button" class="button button-small' + (p === page ? ' button-primary' : '') + '" data-page="' + p + '">' + p + '</button> ';
    }
    pager.innerHTML = h;
  }
  pager.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-page]') : null;
    if (!b || running) return;
    post('report', { page: b.getAttribute('data-page') }).then(function (res) {
      if (!res || !res.success) return;
      rows.innerHTML = res.data.html;
      renderPager(res.data.page, res.data.pages);
    });
  });
  renderPager(1, pages);

  /* Push to MCP: one page (row button) or every page with issues. */
  rows.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-push]') : null;
    if (!b) return;
    b.disabled = true;
    var fd = new FormData();
    fd.append('action', 'tqk_wpseo_mcp_push');
    fd.append('_ajax_nonce', DATA.nonce || '');
    fd.append('post_id', b.getAttribute('data-push'));
    fetch(DATA.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        b.textContent = res && res.success ? '✓ ' + (T.pushed || 'Sent') : ((res && res.data && res.data.message) || 'Error');
      }, function () { b.disabled = false; });
  });
  var pushAll = $('tqk-la-push-all');
  if (pushAll) {
    pushAll.addEventListener('click', function () {
      pushAll.disabled = true;
      post('mcp_push_all').then(function (res) {
        status.textContent = (res && res.data && res.data.message) || '';
        pushAll.disabled = false;
      }, function () { pushAll.disabled = false; });
    });
  }
  paint();
  if (state && state.pending > 0 && state.status === 'running') {
    // The previous session ended without pausing (tab closed / crash).
    status.textContent = progressText(state) + ' — ' + (T.paused || '');
  }
})();

/* Image optimizer buttons: generate / activate / deactivate / purge, per page. */
(function () {
  var box = document.getElementById('tqk-img');
  if (!box) return;
  box.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-img-op]') : null;
    if (!btn) return;
    var page = btn.closest('[data-page]');
    var op = btn.getAttribute('data-img-op');
    if (op === 'purge' && !window.confirm(btn.textContent + '?')) return;
    var fd = new FormData();
    fd.append('action', 'tqk_wpseo_img_fix');
    fd.append('tqk_action', 'img_fix');
    fd.append('_ajax_nonce', box.getAttribute('data-nonce') || '');
    fd.append('op', op);
    fd.append('page_id', page.getAttribute('data-page'));
    btn.disabled = true;
    var old = btn.textContent;
    btn.textContent = '…';
    fetch(box.getAttribute('data-ajax'), { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (res && res.success && res.data.html) {
          var wrap = document.createElement('div');
          wrap.innerHTML = res.data.html;
          var fresh = wrap.firstElementChild;
          fresh.open = true;
          page.parentNode.replaceChild(fresh, page);
          var st = fresh.querySelector('.tqk-img-state');
          if (st) st.textContent = res.data.message + ' — ' + st.textContent;
        } else {
          btn.disabled = false;
          btn.textContent = old;
          window.alert((res && res.data && res.data.message) || 'Error');
        }
      }, function () { btn.disabled = false; btn.textContent = old; });
  });
})();
