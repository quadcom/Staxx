<?php
/* PLAN_82 Part 2 — the release-notes pipeline against a REAL server.
 *
 * The companion file, tests/server/releasenotes.php, is deliberately offline:
 * every project link it uses either fails the GitHub check outright or is only
 * ever pushed through the pure URL builder, so no request is ever made. This
 * file is the exact opposite, and says so plainly: it is a LIVE check that
 * talks to GitHub's real API, over the network, from this box.
 *
 * That is why it is OPT-IN. It refuses to run unless STAXX_LIVE_NOTES=1 is set
 * in the environment, so nobody runs it by accident as part of a sweep of the
 * server suites.
 *
 * The project it asks about is https://github.com/quadcom/feedlog-token, which
 * has one published release tagged v1.0.0. Two things are proven:
 *
 *   1. The whole notes pipeline works end to end against a real project —
 *      including the with/without-'v' alternation in the URL builder, which
 *      the offline suite can only check as a string.
 *   2. A rolling tag ('main', 'latest') finds NOTHING today. That is not a bug
 *      being tested around: it is the known gap recorded in
 *      PLAN_82a-what-changed-in-a-rolling-build.md. Pinning it here means the
 *      case flips to green the day that plan is built, which is what a test
 *      should do.
 *
 *     pscp tests/server/releasenotes_live.php root@<box>:/tmp/
 *     plink … 'STAXX_LIVE_NOTES=1 php /tmp/releasenotes_live.php'
 *
 * Needs NO config keys at all — no STACK_ROOT, no ARCHIVE_ROOT — and restores
 * nothing on the way out, because it changes nothing. It creates no stacks,
 * writes no file, and never calls staxx_update_record_before_pull() or any
 * other function that records state. Every question it asks is read-only, and
 * that is what makes it safe on a production box.
 *
 * THE RATE CEILING. GitHub allows 60 requests an hour to an unauthenticated
 * caller, counted per source address, and that ceiling is SHARED with the
 * plugin's own notes lookups on this machine. Each staxx_release_notes_fetch()
 * call tries up to two candidate addresses, so this file spends up to eight
 * requests per run. The count is printed at the end rather than assumed.
 *
 * Because it depends on an external repository staying as it is, a failure
 * here may mean that repository changed — a release renamed, retagged or
 * deleted — rather than the code being wrong. Check the repository before
 * chasing a bug in the plugin.
 *
 * Prints one line per case and exits non-zero on any failure. */

/* The gate comes BEFORE any require, so a machine that never opted in does not
 * even load the plugin. */
if (getenv('STAXX_LIVE_NOTES') !== '1') {
  echo "SKIP   this is a LIVE check that reaches out to GitHub over the network.\n";
  echo "       Run it with:  STAXX_LIVE_NOTES=1 php /tmp/releasenotes_live.php\n";
  exit(0);
}

require_once '/usr/local/emhttp/plugins/staxx/include/Updates.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}
function note(string $what): void {
  echo "note   $what\n";
}
function skip(string $what, string $reason): void {
  printf("%-6s %s  (%s)\n", 'SKIP', $what, $reason);
}

$project = 'https://github.com/quadcom/feedlog-token';

/* Every request this file spends, counted as it goes. A fetch tries each
 * candidate address in turn and stops at the first one that answers with a
 * body, so this is the worst case — which is the number that matters when the
 * ceiling is what is being budgeted for. */
$requests = 0;
function live_fetch(string $project, string $version): array {
  global $requests;
  $requests += count(staxx_release_notes_urls($project, $version));
  return staxx_release_notes_fetch($project, $version);
}

/* ======================================================================= *
 * A — the release that exists. Tag v1.0.0, asked for both ways.
 * ======================================================================= */

$vPrefixed = live_fetch($project, 'v1.0.0');
ok('the published release v1.0.0 comes back with notes',
   is_string($vPrefixed['notes'] ?? null) && $vPrefixed['notes'] !== '',
   'notes length '.strlen((string)($vPrefixed['notes'] ?? '')));

/* Asserted as a fragment, not the whole address: the release page's exact form
 * is GitHub's to change. The notes' own text is not asserted on at all — it is
 * somebody else's prose and may be edited at any time. */
ok('...and its url points at the release page for that tag',
   is_string($vPrefixed['url'] ?? null)
     && strpos((string)$vPrefixed['url'], 'quadcom/feedlog-token/releases/tag/') !== false,
   (string)($vPrefixed['url'] ?? ''));

$bare = live_fetch($project, '1.0.0');
ok('the same version written WITHOUT the leading v also comes back with notes',
   is_string($bare['notes'] ?? null) && $bare['notes'] !== '',
   'notes length '.strlen((string)($bare['notes'] ?? '')));

/* This is the case the offline suite cannot reach: it proves the alternation
 * actually finds the release on a real server, not merely that the builder
 * emits two strings. */
ok('...and lands on the same release page, so the v-alternation works against '
 . 'a real server',
   ($bare['url'] ?? null) === ($vPrefixed['url'] ?? null),
   (string)($bare['url'] ?? ''));

/* ======================================================================= *
 * B — the gap this file pins: a rolling tag finds nothing.
 * ======================================================================= */

$empty = ['notes' => '', 'url' => '', 'cut' => false];

/* Asserted first, and deliberately: the project link IS a GitHub link, so
 * candidates ARE built and a real request IS made. Without this, the empty
 * result below could not be told apart from the builder simply declining to
 * ask — and those are completely different failures. */
ok('a rolling tag still produces URL candidates, so a real request is made',
   staxx_release_notes_urls($project, 'main') !== []);

$mainNotes = live_fetch($project, 'main');
ok('nothing is found for the rolling tag "main"',
   $mainNotes === $empty,
   json_encode($mainNotes));

ok('the rolling tag "latest" also produces URL candidates',
   staxx_release_notes_urls($project, 'latest') !== []);

$latestNotes = live_fetch($project, 'latest');
ok('nothing is found for the rolling tag "latest"',
   $latestNotes === $empty,
   json_encode($latestNotes));

note('a rolling tag names a branch, not a release, so there is no release to '
   . 'find by that name — the miss above is real and comes from GitHub, not '
   . 'from the builder refusing to ask');
note('the commit-id route in PLAN_82a-what-changed-in-a-rolling-build.md is '
   . 'what would answer this; when it lands, these two cases should be '
   . 'revisited and are expected to flip to finding something');

/* ======================================================================= *
 * C — the real image on this box, read-only.
 *
 * staxx_sh() is reachable here: Updates.php requires Stacks.php, which pulls
 * in Defines.php where the helper lives. It wraps the command in a timeout and
 * closes stdin, so nothing can hang. `docker image inspect` is a plain read of
 * what is already on disk — it pulls nothing, starts nothing and changes
 * nothing.
 * ======================================================================= */

$image = 'ghcr.io/quadcom/feedlog-token:main';
$code  = 1;
$label = trim(staxx_sh(
  'docker image inspect '.escapeshellarg($image)
  .' --format '.escapeshellarg('{{index .Config.Labels "org.opencontainers.image.version"}}'),
  15, $code));

if ($code !== 0) {
  // A missing fixture is not a fault in the code — failing on it would only
  // add noise to every box that has never run this image.
  skip('the version label baked into '.$image,
       'that image is not present on this box, so there is nothing to inspect');
} else {
  ok('the image on this box carries the branch name as its version label',
     $label === 'main',
     'label '.var_export($label, true));
  note('this is the live confirmation of section B: the version the image '
     . 'declares about itself is a branch name, which is why no release can '
     . 'ever be found under it');
}

/* ---------------------------------------------------------------------- */

note('network cost of this run: at most '.$requests.' requests to GitHub, '
   . 'against an unauthenticated ceiling of 60 an hour shared with the '
   . "plugin's own notes lookups");

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
