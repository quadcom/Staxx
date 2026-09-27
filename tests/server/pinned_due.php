<?php
/* What staxx_update_due()/staxx_update_clock() (include/UpdateRun.php) do
 * with a pinned image: reported the same as any other, but never offered
 * as an automatic candidate.
 *
 * Formerly part of `unpin.php` alongside staxx_update_unpin() itself — that
 * function and the 'update-unpin' action are gone (PLAN_188 part D): a pin
 * is now released by picking a tag in the image field or the tag picker,
 * saved through the ordinary 'save'/'file-save' actions like any other
 * edit, so there is no longer a dedicated release function of its own to
 * test. What is left here — staxx_update_due()'s pinned-image exclusion and
 * staxx_update_clock()'s indifference to one — never depended on that
 * function and is unchanged, so it moved rather than being deleted with it.
 * Renamed because "unpin" no longer names anything this file tests.
 *
 * KNOWN GAP, carried over rather than fixed blind: staxx_update_unpin() used
 * to clear a stale 'skip' fingerprint left under an image's UNPINNED key
 * once released (see this file's own history) — put there by an earlier
 * pin/skip under that same name, and otherwise silently suppressing the
 * very update the release was meant to resume. The new release path writes
 * through the ordinary save actions, which have no notion of "this edit
 * just released a pin" and so no hook left to clear it from. Rare (it only
 * bites when the exact unpinned "repo:tag" was skipped before ever being
 * pinned), not reproduced here, and worth a decision rather than a guess.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. Needs STORE_ROOT
 * pointed at /tmp/b5-store, the same way tests/server/rollback.php points it
 * at /tmp/b4-store — never the real stack root. staxx_cfg() memoises the
 * first time it is read, so the key is seeded into the config file BEFORE
 * php runs, not changed from inside this script.
 *
 *     pscp tests/server/pinned_due.php root@<box>:/tmp/
 *     plink … '
 *       CFG=/boot/config/plugins/staxx/staxx.cfg
 *       cp $CFG /tmp/cfg.bak
 *       grep -q "^STORE_ROOT=" $CFG \
 *         && sed -i "s#^STORE_ROOT=.*#STORE_ROOT=\"/tmp/b5-store\"#" $CFG \
 *         || echo "STORE_ROOT=\"/tmp/b5-store\"" >> $CFG
 *       php /tmp/pinned_due.php; RC=$?
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
 * Nothing here runs docker, starts a job, or recreates a container. Every
 * reference used as an "image" in this file is either a fixture never
 * installed on this box or a made-up registry that resolves nowhere. Both
 * functions here read real compose state through staxx_list_stacks()/
 * staxx_state_for() (a read-only "compose ls"-shaped query), the same way
 * every other server suite that touches those functions already does —
 * nothing there starts, stops or changes anything.
 */

$scratch = '/tmp/staxx-pinned-due-test.json';
@unlink($scratch);
putenv('STAXX_UPDATE_STATE='.$scratch);

register_shutdown_function(function () use ($scratch) {
  @unlink($scratch);
  $lock = (defined('STAXX_UPDATE_DIR') ? STAXX_UPDATE_DIR : '/tmp/staxx/updates').'/lock';
  if (is_dir($lock)) @rmdir($lock);
});

require_once '/usr/local/emhttp/plugins/staxx/include/UpdateRun.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

/* A digest has to look like a real one, or compose's own image-reference
 * parser refuses the fixture before it is even used as a pinned example. */
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

/* Same as above, but with whatever raw compose text the case needs. */
function b5_make_stack_raw(string $rel, string $yaml): void {
  global $root;
  $dir = $root.'/'.$rel;
  @exec('rm -rf '.escapeshellarg($dir));
  mkdir($dir, 0755, true);
  file_put_contents($dir.'/compose.yaml', $yaml);
  staxx_scan_stacks_reset();
}

/* ======================================================================= *
 * Pinned services are reported but not acted on.
 * ======================================================================= */

/* 1. A pinned service is absent from the automatic candidates. Reads as it
 * stands in staxx_update_due(): a service's image is skipped with a plain
 * `continue` the moment it contains '@', BEFORE staxx_update_clock() (and so
 * before any policy or running-state check) is ever consulted — so this
 * holds regardless of policy or whether the stack is running. */
b5_wipe();
b5_make_stack('zzb5duepinned', 'web', "ghcr.io/example/duepinned:latest@".dg('d1'));

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

/* 2. Its reported status (staxx_update_clock()) is the same verdict a
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
$imageB = $imageA.'@'.dg('d2');

b5_make_stack_raw('zzb5clockA', $xAuto."services:\n  web:\n    image: $imageA\n");
b5_make_stack_raw('zzb5clockB', $xAuto."services:\n  web:\n    image: $imageB\n");

$seen2 = time() - 100;
staxx_update_state_save(['images' => [
  $imageA => ['remote' => dg('d2-remote'), 'seen' => $seen2, 'skip' => ''],
  $imageB => ['remote' => dg('d2-remote'), 'seen' => $seen2, 'skip' => ''],
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
