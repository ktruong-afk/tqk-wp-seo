'use strict';
/* TQK WP SEO — action plan after a page scan (4 screen sizes).
 *
 * Turns everything the scan measured into ONE list of things to do, grouped
 * by priority, each item said once (an image too big on 3 screens is one
 * item, not three) and with the button that fixes it where the plugin can.
 *
 * API: TQKPlan.build(rep, ctx, T) → { items, facts }
 *        rep: engine report (viewports, groups, seo{findings, facts})
 *        ctx: { sections, images: {count, pro}, server: {critical, warning} }
 *      TQKPlan.html(plan, T) → HTML
 */
(function () {
  function fmt(s) {
    var a = Array.prototype.slice.call(arguments, 1), i = 0;
    return String(s || '')
      .replace(/%(\d)\$[sd]/g, function (m, n) { return a[n - 1]; })
      .replace(/%[sd]/g, function () { return a[i++]; });
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  /* 1 = fix now, 2 = should fix, 3 = nice to have. */
  var SEO = {
    noindex: [1, 'field', '_tqk_wpseo_robots'],
    robotsMany: [1, null],
    badJson: [1, 'schema'],
    noTitle: [1, 'field', '_tqk_wpseo_title'],
    noDesc: [1, 'field', '_tqk_wpseo_description'],
    noH1: [1, null],
    manyH1: [1, null],
    ogBroken: [1, 'tab', 'social'],
    longTitle: [2, 'field', '_tqk_wpseo_title'],
    longDesc: [2, 'field', '_tqk_wpseo_description'],
    redirect: [2, null],
    noCanon: [2, 'field', '_tqk_wpseo_canonical'],
    canonOther: [2, 'field', '_tqk_wpseo_canonical'],
    noOg: [2, 'tab', 'social'],
    ogSmall: [2, 'tab', 'social'],
    ogRatio: [2, 'tab', 'social'],
    ogRelative: [2, 'tab', 'social'],
    ogUrl: [3, null],
    noSchema: [2, 'schema'],
    schemaMiss: [2, 'schema'],
    priceZero: [2, null],
    noAlt: [2, null],
    thin: [2, null],
    noLang: [3, null]
  };

  function build(rep, ctx, T) {
    T = T || {};
    ctx = ctx || {};
    var items = [];
    var seo = rep.seo || { findings: [], facts: null };
    var facts = seo.facts || null;
    function add(prio, area, title, how, fix, extra) {
      items.push({ prio: prio, area: area, code: (extra && extra.code) || area, title: title, how: how || '', fix: fix || null, css: (extra && extra.css) || '', list: (extra && extra.list) || [] });
    }
    function fixOf(rule) {
      if (!rule || !rule[1]) return null;
      return { type: rule[1], key: rule[2] || '' };
    }

    // ---- SEO / social / schema, from the rendered page ----
    var seen = {};
    (seo.findings || []).forEach(function (f) {
      if (f.sev === 'pass') return;
      var rule = SEO[f.code] || [f.sev === 'critical' ? 1 : 2, null];
      var how = (T.how && T.how[f.code]) || '';
      var list = [];
      if (f.code === 'manyH1' && facts) list = facts.h1;
      if (f.code === 'thin' && facts) how = fmt(how, facts.minWords);
      var key = f.code ? f.code + '|' + (f.code === 'schemaMiss' ? f.msg : '') : f.msg;
      if (seen[key]) return;
      seen[key] = 1;
      add(rule[0], f.code && /^og|noOg/.test(f.code) ? 'social' : (/schema|Json/i.test(f.code) ? 'schema' : 'seo'), f.msg, how, fixOf(rule), { list: list, code: f.code || '' });
    });
    if (facts) {
      if (facts.h.h2 === 0 && facts.words > 300) add(2, 'seo', T.noH2 || 'No H2 heading', (T.how && T.how.noH2) || '', null, { code: 'noH2' });
      if (facts.hSkip) add(3, 'seo', fmt(T.hSkip || 'Heading levels skip (%s)', facts.hSkip), (T.how && T.how.hSkip) || '', null, { code: 'hSkip' });
      if (!facts.twitter) add(3, 'social', T.noTwitter || 'No X (Twitter) card tag', '', { type: 'tab', key: 'social' }, { code: 'noTwitter' });
    }

    // ---- layout: horizontal overflow, grouped by root cause ----
    (rep.groups || []).slice(0, 5).forEach(function (g) {
      var css = /flex|grid/.test(g.kind) ? g.root + ' > * { min-width: 0; }' : g.root + ' { max-width: 100%; }';
      add(1, 'layout', fmt(T.overflow || 'The page scrolls sideways on %1$s: %2$s', g.viewports.join(', '), g.root), g.reason, { type: 'css' }, { css: css, code: 'overflow' });
    });

    // ---- per-screen measurements: tap targets + typography ----
    var taps = {}, type = {};
    (rep.viewports || []).forEach(function (m) {
      if (m.error) return;
      (m.smallTap || []).forEach(function (t) {
        var k = t.sel;
        if (!taps[k]) taps[k] = { sel: t.sel, w: t.w, h: t.h, label: t.label, vps: [] };
        if (taps[k].vps.indexOf(m.label) < 0) taps[k].vps.push(m.label);
      });
      (m.type || []).forEach(function (t) {
        var k = t.kind + '|' + t.sel;
        if (!type[k]) type[k] = { t: t, vps: [] };
        if (type[k].vps.indexOf(m.label) < 0) type[k].vps.push(m.label);
      });
    });
    var tapList = Object.keys(taps).map(function (k) { return taps[k]; });
    if (tapList.length) {
      add(2, 'layout', fmt(T.smallTap || '%d buttons / links are too small to tap', tapList.length), T.smallTapHow || '', { type: 'css' }, {
        code: 'smallTap',
        css: tapList.slice(0, 6).map(function (t) { return t.sel; }).join(', ') + ' { min-height: 44px; min-width: 44px; display: inline-flex; align-items: center; }',
        list: tapList.slice(0, 6).map(function (t) { return t.sel + ' — ' + t.w + '×' + t.h + 'px' + (t.label ? ' "' + t.label + '"' : '') + ' (' + t.vps.join(', ') + ')'; })
      });
    }
    var TY = T.type || {};
    Object.keys(type).forEach(function (k) {
      var t = type[k].t, vps = type[k].vps.join(', ');
      var prio = { mainSmall: 2, bodySmall: 2, hBig: 2, contrast: 2, bodyBig: 3, hSmall: 3, lh: 3, measure: 3 }[t.kind] || 3;
      var title;
      switch (t.kind) {
        case 'mainSmall': title = fmt(TY.mainSmall || 'Main reading text is %1$spx on %2$s: use %3$spx', t.px, vps, t.to); break;
        case 'bodySmall': title = fmt(TY.bodySmall || '%1$s: text %2$spx is too small on %3$s: use %4$spx', t.sel, t.px, vps, t.to); break;
        case 'bodyBig': title = fmt(TY.bodyBig || '%1$s: text %2$spx is too big on %3$s: about %4$spx', t.sel, t.px, vps, t.to); break;
        case 'hBig': title = fmt(TY.hBig || '%1$s (%2$s) is %3$spx and takes %4$s lines on %5$s: about %6$spx', t.tag, t.sel, t.px, t.lines, vps, t.to); break;
        case 'hSmall': title = fmt(TY.hSmall || '%1$s (%2$s) is only %3$spx on %4$s: about %5$spx', t.tag, t.sel, t.px, vps, t.to); break;
        case 'lh': title = fmt(TY.lh || '%1$s: lines too tight (line-height %2$s) on %3$s: use %4$s', t.sel, t.px, vps, t.to); break;
        case 'measure': title = fmt(TY.measure || '%1$s: lines of about %2$s characters on %3$s: keep 60–75', t.sel, t.px, vps); break;
        case 'contrast': title = fmt(TY.contrast || '%1$s: %2$s text on %3$s is hard to read (%4$s:1, needs %5$s:1): use %6$s', t.sel, t.from, t.bg, t.px, t.need, t.to); break;
        default: return;
      }
      add(prio, 'text', title, t.sample ? '"' + t.sample + '…"' : '', { type: 'css' }, { css: t.css, code: t.kind });
    });

    // ---- sections (detailed scan) ----
    if (ctx.sections && ctx.sections.sections) {
      var empty = 0;
      ctx.sections.sections.forEach(function (s) {
        (s.issues || []).forEach(function (i) { if (i.code === 'emptyLink') empty += i.count || 0; });
      });
      if (empty) add(2, 'seo', fmt(T.emptyLinks || '%d links without text', empty), T.emptyLinksHow || '', null, { code: 'emptyLinks' });
    }

    // ---- images: one item, the table below does the work ----
    if (ctx.images && ctx.images.count) {
      add(2, 'images', fmt(T.images || '%d images should be resized for each screen', ctx.images.count),
        ctx.images.pro ? (T.imagesPro || '') : (T.imagesFree || ''),
        { type: 'images', key: ctx.images.pro ? '' : 'pro' }, { code: 'images' });
    }

    // ---- security: the page as visitors / Google get it ----
    if (ctx.security) {
      (ctx.security.checks || []).forEach(function (c) {
        add(c.sev === 'critical' ? 1 : 2, 'security', c.msg, c.sev === 'critical' ? (T.secHow || '') : '', { type: 'scripts' }, { code: 'security' });
      });
      if ((ctx.security.unknown || []).length) {
        add(2, 'security', fmt(T.unknownScripts || 'Scripts from unfamiliar domains: %s', ctx.security.unknown.slice(0, 6).join(', ')), T.unknownHow || '', { type: 'scripts' }, { code: 'unknownScripts' });
      }
    }

    // ---- server checklist (content, keyword) ----
    if (ctx.server && (ctx.server.critical || ctx.server.warning)) {
      add(ctx.server.critical ? 1 : 2, 'content', fmt(T.checklist || 'Content checklist: %1$d serious, %2$d warnings', ctx.server.critical, ctx.server.warning), T.checklistHow || '', { type: 'checklist' }, { code: 'checklist' });
    }

    items.sort(function (a, b) { return a.prio - b.prio; });
    return { items: items, facts: facts, verdict: ctx.security ? ctx.security.verdict : '' };
  }

  function html(plan, T) {
    T = T || {};
    var items = plan.items || [];
    var n = [0, 0, 0, 0];
    items.forEach(function (i) { n[i.prio]++; });
    var f = plan.facts;
    var h = '<div class="tqk-plan"><div class="tqk-plan-head"><strong>' + esc(T.title || 'Action plan') + '</strong><span class="tqk-plan-sum">'
      + '<span class="tqk-plan-pill is-bad">' + esc(fmt(T.nNow || '%d fix now', n[1])) + '</span>'
      + '<span class="tqk-plan-pill is-warn">' + esc(fmt(T.nShould || '%d should fix', n[2])) + '</span>'
      + '<span class="tqk-plan-pill is-info">' + esc(fmt(T.nNice || '%d nice to have', n[3])) + '</span></span></div>';
    if (f) {
      var ok = function (b) { return '<span class="tqk-plan-fact ' + (b ? 'is-ok' : 'is-bad') + '">' + (b ? '✓' : '✗') + '</span>'; };
      h += '<div class="tqk-plan-facts">'
        + '<span>' + ok(!!f.title) + ' Title</span>'
        + '<span>' + ok(!!f.desc) + ' Description</span>'
        + '<span>' + ok(f.og.title && f.og.desc && f.og.image) + ' Social</span>'
        + '<span>' + ok(f.schema.length > 0) + ' Schema' + (f.schema.length ? ': ' + esc(f.schema.slice(0, 4).join(', ')) : '') + '</span>'
        + '<span>' + ok(f.h.h1 === 1) + ' H1: ' + f.h.h1 + '</span>'
        + '<span class="tqk-plan-count">H2: ' + f.h.h2 + '</span><span class="tqk-plan-count">H3: ' + f.h.h3 + '</span>'
        + '<span class="tqk-plan-count">' + esc(fmt(T.words || '%d words', f.words)) + '</span>'
        + (plan.verdict ? '<span>' + ok(plan.verdict !== 'infected') + ' ' + esc((T.verdict && T.verdict[plan.verdict]) || plan.verdict) + '</span>' : '')
        + '</div>';
    }
    if (!items.length) return h + '<p class="tqk-plan-empty">' + esc(T.empty || 'Nothing left to fix.') + '</p></div>';
    [[1, T.now || 'Fix now', 'bad'], [2, T.should || 'Should fix', 'warn'], [3, T.nice || 'Nice to have', 'info']].forEach(function (g) {
      var list = items.filter(function (i) { return i.prio === g[0]; });
      if (!list.length) return;
      h += '<div class="tqk-plan-group is-' + g[2] + '"><div class="tqk-plan-gtitle"><span class="tqk-plan-dot"></span>' + esc(g[1]) + '<span class="tqk-plan-gcount">' + list.length + '</span></div>';
      list.forEach(function (i) {
        var btn = '';
        if (i.fix) {
          var label = i.fix.type === 'css' ? (T.copyCss || 'Copy CSS')
            : (i.fix.type === 'images' ? (i.fix.key === 'pro' ? (T.seeImagesFree || 'See how') : (T.seeImages || 'Optimize'))
              : (i.fix.type === 'checklist' || i.fix.type === 'scripts' ? (T.seeChecklist || 'See') : (T.fix || 'Fix')));
          btn = '<button type="button" class="button tqk-plan-fix" data-fix="' + esc(i.fix.type) + '" data-key="' + esc(i.fix.key || '') + '"'
            + (i.css ? ' data-css="' + esc(i.css) + '"' : '') + '>' + esc(label) + '</button>';
        }
        h += '<div class="tqk-plan-item"><div class="tqk-plan-body"><div class="tqk-plan-title">' + esc(i.title) + '</div>'
          + (i.how ? '<div class="tqk-plan-how">' + esc(i.how) + '</div>' : '')
          + (i.list.length ? '<ul class="tqk-plan-list">' + i.list.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul>' : '')
          + (i.css ? '<code class="tqk-plan-css">' + esc(i.css) + '</code>' : '')
          + '</div>' + btn + '</div>';
      });
      h += '</div>';
    });
    return h + '</div>';
  }

  /* The report kept for the page (MCP, editor): same shape whether it comes
     from Live Audit or from the editor's Scan. The server adds its
     checklist, image rows, screenshots and later the security check. */
  function agentReport(rep, ctx, T) {
    ctx = ctx || {};
    var plan = build(rep, { sections: ctx.sections }, T || {});
    var sec = ctx.sections;
    var mob = ctx.mobile;
    var imgMsgs = function (im) { return im.tags.map(function (t) { return t.msg; }); };
    return {
      source: ctx.source || 'editor scan',
      url: rep.url,
      mode: ctx.detail ? 'section by section' : 'quick',
      screens: (rep.viewports || []).map(function (v) {
        return {
          screen: v.label, score: v.score, error: v.error || '',
          overflow_px: v.overflowX || 0,
          small_tap_targets: (v.smallTap || []).slice(0, 8),
          tiny_text: (v.tinyText || []).length,
          typography: v.type || []
        };
      }),
      layout_overflow: rep.groups || [],
      seo: rep.seo ? { facts: rep.seo.facts, findings: (rep.seo.findings || []).filter(function (f) { return f.sev !== 'pass'; }) } : null,
      plan: plan.items.map(function (i) { return { prio: i.prio, area: i.area, code: i.code, title: i.title, how: i.how, css: i.css, list: i.list, fix: i.fix }; }),
      sections: sec ? sec.sections.map(function (s) {
        return {
          name: s.name, words: s.words, issues: s.issues,
          images: s.images.map(function (im) { return { file: im.name, file_px: im.natural, shown_px: im.shown, bytes: im.bytes, issues: imgMsgs(im) }; })
        };
      }) : null,
      mobile_images: mob ? mob.images.map(function (im) { return { file: im.name, file_px: im.natural, shown_px: im.shown, issues: imgMsgs(im) }; }) : null
    };
  }

  /* HTML of a stored report's plan (no re-scan needed). */
  function stored(report, T) {
    return html({
      items: (report.plan || []).map(function (i) { return { prio: i.prio, area: i.area, title: i.title, how: i.how || '', css: i.css || '', list: i.list || [], fix: i.fix || null }; }),
      facts: report.seo ? report.seo.facts : null,
      verdict: report.security ? report.security.verdict : ''
    }, T);
  }

  window.TQKPlan = { build: build, html: html, agentReport: agentReport, stored: stored };
})();
