'use strict';
/* TQK WP SEO — S6 AI modal: preview options, click to fill, never auto-saves. */
(function () {
  var modal = document.getElementById('tqk-wpseo-ai-modal');
  if (!modal || typeof ajaxurl === 'undefined') return;
  var list = document.getElementById('tqk-wpseo-ai-options');
  document.getElementById('tqk-wpseo-ai-close').addEventListener('click', function () {
    modal.hidden = true;
  });

  function targetField(type) {
    if (type === 'title') return document.getElementById('_tqk_wpseo_title');
    if (type === 'description') return document.getElementById('_tqk_wpseo_description');
    if (type === 'keywords') return document.getElementById('_tqk_wpseo_focus_keyword');
    return null;
  }

  document.querySelectorAll('.tqk-wpseo-ai-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var type = btn.getAttribute('data-ai');
      list.textContent = '…';
      modal.hidden = false;
      var body = new FormData();
      body.append('action', 'tqk_wpseo_ai');
      body.append('ai_type', type);
      body.append('post_id', btn.getAttribute('data-post'));
      body.append('_ajax_nonce', btn.getAttribute('data-nonce'));
      fetch(ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          list.innerHTML = '';
          var options = (res && res.data && res.data.options) || [];
          options.forEach(function (opt) {
            var p = document.createElement('p');
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'button tqk-wpseo-ai-pick';
            b.textContent = opt;
            b.addEventListener('click', function () {
              var field = targetField(type);
              if (field) field.value = opt;
              modal.hidden = true;
            });
            p.appendChild(b);
            list.appendChild(p);
          });
        })
        .catch(function () {
          list.textContent = 'error';
        });
    });
  });
})();
