'use strict';
/* TQK WP SEO — shared admin JS: every action runs over AJAX, pages never
 * full-reload for actions. Mutations append/remove rows or toast. */
(function () {
  var cfg = window.tqkWpseoAjax || {};
  var ajaxurl = window.ajaxurl || '/wp-admin/admin-ajax.php';

  /* ---------- toasts ---------- */
  function toast(msg, type) {
    var wrap = document.getElementById('tqk-wpseo-toasts');
    if (!wrap) {
      wrap = document.createElement('div');
      wrap.id = 'tqk-wpseo-toasts';
      document.body.appendChild(wrap);
    }
    var el = document.createElement('div');
    el.className = 'tqk-wpseo-toast' + (type === 'error' ? ' is-error' : '');
    el.textContent = msg;
    wrap.appendChild(el);
    setTimeout(function () { el.classList.add('is-out'); }, 3600);
    setTimeout(function () { el.remove(); }, 4100);
  }

  function appendDeep(body, prefix, value) {
    if (value !== null && typeof value === 'object' && !(value instanceof File)) {
      Object.keys(value).forEach(function (k) {
        appendDeep(body, prefix ? prefix + '[' + k + ']' : k, value[k]);
      });
    } else {
      body.append(prefix, value == null ? '' : value);
    }
  }

  function post(fields) {
    var body = new FormData();
    Object.keys(fields).forEach(function (k) { appendDeep(body, k, fields[k]); });
    body.append('_ajax_nonce', cfg.nonce || '');
    return fetch(ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); });
  }

  function result(res, okMsg) {
    if (res && res.success) {
      if (okMsg !== false) toast((res.data && res.data.message) || okMsg || 'Saved');
      return (res.data || {});
    }
    toast((res && res.data && res.data.message) || 'error', 'error');
    return null;
  }

  /* ---------- delete buttons: [data-tqk-del="redirect|four04" data-id] ---------- */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-tqk-del]');
    if (!btn) return;
    var kind = btn.getAttribute('data-tqk-del');
    var id = btn.getAttribute('data-id');
    var action = kind === 'redirect' ? 'tqk_wpseo_redirect' : 'tqk_wpseo_four04';
    post({ action: action, tqk_action: kind === 'redirect' ? 'redirect' : 'four04', op: 'del', id: id })
      .then(function (res) {
        var data = result(res, false);
        if (!data) return;
        var tr = btn.closest('tr');
        if (tr) tr.remove();
        toast(data.message || 'Deleted');
      });
  });

  /* ---------- forms: [data-tqk-form] ---------- */
  document.addEventListener('submit', function (e) {
    var form = e.target.closest('form[data-tqk-form]');
    if (!form) return;
    e.preventDefault();
    var kind = form.getAttribute('data-tqk-form');
    var fd = new FormData(form);
    var fields = { action: 'tqk_wpseo_' + kind, tqk_action: kind };
    fd.forEach(function (v, k) { fields[k] = v; });

    if (kind === 'bulk') {
      var rows = {};
      form.querySelectorAll('tbody tr').forEach(function (tr, i) {
        var t = tr.querySelector('input[type="text"]');
        var d = tr.querySelector('textarea');
        if (t && d) rows[i] = { title: t.value, desc: d.value };
      });
      // Re-key by post id parsed from the edit link.
      rows = {};
      form.querySelectorAll('tbody tr').forEach(function (tr) {
        var a = tr.querySelector('a[href*="post="]');
        var t = tr.querySelector('input[type="text"]');
        var d = tr.querySelector('textarea');
        var m = a ? /post=(\d+)/.exec(a.getAttribute('href')) : null;
        if (m && t && d) rows[m[1]] = { title: t.value, desc: d.value };
      });
      post({ action: 'tqk_wpseo_bulk', tqk_action: 'bulk', rows: rows }).then(function (res) { result(res); });
      return;
    }

    if (kind === 'redirect') {
      post(fields).then(function (res) {
        var data = result(res, false);
        if (!data || !data.row) return;
        var tb = document.getElementById('tqk-rows-redirects');
        if (tb) tb.insertAdjacentHTML('beforeend', data.row);
        form.reset();
        toast(data.message);
      });
      return;
    }

    if (kind === 'robots') {
      var op = e.submitter && e.submitter.hasAttribute('data-tqk-robots-reset') ? 'reset' : 'save';
      post({ action: 'tqk_wpseo_robots', tqk_action: 'robots', op: op, content: (document.getElementById('tqk-robots-text') || {}).value || '' })
        .then(function (res) {
          var data = result(res, false);
          if (!data) return;
          if (data.content !== undefined) document.getElementById('tqk-robots-text').value = data.content;
          var w = document.getElementById('tqk-robots-warnings');
          if (w) w.innerHTML = (data.warnings || []).map(function (x) { return '<div class="notice notice-error"><p></p></div>'; }).join('');
          if (w && data.warnings) {
            var ps = w.querySelectorAll('p');
            data.warnings.forEach(function (x, i) { if (ps[i]) ps[i].textContent = x; });
          }
          toast(data.message);
        });
      return;
    }

    post(fields).then(function (res) { result(res); });
  });

  /* ---------- quick fix forms: single-place edit, save → finish inline (no full rescan) ---------- */
  document.addEventListener('submit', function (e) {
    var form = e.target.closest('form[data-tqk-quickfix]');
    if (!form) return;
    e.preventDefault();
    var id = form.getAttribute('data-id');
    var status = form.querySelector('.tqk-qf-status');
    var btn = form.querySelector('button[type="submit"]');
    var fields = {};
    ['title', 'desc', 'keyword', 'secondary', 'canonical', 'robots', 'og_image', 'schema', 'og_title', 'og_description'].forEach(function (k) {
      var input = form.querySelector('[name="' + k + '"]');
      if (input) fields[k] = input.value;
    });
    // Only forms that show the box may change it (the dashboard quick fix
    // does not, and must not clear a "hidden from sitemap" choice).
    var ex = form.querySelector('[name="sitemap_exclude"]');
    if (ex) fields['sitemap_exclude'] = ex.checked ? '1' : '';
    var rows = {};
    rows[id] = fields;
    if (btn) btn.disabled = true;
    if (status) status.textContent = '…';
    post({ action: 'tqk_wpseo_bulk', tqk_action: 'bulk', rows: rows }).then(function (res) {
      if (btn) btn.disabled = false;
      var data = result(res, false);
      if (!data) { if (status) status.textContent = ''; return; }
      var f = data.fresh && data.fresh[id];
      if (status) status.textContent = data.message || 'Saved';
      if (!f) { toast(data.message || 'Saved'); return; }
      var card = form.closest('[data-tqk-card]');
      if (card) {
        var num = card.querySelector('.tqk-wpseo-score-num');
        if (num) {
          num.textContent = f.score;
          num.classList.remove('is-good', 'is-ok', 'is-bad');
          num.classList.add(f.score >= 80 ? 'is-good' : (f.score >= 50 ? 'is-ok' : 'is-bad'));
        }
        var cw = card.querySelector('.tqk-wpseo-cw');
        if (cw) cw.textContent = 'C ' + f.critical + ' · W ' + f.warnings;
        var accBody = card.querySelector('.tqk-wpseo-acc-body');
        if (accBody) {
          var list = accBody.querySelector('.tqk-wpseo-findings');
          var okDiv = accBody.querySelector('.tqk-wpseo-allok');
          if ((f.critical + f.warnings) === 0) {
            if (list) list.outerHTML = '<div class="tqk-wpseo-allok">✓ All checks passed.</div>';
            else if (!okDiv) accBody.insertAdjacentHTML('afterbegin', '<div class="tqk-wpseo-allok">✓ All checks passed.</div>');
          } else {
            if (okDiv) okDiv.outerHTML = '<ul class="tqk-wpseo-findings"></ul>';
            var ul = accBody.querySelector('.tqk-wpseo-findings');
            if (ul) {
              ul.innerHTML = (f.findings || []).map(function (x) {
                var m = (x.severity === 'critical' ? '✗ ' : '⚠ ') + (x.message || '');
                var li = document.createElement('li');
                li.textContent = m;
                return li.outerHTML;
              }).join('');
            }
          }
        }
      }
      toast(f.critical + f.warnings === 0 ? 'Done ✓' : data.message);
    });
  });

  /* ---------- head tag inline save (page audit report) ---------- */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-tqk-save-head]');
    if (!btn) return;
    var wrap = btn.closest('.tqk-head-edit');
    if (!wrap) return;
    var id = wrap.getAttribute('data-tqk-post');
    var field = wrap.getAttribute('data-tqk-field');
    var input = wrap.querySelector('input,select');
    var rows = {};
    rows[id] = {};
    rows[id][field] = input ? input.value : '';
    post({ action: 'tqk_wpseo_bulk', tqk_action: 'bulk', rows: rows }).then(function (res) { result(res); });
  });

  /* ---------- set image ALT (page audit report) ---------- */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-tqk-set-alt]');
    if (!btn) return;
    var attId = btn.getAttribute('data-tqk-set-alt');
    var wrap = btn.closest('.tqk-alt-edit');
    var input = wrap
      ? wrap.querySelector('input[data-tqk-alt-for]')
      : document.querySelector('input[data-tqk-alt-for="' + attId + '"]');
    var alt = input ? input.value : '';
    var scanBox = document.getElementById('tqk-wpseo-scan');
    var nonce = scanBox ? scanBox.getAttribute('data-nonce') || '' : '';
    var body = new FormData();
    body.append('action', 'tqk_wpseo_scan');
    body.append('op', 'set-alt');
    body.append('attachment_id', attId);
    body.append('alt', alt);
    body.append('_ajax_nonce', nonce);
    fetch(ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        var data = result(res, false);
        if (!data) return;
        if (wrap) {
          var span = document.createElement('span');
          span.textContent = alt ? '\u2713 ' + alt : '\u2717 \u2014';
          wrap.replaceWith(span);
        }
        toast(data.message);
      });
  });

  /* ---------- one-shot buttons: robots reset, CSV, links check, MCP, GSC sync ---------- */
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-tqk-robots-reset],[data-tqk-export-csv],[data-tqk-links-check],[data-tqk-links-rebuild],[data-tqk-mcp-regen],[data-tqk-gsc-sync],[data-tqk-export-settings]');
    if (!btn) return;

    if (btn.hasAttribute('data-tqk-robots-reset')) {
      post({ action: 'tqk_wpseo_robots', tqk_action: 'robots', op: 'reset' }).then(function (res) {
        var data = result(res, false);
        if (!data) return;
        if (data.content !== undefined) document.getElementById('tqk-robots-text').value = data.content;
        toast(data.message);
      });
      return;
    }

    if (btn.hasAttribute('data-tqk-export-csv')) {
      post({ action: 'tqk_wpseo_redirect', tqk_action: 'redirect', op: 'export' }).then(function (res) {
        if (!res || !res.success || !res.data.csv) { result(res); return; }
        var blob = new Blob([res.data.csv], { type: 'text/csv' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'tqk-wpseo-redirects.csv';
        a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); }, 2000);
      });
      return;
    }

    // Link index rebuild and link check: batches with an offset, so every
    // request moves forward (no re-checking the same first batch forever).
    if (btn.hasAttribute('data-tqk-links-check') || btn.hasAttribute('data-tqk-links-rebuild')) {
      var rebuild = btn.hasAttribute('data-tqk-links-rebuild');
      var status = document.getElementById(rebuild ? 'tqk-links-rebuild-status' : 'tqk-links-status');
      var wrap = document.getElementById(rebuild ? 'tqk-orphans-wrap' : 'tqk-broken-wrap');
      btn.disabled = true;
      (function batch(offset) {
        post({ action: 'tqk_wpseo_links', tqk_action: 'links', op: rebuild ? 'rebuild' : 'check', offset: offset }).then(function (res) {
          if (!res || !res.success) { result(res); btn.disabled = false; return; }
          var data = res.data || {};
          if (status) status.textContent = data.message || '';
          if (!data.done) { batch(data.next); return; }
          if (wrap && data.html) wrap.innerHTML = data.html;
          btn.disabled = false;
          toast(data.message || 'Done');
        });
      })(0);
      return;
    }

    if (btn.hasAttribute('data-tqk-mcp-regen')) {
      post({ action: 'tqk_wpseo_mcp', tqk_action: 'mcp', op: 'regen' }).then(function (res) {
        var data = result(res, false);
        if (!data) return;
        if (data.token) document.getElementById('tqk-mcp-token').value = data.token;
        toast(data.message);
      });
      return;
    }

    if (btn.hasAttribute('data-tqk-gsc-sync')) {
      var st = document.getElementById('tqk-gsc-status');
      btn.disabled = true;
      if (st) st.textContent = '…';
      post({ action: 'tqk_wpseo_gsc', tqk_action: 'gsc', op: 'sync' }).then(function (res) {
        btn.disabled = false;
        var data = result(res, false);
        if (!data) { if (st) st.textContent = ''; return; }
        if (data.html) {
          var tbl = document.getElementById('tqk-gsc-table');
          if (tbl) tbl.innerHTML = data.html;
        }
        if (st) st.textContent = data.message || '';
        toast(data.message || 'Synced');
      });
      return;
    }

    if (btn.hasAttribute('data-tqk-export-settings')) {      post({ action: 'tqk_wpseo_tools', tqk_action: 'tools', op: 'export' }).then(function (res) {
        if (!res || !res.success || !res.data.json) { result(res); return; }
        var blob = new Blob([res.data.json], { type: 'application/json' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'tqk-wpseo-settings.json';
        a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); }, 2000);
      });
    }
  });

  /* ---------- GSC rank history: query select → table, no reload ---------- */
  document.addEventListener('change', function (e) {
    if (e.target.id !== 'tqk-gsc-q') return;
    var box = document.getElementById('tqk-gsc-hist');
    if (!box || !e.target.value) {
      if (box) box.innerHTML = '';
      return;
    }
    box.innerHTML = '<p>…</p>';
    post({ action: 'tqk_wpseo_gsc_history', tqk_action: 'gsc_history', query: e.target.value })
      .then(function (res) {
        var data = result(res, false);
        if (box) box.innerHTML = data && data.html ? data.html : '';
      });
  });

  /* ---------- Bulk AI: generate for checked pages, preview, apply selected ---------- */
  document.addEventListener('click', function (e) {
    var go = e.target.closest('[data-tqk-bulk-ai-go]');
    if (go) {
      var ids = [];
      document.querySelectorAll('[data-tqk-bulk-ai]:checked').forEach(function (box) {
        ids.push(box.getAttribute('data-tqk-bulk-ai'));
      });
      var typeSel = document.getElementById('tqk-bulk-ai-type');
      var type = typeSel ? typeSel.value : 'title';
      var out = document.getElementById('tqk-bulk-ai-out');
      var applyBtn = document.querySelector('[data-tqk-bulk-ai-apply]');
      if (!ids.length) {
        toast('Nothing checked', 'error');
        return;
      }
      go.disabled = true;
      if (out) out.innerHTML = '<p>…</p>';
      var body = new FormData();
      body.append('action', 'tqk_wpseo_ai');
      body.append('ai_type', type);
      ids.forEach(function (id, i) { body.append('post_ids[' + i + ']', id); });
      body.append('_ajax_nonce', go.getAttribute('data-nonce') || '');
      fetch(ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          go.disabled = false;
          if (!res || !res.success) {
            result(res);
            return;
          }
          var html = '';
          Object.keys(res.data.results || {}).forEach(function (id) {
            var r = res.data.results[id];
            var first = (r && r[0]) || '';
            var err = r && r.error ? ' (' + r.error + ')' : '';
            html += '<div class="tqk-wpseo-formcard" data-tqk-ai-row="' + id + '">'
              + '<label><input type="checkbox" data-tqk-ai-pick="' + id + '" checked> <strong>#' + id + '</strong>' + err + '</label><br>'
              + '<textarea class="large-text" rows="2" data-tqk-ai-val="' + id + '"></textarea></div>';
          });
          if (out) {
            out.innerHTML = html;
            Object.keys(res.data.results || {}).forEach(function (id) {
              var ta = out.querySelector('[data-tqk-ai-val="' + id + '"]');
              var r = res.data.results[id];
              if (ta) ta.value = (r && r[0]) || '';
            });
          }
          if (applyBtn) applyBtn.hidden = false;
        })
        .catch(function () { go.disabled = false; });
      return;
    }
    var apply = e.target.closest('[data-tqk-bulk-ai-apply]');
    if (apply) {
      var typeSel2 = document.getElementById('tqk-bulk-ai-type');
      var field = (typeSel2 && typeSel2.value === 'description') ? 'desc' : 'title';
      var rows = {};
      document.querySelectorAll('[data-tqk-ai-pick]:checked').forEach(function (box) {
        var id = box.getAttribute('data-tqk-ai-pick');
        var ta = document.querySelector('[data-tqk-ai-val="' + id + '"]');
        rows[id] = {};
        rows[id][field] = ta ? ta.value : '';
      });
      post({ action: 'tqk_wpseo_bulk', tqk_action: 'bulk', rows: rows }).then(function (res) {
        result(res);
        if (res && res.success) toast('Applied — check Dashboard');
      });
    }
  });

  /* ---------- module toggles ---------- */
  document.addEventListener('change', function (e) {
    var t = e.target.closest('[data-tqk-module]');
    if (!t) return;
    post({ action: 'tqk_wpseo_module', tqk_action: 'module', module: t.getAttribute('data-tqk-module'), on: t.checked ? '1' : '' })
      .then(function (res) {
        var data = result(res, false);
        if (!data) { t.checked = !t.checked; return; }
        if (data.states) {
          document.querySelectorAll('[data-tqk-module]').forEach(function (box) {
            var s = box.getAttribute('data-tqk-module');
            if (data.states[s] !== undefined) box.checked = !!data.states[s];
          });
        }
        toast(data.message);
      });
  });
  document.addEventListener('change', function (e) {
    if (e.target.id !== 'tqk-import-csv' || !e.target.files.length) return;
    var reader = new FileReader();
    reader.onload = function () {
      var queue = [];
      String(reader.result || '').split(/\r?\n/).forEach(function (ln, i) {
        if (i === 0 || !ln.trim()) return; // header / empty
        var parts = ln.split(',');
        if (parts.length >= 2 && parts[0].trim()) {
          queue.push({ source: parts[0].trim(), target: (parts[1] || '').trim(), type: parseInt(parts[2], 10) || 301 });
        }
      });
      var i = 0;
      (function next() {
        if (i >= queue.length) { toast('Imported'); return; }
        var r = queue[i++];
        post({ action: 'tqk_wpseo_redirect', tqk_action: 'redirect', op: 'add', source: r.source, target: r.target, type: r.type })
          .then(function (res) {
            if (res && res.success && res.data.row) {
              var tb = document.getElementById('tqk-rows-redirects');
              if (tb) tb.insertAdjacentHTML('beforeend', res.data.row);
            }
            next();
          });
      })();
    };
    reader.readAsText(e.target.files[0]);
    e.target.value = '';
  });
  document.addEventListener('change', function (e) {
    if (e.target.id !== 'tqk-import-settings' || !e.target.files.length) return;
    var reader = new FileReader();
    reader.onload = function () {
      post({ action: 'tqk_wpseo_tools', tqk_action: 'tools', op: 'import', payload: String(reader.result || '') })
        .then(function (res) {
          result(res);
          if (res && res.success) setTimeout(function () { location.reload(); }, 800);
        });
    };
    reader.readAsText(e.target.files[0]);
    e.target.value = '';
  });

  /* ---------- wizard: swap steps in place, no reload ---------- */
  var wiz = document.getElementById('tqk-wpseo-wizard');
  if (wiz) {
    var body = document.getElementById('tqk-wpseo-wizard-body');
    var prog = document.getElementById('tqk-wpseo-wizard-progress');
    function paint(step, data) {
      if (data.progress) prog.innerHTML = data.progress;
      body.innerHTML = data.html || '';
      wiz.setAttribute('data-step', step);
    }
    wiz.addEventListener('submit', function (e) {
      var form = e.target.closest('form[data-tqk-wizard]');
      if (!form) return;
      e.preventDefault();
      var step = parseInt(form.getAttribute('data-tqk-wizard'), 10);
      var fd = new FormData(form);
      var fields = { action: 'tqk_wpseo_wizard', tqk_action: 'wizard', step: step };
      fd.forEach(function (v, k) { fields[k] = v; });
      post(fields).then(function (res) {
        if (!res || !res.success) { result(res); return; }
        var data = res.data || {};
        if (data.done) {
          wiz.innerHTML = data.html || '';
          toast('Done');
          return;
        }
        paint(data.step, data);
      });
    });
    wiz.addEventListener('click', function (e) {
      var skip = e.target.closest('[data-tqk-wizard-skip]');
      if (!skip) return;
      post({ action: 'tqk_wpseo_wizard', tqk_action: 'wizard', step: skip.getAttribute('data-tqk-wizard-skip'), skip: '1' })
        .then(function (res) {
          if (!res || !res.success) { result(res); return; }
          var data = res.data || {};
          if (data.done) { wiz.innerHTML = data.html || ''; return; }
          paint(data.step, data);
        });
    });
  }

  /* ---------- media picker (site-wide default share image) ---------- */
  (function imagePickers() {
    var frame = null;
    var fields = document.querySelectorAll('[data-image-field]');
    if (!fields.length || !window.wp || !window.wp.media) return;
    fields.forEach(function (wrap) {
      var input = wrap.querySelector('[data-image-url]');
      var pick = wrap.parentElement && wrap.parentElement.querySelector('.tqk-wpseo-image-pick');
      if (!pick) pick = wrap.querySelector('.tqk-wpseo-image-pick');
      var clear = wrap.querySelector('.tqk-wpseo-image-clear')
        || (wrap.parentElement && wrap.parentElement.querySelector('.tqk-wpseo-image-clear'));
      if (!input || !pick) return;
      pick.addEventListener('click', function (ev) {
        ev.preventDefault();
        if (frame) { frame.open(); return; }
        frame = window.wp.media({ title: pick.textContent, multiple: false, library: { type: 'image' } });
        frame.on('select', function () {
          var a = frame.state().get('selection').first().toJSON();
          var url = a.url || (a.sizes && a.sizes.large && a.sizes.large.url) || '';
          if (!url) return;
          input.value = url;
          var host = wrap.querySelector('[data-image-preview-wrap]') || wrap;
          var prev = host.querySelector('.tqk-wpseo-image-preview');
          if (!prev) {
            prev = document.createElement('img');
            prev.className = 'tqk-wpseo-image-preview';
            host.appendChild(prev);
          }
          prev.src = url;
          prev.hidden = false;
        });
        frame.open();
      });
      if (clear) {
        clear.addEventListener('click', function (ev) {
          ev.preventDefault();
          input.value = '';
          var host = wrap.querySelector('[data-image-preview-wrap]') || wrap;
          var prev = host.querySelector('.tqk-wpseo-image-preview');
          if (prev) prev.hidden = true;
        });
      }
    });
  })();

  /* ---------- scanner: inject report, no reload ---------- */
  var scanBox = document.getElementById('tqk-wpseo-scan');
  if (scanBox && typeof window.tqkWpseoScanInit !== 'function') {
    window.tqkWpseoScanInit = function (startLoop) { startLoop(); };
  }

  /* ---------- settings: show only the chosen AI provider's fields ---------- */
  var aiSel = document.querySelector('[data-ai-provider]');
  if (aiSel) {
    var syncAi = function () {
      Array.prototype.forEach.call(document.querySelectorAll('[data-ai-for]'), function (box) {
        box.hidden = (' ' + box.getAttribute('data-ai-for') + ' ').indexOf(' ' + aiSel.value + ' ') < 0;
      });
    };
    aiSel.addEventListener('change', syncAi);
    syncAi();
  }
})();
