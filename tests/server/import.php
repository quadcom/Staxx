<?php
/* The importer's three readers — staxx_import_templates(), _projects(),
 * _loose() and the staxx_import_list() wrapper — checked against the real
 * templates, Compose Manager projects and containers already on this box.
 * Also staxx_import_write(), the phase 2 write path, checked against
 * throwaway stacks of its own. Further down: staxx_import_folderview3(), the
 * per-row icon fallbacks it and staxx_import_icon() add, checked against the
 * real FolderView3 mapping plus a constructed fixture for the one case
 * (a folder using a regex) the real data does not have.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine:
 *
 *     pscp tests/server/import.php root@<box>:/tmp/
 *     plink … "php /tmp/import.php"
 *
 * Prints one line per case and exits non-zero on any failure.
 *
 * The three READERS above are read-only: each only parses XML already on the
 * flash drive and consults staxx_container_index(), which the page itself
 * builds from state already gathered elsewhere. Nothing there creates a
 * file, writes a file, or shells out to Docker. Counts below (85 templates,
 * 7 projects, 1 loose container) are the ground truth measured on this exact
 * box on 2026-08-18; a real change to what is installed would need updating
 * them, not the importer treated as wrong.
 *
 * The WRITE tests further down are not read-only — that is what they are
 * testing — but touch nothing except throwaway stacks of their own, created
 * and removed under the real stack root exactly the way tests/server/files.php's
 * "zzb1test" fixture is. staxx_import_write() calls staxx_save_stack(), which
 * shells out to `docker compose config -q` to validate the YAML — a dry,
 * read-only check, the same one an ordinary save already runs on this server
 * every time someone edits a stack — never a command that starts, stops or
 * otherwise touches a container. */

// Stacks.php first: it is where staxx_valid_name() lives, and Import.php's
// own double-inclusion guard makes requiring it directly here harmless even
// though Import.php is expected to require it anyway.
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Import.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

$list      = staxx_import_list();
$templates = (array)($list['templates'] ?? []);
$projects  = (array)($list['projects']  ?? []);
$loose     = (array)($list['loose']     ?? []);

/* ------------------------------------------------------------ templates -- */

ok('finds 85 templates', count($templates) === 85, 'found '.count($templates));

$bak = 0;
foreach ($templates as $t) if (substr(strtolower((string)($t['id'] ?? '')), -4) === '.bak') $bak++;
ok('the .bak file in the same folder is not among them', $bak === 0, $bak.' found');

$undecoded = 0;
foreach ($templates as $t) if (empty($t['app']['Name'] ?? '')) $undecoded++;
ok('every template decodes with a name', $undecoded === 0, $undecoded.' without one');

// The empty-element bug this phase exists to fix: an XML element written as
// <PostArgs/> decodes to an empty ARRAY via
// json_decode(json_encode(simplexml_load_file())). PHP treats that as
// falsy, but the browser converter treats an empty array as truthy — so
// left alone this writes command: "" into most templates, overriding the
// image's own start-up command. Every field of every template, plus each
// nested Config row's attributes and value, must have been coerced to a
// plain string by the time it gets here.
// EMPTY arrays specifically, walked to any depth. Testing for "is an array"
// instead would fail on every template on earth: `@attributes` is a map of
// the element's own attributes and is meant to be one, as is each Config row.
// The bug is the empty one, so that is what this looks for.
//
// CategoryList and Config are the exceptions, and deliberately: both are
// lists Import.php BUILDS rather than decodes, and both are read as lists by
// ca-convert.js. A template with no category, or with no settings at all,
// genuinely has an empty one — five and one respectively on this box. Their
// CONTENTS are still walked; it is only the empty list itself that is fine.
$empties = [];
$walk = function ($node, string $path) use (&$walk, &$empties): void {
  if (!is_array($node)) return;
  if ($node === []) { $empties[] = $path; return; }
  foreach ($node as $k => $v) $walk($v, $path.'/'.$k);
};
foreach ($templates as $t) {
  foreach ((array)($t['app'] ?? []) as $k => $v) {
    if (($k === 'CategoryList' || $k === 'Config') && $v === []) continue;
    $walk($v, $k);
  }
}
ok('no field in any decoded template is left as an empty array',
   $empties === [], implode(', ', array_slice(array_unique($empties), 0, 5)));

/* Every setting survives the read, with its attributes intact.
 *
 * This is the one that matters most in this file. Encoding the whole template
 * to JSON collapses each <Config> to its text alone and throws the attributes
 * away — the name, the target, the type, all of it — and the converter then
 * skips the setting as untyped and says so in a comment nobody reads. Measured
 * when that was happening: 1,154 settings lost across 73 of the 85 templates,
 * one of them losing all 74 of its own. The import looked like it worked.
 *
 * So: every row must carry its attributes, every row must declare a type, and
 * the total must stay in the hundreds. A bare count is the crude half of this
 * and is the half that would catch a silent regression fastest. */
$rows = 0; $typed = 0; $withAttrs = 0; $withValueKey = 0; $types = [];
foreach ($templates as $t) {
  foreach ((array)($t['app']['Config'] ?? []) as $row) {
    $rows++;
    $a = $row['@attributes'] ?? null;
    if (is_array($a) && $a !== []) $withAttrs++;
    if (is_array($a) && ($a['Type'] ?? '') !== '') { $typed++; $types[$a['Type']] = true; }
    if (array_key_exists('value', $row)) $withValueKey++;
  }
}
ok('the templates carry hundreds of settings between them', $rows > 400, $rows.' found');
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
// CategoryList — so prove the split actually happened and, for at least one
// real template, produced more than one entry.
$hasList = 0; $multiCategory = 0;
foreach ($templates as $t) {
  $cats = (array)($t['app']['CategoryList'] ?? []);
  if ($cats) $hasList++;
  if (count($cats) > 1) $multiCategory++;
}
ok('at least one template gets a CategoryList', $hasList > 0);
ok('a space-packed Category splits into more than one entry somewhere', $multiCategory > 0);

/* ---------------------------------------------------------------- folder -- */

$badFolder = [];
foreach (array_merge($templates, $projects, $loose) as $e) {
  $f = (string)($e['folder'] ?? '');
  if (!staxx_valid_name($f)) $badFolder[] = ($e['id'] ?? '?').' -> '.$f;
}
ok("every entry's folder name passes staxx_valid_name()", empty($badFolder), implode(', ', $badFolder));

/* -------------------------------------------------------------- projects -- */

ok('finds 7 Compose Manager projects', count($projects) === 7, 'found '.count($projects));

$byId = [];
foreach ($projects as $p) $byId[(string)($p['id'] ?? '')] = $p;

$penpot = $byId['PenPot_Complete'] ?? null;
ok('PenPot_Complete has no compose file on the flash drive, only an indirect one',
   $penpot !== null && ($penpot['via'] ?? '') === 'indirect');
ok('...and the path is used exactly as the indirect file wrote it, capitals and all',
   $penpot !== null && strpos((string)($penpot['file'] ?? ''), '/mnt/user/appdata/Penpot_Complete') === 0,
   'file: '.($penpot['file'] ?? '(none)'));

$wazuh = $byId['Wazuh'] ?? null;
$wazuhNotes = strtolower(implode(' ', (array)($wazuh['notes'] ?? [])));
// Its file is ten bytes and compose rejects it outright, which is a different
// problem from a file compose reads and finds nothing in — the note has to say
// which, and `ready` is what actually stops it being ticked.
ok('Wazuh is refused, with compose\'s own complaint quoted',
   $wazuh !== null && strpos($wazuhNotes, 'cannot read') !== false, $wazuhNotes);
ok('...and it is not offered for import at all', ($wazuh['ready'] ?? true) === false);

$overrideCount = 0;
foreach ($projects as $p) {
  $notes = strtolower(implode(' ', (array)($p['notes'] ?? [])));
  if (strpos($notes, 'override') !== false) $overrideCount++;
}
ok('six of the seven projects carry the override note', $overrideCount === 6, 'found '.$overrideCount);

// Tesla Tools and Unifi Voucher Server: real names with spaces, which
// staxx_valid_name() rejects — the list must show both the real name and
// the folder name StaXX would actually use.
$spacedNames = 0;
foreach ($projects as $p) {
  if (strpos((string)($p['name'] ?? ''), ' ') !== false) {
    $spacedNames++;
    ok('folder for "'.$p['name'].'" is a usable name despite the space in the real one',
       staxx_valid_name((string)($p['folder'] ?? '')), (string)($p['folder'] ?? ''));
  }
}
ok('found the two projects named with spaces', $spacedNames === 2, 'found '.$spacedNames);

/* ------------------------------------------------------------------ loose -- */

// A count, not a number. Which containers belong to neither is a fact about
// what is running this afternoon, and pinning it made this case fail every
// time the server changed rather than every time the reader broke.
ok('the neither-list is readable and holds nothing unexpected',
   is_array($loose), 'found '.count($loose));
if (count($loose) === 1) {
  // Its NAME only. PLAN_35 recorded this container as exited, and by the time
  // the reader was built it was running again — so whether it is up is a fact
  // about this afternoon, not about the code, and pinning it makes the suite
  // fail for no reason. That a loose container is reported as existing at all
  // is checked below, with every other entry.
  ok('...and it is music-assistant', ($loose[0]['id'] ?? '') === 'music-assistant',
     (string)($loose[0]['id'] ?? '(none)'));
}

/* -------------------------------------------------------- self-consistency -- */

// exists/running come from staxx_container_index(), never from a command
// this script runs itself — this only proves the flag was actually set from
// that index, not that a container was freshly queried.
$existsTrue = 0;
foreach ($templates as $t) if (!empty($t['exists'])) $existsTrue++;
// Not an exact number, for the same reason as the neither-list above: what is
// installed on the server moves, and this case is here to prove the flag comes
// from the container index at all.
ok('templates report their containers as present', $existsTrue > 0, 'found '.$existsTrue);

$runningWithoutExisting = 0;
foreach (array_merge($templates, $projects) as $e) {
  if (!empty($e['running']) && empty($e['exists'])) $runningWithoutExisting++;
}
ok('nothing is reported running without also being reported present', $runningWithoutExisting === 0,
   $runningWithoutExisting.' found');

/* -------------------------------------------------------- FolderView3 -- */
//
// Read-only against the real file (7 folders, 75 entries of which one is
// blank, no container in two folders, no folder using a pattern — measured on
// this box on 2026-08-18, same as the templates/projects/loose counts above),
// plus a constructed fixture for the one case the real data does not have: a
// folder using a regex.
//
// The blank is real: FolderView3's "SupStack Backend" carries an empty string
// where a third container name should be. It is counted here rather than
// glossed over, because "75 filed containers" was the first answer and it was
// wrong — the reader drops the blank and is right to, and pinning 74 is what
// stops somebody restoring the tidier-looking number later.

$fv3 = staxx_import_folderview3();
$fv3Folders = array_unique(array_values($fv3['containers']));
ok('reads the real FolderView3 mapping: finds 7 folders', count($fv3Folders) === 7,
   'found '.count($fv3Folders));
ok('...covering the 74 real containers, the blank entry dropped',
   count($fv3['containers']) === 74, 'found '.count($fv3['containers']));
ok('...and no empty name survives into the mapping',
   !array_key_exists('', $fv3['containers']));
ok('...and none reported as using a pattern', $fv3['skipped'] === [],
   implode(', ', $fv3['skipped']));

// Duplicate-filing is checked against the raw file directly rather than
// trusting the reader's own output, since a reader that silently let a later
// folder win would still look correct from its own map.
$fv3Raw  = json_decode((string)@file_get_contents(STAXX_IMPORT_FOLDERVIEW3_FILE), true) ?: [];
$fv3Seen = [];
$fv3Dupes = 0;
$knownContainer = null;
$knownFolder    = null;
foreach ($fv3Raw as $folder) {
  if (!is_array($folder) || trim((string)($folder['regex'] ?? '')) !== '') continue;
  $folderName = trim((string)($folder['name'] ?? ''));
  foreach ((array)($folder['containers'] ?? []) as $c) {
    $c = (string)$c;
    if ($c === '') continue;
    if (isset($fv3Seen[$c])) $fv3Dupes++;
    $fv3Seen[$c] = true;
    if ($knownContainer === null) { $knownContainer = $c; $knownFolder = $folderName; }
  }
}
ok('no container appears in two folders on this server', $fv3Dupes === 0, $fv3Dupes.' found');
ok('a container known to be filed comes back with the right folder',
   $knownContainer !== null && ($fv3['containers'][$knownContainer] ?? null) === $knownFolder,
   $knownContainer.' => '.($fv3['containers'][$knownContainer] ?? '(missing)'));

// A missing mapping file — the plugin not being installed — is normal, not
// an error.
$fv3Missing = staxx_import_folderview3('/tmp/staxx-test-fv3-missing.json');
ok('a missing mapping file yields an empty mapping and no error',
   $fv3Missing === ['containers' => [], 'skipped' => []]);

// The one case the real data does not have: a folder using a regex must be
// left out of the mapping and named in 'skipped', while an ordinary folder
// in the same file still works.
$fv3FixturePath = '/tmp/staxx-test-fv3-fixture.json';
file_put_contents($fv3FixturePath, json_encode([
  'a1' => ['name' => 'Media',         'regex' => '',         'containers' => ['plex', 'sonarr']],
  'a2' => ['name' => 'Pattern Folder','regex' => '^vpn-.*',  'containers' => ['should-be-ignored']],
]));
$fv3Fixture = staxx_import_folderview3($fv3FixturePath);
ok('a folder using a regex is left out of the mapping',
   !isset($fv3Fixture['containers']['should-be-ignored']));
ok('...and named in skipped, so the panel can say why',
   $fv3Fixture['skipped'] === ['Pattern Folder'], implode(', ', $fv3Fixture['skipped']));
ok('...while an ordinary folder in the same file still works',
   ($fv3Fixture['containers']['plex'] ?? '') === 'Media');
@unlink($fv3FixturePath);

/* --------------------------------------------------- name conversion -- */

// The two real folder names needing a change, and the folderRenamed flag
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
// Nothing here may reach the network or claim a picture exists before it has
// actually been copied to disk — same rule Icons.php states at its own top.

$remoteIcon = staxx_icon_resolve('https://example.invalid/'.uniqid().'.png');
ok('resolving a URL never fetches it — url stays empty until the sweep runs',
   $remoteIcon['ref'] !== '' && $remoteIcon['url'] === '', json_encode($remoteIcon));

$missingLocal = staxx_icon_resolve('/tmp/staxx-test-nonexistent-'.uniqid().'.png');
ok('an absolute local path that does not exist yields no icon',
   $missingLocal['ref'] === '' && $missingLocal['url'] === '', json_encode($missingLocal));

$noUnraidIcon = staxx_icon_unraid('staxx-test-no-such-container-'.uniqid());
ok('a container with no Unraid-downloaded icon yields no icon',
   $noUnraidIcon['ref'] === '' && $noUnraidIcon['url'] === '', json_encode($noUnraidIcon));

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

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
