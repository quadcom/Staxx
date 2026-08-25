<?PHP
/* StaXX — the Stacks screen.
 * Copyright 2026, StaXX contributors.
 *
 * Included by both Stacks.page (tab under the Docker menu) and
 * StaXX.page (own button in the top navigation bar). Exactly one of those is
 * enabled at a time, decided by the HEADER_MENU config key, so this file is
 * only ever rendered once per request.
 *
 * Every class used here is our own. Stock Unraid classes are not borrowed for
 * layout: their rules are invisible to us and change between releases.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Folders.php';
require_once '/usr/local/emhttp/plugins/staxx/include/StacksTable.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Autostart.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Updates.php';
// Only for staxx_settings_read(), to read the remembered PLAN_68 Part B
// storage-choice below — nothing else here writes a setting.
require_once '/usr/local/emhttp/plugins/staxx/include/Settings.php';

$compose       = staxx_compose();
$dockerRunning = staxx_docker_running();
$updateState   = staxx_update_state();
$projects      = staxx_containers_by_project();
$stacks        = staxx_list_stacks();
// Reconcile with Unraid's boot list before the layout is worked out, since
// adopting a change made on its own Docker page can reorder what follows.
// $syncError is kept and shown below rather than discarded: a failed write
// here means the boot markers the table is about to draw describe a state
// that was never actually written, which is worse than a banner nobody
// wanted to see.
$syncError = '';
staxx_autostart_sync($stacks, $syncError);
$rows          = staxx_folder_layout($stacks);
$folders       = staxx_folder_names();
$root          = staxx_stack_root();
$canRun        = $compose['available'] && $dockerRunning;
// PLAN_68 Part B: the same "is this on flash" test the volume-path warning
// below already makes, reused rather than asked a second way. Read straight
// from the settings allowlist's own getter rather than staxx_cfg() directly,
// so a config predating this key still gets the right default ('ask') and a
// masked/coerced value is never possible here either.
$onFlash       = strpos($root, '/boot/') === 0;
$storageChoice = staxx_settings_read()['STORAGE_CHOICE'];

$stackCount     = count(array_filter(array_keys($projects), fn($p) => $p !== ''));
$unmanagedCount = count($projects[''] ?? []);

$vars = @parse_ini_file('/var/local/emhttp/var.ini') ?: [];
$csrf = (string)($vars['csrf_token'] ?? '');

// Both assets carry the file's modification time in the URL. Without it an
// edited stylesheet or script sits in the browser cache and the page appears
// not to have changed at all — which costs a great deal of time to diagnose,
// because it looks exactly like a change that did not work.
$assets  = '/plugins/'.STAXX_PLUGIN;
$jsFile  = STAXX_ROOT.'/javascript/stacks.js';
$modelFile = STAXX_ROOT.'/javascript/compose-model.js';
$caFile  = STAXX_ROOT.'/javascript/ca-convert.js';
$imageFile = STAXX_ROOT.'/javascript/image-import.js';
// The Manage tab's own script and stylesheet (PLAN_44 phase 2) — written by a
// separate agent in parallel with this file, so neither is guaranteed to exist
// yet at any given moment. Guarded the same way the three scripts above are:
// a missing file costs a 404 in the console, not a broken page.
$manageJsFile  = STAXX_ROOT.'/javascript/manage.js';
$manageCssFile = STAXX_ROOT.'/sheets/manage.css';
$cssFile = STAXX_ROOT.'/sheets/staxx.css';
$jsTag   = $assets.'/javascript/stacks.js?v='.(is_file($jsFile) ? filemtime($jsFile) : '0');
$modelTag = $assets.'/javascript/compose-model.js?v='.(is_file($modelFile) ? filemtime($modelFile) : '0');
$caTag   = $assets.'/javascript/ca-convert.js?v='.(is_file($caFile) ? filemtime($caFile) : '0');
$imageTag = $assets.'/javascript/image-import.js?v='.(is_file($imageFile) ? filemtime($imageFile) : '0');
$manageJsTag  = $assets.'/javascript/manage.js?v='.(is_file($manageJsFile) ? filemtime($manageJsFile) : '0');
$manageCssTag = $assets.'/sheets/manage.css?v='.(is_file($manageCssFile) ? filemtime($manageCssFile) : '0');
$cssTag  = $assets.'/sheets/staxx.css?v='.(is_file($cssFile) ? filemtime($cssFile) : '0');

// Password managers ignore autocomplete="off" — that attribute only speaks to
// the browser's own autofill. They read the words around a box instead, and a
// lone name box on a dialog reads to them like a username. There is no
// standard way to say "not a login field", so each manager's own opt-out is
// set: 1Password, LastPass, Bitwarden, Dashlane, Proton Pass. The form's own
// boxes are built in JavaScript and carry the same list as NOFILL there.
$nofill = 'autocomplete="off" data-1p-ignore data-lpignore="true" '
        . 'data-bwignore data-form-type="other" data-protonpass-ignore="true"';

if (!function_exists('staxx_status_row')):
function staxx_status_row(string $label, bool $ok, string $detail): void {
  $icon = $ok ? 'fa-check green-text' : 'fa-times-circle red-text';
  echo '<div class="staxx-row" role="row">';
  echo   '<span class="staxx-cell" role="cell"><i class="fa ', $icon, '"></i> ', htmlspecialchars($label), '</span>';
  echo   '<span class="staxx-cell" role="cell">', htmlspecialchars($detail), '</span>';
  echo '</div>';
}
endif;
?>

<link rel="stylesheet" href="<?= $cssTag ?>">
<!-- The Manage tab's own stylesheet, kept separate on purpose so it cannot
     collide with anything below — see the comment on $manageCssFile above. A
     missing file 404s quietly; nothing here depends on it loading. -->
<link rel="stylesheet" href="<?= $manageCssTag ?>">

<!-- `unapi` is Unraid's own opt-out marker, not a styling class. Its only
     appearances in webGui/styles are inside :not(.unapi *) guards on 88 rules
     that would otherwise restyle every <button>, <input> and <textarea> on the
     page — uppercasing our menu labels, giving each item a 10px margin, and
     painting the row icons as 86px orange gradient slabs. Specificity alone
     cannot undo those, because the :hover variants outrank a plain class.
     Nothing in Unraid's JavaScript or PHP reads this class. -->
<div class="staxx-scaffold unapi"
     data-csrf="<?= htmlspecialchars($csrf) ?>"
     data-endpoint="<?= $assets ?>/include/action.php"
     data-canrun="<?= $canRun ? '1' : '0' ?>"
     data-folders="<?= htmlspecialchars(json_encode(array_map(
         fn($f) => ['id' => $f, 'name' => $f], $folders
     )), ENT_QUOTES) ?>"
     data-appdata="<?= htmlspecialchars(staxx_appdata_root()) ?>">

  <!-- Only conditions that need acting on get a banner here. The standing
       "this is alpha" notice is gone: a banner shown on every visit stops
       being read, and takes the top of the page with it.

       Deliberately not Unraid's .notice class. Borrowing a stock class means
       inheriting layout rules we do not control and cannot see change. -->
  <? if ($csrf === ''): ?>
    <!-- Unraid's own CSRF check rejects every POST this page makes once
         csrf_token is missing from var.ini, and it does so silently from
         here — the request just comes back refused. Said outright, because
         nothing else on the page would explain why every button seems to do
         nothing. -->
    <div class="staxx-notice staxx-notice--bad">
      <i class="fa fa-times-circle" aria-hidden="true"></i>
      <div>
        <strong><?= _('The security token this page needs was not found.') ?></strong>
        <?= _('Every action on this page will be refused until you reload it. If reloading does not help, log in to the webGUI again.') ?>
      </div>
    </div>
  <? endif; ?>

  <? if (!$compose['available']): ?>
    <div class="staxx-notice staxx-notice--bad">
      <i class="fa fa-times-circle" aria-hidden="true"></i>
      <div>
        <strong><?= _('Compose is not installed.') ?></strong>
        <?= _('Stacks can be written and edited, but nothing can be started until compose is available.') ?>
      </div>
    </div>
  <? elseif (!$dockerRunning): ?>
    <div class="staxx-notice staxx-notice--bad">
      <i class="fa fa-times-circle" aria-hidden="true"></i>
      <div>
        <strong><?= _('The Docker service is not running.') ?></strong>
        <?= _('Start it under Settings → Docker before running anything here.') ?>
      </div>
    </div>
  <? endif; ?>

  <!-- A warning, not a failure: Docker Hub answering "no more for now" is a
       temporary limit with a clear fix, and it clears itself the moment a
       later check succeeds. Deliberately plain .staxx-notice, without --bad
       — the unmodified class already borders and tints its icon with the
       accent colour, which is what --hint--warn below also uses for "worth
       a look, not broken"; --bad exists precisely to override that to red for
       the two "act now" conditions above, which this is not. -->
  <? if (!empty($updateState['limited'])): ?>
    <div class="staxx-notice">
      <i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
      <div>
        <strong><?= _('Docker Hub has stopped answering questions about images from this server for now.') ?></strong>
        <?= _('Signing in to a Docker Hub account in the') ?> <button type="button" id="staxx-open-hub-settings" class="staxx-link-btn"><?= _('settings panel') ?></button> <?= _('raises the limit, or you can just leave it to try again later.') ?>
      </div>
    </div>
  <? endif; ?>

  <!-- Said by the page itself rather than by a dialog, for cases with no
       dialog to say it in: an install caught on Unraid's Add Container page
       that StaXX then could not convert, or (Phase F) a container that
       appeared belonging to no stack, offering the same importer the "Import"
       button opens. Filled in by showPageNotice() in stacks.js, which also
       switches the icon/colour and shows or hides the button depending on
       whether this is an error or an offer. -->
  <div class="staxx-notice staxx-notice--bad" id="staxx-page-notice" hidden>
    <i class="fa fa-times-circle" aria-hidden="true"></i>
    <div></div>
    <button type="button" class="staxx-link-btn" id="staxx-page-notice-action" hidden></button>
  </div>

  <!-- A real failure, not a warning: the boot markers this table is about to
       draw come from what staxx_autostart_sync() intended to write, and if
       that write failed they describe a state Unraid's own boot list never
       actually reached. -->
  <? if ($syncError !== ''): ?>
    <div class="staxx-notice staxx-notice--bad">
      <i class="fa fa-times-circle" aria-hidden="true"></i>
      <div>
        <strong><?= _('The boot-start list could not be updated.') ?></strong>
        <?= htmlspecialchars($syncError) ?>
      </div>
    </div>
  <? endif; ?>

  <!-- PLAN_68 Part B: a one-time offer, not a standing warning — shown only
       while the stacks folder is still on flash AND nobody has answered this
       yet. "Not now" (wired in stacks.js) writes STORAGE_CHOICE=declined so
       this never asks twice; the settings panel's own line for STACK_ROOT is
       the way back for anyone who changes their mind, per PLAN_68 section 5. -->
  <? if ($onFlash && $storageChoice === 'ask'): ?>
    <div class="staxx-notice" id="staxx-storage-banner">
      <i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
      <div>
        <strong><?= _('Your stacks are stored on the USB flash drive.') ?></strong>
        <?= _('Flash storage can only be written a limited number of times before it wears out, and it is the least redundant thing in the machine. A drive pool is available before Docker even starts, so nothing is gained by staying on flash.') ?>
        <div class="staxx-buttons staxx-buttons--inline">
          <button type="button" class="staxx-btn staxx-btn--primary" id="staxx-storage-banner-open"><?= _('Choose a location') ?></button>
          <button type="button" class="staxx-btn" id="staxx-storage-banner-decline"><?= _('Not now') ?></button>
        </div>
      </div>
    </div>
  <? endif; ?>

  <!-- ---------------------------------------------------------- stacks -- -->

  <!-- No heading: Unraid's own title bar directly above already names the
       page, so a second "Stacks" under it said the same thing twice. --end
       keeps the buttons in the corner they were in when a heading held the
       other side of the row; the plain .staxx-bar is still space-between for
       the log panel, which does have a heading. -->
  <div class="staxx-bar staxx-bar--end">
    <div class="staxx-buttons staxx-buttons--inline">
      <button type="button" class="staxx-btn" id="staxx-settings-btn">
        <i class="fa fa-cog"></i> <?= _('Settings') ?>
      </button>
      <button type="button" class="staxx-btn" id="staxx-diagnose">
        <i class="fa fa-stethoscope"></i> <?= _('Self-test') ?>
      </button>
      <button type="button" class="staxx-btn" id="staxx-add-folder">
        <i class="fa fa-folder"></i> <?= _('New folder') ?>
      </button>
      <button type="button" class="staxx-btn" id="staxx-apps">
        <i class="fa fa-th"></i> <?= _('Apps') ?>
      </button>
      <button type="button" class="staxx-btn" id="staxx-import">
        <i class="fa fa-download"></i> <?= _('Import') ?>
      </button>
      <button type="button" class="staxx-btn staxx-btn--primary" id="staxx-add">
        <i class="fa fa-plus"></i> <?= _('Add stack') ?>
      </button>
      <button type="button" class="staxx-btn" id="staxx-check-updates">
        <i class="fa fa-refresh"></i> <?= _('Check for updates') ?>
      </button>
      <button type="button" class="staxx-btn" id="staxx-update-all">
        <i class="fa fa-download"></i> <?= _('Update all') ?>
      </button>
      <!-- Icon and label are repainted by paintPauseButton() from the
           `paused` key the updates poll carries — this is state, not a
           setting, so nothing here has to guess which way it starts. -->
      <button type="button" class="staxx-btn" id="staxx-update-pause">
        <i class="fa fa-pause"></i> <?= _('Pause updates') ?>
      </button>
    </div>
  </div>

  <!-- Filled in by refreshUpdates() the moment the page runs — there is
       nothing to say about update checking before that first reply lands. -->
  <p class="staxx-hint" id="staxx-updates-line"></p>

  <!-- PLAN_45 phase 4-8. Hidden until update-queue-start begins one, and
       painted entirely by paintUpdateQueue() in stacks.js — a queue's own
       progress is polled from the browser, not part of the page's render. -->
  <div class="staxx-updatequeue" id="staxx-update-queue" hidden></div>

  <p class="staxx-hint">
    <?= sprintf(_('Each stack is a folder holding one compose file, kept in %s.'), htmlspecialchars($root)) ?>
    <?= _('Anything you put there by hand shows up here, and anything added here is an ordinary compose file you can copy elsewhere.') ?>
  </p>

  <!-- Machine-wide figures, kept separate from the table on purpose.
       Per-container GPU comes from /proc/<pid>/fdinfo and works for both Intel
       and AMD, so the table's own GPU column is the real reading. What is shown
       here is the whole card, including whatever is using it from outside
       docker — and it is labelled as the whole machine's rather than being
       divided between containers, which would be a guess wearing a number's
       clothes. -->
  <div class="staxx-strip" id="staxx-strip" hidden>
    <span class="staxx-strip-item" id="staxx-strip-gpu" hidden></span>
    <span class="staxx-strip-item" id="staxx-strip-age"></span>
  </div>

  <!-- The table is always here, even with nothing in it.
       The browser replaces this table's body in place rather than reloading
       the page, so there has to be a body to replace — a page that swapped the
       whole table for a paragraph when the last stack was deleted would leave
       nothing to put the next one into. "No stacks yet" is a row inside it. -->
  <div class="staxx-table-wrap">
    <div class="staxx-stacks" role="treegrid" aria-label="<?= _('Stacks') ?>">
      <div class="staxx-row staxx-head-row" role="row">
        <span class="staxx-cell" role="columnheader"><?= _('Stack') ?></span>
        <span class="staxx-cell" role="columnheader"><?= _('Services') ?></span>
        <span class="staxx-cell" role="columnheader"><?= _('State') ?></span>
        <span class="staxx-cell" role="columnheader"><?= _('Address') ?></span>
        <span class="staxx-cell staxx-num" role="columnheader" data-stat="cpu"><?= _('CPU') ?></span>
        <span class="staxx-cell staxx-num" role="columnheader" data-stat="mem"><?= _('Memory') ?></span>
        <span class="staxx-cell staxx-num" role="columnheader" data-stat="net"><?= _('Network') ?></span>
        <!-- data-stat on the heading too, so the whole column can be hidden
             with one rule when nothing on the page has a GPU. -->
        <span class="staxx-cell staxx-num" role="columnheader" data-stat="gpu"><?= _('GPU') ?></span>
      </div>

      <!-- Rendered by the same function the JSON endpoint calls, so a row that
           arrives without a page load is identical to one that arrived with
           it. See include/StacksTable.php. -->
      <div class="staxx-body" id="staxx-rows" role="rowgroup"><?= staxx_render_rows($rows, $canRun) ?></div>
    </div>
  </div>

  <!-- One menu, reused by every row, and attached to the page rather than to a
       table cell. A menu nested inside the scrolling table container would be
       clipped by it the moment it opened. -->
  <div class="staxx-menu" id="staxx-menu" role="menu" hidden>
    <div class="staxx-menu-head" id="staxx-menu-head"></div>
    <div id="staxx-menu-items"></div>
  </div>

  <!-- ---------------------------------------------------------- editor -- -->

  <!-- A <dialog>, not a panel, and deliberately a direct child of the scaffold.
       Two things depend on that placement. It has to sit OUTSIDE
       .staxx-table-wrap, which sets container-type: inline-size and would
       otherwise contain a fixed-position descendant — the same reason the menu
       above is a sibling of the table. And it has to sit INSIDE the scaffold,
       because the --sm-* colour tokens are scoped there; showModal() promotes
       the dialog to the top layer, which changes painting and stacking order
       only, so inheritance still follows the DOM and the tokens survive.

       No <form> wrapper. A form would submit implicitly on Enter in the name
       field, and with method="dialog" that closes the dialog and throws the
       edit away. -->
  <dialog class="staxx-modal" id="staxx-modal" data-tab="configure" aria-labelledby="staxx-modal-title">

    <div class="staxx-modal-head">
      <h3 class="staxx-modal-title" id="staxx-modal-title"><?= _('New stack') ?></h3>

      <!-- "Stack name", not "Folder". It is the directory holding the compose
           file, and calling it a folder made it read as the folder in the
           list — which is a different thing sitting one level above it. That
           one is shown beside it, as context, and is changed with "Move to
           folder". The hint is a visible line rather than a title attribute,
           because a tooltip cannot be reached on a phone and this sentence is
           the one that tells the two apart. -->
      <label class="staxx-modal-name" id="staxx-name-field">
        <span><?= _('Stack name') ?></span>
        <span class="staxx-name-folder" id="staxx-name-folder" hidden></span>
        <input type="text" id="staxx-name" spellcheck="false" <?= $nofill ?>
               placeholder="<?= _('jellyfin') ?>">
        <span class="staxx-name-hint"><?= _("The folder that holds this stack's compose file. Renaming it moves the folder.") ?></span>
      </label>

      <div class="staxx-modal-tools">
        <label class="staxx-sanitise" title="<?= _('Hide values marked sensitive, for taking a screenshot') ?>">
          <input type="checkbox" id="staxx-sanitise">
          <span><?= _('Sanitise') ?></span>
        </label>
        <!-- Positioning wrapper only, same job as .staxx-sections above the
             Sections button: the panel hangs from this box, not from the
             button itself, so it can sit flush against the button's edge
             regardless of where that button ends up in the flex row. Script
             fills #staxx-outline with rows and toggles hidden. -->
        <div class="staxx-outlinewrap">
          <button type="button" class="staxx-btn staxx-outlinebtn" id="staxx-outline-btn" aria-expanded="false"
                  title="<?= _('Jump to a block or service in the compose file') ?>">
            <i class="fa fa-list-ul" aria-hidden="true"></i> <?= _('Outline') ?>
          </button>
          <div class="staxx-outline" id="staxx-outline" role="menu" hidden></div>
        </div>
        <!-- Split is the one anyone wants on a desktop, and meaningless on a
             phone: two panes of twenty characters each are worse than either
             one alone. So its button is hidden below 45rem and the editor
             opens on Form there instead. Hidden rather than removed, because
             which it is depends on the window and can change under you. -->
        <div class="staxx-views" role="group" aria-label="<?= _('View') ?>">
          <button type="button" class="staxx-viewbtn" data-view="form"  aria-pressed="false"><?= _('Form') ?></button>
          <button type="button" class="staxx-viewbtn staxx-viewbtn--split" data-view="split" aria-pressed="true"><?= _('Split') ?></button>
          <button type="button" class="staxx-viewbtn" data-view="yaml"  aria-pressed="false"><?= _('Compose') ?></button>
        </div>
        <button type="button" class="staxx-btn" id="staxx-modal-close">
          <i class="fa fa-times"></i> <?= _('Close') ?>
        </button>
      </div>
    </div>

    <!-- PLAN_44 C1. Configure is the form/split/compose editing everything
         above already did; Manage (PLAN_44 Part D) is a live console for a
         running or stopped container — a shell, a log and a file browser —
         built by a separate agent into #staxx-modal-manage below. Which one
         shows is the .staxx-modal's own data-tab attribute, read by the
         stylesheet (see the ~2454 comment there for the one trap it has to
         dodge) and set by stacks.js's setTab(). Copies .staxx-tabstrip/
         .staxx-tab wholesale from the file-tab strip further down rather than
         .staxx-ca-tab, so the dialog is internally consistent with the one
         tab strip it already had.

         History (PLAN_68 Part A piece 3) is a third peer here rather than a
         control tucked inside Configure, because it is a property of the
         file being edited, not of whatever the editor happens to be showing
         right now — burying it behind Configure's own controls would hide it
         exactly when somebody has clicked away from the file that has a
         history. It is disabled (native `disabled`, not a class — see
         .staxx-tab:disabled in the stylesheet) whenever Sanitise is on: an
         old version holds the real, unhidden values, and showing it would
         defeat Sanitise outright.

         Versions (PLAN_82 Part 2 step 2) is a fourth peer, for the same
         reason — which build of an image has actually run. Unlike History it
         is never disabled under Sanitise: an image name and a version are
         not values the author wrote, and are not hidden by it. -->
    <div class="staxx-tabstrip staxx-modal-tabstrip" role="tablist" aria-label="<?= _('Editor section') ?>">
      <button type="button" class="staxx-tab" id="staxx-tab-configure" role="tab"
              aria-selected="true" data-tab="configure"><?= _('Configure') ?></button>
      <button type="button" class="staxx-tab" id="staxx-tab-manage" role="tab"
              aria-selected="false" data-tab="manage"><?= _('Manage') ?></button>
      <button type="button" class="staxx-tab" id="staxx-tab-history" role="tab"
              aria-selected="false" data-tab="history"><?= _('History') ?></button>
      <button type="button" class="staxx-tab" id="staxx-tab-versions" role="tab"
              aria-selected="false" data-tab="versions"><?= _('Versions') ?></button>
    </div>

    <div class="staxx-notice staxx-modal-banner" id="staxx-sanitise-note" hidden>
      <i class="fa fa-eye-slash" aria-hidden="true"></i>
      <div>
        <strong><?= _('Sanitised for screenshots.') ?></strong>
        <?= _('Values marked sensitive are hidden and nothing can be changed. Turn Sanitise off to make edits. The real values are still in the page — this hides them from a picture, not from anyone with access to this browser.') ?>
      </div>
    </div>

    <!-- An install caught on Unraid's own Add Container page opens the editor
         with the app already converted, which is a surprising thing to be
         looking at with no explanation of how it got there. Filled in and
         shown by the handoff hook in stacks.js; plain .staxx-notice, not
         --bad, because nothing is wrong. -->
    <div class="staxx-notice staxx-modal-banner" id="staxx-install-note" hidden>
      <i class="fa fa-download" aria-hidden="true"></i>
      <div></div>
      <!-- PLAN_63 section 16: the way out of a caught install. Filled in and
           shown/hidden by caOpenConverted() in stacks.js — only when the
           handoff behind this had an original Unraid request AND the
           temp file it points at is still there for Unraid to read. -->
      <a href="#" class="staxx-link-btn" id="staxx-install-note-escape" hidden><?= _('Let Unraid install this instead') ?></a>
    </div>

    <div class="staxx-modal-body" data-view="split">

      <div class="staxx-pane staxx-pane--form">
        <!-- Why the form is locked, shown only while a companion file's tab is
             open. It sits above #staxx-form rather than inside it because
             reparse() replaces that element's contents wholesale, and it would
             go with them. Script fills in the filename. -->
        <p class="staxx-refnote" id="staxx-refnote" hidden></p>
        <div class="staxx-form" id="staxx-form">
          <!-- Replaced by the real form as soon as the file is parsed, so it is
               only ever seen for an instant — but this pane is on screen from
               the moment the editor opens, so that instant is visible. -->
          <p class="staxx-form-empty">
            <?= _('Reading the compose file…') ?>
          </p>
        </div>
      </div>

      <div class="staxx-pane staxx-pane--yaml">
        <!-- Always visible now, not only when there is more than one tab —
             it also carries the New file and Add a file controls, and
             hiding the strip would hide those with it. Filled by script
             (renderTabs() in stacks.js) with one button per file in the
             stack's own folder; the compose file's own tab is pinned first
             and cannot be closed — everything else in the folder follows it
             alphabetically. -->
        <div class="staxx-tabstrip">
          <div class="staxx-tabs" id="staxx-tabs" role="tablist" aria-label="<?= _('Files in this stack') ?>"></div>
          <button type="button" class="staxx-chevron" id="staxx-file-new"
                  title="<?= _('Add a new, empty file to this stack') ?>"
                  aria-label="<?= _('New file') ?>">
            <i class="fa fa-plus" aria-hidden="true"></i>
          </button>
          <button type="button" class="staxx-chevron" id="staxx-file-add"
                  title="<?= _('Add a file from this computer') ?>"
                  aria-label="<?= _('Add a file from this computer') ?>">
            <i class="fa fa-upload" aria-hidden="true"></i>
          </button>
          <!-- Never shown itself. Both buttons above click it open, and so
               does Replace… on the binary panel further down — for that one
               it is switched to single-file for the one pick, because a
               replacement keeps the name already on the tab regardless of
               what the chosen file is called (see stacks.js). -->
          <input type="file" id="staxx-file-input" multiple hidden>
        </div>
        <!-- The active tab's menu (Rename / Delete / Download). Not
             #staxx-menu — that one lives outside this dialog, and a
             <dialog> opened with showModal() paints in the top layer above
             anything outside it. A plain sibling of the strip rather than
             nested inside it, because the strip scrolls sideways and script
             positions this in pixels against whichever chevron opened it —
             see openTabmenu() in stacks.js. -->
        <div class="staxx-tabmenu" id="staxx-tabmenu" role="menu" hidden></div>
        <!-- Hidden until script opens it (Ctrl+F inside the compose pane).
             Unhiding it, running the search itself, and painting the
             .staxx-hit boxes into #staxx-yamlmarks below are all
             script's job — this is markup only. The replace row is a second
             line inside the same bar (see .staxx-find-replace in the
             stylesheet), shown only when replace, not plain find, is open. -->
        <div class="staxx-find" id="staxx-find" hidden>
          <label class="staxx-sr" for="staxx-find-what"><?= _('Find in compose file') ?></label>
          <input type="text" id="staxx-find-what" class="staxx-find-input"
                 spellcheck="false" <?= $nofill ?>
                 placeholder="<?= _('find in this file') ?>">
          <span class="staxx-find-count" id="staxx-find-count"></span>
          <button type="button" class="staxx-chevron" id="staxx-find-prev"
                  title="<?= _('Previous match') ?>" aria-label="<?= _('Previous match') ?>">
            <i class="fa fa-chevron-up"></i>
          </button>
          <button type="button" class="staxx-chevron" id="staxx-find-next"
                  title="<?= _('Next match') ?>" aria-label="<?= _('Next match') ?>">
            <i class="fa fa-chevron-down"></i>
          </button>
          <label class="staxx-sanitise">
            <input type="checkbox" id="staxx-find-case">
            <span><?= _('Match case') ?></span>
          </label>
          <label class="staxx-sanitise">
            <input type="checkbox" id="staxx-find-regex">
            <span><?= _('Regex') ?></span>
          </label>
          <button type="button" class="staxx-chevron" id="staxx-find-close"
                  title="<?= _('Close find') ?>" aria-label="<?= _('Close find') ?>">
            <i class="fa fa-times"></i>
          </button>

          <div class="staxx-find-replace" id="staxx-find-replacerow" hidden>
            <label class="staxx-sr" for="staxx-find-with"><?= _('Replace with') ?></label>
            <input type="text" id="staxx-find-with" class="staxx-find-input"
                   spellcheck="false" <?= $nofill ?>
                   placeholder="<?= _('replace with') ?>">
            <button type="button" class="staxx-btn" id="staxx-find-one"><?= _('Replace') ?></button>
            <button type="button" class="staxx-btn" id="staxx-find-all"><?= _('Replace all') ?></button>
          </div>
        </div>
        <!-- The highlight bands are painted as absolutely positioned divs
             behind a transparent-background textarea, so the wrapper carries
             the colour. It works without mirroring the text because the box
             never wraps (wrap="off", white-space: pre) and the line height is
             fixed, which puts line N at exactly (N-1) x lineHeight. -->
        <div class="staxx-yamlwrap" id="staxx-yamlwrap">
          <!-- The gutter is painted OVER the textarea, not beside it. A
               textarea scrolls its own padding away horizontally, so a long
               line would otherwise slide out from under the numbers; an opaque
               gutter on top is what hides it, which is what every code editor
               does. aria-hidden because line numbers read aloud are noise. -->
          <!-- Second inner div holds one dot per flagged line (script sets its
               own top offset in pixels and a title with the message), kept as
               a sibling after the numbers div because script reaches that one
               as firstElementChild. -->
          <div class="staxx-yamlnums" id="staxx-yamlnums" aria-hidden="true"><div></div><div class="staxx-yamldots"></div></div>
          <div class="staxx-yamlmarks" id="staxx-yamlmarks" aria-hidden="true"></div>
          <!-- Syntax colour, same trick again: a coloured copy of the text
               behind a textarea whose own text is made transparent. Marks,
               then ink, then the textarea last — in that order — so the
               textarea's selection highlight paints over the ink, and the
               gutter (z-index: 2) stays over both. aria-hidden for the same
               reason as the gutter: it is a decorative duplicate, not content. -->
          <div class="staxx-yamlink" id="staxx-yamlink" aria-hidden="true"><div></div></div>
          <textarea class="staxx-yaml" id="staxx-yaml" wrap="off" spellcheck="false"
                    autocomplete="off" autocapitalize="off" autocorrect="off"
                    aria-label="<?= _('Compose file') ?>"
                    placeholder="services:&#10;  example:&#10;    image: alpine:3.20&#10;    command: [&quot;sleep&quot;, &quot;infinity&quot;]"></textarea>
          <!-- Key-suggestion list. Script fills it with rows and positions it
               with inline left/top in pixels, following the caret; hidden
               until there is something to suggest. -->
          <div class="staxx-suggest" id="staxx-suggest" role="listbox" hidden></div>
          <!-- Hover panel explaining the compose key under the pointer.
               pointer-events: none in the stylesheet, so it can never sit
               between the pointer and the text that spawned it. -->
          <div class="staxx-keyhelp" id="staxx-keyhelp" role="tooltip" hidden></div>
          <!-- Shown instead of the textarea for a companion file that is not
               text — a certificate or a key, most often, which is the whole
               reason a binary file is accepted here at all. It covers the
               box rather than replacing it, so nothing about the editor's
               own layout has to change; script shows and hides it in
               openFile() and loadCompanion() (stacks.js). -->
          <div class="staxx-binfile" id="staxx-binfile" hidden>
            <div class="staxx-binfile-name" id="staxx-binfile-name"></div>
            <div class="staxx-binfile-meta" id="staxx-binfile-meta"></div>
            <div class="staxx-buttons staxx-buttons--inline">
              <button type="button" class="staxx-btn" id="staxx-binfile-get"><?= _('Download') ?></button>
              <button type="button" class="staxx-btn" id="staxx-binfile-put"><?= _('Replace…') ?></button>
            </div>
          </div>
        </div>
        <div class="staxx-yamlstatus" id="staxx-yaml-status" role="status" aria-live="polite"></div>
      </div>

    </div>

    <!-- Empty on purpose. The other PLAN_44 agent's manage.js fills this in
         via window.StaxxManage.create({ host: this element, ... }) the first
         time the Manage tab is opened — see ensureManage()/mountManageFor-
         CurrentStack() in stacks.js. Shown only while data-tab="manage" on
         the dialog above; .staxx-modal-body stays in the DOM meanwhile
         rather than being hidden and re-shown, which is what the width-clamp
         comment at staxx.css:~2454 has to work around. -->
    <div class="staxx-modal-manage" id="staxx-modal-manage"></div>

    <!-- Empty on purpose, same reasoning as #staxx-modal-manage above:
         stacks.js fills this the first time the History tab is opened and
         owns everything inside it — the list of previous versions, the
         read-only version view, the Restore button, and (while Sanitise is
         on) the "not available" notice in its place. Built on demand rather
         than with the dialog, because most people never open this tab.
         Shown only while data-tab="history" on the dialog above. -->
    <div class="staxx-modal-history" id="staxx-modal-history"></div>

    <!-- Empty on purpose, same reasoning as #staxx-modal-history above:
         stacks.js fills this the first time the Versions tab is opened — the
         service list, each one's recorded versions, and "Put this back".
         Shown only while data-tab="versions" on the dialog above. Left
         enabled under Sanitise, unlike History's own tab button just above —
         see the tab strip comment further up for why. -->
    <div class="staxx-modal-versions" id="staxx-modal-versions"></div>

    <div class="staxx-modal-foot">
      <div class="staxx-error" id="staxx-error" hidden></div>

      <!-- A button, not a paragraph: naming the file that is not there is
           only half the point — clicking it creates that file, which is why
           this is stacks.js's updateMissing()/createMissingFile(), not a
           second one-shot "paste bar" as PLAN_13 first sketched it. -->
      <button type="button" class="staxx-missing" id="staxx-missing" hidden></button>

      <!-- Same shape and job as #staxx-missing above, but for a volume's
           HOST side rather than a file inside the stack: clicking it asks the
           server to create the folder(s) checkHostPaths() found nothing at,
           rather than leaving Docker to make them at 99:100-unfriendly
           root:root 755 on first start. See stacks.js's updateMissingPaths(). -->
      <button type="button" class="staxx-missing staxx-missing--paths" id="staxx-makepaths" hidden></button>

      <!-- Same warning colour and weight as #staxx-makepaths above, but a
           paragraph, not a button: a folder found already full of data while
           creating a new stack is something to check by eye, not something
           this plugin can safely act on for you. See stacks.js's
           updateInUsePaths(). -->
      <p class="staxx-missing staxx-missing--inuse" id="staxx-inusepaths" hidden></p>

      <!-- A button, not a paragraph: it says which field is empty AND takes
           you to it, which is the whole reason it is worth showing. -->
      <button type="button" class="staxx-required" id="staxx-required-note" hidden></button>

      <div class="staxx-modal-actions">
        <p class="staxx-hint staxx-modal-note">
          <?= _('Saved exactly as written — comments, spacing and ordering are kept as they are. This stays a standard compose file that runs anywhere.') ?>
          <? if (strpos($root, '/boot/') === 0): ?>
            <span class="staxx-hint--warn">
              <i class="fa fa-exclamation-triangle"></i>
              <?= _('Stacks are currently stored on the USB flash drive. A volume written as a relative path — <code>./data:/config</code> — would put container data on the flash drive, which is small, slow, and wears out. Use a full path such as <code>/mnt/user/appdata/name:/config</code> instead.') ?>
            </span>
          <? endif; ?>
        </p>

        <div class="staxx-buttons staxx-buttons--inline">
          <!-- Covers adding and removing an entry, and nothing else. Typing
               already has the browser's own undo inside each box and inside
               the compose pane; a second stack competing with that one would
               be worse than none. -->
          <button type="button" class="staxx-btn" id="staxx-undo" disabled><?= _('Undo') ?></button>
          <button type="button" class="staxx-btn staxx-btn--primary" id="staxx-save"><?= _('Save') ?></button>
          <button type="button" class="staxx-btn" id="staxx-save-start" <?= $canRun ? '' : 'disabled' ?>><?= _('Save and start') ?></button>
        </div>
      </div>
    </div>

  </dialog>

  <!-- ---------------------------------------------------------- picker -- -->

  <!-- A second <dialog>, opened with showModal() while the editor is already
       open. Dialogs stack in the top layer in the order they were opened, so
       this one paints above the editor and takes the focus trap with it — none
       of which would be true of a panel positioned by hand.

       It sits outside the editor dialog on purpose. Nested inside, closing the
       editor with Escape would take the picker with it while the picker still
       held focus, and the browser's own focus restore would have nowhere to go. -->
  <dialog class="staxx-picker" id="staxx-picker" aria-labelledby="staxx-picker-title">

    <div class="staxx-picker-head">
      <h3 class="staxx-picker-title" id="staxx-picker-title"><?= _('Choose a folder') ?></h3>
      <p class="staxx-picker-hint">
        <?= _('Open a folder to look inside it, then choose "Use this folder". You can also type a path straight into the box.') ?>
      </p>
    </div>

    <div class="staxx-picker-where">
      <code id="staxx-picker-here">/mnt</code>
    </div>

    <div class="staxx-picker-list" id="staxx-picker-list"></div>

    <!-- Made here rather than left to Docker. A bind mount whose host folder
         does not exist yet is created at first start as root:root 755, which a
         container running as 99:100 then cannot write to. This one copies the
         owner and permissions of the folder it goes into. -->
    <div class="staxx-picker-new">
      <label class="staxx-sr" for="staxx-picker-newname"><?= _('New folder name') ?></label>
      <input type="text" id="staxx-picker-newname" maxlength="63"
             spellcheck="false" <?= $nofill ?>
             placeholder="<?= _('name a new folder to create here') ?>">
      <button type="button" class="staxx-btn" id="staxx-picker-make"><?= _('Create') ?></button>
    </div>

    <div class="staxx-picker-foot">
      <p class="staxx-picker-msg" id="staxx-picker-msg" role="status" aria-live="polite"></p>
      <div class="staxx-buttons staxx-buttons--inline">
        <button type="button" class="staxx-btn" id="staxx-picker-cancel"><?= _('Cancel') ?></button>
        <button type="button" class="staxx-btn staxx-btn--primary" id="staxx-picker-use"><?= _('Use this folder') ?></button>
      </div>
    </div>

  </dialog>

  <!-- ------------------------------------------------------- timezone -- -->

  <!-- The third dialog, opened from the button beside a TZ variable. Dialogs
       stack in the top layer in the order they open, which is what lets this
       sit over the editor the same way the folder picker does.

       EQUIRECTANGULAR, and that is the whole trick. With this viewBox, x IS
       longitude and y is minus latitude — so 15 degrees of x is exactly one
       hour, and a time band is a plain rectangle. There is no projection
       maths anywhere in the JavaScript because the coordinate system already
       is the answer.

       The coastlines are deliberately coarse: at this size one degree is
       about two pixels, so detail would be invisible and only make the file
       bigger. Drawn here rather than borrowed, so there is no third-party
       asset with its own licence riding along into an upstream pull request.
       Cropped at 60 degrees south — Antarctica holds a handful of research
       stations, which the search below still finds by name. -->
  <dialog class="staxx-tz" id="staxx-tz" aria-labelledby="staxx-tz-title">

    <div class="staxx-tz-head">
      <h3 class="staxx-tz-title" id="staxx-tz-title"><?= _('Choose a timezone') ?></h3>
      <p class="staxx-tz-hint">
        <?= _('Click a band to see the places in it, or just type a name. The map shows standard time, so a country stays in the same band all year round.') ?>
      </p>
    </div>

    <div class="staxx-tz-map">
      <svg viewBox="-180 -85 360 145" preserveAspectRatio="xMidYMid meet"
           role="img" aria-label="<?= _('World map divided into hourly time bands') ?>">

        <g class="staxx-tz-land">
          <!-- North America -->
          <path d="M -168,-65 -166,-68 -157,-71 -141,-70 -128,-70 -115,-69 -101,-69 -95,-72
                     -85,-70 -80,-73 -72,-73 -65,-62 -56,-52 -53,-48 -59,-46 -60,-44 -65,-43
                     -67,-45 -70,-42
                     -74,-40 -76,-37 -81,-32 -80,-27 -80,-25 -84,-30 -89,-29 -94,-29 -97,-26
                     -97,-20 -92,-18 -88,-21 -87,-17 -83,-15 -84,-10 -79,-9 -77,-8 -82,-8
                     -87,-13 -92,-15 -96,-16 -105,-20 -110,-24 -114,-28 -117,-32 -120,-34
                     -122,-37 -124,-42 -124,-48 -128,-52 -132,-56 -136,-59 -140,-60 -146,-60
                     -152,-58 -158,-56 -162,-60 -165,-62 Z"/>
          <!-- Greenland -->
          <path d="M -45,-60 -53,-64 -56,-71 -59,-76 -50,-82 -32,-83 -21,-80 -20,-74 -26,-70
                     -38,-66 -43,-60 Z"/>
          <!-- South America -->
          <path d="M -81,0 -80,6 -77.5,14 -71,18 -70,23 -72,30 -73,37 -74,42 -75,48 -72,52
                     -69,55 -66,55 -65,52 -68,48 -64,42 -62,39 -57,35 -53,33 -48,26 -42,23
                     -39,16 -35,8 -38,5 -44,2 -50,0 -52,-4 -60,-8 -64,-10 -72,-12 -77,-8
                     -79,-2 Z"/>
          <!-- Africa -->
          <path d="M -17,-15 -17,-21 -13,-28 -10,-32 -6,-36 3,-37 10,-37 20,-32 25,-32 32,-31
                     34,-28 37,-22 39,-15 43,-12 51,-12 48,-5 41,1 40,10 35,20 32,26 28,33
                     20,35 18,34 15,27 12,18 9,1 2,-4 -5,-5 -8,-4 -13,-8 Z"/>
          <!-- Eurasia -->
          <path d="M -9.5,-37 -9.5,-43 -1,-44 -2,-48 2,-51 5,-53 8,-54 8,-57 11,-58 13,-55 19,-55
                     24,-58 24,-60 21,-63 17,-66 22,-70 28,-71 33,-70 45,-68 60,-71 70,-73
                     80,-74 95,-77 105,-78 113,-74 125,-73 135,-72 145,-70 155,-71 165,-70
                     180,-68 180,-64 170,-61 163,-58 158,-53 155,-49 143,-45 135,-44 131,-43
                     129,-39 126,-37 122,-39 119,-39 122,-31 121,-28 117,-24 114,-22 110,-21
                     108,-15 109,-11 105,-9 100,-6 98,-8 95,-16 94,-20 90,-22 88,-22 81,-16
                     80,-10 77,-8 73,-15 70,-20 68,-24 61,-25 57,-25 59,-22 55,-17 52,-15
                     45,-13 43,-13 39,-20 35,-28 34,-31 36,-36 31,-38.5 26,-40 23,-36.4
                     21,-39 18,-41 12,-44 4,-43 0,-41 -6,-36 Z"/>
          <!-- Italy, drawn on its own. Carving a boot out of the same ring that
               forms the Mediterranean coast puts Rome in the sea; a separate
               shape overlapping the mainland costs one path and gets it right. -->
          <path d="M 7,-45 9,-46.5 13,-46 18,-40 16,-38.5 13,-41 10,-43 Z"/>
          <!-- Australia -->
          <path d="M 113,22 114,26 115,32 118,35 123,34 129,32 134,33 137,35 140,38 146,39
                     150,37 153,31 153,25 148,20 146,19 142,11 137,12 136,15 131,12 129,15
                     125,14 122,17 117,20 Z"/>
          <!-- New Zealand -->
          <path d="M 173,35 175,37 178,38 178,40 176,41 172,43 170,46 167,46 170,43 172,40
                     174,38 172,34 Z"/>
          <!-- Japan -->
          <path d="M 131,-31 130,-33 133,-36 137,-38 139,-40 140,-42 142,-43 145,-44 144,-42
                     142,-40 141,-36 138,-35 136,-34 133,-33 Z"/>
          <!-- Great Britain and Ireland -->
          <path d="M -5,-50 -6,-53 -3,-55 -5,-58 -3,-58 -1,-55 2,-53 1,-51 Z"/>
          <path d="M -10,-52 -10,-55 -6,-55 -6,-52 Z"/>
          <!-- Iceland -->
          <path d="M -24,-64 -22,-66 -15,-66 -14,-64 -19,-63 Z"/>
          <!-- Madagascar -->
          <path d="M 43,12 50,15 50,25 45,25 43,19 Z"/>
          <!-- Sri Lanka -->
          <path d="M 80,-6 82,-7 82,-9 80,-9 Z"/>
          <!-- Sumatra, Java, Borneo, Sulawesi, New Guinea, the Philippines -->
          <path d="M 95,-6 99,-4 104,2 106,6 102,5 97,-2 Z"/>
          <path d="M 104.5,5.8 105,8 114.5,9 115,8 110,6 106,5.8 Z"/>
          <path d="M 109,-2 117,-4 119,1 116,4 110,3 Z"/>
          <path d="M 119,-1 125,-1 125,5 121,5 120,2 Z"/>
          <path d="M 131,1 141,3 147,8 143,9 134,5 131,2 Z"/>
          <path d="M 120,-18 122,-14 126,-10 126,-6 122,-7 120,-13 Z"/>
          <!-- Cuba and Hispaniola -->
          <path d="M -84,-22 -77,-21 -74,-20 -80,-23 Z"/>
          <path d="M -74,-19 -68,-19 -68,-18 -74,-18 Z"/>
        </g>

        <!-- Filled by the JavaScript: 24 rects with a data-offset each. Written
             by a loop rather than by hand, because that is what a loop is for. -->
        <g id="staxx-tz-bands"></g>
      </svg>

      <p class="staxx-tz-caption" id="staxx-tz-caption"></p>
    </div>

    <!-- The five zones that sit WEST of the date line but keep an eastern
         offset. There is no honest place to draw them on a map laid out by
         longitude, so they are named instead. -->
    <div class="staxx-tz-chips" id="staxx-tz-chips"></div>

    <div class="staxx-tz-find">
      <label class="staxx-sr" for="staxx-tz-search"><?= _('Search timezones') ?></label>
      <input type="text" id="staxx-tz-search" spellcheck="false" <?= $nofill ?>
             placeholder="<?= _('or search every zone — try “tokyo” or “st john”') ?>">
    </div>

    <div class="staxx-tz-list" id="staxx-tz-list"></div>

    <div class="staxx-tz-foot">
      <p class="staxx-tz-msg" id="staxx-tz-msg" role="status" aria-live="polite"></p>
      <button type="button" class="staxx-btn" id="staxx-tz-cancel"><?= _('Cancel') ?></button>
    </div>

  </dialog>

  <!-- ------------------------------------------------ Community Applications -- -->

  <!-- The fourth dialog, opened from the Apps button beside "Add stack". It
       sits outside the editor for the same reason the picker and timezone
       dialogs do: nested inside the editor, closing this one with Escape
       would take the editor with it while this dialog still held focus, and
       the browser's own focus restore would have nowhere to go.

       Search runs on the server rather than in this page. The catalogue
       behind it is roughly 4,100 apps — about 24 MB of JSON — which is too
       much to hand to the browser and filter locally, so the page only ever
       holds the one page of results it asked for, never the whole thing. -->
  <dialog class="staxx-ca" id="staxx-ca" aria-labelledby="staxx-ca-title">

    <div class="staxx-ca-head">
      <h3 class="staxx-ca-title" id="staxx-ca-title"><?= _('Add an app') ?></h3>
      <p class="staxx-ca-hint">
        <?= _('A Community Applications app arrives with its ports, paths and settings already filled in. A Docker Hub image, or one already on this server, arrives with whatever its own documentation describes — and with just the image when there is nothing to read. Either way it opens in the editor for you to look over before anything is saved.') ?>
      </p>
    </div>

    <!-- Two views of one panel — a curated homepage, or a search's results —
         so only one is ever on screen; typing or picking a category switches
         to Search on its own, but these two buttons are the only way back. -->
    <div class="staxx-ca-tabs">
      <button type="button" class="staxx-ca-tab is-on" id="staxx-ca-tab-home" aria-pressed="true"><?= _('Home') ?></button>
      <button type="button" class="staxx-ca-tab" id="staxx-ca-tab-search" aria-pressed="false"><?= _('Search') ?></button>
    </div>

    <div class="staxx-ca-find">
      <label class="staxx-sr" for="staxx-ca-search"><?= _('Search apps and images') ?></label>
      <!-- No count in the placeholder. The catalogue's size moves — it lost 303
           entries the day Unraid plugins were filtered out of it — and a number
           baked in here is one nobody will remember to update. -->
      <input type="text" id="staxx-ca-search" spellcheck="false" <?= $nofill ?>
             placeholder="<?= _('search apps and images — try “jellyfin”') ?>">
      <select id="staxx-ca-cat">
        <option value=""><?= _('Every category') ?></option>
      </select>
    </div>

    <div class="staxx-ca-list" id="staxx-ca-list"></div>

    <div class="staxx-ca-foot">
      <p class="staxx-ca-msg" id="staxx-ca-msg" role="status" aria-live="polite"></p>
      <button type="button" class="staxx-btn" id="staxx-ca-cancel"><?= _('Cancel') ?></button>
    </div>

  </dialog>

  <!-- -------------------------------------------------- CA app details -- -->

  <!-- The fifth dialog, opened from a card inside the Apps dialog above. It
       sits outside staxx-ca rather than nested in it, for the same reason
       the picker sits outside the editor: nested inside, closing staxx-ca
       with Escape would take this one with it while it still held focus, and
       the browser's own focus restore would have nowhere to go. -->
  <dialog class="staxx-ca-app" id="staxx-ca-app" aria-labelledby="staxx-ca-app-title">

    <div class="staxx-ca-app-head">
      <span class="staxx-ca-app-icon" id="staxx-ca-app-icon"></span>
      <div class="staxx-ca-app-id">
        <h3 class="staxx-ca-app-title" id="staxx-ca-app-title"></h3>
        <p class="staxx-ca-app-by" id="staxx-ca-app-by"></p>
      </div>
      <button type="button" class="staxx-btn staxx-btn--primary" id="staxx-ca-app-add"><?= _('Add this app') ?></button>
      <button type="button" class="staxx-btn" id="staxx-ca-app-close"><?= _('Close') ?></button>
    </div>

    <div class="staxx-ca-app-body" id="staxx-ca-app-body"></div>

  </dialog>

  <!-- ---------------------------------------------------------- import -- -->

  <!-- The sixth dialog, opened from the Import button beside Apps. Lists
       everything on the server that could be brought into StaXX; only
       Unraid template rows can be ticked and written in, as new stacks that
       arrive locked (see NEEDS-REVIEW.md, written server-side) so nothing
       runs and no existing container is touched until that lock is cleared
       by hand later. Compose Manager projects and containers belonging to
       neither template nor project stay read-only — that still needs
       multi-file support this phase does not have. Sits outside the editor
       for the same reason the Apps dialog immediately above does. -->
  <dialog class="staxx-import" id="staxx-import-dlg" aria-labelledby="staxx-import-title">

    <div class="staxx-import-head">
      <h3 class="staxx-import-title" id="staxx-import-title"><?= _('Import') ?></h3>
      <p class="staxx-import-hint">
        <?= _('Unraid templates and Compose Manager projects can be ticked and imported below; each one is written as a new stack that starts out locked, so nothing runs and no existing container is touched until you choose to take it over later.') ?>
        <?= _('A Compose Manager project is copied exactly as you wrote it, and the project it came from is left where it is.') ?>
        <?= _('Containers belonging to neither are shown for reference only — importing those is not built yet.') ?>
      </p>
    </div>

    <div class="staxx-import-list" id="staxx-import-list"></div>

    <div class="staxx-import-foot">

      <div class="staxx-import-dest" id="staxx-import-dest" hidden>
        <label class="staxx-import-destlabel" for="staxx-import-folder"><?= _('Import into') ?></label>
        <select id="staxx-import-folder"></select>
        <p class="staxx-import-destpath" id="staxx-import-destpath"></p>
        <p class="staxx-import-destnote" id="staxx-import-destnote" hidden></p>
      </div>

      <div class="staxx-import-summary" id="staxx-import-summary" hidden></div>

      <div class="staxx-import-footrow">
        <p class="staxx-import-msg" id="staxx-import-msg" role="status" aria-live="polite"></p>
        <div class="staxx-import-footbtns">
          <button type="button" class="staxx-btn staxx-btn--primary" id="staxx-import-go" disabled><?= _('Import') ?></button>
          <button type="button" class="staxx-btn" id="staxx-import-close"><?= _('Close') ?></button>
        </div>
      </div>

    </div>

  </dialog>

  <!-- --------------------------------------------------------- confirm -- -->

  <!-- The seventh dialog, opened from deleteStack() in stacks.js. It sits
       outside the editor for the same reason the picker does: nested inside
       it, closing the editor with Escape would take this one with it while
       it still held focus, and the browser's own focus restore would have
       nowhere to go.

       One dialog serves both stages of the confirmation. The body starts
       with the plain warning; if the folder holds more than the compose
       file, script appends a second paragraph and the file list in place,
       and relabels the button, rather than opening a second dialog on top
       of the first. -->
  <dialog class="staxx-confirm" id="staxx-confirm" aria-labelledby="staxx-confirm-title">

    <div class="staxx-confirm-head">
      <h3 class="staxx-confirm-title" id="staxx-confirm-title"></h3>
    </div>

    <div class="staxx-confirm-body" id="staxx-confirm-body"></div>

    <div class="staxx-confirm-foot">
      <p class="staxx-confirm-msg" id="staxx-confirm-msg" role="status" aria-live="polite"></p>
      <div class="staxx-buttons staxx-buttons--inline">
        <button type="button" class="staxx-btn" id="staxx-confirm-cancel"><?= _('Cancel') ?></button>
        <!-- The third answer askConfirm() can offer (PLAN_44 C2: "Save and
             start" beside "Start anyway"). Hidden except when a caller passes
             opts.extraLabel — every existing two-answer question leaves this
             alone and never sees it. -->
        <button type="button" class="staxx-btn staxx-btn--primary" id="staxx-confirm-extra" hidden></button>
        <button type="button" class="staxx-btn staxx-btn--danger" id="staxx-confirm-go"><?= _('Delete stack') ?></button>
      </div>
    </div>

  </dialog>

  <!-- ------------------------------------------------------------ settings -- -->

  <!-- The eighth dialog, a sibling of the seven above for the same reason:
       outside .staxx-table-wrap, inside the scaffold. No <form> wrapper,
       for the same reason as the editor above. The body starts empty; script
       fills it from the settings-read action before showModal(). -->
  <dialog class="staxx-settings" id="staxx-settings" aria-labelledby="staxx-settings-title">

    <div class="staxx-settings-head">
      <h3 class="staxx-settings-title" id="staxx-settings-title"><?= _('Settings') ?></h3>
    </div>

    <div class="staxx-settings-body" id="staxx-settings-body"></div>

    <div class="staxx-settings-foot">
      <p class="staxx-settings-msg" id="staxx-settings-msg" role="status" aria-live="polite"></p>
      <div class="staxx-buttons staxx-buttons--inline">
        <button type="button" class="staxx-btn" id="staxx-settings-cancel"><?= _('Cancel') ?></button>
        <button type="button" class="staxx-btn staxx-btn--primary" id="staxx-settings-save"><?= _('Save') ?></button>
      </div>
    </div>

  </dialog>

  <!-- ------------------------------------------------------------ storage -- -->

  <!-- PLAN_68 Part B: the chooser behind both the one-time banner above and
       the settings panel's own "Move it" line. Reuses every .staxx-settings
       class rather than a stylesheet of its own — this is the same shape of
       dialog, a list of fields and a Close button, so a second look and a
       second animation would only be two ways of saying the same thing. The
       body starts empty; script fills it from the storage-options action
       before showModal(). -->
  <dialog class="staxx-settings" id="staxx-storage-dlg" aria-labelledby="staxx-storage-title">

    <div class="staxx-settings-head">
      <h3 class="staxx-settings-title" id="staxx-storage-title"><?= _('Where should stacks live?') ?></h3>
    </div>

    <div class="staxx-settings-body" id="staxx-storage-body"></div>

    <div class="staxx-settings-foot">
      <p class="staxx-settings-msg" id="staxx-storage-msg" role="status" aria-live="polite"></p>
      <div class="staxx-buttons staxx-buttons--inline">
        <button type="button" class="staxx-btn" id="staxx-storage-close"><?= _('Close') ?></button>
      </div>
    </div>

  </dialog>

  <!-- ------------------------------------------------------------- log -- -->

  <!-- The ninth dialog: a job's output on demand — a failed command, Logs,
       the self-test. It never opens on its own; every call site in stacks.js
       chooses to open it. A <dialog> cannot scroll the page behind it, which
       is the whole reason the old page-bottom panel this replaced is gone —
       Adrian's word for that panel was "annoying". Same recipe as
       .staxx-settings: own class, own :not([open]), ::backdrop and
       @starting-style. -->
  <dialog class="staxx-logdlg" id="staxx-log-dlg" aria-labelledby="staxx-log-title">

    <div class="staxx-logdlg-head">
      <h3 class="staxx-logdlg-title" id="staxx-log-title"><?= _('Output') ?></h3>
    </div>

    <pre class="staxx-logdlg-body" id="staxx-log-body"></pre>

    <div class="staxx-logdlg-foot">
      <button type="button" class="staxx-btn" id="staxx-log-close"><?= _('Close') ?></button>
    </div>

  </dialog>

  <!-- ---------------------------------------------------- environment -- -->

  <details class="staxx-details">
    <summary><?= _('Environment') ?></summary>

    <div class="staxx-status" role="table">
      <div class="staxx-row staxx-head-row" role="row">
        <span class="staxx-cell" role="columnheader"><?= _('Check') ?></span>
        <span class="staxx-cell" role="columnheader"><?= _('Result') ?></span>
      </div>
      <?
        staxx_status_row(
          _('Docker service'),
          $dockerRunning,
          $dockerRunning ? _('Running') : _('Not running')
        );

        // Reported as two rows: whether compose runs at all, and where it was
        // found. They can disagree — compose can work while sitting somewhere
        // this plugin does not know to look — and that difference is worth
        // seeing rather than hiding behind a single tick.
        staxx_status_row(
          _('Compose available'),
          $compose['available'],
          $compose['available']
            ? sprintf(
                $compose['form'] === 'standalone'
                  ? _('v%s — standalone `docker-compose` command')
                  : _('v%s — `docker compose` CLI plugin'),
                $compose['version'] ?: '?'
              )
            // Says how to get it rather than promising an installer this
            // plugin does not have. Unraid ships no compose of its own, and
            // Compose Manager is how it reaches nearly every box that has it.
            : _('Not found. Unraid does not ship compose — install the Docker Compose '
              . 'Manager plugin from Community Applications, then reload this page.')
        );

        staxx_status_row(
          _('Compose location'),
          $compose['path'] !== '',
          $compose['path'] !== ''
            ? $compose['path']
            : ($compose['available']
                ? _('Runs, but not in any known location — please report where it lives.')
                : _('Nothing to locate.'))
        );

        staxx_status_row(
          _('Stack folder'),
          is_dir($root),
          is_dir($root) ? $root : sprintf(_('%s does not exist yet'), $root)
        );

        staxx_status_row(
          _('Compose project labels'),
          $stackCount > 0,
          $stackCount > 0
            ? sprintf(_('%d compose stack(s) detected via com.docker.compose.project'), $stackCount)
            : _('No compose-managed containers found yet.')
        );
      ?>
    </div>

    <? if ($projects): ?>
      <h3><?= _('Containers by stack') ?></h3>
      <p class="staxx-hint">
        <?= _('Grouping comes from the com.docker.compose.project label — stacks group themselves, with no folders to configure.') ?>
      </p>

      <div class="staxx-projects" role="table">
        <? foreach ($projects as $project => $containers): ?>
          <div class="staxx-row" role="row">
            <span class="staxx-cell staxx-project" role="cell">
              <? if ($project === ''): ?>
                <i class="fa fa-cube"></i> <em><?= _('Not compose-managed') ?></em>
              <? else: ?>
                <i class="fa fa-cubes"></i> <?= htmlspecialchars($project) ?>
              <? endif; ?>
            </span>
            <span class="staxx-cell" role="cell"><?= htmlspecialchars(implode(', ', $containers)) ?></span>
          </div>
        <? endforeach; ?>
      </div>

      <? if ($unmanagedCount): ?>
        <p class="staxx-hint">
          <?= sprintf(
                _('%d container(s) carry no compose project label. These were created by Unraid templates or by hand, and are what an import path would need to adopt.'),
                $unmanagedCount
              ) ?>
        </p>
      <? endif; ?>
    <? endif; ?>
  </details>

</div>

<!-- The compose model is a separate file on purpose. stacks.js is one large
     IIFE, so a single typo in it silently kills every behaviour on the page;
     keeping the YAML parser out of that blast radius means a bad edit there
     costs the editor and leaves the table, menus and stats working. It is also
     the only way to reach the parser from a Node test, since nothing inside
     that IIFE is reachable from outside it. Load order matters: it defines
     window.StaxxYaml, which stacks.js reads. Conditional because the form
     view is still being built and the file is not there yet; a missing src
     would only put a 404 in the console for no benefit. -->
<? if (is_file($modelFile)): ?>
<script src="<?= $modelTag ?>"></script>
<? endif; ?>
<!-- The Community Applications converter is likewise a separate file so a bad
     conversion never risks the rest of the page — stacks.js guards its own
     use of it with a presence check for exactly this reason. Conditional
     because it may not exist yet on an older install; a missing src would
     only put a 404 in the console for no benefit. -->
<? if (is_file($caFile)): ?>
<script src="<?= $caTag ?>"></script>
<? endif; ?>
<!-- Turns an image-facts reply into a compose file for caAddImage(); reads
     window.StaxxCA and window.StaxxYaml, so it must load after both.
     Conditional for the same reason as the two above. -->
<? if (is_file($imageFile)): ?>
<script src="<?= $imageTag ?>"></script>
<? endif; ?>
<!-- The Manage tab (PLAN_44 Part D), a separate file for the same reason as
     the three above: a bad edit there costs the Manage tab, not the rest of
     the page. It sets window.StaxxManage, which stacks.js reads with a null
     check and a plain refusal — the Manage tab disables itself rather than
     the page breaking — so load order only matters in that stacks.js must
     come after it, not the reverse. Conditional because it may not exist yet
     while it is still being written; a missing src would only be a 404. -->
<? if (is_file($manageJsFile)): ?>
<script src="<?= $manageJsTag ?>"></script>
<? endif; ?>
<script src="<?= $jsTag ?>"></script>
