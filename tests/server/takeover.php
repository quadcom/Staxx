<?php
/* The takeover — staxx_project_containers() and staxx_start_takeover(), the
 * route an imported Compose Manager project takes instead of a handover.
 *
 * Every case here is a REFUSAL, on purpose. The one thing a takeover does
 * when it is not refused is rebuild containers that are really running, which
 * is not something a test may do on a live server — so what is proved here is
 * that each door is shut, and the route each real project would take is
 * printed for a human to read rather than asserted.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. Stacks live on
 * /boot by default, so this run needs STACK_ROOT pointed at /tmp instead —
 * the CALLER sets that and puts the config back, same as override.php:
 *
 *     CFG=/boot/config/plugins/staxx/staxx.cfg
 *     cp $CFG /tmp/cfg.bak
 *     sed -i 's#^STACK_ROOT=.*#STACK_ROOT="/tmp/b1-takeover"#' $CFG
 *     mkdir -p /tmp/b1-takeover
 *     php /tmp/takeover.php; RC=$?
 *     cp /tmp/cfg.bak $CFG
 *     exit $RC
 *
 * Prints one line per case and exits non-zero on any failure.
 */
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Import.php';

$root = staxx_stack_root();
if (strpos($root, '/tmp/') !== 0) {
  echo "FAIL   the temporary stack root is not in place (got $root)\n";
  exit(1);
}

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

/* ------------------------------------------------------- the closed doors -- */

$err = '';
ok('a stack that does not exist is refused',
   staxx_start_takeover('nosuchstack', $err) === '' && stripos($err, 'no stack') !== false, $err);

// The review lock is the only door in — a stack nobody has imported must not
// be startable this way, since this path exists to clear that lock.
$plain = 'zzt1plain';
@mkdir(staxx_stack_dir($plain), 0755, true);
file_put_contents(staxx_stack_dir($plain).'/compose.yaml',
  "services:\n  a:\n    image: alpine:3.20\n");
$err = '';
ok('a stack not awaiting review is refused',
   staxx_start_takeover($plain, $err) === '' && stripos($err, 'awaiting review') !== false, $err);

file_put_contents(staxx_stack_dir($plain).'/'.STAXX_REVIEW_FILE, "held\n");
ok('the lock is seen', staxx_review_locked($plain));

$err = '';
ok('a locked stack with nothing running under its name is refused',
   staxx_start_takeover($plain, $err) === '' && stripos($err, 'nothing to take over') !== false, $err);

// The note is held aside rather than deleted, so a refusal that happens before
// the job starts must leave it exactly where it was — otherwise a refused
// takeover would quietly unlock the stack it refused to touch.
ok('...and the review note was left exactly where it was',
   is_file(staxx_stack_dir($plain).'/'.STAXX_REVIEW_FILE)
   && !is_file(staxx_stack_dir($plain).'/.'.STAXX_REVIEW_FILE));

@exec('rm -rf '.escapeshellarg(staxx_stack_dir($plain)));

/* ---------------------------------------- the route each project would take -- */

echo "\n       Route each importable project would take, for reading:\n";
foreach (staxx_import_projects() as $p) {
  if (!$p['ready']) continue;
  $rows    = staxx_project_containers($p['dest']);
  $running = 0;
  foreach ($rows as $r) if ($r['running']) $running++;
  printf("       %-22s route=%-8s containers=%d running=%d\n",
    $p['id'], $rows ? 'rebuild' : 'none', count($rows), $running);
}

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
