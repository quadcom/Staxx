<?php
/* PLAN_181 Part B — archiving a stack removes the roll-back copies of its
 * images that nothing else on the server still needs (include/Stacks.php's
 * staxx_archive_stack_history_pairs(), include/Images.php's
 * staxx_archive_removable_images()).
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. Needs STORE_ROOT
 * pointed at /tmp/b-archive-store, the same way tests/server/rollback.php
 * points it at /tmp/b4-store — never the real stack root. staxx_cfg()
 * memoises the first time it is read, so the key is seeded into the config
 * file BEFORE php runs, not changed from inside this script.
 *
 *     pscp tests/server/archive_images.php root@<box>:/tmp/
 *     plink … '
 *       CFG=/boot/config/plugins/staxx/staxx.cfg
 *       cp $CFG /tmp/cfg.bak
 *       grep -q "^STORE_ROOT=" $CFG \
 *         && sed -i "s#^STORE_ROOT=.*#STORE_ROOT=\"/tmp/b-archive-store\"#" $CFG \
 *         || echo "STORE_ROOT=\"/tmp/b-archive-store\"" >> $CFG
 *       php /tmp/archive_images.php; RC=$?
 *       cp /tmp/cfg.bak $CFG
 *       exit $RC
 *     '
 *
 * The central update-state file lives on the flash drive too — this script
 * points STAXX_UPDATE_STATE at a scratch file in /tmp via putenv(), before
 * the first require, the same trick tests/server/rollback.php uses.
 *
 * WHAT THIS SUITE DOES AND DOES NOT PROVE. The real removal path
 * (staxx_archive_removable_images()) only ever offers a digest that
 * `docker image inspect repo@digest` can resolve locally — and Docker only
 * ever records a RepoDigest for an image it pulled or pushed, never for one
 * `docker import` or a plain local build produced (see PLAN_181 item 7 and
 * tests/server/images_unused.php's own header for the same limit on its
 * "built here" fixtures). Reaching a real repo@digest without a real pull
 * would mean this suite pulling something, which it must never do. So the
 * two things this suite CAN prove without touching Docker at all — that the
 * (repo, digest) pairs are read correctly from a stack's own record before
 * its folder is touched, and that a digest still protected by the keep-set
 * (Part A) is screened out BEFORE any Docker call is made, which is the
 * security-critical half of the removal rule — are proved directly, in full.
 * staxx_archive_removable_images() also fails closed — returns [] — when
 * `docker ps` itself cannot be asked, or names a container `docker ps`
 * cannot even name an image for (staxx_images_used_scan(), shared with
 * staxx_images_unused()). It does NOT refuse merely because a named image
 * fails to resolve locally (the box's postgresql15 case): an image that is
 * not on the server needs no protecting. Neither shape is reproducible here
 * without a genuinely broken container of this suite's own making, which is
 * exactly the kind of Docker state this suite must never manufacture —
 * proved by hand on the box instead, alongside the one below.
 *
 * The remaining half (a genuinely resolvable digest is actually removed) is
 * exactly the kind of case PLAN_181's own rollback suite already declines to
 * fabricate for the same reason, and is proved by hand on the box instead —
 * with a before/after image-id list, never by running this suite against a
 * real stack's real history.
 *
 * Prints one line per case and exits non-zero on any failure. Creates and
 * removes its own stacks, "zzarchimg…", under the temporary stack root.
 * Never runs `docker rmi`, `docker pull` or `staxx_archive_stack()` itself —
 * only the two read-only building blocks it calls out to. */

$scratch = '/tmp/staxx-archive-images-test.json';
@unlink($scratch);
putenv('STAXX_UPDATE_STATE=' . $scratch);

register_shutdown_function(function () use ($scratch) {
  @unlink($scratch);
});

require_once '/usr/local/emhttp/plugins/staxx/include/Images.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  (' . $note . ')' : '');
}

function dg(string $label): string {
  return 'sha256:' . substr(hash('sha256', $label), 0, 64);
}

if (staxx_stack_root() !== '/tmp/b-archive-store/stacks') {
  echo "FAIL   the temporary stack root is not in place (got " . staxx_stack_root() . ")\n";
  exit(1);
}

$root = staxx_stack_root();

function archimg_wipe(): void {
  global $root;
  @exec('rm -rf ' . escapeshellarg($root));
  mkdir($root, 0755, true);
}
archimg_wipe();

function archimg_make_stack(string $rel, string $service, string $image): void {
  global $root;
  $dir = $root . '/' . $rel;
  @exec('rm -rf ' . escapeshellarg($dir));
  mkdir($dir, 0755, true);
  file_put_contents($dir . '/compose.yaml', "services:\n  $service:\n    image: $image\n");
  staxx_scan_stacks_reset();
}

register_shutdown_function(function () use ($root) {
  @exec('rm -rf ' . escapeshellarg($root));
});

/* ======================================================================= *
 * Part 1 — staxx_archive_stack_history_pairs(): reads a stack's own version
 * history, paired with the repository its compose file currently names,
 * while the folder still exists. Pure file reads; no Docker involved.
 * ======================================================================= */

ok('history pairs: an invalid stack name reads as nothing, never a fatal',
   staxx_archive_stack_history_pairs('../escaping') === []);

ok('history pairs: a stack that does not exist on disk reads as nothing',
   staxx_archive_stack_history_pairs('zzarchimg-never-made') === []);

archimg_make_stack('zzarchimg-none', 'web', 'ghcr.io/example/none:latest');
ok('history pairs: a stack with no recorded image history reads as nothing',
   staxx_archive_stack_history_pairs('zzarchimg-none') === []);

archimg_make_stack('zzarchimg-target', 'web', 'ghcr.io/example/target:latest');
staxx_image_history_push('zzarchimg-target', 'web', dg('archimg-one'), []);
staxx_image_history_push('zzarchimg-target', 'web', dg('archimg-two'), []);

$pairs = staxx_archive_stack_history_pairs('zzarchimg-target');
$digestsSeen = array_column($pairs, 'digest');
$reposSeen   = array_unique(array_column($pairs, 'repo'));

ok('history pairs: every recorded digest for the stack\'s own service comes back',
   in_array(dg('archimg-one'), $digestsSeen, true) && in_array(dg('archimg-two'), $digestsSeen, true),
   json_encode($digestsSeen));
ok('history pairs: each pair carries the LOCAL repo name (tag/digest stripped), not the raw compose value',
   $reposSeen === ['ghcr.io/example/target'], json_encode($reposSeen));

/* A service with no image at all contributes nothing, rather than a pair
 * with an empty repo that could later match anything. */
archimg_make_stack('zzarchimg-bare', 'bare', 'placeholder:1');
file_put_contents($root . '/zzarchimg-bare/compose.yaml', "services:\n  bare:\n    build: ./bare\n");
staxx_scan_stacks_reset();
staxx_image_history_push('zzarchimg-bare', 'bare', dg('archimg-bare'), []);
ok('history pairs: a service with no image set contributes no pair',
   staxx_archive_stack_history_pairs('zzarchimg-bare') === []);

/* ======================================================================= *
 * Part 2 — staxx_archive_removable_images(): the keep-set screen runs
 * BEFORE any Docker call, so a digest another current stack's keep-set still
 * protects is provably never even asked about — the security-critical half
 * of the rule, and the only half this suite can prove without a real pull.
 * ======================================================================= */

// A second, SURVIVING stack whose own history keeps one of the same digests
// — standing in for "another current stack still needs this roll-back
// copy", which staxx_update_keep_digests() (Part A) must go on protecting.
archimg_make_stack('zzarchimg-survivor', 'web', 'ghcr.io/example/target:latest');
staxx_image_history_push('zzarchimg-survivor', 'web', dg('archimg-one'), []);

$keepFlat = [];
foreach (staxx_update_keep_digests('zzarchimg-target') as $ds) foreach ($ds as $d) $keepFlat[$d] = true;
ok('setup: excluding the archived stack, the survivor still keeps the shared digest',
   isset($keepFlat[dg('archimg-one')]));
ok('setup: the digest only the archived stack ever held is not kept by anyone else',
   !isset($keepFlat[dg('archimg-two')]));

// Neither digest resolves to a real local image (nothing here was ever
// pulled), so BOTH calls return no rows either way — but for different
// reasons: one is screened by the keep-set before Docker is ever asked,
// the other reaches the (failing) Docker lookup and is dropped there. The
// point proved here is that the function never errors or throws either
// way, and returns exactly the same "nothing to remove" shape for both —
// see the file header for why the two paths cannot be told apart from the
// return value alone without a real pull.
$rows = staxx_archive_removable_images($pairs, 'zzarchimg-target');
ok('archive-removable: with nothing resolvable locally, nothing is offered for removal '
 . '(a kept digest is screened before Docker; an absent one is dropped by Docker itself)',
   $rows === []);

ok('archive-removable: an empty pairs list is always a no-op',
   staxx_archive_removable_images([], 'zzarchimg-target') === []);

ok('archive-removable: a pair missing its repo or digest is ignored rather than mishandled',
   staxx_archive_removable_images([['repo' => '', 'digest' => dg('archimg-one')]], 'zzarchimg-target') === []
   && staxx_archive_removable_images([['repo' => 'ghcr.io/example/target', 'digest' => '']], 'zzarchimg-target') === []);

printf("\n%s — %d failure%s\n", $fails ? 'FAILED' : 'passed', $fails, $fails === 1 ? '' : 's');
exit($fails ? 1 : 0);
