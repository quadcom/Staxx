/* Stack Manager — behaviour for the Stacks screen.
 * Copyright 2026, Stack Manager contributors. GPL-2.0.
 *
 * Plain browser JavaScript, no libraries. The page works on its own terms and
 * does not reach into anything Unraid renders.
 */

(function () {
  'use strict';

  var scaffold = document.querySelector('.stackman-scaffold');
  if (!scaffold) return;

  var ENDPOINT = scaffold.dataset.endpoint;
  var CSRF     = scaffold.dataset.csrf;

  var modal       = document.getElementById('stackman-modal');
  var modalTitle  = document.getElementById('stackman-modal-title');
  var modalBody   = modal.querySelector('.stackman-modal-body');
  var nameField   = document.getElementById('stackman-name-field');
  var nameInput   = document.getElementById('stackman-name');
  var nameFolder  = document.getElementById('stackman-name-folder');
  var yamlPane    = document.getElementById('stackman-yaml');
  var yamlNums    = document.getElementById('stackman-yamlnums');
  var yamlMarks   = document.getElementById('stackman-yamlmarks');
  var yamlStatus  = document.getElementById('stackman-yaml-status');
  var formHost    = document.getElementById('stackman-form');
  var sanitiseBox = document.getElementById('stackman-sanitise');
  var sanitiseNote = document.getElementById('stackman-sanitise-note');
  var gapNote     = document.getElementById('stackman-required-note');
  var errorBox    = document.getElementById('stackman-error');

  var tzModal     = document.getElementById('stackman-tz');
  var tzBands     = document.getElementById('stackman-tz-bands');
  var tzCaption   = document.getElementById('stackman-tz-caption');
  var tzChips     = document.getElementById('stackman-tz-chips');
  var tzSearch    = document.getElementById('stackman-tz-search');
  var tzList      = document.getElementById('stackman-tz-list');
  var tzMsg       = document.getElementById('stackman-tz-msg');

  var picker      = document.getElementById('stackman-picker');
  var pickerHere  = document.getElementById('stackman-picker-here');
  var pickerList  = document.getElementById('stackman-picker-list');
  var pickerMsg   = document.getElementById('stackman-picker-msg');
  var pickerNew   = document.getElementById('stackman-picker-newname');

  var YAML = window.StackmanYaml || null;

  var saveBtn  = document.getElementById('stackman-save');
  var startBtn = document.getElementById('stackman-save-start');
  var undoBtn  = document.getElementById('stackman-undo');

  // The lists a service can gain an entry in. The buttons that add one belong
  // to the SERVICE, never to the list: removing the last port has to take the
  // "ports:" key with it, because a key with nothing under it is null and
  // compose rejects the file — so a control living on the list would vanish
  // along with the list and leave no way back.
  var ADDABLE = [
    { binder: 'port',   word: 'port' },
    { binder: 'volume', word: 'volume' },
    { binder: 'device', word: 'device' },
    { binder: 'env',    word: 'variable' },
    { binder: 'label',  word: 'label' },
    // One per dynamic list group — data-add carries "list:<key>" so the click
    // handler knows which list, and addWord() keys on that whole string.
    { binder: 'list:networks',   word: 'network' },
    { binder: 'list:secrets',    word: 'secret' },
    { binder: 'list:configs',    word: 'config' },
    { binder: 'list:depends_on', word: 'service' },
    { binder: 'list:profiles',   word: 'profile' },
    { binder: 'list:dns',        word: 'DNS server' },
    { binder: 'list:cap_add',    word: 'permission' },
    { binder: 'list:expose',     word: 'port' },
    { binder: 'list:env_file',   word: 'file' },
    // The Stack section's four Add buttons — capitalised, matching the noun
    // they add rather than the lower-case word a service's own lists use.
    { binder: 'declared:networks', word: 'Network' },
    { binder: 'declared:volumes',  word: 'Volume' },
    { binder: 'declared:secrets',  word: 'Secret' },
    { binder: 'declared:configs',  word: 'Config' }
  ];

  function addWord(binder) {
    for (var i = 0; i < ADDABLE.length; i++) {
      if (ADDABLE[i].binder === binder) return ADDABLE[i].word;
    }
    return binder;
  }

  // Lower-case singular word for a declaration kind, used in the sentences
  // that say renaming, adding and removing a declaration from the form is
  // not built yet (phase 5) — one table rather than three copies of the map.
  var DECL_WORD = { networks: 'network', volumes: 'volume', secrets: 'secret', configs: 'config' };

  // What the primary-setting box is called per kind, for its tooltip. The
  // caption above the column always says "setting" — this is just the hint
  // shown on hover, same as every other box's title.
  var DECL_HINT = { networks: 'driver', volumes: 'driver',
                     secrets: 'file on the server', configs: 'file on the server' };

  // The groups a service's fields are sorted into, in the order they render.
  // `cls` picks the group's column template in the stylesheet, and `add`
  // (when set) is the binder passed through to the Add button on that group's
  // header line — the same ADDABLE binder the click handler already knows how
  // to act on.
  //
  // "formgroup", not "group": stackman-group is the stacks table's subgrid
  // wrapper, and its rule sets display and grid-template-columns on the bare
  // class.
  var GROUPS = [
    { key: 'container', heading: 'Container', cls: 'stackman-formgroup--container', note: '(required)' },
    { key: 'port',      heading: 'Ports',     cls: 'stackman-formgroup--pair',   add: 'port' },
    { key: 'volume',    heading: 'Volumes',   cls: 'stackman-formgroup--pair',   add: 'volume' },
    { key: 'device',    heading: 'Devices',   cls: 'stackman-formgroup--device', add: 'device' },
    { key: 'env',       heading: 'Variables', cls: 'stackman-formgroup--pair',   add: 'env' },
    { key: 'label',     heading: 'Labels',    cls: 'stackman-formgroup--pair',   add: 'label' },
    // No `add`: nothing here can create a healthcheck: or deploy: key, so an
    // Add button would offer an action that always fails. Same column shape
    // as Advanced (label · value · note, with ticks) — reused as-is rather
    // than given a template of their own.
    { key: 'health',     heading: 'Health check',     cls: 'stackman-formgroup--advanced' },
    { key: 'resources',  heading: 'Resource limits',  cls: 'stackman-formgroup--advanced' },
    { key: 'advanced',  heading: 'Advanced',  cls: 'stackman-formgroup--advanced' }
  ];

  // The caption text named per group; container has no R/S columns to caption,
  // and no trailing blank for a × column it never grows either.
  var CAPTIONS = {
    container: ['setting', 'value', 'note, kept in the file'],
    port:      ['on the server', 'in the container', 'note, kept in the file'],
    volume:    ['path on the server', 'path in the container', 'note, kept in the file'],
    device:    ['device', 'note, kept in the file'],
    env:       ['variable name', 'value', 'note, kept in the file'],
    label:     ['label name', 'value', 'note, kept in the file'],
    health:    ['setting', 'value', 'note, kept in the file'],
    resources: ['setting', 'value', 'note, kept in the file'],
    advanced:  ['setting', 'value', 'note, kept in the file']
  };

  // Where a field lands. `fixed` wins outright, even over `locked` — that is
  // what keeps Container at exactly four rows whether or not the file has all
  // four lines. A lock on anything else promotes it to Advanced rather than
  // leaving it stranded in a group whose columns no longer fit it. What is
  // left after that is either a listy entry, sorted by its own binder, or a
  // plain setting, which is what Advanced otherwise holds.
  function groupFor(f) {
    if (f.fixed) return 'container';
    // A declaration belongs to no service, so it gets its own bucket per
    // kind rather than falling in with Advanced. A fold field carries this
    // same binder — it is bucketed here too if a caller does not filter it
    // out first, which is why stackSectionHtml() excludes f.fold before ever
    // calling this.
    if (f.binder === 'declared') return 'declared:' + f.declKind;
    // Checked before the locked test below, deliberately against the usual
    // rule that a lock always exiles a row to Advanced: healthcheck.test is
    // itself locked (it is a flow sequence), but it IS the check that
    // healthcheck.interval and friends time — showing it in a different
    // group from its own timings would read as belonging to something else.
    if (/^healthcheck\./.test(f.target)) return 'health';
    if (/^deploy\./.test(f.target)) return 'resources';
    if (f.locked) return 'advanced';
    if (f.binder === 'setting') return 'advanced';
    if (f.binder === 'list') return 'list:' + f.listKey;
    return f.binder;
  }

  // A service's own group list: the static table, plus one dynamic group per
  // distinct list key the service actually has, inserted after Labels and
  // before Advanced — which falls out of building the list this way, since
  // Advanced is the only static entry pulled out to a separate tail. A
  // service without a given key never sees that key's group at all.
  function groupsForService(fields, serviceName) {
    var head = [], tail = [], seen = {}, i;
    for (i = 0; i < GROUPS.length; i++) (GROUPS[i].key === 'advanced' ? tail : head).push(GROUPS[i]);

    for (i = 0; i < fields.length; i++) {
      var f = fields[i];
      if (f.service !== serviceName || f.binder !== 'list' || seen[f.listKey]) continue;
      seen[f.listKey] = true;
      head.push({
        key: 'list:' + f.listKey,
        heading: f.groupTitle,
        cls: 'stackman-formgroup--single',
        add: 'list:' + f.listKey
      });
    }
    return head.concat(tail);
  }

  var logPanel = document.getElementById('stackman-log-panel');
  var logTitle = document.getElementById('stackman-log-title');
  var logBox   = document.getElementById('stackman-log');

  // "Save and start" is disabled server-side when compose or Docker is
  // missing. Remember that, so re-enabling after a save does not quietly
  // switch it back on.
  var startBtnWasDisabled = startBtn.disabled;

  var poller = null;

  // A JavaScript error anywhere in here used to end with the page frozen on
  // whatever it last said. Put it on screen instead.
  window.addEventListener('unhandledrejection', function (event) {
    if (!logPanel) return;
    logPanel.hidden = false;
    logTitle.textContent = 'Script error';
    logBox.textContent = 'Something in the page failed:\n\n' +
      (event.reason && event.reason.stack ? event.reason.stack : String(event.reason));
  });

  /* ---------------------------------------------------------------- net -- */

  // Every failure mode here has to end up visible. A reply that is not JSON —
  // a login page, a 404, a PHP error — is reported verbatim rather than
  // collapsed into "something went wrong", because the raw text is the only
  // thing that says which of those it was.
  // URLSearchParams, never FormData.
  //
  // FormData sends the request as multipart/form-data, and Unraid's web server
  // never passes a multipart POST on to PHP for this path — the request simply
  // hangs until something gives up. Proven by sending both encodings to the
  // same URL with the same session: the ordinary form encoding answers in
  // about a millisecond, the multipart one never answers at all.
  //
  // URLSearchParams sends application/x-www-form-urlencoded, which works. Do
  // not "modernise" this back to FormData.
  function call(action, fields, timeoutMs) {
    var data = new URLSearchParams();
    data.append('csrf_token', CSRF);
    data.append('action', action);
    Object.keys(fields || {}).forEach(function (k) { data.append(k, fields[k]); });

    // A request that never returns leaves the page saying "working…" forever,
    // which looks identical to a button that does nothing. Give up after a
    // while and say so.
    var limit = timeoutMs || 30000;
    var stop = null;
    var opts = { method: 'POST', body: data, credentials: 'same-origin' };

    if (typeof AbortController !== 'undefined') {
      var ctrl = new AbortController();
      opts.signal = ctrl.signal;
      stop = setTimeout(function () { ctrl.abort(); }, limit);
    }

    var settle = function (value) {
      if (stop) { clearTimeout(stop); stop = null; }
      return value;
    };

    return fetch(ENDPOINT, opts)
      .then(settle)
      .then(function (r) {
        return r.text().then(function (text) {
          try {
            return JSON.parse(text);
          } catch (e) {
            return {
              ok: false,
              error: 'The server replied with ' + r.status + ' ' + (r.statusText || '') +
                     ' and something that is not JSON.\n\n' +
                     'Endpoint: ' + ENDPOINT + '\n\n' +
                     (text ? text.slice(0, 1200) : '(empty response)')
            };
          }
        });
      })
      .catch(function (e) {
        settle();
        if (e && e.name === 'AbortError') {
          return {
            ok: false,
            error: 'The server did not answer within ' + Math.round(limit / 1000) + ' seconds.\n\n' +
                   'Action: ' + action + '\n' +
                   'Endpoint: ' + ENDPOINT + '\n\n' +
                   'Something inside the request is stuck. Run this in a terminal to see ' +
                   'the same check without the web server involved:\n\n' +
                   'php -r \'require "/usr/local/emhttp/plugins/stack.manager/include/Stacks.php"; ' +
                   'print_r(stackman_selftest());\''
          };
        }
        return {
          ok: false,
          error: 'Could not reach ' + ENDPOINT + '\n\n' + (e && e.message ? e.message : e)
        };
      });
  }

  // The endpoint may succeed while PHP also printed a warning. That warning is
  // a real problem even when the action worked, so surface it.
  function strayWarning(res) {
    return res && res.stray ? '\n\nPHP also printed:\n' + res.stray : '';
  }

  /* ------------------------------------------------------------ renaming -- */

  // Only one inline rename box may be open at a time. Holds the live box's
  // commit function, so opening a second one finishes the first rather than
  // dropping it — the same rule flushPending() applies to the form's fields.
  var inlineOpen = null;

  // Swaps `host` (whatever is currently showing the name) for a text box:
  // Enter commits, Escape restores `host` untouched, and blurring — clicking
  // away — commits too. Nothing destructive happens on blur because an empty
  // or unchanged value cancels silently before opts.save() is ever called.
  //
  // opts.save(value) does the real work. A string return is a refusal — it is
  // handed to opts.say() and the box stays open for another try. Anything
  // else means the caller has taken over (it redraws the form, or refreshes
  // the table), so the box is simply tidied away.
  function inlineName(host, current, opts) {
    if (inlineOpen) inlineOpen();

    var input = document.createElement('input');
    input.type = 'text';
    input.className = 'stackman-inline-name';
    input.spellcheck = false;
    input.autocomplete = 'off';
    input.value = current;
    if (opts.placeholder) input.placeholder = opts.placeholder;

    host.hidden = true;
    host.insertAdjacentElement('afterend', input);
    input.focus();
    input.select();

    var done = false;

    function restore() {
      input.remove();
      host.hidden = false;
    }

    function cancel() {
      if (done) return;
      done = true;
      inlineOpen = null;
      restore();
    }

    function commit() {
      if (done) return;

      // The input can be destroyed by something other than commit/cancel — the
      // editor dialog closing, or refreshRows() swapping the table body out
      // from under an open folder-name box. A stale inlineOpen must not fire
      // against text nobody is looking at any more.
      if (!input.isConnected) { done = true; inlineOpen = null; return; }

      var value = input.value.trim();
      if (!value || value === current) { cancel(); return; }

      // Set before calling save() — a save that redraws its host synchronously
      // can blur this input as a side effect, and that must not re-enter here.
      done = true;
      var refusal = opts.save(value);
      if (typeof refusal === 'string') {
        done = false;
        opts.say(refusal);
        if (input.isConnected) { input.focus(); input.select(); }
        return;
      }
      inlineOpen = null;
      restore();
    }

    input.addEventListener('keydown', function (event) {
      if (event.key === 'Enter') { event.preventDefault(); commit(); }
      else if (event.key === 'Escape') { event.preventDefault(); cancel(); }
    });
    input.addEventListener('blur', commit);

    inlineOpen = commit;
  }

  // Mirrors stackman_valid_name() in include/Stacks.php — a folder is a real
  // directory, so the same gate against a crafted or traversing name applies.
  function badFolderName(name) {
    if (!name) return 'Give the folder a name.';
    if (name.length > 63) return 'Folder names are 63 characters or fewer.';
    if (name.indexOf('..') !== -1 || !/^[A-Za-z0-9][A-Za-z0-9._-]*$/.test(name)) {
      return 'Folder names start with a letter or number, and may otherwise use letters, numbers, dots, underscores and hyphens.';
    }
    return '';
  }

  /* ------------------------------------------------------------- editor -- */

  /* The editor is a <dialog> opened with showModal(), which is doing more work
   * here than it looks. The top layer means it is not clipped by the table's
   * container-type and does not have to out-number the menu's z-index. The
   * focus trap, the inert background, Escape and focus restore on close are
   * all native — and Escape being native matters specifically, because the
   * hand-written alternative would be a second document keydown listener
   * racing the one the context menu already owns. */

  var textAtOpen  = '';    // what the file said when it opened — the dirty check
  var openedName  = '';    // the rel this editor opened at — what save() renames FROM
  var serviceRenamed = false;  // a pencil rename happened this session — offer a recreate after save

  // What a new stack starts as. A placeholder key rather than the pencil
  // straight away — a brand new service has nothing else in it yet either,
  // so the comment points at the one thing worth changing first.
  var NEW_STACK = [
    'services:',
    '',
    '  # Rename this to whatever the container is — jellyfin, plex, nextcloud.',
    '  my-app:',
    '    image: alpine:3.20',
    '    restart: unless-stopped',
    ''
  ].join('\n');

  function showError(message) {
    errorBox.textContent = message;
    errorBox.hidden = false;
    errorBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function clearError() {
    errorBox.textContent = '';
    errorBox.hidden = true;
  }

  // While Sanitise is on the compose box shows placeholders, so the real text
  // is held aside. Everything that cares about content must ask for it here
  // and never read the box directly.
  function currentText() {
    return sanitised ? realText : yamlPane.value;
  }

  function isDirty() {
    return currentText() !== textAtOpen;
  }

  // The page under the backdrop still scrolls on a wheel — <dialog> makes it
  // inert, not immobile. Save and restore the previous INLINE value so
  // whatever Unraid may have set is put back rather than blanked.
  var overflowWas = null;

  function lockScroll(on) {
    var el = document.documentElement;
    if (on) { overflowWas = el.style.overflow; el.style.overflow = 'hidden'; }
    else    { el.style.overflow = overflowWas || ''; overflowWas = null; }
  }

  /* ---- the compose pane's line numbers ---- */

  // Counted rather than split, so a keystroke does not allocate an array the
  // length of the file.
  function lineCount(text) {
    var n = 1, at = -1;
    while ((at = text.indexOf('\n', at + 1)) !== -1) n++;
    return n;
  }

  var gutterLines = -1;

  function paintGutter() {
    var n = lineCount(yamlPane.value);
    if (n === gutterLines) return;   // most keystrokes change no line count
    gutterLines = n;

    var out = [];
    for (var i = 1; i <= n; i++) out.push(i);
    yamlNums.firstElementChild.textContent = out.join('\n');

    // ch resolves against the gutter's own monospace font, which is why the
    // width is set here and not in the sheet — the wrapper's font is not the
    // one the digits are drawn in.
    yamlNums.style.width = 'calc(' + String(n).length + 'ch + 2.2rem)';

    // Read back the real width so the text and the highlight bands both clear
    // the gutter no matter what the font actually measured.
    var w = yamlNums.offsetWidth;
    yamlPane.style.paddingLeft = (w + 9) + 'px';
    yamlMarks.style.left = w + 'px';
  }

  function syncGutter() {
    yamlNums.firstElementChild.style.transform =
      'translateY(' + (-yamlPane.scrollTop) + 'px)';
  }

  yamlPane.addEventListener('input',  paintGutter);
  yamlPane.addEventListener('scroll', function () { syncGutter(); repaintMark(); });

  // Re-reading the file and redrawing the form is the expensive direction, so
  // it waits for a pause in typing rather than running on every keystroke.
  var yamlTimer = null;

  yamlPane.addEventListener('input', function () {
    if (yamlTimer) clearTimeout(yamlTimer);
    yamlTimer = setTimeout(function () { yamlTimer = null; reparse(); }, 400);
  });

  /* Split is the desktop default and is not offered on a phone, where two panes
   * of twenty characters would be worse than either alone. The same 45rem the
   * stylesheet uses, and deliberately the same string: inside a media query a
   * rem is the browser's own default font size, not the 62.5% Unraid sets on
   * the root, so writing it any other way would put the two thresholds in
   * different places. */
  var NARROW = window.matchMedia('(max-width: 45rem)');

  function defaultView() {
    return NARROW.matches ? 'form' : 'split';
  }

  function setView(view) {
    // Nothing can ask for Split at this width — the button is not there to
    // click — but a window dragged narrower can arrive here already in it.
    if (view === 'split' && NARROW.matches) view = 'form';
    modalBody.dataset.view = view;
    var btns = modal.querySelectorAll('.stackman-viewbtn');
    for (var i = 0; i < btns.length; i++) {
      btns[i].setAttribute('aria-pressed', btns[i].dataset.view === view ? 'true' : 'false');
    }
    // A band measured while its pane was hidden is a band in the wrong place.
    if (view !== 'form') { paintGutter(); syncGutter(); repaintMark(); }
  }

  // The dialog's own width now glides between Form and Split (CSS transition),
  // so the gutter's highlight bands — positioned with inline pixel values —
  // can be left mid-flight where the pane used to be. Registered once here,
  // not inside setView(), because it has to catch the width settling AFTER
  // the switch, not the switch itself.
  modal.addEventListener('transitionend', function (e) {
    if (e.target === modal && e.propertyName === 'width' &&
        modalBody.dataset.view !== 'form') { syncGutter(); repaintMark(); }
  });

  // Crossing the threshold with the editor open. Only Split has to move, and
  // only inwards: going back to a wide window leaves you where you were rather
  // than overriding a choice you made on purpose.
  NARROW.addEventListener('change', function () {
    if (modal.open && modalBody.dataset.view === 'split') setView('form');
  });

  /* ---- the form, drawn from the parsed file ---- */

  /* Read-only for now: this stage proves the file can be understood and shown,
   * and that both panes agree about where each setting lives. Making the
   * controls write back is the next stage, and doing it in that order means no
   * version of this can spoil a file before the round-trip is proven. */

  var MODEL = null;      // the last form that parsed
  var activeField = null;

  function esc(s) {
    return String(s === undefined || s === null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  // One editable box. A part with nowhere to write to — the host half of an
  // anonymous volume, say — is still shown, so the row reads as the mapping it
  // is, but it cannot be typed in.
  // The little button that can sit beside a box. One mechanism, named by what
  // it opens, rather than a separate flag per tool.
  var TOOLS = {
    browse: { icon: 'folder-open-o', title: 'Choose a folder on this server', label: 'Choose a folder' },
    tz:     { icon: 'globe',         title: 'Choose a timezone from a map',   label: 'Choose a timezone' },
    device: { icon: 'plug',          title: 'Choose a device on this server', label: 'Choose a device' }
  };

  // Settings whose value is one of a fixed few, so the box is a list to pick
  // from rather than something to spell correctly. Keyed by binder and target,
  // which is what identifies a field.
  var CHOICES = {
    'setting/restart': {
      hint: 'when to start it again',
      options: [
        ['no',             'no — leave it stopped'],
        ['always',         'always — start it again whenever it stops'],
        ['unless-stopped', 'unless-stopped — always, unless you stopped it'],
        ['on-failure',     'on-failure — only when it crashes']
      ]
    },
    'setting/network_mode': {
      hint: 'which network the container joins',
      options: [
        ['bridge', 'bridge — Docker’s own private network'],
        ['host',   'host — share the server’s network directly'],
        ['none',   'none — no network at all']
      ]
    }
  };

  // What hardware this server says it has. Filled in by the device picker far
  // below and read up here, because a device row is titled after the thing it
  // points at: humanising "/dev/dri" gives "Dev Dri", which tells nobody
  // anything, while the catalogue can say "Intel graphics".
  //
  // Empty until the first reply lands, which is why devLoaded gates the "not
  // found on this server" tag — before that, every row would wrongly wear it.
  //
  // devPresent, not devIndex, is what answers that tag. The catalogue is a
  // curated list of hardware worth offering, so a path nobody would ever suggest
  // — one specific USB node, a disk by its id — is absent from it while being
  // perfectly present on the machine.
  var devIndex   = {};      // host path -> catalogue entry, for naming a row
  var devPresent = {};      // host path -> true, for "is this actually here"
  var devClaims  = {};      // host path -> stacks already mapping it
  var devGroups  = [];      // as the server grouped them
  var devLoaded  = false;

  // This server's own docker networks, appended to the network_mode dropdown
  // once they arrive — see netLoad() far below, beside devLoad().
  var netLoaded  = false;

  // Compose also accepts forms this list does not carry — "on-failure:3" — so
  // a value already in the file that is not on the list joins it as it stands.
  // A dropdown that could not show the current value would change the file
  // just by being opened.
  // A `from` field points at a namespace the file declares once at the top —
  // networks:, secrets:, configs:, services: — and offers those real names
  // instead of a box to spell one in. `default` is added for networks even
  // when the file never declares it, because compose creates that network
  // itself regardless.
  function fromChoice(f) {
    var names = ((MODEL && MODEL.declared && MODEL.declared[f.from]) || []).slice();
    if (f.from === 'networks' && names.indexOf('default') < 0) names.push('default');
    var options = [];
    for (var i = 0; i < names.length; i++) options.push([names[i], names[i]]);
    return { hint: 'a name already declared in this file', options: options };
  }

  // A leading space is never a legal Docker volume name, so this can never
  // collide with a real declaration — picking it writes nothing (see the
  // change handler) and instead hands the row over to the path box.
  var VOL_FOLDER_SENTINEL = ' folder-on-server';

  // A volume's host half is either a name Docker manages the storage for, or
  // a path on the server — never both, and only the file itself says which.
  // Offered as a dropdown only while the value looks like a name; see the
  // host-part branch in boxHtml() below for the other half of that call.
  function volumeSourceChoice() {
    var names = (MODEL && MODEL.declared && MODEL.declared.volumes) || [];
    var options = [];
    for (var i = 0; i < names.length; i++) options.push([names[i], names[i]]);
    options.push([VOL_FOLDER_SENTINEL, 'a folder on the server…']);
    return { hint: 'a named volume Docker manages, or a folder on the server', options: options };
  }

  // network_mode also accepts "service:<name>" — join another service's
  // network stack instead of getting one of its own — one option per OTHER
  // service in the file (a service cannot share its own network). Built
  // fresh on every call rather than folded into CHOICES the way netLoad()
  // appends the server's own docker networks: that table is shared by every
  // service's row, and mutating it here would leak one service's option
  // list into every other service's dropdown.
  function serviceModeOptions(serviceName) {
    var names = (MODEL && MODEL.declared && MODEL.declared.services) || [];
    var options = [];
    for (var i = 0; i < names.length; i++) {
      if (names[i] === serviceName) continue;
      options.push(['service:' + names[i],
                    'service:' + names[i] + ' — share ' + names[i] + '’s network']);
    }
    return options;
  }

  function optionsHtml(choice, value) {
    var out = [], known = false;
    for (var i = 0; i < choice.options.length; i++) {
      var o = choice.options[i];
      if (o[0] === value) known = true;
      out.push('<option value="' + esc(o[0]) + '"' + (o[0] === value ? ' selected' : '') +
               '>' + esc(o[1]) + '</option>');
    }
    if (!known) out.unshift('<option value="' + esc(value) + '" selected>' + esc(value) + '</option>');
    return out.join('');
  }

  // `head` is raw HTML — already built and already escaped by the caller —
  // rendered inside the box, just above the input line. It exists so a device
  // row's heading (and its tags) can ride inside the box rather than as a
  // sibling: a grouped row's children ARE its grid columns, so a full-width
  // sibling would end the row and push everything after it back to column 1.
  // `noChoice` forces the plain path box for a volume's host part even when
  // its value would otherwise read as a bare name — see swapVolumeToPath(),
  // which renders that box by hand right after the sentinel option is chosen,
  // while the value itself (still blank) has not changed at all.
  function boxHtml(f, index, which, hint, tool, head, noChoice) {
    var p = f.parts[which];
    if (!p) return '';
    // Absent counts as writable — typing into an empty Container slot is what
    // gives it a line in the file. Only a part with truly nowhere to write, or
    // a locked row, renders disabled.
    var dead = (!p.spot && !f.absent) || f.locked || f.blocked;
    var t = TOOLS[tool];
    // The options say what the setting means, so the hint below the box says
    // what the setting is for instead of repeating "value".
    var choice = which === 'value' ? CHOICES[f.binder + '/' + f.target] : null;

    // See serviceModeOptions() above for why this is joined per call rather
    // than stored on CHOICES itself.
    if (choice && f.target === 'network_mode') {
      choice = { hint: choice.hint, options: choice.options.concat(serviceModeOptions(f.service)) };
    }
    if (!choice && which === 'value' && f.from) choice = fromChoice(f);

    // A volume's host half: a name is Docker-managed storage, so offer the
    // file's own declared names once there is no path already sitting in the
    // box. A value with a slash, or one that interpolates a variable, is a
    // path already and stays the honest path box instead of being guessed
    // into a dropdown it does not belong in. `p.spot` excludes an anonymous
    // volume, which has nothing here to write to at all.
    if (!noChoice && !choice && which === 'host' && f.binder === 'volume' && p.spot &&
        p.value.indexOf('/') < 0 && p.value.indexOf('${') < 0) {
      choice = volumeSourceChoice();
    }

    if (choice) hint = choice.hint;

    var boxTitle = hint;

    var control = choice
      ? '<select class="stackman-input stackman-choose"' +
              ' data-row="' + index + '" data-part="' + which + '"' +
              ' aria-label="' + esc(f.title + ' — ' + boxTitle) + '"' +
              ' title="' + esc(boxTitle) + '"' +
              (dead ? ' disabled' : '') + '>' +
          optionsHtml(choice, p.value) +
        '</select>'
      : '<input type="text" class="stackman-input"' +
              ' data-row="' + index + '" data-part="' + which + '"' +
              ' value="' + esc(p.value) + '"' +
              ' aria-label="' + esc(f.title + ' — ' + boxTitle) + '"' +
              ' title="' + esc(boxTitle) + '"' +
              ' spellcheck="false" autocomplete="off"' +
              (dead ? ' disabled' : '') + '>';

    // A <div>, not a <label>, because the Browse button sits beside the input.
    // A label may not hold interactive content other than its own control, and
    // a click on a button inside one is not reliably kept away from it — so the
    // input carries its name in aria-label instead of by being wrapped.
    return '<div class="stackman-box">' +
             (head || '') +
             '<div class="stackman-boxline">' +
               control +
               // Never beside a dropdown: the tool mechanism reads and writes
               // a text box directly (pickerOpen sets .value on it), and a
               // <select>'s value has to be one of its own options.
               (t && !dead && !choice
                 ? '<button type="button" class="stackman-browse"' +
                        ' data-tool="' + tool + '" data-row="' + index + '"' +
                        ' title="' + esc(t.title) + '">' +
                     '<i class="fa fa-' + t.icon + '" aria-hidden="true"></i>' +
                     '<span class="stackman-sr">' + esc(t.label) + '</span>' +
                   '</button>'
                 : '') +
             '</div>' +
             '<span class="stackman-boxhint">' + esc(hint) + '</span>' +
           '</div>';
  }

  // The heading on the two kinds of row that still have one — a device, named
  // after its hardware, and a locked row, whose boxes are gone. Title and tags
  // share one wrapper because a row's children ARE its grid cells: left loose,
  // each tag would claim a column of its own and push the boxes out of line
  // with the group's captions.
  function headHtml(title, tags) {
    var bits = ['<div class="stackman-fieldhead">',
                '<span class="stackman-fieldtitle">' + esc(title) + '</span>'];
    for (var i = 0; i < tags.length; i++) {
      if (tags[i]) bits.push(tags[i]);
    }
    bits.push('</div>');
    return bits.join('');
  }

  // The note box is identical wherever it appears, so it is built once rather
  // than four times over.
  function noteBoxHtml(f, index) {
    return '<label class="stackman-box stackman-box--note">' +
             '<input type="text" class="stackman-input" data-row="' + index + '"' +
                   ' data-note="1" value="' + esc(f.note) + '"' +
                   ' spellcheck="false" autocomplete="off"' +
                   (f.commentSpot ? '' : ' disabled') + '>' +
             '<span class="stackman-boxhint">note, kept in the file</span>' +
           '</label>';
  }

  // A plain-English gloss of a command/entrypoint, describing STRUCTURE
  // only — what runs, whether a shell is involved, how many steps — never
  // MEANING. The plugin cannot know what "/app/run" does, so a guess would
  // be worse than nothing: anything not confidently parsed returns '' and
  // no paragraph is shown at all.

  var SHELLS = { sh: 1, bash: 1, ash: 1, dash: 1, zsh: 1 };

  // one..ten spelled out, since a sentence starting "5 steps" reads like a
  // typo where "Five steps" does not. Above ten the word gets unwieldy, so
  // digits take over.
  var NUMWORDS = ['', 'one', 'two', 'three', 'four', 'five',
                  'six', 'seven', 'eight', 'nine', 'ten'];

  function cmdBasename(p) {
    var i = p.lastIndexOf('/');
    return i < 0 ? p : p.slice(i + 1);
  }

  function numWord(n) {
    var w = n <= 10 ? NUMWORDS[n] : String(n);
    return n <= 10 ? w.charAt(0).toUpperCase() + w.slice(1) : w;
  }

  // What separates one piece from the next, per shape. Anchored, because the
  // splitter below asks each one "does a separator start right here".
  var SEP_WS    = /^\s/;          // argv on one line
  var SEP_COMMA = /^,/;           // a ["a", "b"] flow list
  var SEP_STEP  = /^(&&|;)/;      // one shell line into its steps

  // The one quoted-token splitter every command shape below is built from.
  // It walks the string, treats ' and " as opening and closing a quoted
  // run, and only looks for a separator outside one — so `-c "a && b"` is
  // not chopped on its own spaces. Quote characters are consumed, never
  // kept, so tokens come back already unwrapped. An unclosed quote means the
  // text was never meant to be split this way at all, so the whole thing is
  // abandoned (null) rather than guessed at. A null separator splits
  // nowhere, which is how a single token gets its quotes stripped.
  function splitQuoted(str, sep) {
    var tokens = [], buf = '', quote = '', i = 0, m;
    while (i < str.length) {
      var ch = str.charAt(i);
      if (quote) {
        if (ch === quote) quote = ''; else buf += ch;
        i++;
        continue;
      }
      if (ch === '"' || ch === "'") { quote = ch; i++; continue; }
      m = sep ? sep.exec(str.slice(i)) : null;
      if (m) {
        if (buf) { tokens.push(buf); buf = ''; }
        i += m[0].length;
        continue;
      }
      buf += ch;
      i++;
    }
    if (quote) return null;
    if (buf) tokens.push(buf);
    return tokens;
  }

  function dequote(s) {
    var t = splitQuoted(s, null);
    return t === null ? null : (t.length ? t[0] : '');
  }

  // A token starting with "-" absorbs the next token when that token is
  // not itself a flag and the first has no "=" of its own already
  // supplying a value. This is a shape rule, not a knowledge of any
  // particular flag, so it pairs "--config /etc/app.conf" correctly and
  // just as happily pairs a boolean flag with the positional word that
  // happens to follow it — the model has no way to know "--verbose" does
  // not take a value.
  function groupTokens(tokens) {
    var groups = [], i;
    for (i = 0; i < tokens.length; i++) {
      var t = tokens[i];
      if (t.charAt(0) === '-' && t.indexOf('=') < 0 &&
          i + 1 < tokens.length && tokens[i + 1].charAt(0) !== '-') {
        groups.push(t + ' ' + tokens[i + 1]);
        i++;
      } else {
        groups.push(t);
      }
    }
    return groups;
  }

  // Groups rendered as <code> and capped at six, so a fifteen-flag command
  // does not produce an unreadable wall of text. No count is offered: a
  // reader who is told "four arguments" and shown two things has been given
  // a puzzle rather than a summary.
  function groupsHtml(groups) {
    var codes = [], i;
    for (i = 0; i < groups.length && i < 6; i++) {
      codes.push('<code>' + esc(groups[i]) + '</code>');
    }
    if (groups.length > 6) return codes.join(', ') + ', and ' + (groups.length - 6) + ' more';
    if (codes.length < 2) return codes.join('');
    return codes.slice(0, -1).join(', ') + ' and ' + codes[codes.length - 1];
  }

  function plainSay(argv) {
    var prog = argv[0], rest = argv.slice(1);
    if (prog.charAt(0) === '-') {
      // No program to name — argv[0] is itself one of its own arguments.
      return 'Started with its own arguments: ' + groupsHtml(groupTokens(argv)) + '.';
    }
    var out = 'Runs <code>' + esc(prog) + '</code>.';
    if (rest.length) out += ' It is given ' + groupsHtml(groupTokens(rest)) + '.';
    return out;
  }

  function shellSay(line) {
    var out = 'Runs a shell, which is handed one line to run: “' + esc(line) + '”.';
    var steps = splitQuoted(line, SEP_STEP);
    // A stray apostrophe in ordinary text can look like an unclosed quote —
    // losing the whole sentence over that would be worse than just not
    // splitting it into steps.
    if (steps === null) steps = [line];
    var clean = [], i;
    for (i = 0; i < steps.length; i++) {
      var s = steps[i].replace(/^\s+|\s+$/g, '');
      if (s) clean.push(s);
    }
    if (clean.length > 1) {
      out += ' ' + numWord(clean.length) + ' steps, run in order.';
      // Only the "exec" case is said aloud — anything else about which
      // step matters is a guess at meaning, which this summary does not
      // make.
      if (/^exec\s/.test(clean[clean.length - 1])) {
        out += ' The last replaces the shell, so it becomes the container’s main process.';
      }
    }
    return out;
  }

  function argvSay(argv) {
    var cIdx = argv.indexOf('-c');
    if (SHELLS[cmdBasename(argv[0])] && cIdx >= 0 && argv[cIdx + 1] !== undefined) {
      return shellSay(argv[cIdx + 1]);
    }
    return plainSay(argv);
  }

  function scriptSay(lines) {
    var out = 'A script of ' + lines.length + ' line' + (lines.length === 1 ? '' : 's') +
              '. The container runs each line in turn.';
    var first = '', i;
    for (i = 0; i < lines.length; i++) {
      var t = lines[i].replace(/^\s+|\s+$/g, '');
      if (t) { first = t; break; }
    }
    var word = first ? first.split(/\s+/)[0] : '';
    if (word) out += ' It begins with <code>' + esc(word) + '</code>.';
    return out;
  }

  function foldedSay(lines) {
    return 'One long line, wrapped across ' + lines.length +
           ' lines in the file so it stays readable. It runs as a single command.';
  }

  // f.raw is the file's own text for a locked command/entrypoint, key line
  // included — "command:", "command: |" or "command: [\"a\",\"b\"]" as
  // line one, so parsing it here can never disagree with the <pre> shown
  // above it.
  function commandFromRaw(raw) {
    var idx = raw.indexOf(':');
    if (idx < 0) return null;
    var nl = raw.indexOf('\n');
    var lineOneTail = (nl < 0 ? raw.slice(idx + 1) : raw.slice(idx + 1, nl))
                        .replace(/^\s+|\s+$/g, '');
    var fullTail = raw.slice(idx + 1);
    var i, lines, t;

    if (/^[|>][-+]?$/.test(lineOneTail)) {
      lines = raw.split('\n').slice(1);
      while (lines.length && lines[lines.length - 1].replace(/\s+/g, '') === '') lines.pop();
      if (!lines.length) return null;
      var indent = -1;
      for (i = 0; i < lines.length; i++) {
        if (lines[i].replace(/\s+/g, '') === '') continue;
        var lead = lines[i].length - lines[i].replace(/^ */, '').length;
        if (indent < 0 || lead < indent) indent = lead;
      }
      if (indent < 0) indent = 0;
      var body = [];
      for (i = 0; i < lines.length; i++) body.push(lines[i].slice(indent));
      return { kind: lineOneTail.charAt(0) === '|' ? 'script' : 'folded', lines: body };
    }

    // The bracket has to open the value itself, not merely appear somewhere in
    // it — "- echo [ok]" is a list item that happens to contain one, and
    // reading the whole block as a flow list would report the wrong command
    // entirely. Once it does open the value, the rest of the text is fair
    // game, since a flow list may run over several lines.
    if (lineOneTail.charAt(0) === '[') {
      var open = fullTail.indexOf('['), close = fullTail.lastIndexOf(']');
      if (close < open) return null;
      var toks = splitQuoted(fullTail.slice(open + 1, close), SEP_COMMA);
      if (toks === null) return null;
      var argv = [];
      for (i = 0; i < toks.length; i++) {
        t = toks[i].replace(/^\s+|\s+$/g, '');
        if (t) argv.push(t);
      }
      if (!argv.length) return null;
      return { kind: 'argv', argv: argv };
    }

    if (lineOneTail === '') {
      lines = raw.split('\n').slice(1);
      var items = [];
      for (i = 0; i < lines.length; i++) {
        t = lines[i].replace(/^\s+|\s+$/g, '');
        if (t === '') continue;
        if (t.slice(0, 2) !== '- ') return null;   // not a clean list — give up
        var tok = dequote(t.slice(2));
        if (tok === null) return null;
        items.push(tok);
      }
      if (!items.length) return null;
      return { kind: 'argv', argv: items };
    }

    return null;
  }

  // The gloss's own text, with no wrapper — split out so refreshRanges()
  // can drop fresh text straight into an existing [data-say] element rather
  // than rebuilding it. Only for command/entrypoint, and only ever '' unless
  // the shape can be read with confidence — see the file-level comment
  // above for why a guess is worse than silence here.
  function commandSayText(f) {
    if (f.target !== 'command' && f.target !== 'entrypoint') return '';

    var parsed;
    if (f.locked) {
      parsed = commandFromRaw(f.raw || '');
    } else {
      var v = f.parts.value ? f.parts.value.value : '';
      var argv = v ? splitQuoted(v, SEP_WS) : null;
      parsed = (argv && argv.length) ? { kind: 'argv', argv: argv } : null;
    }
    if (!parsed) return '';

    return parsed.kind === 'script' ? scriptSay(parsed.lines)
         : parsed.kind === 'folded' ? foldedSay(parsed.lines)
         : argvSay(parsed.argv);
  }

  // Emitted for every command and entrypoint, even when there is nothing to
  // say. A row is not redrawn while its box is being typed in, so the element
  // has to already be there for refreshRanges() to fill — otherwise a command
  // that starts out unreadable could never grow a sentence. Hidden while
  // empty, because an empty paragraph still takes its own margin.
  function commandSay(f) {
    if (f.target !== 'command' && f.target !== 'entrypoint') return '';
    var text = commandSayText(f);
    return '<p class="stackman-fieldhint" data-say="1"' + (text ? '' : ' hidden') + '>' +
             text +
           '</p>';
  }

  // f.advice's own text, with no wrapper — split out so refreshRanges() can
  // drop fresh notes straight into an existing [data-advice] element rather
  // than rebuilding the row. Each entry is independent (a dangling reference,
  // a value using an outside variable...) so every one gets its own line.
  function adviceText(f) {
    var advice = f.advice || [];
    var out = '';
    for (var i = 0; i < advice.length; i++) {
      out += '<p class="stackman-fieldnote">' + esc(advice[i]) + '</p>';
    }
    return out;
  }

  // Emitted for every field, even with nothing to say — same reason as
  // commandSay() above: the element has to already exist for refreshRanges()
  // to fill it as a value is typed, without redrawing the row under the caret.
  function adviceBlock(f) {
    var text = adviceText(f);
    return '<div class="stackman-advice" data-advice="1"' + (text ? '' : ' hidden') + '>' +
             text +
           '</div>';
  }

  // Everything else on a declaration — internal:, driver_opts: and the rest —
  // lives in a fold, not on the row (see declaredFields() in the model). They
  // are found by scanning MODEL.fields for the ones marked f.fold under this
  // declaration's own target, rather than carried on the row itself, so
  // refreshRanges() (which re-maps rows by index and never redraws) needs no
  // new bookkeeping to keep them in step.
  function foldFieldsFor(f) {
    var out = [], prefix = f.target + '.';
    for (var i = 0; i < MODEL.fields.length; i++) {
      var t = MODEL.fields[i];
      if (t.fold && t.declKind === f.declKind && t.target.indexOf(prefix) === 0) out.push(i);
    }
    return out;
  }

  // One leaf inside that fold. `index` is the leaf's OWN index into
  // MODEL.fields — never the row's — so typing in its box, or refreshRanges()
  // matching it back up afterwards, addresses that leaf and nothing else. A
  // locked leaf (driver_opts:, ipam: — a map, not a scalar) has no editable
  // part at all, so it falls back to the raw text a fully-locked row shows,
  // just without repeating that row's own heading and note.
  function declaredFoldHtml(t, index) {
    var body = t.locked
      ? '<pre class="stackman-fieldraw">' + esc(t.raw || '') + '</pre>'
      : boxHtml(t, index, 'value', 'value');
    return '<div class="stackman-foldrow">' +
             '<span class="stackman-fieldlabel">' + esc(t.title) + '</span>' +
             body +
           '</div>';
  }

  // A declaration's name: text plus a pencil, never a live box — a box
  // commits through the debounce, so it would rename to every half-typed
  // spelling as it goes, rewriting every reference each time. `data-decl-kind`
  // and `data-decl-name` carry what the click handler needs to act on and,
  // after the rename redraws the form, to find this row's pencil again.
  function declNameHtml(f, index) {
    var word = DECL_WORD[f.declKind] || 'declaration';
    return '<div class="stackman-declname">' +
             '<span class="stackman-declname-text">' + esc(f.parts.name.value) + '</span>' +
             '<button type="button" class="stackman-svcrename" data-decl-rename="1"' +
                    ' data-row="' + index + '" data-decl-kind="' + esc(f.declKind) + '"' +
                    ' data-decl-name="' + esc(f.parts.name.value) + '"' +
                    ' aria-label="Rename this ' + esc(word) + '" title="Rename this ' + esc(word) + '">' +
               '<i class="fa fa-pencil" aria-hidden="true"></i>' +
             '</button>' +
           '</div>';
  }

  // How many of the file's fields still point at this declared name — read
  // the same way buildForm's own 1e dangling-reference check does (a volume's
  // host half, or a plain list entry's value), so removing a declaration
  // still in use can say how many places would be left dangling.
  function declaredRefCount(kind, name) {
    var n = 0;
    for (var i = 0; i < MODEL.fields.length; i++) {
      var f = MODEL.fields[i];
      if (f.from !== kind) continue;
      var val = f.parts.host ? f.parts.host.value : (f.parts.value ? f.parts.value.value : '');
      if (val === name) n++;
    }
    return n;
  }

  function fieldHtml(f, index) {
    var grp    = groupFor(f);
    var isContainer = grp === 'container';
    var declared = f.binder === 'declared';
    var mapped = f.binder === 'port' || f.binder === 'volume' || f.binder === 'device';
    var named  = !!f.parts.name;                  // a variable or a label
    var listy  = mapped || f.binder === 'env' || f.binder === 'label' || f.binder === 'list';
    // Never on a Container row — its four settings are not a list. Not for an
    // entry written in a way the model sealed either, since that refuses
    // anyway and a button that always says no is worse than no button. A
    // declaration is removable too, so it gets the × the same as any list entry.
    var showKill  = !isContainer && (listy || declared) && f.target.charAt(0) !== '@';
    var bits = [];
    // A device's folded-away container path. Built inside the branch below but
    // emitted at the very end of the row — see the tail.
    var devMore = '';

    // A device is named after the hardware it points at, when this server has
    // that hardware. When the path is not on the machine at all, the row says so
    // — a compose file written on another server is the usual reason a container
    // will not start, and that is worth saying out loud rather than showing a
    // path that looks perfectly fine.
    //
    // A device can also be written as a single path, meaning the same path both
    // sides. The model puts that one in the container half and leaves the host
    // half with nowhere to write to, so that is the half to read and to show.
    var dev  = f.binder === 'device';
    var solo = dev && (!f.parts.host || !f.parts.host.spot);
    var host = !dev ? ''
             : solo ? (f.parts.container ? f.parts.container.value : '')
                    : f.parts.host.value;
    var kit  = host ? devIndex[host] : null;
    var lost = dev && host && devLoaded && !devPresent[host];

    var roTag   = f.mode === 'ro'
                ? '<span class="stackman-fieldtag">read-only mount</span>' : '';
    var lostTag = lost
                ? '<span class="stackman-fieldtag stackman-fieldtag--lost">' +
                  'not found on this server</span>' : '';

    bits.push('<div class="stackman-fieldrow' + (f.locked ? ' stackman-fieldrow--locked' : '') +
              (f.sensitive ? ' stackman-fieldrow--secret' : '') +
              '" data-row="' + index + '" data-field-row="' + esc(f.id) + '"' +
              ' data-from="' + (f.range ? f.range.start : -1) + '"' +
              ' data-to="'   + (f.range ? f.range.end   : -1) + '"' +
              ' tabindex="0">');

    // A Container row has no ticks — there is nothing to mark required about a
    // setting that already always has to be there. Neither does a
    // declaration: required and sensitive describe a form control, and a
    // name-and-driver pair has none.
    if (!isContainer && !declared) {
      // The words are hidden, not deleted — the group caption above already
      // names these two columns "R" and "S", so spelling them out again beside
      // every tick would just be noise. Still in the accessibility tree, since
      // a bare checkbox with no name is not acceptable there.
      bits.push('<label class="stackman-flag" title="Do not let this be left empty">' +
                  '<input type="checkbox" data-row="' + index + '" data-required="1"' +
                  (f.required ? ' checked' : '') + (f.commentSpot ? '' : ' disabled') + '>' +
                  '<span class="stackman-sr">required</span>' +
                '</label>');
      bits.push('<label class="stackman-flag" title="Hide this value when Sanitise is on">' +
                  '<input type="checkbox" data-row="' + index + '" data-secret="1"' +
                  (f.sensitive ? ' checked' : '') + (f.commentSpot ? '' : ' disabled') + '>' +
                  '<span class="stackman-sr">sensitive</span>' +
                '</label>');
    }

    if (f.locked) {
      // The boxes are gone, so the name has nowhere else to live — the title
      // that every other row dropped reappears here instead.
      bits.push(headHtml(f.title, [roTag, lostTag]));
      bits.push('<pre class="stackman-fieldraw">' + esc(f.raw || '') + '</pre>');
      bits.push(commandSay(f));
      bits.push('<p class="stackman-fieldnote">Not editable here because ' +
                esc(f.lockReason) + '. Use the Compose view.</p>');
      bits.push(adviceBlock(f));
    } else if (f.binder === 'setting') {
      // Every group this can land in — Container, Health check, Resource
      // limits, Advanced — holds nothing but plain settings (Container's
      // four are always fixed settings; a lock on anything else already went
      // to the branch above), so binder is what actually decides this shape,
      // not which group the row happens to be filed under. One value box,
      // named by a label column because a single box on its own could not
      // say what it holds.
      bits.push('<span class="stackman-fieldlabel">' + esc(f.title) + '</span>');
      bits.push(boxHtml(f, index, 'value', 'value'));
      bits.push(noteBoxHtml(f, index));
    } else if (dev) {
      // One box, not two. For nearly every device the two halves are the same
      // path, and where they differ the picker has already set both — a USB
      // stick is mapped from its stable /dev/serial/by-id name to the short
      // name the app inside expects. So the container half is folded away, with
      // its value in the summary, which makes the row shorter and not quieter.
      //
      // The picker is not the folder browser wearing a different hat: that one
      // walks directories under /mnt, while this one lists what the kernel says
      // is attached. Browsing /dev by hand is what never finds what you came
      // for, which is why there was no button here before.
      var hint = kit ? kit.hint : 'path to the device on this server';
      var head = headHtml(kit ? kit.label : f.title, [roTag, lostTag]);

      if (solo) {
        // Written as one path, so there is no second one to fold away.
        bits.push(boxHtml(f, index, 'container', hint, 'device', head));
      } else {
        bits.push(boxHtml(f, index, 'host', hint, 'device', head));

        var into = f.parts.container;
        if (into) {
          var differs = into.value && host && into.value !== host;
          devMore = '<details class="stackman-devmore">' +
                      '<summary>' + (differs
                        ? 'appears inside the container as ' + esc(into.value)
                        : 'change the path inside the container') + '</summary>' +
                      boxHtml(f, index, 'container', 'path in the container') +
                    '</details>';
        }
      }
      bits.push(noteBoxHtml(f, index));
    } else if (mapped) {
      // Only a volume gets the folder picker. A port is a number, so browsing
      // for one would be a button that never finds what you came for.
      bits.push(boxHtml(f, index, 'host',
                f.binder === 'port' ? 'on the server' : 'path on the server',
                f.binder === 'volume' ? 'browse' : ''));
      // A mount someone deliberately made read-only has to keep saying so.
      // The row lost its title, so the badge moves in beside the path it
      // qualifies rather than disappearing with it.
      bits.push(boxHtml(f, index, 'container',
                f.binder === 'port' ? 'in the container' : 'path in the container',
                '', roTag));
      bits.push(noteBoxHtml(f, index));
    } else if (named) {
      // The name is a field like any other. Without it, adding a variable
      // would produce a row that could never be called anything.
      bits.push(boxHtml(f, index, 'name',
                f.binder === 'label' ? 'label name' : 'variable name'));
      // Nearly every image takes one of these, and it has to be an IANA
      // name — getting it wrong is quiet, and every log line is hours out.
      bits.push(boxHtml(f, index, 'value', 'value',
                f.binder === 'env' && /^(tz|timezone)$/i.test(f.target) ? 'tz' : ''));
      bits.push(noteBoxHtml(f, index));
    } else if (f.binder === 'list') {
      // A plain list entry is one whole value with no second half to pair it
      // with — networks, secrets, configs, depends_on and the rest, all of
      // which share this one shape. boxHtml() turns it into a dropdown on
      // its own whenever f.from names a namespace the file declares.
      bits.push(boxHtml(f, index, 'value', 'value', f.tool));
      bits.push(noteBoxHtml(f, index));
    } else if (declared) {
      bits.push(declNameHtml(f, index));
      bits.push(boxHtml(f, index, 'value', DECL_HINT[f.declKind] || 'setting'));
      bits.push(noteBoxHtml(f, index));

      var foldIdx = foldFieldsFor(f);
      if (foldIdx.length) {
        var foldBits = [];
        for (var fi = 0; fi < foldIdx.length; fi++) {
          foldBits.push(declaredFoldHtml(MODEL.fields[foldIdx[fi]], foldIdx[fi]));
        }
        devMore = '<details class="stackman-devmore"><summary>more settings</summary>' +
                  foldBits.join('') + '</details>';
      }
    }

    if (showKill) {
      bits.push('<button type="button" class="stackman-kill" data-row="' + index + '"' +
                ' data-remove="1">' +
                  '<i class="fa fa-times" aria-hidden="true"></i> Remove' +
                  '<span class="stackman-sr"> ' + esc(f.title) + '</span>' +
                '</button>');
    }

    // Everything full-width comes last, after every cell the row's column
    // template names. A full-width child ends the grid row it lands on and
    // resets auto-placement to column 1 below it, so anything emitted after
    // one is stranded in the tick gutter rather than in its own column.
    //
    // A row that is not fully locked can still be partly restricted — an
    // anonymous volume with no host half, a "- FOO" passthrough with no value.
    // Say so beneath the boxes, same as a locked row's fuller version above.
    // The command/entrypoint gloss lands here too, for the same reason.
    if (devMore) bits.push(devMore);
    if (!f.locked && f.lockReason) {
      bits.push('<p class="stackman-fieldnote">' + esc(f.lockReason) + '.</p>');
    }
    if (!f.locked) bits.push(commandSay(f));
    if (!f.locked) bits.push(adviceBlock(f));

    bits.push('</div>');
    return bits.join('');
  }

  // A group's header line: its heading, the grey note beside Container's, and
  // its own Add button where it has one.
  function groupHeadHtml(g, serviceName) {
    var bits = ['<div class="stackman-grouphead"><h5 class="stackman-fieldgroup">' + esc(g.heading)];
    if (g.note) bits.push(' <span class="stackman-groupnote">' + esc(g.note) + '</span>');
    bits.push('</h5>');
    if (g.add) {
      bits.push('<button type="button" class="stackman-add"' +
               ' data-add="' + g.add + '" data-service="' + esc(serviceName) + '">' +
               '<i class="fa fa-plus" aria-hidden="true"></i> ' + esc(addWord(g.add)) +
               '</button>');
    }
    bits.push('</div>');
    return bits.join('');
  }

  // One row naming what sits in each column below it. Skipped entirely when
  // the group holds nothing — a caption over no rows has nothing to caption.
  // A dynamic list group has no entry in CAPTIONS — there is one per compose
  // key and growing that table by hand defeats the point — so it falls back
  // to the same one-box shape as Devices, which is the template it shares.
  function captionRow(grp) {
    var declared = grp.key.slice(0, 9) === 'declared:';
    var cols = CAPTIONS[grp.key] ||
               (grp.cls === 'stackman-formgroup--single' ? ['value', 'note, kept in the file'] : null) ||
               (declared ? ['name', 'setting', 'note, kept in the file'] : null);
    if (!cols) return '';
    var bits = ['<div class="stackman-caption" aria-hidden="true">'];
    // Container has nothing to require or hide, and neither does a
    // declaration — required and sensitive are about a form control, and a
    // name/driver pair is not one.
    if (grp.key !== 'container' && !declared) {
      bits.push('<span class="stackman-capflag" title="required">R</span>');
      bits.push('<span class="stackman-capflag" title="sensitive">S</span>');
    }
    for (var i = 0; i < cols.length; i++) bits.push('<span>' + esc(cols[i]) + '</span>');
    // Container is the only group with no × column to leave a blank for.
    if (grp.key !== 'container') bits.push('<span></span>');
    bits.push('</div>');
    return bits.join('');
  }

  // The Stack section's four groups, in the fixed order the file's own
  // declarations render — networks, then volumes, secrets, configs. Every
  // one carries `add` and so renders even with no rows: an Add button is the
  // only way to create a first declaration, so an empty group cannot be
  // dropped the way an empty Health check group is (see the loop in
  // renderForm below).
  var DECL_GROUPS = [
    { key: 'declared:networks', heading: 'Networks', cls: 'stackman-formgroup--declared', add: 'declared:networks' },
    { key: 'declared:volumes',  heading: 'Volumes',  cls: 'stackman-formgroup--declared', add: 'declared:volumes' },
    { key: 'declared:secrets',  heading: 'Secrets',  cls: 'stackman-formgroup--declared', add: 'declared:secrets' },
    { key: 'declared:configs',  heading: 'Configs',  cls: 'stackman-formgroup--declared', add: 'declared:configs' }
  ];

  // A pseudo-service above the real ones for the file's own networks:,
  // volumes:, secrets: and configs: blocks — they belong to no service,
  // which is exactly what f.service === '' means. Reuses .stackman-svc so
  // the heading matches a service's rather than inventing a second scale.
  //
  // f.fold is excluded here, not in groupFor() — a fold field carries the
  // same 'declared:<kind>' bucket its parent row does, but it renders inside
  // that row's own <details>, never as a row of its own (see fieldHtml).
  function stackSectionHtml(form) {
    var buckets = {};
    for (var i = 0; i < form.fields.length; i++) {
      var f = form.fields[i];
      if (f.service !== '' || f.fold) continue;
      var gk = groupFor(f);
      if (!buckets[gk]) buckets[gk] = [];
      buckets[gk].push(i);
    }

    var out = ['<section class="stackman-svc stackman-svc--stack">',
               '<h4 class="stackman-svchead">Stack</h4>'];
    for (var g = 0; g < DECL_GROUPS.length; g++) {
      var grp = DECL_GROUPS[g], rows = buckets[grp.key] || [];
      out.push('<div class="stackman-formgroup ' + grp.cls + '" data-group="' + grp.key + '">');
      out.push(groupHeadHtml(grp, ''));
      if (rows.length) out.push(captionRow(grp));
      for (var r = 0; r < rows.length; r++) out.push(fieldHtml(form.fields[rows[r]], rows[r]));
      out.push('</div>');
    }
    out.push('</section>');
    return out.join('');
  }

  function renderForm(form) {
    if (!form.ok || !form.services.length) {
      var why = form.warnings.length ? form.warnings[0].message
                                     : 'There is nothing in this file to show yet.';
      return '<p class="stackman-form-empty">' + esc(why) + '</p>';
    }

    var out = [stackSectionHtml(form)];
    for (var s = 0; s < form.services.length; s++) {
      var svc = form.services[s];
      out.push('<section class="stackman-svc" data-service="' + esc(svc.name) + '"' +
               ' data-from="' + svc.range.start + '" data-to="' + svc.range.end + '">');
      out.push('<h4 class="stackman-svchead">' +
               '<span class="stackman-svcname">' + esc(svc.name) + '</span>' +
               ' <button type="button" class="stackman-svcrename" data-svc-rename="1"' +
               ' data-service="' + esc(svc.name) + '"' +
               ' aria-label="Rename this service" title="Rename this service">' +
               '<i class="fa fa-pencil" aria-hidden="true"></i></button>' +
               '</h4>');
      if (svc.overview) out.push('<p class="stackman-fieldhint">' + esc(svc.overview) + '</p>');
      if (svc.note)     out.push('<p class="stackman-fieldnote">' + esc(svc.note) + '</p>');

      // Each group's own header now carries its Add button, so there is no
      // longer one strip listing every list a service could grow. A service
      // the parser could not read gets no groups at all, since adding to one
      // would only ever fail.
      if (svc.readable) {
        // Bucketed by ORIGINAL index into form.fields, not by a fresh count —
        // that index is the row's identity in the DOM, and refreshRanges()
        // re-maps it against a rebuilt model without ever redrawing.
        var groups  = groupsForService(form.fields, svc.name);
        var buckets = {};
        for (var g = 0; g < groups.length; g++) buckets[groups[g].key] = [];
        for (var i = 0; i < form.fields.length; i++) {
          if (form.fields[i].service !== svc.name) continue;
          var gk = groupFor(form.fields[i]);
          // A binder groupsForService did not build a bucket for would
          // otherwise throw on .push — reparse() has no other net under it.
          if (!buckets[gk]) buckets[gk] = [];
          buckets[gk].push(i);
        }

        for (var gi = 0; gi < groups.length; gi++) {
          var grp = groups[gi], rows = buckets[grp.key];
          // A group with an Add button is kept even at zero rows, so the
          // button survives its list emptying out. One with none — Health
          // check and Resource limits, so far — has nothing left to show for
          // an empty bucket but a bare heading, which is noise, not a row.
          if (!rows.length && !grp.add) continue;
          out.push('<div class="stackman-formgroup ' + grp.cls + '" data-group="' + grp.key + '">');
          out.push(groupHeadHtml(grp, svc.name));
          if (rows.length) out.push(captionRow(grp));
          for (var r = 0; r < rows.length; r++) out.push(fieldHtml(form.fields[rows[r]], rows[r]));
          out.push('</div>');
        }
      }

      out.push('</section>');
    }
    return out.join('');
  }

  function setYamlStatus(text) {
    yamlStatus.textContent = text || '';
  }

  /* ---- required fields ---- */

  /* A marker lives on a line, so -!R means "do not leave this blank" and not
   * "this entry must be present" — a field that is not in the file has no
   * comment to carry the mark. Saying it plainly here because the difference
   * matters the first time someone expects the other behaviour. */

  function emptyValue(f) {
    var p = f.parts.host || f.parts.value;
    // A "- FOO" pass-through has no value box at all, so its name is the only
    // thing that could be left blank.
    if (p && !p.spot && f.parts.name && f.parts.name.spot) p = f.parts.name;
    return !p || String(p.value).trim() === '';
  }

  function requiredGaps() {
    var out = [];
    if (!MODEL) return out;
    for (var i = 0; i < MODEL.fields.length; i++) {
      var f = MODEL.fields[i];
      if ((f.required || f.fixedRequired) && !f.locked && emptyValue(f)) out.push({ index: i, field: f });
    }
    return out;
  }

  function updateRequired() {
    var gaps = requiredGaps();

    var rows = formHost.querySelectorAll('.stackman-fieldrow');
    for (var i = 0; i < rows.length; i++) {
      var at = rows[i].dataset.row | 0;
      var bad = gaps.some(function (g) { return g.index === at; });
      rows[i].classList.toggle('stackman-fieldrow--gap', bad);
    }

    if (!gaps.length) {
      gapNote.hidden = true;
      gapNote.textContent = '';
      // Never switch Save-and-start back on by passing this check. It is
      // disabled server-side when compose or Docker is missing, and that
      // decision outranks anything happening in the form.
      saveBtn.disabled  = sanitised;
      startBtn.disabled = sanitised || startBtnWasDisabled;
      return;
    }

    var first = gaps[0].field;
    gapNote.hidden = false;
    gapNote.textContent = gaps.length === 1
      ? '"' + first.title + '" is required and empty.'
      : '"' + first.title + '" is required and empty, and ' +
        (gaps.length - 1) + ' other' + (gaps.length > 2 ? 's are' : ' is') + ' too.';
    gapNote.dataset.row = gaps[0].index;

    saveBtn.disabled  = true;
    startBtn.disabled = true;
  }

  gapNote.addEventListener('click', function () {
    var row = formHost.querySelector('.stackman-fieldrow[data-row="' + (gapNote.dataset.row | 0) + '"]');
    if (!row) return;
    // Only when the form is not on screen at all. Someone already looking at
    // it has not asked to be moved.
    if (modalBody.dataset.view === 'yaml') setView(defaultView());
    row.scrollIntoView({ block: 'center' });
    var box = row.querySelector('input:not([disabled])');
    if (box) box.focus();
  });

  function reparse() {
    if (!YAML) { formHost.innerHTML = '<p class="stackman-form-empty">The form view could not load.</p>'; return; }

    var doc  = YAML.parse(yamlPane.value);
    var form = YAML.buildForm(doc);
    form.doc = doc;
    MODEL = form;

    var scrollWas = formHost.scrollTop;
    devPanel = null;            // the device panel lives in here and just went
    formHost.innerHTML = renderForm(form);
    formHost.scrollTop = scrollWas;

    var warned = form.warnings.length;
    setYamlStatus(warned ? form.warnings[0].message +
                           (warned > 1 ? '  (and ' + (warned - 1) + ' more)' : '') : '');
    repaintMark();
    updateRequired();
  }

  /* ---- form -> file ---- */

  /* The form is never redrawn by an edit made IN the form. It already shows
   * what was typed, and redrawing it would take the caret with it. Only the
   * model behind it is refreshed, and only the attributes that carry line
   * numbers are written back into the rows. */

  function refreshRanges() {
    var doc   = MODEL.doc;
    var fresh = YAML.buildForm(doc);
    fresh.doc = doc;
    MODEL = fresh;

    var rows = formHost.querySelectorAll('.stackman-fieldrow');
    for (var i = 0; i < rows.length; i++) {
      var f = MODEL.fields[rows[i].dataset.row | 0];
      if (!f) continue;
      rows[i].dataset.fieldRow = f.id;
      rows[i].dataset.from = f.range ? f.range.start : -1;
      rows[i].dataset.to   = f.range ? f.range.end   : -1;
      rows[i].classList.toggle('stackman-fieldrow--secret', !!f.sensitive);

      // A slot that just gained its line in the file goes from having no
      // comment to write to, to having one — flip the note and tick inputs
      // back on to match, without redrawing the row they live in.
      var canComment = !!f.commentSpot;
      var note = rows[i].querySelector('[data-note]');
      if (note) note.disabled = !canComment;
      var req = rows[i].querySelector('[data-required]');
      if (req) req.disabled = !canComment;
      var sec = rows[i].querySelector('[data-secret]');
      if (sec) sec.disabled = !canComment;

      // The command/entrypoint gloss is prose derived from the value, so it
      // goes stale exactly like the note and ticks above — refreshed in
      // place rather than by redrawing the row, which would take the caret
      // with it mid-edit.
      var say = rows[i].querySelector('[data-say]');
      if (say) {
        say.innerHTML = commandSayText(f);
        say.hidden = !say.innerHTML;
      }

      // A dangling reference or a ${VAR} note can appear or clear as the
      // value is typed, so it is refreshed the same way as the command
      // gloss above — in place, not by redrawing the row under the caret.
      var advice = rows[i].querySelector('[data-advice]');
      if (advice) {
        advice.innerHTML = adviceText(f);
        advice.hidden = !advice.innerHTML;
      }
    }
    activeField = null;
    repaintMark();
    updateRequired();
  }

  function commit(el) {
    if (!MODEL || sanitised) return;

    var row = el.closest('.stackman-fieldrow');
    var f   = MODEL.fields[el.dataset.row | 0];
    if (!f) return;

    var done;
    if (el.dataset.note !== undefined || el.dataset.secret !== undefined ||
        el.dataset.required !== undefined) {
      // The note and both markers share one comment, so all three are written
      // at once from whatever the row currently shows.
      var note = row.querySelector('[data-note]');
      var sec  = row.querySelector('[data-secret]');
      var req  = row.querySelector('[data-required]');
      done = YAML.setComment(MODEL.doc, MODEL, f.id, note ? note.value : '',
                             sec ? sec.checked : false,
                             req ? req.checked : false);
    } else {
      done = YAML.setPart(MODEL.doc, MODEL, f.id, el.dataset.part, el.value);
    }

    if (!done) {
      setYamlStatus('That value cannot be written as it stands — edit this one in the Compose view.');
      return;
    }

    setYamlStatus('');
    yamlPane.value = YAML.serialise(MODEL.doc);   // assigning .value fires no
    paintGutter();                                // input event, so this cannot
    refreshRanges();                              // loop back round
  }

  // The small button that hands a swapped-to-path volume row back to the
  // dropdown — see swapVolumeToPath()/swapVolumeToChoice() below. Rendered
  // above the boxline via boxHtml()'s `head` slot, same trick a device row's
  // heading already uses to avoid ending the grid row early.
  function volSwitchBackHtml(index) {
    return '<button type="button" class="stackman-browse" data-vol-switch="1"' +
                ' data-row="' + index + '" title="Use a named volume instead">' +
             '<i class="fa fa-database" aria-hidden="true"></i> Use a named volume instead' +
           '</button>';
  }

  // Picking the sentinel writes nothing, so a redraw would just see the same
  // unwritten value and offer the dropdown straight back — the swap has to be
  // done by hand instead. `noChoice` on boxHtml() is what stops it re-guessing.
  function swapVolumeToPath(select) {
    var index = select.dataset.row | 0;
    var f = MODEL && MODEL.fields[index];
    var box = select.closest('.stackman-box');
    var row = select.closest('.stackman-fieldrow');
    if (!f || !box || !row) return;

    row.dataset.source = 'path';
    box.outerHTML = boxHtml(f, index, 'host', 'path on the server', 'browse',
                            volSwitchBackHtml(index), true);

    var input = row.querySelector('[data-part="host"]');
    if (input) { input.focus(); input.select(); }
  }

  // The mis-click's way back — free while nothing has been written yet, since
  // the value itself never changed while the path box was showing. Runs the
  // same detection boxHtml() does on a fresh render, only by hand.
  function swapVolumeToChoice(button) {
    var index = button.dataset.row | 0;
    var f = MODEL && MODEL.fields[index];
    var box = button.closest('.stackman-box');
    var row = button.closest('.stackman-fieldrow');
    if (!f || !box || !row) return;

    delete row.dataset.source;
    box.outerHTML = boxHtml(f, index, 'host',
                            'a named volume Docker manages, or a folder on the server', '');

    var sel = row.querySelector('[data-part="host"]');
    if (sel) sel.focus();
  }

  var commitTimer = null;
  var pendingEl   = null;

  formHost.addEventListener('input', function (event) {
    if (!event.target.dataset.row) return;
    if (commitTimer) clearTimeout(commitTimer);
    pendingEl = event.target;
    // Long enough to skip mid-word churn, short enough that the compose pane
    // still feels live.
    commitTimer = setTimeout(function () {
      commitTimer = null;
      var el = pendingEl; pendingEl = null;
      commit(el);
    }, 250);
  });

  formHost.addEventListener('change', function (event) {
    var el = event.target;

    // Choosing "a folder on the server…" from a volume's host dropdown must
    // never reach the file — swap the control instead of committing it.
    if (el.tagName === 'SELECT' && el.dataset.part === 'host' && el.value === VOL_FOLDER_SENTINEL) {
      var f = MODEL && MODEL.fields[el.dataset.row | 0];
      if (f && f.binder === 'volume') { swapVolumeToPath(el); return; }
    }

    // A tick or a pick from a list is a decision, not a keystroke — commit it
    // at once. A dropdown fires input as well, so the pending timer that set
    // off has to be dropped or the same edit is written twice.
    if (el.dataset.secret === undefined &&
        el.dataset.required === undefined &&
        el.tagName !== 'SELECT') return;
    if (commitTimer) { clearTimeout(commitTimer); commitTimer = null; pendingEl = null; }
    commit(el);
  });

  /* ---- adding and removing entries ---- */

  /* Both change the SET of rows, so unlike a value edit they redraw the whole
   * form. A value edit deliberately never does — the form already shows what
   * was typed, and rebuilding would take the caret with it — but here there is
   * a row that did not exist a moment ago, or one that has gone. */

  var undoStack = [];

  function updateUndo() {
    var top = undoStack[undoStack.length - 1];
    undoBtn.disabled = sanitised || !top;
    undoBtn.title = top ? 'Undo ' + top.what : 'Nothing to undo yet';
  }

  // Called BEFORE the document is touched. The compose pane and the model are
  // in step at that moment, which is what makes the snapshot honest.
  function pushUndo(what) {
    undoStack.push({ text: yamlPane.value, what: what });
    if (undoStack.length > 25) undoStack.shift();
    updateUndo();
  }

  function structuralEdit(line, say) {
    yamlPane.value = YAML.serialise(MODEL.doc);
    paintGutter();
    activeField = null;          // whatever was highlighted may have just gone
    reparse();
    if (say) setYamlStatus(say);
    updateUndo();
    if (line < 0) return;

    var id  = YAML.fieldAtLine(MODEL, line);
    var row = id && formHost.querySelector('[data-field-row="' + id.replace(/"/g, '\\"') + '"]');
    if (!row) return;

    // The new row is in the form, so the form has to be on screen to show it.
    if (modalBody.dataset.view === 'yaml') setView(defaultView());
    row.scrollIntoView({ block: 'center' });

    // Selected, not just focused: the new entry arrives with a placeholder in
    // it, so typing should replace it rather than append to it.
    var box = row.querySelector('input:not([disabled])');
    if (box) { box.focus(); box.select(); }
  }

  formHost.addEventListener('click', function (event) {
    if (sanitised || !MODEL) return;

    var add = event.target.closest('[data-add]');
    if (add) {
      // A device is chosen from what this server actually has, never typed. The
      // placeholder route wrote /dev/dri whether or not the machine had a
      // graphics card, and a second one wrote /dev/dri2, which is not a path to
      // anything at all.
      if (add.dataset.add === 'device') {
        devOpen(add.closest('.stackman-grouphead'), null, add.dataset.service);
        return;
      }
      // A declaration has no service to hand YAML.addItem below, so it goes
      // through the model's own nested insert instead — same shape, one
      // level up (see YAML.addDeclared).
      if (add.dataset.add.slice(0, 9) === 'declared:') {
        var declKind = add.dataset.add.slice(9);
        flushPending();
        pushUndo('adding that ' + addWord(add.dataset.add));
        var declLine = YAML.addDeclared(MODEL.doc, declKind, DECL_WORD[declKind] || 'item');
        if (declLine < 0) {
          undoStack.pop();
          updateUndo();
          setYamlStatus('That block is written in a way the form cannot add to — ' +
                        'add it in the Compose view instead.');
          return;
        }
        structuralEdit(declLine, '');
        return;
      }
      flushPending();
      pushUndo('adding that ' + addWord(add.dataset.add));

      // A dynamic list group's button carries which list as "list:<key>" —
      // one binder covers all of them in the model, so the key rides as its
      // own argument rather than being folded into the binder string there.
      var addBinder = add.dataset.add, listKey = '';
      if (addBinder.slice(0, 5) === 'list:') {
        listKey   = addBinder.slice(5);
        addBinder = 'list';
      }
      var line = YAML.addItem(MODEL.doc, MODEL, add.dataset.service, addBinder, '', listKey);
      if (line < 0) {
        undoStack.pop();
        updateUndo();
        setYamlStatus('That list is written in a way the form cannot add to — ' +
                      'add it in the Compose view instead.');
        return;
      }
      structuralEdit(line, '');
      return;
    }

    var rename = event.target.closest('[data-svc-rename]');
    if (rename) {
      var was = rename.dataset.service;
      var nameHost = rename.closest('.stackman-svchead').querySelector('.stackman-svcname');
      if (!nameHost) return;

      inlineName(nameHost, was, {
        // The compose pane (and its status line) is hidden in Form view, which
        // is where this pencil lives — a refusal has to go to the footer instead.
        say: showError,
        save: function (next) {
          clearError();
          flushPending();
          pushUndo('renaming the service "' + was + '" to "' + next + '"');
          var renamed = YAML.renameService(MODEL.doc, was, next);
          if (!renamed.ok) {
            undoStack.pop();
            updateUndo();
            return renamed.error;
          }

          serviceRenamed = true;
          structuralEdit(-1, 'Renamed "' + was + '" to "' + next + '"' +
                        (renamed.refs > 0
                          ? '. ' + renamed.refs + (renamed.refs === 1 ? ' reference' : ' references') + ' updated.'
                          : '.'));

          // structuralEdit() just redrew the whole form, which took focus with
          // it — land it back on the pencil for the section that now exists.
          var pencil = formHost.querySelector('[data-svc-rename][data-service="' + next + '"]');
          if (pencil) pencil.focus();
        }
      });
      return;
    }

    var declRename = event.target.closest('[data-decl-rename]');
    if (declRename) {
      var declKind = declRename.dataset.declKind;
      var was = declRename.dataset.declName;
      var declWord = DECL_WORD[declKind] || 'declaration';
      var nameHost = declRename.closest('.stackman-declname').querySelector('.stackman-declname-text');
      if (!nameHost) return;

      inlineName(nameHost, was, {
        say: showError,
        save: function (next) {
          clearError();
          flushPending();
          pushUndo('renaming the ' + declWord + ' "' + was + '" to "' + next + '"');
          var renamed = YAML.renameDeclared(MODEL.doc, declKind, was, next);
          if (!renamed.ok) {
            undoStack.pop();
            updateUndo();
            return renamed.error;
          }

          structuralEdit(-1, 'Renamed "' + was + '" to "' + next + '"' +
                        (renamed.refs > 0
                          ? '. ' + renamed.refs + (renamed.refs === 1 ? ' reference' : ' references') + ' updated.'
                          : '.'));

          // structuralEdit() just redrew the whole form, which took focus with
          // it — land it back on the pencil for the row that now exists.
          var pencil = formHost.querySelector('[data-decl-rename][data-decl-kind="' + declKind +
                      '"][data-decl-name="' + next + '"]');
          if (pencil) pencil.focus();
        }
      });
      return;
    }

    var kill = event.target.closest('[data-remove]');
    if (!kill) return;

    var f = MODEL.fields[kill.dataset.row | 0];
    if (!f) return;

    // A declaration splices out its whole block (and networks:/volumes:/…
    // with it, when it was the last one) via YAML.removeDeclared rather than
    // removeItem, which knows nothing of this binder. It does not refuse when
    // a service still references the name — see PLAN_4.md's dangling-reference
    // advice on the referencing row — so say how many do, instead.
    if (f.binder === 'declared') {
      var declKind = f.declKind, declName = f.parts.name.value;
      var declWord = DECL_WORD[declKind] || 'declaration';
      var refs = declaredRefCount(declKind, declName);

      flushPending();
      pushUndo('removing the ' + declWord + ' "' + declName + '"');
      if (!YAML.removeDeclared(MODEL.doc, declKind, declName)) {
        undoStack.pop();
        updateUndo();
        setYamlStatus('That block is written in a way the form cannot remove — ' +
                      'remove it in the Compose view instead.');
        return;
      }
      structuralEdit(-1, 'Removed the ' + declWord + ' "' + declName + '"' +
                    (refs > 0
                      ? '. ' + refs + (refs === 1 ? ' service still refers' : ' services still refer') + ' to it.'
                      : '. Undo is at the bottom if that was wrong.'));
      return;
    }

    flushPending();
    pushUndo('removing ' + f.title);
    if (!YAML.removeItem(MODEL.doc, MODEL, f.id)) {
      undoStack.pop();
      updateUndo();
      setYamlStatus('That entry is written in a way the form cannot remove — ' +
                    'remove it in the Compose view instead.');
      return;
    }
    structuralEdit(-1, 'Removed ' + f.title + '. Undo is at the bottom if that was wrong.');
  });

  undoBtn.addEventListener('click', function () {
    var step = undoStack.pop();
    if (!step) return;
    yamlPane.value = step.text;
    paintGutter();
    reparse();
    setYamlStatus('Undid ' + step.what + '.');
    updateUndo();
  });

  // Moving into the compose pane, or opening the folder picker, must not
  // silently drop an edit still waiting on its timer. The element is
  // remembered rather than read back from document.activeElement, which by the
  // time this runs is often the button that caused it.
  function flushPending() {
    if (!commitTimer) return;
    clearTimeout(commitTimer);
    commitTimer = null;
    var el = pendingEl;
    pendingEl = null;
    if (el && el.isConnected) commit(el);
  }

  /* ---- highlighting, both ways ---- */

  var LINE_H = 0, PAD_T = 0;

  function measure() {
    var cs = window.getComputedStyle(yamlPane);
    LINE_H = parseFloat(cs.lineHeight);
    PAD_T  = parseFloat(cs.paddingTop);
    // A theme that leaves line-height at `normal` gives a keyword, not pixels,
    // and every band would land at the top of the box.
    if (!LINE_H) LINE_H = parseFloat(cs.fontSize) * 1.45;
    if (!PAD_T) PAD_T = 0;
  }

  function repaintMark() {
    yamlMarks.textContent = '';
    if (!activeField || !MODEL) return;

    var f = YAML.fieldById(MODEL, activeField);
    if (!f || !f.range) return;

    if (!LINE_H) measure();
    var band = document.createElement('div');
    band.className = 'stackman-mark';
    band.style.top    = (PAD_T + f.range.start * LINE_H - yamlPane.scrollTop) + 'px';
    band.style.height = ((f.range.end - f.range.start) * LINE_H) + 'px';
    yamlMarks.appendChild(band);
  }

  function revealLine(line) {
    if (!LINE_H) measure();
    var top  = PAD_T + line * LINE_H;
    var view = yamlPane.clientHeight;
    if (top < yamlPane.scrollTop + LINE_H || top > yamlPane.scrollTop + view - LINE_H * 2) {
      // A third down rather than hard against the top, so the lines above it
      // are visible too. Never smooth — on every caret move it races itself.
      yamlPane.scrollTop = Math.max(0, top - view / 3);
    }
  }

  // Form field -> compose pane.
  function focusField(id, reveal) {
    activeField = id;
    var rows = formHost.querySelectorAll('.stackman-fieldrow');
    for (var i = 0; i < rows.length; i++) {
      rows[i].classList.toggle('stackman-fieldrow--active', rows[i].dataset.fieldRow === id);
    }
    if (reveal && MODEL) {
      var f = YAML.fieldById(MODEL, id);
      if (f && f.range) revealLine(f.range.start);
    }
    repaintMark();
    syncGutter();
  }

  formHost.addEventListener('focusin', function (event) {
    var row = event.target.closest('.stackman-fieldrow');
    if (row) focusField(row.dataset.fieldRow, true);
  });

  formHost.addEventListener('click', function (event) {
    // Not for a button. Remove has already torn its row out by the time this
    // runs, and highlighting a row that no longer exists leaves the band
    // pointing at nothing.
    if (event.target.closest('button')) return;
    var row = event.target.closest('.stackman-fieldrow');
    if (row) focusField(row.dataset.fieldRow, true);
  });

  // Compose pane -> form field.
  var caretRaf = null;

  function syncFromCaret() {
    caretRaf = null;
    if (!MODEL || !MODEL.doc) return;

    var line = YAML.lineAtOffset(MODEL.doc, yamlPane.selectionStart);
    var id   = YAML.fieldAtLine(MODEL, line);

    if (!id) {
      // Nothing owns this line. Settle on the service rather than flickering
      // the highlight off every time the caret crosses a blank line.
      activeField = null;
      yamlMarks.textContent = '';
      var svc = YAML.serviceAtLine(MODEL, line);
      var secs = formHost.querySelectorAll('.stackman-svc');
      for (var i = 0; i < secs.length; i++) {
        secs[i].classList.toggle('stackman-svc--active', secs[i].dataset.service === svc);
      }
      var rows = formHost.querySelectorAll('.stackman-fieldrow--active');
      for (var j = 0; j < rows.length; j++) rows[j].classList.remove('stackman-fieldrow--active');
      return;
    }

    focusField(id, false);
    var row = formHost.querySelector('[data-field-row="' + id.replace(/"/g, '\\"') + '"]');
    if (row) row.scrollIntoView({ block: 'nearest' });
  }

  function scheduleCaretSync() {
    if (caretRaf) return;
    caretRaf = window.requestAnimationFrame(syncFromCaret);
  }

  yamlPane.addEventListener('keyup', scheduleCaretSync);
  yamlPane.addEventListener('click', scheduleCaretSync);

  // Whichever pane has focus owns the text. Moving into the compose box first
  // commits anything the form was still holding.
  yamlPane.addEventListener('focus', flushPending);

  /* ---- Sanitise ---------------------------------------------------------- */

  /* A view mode for taking a screenshot, and nothing more. It hides the values
   * marked sensitive in both panes and locks the whole modal while it is on.
   *
   * The lock is the safety property, not a convenience. While this is on, the
   * compose box shows **REDACTED** in place of real values — so if anything
   * could still be saved in that state, that placeholder would be written into
   * someone's file. Locking removes the possibility rather than guarding
   * against it.
   *
   * Be honest about what it is: the real values are still in the page and
   * still copyable. This hides them from a screenshot, not from an attacker. */

  var sanitised = false;
  var realText  = '';

  function redact(text) {
    if (!MODEL) return text;

    var lines = text.split('\n');
    var seen = {}, byLine = {};

    MODEL.fields.forEach(function (f) {
      if (!f.sensitive) return;
      Object.keys(f.parts).forEach(function (k) {
        // A variable's NAME is not the secret — hiding ADMIN_TOKEN as well as
        // its value makes the screenshot unreadable for no gain.
        if (k === 'name') return;
        var s = f.parts[k].spot;
        if (!s) return;
        // Both halves of "8096:8097" share one scalar, so the same span would
        // otherwise be replaced twice.
        var key = s.line + ':' + s.col;
        if (seen[key]) return;
        seen[key] = true;
        (byLine[s.line] = byLine[s.line] || []).push(s);
      });
    });

    Object.keys(byLine).forEach(function (n) {
      // Right to left, so replacing one span cannot shift the column of the
      // next one along the same line.
      var spots = byLine[n].sort(function (a, b) { return b.col - a.col; });
      var line = lines[n];
      spots.forEach(function (s) {
        line = line.slice(0, s.col) + '**REDACTED**' + line.slice(s.col + s.len);
      });
      lines[n] = line;
    });

    return lines.join('\n');
  }

  function setSanitised(on) {
    if (on === sanitised) return;
    flushPending();
    sanitised = on;

    if (on) {
      realText = yamlPane.value;
      yamlPane.value = redact(realText);
    } else {
      yamlPane.value = realText;
      realText = '';
    }

    yamlPane.readOnly = on;
    modal.dataset.sanitised = on ? '1' : '0';
    sanitiseNote.hidden = !on;
    saveBtn.disabled  = on;
    startBtn.disabled = on || startBtnWasDisabled;
    if (!on) updateRequired();   // turning it off hands the decision back

    var controls = formHost.querySelectorAll('input, select, button');
    for (var i = 0; i < controls.length; i++) {
      if (on) {
        // Remember which were already dead, so turning it off does not switch
        // on a box that was never editable.
        controls[i].dataset.wasOff = controls[i].disabled ? '1' : '0';
        controls[i].disabled = true;
      } else if (controls[i].dataset.wasOff === '0') {
        controls[i].disabled = false;
      }
    }

    updateUndo();
    paintGutter();
    syncGutter();
    repaintMark();
  }

  sanitiseBox.addEventListener('change', function () { setSanitised(sanitiseBox.checked); });

  /* ---- the folder picker ------------------------------------------------- */

  /* Opened from the Browse button on a volume's host path. It lists folders
   * under /mnt and does nothing else — no create, no rename, no delete — and
   * the server refuses any path that resolves outside that root.
   *
   * The box it fills in stays an ordinary text field. Someone who needs a path
   * this will not show them can still type it, so this is a shortcut rather
   * than the only way in. */

  var PICKER_ROOT = '/mnt';
  var pickerFor   = null;      // the input being filled in
  var pickerAt    = PICKER_ROOT;
  var pickerBusy  = false;

  function pickerStart(value) {
    var v = String(value || '').trim();
    return (v === PICKER_ROOT || v.indexOf(PICKER_ROOT + '/') === 0) ? v : PICKER_ROOT;
  }

  function paintPicker(res) {
    var out = [];

    if (res.up) {
      out.push('<button type="button" class="stackman-pickrow stackman-pickrow--up" data-path="' +
               esc(res.up) + '"><i class="fa fa-level-up" aria-hidden="true"></i> ' +
               esc(res.up) + '</button>');
    }
    for (var i = 0; i < res.dirs.length; i++) {
      out.push('<button type="button" class="stackman-pickrow" data-path="' +
               esc(res.path + '/' + res.dirs[i]) + '">' +
               '<i class="fa fa-folder-o" aria-hidden="true"></i> ' +
               esc(res.dirs[i]) + '</button>');
    }

    pickerList.innerHTML = out.join('');
    pickerList.scrollTop = 0;
  }

  // `carry` is a message to show once the load succeeds, so that the reason a
  // typed path was refused survives the fall back to the root.
  function pickerLoad(path, carry) {
    if (pickerBusy) return;
    pickerBusy = true;
    if (!carry) pickerMsg.textContent = 'Reading ' + path + '…';

    call('browse', { path: path }, 20000).then(function (res) {
      pickerBusy = false;

      var why = res.ok ? (res.error || '') : res.error;
      if (why) {
        pickerMsg.textContent = why;
        // A path typed by hand may not exist yet. Say why, then land somewhere
        // usable rather than on an empty list with no way back.
        if (!carry && path !== PICKER_ROOT) {
          pickerLoad(PICKER_ROOT, why + '  Showing ' + PICKER_ROOT + ' instead.');
        }
        return;
      }

      pickerAt = res.path;
      pickerHere.textContent = res.path;
      pickerMsg.textContent = carry || (res.more
        ? 'Showing the first ' + res.dirs.length + ' folders — there are more in here than that.'
        : (res.dirs.length ? '' : 'There are no folders inside this one.'));
      paintPicker(res);
    });
  }

  // Makes one folder inside whichever one is on screen, then steps into it so
  // that "Use this folder" is the obvious next click.
  function pickerMake() {
    var name = pickerNew.value.trim();
    if (!name) { pickerNew.focus(); return; }
    if (pickerBusy) return;

    pickerBusy = true;
    pickerMsg.textContent = 'Creating ' + name + '…';

    call('browse-mkdir', { path: pickerAt, folderName: name }, 20000).then(function (res) {
      pickerBusy = false;
      var why = res.ok ? (res.error || '') : res.error;
      if (why) { pickerMsg.textContent = why; pickerNew.select(); return; }

      pickerNew.value = '';
      pickerLoad(res.path, 'Created ' + res.path + '.');
    });
  }

  function pickerOpen(input) {
    flushPending();              // whatever was typed in the box goes in first
    pickerFor = input;
    pickerAt  = PICKER_ROOT;
    pickerHere.textContent = PICKER_ROOT;
    pickerList.innerHTML = '';
    pickerMsg.textContent = '';
    pickerNew.value = '';
    picker.showModal();
    pickerLoad(pickerStart(input.value), '');
  }

  pickerList.addEventListener('click', function (event) {
    var row = event.target.closest('.stackman-pickrow');
    if (row) pickerLoad(row.dataset.path, '');
  });

  document.getElementById('stackman-picker-make').addEventListener('click', pickerMake);

  // There is no <form> in the dialog, so Enter does nothing on its own — and
  // without this it would reach the dialog and close it instead.
  pickerNew.addEventListener('keydown', function (event) {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    pickerMake();
  });

  document.getElementById('stackman-picker-use').addEventListener('click', function () {
    if (pickerFor) {
      pickerFor.value = pickerAt;
      // Assigning .value fires no input event, so nothing else would ever
      // notice this. Commit it here or the choice never reaches the file.
      commit(pickerFor);
      pickerFor.focus();
    }
    picker.close();
  });

  document.getElementById('stackman-picker-cancel').addEventListener('click', function () {
    picker.close();
  });

  // Same hit-test as the editor: <dialog> fires no backdrop event, because a
  // click on the backdrop targets the dialog itself.
  picker.addEventListener('click', function (event) {
    if (event.target !== picker) return;
    var r = picker.getBoundingClientRect();
    if (event.clientX < r.left || event.clientX > r.right ||
        event.clientY < r.top  || event.clientY > r.bottom) picker.close();
  });

  picker.addEventListener('close', function () { pickerFor = null; });

  /* ---- the timezone picker ----------------------------------------------- */

  /* A world map cut into hourly bands. The projection is equirectangular, so x
   * IS longitude — 15 degrees is one hour and a band is a plain rectangle.
   * That is why there is no projection maths anywhere below.
   *
   * Zones are placed by their STANDARD offset, not by what their clock says
   * today. Placed by today's offset, half the map would slide sideways twice a
   * year and Toronto would swap bands with Bogota each spring. What each zone
   * reads right now is shown beside its name instead, so nothing is hidden. */

  var TZ_FIRST = -11, TZ_LAST = 12;   // the bands that fit on a map of the world
  var tzZones  = null;                // asked for once, then kept
  var tzFor    = null;                // the input being filled in
  var tzBand   = null;

  function tzOffset(min) {
    var s = min < 0 ? '-' : '+';
    var a = Math.abs(min);
    return s + ('0' + Math.floor(a / 60)).slice(-2) + ':' + ('0' + (a % 60)).slice(-2);
  }

  function tzBandOf(zone) {
    return Math.floor(zone.std / 60);
  }

  function tzInBand(h) {
    return tzZones.filter(function (z) { return tzBandOf(z) === h; });
  }

  // 24 rects, written by a loop. Bands are centred on their meridian, so the
  // +12 one runs off the right edge of the map and is clipped to half width.
  function tzPaintBands() {
    if (tzBands.childNodes.length) return;
    var ns = 'http://www.w3.org/2000/svg', frag = document.createDocumentFragment();

    for (var h = TZ_FIRST; h <= TZ_LAST; h++) {
      var x0 = Math.max(-180, h * 15 - 7.5);
      var x1 = Math.min(180,  h * 15 + 7.5);

      var r = document.createElementNS(ns, 'rect');
      r.setAttribute('class', 'stackman-tz-band');
      r.setAttribute('x', x0);
      r.setAttribute('y', -85);
      r.setAttribute('width', x1 - x0);
      r.setAttribute('height', 145);
      r.setAttribute('data-offset', h);
      frag.appendChild(r);

      // A three-character label needs about 11 of the 15 units a full band has.
      // The clipped +12 band has 7.5, so its label would print over +11's —
      // leave it off. The band is still clickable and the caption names it.
      if (x1 - x0 >= 15) {
        var t = document.createElementNS(ns, 'text');
        t.setAttribute('class', 'stackman-tz-banglabel');
        t.setAttribute('x', (x0 + x1) / 2);
        t.setAttribute('y', -77);
        t.setAttribute('text-anchor', 'middle');
        t.textContent = h > 0 ? '+' + h : String(h);
        frag.appendChild(t);
      }
    }
    tzBands.appendChild(frag);
  }

  function tzRowHtml(z, chosen) {
    return '<button type="button" class="stackman-tzrow' +
             (z.name === chosen ? ' stackman-tzrow--on' : '') +
           '" data-zone="' + esc(z.name) + '">' +
             '<span class="stackman-tzcity">' + esc(z.city) + '</span>' +
             '<span class="stackman-tzname">' + esc(z.name) + '</span>' +
             '<span class="stackman-tzoff">now ' + tzOffset(z.now) +
               (z.abbr ? ' · ' + esc(z.abbr) : '') + '</span>' +
           '</button>';
  }

  function tzPaintList(list, why) {
    var chosen = tzFor ? String(tzFor.value).trim() : '';
    tzList.innerHTML = list.length
      ? list.map(function (z) { return tzRowHtml(z, chosen); }).join('')
      : '<p class="stackman-form-empty">Nothing matches that. Try part of a city name.</p>';
    tzList.scrollTop = 0;
    tzMsg.textContent = why || '';

    var on = tzList.querySelector('.stackman-tzrow--on');
    if (on) on.scrollIntoView({ block: 'center' });
  }

  function tzShowBand(h) {
    tzBand = h;
    var rects = tzBands.querySelectorAll('.stackman-tz-band');
    for (var i = 0; i < rects.length; i++) {
      rects[i].classList.toggle('stackman-tz-band--on', (rects[i].dataset.offset | 0) === h);
    }
    var chips = tzChips.querySelectorAll('.stackman-tzchip');
    for (var j = 0; j < chips.length; j++) {
      chips[j].classList.toggle('stackman-tzchip--on', (chips[j].dataset.offset | 0) === h);
    }

    var zones = tzInBand(h);
    tzCaption.textContent = 'UTC' + (h > 0 ? '+' + h : h) + ' — ' +
      zones.length + (zones.length === 1 ? ' place' : ' places');
    tzPaintList(zones, '');
  }

  // Everything past +12 sits west of the date line while keeping an eastern
  // offset, so it has no honest place on a map laid out by longitude.
  function tzPaintChips() {
    var out = [];
    tzZones.forEach(function (z) {
      var h = tzBandOf(z);
      if (h <= TZ_LAST || out.indexOf(h) >= 0) return;
      out.push(h);
    });
    out.sort(function (a, b) { return a - b; });

    tzChips.innerHTML = out.length
      ? '<span class="stackman-tzchips-lead">Past the date line:</span>' +
        out.map(function (h) {
          return '<button type="button" class="stackman-tzchip" data-offset="' + h + '">' +
                 'UTC+' + h + '</button>';
        }).join('')
      : '';
  }

  function tzChoose(name) {
    if (tzFor) {
      tzFor.value = name;
      commit(tzFor);            // assigning .value fires no input event
      tzFor.focus();
    }
    tzModal.close();
  }

  function tzReady() {
    tzPaintBands();
    tzPaintChips();

    var current = tzFor ? String(tzFor.value).trim() : '';
    var known = tzZones.filter(function (z) { return z.name === current; })[0];

    if (known) {
      tzShowBand(tzBandOf(known));
      tzMsg.textContent = 'Currently set to ' + known.name + '.';
    } else {
      tzPaintList(tzZones, current
        ? '"' + current + '" is not a zone name this server knows. Pick one below.'
        : '');
      tzCaption.textContent = 'Click a band, or search.';
    }
  }

  function tzOpen(input) {
    flushPending();
    tzFor = input;
    tzBand = null;
    tzSearch.value = '';
    tzModal.showModal();

    if (tzZones) { tzReady(); return; }

    tzList.innerHTML = '';
    tzMsg.textContent = 'Reading the timezones this server knows…';
    call('timezones', {}, 20000).then(function (res) {
      if (!res.ok) { tzMsg.textContent = res.error; return; }
      tzZones = res.zones || [];
      tzReady();
    });
  }

  tzBands.addEventListener('click', function (event) {
    var r = event.target.closest('.stackman-tz-band');
    if (r) tzShowBand(r.dataset.offset | 0);
  });

  tzBands.addEventListener('mousemove', function (event) {
    var r = event.target.closest('.stackman-tz-band');
    if (!r || !tzZones) return;
    var h = r.dataset.offset | 0;
    if (h === tzBand) return;
    var n = tzInBand(h).length;
    tzCaption.textContent = 'UTC' + (h > 0 ? '+' + h : h) + ' — ' +
      n + (n === 1 ? ' place' : ' places');
  });

  tzChips.addEventListener('click', function (event) {
    var c = event.target.closest('.stackman-tzchip');
    if (c) tzShowBand(c.dataset.offset | 0);
  });

  tzList.addEventListener('click', function (event) {
    var row = event.target.closest('.stackman-tzrow');
    if (row) tzChoose(row.dataset.zone);
  });

  // Searching looks at every zone, not just the chosen band. Typing "tokyo"
  // beats hunting for the right slice, and one band alone holds 49 places.
  tzSearch.addEventListener('input', function () {
    if (!tzZones) return;
    var q = tzSearch.value.trim().toLowerCase();

    if (!q) {
      if (tzBand === null) tzPaintList(tzZones, '');
      else tzShowBand(tzBand);
      return;
    }

    // A search spans the whole world, so no one band is the answer any more.
    tzBand = null;
    var rects = tzBands.querySelectorAll('.stackman-tz-band--on');
    for (var i = 0; i < rects.length; i++) rects[i].classList.remove('stackman-tz-band--on');

    var hits = tzZones.filter(function (z) {
      return (z.name + ' ' + z.city).toLowerCase().indexOf(q) >= 0;
    });
    tzCaption.textContent = hits.length + (hits.length === 1 ? ' match' : ' matches') +
                            ' anywhere in the world';
    tzPaintList(hits, '');
  });

  document.getElementById('stackman-tz-cancel').addEventListener('click', function () {
    tzModal.close();
  });

  tzModal.addEventListener('click', function (event) {
    if (event.target !== tzModal) return;
    var r = tzModal.getBoundingClientRect();
    if (event.clientX < r.left || event.clientX > r.right ||
        event.clientY < r.top  || event.clientY > r.bottom) tzModal.close();
  });

  tzModal.addEventListener('close', function () { tzFor = null; });

  /* ---- the device picker ---- */

  /* What hardware this server has, offered by name.
   *
   * A panel in the flow of the form, not a floating menu. The editor is a
   * <dialog> with overflow: hidden, so an absolutely positioned box inside it is
   * either clipped or stretches the dialog — which is exactly what the
   * visually-hidden labels did before they were pinned down. A block that pushes
   * the rows below it out of the way can do neither.
   *
   * The catalogue itself is held in devIndex up beside the renderer, because the
   * form needs it to name rows whether or not this panel has ever been opened. */

  var devPanel   = null;    // the open panel, or null — cleared by reparse() above
  var devFor     = null;    // the host box being re-picked, or null when adding
  var devSvc     = '';      // the service a new device is being added to
  var devShowAll = false;   // are the risky entries showing
  var devFilter  = '';

  function devLoad() {
    return call('devices', {}, 15000).then(function (res) {
      if (!res.ok) return res;

      devGroups = res.groups || [];
      devClaims = res.claims || {};

      devIndex = {};
      for (var g = 0; g < devGroups.length; g++) {
        var list = devGroups[g].devices || [];
        for (var i = 0; i < list.length; i++) devIndex[list[i].host] = list[i];
      }

      devPresent = {};
      var here = res.present || [];
      for (var p = 0; p < here.length; p++) devPresent[here[p]] = true;

      // The form was drawn before this arrived, so its device rows are still
      // showing bare paths. Redraw once to put the hardware names in. Only on
      // the first reply, and only with nothing in flight — a redraw takes the
      // caret with it, and it would also destroy the panel it was opened from.
      var first = !devLoaded;
      devLoaded = true;
      if (first && modal.open && MODEL && !commitTimer && !devPanel) reparse();

      return res;
    });
  }

  // This server's own docker networks — bridge/host/none are offered already,
  // so only names beyond those (a macvlan, a user-defined bridge) are worth
  // adding. Modelled on devLoad() just above: same first-reply-only redraw,
  // guarded the same way, because opening the editor is exactly what this
  // list is stale for otherwise.
  function netLoad() {
    return call('networks', {}, 15000).then(function (res) {
      if (!res.ok) return res;

      var options = CHOICES['setting/network_mode'].options;
      var known = {};
      for (var i = 0; i < options.length; i++) known[options[i][0]] = true;

      var nets = res.networks || [];
      for (var n = 0; n < nets.length; n++) {
        var name = nets[n].name, driver = nets[n].driver;
        if (!name || known[name]) continue;
        options.push([name, driver ? name + ' — ' + driver + ' network on this server' : name]);
        known[name] = true;
      }

      var first = !netLoaded;
      netLoaded = true;
      if (first && modal.open && MODEL && !commitTimer && !devPanel) reparse();

      return res;
    });
  }

  function devShellHtml() {
    return '<div class="stackman-devhead">' +
             '<input type="text" class="stackman-input stackman-devfilter"' +
                   ' placeholder="Filter devices" spellcheck="false" autocomplete="off"' +
                   ' aria-label="Filter the device list">' +
             '<button type="button" class="stackman-browse stackman-devrefresh"' +
                   ' title="Look again — for something just plugged in">' +
               '<i class="fa fa-refresh" aria-hidden="true"></i>' +
               '<span class="stackman-sr">Look again</span>' +
             '</button>' +
             '<button type="button" class="stackman-browse stackman-devcancel" title="Close">' +
               '<i class="fa fa-times" aria-hidden="true"></i>' +
               '<span class="stackman-sr">Close the device list</span>' +
             '</button>' +
           '</div>' +
           '<div class="stackman-devbody"></div>' +
           '<div class="stackman-devfoot">' +
             '<button type="button" class="stackman-devall"></button>' +
             '<span class="stackman-devmsg" role="status"></span>' +
           '</div>';
  }

  function devRowHtml(d) {
    var claim = devClaims[d.host] || [];
    var also  = (d.companions || []).map(function (c) { return c.key + ': ' + c.value; });

    return '<button type="button" class="stackman-devrow' +
             (d.risky ? ' stackman-devrow--risky' : '') + '"' +
             ' data-dev="' + esc(d.host) + '">' +
             '<span class="stackman-devname">' + esc(d.label) +
               // Two containers holding one Zigbee stick is a setup that looks
               // fine and never works. Said, not blocked: sharing /dev/dri
               // between two transcoders is perfectly reasonable.
               (claim.length
                 ? '<span class="stackman-devclaim">in use by ' + esc(claim.join(', ')) + '</span>'
                 : '') +
             '</span>' +
             '<span class="stackman-devpath">' + esc(d.host) +
               (d.container !== d.host ? ' → ' + esc(d.container) : '') + '</span>' +
             (d.hint ? '<span class="stackman-devhint">' + esc(d.hint) + '</span>' : '') +
             (also.length
               ? '<span class="stackman-devhint stackman-devhint--also">Also needs ' +
                 esc(also.join(' and ')) + ', which you can add in the Compose view.</span>'
               : '') +
           '</button>';
  }

  function devPaint() {
    if (!devPanel) return;

    var q = devFilter.toLowerCase();
    var bits = [], shown = 0, held = 0;

    for (var g = 0; g < devGroups.length; g++) {
      var grp  = devGroups[g];
      var list = grp.devices || [];
      var rows = [];

      for (var i = 0; i < list.length; i++) {
        var d = list[i];
        if (d.risky && !devShowAll) { held++; continue; }
        if (q && (d.label + ' ' + d.host + ' ' + d.container).toLowerCase().indexOf(q) < 0) continue;
        rows.push(devRowHtml(d));
      }
      shown += rows.length;

      // A group whose every entry was filtered out has nothing worth a heading.
      // One with no entries at all but something to say — the Nvidia note — is
      // why this is not simply "if (rows.length)".
      if (!rows.length && list.length) continue;

      bits.push('<h5 class="stackman-devgroup">' + esc(grp.title) + '</h5>');
      if (grp.note) bits.push('<p class="stackman-devnote">' + esc(grp.note) + '</p>');
      bits.push(rows.join(''));
    }

    if (!shown) {
      bits.push('<p class="stackman-devnote">' + esc(
        !devLoaded  ? 'Asking the server what it has…'
        : devFilter ? 'Nothing matches "' + devFilter + '".'
        : 'This server reports no devices that can be handed to a container.'
      ) + '</p>');
    }

    // Only the body is rewritten, never the whole panel — the filter box is in
    // the header, and replacing it while it is being typed in would take the
    // caret with it on every keystroke.
    devPanel.querySelector('.stackman-devbody').innerHTML = bits.join('');

    var all = devPanel.querySelector('.stackman-devall');
    all.hidden = !held && !devShowAll;
    all.textContent = devShowAll
      ? 'Hide disks and the USB bus'
      : 'Show everything in /dev (' + held + ' more)';
  }

  function devMsg(text) {
    if (devPanel) devPanel.querySelector('.stackman-devmsg').textContent = text || '';
  }

  function devClose() {
    if (devPanel && devPanel.parentNode) devPanel.parentNode.removeChild(devPanel);
    devPanel  = null;
    devFor    = null;
    devSvc    = '';
    devFilter = '';
  }

  function devOpen(anchor, box, service) {
    if (!anchor || sanitised || !MODEL) return;

    // An edit still on its debounce timer would be lost the moment a pick
    // redraws the form. The same reason the other two pickers do this first.
    flushPending();
    devClose();

    devFor = box || null;
    devSvc = service || '';

    devPanel = document.createElement('div');
    devPanel.className = 'stackman-devpick';
    devPanel.innerHTML = devShellHtml();
    anchor.after(devPanel);

    devPaint();
    devPanel.scrollIntoView({ block: 'nearest' });
    devPanel.querySelector('.stackman-devfilter').focus();

    // Asked again on every open. Someone opening this has often just plugged
    // something in, which is the one moment a list from earlier is wrong.
    devRefresh();
  }

  function devRefresh() {
    return devLoad().then(function (res) {
      devMsg(res.ok ? '' : (res.error || 'The server did not say what hardware it has.'));
      devPaint();
    }, function () {
      devMsg('Could not reach the server to ask what hardware it has.');
    });
  }

  /* The hardware's name, written as the comment beside the entry.
   *
   * A /dev/serial/by-id path is specific to this machine — still valid compose
   * that runs anywhere, but meaningless to anyone reading the file on another
   * server. The comment is what carries the answer across, and a comment beside
   * a setting is already where this project keeps what that setting is for.
   *
   * Only ever written into an empty note, never over the user's own words. */
  function devNameLine(line, label) {
    var fresh = YAML.buildForm(MODEL.doc);
    var id    = YAML.fieldAtLine(fresh, line);
    if (!id) return;

    for (var i = 0; i < fresh.fields.length; i++) {
      if (fresh.fields[i].id !== id) continue;
      if (!fresh.fields[i].note) YAML.setComment(MODEL.doc, fresh, id, label, false, false);
      return;
    }
  }

  function devPick(host) {
    var d = devIndex[host];
    if (!d || !MODEL) return;

    if (devFor) {
      // The host half only. The container path is the row's identity, and it may
      // have been set deliberately — rewriting it would move the row.
      var box = devFor;
      box.value = d.host;
      devClose();
      commit(box);                 // assigning .value fires no input event
      box.focus();
      return;
    }

    // The duplicate check belongs here, not in the model: this is the only place
    // that knows the device was already mapped and can say so. Two rows keyed on
    // one container path would also share a single id.
    for (var i = 0; i < MODEL.fields.length; i++) {
      var f = MODEL.fields[i];
      if (f.binder === 'device' && f.service === devSvc && f.target === d.container) {
        devMsg('This service already has a device at ' + d.container + '.');
        return;
      }
    }

    pushUndo('adding that device');
    var line = YAML.addItem(MODEL.doc, MODEL, devSvc, 'device', d.host + ':' + d.container);
    if (line < 0) {
      undoStack.pop();
      updateUndo();
      devMsg('That list is written in a way the form cannot add to — ' +
             'add it in the Compose view instead.');
      return;
    }

    devNameLine(line, d.label);
    devClose();
    structuralEdit(line, '');
  }

  formHost.addEventListener('click', function (event) {
    if (devPanel && devPanel.contains(event.target)) {
      var row = event.target.closest('[data-dev]');
      if (row)                                          { devPick(row.dataset.dev); return; }
      if (event.target.closest('.stackman-devcancel'))  { devClose(); return; }
      if (event.target.closest('.stackman-devrefresh')) { devMsg('Looking again…');
                                                          devRefresh(); return; }
      if (event.target.closest('.stackman-devall'))     { devShowAll = !devShowAll; devPaint(); }
      return;
    }

    var back = event.target.closest('[data-vol-switch]');
    if (back) { if (!sanitised) swapVolumeToChoice(back); return; }

    var btn = event.target.closest('[data-tool]');
    if (!btn || sanitised) return;
    var box = btn.closest('.stackman-boxline').querySelector('.stackman-input');
    if (!box) return;

    if (btn.dataset.tool === 'tz')          tzOpen(box);
    else if (btn.dataset.tool === 'device') devOpen(btn.closest('.stackman-fieldrow'), box, '');
    else                                    pickerOpen(box);
  });

  formHost.addEventListener('input', function (event) {
    if (!devPanel || !event.target.classList.contains('stackman-devfilter')) return;
    devFilter = event.target.value.trim();
    devPaint();
  });

  formHost.addEventListener('keydown', function (event) {
    if (!devPanel || !devPanel.contains(event.target)) return;

    // Escape closes the panel, not the editor behind it.
    if (event.key === 'Escape') {
      event.preventDefault();
      event.stopPropagation();
      devClose();
      return;
    }
    // There is no <form> in here, so Enter would otherwise do nothing. Take the
    // first device on the list, which is what filtering down to one is for.
    if (event.key !== 'Enter') return;
    event.preventDefault();
    var first = devPanel.querySelector('[data-dev]');
    if (first) devPick(first.dataset.dev);
  });

  function openEditor(name, body, isNew) {
    closeMenu();
    clearError();

    modal.dataset.new = isNew ? '1' : '0';
    modalTitle.textContent = isNew ? 'New stack' : 'Edit stack';
    openedName = name || '';
    serviceRenamed = false;

    // A stack's identity is its path under the stack root — "jellyfin" at the
    // top level, "Media/jellyfin" inside a folder. The box shows only the last
    // part, with the folder beside it as context, because those are two
    // different things and putting them in one box is what made "Folder" in
    // this header mean something different from "folder" in the list.
    //
    // Editable even for an existing stack: renaming it is a directory move, so
    // save() writes the compose file to the OLD path first and only then asks
    // the server to rename it — stopping and starting the containers around
    // that move if the stack is running. Use "Move to folder" to file it
    // instead — that moves the directory above this name, which compose does
    // not care about.
    var at = (name || '').lastIndexOf('/');
    modal.dataset.folder = at < 0 ? '' : name.slice(0, at);
    nameFolder.textContent = at < 0 ? '' : name.slice(0, at) + ' /';
    nameFolder.hidden = at < 0;

    nameInput.value = at < 0 ? (name || '') : name.slice(at + 1);
    nameInput.readOnly = false;
    nameField.hidden = false;

    // Always off on open. Coming back to blurred fields a week later and
    // wondering why is worse than one extra click before a screenshot.
    sanitised = false;
    realText = '';
    sanitiseBox.checked = false;
    sanitiseNote.hidden = true;
    modal.dataset.sanitised = '0';
    yamlPane.readOnly = false;

    // A new stack starts with one service rather than an empty box. The Add
    // buttons hang off a service, so with nothing in the file there would be
    // nothing to add to — the same trap as a list losing its last entry, one
    // level up.
    textAtOpen = body || (isNew ? NEW_STACK : '');
    yamlPane.value = textAtOpen;
    setView(defaultView());

    undoStack.length = 0;
    updateUndo();

    lockScroll(true);
    modal.showModal();

    // After showModal(), not before. A closed dialog is display: none, so the
    // gutter measures zero wide and every band would be positioned against a
    // line height of nothing.
    gutterLines = -1;
    activeField = null;
    LINE_H = 0;
    measure();
    paintGutter();
    syncGutter();
    reparse();

    // Ask what hardware this server has, so device rows can be named after it
    // rather than showing a bare path. Not waited for — the form is usable at
    // once and devLoad() redraws it when the names arrive. netLoad() does the
    // same for this server's own docker networks, feeding the network_mode
    // dropdown.
    devLoad().catch(function () {});
    netLoad().catch(function () {});

    // Explicit, and after showModal(). The dialog's own "first focusable
    // descendant" rule would land on the view selector, which is nobody's
    // starting point.
    (isNew ? nameInput : yamlPane).focus({ preventScroll: true });
  }

  function closeEditor() {
    if (modal.open) modal.close();
  }

  // Returns false when the user backed out, so callers can abandon whatever
  // they were about to do.
  function confirmDiscard() {
    if (!isDirty()) return true;
    return window.confirm(
      'Close without saving?\n\n' +
      'Your changes to "' + (nameInput.value || 'this stack') + '" will be lost.');
  }

  modal.addEventListener('cancel', function (event) {
    // Escape inside the compose box means "get me out of this box", not "throw
    // my work away". The first press leaves the textarea; a second one closes.
    // This doubles as the escape hatch from the Tab key being captured below.
    if (document.activeElement === yamlPane) {
      event.preventDefault();
      // Whichever button is pressed right now. Naming one outright would pick a
      // button that is not there — Split is hidden on a narrow screen.
      modal.querySelector('.stackman-viewbtn[aria-pressed="true"]').focus();
      return;
    }
    if (!confirmDiscard()) event.preventDefault();
  });

  modal.addEventListener('close', function () {
    lockScroll(false);
    clearError();
    // Nothing the user can do closes the editor from under the picker, but
    // closeEditor() can be called from code. A picker left open over a closed
    // editor would be pointing at an input that no longer exists.
    if (picker.open) picker.close();
    if (tzModal.open) tzModal.close();
    devClose();
  });

  // <dialog> fires no event for the backdrop, because the backdrop is a
  // pseudo-element of the dialog itself and a click on it targets the dialog.
  // Tell them apart by hit-testing the click against the dialog's own box.
  modal.addEventListener('click', function (event) {
    if (event.target !== modal) return;
    var r = modal.getBoundingClientRect();
    var inside = event.clientX >= r.left && event.clientX <= r.right &&
                 event.clientY >= r.top  && event.clientY <= r.bottom;
    if (!inside && confirmDiscard()) modal.close();
  });

  modal.addEventListener('click', function (event) {
    var btn = event.target.closest('.stackman-viewbtn');
    if (btn) setView(btn.dataset.view);
  });

  // Tab indents rather than leaving the box, which is what anyone editing YAML
  // expects. execCommand is deprecated everywhere and removed nowhere, and it
  // is the only way to insert text while keeping the browser's own undo stack;
  // assigning .value wipes it, and an editor that forgets Ctrl+Z is worse than
  // one with no Tab key. Escape is the way out — see the cancel handler above.
  yamlPane.addEventListener('keydown', function (event) {
    if (event.key !== 'Tab' || event.shiftKey) return;
    event.preventDefault();
    if (!document.execCommand('insertText', false, '  ')) {
      var at = yamlPane.selectionStart;
      yamlPane.setRangeText('  ', at, yamlPane.selectionEnd, 'end');
    }
  });

  // Renaming a service leaves its old container orphaned once the stack is
  // next up — so if this session renamed one, and the stack is (or was)
  // running, offer the same whole-stack recreate the row menu's own Start
  // uses. A stopped stack has no containers to orphan, so nothing to offer.
  function offerRecreate(name) {
    if (!CAN_RUN) return;
    if (!window.confirm(
          'Renaming a service leaves its old container behind until the stack ' +
          'is recreated.\n\nRecreate "' + stackLabel(name) + '" now?')) {
      return;
    }
    run(name, 'up', afterRun('up'));
  }

  // The common ending once the compose file is on disk and no directory move
  // is needed — a brand new stack, or an existing one whose leaf did not
  // change.
  function finishSave(name, thenStart) {
    saveBtn.disabled = false;
    startBtn.disabled = startBtnWasDisabled;

    var offer = serviceRenamed && stackIsRunning(name);
    serviceRenamed = false;

    // The row has to exist before the start can report back into it, so
    // the table is refreshed first and the command issued from there.
    closeEditor();
    refreshRows(function () {
      if (thenStart) run(name, 'up', afterRun('up'));
      else if (offer) offerRecreate(name);
    });
  }

  // The leaf changed: a rename is a directory move, sequenced by the page
  // rather than any new shell command. A running stack is stopped first and
  // started again under the new name — the down and the up are the very
  // same job machinery Start/Stop already use, just issued back to back.
  function renameThenFinish(oldName, newLeaf, thenStart) {
    var label   = stackLabel(oldName);
    var running = stackIsRunning(oldName);

    function reenable() {
      saveBtn.disabled = false;
      startBtn.disabled = startBtnWasDisabled;
    }

    function applyRename() {
      call('stack-rename', { name: oldName, stackName: newLeaf }).then(function (r) {
        if (!r.ok) {
          reenable();
          showError((r.error || 'Could not rename the stack.') +
                    ' The compose file was already saved, so nothing is lost.');
          return;
        }

        // Either the fresh 'up' below recreates every container from
        // scratch, or the stack is stopped and has none to orphan — either
        // way there is nothing left for offerRecreate() to offer.
        serviceRenamed = false;

        closeEditor();
        refreshRows(function () {
          if (thenStart || running) run(r.name, 'up', afterRun('up'));
        });
      });
    }

    if (!running) { applyRename(); return; }

    if (!window.confirm(
          label + ' is running. Renaming it will stop the containers and ' +
          'start them again under the new name.')) {
      reenable();
      return;
    }

    run(oldName, 'down', function (job) {
      // run() has already shown a failure box for a non-zero exit, so this
      // just stops the sequence rather than saying the same thing twice.
      if (job.exit !== 0 && job.exit !== null) { reenable(); return; }
      applyRename();
    });
  }

  function save(thenStart) {
    var leaf  = nameInput.value.trim();
    var isNew = modal.dataset.new === '1';

    // An existing stack's compose file is written to the path the editor
    // opened at; if the leaf changed too, the rename happens as its own step
    // afterwards, once the content is safe on disk. A new stack has no old
    // path — the leaf just typed is where it is created.
    var name = isNew
      ? (modal.dataset.folder ? modal.dataset.folder + '/' + leaf : leaf)
      : openedName;
    var body = currentText();

    if (!leaf) { showError('Give the stack a name.'); nameInput.focus(); return; }

    clearError();
    saveBtn.disabled = true;
    startBtn.disabled = true;

    call('save', { name: name, body: body, 'new': modal.dataset.new })
      .then(function (res) {
        if (!res.ok) {
          saveBtn.disabled = false;
          startBtn.disabled = startBtnWasDisabled;
          showError((res.error || 'Save failed.') + strayWarning(res));
          return;
        }

        // Trust nothing: the server reports the path and size it actually
        // wrote, and an empty file means the save silently did nothing.
        if (!res.file || !res.bytes) {
          saveBtn.disabled = false;
          startBtn.disabled = startBtnWasDisabled;
          showError('The server reported success but no file was written.\n\n' +
                    'file: ' + (res.file || '(none)') + '\n' +
                    'bytes: ' + (res.bytes || 0) + strayWarning(res));
          return;
        }

        // What is on screen is now what is on disk, so closing must not ask
        // about discarding it.
        textAtOpen = body;

        var oldLeaf = openedName.slice(openedName.lastIndexOf('/') + 1);
        if (isNew || leaf === oldLeaf) { finishSave(name, thenStart); return; }

        renameThenFinish(name, leaf, thenStart);
      });
  }

  /* ---------------------------------------------------------------- run -- */

  /* Running a command shows a spinner on the row, not a panel.
   *
   * A box of command output appearing under the table for every start and stop
   * is a lot of furniture for something you already know the answer to, and it
   * pushes the list around while you are looking at it. Unraid's own Docker
   * page spins the app icon instead, and this does the same.
   *
   * The output is still collected and still followed — it is just not shown
   * unless it turns out to be worth reading:
   *
   *   Logs, Resolved settings   the output IS the point, so the panel opens.
   *   anything that fails       the panel opens with what compose said. An
   *                             error you cannot see is worse than a box.
   *   everything else           spinner, then the row updates itself.
   */

  var BUSY_LABEL = {
    up:      'Starting…',
    down:    'Stopping…',
    restart: 'Restarting…',
    pull:    'Updating…',
    remove:  'Removing…',
    // Same word as `pull` on purpose: to whoever is watching the spinner,
    // pull-then-up-d IS the update, not two things happening.
    update:  'Updating…'
  };

  // Verbs whose whole purpose is to show you something.
  function wantsOutput(verb) {
    return verb === 'logs' || verb === 'config';
  }

  // Show a spinner OVER the row's icon, and take it away again.
  //
  // This used to swap the <i> element's class for fa-refresh and swap it back
  // afterwards, which only works while every icon is a font glyph. A row whose
  // icon is a real picture has no <i> to swap and nothing to put back, so the
  // spinner is now its own element sitting on top — which is also what Unraid's
  // own Docker page does, and it leaves the icon visible while the command runs
  // instead of hiding the one thing that says which row is working.
  function spin(row, on) {
    row.classList.toggle('stackman-busy', !!on);
  }

  // A stack row and the container rows underneath it move together: a command
  // is issued to the whole stack, so the whole stack shows it is working.
  function stackRows(name) {
    var out = [];
    var row = rowFor(name);
    if (row) out.push(row);
    Array.prototype.forEach.call(
      document.querySelectorAll('.stackman-container-row[data-in-stack="' + name + '"]'),
      function (r) { out.push(r); }
    );
    return out;
  }

  // The container rows of one stack whose menu targets this service —
  // plural, deliberately: a replicated service has one row per container,
  // each carrying a menu button with the same compose service name, and a
  // command aimed at that service reaches every one of them (compose itself
  // works that way; see the data-service comment on the button in
  // StacksTable.php). This is NOT a `[data-service="..."]` selector on the
  // ROW itself — the row's own data-service is $kid['key'], which becomes
  // "service/container-name" for a replica specifically so rows do not
  // collide, so matching it against a bare service name would only ever hit
  // the first replica and miss the rest.
  function containerRows(stack, service) {
    var out = [];
    Array.prototype.forEach.call(
      document.querySelectorAll(
        '.stackman-container-row[data-in-stack="' + stack + '"] ' +
        '[data-menu="container"][data-service="' + service + '"]'
      ),
      function (btn) {
        var row = btn.closest('.stackman-container-row');
        if (row) out.push(row);
      }
    );
    return out;
  }

  function setBusy(rows, label) {
    rows.forEach(function (row) {
      // Marked on the row so a state refresh arriving mid-command does not
      // paint the old state over the top of "Starting…".
      row.dataset.busy = '1';
      spin(row, true);
      var td = row.querySelector('[data-cell="state"]');
      if (td) td.innerHTML = '<span class="stackman-pill stackman-pill--busy">' + label + '</span>';
    });
  }

  function clearBusy(rows) {
    rows.forEach(function (row) {
      delete row.dataset.busy;
      spin(row, false);
    });
  }

  function run(name, verb, done, service) {
    var show = wantsOutput(verb);

    // A container-scoped command spins only its own row(s), not the whole
    // stack — spinning every sibling container for a command that never
    // touched them would be a lie on screen. The stack row's own pill is
    // left alone here on purpose; the refreshStateSoon() that afterRun()
    // chains on afterwards is what corrects it, which matters because
    // stopping the last running container does change the stack's own state.
    var rows = show ? [] : (service ? containerRows(name, service) : stackRows(name));

    if (rows.length) setBusy(rows, BUSY_LABEL[verb] || 'Working…');

    // `fields` gains `service` only when one was given, so the 3-argument
    // calls elsewhere in this file — there are many — post exactly what they
    // always have.
    var fields = { name: name, verb: verb };
    if (service) fields.service = service;

    call('run', fields).then(function (res) {
      if (!res.ok) {
        clearBusy(rows);
        failed('Could not start', res.error || 'Could not start the command.');
        return;
      }

      if (show) {
        logPanel.hidden = false;
        logTitle.textContent = res.title || 'Output';
        logBox.textContent = 'Working…';
        logPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }

      follow(res.job, function (job) {
        clearBusy(rows);

        // Silent while it works, loud when it breaks.
        if (!show && job.exit !== 0 && job.exit !== null) {
          failed((res.title || 'Command') + ' — failed (exit ' + job.exit + ')',
                 job.text || '(no output)');
        }
        if (done) done(job);
      }, show);
    });
  }

  // Compose commands are slow — pulling an image can take minutes — so the
  // server detaches the command and its output is collected as it accumulates.
  // `show` decides whether any of that reaches the screen while it runs; the
  // polling happens either way, because the finish is what the row waits for.
  function follow(job, done, show) {
    if (poller) { clearInterval(poller); poller = null; }

    var atBottom = true;
    if (show) {
      logBox.onscroll = function () {
        atBottom = logBox.scrollHeight - logBox.scrollTop - logBox.clientHeight < 30;
      };
    }

    var tick = function () {
      call('job', { job: job }).then(function (res) {
        if (!res.ok) return;

        if (show) {
          logBox.textContent = res.text || 'Working…';
          if (atBottom) logBox.scrollTop = logBox.scrollHeight;
        }

        if (res.done) {
          clearInterval(poller);
          poller = null;
          if (show) {
            logTitle.textContent += (res.exit !== 0 && res.exit !== null)
              ? ' — failed (exit ' + res.exit + ')'
              : ' — done';
          }
          if (done) done(res);
        }
      });
    };

    poller = setInterval(tick, 1000);
    tick();
  }

  /* ------------------------------------------------------------ refresh -- */

  /* Nothing here reloads the page.
   *
   * A reload is the simplest way to show a new state and it was what this did
   * at first, but it costs more than it looks. The graphs lose their history
   * and start again from a flat line, the page jumps back to the top, the
   * command output panel closes just as you were reading it, and the server
   * re-reads every compose file — several seconds on a machine with a lot of
   * stacks, during which the page is blank.
   *
   * Two refreshes replace it, and the cheap one covers the common case:
   *
   *   refreshState()  after start, stop and restart. One `compose ls` for the
   *                   whole machine. Starting a stack cannot change its
   *                   services or whether its file parses, so nothing else is
   *                   worth re-reading — only the State cell, the green dot,
   *                   and what the menu offers next.
   *
   *   refreshRows()   only when the set of rows changes: a stack added or
   *                   deleted, a folder created, renamed or moved through. The
   *                   server renders the table body and it is swapped in, so
   *                   there is still exactly one copy of that markup.
   */

  var rowsHost   = document.getElementById('stackman-rows');
  var stateBusy  = false;
  var stateAgain = false;

  // Stack names and folder ids are both restricted to letters, numbers, dots,
  // dashes and underscores before anything is ever created, so there is nothing
  // in either that needs escaping inside a quoted attribute selector.
  function rowFor(name) {
    return rowsHost
      ? rowsHost.querySelector('.stackman-stack-row[data-stack-row="' + name + '"]')
      : null;
  }

  // Paint one row's state cell, address cell and status dot, unless it is
  // mid-command. The address moves with the state: a container that has just
  // been recreated may be answering somewhere else.
  function paintState(row, html, isUp, address) {
    if (row.dataset.busy) return;
    var td = row.querySelector('[data-cell="state"]');
    if (td) td.innerHTML = html;
    var addr = row.querySelector('[data-cell="address"]');
    if (addr && address !== undefined) addr.innerHTML = address;
    var dot = row.querySelector('.stackman-dot');
    if (dot) dot.classList.toggle('stackman-dot--up', !!isUp);
  }

  // "2 of 3 running". Counted from the rows on screen rather than sent by the
  // server, because the total includes services that have never been created —
  // which only the compose file knows, and only a full refresh re-reads.
  function stackSub(total, up) {
    if (!total) return 'no services';
    if (up === total) return total + (total === 1 ? ' container' : ' containers');
    return up + ' of ' + total + ' running';
  }

  function applyState(res) {
    var stacks = res.stacks || {};
    Object.keys(stacks).forEach(function (name) {
      var row = rowFor(name);
      if (!row) return;                 // added since this table was rendered
      var s = stacks[name];

      // The cell's contents come from the server already rendered, so a pill
      // that appears without a page load is identical to one that came with
      // it — including its translated wording.
      paintState(row, s.html, s.running, s.address);

      // The menu is rebuilt from these attributes every time it opens, so
      // updating them is what turns Start into Restart and enables Stop.
      var btn = row.querySelector('[data-menu="stack"]');
      if (btn) btn.dataset.running = s.running ? '1' : '0';

      // Compose only reveals the project name once a stack is up, and it is not
      // always the folder name — a compose file may set its own `name:`. Taking
      // the real one here is what lets the statistics find the containers of a
      // stack that has just started, rather than waiting for a page load.
      if (s.project && row.dataset.project !== s.project) {
        row.dataset.project = s.project;
        rebindStatRows();
      }

      /* ---- the containers underneath it ---- */
      var kids  = kidRows[name] || [];
      var up    = 0;
      var known = s.containers || {};

      kids.forEach(function (kid) {
        var c = known[kid.dataset.service];

        if (!c) {
          // Stopping a stack REMOVES its containers — `compose down` is not
          // `stop` — so a row with nothing behind it is the normal end state,
          // not a missing reading.
          kid.dataset.container = '';
          kid.dataset.state = '';
          paintState(kid, res.notCreated || '', false, res.noAddress || '');
          return;
        }

        // Filled in here rather than at render time: the first time a stack is
        // started these rows came from the compose file and had no container
        // to point at yet. This is what binds them to a real one.
        kid.dataset.container = c.container;
        kid.dataset.state     = c.state;
        if (c.state === 'running') up++;
        paintState(kid, c.html, c.state === 'running', c.address);
      });

      var sub = row.querySelector('[data-cell="stack-sub"]');
      if (sub && kids.length) sub.textContent = stackSub(kids.length, up);
    });

    var folders = res.folders || {};
    Object.keys(folders).forEach(function (id) {
      var tr = document.querySelector('[data-folder-row="' + id + '"]');
      if (!tr) return;
      var sub = tr.querySelector('[data-cell="folder-sub"]');
      if (sub) sub.innerHTML = folders[id].html;
      var fdot = tr.querySelector('.stackman-dot');
      if (fdot) fdot.classList.toggle('stackman-dot--up', folders[id].running > 0);
    });
  }

  function refreshState() {
    // Two of these in flight at once would race, and the slower reply would
    // paint over the newer one. Run one at a time and remember if another was
    // asked for while it was out.
    if (stateBusy) { stateAgain = true; return; }
    stateBusy = true;
    call('state', {}, 30000).then(function (res) {
      stateBusy = false;
      if (res.ok) applyState(res);
      if (stateAgain) { stateAgain = false; refreshState(); }
    });
  }

  // Compose returns once it has issued the command, not once the containers
  // have settled — and a container that starts and immediately dies takes
  // longer still to show its real state. Asking straight away and twice more
  // afterwards catches all three without polling on indefinitely.
  function refreshStateSoon() {
    refreshState();
    setTimeout(refreshState, 1500);
    setTimeout(refreshState, 5000);
  }

  function refreshRows(done) {
    call('rows', {}, 60000).then(function (res) {
      if (!res.ok) { failed('Could not refresh the stack list', res.error); return; }

      // Captured right before the swap, not any earlier — the request can
      // sit on the network for a while, and whatever the user was focused
      // on when it started is not necessarily still true when it lands.
      // The row itself is about to be thrown away wholesale, so what
      // survives the swap is a description of it (see describeRow() in the
      // keyboard-navigation section below), not the element.
      var hadFocus  = document.activeElement === rovingRow;
      var rovingWas = describeRow(rovingRow);

      // The whole table is replaced below, so without this snapshot every
      // row would look "new" on every refresh and the list would shimmer on
      // the timer instead of staying still for rows that were already there.
      var seenRows = {};
      if (rowsHost) {
        Array.prototype.forEach.call(
          rowsHost.querySelectorAll('[data-stack-row]'),
          function (r) { seenRows[r.dataset.stackRow] = true; });

        rowsHost.innerHTML = res.html;

        Array.prototype.forEach.call(
          rowsHost.querySelectorAll('[data-stack-row]'), function (r) {
            if (seenRows[r.dataset.stackRow]) return;
            r.classList.add('stackman-row--enter');
            r.addEventListener('animationend', function () {
              r.classList.remove('stackman-row--enter');
            }, { once: true });
          });
      }

      // The server just rendered every stack collapsed — put back whatever
      // this session had open before the swap. See expandedStacks above.
      restoreExpandedStacks();

      // Every row in the fresh markup starts with no tabindex at all, the
      // same situation the very first page load was in — rebuild the
      // roving index from scratch and land back on whichever row this was,
      // matched by name/id since the actual DOM node is gone. Only actually
      // moves keyboard focus if the grid genuinely had it a moment ago.
      initRowNav(hadFocus, rovingWas);

      // Whatever the menu was attached to may not exist any more.
      closeMenu();

      FOLDERS = res.folders || [];
      scaffold.dataset.folders = JSON.stringify(FOLDERS);

      // New rows arrive with empty statistics cells. Re-collect them and ask
      // for figures immediately rather than leaving a table of em dashes until
      // the next poll comes round. The graph history is held in this script,
      // keyed by project, so it survives the swap untouched.
      rebindStatRows();
      pollStats();
      // Fresh markup may name icons this browser has never loaded — a stack
      // that was just added, for instance.
      fetchIcons();

      if (done) done();
    });
  }

  /* -------------------------------------------------------------- icons -- */

  /* Icons arrive after the page does.
   *
   * Downloading them while building the page would be the obvious thing and the
   * wrong one: twenty new containers at a tenth of a second each is a two-second
   * page, paid on the one render where nobody is willing to wait. So the table
   * draws with whatever is already cached, every tile that is still missing one
   * carries data-icon-ref, and this fills them in afterwards. Nothing moves when
   * they land — the tile is the same size either way.
   */

  var iconsBusy = false;

  function paintIcons(map) {
    Object.keys(map).forEach(function (ref) {
      // A reference is lower-case letters, digits and hyphens — the server
      // enforces that before it will write a file under one — so it is safe to
      // put straight into a selector.
      var nodes = document.querySelectorAll('[data-icon-ref="' + ref + '"]');

      Array.prototype.forEach.call(nodes, function (node) {
        if (node.tagName === 'IMG') {
          if (!node.getAttribute('src')) node.src = map[ref];
          return;
        }

        // Replacing the initials tile, not hiding it: the letters and colour go
        // onto the picture so that if the picture later fails to load, there is
        // still something to put back.
        var img = document.createElement('img');
        img.alt = '';
        img.dataset.iconRef = ref;
        img.dataset.fallback = (node.textContent || '').trim();
        img.dataset.fallbackColour = (String(node.className).match(/stackman-tile--(\d+)/) || [])[1] || '0';
        img.src = map[ref];
        if (node.parentNode) node.parentNode.replaceChild(img, node);
      });
    });
  }

  function fetchIcons() {
    if (iconsBusy) return;
    iconsBusy = true;
    call('icons', {}, 60000).then(function (res) {
      iconsBusy = false;
      if (!res || !res.ok) return;
      paintIcons(res.icons || {});
      // The sweep keeps a time budget. `done: false` means it stopped with work
      // still on the list rather than because there was nothing left.
      if (res.done === false) setTimeout(fetchIcons, 500);
    });
  }

  /* An icon that cannot load leaves a broken-image box, which reads as a bug in
   * the page. It happens for a real reason: the copy the browser loads lives in
   * RAM and does not survive a reboot, so a page left open overnight asks for
   * files that are no longer there. Put the initials back instead.
   *
   * Listened for in the capture phase because `error` does not bubble. */
  document.addEventListener('error', function (e) {
    var img = e.target;
    if (!img || img.tagName !== 'IMG' || !img.dataset || !img.dataset.fallback) return;
    if (!img.closest || !img.closest('.stackman-icon')) return;

    var span = document.createElement('span');
    span.className = 'stackman-tile stackman-tile--' + (img.dataset.fallbackColour || '0');
    span.textContent = img.dataset.fallback;
    if (img.dataset.iconRef) span.dataset.iconRef = img.dataset.iconRef;
    if (img.parentNode) img.parentNode.replaceChild(span, img);
  }, true);

  /* ------------------------------------------------------------ wiring -- */

  document.getElementById('stackman-add').addEventListener('click', function () {
    openEditor('', '', true);
  });

  document.getElementById('stackman-diagnose').addEventListener('click', function () {
    logPanel.hidden = false;
    logTitle.textContent = 'Self-test';
    logBox.textContent = 'Checking…';
    logPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });

    // Stage one is pure PHP and runs no commands, so it cannot hang. Stage two
    // runs the external commands one at a time, appending each result as it
    // lands — so if one of them never returns, the line above it is the last
    // thing that worked, and the missing line is the culprit.
    call('ping', {}, 15000).then(function (res) {
      if (!res.ok || !res.report) {
        logBox.textContent = res.error || 'Self-test returned nothing usable.\n\n' +
                             JSON.stringify(res, null, 2);
        return;
      }

      var width = 0;
      Object.keys(res.report).forEach(function (k) { width = Math.max(width, k.length); });
      var pad = function (s, w) { return s + new Array(Math.max(1, w - s.length + 3)).join(' '); };

      logBox.textContent = Object.keys(res.report).map(function (k) {
        return pad(k, width) + res.report[k];
      }).join('\n') + strayWarning(res);

      var keys = Object.keys(res.probes || {});
      if (!keys.length) return;

      logBox.textContent += '\n\nCommands, one at a time:\n';

      // Sequential on purpose. Running them together would tell us that
      // something stalled, but not which.
      keys.reduce(function (chain, key) {
        return chain.then(function () {
          logBox.textContent += '\n  ' + pad(key, 10) + res.probes[key] + ' … ';
          logBox.scrollTop = logBox.scrollHeight;

          return call('probe', { probe: key }, 30000).then(function (p) {
            if (!p.ok || !p.result) {
              logBox.textContent += 'NO REPLY — ' + (p.error || 'unknown').split('\n')[0];
              throw new Error('stop');   // nothing after this would be meaningful
            }
            var r = p.result;
            logBox.textContent += (r.ok ? 'ok' : 'FAILED (exit ' + r.exit + ')') +
                                  '  [' + r.ms + ' ms]' +
                                  (r.ok && r.exit === 0 ? '' : '\n' + pad('', 14) + r.output);
            logBox.scrollTop = logBox.scrollHeight;
          });
        });
      }, Promise.resolve()).catch(function () {
        logBox.textContent += '\n\nStopped here. The step above is where it gets stuck.';
      });
    });
  });

  document.getElementById('stackman-modal-close').addEventListener('click', function () {
    if (confirmDiscard()) closeEditor();
  });
  saveBtn.addEventListener('click', function () { save(false); });
  startBtn.addEventListener('click', function () { save(true); });

  document.getElementById('stackman-log-close').addEventListener('click', function () {
    if (poller) { clearInterval(poller); poller = null; }
    logPanel.hidden = true;
  });

  function failed(title, message) {
    logPanel.hidden = false;
    logTitle.textContent = title;
    logBox.textContent = message || '(no detail given)';
    logPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  /* ------------------------------------------------------------- actions -- */

  /* Every one of these takes both names. `name` is the folder, which is what
   * the server acts on; `label` is what the row says, which for a stack of one
   * container is that container's name and not the folder's. Anything shown to
   * someone has to use the label, or a message names something they cannot see
   * on the page. The one exception is the folder itself in the delete warning
   * below, and there it is spelled out as a folder for exactly that reason. */

  /* What a stack's row is currently showing, read back off the page. Only the
   * stack menu is handed a label by the markup; anything else that needs one
   * has to ask the row for it. Falls back to the folder name if the row has
   * gone — a message naming the folder beats no message at all. */
  function stackLabel(name) {
    var row = rowFor(name);
    var btn = row && row.querySelector('[data-menu="stack"]');
    return (btn && btn.dataset.label) || name;
  }

  // Whether a stack has any containers up right now, read off the row's own
  // menu button — the same attribute applyState() keeps current on every poll.
  function stackIsRunning(name) {
    var row = rowFor(name);
    var btn = row && row.querySelector('[data-menu="stack"]');
    return !!(btn && btn.dataset.running === '1');
  }

  function editStack(name, label) {
    call('read', { name: name }).then(function (res) {
      if (!res.ok) { failed('Could not open ' + label, res.error); return; }
      openEditor(res.name, res.body, false);
    });
  }

  function deleteStack(name, label) {
    var where = label === name ? '' : ' Its folder, "' + name + '", goes with it.';

    if (!window.confirm(
          'Delete "' + label + '"?\n\n' +
          'Its containers are stopped and removed, and the compose file is deleted.' + where + '\n\n' +
          'Data stored outside the stack folder is left alone.')) {
      return;
    }
    call('delete', { name: name }).then(function (res) {
      if (!res.ok) { failed('Could not delete ' + label, res.error); return; }
      // Fade the row before the table re-renders wholesale, rather than
      // having it just vanish when the replacement HTML arrives without it.
      var row = rowFor(name);
      if (row) row.classList.add('stackman-row--leave');
      setTimeout(refreshRows, 140);
    });
  }

  function afterRun(verb) {
    // Logs and config change nothing, so leave the table as it is.
    return function () {
      if (verb !== 'logs' && verb !== 'config') refreshStateSoon();
    };
  }

  /* --------------------------------------------------------- context menu -- */

  // One menu element, repopulated per row and positioned with `fixed` so it is
  // measured against the viewport. Anything else has to account for page
  // scroll and for the table's own scroll container, and gets it wrong.
  var menu      = document.getElementById('stackman-menu');
  var menuHead  = document.getElementById('stackman-menu-head');
  var menuItems = document.getElementById('stackman-menu-items');
  var FOLDERS   = [];
  try { FOLDERS = JSON.parse(scaffold.dataset.folders || '[]'); } catch (e) { FOLDERS = []; }
  var CAN_RUN = scaffold.dataset.canrun === '1';

  function closeMenu() {
    // The scroll listener that calls this is registered in the CAPTURE phase,
    // so it fires for scrolling inside ANY element on the page — including the
    // editor's own panes, where it would otherwise run on every frame.
    if (menu.hidden) return;
    menu.hidden = true;
    menuItems.textContent = '';
  }

  function menuItem(label, icon, handler, opts) {
    opts = opts || {};
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'stackman-menu-item' + (opts.danger ? ' stackman-menu-item--danger' : '');
    b.disabled = !!opts.disabled;
    // The item is a flex row: glyph, then a column holding the label and its
    // explanation. A bare text node would become an anonymous flex item and
    // sit alongside the hint rather than above it.
    b.innerHTML = '<i class="fa fa-' + icon + '"></i>';

    var col = document.createElement('span');
    col.className = 'stackman-menu-label';

    var text = document.createElement('span');
    text.textContent = label;
    col.appendChild(text);

    if (opts.hint) {
      var s = document.createElement('span');
      s.className = 'stackman-menu-hint';
      s.textContent = opts.hint;
      col.appendChild(s);
    }

    b.appendChild(col);
    b.addEventListener('click', function () {
      if (b.disabled) return;
      closeMenu();
      handler();
    });
    menuItems.appendChild(b);
    return b;
  }

  function menuSeparator(label) {
    var d = document.createElement('div');
    d.className = 'stackman-menu-sep';
    if (label) d.textContent = label;
    menuItems.appendChild(d);
  }

  function buildStackMenu(d) {
    var name    = d.stack;
    // What the row says, which is not always the folder name. Commands take the
    // folder; anything a person reads takes this.
    var label   = d.label || d.stack;
    var parses  = d.parses === '1';
    var hasFile = d.hasfile === '1';
    var running = d.running === '1';
    var inFolder = d.folder || '';

    if (parses) {
      var why = CAN_RUN ? '' : 'Docker or compose unavailable';
      menuItem(running ? 'Restart' : 'Start', running ? 'refresh' : 'play',
               function () { run(name, running ? 'restart' : 'up', afterRun('up')); },
               { disabled: !CAN_RUN, hint: why });
      menuItem('Stop', 'stop', function () { run(name, 'down', afterRun('down')); },
               { disabled: !CAN_RUN || !running });
      // Still a plain pull, not pull-then-up-d like the container menu's own
      // Update below — recreating several containers across a whole stack
      // from one click is a bigger decision than this plugin makes for you
      // yet, so for now this only fetches new images; nothing running is
      // touched. Deliberate gap, not a missed spot.
      menuItem('Update images', 'download', function () { run(name, 'pull', afterRun('pull')); },
               { disabled: !CAN_RUN });
      menuItem('Logs', 'file-text-o', function () { run(name, 'logs', afterRun('logs')); },
               { disabled: !CAN_RUN });
      menuSeparator();
    }

    if (hasFile) menuItem('Edit compose file', 'pencil', function () { editStack(name, label); });

    if (FOLDERS.length) {
      menuSeparator('Move to folder');
      FOLDERS.forEach(function (f) {
        menuItem(f.name, f.id === inFolder ? 'check-square-o' : 'folder-o', function () {
          call('folder-assign', { name: name, folder: f.id }).then(function (r) {
            if (!r.ok) { failed('Could not move ' + label, r.error); return; }
            refreshRows();
          });
        }, { disabled: f.id === inFolder });
      });
      if (inFolder) {
        menuItem('Remove from folder', 'level-up', function () {
          call('folder-assign', { name: name, folder: '' }).then(function (r) {
            if (!r.ok) { failed('Could not move ' + label, r.error); return; }
            refreshRows();
          });
        });
      }
    }

    menuSeparator();
    menuItem('Delete stack', 'trash-o', function () { deleteStack(name, label); }, { danger: true });
  }

  /* The container menu takes the trigger ELEMENT, not just its dataset, the
   * way buildStackMenu(d) and buildFolderMenu(d) do — because the one thing
   * this menu most needs, whether the container is up, does not live on the
   * button at all. It lives on the ROW, refreshed by applyState() on every
   * poll, and the button carries only what render time already knew (which
   * stack, which service). trigger.closest() is what reaches it.
   */
  function buildContainerMenu(trigger) {
    var row     = trigger.closest('.stackman-container-row');
    var stack   = trigger.dataset.stack;
    var service = trigger.dataset.service;
    var state   = row ? (row.dataset.state || '') : '';
    var exists  = state !== '';
    var up      = state === 'running' || state === 'restarting' || state === 'paused';

    var why = CAN_RUN ? '' : 'Docker or compose unavailable';

    menuItem(up ? 'Restart' : 'Start', up ? 'refresh' : 'play', function () {
      run(stack, up ? 'restart' : 'up', afterRun('up'), service);
    }, { disabled: !CAN_RUN, hint: why });

    menuItem('Stop', 'stop', function () {
      run(stack, 'down', afterRun('down'), service);
    }, { disabled: !CAN_RUN || !up });

    // Singular "image" — this pulls the one image behind this one container,
    // not every service's, which is what "Update images" on the stack menu
    // does.
    //
    // Which verb runs depends on whether the container is up, and that is
    // the point, not an inconsistency to fix later. A running container gets
    // `pull` followed by `up -d`, so it comes back on the image that was
    // just fetched — that IS what "update" means for something that is
    // running, since a pull on its own only leaves a new image sitting on
    // disk unused. A container that is not running has nothing to restart,
    // and `up -d` on it would START it, which is not what pressing Update
    // asked for — so a stopped container is only pulled, and the new image
    // is simply what its next Start uses. Same label, same icon, and either
    // way the container ends up on the newest image; the only difference is
    // whether Update is allowed to change whether the container is running,
    // and it is not.
    menuItem('Update image', 'download', function () {
      run(stack, up ? 'update' : 'pull', afterRun('update'), service);
    }, { disabled: !CAN_RUN });

    menuItem('Logs', 'file-text-o', function () {
      run(stack, 'logs', afterRun('logs'), service);
    }, {
      disabled: !CAN_RUN || !exists,
      // A container that has never been created has no log to show — that
      // is worth explaining on its own, separately from the ordinary
      // "docker unavailable" hint above, which is why this checks !exists
      // specifically rather than reusing `why`.
      hint: !exists ? 'This container has not been created yet' : ''
    });

    menuSeparator();

    // A container's settings live in its STACK's compose file — there is no
    // separate file to open for one service — so this is the very same
    // handler the stack menu's own "Edit compose file" calls. Offering it
    // here too saves hunting for the parent row just to reach it.
    menuItem('Edit compose file', 'pencil', function () { editStack(stack, stackLabel(stack)); });

    menuSeparator();

    menuItem('Remove container', 'trash-o', function () {
      if (!window.confirm(
            'Remove the container for "' + service + '"?\n\n' +
            'It is stopped and removed. The compose file is not touched, so Start ' +
            'recreates it. Named volumes and anything stored outside the container ' +
            'are left alone.')) {
        return;
      }
      run(stack, 'remove', afterRun('remove'), service);
    }, { danger: true, disabled: !CAN_RUN || !exists });
  }

  function buildFolderMenu(d) {
    var id   = d.folder;
    var name = d.label;

    menuItem('Start everything', 'play', function () { folderRun(id, 'up', name); },
             { disabled: !CAN_RUN });
    menuItem('Stop everything', 'stop', function () { folderRun(id, 'down', name); },
             { disabled: !CAN_RUN });
    menuSeparator();
    menuItem('Rename folder', 'pencil', function () {
      var row  = document.querySelector('[data-folder-row="' + id + '"]');
      var host = row && row.querySelector('.stackman-folder-name');
      if (!host) return;

      inlineName(host, name, {
        say: function (message) { failed('Could not rename the folder', message); },
        save: function (value) {
          var why = badFolderName(value);
          if (why) return why;

          // The server answers after this returns, so a refusal from it goes
          // to the failed() dialog rather than reopening the box.
          call('folder-rename', { folder: id, folderName: value }).then(function (r) {
            if (!r.ok) { failed('Could not rename the folder', r.error); return; }
            refreshRows();
          });
        }
      });
    });
    menuSeparator();
    menuItem('Delete folder', 'trash-o', function () {
      if (!window.confirm(
            'Delete the folder "' + name + '"?\n\n' +
            'A folder is a real directory now. The stacks inside it are not deleted — ' +
            'each one is moved back up to the top level first, and nothing is moved ' +
            'at all unless every one of them can be.')) {
        return;
      }
      call('folder-delete', { folder: id }).then(function (r) {
        if (!r.ok) { failed('Could not delete the folder', r.error); return; }
        refreshRows();
      });
    }, { danger: true });
  }

  function folderRun(id, verb, label) {
    // Every stack in the folder spins, and so does everything inside them.
    var rows = [];
    Array.prototype.forEach.call(
      document.querySelectorAll('.stackman-stack-row[data-in-folder="' + id + '"]'),
      function (r) { rows = rows.concat(stackRows(r.dataset.stackRow)); }
    );
    setBusy(rows, BUSY_LABEL[verb] || 'Working…');

    call('folder-run', { folder: id, verb: verb }).then(function (res) {
      if (!res.ok) { clearBusy(rows); failed(label, res.error); return; }

      // Follow the last one; they all run at once, and every row is refreshed
      // together once it finishes.
      follow(res.jobs[res.jobs.length - 1].job, function (job) {
        clearBusy(rows);
        if (job.exit !== 0 && job.exit !== null) {
          failed(label + ' — something failed (exit ' + job.exit + ')',
                 job.text || '(no output)');
        }
        refreshStateSoon();
      }, false);
    });
  }

  function openMenu(trigger) {
    closeMenu();
    var d = trigger.dataset;

    menuHead.textContent = d.label || '';
    if (d.menu === 'folder') buildFolderMenu(d);
    else if (d.menu === 'container') buildContainerMenu(trigger);
    else buildStackMenu(d);

    // Show it before measuring — a hidden element has no size.
    menu.hidden = false;
    menu.style.left = '0px';
    menu.style.top  = '0px';

    var at   = trigger.getBoundingClientRect();
    var size = menu.getBoundingClientRect();
    var pad  = 8;

    var left = at.left;
    var top  = at.bottom + 4;

    // Keep it on screen: flip above the icon if it would run off the bottom,
    // and pull it left if it would run off the right.
    if (left + size.width + pad > window.innerWidth) {
      left = Math.max(pad, window.innerWidth - size.width - pad);
    }
    if (top + size.height + pad > window.innerHeight) {
      var above = at.top - size.height - 4;
      top = above >= pad ? above : Math.max(pad, window.innerHeight - size.height - pad);
    }

    menu.style.left = Math.round(left) + 'px';
    menu.style.top  = Math.round(top) + 'px';
  }

  /* What actually identifies a menu trigger, for deciding whether a second
   * click on an icon should close ITS OWN menu or open a different one.
   *
   * The label alone is not identity, which used to be the whole key. Two
   * different stacks can each contain a service with the same name — every
   * "demo" compose file in this repository has a service called that — so a
   * key built from just the label and the menu kind collides between them:
   * clicking one container's icon while another, same-named container's menu
   * was already open would read as "same icon, close it" and leave the wrong
   * menu open. Folding in whatever attributes actually distinguish a trigger
   * — folder id, stack name, service name — is what makes two same-labelled
   * triggers compare unequal.
   */
  function menuOwnerKey(el) {
    var d = el.dataset;
    return d.menu + '|' + (d.folder || '') + '|' + (d.stack || '') + '|' + (d.service || '');
  }

  /* ------------------------------------------------------------- folders -- */

  /* Two independent switches decide whether a container row is on screen:
   * whether its folder is open, and whether its stack is expanded. They are
   * worked out from scratch every time rather than toggled — toggling one
   * while the other is shut is how a container row reappears out of a
   * collapsed folder: hiding is easy, and it is putting things BACK that
   * needs to know about the other switch.
   *
   * `hidden` never belongs on .stackman-group--folder or .stackman-group--
   * stack — each of those wrappers contains its own heading, and hiding the
   * wrapper would hide the chevron that undoes the collapse along with the
   * rows it controls: a folder gone until the page reloads, or a stack row
   * missing where the user just clicked to bring its containers back.
   *
   * .stackman-group--folder-children and .stackman-group--children are both
   * different, and deliberately so — each is hidden here as a WHOLE, not row
   * by row. The rule above reads as "never hide a group", but the reason
   * underneath it was always narrower than that: "never hide the element
   * that contains your own escape hatch". Neither wrapper holds a chevron —
   * the control that re-expands it is one level up, outside the wrapper — so
   * hiding it can never hide the thing that would undo the hiding. That is
   * also what lets stack.manager.css slide each one open as a single band,
   * and it is why a stack row's own `hidden` no longer needs to ask whether
   * its folder is open: an ancestor wrapper does that hiding now.
   */
  function applyVisibility() {
    var folderOpen = {};
    Array.prototype.forEach.call(
      document.querySelectorAll('[data-folder-row]'), function (tr) {
        var chevron = tr.querySelector('[data-toggle-folder]');
        folderOpen[tr.dataset.folderRow] =
          !chevron || chevron.getAttribute('aria-expanded') === 'true';
      });

    Array.prototype.forEach.call(
      document.querySelectorAll('[data-folder-children]'), function (g) {
        g.hidden = folderOpen[g.dataset.folderChildren] === false;
      });

    // A stack row's own `hidden` is never set here any more — a collapsed
    // folder now hides its contents via the wrapper above, and a stack with
    // no folder (data-in-folder="") sits outside every wrapper and is always
    // visible. Clear any stale `hidden` a previous version of this function
    // could have left behind.
    var stackOpen = {};
    Array.prototype.forEach.call(
      document.querySelectorAll('.stackman-stack-row'), function (tr) {
        tr.hidden = false;
        stackOpen[tr.dataset.stackRow] = tr.dataset.expanded === '1';
      });

    // Keyed by the same stack name as stackOpen — data-stack-children carries
    // it on the wrapper, the same way data-stack-row does on the row itself —
    // so no separate lookup is needed here.
    Array.prototype.forEach.call(
      document.querySelectorAll('.stackman-group--children'), function (g) {
        g.hidden = !stackOpen[g.dataset.stackChildren];
      });
  }

  /* The server now always renders every stack collapsed (the user's explicit
   * choice — see Folders.php). Left alone, that would mean any action that
   * calls refreshRows() — adding a stack, deleting one, touching a folder —
   * would slam shut every stack the page had open, because the replacement
   * HTML arrives collapsed every single time.
   *
   * So this page keeps its own memory of which stacks are open, independent
   * of the server, for as long as the page stays loaded. A fresh load starts
   * with nothing in it (collapsed, as asked); refreshRows() re-applies it
   * to the new markup so a stack the user had open stays open across a
   * mid-session refresh. Nothing here is sent to the server.
   */
  var expandedStacks = {};   // stack name -> true, for this page load only

  // Paints a stack row and its chevron as expanded or collapsed. Pulled out
  // of toggleStack() so the restore pass below can reach the same four
  // things — data-expanded, both aria-expanded attributes, the chevron's
  // title, and its <i> class — without a second copy of the logic that could
  // drift out of step with the original.
  function setStackExpanded(row, chevron, open) {
    row.dataset.expanded = open ? '1' : '0';
    // Same reasoning as toggleFolder(): the chevron's aria-expanded belongs to
    // the button a screen reader just activated, the row's is what says the
    // row itself is expanded.
    row.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (!chevron) return;
    chevron.setAttribute('aria-expanded', open ? 'true' : 'false');
    chevron.title = open ? 'Hide containers' : 'Show containers';
    var icon = chevron.querySelector('i');
    if (icon) icon.className = 'fa fa-chevron-' + (open ? 'down' : 'right');
  }

  // Called after refreshRows() swaps in fresh, always-collapsed markup: puts
  // back whatever this session had open before the swap.
  function restoreExpandedStacks() {
    Object.keys(expandedStacks).forEach(function (name) {
      var row = rowFor(name);
      if (!row) return;                                    // stack is gone
      var chevron = row.querySelector('[data-toggle-stack]');
      if (!chevron) return;   // no longer expandable (down to one container)
      setStackExpanded(row, chevron, true);
    });
    applyVisibility();
  }

  function toggleFolder(id, chevron) {
    var open = chevron.getAttribute('aria-expanded') !== 'true';

    chevron.setAttribute('aria-expanded', open ? 'true' : 'false');
    chevron.querySelector('i').className = 'fa fa-chevron-' + (open ? 'down' : 'right');

    // The row carries its own aria-expanded too — the chevron's is what a
    // screen reader announces on the control it just activated, the row's is
    // what makes the row itself read as expanded or collapsed in the tree.
    var row = document.querySelector('[data-folder-row="' + id + '"]');
    if (row) row.setAttribute('aria-expanded', open ? 'true' : 'false');

    // The class is the only thing that may change here. Unraid's
    // default-fonts.css carries `[data-icon]:before { font-family: docker-icon
    // !important }`, and that font holds a single glyph — the Docker whale — so
    // a data-icon attribute alongside a Font Awesome class renders an empty box
    // that no rule of ours can win back.
    var folderIcon = document.querySelector('[data-menu="folder"][data-folder="' + id + '"] i');
    if (folderIcon) folderIcon.className = 'fa fa-folder' + (open ? '-open' : '');

    applyVisibility();

    // Remembered on the server, so the layout is the same from any device.
    call('folder-collapse', { folder: id, collapsed: open ? '0' : '1' });
  }

  function toggleStack(name, chevron) {
    var row = rowFor(name);
    if (!row) return;

    var open = row.dataset.expanded !== '1';
    setStackExpanded(row, chevron, open);

    // Session memory only — see the comment above expandedStacks. Nothing is
    // sent to the server; "always collapsed on load" is what was asked for.
    if (open) expandedStacks[name] = true;
    else      delete expandedStacks[name];

    applyVisibility();
  }

  var addFolderBtn = document.getElementById('stackman-add-folder');
  addFolderBtn.addEventListener('click', function () {
    inlineName(addFolderBtn, '', {
      placeholder: 'Media',
      say: function (message) { failed('Could not create the folder', message); },
      save: function (value) {
        var why = badFolderName(value);
        if (why) return why;

        call('folder-create', { folderName: value }).then(function (r) {
          if (!r.ok) { failed('Could not create the folder', r.error); return; }
          refreshRows();
        });
      }
    });
  });

  /* -------------------------------------------------------------- wiring -- */

  scaffold.addEventListener('click', function (event) {
    var el = event.target.closest('button');
    if (!el) return;

    if (el.dataset.toggleFolder) { toggleFolder(el.dataset.toggleFolder, el); return; }
    if (el.dataset.toggleStack)  { toggleStack(el.dataset.toggleStack, el);  return; }
    if (el.dataset.menu) {
      event.stopPropagation();
      var ownerKey = menuOwnerKey(el);
      if (!menu.hidden && menu.dataset.owner === ownerKey) {
        closeMenu();                        // clicking the same icon closes it
      } else {
        menu.dataset.owner = ownerKey;
        openMenu(el);
      }
    }
  });

  document.addEventListener('click', function (event) {
    if (menu.hidden) return;
    if (!menu.contains(event.target)) closeMenu();
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && !menu.hidden) closeMenu();
  });

  // A menu positioned against the viewport has to go away when the viewport
  // moves under it, or it detaches from the icon it belongs to.
  window.addEventListener('resize', closeMenu);
  window.addEventListener('scroll', closeMenu, true);

  /* -------------------------------------------------------- keyboard nav -- */

  /* role="treegrid" carries an expectation plain role="table" never did: that
   * the arrow keys move you around it. Shipping the role without that is
   * worse than not having it at all, so this is what makes good on it.
   *
   * The approach is a single roving tabindex: exactly one row (rovingRow)
   * carries tabindex="0" and is the one Tab lands on; every other row
   * carries tabindex="-1", which keeps it out of the Tab sequence but still
   * focusable from script — a plain <div> with NO tabindex at all cannot be
   * focused programmatically at all, tabindex="-1" or not, so every body row
   * has to be touched once up front (initRowTabIndex()) before any of this
   * can move focus anywhere.
   *
   * DELIBERATE GAP: the strict version of this pattern also drops every
   * button INSIDE a row — chevron, icon, menu trigger — to tabindex="-1"
   * and has the row manage them as if they were the only stops in the
   * whole grid. That is not done here. Every one of those buttons is
   * already reachable by an ordinary Tab press today, and taking that away
   * would be a regression for anyone already using the page that way. Rows
   * become an ADDITIONAL focus stop threaded in among the existing ones,
   * never a replacement for them — which is also why the handler below
   * only reacts when the ROW ITSELF is the focus target, not a button
   * inside it; see onGridKeydown().
   */

  var ROW_SELECTOR = '.stackman-stacks .stackman-row:not(.stackman-head-row)';
  var stacksGrid   = document.querySelector('.stackman-stacks');
  var rovingRow    = null;   // the element currently carrying tabindex="0"

  /* "Visible" is never `!row.hidden`: no row carries `hidden` itself. Only
   * the two wrappers do — `.stackman-group--folder-children` for a collapsed
   * folder and `.stackman-group--children` for an unexpanded stack (see
   * applyVisibility() above) — so every hidden row is hidden by an ancestor,
   * one or two levels up. closest() walks up until it finds one, which is
   * also why this stays right if the nesting gains another level.
   *
   * The attribute, not the computed style: a collapsed wrapper is deliberately
   * still `display: grid` so its height can animate (stack.manager.css), so
   * anything measuring pixels here would call a collapsed row visible. */
  function rowVisible(row) {
    return !!row && !row.closest('[hidden]');
  }

  function visibleRows() {
    return Array.prototype.filter.call(
      document.querySelectorAll(ROW_SELECTOR), rowVisible
    );
  }

  // First element of a NodeList that passes rowVisible(), or null. A plain
  // loop rather than Array.prototype.filter/find: nothing here needs the
  // rest of the list once a match turns up, and this file already reaches
  // for Array.prototype.X.call() only where it actually needs every item.
  function firstVisibleOf(nodeList) {
    for (var i = 0; i < nodeList.length; i++) {
      if (rowVisible(nodeList[i])) return nodeList[i];
    }
    return null;
  }

  // A row's chevron, if it has one. `null` for a leaf: a container row has
  // no toggle control at all, and a single-service stack row's chevron slot
  // holds a `.stackman-chevron--empty` placeholder <span> with neither
  // data attribute — see the `$expandable` branch in StacksTable.php — so
  // this selector already tells expandable apart from leaf without a
  // separate check of its own.
  function rowChevron(row) {
    return row.querySelector('[data-toggle-folder], [data-toggle-stack]');
  }

  function isExpandable(row) {
    return !!rowChevron(row);
  }

  // Folder rows always carry aria-expanded (StacksTable.php sets it
  // unconditionally); expandable stack rows carry it only while they ARE
  // expandable, which is exactly when this is ever asked of them.
  function isExpanded(row) {
    return row.getAttribute('aria-expanded') === 'true';
  }

  // Toggles a row by calling the SAME toggleFolder()/toggleStack() a mouse
  // click on its chevron would — see the constraints this satisfies: the
  // toggles' own behaviour is not reimplemented here, only invoked.
  function toggleRow(row) {
    var chevron = rowChevron(row);
    if (!chevron) return;
    if (row.classList.contains('stackman-folder-row')) {
      toggleFolder(row.dataset.folderRow, chevron);
    } else if (row.classList.contains('stackman-stack-row')) {
      toggleStack(row.dataset.stackRow, chevron);
    }
  }

  /* ---- parent / first-child lookups ----
   *
   * These follow the WRAPPERS the markup actually nests through, not
   * aria-level — a container filed under a folder is level 3 and one
   * filed directly under an unfiled stack is level 2, so level alone
   * cannot tell "go up one" from "go up to the folder". `:scope >` picks
   * the row that belongs to THIS group specifically, not one a deeper
   * group happens to also contain.
   */

  // Container row -> its own stack's row. The container lives inside
  // `.stackman-group--children`, which is a SIBLING of the stack row, not
  // an ancestor of it — both hang off the same `.stackman-group--stack` —
  // so this has to climb to that shared wrapper and come back down the
  // other branch, not just walk up looking for a `.stackman-stack-row`.
  function parentOfContainer(row) {
    var group = row.closest('.stackman-group--stack');
    return group ? group.querySelector(':scope > .stackman-stack-row') : null;
  }

  // Stack row -> the folder row it is filed under, or null when it is not
  // filed in one at all (an unfiled stack has no `.stackman-group--folder`
  // ancestor to find).
  function parentOfStack(row) {
    var group = row.closest('.stackman-group--folder');
    return group ? group.querySelector(':scope > .stackman-folder-row') : null;
  }

  function parentOf(row) {
    if (row.classList.contains('stackman-container-row')) return parentOfContainer(row);
    if (row.classList.contains('stackman-stack-row'))     return parentOfStack(row);
    return null;   // a folder row is already at the top
  }

  // Folder row -> the first visible stack row filed under it. A folder
  // group never nests inside another folder group, so an ordinary
  // descendant query is unambiguous here — there is no deeper folder whose
  // rows could be mistaken for this one's.
  function firstChildOfFolder(row) {
    var group = row.closest('.stackman-group--folder');
    return group ? firstVisibleOf(group.querySelectorAll('.stackman-stack-row')) : null;
  }

  // Expandable stack row -> the first visible container row inside its own
  // `.stackman-group--children`. Scoped to THIS stack's own wrapper via
  // `:scope >` before descending into it, even though today no stack group
  // nests inside another — a leaf's `parentOfContainer()` above already
  // depends on that same one-level relationship staying true, so keeping
  // this one explicit too means a future change that broke the assumption
  // would fail loudly in both places rather than quietly in just one.
  function firstChildOfStack(row) {
    var group   = row.closest('.stackman-group--stack');
    var wrapper = group ? group.querySelector(':scope > .stackman-group--children') : null;
    return wrapper ? firstVisibleOf(wrapper.querySelectorAll('.stackman-container-row')) : null;
  }

  function firstChildOf(row) {
    if (row.classList.contains('stackman-folder-row')) return firstChildOfFolder(row);
    if (row.classList.contains('stackman-stack-row'))  return firstChildOfStack(row);
    return null;   // a container row has no children of its own
  }

  /* ---- moving the roving index ---- */

  function moveRovingTo(row, focus) {
    if (!row) return;
    if (rovingRow && rovingRow !== row) rovingRow.tabIndex = -1;
    row.tabIndex = 0;
    rovingRow = row;
    if (focus) row.focus();
  }

  // Every row starts with no tabindex at all — including rows that just
  // arrived from refreshRows() — so this has to run before moveRovingTo()
  // can put tabindex="0" on any single one of them and have the REST
  // legitimately read as -1 rather than "never considered".
  function initRowTabIndex() {
    Array.prototype.forEach.call(
      document.querySelectorAll(ROW_SELECTOR), function (r) { r.tabIndex = -1; }
    );
  }

  /* What to remember about a row so it can be found again after
   * refreshRows() throws the actual element away — see the three row
   * kinds this needs to tell apart. Deliberately NOT data-container for
   * the container case, even though that is the more obvious field to
   * match on: it is blank until the container actually exists (a service
   * declared in the compose file but never started renders with
   * data-container="", see stackman_stack_children() in StacksTable.php),
   * where data-service is always present and is exactly the key
   * stack.manager.css and this file already trust elsewhere. */
  function describeRow(row) {
    if (!row) return null;
    if (row.classList.contains('stackman-folder-row')) {
      return { type: 'folder', id: row.dataset.folderRow };
    }
    if (row.classList.contains('stackman-stack-row')) {
      return { type: 'stack', name: row.dataset.stackRow };
    }
    if (row.classList.contains('stackman-container-row')) {
      return { type: 'container', stack: row.dataset.inStack, service: row.dataset.service };
    }
    return null;
  }

  // The other half of describeRow(): given what it remembered, find the row
  // in whatever markup is on screen NOW. Selectors built by concatenation
  // here follow the same rule the rest of this file does — folder ids and
  // stack names are restricted to [A-Za-z0-9._-] before either can ever be
  // created, and a service key is a compose service name (same restricted
  // charset) optionally followed by "/" and a docker container name (letters,
  // digits, "._-" too) — so none of them can carry a quote or bracket that
  // would break out of the attribute selector.
  function findRow(desc) {
    if (!desc) return null;
    if (desc.type === 'folder') {
      return document.querySelector('[data-folder-row="' + desc.id + '"]');
    }
    if (desc.type === 'stack') {
      return rowFor(desc.name);
    }
    if (desc.type === 'container') {
      return document.querySelector(
        '.stackman-container-row[data-in-stack="' + desc.stack + '"]' +
        '[data-service="' + desc.service + '"]'
      );
    }
    return null;
  }

  // Rebuilds the roving index from scratch — used both for the very first
  // page load (desc is null, hadFocus is false: nothing to restore, nothing
  // to steal focus from) and after refreshRows() swaps in fresh markup.
  function initRowNav(hadFocus, desc) {
    initRowTabIndex();
    var target = findRow(desc) || visibleRows()[0] || null;
    moveRovingTo(target, hadFocus && !!target);
  }

  /* applyVisibility() is the ONE place every visibility change funnels
   * through, however it was triggered — a mouse click on a chevron (handled
   * in the wiring above, calling toggleFolder()/toggleStack() directly) or
   * a keyboard toggle through toggleRow() below. That makes it the single
   * correct place to also notice when a collapse has hidden the row
   * currently in the tab sequence. Nowhere else sees both paths at once:
   * covering only the keyboard one would miss a mouse click collapsing a
   * folder around a row that was given keyboard focus a moment earlier
   * (arrow-key your way into a stack, then click that folder's OWN chevron
   * with the mouse — the two are on different elements, so this really
   * does happen), and covering the mouse path directly would mean editing
   * toggleFolder()/toggleStack(), which is off-limits — see the constraints
   * this section satisfies.
   *
   * applyVisibility()'s own logic is untouched below: this wraps the plain
   * function-valued variable the name `applyVisibility` points to, rather
   * than changing anything inside what it already does. Every call already
   * written above this point — inside restoreExpandedStacks(), toggleFolder(),
   * toggleStack() — resolves the name `applyVisibility` at the moment it
   * RUNS, not at the moment it was written, and none of those moments can
   * arrive before this reassignment does: they only ever fire from a later
   * user action or a later refreshRows(), never during this script's own
   * initial, synchronous, top-to-bottom run. So they all pick up the
   * wrapped version automatically, with nothing above needing to change. */
  var applyVisibilityUnwrapped = applyVisibility;
  applyVisibility = function () {
    applyVisibilityUnwrapped();
    syncRovingRowAfterVisibility();
  };

  function syncRovingRowAfterVisibility() {
    if (!rovingRow || rowVisible(rovingRow)) return;

    // Walk up to whichever ancestor row is still on screen — a container's
    // stack, or a stack's folder — however many levels that takes.
    var next = parentOf(rovingRow);
    while (next && !rowVisible(next)) next = parentOf(next);
    if (!next) next = visibleRows()[0] || null;

    // Only actually move keyboard focus if the row that just disappeared
    // is the thing that HELD focus. If it is not — the user is focused on
    // the very chevron button that did the collapsing, say, which is a
    // different element from the row it lives in and is still visible and
    // still legitimately focused — then moving focus now would yank it off
    // a button the user just deliberately used, which is exactly the kind
    // of focus-stealing "do not steal focus" above is warning against.
    var hadFocus = document.activeElement === rovingRow;
    moveRovingTo(next, hadFocus);
  }

  function onGridKeydown(event) {
    var row = event.target;

    // Reacts only when the ROW ITSELF is the focused element, not a button
    // inside it. Every chevron and icon button is a real <button> with its
    // own Enter/Space handling already, and this keydown bubbles up through
    // the row regardless of which of the two triggered it — matching the
    // row's own class here, rather than event.target.closest('.stackman-row'),
    // is what keeps that native behaviour from also being run a second time
    // by the switch below.
    if (!row.classList || !row.classList.contains('stackman-row') ||
        row.classList.contains('stackman-head-row')) {
      return;
    }

    var rows, idx, next;

    switch (event.key) {
      case 'ArrowDown':
        rows = visibleRows();
        idx  = rows.indexOf(row);
        if (idx > -1 && idx < rows.length - 1) moveRovingTo(rows[idx + 1], true);
        event.preventDefault();
        break;

      case 'ArrowUp':
        rows = visibleRows();
        idx  = rows.indexOf(row);
        if (idx > 0) moveRovingTo(rows[idx - 1], true);
        event.preventDefault();
        break;

      case 'ArrowRight':
        if (isExpandable(row)) {
          if (!isExpanded(row)) {
            toggleRow(row);                          // collapsed -> expand it
          } else {
            next = firstChildOf(row);                // expanded -> descend
            if (next) moveRovingTo(next, true);
          }
        }
        // A leaf has nothing to expand into and nowhere to descend to, so
        // it does nothing at all — that is the correct, unremarkable case,
        // not a missing branch.
        event.preventDefault();
        break;

      case 'ArrowLeft':
        if (isExpandable(row) && isExpanded(row)) {
          toggleRow(row);                            // expanded -> collapse it
        } else {
          next = parentOf(row);                      // collapsed, or a leaf -> ascend
          if (next) moveRovingTo(next, true);         // null at the top level: no-op
        }
        event.preventDefault();
        break;

      case 'Home':
        rows = visibleRows();
        if (rows.length) moveRovingTo(rows[0], true);
        event.preventDefault();
        break;

      case 'End':
        rows = visibleRows();
        if (rows.length) moveRovingTo(rows[rows.length - 1], true);
        event.preventDefault();
        break;

      case 'Enter':
        if (isExpandable(row)) toggleRow(row);
        event.preventDefault();
        break;

      case ' ':
      case 'Spacebar':   // what old IE/Edge called it; costs nothing to keep
        if (isExpandable(row)) toggleRow(row);
        event.preventDefault();   // MUST run even on a leaf, or the page scrolls
        break;

      default:
        return;   // anything else — Tab very much included — is left alone
    }
  }

  if (stacksGrid) stacksGrid.addEventListener('keydown', onGridKeydown);

  // First run: nothing to restore (desc is null) and nothing to steal focus
  // from (hadFocus is false) — this only ever primes tabindex="0" onto the
  // first visible row so the very first Tab press has somewhere to land.
  initRowNav(false, null);

  /* --------------------------------------------------------------- stats -- */

  /* Live figures for each row.
   *
   * The server samples in the background and hands back a snapshot; everything
   * below is presentation. Two things are worth knowing:
   *
   * 1. Network and disk counters from docker are TOTALS since the container
   *    started, not rates. A rate only exists between two samples, so it is
   *    worked out here from the change over the time between them.
   *
   * 2. Samples are only counted when the server's own timestamp moves. The
   *    collector samples every few seconds and this polls slightly faster, so
   *    the same snapshot is often seen twice — counting it twice would divide
   *    by a zero-length interval and produce nonsense.
   */

  var STATS_POLL   = 3000;   // how often to ask
  var STATS_POINTS = 60;     // roughly three minutes of history per graph

  // Re-collected whenever the table body is replaced, and whenever a row's
  // project name is corrected — the rows held here are the actual elements, so
  // a stale list would keep painting figures into a row that is no longer on
  // the page.
  var statRows = [];
  var kidRows  = {};        // stack name -> its container rows, in order

  function rebindStatRows() {
    statRows = Array.prototype.slice.call(
      document.querySelectorAll('.stackman-stack-row[data-project]')
    );

    kidRows = {};
    Array.prototype.forEach.call(
      document.querySelectorAll('.stackman-container-row'), function (r) {
        var key = r.dataset.inStack;
        (kidRows[key] = kidRows[key] || []).push(r);
      });
  }
  rebindStatRows();

  var strip    = document.getElementById('stackman-strip');
  var stripGpu = document.getElementById('stackman-strip-gpu');
  var stripAge = document.getElementById('stackman-strip-age');

  var history  = {};    // project -> { cpu:[], mem:[], net:[], gpu:[] }
  var previous = {};    // project -> last cumulative counters
  var lastAt   = 0;     // server timestamp of the last snapshot we counted
  var statsTimer = null;

  function bucket(project) {
    if (!history[project]) {
      history[project] = { cpu: [], mem: [], net: [], gpu: [] };
    }
    return history[project];
  }

  function push(list, value) {
    list.push(value);
    if (list.length > STATS_POINTS) list.shift();
  }

  /* ---- formatting ---- */

  function bytes(n) {
    if (!n || n < 1) return '0 B';
    var units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
    var i = 0;
    while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
    return (n >= 100 || i === 0 ? Math.round(n) : n.toFixed(1)) + ' ' + units[i];
  }

  function rate(n) {
    if (!n || n < 1) return '0';
    var units = ['B', 'K', 'M', 'G'];
    var i = 0;
    while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
    return (n >= 100 || i === 0 ? Math.round(n) : n.toFixed(1)) + units[i];
  }

  /* ---- the little graphs ----
   *
   * Inline SVG built from the history array. No library, and no canvas: an
   * SVG scales with the page zoom and stays sharp, and there is one of these
   * per row per metric so it has to stay cheap.
   */
  function sparkline(host, values, peakFloor) {
    if (!host) return;
    if (!values || values.length < 2) { host.innerHTML = ''; return; }

    var W = 88, H = 24, pad = 1.5;
    var peak = Math.max.apply(null, values);
    if (peakFloor && peak < peakFloor) peak = peakFloor;
    if (!(peak > 0)) peak = 1;

    var step = W / (values.length - 1);
    var line = [];
    for (var i = 0; i < values.length; i++) {
      var x = i * step;
      var y = H - pad - (values[i] / peak) * (H - pad * 2);
      line.push(x.toFixed(1) + ',' + y.toFixed(1));
    }

    var area = '0,' + H + ' ' + line.join(' ') + ' ' + W + ',' + H;

    host.innerHTML =
      '<svg viewBox="0 0 ' + W + ' ' + H + '" width="' + W + '" height="' + H +
      '" preserveAspectRatio="none" aria-hidden="true">' +
        '<polygon class="stackman-spark-fill" points="' + area + '"/>' +
        '<polyline class="stackman-spark-line" points="' + line.join(' ') + '"/>' +
      '</svg>';
  }

  /* A small chip badge saying whose GPU it is.
   *
   * Drawn as inline SVG rather than a font glyph: the icon sets Unraid ships
   * vary by release and a missing glyph shows as a blank box, whereas this
   * always renders. Colour does the identifying — Intel blue, AMD red, Nvidia
   * green — with the name on hover for anyone who does not read it that way.
   */
  var GPU_NAMES = { intel: 'Intel', amd: 'AMD', nvidia: 'NVIDIA' };

  function gpuBadge(vendors) {
    if (!vendors || !vendors.length) return '';

    return vendors.map(function (v) {
      var name = GPU_NAMES[v] || v;
      return '<span class="stackman-gpu-badge stackman-gpu-' + v +
             '" title="' + name + ' GPU">' +
        '<svg viewBox="0 0 16 16" width="11" height="11" aria-hidden="true">' +
          '<rect class="stackman-chip" x="3" y="3" width="10" height="10" rx="2"/>' +
          '<g class="stackman-pins">' +
            '<path d="M5.5 1v2M8 1v2M10.5 1v2M5.5 13v2M8 13v2M10.5 13v2"/>' +
            '<path d="M1 5.5h2M1 8h2M1 10.5h2M13 5.5h2M13 8h2M13 10.5h2"/>' +
          '</g>' +
        '</svg>' +
        '<span class="stackman-sr">' + name + '</span>' +
      '</span>';
    }).join('');
  }

  function cell(row, metric) {
    return row.querySelector('[data-stat="' + metric + '"]');
  }

  function setCell(row, metric, text, values, peakFloor) {
    var td = cell(row, metric);
    if (!td) return;
    var value = td.querySelector('.stackman-statv');
    if (value) value.innerHTML = text;
    sparkline(td.querySelector('.stackman-spark'), values, peakFloor);
  }

  /* ---- one row's figures ----
   *
   * A stack and a single container carry exactly the same fields, so they are
   * drawn by the same code. `key` is what the graph history is filed under —
   * the project for a stack, project plus container name for one of its rows —
   * and it has to stay stable across refreshes or the graph restarts.
   */
  function paintFigures(row, key, s, dt, fresh) {
    var h    = bucket(key);
    var prev = previous[key];

    // Totals to rates. A negative change means the container restarted and its
    // counters went back to zero, which is not a transfer.
    var rx = 0, tx = 0;
    if (prev && dt > 0) {
      rx = Math.max(0, s.netRx - prev.netRx) / dt;
      tx = Math.max(0, s.netTx - prev.netTx) / dt;
    }

    if (fresh) {
      push(h.cpu, s.cpu);
      push(h.mem, s.memUsed);
      push(h.net, rx + tx);
      push(h.gpu, s.gpu);
      previous[key] = { netRx: s.netRx, netTx: s.netTx };
    }

    setCell(row, 'cpu', s.cpu.toFixed(1) + '<small>%</small>', h.cpu, 5);
    setCell(row, 'mem', bytes(s.memUsed), h.mem);
    setCell(row,
            'net',
            '<span class="stackman-rx">&darr;' + rate(rx) + '</span>' +
            '<span class="stackman-tx">&uarr;' + rate(tx) + '</span>',
            h.net);

    // The GPU column is reserved for containers that actually have one.
    //
    // Three distinct cases, and they are not the same thing:
    //   no GPU handed to it   -> blank. Not a dash, not a zero. Most containers
    //                            never touch a GPU and a column of "0.0%" says
    //                            nothing.
    //   has one, unmeasurable -> "n/a" plus the reason on hover: the card is
    //                            there and may well be busy, but nothing
    //                            reports which process caused it.
    //   has one, measurable   -> the figure and its graph.
    var gpuTd = cell(row, 'gpu');
    var badge = gpuBadge(s.gpuVendors);

    if (!s.gpuMapped) {
      setCell(row, 'gpu', '', null);
      if (gpuTd) gpuTd.title = '';
    } else if (!s.gpuMeasurable) {
      setCell(row, 'gpu', badge + '<span class="stackman-na">n/a</span>', null);
      if (gpuTd) gpuTd.title = s.gpuWhy || 'No per-container figure available';
    } else {
      setCell(row, 'gpu', badge + s.gpu.toFixed(1) + '<small>%</small>', h.gpu, 5);
      if (gpuTd) {
        gpuTd.title = (s.gpuVendors || []).map(function (v) {
          return GPU_NAMES[v] || v;
        }).join(' + ') + ' GPU';
      }
    }

    // Kept on the row so folder totals can be added up without re-reading the
    // snapshot. Only stack rows are summed — see updateFolderTotals.
    row.dataset.statCpu = s.cpu;
    row.dataset.statMem = s.memUsed;
    row.dataset.statNet = rx + tx;
    row.dataset.statGpu = s.gpu;
    row.dataset.statGpuMapped = s.gpuMapped ? '1' : '';

    // The GPU cell itself is dropped, not just left blank, for a row with
    // nothing mapped — see .stackman-row--no-gpu in stack.manager.css for the
    // CSS half of this. A CLASS, not a selector reading the dataset attribute
    // just above: that attribute is absent before the very first poll lands,
    // and it is ALSO absent on a folder row forever, since nothing here ever
    // touches one — this function only ever runs on a stack row or a
    // container row. Selecting on "attribute absent" would therefore hide a
    // folder's legitimate GPU aggregate along with everything else; toggling
    // a class only from code that actually knows the answer avoids that
    // trap by construction, because a folder row simply never has this
    // function called on it at all.
    row.classList.toggle('stackman-row--no-gpu', !s.gpuMapped);
  }

  function blankFigures(row) {
    setCell(row, 'cpu', '—', null);
    setCell(row, 'mem', '—', null);
    setCell(row, 'net', '—', null);
    setCell(row, 'gpu', '', null);          // blank, never a dash
    row.dataset.statCpu = '';
    row.dataset.statGpuMapped = '';

    // No stats at all this poll — container stopped, never created, or its
    // whole stack is down — means no way to know whether a GPU is mapped
    // either. Hiding the cell here too, unconditionally, rather than leaving
    // it as whatever paintFigures last decided: the alternative is a class
    // that keeps whatever value it happened to have from the last time
    // stats WERE available, which is fine while a row bounces between
    // running and stopped in the ordinary case, but leaves a row that has
    // NEVER had stats (a service declared in the compose file but never
    // started) with no class at all and its GPU cell visible — showing the
    // empty label this whole feature exists to remove. Setting it every
    // time is not a flicker risk: this function is called with the row in
    // the same "no stats" state on every poll for as long as that stays
    // true, so the class is written to the same value it already had.
    row.classList.add('stackman-row--no-gpu');
  }

  /* ---- applying a snapshot ---- */

  function applyStats(res) {
    if (!res || !res.ok) return;

    // Say how stale the figures are rather than letting an unchanging table
    // look like a quiet server.
    if (strip) strip.hidden = false;
    if (stripAge) {
      if (res.warming) {
        stripAge.textContent = 'Collecting first sample…';
      } else if (res.age === null || res.age > 30) {
        stripAge.textContent = 'Figures are ' + (res.age || '?') + 's old' +
                               (res.collector ? '' : ' — collector not running');
      } else {
        stripAge.textContent = 'Updated ' + res.age + 's ago';
      }
    }

    // The machine's own GPU figures, one card per entry.
    //
    // EVERY CARD IS WRITTEN THE SAME WAY: a count of what it is running, then
    // how busy it is. This used to describe an unused Intel card as "idle" and
    // an unused AMD card as "0%", which is the same state told two ways and
    // reads as though the two cards differ. Whatever a card is doing, it is now
    // described in the same shape as its neighbour.
    if (stripGpu) {
      var g = res.gpu || {};

      var card = function (label, c) {
        if (!c) return null;

        var n = c.clients || 0;
        var busy = (typeof c.busy === 'number' ? c.busy : 0);

        // Engines only ever appear for a card that has some, and only while
        // something is on them, so they cannot reintroduce the asymmetry.
        var engines = Object.keys(c.engines || {})
          .map(function (k) { return k + ' ' + c.engines[k] + '%'; });

        return '<b>' + label + '</b> ' +
               n + ' thread' + (n === 1 ? '' : 's') + ' &middot; ' + busy + '%' +
               (engines.length ? ' <i>(' + engines.join(', ') + ')</i>' : '');
      };

      var parts = [card('Intel GPU', g.intel), card('AMD GPU', g.amd)]
        .filter(function (p) { return p !== null; });

      stripGpu.innerHTML = parts.join(' &nbsp;&middot;&nbsp; ');
      stripGpu.hidden = parts.length === 0;

      // The AMD figure comes from radeontop, which watches the card as a whole
      // and has no per-process breakdown; the Intel one can be attributed to a
      // container. That is a real difference in what the numbers mean, so it is
      // said here rather than being allowed to distort how they are printed.
      stripGpu.title = 'Whole-machine GPU figures. '
                     + 'The thread count is the number of separate pieces of work each card is '
                     + 'running, counted the same way for every card.';
    }

    // Only a snapshot the server has actually refreshed advances the graphs.
    var fresh = res.sampledAt && res.sampledAt !== lastAt;
    var dt    = fresh && lastAt ? (res.sampledAt - lastAt) : 0;
    var stacks = res.stacks || {};
    var anyGpu = false;

    statRows.forEach(function (row) {
      var project = row.dataset.project;
      var s       = stacks[project];
      var kids    = kidRows[row.dataset.stackRow] || [];

      if (!s) {
        blankFigures(row);
        kids.forEach(blankFigures);
        return;
      }

      paintFigures(row, project, s, dt, fresh);
      if (s.gpuMapped) anyGpu = true;

      // The snapshot already carries the per-container breakdown, so the rows
      // underneath cost no extra round trip — they are drawn from the same
      // reply the stack total came from.
      var byName = {};
      (s.containers || []).forEach(function (c) { byName[c.name] = c; });

      kids.forEach(function (kid) {
        var c = byName[kid.dataset.container];
        // No entry means the container is not running: `docker stats` only
        // reports live ones. Blank, not zero.
        if (!c) { blankFigures(kid); return; }

        paintFigures(kid, project + '::' + c.name, c, dt, fresh);
        if (c.gpuMapped) anyGpu = true;
      });
    });

    // Nothing on this page has a GPU, so the column is dropped entirely rather
    // than left as a full-height strip of empty cells. It reappears by itself
    // the moment a stack with a GPU starts.
    var table = document.querySelector('.stackman-stacks');
    if (table) table.classList.toggle('stackman-no-gpu', !anyGpu);

    updateFolderTotals();
    if (fresh) lastAt = res.sampledAt;
  }

  // A folder shows the sum of what is filed in it, including rows currently
  // hidden by a collapsed folder — that is the point of collapsing it.
  function updateFolderTotals() {
    document.querySelectorAll('[data-folder-row]').forEach(function (tr) {
      var id = tr.dataset.folderRow;
      var sum = { cpu: 0, mem: 0, net: 0, gpu: 0 };
      var any = false;
      var anyGpu = false;

      // Stack rows only. Container rows also carry data-in-folder, and adding
      // those in would count every stack twice — once whole, once in pieces.
      document.querySelectorAll('.stackman-stack-row[data-in-folder="' + id + '"]')
              .forEach(function (row) {
        if (!row.dataset.statCpu) return;
        any = true;
        sum.cpu += parseFloat(row.dataset.statCpu) || 0;
        sum.mem += parseFloat(row.dataset.statMem) || 0;
        sum.net += parseFloat(row.dataset.statNet) || 0;
        if (row.dataset.statGpuMapped) {
          anyGpu = true;
          sum.gpu += parseFloat(row.dataset.statGpu) || 0;
        }
      });

      var put = function (metric, text) {
        var td = tr.querySelector('[data-stat="' + metric + '"] .stackman-statv');
        if (td) td.innerHTML = text;
      };

      if (!any) {
        put('cpu', '—'); put('mem', '—'); put('net', '—'); put('gpu', '');
        return;
      }
      put('cpu', sum.cpu.toFixed(1) + '<small>%</small>');
      put('mem', bytes(sum.mem));
      put('net', rate(sum.net) + '<small>/s</small>');
      // Only folders holding a GPU stack get a GPU total.
      put('gpu', anyGpu ? sum.gpu.toFixed(1) + '<small>%</small>' : '');
    });
  }

  function pollStats() {
    // A hidden tab does not need updating, and stopping the asking is also
    // what lets the server-side collector shut itself down.
    if (document.hidden) return;
    // Nothing to fill in. Asking anyway would keep the collector sampling 60
    // containers on behalf of an empty table.
    if (!statRows.length) return;
    call('stats', {}, 10000).then(applyStats);
  }

  // Started on whether anything CAN run, not on whether there is a row right
  // now: the first stack added to an empty page arrives without a reload, and
  // a timer that was never set up would leave it with no figures at all.
  // Not gated on CAN_RUN: icons are worth having whether or not docker and
  // compose are usable, and a stack that cannot start still deserves a face.
  fetchIcons();

  if (CAN_RUN) {
    pollStats();
    statsTimer = setInterval(pollStats, STATS_POLL);
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) pollStats();
    });
  }
})();
