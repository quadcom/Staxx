/* StaXX — the first-run "where should the data store live" screen.
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 * A separate file from stacks.js on purpose. stacks.js is one big IIFE that
 * dereferences #staxx-modal and a long list of other elements the moment it
 * runs, none of which exist on a page with no stacks table — so it cannot be
 * loaded here. What it must not do is duplicate stacks.js's own logic: the
 * suggestion, the filtered folder picker and the live path check all live on
 * the server (storage-options / browse / store-check), and this file only
 * ever asks them the same questions the storage chooser does and draws their
 * answers. See PLAN_97 Phase 2.
 *
 * Plain browser JavaScript, no libraries.
 */

(function () {
  'use strict';

  // Same bail-out shape stacks.js opens with: nothing below can run without
  // its host, and the server-rendered paragraph already says everything that
  // needs saying if script never loads at all.
  var openBtn = document.getElementById('staxx-firstrun-open');
  var scaffold = document.querySelector('.staxx-scaffold');
  if (!openBtn || !scaffold) return;

  var ENDPOINT = scaffold.dataset.endpoint;
  var CSRF     = scaffold.dataset.csrf;

  function esc(s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function bytes(n) {
    if (n === null || n === undefined) return '';
    var units = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0, v = n;
    while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
    return (i === 0 ? String(v) : v.toFixed(1)) + ' ' + units[i];
  }

  /* Same reasoning as stacks.js's own call(): URLSearchParams (application/
   * x-www-form-urlencoded), never FormData — a multipart POST to this
   * endpoint simply hangs rather than answering. */
  function call(action, fields, timeoutMs) {
    var data = new URLSearchParams();
    data.append('csrf_token', CSRF);
    data.append('action', action);
    Object.keys(fields || {}).forEach(function (k) { data.append(k, fields[k]); });

    var opts = { method: 'POST', body: data, credentials: 'same-origin' };
    var stop = null;
    if (typeof AbortController !== 'undefined') {
      var ctrl = new AbortController();
      opts.signal = ctrl.signal;
      stop = setTimeout(function () { ctrl.abort(); }, timeoutMs || 20000);
    }

    return fetch(ENDPOINT, opts).then(function (r) {
      if (stop) clearTimeout(stop);
      return r.text();
    }, function (err) {
      if (stop) clearTimeout(stop);
      throw err;
    }).then(function (text) {
      try { return JSON.parse(text); }
      catch (e) {
        return { ok: false, error: 'The server sent back something that could not be read.' };
      }
    }, function (err) {
      return { ok: false, error: (err && err.name === 'AbortError')
        ? 'Timed out waiting for a reply.' : 'Could not reach the server.' };
    });
  }

  /* ---------------------------------------------------------- markup shell */

  var dlg = document.createElement('dialog');
  dlg.className = 'staxx-firstrun';
  dlg.id = 'staxx-firstrun-dlg';
  dlg.setAttribute('aria-labelledby', 'staxx-firstrun-title');
  dlg.innerHTML =
    '<div class="staxx-modal-head">' +
      '<h3 class="staxx-modal-title" id="staxx-firstrun-title">' +
        'Where should StaXX keep its data?</h3>' +
      '<button type="button" class="staxx-btn" id="staxx-firstrun-close">Close</button>' +
    '</div>' +
    '<div class="staxx-firstrun-body" id="staxx-firstrun-body"></div>' +
    '<p class="staxx-firstrun-msg" id="staxx-firstrun-msg" role="status" aria-live="polite"></p>';
  scaffold.appendChild(dlg);

  var bodyEl = document.getElementById('staxx-firstrun-body');
  var msgEl  = document.getElementById('staxx-firstrun-msg');
  var closeBtn = document.getElementById('staxx-firstrun-close');

  // A second, small dialog for the filtered folder picker — the same
  // #staxx-picker classes staxx.css already defines, so nothing new has to
  // be styled for it. Dialogs stack in the top layer in the order they were
  // opened, so this paints above the screen above without any positioning
  // of its own.
  var pk = document.createElement('dialog');
  pk.className = 'staxx-picker';
  pk.id = 'staxx-firstrun-picker';
  pk.setAttribute('aria-labelledby', 'staxx-firstrun-picker-title');
  pk.innerHTML =
    '<div class="staxx-picker-head">' +
      '<h3 class="staxx-picker-title" id="staxx-firstrun-picker-title">Choose a folder</h3>' +
      '<p class="staxx-picker-hint">Open a folder to look inside it, then choose "Choose this ' +
        'folder" to put its path in the box — nothing is created until the data store is actually ' +
        'set up.</p>' +
    '</div>' +
    '<div class="staxx-picker-where"><code id="staxx-firstrun-pk-here">/mnt</code></div>' +
    '<div class="staxx-picker-list" id="staxx-firstrun-pk-list"></div>' +
    '<div class="staxx-picker-foot">' +
      '<p class="staxx-picker-msg" id="staxx-firstrun-pk-msg" role="status" aria-live="polite"></p>' +
      '<div class="staxx-buttons staxx-buttons--inline">' +
        '<button type="button" class="staxx-btn" id="staxx-firstrun-pk-cancel">Cancel</button>' +
        '<button type="button" class="staxx-btn staxx-btn--primary" id="staxx-firstrun-pk-use">' +
          'Choose this folder</button>' +
      '</div>' +
    '</div>';
  scaffold.appendChild(pk);

  var pkHere = document.getElementById('staxx-firstrun-pk-here');
  var pkList = document.getElementById('staxx-firstrun-pk-list');
  var pkMsg  = document.getElementById('staxx-firstrun-pk-msg');
  var PICKER_ROOT = '/mnt';
  var pkAt   = PICKER_ROOT;
  var pkBusy = false;
  var pkFor  = null; // the text input the chosen folder is written into

  function pkStart(value) {
    var v = String(value || '').trim();
    return (v === PICKER_ROOT || v.indexOf(PICKER_ROOT + '/') === 0) ? v : PICKER_ROOT;
  }

  function pkPaint(res) {
    var out = [];
    if (res.up) {
      out.push('<button type="button" class="staxx-pickrow staxx-pickrow--up" data-path="' +
        esc(res.up) + '"><i class="fa fa-level-up" aria-hidden="true"></i> ' + esc(res.up) + '</button>');
    }
    var blocked = res.blocked || {};
    res.dirs.forEach(function (name) {
      var why = blocked[name] || '';
      if (why) {
        out.push('<button type="button" class="staxx-pickrow staxx-pickrow--out" disabled title="' +
          esc(why) + '"><i class="fa fa-ban" aria-hidden="true"></i> ' + esc(name) +
          '<span class="staxx-pickrow-why">' + esc(why) + '</span></button>');
        return;
      }
      out.push('<button type="button" class="staxx-pickrow" data-path="' + esc(res.path + '/' + name) +
        '"><i class="fa fa-folder-o" aria-hidden="true"></i> ' + esc(name) + '</button>');
    });
    pkList.innerHTML = out.join('');
    pkList.scrollTop = 0;
  }

  function pkLoad(path, carry) {
    if (pkBusy) return;
    pkBusy = true;
    if (!carry) pkMsg.textContent = 'Reading ' + path + '…';

    // 'stacks' is the same purpose the storage chooser's own browse button
    // passes — it is what marks array disks, unassigned drives and network
    // mounts as unusable here.
    call('browse', { path: path, purpose: 'stacks' }, 20000).then(function (res) {
      pkBusy = false;
      var why = res.ok ? (res.error || '') : res.error;
      if (why) {
        pkMsg.textContent = why;
        if (!carry && path !== PICKER_ROOT) { pkLoad(PICKER_ROOT, why + '  Showing ' + PICKER_ROOT + ' instead.'); }
        return;
      }
      pkAt = res.path;
      pkHere.textContent = res.path;
      pkMsg.textContent = carry || (res.more
        ? 'Showing the first ' + res.dirs.length + ' folders — there are more in here than that.'
        : (res.dirs.length ? '' : 'There are no folders inside this one.'));
      pkPaint(res);
    });
  }

  function pkOpen(input) {
    pkFor = input;
    pkAt = PICKER_ROOT;
    pkHere.textContent = PICKER_ROOT;
    pkList.innerHTML = '';
    pkMsg.textContent = '';
    pk.showModal();
    pkLoad(pkStart(input.value), '');
  }

  pkList.addEventListener('click', function (event) {
    var row = event.target.closest('.staxx-pickrow');
    if (row && row.dataset.path) pkLoad(row.dataset.path, '');
  });
  document.getElementById('staxx-firstrun-pk-use').addEventListener('click', function () {
    if (pkFor) { pkFor.value = pkAt; pkFor.dispatchEvent(new Event('input', { bubbles: true })); pkFor.focus(); }
    pk.close();
  });
  document.getElementById('staxx-firstrun-pk-cancel').addEventListener('click', function () { pk.close(); });
  pk.addEventListener('click', function (event) {
    if (event.target !== pk) return;
    var r = pk.getBoundingClientRect();
    if (event.clientX < r.left || event.clientX > r.right ||
        event.clientY < r.top  || event.clientY > r.bottom) pk.close();
  });
  pk.addEventListener('close', function () { pkFor = null; });

  /* --------------------------------------------------- reasons, collapsed */

  // Adrian's instruction: full explanations, but each collapsed to one line
  // until opened — <details>/<summary> is exactly that, natively, with no
  // script needed to toggle it. The wording below follows the same claims
  // storageKindLine(), staxx_browse_dirs()'s blocked reasons and
  // staxx_placement_risk() already make; see PLAN_97's "What the dialog
  // says" for where each one comes from.
  var REASONS = [
    { s: 'Why not go through Unraid’s share layer',
      d: 'Unraid’s share layer sits between StaXX and the disk. It is slower, and what is ' +
         'actually behind it can change: if the pool it lands on fills up, files can quietly end ' +
         'up on the array instead of the pool that was chosen.' },
    { s: 'Why not an array disk',
      d: 'One disk on its own has no redundancy of its own, and writing straight to it goes ' +
         'behind the rules the array itself uses to decide where files go.' },
    { s: 'Why not an unassigned drive or a network mount',
      d: 'Either one can simply be missing the next time the server boots, with the data still ' +
         'sitting inside it.' },
    { s: 'Why redundancy is read, never guessed',
      d: 'Unraid publishes each pool’s own redundancy profile itself. A pool that reports none is ' +
         '"not reported as redundant" — a weaker, different claim from "not redundant", so the two ' +
         'are never folded into one.' },
    { s: 'Why a folder inside a share, rather than a share of its own',
      d: 'A folder sitting at the very top of a pool is discovered by Unraid as a new share on its ' +
         'own, with whatever defaults happen to apply — so the data store goes inside an existing ' +
         'share instead of becoming one.' },
    { s: 'What Unraid’s mover would do to the wrong choice',
      d: 'If the share holding the data store is set to move its contents onto the array, ' +
         'Unraid’s mover carries them there on its own schedule — the files do not stay where they ' +
         'were put.' },
    { s: 'Nothing is backing this up yet',
      d: 'Whatever is chosen here is not covered by any backup until it is actually named in one. ' +
         'That is worth doing as soon as this choice is made — the settings page has a reminder ' +
         'for it once a store exists.' },
    { s: 'This is also where StaXX’s own settings will live',
      d: 'The folder chosen here holds more than the compose files — it becomes StaXX’s whole ' +
         'record: its settings, the archives of removed stacks, and everything else it keeps for ' +
         'itself. That is why this choice matters more than it looks.' }
  ];

  function reasonsHtml() {
    return '<div class="staxx-firstrun-reasons">' + REASONS.map(function (r) {
      return '<details class="staxx-firstrun-reason"><summary>' + esc(r.s) + '</summary><p>' +
        esc(r.d) + '</p></details>';
    }).join('') + '</div>';
  }

  /* ------------------------------------------------- facts about an option */

  // Reuses stacks.js's own storageKindLine()/storageFreeLine() wording — the
  // same facts, read from the same storage-options reply, phrased the same
  // way a person deciding on the storage chooser already sees them.
  function redundancyLine(opt) {
    if (opt.kind !== 'pool') return '';
    if (opt.redundant) return 'Reports a "' + esc(opt.fsProfile) + '" redundancy profile.';
    if (opt.fsProfile) return 'Reports a "' + esc(opt.fsProfile) + '" profile, which is not one of the redundant kinds.';
    return 'This pool does not report a redundancy profile.';
  }

  function kindLine(opt) {
    if (opt.kind === 'pool') {
      return 'Reached directly, rather than through Unraid’s share layer, which is both faster ' +
        'and safer here. ' + redundancyLine(opt);
    }
    if (opt.kind === 'overlay') {
      return 'Works, but goes through Unraid’s share layer — if the pool behind it ever fills, ' +
        'the data store could quietly land on the array instead. A pool path is reached directly ' +
        'and is the better choice, ideally a redundant one.';
    }
    return 'Where the flash drive already is.';
  }

  function freeLine(opt) {
    return (opt.freeBytes === null || opt.freeBytes === undefined)
      ? 'Free space not reported.' : bytes(opt.freeBytes) + ' free.';
  }

  function factsLine(opt) { return kindLine(opt) + ' ' + freeLine(opt); }

  // What can be said about a path nothing offered — typed in, or reached
  // through Browse. Same claim staxx.js's storageShapeLine() makes: only
  // what the path itself proves.
  function shapeLine(value) {
    var v = String(value || '').replace(/\/+$/, '');
    if (v !== '/mnt/user' && v.indexOf('/mnt/user/') !== 0) return '';
    return 'This goes through Unraid’s share layer, so what sits behind it can change — if the ' +
      'pool it lands on fills, files can quietly end up on the array instead. A pool path ' +
      '(/mnt/&lt;pool&gt;/…) is reached directly and is the better choice, ideally a redundant one.';
  }

  /* --------------------------------------------------------- main screen */

  var state = { options: null };
  var checkSeq = 0;
  var checkTimer = null;

  function unavailableHtml(list) {
    if (!list || !list.length) return '';
    return '<div class="staxx-field"><span>Not offered</span><ul class="staxx-confirm-list">' +
      list.map(function (u) { return '<li><strong>' + esc(u.name || u.kind) + '</strong>: ' + esc(u.reason) + '</li>'; }).join('') +
      '</ul></div>';
  }

  function destPanelHtml(res, bestOpt) {
    var prefill = res.suggested || '';
    var suggestions = res.offered.filter(function (o) { return o.kind !== 'flash' && o.path !== prefill; });
    var suggestionHtml = suggestions.length
      ? '<p class="staxx-hint staxx-storage-suggestions">Or use: ' + suggestions.map(function (o) {
          return '<a href="#" class="staxx-storage-suggest" data-firstrun-suggest="' + esc(o.path) + '">' + esc(o.path) + '</a>';
        }).join(' · ') + '</p>'
      : '';

    return '<div class="staxx-field">' +
        '<span>Data store location</span>' +
        '<div class="staxx-boxline">' +
          '<input type="text" class="staxx-input" id="staxx-firstrun-path" spellcheck="false" value="' + esc(prefill) + '">' +
          '<button type="button" class="staxx-btn" id="staxx-firstrun-browse">Browse…</button>' +
        '</div>' +
        '<div class="staxx-boxline">' +
          '<span class="staxx-hint" id="staxx-firstrun-check">' + (prefill ? 'Checking…' : 'Type a path, or use Browse…') + '</span>' +
          '<button type="button" class="staxx-btn staxx-btn--primary" id="staxx-firstrun-use" disabled>Use this location</button>' +
        '</div>' +
      '</div>' +
      '<p class="staxx-hint" id="staxx-firstrun-desc">' + (bestOpt ? factsLine(bestOpt) : '') + '</p>' +
      suggestionHtml;
  }

  function noPoolHtml() {
    return '<div class="staxx-notice staxx-notice--bad">' +
      '<i class="fa fa-exclamation-triangle" aria-hidden="true"></i>' +
      '<div><strong>This machine has no cache pool StaXX can reach directly.</strong> Without one ' +
      'there is no genuinely good place for the data store: going through the array by way of ' +
      'Unraid’s share layer is slower and can let files quietly drift onto the array if it fills, ' +
      'and the flash drive is small, wears out with use, and is the least redundant thing in the ' +
      'machine. Neither is right, but a choice still has to be made — the share layer is offered ' +
      'below as the safer of the two.</div></div>';
  }

  function renderWaiting() {
    bodyEl.innerHTML =
      '<div class="staxx-notice"><i class="fa fa-clock-o" aria-hidden="true"></i>' +
      '<div>The disks are still starting up, so the good places to put the data store cannot be ' +
      'shown yet. Nothing is selectable here until they finish coming up — wait a moment, then ' +
      'look again.</div></div>' +
      '<div class="staxx-buttons staxx-buttons--inline">' +
        '<button type="button" class="staxx-btn staxx-btn--primary" id="staxx-firstrun-retry">Look again</button>' +
      '</div>';
    var retry = document.getElementById('staxx-firstrun-retry');
    if (retry) retry.addEventListener('click', loadOptions);
  }

  function renderMain(res) {
    state.options = res;
    if (checkTimer) { clearTimeout(checkTimer); checkTimer = null; }

    var bestOpt = res.offered.filter(function (o) { return o.path === res.suggested; })[0] || null;
    var hasPool = res.offered.some(function (o) { return o.kind === 'pool'; });

    bodyEl.innerHTML =
      (hasPool ? '' : noPoolHtml()) +
      '<p class="staxx-hint">StaXX keeps everything it owns — the stacks, the archives of removed ' +
      'ones, and its own settings — in one folder. Nothing is written to it until it is chosen ' +
      'here.</p>' +
      destPanelHtml(res, bestOpt) +
      reasonsHtml() +
      unavailableHtml(res.unavailable);

    var input = document.getElementById('staxx-firstrun-path');
    if (input && input.value) runCheck(input.value);
  }

  function loadOptions() {
    msgEl.textContent = '';
    bodyEl.innerHTML = '<p class="staxx-hint">Checking what is available…</p>';
    call('storage-options', {}).then(function (res) {
      if (!res.ok) { bodyEl.innerHTML = ''; msgEl.textContent = res.error || 'Could not read what is available.'; return; }
      if (!res.arrayStarted) { renderWaiting(); return; }
      renderMain(res);
    });
  }

  function checkLine() { return document.getElementById('staxx-firstrun-check'); }
  function useBtn()    { return document.getElementById('staxx-firstrun-use'); }

  function updateDesc(value) {
    var line = document.getElementById('staxx-firstrun-desc');
    if (!line) return;
    var match = state.options && state.options.offered.filter(function (o) {
      return o.kind !== 'flash' && o.path === value;
    })[0];
    line.innerHTML = match ? factsLine(match) : shapeLine(value);
  }

  function runCheck(dest) {
    var seq = ++checkSeq;
    var btn = useBtn(), line = checkLine();
    if (btn) btn.disabled = true;
    if (line) { line.textContent = 'Checking…'; line.classList.remove('staxx-error'); }

    call('store-check', { path: dest }).then(function (res) {
      if (seq !== checkSeq) return; // a later keystroke already asked again
      if (!res.ok) {
        if (line) { line.textContent = res.error || 'Could not check this path.'; line.classList.add('staxx-error'); }
        return;
      }
      if (res.ready) {
        if (btn) btn.disabled = false;
        if (line) {
          line.textContent = res.warn ? 'This path can be used, but — ' + res.warn + '.' : 'This path can be used.';
          line.classList.toggle('staxx-warn', !!res.warn);
          line.classList.remove('staxx-error');
        }
      } else if (line) {
        line.textContent = res.error;
        line.classList.add('staxx-error');
        line.classList.remove('staxx-warn');
      }
    });
  }

  // Same 400ms debounce as the storage chooser: short enough to feel
  // immediate once someone pauses, long enough that a typing burst costs one
  // check rather than one per keystroke.
  function debounceCheck(dest) {
    if (checkTimer) clearTimeout(checkTimer);
    var btn = useBtn();
    if (btn) btn.disabled = true;
    checkTimer = setTimeout(function () { runCheck(dest); }, 400);
  }

  /* --------------------------------------------------- inspect and create */

  function renderConfirm(path, res) {
    var cls = res.state === 'staxx' ? 'staxx-notice--good' : (res.state === 'other' ? 'staxx-notice--bad' : '');
    var icon = res.state === 'staxx' ? 'fa-check-circle' : 'fa-exclamation-triangle';
    var showNotice = res.state !== 'empty' && res.state !== 'missing';

    bodyEl.innerHTML =
      (showNotice
        ? '<div class="staxx-notice ' + cls + '"><i class="fa ' + icon + '" aria-hidden="true"></i>' +
          '<div>' + esc(res.detail) + '</div></div>'
        : '<p class="staxx-hint">' + esc(res.detail) + '</p>') +
      (res.stacks && res.stacks.length
        ? '<ul class="staxx-confirm-list">' + res.stacks.map(function (s) { return '<li>' + esc(s) + '</li>'; }).join('') + '</ul>'
        : '') +
      '<div class="staxx-buttons staxx-buttons--inline">' +
        '<button type="button" class="staxx-btn" id="staxx-firstrun-back">Back</button>' +
        '<button type="button" class="staxx-btn staxx-btn--primary" id="staxx-firstrun-create">Use this store</button>' +
      '</div>';

    document.getElementById('staxx-firstrun-back').addEventListener('click', function () { renderMain(state.options); });
    document.getElementById('staxx-firstrun-create').addEventListener('click', function () { doCreate(path); });
  }

  function doCreate(path) {
    msgEl.textContent = 'Setting up…';
    call('store-create', { path: path }, 30000).then(function (res) {
      if (!res.ok) { msgEl.textContent = res.error || 'Could not use this location.'; return; }
      // The store just came into existence — everything the placeholder
      // panel and every gate ahead of it were waiting on. A reload is the
      // simplest way to let the whole page pick that up, the same way a
      // completed relocation reloads the main Stacks page.
      msgEl.textContent = 'Done — reloading…';
      location.reload();
    });
  }

  function useLocation() {
    var input = document.getElementById('staxx-firstrun-path');
    if (!input) return;
    var path = input.value;
    var btn = useBtn();
    if (btn) btn.disabled = true;
    var line = checkLine();
    if (line) line.textContent = 'Looking at what is already there…';

    call('store-inspect', { path: path }).then(function (res) {
      if (!res.ok) {
        if (line) { line.textContent = res.error || 'Could not look at that folder.'; line.classList.add('staxx-error'); }
        if (btn) btn.disabled = false;
        return;
      }
      renderConfirm(path, res);
    });
  }

  bodyEl.addEventListener('click', function (event) {
    if (event.target.closest('#staxx-firstrun-use')) { useLocation(); return; }
    if (event.target.closest('#staxx-firstrun-browse')) {
      var box = document.getElementById('staxx-firstrun-path');
      if (box) pkOpen(box);
      return;
    }
    var suggest = event.target.closest('[data-firstrun-suggest]');
    if (suggest) {
      event.preventDefault();
      var sbox = document.getElementById('staxx-firstrun-path');
      if (sbox) {
        sbox.value = suggest.dataset.firstrunSuggest;
        sbox.dispatchEvent(new Event('input', { bubbles: true }));
        sbox.focus();
      }
    }
  });

  bodyEl.addEventListener('input', function (event) {
    var input = event.target.closest('#staxx-firstrun-path');
    if (!input) return;
    debounceCheck(input.value);
    updateDesc(input.value);
  });

  /* --------------------------------------------------------- open/close */

  function openFirstRun() {
    if (!dlg.open) dlg.showModal();
    loadOptions();
  }

  closeBtn.addEventListener('click', function () { dlg.close(); });
  dlg.addEventListener('click', function (event) {
    if (event.target !== dlg) return;
    var r = dlg.getBoundingClientRect();
    if (event.clientX < r.left || event.clientX > r.right ||
        event.clientY < r.top  || event.clientY > r.bottom) dlg.close();
  });

  // The placeholder paragraph rendered by StacksPage.php is what remains
  // visible once this dialog is dismissed — that is the "no data store has
  // been chosen, with a button to reopen it" state the plan asks for, and it
  // needs no separate markup because the paragraph was already exactly that.
  openBtn.addEventListener('click', openFirstRun);

  // The dialog is offered unasked the first time this page is seen — a
  // blank STORE_ROOT is the one and only trigger (see StacksPage.php), so
  // there is nothing else to check before opening it here.
  openFirstRun();
})();
