<?php
/* PLAN_165 §5 — the sweep for stacks taken over before this plan:
 * staxx_unraid_templates_at_risk() and staxx_unraid_templates_reclaim().
 * Checked against the real installed Stacks.php.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. STAXX_UNRAID_
 * TEMPLATES_DIR and STAXX_AUTOUPDATE_FILE are both overridable by env, the
 * same two overrides tests/server/handover_unraid.php uses — set as
 * environment variables BEFORE Stacks.php is required, since it reads them
 * into constants on first include. STORE_ROOT is left exactly as the real
 * server has it configured: staxx_unraid_named_containers() reads every
 * real stack's own compose file to know which container name belongs to
 * which stack, so the three fixture stacks below are created under the
 * real stack root, named "zzd3…" so they cannot collide with any other
 * suite's fixtures, and removed again on every exit path. A held template
 * this suite moves lands under the real store's config/unraid-templates/,
 * the same folder a real handover would use, and is likewise cleaned up.
 *
 *     pscp tests/server/unraid_templates.php root@<box>:/tmp/
 *     plink … "php /tmp/unraid_templates.php"
 *
 * Prints one line per case and exits non-zero on any failure.
 *
 * MUST NEVER RUN DOCKER: staxx_unraid_templates_at_risk() takes an injected
 * container list throughout (the same shape staxx_docker_container_names()
 * itself returns), so nothing here ever asks Docker anything. */

putenv('STAXX_UNRAID_TEMPLATES_DIR=/tmp/staxx-tpl-test2');
putenv('STAXX_AUTOUPDATE_FILE=/tmp/staxx-cau-test2.json');

require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

/* ------------------------------------------------------------ fixtures --- */

$tplDir  = STAXX_UNRAID_TEMPLATES_DIR;   // /tmp/staxx-tpl-test2, from the env override above
$cauFile = STAXX_AUTOUPDATE_FILE;        // /tmp/staxx-cau-test2.json, likewise
$heldDir = staxx_held_templates_dir();   // the REAL store's config/unraid-templates/
$root    = staxx_stack_root();

// Three stacks, each declaring one container name a template also claims —
// staxx_unraid_named_containers() has to find these by actually reading a
// real compose file, the same way it will for a genuine user's stacks.
$stackNames = ['zzd3ours', 'zzd3absent', 'zzd3unraid'];
$compose = function (string $name): string {
  return "services:\n  a:\n    image: alpine:3.20\n    container_name: $name\n";
};

function xmlTemplate2(string $name): string {
  return "<?xml version=\"1.0\"?>\n<Container>\n  <Name>$name</Name>\n  <Repository>alpine</Repository>\n</Container>\n";
}

$cauFixture = [
  'containers' => [
    // zzd3ours is written LAST so a reclaim that drops it and nothing else
    // leaves the remaining entry's own bytes provably undisturbed.
    'zzd3unraid' => ['name' => 'zzd3unraid', 'update' => true],
    'zzd3ours'   => ['name' => 'zzd3ours',   'update' => true],
  ],
];
$cauFixtureJson = json_encode($cauFixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

function resetAll(array $stackNames, callable $compose, string $root,
                   string $tplDir, string $cauFile, string $cauFixtureJson, string $heldDir): void {
  foreach ($stackNames as $name) {
    @exec('rm -rf '.escapeshellarg($root.'/'.$name));
    mkdir($root.'/'.$name, 0755, true);
    file_put_contents($root.'/'.$name.'/compose.yaml', $compose($name));
  }

  @exec('rm -rf '.escapeshellarg($tplDir));
  mkdir($tplDir, 0755, true);
  foreach ($stackNames as $name) {
    file_put_contents($tplDir.'/my-'.$name.'.xml', xmlTemplate2($name));
  }

  file_put_contents($cauFile, $cauFixtureJson);

  foreach ($stackNames as $name) {
    $held = $heldDir.'/my-'.$name.'.xml';
    if (is_file($held)) @unlink($held);
  }
}

resetAll($stackNames, $compose, $root, $tplDir, $cauFile, $cauFixtureJson, $heldDir);

register_shutdown_function(function () use ($stackNames, $root, $tplDir, $cauFile, $heldDir) {
  foreach ($stackNames as $name) {
    @exec('rm -rf '.escapeshellarg($root.'/'.$name));
    $held = $heldDir.'/my-'.$name.'.xml';
    if (is_file($held)) @unlink($held);
  }
  @exec('rm -rf '.escapeshellarg($tplDir));
  @unlink($cauFile);
});

// zzd3ours matches its own stack's project (leaf "zzd3ours" -> project
// "zzd3ours" via staxx_project_name()); zzd3unraid is held by no project at
// all, exactly the way a dockerman container is; zzd3absent has no container
// running under that name at all.
$containers = [
  'zzd3ours'   => ['running' => true, 'project' => staxx_project_name('zzd3ours')],
  'zzd3unraid' => ['running' => true, 'project' => ''],
  // zzd3absent is deliberately missing from this list.
];

/* ------------------------------------------- staxx_unraid_templates_at_risk -- */

$rows = staxx_unraid_templates_at_risk($containers);
$byName = [];
foreach ($rows as $r) $byName[$r['name']] = $r;

ok('every fixture template is reported', count($rows) === 3, json_encode($rows));
ok('a template whose container is this stack\'s own project reads "ours", on Auto Update\'s list',
   ($byName['zzd3ours']['state'] ?? '') === 'ours' && ($byName['zzd3ours']['autoupdate'] ?? null) === true
   && ($byName['zzd3ours']['stack'] ?? '') === 'zzd3ours', json_encode($byName['zzd3ours'] ?? null));
ok('a template with no container at all reads "absent", not on Auto Update\'s list',
   ($byName['zzd3absent']['state'] ?? '') === 'absent' && ($byName['zzd3absent']['autoupdate'] ?? null) === false,
   json_encode($byName['zzd3absent'] ?? null));
ok('a template held by no project at all (dockerman) reads "unraid", on Auto Update\'s list',
   ($byName['zzd3unraid']['state'] ?? '') === 'unraid' && ($byName['zzd3unraid']['autoupdate'] ?? null) === true,
   json_encode($byName['zzd3unraid'] ?? null));

/* ---------------------------------------------- staxx_unraid_templates_reclaim -- */

$error = '';
// The same injected list the classification above used — reclaim decides what
// is still Unraid's from its own reading, so without this it would ask the real
// Docker, find none of these fixtures, and move the one row it must not touch.
$moved = staxx_unraid_templates_reclaim([], $error, $containers);
sort($moved);

ok('reclaim moves only the "ours" and "absent" rows',
   $moved === ['zzd3absent', 'zzd3ours'] && $error === '', json_encode($moved).' '.$error);
ok('their templates now live in StaXX\'s own held folder',
   is_file($heldDir.'/my-zzd3ours.xml') && is_file($heldDir.'/my-zzd3absent.xml'));
ok('neither template is still in Unraid\'s own folder',
   !is_file($tplDir.'/my-zzd3ours.xml') && !is_file($tplDir.'/my-zzd3absent.xml'));
ok('the "unraid" row\'s template is untouched — still genuinely Unraid\'s',
   is_file($tplDir.'/my-zzd3unraid.xml') && !is_file($heldDir.'/my-zzd3unraid.xml'));

$afterReclaim = $cauFixture;
unset($afterReclaim['containers']['zzd3ours']);
$afterReclaimJson = json_encode($afterReclaim, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
ok('Auto Update\'s list drops the moved "ours" entry, and only that one — the untouched entry is byte-identical',
   file_get_contents($cauFile) === $afterReclaimJson);

// Asking again now finds only the untouched "unraid" row — the moved
// templates are gone from Unraid's own folder, so scanning it again cannot
// see them at all.
$rowsAfter = staxx_unraid_templates_at_risk($containers);
ok('a second sweep finds only the row that was never moved',
   count($rowsAfter) === 1 && $rowsAfter[0]['name'] === 'zzd3unraid', json_encode($rowsAfter));

/* -------------------------------------------------------------- cleanup --- */

foreach ($stackNames as $name) {
  @exec('rm -rf '.escapeshellarg($root.'/'.$name));
  $held = $heldDir.'/my-'.$name.'.xml';
  if (is_file($held)) @unlink($held);
}
@exec('rm -rf '.escapeshellarg($tplDir));
@unlink($cauFile);

ok('nothing left behind',
   !is_dir($root.'/zzd3ours') && !is_dir($root.'/zzd3absent') && !is_dir($root.'/zzd3unraid')
   && !is_file($heldDir.'/my-zzd3ours.xml') && !is_file($heldDir.'/my-zzd3absent.xml'));

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
