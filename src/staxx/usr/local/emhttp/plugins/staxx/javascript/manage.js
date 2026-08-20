/* StaXX — the Manage tab's own contents.
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 * PLAN_44 phase 2: the container tab row (D1) and the buttons column (D2),
 * plus an honest skeleton for the three panes that later phases fill in
 * (log D3, shell D4, files D5).
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

  // PLAN_44 D4, the shell.
  //
  // Completed lines kept per session - smaller than LOG_CAP because a shell
  // is a conversation someone is watching live, not a record kept for later,
  // and a session outlives the tab it started on so this bounds every one of
  // them at once, not just the visible one.
  var SHELL_LINE_CAP = 2000;

  // Control bytes built at runtime rather than written as escape literals in
  // this file - see the header note on grepping for raw control bytes: a
  // literal one has already made a tool read this file as binary once, and
  // String.fromCharCode() cannot repeat that mistake.
  var CH = {
    NUL: String.fromCharCode(0),
    BEL: String.fromCharCode(7),
    FF:  String.fromCharCode(12),  // Ctrl-L
    ESC: String.fromCharCode(27),
    ETX: String.fromCharCode(3),   // Ctrl-C
    EOT: String.fromCharCode(4),   // Ctrl-D
    NAK: String.fromCharCode(21),  // Ctrl-U
    SUB: String.fromCharCode(26),  // Ctrl-Z
    DEL: String.fromCharCode(127)  // Backspace, as most shells expect it
  };

  // Foreground colour classes for SGR codes 30-37/90-97. Bright and normal
  // share a class each - this pane renders eight colours, not sixteen, which
  // is the plan's own floor and keeps the map small enough to read at a
  // glance. Picked for legibility on both a light and a dark Unraid theme,
  // not copied from any one terminal's palette.
  var SHELL_FG_CLASS = {
    30: 'k', 31: 'r', 32: 'g', 33: 'y', 34: 'b', 35: 'm', 36: 'c', 37: 'w',
    90: 'k', 91: 'r', 92: 'g', 93: 'y', 94: 'b', 95: 'm', 96: 'c', 97: 'w'
  };

  // A fresh terminal model: the lines a session has finished, the one it is
  // still writing, and the SGR/alternate-screen state that decides how the
  // next byte is rendered. One of these lives inside every shell session for
  // as long as that session does - see Instance's shell.sessions.
  function newShellTerm() {
    return { lines: [], cur: [], curCls: '', bold: false, fg: null, altScreen: false, pending: '' };
  }

  // Finds one complete escape sequence starting at text[i] (text[i] is
  // already known to be ESC), or returns null when the bytes seen so far
  // could still be the start of a longer one and more should be waited for.
  // Real terminal output arrives in arbitrary-sized chunks from a byte-offset
  // read, so a sequence split across two polls is routine, not an edge case.
  // The length caps exist so genuinely malformed input cannot stall
  // rendering forever waiting for a terminator that will never arrive.
  function matchShellEscape(text, i) {
    if (text.charAt(i + 1) === '[') {
      // Matched against what follows the ESC, then handed back WITH the ESC
      // back on the front — the caller advances by the returned length, so
      // dropping ESC here would leave it unconsumed and seen again forever.
      var m = /^\[[0-?]*[ -\/]*[@-~]/.exec(text.slice(i + 1));
      if (m) return CH.ESC + m[0];
      if (text.length - i < 32) return null;
      return text.slice(i, i + 2); // gives up and drops just the opener, rather than hanging forever
    }
    if (text.charAt(i + 1) === ']') {
      var tail = text.slice(i + 2);
      var belAt = tail.indexOf(CH.BEL);
      var escAt = tail.indexOf(CH.ESC + '\\');
      var end = belAt === -1 ? escAt : (escAt === -1 ? belAt : Math.min(belAt, escAt));
      if (end === -1) {
        if (text.length - i < 256) return null;
        return text.slice(i, i + 2);
      }
      var termLen = (end === belAt) ? 1 : 2;
      return text.slice(i, i + 2 + end + termLen);
    }
    if (text.length - i < 2) return null;
    return text.slice(i, i + 2); // a lone ESC + one byte - consumed and ignored
  }

  function applyShellSgr(term, params) {
    var codes = params === '' ? [0] : params.split(';').map(function (s) { return parseInt(s, 10) || 0; });
    codes.forEach(function (c) {
      if (c === 0) { term.bold = false; term.fg = null; }
      else if (c === 1) { term.bold = true; }
      else if (c === 22) { term.bold = false; }
      else if (c === 39) { term.fg = null; }
      else if (SHELL_FG_CLASS[c]) { term.fg = SHELL_FG_CLASS[c]; }
    });
    var cls = [];
    if (term.bold) cls.push('staxx-sh-b');
    if (term.fg) cls.push('staxx-sh-fg-' + term.fg);
    term.curCls = cls.join(' ');
  }

  // Built with `new RegExp` rather than a literal, same reasoning as
  // SHELL_SPECIAL_RE below: ESC has no safe way to sit in a regex literal in
  // this file's source, only as the CH.ESC value built at runtime.
  var SHELL_CSI_RE = new RegExp('^' + CH.ESC + '\\[([0-?]*)[ -\\/]*([@-~])$');

  // Only colour and the alternate-screen request change how anything is
  // shown; every other CSI (cursor moves, bracketed-paste toggling, scroll
  // regions...) is recognised, consumed and silently dropped - rendered as
  // nothing rather than as the gibberish it would be printed as verbatim.
  function applyShellEscape(term, seq) {
    var m = SHELL_CSI_RE.exec(seq);
    if (!m) return; // an OSC (window title) or a lone ESC+byte - nothing to render either way
    var params = m[1], final = m[2];
    if (final === 'h' && params === '?1049') { term.altScreen = true; term.cur = []; return; }
    if (final === 'l' && params === '?1049') { term.altScreen = false; return; }
    if (final !== 'm') return;
    applyShellSgr(term, params);
  }

  function shellAppendText(term, run) {
    if (term.altScreen || !run) return;
    term.cur.push({ text: run, cls: term.curCls });
  }

  function shellPushLine(term) {
    if (term.altScreen) return;
    term.lines.push(term.cur);
    term.cur = [];
    if (term.lines.length > SHELL_LINE_CAP) term.lines.shift();
  }

  function shellBackspace(term) {
    if (term.altScreen) return;
    while (term.cur.length) {
      var seg = term.cur[term.cur.length - 1];
      if (seg.text.length > 1) { seg.text = seg.text.slice(0, -1); return; }
      term.cur.pop();
      return;
    }
  }

  // Anything that ends a plain run of text: ESC (built at runtime, see CH
  // above - a literal in a regex here would be exactly the trap this file
  // has already fallen into once), CR, LF and backspace.
  var SHELL_SPECIAL_RE = new RegExp('[' + CH.ESC + '\\r\\n\\b]');

  // Consumes one chunk of raw session output - only the new bytes since the
  // last read, per the contract exec-read hands back. A bare CR (not
  // followed by LF) is a real carriage return, which is how a progress bar
  // redraws its own line; CRLF, the ending every line actually uses, is just
  // a newline once the redundant CR is folded away.
  function feedShellTerm(term, chunk) {
    var text = term.pending + chunk;
    term.pending = '';
    var i = 0;
    while (i < text.length) {
      var ch = text.charAt(i);
      if (ch === CH.ESC) {
        var seq = matchShellEscape(text, i);
        if (seq === null) { term.pending = text.slice(i); break; }
        i += seq.length;
        applyShellEscape(term, seq);
        continue;
      }
      if (ch === '\r') {
        if (text.charAt(i + 1) === '\n') { i++; continue; }
        if (!term.altScreen) term.cur = [];
        i++;
        continue;
      }
      if (ch === '\n') { shellPushLine(term); i++; continue; }
      if (ch === '\b') { shellBackspace(term); i++; continue; }
      if (ch === CH.BEL) { i++; continue; } // nothing visible, and never a sound
      if (ch === CH.NUL) { i++; continue; } // script(1)'s stray leading NUL
      var rest = text.slice(i);
      var stop = rest.search(SHELL_SPECIAL_RE);
      var run = stop === -1 ? rest : rest.slice(0, stop);
      shellAppendText(term, run);
      i += run.length;
    }
  }

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
    // Not called yet — this phase never talks to the server, only onRun().
    // Held for phases 3-5 (log tailing, shell sessions, file listings).
    var call   = opts.call;
    // Not used by this phase's own rendering — everything here goes through
    // textContent, which needs no escaping. Kept and validated anyway,
    // because phases 3-5 (log, shell, file listings) render raw text into
    // markup and will need it.
    var escFn  = typeof opts.esc === 'function' ? opts.esc : esc;
    var bytes  = typeof opts.bytes === 'function' ? opts.bytes : function (n) { return String(n); };
    var onRun  = typeof opts.onRun === 'function' ? opts.onRun : function () {};

    var state = {
      stack:      '',
      services:   [],
      icons:      {},
      snapshot:   null,
      selected:   'all',
      scope:      'container'   // 'container' | 'stack' — meaningless (and forced to stack) when selected === 'all'
    };

    var els = {};

    // unmount() clears the host's DOM (PLAN_44 C1 — whatever Manage was
    // showing dies with its editor), but this Instance is never recreated;
    // the same one mounts the next stack too. Without this, mount() after an
    // unmount would render into DOM nodes no longer attached to anything —
    // invisible, not merely stale. See mount() below.
    var torndown = false;

    function build() {
      host.innerHTML = '';
      host.classList.add('staxx-manage');

      var tabs = document.createElement('div');
      tabs.className = 'staxx-manage-tabs';
      tabs.setAttribute('role', 'tablist');
      host.appendChild(tabs);
      els.tabs = tabs;

      var body = document.createElement('div');
      body.className = 'staxx-manage-body';
      host.appendChild(body);

      var logPane = pane('log', 'Log');
      buildLogBody(logPane.body);
      var mid = document.createElement('div');
      mid.className = 'staxx-manage-buttons';
      var right = document.createElement('div');
      right.className = 'staxx-manage-right';
      var shell = pane('shell', 'Shell');
      buildShellBody(shell.body);
      var files = pane('files', 'Files');
      right.appendChild(shell.el);
      right.appendChild(files.el);

      body.appendChild(logPane.el);
      body.appendChild(mid);
      body.appendChild(right);

      els.log = logPane;
      els.shell = shell;
      els.files = files;

      buildButtons(mid);

      // Repaints whatever the follower already holds onto the fresh DOM —
      // a no-op the first time (log.lines is still empty then), but the
      // reason a rebuild after unmount() does not open on a blank pane.
      renderLines();
    }

    // A collapsible pane: a heading that toggles a body. Fixed proportions
    // come from CSS flex-basis, not from anything measured here — the plan
    // rules out draggable splits, so there is nothing to remember.
    function pane(key, title) {
      var el = document.createElement('section');
      el.className = 'staxx-manage-pane staxx-manage-pane--' + key;

      var h = document.createElement('button');
      h.type = 'button';
      h.className = 'staxx-manage-pane-head';
      h.textContent = title;
      h.setAttribute('aria-expanded', 'true');
      h.addEventListener('click', function () {
        var collapsed = el.classList.toggle('staxx-manage-pane--collapsed');
        h.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      });

      var body = document.createElement('div');
      body.className = 'staxx-manage-pane-body';
      body.innerHTML = '<p class="staxx-manage-placeholder">Not built yet — this is where the ' +
        title.toLowerCase() + ' will be.</p>';

      el.appendChild(h);
      el.appendChild(body);
      return { el: el, head: h, body: body };
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
      pollTimer: null,
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
      var atBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 6;
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
      if (log.lines.length > LOG_CAP) {
        log.lines.shift();
        if (!log.capped) {
          log.capped = true;
          log.lines.push({
            kind: 'note',
            raw: NOTE_PREFIX + 'older lines were dropped — only the most recent ' + LOG_CAP + ' are kept.',
            hidden: '…'
          });
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
      updateEmptyState();
      if (!log.paused) scrollToBottom();
    }

    function appendNewLines(fromIndex) {
      if (!els.logUI) return;
      var linesEl = els.logUI.linesEl;
      for (var i = fromIndex; i < log.lines.length; i++) linesEl.appendChild(makeLineEl(log.lines[i]));
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
      log.alive = false;
      log.error = '';
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
        }
        // alive:false means the follower ended on its own (nothing left to
        // tail, or the container was removed) — the pane already shows
        // everything there was, so polling stops rather than spinning.
        if (log.alive) schedulePoll(mySeq, POLL_MS);
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
      var startIdx = log.lines.length;
      parts.forEach(function (raw) { pushLine('log', raw); });
      appendNewLines(startIdx);
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
      var startIdx = log.lines.length;
      pushLine('note', NOTE_PREFIX + text);
      appendNewLines(startIdx);
    }

    // ---- D4: the shell -----------------------------------------------------
    //
    // One session per container, kept open server-side for as long as the
    // editor is (PLAN_44 D4's "until the editor closes, half-typed line
    // included"), but only the visible one is ever polled — a background
    // session's own output keeps landing in its exec log regardless, so the
    // next poll after switching back to it catches up in one read rather
    // than losing anything. shellState.sessions is keyed by service name;
    // there is deliberately no entry for 'all', which has no shell at all.
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
        '<p>A shell gives a root command line inside the container — the same reach as signing ' +
        'into the machine itself, but scoped to whatever this one container can see.</p>' +
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

      var alt = document.createElement('div');
      alt.className = 'staxx-manage-shell-alt staxx-manage-shell-alt--hidden';
      var altP = document.createElement('p');
      altP.textContent = 'This program wants a full screen — something like nano, htop or mc — ' +
        'which this pane cannot show. Open Unraid’s own terminal for it instead.';
      var ctrlBtn = document.createElement('button');
      ctrlBtn.type = 'button';
      ctrlBtn.className = 'staxx-manage-shell-ctrlc';
      ctrlBtn.textContent = 'Send Ctrl-C';
      ctrlBtn.addEventListener('click', function () { sendShellChunk(state.selected, CH.ETX); });
      alt.appendChild(altP);
      alt.appendChild(ctrlBtn);

      var lines = document.createElement('div');
      lines.className = 'staxx-manage-shell-lines';
      lines.tabIndex = 0;
      lines.setAttribute('role', 'log');
      lines.setAttribute('aria-label', 'Shell');
      lines.title = 'Click here, then type — this is a real shell inside the container.';
      lines.addEventListener('keydown', shellKeydown);
      lines.addEventListener('paste', shellPaste);

      screenWrap.appendChild(alt);
      screenWrap.appendChild(lines);

      bodyEl.appendChild(msg);
      bodyEl.appendChild(warn);
      bodyEl.appendChild(screenWrap);

      els.shellUI = { msg: msg, warn: warn, warnBtn: warnBtn, screenWrap: screenWrap, alt: alt, lines: lines };
    }

    function shellPanel(which) {
      var ui = els.shellUI;
      ui.msg.classList.toggle('staxx-manage-shell-msg--hidden', which !== 'msg');
      ui.warn.classList.toggle('staxx-manage-shell-warn--hidden', which !== 'warn');
      ui.screenWrap.classList.toggle('staxx-manage-shell-screenwrap--hidden', which !== 'screen');
    }

    // Stops the poll loop without closing the session — the difference
    // matters: leaving a container's tab must not lose its half-typed line
    // or its scrollback, only the one-second read nobody is watching for.
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
        id: null, offset: 0, pollTimer: null, pollSeq: 0,
        opening: true, error: '', term: newShellTerm(), rendered: 0
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
        if (!res.ok) { sess.error = res.error || 'Could not open a shell in this container.'; renderShellIfActive(service); return; }
        sess.id = res.id;
        renderShellIfActive(service);
        resumeShellPoll(service);
      });
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

    function pollShell(service, mySeq) {
      var sess = shellState.sessions[service];
      if (!sess || mySeq !== sess.pollSeq || !sess.id || service !== shellState.active) return;
      var id = sess.id;
      call('exec-read', { id: id, offset: sess.offset }).then(function (res) {
        var live = shellState.sessions[service];
        if (!live || mySeq !== live.pollSeq || live.id !== id) return; // superseded meanwhile
        live.pollTimer = null;
        if (res.ok) {
          if (res.text) feedShellTerm(live.term, res.text);
          if (typeof res.offset === 'number') live.offset = res.offset;
          if (service === shellState.active) appendShellRender(service);
        }
        // alive:false means the session ended on its own — the container
        // stopped, or the shell process exited — so polling stops rather
        // than spinning against a session that will never answer again.
        if (res.ok && res.alive !== false && service === shellState.active) {
          live.pollTimer = setTimeout(function () { pollShell(service, mySeq); }, POLL_MS);
        }
      });
    }

    function shellNoteEl(text) {
      var d = document.createElement('div');
      d.className = 'staxx-manage-shell-line staxx-manage-shell-line--note';
      d.textContent = text;
      return d;
    }

    function shellSegEl(seg) {
      var span = document.createElement('span');
      if (seg.cls) span.className = seg.cls;
      span.textContent = seg.text;
      return span;
    }

    function shellLineEl(segments) {
      var d = document.createElement('div');
      d.className = 'staxx-manage-shell-line';
      segments.forEach(function (seg) { d.appendChild(shellSegEl(seg)); });
      return d;
    }

    function shellCurEl(term) {
      var d = document.createElement('div');
      d.className = 'staxx-manage-shell-line staxx-manage-shell-cur';
      term.cur.forEach(function (seg) { d.appendChild(shellSegEl(seg)); });
      return d;
    }

    function scrollShellBottom() {
      var el = els.shellUI.lines;
      el.scrollTop = el.scrollHeight;
    }

    // Full rebuild — used whenever the visible session itself changes
    // (a tab switch, a session opening, an error arriving) rather than just
    // gaining another line. The one-second poll below never calls this
    // directly; see appendShellRender().
    function fullShellRender(service) {
      var sess = shellState.sessions[service];
      var ui = els.shellUI;
      if (!sess) return;
      shellPanel('screen');
      ui.alt.classList.add('staxx-manage-shell-alt--hidden');
      ui.lines.innerHTML = '';
      if (sess.opening) { ui.lines.appendChild(shellNoteEl('Opening a shell…')); sess.rendered = 0; return; }
      if (sess.error) { ui.lines.appendChild(shellNoteEl(sess.error)); sess.rendered = 0; return; }
      if (sess.term.altScreen) { ui.alt.classList.remove('staxx-manage-shell-alt--hidden'); sess.rendered = sess.term.lines.length; return; }
      sess.term.lines.forEach(function (line) { ui.lines.appendChild(shellLineEl(line)); });
      ui.lines.appendChild(shellCurEl(sess.term));
      sess.rendered = sess.term.lines.length;
      scrollShellBottom();
    }

    // Appends only what changed since the last tick: newly finished lines,
    // plus a fresh redraw of the one line still being written. Rebuilding
    // every line on every one-second poll is what the log pane's own
    // appendNewLines() avoids for the same reason — see its comment.
    function appendShellRender(service) {
      if (service !== shellState.active) return;
      var sess = shellState.sessions[service];
      var ui = els.shellUI;
      if (!sess || sess.opening || sess.error) { fullShellRender(service); return; }
      if (sess.term.altScreen) { fullShellRender(service); return; }
      var curEl = ui.lines.lastChild;
      if (!curEl || !curEl.classList || !curEl.classList.contains('staxx-manage-shell-cur')) {
        fullShellRender(service); return; // nothing rendered yet for this session — e.g. resuming after "All"
      }
      for (var i = sess.rendered; i < sess.term.lines.length; i++) {
        ui.lines.insertBefore(shellLineEl(sess.term.lines[i]), curEl);
      }
      sess.rendered = sess.term.lines.length;
      ui.lines.replaceChild(shellCurEl(sess.term), curEl);
      scrollShellBottom();
    }

    // Everything typed goes through one queue per session, and only one write
    // is ever in flight for it.
    //
    // This is not tidiness. Each keystroke used to be its own request, and
    // separate requests finish in whatever order the network and the server
    // give them — so typing "echo SHELL" arrived in the container as
    // "echoS HLELP-NAE". Measured, not imagined. Ordering is the whole point
    // of a terminal, so the next write waits for the previous one to land.
    //
    // Queueing also coalesces: anything typed while a write is out goes in one
    // request rather than one each, so typing fast means fewer requests, not
    // more. The 4096-byte cap exec-write enforces is what bounds each one — a
    // keystroke is never near it, a paste usually is.
    function sendShellChunk(service, text) {
      var sess = shellState.sessions[service];
      if (!sess || !sess.id || !text) return;
      sess.outbox = (sess.outbox || '') + text;
      flushShellOutbox(service);
    }

    function flushShellOutbox(service) {
      var sess = shellState.sessions[service];
      if (!sess || !sess.id || sess.writing || !sess.outbox) return;

      var CAP  = 4096;
      var part = sess.outbox.slice(0, CAP);
      sess.outbox = sess.outbox.slice(CAP);
      sess.writing = true;

      call('exec-write', { id: sess.id, bytes: part }).then(function (res) {
        sess.writing = false;
        if (!res.ok) {
          // What was still queued is dropped on purpose: it was typed at a
          // session that is no longer listening, and sending the rest of it
          // somewhere else later would be worse than losing it.
          sess.outbox = '';
          sess.error = res.error || 'The shell stopped accepting input.';
          if (service === shellState.active) fullShellRender(service);
          return;
        }
        flushShellOutbox(service);
      });
    }

    // The keys a single-line input could never carry: Ctrl-C/D/L/U/Z, tab
    // completion, arrow-key history, Home/End/Delete. Copy, paste and
    // select-all are deliberately left alone — see the two exceptions below.
    function shellKeydown(ev) {
      var service = state.selected;
      if (service === 'all') return;
      var sess = shellState.sessions[service];
      if (!sess || !sess.id) return;

      if (ev.ctrlKey) {
        var lower = ev.key.length === 1 ? ev.key.toLowerCase() : '';
        if (lower === 'c') {
          // A selection means the person wants to copy it — the browser's
          // own shortcut, left untouched. No selection means the shell's
          // own Ctrl-C, sent as the interrupt byte it always is.
          if (window.getSelection && String(window.getSelection()) !== '') return;
          ev.preventDefault(); sendShellChunk(service, CH.ETX); return;
        }
        if (lower === 'd') { ev.preventDefault(); sendShellChunk(service, CH.EOT); return; }
        if (lower === 'l') { ev.preventDefault(); sendShellChunk(service, CH.FF); return; }
        if (lower === 'u') { ev.preventDefault(); sendShellChunk(service, CH.NAK); return; }
        if (lower === 'z') { ev.preventDefault(); sendShellChunk(service, CH.SUB); return; }
        return; // every other Ctrl chord — A, V, and the rest — is left to the browser
      }

      var special = {
        Enter: '\r', Backspace: CH.DEL, Tab: '\t', Escape: CH.ESC,
        ArrowUp: CH.ESC + '[A', ArrowDown: CH.ESC + '[B', ArrowRight: CH.ESC + '[C', ArrowLeft: CH.ESC + '[D',
        Home: CH.ESC + '[H', End: CH.ESC + '[F', Delete: CH.ESC + '[3~'
      };
      if (Object.prototype.hasOwnProperty.call(special, ev.key)) {
        ev.preventDefault(); sendShellChunk(service, special[ev.key]); return;
      }
      if (ev.key.length === 1 && !ev.metaKey && !ev.altKey) {
        ev.preventDefault(); sendShellChunk(service, ev.key);
      }
    }

    // Paste is its own event rather than a Ctrl-V keydown — the pane holds
    // no editable field for the browser to paste into, so there is no
    // default behaviour here to override, only clipboard text to forward.
    function shellPaste(ev) {
      var service = state.selected;
      if (service === 'all') return;
      var sess = shellState.sessions[service];
      if (!sess || !sess.id) return;
      ev.preventDefault();
      var cd = ev.clipboardData || window.clipboardData;
      var text = cd ? cd.getData('text') : '';
      if (text) sendShellChunk(service, text);
    }

    // Closes every open session — called on unmount() (the editor closing)
    // and at the start of mount() (a different stack has an entirely
    // different set of containers, so nothing here still applies to it).
    function closeAllShellSessions() {
      Object.keys(shellState.sessions).forEach(function (svc) {
        var sess = shellState.sessions[svc];
        if (sess.pollTimer) clearTimeout(sess.pollTimer);
        if (sess.id) call('exec-close', { id: sess.id });
      });
      shellState.sessions = {};
      shellState.active = null;
    }

    // Called at the end of every render() (PLAN_44 D4) — decides what the
    // shell pane shows for whichever tab is currently selected, and moves
    // the poll loop to match. Mirrors syncLogFollower()'s place in render(),
    // but a shell's session is never restarted on a re-render the way a log
    // follower is: switching back to a container already open just resumes
    // its own poll loop exactly where it left off.
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
      var sess = shellState.sessions[service];
      if (sess.id || sess.error) fullShellRender(service);
      else { shellPanel('screen'); els.shellUI.lines.innerHTML = ''; els.shellUI.lines.appendChild(shellNoteEl('Opening a shell…')); }
      resumeShellPoll(service);
    }

    function buildButtons(mid) {
      var scope = document.createElement('div');
      scope.className = 'staxx-manage-scope';
      scope.setAttribute('role', 'group');
      scope.setAttribute('aria-label', 'Scope');

      var bContainer = scopeBtn('container', 'This container');
      var bStack = scopeBtn('stack', 'Whole stack');
      scope.appendChild(bContainer);
      scope.appendChild(bStack);
      els.scopeContainer = bContainer;
      els.scopeStack = bStack;

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

      mid.appendChild(scope);
      mid.appendChild(btns);
    }

    function scopeBtn(value, label) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'staxx-manage-scope-btn';
      b.textContent = label;
      b.addEventListener('click', function () {
        if (state.selected === 'all') return; // forced to stack scope, nothing to choose
        state.scope = value;
        render();
      });
      return b;
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
      els.scopeContainer.classList.toggle('staxx-manage-scope-btn--armed', !isAll && state.scope === 'container');
      els.scopeStack.classList.toggle('staxx-manage-scope-btn--armed', isAll || state.scope === 'stack');
      els.scopeContainer.disabled = isAll;
      // Stack scope stays clickable even on "All", since it is the only
      // honest choice there — disabling it would look broken rather than
      // forced.

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
    }

    build();

    return {
      mount: function (spec) {
        if (torndown) { build(); torndown = false; }
        // A different stack means an entirely different set of containers —
        // nothing an already-open shell session was talking to still exists.
        closeAllShellSessions();
        spec = spec || {};
        state.stack = spec.stack || '';
        state.services = Array.isArray(spec.services) ? spec.services.slice() : [];
        state.icons = spec.icons || {};
        state.snapshot = null;
        state.selected = 'all';
        // The remembered choice starts at "this container", which is the
        // plan's default. It is what a container tab will show; All overrides
        // it while All is selected, without overwriting it.
        state.scope = 'container';
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
