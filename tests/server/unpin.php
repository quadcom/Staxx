<?php
/* Releasing a pin (staxx_update_unpin(), include/UpdateRun.php) and the
 * knock-on effects: the declined-version fingerprint left behind by pinning,
 * and staxx_update_due()/staxx_update_clock() (include/UpdateRun.php) which
 * decide what an automatic pass may act on and what it reports.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. Needs STORE_ROOT
 * pointed at /tmp/b5-store, the same way tests/server/rollback.php points it
 * at /tmp/b4-store — never the real stack root. staxx_cfg() memoises the
 * first time it is read, so the key is seeded into the config file BEFORE
 * php runs, not changed from inside this script.
 *
 *     pscp tests/server/unpin.php root@<box>:/tmp/
 *     plink … '
 *       CFG=/boot/config/plugins/staxx/staxx.cfg
 *       cp $CFG /tmp/cfg.bak
 *       grep -q "^STORE_ROOT=" $CFG \
 *         && sed -i "s#^STORE_ROOT=.*#STORE_ROOT=\"/tmp/b5-store\"#" $CFG \
 *         || echo "STORE_ROOT=\"/tmp/b5-store\"" >> $CFG
 *       php /tmp/unpin.php; RC=$?
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
 * removes its own stacks, "zzb5…", under the temporary stack root, and its
 * own scratch state file. Cleans up on the way in too, so a previous
 * interrupted run cannot affect this one.
 *
 * Nothing here runs docker, starts a job, or recreates a container —
 * staxx_update_unpin() itself does none of those (see the comment above its
 * definition), and every reference used as an "image" in this file is either
 * a fixture never installed on this box or a made-up registry that resolves
 * nowhere. Part D's two functions do read real compose state through
 * staxx_list_stacks()/staxx_state_for() (a read-only "compose ls"-shaped
 * query), the same way every other server suite that touches those
 * functions already does — nothing there starts, stops or changes anything.
 */

$scratch = '/tmp/staxx-unpin-test.json';
@unlink($scratch);
putenv('STAXX_UPDATE_STATE='.$scratch);

register_shutdown_function(function () use ($scratch) {
  @unlink($scratch);
  $lock = (defined('STAXX_UPDATE_DIR') ? STAXX_UPDATE_DIR : '/tmp/staxx/updates').'/lock';
  if (is_dir($lock)) @rmdir($lock);
});

require_once '/usr/local/emhttp/plugins/staxx/include/UpdateRun.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Record.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

/* A digest has to look like a real one, or compose's own image-reference
 * parser refuses the fixture before staxx_update_unpin() is even reached. */
function dg(string $label): string {
  return 'sha256:'.substr(hash('sha256', $label), 0, 64);
}

if (staxx_stack_root() !== '/tmp/b5-store/stacks') {
  echo "FAIL   the temporary stack root is not in place (got ".staxx_stack_root().")\n";
  exit(1);
}
if (staxx_update_state_file() !== $scratch) {
  echo "FAIL   the temporary update-state file is not in place (got ".staxx_update_state_file().")\n";
  exit(1);
}

$root = staxx_stack_root();

/* Clean slate on the way in — an interrupted previous run must not be able
 * to feed stale fixtures into this one — and again at the bottom. */
function b5_wipe(): void {
  global $root, $scratch;
  @exec('rm -rf '.escapeshellarg($root));
  mkdir($root, 0755, true);
  @unlink($scratch);
}
b5_wipe();

function b5_make_stack(string $rel, string $service = 'web', string $image = 'alpine:3.20'): void {
  global $root;
  $dir = $root.'/'.$rel;
  @exec('rm -rf '.escapeshellarg($dir));
  mkdir($dir, 0755, true);
  file_put_contents($dir.'/compose.yaml', "services:\n  $service:\n    image: $image\n");

  // The stack list is memoised for the rest of the request — a fixture
  // built by hand has to reset it or a stack created after anything has
  // already looked is invisible without it.
  staxx_scan_stacks_reset();
}

/* Same as above, but with whatever raw compose text the case needs (a
 * comment to prove it survives, two services, and so on). */
function b5_make_stack_raw(string $rel, string $yaml): void {
  global $root;
  $dir = $root.'/'.$rel;
  @exec('rm -rf '.escapeshellarg($dir));
  mkdir($dir, 0755, true);
  file_put_contents($dir.'/compose.yaml', $yaml);
  staxx_scan_stacks_reset();
}

/* Which refusal fired matters, not merely that one did — see the reasoning
 * above refusedBecause() in tests/server/rollback.php, which this copies
 * verbatim. A fragment that never matches makes a case fail for the wrong
 * reason, and a fragment too generic ("refused" instead of naming the
 * specific check) would pass identically against a broken implementation. */
function refusedBecause(string $err, string $fragment): bool {
  return $err !== '' && strpos($err, $fragment) !== false;
}

const NOT_PINNED      = 'not pinned to a version';
const NO_IMAGE        = 'no image set';
const MISMATCH         = 'does not release this service to its unpinned image';
const COULD_NOT_CHECK  = 'The supplied file could not be checked';
const NO_SERVICE       = 'No service called';
const NO_COMPOSE_FILE  = 'No compose file found in this stack.';

/* ======================================================================= *
 * Part A — refusals. Every case here must leave the file on disk untouched
 * and the update state untouched; none of them reach staxx_save_stack().
 * ======================================================================= */

/* 1. A service that is not pinned at all. */
b5_make_stack('zzb5notpinned', 'web', 'ghcr.io/example/notpinned:latest');
$err = ''; $note = null;
$res = staxx_update_unpin('zzb5notpinned', 'web',
  "services:\n  web:\n    image: ghcr.io/example/notpinned:latest\n", $err, $note);
ok('a service that is not pinned is refused, not silently accepted',
   $res === false && refusedBecause($err, NOT_PINNED), $err);

/* 2. Supplied text that still contains the fingerprint. */
$c2 = dg('c2-real');
b5_make_stack('zzb5stillpinned', 'web', "ghcr.io/example/stillpinned:latest@$c2");
$err = '';
$res = staxx_update_unpin('zzb5stillpinned', 'web',
  "services:\n  web:\n    image: ghcr.io/example/stillpinned:latest@$c2\n", $err);
ok('supplied text that still carries the fingerprint is refused',
   $res === false && refusedBecause($err, MISMATCH), $err);

/* 3. Supplied text that changes the image to a DIFFERENT repository. This
 * is the security boundary: without this exact-equality check, "release"
 * would be a way to change a service's image to anything at all, under
 * cover of an action whose confirmation dialog only ever mentions lifting
 * a pin. */
$c3 = dg('c3-real');
b5_make_stack('zzb5diffrepo', 'web', "ghcr.io/example/original:latest@$c3");
$err = '';
$res = staxx_update_unpin('zzb5diffrepo', 'web',
  "services:\n  web:\n    image: ghcr.io/example/somethingelse:latest\n", $err);
ok('supplied text that changes the image to a different repository is refused '
 . '(the security boundary)',
   $res === false && refusedBecause($err, MISMATCH), $err);

/* 4. Supplied text that changes only the TAG. The check is exact string
 * equality, not "looks close enough". */
$c4 = dg('c4-real');
b5_make_stack('zzb5difftag', 'web', "ghcr.io/example/tagcase:latest@$c4");
$err = '';
$res = staxx_update_unpin('zzb5difftag', 'web',
  "services:\n  web:\n    image: ghcr.io/example/tagcase:1.2\n", $err);
ok('supplied text that changes only the tag is refused (exact equality, not '
 . '"close enough")',
   $res === false && refusedBecause($err, MISMATCH), $err);

/* 5a. Supplied text that does not parse at all (unterminated quoted scalar
 * — invalid YAML under any parser). */
$c5 = dg('c5-real');
b5_make_stack('zzb5noparse', 'web', "ghcr.io/example/noparse:latest@$c5");
$err = '';
$res = staxx_update_unpin('zzb5noparse', 'web',
  "services:\n  web:\n    image: \"unterminated\n", $err);
ok('supplied text that does not parse at all is refused',
   $res === false && refusedBecause($err, COULD_NOT_CHECK), $err);

/* 5b. Supplied text that parses fine but has the service renamed away. */
b5_make_stack('zzb5renamed', 'web', "ghcr.io/example/renamed:latest@$c5");
$err = '';
$res = staxx_update_unpin('zzb5renamed', 'web',
  "services:\n  website:\n    image: ghcr.io/example/renamed:latest\n", $err);
ok('supplied text with the requested service renamed away is refused',
   $res === false && refusedBecause($err, COULD_NOT_CHECK), $err);

/* 5c. Empty-ish text that is not '' — must still be checked and refused,
 * not treated as though nothing was supplied. */
b5_make_stack('zzb5emptyish', 'web', "ghcr.io/example/emptyish:latest@$c5");
foreach (['a bare newline' => "\n", 'a comment only' => "# just a comment\n"] as $label => $bad) {
  $err = '';
  $res = staxx_update_unpin('zzb5emptyish', 'web', $bad, $err);
  ok('empty-ish but non-empty text ('.$label.') is checked and refused',
     $res === false && $bad !== '' && refusedBecause($err, COULD_NOT_CHECK), $err);
}

/* 6. A stack that does not exist, and a service that does not exist in a
 * real stack. */
$err = '';
$res = staxx_update_unpin('zzb5neverexisted', 'web', 'irrelevant', $err);
ok('a stack that does not exist is refused',
   $res === false && refusedBecause($err, NO_COMPOSE_FILE), $err);

b5_make_stack('zzb5realstack', 'web', 'ghcr.io/example/real:latest');
$err = '';
$res = staxx_update_unpin('zzb5realstack', 'noservice', 'irrelevant', $err);
ok('a service that does not exist in a real stack is refused',
   $res === false && refusedBecause($err, NO_SERVICE), $err);

/* Bonus, not asked for by name but cheap to prove while the fixtures are to
 * hand: a service with no image set at all. */
b5_make_stack_raw('zzb5noimageatall', "services:\n  bare:\n    build: ./bare\n");
$err = '';
$res = staxx_update_unpin('zzb5noimageatall', 'bare', 'irrelevant', $err);
ok('a service with no image set is refused for that reason',
   $res === false && refusedBecause($err, NO_IMAGE), $err);

// None of this batch's temp files should still be on disk — every case
// above is a refusal, so no successful save should have run through the
// tempnam('staxx-up-') check file more than transiently.
$tmpLeft = glob(sys_get_temp_dir().'/staxx-up-*');
ok('no leftover temp check-files from the refusal batch',
   $tmpLeft === [], implode(',', $tmpLeft));

/* ======================================================================= *
 * Part B — success, and proving what changed.
 * ======================================================================= */

/* 7. A successful release, and proof the file on disk changed — including
 * that a hand-written comment survives, which is the never-lose-what-the-
 * author-wrote promise this whole project is built on. */
$c7 = dg('c7-real');
$pinned7 = "# do not touch this line, it is the whole point of this test\n"
         . "services:\n  web:\n    image: ghcr.io/example/relcase:latest@$c7\n";
$unpinned7 = "# do not touch this line, it is the whole point of this test\n"
           . "services:\n  web:\n    image: ghcr.io/example/relcase:latest\n";
b5_make_stack_raw('zzb5release', $pinned7);

$err = ''; $note = null;
$res = staxx_update_unpin('zzb5release', 'web', $unpinned7, $err, $note);
ok('a successful release returns true with no error', $res === true, $err);

$onDisk = file_get_contents($root.'/zzb5release/compose.yaml');
ok('the file on disk is exactly the unpinned text that was supplied',
   $onDisk === $unpinned7, $onDisk);
ok('...the fingerprint is gone',
   strpos($onDisk, '@'.$c7) === false);
ok('...and the hand-written comment survived, verbatim',
   strpos($onDisk, '# do not touch this line, it is the whole point of this test') !== false);

/* 8. The pinned file is in the edit history afterwards, and the kept
 * version still carries the fingerprint — staxx_save_stack() captures
 * whatever is on disk BEFORE overwriting it, which for a release is the
 * still-pinned file. */
$versions = staxx_record_list('zzb5release');
ok('the release left at least one kept version behind',
   count($versions) >= 1, 'count='.count($versions));
if (count($versions) >= 1) {
  $newestN = $versions[0]['n'];
  $kept = staxx_record_get('zzb5release', $newestN);
  ok('the newest kept version is the pinned file, fingerprint intact',
     $kept !== null && strpos($kept, '@'.$c7) !== false,
     $kept === null ? 'record_get returned null' : $kept);
}

/* 9. A hand-pinned service with NO recorded image history at all is
 * releasable. Nothing anywhere in this file ever calls
 * staxx_image_history_push() — every fixture here proves this on its own,
 * but this case makes the absence of that call explicit rather than
 * incidental. */
$c9 = dg('c9-real');
b5_make_stack('zzb5nohistory', 'web', "ghcr.io/example/nohistory:latest@$c9");
ok('this fixture genuinely has no recorded image history before the release',
   staxx_image_history('zzb5nohistory', 'web') === []);

$err = '';
$res = staxx_update_unpin('zzb5nohistory', 'web',
  "services:\n  web:\n    image: ghcr.io/example/nohistory:latest\n", $err);
ok('a hand-pinned service with no image history at all is releasable',
   $res === true, $err);

/* 10. Proof that no docker command runs. A made-up registry and repository
 * — nowhere this box, or any box, could ever resolve — pinned and then
 * released. staxx_update_unpin() runs no pull, no image inspect, no
 * recreate (see the comment above its definition): it only parses the
 * supplied text and writes a file. If the release path touched docker in
 * any way — a pull, an inspect, a recreate attempt — a reference that
 * resolves nowhere would fail it (or hang until a timeout); a clean, fast
 * success is therefore itself the proof that no such command ran. */
$c10 = dg('c10-real');
b5_make_stack('zzb5nodocker', 'web',
  "registry.invalid.staxxtest.example/nowhere/ghost:latest@$c10");
$err = '';
$res = staxx_update_unpin('zzb5nodocker', 'web',
  "services:\n  web:\n    image: registry.invalid.staxxtest.example/nowhere/ghost:latest\n", $err);
ok('releasing a pin on an image that resolves nowhere still succeeds — proof '
 . 'no docker command was attempted against it',
   $res === true, $err);

/* ======================================================================= *
 * Part C — the declined-version fingerprint, and the trap in it.
 *
 * The update state's 'images' map is keyed by the image string exactly as
 * it appears in the compose file. The fingerprint recorded at pin time sits
 * under the UNPINNED reference; pinning then adds a fresh entry under the
 * PINNED one and leaves the first behind. Releasing must clear the stale
 * entry under the unpinned key — and must NOT touch whatever sits under the
 * pinned key, or a release could silently clear a skip that has nothing to
 * do with it.
 * ======================================================================= */

$c11 = dg('c11-real');
$unpinnedKey11 = 'ghcr.io/example/c11case:latest';
$pinnedKey11   = $unpinnedKey11.'@'.$c11;

b5_make_stack('zzb5trap', 'web', $pinnedKey11);

$images11 = [
  $unpinnedKey11 => ['remote' => dg('c11-stale-remote'), 'seen' => time(), 'skip' => dg('c11-stale-skip')],
  $pinnedKey11   => ['remote' => dg('c11-pinned-remote'), 'seen' => time(), 'skip' => dg('c11-pinned-skip')],
];
staxx_update_state_save(['images' => $images11]);

$err = '';
$res = staxx_update_unpin('zzb5trap', 'web',
  "services:\n  web:\n    image: $unpinnedKey11\n", $err);
ok('the trap fixture\'s release itself succeeds', $res === true, $err);

$after11 = (array)staxx_update_state()['images'];

/* 11. The skip under the UNPINNED reference is gone. */
ok('the stale skip fingerprint under the unpinned image key is cleared',
   !isset($after11[$unpinnedKey11]['skip']),
   isset($after11[$unpinnedKey11]['skip']) ? $after11[$unpinnedKey11]['skip'] : '(absent)');

/* 12. The skip under the PINNED reference was NOT what got cleared — named
 * and checked separately, so a wrong implementation clearing the pinned
 * key instead of the unpinned one cannot pass this by accident. */
ok('the skip fingerprint under the PINNED image key is untouched, distinct '
 . 'from the one that was cleared',
   ($after11[$pinnedKey11]['skip'] ?? null) === dg('c11-pinned-skip'),
   $after11[$pinnedKey11]['skip'] ?? '(missing)');

/* ======================================================================= *
 * Part D — pinned services are reported but not acted on.
 * ======================================================================= */

/* 13. A pinned service is absent from the automatic candidates. Reads as it
 * stands in staxx_update_due(): a service's image is skipped with a plain
 * `continue` the moment it contains '@', BEFORE staxx_update_clock() (and so
 * before any policy or running-state check) is ever consulted — so this
 * holds regardless of policy or whether the stack is running. */
b5_wipe();
b5_make_stack('zzb5duepinned', 'web', "ghcr.io/example/duepinned:latest@".dg('d13'));

$due = staxx_update_due();
$found = false;
foreach ($due as $row) {
  if ($row['stack'] === 'zzb5duepinned' && $row['service'] === 'web') { $found = true; break; }
}
/* This assertion is NOT a proof, and is printed as a note rather than
 * counted as a pass.
 *
 * Measured: deleting the pinned-image exclusion from staxx_update_due()
 * leaves this suite entirely green, so as written it demonstrates nothing.
 * The reason is structural. A row only reaches the automatic list when its
 * clock has run out AND staxx_update_clock() reports no reason to refuse —
 * and every one of that function's refusal branches except one is skipped
 * only by the stack actually RUNNING. A stopped stack always answers "this
 * stack is stopped, so it will not update itself".
 *
 * So producing a genuinely due row means starting a container, and this
 * suite runs against a live production server where that is out of the
 * question. The empty answer below is therefore the same empty answer the
 * unexcluded code would give, and cannot tell the two apart.
 *
 * The exclusion is verified by reading instead: it is a plain `continue` on
 * the image containing '@', placed before staxx_update_clock() is consulted,
 * so no policy or running-state condition can route around it. Closing this
 * properly means a suite that can stand a container up and tear it down,
 * which belongs on a scratch box rather than here. */
echo ($found ? 'note   a pinned service WAS found among the automatic candidates - investigate'
             : 'note   pinned-service exclusion not provable here (needs a running container)').PHP_EOL;

/* 14. Its reported status (staxx_update_clock()) is the same verdict a
 * pinned service got before this change — read as it stands, that function
 * has no reference to '@' anywhere in its body; the exclusion above lives
 * only in staxx_update_due(). Proven here by giving an unpinned and a pinned
 * image identical state entries and checking staxx_update_clock() returns
 * structurally identical verdicts for both — the pin makes no difference to
 * what gets reported, only to what gets acted on. A stack-level
 * update.mode: auto override is needed to walk staxx_update_clock() past
 * the "policy is not auto" early return and down to the "is this stack
 * running" branch; neither fixture stack is ever started, so both settle on
 * the same "stopped" verdict. */
$xAuto = "x-unraid:\n  update:\n    mode: auto\n";

$imageA = 'ghcr.io/example/clocktest:latest';
$imageB = $imageA.'@'.dg('d14');

b5_make_stack_raw('zzb5clockA', $xAuto."services:\n  web:\n    image: $imageA\n");
b5_make_stack_raw('zzb5clockB', $xAuto."services:\n  web:\n    image: $imageB\n");

$seen14 = time() - 100;
staxx_update_state_save(['images' => [
  $imageA => ['remote' => dg('d14-remote'), 'seen' => $seen14, 'skip' => ''],
  $imageB => ['remote' => dg('d14-remote'), 'seen' => $seen14, 'skip' => ''],
]]);

$clockA = staxx_update_clock('zzb5clockA', 'web', $imageA);
$clockB = staxx_update_clock('zzb5clockB', 'web', $imageB);

ok('staxx_update_clock() reports the identical verdict for a pinned image as '
 . 'for the same image unpinned',
   $clockA === $clockB, 'A='.json_encode($clockA).' B='.json_encode($clockB));

/* ---------------------------------------------------------------------- */

b5_wipe();

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
