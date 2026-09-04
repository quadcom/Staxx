/* StaXX — the Manage tab's own contents.
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 * The container tab row (D1), the buttons column (D2), and the three
 * panes: the log follower (D3), the shell (D4), and the file browser
 * inside a running container (D5).
 *
 * Separate from stacks.js on purpose, same reasoning as compose-model.js:
 * that file is one 14,000-line IIFE where a single typo kills every
 * behaviour on the page, and Manage is a large addition. A bad edit in here
 * costs the Manage tab, not the table, the menus or the editor's Configure
 * side.
 *
 * This file renders and dispatches only. It never calls a server action and
 * never polls — the host page hands it a snapshot each tick via
 * setSnapshot(), and it hands every run request back out via onRun(). That
 * is what keeps "who reports a running command" to one place, which is the
 * row's state column, not here.
 */

(function () {
  'use strict';

  // Container states that read as fine — anything else on a created
  // container gets the warning marker, because "exited with status 0" and
  // "exited with status 1" are both just "exited" without more information
  // than the state snapshot gives here.
  var OK_STATE = { running: true, exited: true };

  // PLAN_44 D3, the log pane.
  //
  // Lines kept per follower. A follower left running for an hour must not
  // grow without limit, so the oldest lines are dropped past this and one
  // note line says so the first time it happens — not every time, or the
  // notice itself would scroll the real content away.
  var LOG_CAP = 4000;

  // How often log-read is polled. This IS the keep-alive the server's
  // heartbeat rule expects — reading is what tells it someone is still
  // watching, so there is deliberately no separate ping.
  var POLL_MS = 1000;

  // The log pane polls faster than that while output is actually arriving.
  // A flat one-second poll made a busy container read a second behind and
  // arrive in visible clumps, which is what "not real-time" looked like. A
  // read that brings text is evidence more is coming, so the next one goes
  // out quickly; a read that brings nothing eases back towards the idle rate
  // rather than holding a fast poll against a container saying nothing. The
  // idle rate is unchanged, so a quiet pane costs exactly what it did.
  var LOG_POLL_FAST = 200;
  var LOG_POLL_IDLE = POLL_MS;
  var LOG_POLL_EASE = 1.6;

  // While the tab is hidden, poll this slowly instead — PLAN_132 B. Must
  // stay under the server's 45-second STAXX_LOG_STALE, or a follower left
  // running in a background tab is reaped for want of a read; 30s leaves a
  // comfortable margin. The offset still advances at this rate, so nothing
  // is lost — the next read just brings more at once.
  var LOG_POLL_HIDDEN = 30000;

  // PLAN_44 D7. The same 45rem the stylesheet uses, and deliberately the
  // same string — stacks.js's own NARROW carries the reasoning (a rem inside
  // a media query is the browser's default font size, not the 62.5% Unraid
  // sets on the root, so writing this any other way would put the two
  // thresholds in different places). Kept as this file's own copy rather
  // than reading stacks.js's: the two files are deliberately independent, so
  // a typo in one cannot take the other down.
  var NARROW = window.matchMedia('(max-width: 45rem)');

  // PLAN_52, the handle between the shell and the file panes.
  //
  // The shell's share of the right-hand column, as a flex-grow figure the
  // file pane subtracts from 2 — so 1 is an even split, and 1.4 gives the
  // shell 70%. Kept at module scope on purpose: "remembered until the page
  // reloads" is exactly what that means, so a new editor, another stack, or
  // a rebuilt instance all read the same number, and a reload starts even.
  var splitRatio = 1;
  var SPLIT_MIN_PX = 60;   // neither pane can be dragged below this
  var SPLIT_STEP = 0.1;    // one arrow-key nudge

  // The log pane's share of the whole body, same spelling as splitRatio
  // above — a second handle between the log and the shell/files column,
  // sitting alongside the one above rather than replacing it.
  var bodyRatio = 1;

  // PLAN_44 D4 / PLAN_133, the shell.
  //
  // How often a session's liveness is polled while its pane is showing. This
  // IS the session's keep-alive — see staxx_exec_alive() — so it has to stay
  // comfortably under the server's 45-second stale window, the same
  // reasoning LOG_POLL_HIDDEN carries for the log follower.
  var SHELL_ALIVE_POLL_MS = 3000;

  // A line compose printed carries a timestamp because the follower always
  // asks for one; the "prefix" a multi-container "All" tab adds (the
  // service name compose stamps in front) sits before it. Hiding timestamps
  // is a client-side choice, so this is parsed once per line and cached
  // rather than re-parsed every time the toggle changes.
  var LOG_TS_RE = /^([^\s|]+\s*\|\s*)?(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z) ?/;

  // Unmistakable marker for a line StaXX itself wrote, rather than something
  // a container said — see Instance.note() and the plan's "clearly marked
  // as StaXX's" requirement.
  var NOTE_PREFIX = 'StaXX » ';

  // The fallback used only when the host does not hand one in. It really
  // escapes, rather than just stringifying: everything this file interpolates
  // — service names above all — comes out of somebody's compose file, so a
  // name holding a < would otherwise inject markup the moment the host forgot
  // to pass its own escaper. A function called esc that does not escape is a
  // trap, not a shortcut.
  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  // One tab strip entry's live facts, looked up from the snapshot handed to
  // setSnapshot(). Never throws: a service with nothing in the snapshot has
  // simply never been created, which is a fact worth showing, not an error.
  function liveFor(snapshot, service) {
    if (!snapshot || !snapshot.containers) return null;
    return snapshot.containers[service] || null;
  }

  // A stopped container is not a warning; a container that exists but is
  // doing something other than plainly running or plainly stopped is.
  function isWarning(state) {
    if (!state) return false;
    return state !== 'running' && state !== 'exited';
  }

  function Instance(opts) {
    var host   = opts.host;
    // The one path to the server this whole file uses: the log follower,
    // the shell sessions and the file browser all go through it. onRun() is
    // the separate path the verb buttons use instead — see buildButtons().
    var call   = opts.call;
    // Not used anywhere in this file — every value interpolated into markup
    // here (service names, file names, paths) is inserted through
    // textContent, which needs no escaping. Kept and validated anyway,
    // since a fallback that silently stringified instead of escaping would
    // be a trap the moment some future render stops using textContent.
    var escFn  = typeof opts.esc === 'function' ? opts.esc : esc;
    var bytes  = typeof opts.bytes === 'function' ? opts.bytes : function (n) { return String(n); };
    var onRun  = typeof opts.onRun === 'function' ? opts.onRun : function () {};

    var state = {
      stack:      '',
      services:   [],
      icons:      {},
      snapshot:   null,
      selected:   'all',
      scope:      'container',  // 'container' | 'stack' — meaningless (and forced to stack) when selected === 'all'
      // Service name -> list of container-side paths that are mounts, read
      // straight from the compose model the host already parsed (PLAN_44
      // D5's mounted-folder marking). Absent for a caller that has not
      // wired this through yet, or a service with no mounts at all — either
      // way the file pane marks nothing and says nothing, rather than
      // guessing at paths it was never told about.
      mounts:     {},
      // PLAN_44 D7 — which of the three panes a narrow screen is currently
      // showing. Meaningless, and ignored, above the breakpoint, where all
      // three are visible at once.
      narrowPane: 'log'
    };

    var els = {};

    // unmount() clears the host's DOM (PLAN_44 C1 — whatever Manage was
    // showing dies with its editor), but this Instance is never recreated;
    // the same one mounts the next stack too. Without this, mount() after an
    // unmount would render into DOM nodes no longer attached to anything —
    // invisible, not merely stale. See mount() below.
    var torndown = false;

    // PLAN_132 B — registered once for this Instance's whole life (it is
    // never recreated, see above), not per mount/unmount, so it is removed
    // only when the Instance itself goes away.
    document.addEventListener('visibilitychange', onVisibilityChange);

    function build() {
      host.innerHTML = '';
      host.classList.add('staxx-manage');

      var tabs = document.createElement('div');
      tabs.className = 'staxx-manage-tabs';
      tabs.setAttribute('role', 'tablist');
      host.appendChild(tabs);
      els.tabs = tabs;

      host.appendChild(buildAbove());       // PLAN_44 D6
      host.appendChild(buildNarrowTabs());  // PLAN_44 D7

      var body = document.createElement('div');
      body.className = 'staxx-manage-body';
      host.appendChild(body);

      var logPane = pane('log', 'Log');
      buildLogBody(logPane.body);
      var right = document.createElement('div');
      right.className = 'staxx-manage-right';
      var shell = pane('shell', 'Shell');
      buildShellBody(shell.body);
      var files = pane('files', 'Files');
      buildFilesBody(files.body);
      right.appendChild(shell.el);
      right.appendChild(splitter(right, shell.el, files.el, {
        prop: '--sm-split',
        vertical: false,
        get: function () { return splitRatio; },
        set: function (v) { splitRatio = v; },
        title: 'Drag to share the room between the shell and the files. ' +
          'Double-click for an even split.'
      }));
      right.appendChild(files.el);

      body.appendChild(logPane.el);
      body.appendChild(splitter(body, logPane.el, right, {
        prop: '--sm-bodysplit',
        vertical: true,
        get: function () { return bodyRatio; },
        set: function (v) { bodyRatio = v; },
        title: 'Drag to share the room between the log and the shell. ' +
          'Double-click for an even split.'
      }));
      body.appendChild(right);

      els.log = logPane;
      els.shell = shell;
      els.files = files;

      buildHeadActions();
      updateNarrowPanes();

      // Repaints whatever the follower already holds onto the fresh DOM —
      // a no-op the first time (log.lines is still empty then), but the
      // reason a rebuild after unmount() does not open on a blank pane.
      renderLines();
    }

    // A collapsible pane: a heading that toggles a body. Proportions come
    // from CSS: the right-hand column's two panes read the --sm-split custom
    // property that the handle below writes, and collapsing still beats any
    // split, because the collapsed rule is declared later at the same
    // specificity. Nothing here measures anything.
    function pane(key, title) {
      var el = document.createElement('section');
      el.className = 'staxx-manage-pane staxx-manage-pane--' + key;

      // The heading is a row, not a button. It used to BE the button, which
      // left nowhere to put the pane's own action — a button inside a button
      // is not markup a browser honours. The collapse toggle is the title
      // alone now, so clicking an action beside it cannot fold the pane.
      var head = document.createElement('div');
      head.className = 'staxx-manage-pane-head';

      var h = document.createElement('button');
      h.type = 'button';
      h.className = 'staxx-manage-pane-title';
      h.textContent = title;
      h.setAttribute('aria-expanded', 'true');
      h.addEventListener('click', function () {
        // Below the breakpoint the narrow tab row (PLAN_44 D7) is what
        // chooses which pane shows, not a per-pane collapse — collapsing the
        // one pane a narrow screen has room for would just leave the screen
        // empty until someone thought to click the header a second time.
        if (NARROW.matches) return;
        var collapsed = el.classList.toggle('staxx-manage-pane--collapsed');
        h.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      });

      var body = document.createElement('div');
      body.className = 'staxx-manage-pane-body';
      body.innerHTML = '<p class="staxx-manage-placeholder">Not built yet — this is where the ' +
        title.toLowerCase() + ' will be.</p>';

      // Where this pane's own button goes — see buildHeadActions(). Left
      // empty for a pane with none, which keeps every heading the same shape.
      var actions = document.createElement('div');
      actions.className = 'staxx-manage-pane-actions';

      head.appendChild(h);
      head.appendChild(actions);
      el.appendChild(head);
      el.appendChild(body);
      // `head` stays the title button rather than the row around it, because
      // that is what carries aria-expanded and what revealPane() reaches for.
      return { el: el, head: h, actions: actions, body: body };
    }

    // A drag handle between two panes. It writes one custom property on the
    // column and the panes' own flex rules read it — never an inline style
    // on a pane, because an inline flex would outrank the collapsed rule and
    // a collapsed pane would keep its size. Used twice: the original
    // horizontal handle between the shell and files (opts.vertical false,
    // dragged up/down), and the vertical one between the log and the
    // shell/files column (opts.vertical true, dragged left/right). The two
    // differ only in which axis they measure and which module-scope ratio
    // they read and write, via opts.get/opts.set — everything else, down to
    // the pointer-capture refusal below, is identical.
    function splitter(column, aEl, bEl, opts) {
      var el = document.createElement('div');
      el.className = 'staxx-manage-split' +
        (opts.vertical ? ' staxx-manage-split--vertical' : '');
      el.setAttribute('role', 'separator');
      el.setAttribute('tabindex', '0');
      el.setAttribute('aria-orientation', opts.vertical ? 'vertical' : 'horizontal');
      el.title = opts.title;

      function collapsed() {
        return aEl.classList.contains('staxx-manage-pane--collapsed') ||
          bEl.classList.contains('staxx-manage-pane--collapsed');
      }

      function apply() {
        var r = opts.get();
        column.style.setProperty(opts.prop, String(r));
        el.setAttribute('aria-valuenow', String(Math.round(r * 50)));
      }

      // The floor is in pixels but what is stored is a ratio, so the split
      // holds its proportions across a window resize instead of pinning one
      // pane to a size that only made sense at one size.
      function set(r) {
        var size = opts.vertical ? column.clientWidth : column.clientHeight;
        var handleSize = opts.vertical ? el.offsetWidth : el.offsetHeight;
        var total = size - handleSize;
        var lo = total > SPLIT_MIN_PX * 2 ? (2 * SPLIT_MIN_PX) / total : 0.5;
        opts.set(Math.max(lo, Math.min(2 - lo, r)));
        apply();
      }

      // The moving and the letting go are listened for on the window, not on
      // the handle, and pointer capture is not used at all. Capture looked
      // like the tidier answer and was tried first: the release arrived as a
      // retargeted event the handle never saw, so the drag flag — and with it
      // the whole page's "do not select text" — stayed on after the mouse came
      // up. Listeners added for the length of the drag and removed at the end
      // of it cannot get stuck that way.
      var dragging = false;

      function onMove(ev) {
        var box = column.getBoundingClientRect();
        var size = opts.vertical ? column.clientWidth : column.clientHeight;
        var handleSize = opts.vertical ? el.offsetWidth : el.offsetHeight;
        var total = size - handleSize;
        if (total <= 0) return;
        var pos = opts.vertical
          ? ev.clientX - box.left - el.offsetWidth / 2
          : ev.clientY - box.top - el.offsetHeight / 2;
        set((2 * pos) / total);
      }

      function endDrag() {
        if (!dragging) return;
        dragging = false;
        window.removeEventListener('pointermove', onMove);
        window.removeEventListener('pointerup', endDrag);
        window.removeEventListener('pointercancel', endDrag);
        el.classList.remove('staxx-manage-split--dragging');
        document.body.classList.remove('staxx-manage-dragging');
        if (opts.vertical) document.body.classList.remove('staxx-manage-dragging--vertical');
      }

      el.addEventListener('pointerdown', function (ev) {
        // A drag while a pane is collapsed would appear to do nothing, since
        // the collapsed rule wins by design. Refusing it is the honest answer.
        if (dragging || collapsed() || NARROW.matches) return;
        ev.preventDefault();
        dragging = true;
        window.addEventListener('pointermove', onMove);
        window.addEventListener('pointerup', endDrag);
        window.addEventListener('pointercancel', endDrag);
        el.classList.add('staxx-manage-split--dragging');
        document.body.classList.add('staxx-manage-dragging');
        if (opts.vertical) document.body.classList.add('staxx-manage-dragging--vertical');
      });

      // Preventing the default on pointerdown does not stop the mouse event
      // that follows it from starting a text selection, so the sweep down the
      // page came up highlighted. This is what actually stops that; the
      // do-not-select class above covers the pointer leaving the handle.
      el.addEventListener('mousedown', function (ev) { ev.preventDefault(); });

      el.addEventListener('dblclick', function () { set(1); });

      el.addEventListener('keydown', function (ev) {
        if (collapsed()) return;
        var r = opts.get();
        if (!opts.vertical && ev.key === 'ArrowUp') set(r - SPLIT_STEP);
        else if (!opts.vertical && ev.key === 'ArrowDown') set(r + SPLIT_STEP);
        else if (opts.vertical && ev.key === 'ArrowLeft') set(r - SPLIT_STEP);
        else if (opts.vertical && ev.key === 'ArrowRight') set(r + SPLIT_STEP);
        else if (ev.key === 'Home') set(1);
        else return;
        ev.preventDefault();
      });

      apply();   // whatever the last drag left, onto a freshly built column
      return el;
    }

    // ---- D3: the log pane --------------------------------------------------
    //
    // One follower at a time for this whole instance, not one per tab —
    // switching container is meant to stop what you were watching. `log`
    // below holds it and everything the pane displays; `els.logUI` holds the
    // DOM it is displayed through. Kept as an array of parsed lines rather
    // than one growing string, so filtering and the timestamp toggle only
    // ever touch what changed, not the whole pane, on every one-second poll.
    var log = {
      id:       null,   // current follower id, or null while none is running
      target:   null,   // stack+selected this follower belongs to
      offset:   0,
      pending:  '',     // a line split across two reads, held until its \n arrives
      lines:    [],     // { kind: 'log'|'note', raw, hidden }
      everArrived: false,
      alive:    false,
      error:    '',
      paused:   false,
      filter:   false,
      search:   '',
      showTs:   true,
      wrap:     true,
      capped:   false,  // true once LOG_CAP has been hit, so the notice is said once
      dropped:  0,      // total lines ever evicted by the LOG_CAP shift
      domDropped: 0,    // how much of `dropped` the DOM has already trimmed for — see appendNewLines()
      pollTimer: null,
      pollDelay: LOG_POLL_FAST, // current gap between reads; see LOG_POLL_FAST
      pollSeq:  0,      // bumped on every start/stop; a stale reply checks this before landing
      downloadText: null
    };

    function mkLogBtn(label, cls) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'staxx-manage-log-btn ' + cls;
      b.textContent = label;
      return b;
    }

    function buildLogBody(bodyEl) {
      bodyEl.innerHTML = '';
      bodyEl.classList.add('staxx-manage-log-body');

      var bar = document.createElement('div');
      bar.className = 'staxx-manage-log-bar';

      var pauseBtn = mkLogBtn('Live', 'staxx-manage-log-pause');
      pauseBtn.title = 'Pause following new lines, or resume. Scrolling up does this on its own.';
      pauseBtn.addEventListener('click', function () {
        setPaused(!log.paused);
        if (!log.paused) scrollToBottom();
      });

      var jumpBtn = mkLogBtn('Jump to latest', 'staxx-manage-log-jump');
      jumpBtn.title = 'Scroll to the newest line and resume following.';
      jumpBtn.addEventListener('click', function () { setPaused(false); scrollToBottom(); });

      var searchInput = document.createElement('input');
      searchInput.type = 'search';
      searchInput.className = 'staxx-manage-log-search';
      searchInput.placeholder = 'Search…';
      searchInput.addEventListener('input', function () {
        log.search = searchInput.value.toLowerCase();
        renderLines();
      });

      var filterBtn = mkLogBtn('Filter', 'staxx-manage-log-filter');
      filterBtn.title = 'Hide every line that does not match the search box, instead of just showing it.';
      filterBtn.setAttribute('aria-pressed', 'false');
      filterBtn.addEventListener('click', function () {
        setToggle(filterBtn, 'filter', !log.filter);
        renderLines();
      });

      var tsBtn = mkLogBtn('Timestamps', 'staxx-manage-log-ts');
      tsBtn.title = 'Every line already carries one from compose — this only hides it.';
      tsBtn.setAttribute('aria-pressed', 'true');
      tsBtn.classList.add('staxx-manage-log-toggle--on');
      tsBtn.addEventListener('click', function () {
        setToggle(tsBtn, 'showTs', !log.showTs);
        renderLines();
      });

      var wrapBtn = mkLogBtn('Wrap', 'staxx-manage-log-wrap');
      wrapBtn.setAttribute('aria-pressed', 'true');
      wrapBtn.classList.add('staxx-manage-log-toggle--on');
      wrapBtn.addEventListener('click', function () {
        setToggle(wrapBtn, 'wrap', !log.wrap);
        els.logUI.linesEl.classList.toggle('staxx-manage-log-lines--nowrap', !log.wrap);
      });

      var copyBtn = mkLogBtn('Copy visible', 'staxx-manage-log-copy');
      copyBtn.title = 'Copies exactly what the pane shows right now, with the same filter and timestamp settings.';
      copyBtn.addEventListener('click', copyVisible);

      var downloadBtn = mkLogBtn('Download all', 'staxx-manage-log-download');
      downloadBtn.title = 'This plugin cannot hand the browser a file directly — this loads the ' +
        'whole log into a box below so it can be selected and copied.';
      downloadBtn.addEventListener('click', toggleDownload);

      [pauseBtn, jumpBtn, searchInput, filterBtn, tsBtn, wrapBtn, copyBtn, downloadBtn]
        .forEach(function (el) { bar.appendChild(el); });

      var linesWrap = document.createElement('div');
      linesWrap.className = 'staxx-manage-log-lineswrap';

      var linesEl = document.createElement('div');
      linesEl.className = 'staxx-manage-log-lines';
      linesEl.tabIndex = 0;
      linesEl.addEventListener('scroll', onScroll);

      var emptyEl = document.createElement('div');
      emptyEl.className = 'staxx-manage-log-empty staxx-manage-log-empty--hidden';

      linesWrap.appendChild(linesEl);
      linesWrap.appendChild(emptyEl);

      var downloadWrap = document.createElement('div');
      downloadWrap.className = 'staxx-manage-log-download-wrap staxx-manage-log-download-wrap--hidden';
      var downloadArea = document.createElement('textarea');
      downloadArea.className = 'staxx-manage-log-download-area';
      downloadArea.readOnly = true;
      downloadWrap.appendChild(downloadArea);

      bodyEl.appendChild(bar);
      bodyEl.appendChild(linesWrap);
      bodyEl.appendChild(downloadWrap);

      els.logUI = {
        linesEl: linesEl, emptyEl: emptyEl,
        pauseBtn: pauseBtn, filterBtn: filterBtn, tsBtn: tsBtn, wrapBtn: wrapBtn,
        downloadBtn: downloadBtn, downloadWrap: downloadWrap, downloadArea: downloadArea
      };
    }

    function setToggle(btn, key, value) {
      log[key] = value;
      btn.setAttribute('aria-pressed', value ? 'true' : 'false');
      btn.classList.toggle('staxx-manage-log-toggle--on', value);
    }

    function setPaused(v) {
      if (log.paused === v) return;
      log.paused = v;
      var ui = els.logUI;
      ui.pauseBtn.textContent = v ? 'Paused' : 'Live';
      ui.pauseBtn.classList.toggle('staxx-manage-log-toggle--on', !v);
    }

    // Reaching the bottom resumes on its own; scrolling away from it pauses
    // on its own. That is the behaviour anyone expects from a log view.
    function onScroll() {
      var el = els.logUI.linesEl;
      // 6px sat inside the rounding error of this pane's own line height at
      // some browser zoom levels, so a genuinely-at-bottom scroll could read
      // as "not at the bottom" and silently latch the pane into paused.
      var atBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 24;
      setPaused(!atBottom);
    }

    function scrollToBottom() {
      var el = els.logUI.linesEl;
      el.scrollTop = el.scrollHeight;
    }

    function parseLine(raw) {
      var m = raw.match(LOG_TS_RE);
      if (!m) return { raw: raw, hidden: raw };
      return { raw: raw, hidden: (m[1] || '') + raw.slice(m[0].length) };
    }

    function pushLine(kind, raw) {
      var parsed = kind === 'note' ? { raw: raw, hidden: raw } : parseLine(raw);
      log.lines.push({ kind: kind, raw: parsed.raw, hidden: parsed.hidden });
      log.everArrived = true;
      // The notice below is pinned at the front once said, so the cap allows
      // one extra entry for it and evictions take the oldest real line rather
      // than index 0 — written the obvious way, the very next line to arrive
      // threw the notice away again.
      var pinned = log.capped ? 1 : 0;
      if (log.lines.length > LOG_CAP + pinned) {
        log.lines.splice(pinned, 1);
        log.dropped++;
        if (!log.capped) {
          log.capped = true;
          // A header on what survived, not a live event that scrolls away.
          // The full rebuild is what puts it there: the incremental appender
          // only ever adds to the tail, and this fires once per follower.
          log.lines.unshift({
            kind: 'note',
            raw: NOTE_PREFIX + 'older lines were dropped — only the most recent ' + LOG_CAP + ' are kept.',
            hidden: '…'
          });
          renderLines();
        }
      }
    }

    function lineText(line) { return log.showTs ? line.raw : line.hidden; }

    function lineMatches(line) {
      if (!log.filter || !log.search) return true;
      return line.raw.toLowerCase().indexOf(log.search) !== -1;
    }

    function makeLineEl(line) {
      var d = document.createElement('div');
      d.className = 'staxx-manage-log-line' + (line.kind === 'note' ? ' staxx-manage-log-line--note' : '');
      d.textContent = lineText(line);
      if (!lineMatches(line)) d.classList.add('staxx-manage-log-line--hidden');
      return d;
    }

    // Full rebuild — used only when a toggle changes what every existing
    // line should show, or when a new follower starts. The one-second poll
    // below never calls this; it only appends, which is the whole reason the
    // line store is an array rather than a single string.
    function renderLines() {
      if (!els.logUI) return;
      var linesEl = els.logUI.linesEl;
      linesEl.innerHTML = '';
      log.lines.forEach(function (line) { linesEl.appendChild(makeLineEl(line)); });
      log.domDropped = log.dropped; // the DOM now mirrors log.lines exactly, drops included
      updateEmptyState();
      if (!log.paused) scrollToBottom();
    }

    // Appends only what changed. Trusts the DOM's own child count, not an
    // index handed in by the caller: lines evicted by LOG_CAP since the last
    // call have already vanished from log.lines, so the DOM's leading
    // children for them are trimmed first, and only then is the true "how
    // much is new" worked out from what is actually left on screen.
    function appendNewLines() {
      if (!els.logUI) return;
      var linesEl = els.logUI.linesEl;
      var drop = log.dropped - (log.domDropped || 0);
      var pinned = log.capped ? 1 : 0;   // the cap notice at the front stays where it is
      for (var d = 0; d < drop; d++) {
        var victim = linesEl.children[pinned];
        if (!victim) break;
        linesEl.removeChild(victim);
      }
      log.domDropped = log.dropped;
      var have = linesEl.childElementCount;
      log.lines.slice(have).forEach(function (line) { linesEl.appendChild(makeLineEl(line)); });
      updateEmptyState();
      if (!log.paused) scrollToBottom();
    }

    function updateEmptyState() {
      var ui = els.logUI;
      if (!ui) return;
      var msg = '';
      if (log.lines.length === 0) {
        if (log.error) msg = log.error;
        else if (!log.alive && !log.everArrived) {
          msg = state.selected === 'all'
            ? 'Nothing to show — these containers have never been created.'
            : 'Nothing to show — this container has never been created.';
        }
      }
      ui.emptyEl.textContent = msg;
      ui.emptyEl.classList.toggle('staxx-manage-log-empty--hidden', !msg);
    }

    // ---- following -----------------------------------------------------

    function logTargetKey() {
      // Escaped, not a raw NUL byte: a literal one makes every text tool
      // treat this file as binary, which has already happened once here.
      return state.stack + '\u0000' + state.selected;
    }

    // Called at the end of every render() — cheap even on a poll tick that
    // changed nothing, since it is only a string compare, and it is the one
    // place that notices the selected tab or stack has moved on.
    function syncLogFollower() {
      if (log.downloadText !== null) return; // showing the download box; leave the live view as it was
      var key = logTargetKey();
      if (key === log.target) return;
      restartFollower();
    }

    function restartFollower() {
      stopFollower();
      log.target = logTargetKey();
      // The offset belongs to the follower being replaced, not to the new one,
      // which starts its own log at zero. Carrying it over asks the server for
      // bytes past the end of a file that has only just been created, so every
      // read comes back empty and the pane stays blank for ever — which is
      // exactly what switching from All to a single container used to do.
      log.offset = 0;
      log.lines = [];
      log.pending = '';
      log.everArrived = false;
      log.capped = false;
      log.dropped = 0;
      log.domDropped = 0;
      log.alive = false;
      log.error = '';
      log.pollDelay = LOG_POLL_FAST;
      renderLines();
      if (!state.stack) return; // nothing open yet to follow
      var mySeq = ++log.pollSeq;
      var service = state.selected === 'all' ? '' : state.selected;
      call('log-start', { name: state.stack, service: service }).then(function (res) {
        if (mySeq !== log.pollSeq) return; // superseded by another switch while this was in flight
        if (!res.ok) {
          log.error = res.error || 'Could not start following this log.';
          updateEmptyState();
          return;
        }
        log.id = res.id;
        log.alive = true;
        schedulePoll(mySeq, 0);
      });
    }

    function stopFollower() {
      log.pollSeq++; // invalidates any poll already in flight for the old id
      if (log.pollTimer) { clearTimeout(log.pollTimer); log.pollTimer = null; }
      if (log.id) call('log-stop', { id: log.id });
      log.id = null;
    }

    function schedulePoll(mySeq, delay) {
      log.pollTimer = setTimeout(function () { doPoll(mySeq); }, delay);
    }

    // PLAN_132 B. The tab going hidden needs no action here — the next scheduled
    // poll simply arrives at the slow rate on its own (see doPoll). Coming back
    // visible is the case worth acting on: rather than wait out whatever slow
    // delay is already ticking, jump the queue and poll now, which also puts
    // the cadence back to normal. Nothing to do if the follower already died —
    // the pane shows that already, and the person restarts it as today.
    function onVisibilityChange() {
      if (document.hidden || !log.alive || !log.id) return;
      if (log.pollTimer) { clearTimeout(log.pollTimer); log.pollTimer = null; }
      doPoll(log.pollSeq);
    }

    function doPoll(mySeq) {
      if (mySeq !== log.pollSeq || !log.id) return;
      var id = log.id;
      call('log-read', { id: id, offset: log.offset }).then(function (res) {
        if (mySeq !== log.pollSeq || log.id !== id) return; // this follower was replaced meanwhile
        if (res.ok) {
          log.alive = !!res.alive;
          if (res.text) appendChunk(res.text);
          if (typeof res.offset === 'number') log.offset = res.offset;
          updateEmptyState();
          // Text means more is probably on its way, so chase it; silence
          // eases back towards the idle rate. See LOG_POLL_FAST. A hidden
          // tab ignores all of that and polls at the slow, keep-alive-only
          // rate regardless of whether text just arrived — see LOG_POLL_HIDDEN.
          log.pollDelay = res.text
            ? LOG_POLL_FAST
            : Math.min(LOG_POLL_IDLE, Math.round(log.pollDelay * LOG_POLL_EASE));
        }
        // alive:false means the follower ended on its own (nothing left to
        // tail, or the container was removed) — the pane already shows
        // everything there was, so polling stops rather than spinning.
        if (log.alive) schedulePoll(mySeq, document.hidden ? LOG_POLL_HIDDEN : log.pollDelay);
      });
    }

    // text is only the new bytes since the last offset — append, never
    // replace. A line can arrive split across two reads, so the trailing
    // partial (anything after the last \n) is held in `pending` rather than
    // pushed, until the read that completes it.
    function appendChunk(text) {
      var full = log.pending + text;
      var endsWithNl = /\n$/.test(full);
      var parts = full.split('\n');
      if (endsWithNl) { parts.pop(); log.pending = ''; }
      else { log.pending = parts.pop(); }
      if (!parts.length) return;
      parts.forEach(function (raw) { pushLine('log', raw); });
      appendNewLines();
    }

    // ---- controls: copy, download, StaXX's own notes --------------------

    function copyVisible() {
      var visible = log.lines.filter(lineMatches).map(lineText).join('\n');
      copyText(visible);
    }

    function copyText(text) {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).catch(function () { fallbackCopy(text); });
      } else {
        fallbackCopy(text);
      }
    }

    // navigator.clipboard needs a secure context, which Unraid's webGUI often
    // is not on a LAN. This works regardless.
    function fallbackCopy(text) {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.focus();
      ta.select();
      try { document.execCommand('copy'); } catch (e) { /* nothing more to do */ }
      document.body.removeChild(ta);
    }

    // "Download" here cannot mean a file — action.php answers JSON, always,
    // and a sandboxed page cannot hand the browser one either. This loads the
    // whole capped log into a selectable box instead, and says so honestly in
    // the button's own title rather than promising something it cannot do.
    function toggleDownload() {
      var ui = els.logUI;
      if (log.downloadText !== null) {
        log.downloadText = null;
        ui.downloadWrap.classList.add('staxx-manage-log-download-wrap--hidden');
        ui.downloadBtn.textContent = 'Download all';
        syncLogFollower(); // resume following if the tab moved on while this was open
        return;
      }
      var service = state.selected === 'all' ? '' : state.selected;
      ui.downloadBtn.disabled = true;
      ui.downloadBtn.textContent = 'Loading…';
      call('log-download', { name: state.stack, service: service }).then(function (res) {
        ui.downloadBtn.disabled = false;
        if (!res.ok) {
          noteLine('could not load the full log: ' + (res.error || 'unknown error.'));
          ui.downloadBtn.textContent = 'Download all';
          return;
        }
        log.downloadText = res.text || '';
        ui.downloadArea.value = log.downloadText;
        ui.downloadWrap.classList.remove('staxx-manage-log-download-wrap--hidden');
        ui.downloadBtn.textContent = 'Back to live log';
        ui.downloadArea.focus();
        ui.downloadArea.select();
      });
    }

    function noteLine(text) {
      pushLine('note', NOTE_PREFIX + text);
      appendNewLines();
    }

    // ---- D4: the shell -----------------------------------------------------
    // PLAN_133 — the pane shows Unraid's own ttyd terminal in an iframe
    // rather than relaying keystrokes itself. See Stacks.php's "exec"
    // section for the mechanism.
    //
    // One session per container, kept open server-side for as long as the
    // editor is (PLAN_44 D4's "until the editor closes"), but only the
    // visible one's liveness is ever polled — a background session's own
    // ttyd keeps running regardless, so switching back to it just resumes
    // watching the frame that has been sitting there the whole time.
    // shellState.sessions is keyed by service name; there is deliberately no
    // entry for 'all', which has no shell at all.
    var shellState = {
      warned:  null,   // null = not yet asked the server; true/false once known — see checkShellWarned()
      warning: false,  // an settings-save for SHELL_WARNED is in flight
      sessions: {},
      active:   null   // the service whose session currently owns the screen and the poll loop
    };

    function buildShellBody(bodyEl) {
      bodyEl.innerHTML = '';
      bodyEl.classList.add('staxx-manage-shell-body');

      var msg = document.createElement('p');
      msg.className = 'staxx-manage-shell-msg staxx-manage-shell-msg--hidden';

      var warn = document.createElement('div');
      warn.className = 'staxx-manage-shell-warn staxx-manage-shell-warn--hidden';
      warn.innerHTML =
        '<p>This shell is Unraid’s own terminal — the same one the Console button opens — a root ' +
        'command line inside the container, with the same reach as signing into the machine itself ' +
        'but scoped to whatever this one container can see.</p>' +
        '<p>Anything changed this way is gone the next time the container is rebuilt — a recreate, ' +
        'an update, or simply the image being pulled again all start it fresh.</p>';
      var warnBtn = document.createElement('button');
      warnBtn.type = 'button';
      warnBtn.className = 'staxx-manage-shell-warn-btn';
      warnBtn.textContent = 'I understand — open the shell';
      warnBtn.addEventListener('click', acknowledgeShellWarning);
      warn.appendChild(warnBtn);

      var screenWrap = document.createElement('div');
      screenWrap.className = 'staxx-manage-shell-screenwrap staxx-manage-shell-screenwrap--hidden';

      bodyEl.appendChild(msg);
      bodyEl.appendChild(warn);
      bodyEl.appendChild(screenWrap);

      // frames holds one { wrap, iframe, overlay } per service, created
      // lazily and kept in the DOM (merely hidden, never removed) for as
      // long as its session lives — see ensureShellFrame(). That is what
      // lets switching back to a container resume the same live terminal
      // rather than reloading it.
      els.shellUI = { msg: msg, warn: warn, warnBtn: warnBtn, screenWrap: screenWrap, frames: {} };
    }

    function shellPanel(which) {
      var ui = els.shellUI;
      ui.msg.classList.toggle('staxx-manage-shell-msg--hidden', which !== 'msg');
      ui.warn.classList.toggle('staxx-manage-shell-warn--hidden', which !== 'warn');
      ui.screenWrap.classList.toggle('staxx-manage-shell-screenwrap--hidden', which !== 'screen');
    }

    // One iframe+overlay pair per service, created once and reused for as
    // long as that service's session lives — see buildShellBody()'s comment.
    function ensureShellFrame(service) {
      var frames = els.shellUI.frames;
      if (frames[service]) return frames[service];

      var wrap = document.createElement('div');
      wrap.className = 'staxx-manage-shell-frame staxx-manage-shell-frame--hidden';

      var iframe = document.createElement('iframe');
      iframe.className = 'staxx-manage-shell-iframe';
      iframe.title = 'Shell in ' + service;
      // No `allow` attribute — a websocket back to the same host needs no
      // permission grant, and an empty allowlist is the least this could ask
      // for.

      var overlay = document.createElement('div');
      overlay.className = 'staxx-manage-shell-overlay staxx-manage-shell-overlay--hidden';

      wrap.appendChild(iframe);
      wrap.appendChild(overlay);
      els.shellUI.screenWrap.appendChild(wrap);

      var frame = { wrap: wrap, iframe: iframe, overlay: overlay };
      frames[service] = frame;
      return frame;
    }

    function removeShellFrame(service) {
      var frame = els.shellUI && els.shellUI.frames[service];
      if (!frame) return;
      if (frame.wrap.parentNode) frame.wrap.parentNode.removeChild(frame.wrap);
      delete els.shellUI.frames[service];
    }

    // Stops the poll loop without closing the session — the difference
    // matters: leaving a container's tab must not end its terminal, only the
    // liveness poll nobody is watching for.
    function pauseShellPoll() {
      var svc = shellState.active;
      if (svc && shellState.sessions[svc]) {
        var sess = shellState.sessions[svc];
        sess.pollSeq++;
        if (sess.pollTimer) { clearTimeout(sess.pollTimer); sess.pollTimer = null; }
      }
      shellState.active = null;
    }

    function showShellMessage(text) {
      pauseShellPoll();
      els.shellUI.msg.textContent = text;
      shellPanel('msg');
    }

    // Asks the server once whether this box has already shown the shell
    // warning (PLAN_44 D4 — "once per server", tracked in the flash config,
    // never in the browser). Cached for the rest of this Instance's life,
    // same as the plan's own "once per server, not once per tab".
    function checkShellWarned(then) {
      if (shellState.warned !== null) { then(); return; }
      if (shellState.warning) return; // a check is already in flight; its render() will call through again
      shellState.warning = true;
      call('settings', {}).then(function (res) {
        shellState.warning = false;
        shellState.warned = !!(res.ok && res.settings && res.settings.SHELL_WARNED === 'true');
        render();
      });
    }

    function acknowledgeShellWarning() {
      els.shellUI.warnBtn.disabled = true;
      call('settings-save', { SHELL_WARNED: 'true' }).then(function () {
        // Marked seen either way: a failed save here is a lost write to a
        // flag nobody but this pane reads, not a reason to nag on every
        // subsequent container tab for the rest of the session.
        shellState.warned = true;
        render();
      });
    }

    // Opens a session the first time a container's tab is actually looked
    // at — not one per service at mount, which would open a shell in every
    // container in a nine-service stack whether anyone asked or not.
    function ensureShellSession(service) {
      if (shellState.sessions[service]) return;
      var sess = {
        id: null, pollTimer: null, pollSeq: 0,
        opening: true, error: '', ended: false,
        srcTries: 0,   // how many times the iframe's src has been (re)set — see loadShellFrame()
        startedAt: 0   // Date.now() once opened, for the one-retry window below
      };
      shellState.sessions[service] = sess;
      call('exec-open', { name: state.stack, service: service }).then(function (res) {
        // The session this belongs to may have already been torn down by
        // unmount() while the request was in flight — closeAllShellSessions()
        // replaces shellState.sessions wholesale, so this object no longer
        // being the one held there is exactly that signal.
        if (shellState.sessions[service] !== sess) {
          if (res.ok) call('exec-close', { id: res.id });
          return;
        }
        sess.opening = false;
        if (!res.ok) {
          sess.error = res.error || 'Could not open a shell in this container.';
          renderShellIfActive(service);
          renderJobsBar();
          return;
        }
        sess.id = res.id;
        sess.startedAt = Date.now();
        renderShellIfActive(service);
        renderJobsBar();
        loadShellFrame(service, sess.id);
        resumeShellPoll(service);
      });
    }

    // Points a session's iframe at ttyd, after giving it a moment to be
    // listening — the same 200ms wait Unraid's own Console button gives it.
    function loadShellFrame(service, id) {
      var frame = ensureShellFrame(service);
      setTimeout(function () {
        var live = shellState.sessions[service];
        if (!live || live.id !== id || live.ended) return;
        frame.iframe.src = '/logterminal/staxx-' + id + '/';
        live.srcTries++;
      }, 200);
    }

    function renderShellIfActive(service) {
      if (service === shellState.active) fullShellRender(service);
    }

    function resumeShellPoll(service) {
      var sess = shellState.sessions[service];
      if (!sess || !sess.id || service !== shellState.active || sess.pollTimer) return;
      var mySeq = ++sess.pollSeq;
      sess.pollTimer = setTimeout(function () { pollShell(service, mySeq); }, 0);
    }

    // Polls exec-alive — this IS the session's keep-alive now, replacing the
    // old one-second exec-read poll with something far cheaper, since there
    // is no output left here to fetch.
    function pollShell(service, mySeq) {
      var sess = shellState.sessions[service];
      if (!sess || mySeq !== sess.pollSeq || !sess.id || service !== shellState.active) return;
      var id = sess.id;
      call('exec-alive', { id: id }).then(function (res) {
        var live = shellState.sessions[service];
        if (!live || mySeq !== live.pollSeq || live.id !== id) return; // superseded meanwhile
        live.pollTimer = null;
        var alive = !!(res.ok && res.alive);
        if (!alive) {
          // Within the first two seconds the socket may simply not exist
          // yet — retry the frame's src once before calling the session
          // dead, the same grace loadShellFrame()'s own wait already gives
          // ttyd to start listening.
          if (live.srcTries < 2 && Date.now() - live.startedAt < 2000) {
            setTimeout(function () {
              var still = shellState.sessions[service];
              if (!still || still.id !== id || still.ended) return;
              ensureShellFrame(service).iframe.src = '/logterminal/staxx-' + id + '/';
              still.srcTries++;
            }, 500);
          } else {
            live.ended = true;
            if (service === shellState.active) { fullShellRender(service); renderJobsBar(); }
            return; // the session is done — nothing left to poll for
          }
        }
        if (service === shellState.active) {
          live.pollTimer = setTimeout(function () { pollShell(service, mySeq); }, SHELL_ALIVE_POLL_MS);
        }
      });
    }

    // Full rebuild — used whenever the visible session's own state changes
    // (a tab switch, a session opening, an error, the session ending).
    // Everything the terminal itself shows is ttyd's, not this pane's, so
    // there is nothing here that changes on every poll tick the way the log
    // pane's incremental append does.
    function fullShellRender(service) {
      var sess = shellState.sessions[service];
      if (!sess) return;
      shellPanel('screen');
      var frames = els.shellUI.frames;
      Object.keys(frames).forEach(function (svc) {
        frames[svc].wrap.classList.toggle('staxx-manage-shell-frame--hidden', svc !== service);
      });
      var frame = ensureShellFrame(service);
      frame.wrap.classList.toggle('staxx-manage-shell-frame--dead', !!sess.ended);
      if (sess.opening) {
        frame.overlay.textContent = 'Opening a shell…';
        frame.overlay.classList.remove('staxx-manage-shell-overlay--hidden');
      } else if (sess.error) {
        frame.overlay.textContent = sess.error;
        frame.overlay.classList.remove('staxx-manage-shell-overlay--hidden');
      } else if (sess.ended) {
        frame.overlay.textContent = 'That session has ended. Press Reconnect above for a new one.';
        frame.overlay.classList.remove('staxx-manage-shell-overlay--hidden');
      } else {
        frame.overlay.classList.add('staxx-manage-shell-overlay--hidden');
      }
    }

    // Closes every open session — called on unmount() (the editor closing)
    // and at the start of mount() (a different stack has an entirely
    // different set of containers, so nothing here still applies to it).
    function closeAllShellSessions() {
      Object.keys(shellState.sessions).forEach(function (svc) {
        var sess = shellState.sessions[svc];
        if (sess.pollTimer) clearTimeout(sess.pollTimer);
        if (sess.id) call('exec-close', { id: sess.id });
        removeShellFrame(svc);
      });
      shellState.sessions = {};
      shellState.active = null;
    }

    // Called at the end of every render() (PLAN_44 D4) — decides what the
    // shell pane shows for whichever tab is currently selected, and moves
    // the poll loop to match. Mirrors syncLogFollower()'s place in render(),
    // but a shell's session is never restarted on a re-render the way a log
    // follower is: switching back to a container already open just resumes
    // its own poll loop against the same terminal that has been running the
    // whole time.
    function syncShellPane() {
      var service = state.selected;

      if (service === 'all') {
        showShellMessage('Pick a container above — there is no shared shell for the whole stack.');
        return;
      }

      if (shellState.warned === null) {
        showShellMessage('Checking…');
        checkShellWarned(syncShellPane);
        return;
      }

      if (!shellState.warned) {
        pauseShellPoll();
        els.shellUI.warnBtn.disabled = false;
        shellPanel('warn');
        return;
      }

      if (shellState.active === service) return; // already showing and polling this one — nothing to do

      pauseShellPoll();
      shellState.active = service;
      ensureShellSession(service);
      fullShellRender(service);
      resumeShellPoll(service);
    }

    // ---- D5: the file pane -------------------------------------------------
    //
    // Unlike the shell, none of cfile-list/read/save/rename/delete/mkdir is a
    // session — each is one request, answered and done. So there is nothing
    // here to poll and nothing to close on unmount; the only state worth
    // keeping is what the browser itself remembers per container, keyed by
    // service name the same way shellState.sessions is, so switching tabs
    // and back returns to the folder last looked at rather than starting
    // over at the container's own working directory every time.
    var fileState = {
      sessions: {},
      active:   null   // the service whose listing currently owns the pane
    };

    // Matches STAXX_FILE_MAX in Stacks.php. Not fetched from the server —
    // this only needs to be close enough to warn before sending something
    // too big, and the refusal that follows if it ever drifts is still a
    // plain sentence, not a broken upload.
    var CFILE_MAX = 262144;

    function fileSession(service) {
      var sess = fileState.sessions[service];
      if (!sess) {
        sess = { dir: null, home: null, entries: [], more: false, loading: false, error: '', view: null };
        fileState.sessions[service] = sess;
      }
      return sess;
    }

    // Called from mount() — a different stack means an entirely different
    // set of containers, so a remembered folder in one of them means
    // nothing against the next. Nothing server-side to close first, unlike
    // closeAllShellSessions(), since there is no open session to leak.
    function resetFileSessions() {
      fileState.sessions = {};
      fileState.active = null;
    }

    function joinPath(dir, name) {
      return dir === '/' ? '/' + name : dir + '/' + name;
    }

    function parentPath(dir) {
      if (!dir || dir === '/') return '/';
      var i = dir.lastIndexOf('/');
      return i <= 0 ? '/' : dir.slice(0, i);
    }

    // Service -> mount list, exactly as mount() was handed it, or null when
    // the host never passed one. See the state.mounts comment above: an
    // absent list means "say nothing", not "assume nothing is mounted".
    function mountsFor(service) {
      var m = state.mounts && state.mounts[service];
      return (m && m.length) ? m : null;
    }

    function isMountPath(service, path) {
      var mounts = mountsFor(service);
      if (!mounts) return false;
      for (var i = 0; i < mounts.length; i++) {
        var m = mounts[i];
        if (path === m || (m !== '/' && path.indexOf(m + '/') === 0) || m === '/') return true;
      }
      return false;
    }

    function mkFileBtn(label, cls) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'staxx-manage-files-btn ' + cls;
      b.textContent = label;
      return b;
    }

    function buildFilesBody(bodyEl) {
      bodyEl.innerHTML = '';
      bodyEl.classList.add('staxx-manage-files-body');

      var msg = document.createElement('p');
      msg.className = 'staxx-manage-files-msg staxx-manage-files-msg--hidden';

      // ---- the browser: toolbar, mounted-folder note, the listing itself --

      var browse = document.createElement('div');
      browse.className = 'staxx-manage-files-browse staxx-manage-files-browse--hidden';

      var toolbar = document.createElement('div');
      toolbar.className = 'staxx-manage-files-toolbar';

      var upBtn = mkFileBtn('Up', 'staxx-manage-files-up');
      upBtn.title = 'Go to the parent folder.';
      upBtn.addEventListener('click', function () {
        var sess = fileSession(state.selected);
        navigateTo(state.selected, parentPath(sess.dir));
      });

      var pathEl = document.createElement('span');
      pathEl.className = 'staxx-manage-files-path';
      pathEl.setAttribute('aria-label', 'Folder path');

      var refreshBtn = mkFileBtn('Refresh', 'staxx-manage-files-refresh');
      refreshBtn.addEventListener('click', function () {
        var sess = fileSession(state.selected);
        navigateTo(state.selected, sess.dir);
      });

      var mkdirBtn = mkFileBtn('New folder', 'staxx-manage-files-mkdir');
      mkdirBtn.addEventListener('click', function () { makeFolder(state.selected); });

      var uploadBtn = mkFileBtn('Upload', 'staxx-manage-files-uploadbtn');
      uploadBtn.title = 'Read a file from this computer and copy it in here — capped at ' +
        bytes(CFILE_MAX) + '.';
      var uploadInput = document.createElement('input');
      uploadInput.type = 'file';
      uploadInput.className = 'staxx-manage-files-uploadinput';
      uploadInput.addEventListener('change', function () {
        var f = uploadInput.files && uploadInput.files[0];
        uploadInput.value = ''; // so choosing the same file twice fires change() again
        if (f) uploadFile(state.selected, f);
      });
      uploadBtn.addEventListener('click', function () { uploadInput.click(); });

      [upBtn, pathEl, refreshBtn, mkdirBtn, uploadBtn, uploadInput]
        .forEach(function (el) { toolbar.appendChild(el); });

      var note = document.createElement('p');
      note.className = 'staxx-manage-files-note staxx-manage-files-note--hidden';
      note.textContent = 'Folders marked ⌂ come from your server — anything outside a marked ' +
        'folder is gone the next time this container is rebuilt.';

      var errorEl = document.createElement('p');
      errorEl.className = 'staxx-manage-files-error staxx-manage-files-error--hidden';

      var capEl = document.createElement('p');
      capEl.className = 'staxx-manage-files-cap staxx-manage-files-cap--hidden';
      capEl.textContent = 'This folder holds more than can be listed here — open a subfolder to see fewer at once.';

      var listWrap = document.createElement('div');
      listWrap.className = 'staxx-manage-files-listwrap';
      var table = document.createElement('table');
      table.className = 'staxx-manage-files-table';
      var thead = document.createElement('thead');
      thead.innerHTML = '<tr><th>Name</th><th>Size</th><th>Owner / permissions</th><th></th></tr>';
      var tbody = document.createElement('tbody');
      table.appendChild(thead);
      table.appendChild(tbody);
      listWrap.appendChild(table);

      var emptyEl = document.createElement('p');
      emptyEl.className = 'staxx-manage-files-empty staxx-manage-files-empty--hidden';

      browse.appendChild(toolbar);
      browse.appendChild(note);
      browse.appendChild(errorEl);
      browse.appendChild(capEl);
      browse.appendChild(listWrap);
      // Inside the scrolling listing, not beside it. This is absolutely
      // positioned, so its parent decides what it covers: as a sibling of the
      // listing its nearest positioned ancestor was the dialog itself, and it
      // spread over the whole editor — 1522x854 against the pane's 709x285 —
      // swallowing every click behind a message about an empty folder. The log
      // pane's own empty message has always been nested this way.
      listWrap.appendChild(emptyEl);

      // ---- the viewer/editor: one file at a time, replacing the listing ---
      //
      // The plan's own first choice — open a text file in the editor on the
      // Configure tab — would mean Configure editing a file that is not this
      // stack's compose file at all, which is a different piece of plumbing
      // than it sounds. This is the honest smaller version: a plain text box
      // right here, with Save and Cancel.

      var view = document.createElement('div');
      view.className = 'staxx-manage-files-view staxx-manage-files-view--hidden';

      var viewHead = document.createElement('div');
      viewHead.className = 'staxx-manage-files-view-head';
      var backBtn = mkFileBtn('Back', 'staxx-manage-files-viewback');
      backBtn.addEventListener('click', function () { closeView(state.selected); });
      var viewPath = document.createElement('span');
      viewPath.className = 'staxx-manage-files-view-path';
      viewHead.appendChild(backBtn);
      viewHead.appendChild(viewPath);

      var viewNote = document.createElement('p');
      viewNote.className = 'staxx-manage-files-view-note staxx-manage-files-view-note--hidden';

      var viewArea = document.createElement('textarea');
      viewArea.className = 'staxx-manage-files-view-area';
      viewArea.addEventListener('input', function () {
        var sess = fileSession(state.selected);
        if (sess.view && sess.view.editable) sess.view.text = viewArea.value;
      });

      var viewBar = document.createElement('div');
      viewBar.className = 'staxx-manage-files-view-bar staxx-manage-files-view-bar--hidden';
      var saveBtn = mkFileBtn('Save', 'staxx-manage-files-viewsave');
      saveBtn.addEventListener('click', function () { saveView(state.selected); });
      var cancelBtn = mkFileBtn('Cancel', 'staxx-manage-files-viewcancel');
      cancelBtn.addEventListener('click', function () { closeView(state.selected); });
      viewBar.appendChild(saveBtn);
      viewBar.appendChild(cancelBtn);

      view.appendChild(viewHead);
      view.appendChild(viewNote);
      view.appendChild(viewArea);
      view.appendChild(viewBar);

      bodyEl.appendChild(msg);
      bodyEl.appendChild(browse);
      bodyEl.appendChild(view);

      els.filesUI = {
        msg: msg, browse: browse, view: view,
        upBtn: upBtn, pathEl: pathEl, note: note, errorEl: errorEl, capEl: capEl,
        tbody: tbody, emptyEl: emptyEl,
        backBtn: backBtn, viewPath: viewPath, viewNote: viewNote, viewArea: viewArea,
        viewBar: viewBar, saveBtn: saveBtn
      };
    }

    function filesPanel(which) {
      var ui = els.filesUI;
      ui.msg.classList.toggle('staxx-manage-files-msg--hidden', which !== 'msg');
      ui.browse.classList.toggle('staxx-manage-files-browse--hidden', which !== 'browse');
      ui.view.classList.toggle('staxx-manage-files-view--hidden', which !== 'view');
    }

    function showFilesMessage(text) {
      els.filesUI.msg.textContent = text;
      filesPanel('msg');
    }

    // ---- listing ---------------------------------------------------------

    function openHome(service) {
      var sess = fileSession(service);
      sess.loading = true;
      sess.error = '';
      renderFileList(service);
      call('cfile-home', { name: state.stack, service: service }).then(function (res) {
        if (fileState.sessions[service] !== sess) return; // superseded by a fresh mount() meanwhile
        if (!res.ok) {
          sess.loading = false;
          sess.error = res.error || 'Could not find a starting folder in this container.';
          renderFileList(service);
          return;
        }
        navigateTo(service, res.home || '/');
      });
    }

    function navigateTo(service, dir) {
      var sess = fileSession(service);
      sess.loading = true;
      sess.error = '';
      if (service === fileState.active) renderFileList(service);
      call('cfile-list', { name: state.stack, service: service, dir: dir }).then(function (res) {
        if (fileState.sessions[service] !== sess) return;
        sess.loading = false;
        if (!res.ok) {
          sess.error = res.error || 'Could not list that folder.';
          renderFileList(service);
          return;
        }
        sess.dir = res.dir;
        sess.entries = res.entries || [];
        sess.more = !!res.more;
        renderFileList(service);
      });
    }

    // Full rebuild of the listing — there is no partial-update path here the
    // way the log and shell panes have, because every action that changes
    // what a folder holds (rename, delete, mkdir, upload) re-lists it from
    // scratch anyway, and a directory listing is cheap next to a log tail.
    function renderFileList(service) {
      if (service !== fileState.active) return;
      var sess = fileSession(service);
      var ui = els.filesUI;
      filesPanel('browse');

      renderCrumbs(ui.pathEl, sess.dir);
      ui.upBtn.disabled = !sess.dir || sess.dir === '/';

      ui.errorEl.textContent = sess.error;
      ui.errorEl.classList.toggle('staxx-manage-files-error--hidden', !sess.error);
      ui.capEl.classList.toggle('staxx-manage-files-cap--hidden', !sess.more);

      ui.tbody.innerHTML = '';
      var anyMarked = false;
      sess.entries.forEach(function (entry) {
        if (isMountPath(service, joinPath(sess.dir, entry.name))) anyMarked = true;
        ui.tbody.appendChild(fileRow(service, sess, entry));
      });

      // Explains a symbol, so it only appears where the symbol does. Shown
      // whenever the stack has any mount at all, it was a legend for a mark
      // nothing on screen was wearing — at the top of the container's own
      // filesystem, where none of it is mounted, that reads as a warning about
      // nothing.
      ui.note.classList.toggle('staxx-manage-files-note--hidden', !anyMarked);

      var showEmpty = !sess.loading && !sess.error && sess.entries.length === 0;
      ui.emptyEl.textContent = sess.loading ? 'Loading…' : (showEmpty ? 'This folder is empty.' : '');
      ui.emptyEl.classList.toggle('staxx-manage-files-empty--hidden', !sess.loading && !showEmpty);
    }

    // The path as somewhere to click rather than something to read. Every
    // part but the last navigates, built from the parts to its left, so there
    // is no second idea in here of what a path is. The last part is where you
    // already are, so it is plain text: a button that does nothing is worse
    // than no button.
    function renderCrumbs(el, dir) {
      el.innerHTML = '';
      if (!dir) { el.textContent = '…'; return; }

      var parts = dir.split('/').filter(function (p) { return p !== ''; });

      function crumb(label, path, last) {
        if (last) {
          var here = document.createElement('span');
          here.className = 'staxx-manage-files-crumb-here';
          here.textContent = label;
          el.appendChild(here);
          return;
        }
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'staxx-manage-files-crumb';
        b.textContent = label;
        b.title = 'Go to ' + path;
        b.addEventListener('click', function () { navigateTo(state.selected, path); });
        el.appendChild(b);
      }

      crumb('/', '/', parts.length === 0);

      var walked = '';
      parts.forEach(function (part, i) {
        if (i > 0) {
          var sep = document.createElement('span');
          sep.className = 'staxx-manage-files-crumb-sep';
          sep.textContent = '/';
          el.appendChild(sep);
        }
        walked += '/' + part;
        crumb(part, walked, i === parts.length - 1);
      });
    }

    function fileRow(service, sess, entry) {
      var path = joinPath(sess.dir, entry.name);
      var mounted = isMountPath(service, path);

      var tr = document.createElement('tr');
      if (mounted) tr.classList.add('staxx-manage-files-row--mounted');

      var nameTd = document.createElement('td');
      nameTd.className = 'staxx-manage-files-name';
      if (mounted) {
        var badge = document.createElement('span');
        badge.className = 'staxx-manage-files-badge-mount';
        badge.title = 'From your server — anything outside a marked folder is gone on the next rebuild.';
        badge.textContent = '⌂';
        nameTd.appendChild(badge);
      }
      if (entry.dir) {
        var dirBtn = document.createElement('button');
        dirBtn.type = 'button';
        dirBtn.className = 'staxx-manage-files-namebtn';
        dirBtn.textContent = entry.name + '/';
        dirBtn.addEventListener('click', function () { navigateTo(service, path); });
        nameTd.appendChild(dirBtn);
      } else {
        var nameSpan = document.createElement('span');
        nameSpan.textContent = entry.name;
        nameTd.appendChild(nameSpan);
      }
      if (entry.link) {
        var linkBadge = document.createElement('span');
        linkBadge.className = 'staxx-manage-files-badge-link';
        // The server's own entry shape carries no target field today; this
        // reads one anyway, so a future listing that adds one is picked up
        // without another round of changes here.
        var target = entry.target || '';
        linkBadge.title = target ? ('Symlink → ' + target) : 'Symlink';
        linkBadge.textContent = '→';
        nameTd.appendChild(linkBadge);
      }

      var sizeTd = document.createElement('td');
      sizeTd.className = 'staxx-manage-files-size';
      sizeTd.textContent = entry.dir ? '—' : bytes(entry.size || 0);

      var permTd = document.createElement('td');
      permTd.className = 'staxx-manage-files-perms';
      permTd.textContent = entry.perms + ' ' + entry.uid + ':' + entry.gid;

      var actTd = document.createElement('td');
      actTd.className = 'staxx-manage-files-actions';
      if (!entry.dir) {
        var openBtn = mkFileBtn('Open', 'staxx-manage-files-open');
        openBtn.title = 'Open a text file here to edit and save it back.';
        openBtn.addEventListener('click', function () { openEntry(service, path, entry.name, true); });
        var dlBtn = mkFileBtn('Download', 'staxx-manage-files-download');
        dlBtn.title = 'This plugin cannot hand the browser a file directly — this shows the ' +
          'contents so they can be selected and copied.';
        dlBtn.addEventListener('click', function () { openEntry(service, path, entry.name, false); });
        actTd.appendChild(openBtn);
        actTd.appendChild(dlBtn);
      }
      var ownBtn = mkFileBtn('O/P', 'staxx-manage-files-owner');
      ownBtn.title = 'Owner and permissions — change either or both, right through a folder.';
      ownBtn.addEventListener('click', function () { ownPermEntry(service, path, entry); });
      actTd.appendChild(ownBtn);
      var renBtn = mkFileBtn('Rename', 'staxx-manage-files-rename');
      renBtn.addEventListener('click', function () { renameEntry(service, sess.dir, entry); });
      var delBtn = mkFileBtn('Delete', 'staxx-manage-files-delete');
      delBtn.addEventListener('click', function () { deleteEntry(service, path, entry); });
      actTd.appendChild(renBtn);
      actTd.appendChild(delBtn);

      tr.appendChild(nameTd);
      tr.appendChild(sizeTd);
      tr.appendChild(permTd);
      tr.appendChild(actTd);
      return tr;
    }

    // ---- opening a file: editable for a text file via Open, read-only via
    // Download, and read-only regardless for a binary file, since there is
    // nothing here that could save one back correctly. Both buttons read the
    // same way — the server decides text-or-binary and the size cap either
    // way, so there is nothing left for the client to branch on beforehand.

    function openEntry(service, path, name, wantEdit) {
      var sess = fileSession(service);
      sess.view = { path: path, name: name, editable: false, loading: true, saving: false,
                    error: '', note: '', text: '', original: '' };
      renderFileView(service);
      call('cfile-read', { name: state.stack, service: service, path: path }).then(function (res) {
        if (fileState.sessions[service] !== sess || sess.view === null) return;
        var view = sess.view;
        view.loading = false;
        if (!res.ok) { view.error = res.error || 'Could not read that file.'; renderFileView(service); return; }
        if (res.binary) {
          view.text = res.b64 || '';
          view.editable = false;
          view.note = 'This is a binary file and cannot be edited here — the box below holds its ' +
            'content base64-encoded, which can still be selected and copied.';
        } else {
          view.text = res.text || '';
          view.original = view.text;
          view.editable = wantEdit;
        }
        renderFileView(service);
      });
    }

    function renderFileView(service) {
      if (service !== fileState.active) return;
      var sess = fileSession(service);
      var view = sess.view;
      var ui = els.filesUI;
      filesPanel('view');
      if (!view) return;
      ui.viewPath.textContent = view.name;
      ui.viewNote.textContent = view.loading ? 'Loading…' : (view.error || view.note);
      ui.viewNote.classList.toggle('staxx-manage-files-view-note--hidden', !ui.viewNote.textContent);
      ui.viewArea.value = view.loading ? '' : view.text;
      ui.viewArea.readOnly = !view.editable;
      ui.viewArea.classList.toggle('staxx-manage-files-view-area--readonly', !view.editable);
      ui.viewBar.classList.toggle('staxx-manage-files-view-bar--hidden', !view.editable);
      ui.saveBtn.disabled = !!view.saving;
    }

    function closeView(service) {
      var sess = fileSession(service);
      var view = sess.view;
      if (view && view.editable && view.text !== view.original) {
        if (!window.confirm('Discard the changes made to "' + view.name + '"?')) return;
      }
      sess.view = null;
      renderFileList(service);
    }

    function saveView(service) {
      var sess = fileSession(service);
      var view = sess.view;
      if (!view || !view.editable || view.saving) return;
      view.saving = true;
      renderFileView(service);
      call('cfile-save', { name: state.stack, service: service, path: view.path, body: view.text })
        .then(function (res) {
          if (fileState.sessions[service] !== sess || sess.view !== view) return;
          view.saving = false;
          if (!res.ok) { view.error = res.error || 'Could not save that file.'; renderFileView(service); return; }
          view.original = view.text;
          closeView(service);
        });
    }

    // ---- folder actions: mkdir, rename, delete, upload --------------------
    //
    // window.prompt/window.confirm, not a dialog — stacks.js already uses
    // both for one-line questions exactly like these, and a dialog would be
    // more furniture than "what should this be called?" needs.

    function makeFolder(service) {
      var sess = fileSession(service);
      if (sess.dir === null) return;
      var name = window.prompt('New folder name:');
      if (name === null) return;
      name = name.trim();
      if (!name || name.indexOf('/') !== -1) {
        sess.error = 'That is not a valid folder name.';
        renderFileList(service);
        return;
      }
      call('cfile-mkdir', { name: state.stack, service: service, path: joinPath(sess.dir, name) })
        .then(function (res) {
          if (!res.ok) { sess.error = res.error || 'Could not create that folder.'; renderFileList(service); return; }
          navigateTo(service, sess.dir);
        });
    }

    function renameEntry(service, dir, entry) {
      var sess = fileSession(service);
      var to = window.prompt('Rename "' + entry.name + '" to:', entry.name);
      if (to === null) return;
      to = to.trim();
      if (to === '' || to === entry.name || to.indexOf('/') !== -1) return;
      call('cfile-rename', { name: state.stack, service: service, path: joinPath(dir, entry.name), to: joinPath(dir, to) })
        .then(function (res) {
          if (!res.ok) { sess.error = res.error || 'Could not rename "' + entry.name + '".'; renderFileList(service); return; }
          navigateTo(service, sess.dir);
        });
    }

    // Delete always asks first, and always says the same thing the mounted
    // note above says: this container gets rebuilt from its image sooner or
    // later regardless, so nothing here is truly permanent EXCEPT the delete
    // itself, which happens right now and cannot be waited out.
    function deleteEntry(service, path, entry) {
      var sess = fileSession(service);
      var msg = 'Delete "' + entry.name + '"? ' +
        (entry.dir ? 'This removes the whole folder and everything in it. ' : '') +
        'This happens right now and cannot be undone — the same as anything else outside a ' +
        'folder marked as coming from your server, which vanishes on the next rebuild anyway.';
      if (!window.confirm(msg)) return;
      call('cfile-delete', { name: state.stack, service: service, path: path, recurse: entry.dir ? '1' : '0' })
        .then(function (res) {
          if (!res.ok) { sess.error = res.error || 'Could not delete "' + entry.name + '".'; renderFileList(service); return; }
          navigateTo(service, sess.dir);
        });
    }

    // Owner and permissions, asked for and then done — not prepared in a
    // shell for someone to finish. Both are typed rather than guessed: the
    // right numbers are whatever this particular image expects, and nothing
    // in here can know them. The listing's own owner column is where to look.
    //
    // Two questions, each skippable by clearing the box, because wanting one
    // without the other is the common case — a folder whose owner is right
    // but which nothing can write to, or the reverse. 99:100 and 755 are
    // offered as starting points, being Unraid's own nobody:users and the
    // ordinary answer for a folder; the confirmation is what stops either
    // becoming a careless Enter.
    function ownPermEntry(service, path, entry) {
      var sess = fileSession(service);
      var owner = window.prompt('Who should own "' + entry.name + '"? A number, or a pair ' +
        'like 99:100 — Unraid\'s own default. Leave it empty to keep the current owner.', '99:100');
      if (owner === null) return;
      owner = owner.trim();

      var mode = window.prompt('What permissions for "' + entry.name + '"? Three or four ' +
        'digits, like 755 for a folder or 644 for a file. Leave it empty to keep them as ' +
        'they are.', entry.dir ? '755' : '644');
      if (mode === null) return;
      mode = mode.trim();

      if (owner === '' && mode === '') return;

      var what = [];
      if (owner !== '') what.push('owner ' + owner);
      if (mode !== '') what.push('permissions ' + mode);
      var msg = 'Set ' + what.join(' and ') + ' on "' + entry.name + '"?' +
        (entry.dir ? ' This reaches everything inside it.' : '');
      // The client only ever knows the container side of a mount — the paths
      // come from the compose file's own volume fields — so this can say the
      // change leaves the container but not where it lands.
      if (isMountPath(service, path)) {
        msg += ' This comes from your server, so it changes those files on your server too, ' +
               'not just inside the container.';
      }
      if (!window.confirm(msg)) return;

      call('cfile-chown', { name: state.stack, service: service, path: path, owner: owner, mode: mode })
        .then(function (res) {
          if (!res.ok) {
            sess.error = res.error || 'Could not change "' + entry.name + '".';
            renderFileList(service);
            return;
          }
          navigateTo(service, sess.dir);
        });
    }

    function uploadFile(service, file) {
      var sess = fileSession(service);
      if (sess.dir === null) return;
      if (file.size > CFILE_MAX) {
        sess.error = '"' + file.name + '" is ' + bytes(file.size) + ', over the ' +
          bytes(CFILE_MAX) + ' limit accepted here.';
        renderFileList(service);
        return;
      }
      var existing = sess.entries.some(function (e) { return e.name === file.name; });
      if (existing && !window.confirm('"' + file.name + '" is already in this folder. Replace it?')) return;

      var reader = new FileReader();
      reader.onload = function () {
        // btoa needs a binary string, one character per byte — the standard
        // way to get one out of an ArrayBuffer without pulling in a library,
        // which the plan already rules out for this whole file.
        var raw = new Uint8Array(reader.result);
        var binary = '';
        for (var i = 0; i < raw.length; i++) binary += String.fromCharCode(raw[i]);
        var b64 = btoa(binary);
        call('cfile-save', {
          name: state.stack, service: service, path: joinPath(sess.dir, file.name),
          body: b64, encoding: 'base64'
        }).then(function (res) {
          if (!res.ok) { sess.error = res.error || 'Could not upload "' + file.name + '".'; renderFileList(service); return; }
          navigateTo(service, sess.dir);
        });
      };
      reader.onerror = function () {
        sess.error = 'Could not read "' + file.name + '" from this computer.';
        renderFileList(service);
      };
      reader.readAsArrayBuffer(file);
    }

    // ---- switching tabs ----------------------------------------------------
    //
    // Called at the end of every render(), same place syncLogFollower() and
    // syncShellPane() sit. Unlike the log pane, a folder listing is not
    // restarted on a return visit — see fileSession()'s own comment — so
    // this only fetches when there is genuinely nothing shown yet.
    function syncFilesPane() {
      var service = state.selected;

      if (service === 'all') {
        fileState.active = null;
        showFilesMessage('Pick a container above — there is no shared file listing for the whole stack.');
        return;
      }

      if (fileState.active === service) return; // already showing and current for this one

      fileState.active = service;
      var sess = fileSession(service);
      if (sess.view) { renderFileView(service); return; }
      if (sess.dir === null) { openHome(service); return; }
      renderFileList(service);
    }

    // ---- D6: the line above the panes, and the one-press jobs -------------

    // Restart count and health, for whichever container is currently
    // selected. Fetched once per visit to a container's tab, not on every
    // one-second poll — a poll-per-container asking `docker inspect` for a
    // figure nobody is watching tick would be wasted work. Cleared whenever
    // the selection leaves a container (including to "All"), so coming back
    // to it later re-asks rather than showing a stale number forever.
    var stat = { service: null, loading: false, error: '', restarts: null, health: null };

    function mkJobBtn(label, cls) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'staxx-manage-jobbtn ' + cls;
      b.textContent = label;
      return b;
    }

    function buildAbove() {
      var wrap = document.createElement('div');
      wrap.className = 'staxx-manage-above';

      var status = document.createElement('div');
      status.className = 'staxx-manage-status staxx-manage-status--hidden';
      var pill = document.createElement('span');
      pill.className = 'staxx-manage-status-pill';
      var extra = document.createElement('span');
      extra.className = 'staxx-manage-status-extra';
      status.appendChild(pill);
      status.appendChild(extra);

      wrap.appendChild(status);
      buildButtons(wrap);

      els.statusUI = { status: status, pill: pill, extra: extra };
      return wrap;
    }

    // Each button belongs in the heading of the pane it acts on. Grouped in a
    // row of their own above all three they were buttons with no visible tie
    // to what they changed, and the row moved across the window as buttons
    // came and went — leaving one of them stranded alone on the left, greyed
    // out, whenever the whole stack was selected.
    //
    // Runs after build() has made the panes, since it needs their headings.
    function buildHeadActions() {
      var envBtn = mkJobBtn('Show environment', 'staxx-manage-job-env');
      envBtn.title = 'Runs "env" inside the container and prints the result into this pane.';
      envBtn.addEventListener('click', function () { showEnvironment(state.selected); });
      els.log.actions.appendChild(envBtn);

      // The shell heading says whether there is a session, not merely whether
      // there is something to press. A button that only appears once the
      // connection has dropped leaves the ordinary state — connected —
      // saying nothing at all, so a silent pane and a dead one look alike.
      // Two elements, one shown at a time: a plain reading while it is fine,
      // a button when it is not.
      var shellStatus = document.createElement('span');
      shellStatus.className = 'staxx-manage-shell-status';
      var statusDot = document.createElement('span');
      statusDot.className = 'staxx-manage-light';
      var statusText = document.createElement('span');
      shellStatus.appendChild(statusDot);
      shellStatus.appendChild(statusText);
      els.shell.actions.appendChild(shellStatus);

      var reconnectBtn = mkJobBtn('', 'staxx-manage-job-reconnect');
      reconnectBtn.title = 'Start a new shell session in this container.';
      var deadDot = document.createElement('span');
      deadDot.className = 'staxx-manage-light staxx-manage-light--dead';
      reconnectBtn.appendChild(deadDot);
      reconnectBtn.appendChild(document.createTextNode('Reconnect'));
      reconnectBtn.addEventListener('click', function () { reconnectShell(state.selected); });
      els.shell.actions.appendChild(reconnectBtn);

      var cfgBtn = mkJobBtn('Config folder', 'staxx-manage-job-cfg');
      cfgBtn.title = 'Point this pane at the container’s first mounted folder.';
      cfgBtn.addEventListener('click', function () { openConfigFolder(state.selected); });
      els.files.actions.appendChild(cfgBtn);

      els.jobsUI = {
        cfgBtn: cfgBtn, envBtn: envBtn, reconnectBtn: reconnectBtn,
        shellStatus: shellStatus, statusDot: statusDot, statusText: statusText
      };
      renderJobsBar();
    }

    // Called at the end of every render(), same place the other three panes'
    // own sync functions sit.
    function syncStatusLine() {
      var service = state.selected;

      if (service === 'all' || !service) {
        stat.service = null; // leaving a container's context — the next visit re-asks
        els.statusUI.status.classList.add('staxx-manage-status--hidden');
        renderJobsBar();
        return;
      }

      els.statusUI.status.classList.remove('staxx-manage-status--hidden');

      if (stat.service !== service) {
        stat.service = service;
        stat.loading = true; stat.error = ''; stat.restarts = null; stat.health = null;
        renderStatusLine();
        call('cstat', { name: state.stack, service: service }).then(function (res) {
          if (stat.service !== service) return; // superseded by another tab switch
          stat.loading = false;
          if (!res.ok) {
            stat.error = res.error || 'Could not read this container’s restarts and health.';
            renderStatusLine();
            return;
          }
          stat.restarts = res.restarts;
          stat.health = res.health;
          renderStatusLine();
        });
      }

      renderStatusLine();
      renderJobsBar();
    }

    // Uptime and health-check wording come straight from the row's own pill
    // markup (docker's own words, already resolved by the state poll) —
    // reused as-is rather than re-parsed, since it is more honest than
    // anything built here and it costs nothing extra to ask for.
    function renderStatusLine() {
      var ui = els.statusUI;
      var live = liveFor(state.snapshot, state.selected);
      ui.pill.innerHTML = (live && live.html) ? live.html : '';

      var bits = [];
      if (stat.loading) {
        bits.push('Checking restarts and health…');
      } else if (stat.error) {
        bits.push(stat.error);
      } else {
        if (stat.restarts !== null) {
          bits.push(stat.restarts + (stat.restarts === 1 ? ' restart' : ' restarts'));
        }
        if (stat.health && stat.health !== 'none') bits.push('health: ' + stat.health);
      }
      ui.extra.textContent = bits.join(' · ');
      // A container restarting in a loop is the single most useful thing
      // this line can say — flagged rather than left to blend in with a
      // container that simply restarted once, on purpose, ages ago.
      ui.extra.classList.toggle('staxx-manage-status-warn', !!(stat.restarts !== null && stat.restarts > 1));
    }

    // "Leave it out rather than offering a button that cannot work" now
    // applies to all three. Show environment used to be merely disabled for
    // the whole-stack view, which is what left one greyed-out button sitting
    // where the other two had been.
    function renderJobsBar() {
      var ui = els.jobsUI;
      if (!ui) return;   // syncStatusLine() can reach here before buildHeadActions() has run
      var service = state.selected;
      var isAll = service === 'all' || !service;
      var mounts = isAll ? null : mountsFor(service);
      var sess = isAll ? null : shellState.sessions[service];

      ui.cfgBtn.classList.toggle('staxx-manage-jobbtn--hidden', isAll || !mounts);
      ui.envBtn.classList.toggle('staxx-manage-jobbtn--hidden', isAll);

      // Three states, not two: "opening" is neither connected nor dropped,
      // and saying either of those while a session is still being set up
      // would be untrue for the second or so that it takes.
      var dead = !!sess && (sess.ended || !!sess.error);
      var opening = !!sess && !dead && !sess.id;
      ui.reconnectBtn.classList.toggle('staxx-manage-jobbtn--hidden', isAll || !dead);
      ui.shellStatus.classList.toggle('staxx-manage-shell-status--hidden', isAll || !sess || dead);
      if (sess && !dead) {
        ui.statusText.textContent = opening ? 'Connecting…' : 'Connected';
        ui.statusDot.classList.toggle('staxx-manage-light--running', !opening);
      }
    }

    function openConfigFolder(service) {
      if (service === 'all' || !service) return;
      var mounts = mountsFor(service);
      if (!mounts) return;
      revealPane('files');
      navigateTo(service, mounts[0]);
    }

    function showEnvironment(service) {
      if (service === 'all' || !service) return;
      noteLine('reading the environment inside "' + service + '"…');
      call('cenv', { name: state.stack, service: service }).then(function (res) {
        if (!res.ok) {
          noteLine('could not read the environment: ' + (res.error || 'unknown error.'));
          return;
        }
        var text = (res.text || '').replace(/\n$/, '');
        if (!text) { noteLine('this container reports no environment variables.'); return; }
        text.split('\n').forEach(function (line) { noteLine(line); });
      });
    }

    // Throws away a finished session and opens another. The one-time shell
    // warning is not asked again — it was accepted for this editor already,
    // and a session ending is not a new decision to take.
    //
    // This replaced a "Fix ownership" button that typed a half-finished chown
    // into the shell for someone to complete by hand. It put its own
    // explanation into the LOG pane, being written through the log's note
    // function, and it needed a working shell to show anything at all — so on
    // a bash-less image it produced a log line about a command that was
    // nowhere to be seen. The file listing's own Owner button does that job
    // properly now.
    function reconnectShell(service) {
      if (service === 'all' || !service) return;
      var sess = shellState.sessions[service];
      if (sess && sess.id) call('exec-close', { id: sess.id });
      pauseShellPoll();
      delete shellState.sessions[service];
      removeShellFrame(service); // the old iframe points at a dead ttyd — a fresh session gets a fresh one
      // syncShellPane() returns early while this still names the service, so
      // clearing it is what makes the next render open a session instead of
      // deciding one is already showing.
      shellState.active = null;
      render();
    }

    // ---- D7: narrow screens -------------------------------------------------
    //
    // Below the breakpoint the log/shell/files panes become a small tab row,
    // one shown at a time, instead of the fixed three-way split — see the
    // plan's own "Under the existing 45rem breakpoint" requirement and the
    // NARROW constant's comment for why the literal is duplicated rather
    // than shared with the stylesheet.

    function buildNarrowTabs() {
      var el = document.createElement('div');
      el.className = 'staxx-manage-narrowtabs';
      el.setAttribute('role', 'tablist');

      var defs = [['log', 'Log'], ['shell', 'Shell'], ['files', 'Files']];
      els.narrowTabBtns = {};
      defs.forEach(function (d) {
        var key = d[0], label = d[1];
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'staxx-manage-narrowtab';
        b.setAttribute('role', 'tab');
        b.textContent = label;
        b.addEventListener('click', function () {
          state.narrowPane = key;
          updateNarrowPanes();
        });
        els.narrowTabBtns[key] = b;
        el.appendChild(b);
      });
      return el;
    }

    // Applied unconditionally, wide screens included — the CSS class this
    // toggles only does anything inside the narrow media query, so this is
    // cheap to call from everywhere a pane's visibility might need to change
    // rather than gating every call site on NARROW.matches itself.
    function updateNarrowPanes() {
      ['log', 'shell', 'files'].forEach(function (key) {
        var active = state.narrowPane === key;
        els[key].el.classList.toggle('staxx-manage-pane--narrowhidden', !active);
        var btn = els.narrowTabBtns && els.narrowTabBtns[key];
        if (btn) {
          btn.classList.toggle('staxx-manage-narrowtab--selected', active);
          btn.setAttribute('aria-selected', active ? 'true' : 'false');
        }
      });
    }

    // Un-collapses a pane on a wide screen and switches the narrow tab row
    // to it on a narrow one — used when a D6 job needs to show its result
    // somewhere the person might currently have hidden or switched away
    // from. Safe to call regardless of width: whichever half does nothing
    // this screen is at is genuinely a no-op, not a wrong guess.
    function revealPane(key) {
      var p = els[key];
      if (!p) return;
      p.el.classList.remove('staxx-manage-pane--collapsed');
      p.head.setAttribute('aria-expanded', 'true');
      state.narrowPane = key;
      updateNarrowPanes();
    }

    // The switch and the five buttons as one group, sat on the status row.
    //
    // They used to be a 9rem column of their own down the middle of the
    // layout, which took width from all three panes for five short words —
    // and left the status row holding nothing but a chip, so selecting All
    // emptied it and everything below jumped up. Here, the row cannot
    // collapse and the panes get the width back.
    //
    // The switch sits left of the buttons and the wording right of them, with
    // the wording OUTSIDE the group so the whole-stack border can wrap the
    // control without enclosing its caption.
    function buildButtons(wrap) {
      var group = document.createElement('div');
      group.className = 'staxx-manage-controls';

      // The same Font Awesome glyph the Autostart menu item uses, rather than
      // a switch built out of CSS — Unraid already ships it, and matching that
      // control was the point. The glyph carries the position; the label to
      // the right of the group names the mode it is in.
      var toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.className = 'staxx-manage-scopetoggle';
      toggle.innerHTML = '<i class="fa fa-toggle-off"></i>';
      toggle.addEventListener('click', function () {
        if (state.selected === 'all') return; // forced to stack scope, nothing to choose
        state.scope = state.scope === 'stack' ? 'container' : 'stack';
        render();
      });
      group.appendChild(toggle);

      var btns = document.createElement('div');
      btns.className = 'staxx-manage-verbs';

      var defs = [
        ['down',      'Stop'],
        ['up',        'Start'],
        ['restart',   'Restart'],
        ['recreate',  'Recreate'],
        ['update',    'Update']
      ];
      els.verbBtns = {};
      defs.forEach(function (d) {
        var verb = d[0], label = d[1];
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'staxx-manage-verb';
        b.textContent = label;
        b.addEventListener('click', function () {
          onRun(verb, state.selected === 'all' ? '' : effectiveScopeService());
        });
        els.verbBtns[verb] = b;
        btns.appendChild(b);
      });

      group.appendChild(btns);

      var label = document.createElement('span');
      label.className = 'staxx-manage-scopelabel';

      wrap.appendChild(group);
      wrap.appendChild(label);

      els.scopeGroup = group;
      els.scopeToggle = toggle;
      els.scopeLabel = label;
    }

    // The service name to pass for a run when a single container tab is
    // selected and scope is "this container"; '' for stack scope, and '' is
    // also what "All" always means, since scope is meaningless there.
    function effectiveScopeService() {
      if (state.selected === 'all' || state.scope === 'stack') return '';
      return state.selected;
    }

    // ---- tab row -----------------------------------------------------

    function renderTabs() {
      els.tabs.innerHTML = '';

      var allTab = tabEl('all', 'All', null);
      els.tabs.appendChild(allTab);

      state.services.forEach(function (service) {
        els.tabs.appendChild(tabEl(service, service, liveFor(state.snapshot, service)));
      });
    }

    function tabEl(value, label, live) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'staxx-manage-tab';
      b.setAttribute('role', 'tab');
      b.id = 'staxx-manage-tab-' + escId(value);
      var selected = state.selected === value;
      b.setAttribute('aria-selected', selected ? 'true' : 'false');
      b.tabIndex = selected ? 0 : -1;
      if (selected) b.classList.add('staxx-manage-tab--selected');

      var icon = state.icons && state.icons[value];
      if (icon) {
        var iconWrap = document.createElement('span');
        iconWrap.className = 'staxx-manage-tab-icon';
        iconWrap.innerHTML = icon;
        b.appendChild(iconWrap);
      }

      var text = document.createElement('span');
      text.className = 'staxx-manage-tab-label';
      text.textContent = label;
      b.appendChild(text);

      if (value !== 'all') {
        var light = document.createElement('span');
        var containerState = live ? live.state : null;
        light.className = 'staxx-manage-light staxx-manage-light--' +
          (containerState ? containerState : 'unknown');
        b.appendChild(light);

        if (isWarning(containerState)) {
          var warn = document.createElement('span');
          warn.className = 'staxx-manage-warn';
          warn.textContent = '⚠';
          warn.title = 'Not plainly running or plainly stopped: ' + containerState;
          b.appendChild(warn);
        }

        var stats = document.createElement('span');
        stats.className = 'staxx-manage-tab-stats';
        var cpuMem = statsFor(live);
        stats.textContent = cpuMem;
        b.appendChild(stats);
      }

      b.addEventListener('click', function () { select(value); });
      attachTabKeys(b);
      return b;
    }

    function escId(s) {
      return String(s).replace(/[^A-Za-z0-9_-]/g, '_');
    }

    // Processor and memory come from the page's own stats reply, keyed by
    // the container's real name — accepted if present, a dash otherwise,
    // never invented here.
    function statsFor(live) {
      if (!live || !live.container) return '—';
      var s = state.snapshot && state.snapshot.stats && state.snapshot.stats[live.container];
      if (!s) return '—';
      var cpu = (s.cpu != null) ? s.cpu + '%' : '—';
      var mem = (s.mem != null) ? bytes(s.mem) : '—';
      return cpu + ' · ' + mem;
    }

    function attachTabKeys(b) {
      b.addEventListener('keydown', function (ev) {
        if (ev.key !== 'ArrowLeft' && ev.key !== 'ArrowRight') return;
        ev.preventDefault();
        var tabs = Array.prototype.slice.call(els.tabs.querySelectorAll('.staxx-manage-tab'));
        var i = tabs.indexOf(b);
        if (i === -1) return;
        var next = ev.key === 'ArrowRight' ? (i + 1) % tabs.length : (i - 1 + tabs.length) % tabs.length;
        tabs[next].focus();
        tabs[next].click();
      });
    }

    // ---- buttons state -------------------------------------------------

    function renderButtons() {
      var isAll = state.selected === 'all';

      // What a button would actually act on, which is not the same thing as
      // which tab is selected: with a container selected but the scope set to
      // the whole stack, the command goes to the stack. Keying the disables
      // off the tab instead greyed out Stop for a stack-wide stop because the
      // container happened never to have been created — refusing to do
      // something that was perfectly possible.
      var wholeStack = isAll || state.scope === 'stack';
      var live    = wholeStack ? null : liveFor(state.snapshot, state.selected);
      var created = wholeStack ? true : !!live;

      // Disable only what is genuinely impossible: a single container that
      // was never created cannot be stopped, restarted or rebuilt. Everything
      // else is left enabled and the server's own refusal explains itself,
      // which is the rule the rest of this plugin follows.
      els.verbBtns.down.disabled     = !created;
      els.verbBtns.restart.disabled  = !created;
      els.verbBtns.recreate.disabled = !created;
      els.verbBtns.up.disabled = false;
      els.verbBtns.update.disabled = false;

      // The switch, its caption and the border all read the SAME expression
      // the disables above just used, so none of the three can disagree with
      // what pressing a button would do.
      els.scopeToggle.innerHTML = '<i class="fa fa-toggle-' + (wholeStack ? 'on' : 'off') + '"></i>';
      els.scopeToggle.setAttribute('aria-pressed', wholeStack ? 'true' : 'false');
      els.scopeToggle.title = isAll
        ? 'The whole stack — there is no single container selected to narrow this to.'
        : (wholeStack ? 'Acting on the whole stack. Press to act on this container only.'
                      : 'Acting on this container. Press to act on the whole stack.');
      // Locked, not merely unhelpful: with All selected the whole stack is the
      // only honest reading, so there is nothing to choose.
      els.scopeToggle.disabled = isAll;
      els.scopeLabel.textContent = wholeStack ? 'Whole stack' : 'This container';
      els.scopeGroup.classList.toggle('staxx-manage-controls--stack', wholeStack);
    }

    // ---- public surface --------------------------------------------------

    function select(value) {
      state.selected = value;
      // state.scope is deliberately NOT touched here. "All" means the whole
      // stack, but it means it by being All — scope() derives that, and the
      // paint below ORs it in. Writing 'stack' into the stored choice instead
      // made it stick: pick All once and every container tab afterwards still
      // said "Whole stack", so the switch claimed a scope the person never
      // chose and the buttons did not use.
      render();
    }

    function render() {
      renderTabs();
      renderButtons();
      syncLogFollower();
      syncShellPane();
      syncFilesPane();
      syncStatusLine();
    }

    build();

    return {
      mount: function (spec) {
        if (torndown) { build(); torndown = false; }
        // A different stack means an entirely different set of containers —
        // nothing an already-open shell session was talking to still exists.
        closeAllShellSessions();
        resetFileSessions();
        spec = spec || {};
        state.stack = spec.stack || '';
        state.services = Array.isArray(spec.services) ? spec.services.slice() : [];
        state.icons = spec.icons || {};
        // Service name -> list of container-side mount paths. Left as {}
        // (mark nothing) when the host does not pass one — see the field's
        // own comment on state above.
        state.mounts = spec.mounts || {};
        state.snapshot = null;
        state.selected = 'all';
        // The remembered choice starts at "this container", which is the
        // plan's default. It is what a container tab will show; All overrides
        // it while All is selected, without overwriting it.
        state.scope = 'container';
        // A different stack means an entirely different set of restart
        // counts and health checks.
        state.narrowPane = 'log';
        stat.service = null; stat.loading = false; stat.error = '';
        stat.restarts = null; stat.health = null;
        render();
      },
      setSnapshot: function (snapshot) {
        // Never let a surprising snapshot shape throw mid-poll — render
        // "not known yet" instead, since this is called from the page's own
        // poll loop and must not be able to take it down.
        try {
          state.snapshot = snapshot || null;
        } catch (e) {
          state.snapshot = null;
        }
        render();
      },
      select: function (serviceOrAll) {
        if (serviceOrAll === 'all' || state.services.indexOf(serviceOrAll) !== -1) select(serviceOrAll);
      },
      selected: function () { return state.selected; },
      scope: function () { return state.selected === 'all' ? 'stack' : state.scope; },
      // Appends a StaXX-marked line to whichever view is current, so a
      // command run from Manage — or a guard answer decided elsewhere in the
      // editor — lands where the person is already looking. See PLAN_44 D3.
      note: function (text) { noteLine(String(text == null ? '' : text)); },
      unmount: function () {
        stopFollower();
        log.downloadText = null;
        closeAllShellSessions();
        host.innerHTML = '';
        host.classList.remove('staxx-manage');
        torndown = true;
      }
    };
  }

  var API = {
    create: function (opts) { return new Instance(opts || {}); }
  };

  if (typeof window !== 'undefined') window.StaxxManage = API;
})();
