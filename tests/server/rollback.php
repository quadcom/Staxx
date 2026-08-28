<?php
/* Rollback target membership (staxx_update_rollback()'s $target parameter,
 * include/UpdateRun.php) and what the update clock does afterwards
 * (staxx_updates_pill_for_image(), include/Updates.php).
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. Needs STORE_ROOT
 * pointed at /tmp/b4-store, the same way tests/server/imagehistory.php points
 * it at /tmp/b3-store — never the real stack root. staxx_cfg() memoises the
 * first time it is read, so the key is seeded into the config file BEFORE
 * php runs, not changed from inside this script.
 *
 *     pscp tests/server/rollback.php root@<box>:/tmp/
 *     plink … '
 *       CFG=/boot/config/plugins/staxx/staxx.cfg
 *       cp $CFG /tmp/cfg.bak
 *       grep -q "^STORE_ROOT=" $CFG \
 *         && sed -i "s#^STORE_ROOT=.*#STORE_ROOT=\"/tmp/b4-store\"#" $CFG \
 *         || echo "STORE_ROOT=\"/tmp/b4-store\"" >> $CFG
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

if (staxx_stack_root() !== '/tmp/b4-store/stacks') {
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
 * A SUCCESSFUL rollback now saves the compose file with the pin already in
 * it, checks the image is present locally, and starts a job — none of that
 * belongs in a suite that must never touch real docker state or write a
 * stack. Every case below is therefore one the function REFUSES, and the
 * success path is deliberately out of scope.
 *
 * One case is the exception worth naming: the "the service that DID record
 * it gets past the membership test" case has to get past the gate, or it
 * would not be proving the gate can be passed. It calls with no $yaml, so —
 * now that an empty $yaml is refused outright rather than falling back to
 * re-tagging a local image — it is refused one step later than the
 * membership test, by that same "no file text was supplied" check, and
 * never reaches docker at all. Its assertion only checks that the refusal is
 * NOT the membership one, which stays true regardless of exactly which later
 * check catches it, so this comment is what pins down which one actually
 * does right now.
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
// which is what proves the test is per-service rather than per-stack. It
// still refuses one step further on, at the "no $yaml supplied" check (see
// NO_YAML below, defined ahead of use here because this is the first case
// that needs it) — named explicitly rather than left as a bare negative, so
// this asserts exactly where it stops rather than merely where it does not.
const NO_YAML = 'no file text was supplied';
$err = '';
staxx_update_rollback('zzb4twosvc', 'db', $err, dg('t-dbonly'));
ok('...while the service that DID record it gets past the membership test, '
 . 'and is refused next for having no file text',
   !refusedBecause($err, NOT_RECORDED) && refusedBecause($err, NO_YAML), $err);

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

/* ======================================================================= *
 * Part C — the supplied $yaml is not trusted.
 *
 * staxx_update_rollback()'s fifth parameter, $yaml, is the browser's own
 * edited compose text. It is never taken at face value: it is written to a
 * temp file, parsed properly with staxx_compose_meta(), and the requested
 * service's image must be shown to actually carry '@'.$target — a plain
 * strpos() on the raw text would not do, since the digest could just as
 * easily sit inside a comment.
 *
 * EVERY case in this section is one the function must REFUSE. None may
 * reach staxx_save_stack(), docker, or staxx_start_job() — proven case by
 * case in the walk-through in the report, not just asserted here.
 *
 * The check order in the function puts this text check BEFORE the "is the
 * image actually present locally" check that follows it. That ordering is
 * what makes every case below reachable from fixtures alone: there is no
 * real image pulled anywhere on this box, and if the text check did not
 * stop these first, they would all refuse anyway — for the local-image
 * reason instead, which would prove the wrong thing entirely. So the
 * ordering is not incidental here; it is the reason this section can exist
 * without touching docker at all.
 *
 * An empty $yaml is now refused even earlier than this, before the text is
 * even looked at (see NO_YAML in Part A) — every case below supplies real,
 * non-empty text, so that earlier gate is passed through and it is genuinely
 * this section's own check being exercised, not the empty-string one.
 * ======================================================================= */

const NOT_PINNED    = 'does not pin this service to the requested version';
const COULD_NOT_CHECK = 'The supplied file could not be checked';

/* A stack with one recorded digest, reused by most of the cases below. A
 * second digest is recorded too, so case 2 (a different RECORDED digest)
 * is testing a mismatch between two genuine values, not a made-up one. */
b4_make_stack('zzb4textcase', 'web', 'ghcr.io/example/text:latest');
$cReal  = dg('c-real');
$cOlder = dg('c-older');
staxx_image_history_push('zzb4textcase', 'web', $cOlder, []);
staxx_image_history_push('zzb4textcase', 'web', $cReal, []);

// Counted around the whole batch below: every case here is a refusal, so
// none of tempnam()'s files should still exist once the batch is done.
$tmpBefore = glob(sys_get_temp_dir().'/staxx-rb-*');

/* 1. Text that does not name the digest at all — a valid file, but a plain
 * unpinned image. Must be refused by the text check, not waved through to
 * the local-image check further down. */
$yaml1 = <<<'YAML'
services:
  web:
    image: ghcr.io/example/text:latest
YAML;
$err = '';
$res = staxx_update_rollback('zzb4textcase', 'web', $err, $cReal, $yaml1);
ok('an unpinned image is refused by the text check',
   $res === '' && refusedBecause($err, NOT_PINNED), $err);

/* 2. Text naming a DIFFERENT recorded digest than $target — the mismatched-
 * arguments case. $cOlder is a real, recorded digest; it is just not the
 * one this call asked to roll back to. */
$yaml2 = <<<YAML
services:
  web:
    image: ghcr.io/example/text:latest@{$cOlder}
YAML;
$err = '';
$res = staxx_update_rollback('zzb4textcase', 'web', $err, $cReal, $yaml2);
ok('text pinned to a different recorded digest than $target is refused',
   $res === '' && refusedBecause($err, NOT_PINNED), $err);

/* 3. Text that does not parse as compose at all (unterminated quoted
 * scalar — invalid YAML under any parser, not merely something compose
 * dislikes). */
$yaml3 = <<<'YAML'
services:
  web:
    image: "unterminated
YAML;
$err = '';
$res = staxx_update_rollback('zzb4textcase', 'web', $err, $cReal, $yaml3);
ok('text that does not parse at all is refused',
   $res === '' && refusedBecause($err, COULD_NOT_CHECK), $err);

/* 4. Text that parses fine but has no service of that name — the service
 * was renamed. */
$yaml4 = <<<YAML
services:
  website:
    image: ghcr.io/example/text:latest@{$cReal}
YAML;
$err = '';
$res = staxx_update_rollback('zzb4textcase', 'web', $err, $cReal, $yaml4);
ok('text with the requested service renamed away is refused',
   $res === '' && refusedBecause($err, COULD_NOT_CHECK), $err);

/* 5. Text naming the right digest but on a DIFFERENT service in the file,
 * while the requested service is left unpinned — the near miss. */
b4_make_stack('zzb4crosssvc', 'web', 'ghcr.io/example/cross:latest');
$cCross = dg('c-cross');
staxx_image_history_push('zzb4crosssvc', 'web', $cCross, []);
$yaml5 = <<<YAML
services:
  web:
    image: ghcr.io/example/cross:latest
  db:
    image: ghcr.io/example/cross-db:latest@{$cCross}
YAML;
$err = '';
$res = staxx_update_rollback('zzb4crosssvc', 'web', $err, $cCross, $yaml5);
ok('the right digest pinned to a sibling service does not count for the '
 . 'requested one',
   $res === '' && refusedBecause($err, NOT_PINNED), $err);

/* 6. Empty-ish text that is NOT '' — must still be checked (and refused),
 * not treated as "no $yaml supplied" and silently fall back to the old
 * tag-moving behaviour. */
foreach (['a bare newline' => "\n", 'a comment only' => "# just a comment\n"] as $label => $yaml6) {
  $err = '';
  $res = staxx_update_rollback('zzb4textcase', 'web', $err, $cReal, $yaml6);
  ok('empty-ish but non-empty text ('.$label.') is checked and refused, not '
   . 'treated as no-$yaml',
     $res === '' && $yaml6 !== '' && refusedBecause($err, COULD_NOT_CHECK), $err);
}

/* 7. Membership is still enforced when text is supplied — a $yaml that
 * correctly names a digest this service never recorded must still be
 * caught by the membership test (checked BEFORE the text is even looked
 * at), proving the new parameter did not open a way round that gate. */
$cNeverRecorded = dg('c-never-recorded-for-text');
$yaml7 = <<<YAML
services:
  web:
    image: ghcr.io/example/text:latest@{$cNeverRecorded}
YAML;
$err = '';
$res = staxx_update_rollback('zzb4textcase', 'web', $err, $cNeverRecorded, $yaml7);
ok('a $yaml correctly pinned to a digest never recorded for this service '
 . 'is still caught by the membership test, not the text check',
   $res === '' && refusedBecause($err, NOT_RECORDED), $err);

/* 8. A four-argument call — a real, recorded target, but no $yaml — is
 * refused outright, by the NO_YAML message specifically and no other. The
 * old behaviour (re-point the local tag, file untouched) is gone: an empty
 * $yaml is no longer a weaker-but-working fallback, it is a footgun closed
 * off. $cReal is genuinely recorded for this stack/service, so this proves
 * the refusal is the "no file text" one and not a membership failure in
 * disguise. */
$err = '';
$res = staxx_update_rollback('zzb4textcase', 'web', $err, $cReal);
ok('a four-argument call with no $yaml is refused for having no file text, '
 . 'not waved through to the old tag-moving behaviour',
   $res === '' && refusedBecause($err, NO_YAML) && !refusedBecause($err, NOT_RECORDED), $err);

$tmpAfter = glob(sys_get_temp_dir().'/staxx-rb-*');
ok('none of the temp files this batch created are still on disk',
   count($tmpBefore) === count($tmpAfter),
   count($tmpBefore).' before, '.count($tmpAfter).' after');

/* ---------------------------------------------------------------------- */

b4_wipe();

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
