/* StaXX — clear out what Unraid Docker and Compose Manager left behind.
 * Copyright 2026, StaXX contributors.
 *
 * PLAN_211: a section injected into the Settings dialog's Storage pane,
 * directly above the Archived stacks box. It lists Unraid
 * templates whose app is gone, templates whose plain container is still
 * there, and Compose Manager's settings folder once the add-on is gone. The
 * person ticks what should go, confirms in the page, and the server keeps a
 * copy of everything so it can be put back.
 *
 * Same shape as unraid-templates.js: it reads only the page's own
 * data-csrf/data-endpoint scaffold and never touches a stacks.js global, so a
 * bad edit here costs only this section. The clear runs as a detached job
 * (stopping a container takes seconds); this file follows the job's log
 * itself with the endpoint's own 'job' action rather than reaching into
 * stacks.js's tracker.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
(function () {
  'use strict';

  var ID = 'staxx-leftovers';

  function scaffold() { return document.querySelector('.staxx-scaffold'); }

  // URLSearchParams only: a multipart POST (FormData) hangs on this box.
  function call(action, fields) {
    var el = scaffold();
    if (!el) return Promise.resolve({ ok: false, error: 'The page is not ready yet.' });

    var data = new URLSearchParams();
    data.append('csrf_token', el.dataset.csrf || '');
    data.append('action', action);
    Object.keys(fields || {}).forEach(function (key) {
      var value = fields[key];
      // PHP only builds an array from repeated keys ending in [].
      if (Array.isArray(value)) value.forEach(function (v) { data.append(key + '[]', v); });
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

  function humanBytes(n) {
    var units = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0;
    n = Number(n) || 0;
    while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
    return (i ? n.toFixed(1) : String(n)) + ' ' + units[i];
  }

  var data = null;      // last leftovers object the server sent
  var notice = '';      // one line shown under the heading after an action

  function host() { return document.getElementById(ID); }

  /* ------------------------------------------------------------ drawing - */

  var DAMAGED_CHIP = '<span class="staxx-leftovers-damaged">Damaged</span>';

  function isRunning(t) { return t.container && t.container.state === 'running'; }

  function groupsHtml(d) {
    var html = '';
    var plain = d.templates.filter(function (t) { return !t.container; });
    var live  = d.templates.filter(function (t) { return !!t.container; });

    function row(t, note, chip) {
      return '<label class="staxx-leftovers-row"><input type="checkbox" data-lo-file="'
        + esc(t.file) + '"> <span class="staxx-leftovers-name">' + esc(t.name) + '</span>'
        + (chip ? ' ' + DAMAGED_CHIP : '')
        + (note ? ' <span class="staxx-leftovers-note">' + note + '</span>' : '') + '</label>';
    }

    if (plain.length) {
      html += '<div class="staxx-leftovers-group"><h4>Templates for apps that no longer exist</h4>'
        + plain.map(function (t) { return row(t, ''); }).join('') + '</div>';
    }
    if (live.length) {
      html += '<div class="staxx-leftovers-group"><h4>Apps still set up in Unraid\'s Docker tab</h4>'
        + live.map(function (t) {
          if (t.container.damaged) {
            return row(t, 'Docker can\'t read this app. Clearing removes its template and tries to '
              + 'remove the app. If Docker refuses, the log says what to do.', true);
          }
          return row(t, esc(t.container.state) + '. '
            + 'Removes the app and its template. Its data folders stay.');
        }).join('') + '</div>';
    }
    if ((d.damaged || []).length) {
      html += '<div class="staxx-leftovers-group"><h4>Damaged apps</h4>'
        + d.damaged.map(function (c) {
          return '<label class="staxx-leftovers-row"><input type="checkbox" data-lo-damaged="'
            + esc(c.id) + '"> <span class="staxx-leftovers-name">' + esc(c.name) + '</span> '
            + DAMAGED_CHIP + ' <span class="staxx-leftovers-note">Docker can\'t read this app, '
            + 'and it has no template. Clearing tries to remove it. If Docker refuses, the log says what to do.</span></label>';
        }).join('') + '</div>';
    }
    if (d.composeManager) {
      var cm = d.composeManager;
      var blocked = cm.blockedBy && cm.blockedBy.length;
      html += '<div class="staxx-leftovers-group">'
        + '<h4>Compose Manager\'s settings folder (the add-on is no longer installed)</h4>'
        + '<label class="staxx-leftovers-row"><input type="checkbox" data-lo-cm="1"'
        + (blocked ? ' disabled' : '') + '> <span class="staxx-leftovers-name">'
        + humanBytes(cm.size) + ', ' + (cm.projects || []).length + ' project'
        + ((cm.projects || []).length === 1 ? '' : 's') + '</span></label>';
      if ((cm.projects || []).length) {
        html += '<div class="staxx-leftovers-note">' + cm.projects.map(function (p) {
          return esc(p.name);
        }).join(', ') + '</div>';
      }
      if (blocked) {
        html += cm.blockedBy.map(function (n) {
          return '<p class="staxx-leftovers-blocked">Can\'t remove this yet: the project "'
            + esc(n) + '" still has a container. Bring it into StaXX first, then come back.</p>';
        }).join('');
      }
      html += '</div>';
    }
    return html;
  }

  function keptHtml(kept) {
    if (!kept.length) return '';
    var html = '<div class="staxx-leftovers-group"><h4>Kept copies</h4>';
    kept.forEach(function (set) {
      html += '<div class="staxx-leftovers-set"><div class="staxx-leftovers-setname">'
        + esc(set.stamp) + '</div>';
      set.items.forEach(function (it) {
        html += '<div class="staxx-leftovers-row"><span class="staxx-leftovers-name">'
          + esc(it.name) + '</span> <span class="staxx-leftovers-note">' + esc(it.kind) + '</span>'
          + (it.back ? ' <button type="button" class="staxx-btn" data-lo-restore="1"'
            + ' data-stamp="' + esc(set.stamp) + '" data-kind="' + esc(it.kind)
            + '" data-name="' + esc(it.name) + '">Put back</button>' : '')
          + '</div>';
      });
      html += '<div class="staxx-buttons staxx-buttons--inline">'
        + '<button type="button" class="staxx-btn" data-lo-forget="' + esc(set.stamp)
        + '">Delete for good</button></div></div>';
    });
    return html + '</div>';
  }

  function draw() {
    var el = host();
    if (!el) return;
    var d = data;
    if (!d || (!d.templates.length && !d.damaged.length && !d.composeManager && !d.kept.length)) {
      // Keep the node (the loader's idempotence check looks for its id) but
      // show nothing, unless there is a line about what just happened.
      el.hidden = !notice;
      el.innerHTML = notice ? '<p class="staxx-settings-msg">' + esc(notice) + '</p>' : '';
      return;
    }
    el.hidden = false;
    el.innerHTML = '<span>Left behind by Unraid Docker and Compose Manager</span>'
      + '<span class="staxx-hint">These are settings files from apps you no longer run through '
      + 'Unraid. Tick what you want gone. StaXX keeps a copy, so you can put any of it back.</span>'
      + (notice ? '<p class="staxx-settings-msg">' + esc(notice) + '</p>' : '')
      + '<div class="staxx-leftovers-body" id="staxx-leftovers-body">'
      + groupsHtml(d)
      + ((d.templates.length || d.damaged.length || d.composeManager)
        ? '<div class="staxx-buttons staxx-buttons--inline">'
          + '<button type="button" class="staxx-btn staxx-btn--primary" id="staxx-leftovers-clear" disabled>'
          + 'Clear out 0 items</button></div>' : '')
      + '<div id="staxx-leftovers-confirm"></div>'
      + '</div>'
      + keptHtml(d.kept);
    syncButton();
  }

  // Runs a redraw without losing the panel's scroll position.
  function keepScroll(fn) {
    var spots = [];
    var pane = document.querySelector('[data-pane="storage"]');
    var body = document.getElementById('staxx-settings-body');
    [pane, body].forEach(function (n) { if (n) spots.push([n, n.scrollTop]); });
    fn();
    spots.forEach(function (s) { s[0].scrollTop = s[1]; });
  }

  function ticked() {
    var el = host();
    if (!el) return { files: [], damaged: [], cm: false };
    var files = [], damaged = [];
    el.querySelectorAll('input[data-lo-file]:checked').forEach(function (b) {
      files.push(b.getAttribute('data-lo-file'));
    });
    el.querySelectorAll('input[data-lo-damaged]:checked').forEach(function (b) {
      damaged.push(b.getAttribute('data-lo-damaged'));
    });
    var cm = !!el.querySelector('input[data-lo-cm]:checked');
    return { files: files, damaged: damaged, cm: cm };
  }

  function syncButton() {
    var btn = document.getElementById('staxx-leftovers-clear');
    if (!btn) return;
    var t = ticked();
    var n = t.files.length + t.damaged.length + (t.cm ? 1 : 0);
    btn.disabled = n === 0;
    btn.textContent = 'Clear out ' + n + (n === 1 ? ' item' : ' items');
  }

  /* ----------------------------------------------------------- actions - */

  function refresh(then) {
    return call('leftovers', {}).then(function (res) {
      data = res.ok && res.leftovers ? {
        templates: res.leftovers.templates || [],
        composeManager: res.leftovers.composeManager || null,
        damaged: res.leftovers.damaged || [],
        kept: res.leftovers.kept || []
      } : null;
      if (!res.ok) notice = res.error || 'Could not read what was left behind.';
      keepScroll(draw);
      if (then) then();
    });
  }

  function findTemplate(file) {
    return (data.templates || []).filter(function (t) { return t.file === file; })[0];
  }

  // The in-page confirm: every chosen item on its own line, a running app
  // marked as such. Never a browser dialog.
  function askConfirm() {
    var slot = document.getElementById('staxx-leftovers-confirm');
    if (!slot) return;
    var t = ticked();
    if (!t.files.length && !t.damaged.length && !t.cm) return;
    var lines = t.files.map(function (f) {
      var tpl = findTemplate(f);
      if (!tpl) return '';
      return '<li>' + esc(tpl.name)
        + (tpl.container && tpl.container.damaged ? ' — the damaged app and its template'
          : isRunning(tpl) ? ' — running now, will be stopped and removed'
          : (tpl.container ? ' — the app and its template' : ' — the template')) + '</li>';
    });
    t.damaged.forEach(function (id) {
      var c = (data.damaged || []).filter(function (x) { return x.id === id; })[0];
      if (c) lines.push('<li>' + esc(c.name) + ' — the damaged app</li>');
    });
    if (t.cm) lines.push('<li>Compose Manager\'s settings folder</li>');
    slot.innerHTML = '<div class="staxx-leftovers-confirm"><p>This removes:</p>'
      + '<ul class="staxx-confirm-list">' + lines.join('') + '</ul>'
      + '<p>A running app will be stopped first. You can put templates and folders back from '
      + '"Kept copies" below.</p>'
      + '<div class="staxx-buttons staxx-buttons--inline">'
      + '<button type="button" class="staxx-btn staxx-btn--primary" id="staxx-leftovers-go">'
      + 'Clear out</button> <button type="button" class="staxx-btn" id="staxx-leftovers-nope">'
      + 'Cancel</button></div></div>';
  }

  function runClear() {
    var t = ticked();
    var body = document.getElementById('staxx-leftovers-body');
    if (!body || (!t.files.length && !t.damaged.length && !t.cm)) return;
    var fields = { templates: t.files };
    if (t.damaged.length) fields.damaged = t.damaged;
    if (t.cm) fields.composeManager = '1';
    body.innerHTML = '<p class="staxx-hint">Clearing…</p>'
      + '<pre class="staxx-leftovers-log" id="staxx-leftovers-log"></pre>';

    // Same key the image-removal endpoint hands back: 'job'.
    call('leftovers-clear', fields).then(function (res) {
      if (!res.ok || !res.job) {
        notice = res.error || 'Could not start.';
        refresh();
        return;
      }
      follow(res.job, 0, 0);
    });
  }

  // Polls the shared 'job' action until it says done, then redraws.
  function follow(job, offset, failures) {
    call('job', { job: job, offset: offset }).then(function (res) {
      if (!res.ok) {
        if (failures >= 5) { notice = res.error || 'Lost track of the job.'; refresh(); return; }
        setTimeout(function () { follow(job, offset, failures + 1); }, 1500);
        return;
      }
      var pre = document.getElementById('staxx-leftovers-log');
      if (pre && res.text) pre.textContent += res.text;
      if (res.done) {
        notice = (res.exit === 0 || res.exit == null)
          ? 'Done. Anything you removed may leave an image behind; the image cleanup can clear those.'
          : 'The job stopped with an error. See the log above.';
        refresh();
        return;
      }
      setTimeout(function () { follow(job, res.offset || offset, 0); }, 1000);
    });
  }

  function restore(btn) {
    var kind = btn.getAttribute('data-kind'), name = btn.getAttribute('data-name');
    btn.disabled = true;
    call('leftovers-restore', {
      stamp: btn.getAttribute('data-stamp'), kind: kind, name: name
    }).then(function (res) {
      notice = !res.ok ? (res.error || 'That failed.')
        : (kind === 'container'
          ? 'The template is back. Make the app again from Unraid\'s Docker tab: Add Container, then pick "'
            + name + '".'
          : 'Put back.');
      refresh();
    });
  }

  function askForget(btn) {
    var stamp = btn.getAttribute('data-lo-forget');
    btn.parentNode.innerHTML = '<span class="staxx-leftovers-note">Delete this copy for good? '
      + 'It cannot be put back afterwards.</span> '
      + '<button type="button" class="staxx-btn staxx-btn--primary" data-lo-forget-yes="'
      + esc(stamp) + '">Delete for good</button> '
      + '<button type="button" class="staxx-btn" data-lo-forget-no="1">Cancel</button>';
  }

  document.addEventListener('click', function (e) {
    var el = host();
    if (!el || !el.contains(e.target)) return;
    var t = e.target;
    if (t.closest('#staxx-leftovers-clear')) return askConfirm();
    if (t.closest('#staxx-leftovers-go')) return runClear();
    if (t.closest('#staxx-leftovers-nope')) {
      var slot = document.getElementById('staxx-leftovers-confirm');
      if (slot) slot.innerHTML = '';
      return;
    }
    var r = t.closest('[data-lo-restore]');
    if (r) return restore(r);
    var f = t.closest('[data-lo-forget]');
    if (f) return askForget(f);
    if (t.closest('[data-lo-forget-no]')) { keepScroll(draw); return; }
    var y = t.closest('[data-lo-forget-yes]');
    if (y) {
      y.disabled = true;
      call('leftovers-forget', { stamp: y.getAttribute('data-lo-forget-yes') }).then(function (res) {
        notice = res.ok ? '' : (res.error || 'That failed.');
        refresh();
      });
    }
  });

  document.addEventListener('change', function (e) {
    var el = host();
    if (el && el.contains(e.target) && e.target.type === 'checkbox') {
      // A changed tick invalidates any confirm already showing.
      var slot = document.getElementById('staxx-leftovers-confirm');
      if (slot) slot.innerHTML = '';
      syncButton();
    }
  });

  /* ------------------------------------------------------- injection - */

  // Runs every time the settings dialog's body is rebuilt; idempotent by id.
  function loadSection() {
    var pane = document.querySelector('[data-pane="storage"]');
    if (!pane || host()) return;

    var div = document.createElement('div');
    div.className = 'staxx-field staxx-leftovers';
    div.id = ID;
    div.setAttribute('data-key', 'leftovers');
    div.hidden = true;
    var archive = document.getElementById('staxx-archive-list');
    if (archive && archive.parentNode === pane) archive.before(div);
    else pane.appendChild(div);

    notice = '';
    refresh();
  }

  var settingsBody = document.getElementById('staxx-settings-body');
  if (settingsBody && typeof MutationObserver !== 'undefined') {
    new MutationObserver(loadSection).observe(settingsBody, { childList: true });
  }
})();
