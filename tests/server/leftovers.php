<?php
/* PLAN_211 — clearing out what Unraid Docker and Compose Manager left behind:
 * staxx_leftovers(), staxx_leftovers_do_clear() / _restore() / _forget() and
 * the job-start guard. Checked against the real installed Stacks.php.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. Everything lives
 * under /tmp: STORE_ROOT (through run-with-store.sh, which puts the real value
 * back on every exit path), STAXX_UNRAID_TEMPLATES_DIR, STAXX_AUTOUPDATE_FILE,
 * STAXX_COMPOSE_MANAGER_DIR and STAXX_COMPOSE_MANAGER_PLUGIN_DIR. The env vars
 * are set below, before Stacks.php reads them into constants.
 *
 *     pscp tests/server/run-with-store.sh tests/server/leftovers.php root@<box>:/tmp/
 *     plink … 'bash /tmp/run-with-store.sh /tmp/leftovers-store /tmp/leftovers.php'
 *
 * Docker is only ever READ (docker ps) unless the opt-in below is set. The one
 * case that removes a container needs Adrian's OK on the production box, so it
 * is off by default:
 *
 *     plink … 'STAXX_LEFTOVERS_CONTAINER=1 bash /tmp/run-with-store.sh /tmp/leftovers-store /tmp/leftovers.php'
 *
 * With it on, the suite `docker create`s (never starts, never pulls) one
 * labelled container from an image already on the box, and removes only that
 * exact id, after checking its label.
 *
 * Prints one line per case and exits non-zero on any failure. */

putenv('STAXX_UNRAID_TEMPLATES_DIR=/tmp/staxx-left-tpl');
putenv('STAXX_AUTOUPDATE_FILE=/tmp/staxx-left-cau.json');
putenv('STAXX_COMPOSE_MANAGER_DIR=/tmp/staxx-left-cm/compose.manager');
putenv('STAXX_COMPOSE_MANAGER_PLUGIN_DIR=/tmp/staxx-left-cm/plugin-dir');

require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

// Refuse to run against anything but the scratch store: this suite moves files.
if (strpos(staxx_store_root(), '/tmp/') !== 0) {
  echo "FAIL   STORE_ROOT is not under /tmp (got ".staxx_store_root()."); use run-with-store.sh\n";
  exit(1);
}

$tpl  = STAXX_UNRAID_TEMPLATES_DIR;
$cau  = STAXX_AUTOUPDATE_FILE;
$cm   = STAXX_COMPOSE_MANAGER_DIR;
$plug = STAXX_COMPOSE_MANAGER_PLUGIN_DIR;
$sets = staxx_leftovers_root();
$riskStack = staxx_stack_root().'/zzleftrisk';

function tplXml(string $name): string {
  return "<?xml version=\"1.0\"?>\n<Container>\n  <Name>$name</Name>\n  <Repository>alpine</Repository>\n</Container>\n";
}
function row(string $name, string $project = '', string $workdir = '', string $state = 'exited'): array {
  return ['id' => hash('sha256', $name), 'name' => $name, 'state' => $state, 'image' => 'alpine:3',
          'project' => $project, 'workdir' => $workdir];
}

$createdId = '';
function cleanup(): void {
  global $tpl, $cau, $cm, $sets, $riskStack, $createdId;
  @exec('rm -rf '.escapeshellarg($tpl).' '.escapeshellarg('/tmp/staxx-left-cm').' '.escapeshellarg($riskStack));
  @exec('rm -rf '.escapeshellarg($sets));
  @unlink($cau);
  if ($createdId !== '' && preg_match('/^[0-9a-f]{64}$/', $createdId)) {
    // Label guard: only ever remove the container this run created.
    $lab = trim((string)shell_exec('docker inspect -f \'{{index .Config.Labels "staxx.test"}}\' '.escapeshellarg($createdId).' 2>/dev/null'));
    if ($lab === 'leftovers') @exec('docker rm -f '.escapeshellarg($createdId).' >/dev/null 2>&1');
  }
}
cleanup();
register_shutdown_function('cleanup');

mkdir($tpl, 0755, true);
mkdir($riskStack, 0755, true);
file_put_contents($riskStack.'/compose.yaml', "services:\n  a:\n    image: alpine:3.20\n    container_name: zzleftrisk\n");
foreach (['zzleftgone', 'zzleftplain', 'zzleftcompose', 'zzleftrisk'] as $n) file_put_contents("$tpl/my-$n.xml", tplXml($n));
file_put_contents($cau, json_encode(['containers' => ['zzleftgone' => ['name' => 'zzleftgone', 'update' => true]]]));

mkdir("$cm/projects/A", 0755, true);
mkdir("$cm/projects/B", 0755, true);
file_put_contents("$cm/compose.manager.cfg", str_repeat('x', 2000));

/* ---------------------------------------------------------- the listing --- */

$rows = [
  row('zzleftplain'),
  row('zzleftcompose', 'someproject'),
  row('zzleftrisk'),
  row('inA', 'A', '/mnt/elsewhere/stacks/A'),            // same project name, run from StaXX's folder
  row('inB', 'B', "$cm/projects/B"),                     // genuinely run from the add-on's folder
];
$l = staxx_leftovers($rows, []);
$by = [];
foreach ($l['templates'] as $t) $by[$t['name']] = $t;

ok('a template with no container is listed with container null',
   isset($by['zzleftgone']) && $by['zzleftgone']['container'] === null && $by['zzleftgone']['file'] === 'my-zzleftgone.xml',
   json_encode($by['zzleftgone'] ?? null));
ok('a template for a plain container carries the container id, state and image',
   isset($by['zzleftplain']['container']) && $by['zzleftplain']['container']['id'] === hash('sha256', 'zzleftplain')
   && $by['zzleftplain']['container']['state'] === 'exited' && $by['zzleftplain']['container']['image'] === 'alpine:3');
ok('a template whose container belongs to a compose project is not listed', !isset($by['zzleftcompose']));
ok('a template naming a StaXX stack\'s container (at risk) is not listed', !isset($by['zzleftrisk']));

$c = $l['composeManager'];
ok('the Compose Manager folder is listed when the add-on is gone, with a size',
   is_array($c) && $c['size'] > 0, json_encode($c));
$proj = [];
foreach (($c['projects'] ?? []) as $p) $proj[$p['name']] = $p['containers'];
ok('a project counts only containers whose working_dir is inside the folder',
   ($proj['A'] ?? -1) === 0 && ($proj['B'] ?? -1) === 1, json_encode($proj));
ok('blockedBy names the project that still has a container', ($c['blockedBy'] ?? null) === ['B'], json_encode($c['blockedBy'] ?? null));

mkdir($plug, 0755, true);
ok('the folder is not listed while the plugin folder exists', staxx_leftovers($rows, [])['composeManager'] === null);
rmdir($plug);
touch($cm.'.plg');
ok('the folder is not listed while its .plg exists', staxx_leftovers($rows, [])['composeManager'] === null);
unlink($cm.'.plg');

/* ------------------------------------------------- damaged containers --- */

$idA = hash('sha256', 'dmgA'); $idB = hash('sha256', 'dmgB'); $idC = hash('sha256', 'dmgC');
ok('damaged parse: healthy ids echoed are not damaged',
   staxx_leftovers_damaged_parse([$idA, $idB], "$idA
$idB
") === []);
ok('damaged parse: an error line naming a full id marks that id',
   staxx_leftovers_damaged_parse([$idA, $idB], "$idA
Error response from daemon: readlink /x: $idB: no such
") === [$idB]);
ok('damaged parse: an id missing with no error is not damaged',
   staxx_leftovers_damaged_parse([$idA, $idB], "$idA
") === []);
ok('damaged: empty input never asks Docker', staxx_leftovers_damaged([]) === []);

$dRows = [
  row('zzleftplain'),                       // has a template
  row('zzdmgnotpl'),                        // damaged, no template
  row('zzdmgcompose', 'someproject'),       // damaged but compose-labelled
];
$dIds = [hash('sha256', 'zzleftplain'), hash('sha256', 'zzdmgnotpl'), hash('sha256', 'zzdmgcompose')];
$dl = staxx_leftovers($dRows, $dIds);
$dby = [];
foreach ($dl['templates'] as $t) $dby[$t['name']] = $t;
ok('a template row whose container is damaged is flagged', ($dby['zzleftplain']['container']['damaged'] ?? false) === true);
$hb = [];
foreach (staxx_leftovers($dRows, [])['templates'] as $t) $hb[$t['name']] = $t;
ok('a healthy template row is not flagged', ($hb['zzleftplain']['container']['damaged'] ?? true) === false);
$dn = array_column($dl['damaged'], 'name');
ok('a damaged container with no template is listed under damaged', in_array('zzdmgnotpl', $dn, true), json_encode($dl['damaged']));
ok('a damaged container that a template names is not listed twice', !in_array('zzleftplain', $dn, true));
ok('a damaged compose-labelled container is not listed', !in_array('zzdmgcompose', $dn, true));
ok('the damaged key is always present', staxx_leftovers($dRows, [])['damaged'] === []);

/* --------------------------------------------------- job start refusals --- */

$err = '';
ok('a file not on the list starts nothing', staxx_leftovers_clear_job(['../etc/passwd', 'nope.xml'], false, $err) === '' && $err !== '', $err);
ok('the folder is refused while a project still has a container',
   staxx_leftovers_clear_job([], true, $err, $rows, [], []) === '' && strpos($err, 'still has a container') !== false, $err);
ok('a bad stamp is refused by restore and forget',
   !staxx_leftovers_restore('../x', 'template', 'a', $err) && !staxx_leftovers_forget('../x', $err));

/* -------------------------------------------- clear, restore, forget ----- */

$items = [['kind' => 'template', 'name' => 'zzleftgone', 'file' => 'my-zzleftgone.xml']];
$stamp = '20260101-000000';
ob_start();
$done = staxx_leftovers_do_clear($items, $stamp);
ob_end_clean();
ok('clearing a template with no container succeeds', $done);
ok('the template left the Unraid folder and is kept in the set',
   !is_file("$tpl/my-zzleftgone.xml") && is_file("$sets/$stamp/templates/my-zzleftgone.xml"));
$data = json_decode((string)file_get_contents($cau), true);
ok('its Auto Update entry was removed', !isset($data['containers']['zzleftgone']), json_encode($data));

$kept = staxx_leftovers()['kept'];
ok('the kept set is listed newest first with back=true',
   ($kept[0]['stamp'] ?? '') === $stamp && ($kept[0]['items'][0] ?? null) === ['kind' => 'template', 'name' => 'zzleftgone', 'back' => true],
   json_encode($kept));

file_put_contents("$tpl/my-zzleftgone.xml", 'occupied');
ok('put back refuses to overwrite a file at the old place',
   !staxx_leftovers_restore($stamp, 'template', 'zzleftgone', $err) && $err !== '' && file_get_contents("$tpl/my-zzleftgone.xml") === 'occupied', $err);
unlink("$tpl/my-zzleftgone.xml");
ok('put back returns the template and its Auto Update entry',
   staxx_leftovers_restore($stamp, 'template', 'zzleftgone', $err) && is_file("$tpl/my-zzleftgone.xml")
   && isset(json_decode((string)file_get_contents($cau), true)['containers']['zzleftgone']), $err);
ok('a restored item reads back=false', (staxx_leftovers()['kept'][0]['items'][0]['back'] ?? true) === false);

// Compose Manager folder: blocked while B has a container, so use an empty-usage
// listing by clearing directly (do_clear re-reads real Docker, where nothing runs from /tmp).
$stamp2 = '20260101-000001';
ob_start();
$done = staxx_leftovers_do_clear([['kind' => 'compose-manager', 'name' => 'compose.manager']], $stamp2);
ob_end_clean();
ok('the Compose Manager folder is moved into the set', $done && !is_dir($cm) && is_file("$sets/$stamp2/compose.manager/compose.manager.cfg"));
ok('it can be put back', staxx_leftovers_restore($stamp2, 'compose-manager', 'compose.manager', $err) && is_file("$cm/compose.manager.cfg"), $err);
ok('a second put back is refused', !staxx_leftovers_restore($stamp2, 'compose-manager', 'compose.manager', $err));

ok('forget deletes a set for good', staxx_leftovers_forget($stamp, $err) && !is_dir("$sets/$stamp"), $err);
ok('forget on a missing set is an error', !staxx_leftovers_forget($stamp, $err) && $err !== '');

/* ---------------------------------------- opt-in: a real container ------- */

if (getenv('STAXX_LEFTOVERS_CONTAINER') === '1') {
  $img = trim((string)shell_exec('docker images --format \'{{.Repository}}:{{.Tag}}\' 2>/dev/null | grep -v "<none>" | head -n1'));
  if ($img === '') {
    ok('an image is already on the box to create the container from', false);
  } else {
    $name = 'zzleftbox'.getmypid();
    $out = trim((string)shell_exec('docker create --pull never --name '.escapeshellarg($name).' --label staxx.test=leftovers '.escapeshellarg($img).' true 2>/dev/null'));
    $createdId = preg_match('/^[0-9a-f]{64}$/', $out) ? $out : '';
    ok('the test container was created (never started)', $createdId !== '', $out);
    if ($createdId !== '') {
      file_put_contents("$tpl/my-$name.xml", tplXml($name));
      $t = null;
      foreach (staxx_leftovers()['templates'] as $x) if ($x['name'] === $name) $t = $x;
      ok('the real listing shows the container id', $t !== null && ($t['container']['id'] ?? '') === $createdId, json_encode($t));

      $stamp3 = '20260101-000002';
      ob_start();
      $done = staxx_leftovers_do_clear([['kind' => 'container', 'name' => $name, 'file' => "my-$name.xml", 'id' => $createdId]], $stamp3);
      ob_end_clean();
      $still = trim((string)shell_exec('docker inspect -f \'{{.Id}}\' '.escapeshellarg($createdId).' 2>/dev/null'));
      ok('the container is gone and its template kept', $done && $still === '' && is_file("$sets/$stamp3/templates/my-$name.xml"));
      ok('docker inspect was saved first', is_file("$sets/$stamp3/containers/my-$name.xml.json")
         && strpos((string)file_get_contents("$sets/$stamp3/containers/my-$name.xml.json"), $createdId) !== false);
      ok('put back returns only the template', staxx_leftovers_restore($stamp3, 'container', $name, $err) && is_file("$tpl/my-$name.xml"), $err);
    }
  }
} else {
  echo "skip   container removal (set STAXX_LEFTOVERS_CONTAINER=1 to run; needs Adrian's OK on the production box)\n";
}

echo $fails === 0 ? "\nAll passed.\n" : "\n$fails FAILED.\n";
exit($fails === 0 ? 0 : 1);
