<?php
/* StaXX — watching running apps (PLAN_221, include/AppWatch.php): the pure
 * comparison of two snapshots (restart loops, health checks, stops by
 * themselves, vanished containers, the first-run baseline), the stack lookup
 * by working folder, and one whole pass against a fake docker.
 *
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. Needs STORE_ROOT
 * pointed at a scratch store, via tests/server/run-with-store.sh:
 *
 *     pscp tests/server/run-with-store.sh tests/server/appwatch.php root@<box>:/tmp/
 *     plink … 'bash /tmp/run-with-store.sh /tmp/staxx-appwatch-store /tmp/appwatch.php'
 *
 * Nothing real is touched: docker is a stub script (STAXX_DOCKER_BIN), the
 * state file is under /tmp (STAXX_APPWATCH_FILE), and no notification can
 * leave — STAXX_NOTIFY_BIN is a recording stub, STAXX_MAIL_FILE catches the
 * HTML email, and the digest, notify script, dynamix.cfg and clock are
 * scratch values, all set before anything is loaded.
 */

$dir = '/tmp/staxx-appwatch-test';
@exec('rm -rf '.escapeshellarg($dir));
mkdir($dir, 0755, true);
$log = $dir.'/calls.log';
$stub = $dir.'/notify-stub.sh';
file_put_contents($stub, "#!/bin/sh\nprintf 'CALL\\0' >> ".escapeshellarg($log)."\nprintf '%s\\0' \"\$@\" >> ".escapeshellarg($log)."\n");
chmod($stub, 0755);
file_put_contents($dir.'/script-with', "<?php\n\$entity = \$overrule===false ? \$x : \$overrule;\n");
file_put_contents($dir.'/dynamix.cfg', "[notify]\nnormal=\"7\"\nwarning=\"7\"\nalert=\"7\"\n");
mkdir($dir.'/var', 0755, true);
file_put_contents($dir.'/var/var.ini', "NAME=\"Tower\"\n");
file_put_contents($dir.'/var/nginx.ini', "NGINX_DEFAULTURL=\"http://tower.test\"\n");
putenv('STAXX_NOTIFY_BIN='.$stub);
putenv('STAXX_MAIL_FILE='.$dir.'/mail.eml');
putenv('STAXX_NOTIFY_VARDIR='.$dir.'/var');
putenv('STAXX_NOTIFY_DIGEST='.$dir.'/digest.json');
putenv('STAXX_DYNAMIX_CFG='.$dir.'/dynamix.cfg');
putenv('STAXX_NOTIFY_SCRIPT='.$dir.'/script-with');
putenv('STAXX_NOTIFY_AGENTS='.$dir.'/agents');
putenv('STAXX_NOW='.strtotime('2026-10-03 12:00:00'));
putenv('STAXX_NOTIFY_OPTS=');
putenv('STAXX_APPWATCH_FILE='.$dir.'/appwatch.json');
// Docker-down inputs (PLAN_223): fixtures instead of the real flash config,
// array state and uptime. Booting (under the 10-minute grace) keeps the
// older tests below, which make Docker unreadable, free of any Docker-down record.
putenv('STAXX_APPWATCH_DOCKERCFG='.$dir.'/docker.cfg');
putenv('STAXX_APPWATCH_VARINI='.$dir.'/array-var.ini');
putenv('STAXX_APPWATCH_UPTIME='.$dir.'/uptime');
file_put_contents($dir.'/uptime', "5.00 0.00\n");

// A fake docker: `ps` and `inspect` print canned files; a "fail" file makes it exit 1.
$fake = $dir.'/docker';
file_put_contents($fake, "#!/bin/sh\n[ -e ".escapeshellarg($dir.'/fail')." ] && exit 1\ncase \"\$1\" in\n"
  ."  ps) cat ".escapeshellarg($dir.'/ps.txt')." ;;\n  inspect) cat ".escapeshellarg($dir.'/inspect.txt')." ;;\nesac\n");
chmod($fake, 0755);
putenv('STAXX_DOCKER_BIN='.$fake);

require_once '/usr/local/emhttp/plugins/staxx/include/AppWatch.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, (!$pass && $note !== '') ? '  ('.$note.')' : '');
}
/** One container's snapshot entry; $o overrides any field. */
function c(array $o = []): array {
  return $o + ['name' => 'app', 'dir' => '', 'service' => 'app', 'stack' => 'app', 'status' => 'running',
               'exitCode' => 0, 'oom' => false, 'restarts' => 0, 'health' => '', 'streak' => 0];
}
function kinds(array $events): array { return array_column($events, 'kind'); }
/** Run one comparison; returns [events, newState]. */
function step(array $old, array $now, array $state, int $t, bool $backup = false): array { return staxx_appwatch_events($old, $now, $state, $t, $backup); }

$T = 1000000;

/* ===== restart loop ===== */
$st = []; $old = ['a1' => c()];
// 3 restarts spread over 59 minutes: 1 at T, 1 at T+1740, 1 at T+3540.
[$e, $st] = step($old, ['a1' => c(['restarts' => 1])], $st, $T);          $old = ['a1' => c(['restarts' => 1])];
ok('1 restart is not a loop', $e === []);
[$e, $st] = step($old, ['a1' => c(['restarts' => 2])], $st, $T + 1740);   $old = ['a1' => c(['restarts' => 2])];
ok('2 restarts is not a loop', $e === []);
[$e, $st] = step($old, ['a1' => c(['restarts' => 3])], $st, $T + 3540);   $old = ['a1' => c(['restarts' => 3])];
ok('3 restarts inside 59 minutes reports once, with the count', kinds($e) === ['restarting'] && $e[0]['count'] === 3 && $e[0]['name'] === 'app' && $e[0]['image'] === '', json_encode($e));
[$e, $st] = step($old, ['a1' => c(['restarts' => 4])], $st, $T + 3600);   $old = ['a1' => c(['restarts' => 4])];
ok('a 4th restart in the same hour does not report again', $e === [], json_encode($e));
[$e, $st] = step($old, ['a1' => c(['restarts' => 4])], $st, $T + 3601);
ok('no further rise: still quiet', $e === []);

// 3 spread over 61 minutes never reach 3 inside the hour.
$st = []; $old = ['a1' => c()];
$all = [];
foreach ([[1, 0], [2, 1830], [3, 3660]] as [$n, $dt]) {
  [$e, $st] = step($old, ['a1' => c(['restarts' => $n])], $st, $T + $dt);
  $old = ['a1' => c(['restarts' => $n])];
  $all = array_merge($all, $e);
}
ok('3 restarts over 61 minutes do not report', $all === [], json_encode($all));

// A rise of 3 at once is a loop; and the flag clears once the list drops below 3, so a later loop reports again.
$st = []; $old = ['a1' => c()];
[$e, $st] = step($old, ['a1' => c(['restarts' => 3])], $st, $T);
ok('a rise of 3 in one minute reports', kinds($e) === ['restarting']);
$old = ['a1' => c(['restarts' => 3])];
[$e, $st] = step($old, ['a1' => c(['restarts' => 3])], $st, $T + 3700);
ok('an hour later the entries are pruned and the flag is clear', $e === [] && $st['a1']['restarts'] === [] && $st['a1']['reported']['restarting'] === false, json_encode($st));
$old = ['a1' => c(['restarts' => 3])];
[$e, $st] = step($old, ['a1' => c(['restarts' => 6])], $st, $T + 3760);
ok('a second loop after the flag cleared reports again', kinds($e) === ['restarting']);
// RestartCount going down for the same id is no rise.
[$e, $st2] = step(['a1' => c(['restarts' => 5])], ['a1' => c(['restarts' => 2])], [], $T);
ok('a lower RestartCount is no rise', $e === [] && ($st2['a1']['restarts'] ?? []) === []);

/* ===== stopped by itself ===== */
$run = ['a1' => c()];
[$e] = step($run, ['a1' => c(['status' => 'exited', 'exitCode' => 1])], [], $T);
ok('running to exited with 1 reports stopped, "error code 1"', kinds($e) === ['stopped'] && $e[0]['reason'] === 'It stopped with error code 1.', json_encode($e));
foreach ([0, 137, 143] as $code) {
  [$e] = step($run, ['a1' => c(['status' => 'exited', 'exitCode' => $code])], [], $T);
  ok("exit code $code is not reported", $e === []);
}
[$e] = step($run, ['a1' => c(['status' => 'dead', 'exitCode' => 2])], [], $T);
ok('dead with code 2 is reported too', kinds($e) === ['stopped'] && has_in($e[0]['reason'], 'error code 2'));
function has_in(string $h, string $n): bool { return strpos($h, $n) !== false; }
[$e] = step($run, ['a1' => c(['status' => 'exited', 'exitCode' => 137, 'oom' => true])], [], $T);
ok('OOMKilled reports the memory reason, whatever the code', kinds($e) === ['stopped'] && $e[0]['reason'] === 'It ran out of memory.', json_encode($e));
[$e] = step(['a1' => c(['status' => 'exited', 'exitCode' => 1])], ['a1' => c(['status' => 'exited', 'exitCode' => 1])], [], $T);
ok('already stopped last minute: nothing', $e === []);

/* ===== health ===== */
$st = [];
[$e, $st] = step(['a1' => c(['health' => 'healthy'])], ['a1' => c(['health' => 'unhealthy', 'streak' => 3])], $st, $T);
ok('healthy to unhealthy reports once, count = failing streak', kinds($e) === ['unhealthy'] && $e[0]['count'] === 3, json_encode($e));
[$e, $st] = step(['a1' => c(['health' => 'unhealthy', 'streak' => 3])], ['a1' => c(['health' => 'unhealthy', 'streak' => 4])], $st, $T + 60);
ok('staying unhealthy does not repeat', $e === []);
[$e, $st] = step(['a1' => c(['health' => 'unhealthy'])], ['a1' => c(['health' => 'healthy'])], $st, $T + 120);
ok('unhealthy back to healthy adds a healthy event', kinds($e) === ['healthy'], json_encode($e));
[$e, $st] = step(['a1' => c(['health' => 'healthy'])], ['a1' => c(['health' => 'unhealthy', 'streak' => 3])], $st, $T + 180);
ok('then unhealthy again reports again', kinds($e) === ['unhealthy']);
[$e, $st] = step(['a1' => c(['health' => 'unhealthy'])], ['a1' => c(['health' => 'starting'])], $st, $T + 240);
ok('unhealthy to starting clears the flag without a healthy event', $e === [] && $st['a1']['reported']['unhealthy'] === false);
[$e, $st] = step(['a1' => c(['health' => 'starting'])], ['a1' => c(['health' => 'healthy'])], $st, $T + 300);
ok('healthy after an unreported spell says nothing', $e === []);
// An app with no health check going unhealthy cannot happen, but absent -> unhealthy still counts as a change.
[$e] = step(['a1' => c(['health' => ''])], ['a1' => c(['health' => 'unhealthy', 'streak' => 1])], [], $T);
ok('no health check before counts as "not unhealthy"', kinds($e) === ['unhealthy']);
// A restart while flagged clears the flag, so the app can be reported afresh later.
$st = ['a1' => ['restarts' => [], 'reported' => ['restarting' => false, 'unhealthy' => true]]];
[$e, $st] = step(['a1' => c(['health' => 'unhealthy'])], ['a1' => c(['health' => 'unhealthy', 'restarts' => 1])], $st, $T);
ok('a restart clears the unhealthy flag', $st['a1']['reported']['unhealthy'] === false && $e === []);

/* ===== Appdata Backup (PLAN_229) ===== */
// A stopped container with a health check reads as unhealthy to Docker; that is not a failure.
[$e, $st] = step(['a1' => c(['health' => 'healthy'])], ['a1' => c(['status' => 'exited', 'health' => 'unhealthy', 'streak' => 3])], [], $T);
ok('a stopped container reading unhealthy gives no health event', !in_array('unhealthy', kinds($e), true), json_encode($e));
$st = ['a1' => ['restarts' => [], 'reported' => ['restarting' => false, 'unhealthy' => true]]];
[$e, $st] = step(['a1' => c(['health' => 'unhealthy'])], ['a1' => c(['status' => 'exited', 'health' => 'healthy'])], $st, $T);
ok('a stopped container keeps its unhealthy flag and gives no healthy event', $e === [] && $st['a1']['reported']['unhealthy'] === true, json_encode([$e, $st]));
[$e] = step(['a1' => c(['health' => 'healthy'])], ['a1' => c(['health' => 'unhealthy', 'streak' => 3])], [], $T);
ok('a running container going unhealthy is still reported', kinds($e) === ['unhealthy']);
$stopped = ['a1' => c(['status' => 'exited', 'exitCode' => 2])];
[$e] = step($run, $stopped, [], $T, true);
ok('error-code stop while the backup runs: nothing', $e === [], json_encode($e));
[$e] = step($run, $stopped, [], $T, false);
ok('same stop with no backup running is reported', kinds($e) === ['stopped']);
[$e] = step($run, ['a1' => c(['status' => 'exited', 'exitCode' => 1, 'oom' => true])], [], $T, true);
ok('an out-of-memory stop is silent too while the backup runs', $e === []);

$bk = $dir.'/backup-running';
putenv('STAXX_APPWATCH_BACKUP='.$bk);
@unlink($bk);
ok('backup marker missing: not running', staxx_appwatch_backup_running() === false);
file_put_contents($bk, (string)getmypid());
ok('backup marker with a live PID: running', staxx_appwatch_backup_running() === true);
file_put_contents($bk, "999999999\n");
ok('backup marker with a dead PID: not running', staxx_appwatch_backup_running() === false);
file_put_contents($bk, 'not-a-pid');
ok('backup marker that is not a number: not running', staxx_appwatch_backup_running() === false);
@unlink($bk);
putenv('STAXX_APPWATCH_BACKUP');   // unset, so the whole-pass tests below never see a marker

/* ===== gone, first run, new containers ===== */
[$e, $st] = step(['a1' => c(), 'b2' => c(['name' => 'b'])], ['a1' => c()], ['b2' => ['restarts' => [$T], 'reported' => []]], $T);
ok('a container gone from the snapshot is dropped from state, no event', $e === [] && !isset($st['b2']) && isset($st['a1']));
[$e, $st] = step([], ['a1' => c(['status' => 'exited', 'exitCode' => 1, 'restarts' => 9, 'health' => 'unhealthy'])], [], $T);
ok('empty old snapshot (first run) gives no events, only a baseline', $e === [] && isset($st['a1']));
[$e, $st] = step(['a1' => c(['status' => 'exited', 'health' => 'unhealthy'])], ['a1' => c(['status' => 'exited', 'health' => 'unhealthy'])], $st, $T + 60);
ok('a container unhealthy at the baseline is not reported afterwards', $e === []);
[$e] = step(['a1' => c()], ['a1' => c(), 'n3' => c(['name' => 'new', 'status' => 'exited', 'exitCode' => 1])], [], $T);
ok('a container new since last minute is not "stopped"', $e === []);
[$e] = step(['a1' => c()], ['a1' => c(['stack' => 'media/jf', 'service' => 'jellyfin', 'name' => 'jf-1', 'status' => 'exited', 'exitCode' => 1])], [], $T);
ok('events carry stack, service and name', ($e[0]['stack'] ?? '') === 'media/jf' && $e[0]['service'] === 'jellyfin' && $e[0]['name'] === 'jf-1');

/* ===== stack lookup ===== */
$map = ['jellyfin' => '/mnt/s/jellyfin/compose.yaml', 'Media/sonarr' => '/mnt/s/Media/sonarr/compose.yaml', 'empty' => ''];
ok('working folder finds its stack', staxx_appwatch_stack_for('/mnt/s/Media/sonarr', $map) === 'Media/sonarr');
ok('a trailing slash is ignored', staxx_appwatch_stack_for('/mnt/s/jellyfin/', $map) === 'jellyfin');
ok('a folder no stack owns gives ""', staxx_appwatch_stack_for('/opt/other', $map) === '');
ok('no working folder gives ""', staxx_appwatch_stack_for('', $map) === '');
ok('a parent folder is not a match', staxx_appwatch_stack_for('/mnt/s', $map) === '');

/* ===== one whole pass against the fake docker ===== */
function fake(array $rows): void {
  global $dir;
  $ids = [];
  $lines = '';
  foreach ($rows as $id => $r) {
    $ids[] = $id;
    $lines .= implode("\t", [$id, '/'.$r['name'], $r['dir'] ?? '', $r['service'] ?? '', $r['status'], $r['exit'] ?? 0,
                             !empty($r['oom']) ? 'true' : 'false', $r['restarts'] ?? 0, $r['health'] ?? '', $r['streak'] ?? 0])."\n";
  }
  file_put_contents($dir.'/ps.txt', implode("\n", $ids)."\n");
  file_put_contents($dir.'/inspect.txt', $lines);
}
function saved(): array { return json_decode((string)@file_get_contents(staxx_appwatch_file()), true) ?: []; }
function calls_n(): int { global $log; return is_file($log) ? substr_count((string)file_get_contents($log), "CALL\0") : 0; }

$ff = staxx_appwatch_file();
fake(['aa11' => ['name' => 'web', 'status' => 'running', 'health' => 'healthy']]);
staxx_appwatch_pass();
$s = saved();
ok('pass 1 writes the baseline: snapshot with the container, name without the slash', ($s['snapshot']['aa11']['name'] ?? '') === 'web' && isset($s['state']['aa11']), json_encode($s));
ok('pass 1 sends nothing', calls_n() === 0);

touch($dir.'/fail');
fake(['aa11' => ['name' => 'web', 'status' => 'exited', 'exit' => 1]]);
staxx_appwatch_pass();
ok('Docker unreadable: state file left exactly as it was', saved() === $s);
unlink($dir.'/fail');

$lock = fopen($ff.'.lock', 'c'); flock($lock, LOCK_EX | LOCK_NB);
staxx_appwatch_pass();
ok('lock held by another pass: this one leaves without touching the file', saved() === $s);
flock($lock, LOCK_UN); fclose($lock);

staxx_appwatch_pass();
$s2 = saved();
ok('pass 2 saves the new snapshot', ($s2['snapshot']['aa11']['status'] ?? '') === 'exited' && ($s2['snapshot']['aa11']['exitCode'] ?? 0) === 1, json_encode($s2));
if (function_exists('staxx_notify_text') && strpos((string)@file_get_contents('/usr/local/emhttp/plugins/staxx/include/Notify.php'), "'stopped'") !== false) {
  ok('pass 2 hands the stopped event to the notifier', calls_n() >= 1 || is_file($dir.'/digest.json'), 'no call and no digest');
} else {
  echo "skip   delivery: Notify.php does not know the app-watching kinds yet\n";
}
staxx_appwatch_pass();
$n1 = calls_n();
ok('pass 3 with nothing new adds no further message', calls_n() === $n1);

/* ===== Docker itself down (PLAN_223): the step ===== */
function dstep(array $rec, bool $ans, bool $en, bool $arr, int $up, int $t): array { return staxx_appwatch_docker_step($rec, $ans, $en, $arr, $up, $t); }
$UP = 100000;   // well past the boot grace
$D = $T;
[$e, $r] = dstep([], true, true, true, $UP, $D);
ok('answering with no record gives nothing', $e === [] && $r === []);
[$e, $r] = dstep(['downSince' => $D, 'reported' => false], false, false, true, $UP, $D + 400);
ok('switched off gives nothing and clears the record', $e === [] && $r === [], json_encode([$e, $r]));
[$e, $r] = dstep(['downSince' => $D, 'reported' => true], false, true, false, $UP, $D + 400);
ok('array stopped gives nothing and clears the record', $e === [] && $r === []);
[$e, $r] = dstep([], false, true, true, 599, $D);
ok('uptime under 10 minutes gives nothing and starts no clock', $e === [] && $r === []);
[$e, $r] = dstep([], false, true, true, 600, $D);
ok('uptime of exactly 10 minutes starts the clock', $e === [] && $r === ['downSince' => $D, 'reported' => false], json_encode($r));
[$e, $r] = dstep($r, false, true, true, $UP, $D + 240);
ok('down 4 minutes gives nothing and keeps the record', $e === [] && $r === ['downSince' => $D, 'reported' => false], json_encode($r));
[$e, $r] = dstep($r, false, true, true, $UP, $D + 300);
ok('down 5 minutes gives one dockerdown, count 5, blank app fields',
   kinds($e) === ['dockerdown'] && $e[0]['count'] === 5 && $e[0]['stack'] === '' && $e[0]['service'] === '' && $e[0]['image'] === '' && $e[0]['name'] === '' && $r['reported'] === true, json_encode([$e, $r]));
[$e, $r] = dstep($r, false, true, true, $UP, $D + 360);
ok('the next minute gives nothing (already reported)', $e === [] && $r === ['downSince' => $D, 'reported' => true]);
[$e, $r2] = dstep($r, true, true, true, $UP, $D + 750);
ok('back after a report gives one dockerback with the minutes, and clears the record', kinds($e) === ['dockerback'] && $e[0]['count'] === 12 && $r2 === [], json_encode([$e, $r2]));
[$e, $r2] = dstep(['downSince' => $D, 'reported' => false], true, true, true, $UP, $D + 200);
ok('back before 5 minutes gives nothing and clears the record', $e === [] && $r2 === []);

/* ===== the two readers and the uptime, against fixtures ===== */
@unlink($dir.'/docker.cfg');
ok('docker.cfg missing: Docker counts as on', staxx_appwatch_docker_enabled() === true);
file_put_contents($dir.'/docker.cfg', "DOCKER_IMAGE_SIZE=\"20\"\n");
ok('docker.cfg without the line: on', staxx_appwatch_docker_enabled() === true);
file_put_contents($dir.'/docker.cfg', "DOCKER_ENABLED=\"yes\"\n");
ok('DOCKER_ENABLED="yes": on', staxx_appwatch_docker_enabled() === true);
file_put_contents($dir.'/docker.cfg', "DOCKER_ENABLED=\"no\"\n");
ok('DOCKER_ENABLED="no": off', staxx_appwatch_docker_enabled() === false);
@unlink($dir.'/array-var.ini');
ok('var.ini missing: array counts as started', staxx_appwatch_array_started() === true);
file_put_contents($dir.'/array-var.ini', "NAME=\"Tower\"\n");
ok('var.ini without mdState: started', staxx_appwatch_array_started() === true);
file_put_contents($dir.'/array-var.ini', "mdState=\"STARTED\"\n");
ok('mdState="STARTED": started', staxx_appwatch_array_started() === true);
file_put_contents($dir.'/array-var.ini', "mdState=\"STOPPED\"\n");
ok('mdState="STOPPED": not started', staxx_appwatch_array_started() === false);
file_put_contents($dir.'/uptime', "1234.56 4000.00\n");
ok('uptime reads whole seconds', staxx_appwatch_uptime() === 1234);

/* ===== Docker down across whole passes ===== */
file_put_contents($dir.'/docker.cfg', "DOCKER_ENABLED=\"yes\"\n");
file_put_contents($dir.'/array-var.ini', "mdState=\"STARTED\"\n");
file_put_contents($dir.'/uptime', "100000.00 0.00\n");
$before = saved()['snapshot'] ?? null;
touch($dir.'/fail');
putenv('STAXX_NOW='.$D);
staxx_appwatch_pass();
$s = saved();
ok('pass with Docker unreadable starts the down record and keeps the snapshot', ($s['docker']['downSince'] ?? 0) === $D && $s['docker']['reported'] === false && ($s['snapshot'] ?? null) === $before, json_encode($s));
$c0 = calls_n();
putenv('STAXX_NOW='.($D + 300));
staxx_appwatch_pass();
ok('after 5 minutes the record is marked reported', (saved()['docker']['reported'] ?? false) === true);
if (strpos((string)@file_get_contents('/usr/local/emhttp/plugins/staxx/include/Notify.php'), 'dockerdown') !== false) {
  ok('and one message was handed to the notifier', calls_n() > $c0 || is_file($dir.'/digest.json'));
} else {
  echo "skip   delivery: Notify.php does not know dockerdown yet\n";
}
$c1 = calls_n();
putenv('STAXX_NOW='.($D + 360));
staxx_appwatch_pass();
ok('another unreadable minute sends nothing more', calls_n() === $c1);
unlink($dir.'/fail');
putenv('STAXX_NOW='.($D + 600));
staxx_appwatch_pass();
ok('Docker answering again clears the record and keeps a snapshot', !isset(saved()['docker']) && isset(saved()['snapshot']['aa11']), json_encode(saved()));

@exec('rm -rf '.escapeshellarg($dir));
@unlink($ff); @unlink($ff.'.lock'); @unlink($ff.'.tmp');
echo $fails === 0 ? "\nAll passed.\n" : "\n$fails FAILED.\n";
exit($fails === 0 ? 0 : 1);
