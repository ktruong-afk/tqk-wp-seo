'use strict';
/* TQK WP SEO — scan stage shared by the post editor Scan and Live Audit.
 *
 * The page being scanned is SHOWN, not hidden, for three reasons:
 *   1. browsers only run what is on screen: lazy images, scroll animations,
 *      IntersectionObserver content load for a visible frame, like for a visitor;
 *   2. hidden / tiny off-screen frames get throttled, a visible one keeps
 *      rendering at full speed (no frozen "ghost" frames on a laptop);
 *   3. the person sees what is being checked and can stop it.
 *
 * Two modes, remembered per browser: "full" (covers the screen, best on a
 * laptop) and "mini" (floating box, keep working meanwhile). Switching mid-scan
 * rescales the page being scanned.
 *
 * API: TQKStage.create({ t: {full, mini, stop, title} }) →
 *   { view, open(), close(), label(txt), status(txt), panel(show), onStop(fn), mode() }
 */
(function () {
  var KEY = 'tqk_wpseo_stage_mode';
  var css = ''
    + '.tqk-stage{position:fixed;z-index:100050;background:#0f172a;color:#e2e8f0;display:flex;flex-direction:column;box-shadow:0 20px 50px rgba(0,0,0,.45);font:13px/1.4 -apple-system,"Segoe UI",Roboto,sans-serif}'
    + '.tqk-stage[hidden]{display:none}'
    + '.tqk-stage.is-full{inset:0}'
    + '.tqk-stage.is-mini{right:16px;bottom:16px;width:360px;height:280px;border-radius:12px;overflow:hidden}'
    + '.tqk-stage-bar{display:flex;align-items:center;gap:10px;padding:8px 12px;background:#111827;flex-shrink:0}'
    + '.tqk-stage-title{font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:45%}'
    + '.tqk-stage-status{flex:1;color:#94a3b8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
    + '.tqk-stage-bar button{background:#1f2937;color:#e2e8f0;border:1px solid #374151;border-radius:6px;padding:4px 10px;cursor:pointer;font:inherit}'
    + '.tqk-stage-bar button:hover{background:#374151}'
    + '.tqk-stage-bar .tqk-stage-stop{border-color:#7f1d1d;color:#fecaca}'
    + '.tqk-stage-body{flex:1;display:flex;min-height:0}'
    + '.tqk-stage-view{position:relative;flex:1;overflow:hidden;background:#fff;pointer-events:none}'
    + '.tqk-stage-panel{width:380px;flex-shrink:0;overflow-y:auto;background:#111827;border-left:1px solid #1f2937;padding:10px 12px;box-sizing:border-box}'
    + '.tqk-stage-panel[hidden],.tqk-stage.is-mini .tqk-stage-panel{display:none}'
    + '@media (max-width:900px){.tqk-stage-panel{width:260px}}'
    + '.tqk-stage-view iframe{position:absolute;left:0;top:0;transform-origin:0 0}'
    + '.tqk-stage.is-mini .tqk-stage-title{max-width:30%}'
    + '@media (max-width:600px){.tqk-stage.is-mini{left:8px;right:8px;width:auto}}';

  function injectCss() {
    if (document.getElementById('tqk-stage-css')) return;
    var s = document.createElement('style');
    s.id = 'tqk-stage-css';
    s.textContent = css;
    document.head.appendChild(s);
  }

  function savedMode() {
    try { return localStorage.getItem(KEY) === 'mini' ? 'mini' : 'full'; } catch (e) { return 'full'; }
  }

  /* Fit every frame (sized at its real viewport) into the visible area. */
  function fit(view) {
    var W = view.clientWidth, H = view.clientHeight;
    Array.prototype.forEach.call(view.querySelectorAll('iframe'), function (f) {
      var fw = parseInt(f.style.width, 10) || W, fh = parseInt(f.style.height, 10) || H;
      var s = Math.min(W / fw, H / fh, 1);
      f.style.transform = 'scale(' + s + ')';
      f.style.left = Math.max(0, Math.round((W - fw * s) / 2)) + 'px';
    });
  }

  function create(opts) {
    opts = opts || {};
    var t = opts.t || {};
    injectCss();
    var el = document.createElement('div');
    el.className = 'tqk-stage';
    el.hidden = true;
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-label', t.title || 'Scan');
    el.innerHTML = '<div class="tqk-stage-bar"><span class="tqk-stage-title"></span><span class="tqk-stage-status" aria-live="polite"></span>'
      + '<button type="button" class="tqk-stage-mode"></button><button type="button" class="tqk-stage-stop"></button></div>'
      + '<div class="tqk-stage-body"><div class="tqk-stage-view"></div><div class="tqk-stage-panel" aria-live="polite" hidden></div></div>';
    document.body.appendChild(el);
    var view = el.querySelector('.tqk-stage-view');
    var panel = el.querySelector('.tqk-stage-panel');
    var modeBtn = el.querySelector('.tqk-stage-mode');
    var stopBtn = el.querySelector('.tqk-stage-stop');
    var stopFns = [];
    var mode = savedMode();

    function paintMode() {
      el.classList.toggle('is-full', mode === 'full');
      el.classList.toggle('is-mini', mode === 'mini');
      modeBtn.textContent = mode === 'full' ? (t.mini || 'Minimize') : (t.full || 'Full screen');
      fit(view);
    }
    modeBtn.addEventListener('click', function () {
      mode = mode === 'full' ? 'mini' : 'full';
      try { localStorage.setItem(KEY, mode); } catch (e) { /* private mode */ }
      paintMode();
    });
    stopBtn.textContent = t.stop || 'Stop';
    stopBtn.addEventListener('click', function () { stopFns.forEach(function (fn) { fn(); }); });

    // New frames (one per viewport) and mode switches are refitted.
    if (window.MutationObserver) {
      new MutationObserver(function () { fit(view); }).observe(view, { childList: true });
    }
    if (window.ResizeObserver) {
      new ResizeObserver(function () { fit(view); }).observe(view);
    }
    window.addEventListener('resize', function () { fit(view); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !el.hidden && mode === 'full') { mode = 'mini'; paintMode(); }
    });
    paintMode();

    return {
      view: view,
      open: function () { el.hidden = false; paintMode(); },
      close: function () { el.hidden = true; view.innerHTML = ''; panel.innerHTML = ''; panel.hidden = true; },
      /* Results column next to the page (full-screen mode only). */
      panel: function (show) {
        if (show !== undefined) panel.hidden = !show;
        fit(view);
        return panel;
      },
      label: function (txt) { el.querySelector('.tqk-stage-title').textContent = txt || ''; },
      status: function (txt) { el.querySelector('.tqk-stage-status').textContent = txt || ''; },
      onStop: function (fn) { stopFns.push(fn); },
      mode: function () { return mode; }
    };
  }

  window.TQKStage = { create: create };
})();
