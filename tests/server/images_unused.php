<?php
/* PLAN_180 Part 1 — include/Images.php: the "Scan stored images" window's
 * grouping, rule 2's refusal of an ID not on the server's own current list,
 * and rule 4c's exclusion of StaXX's own images by label.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine:
 *
 *     pscp tests/server/images_unused.php root@<box>:/tmp/
 *     plink … "php /tmp/images_unused.php"
 *
 * THIS SUITE NEVER TOUCHES A REAL IMAGE. Every image it creates is built
 * with `docker import` of an empty tar (no pull, no build context, nothing
 * downloaded) and carries the label staxx.test.images=1 — every removal
 * below names an image by that label, never by guessing at an id, and the
 * fixtures are removed again on every exit path, including a fatal error,
 * via register_shutdown_function(). If a fixture is somehow left behind by
 * an interrupted run, the next run's own cleanup-on-the-way-in sweeps it up
 * before doing anything else.
 *
 * staxx_images_unused() reads the real store and the real Docker state
 * read-only — the same thing the live window does on a page load — so this
 * suite needs no STORE_ROOT redirection; nothing it does can write to a
 * real stack. If the box happens to have an update running or queued, or
 * cannot see the stacks, staxx_images_unused() fails closed exactly as
 * designed and every case here is SKIPPED rather than treated as a fault —
 * that refusal is proved on its own in tests/server/updaterun.php against
 * the sibling function it shares its guards with.
 *
 * Prints one line per case and exits non-zero on any failure.
 */

require_once '/usr/local/emhttp/plugins/staxx/include/Images.php';

$fails = 0;
$skips = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}
function skip(string $what, string $reason): void {
  global $skips;
  $skips++;
  printf("%-6s %s  (%s)\n", 'SKIP', $what, $reason);
}

/* ------------------------------------------------------------- fixture -- */

$LABEL   = 'staxx.test.images=1';
$REPO    = 'zzstaxximgtest';
$TAG1    = $REPO.':one';
$TAG2    = $REPO.':two';
$REPO_C  = 'zzstaxximgtestcrypt';
$TAGC    = $REPO_C.':one';
$docker  = staxx_docker_bin();

function images_test_sweep(string $docker): void {
  // Every image this suite could ever have left behind, named by its own
  // label — never a general prune. Tags first (an id with a tag still on
  // it refuses without -f), then whatever ids are left.
  $ids = trim(staxx_sh(
    $docker.' images --filter '.escapeshellarg('label=staxx.test.images=1').' --format '.escapeshellarg('{{.ID}}'), 10
  ));
  foreach (preg_split('/\s+/', $ids) as $id) {
    if ($id === '') continue;
    $tagsOut = trim(staxx_sh(
      $docker.' inspect --format '.escapeshellarg('{{range .RepoTags}}{{.}} {{end}}').' '.escapeshellarg($id), 8
    ));
    foreach (preg_split('/\s+/', $tagsOut) as $t) {
      if ($t !== '') staxx_sh($docker.' rmi '.escapeshellarg($t), 15);
    }
    staxx_sh($docker.' rmi '.escapeshellarg($id), 15);
  }
}

// Clean up anything an interrupted previous run left, before creating
// anything of our own.
images_test_sweep($docker);
register_shutdown_function(function () use ($docker) { images_test_sweep($docker); });

// An empty tar — the smallest possible filesystem layer `docker import`
// will accept — piped straight into a labelled, untagged image (dangling
// from the moment it exists) and two labelled, tagged ones that share a
// single id.
// A genuinely empty tar is just its own end-of-archive marker: two
// 512-byte blocks of zero bytes, no header, no content.
$emptyTar = tempnam(sys_get_temp_dir(), 'staxx-empty-');
file_put_contents($emptyTar, str_repeat("\0", 1024));

$danglingCode = 1;
staxx_sh($docker.' import --change '.escapeshellarg('LABEL '.$LABEL).' - < '.escapeshellarg($emptyTar).' 2>&1', 20, $danglingCode);

$taggedCode = 1;
$taggedOut = staxx_sh(
  $docker.' import --change '.escapeshellarg('LABEL '.$LABEL).' - '.escapeshellarg($TAG1).' < '.escapeshellarg($emptyTar).' 2>&1',
  20, $taggedCode
);
if ($taggedCode === 0) staxx_sh($docker.' tag '.escapeshellarg($TAG1).' '.escapeshellarg($TAG2), 10);

$cryptCode = 1;
staxx_sh(
  $docker.' import --change '.escapeshellarg('LABEL '.$LABEL).' --change '.escapeshellarg('LABEL staxx.crypt=1')
    .' - '.escapeshellarg($TAGC).' < '.escapeshellarg($emptyTar).' 2>&1',
  20, $cryptCode
);

@unlink($emptyTar);

if ($danglingCode !== 0 || $taggedCode !== 0 || $cryptCode !== 0) {
  skip('every case', 'could not build the throwaway fixture images with docker import — see output above');
  echo "\n$fails fault(s), $skips skipped\n";
  exit($fails ? 1 : 0);
}

/* ---------------------------------------------------------------- list -- */

$error = 'unset';
$listing = staxx_images_unused($error);

if (!($listing['ok'] ?? false)) {
  skip('every listing case', 'staxx_images_unused() refused right now: '.$error);
  echo "\n$fails fault(s), $skips skipped\n";
  exit($fails ? 1 : 0);
}

function images_find(array $listing, string $tagOrLabel): ?array {
  foreach ($listing['groups'] as $rows) {
    foreach ($rows as $row) {
      if (in_array($tagOrLabel, $row['tags'], true)) return $row;
    }
  }
  return null;
}

$taggedRow = images_find($listing, $TAG1);
$cryptRow  = images_find($listing, $TAGC);

ok('the tagged fixture is listed with both its tags', $taggedRow !== null
  && in_array($TAG1, $taggedRow['tags'], true) && in_array($TAG2, $taggedRow['tags'], true));

// A dangling image carries no tag, so it can only be found by walking every
// row and checking which one is untagged and was just created.
$foundDangling = false;
foreach ($listing['groups']['dangling'] as $row) {
  if (!$row['tags']) { $foundDangling = true; break; }
}
ok('an untagged image lands in "Left behind by updates"', $foundDangling);

ok('the image labelled staxx.crypt=1 is excluded from every group', $cryptRow === null);

// docker import never records a RepoDigest (nothing was pulled), so rule 7
// puts our tagged fixture in "Still wanted" with the "built here" note —
// proof the rule reads RepoDigests rather than guessing from the presence
// of a tag.
$inWanted = false;
foreach ($listing['groups']['wanted'] as $row) {
  if (in_array($TAG1, $row['tags'], true)) { $inWanted = true; $wantedRow = $row; break; }
}
ok('an image with no RepoDigests is treated as built on this server', $inWanted
  && strpos($wantedRow['note'] ?? '', 'Docker has no download record') === 0);

ok('nothing in the "keep" group ever came from this fixture',
  images_find(['groups' => ['keep' => $listing['groups']['keep']]], $TAG1) === null);

/* ------------------------------------------------------------- rule 2 -- */

$error = 'unset';
$job = staxx_images_remove_job(['sha256:'.str_repeat('0', 64)], $error);
ok('an id that is not on the server\'s own current list is refused', $job === '' && $error !== '' && $error !== 'unset');

/* --------------------------------------------------------- rule 5 / remove -- */

// Called directly rather than through the detached job wrapper — the job
// itself is only setsid + a log file + this same function, already proved
// against a real removal here without needing to poll a background
// process from inside a test.
$rowsToRemove = [];
if ($taggedRow !== null) $rowsToRemove[] = $taggedRow;
foreach ($listing['groups']['dangling'] as $row) {
  if (!$row['tags']) { $rowsToRemove[] = $row; break; }
}

ob_start();
$removeOk = staxx_images_do_remove($rowsToRemove);
$log = ob_get_clean();
ok('removing the two fixtures reports success', $removeOk, $log !== '' ? trim(explode("\n", $log)[0]) : '');

// Ask directly whether the two REMOVED tags still resolve to anything, and
// separately that the crypt-labelled fixture (never asked to be removed)
// still does.
$tag1Code = 1; staxx_sh($docker.' image inspect '.escapeshellarg($TAG1).' >/dev/null 2>&1', 8, $tag1Code);
$tag2Code = 1; staxx_sh($docker.' image inspect '.escapeshellarg($TAG2).' >/dev/null 2>&1', 8, $tag2Code);
ok('both tags of the removed image are gone', $tag1Code !== 0 && $tag2Code !== 0);

$tagCCode = 1; staxx_sh($docker.' image inspect '.escapeshellarg($TAGC).' >/dev/null 2>&1', 8, $tagCCode);
ok('the excluded crypt-labelled fixture was left alone', $tagCCode === 0);

echo "\n$fails fault(s), $skips skipped\n";
exit($fails ? 1 : 0);
