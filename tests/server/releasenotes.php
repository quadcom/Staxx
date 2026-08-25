<?php
/* PLAN_82 Part 2 step 3 — release notes captured at pull time.
 *
 * Covers the pure URL builder and text trimmer (staxx_release_notes_urls(),
 * staxx_release_notes_trim(), include/Updates.php), the three optional
 * notes keys on an image-history entry (staxx_image_history_valid_entry(),
 * include/ImageHistory.php), and staxx_update_record_before_pull()
 * (include/UpdateRun.php) — the one shared "record before a pull" step that
 * both the queue tick and a hand-pressed Update must call, and the fix to
 * which version name it stamps an outgoing entry with.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. Needs STACK_ROOT
 * pointed at /tmp/b6-root, the same way tests/server/unpin.php points it at
 * /tmp/b5-root — never the real stack root. staxx_cfg() memoises the first
 * time it is read, so the key is seeded into the config file BEFORE php
 * runs, not changed from inside this script.
 *
 *     pscp tests/server/releasenotes.php root@<box>:/tmp/
 *     plink … '
 *       CFG=/boot/config/plugins/staxx/staxx.cfg
 *       cp $CFG /tmp/cfg.bak
 *       sed -i "s#^STACK_ROOT=.*#STACK_ROOT=\"/tmp/b6-root\"#" $CFG
 *       grep -q "^STACK_ROOT=" $CFG || echo "STACK_ROOT=\"/tmp/b6-root\"" >> $CFG
 *       php /tmp/releasenotes.php; RC=$?
 *       cp /tmp/cfg.bak $CFG
 *       exit $RC
 *     '
 *
 * The central update-state file lives on the flash drive too
 * (/boot/config/plugins/staxx/updates.json) and is NEVER touched — this
 * script points STAXX_UPDATE_STATE at a scratch file in /tmp via putenv(),
 * before the first require, and checks the override actually took before
 * doing anything else.
 *
 * Prints one line per case and exits non-zero on any failure. Creates and
 * removes its own stacks, "zzb6…", under the temporary stack root, and its
 * own scratch state file. Cleans up on the way in too, so a previous
 * interrupted run cannot affect this one.
 *
 * Nothing here starts, stops, pulls or removes a container or an image, and
 * no notes fetch in this file ever reaches a real server: every project
 * link used is either not a GitHub link at all (so staxx_release_notes_urls()
 * returns no candidates and staxx_release_notes_fetch() never calls out) or
 * is exercised purely as a string through the pure URL builder. The one
 * place this file touches docker is a plain, read-only `docker images` /
 * `docker image inspect` — the same kind of read staxx_update_record_
 * before_pull() itself always makes, and exactly what rollback.php's Part D
 * comment already establishes is safe on a live box. No image is pulled,
 * no container is started or recreated. */

$scratch = '/tmp/staxx-releasenotes-test.json';
@unlink($scratch);
putenv('STAXX_UPDATE_STATE='.$scratch);

register_shutdown_function(function () use ($scratch) {
  @unlink($scratch);
  $lock = (defined('STAXX_UPDATE_DIR') ? STAXX_UPDATE_DIR : '/tmp/staxx/updates').'/lock';
  if (is_dir($lock)) @rmdir($lock);
});

require_once '/usr/local/emhttp/plugins/staxx/include/UpdateRun.php';
require_once '/usr/local/emhttp/plugins/staxx/include/ImageHistory.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}
function note(string $what): void {
  echo "note   $what\n";
}

/* A digest has to look like a real one, or the reader refuses it — see dg()'s
 * note in tests/server/imagehistory.php, which caught exactly that
 * asymmetry. */
function dg(string $label): string {
  return 'sha256:'.substr(hash('sha256', $label), 0, 64);
}

if (staxx_stack_root() !== '/tmp/b6-root') {
  echo "FAIL   the temporary stack root is not in place (got ".staxx_stack_root().")\n";
  exit(1);
}
if (STAXX_UPDATE_STATE !== $scratch) {
  echo "FAIL   the temporary update-state file is not in place (got ".STAXX_UPDATE_STATE.")\n";
  exit(1);
}

$root = staxx_stack_root();

/* Clean slate on the way in — an interrupted previous run must not be able
 * to feed stale fixtures into this one — and again at the bottom. */
function b6_wipe(): void {
  global $root, $scratch;
  @exec('rm -rf '.escapeshellarg($root));
  mkdir($root, 0755, true);
  @unlink($scratch);
}
b6_wipe();

function b6_make_stack_raw(string $rel, string $yaml): void {
  global $root;
  $dir = $root.'/'.$rel;
  @exec('rm -rf '.escapeshellarg($dir));
  mkdir($dir, 0755, true);
  file_put_contents($dir.'/compose.yaml', $yaml);
  staxx_scan_stacks_reset();
}

/* ======================================================================= *
 * A — the URL builder, staxx_release_notes_urls(). Pure, no network.
 * ======================================================================= */

$gh = 'https://github.com/example/project';

$urls = staxx_release_notes_urls($gh, '10.9.11');
ok('a GitHub project and a bare version gives both candidates',
   count($urls) === 2
     && in_array('https://api.github.com/repos/example/project/releases/tags/10.9.11', $urls, true)
     && in_array('https://api.github.com/repos/example/project/releases/tags/v10.9.11', $urls, true),
   implode(' | ', $urls));

$urlsV = staxx_release_notes_urls($gh, 'v10.9.11');
ok('a v-prefixed version also gives both candidates',
   count($urlsV) === 2
     && in_array('https://api.github.com/repos/example/project/releases/tags/v10.9.11', $urlsV, true)
     && in_array('https://api.github.com/repos/example/project/releases/tags/10.9.11', $urlsV, true),
   implode(' | ', $urlsV));

// Optional trailing slash and optional .git — both named in the brief as
// forms staxx_release_notes_urls() must still recognise as GitHub.
ok('a trailing slash on the project link still resolves',
   count(staxx_release_notes_urls($gh.'/', '1.0')) === 2);
ok('a .git suffix on the project link still resolves',
   count(staxx_release_notes_urls($gh.'.git', '1.0')) === 2);

ok('a documentation-site link gives no candidates at all',
   staxx_release_notes_urls('https://docs.example.test/project', '10.9.11') === []);
ok('a Docker Hub link gives no candidates at all',
   staxx_release_notes_urls('https://hub.docker.com/r/example/project', '10.9.11') === []);

$badVersions = [
  'a slash'            => '1.0/beta',
  'a ..'               => '1.0..beta',
  'a space'             => '1.0 beta',
  'a control character' => "1.0\x01beta",
];
foreach ($badVersions as $label => $bad) {
  ok('a version containing '.$label.' gives no candidates',
     staxx_release_notes_urls($gh, $bad) === [], $bad);
}
ok('an empty version gives no candidates',
   staxx_release_notes_urls($gh, '') === []);

/* ======================================================================= *
 * B — the trimmer, staxx_release_notes_trim(). Pure, no network.
 * ======================================================================= */

$short = "Fixed a bug.\nAdded a feature.";
$trimShort = staxx_release_notes_trim($short);
ok('a body under the cap comes back byte-identical', $trimShort['notes'] === $short);
ok('...and uncut', $trimShort['cut'] === false);

// Built as whole lines so the cap always lands mid-body, not neatly on a
// line boundary by chance — proving the cut point is genuinely CHOSEN, not
// coincidental.
$long = '';
for ($i = 0; strlen($long) <= STAXX_NOTES_MAX + 500; $i++) {
  $long .= 'line number '.$i.' of the release notes, padded out a bit further.'."\n";
}
$trimLong = staxx_release_notes_trim($long);
ok('a body over the cap is marked cut', $trimLong['cut'] === true);
ok('...and the result is at or under the cap',
   strlen($trimLong['notes']) <= STAXX_NOTES_MAX, 'len='.strlen($trimLong['notes']));
// Never mid-word: the character in the ORIGINAL text immediately after the
// returned notes must be the line break that was cut on, not some letter
// half-way through a word.
$afterCut = substr($long, strlen($trimLong['notes']), 1);
ok('...and the cut lands on a line break, never mid-word',
   $afterCut === "\n", 'next char='.var_export($afterCut, true));

$crlf = "one\r\ntwo\r\nthree";
$trimCrlf = staxx_release_notes_trim($crlf);
ok('CRLF line endings are normalised to plain newlines',
   $trimCrlf['notes'] === "one\ntwo\nthree");

/* ======================================================================= *
 * C — the three optional notes keys on an image-history entry
 * (staxx_image_history_valid_entry() / staxx_image_history_valid_map(),
 * include/ImageHistory.php).
 * ======================================================================= */

function entryBase(): array {
  return ['digest' => dg('entry-base'), 'at' => time(), 'version' => '1.0', 'source' => ''];
}

$noNotes = entryBase();
ok('an entry with no notes keys at all validates',
   staxx_image_history_valid_entry($noNotes));
ok('...and validates as a whole map too',
   staxx_image_history_valid_map(['web' => [$noNotes]]));

$badNotes = entryBase();
$badNotes['notes'] = 12345; // not a string
ok('a non-string notes value invalidates the entry on its own',
   !staxx_image_history_valid_entry($badNotes));
ok('...and takes its whole map down with it too — the existing all-or-nothing '
 . 'rule, asserted rather than assumed',
   !staxx_image_history_valid_map(['web' => [entryBase(), $badNotes]]));

$badCut = entryBase();
$badCut['notes'] = 'some notes';
$badCut['notesCut'] = 'yes'; // not a bool
ok('a non-bool notesCut invalidates the entry',
   !staxx_image_history_valid_entry($badCut));
ok('...and its map',
   !staxx_image_history_valid_map(['web' => [$badCut]]));

$allThree = entryBase();
$allThree['notes'] = 'What changed in this release.';
$allThree['notesUrl'] = 'https://github.com/example/project/releases/tag/1.0';
$allThree['notesCut'] = true;
ok('all three notes keys present and correctly typed validates',
   staxx_image_history_valid_entry($allThree));
ok('...and validates as a map',
   staxx_image_history_valid_map(['web' => [$allThree]]));

/* ======================================================================= *
 * D — proving the shared "record before a pull" step is the thing BOTH
 * callers use. This is read straight off the source, as the brief allows:
 * the alternative is actually starting an update job, which this suite must
 * never do against a live box.
 * ======================================================================= */

$updateRunSrc = file_get_contents('/usr/local/emhttp/plugins/staxx/include/UpdateRun.php');
$actionSrc    = file_get_contents('/usr/local/emhttp/plugins/staxx/include/action.php');
ok('UpdateRun.php was readable for the source check', is_string($updateRunSrc) && $updateRunSrc !== '');
ok('action.php was readable for the source check', is_string($actionSrc) && $actionSrc !== '');

// staxx_update_history_push() must now be called from exactly ONE place —
// inside staxx_update_record_before_pull() itself — not also from a second,
// separate block in the queue tick. Two call sites is the old duplication
// the plan says to remove; more than one proves it is still there.
// Anchored to the start of a line so only a real statement counts. A bare
// substring search also matches the function's own definition and the two
// comments that name it, which says nothing about how many places call it.
$pushCalls = preg_match_all('/^\s*staxx_update_history_push\s*\(/m', (string)$updateRunSrc);
ok('staxx_update_history_push() has exactly one call site left in UpdateRun.php '
 . '(inside the shared step, not duplicated in the queue tick)',
   $pushCalls === 1, 'found '.$pushCalls);

// staxx_update_record_before_pull() itself must be called from somewhere
// other than its own definition, inside UpdateRun.php (the queue tick).
$recordCallsInUpdateRun = preg_match_all('/staxx_update_record_before_pull\s*\(/', (string)$updateRunSrc);
ok('staxx_update_record_before_pull() is called from within UpdateRun.php, '
 . 'not merely defined there (the queue tick)',
   $recordCallsInUpdateRun >= 2, 'occurrences (definition + calls) = '.$recordCallsInUpdateRun);

// ...and the queue tick's call must come BEFORE it starts the job, so a slow
// or failed job still leaves something to roll back to. Located by finding
// the call site nearest the queue's own staxx_start_job() invocation.
$defPos = strpos((string)$updateRunSrc, 'function staxx_update_record_before_pull(');
$callPos = false;
$search = 0;
while (($p = strpos((string)$updateRunSrc, 'staxx_update_record_before_pull(', $search)) !== false) {
  if ($p !== $defPos) { $callPos = $p; break; }
  $search = $p + 1;
}
$startJobPos = ($callPos !== false)
  ? strpos((string)$updateRunSrc, 'staxx_start_job(', $callPos)
  : false;
ok('in the queue tick, the shared step runs BEFORE staxx_start_job() starts the job',
   $callPos !== false && $startJobPos !== false && $callPos < $startJobPos,
   'callPos='.var_export($callPos, true).' startJobPos='.var_export($startJobPos, true));

// action.php's update-apply case must call the shared step too, before it
// starts the job — the same "before, not after" ordering.
$caseStart = strpos((string)$actionSrc, "case 'update-apply':");
ok('action.php has an update-apply case to examine', $caseStart !== false);
if ($caseStart !== false) {
  $nextCase = strpos((string)$actionSrc, "case '", $caseStart + 1);
  $caseBlock = $nextCase !== false
    ? substr((string)$actionSrc, $caseStart, $nextCase - $caseStart)
    : substr((string)$actionSrc, $caseStart);

  $recCallInBlock = strpos($caseBlock, 'staxx_update_record_before_pull(');
  $jobCallInBlock = strpos($caseBlock, 'staxx_start_job(');
  ok('update-apply calls the shared recording step before starting the job',
     $recCallInBlock !== false && $jobCallInBlock !== false && $recCallInBlock < $jobCallInBlock,
     'recCall='.var_export($recCallInBlock, true).' jobCall='.var_export($jobCallInBlock, true));
} else {
  ok('update-apply calls the shared recording step before starting the job',
     false, 'no update-apply case found to check');
}

/* ======================================================================= *
 * E — staxx_update_record_before_pull() itself: the shared step lands an
 * entry with NO notes keys when nothing can be fetched, and the naming fix
 * (Finding 2) stamps the right version name.
 *
 * Both need a real, LOCALLY PRESENT image with recorded registry digests, or
 * staxx_image_local() — a plain, read-only `docker image inspect` — finds
 * nothing and the whole service is skipped by staxx_update_record_before_
 * pull() before it ever reaches the naming logic. Rather than guess a name
 * that happens to exist on this box, one is found by asking docker itself,
 * read-only, for whatever it already has cached — the same kind of read
 * staxx_update_record_before_pull() always makes on a real pass. If this box
 * genuinely has no locally cached, registry-pulled image at all, these two
 * cases are reported as unprovable notes rather than false failures — the
 * same honesty rollback.php's Part D14 comment already uses for a case this
 * suite's read-only, no-container constraints cannot reach.
 * ======================================================================= */

function b6_local_image_with_digest(): string {
  $out = staxx_sh(staxx_docker_bin().' images --format {{.Repository}}:{{.Tag}} --filter dangling=false', 15);
  foreach (preg_split('/\r?\n/', trim($out)) as $ref) {
    $ref = trim($ref);
    if ($ref === '' || strpos($ref, '<none>') !== false) continue;
    $local = staxx_image_local($ref);
    if (!empty($local['digest'])) return $ref;
  }
  return '';
}

$fixtureImage = b6_local_image_with_digest();

if ($fixtureImage === '') {
  note('no locally cached, registry-pulled image was found on this box — the '
     . 'naming-fix and empty-notes cases below cannot be proved without one, '
     . 'and are skipped rather than faked');
} else {
  // 1. changed = true (local !== remote, both non-empty) → the outgoing
  // entry is stamped from 'was', not 'version'.
  b6_wipe();
  b6_make_stack_raw('zzb6changed', "services:\n  web:\n    image: $fixtureImage\n");
  staxx_update_state_save(['images' => [
    $fixtureImage => [
      'local' => dg('e-local'), 'remote' => dg('e-remote'),
      'was' => 'WAS-NAME', 'version' => 'VERSION-NAME',
    ],
  ]]);
  staxx_update_record_before_pull('zzb6changed');
  $entryChanged = staxx_image_history('zzb6changed', 'web')[0] ?? null;
  ok('changed (local !== remote): the recorded version name is the outgoing '
   . '"was" value, not "version"',
     is_array($entryChanged) && ($entryChanged['version'] ?? null) === 'WAS-NAME',
     $entryChanged === null ? '(nothing recorded)' : json_encode($entryChanged));
  ok('...and it carries no notes keys, since nothing could be fetched for it',
     is_array($entryChanged)
       && !array_key_exists('notes', $entryChanged)
       && !array_key_exists('notesUrl', $entryChanged)
       && !array_key_exists('notesCut', $entryChanged));

  // 2. changed = false (local === remote) → the outgoing entry is stamped
  // from 'version'. Two cases, distinguishing the two keys, because a test
  // that only tries one of them proves nothing — this is exactly the trap
  // the unpin work hit.
  b6_wipe();
  b6_make_stack_raw('zzb6unchanged', "services:\n  web:\n    image: $fixtureImage\n");
  staxx_update_state_save(['images' => [
    $fixtureImage => [
      'local' => dg('e-same'), 'remote' => dg('e-same'),
      'was' => 'WAS-NAME', 'version' => 'VERSION-NAME',
    ],
  ]]);
  staxx_update_record_before_pull('zzb6unchanged');
  $entryUnchanged = staxx_image_history('zzb6unchanged', 'web')[0] ?? null;
  ok('unchanged (local === remote): the recorded version name is "version", '
   . 'not the outgoing "was" value',
     is_array($entryUnchanged) && ($entryUnchanged['version'] ?? null) === 'VERSION-NAME',
     $entryUnchanged === null ? '(nothing recorded)' : json_encode($entryUnchanged));

  b6_wipe();
}

/* ======================================================================= *
 * F — no network. A project link that is not a GitHub link at all means
 * staxx_release_notes_urls() returns no candidates, so staxx_release_notes_
 * fetch()'s loop never runs and no curl call is ever made — the safest,
 * most deterministic way to prove "no network" without this suite reaching
 * out to a real host from a production box. "Resolves nowhere" is true of
 * this address regardless — it is simply never asked, because it was never
 * a GitHub link to begin with.
 * ======================================================================= */

$fakeProject = 'https://staxxtest-nonexistent-host.invalid/some/repo';
ok('a non-GitHub project link gives no URL candidates at all (so no curl call '
 . 'is ever made)',
   staxx_release_notes_urls($fakeProject, '1.0') === []);

$fetchResult = staxx_release_notes_fetch($fakeProject, '1.0');
ok('the fetch returns the all-empty answer for a project with no candidates',
   $fetchResult === ['notes' => '', 'url' => '', 'cut' => false],
   json_encode($fetchResult));

// The entry is still recorded (with no notes keys) even when the project
// link cannot be resolved to any fetch candidate at all — proven directly
// against staxx_update_record_before_pull() only when a fixture image was
// found above; otherwise this is covered already by section E's "no notes
// keys" assertion, which used no project link at all (an equally valid "no
// notes can be fetched" case).
note('no network call was made anywhere in this file — every project link '
   . 'used either fails the GitHub check outright or was never supplied');

/* ---------------------------------------------------------------------- */

b6_wipe();

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
