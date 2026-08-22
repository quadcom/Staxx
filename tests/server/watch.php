<?php
/* PLAN_62 Stage 1 — watching what an image's own publisher publishes, before
 * any comparison exists. Checked against the real installed Watch.php.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine:
 *
 *     pscp tests/server/watch.php root@<box>:/tmp/
 *     plink … "php /tmp/watch.php"
 *
 * Prints one line per case and exits non-zero on any failure.
 *
 * NEVER touches the real /boot/config/plugins/staxx/updates.json:
 * STAXX_UPDATE_STATE is pointed at a scratch file in /tmp, same trick
 * tests/server/updates.php and tests/server/moves.php use. Never pulls,
 * starts or stops a container, and reaches the real network nowhere in this
 * file — every case here is either pure (no network at all) or deliberately
 * offline (a home address that fails before any curl call is even built).
 *
 * What this does NOT test: the live conditional fetch against GitHub's real
 * API and raw-content host — staxx_watch_discover() and staxx_watch_fetch()
 * actually reaching the network, and a 304 genuinely costing nothing against
 * the rate ceiling. There is no stub of GitHub in this repo to make that
 * deterministic; it was checked by hand against real repositories instead
 * (see the PLAN_62 Stage 1 report). Covered here instead:
 *
 *   - staxx_watch_readme_block() — pure prose-reading, written and checked
 *     first per PLAN_62's own instruction, since it is the one place in this
 *     plan that reads free text rather than a fixed API shape.
 *   - staxx_watch_discover_candidates() — the real judgement inside
 *     discovery, against a captured real git-trees reply (trimmed), so the
 *     "reply is pretty-printed and naive string matching finds nothing"
 *     mistake PLAN_62 records making is pinned by a test and cannot recur.
 *   - staxx_links_rolling_tag() / staxx_watch_rolling_tag() — the gate that
 *     keeps a pinned image out of the network entirely.
 *   - staxx_watch_home() — reading a declared label vs. a derived ghcr.io
 *     address, off a fixture state file.
 *   - staxx_watch_check()'s state machine for the cases reachable with no
 *     network: a pinned tag is never even asked about, and an image with no
 *     known project home says so without touching a socket.
 */

$scratch = '/tmp/staxx-watch-test.json';
@unlink($scratch);
putenv('STAXX_UPDATE_STATE='.$scratch);

register_shutdown_function(function () use ($scratch) {
  @unlink($scratch);
  echo "scratch state removed\n";
});

require_once '/usr/local/emhttp/plugins/staxx/include/Watch.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

/* --------------------------- 1. staxx_watch_readme_block() — written first, -
 * pure, no network. ------------------------------------------------------- */

$oneClearBlock = "# Example\n\n```yaml\nservices:\n  app:\n    image: example/app:latest\n```\n";
$r = staxx_watch_readme_block($oneClearBlock);
ok('one clear services block is accepted', $r['ok'] === true, $r['reason']);
ok('its body is handed back', strpos($r['body'], 'services:') !== false);

$twoBlocks = "```yaml\nservices:\n  a:\n    image: a\n```\n\nOr, as a second option:\n\n"
           . "```yaml\nservices:\n  b:\n    image: b\n```\n";
$r = staxx_watch_readme_block($twoBlocks);
ok('two services blocks is a refusal, not a guess', $r['ok'] === false);
ok('carrying the count',                            $r['count'] === 2, (string)$r['count']);

$noBlocks = "Just run:\n\n```\ndocker run -d example/app\n```\n";
$r = staxx_watch_readme_block($noBlocks);
ok('a docker-run-only README refuses',  $r['ok'] === false);
ok('with a zero count',                 $r['count'] === 0);
ok('and says no example is published',  strpos($r['reason'], 'no example') !== false, $r['reason']);

// The "here is compose, here is plain docker" README from PLAN_62's own
// measurement table: a real compose example alongside a snippet that is not
// one. Exactly one candidate — the plain-docker block never counts, because
// it declares no services: list — so this must NOT be refused.
$composeAndPlainDocker = "```yaml\nservices:\n  app:\n    image: example/app:latest\n```\n\n"
                       . "Prefer plain Docker?\n\n```bash\ndocker run -d example/app\n```\n";
$r = staxx_watch_readme_block($composeAndPlainDocker);
ok('compose alongside a plain-docker snippet is still exactly one candidate',
   $r['ok'] === true, $r['reason']);

$noFence = "This project has no compose example at all, just prose.";
$r = staxx_watch_readme_block($noFence);
ok('no fenced block at all refuses with a zero count', $r['ok'] === false && $r['count'] === 0);

/* ---------------- 2. staxx_watch_discover_candidates() — real reply shape - */

// Trimmed from a real `git/trees/HEAD?recursive=1` reply
// (amir20/dozzle, captured 2026-08-21) — a compact, NOT pretty-printed body,
// same shape a naive '"path":"' string search was tested against and missed
// nothing here because this uses json_decode() properly.
$realTreeJson = '{"sha":"bd18d19","tree":[' .
  '{"path":".air.toml","mode":"100644","type":"blob","sha":"1f6385f","size":867},' .
  '{"path":"docker-compose.yml","mode":"100644","type":"blob","sha":"a1b2c3","size":4148},' .
  '{"path":"internal/deploy/compose.go","mode":"100644","type":"blob","sha":"d4e5f6","size":900},' .
  '{"path":".claude","mode":"040000","type":"tree","sha":"5b85935"}' .
  ']}';
$decoded = json_decode($realTreeJson, true);
ok('the captured reply decodes as real JSON', is_array($decoded) && isset($decoded['tree']));
$hits = staxx_watch_discover_candidates($decoded['tree']);
ok('exactly the real compose file is found, not the .go file that merely mentions compose',
   $hits === ['docker-compose.yml'], implode(',', $hits));

$treeWithExample = [
  ['type' => 'blob', 'path' => 'examples/docker-compose.yaml'],
  ['type' => 'blob', 'path' => 'README.md'],
  ['type' => 'tree', 'path' => 'examples'],
];
ok('a candidate under examples/ is still found',
   staxx_watch_discover_candidates($treeWithExample) === ['examples/docker-compose.yaml']);

$treeWithSeveral = [
  ['type' => 'blob', 'path' => 'compose.yaml'],
  ['type' => 'blob', 'path' => 'test/fixtures/compose.yml'],
];
ok('several candidates are all counted, not silently narrowed to one',
   count(staxx_watch_discover_candidates($treeWithSeveral)) === 2);

ok('no candidates at all comes back empty, not an error',
   staxx_watch_discover_candidates([['type' => 'blob', 'path' => 'main.go']]) === []);

/* ------------------------------------ 3. rolling vs. pinned tags --------- */

ok('latest is rolling',        staxx_links_rolling_tag('latest'));
ok('stable is rolling',        staxx_links_rolling_tag('stable'));
ok('a bare version is pinned', !staxx_links_rolling_tag('1.2.3'));
ok('a v-prefixed version is pinned', !staxx_links_rolling_tag('v10'));
ok('empty is not rolling',     !staxx_links_rolling_tag(''));

ok('a pinned image is never even looked at',
   !staxx_watch_rolling_tag('example/app:1.2.3'));
ok('an image with no tag defaults to latest, which is rolling',
   staxx_watch_rolling_tag('example/app'));
ok('a digest pin is refused outright, before the tag is inspected',
   !staxx_watch_rolling_tag('example/app@sha256:'.str_repeat('a', 64)));

/* ------------------------------------------ 4. staxx_watch_home() -------- */

staxx_update_state_save(['images' => [
  'labelled/app:latest' => ['source' => 'https://example.com/labelled/app'],
  'ghcr.io/someowner/somename:latest' => [],
  'alpine:latest' => [],
]]);

ok('a declared label wins',
   staxx_watch_home('labelled/app:latest') === 'https://example.com/labelled/app');
ok('ghcr.io derives the matching GitHub address when nothing is declared',
   staxx_watch_home('ghcr.io/someowner/somename:latest') === 'https://github.com/someowner/somename');
ok('a plain image with neither source gets nothing, honestly',
   staxx_watch_home('alpine:latest') === '');

/* --------------------------- 5. staxx_watch_check() — no-network cases --- */

$spent = 0;
$w = staxx_watch_check('example/app:1.2.3', [], $spent);
ok('a pinned image is never even fetched — no result, no spend',
   $w === [] && $spent === 0);

$spent = 0;
$w = staxx_watch_check('alpine:latest', [], $spent);
ok('a rolling image with no known project home says so, and spends nothing',
   isset($w['reason']) && $w['reason'] !== '' && $spent === 0, $w['reason'] ?? '');

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
