<?PHP
/* StaXX — the stack table, rendered in exactly one place.
 * Copyright 2026, StaXX contributors.
 *
 * WHY THIS FILE EXISTS
 *
 * Starting or stopping a stack used to reload the whole page. That is the
 * honest thing to do when the table is only ever built server-side, but it is
 * expensive and it throws away everything the page had accumulated: the little
 * graphs start again from nothing, the scroll position jumps to the top, the
 * command output panel closes, and on a server with a lot of stacks the reload
 * itself takes seconds because every compose file is read again.
 *
 * So the markup lives here instead of inside the page, and both the page and
 * the JSON endpoint call the same function. The browser can ask for a fresh
 * table body and swap it in without a reload, and there is still only one copy
 * of the markup — the alternative, rebuilding rows in JavaScript, would mean
 * two renderers that have to be kept saying the same thing.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Folders.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Icons.php';

// NO "already loaded?" guard here, deliberately.
//
// The obvious one — `if (function_exists('staxx_render_rows')) return;` —
// does not work, and fails in a way that looks like something else entirely.
// PHP binds plain top-level function declarations when it COMPILES a file, not
// when it runs it, so every function below already exists by the time the first
// line executes. The guard therefore matches on the very first include and
// returns immediately, skipping everything that is not a function declaration.
// The functions still work, which is why it looks fine, but the constant and
// the fallback below are silently never defined.
//
// require_once already prevents a second include, so no guard is needed at all.

// Page templates are handed _() by Unraid's translation include before they
// run. The JSON endpoint is not, and calling an undefined function is fatal.
//
// Checked rather than declared outright because PHP's gettext extension also
// provides an _(). Note that function_exists('_') reports FALSE on this system
// even with gettext loaded — the alias is not registered under that name — so
// the check is what makes both cases work.
if (!function_exists('_')) {
  function _(string $text): string { return $text; }
}

/**
 * Where a row can be reached, as HTML.
 *
 * Each entry reads as one thing followed by its ports. What that leading thing
 * is depends on the kind of network, and the choice is made in
 * staxx_container_net():
 *
 *   bridge or host   the network's name — mybridge, multi-tier_default, host.
 *                    Both of those answer on THIS server's address, so printing
 *                    it would repeat one string down the whole column; the
 *                    network is what actually differs between rows.
 *   anywhere else    the address itself, because that is what you would type.
 *
 * Those entries carry the server's address on hover, since that is where their
 * ports are actually answering.
 *
 * @param array $addresses list of ['label'=>string, 'ip'=>string, 'ports'=>string[]]
 */
function staxx_address_html(array $addresses): string {
  $hostIp = staxx_host_ip();
  $out    = [];

  foreach ($addresses as $a) {
    $ports = $a['ports'] ?? [];
    $label = (string)($a['label'] ?? $a['ip'] ?? '');
    if ($label === '') continue;

    $title = ($a['ip'] !== $label && $a['ip'] !== '')
      ? ' title="'.htmlspecialchars(sprintf(_('on %s'), $a['ip'])).'"'
      : '';

    $name = '<span class="staxx-addr-label">'.htmlspecialchars($label).'</span>';

    // An address with no ports is still how you reach it — the application may
    // well be on 80 — so it is worth showing on its own.
    if (!$ports) {
      $out[] = '<span class="staxx-addr"'.$title.'>'.$name.'</span>';
      continue;
    }

    // The colon is real text, not a CSS ::before. Generated content is not
    // included when a browser copies a selection, so a styled-in colon would
    // put "192.168.202.598083" on the clipboard — an address nobody can use.
    $out[] = '<span class="staxx-addr"'.$title.'>'.$name.':'
           . '<span class="staxx-addr-ports">'
           . htmlspecialchars(implode(', ', $ports)).'</span></span>';
  }

  return $out ? implode('', $out) : '<span class="staxx-sub">—</span>';
}

/**
 * Every address a whole stack answers on, with the ports of each gathered
 * together.
 *
 * Merged by address rather than listed per container: a three-container stack
 * publishing three ports on the same server is one address with three ports,
 * not three separate entries repeating it.
 *
 * @param array $containers rows from staxx_container_index()
 */
function staxx_merged_addresses(array $containers): array {
  $net = staxx_container_net();

  // Grouped by the label, not by the address behind it. Three containers of one
  // stack sharing a compose network collapse to a single "multi-tier_default"
  // entry holding all three ports, which is the whole point — the alternative
  // repeats the same name on three lines.
  $groups = [];
  foreach ($containers as $c) {
    foreach ($net[$c['id']]['addresses'] ?? [] as $a) {
      $key = (string)($a['label'] ?? $a['ip'] ?? '');
      if ($key === '') continue;
      if (!isset($groups[$key])) $groups[$key] = ['ip' => $a['ip'], 'ports' => []];
      foreach ($a['ports'] as $port) $groups[$key]['ports'][$port] = true;
    }
  }

  $hostIp = staxx_host_ip();
  $out    = [];
  foreach ($groups as $label => $g) {
    $list = array_keys($g['ports']);
    sort($list, SORT_NUMERIC);
    $out[] = ['label' => (string)$label, 'ip' => $g['ip'], 'ports' => array_map('strval', $list)];
  }

  usort($out, function ($a, $b) use ($hostIp) {
    $rank = fn($x) => $x['ip'] === $hostIp ? 0 : 1;
    return $rank($a) <=> $rank($b) ?: strcmp($a['label'], $b['label']);
  });

  return $out;
}

/**
 * The state cell's contents for one stack.
 *
 * Kept apart from the row markup because it is the one piece the browser
 * re-renders on its own after a start or a stop, and it must look identical
 * whichever route produced it. Returning HTML from the server rather than
 * rebuilding it in JavaScript also keeps the translated words in one place.
 */
function staxx_state_pill(array $s, bool $canRun): string {
  if (!$canRun) {
    return '<span class="staxx-sub">'._('unknown').'</span>';
  }
  if (!empty($s['running'])) {
    return '<span class="staxx-pill staxx-pill--up">'
         . htmlspecialchars((string)$s['status']).'</span>';
  }
  if ((string)$s['status'] !== '') {
    return '<span class="staxx-pill">'.htmlspecialchars((string)$s['status']).'</span>';
  }
  return '<span class="staxx-pill staxx-pill--down">'._('stopped').'</span>';
}

/** The "3 stacks · 1 running" line under a folder name. */
function staxx_folder_sub(int $count, int $running): string {
  $text = sprintf($count === 1 ? _('%d stack') : _('%d stacks'), $count);
  if ($running > 0) $text .= ' · '.sprintf(_('%d running'), $running);
  return htmlspecialchars($text);
}

/** The "2 of 3 running" line under a stack name. */
function staxx_stack_sub(int $count, int $running): string {
  if ($count === 0) return htmlspecialchars(_('no services'));
  $text = $running === $count
    ? sprintf($count === 1 ? _('%d container') : _('%d containers'), $count)
    : sprintf(_('%d of %d running'), $running, $count);
  return htmlspecialchars($text);
}

/**
 * The state cell for one container.
 *
 * Docker's own status text is used rather than a word of our own — "Up 5
 * minutes", "Exited (0) 3 days ago", "Restarting (1) 4 seconds ago". It says
 * more than "stopped" does, and it is the same wording the command line gives,
 * so the two never disagree.
 */
function staxx_container_pill(array $c): string {
  if (!$c['exists']) {
    return '<span class="staxx-pill staxx-pill--down">'._('not created').'</span>';
  }

  $status = htmlspecialchars($c['status'] !== '' ? $c['status'] : $c['state']);

  switch ($c['state']) {
    case 'running':
      return '<span class="staxx-pill staxx-pill--up">'.$status.'</span>';
    case 'restarting':
    case 'removing':
    case 'paused':
      return '<span class="staxx-pill staxx-pill--warn">'.$status.'</span>';
    case 'dead':
      return '<span class="staxx-pill staxx-pill--bad">'.$status.'</span>';
    default:                                   // exited, created
      return '<span class="staxx-pill staxx-pill--down">'.$status.'</span>';
  }
}

/**
 * The containers to list underneath a stack, one row each.
 *
 * Two sources have to be reconciled, and neither alone is enough. The compose
 * file says which services SHOULD exist, and is the only source at all before a
 * stack has ever been started. Docker says which containers DO exist, including
 * ones that are stopped, and is the only source of their real state.
 *
 * Declared services come first so the compose file's own ordering survives, and
 * anything docker knows about is then merged onto it.
 *
 * @return array<int, array{key:string, service:string, name:string, id:string,
 *                          image:string, icon:string, state:string, status:string,
 *                          exists:bool}>
 */
function staxx_stack_children(array $s): array {
  $containers = staxx_stack_containers($s);

  // What the compose file declares, which is the only source for a service that
  // has never been started: no container means docker has no image name for it,
  // and no image name means no icon until the first time it runs.
  $declared = $s['file'] !== '' ? staxx_compose_meta($s['file'])['services'] : [];

  $rows = [];

  foreach ($s['services'] as $service) {
    $rows[$service] = [
      'key' => $service, 'service' => $service, 'name' => '', 'id' => '',
      'image' => (string)($declared[$service]['image'] ?? ''),
      'icon'  => (string)($declared[$service]['x']['icon'] ?? ''),
      'state' => '', 'status' => '', 'exists' => false,
    ];
  }

  foreach ($containers as $c) {
    $service = $c['service'] !== '' ? $c['service'] : $c['name'];
    $key     = $service;

    // A service running more than one container — compose replicas — would
    // otherwise overwrite itself and report one container where there are
    // several. Each gets its own row, told apart by container name.
    if (isset($rows[$key]) && $rows[$key]['exists']) $key = $service.'/'.$c['name'];

    $rows[$key] = [
      'key'     => $key,
      'service' => $service,
      'name'    => $c['name'],
      'id'      => $c['id'],
      // Docker's image, because that is the one actually running — a compose
      // file edited since the container was created says something else.
      'image'   => $c['image'] !== '' ? $c['image'] : (string)($declared[$service]['image'] ?? ''),
      'icon'    => (string)($declared[$service]['x']['icon'] ?? ''),
      'state'   => $c['state'],
      'status'  => $c['status'],
      'exists'  => true,
    ];
  }

  return array_values($rows);
}

/**
 * What goes inside one icon tile.
 *
 * Four possibilities, and every one of them draws SOMETHING — a tile that can
 * come out empty is worse than the grey cube this replaces, because an empty
 * square reads as a broken image rather than as an unrecognised container.
 *
 *   a glyph      the compose file asked for a Font Awesome icon by name
 *   a picture    an icon that is already cached and can be loaded right now
 *   initials     a match exists but has not been downloaded yet, so the tile
 *                carries data-icon-ref and the browser swaps a picture in as
 *                soon as the background fetch has it
 *   initials     nothing matched, and nothing ever will
 *
 * @param array  $icon from staxx_icon_resolve()
 * @param string $name what the initials and the colour are worked out from
 */
function staxx_icon_tile(array $icon, string $name): string {
  if ($icon['fa'] !== '') {
    $fa = htmlspecialchars($icon['fa']);
    // Class only. Unraid's default-fonts.css carries `[data-icon]:before {
    // font-family: docker-icon !important }`, and that font holds a single glyph
    // — the Docker whale — so a data-icon attribute on a Font Awesome <i> paints
    // an empty box instead of the icon.
    return '<i class="fa '.$fa.'"></i>';
  }

  $ref  = $icon['ref'] !== '' ? ' data-icon-ref="'.htmlspecialchars($icon['ref']).'"' : '';
  $tile = staxx_icon_initials($name);

  if ($icon['url'] !== '') {
    // alt is empty on purpose: the row already says the name right beside it,
    // and a screen reader repeating it is noise.
    //
    // The initials travel WITH the picture. A cached icon can disappear from
    // under a page that is already open — the served copy lives in RAM and a
    // reboot takes it — and an image that cannot load is a broken-image box,
    // which looks like a bug. stacks.js listens for that and puts these back.
    return '<img src="'.htmlspecialchars($icon['url']).'" alt=""'.$ref
         . ' data-fallback="'.htmlspecialchars($tile['text']).'"'
         . ' data-fallback-colour="'.$tile['colour'].'">';
  }

  return '<span class="staxx-tile staxx-tile--'.$tile['colour'].'"'.$ref.'>'
       . htmlspecialchars($tile['text']).'</span>';
}

/**
 * A stack's tile: the icons of the containers inside it, tiled together.
 *
 * A stack is not one thing, so one logo cannot honestly stand for it. Four is
 * the most that stays legible in a 4.4rem square; beyond that the fourth cell
 * counts what did not fit, the way a photo album cover does.
 *
 * An explicit `icon:` in the stack's own x-unraid block overrides all of this —
 * if someone has said what the stack looks like, that is the answer.
 *
 * @param array $kids from staxx_stack_children()
 */
function staxx_stack_tile(array $s, array $kids): string {
  $own = (string)($s['x']['icon'] ?? '');
  if ($own !== '') {
    // Initials come from the title, so the letters in the tile are the letters
    // written beside it. The folder name is not what anyone is reading here.
    return staxx_icon_tile(staxx_icon_resolve($own, $s['dir']), $s['leaf']);
  }

  if (!$kids) {
    return '<i class="fa fa-cubes"></i>';
  }

  $tiles = [];
  foreach ($kids as $kid) {
    $tiles[] = staxx_icon_tile(
      staxx_icon_resolve($kid['icon'], $s['dir'], $kid['image'], $kid['service'], $s['name']),
      $kid['service'] !== '' ? $kid['service'] : $kid['name']
    );
  }

  if (count($tiles) === 1) return $tiles[0];

  $extra = count($tiles) - 4;
  if ($extra > 0) {
    $tiles = array_slice($tiles, 0, 3);
    $tiles[] = '<span class="staxx-tile staxx-tile--more">+'.($extra + 1).'</span>';
  }

  return '<span class="staxx-mosaic">'.implode('', $tiles).'</span>';
}

/**
 * Every icon the table would like to show but does not have yet.
 *
 * Worked out on the server rather than read off the page, because the browser
 * is not a trustworthy source of "please download this URL". A reference here
 * has already come from a compose file the server read itself, or from the
 * collection index, and nothing else can get into the list.
 *
 * @return array<int, array{ref:string, remote:string}>
 */
function staxx_icon_wanted(): array {
  $wanted = [];

  $add = function (array $icon) use (&$wanted) {
    if ($icon['ref'] === '' || $icon['url'] !== '') return;   // nothing to do, or already cached
    $wanted[$icon['ref']] = ['ref' => $icon['ref'], 'remote' => $icon['remote']];
  };

  foreach (staxx_list_stacks() as $s) {
    if (!$s['parses']) continue;

    $own = (string)($s['x']['icon'] ?? '');
    if ($own !== '') {
      $add(staxx_icon_resolve($own, $s['dir']));
      // A stack that names its own icon does not need its children's, but the
      // container rows underneath it still show them.
    }

    foreach (staxx_stack_children($s) as $kid) {
      $add(staxx_icon_resolve($kid['icon'], $s['dir'], $kid['image'],
                                 $kid['service'], $s['name']));
    }
  }

  return array_values($wanted);
}

/**
 * Every row of the table body, as HTML.
 *
 * Divs standing in for a table, arranged as CSS grid / subgrid so the columns
 * still line up the way a <table>'s did — see staxx.css for the track
 * definitions this markup depends on. The hierarchy that used to be faked with
 * indentation and colspan is now real DOM nesting:
 *
 *   .staxx-group--folder   a folder heading plus every stack filed under it
 *   .staxx-group--stack    an expandable stack's own row plus its containers
 *
 * A stack with only one service is not expandable and gets neither a group nor
 * any child rows, exactly as before — see $expandable below.
 *
 * @param array $rows   from staxx_folder_layout()
 * @param bool  $canRun whether docker and compose are both usable
 */
function staxx_render_rows(array $rows, bool $canRun): string {
  ob_start();

  if (!$rows):
?>
        <div class="staxx-row staxx-empty-row" role="row">
          <span class="staxx-cell" role="gridcell">
            <?= _('No stacks yet. Use "Add stack" to paste a compose file in, or drop one into the folder above.') ?>
          </span>
        </div>
<?
  endif;

  // Whether a <div class="staxx-group staxx-group--folder"> is currently
  // open. staxx_folder_layout() lists every folder's members immediately
  // after it and before the next folder — or before the first unfiled stack,
  // since those always come last — so a group only ever needs closing in
  // those two places.
  $inFolderGroup = false;

  foreach ($rows as $row):

    if ($row['type'] === 'folder'):
      if ($inFolderGroup):
?>
          </div>
        </div>
      </div>
<?
      endif;
      $inFolderGroup = true;
?>
      <div class="staxx-group staxx-group--folder" role="presentation" data-folder-group="<?= htmlspecialchars($row['id']) ?>">
        <div class="staxx-row staxx-folder-row" role="row" aria-level="1"
             aria-expanded="<?= $row['collapsed'] ? 'false' : 'true' ?>"
             data-folder-row="<?= htmlspecialchars($row['id']) ?>">
          <!-- Spans the name, services, state and address columns: a folder has
               none of those of its own, only the four figures on the right. -->
          <span class="staxx-cell staxx-cell--span4" role="gridcell">
            <!-- Flex lives on a div, never on the cell itself. -->
            <div class="staxx-namebox">
              <button type="button" class="staxx-chevron"
                      data-toggle-folder="<?= htmlspecialchars($row['id']) ?>"
                      aria-expanded="<?= $row['collapsed'] ? 'false' : 'true' ?>"
                      title="<?= $row['collapsed'] ? _('Expand') : _('Collapse') ?>">
                <i class="fa fa-chevron-<?= $row['collapsed'] ? 'right' : 'down' ?>"></i>
              </button>

              <!-- Clicking the icon opens the menu — the same gesture Unraid
                   uses on its own Docker page. -->
              <span class="staxx-iconwrap">
                <button type="button" class="staxx-icon"
                        data-menu="folder"
                        data-folder="<?= htmlspecialchars($row['id']) ?>"
                        data-label="<?= htmlspecialchars($row['name']) ?>"
                        title="<?= _('Folder actions') ?>">
                  <i class="fa fa-folder<?= $row['collapsed'] ? '' : '-open' ?>"></i>
                </button>
                <span class="staxx-spinner"><i class="fa fa-refresh fa-spin"></i></span>
                <span class="staxx-dot<?= $row['running'] ? ' staxx-dot--up' : '' ?>"></span>
              </span>

              <span class="staxx-nameinfo">
                <span class="staxx-folder-name"><?= htmlspecialchars($row['name']) ?></span>
                <span class="staxx-sub" data-cell="folder-sub"><?= staxx_folder_sub($row['count'], $row['running']) ?></span>
              </span>
            </div>
          </span>

          <!-- Totals for everything filed in this folder, added up in the
               browser from the stack rows below it. -->
          <span class="staxx-cell staxx-num" role="gridcell" data-stat="cpu"><span class="staxx-statv">—</span></span>
          <span class="staxx-cell staxx-num" role="gridcell" data-stat="mem"><span class="staxx-statv">—</span></span>
          <span class="staxx-cell staxx-num" role="gridcell" data-stat="net"><span class="staxx-statv">—</span></span>
          <span class="staxx-cell staxx-num" role="gridcell" data-stat="gpu"><span class="staxx-statv">—</span></span>
        </div>

        <!-- Holds no chevron of its own: the folder heading's chevron, one
             level up and outside this wrapper, is what reopens it, so
             collapsing this whole element can never hide the control that
             would undo the collapse. -->
        <div class="staxx-group staxx-group--folder-children" role="presentation"
             data-folder-children="<?= htmlspecialchars($row['id']) ?>"
             <?= $row['collapsed'] ? 'hidden' : '' ?>>
          <div class="staxx-group staxx-folder-inner" role="presentation">
<?
    else:
      $s = $row['stack'];

      // The compose project name is what containers are labelled with, and it
      // is only known from `compose ls` while the stack is up. For a stopped
      // stack, fall back to compose's own default — the LEAF name, normalised
      // the way compose itself does — so the row can still be matched the
      // moment it starts. The browser corrects this from the server's answer
      // once it is running, which matters for any stack whose compose file
      // sets its own `name:`.
      // …but never for a stack awaiting review. That fallback derives the name
      // from the folder, which for an import is the name it was copied FROM,
      // so the stats reply — which is keyed by project — would paint the live
      // containers' processor, memory and network figures onto this row and
      // make an unreviewed import look like it was already working. Blank is
      // safe: the stats reply has no empty-project bucket (staxx_stats() skips
      // a container that has no project), so the row simply shows dashes.
      $project = $s['review'] ? ''
               : ($s['project'] !== '' ? $s['project'] : staxx_project_name($s['leaf']));

      $kids = $s['parses'] ? staxx_stack_children($s) : [];

      // A stack with one service has nothing to break down: the row already
      // names it and gives its state, and a chevron that reveals a single line
      // repeating what is above it is a click that buys nothing. Only stacks
      // with more than one container are expandable, and the others render no
      // child rows at all rather than hidden ones.
      //
      // $kids is kept either way — the stack's own tile is built from its
      // containers' icons, and a single-service stack should show that one
      // container's logo rather than a generic cube.
      $expandable = count($kids) > 1;

      $expanded = $expandable && !empty($row['expanded']);
      $kidsUp   = count(array_filter($kids, fn($k) => $k['state'] === 'running'));

      // A container row is only visible when BOTH its stack is expanded and the
      // folder holding that stack is open. Two independent switches, so the
      // answer is computed rather than toggled — toggling one while the other
      // is shut is where a row reappears out of nowhere.
      $hideKids = $row['hidden'] || !$expanded;

      // aria-level counts real nesting: a folder is 1, a stack is one deeper
      // when it is filed in one, and a container is one deeper again.
      $stackLevel     = $row['folder'] !== '' ? 2 : 1;
      $containerLevel = $stackLevel + 1;

      // Unfiled stacks are listed last and never sit in a folder group — close
      // whatever was open the moment the first one comes round.
      if ($row['folder'] === '' && $inFolderGroup):
        $inFolderGroup = false;
?>
          </div>
        </div>
      </div>
<?
      endif;

      if ($expandable):
?>
      <div class="staxx-group staxx-group--stack" role="presentation" data-stack-group="<?= htmlspecialchars($s['name']) ?>">
<?
      endif;
?>
        <div class="staxx-row staxx-stack-row<?= $row['folder'] !== '' ? ' staxx-nested' : '' ?>"
             role="row" aria-level="<?= $stackLevel ?>"
             <? if ($expandable): ?>aria-expanded="<?= $expanded ? 'true' : 'false' ?>"<? endif; ?>
             data-stack-row="<?= htmlspecialchars($s['name']) ?>"
             data-project="<?= htmlspecialchars($project) ?>"
             data-in-folder="<?= htmlspecialchars($row['folder']) ?>"
             data-expanded="<?= $expanded ? '1' : '0' ?>"
             <?= $row['hidden'] ? 'hidden' : '' ?>>

          <span class="staxx-cell staxx-cell--name staxx-stack-name" role="gridcell">
            <div class="staxx-namebox">
              <? if ($expandable): ?>
                <button type="button" class="staxx-chevron"
                        data-toggle-stack="<?= htmlspecialchars($s['name']) ?>"
                        aria-expanded="<?= $expanded ? 'true' : 'false' ?>"
                        title="<?= $expanded ? _('Hide containers') : _('Show containers') ?>">
                  <i class="fa fa-chevron-<?= $expanded ? 'down' : 'right' ?>"></i>
                </button>
              <? else: ?>
                <span class="staxx-chevron staxx-chevron--empty"></span>
              <? endif; ?>

              <span class="staxx-iconwrap">
                <button type="button"
                        class="staxx-icon<?= $s['parses'] ? '' : ' staxx-icon--bad' ?>"
                        data-menu="stack"
                        data-stack="<?= htmlspecialchars($s['name']) ?>"
                        data-label="<?= htmlspecialchars($s['leaf']) ?>"
                        data-parses="<?= $s['parses'] ? '1' : '0' ?>"
                        data-hasfile="<?= $s['hasFile'] ? '1' : '0' ?>"
                        data-running="<?= $s['running'] ? '1' : '0' ?>"
                        data-review="<?= $s['review'] ? '1' : '0' ?>"
                        data-folder="<?= htmlspecialchars($row['folder']) ?>"
                        title="<?= _('Stack actions') ?>">
                  <?= $s['parses']
                        ? staxx_stack_tile($s, $kids)
                        : '<i class="fa fa-exclamation-triangle"></i>' ?>
                </button>
                <!-- The spinner sits OVER the tile rather than replacing it,
                     which is what Unraid's own Docker page does. It is in the
                     markup from the start and only revealed by a class on the
                     row: a real icon is a picture, not a glyph whose class can
                     be swapped, so there is nothing to swap it back to. -->
                <span class="staxx-spinner"><i class="fa fa-refresh fa-spin"></i></span>
                <? if ($canRun && $s['parses']): ?>
                  <span class="staxx-dot<?= $s['running'] ? ' staxx-dot--up' : '' ?>"></span>
                <? endif; ?>
              </span>

              <span class="staxx-nameinfo">
                <!-- The stack's name IS its directory, full stop — there is no
                     separate display-name override any more. The folder name
                     (if it sits in one) is a different thing, carried by the
                     data attributes above rather than printed here. -->
                <span class="staxx-name-text"><?= htmlspecialchars($s['leaf']) ?></span>
                <? if ($s['review']): ?>
                  <!-- Imported and not yet reviewed — see the "review lock"
                       section of Stacks.php. Read-only marker; the menu item
                       that clears it lives in the stack actions button above. -->
                  <span class="staxx-reviewbadge"
                        title="<?= htmlspecialchars(_('Imported and not yet reviewed. Open the stack and choose Mark as reviewed once checked.')) ?>">
                    <?= _('needs review') ?>
                  </span>
                <? endif; ?>
                <!-- The count is only worth printing for a stack that has more
                     than one container; for a single one the State column
                     already says everything this would. -->
                <span class="staxx-sub">
                  <span data-cell="stack-sub"><?= $expandable ? staxx_stack_sub(count($kids), $kidsUp) : '' ?></span>
                </span>
              </span>
            </div>
          </span>

          <span class="staxx-cell staxx-cell--services" role="gridcell">
            <? if (!$s['hasFile']): ?>
              <span class="red-text"><?= _('No compose file in this folder') ?></span>
            <? elseif (!$s['parses']): ?>
              <span class="red-text"><?= _('Compose cannot read this file') ?></span>
              <span class="staxx-parse-error"><?= htmlspecialchars((string)$s['error']) ?></span>
            <? elseif (!$s['services']): ?>
              <span class="staxx-sub"><?= _('none declared') ?></span>
            <? else: ?>
              <!-- The service keys, always — the row's name no longer stands in
                   for one of them, so there is nothing left to save a line by
                   omitting. -->
              <?= htmlspecialchars(implode(', ', $s['services'])) ?>
              <? if (count($s['services']) === 1): ?>
                <!-- The image goes on a sub-line, but only for a single-service
                     stack: printing every image under a five-service stack
                     would swamp the column, and they are all visible on the
                     expanded child rows anyway. -->
                <? $image = (string)($kids[0]['image'] ?? ''); ?>
                <? if ($image !== ''): ?>
                  <span class="staxx-image staxx-image--sub"
                    title="<?= htmlspecialchars($image) ?>"><?= htmlspecialchars($image) ?></span>
                <? endif; ?>
              <? endif; ?>
            <? endif; ?>
          </span>

          <!-- data-cell names these for the browser: after a start or a stop it
               replaces just these cells rather than the whole row. -->
          <span class="staxx-cell staxx-cell--state" role="gridcell" data-cell="state"><?= staxx_state_pill($s, $canRun) ?></span>
          <span class="staxx-cell staxx-cell--address staxx-addrcell" role="gridcell" data-cell="address"><?=
            staxx_address_html(staxx_merged_addresses(staxx_stack_containers($s)))
          ?></span>

          <!-- Filled in by the browser from the stats endpoint, and left as an
               em dash if nothing is running. Each cell holds a figure and a
               small graph of the last couple of minutes. -->
          <span class="staxx-cell staxx-num" role="gridcell" data-stat="cpu">
            <span class="staxx-statv">—</span>
            <span class="staxx-spark" data-spark="cpu"></span>
          </span>
          <span class="staxx-cell staxx-num" role="gridcell" data-stat="mem">
            <span class="staxx-statv">—</span>
            <span class="staxx-spark" data-spark="mem"></span>
          </span>
          <span class="staxx-cell staxx-num" role="gridcell" data-stat="net">
            <span class="staxx-statv staxx-net">—</span>
            <span class="staxx-spark" data-spark="net"></span>
          </span>
          <span class="staxx-cell staxx-num" role="gridcell" data-stat="gpu">
            <span class="staxx-statv">—</span>
            <span class="staxx-spark" data-spark="gpu"></span>
          </span>
        </div>
<?
      // The children wrapper holds ONLY the container rows — no chevron lives
      // inside it — which is exactly why it is safe to hide as a whole. Hiding
      // .staxx-group--stack instead (tried earlier) hides the stack row's
      // own chevron along with it, taking away the only control that can undo
      // the collapse. $hideKids carries both switches already (this stack's
      // own expand state AND whether its folder is open), so the wrapper's
      // `hidden` is the single source of truth for whether anything under it
      // is on screen.
      if ($expandable):
?>
      <div class="staxx-group staxx-group--children" role="presentation"
           data-stack-children="<?= htmlspecialchars($s['name']) ?>"
           <?= $hideKids ? 'hidden' : '' ?>>
        <div class="staxx-group staxx-children-inner" role="presentation">
<?
      endif;

      /* ---- one row per container inside this stack ---- */
      foreach ($expandable ? $kids : [] as $kid):
?>
        <!-- data-state below is read by the container menu (buildContainerMenu()
             in stacks.js) straight off the row, so it has to be right on the
             very first paint, not only once the first state poll lands.
             applyState() maintains this same attribute afterwards
             (kid.dataset.state = c.state, or '' for a container that does not
             exist) — $kid['state'] is already '' for a service that has never
             been started, which is exactly what applyState writes for the
             same case, so the two never disagree. -->
        <div class="staxx-row staxx-container-row"
             role="row" aria-level="<?= $containerLevel ?>"
             data-in-stack="<?= htmlspecialchars($s['name']) ?>"
             data-in-folder="<?= htmlspecialchars($row['folder']) ?>"
             data-service="<?= htmlspecialchars($kid['key']) ?>"
             data-container="<?= htmlspecialchars($kid['name']) ?>"
             data-project="<?= htmlspecialchars($project) ?>"
             data-state="<?= htmlspecialchars($kid['state']) ?>">

          <span class="staxx-cell staxx-cell--name staxx-stack-name" role="gridcell">
            <div class="staxx-namebox staxx-namebox--child">
              <span class="staxx-iconwrap">
                <!-- A real button now, not a dead <span> — this is the menu
                     trigger for a single container, mirroring the stack row's
                     icon button above. Two attributes here are easy to get
                     wrong:

                     data-service is $kid['service'], the compose SERVICE
                     name — what a compose command actually takes. That is
                     deliberately not the same as the ROW's own data-service
                     above, which is $kid['key'] (service, or
                     "service/container-name" for a replica) — a row-matching
                     key for applyState(), not something compose understands.
                     Two different attributes on two different elements, each
                     built for the one job it does. One consequence worth
                     stating: a command aimed at a replicated service hits
                     every replica, because that is what compose itself does
                     with a bare service name.

                     data-label is the menu's heading. Plain $kid['service']
                     unless $kid['name'] is set and differs from it, in which
                     case "service · containername" — otherwise a replicated
                     service would open two menus with an identical, useless
                     heading. -->
                <button type="button" class="staxx-icon staxx-icon--child"
                        data-menu="container"
                        data-stack="<?= htmlspecialchars($s['name']) ?>"
                        data-service="<?= htmlspecialchars($kid['service']) ?>"
                        data-label="<?= htmlspecialchars(
                          $kid['name'] !== '' && $kid['name'] !== $kid['service']
                            ? $kid['service'].' · '.$kid['name']
                            : $kid['service']
                        ) ?>"
                        title="<?= _('Container actions') ?>">
                  <?= staxx_icon_tile(
                        staxx_icon_resolve($kid['icon'], $s['dir'], $kid['image'],
                                              $kid['service'], $s['name']),
                        $kid['service'] !== '' ? $kid['service'] : $kid['name']) ?>
                </button>
                <span class="staxx-spinner"><i class="fa fa-refresh fa-spin"></i></span>
                <!-- Always present when anything can run, even for a container
                     that does not exist yet: the browser only ever toggles this
                     between grey and green, and a dot that has to be created
                     later is a dot that does not appear. -->
                <? if ($canRun): ?>
                  <span class="staxx-dot<?= $kid['state'] === 'running' ? ' staxx-dot--up' : '' ?>"></span>
                <? endif; ?>
              </span>

              <span class="staxx-nameinfo">
                <span class="staxx-name-text"><?= htmlspecialchars($kid['service']) ?></span>
                <? if ($kid['name'] !== '' && $kid['name'] !== $kid['service']): ?>
                  <span class="staxx-sub"><?= htmlspecialchars($kid['name']) ?></span>
                <? endif; ?>
              </span>
            </div>
          </span>

          <!-- The parent row lists the service names, so this one has room for
               the thing you actually cannot see from up there. -->
          <span class="staxx-cell staxx-cell--services staxx-image" role="gridcell">
            <? if ($kid['image'] !== ''): ?>
              <span title="<?= htmlspecialchars($kid['image']) ?>"><?= htmlspecialchars($kid['image']) ?></span>
            <? else: ?>
              <span class="staxx-sub"><?= _('not created yet') ?></span>
            <? endif; ?>
          </span>

          <span class="staxx-cell staxx-cell--state" role="gridcell" data-cell="state"><?= staxx_container_pill($kid) ?></span>
          <span class="staxx-cell staxx-cell--address staxx-addrcell" role="gridcell" data-cell="address"><?=
            staxx_address_html($kid['id'] !== ''
              ? (staxx_container_net()[$kid['id']]['addresses'] ?? [])
              : [])
          ?></span>

          <span class="staxx-cell staxx-num" role="gridcell" data-stat="cpu">
            <span class="staxx-statv">—</span>
            <span class="staxx-spark" data-spark="cpu"></span>
          </span>
          <span class="staxx-cell staxx-num" role="gridcell" data-stat="mem">
            <span class="staxx-statv">—</span>
            <span class="staxx-spark" data-spark="mem"></span>
          </span>
          <span class="staxx-cell staxx-num" role="gridcell" data-stat="net">
            <span class="staxx-statv staxx-net">—</span>
            <span class="staxx-spark" data-spark="net"></span>
          </span>
          <span class="staxx-cell staxx-num" role="gridcell" data-stat="gpu">
            <span class="staxx-statv">—</span>
            <span class="staxx-spark" data-spark="gpu"></span>
          </span>
        </div>
<?
      endforeach;

      // Children first, then the stack — one guard for both closes rather
      // than two separately-guarded blocks that must stay in the right order.
      if ($expandable):
?>
      </div></div></div>
<?
      endif;

    endif;

  endforeach;

  // Whatever group was left open — a folder with no unfiled stack after it —
  // closes here rather than inside the loop, which only closes one before the
  // NEXT thing that needs it.
  if ($inFolderGroup):
?>
          </div>
        </div>
      </div>
<?
  endif;

  return (string)ob_get_clean();
}

/**
 * Just what changes when a stack starts or stops.
 *
 * This is the cheap refresh, and cheap is the whole point: it costs a single
 * `compose ls` for the entire machine, where re-rendering the table costs one
 * compose invocation per stack to re-read files that cannot have changed.
 * Starting a stack does not alter its service list, its filename, or whether
 * its compose file parses — only whether it is up, and what compose decided to
 * call the project.
 *
 * @return array{stacks:array, folders:array}
 */
function staxx_state_snapshot(): array {
  $canRun = staxx_can_run();
  $states = staxx_stack_states();

  $stacks = [];
  foreach ($states as $name => $s) {
    // Keyed by service, which is what the container rows carry, so the browser
    // can find each row without knowing the container names in advance. That
    // matters the first time a stack is started: those rows were rendered from
    // the compose file and have no container name on them yet.
    $mine       = staxx_stack_containers($s);
    $containers = [];
    $seen       = [];
    foreach ($mine as $c) {
      $service = $c['service'] !== '' ? $c['service'] : $c['name'];
      $key     = isset($seen[$service]) ? $service.'/'.$c['name'] : $service;
      $seen[$service] = true;

      $containers[$key] = [
        'container' => $c['name'],
        'state'     => $c['state'],
        'html'      => staxx_container_pill(['exists' => true] + $c),
        // Ports move when a container is recreated with a changed compose
        // file, so this travels with the state rather than being fixed at
        // render time.
        'address'   => staxx_address_html(staxx_container_net()[$c['id']]['addresses'] ?? []),
      ];
    }

    // No count of declared services here, deliberately. That number comes from
    // the compose file, which this function does not read — and it only changes
    // when the file is edited, which refreshes the whole table anyway. The
    // browser counts the rows it already has instead.
    $stacks[$name] = [
      'running'    => $s['running'],
      // Same fallback the table uses, so the row's project is corrected the
      // moment compose reveals the real one.
      'project'    => $s['project'] !== '' ? $s['project'] : staxx_project_name($s['leaf']),
      'html'       => staxx_state_pill($s, $canRun),
      'address'    => staxx_address_html(staxx_merged_addresses($mine)),
      'containers' => $containers,
    ];
  }

  // Folder headings show how many of their stacks are up, so they move too.
  $folders = [];
  foreach (staxx_folder_names() as $folder) {
    $count = 0;
    $up    = 0;
    foreach ($states as $s) {
      if ($s['folder'] !== $folder) continue;
      $count++;
      if ($s['running']) $up++;
    }
    $folders[$folder] = [
      'running' => $up,
      'html'    => staxx_folder_sub($count, $up),
    ];
  }

  return [
    'stacks'  => $stacks,
    'folders' => $folders,
    // Sent once rather than per row. Stopping a stack removes its containers,
    // so every container row of a stopped stack needs this same pill, and the
    // browser has no business inventing the wording itself.
    'notCreated' => staxx_container_pill(['exists' => false]),
    // Likewise for the address cell of a container that no longer exists.
    'noAddress'  => staxx_address_html([]),
  ];
}
?>
