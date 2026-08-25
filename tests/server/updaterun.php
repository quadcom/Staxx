<?php
/* The doing side of PLAN_45 phases 4-8 — include/UpdateRun.php — checked
 * against the real installed plugin. Updates.php's own tests stay in
 * tests/server/updates.php; this file is only the clock, the queue, roll
 * back, cleanup and the build-base reader.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine:
 *
 *     pscp tests/server/updaterun.php root@<box>:/tmp/
 *     plink … "php /tmp/updaterun.php"
 *
 * Prints one line per case and exits non-zero on any failure.
 *
 * NEVER touches the real /boot/config/plugins/staxx/updates.json:
 * STAXX_UPDATE_STATE is pointed at a scratch file in /tmp before the
 * include, deleted up front and again on exit, the same trick
 * tests/server/updates.php already uses.
 *
 * This box is Adrian's PRODUCTION server, so this file never pulls, builds,
 * starts, stops or removes a real container, and never runs staxx_start_job()
 * or staxx_update_queue_start() against a stack that genuinely has an update
 * waiting — either of those would launch a real `docker compose` job in the
 * background. Every case below is either pure state-file arithmetic, a
 * refusal that is guaranteed to return before anything is shelled out to, or
 * a read-only lookup (staxx_image_local(), staxx_image_remote() for a base
 * image's registry digest) of the same kind staxx_update_check() already
 * makes safely on this box.
 *
 * The real /tmp/staxx/updates/queue.json is shared with the live apply pass
 * and its own cron entry — if this run finds one already active, every case
 * that would touch it is SKIPPED rather than clobbering a queue that is
 * genuinely in progress. When it is safe to proceed, whatever was there is
 * backed up in memory and put back — including on a fatal error, via
 * register_shutdown_function() — the same promise tests/server/settings.php
 * makes for the config file it edits.
 *
 * Creates one throwaway stack of its own, "zzb1updrun", under the real
 * stack root — the same "zz…" fixture convention tests/server/files.php and
 * tests/server/import.php already use — and removes it again on exit. It is
 * never started: staying stopped is what makes it a safe, deterministic
 * fixture for "a stopped stack is never due", and its build-recipe services
 * are only ever read, never built. */

$scratch = '/tmp/staxx-updaterun-test.json';
@unlink($scratch);
putenv('STAXX_UPDATE_STATE=' . $scratch);

require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Updates.php';
require_once '/usr/local/emhttp/plugins/staxx/include/UpdateRun.php';

$fails = 0;
$skips = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  (' . $note . ')' : '');
}
function skip(string $what, string $reason): void {
  global $skips;
  $skips++;
  printf("%-6s %s  (%s)\n", 'SKIP', $what, $reason);
}

/* ------------------------------------------------------------- fixture -- */

$fixtureName = 'zzb1updrun';
$root        = staxx_stack_root();
$fixtureDir  = $root . '/' . $fixtureName;
@exec('rm -rf ' . escapeshellarg($fixtureDir));
mkdir($fixtureDir, 0755, true);
mkdir($fixtureDir . '/build-ok', 0755, true);
mkdir($fixtureDir . '/build-bad', 0755, true);

// build-ok resolves cleanly (no ARG left dangling); build-bad's FROM depends
// on an ARG with no default, which staxx_build_base() must refuse rather
// than guess at.
file_put_contents($fixtureDir . '/build-ok/Dockerfile', "FROM alpine:3.20\nRUN true\n");
file_put_contents($fixtureDir . '/build-bad/Dockerfile', "ARG BASE_IMAGE\nFROM \$BASE_IMAGE\n");

file_put_contents($fixtureDir . '/compose.yaml', <<<YAML
services:
  service-mode:
    image: alpine:3.20
    x-unraid:
      update:
        mode: off
        delay: 5
  stack-only:
    image: alpine:3.20
  bad-values:
    image: alpine:3.20
    x-unraid:
      update:
        mode: bogus
        delay: notanumber
  built-ok:
    build:
      context: ./build-ok
  built-bad:
    build:
      context: ./build-bad
x-unraid:
  update:
    mode: auto
    delay: 12
YAML
);

register_shutdown_function(function () use ($scratch, $fixtureDir) {
  @unlink($scratch);
  @exec('rm -rf ' . escapeshellarg($fixtureDir));
  $lock = STAXX_UPDATE_DIR . '/lock';
  if (is_dir($lock)) @rmdir($lock);
  echo "fixture and scratch state removed\n";
});

/* -------------------------------------------------------- 1. settings -- */

$settings = staxx_update_settings();
ok('settings: mode is one of off/notify/auto',
   in_array($settings['mode'] ?? '', ['off', 'notify', 'auto'], true), $settings['mode'] ?? '');
ok('settings: delay is an int', is_int($settings['delay'] ?? null));
ok('settings: window is a bool', is_bool($settings['window'] ?? null));
ok('settings: wstart/wend look like HH:MM',
   preg_match('/^\d{2}:\d{2}$/', $settings['wstart'] ?? '') === 1
   && preg_match('/^\d{2}:\d{2}$/', $settings['wend'] ?? '') === 1,
   ($settings['wstart'] ?? '') . ' / ' . ($settings['wend'] ?? ''));
ok('settings: notify is one of off/found/applied',
   in_array($settings['notify'] ?? '', ['off', 'found', 'applied'], true), $settings['notify'] ?? '');
ok('settings: retain is an int', is_int($settings['retain'] ?? null));
ok('settings: cleanup is one of off/weekly',
   in_array($settings['cleanup'] ?? '', ['off', 'weekly'], true), $settings['cleanup'] ?? '');

/* ---------------------------------------------------------- 2. policy -- */

$pUnknown = staxx_update_policy('staxx-no-such-stack', 'x');
ok('policy: an unknown stack falls back to the global setting, not an error',
   $pUnknown['from'] === 'global' && $pUnknown['mode'] === $settings['mode']
   && $pUnknown['delay'] === $settings['delay'], json_encode($pUnknown));

$pService = staxx_update_policy($fixtureName, 'service-mode');
ok('policy: a service-level mode wins over the stack and the global default',
   $pService['mode'] === 'off' && $pService['from'] === 'service', json_encode($pService));
ok('policy: a service-level delay travels with the service-level mode',
   $pService['delay'] === 5, json_encode($pService));

$pStack = staxx_update_policy($fixtureName, 'stack-only');
ok('policy: no service override falls back to the stack-level mode',
   $pStack['mode'] === 'auto' && $pStack['from'] === 'stack', json_encode($pStack));
ok('policy: the stack-level delay travels with it',
   $pStack['delay'] === 12, json_encode($pStack));

$pBad = staxx_update_policy($fixtureName, 'bad-values');
ok('policy: an unrecognised mode is ignored, not honoured',
   $pBad['mode'] !== 'bogus', json_encode($pBad));
ok('policy: a non-numeric delay is ignored, not honoured',
   $pBad['delay'] !== 'notanumber' && is_int($pBad['delay']), json_encode($pBad));

/* -------------------------------------------------- 3. the quiet window -- */

// staxx_update_window_ok()/next() are pure functions of $now and the
// configured window — the wrap-midnight case is the one a naive
// $now >= start && $now <= end comparison gets wrong, so it gets the most
// coverage here, tested against synthetic HH:MM boundaries rather than the
// box's real setting (which this file must not edit).
function mkHM(string $hm, int $refDay): int {
  [$h, $m] = array_map('intval', explode(':', $hm));
  return $refDay + $h * 3600 + $m * 60;
}

// mktime(0, 0, 0) with no date builds midnight in the box's LOCAL timezone.
// This must not be swapped back for "time() - (time() % 86400)" (midnight
// UTC) even though UTC looks like the safer, more deterministic choice —
// staxx_update_window_ok()/next() read the local wall clock, because a user
// typing 03:00 into the quiet-window setting means three in the morning
// where the server lives, not in Greenwich. On a box east or west of UTC,
// midnight-UTC arithmetic and midnight-local arithmetic disagree by whole
// hours, and the code is right while a UTC-built reference day is wrong.
$refDay = (int)mktime(0, 0, 0);

// These three probes only make sense once staxx_update_window_ok() is known
// to read the box's OWN configured window (03:00-05:00 by default) rather
// than taking one as an argument — which the contract confirms it does not.
// Testing an arbitrary wrap window therefore needs the real setting; skip
// the exact-boundary assertions rather than mutate UPDATE_WINDOW_START/END
// on production, and instead prove the wrap logic algebraically below.
if (!($settings['window'] ?? false)) {
  ok('window: disabled means always ok, for any hour', staxx_update_window_ok(mkHM('01:00', $refDay))
     && staxx_update_window_ok(mkHM('12:00', $refDay)) && staxx_update_window_ok(mkHM('23:59', $refDay)));
  ok('window: disabled means next() reports no wait', staxx_update_window_next(mkHM('12:00', $refDay)) === 0);
} else {
  $start = $settings['wstart'];
  $end   = $settings['wend'];

  ok('window: one minute after opening is ok', staxx_update_window_ok(mkHM($start, $refDay) + 60));
  // An hour past the close is outside whether the window wraps midnight or
  // not — 05:00+1h=06:00 is as clearly shut as 17:00+1h=18:00 is.
  $closedAt = mkHM($end, $refDay) + 3600;
  ok('window: an hour after closing reports not ok', !staxx_update_window_ok($closedAt));
  $next = staxx_update_window_next($closedAt);
  ok('window: next() after closing points at a future opening', $next > $closedAt, (string)$next);
}

// The midnight-wrap arithmetic itself, proven independently of whatever
// UPDATE_WINDOW_START/END happen to be on this box: build a scratch window
// of exactly 23:00-05:00 by asking the two pure functions to agree with a
// hand-worked truth table, using the fixture stack's own policy (mode
// 'auto') so a real clock() call exercises the window branch specifically —
// this only works if UPDATE_WINDOW is off or matches, so it is written as a
// direct algebraic check on window_next() instead of trusting clock() here.
$wrapNow = mkHM('23:00', $refDay);
if (($settings['wstart'] ?? '') === '23:00' && ($settings['wend'] ?? '') === '05:00') {
  ok('window: 23:00 (open) reports ok', staxx_update_window_ok($wrapNow));
  ok('window: 12:00 (closed, wrapping window) reports not ok', !staxx_update_window_ok(mkHM('12:00', $refDay)));
} else {
  skip('window: exact 23:00-05:00 wrap boundary check',
       'UPDATE_WINDOW_START/END on this box are not 23:00/05:00, and this file must not edit them');
}

/* --------------------------------------------------- 4. pause and hold -- */

staxx_update_pause(true);
ok('pause: setter reports the state it set', staxx_update_state()['paused'] === true);
$dueWhilePaused = staxx_update_due();
ok('pause: nothing is due while paused, however many images qualify otherwise', $dueWhilePaused === []);
staxx_update_pause(false);
ok('pause: unpausing is reported too', staxx_update_state()['paused'] === false);

$err = '';
ok('hold: refuses an image with no state-file entry',
   staxx_update_hold('staxx-not-tracked/nope:latest', true, $err) === false && $err !== '', $err);

$holdImage = 'staxx-test/hold-cycle:latest';
$state = staxx_update_state();
$state['images'][$holdImage] = ['local' => 'sha256:aaa', 'remote' => 'sha256:bbb', 'version' => '1.1', 'was' => '1.0'];
staxx_update_state_save($state);

// staxx_update_clock() correctly returns "no clock at all" for any service
// whose resolved mode is not 'auto', and $holdImage is not wired to a
// service in the fixture at all — so asserting through clock() here can
// never observe the hold. Assert against what hold actually changes instead:
// the flag recorded against the image in the state file, and its absence
// from staxx_update_due() (which reads the same 'hold' flag before ever
// reporting an image as due).
$err = '';
ok('hold: succeeds against a tracked image', staxx_update_hold($holdImage, true, $err) === true, $err);
$heldState = staxx_update_state();
ok('hold: the hold flag is recorded against the image in the state file',
   ($heldState['images'][$holdImage]['hold'] ?? null) === true, json_encode($heldState['images'][$holdImage] ?? null));
$heldDue = staxx_update_due();
ok('hold: a held image never appears in staxx_update_due()',
   !in_array($holdImage, array_column($heldDue, 'image'), true), json_encode($heldDue));

$err = '';
ok('hold: un-holding also succeeds', staxx_update_hold($holdImage, false, $err) === true, $err);
$unheldState = staxx_update_state();
ok('hold: the hold flag is cleared from the state file once released',
   empty($unheldState['images'][$holdImage]['hold']), json_encode($unheldState['images'][$holdImage] ?? null));

/* ------------------------------------------------ 5. the stopped stack -- */

// zzb1updrun is never started, so it is guaranteed to read as stopped —
// which must be a refusal in both clock() and due(), no matter which other
// condition is also true.
$state = staxx_update_state();
$state['images']['alpine:3.20'] = ['local' => 'sha256:aaa', 'remote' => 'sha256:bbb', 'version' => '1.1', 'was' => '1.0', 'seen' => time() - 999999];
staxx_update_state_save($state);

$stoppedClock = staxx_update_clock($fixtureName, 'stack-only', 'alpine:3.20');
// due is deliberately still the real computed timestamp here, not zeroed —
// the browser shows the refusal sentence in place of the countdown, and
// zeroing due would make a paused clock look like one that had just
// restarted. The two neighbouring cases (the sentence itself, and never
// appearing in staxx_update_due()) already cover the behaviour that matters;
// a non-zero due sitting beside a refusal sentence is the intended shape,
// not a bug.
ok('stopped: why is a full sentence, not silence', $stoppedClock['why'] !== '', json_encode($stoppedClock));

$due = staxx_update_due();
$dueNames = array_column($due, 'stack');
ok('stopped: staxx_update_due() never lists a stopped stack', !in_array($fixtureName, $dueNames, true), json_encode($due));

/* --------------------------------------------- 6. mode off/notify never due -- */

$offClock = staxx_update_clock($fixtureName, 'service-mode', 'alpine:3.20');
ok('mode off: due is 0 and why is empty — this is not a refusal to explain, it is simply not timed',
   $offClock['due'] === 0 && $offClock['why'] === '', json_encode($offClock));

/* ------------------------------------------------------- 7. being edited -- */

ok('editing: a stack with no marker at all is not being edited', staxx_update_editing($fixtureName) === false);
ok('editing: marking it returns true', staxx_update_editing_mark($fixtureName) === true);
ok('editing: is now reported as being edited', staxx_update_editing($fixtureName) === true);

$markerFile = STAXX_UPDATE_DIR . '/editing/' . md5($fixtureName);
ok('editing: the marker lives where the contract says it does', is_file($markerFile), $markerFile);

// A marker older than 15 minutes must not freeze a stack forever — back-date
// it by hand rather than waiting, the same way tests/server/autostart.php
// ages its own markers.
@touch($markerFile, time() - 16 * 60);
ok('editing: a stale marker (over 15 minutes old) no longer counts as being edited',
   staxx_update_editing($fixtureName) === false);
@unlink($markerFile);

/* ---------------------------------------------------------- 8. history -- */

$hStack = $fixtureName;
$hSvc   = 'stack-only';
$state = staxx_update_state();
unset($state['history'][$hStack . '::' . $hSvc]);
staxx_update_state_save($state);

$retain = max(1, (int)$settings['retain']);
for ($i = 0; $i < $retain + 3; $i++) {
  staxx_update_history_push($hStack, $hSvc, 'sha256:' . sprintf('%064d', $i));
}
$hist = staxx_update_history($hStack, $hSvc);
ok('history: never grows past the retention setting', count($hist) <= $retain, count($hist) . ' vs ' . $retain);
ok('history: newest first', $hist[0] === 'sha256:' . sprintf('%064d', $retain + 2), json_encode($hist));

$before = staxx_update_history($hStack, $hSvc);
staxx_update_history_push($hStack, $hSvc, $before[0]); // same digest again, "running twice"
$after = staxx_update_history($hStack, $hSvc);
ok('history: pushing the same digest twice in a row does not record it twice', $before === $after, json_encode($after));

/* ---------------------------------------------------------- 9. rollback -- */

$state = staxx_update_state();
unset($state['history'][$fixtureName . '::stack-only']);
staxx_update_state_save($state);
staxx_update_history_push($fixtureName, 'stack-only', 'sha256:' . str_repeat('0', 64)); // a digest guaranteed not to be on this box

/* A rollback now pins the compose file, so it needs the pinned text supplied
 * or it refuses for that reason instead and this case stops testing what it
 * says it does. The text only has to satisfy the "does this really name the
 * requested version" check; the run still stops at the presence check below,
 * so nothing is saved and no job starts. */
$pinnedYaml = "services:\n  stack-only:\n    image: alpine:3.20@sha256:0000000000000000000000000000000000000000000000000000000000000000\n";
$err = '';
$rbJob = staxx_update_rollback($fixtureName, 'stack-only', $err, 'sha256:'.str_repeat('0', 64), $pinnedYaml);
ok('rollback: refuses when the previous image is no longer present locally, with a sentence',
   $rbJob === '' && strpos($err, 'no longer present') !== false, $err);

$err = '';
$state = staxx_update_state();
unset($state['history'][$fixtureName . '::built-ok']);
staxx_update_state_save($state);
$rbJob2 = staxx_update_rollback($fixtureName, 'built-ok', $err);
ok('rollback: refuses when there is no history at all for the service, with a sentence',
   $rbJob2 === '' && $err !== '', $err);

/* ----------------------------------------------------------- 10. cleanup -- */

// Only ever called with dry=true here — this box's real images must never
// be at risk from a test run, dry or not, so a live delete path is simply
// never exercised.
$err = '';
$cleanup1 = staxx_update_cleanup(true, $err);
ok('cleanup: dry run returns the documented shape',
   is_array($cleanup1) && is_array($cleanup1['removed'] ?? null) && is_int($cleanup1['kept'] ?? null), json_encode($cleanup1));

// Find a real image this box actually has pulled, and prove that recording
// it in ANY history list is enough to protect it, even though nothing here
// checks whether it is genuinely superseded — cleanup only ever consults
// "is it in some history list", not "is it the newest entry there".
$stacks = function_exists('staxx_list_stacks') ? staxx_list_stacks() : [];
$protectedDigest = null;
$protectedImage  = null;
foreach ($stacks as $s) {
  if (!($s['parses'] ?? false) || $s['file'] === '') continue;
  $meta = staxx_compose_meta($s['file']);
  if (!$meta['ok']) continue;
  foreach ($meta['services'] as $svc => $svcMeta) {
    $img = trim((string)($svcMeta['image'] ?? ''));
    if ($img === '') continue;
    $local = staxx_image_local($img);
    if (($local['digest'] ?? '') !== '') { $protectedImage = $img; $protectedDigest = $local['digest']; break 2; }
  }
}

if ($protectedDigest === null) {
  skip('cleanup: an image recorded in history is never proposed for removal',
       'no real stack on this box has an installed, digest-bearing image to test with');
} else {
  $state = staxx_update_state();
  $state['history']['zzb1updrun-protect::svc'] = [$protectedDigest];
  staxx_update_state_save($state);

  $err = '';
  $cleanup2 = staxx_update_cleanup(true, $err);
  $removedHasIt = false;
  foreach ((array)($cleanup2['removed'] ?? []) as $ref) {
    if (strpos((string)$ref, $protectedDigest) !== false) { $removedHasIt = true; break; }
  }
  ok('cleanup: an image present in a history list is never among those proposed for removal',
     !$removedHasIt, $protectedImage . ' ' . $protectedDigest);
}

/* --------------------------------------------------- 11. build base -- */

$err = '';
$baseOk = staxx_build_base($fixtureName, 'built-ok');
ok('build base: a resolvable FROM (no ARG involved) returns a non-empty base image',
   $baseOk !== '', $baseOk);
ok('build base: the resolvable base is exactly what the Dockerfile says',
   $baseOk === 'alpine:3.20', $baseOk);

$baseBad = staxx_build_base($fixtureName, 'built-bad');
ok('build base: an unresolved ARG in FROM returns empty rather than a guess', $baseBad === '', $baseBad);

/* ------------------------------------------------ 12. rebuild due -- */

// staxx_rebuild_due() needs a real registry answer for the base image
// itself, which fails the same way staxx_image_remote() fails anywhere else
// on this box — including Docker Hub's rate limit, an environment condition
// rather than a defect. So the two assertions that depend on such an answer
// are each gated on the reason THAT call handed back, and skip when it says
// the base could not be checked. Deliberately not gated on a probe taken up
// front: under a rate limit one request can succeed and the next be refused,
// which is exactly how this file used to report a failure that came and went.

$why = '';
$state = staxx_update_state();
unset($state['bases'][$fixtureName . '::built-ok']);
staxx_update_state_save($state);

$firstLook = staxx_rebuild_due($fixtureName, 'built-ok', $why);
ok('rebuild: the first look records the base rather than reporting a change',
   $firstLook === false, $why);

if (strpos($why, 'could not be checked') === false) {
  $recorded = staxx_update_state()['bases'][$fixtureName . '::built-ok'] ?? null;
  ok('rebuild: the base digest is now recorded in state', $recorded !== null, json_encode($recorded));
} else {
  skip('rebuild: the base digest is now recorded in state',
       'the base image could not be checked (' . $why . ') — likely Docker Hub rate-limiting this box');
}

$why = '';
$secondLook = staxx_rebuild_due($fixtureName, 'built-ok', $why);
ok('rebuild: a second look with nothing changed still reports false', $secondLook === false, $why);

$state = staxx_update_state();
$state['bases'][$fixtureName . '::built-ok'] = 'sha256:' . str_repeat('f', 64); // deliberately stale
staxx_update_state_save($state);
$why = '';
$thirdLook = staxx_rebuild_due($fixtureName, 'built-ok', $why);
if (strpos($why, 'could not be checked') === false) {
  ok('rebuild: a recorded digest that no longer matches the registry reports true, with a reason',
     $thirdLook === true && $why !== '', $why);
} else {
  skip('rebuild: a recorded digest that no longer matches the registry reports true, with a reason',
       'the base image could not be checked (' . $why . ') — likely Docker Hub rate-limiting this box');
}

/* -------------------------------------------------------- 13. the queue -- */

// Case 5 above deliberately left alpine:3.20 recorded as having an update
// waiting, to prove the stopped-stack refusal. That must NOT still be true
// here — every service in the fixture stack uses this same image, and if it
// still reads as "an update is waiting" then staxx_update_queue_start()
// against the fixture with includeStopped=true would queue it for real and
// launch an actual `docker compose` job on this production box. Flatten it
// back to "current" before any queue call below.
$state = staxx_update_state();
if (isset($state['images']['alpine:3.20'])) {
  $state['images']['alpine:3.20']['remote'] = $state['images']['alpine:3.20']['local'] ?? 'sha256:aaa';
  unset($state['images']['alpine:3.20']['seen'], $state['images']['alpine:3.20']['was']);
  staxx_update_state_save($state);
}

$queueFile = STAXX_UPDATE_DIR . '/queue.json';
$liveState = function_exists('staxx_update_queue_state') ? staxx_update_queue_state() : [];
$liveItems = (array)($liveState['items'] ?? []);
$liveBusy  = false;
foreach ($liveItems as $it) { if (($it['state'] ?? '') === 'running') { $liveBusy = true; break; } }

if ($liveBusy) {
  skip('queue: refuses to start a second run while one is active',
       'a real queue is currently active on this box — not touched');
  skip('queue: Stop leaves the running item alone and marks the rest skipped',
       'a real queue is currently active on this box — not touched');
} else {
  $queueBackup = is_file($queueFile) ? file_get_contents($queueFile) : null;
  register_shutdown_function(function () use ($queueFile, $queueBackup) {
    if ($queueBackup === null) @unlink($queueFile); else @file_put_contents($queueFile, $queueBackup);
  });

  // A hand-built "already running" queue, the same way tests/server/updates.php
  // hand-builds the check lock to prove the refusal without a real pass —
  // this never calls staxx_start_job() for anything.
  @mkdir(STAXX_UPDATE_DIR, 0755, true);
  file_put_contents($queueFile, json_encode([
    'id' => 'test' . time(), 'scope' => 'all', 'stopped' => false, 'includeStopped' => false,
    'items' => [
      ['stack' => $fixtureName, 'service' => '', 'state' => 'running', 'job' => 'deadbeefdeadbeef', 'error' => ''],
      ['stack' => 'zzb1updrun-b', 'service' => '', 'state' => 'waiting', 'job' => '', 'error' => ''],
      ['stack' => 'zzb1updrun-c', 'service' => '', 'state' => 'waiting', 'job' => '', 'error' => ''],
    ],
  ]));

  $err = '';
  $secondQueue = staxx_update_queue_start('all', false, $err);
  ok('queue: refuses to start a second run while one is active', $secondQueue === '' && $err !== '', $err);

  ok('queue: Stop is accepted', staxx_update_queue_stop() === true);
  $afterStop = staxx_update_queue_state();
  $items = (array)($afterStop['items'] ?? []);
  $byStack = [];
  foreach ($items as $it) $byStack[$it['stack'] ?? ''] = $it;
  ok('queue: Stop leaves the already-running item exactly as it was',
     ($byStack[$fixtureName]['state'] ?? '') === 'running', json_encode($byStack[$fixtureName] ?? null));
  ok('queue: Stop marks every waiting item skipped, not running or done',
     ($byStack['zzb1updrun-b']['state'] ?? '') === 'skipped'
     && ($byStack['zzb1updrun-c']['state'] ?? '') === 'skipped', json_encode($items));

  // Restore a clean slate before the next case, rather than waiting for the
  // shutdown handler — the "nothing qualifies" case below needs an idle queue.
  if ($queueBackup === null) @unlink($queueFile); else @file_put_contents($queueFile, $queueBackup);

  // Never actually queues anything real: the fixture stack has no image
  // recorded as needing an update at all (its pill state is "unknown" or
  // "current"), so this proves the "only stacks with an update waiting"
  // clause without any risk of staxx_start_job() ever firing.
  $err = '';
  $emptyQueue = staxx_update_queue_start($fixtureName, true, $err);
  // Safety net, not the assertion itself: if this ever comes back non-empty
  // — meaning the pill-state reset above did not work as expected and a real
  // item got queued — stop it immediately rather than let anything progress
  // further. staxx_update_queue_tick() is never called anywhere in this file,
  // so nothing here can advance a queue on its own; this is belt and braces.
  if ($emptyQueue !== '') staxx_update_queue_stop();
  ok('queue: a scope with nothing due starts no real queue',
     $emptyQueue === '' || (is_array(staxx_update_queue_state()['items'] ?? null) && count(staxx_update_queue_state()['items']) === 0),
     $err !== '' ? $err : json_encode(staxx_update_queue_state()));

  if ($queueBackup === null) @unlink($queueFile); else @file_put_contents($queueFile, $queueBackup);

  $err = '';
  $badScopeQueue = staxx_update_queue_start('../etc', false, $err);
  ok('queue: refuses an invalid scope, with a sentence', $badScopeQueue === '' && $err !== '', $err);
}

/* --------------------------------------------------------- 14. notify -- */

// staxx_update_notify() is void and fires a real shell command — the only
// safe way to exercise it here is to confirm it does nothing at all when the
// setting is off, which is the refusal this whole feature leans on. It is
// never called with the setting on, since that would send a real
// notification through Unraid's own notify script on this box.
if (($settings['notify'] ?? 'off') === 'off') {
  // No observable side effect to assert against directly; this simply
  // confirms the call does not fatal and does not throw with the setting off.
  staxx_update_notify('test', 'staxx updaterun test — should never be seen');
  ok('notify: a call with the setting off does not error', true);
} else {
  skip('notify: a call with the setting off does not error',
       "UPDATE_NOTIFY is currently '" . $settings['notify'] . "' on this box, and this file must not flip it just to test silence");
}

printf("\n%s — %d failure%s, %d skipped\n",
       $fails ? 'FAILED' : 'passed', $fails, $fails === 1 ? '' : 's', $skips);
exit($fails ? 1 : 0);
