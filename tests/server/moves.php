<?php
/* PLAN_61 — noticing when a catalogue app's template has moved registries.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine:
 *
 *     pscp tests/server/moves.php root@<box>:/tmp/
 *     plink … "php /tmp/moves.php"
 *
 * Prints one line per case and exits non-zero on any failure.
 *
 * Three things this touches that are not this test's own scratch space, all
 * backed up and restored on every exit path — including a fatal error, via
 * register_shutdown_function() — before anything else runs:
 *
 *   - STAXX_UPDATE_STATE, pointed at a /tmp scratch file, same trick
 *     tests/server/updates.php uses. Never the real updates.json.
 *   - /tmp/staxx/ca/index.json, the live Community Applications cache the
 *     Apps dialog also reads. This file has no env-var override, so the test
 *     writes a small fixture over it directly and restores whatever was
 *     there (or removes it, if there was nothing) before exiting.
 *   - STACK_ROOT in the real /boot/config/plugins/staxx/staxx.cfg, the same
 *     production file — it has no env-var override either. Pointed at a
 *     throwaway /tmp stack for the few checks that need a real compose file
 *     (staxx_updates_moved_for_stack() reads a stack's services off disk),
 *     then put back exactly as it was.
 *
 * NEVER pulls, starts, or stops a container, and never touches the real
 * stacks under the production STACK_ROOT.
 *
 * What this does NOT test: the network-proving step itself — asking the new
 * registry for its tag list and checking the tag in use is actually there.
 * That is staxx_registry_tags() reached from inside staxx_update_check(),
 * and there is no stub of a registry in this repo to make it deterministic.
 * The join (staxx_links_move_candidate()) and the state machine around a
 * fact once it exists (staxx_updates_moved_for_stack(),
 * staxx_update_skip_move()) are fully covered here; the proving step was
 * checked by hand against the three real images PLAN_61 measured.
 *
 * Weighted at the refusals, per PLAN_61's own list: a path not in the index;
 * a different account name even when the trailing name matches; the same
 * host, including each of Hub's three aliases; a dismissed hint that stays
 * dismissed; one that revives when the template moves somewhere new; and an
 * image never checked, which cannot be dismissed at all.
 */

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

$scratch  = '/tmp/staxx-moves-test.json';
$caIndex  = '/tmp/staxx/ca/index.json';
$cfgFile  = '/boot/config/plugins/staxx/staxx.cfg';
$testRoot = '/tmp/staxx-moves-test-root';

@unlink($scratch);
putenv('STAXX_UPDATE_STATE='.$scratch);

$caBackup  = @file_get_contents($caIndex);       // false if it did not exist
$cfgBackup = @file_get_contents($cfgFile);        // false if it did not exist

register_shutdown_function(function () use ($scratch, $caIndex, $cfgFile, $testRoot, $caBackup, $cfgBackup) {
  @unlink($scratch);
  $lock = (defined('STAXX_UPDATE_DIR') ? STAXX_UPDATE_DIR : '/tmp/staxx/updates').'/lock';
  if (is_dir($lock)) @rmdir($lock);

  if ($caBackup === false) { @unlink($caIndex); } else { @file_put_contents($caIndex, $caBackup); }
  if ($cfgBackup === false) { @unlink($cfgFile); } else { @file_put_contents($cfgFile, $cfgBackup); }

  @exec('rm -rf '.escapeshellarg($testRoot));
  echo "scratch state, catalogue index and STACK_ROOT restored\n";
});

require_once '/usr/local/emhttp/plugins/staxx/include/Links.php';

// ---- point STACK_ROOT at a throwaway tree, before staxx_cfg() is ever asked ----
// staxx_cfg() memoises on first call, so this has to happen before anything
// in this file reads it — nothing above does; requiring Links.php only
// defines functions and constants.
@exec('rm -rf '.escapeshellarg($testRoot));
mkdir($testRoot, 0755, true);
$cfgLines = $cfgBackup !== false ? preg_split('/\r?\n/', $cfgBackup) : [];
$cfgLines = array_values(array_filter($cfgLines, fn($l) => strpos(trim((string)$l), 'STACK_ROOT=') !== 0));
$cfgLines[] = 'STACK_ROOT="'.$testRoot.'"';
file_put_contents($cfgFile, implode("\n", $cfgLines)."\n");

ok('the throwaway stack root is now in force', staxx_stack_root() === $testRoot, staxx_stack_root());

// ---- a small catalogue-index fixture ----
// Ordinal 0: a Hub app (bare, no host) — the withdrawn-account refusal case.
// Ordinal 1: a Hub app that has genuinely moved to ghcr.io.
$fixtureApps = [
  ['r' => 'binhex/arch-prowlarr'],
  ['r' => 'ghcr.io/ich777/rustdesk-server-aio'],
];
if (!is_dir('/tmp/staxx/ca')) mkdir('/tmp/staxx/ca', 0755, true);
file_put_contents($caIndex, json_encode(['built' => time(), 'count' => 2, 'v' => 4, 'apps' => $fixtureApps]));

/* --------------------------------------- 1. staxx_links_image_host() ----- */

ok('a bare name has no host',              staxx_links_image_host('binhex/arch-prowlarr:latest') === '');
ok('a single-segment name has no host',    staxx_links_image_host('alpine:3.20') === '');
ok('docker.io folds to no host',           staxx_links_image_host('docker.io/binhex/arch-prowlarr:latest') === '');
ok('index.docker.io folds to no host',     staxx_links_image_host('index.docker.io/binhex/arch-prowlarr:latest') === '');
ok('registry-1.docker.io folds to no host', staxx_links_image_host('registry-1.docker.io/binhex/arch-prowlarr:latest') === '');
ok('a real host is not folded away',       staxx_links_image_host('ghcr.io/ich777/rustdesk-server-aio:latest') === 'ghcr.io');

/* ----------------------------------- 2. staxx_links_move_candidate() ---- */

ok('a path not in the index offers nothing',
   staxx_links_move_candidate('someoneelse/not-catalogued:latest') === []);

ok('a different account is never offered, even with the same trailing name',
   staxx_links_move_candidate('someoneelse/arch-prowlarr:latest') === []);

ok('no host either side offers nothing (nothing has moved)',
   staxx_links_move_candidate('binhex/arch-prowlarr:latest') === []);

foreach (['docker.io', 'index.docker.io', 'registry-1.docker.io'] as $alias) {
  ok('the '.$alias.' alias is never offered as a move',
     staxx_links_move_candidate($alias.'/binhex/arch-prowlarr:latest') === []);
}

$candidate = staxx_links_move_candidate('ich777/rustdesk-server-aio:latest');
ok('a genuine move is offered', $candidate === ['host' => 'ghcr.io', 'image' => 'ghcr.io/ich777/rustdesk-server-aio']);

ok('a docker.io-prefixed image triggers the same move as a bare one — the alias fold '
   .'reaches the join, not just staxx_links_image_host() on its own',
   staxx_links_move_candidate('docker.io/ich777/rustdesk-server-aio:latest') === $candidate);

ok('already at the catalogue host offers nothing',
   staxx_links_move_candidate('ghcr.io/ich777/rustdesk-server-aio:latest') === []);

/* ------------------------ 3. a stack, and the moved fact through it ----- */

$stackName = 'movetest';
$stackDir  = $testRoot.'/'.$stackName;
mkdir($stackDir, 0755, true);
file_put_contents($stackDir.'/compose.yaml',
  "services:\n"
  ."  moved:\n"
  ."    image: ich777/rustdesk-server-aio:latest\n"
  ."  plain:\n"
  ."    image: alpine:3.20\n"
);
staxx_scan_stacks_reset();

$movedImage = 'ich777/rustdesk-server-aio:latest';

// Written by hand, exactly the shape staxx_update_check() would have stored
// after a successful pass — this is the read side under test, and it must
// stay a pure read of this file, never touch the network itself.
staxx_update_state_save(['images' => [
  $movedImage => [
    'move' => [
      'host'   => 'ghcr.io',
      'tag'    => 'latest',
      'tags'   => ['latest', '1.2.0', '1.1.0'],
      'reason' => 'The template for this app now publishes at ghcr.io. Docker Hub still '
                . 'answers, but it is no longer where updates are pushed.',
    ],
  ],
  'alpine:3.20' => [], // checked, but never flagged — nothing to skip
]]);

$moved = staxx_updates_moved_for_stack($stackName);
ok('the moved service appears in the read reply', isset($moved['moved']));
ok('the plain service does not',                  !isset($moved['plain']));
if (isset($moved['moved'])) {
  $m = $moved['moved'];
  ok('repo is the registry-free path',   $m['repo'] === 'ich777/rustdesk-server-aio', $m['repo']);
  ok('host is carried through',          $m['host'] === 'ghcr.io');
  ok('tag is carried through',           $m['tag']  === 'latest');
  ok('tags is the shortlist',            $m['tags'] === ['latest', '1.2.0', '1.1.0']);
  ok('reason is a real sentence',        strpos($m['reason'], 'ghcr.io') !== false);
}

/* ------------------------------------------- 4. dismiss and revive ------ */

$err = '';
ok('an image never checked cannot be dismissed',
   !staxx_update_skip_move('nobody/nothing:latest', $err));
ok('and says why', $err !== '');

$err = '';
ok('a checked image with no move fact cannot be dismissed either',
   !staxx_update_skip_move('alpine:3.20', $err));
ok('and says why', $err !== '');

$err = '';
ok('dismissing a real move succeeds', staxx_update_skip_move($movedImage, $err));

$movedAfterSkip = staxx_updates_moved_for_stack($stackName);
ok('a dismissed hint stays dismissed', !isset($movedAfterSkip['moved']));

// The template moves house a second time — same image, a different new host.
$state = staxx_update_state();
// Host and reason are written together by the check pass and can never
// disagree in real use, so the fixture keeps them in step too — a test whose
// own output contradicts itself sends the next reader chasing a phantom.
$state['images'][$movedImage]['move']['host']   = 'codeberg.example.io';
$state['images'][$movedImage]['move']['reason'] =
  'The template for this app now publishes at codeberg.example.io. Docker Hub still '
  . 'answers, but it is no longer where updates are pushed.';
staxx_update_state_save($state);

$movedAfterRevive = staxx_updates_moved_for_stack($stackName);
ok('a dismissed hint revives when the template moves somewhere new',
   isset($movedAfterRevive['moved']) && $movedAfterRevive['moved']['host'] === 'codeberg.example.io');

/* ------------------------------- 5. staxx_updates_moved_report() -------- */
// Stage 4's one-off drift report: one line per drifted image, built from the
// state file alone. It deliberately carries NO stack/service attribution —
// getting that would mean parsing every stack's compose file, which shells
// out on a cache miss and could blow the 15-second budget the browser gives
// the self-test. Asserted here so nobody "improves" it back into a stack walk
// without meeting that constraint.

$report = staxx_updates_moved_report();
ok('the drift report has exactly one line', count($report) === 1);
if ($report) {
  ok('it names the address written in the file',
     strpos($report[0], 'ich777/rustdesk-server-aio:latest ->') === 0, $report[0]);
  ok('it names the address the template now uses',
     strpos($report[0], '-> codeberg.example.io/ich777/rustdesk-server-aio:latest') !== false, $report[0]);
  ok('it explains what the drift means, not just that it exists',
     strlen($report[0]) > strlen('ich777/rustdesk-server-aio:latest -> '
       . 'codeberg.example.io/ich777/rustdesk-server-aio:latest. '), $report[0]);
}

// staxx_selftest() is the actual surface this stage adds to — the drifted
// case must reach its report key as the same one-line fact.
$key = 'images pulling from a registry their template has left';
$selftest = staxx_selftest();
ok('the self-test carries the drift line',
   isset($selftest[$key]) && strpos($selftest[$key], 'codeberg.example.io') !== false,
   $selftest[$key] ?? '(missing)');

// Undo the revive and re-dismiss, so nothing has moved as far as the report
// is concerned — the "nothing to see" case must say so, not print nothing.
staxx_update_skip_move($movedImage, $err);
ok('with every move dismissed, the report is empty', staxx_updates_moved_report() === []);
ok('and the self-test says so in one sentence',
   strpos(staxx_selftest()[$key], 'none — ') === 0, staxx_selftest()[$key]);

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
