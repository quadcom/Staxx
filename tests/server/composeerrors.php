<?php
/* PLAN_212 — include/ComposeErrors.php (the look-up list matcher and the
 * message "shape") and the needs-a-fix mark in the stack's record.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. The needs-a-fix
 * cases write a record, so STORE_ROOT must be pointed at /tmp/p212-store the
 * same way tests/server/adopt.php does it, never the real store:
 *
 *     pscp tests/server/run-with-store.sh tests/server/composeerrors.php root@<box>:/tmp/
 *     plink … 'bash /tmp/run-with-store.sh /tmp/p212-store /tmp/composeerrors.php'
 *
 * The two look-up files (shipped and store copy) are scratch files under
 * /tmp, handed in by constant before ComposeErrors.php loads. Runs no docker
 * command. Creates and removes its own stack, "zzcerr", under the temporary
 * stack root.
 */

$scratch = '/tmp/zzcerr-'.getmypid();
@mkdir($scratch, 0755, true);
define('STAXX_COMPOSE_ERRORS_SHIPPED', $scratch.'/shipped.json');
define('STAXX_COMPOSE_ERRORS_STORE', $scratch.'/store.json');

require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Record.php';
require_once '/usr/local/emhttp/plugins/staxx/include/ComposeErrors.php';

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

$real = '/usr/local/emhttp/plugins/staxx/include/compose-errors.json';
copy($real, STAXX_COMPOSE_ERRORS_SHIPPED);

/* ---------------------------------------------------------- the shape -- */

ok('shape: path, service, quoted value',
  staxx_compose_shape("validating /tmp/abc/compose.yaml: services.web additional properties 'restrat' not allowed")
  === "validating <path>: services.<service> additional properties '<value>' not allowed");
ok('shape: service "name"',
  staxx_compose_shape('service "web" refers to undefined network backend: invalid compose project')
  === 'service "<service>" refers to undefined network backend: invalid compose project');
ok('shape: a quoted dependency name',
  staxx_compose_shape('service "web" depends on undefined service "db": invalid compose project')
  === 'service "<service>" depends on undefined service "<service>": invalid compose project');
ok('shape: an address goes, a line number stays',
  staxx_compose_shape('yaml: line 4: bad host 192.168.1.20:8080') === 'yaml: line 4: bad host <address>');

/* ------------------------------------------------- explain every sample -- */

$list = json_decode(file_get_contents($real), true);
foreach ($list['entries'] as $e) {
  $x = staxx_compose_explain($e['sample']);
  ok('explain: '.$e['id'].' matches its own sample', ($x['entry']['id'] ?? '') === $e['id']);
  ok('explain: '.$e['id'].' leaves no placeholder behind',
    !preg_match('/\{\w+\}/', ($x['entry']['means'] ?? '').($x['entry']['fix'] ?? '')));
}
$x = staxx_compose_explain('non-string key in services.mattermost.environment: 8075');
ok('explain: number-key fills service and key',
  strpos($x['entry']['means'], 'In mattermost, one setting is named 8075.') !== false
  && ($x['entry']['autofix'] ?? '') === 'quote-env-key');
ok('explain: raw, shape and report keys are always there',
  $x['raw'] !== '' && $x['shape'] !== '' && array_key_exists('report', $x));
$x = staxx_compose_explain('something compose has never said');
ok('explain: an unknown message has a null entry and a shape', $x['entry'] === null && $x['shape'] !== '');
// Without $queue, explain must never reach staxx_error_report_status() (the editor's check
// runs on half-typed text); errorreports.php proves the queue itself.
ok('explain: the default does not queue', staxx_compose_explain('something compose has never said')['report'] === ''
  && staxx_compose_explain('something compose has never said', false) === $x);

/* ------------------------------------------- shipped copy against store -- */

$mk = function (string $version, string $title): string {
  return json_encode(['version' => $version, 'entries' => [[
    'id' => 'x', 'match' => 'non-string key', 'title' => $title, 'means' => 'm', 'fix' => 'f', 'docs' => 'd']]]);
};
$probe = 'non-string key in services.a.environment: 1';
file_put_contents(STAXX_COMPOSE_ERRORS_STORE, $mk('2099-01-01', 'FROM STORE'));
ok('lists: a newer store copy wins', staxx_compose_explain($probe)['entry']['title'] === 'FROM STORE');
file_put_contents(STAXX_COMPOSE_ERRORS_STORE, $mk('2000-01-01', 'FROM STORE'));
ok('lists: an older store copy loses', staxx_compose_explain($probe)['entry']['id'] === 'number-key');
file_put_contents(STAXX_COMPOSE_ERRORS_STORE, '{ not json');
ok('lists: a broken store copy is ignored', staxx_compose_explain($probe)['entry']['id'] === 'number-key');
@unlink(STAXX_COMPOSE_ERRORS_STORE);
ok('lists: no store copy is fine', staxx_compose_explain('invalid containerPort: abc')['entry']['id'] === 'bad-port');

/* ---------------------------------------------------- the needs-fix mark -- */

if (staxx_stack_root() !== '/tmp/p212-store/stacks') {
  echo "FAIL   the temporary stack root is not in place (got ".staxx_stack_root().")\n";
  $fails++;
  done();
}
$root = staxx_stack_root();
@exec('rm -rf '.escapeshellarg($root));
mkdir($root.'/zzcerr', 0755, true);
file_put_contents($root.'/zzcerr/compose.yaml', "services:\n  a:\n    image: alpine:3.20\n");
$dir = $root.'/zzcerr';

ok('needsFix: reads empty to start with', staxx_record_needs_fix($dir) === '');
ok('needsFix: clearing a stack with no record makes no record',
  staxx_record_set_needs_fix($dir, '') && !is_dir($dir.'/.staxx'));
ok('needsFix: set', staxx_record_set_needs_fix($dir, 'shape one') && staxx_record_needs_fix($dir) === 'shape one');
ok('needsFix: reading by stack name finds it too', staxx_record_needs_fix('zzcerr') === 'shape one');
$note = '';
staxx_record_capture('zzcerr', 'compose.yaml', $note);
ok('needsFix: a history save carries it forward', staxx_record_needs_fix($dir) === 'shape one');
ok('needsFix: clear', staxx_record_set_needs_fix($dir, '') && staxx_record_needs_fix($dir) === ''
  && strpos((string)file_get_contents($dir.'/.staxx/record.json'), 'needsFix') === false);
ok('needsFix: history survived the round trip', count(staxx_record_read('zzcerr')['versions']) === 1);

@exec('rm -rf '.escapeshellarg($root));
done();
