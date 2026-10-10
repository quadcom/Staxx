<?php
/* PLAN_212 — include/ErrorReports.php: the report queue and the daily fetch of
 * the explanations file, with no real network.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. It writes into the
 * store's config folder, so STORE_ROOT must be pointed at /tmp the way
 * tests/server/composeerrors.php does it, never at the real store:
 *
 *     pscp tests/server/run-with-store.sh tests/server/errorreports.php root@<box>:/tmp/
 *     plink … 'bash /tmp/run-with-store.sh /tmp/p212r-store /tmp/errorreports.php'
 *
 * The queue file is a scratch file under /tmp, the intake address is a dead
 * local port (so a send fails the way an offline box does), automatic sending
 * is switched off, and the fetch reads a file:// address. The switched-off
 * case runs in a child process, because the settings are read once per process.
 * Creates and removes only its own files.
 */

$scratch = '/tmp/zzerep-'.getmypid();
@mkdir($scratch, 0755, true);
define('STAXX_ERROR_REPORTS_FILE', $scratch.'/queue.json');
define('STAXX_ERROR_REPORTS_NO_SEND', true);
define('STAXX_FEEDBACK_BASE', 'http://127.0.0.1:9');
define('STAXX_COMPOSE_ERRORS_URL', 'file://'.$scratch.'/remote.json');

require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/ErrorReports.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}
function done(): void {
  global $fails, $scratch;
  foreach (glob($scratch.'/*') ?: [] as $f) @unlink($f);
  @rmdir($scratch);
  echo $fails === 0 ? "\nAll checks passed.\n" : "\n$fails check(s) FAILED.\n";
  exit($fails > 0 ? 1 : 0);
}

$cfgRoot = staxx_config_root();
if (strpos($cfgRoot, '/tmp/') !== 0) {
  echo "Refusing to run: the store is not under /tmp (use run-with-store.sh).\n";
  exit(2);
}
@mkdir($cfgRoot, 0755, true);

$shape = "validating <path>: services.<service> additional properties '<value>' not allowed";

/* ------------------------------------------------------------ the queue -- */

ok('matched: nothing to say', staxx_error_report_status($shape, true) === '');
ok('matched: nothing is queued', staxx_error_reports_load()['shapes'] === []);
ok('StaXX\'s own refusal is not reported', staxx_error_report_status('The compose file is empty.', false) === ''
  && staxx_error_reports_load()['shapes'] === []);
ok('a shape the intake would refuse is not reported', staxx_error_report_status('too short', false) === '');
ok('known: an unseen shape reads empty and is not queued',
  staxx_error_report_known($shape) === '' && staxx_error_reports_load()['shapes'] === []);
ok('first sight: waiting', staxx_error_report_status($shape, false) === 'waiting');
ok('first sight: queued once', array_keys(staxx_error_reports_load()['shapes']) === [$shape]);
ok('second sight: still waiting, not queued twice',
  staxx_error_report_status($shape, false) === 'waiting' && count(staxx_error_reports_load()['shapes']) === 1);

ok('known: a queued shape reads waiting', staxx_error_report_known($shape) === 'waiting');
$q = staxx_error_reports_load();
$q['shapes'][$shape]['sent'] = true;
$q['serverId'] = 'abc123';
staxx_error_reports_save($q);
ok('sent shape: sent', staxx_error_report_status($shape, false) === 'sent');
ok('known: a sent shape reads sent', staxx_error_report_known($shape) === 'sent');
ok('the server ID is kept', staxx_error_reports_load()['serverId'] === 'abc123');

$q['shapes']["service \"<service>\" has neither an image nor a build context specified: invalid compose project"] = ['sent' => false, 'at' => 1];
staxx_error_reports_save($q);
ok('an unreachable intake sends nothing and leaves the shape waiting', staxx_error_report_send() === 0
  && empty(staxx_error_reports_load()['shapes']["service \"<service>\" has neither an image nor a build context specified: invalid compose project"]['sent']));

/* ---------------------------------------------------- the switch is off -- */

file_put_contents($cfgRoot.'/'.STAXX_PLUGIN.'.cfg', "ERROR_REPORTS=\"false\"\n");
$before = file_get_contents(STAXX_ERROR_REPORTS_FILE);
$child = '$_=1;'
       . 'define("STAXX_ERROR_REPORTS_FILE", '.var_export(STAXX_ERROR_REPORTS_FILE, true).');'
       . 'define("STAXX_ERROR_REPORTS_NO_SEND", true);'
       . 'require "/usr/local/emhttp/plugins/staxx/include/ErrorReports.php";'
       . 'echo staxx_error_report_status("a brand new shape of message", false);';
$out = shell_exec(staxx_php_bin().' -r '.escapeshellarg($child).' 2>&1');
@unlink($cfgRoot.'/'.STAXX_PLUGIN.'.cfg');
ok('switched off: says off', trim((string)$out) === 'off', trim((string)$out));
ok('switched off: queues nothing', file_get_contents(STAXX_ERROR_REPORTS_FILE) === $before);

/* ------------------------------------------------------- the daily fetch -- */

$dest = $cfgRoot.'/compose-errors.json';
@unlink($dest);
$entry = ['id' => 'x', 'match' => 'x', 'title' => 't', 'means' => 'm', 'fix' => 'f'];
function remote(string $version, array $entries): void {
  global $scratch;
  file_put_contents($scratch.'/remote.json', json_encode(['version' => $version, 'entries' => $entries]));
}
remote('2026-10-01', [$entry]);
ok('fetch: a good file is kept', staxx_compose_errors_fetch() === '' && is_file($dest)
  && json_decode(file_get_contents($dest), true)['version'] === '2026-10-01');
remote('2026-09-01', [$entry]);
staxx_compose_errors_fetch();
ok('fetch: an older file does not replace the newer copy',
  json_decode(file_get_contents($dest), true)['version'] === '2026-10-01');
file_put_contents($scratch.'/remote.json', 'not json {');
ok('fetch: an unparseable file is refused and the copy survives',
  staxx_compose_errors_fetch() !== '' && json_decode(file_get_contents($dest), true)['version'] === '2026-10-01');
@unlink($scratch.'/remote.json');
ok('fetch: a failed fetch keeps the copy',
  staxx_compose_errors_fetch() !== '' && json_decode(file_get_contents($dest), true)['version'] === '2026-10-01');
remote('2026-11-01', [$entry]);
staxx_compose_errors_fetch();
ok('fetch: a newer file replaces it', json_decode(file_get_contents($dest), true)['version'] === '2026-11-01');
@unlink($dest);

done();
