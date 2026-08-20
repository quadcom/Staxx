<?php
/* PLAN_44 phase 0's server half: the `recreate` and stack-scope `update`
 * verbs, the scope-refusal rule that already protected `config` and
 * `remove`, and the offset-tailing job log reader.
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
 * hand under STAXX_JOB_DIR, never a real job. */

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

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
