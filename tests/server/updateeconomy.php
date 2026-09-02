<?php
/* PLAN_90 Stages 1, 2, 3 and 3b, and PLAN_112 Phase A0 — spending registry
 * questions like money, and the pure seam behind asking for headers only.
 * Checked against the real installed Defines.php and Updates.php.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. Almost every
 * case needs no config key at all, but the row-notice section (D) calls
 * staxx_updates_for_row(), which reads a real stack off the stacks folder —
 * derived from STORE_ROOT, which has no env-var override the way
 * STAXX_UPDATE_STATE does, so it has to be pointed at /tmp the way
 * tests/server/record.php and files.php do theirs: sed the real config
 * file, run, then put it back — never /boot, which is vfat and would make
 * section D's fixture directory create for the wrong reason:
 *
 *     pscp tests/server/updateeconomy.php root@<box>:/tmp/
 *     plink … '
 *       CFG=/boot/config/plugins/staxx/staxx.cfg
 *       cp $CFG /tmp/cfg.bak
 *       sed -i "s#^STORE_ROOT=.*#STORE_ROOT=\"/tmp/staxx-updateeconomy-store\"#" $CFG
 *       php /tmp/updateeconomy.php; RC=$?
 *       cp /tmp/cfg.bak $CFG
 *       diff -q /tmp/cfg.bak $CFG && echo "config restored, byte-identical"
 *       exit $RC
 *     '
 *
 * Prints one line per case and exits non-zero on any failure.
 *
 * NEVER touches the real /boot/config/plugins/staxx/updates.json:
 * STAXX_UPDATE_STATE is pointed at a scratch file in /tmp before the
 * include, the same trick tests/server/updates.php, moves.php and watch.php
 * use, and it is checked immediately after the include — a mismatch aborts
 * before any case runs rather than risking the real file.
 *
 * REACHES THE REAL NETWORK NOWHERE IN THIS FILE. Every case here is either
 * pure (staxx_registry_ref(), staxx_registry_accept(), staxx_update_cadence()
 * — no network at all, no state read either) or a state-file-only question
 * (staxx_updates_for_row() reading a fabricated 'images' entry this suite
 * writes itself). This project keeps no stub of a registry's HTTP replies —
 * see tests/server/watch.php's own note on the same point about GitHub —
 * so anything that can only be proven by watching a real 200 turn into a
 * real 304, or a real challenge turn into a real bearer token, is proven
 * instead by tests/server/registry_live.php, the opt-in companion to this
 * file. What that leaves untested here, and why:
 *
 *   - staxx_registry_token()'s challenge parsing is exercised only against
 *     real registries, in the live suite. There is no seam in Defines.php
 *     that lets a server-side test hand it a canned HTTP reply.
 *     staxx_registry_digest() itself is the same, but the header-dump
 *     parsing it relies on (staxx_registry_parse_head()) and the rule for
 *     when the header-only form is abandoned (staxx_registry_free_form_
 *     refused()) were pulled out as pure functions for PLAN_112 Phase A0
 *     precisely so that seam could exist — see section E below.
 *   - Whether a stored entity tag is actually sent as If-None-Match only
 *     when the stored Accept fingerprint matches, and what state changes
 *     when a 304 comes back, both live inside staxx_image_remote() and
 *     staxx_update_check()'s per-image loop — neither is a pure function,
 *     and proving the decisive write touches only 'asked'/'nextDue' needs a
 *     real conditional request answered by a real registry. Covered live.
 *   - The 'fails' counter's actual increment/reset — as opposed to what the
 *     row shows once a count is already sitting in the state file, which
 *     IS covered here — happens inside the same per-image loop, gated on a
 *     real registry's answer, and is not reachable offline either.
 *   - 'force' bypassing the cadence entirely is a decision
 *     staxx_update_check() makes before ever calling staxx_update_cadence();
 *     the pure function has no $force parameter to exercise this in
 *     isolation with.
 *
 * Everything else — reference parsing including the ghcr/lscr no-rewrite
 * rule, the Accept list's exact order, the whole cadence table and its
 * churn/floor/ceiling clamps, and the row notice's wording once 'fails' is
 * already in state — is pure or state-only and is exhaustive here. */

$scratch = '/tmp/staxx-updateeconomy-test.json';
@unlink($scratch);
putenv('STAXX_UPDATE_STATE='.$scratch);

register_shutdown_function(function () use ($scratch) {
  @unlink($scratch);
  echo "scratch state removed\n";
});

require_once '/usr/local/emhttp/plugins/staxx/include/Updates.php';

if (getenv('STAXX_UPDATE_STATE') !== $scratch || staxx_update_state_file() !== $scratch) {
  echo "FAIL   the temporary update-state file is not in place (got ".staxx_update_state_file().")\n";
  exit(1);
}
if ($scratch === '/boot/config/plugins/staxx/updates.json') {
  echo "FAIL   refusing to run — scratch path equals the real config file\n";
  exit(1);
}

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}
function note(string $what): void {
  echo "note   $what\n";
}

/* ======================================================================= *
 * A — reference parsing (staxx_registry_ref), pure. The negative cases
 * matter more than the positive ones: every value here is later spliced
 * into a URL.
 * ======================================================================= */

ok('a bare name becomes Docker Hub\'s library/ namespace',
   staxx_registry_ref('postgres') === ['host' => 'docker.io', 'repo' => 'library/postgres', 'tag' => 'latest', 'digest' => ''],
   json_encode(staxx_registry_ref('postgres')));

ok('a bare name with an explicit tag keeps it',
   staxx_registry_ref('postgres:16') === ['host' => 'docker.io', 'repo' => 'library/postgres', 'tag' => '16', 'digest' => ''],
   json_encode(staxx_registry_ref('postgres:16')));

/* The single most important case in this file — waste #3 in the plan, and
 * also a correctness bug: asking Hub about an image whose compose file
 * names ghcr is only safe while the two happen to agree. */
$ghcr = staxx_registry_ref('ghcr.io/linuxserver/sonarr');
ok('ghcr.io/linuxserver/sonarr keeps its OWN host, not docker.io',
   $ghcr['host'] === 'ghcr.io', json_encode($ghcr));
ok('...and its repo is linuxserver/sonarr, unrewritten',
   $ghcr['repo'] === 'linuxserver/sonarr', json_encode($ghcr));

$lscr = staxx_registry_ref('lscr.io/linuxserver/sonarr:latest');
ok('lscr.io/linuxserver/sonarr keeps its OWN host too',
   $lscr['host'] === 'lscr.io' && $lscr['repo'] === 'linuxserver/sonarr' && $lscr['tag'] === 'latest',
   json_encode($lscr));

/* The contrast that is the whole point: staxx_hub_repo_path() DOES still
 * rewrite these two to Hub, unchanged, because the form editor's README/
 * config-label lookup depends on it. Both behaviours must coexist. */
ok('...but staxx_hub_repo_path() still rewrites ghcr to Hub\'s own path (form editor needs this)',
   staxx_hub_repo_path('ghcr.io/linuxserver/sonarr') === 'linuxserver/sonarr');
ok('...and the same for lscr',
   staxx_hub_repo_path('lscr.io/linuxserver/sonarr') === 'linuxserver/sonarr');

/* A host with a port is not mistaken for a tag. */
ok('a host with a port keeps the port on the host, not as a tag',
   staxx_registry_ref('reg.example.com:5000/team/app')
     === ['host' => 'reg.example.com:5000', 'repo' => 'team/app', 'tag' => 'latest', 'digest' => ''],
   json_encode(staxx_registry_ref('reg.example.com:5000/team/app')));

ok('a host with a port AND an explicit tag splits both correctly',
   staxx_registry_ref('reg.example.com:5000/team/app:1.2')
     === ['host' => 'reg.example.com:5000', 'repo' => 'team/app', 'tag' => '1.2', 'digest' => ''],
   json_encode(staxx_registry_ref('reg.example.com:5000/team/app:1.2')));

ok('localhost:5000/app splits the port and repo correctly',
   staxx_registry_ref('localhost:5000/app')
     === ['host' => 'localhost:5000', 'repo' => 'app', 'tag' => 'latest', 'digest' => ''],
   json_encode(staxx_registry_ref('localhost:5000/app')));

ok('localhost with no port is still recognised as a host, not Hub',
   staxx_registry_ref('localhost/app')['host'] === 'localhost');

/* A digest-pinned reference splits its digest out and keeps its tag. */
$digest = 'sha256:'.str_repeat('a', 64);
$pinned = staxx_registry_ref('ghcr.io/linuxserver/sonarr:1.2.3@'.$digest);
ok('a digest-pinned reference keeps its tag AND splits out the digest',
   $pinned === ['host' => 'ghcr.io', 'repo' => 'linuxserver/sonarr', 'tag' => '1.2.3', 'digest' => $digest],
   json_encode($pinned));

$pinnedNoTag = staxx_registry_ref('postgres@'.$digest);
ok('a digest-pinned reference with no tag defaults the tag to latest',
   $pinnedNoTag === ['host' => 'docker.io', 'repo' => 'library/postgres', 'tag' => 'latest', 'digest' => $digest],
   json_encode($pinnedNoTag));

/* ---- refusals: every one of these must come back with repo === '' ---- */

ok('empty input is refused', staxx_registry_ref('')['repo'] === '');
ok('whitespace-only input is refused', staxx_registry_ref('   ')['repo'] === '');

ok('a tag containing whitespace is refused',
   staxx_registry_ref('myapp:bad tag')['repo'] === '',
   json_encode(staxx_registry_ref('myapp:bad tag')));

ok('a tag containing a control character is refused',
   staxx_registry_ref("myapp:bad\x01tag")['repo'] === '',
   json_encode(staxx_registry_ref("myapp:bad\x01tag")));

/* A slash landing where a tag would be does not reach the tag check
 * directly — the last colon can never sit to the right of the last slash
 * once a slash follows it — but it is still refused, because what is left
 * of the reference no longer parses into a valid host+repo shape: the
 * colon ends up trapped inside the repo half, which the repo shape check
 * (host+repo chars only) rejects. The outward contract — repo === '' —
 * holds either way, which is what callers actually depend on. */
ok('a slash where a tag would be is still refused, via the repo shape check',
   staxx_registry_ref('docker.io/repo:1.0/beta')['repo'] === '',
   json_encode(staxx_registry_ref('docker.io/repo:1.0/beta')));

ok('a repo with characters outside the allowed set is refused (uppercase)',
   staxx_registry_ref('docker.io/Repo/App')['repo'] === '',
   json_encode(staxx_registry_ref('docker.io/Repo/App')));

ok('a repo with characters outside the allowed set is refused (punctuation)',
   staxx_registry_ref('docker.io/repo!/app')['repo'] === '',
   json_encode(staxx_registry_ref('docker.io/repo!/app')));

ok('every refusal hands back every key empty, not a partial answer',
   staxx_registry_ref('') === ['host' => '', 'repo' => '', 'tag' => '', 'digest' => '']);

/* ======================================================================= *
 * B — the Accept list (Stage 1). SABOTAGE PIN: reordering the list, or
 * dropping the OCI index entry, breaks this literal string comparison
 * directly — see the report for the walk-through.
 * ======================================================================= */

$expectAccept = 'application/vnd.oci.image.index.v1+json,'
              . 'application/vnd.docker.distribution.manifest.list.v2+json,'
              . 'application/vnd.docker.distribution.manifest.v2+json';
ok('the Accept list sends the OCI index first, then the two Docker shapes, in that exact order',
   staxx_registry_accept() === $expectAccept, staxx_registry_accept());

$expectAcceptId = substr(sha1($expectAccept), 0, 8);
ok('the Accept fingerprint is a short, stable hash of that exact list',
   staxx_registry_accept_id() === $expectAcceptId, staxx_registry_accept_id());

/* ======================================================================= *
 * C — the cadence table (Stage 3), pure. Every row constructed with a
 * recent, non-zero 'asked' and no churn/fails data unless the case is
 * specifically about churn — so the base classification is isolated from
 * modulation, and vice versa.
 * ======================================================================= */

define('STAXX_DAY',  86400);
define('STAXX_HOUR', 3600);

$recentAsked = ['asked' => time() - 100];

/* ---- version-shaped tags: 7 days ---- */
foreach (['1.2.3', 'v1.2', '2024.10.1', '1.2.3-alpine'] as $vtag) {
  ok("a version-shaped tag ($vtag) is checked weekly",
     staxx_update_cadence('app:'.$vtag, $recentAsked) === 7 * STAXX_DAY,
     staxx_update_cadence('app:'.$vtag, $recentAsked).'s');
}

/* ---- rolling tags: 6 hours (the floor) ---- */
foreach (['latest', 'main', 'master', 'develop', 'nightly', 'edge', 'stable', 'beta'] as $rtag) {
  ok("a rolling tag ($rtag) is checked every 6 hours",
     staxx_update_cadence('app:'.$rtag, $recentAsked) === 6 * STAXX_HOUR,
     staxx_update_cadence('app:'.$rtag, $recentAsked).'s');
}
/* No tag at all is the same as a rolling tag — the implied "latest". */
ok('an image with no tag at all is checked every 6 hours, same as a rolling tag',
   staxx_update_cadence('app', $recentAsked) === 6 * STAXX_HOUR);

/* ---- SABOTAGE PIN (rule 2): an unrecognised tag is the 24-hour middle
 * ground, and must be told apart from a rolling one. If the rolling-tag
 * list were ever accidentally widened to swallow this, the case above
 * would still pass (a rolling tag is still 6h) but THIS one would start
 * failing, because an ordinary word would suddenly be treated as rolling
 * and checked far more often than the table says. ---- */
foreach (['foo', 'build-42', 'release'] as $utag) {
  ok("an unrecognised tag ($utag) is checked daily, not every 6 hours",
     staxx_update_cadence('app:'.$utag, $recentAsked) === 24 * STAXX_HOUR,
     staxx_update_cadence('app:'.$utag, $recentAsked).'s');
}

/* ---- a brand-new image with no 'asked' stamp: floor, whatever the tag ---- */
ok('a brand-new version-tagged image (no asked stamp) still gets the floor',
   staxx_update_cadence('app:1.2.3', []) === 6 * STAXX_HOUR,
   staxx_update_cadence('app:1.2.3', []).'s');
ok('a brand-new unrecognised-tag image (no asked stamp) also gets the floor',
   staxx_update_cadence('app:foo', []) === 6 * STAXX_HOUR);

/* ---- churn: two moves inside 14 days pulls back to the floor, whatever
 * the tag says ---- */
$churnyRecent = ['asked' => time() - 100, 'moves' => [time() - 2 * STAXX_DAY, time() - 10 * STAXX_DAY]];
ok('two digest moves inside the last 14 days pull a version-shaped tag back to the floor',
   staxx_update_cadence('app:1.2.3', $churnyRecent) === 6 * STAXX_HOUR,
   staxx_update_cadence('app:1.2.3', $churnyRecent).'s');

/* ---- churn: nothing newer than 90 days stretches the interval, capped at
 * doubling twice (x4). Asserted as inequalities rather than one exact
 * multiplier, since the plan does not pin the exact tiering — but the
 * direction and the cap are both load-bearing and both checked. ---- */
$baseUnrecognised = 24 * STAXX_HOUR;
$staleOnce = ['asked' => time() - 100, 'moves' => [time() - 100 * STAXX_DAY]];
$onceInterval = staxx_update_cadence('app:foo', $staleOnce);
ok('an image quiet for just over 90 days is checked LESS often than the un-modulated base',
   $onceInterval > $baseUnrecognised, $onceInterval.'s vs base '.$baseUnrecognised.'s');
ok('...but never more than double the base',
   $onceInterval <= $baseUnrecognised * 2, $onceInterval.'s');

$staleLong = ['asked' => time() - 100, 'moves' => [time() - 400 * STAXX_DAY]];
$longInterval = staxx_update_cadence('app:foo', $staleLong);
ok('an image quiet for over a year is stretched further still...',
   $longInterval >= $onceInterval, $longInterval.'s vs '.$onceInterval.'s');
ok('...but the doubling is capped at twice (at most 4x the base)',
   $longInterval <= $baseUnrecognised * 4, $longInterval.'s vs cap '.($baseUnrecognised * 4).'s');

/* ---- the floor and ceiling are hard, whatever churn or tag shape would
 * otherwise produce ---- */
$wouldExceedCeiling = ['asked' => time() - 100, 'moves' => [time() - 1000 * STAXX_DAY]];
ok('churn stretching a version tag past 14 days is clamped to the ceiling',
   staxx_update_cadence('app:1.2.3', $wouldExceedCeiling) <= 14 * STAXX_DAY,
   staxx_update_cadence('app:1.2.3', $wouldExceedCeiling).'s');

ok('nothing produced by this function is ever below the floor',
   staxx_update_cadence('app:latest', $recentAsked) >= 6 * STAXX_HOUR);
ok('nothing produced by this function is ever above the ceiling',
   staxx_update_cadence('app:1.2.3', $wouldExceedCeiling) <= 14 * STAXX_DAY);

/* ---- an errored image retries on the floor... ---- */
$erroredFew = ['asked' => time() - 100, 'error' => 'could not check', 'fails' => 4];
ok('an image that has failed a few times (fails=4) still retries on the floor, whatever its tag',
   staxx_update_cadence('app:1.2.3', $erroredFew) === 6 * STAXX_HOUR,
   staxx_update_cadence('app:1.2.3', $erroredFew).'s');

/* ---- ...but Stage 3b: five in a row eases to daily. The boundary between
 * 4 and 5 is the whole feature — proved as adjacent cases. ---- */
$erroredFlagged = ['asked' => time() - 100, 'error' => 'could not check', 'fails' => 5];
ok('an image that has failed FIVE times in a row eases to a daily retry, not the floor',
   staxx_update_cadence('app:1.2.3', $erroredFlagged) === 24 * STAXX_HOUR,
   staxx_update_cadence('app:1.2.3', $erroredFlagged).'s');

$erroredMore = ['asked' => time() - 100, 'error' => 'could not check', 'fails' => 9];
ok('a flagged image stays at the daily retry beyond five, it does not keep climbing',
   staxx_update_cadence('app:1.2.3', $erroredMore) === 24 * STAXX_HOUR,
   staxx_update_cadence('app:1.2.3', $erroredMore).'s');

note('the two SABOTAGE PINS above are: the exact-string Accept-list assertion '
   . '(rule 1 — a reordered or shortened list fails it directly), and the '
   . 'unrecognised-tag-is-not-rolling assertions in the cadence table (rule 2 — '
   . 'widening the rolling-tag list to catch an ordinary word fails those '
   . 'directly while the genuine rolling-tag cases keep passing, which is '
   . 'exactly the "invents or hides an update" failure shape both rules must '
   . 'never take)');

/* ======================================================================= *
 * D — the row notice (staxx_updates_for_row's 'note'), state-only. A
 * fabricated 'images' entry is written straight into the scratch state
 * file; no network, no registry, no check pass runs.
 *
 * staxx_updates_for_row() looks the image up via the stack's own compose
 * file, so a real stack fixture is needed under the temporary stack root —
 * unlike the rest of this suite, this section DOES need STORE_ROOT pointed
 * off /boot, or a fixture directory can't be created safely. Kept local to
 * this section and cleaned up immediately after.
 * ======================================================================= */

$stackScratchRoot = '/tmp/staxx-updateeconomy-store/stacks';
if (staxx_stack_root() !== $stackScratchRoot) {
  echo "FAIL   the temporary stack root is not in place (got ".staxx_stack_root()."); "
     . "section D needs the real config's STORE_ROOT sed'd to /tmp/staxx-updateeconomy-store "
     . "before this file runs — see the header for the exact command\n";
  exit(1);
}
@exec('rm -rf '.escapeshellarg($stackScratchRoot));
mkdir($stackScratchRoot, 0755, true);
{
  $rel = 'zzeconomy';
  $dir = $stackScratchRoot.'/'.$rel;
  mkdir($dir, 0755, true);
  $image = 'ghcr.io/quadcom/staxx-economy-test:1.0.0';
  file_put_contents($dir.'/compose.yaml', "services:\n  app:\n    image: ".$image."\n");

  function economy_set_image_state(string $image, array $entry): void {
    $state = staxx_update_state();
    $images = (array)$state['images'];
    $images[$image] = $entry;
    staxx_update_state_save(['images' => $images]);
  }

  /* fails=9: the message must be read from state, never hard-coded. */
  economy_set_image_state($image, [
    'local' => 'sha256:'.str_repeat('a', 64), 'remote' => 'sha256:'.str_repeat('a', 64),
    'error' => 'could not check', 'fails' => 9, 'failedSince' => time() - 2 * STAXX_DAY,
  ]);
  $pillNine = staxx_updates_for_row($rel, 'app');
  $noteNine = (string)($pillNine['note'] ?? '');
  ok('fails=9: the row carries a note at all', $noteNine !== '', json_encode($pillNine));
  ok('fails=9: the sentence does NOT say "five" — the count is read from state, not hard-coded',
     stripos($noteNine, 'five') === false, $noteNine);

  /* fails=5 vs fails=4 — the boundary the whole feature rests on. */
  economy_set_image_state($image, [
    'local' => 'sha256:'.str_repeat('a', 64), 'remote' => 'sha256:'.str_repeat('a', 64),
    'error' => 'could not check', 'fails' => 5, 'failedSince' => time() - STAXX_DAY,
  ]);
  $noteFive = (string)(staxx_updates_for_row($rel, 'app')['note'] ?? '');
  ok('fails=5: flagged — a note is present', $noteFive !== '', $noteFive);

  economy_set_image_state($image, [
    'local' => 'sha256:'.str_repeat('a', 64), 'remote' => 'sha256:'.str_repeat('a', 64),
    'error' => 'could not check', 'fails' => 4, 'failedSince' => time() - STAXX_DAY,
  ]);
  $noteFour = (string)(staxx_updates_for_row($rel, 'app')['note'] ?? '');
  ok('fails=4: NOT flagged — no note appears at four', $noteFour === '', $noteFour);

  /* notfound and unsupported: flagged on the first ask, no counting
   * involved. These reuse the existing 'error' strings staxx_update_check()
   * has always set on these two outcomes — this suite assumes Stage 3b
   * keys its wording off the same field rather than a new one; if that
   * assumption is wrong the case below shows an empty note instead of a
   * false pass, which is the safe direction to be wrong in. */
  economy_set_image_state($image, [
    'local' => 'sha256:'.str_repeat('a', 64), 'remote' => '',
    'error' => 'not found in the registry',
  ]);
  $noteNotFound = (string)(staxx_updates_for_row($rel, 'app')['note'] ?? '');
  ok('a not-found image gets its own wording, not the repeated-failure sentence',
     stripos($noteNotFound, 'no repository at this address') !== false
       || stripos($noteNotFound, 'not found') !== false,
     $noteNotFound);

  economy_set_image_state($image, [
    'local' => 'sha256:'.str_repeat('a', 64), 'remote' => '',
    'error' => 'cannot be checked here',
  ]);
  $noteUnsupported = (string)(staxx_updates_for_row($rel, 'app')['note'] ?? '');
  ok('an unsupported image gets its own wording too, not phrased as the image\'s fault',
     stripos($noteUnsupported, 'cannot check this image') !== false
       || stripos($noteUnsupported, 'cannot be checked') !== false,
     $noteUnsupported);

  /* tagmissing is untouched by all of this and still produces its shortlist. */
  economy_set_image_state($image, [
    'local' => 'sha256:'.str_repeat('a', 64), 'remote' => '',
    'error' => 'tag withdrawn', 'tags' => ['stable', '1.0.1'], 'suggest' => 'stable',
  ]);
  $pillTagMissing = staxx_updates_for_row($rel, 'app');
  ok('a withdrawn tag keeps producing its own state, untouched by the failure notice',
     ($pillTagMissing['state'] ?? '') === 'tagmissing' && ($pillTagMissing['suggest'] ?? '') === 'stable',
     json_encode($pillTagMissing));

  @exec('rm -rf '.escapeshellarg($stackScratchRoot));
}

/* ======================================================================= *
 * E — PLAN_112 A0, the free form. Pure functions only — no network, no
 * state. Canned header dumps stand in for what curl would actually print;
 * proving those dumps match a real registry's own reply is the live suite's
 * job, not this one's.
 * ======================================================================= */

$singleBlock = "HTTP/2 200\r\n"
  . "docker-content-digest: sha256:".str_repeat('a', 64)."\r\n"
  . "ETag: \"abc123\"\r\n";
$parsedSingle = staxx_registry_parse_head($singleBlock);
ok('a single-block header dump reads its status',
   $parsedSingle['status'] === 200, json_encode($parsedSingle));
ok('...and lower-cases its header names',
   isset($parsedSingle['head']['docker-content-digest']) && isset($parsedSingle['head']['etag']),
   json_encode($parsedSingle));

/* A redirect followed by the real answer — -L means the dump can hold more
 * than one response, and this must NOT be mistaken for a refusal of the
 * header-only form; only the final block counts. */
$twoBlock = "HTTP/1.1 307 Temporary Redirect\r\n"
  . "location: https://elsewhere.example/v2/x/manifests/y\r\n"
  . "\r\n"
  . "HTTP/2 200\r\n"
  . "docker-content-digest: sha256:".str_repeat('b', 64)."\r\n";
$parsedTwo = staxx_registry_parse_head($twoBlock);
ok('a redirect-then-200 dump reads the FINAL block\'s status, not the redirect\'s',
   $parsedTwo['status'] === 200, json_encode($parsedTwo));
ok('...and the final block\'s digest header, not the redirect\'s location',
   isset($parsedTwo['head']['docker-content-digest']) && !isset($parsedTwo['head']['location']),
   json_encode($parsedTwo));

$block401 = "HTTP/1.1 401 Unauthorized\r\n"
  . "www-authenticate: Bearer realm=\"https://auth.example/token\"\r\n";
ok('a 401 block reads as status 401',
   staxx_registry_parse_head($block401)['status'] === 401);

/* The refusal rule is narrow on purpose: only 405 means the host itself
 * rejects the header-only form. Every other status this section checks is
 * answered by the routes already in staxx_registry_digest() and must never
 * trip the same memoisation, or a host that merely omits a digest header
 * would wrongly be downgraded to paying for a full GET on every future
 * check. */
foreach ([401, 307, 200, 404, 429] as $notRefusal) {
  ok("free_form_refused($notRefusal) is false — that status has its own route, not a refusal of the short form",
     staxx_registry_free_form_refused($notRefusal) === false);
}
ok('free_form_refused(405) is true — a plain Method Not Allowed IS a refusal',
   staxx_registry_free_form_refused(405) === true);

/* staxx_registry_headfree() — per-host memo, defaults true, one host's
 * write does not touch another's. */
$hostA = 'example.invalid';
$hostB = 'other.invalid';
ok('an unasked host defaults to "header-only works"',
   staxx_registry_headfree($hostA) === true);
staxx_registry_headfree($hostA, false);
ok('...and setting it false is read back false',
   staxx_registry_headfree($hostA) === false);
ok('a different host is unaffected by the first host\'s memo',
   staxx_registry_headfree($hostB) === true);

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
