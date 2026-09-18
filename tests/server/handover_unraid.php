<?php
/* PLAN_165 §1-4 — the Unraid-template half of the handover: staxx_unraid_
 * template_for(), the Auto Update Applications entry remove/restore pair,
 * staxx_handover_unraid_hold()/_release(), the 'Unraid-N' line in staxx_
 * handover_write()/read(), and staxx_handover_foreign(). Checked against the
 * real installed Stacks.php.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. STAXX_UNRAID_
 * TEMPLATES_DIR and STAXX_AUTOUPDATE_FILE are both overridable by env the
 * same way STAXX_AUTOSTART_FILE is (see tests/server/autostart.php): a test
 * box has no /boot and no ca.update.applications plugin, so this points both
 * at plain files under /tmp instead, set as environment variables BEFORE
 * Stacks.php is required — it reads them into constants on first include,
 * so setting them from inside this script would already be too late.
 * STORE_ROOT is left exactly as the real server has it configured: the
 * "held" copy a successful hold() writes lands under the real store's own
 * config/unraid-templates/, the same folder a real handover would use, so
 * every file this suite puts there is removed again on every exit path,
 * named "zzd2…" so it can never collide with another suite's fixtures.
 *
 *     pscp tests/server/handover_unraid.php root@<box>:/tmp/
 *     plink … "php /tmp/handover_unraid.php"
 *
 * Prints one line per case and exits non-zero on any failure.
 *
 * MUST NEVER RUN DOCKER in the sense of a command that touches a real
 * container: nothing here calls stop, start, rename, rm or compose up/down
 * against anything. staxx_handover_foreign() is exercised with an injected
 * container list throughout, exactly the way staxx_handover_targets()
 * already allows in tests/server/handover.php, so Docker is never asked
 * anything at all.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */

putenv('STAXX_UNRAID_TEMPLATES_DIR=/tmp/staxx-tpl-test');
putenv('STAXX_AUTOUPDATE_FILE=/tmp/staxx-cau-test.json');

require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
// staxx_intruder_compare() (PLAN_166 §1) calls into staxx_pending_diff() —
// Stacks.php does not require Pending.php back, the same one-directional
// relationship action.php already has to satisfy before dispatching, so this
// suite has to bring it in too. Pending.php requires CrossLinks.php itself.
require_once '/usr/local/emhttp/plugins/staxx/include/Pending.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

/* ------------------------------------------------------------ fixtures --- */

$tplDir  = STAXX_UNRAID_TEMPLATES_DIR;   // /tmp/staxx-tpl-test, from the env override above
$cauFile = STAXX_AUTOUPDATE_FILE;        // /tmp/staxx-cau-test.json, likewise
$heldDir = staxx_held_templates_dir();   // the REAL store's config/unraid-templates/
$root    = staxx_stack_root();

// containers.zzd2app is written LAST on purpose: staxx_autoupdate_entry_
// restore() puts a removed key back with $data['containers'][$name] = $entry,
// which appends at the end of a PHP array whose key was unset — so only a
// name that was already last comes back at the exact same byte offset,
// which is what the "byte-for-byte" cases below need to assert against.
$cauFixture = [
  'containers' => [
    'zzd2other' => ['name' => 'zzd2other', 'update' => true],
    'zzd2app'   => ['name' => 'zzd2app',   'update' => true],
  ],
];
$cauFixtureJson = json_encode($cauFixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

function xmlTemplate(string $name): string {
  return "<?xml version=\"1.0\"?>\n<Container>\n  <Name>$name</Name>\n  <Repository>alpine</Repository>\n</Container>\n";
}

// Every fixture rewritten fresh — called once up front, and again before any
// case that would otherwise run against a file an earlier case already moved
// or edited, so cases can be read top to bottom without tracking side effects
// between them.
function resetFixtures(string $tplDir, string $cauFile, string $cauFixtureJson, string $heldDir): void {
  @exec('rm -rf '.escapeshellarg($tplDir));
  mkdir($tplDir, 0755, true);
  file_put_contents($tplDir.'/my-zzd2app.xml',   xmlTemplate('zzd2app'));
  file_put_contents($tplDir.'/my-zzd2other.xml', xmlTemplate('zzd2other'));
  file_put_contents($tplDir.'/my-zzd2dup-a.xml', xmlTemplate('zzd2dup'));
  file_put_contents($tplDir.'/my-zzd2dup-b.xml', xmlTemplate('zzd2dup'));
  file_put_contents($cauFile, $cauFixtureJson);
  // Never leave a held copy from an earlier failed run sitting in the REAL
  // store — same "zz…" fixtures only, never a wider sweep of that folder.
  foreach (['my-zzd2app.xml', 'my-zzd2app2.xml'] as $f) {
    if (is_file($heldDir.'/'.$f)) @unlink($heldDir.'/'.$f);
  }
}

resetFixtures($tplDir, $cauFile, $cauFixtureJson, $heldDir);

register_shutdown_function(function () use ($tplDir, $cauFile, $heldDir, $root) {
  @exec('rm -rf '.escapeshellarg($tplDir));
  @unlink($cauFile);
  foreach (['my-zzd2app.xml', 'my-zzd2app2.xml'] as $f) {
    if (is_file($heldDir.'/'.$f)) @unlink($heldDir.'/'.$f);
  }
  foreach (['zzd2roundtrip', 'zzd2norestart', 'zzd2known', 'zzd2unread'] as $name) {
    @exec('rm -rf '.escapeshellarg($root.'/'.$name));
  }
});

/* --------------------------------------------------- staxx_unraid_template_for -- */

ok('a name matched by exactly one template returns its path',
   staxx_unraid_template_for('zzd2app') === ['path' => $tplDir.'/my-zzd2app.xml', 'count' => 1]);

ok('a name no template claims returns count 0 and no path',
   staxx_unraid_template_for('zzd2none') === ['path' => '', 'count' => 0]);

ok('a name two templates both claim returns count 2 and no path — guessing would move the wrong app',
   staxx_unraid_template_for('zzd2dup') === ['path' => '', 'count' => 2]);

/* ------------------------------------- staxx_autoupdate_entry_remove/restore -- */

$removed = staxx_autoupdate_entry_remove('zzd2app');
ok('remove hands back the removed entry, verbatim',
   $removed === ['name' => 'zzd2app', 'update' => true], json_encode($removed));

$afterRemove = $cauFixture;
unset($afterRemove['containers']['zzd2app']);
$afterRemoveJson = json_encode($afterRemove, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
ok('the rest of the Auto Update file is byte-identical apart from the removed key',
   file_get_contents($cauFile) === $afterRemoveJson);

ok('restore puts the entry back', staxx_autoupdate_entry_restore('zzd2app', $removed));
ok('…and the file is back to its original bytes, in the same key order',
   file_get_contents($cauFile) === $cauFixtureJson);

ok('removing a name with no entry finds nothing to remove',
   staxx_autoupdate_entry_remove('zzd2nothing') === null);

/* --------------------------------------------- staxx_handover_unraid_hold -- */

resetFixtures($tplDir, $cauFile, $cauFixtureJson, $heldDir);

$notes = [];
$holdTargets = [
  ['original' => 'zzd2app', 'setaside' => 'zzd2app-before-staxx', 'wasRunning' => true, 'restart' => 'always'],
];
$holdResult = staxx_handover_unraid_hold($holdTargets, $notes);

ok('hold() returns one record per target', is_array($holdResult) && count($holdResult) === 1, json_encode($holdResult));

$rec = $holdResult[0] ?? [];
ok('the record names the template\'s original and held paths',
   ($rec['template'] ?? '') === $tplDir.'/my-zzd2app.xml'
   && ($rec['held'] ?? '') === $heldDir.'/my-zzd2app.xml', json_encode($rec));
ok('the template file has actually moved',
   !is_file($tplDir.'/my-zzd2app.xml') && is_file($heldDir.'/my-zzd2app.xml'));
ok('the record carries the removed Auto Update entry, verbatim',
   ($rec['autoupdate'] ?? null) === ['name' => 'zzd2app', 'update' => true], json_encode($rec));
ok('the Auto Update file no longer lists it',
   file_get_contents($cauFile) === $afterRemoveJson);
ok('a single clean match leaves no notes behind',
   $notes === [], json_encode($notes));

/* ------------------------------------------ staxx_handover_unraid_release -- */

$sentences = staxx_handover_unraid_release($rec);
ok('release puts the sentence that the template is back',
   in_array('Its Unraid template is back where it was.', $sentences, true), json_encode($sentences));
ok('…and that the Auto Update entry is back',
   in_array('It is back on Auto Update Applications\' list.', $sentences, true), json_encode($sentences));
ok('the template file is back at its original path',
   is_file($tplDir.'/my-zzd2app.xml') && !is_file($heldDir.'/my-zzd2app.xml'));
ok('the Auto Update file is back to its original bytes',
   file_get_contents($cauFile) === $cauFixtureJson);

// A second app, held the same way, but with something else already sitting
// at the original path by the time release() is asked to put it back —
// exactly the "rebuilt while the question was open" case. Neither file
// may be touched: the held copy is still the only safe record of the
// template, so overwriting the newcomer would just trade one loss for
// another.
file_put_contents($tplDir.'/my-zzd2app2.xml', xmlTemplate('zzd2app2'));
$notes2 = [];
$holdResult2 = staxx_handover_unraid_hold(
  [['original' => 'zzd2app2', 'setaside' => 'zzd2app2-before-staxx', 'wasRunning' => false, 'restart' => 'no']],
  $notes2
);
$rec2 = $holdResult2[0] ?? [];
ok('the second app also held cleanly',
   is_file($heldDir.'/my-zzd2app2.xml') && !is_file($tplDir.'/my-zzd2app2.xml'), json_encode($rec2));

$intruderText = xmlTemplate('zzd2app2-intruder');
file_put_contents($tplDir.'/my-zzd2app2.xml', $intruderText);   // something else rebuilt a template here

$sentences2 = staxx_handover_unraid_release($rec2);
ok('release leaves both files and says so, rather than overwriting the newcomer',
   strpos(implode(' ', $sentences2), 'another file has appeared') !== false, json_encode($sentences2));
ok('the newcomer at the original path is untouched',
   file_get_contents($tplDir.'/my-zzd2app2.xml') === $intruderText);
ok('the held copy is still exactly where it was',
   is_file($heldDir.'/my-zzd2app2.xml'));

@unlink($tplDir.'/my-zzd2app2.xml');
@unlink($heldDir.'/my-zzd2app2.xml');

/* -------------------------------------- the Unraid-N line's round trip --- */

$roundtripDir = $root.'/zzd2roundtrip';
@exec('rm -rf '.escapeshellarg($roundtripDir));
mkdir($roundtripDir, 0755, true);

$rtTargets = [
  [
    'original' => 'zzd2rt-old', 'setaside' => 'zzd2rt-old-before-staxx', 'wasRunning' => true, 'restart' => 'always',
    'unraid'   => ['template' => '/boot/config/plugins/dockerMan/templates-user/my-zzd2rt.xml',
                   'held'     => $heldDir.'/my-zzd2rt.xml',
                   'autoupdate' => ['name' => 'zzd2rt-old', 'update' => true]],
  ],
  [
    'original' => 'zzd2rt-second', 'setaside' => 'zzd2rt-second-before-staxx', 'wasRunning' => false, 'restart' => 'no',
    'unraid'   => ['template' => '', 'held' => '', 'autoupdate' => null],
  ],
];
ok('writes a handover note carrying Unraid-N lines',
   staxx_handover_write($roundtripDir, $rtTargets, '2026-09-18T00:00:00+00:00'));

$rtBack = staxx_handover_read($roundtripDir);
// Compared field by field rather than as whole arrays: the note also carries
// fields other plans own (the restart policy), and this suite is about the
// Unraid record alone — a whole-array check would fail on any branch whose
// note carries a different set of them.
$rtKeys = ['original', 'setaside', 'wasRunning', 'unraid'];
$rtGot  = array_map(function ($t) use ($rtKeys) {
  $out = [];
  foreach ($rtKeys as $k) $out[$k] = $t[$k] ?? null;
  return $out;
}, $rtBack['targets'] ?? []);
$rtWant = array_map(function ($t) use ($rtKeys) {
  $out = [];
  foreach ($rtKeys as $k) $out[$k] = $t[$k] ?? null;
  return $out;
}, $rtTargets);
ok('reads back the same targets, unraid record included, in order',
   $rtGot === $rtWant, json_encode($rtBack));

@exec('rm -rf '.escapeshellarg($roundtripDir));

// A note written before this plan carries no Unraid-N line at all.
$noUnraidDir = $root.'/zzd2norestart';
@exec('rm -rf '.escapeshellarg($noUnraidDir));
mkdir($noUnraidDir, 0755, true);
file_put_contents($noUnraidDir.'/'.STAXX_HANDOVER_FILE,
  "# Handover in progress\n\nOriginal-1: zzd2old\nSetAside-1: zzd2old-before-staxx\n"
  . "WasRunning-1: yes\nRestart-1: always\nWhen: 2026-09-18T00:00:00+00:00\n"
);
$noUnraidBack = staxx_handover_read($noUnraidDir);
ok('a note with no Unraid line reads that target\'s unraid record as null',
   // array_key_exists, not ?? — the value being looked for IS null, and a
   // null-coalescing default can never tell "present and null" from "absent".
   array_key_exists('unraid', $noUnraidBack['targets'][0] ?? [])
     && $noUnraidBack['targets'][0]['unraid'] === null, json_encode($noUnraidBack));
@exec('rm -rf '.escapeshellarg($noUnraidDir));

/* ------------------------------------------------- staxx_handover_foreign -- */

// $rel only ever has to pass staxx_valid_path() and name a leaf whose
// project is known ahead of time — the function never reads the stack's
// own compose file, so nothing needs to exist on disk for this.
$foreignRel  = 'zzd2foreign';                                    // project name: "zzd2foreign"
$foreignProj = staxx_project_name(staxx_path_leaf($foreignRel));

$foreignTargets = [
  ['original' => 'zzd2ok'],          // held by this stack's own project — not foreign
  ['original' => 'zzd2gone'],        // no container at all — foreign
  ['original' => 'zzd2dockerman'],   // held, but no project at all — foreign
  ['original' => 'zzd2elsewhere'],   // held by a different project entirely — foreign
];
$foreignContainers = [
  'zzd2ok'         => ['running' => true, 'project' => $foreignProj],
  'zzd2dockerman'  => ['running' => true, 'project' => ''],
  'zzd2elsewhere'  => ['running' => true, 'project' => 'someone-elses-project'],
  // zzd2gone is absent from this list entirely.
];

ok('foreign() clears a target held by the stack\'s own project',
   !in_array('zzd2ok', staxx_handover_foreign($foreignRel, $foreignTargets, $foreignContainers), true));
ok('foreign() names a target with no container at all',
   in_array('zzd2gone', staxx_handover_foreign($foreignRel, $foreignTargets, $foreignContainers), true));
ok('foreign() names a target held with no project (dockerman)',
   in_array('zzd2dockerman', staxx_handover_foreign($foreignRel, $foreignTargets, $foreignContainers), true));
ok('foreign() names a target held by a different project',
   in_array('zzd2elsewhere', staxx_handover_foreign($foreignRel, $foreignTargets, $foreignContainers), true));

/* -------------------------------------------- staxx_pending_diff / _empty -- */

// Driven directly with staged live/file arrays, exactly as PLAN_166's own
// proof section prefers: this needs no Docker at all, and keeps the suite
// honest about testing the comparison itself rather than a container round
// trip. staxx_intruder_compare() below calls this same function, so a case
// proven here can never disagree with what that one reports.
$baseLive = ['image' => 'alpine:3.20', 'env' => ['TZ' => 'UTC'],
             'ports' => ['8080:80/tcp'], 'volumes' => ['/data:/data']];
$baseFile = $baseLive;   // identical on every axis

ok('an identical live/file pair diffs to nothing, and reads as the same',
   staxx_pending_diff_empty(staxx_pending_diff($baseLive, $baseFile)));

$imageFile = $baseFile; $imageFile['image'] = 'alpine:3.21';
$imageDiff = staxx_pending_diff($baseLive, $imageFile);
ok('an image change is named in the diff, and the pair is not the same',
   $imageDiff['image'] === ['from' => 'alpine:3.20', 'to' => 'alpine:3.21']
   && !staxx_pending_diff_empty($imageDiff), json_encode($imageDiff));

$envFile = $baseFile; $envFile['env'] = ['TZ' => 'Europe/London'];
$envDiff = staxx_pending_diff($baseLive, $envFile);
ok('an environment value change is named in the diff, and the pair is not the same',
   $envDiff['env'] === [['name' => 'TZ', 'from' => 'UTC', 'to' => 'Europe/London']]
   && !staxx_pending_diff_empty($envDiff), json_encode($envDiff));

$portFile = $baseFile; $portFile['ports'] = ['8081:80/tcp'];
$portDiff = staxx_pending_diff($baseLive, $portFile);
ok('a port change is named in the diff, and the pair is not the same',
   $portDiff['ports'] === ['added' => ['8081:80/tcp'], 'removed' => ['8080:80/tcp']]
   && !staxx_pending_diff_empty($portDiff), json_encode($portDiff));

$volumeFile = $baseFile; $volumeFile['volumes'] = ['/data2:/data'];
$volumeDiff = staxx_pending_diff($baseLive, $volumeFile);
ok('a volume change is named in the diff, and the pair is not the same',
   $volumeDiff['volumes'] === ['added' => ['/data2:/data'], 'removed' => ['/data:/data']]
   && !staxx_pending_diff_empty($volumeDiff), json_encode($volumeDiff));

/* ------------------------------------------------- staxx_intruder_compare -- */

// A name no service in this stack's file claims at all — never asks Docker
// anything, the same short-circuit staxx_handover_targets() itself takes.
$knownRel = 'zzd2known';
$knownDir = $root.'/'.$knownRel;
@exec('rm -rf '.escapeshellarg($knownDir));
mkdir($knownDir, 0755, true);
file_put_contents($knownDir.'/compose.yaml',
  "services:\n  a:\n    image: alpine:3.20\n    container_name: zzd2known-app\n");

$cmpUnknown = staxx_intruder_compare($knownRel, 'zzd2known-nobody-claims');
ok('a name no service in the file claims is not known',
   ($cmpUnknown['known'] ?? true) === false, json_encode($cmpUnknown));

@exec('rm -rf '.escapeshellarg($knownDir));

// A real container measured against a service that describes something else
// entirely: every port and volume it has is one the file does not ask for, so
// the comparison must come back "not the same" with those named. The container
// name is borrowed, read-only, from whatever is already on this box, the same
// way tests/server/handover.php borrows one for its own "belongs to another
// project" case — nothing is created, changed or removed.
//
// The `unreadable` branch itself is NOT staged here. Every route into it needs
// Docker or compose to misbehave on demand (inspect refusing, `compose config`
// failing, the file no longer resolving), and faking that means mutating the
// box. What matters about it is the direction it fails in, and that is a plain
// read of staxx_intruder_compare(): every one of those returns carries
// same=false. A case that cannot make the condition it claims to test is worse
// than no case at all — the first draft of this one asserted `unreadable` and
// passed a `build:`-only service, which compose resolves perfectly well
// (2026-09-18).
$borrowed = '';
foreach (staxx_docker_container_names() as $borrowedName => $info) { $borrowed = $borrowedName; break; }

if ($borrowed === '') {
  ok('SKIPPED — no container at all on this box to borrow a name from', true);
} else {
  $unreadRel = 'zzd2unread';
  $unreadDir = $root.'/'.$unreadRel;
  @exec('rm -rf '.escapeshellarg($unreadDir));
  mkdir($unreadDir, 0755, true);
  file_put_contents($unreadDir.'/compose.yaml',
    "services:\n  ghost:\n    build: .\n    container_name: ".$borrowed."\n");

  $cmpUnread = staxx_intruder_compare($unreadRel, $borrowed);
  $unreadDiff = $cmpUnread['diff'] ?? [];
  ok('a container that does not match the service claiming its name is not the same',
     ($cmpUnread['known'] ?? false) === true
     && ($cmpUnread['same'] ?? true) === false
     && !staxx_pending_diff_empty($unreadDiff), json_encode($cmpUnread));
  ok('...and it says when that container was built and when the file last changed',
     ($cmpUnread['builtAt'] ?? 0) > 0 && ($cmpUnread['fileAt'] ?? 0) > 0,
     'builtAt='.($cmpUnread['builtAt'] ?? 0).' fileAt='.($cmpUnread['fileAt'] ?? 0));

  @exec('rm -rf '.escapeshellarg($unreadDir));
}

// NOTE — the plan also asks for a case proving the job script staxx_finish_
// handover() builds BEGINS with the intruder's removal, read as text and
// never run, the way tests/server/handover.php already does for staxx_
// handover_script(). staxx_finish_handover() has no equivalent: it builds
// its script and hands it straight to `exec('setsid sh -c … &')` in the
// same breath, returning only a job id, with no seam that hands the text
// back unexecuted. Proving the ordering without running a real docker
// command needs that seam factored out first (mirroring staxx_handover_
// script()) — left undone here rather than guessed at or run for real.

/* -------------------------------------------------------------- cleanup --- */

@exec('rm -rf '.escapeshellarg($tplDir));
@unlink($cauFile);
foreach (['my-zzd2app.xml', 'my-zzd2app2.xml'] as $f) {
  if (is_file($heldDir.'/'.$f)) @unlink($heldDir.'/'.$f);
}
ok('nothing left behind in the real store\'s held-templates folder',
   !is_file($heldDir.'/my-zzd2app.xml') && !is_file($heldDir.'/my-zzd2app2.xml'));

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
