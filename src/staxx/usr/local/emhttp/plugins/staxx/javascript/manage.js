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

      var log = pane('log', 'Log');
      var mid = document.createElement('div');
      mid.className = 'staxx-manage-buttons';
      var right = document.createElement('div');
      right.className = 'staxx-manage-right';
      var shell = pane('shell', 'Shell');
      var files = pane('files', 'Files');
      right.appendChild(shell.el);
      right.appendChild(files.el);

      body.appendChild(log.el);
      body.appendChild(mid);
      body.appendChild(right);

      els.log = log;
      els.shell = shell;
      els.files = files;

      buildButtons(mid);
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
    }

    build();

    return {
      mount: function (spec) {
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
      unmount: function () {
        host.innerHTML = '';
        host.classList.remove('staxx-manage');
      }
    };
  }

  var API = {
    create: function (opts) { return new Instance(opts || {}); }
  };

  if (typeof window !== 'undefined') window.StaxxManage = API;
})();
