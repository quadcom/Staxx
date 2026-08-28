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
 *   - PLAN_62 Stage 5 — staxx_watch_claim_ok(), the gate that decides whether
 *     a third party's claimed project address is trustworthy enough to
 *     fetch from. Pure, no network.
 *   - staxx_watch_template_claims() — the light template scanner, against a
 *     throwaway fixture directory under /tmp, never the real templates.
 *   - staxx_watch_claimed_home() — both the catalogue and the template
 *     source, and the order between them, against a fixture CA cache
 *     (backed up and restored the way tests/server/project-links.php already
 *     does) and the same throwaway template directory. Both functions take
 *     an optional directory argument for exactly this reason; every real
 *     caller in the plugin omits it and gets the real template folder.
 *   - staxx_watch_home()'s provenance word for a declared label and a
 *     derived ghcr.io address, and staxx_watch_check()'s 'home_from' field
 *     surviving the one no-network failure branch (an unGitHub home). The
 *     'unchanged' merge is code-identical (see staxx_watch_check()) and only
 *     reachable via a genuine 304 from GitHub, so it is checked by hand
 *     against the real API rather than here, the same boundary this file
 *     already draws for staxx_watch_discover()/staxx_watch_fetch() reaching
 *     the network.
 */

$scratch = '/tmp/staxx-watch-test.json';
@unlink($scratch);
putenv('STAXX_UPDATE_STATE='.$scratch);

register_shutdown_function(function () use ($scratch) {
  @unlink($scratch);
  echo "scratch state removed\n";
});

/* ---------- STORE_ROOT, pointed at a throwaway tree before ANY include ----
 * Section 6 below reads a stack off disk, and there is no env override for
 * the store, so the real cfg file is rewritten and put back on exit — the
 * same trick tests/server/moves.php uses. It has to happen up here, before
 * the first require: staxx_cfg() memoises on its first call, and any plugin
 * file included above this point would make that call. Nothing in sections
 * 0-5 reads a stack, so redirecting the root this early costs them nothing. */
$cfgFile   = '/boot/config/plugins/staxx/staxx.cfg';
$testStore = '/tmp/staxx-watch-test-store';
$cfgBackup = @file_get_contents($cfgFile);

register_shutdown_function(function () use ($cfgFile, $testStore, $cfgBackup) {
  if ($cfgBackup === false) { @unlink($cfgFile); } else { @file_put_contents($cfgFile, $cfgBackup); }
  @exec('rm -rf '.escapeshellarg($testStore));
  echo "STORE_ROOT restored\n";
});

@exec('rm -rf '.escapeshellarg($testStore));
mkdir($testStore, 0755, true);
$cfgLines = $cfgBackup !== false ? preg_split('/\r?\n/', $cfgBackup) : [];
$cfgLines = array_values(array_filter($cfgLines, fn($l) => strpos(trim((string)$l), 'STORE_ROOT=') !== 0));
$cfgLines[] = 'STORE_ROOT="'.$testStore.'"';
file_put_contents($cfgFile, implode("\n", $cfgLines)."\n");

$testRoot = $testStore.'/stacks'; // staxx_stack_root(), derived

require_once '/usr/local/emhttp/plugins/staxx/include/Watch.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

/* ---------- 0. the catalogue fixture, built before anything reads one -----
 * staxx_ca_index_data() caches statically, so the first catalogue read of the
 * process wins for the rest of it. The cases that use this fixture are in 4d
 * below; the fixture has to be here. */

$caIndex  = STAXX_CA_INDEX;
$caApps   = STAXX_CA_APPS;
$idxBak   = '/tmp/staxx-watch-test-index.bak';
$appsBak  = '/tmp/staxx-watch-test-apps.bak';
$hadIndex = is_file($caIndex);
$hadApps  = is_file($caApps);
if ($hadIndex) copy($caIndex, $idxBak);
if ($hadApps)  copy($caApps, $appsBak);

register_shutdown_function(function () use ($caIndex, $caApps, $idxBak, $appsBak, $hadIndex, $hadApps) {
  if ($hadIndex) { copy($idxBak, $caIndex); @unlink($idxBak); } else { @unlink($caIndex); }
  if ($hadApps)  { copy($appsBak, $caApps); @unlink($appsBak); } else { @unlink($caApps); }
  echo "CA cache restored\n";
});

// Same fixture shape as tests/server/project-links.php: index.json's own 'r'
// joined against apps.jsonl's byte offset, exactly what staxx_ca_app() reads.
$caRepo = 'excalidraw/excalidraw';
$caLine = json_encode(['Name' => 'Excalidraw', 'r' => $caRepo, 'Project' => 'https://github.com/excalidraw/excalidraw']);
if (!is_dir(STAXX_CA_DIR)) @mkdir(STAXX_CA_DIR, 0755, true);
file_put_contents($caApps, $caLine."\n");
file_put_contents($caIndex, json_encode([
  'v' => 4, 'built' => time(), 'count' => 1, 'categories' => [],
  'apps' => [['r' => $caRepo, 'n' => 'Excalidraw', 'o' => 0, 'len' => strlen($caLine)]],
]));

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
   staxx_watch_home('labelled/app:latest') === ['https://example.com/labelled/app', 'label']);
ok('ghcr.io derives the matching GitHub address when nothing is declared',
   staxx_watch_home('ghcr.io/someowner/somename:latest') === ['https://github.com/someowner/somename', 'derived']);
ok('a plain image with neither source gets nothing, honestly',
   staxx_watch_home('alpine:latest') === ['', '']);

/* --------- 4b. staxx_watch_claim_ok() — PLAN_62 Stage 5's gate, pure ------ */

// The plan's own measured accept/reject pairs, by name.
ok('Memos: repo name resemblance accepts (neosmemo/memos -> usememos/memos)',
   staxx_watch_claim_ok('neosmemo/memos:latest', 'https://github.com/usememos/memos')
   === 'https://github.com/usememos/memos');
ok('Excalidraw: exact owner and name accepts',
   staxx_watch_claim_ok('excalidraw/excalidraw:latest', 'https://github.com/excalidraw/excalidraw')
   === 'https://github.com/excalidraw/excalidraw');
ok('CloudBeaver: exact owner and name accepts',
   staxx_watch_claim_ok('dbeaver/cloudbeaver:latest', 'https://github.com/dbeaver/cloudbeaver')
   === 'https://github.com/dbeaver/cloudbeaver');
ok('Actual Budget: a template author\'s own repository is rejected, not the app\'s',
   staxx_watch_claim_ok('actualbudget/actual-server:latest', 'https://github.com/Kippenhof/docker-templates') === '');
ok('a product website is rejected outright — not GitHub at all',
   staxx_watch_claim_ok('postgres:latest', 'https://postgresql.org/download') === '');

// Guards.
ok('an empty namespace never matches',
   staxx_watch_claim_ok('', 'https://github.com/owner/repo') === '');
ok('a namespace of library never matches, even when the name alone would not',
   staxx_watch_claim_ok('postgres:latest', 'https://github.com/library/somethingelse') === '');
ok('a matching token shorter than 3 characters does not count',
   staxx_watch_claim_ok('ab/svc:latest', 'https://github.com/ab/other') === '');
ok('a sub-path normalises to the repository root',
   staxx_watch_claim_ok('excalidraw/excalidraw:latest', 'https://github.com/excalidraw/excalidraw/issues')
   === 'https://github.com/excalidraw/excalidraw');

/* -------- 4c. staxx_watch_template_claims() — the local template scanner - */

$tplDir = '/tmp/staxx-watch-templates-test';
@mkdir($tplDir, 0755, true);
foreach (glob($tplDir.'/*') as $stale) @unlink($stale);
register_shutdown_function(function () use ($tplDir) {
  foreach ((array)@glob($tplDir.'/*') as $stale) @unlink($stale);
  @rmdir($tplDir);
});

file_put_contents($tplDir.'/memos.xml',
  '<?xml version="1.0"?><Container><Repository>neosmemo/memos</Repository>'
  .'<Project>https://github.com/usememos/memos</Project></Container>');
file_put_contents($tplDir.'/actualbudget.xml',
  '<?xml version="1.0"?><Container><Repository>actualbudget/actual-server</Repository>'
  .'<Project>https://github.com/Kippenhof/docker-templates</Project></Container>');
// Same content as a real template, but the extension a stale overwrite
// leaves behind — must never be offered as if it were the real template.
file_put_contents($tplDir.'/memos.xml.bak',
  '<?xml version="1.0"?><Container><Repository>bakonly/bakonly</Repository>'
  .'<Project>https://github.com/bakonly/bakonly</Project></Container>');

$claims = staxx_watch_template_claims($tplDir);
ok('the scanner reads a template\'s Repository and Project fields',
   ($claims['neosmemo/memos'] ?? '') === 'https://github.com/usememos/memos');
ok('a rejectable claim (Actual Budget) is still recorded by the scanner — the gate, not the scan, refuses it',
   ($claims['actualbudget/actual-server'] ?? '') === 'https://github.com/Kippenhof/docker-templates');
ok('a .bak file is ignored by the scanner', !isset($claims['bakonly/bakonly']));

/* ---------- 4d. staxx_watch_claimed_home() — the catalogue source --------
 * The catalogue fixture itself is built at the top of this file, before any
 * call that could read the real one: staxx_ca_index_data() caches statically,
 * so the FIRST catalogue read of the process wins and a fixture written here
 * would never be seen. That is a real trap, not a test artefact — anything
 * that stubs the catalogue has to do it before the first read. */

$claimed = staxx_watch_claimed_home('excalidraw/excalidraw:latest', $tplDir);
ok('the catalogue is tried first and its claim accepted',
   $claimed === ['https://github.com/excalidraw/excalidraw', 'catalog'], json_encode($claimed));

// Memos is only in the template fixture, not the catalogue fixture above —
// proves the fall-through to the template source, and its own provenance.
$claimed = staxx_watch_claimed_home('neosmemo/memos:latest', $tplDir);
ok('a repository named only by a template falls through to it, tagged template',
   $claimed === ['https://github.com/usememos/memos', 'template'], json_encode($claimed));

// The template fixture also names Actual Budget's rejectable claim — proves
// the gate, not just the catalogue-then-template order, still governs here.
$claimed = staxx_watch_claimed_home('actualbudget/actual-server:latest', $tplDir);
ok('a template claim the gate rejects yields nothing, not the stranger\'s repository',
   $claimed === ['', ''], json_encode($claimed));

ok('an image nobody claims comes back empty from both sources',
   staxx_watch_claimed_home('nobody-publishes-this/at-all:latest', $tplDir) === ['', '']);

/* --------------------------- 5. staxx_watch_check() — no-network cases --- */

$spent = 0;
$w = staxx_watch_check('example/app:1.2.3', [], $spent);
ok('a pinned image is never even fetched — no result, no spend',
   $w === [] && $spent === 0);

$spent = 0;
$w = staxx_watch_check('alpine:latest', [], $spent);
ok('a rolling image with no known project home says so, and spends nothing',
   isset($w['reason']) && $w['reason'] !== '' && $spent === 0, $w['reason'] ?? '');

/* ---- 5b. 'home_from' surviving a failed discovery — the no-network case - *
 * A non-GitHub home fails inside staxx_watch_discover() before any curl is
 * built (see its own 'not a GitHub repository' branch), so this is the one
 * failure this suite can exercise deterministically; the 'unchanged' merge
 * is code-identical to the fresh-discovery branch below and is checked by
 * hand against the real API instead (see this file's header). */

staxx_update_state_save(['images' => [
  'nongithub/app:latest' => ['source' => 'https://example.com/nongithub/app'],
]]);

$spent = 0;
$w = staxx_watch_check('nongithub/app:latest', [], $spent);
ok('a non-GitHub home is refused without any network call, and home_from is stored',
   $w['home'] === 'https://example.com/nongithub/app' && $w['home_from'] === 'label',
   json_encode($w));

$prior = [
  'home' => 'https://example.com/nongithub/app', 'home_from' => 'label',
  'path' => 'docker-compose.yml', 'reason' => 'a stale reason from a previous pass',
];
$spent = 0;
$w2 = staxx_watch_check('nongithub/app:latest', $prior, $spent);
ok('a repeated failure returns the prior entry wholesale — home_from is not lost',
   $w2 === $prior && $w2['home_from'] === 'label', json_encode($w2));

/* ------- 6. PLAN_85 — staxx_watch_for_stack()'s note names its service --- *
 * A note built off the state file alone (section 5 above) named no service
 * at all; this proves the naming clause, and that two services sharing one
 * image still produce exactly one note, naming both. Needs StacksTable.php
 * (staxx_watch_for_stack() itself, plus the compose reader it calls), and a
 * real stack directory to read, under the throwaway stack root set up at the
 * top of this file. */

require_once '/usr/local/emhttp/plugins/staxx/include/StacksTable.php';

ok('the throwaway stack root is now in force', staxx_stack_root() === $testRoot, staxx_stack_root());

// One service alone with an unknown-home image, and two services sharing a
// second such image — the case the dedupe has to still collapse to one note.
$namingStack = 'namingtest';
$namingDir   = $testRoot.'/'.$namingStack;
mkdir($namingDir, 0755, true);
file_put_contents($namingDir.'/compose.yaml',
  "services:\n"
  ."  fldb:\n"
  ."    image: nobody-publishes-this/alone:latest\n"
  ."  adminer:\n"
  ."    image: nobody-publishes-this/shared:latest\n"
  ."  cron:\n"
  ."    image: nobody-publishes-this/shared:latest\n"
);
staxx_scan_stacks_reset();

// Both images checked and found to have no known project home — the one
// no-network reason staxx_watch_check() can reach deterministically (see
// section 5 above).
staxx_update_state_save(['images' => [
  'nobody-publishes-this/alone:latest'  => ['watch' => ['reason' => 'no known project home for this image']],
  'nobody-publishes-this/shared:latest' => ['watch' => ['reason' => 'no known project home for this image']],
]]);

$watch = staxx_watch_for_stack($namingStack);
ok('one note per image, not per service', count($watch['notes']) === 2, implode(' | ', $watch['notes']));
ok('a single-service image names that one service',
   in_array('No known project home for this image, used by "fldb".', $watch['notes'], true),
   implode(' | ', $watch['notes']));
ok('two services sharing an image produce ONE note naming both',
   in_array('No known project home for this image, used by "adminer" and "cron".', $watch['notes'], true),
   implode(' | ', $watch['notes']));

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
