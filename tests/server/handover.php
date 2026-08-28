<?php
/* The handover: staxx_handover_targets(), the set-aside name, the state
 * file's round trip, the script text, and every refusal in
 * staxx_start_handover()/staxx_finish_handover().
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine:
 *
 *     pscp tests/server/handover.php root@<box>:/tmp/
 *     plink … "php /tmp/handover.php"
 *
 * Prints one line per case and exits non-zero on any failure. Creates and
 * removes its own stacks, all named "zzd1…" so they cannot collide with
 * review.php's "zzc1…" or files.php's "zzb1test", under whatever the stack
 * root is — same approach both of those already take, rather than pointing
 * STORE_ROOT anywhere special.
 *
 * MUST NEVER RUN DOCKER in the sense of a command that touches a real
 * container: nothing here calls stop, start, rename, rm or compose up/down
 * against anything, and staxx_handover_script()'s text is only ever built
 * and read, never executed. What IS called, the same as review.php already
 * does for its own "unlocked" case: staxx_compose_cmd() (a version check)
 * and staxx_compose_meta() (`compose config`, to parse a fixture file) —
 * both read-only, and both already run on every ordinary page load. The one
 * place this reaches for a REAL container name at all is the "already
 * belongs to another project" case, which borrows one the same way
 * review.php borrows a live project name — read-only, and skipped outright
 * if this server has nothing to borrow. staxx_handover_targets() also takes
 * an injectable container list precisely so the rest of the matching logic
 * never needs Docker in the room at all. */

require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

/* ------------------------------------------------------------ fixtures --- */

$root = staxx_stack_root();

$plainRel    = 'zzd1plain';     // ordinary stack, nothing about it changed
$lockedRel   = 'zzd1locked';    // awaiting review, no container_name at all
$activeRel   = 'zzd1active';    // a handover already waiting to be confirmed
$noFileRel   = 'zzd1nofile';    // folder exists, no compose file in it
$labelRel    = 'zzd1label';     // container_name borrowed from a live project
$noneRel     = 'zzd1none';      // deliberately never created

$plainDir  = $root.'/'.$plainRel;
$lockedDir = $root.'/'.$lockedRel;
$activeDir = $root.'/'.$activeRel;
$noFileDir = $root.'/'.$noFileRel;
$labelDir  = $root.'/'.$labelRel;

$sweep = [$plainRel, $lockedRel, $activeRel, $noFileRel, $labelRel];
foreach ($sweep as $name) @exec('rm -rf '.escapeshellarg($root.'/'.$name));

$compose = "services:\n  a:\n    image: alpine:3.20\n";

mkdir($plainDir, 0755, true);
file_put_contents($plainDir.'/compose.yaml', $compose);

mkdir($lockedDir, 0755, true);
file_put_contents($lockedDir.'/compose.yaml', $compose);
file_put_contents($lockedDir.'/'.STAXX_REVIEW_FILE, "imported\n");

mkdir($activeDir, 0755, true);
file_put_contents($activeDir.'/compose.yaml', $compose);
staxx_handover_write($activeDir, [
  ['original' => 'zzd1-old', 'setaside' => 'zzd1-old-before-staxx', 'wasRunning' => true],
], '2026-08-18T00:00:00+00:00');

mkdir($noFileDir, 0755, true);

/* ------------------------------------------------ staxx_handover_targets -- */

ok('invalid path finds no targets', staxx_handover_targets('a/b/c') === []);
ok('no compose file finds no targets', staxx_handover_targets($noneRel) === []);

// No service declares a container_name at all, so this returns without ever
// asking Docker anything — the short-circuit staxx_handover_targets()'s own
// comment describes.
ok('a stack with no container_name declared has no targets',
   staxx_handover_targets($lockedRel) === []);

// One service DOES declare a name, but the injected container list says
// nothing answers to it — still zero Docker in the room.
$namedRel = 'zzd1named';
$namedDir = $root.'/'.$namedRel;
@exec('rm -rf '.escapeshellarg($namedDir));
mkdir($namedDir, 0755, true);
file_put_contents($namedDir.'/compose.yaml',
  "services:\n  a:\n    image: alpine:3.20\n    container_name: zzd1-target\n");

ok('a declared name nothing answers to is not a target',
   staxx_handover_targets($namedRel, []) === []);

$found = staxx_handover_targets($namedRel, ['zzd1-target' => ['running' => true, 'project' => '']]);
ok('a declared name something answers to IS a target',
   count($found) === 1 && $found[0]['service'] === 'a'
   && $found[0]['name'] === 'zzd1-target' && $found[0]['running'] === true, json_encode($found));

/* ------------------------------------------- staxx_handover_setaside_name -- */

ok('first choice is <name>-before-staxx',
   staxx_handover_setaside_name('foo', []) === 'foo-before-staxx');
ok('a taken first choice falls to -2',
   staxx_handover_setaside_name('foo', ['foo-before-staxx']) === 'foo-before-staxx-2');
ok('a taken -2 falls to -3',
   staxx_handover_setaside_name('foo', ['foo-before-staxx', 'foo-before-staxx-2']) === 'foo-before-staxx-3');
ok('a name with a dot and a dash is untouched by the derivation',
   staxx_handover_setaside_name('my.app-1', []) === 'my.app-1-before-staxx');

/* ------------------------------------------------ the state file's round trip -- */

$targets = [
  ['original' => 'my.app-1',      'setaside' => 'my.app-1-before-staxx',   'wasRunning' => true],
  ['original' => 'second-thing',  'setaside' => 'second-thing-before-staxx-2', 'wasRunning' => false],
];
$roundtripDir = $root.'/zzd1roundtrip';
@exec('rm -rf '.escapeshellarg($roundtripDir));
mkdir($roundtripDir, 0755, true);

ok('writes the handover note', staxx_handover_write($roundtripDir, $targets, '2026-08-18T12:00:00+00:00'));
ok('is then found present', staxx_handover_file($roundtripDir) === STAXX_HANDOVER_FILE);

$readBack = staxx_handover_read($roundtripDir);
ok('reads back the same targets, in order', ($readBack['targets'] ?? null) === $targets, json_encode($readBack));
ok('reads back the same timestamp', ($readBack['when'] ?? '') === '2026-08-18T12:00:00+00:00');

@exec('rm -rf '.escapeshellarg($roundtripDir));

/* --------------------------------------------------- the script's text --- */

$hostile = [
  ['original' => 'plain-one', 'setaside' => 'plain-one-before-staxx', 'wasRunning' => true],
  ['original' => "evil'name", 'setaside' => "evil'name-before-staxx", 'wasRunning' => false],
];
$script = staxx_handover_script(
  'docker compose', ['compose.yaml'], '/tmp/zzd1script',
  $hostile, '/tmp/zzd1script/.NEEDS-REVIEW.md', '/tmp/zzd1script/NEEDS-REVIEW.md',
  '/tmp/zzd1script/HANDOVER.md'
);

ok('the script contains an undo() function', strpos($script, 'undo()') !== false);
ok('a hostile container name comes out through escapeshellarg, quoted',
   strpos($script, escapeshellarg("evil'name")) !== false);

// Not prefixed with the docker binary's own path — staxx_docker_bin() finds
// whatever this real server actually has, so only the argument half of each
// command is something the test can predict.
$posStop     = strpos($script, "stop 'plain-one'");
$posRename   = strpos($script, "rename 'plain-one' 'plain-one-before-staxx'");
// `up -d` rather than the compose binary, which now appears in undo() as well.
$posCompose  = strpos($script, 'up -d --remove-orphans');
ok('every target is stopped before any is renamed',
   $posStop !== false && $posRename !== false && $posStop < $posRename);
ok('the compose step comes after every rename',
   $posCompose !== false && $posRename < $posCompose);

// undo() has to clear this stack's own containers away before it hands the
// original its name back: a start that failed partway can still have created
// some of them, and they hold the ports and the fixed address the original
// needs. So `down` comes first, and the rename back after it.
$undoPart      = substr($script, 0, strpos($script, "\n\nfail() {"));
$undoDown      = strpos($undoPart, 'down 2>/dev/null');
$undoRename    = strpos($undoPart, "rename 'plain-one-before-staxx' 'plain-one'");
ok('undo() brings this stack down before putting any name back',
   $undoDown !== false && $undoRename !== false && $undoDown < $undoRename);
ok('undo() starts back only what had been running',
   strpos($undoPart, "start 'plain-one'") !== false &&
   strpos($undoPart, "start 'evil'\\''name'") === false, $undoPart);

// Every MAIN step carries its own 2>&1 — undo() deliberately uses
// 2>/dev/null instead, since its output is not read, so counting is done on
// the lines after undo()'s closing brace.
$mainPart = substr($script, strpos($script, "\n\nfail() {"));
$mainSteps = preg_match_all('/\b(docker|cd|rm -f|echo)[^\n]*/', $mainPart, $m2) ? $m2[0] : [];
$missing2and1 = array_filter($mainSteps, function ($line) {
  // "echo" lines that only print a sentence carry no redirection and are not
  // part of this check — they run no external command that could fail.
  if (strpos($line, 'echo "') === 0) return false;
  return strpos($line, '2>&1') === false;
});
ok('every command step in the main body carries its own 2>&1',
   $missing2and1 === [], implode(' | ', $missing2and1));

/* ------------------------------------------------------- staxx_start_handover -- */

// Every refusal here must leave the stack folder exactly as it found it —
// checked by comparing a directory listing before and after each call.
$snapshot = fn(string $dir) => (array)@scandir($dir);

$err = '';
ok('refuses an invalid path', staxx_start_handover('a/b/c', $err) === '' && $err !== '');

$err = '';
ok('refuses a stack that does not exist', staxx_start_handover($noneRel, $err) === '' && $err !== '');

$before = $snapshot($noFileDir);
$err = '';
ok('refuses a stack with no compose file', staxx_start_handover($noFileRel, $err) === '' && $err !== '');
ok('...and touches nothing in its folder', $snapshot($noFileDir) === $before);

$before = $snapshot($plainDir);
$err = '';
ok('refuses an ordinary, unlocked stack with nothing to hand over',
   staxx_start_handover($plainRel, $err) === '' && stripos($err, 'Nothing on this server') !== false, $err);
ok('...and touches nothing in its folder', $snapshot($plainDir) === $before);

$before = $snapshot($activeDir);
$err = '';
ok('refuses a stack whose handover is already waiting to be confirmed',
   staxx_start_handover($activeRel, $err) === '' && stripos($err, 'already') !== false, $err);
ok('...and touches nothing in its folder', $snapshot($activeDir) === $before);

$before = $snapshot($lockedDir);
$err = '';
ok('refuses a locked stack with nothing to hand over',
   staxx_start_handover($lockedRel, $err) === '' && stripos($err, 'Nothing on this server') !== false, $err);
ok('...and touches nothing in its folder', $snapshot($lockedDir) === $before);

// Borrow a real, live compose project's name the same way review.php does,
// so the "belongs to someone else" refusal is proven against something that
// genuinely exists rather than a name invented for the test.
$liveName = '';
$liveProject = '';
foreach (staxx_docker_container_names() as $name => $info) {
  if ($info['project'] !== '') { $liveName = $name; $liveProject = $info['project']; break; }
}

if ($liveName === '') {
  ok('SKIPPED — no compose-managed container on this machine to borrow a name from', true);
} else {
  mkdir($labelDir, 0755, true);
  file_put_contents($labelDir.'/compose.yaml',
    "services:\n  a:\n    image: alpine:3.20\n    container_name: ".$liveName."\n");
  file_put_contents($labelDir.'/'.STAXX_REVIEW_FILE, "imported\n");

  $before = $snapshot($labelDir);
  $err = '';
  $r = staxx_start_handover($labelRel, $err);
  ok('refuses a target that already belongs to another compose project',
     $r === '' && strpos($err, $liveProject) !== false, $err);
  ok('...and touches nothing in its folder', $snapshot($labelDir) === $before);
}

/* ------------------------------------------------------ staxx_finish_handover -- */

$before = $snapshot($plainDir);
$err = '';
ok('refuses to finish a plain stack with no handover in progress',
   staxx_finish_handover($plainRel, true, $err) === '' && $err !== '');
ok('...and touches nothing in its folder', $snapshot($plainDir) === $before);

/* -------------------------------------------------------------- cleanup --- */

foreach (array_merge($sweep, [$namedRel]) as $name) @exec('rm -rf '.escapeshellarg($root.'/'.$name));
ok('nothing left behind', !is_dir($plainDir) && !is_dir($lockedDir) && !is_dir($activeDir)
                        && !is_dir($noFileDir) && !is_dir($labelDir) && !is_dir($root.'/'.$namedRel));

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
