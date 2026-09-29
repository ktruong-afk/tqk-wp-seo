'use strict';
/* TQK WP SEO — post metabox: tabs, live SERP/social preview, counters. */
(function () {
  var root = document.querySelector('.tqk-wpseo-metabox');
  if (!root) return;

  var $ = function (id) { return document.getElementById(id); };
  var title = $('_tqk_wpseo_title');
  var desc = $('_tqk_wpseo_description');
  var focus = $('_tqk_wpseo_focus_keyword');
  var tPrev = $('tqk-wpseo-serp-title');
  var dPrev = $('tqk-wpseo-serp-desc');

  var T = (window.tqkWpseoAjax && window.tqkWpseoAjax.t) || {};
  var isBlock = !!(window.wp && window.wp.data && document.body.classList.contains('block-editor-page'));
  function store(k, v) { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) { /* private mode */ } return null; }

  /* Google cuts on PIXEL width, not characters: "WWW" and "iii" differ 3x.
     Desktop results: title ~580 px in 20px Arial, description ~920 px in
     14px Arial. Measured with a canvas, exactly like the browser draws it. */
  var LIMITS = {
    title: { font: '20px Arial', px: 580 },
    desc: { font: '14px Arial', px: 920 }
  };
  var ctx = document.createElement('canvas').getContext('2d');
  function width(text, font) {
    ctx.font = font;
    return Math.round(ctx.measureText(text).width);
  }
  function fmt(s) {
    var a = Array.prototype.slice.call(arguments, 1);
    return String(s || '').replace(/%(\d)\$[sd]/g, function (m, i) { return a[i - 1]; });
  }

  /* Longest prefix that fits, cut at a word boundary, with an ellipsis. */
  function clip(text, cfg) {
    if (width(text, cfg.font) <= cfg.px) return { text: text, cut: false };
    var lo = 0, hi = text.length;
    while (lo < hi) {
      var mid = (lo + hi + 1) >> 1;
      if (width(text.slice(0, mid) + ' …', cfg.font) <= cfg.px) lo = mid; else hi = mid - 1;
    }
    return { text: text.slice(0, lo).replace(/\s+\S*$/, '') + ' …', cut: true };
  }

  /* The value Google will actually show: typed text, else the template value. */
  function effective(input, target) {
    var v = input ? input.value.trim() : '';
    return { text: v !== '' ? v : ((target && target.getAttribute('data-effective')) || ''), typed: v !== '' };
  }

  function paintCounter(input, target, cfg) {
    var counter = document.querySelector('.tqk-wpseo-count[data-for="' + input.id + '"]');
    if (!counter) return;
    var e = effective(input, target);
    if (e.text === '') { counter.textContent = ''; counter.className = 'tqk-wpseo-count'; return; }
    var w = width(e.text, cfg.font);
    counter.textContent = fmt(T.px || '%1$d / %2$d px · %3$d chars', w, cfg.px, e.text.length) + (e.typed ? '' : ' · ' + (T.template || 'template'));
    counter.className = 'tqk-wpseo-count ' + (w <= cfg.px * 0.95 ? 'is-good' : (w <= cfg.px ? 'is-warn' : 'is-bad'));
    counter.title = w > cfg.px ? (T.cut || '') : '';
  }

  function paintPreview(input, target, cfg) {
    if (!target) return;
    var e = effective(input, target);
    if (e.text === '') {
      target.textContent = target === dPrev ? (T.noDesc || '') : '';
      target.classList.add('is-empty');
      target.classList.remove('is-cropped');
      return;
    }
    var out = clip(e.text, cfg);
    target.textContent = out.text;
    target.classList.remove('is-empty');
    target.classList.toggle('is-cropped', out.cut);
  }

  function paintCounters() {
    if (title) paintCounter(title, tPrev, LIMITS.title);
    if (desc) paintCounter(desc, dPrev, LIMITS.desc);
  }

  function paintSerp() {
    paintPreview(title, tPrev, LIMITS.title);
    paintPreview(desc, dPrev, LIMITS.desc);
  }

  /* Live checklist: the server re-runs the full analysis on the values being
     typed (same rules as after saving, accent-insensitive keyword matching)
     without saving anything. Debounced so typing stays smooth. */
  var analysis = root.querySelector('.tqk-wpseo-analysis');
  var postId = (document.getElementById('post_ID') || {}).value;
  var liveTimer = null;
  var liveSeq = 0;
  function liveAnalyze() {
    if (!analysis || !postId || !window.ajaxurl) return;
    clearTimeout(liveTimer);
    liveTimer = setTimeout(function () {
      var seq = ++liveSeq;
      analysis.classList.add('is-updating');
      var body = new FormData();
      body.append('action', 'tqk_wpseo_live_analysis');
      body.append('_ajax_nonce', (window.tqkWpseoAjax || {}).nonce || '');
      body.append('post_id', postId);
      [['title', title], ['description', desc], ['focus_keyword', focus], ['secondary_keywords', $('_tqk_wpseo_secondary_keywords')]].forEach(function (p) {
        if (p[1]) body.append(p[0], p[1].value);
      });
      fetch(window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (seq !== liveSeq || !res || !res.success) return;
          analysis.innerHTML = res.data.html;
          var badge = root.querySelector('.tqk-wpseo-tab[data-tab="seo"] .tqk-wpseo-tab-score');
          if (badge) badge.textContent = res.data.score;
        })
        .catch(function () { /* keep the last checklist */ })
        .then(function () { if (seq === liveSeq) analysis.classList.remove('is-updating'); });
    }, 700);
  }

  /* Share previews (Facebook, Zalo, X). Same fallbacks as the front end:
     share field → SEO field → template value; X: its own → share → SEO.
     With "Same as Google" ticked the share fields do not count. */
  var socialSync = $('tqk-social-sync');
  var socialCustom = root.querySelector('.tqk-social-custom');
  function own(id) {
    if (socialSync && socialSync.checked) return '';
    var el = $(id);
    return el ? el.value.trim() : '';
  }
  function paintSocial() {
    var seoT = effective(title, tPrev).text;
    var seoD = effective(desc, dPrev).text;
    var ogT = own('_tqk_wpseo_og_title') || seoT;
    var ogD = own('_tqk_wpseo_og_description') || seoD;
    var xT = own('_tqk_wpseo_twitter_title') || ogT;
    var xD = own('_tqk_wpseo_twitter_description') || ogD;
    root.querySelectorAll('.tqk-card').forEach(function (card) {
      var x = card.getAttribute('data-net') === 'x';
      card.querySelectorAll('[data-social-title]').forEach(function (el) { el.textContent = x ? xT : ogT; });
      card.querySelectorAll('[data-social-desc]').forEach(function (el) { el.textContent = x ? xD : ogD; });
    });
    var ogI = $('_tqk_wpseo_og_image');
    if (ogI) {
      var wrap = ogI.closest('[data-image-field]');
      var url = ogI.value.trim() || (wrap && wrap.getAttribute('data-auto-url')) || '';
      root.querySelectorAll('[data-social-img]').forEach(function (im) {
        if (url) { im.src = url; im.hidden = false; } else { im.removeAttribute('src'); im.hidden = true; }
      });
    }
  }

  if (socialSync) {
    socialSync.addEventListener('change', function () {
      if (socialCustom) socialCustom.hidden = socialSync.checked;
      if (!socialSync.checked) {
        // Start from what is shown now, so there is something to edit.
        [['_tqk_wpseo_og_title', effective(title, tPrev).text], ['_tqk_wpseo_og_description', effective(desc, dPrev).text]].forEach(function (p) {
          var el = $(p[0]);
          if (el && !el.value.trim()) el.value = p[1];
        });
      }
      refresh();
    });
  }

  /* Facebook / Zalo / X preview switch, remembered. */
  var netBtns = root.querySelectorAll('.tqk-social-tabs button');
  function showNet(net) {
    netBtns.forEach(function (b) { b.setAttribute('aria-selected', b.getAttribute('data-net') === net ? 'true' : 'false'); });
    root.querySelectorAll('.tqk-card[data-net]').forEach(function (c) { c.hidden = c.getAttribute('data-net') !== net; });
    store('tqk_wpseo_net', net);
  }
  netBtns.forEach(function (b) { b.addEventListener('click', function () { showNet(b.getAttribute('data-net')); }); });
  if (netBtns.length) showNet(store('tqk_wpseo_net') || 'facebook');

  function refresh() {
    paintCounters();
    paintSerp();
    paintSocial();
  }

  [title, desc, focus, $('_tqk_wpseo_og_title'), $('_tqk_wpseo_og_description'), $('_tqk_wpseo_og_image'), $('_tqk_wpseo_twitter_title'), $('_tqk_wpseo_twitter_description')]
    .forEach(function (el) { if (el) el.addEventListener('input', refresh); });
  [title, desc, focus, $('_tqk_wpseo_secondary_keywords')]
    .forEach(function (el) { if (el) el.addEventListener('input', liveAnalyze); });
  refresh();

  /* "Same as page title": the SEO title mirrors the post title while ticked
     (classic title box or the block editor's title), read-only meanwhile. */
  var titleSync = $('tqk-title-sync');
  function postTitle() {
    if (isBlock) {
      try { return window.wp.data.select('core/editor').getEditedPostAttribute('title') || ''; } catch (e) { /* fall through */ }
    }
    var t = document.getElementById('title');
    return t ? t.value : (root.getAttribute('data-post-title') || '');
  }
  function syncTitle() {
    if (!titleSync || !title) return;
    title.readOnly = titleSync.checked;
    title.classList.toggle('is-synced', titleSync.checked);
    if (!titleSync.checked) return;
    var v = postTitle();
    if (title.value !== v) {
      title.value = v;
      refresh();
      liveAnalyze();
    }
  }
  if (titleSync) {
    titleSync.addEventListener('change', syncTitle);
    var classicTitle = document.getElementById('title');
    if (classicTitle) classicTitle.addEventListener('input', syncTitle);
    if (isBlock && window.wp.data.subscribe) {
      var lastT = null;
      window.wp.data.subscribe(function () {
        var t = postTitle();
        if (t !== lastT) { lastT = t; syncTitle(); }
      });
    }
    syncTitle();
  }

  /* Tabs: persist the open one so the box reopens where the user left it. */
  var KEY = 'tqk_wpseo_tab';
  var tabs = root.querySelectorAll('.tqk-wpseo-tab');
  function open(slug) {
    tabs.forEach(function (b) {
      var on = b.getAttribute('data-tab') === slug;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    root.querySelectorAll('.tqk-wpseo-panel').forEach(function (p) {
      p.hidden = p.getAttribute('data-panel') !== slug;
    });
    try { localStorage.setItem(KEY, slug); } catch (e) { /* private mode */ }
  }
  tabs.forEach(function (b) {
    b.addEventListener('click', function () { open(b.getAttribute('data-tab')); });
  });
  var saved = null;
  try { saved = localStorage.getItem(KEY); } catch (e) { /* ignore */ }
  open(saved || 'seo');

  /* ---------- Schema: per-type fields, client validation, harvest ---------- */
  var schemaSelect = $('_tqk_wpseo_schema_type');
  var schemaBox = $('tqk-wpseo-schema-fields');
  var spec = {};
  var specEl = document.querySelector('.tqk-wpseo-schema-spec');
  if (specEl) {
    try { spec = JSON.parse(specEl.textContent) || {}; } catch (e) { spec = {}; }
  }

  /* Same rules the server enforces, so the editor sees the problem before the
     round trip instead of after it. */
  function durationIsBad(v) {
    if (!v) return false;
    if (/^P(?:\d+[YMWD])*(?:T(?:\d+[HMS])+)?$/.test(v.trim())) return false;
    var norm = v.toLowerCase()
      .replace(/giờ|gio|hours?|hrs?|tieng/g, 'h')
      .replace(/phút|phut|minutes?|mins?/g, 'm')
      .replace(/giây|seconds?|secs?/g, 's');
    return !/\d/.test(norm) || !/[hms]/.test(norm);
  }
  function dateIsBad(v) {
    if (!v) return false;
    var t = v.trim().replace('T', ' ');
    return !/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/.test(t);
  }

  function paintSchema() {
    if (!schemaBox || !schemaSelect) return;
    var type = schemaSelect.value;
    schemaBox.setAttribute('data-active', type);
    schemaBox.querySelectorAll('.tqk-wpseo-schema-block').forEach(function (b) {
      b.hidden = b.getAttribute('data-schema-type') !== type;
    });
    /* Required markers only mean something for the type on screen. */
    schemaBox.querySelectorAll('.tqk-wpseo-schema-field').forEach(function (f) {
      var req = (spec[type] || []).indexOf(f.getAttribute('data-key')) !== -1;
      var star = f.querySelector('.tqk-req');
      if (star) star.style.display = req ? '' : 'none';
      var input = f.querySelector('[data-schema-field]');
      if (input) input.classList.toggle('is-required', req);
    });
  }

  function validateSchema() {
    if (!schemaBox || !schemaSelect) return true;
    var type = schemaSelect.value;
    var req = spec[type] || [];
    if (req.length === 0) return true;
    var ok = true;
    req.forEach(function (key) {
      var f = schemaBox.querySelector('.tqk-wpseo-schema-field[data-key="' + key + '"]');
      var input = f && f.querySelector('[data-schema-field]');
      if (!input) return;
      var bad = !input.value.trim();
      if (!bad && durationIsBad(input.value)) bad = true;
      if (!bad && dateIsBad(input.value)) bad = true;
      f.classList.toggle('is-invalid', bad);
      if (bad) ok = false;
    });
    return ok;
  }

  if (schemaSelect) {
    schemaSelect.addEventListener('change', function () { paintSchema(); validateSchema(); });
  }
  if (schemaBox) {
    schemaBox.addEventListener('input', validateSchema);
  }

  /* Extract from post content. Server only reads; it never writes meta. */
  schemaBox && schemaBox.querySelectorAll('.tqk-wpseo-schema-harvest').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var type = btn.getAttribute('data-harvest');
      var post = btn.getAttribute('data-post');
      if (typeof window.tqkWpseoAjax === 'undefined' || !window.tqkWpseoAjax.nonce) {
        btn.textContent = 'AJAX unavailable — fill in manually.';
        return;
      }
      btn.disabled = true;
      var body = new URLSearchParams();
      body.append('action', 'tqk_wpseo_schema_harvest');
      body.append('tqk_action', 'schema_harvest');
      body.append('_ajax_nonce', window.tqkWpseoAjax.nonce);
      body.append('post_id', post);
      body.append('schema_type', type);
      fetch(window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          var found = (res.data && res.data.fields) || {};
          Object.keys(found).forEach(function (key) {
            var f = schemaBox.querySelector('.tqk-wpseo-schema-field[data-key="' + key + '"]');
            var input = f && f.querySelector('[data-schema-field]');
            /* Never overwrite something the editor already wrote. */
            if (input && !input.value.trim()) input.value = found[key];
          });
          btn.textContent = (res.data && res.data.message) || (res.success ? 'Done.' : 'Nothing found.');
          validateSchema();
        })
        .catch(function () { btn.textContent = 'Failed — fill in manually.'; })
        .then(function () { btn.disabled = false; });
    });
  });

  /* ---------- Media picker for the share image ----------
   * The frame always shows the image that WILL be shared (picked, else the
   * automatic one), cropped 1.91:1 like Facebook / Zalo, with its real size. */
  var frame = null;

  /* Smallest registered size that is still >= 1200 px wide: a share image
     does not need a 5 MB original. Falls back to the full image. */
  function bestSize(a) {
    var best = null;
    Object.keys(a.sizes || {}).forEach(function (k) {
      var s = a.sizes[k];
      if (s && s.width >= 1200 && (!best || s.width < best.width)) best = s;
    });
    return best ? best.url : (a.url || '');
  }

  function rateSize(wrap, url) {
    var out = wrap.querySelector('.tqk-share-size');
    if (!out) return;
    out.textContent = '';
    out.className = 'tqk-share-size';
    if (!url) return;
    var im = new Image();
    im.onload = function () {
      var w = im.naturalWidth, h = im.naturalHeight, r = w / Math.max(1, h);
      var msg, cls;
      if (w < 600 || h < 315) { msg = T.sizeSmall; cls = 'is-bad'; }
      else if (r < 1.6 || r > 2.2) { msg = T.sizeRatio; cls = 'is-warn'; }
      else if (w < 1200) { msg = T.sizeLow; cls = 'is-warn'; }
      else { msg = T.sizeOk; cls = 'is-good'; }
      out.textContent = w + '×' + h + ' · ' + (msg || '');
      out.className = 'tqk-share-size ' + cls;
    };
    im.onerror = function () { out.textContent = T.sizeBroken || ''; out.className = 'tqk-share-size is-bad'; };
    im.src = url;
  }

  function showShare(wrap) {
    var input = wrap.querySelector('[data-image-url]');
    var custom = input.value.trim();
    var url = custom || wrap.getAttribute('data-auto-url') || '';
    var img = wrap.querySelector('.tqk-wpseo-image-preview');
    var empty = wrap.querySelector('.tqk-share-empty');
    var box = wrap.querySelector('.tqk-share-frame');
    var src = wrap.querySelector('.tqk-share-source');
    var pick = wrap.querySelector('.tqk-wpseo-image-pick');
    var clear = wrap.querySelector('.tqk-wpseo-image-clear');
    if (img) { if (url) img.src = url; else img.removeAttribute('src'); img.hidden = !url; }
    if (empty) empty.hidden = !!url;
    if (box) box.classList.toggle('is-empty', !url);
    if (src) {
      src.textContent = custom ? wrap.getAttribute('data-custom-label') : wrap.getAttribute('data-auto-label');
      src.setAttribute('data-source', custom ? 'custom' : (url ? 'auto' : 'none'));
    }
    if (pick) pick.textContent = custom ? pick.getAttribute('data-label-change') : pick.getAttribute('data-label-select');
    if (clear) clear.hidden = !custom;
    rateSize(wrap, url);
    return url;
  }

  root.querySelectorAll('[data-image-field]').forEach(function (wrap) {
    var input = wrap.querySelector('[data-image-url]');
    var pick = wrap.querySelector('.tqk-wpseo-image-pick');
    var clear = wrap.querySelector('.tqk-wpseo-image-clear');
    if (!input || !pick) return;
    showShare(wrap);
    pick.addEventListener('click', function (ev) {
      ev.preventDefault();
      if (!window.wp || !window.wp.media) return;
      if (!frame) {
        frame = window.wp.media({ title: pick.textContent, multiple: false, library: { type: 'image' } });
      }
      frame.off('select');
      frame.on('select', function () {
        var url = bestSize(frame.state().get('selection').first().toJSON());
        if (!url) return;
        input.value = url;
        showShare(wrap);
        refresh();
      });
      frame.open();
    });
    if (clear) {
      clear.addEventListener('click', function (ev) {
        ev.preventDefault();
        input.value = '';
        showShare(wrap);
        refresh();
      });
    }
  });

  paintSchema();
  validateSchema();
  refresh();

  /* ---------- Scan: open the real page in the visible stage ----------
   * Same engine and stage as Live Audit. The rendered, scrolled page (lazy
   * images, builder / JS content) is read in the browser and sent back, so
   * the checklist judges what visitors actually get, not post_content. */
  var AJAX = window.tqkWpseoAjax || {};
  /* Numbered (%1$s) and plain (%s / %d) placeholders, as in the PHP strings. */
  var fmt2 = function (s) {
    var a = Array.prototype.slice.call(arguments, 1), i = 0;
    return fmt.apply(null, arguments).replace(/%[sd]/g, function () { return a[i++]; });
  };
  var gutenberg = isBlock;
  var scanBar = root.querySelector('.tqk-scan-bar');
  var scanMsg = scanBar && scanBar.querySelector('.tqk-scan-msg');
  var scanReport = root.querySelector('.tqk-scan-report');
  var stage = null;
  var scanning = null;
  var dirty = false;
  var form = document.getElementById('post');
  if (form) form.addEventListener('input', function () { dirty = true; });

  function post(action, data) {
    var body = new FormData();
    body.append('action', action);
    body.append('_ajax_nonce', AJAX.nonce || '');
    body.append('post_id', postId);
    Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
    return fetch(window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json(); });
  }

  function say(el, txt, tone) {
    if (!el) return;
    el.textContent = txt || '';
    el.className = el.className.replace(/\s*is-(good|bad|warn)/g, '') + (tone ? ' is-' + tone : '');
  }

  function isDirty() {
    if (gutenberg) {
      try { return window.wp.data.select('core/editor').isEditedPostDirty(); } catch (e) { return dirty; }
    }
    return dirty;
  }

  function scanUrl() {
    if (gutenberg) {
      try {
        var ed = window.wp.data.select('core/editor');
        var st = ed.getEditedPostAttribute('status');
        return st === 'publish' ? ed.getPermalink() : ed.getEditedPostPreviewLink();
      } catch (e) { /* fall back to the server value */ }
    }
    return scanBar ? scanBar.getAttribute('data-scan-url') : '';
  }

  /* Main content only, like the server-side capture: no header / footer /
     menus / scripts, so the word count and links are the article's own. */
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

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  /* Scan mode: section by section (slow, results next to the page) or quick. */
  var MODE_KEY = 'tqk_wpseo_scan_mode';
  var modeRadios = scanBar ? Array.prototype.slice.call(scanBar.querySelectorAll('input[name="tqk_scan_mode"]')) : [];
  var modeHelp = scanBar && scanBar.querySelector('.tqk-scan-help');
  function scanMode() {
    var m = 'detail';
    modeRadios.forEach(function (r) { if (r.checked) m = r.value; });
    return m;
  }
  function paintMode() {
    if (modeHelp) modeHelp.textContent = modeHelp.getAttribute(scanMode() === 'fast' ? 'data-fast' : 'data-detail') || '';
  }
  (function () {
    var saved = store(MODE_KEY) === 'fast' ? 'fast' : 'detail';
    modeRadios.forEach(function (r) {
      r.checked = r.value === saved;
      r.addEventListener('change', function () { store(MODE_KEY, scanMode()); paintMode(); });
    });
    paintMode();
  })();

  var secResult = null;
  var secCheck = null;
  var mobileRes = null;

  /* Report after a scan: the action plan first (what to do), then the
     snapshots, the per-screen image table (optimizer), the section tour, and
     the raw measurements folded away. Painted once when the scan ends, again
     when the server has answered (images, checklist counts, snapshots). */
  function paintReport(rep, srv) {
    if (!scanReport) return;
    var vps = (rep.viewports || []).map(function (v) {
      var tone = v.error ? 'bad' : (v.score >= 90 ? 'good' : (v.score >= 70 ? 'warn' : 'bad'));
      // Display score of the screen, out of 100; the tooltip says what cost points.
      var why = [];
      if (v.overflowX > 0) why.push(fmt2(T.whyOverflow || 'scrolls sideways by %dpx', v.overflowX));
      if ((v.tinyText || []).length) why.push(fmt2(T.whyTiny || '%d text(s) under 12px', v.tinyText.length));
      if ((v.smallTap || []).length) why.push(fmt2(T.whyTap || '%d button(s)/link(s) under 44px', v.smallTap.length));
      if ((v.missingDims || []).length) why.push(fmt2(T.whyDims || '%d image(s) without width/height', v.missingDims.length));
      if ((v.oversized || []).length) why.push(fmt2(T.whyOver || '%d oversized image(s)', v.oversized.length));
      var title = v.error ? v.error : (T.whyTitle || '') + ' ' + (why.length ? (T.whyLost || '') + ' ' + why.join(', ') + '.' : (T.whyNone || ''));
      return '<span class="tqk-scan-vp is-' + tone + '" title="' + esc(title) + '">' + esc(v.label) + ' · ' + (v.error ? '×' : '<b>' + esc(v.score) + '</b>/100') + '</span>';
    }).join('');
    var plan = window.TQKPlan
      ? window.TQKPlan.html(window.TQKPlan.build(rep, {
        sections: secResult,
        images: srv ? srv.images : null,
        server: srv ? srv.server : null,
        security: secCheck
      }, T.plan || {}), T.plan || {})
      : '';
    var scripts = '';
    if (secCheck) {
      var rows = (secCheck.scripts || []).map(function (s) {
        return '<tr><td><span class="tqk-kind is-' + esc(s.kind) + '">' + esc((T.kinds && T.kinds[s.kind]) || s.kind) + '</span></td><td>' + esc(s.origin) + '</td>'
          + '<td class="tqk-src">' + esc(s.src.replace(/^https?:\/\/[^/]+/, '')) + '</td></tr>';
      }).join('');
      var flagged = ((secCheck.inline || {}).flagged || []).map(function (s) {
        return '<li><strong>#' + (s.index + 1) + '</strong> <code>' + esc(s.sample) + '</code></li>';
      }).join('');
      scripts = '<details class="tqk-report-block tqk-scripts" id="tqk-plan-scripts"' + (secCheck.verdict !== 'clean' ? ' open' : '') + '><summary><strong>' + esc(T.scriptsTitle) + '</strong>'
        + '<span class="tqk-kind is-' + esc(secCheck.verdict) + '">' + esc((T.plan && T.plan.verdict && T.plan.verdict[secCheck.verdict]) || secCheck.verdict) + '</span>'
        + '<span class="description">' + esc(fmt2(T.scriptsSum || '%1$d files · %2$d inline', (secCheck.scripts || []).length, (secCheck.inline || {}).count || 0)) + '</span></summary>'
        + '<p class="description">' + esc(T.scriptsHow) + '</p>'
        + (rows ? '<table class="tqk-scripts-table"><tbody>' + rows + '</tbody></table>' : '')
        + (flagged ? '<p><strong>' + esc(T.inlineFlagged) + '</strong></p><ul class="tqk-inline-flagged">' + flagged + '</ul>' : '')
        + '</details>';
    }
    var shots = '';
    if (srv && srv.shots) {
      Object.keys(srv.shots).forEach(function (k) {
        shots += '<a class="tqk-shot" href="' + esc(srv.shots[k]) + '" target="_blank" rel="noopener"><img src="' + esc(srv.shots[k]) + '" alt=""><span>' + esc(k) + '</span></a>';
      });
      if (shots) {
        shots = '<div class="tqk-report-block"><div class="tqk-report-row"><strong>' + esc(T.shotsTitle) + '</strong><span class="description">' + esc(T.shotsHow) + '</span></div>'
          + '<div class="tqk-shots">' + shots + '</div></div>';
      }
    }
    var images = srv && srv.images_html
      ? '<div class="tqk-report-block" id="tqk-plan-images"><div class="tqk-report-row"><strong>' + esc(T.imagesTitle) + '</strong></div>' + srv.images_html + '</div>'
      : '';
    var secs = '';
    if (secResult && window.TQKSections) {
      secs = '<div class="tqk-report-block"><div class="tqk-report-row"><strong>' + esc(T.secTitle) + '</strong><span class="description">'
        + esc(fmt2(T.secDone || '%1$d / %2$d / %3$d', secResult.sections.length, secResult.images, secResult.issues)) + '</span></div>'
        + secResult.sections.map(function (sc) { return window.TQKSections.html(sc, T.sec); }).join('')
        + (mobileRes && mobileRes.images.length ? window.TQKSections.html(mobileRes, T.sec) : '') + '</div>';
    }
    var fs = ((rep.seo && rep.seo.findings) || []).filter(function (f) { return f.sev !== 'pass'; });
    var raw = fs.length
      ? '<ul>' + fs.map(function (f) { return '<li class="is-' + (f.sev === 'critical' ? 'bad' : 'warn') + '">' + esc(f.msg) + '</li>'; }).join('') + '</ul>'
      : '<p class="is-good">' + esc(T.scanNoIssue) + '</p>';
    scanReport.innerHTML = plan + shots + images + scripts + secs
      + '<details class="tqk-report-more"><summary><strong>' + esc(T.scanScreens) + '</strong><span class="tqk-report-vps">' + vps + '</span></summary>'
      + '<div class="tqk-report-row"><strong>' + esc(T.scanPage) + '</strong></div>' + raw + '</details>';
    scanReport.hidden = false;
  }

  /* What an AI agent reads over MCP: the measurements in plain fields
     (the server adds its checklist, images and later the security check). */
  function agentReport(rep, detail) {
    return window.TQKPlan
      ? window.TQKPlan.agentReport(rep, { sections: secResult, mobile: mobileRes, detail: detail, source: 'editor scan' }, T.plan || {})
      : {};
  }

  /* Last stored report of this page (from Live Audit or an earlier Scan):
     shown right away, no need to scan again to see the plan. */
  (function () {
    var el = root.querySelector('.tqk-last-report');
    if (!el || !scanReport || !window.TQKPlan) return;
    var r;
    try { r = JSON.parse(el.textContent || 'null'); } catch (e) { r = null; }
    if (!r || !r.plan) return;
    var shots = '';
    Object.keys(r.shots || {}).forEach(function (k) {
      shots += '<a class="tqk-shot" href="' + esc(r.shots[k]) + '" target="_blank" rel="noopener"><img src="' + esc(r.shots[k]) + '" alt="" loading="lazy"><span>' + esc(k) + '</span></a>';
    });
    // Score trail: first earlier check → now, so progress shows at a glance.
    var trend = '';
    var hist = r.history || [];
    if (hist.length) {
      var was = hist[0];
      var arrow = function (a, b) { return b > a ? ' ↑' : (b < a ? ' ↓' : ''); };
      trend = '<div class="tqk-report-row tqk-trend"><span class="description">'
        + esc(fmt2(T.trend || 'Over %1$d checks: SEO %2$d → %3$d, to fix now %4$d → %5$d', hist.length + 1, was.seo, r.score || 0, was.fix_now,
          (r.plan || []).filter(function (p) { return p.prio === 1; }).length))
        + esc(arrow(was.seo, r.score || 0)) + '</span></div>';
    }
    scanReport.innerHTML = '<div class="tqk-report-row tqk-last-head"><strong>' + esc(T.lastTitle) + '</strong><span class="description">'
      + esc(fmt2(T.lastWhen || '%1$s · %2$s', (T.sources && T.sources[r.source]) || r.source || '', el.getAttribute('data-when') || '')) + '</span></div>'
      + trend
      + window.TQKPlan.stored(r, T.plan || {})
      + (shots ? '<div class="tqk-report-block"><div class="tqk-shots">' + shots + '</div></div>' : '');
    scanReport.hidden = false;
  })();

  /* Toast: a short confirmation at the bottom right, gone after a few
     seconds (errors stay a little longer). One at a time. */
  var toastEl = null;
  var toastTimer = null;
  function toast(msg, tone) {
    if (!toastEl) {
      toastEl = document.createElement('div');
      toastEl.className = 'tqk-toast';
      toastEl.setAttribute('role', 'status');
      toastEl.setAttribute('aria-live', 'polite');
      document.body.appendChild(toastEl);
    }
    toastEl.className = 'tqk-toast is-' + (tone || 'good');
    toastEl.innerHTML = '<span class="tqk-toast-ico">' + (tone === 'bad' ? '✕' : (tone === 'warn' ? '!' : '✓')) + '</span><span class="tqk-toast-msg"></span>';
    toastEl.querySelector('.tqk-toast-msg').textContent = msg;
    // Next frame, so the slide-in plays again for a new message.
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

  function flashField(el) {
    var wrap = el && el.closest('.tqk-wpseo-field');
    if (!wrap) return;
    wrap.classList.add('is-filled');
    setTimeout(function () { wrap.classList.remove('is-filled'); }, 2500);
  }

  /* Plan buttons: jump to the field / tab that fixes it, copy the CSS, or
     open the image table. Image optimizer buttons: same AJAX as Live Audit. */
  if (scanReport) {
    scanReport.addEventListener('click', function (e) {
      var fix = e.target.closest ? e.target.closest('.tqk-plan-fix') : null;
      if (fix) {
        var type = fix.getAttribute('data-fix');
        var key = fix.getAttribute('data-key');
        if (type === 'css') {
          var old = fix.textContent;
          copyText(fix.getAttribute('data-css') || '').then(function () {
            fix.textContent = T.copied || '✓';
            setTimeout(function () { fix.textContent = old; }, 1500);
            toast(T.toastCss || 'CSS copied. Paste it in Appearance → Customize → Additional CSS.', 'good');
          }, function () {
            toast(T.toastCssFail || 'Not copied: select the CSS line under the item and press Ctrl+C.', 'warn');
          });
        } else if (type === 'field' || type === 'schema') {
          var el = $(type === 'schema' ? '_tqk_wpseo_schema_type' : key);
          var panelEl = el && el.closest('.tqk-wpseo-panel');
          if (panelEl) open(panelEl.getAttribute('data-panel'));
          if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            setTimeout(function () { el.focus(); }, 350);
            flashField(el);
          }
        } else if (type === 'tab') {
          open(key);
          root.querySelector('.tqk-wpseo-tabs').scrollIntoView({ behavior: 'smooth', block: 'start' });
        } else if (type === 'images') {
          var box = document.getElementById('tqk-plan-images');
          if (box) {
            var d = box.querySelector('details');
            if (d) d.open = true;
            box.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
        } else if (type === 'scripts') {
          var sb = document.getElementById('tqk-plan-scripts');
          if (sb) { sb.open = true; sb.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
        } else if (type === 'checklist' && analysis) {
          analysis.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        return;
      }
      var btn = e.target.closest ? e.target.closest('[data-img-op]') : null;
      if (!btn) return;
      var page = btn.closest('[data-page]');
      var op = btn.getAttribute('data-img-op');
      if (op === 'purge' && !window.confirm(btn.textContent + '?')) return;
      var body = new FormData();
      body.append('action', 'tqk_wpseo_img_fix');
      body.append('tqk_action', 'img_fix');
      body.append('_ajax_nonce', AJAX.nonce || '');
      body.append('op', op);
      body.append('page_id', page.getAttribute('data-page'));
      btn.disabled = true;
      var was = btn.textContent;
      btn.textContent = '…';
      fetch(window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (res && res.success && res.data.html) {
            var w = document.createElement('div');
            w.innerHTML = res.data.html;
            var fresh = w.firstElementChild;
            fresh.open = true;
            page.parentNode.replaceChild(fresh, page);
            var st = fresh.querySelector('.tqk-img-state');
            if (st) st.textContent = res.data.message + ' — ' + st.textContent;
          } else {
            btn.disabled = false;
            btn.textContent = was;
            window.alert((res && res.data && res.data.message) || 'Error');
          }
        }, function () { btn.disabled = false; btn.textContent = was; });
    });
  }

  function scan() {
    if (scanning || !window.TQKResponsive || !scanBar) return;
    var url = scanUrl();
    if (!url) return;
    if (!stage && window.TQKStage) {
      stage = window.TQKStage.create({ t: T.stage || {} });
      stage.onStop(function () {
        if (scanning) { scanning.cancel(); scanning = null; }
        stage.close();
        say(scanMsg, T.scanStopped, 'warn');
        scanBar.classList.remove('is-busy');
      });
    }
    var warn = isDirty() ? ' ' + (T.scanDirty || '') : '';
    var html = '';
    var detail = scanMode() === 'detail' && !!window.TQKSections;
    secResult = null;
    mobileRes = null;
    secCheck = null;
    var panel = stage && stage.panel ? stage.panel(detail) : null;
    if (panel) panel.innerHTML = '';
    scanBar.classList.add('is-busy');
    say(scanMsg, T.scanRead);
    if (stage) {
      stage.label(root.getAttribute('data-post-title') || url);
      stage.open();
    }
    scanning = window.TQKResponsive.run(url, {
      stage: stage ? stage.view : null,
      scrollDelay: detail ? 450 : 220,
      shots: window.TQKSnapshot ? ['desktop', 'laptop', 'tablet', 'mobile'] : [],
      t: T.seo || {},
      timeout: 30,
      onDoc: function (win, doc, key) {
        if (key === 'desktop') {
          html = mainHtml(doc); // before any outline is drawn on the page
          if (!detail) return null;
          return window.TQKSections.tour(win, doc, {
            t: T.sec || {},
            dwell: 1100,
            imgDwell: 600,
            cancelled: function () { return !scanning; },
            onStep: function (txt) { say(scanMsg, txt); if (stage) stage.status(txt); },
            onSection: function (sec) {
              if (!panel) return;
              panel.insertAdjacentHTML('beforeend', window.TQKSections.html(sec, T.sec));
              panel.scrollTop = panel.scrollHeight;
            }
          }).then(function (r) { secResult = r; });
        }
        if (detail && key === 'mobile') mobileRes = window.TQKSections.mobile(win, doc, T.sec || {});
        return null;
      }
    }, function (step) {
      if (step.phase !== 'load' && step.phase !== 'snapshot') return;
      var txt = step.phase === 'snapshot'
        ? fmt2(T.scanShot || '%s', step.viewport.label)
        : fmt2(T.scanLoad || '%s', step.viewport.label) + ' (' + (step.index + 1) + '/' + step.total + ')';
      say(scanMsg, txt);
      if (stage) stage.status(txt);
    }, function (rep) {
      scanning = null;
      if (stage) stage.close();
      scanBar.classList.remove('is-busy');
      if (!html) {
        say(scanMsg, fmt2(T.scanFail || '%s', rep.error || '?'), 'bad');
        return;
      }
      paintReport(rep, null);
      say(scanMsg, T.scanSaving);
      if (analysis) analysis.classList.add('is-updating');
      var ok = (rep.viewports || []).filter(function (v) { return !v.error; });
      var data = { html: html, images: JSON.stringify(window.TQKResponsive.mergeImages ? window.TQKResponsive.mergeImages(ok) : []) };
      Object.keys(rep.shots || {}).forEach(function (k) { data['shot_' + k] = rep.shots[k]; });
      data.report = JSON.stringify(agentReport(rep, detail));
      post('tqk_wpseo_scan_result', data).then(function (res) {
        if (!res || !res.success) throw new Error((res && res.data && res.data.message) || 'error');
        if (analysis) analysis.innerHTML = res.data.html;
        paintReport(rep, res.data);
        // Security: the server fetches the page as visitor / from Google /
        // as Googlebot and compares. Runs after, the plan updates when done.
        var srv = res.data;
        say(scanMsg, T.secRunning);
        post('tqk_wpseo_page_security', {}).then(function (r2) {
          if (r2 && r2.success) { secCheck = r2.data; paintReport(rep, srv); }
        }).catch(function () { /* the plan stays without the security part */ })
          .then(function () { say(scanMsg, fmt2(T.scanDone || '%s', new Date().toLocaleTimeString()) + warn, warn ? 'warn' : 'good'); });
        var badge = root.querySelector('.tqk-wpseo-tab[data-tab="seo"] .tqk-wpseo-tab-score');
        if (badge) badge.textContent = res.data.score;
      }).catch(function (e) {
        say(scanMsg, fmt2(T.scanFail || '%s', e.message), 'bad');
      }).then(function () { if (analysis) analysis.classList.remove('is-updating'); });
    });
  }

  /* Save & scan. The post is saved by WordPress itself (never a home-made
     AJAX save: other plugins' fields in the same form would be lost). */
  var FLAG = 'tqk_wpseo_autoscan';
  function saveAndScan() {
    if (gutenberg) {
      var d = window.wp.data;
      var seen = false, waited = 0;
      say(scanMsg, T.saving);
      d.dispatch('core/editor').savePost();
      var timer = setInterval(function () {
        waited += 300;
        var ed = d.select('core/editor');
        var mb = d.select('core/edit-post');
        var busy = ed.isSavingPost() || ed.isAutosavingPost() || (mb && mb.isSavingMetaBoxes && mb.isSavingMetaBoxes());
        if (busy) seen = true;
        if ((seen && !busy) || waited > 60000) {
          clearInterval(timer);
          dirty = false;
          scan();
        }
      }, 300);
      return;
    }
    // Classic editor: the page reloads after saving, the scan starts there.
    try { sessionStorage.setItem(FLAG, postId + ':' + Date.now()); } catch (e) { /* no storage: plain save */ }
    var draft = document.getElementById('save-post');
    var btn = draft && draft.offsetParent !== null ? draft : document.getElementById('publish');
    if (btn) btn.click();
  }

  if (scanBar) {
    scanBar.querySelector('.tqk-scan-run').addEventListener('click', scan);
    scanBar.querySelector('.tqk-scan-save').addEventListener('click', saveAndScan);
    try {
      var flag = (sessionStorage.getItem(FLAG) || '').split(':');
      sessionStorage.removeItem(FLAG);
      if (flag[0] === String(postId) && Date.now() - Number(flag[1]) < 120000) setTimeout(scan, 600);
    } catch (e) { /* ignore */ }
  }

  /* ---------- JSON for another AI / JSON-LD import ---------- */
  var jbox = root.querySelector('.tqk-json-box');
  if (jbox) {
    var jmsg = jbox.querySelector('.tqk-json-msg');
    var jin = jbox.querySelector('.tqk-json-in');
    var jres = jbox.querySelector('.tqk-json-result');

    /* One set per click: page text first, then one task with only its keys.
       Compact JSON (no indentation) keeps it short for small assistants. */
    /* Sets are fetched as soon as the box opens: the copy then happens
       inside the click itself, which is what browsers require to allow it
       (on http, or once a slow request has used up the click). Editing a
       field drops the cache so the next copy has the new values. */
    var setCache = {};
    function fetchSet(bundle) {
      if (!setCache[bundle]) {
        var cache = setCache;
        var p = post('tqk_wpseo_ai_json', { mode: 'bundle', bundle: bundle }).then(function (res) {
          if (!res || !res.success) { if (cache[bundle] === p) delete cache[bundle]; throw new Error('x'); }
          var txt = typeof res.data.text === 'string' ? res.data.text : JSON.stringify(res.data.json);
          p.txt = txt;
          return txt;
        });
        cache[bundle] = p;
      }
      return setCache[bundle];
    }
    jbox.addEventListener('toggle', function () {
      if (jbox.open) jbox.querySelectorAll('.tqk-json-set').forEach(function (b) { fetchSet(b.getAttribute('data-bundle')).catch(function () {}); });
    });
    root.addEventListener('input', function (e) {
      if (!jbox.contains(e.target)) setCache = {};
    });
    root.addEventListener('change', function (e) {
      if (!jbox.contains(e.target)) setCache = {};
    });

    jbox.querySelectorAll('.tqk-json-set').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var bundle = btn.getAttribute('data-bundle');
        jbox.querySelectorAll('.tqk-json-set').forEach(function (b) { b.classList.toggle('is-picked', b === btn); });
        var ready = setCache[bundle] && typeof setCache[bundle].txt === 'string';
        if (!ready) say(jmsg, '…');
        // Ready: resolve now, the copy below still runs within this click.
        (ready ? { then: function (fn) { return fn(setCache[bundle].txt); } } : fetchSet(bundle)).then(function (txt) {
          var info = fmt2(T.jsonCopiedSet || '%1$s: %2$s characters (~%3$s tokens).', btn.textContent.trim(), txt.length.toLocaleString(), Math.round(txt.length / 3.5).toLocaleString());
          return copyText(txt).then(function () {
            say(jmsg, info + ' ' + (bundle === 'content' ? (T.jsonCopiedText || '') : (T.jsonCopied || '')), 'good');
            toast(fmt2(T.toastCopied || 'Copied %1$s — %2$s characters. Paste it into the AI chat.', btn.textContent.trim(), txt.length.toLocaleString()), 'good');
          }, function () {
            // Clipboard refused (http, browser setting): the text is put in
            // the box below, selected, ready for Ctrl+C.
            jin.value = txt;
            jin.select();
            say(jmsg, info + ' ' + (T.jsonCopyFail || ''), 'warn');
            toast(T.toastNotCopied || 'Not copied automatically: the text is selected in the box below, press Ctrl+C.', 'warn');
          });
        }).catch(function () {
          say(jmsg, T.scanFail ? fmt2(T.scanFail, 'AJAX') : 'Error', 'bad');
          toast(T.toastFailed || 'Could not prepare the text. Reload the page and try again.', 'bad');
        });
      });
    });

    /* Send to an AI app via MCP: the stored scan report goes to the inbox. */
    var mcpBtn = root.querySelector('.tqk-mcp-push');
    if (mcpBtn) {
      mcpBtn.addEventListener('click', function () {
        var msg = root.querySelector('.tqk-mcp-msg');
        var note = root.querySelector('.tqk-mcp-note');
        mcpBtn.disabled = true;
        say(msg, '…');
        post('tqk_wpseo_mcp_push', { note: note ? note.value : '' }).then(function (res) {
          var text = (res && res.data && res.data.message) || 'Error';
          say(msg, text, res && res.success ? 'good' : 'bad');
          toast(text, res && res.success ? 'good' : 'bad');
        }).catch(function () { say(msg, 'Error', 'bad'); toast(T.toastFailed || 'Error', 'bad'); })
          .then(function () { mcpBtn.disabled = false; });
      });
    }

    jbox.querySelector('.tqk-json-read').addEventListener('click', function () {
      var raw = jin.value.trim();
      jres.innerHTML = '';
      if (!raw) { say(jmsg, T.jsonEmpty, 'warn'); return; }
      say(jmsg, '…');
      post('tqk_wpseo_ai_json', { mode: 'read', json: raw }).then(function (res) {
        if (!res || !res.success) { say(jmsg, (res && res.data && res.data.message) || 'Error', 'bad'); return; }
        say(jmsg, '');
        reviewTable(jres, res.data, jmsg);
      }).catch(function () { say(jmsg, 'Error', 'bad'); });
    });
  }

  /* Review table shared by the JSON import and the AI suggestions (MCP):
     field · now · proposed, ticked where they differ, then fill the ticked
     fields into the form (the post is saved by WordPress as usual). */
  function reviewTable(box, d, msgEl, onFilled) {
    var fieldEl = function (key) { return document.getElementById(key); };
    var currentVal = function (el) { return el.type === 'checkbox' ? (el.checked ? '1' : '') : String(el.value || ''); };
    var out = '';
    if (d.post_mismatch) out += '<p class="is-bad">' + esc(fmt2(T.jsonMismatch || '%d', d.reply_post_id)) + '</p>';
    if (d.kind === 'jsonld') out += '<p>' + esc(T.jsonFromLd) + '</p>';
    (d.dropped || []).forEach(function (n) { out += '<p class="is-warn">' + esc(n) + '</p>'; });
    (d.notes || []).forEach(function (n) { out += '<p class="description">' + esc(n) + '</p>'; });
    var rows = '';
    var changed = 0;
    Object.keys(d.fields || {}).forEach(function (key) {
      var el = fieldEl(key);
      if (!el) return;
      var now = currentVal(el), nw = d.fields[key];
      var diff = now.trim() !== String(nw).trim();
      if (diff) changed++;
      rows += '<tr' + (diff ? '' : ' class="is-same"') + '><td><input type="checkbox" data-key="' + esc(key) + '"' + (diff ? ' checked' : ' disabled') + '></td>'
        + '<th>' + esc((d.labels || {})[key] || key) + '</th><td class="tqk-json-now">' + (now ? esc(now) : '<em>' + esc(T.empty) + '</em>') + '</td>'
        + '<td class="tqk-json-new">' + esc(nw) + '</td></tr>';
    });
    if (rows) {
      out += '<table class="widefat striped tqk-json-table"><thead><tr><th></th><th>' + esc(T.jsonField) + '</th><th>' + esc(T.jsonNow) + '</th><th>' + esc(T.jsonNew) + '</th></tr></thead><tbody>' + rows + '</tbody></table>';
      out += changed ? '<p><button type="button" class="button button-primary tqk-json-apply">' + esc(T.jsonApply) + '</button></p>' : '<p class="is-good">' + esc(T.jsonSame) + '</p>';
    }
    box.innerHTML = out;
    var apply = box.querySelector('.tqk-json-apply');
    if (!apply) return;
    apply.addEventListener('click', function () {
      var n = 0;
      // Schema type first: its change reveals the matching form.
      var boxes = Array.prototype.slice.call(box.querySelectorAll('input[data-key]:checked'));
      var isType = function (cb) { return cb.getAttribute('data-key') === '_tqk_wpseo_schema_type' ? 1 : 0; };
      boxes.sort(function (a, b) { return isType(b) - isType(a); });
      boxes.forEach(function (cb) {
        var key = cb.getAttribute('data-key');
        var el = fieldEl(key);
        if (!el) return;
        if (el.type === 'checkbox') el.checked = d.fields[key] === '1'; else el.value = d.fields[key];
        el.dispatchEvent(new Event(el.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true }));
        var wrap = el.closest('.tqk-wpseo-field');
        if (wrap) { wrap.classList.add('is-filled'); setTimeout(function () { wrap.classList.remove('is-filled'); }, 4000); }
        cb.checked = false;
        cb.disabled = true;
        n++;
      });
      dirty = true;
      refresh();
      liveAnalyze();
      validateSchema();
      say(msgEl, fmt2(T.jsonApplied || '%d', n), 'good');
      toast(fmt2(T.jsonApplied || '%d', n), 'good');
      apply.disabled = true;
      if (onFilled) onFilled(n);
    });
  }

  /* AI suggestions sent over MCP, waiting for approval: the advice, then
     the same review table. Filled values are saved with the post; the
     suggestion is marked used on that save, or dismissed here. */
  var review = root.querySelector('.tqk-ai-review');
  if (review) {
    var sdata = null;
    try { sdata = JSON.parse((review.querySelector('.tqk-ai-review-data') || {}).textContent || 'null'); } catch (e) { sdata = null; }
    var rmsg = review.querySelector('.tqk-ai-review-msg');
    if (sdata) {
      reviewTable(review.querySelector('.tqk-ai-review-fields'), { fields: sdata.fields || {}, labels: sdata.labels || {}, dropped: sdata.refused || [] }, rmsg, function () {
        var used = review.querySelector('input[name="tqk_wpseo_ai_applied"]');
        if (used) used.value = '1';
        toast(T.aiFilled || '', 'good');
      });
    }
    var dismiss = review.querySelector('.tqk-ai-dismiss');
    if (dismiss) {
      dismiss.addEventListener('click', function () {
        dismiss.disabled = true;
        post('tqk_wpseo_ai_suggestion', { op: 'dismiss' }).then(function (res) {
          if (res && res.success) { review.hidden = true; toast(T.aiDismissed || '', 'good'); } else { dismiss.disabled = false; }
        }, function () { dismiss.disabled = false; });
      });
    }
  }
})();
