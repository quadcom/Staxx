<?php
/* The bridge to Unraid's boot-start list, checked against the real installed
 * Autostart.php. See PLAN_43.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine:
 *
 *     pscp tests/server/autostart.php root@<box>:/tmp/
 *     plink … "php /tmp/autostart.php"
 *
 * Prints one line per case and exits non-zero on any failure.
 *
 * NEVER touches the real /var/lib/docker/unraid-autostart: STAXX_AUTOSTART_FILE
 * is pointed at a scratch file in /tmp before the include, which is also what
 * makes staxx_autostart_available() true on a box with Docker stopped.
 *
 * It does write folders.json, because that is where the order lives and there
 * is no override for it. The file is copied aside first and put back on the way
 * out, including after a fatal error. */

$scratch = '/tmp/staxx-autostart-test';
putenv('STAXX_AUTOSTART_FILE='.$scratch);

require_once '/usr/local/emhttp/plugins/staxx/include/Autostart.php';

$saved  = STAXX_CFG_DIR.'/folders.json';
$backup = '/tmp/staxx-folders-test-backup.json';
$had    = is_file($saved);
if ($had) copy($saved, $backup);
register_shutdown_function(function () use ($saved, $backup, $had, $scratch) {
  if ($had) { copy($backup, $saved); unlink($backup); } else { @unlink($saved); }
  @unlink($scratch);
  echo "restored $saved\n";
});

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}
function put(array $lines): void {
  file_put_contents(STAXX_AUTOSTART_FILE, $lines ? implode("\n", $lines)."\n" : '');
}
function got(): array {
  $raw = (string)@file_get_contents(STAXX_AUTOSTART_FILE);
  return array_values(array_filter(array_map('trim', explode("\n", $raw)), fn($l) => $l !== ''));
}
function reset_start(): void {
  $d = staxx_folders_load();
  $d['start'] = staxx_start_defaults();
  staxx_folders_save($d);
}
function last_of(array $a) { return $a ? $a[count($a) - 1] : null; }

ok('override in force', STAXX_AUTOSTART_FILE === $scratch && STAXX_AUTOSTART_OVERRIDE);
ok('available despite docker', staxx_autostart_available());

$stacks = staxx_list_stacks();
ok('stacks found', count($stacks) > 0, count($stacks).' stacks');

/* Subjects picked out of whatever is really on the box. */
$multi = null;
foreach ($stacks as $s) {
  if ($multi === null && count($s['services']) >= 2 && $s['parses']) $multi = $s;
}
ok('a multi-service stack exists', $multi !== null, $multi['name'] ?? '');
if ($multi === null) { echo "cannot continue\n"; exit(1); }

/* ------------------------------------------------------------- reading ---- */

put(['alpha', 'beta 10', 'gamma 0', '', 'delta   7   junk']);
$read = staxx_autostart_read();
ok('read: blank lines dropped', count($read['lines']) === 4);
ok('read: bare name is wait 0', $read['lines'][0] === ['name' => 'alpha', 'wait' => 0]);
ok('read: wait parsed', $read['lines'][1] === ['name' => 'beta', 'wait' => 10]);
ok('read: explicit zero is zero', $read['lines'][2]['wait'] === 0);
ok('read: third field ignored', $read['lines'][3] === ['name' => 'delta', 'wait' => 7]);
ok('read: hash is the file', $read['hash'] === md5((string)file_get_contents(STAXX_AUTOSTART_FILE)));
@unlink(STAXX_AUTOSTART_FILE);
ok('read: missing file has empty hash', staxx_autostart_read()['hash'] === '');

/* --------------------------------------------------------------- names ---- */

$names = staxx_autostart_names($multi);
ok('names: one entry per service', count($names) === count($multi['services']));
$flat = array_merge(...array_values($names));
ok('names: every service has a name', count(array_filter($flat)) === count($flat), implode(',', $flat));
$svcs = array_keys($names);

/* ------------------------------------------------------------ toggling ---- */

reset_start();
put(['foreign-one', 'foreign-two 5']);
$e = '';
ok('set: whole stack on', staxx_autostart_set($stacks, $multi['name'], '', true, $e), $e);
$lines = got();
ok('set: foreign lines kept, in order',
   array_slice($lines, 0, 2) === ['foreign-one', 'foreign-two 5'], implode(' | ', $lines));
ok('set: every service listed', count($lines) === 2 + count($flat), implode(' | ', $lines));

$state = staxx_autostart_state($stacks);
ok('state: stack reads all on', $state['stacks'][$multi['name']]['mode'] === 'all');
ok('state: not interleaved', $state['stacks'][$multi['name']]['interleaved'] === false);

ok('set: one service off', staxx_autostart_set($stacks, $multi['name'], $svcs[0], false, $e), $e);
$state = staxx_autostart_state($stacks);
ok('state: stack reads some', $state['stacks'][$multi['name']]['mode'] === 'some');
ok('state: that service is off', $state['services'][$multi['name']][$svcs[0]]['on'] === false);
ok('state: the others are on', $state['services'][$multi['name']][$svcs[1]]['on'] === true);

ok('set: bad service refused', !staxx_autostart_set($stacks, $multi['name'], 'nope-not-real', true, $e));
ok('set: bad stack refused', !staxx_autostart_set($stacks, '../escape', '', true, $e));

ok('set: whole stack off', staxx_autostart_set($stacks, $multi['name'], '', false, $e), $e);
ok('set: back to just the foreign lines', got() === ['foreign-one', 'foreign-two 5'], implode(' | ', got()));

/* ----------------------------------------------------------- the waits ---- */

reset_start();
put([]);
staxx_autostart_set($stacks, $multi['name'], '', true, $e);
$order   = staxx_start_sort($multi['services'], staxx_start_load()['services'][$multi['name']] ?? []);
$lastSvc = last_of($order);

ok('wait: service wait set', staxx_autostart_wait($stacks, 'service', $multi['name'].'/'.$order[0], 5, $e), $e);
ok('wait: stack wait set', staxx_autostart_wait($stacks, 'stack', $multi['name'], 30, $e), $e);
$lines = got();
ok('wait: service wait on its own line',
   in_array(last_of($names[$order[0]]).' 5', $lines, true), implode(' | ', $lines));
ok('wait: stack wait on the last line',
   in_array(last_of($names[$lastSvc]).' 30', $lines, true), implode(' | ', $lines));

/* Both landing on one line — the sum. */
reset_start();
put([]);
staxx_autostart_set($stacks, $multi['name'], '', true, $e);
staxx_autostart_wait($stacks, 'service', $multi['name'].'/'.$lastSvc, 5, $e);
staxx_autostart_wait($stacks, 'stack', $multi['name'], 30, $e);
ok('wait: two levels on one line are summed',
   in_array(last_of($names[$lastSvc]).' 35', got(), true), implode(' | ', got()));

$before = got();
ok('wait: out of range refused', !staxx_autostart_wait($stacks, 'stack', $multi['name'], 601, $e));
ok('wait: negative refused', !staxx_autostart_wait($stacks, 'stack', $multi['name'], -1, $e));
ok('wait: bad scope refused', !staxx_autostart_wait($stacks, 'planet', $multi['name'], 5, $e));
ok('wait: unknown stack refused', !staxx_autostart_wait($stacks, 'stack', 'no-such-stack-here', 5, $e));
ok('wait: a refusal changed nothing', got() === $before);

ok('wait: zero clears it', staxx_autostart_wait($stacks, 'service', $multi['name'].'/'.$lastSvc, 0, $e), $e);
ok('wait: cleared from the store',
   !isset(staxx_start_load()['delay']['service:'.$multi['name'].'/'.$lastSvc]));
ok('wait: line back to the stack wait alone',
   in_array(last_of($names[$lastSvc]).' 30', got(), true), implode(' | ', got()));

/* -------------------------------------------------------- idempotency ----- */

$snapshot   = got();
$hashBefore = staxx_start_load()['seen'];
ok('project: succeeds', staxx_autostart_project($stacks, $e), $e);
ok('project: wrote nothing new', got() === $snapshot, implode(' | ', got()));
ok('project: seen unchanged', staxx_start_load()['seen'] === $hashBefore);
ok('project: seen matches the file',
   staxx_start_load()['seen'] === md5((string)file_get_contents(STAXX_AUTOSTART_FILE)));

/* ------------------------------------------------------------- the run ---- */

reset_start();
put(array_merge(['f1', 'f2', 'f3'], [$flat[0]], ['f4'], array_slice($flat, 1), ['f5']));
ok('state: interleaved is reported',
   staxx_autostart_state($stacks)['stacks'][$multi['name']]['interleaved'] === true);
ok('project: gathers the run', staxx_autostart_project($stacks, $e), $e);
$lines = got();
ok('project: foreign lines before stay put', array_slice($lines, 0, 3) === ['f1', 'f2', 'f3'],
   implode(' | ', $lines));
$run = array_slice($lines, 3, count($flat));
ok('project: the run is ours and contiguous',
   count(array_filter($run, fn($l) => in_array(strtok($l, ' '), $flat, true))) === count($flat),
   implode(' | ', $lines));
ok('project: remaining foreign lines follow, in order',
   array_slice($lines, 3 + count($flat)) === ['f4', 'f5'], implode(' | ', $lines));
ok('project: no longer interleaved',
   staxx_autostart_state($stacks)['stacks'][$multi['name']]['interleaved'] === false);
ok('project: nothing was lost', count($lines) === 5 + count($flat));

/* ------------------------------------------------------------ adopting ---- */

reset_start();
put([]);
staxx_autostart_set($stacks, $multi['name'], '', true, $e);
$onOrder = staxx_start_sort($multi['services'], staxx_start_load()['services'][$multi['name']] ?? []);
/* Reversed by hand, the way a reorder on Unraid's own Docker page would. */
put(array_reverse(got()));
staxx_autostart_sync($stacks, $e);
$adopted = staxx_start_load()['services'][$multi['name']] ?? [];
ok('adopt: service order followed the file', $adopted === array_reverse($onOrder),
   implode(',', $adopted).' vs '.implode(',', array_reverse($onOrder)));
ok('adopt: seen caught up', staxx_start_load()['seen'] === staxx_autostart_read()['hash']);
staxx_autostart_sync($stacks, $e);
ok('adopt: a second sync is a no-op', (staxx_start_load()['services'][$multi['name']] ?? []) === $adopted);

/* A hand-typed wait is taken as the service's own, and the group wait that
   would have landed on the same line is cleared rather than guessed at. */
reset_start();
put([]);
staxx_autostart_set($stacks, $multi['name'], '', true, $e);
staxx_autostart_wait($stacks, 'stack', $multi['name'], 30, $e);
$lines = got();
$lines[count($lines) - 1] = strtok($lines[count($lines) - 1], ' ').' 12';
put($lines);
staxx_autostart_sync($stacks, $e);
$delay  = staxx_start_load()['delay'];
$lastOn = last_of(staxx_start_sort($multi['services'], staxx_start_load()['services'][$multi['name']] ?? []));
ok('adopt: hand-typed wait became the service wait',
   ($delay['service:'.$multi['name'].'/'.$lastOn] ?? 0) === 12, json_encode($delay));
ok('adopt: the stack wait on that line was cleared',
   !isset($delay['stack:'.$multi['name']]), json_encode($delay));

/* -------------------------------------- ordering across stacks and folders */

/* Two stacks in one folder, so the stored order actually has something to say.
   The stacks order is keyed by LEAF name — the same key staxx_folder_layout()
   sorts by and a drag posts — which is the whole point of these cases. */
$folder = $multi['folder'];
$peers  = array_values(array_filter($stacks, fn($s) => $s['folder'] === $folder && $s['parses']));
ok('two stacks share a folder', count($peers) >= 2, $folder.': '.count($peers));

if (count($peers) >= 2) {
  $a = $peers[0];
  $b = $peers[1];
  $aNames = array_merge(...array_values(staxx_autostart_names($a)));
  $bNames = array_merge(...array_values(staxx_autostart_names($b)));

  reset_start();
  put([]);
  staxx_autostart_set($stacks, $a['name'], '', true, $e);
  staxx_autostart_set($stacks, $b['name'], '', true, $e);

  ok('order: drag B ahead of A accepted',
     staxx_start_order_set('stacks', $folder, [$b['leaf'], $a['leaf']], $e), $e);
  ok('order: projection follows it', staxx_autostart_project($stacks, $e), $e);
  $lines = array_map(fn($l) => strtok($l, ' '), got());
  $posB  = array_search($bNames[0], $lines, true);
  $posA  = array_search($aNames[0], $lines, true);
  ok('order: B now starts before A', $posB !== false && $posA !== false && $posB < $posA,
     implode(' | ', $lines));

  ok('order: dragged back the other way',
     staxx_start_order_set('stacks', $folder, [$a['leaf'], $b['leaf']], $e), $e);
  staxx_autostart_project($stacks, $e);
  $lines = array_map(fn($l) => strtok($l, ' '), got());
  ok('order: A starts before B again',
     array_search($aNames[0], $lines, true) < array_search($bNames[0], $lines, true),
     implode(' | ', $lines));

  ok('order: a rel path is not accepted as a stack order entry',
     !staxx_start_order_set('stacks', $folder, [$a['name']], $e));

  /* A folder wait lands on the last line of the folder's last starting stack,
     on top of whatever that stack already carries. */
  ok('wait: folder wait set', staxx_autostart_wait($stacks, 'folder', $folder, 20, $e), $e);
  ok('wait: stack wait on the last stack set', staxx_autostart_wait($stacks, 'stack', $b['name'], 3, $e), $e);
  $lines = got();
  ok('wait: folder and stack waits share the last line',
     last_of($lines) === last_of($bNames).' 23', implode(' | ', $lines));
  ok('wait: nothing else carries the folder wait',
     count(array_filter($lines, fn($l) => str_contains($l, ' 20'))) === 0, implode(' | ', $lines));

  /* And it comes apart again. */
  $lines[count($lines) - 1] = last_of($bNames).' 9';
  put($lines);
  staxx_autostart_sync($stacks, $e);
  $delay = staxx_start_load()['delay'];
  ok('adopt: the shared line became a service wait',
     ($delay['service:'.$b['name'].'/'.last_of(array_keys(staxx_autostart_names($b)))] ?? 0) === 9
     || in_array(9, $delay, true), json_encode($delay));
  ok('adopt: both group waits on that line were cleared',
     !isset($delay['folder:'.$folder]) && !isset($delay['stack:'.$b['name']]), json_encode($delay));
}

/* ------------------------------- what does NOT start at boot keeps its place */

/* The first sync of a fresh install reads a file that mentions only some of
   the stacks. Everything it does not mention has to stay exactly where it
   already sat — otherwise opening the page once would shove every stack that
   does not start at boot to the bottom of its folder. */
if (count($peers) >= 2) {
  $a = $peers[0];
  $b = $peers[1];
  $folderLeaves = array_values(array_map(
    fn($s) => $s['leaf'],
    array_filter($stacks, fn($s) => $s['folder'] === $folder)
  ));

  reset_start();
  put(array_merge(...array_values(staxx_autostart_names($b))));   // only B starts at boot
  staxx_autostart_sync($stacks, $e);
  $stored = staxx_start_load()['stacks'][$folder] ?? [];

  ok('adopt: the whole folder is kept, not just what starts at boot',
     count($stored) === count($folderLeaves), count($stored).' of '.count($folderLeaves));
  ok('adopt: nothing was invented or lost',
     !array_diff($stored, $folderLeaves) && !array_diff($folderLeaves, $stored));
  /* B is the only one mentioned, so it lands in the slot it already held and
     nothing else moves — the stored order equals the natural order. */
  ok('adopt: the unmentioned stacks did not move', $stored === $folderLeaves,
     implode(',', array_slice($stored, 0, 6)).' vs '.implode(',', array_slice($folderLeaves, 0, 6)));

  /* And with the file naming them the other way round, only those two swap. */
  reset_start();
  put(array_merge(
    array_merge(...array_values(staxx_autostart_names($b))),
    array_merge(...array_values(staxx_autostart_names($a)))
  ));
  staxx_autostart_sync($stacks, $e);
  $stored = staxx_start_load()['stacks'][$folder] ?? [];
  $want = $folderLeaves;
  $ia = array_search($a['leaf'], $want, true);
  $ib = array_search($b['leaf'], $want, true);
  $want[$ia] = $b['leaf'];
  $want[$ib] = $a['leaf'];
  ok('adopt: only the two the file named swapped', $stored === $want,
     implode(',', array_slice($stored, 0, 6)).' vs '.implode(',', array_slice($want, 0, 6)));
}

/* -------------------------------------------------------------- sorting --- */

ok('sort: listed first, in order', staxx_start_sort(['a', 'b', 'c'], ['c', 'a']) === ['c', 'a', 'b']);
ok('sort: unknown names in the order are ignored', staxx_start_sort(['a', 'b'], ['z', 'b']) === ['b', 'a']);
ok('sort: an empty order changes nothing', staxx_start_sort(['a', 'b'], []) === ['a', 'b']);

/* ---------------------------------------------------- the real file is safe */

ok('the real boot list was never opened',
   STAXX_AUTOSTART_FILE !== '/var/lib/docker/unraid-autostart');

printf("\n%s — %d failure%s\n", $fails ? 'FAILED' : 'passed', $fails, $fails === 1 ? '' : 's');
exit($fails ? 1 : 0);
