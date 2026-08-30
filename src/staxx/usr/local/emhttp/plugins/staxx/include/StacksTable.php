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
require_once '/usr/local/emhttp/plugins/staxx/include/Autostart.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Import.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Updates.php';

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
    $ip    = (string)($a['ip'] ?? '');
    $label = (string)($a['label'] ?? $ip);
    if ($label === '') continue;

    $title = ($ip !== $label && $ip !== '')
      ? ' title="'.htmlspecialchars(sprintf(_('on %s'), $ip)).'"'
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
 * The same "declared port is only a fallback" correction staxx_address_html()
 * gets for a single container (see staxx_address_webui_override()) is applied
 * here too, per container before its addresses are folded together — so the
 * stack row never shows a different port than the container row underneath it.
 *
 * @param array $containers rows from staxx_container_index()
 * @param array<string,string> $webuiById container id => staxx_webui_url() result,
 *                                        e.g. from staxx_stack_children()
 */
function staxx_merged_addresses(array $containers, array $webuiById = []): array {
  $net = staxx_container_net();

  // Grouped by the label, not by the address behind it. Three containers of one
  // stack sharing a compose network collapse to a single "multi-tier_default"
  // entry holding all three ports, which is the whole point — the alternative
  // repeats the same name on three lines.
  $groups = [];
  foreach ($containers as $c) {
    $rec = $net[$c['id']] ?? [];
    $addresses = staxx_address_webui_override(
      $rec['addresses'] ?? [], $rec['published'] ?? false,
      $webuiById[$c['id']] ?? '', $rec['exposed'] ?? []
    );
    foreach ($addresses as $a) {
      $key = (string)($a['label'] ?? $a['ip'] ?? '');
      if ($key === '') continue;
      if (!isset($groups[$key])) $groups[$key] = ['ip' => $a['ip'] ?? '', 'ports' => []];
      foreach ((array)($a['ports'] ?? []) as $port) $groups[$key]['ports'][$port] = true;
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
 * The update pill for one row, drawn beside the state pill it already sits
 * next to (folder, stack and service) rather than in a column of its own —
 * a column would cost every row the same sliver of width for something most
 * rows have nothing to say about.
 *
 * `current` (checked, and it matches) and `unknown` (never checked at all)
 * both render nothing — a page that has never run a check must not shout
 * "up to date" on every row, and once it has, "up to date" on forty rows is
 * noise that buries the three that matter. The two are told apart by
 * $u['state'] itself, which already distinguishes them; nothing here needs
 * to guess.
 *
 * label/tip/image/source all come out of a registry — a stranger's text —
 * and are escaped exactly like every other value this file prints. source
 * is the "what changed" link, carried as a data attribute rather than a
 * tooltip here so the row menu can read it straight off the pill instead of
 * asking the server again for something this same reply already knew.
 *
 * @param array $u from staxx_updates_for_row() / staxx_updates_for_folder()
 * @param bool  $pressable whether this row has somewhere for a press to go —
 *                         false on a folder row, whose updates are a queue the
 *                         folder's own menu starts, not a single command
 */
function staxx_update_pill_html(array $u, bool $pressable = true): string {
  $state = (string)($u['state'] ?? 'unknown');

  // Only the states with something worth flagging get a modifier class; an
  // unrecognised value (a future state this file has not been taught about
  // yet) falls through to showing nothing rather than guessing at a colour.
  $cls = [
    'update'     => 'staxx-updatepill--update',
    // A locally built image whose base has moved on. Worth acting on, like
    // an update, so it shares that colour rather than built's quiet one.
    'rebuild'    => 'staxx-updatepill--rebuild',
    'built'      => 'staxx-updatepill--built',
    'missing'    => 'staxx-updatepill--missing',
    'error'      => 'staxx-updatepill--error',
    // A withdrawn tag is factual, not alarming — nothing is broken right
    // now — so it gets the same quiet treatment as built/missing rather
    // than error's louder colour.
    'tagmissing' => 'staxx-updatepill--tagmissing',
    // A registry move is the same kind of fact, not an alarm — see
    // PLAN_61 — so it reuses tagmissing's quiet styling rather than a class
    // of its own; the two can be split apart later if they ever need to
    // look different.
    'moved'      => 'staxx-updatepill--tagmissing',
    // PLAN_62 Stage 3 — the author's published example says something this
    // file does not. Also a fact, not an alarm (it is a suggestion, never
    // proven correct), so it shares the same quiet styling too.
    'watch'      => 'staxx-updatepill--tagmissing',
  ][$state] ?? '';
  if ($cls === '') return '';

  $label  = htmlspecialchars((string)($u['label'] ?? ''));
  $image  = htmlspecialchars((string)($u['image'] ?? ''));
  // Same ^https?:// gate every href in this file uses (see
  // staxx_row_actions_html() below) — stacks.js opens this value with
  // window.open(), so an unrecognised scheme is dropped here rather than
  // trusted through to it.
  $rawSource = (string)($u['source'] ?? '');
  $source = preg_match('~^https?://~i', $rawSource) ? htmlspecialchars($rawSource) : '';
  $title  = (string)($u['tip'] ?? '');
  // PLAN_90 Stage 3b — the full explanation for a flagged image (repeated
  // failure, not found, unsupported). A note describes one image, so a
  // folder row (its updates are a queue, not one image — $pressable false)
  // must never show one, whatever the caller happened to pass in.
  $note = $pressable ? (string)($u['note'] ?? '') : '';
  $noteAttr = $note !== '' ? ' data-update-note="'.htmlspecialchars($note).'"' : '';
  // The note is appended to the existing tip rather than replacing it, so
  // hovering still gives the version tip where there is one, plus why
  // nothing is happening.
  $titleBits = array_filter([$title, $note], function ($s) { return $s !== ''; });
  $titleAttr = $titleBits ? ' title="'.htmlspecialchars(implode("\n\n", $titleBits)).'"' : '';

  // Only `update` becomes a real button, and only where a press has somewhere
  // to go. Every other state is text, and a button that can only ever do
  // nothing is worse than a span — `rebuild` is deliberately among them,
  // because rebuilding names one service and a stack row cannot. Classes and
  // data-update-* attributes are identical either way, so stacks.js's
  // paintUpdatePill()/paintPillClock() need no change: both read the pill by
  // its class, never by its tag.
  $tag = ($pressable && $state === 'update') ? 'button' : 'span';
  $typeAttr = $tag === 'button' ? ' type="button"' : '';

  // The clock's own three values, written here as well as by the browser so a
  // countdown is right in the very first render rather than only once the
  // updates poll has been round. paintPillClock() in stacks.js reads exactly
  // these, and `back` is what lets the row menu hide roll back when there is
  // nothing kept to roll back to.
  // A noted pill (a chip's worth of "why" backed by a full sentence) gets
  // its own modifier so it reads as more wrong than a plain quiet error,
  // without inventing a new state — the state is still 'error'.
  $cls .= $note !== '' ? ' staxx-updatepill--noted' : '';

  return '<'.$tag.' class="staxx-updatepill '.$cls.'"'.$typeAttr
       . ' data-update-state="'.htmlspecialchars($state).'"'
       . ' data-update-image="'.$image.'"'
       . ' data-update-source="'.$source.'"'
       . ' data-update-due="'.(int)($u['due'] ?? 0).'"'
       . ' data-update-hold="'.(!empty($u['hold']) ? '1' : '0').'"'
       . ' data-update-back="'.(!empty($u['back']) ? '1' : '0').'"'
       . ' data-update-why="'.htmlspecialchars((string)($u['why'] ?? '')).'"'
       . ' data-update-suggest="'.htmlspecialchars((string)($u['suggest'] ?? '')).'"'
       . $noteAttr
       . $titleAttr.'>'.$label.'</'.$tag.'>';
}

/**
 * PLAN_62 Stage 3 — how many things this stack's author-example comparison
 * found worth a look, straight off the state file Stage 2 already wrote.
 * No network and no compose parsing here — just counting an array that is
 * already there, so this costs nothing extra on every row of every render.
 */
function staxx_watch_count_for_stack(string $stack): int {
  $watch = (array)(staxx_update_state()['stacks'][$stack]['watch'] ?? []);
  $count = 0;
  // PLAN_62 Stage 4 — a dismissed finding must not still swell this count;
  // staxx_watch_active_findings() is the one place that decides "dismissed",
  // shared with the field grafts and the combined report below.
  foreach ($watch as $entry) {
    if (is_array($entry)) $count += count(staxx_watch_active_findings($entry));
  }
  return $count;
}

/**
 * Overlays a "N to look at" pill onto one already computed by
 * staxx_updates_for_row()/staxx_updates_for_folder() — the same way PLAN_61's
 * 'moved' is promoted only from 'current' (see
 * staxx_updates_apply_service_state()), so this can never outrank a real
 * problem. Kept here rather than in Updates.php's own rank table on purpose:
 * this is presentation only, and nothing else in the plugin (the notifier,
 * the check pass itself) needs to know a row looks this way.
 */
function staxx_watch_apply_pill(array $u, int $count): array {
  if ($count <= 0) return $u;
  if (!in_array($u['state'] ?? 'unknown', ['unknown', 'current'], true)) return $u;
  $u['state'] = 'watch';
  $u['label'] = $count.' to look at';
  $u['tip']   = $count === 1
    ? 'The author\'s published example does one thing differently here that this file does not. '
    . 'Open the stack to see it.'
    : 'The author\'s published example does '.$count.' things differently here that this file '
    . 'does not. Open the stack to see them.';
  return $u;
}

/**
 * PLAN_85 — joins service names into one clause naming all of them: "fldb",
 * "fldb" and "adminer", or "fldb", "adminer" and "cron" for three or more.
 * Plain double quotes, matching how this file already quotes a stack's own
 * name back to the user (e.g. the "already exists" refusal in action.php).
 */
function staxx_watch_join_names(array $names): string {
  $quoted = array_map(static fn($n) => '"'.$n.'"', $names);
  if (count($quoted) === 1) return $quoted[0];
  $last = array_pop($quoted);
  return implode(', ', $quoted).' and '.$last;
}

/**
 * PLAN_62 Stage 3 — this stack's Stage-2 findings, reshaped for the `read`
 * reply: per service, the settings the author's own example adds or drops;
 * plus one sentence for every image that could not be compared at all,
 * worded as a fact ("this author publishes no example"), never a failure.
 * A pure read of the state file and the compose metadata staxx_compose_meta()
 * already caches — no network, so opening the editor costs nothing extra.
 *
 * @return array{findings: array<string, array>, notes: string[]}
 */
function staxx_watch_for_stack(string $stack): array {
  $empty = ['findings' => [], 'notes' => []];

  // Same cheap lookup staxx_updates_moved_for_stack() uses, rather than
  // staxx_list_stacks() — see its own comment for why.
  $file = '';
  foreach (staxx_scan_stacks()['stacks'] as $s) {
    if ($s['rel'] === $stack) { $file = staxx_find_compose_file($s['dir']); break; }
  }
  $meta = $file !== '' ? staxx_compose_meta($file) : ['ok' => false, 'services' => []];
  if (!$meta['ok']) return $empty;

  $state       = staxx_update_state();
  $stackWatch  = (array)($state['stacks'][$stack]['watch'] ?? []);
  $imagesState = (array)$state['images'];

  $findings = [];
  $notes    = [];

  // Collected before any lookup: an image-level reason is said once even when
  // several services here share it (PLAN_85 — and now names all of them, so
  // "nobody needs to be told twice" no longer costs "told about nobody").
  $imageServices = [];
  foreach ($meta['services'] as $svc => $svcMeta) {
    $image = trim((string)($svcMeta['image'] ?? ''));
    if ($image === '') continue;
    $imageServices[$image][] = $svc;
  }

  foreach ($imageServices as $image => $services) {
    $svc   = $services[0];
    $entry = $stackWatch[$image] ?? null;
    // PLAN_62 Stage 4 — a dismissed finding is left out here too, the same
    // filter the row's count and the combined report both go through.
    $active = is_array($entry) ? staxx_watch_active_findings($entry) : [];
    if ($active !== []) {
      foreach ($active as $f) {
        // 'image' rides along so a dismiss button can name exactly what
        // staxx_watch_skip() needs (PLAN_62 Stage 4) — the field itself
        // never carries it otherwise, since a service's image is not one of
        // its own settings.
        // 'home_from' rides along for the same reason — where the example's
        // address came from is a property of the image's check, not of the
        // setting, and the browser says so beside a finding whose address was
        // a third party's claim rather than the publisher's own (PLAN_62
        // Stage 5). '' for state written before that was recorded.
        $findings[(string)($f['service'] ?? $svc)][] = [
          'setting'   => (string)($f['setting'] ?? ''),
          'side'      => (string)($f['side'] ?? ''),
          'image'     => $image,
          'home_from' => (string)($entry['home_from'] ?? ''),
        ];
      }
      continue;
    }

    // Nothing to graft onto a field — either the comparison genuinely found
    // no differences (silence, correctly), or it could not even be
    // attempted. staxx_watch_compare() records the former reason on the
    // stack's own entry; staxx_watch_check() records the latter on the
    // image's, since discovery is a property of the image, not this stack.
    $reason = '';
    if (is_array($entry) && ($entry['compare_error'] ?? '') !== '') {
      $reason = (string)$entry['compare_error'];
    } elseif (($imagesState[$image]['watch']['reason'] ?? '') !== '') {
      $reason = (string)$imagesState[$image]['watch']['reason'];
    }
    // PLAN_85 — the reason is a sentence fragment worded about "this image"
    // generically ("no known project home for this image"); rather than
    // rewrite that wording per reason, the service name(s) are appended as
    // their own clause, which reads correctly for every reason in the list.
    if ($reason !== '') {
      $named = staxx_watch_join_names($services);
      $notes[] = ucfirst($reason).', used by '.$named.'.';
    }
  }

  return ['findings' => $findings, 'notes' => $notes];
}

/**
 * PLAN_85 — one icon per service, for the editor's heading row.
 *
 * Resolves the same way staxx_stack_children()'s icon lookup does: a stated
 * icon always wins, and a service with none falls through to
 * staxx_icon_match()'s guess from the image name — a guess is a stand-in,
 * not a lie, so the editor and the table row must never disagree.
 *
 * @return array<string, array{html: string, q: string}>
 */
function staxx_service_icons_for_stack(string $stack): array {
  // Same cheap lookup staxx_watch_for_stack() uses, rather than
  // staxx_list_stacks() — see its own comment for why.
  $file = '';
  foreach (staxx_scan_stacks()['stacks'] as $s) {
    if ($s['rel'] === $stack) { $file = staxx_find_compose_file($s['dir']); break; }
  }
  if ($file === '') return [];
  $meta = staxx_compose_meta($file);
  if (!$meta['ok']) return [];

  $dir = dirname($file);
  $out = [];
  foreach ($meta['services'] as $svc => $svcMeta) {
    $icon  = (string)($svcMeta['x']['icon'] ?? '');
    $image = trim((string)($svcMeta['image'] ?? ''));
    $out[$svc] = [
      'html' => staxx_icon_tile(staxx_icon_resolve($icon, $dir, $image, $svc, $stack), $svc),
      'q'    => $image !== '' ? (staxx_icon_candidates($image)[0] ?? '') : '',
    ];
  }
  return $out;
}

/**
 * PLAN_86 — the walk that finds services worth recording an icon for, and
 * copies each picture into its own stack's folder as it goes: an item is
 * only ever offered once the file it names is already sitting there.
 *
 * Skips, quietly, each for its own reason: a stack in $skip (the editor is
 * open on it right now); a stack whose compose file did not parse; a service
 * that already records an icon (never overwritten, ever); a service with no
 * image; an image staxx_icon_match() cannot place; and a copy that failed
 * for any reason. None of those stop the walk — only the cap does.
 *
 * Same call, same arguments, as the grid's own child rows (see
 * staxx_stack_tile()) — what gets recorded is exactly what the grid was
 * already showing, never a fresh guess.
 *
 * @return array<int, array{stack:string, service:string, file:string}>
 */
function staxx_icon_adopt_sweep(array $skip, int $cap, bool &$done): array {
  $skip   = array_flip($skip);
  $out    = [];
  $cutoff = false;

  foreach (staxx_scan_stacks()['stacks'] as $s) {
    if (isset($skip[$s['rel']])) continue;

    $file = staxx_find_compose_file($s['dir']);
    if ($file === '') continue;

    $meta = staxx_compose_meta($file);
    if (!$meta['ok']) continue;

    foreach ($meta['services'] as $svc => $svcMeta) {
      if (trim((string)($svcMeta['x']['icon'] ?? '')) !== '') continue;

      $image = trim((string)($svcMeta['image'] ?? ''));
      if ($image === '') continue;

      // $s['rel'], not the leaf: a stack row's 'name' IS its path under the
      // root, and that is what the grid hands staxx_icon_match() as its last
      // candidate. Passing the leaf here instead would let a stack inside a
      // folder match something the grid never showed, and recording that
      // would change what it looks like — the one thing this must not do.
      $ref = staxx_icon_match($image, $svc, $s['rel']);
      if ($ref === '') continue;

      $error = '';
      $written = staxx_icon_adopt($ref, $s['dir'], $error);
      if ($written === '') continue;

      $out[] = ['stack' => $s['rel'], 'service' => $svc, 'file' => $written];
      if (count($out) >= $cap) { $cutoff = true; break 2; }
    }
  }

  // Only a cap-cut walk is unfinished — one that ran out of stacks and
  // services on its own has genuinely seen everything there is.
  $done = !$cutoff;
  return $out;
}

/**
 * The web page and logs buttons drawn under a row's icon.
 *
 * One renderer for both row types: a stack row and a container row need the
 * same two buttons pointed at different targets, and writing the markup twice
 * would be two copies to keep in sync rather than one. Hidden entirely by the
 * caller for a stack awaiting review, alongside the run verbs.
 *
 * @param string $stack   the stack's name — data-stack on both buttons
 * @param string $service the service name, or '' on the stack row (whose logs
 *                         button means "every service")
 * @param string $url     staxx_webui_url() for the one service this button
 *                         speaks for, or '' when there is none
 * @param bool   $running whether that service's own container is running
 * @param string $offWhy  why the web button is off when $url is ''; ignored
 *                         when $url is set (then it is always "not running")
 * @param string $project the service's (or, on the stack row, the one
 *                         candidate's) resolved project-page URL, or '' —
 *                         every row gets all four chips, Repo and CA
 *                         disabled the same way WebUI is when there is
 *                         nothing to link to
 * @param string $support the service's resolved support/forum-thread URL, or ''
 * @param string $linksOffWhy why Repo/CA are off, used for both chips alike
 *                         since a stack's reason for having neither is one
 *                         reason, not two; '' keeps the default per-chip
 *                         sentence ("no project page" / "no support thread")
 */
function staxx_row_actions_html(string $stack, string $service, string $url, bool $running, string $offWhy, string $project = '', string $support = '', string $linksOffWhy = ''): string {
  $label = _('Open web page');

  // Checked here as well as in staxx_webui_url(), which is the only caller
  // that has any business producing one — because this is the function that
  // writes an href, and the value behind it comes out of a file the user can
  // type anything into. Escaping is not enough on its own: a `javascript:`
  // URL contains nothing that needs escaping and would still run on click.
  // The one place that emits the attribute is the right place to be sure.
  if (!preg_match('~^https?://~i', $url)) $url = '';

  // Named in words rather than by an icon, and the pair sized to match each
  // other by the stylesheet's grid — an external-link glyph and a document
  // glyph are not something anybody reads at 11px beside a container tile.
  // Web button first: the pair is stacked, and the one that opens the app
  // belongs above the one that explains why it will not.
  $webText  = _('WebUI');
  $logsWord = _('Logs');

  if ($url !== '' && $running) {
    $web = '<a class="staxx-webbtn" href="'.htmlspecialchars($url).'"'
         . ' target="_blank" rel="noopener" title="'.htmlspecialchars($label).'">'
         . htmlspecialchars($webText).'</a>';
  } else {
    // $url set but not running says one thing; no $url at all (or, on the
    // stack row, more than one candidate) says another — the caller knows
    // which applies here.
    $why = $url !== '' ? _('Start the container to open its web page.') : $offWhy;
    $web = '<span class="staxx-webbtn staxx-webbtn--off" title="'.htmlspecialchars($why).'">'
         . htmlspecialchars($webText).'</span>';
  }

  $svcAttr  = $service !== '' ? ' data-service="'.htmlspecialchars($service).'"' : '';
  $logsText = _('View logs');
  // Never disabled — logs are worth reading precisely when something is not
  // running, which is the one moment a disabled button would hide them.
  $logs = '<button type="button" class="staxx-logsbtn" data-logs="'.htmlspecialchars($stack).'"'.$svcAttr
        . ' title="'.htmlspecialchars($logsText).'">'
        . htmlspecialchars($logsWord).'</button>';

  // Repo and CA: the same href gate as WebUI above (a `javascript:` URL has
  // nothing to escape and would still run on click). Greyed and inert rather
  // than omitted when there is nothing to link to, same as WebUI's own
  // disabled shape above — so the icon column never reflows depending on
  // whether this image happens to carry a project page or a support thread.
  // Every row builds both chips, stack and service alike: the stack row
  // either has one clear candidate (mirroring $url/$running above) or shares
  // one reason via $linksOffWhy for having neither.
  // Both link values come out of a registry label or a third-party feed, so
  // they are escaped exactly like every other stranger's text this file
  // prints.
  if ($project !== '' && preg_match('~^https?://~i', $project)) {
    $repo = '<a class="staxx-webbtn" href="'.htmlspecialchars($project).'"'
          . ' target="_blank" rel="noopener" title="'.htmlspecialchars(_('Open the project page')).'">'
          . htmlspecialchars(_('Repo')).'</a>';
  } else {
    $repoWhy = $linksOffWhy !== '' ? $linksOffWhy : _('This image has no project page to open.');
    $repo = '<span class="staxx-webbtn staxx-webbtn--off" title="'.htmlspecialchars($repoWhy).'">'
          . htmlspecialchars(_('Repo')).'</span>';
  }

  if ($support !== '' && preg_match('~^https?://~i', $support)) {
    $ca = '<a class="staxx-webbtn" href="'.htmlspecialchars($support).'"'
        . ' target="_blank" rel="noopener" title="'.htmlspecialchars(_('Open the support thread')).'">'
        . htmlspecialchars(_('CA')).'</a>';
  } else {
    $caWhy = $linksOffWhy !== '' ? $linksOffWhy : _('This image has no support thread to open.');
    $ca = '<span class="staxx-webbtn staxx-webbtn--off" title="'.htmlspecialchars($caWhy).'">'
        . htmlspecialchars(_('CA')).'</span>';
  }

  // Every row carries all four chips, enabled or disabled, so the 2x2 block
  // is the same size on a stack row as on a service row. See
  // .staxx-rowactions--wide in sheets/staxx.css.
  return '<span class="staxx-rowactions staxx-rowactions--wide">'.$web.$logs.$repo.$ca.'</span>';
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
 *                          image:string, icon:string, webui:string, project:string,
 *                          support:string, state:string, status:string, exists:bool}>
 */
function staxx_stack_children(array $s): array {
  // Memoised on the stack's name: the folder strip and the stack row both ask
  // for the same stack's children within one request, and nothing here can
  // change under a stack mid-request, so the second call is free rather than
  // re-reading the compose file and re-merging docker's view of it again.
  static $memo = [];
  if (isset($memo[$s['name']])) return $memo[$s['name']];

  $containers = staxx_stack_containers($s);

  // What the compose file declares, which is the only source for a service that
  // has never been started: no container means docker has no image name for it,
  // and no image name means no icon until the first time it runs.
  //
  // $stackX rides along for project/support: those keys are the older,
  // stack-level form of the metadata and have to keep working for a service
  // that never got its own copy. $declared is already in hand for icon and
  // webui, so reading it costs nothing extra — staxx_compose_meta() is the
  // one call every row here already relies on, cached on disk.
  $meta     = $s['file'] !== '' ? staxx_compose_meta($s['file']) : ['services' => [], 'x' => []];
  $declared = $meta['services'];
  $stackX   = $meta['x'];

  $rows = [];
  $hostIp = staxx_host_ip();

  // Falls back to compose document order (already $s['services']'s own order)
  // for any service nobody has ever dragged.
  $order = staxx_start_load()['services'][$s['name']] ?? [];
  foreach (staxx_start_sort($s['services'], $order) as $service) {
    $rows[$service] = [
      'key' => $service, 'service' => $service, 'name' => '', 'id' => '',
      'image' => (string)($declared[$service]['image'] ?? ''),
      // What the file asks for, kept alongside docker's answer below so a
      // reader can be told the two disagree — never re-read here.
      'declared' => (string)($declared[$service]['image'] ?? ''),
      'icon'  => (string)($declared[$service]['x']['icon'] ?? ''),
      // Read here rather than only where it is drawn, so a service that has
      // never run still has one — declared[$service] is the compose file's
      // own view, nothing docker knows.
      'webui' => staxx_webui_url($declared[$service] ?? [], $hostIp),
      'project' => (string)($declared[$service]['x']['project'] ?? $stackX['project'] ?? ''),
      'support' => (string)($declared[$service]['x']['support'] ?? $stackX['support'] ?? ''),
      'state' => '', 'status' => '', 'exists' => false,
    ];
  }

  // Hoisted rather than called per row: it inspects every container on the
  // server once and is statically cached, so this costs nothing extra — the
  // ports column elsewhere in this render already forces the same call.
  $net = staxx_container_net();

  foreach ($containers as $c) {
    $service = $c['service'] !== '' ? $c['service'] : $c['name'];
    $key     = $service;

    // A service running more than one container — compose replicas — would
    // otherwise overwrite itself and report one container where there are
    // several. Each gets its own row, told apart by container name.
    if (isset($rows[$key]) && $rows[$key]['exists']) $key = $service.'/'.$c['name'];

    // The list is already sorted with this server's own address first (see
    // staxx_container_net()), so the first entry is the one worth trying —
    // taking any other would prefer a macvlan address over one that is
    // actually reachable from wherever this page is being read.
    $addr = $net[$c['id']]['addresses'][0]['ip'] ?? '';

    $rows[$key] = [
      'key'     => $key,
      'service' => $service,
      'name'    => $c['name'],
      'id'      => $c['id'],
      // Docker's image, because that is the one actually running — a compose
      // file edited since the container was created says something else.
      'image'   => $c['image'] !== '' ? $c['image'] : (string)($declared[$service]['image'] ?? ''),
      // What the file asks for right now, so the browser can flag it against
      // the running image above when the two no longer match.
      'declared' => (string)($declared[$service]['image'] ?? ''),
      'icon'    => (string)($declared[$service]['x']['icon'] ?? ''),
      'webui'   => staxx_webui_url(
                     $declared[$service] ?? [], $hostIp,
                     $addr, (string)($net[$c['id']]['mode'] ?? ''), (string)($net[$c['id']]['driver'] ?? '')
                   ),
      'project' => (string)($declared[$service]['x']['project'] ?? $stackX['project'] ?? ''),
      'support' => (string)($declared[$service]['x']['support'] ?? $stackX['support'] ?? ''),
      'state'   => $c['state'],
      'status'  => $c['status'],
      'exists'  => true,
    ];
  }

  return $memo[$s['name']] = array_values($rows);
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
 * The stack's own icon, resolved — or null when it does not say anything
 * usable, so the caller falls through to its children's icons.
 *
 * A stack's own `icon:` outranks its children's, but an address that cannot
 * be downloaded is not an answer. Converted Unraid templates carry plenty of
 * these: StirlingPDF's still names a favicon under the project's former
 * "Frooodle" account, which 404s, and the initials tile that produced was
 * indistinguishable from a stack whose icon had never been set — while the
 * editor, reading the service's own icon, showed the real logo. A download
 * already known to have failed therefore counts as no icon at all.
 *
 * Not a permanent verdict, and deliberately not one for an icon merely
 * waiting to be fetched: that keeps its reference so the page can swap the
 * picture in when it arrives, and the failure marker expires on its own, so
 * a site that was briefly down recovers without anything being edited.
 */
function staxx_stack_own_icon(array $s): ?array {
  $own = (string)($s['x']['icon'] ?? '');
  if ($own === '') return null;

  $icon = staxx_icon_resolve($own, $s['dir']);
  if ($icon['fa'] === '' && $icon['url'] === ''
      && ($icon['ref'] === '' || staxx_icon_missed($icon['ref']))) return null;

  return $icon;
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
  // Initials come from the title, so the letters in the tile are the letters
  // written beside it. The folder name is not what anyone is reading here.
  $own = staxx_stack_own_icon($s);
  if ($own !== null) return staxx_icon_tile($own, $s['leaf']);

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
 * A stack's icon at strip size: one tile, never the mosaic.
 *
 * Used for the folder-row strip, where four tiny smudges are less legible
 * than one. Same precedence as staxx_stack_tile(), just stopping after the
 * first picture instead of tiling every child: the stack's own x-unraid icon
 * first, then its first child's, then the generic cube. Resolved through the
 * same staxx_icon_resolve()/staxx_icon_tile() pair with the same arguments so
 * this can never pick a different picture than the stack's own row does.
 *
 * @param array $kids from staxx_stack_children()
 */
function staxx_stack_strip_tile(array $s, array $kids): string {
  $own = staxx_stack_own_icon($s);
  if ($own !== null) return staxx_icon_tile($own, $s['leaf']);

  if (!$kids) {
    return '<i class="fa fa-cubes"></i>';
  }

  $kid = $kids[0];
  return staxx_icon_tile(
    staxx_icon_resolve($kid['icon'], $s['dir'], $kid['image'], $kid['service'], $s['name']),
    $kid['service'] !== '' ? $kid['service'] : $kid['name']
  );
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
 * The drag grip drawn on every folder, stack and container row (PLAN_43).
 *
 * Always drawn, disabled with a reason when dragging cannot mean anything
 * here — the same doctrine staxx_row_actions_html() already argues for the
 * webui/logs pair: omitting it on some rows pulls the name column out of
 * alignment.
 *
 * @param string $kind  'folder', 'stack' or 'service' — matches data-row-grip
 * @param string $label what the aria-label says this grip moves
 */
function staxx_grip_html(string $kind, string $label, bool $disabled, string $disabledWhy): string {
  $title = $disabled ? $disabledWhy : _('Drag to reorder, or use the up and down arrow keys');
  $cls   = 'staxx-grip'.($disabled ? ' staxx-grip--off' : '');
  return '<button type="button" class="'.$cls.'" data-row-grip="'.htmlspecialchars($kind).'"'
       . ($disabled ? ' disabled' : '')
       . ' title="'.htmlspecialchars($title).'"'
       . ' aria-label="'.htmlspecialchars(sprintf(_('Reorder %s'), $label)).'">'
       . '<i class="fa fa-bars"></i></button>';
}

/**
 * The sentence a boot marker's title carries — one thing said plainly rather
 * than a code the reader has to look up, since this is the only place that
 * says whether a row starts at boot at all.
 *
 * @param string $mode        'all'/'on', 'some', or 'none'/'off'
 * @param string $groupNoun   what "some of its ___ start at boot" fills in —
 *                             'stacks' for a folder, 'services' for a stack;
 *                             unused (and left '') for a single container.
 */
function staxx_boot_title(string $mode, int $wait, bool $interleaved, string $groupNoun): string {
  if ($mode === 'all' || $mode === 'on') {
    $text = $wait > 0
      ? sprintf(_('Starts at boot, then waits %d seconds before the next one starts.'), $wait)
      : _('Starts at boot.');
  } elseif ($mode === 'some') {
    $text = sprintf(_('Some of its %s start at boot.'), $groupNoun);
  } else {
    $text = _('Does not start at boot.');
  }

  if ($interleaved) {
    // Only ever true for a stack: a flat autostart list can interleave one
    // stack's lines with another's, which the folder/stack/service tree
    // cannot express — see the module comment in Autostart.php.
    $text .= ' '._("Unraid's own Docker page has this stack's containers out of one run — "
                  . 'StaXX will gather them together the next time anything is dragged.');
  }

  return $text;
}

/**
 * The always-rendered boot marker inside .staxx-nameinfo. Hiding it
 * entirely for the "off" case is left to CSS, keyed off the row's own
 * data-boot attribute — see sheets/staxx.css — because the row element is
 * what the browser actually toggles after a drag or a switch.
 */
function staxx_boot_mark_html(int $wait, string $title): string {
  $waitHtml = $wait > 0
    ? '<span class="staxx-boot-wait">'.htmlspecialchars($wait.'s').'</span>'
    : '';
  return '<span class="staxx-boot" data-boot-mark title="'.htmlspecialchars($title).'">'
       . '<i class="fa fa-bolt"></i>'.$waitHtml.'</span>';
}

/**
 * The small marker on a stack row whose Compose Manager source has changed
 * since it was imported — see staxx_import_drift(). Icon-only with a
 * tooltip, the same shape as staxx_boot_mark_html(), because this is rare
 * enough that spelling it out in a badge on every row would be noise.
 */
function staxx_drift_mark_html(string $title): string {
  return '<span class="staxx-driftmark" title="'.htmlspecialchars($title).'">'
       . '<i class="fa fa-exclamation-triangle"></i></span>';
}

/**
 * PLAN_71 Stage 4a — the "restart pending" chip on a stack row: what is
 * running does not match what the file now says. Deliberately its own
 * classes, never staxx-updatepill's or staxx-driftmark's — those answer two
 * unrelated questions (an image update; an Unraid template moved on since
 * import) and must never look like the same thing as this one.
 *
 * A real button, because pressing it opens an explanation panel (built in
 * stacks.js) — it never restarts anything itself. The data attributes carry
 * everything that panel needs so a press costs nothing further.
 *
 * @param array $p from staxx_restart_pending()
 */
function staxx_pending_chip_html(array $p): string {
  if (($p['state'] ?? '') !== 'pending') return '';

  $title = _('The running containers do not yet reflect what is on screen. Restarting rebuilds them from the file as it stands; nothing is wrong until then.');

  return '<button type="button" class="staxx-pendingchip"'
       . ' data-stack="'.htmlspecialchars((string)($p['stack'] ?? '')).'"'
       . ' data-edited="'.(int)($p['edited'] ?? 0).'"'
       . ' data-changed="'.htmlspecialchars(implode(',', $p['changed'] ?? [])).'"'
       . ' data-absent="'.htmlspecialchars(implode(',', $p['absent'] ?? [])).'"'
       . ' data-leftover="'.htmlspecialchars(implode(',', $p['leftover'] ?? [])).'"'
       . ' aria-label="'.htmlspecialchars($title).'"'
       . ' title="'.htmlspecialchars($title).'">'
       . '↻ '._('Restart to apply').'</button>';
}

/**
 * The same chip's icon-only form for one service row — see
 * staxx_pending_chip_html() above for why this shares no class with the
 * update pill or the drift mark. '' for 'match', 'unknown', or anything this
 * has not been taught about: those are exactly the cases with nothing to
 * report.
 */
function staxx_pending_service_chip_html(string $kind): string {
  $title = [
    'changed'  => _('Settings changed since this started'),
    'absent'   => _('Not started yet'),
    'leftover' => _('No longer in the file'),
  ][$kind] ?? '';
  if ($title === '') return '';

  return '<span class="staxx-pendingchip staxx-pendingchip--service" title="'.htmlspecialchars($title).'"'
       . ' aria-label="'.htmlspecialchars($title).'">↻</span>';
}

/**
 * The small marker beside the image column when the compose file asks for one
 * image and the running container is another — a save that has not yet been
 * followed by a start or restart. Deliberately its own class rather than
 * staxx_drift_mark_html()'s: that one means the Compose Manager project this
 * stack was copied from has changed since, an unrelated fact, and sharing a
 * class would blur the two together.
 *
 * Rendered here AND repainted by applyState() in stacks.js on every state
 * poll, so the marker is right on the very first paint and stays right
 * afterwards without a page reload — see the comment on applyState() for how
 * the two stay in step.
 */
function staxx_image_mismatch_html(string $declared, string $running): string {
  if ($declared === '' || $running === '' || $declared === $running) return '';
  $title = sprintf(
    _('The compose file asks for %s. This container is running %s. Restart it to apply the change.'),
    $declared, $running
  );
  return '<span class="staxx-imgmismatch" title="'.htmlspecialchars($title).'">'
       . '<i class="fa fa-exclamation-triangle"></i></span>';
}

/**
 * The human half of a pinned image's fingerprint — the tag written before the
 * @, since that is the name a person actually recognises. Only when there is
 * none (an image pinned with no tag at all) does this fall back to the first
 * twelve characters of the digest itself, which is the shortest slice of it
 * anyone could still tell apart from another.
 */
function staxx_pin_version(string $image): string {
  $at = strpos($image, '@');
  if ($at === false) return $image;
  $repoTag = substr($image, 0, $at);
  $digest  = substr($image, $at + 1);   // algo:hex, e.g. sha256:abcdef012345...

  // A colon only names a tag when it comes after the last slash — one before
  // it is a registry host's port number (registry.example.com:5000/app).
  $slash = strrpos($repoTag, '/');
  $colon = strrpos($repoTag, ':');
  if ($colon !== false && ($slash === false || $colon > $slash)) {
    return substr($repoTag, $colon + 1);
  }

  $hexColon = strpos($digest, ':');
  $hex      = $hexColon !== false ? substr($digest, $hexColon + 1) : $digest;
  return substr($hex, 0, 12);
}

/**
 * The small marker on a stack row naming any service pinned to an exact
 * build — an image whose value carries an @, so a pull can never move it off
 * that build. Read straight off $kids' own 'declared' field, which is the
 * compose file's own image string already parsed once by
 * staxx_stack_children() — no second read of the file here.
 *
 * Deliberately its own class rather than staxx_drift_mark_html()'s or
 * staxx_image_mismatch_html()'s: this is neither a warning nor a change since
 * import, just a fact worth a quiet note, and sharing either class would say
 * otherwise.
 *
 * @param array $kids from staxx_stack_children()
 */
function staxx_pin_mark_html(array $kids): string {
  $pins = [];
  foreach ($kids as $kid) {
    $image = (string)($kid['declared'] ?? '');
    if (strpos($image, '@') === false) continue;
    $pins[] = ['service' => $kid['service'] !== '' ? $kid['service'] : $kid['name'], 'image' => $image];
  }
  if (!$pins) return '';

  $text = count($pins) === 1
    ? sprintf(_('%s is pinned to %s.'), $pins[0]['service'], staxx_pin_version($pins[0]['image']))
    : sprintf(_('%d services are pinned to an exact build.'), count($pins));

  // Named for a screen reader with a .staxx-sr span, not title alone — title
  // is not reliably read out by assistive tech, and .staxx-sr is this file's
  // existing pattern for an icon that has to carry real text (see
  // staxx_grip_html() and stacks.js's own uses of the same class).
  return '<span class="staxx-pinmark" title="'.htmlspecialchars($text).'">'
       . '<i class="fa fa-thumb-tack"></i>'
       . '<span class="staxx-sr">'.htmlspecialchars($text).'</span></span>';
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
  // Gathered once, up front, rather than per row: staxx_autostart_state()
  // reads Unraid's own boot file and the live container labels, and $rows
  // does not carry the stack list itself — only each stack row does.
  $stackList   = [];
  $groupCounts = [];   // folder (or '' for unfiled) => how many stacks sit in it
  $folderStacks = [];  // folder => its member stack records, in display order
  $folderCount = 0;
  foreach ($rows as $r) {
    if ($r['type'] === 'folder') { $folderCount++; continue; }
    $stackList[] = $r['stack'];
    $groupCounts[$r['folder']] = ($groupCounts[$r['folder']] ?? 0) + 1;
    if ($r['folder'] !== '') $folderStacks[$r['folder']][] = $r['stack'];
  }
  $autostart = staxx_autostart_state($stackList);

  // Gathered once for the same reason as $autostart above: this walks at
  // most a handful of Compose Manager projects, not once per stack, so it
  // costs nothing per row even though every row consults it.
  $drift = staxx_import_drift();

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

      // A folder's own boot state is the OR of its stacks': 'on' only when
      // every stack in it starts at boot, 'some' when any does. No
      // interleaving concept at this level — that is a single stack's flat
      // lines getting split apart, not something a folder can suffer.
      $fBoot   = $autostart['available'] ? ($autostart['folders'][$row['id']] ?? ['mode' => 'none', 'wait' => 0]) : ['mode' => 'none', 'wait' => 0];
      $fMode   = $autostart['available'] ? ($fBoot['mode'] === 'all' ? 'on' : ($fBoot['mode'] === 'some' ? 'some' : 'off')) : 'off';
      $fWait   = (int)($fBoot['wait'] ?? 0);
      $fTitle  = $autostart['available']
        ? staxx_boot_title($fBoot['mode'], $fWait, false, _('stacks'))
        : _('The boot list cannot be read while Docker is stopped.');
      // This grip drags the FOLDER itself, to trade places with another
      // folder — how many stacks sit inside it is irrelevant to that; only
      // whether there is a second folder to trade places with is.
      $fGripOff = $folderCount < 2;
      $fGripWhy = _('There is only one folder, so there is nothing to reorder it against.');
      // Sums its stacks' own pills (see PLAN_45 Part H) — a folder has no
      // image of its own to check.
      $fUpdate = staxx_updates_for_folder($row['id']);
      // PLAN_62 Stage 3 — summed the same way, over the same stacks.
      $fWatch = 0;
      foreach (staxx_scan_stacks()['stacks'] as $watchStack) {
        if (strpos($watchStack['rel'], $row['id'].'/') === 0) $fWatch += staxx_watch_count_for_stack($watchStack['rel']);
      }
      $fUpdate = staxx_watch_apply_pill($fUpdate, $fWatch);
?>
      <div class="staxx-group staxx-group--folder" role="presentation" data-folder-group="<?= htmlspecialchars($row['id']) ?>">
        <div class="staxx-row staxx-folder-row" role="row" aria-level="1"
             aria-expanded="<?= $row['collapsed'] ? 'false' : 'true' ?>"
             data-folder-row="<?= htmlspecialchars($row['id']) ?>"
             data-boot="<?= $fMode ?>"
             <?= $fWait > 0 ? 'data-boot-wait="'.$fWait.'"' : '' ?>>
          <!-- Spans the name, services, state and address columns: a folder has
               none of those of its own, only the four figures on the right. -->
          <span class="staxx-cell staxx-cell--span4" role="gridcell">
            <!-- Flex lives on a div, never on the cell itself. -->
            <div class="staxx-folder-head">
            <div class="staxx-namebox">
              <?= staxx_grip_html('folder', $row['name'] !== '' ? $row['name'] : _('this folder'), $fGripOff, $fGripWhy) ?>
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
                        data-boot="<?= $fMode ?>"
                        data-boot-wait="<?= $fWait ?>"
                        data-boot-available="<?= $autostart['available'] ? '1' : '0' ?>"
                        aria-haspopup="menu" aria-expanded="false"
                        title="<?= _('Folder actions') ?>">
                  <i class="fa fa-folder<?= $row['collapsed'] ? '' : '-open' ?>"></i>
                </button>
                <span class="staxx-spinner"><i class="fa fa-refresh fa-spin"></i></span>
                <span class="staxx-dot<?= $row['running'] ? ' staxx-dot--up' : '' ?>"></span>
              </span>

              <span class="staxx-nameinfo">
                <span class="staxx-folder-name"><?= htmlspecialchars($row['name']) ?></span>
                <?= staxx_boot_mark_html($fWait, $fTitle) ?>
                <?= staxx_update_pill_html($fUpdate, false) ?>
                <span class="staxx-sub" data-cell="folder-sub"><?= staxx_folder_sub($row['count'], $row['running']) ?></span>
              </span>
            </div>
            <?
              // One small icon per stack, so a collapsed folder still shows
              // what is inside it. Deliberately outside data-cell="folder-sub"
              // above — the state poll replaces that element wholesale, and
              // rewriting the strip along with it would fight the dimming the
              // browser does to data-fstrip-stack items on its own. No
              // .staxx-dot in here either: the browser takes the first one in
              // a folder row as the folder's own status light.
              $fMembers = $folderStacks[$row['id']] ?? [];
              if ($fMembers):
            ?>
            <div class="staxx-fstrip" aria-hidden="true">
              <? foreach ($fMembers as $fs): ?>
                <span class="staxx-fstrip-item"
                      data-fstrip-stack="<?= htmlspecialchars($fs['name']) ?>"
                      data-running="<?= $fs['running'] ? '1' : '0' ?>"
                      title="<?= htmlspecialchars($fs['leaf']) ?>">
                  <?= $fs['parses']
                        ? staxx_stack_strip_tile($fs, staxx_stack_children($fs))
                        : '<i class="fa fa-exclamation-triangle"></i>' ?>
                </span>
              <? endforeach; ?>
            </div>
            <? endif; ?>
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

      // The stack row's own web-page button: only meaningful when exactly one
      // container has one to offer. Picking between several would be a guess,
      // so more than one collapses to the same disabled shape as none at all,
      // with a title that says why.
      $webKids    = array_filter($kids, fn($k) => $k['webui'] !== '');
      $webKid     = count($webKids) === 1 ? reset($webKids) : null;
      $webUrl     = $webKid['webui'] ?? '';
      $webRunning = ($webKid['state'] ?? '') === 'running';
      // Only read when $webUrl is '' (see staxx_row_actions_html()), so this
      // is the one message worth getting right for the two ways there can be
      // nothing to open: none at all, or too many to guess between.
      $webOffWhy  = count($webKids) > 1
        ? _('More than one container here has its own web page — open one from the container row below.')
        : _('This stack has no web page to open.');

      // Repo/CA for the stack row: same "one clear candidate or none" rule as
      // WebUI above, but keyed on the service count rather than which ones
      // happen to have a link — picking one project page out of several would
      // be a guess, so a multi-service stack shows the pair greyed and points
      // at the rows below, exactly as the web-page chip already does.
      $linkKid    = count($kids) === 1 ? reset($kids) : null;
      $linkProject = $linkKid['project'] ?? '';
      $linkSupport = $linkKid['support'] ?? '';
      $linksOffWhy = count($kids) > 1
        ? _('More than one container here has its own project page — open one from the container row below.')
        : '';

      // What the compose file asks for, for the row's mismatch marker — only
      // meaningful for a single-service stack, which is the only case the row
      // ever prints an image at all.
      $declaredImg = count($kids) === 1 ? (string)($kids[0]['declared'] ?? '') : '';

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

      $sBoot   = $autostart['available'] ? ($autostart['stacks'][$s['name']] ?? ['mode' => 'none', 'wait' => 0, 'interleaved' => false]) : ['mode' => 'none', 'wait' => 0, 'interleaved' => false];
      $sMode   = $autostart['available'] ? ($sBoot['mode'] === 'all' ? 'on' : ($sBoot['mode'] === 'some' ? 'some' : 'off')) : 'off';
      $sWait   = (int)($sBoot['wait'] ?? 0);
      $sInterleaved = $autostart['available'] && !empty($sBoot['interleaved']);
      $sTitle  = $autostart['available']
        ? staxx_boot_title($sBoot['mode'], $sWait, $sInterleaved, _('services'))
        : _('The boot list cannot be read while Docker is stopped.');
      // Nothing to drag when this stack is the only one in its folder (or,
      // for an unfiled stack, the only one at the top level).
      $sGripOff = ($groupCounts[$row['folder']] ?? 0) < 2;
      $sGripWhy = _('This stack is alone in its group, so there is nothing to reorder it against.');

      // Whole-stack pill (see PLAN_45 Part H) — one image, or several rolled
      // up, depending what staxx_updates_for_row() decides for a multi-service
      // stack.
      $sUpdate = staxx_updates_for_row($s['name']);
      // PLAN_62 Stage 3 — the row's own "N to look at", never outranking a
      // real problem — see staxx_watch_apply_pill().
      $sUpdate = staxx_watch_apply_pill($sUpdate, staxx_watch_count_for_stack($s['name']));

      // PLAN_71 — "restart pending": what is running versus what the file
      // now says. 'stack' is added here rather than by staxx_restart_pending()
      // itself, which answers only the comparison and does not otherwise
      // need to know its own row's name.
      $sPending = staxx_restart_pending($s);
      $sPending['stack'] = $s['name'];

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
             data-boot="<?= $sMode ?>"
             <?= $sWait > 0 ? 'data-boot-wait="'.$sWait.'"' : '' ?>
             <?= $sInterleaved ? 'data-interleaved="1"' : '' ?>
             data-image-declared="<?= htmlspecialchars($declaredImg) ?>"
             <?php /* The one service this stack declares, or absent. A stack with a
                      single service renders no container row, so its own row is the
                      only place a per-service action (fixing a withdrawn tag) can be
                      offered from — and that action needs the service NAME, which
                      compose takes, not the label a reader sees. */ ?>
             <?= count($s['services']) === 1 ? 'data-sole-service="'.htmlspecialchars($s['services'][0]).'"' : '' ?>
             <?= $row['hidden'] ? 'hidden' : '' ?>>

          <span class="staxx-cell staxx-cell--name staxx-stack-name" role="gridcell">
            <div class="staxx-namebox">
              <?= staxx_grip_html('stack', $s['leaf'], $sGripOff, $sGripWhy) ?>
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
                        data-handover="<?= ($s['handover'] ?? false) ? '1' : '0' ?>"
                        data-takeover="<?= ($s['takeover'] ?? false) ? '1' : '0' ?>"
                        data-folder="<?= htmlspecialchars($row['folder']) ?>"
                        data-boot="<?= $sMode ?>"
                        data-boot-wait="<?= $sWait ?>"
                        data-interleaved="<?= $sInterleaved ? '1' : '0' ?>"
                        data-boot-available="<?= $autostart['available'] ? '1' : '0' ?>"
                        aria-haspopup="menu" aria-expanded="false"
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
                <?
                  // On every stack row without exception, disabled where it
                  // cannot act rather than left out. The pair sits beside the
                  // tile, so omitting it would pull this row's name ~44px left
                  // of its neighbours' and leave the name column ragged.
                  //
                  // Both reasons it used to be dropped are already answered by
                  // the disabled shape. A stack that breaks out into container
                  // rows cannot say which child's page to open, and $webOffWhy
                  // above carries exactly that sentence. A stack awaiting
                  // review is not running, which is the other thing the off
                  // state already says. Logs stays live in both cases: it
                  // reads, it never starts anything, and on this row it means
                  // the whole stack rather than any one service.
                ?>
                <?= staxx_row_actions_html($s['name'], '', $webUrl, $webRunning, $webOffWhy, $linkProject, $linkSupport, $linksOffWhy) ?>
              </span>

              <span class="staxx-nameinfo">
                <!-- The stack's name IS its directory, full stop — there is no
                     separate display-name override any more. The folder name
                     (if it sits in one) is a different thing, carried by the
                     data attributes above rather than printed here. -->
                <span class="staxx-name-text"><?= htmlspecialchars($s['leaf']) ?></span>
                <?= staxx_boot_mark_html($sWait, $sTitle) ?>
                <? if ($s['handover'] ?? false): ?>
                  <!-- A handover has switched the old container off and set it
                       aside, and this stack is running in its place — see the
                       "handover" section of Stacks.php. Distinct from the
                       review badge below: unlike a locked import, this row
                       genuinely owns its containers and shows their real
                       state, so nothing about it is blanked. It is a real
                       button, not a static label: pressing it opens the same
                       "does it work?" question the stack menu's own two
                       items ask, so checking the webui does not mean leaving
                       the row and reopening the menu to answer it. -->
                  <button type="button" class="staxx-handoverbadge"
                        title="<?= htmlspecialchars(_('This app has been switched over and is running now. Check that it works, then press this to keep it or put the old one back.')) ?>">
                    <?= _('waiting to confirm') ?>
                  </button>
                <? elseif ($s['review']): ?>
                  <!-- Imported and not yet reviewed — see the "review lock"
                       section of Stacks.php. Read-only marker; the menu item
                       that clears it lives in the stack actions button above. -->
                  <span class="staxx-reviewbadge"
                        title="<?= htmlspecialchars(_('Imported and not yet reviewed. Check it over, then choose "Take over and start" from the stack menu, or "Clear the lock only" if nothing else holds its container name.')) ?>">
                    <?= _('needs review') ?>
                  </span>
                <? endif; ?>
                <? if (isset($drift[$s['name']])): ?>
                  <?= staxx_drift_mark_html($drift[$s['name']]) ?>
                <? endif; ?>
                <?= staxx_pin_mark_html($kids) ?>
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
                  <!-- The inner span carries the running image's own text and
                       title; the outer one is the repaint target so a mismatch
                       marker (see javascript/stacks.js applyState()) can sit
                       beside it as a sibling rather than inside the tooltip. -->
                  <span class="staxx-image staxx-image--sub" data-cell="image">
                    <span class="staxx-image-text" title="<?= htmlspecialchars($image) ?>"><?= htmlspecialchars($image) ?></span>
                    <?= staxx_image_mismatch_html($declaredImg, $image) ?>
                  </span>
                <? endif; ?>
              <? endif; ?>
            <? endif; ?>
          </span>

          <!-- data-cell names these for the browser: after a start or a stop it
               replaces just these cells rather than the whole row. -->
          <span class="staxx-cell staxx-cell--state" role="gridcell" data-cell="state"><?= staxx_state_pill($s, $canRun).staxx_update_pill_html($sUpdate).staxx_pending_chip_html($sPending) ?></span>
          <span class="staxx-cell staxx-cell--address staxx-addrcell" role="gridcell" data-cell="address"><?=
            staxx_address_html(staxx_merged_addresses(staxx_stack_containers($s), array_column($kids, 'webui', 'id')))
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
        $cBoot  = $autostart['available'] ? ($autostart['services'][$s['name']][$kid['service']] ?? ['on' => false, 'wait' => 0]) : ['on' => false, 'wait' => 0];
        $cMode  = $autostart['available'] ? ($cBoot['on'] ? 'on' : 'off') : 'off';
        $cWait  = (int)($cBoot['wait'] ?? 0);
        $cTitle = $autostart['available']
          ? staxx_boot_title($cBoot['on'] ? 'all' : 'none', $cWait, false, '')
          : _('The boot list cannot be read while Docker is stopped.');
        // count($kids) is always > 1 here — a single-service stack is not
        // $expandable, so its one container row is never even drawn — but the
        // check stays honest rather than assuming the caller never changes.
        $cGripOff = count($kids) < 2;
        $cGripWhy = _('This is the only service here, so there is nothing to reorder it against.');
        // One image, this row's own — see PLAN_45 Part H.
        $kUpdate  = staxx_updates_for_row($s['name'], $kid['service']);
        // PLAN_71 — this service's own verdict off the stack's already-computed
        // comparison; a service with no verdict at all (never ran, say) gets
        // '', which is exactly what staxx_pending_service_chip_html() shows nothing for.
        $kPending = (string)($sPending['services'][$kid['service']] ?? '');
?>
        <!-- data-state below is read by the container menu (buildContainerMenu()
             in stacks.js) straight off the row, so it has to be right on the
             very first paint, not only once the first state poll lands.
             applyState() maintains this same attribute afterwards
             (kid.dataset.state = c.state, or '' for a container that does not
             exist) — $kid['state'] is already '' for a service that has never
             been started, which is exactly what applyState writes for the
             same case, so the two never disagree.

             data-order-key: the row's own data-service above is $kid['key'],
             which for a replica is "service/container-name" — not a compose
             service name, so the drag order (which must post a real service
             name) needs its own attribute rather than reusing that one. Same
             trap the comment on the menu button below already describes for
             a different attribute pair. -->
        <div class="staxx-row staxx-container-row"
             role="row" aria-level="<?= $containerLevel ?>"
             data-in-stack="<?= htmlspecialchars($s['name']) ?>"
             data-in-folder="<?= htmlspecialchars($row['folder']) ?>"
             data-service="<?= htmlspecialchars($kid['key']) ?>"
             data-order-key="<?= htmlspecialchars($kid['service']) ?>"
             data-container="<?= htmlspecialchars($kid['name']) ?>"
             data-project="<?= htmlspecialchars($project) ?>"
             data-state="<?= htmlspecialchars($kid['state']) ?>"
             data-boot="<?= $cMode ?>"
             <?= $cWait > 0 ? 'data-boot-wait="'.$cWait.'"' : '' ?>
             data-image-declared="<?= htmlspecialchars((string)($kid['declared'] ?? '')) ?>">

          <span class="staxx-cell staxx-cell--name staxx-stack-name" role="gridcell">
            <div class="staxx-namebox staxx-namebox--child">
              <?= staxx_grip_html('service', $kid['service'] !== '' ? $kid['service'] : $kid['name'], $cGripOff, $cGripWhy) ?>
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
                        data-boot="<?= $cMode ?>"
                        data-boot-wait="<?= $cWait ?>"
                        data-boot-available="<?= $autostart['available'] ? '1' : '0' ?>"
                        aria-haspopup="menu" aria-expanded="false"
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
                <?
                  // Unconditional for the same reason as the stack row above:
                  // a container row that dropped the pair would sit its name
                  // out of line with its siblings'. Not running already reads
                  // as the disabled web button.
                ?>
                <?= staxx_row_actions_html($s['name'], $kid['service'], $kid['webui'],
                      $kid['state'] === 'running', _('This container has no web page to open.'),
                      $kid['project'], $kid['support']) ?>
              </span>

              <span class="staxx-nameinfo">
                <span class="staxx-name-text"><?= htmlspecialchars($kid['service']) ?></span>
                <?= staxx_boot_mark_html($cWait, $cTitle) ?>
                <? if ($kid['name'] !== '' && $kid['name'] !== $kid['service']): ?>
                  <span class="staxx-sub"><?= htmlspecialchars($kid['name']) ?></span>
                <? endif; ?>
              </span>
            </div>
          </span>

          <!-- The parent row lists the service names, so this one has room for
               the thing you actually cannot see from up there. data-cell is on
               the wrapper rather than either inner span, because the browser
               has to be able to swap between "not created yet" and a real
               image (and back) — see applyState() in stacks.js, which owns
               this cell's whole innerHTML rather than just its text. -->
          <span class="staxx-cell staxx-cell--services staxx-image" role="gridcell" data-cell="image">
            <? if ($kid['image'] !== ''): ?>
              <span class="staxx-image-text" title="<?= htmlspecialchars($kid['image']) ?>"><?= htmlspecialchars($kid['image']) ?></span>
              <?= staxx_image_mismatch_html((string)($kid['declared'] ?? ''), $kid['image']) ?>
            <? else: ?>
              <span class="staxx-sub"><?= _('not created yet') ?></span>
            <? endif; ?>
          </span>

          <span class="staxx-cell staxx-cell--state" role="gridcell" data-cell="state"><?= staxx_container_pill($kid).staxx_update_pill_html($kUpdate).staxx_pending_service_chip_html($kPending) ?></span>
          <span class="staxx-cell staxx-cell--address staxx-addrcell" role="gridcell" data-cell="address"><?=
            staxx_address_html($kid['id'] !== ''
              ? staxx_address_webui_override(
                  staxx_container_net()[$kid['id']]['addresses'] ?? [],
                  staxx_container_net()[$kid['id']]['published'] ?? false,
                  $kid['webui'], staxx_container_net()[$kid['id']]['exposed'] ?? []
                )
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
 * `exposed` rides along per service so the compose form's port-row suggestion
 * has a second source to fall back on while the editor is open — see
 * staxx_container_net() for where that list comes from. If this snapshot is
 * ever trimmed for speed, that suggestion quietly loses its second source.
 *
 * @return array{stacks:array, folders:array}
 */
function staxx_state_snapshot(): array {
  $canRun = staxx_can_run();
  $states = staxx_stack_states();

  $hostIp = staxx_host_ip();

  $stacks = [];
  foreach ($states as $name => $s) {
    // The compose file's own declared services, needed to resolve each
    // container's web address so this cheap refresh and the full render never
    // disagree about which port the Address column shows.
    //
    // Read ONLY when some container here publishes nothing, because that is
    // the only case staxx_address_webui_override() can change anything in —
    // and reading it for every stack is not cheap at all. staxx_compose_meta()
    // keys its stored answer on a hash of the file's own contents, so asking
    // costs a read of that file off the flash drive: measured at 316ms across
    // 64 stacks, against 90ms for this whole refresh before it. Gating it here
    // takes that to a handful of files. See PLAN_48 on why this particular
    // function has to stay fast.
    // Called once and kept — this and the 'declared' check just below both
    // used to call staxx_stack_containers($s) separately.
    $mine = staxx_stack_containers($s);

    $needWebui = false;
    foreach ($mine as $c) {
      if (!(staxx_container_net()[$c['id']]['published'] ?? false)) { $needWebui = true; break; }
    }
    $declared = ($needWebui && $s['file'] !== '') ? staxx_compose_meta($s['file'])['services'] : [];

    // Keyed by service, which is what the container rows carry, so the browser
    // can find each row without knowing the container names in advance. That
    // matters the first time a stack is started: those rows were rendered from
    // the compose file and have no container name on them yet.
    $containers = [];
    $webuiById  = [];
    $seen       = [];
    foreach ($mine as $c) {
      $service = $c['service'] !== '' ? $c['service'] : $c['name'];
      $key     = isset($seen[$service]) ? $service.'/'.$c['name'] : $service;
      $seen[$service] = true;

      $rec   = staxx_container_net()[$c['id']] ?? [];
      $webui = staxx_webui_url(
        $declared[$service] ?? [], $hostIp,
        (string)($rec['addresses'][0]['ip'] ?? ''), (string)($rec['mode'] ?? ''), (string)($rec['driver'] ?? '')
      );
      $webuiById[$c['id']] = $webui;

      $containers[$key] = [
        'container' => $c['name'],
        'state'     => $c['state'],
        // No update pill here, deliberately — staxx_updates_for_row() needs
        // this stack's compose metadata, and reading that for every stack on
        // every poll is the same 316ms-across-64-stacks cost the comment on
        // this function already measured and ruled out. The browser keeps
        // its own copy of the last `updates` reply and re-applies it after
        // painting this html, so the pill still survives a poll.
        'html'      => staxx_container_pill(['exists' => true] + $c),
        // Ports move when a container is recreated with a changed compose
        // file, so this travels with the state rather than being fixed at
        // render time.
        'address'   => staxx_address_html(staxx_address_webui_override(
          $rec['addresses'] ?? [], $rec['published'] ?? false, $webui, $rec['exposed'] ?? []
        )),
        'exposed'   => $rec['exposed'] ?? [],
        // Docker's own image, already in hand on every row $mine carries — this
        // is what keeps the image column live after a recreate without a second
        // compose-file read.
        'image'     => $c['image'],
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
      // No update pill here either — same reasoning as the container html
      // just above.
      'html'       => staxx_state_pill($s, $canRun),
      'address'    => staxx_address_html(staxx_merged_addresses($mine, $webuiById)),
      'containers' => $containers,
      // The row's own sub-line only ever prints an image for a stack with
      // exactly one container — anything else and the containers array above
      // is what the browser reads instead.
      'image'      => count($mine) === 1 ? $mine[0]['image'] : '',
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
    // Plain text, not a pill — the image cell builds its own small markup
    // around this rather than swallowing server HTML whole, so it can still
    // attach the mismatch marker beside it.
    'notCreatedImage' => _('not created yet'),
  ];
}
?>
