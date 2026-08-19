/* StaXX — behaviour for the Stacks screen.
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 * Plain browser JavaScript, no libraries. The page works on its own terms and
 * does not reach into anything Unraid renders.
 */

(function () {
  'use strict';

  var scaffold = document.querySelector('.staxx-scaffold');
  if (!scaffold) return;

  var ENDPOINT = scaffold.dataset.endpoint;
  var CSRF     = scaffold.dataset.csrf;
  var APPDATA  = scaffold.dataset.appdata || '';

  var modal       = document.getElementById('staxx-modal');
  var modalTitle  = document.getElementById('staxx-modal-title');
  var modalBody   = modal.querySelector('.staxx-modal-body');
  var nameField   = document.getElementById('staxx-name-field');
  var nameInput   = document.getElementById('staxx-name');
  var nameFolder  = document.getElementById('staxx-name-folder');
  var yamlPane    = document.getElementById('staxx-yaml');
  var yamlNums    = document.getElementById('staxx-yamlnums');
  var yamlMarks   = document.getElementById('staxx-yamlmarks');
  var yamlWrap    = document.getElementById('staxx-yamlwrap');
  var yamlInk     = document.getElementById('staxx-yamlink');
  // The problem markers' own layer, a sibling of the numbers inside the same
  // gutter div. May be null while the markup has not landed yet — guarded
  // wherever it is used, the same way paintInk() guards for YAML.highlight.
  var yamlDots    = yamlNums.querySelector('.staxx-yamldots');
  var yamlStatus  = document.getElementById('staxx-yaml-status');
  // The autocomplete list and the hover-help panel, both siblings of the
  // textarea inside yamlWrap. May be null while the markup has not landed
  // yet — guarded everywhere they are used, the same way yamlDots is above.
  var suggestBox  = document.getElementById('staxx-suggest');
  var keyHelp     = document.getElementById('staxx-keyhelp');
  var formHost    = document.getElementById('staxx-form');
  // The structure outline: a button in the modal header and the panel it
  // toggles open, both siblings inside .staxx-outlinewrap. May be null
  // while the markup has not landed yet — guarded everywhere below, the same
  // way suggestBox is guarded above.
  var outlineBtn   = document.getElementById('staxx-outline-btn');
  var outlinePanel = document.getElementById('staxx-outline');
  // The file tab strip above the find bar. May be null on a stale page, same
  // reasoning as outlinePanel above — guarded wherever it is touched.
  var tabsBar     = document.getElementById('staxx-tabs');
  // The active tab's menu (Rename / Delete / Download), a sibling of
  // tabsBar rather than nested in it — see the markup comment in
  // StacksPage.php. Same null-guarding reasoning as tabsBar above.
  var tabmenuPanel = document.getElementById('staxx-tabmenu');
  // The strip's own New file / Add a file controls and the hidden input
  // behind both of them (and behind Replace… on the binary panel below).
  // Same null-guarding reasoning as tabsBar above.
  var fileNewBtn = document.getElementById('staxx-file-new');
  var fileAddBtn = document.getElementById('staxx-file-add');
  var fileInput  = document.getElementById('staxx-file-input');
  // The panel shown instead of the textarea for a companion file that is
  // not text. Same null-guarding reasoning as tabsBar above.
  var binPanel = document.getElementById('staxx-binfile');
  var binName  = document.getElementById('staxx-binfile-name');
  var binMeta  = document.getElementById('staxx-binfile-meta');
  var binGet   = document.getElementById('staxx-binfile-get');
  var binPut   = document.getElementById('staxx-binfile-put');
  var sanitiseBox = document.getElementById('staxx-sanitise');
  var sanitiseNote = document.getElementById('staxx-sanitise-note');
  var refNote      = document.getElementById('staxx-refnote');
  var gapNote     = document.getElementById('staxx-required-note');
  var errorBox    = document.getElementById('staxx-error');
  var missingNote = document.getElementById('staxx-missing');
  var makePathsNote = document.getElementById('staxx-makepaths');
  var inUseNote     = document.getElementById('staxx-inusepaths');

  var tzModal     = document.getElementById('staxx-tz');
  var tzBands     = document.getElementById('staxx-tz-bands');
  var tzCaption   = document.getElementById('staxx-tz-caption');
  var tzChips     = document.getElementById('staxx-tz-chips');
  var tzSearch    = document.getElementById('staxx-tz-search');
  var tzList      = document.getElementById('staxx-tz-list');
  var tzMsg       = document.getElementById('staxx-tz-msg');

  var picker      = document.getElementById('staxx-picker');
  var pickerHere  = document.getElementById('staxx-picker-here');
  var pickerList  = document.getElementById('staxx-picker-list');
  var pickerMsg   = document.getElementById('staxx-picker-msg');
  var pickerNew   = document.getElementById('staxx-picker-newname');

  // The delete confirmation. May be null while the markup has not landed yet
  // on a stale page — guarded the same way suggestBox and findBar are above;
  // deleteStack() falls back to window.confirm() when it is missing.
  var confirmModal  = document.getElementById('staxx-confirm');
  var confirmTitle  = document.getElementById('staxx-confirm-title');
  var confirmBody   = document.getElementById('staxx-confirm-body');
  var confirmMsg    = document.getElementById('staxx-confirm-msg');
  var confirmCancel = document.getElementById('staxx-confirm-cancel');
  var confirmGo     = document.getElementById('staxx-confirm-go');

  // The Settings panel. May be null on a stale page — guarded the same way
  // confirmModal is above; openSettings() itself is a no-op without it.
  var settingsModal  = document.getElementById('staxx-settings');
  var settingsBody   = document.getElementById('staxx-settings-body');
  var settingsMsg    = document.getElementById('staxx-settings-msg');
  var settingsCancel = document.getElementById('staxx-settings-cancel');
  var settingsSave   = document.getElementById('staxx-settings-save');

  // The find/replace bar. May be null while the markup has not landed yet —
  // every function below that touches one of these guards on findBar first,
  // the same way yamlDots is guarded above.
  var findBar         = document.getElementById('staxx-find');
  var findWhat        = document.getElementById('staxx-find-what');
  var findCount       = document.getElementById('staxx-find-count');
  var findPrev        = document.getElementById('staxx-find-prev');
  var findNext        = document.getElementById('staxx-find-next');
  var findCase        = document.getElementById('staxx-find-case');
  var findRegex       = document.getElementById('staxx-find-regex');
  var findClose       = document.getElementById('staxx-find-close');
  var findReplaceRow  = document.getElementById('staxx-find-replacerow');
  var findWith        = document.getElementById('staxx-find-with');
  var findOne         = document.getElementById('staxx-find-one');
  var findAll         = document.getElementById('staxx-find-all');

  var YAML = window.StaxxYaml || null;

  var saveBtn  = document.getElementById('staxx-save');
  var startBtn = document.getElementById('staxx-save-start');
  var undoBtn  = document.getElementById('staxx-undo');

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
    { binder: 'list:cap_drop',   word: 'permission' },
    { binder: 'list:expose',     word: 'port' },
    { binder: 'list:env_file',   word: 'file' },
    // The long form of depends_on — groupsForService swaps the group's own
    // `add` binder to this one instead of 'list:depends_on' when the file
    // already uses long form, or has no depends_on at all (see the comment
    // there for why the default leans long).
    { binder: 'depends', word: 'service' },
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

  // Label, and where one exists a closed vocabulary, for every extra part
  // harvestLongExtras() (compose-model.js) can find on a long-form port,
  // mount, networks: map entry or secrets:/configs: entry — read (via
  // longExtraInfo() below) by longExtrasDevMoreHtml() to build the row's
  // 'more settings' toggle, and by choiceFor() to give the dropdown ones a
  // vocab. A key not listed here still renders, labelled with the key itself
  // — a compose setting we have never heard of must not vanish.
  var LONG_EXTRA_INFO = {
    'mode':                    { label: 'how it is published',        vocab: 'portmode' },
    'host_ip':                 { label: 'address on the server' },
    'name':                    { label: 'name for this port' },
    'type':                    { label: 'kind of mount',               vocab: 'volumetype' },
    'read_only':               { label: 'read-only',                   vocab: 'boolean' },
    'consistency':             { label: 'consistency' },
    'subpath':                 { label: 'path inside the source' },
    'bind.propagation':        { label: 'how mounts inside it are shared', vocab: 'propagation' },
    'bind.selinux':            { label: 'SELinux label',               vocab: 'selinux' },
    'bind.create_host_path':   { label: 'create the folder if it is missing', vocab: 'boolean' },
    'bind.recursive':          { label: 'include mounts underneath it', vocab: 'boolean' },
    'volume.nocopy':           { label: 'do not copy existing files in', vocab: 'boolean' },
    'volume.subpath':          { label: 'path inside the volume' },
    'tmpfs.size':              { label: 'size limit' },
    'tmpfs.mode':              { label: 'permissions' },
    'image.subpath':           { label: 'path inside the image' },

    // networks: written as a map — the entry's own settings beyond the name
    // (see harvestNetworksMap() in compose-model.js).
    'ipv4_address':            { label: 'fixed IPv4 address' },
    'ipv6_address':            { label: 'fixed IPv6 address' },
    'mac_address':             { label: 'fixed hardware address' },
    'priority':                { label: 'which network it prefers' },
    'gw_priority':             { label: 'which network it uses as its gateway' },
    'interface_name':          { label: 'name of the network interface inside the container' },
    // Both always render locked (harvestLongExtras() only ever gives them a
    // lockedPart — one is a list, the other a list of addresses), so neither
    // gets a vocab: there is no box to give one to.
    'aliases':                 { label: 'other names it answers to on this network' },
    'link_local_ips':          { label: 'extra link-local addresses' },

    // secrets:/configs: written the long way.
    'target':                  { label: 'file name inside the container' },
    'uid':                     { label: 'owning user id' },
    'gid':                     { label: 'owning group id' }
  };

  // secrets:/configs:' own mode: is a file permission ("0400"), not a port's
  // publish mode ("host"/"ingress") — the same part name from two different
  // compose keys, told apart by listKey rather than a second lookup table for
  // one entry. Both call sites below go through this rather than indexing
  // LONG_EXTRA_INFO directly.
  function longExtraInfo(f, name) {
    if (name === 'mode' && (f.listKey === 'secrets' || f.listKey === 'configs')) {
      return { label: 'file permission' };
    }
    return LONG_EXTRA_INFO[name];
  }

  // The groups a service's fields are sorted into, in the order they render.
  // `cls` picks the group's column template in the stylesheet, and `add`
  // (when set) is the binder passed through to the Add button on that group's
  // header line — the same ADDABLE binder the click handler already knows how
  // to act on.
  //
  // "formgroup", not "group": staxx-group is the stacks table's subgrid
  // wrapper, and its rule sets display and grid-template-columns on the bare
  // class.
  var GROUPS = [
    { key: 'container', heading: 'Container', cls: 'staxx-formgroup--container', note: '(required)' },
    // --mapped, not --pair: a port and a mount carry a third small box (the
    // protocol, the read/write mode) that a variable and a label do not, so
    // they take a five-track template while the other two keep the four.
    // `flag` gates whether the group shows at all (serviceFlags/the render
    // loop below) — every group in this table but Container and Advanced has
    // one now, driven by the SECTIONS table beside it.
    { key: 'list:networks',  heading: 'Networks',            cls: 'staxx-formgroup--single', add: 'list:networks',  flag: 'list:networks' },
    // --ports rides alongside --mapped rather than replacing it: a port row is
    // the same five-cell shape as a mount, but its two halves hold numbers,
    // not paths, so they get much narrower tracks and give the width back to
    // the note. It also adds two more tracks: a leading one for the
    // drag/reorder grip (PLAN_40) and a trailing one the WebUI chip lives in.
    { key: 'port',      heading: 'Ports',     cls: 'staxx-formgroup--mapped staxx-formgroup--ports', add: 'port',   flag: 'port' },
    // --volumes rides alongside --mapped the same way --ports does, and for
    // the same reason: it is what lets the two column shapes diverge without
    // Ports losing its own seven-track single line. A mount's server path is
    // long and unreadable in a track sized for a port number, so it gets a
    // two-line layout of its own — see the stylesheet rule this class picks.
    { key: 'volume',    heading: 'Volumes',   cls: 'staxx-formgroup--mapped staxx-formgroup--volumes', add: 'volume', flag: 'volume' },
    { key: 'env',       heading: 'Variables', cls: 'staxx-formgroup--pair',   add: 'env',    flag: 'env' },
    { key: 'device',    heading: 'Devices',   cls: 'staxx-formgroup--device', add: 'device', flag: 'device' },
    { key: 'label',     heading: 'Labels',    cls: 'staxx-formgroup--pair',   add: 'label',  flag: 'label' },
    // health, resources and build have no `add`: every one of their leaves is
    // always present as a field (harvestLeaves), so typing into a blank box
    // is what creates the line — an Add button would offer an action that
    // always fails. Same column shape as Advanced (label · value · note) —
    // reused as-is rather than given a template of their own.
    { key: 'health',     heading: 'Health check',     cls: 'staxx-formgroup--advanced', flag: 'health' },
    { key: 'resources',  heading: 'Resource limits',  cls: 'staxx-formgroup--advanced', flag: 'resources' },
    { key: 'build',      heading: 'Build',            cls: 'staxx-formgroup--advanced', flag: 'build' },
    { key: 'depends',    heading: 'Depends on', cls: 'staxx-formgroup--single',
      add: 'list:depends_on', flag: 'depends' },
    // The nine list sections used to be discovered per service, one group per
    // compose key the file happened to use (see groupsForService's history).
    // SECTIONS now names all of them, so they are static rows here too —
    // shown or hidden by their own tick, same as everything else in this list.
    { key: 'list:secrets',   heading: 'Secrets',             cls: 'staxx-formgroup--single', add: 'list:secrets',   flag: 'list:secrets' },
    { key: 'list:configs',   heading: 'Configs',             cls: 'staxx-formgroup--single', add: 'list:configs',   flag: 'list:configs' },
    { key: 'list:profiles',  heading: 'Profiles',            cls: 'staxx-formgroup--single', add: 'list:profiles',  flag: 'list:profiles' },
    { key: 'list:dns',       heading: 'DNS servers',         cls: 'staxx-formgroup--single', add: 'list:dns',       flag: 'list:dns' },
    { key: 'list:cap_add',   heading: 'Extra permissions',   cls: 'staxx-formgroup--single', add: 'list:cap_add',   flag: 'list:cap_add' },
    { key: 'list:cap_drop',  heading: 'Dropped permissions', cls: 'staxx-formgroup--single', add: 'list:cap_drop',  flag: 'list:cap_drop' },
    { key: 'list:expose',    heading: 'Internal ports',      cls: 'staxx-formgroup--single', add: 'list:expose',    flag: 'list:expose' },
    { key: 'list:env_file',  heading: 'Variable files',      cls: 'staxx-formgroup--single', add: 'list:env_file',  flag: 'list:env_file' },
    { key: 'logging',    heading: 'Logging',   cls: 'staxx-formgroup--advanced', flag: 'logging' },
    { key: 'advanced',  heading: 'Advanced',  cls: 'staxx-formgroup--advanced' }
  ];

  // Every switchable section, in the order it renders and the order the
  // Sections picker lists it. `path` is the compose key(s) stashSection,
  // restoreSection and setSectionState (compose-model.js) move — joined with
  // '.' for the entry name x-unraid.sections records it under. `on` is
  // whether a service with nothing recorded starts ticked. Container and
  // Advanced are not here: Container is required, and Advanced is the
  // catch-all for anything with no better home, so hiding it could hide
  // something the file genuinely has.
  var SECTIONS = [
    { key: 'list:networks', label: 'Networks',            path: ['networks'],            on: false },
    { key: 'port',          label: 'Ports',               path: ['ports'],               on: true },
    { key: 'volume',        label: 'Volumes',             path: ['volumes'],             on: true },
    { key: 'env',           label: 'Variables',           path: ['environment'],         on: true },
    { key: 'device',        label: 'Devices',             path: ['devices'],             on: true },
    { key: 'label',         label: 'Labels',              path: ['labels'],              on: true },
    { key: 'health',        label: 'Health check',        path: ['healthcheck'],         on: false },
    { key: 'resources',     label: 'Resource limits',     path: ['deploy', 'resources'], on: false },
    // Most services pull a ready-made image, so Build starts unticked the
    // same as health/resources — the file itself flips it on when a service
    // already has a build: key (serviceFlags/fileFlagCounts).
    { key: 'build',         label: 'Build',               path: ['build'],               on: false },
    { key: 'depends',       label: 'Depends on',          path: ['depends_on'],          on: false },
    { key: 'list:secrets',  label: 'Secrets',             path: ['secrets'],             on: false },
    { key: 'list:configs',  label: 'Configs',             path: ['configs'],             on: false },
    { key: 'list:profiles', label: 'Profiles',            path: ['profiles'],            on: false },
    { key: 'list:dns',      label: 'DNS servers',         path: ['dns'],                 on: false },
    { key: 'list:cap_add',  label: 'Extra permissions',   path: ['cap_add'],             on: false },
    { key: 'list:cap_drop', label: 'Dropped permissions', path: ['cap_drop'],            on: false },
    { key: 'list:expose',   label: 'Internal ports',      path: ['expose'],              on: false },
    { key: 'list:env_file', label: 'Variable files',      path: ['env_file'],            on: false },
    { key: 'logging',       label: 'Logging',             path: ['logging'],             on: false }
  ];
  var SECTIONS_BY_KEY = {};
  for (var _si = 0; _si < SECTIONS.length; _si++) SECTIONS_BY_KEY[SECTIONS[_si].key] = SECTIONS[_si];

  // The caption text named per group; container has no trailing blank for a ×
  // column, since it never grows one.
  var CAPTIONS = {
    container: ['setting', 'value', 'note, kept in the file'],
    // Ports are captioned in single words where mounts get phrases: the two
    // boxes hold a five-digit number, so the columns are narrow and anything
    // longer wrapped the heading onto a second line. The fuller wording is
    // still what a screen reader hears — see the hints passed to boxHtml().
    port:      ['Host', 'Container', 'protocol', 'note, kept in the file'],
    // Two headings only: a mount is a two-line row now (see
    // .staxx-formgroup--volumes), and its second line — mode and note — has
    // no caption above it, the same reason Devices' own hint stays visible
    // (see the .staxx-boxhint exception in the stylesheet).
    volume:    ['path on the server', 'path in the container'],
    device:    ['device', 'note, kept in the file'],
    env:       ['variable name', 'value', 'note, kept in the file'],
    label:     ['label name', 'value', 'note, kept in the file'],
    health:    ['setting', 'value', 'note, kept in the file'],
    resources: ['setting', 'value', 'note, kept in the file'],
    build:     ['setting', 'value', 'note, kept in the file'],
    // Short form only this phase — one box per dependency, the same shape as
    // any other dynamic list. The three-column shape phase 1 wrote here was
    // for the long form's name/condition pair, which does not exist yet.
    depends:   ['service', 'note, kept in the file'],
    logging:   ['setting', 'value', 'note, kept in the file'],
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
    // usually editable now (readTest()/writeTest(), PLAN_8 phase 4), but a
    // shape it cannot confidently read still locks the same as any other
    // leaf — and it IS the check that healthcheck.interval and friends time,
    // so it belongs in Health check regardless, not off in Advanced.
    if (/^healthcheck\./.test(f.target)) return 'health';
    // Narrowed to deploy.resources.* only. deploy.replicas, deploy.placement
    // and the rest of deploy: are ordinary settings, not resource limits —
    // the Resource limits tick is derived from deploy.resources.* alone
    // (serviceFlags), so catching all of deploy: here would hide a sibling
    // key like replicas behind an off tick. The form must never hide
    // something the file has.
    if (/^deploy\.resources\./.test(f.target)) return 'resources';
    // logging.driver (and the whole logging: block, when it is some shape the
    // form cannot open) belongs beside its own tick, not off in Advanced —
    // same reasoning as healthcheck/deploy.resources above.
    if (/^logging(\.|$)/.test(f.target)) return 'logging';
    // Same reasoning as health/resources/logging above: a build leaf can lock
    // (e.g. build.cache_from, a map or list child harvestBlock's second loop
    // emits read-only) and must still land beside its editable siblings in
    // Build, not be exiled to Advanced by the locked test below. Matches the
    // short form too — build: ./app is the target build with no dot.
    if (/^build(\.|$)/.test(f.target)) return 'build';
    // Short-form depends_on shares the Depends on group with the long form —
    // which stays a locked field in Advanced until phase 5 (see f.locked
    // below), so only the short form's own list entries are routed here.
    if (f.binder === 'list' && f.listKey === 'depends_on') return 'depends';
    // Checked ahead of the locked test below, for the same reason
    // healthcheck.test is: a long-form dependency this cannot confidently
    // read (the inline flow form, or anything stranger) still locks, but
    // belongs beside its editable siblings in Depends on, not exiled to
    // Advanced.
    if (f.binder === 'depends') return 'depends';
    if (f.locked) return 'advanced';
    if (f.binder === 'setting') return 'advanced';
    if (f.binder === 'list') return 'list:' + f.listKey;
    return f.binder;
  }

  // A service's own group list: the static table, with Depends on's row shape
  // swapped for whichever of the two forms (short list, long name/condition
  // pairs) the file's depends_on actually uses. Every other group is static
  // now — SECTIONS names all nine list keys and logging up front, so there is
  // nothing left to discover per service the way list groups once were.
  function groupsForService(fields, serviceName) {
    var head = [], tail = [], i;
    for (i = 0; i < GROUPS.length; i++) (GROUPS[i].key === 'advanced' ? tail : head).push(GROUPS[i]);

    // Depends on has two row shapes, one per form the file's depends_on can
    // take — short (one box per name) or long (name and condition, restart/
    // required folded below). GROUPS' own 'depends' entry is shared by every
    // service's render, so the shape actually used here is a clone, never a
    // mutation of it. Long form wins when the file has any; with neither
    // form present at all, long form is still the default — it is what the
    // Add button on an empty group has to write (PLAN_7.md's condition:
    // service_started rule leaves no way to write a bare short-form add here
    // anyway once nothing exists yet to copy the shape from).
    var longForm = false, shortForm = false;
    for (i = 0; i < fields.length; i++) {
      var df = fields[i];
      if (df.service !== serviceName) continue;
      if (df.binder === 'depends' && !df.fold) longForm = true;
      else if (df.binder === 'list' && df.listKey === 'depends_on') shortForm = true;
    }
    for (i = 0; i < head.length; i++) {
      if (head[i].key !== 'depends') continue;
      head[i] = (longForm || !shortForm)
        ? { key: 'depends', heading: 'Depends on', cls: 'staxx-formgroup--pair',
            add: 'depends', flag: 'depends',
            cols: ['service', 'wait until', 'note, kept in the file'] }
        : { key: 'depends', heading: 'Depends on', cls: 'staxx-formgroup--single',
            add: 'list:depends_on', flag: 'depends' };
      break;
    }
    return head.concat(tail);
  }

  // Which section (if any) a field's presence counts towards. Broader than
  // groupFor()'s routing for depends: this also catches the long-form
  // depends_on map, which groupFor still exiles to Advanced as a locked
  // field (phase 5) but which just as surely means the file already has
  // dependencies, so the tick has to read true for it. Everything else falls
  // through to groupFor(), which already sorts port/volume/list:dns/etc. into
  // the same keys SECTIONS uses — a field groupFor sends to Advanced is
  // simply not one of them, and is ignored here too.
  function flagFor(f) {
    if (/^healthcheck\./.test(f.target)) return 'health';
    if (/^deploy\.resources\./.test(f.target)) return 'resources';
    // binder 'depends' covers the long form (PLAN_8 phase 5) — every one of
    // its fields, folded restart/required included, so "how many settings
    // will be deleted" counts them the same way health/resources already do.
    // The other two catch the short list form and the still-locked inline
    // flow map, which carries no binder of its own.
    if (f.binder === 'depends' || f.listKey === 'depends_on' || f.target === 'depends_on') return 'depends';
    if (/^logging(\.|$)/.test(f.target)) return 'logging';
    var g = groupFor(f);
    return SECTIONS_BY_KEY[g] ? g : null;
  }

  // How many of a service's fields the file genuinely holds for each section —
  // an absent leaf (harvestLeaves' placeholder for a line the file does not
  // have) never counts. Used both to decide a tick's starting state and to
  // tell the stash/setSectionState choice in the change listener below apart.
  function fileFlagCounts(form, name) {
    var out = {};
    for (var si = 0; si < SECTIONS.length; si++) out[SECTIONS[si].key] = 0;
    for (var i = 0; i < form.fields.length; i++) {
      var f = form.fields[i];
      if (f.service !== name || f.absent) continue;
      var k = flagFor(f);
      if (k) out[k]++;
    }
    return out;
  }

  /* Sections switched on but still empty, as sectionOn[service][key]. This is
   * the page's own state and is never written to the file: a section with
   * nothing in it has nothing to record, and writing a marker for one would
   * put an x-unraid block into a file that had no need of one. Adding the
   * first entry makes the file hold the section itself, which is what carries
   * the state from then on.
   *
   * The trade is deliberate: tick a section on, add nothing, save and reopen,
   * and it is hidden again — having lost nothing, because there was nothing in
   * it. Cleared whenever the editor opens a stack, beside sectionsOpen. */
  var sectionOn = {};

  // Whether each switchable section shows for a service: on when the file
  // genuinely holds it; else off when x-unraid.sections marks it hidden
  // (false, or a stash with lines still in it — see sectionHidden()); else on
  // when it has been ticked on in this editor; else the section's own default.
  function serviceFlags(form, name) {
    var counts = fileFlagCounts(form, name);
    var sections = YAML.readSections(form.doc)[name] || {};
    var open = sectionOn[name] || {};
    var out = {};
    for (var i = 0; i < SECTIONS.length; i++) {
      var s = SECTIONS[i];
      if (counts[s.key] > 0) { out[s.key] = true; continue; }
      var entry = sections[s.path.join('.')];
      // A stashed entry is a truthy object, but it means the block was taken
      // OUT of the file — so hidden must be checked before treating entry
      // itself as a sign the section is on.
      out[s.key] = YAML.sectionHidden(entry) ? false : open[s.key] ? true : s.on;
    }
    return out;
  }

  var logPanel = document.getElementById('staxx-log-panel');
  var logTitle = document.getElementById('staxx-log-title');
  var logBox   = document.getElementById('staxx-log');

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
                   'php -r \'require "/usr/local/emhttp/plugins/staxx/include/Stacks.php"; ' +
                   'print_r(staxx_selftest());\''
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
    input.className = 'staxx-inline-name';
    input.spellcheck = false;
    // The same opt-outs NOFILL carries, set one at a time because this box is
    // built as an element rather than as a string of markup.
    input.autocomplete = 'off';
    input.setAttribute('data-1p-ignore', '');
    input.setAttribute('data-lpignore', 'true');
    input.setAttribute('data-bwignore', '');
    input.setAttribute('data-form-type', 'other');
    input.setAttribute('data-protonpass-ignore', 'true');
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

  // Mirrors staxx_valid_name() in include/Stacks.php — a folder is a real
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
  var composeEol  = '\n';  // the compose file's own line ending — put back on save(), see withEol()
  var openedName  = '';    // the rel this editor opened at — what save() renames FROM
  var serviceRenamed = false;  // a pencil rename happened this session — offer a recreate after save

  // The tab strip's own state. FILES is the last `files` listing, in the
  // order it arrived — compose file first, then alphabetical (see
  // staxx_list_files() in Stacks.php). fileOpen is the companion filename
  // on screen, or null while the compose file itself is showing.
  var FILES      = [];
  var fileOpen   = null;
  var fileStash  = '';    // the compose file's text, held aside while a companion is shown
  var fileAtLoad = '';    // what the companion said when it was loaded — its dirty check
  var fileEol    = '\n';  // the companion's own line ending — put back on runFileSave(), see withEol()
  // Whether the box is holding a companion's real text. False for a binary,
  // for a read that failed, and for the moment before one lands — and the
  // autosave refuses to write anything while it is false. See loadCompanion().
  var fileEditable = false;
  var viewBeforeFile = null;   // the view (form/split/yaml) to restore on the way back to Compose
  // filename -> the MIME type the browser reported when the bytes last
  // arrived from this computer (upload or Replace…). The server has no
  // reliable way to know a file's type, so this only ever holds an entry for
  // a file this browser session itself picked up — everything else shows
  // size alone.
  var fileMime = {};
  // The companion filename Replace… is standing in for, while its own
  // single-file picker is open — null the rest of the time. Read by the
  // file input's change handler to tell that one pick apart from an
  // ordinary multi-file add.
  var fileReplaceTarget = null;

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
    // While a companion file is on screen the box is not showing the compose
    // file at all, so everything that asks "what does the compose file say" —
    // the dirty check, Save, the parser — has to be told about the copy held
    // aside rather than reading a .env and calling it YAML.
    if (fileOpen !== null) return fileStash;
    return sanitised ? realText : yamlPane.value;
  }

  function isDirty() {
    return currentText() !== textAtOpen;
  }

  // A textarea always hands its value back as LF, whatever went into it, so a
  // file that arrived with Windows line endings needs them put back on the
  // way out — otherwise saving rewrites every line of a file nobody edited.
  //
  // The one place this project's "only what changed is written" promise does
  // not hold literally: composeEol is decided by whether the file held ANY
  // CRLF, so a file with mixed endings comes out wholly CRLF, rewriting even
  // lines nobody touched. A textarea gives no way to know which lines ended
  // how, and a consistent file beats a half-converted one — but it is a
  // rewrite, and pretending otherwise in this comment would be worse.
  function withEol(text, eol) {
    return eol === '\r\n' ? text.replace(/\n/g, '\r\n') : text;
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
    // The ink layer draws its own text, so it needs the textarea's own left
    // padding, not the bands' — otherwise the colouring sits under the gutter.
    yamlInk.firstElementChild.style.paddingLeft = (w + 9) + 'px';
  }

  // What paintInk() drew last time, so ordinary typing repaints only the one
  // line that changed. carryAfter[i] is the tokeniser's carry state at the END
  // of line i — needed because opening a "|" block scalar recolours every line
  // below it, and closing one recolours them back.
  var inkLines   = [];
  var carryAfter = [];

  // A compose file this large is pathological, and painting per-line colour on
  // every keystroke would make typing laggy — a slow editor is worse than a
  // plain-coloured one, so past this size the ink layer is switched off.
  var INK_LIMIT = 3000;

  function paintInk() {
    // A companion file is never YAML — colouring a .env with the YAML
    // tokeniser would light it up as if it were broken syntax. plainInk()
    // below is the whole of what a companion file gets instead.
    if (fileOpen !== null) { plainInk(); return; }
    if (modalBody.dataset.view === 'form') return;
    if (!YAML || typeof YAML.highlight !== 'function') return;   // not landed yet

    var text  = yamlPane.value;
    var lines = text.split('\n');
    var inner = yamlInk.firstElementChild;

    var big = lines.length > INK_LIMIT;
    yamlWrap.classList.toggle('staxx-noink', big);
    if (big) {
      inner.textContent = '';
      inkLines   = [];
      carryAfter = [];
      return;
    }

    // The class is not decoration: without it an empty line has no line box at
    // all, collapses to nothing, and every line below it drifts up out of step
    // with the textarea and the gutter. The sheet's :empty::after puts the
    // height back.
    while (inner.children.length < lines.length) {
      var row = document.createElement('div');
      row.className = 'staxx-inkline';
      inner.appendChild(row);
    }
    while (inner.children.length > lines.length) inner.lastElementChild.remove();

    // Read from the OLD carry array throughout — overwriting it as we go would
    // make a line's "did my incoming carry change" check compare against a
    // value this same pass already rewrote, instead of what was really there
    // before the edit.
    var oldCarry = carryAfter;
    var newCarry = new Array(lines.length);
    var carry = '';

    for (var i = 0; i < lines.length; i++) {
      var carryIn    = carry;
      var oldCarryIn = i === 0 ? '' : oldCarry[i - 1];

      // Unchanged text with an unchanged carry in front of it tokenises to
      // exactly what it did last time, so neither the work nor the DOM write
      // is worth doing — which is what makes ordinary typing cost one line.
      //
      // It cannot stop the walk early, though, and an earlier version that did
      // was wrong: an edit is not always one contiguous run. An undo, a paste
      // over a selection, or a model write can change line 2 and line 40 and
      // leave line 3 alone — and stopping at line 3 left line 40 showing the
      // text it used to hold.
      if (lines[i] === inkLines[i] && carryIn === oldCarryIn &&
          oldCarry[i] !== undefined) {
        newCarry[i] = oldCarry[i];
        carry = oldCarry[i];
        continue;
      }

      var res = YAML.highlight(lines[i], carryIn);
      inner.children[i].innerHTML = res.html;
      inkLines[i] = lines[i];
      newCarry[i] = res.carry;
      carry = res.carry;
    }

    inkLines.length = lines.length;
    carryAfter = newCarry;
  }

  // A companion file's colouring: three cases only, no carry state between
  // lines because none of them (comment, key=value, plain text) can span one.
  function plainInk() {
    // This is about to overwrite the very layer paintInk() keeps a record of,
    // so that record stops being true here. Left standing, paintInk() compares
    // the compose file against it on the way back, finds every line
    // "unchanged", repaints none of them, and leaves the layer holding this
    // file's lines — or, for an empty one, nothing at all. The textarea's own
    // text is transparent, so that reads as a blank editor.
    inkLines   = [];
    carryAfter = [];

    var lines = yamlPane.value.split('\n');
    var inner = yamlInk.firstElementChild;

    // Same escape hatch paintInk() has, and for the same reason: a file long
    // enough to need one div per line costs more to colour than the colour is
    // worth. .staxx-noink hides the overlay and makes the textarea's own
    // text visible again, so the file is still perfectly readable.
    var big = lines.length > INK_LIMIT;
    yamlWrap.classList.toggle('staxx-noink', big);
    if (big) { inner.textContent = ''; return; }

    while (inner.children.length < lines.length) {
      var row = document.createElement('div');
      row.className = 'staxx-inkline';
      inner.appendChild(row);
    }
    while (inner.children.length > lines.length) inner.lastElementChild.remove();

    for (var i = 0; i < lines.length; i++) {
      var line = lines[i], html, kv;
      if (/^\s*#/.test(line)) {
        html = '<span class="staxx-t--comment">' + esc(line) + '</span>';
      } else if ((kv = /^(\s*[A-Za-z_][A-Za-z0-9_]*)(=)(.*)$/.exec(line))) {
        html = '<span class="staxx-t--key">' + esc(kv[1]) + '</span>' +
               '<span class="staxx-t--punct">' + esc(kv[2]) + '</span>' +
               '<span class="staxx-t--str">' + esc(kv[3]) + '</span>';
      } else {
        html = '<span class="staxx-t--text">' + esc(line) + '</span>';
      }
      inner.children[i].innerHTML = html;
    }
  }

  // Problems are sparse — a handful at most — so one element per problem,
  // never one per line the way the ink layer works. `list` is whatever
  // YAML.lint() returned (plus, after a refused save, one extra entry — see
  // markSaveError()). Cleared and rebuilt in full on every call, since there
  // are never enough of them for that to be worth avoiding.
  function paintDots(list) {
    // A companion file has no compose lint to show, and the compose file's
    // line numbers mean nothing over its text — so the gutter is cleared
    // rather than left carrying the last file's marks. In Split those marks
    // are on screen beside the file, not hidden with the pane.
    if (fileOpen !== null) { if (yamlDots) yamlDots.textContent = ''; return; }
    if (modalBody.dataset.view === 'form') return;   // nothing on screen to paint into
    if (!yamlDots) return;                            // markup not landed yet
    yamlDots.textContent = '';

    if (!LINE_H) measure();
    list = list || [];
    for (var i = 0; i < list.length; i++) {
      var item = list[i];
      if (item.line < 0) continue;   // whole-file problems have no line — setYamlStatus() shows those instead
      var dot = document.createElement('div');
      dot.className = 'staxx-yamldot staxx-yamldot--' + (item.level === 'warn' ? 'warn' : 'error');
      // The MIDDLE of the line, not its top. A band fills the whole line so its
      // top is the line's top; a dot is a few pixels tall and set that way sits
      // against the line above's descenders. The sheet pulls it back half its
      // own height, which keeps this free of any idea how big the dot is.
      //
      // No scroll term here, unlike repaintMark(): the marks layer is not
      // moved on scroll and so has to subtract scrollTop itself, whereas this
      // layer is inside the gutter and syncGutter() translates it.
      dot.style.top = (PAD_T + (item.line + 0.5) * LINE_H) + 'px';
      dot.title = item.message;
      yamlDots.appendChild(dot);
    }
  }

  function syncGutter() {
    yamlNums.firstElementChild.style.transform =
      'translateY(' + (-yamlPane.scrollTop) + 'px)';
    // The dots live in the same gutter as the numbers, positioned the same
    // unshifted way, so they need the same vertical carry — never horizontal,
    // since the gutter itself never scrolls sideways.
    if (yamlDots) {
      yamlDots.style.transform = 'translateY(' + (-yamlPane.scrollTop) + 'px)';
    }
    // Unlike the number gutter, ink is full-width text — it has to track the
    // textarea's horizontal scroll too, or it drifts out from under the words
    // the moment a long line is scrolled sideways.
    yamlInk.firstElementChild.style.transform =
      'translate(' + (-yamlPane.scrollLeft) + 'px, ' + (-yamlPane.scrollTop) + 'px)';
  }

  yamlPane.addEventListener('input',  paintGutter);
  yamlPane.addEventListener('input',  paintInk);
  yamlPane.addEventListener('scroll', function () {
    syncGutter(); repaintMark();
    hideSuggest(); hideHover();   // both are pixel-positioned; a scroll invalidates them
  });

  // Re-reading the file and redrawing the form is the expensive direction, so
  // it waits for a pause in typing rather than running on every keystroke.
  var yamlTimer = null;

  yamlPane.addEventListener('input', function () {
    if (fileOpen !== null) return;   // a companion file has no compose model to reparse
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
    // A companion file's editor is the compose pane itself, so Form alone
    // would hide the very file the tab opened. Split is what shows the compose
    // form beside it, and the order here matters: the narrow coercion runs
    // first, and this re-handles what it produced.
    if (fileOpen !== null && view === 'form') view = NARROW.matches ? 'yaml' : 'split';
    modalBody.dataset.view = view;
    // Both panels are positioned in pixels against the compose pane, which a
    // view switch just moved or hid outright.
    hideSuggest();
    hideHover();
    var btns = modal.querySelectorAll('.staxx-viewbtn');
    for (var i = 0; i < btns.length; i++) {
      btns[i].setAttribute('aria-pressed', btns[i].dataset.view === view ? 'true' : 'false');
    }
    // A band measured while its pane was hidden is a band in the wrong place.
    if (view !== 'form') {
      paintGutter(); paintInk(); syncGutter(); repaintMark();
      // Repaint from what is already known rather than re-linting — the
      // document has not changed just because the pane became visible.
      redrawDots();
    }
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

  // Whether the Stack section's <details> is open. renderForm() rebuilds the
  // whole form from scratch on every structural edit, so nothing the DOM
  // itself remembers survives an add, remove or undo — this is the session's
  // memory of it instead.
  var stackOpen = false;

  // Whether a service's Sections panel is open. Same reason stackOpen exists:
  // the panel is rendered by renderForm() itself (see groupHeadHtml), not
  // built by hand on click, because every tick reparses and redraws the whole
  // form — a hand-built panel would be destroyed by the first tick made
  // inside it. This map is what survives that redraw.
  var sectionsOpen = {};

  // Whether a networks: entry's "more settings" fold is open, keyed by field
  // id rather than by row — an id is stable across the reparse a promote
  // (PLAN_34 phase 5) causes, since the entry keeps its name and position, so
  // this is what stops the very click that promotes a list entry from also
  // closing the fold it just opened. Same reason stackOpen/sectionsOpen exist.
  var netFoldOpen = {};

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
      vocab: 'restart'
    },
    'setting/network_mode': {
      hint: 'which network the container joins',
      vocab: 'netmode'
    },
    // Values are readTest()/writeTest()'s own words (compose-model.js), not
    // the compose keywords (CMD-SHELL/CMD/NONE) — the dropdown only ever
    // shows the plain-English label.
    'setting/healthcheck.test.mode': {
      hint: 'how the check itself is run',
      options: [
        ['shell', 'run a shell line'],
        ['cmd',   'run a program'],
        ['none',  'no check']
      ]
    },
    // Not reached by the binder+target key every other entry here uses — a
    // dependency's target carries its own name (depends_on.db, ...), so
    // boxHtml() looks this one up directly by binder alone. Compose's own
    // condition values, given plain-English labels the same way restart's are.
    'depends/condition': {
      hint: 'when the dependency counts as ready',
      vocab: 'dependscondition'
    },
    'setting/pull_policy': {
      hint: 'when to check for a newer image',
      vocab: 'pullpolicy'
    },
    'setting/stop_signal': {
      hint: 'which signal asks the container to stop',
      vocab: 'stopsignal'
    },
    // Which values ipc's vocabulary carries — and leaves "container:<name>"
    // out on purpose — is compose-model.js's call now (see its 'ipc' vocab
    // and PLAN.md's "everyday enum set only" call). service: options are
    // still joined per call here, below.
    'setting/ipc': {
      hint: 'which IPC namespace the container joins',
      vocab: 'ipc'
    },
    'setting/pid': {
      hint: 'which process namespace the container joins',
      vocab: 'pid'
    },
    // These four vocabularies already existed, for the editor's own
    // autocomplete — see compose-model.js's VOCAB_AT comment noting they
    // were reached "through SERVICE_SPEC_KEYS, not KEYS", meaning no form
    // control used them. These entries are what closes that gap and gives
    // the form the same dropdown.
    'setting/cgroup': {
      hint: 'which cgroup namespace the container uses',
      vocab: 'cgroup'
    },
    'setting/isolation': {
      hint: 'which isolation technology the container uses',
      vocab: 'isolation'
    },
    'setting/userns_mode': {
      hint: 'whose user ID range the container uses',
      vocab: 'usernsmode'
    },
    'setting/uts': {
      hint: 'whose hostname and domain name the container uses',
      vocab: 'uts'
    },
    'setting/logging.driver': {
      hint: 'where this container’s logs are sent',
      vocab: 'logdriver'
    },
    // A short-form port's protocol and a short-form volume's mode (see
    // splitPortShort()/splitPathShort() in compose-model.js): the value
    // carries its own separator, so the empty option writes the separator
    // away too rather than needing a special case for "nothing chosen".
    //
    // Two options, not three: '' and '/tcp' mean the same thing to Docker, and
    // offering both read as one option listed twice. A file that already spells
    // out '/tcp' keeps it — optionsHtml() puts any value it does not recognise
    // at the top of the list — so nothing is rewritten behind anyone's back.
    'port/proto': {
      hint: 'which protocol this port uses',
      options: [
        ['',     'tcp — the default'],
        ['/udp', 'udp']
      ]
    },
    // The long form's own protocol: line — a bare word with no separator to
    // strip, so unlike port/proto above it draws on compose-model.js's
    // 'protocol' vocabulary rather than carrying its own options here.
    'port/protocol': {
      hint: 'which protocol this port uses',
      vocab: 'protocol'
    },
    'volume/mode': {
      hint: 'whether the container can write to this mount',
      options: [
        // Short enough to read whole in the narrow track the mode box now
        // sits in on a mount's second line — the two write options still read
        // apart from each other, and the hint below says what the box is for.
        ['',    'read and write'],
        [':ro', 'read-only'],
        [':rw', 'read and write, spelled out']
      ]
    },
    // A declaration's driver is folded onto the row's OWN value box (see
    // DECL_PRIMARY in compose-model.js), never a suffix on its target, so
    // choiceFor() below looks these up by declKind directly rather than by
    // the binder+target key every other entry here uses.
    'declared/networks.driver': {
      hint: 'how Docker implements this network',
      vocab: 'networkdriver'
    },
    // Why only 'local' is listed, and why an unlisted value still joins the
    // file as it stands, is compose-model.js's 'volumedriver' vocab's call
    // now — see its own comment there.
    'declared/volumes.driver': {
      hint: 'what manages the storage behind this volume',
      vocab: 'volumedriver'
    }
  };

  // A boolean field's dropdown, worded for what the setting actually does —
  // f.type === 'boolean' is the trigger (see compose-model.js's booleanTail()
  // and KEYS[...].type), never a hand-kept list of key names, but the WORDING
  // still benefits from knowing which setting it is. Keyed the same way
  // booleanTail() tells the two dynamically-named cases apart (a dependency's
  // required, a declaration's external) from the five statically-named ones.
  var BOOL_CHOICES = {
    'privileged': {
      hint: 'how much access to the host this container gets',
      options: [['true', 'true — full access to the host'],
                ['false', 'false — normal, isolated']]
    },
    'read_only': {
      hint: 'whether the container can write to its own filesystem',
      options: [['true', 'true — read-only filesystem'],
                ['false', 'false — normal, writable filesystem']]
    },
    'init': {
      hint: 'whether a tiny init process runs as PID 1',
      options: [['true', 'true — runs one, to clean up stray processes'],
                ['false', 'false — the container’s own process is PID 1']]
    },
    'tty': {
      hint: 'whether the container gets a terminal',
      options: [['true', 'true — allocates one, as if run interactively'],
                ['false', 'false — no terminal']]
    },
    'stdin_open': {
      hint: 'whether standard input stays open',
      options: [['true', 'true — keeps it open, as if run interactively'],
                ['false', 'false — closes it at once']]
    },
    'healthcheck.disable': {
      hint: 'whether the health check above is switched off',
      options: [['true', 'true — disabled, even though one is written above'],
                ['false', 'false — runs as written above']]
    },
    'depends.required': {
      hint: 'whether this dependency must succeed for the service to start',
      options: [['true', 'true — must start successfully, or this service will not start'],
                ['false', 'false — allowed to fail without stopping this service']]
    },
    'declared.external': {
      hint: 'whether this already exists outside the file',
      options: [['true', 'true — already exists; this file only refers to it'],
                ['false', 'false — created by this file']]
    }
  };
  // Guarded the way safeFieldHelp/YAML.keySuggestions already are (see
  // below) — a stale cached compose-model.js must leave a plain box or a
  // datalist with no suggestions rather than throw. Returns null, never [],
  // so a caller can tell "not landed yet" apart from "no values".
  function safeVocab(id) {
    if (!YAML || typeof YAML.vocab !== 'function') return null;
    return YAML.vocab(id);
  }

  // Guarded the same way — the `default` network and no-self-reference rule
  // now live in compose-model.js's refNames() (shared with the editor's own
  // suggestions), so a stale cached copy falls back to the plain name list
  // rather than losing the dropdown entirely.
  function safeRefNames(names, kind, serviceName) {
    if (!YAML || typeof YAML.refNames !== 'function') return names;
    return YAML.refNames(names, kind, serviceName);
  }

  function boolChoice(f) {
    var kind = f.binder === 'setting' ? f.target
             : f.binder === 'depends'  && /\.required$/.test(f.target) ? 'depends.required'
             : f.binder === 'declared' && /\.external$/.test(f.target) ? 'declared.external'
             : '';
    if (BOOL_CHOICES[kind]) return BOOL_CHOICES[kind];
    var options = safeVocab('boolean');
    return options ? { hint: 'true or false', options: options } : null;
  }

  // The Linux capability names cap_add/cap_drop accept — a suggestion list,
  // not a closed one (see the datalist branch in boxHtml()), because compose
  // also accepts CAP_-prefixed spellings and custom ones this table cannot
  // know about. The names themselves, and their "what does that mean"
  // labels, now live in compose-model.js's 'capability' vocab — see
  // choiceFor()'s cap_add/cap_drop case below.

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
  // once they arrive — see netLoad() far below, beside devLoad(). Held apart
  // from the vocab table itself (unlike the old CHOICES entry, which netLoad()
  // used to push straight onto) because YAML.vocab() now hands back a fresh
  // copy every call — a push onto that copy would vanish before the next
  // redraw. Joined at render time in choiceFor(), the same way IMAGES/
  // imageOptions() below is never folded into the image vocab either.
  var netLoaded  = false;
  var NETWORKS   = [];      // [name, label] pairs found on this server, beyond netmode's own

  // Just the names, for YAML.lint()'s network_mode check. null — not [] —
  // until the server has answered, because "we do not know yet" and "there
  // are none" have to lead to different behaviour: the first must not let a
  // real network like br0 be reported as a typo of bridge.
  function netNames() {
    if (!netLoaded) return null;
    var out = [];
    for (var i = 0; i < NETWORKS.length; i++) out.push(NETWORKS[i][0]);
    return out;
  }

  // Images already on this server (IMAGES, up near choiceFor()) and, per
  // repo, its tags from the registry — see imgLoad()/tagLoad() far below.
  var imgLoaded  = false;
  var tagCache   = {};      // repo -> tags[], including [] for "nothing found"
  var tagTimer   = null;

  // Compose also accepts forms a vocab list does not carry — restart's
  // "on-failure:3" is one such value, kept in compose-model.js's own comment
  // now — so a value already in the file that is not on the list joins it as
  // it stands. A dropdown that could not show the current value would change
  // the file just by being opened.
  // A `from` field points at a namespace the file declares once at the top —
  // networks:, secrets:, configs:, services: — and offers those real names
  // instead of a box to spell one in. The `default` network and no-self rule
  // are refNames()'s own, in compose-model.js, so the editor's suggestions
  // agree with this dropdown rather than each holding its own copy.
  function fromChoice(f) {
    var names = safeRefNames((MODEL && MODEL.declared && MODEL.declared[f.from]) || [], f.from, f.service);
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

  // network_mode, ipc and pid all also accept "<prefix><name>" — join another
  // service's namespace instead of getting one of its own — one option per
  // OTHER service in the file (a service cannot share its own). `what` names
  // the thing being shared, since the three settings do not share one. Built
  // fresh on every call rather than folded into CHOICES the way netLoad()
  // appends the server's own docker networks: that table is shared by every
  // service's row, and mutating it here would leak one service's option
  // list into every other service's dropdown.
  function serviceModeOptions(serviceName, prefix, what) {
    var names = safeRefNames((MODEL && MODEL.declared && MODEL.declared.services) || [], 'services', serviceName);
    var options = [];
    for (var i = 0; i < names.length; i++) {
      options.push([prefix + names[i],
                    prefix + names[i] + ' — share ' + names[i] + '’s ' + what]);
    }
    return options;
  }

  // Returns both the <option> markup and whether the file's own value was on
  // the list — boxHtml() below folds `known` into the <select>'s class so an
  // unrecognised value looks different from a normal one, without changing
  // which option ends up selected.
  function optionsHtml(choice, value) {
    var out = [], known = false;
    for (var i = 0; i < choice.options.length; i++) {
      var o = choice.options[i];
      if (o[0] === value) known = true;
      out.push('<option value="' + esc(o[0]) + '"' + (o[0] === value ? ' selected' : '') +
               '>' + esc(o[1]) + '</option>');
    }
    if (!known) out.unshift('<option value="' + esc(value) + '" selected>' + esc(value) + '</option>');
    return { html: out.join(''), known: known };
  }

  // A <datalist>'s own <option> never needs the "reinject the unknown
  // current value" treatment optionsHtml() above gives a <select> — the text
  // box already shows whatever was typed. Value is the bare name that lands
  // in the box when an option is picked; the label (text content) is only
  // ever shown as the suggestion's hint, never inserted — confirmed against
  // Chrome and Firefox, which both fill the box from `value=`, not from the
  // option's text.
  function datalistOptionsHtml(options) {
    var out = [];
    for (var i = 0; i < options.length; i++) {
      out.push('<option value="' + esc(options[i][0]) + '">' + esc(options[i][1]) + '</option>');
    }
    return out.join('');
  }

  // Every image already on this server, from imgLoad() below — empty until
  // that reply lands, same as devIndex/devPresent above.
  var IMAGES = [];

  function imageOptions() {
    var out = [];
    for (var i = 0; i < IMAGES.length; i++) out.push([IMAGES[i], IMAGES[i]]);
    return out;
  }

  // profiles: has no namespace of its own the way networks:/secrets:/etc. do
  // — MODEL.declared only carries those four plus services — so the file's
  // own services have to be walked by hand, straight off the parsed
  // document, to find every profile name written anywhere in it.
  function fileProfiles() {
    var seen = {}, out = [];
    var svcBlock = MODEL && MODEL.doc && MODEL.doc.root && MODEL.doc.root.kind === 'map' &&
                   MODEL.doc.root.pairs.services && MODEL.doc.root.pairs.services.value;
    if (!svcBlock || svcBlock.kind !== 'map') return out;

    for (var i = 0; i < svcBlock.keys.length; i++) {
      var svc = svcBlock.pairs[svcBlock.keys[i]];
      var svcMap = svc && svc.value && svc.value.kind === 'map' ? svc.value : null;
      var pPair = svcMap ? svcMap.pairs.profiles : null;
      var seq = pPair && pPair.value && pPair.value.kind === 'seq' ? pPair.value : null;
      if (!seq) continue;

      for (var j = 0; j < seq.items.length; j++) {
        var it = seq.items[j].value;
        if (it && it.kind === 'scalar' && it.value && !seen[it.value]) {
          seen[it.value] = true;
          out.push(it.value);
        }
      }
    }
    return out;
  }

  function profileOptions() {
    var names = fileProfiles(), out = [];
    for (var i = 0; i < names.length; i++) out.push([names[i], names[i]]);
    return out;
  }

  // Most of CHOICES now names a compose-model.js vocabulary id rather than
  // carrying its own options — resolved here, at choiceFor()'s own call
  // time, so the form never depends on compose-model.js having loaded first.
  // Falls to a plain box (null), the same as safeVocab() itself, rather than
  // an empty dropdown when the vocab is missing.
  function resolveEntry(entry) {
    if (!entry) return null;
    if (!entry.vocab) return entry;
    var options = safeVocab(entry.vocab);
    return options ? { hint: entry.hint, options: options } : null;
  }

  // Every box that offers a closed list or a suggestion list, in one place —
  // boxHtml() below calls this exactly once. Returns { hint, options }, the
  // same shape with `open: true` added for a suggestion list (rendered as a
  // <datalist> rather than a <select> — see boxHtml()), or null for a box
  // that just takes whatever is typed. Precedence among the five original
  // cases (explicit lookup, network_mode, depends/condition, `from`,
  // volume's host-part heuristic) is unchanged from before this was pulled
  // out of boxHtml() — read them in that order if changing any one of them.
  function choiceFor(f, which) {
    // A boolean is a boolean whatever it is called — see BOOL_CHOICES above
    // and compose-model.js's booleanTail(). Checked first since it is the
    // most specific signal available and never overlaps a key any case below
    // also matches.
    if (which === 'value' && f.type === 'boolean') return boolChoice(f);

    // 1: explicit lookup by binder+target.
    var choice = which === 'value' ? resolveEntry(CHOICES[f.binder + '/' + f.target]) : null;

    // NETWORKS (this server's own docker networks) and serviceModeOptions()
    // are both joined per call rather than stored on the vocab itself — see
    // their own comments for why.
    if (choice && f.target === 'network_mode') {
      choice = { hint: choice.hint, options: choice.options.concat(NETWORKS, serviceModeOptions(f.service, 'service:', 'network')) };
    }
    // ipc and pid share network_mode's "service:<name>" trick, joined the
    // same way and for the same reason.
    if (choice && f.binder === 'setting' && (f.target === 'ipc' || f.target === 'pid')) {
      var what = f.target === 'ipc' ? 'IPC namespace' : 'process namespace';
      choice = { hint: choice.hint, options: choice.options.concat(serviceModeOptions(f.service, 'service:', what)) };
    }

    // 3: a dependency's condition is a closed set, not a
    // namespace the file declares — CHOICES keys on binder+target, but a
    // dependency's target carries its own name (depends_on.db,
    // depends_on.redis...), so it can never be found that way. Checked
    // directly instead, the same way a volume's host part gets its own
    // dropdown just below. f.fold excludes restart/required, which share
    // this binder but take a plain box (required gets its own boolean
    // dropdown above instead).
    if (!choice && which === 'value' && f.binder === 'depends' && !f.fold) choice = resolveEntry(CHOICES['depends/condition']);
    // depends_on's long form is the one place `from` belongs on the name
    // part rather than the value part — see harvestDependsLong() — so this
    // is the only binder that looks there instead.
    var fromPart = f.binder === 'depends' ? 'name' : 'value';
    if (!choice && which === fromPart && f.from) choice = fromChoice(f);

    // 5: a volume's host half: a name is Docker-managed storage, so offer the
    // file's own declared names once there is no path already sitting in the
    // box. A value with a slash, or one that interpolates a variable, is a
    // path already and stays the honest path box instead of being guessed
    // into a dropdown it does not belong in. `p.spot` excludes an anonymous
    // volume, which has nothing here to write to at all. `noChoice` (the
    // sentinel-swap escape hatch) is applied by the caller, since only it
    // knows when that applies.
    var p = f.parts[which];
    if (!choice && which === 'host' && f.binder === 'volume' && p && p.spot &&
        p.value.indexOf('/') < 0 && p.value.indexOf('${') < 0) {
      choice = volumeSourceChoice();
    }

    if (choice) return choice;

    // A declaration's driver is folded onto the row's OWN value box (see
    // DECL_PRIMARY in compose-model.js) rather than living at a target this
    // table could key on, so it is checked directly by declKind instead.
    if (which === 'value' && f.binder === 'declared' && !f.fold &&
        (f.declKind === 'networks' || f.declKind === 'volumes')) {
      var driverChoice = resolveEntry(CHOICES['declared/' + f.declKind + '.driver']);
      // "external" is not a driver — it says Docker never creates this
      // network/volume at all, it must already exist. compose-model.js owns
      // the sentinel (a leading space, so it can never collide with a real
      // driver name) because it also has to read the value back out; the
      // option list itself is only ever wanted here. Copy the array rather
      // than unshifting onto it — driverChoice.options IS the shared vocab
      // resolveEntry() just handed back, and mutating it would grow the
      // dropdown by one "external" every time this row re-renders.
      if (driverChoice && YAML && YAML.externalChoice) {
        driverChoice = {
          hint: driverChoice.hint,
          options: [[YAML.externalChoice, 'external — already exists, not created by this file']]
                      .concat(driverChoice.options)
        };
      }
      return driverChoice;
    }

    // A short-form port's protocol, or a short-form volume's mode — static
    // pairs, not looked up by target, since a port/volume's target is the
    // mapping's own key ("8096/tcp"), never "proto" or "mode". A long-form
    // port's proto part holds the bare word a protocol: line writes, not the
    // short form's slash-carrying value, so it needs its own vocabulary.
    if (which === 'proto' && f.binder === 'port') {
      return f.longForm ? resolveEntry(CHOICES['port/protocol']) : CHOICES['port/proto'];
    }
    if (which === 'mode'  && f.binder === 'volume') return CHOICES['volume/mode'];

    // A long-form port or mount's own extras (see harvestLongExtras() in the
    // model) — checked after the two lines above, which is what makes this
    // safe rather than a collision: a long-form port's OWN `mode:` line
    // ('host'/'ingress') looks like the same name as the short-form volume's
    // `mode` part just above, but the volume line is guarded on
    // f.binder === 'volume', so a long-form port's `mode` never reaches it.
    if (f.longForm) {
      var extraInfo = longExtraInfo(f, which);
      if (extraInfo && extraInfo.vocab) return resolveEntry({ hint: extraInfo.label, vocab: extraInfo.vocab });
    }

    // Suggest, never refuse: image, cap_add/cap_drop and profiles all offer
    // what is known but accept anything typed — see the datalist branch in
    // boxHtml() below, which `open: true` switches on.
    if (which === 'value' && f.binder === 'setting' && f.target === 'image') {
      return { hint: 'an image already on this server, or a fresh one to pull', options: imageOptions(), open: true };
    }
    if (which === 'value' && f.binder === 'list' && (f.listKey === 'cap_add' || f.listKey === 'cap_drop')) {
      var capOptions = safeVocab('capability');
      return capOptions ? { hint: 'a Linux capability name', options: capOptions, open: true } : null;
    }
    if (which === 'value' && f.binder === 'list' && f.listKey === 'profiles') {
      return { hint: 'a profile named anywhere in this file', options: profileOptions(), open: true };
    }

    return null;
  }

  // Password managers ignore autocomplete="off" — that attribute only speaks
  // to the browser's own autofill. They read the words around a box instead,
  // so an environment row named MYSQL_ROOT_PASSWORD gets offered a saved
  // login and a dropdown gets an icon planted inside it. There is no standard
  // way to say "not a login field", so each manager's own opt-out is set:
  // 1Password, LastPass, Bitwarden, Dashlane, Proton Pass. Leading space, so
  // it appends straight onto an attribute list.
  var NOFILL = ' autocomplete="off" data-1p-ignore data-lpignore="true"' +
               ' data-bwignore data-form-type="other" data-protonpass-ignore="true"';

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
    // gives it a line in the file. A field carrying a path (a LEAVES leaf, or
    // a long-form dependency's condition/restart/required) is creatable via
    // addNested the same way regardless of absence, so it is never dead for
    // that reason either. Only a part with truly nowhere to write, or a
    // locked row, renders disabled.
    // A bare declaration ("backend:" with nothing after it) is neither
    // absent (the line already exists) nor carrying a path (declarations
    // aren't LEAVES), so it fell through both escape hatches above and read
    // as dead with no lock reason to explain why. setPart's declaration
    // branch already inserts the primary key under a name with nothing
    // there yet, so the box is genuinely writable — only a locked or
    // blocked declaration (an unreadable shape, or one still needing a fix
    // elsewhere) should stay disabled.
    // `p.creatable` is the same idea one level down: a network map entry's
    // fixed IPv4/hardware address (PLAN_34 phase 3c) has no spot either, but
    // the field it belongs to is an ordinary present row, not absent and
    // carrying no path — none of the field-level escape hatches above see
    // it, so the part itself says it is writable.
    var dead = (!p.spot && !f.absent && !f.path && f.binder !== 'declared' && !p.creatable) ||
               f.locked || f.blocked;
    var t = TOOLS[tool];
    // The options say what the setting means, so the hint below the box says
    // what the setting is for instead of repeating "value".
    var choice = noChoice ? null : choiceFor(f, which);

    if (choice) hint = choice.hint;

    var boxTitle = hint;
    var listId = 'staxx-dl-' + index + '-' + which;
    // Computed even when unused (the input/datalist branches below ignore
    // it) — cheaper than a second choice-shaped branch just to defer this.
    var opts = choice ? optionsHtml(choice, p.value) : null;

    var control = choice && choice.open
      ? '<input type="text" class="staxx-input" list="' + listId + '"' +
              ' data-row="' + index + '" data-part="' + which + '"' +
              ' value="' + esc(p.value) + '"' +
              ' aria-label="' + esc(f.title + ' — ' + boxTitle) + '"' +
              ' title="' + esc(boxTitle) + '"' +
              ' spellcheck="false"' + NOFILL +
              (dead ? ' disabled' : '') + '>' +
          '<datalist id="' + listId + '">' + datalistOptionsHtml(choice.options) + '</datalist>'
      : choice
        // staxx-choose--odd marks a value optionsHtml() had to keep on the
        // list even though it is not one of the known ones — see the comment
        // on optionsHtml() itself for why that value is never dropped.
        // An empty box is not an odd value, it is no value — a setting left
        // unset, or one inherited from a shared block, is blank here and must
        // not be marked as though the file said something wrong.
        ? '<select class="staxx-input staxx-choose' +
                (opts.known || !p.value ? '' : ' staxx-choose--odd') + '"' +
                ' data-row="' + index + '" data-part="' + which + '"' +
                ' aria-label="' + esc(f.title + ' — ' + boxTitle) + '"' +
                ' title="' + esc(boxTitle) + '"' + NOFILL +
                (dead ? ' disabled' : '') + '>' +
            opts.html +
          '</select>'
        : '<input type="text" class="staxx-input"' +
                ' data-row="' + index + '" data-part="' + which + '"' +
                ' value="' + esc(p.value) + '"' +
                ' aria-label="' + esc(f.title + ' — ' + boxTitle) + '"' +
                ' title="' + esc(boxTitle) + '"' +
                ' spellcheck="false"' + NOFILL +
                (dead ? ' disabled' : '') + '>';

    // A <div>, not a <label>, because the Browse button sits beside the input.
    // A label may not hold interactive content other than its own control, and
    // a click on a button inside one is not reliably kept away from it — so the
    // input carries its name in aria-label instead of by being wrapped.
    // staxx-box--<which> names the cell by the part it holds rather than its
    // position in the markup — a mount's two-line layout places cells by
    // name (see the stylesheet), and a long-form mount has no mode part at
    // all, so nth-child would silently shift every cell after the gap.
    return '<div class="staxx-box staxx-box--' + which + (choice && choice.open ? ' staxx-box--open' : '') + '">' +
             (head || '') +
             '<div class="staxx-boxline">' +
               control +
               // Never beside a real <select>: the tool mechanism reads and
               // writes a text box directly (pickerOpen sets .value on it),
               // and a <select>'s value has to be one of its own options. A
               // datalist box is still a text box underneath, so it keeps
               // its picker (env_file's Browse button relies on this).
               (t && !dead && (!choice || choice.open)
                 ? '<button type="button" class="staxx-browse"' +
                        ' data-tool="' + tool + '" data-row="' + index + '"' +
                        ' title="' + esc(t.title) + '">' +
                     '<i class="fa fa-' + t.icon + '" aria-hidden="true"></i>' +
                     '<span class="staxx-sr">' + esc(t.label) + '</span>' +
                   '</button>'
                 : '') +
             '</div>' +
             '<span class="staxx-boxhint">' + esc(hint) + '</span>' +
           '</div>';
  }

  // The heading on the two kinds of row that still have one — a device, named
  // after its hardware, and a locked row, whose boxes are gone. Title and tags
  // share one wrapper because a row's children ARE its grid cells: left loose,
  // each tag would claim a column of its own and push the boxes out of line
  // with the group's captions.
  function headHtml(title, tags) {
    var bits = ['<div class="staxx-fieldhead">',
                '<span class="staxx-fieldtitle">' + esc(title) + '</span>'];
    for (var i = 0; i < tags.length; i++) {
      if (tags[i]) bits.push(tags[i]);
    }
    bits.push('</div>');
    return bits.join('');
  }

  // The note box is identical wherever it appears, so it is built once rather
  // than four times over.
  function noteBoxHtml(f, index) {
    return '<label class="staxx-box staxx-box--note">' +
             '<input type="text" class="staxx-input" data-row="' + index + '"' +
                   ' data-note="1" value="' + esc(f.note) + '"' +
                   ' spellcheck="false"' + NOFILL +
                   (f.commentSpot ? '' : ' disabled') + '>' +
             '<span class="staxx-boxhint">note, kept in the file</span>' +
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
  // splitter asks each one "does a separator start right here".
  var SEP_WS    = /^\s/;          // argv on one line
  var SEP_STEP  = /^(&&|;)/;      // one shell line into its steps

  // The one quoted-token splitter every command shape below is built from —
  // now in compose-model.js, since readTest()/writeTest() (PLAN_8 phase 4)
  // need the exact same splitting for a healthcheck.test line and keeping two
  // copies is how they would quietly drift apart. See its own comment there
  // for what it does.
  var splitQuoted = YAML.splitQuoted;

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
    // entirely. parseFlowList() (compose-model.js) makes the same check
    // itself and is what readTest() uses for healthcheck.test's own flow
    // shape, so this stays the one copy of that extraction.
    if (lineOneTail.charAt(0) === '[') {
      var argv = YAML.parseFlowList(raw);
      return argv ? { kind: 'argv', argv: argv } : null;
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
    return '<p class="staxx-fieldhint" data-say="1"' + (text ? '' : ' hidden') + '>' +
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
      out += '<p class="staxx-fieldnote">' + esc(advice[i]) + '</p>';
    }
    // Networks only (declareMissing is set nowhere else — see the 1e loop in
    // compose-model.js): one click writes the missing network's whole
    // declaration, so the advice above gets a fix rather than leaving the
    // reader to go and add it in the Compose view by hand. Rebuilt on every
    // keystroke along with the rest of this block, which is fine since
    // nothing inside it ever holds focus.
    if (f.declareMissing) {
      out += '<button type="button" class="staxx-declfix" data-declare-net="1" ' +
             'data-net-name="' + esc(f.declareMissing) + '" ' +
             'title="Declares ' + esc(f.declareMissing) + ' as a network created outside this file, ' +
             'which is what an Unraid network is.">Add it to this file</button>';
    }
    return out;
  }

  // Emitted for every field, even with nothing to say — same reason as
  // commandSay() above: the element has to already exist for refreshRanges()
  // to fill it as a value is typed, without redrawing the row under the caret.
  function adviceBlock(f) {
    var text = adviceText(f);
    return '<div class="staxx-advice" data-advice="1"' + (text ? '' : ' hidden') + '>' +
             text +
           '</div>';
  }

  // Everything else on a declaration — internal:, driver_opts: and the rest —
  // lives in a fold, not on the row (see declaredFields() in the model). They
  // are found by scanning MODEL.fields for the ones marked f.fold under this
  // declaration's own target, rather than carried on the row itself, so
  // refreshRanges() (which re-maps rows by index and never redraws) needs no
  // new bookkeeping to keep them in step.
  // Shared by a declaration's fold (declKind set, service '') and a long-form
  // dependency's restart/required fold (service set, declKind unset) — the
  // extra t.service === f.service clause is a no-op for a declaration, since
  // both sides are always '' there, and is what scopes a dependency's fold to
  // the one service it belongs to.
  function foldFieldsFor(f) {
    var out = [], prefix = f.target + '.';
    for (var i = 0; i < MODEL.fields.length; i++) {
      var t = MODEL.fields[i];
      if (t.fold && t.declKind === f.declKind && t.service === f.service &&
          t.target.indexOf(prefix) === 0) out.push(i);
    }
    return out;
  }

  // One leaf inside that fold. `index` is the leaf's OWN index into
  // MODEL.fields — never the row's — so typing in its box, or refreshRanges()
  // matching it back up afterwards, addresses that leaf and nothing else. A
  // locked leaf (driver_opts:, ipam: — a map, not a scalar) has no editable
  // part at all, so it falls back to the raw text a fully-locked row shows,
  // just without repeating that row's own heading and note.
  // A fold row is a plain block, label above box, so its help paragraph needs
  // none of the full-width care a grid field row's does — it simply follows
  // the box. These are the settings least likely to be understood on sight
  // (internal, attachable, external), so leaving them the only described
  // fields with no way to read their description would be the odd one out.
  function declaredFoldHtml(t, index) {
    var body = t.locked
      ? '<pre class="staxx-fieldraw">' + esc(t.raw || '') + '</pre>'
      : boxHtml(t, index, 'value', 'value');
    var help = t.locked ? null : safeFieldHelp(t);
    var helpId = 'staxx-help-' + index;
    return '<div class="staxx-foldrow">' +
             '<span class="staxx-fieldlabel">' + esc(t.title) + helpBtnHtml(help, helpId) + '</span>' +
             body +
             helpParaHtml(help, helpId) +
           '</div>';
  }

  // One extra part of a long-form entry — a port, a mount, a networks: map
  // entry or a secrets:/configs: entry — a 'more settings' row built from
  // LONG_EXTRA_INFO, mirroring declaredFoldHtml() above but for a PART of
  // this field rather than a field of its own, so it reads f.parts[name]
  // through the ordinary boxHtml()/setPart() path — no new ids or binders.
  function longExtraFoldHtml(f, index, name) {
    var info = longExtraInfo(f, name) || { label: name };
    var p = f.parts[name];
    if (p && p.locked) {
      // Same treatment settingTarget()'s locked case gets at field level: the
      // file's own text plus a plain-English reason, since there is no box to
      // bind. staxx-foldrow--long is left off here — that modifier exists
      // only to hide a box's own duplicate hint (see the stylesheet's note on
      // it), and a locked part renders no box at all.
      return '<div class="staxx-foldrow">' +
               '<span class="staxx-fieldlabel">' + esc(info.label) + '</span>' +
               '<pre class="staxx-fieldraw">' + esc(p.raw || '') + '</pre>' +
               '<p class="staxx-fieldnote">' + esc(p.reason || '') + '</p>' +
             '</div>';
    }
    // The label is passed as the box's hint as well, so the box carries its
    // own name for a screen reader — the modifier class hides the second,
    // visible copy of it. See the stylesheet's own note on that rule.
    return '<div class="staxx-foldrow staxx-foldrow--long">' +
             '<span class="staxx-fieldlabel">' + esc(info.label) + '</span>' +
             boxHtml(f, index, name, info.label) +
           '</div>';
  }

  // The "more settings" <details> for a long-form row's own extras — target:,
  // published:/protocol: or source:/read_only:/bind: and the rest for a port
  // or mount, a networks: map entry's fixed IP/priority, a secrets:/configs:
  // entry's target/uid/gid/mode. One .staxx-foldrow per part
  // harvestLongExtras() found, in file order. Built once here and called from
  // both fieldHtml() branches that can carry longExtras (mapped, and list) —
  // pulled out so the two call sites cannot drift apart.
  function longExtrasDevMoreHtml(f, index) {
    // A networks: entry written as a plain list item ("- backend") has no
    // long form at all, so there is nothing here for the ordinary check below
    // to find — but it still needs this same toggle, because opening it is
    // what promotes the whole list into a map (PLAN_34 phase 5), which is
    // what gives the entry somewhere to hang a fixed or hardware address.
    // See the capture-phase 'toggle' listener for what an open here actually
    // does, and netFoldOpen for why the fold survives the redraw that causes.
    var netList = f.binder === 'list' && f.listKey === 'networks';
    if (netList && !f.longForm) {
      return '<details class="staxx-devmore" data-row="' + index + '" data-promote-networks="1"' +
             (netFoldOpen[f.id] ? ' open' : '') + '><summary>more settings</summary></details>';
    }
    if (!f.longForm || !f.longExtras || !f.longExtras.length) return '';
    var extraBits = [];
    for (var lei = 0; lei < f.longExtras.length; lei++) {
      extraBits.push(longExtraFoldHtml(f, index, f.longExtras[lei]));
    }
    return '<details class="staxx-devmore"' + (netList ? ' data-row="' + index + '"' : '') +
           (netList && netFoldOpen[f.id] ? ' open' : '') + '><summary>more settings</summary>' +
           extraBits.join('') + '</details>';
  }

  // A declaration's name: text plus a pencil, never a live box — a box
  // commits through the debounce, so it would rename to every half-typed
  // spelling as it goes, rewriting every reference each time. `data-decl-kind`
  // and `data-decl-name` carry what the click handler needs to act on and,
  // after the rename redraws the form, to find this row's pencil again.
  function declNameHtml(f, index) {
    var word = DECL_WORD[f.declKind] || 'declaration';
    return '<div class="staxx-declname">' +
             '<span class="staxx-declname-text">' + esc(f.parts.name.value) + '</span>' +
             helpBtnHtml(safeFieldHelp(f), 'staxx-help-' + index) +
             '<button type="button" class="staxx-svcrename" data-decl-rename="1"' +
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

  // Guarded the way YAML.keySuggestions/keyAt already are (:4830, :4937) — a
  // stale cached script must leave a row with no help rather than throw.
  function safeFieldHelp(f) {
    if (!YAML || typeof YAML.fieldHelp !== 'function') return null;
    return YAML.fieldHelp(f);
  }

  function safeKeyInfo(key, where) {
    if (!YAML || typeof YAML.keyInfo !== 'function') return null;
    return YAML.keyInfo(key, where);
  }

  // Whether this script can ask the model to reorder a list at all — gates
  // the port grip's own markup below, so a stale cached compose-model.js
  // (missing moveItem) leaves a row with no grip rather than one that throws
  // the moment it is pressed.
  function hasMoveItem() {
    return !!(YAML && typeof YAML.moveItem === 'function');
  }

  function safeMoveItem(doc, form, service, listKey, from, to) {
    if (!hasMoveItem()) return null;
    return YAML.moveItem(doc, form, service, listKey, from, to);
  }

  // The ⓘ itself. '' when there is nothing to say, so a caller can splice
  // this straight into a label or heading without an extra branch either
  // side of it. Named in its title and a .staxx-sr span, same pattern as
  // the × button below — a bare icon is not itself an accessible name.
  function helpBtnHtml(info, id) {
    if (!info) return '';
    return ' <button type="button" class="staxx-helpbtn" data-help="1"' +
           ' aria-expanded="false" aria-controls="' + esc(id) + '"' +
           ' title="More about ' + esc(info.title) + '">' +
             '<i class="fa fa-info-circle" aria-hidden="true"></i>' +
             '<span class="staxx-sr">More about ' + esc(info.title) + '</span>' +
           '</button>';
  }

  // The sentence a helpBtnHtml() button reveals. Always in the markup and
  // hidden, never added afterwards — the click handler only ever flips the
  // `hidden` attribute, so there is nothing to build on demand.
  function helpParaHtml(info, id) {
    if (!info) return '';
    return '<p class="staxx-fieldhint staxx-fieldhelp" id="' + esc(id) + '" hidden>' +
             esc(info.description) + '</p>';
  }

  // firstPort is true only for the first port row of a service, in file
  // order — set by the one caller that knows a row's position within its
  // group (renderForm). It draws the badge marking the port PLAN_39's web
  // page button opens; every other row ignores the argument entirely.
  function fieldHtml(f, index, firstPort) {
    var grp    = groupFor(f);
    var isContainer = grp === 'container';
    var declared = f.binder === 'declared';
    // This function attaches help to the two shapes that have a name of their
    // own: a setting's label and a declaration's name (see fieldHelp() in the
    // model; the folded settings under either get theirs in declaredFoldHtml).
    // Ports, volumes, variables, devices, labels and every list entry get
    // theirs from the group heading instead, since those rows have no label of
    // their own to hang an icon from. A long-form dependency's fieldHelp answer
    // describes its condition box, which has no label either, so it is left
    // unattached rather than forced somewhere that would not read as the row's
    // own name — the Depends on group heading carries the general sentence.
    var help = safeFieldHelp(f);
    var helpId = 'staxx-help-' + index;
    var mapped = f.binder === 'port' || f.binder === 'volume' || f.binder === 'device';
    // A long-form dependency also carries parts.name (its key), but it is not
    // this env/label shape — it gets its own branch below, checked ahead of
    // this one for that reason. A declaration carries one too, and for the
    // same reason must not be caught here: its name is text plus a pencil
    // (declNameHtml), never a live box, because renaming one has to go
    // through renameDeclared() and carry every service reference with it —
    // caught by this branch instead, its name box wrote the key directly and
    // left every reference pointing at a network that no longer existed, and
    // its folded settings never rendered at all.
    var named  = !!f.parts.name && f.binder !== 'depends' && f.binder !== 'declared';
    var listy  = mapped || f.binder === 'env' || f.binder === 'label' || f.binder === 'list';
    // Never on a Container row — its four settings are not a list. Not for an
    // entry written in a way the model sealed either, since that refuses
    // anyway and a button that always says no is worse than no button. A
    // declaration is removable too, so it gets the × the same as any list
    // entry — as does a whole dependency, long or short.
    var showKill  = !isContainer && (listy || declared || f.binder === 'depends') &&
                    f.target.charAt(0) !== '@';
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
                ? '<span class="staxx-fieldtag">read-only mount</span>' : '';
    var lostTag = lost
                ? '<span class="staxx-fieldtag staxx-fieldtag--lost">' +
                  'not found on this server</span>' : '';

    bits.push('<div class="staxx-fieldrow' + (f.locked ? ' staxx-fieldrow--locked' : '') +
              (f.sensitive ? ' staxx-fieldrow--secret' : '') +
              '" data-row="' + index + '" data-field-row="' + esc(f.id) + '"' +
              ' data-from="' + (f.range ? f.range.start : -1) + '"' +
              ' data-to="'   + (f.range ? f.range.end   : -1) + '"' +
              ' tabindex="0">');

    if (f.locked) {
      // The boxes are gone, so the name has nowhere else to live — the title
      // that every other row dropped reappears here instead.
      bits.push(headHtml(f.title, [roTag, lostTag]));
      bits.push('<pre class="staxx-fieldraw">' + esc(f.raw || '') + '</pre>');
      bits.push(commandSay(f));
      bits.push('<p class="staxx-fieldnote">Not editable here because ' +
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
      bits.push('<span class="staxx-fieldlabel">' + esc(f.title) + helpBtnHtml(help, helpId) + '</span>');
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
          devMore = '<details class="staxx-devmore">' +
                      '<summary>' + (differs
                        ? 'appears inside the container as ' + esc(into.value)
                        : 'change the path inside the container') + '</summary>' +
                      boxHtml(f, index, 'container', 'path in the container') +
                    '</details>';
        }
      }
      bits.push(noteBoxHtml(f, index));
    } else if (mapped) {
      // The drag/keyboard handle that reorders a port (PLAN_40) — only ports
      // carry one; a mount or a device's order carries no meaning, so a
      // handle there would be motion without purpose. Always a cell, even
      // when hasMoveItem() says no, so the leading track never appears or
      // disappears and the columns stay put (see --sm-fieldcols above).
      if (f.binder === 'port') {
        bits.push(hasMoveItem()
          ? '<button type="button" class="staxx-portgrip" data-port-grip="1"' +
            ' title="Drag to reorder, or use the up and down arrow keys">' +
              '<i class="fa fa-arrows-v" aria-hidden="true"></i>' +
              '<span class="staxx-sr">Drag to reorder this port, or focus it and use the ' +
              'up and down arrow keys</span>' +
            '</button>'
          : '<span class="staxx-boxgap" aria-hidden="true"></span>');
      }
      // The rule the web page button follows (PLAN_39): the first port in
      // the file is the one it opens. Said here, on that row's own host box,
      // rather than left as a fact nobody but the button knows.
      // Only a volume gets the folder picker. A port is a number, so browsing
      // for one would be a button that never finds what you came for.
      bits.push(boxHtml(f, index, 'host',
                f.binder === 'port' ? 'port on the server' : 'path on the server',
                f.binder === 'volume' ? 'browse' : ''));
      // A mount someone deliberately made read-only has to keep saying so.
      // The row lost its title, so the badge moves in beside the path it
      // qualifies rather than disappearing with it.
      bits.push(boxHtml(f, index, 'container',
                f.binder === 'port' ? 'port inside the container' : 'path in the container',
                '', roTag));
      // The protocol, or the read/write mode: a part of the same scalar as
      // the two boxes above (see splitPortShort()/splitPathShort()), so it
      // belongs beside them rather than below.
      //
      // Always a cell, even when there is nothing to put in it. A long-form
      // port carries this part too now (see harvestLongForm() and
      // choiceFor()'s f.longForm branch) — with a spot when the file has a
      // protocol: line, a dead one when it doesn't, but never absent, so
      // boxHtml() never falls through to the placeholder for a port. A
      // long-form volume has no equivalent part at all, so that row still
      // does: without the placeholder it would render four children into
      // five tracks, the note sliding into this narrow column and the × out
      // of its own. Guarded on the two binders that own this column rather
      // than left to the branch order above: a device is `mapped` too, and
      // reaches its own branch first only by sitting higher up the chain. It
      // has three columns, not five, so a placeholder here would misalign it
      // instead.
      if (f.binder === 'port' || f.binder === 'volume') {
        var extra = f.binder === 'port'
                  ? boxHtml(f, index, 'proto', 'protocol')
                  : boxHtml(f, index, 'mode', 'read and write');
        bits.push(extra || '<span class="staxx-boxgap" aria-hidden="true"></span>');
      }
      bits.push(noteBoxHtml(f, index));

      devMore = longExtrasDevMoreHtml(f, index);
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

      // A networks: map entry's own settings beyond its name (fixed IP,
      // priority...), or a long-form secrets:/configs: entry's target/uid/
      // gid/mode — see harvestNetworksMap()/harvestList() in the model.
      devMore = longExtrasDevMoreHtml(f, index);
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
        devMore = '<details class="staxx-devmore"><summary>more settings</summary>' +
                  foldBits.join('') + '</details>';
      }
    } else if (f.binder === 'depends') {
      // Long form: the dependency's own name (a dropdown of the file's other
      // services, written through its key) beside its condition — restart and
      // required fold away below, same shape as a declaration's own extras.
      bits.push(boxHtml(f, index, 'name', 'service name'));
      bits.push(boxHtml(f, index, 'value', 'when it counts as ready'));
      bits.push(noteBoxHtml(f, index));

      var depFoldIdx = foldFieldsFor(f);
      if (depFoldIdx.length) {
        var depFoldBits = [];
        for (var dfi = 0; dfi < depFoldIdx.length; dfi++) {
          depFoldBits.push(declaredFoldHtml(MODEL.fields[depFoldIdx[dfi]], depFoldIdx[dfi]));
        }
        devMore = '<details class="staxx-devmore"><summary>more settings</summary>' +
                  depFoldBits.join('') + '</details>';
      }
    }

    if (showKill) {
      // The × alone, with the words moved to the tooltip and the accessibility
      // tree — "Remove" beside every one of them was more noise than help.
      bits.push('<button type="button" class="staxx-kill" data-row="' + index + '"' +
                ' data-remove="1" title="Remove ' + esc(f.title) + '">' +
                  '<i class="fa fa-times" aria-hidden="true"></i>' +
                  '<span class="staxx-sr">Remove ' + esc(f.title) + '</span>' +
                '</button>');
    }

    // Last cell on a port row, after the ×, and a column that is ALWAYS there
    // — an empty one when this is not the first port. Two reasons it holds its
    // place rather than appearing only where it is needed: every port row then
    // lines up with its neighbours, and reordering (PLAN_40) can move rows
    // around without the chip's column appearing and disappearing under the
    // drag.
    if (f.binder === 'port') {
      bits.push(firstPort
        ? '<span class="staxx-webchip" title="' +
          esc('The WebUI button on this container’s row opens this port. ' +
              'Put a different port first to change which one.') + '">WebUI</span>'
        : '<span class="staxx-webchip-gap" aria-hidden="true"></span>');
    }

    // Everything full-width comes last, after every cell the row's column
    // template names. A full-width child ends the grid row it lands on and
    // resets auto-placement to column 1 below it, so anything emitted after
    // one is stranded in the label column rather than in its own.
    //
    // A row that is not fully locked can still be partly restricted — an
    // anonymous volume with no host half, a "- FOO" passthrough with no value.
    // Say so beneath the boxes, same as a locked row's fuller version above.
    // The command/entrypoint gloss lands here too, for the same reason.
    if (devMore) bits.push(devMore);
    if (!f.locked && f.lockReason) {
      bits.push('<p class="staxx-fieldnote">' + esc(f.lockReason) + '.</p>');
    }
    if (!f.locked) bits.push(commandSay(f));
    if (!f.locked) bits.push(adviceBlock(f));
    // Only the two shapes that got a button above (a setting's own label, a
    // declaration's own name) ever have anywhere to reveal this. A locked row
    // takes the branch at the very top of this function instead, so it never
    // gets that button — there is deliberately no help paragraph here with
    // nothing to toggle it, rather than one shown open with no way to hide it.
    if (!f.locked && help && (f.binder === 'setting' || declared)) {
      bits.push(helpParaHtml(help, helpId));
    }

    bits.push('</div>');
    return bits.join('');
  }

  // A group's header line: its heading, the grey note beside Container's, the
  // Sections button and its picker panel (Container only — flags is only ever
  // passed for that group), and its own Add button where it has one.
  //
  // The panel is only in the markup at all when sectionsOpen[serviceName] is
  // true — there is no CSS-hidden state — because it is drawn fresh by
  // renderForm() on every render rather than built once by hand: a tick
  // reparses and redraws the whole form, so a hand-built panel (the way
  // devOpen() builds the device picker) would not survive the first tick made
  // inside it.
  // The compose key each group heading explains, and the vocabulary to read
  // it against — 'service' for everything under a service, 'top' for the
  // file's own networks:/volumes:/secrets:/configs: blocks, which are a
  // different table from a service's keys (see keyInfo's own comment).
  // Container and Advanced describe no single key, so they carry no entry
  // and groupHelpInfo() answers null for both, same as a row with no help.
  var GROUP_HELP_KEY = {
    port: 'ports', volume: 'volumes', env: 'environment', device: 'devices',
    label: 'labels', health: 'healthcheck', resources: 'deploy',
    depends: 'depends_on', logging: 'logging'
  };
  var DECLARED_GROUP_KEY = { 'declared:networks': 'networks', 'declared:volumes': 'volumes',
                              'declared:secrets': 'secrets', 'declared:configs': 'configs' };

  function groupHelpInfo(g) {
    if (DECLARED_GROUP_KEY[g.key]) return safeKeyInfo(DECLARED_GROUP_KEY[g.key], 'top');
    // A dynamic list group's own key IS the compose key it describes
    // ('list:networks' -> 'networks'), so there is no table row to write.
    if (g.key.slice(0, 5) === 'list:') return safeKeyInfo(g.key.slice(5), 'service');
    var key = GROUP_HELP_KEY[g.key];
    return key ? safeKeyInfo(key, 'service') : null;
  }

  function groupHeadHtml(g, serviceName, flags) {
    var help = groupHelpInfo(g);
    // Raw, not esc()'d: helpBtnHtml/helpParaHtml escape it as they emit it,
    // and escaping here as well would put "&amp;amp;" in the id of a service
    // whose name holds an "&".
    var helpId = 'staxx-grouphelp-' + serviceName + '-' + g.key;
    var bits = ['<div class="staxx-grouphead"><h5 class="staxx-fieldgroup">' + esc(g.heading)];
    if (g.note) bits.push(' <span class="staxx-groupnote">' + esc(g.note) + '</span>');
    bits.push(helpBtnHtml(help, helpId));
    bits.push('</h5>');
    if (flags) {
      var open = !!sectionsOpen[serviceName];
      bits.push('<div class="staxx-sections">');
      bits.push('<button type="button" class="staxx-sectionsbtn" data-sections="' + esc(serviceName) +
                '" aria-expanded="' + (open ? 'true' : 'false') + '">' +
                'Sections <i class="fa fa-caret-down" aria-hidden="true"></i></button>');
      if (open) {
        bits.push('<div class="staxx-sectionpick">');
        for (var si = 0; si < SECTIONS.length; si++) {
          var s = SECTIONS[si];
          bits.push('<label class="staxx-sectionrow"><input type="checkbox" data-flag="' + s.key +
                    '" data-service="' + esc(serviceName) + '"' + (flags[s.key] ? ' checked' : '') + '> ' +
                    esc(s.label) + '</label>');
        }
        bits.push('</div>');
      }
      bits.push('</div>');
    }
    if (g.add) {
      bits.push('<button type="button" class="staxx-add"' +
               ' data-add="' + g.add + '" data-service="' + esc(serviceName) + '">' +
               '<i class="fa fa-plus" aria-hidden="true"></i> ' + esc(addWord(g.add)) +
               '</button>');
    }
    bits.push('</div>');
    // After .staxx-grouphead's own closing tag, not inside it — a
    // full-width paragraph would end that flex row's content early exactly
    // the way fieldHtml's tail comment describes for a field row.
    bits.push(helpParaHtml(help, helpId));
    return bits.join('');
  }

  // One row naming what sits in each column below it. Skipped entirely when
  // the group holds nothing — a caption over no rows has nothing to caption.
  // A dynamic list group has no entry in CAPTIONS — there is one per compose
  // key and growing that table by hand defeats the point — so it falls back
  // to the same one-box shape as Devices, which is the template it shares.
  function captionRow(grp) {
    var declared = grp.key.slice(0, 9) === 'declared:';
    // grp.cols lets one render override the columns a shared GROUPS entry
    // would otherwise show — depends_on's long form needs a third column
    // CAPTIONS' static 'depends' row does not carry (see groupsForService).
    var cols = grp.cols || CAPTIONS[grp.key] ||
               (grp.cls === 'staxx-formgroup--single' ? ['value', 'note, kept in the file'] : null) ||
               (declared ? ['name', 'setting', 'note, kept in the file'] : null);
    if (!cols) return '';
    var bits = ['<div class="staxx-caption" aria-hidden="true">'];
    // Ports alone carry a leading grip column (PLAN_40) with nothing to name
    // — a blank cell here keeps every heading lined up with the box beneath
    // it instead of sliding one column left of where it belongs.
    if (grp.key === 'port') bits.push('<span></span>');
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
    { key: 'declared:networks', heading: 'Networks', cls: 'staxx-formgroup--declared', add: 'declared:networks' },
    { key: 'declared:volumes',  heading: 'Volumes',  cls: 'staxx-formgroup--declared', add: 'declared:volumes' },
    { key: 'declared:secrets',  heading: 'Secrets',  cls: 'staxx-formgroup--declared', add: 'declared:secrets' },
    { key: 'declared:configs',  heading: 'Configs',  cls: 'staxx-formgroup--declared', add: 'declared:configs' }
  ];

  // A pseudo-service above the real ones for the file's own networks:,
  // volumes:, secrets: and configs: blocks — they belong to no service,
  // which is exactly what f.service === '' means. Reuses .staxx-svc so
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

    var out = ['<section class="staxx-svc staxx-svc--stack">',
               '<details class="staxx-stackfold"' + (stackOpen ? ' open' : '') + '>',
               '<summary class="staxx-svchead">Stack</summary>'];
    for (var g = 0; g < DECL_GROUPS.length; g++) {
      var grp = DECL_GROUPS[g], rows = buckets[grp.key] || [];
      out.push('<div class="staxx-formgroup ' + grp.cls + '" data-group="' + grp.key + '">');
      out.push(groupHeadHtml(grp, ''));
      if (rows.length) out.push(captionRow(grp));
      for (var r = 0; r < rows.length; r++) out.push(fieldHtml(form.fields[rows[r]], rows[r]));
      out.push('</div>');
    }
    out.push('</details>');
    out.push('</section>');
    return out.join('');
  }

  // One block per top-level `include:` entry, shown in place of the empty-
  // form message when a file only points at others rather than listing any
  // services itself. Its own function, not folded into renderForm(), so an
  // editable version can replace this renderer later without unpicking the
  // branch that calls it (PLAN_21).
  //
  // A file can carry both `include:` and its own `services:` — that
  // combination renders as an ordinary service list, since this only runs
  // from renderForm's empty branch, so the include blocks never show
  // alongside real services in this pass.
  function includesHtml(form) {
    var out = ['<div class="staxx-includes">'];
    for (var i = 0; i < form.includes.length; i++) {
      var inc = form.includes[i];
      var outside = inc.file.indexOf('/') >= 0 || inc.file.slice(0, 3) === '../';
      var known = false;
      if (!outside) {
        for (var j = 0; j < FILES.length; j++) {
          // Same exclusions renderTabs() uses to decide a file has no tab of
          // its own — a directory or a link cannot be opened here either.
          if (FILES[j].name === inc.file && !FILES[j].compose && !FILES[j].dir && !FILES[j].link) {
            known = true;
            break;
          }
        }
      }
      out.push('<div class="staxx-includeblock">');
      out.push('<div class="staxx-includehead">' +
               '<span class="staxx-includename">' + esc(inc.file) + '</span>' +
               '<span class="staxx-fieldtag">include</span></div>');
      out.push('<p class="staxx-fieldhint">Its services are defined in that file, not this one.</p>');
      out.push(known
        ? '<button type="button" class="staxx-add" data-open-file="' + esc(inc.file) + '">Open</button>'
        : '<p class="staxx-fieldnote">This file cannot be opened here.</p>');
      out.push('</div>');
    }
    out.push('</div>');
    return out.join('');
  }

  // A file the parser could not read at all is reparse()'s business — see
  // brokenFormHtml() — so by the time this runs form.ok is always true, and
  // the only empty case left is a readable file that simply lists nothing.
  function renderForm(form) {
    if (!form.services.length) {
      if (form.includes && form.includes.length) return includesHtml(form);
      var why = form.warnings.length ? form.warnings[0].message
                                     : 'There is nothing in this file to show yet.';
      return '<p class="staxx-form-empty">' + esc(why) + '</p>';
    }

    var out = [stackSectionHtml(form)];
    for (var s = 0; s < form.services.length; s++) {
      var svc = form.services[s];
      out.push('<section class="staxx-svc" data-service="' + esc(svc.name) + '"' +
               ' data-from="' + svc.range.start + '" data-to="' + svc.range.end + '">');
      out.push('<h4 class="staxx-svchead">' +
               '<span class="staxx-svcname">' + esc(svc.name) + '</span>' +
               ' <button type="button" class="staxx-svcrename" data-svc-rename="1"' +
               ' data-service="' + esc(svc.name) + '"' +
               ' aria-label="Rename this service" title="Rename this service">' +
               '<i class="fa fa-pencil" aria-hidden="true"></i></button>' +
               '</h4>');
      if (svc.overview) out.push('<p class="staxx-fieldhint">' + esc(svc.overview) + '</p>');
      if (svc.note)     out.push('<p class="staxx-fieldnote">' + esc(svc.note) + '</p>');

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
          // A fold field (a dependency's restart/required) renders inside its
          // own parent row via foldFieldsFor(), never as a row of its own —
          // same reason stackSectionHtml() excludes a declaration's fold.
          if (form.fields[i].fold) continue;
          var gk = groupFor(form.fields[i]);
          // A binder groupsForService did not build a bucket for would
          // otherwise throw on .push — reparse() has no other net under it.
          if (!buckets[gk]) buckets[gk] = [];
          buckets[gk].push(i);
        }

        var flags = serviceFlags(form, svc.name);

        for (var gi = 0; gi < groups.length; gi++) {
          var grp = groups[gi], rows = buckets[grp.key];
          // A flagged group (health/resources/depends) shows exactly when its
          // tick is on, at zero rows or many — health and resources always
          // have every leaf as a field (harvestLeaves), so "zero rows" never
          // actually happens for them, but depends can. Anything else keeps
          // the older rule: an Add button survives its list emptying out, one
          // with none has nothing left to show for an empty bucket.
          if (grp.flag) { if (!flags[grp.flag]) continue; }
          else if (!rows.length && !grp.add) continue;
          out.push('<div class="staxx-formgroup ' + grp.cls + '" data-group="' + grp.key + '">');
          out.push(groupHeadHtml(grp, svc.name, grp.key === 'container' ? flags : null));
          if (rows.length) out.push(captionRow(grp));
          for (var r = 0; r < rows.length; r++) {
            out.push(fieldHtml(form.fields[rows[r]], rows[r], grp.key === 'port' && r === 0));
          }
          out.push('</div>');
        }
      }

      out.push('</section>');
    }

    // Sits below the last service, never inside one — adding a whole new
    // container is not one more entry in a list already on screen. The
    // handler picks the name (new-container, new-container-2…) itself, so
    // there is nothing here to disable it over.
    out.push('<button type="button" class="staxx-addsvc" data-add-service="1">' +
             '<i class="fa fa-plus" aria-hidden="true"></i> Add container</button>');

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

  /* A mapping written with a separator but with one side left empty — "8080:"
   * once the container box is cleared, ":/data" once the host box is. Both
   * halves carry a spot only when both were written, which is what separates
   * this from a legitimate one-sided entry ("- 8080", "- /data"), whose absent
   * half has no spot at all and is compose's business rather than a gap.
   *
   * Compose refuses most of these ("invalid proto:", "empty section between
   * colons") and quietly accepts the rest, so the form says so itself — from
   * the moment the file opens, naming the row, rather than leaving it to a
   * refusal from the server after a round trip. */
  function halfMapping(f) {
    if (f.binder !== 'port' && f.binder !== 'volume' && f.binder !== 'device') return false;
    var h = f.parts.host, c = f.parts.container;
    if (!h || !c || !h.spot || !c.spot) return false;
    return !String(h.value).trim() || !String(c.value).trim();
  }

  // What the note says about one gap. A half mapping is named by what it is
  // rather than by f.title, because the title of a port whose number has just
  // been cleared is "Port " — the missing half IS its name.
  function gapWhy(f) {
    if (!halfMapping(f)) return '"' + f.title + '" is required and empty.';
    var word = f.binder === 'port' ? 'port' : f.binder === 'volume' ? 'volume' : 'device';
    var host = String(f.parts.host.value).trim();
    var cont = String(f.parts.container.value).trim();
    if (!host && !cont) return 'A ' + word + ' entry has nothing on either side.';
    return 'A ' + word + ' entry has no ' + (cont ? 'host' : 'container') + ' side.';
  }

  function requiredGaps() {
    var out = [];
    if (!MODEL) return out;
    for (var i = 0; i < MODEL.fields.length; i++) {
      var f = MODEL.fields[i];
      if (f.locked) continue;
      if (((f.required || f.fixedRequired) && emptyValue(f)) || halfMapping(f)) {
        out.push({ index: i, field: f, why: gapWhy(f) });
      }
    }
    return out;
  }

  function updateRequired() {
    var gaps = requiredGaps();

    var rows = formHost.querySelectorAll('.staxx-fieldrow');
    for (var i = 0; i < rows.length; i++) {
      var at = rows[i].dataset.row | 0;
      var bad = gaps.some(function (g) { return g.index === at; });
      rows[i].classList.toggle('staxx-fieldrow--gap', bad);
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

    // The first gap's own sentence, since the two kinds do not read alike —
    // see gapWhy() — with the rest counted after it.
    gapNote.hidden = false;
    gapNote.textContent = gaps[0].why +
      (gaps.length > 1
        ? ' And ' + (gaps.length - 1) + ' other row' +
          (gaps.length > 2 ? 's need' : ' needs') + ' attention.'
        : '');
    gapNote.dataset.row = gaps[0].index;

    saveBtn.disabled  = true;
    startBtn.disabled = true;
  }

  gapNote.addEventListener('click', function () {
    var row = formHost.querySelector('.staxx-fieldrow[data-row="' + (gapNote.dataset.row | 0) + '"]');
    if (!row) return;
    // Only when the form is not on screen at all. Someone already looking at
    // it has not asked to be moved.
    if (modalBody.dataset.view === 'yaml') setView(defaultView());
    row.scrollIntoView({ block: 'center' });
    var box = row.querySelector('input:not([disabled])');
    if (box) box.focus();
  });

  // The missing-file names flagged the last time updateMissing() ran, so a
  // name that is newly missing — a paste that references a file nobody has
  // added yet, most often — can be told apart from the same warning simply
  // being redrawn on every reparse. missingRefs() itself is defined further
  // down, beside fileRefMap(); function declarations hoist, so calling it
  // from here is fine.
  var missingSeen = [];

  // Note text and behaviour for #staxx-missing — see missingRefs() below
  // for what counts as missing. Called from reparse() (every settled edit)
  // and from filesLoad() (files.length changes without the compose text
  // moving at all), which between them cover every way the answer changes.
  function updateMissing() {
    var missing = missingRefs();

    if (!missing.length) {
      missingNote.hidden = true;
      missingNote.textContent = '';
      missingSeen = [];
      return;
    }

    var first = missing[0];
    missingNote.textContent = '"' + first.file + '" is named in this compose file but is not in ' +
      'this stack. Create it, or add it with the + button above.' +
      (missing.length > 1
        ? ' And ' + (missing.length - 1) + ' other' + (missing.length > 2 ? 's' : '') +
          ' ' + (missing.length > 2 ? 'are' : 'is') + ' missing.'
        : '');
    missingNote.hidden = false;

    // PLAN_13 asked for a second bar just for a paste that references a
    // missing file. This note already covers that — reparse() runs after a
    // paste same as any other edit — so the only thing worth adding is
    // making a freshly-appeared name hard to miss, the same courtesy
    // showError() gives an outright failure.
    var names = missing.map(function (m) { return m.file; });
    var isNew = names.some(function (n) { return missingSeen.indexOf(n) < 0; });
    missingSeen = names;
    if (isNew) missingNote.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  missingNote.addEventListener('click', function () {
    // Recomputed rather than read off a stored dataset — the compose file
    // is the only source of truth, so a fix made and undone between the
    // last redraw and this click must never be acted on as stale.
    var missing = missingRefs();
    if (missing.length) createMissingFile(missing[0]);
  });

  // A file the parser cannot read as a mapping at all has no services, which
  // renderForm() would otherwise draw as an almost-empty form — reading as
  // "this file has no containers" when the truth is "this could not be
  // read". Said plainly instead, in the order asked for: what is wrong, why,
  // then what to do about it.
  function brokenFormHtml(form) {
    var why = form.warnings.length ? form.warnings[0].message
                                   : 'This file could not be read.';
    // No closing "fix it in the Compose view" line here: the message buildForm
    // returns on this path already ends with exactly that, and saying it twice
    // in a four-line panel reads as though they are two different instructions.
    return '<div class="staxx-form-broken">' +
           '<strong>This file cannot be shown as a form</strong>' +
           '<p>' + esc(why) + '</p>' +
           '</div>';
  }

  // Form and Split both need the form drawn to be worth showing, so both are
  // switched off together while the file cannot be read — Compose is left
  // alone, since that is where the fix has to happen anyway.
  //
  // This never forces the view back to Compose by itself. Typing in the
  // Compose pane makes the file transiently unreadable all the time — mid
  // word, mid line — and yanking Split or Form away underneath someone on
  // every 400ms debounce would be worse than the broken moment it is
  // guarding against. Disabling the buttons stops a NEW jump into a broken
  // form; the panel above explains it to anyone already there.
  function setFormGate(ok, why) {
    var btns = modal.querySelectorAll(
      '.staxx-viewbtn[data-view="form"], .staxx-viewbtn[data-view="split"]');
    for (var i = 0; i < btns.length; i++) {
      // Form on its own would hide the companion file the tab just opened —
      // its editor IS the compose pane. Split is the one that shows both, so
      // this gate cannot hand Form back while a companion is on screen.
      var off = !ok || (btns[i].dataset.view === 'form' && fileOpen !== null);
      btns[i].disabled = off;
      btns[i].title = off ? (ok ? FORM_GATE_TITLE : why) : '';
    }
    // Outline needs a readable model exactly as much as Form/Split do — but
    // keeps its own static title (what the button does), rather than the
    // other two's title-as-explanation, so only .disabled is touched here.
    if (outlineBtn) {
      outlineBtn.disabled = !ok;
      if (!ok) closeOutline();   // open on a file that just stopped parsing would show stale lines
    }
  }

  // The last YAML.lint() result, cached so a view switch (see setView()) can
  // repaint the same dots rather than re-linting a document that has not
  // changed. varLint is the undefined-${VAR} scan (see varDots() below) —
  // a second source painted the same way, not a second place that paints.
  // saveErrorDot is one extra entry for a save the server refused (see
  // markSaveError()) — kept apart from both so relint() dropping it on the
  // next real reparse is a one-line thing, not a filter. checkDot is the
  // same idea for the live "does compose accept this" round trip below (see
  // runCheck()) — one dot, cleared the same way.
  var lastLint     = [];
  var varLint      = [];
  var saveErrorDot = null;
  var checkDot     = null;

  function redrawDots() {
    var list = lastLint.concat(varLint);
    if (saveErrorDot) list = list.concat([saveErrorDot]);
    if (checkDot)     list = list.concat([checkDot]);
    paintDots(list);
  }

  // Guarded the same way paintInk() guards for YAML.highlight: if the linter
  // has not landed yet, this simply never finds any problems to mark.
  function relint() {
    // netNames() is null until the server has answered, which switches the
    // network_mode value check off rather than letting it call a real network
    // a typo — netLoad()'s first reply triggers a reparse, so it starts then.
    lastLint = (YAML && typeof YAML.lint === 'function' && MODEL && MODEL.doc)
      ? YAML.lint(MODEL.doc, netNames()) : [];
    varLint = varDots();
    saveErrorDot = null;   // a fresh reparse is the moment a save error goes stale
    checkDot = null;       // ditto — the line numbers a stale check answer named may no longer mean the same thing
    redrawDots();
  }

  function reparse() {
    if (!YAML) { formHost.innerHTML = '<p class="staxx-form-empty">The form view could not load.</p>'; return; }

    var doc  = YAML.parse(currentText());
    var form = YAML.buildForm(doc);
    form.doc = doc;
    MODEL = form;

    var scrollWas = formHost.scrollTop;
    devPanel = null;            // the device panel lives in here and just went
    formHost.innerHTML = form.ok ? renderForm(form) : brokenFormHtml(form);
    formHost.scrollTop = scrollWas;

    setFormGate(form.ok, form.warnings[0] && form.warnings[0].message);

    var warned = form.warnings.length;
    setYamlStatus(warned ? form.warnings[0].message +
                           (warned > 1 ? '  (and ' + (warned - 1) + ' more)' : '') : '');
    // The file's own text just changed underneath any open search, so its
    // offsets are stale — recompute quietly rather than jumping the caret,
    // since whatever triggered this reparse (typing, an undo, a structural
    // edit) already knows where it wants the caret to be.
    findRecompute();
    updateRequired();
    updateMissing();
    relint();
    checkHostPaths();   // ask the server about any volume host path not already cached
    scheduleCheck();    // ask the server whether compose itself accepts this file (own, longer debounce)
    // The form's markup was just rebuilt from scratch, so a companion file
    // still on screen has to have it locked again — find and replace is the
    // one path that reaches this from a companion tab.
    if (fileOpen !== null) lockForm();
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

    var rows = formHost.querySelectorAll('.staxx-fieldrow');
    for (var i = 0; i < rows.length; i++) {
      var f = MODEL.fields[rows[i].dataset.row | 0];
      if (!f) continue;
      rows[i].dataset.fieldRow = f.id;
      rows[i].dataset.from = f.range ? f.range.start : -1;
      rows[i].dataset.to   = f.range ? f.range.end   : -1;
      rows[i].classList.toggle('staxx-fieldrow--secret', !!f.sensitive);

      // A slot that just gained its line in the file goes from having no
      // comment to write to, to having one — flip the note box back on to
      // match, without redrawing the row it lives in.
      var note = rows[i].querySelector('[data-note]');
      if (note) note.disabled = !f.commentSpot;

      // The command/entrypoint gloss is prose derived from the value, so it
      // goes stale exactly like the note box above — refreshed in
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
    // commit() just rewrote yamlPane.value directly (see its own comment on
    // why it skips reparse()) — a search still has to notice, quietly (see
    // reparse() above for why this is the quiet recompute, not findRun()).
    findRecompute();
    updateRequired();
    relint();   // a form commit rebuilds MODEL.doc just like reparse() does
  }

  function commit(el) {
    if (!MODEL || sanitised) return;
    // A companion file is in the box, so the serialised compose file below
    // would land in that file and the autosave would write it there. The form
    // is locked while one is open, but this runs on a 250ms timer that a tab
    // switch can outlive — openFile() flushes it first so the edit is not
    // lost, and this is what catches anything still in flight.
    if (fileOpen !== null) return;

    var f = MODEL.fields[el.dataset.row | 0];
    // A row's data-row is only ever refreshed, never reassigned (see the
    // note above refreshRanges()) — so a structural change elsewhere that
    // reshapes MODEL.fields can leave this row pointing at nothing. Saying
    // so beats writing quietly nowhere: that is exactly how a box can look
    // live while every edit to it is silently dropped (PLAN_14.md).
    if (!f) { setYamlStatus('This box lost track of its place in the file — reopen the stack to fix it.'); return; }

    var done;
    if (el.dataset.note !== undefined) {
      // The note shares one comment with the -!S and -!R markers, so the whole
      // comment is rewritten on every note edit. The markers come from the
      // model, not from the row — there is no control for them, and passing
      // false would silently strip a hand-written marker out of the file.
      done = YAML.setComment(MODEL.doc, MODEL, f.id, el.value, !!f.sensitive, !!f.required);
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
    paintInk();                                   // loop back round
    refreshRanges();
  }

  // The small button that hands a swapped-to-path volume row back to the
  // dropdown — see swapVolumeToPath()/swapVolumeToChoice() below. Rendered
  // above the boxline via boxHtml()'s `head` slot, same trick a device row's
  // heading already uses to avoid ending the grid row early.
  function volSwitchBackHtml(index) {
    return '<button type="button" class="staxx-browse" data-vol-switch="1"' +
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
    var box = select.closest('.staxx-box');
    var row = select.closest('.staxx-fieldrow');
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
    var box = button.closest('.staxx-box');
    var row = button.closest('.staxx-fieldrow');
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

  // 'toggle' does not bubble, so a delegated listener only ever sees it in
  // the capture phase — the trailing `true` is load-bearing, not decoration.
  formHost.addEventListener('toggle', function (event) {
    var el = event.target;
    if (el.classList.contains('staxx-stackfold')) { stackOpen = el.open; return; }

    // A networks: entry's own "more settings" fold — remembered by field id
    // so it survives the redraw a promote causes (see netFoldOpen above), and
    // for a not-yet-promoted list entry, opening it IS the promote (PLAN_34
    // phase 5) rather than merely revealing something already there.
    if (el.dataset.row !== undefined && el.classList.contains('staxx-devmore')) {
      var nf = MODEL && MODEL.fields[el.dataset.row | 0];
      if (nf) {
        netFoldOpen[nf.id] = el.open;
        if (el.open && el.dataset.promoteNetworks) promoteNetworks(nf);
      }
    }
  }, true);

  formHost.addEventListener('change', function (event) {
    var el = event.target;

    // Choosing "a folder on the server…" from a volume's host dropdown must
    // never reach the file — swap the control instead of committing it.
    if (el.tagName === 'SELECT' && el.dataset.part === 'host' && el.value === VOL_FOLDER_SENTINEL) {
      var f = MODEL && MODEL.fields[el.dataset.row | 0];
      if (f && f.binder === 'volume') { swapVolumeToPath(el); return; }
    }

    // A pick from a list is a decision, not a keystroke — commit it at once. A
    // dropdown fires input as well, so the pending timer that set off has to be
    // dropped or the same edit is written twice.
    if (el.tagName !== 'SELECT') return;
    if (commitTimer) { clearTimeout(commitTimer); commitTimer = null; pendingEl = null; }
    commit(el);
  });

  /* A mapping with nothing on either side is nothing at all, so the entry goes
   * rather than sitting in the file as a bare separator. It waits until the
   * eye has left the row: a value commits on a 250ms pause, so sweeping any
   * earlier would delete the row from under someone who cleared a box to
   * retype it — and moving between the two halves of the same mapping is not
   * leaving it, which is what relatedTarget answers. A null one, focus gone to
   * the document, counts as leaving. */
  formHost.addEventListener('focusout', function (event) {
    var el = event.target;
    if (!MODEL || sanitised || !el.dataset || el.dataset.row === undefined) return;

    var row = el.closest('.staxx-fieldrow');
    if (!row || (event.relatedTarget && row.contains(event.relatedTarget))) return;

    flushPending();                     // whatever was typed goes in first
    var f = MODEL.fields[row.dataset.row | 0];
    if (!f || f.locked) return;
    if (f.binder !== 'port' && f.binder !== 'volume' && f.binder !== 'device') return;

    var h = f.parts.host, c = f.parts.container;
    if (!h || !c || String(h.value).trim() || String(c.value).trim()) return;

    removeRow(f, 'Removed the empty ' + f.binder + ' entry. ' +
                 'Undo is at the bottom if that was wrong.');
  });

  // Ticking or unticking a section. Not a value commit — a bare tick writes
  // no value of its own, so this is its own listener rather than a branch
  // inside the one above that ends at commit(). Unticking is a move, never a
  // delete: the block's lines go into x-unraid.sections verbatim and come
  // back exactly as they were, so there is nothing here for a confirm prompt
  // to warn about — undo still covers a mis-click.
  formHost.addEventListener('change', function (event) {
    var box = event.target;
    if (box.dataset.flag === undefined || !MODEL) return;

    var svc = box.dataset.service, flagKey = box.dataset.flag;
    var sect = SECTIONS_BY_KEY[flagKey];
    if (!sect) return;

    var key   = sect.path.join('.');
    var label = sect.label.toLowerCase();
    var say, ok;

    flushPending();

    if (box.checked) {
      var entry = YAML.readSections(MODEL.doc)[svc];
      entry = entry ? entry[key] : undefined;
      var restoring = !!(entry && entry.lines && entry.lines.length);

      pushUndo('turning on ' + label + ' for "' + svc + '"');
      // Nothing to restore means nothing to write: the tick is remembered in
      // sectionOn until something is put in the section. An entry saying
      // "off" still has to go, though — serviceFlags reads a false ahead of
      // everything else, so leaving one would hide the section it just
      // switched on.
      ok = restoring ? YAML.restoreSection(MODEL.doc, MODEL, svc, key)
                     : entry === false ? YAML.setSectionState(MODEL.doc, MODEL, svc, key, null)
                     : true;
      if (!ok) {
        undoStack.pop();
        updateUndo();
        setYamlStatus('That block is written in a way the form cannot restore — ' +
                      'restore it in the Compose view instead.');
        box.checked = false;
        return;
      }
      (sectionOn[svc] = sectionOn[svc] || {})[flagKey] = true;

      say = 'Turned ' + label + (restoring ? ' back on' : ' on') + ' for "' + svc +
            '". Undo is at the bottom if that was wrong.';

      // Closed before the redraw, not after, so the form is drawn without the
      // panel rather than drawn with it and then having it taken away. The
      // panel hangs off the Container header and the form is about to scroll
      // away from it — the rule is that the panel closes when the form moves,
      // which is why unticking, which moves nothing, leaves it open.
      sectionsOpen[svc] = false;
    } else {
      var fileHasIt = fileFlagCounts(MODEL, svc)[flagKey] > 0;

      pushUndo('turning off ' + label + ' for "' + svc + '"');
      ok = fileHasIt ? YAML.stashSection(MODEL.doc, MODEL, svc, sect.path)
                     : YAML.setSectionState(MODEL.doc, MODEL, svc, key, sect.on ? false : null);
      if (!ok) {
        undoStack.pop();
        updateUndo();
        setYamlStatus('That block is written in a way the form cannot move — ' +
                      'remove it in the Compose view instead.');
        box.checked = true;
        return;
      }
      if (sectionOn[svc]) delete sectionOn[svc][flagKey];

      // Read back what the stash actually recorded rather than assuming the
      // block had something worth keeping in it: stashSection drops entries
      // with no value, and a block that was nothing but those keeps nothing.
      var stashed = fileHasIt ? (YAML.readSections(MODEL.doc)[svc] || {})[key] : undefined;
      var kept    = !!(stashed && stashed.lines && stashed.lines.length);

      // Nothing kept, and off is what this section is anyway — so leave
      // nothing behind. An entry saying "off" here would be residue, and the
      // x-unraid block holding it would outlive the reason it was written.
      // A section that is ON by default is the exception: there, absence
      // means shown, so the false is the only thing keeping it hidden.
      if (!kept && !sect.on) YAML.setSectionState(MODEL.doc, MODEL, svc, key, null);

      say = 'Turned ' + label + ' off for "' + svc + '".' +
            (kept ? ' Its settings are kept — tick it again to bring them back.' : '') +
            ' Undo is at the bottom if that was wrong.';
    }

    structuralEdit(-1, say);

    // Switched on: the panel has just closed and the new group is somewhere
    // below, so take the eye to it and mark which one it is. A section's key
    // is also its group key, so the group needs no attribute of its own to be
    // found by — data-service on the service and data-group on the group are
    // both already emitted.
    if (box.checked) {
      // The form has to be the visible view for a scroll to it to mean
      // anything — the same guard structuralEdit() uses before showing a row.
      if (modalBody.dataset.view === 'yaml') setView(defaultView());

      var grp = formHost.querySelector('.staxx-svc[data-service="' +
                svc.replace(/"/g, '\\"') + '"] .staxx-formgroup[data-group="' +
                flagKey.replace(/"/g, '\\"') + '"]');
      if (!grp) return;

      // block: 'start', not the 'center' used for a single row elsewhere in
      // this file: centring an element taller than the pane puts its top
      // ABOVE the pane, hiding the very heading that names the group.
      grp.scrollIntoView({ behavior: 'smooth', block: 'start' });
      grp.classList.add('staxx-formgroup--landed');

      // preventScroll, or the browser's own focus scroll jumps straight to the
      // control and the smooth scroll then arrives on top of it with a jerk.
      // Nothing focusable leaves focus where it is rather than throwing it to
      // the document.
      var first = grp.querySelector('button:not([disabled]), input:not([disabled]),' +
                                    ' select:not([disabled])');
      if (first) first.focus({ preventScroll: true });
      return;
    }

    // Switched off: the panel is still open and the form has not moved, but
    // the redraw took focus with it. Land it back on the box just clicked.
    var back = formHost.querySelector('[data-flag="' + flagKey.replace(/"/g, '\\"') +
               '"][data-service="' + svc.replace(/"/g, '\\"') + '"]');
    if (back) back.focus();
  });

  /* ---- adding and removing entries ---- */

  /* Both change the SET of rows, so unlike a value edit they redraw the whole
   * form. A value edit deliberately never does — the form already shows what
   * was typed, and rebuilding would take the caret with it — but here there is
   * a row that did not exist a moment ago, or one that has gone. */

  var undoStack = [];

  function updateUndo() {
    var top = undoStack[undoStack.length - 1];
    // fileChrome() switches Undo off while a companion file is open, and this
    // is called from paths that reach it there (find and replace runs on a
    // companion tab) — without the same term it would quietly switch it back
    // on, and undoing there writes the compose file's text into that file.
    undoBtn.disabled = sanitised || fileOpen !== null || !top;
    undoBtn.title = top ? 'Undo ' + top.what : 'Nothing to undo yet';
  }

  // Called BEFORE the document is touched. The compose pane and the model are
  // in step at that moment, which is what makes the snapshot honest. It is the
  // compose file's history, so it snapshots that file's text and not whatever
  // the box happens to be showing — a find and replace run inside a companion
  // file would otherwise put that file's text where Undo restores it into the
  // compose file.
  function pushUndo(what) {
    undoStack.push({ text: currentText(), what: what });
    if (undoStack.length > 25) undoStack.shift();
    updateUndo();
  }

  function structuralEdit(line, say) {
    yamlPane.value = YAML.serialise(MODEL.doc);
    paintGutter();
    paintInk();
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

    // Selected, not just focused: most new entries arrive with a placeholder
    // in them, so typing should replace it rather than append to it. The few
    // that arrive empty (see newEntry in compose-model.js) are unaffected —
    // selecting nothing is the same as not selecting.
    var box = row.querySelector('input:not([disabled])');
    if (box) { box.focus(); box.select(); }
  }

  formHost.addEventListener('click', function (event) {
    if (sanitised || !MODEL) return;

    // The ⓘ beside a field's label or a group's heading. Purely a display
    // toggle — the sentence is already in the markup, hidden — so there is
    // nothing here to reparse or redraw, unlike every other case below.
    var helpBtn = event.target.closest('[data-help]');
    if (helpBtn) {
      var helpBody = document.getElementById(helpBtn.getAttribute('aria-controls'));
      if (helpBody) {
        helpBody.hidden = !helpBody.hidden;
        helpBtn.setAttribute('aria-expanded', helpBody.hidden ? 'false' : 'true');
      }
      return;
    }

    var secBtn = event.target.closest('[data-sections]');
    if (secBtn) {
      // Stopped here, not left to bubble: the document-level listener below
      // closes any open panel on a click outside it, and this button's own
      // click would otherwise count as "outside" a panel it has just opened.
      event.stopPropagation();
      var secSvc = secBtn.dataset.sections;
      sectionsOpen[secSvc] = !sectionsOpen[secSvc];
      flushPending();
      reparse();
      var freshBtn = formHost.querySelector('[data-sections="' + secSvc.replace(/"/g, '\\"') + '"]');
      if (freshBtn) freshBtn.focus();
      return;
    }

    // The "Add it to this file" button beside an undeclared network's advice
    // (adviceText()). Checked ahead of [data-add] below: this button carries
    // no data-add attribute of its own, and sits inside a field row rather
    // than inside a group header, so closest('[data-add]') would not have
    // caught it anyway — but the check still comes first, so a later change
    // to either markup cannot make one swallow the other silently.
    var declareNet = event.target.closest('[data-declare-net]');
    if (declareNet) {
      flushPending();
      pushUndo('adding that network');
      var nLine = YAML.declareNetwork(MODEL.doc, declareNet.dataset.netName);
      if (nLine < 0) {
        undoStack.pop();
        updateUndo();
        setYamlStatus('The networks block in this file is written in a way the form cannot add to — ' +
                      'add the declaration in the Compose view instead.');
        return;
      }
      structuralEdit(nLine, '');
      return;
    }

    var add = event.target.closest('[data-add]');
    if (add) {
      // A device is chosen from what this server actually has, never typed. The
      // placeholder route wrote /dev/dri whether or not the machine had a
      // graphics card, and a second one wrote /dev/dri2, which is not a path to
      // anything at all.
      if (add.dataset.add === 'device') {
        devOpen(add.closest('.staxx-grouphead'), null, add.dataset.service);
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
      // The long form of depends_on: pick the first other service this one
      // does not already depend on, and write condition: service_started —
      // compose's own default, but the one place a blank cannot be left to
      // mean "nothing yet", since a bare "name:" here is null and compose
      // refuses the file.
      if (add.dataset.add === 'depends') {
        var dSvc = add.dataset.service, used = {};
        used[dSvc] = true;   // a service cannot depend on itself
        for (var ui = 0; ui < MODEL.fields.length; ui++) {
          var uf = MODEL.fields[ui];
          if (uf.service !== dSvc) continue;
          if (uf.binder === 'depends' && !uf.fold) used[uf.parts.name.value] = true;
          else if (uf.binder === 'list' && uf.listKey === 'depends_on') used[uf.parts.value.value] = true;
        }
        var candidates = (MODEL.declared.services || []), pick = null;
        for (var ci = 0; ci < candidates.length; ci++) {
          if (!used[candidates[ci]]) { pick = candidates[ci]; break; }
        }
        if (!pick) {
          setYamlStatus(dSvc + ' already depends on every other service in this file, ' +
                        'so there is nothing left to add.');
          return;
        }
        flushPending();
        pushUndo('adding that service dependency');
        var dLine = YAML.addNested(MODEL.doc, MODEL, dSvc, ['depends_on', pick, 'condition'], 'service_started');
        if (dLine < 0) {
          undoStack.pop();
          updateUndo();
          setYamlStatus('That block is written in a way the form cannot add to — ' +
                        'add it in the Compose view instead.');
          return;
        }
        structuralEdit(dLine, '');
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

      // Compose refuses a service with both network_mode and networks: —
      // KEYS.network_mode's own `excludes` in the model. PLAN_34 phase 4
      // took network_mode out of the form, which removed the row that used
      // to block this from the OTHER side (setting network_mode while
      // networks: already existed), so this is now the only guard left
      // against the reverse: adding a network from underneath a service that
      // already sets network_mode. Reported the same way an unreadable list
      // is below — pop the undo entry the generic push above already made,
      // and explain in the status line instead of leaving addItem to refuse
      // with a message about the wrong problem.
      if (listKey === 'networks') {
        var svcNm = null;
        for (var nmi = 0; nmi < MODEL.fields.length; nmi++) {
          var nmf = MODEL.fields[nmi];
          if (nmf.service === add.dataset.service && nmf.binder === 'setting' && nmf.target === 'network_mode') {
            svcNm = nmf;
            break;
          }
        }
        if (svcNm && !svcNm.absent) {
          undoStack.pop();
          updateUndo();
          setYamlStatus(add.dataset.service + ' already sets network_mode, and compose does not allow a ' +
                        'service to have both network_mode and networks — remove network_mode first.');
          return;
        }
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

    // The full-width button below the last service — its own data attribute,
    // not "data-add", since it belongs to no service and addWord() has
    // nothing to say for it.
    var addSvc = event.target.closest('[data-add-service]');
    if (addSvc) {
      var takenNames = {}, existingSvcs = MODEL.declared.services || [];
      for (var ni = 0; ni < existingSvcs.length; ni++) takenNames[existingSvcs[ni]] = true;
      var newSvcName = 'new-container', suffix = 2;
      while (takenNames[newSvcName]) { newSvcName = 'new-container-' + suffix; suffix++; }

      flushPending();
      pushUndo('adding a new container');
      var svcLine = YAML.addService(MODEL.doc, MODEL, newSvcName);
      if (svcLine < 0) {
        undoStack.pop();
        updateUndo();
        setYamlStatus('That could not be added — add it in the Compose view instead.');
        return;
      }
      structuralEdit(svcLine, 'Added "' + newSvcName + '". Undo is at the bottom if that was wrong.');

      // Naming it is the first thing to do with a container nobody has
      // named yet. A real click on its own rename pencil, not a copy of what
      // that click does, so the two can never drift apart.
      var newPencil = formHost.querySelector('[data-svc-rename][data-service="' + newSvcName + '"]');
      if (newPencil) newPencil.click();
      return;
    }

    var rename = event.target.closest('[data-svc-rename]');
    if (rename) {
      var was = rename.dataset.service;
      var nameHost = rename.closest('.staxx-svchead').querySelector('.staxx-svcname');
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
      var nameHost = declRename.closest('.staxx-declname').querySelector('.staxx-declname-text');
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
    removeRow(f, 'Removed ' + f.title + '. Undo is at the bottom if that was wrong.');
  });

  /* Takes one entry out of the file and redraws. Shared by the × button above
   * and by the empty-mapping sweep below, which needs the same care: a row
   * that has gone from the file has to leave the screen with it, or the next
   * edit writes into a field that is no longer there.
   *
   * flushPending() is the caller's job, not this function's — the sweep runs
   * from inside a commit and would otherwise re-enter it. */
  function removeRow(f, say) {
    pushUndo('removing ' + f.title);
    if (!YAML.removeItem(MODEL.doc, MODEL, f.id)) {
      undoStack.pop();
      updateUndo();
      setYamlStatus('That entry is written in a way the form cannot remove — ' +
                    'remove it in the Compose view instead.');
      return false;
    }

    // After the guard, never before it: a removal the model refused leaves the
    // list exactly as it was. A list section defaults off, so a service whose
    // last entry has just gone would otherwise revert to that default and
    // vanish, taking its Add button — the only way back — with it. Remembering
    // the tick is what keeps it on screen, empty, exactly like unticking then
    // re-ticking would. The row itself is still deleted outright; this only
    // records that the section stays open, and in this editor rather than in
    // the file — an empty section is nothing to write down.
    var gk = (f.binder === 'depends' || f.listKey === 'depends_on') ? 'depends'
           : f.listKey ? 'list:' + f.listKey : '';
    if (SECTIONS_BY_KEY[gk] && fileFlagCounts(YAML.buildForm(MODEL.doc), f.service)[gk] === 0) {
      (sectionOn[f.service] = sectionOn[f.service] || {})[gk] = true;
    }
    structuralEdit(-1, say);
    return true;
  }

  // Turns a service's whole networks: block from a list of names into a map
  // of them (PLAN_34 phase 5), called when the "more settings" fold on a
  // not-yet-promoted entry is opened — see the capture-phase 'toggle'
  // listener. Reversible the same way every other structural edit here is.
  function promoteNetworks(f) {
    flushPending();
    pushUndo('turning "' + f.target + '" into a setting');
    var res = YAML.promoteNetworksList(MODEL.doc, f.service);
    if (!res.ok) {
      undoStack.pop();
      updateUndo();
      setYamlStatus(res.error);
      return;
    }
    netFoldOpen[f.id] = true;
    structuralEdit(-1, 'Every network under "' + f.service + '" can now take a fixed address ' +
                       'or a hardware address. Undo is at the bottom if that was wrong.');
  }

  // Moves one port to a new spot in its service's list (PLAN_40) — the same
  // shape the promote control above and the declare-network button use: flush
  // whatever is pending, push one undo entry, ask the model, and pop that
  // entry again if it refuses. from === to is a plain no-op: nothing changed,
  // so nothing is flushed and no undo entry goes on the stack for it.
  function movePort(service, from, to) {
    if (from === to) return;
    flushPending();
    pushUndo('moving that port');
    var res = safeMoveItem(MODEL.doc, MODEL, service, 'ports', from, to);
    if (!res || !res.ok) {
      undoStack.pop();
      updateUndo();
      if (res) setYamlStatus(res.error);
      return;
    }
    structuralEdit(-1, '');
    // structuralEdit() just redrew the whole form, taking focus with it —
    // land it back on the grip that moved, at its new spot, so a keyboard
    // move can be repeated without hunting the row down again.
    var svcSel = '.staxx-svc[data-service="' + service.replace(/"/g, '\\"') + '"]';
    var grp    = formHost.querySelector(svcSel + ' .staxx-formgroup--ports');
    var rows   = grp ? grp.querySelectorAll(':scope > .staxx-fieldrow') : [];
    var landed = rows[Math.min(to, rows.length - 1)];
    var grip   = landed && landed.querySelector('[data-port-grip]');
    if (grip) grip.focus();
  }

  // The row a port grip is dragging, from pointerdown until the drag ends —
  // one variable is enough, since only one row can ever be mid-drag.
  var draggingPortRow = null;
  // The placeholder that holds the gap open while draggingPortRow is hidden,
  // and that row's index among all rows at the moment the drag started. The
  // row itself is only ever hidden, never moved in the DOM, so that index is
  // exactly what 'from' means at drop time.
  var portSlot = null;
  var draggingPortFrom = -1;

  formHost.addEventListener('pointerdown', function (event) {
    var grip = event.target.closest('[data-port-grip]');
    if (!grip) return;
    var row = grip.closest('.staxx-fieldrow');
    if (!row) return;
    // A port row holds text inputs, so it is not draggable by default — a
    // permanently draggable row would swallow the pointer gesture used to
    // select text inside them (dragging inside a box would move the row
    // instead of the caret). The grip arms dragging only for as long as it is
    // actually held; endPortDrag() below disarms it again on release or once
    // a drag finishes. Both paths matter — either alone would leave a row
    // stuck draggable if the other never fired: a plain click with no drag,
    // or a drag released outside the window.
    row.draggable = true;
    draggingPortRow = row;
  });

  function endPortDrag() {
    if (!draggingPortRow) return;
    draggingPortRow.draggable = false;
    draggingPortRow.classList.remove('staxx-portdrag');
    if (portSlot && portSlot.parentNode) portSlot.parentNode.removeChild(portSlot);
    portSlot = null;
    draggingPortRow = null;
  }
  formHost.addEventListener('pointerup', endPortDrag);
  formHost.addEventListener('dragend', endPortDrag);

  formHost.addEventListener('dragstart', function (event) {
    if (!draggingPortRow || event.target.closest('.staxx-fieldrow') !== draggingPortRow) return;
    event.dataTransfer.effectAllowed = 'move';
    // Firefox refuses to start a drag that carries no data at all.
    event.dataTransfer.setData('text/plain', '');

    var row  = draggingPortRow;
    var grp  = row.closest('.staxx-formgroup--ports');
    var rows = grp ? Array.prototype.slice.call(grp.querySelectorAll(':scope > .staxx-fieldrow')) : [];
    draggingPortFrom = rows.indexOf(row);
    var rowHeight = row.offsetHeight;   // measured now, while the row is still laid out

    // Deferred by one tick: Chrome snapshots the drag image asynchronously,
    // so hiding the row synchronously here — inside dragstart itself — blanks
    // or cancels that snapshot instead of leaving it to finish first.
    setTimeout(function () {
      if (draggingPortRow !== row) return;   // drag already over — a click with no drag
      portSlot = document.createElement('div');
      portSlot.className = 'staxx-portslot';
      portSlot.style.height = rowHeight + 'px';
      row.parentNode.insertBefore(portSlot, row);
      row.classList.add('staxx-portdrag');
    }, 0);
  });

  // Every position inside the group accepts the drop unconditionally — that
  // is the whole flicker fix, since there is no longer a dead zone the
  // browser can read as "cannot drop" and flip the cursor over. The target
  // position comes from comparing the pointer to each row's midpoint, not
  // from whatever element happens to be under it.
  formHost.addEventListener('dragover', function (event) {
    if (!draggingPortRow || !portSlot) return;
    var grp = draggingPortRow.closest('.staxx-formgroup--ports');
    if (!grp) return;
    var rect = grp.getBoundingClientRect();
    if (event.clientY < rect.top || event.clientY > rect.bottom ||
        event.clientX < rect.left || event.clientX > rect.right) {
      // Outside the group: refuse the drop and send the placeholder home.
      // The row itself never moved, only hid — "home" is just beside it.
      if (portSlot.nextSibling !== draggingPortRow) {
        draggingPortRow.parentNode.insertBefore(portSlot, draggingPortRow);
      }
      return;
    }
    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';

    var rows = Array.prototype.slice.call(grp.querySelectorAll(':scope > .staxx-fieldrow'))
      .filter(function (r) { return r !== draggingPortRow; });
    var target = null;
    for (var i = 0; i < rows.length; i++) {
      var mid = rows[i].getBoundingClientRect().top + rows[i].offsetHeight / 2;
      if (mid > event.clientY) { target = rows[i]; break; }
    }
    // The hidden row and the placeholder are the same height, so the group's
    // total height never changes as the gap moves — that is what stops the
    // classic gap-drag oscillation, where opening a gap shifts the row out
    // from under the pointer and the decision flips back and forth.
    if (portSlot.nextSibling !== target) grp.insertBefore(portSlot, target);
  });

  formHost.addEventListener('drop', function (event) {
    if (!draggingPortRow || !portSlot) return;
    event.preventDefault();
    var svcSection = draggingPortRow.closest('.staxx-svc');
    var grp        = draggingPortRow.closest('.staxx-formgroup--ports');
    var from       = draggingPortFrom;
    // 'to' is the number of still-visible rows before the placeholder — the
    // final position the moved entry lands at, which is exactly what
    // YAML.moveItem means by 'to'.
    var to = 0;
    if (grp) {
      var kids = grp.children;
      for (var i = 0; i < kids.length; i++) {
        if (kids[i] === portSlot) break;
        if (kids[i] !== draggingPortRow && kids[i].classList.contains('staxx-fieldrow')) to++;
      }
    }
    endPortDrag();   // unhides the row and drops the placeholder either way
    if (!svcSection || from < 0 || to === from) return;
    movePort(svcSection.dataset.service, from, to);
  });

  // The grip is a keyboard control too (PLAN_40) — focused, the arrow keys
  // and Home/End move the row the same way a drag would, which is what makes
  // reordering usable without a mouse. Default is prevented for every key
  // handled here so the page does not scroll under it.
  formHost.addEventListener('keydown', function (event) {
    var grip = event.target.closest('[data-port-grip]');
    if (!grip || sanitised || !MODEL) return;
    var key = event.key;
    if (key !== 'ArrowUp' && key !== 'ArrowDown' && key !== 'Home' && key !== 'End') return;
    event.preventDefault();
    var row        = grip.closest('.staxx-fieldrow');
    var grp        = row && row.closest('.staxx-formgroup--ports');
    var svcSection = row && row.closest('.staxx-svc');
    if (!row || !grp || !svcSection) return;
    var rows = Array.prototype.slice.call(grp.querySelectorAll(':scope > .staxx-fieldrow'));
    var from = rows.indexOf(row);
    if (from < 0) return;
    var to = key === 'ArrowUp'   ? from - 1
           : key === 'ArrowDown' ? from + 1
           : key === 'Home'      ? 0
           :                       rows.length - 1;
    if (to < 0 || to >= rows.length || to === from) return;
    movePort(svcSection.dataset.service, from, to);
  });

  // Closes any open Sections panel: a click outside it, or Escape. The button
  // that opens one stops its own click reaching here (see [data-sections]
  // above), so this only ever sees a click genuinely outside.
  function anySectionsOpen() {
    for (var k in sectionsOpen) if (sectionsOpen[k]) return true;
    return false;
  }
  document.addEventListener('click', function (event) {
    if (!modal.open || !anySectionsOpen()) return;
    if (event.target.closest('.staxx-sections')) return;
    sectionsOpen = {};
    flushPending();
    reparse();
  });
  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape' || !modal.open || !anySectionsOpen()) return;
    sectionsOpen = {};
    flushPending();
    reparse();
  });

  /* ---- structure outline --------------------------------------------------
   *
   * A picture of the file, not a search of it: top-level keys in the order
   * they appear, each service nested under "services". Built once when the
   * panel opens rather than kept live — it is read-only navigation, so there
   * is nothing here that has to stay in step with the file the way the
   * Sections panel above does.
   *
   * MODEL's ranges are 0-based line indexes, like everywhere else in this
   * editor; the gutter numbers lines from 1. outlineRowHtml() is the one
   * place that +1 happens.
   */

  function outlineOpen() {
    return !!(outlinePanel && !outlinePanel.hidden);
  }

  function closeOutline() {
    if (!outlineOpen()) return;
    outlinePanel.hidden = true;
    outlinePanel.innerHTML = '';
    outlineBtn.setAttribute('aria-expanded', 'false');
  }

  function outlineRowHtml(cls, name, line) {
    return '<div class="staxx-outline-row ' + cls + '" role="menuitem" data-line="' + line + '">' +
           '<span>' + esc(name) + '</span>' +
           '<span class="staxx-outline-line">' + (line + 1) + '</span></div>';
  }

  function outlineHtml() {
    if (!MODEL || !MODEL.ok) {
      return '<div class="staxx-outline-empty">This file could not be read, so there is ' +
             'nothing to list yet. Fix it in the Compose view first.</div>';
    }
    var root = MODEL.doc.root;
    if (!root.keys.length) {
      return '<div class="staxx-outline-empty">There is nothing in this file yet.</div>';
    }

    var out = [];
    for (var i = 0; i < root.keys.length; i++) {
      var key = root.keys[i];
      out.push(outlineRowHtml('staxx-outline-row--top', key, root.pairs[key].start));
      if (key !== 'services') continue;
      // The service's own key line, taken from the document rather than from
      // svc.range.start. A service's RANGE deliberately opens at the comment
      // introducing it, which is right for the form — the comment belongs to
      // the service — but wrong for a list saying "quirks-alpha, line 56":
      // it would name a line holding a comment, and send you one line short
      // of the thing you asked for.
      var nodes = root.pairs.services.value && root.pairs.services.value.pairs;
      for (var s = 0; s < MODEL.services.length; s++) {
        var svc = MODEL.services[s];
        var node = nodes && nodes[svc.name];
        var at = node && typeof node.start === 'number' ? node.start : svc.range.start;
        out.push(outlineRowHtml('staxx-outline-row--svc', svc.name, at));
      }
    }
    return out.join('');
  }

  function openOutline() {
    if (!outlineBtn || !outlinePanel) return;
    outlinePanel.innerHTML = outlineHtml();
    outlinePanel.hidden = false;
    outlineBtn.setAttribute('aria-expanded', 'true');
  }

  // A 0-based line number (as MODEL gives it) to a caret offset. Counted with
  // indexOf rather than split(), same reasoning lineCount() gives above.
  function offsetOfLine(line) {
    var text = yamlPane.value, at = 0;
    for (var i = 0; i < line; i++) {
      var nl = text.indexOf('\n', at);
      if (nl === -1) return text.length;
      at = nl + 1;
    }
    return at;
  }

  if (outlineBtn) {
    outlineBtn.addEventListener('click', function (event) {
      if (fileOpen !== null) return;   // a companion file has no structure to outline
      // Stopped here, same reasoning as the Sections button's own click
      // above: left to bubble, the document-level "click outside" listener
      // below would see this same click and close what it has just opened.
      event.stopPropagation();
      if (outlineOpen()) closeOutline(); else openOutline();
    });
  }

  if (outlinePanel) {
    outlinePanel.addEventListener('click', function (event) {
      var row = event.target.closest('.staxx-outline-row');
      if (!row) return;
      var line = parseInt(row.dataset.line, 10);
      closeOutline();
      revealLine(line);
      var at = offsetOfLine(line);
      yamlPane.focus();
      yamlPane.setSelectionRange(at, at);
    });
  }

  document.addEventListener('click', function (event) {
    if (!modal.open || !outlineOpen()) return;
    if (event.target.closest('.staxx-outlinewrap')) return;
    closeOutline();
  });
  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape' || !modal.open || !outlineOpen()) return;
    // preventDefault here, not left to the dialog's own Escape-closes-me
    // action, is the same trick the suggestion list and find bar rely on
    // (see their own comments) — it is what keeps this closing only the
    // outline panel rather than the whole editor.
    event.preventDefault();
    closeOutline();
    outlineBtn.focus();
  });

  undoBtn.addEventListener('click', function () {
    var step = undoStack.pop();
    if (!step) return;
    yamlPane.value = step.text;
    paintGutter();
    paintInk();
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

  var LINE_H = 0, PAD_T = 0, CHAR_W = 0;

  function measure() {
    var cs = window.getComputedStyle(yamlPane);
    LINE_H = parseFloat(cs.lineHeight);
    PAD_T  = parseFloat(cs.paddingTop);
    // A theme that leaves line-height at `normal` gives a keyword, not pixels,
    // and every band would land at the top of the box.
    if (!LINE_H) LINE_H = parseFloat(cs.fontSize) * 1.45;
    if (!PAD_T) PAD_T = 0;

    // A character's width, needed only for search-hit boxes — the active-field
    // band spans the full line and never had to sit at a particular column. A
    // probe span in the pane's own monospace font, a run of characters rather
    // than one so a fraction-of-a-pixel rounding error does not add up into a
    // visibly wrong column far along a long line, measured once here (not per
    // hit) and removed straight after.
    var probe = document.createElement('span');
    probe.style.cssText = 'position:absolute;visibility:hidden;white-space:pre;' +
      'font-family:' + cs.fontFamily + ';font-size:' + cs.fontSize + ';';
    var sample = '0123456789012345678901234567890123456789';
    probe.textContent = sample;
    yamlWrap.appendChild(probe);
    CHAR_W = probe.getBoundingClientRect().width / sample.length;
    probe.remove();
  }

  function repaintMark() {
    yamlMarks.textContent = '';
    // The band is a range of compose-file lines, which mean nothing painted
    // over a companion file's text. missingHostPaths() itself returns empty
    // for a companion file, so this still hides the "create folders" note
    // rather than leaving it showing over a file with no volumes at all.
    if (fileOpen !== null) { updateMissingPaths(); updateInUsePaths(); return; }
    if (!LINE_H) measure();

    if (activeField && MODEL) {
      var f = YAML.fieldById(MODEL, activeField);
      if (f && f.range) {
        var band = document.createElement('div');
        band.className = 'staxx-mark';
        band.style.top    = (PAD_T + f.range.start * LINE_H - yamlPane.scrollTop) + 'px';
        band.style.height = ((f.range.end - f.range.start) * LINE_H) + 'px';
        yamlMarks.appendChild(band);
      }
    }

    // Search hits share this layer rather than a second one of their own, and
    // are drawn here — not in a parallel function with its own call sites —
    // so every place that already repaints this layer (scroll, view switch,
    // dialog resize, caret move) keeps the hits in step for free. Appended
    // after the band, so a hit that falls inside the active field's band is
    // still legible on top of it rather than washed out underneath.
    repaintHits();

    // Bad host paths, same layer and same reasoning — see repaintPaths()
    // below. Drawn last so a path mark under a search hit still shows
    // through: the hit is a fill, the path mark only an underline.
    repaintPaths();
    updateMissingPaths();
    updateInUsePaths();
  }

  // The visible slice of search hits, drawn into #staxx-yamlmarks. A plain
  // substring search over a large file can match thousands of times (see
  // YAML.searchMatches' 5000 cap) and almost none of them are on screen at
  // once — building a box for each would be a page-freezing amount of DOM for
  // a result nobody can see, so only the visible range (plus a small margin)
  // is drawn. The counter above the pane still reflects every match; only the
  // drawing is limited. The current hit is always drawn even if the line math
  // puts it just outside the margin, so stepping to it never looks like it
  // vanished for the instant before the scroll catches up.
  function repaintHits() {
    if (!findMatches.length) return;

    var text = yamlPane.value;

    // #staxx-yamlmarks' own box already starts just past the gutter —
    // paintGutter() offsets its "left" by the gutter's measured width — while
    // the textarea's padding-left goes a few pixels further so the text has
    // room to breathe. The difference between the two, read back rather than
    // recomputed, is how far into THIS layer a hit box has to start to land
    // under the first real character of a line.
    var padLeft  = parseFloat(yamlPane.style.paddingLeft) || 0;
    var markLeft = parseFloat(yamlMarks.style.left) || 0;
    var leftBase = padLeft - markLeft;

    var top = yamlPane.scrollTop, viewH = yamlPane.clientHeight;
    var firstLine = Math.floor((top - PAD_T) / LINE_H) - 2;
    var lastLine  = Math.ceil((top + viewH - PAD_T) / LINE_H) + 2;

    for (var i = 0; i < findMatches.length; i++) {
      var m = findMatches[i];
      var isCurrent = i === findCurrent;
      if (!isCurrent && (m.line < firstLine || m.line > lastLine)) continue;

      // A match that spans a newline (only possible with regex on) is drawn
      // only on its first line — this pane assumes one box per source line
      // everywhere else, and a wrapped second box is more machinery than a
      // rare regex edge case is worth.
      var nl = text.indexOf('\n', m.start);
      var lineEnd = nl === -1 ? text.length : nl;
      var len = Math.max(1, Math.min(m.end, lineEnd) - m.start);

      var box = document.createElement('div');
      box.className = 'staxx-hit' + (isCurrent ? ' staxx-hit--current' : '');
      box.style.top    = (PAD_T + m.line * LINE_H - yamlPane.scrollTop) + 'px';
      box.style.left   = (leftBase + m.col * CHAR_W - yamlPane.scrollLeft) + 'px';
      box.style.width  = (len * CHAR_W) + 'px';
      box.style.height = LINE_H + 'px';
      yamlMarks.appendChild(box);
    }
  }

  /* ---- host path existence ------------------------------------------------
   *
   * A volume's host side is often just a typo, or a mount that has not been
   * created yet. Asking the server is a courtesy, never part of what gets
   * written — every failure here (a slow reply, a timeout, the model not
   * having landed) is "no information" and stays silent, the same way every
   * other advisory lookup in this editor (devLoad, netLoad, imgLoad) treats
   * its own failures.
   *
   * Cached by path STRING, not by line: the same host path often appears on
   * several volumes, or several services, in one file and only needs asking
   * about once per editor session. pathsReset() below drops the cache along
   * with the marks, so nothing survives from one stack's editor into the
   * next.
   */
  var pathCache = {};   // path string -> 'ok' | 'file' | 'missing' | 'skipped' | 'inuse'
  var pathHits  = [];   // last YAML.hostPaths() result: [{path, line, col, len}]
  var pathToken = 0;    // bumped by pathsReset() so a late reply cannot paint

  function pathsReset() {
    pathToken++;
    pathCache = {};
    pathHits = [];
    // The boxes themselves, not just the arrays behind them — the same
    // reasoning findReset() gives for clearing .staxx-hit: left in place
    // they would show, briefly, at positions measured against a different
    // file.
    var stale = yamlMarks.querySelectorAll('.staxx-badpath');
    for (var i = 0; i < stale.length; i++) stale[i].remove();
    makePathsNote.hidden = true;
    makePathsNote.textContent = '';
    inUseNote.hidden = true;
    inUseNote.textContent = '';
    inUseText = '';
  }

  // Called from reparse(), which is already debounced 400ms behind typing —
  // this piggybacks on that pause rather than starting a second timer.
  function checkHostPaths() {
    if (fileOpen !== null) return;   // a companion file has no volumes to check
    if (!YAML || typeof YAML.hostPaths !== 'function') return;   // not landed yet

    pathHits = YAML.hostPaths(yamlPane.value);
    repaintMark();   // redraw at once with whatever the cache already knows

    var unknown = [];
    pathHits.forEach(function (h) {
      if (!(h.path in pathCache) && unknown.indexOf(h.path) < 0) unknown.push(h.path);
    });
    if (!unknown.length) return;

    // One request for everything not already answered, not one per path —
    // twenty mounts in a file is twenty entries in one array, not twenty
    // round trips.
    // isNew: only a stack being created gets the "inuse" verdict — an
    // existing stack's volumes are expected to already hold its own data, so
    // asking for that flag there would just teach people to ignore it.
    var myToken = pathToken;
    call('paths', { name: openedName, paths: JSON.stringify(unknown), isNew: modal.dataset.new }, 4000)
      .then(function (res) {
        // A reply for a stack that has since closed, or been replaced by
        // another — stay quiet rather than painting marks over someone
        // else's file. call() itself never rejects (see its own comment),
        // so a bad reply lands here as res.ok === false, handled the same
        // way as a token mismatch: simply nothing drawn.
        if (myToken !== pathToken || !res || !res.ok || !res.paths) return;
        Object.keys(res.paths).forEach(function (p) { pathCache[p] = res.paths[p]; });
        repaintMark();
      });
  }

  // The distinct host paths currently underlined as 'missing' — not 'file'
  // (nothing to create there) and not 'skipped' or 'ok'. Recomputed fresh on
  // every call rather than cached, the same reasoning missingNote's click
  // handler gives: a path made or corrected between the last redraw and a
  // click must never be acted on as stale.
  //
  // Only paths under /mnt are offered, because that is the only place the
  // server will make one — a button that can only ever answer "folders can
  // only be made under /mnt" is worse than no button. A relative path is
  // still underlined; it just does not get the offer, since whether it lands
  // under /mnt depends on where the stack root is and only the server knows.
  function missingHostPaths() {
    if (fileOpen !== null) return [];   // a companion file has no volumes at all
    var out = [];
    pathHits.forEach(function (h) {
      if (h.path.indexOf('/mnt/') !== 0) return;
      if (pathCache[h.path] === 'missing' && out.indexOf(h.path) < 0) out.push(h.path);
    });
    return out;
  }

  // Note text for #staxx-makepaths — same shape as updateMissing() above,
  // but for a volume's host side rather than a file inside the stack. Called
  // from repaintMark(), which already runs after every reply checkHostPaths()
  // gets back and after every redraw that could change which paths are still
  // missing (a view switch, a scroll, a field focus) — so this needs no call
  // sites of its own.
  // Written only when it actually changes: repaintMark() runs on every scroll
  // frame, and rewriting the same sentence into the DOM sixty times a second
  // dirties a node that never moved.
  var makePathsText = '';

  function updateMissingPaths() {
    var missing = missingHostPaths();
    var text = !missing.length ? ''
      : (missing.length === 1
          ? '"' + missing[0] + '" is named in this compose file but does not exist on the server yet. Create it.'
          : missing.length + ' folders named in this compose file do not exist yet. Create them.');
    if (text === makePathsText) return;
    makePathsText = text;
    makePathsNote.textContent = text;
    makePathsNote.hidden = text === '';
  }

  // The distinct host paths currently underlined as 'inuse' — an existing,
  // non-empty folder, only ever reported for a stack being created (see
  // checkHostPaths()'s isNew flag). No /mnt-only filter here, unlike
  // missingHostPaths() above: this note has no button and nothing to act on,
  // so there is no "can the server reach it" question to narrow it by.
  function inUseHostPaths() {
    if (fileOpen !== null) return [];   // a companion file has no volumes at all
    var out = [];
    pathHits.forEach(function (h) {
      if (pathCache[h.path] === 'inuse' && out.indexOf(h.path) < 0) out.push(h.path);
    });
    return out;
  }

  // Note text for #staxx-inusepaths — same shape and same call sites as
  // updateMissingPaths() just above, but for the "this folder already has
  // something in it" caution rather than the "nothing here yet" one.
  var inUseText = '';

  function updateInUsePaths() {
    var inuse = inUseHostPaths();
    var text = !inuse.length ? ''
      : (inuse.length === 1
          ? '"' + inuse[0] + '" already has files in it. Starting this stack would point it at whatever is already there — check that is what you mean before starting it.'
          : inuse.length + ' folders named in this compose file already have files in them. Starting this stack would point it at whatever is already there — check each one before starting it.');
    if (text === inUseText) return;
    inUseText = text;
    inUseNote.textContent = text;
    inUseNote.hidden = text === '';
  }

  // True while a make-paths request is in flight, so a second click cannot
  // fire a second request for the same folders before the first has answered.
  var makePathsBusy = false;

  makePathsNote.addEventListener('click', function () {
    if (makePathsBusy) return;
    var missing = missingHostPaths();
    if (!missing.length) return;

    makePathsBusy = true;
    call('make-paths', { name: openedName, paths: JSON.stringify(missing) }, 8000)
      .then(function (res) {
        makePathsBusy = false;
        if (!res || !res.ok || !res.results) {
          setYamlStatus((res && res.error) || 'Could not create these folders.');
          return;
        }

        // Every path just asked about, made or not, is stale cached knowledge
        // now — dropped so checkHostPaths() below asks the server again
        // rather than assuming today's answer still holds.
        var errors = [];
        Object.keys(res.results).forEach(function (p) {
          var r = res.results[p];
          if (r && r.status === 'error') errors.push(r.error || ('Could not create "' + p + '".'));
          delete pathCache[p];
        });

        // Server messages shown exactly as written, never reworded or
        // merged — the same courtesy the compose-check error path gives.
        if (errors.length) {
          setYamlStatus(errors[0] +
            (errors.length > 1 ? '  (and ' + (errors.length - 1) + ' more failed)' : ''));
        }
        checkHostPaths();   // re-ask, so the underlines that were fixed disappear on their own
      });
  });

  /* ---- does compose itself accept this file? --------------------------
   *
   * Our own lint and the schema check catch structure; neither has a rule
   * for a value that parses fine but means nothing to compose — restart:
   * alwyas, network_mode: hots (see PLAN_20). Docker Compose's own
   * `config -q` already rejects the first and warns on nothing we would
   * ever guess, so asking it is a courtesy, not a replacement for a
   * vocabulary list. Debounced separately from, and longer than, reparse()'s
   * own 400ms — a network round trip is not worth firing on every pause in
   * typing the way checkHostPaths() above does.
   *
   * Advisory only, exactly like checkHostPaths(): never blocks Save, never
   * disables anything, never rewrites the text. Every failure — a slow
   * reply, a timeout, no compose on the server, ok:false — is "no
   * information" and stays silent.
   */
  var checkTimer   = null;   // the debounce handle, 800ms after typing settles
  var checkSeq     = 0;      // bumped by every request sent, so a superseded reply cannot paint
  var checkedText  = null;   // text the last request was sent for, so an unchanged pane asks only once
  var checkVerdict = null;   // what that answer was, so an unchanged pane can put its mark back

  // Called from reparse(). relint() has just cleared checkDot, so an answer
  // we already have has to be put back rather than merely not re-asked for:
  // reparse() runs on things other than typing — netLoad()'s first reply is
  // one — and without this the mark would vanish a second after it appeared
  // and never return, since the text never changed to trigger a new ask.
  function scheduleCheck() {
    if (fileOpen !== null) return;   // a companion file is not the compose file
    if (checkTimer) clearTimeout(checkTimer);

    var text = currentText();
    if (text === checkedText) {
      if (checkVerdict) { checkDot = checkVerdict; redrawDots(); }
      return;
    }

    checkTimer = setTimeout(function () {
      checkTimer = null;
      runCheck(text);
    }, 800);
  }

  function runCheck(text) {
    checkedText  = text;
    checkVerdict = null;
    var mySeq = ++checkSeq;
    call('check', { name: openedName, body: text }, 4000)
      .then(function (res) {
        // A reply for text an edit has since moved past — call() itself
        // never rejects (see its own comment), so a bad reply lands here as
        // res.ok === false and is handled the same way as a superseded
        // sequence number: simply nothing painted.
        if (mySeq !== checkSeq || !res || !res.ok) return;

        if (!res.valid) {
          // Reuses markSaveError()'s own line extraction so the compose
          // line -> gutter line mapping lives in one place. A message that
          // names no line is shown in the status line rather than guessed at.
          // Compose names a line for a YAML syntax error, but only a path
          // for a schema one ("services.web.pull_policy 'alwyas' does not
          // match …"). Both end up on the right line; only the way of
          // finding it differs.
          var line = lineFromMessage(res.error);
          if (line === null) line = lineFromPath(res.error);
          if (line !== null) {
            checkVerdict = { line: line, level: 'error', message: res.error };
            checkDot = checkVerdict;
            redrawDots();
          } else if (res.error) {
            setYamlStatus(res.error);
          }
        } else if (res.warnings && res.warnings.length) {
          // Warnings carry no line of their own, so the status line is the
          // only place for them — never a guessed gutter dot.
          var extra = res.warnings.length - 1;
          setYamlStatus(res.warnings[0] + (extra ? '  (and ' + extra + ' more)' : ''));
        }
      });
  }

  // The visible slice of bad host paths, drawn into the same layer as the
  // active-field band and the search hits above — see repaintMark()'s own
  // comment for why one layer serves all of them. Only 'missing', 'file' and
  // 'inuse' are ever drawn; 'ok', 'skipped', and any path the server has not
  // answered for yet, are left alone.
  function repaintPaths() {
    if (!pathHits.length) return;

    // Same geometry and the same visible-range trim as repaintHits() above.
    var padLeft  = parseFloat(yamlPane.style.paddingLeft) || 0;
    var markLeft = parseFloat(yamlMarks.style.left) || 0;
    var leftBase = padLeft - markLeft;

    var top = yamlPane.scrollTop, viewH = yamlPane.clientHeight;
    var firstLine = Math.floor((top - PAD_T) / LINE_H) - 2;
    var lastLine  = Math.ceil((top + viewH - PAD_T) / LINE_H) + 2;

    for (var i = 0; i < pathHits.length; i++) {
      var h = pathHits[i];
      var verdict = pathCache[h.path];
      if (verdict !== 'missing' && verdict !== 'file' && verdict !== 'inuse') continue;
      if (h.line < firstLine || h.line > lastLine) continue;

      var box = document.createElement('div');
      box.className = 'staxx-badpath' +
        (verdict === 'file' ? ' staxx-badpath--file' : verdict === 'inuse' ? ' staxx-badpath--inuse' : '');
      box.style.top    = (PAD_T + h.line * LINE_H - yamlPane.scrollTop) + 'px';
      box.style.left   = (leftBase + h.col * CHAR_W - yamlPane.scrollLeft) + 'px';
      box.style.width  = (h.len * CHAR_W) + 'px';
      box.style.height = LINE_H + 'px';
      yamlMarks.appendChild(box);
    }
  }

  // Hit-test for the hover panel: is (line, col) inside a currently-drawn bad
  // path mark? Same list repaintPaths() draws from, so a mark is hoverable
  // exactly where it is visible.
  function pathMarkAt(line, col) {
    for (var i = 0; i < pathHits.length; i++) {
      var h = pathHits[i];
      var verdict = pathCache[h.path];
      if (verdict !== 'missing' && verdict !== 'file' && verdict !== 'inuse') continue;
      if (h.line === line && col >= h.col && col < h.col + h.len) {
        return { path: h.path, verdict: verdict };
      }
    }
    return null;
  }

  function pathHoverText(mark) {
    if (mark.verdict === 'file') {
      return mark.path + ' is a file, not a folder. A volume\'s host side must be a folder.';
    }
    if (mark.verdict === 'inuse') {
      return mark.path + ' already has files in it. Another container may already be using it. ' +
        'Starting this stack would point this container at that same data.';
    }
    return 'Nothing exists at ' + mark.path + ' on the server. Create the folder, or correct the path.';
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
    var rows = formHost.querySelectorAll('.staxx-fieldrow');
    for (var i = 0; i < rows.length; i++) {
      rows[i].classList.toggle('staxx-fieldrow--active', rows[i].dataset.fieldRow === id);
    }
    if (reveal && MODEL) {
      var f = YAML.fieldById(MODEL, id);
      if (f && f.range) revealLine(f.range.start);
    }
    repaintMark();
    syncGutter();
  }

  formHost.addEventListener('focusin', function (event) {
    var row = event.target.closest('.staxx-fieldrow');
    if (row) focusField(row.dataset.fieldRow, true);
  });

  formHost.addEventListener('click', function (event) {
    // Not for a button. Remove has already torn its row out by the time this
    // runs, and highlighting a row that no longer exists leaves the band
    // pointing at nothing.
    if (event.target.closest('button')) return;
    var row = event.target.closest('.staxx-fieldrow');
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
      var secs = formHost.querySelectorAll('.staxx-svc');
      for (var i = 0; i < secs.length; i++) {
        secs[i].classList.toggle('staxx-svc--active', secs[i].dataset.service === svc);
      }
      var rows = formHost.querySelectorAll('.staxx-fieldrow--active');
      for (var j = 0; j < rows.length; j++) rows[j].classList.remove('staxx-fieldrow--active');
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

    // The replace half writes into whichever text is on screen right now —
    // the redacted copy while Sanitise is on, which is thrown away the moment
    // it goes off — so it is disabled for the same reason the form's own
    // controls are above. Find is left alone: reading redacted text is
    // harmless, just less useful.
    if (findWith)  findWith.disabled  = on;
    if (findOne)   findOne.disabled   = on;
    if (findAll)   findAll.disabled   = on;

    updateUndo();
    paintGutter();
    paintInk();
    syncGutter();
    repaintMark();
    // The pane's own text just swapped between real and redacted, so a search
    // over it has to notice — quietly, same as reparse()/refreshRanges().
    findRecompute();
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
      out.push('<button type="button" class="staxx-pickrow staxx-pickrow--up" data-path="' +
               esc(res.up) + '"><i class="fa fa-level-up" aria-hidden="true"></i> ' +
               esc(res.up) + '</button>');
    }
    for (var i = 0; i < res.dirs.length; i++) {
      out.push('<button type="button" class="staxx-pickrow" data-path="' +
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
    var row = event.target.closest('.staxx-pickrow');
    if (row) pickerLoad(row.dataset.path, '');
  });

  document.getElementById('staxx-picker-make').addEventListener('click', pickerMake);

  // There is no <form> in the dialog, so Enter does nothing on its own — and
  // without this it would reach the dialog and close it instead.
  pickerNew.addEventListener('keydown', function (event) {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    pickerMake();
  });

  document.getElementById('staxx-picker-use').addEventListener('click', function () {
    if (pickerFor) {
      pickerFor.value = pickerAt;
      // Assigning .value fires no input event, so nothing else would ever
      // notice this. The compose form commits its own boxes through
      // commit(), which expects a row of MODEL.fields behind it — anything
      // else that opens this picker (the Settings panel's stack directory
      // box, for one) has no such row, so it only gets a plain input event
      // for its own listener to notice instead.
      if (formHost.contains(pickerFor)) commit(pickerFor);
      else pickerFor.dispatchEvent(new Event('input', { bubbles: true }));
      pickerFor.focus();
    }
    picker.close();
  });

  document.getElementById('staxx-picker-cancel').addEventListener('click', function () {
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
      r.setAttribute('class', 'staxx-tz-band');
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
        t.setAttribute('class', 'staxx-tz-banglabel');
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
    return '<button type="button" class="staxx-tzrow' +
             (z.name === chosen ? ' staxx-tzrow--on' : '') +
           '" data-zone="' + esc(z.name) + '">' +
             '<span class="staxx-tzcity">' + esc(z.city) + '</span>' +
             '<span class="staxx-tzname">' + esc(z.name) + '</span>' +
             '<span class="staxx-tzoff">now ' + tzOffset(z.now) +
               (z.abbr ? ' · ' + esc(z.abbr) : '') + '</span>' +
           '</button>';
  }

  function tzPaintList(list, why) {
    var chosen = tzFor ? String(tzFor.value).trim() : '';
    tzList.innerHTML = list.length
      ? list.map(function (z) { return tzRowHtml(z, chosen); }).join('')
      : '<p class="staxx-form-empty">Nothing matches that. Try part of a city name.</p>';
    tzList.scrollTop = 0;
    tzMsg.textContent = why || '';

    var on = tzList.querySelector('.staxx-tzrow--on');
    if (on) on.scrollIntoView({ block: 'center' });
  }

  function tzShowBand(h) {
    tzBand = h;
    var rects = tzBands.querySelectorAll('.staxx-tz-band');
    for (var i = 0; i < rects.length; i++) {
      rects[i].classList.toggle('staxx-tz-band--on', (rects[i].dataset.offset | 0) === h);
    }
    var chips = tzChips.querySelectorAll('.staxx-tzchip');
    for (var j = 0; j < chips.length; j++) {
      chips[j].classList.toggle('staxx-tzchip--on', (chips[j].dataset.offset | 0) === h);
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
      ? '<span class="staxx-tzchips-lead">Past the date line:</span>' +
        out.map(function (h) {
          return '<button type="button" class="staxx-tzchip" data-offset="' + h + '">' +
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
    var r = event.target.closest('.staxx-tz-band');
    if (r) tzShowBand(r.dataset.offset | 0);
  });

  tzBands.addEventListener('mousemove', function (event) {
    var r = event.target.closest('.staxx-tz-band');
    if (!r || !tzZones) return;
    var h = r.dataset.offset | 0;
    if (h === tzBand) return;
    var n = tzInBand(h).length;
    tzCaption.textContent = 'UTC' + (h > 0 ? '+' + h : h) + ' — ' +
      n + (n === 1 ? ' place' : ' places');
  });

  tzChips.addEventListener('click', function (event) {
    var c = event.target.closest('.staxx-tzchip');
    if (c) tzShowBand(c.dataset.offset | 0);
  });

  tzList.addEventListener('click', function (event) {
    var row = event.target.closest('.staxx-tzrow');
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
    var rects = tzBands.querySelectorAll('.staxx-tz-band--on');
    for (var i = 0; i < rects.length; i++) rects[i].classList.remove('staxx-tz-band--on');

    var hits = tzZones.filter(function (z) {
      return (z.name + ' ' + z.city).toLowerCase().indexOf(q) >= 0;
    });
    tzCaption.textContent = hits.length + (hits.length === 1 ? ' match' : ' matches') +
                            ' anywhere in the world';
    tzPaintList(hits, '');
  });

  document.getElementById('staxx-tz-cancel').addEventListener('click', function () {
    tzModal.close();
  });

  tzModal.addEventListener('click', function (event) {
    if (event.target !== tzModal) return;
    var r = tzModal.getBoundingClientRect();
    if (event.clientX < r.left || event.clientX > r.right ||
        event.clientY < r.top  || event.clientY > r.bottom) tzModal.close();
  });

  tzModal.addEventListener('close', function () { tzFor = null; });

  /* ---- the Apps browser ---- */

  /* Community Applications is roughly 4,100 templates — about 24 MB of JSON —
   * far too much to ship to the browser and filter here, so every search is a
   * round trip to ca-search (include/action.php), which keeps the catalogue
   * on the server and hands back only the page of results asked for.
   *
   * The first search after a fresh install (or after the cached catalogue
   * expires) has nothing to answer with yet — the server is still downloading
   * it, which takes about a minute — so ca-search replies `state: 'building'`
   * instead of results, and this polls every three seconds until it flips to
   * `ready` rather than making the user retype their search once it has. */

  var caModal  = document.getElementById('staxx-ca');
  var caBox    = document.getElementById('staxx-ca-search');
  var caCatSel = document.getElementById('staxx-ca-cat');
  var caList   = document.getElementById('staxx-ca-list');
  var caMsg    = document.getElementById('staxx-ca-msg');
  var caTabHome   = document.getElementById('staxx-ca-tab-home');
  var caTabSearch = document.getElementById('staxx-ca-tab-search');

  // The details window a card opens. A sibling dialog, not nested inside
  // staxx-ca, so Escape closing the search dialog does not take a focused
  // child down with it — instead caModal's own 'close' handler below force-
  // closes this one, the way the editor's does for the picker and timezone
  // dialogs.
  var caAppModal = document.getElementById('staxx-ca-app');
  var caAppIcon  = document.getElementById('staxx-ca-app-icon');
  var caAppTitle = document.getElementById('staxx-ca-app-title');
  var caAppBy    = document.getElementById('staxx-ca-app-by');
  var caAppAdd   = document.getElementById('staxx-ca-app-add');
  var caAppClose = document.getElementById('staxx-ca-app-close');
  var caAppBody  = document.getElementById('staxx-ca-app-body');

  var caApps  = [];     // the last results rendered — caAdd()/caDetails() read the app's name back out of this
  var caTimer = null;   // the search box's debounce handle
  var caPoll  = null;   // the "still building" poll handle — cleared on close so a shut dialog never keeps polling
  var caCats  = false;  // whether the category <select> has been filled in yet
  var caCatCount = null;   // the whole catalogue's size, from the last ca-search reply — for the footer message only
  var caSearched = false;  // whether caSearch() has run yet in this open — so the Search tab knows to run one rather than show a blank list

  // The dialog opens on a curated homepage rather than a search; caView says
  // which of the two views of this one panel is on screen, caHomeData is the
  // last ca-home reply (kept so switching back from Search needs no refetch),
  // and caPage is which two-row page each home section is currently showing.
  var caView = 'home';
  var caHomeData = null;
  var CA_ROWS = 2;   // rows per pager page — every "two rows" below means this
  var caPage = { spot: 0, new: 0, trend: 0 };
  var caFitTimer = null;   // the resize listener's debounce handle, cleared in caModal's 'close' handler below

  var caAppOrdinal = null;  // which ordinal the open details window is showing, or is mid-fetch for
  var caAppRecord  = null;  // the full ca-app record behind the open details window — kept so its own
                            // Add button does not ask the server again for what it is already looking at

  // The two extra sources, B2-B5. Docker Hub is a network round trip on every
  // keystroke, so it gets its own debounce and a stamp to discard a reply for
  // a query the user has since moved on from. Local images are fetched once
  // per dialog open and filtered here rather than asked of docker per keystroke.
  var caHubHits    = [];    // the last Docker Hub reply rendered, capped at 25
  var caHubTimer   = null;  // Docker Hub's own 600ms debounce handle, separate from caTimer's 250ms
  var caHubStamp   = 0;     // bumped on every request; a reply is only used while it still matches this
  var caLocalImages = null; // every repo:tag on this server, fetched once by caOpen()
  var caLocalHits   = [];   // caLocalImages filtered by the search box, capped at 15
  var caLocalTotal  = 0;    // the filtered count before that cap, so the footer can say "first 15 of N"

  // caAddImage()'s own lookup — same stamp precedent as caHubStamp, plus a
  // busy flag: a second click while one is still running must do nothing,
  // not race the first for which editor opens.
  var caFactsStamp = 0;
  var caFactsBusy  = false;

  function caStopPoll() {
    if (caPoll) { clearTimeout(caPoll); caPoll = null; }
  }

  // Filled once, from whichever reply first carries a category list. Nothing
  // here touches the select's value, so whatever the user has chosen (only
  // ever "Every category" until that first reply lands) is left alone.
  function caFillCats(categories) {
    if (caCats || !categories || !categories.length) return;
    caCats = true;
    categories.forEach(function (cat) {
      var opt = document.createElement('option');
      opt.value = cat;
      opt.textContent = cat;
      caCatSel.appendChild(opt);
    });
  }

  // The feed is third-party data and these values land in href/src
  // attributes. esc() only escapes markup characters, not scheme — one live
  // entry's project link is "https://https://emby.media/", so a bare esc()
  // would still emit a malformed-but-well-formed-looking URL. Anything that
  // is not http(s) is dropped rather than "fixed".
  function caUrl(u) { return (typeof u === 'string' && /^https?:\/\//i.test(u)) ? u : ''; }

  // A Docker Hub repository's own page. "nginx" (no slash) is a single-segment
  // official image, which Docker Hub serves at /_/name rather than /r/ns/name.
  function caHubUrl(name) {
    var n = String(name || '');
    var slash = n.indexOf('/');
    if (slash === -1) return 'https://hub.docker.com/_/' + encodeURIComponent(n);
    return 'https://hub.docker.com/r/' + n.split('/').map(encodeURIComponent).join('/');
  }

  // A stack/service name derived from an image reference, for the two sources
  // that carry nothing else to name it by. The last path segment is what
  // identifies the app — linuxserver/jellyfin -> jellyfin, and a registry host
  // or tag is neither — lowercased and stripped to what staxx_valid_name()
  // (include/Stacks.php) actually accepts: starts with a letter or digit, then
  // letters, digits, '.', '_' or '-', at most 63 characters.
  function caImageName(image) {
    var s = String(image || '');
    var slash = s.lastIndexOf('/');
    var tail = slash >= 0 ? s.slice(slash + 1) : s;
    var colon = tail.lastIndexOf(':');
    if (colon > 0) tail = tail.slice(0, colon);
    tail = tail.toLowerCase().replace(/[^a-z0-9._-]/g, '').replace(/^[^a-z0-9]+/, '');
    return tail.slice(0, 63) || 'app';
  }

  // Pull counts run into the billions; a card has room for "151M pulls", not
  // the sentence CA's own tile spells the number out as.
  // A decimal place while the leading figure is still a single digit, and
  // none after that: 1577 reads as "1.6k" rather than "2k", which looks like
  // a different number rather than a rounded one, while 405321880 reads as
  // "405M" rather than a false precision nobody asked for.
  function caCompact(n) {
    n = Number(n) || 0;
    var units = [[1e9, 'B'], [1e6, 'M'], [1e3, 'k']];
    for (var i = 0; i < units.length; i++) {
      if (n >= units[i][0]) {
        var v = n / units[i][0];
        return (v < 10 ? Math.round(v * 10) / 10 : Math.round(v)) + units[i][1];
      }
    }
    return String(n);
  }

  // At most one badge. Deprecated wins outright; otherwise "official" is the
  // same test Unraid itself derives it by — a repository with no namespace,
  // or explicitly under library/, is a Docker Hub official image — so it
  // costs no extra index field.
  function caFlagHtml(app) {
    if (app.dep) return '<span class="staxx-ca-flag staxx-ca-flag--dep">deprecated</span>';
    var r = app.r || '';
    if (r.indexOf('/') === -1 || r.indexOf('library/') === 0) {
      return '<span class="staxx-ca-flag staxx-ca-flag--off">official</span>';
    }
    return '';
  }

  function caCardHtml(app) {
    var iconUrl = caUrl(app.ic);
    var icon = iconUrl
      ? '<img class="staxx-ca-cardicon" src="' + esc(iconUrl) + '" alt="" loading="lazy" referrerpolicy="no-referrer">'
      : '<span class="staxx-ca-cardicon staxx-ca-cardicon--empty" aria-hidden="true"></span>';

    // One metadata line under the name, beside the thumbnail: who publishes it
    // and how many have pulled the image. The category used to sit here too and
    // no longer does — this column is narrow, since the Add button and the star
    // count are pinned above its right-hand end, and a third fact only pushed
    // the other two out of view.
    var byParts = [];
    if (app.a) byParts.push(esc(app.a));
    if (app.d) byParts.push(caCompact(app.d) + ' pulls');
    var by = byParts.length
      ? '<span class="staxx-ca-cardby">' + byParts.join(' · ') + '</span>'
      : '';

    // Docker Hub stars — the nearest thing the catalogue has to a rating, since
    // Community Applications itself has had no likes or votes for years. Absent
    // on about half the entries, and absent there means nobody counted rather
    // than nobody liked it, so a card with no figure shows no line at all.
    var stars = app.st
      ? '<span class="staxx-ca-cardstars" title="' + app.st + ' Docker Hub stars">★ ' + caCompact(app.st) + '</span>'
      : '';

    // Add, stars and the flag share the top-right corner, so they are one
    // absolutely-positioned column rather than three things each guessing an
    // offset that the absence of either of the others would leave as a gap.
    var corner = '<div class="staxx-ca-cardtop">' +
                   '<button type="button" class="staxx-ca-cardadd" data-add="' + esc(app.i) + '">Add</button>' +
                   stars + caFlagHtml(app) +
                 '</div>';

    // Spotlight rows only — CA's own reason for the pick, and who gave it.
    // That is the whole point of a spotlight, so it earns a line of its own
    // rather than being folded into the description underneath.
    var rec = app.why
      ? '<span class="staxx-ca-cardrec">' + esc(app.why) + (app.who ? ' — ' + esc(app.who) : '') + '</span>'
      : '';

    // Thumbnail and identity share the top row; everything that needs the
    // card's full width — the image reference, the reason, the description —
    // runs underneath it.
    return '<div class="staxx-ca-card" data-i="' + esc(app.i) + '" role="button" tabindex="0">' +
             corner +
             '<div class="staxx-ca-cardhead">' +
               icon +
               '<div class="staxx-ca-cardid">' +
                 '<span class="staxx-ca-cardname">' + esc(app.n) + '</span>' +
                 by +
               '</div>' +
             '</div>' +
             '<span class="staxx-ca-cardrepo">' + esc(app.r) + '</span>' +
             rec +
             '<span class="staxx-ca-carddesc">' + caPlainText(app.ov) + '</span>' +
           '</div>';
  }

  // A Docker Hub hit carries no icon and barely any metadata next to a CA
  // record, so it renders as a compact row rather than a card — see PLAN_23 B3.
  // data-src tells the click/keydown handlers below which skeleton wording to
  // use, without having to sniff back up the DOM for which group a row is in.
  function caHubRowHtml(hit) {
    var name = String(hit.name || '');
    // Emitted even when empty: it is the only child that grows, so without it
    // a description-less hit leaves its stars and its Docker Hub link stranded
    // against the name instead of out at the right edge with every other row's.
    var descText = hit.desc ? String(hit.desc).trim() : '';
    var desc = '<span class="staxx-ca-rowdesc">' + esc(descText) + '</span>';

    var metaParts = [];
    if (hit.official) metaParts.push('official');
    if (hit.stars !== undefined && hit.stars !== null && hit.stars !== '') {
      metaParts.push(caCompact(hit.stars) + ' stars');
    }
    if (hit.pulls) metaParts.push(caCompact(hit.pulls) + ' pulls');
    var meta = metaParts.length
      ? '<span class="staxx-ca-rowmeta">' + metaParts.join(' · ') + '</span>'
      : '';

    var url = caHubUrl(name);
    var link = url
      ? '<a class="staxx-ca-rowlink" href="' + esc(url) +
        '" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer">Docker Hub</a>'
      : '';

    return '<div class="staxx-ca-row" data-img="' + esc(name) + '" data-src="hub" role="button" tabindex="0">' +
             '<span class="staxx-ca-rowname">' + esc(name) + '</span>' + desc + meta + link +
           '</div>';
  }

  // A local image carries nothing beyond its own reference — no description,
  // no stars, nowhere to link out to.
  function caImgRowHtml(ref) {
    return '<div class="staxx-ca-row" data-img="' + esc(ref) + '" data-src="local" role="button" tabindex="0">' +
             '<span class="staxx-ca-rowname">' + esc(ref) + '</span>' +
           '</div>';
  }

  // Assembles all three groups from whatever is currently known — the three
  // sources land at different times, so this is called again by each one
  // independently rather than only after the Community Applications reply.
  // A heading is only ever written for a group that has results; the "nothing
  // matches" state now depends on all three being empty, not just the first.
  // resetScroll is true only for a genuinely new search settling (the
  // ca-search reply, a category change) — a Docker Hub or local-image update
  // filling in underneath must not yank the list back to the top on the user.
  function caRenderAll(resetScroll) {
    var blocks = [];

    if (caApps.length) {
      blocks.push(
        '<h4 class="staxx-ca-group">Community Applications ' +
        '<span class="staxx-ca-count">' + caApps.length + '</span></h4>' +
        '<div class="staxx-ca-cards">' + caApps.map(caCardHtml).join('') + '</div>');
    }

    if (caHubHits.length) {
      blocks.push(
        '<h4 class="staxx-ca-group">Docker Hub ' +
        '<span class="staxx-ca-count">' + caHubHits.length + '</span></h4>' +
        '<div class="staxx-ca-rows">' + caHubHits.map(caHubRowHtml).join('') + '</div>');
    }

    if (caLocalHits.length) {
      blocks.push(
        '<h4 class="staxx-ca-group">Images on this server ' +
        '<span class="staxx-ca-count">' + caLocalHits.length + '</span></h4>' +
        '<div class="staxx-ca-rows">' + caLocalHits.map(caImgRowHtml).join('') + '</div>');
    }

    caList.innerHTML = blocks.length
      ? blocks.join('')
      : '<p class="staxx-form-empty">Nothing matches that. Try a shorter search.</p>';

    if (resetScroll) caList.scrollTop = 0;
    caFooterMsg();
  }

  // The homepage — three curated rows from the last ca-home reply. Kept
  // separate from caRenderAll() because the two views never share a reply:
  // this one re-renders on switching back from Search, with no round trip.
  // Nothing to render yet (caHomeData still null, the fetch still in flight)
  // is left alone rather than shown as "empty" — caHomeFetch() owns the
  // footer message until its first reply lands.
  function caRenderHome() {
    var data = caHomeData;
    if (!data) return;

    var sections = [
      ['spot',  'Spotlight Apps',     data.spot],
      ['new',   'Recently Added',     data.new],
      ['trend', 'Top Trending Apps',  data.trend]
    ];

    var blocks = [];
    sections.forEach(function (s) {
      var key = s[0], label = s[1], rows = s[2] || [];
      if (!rows.length) return;
      // No count beside a home heading, unlike the search view's — there the
      // number says how much a search matched, which is the answer; here it
      // would only ever say how long a list nobody asked for the length of is.
      //
      // Heading, deck and pager stay flat siblings — NEVER wrap a section in
      // a container element. .staxx-ca-group:first-child in the
      // stylesheet kills the top margin on the first heading, and that only
      // works while the heading is a direct child of the list.
      //
      // Every card for the section goes into one grid; the deck just clips
      // it to two rows and the track slides it. How many cards make two rows
      // depends on how many columns the current window width gives the CSS
      // grid, which is decided entirely by media queries — nothing here
      // duplicates those breakpoints. The pager is always emitted because
      // whether it is needed depends on the row count, which is not known
      // until the layout is measured; caDeckFitAll() below hides it again
      // when a section turns out to fit on one page.
      blocks.push(
        '<h4 class="staxx-ca-group">' + label + '</h4>' +
        '<div class="staxx-ca-deck" data-deck="' + key + '">' +
          '<div class="staxx-ca-track">' +
            '<div class="staxx-ca-cards staxx-ca-cards--home">' + rows.map(caCardHtml).join('') + '</div>' +
          '</div>' +
        '</div>' +
        '<div class="staxx-ca-pager">' +
          '<button type="button" class="staxx-ca-step" data-page="' + key + '" data-dir="-1" ' +
            'aria-label="Previous two rows" title="Previous">&#9650;</button>' +
          '<button type="button" class="staxx-ca-step" data-page="' + key + '" data-dir="1" ' +
            'aria-label="Next two rows" title="Next">&#9660;</button>' +
        '</div>');
    });

    caList.innerHTML = blocks.length
      ? blocks.join('')
      : '<p class="staxx-form-empty">Nothing to show here yet. Try Search instead.</p>';

    // A deck's own scrollTop is never a legitimate state — the track's
    // transform is what moves the content here. Attached once per fresh set
    // of deck elements (this function only runs for a genuine re-render, so
    // there is no risk of piling up duplicate listeners on the same element).
    // Without this, anything that scrolls a clipped descendant into view —
    // focusing a tabbed-to card, browser find-in-page, assistive tech —
    // leaves the deck offset with nothing to put it back afterwards.
    var decks = caList.querySelectorAll('.staxx-ca-deck');
    for (var di = 0; di < decks.length; di++) {
      decks[di].addEventListener('scroll', function () { this.scrollTop = 0; this.scrollLeft = 0; });
    }

    // Run synchronously, in the same tick as the innerHTML write above — a
    // deferred fit would let the browser paint all of a section's cards
    // before the clip applies, so every section would flash full height and
    // then visibly collapse to two rows.
    caDeckFitAll(false);

    // Still correct here: this function now only runs for a genuine first
    // render — opening the dialog, or switching back from Search — paging
    // between pages never calls it, so jumping to the top of the list is
    // still the right thing to do.
    caList.scrollTop = 0;
    caFooterMsg();
  }

  // Distinct row start positions inside a home section's grid, in document
  // order. Cards on the same CSS grid row share an offsetTop, so walking the
  // children and keeping every value that differs from the one before it
  // gives exactly one entry per row — however many columns the current media
  // query has chosen, without this file ever needing to know that number.
  //
  // offsetTop is measured from the element's offsetParent, which for a card
  // here is the <dialog> itself — the nearest positioned ancestor, since the
  // deck, the track and the grid are all statically positioned. That is a
  // position on the page, not a position within the grid, so the first value
  // is subtracted from every value before returning: the result always
  // starts at 0, regardless of how much sits above the grid or what the
  // offsetParent turns out to be. Deleting this subtraction looks harmless —
  // the numbers still look like offsets — but it is what keeps the sums
  // below correct if the layout above the grid ever changes.
  function caDeckRows(grid) {
    var raw = [];
    var kids = grid.children;
    for (var i = 0; i < kids.length; i++) {
      var top = kids[i].offsetTop;
      if (!raw.length || top !== raw[raw.length - 1]) raw.push(top);
    }
    if (!raw.length) return [];
    var base = raw[0];
    return raw.map(function (t) { return t - base; });
  }

  // The row/page arithmetic shared between caDeckFit (which also writes the
  // geometry) and caPageStep (which only needs to know how many pages exist)
  // — kept in one place so the two never drift into subtly different counts.
  function caDeckPages(grid) {
    var tops = caDeckRows(grid);
    return { tops: tops, pages: Math.max(1, Math.ceil(tops.length / CA_ROWS)) };
  }

  // Positions one home section at its current page: clips the deck to the
  // height of two rows and slides the track up so that page's first row sits
  // at the top. The height is measured rather than calculated because a
  // card's name clamps at two lines and its description at three, so rows
  // here are not all the same height — reading where the row after this page
  // actually begins is the only way two rows land exactly on the boundary
  // instead of half-clipping the third.
  function caDeckFit(deck, animate) {
    var key = deck.dataset.deck;
    var track = deck.firstElementChild;
    var grid = track.firstElementChild;
    var pager = deck.nextElementSibling;

    var info = caDeckPages(grid);
    var tops = info.tops, pages = info.pages;
    if (caPage[key] >= pages) caPage[key] = pages - 1;   // a resize can leave the index past the end

    if (pager) pager.hidden = pages < 2;   // only worth a pager once there is more than one page

    var first = caPage[key] * CA_ROWS;
    var offset = tops[first] || 0;
    var nextTop = tops[first + CA_ROWS];
    var gap = parseFloat(getComputedStyle(grid).rowGap) || 0;   // read live — never hard-code the stylesheet's gap
    var bottom = (nextTop === undefined) ? grid.offsetHeight : (nextTop - gap);
    var height = bottom - offset;

    // Cards outside the visible pair of rows stay in the DOM — the whole
    // point of this design is that every card is rendered so the layout can
    // be measured — but must leave the tab order. Left alone, tabbing
    // through the dialog would walk every card and Add button a section
    // has, not just the two rows on screen, and focusing a clipped card
    // makes the browser scroll it into view, which drags the deck's own
    // scrollTop away from zero and corrupts the very clip this function is
    // setting. row increments each time offsetTop changes, exactly as it
    // does inside caDeckRows above, so a card counts as visible when its row
    // falls inside [first, first + CA_ROWS).
    var row = -1, lastTop = null;
    var kids = grid.children;
    for (var i = 0; i < kids.length; i++) {
      var card = kids[i];
      if (lastTop === null || card.offsetTop !== lastTop) { row++; lastTop = card.offsetTop; }
      var visible = row >= first && row < first + CA_ROWS;
      card.setAttribute('tabindex', visible ? '0' : '-1');
      var addBtn = card.querySelector('[data-add]');
      if (addBtn) addBtn.setAttribute('tabindex', visible ? '0' : '-1');
    }

    // No transition wanted for a first fit or a resize snap, so the geometry
    // is written with the "still" classes on and they come off afterwards.
    if (!animate) {
      deck.classList.add('staxx-ca-deck--still');
      track.classList.add('staxx-ca-track--still');
    }
    deck.style.height = height + 'px';
    track.style.transform = 'translateY(-' + offset + 'px)';
    if (!animate) {
      // The forced reflow belongs AFTER the writes, not before them. A
      // transition is decided by comparing the last style the browser
      // resolved against the next one, so what has to be true is that it
      // resolves the NEW height and transform while transitions are still
      // switched off. Reading offsetHeight here makes it do exactly that,
      // after which removing the classes changes nothing and starts nothing.
      // Reading it before the writes instead flushes the old values, leaves
      // the new ones and the class removal in the same tick, and the deck
      // animates on every drag-resize frame — which is the bug this shape
      // exists to avoid.
      void deck.offsetHeight;
      deck.classList.remove('staxx-ca-deck--still');
      track.classList.remove('staxx-ca-track--still');
    }
  }

  // Runs caDeckFit over every home section currently in the DOM — called
  // after a fresh render and after a debounced window resize.
  function caDeckFitAll(animate) {
    var decks = caList.querySelectorAll('.staxx-ca-deck');
    for (var i = 0; i < decks.length; i++) caDeckFit(decks[i], animate);
  }

  // Rebuilds the footer line from whatever is currently known. Kept separate
  // from caRenderAll's DOM write because it is also the thing a Docker Hub or
  // local-image update calls on its own, without re-deriving the Community
  // Applications wording every time.
  function caFooterMsg() {
    // The homepage reads caHomeData, not caApps — caApps is last search's
    // results, which is not what is on screen while this view is up, and
    // describing it here would be describing a search nobody ran.
    if (caView === 'home') {
      var d = caHomeData;
      var total = d ? (d.spot.length + d.new.length + d.trend.length) : 0;
      caMsg.textContent = total
        ? 'Browsing a few curated picks. Type to search the whole catalogue instead.'
        : 'Nothing to show here yet. Type to search the whole catalogue instead.';
      return;
    }

    var n = caApps.length;
    var browsing = caBox.value.trim() === '';
    var inCat = caCatSel.value;

    // Worded from what the user actually did. With an empty box this is a
    // browse through the whole catalogue, not a search that matched things,
    // and calling 60 A-Z entries "matches" would read as a result nobody
    // asked for. res.count (the whole 4,000-odd) only appears in the full-
    // browse case; elsewhere it would read as "4033 apps found" after a
    // search that matched three.
    var caPart = '';
    if (n > 0) {
      if (n < 60) {
        caPart = n + (n === 1 ? ' app' : ' apps') + ' found.';
      } else if (!browsing) {
        caPart = 'Showing the first ' + n + ' matches — narrow the search to see fewer.';
      } else if (inCat) {
        caPart = 'Showing the first ' + n + ' in ' + inCat + ', A to Z — search to narrow it.';
      } else {
        caPart = 'Showing ' + n + ' of ' + (caCatCount || 'the') + ' apps, A to Z — search or pick a category.';
      }
    }

    var hubPart = '';
    if (caHubHits.length) {
      var h = caHubHits.length;
      hubPart = h + (h >= 25 ? '+' : '') + (h === 1 ? ' image' : ' images') + ' from Docker Hub.';
    }

    // caLocalTotal is the filtered count before the 15-row cap, so a fuller
    // match still says how many there really were rather than just "15".
    var localPart = '';
    if (caLocalTotal) {
      localPart = (caLocalTotal > 15 ? 'The first 15 of ' + caLocalTotal : caLocalTotal) +
        (caLocalTotal === 1 ? ' image' : ' images') + ' already on this server.';
      localPart = localPart.charAt(0).toUpperCase() + localPart.slice(1);
    }

    var msg = [caPart, hubPart, localPart].filter(Boolean).join(' ');
    caMsg.textContent = msg || 'Nothing matches that. Try a shorter search.';
  }

  function caRender(apps) {
    caApps = apps || [];
    caRenderAll(true);   // a settled ca-search reply is a genuinely new search
  }

  function caSearch() {
    caSearched = true;   // so the Search tab knows not to run another one just to avoid a blank list
    call('ca-search', { q: caBox.value.trim(), cat: caCatSel.value }, 20000).then(function (res) {
      if (!caModal.open) return;   // the dialog closed while this was in flight

      // Typing and then clearing the box quickly leaves this reply arriving
      // after the homepage is back on screen. Keep the results for when the
      // user returns to Search, but do not draw over the view they are on.
      if (caView !== 'search') {
        if (res.ok && res.state === 'ready') caApps = res.apps || [];
        return;
      }

      if (!res.ok) { caMsg.textContent = res.error; return; }

      if (res.state === 'building') {
        caMsg.textContent = res.message || 'Fetching the applications catalogue. This happens the first time only…';
        caList.innerHTML = '';
        caStopPoll();
        caPoll = setTimeout(caSearch, 3000);
        return;
      }

      // The download failed, and polling for it to succeed on its own would
      // be waiting for something that is not going to happen. Say why and
      // stop; searching again is what retries it, once the server has stopped
      // reporting the failure as recent.
      if (res.state === 'failed') {
        caStopPoll();
        caList.innerHTML = '';
        caMsg.textContent = res.message ||
          'The app catalogue could not be downloaded. Check this server can reach the internet, then search again.';
        return;
      }

      // Kept for caFooterMsg()'s full-browse wording — res.count is the whole
      // 4,000-odd catalogue, only ever worth saying when nothing narrowed it.
      caCatCount = res.count || null;
      caFillCats(res.categories);
      caRender(res.apps);   // renders all three groups and rebuilds the footer message
    });
  }

  // The homepage's fetch — same building/failed handling as caSearch(),
  // since both are reading the one catalogue cache underneath and hit the
  // same "still downloading" and "download failed" states.
  function caHomeFetch() {
    call('ca-home', {}, 20000).then(function (res) {
      if (!caModal.open) return;   // the dialog closed while this was in flight

      // The user may have switched to Search while this was travelling. A
      // stale reply landing then must not stomp the search view or its
      // message — except a ready one, which is still worth keeping so
      // switching back to Home needs no second round trip.
      if (caView !== 'home') {
        if (res.ok && res.state === 'ready') caHomeData = res;
        return;
      }

      if (!res.ok) { caMsg.textContent = res.error; return; }

      if (res.state === 'building') {
        caMsg.textContent = res.message || 'Fetching the applications catalogue. This happens the first time only…';
        caList.innerHTML = '';
        caStopPoll();
        caPoll = setTimeout(caHomeFetch, 3000);
        return;
      }

      if (res.state === 'failed') {
        caStopPoll();
        caList.innerHTML = '';
        caMsg.textContent = res.message ||
          'The app catalogue could not be downloaded. Check this server can reach the internet, then search again.';
        return;
      }

      caCatCount = res.count || null;
      caFillCats(res.categories);
      caHomeData = res;
      caRenderHome();
    });
  }

  // Flips between the homepage and the search results — the only two views
  // this one panel ever shows. Re-renders from whatever is already known;
  // fetching (or not) is left to whoever calls this, since the two tab
  // buttons and the search box each have their own opinion on when a fetch
  // is actually needed.
  function caShowView(view) {
    var changed = caView !== view;
    caView = view;
    var onHome = view === 'home';
    caTabHome.classList.toggle('is-on', onHome);
    caTabHome.setAttribute('aria-pressed', onHome ? 'true' : 'false');
    caTabSearch.classList.toggle('is-on', !onHome);
    caTabSearch.setAttribute('aria-pressed', onHome ? 'false' : 'true');

    // Only a genuine switch redraws. Every settled keystroke asks for the
    // search view, and re-rendering there would tear down and rebuild sixty
    // cards showing the PREVIOUS search's results, a flicker for a list that
    // is about to be replaced by the reply anyway.
    if (!changed) return;
    if (onHome) { caRenderHome(); return; }

    // Switching to Search before any search has run: an empty caApps would
    // draw "nothing matches", which is a claim about a search nobody made.
    if (!caSearched) { caList.innerHTML = ''; caMsg.textContent = 'Searching the catalogue…'; return; }
    caRenderAll(true);
  }

  function caOpen() {
    flushPending();
    caBox.value = '';
    // Yesterday's results are not this search's, and leaving them up while the
    // new reply is in flight shows a list that does not answer the box above it.
    caList.innerHTML = '';
    caApps = [];
    caHubHits = [];
    caLocalImages = null;
    caLocalHits = [];
    caLocalTotal = 0;
    caCatCount = null;
    caHomeData = null;
    caPage = { spot: 0, new: 0, trend: 0 };
    caSearched = false;
    if (caHubTimer) { clearTimeout(caHubTimer); caHubTimer = null; }
    caHubStamp++;   // invalidates any Docker Hub reply still travelling from a previous open
    caMsg.textContent = 'Reading the catalogue…';
    caModal.showModal();
    // showModal() focuses the first focusable thing in the dialog, which is now
    // the Home tab — so it opened wearing a focus ring that read as a selected
    // border. The box is where anyone opening this wants to be anyway.
    caBox.focus();
    caShowView('home');   // always opens on the homepage; caHomeData is still null so this renders nothing yet
    caHomeFetch();

    // Every repo:tag on this server, fetched once per open — caFilterLocalImages()
    // does the per-keystroke work from here on, client-side, since a docker
    // images call for every keypress would be absurd.
    call('images', {}, 15000).then(function (res) {
      if (!caModal.open) return;   // the dialog closed while this was in flight
      caLocalImages = (res.ok && res.images) ? res.images : [];
      caFilterLocalImages();
      // Skipped while the homepage is up — this reply landing a moment later
      // must not wipe it out from under the user.
      if (caView !== 'home') caRenderAll(false);
    });
  }

  // Filters the one-time images fetch by plain substring, case-insensitive —
  // there is no network call here to debounce. caLocalTotal is the count
  // before the 15-row cap, so caFooterMsg() can say how many really matched.
  // The same word-start rule the catalogue search uses: 'plex' must not match
  // mintplexlabs/anythingllm. Written by hand rather than as a lookbehind,
  // which older browsers do not all support. Both arguments are lowercase.
  function caWordStart(hay, needle) {
    for (var i = hay.indexOf(needle); i >= 0; i = hay.indexOf(needle, i + 1)) {
      if (i === 0 || !/[a-z0-9]/.test(hay.charAt(i - 1))) return true;
    }
    return false;
  }

  function caFilterLocalImages() {
    var q = caBox.value.trim().toLowerCase();
    var list = caLocalImages || [];
    var hits = q ? list.filter(function (ref) { return caWordStart(ref.toLowerCase(), q); }) : list;
    caLocalTotal = hits.length;
    caLocalHits = hits.slice(0, 15);
  }

  // Docker Hub's own search, on its own 600ms debounce (separate from
  // caTimer's 250ms) and only for a query of 3+ characters — the catalogue is
  // local and instant, Docker Hub is a network round trip on every keystroke.
  // Stamped so a slow reply for a shorter query cannot land under a box that
  // has since moved on.
  function caHubSearch(q) {
    var stamp = ++caHubStamp;
    call('hub-search', { q: q }, 15000).then(function (res) {
      if (!caModal.open || stamp !== caHubStamp) return;
      caHubHits = (res.ok && res.hits) ? res.hits.slice(0, 25) : [];
      caRenderAll(false);   // a Docker Hub reply filling in must not move the scroll position
    });
  }

  // The shared tail of the import path: convert an already-fetched record and
  // open the editor on it. caAdd() reaches this after its own fetch; the
  // details window's own Add button reaches it with the record caDetails()
  // already fetched, so pressing Add in there never asks the server twice.
  function caImport(ordinal, app) {
    if (!window.StaxxCA) {
      caMsg.textContent = 'The app converter has not loaded. Reload the page and try again.';
      return;
    }

    var appName = 'This app';
    for (var j = 0; j < caApps.length; j++) {
      if (String(caApps[j].i) === String(ordinal)) { appName = caApps[j].n; break; }
    }

    // A single odd template must not take the page down — convert() runs
    // against whatever Community Applications published, which answers to
    // no schema this plugin controls.
    var result;
    try {
      result = window.StaxxCA.convert(app, { appdataRoot: APPDATA });
    } catch (e) {
      caMsg.textContent = appName + ' could not be converted: ' + (e && e.message ? e.message : e);
      return;
    }

    // Closing the search dialog also force-closes the details window, via
    // caModal's own 'close' handler below — so this needs no opinion on
    // whether that dialog happens to be open too.
    caModal.close();
    openEditor(result.name, result.yaml, true);

    // Two different lists, two different headlines. warnings are settings
    // that had nothing to convert TO — a real gap worth calling a failure.
    // notes are values the template never set at all (SWAG's blank config
    // path, most often) that a placeholder was filled in for — not a
    // failure, so it must not be announced as one. Kept visually apart in
    // the body too, so which list said what is never in doubt.
    var warnings = result.warnings || [];
    var notes    = result.notes || [];
    if (warnings.length && notes.length) {
      showError('This app converted, but some settings had no compose equivalent, and some ' +
                 'values were not in the template and have been filled in. Check these before saving:\n\n' +
                 'No compose equivalent:\n' + warnings.join('\n') +
                 '\n\nFilled in:\n' + notes.join('\n'));
    } else if (warnings.length) {
      showError('This app converted, but some of its settings had no compose equivalent. ' +
                 'Check these before saving:\n\n' + warnings.join('\n'));
    } else if (notes.length) {
      showError('This app converted. Some values were not in the template and have been filled in. ' +
                 'Check these before saving:\n\n' + notes.join('\n'));
    }
  }

  function caAdd(ordinal) {
    call('ca-app', { i: ordinal }, 20000).then(function (res) {
      if (!res.ok) { caMsg.textContent = res.error; return; }
      caImport(ordinal, res.app);
    });
  }

  // What a Docker Hub or local-image row adds — there is no template and no
  // converter behind either, so this is the same six-line shape NEW_STACK
  // uses for a brand new stack, with the image substituted in. The leading
  // comment is where the difference from a Community Applications app has to
  // show, because nothing else in the dialog says it once the editor is open.
  function caSkeleton(image, source) {
    var note = source === 'hub'
      ? '  # Added from a Docker Hub search — just the image, nothing else.'
      : '  # Added from an image already on this server — just the image, nothing else.';
    return [
      'services:',
      '',
      note,
      '  # Ports, paths and variables are not set; add whatever this container needs.',
      '  ' + caImageName(image) + ':',
      '    image: ' + image,
      '    restart: unless-stopped',
      ''
    ].join('\n');
  }

  // caAdd()/caImport() convert a Community Applications record; this is the
  // equivalent for the other two groups, which carry nothing of their own to
  // convert — so this asks the server what the image and its own
  // documentation say about itself, and builds a compose file from that.
  // Any failure at any stage — no reply, a refused request, a bug in the
  // builder — falls silently back to caSkeleton(), which was always an
  // acceptable answer and still is; the dialog must never look like it is
  // doing nothing, and it must never be left stuck saying so either.
  function caAddImage(image, source) {
    if (caFactsBusy) return;   // a lookup is already running; a second click must not open a second editor
    if (!window.StaxxImage) {
      caModal.close();
      openEditor(caImageName(image), caSkeleton(image, source), true);
      return;
    }

    caFactsBusy = true;
    var stamp = ++caFactsStamp;
    caMsg.textContent = 'Reading ' + image + "'s own documentation…";

    // True only while this is still the lookup the user is waiting on — a
    // reply for a dialog since closed, or superseded by a later stamp, is
    // dropped rather than acted on.
    function stillLive() {
      return stamp === caFactsStamp && caModal.open;
    }

    // Clears the busy flag and, while the dialog is still open, restores the
    // ordinary footer text — the one place both the fallback and the success
    // path have to leave tidy, so it is written once rather than in each.
    function settle() {
      caFactsBusy = false;
      if (caModal.open) caFooterMsg();
    }

    function fallback() {
      settle();
      caModal.close();
      openEditor(caImageName(image), caSkeleton(image, source), true);
    }

    function finish(result) {
      settle();
      caModal.close();
      openEditor(result.name, result.yaml, true);

      // caImport()'s own three wordings, in shape: warnings are settings a
      // correction had nothing to put right, notes are values filled in on
      // the user's behalf. A clean import says nothing at all.
      var warnings = result.warnings || [];
      var notes    = result.notes || [];

      // The two routes produce very different files, so they cannot share one
      // opening sentence: an example somebody wrote and published is not the
      // same claim as a guess assembled from the image's own declared ports.
      var lead = result.route === 'readme'
        ? 'This image came with an example file in its own documentation, and that is what ' +
          'the editor is showing.'
        : 'This image publishes no example file, so this was built from what the image itself ' +
          'declares.';

      if (warnings.length && notes.length) {
        showError(lead + ' Some of it needs your attention, and some values were filled in for ' +
                   'you. Check these before saving:\n\n' +
                   'Needs your attention:\n' + warnings.join('\n') +
                   '\n\nFilled in for you:\n' + notes.join('\n'));
      } else if (warnings.length) {
        showError(lead + ' Some of it needs your attention. Check this before saving:\n\n' +
                   warnings.join('\n'));
      } else if (notes.length) {
        showError(lead + ' Some values were filled in for you. Check these before saving:\n\n' +
                   notes.join('\n'));
      }
    }

    call('image-facts', { image: image, source: source }, 20000).then(function (reply) {
      if (!stillLive()) { settle(); return; }
      if (!reply || !reply.ok) { fallback(); return; }

      var facts = reply.facts || {};
      var opts = { appdata: facts.appdata || '', timezone: facts.timezone || '' };
      var result;
      try {
        result = window.StaxxImage.build(image, source, facts, opts);
      } catch (e) {
        fallback();
        return;
      }

      if (!result.wantConfig) { finish(result); return; }

      // The registry fallback is four chained requests, so it is only ever
      // asked for once — whatever this second reply says, build() runs
      // exactly one more time and that answer is final.
      call('image-facts', { image: image, source: source, config: '1' }, 40000).then(function (reply2) {
        if (!stillLive()) { settle(); return; }

        var facts2 = (reply2 && reply2.ok && reply2.facts) || {};
        var merged = {};
        Object.keys(facts).forEach(function (k) { merged[k] = facts[k]; });
        Object.keys(facts2).forEach(function (k) { merged[k] = facts2[k]; });
        var opts2 = { appdata: merged.appdata || '', timezone: merged.timezone || '' };

        var result2;
        try {
          result2 = window.StaxxImage.build(image, source, merged, opts2);
        } catch (e) {
          fallback();
          return;
        }
        finish(result2);
      });
    });
  }

  // A row in the details window's table — omitted outright when its value is
  // absent, rather than printed blank or as "undefined". 0 is a real value
  // (zero Docker Hub stars, say) and must still show, so this checks for
  // absence rather than falsiness.
  function caTableRow(label, value) {
    if (value === '' || value === null || value === undefined) return '';
    return '<div class="staxx-ca-app-tr"><span class="staxx-ca-app-tk">' + esc(label) +
           '</span><span class="staxx-ca-app-tv">' + esc(value) + '</span></div>';
  }

  function caLinkRow(label, url) {
    var u = caUrl(url);
    if (!u) return '';
    return '<a class="staxx-ca-app-link" href="' + esc(u) +
           '" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer">' + esc(label) + '</a>';
  }

  // Markdown is not rendered here, deliberately. CA runs these overviews
  // through a markdown renderer; we do not carry one, and will not add one
  // just to turn a bare "(https://…)" back into a link — that is exactly how
  // a client-side library creeps into a page that has none.
  //
  // Bracket tags are a different thing: a small, fixed list of about twenty
  // BBCode-ish tokens ([b], [br], [span style='...'], and so on) that CA's own
  // site renders as HTML. Because the list is closed and known, converting
  // exactly those tags is not "adding a markdown renderer" — it is matching
  // twenty literal strings.
  //
  // The alternation below matches each tag's full name, never a prefix — [p]
  // is its own alternative, not "p" followed by anything up to "]". A prefix
  // pattern would also swallow the placeholders several apps write as literal
  // bracketed text, not markup: [PORT], [port:5001], [unraid-ip], and a run of
  // forty DDNS provider names such as [aliyun] and [godaddy]. Only span, color
  // and a ever carry anything before their closing bracket, so only those
  // three are written as open-ended alternatives (and "a" requires a space
  // straight after it, so a literal [aliyun] cannot match it).
  var CA_TAG_RE = /\[(?:br\s*\/?|\/br|\/?(?:b|strong|u|i|code|ul|ol|center|url)|\/?h[1-6]|li|\/li|p|\/p|span[^\]]*|\/span|color=[^\]]*|\/color|a\s[^\]]*|\/a)\]/gi;

  // Walks the tag list once, in two modes: caTextHtml keeps the structure
  // CA's own renderer would show (line breaks, bold, headings); caPlainText
  // flattens the same tags to nothing for the card blurb, which has no room
  // for any of it. Colour is dropped in both — the dialog has its own palette
  // and a hard-coded style='color: #E80000;' is not ours to honour — and
  // links are unwrapped rather than turned into anchors, since only a couple
  // of dozen occurrences exist and Project/Support/Read Me already sit at the
  // top of every details window as real links.
  function caConvertTags(s, plain) {
    return s.replace(CA_TAG_RE, function (m) {
      var t = m.slice(1, -1).toLowerCase();
      if (t === 'b' || t === 'strong') return plain ? '' : '<strong>';
      if (t === '/b' || t === '/strong') return plain ? '' : '</strong>';
      if (t === 'u') return plain ? '' : '<u>';
      if (t === '/u') return plain ? '' : '</u>';
      if (t === 'i') return plain ? '' : '<em>';
      if (t === '/i') return plain ? '' : '</em>';
      if (t === 'code') return plain ? '' : '<code>';
      if (t === '/code') return plain ? '' : '</code>';
      if (/^h[1-6]$/.test(t)) return plain ? ' ' : '<br><strong>';
      if (/^\/h[1-6]$/.test(t)) return plain ? '' : '</strong>';
      if (t === 'li') return plain ? ' ' : '<br>&bull; ';
      if (t === 'p') return plain ? ' ' : '<br>';
      if (t === '/br' || /^br/.test(t)) return plain ? ' ' : '<br>';
      // Everything else recognised — /li, /p, span/color/url/ul/ol/center and
      // their closers, and a — is unwrapped: the inner text stays, the tag
      // itself contributes nothing.
      return '';
    });
  }

  // esc() runs FIRST, then the bracket conversion runs on the already-escaped
  // string. That order is the whole safety argument: esc() neutralises "<"
  // and ">" but leaves square brackets alone, so the only HTML tags that can
  // end up in the output are the ones caConvertTags() builds itself — nothing
  // from the feed can inject one. Nothing here re-emits an attribute value
  // either: esc() does not escape a single quote, and the one source tag that
  // carries one (style='...') is unwrapped rather than reproduced.
  //
  // The 160-character cut the server applies to the card blurb can land
  // mid-tag ("...this updated mod[b"), so a trailing "[" with no closing "]"
  // is dropped once tag conversion is done — left alone it would render as
  // literal, meaningless bracket text.
  // A newline is only a line break when the text has no [br] tags of its own.
  // These overviews live inside an XML template and arrive pretty-printed, so
  // an overview that already breaks its lines with [br] also carries a newline
  // and several spaces of indentation after each one. Honouring both put a
  // blank line between every line of EmbyServer's directory list.
  function caTextHtml(s) {
    var t = esc(s);
    t = /\[br\s*\/?\]/i.test(t)
      ? t.replace(/[ \t]*(?:\r\n|\r|\n)[ \t]*/g, ' ')
      : t.replace(/\r\n|\r|\n/g, '<br>');
    return caConvertTags(t, false).replace(/\[[^\]]*$/, '');
  }

  // Same tag list, flattened to one line for the card blurb rather than kept
  // as structured HTML — a card has no space for headings or bulleted lists.
  // Escaping and the trailing-cut-tag guard are identical to caTextHtml, so
  // it must not be wrapped in esc() again at the call site.
  function caPlainText(s) {
    return caConvertTags(esc(s), true)
      .replace(/\[[^\]]*$/, '')
      .replace(/\s+/g, ' ')
      .trim();
  }

  // FirstSeen and LastUpdate are Unix seconds. Some records carry junk far
  // below any real date — one well-known app's Date is literally
  // "1970-01-01", which is why Date itself is never read at all — so
  // anything under roughly the year 2001 is treated as absent rather than
  // printed as a nonsense day.
  function caDate(ts) {
    ts = Number(ts);
    if (!ts || ts < 1000000000) return '';
    var d = new Date(ts * 1000);
    var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return months[d.getUTCMonth()] + ' ' + d.getUTCDate() + ', ' + d.getUTCFullYear();
  }

  // The description is rendered in full and clamped by CSS; this only decides
  // whether the clamp is actually cutting anything off. A short overview gets
  // no toggle at all, rather than a button that does nothing.
  function caSetupClip(textEl, moreBtn) {
    if (textEl.scrollHeight <= textEl.clientHeight + 2) {
      moreBtn.hidden = true;
      textEl.classList.remove('staxx-ca-app-text--clip');
      return;
    }
    moreBtn.hidden = false;
    moreBtn.textContent = 'Show more';
    moreBtn.onclick = function () {
      // toggle() reports whether the class ended up present, i.e. whether
      // this click just re-clipped the text — so the label always names
      // what the *next* click will do, not what this one just did.
      var clipped = textEl.classList.toggle('staxx-ca-app-text--clip');
      moreBtn.textContent = clipped ? 'Show more' : 'Show less';
    };
  }

  // Fills the details window from the full ca-app record, matching Unraid's
  // own Apps panel minus the parts that are theirs (install, pin, trend
  // charts, changelog). Every section is omitted outright when its data is
  // absent, rather than shown empty.
  function caFillDetails(app) {
    var iconUrl = caUrl(app.Icon);
    caAppIcon.innerHTML = iconUrl
      ? '<img src="' + esc(iconUrl) + '" alt="" loading="lazy" referrerpolicy="no-referrer">'
      : '';
    caAppTitle.textContent = app.Name || '';
    caAppBy.textContent = app.Author || app.Repo || '';

    var sections = [];

    var links = [
      caLinkRow('Project', app.Project),
      caLinkRow('Support', app.Support),
      caLinkRow('Read Me', app.ReadMe),
      caLinkRow('Registry', app.Registry),
      caLinkRow('Donate', app.DonateLink)
    ].filter(Boolean);
    if (links.length) sections.push('<div class="staxx-ca-app-links">' + links.join('') + '</div>');

    var overview = app.OriginalOverview || app.Overview || '';
    if (overview) {
      sections.push(
        '<div class="staxx-ca-app-sec">' +
          '<h4 class="staxx-ca-app-h">Description</h4>' +
          '<div class="staxx-ca-app-text staxx-ca-app-text--clip" data-role="desc">' + caTextHtml(overview) + '</div>' +
          '<button type="button" class="staxx-ca-app-more" data-role="desc-more" hidden>Show more</button>' +
        '</div>');
    }

    var requires = app.Requires || '';
    if (requires) {
      sections.push(
        '<div class="staxx-ca-app-sec">' +
          '<h4 class="staxx-ca-app-h">Additional requirements</h4>' +
          '<div class="staxx-ca-app-text">' + caTextHtml(requires) + '</div>' +
        '</div>');
    }

    if (app.Deprecated) {
      sections.push(
        '<div class="staxx-ca-app-sec">' +
          '<h4 class="staxx-ca-app-h">Attention</h4>' +
          '<p class="staxx-ca-app-note">The catalogue still lists this app, but its maintainer ' +
          'has marked it as no longer kept up.</p>' +
        '</div>');
    }

    // The feed spells this both ways — Screenshot and Screenshots — and either
    // spelling may carry a single URL string or an array of them.
    var shots = [];
    if (Array.isArray(app.Screenshot)) shots = shots.concat(app.Screenshot);
    else if (app.Screenshot) shots.push(app.Screenshot);
    if (Array.isArray(app.Screenshots)) shots = shots.concat(app.Screenshots);
    else if (app.Screenshots) shots.push(app.Screenshots);
    shots = shots.map(caUrl).filter(Boolean);
    if (shots.length) {
      sections.push(
        '<div class="staxx-ca-app-sec">' +
          '<h4 class="staxx-ca-app-h">Screenshots</h4>' +
          '<div class="staxx-ca-app-shots">' +
            shots.map(function (u) {
              return '<a class="staxx-ca-app-shot" href="' + esc(u) +
                     '" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer">' +
                     '<img src="' + esc(u) + '" alt="" loading="lazy" referrerpolicy="no-referrer"></a>';
            }).join('') +
          '</div>' +
        '</div>');
    }

    var cats = Array.isArray(app.CategoryList) && app.CategoryList.length
      ? app.CategoryList.join(', ')
      : (app.Category || '');
    var stars = (app.stars !== undefined && app.stars !== null && app.stars !== '') ? app.stars : '';
    var rows =
      caTableRow('Categories', cats) +
      caTableRow('Added', caDate(app.FirstSeen)) +
      caTableRow('Downloads', app.downloads ? caCompact(app.downloads) : '') +
      caTableRow('Repository', app.Repository) +
      caTableRow('Docker Hub stars', stars) +
      caTableRow('Last update', caDate(app.LastUpdate)) +
      caTableRow('Minimum Unraid version', app.MinVer) +
      caTableRow('Licence', app.Licence || app.License || '');
    if (rows) {
      sections.push(
        '<div class="staxx-ca-app-sec">' +
          '<h4 class="staxx-ca-app-h">Details</h4>' +
          '<div class="staxx-ca-app-table">' + rows + '</div>' +
        '</div>');
    }

    // No profile, bio or icon for a maintainer — that lives in a second CA
    // catalogue file (repositoryList.json) we do not download.
    var maintainer = app.RepoName || app.Repo || '';
    if (maintainer) {
      sections.push(
        '<div class="staxx-ca-app-sec">' +
          '<h4 class="staxx-ca-app-h">Maintainer</h4>' +
          '<p>' + esc(maintainer) + '</p>' +
        '</div>');
    }

    caAppBody.innerHTML = sections.join('');

    var descText = caAppBody.querySelector('[data-role="desc"]');
    var descMore = caAppBody.querySelector('[data-role="desc-more"]');
    if (descText && descMore) caSetupClip(descText, descMore);
  }

  function caDetails(ordinal) {
    caAppOrdinal = ordinal;
    caAppRecord = null;
    caAppBody.innerHTML = '';
    caAppTitle.textContent = 'Loading…';
    caAppBy.textContent = '';
    caAppIcon.innerHTML = '';
    caAppModal.showModal();
    // Explicit, and after showModal(), for the same reason every dialog in
    // this file sets focus by hand: the browser's own "first focusable
    // descendant" choice is not where anyone wants to land.
    caAppAdd.focus({ preventScroll: true });

    call('ca-app', { i: ordinal }, 20000).then(function (res) {
      // Closed, or superseded by a second card clicked before this landed —
      // either way, this reply is not for what is on screen any more.
      if (!caAppModal.open || caAppOrdinal !== ordinal) return;
      if (!res.ok) {
        caAppBody.innerHTML = '<p class="staxx-form-empty">' + esc(res.error) + '</p>';
        return;
      }
      caAppRecord = res.app;
      caFillDetails(res.app);
    });
  }

  // Steps one home section forward or back by one page, wrapping at both
  // ends (dir is +1 or -1) — the single expression below is what makes it
  // wrap in both directions at once, rather than needing a clamp either side.
  // No DOM is inserted or removed here: the whole motion is two animated
  // property changes (height and transform) on elements that already exist,
  // so there is no clean-up to do, nothing to orphan, and no need for a lock
  // against rapid clicks — a click that lands mid-slide just retargets the
  // transition, which is what a carousel should do.
  function caPageStep(key, dir) {
    var deck = caList.querySelector('[data-deck="' + key + '"]');
    if (!deck) return;
    var grid = deck.firstElementChild.firstElementChild;
    var pages = caDeckPages(grid).pages;
    if (pages < 2) return;
    caPage[key] = (caPage[key] + dir + pages) % pages;
    caDeckFit(deck, true);
  }

  caList.addEventListener('click', function (event) {
    var addBtn = event.target.closest('[data-add]');
    if (addBtn) { caAdd(addBtn.dataset.add); return; }

    // The Docker Hub link inside a row opens in its own tab; it must not also
    // add the image the row represents.
    if (event.target.closest('.staxx-ca-rowlink')) return;

    var row = event.target.closest('.staxx-ca-row');
    if (row) { caAddImage(row.dataset.img, row.dataset.src); return; }

    // The homepage's own pager arrows — slide a section by two rows. Only
    // ever present beside a .staxx-ca-deck, never inside a card, so this
    // must run before the card check below.
    var pageBtn = event.target.closest('[data-page]');
    if (pageBtn) { caPageStep(pageBtn.dataset.page, parseInt(pageBtn.dataset.dir, 10)); return; }

    var card = event.target.closest('.staxx-ca-card');
    if (card) caDetails(card.dataset.i);
  });

  // The card is a div with role="button", not an actual button — it now
  // contains one (Add) of its own, and a button inside a button is invalid.
  // A Docker Hub/local-image row is the same shape, minus the Add button.
  // Enter and Space stand in for the click a real button would get for free.
  caList.addEventListener('keydown', function (event) {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    if (event.target.closest('[data-add]')) return;         // the Add button handles its own activation
    if (event.target.closest('.staxx-ca-rowlink')) return;   // likewise the Docker Hub link

    var row = event.target.closest('.staxx-ca-row');
    if (row) {
      if (event.key === ' ') event.preventDefault();   // stop the list scrolling
      caAddImage(row.dataset.img, row.dataset.src);
      return;
    }

    var card = event.target.closest('.staxx-ca-card');
    if (!card) return;
    if (event.key === ' ') event.preventDefault();   // stop the list scrolling
    caDetails(card.dataset.i);
  });

  // Delegated on the list, in the capture phase, because `error` does not
  // bubble — same reasoning as the page-wide icon fallback further down this
  // file, just scoped to this one dialog rather than the whole page.
  caList.addEventListener('error', function (event) {
    var img = event.target;
    if (!img || img.tagName !== 'IMG' || !img.classList.contains('staxx-ca-cardicon')) return;
    var span = document.createElement('span');
    span.className = 'staxx-ca-cardicon staxx-ca-cardicon--empty';
    span.setAttribute('aria-hidden', 'true');
    if (img.parentNode) img.parentNode.replaceChild(span, img);
  }, true);

  /* The same treatment for the details window's own images, and needed more
   * often than it looks: an app's icon and screenshots are wherever its
   * author put them years ago, and plenty of those addresses are now dead —
   * one catalogue entry points every one of its four images at a GitHub
   * repository that no longer answers. Left alone that is a broken-image box
   * where the icon goes and a tall empty gap under the Screenshots heading,
   * both of which read as this page failing rather than as the catalogue
   * being out of date. The icon falls back to its own empty tile; a dead
   * screenshot is dropped, and the heading goes with the last one.
   *
   * Capture phase, because `error` does not bubble. */
  caAppModal.addEventListener('error', function (event) {
    var img = event.target;
    if (!img || img.tagName !== 'IMG') return;

    if (img.parentNode === caAppIcon) { caAppIcon.innerHTML = ''; return; }

    var shot = img.closest ? img.closest('.staxx-ca-app-shot') : null;
    if (!shot) return;
    var strip = shot.parentNode;
    shot.remove();
    if (strip && !strip.querySelector('.staxx-ca-app-shot')) {
      var section = strip.closest('.staxx-ca-app-sec');
      if (section) section.remove();
    }
  }, true);

  caAppAdd.addEventListener('click', function () {
    if (!caAppRecord) return;   // still loading — nothing to add yet
    caImport(caAppOrdinal, caAppRecord);
  });

  caAppClose.addEventListener('click', function () { caAppModal.close(); });

  caAppModal.addEventListener('click', function (event) {
    if (event.target !== caAppModal) return;
    var r = caAppModal.getBoundingClientRect();
    if (event.clientX < r.left || event.clientX > r.right ||
        event.clientY < r.top  || event.clientY > r.bottom) caAppModal.close();
  });

  caAppModal.addEventListener('close', function () {
    // A stale app must never flash up on the next open.
    caAppBody.innerHTML = '';
    caAppRecord = null;
    caAppOrdinal = null;
  });

  caBox.addEventListener('input', function () {
    // Local images are filtered from the one-time fetch — no debounce, there
    // is no network call to spare one for.
    caFilterLocalImages();

    var q = caBox.value.trim();
    if (q.length < 3) {
      // Below the Docker Hub minimum: drop whatever that group was showing
      // and invalidate a request still travelling for a longer query.
      if (caHubTimer) { clearTimeout(caHubTimer); caHubTimer = null; }
      caHubHits = [];
      caHubStamp++;
    } else {
      if (caHubTimer) clearTimeout(caHubTimer);
      caHubTimer = setTimeout(function () { caHubTimer = null; caHubSearch(q); }, 600);
    }

    // Deliberately no redraw here. The list is one innerHTML write, so
    // redrawing on every keystroke would tear down and rebuild all sixty
    // cards — and their sixty <img> elements — while the user is still
    // typing, which flickers. The filtered local images are held in state and
    // appear with the catalogue reply a quarter of a second later, so every
    // group updates together rather than in three separate lurches.

    if (caTimer) clearTimeout(caTimer);
    caTimer = setTimeout(function () {
      caTimer = null;
      // An emptied box goes back to the homepage rather than running an
      // empty-query search — caHomeData is already in hand from caOpen()
      // unless this view was never visited yet this open.
      if (caBox.value.trim() === '') {
        caShowView('home');
        if (!caHomeData) caHomeFetch();
      } else {
        caShowView('search');
        caSearch();
      }
    }, 250);
  });

  caCatSel.addEventListener('change', function () {
    caShowView('search');   // picking a category is itself a search
    caSearch();
  });

  caTabHome.addEventListener('click', function () {
    if (caView === 'home') return;
    caShowView('home');
    if (!caHomeData) caHomeFetch();
  });

  caTabSearch.addEventListener('click', function () {
    if (caView === 'search') return;
    caShowView('search');
    if (!caSearched) caSearch();   // nothing has run yet this open — an empty list would look broken, not blank on purpose
  });

  document.getElementById('staxx-ca-cancel').addEventListener('click', function () {
    caModal.close();
  });

  caModal.addEventListener('click', function (event) {
    if (event.target !== caModal) return;
    var r = caModal.getBoundingClientRect();
    if (event.clientX < r.left || event.clientX > r.right ||
        event.clientY < r.top  || event.clientY > r.bottom) caModal.close();
  });

  caModal.addEventListener('close', function () {
    caStopPoll();
    if (caFitTimer) { clearTimeout(caFitTimer); caFitTimer = null; }   // a pending fit must not fire against a closed dialog
    // Nested-dialog rule, same as the editor's close handler for the picker
    // and timezone dialogs: a dialog left open over a closed parent would be
    // stacked over nothing, still holding focus.
    if (caAppModal.open) caAppModal.close();
  });

  // The column count is a CSS media query on the window width, so a resize
  // can change how many cards make up two rows for every home section at
  // once. Debounced the same way as the search box (caTimer) — a live
  // drag-resize fires this continuously and re-measuring on every tick would
  // be wasted work. No animation on the refit: a deck animating its height
  // through a drag-resize only lags behind the pointer.
  window.addEventListener('resize', function () {
    if (caFitTimer) clearTimeout(caFitTimer);
    caFitTimer = setTimeout(function () {
      caFitTimer = null;
      if (caModal.open && caView === 'home') caDeckFitAll(false);
    }, 150);
  });

  /* ---- Import (PLAN_38 phase 1) ----
   *
   * A sibling of the Community Applications panel just above: one dialog,
   * one fetch on open, rendered from whatever the last reply said. Far
   * simpler than that one though — there is no search, no tabs and nothing
   * to add, since this phase only lists what could be brought into StaXX and
   * previews what it would write. Nothing here calls the server again after
   * the initial fetch; a row's preview is generated once, from data already
   * in hand, the first time that row is opened. */

  var importModal    = document.getElementById('staxx-import-dlg');
  var importList     = document.getElementById('staxx-import-list');
  var importMsg      = document.getElementById('staxx-import-msg');
  var importDest     = document.getElementById('staxx-import-dest');
  var importFolderSel = document.getElementById('staxx-import-folder');
  var importDestPath = document.getElementById('staxx-import-destpath');
  var importDestNote = document.getElementById('staxx-import-destnote');
  var importSummary  = document.getElementById('staxx-import-summary');
  var importGoBtn    = document.getElementById('staxx-import-go');
  var importCloseBtn = document.getElementById('staxx-import-close');

  var importData    = null;  // the last import-list reply — {templates, projects, loose}
  var importEntries = [];    // every entry from that reply, flattened in the order rendered — data-import-toggle below is a position in this array
  var importOpenIdx = {};    // which rows are expanded this open, keyed by that position

  /* ---- Writing templates in (PLAN_41 phase 2) ----
   *
   * Only template rows are ever selectable — Compose Manager projects and the
   * "Neither" group stay read-only, so none of the state below applies to
   * them. importSelected only ever holds idxs of AVAILABLE template rows;
   * whatever marks a row unavailable (a folder switch that lands on a taken
   * name, a write that just succeeded) also deletes it from here, so a plain
   * count of this object's keys is always the right number for the button. */
  var importRoot     = '';    // the stack root, for the "files land here" sentence
  var importExisting = [];    // [{folder, leaf}] — every stack that exists, kept in sync as writes land
  // A sentinel rather than a real folder id — no real one can start with an
  // underscore (staxx_valid_name() requires alphanumeric first), so this can
  // never collide with a folder someone actually made.
  var IMPORT_MATCH   = '__match__';
  var importFolder   = '';    // the chosen destination: '' (top level), a folder id, or IMPORT_MATCH
  var importSelected = {};    // idx -> true, ticked template rows
  var importWrittenIdx = {};  // idx -> true once this session's run has written it
  var importBusy      = false;
  var importStopFlag  = false; // Stop was pressed — finish the row in flight, then quit

  function importSourceLabel(entry) {
    if (entry.source === 'project') return 'Compose Manager project';
    if (entry.source === 'loose') return 'Not managed by StaXX';
    return 'Unraid template';
  }

  function importStateLabel(entry) {
    if (!entry.exists) return 'Not installed';
    return entry.running ? 'Running' : 'Stopped';
  }

  // Every row has its own destination while "Match my Docker folders" is
  // chosen — its own folderName, or the top level when it has none. Any
  // other choice in the picker is one folder for every row alike.
  function importRowFolder(entry) {
    if (importFolder === IMPORT_MATCH) return entry.folderName || '';
    return importFolder;
  }

  // A template row can be ticked only while its own name (entry.folder — the
  // server's already-sanitised stack name) is free INSIDE ITS OWN DESTINATION
  // FOLDER. Compared case-insensitively because the default stack root is the
  // flash drive, which does not distinguish case, so "Vert" and "vert" are
  // the same folder whichever of them typed a name first.
  function importIsTaken(entry, folder) {
    var leaf = String(entry.folder || '').toLowerCase();
    var f    = String(folder || '').toLowerCase();
    return importExisting.some(function (s) {
      return String(s.folder || '').toLowerCase() === f &&
             String(s.leaf   || '').toLowerCase() === leaf;
    });
  }

  function importSelectedCount() {
    var n = 0;
    for (var k in importSelected) if (importSelected[k]) n++;
    return n;
  }

  // Initials and a colour worked out from the name, the same shape as
  // staxx_icon_initials() in Icons.php (split on non-alphanumeric runs, two
  // letters either way, a deterministic colour 0-9 matching the ten
  // .staxx-tile--N rules already in the stylesheet). Not the same hash —
  // crc32 is not reachable from here — but this tile is only ever a stand-in
  // until the sweep below fills in a real icon, or a permanent one for a row
  // with no icon at all, so it only has to be stable and legible, not
  // pixel-identical to the main table's.
  function importInitials(name) {
    var words = String(name || '').split(/[^A-Za-z0-9]+/).filter(function (w) { return w; });
    var text;
    if (words.length >= 2) text = (words[0].charAt(0) + words[1].charAt(0)).toUpperCase();
    else if (words.length === 1) text = words[0].slice(0, 2).toUpperCase();
    else text = '?';

    var s = String(name || '').toLowerCase();
    var hash = 0;
    for (var i = 0; i < s.length; i++) hash = (hash * 31 + s.charCodeAt(i)) >>> 0;
    return { text: text, colour: hash % 10 };
  }

  // Builds exactly what staxx_icon_tile() writes server-side for the main
  // table, from the {fa, url, ref} the reply carries for every row — reusing
  // the same classes and data-icon-ref hook rather than a second rendering
  // path, so paintIcons() and the broken-image fallback listener (both
  // already wired for the main table) work here unchanged.
  function importIconHtml(entry) {
    var icon = entry.icon || {};
    var inner;
    if (icon.fa) {
      inner = '<i class="fa ' + esc(icon.fa) + '"></i>';
    } else if (icon.url) {
      inner = '<img src="' + esc(icon.url) + '" alt="">';
    } else {
      var tile = importInitials(entry.name);
      var ref = icon.ref ? ' data-icon-ref="' + esc(icon.ref) + '"' : '';
      inner = '<span class="staxx-tile staxx-tile--' + tile.colour + '"' + ref + '>' + esc(tile.text) + '</span>';
    }
    return '<span class="staxx-icon staxx-import-rowicon">' + inner + '</span>';
  }

  // The row's own destination, shown only while "Match my Docker folders" is
  // chosen — so nothing lands anywhere without the row having said so first.
  function importDestHtml(entry) {
    if (importFolder !== IMPORT_MATCH) return '';
    var folderName = entry.folderName || '';
    if (!folderName) return '<span class="staxx-import-rowdest">Top level</span>';
    if (entry.folderRenamed) {
      return '<span class="staxx-import-rowdest staxx-import-rowdest--renamed">→ ' +
        esc(folderName) + ' (Docker calls it “' + esc(entry.dockerFolder || '') + '”)</span>';
    }
    return '<span class="staxx-import-rowdest">→ ' + esc(folderName) + '</span>';
  }

  // A project's preview has no endpoint to read the file's contents yet
  // (phase 2) — it can only say where the file was found, which is the thing
  // PLAN_38 itself says is most likely to be wrong.
  function importProjectPreviewHtml(entry) {
    var viaText = {
      indirect: 'an indirect file',
      label: "the container's own label",
      flash: 'the project folder on the flash drive'
    }[entry.via];

    if (!entry.file) {
      return '<p class="staxx-form-empty">No compose file could be found for this project.</p>';
    }

    return '<p class="staxx-import-via">' +
             'Found via ' + esc(viaText || 'an unrecorded source') + '. This file would be copied:' +
           '</p>' +
           '<pre class="staxx-fieldraw">' + esc(entry.file) + '</pre>';
  }

  // A template's preview runs the same converter Add-an-app uses, on the
  // decoded template the server already sent — guarded the same way
  // caImport() guards it (:5701 or thereabouts), because a stale cached
  // script must leave the row without a preview rather than throw and take
  // the whole page down with it.
  function importTemplatePreviewHtml(entry) {
    if (!window.StaxxCA || typeof window.StaxxCA.convert !== 'function') {
      return '<p class="staxx-form-empty">The app converter has not loaded. Reload the page and try again.</p>';
    }

    var result;
    try {
      // 'template' here, not the CA-catalogue default: what a row previews
      // has to be byte-for-byte what pressing Import would write, and that
      // depends on the first line the converter writes naming its source.
      result = window.StaxxCA.convert(entry.app, { appdataRoot: APPDATA, origin: 'template' });
    } catch (e) {
      return '<p class="staxx-form-empty">This template could not be converted: ' +
             esc(e && e.message ? e.message : String(e)) + '</p>';
    }

    var bits = ['<pre class="staxx-fieldraw">' + esc(result.yaml) + '</pre>'];
    if (result.warnings && result.warnings.length) {
      bits.push('<p class="staxx-import-warn">Could not be translated automatically:</p>' +
        '<ul class="staxx-import-notes">' +
          result.warnings.map(function (w) { return '<li>' + esc(w) + '</li>'; }).join('') +
        '</ul>');
    }
    if (result.notes && result.notes.length) {
      bits.push('<p class="staxx-import-warn">Filled in for you — check these before starting:</p>' +
        '<ul class="staxx-import-notes">' +
          result.notes.map(function (n) { return '<li>' + esc(n) + '</li>'; }).join('') +
        '</ul>');
    }
    return bits.join('');
  }

  function importPreviewHtml(entry) {
    if (entry.source === 'project') return importProjectPreviewHtml(entry);
    if (entry.source === 'loose') {
      return '<p class="staxx-form-empty">This container belongs to neither a template nor a ' +
             'compose project, so there is nothing to preview.</p>';
    }
    return importTemplatePreviewHtml(entry);
  }

  function importRowHtml(entry, idx) {
    var open = !!importOpenIdx[idx];
    var notesHtml = (entry.notes && entry.notes.length)
      ? '<ul class="staxx-import-notes">' +
          entry.notes.map(function (n) { return '<li>' + esc(n) + '</li>'; }).join('') +
        '</ul>'
      : '';

    // A template whose own XML could not be read (entry.app is null) is
    // never selectable — the notes above already say why, so no flag is
    // needed on top of that.
    var isTemplate  = entry.source === 'template';
    var selectable  = isTemplate && !!entry.app;
    // Once a row has been written this session it stays done regardless of
    // which folder is chosen afterwards — it is not re-offered for a second
    // folder just because that folder happens to be free of the name too.
    var taken       = selectable && (importWrittenIdx[idx] || importIsTaken(entry, importRowFolder(entry)));
    var takenHtml   = (!isTemplate && entry.taken)
      ? '<span class="staxx-import-flag staxx-import-flag--taken">Already in StaXX</span>' : '';

    var head = '<button type="button" class="staxx-import-rowhead" data-import-toggle="' + idx + '" ' +
                 'aria-expanded="' + (open ? 'true' : 'false') + '" aria-controls="staxx-import-body-' + idx + '">' +
                 importIconHtml(entry) +
                 '<span class="staxx-import-rowname">' + esc(entry.name) + '</span>' +
                 '<span class="staxx-import-rowsrc">' + importSourceLabel(entry) + '</span>' +
                 '<span class="staxx-import-rowstate">' + importStateLabel(entry) + '</span>' +
                 (isTemplate ? importDestHtml(entry) : '') +
                 takenHtml +
               '</button>';

    var body;
    if (selectable) {
      // The tick box is a SIBLING of the row's button, never nested inside
      // it — a button can't validly contain another control, and nesting it
      // is what would make ticking the box also fire the button's own click
      // and expand the row.
      var mark = taken
        ? '<span class="staxx-import-flag staxx-import-flag--taken">' +
            (importWrittenIdx[idx] ? 'Now in StaXX' : 'Already in StaXX') + '</span>'
        : '<input type="checkbox" class="staxx-import-check" id="staxx-import-check-' + idx + '" ' +
            'data-import-check="' + idx + '" aria-label="' + esc('Import ' + entry.name) + '"' +
            (importSelected[idx] ? ' checked' : '') + '>';
      body = '<div class="staxx-import-rowline">' + mark + head + '</div>';
    } else {
      body = head;
    }

    var rowTaken = taken || (!isTemplate && entry.taken);
    return '<div class="staxx-import-row' + (rowTaken ? ' staxx-import-row--taken' : '') + '">' +
             body +
             notesHtml +
             '<div class="staxx-import-body" id="staxx-import-body-' + idx + '"' + (open ? '' : ' hidden') + '></div>' +
           '</div>';
  }

  // Three groups, each only written when it has something to show — same
  // rule caRenderAll() uses above. importEntries is rebuilt here rather than
  // kept from the fetch, because it has to hold exactly the entries in the
  // order they were rendered for the toggle handler's index to mean anything.
  function importRenderAll() {
    var data = importData;
    if (!data) return;

    // The third element marks the one group that gets tick boxes. The other
    // two get a plain sentence instead — Compose Manager import needs
    // multi-file support this phase does not have (PLAN_35 phases 4a/4b).
    var groups = [
      ['Unraid templates', data.templates || [], true],
      ['Compose Manager projects', data.projects || [], false],
      ['Neither', data.loose || [], false]
    ];

    importEntries = [];
    var blocks = [];
    groups.forEach(function (g) {
      var label = g[0], list = g[1], selectableGroup = g[2];
      if (!list.length) return;
      var rowsHtml = list.map(function (entry) {
        var idx = importEntries.length;
        importEntries.push(entry);
        return importRowHtml(entry, idx);
      }).join('');
      var extra = selectableGroup
        ? '<label class="staxx-import-selectall">' +
            '<input type="checkbox" data-import-allcheck aria-label="Select all templates">' +
            '<span>Select all</span>' +
          '</label>'
        : '<span class="staxx-import-groupnote">Importing these is not built yet.</span>';
      blocks.push(
        '<div class="staxx-import-grouphead">' +
          '<h4 class="staxx-import-group">' + label +
          ' <span class="staxx-import-count">' + list.length + '</span></h4>' +
          extra +
        '</div>' +
        '<div class="staxx-import-rows">' + rowsHtml + '</div>');
    });

    importList.innerHTML = blocks.length
      ? blocks.join('')
      : '<p class="staxx-form-empty">Nothing was found to import.</p>';

    // The destination controls only matter when there is at least one
    // template row to tick — no point asking where to put nothing.
    importDest.hidden = !(data.templates && data.templates.length);
  }

  // A folder switch can turn an available row into a taken one — a name free
  // at the top level need not be free inside a folder. Anything ticked that
  // is no longer offerable is dropped, or the count and the run below would
  // both still include it.
  function importPruneSelected() {
    for (var k in importSelected) {
      var idx = Number(k);
      var entry = importEntries[idx];
      var selectable = !!entry && entry.source === 'template' && !!entry.app;
      if (!selectable || importWrittenIdx[idx] || importIsTaken(entry, importRowFolder(entry))) delete importSelected[k];
    }
  }

  // Rebuilds the whole list, then restores whatever a click had already
  // expanded — importRenderAll() always starts every body empty, so a row
  // left open across a repaint (the folder switch below) would otherwise go
  // blank rather than just losing its scroll position.
  function importPaint() {
    importPruneSelected();
    importRenderAll();
    for (var k in importOpenIdx) {
      if (!importOpenIdx[k]) continue;
      var idx = Number(k);
      var entry = importEntries[idx];
      var body = document.getElementById('staxx-import-body-' + idx);
      if (!entry || !body) continue;
      body.innerHTML = importPreviewHtml(entry);
      body.dataset.filled = '1';
    }
    importSyncSelectAll();
    importFetchIcons();
  }

  // Sweeps whatever this repaint just left carrying a data-icon-ref — the
  // same loop fetchIcons() below already runs for the main table, pointed at
  // the import list instead via scope: 'import' (action.php reads
  // $_POST['scope'] === 'import' to sweep staxx_import_icon_wanted() rather
  // than the main table's own list). A full repaint (every open or folder
  // switch) rebuilds every tile from scratch, so this has to run again each
  // time rather than once per dialog open.
  var importIconsBusy = false;
  function importFetchIcons() {
    if (importIconsBusy || !importModal.open) return;
    if (!importList.querySelector('[data-icon-ref]')) return;
    importIconsBusy = true;
    call('icons', { scope: 'import' }, 60000).then(function (res) {
      importIconsBusy = false;
      if (!importModal.open) return;   // the dialog closed while this was in flight
      if (!res || !res.ok) return;
      paintIcons(res.icons || {});
      if (res.done === false) setTimeout(importFetchIcons, 500);
    });
  }

  // The header tick box reads as a tri-state summary of the rows below it,
  // not a control with its own independent value — so its state is worked
  // out fresh from theirs every time, rather than tracked separately.
  function importSyncSelectAll() {
    var all = importList.querySelector('[data-import-allcheck]');
    if (!all) return;
    var boxes = importList.querySelectorAll('[data-import-check]');
    var checked = 0;
    boxes.forEach(function (b) { if (b.checked) checked++; });
    all.checked = boxes.length > 0 && checked === boxes.length;
    all.indeterminate = checked > 0 && checked < boxes.length;
  }

  function importUpdateDestPath() {
    var root = String(importRoot || '').replace(/\/+$/, '');

    if (importFolder === IMPORT_MATCH) {
      importDestPath.innerHTML = 'Each template you tick will be written into a folder named after ' +
        'its own Docker folder, inside <code>' + esc(root) + '</code> — or at the top level for ' +
        'anything with no Docker folder. Whatever a template already had filled in — passwords and ' +
        'API keys included — is copied straight into that file.';
    } else {
      var path = root + (importFolder ? '/' + importFolder : '');
      importDestPath.innerHTML = 'Templates you tick will be written to <code>' + esc(path) + '</code>. ' +
        'Whatever a template already had filled in — passwords and API keys included — is copied ' +
        'straight into that file.';
    }

    // The pattern-rule notice only matters while StaXX is trying to match
    // folders at all — it would be noise once a single destination is chosen
    // by hand.
    var rules = (importFolder === IMPORT_MATCH && importData) ? (importData.folderRules || []) : [];
    if (rules.length) {
      var many = rules.length > 1;
      importDestNote.textContent = 'Docker decides membership of ' +
        (many ? 'these folders using a pattern rather than a plain list, so StaXX could not match them: ' + rules.join(', ') + '.'
              : 'the folder “' + rules[0] + '” using a pattern rather than a plain list, so StaXX could not match it.') +
        ' Anything from ' + (many ? 'them' : 'it') + ' lands at the top level instead.';
      importDestNote.hidden = false;
    } else {
      importDestNote.hidden = true;
      importDestNote.textContent = '';
    }
  }

  function importUpdateGo() {
    var n = importSelectedCount();
    importGoBtn.textContent = n > 0 ? 'Import (' + n + ')' : 'Import';
    importGoBtn.disabled = importBusy || n === 0;
  }

  function importBuildFolderOptions() {
    var anyMatch = !!(importData && importData.templates &&
      importData.templates.some(function (e) { return e.folderName; }));

    var opts = [];
    if (anyMatch) opts.push('<option value="' + IMPORT_MATCH + '">Match my Docker folders</option>');
    opts.push('<option value="">(top level)</option>');
    FOLDERS.forEach(function (f) {
      opts.push('<option value="' + esc(f.id) + '">' + esc(f.name) + '</option>');
    });
    importFolderSel.innerHTML = opts.join('');

    // Matching is the more useful default whenever it has anything to work
    // with — picking a single folder by hand is still one click away.
    importFolder = anyMatch ? IMPORT_MATCH : '';
    importFolderSel.value = importFolder;
  }

  function importOpen() {
    flushPending();
    importData = null;
    importEntries = [];
    importOpenIdx = {};
    importSelected = {};
    importWrittenIdx = {};
    importRoot = '';
    importFolder = '';
    importList.innerHTML = '';
    importSummary.hidden = true;
    importSummary.innerHTML = '';
    importDest.hidden = true;
    importMsg.textContent = 'Reading the server…';
    importUpdateGo();
    importModal.showModal();

    call('import-list', {}, 20000).then(function (res) {
      if (!importModal.open) return;   // the dialog closed while this was in flight
      if (!res.ok) { importMsg.textContent = res.error; return; }
      importData = res;
      importRoot = res.root || '';
      importExisting = res.existing || [];
      importMsg.textContent = '';
      importBuildFolderOptions();
      importUpdateDestPath();
      importPaint();
    });
  }

  // One delegated listener for every row, the same shape as helpBtnHtml's
  // toggle (:3434) — the preview is built once, the first time a row opens,
  // and left in the DOM rather than rebuilt on every click. Ticking the box
  // beside it fires no click here at all, since the box is a sibling of this
  // button rather than nested inside it.
  importList.addEventListener('click', function (event) {
    var btn = event.target.closest('[data-import-toggle]');
    if (!btn) return;

    var idx = Number(btn.dataset.importToggle);
    var entry = importEntries[idx];
    if (!entry) return;

    var open = !importOpenIdx[idx];
    importOpenIdx[idx] = open;
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');

    var body = document.getElementById('staxx-import-body-' + idx);
    if (!body) return;
    body.hidden = !open;
    if (open && !body.dataset.filled) {
      body.innerHTML = importPreviewHtml(entry);
      body.dataset.filled = '1';
    }
  });

  // The per-row box and the group's header box, both delegated the same way
  // as the toggle above.
  importList.addEventListener('change', function (event) {
    var box = event.target.closest('[data-import-check]');
    if (box) {
      var idx = Number(box.dataset.importCheck);
      if (box.checked) importSelected[idx] = true; else delete importSelected[idx];
      importSyncSelectAll();
      importUpdateGo();
      return;
    }

    var all = event.target.closest('[data-import-allcheck]');
    if (!all) return;
    var checked = all.checked;
    importList.querySelectorAll('[data-import-check]').forEach(function (b) {
      b.checked = checked;
      var i = Number(b.dataset.importCheck);
      if (checked) importSelected[i] = true; else delete importSelected[i];
    });
    importUpdateGo();
  });

  importFolderSel.addEventListener('change', function () {
    importFolder = importFolderSel.value;
    // A name taken at the top level may be free inside a folder and the
    // other way round, so every template row's availability is worked out
    // fresh against the newly chosen folder rather than carried over.
    importPaint();
    importUpdateDestPath();
    importUpdateGo();
  });

  // Marks one row as done in place, rather than repainting the whole list —
  // during a run of eighty this runs once per row, and a full repaint each
  // time would both flicker and reset whatever else was expanded.
  function importMarkWritten(idx) {
    importWrittenIdx[idx] = true;
    delete importSelected[idx];
    var box = document.getElementById('staxx-import-check-' + idx);
    if (!box) return;
    var flag = document.createElement('span');
    flag.className = 'staxx-import-flag staxx-import-flag--taken';
    flag.textContent = 'Now in StaXX';
    box.replaceWith(flag);
    var row = flag.closest('.staxx-import-row');
    if (row) row.classList.add('staxx-import-row--taken');
  }

  function importSetBusyUI(busy) {
    importList.toggleAttribute('inert', busy);
    importDest.toggleAttribute('inert', busy);
    importCloseBtn.textContent = busy ? 'Stop' : 'Close';
    importCloseBtn.disabled = false;
    importUpdateGo();
  }

  // Writes every ticked row one at a time. Sequential on purpose — each
  // write runs compose's own validation on the server, and firing them all
  // at once would bury the box rather than show progress.
  function importRunSelected() {
    var idxs = [];
    for (var k in importSelected) if (importSelected[k]) idxs.push(Number(k));
    if (!idxs.length) return;

    importBusy = true;
    importStopFlag = false;
    importSummary.hidden = true;
    importSetBusyUI(true);

    var total = idxs.length;
    var written = 0;
    var failures = [];
    var folderFailures = {};   // folderName -> error, filled in only in Match mode

    // Every distinct folder a ticked row would land in, minus whatever
    // already exists — checked against the folder list this dialog already
    // has, since folder-create itself refuses rather than confirms a name is
    // already there.
    function neededFolders() {
      if (importFolder !== IMPORT_MATCH) return [];
      var seen = {}, out = [];
      idxs.forEach(function (idx) {
        var name = importEntries[idx].folderName;
        if (!name || seen[name.toLowerCase()]) return;
        seen[name.toLowerCase()] = true;
        if (!FOLDERS.some(function (f) { return f.id.toLowerCase() === name.toLowerCase(); })) out.push(name);
      });
      return out;
    }

    // Made one at a time, before any writing starts — a row whose folder
    // could not be made needs to know that before step() decides where it
    // is going, not partway through.
    function ensureFolders(names, cb) {
      if (!names.length) { cb(); return; }
      importMsg.textContent = 'Creating folders…';
      var i = 0;
      function next() {
        if (i >= names.length) { cb(); return; }
        var name = names[i++];
        call('folder-create', { folderName: name }, 20000).then(function (res) {
          if (res.ok) FOLDERS.push({ id: res.id || name, name: name });
          else folderFailures[name] = res.error;
          next();
        });
      }
      next();
    }

    function step(i) {
      if (i >= idxs.length || importStopFlag) { finish(); return; }
      var idx = idxs[i];
      var entry = importEntries[idx];
      importMsg.textContent = 'Writing ' + (i + 1) + ' of ' + total + ' — ' + entry.name;

      var result;
      try {
        result = window.StaxxCA.convert(entry.app, { appdataRoot: APPDATA, origin: 'template' });
      } catch (e) {
        // The converter runs against whatever template is actually on this
        // server, which answers to no schema this plugin controls — one bad
        // one must not take the rest of the run down with it.
        failures.push({ name: entry.name, error: e && e.message ? e.message : String(e) });
        step(i + 1);
        return;
      }

      // A folder that could not be made falls this one row back to the top
      // level rather than losing it — the summary below says which folders
      // that happened to and why.
      var destFolder = importRowFolder(entry);
      if (destFolder && folderFailures[destFolder]) destFolder = '';
      var stackName = destFolder ? destFolder + '/' + entry.folder : entry.folder;

      var about = JSON.stringify({
        source: 'template',
        id: entry.id,
        name: entry.name,
        container: entry.name,
        containerExists: entry.exists,
        containerRunning: entry.running,
        warnings: result.warnings,
        notes: result.notes
      });

      call('import-write', { name: stackName, body: result.yaml, about: about }, 20000).then(function (res) {
        if (res.ok) {
          written++;
          importExisting.push({ folder: destFolder, leaf: entry.folder });
          importMarkWritten(idx);
        } else {
          failures.push({ name: entry.name, error: res.error });
        }
        step(i + 1);
      });
    }

    function finish() {
      importBusy = false;
      importSetBusyUI(false);
      importMsg.textContent = '';

      var bits = ['<p class="staxx-import-summarymsg">Wrote ' + written + ' of ' + total + '.</p>'];

      var badFolders = Object.keys(folderFailures);
      if (badFolders.length) {
        bits.push('<p class="staxx-import-summarymsg">Could not create ' +
          (badFolders.length > 1 ? 'these folders' : 'this folder') + ', so anything meant for ' +
          (badFolders.length > 1 ? 'them' : 'it') + ' went to the top level instead:</p>' +
          '<ul class="staxx-import-summaryfails">' +
          badFolders.map(function (name) {
            return '<li><strong>' + esc(name) + '</strong> — ' + esc(folderFailures[name] || '(no detail given)') + '</li>';
          }).join('') +
          '</ul>');
      }

      if (failures.length) {
        bits.push('<ul class="staxx-import-summaryfails">' +
          failures.map(function (f) {
            return '<li><strong>' + esc(f.name) + '</strong> — ' + esc(f.error || '(no detail given)') + '</li>';
          }).join('') +
        '</ul>');
      }
      importSummary.innerHTML = bits.join('');
      importSummary.hidden = false;

      if (written > 0) refreshRows();  // the new stacks belong on the page behind this dialog
    }

    ensureFolders(neededFolders(), function () { step(0); });
  }

  importGoBtn.addEventListener('click', function () {
    if (importBusy || importGoBtn.disabled) return;
    importRunSelected();
  });

  importCloseBtn.addEventListener('click', function () {
    if (importBusy) {
      // Stops after the row in flight finishes rather than mid-write, so a
      // stack never ends up with half a compose file behind its lock note.
      importStopFlag = true;
      importCloseBtn.disabled = true;
      importCloseBtn.textContent = 'Stopping…';
      return;
    }
    importModal.close();
  });

  importModal.addEventListener('click', function (event) {
    if (importBusy) return;   // Close is Stop while a run is in flight; no backdrop escape either
    if (event.target !== importModal) return;
    var r = importModal.getBoundingClientRect();
    if (event.clientX < r.left || event.clientX > r.right ||
        event.clientY < r.top  || event.clientY > r.bottom) importModal.close();
  });

  // Escape fires 'cancel' before 'close' on a <dialog> — block it here for
  // the same reason the backdrop click above is blocked while busy.
  importModal.addEventListener('cancel', function (event) {
    if (importBusy) event.preventDefault();
  });

  importModal.addEventListener('close', function () {
    // A stale list must never flash up on the next open.
    importData = null;
    importEntries = [];
    importOpenIdx = {};
    importSelected = {};
    importWrittenIdx = {};
    importExisting = [];
    importRoot = '';
    importFolder = '';
    importBusy = false;
    importStopFlag = false;
    importList.innerHTML = '';
    importSummary.hidden = true;
    importSummary.innerHTML = '';
    importDest.hidden = true;
  });

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

      // Dedupe against netmode's own vocab (a server network actually called
      // bridge or host must not be listed twice), not against the mutable
      // NETWORKS this fills in — see NETWORKS's own comment for why the two
      // are kept apart.
      var known = {};
      var vocab = safeVocab('netmode') || [];
      for (var i = 0; i < vocab.length; i++) known[vocab[i][0]] = true;

      NETWORKS = [];
      var nets = res.networks || [];
      for (var n = 0; n < nets.length; n++) {
        var name = nets[n].name, driver = nets[n].driver;
        if (!name || known[name]) continue;
        NETWORKS.push([name, driver ? name + ' — ' + driver + ' network on this server' : name]);
        known[name] = true;
      }

      var first = !netLoaded;
      netLoaded = true;
      if (first && modal.open && MODEL && !commitTimer && !devPanel) reparse();

      return res;
    });
  }

  // Images already pulled onto this server — offered in the image datalist
  // alongside whatever tagLoad() below finds for one repo at a time.
  // Modelled on netLoad() just above: same first-reply-only redraw, guarded
  // the same way.
  function imgLoad() {
    return call('images', {}, 15000).then(function (res) {
      if (!res.ok) return res;

      IMAGES = res.images || [];

      var first = !imgLoaded;
      imgLoaded = true;
      if (first && modal.open && MODEL && !commitTimer && !devPanel) reparse();

      return res;
    });
  }

  // "repo:tag" splits on the LAST ':', but only past the last '/' — otherwise
  // "localhost:5000/foo" loses its port to the split. No colon past the last
  // slash means no tag has been typed yet, so the whole value is the repo.
  function repoOf(value) {
    var v = String(value || '');
    var slash = v.lastIndexOf('/'), colon = v.lastIndexOf(':');
    return colon > slash ? v.slice(0, colon) : v;
  }

  // Unlike imgLoad()/netLoad(), this never redraws the form — a repo is
  // looked up while its box is being typed in, and a redraw would take the
  // caret with it. The new tags are spliced straight into that one box's own
  // <datalist> instead. Cached per repo for the session, a negative result
  // (registry does not carry it, or was never going to be asked, see
  // action.php's `tags` case) included, so a private registry is asked about
  // once and a re-opened stack costs nothing.
  function tagLoad(box) {
    var repo = repoOf(box.value);
    if (!repo) return;

    if (tagCache.hasOwnProperty(repo)) { mergeTags(box, repo, tagCache[repo]); return; }

    call('tags', { repo: repo }, 15000).then(function (res) {
      if (!res.ok) return;   // a network hiccup, not "no tags" — do not cache it
      tagCache[repo] = res.tags || [];
      // The box may have moved on to a different repo, or vanished (removed,
      // or the form redrew under it), by the time this lands.
      if (box.isConnected && repoOf(box.value) === repo) mergeTags(box, repo, tagCache[repo]);
    }).catch(function () {});
  }

  function mergeTags(box, repo, tags) {
    var dl = document.getElementById(box.getAttribute('list') || '');
    if (!dl || !tags.length) return;

    var known = {};
    var existing = dl.querySelectorAll('option');
    for (var i = 0; i < existing.length; i++) known[existing[i].value] = true;

    var frag = document.createDocumentFragment();
    for (var t = 0; t < tags.length; t++) {
      var full = repo + ':' + tags[t];
      if (known[full]) continue;
      var opt = document.createElement('option');
      opt.value = full;
      opt.textContent = full;
      frag.appendChild(opt);
    }
    dl.appendChild(frag);
  }

  function scheduleTagLoad(box) {
    if (tagTimer) clearTimeout(tagTimer);
    tagTimer = setTimeout(function () { tagTimer = null; tagLoad(box); }, 400);
  }

  // Typing in the image box, or landing on it, is what makes its repo worth
  // asking the registry about — delegated, since the box is redrawn whenever
  // the form is (adding a service, undo, reparse...).
  formHost.addEventListener('input', function (event) {
    var el = event.target;
    if (el.dataset.part !== 'value' || !el.dataset.row) return;
    var f = MODEL && MODEL.fields[el.dataset.row | 0];
    if (f && f.binder === 'setting' && f.target === 'image') scheduleTagLoad(el);
  });

  // 'focus' does not bubble, so this delegated pair uses 'focusin' instead —
  // reaching the same box the moment it is tabbed or clicked into, before
  // anything has been typed.
  formHost.addEventListener('focusin', function (event) {
    var el = event.target;
    if (el.dataset.part !== 'value' || !el.dataset.row) return;
    var f = MODEL && MODEL.fields[el.dataset.row | 0];
    if (f && f.binder === 'setting' && f.target === 'image') scheduleTagLoad(el);
  });

  function devShellHtml() {
    return '<div class="staxx-devhead">' +
             '<input type="text" class="staxx-input staxx-devfilter"' +
                   ' placeholder="Filter devices" spellcheck="false"' + NOFILL +
                   ' aria-label="Filter the device list">' +
             '<button type="button" class="staxx-browse staxx-devrefresh"' +
                   ' title="Look again — for something just plugged in">' +
               '<i class="fa fa-refresh" aria-hidden="true"></i>' +
               '<span class="staxx-sr">Look again</span>' +
             '</button>' +
             '<button type="button" class="staxx-browse staxx-devcancel" title="Close">' +
               '<i class="fa fa-times" aria-hidden="true"></i>' +
               '<span class="staxx-sr">Close the device list</span>' +
             '</button>' +
           '</div>' +
           '<div class="staxx-devbody"></div>' +
           '<div class="staxx-devfoot">' +
             '<button type="button" class="staxx-devall"></button>' +
             '<span class="staxx-devmsg" role="status"></span>' +
           '</div>';
  }

  function devRowHtml(d) {
    var claim = devClaims[d.host] || [];
    var also  = (d.companions || []).map(function (c) { return c.key + ': ' + c.value; });

    return '<button type="button" class="staxx-devrow' +
             (d.risky ? ' staxx-devrow--risky' : '') + '"' +
             ' data-dev="' + esc(d.host) + '">' +
             '<span class="staxx-devname">' + esc(d.label) +
               // Two containers holding one Zigbee stick is a setup that looks
               // fine and never works. Said, not blocked: sharing /dev/dri
               // between two transcoders is perfectly reasonable.
               (claim.length
                 ? '<span class="staxx-devclaim">in use by ' + esc(claim.join(', ')) + '</span>'
                 : '') +
             '</span>' +
             '<span class="staxx-devpath">' + esc(d.host) +
               (d.container !== d.host ? ' → ' + esc(d.container) : '') + '</span>' +
             (d.hint ? '<span class="staxx-devhint">' + esc(d.hint) + '</span>' : '') +
             (also.length
               ? '<span class="staxx-devhint staxx-devhint--also">Also needs ' +
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

      bits.push('<h5 class="staxx-devgroup">' + esc(grp.title) + '</h5>');
      if (grp.note) bits.push('<p class="staxx-devnote">' + esc(grp.note) + '</p>');
      bits.push(rows.join(''));
    }

    if (!shown) {
      bits.push('<p class="staxx-devnote">' + esc(
        !devLoaded  ? 'Asking the server what it has…'
        : devFilter ? 'Nothing matches "' + devFilter + '".'
        : 'This server reports no devices that can be handed to a container.'
      ) + '</p>');
    }

    // Only the body is rewritten, never the whole panel — the filter box is in
    // the header, and replacing it while it is being typed in would take the
    // caret with it on every keystroke.
    devPanel.querySelector('.staxx-devbody').innerHTML = bits.join('');

    var all = devPanel.querySelector('.staxx-devall');
    all.hidden = !held && !devShowAll;
    all.textContent = devShowAll
      ? 'Hide disks and the USB bus'
      : 'Show everything in /dev (' + held + ' more)';
  }

  function devMsg(text) {
    if (devPanel) devPanel.querySelector('.staxx-devmsg').textContent = text || '';
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
    devPanel.className = 'staxx-devpick';
    devPanel.innerHTML = devShellHtml();
    anchor.after(devPanel);

    devPaint();
    devPanel.scrollIntoView({ block: 'nearest' });
    devPanel.querySelector('.staxx-devfilter').focus();

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
      if (event.target.closest('.staxx-devcancel'))  { devClose(); return; }
      if (event.target.closest('.staxx-devrefresh')) { devMsg('Looking again…');
                                                          devRefresh(); return; }
      if (event.target.closest('.staxx-devall'))     { devShowAll = !devShowAll; devPaint(); }
      return;
    }

    var back = event.target.closest('[data-vol-switch]');
    if (back) { if (!sanitised) swapVolumeToChoice(back); return; }

    var btn = event.target.closest('[data-tool]');
    if (!btn || sanitised) return;
    var box = btn.closest('.staxx-boxline').querySelector('.staxx-input');
    if (!box) return;

    if (btn.dataset.tool === 'tz')          tzOpen(box);
    else if (btn.dataset.tool === 'device') devOpen(btn.closest('.staxx-fieldrow'), box, '');
    else                                    pickerOpen(box);
  });

  formHost.addEventListener('input', function (event) {
    if (!devPanel || !event.target.classList.contains('staxx-devfilter')) return;
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

    // Yesterday's tabs are meaningless against today's stack — cleared before
    // the fresh listing arrives (or, for a new stack with no folder yet, before
    // renderTabs() below draws the bare compose tab and leaves it at that).
    fileOpen = null;
    fileStash = '';
    fileAtLoad = '';
    fileEol = '\n';
    viewBeforeFile = null;
    FILES = [];
    envVars = null;   // yesterday's .env answer is meaningless against today's stack
    fileDots = {};
    fileMime = {};
    hideBinPanel();   // yesterday's stack may have left this showing

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
    // A textarea always normalises CRLF to LF on the way in, so a CRLF file's
    // ending is remembered here (for save() to put back) and textAtOpen is
    // read from the box itself rather than from the raw text — otherwise the
    // dirty check below compares CRLF against LF and never agrees, even with
    // nothing typed.
    var raw = body || (isNew ? NEW_STACK : '');
    composeEol = raw.indexOf('\r\n') >= 0 ? '\r\n' : '\n';
    yamlPane.value = raw;
    textAtOpen = yamlPane.value;

    // Before setView, which draws the form: a freshly loaded file that says a
    // service has no networks is telling the truth about it, so nothing from
    // the last stack's editing session may survive into this one. A search
    // is exactly the same kind of leftover — findReset() below.
    sectionsOpen = {};
    sectionOn    = {};
    stackOpen    = false;
    netFoldOpen  = {};
    findReset();
    pathsReset();   // a fresh cache and marks for the stack that is about to open
    // Yesterday's compose-check answer, and any request still in flight for
    // it, are meaningless against today's stack — the seq bump is what stops
    // a late reply for the stack just left from painting over this one.
    if (checkTimer) { clearTimeout(checkTimer); checkTimer = null; }
    checkSeq++;
    checkedText = null;
    checkVerdict = null;
    checkDot = null;
    hideSuggest();   // neither panel may leak from one stack's editor into the next
    hideHover();
    closeOutline();   // yesterday's line numbers are meaningless against today's stack
    closeTabmenu();   // yesterday's menu would be positioned against a tab that is gone
    setView(defaultView());

    undoStack.length = 0;
    updateUndo();

    lockScroll(true);
    modal.showModal();

    // After showModal(), not before. A closed dialog is display: none, so the
    // gutter measures zero wide and every band would be positioned against a
    // line height of nothing.
    gutterLines = -1;
    // A different stack's lines could coincidentally match this one's at the
    // same index — reset the tracking so paintInk() cannot mistake that for
    // nothing having changed and skip painting lines that were never drawn.
    inkLines   = [];
    carryAfter = [];
    activeField = null;
    LINE_H = 0;
    measure();
    paintGutter();
    paintInk();
    syncGutter();
    reparse();

    // Ask what hardware this server has, so device rows can be named after it
    // rather than showing a bare path. Not waited for — the form is usable at
    // once and devLoad() redraws it when the names arrive. netLoad() does the
    // same for this server's own docker networks, feeding the network_mode
    // dropdown, and imgLoad() for the images already on this server, feeding
    // the image datalist.
    devLoad().catch(function () {});
    netLoad().catch(function () {});
    imgLoad().catch(function () {});
    // A new stack has no folder on disk yet, so there is nothing to list —
    // draw the bare, uncloseable compose tab and stop there.
    if (isNew) renderTabs(); else filesLoad();

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
    // The find bar owns Escape first: closing it and landing back in the
    // textarea is "get me out of this box" too, and takes priority over the
    // dialog's own Escape-closes-me behaviour below.
    if (findIsOpen()) {
      event.preventDefault();
      closeFind();
      return;
    }
    // Escape inside the compose box means "get me out of this box", not "throw
    // my work away". The first press leaves the textarea; a second one closes.
    // This doubles as the escape hatch from the Tab key being captured below.
    if (document.activeElement === yamlPane) {
      event.preventDefault();
      // Whichever button is pressed right now. Naming one outright would pick a
      // button that is not there — Split is hidden on a narrow screen.
      modal.querySelector('.staxx-viewbtn[aria-pressed="true"]').focus();
      return;
    }
    if (!confirmDiscard()) event.preventDefault();
  });

  modal.addEventListener('close', function () {
    lockScroll(false);
    clearError();
    // The dialog is already closing, synchronously, so this cannot be
    // awaited — but the save request it fires still completes over the
    // network after that, which is all a last edit needs.
    flushFileSave();
    fileOpen = null;
    fileStash = '';
    fileAtLoad = '';
    fileEol = '\n';
    viewBeforeFile = null;
    FILES = [];
    envVars = null;
    fileDots = {};
    fileMime = {};
    hideBinPanel();
    // Emptied, never hidden. The strip is permanent — it carries the New file
    // and Add a file buttons whether or not there is a second tab — and
    // nothing anywhere sets `hidden` back to false, so hiding it here was a
    // one-way door: renderTabs() went on building the tabs correctly on every
    // later open, into a strip CSS had already taken out of the layout.
    if (tabsBar) tabsBar.innerHTML = '';
    // Nothing the user can do closes the editor from under the picker, but
    // closeEditor() can be called from code. A picker left open over a closed
    // editor would be pointing at an input that no longer exists.
    if (picker.open) picker.close();
    if (tzModal.open) tzModal.close();
    devClose();
    findReset();   // a search must not leak from one stack into the next
    pathsReset();
    if (checkTimer) { clearTimeout(checkTimer); checkTimer = null; }
    checkSeq++;   // a reply landing after close must find itself superseded
    checkedText = null;
    checkVerdict = null;
    checkDot = null;
    hideSuggest();
    hideHover();
    closeOutline();
    closeTabmenu();
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
    var btn = event.target.closest('.staxx-viewbtn');
    if (!btn) return;
    // A view picked while a companion tab is on screen is a deliberate choice
    // and outlives that tab; without this the way back to the compose file
    // snaps to whatever it was showing before, undoing the choice.
    if (fileOpen !== null) viewBeforeFile = btn.dataset.view;
    setView(btn.dataset.view);
  });

  /* ---- the file tab strip -------------------------------------------------
   *
   * The compose file and its box are the same textarea a companion file
   * shows — there is no second editor. openFile() is what moves the box
   * between them; everything else here supports that one job. */

  // The real compose filename once FILES has arrived; a placeholder for the
  // instant before that first `files` reply lands.
  function tabLabel() {
    for (var i = 0; i < FILES.length; i++) {
      if (FILES[i].compose) return FILES[i].name;
    }
    return 'compose.yaml';
  }

  // filename -> 'pending' | 'bad', for whichever autosave has not landed (or
  // failed) yet. Kept apart from the DOM rather than read off it, because
  // renderTabs() below rebuilds the strip from scratch on every switch —
  // reading dots back off elements about to be thrown away would lose
  // exactly the ones this exists to keep visible.
  var fileDots = {};

  function tabDotHtml(name) {
    var state = fileDots[name];
    return '<span class="staxx-tab-dot' + (state === 'bad' ? ' staxx-tab-dot--bad' : '') + '"' +
           (state ? '' : ' hidden') + '></span>';
  }

  // [] while the scan has not landed in the model yet — guarded the same way
  // checkHostPaths() guards for YAML.hostPaths. Always read from
  // currentText(), never cached: the compose file stays the only source of
  // truth, so a reference removed by editing it must stop showing at once.
  function fileRefsSafe() {
    if (!YAML || typeof YAML.fileRefs !== 'function') return [];
    return YAML.fileRefs(currentText()) || [];
  }

  // filename -> the distinct, non-blank service names that reference it (in
  // the order fileRefs() found them). A file present in this map but with an
  // empty array is referenced only from a top-level secrets:/configs: entry,
  // which has no service to name — renderTabs() below turns that into "Used
  // by this stack" rather than an empty list.
  function fileRefMap() {
    var refs = fileRefsSafe(), out = {};
    refs.forEach(function (r) {
      var list = out[r.file] || (out[r.file] = []);
      if (r.service && list.indexOf(r.service) < 0) list.push(r.service);
    });
    return out;
  }

  /* ---- references that point at nothing (PLAN_13 phase B5) --------------
   *
   * missingRefs() -> [{file, services, where}], one entry per distinct
   * missing name — two services naming the same absent file is one thing to
   * fix, not two. Recomputed from FILES and the live compose text on every
   * call, never cached: FILES.length === 0 means the first `files` listing
   * has not landed yet, not an empty folder, so nothing is reported until it
   * has (see filesLoad()) — otherwise every stack would flash a false
   * warning the instant it opens. */
  function missingRefs() {
    if (!FILES.length) return [];

    var byName = {};
    FILES.forEach(function (f) { byName[f.name] = f; });

    var out = [], seen = {};
    fileRefsSafe().forEach(function (r) {
      var name = r.file;
      // "." and "../..." are outside this folder's business (build's own
      // "context: ." means the stack folder itself); a leading ".." is the
      // same idea one level up.
      if (name === '.' || name === '..' || name.slice(0, 3) === '../') return;

      var slash = name.indexOf('/');
      if (slash >= 0) {
        // FILES only lists one level down. A directory by that first name
        // existing is as far as this can see — reporting anything past it
        // would be a guess, and a wrong guess here is worse than no answer.
        var head = byName[name.slice(0, slash)];
        if (head && head.dir) return;
      } else if (byName[name]) {
        return;   // present, file or directory either one
      }

      var hit = seen[name];
      if (!hit) { hit = seen[name] = { file: name, services: [], where: r.where }; out.push(hit); }
      if (r.service && hit.services.indexOf(r.service) < 0) hit.services.push(r.service);
    });
    return out;
  }

  /* ---- ${VAR} placeholders nothing will ever fill in ---------------------
   *
   * Only a file named exactly ".env" is asked about here, never env_file:.
   * Compose reads ".env" itself, automatically, to fill in "${VAR}" INSIDE
   * the compose file — that is what settles whether a placeholder is
   * defined. env_file: does something else entirely: it passes values INTO
   * THE CONTAINER, with no effect on substitution in the compose file at
   * all. PLAN_13's own phase B5 line about "a value in an attached env
   * file" is wrong about this and is overridden here.
   *
   * envVars is null until envLoad() below has answered once, and simply
   * keeps its last answer while a fresh read is in flight — never blanked
   * back to null for that, or an unrelated rename or upload would flash
   * every dot off and on. null is only ever seen for the moment right after
   * a stack opens, and varDots() treats that as "do not know yet" rather
   * than "nothing is defined": a screenful of warnings that vanish a moment
   * later is worse than warnings arriving a moment late.
   */
  var envVars = null;   // null = not fetched yet; otherwise name -> true

  function envKeys() {
    return envVars;
  }

  // "export FOO=1" as well as "FOO=1" — the former is common enough in
  // hand-written .env files to be worth allowing. A "#" comment line never
  // matches this at all, since '#' is not a legal first character of NAME.
  var ENV_KEY_RE = /^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=/;

  // Called from filesLoad() and from runFileSave() after an autosave that
  // wrote ".env" — the only two moments the answer can go stale. Never
  // called from relint()/reparse(), so a keystroke in the compose pane never
  // starts a network request.
  function envLoad() {
    var f = null;
    for (var i = 0; i < FILES.length; i++) {
      var c = FILES[i];
      if (c.name === '.env' && !c.dir && !c.link && c.text) { f = c; break; }
    }
    if (!f) { envVars = {}; relint(); return; }

    var was = openedName;
    return call('file-read', { name: openedName, file: '.env' }).then(function (res) {
      if (openedName !== was) return;   // the editor moved on to a different stack
      var names = {}, m;
      if (res && res.ok && !res.binary && typeof res.text === 'string') {
        var lines = res.text.split('\n');
        for (var j = 0; j < lines.length; j++) {
          if ((m = ENV_KEY_RE.exec(lines[j]))) names[m[1]] = true;
        }
      }
      envVars = names;
      relint();
    });
  }

  // parts already reads as English ("${A}", "A and B", "A, B and C") —
  // shared by the placeholder list and the plain-name list in
  // varDotMessage() below so the two stay in the same style.
  function joinEnglish(parts) {
    if (parts.length < 2) return parts[0] || '';
    if (parts.length === 2) return parts[0] + ' and ' + parts[1];
    return parts.slice(0, -1).join(', ') + ' and ' + parts[parts.length - 1];
  }

  // One line's worth of undefined names -> the gutter dot's tooltip. Always
  // four sentences' worth: what is missing, why it fails quietly rather than
  // loudly, the two ways to fix it, and the one place this page cannot see
  // that may already be fixing it.
  function varDotMessage(names) {
    var many = names.length > 1;
    var placeholders = names.map(function (n) { return '${' + n + '}'; });
    return joinEnglish(placeholders) + (many ? ' have no values.' : ' has no value.') +
      ' Compose replaces ' + (many ? 'them' : 'it') + ' with nothing rather than complaining, ' +
      'so this usually shows up as a setting that looks ignored. Put ' + joinEnglish(names) +
      ' in this stack\'s .env file, or give ' +
      (many ? 'each a default, e.g. ${' + names[0] + ':-something}.'
            : 'it a default with ${' + names[0] + ':-something}.') +
      ' ' + (many ? 'They' : 'It') +
      ' may also be coming from this server\'s own environment, which this page cannot see.';
  }

  // Every YAML.varRefs() hit that is not already "filled" (its own default
  // or its own loud failure) and whose name is not in envKeys() — one dot
  // per LINE, not per placeholder, since two undefined names on the same
  // line are one thing to fix and the gutter has one dot's worth of room.
  //
  // [] whenever an input is still unsettled, on purpose: MODEL.ok false
  // means the file does not parse, so the linter's own error is the useful
  // message right now rather than a screen of half-typed variables; envKeys()
  // === null means the .env read has not landed (see the comment on envVars
  // above).
  function varDots() {
    if (!YAML || typeof YAML.varRefs !== 'function') return [];
    var known = envKeys();
    if (!MODEL || !MODEL.ok || known === null) return [];

    var byLine = {};
    YAML.varRefs(yamlPane.value).forEach(function (r) {
      if (r.filled || known[r.name]) return;
      var names = byLine[r.line] || (byLine[r.line] = []);
      if (names.indexOf(r.name) < 0) names.push(r.name);
    });

    var out = [];
    for (var line in byLine) {
      if (!byLine.hasOwnProperty(line)) continue;
      out.push({ line: parseInt(line, 10), level: 'warn', message: varDotMessage(byLine[line]) });
    }
    out.sort(function (a, b) { return a.line - b.line; });
    return out;
  }

  // Deliberately the plain, small version: a regular expression straight over
  // the raw text, not YAML's own variable handling — that fuller pass is not
  // ready yet (see the comment on fileRefs() in compose-model.js). Every
  // "${VAR}" with no ":-default" becomes one name, first-appearance order,
  // no duplicates — good enough for a list of blanks to fill in.
  function envVarsForPrefill(text) {
    var names = [], seen = {};
    var re = /\$\{([A-Za-z_][A-Za-z0-9_]*)([^}]*)\}/g, m;
    while ((m = re.exec(text))) {
      if (m[2].slice(0, 2) === ':-') continue;   // already has a default
      if (!seen[m[1]]) { seen[m[1]] = true; names.push(m[1]); }
    }
    return names;
  }

  // Writes `ref.file` — prefilled with its referenced variables for an
  // env_file, empty for anything else, since an empty secret or Dockerfile is
  // the most that can honestly be produced — then opens its tab. A name with
  // a "/" in it is refused outright: staxx_write_file() only ever writes a
  // direct child of the stack folder, so the folder itself has to exist on
  // the server first.
  function createMissingFile(ref) {
    var slash = ref.file.indexOf('/');
    if (slash >= 0) {
      setYamlStatus('"' + ref.file + '" is inside a folder this cannot create on its own — ' +
        'make "' + ref.file.slice(0, slash) + '" on the server first, then add the file from there.');
      return;
    }

    var isEnv = ref.where === 'env_file';
    var body = '';
    if (isEnv) {
      var names = envVarsForPrefill(currentText());
      if (names.length) {
        body = ['# Names taken from this compose file — fill in the values below.']
          .concat(names.map(function (n) { return n + '='; })).join('\n') + '\n';
      }
    }

    call('file-save', { name: openedName, file: ref.file, body: body, encoding: 'text' }).then(function (res) {
      if (!res || !res.ok) {
        setYamlStatus((res && res.error) || ('Could not create "' + ref.file + '".'));
        return;
      }
      filesLoad().then(function () {
        openFile(ref.file);
        setYamlStatus(isEnv
          ? 'Created "' + ref.file + '" — fill in its values.'
          : 'Created "' + ref.file + '" — it is empty and needs its contents.');
      });
    });
  }

  function renderTabs() {
    if (!tabsBar) return;

    var refs = fileRefMap();
    var name = tabLabel();
    var composeActive = fileOpen === null;
    var rows = [
      '<button type="button" class="staxx-tab" role="tab" data-file="" ' +
        'aria-selected="' + (composeActive ? 'true' : 'false') + '" title="' + esc(name) + '">' +
        '<span class="staxx-tab-name">' + esc(name) + '</span>' + tabDotHtml('') + '</button>' +
        (composeActive ? tabMenuBtnHtml() : '')
    ];

    for (var i = 0; i < FILES.length; i++) {
      var f = FILES[i];
      // A directory or a link is named in the delete confirmation already,
      // and neither can be opened here — staxx_read_file() refuses a
      // link outright, and a directory has no text of its own to show.
      if (f.compose || f.dir || f.link) continue;

      var active = fileOpen === f.name;
      var used   = refs[f.name];
      var cls    = 'staxx-tab';
      var title  = f.name;
      if (used) {
        title = 'Used by ' + (used.length ? used.join(', ') : 'this stack');
      } else {
        cls += ' staxx-tab--orphan';
        title = 'Nothing in the compose file uses this file.';
      }

      rows.push(
        '<button type="button" class="' + cls + '" role="tab" data-file="' + esc(f.name) + '" ' +
          'aria-selected="' + (active ? 'true' : 'false') + '" title="' + esc(title) + '">' +
          '<span class="staxx-tab-name">' + esc(f.name) + '</span>' + tabDotHtml(f.name) + '</button>' +
          (active ? tabMenuBtnHtml() : '')
      );
    }

    tabsBar.innerHTML = rows.join('');
    // Left visible even for the bare compose tab — the strip now carries the
    // New file and Add a file controls beside it (see StacksPage.php), and
    // those need somewhere to live whether or not there is a second tab yet.
    // innerHTML above just threw away whatever chevron the open menu, if
    // any, was positioned against — most callers (a click on another tab)
    // already close it via the outside-click listener below, but filesLoad()
    // can land here from a network reply with no click involved at all.
    closeTabmenu();
  }

  function filesLoad() {
    // Guarded the way envLoad() below guards, and for the same reason: this
    // reply can land after the editor has moved on to another stack, and it
    // would then draw that stack's tabs over this one's.
    var was = openedName;
    return call('files', { name: openedName }).then(function (res) {
      if (openedName !== was) return;

      // An answer we did not get is not an empty folder. Blanking the list on
      // a refusal loses the difference between "this stack has no companion
      // files" and "we never found out" — and it silences the missing-file
      // note too, since missingRefs() reads the same list and treats empty as
      // "not landed yet". The server sends a real sentence; show it.
      if (!res || !res.ok || !res.files) {
        setYamlStatus((res && res.error) ||
          'Could not list this stack\'s files. Try "Refresh file list" on the compose tab\'s menu.');
        return;
      }

      FILES = res.files;
      renderTabs();
      // The set of files just changed without the compose text moving at
      // all — a reference that was missing a moment ago may not be now, or
      // vice versa after a delete — so the note is refreshed here too, not
      // only from reparse().
      updateMissing();
      // Same reasoning for .env: an upload, a rename or a delete can add or
      // remove it without the compose text moving either.
      envLoad();
      // includesHtml() decides whether each include: gets an Open button by
      // looking this list up, and it renders before this reply lands — so
      // without a redraw here every include reads "cannot be opened here"
      // even when the file is sitting right beside the compose file. Only
      // the include view needs it: every other form reads FILES nowhere.
      if (MODEL && MODEL.includes && MODEL.includes.length && !MODEL.services.length) {
        formHost.innerHTML = includesHtml(MODEL);
      }
    }).catch(function (e) {
      // renderTabs() reads the compose text through YAML.fileRefs(), which can
      // throw on a file it cannot walk. Left to the bare .catch its callers
      // carry, that produced a strip with no tabs and no explanation — exactly
      // what a stack with no companion files looks like.
      setYamlStatus('The file list could not be drawn — ' + ((e && e.message) || e) +
        '. Try "Refresh file list" on the compose tab\'s menu.');
    });
  }

  function fileTabEl(name) {
    if (!tabsBar) return null;
    var btns = tabsBar.querySelectorAll('.staxx-tab');
    for (var i = 0; i < btns.length; i++) {
      if (btns[i].dataset.file === name) return btns[i];
    }
    return null;
  }

  // The dot lives on the tab, not the strip, precisely so it is still visible
  // from whatever other tab you move on to — recorded in fileDots first, so
  // a later renderTabs() (switching tabs again rebuilds the whole strip)
  // draws it back rather than losing it.
  function setTabDot(name, state) {
    if (state === 'hidden') delete fileDots[name]; else fileDots[name] = state;
    var btn = fileTabEl(name);
    var dot = btn && btn.querySelector('.staxx-tab-dot');
    if (!dot) return;
    dot.hidden = state === 'hidden';
    dot.classList.toggle('staxx-tab-dot--bad', state === 'bad');
  }

  var fileSaveTimer = null;

  function runFileSave() {
    if (fileOpen === null) return Promise.resolve();
    // See loadCompanion(): only a file whose text actually loaded may be
    // written back. A binary, or a read that failed, leaves an empty box that
    // must never reach the disk.
    if (!fileEditable) return Promise.resolve();
    var file = fileOpen, body = yamlPane.value;
    // Nothing typed since the file was loaded or last saved, so there is
    // nothing to write. Worth the check rather than writing anyway: this is
    // called on every tab switch and on closing the editor, and stacks live
    // on the flash drive by default, which has a finite number of writes.
    if (body === fileAtLoad) return Promise.resolve();
    // fileAtLoad above and fileAtLoad below both stay LF, since that is the
    // box's own form — only the posted body gets the file's ending back.
    return call('file-save', { name: openedName, file: file, body: withEol(body, fileEol), encoding: 'text' })
      .then(function (res) {
        if (!res || !res.ok) {
          // Left ON, deliberately — the file on disk is now out of step with
          // what is on screen, and that has to stay visible from any tab.
          setTabDot(file, 'bad');
          if (fileOpen === file) setYamlStatus((res && res.error) || ('Could not save "' + file + '".'));
          return;
        }
        // The saved text becomes the new baseline — not yamlPane.value, which
        // may have moved on while the request was in flight and would then
        // count typing that has not been written as already saved.
        if (fileOpen === file) fileAtLoad = body;
        setTabDot(file, 'hidden');
        if (fileOpen === file) setYamlStatus('Saved');
        // The other moment envKeys() can go stale — see envLoad()'s comment.
        // filesLoad() is not involved in an autosave, so nothing else would
        // notice this write.
        if (file === '.env') envLoad();
      });
  }

  // Cancels the debounce and sends at once, so a tab switch or the editor
  // closing can wait for a pending edit to land before the box moves on.
  function flushFileSave() {
    if (fileSaveTimer) { clearTimeout(fileSaveTimer); fileSaveTimer = null; }
    return runFileSave();
  }

  yamlPane.addEventListener('input', function () {
    if (fileOpen === null) return;
    setTabDot(fileOpen, 'pending');
    if (fileSaveTimer) clearTimeout(fileSaveTimer);
    fileSaveTimer = setTimeout(function () { fileSaveTimer = null; runFileSave(); }, 1000);
  });

  // Two titles, used from both directions so they cannot say different things
  // depending on which way you crossed. FORM_GATE_TITLE is the Form button's
  // alone — Split stays available on a companion tab, and setFormGate() knows
  // to leave Form gated while one is open.
  var FORM_GATE_TITLE = 'This file is edited in the Compose pane — choose Split to see the compose form beside it.';
  var FILE_TAB_TITLE  = 'The compose file is on the first tab.';
  var sanitiseTitleWas = sanitiseBox.title;
  // The box's own placeholder is an example compose file. Held aside so a
  // companion tab can drop it: on an empty new file it reads as though the
  // file already held somebody else's YAML.
  var yamlPlaceholder = yamlPane.placeholder;

  // The compose form stays on screen beside a companion file, but it edits the
  // compose file and nothing else — so while one is open it is there to read.
  // Text boxes go readOnly rather than disabled because a disabled box's value
  // cannot be selected, and copying a value out of the form is most of the
  // reason for showing it at all. Tick boxes and dropdowns have no read-only
  // state, so those and the buttons are disabled outright.
  //
  // One direction only: the way back to the compose file re-renders the form
  // from scratch, which unlocks it without keeping any record of what was
  // already off — the fiddly half of the same job in setSanitised().
  function lockForm() {
    var els = formHost.querySelectorAll('input, textarea, select, button');
    for (var i = 0; i < els.length; i++) {
      var el = els[i];
      if (el.tagName === 'TEXTAREA' ||
          (el.tagName === 'INPUT' && el.type !== 'checkbox' && el.type !== 'radio')) {
        el.readOnly = true;
      } else {
        el.disabled = true;
      }
    }
  }

  // Disables (on) or restores (off) everything that only ever acts on the
  // compose file. One function for both directions, called from openFile()
  // each way, so they cannot drift apart.
  function fileChrome(on) {
    var formBtn = modal.querySelector('.staxx-viewbtn[data-view="form"]');
    if (formBtn) { formBtn.disabled = on; formBtn.title = on ? FORM_GATE_TITLE : ''; }
    // Split is deliberately left alone. The compose form beside a companion
    // file is what says which service reads that file and what it has to
    // define, which is worth more than the pane it costs.
    if (on) lockForm();

    if (refNote) {
      refNote.textContent = on
        ? 'Showing the compose file for reference while "' + fileOpen +
          '" is open. Switch to its tab to make changes.'
        : '';
      refNote.hidden = !on;
    }

    yamlPane.placeholder = on ? '' : yamlPlaceholder;

    saveBtn.disabled  = on || sanitised;
    saveBtn.title     = on ? FILE_TAB_TITLE : '';
    // Save and start carries its own server-side gate (compose or Docker
    // missing) — restored here rather than simply switched back on.
    startBtn.disabled = on || sanitised || startBtnWasDisabled;
    startBtn.title    = on ? FILE_TAB_TITLE : '';
    sanitiseBox.disabled = on;
    sanitiseBox.title    = on ? FILE_TAB_TITLE : sanitiseTitleWas;
    undoBtn.title = on ? FILE_TAB_TITLE : '';
    if (on) undoBtn.disabled = true; else updateUndo();   // updateUndo() knows the real stack, not just "on"
  }

  // filename -> its size, read off the last `files` listing — the only
  // place the server tells the browser how big a companion is.
  function binFileSize(name) {
    for (var i = 0; i < FILES.length; i++) {
      if (FILES[i].name === name) return FILES[i].size;
    }
    return null;
  }

  // Certificates and key files are the reason a binary file is accepted at
  // all — this is what stands in for an editor it cannot usefully offer one.
  //
  // bytes() is the stats table's formatter, declared further down and reached
  // by hoisting. Every size on this page reads the same way because there is
  // one of it; a second formatter here would quietly disagree with the delete
  // confirmation's list about how big the same file is.
  function showBinPanel(name) {
    if (!binPanel) return;
    binName.textContent = name;
    var n = binFileSize(name);
    var size = n == null ? '' : bytes(n);
    var type = fileMime[name];
    binMeta.textContent = type ? (size + ' — ' + type) : size;
    binPanel.hidden = false;
  }

  function hideBinPanel() {
    if (binPanel) binPanel.hidden = true;
  }

  // Reads a companion file and puts it on screen — pulled out of openFile()
  // so the Replace… flow (further down) can redraw a tab without it, since a
  // replacement can turn a binary file into a text one or back and both have
  // to redraw exactly as a fresh open would.
  function loadCompanion(name) {
    // Nothing may be written back until a read has actually succeeded and
    // handed us the file's own text. Without this the empty box shown for a
    // binary — or for a file that failed to load at all — counts as an edit,
    // and switching tabs saves that emptiness over the real file. It did:
    // opening a certificate's tab and clicking away truncated it to nothing.
    fileEditable = false;
    return call('file-read', { name: openedName, file: name }).then(function (res) {
      if (fileOpen !== name) return;   // the tab moved on again before this answered
      if (!res || !res.ok) {
        setYamlStatus((res && res.error) || ('Could not read "' + name + '".'));
        return;
      }
      if (res.binary) {
        yamlPane.readOnly = true;
        yamlPane.value = '';
        showBinPanel(name);
        return;
      }
      hideBinPanel();
      yamlPane.readOnly = false;
      // fileEol remembers the ending for runFileSave() to put back. fileAtLoad
      // is read from the box, not from res.text — the box has already
      // normalised CRLF to LF, and if the baseline did not match that an
      // untouched file would look edited and write itself straight back.
      fileEol = res.text.indexOf('\r\n') >= 0 ? '\r\n' : '\n';
      yamlPane.value = res.text;
      fileAtLoad = yamlPane.value;
      fileEditable = true;
      paintGutter();
      paintInk();
      syncGutter();
    });
  }

  // name is '' for the compose file, or a companion's filename.
  function openFile(name) {
    name = name || '';
    if (name === (fileOpen || '')) return Promise.resolve();   // already the tab on screen

    // A form edit still on its timer belongs to the compose file, and this is
    // the last moment the box is still showing it — the same reason the folder
    // picker flushes before it opens.
    flushPending();

    // Returned (nothing used to) so the env-file offer below can wait for
    // the switch back to the compose tab to actually land before it writes
    // to MODEL.doc — this and that are the only callers that need to know.
    return flushFileSave().then(function () {
      if (name === '') {
        // Back to the compose tab: hand the box the real text and the view
        // back, and let reparse() rebuild everything that depends on it.
        fileOpen = null;
        hideBinPanel();   // easy to forget — the panel covers the box, not just a companion tab's own text
        yamlPane.readOnly = false;
        yamlPane.value = fileStash;
        fileChrome(false);
        setView(viewBeforeFile || defaultView());
        paintGutter();
        paintInk();
        syncGutter();
        reparse();
        renderTabs();
        return;
      }

      if (fileOpen === null) {
        // Leaving the compose tab. Sanitise redacts the compose file only —
        // turned off through its own path (setSanitised()) so every control
        // it disables comes back in step, not just the box's own text.
        if (sanitised) { sanitiseBox.checked = false; setSanitised(false); }
        fileStash = yamlPane.value;
        viewBeforeFile = modalBody.dataset.view;
      }

      fileOpen = name;
      hideBinPanel();   // last tab's panel, if any, would otherwise sit over this one while the read is in flight
      fileChrome(true);
      setView(modalBody.dataset.view);   // setView() coerces Form to Split; any other view is kept
      closeOutline();   // yesterday's — this stack's own compose file's — structure means nothing here
      renderTabs();

      loadCompanion(name);
    });
  }

  if (tabsBar) {
    tabsBar.addEventListener('click', function (event) {
      var menuBtn = event.target.closest('.staxx-tab-menubtn');
      if (menuBtn) {
        // Stopped here, same reasoning the Outline button gives for its own
        // click: left to bubble, the document-level "click outside"
        // listener below would see this same click and close what it just
        // opened.
        event.stopPropagation();
        if (tabmenuOpen()) closeTabmenu(); else openTabmenu(menuBtn);
        return;
      }
      var btn = event.target.closest('.staxx-tab');
      if (btn) openFile(btn.dataset.file || '');
    });
  }

  // The Open button on an include: block (includesHtml() above) — switches
  // to the file's own tab through the same path a click on that tab already
  // takes, rather than a second way to open one.
  formHost.addEventListener('click', function (event) {
    var openBtn = event.target.closest('[data-open-file]');
    if (openBtn) openFile(openBtn.dataset.openFile);
  });

  /* ---- the active tab's menu: Rename, Delete, Download -----------------
   *
   * One menu, for whichever tab is active — a menu on a tab you are not
   * looking at would act on a file you cannot see. Modelled on the Outline
   * button and #staxx-outline (openOutline()/closeOutline() above), not
   * on #staxx-menu: that one lives outside this <dialog>, and a dialog
   * opened with showModal() paints in the top layer above anything outside
   * it, so it would be invisible here regardless of z-index.
   */

  function tabMenuBtnHtml() {
    return '<button type="button" class="staxx-chevron staxx-tab-menubtn" ' +
           'aria-haspopup="menu" aria-expanded="false" title="' + esc('File options') + '">' +
           '<i class="fa fa-chevron-down" aria-hidden="true"></i></button>';
  }

  function tabmenuOpen() {
    return !!(tabmenuPanel && !tabmenuPanel.hidden);
  }

  function closeTabmenu() {
    if (!tabmenuOpen()) return;
    tabmenuPanel.hidden = true;
    tabmenuPanel.innerHTML = '';
    var btn = tabsBar && tabsBar.querySelector('.staxx-tab-menubtn');
    if (btn) btn.setAttribute('aria-expanded', 'false');
  }

  // The compose tab's own menu offers Download only — renaming it would
  // change which file compose runs, and deleting it deletes the stack, so
  // both are said plainly rather than shown as two items nobody can use.
  function tabmenuItemsHtml() {
    if (fileOpen === null) {
      return '<div class="staxx-tabmenu-note">Renaming or deleting this file would change ' +
             'which file compose runs, or delete the stack — do either from the stack list ' +
             'instead.</div>' +
             // Re-reads the folder. The listing is otherwise fetched once, when
             // the editor opens, so this is the way back from a listing that
             // failed or from a file put there by something other than this page.
             '<div class="staxx-tabmenu-row" role="menuitem" data-act="refresh">Refresh file list</div>' +
             '<div class="staxx-tabmenu-row" role="menuitem" data-act="download">Download</div>';
    }
    return '<div class="staxx-tabmenu-row" role="menuitem" data-act="rename">Rename</div>' +
           '<div class="staxx-tabmenu-row" role="menuitem" data-act="delete">Delete</div>' +
           '<div class="staxx-tabmenu-row" role="menuitem" data-act="download">Download</div>';
  }

  // Positioned in pixels against the chevron that opened it, the same
  // technique placeCaretPanel() (further down) uses to follow the caret —
  // the active tab can be anywhere along a strip that scrolls sideways, so
  // there is nothing fixed here to hang a CSS-only position off, unlike
  // #staxx-outline's own button.
  function openTabmenu(btn) {
    if (!tabmenuPanel) return;
    tabmenuPanel.innerHTML = tabmenuItemsHtml();
    tabmenuPanel.hidden = false;
    btn.setAttribute('aria-expanded', 'true');

    var pane = tabmenuPanel.parentElement;
    var paneRect = pane.getBoundingClientRect();
    var btnRect  = btn.getBoundingClientRect();
    var left = btnRect.left - paneRect.left;
    var maxLeft = pane.clientWidth - tabmenuPanel.offsetWidth;
    tabmenuPanel.style.left = Math.max(0, Math.min(left, maxLeft)) + 'px';
    tabmenuPanel.style.top  = (btnRect.bottom - paneRect.top) + 'px';
  }

  if (tabmenuPanel) {
    tabmenuPanel.addEventListener('click', function (event) {
      var row = event.target.closest('.staxx-tabmenu-row');
      if (!row) return;
      var act = row.dataset.act;
      closeTabmenu();
      if (act === 'rename') renameFile();
      else if (act === 'delete') deleteFile();
      else if (act === 'download') downloadFile();
      else if (act === 'refresh') filesLoad();
    });
  }

  // A file can arrive in the folder from a terminal, a share, or another
  // browser tab, and the listing is otherwise only fetched when the editor
  // opens. Coming back to the window is the moment to catch up — cheap, since
  // this is one directory read, and skipped entirely while the editor is shut.
  var filesFocusTimer = null;
  window.addEventListener('focus', function () {
    if (!modal.open || modal.dataset.new === '1') return;
    if (filesFocusTimer) return;   // several focus events can arrive together
    filesFocusTimer = setTimeout(function () {
      filesFocusTimer = null;
      if (modal.open && modal.dataset.new !== '1') filesLoad();
    }, 400);
  });

  document.addEventListener('click', function (event) {
    if (!modal.open || !tabmenuOpen()) return;
    if (event.target.closest('.staxx-tabmenu') || event.target.closest('.staxx-tab-menubtn')) return;
    closeTabmenu();
  });
  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape' || !modal.open || !tabmenuOpen()) return;
    // preventDefault here, not left to the dialog's own Escape-closes-me
    // action, is the same trick the outline panel relies on — it is what
    // keeps this closing only the tab menu rather than the whole editor.
    event.preventDefault();
    closeTabmenu();
  });

  // The server's own rules (staxx_valid_filename() and STAXX_FILE_MAX
  // in Stacks.php), mirrored here so an obviously bad name or an oversized
  // file is refused before a round trip — not so the server-side check can
  // be skipped.
  var FILENAME_RE = /^\.?[A-Za-z0-9][A-Za-z0-9._-]*$/;
  var COMPOSE_FILENAMES = ['compose.yaml', 'compose.yml', 'docker-compose.yaml', 'docker-compose.yml'];
  var STAXX_FILE_MAX = 262144;   // 256 KB

  function validFilename(file) {
    if (!file || file.length > 63) return false;
    if (file.indexOf('..') !== -1 || file.indexOf('/') !== -1) return false;
    if (!FILENAME_RE.test(file)) return false;
    return COMPOSE_FILENAMES.indexOf(file.toLowerCase()) === -1;
  }

  // window.prompt, not a dialog — this file already uses window.prompt and
  // window.confirm for one-line questions, and a dialog would be more
  // furniture than "what should this be called?" needs.
  function renameFile() {
    var name = fileOpen;
    if (name === null) return;   // the compose tab has no Rename item

    var to = window.prompt('Rename "' + name + '" to:', name);
    if (to === null) return;   // cancelled
    to = to.trim();
    if (to === name || to === '') return;

    if (!validFilename(to)) {
      setYamlStatus('File names may contain letters, numbers, dots, dashes and underscores, ' +
                     'must be 63 characters or fewer, and may not be a compose file.');
      return;
    }

    call('file-rename', { name: openedName, file: name, to: to }).then(function (res) {
      if (!res || !res.ok) {
        setYamlStatus((res && res.error) || ('Could not rename "' + name + '".'));
        return;
      }
      // The pending-autosave dot, if any, follows the file to its new name —
      // otherwise it would keep showing under a filename no tab has any more.
      if (fileDots[name] !== undefined) { fileDots[to] = fileDots[name]; delete fileDots[name]; }
      // fileOpen has to end up holding the new name, or the next autosave
      // (runFileSave() keys off it) would write to a file that no longer
      // exists.
      if (fileOpen === name) fileOpen = to;
      filesLoad();
    });
  }

  // Deletes the file on the active tab. A file nothing references only
  // needs one sentence, so window.confirm() is enough for that case — the
  // same reasoning openFile()'s comments give for using window.prompt above.
  // A file the compose file DOES reference needs more than one sentence, so
  // that case goes through #staxx-confirm via askConfirm() instead.
  function deleteFile() {
    var name = fileOpen;
    if (name === null) return;   // the compose tab has no Delete item

    function go() {
      // Whatever autosave was pending is moot the moment the file is gone —
      // cleared rather than flushed, or the next tick would recreate the
      // file it was just asked to delete.
      if (fileSaveTimer) { clearTimeout(fileSaveTimer); fileSaveTimer = null; }
      call('file-delete', { name: openedName, file: name }).then(function (res) {
        if (!res || !res.ok) {
          setYamlStatus((res && res.error) || ('Could not delete "' + name + '".'));
          return;
        }
        if (fileOpen === name) {
          fileAtLoad = yamlPane.value;   // nothing left to autosave — mark it clean
          openFile('');
        }
        filesLoad();
      });
    }

    var users = fileRefMap()[name];
    if (!users) {
      if (window.confirm('Delete "' + name + '"? This cannot be undone.')) go();
      return;
    }

    var who = users.length ? users.join(', ') : 'this stack';
    askConfirm({
      title: 'Delete "' + name + '"?',
      bodyHtml:
        '<p>The compose file uses "' + esc(name) + '" — ' + esc(who) + ' will stop working ' +
        'until it is put back or the reference is removed.</p>' +
        '<p>This only removes "' + esc(name) + '" itself; the compose file is left exactly as ' +
        'it is.</p>',
      goLabel: 'Delete file'
    }).then(function (goAhead) {
      closeConfirm();
      if (goAhead) go();
    });
  }

  function base64ToBytes(b64) {
    var bin = atob(b64), out = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
    return out;
  }

  // data is a string (text) or a Uint8Array (binary) — either is a valid
  // Blob part. The object URL is revoked in a finally, or every download
  // leaks the whole file until the page is closed.
  function triggerDownload(name, data) {
    var url = URL.createObjectURL(new Blob([data]));
    try {
      var a = document.createElement('a');
      a.href = url;
      a.download = name;
      document.body.appendChild(a);
      a.click();
      a.remove();
    } finally {
      URL.revokeObjectURL(url);
    }
  }

  // The compose file's own text is already on screen, so that tab downloads
  // it with no round trip. A companion file is not necessarily loaded —
  // read() it fresh, so Download works for a file that is not the one open.
  function downloadFile() {
    if (fileOpen === null) { triggerDownload(tabLabel(), currentText()); return; }

    var name = fileOpen;
    call('file-read', { name: openedName, file: name }).then(function (res) {
      if (!res || !res.ok) {
        setYamlStatus((res && res.error) || ('Could not read "' + name + '".'));
        return;
      }
      triggerDownload(name, res.binary ? base64ToBytes(res.b64) : res.text);
    });
  }

  /* ---- uploading and creating files ---------------------------------------
   *
   * Deliberately not a file upload — call()'s own comment (above, near
   * where it builds URLSearchParams) explains why FormData is off the table
   * on this server. The browser reads the dropped or picked file itself with
   * FileReader, and the bytes go up through call() exactly like any other
   * field: text as text, binary as base64. */

  // Matches staxx_looks_text() in Stacks.php — the first 8 KB with no NUL
  // byte is text — so a file this decides is text can never come back from
  // the server reading as binary, or vice versa.
  function looksText(bytes) {
    var n = Math.min(bytes.length, 8192);
    for (var i = 0; i < n; i++) if (bytes[i] === 0) return false;
    return true;
  }

  // btoa() takes a string. String.fromCharCode.apply(null, bytes) blows the
  // argument limit and throws once bytes gets into the tens of thousands, so
  // the string is built a chunk at a time instead.
  function bytesToBase64(bytes) {
    var CHUNK = 8192, parts = [];
    for (var i = 0; i < bytes.length; i += CHUNK) {
      parts.push(String.fromCharCode.apply(null, bytes.subarray(i, i + CHUNK)));
    }
    return btoa(parts.join(''));
  }

  // Resolves to {name, body, encoding} or rejects with a sentence naming the
  // file and the reason. The size is checked off the File object itself,
  // before anything is read — a multi-gigabyte drop fails at once rather
  // than after it has all been pulled into memory.
  function readUpload(file) {
    if (file.size > STAXX_FILE_MAX) {
      return Promise.reject('"' + file.name + '" is ' + bytes(file.size) +
        ', over the 256 KiB limit for a file kept alongside a stack.');
    }
    return new Promise(function (resolve, reject) {
      var reader = new FileReader();
      reader.onerror = function () { reject('"' + file.name + '" could not be read from this computer.'); };
      reader.onload = function () {
        var bytes = new Uint8Array(reader.result);
        if (looksText(bytes)) {
          resolve({ name: file.name, body: new TextDecoder('utf-8').decode(bytes), encoding: 'text' });
        } else {
          resolve({ name: file.name, body: bytesToBase64(bytes), encoding: 'base64' });
        }
      };
      reader.readAsArrayBuffer(file);
    });
  }

  // Saves body/encoding under `name`, asking first if that would replace a
  // file already in the folder. Resolves to the name once it has landed, or
  // to null if the overwrite was declined — never rejects for that, since a
  // decline is not a failure a batch upload needs to report.
  function saveUpload(name, body, encoding) {
    if (!validFilename(name)) {
      return Promise.reject('"' + name + '" is not a name this can use — file names may contain ' +
        'letters, numbers, dots, dashes and underscores, must be 63 characters or fewer, and may ' +
        'not be a compose file.');
    }
    var exists = FILES.some(function (f) { return f.name === name && !f.dir; });
    if (exists && !window.confirm('"' + name + '" is already in this folder. Replace it?')) {
      return Promise.resolve(null);
    }
    return call('file-save', { name: openedName, file: name, body: body, encoding: encoding })
      .then(function (res) {
        if (!res || !res.ok) return Promise.reject((res && res.error) || ('Could not save "' + name + '".'));
        return name;
      });
  }

  /* ---- offering to wire a freshly added settings file in ---------------
   *
   * Only ever offered right after an add — never a Replace (the file kept
   * whatever wiring it already had) and never a rename. uploadFiles() and
   * newFile() below each know, at the point they call in, whether what just
   * landed is genuinely new. */

  var ENV_LINE_RE = /^[A-Za-z_][A-Za-z0-9_]*=/;

  // By name first — unambiguous, and needs no peek at the content — and only
  // then by sniffing lines for KEY=value pairs, since a couple of those turn
  // up by coincidence in all sorts of files that are not settings at all.
  function looksEnvFile(name, text) {
    if (name === '.env' || name.slice(0, 5) === '.env.' || name.slice(-4) === '.env') return true;

    var lines = String(text == null ? '' : text).split('\n');
    var total = 0, hits = 0;
    for (var i = 0; i < lines.length; i++) {
      var line = lines[i].trim();
      if (line === '' || line.charAt(0) === '#') continue;
      total++;
      if (ENV_LINE_RE.test(line)) hits++;
    }
    return total >= 2 && hits / total >= 0.8;
  }

  // Which services already read `name` as an env_file, keyed by service
  // name — used both to grey those rows out in the picker below and to skip
  // the offer altogether when every service already has it.
  function envFileServices(name) {
    var out = {};
    fileRefsSafe().forEach(function (r) {
      if (r.file === name && r.where === 'env_file' && r.service) out[r.service] = true;
    });
    return out;
  }

  // Two extra paragraphs first for a file named exactly ".env" — Compose
  // already reads that name on its own, with no env_file: at all, and
  // confusing the two is the single most common mistake with settings files.
  function envPickBodyHtml(name, services, already) {
    var rows = services.map(function (svc) {
      var got = !!already[svc.name];
      return '<li><label><input type="checkbox" data-service="' + esc(svc.name) + '" checked' +
             (got ? ' disabled' : '') + '> ' + esc(svc.name) +
             (got ? ' <span class="staxx-envpick-already">(already)</span>' : '') +
             '</label></li>';
    }).join('');

    var intro = '<p>This looks like a list of settings. Tick the services that should read it.</p>';
    if (name === '.env') {
      intro = '<p>A file named exactly ".env" is already special to Compose: it is read on its ' +
        'own to fill in <code>${...}</code> placeholders inside the compose file, with no ' +
        '<code>env_file:</code> entry at all. That happens whether or not anything below is ' +
        'ticked.</p>' +
        '<p><code>env_file:</code> is a different thing — it passes these settings into the ' +
        'containers themselves. Both are useful, and the two get confused constantly.</p>' + intro;
    }
    return intro + '<ul class="staxx-confirm-list staxx-envpick">' + rows + '</ul>';
  }

  // Asks, then wires `name` in as an env_file for whichever services were
  // ticked — see YAML.addItem's own call site in formHost's click handler
  // above for the add/undo/repaint pattern this follows.
  function envPickAndWire(name, body) {
    if (!looksEnvFile(name, body)) return Promise.resolve();
    if (!MODEL || !MODEL.ok) return Promise.resolve();   // nothing safe to edit
    var services = MODEL.services || [];
    if (!services.length) return Promise.resolve();

    var already = envFileServices(name);
    if (services.every(function (svc) { return already[svc.name]; })) return Promise.resolve();

    return askConfirm({
      title: 'Use "' + name + '" in this stack?',
      bodyHtml: envPickBodyHtml(name, services, already),
      goLabel: 'Add to compose file'
    }).then(function (goAhead) {
      // Read the ticks before anything else, while the dialog's own body is
      // still the one this askConfirm() call filled in — openFile('') just
      // below hands the box to a different tab, and nothing past that point
      // is a safe place left to still be reading this markup from.
      var picked = [];
      if (goAhead) {
        confirmBody.querySelectorAll('input[data-service]').forEach(function (box) {
          if (box.checked && !box.disabled) picked.push(box.dataset.service);
        });
      }
      closeConfirm();
      if (!picked.length) return;

      // The tab on screen right now is the settings file that just landed,
      // not the compose file — MODEL.doc is still the compose document
      // underneath it. Writing the edit out without switching back first
      // would serialise the compose file's own YAML into the settings
      // file's tab and corrupt it.
      return openFile('').then(function () {
        flushPending();
        pushUndo('adding "' + name + '" as an env_file');
        var lastLine = -1, failed = [];
        picked.forEach(function (svc) {
          var line = YAML.addItem(MODEL.doc, MODEL, svc, 'list', name, 'env_file');
          if (line < 0) failed.push(svc); else lastLine = line;
        });
        // Nothing changed, so there is nothing to undo — matches what the
        // single-item Add button does on its own -1.
        if (lastLine < 0) { undoStack.pop(); updateUndo(); }
        structuralEdit(lastLine, null);
        renderTabs();   // the file just stopped being an orphan, on however many services landed

        var added = picked.length - failed.length, parts = [];
        if (added) {
          parts.push('Added "' + name + '" as an env_file for ' + added +
                      ' service' + (added === 1 ? '' : 's') + '.');
        }
        if (failed.length) {
          parts.push('Could not add it for ' + failed.join(', ') + ' — that env_file is written ' +
                      'in a way the form cannot add to; use the Compose view instead.');
        }
        setYamlStatus(parts.join(' '));
      });
    });
  }

  // Sends a batch one at a time, not all at once, so a failure names the
  // file it belongs to rather than racing several requests against each
  // other. Ends by opening the last file that landed, and reporting through
  // setYamlStatus() what landed plus the first refusal, with a count of any
  // others.
  function uploadFiles(fileList) {
    var files = Array.prototype.slice.call(fileList);
    var landed = [], lastName = null, firstError = null, errorCount = 0;

    // Snapshotted once, before any of this batch lands, so a file replaced
    // partway through a multi-file drop is never mistaken for a fresh add —
    // the settings-file offer below is only ever about one that has just
    // arrived, not one that already had a place (and wiring) in the folder.
    var hadAlready = {};
    FILES.forEach(function (f) { if (!f.dir) hadAlready[f.name] = true; });
    var freshAdds = [];

    return files.reduce(function (chain, file) {
      return chain
        .then(function () { return readUpload(file); })
        .then(function (r) {
          return saveUpload(r.name, r.body, r.encoding).then(function (name) {
            if (!name) return;
            landed.push(name);
            lastName = name;
            // Binaries never look like settings, so only text is worth
            // carrying the body along for — see looksEnvFile()'s own note.
            if (!hadAlready[name] && r.encoding === 'text') freshAdds.push({ name: name, body: r.body });
          });
        })
        .catch(function (msg) { if (firstError === null) firstError = msg; else errorCount++; });
    }, Promise.resolve()).then(function () {
      return filesLoad().then(function () {
        if (lastName) openFile(lastName);
        var parts = [];
        if (landed.length) {
          parts.push(landed.length + ' file' + (landed.length === 1 ? '' : 's') + ' added.');
        }
        if (firstError) {
          parts.push(firstError + (errorCount ? ' (' + errorCount + ' more failed.)' : ''));
        }
        setYamlStatus(parts.join(' ') || 'Nothing to add.');

        // One at a time, in order — several of these dialogs stacked on top
        // of each other would be worse than a short queue of them.
        return freshAdds.reduce(function (chain, f) {
          return chain.then(function () { return envPickAndWire(f.name, f.body); });
        }, Promise.resolve());
      });
    });
  }

  if (fileAddBtn && fileInput) {
    fileAddBtn.addEventListener('click', function () { fileInput.click(); });
  }

  // window.prompt, same reasoning renameFile() gives above for using it over
  // a dialog — "what should this be called?" is one line, not a form.
  function newFile() {
    var name = window.prompt('New file name:');
    if (name === null) return;   // cancelled
    name = name.trim();
    if (!name) return;

    var existed = FILES.some(function (f) { return f.name === name && !f.dir; });
    saveUpload(name, '', 'text').then(function (landed) {
      if (!landed) return;
      return filesLoad().then(function () {
        openFile(landed);
        if (!existed) return envPickAndWire(landed, '');
      });
    }).catch(function (msg) { setYamlStatus(msg); });
  }

  if (fileNewBtn) fileNewBtn.addEventListener('click', newFile);

  // The picker driving both Add a file (multiple) and Replace… on the binary
  // panel (one file, standing in for whatever is already on the active tab —
  // set just below). Cleared afterwards regardless of outcome, or picking
  // the same file twice in a row does nothing the second time.
  if (fileInput) {
    fileInput.addEventListener('change', function () {
      var picked = fileInput.files;
      var target = fileReplaceTarget;
      fileReplaceTarget = null;
      fileInput.multiple = true;   // Replace… below turns this off for one pick only

      if (picked && picked.length) {
        if (target !== null) {
          var chosen = picked[0];
          readUpload(chosen).then(function (r) {
            fileMime[target] = chosen.type || null;
            return call('file-save', { name: openedName, file: target, body: r.body, encoding: r.encoding });
          }).then(function (res) {
            if (!res || !res.ok) throw (res && res.error) || ('Could not save "' + target + '".');
            return filesLoad().then(function () {
              // A replacement can turn a binary file into a text one or
              // back — loadCompanion() redraws the tab either way, exactly
              // as opening it fresh would.
              if (fileOpen === target) loadCompanion(target);
              setYamlStatus('Replaced "' + target + '".');
            });
          }).catch(function (msg) { setYamlStatus(String(msg)); });
        } else {
          uploadFiles(picked);
        }
      }
      fileInput.value = '';
    });
  }

  if (binPut) {
    // Replace… keeps the name already on the tab, whatever the chosen file
    // is called — a certificate gets renewed and stays the file every
    // service already points at, rather than landing under a new name that
    // nothing references.
    binPut.addEventListener('click', function () {
      if (fileOpen === null || !fileInput) return;
      fileReplaceTarget = fileOpen;
      fileInput.multiple = false;
      fileInput.click();
    });
  }

  if (binGet) binGet.addEventListener('click', downloadFile);

  // Dropping is anywhere on the editor, not just the strip — listened for on
  // the modal body, which covers both panes. preventDefault() on dragenter
  // AND dragover: without both the browser treats the drop as opening the
  // file in the tab instead of handing it to this listener, and a drop
  // inside the textarea itself would insert the file's path as text.
  if (modalBody) {
    modalBody.addEventListener('dragenter', function (event) {
      event.preventDefault();
      modalBody.classList.add('staxx-dragover');
    });
    modalBody.addEventListener('dragover', function (event) { event.preventDefault(); });
    modalBody.addEventListener('dragleave', function () {
      modalBody.classList.remove('staxx-dragover');
    });
    modalBody.addEventListener('drop', function (event) {
      event.preventDefault();
      modalBody.classList.remove('staxx-dragover');
      if (event.dataTransfer && event.dataTransfer.files.length) uploadFiles(event.dataTransfer.files);
    });
  }

  // Replaces one range with text via execCommand, falling back to setRangeText
  // — the same pairing every call site here uses, because execCommand is what
  // keeps the browser's own undo stack working. assigning .value wipes it, and
  // an editor that forgets Ctrl+Z is worse than one with no smart keys.
  function replaceRange(from, to, text) {
    yamlPane.setSelectionRange(from, to);
    if (!document.execCommand('insertText', false, text)) {
      yamlPane.setRangeText(text, from, to, 'end');
    }
  }

  // Two spaces, never a real tab character: YAML forbids tabs in indentation,
  // and a file that has one is sealed whole by the parser — the form would
  // silently lose the ability to edit any of it.
  function indentBlock(text) {
    return text.split('\n').map(function (l) { return '  ' + l; }).join('\n');
  }

  function outdentBlock(text) {
    return text.split('\n').map(function (l) {
      var lead = /^ {1,2}/.exec(l);
      return lead ? l.slice(lead[0].length) : l;   // already at column 0: leave it
    }).join('\n');
  }

  // The full lines a selection touches, so Tab/Shift-Tab indent whole lines
  // even when the drag only grazes the first or last one. A selection ending
  // exactly at another line's column 0 does not pull that line in — nothing of
  // it was actually selected.
  function selectedLines(text, start, end) {
    var lineStart = text.lastIndexOf('\n', start - 1) + 1;
    var stop = (end > start && text.charAt(end - 1) === '\n') ? end - 1 : end;
    var nl = text.indexOf('\n', stop);
    return { start: lineStart, end: nl === -1 ? text.length : nl };
  }

  /* ---- key suggestions & hover help ---------------------------------------
   *
   * Two panels riding on top of the compose pane, both positioned from the
   * same LINE_H/PAD_T/CHAR_W measurements the search-hit boxes already use
   * (see measure()) — there is no browser API for a textarea's own caret
   * pixel position, so this is the only way to place anything against it.
   *
   * Guarded on YAML.keySuggestions/YAML.keyAt existing, the same way
   * paintInk() guards for YAML.highlight: neither has landed in the model
   * yet, so both panels quietly do nothing until it has. */

  var suggestItems = [];    // the {key, title} list the model offered last
  var suggestOn    = -1;    // index of the highlighted row
  var suggestRange = null;  // {start, end} in the text the accepted key replaces
  var suggestIsValue = false; // true when the open panel is offering values, not keys — see acceptSuggest()
  var suggestTimer = null;
  var hoverTimer   = null;

  function suggestOpen() {
    return !!(suggestBox && !suggestBox.hidden);
  }

  // Offsets to a 0-based line/col, the terms YAML.keySuggestions() and
  // YAML.keyAt() both deal in. Walked by hand rather than through
  // YAML.lineAtOffset(), which needs a parsed doc — one may not exist (the
  // file may not parse at all) or may not cover where the caret is right now.
  function offsetToLineCol(text, offset) {
    var line = 0, lastNL = -1;
    for (var i = 0; i < offset; i++) {
      if (text.charCodeAt(i) === 10) { line++; lastNL = i; }
    }
    return { line: line, col: offset - lastNL - 1 };
  }

  // The reverse: a mouse position to a line/col, for the hover panel. There
  // is no element to hit-test against — the textarea is one opaque box — so
  // this is repaintHits()'s own column arithmetic, run backwards.
  function pointToLineCol(clientX, clientY) {
    if (!LINE_H) measure();
    var rect    = yamlWrap.getBoundingClientRect();
    var padLeft = parseFloat(yamlPane.style.paddingLeft) || 0;
    var x = clientX - rect.left + yamlPane.scrollLeft - padLeft;
    var y = clientY - rect.top  + yamlPane.scrollTop  - PAD_T;
    if (x < 0 || y < 0) return null;
    return { line: Math.floor(y / LINE_H), col: Math.round(x / CHAR_W) };
  }

  // Positions `panel` against a line/col in the terms paintDots() and
  // repaintHits() already use. `below` puts it under the line, so it never
  // covers what is being typed — what the suggestion list wants; the hover
  // panel sits against the line itself, closer to the pointer that asked
  // for it.
  //
  // .staxx-yamlwrap clips overflow (it has to, so the gutter can hide a
  // too-long line rather than let it slide past) — so a panel placed past
  // its bottom or right edge does not reflow, it is just silently cut off.
  // Flipping above the line, and pulling the left edge back in, is what
  // keeps it on screen instead.
  function placeCaretPanel(panel, line, col, below) {
    if (!LINE_H) measure();
    var padLeft = parseFloat(yamlPane.style.paddingLeft) || 0;
    var left    = padLeft + col * CHAR_W - yamlPane.scrollLeft;
    var lineTop = PAD_T + line * LINE_H - yamlPane.scrollTop;
    var top     = below ? lineTop + LINE_H : lineTop;

    panel.style.left = left + 'px';
    panel.style.top  = top + 'px';
    panel.hidden = false;   // has to be visible before offsetHeight/Width mean anything

    var wrapW = yamlWrap.clientWidth, wrapH = yamlWrap.clientHeight;
    if (top + panel.offsetHeight > wrapH) top  = Math.max(0, lineTop - panel.offsetHeight);
    if (left + panel.offsetWidth > wrapW) left = Math.max(0, wrapW - panel.offsetWidth);

    panel.style.top  = top + 'px';
    panel.style.left = left + 'px';
  }

  /* ---- suggestions ---- */

  function hideSuggest() {
    if (suggestTimer) { clearTimeout(suggestTimer); suggestTimer = null; }
    if (!suggestBox) return;
    suggestBox.hidden = true;
    suggestBox.innerHTML = '';
    suggestItems = [];
    suggestOn = -1;
    suggestRange = null;
    suggestIsValue = false;
  }

  // The typed letters marked inside the key, matched from the front only —
  // this is completion, not search, so a hit partway through the key would
  // be a confusing thing to highlight.
  function suggestRowHtml(item, prefix, i, on) {
    var key = String(item.key || '');
    var hit = prefix && key.slice(0, prefix.length).toLowerCase() === prefix.toLowerCase();
    var keyHtml = hit
      ? '<mark class="staxx-suggest-hit">' + esc(key.slice(0, prefix.length)) + '</mark>' +
        esc(key.slice(prefix.length))
      : esc(key);
    return '<div class="staxx-suggest-row' + (on ? ' staxx-suggest-row--on' : '') +
           '" role="option" data-idx="' + i + '">' +
           '<span class="staxx-suggest-key">' + keyHtml + '</span>' +
           '<span class="staxx-suggest-title">' + esc(item.title || '') + '</span></div>';
  }

  function showSuggest(hit) {
    suggestItems = hit.keys;
    suggestRange = { start: hit.start, end: hit.end };
    suggestIsValue = !!hit.value;
    suggestOn = 0;

    var rows = [];
    for (var i = 0; i < suggestItems.length; i++) {
      rows.push(suggestRowHtml(suggestItems[i], hit.prefix, i, i === 0));
    }
    suggestBox.innerHTML = rows.join('');

    var lc = offsetToLineCol(yamlPane.value, yamlPane.selectionStart);
    placeCaretPanel(suggestBox, lc.line, lc.col, true);
    hideHover();   // the two panels share a corner; only one shows at once
  }

  function runSuggest() {
    suggestTimer = null;
    if (fileOpen !== null) return;   // a companion file has no compose keys to suggest
    if (!suggestBox) return;
    if (!YAML || typeof YAML.keySuggestions !== 'function') return;   // not landed yet
    if (yamlPane.readOnly) return;
    if (document.activeElement !== yamlPane) return;
    if (yamlPane.selectionStart !== yamlPane.selectionEnd) { hideSuggest(); return; }

    var hit = YAML.keySuggestions(yamlPane.value, yamlPane.selectionStart);
    if (!hit || !hit.keys || !hit.keys.length) {
      // A caret is either before the colon or after it, never both — so this
      // is a fallback to try, not a second source to merge with the first.
      if (typeof YAML.valueSuggestions === 'function') {
        hit = YAML.valueSuggestions(yamlPane.value, yamlPane.selectionStart);
      }
      if (!hit || !hit.keys || !hit.keys.length) { hideSuggest(); return; }
    }
    showSuggest(hit);
  }

  // Lighter debounce than the 400ms reparse below — this has to feel like it
  // is keeping up with typing, not settling in after a pause.
  function scheduleSuggest() {
    if (!suggestBox) return;
    if (suggestTimer) clearTimeout(suggestTimer);
    suggestTimer = setTimeout(runSuggest, 80);
  }

  function moveSuggest(delta) {
    if (!suggestItems.length) return;
    suggestOn = (suggestOn + delta + suggestItems.length) % suggestItems.length;
    var rows = suggestBox.children;
    for (var i = 0; i < rows.length; i++) {
      rows[i].classList.toggle('staxx-suggest-row--on', i === suggestOn);
    }
    rows[suggestOn].scrollIntoView({ block: 'nearest' });
  }

  // Goes through replaceRange(), same as every other scripted edit to this
  // box, so the browser's own undo stack still covers it.
  function acceptSuggest() {
    if (suggestOn < 0 || !suggestRange) { hideSuggest(); return; }
    var text = suggestItems[suggestOn].key + (suggestIsValue ? '' : ': ');
    var at = suggestRange.start;
    replaceRange(suggestRange.start, suggestRange.end, text);
    yamlPane.setSelectionRange(at + text.length, at + text.length);
    hideSuggest();
  }

  yamlPane.addEventListener('input', scheduleSuggest);
  yamlPane.addEventListener('blur', hideSuggest);

  if (suggestBox) {
    // mousedown, not click: a click fires after the textarea has already
    // lost focus, and by then the caret position acceptSuggest() needs is
    // gone. preventDefault keeps the focus (and the caret) right where it is.
    suggestBox.addEventListener('mousedown', function (event) {
      var row = event.target.closest('.staxx-suggest-row');
      if (!row) return;
      event.preventDefault();
      suggestOn = parseInt(row.dataset.idx, 10);
      acceptSuggest();
    });
  }

  // Registered ahead of the Tab/Enter handler just below, so an open list
  // claims those keys first — see that handler's own comment for why they
  // are otherwise spoken for. Escape's preventDefault() also stops the
  // dialog's own cancel-on-Escape default action (the same trick findWhat's
  // Escape handler further down relies on), so this closes only the list —
  // never the find bar or the editor behind it.
  yamlPane.addEventListener('keydown', function (event) {
    if (!suggestOpen()) return;
    if (event.isComposing || event.keyCode === 229) return;

    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      event.stopImmediatePropagation();
      moveSuggest(event.key === 'ArrowDown' ? 1 : -1);
    } else if (event.key === 'Enter' || event.key === 'Tab') {
      event.preventDefault();
      event.stopImmediatePropagation();
      acceptSuggest();
    } else if (event.key === 'Escape') {
      event.preventDefault();
      event.stopImmediatePropagation();
      hideSuggest();
    }
  });

  /* ---- hover help ---- */

  function hideHover() {
    if (hoverTimer) { clearTimeout(hoverTimer); hoverTimer = null; }
    if (!keyHelp) return;
    keyHelp.hidden = true;
    keyHelp.innerHTML = '';
  }

  function runHover(clientX, clientY) {
    hoverTimer = null;
    if (fileOpen !== null) return;   // a companion file has no compose keys to explain
    if (!keyHelp) return;
    if (suggestOpen()) return;   // one panel at a time — see showSuggest()

    var lc = pointToLineCol(clientX, clientY);
    if (!lc) { hideHover(); return; }

    // A bad host path outranks a key description at the same spot — it is the
    // thing actually wrong here, and the key description would still be true
    // but beside the point.
    var mark = pathMarkAt(lc.line, lc.col);
    if (mark) {
      keyHelp.innerHTML = '<strong>' + esc(mark.path) + '</strong><p>' + esc(pathHoverText(mark)) + '</p>';
      placeCaretPanel(keyHelp, lc.line, lc.col, false);
      return;
    }

    if (!YAML || typeof YAML.keyAt !== 'function') return;   // not landed yet

    var info = YAML.keyAt(yamlPane.value, lc.line, lc.col);
    if (!info) { hideHover(); return; }

    keyHelp.innerHTML = '<strong>' + esc(info.key) + '</strong><p>' +
      esc(info.description || info.title || '') + '</p>';
    placeCaretPanel(keyHelp, lc.line, lc.col, false);
  }

  yamlPane.addEventListener('mousemove', function (event) {
    var x = event.clientX, y = event.clientY;
    if (hoverTimer) clearTimeout(hoverTimer);
    hoverTimer = setTimeout(function () { runHover(x, y); }, 120);
  });
  yamlPane.addEventListener('mouseleave', hideHover);
  // Any key, not just the ones the box cares about — reading a hover panel
  // while typing past it is not a thing anyone wants.
  yamlPane.addEventListener('keydown', function () { hideHover(); });

  // Tab indents rather than leaving the box, Enter copies the line above's
  // indent, and Backspace across leading whitespace jumps back a full stop —
  // all of it what anyone editing YAML by hand expects. Escape is the way out
  // of the box — see the cancel handler above, which this doubles as the
  // escape hatch for.
  yamlPane.addEventListener('keydown', function (event) {
    // Mid-composition, Enter and Tab belong to the input method — they pick a
    // candidate rather than typing anything, and taking them here would make
    // the box unusable in Japanese, Chinese or Korean. keyCode 229 is the
    // older browsers' way of saying the same thing.
    if (event.isComposing || event.keyCode === 229) return;

    // Sanitised is a read-only screenshot mode showing redacted text. The
    // browser blocks typing into it, but setRangeText below is script and
    // would go straight through — writing into a copy that is thrown away
    // when Sanitise is switched off.
    if (yamlPane.readOnly) return;

    var val = yamlPane.value;
    var start = yamlPane.selectionStart, end = yamlPane.selectionEnd;

    if (event.key === 'Tab') {
      event.preventDefault();

      if (event.shiftKey) {
        if (start !== end) {
          var outRows = selectedLines(val, start, end);
          var outText = outdentBlock(val.slice(outRows.start, outRows.end));
          replaceRange(outRows.start, outRows.end, outText);
          yamlPane.setSelectionRange(outRows.start, outRows.start + outText.length);
        } else {
          // Up to two spaces immediately before the caret — a lone odd space
          // still goes, rather than leaving the caret one column short of even.
          var two = val.charAt(start - 1) === ' ' && val.charAt(start - 2) === ' ';
          var one = !two && val.charAt(start - 1) === ' ';
          var n = two ? 2 : (one ? 1 : 0);
          if (n) replaceRange(start - n, start, '');
        }
        return;
      }

      if (start !== end && val.slice(start, end).indexOf('\n') !== -1) {
        var inRows = selectedLines(val, start, end);
        var inText = indentBlock(val.slice(inRows.start, inRows.end));
        replaceRange(inRows.start, inRows.end, inText);
        yamlPane.setSelectionRange(inRows.start, inRows.start + inText.length);
      } else {
        replaceRange(start, end, '  ');
      }
      return;
    }

    if (event.key === 'Enter') {
      // Only up to the caret — text already on the line after it is not the
      // line's indent and must not be read as if it were.
      var lineStart = val.lastIndexOf('\n', start - 1) + 1;
      var upToCaret = val.slice(lineStart, start);
      var indent  = /^ */.exec(upToCaret)[0];
      var trimmed = upToCaret.trim();
      var extra   = (/:$/.test(trimmed) || trimmed === '-') ? '  ' : '';
      event.preventDefault();
      replaceRange(start, end, '\n' + indent + extra);
      return;
    }

    if (event.key === 'Backspace' && start === end) {
      var ls   = val.lastIndexOf('\n', start - 1) + 1;
      var lead = val.slice(ls, start);
      if (lead.length && /^ *$/.test(lead)) {
        // Back to the previous multiple of two, not one space at a time — the
        // mirror image of Tab's own two-space step.
        var back = lead.length % 2 === 0 ? 2 : 1;
        event.preventDefault();
        replaceRange(start - back, start, '');
      }
    }
  });

  /* ---- find and replace --------------------------------------------------- */

  /* Lives entirely on top of the compose pane's own textarea: a match is just
   * a selection range (setSelectionRange), stepping is just revealLine() plus
   * that, and a single replace goes through replaceRange() so the browser's
   * undo keeps working exactly as it does for ordinary typing (see
   * replaceRange()'s own comment above). Only Replace All is a big enough
   * change to earn its own undo step.
   *
   * findMatches/findCurrent are declared unconditionally (not inside a
   * "markup landed" guard) because repaintHits() reads them on every
   * repaintMark() call, including before the bar itself exists. Every
   * function below that touches an actual find-bar element guards on
   * findBar first, same as yamlDots elsewhere in this file. */

  var findMatches = [];
  var findCurrent = -1;
  var findDebounceTimer = null;

  function findIsOpen() {
    return !!(findBar && !findBar.hidden);
  }

  // The raw match list for whatever is typed right now — no side effects, so
  // both the quiet resync (findRecompute) and the active one (findRun) build
  // on it rather than duplicating the call to YAML.searchMatches.
  function findCompute() {
    var needle = findWhat.value;
    return (needle && YAML && typeof YAML.searchMatches === 'function')
      ? YAML.searchMatches(yamlPane.value, needle, {
          caseSensitive: findCase.checked,
          regex: findRegex.checked
        })
      : [];
  }

  function findUpdateCount() {
    if (!findBar) return;
    if (!findWhat.value) {
      findCount.textContent = '';
      findBar.classList.remove('staxx-find--none');
      return;
    }
    if (!findMatches.length) {
      findCount.textContent = 'No matches';
      findBar.classList.add('staxx-find--none');
      return;
    }
    findCount.textContent = (findCurrent + 1) + ' of ' + findMatches.length;
    findBar.classList.remove('staxx-find--none');
  }

  // Recomputes the match list and picks whichever match now sits at or after
  // the previous current match (wrapping to the first when none does), but
  // does not move the caret or the scroll position. Used whenever the
  // document changes for a reason that has nothing to do with the search box
  // itself — typing in the pane, a form commit, Sanitise toggling redacted
  // text in and out — where grabbing the selection out from under whatever
  // the user is actually doing would be the wrong kind of "helpful".
  function findRecompute() {
    if (!findBar) return;
    var prevStart = (findCurrent >= 0 && findMatches[findCurrent])
      ? findMatches[findCurrent].start : yamlPane.selectionStart;

    findMatches = findCompute();

    if (!findMatches.length) {
      findCurrent = -1;
    } else {
      var idx = 0;
      for (var i = 0; i < findMatches.length; i++) {
        if (findMatches[i].start >= prevStart) { idx = i; break; }
      }
      findCurrent = idx;
    }
    findUpdateCount();
    repaintMark();
  }

  // The active version: same recompute, but also lands the selection on the
  // result — for typing in the find box, toggling case/regex, and landing on
  // the next match after a single replace, all of which are the user (or a
  // replace acting on the user's behalf) steering the search, not the file
  // changing out from under it.
  function findRun() {
    if (!findBar) return;
    findRecompute();
    if (findCurrent >= 0) findGoTo(findCurrent);
  }

  function findScheduleRun() {
    if (findDebounceTimer) clearTimeout(findDebounceTimer);
    // Much cheaper than the 400ms re-parse debounce elsewhere in this file —
    // a plain scan or a regex exec over the buffer, not a full YAML parse —
    // so it can afford to feel closer to immediate.
    findDebounceTimer = setTimeout(function () {
      findDebounceTimer = null;
      findRun();
    }, 150);
  }

  // Selects match `idx` (wrapping) and scrolls it into view. Shared by Enter,
  // Shift-Enter, the prev/next buttons, and findRun() landing on a fresh
  // result.
  function findGoTo(idx) {
    if (!findBar || !findMatches.length) return;
    if (idx < 0) idx = findMatches.length - 1;
    if (idx >= findMatches.length) idx = 0;
    findCurrent = idx;

    var m = findMatches[idx];
    revealLine(m.line);
    yamlPane.setSelectionRange(m.start, m.end);
    findUpdateCount();
    repaintMark();
  }

  function openFind(mode) {
    if (!findBar) return;
    findReplaceRow.hidden = mode !== 'replace';

    var sel = yamlPane.value.slice(yamlPane.selectionStart, yamlPane.selectionEnd);
    if (sel) findWhat.value = sel;

    findBar.hidden = false;
    findWhat.focus();
    findWhat.select();
    findRun();
  }

  function closeFind() {
    if (!findBar) return;
    findBar.hidden = true;
    findMatches = [];
    findCurrent = -1;
    repaintMark();
    yamlPane.focus();
  }

  // Full reset, for a stack that is closing or about to be replaced by
  // another — unlike closeFind(), this also drops the typed terms, since
  // carrying "POSTGRES_PASSWORD" from one stack's search into the next one's
  // freshly-opened editor is not a convenience, it is a leak.
  function findReset() {
    if (!findBar) return;
    findBar.hidden = true;
    findBar.classList.remove('staxx-find--none');
    findWhat.value = '';
    findWith.value = '';
    findCount.textContent = '';
    findMatches = [];
    findCurrent = -1;

    // The boxes themselves, not just the list behind them. Left in place they
    // survive into the next stack the editor opens, and show for the moment
    // between the dialog appearing and the first repaint — at positions
    // measured against a different file.
    var stale = yamlMarks.querySelectorAll('.staxx-hit');
    for (var i = 0; i < stale.length; i++) stale[i].remove();
  }

  // With regex on, "$1" in the replacement is a group reference exactly as it
  // is everywhere else JavaScript does this — built by replacing the SAME
  // pattern against just the matched slice, so a capture group is filled from
  // this match's own text. With regex off, the box is a literal string: a "$"
  // typed there must reach the file as a literal "$", never as String.replace's
  // special substitution syntax ($&, $1, $$…) that a literal-mode call would
  // otherwise still honour even though the search itself was not a regex.
  // Getting this backwards would silently rewrite someone's file, so it is
  // never routed through .replace() at all in literal mode — just spliced in.
  function buildReplacement(m, text) {
    if (!findRegex.checked) return findWith.value;
    try {
      var re = new RegExp(findWhat.value, findCase.checked ? '' : 'i');
      return text.slice(m.start, m.end).replace(re, findWith.value);
    } catch (e) {
      return findWith.value;   // the pattern stopped being valid between the search and this click
    }
  }

  function findReplaceOne() {
    if (!findBar) return;
    if (yamlPane.readOnly) {
      // Read-only means one of two different things now — a redacted compose
      // copy, or a binary companion this box cannot show at all — and the two
      // need two different sentences.
      setYamlStatus(fileOpen !== null
        ? 'That file is not text, so it cannot be edited here.'
        : 'Turn off Sanitise to replace text — it only edits the redacted copy on screen, not the file.');
      return;
    }
    if (findDebounceTimer) { clearTimeout(findDebounceTimer); findDebounceTimer = null; findRun(); }
    if (findCurrent < 0 || !findMatches.length) return;

    var text = yamlPane.value;
    var m = findMatches[findCurrent];
    replaceRange(m.start, m.end, buildReplacement(m, text));
    // The replacement just shifted every offset after it — recompute AND land
    // on the result (findRun(), not findRecompute()), which is what makes
    // this "replace the current match and move to the next".
    findRun();
  }

  function findReplaceAll() {
    if (!findBar) return;
    if (yamlPane.readOnly) {
      // See findReplaceOne()'s own comment — read-only has two causes now.
      setYamlStatus(fileOpen !== null
        ? 'That file is not text, so it cannot be edited here.'
        : 'Turn off Sanitise to replace text — it only edits the redacted copy on screen, not the file.');
      return;
    }
    if (findDebounceTimer) { clearTimeout(findDebounceTimer); findDebounceTimer = null; }
    findMatches = findCompute();
    if (!findMatches.length) { setYamlStatus('No matches to replace.'); return; }

    flushPending();
    var count = findMatches.length;
    pushUndo('replacing ' + count + ' match' + (count === 1 ? '' : 'es'));

    var text = yamlPane.value, out = [], at = 0;
    for (var i = 0; i < findMatches.length; i++) {
      var m = findMatches[i];
      out.push(text.slice(at, m.start));
      out.push(buildReplacement(m, text));
      at = m.end;
    }
    out.push(text.slice(at));

    // The same sequence structuralEdit() runs after a model edit, except the
    // new text here already IS the finished string — this was a plain splice
    // over the buffer, not a change to MODEL.doc, so there is no document to
    // re-serialise.
    yamlPane.value = out.join('');
    paintGutter();
    paintInk();
    activeField = null;
    reparse();
    updateUndo();
    setYamlStatus('Replaced ' + count + ' match' + (count === 1 ? '' : 'es') + '.');
    // reparse() already calls findRecompute(); findRun() on top of that lands
    // the selection on the (now empty, until searched again) match list.
    findRun();
  }

  if (findBar) {
    findWhat.addEventListener('input', findScheduleRun);
    findCase.addEventListener('change', findRun);
    findRegex.addEventListener('change', findRun);
    findClose.addEventListener('click', closeFind);
    findPrev.addEventListener('click', function () { findGoTo(findCurrent - 1); });
    findNext.addEventListener('click', function () { findGoTo(findCurrent + 1); });
    findOne.addEventListener('click', findReplaceOne);
    findAll.addEventListener('click', findReplaceAll);

    findWhat.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') { event.preventDefault(); closeFind(); return; }
      if (event.key !== 'Enter') return;
      event.preventDefault();
      if (findDebounceTimer) {
        // Typing then pressing Enter straight away: flush the debounce and
        // land on the nearest match rather than also stepping past it.
        clearTimeout(findDebounceTimer);
        findDebounceTimer = null;
        findRun();
      } else {
        findGoTo(findCurrent + (event.shiftKey ? -1 : 1));
      }
    });

    findWith.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') { event.preventDefault(); closeFind(); }
      else if (event.key === 'Enter') { event.preventDefault(); findReplaceOne(); }
    });
  }

  // Ctrl-F/Ctrl-H only take over from the browser's own Find when there is a
  // compose pane on screen to search — not in Form view, and not anywhere
  // outside the editor dialog.
  document.addEventListener('keydown', function (event) {
    if (!findBar || !modal.open || modalBody.dataset.view === 'form') return;
    var lower = event.key.toLowerCase();
    if (!(event.ctrlKey || event.metaKey) || (lower !== 'f' && lower !== 'h')) return;
    event.preventDefault();
    openFind(lower === 'h' ? 'replace' : 'find');
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

  // Compose's own refusal message usually names a line ("yaml: line 31: did
  // not find expected key"), but compose counts lines from 1 and lint()/
  // paintDots() count from 0 — converted once, here, so a mismatch cannot
  // creep in anywhere else and send the user to the wrong line. Shared with
  // the live compose check below (see runCheck()) so this stays the one
  // place that mapping lives; returns null when the message names no line,
  // rather than inventing one.
  function lineFromMessage(message) {
    var m = /line (\d+)/.exec(message || '');
    return m ? parseInt(m[1], 10) - 1 : null;
  }

  // The other half: a schema complaint names a dotted path where a syntax
  // error names a line. Only paths that start at a real top-level compose
  // section are followed, so an ordinary sentence containing a full stop
  // cannot be mistaken for one.
  var PATH_HEAD = /^(services|networks|volumes|configs|secrets|include)\./;

  function lineFromPath(message) {
    var m = /validating[^:]*:\s*([A-Za-z0-9_.\-]+)/.exec(message || '');
    if (!m || !PATH_HEAD.test(m[1])) return null;
    if (!YAML || typeof YAML.lineOfPath !== 'function' || !MODEL || !MODEL.doc) return null;
    var line = YAML.lineOfPath(MODEL.doc, m[1]);
    return line >= 0 ? line : null;
  }

  // The dot is merged in on top of whatever lint already found, and lives
  // until the next reparse() (typing again, or a form commit) replaces it.
  function markSaveError(message) {
    var line = lineFromMessage(message);
    if (line === null) return;
    saveErrorDot = { line: line, level: 'error', message: message };
    redrawDots();
    revealLine(line);
  }

  function save(thenStart) {
    // A no-op in practice — Save is disabled whenever a companion tab is on
    // screen — but the compose file is what gets written next, so any pending
    // autosave for another file must be on its way to disk first regardless.
    flushFileSave();

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

    // The box only ever holds LF, so the file's own ending — recorded when it
    // was opened — is put back for the write. textAtOpen below stays LF: it
    // is compared against the box, not against the file on disk.
    call('save', { name: name, body: withEol(body, composeEol), 'new': modal.dataset.new })
      .then(function (res) {
        if (!res.ok) {
          saveBtn.disabled = false;
          startBtn.disabled = startBtnWasDisabled;
          showError((res.error || 'Save failed.') + strayWarning(res));
          markSaveError(res.error);
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
    row.classList.toggle('staxx-busy', !!on);
  }

  // A stack row and the container rows underneath it move together: a command
  // is issued to the whole stack, so the whole stack shows it is working.
  function stackRows(name) {
    var out = [];
    var row = rowFor(name);
    if (row) out.push(row);
    Array.prototype.forEach.call(
      document.querySelectorAll('.staxx-container-row[data-in-stack="' + name + '"]'),
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
        '.staxx-container-row[data-in-stack="' + stack + '"] ' +
        '[data-menu="container"][data-service="' + service + '"]'
      ),
      function (btn) {
        var row = btn.closest('.staxx-container-row');
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
      if (td) {
        td.innerHTML = '<span class="staxx-pill staxx-pill--busy">' + label + '</span>';

        // paintState skips a cell whose HTML has not changed since it last
        // wrote one. "Starting…" was put here by this function instead, so
        // that record has to go: without it a stack that ends up back in the
        // state it started in — a restart, or a start that failed — keeps the
        // busy pill for ever, because the state arriving afterwards matches
        // what paintState last wrote and gets skipped.
        td.staxxTxt = '';
      }
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

  var rowsHost   = document.getElementById('staxx-rows');
  var stateBusy  = false;
  var stateAgain = false;

  // Stack names and folder ids are both restricted to letters, numbers, dots,
  // dashes and underscores before anything is ever created, so there is nothing
  // in either that needs escaping inside a quoted attribute selector.
  function rowFor(name) {
    return rowsHost
      ? rowsHost.querySelector('.staxx-stack-row[data-stack-row="' + name + '"]')
      : null;
  }

  // Paint one row's state cell, address cell and status dot, unless it is
  // mid-command. The address moves with the state: a container that has just
  // been recreated may be answering somewhere else.
  //
  // Both cells come from the server as ready-made HTML, and on a settled
  // machine that HTML is identical poll after poll — so it is compared and
  // skipped the same way a figure is. See setCell for why the comparison is
  // against what we were handed rather than against innerHTML.
  function paintState(row, html, isUp, address) {
    if (row.dataset.busy) return;
    var td = row.querySelector('[data-cell="state"]');
    if (td && td.staxxTxt !== html) {
      td.innerHTML = html;
      td.staxxTxt = html;
    }
    var addr = row.querySelector('[data-cell="address"]');
    if (addr && address !== undefined && addr.staxxTxt !== address) {
      addr.innerHTML = address;
      addr.staxxTxt = address;
    }
    setClass(row.querySelector('.staxx-dot'), 'staxx-dot--up', !!isUp);
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
      if (btn) setData(btn, 'running', s.running ? '1' : '0');

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
          setData(kid, 'container', '');
          setData(kid, 'state', '');
          paintState(kid, res.notCreated || '', false, res.noAddress || '');
          return;
        }

        // Filled in here rather than at render time: the first time a stack is
        // started these rows came from the compose file and had no container
        // to point at yet. This is what binds them to a real one.
        setData(kid, 'container', c.container);
        setData(kid, 'state', c.state);
        if (c.state === 'running') up++;
        paintState(kid, c.html, c.state === 'running', c.address);
      });

      var sub = row.querySelector('[data-cell="stack-sub"]');
      if (sub && kids.length) {
        var line = stackSub(kids.length, up);
        if (sub.textContent !== line) sub.textContent = line;
      }
    });

    var folders = res.folders || {};
    Object.keys(folders).forEach(function (id) {
      var tr = document.querySelector('[data-folder-row="' + id + '"]');
      if (!tr) return;
      var sub = tr.querySelector('[data-cell="folder-sub"]');
      if (sub && sub.staxxTxt !== folders[id].html) {
        sub.innerHTML = folders[id].html;
        sub.staxxTxt = folders[id].html;
      }
      setClass(tr.querySelector('.staxx-dot'), 'staxx-dot--up', folders[id].running > 0);
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
            r.classList.add('staxx-row--enter');
            r.addEventListener('animationend', function () {
              r.classList.remove('staxx-row--enter');
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
        img.dataset.fallbackColour = (String(node.className).match(/staxx-tile--(\d+)/) || [])[1] || '0';
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
    if (!img.closest || !img.closest('.staxx-icon')) return;

    var span = document.createElement('span');
    span.className = 'staxx-tile staxx-tile--' + (img.dataset.fallbackColour || '0');
    span.textContent = img.dataset.fallback;
    if (img.dataset.iconRef) span.dataset.iconRef = img.dataset.iconRef;
    if (img.parentNode) img.parentNode.replaceChild(span, img);
  }, true);

  /* ------------------------------------------------------------ wiring -- */

  var settingsBtn = document.getElementById('staxx-settings-btn');
  if (settingsBtn) settingsBtn.addEventListener('click', openSettings);

  document.getElementById('staxx-add').addEventListener('click', function () {
    openEditor('', '', true);
  });

  document.getElementById('staxx-apps').addEventListener('click', caOpen);

  document.getElementById('staxx-import').addEventListener('click', importOpen);

  document.getElementById('staxx-diagnose').addEventListener('click', function () {
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

  document.getElementById('staxx-modal-close').addEventListener('click', function () {
    if (confirmDiscard()) closeEditor();
  });
  saveBtn.addEventListener('click', function () { save(false); });
  startBtn.addEventListener('click', function () { save(true); });

  document.getElementById('staxx-log-close').addEventListener('click', function () {
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

  // One row of the delete confirmation's file list: a plain file shows its
  // size, a subdirectory shows how many entries it holds one level down, and
  // a symlink is called out on its own — staxx_rmtree() unlinks a symlink
  // rather than following it, so whatever one points at is never touched.
  //
  // bytes() is the stats table's formatter, declared further down and reached
  // by hoisting. A second one here would shadow it for the whole IIFE and
  // change every memory figure on the page, which is exactly what happened.
  function extraLine(e) {
    var meta;
    if (e.link) {
      meta = 'link — what it points to is left alone';
    } else if (e.dir) {
      meta = e.count === 0 ? 'empty' : e.count + (e.count === 1 ? ' file' : ' files');
    } else {
      meta = bytes(e.size);
    }
    return '<li><code>' + esc(e.name) + '</code><span class="staxx-confirm-meta">' +
           esc(meta) + '</span></li>';
  }

  // Whether a delete is in flight (disables both buttons) and the resolve
  // function for whichever askConfirm() question is currently open — set by
  // askConfirm() below, consumed once by whichever button, or closing,
  // settles it.
  var confirmBusy    = false;
  var confirmResolve = null;

  function confirmSetBusy(busy) {
    confirmBusy = busy;
    confirmCancel.disabled = busy;
    confirmGo.disabled = busy;
  }

  function closeConfirm() {
    if (confirmModal.open) confirmModal.close();
  }

  function settleConfirm(value) {
    if (!confirmResolve) return;
    var resolve = confirmResolve;
    confirmResolve = null;
    resolve(value);
  }

  // A yes/no question through #staxx-confirm, resolving true (Go) or
  // false (Cancel, Escape, a backdrop click, or the dialog closing any other
  // way). Callable again while the dialog is already open — it updates the
  // title, body and button label in place rather than closing and
  // reopening — so a multi-stage question (deleteStack()'s needsConfirm flow
  // below) can grow the same dialog instead of flickering through a second
  // one. Leaves confirmMsg alone: that is a status line for the request a
  // question leads to, not part of the question itself, and clearing it here
  // would erase an error the moment a caller re-asks to offer a retry.
  function askConfirm(opts) {
    confirmSetBusy(false);
    confirmTitle.textContent = opts.title;
    confirmBody.innerHTML = opts.bodyHtml;
    confirmGo.textContent = opts.goLabel;
    if (!confirmModal.open) confirmModal.showModal();
    // Explicit, and after showModal(), for the same reason openEditor() sets
    // focus explicitly: the dialog's own "first focusable descendant" rule
    // would land on the destructive button, and a stray Enter must not
    // activate it. Refocused on every call, including a dialog that is
    // already open and growing into a bigger question, so focus does not
    // linger on a button whose label just changed under it.
    confirmCancel.focus({ preventScroll: true });
    return new Promise(function (resolve) { confirmResolve = resolve; });
  }

  if (confirmModal) {
    confirmCancel.addEventListener('click', function () {
      closeConfirm();
      settleConfirm(false);
    });

    confirmModal.addEventListener('close', function () {
      settleConfirm(false);
    });

    // A request in flight must finish before Escape can close the dialog —
    // otherwise a fetch already sent could still delete everything after the
    // dialog the person thought they backed out of has gone.
    confirmModal.addEventListener('cancel', function (event) {
      if (confirmBusy) event.preventDefault();
    });

    // Same hit-test the picker and editor use: <dialog> fires no backdrop
    // click of its own, because a click on the backdrop targets the dialog
    // element itself.
    confirmModal.addEventListener('click', function (event) {
      if (event.target !== confirmModal || confirmBusy) return;
      var r = confirmModal.getBoundingClientRect();
      if (event.clientX < r.left || event.clientX > r.right ||
          event.clientY < r.top  || event.clientY > r.bottom) closeConfirm();
    });

    confirmGo.addEventListener('click', function () {
      if (confirmBusy) return;
      settleConfirm(true);
    });
  }

  // Stage one's body: the same warning window.confirm() used to show, as
  // paragraphs instead of a string with blank lines baked into it.
  function confirmStageOneHtml(name, label) {
    var where = label === name ? '' : ' Its folder, "' + name + '", goes with it.';
    return '<p>Its containers are stopped and removed, and the compose file is deleted.' + where + '</p>' +
           '<p>Data stored outside the stack folder is left alone.</p>';
  }

  // Stage two's addendum: the folder held more than the compose file, so
  // the dialog grows in place — naming what else would go with it — rather
  // than the person finding out only after they have already clicked
  // through.
  function confirmStageTwoHtml(entries) {
    return '<p>This folder also holds files that are not part of the compose file. ' +
           'Deleting the stack deletes these too:</p>' +
           '<ul class="staxx-confirm-list">' + entries.map(extraLine).join('') + '</ul>';
  }

  function deleteStack(name, label) {
    if (!confirmModal) {
      // Markup from before this dialog existed. Same wording as stage one,
      // so deleting still works rather than silently doing nothing.
      var where = label === name ? '' : ' Its folder, "' + name + '", goes with it.';
      if (!window.confirm(
            'Delete "' + label + '"?\n\n' +
            'Its containers are stopped and removed, and the compose file is deleted.' + where + '\n\n' +
            'Data stored outside the stack folder is left alone.')) {
        return;
      }
      call('delete', { name: name }).then(function (res) {
        if (!res.ok) { failed('Could not delete ' + label, res.error); return; }
        var row = rowFor(name);
        if (row) row.classList.add('staxx-row--leave');
        setTimeout(refreshRows, 140);
      });
      return;
    }

    confirmMsg.textContent = '';

    // Asks one question, then either finishes or asks again in place: a
    // plain failure re-asks the same question so Go still works as a retry,
    // and needsConfirm re-asks with the dialog grown into stage two — both
    // without ever closing and reopening it.
    function step(fields, bodyHtml, goLabel) {
      askConfirm({ title: 'Delete "' + label + '"?', bodyHtml: bodyHtml, goLabel: goLabel })
        .then(function (go) {
          if (!go) return;
          confirmSetBusy(true);
          confirmMsg.textContent = '';

          call('delete', fields).then(function (res) {
            confirmSetBusy(false);

            if (res.ok) {
              closeConfirm();
              // Fade the row before the table re-renders wholesale, rather
              // than having it just vanish when the replacement HTML
              // arrives without it.
              var row = rowFor(name);
              if (row) row.classList.add('staxx-row--leave');
              setTimeout(refreshRows, 140);
              return;
            }

            if (res.needsConfirm) {
              step({ name: name, confirm: '1' }, bodyHtml + confirmStageTwoHtml(res.entries || []),
                   'Delete everything');
              return;
            }

            confirmMsg.textContent = res.error || ('Could not delete ' + label + '.');
            step(fields, bodyHtml, goLabel);
          });
        });
    }

    step({ name: name }, confirmStageOneHtml(name, label), 'Delete stack');
  }

  // Unlocking is just deleting the lock file — the same companion-file
  // delete action the editor already uses, so there is nothing new on the
  // endpoint to guard or test. This is the lesser option on a review-locked
  // stack's menu (see buildStackMenu): it removes the lock and nothing
  // else, so a container already using this stack's name is left exactly
  // as it is and starting can still fail against it. "Take over and
  // start", below, is the one that actually deals with that.
  function markReviewed(name, label) {
    call('file-delete', { name: name, file: 'NEEDS-REVIEW.md' }).then(function (r) {
      if (!r.ok) { failed('Could not mark ' + label + ' as reviewed', r.error); return; }
      refreshRows();
    });
  }

  /* ---- handover (PLAN_42): taking over an imported stack's container name --
   *
   * Two windows. The first names what a takeover would replace and is built
   * from handover-check, called fresh every time rather than from menu-time
   * data, since a container clash can only be known by asking Docker right
   * now. The second asks whether it worked, either straight after a
   * successful takeover job or reached again later from the stack's own
   * menu — the answer can wait, and nothing here forces it.
   *
   * Both windows run their command through call()+follow(), the same
   * machinery run() uses, because a handover is exactly as slow and exactly
   * as important to watch as any other compose command.
   */

  // Plain "a and b", never an Oxford comma — the same join the server's own
  // handover note uses, so the wording here reads the same as the file a
  // person might open by hand instead.
  function joinNames(names) { return names.join(' and '); }

  function handoverCheck(name) { return call('handover-check', { name: name }); }

  // Window 1: what a takeover is about to do. Grammar below is written for
  // one target, which is what every real case is — a stack from a single
  // Unraid template — since bending every sentence to agree in number with
  // a rare multi-service clash would cost more clarity than it returns.
  function openTakeover(name, label) {
    handoverCheck(name).then(function (res) {
      if (!res.ok) { failed('Could not check what "' + label + '" would replace', res.error); return; }

      if (res.active) {
        askConfirm({
          title: 'A handover for "' + label + '" is already waiting',
          bodyHtml: '<p>It already replaced its old container and is waiting for you to say ' +
                    'whether it works. Answer that first, from this stack\'s own menu.</p>',
          goLabel: 'Answer now'
        }).then(function (go) {
          closeConfirm();
          if (go) openHandoverAnswer(name, label, true);
        });
        return;
      }

      var targets = res.targets || [];
      if (!targets.length) {
        askConfirm({
          title: 'Nothing to take over',
          bodyHtml: '<p>Nothing on this server holds a container name "' + label + '" asks ' +
                    'for, so there is nothing to replace. Clear the lock and it will start ' +
                    'normally.</p>',
          goLabel: 'Clear the lock'
        }).then(function (go) {
          closeConfirm();
          if (go) markReviewed(name, label);
        });
        return;
      }

      var quoted = joinNames(targets.map(function (t) { return '"' + t.name + '"'; }));

      // An imported stack is usually named after the very container it is
      // replacing, so the obvious sentence comes out as 'starts "Vert" in
      // place of "Vert"' — which reads like a mistake even though it is
      // exactly right. Say it the other way round when they match.
      var opening = targets.length === 1 && targets[0].name === label
        ? '<p>This starts this stack in place of the container called ' + quoted + '.</p>'
        : '<p>This starts "' + label + '" in place of ' + quoted + '.</p>';

      var bodyHtml =
        opening +
        '<p>The Unraid template it came from is untouched, so it can be rebuilt from that ' +
        'template at any time — it still shows on Unraid\'s own Docker page.</p>' +
        '<p>This is a rebuild, not a move. Anything written inside the old container rather ' +
        'than into one of its mapped folders will be lost; everything in appdata is ' +
        'untouched.</p>' +
        '<p>The new container keeps the same name, so anything that points at it by name — ' +
        'including starting at boot — carries on working.</p>' +
        '<p>' + quoted + ' is not deleted. It is switched off and set aside under another ' +
        'name, and can be put straight back if this does not work out.</p>';

      function step() {
        askConfirm({ title: 'Take over ' + quoted + '?', bodyHtml: bodyHtml,
                     goLabel: 'Take over and start' }).then(function (go) {
          if (!go) return;
          confirmSetBusy(true);
          confirmMsg.textContent = '';

          call('handover-start', { name: name }).then(function (r) {
            if (!r.ok) {
              confirmSetBusy(false);
              confirmMsg.textContent = r.error || 'Could not start the handover.';
              step();
              return;
            }
            closeConfirm();

            logPanel.hidden = false;
            logTitle.textContent = 'Handover — ' + label;
            logBox.textContent = 'Working…';
            logPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });

            // A failed handover has already put itself back on the server —
            // the job's own output says what went wrong, so there is
            // nothing to ask here beyond refreshing the row's badge either
            // way. Only a clean finish has anything left to confirm.
            follow(r.job, function (job) {
              refreshRows();
              if (job.exit === 0) openHandoverAnswer(name, label, true);
            }, true);
          });
        });
      }

      step();
    });
  }

  // Window 2: did it work. `focusWorks` lands the initial focus on whichever
  // answer the caller already expects — true straight after a takeover job,
  // since that is the hoped-for outcome, and true again from the "It works"
  // menu item; the "It does not work" item passes false.
  function openHandoverAnswer(name, label, focusWorks) {
    handoverCheck(name).then(function (res) {
      if (!res.ok) { failed('Could not read the handover for "' + label + '"', res.error); return; }

      if (!res.active) {
        askConfirm({
          title: 'Nothing waiting for an answer',
          bodyHtml: '<p>"' + label + '" has no handover waiting to be confirmed — it may ' +
                    'already have been answered.</p>',
          goLabel: 'OK'
        }).then(function () { closeConfirm(); });
        refreshRows();
        return;
      }

      var quoted   = joinNames((res.targets || []).map(function (t) { return '"' + t.name + '"'; }));
      var replaced = quoted || 'the old container';

      // The dialog's fixed Cancel/Go pair only carries two outcomes, and
      // "decide later" has to be a genuine third one — dismissing this must
      // never read as an answer. So "It works" is a plain button built into
      // the body instead, resolving the promise with a string rather than
      // true/false; Cancel, Escape and a backdrop click all still resolve
      // false and do nothing, exactly as they do everywhere else this
      // dialog is used.
      var bodyHtml =
        '<p>This replaced ' + replaced + '.</p>' +
        '<p>Check that the app works, then answer: "It works" clears the old container away ' +
        'for good; "It does not work" puts everything back exactly as it was, running again, ' +
        'within seconds.</p>' +
        '<div class="staxx-buttons"><button type="button" class="staxx-btn staxx-btn--primary" ' +
        'id="staxx-handover-works">It works</button></div>';

      function step() {
        var p = askConfirm({ title: 'Does "' + label + '" work?', bodyHtml: bodyHtml,
                              goLabel: 'It does not work' });

        var worksBtn = confirmBody.querySelector('#staxx-handover-works');
        if (worksBtn) {
          worksBtn.addEventListener('click', function () {
            if (confirmBusy) return;
            settleConfirm('works');
          });
          if (focusWorks) worksBtn.focus({ preventScroll: true });
        }

        p.then(function (answer) {
          if (answer === false) return;   // decide later — no side effect
          var worked = answer === 'works';

          if (worksBtn) worksBtn.disabled = true;
          confirmSetBusy(true);
          confirmMsg.textContent = '';

          call('handover-finish', { name: name, worked: worked ? '1' : '0' }).then(function (r) {
            if (!r.ok) {
              confirmSetBusy(false);
              if (worksBtn) worksBtn.disabled = false;
              confirmMsg.textContent = r.error || 'Could not answer for "' + label + '".';
              step();
              return;
            }
            closeConfirm();

            logPanel.hidden = false;
            logTitle.textContent = (worked ? 'Clearing away the old container'
                                            : 'Putting everything back') + ' — ' + label;
            logBox.textContent = 'Working…';
            logPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });

            follow(r.job, function () { refreshRows(); }, true);
          });
        });
      }

      step();
    });
  }

  function afterRun(verb) {
    // Logs and config change nothing, so leave the table as it is.
    return function () {
      if (verb !== 'logs' && verb !== 'config') refreshStateSoon();
    };
  }

  /* ---- settings panel -----------------------------------------------------
   *
   * One row per setting the server allows (staxx_settings_keys() holds the
   * matching allowlist), described once here so a sixth setting is one more
   * entry rather than a fresh block of markup. `id` is filled in below rather
   * than repeated in every entry.
   *
   * Prose is carried over verbatim from the old settings.page — same voice,
   * same facts about what does and does not leave the server, same selfh.st
   * licence line — because that page is being cut down to a signpost and this
   * is now the only place any of it is said. TAKEOVER_DOCKER_TAB is the one
   * setting that never had a control before this, so its wording is new.
   */
  var SETTINGS_ROWS = [
    {
      key: 'HEADER_MENU', control: 'choice', label: 'Show StaXX in',
      choices: [
        ['false', 'A tab under the Docker menu'],
        ['true',  'Its own button in the top navigation bar']
      ],
      help: 'Where the stacks view appears. As a Docker tab it sits ahead of Docker Containers ' +
            'and becomes the default landing tab; nothing is replaced either way.'
    },
    {
      key: 'STACK_ROOT', control: 'path', label: 'Stack directory',
      help: 'Root directory holding stack definitions, one subdirectory per stack. Keeping this ' +
            'on the flash device means stacks are readable before the array starts, which matters ' +
            'for autostart. Placing it on an array share gives more room but is unavailable until ' +
            'the array is up.'
    },
    {
      key: 'TAKEOVER_DOCKER_TAB', control: 'choice', label: 'Docker menu',
      choices: [
        ['false', 'Leave the Docker menu alone'],
        ['true',  'Replace it with StaXX']
      ],
      help: 'Off by default. Switched on, the Docker button disappears from the top of the ' +
            'screen and StaXX takes its place as a menu item of its own. Everything that lived ' +
            'under the Docker menu goes with it — Unraid\'s own container list included, and any ' +
            'other plugin\'s Docker pages. Nothing is modified and no container is touched; ' +
            'turning it back off puts all of it straight back. While this is on, the "Show ' +
            'StaXX in" setting above has no effect, because StaXX has to be a top-level item ' +
            'for there to be any way in.'
    },
    {
      key: 'ICON_FETCH', control: 'choice', label: 'Container icons',
      choices: [
        ['true',  'Download them automatically'],
        ['false', 'Do not download anything']
      ],
      help: 'Each container shows the logo of the software it runs, taken from the ' +
            '<a href="https://selfh.st/icons/" target="_blank" rel="noopener">selfh.st icon ' +
            'collection</a>. Your server fetches an icon the first time it sees a container and ' +
            'then keeps it, so this happens once per icon and never again; the only thing sent ' +
            'out is the name of the icon being asked for. Turning it off stops all downloading — ' +
            'icons already saved keep working, and containers with no icon show a coloured tile ' +
            'with their initials instead. You can always name an icon yourself with ' +
            '<code>icon:</code> in a stack\'s <code>x-unraid</code> section, which works whichever ' +
            'way this is set.<br><br>Icons are by ' +
            '<a href="https://selfh.st/icons/" target="_blank" rel="noopener">selfh.st</a> and ' +
            'used under the <a href="https://creativecommons.org/licenses/by/4.0/" target="_blank" ' +
            'rel="noopener">CC BY 4.0</a> licence.'
    },
    {
      key: 'IMAGE_LOOKUP', control: 'choice', label: 'Image documentation',
      choices: [
        ['true',  'Read it automatically'],
        ['false', 'Do not look anything up']
      ],
      help: 'When you press Add on a Docker Hub or local image, your server reads that image\'s ' +
            'own documentation from Docker Hub and uses it to build a fuller starting file — with ' +
            'the ports, paths and settings it describes, instead of just four bare lines. This ' +
            'only ever happens the moment you add something; it never runs in the background. The ' +
            'only thing sent out is the name of the image being added, nothing else. Turning it ' +
            'off gives you the four-line starting file only, instantly, and nothing leaves the ' +
            'server.'
    }
  ];
  SETTINGS_ROWS.forEach(function (row) {
    row.id = 'staxx-setting-' + row.key.toLowerCase().replace(/_/g, '-');
  });

  // What the open fetched, keyed the same way as SETTINGS_ROWS — compared
  // against the controls on every input/change to decide whether Save may be
  // pressed. Null while the panel is shut, so a stray listener firing late
  // cannot read a dirty state that no longer means anything.
  var settingsOpenValues = null;
  var settingsBusy = false;

  function settingsFieldHtml(row, value) {
    var control;
    if (row.control === 'choice') {
      var opts = row.choices.map(function (o) {
        return '<option value="' + esc(o[0]) + '"' + (o[0] === value ? ' selected' : '') +
               '>' + esc(o[1]) + '</option>';
      }).join('');
      control = '<select id="' + row.id + '" aria-label="' + esc(row.label) + '">' + opts + '</select>';
    } else {
      // A plain <div>, not a <label>, for the same reason boxHtml() above
      // uses one: a label may not hold interactive content besides its own
      // control, and the Browse button beside this box is a second one.
      control = '<div class="staxx-boxline">' +
                  '<input type="text" class="staxx-input" id="' + row.id + '" ' +
                       'aria-label="' + esc(row.label) + '" spellcheck="false" value="' + esc(value) + '">' +
                  '<button type="button" class="staxx-browse" data-browse="' + row.id + '" ' +
                       'title="Choose a folder on this server">' +
                    '<i class="fa fa-folder-open-o" aria-hidden="true"></i>' +
                    '<span class="staxx-sr">Choose a folder</span>' +
                  '</button>' +
                '</div>';
    }
    return '<div class="staxx-field">' +
             '<span>' + esc(row.label) + '</span>' +
             control +
             '<span class="staxx-hint">' + row.help + '</span>' +
           '</div>';
  }

  function settingsControlValue(row) {
    var el = document.getElementById(row.id);
    return el ? el.value : '';
  }

  function settingsDirty() {
    if (!settingsOpenValues) return false;
    return SETTINGS_ROWS.some(function (row) {
      return settingsControlValue(row) !== settingsOpenValues[row.key];
    });
  }

  function settingsUpdateDirty() {
    if (settingsSave) settingsSave.disabled = !settingsDirty();
  }

  function openSettings() {
    if (!settingsModal) return;
    call('settings', {}).then(function (res) {
      if (!res.ok || !res.settings) {
        failed('Could not open Settings', res.error || 'The server did not return anything usable.');
        return;
      }
      settingsOpenValues = res.settings;
      settingsMsg.textContent = '';
      settingsMsg.classList.remove('staxx-settings-msg--bad');
      settingsBody.innerHTML = SETTINGS_ROWS.map(function (row) {
        return settingsFieldHtml(row, res.settings[row.key] || '');
      }).join('');
      settingsSave.disabled = true;
      settingsModal.showModal();
      // Explicit, and after showModal(), for the same reason every dialog in
      // this file sets focus by hand: the browser's own "first focusable
      // descendant" choice is never where anyone wants to land.
      var first = document.getElementById(SETTINGS_ROWS[0].id);
      if (first) first.focus({ preventScroll: true });
    });
  }

  // Closing with something unsaved asks first, through the project's own
  // yes/no dialog rather than window.confirm() — askConfirm() is guarded the
  // same way deleteStack() guards it, falling back to window.confirm() on a
  // stale page with no #staxx-confirm markup.
  function closeSettingsAsk() {
    if (!settingsModal || !settingsModal.open || settingsBusy) return;
    if (!settingsDirty()) { settingsModal.close(); return; }

    if (!confirmModal) {
      if (window.confirm('Settings has changes that have not been saved. Discard them?')) settingsModal.close();
      return;
    }

    askConfirm({
      title: 'Discard changes?',
      bodyHtml: '<p>Settings has changes that have not been saved.</p>',
      goLabel: 'Discard'
    }).then(function (go) {
      if (go) settingsModal.close();
    });
  }

  function saveSettings() {
    if (!settingsOpenValues || settingsBusy) return;

    var fields = {};
    SETTINGS_ROWS.forEach(function (row) { fields[row.key] = settingsControlValue(row); });

    settingsBusy = true;
    settingsSave.disabled = true;
    settingsCancel.disabled = true;
    settingsMsg.classList.remove('staxx-settings-msg--bad');
    settingsMsg.textContent = 'Saving…';

    call('settings-save', fields).then(function (res) {
      settingsBusy = false;
      settingsCancel.disabled = false;

      if (!res.ok) {
        settingsSave.disabled = false;
        settingsMsg.textContent = res.error || 'Could not save settings.';
        settingsMsg.classList.add('staxx-settings-msg--bad');
        return;
      }

      if (res.reload) {
        settingsMsg.textContent = 'Saved. The page needs to reload for this to show.';
        // The first location.reload() in this file — justified because the
        // navigation, the page's own prose about where stacks live, and the
        // flash-drive warning are all rendered on the server at page load, so
        // nothing short of a reload brings any of them up to date. (A
        // stack-folder change is reported through this same flag, which is
        // why there is no separate refreshRows() branch here: the reload
        // already covers it.)
        location.reload();
        return;
      }

      // Neither ICON_FETCH nor IMAGE_LOOKUP — the only two settings that
      // never set reload — change anything already on screen, so closing is
      // the whole of a successful save.
      settingsModal.close();
    });
  }

  if (settingsModal) {
    settingsBody.addEventListener('input', settingsUpdateDirty);
    settingsBody.addEventListener('change', settingsUpdateDirty);

    settingsBody.addEventListener('click', function (event) {
      var btn = event.target.closest('[data-browse]');
      if (!btn) return;
      var input = document.getElementById(btn.dataset.browse);
      if (input) pickerOpen(input);
    });

    settingsCancel.addEventListener('click', closeSettingsAsk);
    settingsSave.addEventListener('click', saveSettings);

    // Same hit-test every dialog here uses: <dialog> fires no backdrop click
    // of its own, because a click on the backdrop targets the dialog element.
    settingsModal.addEventListener('click', function (event) {
      if (event.target !== settingsModal || settingsBusy) return;
      var r = settingsModal.getBoundingClientRect();
      if (event.clientX < r.left || event.clientX > r.right ||
          event.clientY < r.top  || event.clientY > r.bottom) closeSettingsAsk();
    });

    document.addEventListener('keydown', function (event) {
      if (event.key !== 'Escape' || !settingsModal.open) return;
      // Two dialogs can sit on top of this one and each owns Escape while it
      // is showing: the discard question, and the folder browser opened from
      // the stack directory row. Without these checks one Escape press would
      // reach this handler too — asking twice in the first case, and in the
      // second closing the panel out from under the browser the person was
      // actually trying to back out of.
      if (confirmModal && confirmModal.open) return;
      if (picker && picker.open) return;
      // preventDefault here, not left to the dialog's own Escape-closes-me
      // action, is the same trick the outline panel, tab menu, devices panel
      // and find bar all rely on — it is what lets closeSettingsAsk() ask
      // first instead of the browser discarding silently.
      event.preventDefault();
      closeSettingsAsk();
    });

    settingsModal.addEventListener('close', function () {
      settingsOpenValues = null;
    });
  }

  /* --------------------------------------------------------- context menu -- */

  // One menu element, repopulated per row and positioned with `fixed` so it is
  // measured against the viewport. Anything else has to account for page
  // scroll and for the table's own scroll container, and gets it wrong.
  var menu      = document.getElementById('staxx-menu');
  var menuHead  = document.getElementById('staxx-menu-head');
  var menuItems = document.getElementById('staxx-menu-items');
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
    b.className = 'staxx-menu-item' + (opts.danger ? ' staxx-menu-item--danger' : '');
    b.disabled = !!opts.disabled;
    // The item is a flex row: glyph, then a column holding the label and its
    // explanation. A bare text node would become an anonymous flex item and
    // sit alongside the hint rather than above it.
    b.innerHTML = '<i class="fa fa-' + icon + '"></i>';

    var col = document.createElement('span');
    col.className = 'staxx-menu-label';

    var text = document.createElement('span');
    text.textContent = label;
    col.appendChild(text);

    if (opts.hint) {
      var s = document.createElement('span');
      s.className = 'staxx-menu-hint';
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
    d.className = 'staxx-menu-sep';
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
    var review  = d.review === '1';
    var handover = d.handover === '1';
    var inFolder = d.folder || '';

    // An imported stack awaiting review has nothing runnable — its identity
    // may belong to containers someone else already runs, so the run verbs
    // are skipped outright rather than shown disabled, which would read as a
    // fault instead of the deliberate refusal it is. A stack waiting to be
    // confirmed after a handover is in the same position, for the same
    // reason it was locked in the first place.
    if (handover) {
      menuItem('It works', 'check', function () { openHandoverAnswer(name, label, true); });
      menuItem('It does not work', 'undo', function () { openHandoverAnswer(name, label, false); });
      menuSeparator();
    } else if (review) {
      menuItem('Take over and start', 'exchange', function () { openTakeover(name, label); });
      // The lesser option, on purpose: it only removes the lock, so a
      // container already using this name is left exactly as it is and
      // starting can still fail against it. See NEEDS-REVIEW.md's own
      // wording, which this menu has to agree with.
      menuItem('Clear the lock only', 'unlock', function () { markReviewed(name, label); }, {
        hint: 'Starts nothing, and does not deal with a container already using this name.'
      });
      menuSeparator();
    }

    if (parses && !review && !handover) {
      var why = CAN_RUN ? '' : 'Docker or compose unavailable';
      menuItem(running ? 'Restart' : 'Start', running ? 'refresh' : 'play',
               function () { run(name, running ? 'restart' : 'up', afterRun('up')); },
               { disabled: !CAN_RUN, hint: why });
      menuItem('Stop', 'stop', function () { run(name, 'down', afterRun('down')); },
               { disabled: !CAN_RUN || !running });
      // A plain pull, not pull-then-up-d like the container menu's own
      // Update below: at stack scope the two halves are separate buttons.
      // This fetches the new images and leaves everything running on the old
      // ones; Restart above is what rebuilds the containers onto them, since
      // that is already what it does to apply any other change. Splitting
      // them means a pull can be left to finish on a busy stack without
      // taking it down as a side effect.
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
    var row     = trigger.closest('.staxx-container-row');
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
      var host = row && row.querySelector('.staxx-folder-name');
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
      document.querySelectorAll('.staxx-stack-row[data-in-folder="' + id + '"]'),
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
   * `hidden` never belongs on .staxx-group--folder or .staxx-group--
   * stack — each of those wrappers contains its own heading, and hiding the
   * wrapper would hide the chevron that undoes the collapse along with the
   * rows it controls: a folder gone until the page reloads, or a stack row
   * missing where the user just clicked to bring its containers back.
   *
   * .staxx-group--folder-children and .staxx-group--children are both
   * different, and deliberately so — each is hidden here as a WHOLE, not row
   * by row. The rule above reads as "never hide a group", but the reason
   * underneath it was always narrower than that: "never hide the element
   * that contains your own escape hatch". Neither wrapper holds a chevron —
   * the control that re-expands it is one level up, outside the wrapper — so
   * hiding it can never hide the thing that would undo the hiding. That is
   * also what lets staxx.css slide each one open as a single band,
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
      document.querySelectorAll('.staxx-stack-row'), function (tr) {
        tr.hidden = false;
        stackOpen[tr.dataset.stackRow] = tr.dataset.expanded === '1';
      });

    // Keyed by the same stack name as stackOpen — data-stack-children carries
    // it on the wrapper, the same way data-stack-row does on the row itself —
    // so no separate lookup is needed here.
    Array.prototype.forEach.call(
      document.querySelectorAll('.staxx-group--children'), function (g) {
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

  var addFolderBtn = document.getElementById('staxx-add-folder');
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
    // Same call shape as the stack menu's and the container menu's own Logs
    // item (buildStackMenu/buildContainerMenu above) — undefined service
    // scopes it to the whole stack, exactly as those two call sites rely on.
    // The stack name is in data-logs itself, the way data-toggle-stack above
    // carries its own subject — the button has no separate data-stack, and
    // reading one gave every click an empty stack name and "no compose file
    // found in this stack".
    if (el.dataset.logs) {
      run(el.dataset.logs, 'logs', afterRun('logs'), el.dataset.service);
      return;
    }
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

  var ROW_SELECTOR = '.staxx-stacks .staxx-row:not(.staxx-head-row)';
  var stacksGrid   = document.querySelector('.staxx-stacks');
  var rovingRow    = null;   // the element currently carrying tabindex="0"

  /* "Visible" is never `!row.hidden`: no row carries `hidden` itself. Only
   * the two wrappers do — `.staxx-group--folder-children` for a collapsed
   * folder and `.staxx-group--children` for an unexpanded stack (see
   * applyVisibility() above) — so every hidden row is hidden by an ancestor,
   * one or two levels up. closest() walks up until it finds one, which is
   * also why this stays right if the nesting gains another level.
   *
   * The attribute, not the computed style: a collapsed wrapper is deliberately
   * still `display: grid` so its height can animate (staxx.css), so
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
  // holds a `.staxx-chevron--empty` placeholder <span> with neither
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
    if (row.classList.contains('staxx-folder-row')) {
      toggleFolder(row.dataset.folderRow, chevron);
    } else if (row.classList.contains('staxx-stack-row')) {
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
  // `.staxx-group--children`, which is a SIBLING of the stack row, not
  // an ancestor of it — both hang off the same `.staxx-group--stack` —
  // so this has to climb to that shared wrapper and come back down the
  // other branch, not just walk up looking for a `.staxx-stack-row`.
  function parentOfContainer(row) {
    var group = row.closest('.staxx-group--stack');
    return group ? group.querySelector(':scope > .staxx-stack-row') : null;
  }

  // Stack row -> the folder row it is filed under, or null when it is not
  // filed in one at all (an unfiled stack has no `.staxx-group--folder`
  // ancestor to find).
  function parentOfStack(row) {
    var group = row.closest('.staxx-group--folder');
    return group ? group.querySelector(':scope > .staxx-folder-row') : null;
  }

  function parentOf(row) {
    if (row.classList.contains('staxx-container-row')) return parentOfContainer(row);
    if (row.classList.contains('staxx-stack-row'))     return parentOfStack(row);
    return null;   // a folder row is already at the top
  }

  // Folder row -> the first visible stack row filed under it. A folder
  // group never nests inside another folder group, so an ordinary
  // descendant query is unambiguous here — there is no deeper folder whose
  // rows could be mistaken for this one's.
  function firstChildOfFolder(row) {
    var group = row.closest('.staxx-group--folder');
    return group ? firstVisibleOf(group.querySelectorAll('.staxx-stack-row')) : null;
  }

  // Expandable stack row -> the first visible container row inside its own
  // `.staxx-group--children`. Scoped to THIS stack's own wrapper via
  // `:scope >` before descending into it, even though today no stack group
  // nests inside another — a leaf's `parentOfContainer()` above already
  // depends on that same one-level relationship staying true, so keeping
  // this one explicit too means a future change that broke the assumption
  // would fail loudly in both places rather than quietly in just one.
  function firstChildOfStack(row) {
    var group   = row.closest('.staxx-group--stack');
    var wrapper = group ? group.querySelector(':scope > .staxx-group--children') : null;
    return wrapper ? firstVisibleOf(wrapper.querySelectorAll('.staxx-container-row')) : null;
  }

  function firstChildOf(row) {
    if (row.classList.contains('staxx-folder-row')) return firstChildOfFolder(row);
    if (row.classList.contains('staxx-stack-row'))  return firstChildOfStack(row);
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
   * data-container="", see staxx_stack_children() in StacksTable.php),
   * where data-service is always present and is exactly the key
   * staxx.css and this file already trust elsewhere. */
  function describeRow(row) {
    if (!row) return null;
    if (row.classList.contains('staxx-folder-row')) {
      return { type: 'folder', id: row.dataset.folderRow };
    }
    if (row.classList.contains('staxx-stack-row')) {
      return { type: 'stack', name: row.dataset.stackRow };
    }
    if (row.classList.contains('staxx-container-row')) {
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
        '.staxx-container-row[data-in-stack="' + desc.stack + '"]' +
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
    // row's own class here, rather than event.target.closest('.staxx-row'),
    // is what keeps that native behaviour from also being run a second time
    // by the switch below.
    if (!row.classList || !row.classList.contains('staxx-row') ||
        row.classList.contains('staxx-head-row')) {
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
      document.querySelectorAll('.staxx-stack-row[data-project]')
    );

    kidRows = {};
    Array.prototype.forEach.call(
      document.querySelectorAll('.staxx-container-row'), function (r) {
        var key = r.dataset.inStack;
        (kidRows[key] = kidRows[key] || []).push(r);
      });
  }
  rebindStatRows();

  var strip    = document.getElementById('staxx-strip');
  var stripGpu = document.getElementById('staxx-strip-gpu');
  var stripAge = document.getElementById('staxx-strip-age');

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
    if (!values || values.length < 2) {
      if (host.firstChild) { host.innerHTML = ''; host.staxxPts = ''; }
      return;
    }

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

    var pts  = line.join(' ');
    var area = '0,' + H + ' ' + pts + ' ' + W + ',' + H;

    // A poll lands every three seconds whether anything moved or not, and a
    // stopped container's graph is the same picture every time. Remembering
    // the points on the host — a plain property, so it dies with the node when
    // the table is re-rendered and the next poll redraws from scratch — keeps
    // an unchanged graph from costing anything at all.
    if (host.staxxPts === pts) return;
    host.staxxPts = pts;

    var poly = host.querySelector('.staxx-spark-line');
    if (poly) {
      // Move the shapes already on screen instead of parsing a fresh SVG:
      // same picture, two attribute writes rather than a new subtree.
      poly.setAttribute('points', pts);
      host.querySelector('.staxx-spark-fill').setAttribute('points', area);
      return;
    }

    host.innerHTML =
      '<svg viewBox="0 0 ' + W + ' ' + H + '" width="' + W + '" height="' + H +
      '" preserveAspectRatio="none" aria-hidden="true">' +
        '<polygon class="staxx-spark-fill" points="' + area + '"/>' +
        '<polyline class="staxx-spark-line" points="' + pts + '"/>' +
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
      return '<span class="staxx-gpu-badge staxx-gpu-' + v +
             '" title="' + name + ' GPU">' +
        '<svg viewBox="0 0 16 16" width="11" height="11" aria-hidden="true">' +
          '<rect class="staxx-chip" x="3" y="3" width="10" height="10" rx="2"/>' +
          '<g class="staxx-pins">' +
            '<path d="M5.5 1v2M8 1v2M10.5 1v2M5.5 13v2M8 13v2M10.5 13v2"/>' +
            '<path d="M1 5.5h2M1 8h2M1 10.5h2M13 5.5h2M13 8h2M13 10.5h2"/>' +
          '</g>' +
        '</svg>' +
        '<span class="staxx-sr">' + name + '</span>' +
      '</span>';
    }).join('');
  }

  function cell(row, metric) {
    return row.querySelector('[data-stat="' + metric + '"]');
  }

  /* Writes only what actually changed.
   *
   * The figure is compared as the HTML we were handed, not as what the cell
   * reads back: the browser normalises what it parsed (&darr; comes back as
   * the character itself), so comparing against innerHTML would differ every
   * time and never skip anything.
   *
   * Setting an attribute or innerHTML counts as a change even when the value
   * is identical, so on a mostly idle server this is the difference between
   * rewriting every cell of every row three times a minute and touching
   * nothing at all. */
  function setCell(row, metric, text, values, peakFloor) {
    var td = cell(row, metric);
    if (!td) return;
    var value = td.querySelector('.staxx-statv');
    if (value && value.staxxTxt !== text) {
      value.innerHTML = text;
      value.staxxTxt = text;
    }
    sparkline(td.querySelector('.staxx-spark'), values, peakFloor);
  }

  // Same reasoning as setCell for the two things a row carries outside its
  // cells: a dataset key is an attribute, and a title is one too.
  function setData(row, key, value) {
    value = String(value);
    if (row.dataset[key] !== value) row.dataset[key] = value;
  }

  function setTitle(el, text) {
    if (el && el.title !== text) el.title = text;
  }

  // classList.add and classList.toggle rewrite the class attribute even when
  // the class is already in the state being asked for, and a rewritten
  // attribute is a change to anything watching the document.
  function setClass(el, name, on) {
    if (el && el.classList.contains(name) !== on) el.classList.toggle(name, on);
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
            '<span class="staxx-rx">&darr;' + rate(rx) + '</span>' +
            '<span class="staxx-tx">&uarr;' + rate(tx) + '</span>',
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
      setTitle(gpuTd, '');
    } else if (!s.gpuMeasurable) {
      setCell(row, 'gpu', badge + '<span class="staxx-na">n/a</span>', null);
      setTitle(gpuTd, s.gpuWhy || 'No per-container figure available');
    } else {
      setCell(row, 'gpu', badge + s.gpu.toFixed(1) + '<small>%</small>', h.gpu, 5);
      setTitle(gpuTd, (s.gpuVendors || []).map(function (v) {
        return GPU_NAMES[v] || v;
      }).join(' + ') + ' GPU');
    }

    // Kept on the row so folder totals can be added up without re-reading the
    // snapshot. Only stack rows are summed — see updateFolderTotals.
    setData(row, 'statCpu', s.cpu);
    setData(row, 'statMem', s.memUsed);
    setData(row, 'statNet', rx + tx);
    setData(row, 'statGpu', s.gpu);
    setData(row, 'statGpuMapped', s.gpuMapped ? '1' : '');

    // The GPU cell itself is dropped, not just left blank, for a row with
    // nothing mapped — see .staxx-row--no-gpu in staxx.css for the
    // CSS half of this. A CLASS, not a selector reading the dataset attribute
    // just above: that attribute is absent before the very first poll lands,
    // and it is ALSO absent on a folder row forever, since nothing here ever
    // touches one — this function only ever runs on a stack row or a
    // container row. Selecting on "attribute absent" would therefore hide a
    // folder's legitimate GPU aggregate along with everything else; toggling
    // a class only from code that actually knows the answer avoids that
    // trap by construction, because a folder row simply never has this
    // function called on it at all.
    setClass(row, 'staxx-row--no-gpu', !s.gpuMapped);
  }

  function blankFigures(row) {
    setCell(row, 'cpu', '—', null);
    setCell(row, 'mem', '—', null);
    setCell(row, 'net', '—', null);
    setCell(row, 'gpu', '', null);          // blank, never a dash
    setData(row, 'statCpu', '');
    setData(row, 'statGpuMapped', '');

    // No stats at all this poll — container stopped, never created, or its
    // whole stack is down — means no way to know whether a GPU is mapped
    // either. Hiding the cell here too, rather than leaving it as whatever
    // paintFigures last decided: the alternative is a class that keeps
    // whatever value it happened to have from the last time stats WERE
    // available, which is fine while a row bounces between running and
    // stopped in the ordinary case, but leaves a row that has NEVER had
    // stats (a service declared in the compose file but never started) with
    // no class at all and its GPU cell visible — showing the empty label
    // this whole feature exists to remove.
    setClass(row, 'staxx-row--no-gpu', true);
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

      var strapline = parts.join(' &nbsp;&middot;&nbsp; ');
      if (stripGpu.staxxTxt !== strapline) {      // see setCell
        stripGpu.innerHTML = strapline;
        stripGpu.staxxTxt = strapline;
      }
      stripGpu.hidden = parts.length === 0;

      // The AMD figure comes from radeontop, which watches the card as a whole
      // and has no per-process breakdown; the Intel one can be attributed to a
      // container. That is a real difference in what the numbers mean, so it is
      // said here rather than being allowed to distort how they are printed.
      setTitle(stripGpu, 'Whole-machine GPU figures. '
                       + 'The thread count is the number of separate pieces of work each card is '
                       + 'running, counted the same way for every card.');
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
    var table = document.querySelector('.staxx-stacks');
    if (table) setClass(table, 'staxx-no-gpu', !anyGpu);

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
      document.querySelectorAll('.staxx-stack-row[data-in-folder="' + id + '"]')
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
        var td = tr.querySelector('[data-stat="' + metric + '"] .staxx-statv');
        if (td && td.staxxTxt !== text) {        // see setCell
          td.innerHTML = text;
          td.staxxTxt = text;
        }
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

  // The applications catalogue refreshes itself here rather than waiting for
  // somebody to open the Apps dialog, so it is normally already current by the
  // time they do — and on a server that has just booted, where /tmp is RAM and
  // the cache is simply gone, the first build runs while the stack list is
  // being read. Deferred so it never competes with the table, the state
  // refresh and the icon sweep. The reply says nothing and nothing is shown:
  // a catalogue failure belongs in the dialog that asked for it.
  setTimeout(function () { call('ca-refresh', {}, 10000); }, 2000);

  if (CAN_RUN) {
    pollStats();
    statsTimer = setInterval(pollStats, STATS_POLL);
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) pollStats();
    });
  }

  // The signpost page (Settings → StaXX) links here to open the
  // panel directly, for whoever followed it from the Plugins list.
  if (location.hash === '#settings') openSettings();
})();
