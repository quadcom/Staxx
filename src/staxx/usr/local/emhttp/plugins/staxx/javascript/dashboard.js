/* StaXX — behaviour for the Dashboard tile (PLAN_183 sections 5 and 6).
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 * Plain browser JavaScript, no libraries. Talks only to actions the server
 * already has: `dash_state` (built alongside this file by another agent —
 * see the contract in the PLAN_183 brief), and the existing `run` and
 * `stats` actions (notes/jobs.md, notes/stats.md) that every other part of
 * StaXX already uses. Nothing here is specific to this file on the server.
 */

(function () {
  'use strict';

  var root = document.querySelector('.staxx-tile-root');
  if (!root) return;

  var grid  = document.getElementById('staxx-tile-grid');
  var subEl = document.getElementById('staxx-tile-sub');
  var mark  = root.querySelector('.staxx-tile-mark');
  if (!grid || !subEl) return;

  var ENDPOINT = root.dataset.endpoint;
  // Unraid's own global on every page, used only when this tile's own
  // data-csrf attribute could not be read (see the .page file's header
  // comment on why it is read straight from var.ini instead of $var).
  var CSRF = root.dataset.csrf || (typeof window.csrf_token !== 'undefined' ? window.csrf_token : '');
  // The StaXX view's own address with no marker — StaXX.page or
  // Docker/Stacks depending on HEADER_MENU (notes/pages.md); read back off
  // the mark's own href rather than duplicated here, so the two can never
  // disagree.
  var VIEW_URL = mark ? mark.getAttribute('href').split('#')[0] : '/StaXX';

  var STATE_POLL = 5000;
  var STATS_POLL = 2000;
  var HISTORY_LEN = 60; // 2 minutes at one sample every 2s

  var state = null;          // last dash_state reply
  var openFolderId = null;
  var autoState = {};        // folder id -> { idx, timer }
  var menuEl = null;

  var statsOverlay = null;
  var statsProject = null;
  var statsWin = null;
  var statsTimer = null;
  var statHistory = null;
  var netPrev = null;

  /* ---------------------------------------------------------------- net -- */

  // URLSearchParams, never FormData — a multipart POST hangs on this box
  // rather than answering (memory "Multipart POSTs hang on the box"; the
  // same reasoning is spelled out in full in stacks.js).
  function call(action, fields) {
    var data = new URLSearchParams();
    data.append('csrf_token', CSRF);
    data.append('action', action);
    Object.keys(fields || {}).forEach(function (k) { data.append(k, fields[k]); });
    return fetch(ENDPOINT, { method: 'POST', body: data, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .catch(function () { return { ok: false }; });
  }

  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function stackOf(project) { return state && state.stacks ? state.stacks[project] : null; }

  /* ---- icons -------------------------------------------------------------
   * icon is "auto" (folders only), "plain:<glyph>" (a built-in Font Awesome
   * 4 name), or a file name looked up in iconUrls. Anything unrecognised
   * falls back to a plain cube glyph rather than an empty square.
   */
  function resolveIcon(icon) {
    if (!icon) return { kind: 'plain', glyph: 'cube' };
    if (icon === 'auto') return { kind: 'auto' };
    if (icon.indexOf('plain:') === 0) return { kind: 'plain', glyph: icon.slice(6) || 'cube' };
    // A stack's icon arrives as an address already; only a folder's picked
    // picture is a bare file name to look up.
    var url = /^(https?:)?\/|^data:/.test(icon) ? icon
            : (state && state.iconUrls ? state.iconUrls[icon] : null);
    return url ? { kind: 'url', url: url } : { kind: 'plain', glyph: 'cube' };
  }

  function paintIcon(sq, iconVal, folder, names) {
    var spec = resolveIcon(iconVal);
    if (spec.kind === 'auto') { startAuto(sq, folder, names); return; }
    if (spec.kind === 'url') {
      var img = document.createElement('img');
      img.className = folder ? 'staxx-tile-icon--cover' : 'staxx-tile-icon';
      img.alt = '';
      img.src = spec.url;
      sq.appendChild(img);
    } else {
      var g = document.createElement('i');
      g.className = 'staxx-tile-glyph fa fa-' + spec.glyph;
      g.setAttribute('aria-hidden', 'true');
      sq.appendChild(g);
    }
  }

  // A true cross-fade that keeps running across redraws: autoState[id]
  // persists in the module, so a fresh pair of <img> layers picks up where
  // the last pair left off (same idx) even though the whole grid is
  // rebuilt every poll. The 0.6s opacity transition is a fade, not motion,
  // so it is never gated behind prefers-reduced-motion (PLAN_202's ruling,
  // restated for this feature in the PLAN_183 brief).
  function startAuto(sq, folder, names) {
    var icons = [];
    (names || []).forEach(function (p) {
      var s = stackOf(p);
      if (!s) return;
      var spec = resolveIcon(s.icon);
      if (spec.kind === 'url') icons.push(spec.url);
    });
    if (!icons.length) {
      var g = document.createElement('i');
      g.className = 'staxx-tile-glyph fa fa-folder';
      g.setAttribute('aria-hidden', 'true');
      sq.appendChild(g);
      return;
    }

    var id = folder.id;
    if (!autoState[id]) autoState[id] = { idx: 0, timer: null };
    var st = autoState[id];
    if (st.idx >= icons.length) st.idx = 0;
    clearTimeout(st.timer);

    var a = document.createElement('img');
    a.className = 'staxx-tile-auto-layer is-front';
    a.alt = ''; a.src = icons[st.idx];
    var b = document.createElement('img');
    b.className = 'staxx-tile-auto-layer';
    b.alt = '';
    sq.appendChild(a);
    sq.appendChild(b);

    if (icons.length < 2) return;
    var front = a, back = b;
    var tick = function () {
      st.idx = (st.idx + 1) % icons.length;
      back.src = icons[st.idx];
      void back.offsetWidth; // force layout so the opacity change transitions
      front.classList.remove('is-front');
      back.classList.add('is-front');
      var t = front; front = back; back = t;
      st.timer = setTimeout(tick, 2000);
    };
    st.timer = setTimeout(tick, 2000);
  }

  /* ---- state: green/amber/red/none, the page's own chip colours --------- */
  function folderState(names) {
    var any = false, allRun = true, anyRun = false, anyFail = false;
    names.forEach(function (p) {
      var s = stackOf(p);
      if (!s) return;
      any = true;
      if (s.state === 'failed') anyFail = true;
      if (s.state === 'running') anyRun = true; else allRun = false;
      if (s.state === 'partial') anyRun = true;
    });
    if (!any) return 'stopped';
    if (anyFail) return 'failed';
    if (allRun) return 'running';
    if (anyRun) return 'partial';
    return 'stopped';
  }

  function dotClass(st) {
    if (st === 'running') return 'staxx-tile-dot--run';
    if (st === 'partial') return 'staxx-tile-dot--part';
    if (st === 'failed') return 'staxx-tile-dot--fail';
    return null; // stopped: no dot at all
  }

  /* ---- building one square (also reused, at a smaller size, inside an
     opened folder's panel) ------------------------------------------------ */
  function buildCell(it, sidePx, forPanel) {
    var cell = document.createElement('div');
    cell.className = 'staxx-tile-cell';
    cell.style.width = sidePx + 'px';
    cell.style.height = sidePx + 'px';

    var sq = document.createElement('div');
    sq.className = 'staxx-tile-square';

    // An empty cell keeps its size so the grid lines up, but draws nothing.
    if (!it) return cell;

    sq.className += ' staxx-tile-square--filled';

    var names, iconVal, label, st;
    if (it.type === 'folder') {
      names = (it.stacks || []).filter(function (p) { return stackOf(p); });
      iconVal = it.icon;
      label = it.name;
      st = folderState(names);
      if (it.id === openFolderId) sq.className += ' staxx-tile-square--open';
      if (it.bg) sq.style.background = it.bg;
    } else {
      var s = stackOf(it.stack);
      names = s ? [it.stack] : [];
      iconVal = s ? s.icon : '';
      label = s ? s.name : it.stack;
      st = s ? s.state : 'stopped';
    }

    paintIcon(sq, iconVal, it.type === 'folder' ? it : null, names);

    var hasFade = it.type === 'folder' && (!!it.bg || resolveIcon(it.icon).kind === 'url');
    var nameEl = document.createElement('div');
    nameEl.className = 'staxx-tile-name' + (hasFade ? ' staxx-tile-name--fade' : '');
    nameEl.textContent = label;
    nameEl.title = label; // full name when the ellipsis cuts it short
    sq.appendChild(nameEl);

    var dc = dotClass(st);
    if (dc) {
      var dot = document.createElement('div');
      dot.className = 'staxx-tile-dot ' + dc;
      sq.appendChild(dot);
    }

    sq.addEventListener('click', function (ev) {
      ev.stopPropagation();
      if (it.type === 'folder') { toggleFolder(it.id); } else { openStats(it.stack); }
    });
    sq.addEventListener('contextmenu', function (ev) {
      ev.preventDefault();
      ev.stopPropagation();
      if (it.type === 'folder') openFolderMenu(ev, it, names); else openStackMenu(ev, it.stack);
    });

    cell.appendChild(sq);
    return cell;
  }

  function toggleFolder(id) {
    openFolderId = (openFolderId === id) ? null : id;
    render();
  }

  function buildPanel(folder, cellPx) {
    var wrap = document.createElement('div');
    wrap.className = 'staxx-tile-panel-wrap';

    var panel = document.createElement('div');
    panel.className = 'staxx-tile-panel';

    var names = (folder.stacks || []).filter(function (p) { return stackOf(p); });
    if (!names.length) {
      var empty = document.createElement('div');
      empty.className = 'staxx-tile-panel-empty';
      empty.textContent = 'Nothing in this folder yet.';
      panel.appendChild(empty);
    } else {
      var mini = cellPx * 3 / 4;
      // The cell is exactly the square, so only the panel gap spaces them. The squares read --sq from the grid; without their own smaller value
      // here they stay full size and spill out of the panel's bottom edge.
      var sqMini = Math.max(1, mini - 7);
      panel.style.setProperty('--sq', sqMini + 'px');
      panel.style.setProperty('--rad', (sqMini * 22 / 96) + 'px');
      panel.style.setProperty('--name-fs', Math.max(10, 12 * sqMini / 96) + 'px');
      names.forEach(function (p) {
        panel.appendChild(buildCell({ type: 'stack', stack: p }, sqMini, true));
      });
    }
    wrap.appendChild(panel);

    // Added after the panel so its lower half paints over the panel's top
    // border. Centred on the folder's real grid column (cellPx is the main
    // grid's cell, not the panel's smaller one); 6px is half the arrow's width.
    var arrow = document.createElement('div');
    arrow.className = 'staxx-tile-panel-arrow';
    arrow.style.left = Math.max(14, folder.c * cellPx + cellPx / 2 - 6) + 'px';
    wrap.appendChild(arrow);
    return wrap;
  }

  /* ---- the whole tile ----------------------------------------------------
   * Rebuilt on every poll and every resize — cheap enough at a handful of
   * cells a page, and far simpler than patching a live tree in place. */
  function render() {
    if (!state) return;
    var layout = state.layout || { cols: 0, rows: 0, items: [] };
    var stacks = state.stacks || {};
    var cols = Math.max(1, layout.cols || 1);
    var rows = Math.max(1, layout.rows || 1);

    // Only stacks placed on the tile (loose or inside a tile folder) count.
    var placed = {}, total = 0, running = 0;
    (layout.items || []).forEach(function (it) {
      (it.type === 'folder' ? (it.stacks || []) : [it.stack]).forEach(function (p) {
        if (stacks[p] && !placed[p]) {
          placed[p] = true;
          total++;
          if (stacks[p].running > 0) running++;
        }
      });
    });
    subEl.textContent = total + ' stack' + (total === 1 ? '' : 's') + ' · ' + running + ' running';

    // The contract's own rule (PLAN_183 section 7): an item whose stack (or
    // every stack inside a folder) is gone from `stacks` is skipped rather
    // than drawn empty or broken.
    var byCell = {};
    (layout.items || []).forEach(function (it) {
      if (it.type === 'stack' && !stacks[it.stack]) return;
      byCell[it.c + ',' + it.r] = it;
    });

    var cellPx = grid.clientWidth > 0 ? grid.clientWidth / cols : 96;
    grid.style.setProperty('--sq', Math.max(1, cellPx - 7) + 'px');
    grid.style.setProperty('--rad', ((Math.max(1, cellPx - 7)) * 22 / 96) + 'px');
    grid.style.setProperty('--name-fs', Math.max(10, 12 * Math.max(1, cellPx - 7) / 96) + 'px');

    grid.innerHTML = '';
    var activeFolders = {};
    for (var r = 0; r < rows; r++) {
      var rowEl = document.createElement('div');
      rowEl.className = 'staxx-tile-row';
      var openInThisRow = null;
      for (var c = 0; c < cols; c++) {
        var it = byCell[c + ',' + r];
        if (it && it.type === 'folder') {
          activeFolders[it.id] = true;
          if (it.id === openFolderId) openInThisRow = it;
        }
        rowEl.appendChild(buildCell(it, cellPx, false));
      }
      grid.appendChild(rowEl);
      if (openInThisRow) grid.appendChild(buildPanel(openInThisRow, cellPx));
    }

    // A folder removed from the tile (or emptied of its own project) stops
    // cross-fading rather than ticking away on detached nodes forever.
    Object.keys(autoState).forEach(function (id) {
      if (!activeFolders[id]) { clearTimeout(autoState[id].timer); delete autoState[id]; }
    });
    if (openFolderId && !activeFolders[openFolderId]) openFolderId = null;
  }

  /* ---- polling dash_state ------------------------------------------------ */
  function fetchState() {
    if (document.hidden) return;
    call('dash_state', {}).then(function (res) {
      if (!res || !res.ok) return;
      state = res;
      render();
      if (statsProject) refreshStatsHeader();
    });
  }

  /* ---- right-click menus -------------------------------------------------- */
  function closeMenu() {
    if (!menuEl) return;
    menuEl.remove();
    menuEl = null;
    document.removeEventListener('click', closeMenu, true);
  }

  function showMenu(x, y, buildFn) {
    closeMenu();
    var m = document.createElement('div');
    m.className = 'staxx-tile-menu';
    buildFn(m);
    document.body.appendChild(m);
    var w = m.offsetWidth, h = m.offsetHeight;
    m.style.left = Math.max(4, Math.min(x, window.innerWidth - w - 8)) + 'px';
    m.style.top = Math.max(4, Math.min(y, window.innerHeight - h - 8)) + 'px';
    menuEl = m;
    setTimeout(function () { document.addEventListener('click', closeMenu, true); }, 0);
  }

  function menuItem(m, label, glyph, disabled, fn) {
    var d = document.createElement('div');
    d.className = 'staxx-tile-menu-item' + (disabled ? ' staxx-tile-menu-item--off' : '');
    var i = document.createElement('i');
    i.className = 'fa fa-' + glyph;
    i.setAttribute('aria-hidden', 'true');
    var s = document.createElement('span');
    s.textContent = label;
    d.appendChild(i);
    d.appendChild(s);
    if (!disabled) d.addEventListener('click', function () { closeMenu(); fn(); });
    m.appendChild(d);
  }

  function openFolderMenu(ev, folder, names) {
    var canRun = !!(state && state.canRun);
    var anyRunning = names.some(function (p) { var s = stackOf(p); return s && s.running > 0; });
    var allRunning = names.length > 0 && names.every(function (p) {
      var s = stackOf(p); return s && s.total > 0 && s.running === s.total;
    });
    showMenu(ev.clientX, ev.clientY, function (m) {
      var h = document.createElement('div');
      h.className = 'staxx-tile-menu-header';
      h.textContent = folder.name;
      m.appendChild(h);
      var sub = document.createElement('div');
      sub.className = 'staxx-tile-menu-sub';
      sub.textContent = names.length + ' stack' + (names.length === 1 ? '' : 's') + ' inside';
      m.appendChild(sub);
      menuItem(m, 'Start all', 'play', !canRun || allRunning, function () {
        names.forEach(function (p) { runVerb(p, 'up'); });
      });
      menuItem(m, 'Stop all', 'stop', !canRun || !anyRunning, function () {
        names.forEach(function (p) { runVerb(p, 'down'); });
      });
      menuItem(m, 'Restart all', 'refresh', !canRun, function () {
        names.forEach(function (p) { runVerb(p, 'restart'); });
      });
    });
  }

  function openStackMenu(ev, project) {
    var s = stackOf(project);
    if (!s) return;
    var canRun = !!(state && state.canRun);
    var running = s.running > 0;
    var allRunning = s.total > 0 && s.running === s.total;
    showMenu(ev.clientX, ev.clientY, function (m) {
      var h = document.createElement('div');
      h.className = 'staxx-tile-menu-header';
      h.textContent = s.name;
      m.appendChild(h);
      menuItem(m, 'Start', 'play', !canRun || allRunning, function () { runVerb(project, 'up'); });
      menuItem(m, 'Stop', 'stop', !canRun || !running, function () { runVerb(project, 'down'); });
      menuItem(m, 'Restart', 'refresh', !canRun, function () { runVerb(project, 'restart'); });
    });
  }

  // One job per stack, as the brief asks — no batching. `dash_state` is
  // re-polled shortly after rather than followed job-by-job, which keeps
  // this file far shorter than stacks.js's own job-log following; a command
  // that is still running when the second poll lands simply shows its
  // in-between state, which the next 5s poll corrects.
  function runVerb(project, verb) {
    call('run', { name: project, verb: verb });
    setTimeout(fetchState, 1200);
    setTimeout(fetchState, 4000);
  }

  /* ====================================================================
   * The statistics window (section 6)
   * ==================================================================== */

  function tileFolderNameOf(project) {
    var items = (state && state.layout && state.layout.items) || [];
    for (var i = 0; i < items.length; i++) {
      var it = items[i];
      if (it.type === 'folder' && (it.stacks || []).indexOf(project) !== -1) return it.name;
    }
    return null;
  }

  function chipClass(st) {
    if (st === 'running') return 'staxx-dash-chip--run';
    if (st === 'partial') return 'staxx-dash-chip--part';
    if (st === 'failed') return 'staxx-dash-chip--fail';
    return 'staxx-dash-chip--stop';
  }
  function chipLabel(st) {
    if (st === 'running') return 'Running';
    if (st === 'partial') return 'Partial';
    if (st === 'failed') return 'Failed';
    return 'Stopped';
  }

  function openStats(project) {
    var s = stackOf(project);
    if (!s) return;
    closeMenu();
    closeStats();

    statsProject = project;
    statHistory = { cpu: [], mem: [], net: [], gpu: [] };
    netPrev = null;

    var overlay = document.createElement('div');
    overlay.className = 'staxx-dash-stats-overlay';
    overlay.addEventListener('click', function (ev) { if (ev.target === overlay) closeStats(); });

    var win = document.createElement('div');
    win.className = 'staxx-dash-stats';
    overlay.appendChild(win);
    document.body.appendChild(overlay);

    statsOverlay = overlay;
    statsWin = win;
    buildStatsWindow(win, s);

    pollStatsOnce();
    statsTimer = setInterval(pollStatsOnce, STATS_POLL);
  }

  function closeStats() {
    if (statsTimer) { clearInterval(statsTimer); statsTimer = null; }
    if (statsOverlay) { statsOverlay.remove(); statsOverlay = null; }
    statsWin = null;
    statsProject = null;
    statHistory = null;
    netPrev = null;
  }

  function buildStatsWindow(win, s) {
    var folderName = tileFolderNameOf(statsProject);
    var iconSpec = resolveIcon(s.icon);
    var iconHtml = iconSpec.kind === 'url'
      ? '<img class="staxx-dash-stats-icon" alt="" src="' + escapeHtml(iconSpec.url) + '">'
      : '<i class="staxx-dash-stats-icon fa fa-' + escapeHtml(iconSpec.glyph || 'cube') + '" '
        + 'aria-hidden="true" style="font-size:2.8rem;color:#e68a00;width:28px;"></i>';

    var servicesHtml = (s.services || []).map(function (svc) {
      return '<div>' + escapeHtml(svc.name) + ' <span>· ' + escapeHtml(svc.image) + '</span></div>';
    }).join('');

    win.innerHTML =
      '<div class="staxx-dash-stats-head">'
        + '<button type="button" class="staxx-dash-stats-close" data-act="close" title="Close" aria-label="Close">'
        + '<i class="fa fa-times" aria-hidden="true"></i></button>'
        + '<div class="staxx-dash-stats-headrow">' + iconHtml
        + '<span class="staxx-dash-stats-name">' + escapeHtml(s.name) + '</span>'
        + '<span class="staxx-dash-chip ' + chipClass(s.state) + '">' + chipLabel(s.state) + '</span>'
        + '</div>'
        + '<div class="staxx-dash-stats-sub">'
        // Where it sits on the tile, then dash_state's uptime ("up 5 days",
        // from Docker's own status; empty when nothing runs).
        + (folderName ? 'In the &quot;' + escapeHtml(folderName) + '&quot; folder on the tile' : 'On the tile')
        + (s.uptime ? ' · ' + escapeHtml(s.uptime) : '')
        + '</div>'
      + '</div>'
      + (s.address ? '<div class="staxx-dash-stats-row"><b>Address</b>' + escapeHtml(s.address) + '</div>' : '')
      + '<div class="staxx-dash-stats-row staxx-dash-stats-services"><b>Services</b>' + servicesHtml + '</div>'
      + '<div class="staxx-dash-stats-actions">'
        + '<button type="button" class="staxx-dash-btn" data-act="up"><i class="fa fa-play" aria-hidden="true"></i> Start</button>'
        + '<button type="button" class="staxx-dash-btn" data-act="down"><i class="fa fa-stop" aria-hidden="true"></i> Stop</button>'
        + '<button type="button" class="staxx-dash-btn" data-act="recreate"><i class="fa fa-refresh" aria-hidden="true"></i> Recreate</button>'
      + '</div>'
      + '<div class="staxx-dash-cards">'
        + '<div class="staxx-dash-card staxx-dash-card--cpu" data-card="cpu">'
          + '<div class="staxx-dash-card-head"><span class="staxx-dash-card-title">CPU</span>'
          + '<span class="staxx-dash-card-sub">of the whole CPU</span></div>'
          + '<div class="staxx-dash-card-value" data-val>&nbsp;</div><div data-graph></div></div>'
        + '<div class="staxx-dash-card staxx-dash-card--mem" data-card="mem">'
          + '<div class="staxx-dash-card-head"><span class="staxx-dash-card-title">Memory</span>'
          + '<span class="staxx-dash-card-sub">last 2 min</span></div>'
          + '<div class="staxx-dash-card-value" data-val>&nbsp;</div><div data-graph></div></div>'
        + '<div class="staxx-dash-card staxx-dash-card--net" data-card="net">'
          + '<div class="staxx-dash-card-head"><span class="staxx-dash-card-title">Network</span>'
          + '<span class="staxx-dash-card-sub">last 2 min</span></div>'
          + '<div class="staxx-dash-card-value" data-val>&nbsp;</div><div data-graph></div></div>'
        + (s.gpu ? '<div class="staxx-dash-card staxx-dash-card--gpu" data-card="gpu">'
          + '<div class="staxx-dash-card-head"><span class="staxx-dash-card-title">GPU</span>'
          + '<span class="staxx-dash-card-sub">last 2 min</span></div>'
          + '<div class="staxx-dash-card-value" data-val>&nbsp;</div><div data-graph></div></div>' : '')
      + '</div>'
      + '<div class="staxx-dash-stats-foot">'
        + (s.webui ? '<a class="staxx-dash-foot-btn" target="_blank" rel="noopener" href="'
            + escapeHtml(s.webui) + '"><i class="fa fa-external-link" aria-hidden="true"></i> Open web page</a>' : '')
        + '<a class="staxx-dash-foot-btn" data-foot="logs"><i class="fa fa-file-text-o" aria-hidden="true"></i> Logs</a>'
        + '<a class="staxx-dash-foot-btn" data-foot="row"><i class="fa fa-external-link" aria-hidden="true"></i> Open in StaXX</a>'
      + '</div>';

    win.querySelector('[data-act="close"]').addEventListener('click', closeStats);
    win.querySelector('[data-act="up"]').addEventListener('click', function () { runVerb(statsProject, 'up'); });
    win.querySelector('[data-act="down"]').addEventListener('click', function () { runVerb(statsProject, 'down'); });
    win.querySelector('[data-act="recreate"]').addEventListener('click', function () { runVerb(statsProject, 'recreate'); });

    var logsBtn = win.querySelector('[data-foot="logs"]');
    logsBtn.href = VIEW_URL + '#logs=' + encodeURIComponent(s.path);
    var rowBtn = win.querySelector('[data-foot="row"]');
    rowBtn.href = VIEW_URL + '#row=' + encodeURIComponent(s.path);

    paintStatsButtons(s);
  }

  function paintStatsButtons(s) {
    if (!statsWin) return;
    var canRun = !!(state && state.canRun);
    var running = s.running > 0;
    var allRunning = s.total > 0 && s.running === s.total;
    var up = statsWin.querySelector('[data-act="up"]');
    var down = statsWin.querySelector('[data-act="down"]');
    var recreate = statsWin.querySelector('[data-act="recreate"]');
    if (up) { up.disabled = !canRun || allRunning; up.title = allRunning ? 'It is already running' : ''; }
    if (down) { down.disabled = !canRun || !running; down.title = running ? '' : 'Nothing is running'; }
    if (recreate) recreate.disabled = !canRun;
    var chip = statsWin.querySelector('.staxx-dash-chip');
    if (chip) { chip.className = 'staxx-dash-chip ' + chipClass(s.state); chip.textContent = chipLabel(s.state); }
  }

  // Called after every dash_state poll while the window is open, so the
  // chip and buttons track a change made from another tab or from the
  // Stacks page itself, not only from this window's own buttons.
  function refreshStatsHeader() {
    var s = stackOf(statsProject);
    if (!s) { closeStats(); return; }
    paintStatsButtons(s);
  }

  function pushSample(arr, v) {
    arr.push(v);
    if (arr.length > HISTORY_LEN) arr.shift();
  }

  function graphSvg(values, min, max, color) {
    if (!values.length) return '<svg class="staxx-dash-card-graph" viewBox="0 0 100 30" preserveAspectRatio="none"></svg>';
    var n = values.length;
    var span = (max - min) || 1;
    var pts = [];
    for (var i = 0; i < n; i++) {
      var x = n > 1 ? (i / (n - 1)) * 100 : 100;
      var y = 29 - ((values[i] - min) / span) * 27;
      pts.push(x.toFixed(1) + ',' + y.toFixed(1));
    }
    var line = pts.join(' ');
    var area = '0,30 ' + line + ' 100,30';
    return '<svg class="staxx-dash-card-graph" viewBox="0 0 100 30" preserveAspectRatio="none">'
      + '<polygon points="' + area + '" fill="' + color + '" fill-opacity="0.22"></polygon>'
      + '<polyline points="' + line + '" fill="none" stroke="' + color + '" stroke-width="1.6"></polyline>'
      + '</svg>';
  }

  function bytes(n) {
    if (!n) return '0 B';
    var units = ['B', 'KiB', 'MiB', 'GiB'];
    var i = 0;
    while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
    return (i === 0 ? n.toFixed(0) : n.toFixed(1)) + ' ' + units[i];
  }

  // Both directions share the larger rate's unit, e.g. "↓ 1.9 ↑ 0.3 MiB/s".
  function netText(rx, tx) {
    var units = ['B', 'KiB', 'MiB', 'GiB'], big = Math.max(rx, tx), i = 0;
    while (big >= 1024 && i < units.length - 1) { big /= 1024; i++; }
    var div = Math.pow(1024, i), d = i === 0 ? 0 : 1;
    return '↓ ' + (rx / div).toFixed(d) + ' ↑ ' + (tx / div).toFixed(d) + ' ' + units[i] + '/s';
  }

  function paintCard(win, key, valueHtml, values, min, max, color) {
    var card = win.querySelector('[data-card="' + key + '"]');
    if (!card) return;
    card.querySelector('[data-val]').innerHTML = valueHtml;
    card.querySelector('[data-graph]').innerHTML = graphSvg(values, min, max, color);
  }

  function pollStatsOnce() {
    if (!statsProject) return;
    var project = statsProject;
    call('stats', {}).then(function (res) {
      if (!res || !res.ok || statsProject !== project || !statsWin) return;
      var s = (res.stacks || {})[project];
      if (!s) return;

      var now = Date.now();
      var rx = 0, tx = 0;
      if (netPrev) {
        var dt = (now - netPrev.t) / 1000;
        if (dt > 0) {
          rx = Math.max(0, s.netRx - netPrev.netRx) / dt;
          tx = Math.max(0, s.netTx - netPrev.netTx) / dt;
        }
      }
      netPrev = { netRx: s.netRx, netTx: s.netTx, t: now };

      pushSample(statHistory.cpu, s.cpu || 0);
      pushSample(statHistory.mem, s.memUsed || 0);
      pushSample(statHistory.net, rx + tx);
      if (s.gpuMapped) pushSample(statHistory.gpu, s.gpu || 0);

      paintCard(statsWin, 'cpu', (s.cpu || 0).toFixed(1) + '<small>%</small>', statHistory.cpu, 0, 100, '#e68a00');
      paintCard(statsWin, 'mem', bytes(s.memUsed || 0), statHistory.mem,
        Math.min.apply(null, statHistory.mem), Math.max.apply(null, statHistory.mem), '#4aa3df');
      paintCard(statsWin, 'net', netText(rx, tx), statHistory.net,
        Math.min.apply(null, statHistory.net), Math.max.apply(null, statHistory.net), '#8bc34a');
      if (s.gpuMapped) {
        paintCard(statsWin, 'gpu', (s.gpu || 0).toFixed(1) + '<small>%</small>', statHistory.gpu, 0, 100, '#b388ff');
      }
    });
  }

  /* ---- lifecycle ---------------------------------------------------------- */
  var resizeTimer = null;
  window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(render, 120);
  });

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) fetchState();
  });

  fetchState();
  setInterval(fetchState, STATE_POLL);
})();
