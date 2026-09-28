<?php
/* StaXX — the pinned-service reminder (PLAN_205): the daily walk and weekly
 * send hooked into the 15-minute apply pass that notices a service is still
 * pinned to an exact build and says so once, never once per service.
 *
 * Two clocks, proved separately: 'pinnedWalkAt' gates the slow walk itself
 * (once a day) and is all that keeps 'pinnedSince' current; 'pinnedNoticeAt'
 * gates SENDING (once a week) and only moves when a send decision is made.
 *
 * Proves: the very first run ever records pinnedSince and starts both clocks
 * but sends nothing; a second call inside a day does nothing at all, not
 * even touch pinnedSince; a walk after a day keeps or drops pinnedSince as
 * the pin is kept or released, but still sends nothing before a week has
 * passed; a send once the week is up, naming every opted-in pinned service
 * and leaving out an unpinned one and one whose own
 * x-unraid.update.notify.pinned is false; and an 'images' entry written by
 * something else in the gap between this pass's slow walk and its own save
 * survives.
 *
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. Needs STORE_ROOT
 * pointed at a scratch store, run through tests/server/run-with-store.sh the
 * same way tests/server/pinned_due.php does:
 *
 *     pscp tests/server/run-with-store.sh tests/server/pinned_reminder.php root@<box>:/tmp/
 *     plink … 'bash /tmp/run-with-store.sh /tmp/staxx-pinned-reminder-store /tmp/pinned_reminder.php'
 *
 * The central update-state file lives on the flash drive
 * (/boot/config/plugins/staxx/updates.json) and is NEVER touched — this
 * script points STAXX_UPDATE_STATE at a scratch file in /tmp via putenv(),
 * before the first require, the same trick tests/server/pinned_due.php uses.
 *
 * Never sends a real notification: STAXX_NOTIFY_BIN — the override
 * staxx_update_notify() gained for exactly this — is pointed at a throwaway
 * stub script that logs its own arguments to a scratch file instead of
 * calling Unraid's real notifier, so staxx_update_notify() runs for real and
 * nothing ever reaches this box's notification centre.
 *
 * The concurrent-write test needs no seam in the plugin itself: it primes
 * this process's own state cache with an aged snapshot via
 * staxx_update_state_cache() (so the pass's own first read — and the copy
 * it starts its walk from — is the stale one), then writes a NEWER copy of
 * the state file straight to disk, carrying one extra 'images' entry no
 * fixture stack ever produced, as if a check pass had just landed its own
 * write. Running the pass after that proves the real code path: it can only
 * see that entry in its final save by actually re-reading the state file
 * from disk rather than trusting the stale snapshot it started with, which
 * is exactly what PLAN_205's fix 3 requires.
 *
 * Creates its own throwaway stacks, "zzpr…", under the temporary stack
 * root, and its own scratch state file, stub script and notify log. Cleans
 * up on the way in too, so a previous interrupted run cannot affect this
 * one. Nothing here runs docker, starts a job, recreates a container, or
 * touches the live update queue — every fixture image is a made-up
 * reference that resolves nowhere, and staxx_update_pinned_reminder_pass()
 * only ever reads compose files and the state file.
 */

$scratch    = '/tmp/staxx-pinned-reminder-test.json';
$notifyLog  = '/tmp/staxx-pinned-reminder-notify.log';
$stubPath   = '/tmp/staxx-pinned-reminder-notify-stub.sh';
@unlink($scratch);
@unlink($notifyLog);
@unlink($stubPath);
putenv('STAXX_UPDATE_STATE='.$scratch);
putenv('STAXX_NOTIFY_BIN='.$stubPath);

// Logs its own arguments, NUL-separated so a body carrying a newline (one
// line per pinned service) can never be mistaken for a second invocation —
// and never calls the real notifier.
file_put_contents($stubPath, "#!/bin/sh\nprintf '%s\\0' \"\$@\" > ".escapeshellarg($notifyLog)."\n");
chmod($stubPath, 0755);

register_shutdown_function(function () use ($scratch, $notifyLog, $stubPath) {
  @unlink($scratch);
  @unlink($notifyLog);
  @unlink($stubPath);
  $lock = (defined('STAXX_UPDATE_DIR') ? STAXX_UPDATE_DIR : '/tmp/staxx/updates').'/lock';
  if (is_dir($lock)) @rmdir($lock);
});

require_once '/usr/local/emhttp/plugins/staxx/include/UpdateRun.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

/* A digest has to look like a real one, or compose's own image-reference
 * parser refuses the fixture before it is even used as a pinned example. */
function dg(string $label): string {
  return hash('sha256', $label);
}

if (staxx_stack_root() !== '/tmp/staxx-pinned-reminder-store/stacks') {
  echo "FAIL   the temporary stack root is not in place (got ".staxx_stack_root().")\n";
  exit(1);
}
if (staxx_update_state_file() !== $scratch) {
  echo "FAIL   the temporary update-state file is not in place (got ".staxx_update_state_file().")\n";
  exit(1);
}

$root = staxx_stack_root();
$day  = 86400;               // STAXX_PINNED_WALK_INTERVAL
$week = 7 * 86400;           // STAXX_PINNED_NOTICE_INTERVAL

function pr_wipe(): void {
  global $root, $scratch, $notifyLog;
  @exec('rm -rf '.escapeshellarg($root));
  mkdir($root, 0755, true);
  @unlink($scratch);
  @unlink($notifyLog);
  staxx_scan_stacks_reset();
  $err = null;
  staxx_compose_meta('', $err, true); // clears its own in-process cache too
}

function pr_make_stack(string $rel, string $yaml): void {
  global $root;
  $dir = $root.'/'.$rel;
  @exec('rm -rf '.escapeshellarg($dir));
  mkdir($dir, 0755, true);
  file_put_contents($dir.'/compose.yaml', $yaml);
  staxx_scan_stacks_reset();
  // staxx_compose_meta() also caches in-process by file path alone (the
  // on-disk half is keyed by content, but that guard is never reached while
  // this survives) — a fixture rewriting the SAME path within one run, as
  // the "released" case below does, would otherwise read the compose file
  // as it stood before this edit.
  $err = null;
  staxx_compose_meta('', $err, true);
}

/** null when nothing was ever sent, else the subject/body the stub caught. */
function pr_read_notify(): ?array {
  global $notifyLog;
  if (!is_file($notifyLog)) return null;
  $parts = explode("\0", (string)file_get_contents($notifyLog));
  array_pop($parts); // the trailing empty piece after the final NUL
  $subject = ''; $body = '';
  foreach ($parts as $i => $p) {
    if ($p === '-s') $subject = $parts[$i + 1] ?? '';
    if ($p === '-d') $body = $parts[$i + 1] ?? '';
  }
  return ['subject' => $subject, 'body' => $body];
}

pr_wipe();

$webImage = 'ghcr.io/example/prweb:1.2.3@sha256:'.dg('pr-web');
$webImageReleased = 'ghcr.io/example/prweb:1.2.3'; // what it becomes once released
$cacheImage = 'ghcr.io/example/prcache:7.7.7@sha256:'.dg('pr-cache');
$dbImage = 'ghcr.io/example/prdb:9.9@sha256:'.dg('pr-db');
$appImage = 'ghcr.io/example/prapp:latest'; // never pinned at all

pr_make_stack('zzprpinned', "services:\n"
  ."  web:\n    image: $webImage\n"
  ."  db:\n    image: $dbImage\n"
  ."    x-unraid:\n      update:\n        notify:\n          pinned: false\n");
pr_make_stack('zzprpinned2', "services:\n  cache:\n    image: $cacheImage\n");
pr_make_stack('zzprunpinned', "services:\n  app:\n    image: $appImage\n");

/* ======================================================================= *
 * 1. First run ever (both clocks unset): records pinnedSince for what it
 *    finds and starts both clocks from now, but sends nothing — the first
 *    real reminder must wait a full week from install, not fire at once.
 * ======================================================================= */
staxx_update_pinned_reminder_pass();

ok('the first run ever sends nothing', !is_file($notifyLog));
$state = staxx_update_state();
$t0 = time();
ok('the first run starts the walk clock',
   (int)($state['pinnedWalkAt'] ?? 0) > 0 && (int)($state['pinnedWalkAt'] ?? 0) <= $t0);
ok('the first run starts the notice clock',
   (int)($state['pinnedNoticeAt'] ?? 0) > 0 && (int)($state['pinnedNoticeAt'] ?? 0) <= $t0);
ok('pinnedSince is recorded for the web pin on the first run',
   isset($state['images'][$webImage]['pinnedSince']));
ok('pinnedSince is recorded for the cache pin on the first run',
   isset($state['images'][$cacheImage]['pinnedSince']));
$sinceWeb1 = (int)($state['images'][$webImage]['pinnedSince'] ?? 0);
$walkAt1   = (int)($state['pinnedWalkAt'] ?? 0);
$noticeAt1 = (int)($state['pinnedNoticeAt'] ?? 0);

/* ======================================================================= *
 * 2. A second call inside a day of the last walk does nothing at all — not
 *    even pinnedSince moves, proving the walk itself is gated, not just the
 *    send.
 * ======================================================================= */
staxx_update_pinned_reminder_pass();

$state = staxx_update_state();
ok('a walk inside a day leaves pinnedWalkAt untouched',
   (int)($state['pinnedWalkAt'] ?? 0) === $walkAt1);
ok('a walk inside a day leaves pinnedNoticeAt untouched',
   (int)($state['pinnedNoticeAt'] ?? 0) === $noticeAt1);
ok('a walk inside a day leaves pinnedSince untouched',
   (int)($state['images'][$webImage]['pinnedSince'] ?? 0) === $sinceWeb1);
ok('a walk inside a day sends nothing', !is_file($notifyLog));

/* ======================================================================= *
 * 3. After a day, the walk runs again: pinnedSince is kept for a pin still
 *    in place, but nothing is sent yet because the week has not passed —
 *    the two clocks really are independent.
 * ======================================================================= */
staxx_update_state_save(['pinnedWalkAt' => time() - ($day + 5)]);
@unlink($notifyLog);
staxx_update_pinned_reminder_pass();

$state = staxx_update_state();
// time() is second-granular and this whole suite can run inside one second,
// so the fresh walk timestamp can legitimately equal $walkAt1 rather than
// exceed it — what actually proves the walk ran is that it moved off the
// artificially aged value just forced onto the state, not that it beat a
// timestamp from a moment ago.
ok('the walk clock moves on after a day', (int)($state['pinnedWalkAt'] ?? 0) >= $walkAt1);
ok('pinnedSince is kept across a day-later walk while still pinned',
   (int)($state['images'][$webImage]['pinnedSince'] ?? 0) === $sinceWeb1,
   'was '.$sinceWeb1.', now '.(int)($state['images'][$webImage]['pinnedSince'] ?? 0));
ok('nothing is sent before the week is up even though the walk ran',
   !is_file($notifyLog));
ok('pinnedNoticeAt does not move when nothing was due to send',
   (int)($state['pinnedNoticeAt'] ?? 0) === $noticeAt1);

/* ======================================================================= *
 * 4. Releasing the pin and walking again (still inside the week) drops
 *    pinnedSince for the web service, and still sends nothing.
 * ======================================================================= */
pr_make_stack('zzprpinned', "services:\n"
  ."  web:\n    image: $webImageReleased\n"
  ."  db:\n    image: $dbImage\n"
  ."    x-unraid:\n      update:\n        notify:\n          pinned: false\n");
staxx_update_state_save(['pinnedWalkAt' => time() - ($day + 5)]);
@unlink($notifyLog);
staxx_update_pinned_reminder_pass();

$state = staxx_update_state();
ok('pinnedSince is dropped once the pin is released',
   !isset($state['images'][$webImage]['pinnedSince']));
ok('releasing a pin sends nothing on its own', !is_file($notifyLog));

/* ======================================================================= *
 * 5. Once the week is up (and a day has passed for the walk too), one
 *    message goes out naming every opted-in pinned service still in place
 *    and leaving out the released one, the opted-out one, and the never-
 *    pinned one.
 * ======================================================================= */
staxx_update_state_save([
  'pinnedWalkAt'   => time() - ($day + 5),
  'pinnedNoticeAt' => time() - ($week + 5),
]);
@unlink($notifyLog);
staxx_update_pinned_reminder_pass();

$sent = pr_read_notify();
ok('a send happens once the week has passed', $sent !== null);
if ($sent !== null) {
  ok('subject names the pinned container',
     $sent['subject'] === 'StaXX: 1 container still pinned', $sent['subject']);
  ok('body lists the cache service pinned line',
     strpos($sent['body'], 'zzprpinned2 / cache is pinned to 7.7.7 ('.substr(dg('pr-cache'), 0, 12).') since') !== false,
     $sent['body']);
  ok('body leaves out the released web service',
     strpos($sent['body'], 'web is pinned') === false, $sent['body']);
  ok('body leaves out the service whose own notify.pinned is false',
     strpos($sent['body'], 'db is pinned') === false, $sent['body']);
  ok('body leaves out the unpinned service entirely',
     strpos($sent['body'], 'app') === false, $sent['body']);
  $hint = 'Pick a tag from its update choices to release it.';
  ok('body ends with the release hint',
     substr(rtrim($sent['body']), -strlen($hint)) === $hint,
     $sent['body']);
}
$state = staxx_update_state();
// Same second-granularity caveat as the walk clock above: this whole suite
// can run inside one wall-clock second, so equal to the aged value this
// test forced is still proof the clock moved off it, not proof it did not.
ok('pinnedNoticeAt moves on after a send', (int)($state['pinnedNoticeAt'] ?? 0) >= $noticeAt1);

/* ======================================================================= *
 * 6. An 'images' entry that lands straight on the state file in the gap
 *    between this pass's own first read and its final save survives —
 *    proving the fix for the lost-update bug (PLAN_205 fix 3), not just
 *    asserting it. staxx_update_state_cache() primes this process with a
 *    stale snapshot, exactly what the pass's own first read would see; a
 *    NEWER copy of the state file, carrying one extra 'images' entry no
 *    fixture stack ever produced, is then written straight to disk, as a
 *    concurrent check pass's own write would land. The pass can only see
 *    that entry in its own save by actually re-reading the state file
 *    under lock, not by trusting the stale snapshot it started from.
 * ======================================================================= */
$raceImage = 'ghcr.io/example/other-writer:1';

$stale = staxx_update_state();
$stale['pinnedWalkAt'] = time() - ($day + 5); // let this pass's own walk run
staxx_update_state_cache($stale);

$newer = $stale;
$newer['images'][$raceImage]['remote'] = 'sha256:'.hash('sha256', 'other-writer');
$newer['checked'] = time();
file_put_contents($scratch, json_encode($newer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

@unlink($notifyLog);
staxx_update_pinned_reminder_pass();

$state = staxx_update_state();
ok('an images entry written straight to disk before this pass\'s save survives',
   ($state['images'][$raceImage]['remote'] ?? '') === 'sha256:'.hash('sha256', 'other-writer'));
ok('this pass\'s own pinnedSince work still landed alongside it',
   isset($state['images'][$cacheImage]['pinnedSince']));

/* ---------------------------------------------------------------------- */

pr_wipe();

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
