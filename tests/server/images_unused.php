<?php
/* PLAN_180 Part 1 / PLAN_181 items 9, 10, 11 and the layer-counting build —
 * include/Images.php: the "Scan stored images" window's grouping (clutter
 * only — "wanted" is no longer a group, its bytes fold into
 * totals.wantedBytes; "dangling" split into "dangling" and "rebuilt" by
 * whether a RepoDigest is present), rule 2's refusal of an ID not on the
 * server's own current list, rule 4c's exclusion of StaXX's own images by
 * label, the `storage` and `broken` shapes (items 9 and 10), and the
 * layer-attributed `sizing`/`layers`/`clutterLayers` shape.
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
 * EVERY FIXTURE IS IDENTIFIED BY ITS OWN IMAGE ID, captured once at the
 * moment it is created, and never by its position or shape in a listing
 * (e.g. "the first untagged row"). On 2026-09-25 a "first untagged row in
 * groups['rebuilt']" lookup picked a REAL leftover image on the production
 * box instead of this suite's own fixture — the box has real untagged
 * rebuild leftovers too, and they sort first (largest) — and the removal
 * case then removed it, deleting four real 2.91 GB images across four
 * runs before this was caught. Every lookup below is by id against
 * $fixtureIds, and nothing is ever handed to removal without a further
 * check, right before it runs, that its id is in that set AND it still
 * carries the staxx.test.images=1 label, read straight from Docker.
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
  //
  // Two queries, unioned, not one — the same PLAN_181 item 11 gap the
  // window itself works around: on Docker 29 a PLAIN `docker images`
  // listing (even filtered by label) hides untagged images entirely. This
  // session's own dangling fixture leaked on the box the first time
  // because this sweep, and the "nothing left" check after it, both used
  // the blind form; -a is avoided (it also lists intermediate build layers)
  // in favour of the same --filter dangling=true the window uses.
  $labelFilter = escapeshellarg('label=staxx.test.images=1');
  $ids = trim(
    staxx_sh($docker.' images --filter '.$labelFilter.' --format '.escapeshellarg('{{.ID}}'), 10)
    ."\n".
    staxx_sh($docker.' images --filter dangling=true --filter '.$labelFilter.' --format '.escapeshellarg('{{.ID}}'), 10)
  );
  $ids = implode("\n", array_unique(array_filter(array_map('trim', explode("\n", $ids)))));
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

// A second LABEL, unique to this one, so its config (and so its image id)
// differs from the tagged fixture below — both import the same empty tar
// with the same staxx.test.images=1 label in the same second, and without
// something else to tell them apart Docker gives them the SAME id, which
// then carries whichever tag was applied last rather than staying dangling.
$danglingCode = 1;
$danglingOut = staxx_sh($docker.' import --change '.escapeshellarg('LABEL '.$LABEL)
  .' --change '.escapeshellarg('LABEL staxx.test.kind=dangling')
  .' - < '.escapeshellarg($emptyTar).' 2>&1', 20, $danglingCode);

$taggedCode = 1;
$taggedOut = staxx_sh(
  $docker.' import --change '.escapeshellarg('LABEL '.$LABEL).' - '.escapeshellarg($TAG1).' < '.escapeshellarg($emptyTar).' 2>&1',
  20, $taggedCode
);
if ($taggedCode === 0) staxx_sh($docker.' tag '.escapeshellarg($TAG1).' '.escapeshellarg($TAG2), 10);

$cryptCode = 1;
$cryptOut = staxx_sh(
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

/* ------------------------------------------------- fixture ids, captured -- */

// `docker import`'s own output is the new image's id, "sha256:<64 hex>",
// as its last non-blank line — read straight from THIS run's own command,
// never guessed at afterwards. The tagged ones are looked up again by
// their own (equally suite-owned) tag, which `docker image inspect` can
// answer for exactly one image.
function images_test_extract_id(string $output): string {
  foreach (array_reverse(explode("\n", trim($output))) as $line) {
    $line = trim($line);
    if (preg_match('/^sha256:[0-9a-f]{64}$/', $line)) return $line;
  }
  return '';
}

$danglingId = images_test_extract_id($danglingOut);
$tag1Id     = trim(staxx_sh($docker.' image inspect --format '.escapeshellarg('{{.Id}}').' '.escapeshellarg($TAG1), 8));
$cryptId    = trim(staxx_sh($docker.' image inspect --format '.escapeshellarg('{{.Id}}').' '.escapeshellarg($TAGC), 8));

if ($danglingId === '' || $tag1Id === '' || $cryptId === '') {
  skip('every case', 'could not capture one or more fixture ids after creating them — see output above');
  echo "\n$fails fault(s), $skips skipped\n";
  exit($fails ? 1 : 0);
}

// Every id this run created — and the ONLY set anything below is ever
// allowed to be matched against, let alone removed.
$fixtureIds = [$danglingId => true, $tag1Id => true, $cryptId => true];

/* ---------------------------------------------------------------- list -- */

$error = 'unset';
$listing = staxx_images_unused($error);

if (!($listing['ok'] ?? false)) {
  skip('every listing case', 'staxx_images_unused() refused right now: '.$error);
  echo "\n$fails fault(s), $skips skipped\n";
  exit($fails ? 1 : 0);
}

// Every lookup below is BY ID, against $fixtureIds — see the file header.
// Never by tag, by label, by "first row of a group", or any other shape:
// that is exactly the mistake that picked a real box image on 2026-09-25.
function images_find_by_id(array $listing, string $id): ?array {
  foreach ($listing['groups'] as $rows) {
    foreach ($rows as $row) {
      if (($row['id'] ?? '') === $id) return $row;
    }
  }
  return null;
}
function images_find_in_group(array $listing, string $group, string $id): ?array {
  foreach ($listing['groups'][$group] ?? [] as $row) {
    if (($row['id'] ?? '') === $id) return $row;
  }
  return null;
}

$cryptRow = images_find_by_id($listing, $cryptId);

// The dangling fixture carries no tag and (docker import never records a
// RepoDigest) lands in the NEW "rebuilt" group rather than "dangling" —
// item 11's naming split. Looked up in THAT group specifically, by id, so
// a row landing in the wrong group would show up as "not found" here
// rather than being silently accepted from wherever it turned up.
$danglingRow = images_find_in_group($listing, 'rebuilt', $danglingId);
ok('an untagged image with no RepoDigests lands in "Left behind by rebuilds"', $danglingRow !== null);

// buildOf must be ABSENT here, and for a plain reason: this fixture's only
// layer is the canonical empty-tar layer (0 bytes) every fixture in this
// suite shares, and the naming rule (include/Images.php, Pass 2b) requires
// at least one layer OVER 0 bytes in a matched run before it will name
// anything — an all-empty run is disqualified outright, not a near miss.
// (What looked like a tie on 2026-09-25 was this test picking up a REAL
// box image through a bad lookup, not anything to do with this fixture —
// see the file header.)
ok('an all-empty-layer leftover is never named, never a guess',
  $danglingRow !== null && !array_key_exists('buildOf', $danglingRow),
  $danglingRow !== null ? ('buildOf: '.var_export($danglingRow['buildOf'] ?? null, true)) : '');

ok('the image labelled staxx.crypt=1 is excluded from every group', $cryptRow === null);

// PLAN_181 item 9 — "wanted" is no longer a group at all: docker import
// never records a RepoDigest (nothing was pulled), so rule 7 treats our
// tagged fixture as "may have been built here" and its row is dropped from
// every returned group, its bytes folded into totals.wantedBytes instead.
ok('an image with no RepoDigests never appears in any returned group '
  .'(rule 7 + item 9 — it is "wanted", which is no longer listed)',
  images_find_by_id($listing, $tag1Id) === null);

ok('the totals carry a wantedBytes figure', array_key_exists('wantedBytes', $listing['totals'] ?? []));

/* -------------------------------------------------------- item 9 storage -- */

ok('storage is null or a {total, free} pair in bytes',
  ($listing['storage'] ?? 'missing') === null
  || (is_int($listing['storage']['total'] ?? null) && is_int($listing['storage']['free'] ?? null)));

/* --------------------------------------------------- layer-counting build -- */

ok('sizing is "layers" or "approximate"', in_array($listing['sizing'] ?? null, ['layers', 'approximate'], true));
ok('layers is a map', is_array($listing['layers'] ?? null));
if (($listing['sizing'] ?? null) === 'layers') {
  // Every clutter row's own clutterLayers chain IDs must be keys of the
  // layers map it was summed from — a row pointing at a layer with no
  // known size would mean its own 'size' was invented rather than summed.
  $layersOk = true;
  foreach (['dangling', 'rebuilt', 'older', 'unused'] as $g) {
    foreach ($listing['groups'][$g] as $row) {
      foreach (($row['clutterLayers'] ?? []) as $chainId) {
        if (!array_key_exists($chainId, $listing['layers'])) { $layersOk = false; }
      }
    }
  }
  ok('every clutter row\'s clutterLayers are all priced in the layers map', $layersOk);
} else {
  skip('layer-attribution shape checks', 'this run came back "approximate" — a layer size file could not be read');
}

/* --------------------------------------------------------- item 10 broken -- */

// This box may or may not currently have a container docker inspect
// cannot read (the real case is PLAN_181's postgresql15) — read only,
// never acted on here; the shape is what this suite can prove everywhere.
ok('broken is a list', is_array($listing['broken'] ?? null));
foreach (($listing['broken'] ?? []) as $b) {
  ok('a broken entry carries a 64-hex container id, a name and a state',
    preg_match('/^[0-9a-f]{64}$/', $b['id'] ?? '') === 1
    && ($b['name'] ?? '') !== '' && ($b['state'] ?? '') !== '');
}

/* ------------------------------------------------------------- rule 2 -- */

$error = 'unset';
$job = staxx_images_remove_job(['sha256:'.str_repeat('0', 64)], $error);
ok('an id that is not on the server\'s own current list is refused', $job === '' && $error !== '' && $error !== 'unset');

/* --------------------------------------------------------- rule 5 / remove -- */

// Called directly rather than through the detached job wrapper — the job
// itself is only setsid + a log file + this same function, already proved
// against a real removal here without needing to poll a background
// process from inside a test.
//
// The tagged fixture's own row is no longer in any returned group (item 9
// — it reads as "wanted"), so it is built by hand from the id already
// captured at creation, never re-derived from the listing: id, tags, size.
$tag1SizeOut = trim(staxx_sh($docker.' image inspect --format '.escapeshellarg('{{.Size}}').' '.escapeshellarg($TAG1), 8));
$rowsToRemove = [
  ['id' => $tag1Id, 'tags' => [$TAG1, $TAG2], 'size' => (int)$tag1SizeOut, 'note' => ''],
];
if ($danglingRow !== null) $rowsToRemove[] = $danglingRow;

// HARD GUARD, added after the 2026-09-25 incident (see file header):
// nothing reaches staxx_images_do_remove() without this passing. Every row
// about to be removed must carry an id THIS RUN itself captured at
// creation, AND that id must still answer to the label a real StaXX image
// would never carry — read straight from Docker right now, not trusted
// from $rowsToRemove or from anything built earlier. A row failing either
// check means removal is refused outright and nothing at all is removed.
function images_test_safe_to_remove(array $rows, array $fixtureIds, string $docker): bool {
  foreach ($rows as $row) {
    $id = $row['id'] ?? '';
    if ($id === '' || !isset($fixtureIds[$id])) return false;
    $label = trim(staxx_sh(
      $docker.' image inspect --format '.escapeshellarg('{{index .Config.Labels "staxx.test.images"}}').' '.escapeshellarg($id),
      8
    ));
    if ($label !== '1') return false;
  }
  return true;
}

if (!images_test_safe_to_remove($rowsToRemove, $fixtureIds, $docker)) {
  printf("%-6s %s\n", 'FAIL',
    'a row about to be removed is not one of this run\'s own labelled fixtures — refusing to remove anything');
  $fails++;
  echo "\n$fails fault(s), $skips skipped\n";
  exit(1);
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
