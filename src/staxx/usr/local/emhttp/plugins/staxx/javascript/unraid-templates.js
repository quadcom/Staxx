/* StaXX — Unraid templates still on flash after a takeover.
 * Copyright 2026, StaXX contributors.
 *
 * PLAN_165 §5: a small section injected into the Settings dialog's own
 * "Health check" pane, listing whichever Unraid templates §1 never got a
 * chance to move because the stack was taken over before this plan existed,
 * with one button that moves them. Driven off the page's own
 * data-csrf/data-endpoint scaffold rather than anything private to
 * stacks.js — this file never reads or sets a stacks.js global, so a bad
 * edit here costs only this section, never the whole page's behaviour.
 *
 * The first-load "Unraid templates found" window (§6) and the header pill
 * (§5) live in stacks.js, off its ordinary 'state' refresh — the same reply
 * this section's move refreshes, so the two never disagree.
 *
 * The settings dialog itself is stacks.js's own, and it redraws its whole
 * body from scratch every time it opens (see openSettings() there) — there
 * is no hook to run code after that happens, so this watches for it with a
 * MutationObserver instead of patching that function.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
(function () {
  'use strict';

  function scaffold() { return document.querySelector('.staxx-scaffold'); }

  // Same URLSearchParams-only rule stacks.js's own call() follows — see its
  // header comment: a multipart POST (FormData) simply hangs on this box.
  function call(action, fields) {
    var el = scaffold();
    if (!el) return Promise.resolve({ ok: false, error: 'The page is not ready yet.' });

    var data = new URLSearchParams();
    data.append('csrf_token', el.dataset.csrf || '');
    data.append('action', action);
    Object.keys(fields || {}).forEach(function (key) {
      var value = fields[key];
      if (Array.isArray(value)) value.forEach(function (v) { data.append(key, v); });
      else data.append(key, value);
    });

    return fetch(el.dataset.endpoint, { method: 'POST', body: data, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .catch(function () { return { ok: false, error: 'The request failed.' }; });
  }

  var ESCAPE_MAP = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ESCAPE_MAP[c]; });
  }

  /* ---------------------------------------------------- settings section - */

  // Verbatim text from PLAN_165 §5's "Settings page" bullet.
  function sectionHtml(rows) {
    var risk   = rows.filter(function (r) { return r.state !== 'unraid'; });
    var unraid = rows.filter(function (r) { return r.state === 'unraid'; });

    var html = '<div class="staxx-field" data-key="unraid-templates" id="staxx-unraid-templates">'
             + '<span>Unraid templates</span>';

    if (!risk.length && !unraid.length) {
      html += '<span class="staxx-hint">Every taken-over stack\'s Unraid template is already '
            + 'in StaXX\'s store.</span>';
      html += '</div>';
      return html;
    }

    if (risk.length) {
      html += '<span class="staxx-hint">' + risk.length + ' Unraid templates still name '
            + 'containers that StaXX now runs. Unraid\'s Auto Update Applications and '
            + 'Appdata Backup can rebuild the old container from them and push the stack\'s '
            + 'own container out. Moving them into StaXX\'s store stops that; they are kept, '
            + 'not deleted.</span>';
      html += '<ul class="staxx-confirm-list">' + risk.map(function (r) {
        return '<li>' + esc(r.name) + ' — ' + esc(r.stack)
             + (r.autoupdate ? ' (on Auto Update\'s list)' : '') + '</li>';
      }).join('') + '</ul>';
      html += '<div class="staxx-buttons staxx-buttons--inline">'
            + '<button type="button" class="staxx-btn" id="staxx-unraid-templates-move">'
            + 'Move them into StaXX</button></div>';
    }

    if (unraid.length) {
      html += '<ul class="staxx-confirm-list">' + unraid.map(function (r) {
        return '<li>' + esc(r.name) + ' — ' + esc(r.stack)
             + ' — Take the stack over to move its template.</li>';
      }).join('') + '</ul>';
    }

    html += '<p class="staxx-settings-msg" id="staxx-unraid-templates-msg"></p>';
    html += '</div>';
    return html;
  }

  // Runs every time the settings dialog's body is (re)built. Idempotent: it
  // checks for its own id first, so a MutationObserver firing more than
  // once for the same open (it can, since innerHTML assignment is one
  // mutation but a later click inside the pane is another) never asks the
  // server twice for the same dialog.
  function loadSection() {
    var host = document.querySelector('[data-pane="selftest"]');
    if (!host || document.getElementById('staxx-unraid-templates')) return;

    var placeholder = document.createElement('div');
    placeholder.className = 'staxx-field';
    placeholder.id = 'staxx-unraid-templates';
    placeholder.innerHTML = '<span>Unraid templates</span><span class="staxx-hint">Checking…</span>';
    host.appendChild(placeholder);

    call('unraid-templates', {}).then(function (res) {
      // The dialog may have been closed and reopened while this was in
      // flight — placeholder-by-id is gone, and drawing into a detached
      // node would just be thrown away, so check it is still there.
      if (!document.getElementById('staxx-unraid-templates')) return;
      replaceSection(res.ok ? (res.templates || []) : []);
    });
  }

  function replaceSection(rows) {
    var current = document.getElementById('staxx-unraid-templates');
    if (!current) return;
    var wrap = document.createElement('div');
    wrap.innerHTML = sectionHtml(rows);
    current.replaceWith(wrap.firstChild);
  }

  function moveThemIn(btn) {
    btn.disabled = true;
    call('unraid-templates-reclaim', {}).then(function (res) {
      if (!res.ok) {
        var msg = document.getElementById('staxx-unraid-templates-msg');
        if (msg) msg.textContent = res.error || 'That failed.';
        btn.disabled = false;
        return;
      }
      // Re-read rather than assume "moved everything" — a row already
      // classed 'unraid' (still genuinely Unraid's own) is never touched by
      // this button and has to keep showing.
      call('unraid-templates', {}).then(function (r2) {
        replaceSection(r2.ok ? (r2.templates || []) : []);
      });
    });
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest('#staxx-unraid-templates-move');
    if (btn) moveThemIn(btn);
  });

  var settingsBody = document.getElementById('staxx-settings-body');
  if (settingsBody && typeof MutationObserver !== 'undefined') {
    new MutationObserver(loadSection).observe(settingsBody, { childList: true });
  }
})();
