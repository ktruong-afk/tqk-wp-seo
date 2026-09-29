'use strict';
/* TQK WP SEO — full-page snapshot of a same-origin iframe document.
 *
 * Browsers expose no "screenshot the whole page" API, so the page is
 * re-rendered: its DOM is cloned, its CSS and images are inlined as data
 * URIs, the result is wrapped in an SVG <foreignObject> and drawn to a
 * canvas. What this cannot reproduce, and the report should say so:
 *   - cross-origin images/fonts without CORS headers (left blank),
 *   - <iframe>, <video>, maps (drawn as grey boxes),
 *   - anything painted only by JS at draw time.
 * vh units are rewritten to px of the real viewport, otherwise a 100vh hero
 * would stretch to the full height of the snapshot.
 *
 * Public surface: window.TQKSnapshot = { capture(win, doc, opts) → Promise<dataURL> }
 */
(function () {
  var MAX_H = 16000;          // CSS px captured; taller pages are cut here
  var MAX_ASSETS = 250;       // unique CSS url() per page
  var FETCH_MS = 8000;
  var GREY = 'data:image/gif;base64,R0lGODlhAQABAIAAAMzMzAAAACwAAAAAAQABAAACAkQBADs=';

  function timeout(p, ms) {
    return Promise.race([p, new Promise(function (_, rej) {
      setTimeout(function () { rej(new Error('timeout')); }, ms);
    })]);
  }
  function toDataURL(blob) {
    return new Promise(function (res, rej) {
      var r = new FileReader();
      r.onload = function () { res(r.result); };
      r.onerror = rej;
      r.readAsDataURL(blob);
    });
  }
  function fetchBlob(url) {
    var ctl = window.AbortController ? new AbortController() : null;
    var t = setTimeout(function () { if (ctl) ctl.abort(); }, FETCH_MS);
    return fetch(url, { credentials: 'same-origin', signal: ctl ? ctl.signal : undefined })
      .then(function (r) {
        clearTimeout(t);
        if (!r.ok) throw new Error('http ' + r.status);
        return r.blob();
      }, function (e) { clearTimeout(t); throw e; });
  }
  /* Raster images are re-encoded at the size they are drawn: a 3000px photo
   * shown at 400px becomes a small WebP, which keeps the SVG light. */
  function shrink(blob, maxW) {
    if (!window.createImageBitmap || /svg/i.test(blob.type)) return toDataURL(blob);
    return createImageBitmap(blob).then(function (bmp) {
      var w = bmp.width, h = bmp.height;
      if (maxW > 0 && w > maxW) { h = Math.round(h * maxW / w); w = Math.round(maxW); }
      var c = document.createElement('canvas');
      c.width = Math.max(1, w);
      c.height = Math.max(1, h);
      c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
      if (bmp.close) bmp.close();
      return c.toDataURL('image/webp', 0.72);
    }).catch(function () { return toDataURL(blob); });
  }

  function Inliner() {
    var cache = {};
    this.image = function (url, maxW) {
      var k = url + '|' + Math.round(maxW || 0);
      if (!cache[k]) {
        cache[k] = fetchBlob(url)
          .then(function (b) { if (b.size > 8e6) throw new Error('big'); return shrink(b, maxW); })
          .catch(function () { return ''; });
      }
      return cache[k];
    };
    this.raw = function (url, maxBytes) {
      var k = 'raw|' + url;
      if (!cache[k]) {
        cache[k] = fetchBlob(url)
          .then(function (b) { if (b.size > maxBytes) throw new Error('big'); return toDataURL(b); })
          .catch(function () { return ''; });
      }
      return cache[k];
    };
  }

  var URL_RE = /url\(\s*(['"]?)([^'")]+)\1\s*\)/g;
  var FONT_RE = /\.(woff2?|ttf|otf)(\?|#|$)/i;

  function vhToPx(css, vh) {
    return css.replace(/(-?\d*\.?\d+)(?:d|s|l)?vh\b/g, function (_, n) {
      return (parseFloat(n) * vh / 100).toFixed(2) + 'px';
    });
  }

  /* Replace every url() in a CSS string with an inlined data URI. */
  function inlineUrls(css, base, inl, budget, imgMaxW) {
    var urls = {};
    css.replace(URL_RE, function (m, q, u) {
      u = u.trim();
      if (!/^(data:|#|about:)/i.test(u)) urls[u] = true;
      return m;
    });
    var list = Object.keys(urls);
    return Promise.all(list.map(function (u) {
      if (budget.left <= 0) return Promise.resolve([u, '']);
      budget.left--;
      var abs;
      try { abs = new URL(u, base).href; } catch (e) { return Promise.resolve([u, '']); }
      var p = FONT_RE.test(abs) ? inl.raw(abs, 800 * 1024) : inl.image(abs, imgMaxW);
      return p.then(function (d) { return [u, d]; });
    })).then(function (pairs) {
      var map = {};
      pairs.forEach(function (p) { map[p[0]] = p[1]; });
      return css.replace(URL_RE, function (m, q, u) {
        var d = map[u.trim()];
        return d ? 'url("' + d + '")' : (d === '' ? 'url("")' : m);
      });
    });
  }

  /* All stylesheet text of the document, in order, @import resolved. */
  function collectCss(doc) {
    function fromSheet(sheet) {
      var media = sheet.media && sheet.media.mediaText;
      var wrap = function (t) { return media && media !== 'all' ? '@media ' + media + '{' + t + '}' : t; };
      var rules;
      try { rules = sheet.cssRules; } catch (e) { rules = null; }
      if (!rules) {
        if (!sheet.href) return Promise.resolve('');
        return timeout(fetch(sheet.href).then(function (r) { return r.ok ? r.text() : ''; }), FETCH_MS)
          .then(function (t) { return { text: wrap(t), base: sheet.href }; })
          .catch(function () { return ''; });
      }
      var parts = [];
      for (var i = 0; i < rules.length; i++) {
        var r = rules[i];
        if (r.type === 3 && r.styleSheet) parts.push(fromSheet(r.styleSheet));
        else parts.push(Promise.resolve({ text: r.cssText, base: sheet.href || doc.baseURI }));
      }
      return Promise.all(parts).then(function (ps) {
        return ps.filter(Boolean).map(function (p) { return { text: wrap(p.text), base: p.base }; });
      });
    }
    var sheets = Array.prototype.filter.call(doc.styleSheets, function (s) { return !s.disabled; });
    return Promise.all(sheets.map(fromSheet)).then(function (all) {
      var flat = [];
      (function walk(a) {
        a.forEach(function (x) { if (Array.isArray(x)) walk(x); else if (x && x.text) flat.push(x); });
      })(all);
      return flat;
    });
  }

  /* Freeze animated elements in their current visual state: an SVG image
   * renders animations at t=0, where fade-in content is still invisible. */
  function freezeStyle(win, el) {
    var cs = win.getComputedStyle(el);
    if (!cs || cs.animationName === 'none') return '';
    return 'animation:none!important;transition:none!important;opacity:' + cs.opacity
      + '!important;transform:' + cs.transform + '!important;visibility:' + cs.visibility + '!important;';
  }

  function capture(win, doc, opts) {
    opts = opts || {};
    var vw = win.innerWidth;
    var vh = win.innerHeight;
    var scale = opts.scale || 1;
    var fullH = Math.max(doc.documentElement.scrollHeight, doc.body ? doc.body.scrollHeight : 0);
    var H = Math.min(fullH, MAX_H);
    var inl = new Inliner();
    var budget = { left: MAX_ASSETS };

    var clone = doc.documentElement.cloneNode(true);
    var origAll = doc.documentElement.querySelectorAll('*');
    var cloneAll = clone.querySelectorAll('*');
    var jobs = [];

    // Walk original + clone in lockstep (cloneNode keeps order).
    for (var i = 0; i < origAll.length && i < cloneAll.length; i++) {
      (function (o, c) {
        var tag = o.tagName;
        if (tag === 'SCRIPT' || tag === 'NOSCRIPT' || tag === 'TEMPLATE'
          || (tag === 'LINK' && /stylesheet|preload|prefetch/i.test(o.getAttribute('rel') || ''))
          || tag === 'STYLE' || tag === 'SOURCE') {
          c.setAttribute('data-tqk-drop', '1');
          return;
        }
        var r = o.getBoundingClientRect ? o.getBoundingClientRect() : null;
        var frozen = freezeStyle(win, o);
        if (frozen) c.setAttribute('style', (c.getAttribute('style') || '') + ';' + frozen);

        if (tag === 'IFRAME' || tag === 'VIDEO' || tag === 'EMBED' || tag === 'OBJECT') {
          var box = doc.createElement('div');
          box.setAttribute('style', 'display:' + (win.getComputedStyle(o).display === 'inline' ? 'inline-block' : win.getComputedStyle(o).display)
            + ';width:' + Math.round(r.width) + 'px;height:' + Math.round(r.height) + 'px;background:#d1d5db;');
          c.setAttribute('data-tqk-swap', '1');
          c.__tqkSwap = box;
          return;
        }
        if (tag === 'CANVAS') {
          try {
            var img = doc.createElement('img');
            img.setAttribute('src', o.toDataURL());
            img.setAttribute('style', 'width:' + Math.round(r.width) + 'px;height:' + Math.round(r.height) + 'px;');
            c.__tqkSwap = img;
            c.setAttribute('data-tqk-swap', '1');
          } catch (e) { /* tainted canvas: keep empty */ }
          return;
        }
        if (tag === 'INPUT') {
          if (o.type === 'checkbox' || o.type === 'radio') {
            if (o.checked) c.setAttribute('checked', ''); else c.removeAttribute('checked');
          } else c.setAttribute('value', o.value);
        }
        if (tag === 'TEXTAREA') c.textContent = o.value;
        if (tag === 'IMG') {
          var src = o.currentSrc || o.src || o.getAttribute('data-src') || o.getAttribute('data-lazy-src') || '';
          ['srcset', 'sizes', 'loading', 'decoding', 'data-src', 'data-srcset', 'data-lazy-src'].forEach(function (a) { c.removeAttribute(a); });
          var drawnW = Math.max(1, Math.round((r ? r.width : 0) * scale * 2));
          var keepBox = function () {
            if (r && r.width) c.setAttribute('style', (c.getAttribute('style') || '') + ';width:' + Math.round(r.width) + 'px;height:' + Math.round(r.height) + 'px;');
          };
          if (!src || /^data:/i.test(src)) return;
          c.setAttribute('src', GREY);
          jobs.push(inl.image(src, drawnW).then(function (d) {
            if (d) c.setAttribute('src', d); else keepBox();
          }));
        }
        var st = c.getAttribute('style');
        if (st && /url\(|vh\b/.test(st)) {
          jobs.push(inlineUrls(vhToPx(st, vh), doc.baseURI, inl, budget, vw * scale * 2).then(function (s) { c.setAttribute('style', s); }));
        }
      })(origAll[i], cloneAll[i]);
    }

    return collectCss(doc).then(function (sheets) {
      return Promise.all(sheets.map(function (s) {
        return inlineUrls(vhToPx(s.text, vh), s.base, inl, budget, vw * scale * 2);
      }));
    }).then(function (cssParts) {
      return Promise.all(jobs).then(function () { return cssParts; });
    }).then(function (cssParts) {
      Array.prototype.forEach.call(clone.querySelectorAll('[data-tqk-swap]'), function (c) {
        if (c.__tqkSwap && c.parentNode) c.parentNode.replaceChild(c.__tqkSwap, c);
      });
      Array.prototype.forEach.call(clone.querySelectorAll('[data-tqk-drop]'), function (c) {
        if (c.parentNode) c.parentNode.removeChild(c);
      });
      Array.prototype.forEach.call(clone.querySelectorAll('picture > source'), function (c) { c.parentNode.removeChild(c); });

      var head = clone.querySelector('head');
      if (!head) { head = doc.createElement('head'); clone.insertBefore(head, clone.firstChild); }
      while (head.firstChild) head.removeChild(head.firstChild);
      var style = doc.createElement('style');
      style.textContent = cssParts.join('\n')
        + '\nhtml{width:' + vw + 'px!important;height:auto!important;overflow:hidden!important;scroll-behavior:auto!important}';
      head.appendChild(style);
      // The scroll offset was reset before capture; keep the page at the top.
      clone.style.marginTop = '0';

      var xml = new XMLSerializer().serializeToString(clone);
      var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' + vw + '" height="' + H + '">'
        + '<foreignObject x="0" y="0" width="100%" height="100%">' + xml + '</foreignObject></svg>';
      return timeout(new Promise(function (res, rej) {
        var img = new Image();
        img.onload = function () { res(img); };
        img.onerror = function () { rej(new Error('svg render failed')); };
        img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
      }), 30000);
    }).then(function (img) {
      // One extra frame: fonts inside the SVG settle after onload.
      return new Promise(function (res) { setTimeout(function () { res(img); }, 120); });
    }).then(function (img) {
      var c = document.createElement('canvas');
      c.width = Math.round(vw * scale);
      c.height = Math.round(H * scale);
      var ctx = c.getContext('2d');
      ctx.fillStyle = '#fff';
      ctx.fillRect(0, 0, c.width, c.height);
      ctx.drawImage(img, 0, 0, c.width, c.height);
      return c.toDataURL('image/jpeg', 0.72);
    });
  }

  window.TQKSnapshot = { capture: capture, maxHeight: MAX_H };
})();
