<?php
/* PLAN_224 part B — explaining a port clash after an app on the server's own
 * network (`network_mode: host`) is started: the log matcher (nginx, Go and
 * Node wording), the four "who holds it" sentences with `ss` output and cgroup
 * text handed in, the whole check against injected container rows, which
 * services count as host-mode, and the shell wrapper that carries the exit
 * code (run through a real `sh` against stand-in commands).
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. The host-mode
 * detection reads a compose file the suite writes into a scratch stack, so
 * STORE_ROOT has to be pointed at /tmp through the shared wrapper, which
 * puts it back on every exit path:
 *
 *     pscp tests/server/run-with-store.sh tests/server/hostport.php root@<box>:/tmp/
 *     plink … 'bash /tmp/run-with-store.sh /tmp/zzh224-store /tmp/hostport.php'
 *
 * Nothing here starts, pulls or stops a container, opens a port, or sleeps
 * five seconds: Docker is never asked for containers (rows are injected), and
 * the shell wrapper runs stand-in commands. Not covered here: the live case,
 * a real host-mode nginx on port 80, which is run once by hand with Adrian's
 * OK. Prints one line per case and exits non-zero on any failure.
 */

require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

if (staxx_stack_root() !== '/tmp/zzh224-store/stacks') {
  echo "FAIL   the temporary stack root is not in place (got ".staxx_stack_root().")\n";
  exit(1);
}

/* ------------------------------------------------------ the log matcher -- */

$nginx = "2026/10/03 12:34:56 [emerg] 1#1: bind() to 0.0.0.0:80 failed (98: Address in use)\n";
$go    = "2026/10/03 12:34:56 listen tcp :8080: bind: address already in use\n";
$node  = "Error: listen EADDRINUSE: address already in use :::3000\n    at Server.setupListenHandle\n";
ok('nginx wording gives 80, not the timestamp', staxx_hostport_log_port($nginx) === 80);
ok('Go wording gives 8080', staxx_hostport_log_port($go) === 8080);
ok('Node wording gives 3000', staxx_hostport_log_port($node) === 3000);
ok('a log with no clash gives nothing', staxx_hostport_log_port("starting\nlistening on :80\nready\n") === 0);
ok('a clash line with no port gives nothing', staxx_hostport_log_port("OSError: Address already in use\n") === 0);
ok('the clash is found among other lines',
   staxx_hostport_log_port("one\ntwo :99\n".$go."three\n") === 8080);

/* ----------------------------------------------------- who holds the port -- */

$id64 = str_repeat('ab', 32);
$ssNginx = 'LISTEN 0 511 0.0.0.0:80 0.0.0.0:* users:(("nginx",pid=4242,fd=6),("nginx",pid=4243,fd=6))';
$ssOther = 'LISTEN 0 128 0.0.0.0:8080 0.0.0.0:* users:(("python3",pid=777,fd=3))';

ok('a container holder is named',
   staxx_hostport_owner(80, $ssNginx, "0::/docker/$id64\n", '/sonarr')
     === 'Port 80 is already used by the app sonarr.');
ok('Unraid\'s own nginx is its web page',
   staxx_hostport_owner(80, $ssNginx, "0::/\n")
     === "Port 80 is already used by Unraid's own web page.");
ok('emhttpd is Unraid\'s web page too',
   staxx_hostport_owner(80, 'LISTEN 0 5 127.0.0.1:80 0.0.0.0:* users:(("emhttpd",pid=9,fd=4))', "0::/\n")
     === "Port 80 is already used by Unraid's own web page.");
ok('a container\'s own nginx is the app, not Unraid',
   strpos(staxx_hostport_owner(80, $ssNginx, "0::/system.slice/docker-$id64.scope\n", 'proxy'), 'the app proxy') !== false);
ok('another process is named',
   staxx_hostport_owner(8080, $ssOther, "0::/\n")
     === 'Port 8080 is already used by python3 on the server.');
ok('nothing found says it was in use when it started',
   staxx_hostport_owner(8080, '', '') === 'Port 8080 was in use when it started.');

/* ------------------------------------------------------- the whole check -- */

$rows = [
  ['name' => 'web-hn-b', 'service' => 'b', 'mode' => 'host', 'state' => 'exited', 'log' => $nginx],
  ['name' => 'web-hn-a', 'service' => 'a', 'mode' => 'host', 'state' => 'running', 'log' => $nginx],
  ['name' => 'bridged',  'service' => 'c', 'mode' => 'bridge', 'state' => 'exited', 'log' => $nginx],
  ['name' => 'quiet',    'service' => 'd', 'mode' => 'host', 'state' => 'exited', 'log' => "bye\n"],
  ['name' => 'looper',   'service' => 'e', 'mode' => 'host', 'state' => 'restarting', 'log' => $go],
];
ob_start();
$n = staxx_hostport_check('x', [], $rows, $ssNginx, "0::/\n");
$out = ob_get_clean();
ok('two host-mode clashes are counted; running, bridge and quiet ones are not', $n === 2, "got $n");
ok('the sentence is the planned one, word for word',
   strpos($out, "web-hn-b stopped straight away: it could not open port 80. "
     ."Port 80 is already used by Unraid's own web page. "
     ."Change the port this app listens on, then start it again.\n") === 0);
ok('a restarting container is explained too', strpos($out, 'looper stopped straight away: it could not open port 8080.') !== false);

ob_start();
$n = staxx_hostport_check('x', ['e'], $rows, $ssOther, "0::/\n");
ob_end_clean();
ok('limiting to one service leaves the others out', $n === 1, "got $n");

ob_start();
$n = staxx_hostport_check('x', [], [], null, null);
ob_end_clean();
ok('no containers means no clash', $n === 0);

/* ------------------------------------------- which services are host-mode -- */

$dir = staxx_stack_dir('zzh224a');
@mkdir($dir, 0755, true);
file_put_contents($dir.'/compose.yaml',
  "services:\n  a:\n    image: busybox\n    network_mode: host\n"
 ."  b:\n    image: busybox\n    network_mode: host\n"
 ."  c:\n    image: busybox\n");
$file = staxx_find_compose_file($dir);
ok('host-mode services are found', staxx_hostport_services($file) === ['a', 'b']);
ok('a single-service start is limited to its own', staxx_hostport_services($file, ['b']) === ['b']);
ok('a bridge service alone has none', staxx_hostport_services($file, ['c']) === []);

$dir2 = staxx_stack_dir('zzh224b');
@mkdir($dir2, 0755, true);
file_put_contents($dir2.'/compose.yaml', "services:\n  c:\n    image: busybox\n");
ok('a stack with no host-mode service has none', staxx_hostport_services(staxx_find_compose_file($dir2)) === []);

/* ------------------------------------------------------- the shell chain -- */

$step = staxx_hostport_step("it's", ['a']);
ok('the step runs the check with the name quoted, never raw',
   strpos($step, 'staxx_hostport_check(') !== false && strpos($step, "'it's'") === false
   && strpos($step, "it\\'") !== false);

ok('without a check the chain is unchanged',
   staxx_job_inner('/tmp', 'true 2>&1') === 'cd \'/tmp\' && true 2>&1; echo "'.STAXX_JOB_END.' $?"');
ok('with a check the step and the wait are present',
   strpos(staxx_job_inner('/tmp', 'true 2>&1', 'CHECKSTEP'), 'sleep 5; CHECKSTEP') !== false);

// Run it for real, with stand-in commands and the wait stripped out.
function run_inner(string $chain, string $check): array {
  $cmd = str_replace('sleep 5; ', '', staxx_job_inner('/tmp', $chain, $check));
  $out = [];
  exec('sh -c '.escapeshellarg($cmd).' 2>&1', $out);
  $text = implode("\n", $out);
  preg_match('/'.preg_quote(STAXX_JOB_END, '/').' (\d+)/', $text, $m);
  return [(int)($m[1] ?? -1), $text];
}
[$rc, $t] = run_inner('true', 'echo said-it; (exit 1)');
ok('a clash makes the job exit 1 although compose succeeded', $rc === 1 && strpos($t, 'said-it') !== false, "rc $rc");
[$rc] = run_inner('true', 'true');
ok('no clash leaves the exit 0', $rc === 0, "rc $rc");
[$rc] = run_inner('true', '(exit 255)');
ok('a check that crashed does not fail a start that worked', $rc === 0, "rc $rc");
[$rc, $t] = run_inner('(exit 7)', 'echo should-not-run');
ok('a compose failure keeps its own code and skips the check', $rc === 7 && strpos($t, 'should-not-run') === false, "rc $rc");
[$rc] = run_inner('(exit 7)', '');
ok('with no check a failure is still its own code', $rc === 7, "rc $rc");

/* ---------------------------------------------------------------- tidy -- */

@unlink($dir.'/compose.yaml');  @rmdir($dir);
@unlink($dir2.'/compose.yaml'); @rmdir($dir2);

exit($fails > 0 ? 1 : 0);
