<?php
/* The importer, checked against data the suite makes for itself — so it passes
 * on any Unraid box, with or without Compose Manager, FolderView3 or any
 * Unraid template installed.
 *
 *   - The three READERS (templates, Compose Manager projects, loose
 *     containers) and the FolderView3 mapping read a fake templates folder, a
 *     fake Compose Manager projects folder and a fake FolderView3 file, all
 *     under /tmp/staxx-imp-<pid>. Import.php lets a suite name those three
 *     places by defining their constants first (STAXX_IMPORT_TEMPLATES_DIR,
 *     STAXX_IMPORT_PROJECTS_DIR, STAXX_IMPORT_FOLDERVIEW3_FILE), done below
 *     before it loads. STAXX_IMPORT_LOG_FILE is defined the same way, so the
 *     import log is a scratch file too.
 *   - PLAN_219's reader of compose projects other tools started
 *     (staxx_import_compose_projects(), _map_path(), _scan_folder()) is fed
 *     canned Docker rows and mounts, so no Docker is needed for its logic.
 *   - The write tests import into the scratch store, never the real one: the
 *     suite refuses to run unless STORE_ROOT is under /tmp.
 *     staxx_import_write() and staxx_import_write_project() shell out to
 *     `docker compose config -q` to validate the YAML, the same dry check an
 *     ordinary save runs; nothing here starts, stops or pulls anything.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. Through the helper,
 * which redirects STORE_ROOT to /tmp and puts the real value back:
 *
 *     scp tests/server/import.php tests/server/run-with-store.sh root@<box>:/tmp/
 *     ssh … 'bash /tmp/run-with-store.sh /tmp/import-store /tmp/import.php'
 *
 * OPT-IN (STAXX_IMPORT_CONTAINERS=1) adds the Docker half: five "dummy
 * containers" carrying compose labels plus one stand-in for the tool that runs
 * them, made with `docker create --pull never` from nginx:alpine (skipped with
 * a note when that image is not already on the box), NEVER started. They are
 * removed by the exact ids this run created, each after a label check, on
 * every exit path. They let the suite check that staxx_import_compose_projects()
 * finds a project through real `docker ps` and `docker inspect`, and that a
 * compose row can be written. Off by default, so a default run creates
 * nothing in Docker.
 *
 *     ssh … 'STAXX_IMPORT_CONTAINERS=1 bash /tmp/run-with-store.sh /tmp/import-store /tmp/import.php'
 *
 * Prints one line per case and exits non-zero on any failure.
 *
 * Everything the suite makes lives in /tmp/staxx-imp-<pid>, the scratch
 * store's own zz* stacks and (opt-in) the dummy containers, and is removed by a
 * shutdown function, so it also runs on exit() and on a fatal error. */

ini_set('display_errors', '1');   // a fatal must show, not exit silently
error_reporting(E_ALL & ~E_DEPRECATED);
$T = '/tmp/staxx-imp-'.getmypid();
define('STAXX_IMPORT_TEMPLATES_DIR',     $T.'/templates');
define('STAXX_IMPORT_PROJECTS_DIR',      $T.'/cm/projects');
define('STAXX_IMPORT_FOLDERVIEW3_FILE',  $T.'/fv3.json');
define('STAXX_IMPORT_LOG_FILE',          $T.'/import.log');
putenv('STAXX_UNRAID_TEMPLATES_DIR='.$T.'/templates');

// Stacks.php first: it is where staxx_valid_name() lives, and Import.php's
// own double-inclusion guard makes requiring it directly here harmless even
// though Import.php is expected to require it anyway.
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Import.php';
// action.php loads this before it reaches any import code; staxx_import_write_project()
// needs staxx_compose_explain() from it when compose refuses a file.
require_once '/usr/local/emhttp/plugins/staxx/include/ComposeErrors.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}
// For a case that needs something this box happens to have — not finding it
// is a fact about this box, not a wrong answer, so it never counts as a failure.
function skip(string $what, string $why): void {
  printf("%-6s %s  (%s)\n", 'skip', $what, $why);
}

// Nothing below may touch the real store: the write tests create stacks.
if (strpos(staxx_store_root(), '/tmp/') !== 0) {
  echo "FAIL   STORE_ROOT is not under /tmp (got ".staxx_store_root()."); use run-with-store.sh\n";
  exit(1);
}
$root = staxx_stack_root();
@mkdir($root, 0755, true);

/* ------------------------------------------------------- cleanup, always -- */

$DOCKER_ON = getenv('STAXX_IMPORT_CONTAINERS') === '1';
$created   = [];   // container ids this run made, nothing else is ever removed
$ownStacks = ['zzb1import', 'zzb1importbad', 'zzb1importnone', 'zzb1importfolder', 'zzb1importasis',
              'zzb1importsame', 'zzb1importfolder2', 'zzb1importidmatch', 'App-One',
              'zzimp-folder', 'zzimp-bad', 'zzimp-compose', 'zzimp-existing'];

function dk(string $args): string {
  return trim((string)shell_exec(escapeshellarg(staxx_docker_bin()).' '.$args.' 2>&1'));
}
function cleanup(): void {
  global $T, $created, $ownStacks, $root;
  foreach ($created as $id) {
    // Label check first: only a container this exact run labelled is removed.
    $lbl = dk('inspect -f '.escapeshellarg('{{index .Config.Labels "staxx.suite.run"}}').' '.escapeshellarg($id));
    if ($lbl === (string)getmypid()) dk('rm '.escapeshellarg($id));
  }
  foreach ($ownStacks as $s) {
    if ($root !== '' && strpos($root, '/tmp/') === 0) @exec('rm -rf '.escapeshellarg($root.'/'.$s));
  }
  if (preg_match('#^/tmp/staxx-imp-\d+$#', $T)) @exec('rm -rf '.escapeshellarg($T));
}
register_shutdown_function('cleanup');

function mk(string $path, string $content): void {
  @mkdir(dirname($path), 0755, true);
  file_put_contents($path, $content);
}

/* ------------------------------------------------------------ fixtures -- */

$SVC     = "services:\n  web:\n    image: nginx:alpine\n";
$REFUSED = "services: [unclosed\n";            // compose refuses this outright
$NOSVC   = "services: {}\n";                   // compose reads it and finds nothing

// Unraid templates: one with spaces in its name and a space-packed category,
// one plain, one that is not XML at all, and a .bak that must be ignored.
$tplXml = function (string $name, string $category, string $extra = '') {
  return "<?xml version=\"1.0\"?>\n<Container version=\"2\">\n  <Name>$name</Name>\n"
       . "  <Repository>nginx:alpine</Repository>\n  <Category>$category</Category>\n$extra"
       . "  <PostArgs/>\n"
       . "  <Config Name=\"Web UI\" Target=\"80\" Default=\"8080\" Mode=\"tcp\" Description=\"\" Type=\"Port\" Display=\"always\" Required=\"true\" Mask=\"false\">8080</Config>\n"
       . "  <Config Name=\"Data\" Target=\"/data\" Default=\"/mnt/user/appdata/x\" Mode=\"rw\" Description=\"\" Type=\"Path\" Display=\"always\" Required=\"true\" Mask=\"false\">/mnt/user/appdata/x</Config>\n"
       . "  <Config Name=\"Mode\" Target=\"MODE\" Default=\"\" Mode=\"\" Description=\"\" Type=\"Variable\" Display=\"always\" Required=\"false\" Mask=\"false\">fast</Config>\n"
       . "</Container>\n";
};
mk($T.'/templates/app-one.xml', $tplXml('App One', 'Network:Management Productivity: Tools:Utilities'));
mk($T.'/templates/apptwo.xml',  $tplXml('apptwo', 'Tools:'));
mk($T.'/templates/broken.xml',  "this is not xml <<<\n");
mk($T.'/templates/old.xml.bak', $tplXml('Old Backup', 'Tools:'));
$expectTemplates = 3;
$MGR_NAME = 'staxx-suite-import-'.getmypid().'-mgr';
if ($DOCKER_ON) {   // a template whose container really exists, to prove exists/running
  mk($T.'/templates/mgr.xml', $tplXml($MGR_NAME, 'Tools:'));
  $expectTemplates++;
}

// Compose Manager projects.
$CM = $T.'/cm/projects';
mk($CM.'/plain/docker-compose.yml', $SVC);
mk($CM.'/with-override/docker-compose.yml', $SVC);
mk($CM.'/with-override/docker-compose.override.yml', "services:\n  web:\n    environment:\n      A: b\n");
mk($CM.'/with-override/.env', "X=1\n");
mk($CM.'/indirect-proj/indirect', $T.'/appdata/Indirect_Proj'."\n");
mk($T.'/appdata/Indirect_Proj/docker-compose.yml', $SVC);
mk($CM.'/refused/docker-compose.yml', $REFUSED);
mk($CM.'/spaced/docker-compose.yml', $SVC);
mk($CM.'/spaced/name', "My Spaced App\n");
mk($CM.'/noservices/docker-compose.yml', $NOSVC);

// FolderView3: seven folders, a blank entry, and a folder built on a regex.
mk($T.'/fv3.json', json_encode([
  'a1' => ['name' => 'Media',            'regex' => '', 'containers' => ['plex', 'sonarr', 'radarr']],
  'a2' => ['name' => '268 Stuff',        'regex' => '', 'containers' => ['apptwo', 'x1']],
  'a3' => ['name' => 'Net',              'regex' => '', 'containers' => ['n1', 'n2']],
  'a4' => ['name' => 'SupStack Backend', 'regex' => '', 'containers' => ['b1', 'b2', '']],
  'a5' => ['name' => 'Tools',            'regex' => '', 'containers' => ['t1']],
  'a6' => ['name' => 'Cams',             'regex' => '', 'containers' => ['c1', 'c2']],
  'a7' => ['name' => 'Misc',             'regex' => '', 'containers' => ['m1']],
  'a8' => ['name' => 'Pattern Folder',   'regex' => '^vpn-.*', 'containers' => ['should-be-ignored']],
]));

// Opt-in dummy containers, made BEFORE anything asks Docker for its list
// (staxx_docker_ps_raw() remembers its answer for the request).
$DK = $T.'/dk';
$P  = 'staxximp'.getmypid().'x';
$dummy = [];   // role => container id
if ($DOCKER_ON) {
  if (!staxx_docker_running()) {
    skip('dummy containers', 'Docker is not running');
    $DOCKER_ON = false;
  } elseif (dk('image inspect nginx:alpine --format x') !== 'x') {
    skip('dummy containers', 'nginx:alpine is not on this box and this suite never pulls');
    $DOCKER_ON = false;
  } else {
    mk($DK.'/scanroot/host/compose.yaml', $SVC);
    mk($DK.'/scanroot/host/.env', "A=1\n");
    mk($DK.'/scanroot/other/compose.yaml', $SVC);
    mk($DK.'/mgr/compose/7/docker-compose.yml', $SVC);
    mk($DK.'/two/compose.yaml', $SVC);
    mk($DK.'/two/compose.override.yaml', "services:\n  web:\n    environment:\n      A: b\n");
    mk($DK.'/bad/compose.yaml', $REFUSED);
    $make = function (string $role, array $labels, array $mounts = []) use (&$created, &$dummy): void {
      $cmd = 'create --pull never --name '.escapeshellarg('staxx-suite-import-'.getmypid().'-'.$role)
           . ' --label staxx.suite=import --label staxx.suite.run='.getmypid();
      foreach ($labels as $k => $v) $cmd .= ' --label '.escapeshellarg($k.'='.$v);
      foreach ($mounts as $m) $cmd .= ' -v '.escapeshellarg($m);
      $out = dk($cmd.' nginx:alpine');
      $parts = explode(PHP_EOL, $out); $id = (string)array_pop($parts);
      if (preg_match('/^[0-9a-f]{64}$/', $id)) { $created[] = $id; $dummy[$role] = $id; }
      else ok('creating the '.$role.' dummy container', false, $out);
    };
    $c = 'com.docker.compose.project';
    $make('mgr',    [], [$DK.'/mgr:/staxx-suite-mgr']);
    $make('host',   [$c => $P.'host',    $c.'.config_files' => $DK.'/scanroot/host/compose.yaml', $c.'.working_dir' => $DK.'/scanroot/host']);
    $make('mapped', [$c => $P.'mapped',  $c.'.config_files' => '/staxx-suite-mgr/compose/7/docker-compose.yml', $c.'.working_dir' => '/staxx-suite-mgr/compose/7']);
    $make('nowhere',[$c => $P.'nowhere', $c.'.config_files' => '/staxx-suite-nowhere/compose.yaml']);
    $make('two',    [$c => $P.'two',     $c.'.config_files' => $DK.'/two/compose.yaml,'.$DK.'/two/compose.override.yaml', $c.'.working_dir' => $DK.'/two']);
    $make('bad',    [$c => $P.'bad',     $c.'.config_files' => $DK.'/bad/compose.yaml', $c.'.working_dir' => $DK.'/bad']);
    if (count($dummy) !== 6) $DOCKER_ON = false;   // the failures above already counted
  }
}

$list      = staxx_import_list();
$templates = (array)($list['templates'] ?? []);
$projects  = (array)($list['projects']  ?? []);
$loose     = (array)($list['loose']     ?? []);
$readable  = array_values(array_filter($templates, fn($t) => ($t['app'] ?? null) !== null));

/* ------------------------------------------------------------ templates -- */

$diskTemplates = 0;
foreach ((array)@scandir(STAXX_IMPORT_TEMPLATES_DIR) as $file) {
  if (!preg_match('/\.xml$/i', $file)) continue;
  if (!is_file(STAXX_IMPORT_TEMPLATES_DIR.'/'.$file)) continue;
  $diskTemplates++;
}
ok('finds every template on disk, readable or not', count($templates) === $diskTemplates
   && $diskTemplates === $expectTemplates,
   'found '.count($templates).' of '.$diskTemplates.' *.xml files');
ok('...and the one that is not XML is listed unreadable, not dropped',
   count($templates) - count($readable) === 1);

$bak = 0;
foreach ($templates as $t) if (substr(strtolower((string)($t['id'] ?? '')), -4) === '.bak') $bak++;
ok('the .bak file in the same folder is not among them', $bak === 0, $bak.' found');

$undecoded = 0;
foreach ($readable as $t) if (empty($t['app']['Name'] ?? '')) $undecoded++;
ok('every readable template decodes with a name', $undecoded === 0, $undecoded.' without one');

// The empty-element bug: an XML element written as <PostArgs/> decodes to an
// empty ARRAY via json_decode(json_encode(simplexml_load_file())). PHP treats
// that as falsy, but the browser converter treats an empty array as truthy —
// so left alone this writes command: "" into most templates, overriding the
// image's own start-up command. Every field of every template, plus each
// nested Config row's attributes and value, must have been coerced to a
// plain string by the time it gets here.
// EMPTY arrays specifically, walked to any depth. Testing for "is an array"
// instead would fail on every template on earth: `@attributes` is a map of
// the element's own attributes and is meant to be one, as is each Config row.
//
// CategoryList and Config are the exceptions, and deliberately: both are
// lists Import.php BUILDS rather than decodes, and both are read as lists by
// ca-convert.js. A template with no category, or with no settings at all,
// genuinely has an empty one. Their CONTENTS are still walked.
$empties = [];
$walk = function ($node, string $path) use (&$walk, &$empties): void {
  if (!is_array($node)) return;
  if ($node === []) { $empties[] = $path; return; }
  foreach ($node as $k => $v) $walk($v, $path.'/'.$k);
};
foreach ($readable as $t) {
  foreach ((array)($t['app'] ?? []) as $k => $v) {
    if (($k === 'CategoryList' || $k === 'Config') && $v === []) continue;
    $walk($v, $k);
  }
}
ok('no field in any decoded template is left as an empty array',
   $empties === [], implode(', ', array_slice(array_unique($empties), 0, 5)));
ok('...the empty <PostArgs/> in particular reads as an empty string',
   (($readable[0]['app']['PostArgs'] ?? null) === ''));

/* Every setting survives the read, with its attributes intact.
 *
 * Encoding the whole template to JSON collapses each <Config> to its text
 * alone and throws the attributes away — the name, the target, the type — and
 * the converter then skips the setting as untyped and says so in a comment
 * nobody reads. Measured when that was happening: 1,154 settings lost across
 * 73 of 85 templates. The import looked like it worked. The fixtures carry
 * exactly three settings each (a port, a path, a variable). */
$rows = 0; $typed = 0; $withAttrs = 0; $withValueKey = 0; $types = [];
foreach ($readable as $t) {
  foreach ((array)($t['app']['Config'] ?? []) as $row) {
    $rows++;
    $a = $row['@attributes'] ?? null;
    if (is_array($a) && $a !== []) $withAttrs++;
    if (is_array($a) && ($a['Type'] ?? '') !== '') { $typed++; $types[$a['Type']] = true; }
    if (array_key_exists('value', $row)) $withValueKey++;
  }
}
ok('every readable template carries its three settings', $rows === 3 * count($readable),
   $rows.' rows across '.count($readable).' templates');
ok('every setting kept its attributes', $rows > 0 && $withAttrs === $rows,
   $withAttrs.' of '.$rows);
ok('every setting declares a type, so none is skipped as untyped',
   $rows > 0 && $typed === $rows, $typed.' of '.$rows);
ok('every setting carries a value key for the converter to read',
   $rows > 0 && $withValueKey === $rows, $withValueKey.' of '.$rows);
ok('the usual Unraid setting types are all present',
   isset($types['Port'], $types['Path'], $types['Variable']),
   implode(', ', array_keys($types)));

// Category is one space-packed string on a template; the catalogue supplies
// a list, and ca-convert.js's normaliseCategory() already prefers
// CategoryList — so prove the split actually happened.
$appOne = null; $appTwo = null; $mgrTpl = null;
foreach ($readable as $t) {
  if ($t['name'] === 'App One') $appOne = $t;
  if ($t['name'] === 'apptwo')  $appTwo = $t;
  if ($t['name'] === $MGR_NAME) $mgrTpl = $t;
}
ok('a space-packed Category splits into more than one entry',
   $appOne !== null && $appOne['app']['CategoryList'] === ['Network:Management', 'Productivity:', 'Tools:Utilities'],
   json_encode($appOne['app']['CategoryList'] ?? null));
ok('a template with one category gets a one-entry CategoryList',
   $appTwo !== null && $appTwo['app']['CategoryList'] === ['Tools:']);
ok('a name with a space gets a usable stack folder and a note saying so',
   $appOne !== null && $appOne['folder'] === 'App-One' && staxx_valid_name($appOne['folder'])
   && strpos(implode(' ', $appOne['notes']), 'would call it "App-One"') !== false, json_encode($appOne['notes'] ?? null));
if ($DOCKER_ON) {
  ok('a template whose container exists reports it present and not running',
     $mgrTpl !== null && $mgrTpl['exists'] === true && $mgrTpl['running'] === false, json_encode($mgrTpl['exists'] ?? null));
}

/* ---------------------------------------------------------------- folder -- */

$badFolder = [];
foreach (array_merge($readable, $projects, $loose) as $e) {
  $f = (string)($e['folder'] ?? '');
  if (!staxx_valid_name($f)) $badFolder[] = ($e['id'] ?? '?').' -> '.$f;
}
ok("every entry's folder name passes staxx_valid_name()", empty($badFolder), implode(', ', $badFolder));

/* -------------------------------------------------------------- projects -- */

ok('finds the 6 Compose Manager projects in the fixture folder', count($projects) === 6, 'found '.count($projects));

$byId = [];
foreach ($projects as $p) $byId[(string)($p['id'] ?? '')] = $p;

$ind = $byId['indirect-proj'] ?? null;
ok('a project with only an indirect file resolves through it',
   $ind !== null && ($ind['via'] ?? '') === 'indirect');
ok('...and the path is used exactly as the indirect file wrote it, capitals and all',
   $ind !== null && ($ind['file'] ?? '') === $T.'/appdata/Indirect_Proj/docker-compose.yml',
   'file: '.($ind['file'] ?? '(none)'));

// PLAN_212 / PLAN_219: a file compose refuses is no longer turned away. It is
// listed ready, with a note saying it will be imported and marked as needing a fix.
$refused = $byId['refused'] ?? null;
ok('a file compose refuses is listed READY with the keep-and-flag note',
   $refused !== null && ($refused['ready'] ?? false) === true
   && in_array('Docker Compose cannot read this file yet. It will be imported and marked as needing a fix.', $refused['notes'], true),
   json_encode($refused['notes'] ?? null));

$nosvc = $byId['noservices'] ?? null;
ok('a compose file with no services is not ready', $nosvc !== null && ($nosvc['ready'] ?? true) === false
   && strpos(implode(' ', $nosvc['notes']), 'holds no services') !== false, json_encode($nosvc['notes'] ?? null));
ok('the plain, override, indirect and spaced projects are ready',
   ($byId['plain']['ready'] ?? false) && ($byId['with-override']['ready'] ?? false)
   && ($ind['ready'] ?? false) && ($byId['spaced']['ready'] ?? false));

$overrideCount = 0;
foreach ($projects as $p) {
  $notes = strtolower(implode(' ', (array)($p['notes'] ?? [])));
  if (strpos($notes, 'override') !== false) $overrideCount++;
}
ok('exactly one project carries the override note', $overrideCount === 1, 'found '.$overrideCount);
ok('...and its settings file was found', ($byId['with-override']['env'] ?? '') === $CM.'/with-override/.env');

// A real name with spaces, which staxx_valid_name() rejects — the list must
// show both the real name and the folder name StaXX would actually use.
$spaced = $byId['spaced'] ?? null;
ok('a project named with a space keeps its real name', $spaced !== null && ($spaced['name'] ?? '') === 'My Spaced App');
ok('...and gets a usable folder name',
   $spaced !== null && staxx_valid_name((string)$spaced['folder']), (string)($spaced['folder'] ?? ''));

/* ------------------------------------------------------------------ loose -- */

// A count, not a number. Which containers belong to neither is a fact about
// what is running this afternoon.
ok('the neither-list is readable', is_array($loose), 'found '.count($loose));

/* -------------------------------------------------------- self-consistency -- */

$runningWithoutExisting = 0;
foreach (array_merge($templates, $projects) as $e) {
  if (!empty($e['running']) && empty($e['exists'])) $runningWithoutExisting++;
}
ok('nothing is reported running without also being reported present', $runningWithoutExisting === 0,
   $runningWithoutExisting.' found');

/* -------------------------------------------------------- FolderView3 -- */
//
// Read from the fixture file, which is what STAXX_IMPORT_FOLDERVIEW3_FILE
// names here: 7 folders, 13 real containers, one blank entry, no container in
// two folders, and one folder using a regex.
//
// The blank is a real-world quirk: FolderView3's "SupStack Backend" once
// carried an empty string where a third container name should be. The reader
// drops it and is right to; pinning the count is what stops somebody
// restoring a tidier-looking number later.

$fv3 = staxx_import_folderview3();
$fv3Folders = array_unique(array_values($fv3['containers']));
ok('reads the mapping: finds 7 folders', count($fv3Folders) === 7, 'found '.count($fv3Folders));
ok('...covering the 13 containers, the blank entry dropped',
   count($fv3['containers']) === 13, 'found '.count($fv3['containers']));
ok('...and no empty name survives into the mapping', !array_key_exists('', $fv3['containers']));
ok('a folder using a regex is left out of the mapping', !isset($fv3['containers']['should-be-ignored']));
ok('...and named in skipped, so the panel can say why',
   $fv3['skipped'] === ['Pattern Folder'], implode(', ', $fv3['skipped']));
ok('...while an ordinary folder still works', ($fv3['containers']['plex'] ?? '') === 'Media');
ok('the page list carries the skipped folder names', $list['folderRules'] === ['Pattern Folder']);

// Duplicate-filing is checked against the raw file rather than trusting the
// reader's own output, since a reader that silently let a later folder win
// would still look correct from its own map.
$fv3Raw  = json_decode((string)@file_get_contents(STAXX_IMPORT_FOLDERVIEW3_FILE), true) ?: [];
$fv3Seen = [];
$fv3Dupes = 0;
foreach ($fv3Raw as $folder) {
  if (!is_array($folder) || trim((string)($folder['regex'] ?? '')) !== '') continue;
  foreach ((array)($folder['containers'] ?? []) as $c) {
    $c = (string)$c;
    if ($c === '') continue;
    if (isset($fv3Seen[$c])) $fv3Dupes++;
    $fv3Seen[$c] = true;
  }
}
ok('no container appears in two folders', $fv3Dupes === 0, $fv3Dupes.' found');

// A missing mapping file — the plugin not being installed — is normal, not
// an error.
$fv3Missing = staxx_import_folderview3('/tmp/staxx-test-fv3-missing.json');
ok('a missing mapping file yields an empty mapping and no error',
   $fv3Missing === ['containers' => [], 'skipped' => []]);

// A template whose container is filed in a folder takes that folder's name.
ok('a template filed in a FolderView3 folder carries it, renamed where it must be',
   $appTwo !== null && $appTwo['dockerFolder'] === '268 Stuff' && $appTwo['folderName'] === '268-Stuff'
   && $appTwo['folderRenamed'] === true, json_encode([$appTwo['dockerFolder'] ?? null, $appTwo['folderName'] ?? null]));

/* --------------------------------------------------- name conversion -- */

// The folder name needing a change, and the folderRenamed flag
// staxx_import_templates() derives from comparing them the same way.
ok('"268 Stuff" becomes a usable stack folder name',
   staxx_valid_name(staxx_import_safe_name('268 Stuff')));
ok('...specifically "268-Stuff"', staxx_import_safe_name('268 Stuff') === '268-Stuff',
   staxx_import_safe_name('268 Stuff'));
ok('folderRenamed would read true for "268 Stuff"',
   staxx_import_safe_name('268 Stuff') !== '268 Stuff');
ok('...and false for "Media", which needed no change',
   staxx_import_safe_name('Media') === 'Media');

/* -------------------------------------------------------------- icons -- */
//
// Nothing here may reach the network or copy anything to disk to show a
// picture — same rule Icons.php states at its own top. A picture is either
// already a file inside a stack's own .staxx folder, or shown straight from
// its own address; nothing else is ever fetched or claimed to exist.

$remoteIcon = staxx_icon_resolve('https://example.invalid/'.uniqid().'.png');
ok('resolving a URL shows it straight from its own address — nothing is fetched to do that',
   $remoteIcon['ref'] !== '' && $remoteIcon['url'] !== '', json_encode($remoteIcon));

$missingLocal = staxx_icon_resolve('/tmp/staxx-test-nonexistent-'.uniqid().'.png');
ok('an absolute local path names no picture at all — only a .staxx file or an address ever does',
   $missingLocal['ref'] === '' && $missingLocal['url'] === '', json_encode($missingLocal));

/* -------------------------------------------------------------- writing -- */

$root = staxx_stack_root();

$about = [
  'source'           => 'template',
  'id'               => 'my-app.xml',
  'name'             => 'My App',
  'container'        => 'my-app',
  'containerExists'  => true,
  'containerRunning' => true,
  'warnings'         => ['A path pointed at a bare word and was skipped.'],
  'notes'            => ['A blank Web UI port was filled in as 8080.'],
];
$yaml = "services:\n  a:\n    image: alpine:3.20\n";

// ---- case 1, 2, 5a: a clean write, its lock, and its note ----

$rel = 'zzb1import';
$dir = $root.'/'.$rel;
@exec('rm -rf '.escapeshellarg($dir));

$err = '';
ok('a write succeeds', staxx_import_write($rel, $yaml, $about, $err), $err);

// Stale note fixed in passing, unrelated to PLAN_106: staxx_save_stack()
// now writes its own .staxx folder (history, record.json) beside the
// compose file on every save — this assertion predates that and was
// failing before this plan touched anything.
$entries = array_values(array_diff((array)@scandir($dir), ['.', '..']));
sort($entries);
ok('the folder holds exactly the compose file, the note, and .staxx',
   $entries === ['.staxx', 'NEEDS-REVIEW.md', 'compose.yaml'], implode(', ', $entries));

ok('the written stack reads as locked', staxx_review_locked($rel));

$note = (string)@file_get_contents($dir.'/NEEDS-REVIEW.md');
ok('the note names the container when one exists',
   strpos($note, 'my-app') !== false && strpos($note, 'already exists') !== false, $note);

// ---- case 3: a second write to the same name is refused, first untouched ----

$beforeCompose = file_get_contents($dir.'/compose.yaml');
$beforeNote    = file_get_contents($dir.'/NEEDS-REVIEW.md');

$err2 = '';
$again = staxx_import_write($rel, "services:\n  b:\n    image: alpine:3.21\n", $about, $err2);
ok('a second write to the same name is refused', !$again, $err2);
ok('...naming what to do about it', stripos($err2, 'already exists') !== false, $err2);
ok('the first compose file is left byte-for-byte unchanged',
   file_get_contents($dir.'/compose.yaml') === $beforeCompose);
ok('the first note is left byte-for-byte unchanged',
   file_get_contents($dir.'/NEEDS-REVIEW.md') === $beforeNote);

@exec('rm -rf '.escapeshellarg($dir));

// ---- case 4: a rejected compose file leaves nothing behind ----
//
// An empty body is refused by staxx_validate_compose() before it ever shells
// out to compose, which is what makes this the cheap, docker-free way to
// prove the rollback: no folder, no note left over from the attempt.

$relBad = 'zzb1importbad';
$dirBad = $root.'/'.$relBad;
@exec('rm -rf '.escapeshellarg($dirBad));

$err4 = '';
ok('a write of a rejected compose file is refused',
   !staxx_import_write($relBad, '', $about, $err4), $err4);
ok('...and leaves no folder behind', !is_dir($dirBad));

// ---- case 5b: the note when no matching container exists ----

$relNo = 'zzb1importnone';
$dirNo = $root.'/'.$relNo;
@exec('rm -rf '.escapeshellarg($dirNo));

$aboutNo = $about;
$aboutNo['containerExists']  = false;
$aboutNo['containerRunning'] = false;

$err5 = '';
staxx_import_write($relNo, $yaml, $aboutNo, $err5);
$noteNo = (string)@file_get_contents($dirNo.'/NEEDS-REVIEW.md');
ok('the note says there is nothing to replace when no container exists',
   strpos($noteNo, 'nothing here for this stack to replace') !== false, $noteNo);

@exec('rm -rf '.escapeshellarg($dirNo));

// ---- case 6: a write into an existing folder lands in the right place ----

$folder    = 'zzb1importfolder';
$folderDir = $root.'/'.$folder;
@exec('rm -rf '.escapeshellarg($folderDir));
mkdir($folderDir, 0755, true);

$err6 = '';
ok('a write into an existing folder succeeds',
   staxx_import_write($folder.'/leaf', $yaml, $about, $err6), $err6);
ok('...and lands at Folder/leaf', is_file($folderDir.'/leaf/compose.yaml'));

// ---- case 7: PLAN_106 phase 5 — the as-is text lands in the stack's own
// history. staxx_save_stack() captures BOTH what it is about to overwrite
// and what it has just written, so the as-is save alone (the first of the
// two staxx_import_write() now makes) already files it as version 1 the
// moment it lands; the second save then files the escaped text actually
// left running as version 2 the same way. Either capture on its own would
// have kept the as-is wording — this proves both land, in the right order.

$relAsIs = 'zzb1importasis';
$dirAsIs = $root.'/'.$relAsIs;
@exec('rm -rf '.escapeshellarg($dirAsIs));

$asIsYaml   = "services:\n  a:\n    environment:\n      TOKEN: \"\$argon2id\$v=19\$m=6\"\n    image: alpine:3.20\n";
$escapedYaml = "services:\n  a:\n    environment:\n      TOKEN: \"\$\$argon2id\$\$v=19\$\$m=6\"\n    image: alpine:3.20\n";

$err7 = '';
ok('a write carrying an as-is text succeeds',
   staxx_import_write($relAsIs, $escapedYaml, $about, $err7, $asIsYaml), $err7);
ok('...and the file on disk is the escaped version, not the as-is one',
   file_get_contents($dirAsIs.'/compose.yaml') === $escapedYaml);

$historyAsIs = staxx_record_list($relAsIs);
ok('...and both saves filed their own history version', count($historyAsIs) === 2, json_encode($historyAsIs));
$asIsVersion = null;
foreach ($historyAsIs as $v) { if ($v['n'] === 1) $asIsVersion = $v; }
if ($asIsVersion !== null) {
  $kept = staxx_record_get($relAsIs, 1);
  ok('...and the earlier one holds the as-is wording, dollar signs single',
     $kept === $asIsYaml, (string)$kept);
}

@exec('rm -rf '.escapeshellarg($dirAsIs));

// ---- case 8: an as-is text identical to what is being written is never
// saved twice — staxx_import_write() only makes the extra save when the two
// actually differ, so a template with no dollar sign in it writes exactly
// once, the same as an ordinary import always has.

$relSame = 'zzb1importsame';
$dirSame = $root.'/'.$relSame;
@exec('rm -rf '.escapeshellarg($dirSame));

$err8 = '';
ok('a write with an as-is text identical to the body still succeeds',
   staxx_import_write($relSame, $yaml, $about, $err8, $yaml), $err8);
$historySame = staxx_record_list($relSame);
ok('...and files exactly one history version, same as an ordinary first save',
   count($historySame) === 1, json_encode($historySame));

@exec('rm -rf '.escapeshellarg($dirSame));

@exec('rm -rf '.escapeshellarg($folderDir));

/* -------------------------------------------------------------- PLAN_141 -- */
//
// Point 1: a stack counts as taken at any depth, not just the top level, and
// the note names exactly where it already lives.

$nestedFolder    = 'zzb1importfolder2';
$nestedFolderDir = $root.'/'.$nestedFolder;
@exec('rm -rf '.escapeshellarg($nestedFolderDir));
mkdir($nestedFolderDir.'/zzb1importleaf', 0755, true);
file_put_contents($nestedFolderDir.'/zzb1importleaf/compose.yaml',
  "services:\n  a:\n    image: alpine:3.20\n");

staxx_scan_stacks_reset();
staxx_import_taken_names(true);
staxx_import_taken_sources(true);
  staxx_compose_meta('', $metaErr, true);

$nestedTaken = staxx_import_taken_by('zzb1importleaf');
ok('a nested stack counts as taken at any depth',
   $nestedTaken === 'zzb1importfolder2/zzb1importleaf', $nestedTaken);

ok('the Add Container warning finds a stack inside a folder', staxx_import_name_taken('zzb1importleaf'));
ok('the Add Container warning ignores case', staxx_import_name_taken('ZZB1ImportLeaf'));
ok('the Add Container warning stays quiet for an unused name', !staxx_import_name_taken('zzb1nosuchapp'));
ok('the Add Container warning stays quiet for no name', !staxx_import_name_taken(''));

@exec('rm -rf '.escapeshellarg($nestedFolderDir));
staxx_scan_stacks_reset();
staxx_import_taken_names(true);
staxx_import_taken_sources(true);
  staxx_compose_meta('', $metaErr, true);

// Point 3: a stack's own imported.id marks the template it names as taken,
// even though its folder name matches nothing about that template at all.

$tplForId = null;
// One nothing already claims: a name match is tried first and would win.
foreach ($templates as $t) { if (($t['app'] ?? null) !== null && !$t['taken']) { $tplForId = $t; break; } }

if ($tplForId === null) {
  skip('a stack whose imported.id matches marks that template row taken',
       'no readable template found to test against');
} else {
  $relId = 'zzb1importidmatch';
  $dirId = $root.'/'.$relId;
  @exec('rm -rf '.escapeshellarg($dirId));
  mkdir($dirId, 0755, true);
  $idYaml = "x-unraid:\n  version: 1\n  imported:\n    from: unraid-template\n    on: 2026-01-01\n"
          . "    id: \"".addslashes((string)$tplForId['id'])."\"\n"
          . "services:\n  a:\n    image: alpine:3.20\n";
  file_put_contents($dirId.'/compose.yaml', $idYaml);

  staxx_scan_stacks_reset();
  staxx_import_taken_names(true);
  staxx_import_taken_sources(true);
  staxx_compose_meta('', $metaErr, true);
  $freshTemplates = staxx_import_templates(true);

  $matched = null;
  foreach ($freshTemplates as $t) { if ($t['id'] === $tplForId['id']) { $matched = $t; break; } }
  ok('a stack whose imported.id matches marks that template row taken',
     $matched !== null && $matched['taken'] === true && $matched['takenBy'] === $relId,
     json_encode($matched));
  ok('...and the note names where it already is',
     $matched !== null && in_array('Already in StaXX as "'.$relId.'".', $matched['notes'], true),
     json_encode($matched['notes'] ?? null));

  @exec('rm -rf '.escapeshellarg($dirId));
  staxx_scan_stacks_reset();
  staxx_import_taken_names(true);
  staxx_import_taken_sources(true);
  staxx_compose_meta('', $metaErr, true);
  staxx_import_templates(true);
}

// Point 6: the back-fill. A throwaway stack whose leaf is a real template's
// own safe name, and whose file carries only the plain from/on block, gains
// id/name once — and a second run changes nothing more.

$tplForBackfill = null;
foreach ($templates as $t) {
  if (($t['app'] ?? null) === null) continue;
  $leaf = staxx_import_safe_name((string)$t['name']);
  if ($leaf === '' || $leaf === 'stack') continue;
  if (isset(staxx_import_taken_names()[strtolower($leaf)])) continue; // a real stack, at any depth, already uses this name
  $tplForBackfill = $t;
  break;
}

if ($tplForBackfill === null) {
  skip('the back-fill stamps a matched stack once',
       'no usable, uncollided template name found to test against');
} else {
  $leafBackfill = staxx_import_safe_name((string)$tplForBackfill['name']);
  $dirBackfill  = $root.'/'.$leafBackfill;
  @exec('rm -rf '.escapeshellarg($dirBackfill));
  mkdir($dirBackfill, 0755, true);
  $plainYaml = "x-unraid:\n  version: 1\n  imported:\n    from: unraid-template\n    on: 2026-01-01\n"
             . "services:\n  a:\n    image: alpine:3.20\n";
  file_put_contents($dirBackfill.'/compose.yaml', $plainYaml);

  staxx_scan_stacks_reset();
  staxx_import_taken_names(true);
  staxx_import_taken_sources(true);
  staxx_compose_meta('', $metaErr, true);
  $freshTemplates2 = staxx_import_templates(true);

  $historyBefore = staxx_record_list($leafBackfill);
  $changed = staxx_import_backfill($freshTemplates2);

  $changedRel = null;
  foreach ($changed as $c) { if ($c['rel'] === $leafBackfill) { $changedRel = $c; break; } }
  ok('the back-fill stamps a matched stack once', $changedRel !== null, json_encode($changed));

  $afterText = (string)@file_get_contents($dirBackfill.'/compose.yaml');
  ok('...and the file now carries an id line',
     strpos($afterText, '    id: "'.$tplForBackfill['id'].'"') !== false, $afterText);
  ok('...and a name line',
     strpos($afterText, '    name: "'.$tplForBackfill['name'].'"') !== false, $afterText);

  $historyAfter = staxx_record_list($leafBackfill);
  ok('...and the stack gained a history entry for its previous version',
     count($historyAfter) > count($historyBefore), json_encode([$historyBefore, $historyAfter]));

  // Second run: the stack now carries imported.id, so nothing further happens.
  staxx_scan_stacks_reset();
  staxx_import_taken_names(true);
  staxx_import_taken_sources(true);
  staxx_compose_meta('', $metaErr, true);
  $freshTemplates3 = staxx_import_templates(true);
  $changedAgain = staxx_import_backfill($freshTemplates3);
  $stillThere = false;
  foreach ($changedAgain as $c) { if ($c['rel'] === $leafBackfill) $stillThere = true; }
  ok('a second run changes nothing more', !$stillThere, json_encode($changedAgain));

  @exec('rm -rf '.escapeshellarg($dirBackfill));
  staxx_scan_stacks_reset();
  staxx_import_taken_names(true);
  staxx_import_taken_sources(true);
  staxx_compose_meta('', $metaErr, true);
  staxx_import_templates(true);
}

// A stack matching two different templates (one by leaf, one by container
// name) is left alone — the back-fill only ever acts when exactly one
// template can be named with confidence.

$tplA = null; $tplB = null;
$dockerNameRe = '/^[a-zA-Z0-9][a-zA-Z0-9_.-]*$/';
foreach ($templates as $t) {
  if (($t['app'] ?? null) === null) continue;
  $leaf = staxx_import_safe_name((string)$t['name']);
  if ($leaf === '' || $leaf === 'stack') continue;
  if (isset(staxx_import_taken_names()[strtolower($leaf)])) continue;
  if ($tplA === null) { $tplA = $t; continue; }
  if ($tplB === null && preg_match($dockerNameRe, (string)$t['name']) && $t['name'] !== $tplA['name']) {
    $tplB = $t;
    break;
  }
}

if ($tplA === null || $tplB === null) {
  skip('a stack matching two templates is left alone',
       'no usable pair of templates found to test against');
} else {
  $leafAmbig = staxx_import_safe_name((string)$tplA['name']);
  $dirAmbig  = $root.'/'.$leafAmbig;
  @exec('rm -rf '.escapeshellarg($dirAmbig));
  mkdir($dirAmbig, 0755, true);
  $ambigYaml = "x-unraid:\n  version: 1\n  imported:\n    from: unraid-template\n    on: 2026-01-01\n"
             . "services:\n  a:\n    image: alpine:3.20\n    container_name: ".$tplB['name']."\n";
  file_put_contents($dirAmbig.'/compose.yaml', $ambigYaml);

  staxx_scan_stacks_reset();
  staxx_import_taken_names(true);
  staxx_import_taken_sources(true);
  staxx_compose_meta('', $metaErr, true);
  $freshTemplates4 = staxx_import_templates(true);

  $changedAmbig = staxx_import_backfill($freshTemplates4);
  $touchedAmbig = false;
  foreach ($changedAmbig as $c) { if ($c['rel'] === $leafAmbig) $touchedAmbig = true; }
  ok('a stack matching two templates is left alone', !$touchedAmbig, json_encode($changedAmbig));

  @exec('rm -rf '.escapeshellarg($dirAmbig));
  staxx_scan_stacks_reset();
  staxx_import_taken_names(true);
  staxx_import_taken_sources(true);
  staxx_compose_meta('', $metaErr, true);
  staxx_import_templates(true);
}

// PLAN_73 — staxx_parse_ss_listeners() against canned `ss -ltunpH` text, not
// the real machine, so what survives and how it is labelled is proved rather
// than merely observed. One line per case ss can actually produce: a
// container's own published port (docker-proxy, must be dropped since the
// container fact already names it), a loopback-only listener with a real
// process name, and a wildcard listener with no process column at all (what
// a non-root run of ss would look like, even though this page always runs
// as root).
$ssSample = implode("\n", [
  'tcp   LISTEN 0      511          0.0.0.0:8080       0.0.0.0:*    users:(("docker-proxy",pid=111,fd=6))',
  'tcp   LISTEN 0      128        127.0.0.1:5432        0.0.0.0:*    users:(("postgres",pid=222,fd=3))',
  'tcp   LISTEN 0      511             [::]:443            [::]:*    users:(("nginx",pid=333,fd=6))',
  'tcp   LISTEN 0      128                *:9999             *:*',
]);
$ssParsed = staxx_parse_ss_listeners($ssSample);

$byPort = [];
foreach ($ssParsed as $row) $byPort[$row['port']] = $row;

ok('a docker-proxy line never survives the parse', !isset($byPort['8080']));
ok('a loopback listener survives, named', ($byPort['5432']['addr'] ?? null) === '127.0.0.1'
   && ($byPort['5432']['holder'] ?? null) === 'postgres');
ok('an IPv6 wildcard listener survives, brackets stripped', ($byPort['443']['addr'] ?? null) === '::'
   && ($byPort['443']['holder'] ?? null) === 'nginx');
ok('a listener with no process column survives unnamed', ($byPort['9999']['addr'] ?? null) === '*'
   && ($byPort['9999']['holder'] ?? '') === '');
ok('exactly the three genuine host listeners survive', count($ssParsed) === 3, count($ssParsed).' rows');

/* ------------------------------------------- PLAN_219: staxx_import_map_path -- */
//
// Pure: canned mounts, no Docker. A path as a container sees it is turned
// into one on the server by the container's longest mount destination that is
// a prefix on a "/" boundary.

function mnt(string $id, string $dest, string $src, string $image = 'x/y'): array {
  return ['id' => $id, 'container' => 'c-'.$id, 'image' => $image, 'dest' => $dest, 'src' => $src];
}

$m = staxx_import_map_path('/data/compose/7/f.yml', [mnt('a', '/data', '/mnt/x/data'), mnt('a', '/data/compose', '/mnt/y/c')]);
ok('map_path takes the longest matching mount of a container',
   count($m) === 1 && $m[0]['host'] === '/mnt/y/c/7/f.yml', json_encode($m));
ok('map_path: /data does not match /database/f.yml (the "/" boundary)',
   staxx_import_map_path('/database/f.yml', [mnt('a', '/data', '/mnt/x')]) === []);
ok('map_path: a mount at "/" is ignored',
   staxx_import_map_path('/data/f.yml', [mnt('a', '/', '/mnt/x'), mnt('b', '', '/mnt/z')]) === []);
$m = staxx_import_map_path('/data/f.yml', [mnt('a', '/data', '/mnt/x'), mnt('b', '/data', '/mnt/z')]);
ok('map_path: two containers give two answers',
   count($m) === 2 && $m[0]['host'] === '/mnt/x/f.yml' && $m[1]['host'] === '/mnt/z/f.yml', json_encode($m));
$m = staxx_import_map_path('/data/f.yml', [mnt('a', '/data', '/mnt/x'), mnt('b', '/data', '/mnt/z')], ['a']);
ok('map_path: a skipped container is left out', count($m) === 1 && $m[0]['container'] === 'c-b');
$m = staxx_import_map_path('/data/f.yml', [mnt('a', '/data/', '/mnt/x/')]);
ok('map_path: trailing slashes on a mount do not double up',
   count($m) === 1 && $m[0]['host'] === '/mnt/x/f.yml', json_encode($m));
$m = staxx_import_map_path('/data/f.yml', [mnt('a', '/data/f.yml', '/mnt/x/real.yml')]);
ok('map_path: a mount of the file itself maps to its source',
   count($m) === 1 && $m[0]['host'] === '/mnt/x/real.yml', json_encode($m));

ok('the tool is named from the image: Portainer, Dockge, anything else',
   staxx_import_tool('portainer/portainer-ce:latest') === 'Portainer'
   && staxx_import_tool('ghcr.io/louislam/dockge:1') === 'Dockge'
   && staxx_import_tool('nginx:alpine') === 'another compose tool');

/* ---------------------------------- PLAN_219: staxx_import_compose_projects -- */
//
// Canned Docker rows and mounts. The container ids are made up; nothing here
// asks Docker anything.

function prow(string $id, string $project, string $cfg, string $wd = '', string $env = '', string $state = 'exited'): array {
  return ['id' => $id, 'name' => 'c-'.$id, 'state' => $state, 'status' => '', 'image' => 'nginx:alpine',
          'project' => $project, 'service' => 'web', 'configFiles' => $cfg, 'configHash' => '',
          'envFile' => $env, 'workingDir' => $wd, 'health' => ''];
}
function byProject(array $rows): array {
  $o = [];
  foreach ($rows as $r) $o[$r['id']] = $r;
  return $o;
}

$CP = $T.'/cp';
mk($CP.'/hp/compose.yaml', $SVC);
mk($CP.'/hp/.env', "A=1\n");
mk($CP.'/dockge/app/compose.yaml', $SVC);
mk($CP.'/portainer-data/compose/7/docker-compose.yml', $SVC);
mk($CP.'/portainer-data/compose/7/.env', "P=1\n");
mk($CP.'/two/compose.yaml', $SVC);
mk($CP.'/two/compose.prod.yaml', "services:\n  web:\n    environment:\n      A: b\n");
mk($CP.'/bad/compose.yaml', $REFUSED);
mk($CP.'/x1/compose.yaml', $SVC);
mk($CP.'/x2/compose.yaml', $SVC);

// A stack already in StaXX, so a row for its project is skipped.
mk($root.'/zzimp-existing/compose.yaml', $SVC);
staxx_scan_stacks_reset();

$canned = [
  prow('h1', 'hostproj', $CP.'/hp/compose.yaml', $CP.'/hp'),
  prow('d1', 'dockgeproj', $CP.'/dockge/app/compose.yaml', $CP.'/dockge/app', '', 'running'),
  prow('p1', 'portstack', '/staxx-suite-mgr/compose/7/docker-compose.yml', '/staxx-suite-mgr/compose/7'),
  prow('n1', 'lostproj', '/staxx-suite-lost/compose/9/docker-compose.yml', '/staxx-suite-lost/compose/9'),
  prow('t1', 'twoproj', $CP.'/two/compose.yaml,'.$CP.'/two/compose.prod.yaml', $CP.'/two'),
  prow('b1', 'badproj', $CP.'/bad/compose.yaml', $CP.'/bad'),
  prow('s1', 'zzimp-existing', $CP.'/x1/compose.yaml', $CP.'/x1'),
  prow('s2', 'inthestore', $CP.'/x2/compose.yaml', rtrim($root, '/').'/somewhere'),
  prow('c1', 'plain', $CP.'/x1/compose.yaml', $CP.'/x1'),                                  // a Compose Manager project name
  prow('c2', 'cmbypath', $CP.'/x2/compose.yaml', STAXX_IMPORT_PROJECTS_DIR.'/cmbypath'),   // working dir under its folder
  prow('u1', '', '', ''),                                                                  // no project label at all
  prow('h2', 'hostproj', $CP.'/hp/compose.yaml', $CP.'/hp'),                               // second container, same project
];
$mounts = [
  mnt('m1', '/staxx-suite-mgr', $CP.'/portainer-data', 'portainer/portainer-ce:latest'),
  mnt('m2', '/opt/stacks', $CP.'/dockge', 'louislam/dockge:1'),
];
$rowsOut = byProject(staxx_import_compose_projects($canned, $mounts));

$h = $rowsOut['hostproj'] ?? null;
ok('a project whose file is on the server at its labelled path is found there, once',
   $h !== null && $h['file'] === $CP.'/hp/compose.yaml' && $h['ready'] === true && $h['source'] === 'compose',
   json_encode($h));
ok('...with the .env beside it', $h !== null && $h['env'] === $CP.'/hp/.env');
ok('...and no tool named when no manager container mounts it', $h !== null && $h['tool'] === 'another compose tool');

$d = $rowsOut['dockgeproj'] ?? null;
ok('a host path under a manager\'s mount names that tool (Dockge)',
   $d !== null && $d['tool'] === 'Dockge' && $d['running'] === true, json_encode($d['tool'] ?? null));

$p = $rowsOut['portstack'] ?? null;
ok('a path mapped through a manager\'s mount lands on the server file, tool Portainer',
   $p !== null && $p['ready'] === true && $p['file'] === $CP.'/portainer-data/compose/7/docker-compose.yml'
   && $p['tool'] === 'Portainer', json_encode([$p['file'] ?? null, $p['tool'] ?? null]));
ok('...and the .env beside the mapped file is found', $p !== null && $p['env'] === $CP.'/portainer-data/compose/7/.env');
ok('...the folder name is Docker\'s project name', $p !== null && $p['dest'] === 'portstack' && $p['name'] === 'portstack');

$n = $rowsOut['lostproj'] ?? null;
ok('a path that maps nowhere is not ready, with the exact sentence',
   $n !== null && $n['ready'] === false
   && in_array('StaXX could not find this project\'s files on the server. The tool that runs it keeps them at '
             . '/staxx-suite-lost/compose/9/docker-compose.yml, inside its own container.', $n['notes'], true),
   json_encode($n['notes'] ?? null));

$t = $rowsOut['twoproj'] ?? null;
ok('two config files: the first is the compose file, the second the override',
   $t !== null && $t['file'] === $CP.'/two/compose.yaml' && $t['override'] === $CP.'/two/compose.prod.yaml'
   && count($t['files']) === 2 && $t['moreFiles'] === [], json_encode([$t['file'] ?? null, $t['override'] ?? null]));

$b = $rowsOut['badproj'] ?? null;
ok('a file compose refuses stays ready, with the keep-and-flag note',
   $b !== null && $b['ready'] === true
   && in_array('Docker Compose cannot read this file yet. It will be imported and marked as needing a fix.', $b['notes'], true),
   json_encode($b['notes'] ?? null));

ok('a project that is already a StaXX stack is skipped', !isset($rowsOut['zzimp-existing']));
ok('a project whose working dir is inside the store is skipped', !isset($rowsOut['inthestore']));
ok('a project named like a Compose Manager project is skipped', !isset($rowsOut['plain']));
ok('a project whose working dir is in the Compose Manager folder is skipped', !isset($rowsOut['cmbypath']));
ok('containers with no project label are not projects', !isset($rowsOut['']));
ok('two containers of one project make one row',
   count(array_filter(staxx_import_compose_projects($canned, $mounts), fn($r) => $r['id'] === 'hostproj')) === 1);

// Two different managers that both map the path to a file that exists.
mk($CP.'/mapA/f/compose.yaml', $SVC);
mk($CP.'/mapB/f/compose.yaml', $SVC);
$two = staxx_import_compose_projects(
  [prow('q1', 'ambig', '/staxx-suite-amb/f/compose.yaml')],
  [mnt('ma', '/staxx-suite-amb', $CP.'/mapA'), mnt('mb', '/staxx-suite-amb', $CP.'/mapB')]);
ok('two managers mapping to different real files: not ready, both named',
   count($two) === 1 && $two[0]['ready'] === false
   && strpos(implode(' ', $two[0]['notes']), $CP.'/mapA/f/compose.yaml') !== false
   && strpos(implode(' ', $two[0]['notes']), $CP.'/mapB/f/compose.yaml') !== false, json_encode($two[0]['notes'] ?? null));

@exec('rm -rf '.escapeshellarg($root.'/zzimp-existing'));
staxx_scan_stacks_reset();

/* ------------------------------------------ PLAN_219: staxx_import_scan_folder -- */

$err = '';
$refusals = [
  ['/',                    'Choose the folder that holds your stacks, not the top of the server.'],
  ['/mnt',                 'Choose the folder that holds your stacks, not the top of the server.'],
  ['/mnt/user',            'Choose the folder that holds your stacks, not the top of the server.'],
  ['/boot',                'Choose the folder that holds your stacks, not the top of the server.'],
  ['/proc/self',           'Choose the folder that holds your stacks, not the top of the server.'],
  ['/mnt/remotes/x',       'That folder is on another computer on your network. Copy the stacks onto this server first.'],
  ['/mnt/rootshare/x',     'That folder is on another computer on your network. Copy the stacks onto this server first.'],
  [$T.'/no/such/folder',   'That folder does not exist on the server.'],
  ['',                     'That folder does not exist on the server.'],
];
foreach ($refusals as [$path, $sentence]) {
  // A top-level folder this box does not have is "does not exist" instead,
  // which is a fact about the box, not a wrong answer.
  if ($sentence[0] === 'C' && !is_dir($path)) { skip('scan_folder refuses "'.$path.'"', 'no such folder on this box'); continue; }
  $got = staxx_import_scan_folder($path, $err);
  ok('scan_folder refuses "'.$path.'" with its own sentence', $got === [] && $err === $sentence, $err);
}

mk($T.'/emptyscan/readme.txt', "nothing here\n");
$got = staxx_import_scan_folder($T.'/emptyscan', $err);
ok('scan_folder: an empty folder says no projects were found',
   $got === [] && $err === 'No compose projects were found in that folder or the folders directly inside it.', $err);

// The folder itself plus ONE level; hidden folders skipped; top-level name: used.
$R = $T.'/scan';
mk($R.'/compose.yaml', $SVC);
mk($R.'/alpha/docker-compose.yml', $SVC);
mk($R.'/beta/compose.yaml', "name: Custom_Name\n".$SVC);
mk($R.'/beta/compose.override.yaml', "services:\n  web:\n    environment:\n      A: b\n");
mk($R.'/beta/.env', "B=2\n");
mk($R.'/Gamma Dir/compose.yml', $SVC);
mk($R.'/bad/compose.yaml', $REFUSED);
mk($R.'/.hidden/compose.yaml', $SVC);
mk($R.'/mid/deeper/compose.yaml', $SVC);
$realR = (string)realpath($R);
$got = staxx_import_scan_folder($R, $err);
$byDir = [];
foreach ($got as $r) $byDir[$r['id']] = $r;
ok('scan_folder finds the folder itself and the folders directly inside it, and nothing deeper',
   $err === '' && (function () use ($byDir, $realR) { $k = array_keys($byDir); sort($k); return $k === [$realR, $realR.'/Gamma Dir', $realR.'/alpha', $realR.'/bad', $realR.'/beta']; })(),
   $err.' '.implode(', ', array_map(fn($k) => substr($k, strlen($realR)), array_keys($byDir))));
ok('...a project two levels down is not found', !isset($byDir[$realR.'/mid/deeper']) && !isset($byDir[$realR.'/mid']));
ok('...a hidden folder is skipped', !isset($byDir[$realR.'/.hidden']));
ok('...a top-level name: is used, lowercased', ($byDir[$realR.'/beta']['project'] ?? '') === 'custom_name',
   (string)($byDir[$realR.'/beta']['project'] ?? ''));
ok('...otherwise the folder name, cleaned the way compose cleans it',
   ($byDir[$realR.'/Gamma Dir']['project'] ?? '') === 'gammadir' && ($byDir[$realR.'/alpha']['project'] ?? '') === 'alpha',
   (string)($byDir[$realR.'/Gamma Dir']['project'] ?? ''));
ok('...override and .env are paired from the same folder',
   ($byDir[$realR.'/beta']['override'] ?? '') === $realR.'/beta/compose.override.yaml'
   && ($byDir[$realR.'/beta']['env'] ?? '') === $realR.'/beta/.env');
ok('...rows are folder rows, marked as having no containers',
   ($byDir[$realR.'/alpha']['source'] ?? '') === 'folder' && ($byDir[$realR.'/alpha']['exists'] ?? true) === false);
ok('...a file compose refuses is listed ready, with the keep-and-flag note',
   ($byDir[$realR.'/bad']['ready'] ?? false) === true
   && in_array('Docker Compose cannot read this file yet. It will be imported and marked as needing a fix.',
               $byDir[$realR.'/bad']['notes'] ?? [], true));

// The 200-folder cap: 201 subfolders, a project in the first and in the last.
for ($i = 0; $i <= 200; $i++) @mkdir($T.'/cap/'.sprintf('d%03d', $i), 0755, true);
mk($T.'/cap/d000/compose.yaml', $SVC);
mk($T.'/cap/d200/compose.yaml', $SVC);
$capRows = staxx_import_scan_folder($T.'/cap', $err);
$capRoot = (string)realpath($T.'/cap');
ok('scan_folder stops at 200 folders: the first is read, the 201st is not',
   count($capRows) === 1 && $capRows[0]['id'] === $capRoot.'/d000', json_encode(array_column($capRows, 'id')));
ok('...and the row says only the first 200 folders were read',
   count($capRows) === 1 && in_array('Only the first 200 folders were read.', $capRows[0]['notes'], true));

/* ------------------------------------------------ PLAN_219: writing a project -- */

// A Compose Manager project, byte for byte, with its override renamed to pair.
$e1 = ''; $nf = '';
ok('a Compose Manager project is written into the scratch store',
   staxx_import_write_project('zzimp-folder', 'with-override', [], $e1, $nf), $e1);
$wd = $root.'/zzimp-folder';
ok('...its compose file, override and settings are copied byte for byte',
   is_file($wd.'/docker-compose.yml') && file_get_contents($wd.'/docker-compose.yml') === file_get_contents($CM.'/with-override/docker-compose.yml')
   && file_get_contents($wd.'/docker-compose.override.yml') === file_get_contents($CM.'/with-override/docker-compose.override.yml')
   && file_get_contents($wd.'/.env') === "X=1\n");
@exec('rm -rf '.escapeshellarg($wd));
staxx_scan_stacks_reset();

// A folder row: files copied byte for byte, the reminder written, a clean file not flagged.
staxx_import_log_begin('suite write');
$e2 = ''; $nf2 = 'unset';
$ok2 = staxx_import_write_project('zzimp-folder', $realR.'/beta', [], $e2, $nf2, 'folder', $R);
staxx_import_log_end();
ok('a folder row is written into the scratch store', $ok2, $e2);
ok('...the compose file is copied byte for byte',
   file_get_contents($wd.'/compose.yaml') === file_get_contents($R.'/beta/compose.yaml'));
ok('...the override is copied byte for byte under the name that pairs with it',
   file_get_contents($wd.'/compose.override.yaml') === file_get_contents($R.'/beta/compose.override.yaml'));
ok('...the .env is copied byte for byte', file_get_contents($wd.'/.env') === "B=2\n");
$note = (string)@file_get_contents($wd.'/'.STAXX_REVIEW_FILE);
ok('...NEEDS-REVIEW.md tells the person to remove it from the tool that used to run it',
   strpos($note, 'After you take this stack over, remove it from the tool that used to run it, so the two do not both run it.') !== false);
ok('...a file compose accepts is not marked as needing a fix', $nf2 === '' && staxx_record_needs_fix($wd) === '');
ok('...the import log recorded the write', strpos(staxx_import_log_recent(), 'write folder '.$realR.'/beta as zzimp-folder: written') !== false);
@exec('rm -rf '.escapeshellarg($wd));
staxx_scan_stacks_reset();

// A refused file is kept and flagged.
$e3 = ''; $nf3 = '';
$ok3 = staxx_import_write_project('zzimp-bad', $realR.'/bad', [], $e3, $nf3, 'folder', $R);
$wd3 = $root.'/zzimp-bad';
ok('a file compose refuses is still imported', $ok3 && $e3 === '' && is_file($wd3.'/compose.yaml'), $e3);
ok('...byte for byte', file_get_contents($wd3.'/compose.yaml') === $REFUSED);
ok('...and marked as needing a fix', $nf3 !== '' && staxx_record_needs_fix($wd3) !== '', $nf3);
@exec('rm -rf '.escapeshellarg($wd3));
staxx_scan_stacks_reset();

// The browser names a folder and an id; an id the scan does not return is refused.
$e4 = '';
ok('a folder id the scan does not return is refused',
   !staxx_import_write_project('zzimp-folder', '/etc', [], $e4, $nf, 'folder', $R) && $e4 !== '' && !is_dir($root.'/zzimp-folder'), $e4);

/* ------------------------------------------------------------- the import log -- */

$logFile = STAXX_IMPORT_LOG_FILE;
@unlink($logFile);
staxx_import_log('written with no run begun');
staxx_import_log_end();
ok('staxx_import_log() before a run begins writes nothing', !file_exists($logFile));

for ($i = 1; $i <= 6; $i++) {
  staxx_import_log_begin('run '.$i);
  staxx_import_log('line '.$i);
  staxx_import_log_end();
}
$logText = (string)@file_get_contents($logFile);
ok('six runs leave the last five', preg_match_all('/^=== /m', $logText) === 5
   && strpos($logText, 'run 1 ===') === false && strpos($logText, 'run 2 ===') !== false
   && strpos($logText, 'run 6 ===') !== false, (string)preg_match_all('/^=== /m', $logText));
ok('...and a run just written reads back as recent', strpos(staxx_import_log_recent(), 'run 6 ===') !== false);

staxx_import_log_begin('big run');
for ($i = 0; $i < 3000; $i++) staxx_import_log(str_repeat('x', 100).' '.$i);
staxx_import_log_end();
clearstatcache();
$bigText = (string)@file_get_contents($logFile);
ok('a run of about 300 KB is cut to fit under 256 KB', filesize($logFile) <= 262144 && filesize($logFile) > 100000,
   filesize($logFile).' bytes');
ok('...keeping its header and a marker line saying lines were cut',
   strpos($bigText, 'big run ===') !== false
   && strpos($bigText, '[earlier lines of this run were cut to keep the log small]') !== false);

/* --------------------------------------------- PLAN_219: through real Docker -- */

if (!$DOCKER_ON) {
  skip('dummy containers through real Docker', 'set STAXX_IMPORT_CONTAINERS=1 to run');
} else {
  $real = byProject(staxx_import_compose_projects());
  $f = $real[$P.'host'] ?? null;
  ok('docker: a project with a host path is found by its labels',
     $f !== null && $f['file'] === $DK.'/scanroot/host/compose.yaml' && $f['ready'] === true && $f['exists'] === true
     && $f['running'] === false, json_encode($f));
  ok('docker: ...with the .env beside it', $f !== null && $f['env'] === $DK.'/scanroot/host/.env');

  $mp = $real[$P.'mapped'] ?? null;
  ok('docker: a path inside another container is mapped through that container\'s real mount',
     $mp !== null && $mp['ready'] === true && $mp['file'] === $DK.'/mgr/compose/7/docker-compose.yml', json_encode($mp));

  $nw = $real[$P.'nowhere'] ?? null;
  ok('docker: a path that maps nowhere is not ready',
     $nw !== null && $nw['ready'] === false && strpos(implode(' ', $nw['notes']), 'could not find this project\'s files') !== false);

  $tw = $real[$P.'two'] ?? null;
  ok('docker: two config files become a compose file and an override',
     $tw !== null && $tw['file'] === $DK.'/two/compose.yaml' && $tw['override'] === $DK.'/two/compose.override.yaml');

  $bd = $real[$P.'bad'] ?? null;
  ok('docker: a file compose refuses stays ready', $bd !== null && $bd['ready'] === true);

  // Looking in a folder skips the folder a label row already points at.
  $sc = staxx_import_scan_folder($DK.'/scanroot', $err);
  ok('docker: a folder scan does not list a project a label row already shows',
     $err === '' && array_column($sc, 'id') === [(string)realpath($DK.'/scanroot/other')], json_encode(array_column($sc, 'id')));

  // Writing a compose row (the mapped one): copied byte for byte, reminder present.
  $e5 = ''; $nf5 = '';
  $ok5 = staxx_import_write_project('zzimp-compose', $P.'mapped', [], $e5, $nf5, 'compose');
  $wd5 = $root.'/zzimp-compose';
  ok('docker: a compose row is written into the scratch store', $ok5, $e5);
  ok('...its file copied byte for byte',
     is_file($wd5.'/docker-compose.yml') && file_get_contents($wd5.'/docker-compose.yml') === $SVC);
  ok('...and the review note carries the reminder',
     strpos((string)@file_get_contents($wd5.'/'.STAXX_REVIEW_FILE), 'remove it from the tool that used to run it') !== false);
  @exec('rm -rf '.escapeshellarg($wd5));
  staxx_scan_stacks_reset();
}


echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
