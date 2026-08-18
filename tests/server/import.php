<?php
/* The importer's three readers — staxx_import_templates(), _projects(),
 * _loose() and the staxx_import_list() wrapper — checked against the real
 * templates, Compose Manager projects and containers already on this box.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine:
 *
 *     pscp tests/server/import.php root@<box>:/tmp/
 *     plink … "php /tmp/import.php"
 *
 * Prints one line per case and exits non-zero on any failure.
 *
 * READ-ONLY. Every reader only parses XML already on the flash drive and
 * consults staxx_container_index(), which the page itself builds from state
 * already gathered elsewhere — nothing here creates a file, writes a file,
 * or shells out to Docker. Counts below (85 templates, 7 projects, 1 loose
 * container) are the ground truth measured on this exact box on 2026-08-18;
 * a real change to what is installed would need updating them, not the
 * importer treated as wrong. */

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
ok('Wazuh is flagged as an empty project holding no services',
   $wazuh !== null && strpos($wazuhNotes, 'no services') !== false, $wazuhNotes);

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

ok('finds 1 container belonging to neither a template nor a project',
   count($loose) === 1, 'found '.count($loose));
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
ok('70 templates report a container present', $existsTrue === 70, 'found '.$existsTrue);

$runningWithoutExisting = 0;
foreach (array_merge($templates, $projects) as $e) {
  if (!empty($e['running']) && empty($e['exists'])) $runningWithoutExisting++;
}
ok('nothing is reported running without also being reported present', $runningWithoutExisting === 0,
   $runningWithoutExisting.' found');

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
