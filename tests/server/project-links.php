<?php
/* PLAN_55 Part B — staxx_project_links() and its helpers, checked against the
 * real installed Links.php.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine:
 *
 *     pscp tests/server/project-links.php root@<box>:/tmp/
 *     plink … "php /tmp/project-links.php"
 *
 * Prints one line per case and exits non-zero on any failure.
 *
 * There is already a tests/server/links.php, for an unrelated feature (a
 * symlink sitting in a stack folder) — this file is named differently
 * deliberately, so as not to overwrite it.
 *
 * NEVER touches the real /boot/config/plugins/staxx/updates.json:
 * STAXX_UPDATE_STATE is pointed at a scratch file in /tmp before the include,
 * same trick tests/server/updates.php uses.
 *
 * The Community Applications cache has no such override — it always reads
 * /tmp/staxx/ca/index.json and apps.jsonl (see CA.php; that is already a
 * reducible download cache, never the real settings on flash). Whatever is
 * there is backed up, replaced with a small fixture for the run, and put
 * back afterwards, including after a fatal error.
 */

$scratch = '/tmp/staxx-links-test.json';
@unlink($scratch);
putenv('STAXX_UPDATE_STATE='.$scratch);

require_once '/usr/local/emhttp/plugins/staxx/include/Links.php';

$caIndex  = STAXX_CA_INDEX;
$caApps   = STAXX_CA_APPS;
$idxBak   = '/tmp/staxx-links-test-index.bak';
$appsBak  = '/tmp/staxx-links-test-apps.bak';
$hadIndex = is_file($caIndex);
$hadApps  = is_file($caApps);
if ($hadIndex) copy($caIndex, $idxBak);
if ($hadApps)  copy($caApps, $appsBak);

register_shutdown_function(function () use ($scratch, $caIndex, $caApps, $idxBak, $appsBak, $hadIndex, $hadApps) {
  @unlink($scratch);
  if ($hadIndex) { copy($idxBak, $caIndex); @unlink($idxBak); } else { @unlink($caIndex); }
  if ($hadApps)  { copy($appsBak, $caApps); @unlink($appsBak); } else { @unlink($caApps); }
  echo "scratch state and CA cache restored\n";
});

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

ok('scratch state path is in force', getenv('STAXX_UPDATE_STATE') === $scratch);

/* -------------------------------------------------- the CA fixture -------- */

// One deliberate catalogue entry, read the same way a real one is: index.json
// carries the small fields search needs (including the repository, 'r'),
// apps.jsonl carries this one app's full record at the offset index.json
// names, exactly what staxx_ca_app() expects.
$caRepo = 'staxx-fixture/repo';
$caLine = json_encode([
  'Name'    => 'Fixture App',
  'r'       => $caRepo,
  'Project' => 'https://catalog.example/project',
  'Support' => 'https://catalog.example/support',
]);
if (!is_dir(STAXX_CA_DIR)) @mkdir(STAXX_CA_DIR, 0755, true);
file_put_contents($caApps, $caLine."\n");
file_put_contents($caIndex, json_encode([
  'v' => 4, 'built' => time(), 'count' => 1, 'categories' => [],
  'apps' => [
    ['r' => $caRepo, 'n' => 'Fixture App', 'o' => 0, 'len' => strlen($caLine)],
  ],
]));

/* -------------------------------------------------------- 1. the helpers -- */

ok('repo path: strips a host and a tag',
   staxx_links_repo_path('ghcr.io/owner/name:latest') === 'owner/name');
ok('repo path: no host to strip, tag still goes',
   staxx_links_repo_path('linuxserver/jellyfin:latest') === 'linuxserver/jellyfin');
ok('repo path: a bare name keeps its shape',
   staxx_links_repo_path('alpine:3.19') === 'alpine');

ok('derive ghcr: owner/name out of a ghcr.io address',
   staxx_links_derive_ghcr('ghcr.io/someowner/somename:latest') === 'https://github.com/someowner/somename');
ok('derive ghcr: refuses a non-ghcr host', staxx_links_derive_ghcr('quay.io/someowner/somename:latest') === '');
ok('derive ghcr: refuses too few segments', staxx_links_derive_ghcr('ghcr.io/onlyowner:latest') === '');

ok('base image: alpine resolves to its Docker Hub page',
   staxx_links_base_image('alpine:3.19') === 'https://hub.docker.com/_/alpine');
ok('base image: a namespaced name is never treated as a base image',
   staxx_links_base_image('someuser/alpine:latest') === '');
ok('base image: a local-looking single-segment name invents nothing',
   staxx_links_base_image('paperless-redis') === '', 'never fabricated');

$map = staxx_links_ca_map();
ok('ca map: the fixture repository resolves to an ordinal', isset($map[$caRepo]));
ok('ca map: the ordinal reads back the fixture record',
   is_array(staxx_ca_app($map[$caRepo] ?? -1))
   && (staxx_ca_app($map[$caRepo])['Project'] ?? '') === 'https://catalog.example/project');

/* ------------------------------------------------ 2. genuinely nothing ---- */

// A local-only name — no host, not in the base-image list, no label, no
// catalogue match — must come back empty on every field, never a fabricated
// guess. This is the paperless-redis case named in PLAN_55.
$nothing = staxx_project_links('paperless-redis', [], []);
ok('nothing: project is empty for a local-only name', $nothing['project'] === '', json_encode($nothing));
ok('nothing: support is empty too', $nothing['support'] === '', json_encode($nothing));
ok('nothing: from names no source', $nothing['from'] === '', json_encode($nothing));

/* --------------------------------------------- 3. a stored answer wins ---- */

// Every other source would also answer here — a ghcr-shaped address that
// matches the CA fixture's repository and carries a label in the state file
// — and the stored x-unraid values must win over all of it.
$image = 'ghcr.io/'.$caRepo;
staxx_update_state_save(['images' => [$image => ['source' => 'https://label.example/project']]]);

$stored = staxx_project_links($image,
  [], // stack-level x-unraid
  ['project' => 'https://stored.example/project', 'support' => 'https://stored.example/support']);
ok('stored: project is the service-level value', $stored['project'] === 'https://stored.example/project', json_encode($stored));
ok('stored: support is the service-level value', $stored['support'] === 'https://stored.example/support', json_encode($stored));
ok('stored: from says "stored"', $stored['from'] === 'stored', $stored['from']);

/* ------------------------------------ 4. service-level beats stack-level -- */

$mixed = staxx_project_links($image,
  ['project' => 'https://stack.example/project', 'support' => 'https://stack.example/support'],
  ['project' => 'https://service.example/project']); // no service-level support
ok('service beats stack: project is the service-level value',
   $mixed['project'] === 'https://service.example/project', json_encode($mixed));
ok('service beats stack: support falls back to the stack-level value, since the service set none',
   $mixed['support'] === 'https://stack.example/support', json_encode($mixed));
ok('service beats stack: from is still "stored"', $mixed['from'] === 'stored', $mixed['from']);

/* ---------------------------------------- 5. a label beats the catalogue -- */

// No stored answer this time. The label (source 2) still outranks the
// catalogue match (source 3) even though both could answer — but the
// catalogue is still consulted for support, which the label cannot supply.
$labelled = staxx_project_links($image, [], []);
ok('label beats catalog: project is the label value',
   $labelled['project'] === 'https://label.example/project', json_encode($labelled));
ok('label beats catalog: support falls through to the catalogue, since a label carries none',
   $labelled['support'] === 'https://catalog.example/support', json_encode($labelled));
ok('label beats catalog: from is "label"', $labelled['from'] === 'label', $labelled['from']);

// A label that is not https:// must never count — it is never invented.
staxx_update_state_save(['images' => [$image => ['source' => 'not-a-url']]]);
$badLabel = staxx_project_links($image, [], []);
ok('label: a non-https value is discarded, falling through to the catalogue',
   $badLabel['project'] === 'https://catalog.example/project', json_encode($badLabel));
ok('label: from is "catalog" once the bad label is skipped', $badLabel['from'] === 'catalog', $badLabel['from']);

/* -------------------------------------------------- 6. the catalogue only - */

staxx_update_state_save(['images' => []]);
$catalog = staxx_project_links($caRepo.':latest', [], []);
ok('catalog only: project comes from the fixture record',
   $catalog['project'] === 'https://catalog.example/project', json_encode($catalog));
ok('catalog only: support comes from the fixture record too',
   $catalog['support'] === 'https://catalog.example/support', json_encode($catalog));
ok('catalog only: from is "catalog"', $catalog['from'] === 'catalog', $catalog['from']);

/* --------------------------------------------- 7. derived from a ghcr address */

// Deliberately a repository the CA fixture does not know, and no label
// recorded for it, so only the derived source is left standing.
$ghcrImage = 'ghcr.io/unmatched-owner/unmatched-name:latest';
$derived = staxx_project_links($ghcrImage, [], []);
ok('derived: project is the guessed GitHub address',
   $derived['project'] === 'https://github.com/unmatched-owner/unmatched-name', json_encode($derived));
ok('derived: support stays empty — nothing offers one',
   $derived['support'] === '', json_encode($derived));
ok('derived: from says "derived", so the wording can hedge', $derived['from'] === 'derived', $derived['from']);

/* --------------------------------------------- 8. an official base image -- */

$base = staxx_project_links('debian:12', [], []);
ok('base image: project is its own Docker Hub page',
   $base['project'] === 'https://hub.docker.com/_/debian', json_encode($base));
ok('base image: support stays empty', $base['support'] === '', json_encode($base));
ok('base image: from says "registry"', $base['from'] === 'registry', $base['from']);

printf("\n%s — %d failure%s\n", $fails ? 'FAILED' : 'passed', $fails, $fails === 1 ? '' : 's');
exit($fails ? 1 : 0);
