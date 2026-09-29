'use strict';
/* TQK WP SEO — Responsive Audit engine.
 *
 * Runs the real page inside a sized IFRAME, not the admin window. Resizing
 * window.resizeTo() is blocked and would wreck the admin layout; an iframe
 * at 375px activates the site's real @media rules, which is the only honest
 * way to measure a breakpoint. Zero server calls for the measurement pass.
 *
 * Public surface: window.TQKResponsive = { run, viewports, version }
 */
(function () {
  var VIEWPORTS = [
    { key: 'desktop', w: 1440, h: 900, label: 'Desktop 1440' },
    { key: 'laptop', w: 1280, h: 800, label: 'Laptop 1280' },
    { key: 'tablet', w: 768, h: 1024, label: 'Tablet 768' },
    { key: 'mobile', w: 375, h: 812, label: 'Mobile 375' }
  ];

  /* ---------- measurement, runs inside the iframe document ---------- */
  function measure(win, doc) {
    var vw = win.innerWidth;
    var vh = win.innerHeight;
    var out = {
      vw: vw,
      scrollW: doc.documentElement.scrollWidth,
      scrollH: doc.documentElement.scrollHeight,
      overflowX: 0,
      offenders: [],
      tinyText: [],
      smallTap: [],
      missingDims: [],
      oversized: []
    };
    out.overflowX = Math.max(0, out.scrollW - vw);

    // A root cause is a shared ancestor + the property that actually broke.
    function causeOf(el) {
      var computed = win.getComputedStyle(el);
      var node = el;
      var chain = [];
      while (node && node.nodeType === 1 && node !== doc.body) {
        var cs = win.getComputedStyle(node);
        var sel = node.tagName.toLowerCase();
        if (node.id) sel += '#' + node.id;
        else if (node.classList && node.classList.length) {
          sel += '.' + Array.prototype.slice.call(node.classList, 0, 2).join('.');
        }
        chain.push({ sel: sel, display: cs.display, minWidth: cs.minWidth, width: cs.width });
        if (cs.display.indexOf('grid') >= 0 || cs.display.indexOf('flex') >= 0) {
          return {
            root: sel,
            kind: cs.display,
            reason: 'flex/grid child without min-width:0 can exceed its track',
            chain: chain
          };
        }
        if (cs.overflow === 'hidden' || cs.overflowX === 'hidden') {
          return { root: sel, kind: cs.display, reason: 'overflow:hidden is clipping instead of fixing', chain: chain };
        }
        node = node.parentElement;
      }
      return { root: chain.length ? chain[0].sel : (el.tagName || '?'), kind: 'block', reason: 'fixed width exceeds container', chain: chain };
    }

    var all = doc.body ? doc.body.querySelectorAll('*') : [];
    Array.prototype.forEach.call(all, function (el) {
      if (el.closest && el.closest('#tqk-rs-host')) return;
      var r = el.getBoundingClientRect();
      if (!r.width && !r.height) return;

      // 1. horizontal overflow: the element is wider than the screen.
      if (r.width > vw + 2 || r.right > vw + 2) {
        out.offenders.push({
          sel: el.tagName.toLowerCase() + (el.id ? '#' + el.id : (el.classList && el.classList.length ? '.' + Array.prototype.slice.call(el.classList, 0, 2).join('.') : '')),
          width: Math.round(r.width),
          right: Math.round(r.right),
          cause: causeOf(el)
        });
      }

      // 2. text smaller than 12px.
      var text = (el.textContent || '').trim();
      var leafText = text && !Array.prototype.some.call(el.children, function (c) {
        return (c.textContent || '').trim().length > 0;
      });
      if (leafText) {
        var fs = parseFloat(win.getComputedStyle(el).fontSize);
        if (fs && fs < 12) {
          out.tinyText.push({ sel: el.tagName.toLowerCase(), px: Math.round(fs * 10) / 10, sample: text.slice(0, 40) });
        }
      }

      // 3. interactive target under 44px on touch-sized screens.
      if (vw <= 768 && (el.tagName === 'A' || el.tagName === 'BUTTON' || el.getAttribute('role') === 'button')) {
        if (r.width < 44 || r.height < 44) {
          out.smallTap.push({ sel: el.tagName.toLowerCase() + (el.classList && el.classList.length ? '.' + Array.prototype.slice.call(el.classList, 0, 1).join('.') : ''), w: Math.round(r.width), h: Math.round(r.height), label: text.slice(0, 30) });
        }
      }
    });

    // 4. images: missing dimensions, and absurdly large files.
    Array.prototype.forEach.call(doc.querySelectorAll('img'), function (img) {
      if (img.closest && img.closest('#tqk-rs-host')) return;
      if (!img.getAttribute('width') || !img.getAttribute('height')) {
        out.missingDims.push({ src: (img.currentSrc || img.src || '').split('/').pop().slice(0, 48) });
      }
      var b = img.getBoundingClientRect();
      var shown = Math.round(b.width);
      var nat = img.naturalWidth || 0;
      if (shown > 0 && nat > shown * 2) {
        out.oversized.push({ src: (img.currentSrc || img.src || '').split('/').pop().slice(0, 48), natural: nat, shown: shown });
      }
    });

    out.images = measureImages(win, doc);
    out.type = typeCheck(win, doc);
    return out;
  }

  /* ---------- typography: sizes, line height, line length, contrast ----------
   * Measured on what the browser actually draws at this screen width. Every
   * finding carries the value to change to and the CSS for it. */
  function selOf(el) {
    var s = el.tagName.toLowerCase();
    if (el.id && /^[A-Za-z][\w-]*$/.test(el.id)) return s + '#' + el.id;
    var cls = typeof el.className === 'string'
      ? el.className.trim().split(/\s+/).filter(function (c) { return c && !/^(is-|has-|wp-image-)/.test(c); }).slice(0, 2)
      : [];
    return s + (cls.length ? '.' + cls.join('.') : '');
  }
  function rgb(c) {
    var m = /rgba?\(([\d.]+),\s*([\d.]+),\s*([\d.]+)(?:,\s*([\d.]+))?/.exec(c || '');
    return m ? { r: +m[1], g: +m[2], b: +m[3], a: m[4] === undefined ? 1 : +m[4] } : null;
  }
  function lum(c) {
    var f = function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
    return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b);
  }
  function contrast(a, b) {
    var x = lum(a), y = lum(b);
    return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
  }
  function hex(c) {
    return '#' + [c.r, c.g, c.b].map(function (v) { return ('0' + Math.round(v).toString(16)).slice(-2); }).join('');
  }
  /* Solid background behind an element; null when an image / gradient is behind it. */
  function bgOf(win, el) {
    for (var n = el; n && n.nodeType === 1; n = n.parentElement) {
      var cs = win.getComputedStyle(n);
      if (cs.backgroundImage && cs.backgroundImage !== 'none') return null;
      var c = rgb(cs.backgroundColor);
      if (c && c.a >= 0.95) return c;
    }
    return { r: 255, g: 255, b: 255, a: 1 };
  }
  /* Same hue, moved toward black (light background) or white, until readable. */
  function fixColor(fg, bg, need) {
    var toDark = lum(bg) > 0.4;
    var c = { r: fg.r, g: fg.g, b: fg.b };
    for (var i = 0; i < 40 && contrast(c, bg) < need; i++) {
      c = toDark
        ? { r: c.r * 0.92, g: c.g * 0.92, b: c.b * 0.92 }
        : { r: c.r + (255 - c.r) * 0.12, g: c.g + (255 - c.g) * 0.12, b: c.b + (255 - c.b) * 0.12 };
    }
    return hex(c);
  }

  function typeCheck(win, doc) {
    var vw = win.innerWidth;
    var phone = vw <= 480;
    var out = [];
    var seen = {};
    function push(o) {
      var k = o.kind + '|' + o.sel;
      if (seen[k] || out.length >= 14) return;
      seen[k] = 1;
      out.push(o);
    }
    var nodes = doc.body ? doc.body.querySelectorAll('p, li, td, dd, blockquote, h1, h2, h3, a, span, div, label, button') : [];
    var bodyChars = {};
    var n = 0;
    Array.prototype.some.call(nodes, function (el) {
      if (++n > 2500) return true;
      if (el.closest && el.closest('#tqk-rs-host, #wpadminbar, #tqk-sec-ovl, #tqk-img-ovl')) return false;
      // Own text only: wrappers whose text lives in child elements are skipped.
      var own = '';
      for (var c = el.firstChild; c; c = c.nextSibling) if (c.nodeType === 3) own += c.nodeValue;
      own = own.replace(/\s+/g, ' ').trim();
      if (own.length < 12) return false;
      var r = el.getBoundingClientRect();
      if (r.width < 40 || r.height < 8) return false;
      var cs = win.getComputedStyle(el);
      if (cs.visibility === 'hidden' || cs.display === 'none' || parseFloat(cs.opacity) < 0.3) return false;
      var fs = parseFloat(cs.fontSize) || 16;
      var lh = cs.lineHeight === 'normal' ? fs * 1.2 : (parseFloat(cs.lineHeight) || fs * 1.2);
      var tag = el.tagName;
      var sel = selOf(el);
      var sample = own.slice(0, 50);
      if (/^H[1-3]$/.test(tag)) {
        var lines = Math.max(1, Math.round(r.height / lh));
        if (phone && tag === 'H1' && (fs > 36 || (fs > 28 && lines >= 4))) {
          push({ kind: 'hBig', sel: sel, tag: tag, px: Math.round(fs), lines: lines, to: 28, css: sel + ' { font-size: clamp(24px, 7vw, ' + Math.round(fs) + 'px); }', sample: sample });
        } else if (phone && tag === 'H2' && fs > 30) {
          push({ kind: 'hBig', sel: sel, tag: tag, px: Math.round(fs), lines: lines, to: 24, css: sel + ' { font-size: clamp(20px, 6vw, ' + Math.round(fs) + 'px); }', sample: sample });
        } else if (vw >= 1024 && tag === 'H1' && fs < 24) {
          push({ kind: 'hSmall', sel: sel, tag: tag, px: Math.round(fs), to: 32, css: sel + ' { font-size: 32px; }', sample: sample });
        }
      } else if (own.length >= 40) {
        bodyChars[Math.round(fs)] = (bodyChars[Math.round(fs)] || 0) + own.length;
        if (fs < 14 && tag !== 'A' && tag !== 'BUTTON' && tag !== 'LABEL') {
          push({ kind: 'bodySmall', sel: sel, px: Math.round(fs * 10) / 10, to: 16, css: sel + ' { font-size: 16px; }', sample: sample });
        } else if (phone && fs > 20 && tag === 'P') {
          push({ kind: 'bodyBig', sel: sel, px: Math.round(fs), to: 17, css: sel + ' { font-size: 17px; }', sample: sample });
        }
        if (lh / fs < 1.3 && own.length > 80 && r.height > lh * 1.5) {
          push({ kind: 'lh', sel: sel, px: Math.round(lh / fs * 100) / 100, to: 1.5, css: sel + ' { line-height: 1.5; }', sample: sample });
        }
        // Characters per line: width / 0.5em is a fair average for Latin script.
        var cpl = Math.round(r.width / (fs * 0.5));
        if (!phone && tag === 'P' && cpl > 100 && r.height > lh * 2.5) {
          push({ kind: 'measure', sel: sel, px: cpl, to: 75, css: sel + ' { max-width: 70ch; }', sample: sample });
        }
      }
      var fg = rgb(cs.color);
      var bg = fg ? bgOf(win, el) : null;
      if (fg && bg && fg.a > 0.5) {
        var large = fs >= 24 || (fs >= 18.66 && parseInt(cs.fontWeight, 10) >= 700);
        var need = large ? 3 : 4.5;
        var cr = contrast(fg, bg);
        if (cr < need) {
          var to = fixColor(fg, bg, need);
          push({ kind: 'contrast', sel: sel, px: Math.round(cr * 10) / 10, need: need, from: hex(fg), bg: hex(bg), to: to, css: sel + ' { color: ' + to + '; }', sample: sample });
        }
      }
      return false;
    });
    // Main reading size: the size most body characters are set in.
    var main = 0, best = 0;
    Object.keys(bodyChars).forEach(function (k) { if (bodyChars[k] > best) { best = bodyChars[k]; main = +k; } });
    if (main && main < 15) out.unshift({ kind: 'mainSmall', sel: 'body', px: main, to: 16, css: 'body, p, li { font-size: 16px; }', sample: '' });
    return out;
  }

  /* ---------- per-image geometry for the image optimizer ----------
   * Everything the server needs to size files without guessing: the box the
   * image is drawn in, the pixels of the file actually downloaded, how the
   * box crops it (object-fit / object-position), and whether the markup
   * already has srcset/sizes. Builder-agnostic: plain <img> + CSS backgrounds. */
  /* Hook for the background override stylesheet: the element's id, or
   * Elementor's per-element class. '' when neither exists (report only). */
  function bgSelector(el) {
    if (el.id && /^[A-Za-z][\w-]*$/.test(el.id)) return '#' + el.id;
    var cls = typeof el.className === 'string' ? el.className : '';
    var m = /(?:^|\s)(elementor-element-[a-z0-9]{3,20})(?:\s|$)/.exec(cls);
    return m ? '.' + m[1] : '';
  }

  function measureImages(win, doc) {
    var list = [];
    Array.prototype.forEach.call(doc.querySelectorAll('img'), function (img) {
      if (list.length >= 60) return;
      var r = img.getBoundingClientRect();
      if (r.width < 24 || r.height < 24) return;
      var cs = win.getComputedStyle(img);
      if (cs.visibility === 'hidden' || cs.display === 'none') return;
      var cls = (img.className && img.className.baseVal === undefined ? img.className : '') || '';
      var m = /(?:^|\s)wp-image-(\d+)(?:\s|$)/.exec(cls);
      list.push({
        kind: 'img',
        src: img.getAttribute('src') || '',
        cur: img.currentSrc || img.src || '',
        att: m ? parseInt(m[1], 10) : parseInt(img.getAttribute('data-id') || img.getAttribute('data-attachment-id') || '0', 10) || 0,
        nw: img.naturalWidth || 0,
        nh: img.naturalHeight || 0,
        w: Math.round(r.width),
        h: Math.round(r.height),
        fit: cs.objectFit || 'fill',
        pos: cs.objectPosition || '50% 50%',
        srcset: img.hasAttribute('srcset'),
        // sizes only matters for width descriptors (800w), not density (2x).
        wdesc: /\s\d+w\s*(,|$)/.test(img.getAttribute('srcset') || ''),
        sizes: (img.getAttribute('sizes') || '').slice(0, 200)
      });
    });
    // Background images: measured and reported. Whether they can be fixed
    // depends on where the builder writes the CSS (inline vs a .css file).
    Array.prototype.forEach.call(doc.body ? doc.body.querySelectorAll('*') : [], function (el) {
      if (list.length >= 80) return;
      var bg = win.getComputedStyle(el).backgroundImage;
      if (!bg || bg.indexOf('url(') < 0 || bg.indexOf('gradient') >= 0) return;
      var u = /url\(["']?([^"')]+)["']?\)/.exec(bg);
      var r = el.getBoundingClientRect();
      if (!u || r.width < 120 || r.height < 60 || /^data:/.test(u[1])) return;
      list.push({
        kind: 'bg',
        src: u[1],
        cur: u[1],
        att: 0,
        nw: 0,
        nh: 0,
        w: Math.round(r.width),
        h: Math.round(r.height),
        fit: win.getComputedStyle(el).backgroundSize || 'auto',
        pos: win.getComputedStyle(el).backgroundPosition || '50% 50%',
        srcset: false,
        sizes: '',
        inline: /background/.test(el.getAttribute('style') || ''),
        sel: bgSelector(el)
      });
    });
    return list;
  }

  /* ---------- group findings by root cause, across ALL viewports ----------
   * Aggregating only the worst viewport is a trap: tap-target and tiny-text
   * failures live at 375px specifically, so a tablet that happens to overflow
   * more would hide every mobile finding. Merge everything, and label which
   * viewport each root cause showed up in. */
  function group(list) {
    var buckets = {};
    list.forEach(function (m) {
      m.offenders.forEach(function (o) {
        var key = o.cause.root + '|' + o.cause.kind;
        if (!buckets[key]) {
          buckets[key] = { root: o.cause.root, kind: o.cause.kind, reason: o.cause.reason, count: 0, samples: [], viewports: [] };
        }
        buckets[key].count++;
        if (buckets[key].samples.length < 5) buckets[key].samples.push(o.sel);
        if (buckets[key].viewports.indexOf(m.label) < 0) buckets[key].viewports.push(m.label);
      });
    });
    return Object.keys(buckets).map(function (k) { return buckets[k]; })
      .sort(function (a, b) { return b.count - a.count; });
  }

  function score(m) {
    var s = 100;
    if (m.overflowX > 0) s -= Math.min(45, 15 + m.overflowX / 4);
    s -= Math.min(20, m.tinyText.length * 2);
    s -= Math.min(15, m.smallTap.length * 3);
    s -= Math.min(12, m.missingDims.length * 2);
    s -= Math.min(8, m.oversized.length * 2);
    return Math.max(0, Math.round(s));
  }

  /* ---------- SEO: tags + JSON-LD, read from the rendered document ---------- */
  // Required properties Google needs before a rich result is even considered.
  var SCHEMA_REQUIRED = {
    Article: ['headline', 'image', 'datePublished', 'author'],
    BlogPosting: ['headline', 'image', 'datePublished', 'author'],
    NewsArticle: ['headline', 'image', 'datePublished', 'author'],
    Product: ['name', 'image', 'offers|review|aggregateRating'],
    FAQPage: ['mainEntity'],
    HowTo: ['name', 'step'],
    Recipe: ['name', 'image', 'recipeIngredient', 'recipeInstructions'],
    BreadcrumbList: ['itemListElement'],
    Organization: ['name'],
    LocalBusiness: ['name', 'address'],
    Event: ['name', 'startDate', 'location'],
    VideoObject: ['name', 'thumbnailUrl', 'uploadDate'],
    Course: ['name', 'description', 'provider']
  };

  function fmt(s) {
    var a = Array.prototype.slice.call(arguments, 1), n = 0;
    return String(s || '').replace(/%(\d)\$[sd]|%[sd]/g, function (m, pos) {
      return String(pos ? a[parseInt(pos, 10) - 1] : a[n++]);
    });
  }

  function seo(win, doc, pageUrl, T) {
    T = T || {};
    var f = [];
    var crit = 0, warn = 0;
    // code: stable id the action plan groups by (messages are translated).
    function add(sev, msg, code) {
      f.push({ sev: sev, msg: msg, code: code || '' });
      if (sev === 'critical') crit++; else if (sev === 'warning') warn++;
    }
    function meta(sel) {
      var el = doc.querySelector(sel);
      return el ? (el.getAttribute('content') || '').trim() : '';
    }
    var title = ((doc.querySelector('title') || {}).textContent || '').trim();
    if (!title) add('critical', T.noTitle || 'Missing <title>', 'noTitle');
    else if (title.length > 60) add('warning', fmt(T.longTitle || 'Title over 60 chars (%d)', title.length), 'longTitle');
    else add('pass', 'title');

    var desc = meta('meta[name="description"]');
    if (!desc) add('critical', T.noDesc || 'Missing meta description', 'noDesc');
    else if (desc.length > 160) add('warning', fmt(T.longDesc || 'Description over 160 chars (%d)', desc.length), 'longDesc');
    else add('pass', 'description');

    var norm = function (u) {
      try {
        var x = new URL(u, pageUrl);
        x.searchParams.delete('tqk_rs');
        return (x.origin + x.pathname).replace(/\/$/, '') + x.search;
      } catch (e) { return u; }
    };
    // Where the browser actually landed: a redirect is a finding of its own,
    // and the canonical must match the landed URL, not the queued one.
    var landed = (doc.location && doc.location.href) || pageUrl;
    if (norm(landed) !== norm(pageUrl)) add('warning', fmt(T.redirect || 'Redirects to %s', norm(landed)), 'redirect');
    var canon = doc.querySelector('link[rel="canonical"]');
    if (!canon) add('warning', T.noCanon || 'No canonical link', 'noCanon');
    else {
      var href = canon.getAttribute('href') || '';
      if (norm(href) !== norm(landed)) add('warning', fmt(T.canonOther || 'Canonical points to another URL: %s', href), 'canonOther');
      else add('pass', 'canonical');
    }
    // Every robots tag counts: two plugins (or core + a plugin) printing
    // contradicting tags is common, and Google applies the most restrictive.
    var robots = Array.prototype.map.call(doc.querySelectorAll('meta[name="robots"]'), function (m) {
      return (m.getAttribute('content') || '').trim();
    });
    if (robots.length > 1) add('critical', fmt(T.robotsMany || '%1$d robots meta tags: %2$s', robots.length, robots.join(' | ')), 'robotsMany');
    if (robots.some(function (r) { return /noindex/i.test(r); })) add('critical', T.noindex || 'Page is set to noindex', 'noindex');

    if (!meta('meta[property="og:title"]') || !meta('meta[property="og:description"]') || !meta('meta[property="og:image"]')) {
      add('warning', T.noOg || 'Open Graph incomplete', 'noOg');
    }

    var h1 = doc.querySelectorAll('body h1');
    if (!h1.length) add('critical', T.noH1 || 'No H1', 'noH1');
    else if (h1.length > 1) add('warning', fmt(T.manyH1 || 'Multiple H1 (%d)', h1.length), 'manyH1');

    if (!doc.documentElement.getAttribute('lang')) add('warning', T.noLang || 'No lang attribute', 'noLang');

    var noAlt = Array.prototype.filter.call(doc.querySelectorAll('main img, article img, .entry-content img, body img'), function (im) {
      return !(im.getAttribute('alt') || '').trim() && im.getAttribute('role') !== 'presentation' && im.getAttribute('aria-hidden') !== 'true';
    }).length;
    if (noAlt) add('warning', fmt(T.noAlt || '%d image(s) missing ALT', noAlt), 'noAlt');

    var main = doc.querySelector('main, article, .entry-content') || doc.body;
    var text = ((main && main.innerText) || '').trim();
    var words = text.split(/\s+/).filter(Boolean).length;
    // Vietnamese: space-split units are syllables (~1.7 per word).
    var letters = (text.match(/\p{L}/gu) || []).length;
    var viChars = (text.match(/[ăâđêôơưạảấầẩẫậắằẳẵặẹẻẽếềểễệỉịọỏốồổỗộớờởỡợụủứừửữựỳỵỷỹ]/gi) || []).length;
    var minWords = letters > 40 && viChars / letters > 0.03 ? 500 : 300;
    if (words < minWords) add('warning', fmt(T.thin || 'Thin content (%d words)', words), 'thin');

    // JSON-LD: parse every block, walk @graph, check required props per type.
    var types = [];
    var blocks = doc.querySelectorAll('script[type="application/ld+json"]');
    if (!blocks.length) add('warning', T.noSchema || 'No JSON-LD schema', 'noSchema');
    Array.prototype.forEach.call(blocks, function (b) {
      var data;
      try { data = JSON.parse(b.textContent || ''); } catch (e) { add('critical', T.badJson || 'Invalid JSON-LD', 'badJson'); return; }
      var nodes = [];
      (function walk(x) {
        if (Array.isArray(x)) { x.forEach(walk); return; }
        if (!x || typeof x !== 'object') return;
        if (x['@graph']) walk(x['@graph']);
        if (x['@type']) nodes.push(x);
      })(data);
      nodes.forEach(function (n) {
        var ts = Array.isArray(n['@type']) ? n['@type'] : [n['@type']];
        ts.forEach(function (t) {
          if (types.indexOf(t) < 0) types.push(t);
          if (t === 'Product') {
            var offers = [].concat(n.offers || []);
            if (offers.some(function (o) { return o && o.price !== undefined && parseFloat(o.price) === 0; })) {
              add('warning', T.priceZero || 'Product price is 0: Google shows it as free. Use "no price" handling instead.', 'priceZero');
            }
          }
          var req = SCHEMA_REQUIRED[t];
          if (!req) return;
          var miss = req.filter(function (k) {
            return !k.split('|').some(function (alt) {
              var v = n[alt];
              return v !== undefined && v !== null && v !== '' && !(Array.isArray(v) && !v.length);
            });
          });
          if (miss.length) add('warning', fmt(T.schemaMiss || '%1$s schema is missing: %2$s', t, miss.join(', ')), 'schemaMiss');
        });
      });
    });

    // Address snippets (footer / contact): checked server-side against the
    // 2025 province merger list.
    var addr = [];
    Array.prototype.some.call(doc.querySelectorAll('footer, address, [class*="contact"], [class*="footer"], [class*="address"], main, body'), function (el) {
      var lines = (el.innerText || '').split(/\n+/);
      lines.forEach(function (l) {
        l = l.trim();
        if (l.length > 8 && l.length < 220 && /(địa chỉ|đ\/c|address|trụ sở|văn phòng|chi nhánh)/i.test(l) && addr.indexOf(l) < 0) addr.push(l);
      });
      return addr.length >= 3;
    });

    // Plain facts for the action plan: counts, not verdicts.
    var hs = {};
    ['h1', 'h2', 'h3', 'h4'].forEach(function (t) { hs[t] = doc.querySelectorAll('body ' + t).length; });
    var levels = Array.prototype.map.call(doc.querySelectorAll('body h1, body h2, body h3, body h4, body h5, body h6'), function (h) { return parseInt(h.tagName.charAt(1), 10); });
    var skip = '';
    levels.some(function (l, i) {
      if (i > 0 && l - levels[i - 1] > 1) { skip = 'H' + levels[i - 1] + ' → H' + l; return true; }
      return false;
    });
    var facts = {
      title: title,
      desc: desc,
      canonical: !!canon,
      noindex: robots.some(function (r) { return /noindex/i.test(r); }),
      og: { title: !!meta('meta[property="og:title"]'), desc: !!meta('meta[property="og:description"]'), image: meta('meta[property="og:image"]') },
      twitter: !!doc.querySelector('meta[name="twitter:card"]'),
      h: hs,
      h1: Array.prototype.slice.call(h1, 0, 5).map(function (x) { return (x.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 80); }),
      hSkip: skip,
      noAlt: noAlt,
      words: words,
      minWords: minWords,
      schema: types
    };
    return { score: Math.max(0, 100 - crit * 25 - warn * 8), findings: f, schema: types, addr: addr.slice(0, 3), facts: facts };
  }

  /* ---------- share preview (Facebook / Zalo read the same OG tags) ----------
   * Both crop the preview to about 1.91:1 and skip tiny images, so a share
   * image that is too small or square shows badly (or not at all) when the
   * link is pasted into a chat. Loaded for real to get its true size. */
  function ogCheck(doc, pageUrl, T) {
    T = T || {};
    var out = [];
    function meta(p) {
      var el = doc.querySelector('meta[property="' + p + '"]');
      return el ? (el.getAttribute('content') || '').trim() : '';
    }
    var img = meta('og:image');
    var ogUrl = meta('og:url');
    var canon = (doc.querySelector('link[rel="canonical"]') || {}).href || '';
    if (ogUrl && canon && ogUrl.replace(/\/$/, '') !== canon.replace(/\/$/, '')) {
      out.push({ sev: 'warning', msg: fmt(T.ogUrl || 'og:url differs from canonical: %s', ogUrl), code: 'ogUrl' });
    }
    if (!img) return Promise.resolve(out);
    if (!/^https?:\/\//i.test(img)) {
      out.push({ sev: 'warning', msg: T.ogRelative || 'og:image must be a full https:// URL.', code: 'ogRelative' });
      return Promise.resolve(out);
    }
    return new Promise(function (res) {
      var im = new Image();
      var done = false;
      var finish = function () { if (!done) { done = true; res(out); } };
      im.onload = function () {
        var w = im.naturalWidth, h = im.naturalHeight, r = w / Math.max(1, h);
        if (w < 600 || h < 315) {
          out.push({ sev: 'warning', msg: fmt(T.ogSmall || 'Share image %1$d×%2$d is too small (min 600×315, best 1200×630).', w, h), code: 'ogSmall' });
        } else if (r < 1.5 || r > 2.2) {
          out.push({ sev: 'warning', msg: fmt(T.ogRatio || 'Share image ratio %s: it will be cropped to 1.91:1 (1200×630).', r.toFixed(2) + ':1'), code: 'ogRatio' });
        }
        finish();
      };
      im.onerror = function () {
        out.push({ sev: 'critical', msg: fmt(T.ogBroken || 'Share image does not load: %s', img), code: 'ogBroken' });
        finish();
      };
      setTimeout(finish, 8000);
      im.src = img;
    });
  }

  /* ---------- make the page render like a visitor who scrolled it ----------
   * Lazy loaders (native and JS) only load what comes near the viewport, and
   * scroll-reveal animations stay hidden until scrolled. Walk the page down
   * once, force native lazy images eager, then wait for images to finish. */
  function prep(win, doc, delay) {
    // A redirect drops ?tqk_rs, so the server cannot hide admin-only chrome
    // there: remove it here, it is not what a visitor sees.
    ['wpadminbar', 'tqk-scan-fab', 'tqk-scan-pop'].forEach(function (id) {
      var el = doc.getElementById(id);
      if (el && el.parentNode) el.parentNode.removeChild(el);
    });
    doc.documentElement.style.setProperty('margin-top', '0', 'important');
    if (doc.body) doc.body.classList.remove('admin-bar');
    Array.prototype.forEach.call(doc.querySelectorAll('img'), function (im) {
      if (im.getAttribute('loading') === 'lazy') im.setAttribute('loading', 'eager');
      var ds = im.getAttribute('data-src') || im.getAttribute('data-lazy-src');
      if (ds && (!im.getAttribute('src') || /^data:/.test(im.getAttribute('src')))) im.setAttribute('src', ds);
      var dss = im.getAttribute('data-srcset') || im.getAttribute('data-lazy-srcset');
      if (dss && !im.getAttribute('srcset')) im.setAttribute('srcset', dss);
    });
    var vh = win.innerHeight || 800;
    var steps = Math.min(40, Math.ceil((doc.documentElement.scrollHeight || vh) / vh));
    var i = 0;
    return new Promise(function (res) {
      (function down() {
        if (i > steps) { res(); return; }
        try { win.scrollTo(0, i * vh); } catch (e) { /* ignore */ }
        i++;
        setTimeout(down, delay || 140);
      })();
    }).then(function () {
      try { win.scrollTo(0, 0); } catch (e) { /* ignore */ }
      var pending = Array.prototype.filter.call(doc.images, function (im) { return !im.complete; });
      return Promise.race([
        Promise.all(pending.map(function (im) {
          return new Promise(function (r) { im.addEventListener('load', r); im.addEventListener('error', r); });
        })),
        new Promise(function (r) { setTimeout(r, 5000); })
      ]);
    }).then(function () {
      return new Promise(function (r) { setTimeout(r, 300); });
    });
  }

  /* ---------- runner: one URL, four viewports ----------
   * opts.stage: element the iframe is shown in (scaled down, on screen, so
   *   IntersectionObserver-based lazy loaders fire). Off-screen if absent.
   * opts.shots: viewport keys to snapshot, e.g. ['desktop','mobile'].
   * opts.t: translated SEO messages.
   * opts.timeout: seconds per viewport load (default 30). */
  function run(url, opts, onStep, onDone) {
    opts = opts || {};
    var host = opts.stage;
    var own = false;
    if (!host) {
      host = document.createElement('div');
      host.id = 'tqk-rs-host';
      host.setAttribute('aria-hidden', 'true');
      host.style.cssText = 'position:fixed;left:-20000px;top:0;width:0;height:0;overflow:hidden;';
      document.body.appendChild(host);
      own = true;
    }
    var shots = opts.shots || [];
    var limit = (opts.timeout || 30) * 1000;
    var idx = 0;
    var results = [];
    var out = { url: url, viewports: results, groups: [], seo: null, shots: {}, error: '' };
    var cancelled = false;
    var frame = null;

    function frameUrl(vp) {
      try {
        var u = new URL(url, location.href);
        u.searchParams.set('tqk_rs', vp.key);
        return u.href;
      } catch (e) {
        return url + (url.indexOf('?') >= 0 ? '&' : '?') + 'tqk_rs=' + vp.key;
      }
    }

    function finish() {
      if (frame && frame.parentNode) frame.parentNode.removeChild(frame);
      if (own) host.remove();
      out.groups = group(results.filter(function (m) { return !m.error; }));
      var ok = results.filter(function (m) { return !m.error; });
      if (!ok.length) out.error = results.length ? results[0].error : 'no viewport';
      onDone(out);
    }

    function stepViewport() {
      if (cancelled) return;
      if (idx >= VIEWPORTS.length) { finish(); return; }
      var vp = VIEWPORTS[idx];
      if (frame && frame.parentNode) frame.parentNode.removeChild(frame);
      frame = document.createElement('iframe');
      frame.setAttribute('title', 'TQK audit ' + vp.label);
      frame.setAttribute('tabindex', '-1');
      var sc = host.clientWidth && host.clientHeight ? Math.min(host.clientWidth / vp.w, host.clientHeight / vp.h, 1) : 1;
      frame.style.cssText = 'width:' + vp.w + 'px;height:' + vp.h + 'px;border:0;transform:scale(' + sc + ');transform-origin:0 0;background:#fff;';
      host.appendChild(frame);
      onStep({ phase: 'load', viewport: vp, index: idx, total: VIEWPORTS.length });

      var settled = false;
      var timer = setTimeout(function () { fail('timeout'); }, limit);
      function next() { idx++; setTimeout(stepViewport, 30); }
      function fail(code) {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        results.push({ key: vp.key, label: vp.label, error: code, score: 0, offenders: [], tinyText: [], smallTap: [], missingDims: [], oversized: [], overflowX: 0 });
        onStep({ phase: 'done', viewport: vp, index: idx, total: VIEWPORTS.length, result: results[results.length - 1] });
        next();
      }
      frame.onload = function () {
        if (settled) return;
        // Loaded: the load limit is over, the page work (scroll, a section
        // tour in the editor) gets its own, longer limit.
        clearTimeout(timer);
        timer = setTimeout(function () { fail('timeout'); }, opts.workTimeout || 240000);
        var win, doc;
        try {
          win = frame.contentWindow;
          doc = frame.contentDocument;
          if (!doc || !doc.body || doc.location.href === 'about:blank') throw new Error('blocked');
        } catch (e) {
          fail('blocked');
          return;
        }
        prep(win, doc, opts.scrollDelay).then(function () {
          if (settled) return null;
          var m = measure(win, doc);
          m.key = vp.key;
          m.label = vp.label;
          m.score = score(m);
          // Caller hook, every viewport, on the fully rendered and scrolled
          // page (the post editor reads its content and tours its sections).
          // May return a promise: the scan waits for it.
          var hook = null;
          if (typeof opts.onDoc === 'function') {
            try { hook = opts.onDoc(win, doc, vp.key); } catch (e) { hook = null; }
          }
          return Promise.resolve(hook).catch(function () { return null; }).then(function () { return m; });
        }).then(function (m) {
          if (!m || settled) return null;
          var og = Promise.resolve();
          if (vp.key === 'desktop') {
            out.seo = seo(win, doc, url, opts.t);
            og = ogCheck(doc, url, opts.t).then(function (fs) {
              fs.forEach(function (f) {
                out.seo.findings.push(f);
                out.seo.score = Math.max(0, out.seo.score - (f.sev === 'critical' ? 25 : 8));
              });
            });
          }
          return og.then(function () {
            if (shots.indexOf(vp.key) < 0 || !window.TQKSnapshot) return m;
            onStep({ phase: 'snapshot', viewport: vp, index: idx, total: VIEWPORTS.length });
            return window.TQKSnapshot.capture(win, doc, { scale: vp.w > 800 ? 0.5 : 1 })
              .then(function (d) { out.shots[vp.key] = d; }, function (e) { m.snapError = String(e && e.message || e); })
              .then(function () { return m; });
          });
        }).then(function (m) {
          if (!m || settled) return;
          settled = true;
          clearTimeout(timer);
          results.push(m);
          onStep({ phase: 'done', viewport: vp, index: idx, total: VIEWPORTS.length, result: m });
          next();
        }).catch(function (e) {
          fail('measure: ' + String(e && e.message || e).slice(0, 60));
        });
      };
      frame.src = frameUrl(vp);
    }

    stepViewport();
    return {
      cancel: function () {
        cancelled = true;
        try { if (frame && frame.parentNode) frame.parentNode.removeChild(frame); if (own) host.remove(); } catch (e) { /* gone */ }
      }
    };
  }

  /* One row per image with its box at every screen: the optimizer's input. */
  function mergeImages(vps) {
    var by = {};
    var order = [];
    (vps || []).forEach(function (m) {
      (m.images || []).forEach(function (im) {
        var k = im.kind + '|' + im.src;
        if (!by[k]) {
          by[k] = { kind: im.kind, src: im.src, att: im.att, srcset: im.srcset, wdesc: !!im.wdesc, sizes: im.sizes, inline: !!im.inline, sel: im.sel || '', vp: {} };
          order.push(k);
        }
        if (!by[k].vp[m.key]) by[k].vp[m.key] = { w: im.w, h: im.h, nw: im.nw, nh: im.nh, fit: im.fit, pos: im.pos, cur: im.cur };
      });
    });
    return order.slice(0, 60).map(function (k) { return by[k]; });
  }

  window.TQKResponsive = { run: run, seo: seo, viewports: VIEWPORTS, fmt: fmt, mergeImages: mergeImages, version: '2.1' };
})();
