<?php
/* Rollback target membership (staxx_update_rollback()'s $target parameter,
 * include/UpdateRun.php) and what the update clock does afterwards
 * (staxx_updates_pill_for_image(), include/Updates.php).
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. Needs STACK_ROOT
 * pointed at /tmp/b4-root, the same way tests/server/imagehistory.php points
 * it at /tmp/b3-root — never the real stack root. staxx_cfg() memoises the
 * first time it is read, so the key is seeded into the config file BEFORE
 * php runs, not changed from inside this script.
 *
 *     pscp tests/server/rollback.php root@<box>:/tmp/
 *     plink … '
 *       CFG=/boot/config/plugins/staxx/staxx.cfg
 *       cp $CFG /tmp/cfg.bak
 *       sed -i "s#^STACK_ROOT=.*#STACK_ROOT=\"/tmp/b4-root\"#" $CFG
 *       grep -q "^STACK_ROOT=" $CFG || echo "STACK_ROOT=\"/tmp/b4-root\"" >> $CFG
 *       php /tmp/rollback.php; RC=$?
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
 * removes its own stacks, "zzb4…", under the temporary stack root, and its
 * own scratch state file. Cleans up on the way in too, so a previous
 * interrupted run cannot affect this one.
 *
 * Nothing here runs docker, starts a job, or removes an image — see the
 * comment blocks above Part A and Part B for exactly why each half is safe
 * to run against a live box. */

$scratch = '/tmp/staxx-rollback-test.json';
@unlink($scratch);
putenv('STAXX_UPDATE_STATE='.$scratch);

register_shutdown_function(function () use ($scratch) {
  @unlink($scratch);
});

require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/UpdateRun.php';
require_once '/usr/local/emhttp/plugins/staxx/include/ImageHistory.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

/* A digest has to look like a real one. staxx_image_history_valid_entry()
 * refuses anything that is not "algo:" plus at least 32 hex characters, so a
 * short fake would be pushed and then never read back — see dg()'s note in
 * tests/server/imagehistory.php, which caught exactly that asymmetry. */
function dg(string $label): string {
  return 'sha256:'.substr(hash('sha256', $label), 0, 64);
}

if (staxx_stack_root() !== '/tmp/b4-root') {
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
function b4_wipe(): void {
  global $root, $scratch;
  @exec('rm -rf '.escapeshellarg($root));
  mkdir($root, 0755, true);
  @unlink($scratch);
}
b4_wipe();

function b4_make_stack(string $rel, string $service = 'web', string $image = 'alpine:3.20'): void {
  global $root;
  $dir = $root.'/'.$rel;
  @exec('rm -rf '.escapeshellarg($dir));
  mkdir($dir, 0755, true);
  file_put_contents($dir.'/compose.yaml', "services:\n  $service:\n    image: $image\n");

  // The stack list is memoised for the rest of the request — a fixture
  // built by hand has to reset it or a stack created after something has
  // already looked is invisible for the whole run.
  staxx_scan_stacks_reset();
}

/* ======================================================================= *
 * Part A — the rollback's target membership rule.
 *
 * staxx_update_rollback()'s optional fourth parameter, $target, must be
 * checked for strict membership in staxx_update_history($stack, $service)
 * before anything else happens. This is the security boundary: without it a
 * request could re-tag a service's image to any digest present on the
 * server, not just one it actually ran.
 *
 * A SUCCESSFUL rollback runs `docker image inspect`, `docker tag` and starts
 * a job — none of that belongs in a suite that must never touch real docker
 * state. Every case below is therefore one the function REFUSES, and the
 * success path is deliberately out of scope.
 *
 * One case is the exception worth naming: the "the service that DID record
 * it gets past the membership test" case has to get past the gate, or it
 * would not be proving the gate can be passed. It reaches a read-only
 * `docker image inspect` for an image that does not exist, which fails, and
 * the function refuses there — so no `docker tag` and no job ever run. That
 * is the furthest anything in this file goes.
 * ======================================================================= */

b4_make_stack('zzb4target', 'web', 'ghcr.io/example/target:latest');
staxx_image_history_push('zzb4target', 'web', dg('t-real'), []);

b4_make_stack('zzb4other', 'db', 'ghcr.io/example/other:latest');
staxx_image_history_push('zzb4other', 'db', dg('t-otherservice'), []);

b4_make_stack('zzb4elsewhere', 'web', 'ghcr.io/example/elsewhere:latest');
staxx_image_history_push('zzb4elsewhere', 'web', dg('t-otherstack'), []);

/* Which refusal fired matters, not merely that one did. A membership test
 * replaced by a shape check ("does $target look like algo:hex?") would let
 * every well-shaped case below through the gate and on to `docker image
 * inspect`, which would then refuse it too — for a completely different
 * reason. Asserting only "$res === '' and $err !== ''" therefore passes
 * identically against the wrong implementation, and proves nothing. So each
 * case names the refusal it expects, and a wrong one is a failure. Matching
 * on message text is normally poor practice; here the message IS the only
 * observable difference between a gate that holds and one that does not.
 *
 * It also proves the shell was never reached: if the error is the membership
 * one, docker was not called. */
function refusedBecause(string $err, string $fragment): bool {
  return $err !== '' && strpos($err, $fragment) !== false;
}
const NOT_RECORDED = 'not one recorded for this service';

/* 1. Correctly shaped, but never recorded for this service at all. */
$err = '';
$res = staxx_update_rollback('zzb4target', 'web', $err, dg('t-never-recorded'));
ok('a well-shaped digest never recorded for this service is refused BY the '
 . 'membership test, not by docker further down',
   $res === '' && refusedBecause($err, NOT_RECORDED), $err);

/* 2. Recorded, but for a DIFFERENT service in the SAME stack. Both services
 * must really exist in the compose file, or this refuses for the trivial
 * reason that the service is unknown and never reaches the membership test —
 * which is how this case was first written, and it proved nothing. */
$twoDir = $root.'/zzb4twosvc';
@exec('rm -rf '.escapeshellarg($twoDir));
mkdir($twoDir, 0755, true);
file_put_contents($twoDir.'/compose.yaml',
  "services:
  web:
    image: ghcr.io/example/two-web:latest
"
 ."  db:
    image: ghcr.io/example/two-db:latest
");
staxx_scan_stacks_reset();
staxx_image_history_push('zzb4twosvc', 'db', dg('t-dbonly'), []);

$err = '';
$res = staxx_update_rollback('zzb4twosvc', 'web', $err, dg('t-dbonly'));
ok('a digest recorded for a sibling service in the SAME stack is refused',
   $res === '' && refusedBecause($err, NOT_RECORDED), $err);

// ...and the service it really belongs to is not refused for that reason,
// which is what proves the test is per-service rather than per-stack.
$err = '';
staxx_update_rollback('zzb4twosvc', 'db', $err, dg('t-dbonly'));
ok('...while the service that DID record it gets past the membership test',
   !refusedBecause($err, NOT_RECORDED), $err);

/* 3. Recorded, but for the SAME service name in a DIFFERENT stack. */
$err = '';
$res = staxx_update_rollback('zzb4target', 'web', $err, dg('t-otherstack'));
ok('a digest recorded for the same service name in a different stack is refused',
   $res === '' && refusedBecause($err, NOT_RECORDED), $err);

/* 4. Malformed digests — none of these must reach the shell. */
$malformed = [
  'empty-ish rubbish'        => 'not-a-digest',
  'a bare algorithm, no hex' => 'sha256:',
  'path traversal shaped'    => 'sha256:../../../../etc/passwd',
  'a shell metacharacter'    => 'sha256:aaaa;rm -rf /',
];
foreach ($malformed as $label => $bad) {
  $err = '';
  $res = staxx_update_rollback('zzb4target', 'web', $err, $bad);
  ok('a malformed target ('.$label.') is refused before anything runs',
     $res === '' && refusedBecause($err, NOT_RECORDED), $err);
}

/* 5. A stack that does not exist, and a service that does not exist in a
 * real stack — refused with a full sentence, and $target being non-empty
 * must not change that. */
$err = '';
$res = staxx_update_rollback('zzb4neverexisted', 'web', $err, dg('t-real'));
ok('a stack that does not exist is refused with a full sentence',
   $res === '' && str_word_count($err) >= 3, $err);

$err = '';
$res = staxx_update_rollback('zzb4target', 'noservice', $err, dg('t-real'));
ok('a service that does not exist in a real stack is refused with a full sentence',
   $res === '' && str_word_count($err) >= 3, $err);

/* 6. A service whose compose entry has no image at all. */
b4_make_stack('zzb4noimage', 'bare', 'placeholder:1');
// A service with NOTHING but a command is dropped by the parser altogether,
// so the refusal comes back as "no such service" and the no-image branch is
// never reached - which is how this case first passed for the wrong reason.
// A build: key keeps the service real while leaving its image empty, which
// is the arrangement that actually exercises it.
file_put_contents($root.'/zzb4noimage/compose.yaml',
  "services:\n  bare:\n    build: ./bare\n");
staxx_scan_stacks_reset();
staxx_image_history_push('zzb4noimage', 'bare', dg('t-real'), []);
$err = '';
$res = staxx_update_rollback('zzb4noimage', 'bare', $err, dg('t-real'));
ok('a service with no image set is refused for THAT reason, not as unknown',
   $res === '' && refusedBecause($err, 'no image set'), $err);

/* 7. The old, untargeted path must be untouched by the new parameter. With
 * no history at all and no target, the refusal must still be the original
 * "nothing recorded" one — not the membership message, which would mean the
 * new branch had swallowed the default. */
b4_make_stack('zzb4nohistory', 'web', 'ghcr.io/example/nohistory:latest');
$err = '';
$res = staxx_update_rollback('zzb4nohistory', 'web', $err);
ok('with no target and no history, the original refusal is unchanged',
   $res === '' && refusedBecause($err, 'no earlier version recorded'), $err);

/* ======================================================================= *
 * Part B — what the update clock does after a rollback.
 *
 * The claim under test: the version a rollback moved away from cannot come
 * back on its own — a "skip" fingerprint is enough, no hold is needed — but
 * a genuinely NEWER version still arrives, and clears any hold when it
 * does. If this holds, no "hold" control needs to be offered after a
 * rollback: the protection is automatic, and a hold would expire exactly
 * when it would first have mattered.
 *
 * staxx_updates_pill_for_image() is pure — it takes the images map as an
 * argument and returns the verdict for one image, with no file, docker or
 * network access — so it is called directly with hand-built maps below.
 * ======================================================================= */

$image = 'ghcr.io/example/rolledback:latest';

/* 1. Rolled back: local is the old version, remote is the version rolled
 * away from, and skip equals that remote digest. Must not offer an update. */
$images = [
  $image => [
    'local'  => dg('b-old'),
    'remote' => dg('b-rolledback'),
    'skip'   => dg('b-rolledback'),
  ],
];
$pill = staxx_updates_pill_for_image($image, $images);
ok('a rolled-back version does not come back on its own (state != "update")',
   $pill['state'] !== 'update', 'state='.$pill['state']);

/* 2. A newer version then appears: same entry, but remote is now a THIRD,
 * newer digest, while skip still holds the old rolled-away-from one. */
$images2 = [
  $image => [
    'local'  => dg('b-old'),
    'remote' => dg('b-newer'),
    'skip'   => dg('b-rolledback'),
  ],
];
$pill2 = staxx_updates_pill_for_image($image, $images2);
ok('a genuinely newer version is offered even though an old skip is still set',
   $pill2['state'] === 'update', 'state='.$pill2['state']);

/* 3. A hold present when a newer version appears.
 *
 * Read as it stands: the hold is cleared by include/Updates.php's own check
 * pass — staxx_update_check(), inside the branch that first notices a fresh
 * remote digest — not by staxx_updates_pill_for_image(). The pill function
 * never reads or writes 'hold' at all; it has no such key anywhere in its
 * body. So this half of the claim is NOT provable by calling the pill
 * function, however the fixture map is built — a 'hold' key handed to it
 * would simply be ignored, and any assertion here would pass without
 * exercising the clearing code at all. That would be a green line that
 * proves nothing, which is worse than no line.
 *
 * Proving hold-clearing for real means calling staxx_update_check() with a
 * live (or faked) registry answer, which reaches past what this file's
 * fixtures-only, no-network brief allows. That belongs in a suite built
 * around staxx_update_check() itself, not here. */
/* Printed as a note rather than passed as a case on purpose: an ok() that is
 * hardcoded true adds a green line to the count and reads, to anyone
 * skimming the output, exactly like something that was proved. */
echo "note   hold-clearing lives in staxx_update_check(), not in the pure pill
"
   . "note   function — not provable from here, and deliberately not faked.
";

/* ---------------------------------------------------------------------- */

b4_wipe();

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
