<?PHP
/* StaXX — the bridge to Unraid's own boot-start list.
 * Copyright 2026, StaXX contributors.
 *
 * Unraid decides what starts at boot from one plain file: container names,
 * one per line, in start order, each optionally followed by a wait in
 * seconds. /etc/rc.d/rc.docker walks it top to bottom and runs `docker start
 * <name>` for whichever ones already exist — it never runs `docker compose
 * up` and never consults `depends_on`. StaXX does not replace that file; it
 * projects its own folder/stack/service tree onto it, and reads it back the
 * other way when something changes it directly (Unraid's own Docker page).
 *
 * Membership (does a service start at boot at all) lives ONLY in that file —
 * there is no second copy of it to disagree with. Order and wait live in
 * folders.json's `start` block (staxx_start_load()/staxx_start_store(), see
 * Folders.php), because Unraid's list can only order the things that start at
 * boot, and folders and empty stacks still need a place to sit in it.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Folders.php';

if (defined('STAXX_AUTOSTART_FILE')) return;

// A test box has no /var/lib/docker to mount, so tests/server/ points this at
// a plain file under /tmp instead. STAXX_AUTOSTART_OVERRIDE records whether
// that happened, because an overridden path needs none of the
// staxx_docker_running() gating the real one does — see
// staxx_autostart_available().
$staxx_autostart_env = getenv('STAXX_AUTOSTART_FILE');
define('STAXX_AUTOSTART_OVERRIDE', $staxx_autostart_env !== false && $staxx_autostart_env !== '');
define('STAXX_AUTOSTART_FILE', STAXX_AUTOSTART_OVERRIDE
  ? $staxx_autostart_env
  : '/var/lib/docker/unraid-autostart');

/**
 * Is the boot-start file safe to read and write right now?
 *
 * /var/lib/docker is only mounted while Docker is running; with Docker down,
 * anything written there lands in a RAM overlay that Docker starting up just
 * mounts over, so it is not really "written" at all. A test override points
 * at an ordinary file with no such mount involved, so it is always available.
 */
function staxx_autostart_available(): bool {
  return STAXX_AUTOSTART_OVERRIDE || staxx_docker_running();
}

/**
 * The boot-start file, parsed.
 *
 * @return array{lines: array<int, array{name:string, wait:int}>, hash:string}
 *         hash is md5() of the raw file content; '' when the file is absent
 *         or unreadable — never a real file's hash, which cannot be empty.
 */
function staxx_autostart_read(): array {
  $raw = @file_get_contents(STAXX_AUTOSTART_FILE);
  if ($raw === false) return ['lines' => [], 'hash' => ''];

  $lines = [];
  foreach (explode("\n", $raw) as $line) {
    $line = trim($line);
    if ($line === '') continue;
    // Anything past the first two fields is ignored, not preserved — Unraid's
    // own writer never adds a third, so there is nothing here worth keeping.
    $parts = preg_split('/\s+/', $line, 3);
    $lines[] = ['name' => $parts[0], 'wait' => isset($parts[1]) ? max(0, (int)$parts[1]) : 0];
  }
  return ['lines' => $lines, 'hash' => md5($raw)];
}

/** Render parsed lines back to the file's own format — bare name, or "name seconds". */
function staxx_autostart_format(array $lines): string {
  $body = '';
  foreach ($lines as $l) {
    $body .= $l['name'].($l['wait'] > 0 ? ' '.$l['wait'] : '')."\n";
  }
  return $body;
}

/** Write the file atomically — a temp file plus rename, so a reader never sees half a list. */
function staxx_autostart_write(array $lines): bool {
  $dir = dirname(STAXX_AUTOSTART_FILE);
  $tmp = $dir.'/.'.basename(STAXX_AUTOSTART_FILE).'.'.getmypid().'.tmp';
  if (@file_put_contents($tmp, staxx_autostart_format($lines)) === false) return false;
  if (!@rename($tmp, STAXX_AUTOSTART_FILE)) { @unlink($tmp); return false; }
  return true;
}

/**
 * The container name(s) behind every service of one stack — the join between
 * StaXX's tree and Unraid's flat list of names.
 *
 * Live names win, straight from staxx_stack_containers(), because that is
 * what makes a service scaled past one container come out right. A service
 * with no running or stopped container yet falls back to the compose file's
 * own `container_name:`, and failing that to the name compose would generate
 * itself: <project>-<service>-1, where the project is compose's own
 * normalisation of the stack's LEAF — never the full path; see
 * staxx_stack_containers()'s comment for why.
 *
 * @param  array $s one entry from staxx_list_stacks()
 * @return array<string, string[]> service => container names
 */
function staxx_autostart_names(array $s): array {
  $names = [];
  foreach ($s['services'] as $svc) $names[$svc] = [];

  foreach (staxx_stack_containers($s) as $c) {
    $svc = $c['service'] ?? '';
    if ($svc === '') continue;
    if (!isset($names[$svc])) $names[$svc] = [];
    $names[$svc][] = $c['name'];
  }

  $meta = ($s['file'] ?? '') !== '' ? staxx_compose_meta($s['file']) : ['services' => []];
  $leaf = $s['leaf'] ?? staxx_path_leaf($s['name']);

  foreach ($names as $svc => $list) {
    if ($list) continue; // a live container already answered this
    $cn = $meta['services'][$svc]['container_name'] ?? '';
    $names[$svc] = [$cn !== '' ? $cn : staxx_project_name($leaf).'-'.$svc.'-1'];
  }

  return $names;
}

/**
 * Every container name StaXX knows about, mapped back to the stack/service it
 * belongs to, plus the per-stack service=>names map itself — the lookup every
 * function below needs, built once so the same staxx_autostart_names() calls
 * are not repeated across the whole stack list.
 *
 * @return array{owner: array<string, array{0:string,1:string}>,
 *               names: array<string, array<string, string[]>>,
 *               byName: array<string, array>}
 */
function staxx_autostart_index(array $stacks): array {
  $owner  = [];
  $names  = [];
  $byName = [];

  foreach ($stacks as $s) {
    $byName[$s['name']] = $s;
    $names[$s['name']]  = staxx_autostart_names($s);
    foreach ($names[$s['name']] as $svc => $list) {
      foreach ($list as $n) $owner[$n] = [$s['name'], $svc];
    }
  }

  return ['owner' => $owner, 'names' => $names, 'byName' => $byName];
}

/**
 * Which services are switched on right now, and where their lines sit —
 * read straight off the file, since presence there IS the switch. Grouped by
 * stack, each service's value is its list of line indexes (into $lines), in
 * file order — several when a service has scaled past one container.
 *
 * @return array<string, array<string, int[]>>
 */
function staxx_autostart_on_map(array $lines, array $owner): array {
  $on = [];
  foreach ($lines as $i => $l) {
    if (!isset($owner[$l['name']])) continue;
    [$rel, $svc] = $owner[$l['name']];
    $on[$rel][$svc][] = $i;
  }
  return $on;
}

/**
 * Merge a freshly-observed order into a stored one: items the observation
 * mentions are reordered to match it, slotted into the positions the stored
 * list already held for them; anything the stored list has that the
 * observation does not mention is left exactly where it was; anything the
 * observation mentions that the stored list never had is appended at the end.
 *
 * The read-back half of ordering. staxx_start_sort() (Folders.php) is the
 * write-out half and deliberately not reused here — that one always keeps the
 * full stored list and only tie-breaks the unlisted remainder, where this one
 * has to let a genuinely new relative order win for what it just saw.
 */
function staxx_autostart_merge_order(array $stored, array $seen): array {
  if (!$seen) return $stored;

  $seenSet   = array_flip($seen);
  $positions = [];
  foreach ($stored as $i => $name) if (isset($seenSet[$name])) $positions[] = $i;

  $result = $stored;
  $extra  = [];
  foreach ($seen as $i => $name) {
    if ($i < count($positions)) $result[$positions[$i]] = $name;
    else $extra[] = $name;
  }
  return array_values(array_merge($result, $extra));
}

/**
 * The state the UI draws: per folder/stack/service, whether it starts at boot
 * and its wait. Membership and 'interleaved' come from the file — the only
 * place either is recorded; every wait comes from the store, since the file
 * only ever holds the summed total that lands on one line.
 *
 * @return array{available:bool,
 *               folders:array<string, array{mode:string, wait:int}>,
 *               stacks:array<string, array{mode:string, wait:int, interleaved:bool}>,
 *               services:array<string, array<string, array{on:bool, wait:int}>>}
 */
function staxx_autostart_state(array $stacks): array {
  $result = ['available' => staxx_autostart_available(), 'folders' => [], 'stacks' => [], 'services' => []];
  if (!$result['available']) return $result;

  $start = staxx_start_load();
  $delay = (array)($start['delay'] ?? []);
  $idx   = staxx_autostart_index($stacks);
  $onMap = staxx_autostart_on_map(staxx_autostart_read()['lines'], $idx['owner']);

  foreach ($stacks as $s) {
    $rel = $s['name'];
    $on  = [];
    foreach ($s['services'] as $svc) {
      $isOn = isset($onMap[$rel][$svc]);
      $on[$svc] = $isOn;
      $result['services'][$rel][$svc] = [
        'on'   => $isOn,
        'wait' => (int)($delay['service:'.$rel.'/'.$svc] ?? 0),
      ];
    }

    $onCount = count(array_filter($on));
    $mode = $onCount === 0 ? 'none' : ($onCount === count($on) ? 'all' : 'some');

    // A flat file can interleave this stack's lines with another's, which the
    // folder/stack/service tree cannot express — flagged rather than hidden;
    // see the module comment.
    $allIdx = [];
    foreach ($onMap[$rel] ?? [] as $list) foreach ($list as $i) $allIdx[] = $i;
    sort($allIdx);
    $interleaved = $allIdx && (end($allIdx) - $allIdx[0] + 1) !== count($allIdx);

    $result['stacks'][$rel] = [
      'mode'        => $mode,
      'wait'        => (int)($delay['stack:'.$rel] ?? 0),
      'interleaved' => $interleaved,
    ];
  }

  foreach (staxx_folder_names() as $folder) {
    $members = array_values(array_filter($stacks, fn($s) => ($s['folder'] ?? '') === $folder));
    $modes   = array_map(fn($s) => $result['stacks'][$s['name']]['mode'] ?? 'none', $members);

    if (!$modes) {
      $mode = 'none';
    } else {
      $allOn = count(array_filter($modes, fn($m) => $m === 'all')) === count($modes);
      $anyOn = in_array('all', $modes, true) || in_array('some', $modes, true);
      $mode  = $allOn ? 'all' : ($anyOn ? 'some' : 'none');
    }

    $result['folders'][$folder] = ['mode' => $mode, 'wait' => (int)($delay['folder:'.$folder] ?? 0)];
  }

  return $result;
}

/**
 * The lines one folder (or, for $folder === '', the ungrouped stacks at the
 * top level) contributes to the projected file: start-sorted, on-services
 * only, every wait summed onto the one line it lands on.
 *
 * @param array<int, array> $stacksHere every stack in this folder, natural order
 * @param array $onMap   staxx_autostart_on_map()'s return
 * @return array<int, array{name:string, wait:int}>
 */
function staxx_autostart_group_lines(
  string $folder, array $stacksHere, array $start, array $idx, array $onMap, array $delay
): array {
  $lines = [];

  // The stored stacks order holds LEAF names, because that is what
  // staxx_folder_layout() sorts by and what a drag posts — sorting rel paths
  // against it would match nothing and silently ignore the order.
  $byLeaf = [];
  foreach ($stacksHere as $s) $byLeaf[$s['leaf'] ?? staxx_path_leaf($s['name'])] = $s['name'];
  $stackRels = array_map(
    fn($leaf) => $byLeaf[$leaf],
    staxx_start_sort(array_keys($byLeaf), (array)($start['stacks'][$folder] ?? []))
  );

  $onStackRels = array_values(array_filter($stackRels, fn($rel) => !empty($onMap[$rel])));
  $lastStackRel = $onStackRels ? end($onStackRels) : null;

  foreach ($onStackRels as $rel) {
    $s        = $idx['byName'][$rel];
    $svcOrder = staxx_start_sort($s['services'], (array)($start['services'][$rel] ?? []));
    $onSvcs   = array_values(array_filter($svcOrder, fn($svc) => !empty($onMap[$rel][$svc])));
    $lastSvc  = $onSvcs ? end($onSvcs) : null;

    foreach ($onSvcs as $svc) {
      $names       = $idx['names'][$rel][$svc] ?? [];
      $lastNameIdx = count($names) - 1;

      foreach ($names as $ni => $name) {
        $wait = 0;
        if ($ni === $lastNameIdx) {
          $wait += (int)($delay['service:'.$rel.'/'.$svc] ?? 0);
          if ($svc === $lastSvc) {
            $wait += (int)($delay['stack:'.$rel] ?? 0);
            if ($folder !== '' && $rel === $lastStackRel) {
              $wait += (int)($delay['folder:'.$folder] ?? 0);
            }
          }
        }
        $lines[] = ['name' => $name, 'wait' => $wait];
      }
    }
  }

  return $lines;
}

/**
 * Rebuild Unraid's boot-start file from the tree, whole, and write it only if
 * that changes anything — projection must be idempotent, both because it
 * runs on every render and because staxx_autostart_sync() relies on a
 * matching hash to know nothing needs doing.
 *
 * Docker down: the file cannot be trusted, so nothing is read or written.
 * `pending` is set instead, and the next render — staxx_autostart_sync(),
 * once Docker is back up — projects for real. That is success, not failure:
 * the caller gets true back and no error.
 */
function staxx_autostart_project(array $stacks, string &$error): bool {
  $error = '';

  if (!staxx_autostart_available()) {
    $start = staxx_start_load();
    $start['pending'] = true;
    $ignored = '';
    staxx_start_store($start, $ignored);
    return true;
  }

  $start = staxx_start_load();
  $delay = (array)($start['delay'] ?? []);
  $idx   = staxx_autostart_index($stacks);

  $read     = staxx_autostart_read();
  $curLines = $read['lines'];
  $onMap    = staxx_autostart_on_map($curLines, $idx['owner']);

  // Split the file around the first line StaXX owns: everything before that
  // point, and every foreign line from that point on, keeps its place and its
  // wait untouched. Everything StaXX owns is pulled into one run there.
  $foreignBefore = [];
  $foreignAfter  = [];
  $reachedOwned  = false;
  foreach ($curLines as $l) {
    if (isset($idx['owner'][$l['name']])) { $reachedOwned = true; continue; }
    if ($reachedOwned) $foreignAfter[] = $l; else $foreignBefore[] = $l;
  }

  $byFolder = [];
  foreach ($stacks as $s) $byFolder[$s['folder'] ?? ''][] = $s;

  $folderOrder = staxx_start_sort(staxx_folder_names(), (array)($start['folders'] ?? []));

  $ourRun = [];
  foreach ($folderOrder as $folder) {
    if (empty($byFolder[$folder])) continue;
    $ourRun = array_merge(
      $ourRun,
      staxx_autostart_group_lines($folder, $byFolder[$folder], $start, $idx, $onMap, $delay)
    );
  }
  // Ungrouped stacks always come after every real folder, same as
  // staxx_folder_layout() shows them.
  if (!empty($byFolder[''])) {
    $ourRun = array_merge(
      $ourRun,
      staxx_autostart_group_lines('', $byFolder[''], $start, $idx, $onMap, $delay)
    );
  }

  $final = array_merge($foreignBefore, $ourRun, $foreignAfter);

  if ($final !== $curLines) {
    if (!staxx_autostart_write($final)) {
      $error = 'Could not write '.STAXX_AUTOSTART_FILE.'.';
      return false;
    }
    $newHash = md5(staxx_autostart_format($final));
  } else {
    // Nothing to write, so nothing on disk actually changed — keep the hash
    // that matches what is really there, cosmetic quirks (a stray "name 0")
    // and all, rather than one staxx_autostart_format() would have produced.
    $newHash = $read['hash'];
  }

  $start['pending'] = false;
  $start['seen']    = $newHash;

  $storeError = '';
  if (!staxx_start_store($start, $storeError)) {
    $error = $storeError !== '' ? $storeError : 'Could not save the autostart order.';
    return false;
  }
  return true;
}

/**
 * Switch one service — or, with $service === '', every service of the
 * stack — on or off. Membership lives only in Unraid's file, so this reads
 * it, adds or removes that stack's names, writes the raw result back, and
 * lets staxx_autostart_project() fold it into the tree's order and recompute
 * every wait sum from the store.
 */
function staxx_autostart_set(array $stacks, string $stack, string $service, bool $on, string &$error): bool {
  $error = '';

  if (!staxx_autostart_available()) {
    // Membership can only be changed by writing the real file, and with
    // Docker down that write would land in a RAM overlay Docker starting up
    // simply mounts over — it would look like it worked and then vanish.
    // Unlike order and wait, nothing in the store remembers a toggle to
    // replay later, so this is refused outright rather than pretended.
    $error = 'Docker is not running, so what starts at boot cannot be changed right now.';
    return false;
  }

  if (!staxx_valid_path($stack)) { $error = 'Invalid stack.'; return false; }

  $found = null;
  foreach ($stacks as $s) if ($s['name'] === $stack) { $found = $s; break; }
  if ($found === null) { $error = 'No such stack.'; return false; }

  if ($service !== '' && !in_array($service, $found['services'], true)) {
    $error = 'No such service in this stack.';
    return false;
  }
  $targetServices = $service === '' ? $found['services'] : [$service];

  $namesByService = staxx_autostart_names($found);
  $targets = [];
  foreach ($targetServices as $svc) {
    foreach ($namesByService[$svc] ?? [] as $n) $targets[] = $n;
  }

  $lines = staxx_autostart_read()['lines'];

  if ($on) {
    $existing = array_column($lines, 'name');
    foreach ($targets as $n) {
      if (!in_array($n, $existing, true)) $lines[] = ['name' => $n, 'wait' => 0];
    }
  } else {
    $lines = array_values(array_filter($lines, fn($l) => !in_array($l['name'], $targets, true)));
  }

  if (!staxx_autostart_write($lines)) {
    $error = 'Could not write '.STAXX_AUTOSTART_FILE.'.';
    return false;
  }

  // That write is raw and unordered — projection is what folds it into the
  // tree's order and turns the store's per-level waits into real sums.
  return staxx_autostart_project($stacks, $error);
}

/**
 * Set (or, at 0, clear) the wait attached to a folder, a stack or a service.
 * $key is a folder name, a stack's rel path, or "<rel>/<service>".
 */
function staxx_autostart_wait(
  array $stacks, string $scope, string $key, int $seconds, string &$error
): bool {
  $error = '';

  if (!in_array($scope, ['folder', 'stack', 'service'], true)) {
    $error = 'That is not something a wait can be attached to.';
    return false;
  }
  if ($seconds < 0 || $seconds > 600) {
    $error = 'The wait must be between 0 and 600 seconds.';
    return false;
  }

  if ($scope === 'folder') {
    if (!in_array($key, staxx_folder_names(), true)) { $error = 'No such folder.'; return false; }
    $storeKey = 'folder:'.$key;
  } elseif ($scope === 'stack') {
    $exists = false;
    foreach ($stacks as $s) if ($s['name'] === $key) { $exists = true; break; }
    if (!$exists) { $error = 'No such stack.'; return false; }
    $storeKey = 'stack:'.$key;
  } else {
    // A stack's own rel path may itself hold one slash ("Media/jellyfin"), so
    // the service name is split off the LAST slash, not the first.
    $slash = strrpos($key, '/');
    if ($slash === false) { $error = 'No such service.'; return false; }
    $rel = substr($key, 0, $slash);
    $svc = substr($key, $slash + 1);

    $found = null;
    foreach ($stacks as $s) if ($s['name'] === $rel) { $found = $s; break; }
    if ($found === null || !in_array($svc, $found['services'], true)) {
      $error = 'No such service.';
      return false;
    }
    $storeKey = 'service:'.$key;
  }

  $start = staxx_start_load();
  $delay = (array)($start['delay'] ?? []);
  if ($seconds === 0) unset($delay[$storeKey]); else $delay[$storeKey] = $seconds;
  $start['delay'] = $delay;

  if (!staxx_start_store($start, $error)) return false;

  return staxx_autostart_project($stacks, $error);
}

/**
 * Reconcile with whatever is on disk — called on every render, so it must be
 * cheap and must never throw.
 *
 *   not available          → nothing to do yet
 *   pending                → we owe the file a write from while Docker was
 *                             down; project it now and clear the flag
 *   hash differs from seen → somebody else changed the file (Unraid's own
 *                             Docker page, almost certainly) — adopt its
 *                             order and its waits rather than overwriting them
 *   hash matches           → nothing changed
 *
 * Adopting and projecting never happen in the same pass: projecting always
 * updates `seen` to match what it just wrote, so the very next render sees a
 * matching hash and stops — this cannot loop.
 */
function staxx_autostart_sync(array $stacks, ?string &$error = null): void {
  $error = '';
  if (!staxx_autostart_available()) return;

  $start = staxx_start_load();

  if (!empty($start['pending'])) {
    staxx_autostart_project($stacks, $error);
    return;
  }

  $read = staxx_autostart_read();
  if ($read['hash'] === (string)($start['seen'] ?? '')) return;

  staxx_autostart_adopt($stacks, $start, $read);
}

/**
 * Read the tree's order and waits back OUT of a file that changed under us.
 * Order: reorder within each stored list to match the file's own relative
 * order for whatever it mentions, leaving anything it does not mention where
 * it already was. Waits: where a line's actual wait does not match what this
 * code would have summed onto it from the store, the whole number is taken as
 * that service's own wait, and the group waits that would have landed on the
 * same line are cleared — there is no way to tell how a hand-typed number was
 * meant to be split, so it is not guessed at.
 */
function staxx_autostart_adopt(array $stacks, array $start, array $read): void {
  $delay    = (array)($start['delay'] ?? []);
  $idx      = staxx_autostart_index($stacks);
  $curLines = $read['lines'];

  $folderSeen  = [];  // folder => true, insertion order = first-seen order
  $stackSeen   = [];  // folder => [leaf => rel] — LEAF keys, matching the store
  $serviceSeen = [];  // rel => [svc => true]
  $lastLineIdx = [];  // "rel/svc" => index of its LAST line in $curLines

  foreach ($curLines as $i => $l) {
    if (!isset($idx['owner'][$l['name']])) continue;
    [$rel, $svc] = $idx['owner'][$l['name']];
    $folder = $idx['byName'][$rel]['folder'] ?? '';
    $leaf   = $idx['byName'][$rel]['leaf'] ?? staxx_path_leaf($rel);

    // The ungrouped level is a group like any other here: '' is a real key in
    // the stored stacks map, and skipping it would leave the top of the list
    // as the one place a change made on Unraid's page never came back.
    if ($folder !== '') $folderSeen[$folder] = true;
    $stackSeen[$folder][$leaf] = $rel;
    $serviceSeen[$rel][$svc] = true;
    $lastLineIdx[$rel.'/'.$svc] = $i; // last write wins — walking in order
  }

  $start['folders'] = staxx_autostart_merge_order(
    (array)($start['folders'] ?? []), array_keys($folderSeen)
  );
  foreach ($stackSeen as $folder => $leaves) {
    $start['stacks'][$folder] = staxx_autostart_merge_order(
      (array)($start['stacks'][$folder] ?? []), array_keys($leaves)
    );
  }
  foreach ($serviceSeen as $rel => $svcs) {
    $start['services'][$rel] = staxx_autostart_merge_order(
      (array)($start['services'][$rel] ?? []), array_keys($svcs)
    );
  }

  foreach ($serviceSeen as $rel => $svcs) {
    $svcNames = array_keys($svcs);
    $lastSvc  = end($svcNames);
    $folder   = $idx['byName'][$rel]['folder'] ?? '';
    $isLastStackInFolder = false;
    if ($folder !== '') {
      $seenRels = array_values($stackSeen[$folder] ?? []);
      $isLastStackInFolder = $seenRels && end($seenRels) === $rel;
    }

    foreach ($svcNames as $svc) {
      $lineIdx = $lastLineIdx[$rel.'/'.$svc] ?? null;
      if ($lineIdx === null) continue;
      $actual = (int)$curLines[$lineIdx]['wait'];

      $isLastInStack = ($svc === $lastSvc);
      $expected = (int)($delay['service:'.$rel.'/'.$svc] ?? 0);
      if ($isLastInStack) {
        $expected += (int)($delay['stack:'.$rel] ?? 0);
        if ($isLastStackInFolder) $expected += (int)($delay['folder:'.$folder] ?? 0);
      }

      if ($actual === $expected) continue;

      if ($actual > 0) $delay['service:'.$rel.'/'.$svc] = $actual;
      else unset($delay['service:'.$rel.'/'.$svc]);

      if ($isLastInStack) {
        unset($delay['stack:'.$rel]);
        if ($isLastStackInFolder) unset($delay['folder:'.$folder]);
      }
    }
  }

  $start['delay'] = $delay;
  $start['seen']  = $read['hash'];

  $ignored = '';
  staxx_start_store($start, $ignored);
}
?>
