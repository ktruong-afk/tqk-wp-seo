'use strict';
/* TQK WP SEO — AI Advisor runner.
 *
 * The server decides pacing (RateLimiter): each 'step' makes at most one
 * Gemini request and answers either with new suggestions or "wait N s".
 * This file only waits as told and asks again, so two tabs or a reload can
 * never push the account past its limits.
 */
(function () {
  var root = document.getElementById('tqk-ai');
  var dataEl = document.getElementById('tqk-ai-data');
  if (!root || !dataEl) return;

  var DATA;
  try { DATA = JSON.parse(dataEl.textContent || '{}'); } catch (e) { DATA = {}; }
  var T = DATA.t || {};
  var state = DATA.state || null;
  var $ = function (id) { return document.getElementById(id); };
  var startBtn = $('tqk-ai-start'), contBtn = $('tqk-ai-continue'), retryBtn = $('tqk-ai-retry'), stopBtn = $('tqk-ai-stop');
  var status = $('tqk-ai-status'), fill = $('tqk-ai-fill'), rows = $('tqk-ai-rows');
  var running = false, stopAsked = false, timer = null;

  function fmt(s) {
    var a = Array.prototype.slice.call(arguments, 1), n = 0;
    return String(s || '').replace(/%(\d)\$[sd]|%[sd]/g, function (m, pos) { return String(pos ? a[parseInt(pos, 10) - 1] : a[n++]); });
  }
  function progress(s) {
    return fmt(T.progress, s.done + s.failed, s.total, s.failed, s.calls, s.cached, s.used_today, s.limit_today);
  }
  function paint() {
    startBtn.hidden = running;
    stopBtn.hidden = !running;
    contBtn.hidden = running || !state || state.pending === 0;
    retryBtn.hidden = running || !state || state.failed === 0;
    if (!state) return;
    fill.style.width = (state.total ? Math.round(((state.done + state.failed) / state.total) * 100) : 0) + '%';
    if (!running) status.textContent = progress(state) + (state.error ? ' — ' + state.error : '');
  }

  function post(op, extra) {
    var fd = new FormData();
    fd.append('action', 'tqk_wpseo_ai_advisor');
    fd.append('tqk_action', 'ai_advisor');
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
        if (typeof j !== 'object' || j === null) return { success: false, data: { message: T.expired } };
        return j;
      });
  }

  function countdown(sec, daily, then) {
    if (daily) {
      var at = new Date(Date.now() + sec * 1000);
      status.textContent = progress(state) + ' — ' + fmt(T.daily, at.toLocaleString());
      running = false;
      paint();
      return;
    }
    var left = sec;
    (function tick() {
      if (stopAsked) { finish(T.stopped); return; }
      status.textContent = progress(state) + ' — ' + fmt(T.waiting, left);
      if (left <= 0) { then(); return; }
      left--;
      timer = setTimeout(tick, 1000);
    })();
  }

  function step() {
    if (stopAsked) { finish(T.stopped); return; }
    status.textContent = progress(state) + ' — ' + (T.working || '…');
    post('step').then(function (res) {
      if (!res.success) { finish(res.data && res.data.message); return; }
      var d = res.data;
      state = d.state;
      if (d.rows) rows.insertAdjacentHTML('afterbegin', d.rows);
      paint();
      if (d.fatal) { finish(d.fatal); return; }
      if (d.wait > 0) { countdown(d.wait, d.daily, step); return; }
      if (state.pending === 0) { finish(T.finished); return; }
      step();
    }).catch(function () {
      // Network or server hiccup: back off, the server keeps the progress.
      countdown(30, false, step);
    });
  }

  function begin(op) {
    if (running) return;
    running = true;
    stopAsked = false;
    paint();
    post(op).then(function (res) {
      if (!res.success) { finish(res.data && res.data.message); return; }
      state = res.data.state;
      if (op === 'start') rows.innerHTML = '';
      paint();
      step();
    }).catch(function (e) { finish(String(e && e.message || e)); });
  }

  function finish(msg) {
    running = false;
    clearTimeout(timer);
    paint();
    if (msg) status.textContent = (state ? progress(state) + ' — ' : '') + msg;
  }

  startBtn.addEventListener('click', function () { begin('start'); });
  contBtn.addEventListener('click', function () { running = true; stopAsked = false; paint(); step(); });
  retryBtn.addEventListener('click', function () { begin('retry'); });
  stopBtn.addEventListener('click', function () { stopAsked = true; });

  // Apply: only the ticked values of one page.
  rows.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('[data-ai-apply]') : null;
    if (!btn) return;
    var card = btn.closest('.tqk-ai-card');
    var pick = { alts: [], schema: [] };
    Array.prototype.forEach.call(card.querySelectorAll('input[type=checkbox]:checked'), function (c) {
      if (c.hasAttribute('data-pick')) pick[c.getAttribute('data-pick')] = true;
      if (c.hasAttribute('data-pick-alt')) pick.alts.push(c.getAttribute('data-pick-alt'));
      if (c.hasAttribute('data-pick-schema')) pick.schema.push(c.getAttribute('data-pick-schema'));
    });
    btn.disabled = true;
    post('apply', { post_id: card.getAttribute('data-post'), pick: JSON.stringify(pick) }).then(function (res) {
      btn.disabled = false;
      btn.textContent = res.success ? (res.data.message || T.applied) : (res.data && res.data.message) || '✗';
      if (res.success) card.classList.add('is-applied');
    }, function () { btn.disabled = false; });
  });

  window.addEventListener('beforeunload', function (e) {
    if (!running) return undefined;
    e.preventDefault();
    e.returnValue = '';
    return '';
  });

  paint();
})();
