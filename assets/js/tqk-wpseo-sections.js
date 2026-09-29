'use strict';
/* TQK WP SEO — section-by-section page check ("Detailed" scan mode).
 *
 * Walks the rendered page top to bottom, one section at a time: scrolls it
 * into view, outlines it, points at every image in it, checks each one and
 * reports right away. Slow on purpose: lazy content gets time to appear and
 * the person can follow what is being looked at.
 *
 * API: TQKSections.tour(win, doc, opts) → Promise<{sections, images, issues}>
 *        opts: { t, dwell, imgDwell, onSection(sec, i, n), onStep(txt), cancelled() }
 *      TQKSections.mobile(win, doc, t) → section-like result (no tour)
 *      TQKSections.html(sec, t) → HTML of one section result
 */
(function () {
  var CSS = ''
    + '.tqk-sec{border:1px solid #e2e8f0;border-radius:10px;margin:0 0 8px;background:#fff;overflow:hidden}'
    + '.tqk-sec>summary{list-style:none;cursor:pointer;display:flex;align-items:center;gap:8px;min-height:36px;padding:0 10px;font-size:13px;font-weight:600}'
    + '.tqk-sec>summary::-webkit-details-marker{display:none}'
    + '.tqk-sec-name{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
    + '.tqk-sec-idx{flex-shrink:0;font-size:11px;font-weight:700;color:#64748b;min-width:38px}'
    + '.tqk-sec-meta{flex-shrink:0;font-size:11px;color:#64748b;font-weight:500}'
    + '.tqk-sec-badge{flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:20px;padding:0 6px;border-radius:999px;font-size:11px;font-weight:800;box-sizing:border-box}'
    + '.tqk-sec-badge.is-ok{background:#ecfdf5;color:#059669}.tqk-sec-badge.is-warn{background:#fffbeb;color:#b45309}.tqk-sec-badge.is-bad{background:#fff1f2;color:#e11d48}'
    + '.tqk-sec-list{list-style:none;margin:0;padding:0 10px 8px}'
    + '.tqk-sec-list li{display:flex;align-items:center;gap:8px;min-height:24px;font-size:12px;line-height:1.4;padding:2px 0}'
    + '.tqk-sec-ico{flex-shrink:0;width:16px;text-align:center;font-weight:800}'
    + '.tqk-sec-list .is-ok .tqk-sec-ico{color:#059669}.tqk-sec-list .is-warn .tqk-sec-ico{color:#d97706}.tqk-sec-list .is-bad .tqk-sec-ico{color:#e11d48}.tqk-sec-list .is-info .tqk-sec-ico{color:#2563eb}'
    + '.tqk-sec-img{display:flex;align-items:center;gap:10px;padding:6px 10px;border-top:1px solid #f1f5f9}'
    + '.tqk-sec-img img{width:64px;height:40px;object-fit:cover;border-radius:6px;background:#f1f5f9;flex-shrink:0}'
    + '.tqk-sec-img-body{flex:1;min-width:0}'
    + '.tqk-sec-img-name{font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
    + '.tqk-sec-img-dim{font-size:11px;color:#64748b}'
    + '.tqk-sec-tags{display:flex;flex-wrap:wrap;gap:4px;margin-top:3px}'
    + '.tqk-sec-tag{display:inline-flex;align-items:center;height:18px;padding:0 7px;border-radius:999px;font-size:10.5px;font-weight:700}'
    + '.tqk-sec-tag.is-bad{background:#fff1f2;color:#e11d48}.tqk-sec-tag.is-warn{background:#fffbeb;color:#b45309}.tqk-sec-tag.is-info{background:#eff6ff;color:#2563eb}.tqk-sec-tag.is-ok{background:#ecfdf5;color:#059669}'
    /* dark variant inside the scan stage */
    + '.tqk-stage .tqk-sec{background:#0f172a;border-color:#1f2937;color:#e2e8f0}'
    + '.tqk-stage .tqk-sec-img{border-top-color:#1f2937}.tqk-stage .tqk-sec-meta,.tqk-stage .tqk-sec-idx,.tqk-stage .tqk-sec-img-dim{color:#94a3b8}'
    + '.tqk-stage .tqk-sec-img img{background:#1f2937}';

  function injectCss(d) {
    if (d.getElementById('tqk-sec-css')) return;
    var s = d.createElement('style');
    s.id = 'tqk-sec-css';
    s.textContent = CSS;
    (d.head || d.documentElement).appendChild(s);
  }

  function fmt(s) {
    var a = Array.prototype.slice.call(arguments, 1), i = 0;
    return String(s || '')
      .replace(/%(\d)\$[sd]/g, function (m, n) { return a[n - 1]; })
      .replace(/%[sd]/g, function () { return a[i++]; });
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
  function kb(b) { return b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.round(b / 1024) + ' KB'; }
  function qsa(root, sel) { return Array.prototype.slice.call(root.querySelectorAll(sel)); }

  /* Page top of an element (document coordinates). */
  function box(win, el) {
    var r = el.getBoundingClientRect();
    var y = win.scrollY || win.pageYOffset || 0;
    return { top: r.top + y, left: r.left, width: r.width, height: r.height, bottom: r.bottom + y };
  }

  /* ---------- sections ----------
   * Builders wrap sections differently (Elementor, Flatsome, Gutenberg,
   * plain themes), so: take every section-like block that spans most of the
   * width, drop wrappers that contain two or more of them, keep outermost of
   * the rest. Falls back to screen-high slices when the page has no such
   * structure. */
  var SEL = 'header, footer, section, article, main > div, .elementor-top-section, .elementor-section, .e-con.e-parent, '
    + '.wp-block-cover, .wp-block-group.alignfull, .wp-block-group.alignwide, .wp-block-columns.alignfull, .ux-section, .section, .banner, .row-full-width';

  function findSections(win, doc) {
    var vw = win.innerWidth || 1200;
    var list = qsa(doc.body, SEL).filter(function (el) {
      if (el.closest('#wpadminbar')) return false;
      var r = el.getBoundingClientRect();
      if (r.height < 80 || r.width < vw * 0.5) return false;
      var cs = win.getComputedStyle(el);
      return cs.display !== 'none' && cs.visibility !== 'hidden';
    });
    list = list.filter(function (el) {
      var inner = 0;
      for (var i = 0; i < list.length && inner < 2; i++) if (list[i] !== el && el.contains(list[i])) inner++;
      return inner < 2;
    });
    list = list.filter(function (el) {
      return !list.some(function (o) { return o !== el && o.contains(el); });
    });
    if (list.length < 2) list = structural(win, doc);
    var out = list.map(function (el) { var b = box(win, el); return { el: el, top: b.top, height: b.height }; });
    out.sort(function (a, b) { return a.top - b.top; });
    if (out.length < 2) {
      var vh = win.innerHeight || 800;
      var total = doc.documentElement.scrollHeight || vh;
      out = [];
      for (var y = 0; y < total && out.length < 12; y += vh) out.push({ el: null, top: y, height: Math.min(vh, total - y) });
    }
    return out.slice(0, 25);
  }

  /* Builder-agnostic fallback: from <body>, open every wrapper that holds
     most of the page and has several blocks inside, keep the blocks. */
  function structural(win, doc) {
    var vw = win.innerWidth || 1200;
    var pageH = doc.documentElement.scrollHeight || 1;
    function blocks(el) {
      return Array.prototype.filter.call(el.children, function (c) {
        if (/^(SCRIPT|STYLE|NOSCRIPT|TEMPLATE|LINK|META)$/.test(c.tagName) || c.id === 'wpadminbar') return false;
        var r = c.getBoundingClientRect();
        return r.height >= 80 && r.width >= vw * 0.5;
      });
    }
    function expand(el, depth) {
      var kids = blocks(el);
      if (depth > 10 || !kids.length) return [el];
      if (kids.length === 1) return expand(kids[0], depth + 1);
      var out = [];
      kids.forEach(function (k) {
        // Go through single-child wrappers (main > .container > .row …).
        var inner = k;
        for (var i = 0; i < 6 && blocks(inner).length === 1; i++) inner = blocks(inner)[0];
        var big = k.getBoundingClientRect().height > pageH * 0.5 && blocks(inner).length >= 2;
        out = out.concat(big ? expand(inner, depth + 1) : [k]);
      });
      return out;
    }
    var found = doc.body ? expand(doc.body, 0) : [];
    return found.length >= 2 ? found : [];
  }

  function sectionName(sec, i) {
    var el = sec.el;
    if (el) {
      var h = el.querySelector('h1, h2, h3, h4');
      var txt = h ? (h.textContent || '').replace(/\s+/g, ' ').trim() : '';
      if (txt) return txt.length > 48 ? txt.slice(0, 48) + '…' : txt;
      var tag = el.tagName.toLowerCase();
      if (tag === 'header' || tag === 'footer') return tag;
      // Builder classes usually say what the block is: header, banner, hero…
      var cls = typeof el.className === 'string' ? el.className : '';
      var kind = /\b[\w-]*?(header|footer|banner|hero|slider|gallery|testimonial|pricing|contact|faq|menu|product|blog|cta)\b/i.exec(cls);
      if (kind) return kind[1].toLowerCase();
      if (el.id) return tag + '#' + el.id;
      var first = cls.trim().split(/\s+/)[0];
      if (first) return first.replace(/^(op|elementor|wp-block|et_pb|vc|fl)-/, '').replace(/[-_]+/g, ' ');
    }
    return '#' + (i + 1);
  }

  function inSection(win, sec, el) {
    if (sec.el) return sec.el.contains(el);
    var b = box(win, el);
    var mid = b.top + b.height / 2;
    return mid >= sec.top && mid < sec.top + sec.height;
  }

  /* ---------- overlay drawn inside the scanned page ---------- */
  function overlay(doc, id, color) {
    var o = doc.getElementById(id);
    if (!o) {
      o = doc.createElement('div');
      o.id = id;
      o.setAttribute('aria-hidden', 'true');
      o.style.cssText = 'position:absolute;z-index:2147483646;pointer-events:none;box-sizing:border-box;border:3px solid ' + color
        + ';background:' + color.replace('rgb', 'rgba').replace(')', ',.08)') + ';border-radius:6px;transition:all .35s ease;left:0;top:0;width:0;height:0';
      var tag = doc.createElement('span');
      tag.style.cssText = 'position:absolute;left:-3px;top:-26px;background:' + color + ';color:#fff;font:600 12px/24px -apple-system,Segoe UI,Roboto,sans-serif;padding:0 10px;border-radius:6px 6px 0 0;white-space:nowrap;max-width:600px;overflow:hidden;text-overflow:ellipsis';
      o.appendChild(tag);
      doc.body.appendChild(o);
    }
    return o;
  }
  function place(win, o, b, label) {
    o.style.left = Math.max(0, b.left - 4) + 'px';
    o.style.top = Math.max(26, b.top - 4) + 'px';
    o.style.width = (b.width + 8) + 'px';
    o.style.height = (b.height + 8) + 'px';
    o.style.display = 'block';
    o.firstChild.textContent = label || '';
  }
  function hide(doc, id) {
    var o = doc.getElementById(id);
    if (o && o.parentNode) o.parentNode.removeChild(o);
  }
  function scrollToY(win, y) {
    try { win.scrollTo({ top: Math.max(0, y), behavior: 'smooth' }); } catch (e) { win.scrollTo(0, Math.max(0, y)); }
  }

  /* ---------- image checks ---------- */
  function bytesOf(win, src) {
    try {
      var list = win.performance.getEntriesByName(src);
      for (var i = 0; i < list.length; i++) {
        var b = list[i].transferSize || list[i].encodedBodySize || 0;
        if (b > 0) return b;
      }
    } catch (e) { /* ignore */ }
    return 0;
  }
  function loadSize(src) {
    return new Promise(function (res) {
      var im = new Image();
      var t = setTimeout(function () { res(null); }, 4000);
      im.onload = function () { clearTimeout(t); res({ w: im.naturalWidth, h: im.naturalHeight }); };
      im.onerror = function () { clearTimeout(t); res(null); };
      im.src = src;
    });
  }
  function fileName(src) {
    var s = String(src || '').split('?')[0];
    return decodeURIComponent(s.slice(s.lastIndexOf('/') + 1)) || s;
  }

  /* Rules for one image as rendered. first = in the first screen. */
  function judge(win, info, T, first, mobile) {
    var tags = [];
    var shown = info.shown, nat = info.natural;
    if (info.broken) { tags.push({ sev: 'bad', msg: T.broken || 'Image does not load' }); return tags; }
    if (!info.bg && info.alt === null) tags.push({ sev: 'warn', msg: T.noAlt || 'No ALT' });
    if (shown > 0 && nat > 0) {
      var ratio = mobile ? 3 : 2.2;
      if (nat > shown * ratio && nat > 800) tags.push({ sev: 'warn', msg: fmt(mobile ? (T.mobileBig || 'Mobile: %1$dpx file shown at %2$dpx') : (T.oversized || '%1$dpx file shown at %2$dpx'), nat, shown) });
      else if (!mobile && nat < shown * 0.9 && shown > 120) tags.push({ sev: 'warn', msg: fmt(T.upscaled || '%1$dpx file stretched to %2$dpx: blurry', nat, shown) });
    }
    if (!info.bg && !info.dims && shown > 60) tags.push({ sev: 'info', msg: T.noDims || 'No width/height' });
    if (info.bytes > 800 * 1024) tags.push({ sev: 'bad', msg: fmt(T.heavy || 'Heavy: %s', kb(info.bytes)) });
    else if (info.bytes > 300 * 1024) tags.push({ sev: 'warn', msg: fmt(T.heavy || 'Heavy: %s', kb(info.bytes)) });
    if (/\.(jpe?g|png)(\?|$)/i.test(info.src) && info.bytes > 100 * 1024) tags.push({ sev: 'info', msg: fmt(T.webp || 'WebP would be lighter (%s)', kb(info.bytes)) });
    if (!info.bg && !mobile) {
      if (first && info.lazy) tags.push({ sev: 'warn', msg: T.lazyTop || 'Lazy-loaded in the first screen' });
      if (!first && !info.lazy && info.bytes > 60 * 1024) tags.push({ sev: 'info', msg: T.noLazy || 'Not lazy-loaded' });
    }
    return tags;
  }

  function imgInfo(win, im) {
    var r = im.getBoundingClientRect();
    var src = im.currentSrc || im.src || '';
    var cls = (im.className && im.className.baseVal === undefined ? im.className : '') || '';
    return {
      el: im,
      src: src,
      bg: false,
      alt: im.hasAttribute('alt') && (im.getAttribute('alt') || '').trim() !== '' ? im.getAttribute('alt') : (im.getAttribute('role') === 'presentation' || im.getAttribute('aria-hidden') === 'true' ? '' : null),
      shown: Math.round(r.width),
      natural: im.naturalWidth || 0,
      broken: im.complete && src !== '' && !/^data:/.test(src) && im.naturalWidth === 0,
      dims: im.hasAttribute('width') && im.hasAttribute('height'),
      lazy: im.getAttribute('loading') === 'lazy' || /lazy/i.test(cls) || im.hasAttribute('data-src') || im.hasAttribute('data-lazy-src'),
      bytes: bytesOf(win, src)
    };
  }

  function bgImages(win, root, limit) {
    var out = [];
    var nodes = root ? [root].concat(qsa(root, '*')) : [];
    for (var i = 0; i < nodes.length && out.length < limit; i++) {
      var el = nodes[i];
      if (el.tagName === 'IMG' || el.id === 'tqk-sec-ovl' || el.id === 'tqk-img-ovl') continue;
      var bgi = win.getComputedStyle(el).backgroundImage || '';
      var m = bgi.match(/url\(["']?([^"')]+)["']?\)/);
      if (!m || /^data:/.test(m[1])) continue;
      var r = el.getBoundingClientRect();
      if (r.width < 120 || r.height < 60) continue;
      out.push({ el: el, src: m[1], bg: true, alt: '', shown: Math.round(r.width), natural: 0, broken: false, dims: true, lazy: false, bytes: 0 });
    }
    return out;
  }

  function visibleImgs(root) {
    return qsa(root, 'img').filter(function (im) {
      var r = im.getBoundingClientRect();
      return r.width >= 24 && r.height >= 24;
    });
  }

  /* ---------- tour ---------- */
  function tour(win, doc, opts) {
    opts = opts || {};
    var T = opts.t || {};
    var dwell = opts.dwell || 1100;
    var imgDwell = opts.imgDwell || 550;
    var cancelled = opts.cancelled || function () { return false; };
    var vh = win.innerHeight || 800;
    var secs = findSections(win, doc);
    var results = [];
    var secO = overlay(doc, 'tqk-sec-ovl', 'rgb(99,102,241)');
    var imgO = overlay(doc, 'tqk-img-ovl', 'rgb(245,158,11)');
    imgO.style.display = 'none';
    var seen = [];

    function one(i) {
      if (i >= secs.length || cancelled()) return Promise.resolve();
      var sec = secs[i];
      var name = sectionName(sec, i);
      var label = fmt(T.part || 'Part %1$d/%2$d', i + 1, secs.length) + ' · ' + name;
      if (opts.onStep) opts.onStep(label);
      scrollToY(win, sec.top - 60);
      return wait(500).then(function () {
        var b = sec.el ? box(win, sec.el) : { top: sec.top, left: 0, width: win.innerWidth, height: sec.height };
        place(win, secO, b, label);
        return wait(dwell);
      }).then(function () {
        var root = sec.el || doc.body;
        var imgs = visibleImgs(root).filter(function (im) { return seen.indexOf(im) < 0 && (sec.el || inSection(win, sec, im)); });
        imgs.forEach(function (im) { seen.push(im); });
        var infos = imgs.slice(0, 20).map(function (im) { return imgInfo(win, im); });
        var bgs = sec.el ? bgImages(win, sec.el, 6) : [];
        var res = { name: name, index: i, total: secs.length, images: [], issues: [], words: 0 };
        var text = sec.el ? (sec.el.innerText || '') : '';
        res.words = text.trim() ? text.trim().split(/\s+/).length : 0;
        if (sec.el) {
          var empty = qsa(sec.el, 'a[href]').filter(function (a) {
            return !(a.textContent || '').trim() && !a.getAttribute('aria-label') && !a.getAttribute('title') && !a.querySelector('img[alt]:not([alt=""])');
          }).length;
          if (empty) res.issues.push({ sev: 'warn', code: 'emptyLink', count: empty, msg: fmt(T.emptyLink || '%d link(s) without text', empty) });
        }
        var all = infos.concat(bgs);
        var j = 0;
        function nextImg() {
          if (j >= all.length || cancelled()) return Promise.resolve();
          var info = all[j++];
          if (opts.onStep) opts.onStep(label + ' — ' + fmt(T.imgCheck || 'image %1$d/%2$d', j, all.length));
          var ib = box(win, info.el);
          if (ib.top < (win.scrollY || 0) + 40 || ib.bottom > (win.scrollY || 0) + vh - 40) scrollToY(win, ib.top - vh / 3);
          return wait(250).then(function () {
            place(win, imgO, box(win, info.el), (info.bg ? (T.bg || 'Background') + ' · ' : '') + fileName(info.src));
            return info.bg ? loadSize(info.src) : null;
          }).then(function (sz) {
            if (info.bg) { info.natural = sz ? sz.w : 0; info.broken = !sz; info.bytes = bytesOf(win, info.src); }
            var first = ib.top < vh;
            info.tags = judge(win, info, T, first, false);
            res.images.push({ src: info.src, name: fileName(info.src), bg: info.bg, shown: info.shown, natural: info.natural, bytes: info.bytes, tags: info.tags });
            return wait(info.tags.length ? imgDwell + 250 : imgDwell);
          }).then(nextImg);
        }
        return nextImg().then(function () {
          imgO.style.display = 'none';
          results.push(res);
          if (opts.onSection) opts.onSection(res, i, secs.length);
          return one(i + 1);
        });
      });
    }

    return one(0).then(function () {
      hide(doc, 'tqk-sec-ovl');
      hide(doc, 'tqk-img-ovl');
      try { win.scrollTo(0, 0); } catch (e) { /* ignore */ }
      return summary(results);
    }, function (e) {
      hide(doc, 'tqk-sec-ovl');
      hide(doc, 'tqk-img-ovl');
      throw e;
    });
  }

  /* Mobile: no tour, only what changes on a phone (oversized files). */
  function mobile(win, doc, T) {
    var res = { name: T.mobile || 'Mobile', index: -1, total: 0, images: [], issues: [], words: 0, mobile: true };
    visibleImgs(doc.body).slice(0, 60).forEach(function (im) {
      var info = imgInfo(win, im);
      var tags = judge(win, info, T, false, true).filter(function (t) { return t.sev !== 'info'; });
      if (tags.length) res.images.push({ src: info.src, name: fileName(info.src), shown: info.shown, natural: info.natural, bytes: info.bytes, tags: tags });
    });
    return res;
  }

  function counts(sec) {
    var bad = 0, warn = 0;
    sec.issues.forEach(function (i) { if (i.sev === 'bad') bad++; else if (i.sev === 'warn') warn++; });
    sec.images.forEach(function (im) { im.tags.forEach(function (t) { if (t.sev === 'bad') bad++; else if (t.sev === 'warn') warn++; }); });
    return { bad: bad, warn: warn };
  }

  function summary(sections) {
    var images = 0, issues = 0;
    sections.forEach(function (s) { images += s.images.length; var c = counts(s); issues += c.bad + c.warn; });
    return { sections: sections, images: images, issues: issues };
  }

  function html(sec, T) {
    T = T || {};
    var c = counts(sec);
    var tone = c.bad ? 'bad' : (c.warn ? 'warn' : 'ok');
    var h = '<details class="tqk-sec"' + (c.bad || c.warn ? ' open' : '') + '><summary>'
      + (sec.index >= 0 ? '<span class="tqk-sec-idx">' + (sec.index + 1) + '/' + sec.total + '</span>' : '')
      + '<span class="tqk-sec-name">' + esc(sec.name) + '</span>'
      + '<span class="tqk-sec-meta">' + (sec.mobile || !sec.words ? '' : esc(fmt(T.words || '%d words', sec.words)) + ' · ') + esc(fmt(T.images || '%d images', sec.images.length)) + '</span>'
      + '<span class="tqk-sec-badge is-' + tone + '">' + (c.bad + c.warn || '✓') + '</span></summary>';
    var list = sec.issues.map(function (i) {
      return '<li class="is-' + i.sev + '"><span class="tqk-sec-ico">' + (i.sev === 'bad' ? '✗' : '!') + '</span><span>' + esc(i.msg) + '</span></li>';
    }).join('');
    if (!sec.issues.length && !sec.images.some(function (im) { return im.tags.length; })) {
      list = '<li class="is-ok"><span class="tqk-sec-ico">✓</span><span>' + esc(T.ok || 'No problem found') + '</span></li>';
    }
    if (list) h += '<ul class="tqk-sec-list">' + list + '</ul>';
    sec.images.forEach(function (im) {
      if (!im.tags.length && sec.images.length > 6) return; // long lists: problems only
      var tags = im.tags.length
        ? im.tags.map(function (t) { return '<span class="tqk-sec-tag is-' + t.sev + '">' + esc(t.msg) + '</span>'; }).join('')
        : '<span class="tqk-sec-tag is-ok">✓ OK</span>';
      h += '<div class="tqk-sec-img"><img src="' + esc(im.src) + '" alt="" loading="lazy"><div class="tqk-sec-img-body">'
        + '<div class="tqk-sec-img-name">' + (im.bg ? esc(T.bg || 'Background') + ' · ' : '') + esc(im.name) + '</div>'
        + '<div class="tqk-sec-img-dim">' + (im.natural || '?') + 'px → ' + (im.shown || '?') + 'px' + (im.bytes ? ' · ' + kb(im.bytes) : '') + '</div>'
        + '<div class="tqk-sec-tags">' + tags + '</div></div></div>';
    });
    return h + '</details>';
  }

  injectCss(document);
  window.TQKSections = { tour: tour, mobile: mobile, html: html, find: findSections };
})();
