<?php
/* PLAN_44 phase 0's server half: the `recreate` and stack-scope `update`
 * verbs, the scope-refusal rule that already protected `config` and
 * `remove`, and the offset-tailing job log reader.
 *
 * PLAN_44 phase 3 adds the log pane's follower — staxx_log_start(),
 * staxx_log_read(), staxx_log_stop() and staxx_log_reap() — covered further
 * down under "log follower".
 *
 * PLAN_44 phase 4 adds the shell — staxx_exec_start(), staxx_exec_write(),
 * staxx_exec_read(), staxx_exec_stop() and staxx_exec_reap() — covered
 * further down under "exec". Nothing there ever opens a real session either:
 * every staxx_exec_start() call below is refused before it ever calls
 * exec(), and the offset/heartbeat/reap cases only ever touch fake session
 * files this script writes by hand under STAXX_EXEC_DIR, with a pid that is
 * never a real process. The one exception, by design, is the "does not
 * appear to be running" case, which asks real docker/compose read-only
 * questions about a stack that was deliberately never started — see the
 * comment beside it.
 *
 * PLAN_44 phase 5 adds the container file manager — staxx_cfile_list(),
 * staxx_cfile_read(), staxx_cfile_write(), staxx_cfile_rename(),
 * staxx_cfile_delete(), staxx_cfile_mkdir() and staxx_cfile_home() —
 * covered further down under "container files". Every one of them calls
 * staxx_cfile_container() first, which shares staxx_exec_start()'s own
 * refusal chain, so the same never-really-runs-docker guarantee applies:
 * each case below is refused at path validation or at that shared chain
 * before any staxx_sh() call is built. The command-builder helpers
 * (staxx_cfile_ls_cmd() and friends) are asserted on as plain strings,
 * never executed, which is what proves the `--`-before-path and
 * escapeshellarg() rules without touching a container at all.
 *
 * PLAN_44 phase 6 adds staxx_cstat() and staxx_cenv() — the restart-count/
 * health line above the panes and the "show the environment" job, covered
 * further down under "PLAN_44 D6". Both call staxx_cfile_container() first,
 * so the same never-really-runs-docker guarantee applies to every case below
 * except the "not running" one, which only asks read-only docker/compose
 * questions about a stack deliberately never started.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine.
 *
 *     pscp tests/server/console.php root@<box>:/tmp/
 *     plink … "php /tmp/console.php"
 *
 * Prints one line per case and exits non-zero on any failure.
 *
 * MUST NEVER RUN DOCKER. Every staxx_start_job() call here is made against a
 * stack name that is never created on disk, so it always refuses at either
 * the scope check or the "no compose file" check — both come before
 * anything is built or exec()'d, so nothing here ever reaches a shell. The
 * offset-reader cases only ever touch fake log files this script writes by
 * hand under STAXX_JOB_DIR, never a real job. The same is true of every
 * staxx_log_start() call below: each one is refused before staxx_log_start()
 * ever calls exec(), either for an invalid path, a review lock, or a service
 * name that is not a member of a real, on-disk-but-never-started compose
 * file — never far enough to detach a follower. The reap cases only ever
 * touch fake follower files this script writes by hand under STAXX_LOG_DIR,
 * with a pid that is never a real process. */

require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

$none = 'zzc2none'; // deliberately never created, same trick as review.php's $noneRel

/* ------------------------------------------------------- verb table ---- */

$verbs = staxx_job_verbs();

ok('recreate has a stack-scope form',
   ($verbs['recreate']['args'] ?? null) === 'up -d --force-recreate --remove-orphans');
ok('recreate has a service-scope form',
   ($verbs['recreate']['svc'] ?? null) === 'up -d --force-recreate');
ok('update now has a stack-scope form',
   ($verbs['update']['args'] ?? null) === ['pull', 'up -d --remove-orphans']);
ok('update keeps its service-scope form',
   ($verbs['update']['svc'] ?? null) === ['pull', 'up -d']);

/* ------------------------------------------------------ scope refusals -- */
//
// A stack that does not exist on disk still passes through the scope check
// before staxx_start_job() ever looks at the disk — see the comment beside
// that check in Stacks.php. So a verb refused at this scope reports the
// scope sentence; a verb accepted at this scope instead gets past it and is
// refused for the next reason in line, "no compose file found", proving the
// scope gate let it through.

$err = '';
$r = staxx_start_job($none, 'config', $err, 'a');
ok('config still refuses a service scope', $r === '' && stripos($err, 'single container') !== false, $err);

$err = '';
$r = staxx_start_job($none, 'remove', $err, '');
ok('remove still refuses a stack scope', $r === '' && stripos($err, 'whole stack') !== false, $err);

foreach (['recreate', 'update'] as $verb) {
  foreach (['' => 'whole stack', 'a' => 'service'] as $service => $label) {
    $err = '';
    $r = staxx_start_job($none, $verb, $err, $service);
    ok("$verb is accepted at $label scope",
       $r === '' && stripos($err, 'no compose file') !== false, $err);
  }
}

/* ------------------------------------------------ unknown verb first --- */

$err = '';
$r = staxx_start_job($none, 'nonsense', $err, '');
ok('an unknown verb is refused before the stack is even looked at',
   $r === '' && $err === 'Unknown action.', $err);

$err = '';
$r = staxx_start_job('a/b/c', 'nonsense', $err, '');
ok('an unknown verb wins even over an invalid stack name',
   $r === '' && $err === 'Unknown action.', $err);

/* -------------------------------------------------------- offset reader -- */

if (!is_dir(STAXX_JOB_DIR)) mkdir(STAXX_JOB_DIR, 0755, true);

$job  = bin2hex(random_bytes(8));
$log  = STAXX_JOB_DIR.'/'.$job.'.log';

file_put_contents($log, "hello\n");
$size1 = filesize($log);

$r1 = staxx_job_log($job, 0);
ok('reading from 0 returns everything', $r1['text'] === "hello\n");
ok('…and the new offset is the file size', $r1['offset'] === $size1);
ok('…and it is not done yet', $r1['done'] === false && $r1['exit'] === null);

$r2 = staxx_job_log($job, $r1['offset']);
ok('reading again from that offset returns nothing new', $r2['text'] === '' && $r2['offset'] === $size1);

file_put_contents($log, " world\n", FILE_APPEND);
$size2 = filesize($log);

$r3 = staxx_job_log($job, $r1['offset']);
ok('reading from the remembered offset returns only the new bytes', $r3['text'] === " world\n");
ok('…with its leading whitespace intact', $r3['text'][0] === ' ');
ok('…and the offset now matches the new file size', $r3['offset'] === $size2);

$sentinel = STAXX_JOB_END.' 0'."\n";
file_put_contents($log, $sentinel, FILE_APPEND);
$size3 = filesize($log);

$r4 = staxx_job_log($job, $r3['offset']);
ok('the sentinel chunk reports done', $r4['done'] === true);
ok('…with exit code 0', $r4['exit'] === 0);
ok('…and the sentinel itself is stripped from the text', $r4['text'] === '');
ok('…and the offset covers the sentinel, measured before it was stripped', $r4['offset'] === $size3);

$r5 = staxx_job_log($job, $r4['offset']);
ok('polling again after a finish does not re-serve the tail',
   $r5['text'] === '' && $r5['offset'] === $size3);

// A non-zero exit code, on its own fake log.
$job2 = bin2hex(random_bytes(8));
$log2 = STAXX_JOB_DIR.'/'.$job2.'.log';
file_put_contents($log2, "went wrong\n".STAXX_JOB_END.' 137'."\n");
$r6 = staxx_job_log($job2, 0);
ok('a non-zero exit code is reported as-is', $r6['done'] === true && $r6['exit'] === 137);

@unlink($log);
@unlink($log2);

/* ---------------------------------------------------- job id validation -- */

$bad = [
  '15 characters'         => str_repeat('a', 15),
  '17 characters'         => str_repeat('a', 17),
  'uppercase hex'         => str_repeat('A', 16),
  'path traversal'        => '../../etc/passwd',
];
foreach ($bad as $label => $id) {
  $r = staxx_job_log($id, 0);
  ok("a malformed job id ($label) is refused rather than read",
     $r === ['text' => '', 'offset' => 0, 'done' => true, 'exit' => null], json_encode($r));
}

// An unknown but well-shaped id — never written above — must refuse the
// same way, not error, since a pruned or mistyped id is the common case.
$r = staxx_job_log(bin2hex(random_bytes(8)), 0);
ok('an unknown but well-shaped job id is refused the same way',
   $r === ['text' => '', 'offset' => 0, 'done' => true, 'exit' => null], json_encode($r));

/* ========================================================= log follower === */

$root = staxx_stack_root();

/* ------------------------------------------------- staxx_log_start refusals -- */

$err = '';
$r = staxx_log_start('a/b/c', '', $err);
ok('an invalid stack path is refused', $r === '' && stripos($err, 'invalid') !== false, $err);

// A locked stack, the same fixture shape as review.php: a real folder with a
// compose file and the review-lock marker beside it, but never started —
// the lock refuses before compose or docker is even asked about, so this
// never reaches exec() either.
$lockedRel = 'zzc2locked';
$lockedDir = $root.'/'.$lockedRel;
@exec('rm -rf '.escapeshellarg($lockedDir));
mkdir($lockedDir, 0755, true);
file_put_contents($lockedDir.'/compose.yaml', "services:\n  a:\n    image: alpine:3.20\n");
file_put_contents($lockedDir.'/'.STAXX_REVIEW_FILE, "imported\n");

$err = '';
$r = staxx_log_start($lockedRel, '', $err);
ok('a review-locked stack is refused', $r === '' && stripos($err, 'review') !== false, $err);
$err = '';
$r = staxx_log_start($lockedRel, 'a', $err);
ok('...at single-service scope too', $r === '' && stripos($err, 'review') !== false, $err);

// An unlocked stack whose compose file really exists but names no such
// service — this is the one case that has to get past the review, compose
// and docker checks to prove the membership rule itself, but a refused
// service name means staxx_log_start() returns before ever building a
// command, so nothing here is exec()'d.
$plainRel = 'zzc2plain';
$plainDir = $root.'/'.$plainRel;
@exec('rm -rf '.escapeshellarg($plainDir));
mkdir($plainDir, 0755, true);
file_put_contents($plainDir.'/compose.yaml', "services:\n  a:\n    image: alpine:3.20\n");

$err = '';
$r = staxx_log_start($plainRel, 'nosuchservice', $err);
ok('a service not in the compose file is refused',
   $r === '' && stripos($err, 'no service called') !== false, $err);

@exec('rm -rf '.escapeshellarg($lockedDir));
@exec('rm -rf '.escapeshellarg($plainDir));

/* ------------------------------------------------------- follower id shape -- */

$badIds = [
  '15 characters'  => str_repeat('a', 15),
  '17 characters'  => str_repeat('a', 17),
  'uppercase hex'  => str_repeat('A', 16),
  'path traversal' => '../../etc/passwd',
];
foreach ($badIds as $label => $id) {
  $r = staxx_log_read($id, 0);
  ok("a malformed follower id ($label) is refused before anything is read",
     $r === ['text' => '', 'offset' => 0, 'alive' => false], json_encode($r));
}

$r = staxx_log_read(bin2hex(random_bytes(8)), 0);
ok('an unknown but well-shaped follower id is refused the same way',
   $r === ['text' => '', 'offset' => 0, 'alive' => false], json_encode($r));

/* --------------------------------------------------- offset reader (fake) -- */

if (!is_dir(STAXX_LOG_DIR)) mkdir(STAXX_LOG_DIR, 0755, true);

$fid  = bin2hex(random_bytes(8));
$flog = STAXX_LOG_DIR.'/'.$fid.'.log';
$fhb  = STAXX_LOG_DIR.'/'.$fid.'.hb';

file_put_contents($flog, "line one\n");
$size1 = filesize($flog);

$hbBefore = time() - 30; // deliberately stale-ish, so the touch below is provable
file_put_contents($fhb, (string)$hbBefore);

$fr1 = staxx_log_read($fid, 0);
ok('reading from 0 returns everything', $fr1['text'] === "line one\n");
ok('…and the new offset is the file size', $fr1['offset'] === $size1);
ok('…and the follower with no pid file yet reads as alive', $fr1['alive'] === true);

$hbAfterFirstRead = (int)trim((string)file_get_contents($fhb));
ok('reading touched the heartbeat', $hbAfterFirstRead > $hbBefore);

$fr2 = staxx_log_read($fid, $fr1['offset']);
ok('reading again from that offset returns nothing new',
   $fr2['text'] === '' && $fr2['offset'] === $size1);

file_put_contents($flog, " line two\n", FILE_APPEND);
$size2 = filesize($flog);

$fr3 = staxx_log_read($fid, $fr1['offset']);
ok('reading from the remembered offset returns only the new bytes', $fr3['text'] === " line two\n");
ok('…with its leading whitespace intact', $fr3['text'][0] === ' ');
ok('…and the offset now matches the new file size', $fr3['offset'] === $size2);

@unlink($flog);
@unlink($fhb);

/* --------------------------------------------------------- staxx_log_reap -- */

// A "stale" fixture: heartbeat older than STAXX_LOG_STALE, and a pid that is
// never a real process — /proc/<pid> will not exist for it, so
// staxx_log_kill() returns at its very first check and no kill() is ever
// attempted. This is what makes it safe to reap without going near Docker.
$staleId  = bin2hex(random_bytes(8));
$staleLog = STAXX_LOG_DIR.'/'.$staleId.'.log';
$stalePid = STAXX_LOG_DIR.'/'.$staleId.'.pid';
$staleHb  = STAXX_LOG_DIR.'/'.$staleId.'.hb';
file_put_contents($staleLog, "old output\n");
file_put_contents($stalePid, '999999999'); // not a real pid on any Linux box
file_put_contents($staleHb, (string)(time() - STAXX_LOG_STALE - 5));

// A "fresh" fixture, heartbeat well within the stale window, that a reap
// must leave alone.
$freshId  = bin2hex(random_bytes(8));
$freshLog = STAXX_LOG_DIR.'/'.$freshId.'.log';
$freshHb  = STAXX_LOG_DIR.'/'.$freshId.'.hb';
file_put_contents($freshLog, "still going\n");
file_put_contents($freshHb, (string)time());

staxx_log_reap();

ok('a stale follower\'s log is removed', !is_file($staleLog));
ok('…and its pid file is removed', !is_file($stalePid));
ok('…and its heartbeat file is removed', !is_file($staleHb));
ok('a fresh follower\'s log is left alone', is_file($freshLog));
ok('…and its heartbeat is left alone', is_file($freshHb));

@unlink($freshLog);
@unlink($freshHb);
@unlink($staleLog);
@unlink($stalePid);
@unlink($staleHb);

/* ================================================================ exec === */

$root = staxx_stack_root();

/* --------------------------------------------------- staxx_exec_start refusals -- */

$err = '';
$r = staxx_exec_start('a/b/c', 'a', $err);
ok('an invalid stack path is refused', $r === '' && stripos($err, 'invalid') !== false, $err);

// staxx_cfg() caches its answer for the life of this one PHP process, so the
// off state cannot be flipped and re-checked within this same run — the same
// reason links.php/override.php/takeover.php each need their own dedicated
// invocation with STACK_ROOT sed-replaced beforehand. Whichever way this box
// is currently configured, the assertion below matches it; to prove the
// *other* branch, edit the real cfg first:
//
//     sed -i 's#^SHELL_ENABLED=.*#SHELL_ENABLED="false"#' $CFG
//     php console.php
//     sed -i 's#^SHELL_ENABLED=.*#SHELL_ENABLED="true"#'  $CFG
$err = '';
$r = staxx_exec_start($none, 'a', $err);
if (staxx_cfg_bool('SHELL_ENABLED')) {
  ok('SHELL_ENABLED=true lets a request through to the next check',
     $r === '' && stripos($err, 'turned off') === false, $err);
} else {
  ok('SHELL_ENABLED=false refuses, and says so in words',
     $r === '' && stripos($err, 'turned off') !== false, $err);
}

// A locked stack, the same fixture shape review.php and the log follower
// tests above use: a real folder with a compose file and the review-lock
// marker beside it, never started — the lock refuses before compose or
// docker is even asked about, so this never reaches exec() either.
$xLockedRel = 'zzc2xlocked';
$xLockedDir = $root.'/'.$xLockedRel;
@exec('rm -rf '.escapeshellarg($xLockedDir));
mkdir($xLockedDir, 0755, true);
file_put_contents($xLockedDir.'/compose.yaml', "services:\n  a:\n    image: alpine:3.20\n");
file_put_contents($xLockedDir.'/'.STAXX_REVIEW_FILE, "imported\n");

$err = '';
$r = staxx_exec_start($xLockedRel, 'a', $err);
ok('a review-locked stack is refused', $r === '' && stripos($err, 'review') !== false, $err);

// An unlocked stack whose compose file really exists but names no such
// service — has to get past the review, compose and docker checks to prove
// the membership rule itself, but a refused service name means
// staxx_exec_start() returns before ever resolving a container, so nothing
// here is exec()'d.
$xPlainRel = 'zzc2xplain';
$xPlainDir = $root.'/'.$xPlainRel;
@exec('rm -rf '.escapeshellarg($xPlainDir));
mkdir($xPlainDir, 0755, true);
file_put_contents($xPlainDir.'/compose.yaml', "services:\n  a:\n    image: alpine:3.20\n");

$err = '';
$r = staxx_exec_start($xPlainRel, 'nosuchservice', $err);
ok('a service not in the compose file is refused',
   $r === '' && stripos($err, 'no service called') !== false, $err);

// The same stack, a real member service, but never started — this is the
// one case above that goes as far as a real (read-only) `docker ps` and
// `compose ls`, because there is no way to prove "not running" any earlier.
// Nothing here starts, stops or execs anything. Skipped when SHELL_ENABLED
// is off on this box, since the gate above fires first and the point of
// this case is the *next* refusal down the chain, not that one again.
if (staxx_cfg_bool('SHELL_ENABLED')) {
  $err = '';
  $r = staxx_exec_start($xPlainRel, 'a', $err);
  ok('a stack whose containers are not running is refused',
     $r === '' && stripos($err, 'does not appear to be running') !== false, $err);
}

@exec('rm -rf '.escapeshellarg($xLockedDir));
@exec('rm -rf '.escapeshellarg($xPlainDir));

/* ------------------------------------------------------- session id shape -- */

$badExecIds = [
  '15 characters'  => str_repeat('a', 15),
  '17 characters'  => str_repeat('a', 17),
  'uppercase hex'  => str_repeat('A', 16),
  'path traversal' => '../../etc/passwd',
];
foreach ($badExecIds as $label => $id) {
  $err = '';
  $rw = staxx_exec_write($id, 'x', $err);
  ok("a malformed session id ($label) is refused by write before any path is built",
     $rw === false && stripos($err, 'invalid') !== false, $err);

  $rr = staxx_exec_read($id, 0);
  ok("a malformed session id ($label) is refused by read before any path is built",
     $rr === ['text' => '', 'offset' => 0, 'alive' => false], json_encode($rr));
}

$err = '';
$rw = staxx_exec_write(bin2hex(random_bytes(8)), 'x', $err);
ok('write refuses an unknown but well-shaped session id',
   $rw === false && stripos($err, 'ended') !== false, $err);

$rr = staxx_exec_read(bin2hex(random_bytes(8)), 0);
ok('read refuses an unknown but well-shaped session id the same way',
   $rr === ['text' => '', 'offset' => 0, 'alive' => false], json_encode($rr));

/* -------------------------------------------------------- the write cap -- */

if (!is_dir(STAXX_EXEC_DIR)) mkdir(STAXX_EXEC_DIR, 0755, true);

$capId  = bin2hex(random_bytes(8));
$capDir = STAXX_EXEC_DIR.'/'.$capId;
mkdir($capDir, 0700, true);
// A plain file stands in for the fifo — fopen()'s append mode does not care
// which it is, and it is far easier to assert against than a real pipe. No
// pid file is written, so staxx_exec_alive() reads this session as alive —
// see its own doc comment for why a missing pid file means "still starting"
// rather than "gone".
file_put_contents($capDir.'/fifo', '');

$err = '';
$rw = staxx_exec_write($capId, str_repeat('x', STAXX_EXEC_WRITE_MAX + 1), $err);
ok('a write over the cap is refused', $rw === false && stripos($err, 'smaller') !== false, $err);
ok('...and nothing was written', filesize($capDir.'/fifo') === 0);

/* --------------------------------------------- bytes reach the pipe as-is -- */
//
// The one case that matters most: nothing here is ever handed to a shell, so
// a payload full of shell metacharacters must land byte for byte, proving
// nothing interpolated it.

$mischief = "hello `whoami` \$(id) ; rm -rf / \n it's \"quoted\"\n";
$err = '';
$rw = staxx_exec_write($capId, $mischief, $err);
ok('a write within the cap succeeds', $rw === true, $err);
ok('...and the exact bytes reach the pipe, untouched',
   file_get_contents($capDir.'/fifo') === $mischief);

@exec('rm -rf '.escapeshellarg($capDir));

/* --------------------------------------------------- offset reader (fake) -- */

$fid  = bin2hex(random_bytes(8));
$fDir = STAXX_EXEC_DIR.'/'.$fid;
mkdir($fDir, 0700, true);
$fOut = $fDir.'/out';
$fHb  = $fDir.'/hb';

file_put_contents($fOut, "line one\n");
$size1 = filesize($fOut);

$hbBefore = time() - 30; // deliberately stale-ish, so the touch below is provable
file_put_contents($fHb, (string)$hbBefore);

$fr1 = staxx_exec_read($fid, 0);
ok('reading from 0 returns everything', $fr1['text'] === "line one\n");
ok('…and the new offset is the file size', $fr1['offset'] === $size1);
ok('…and a session with no pid file yet reads as alive', $fr1['alive'] === true);

$hbAfterFirstRead = (int)trim((string)file_get_contents($fHb));
ok('reading touched the heartbeat', $hbAfterFirstRead > $hbBefore);

$fr2 = staxx_exec_read($fid, $fr1['offset']);
ok('reading again from that offset returns nothing new',
   $fr2['text'] === '' && $fr2['offset'] === $size1);

file_put_contents($fOut, " line two\n", FILE_APPEND);
$size2 = filesize($fOut);

$fr3 = staxx_exec_read($fid, $fr1['offset']);
ok('reading from the remembered offset returns only the new bytes', $fr3['text'] === " line two\n");
ok('…with its leading whitespace intact', $fr3['text'][0] === ' ');
ok('…and the offset now matches the new file size', $fr3['offset'] === $size2);

@exec('rm -rf '.escapeshellarg($fDir));

/* --------------------------------------------------------- staxx_exec_reap -- */

// A "stale" fixture: heartbeat older than STAXX_LOG_STALE, and a pid that is
// never a real process — /proc/<pid> will not exist for it, so
// staxx_exec_kill() returns at its very first check and no kill() is ever
// attempted. This is what makes it safe to reap without going near Docker.
$staleId  = bin2hex(random_bytes(8));
$staleDir = STAXX_EXEC_DIR.'/'.$staleId;
mkdir($staleDir, 0700, true);
file_put_contents($staleDir.'/out', "old output\n");
file_put_contents($staleDir.'/pid', '999999999'); // not a real pid on any Linux box
file_put_contents($staleDir.'/hb',  (string)(time() - STAXX_LOG_STALE - 5));

// A "fresh" fixture, heartbeat well within the stale window, that a reap
// must leave alone.
$freshId  = bin2hex(random_bytes(8));
$freshDir = STAXX_EXEC_DIR.'/'.$freshId;
mkdir($freshDir, 0700, true);
file_put_contents($freshDir.'/out', "still going\n");
file_put_contents($freshDir.'/hb',  (string)time());

staxx_exec_reap();

ok('a stale session\'s whole folder is removed', !is_dir($staleDir));
ok('a fresh session\'s folder is left alone', is_dir($freshDir));
ok('…and its heartbeat is left alone', is_file($freshDir.'/hb'));

@exec('rm -rf '.escapeshellarg($staleDir));
@exec('rm -rf '.escapeshellarg($freshDir));

/* ==================================================== container files === */

$root = staxx_stack_root();

/* ------------------------------------------------- path shape refusals -- */

$badPaths = [
  'relative'         => 'etc/passwd',
  'traversal'        => '/mnt/user/../etc/passwd',
  'traversal at end' => '/mnt/user/..',
  'empty'            => '',
];

foreach ($badPaths as $label => $bad) {
  ok("staxx_cfile_valid_path() refuses a $label path", staxx_cfile_valid_path($bad) === false);
}
ok('staxx_cfile_valid_path() accepts a clean absolute path',
   staxx_cfile_valid_path('/config/settings.json') === true);
ok('...and one beginning with a dash, since a dash is only a problem for a flag position',
   staxx_cfile_valid_path('/-oddname') === true);

// Every function checks path shape before it ever asks staxx_cfile_container()
// anything, so a bad path is refused the same way regardless of the stack.
foreach ($badPaths as $label => $bad) {
  $err = '';
  ok("cfile_list refuses a $label path",
     staxx_cfile_list($none, 'a', $bad, $err) === null && stripos($err, 'valid') !== false, $err);

  $err = '';
  ok("cfile_read refuses a $label path",
     staxx_cfile_read($none, 'a', $bad, $err) === null && stripos($err, 'valid') !== false, $err);

  $err = '';
  ok("cfile_write refuses a $label path",
     staxx_cfile_write($none, 'a', $bad, 'x', true, $err) === false && stripos($err, 'valid') !== false, $err);

  $err = '';
  ok("cfile_rename refuses a $label path (from)",
     staxx_cfile_rename($none, 'a', $bad, '/tmp/x', $err) === false && stripos($err, 'valid') !== false, $err);
  $err = '';
  ok("cfile_rename refuses a $label path (to)",
     staxx_cfile_rename($none, 'a', '/tmp/x', $bad, $err) === false && stripos($err, 'valid') !== false, $err);

  $err = '';
  ok("cfile_delete refuses a $label path",
     staxx_cfile_delete($none, 'a', $bad, false, $err) === false && stripos($err, 'valid') !== false, $err);

  $err = '';
  ok("cfile_mkdir refuses a $label path",
     staxx_cfile_mkdir($none, 'a', $bad, $err) === false && stripos($err, 'valid') !== false, $err);
}

/* --------------------------------------------------- shared refusal chain -- */
//
// staxx_cfile_container() is exercised directly rather than through all six
// callers again — the same refusal chain staxx_exec_start() already proves
// case-by-case above, shared by every staxx_cfile_*() function.

$err = '';
$r = staxx_cfile_container('a/b/c', 'a', $err);
ok('an invalid stack path is refused', $r === '' && stripos($err, 'invalid') !== false, $err);

$err = '';
$r = staxx_cfile_container($none, 'a', $err);
if (staxx_cfg_bool('SHELL_ENABLED')) {
  ok('SHELL_ENABLED=true lets a request through to the next check',
     $r === '' && stripos($err, 'turned off') === false, $err);
} else {
  ok('SHELL_ENABLED=false refuses, and says so in words',
     $r === '' && stripos($err, 'turned off') !== false, $err);
}

$cLockedRel = 'zzc2clocked';
$cLockedDir = $root.'/'.$cLockedRel;
@exec('rm -rf '.escapeshellarg($cLockedDir));
mkdir($cLockedDir, 0755, true);
file_put_contents($cLockedDir.'/compose.yaml', "services:\n  a:\n    image: alpine:3.20\n");
file_put_contents($cLockedDir.'/'.STAXX_REVIEW_FILE, "imported\n");

$err = '';
$r = staxx_cfile_container($cLockedRel, 'a', $err);
ok('a review-locked stack is refused', $r === '' && stripos($err, 'review') !== false, $err);

$cPlainRel = 'zzc2cplain';
$cPlainDir = $root.'/'.$cPlainRel;
@exec('rm -rf '.escapeshellarg($cPlainDir));
mkdir($cPlainDir, 0755, true);
file_put_contents($cPlainDir.'/compose.yaml', "services:\n  a:\n    image: alpine:3.20\n");

$err = '';
$r = staxx_cfile_container($cPlainRel, 'nosuchservice', $err);
ok('a service not in the compose file is refused',
   $r === '' && stripos($err, 'no service called') !== false, $err);

// The same read-only "not running" proof staxx_exec_start() relies on —
// nothing here starts, stops or execs anything either.
if (staxx_cfg_bool('SHELL_ENABLED')) {
  $err = '';
  $r = staxx_cfile_container($cPlainRel, 'a', $err);
  ok('a stack whose containers are not running is refused',
     $r === '' && stripos($err, 'does not appear to be running') !== false, $err);
}

@exec('rm -rf '.escapeshellarg($cLockedDir));
@exec('rm -rf '.escapeshellarg($cPlainDir));

/* ------------------------------------------------- command builders ---- */
//
// Asserted as plain strings rather than run, which is what proves the
// `--`-before-path rule without ever touching docker. A container name and a
// path each go through their own escapeshellarg(), so a path beginning with
// "-" is quoted data sitting after a literal `--`, never a bare flag.

$docker = staxx_docker_bin();
$dq     = escapeshellarg($docker);

ok('ls command puts -- before the path',
   staxx_cfile_ls_cmd('my-c', '-oddname')
     === $dq.' exec '.escapeshellarg('my-c').' ls -lAn -- '.escapeshellarg('-oddname'));

ok('mv command quotes both paths and separates them from the flags with --',
   staxx_cfile_mv_cmd('my-c', '-from', '-to')
     === $dq.' exec '.escapeshellarg('my-c').' mv -- '.escapeshellarg('-from').' '.escapeshellarg('-to'));

ok('rm command without recurse uses -f, not -rf',
   staxx_cfile_rm_cmd('my-c', '-target', false)
     === $dq.' exec '.escapeshellarg('my-c').' rm -f -- '.escapeshellarg('-target'));
ok('rm command with recurse uses -rf',
   staxx_cfile_rm_cmd('my-c', '-target', true)
     === $dq.' exec '.escapeshellarg('my-c').' rm -rf -- '.escapeshellarg('-target'));

ok('mkdir command puts -- before the path',
   staxx_cfile_mkdir_cmd('my-c', '-newdir')
     === $dq.' exec '.escapeshellarg('my-c').' mkdir -p -- '.escapeshellarg('-newdir'));

ok('docker cp out quotes the container:path pair as one argument',
   staxx_cfile_cp_out_cmd('my-c', '-oddname', '/tmp/x')
     === $dq.' cp -- '.escapeshellarg('my-c:-oddname').' '.escapeshellarg('/tmp/x'));
ok('docker cp in quotes the container:path pair as one argument',
   staxx_cfile_cp_in_cmd('my-c', '/tmp/x', '-oddname')
     === $dq.' cp -- '.escapeshellarg('/tmp/x').' '.escapeshellarg('my-c:-oddname'));

/* -------------------------------------------------- the listing cap ---- */
//
// staxx_cfile_list() itself needs a real container to call ls inside, which
// this script must never do — so only the constant it caps against is
// checked here, the same way the constant's own comment justifies it
// against staxx_browse_dirs()'s cap on the host-folder picker.
ok('the container listing cap exists and is a sane positive number',
   STAXX_CFILE_LIST_MAX > 0 && STAXX_CFILE_LIST_MAX <= 20000);

/* ------------------------------------- text/binary and size cap parity -- */
//
// staxx_cfile_read() reuses staxx_looks_text() and STAXX_FILE_MAX directly
// rather than its own copies, so proving those two constants/functions
// already covered by the plain file tests elsewhere is proving this path's
// contract too. Asserted here directly, since this script has no container
// to copy a real file out of.
ok('the container file size cap is the same constant a stack file uses',
   STAXX_FILE_MAX === 262144);

$textTmp = STAXX_CFILE_TMP;
if (!is_dir($textTmp)) mkdir($textTmp, 0700, true);
$sample = $textTmp.'/zzc2sample.txt';
file_put_contents($sample, "hello\n");
ok('staxx_looks_text() — the same test staxx_cfile_read() defers to — says text is text',
   staxx_looks_text($sample) === true);
file_put_contents($sample, "hello\0world");
ok('...and binary is binary', staxx_looks_text($sample) === false);
@unlink($sample);

/* ======================================================== PLAN_44 D6 === *
 *
 * staxx_cstat() and staxx_cenv() — the restart-count/health line and the
 * "show the environment" job. Both call staxx_cfile_container() first, so
 * they share the never-really-runs-docker guarantee that section's own
 * header already argues for: each case below is refused at path validation
 * or at that shared chain before either ever builds a real command. Only the
 * "not running" case goes as far as a read-only `docker ps`/`compose ls`,
 * for the same reason the exec and cfile sections above each need one too —
 * there is no way to prove "not running" any earlier.
 */

$root = staxx_stack_root();

$err = '';
$r = staxx_cstat('a/b/c', 'a', $err);
ok('cstat refuses an invalid stack path', $r === null && stripos($err, 'invalid') !== false, $err);

$err = '';
$r = staxx_cenv('a/b/c', 'a', $err);
ok('cenv refuses an invalid stack path', $r === null && stripos($err, 'invalid') !== false, $err);

$err = '';
$r = staxx_cstat($none, 'a', $err);
if (staxx_cfg_bool('SHELL_ENABLED')) {
  ok('cstat: SHELL_ENABLED=true lets a request through to the next check',
     $r === null && stripos($err, 'turned off') === false, $err);
} else {
  ok('cstat: SHELL_ENABLED=false refuses, and says so in words',
     $r === null && stripos($err, 'turned off') !== false, $err);
}

$dLockedRel = 'zzc2dlocked';
$dLockedDir = $root.'/'.$dLockedRel;
@exec('rm -rf '.escapeshellarg($dLockedDir));
mkdir($dLockedDir, 0755, true);
file_put_contents($dLockedDir.'/compose.yaml', "services:\n  a:\n    image: alpine:3.20\n");
file_put_contents($dLockedDir.'/'.STAXX_REVIEW_FILE, "imported\n");

$err = '';
$r = staxx_cstat($dLockedRel, 'a', $err);
ok('cstat: a review-locked stack is refused', $r === null && stripos($err, 'review') !== false, $err);

$err = '';
$r = staxx_cenv($dLockedRel, 'a', $err);
ok('cenv: a review-locked stack is refused', $r === null && stripos($err, 'review') !== false, $err);

$dPlainRel = 'zzc2dplain';
$dPlainDir = $root.'/'.$dPlainRel;
@exec('rm -rf '.escapeshellarg($dPlainDir));
mkdir($dPlainDir, 0755, true);
file_put_contents($dPlainDir.'/compose.yaml', "services:\n  a:\n    image: alpine:3.20\n");

$err = '';
$r = staxx_cstat($dPlainRel, 'nosuchservice', $err);
ok('cstat: a service not in the compose file is refused',
   $r === null && stripos($err, 'no service called') !== false, $err);

$err = '';
$r = staxx_cenv($dPlainRel, 'nosuchservice', $err);
ok('cenv: a service not in the compose file is refused',
   $r === null && stripos($err, 'no service called') !== false, $err);

// The same read-only "not running" proof the exec and cfile sections above
// rely on — nothing here starts, stops or execs anything either.
if (staxx_cfg_bool('SHELL_ENABLED')) {
  $err = '';
  $r = staxx_cstat($dPlainRel, 'a', $err);
  ok('cstat: a stack whose containers are not running is refused',
     $r === null && stripos($err, 'does not appear to be running') !== false, $err);

  $err = '';
  $r = staxx_cenv($dPlainRel, 'a', $err);
  ok('cenv: a stack whose containers are not running is refused',
     $r === null && stripos($err, 'does not appear to be running') !== false, $err);
}

@exec('rm -rf '.escapeshellarg($dLockedDir));
@exec('rm -rf '.escapeshellarg($dPlainDir));

/* --------------------------------------------------- command builders ---- */
//
// Asserted as plain strings, never run — same proof as staxx_cfile_ls_cmd()
// and friends above. The one trap worth naming twice: the format flag has
// to sit before the `--`, or the `--` swallows it and docker hands back the
// whole JSON document instead of the two values asked for.

ok('cstat command puts the format flag before --',
   staxx_cstat_cmd('my-c')
     === $dq.' inspect --format '
       . escapeshellarg('{{.RestartCount}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}')
       . ' -- '.escapeshellarg('my-c'));

ok('cenv command names the container after exec',
   staxx_cenv_cmd('my-c') === $dq.' exec '.escapeshellarg('my-c').' env');

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
