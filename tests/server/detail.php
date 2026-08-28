<?php
/* PLAN_84 Phase 2 — staxx_detail_discover() and the helpers behind it,
 * checked against the real installed Detail.php.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. Needs STORE_ROOT
 * pointed at /tmp/zzdetail-store and IMAGE_LOOKUP forced to "false", both set
 * in the real config file BEFORE php starts (staxx_cfg() memoises on first
 * read, so changing either from inside this script is already too late —
 * same reasoning tests/server/record.php gives for STORE_ROOT).
 * Forcing IMAGE_LOOKUP off is deliberate, not incidental: with it off, every
 * network step in Detail.php (the registry chain, the Docker Hub request)
 * is skipped outright, so this suite never touches the network at all and
 * is fully deterministic run to run. The registry/Hub cascades themselves
 * already have their own opt-in live suites (registry_live.php,
 * releasenotes_live.php); this file is not trying to re-prove those.
 *
 *     pscp tests/server/detail.php root@<box>:/tmp/
 *     plink … '
 *       CFG=/boot/config/plugins/staxx/staxx.cfg
 *       cp $CFG /tmp/cfg.bak
 *       grep -q "^STORE_ROOT=" $CFG \
 *         && sed -i "s#^STORE_ROOT=.*#STORE_ROOT=\"/tmp/zzdetail-store\"#" $CFG \
 *         || echo "STORE_ROOT=\"/tmp/zzdetail-store\"" >> $CFG
 *       sed -i "s#^IMAGE_LOOKUP=.*#IMAGE_LOOKUP=\"false\"#" $CFG
 *       grep -q "^IMAGE_LOOKUP=" $CFG || echo "IMAGE_LOOKUP=\"false\"" >> $CFG
 *       php /tmp/detail.php; RC=$?
 *       cp /tmp/cfg.bak $CFG
 *       exit $RC
 *     '
 *
 * Prints one line per case and exits non-zero on any failure.
 *
 * PULLS NOTHING, STARTS NOTHING, CHANGES NOTHING ON THE REAL SERVER. Every
 * case either calls a pure function with made-up data, or hands
 * staxx_detail_discover() a stack this file created for itself under the
 * temporary stack root. Every image reference used is fictional
 * (zzstaxxtest/…) so staxx_local_image_config()'s real `docker image
 * inspect` call — always safe and always local, never a pull — comes back
 * empty every time, deterministically, regardless of what is actually
 * pulled on this box. The Community Applications cache (no config override;
 * always /tmp/staxx/ca/…) and the icon collection's index (on the flash
 * device, no override either) are both backed up, replaced with a small
 * fixture, and restored, including after a fatal error — the same trick
 * tests/server/project-links.php already uses for the CA cache.
 *
 * WHAT THIS FILE CANNOT REACH, AND WHY (see the report handed back with
 * this suite for the full explanation):
 *
 *   - staxx_detail_template_match() now takes a $dir argument (its static
 *     cache keyed on the folder as well as the image), so the template
 *     cascade itself IS exercised directly below, against fixture folders
 *     this file creates and removes under /tmp — repository match, the
 *     absolute-local-path icon refusal, a .bak file being ignored, a
 *     non-matching repository, and the folder-keyed cache. What is still
 *     out of reach is only staxx_detail_project_support()'s OWN call to
 *     staxx_watch_claimed_home($image) — that call site still has no
 *     $dir parameter, so the template-claimed *project/support* fallback
 *     cannot be driven end to end through staxx_detail_discover(). The
 *     exact two calls that fallback makes (staxx_watch_claimed_home() then
 *     staxx_detail_answer()) are composed directly instead, proving the
 *     same conflict/suppression behaviour without the real call site.
 *   - An image's own on-disk labels (org.opencontainers.image.*), read by
 *     staxx_local_image_config() via a real `docker image inspect`. There is
 *     no fixture-image mechanism for this, and pulling something for this
 *     suite to inspect is exactly what it must never do. The 'stated' tier
 *     IS proven positively below through the update-check state file
 *     instead (the same 'label' source staxx_project_links() already uses),
 *     which is a genuine stated answer sourced from a different cache.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */

require_once '/usr/local/emhttp/plugins/staxx/include/Defines.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Links.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Watch.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Icons.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Import.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Detail.php';

if (staxx_stack_root() !== '/tmp/zzdetail-store/stacks') {
  echo "FAIL   the temporary stack root is not in place (got ".staxx_stack_root().")\n";
  exit(1);
}
if ((staxx_cfg()['IMAGE_LOOKUP'] ?? '') !== 'false') {
  echo "FAIL   IMAGE_LOOKUP is not forced off — this run would be free to touch the network\n";
  exit(1);
}

$root = staxx_stack_root();
@exec('rm -rf '.escapeshellarg($root));
mkdir($root, 0755, true);

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

/* --------------------------------------------- CA catalogue fixture -- */

$caRepo1 = 'zzstaxxtest/caapp';
$caRepo2 = 'zzstaxxtest/badsupportapp';
$caRepo3 = 'zzstaxxtest/badreadmeapp';
$longOverview = 'This fixture overview repeats itself on purpose so it comfortably '
  .'clears the three-hundred character cap the collapse routine applies to a '
  .'found description, proving the truncation actually fires rather than only '
  .'existing as an untested code path that looks right on the page. '
  .'Repeat: this fixture overview repeats itself on purpose to clear the cap. '
  .'Repeat again for good measure so there is no doubt at all about the length.';

$caLines = [
  json_encode([
    'Name' => 'CA App', 'r' => $caRepo1,
    'Project' => 'https://catalog.example/caproject',
    'Support' => 'https://catalog.example/casupport',
    'Icon' => 'capp-icon-fixture',
    'Overview' => $longOverview,
    'CategoryList' => ['MediaApp-Video'],
    'Repo' => 'zzstaxxtest-maintainer',
    'Readme' => 'https://catalog.example/careadme',
  ]),
  json_encode([
    'Name' => 'Bad Support App', 'r' => $caRepo2,
    'Project' => 'https://catalog.example/goodproj',
    'Support' => 'http://insecure.example/support',
  ]),
  json_encode([
    'Name' => 'Bad Readme App', 'r' => $caRepo3,
    'Readme' => 'http://insecure.example/readme',
  ]),
];

$caApps = STAXX_CA_APPS;
$caIndex = STAXX_CA_INDEX;
$caIdxBak = '/tmp/zzdetail-ca-index.bak';
$caAppsBak = '/tmp/zzdetail-ca-apps.bak';
$hadCaIndex = is_file($caIndex);
$hadCaApps = is_file($caApps);
if ($hadCaIndex) copy($caIndex, $caIdxBak);
if ($hadCaApps) copy($caApps, $caAppsBak);

if (!is_dir(STAXX_CA_DIR)) @mkdir(STAXX_CA_DIR, 0755, true);
$offset = 0;
$caApps_entries = [];
$caBody = '';
foreach ($caLines as $i => $line) {
  $caApps_entries[] = ['r' => [$caRepo1, $caRepo2, $caRepo3][$i], 'n' => 'x', 'o' => $offset, 'len' => strlen($line)];
  $caBody .= $line."\n";
  $offset += strlen($line) + 1;
}
file_put_contents($caApps, $caBody);
file_put_contents($caIndex, json_encode([
  'v' => 4, 'built' => time(), 'count' => count($caApps_entries), 'categories' => [],
  'apps' => $caApps_entries,
]));

/* --------------------------------------------- icon index fixture -- */

$iconIndexPath = STAXX_ICON_INDEX;
$iconIndexBak = '/tmp/zzdetail-icon-index.bak';
$hadIconIndex = is_file($iconIndexPath);
if ($hadIconIndex) copy($iconIndexPath, $iconIndexBak);

if (!is_dir(STAXX_ICON_STORE)) @mkdir(STAXX_ICON_STORE, 0755, true);
file_put_contents($iconIndexPath, json_encode([
  'refs' => [
    'uniquewidgetzz' => 's',
    'widgettest-alpha' => 's',
    'widgettest-beta' => 's',
  ],
  'alias' => [],
]));

/* --------------------------------------------- update-state fixture -- */

$scratchState = '/tmp/zzdetail-update-state.json';
@unlink($scratchState);
putenv('STAXX_UPDATE_STATE='.$scratchState);

$labelBadImage = 'zzstaxxtest/labelbadapp:latest';
$labelGoodImage = 'zzstaxxtest/labelgoodapp:latest';
staxx_update_state_save(['images' => [
  $labelBadImage => ['source' => 'http://insecure.example/proj'],
  $labelGoodImage => ['source' => 'https://good.example/projectlabel'],
]]);

register_shutdown_function(function () use (
  $root, $caIndex, $caApps, $caIdxBak, $caAppsBak, $hadCaIndex, $hadCaApps,
  $iconIndexPath, $iconIndexBak, $hadIconIndex, $scratchState
) {
  @exec('rm -rf '.escapeshellarg($root));
  if ($hadCaIndex) { copy($caIdxBak, $caIndex); @unlink($caIdxBak); } else { @unlink($caIndex); }
  if ($hadCaApps) { copy($caAppsBak, $caApps); @unlink($caAppsBak); } else { @unlink($caApps); }
  if ($hadIconIndex) { copy($iconIndexBak, $iconIndexPath); @unlink($iconIndexBak); } else { @unlink($iconIndexPath); }
  @unlink($scratchState);
  echo "scratch stack root, CA cache and icon index restored\n";
});

/* --------------------------------------------------------- fixture stacks -- */

function zd_write_stack(string $rel, string $yaml): void {
  global $root;
  $dir = $root.'/'.$rel;
  mkdir($dir, 0755, true);
  file_put_contents($dir.'/compose.yaml', $yaml);
}

/** One service, image only — the common case below. */
function zd_simple(string $svc, string $image, string $stackX = ''): string {
  $yaml = "services:\n  $svc:\n    image: $image\n";
  if ($stackX !== '') $yaml = "x-unraid:\n$stackX\nservices:\n  $svc:\n    image: $image\n";
  return $yaml;
}

// One place every answer this suite ever produces is collected, so the two
// invariants that apply to EVERY case (never 'stated' for a claim, always
// schema-legal) are checked once at the end rather than repeated per case.
$answers = [];
function zd_collect(array &$answers, string $field, ?array $answer): void {
  if ($answer === null) return;
  $answers[] = ['field' => $field, 'from' => $answer['from'], 'tier' => $answer['tier'], 'value' => $answer['value']];
}
function zd_collect_result(array &$answers, array $result): void {
  foreach (($result['stack'] ?? []) as $field => $row) zd_collect($answers, $field, $row['answer']);
  foreach (($result['services'] ?? []) as $svc => $fields) {
    foreach ($fields as $field => $row) zd_collect($answers, $field, $row['answer']);
  }
}

/* ============================================================ 1. nothing invented === */

zd_write_stack('zzdetail-nothing', zd_simple('app', 'zzdetailnothingxyz'));
$r = staxx_detail_discover('zzdetail-nothing');
ok('nothing: discover succeeds', $r['ok'] === true, json_encode($r));
$allNull = true;
foreach ($r['stack'] as $field => $row) if ($row['answer'] !== null) $allNull = false;
// NOT `?? 'missing'`: ?? treats a null as absent, so a correctly-null answer
// would read as missing and this assertion could never pass. The distinction
// that matters here is "the field is present and its answer is null".
if (!array_key_exists('webui', $r['services']['app'] ?? [])
    || $r['services']['app']['webui']['answer'] !== null) $allNull = false;
ok('nothing: an unknown, uncatalogued, untemplated image yields no answers on any field',
   $allNull, json_encode($r['stack']));
zd_collect_result($answers, $r);

/* ============================================================ 2. claimed tier, full shape === */

zd_write_stack('zzdetail-claimed', zd_simple('app', $caRepo1.':latest'));
$r = staxx_detail_discover('zzdetail-claimed');
ok('claimed: discover succeeds', $r['ok'] === true);
$s = $r['stack'];
ok('claimed: project comes from the catalogue', ($s['project']['answer']['value'] ?? '') === 'https://catalog.example/caproject'
   && ($s['project']['answer']['from'] ?? '') === 'catalog' && ($s['project']['answer']['tier'] ?? '') === 'claimed');
ok('claimed: support comes from the catalogue too', ($s['support']['answer']['value'] ?? '') === 'https://catalog.example/casupport'
   && ($s['support']['answer']['tier'] ?? '') === 'claimed');
ok('claimed: icon is the bare catalogue name, tier claimed',
   ($s['icon']['answer']['value'] ?? '') === 'capp-icon-fixture' && ($s['icon']['answer']['tier'] ?? '') === 'claimed');
ok('claimed: overview is collapsed to one line and capped',
   strpos((string)($s['overview']['answer']['value'] ?? ''), "\n") === false
   && strlen((string)($s['overview']['answer']['value'] ?? '')) <= 300
   && ($s['overview']['answer']['tier'] ?? '') === 'claimed',
   (string)($s['overview']['answer']['value'] ?? ''));
ok('claimed: category is normalised to Head:Rest', ($s['category']['answer']['value'] ?? '') === 'MediaApp:Video');
ok('claimed: author comes from the catalogue Repo fallback', ($s['author']['answer']['value'] ?? '') === 'zzstaxxtest-maintainer');
ok('claimed: readme comes from the catalogue', ($s['readme']['answer']['value'] ?? '') === 'https://catalog.example/careadme');
ok('claimed: webui stays null — this fixture publishes no port and no local template matches',
   array_key_exists('webui', $r['services']['app'] ?? [])
   && $r['services']['app']['webui']['answer'] === null,
   json_encode($r['services'] ?? []));
zd_collect_result($answers, $r);

/* ============================================================ 3. suppression =========== */

zd_write_stack('zzdetail-suppress', zd_simple('app', $caRepo1.':latest',
  "  icon: capp-icon-fixture\n  category: Network\n"));
$r = staxx_detail_discover('zzdetail-suppress');
ok('suppress: discover succeeds', $r['ok'] === true);
ok('suppress: an icon identical to what is already there is never offered as new',
   $r['stack']['icon']['answer'] === null, json_encode($r['stack']['icon']));
ok('suppress: a DIFFERENT current category still surfaces the found value',
   ($r['stack']['category']['answer']['value'] ?? '') === 'MediaApp:Video', json_encode($r['stack']['category']));
ok('suppress: current is reported back exactly as stored', $r['stack']['category']['current'] === 'Network');
zd_collect_result($answers, $r);

/* ============================================================ 4. non-https discarded === */

zd_write_stack('zzdetail-support-bad', zd_simple('app', $caRepo2.':latest'));
$r = staxx_detail_discover('zzdetail-support-bad');
ok('non-https/support: the good project still comes through',
   ($r['stack']['project']['answer']['value'] ?? '') === 'https://catalog.example/goodproj');
ok('non-https/support: a non-https support value is discarded, not surfaced',
   $r['stack']['support']['answer'] === null, json_encode($r['stack']['support']));
zd_collect_result($answers, $r);

zd_write_stack('zzdetail-readme-bad', zd_simple('app', $caRepo3.':latest'));
$r = staxx_detail_discover('zzdetail-readme-bad');
ok('non-https/readme: a non-https readme value is discarded, not surfaced',
   $r['stack']['readme']['answer'] === null, json_encode($r['stack']['readme']));
ok('non-https/readme: nothing else was accidentally triggered for this bare fixture',
   $r['stack']['project']['answer'] === null);
zd_collect_result($answers, $r);

zd_write_stack('zzdetail-labelbad', zd_simple('app', $labelBadImage));
$r = staxx_detail_discover('zzdetail-labelbad');
ok('non-https/label: a non-https source label is discarded, project stays unanswered',
   $r['stack']['project']['answer'] === null, json_encode($r['stack']['project']));
zd_collect_result($answers, $r);

/* ============================================================ 5. positive tiers ========= */

zd_write_stack('zzdetail-labelgood', zd_simple('app', $labelGoodImage));
$r = staxx_detail_discover('zzdetail-labelgood');
ok('stated tier: a genuine source label answers project as stated',
   ($r['stack']['project']['answer']['value'] ?? '') === 'https://good.example/projectlabel'
   && ($r['stack']['project']['answer']['from'] ?? '') === 'label'
   && ($r['stack']['project']['answer']['tier'] ?? '') === 'stated');
zd_collect_result($answers, $r);

zd_write_stack('zzdetail-derived', zd_simple('app', 'ghcr.io/zzstaxxtest/derivedapp:latest'));
$r = staxx_detail_discover('zzdetail-derived');
ok('guess tier: a ghcr.io address derives a project, tier guess',
   ($r['stack']['project']['answer']['value'] ?? '') === 'https://github.com/zzstaxxtest/derivedapp'
   && ($r['stack']['project']['answer']['from'] ?? '') === 'derived'
   && ($r['stack']['project']['answer']['tier'] ?? '') === 'guess');
ok('guess tier: the registry namespace answers author, described as who PUBLISHES not who wrote it',
   ($r['stack']['author']['answer']['value'] ?? '') === 'zzstaxxtest'
   && ($r['stack']['author']['answer']['from'] ?? '') === 'registry'
   && ($r['stack']['author']['answer']['tier'] ?? '') === 'guess');
zd_collect_result($answers, $r);

/* ============================================================ 6. a stored value can now surface a genuine conflict; suppression still holds === */
// staxx_detail_project_support() asks staxx_project_links() with the stored
// blocks deliberately emptied — a different question ("what would this
// server say if the file said nothing?") from the one that function
// ordinarily answers. The fix for the finding this suite raised earlier:
// a stored project no longer masks a differing catalogue/label value, but
// an identical one still suppresses cleanly (staxx_detail_answer()'s own
// equality check, unchanged).

zd_write_stack('zzdetail-storedblocks', zd_simple('app', $caRepo1.':latest', "  project: https://old.example/storedproject\n"));
$r = staxx_detail_discover('zzdetail-storedblocks');
ok('stored+differing (catalog): the catalogue value now surfaces, honestly labelled — never "stored"',
   ($r['stack']['project']['answer']['value'] ?? '') === 'https://catalog.example/caproject'
   && ($r['stack']['project']['answer']['from'] ?? '') === 'catalog'
   && ($r['stack']['project']['answer']['tier'] ?? '') === 'claimed',
   json_encode($r['stack']['project']));
ok('stored+differing: current is still reported back exactly as the file has it',
   $r['stack']['project']['current'] === 'https://old.example/storedproject');
ok('stored: support is unaffected by any of this — a new support value still surfaces',
   ($r['stack']['support']['answer']['value'] ?? '') === 'https://catalog.example/casupport',
   json_encode($r['stack']['support']));
zd_collect_result($answers, $r);

zd_write_stack('zzdetail-storedsame', zd_simple('app', $caRepo1.':latest', "  project: https://catalog.example/caproject\n"));
$r = staxx_detail_discover('zzdetail-storedsame');
ok('stored+identical (catalog): a value identical to the one already stored surfaces nothing — suppression still holds',
   $r['stack']['project']['answer'] === null, json_encode($r['stack']['project']));
zd_collect_result($answers, $r);

// The same two shapes again, this time against the label fallback — the
// project_links() 'label' hit sourced from the update-check state cache
// (the only 'label' source this suite can drive without pulling an image;
// see the file header). $labelGoodImage has no catalogue entry at all, so
// this hit is the only thing that can answer once the stored blocks are
// emptied.
zd_write_stack('zzdetail-storedlabeldiff', zd_simple('app', $labelGoodImage, "  project: https://old.example/differentstored\n"));
$r = staxx_detail_discover('zzdetail-storedlabeldiff');
ok('stored+differing (label): the label value now surfaces, tier stated, never "stored"',
   ($r['stack']['project']['answer']['value'] ?? '') === 'https://good.example/projectlabel'
   && ($r['stack']['project']['answer']['from'] ?? '') === 'label'
   && ($r['stack']['project']['answer']['tier'] ?? '') === 'stated',
   json_encode($r['stack']['project']));
zd_collect_result($answers, $r);

zd_write_stack('zzdetail-storedlabelsame', zd_simple('app', $labelGoodImage, "  project: https://good.example/projectlabel\n"));
$r = staxx_detail_discover('zzdetail-storedlabelsame');
ok('stored+identical (label): a value identical to the label surfaces nothing',
   $r['stack']['project']['answer'] === null, json_encode($r['stack']['project']));
zd_collect_result($answers, $r);

// And against the TEMPLATE fallback (staxx_watch_claimed_home() then
// staxx_detail_answer(), exactly as staxx_detail_project_support()'s own
// second fallback composes them) — composed directly here rather than
// through staxx_detail_discover(), because that function's own call site
// still hardcodes the real template folder (see the file header).
$tplFallbackDir = '/tmp/zzdetail-templates-case6';
@exec('rm -rf '.escapeshellarg($tplFallbackDir));
mkdir($tplFallbackDir, 0755, true);
file_put_contents($tplFallbackDir.'/my-fallback.xml',
  '<Container><Repository>zzstaxxtest/tplfallback</Repository><Project>https://github.com/zzstaxxtest/tplfallback</Project></Container>');

[$tplHome, $tplHomeFrom] = staxx_watch_claimed_home('zzstaxxtest/tplfallback', $tplFallbackDir);
ok('template fallback: the claimed template home is found and passes the ownership guard',
   $tplHome === 'https://github.com/zzstaxxtest/tplfallback' && $tplHomeFrom === 'template');

$tplWhy = 'A local Unraid template names this as the project home, and it resembles this image closely enough to trust.';
$tplDiffering = staxx_detail_answer($tplHome, $tplHomeFrom, 'claimed', $tplWhy, 'https://old.example/differentstored');
ok('stored+differing (template): the template-claimed home surfaces, honestly labelled',
   ($tplDiffering['value'] ?? '') === $tplHome && ($tplDiffering['from'] ?? '') === 'template'
   && ($tplDiffering['tier'] ?? '') === 'claimed', json_encode($tplDiffering));
$tplSame = staxx_detail_answer($tplHome, $tplHomeFrom, 'claimed', $tplWhy, $tplHome);
ok('stored+identical (template): a value identical to the template-claimed home surfaces nothing',
   $tplSame === null, json_encode($tplSame));
zd_collect($answers, 'project', $tplDiffering);
@exec('rm -rf '.escapeshellarg($tplFallbackDir));

/* ============================================================ 7. icon ambiguity ========= */

zd_write_stack('zzdetail-iconambiguous', zd_simple('app', 'zzstaxxtest/widgettest:latest'));
$r = staxx_detail_discover('zzdetail-iconambiguous');
ok('icon: a name matching two collection entries yields no icon at all',
   $r['stack']['icon']['answer'] === null, json_encode($r['stack']['icon']));
zd_collect_result($answers, $r);

zd_write_stack('zzdetail-iconmatch', zd_simple('app', 'zzstaxxtest/uniquewidgetzz:latest'));
$r = staxx_detail_discover('zzdetail-iconmatch');
ok('icon: a name matching exactly one collection entry is offered, marked auto-matched',
   ($r['stack']['icon']['answer']['value'] ?? '') === 'uniquewidgetzz'
   && ($r['stack']['icon']['answer']['from'] ?? '') === 'matcher'
   && ($r['stack']['icon']['answer']['tier'] ?? '') === 'guess'
   && ($r['stack']['icon']['answer']['auto_comment'] ?? false) === true);
zd_collect_result($answers, $r);

/* ============================================================ 8. lead service rule ====== */

zd_write_stack('zzdetail-leadmatch',
  "services:\n"
  ."  zzdetail-leadmatch:\n    image: ".$caRepo1.":latest\n"
  ."  sidecar:\n    image: ".$caRepo2.":latest\n");
$r = staxx_detail_discover('zzdetail-leadmatch');
ok('lead: the service named after the stack is the lead', $r['lead_service'] === 'zzdetail-leadmatch');
ok('lead: no note when a lead was found', $r['lead_note'] === '');
zd_collect_result($answers, $r);

zd_write_stack('zzdetail-leadonly', zd_simple('onlysvc', $caRepo1.':latest'));
$r = staxx_detail_discover('zzdetail-leadonly');
ok('lead: the only service is the lead when none matches the stack name', $r['lead_service'] === 'onlysvc');
zd_collect_result($answers, $r);

zd_write_stack('zzdetail-leadnone',
  "services:\n"
  ."  alpha:\n    image: zzstaxxtest/alphaimg\n"
  ."  beta:\n    image: zzstaxxtest/betaimg\n");
$r = staxx_detail_discover('zzdetail-leadnone');
ok('lead: none found on a multi-service stack with no name match', $r['lead_service'] === '');
ok('lead: a note explains why, rather than a guess', $r['lead_note'] !== '');
ok('lead: stack-level fields are simply absent, not answered blank', $r['stack'] === []);
ok('lead: both services still get their own webui answer slot', isset($r['services']['alpha']['webui']) && isset($r['services']['beta']['webui']));
zd_collect_result($answers, $r);

/* ============================================================ 9. the budget degrades ==== */

$expiredBudget = ['deadline' => microtime(true) - 5, 'chains' => 0, 'offline' => false];
$facts = staxx_detail_image_facts('zzstaxxtest/neverpulledimg:latest', $expiredBudget);
ok('budget: an expired deadline skips the registry chain and still returns cleanly',
   $facts === ['labels' => [], 'ports' => [], 'pulled' => false], json_encode($facts));
$hubDesc = staxx_detail_hub_description('zzstaxxtest/neverpulledimg2:latest', $expiredBudget);
ok('budget: an expired deadline skips the Hub request and still returns cleanly', $hubDesc === '');

$offlineBudget = ['deadline' => microtime(true) + 20, 'chains' => 0, 'offline' => true];
$facts2 = staxx_detail_image_facts('zzstaxxtest/neverpulledimg3:latest', $offlineBudget);
ok('budget: the offline setting alone (fresh deadline) also skips the network cleanly',
   $facts2 === ['labels' => [], 'ports' => [], 'pulled' => false], json_encode($facts2));

/* ============================================================ 10. the ownership guard === */

ok('ownership guard: an unrelated GitHub repository is refused',
   staxx_watch_claim_ok('zzstaxxtest/myapp', 'https://github.com/completely-unrelated-org/other-thing') === '');
ok('ownership guard: a repository owned by the image\'s own namespace is accepted',
   staxx_watch_claim_ok('zzstaxxtest/myapp', 'https://github.com/zzstaxxtest/anything-at-all')
   === 'https://github.com/zzstaxxtest/anything-at-all');

$tplDir = '/tmp/zzdetail-templates';
@exec('rm -rf '.escapeshellarg($tplDir));
mkdir($tplDir, 0755, true);
file_put_contents($tplDir.'/my-rejected.xml',
  '<Container><Repository>zzstaxxtest/rejectedapp</Repository><Project>https://github.com/attacker/unrelated</Project></Container>');
file_put_contents($tplDir.'/my-accepted.xml',
  '<Container><Repository>zzstaxxtest/acceptedapp</Repository><Project>https://github.com/zzstaxxtest/acceptedapp</Project></Container>');

[$home, $from] = staxx_watch_claimed_home('zzstaxxtest/rejectedapp', $tplDir);
ok('ownership guard, via staxx_watch_claimed_home(): a claimed template repository that fails the guard is refused',
   $home === '' && $from === '');
[$home2, $from2] = staxx_watch_claimed_home('zzstaxxtest/acceptedapp', $tplDir);
ok('ownership guard, via staxx_watch_claimed_home(): a claimed template repository that passes the guard is accepted',
   $home2 === 'https://github.com/zzstaxxtest/acceptedapp' && $from2 === 'template');
@exec('rm -rf '.escapeshellarg($tplDir));

/* ============================================================ 10b. the template cascade, direct === */
// staxx_detail_template_match(image, $dir) now takes its own $dir, cache keyed
// on the folder as well as the image (see the file header). Exercised
// directly against fixture folders under /tmp, never the real one.

$tplCascadeDir = '/tmp/zzdetail-tplcascade';
@exec('rm -rf '.escapeshellarg($tplCascadeDir));
mkdir($tplCascadeDir, 0755, true);
file_put_contents($tplCascadeDir.'/my-match.xml',
  '<Container>'
  .'<Repository>zzstaxxtest/tplcascade</Repository>'
  .'<Icon>https://example.org/tplicon.png</Icon>'
  .'<Overview>A template-sourced overview for the cascade test, verifying the four fields all come through together.</Overview>'
  .'<Category>MediaApp-Video</Category>'
  .'<Author>Template Author Co</Author>'
  .'</Container>');
// A .bak of the same template, holding a DIFFERENT icon — real templates-user
// folders carry exactly this after an overwrite, and it must parse just as
// happily as the real file while never being the one actually read.
file_put_contents($tplCascadeDir.'/my-match.xml.bak',
  '<Container><Repository>zzstaxxtest/tplcascade</Repository><Icon>https://example.org/WRONG-BAK-ICON.png</Icon></Container>');
file_put_contents($tplCascadeDir.'/my-abspath.xml',
  '<Container><Repository>zzstaxxtest/tplabspath</Repository><Icon>/mnt/user/appdata/icons/abspath.png</Icon></Container>');

$tplMatch = staxx_detail_template_match('zzstaxxtest/tplcascade:latest', $tplCascadeDir);
ok('template cascade: a matching repository supplies icon, overview, category and author together',
   is_array($tplMatch)
   && $tplMatch['icon'] === 'https://example.org/tplicon.png'
   && strpos($tplMatch['overview'], 'cascade test') !== false
   && $tplMatch['category'] === 'MediaApp-Video'
   && $tplMatch['author'] === 'Template Author Co',
   json_encode($tplMatch));
ok('template cascade: the .bak beside it is ignored — its different icon never wins',
   ($tplMatch['icon'] ?? '') !== 'https://example.org/WRONG-BAK-ICON.png');

// The absolute-local-path refusal lives in staxx_detail_icon() (the caller),
// not in staxx_detail_template_match() itself, which hands back the raw XML
// field untouched — so the refusal is proven at the level that actually
// enforces it, using the real template array this dir produces.
$tplAbsPath = staxx_detail_template_match('zzstaxxtest/tplabspath:latest', $tplCascadeDir);
ok('template cascade: the repository still matches, carrying the raw (unfiltered) icon value',
   is_array($tplAbsPath) && $tplAbsPath['icon'] === '/mnt/user/appdata/icons/abspath.png');
$absPathIcon = staxx_detail_icon('zzstaxxtest/tplabspath:latest', [], 'app', 'zzdetail-tplabspath', $tplAbsPath);
ok('template cascade: an absolute local path is refused as an icon — the file must run anywhere',
   $absPathIcon === null, json_encode($absPathIcon));

$tplNone = staxx_detail_template_match('zzstaxxtest/tplcascade-absent:latest', $tplCascadeDir);
ok('template cascade: a repository nothing in the folder claims is not used, even though the folder is not empty',
   $tplNone === null);

// The folder-keyed cache: a second folder, same image, a genuinely different
// answer — proving the cache cannot serve one folder's answer for another.
$tplCascadeDir2 = '/tmp/zzdetail-tplcascade2';
@exec('rm -rf '.escapeshellarg($tplCascadeDir2));
mkdir($tplCascadeDir2, 0755, true);
file_put_contents($tplCascadeDir2.'/my-other.xml',
  '<Container><Repository>zzstaxxtest/tplcascade</Repository><Icon>https://example.org/OTHERDIR-icon.png</Icon></Container>');
$tplFromDir2 = staxx_detail_template_match('zzstaxxtest/tplcascade:latest', $tplCascadeDir2);
ok('template cascade: the same image in a DIFFERENT folder gets that folder\'s own answer',
   is_array($tplFromDir2) && $tplFromDir2['icon'] === 'https://example.org/OTHERDIR-icon.png',
   json_encode($tplFromDir2));
$tplFromDir1Again = staxx_detail_template_match('zzstaxxtest/tplcascade:latest', $tplCascadeDir);
ok('template cascade: ...and the original folder\'s cached answer is untouched by that second call',
   is_array($tplFromDir1Again) && $tplFromDir1Again['icon'] === 'https://example.org/tplicon.png');

@exec('rm -rf '.escapeshellarg($tplCascadeDir).' '.escapeshellarg($tplCascadeDir2));

/* ============================================================ 11. webui, direct unit tests === */
// staxx_detail_webui() is exercised directly rather than through discover(),
// because reaching it through discover() needs a real matched template —
// see the file header. $service and $budget are the exact shapes the real
// caller builds; $budget's deadline is set in the PAST throughout so the
// live-probe branch (a real docker/curl check) never runs, keeping every
// case here a pure function call.

$pastBudget = ['deadline' => microtime(true) - 5, 'chains' => 0, 'offline' => true];

// A template webui in a scheme staxx_detail_webui() does not recognise
// (ftp://, here) fails the shape regex and is never returned as the
// candidate's scheme or path — the function falls through to its own
// bare, path-less guess (this stack's own published port, http:// only)
// rather than trusting the ftp:// address in any way.
$nonHttps = staxx_detail_webui(
  ['x' => ['webui' => ''], 'firstPort' => ['published' => '8080', 'target' => '80', 'count' => 1], 'netMode' => ''],
  ['webui' => 'ftp://[IP]:21/'], $pastBudget, ['ports' => []], 'zzdetail-webui', 'app', ''
);
ok('webui: a non-http(s) template scheme is never used for the candidate — a bare guess replaces it',
   ($nonHttps['value'] ?? '') === 'http://[IP]:8080/' && ($nonHttps['from'] ?? '') === 'ports',
   json_encode($nonHttps));

// More than one published port is the same coin-toss the icon matcher and
// the port-fallback both already refuse — even the path-less bare guess
// must not be offered.
$tooManyPorts = staxx_detail_webui(
  ['x' => ['webui' => ''], 'firstPort' => ['published' => '', 'target' => '', 'count' => 2], 'netMode' => ''],
  null, $pastBudget, ['ports' => []], 'zzdetail-webui', 'app', ''
);
ok('webui: more than one published port refuses even the bare guess', $tooManyPorts === null, json_encode($tooManyPorts));

// No template at all, but exactly one published port — still worth a bare,
// path-less guess (http:// only, since nothing here can know the scheme).
$barePublished = staxx_detail_webui(
  ['x' => ['webui' => ''], 'firstPort' => ['published' => '7000', 'target' => '80', 'count' => 1], 'netMode' => ''],
  null, $pastBudget, ['ports' => []], 'zzdetail-webui', 'app', ''
);
ok('webui: no template, one published port — a bare guess is still offered, from "ports"',
   ($barePublished['value'] ?? '') === 'http://[IP]:7000/' && ($barePublished['from'] ?? '') === 'ports',
   json_encode($barePublished));

// No template, nothing published, but the image declares exactly one port —
// the same bare guess, this time sourced from the image and said so via
// 'from' — a caller can tell the two apart even though both read as a guess.
$bareImagePort = staxx_detail_webui(
  ['x' => ['webui' => ''], 'firstPort' => [], 'netMode' => ''],
  null, $pastBudget, ['ports' => ['8000/tcp']], 'zzdetail-webui', 'app', ''
);
ok('webui: no template, no published port, one declared image port — from "image-port"',
   ($bareImagePort['value'] ?? '') === 'http://[IP]:8000/' && ($bareImagePort['from'] ?? '') === 'image-port',
   json_encode($bareImagePort));

$bridge = staxx_detail_webui(
  ['x' => ['webui' => ''], 'firstPort' => ['published' => '9999', 'target' => '80'], 'netMode' => ''],
  ['webui' => 'https://[IP]/admin'], $pastBudget, ['ports' => []], 'zzdetail-webui', 'app', ''
);
ok('webui: on a bridge network the PUBLISHED port is used, never the template\'s own token number',
   ($bridge['value'] ?? '') === 'https://[IP]:9999/admin', json_encode($bridge));
ok('webui: from/tier are ports/guess', ($bridge['from'] ?? '') === 'ports' && ($bridge['tier'] ?? '') === 'guess');

$host = staxx_detail_webui(
  ['x' => ['webui' => ''], 'firstPort' => ['published' => '', 'target' => '8080'], 'netMode' => 'host'],
  ['webui' => 'https://[IP]/admin'], $pastBudget, ['ports' => []], 'zzdetail-webui', 'app', ''
);
ok('webui: on host networking the container\'s own (target) port is used',
   ($host['value'] ?? '') === 'https://[IP]:8080/admin', json_encode($host));

$uniquePort = staxx_detail_webui(
  ['x' => ['webui' => ''], 'firstPort' => [], 'netMode' => ''],
  ['webui' => 'https://[IP]/admin'], $pastBudget, ['ports' => ['9000/tcp']], 'zzdetail-webui', 'app', ''
);
ok('webui: nothing published falls back to the image\'s ONE declared port',
   ($uniquePort['value'] ?? '') === 'https://[IP]:9000/admin', json_encode($uniquePort));

$ambiguousPort = staxx_detail_webui(
  ['x' => ['webui' => ''], 'firstPort' => [], 'netMode' => ''],
  ['webui' => 'https://[IP]/admin'], $pastBudget, ['ports' => ['9000/tcp', '9001/tcp']], 'zzdetail-webui', 'app', ''
);
ok('webui: two declared ports is ambiguous — nothing is guessed', $ambiguousPort === null, json_encode($ambiguousPort));

$suppressedWebui = staxx_detail_webui(
  ['x' => ['webui' => ''], 'firstPort' => ['published' => '9999', 'target' => '80'], 'netMode' => ''],
  ['webui' => 'https://[IP]/admin'], $pastBudget, ['ports' => []], 'zzdetail-webui', 'app', 'https://[IP]:9999/admin'
);
ok('webui: a candidate identical to what is already there is never offered as new', $suppressedWebui === null);

foreach ([$nonHttps, $barePublished, $bareImagePort, $bridge, $host, $uniquePort] as $w) zd_collect($answers, 'webui', $w);

/* ============================================================ 12. feed a suggestion back into the real resolver === */
// Proves the suggestion is actually openable by the thing that consumes it
// — no pattern match can show that, only calling staxx_webui_url() itself.

$resolved = staxx_webui_url(
  ['x' => ['webui' => 'https://[IP]:9999/admin'], 'fixedIp' => '', 'firstPort' => ['published' => '9999', 'target' => '80']],
  '10.0.0.5'
);
ok('proof: the suggested web address resolves to a real, openable link',
   $resolved === 'https://10.0.0.5:9999/admin', $resolved);

/* ============================================================ 13. category drift guard === */
// Compares only what is mechanically comparable: the two files' recognised
// category head lists. Does NOT compare the surrounding split-on-space or
// multi-category handling — javascript/ca-convert.js's normaliseCategory()
// takes a single already-chosen category, while Detail.php's copy also
// splits a template's space-separated list first, so the two functions are
// not call-for-call identical and this does not pretend they are.

$jsSrc = @file_get_contents('/usr/local/emhttp/plugins/staxx/javascript/ca-convert.js');
ok('drift guard: ca-convert.js is readable on this server', is_string($jsSrc) && $jsSrc !== '');
$jsHeads = [];
if (is_string($jsSrc) && preg_match('/CATEGORY_HEADS\s*=\s*\[(.*?)\]/s', $jsSrc, $m)) {
  preg_match_all("/'([^']+)'/", $m[1], $mm);
  $jsHeads = $mm[1];
}
ok('drift guard: the category head list is byte-for-byte the same in both files',
   $jsHeads === STAXX_DETAIL_CATEGORY_HEADS, 'js='.json_encode($jsHeads).' php='.json_encode(STAXX_DETAIL_CATEGORY_HEADS));

/* ============================================================ 14. Hub readme never used === */
// A static check, not a runtime one: with the network forced off for this
// entire run, staxx_detail_hub_description() never calls staxx_hub_repo()
// at all, so there is no runtime answer to inspect. What CAN be proven
// without the network is that the shipped source never wires Hub's
// 'readme' key to anything — the exact mistake PLAN_84's defect 4 names.

$detailSrc = @file_get_contents('/usr/local/emhttp/plugins/staxx/include/Detail.php');
ok('Hub readme: the source file is readable on this server', is_string($detailSrc) && $detailSrc !== '');
ok('Hub readme: staxx_hub_repo()\'s readme key is never read anywhere in this file',
   is_string($detailSrc) && !preg_match('/staxx_hub_repo\([^;]*?\)\s*\[\s*[\'"]readme[\'"]\s*\]/s', $detailSrc));

/* ============================================================ invariants, over every answer collected above === */

/** An independent copy of the schema's own patterns, read from
 *  schema/x-unraid.schema.json by eye rather than from Detail.php's own
 *  staxx_detail_schema_ok() — the whole point is to catch the two ever
 *  drifting apart, not to test Detail.php against itself. */
function zd_schema_pattern_ok(string $field, string $value): bool {
  if ($value === '' || strpos($value, "\n") !== false) return false;
  switch ($field) {
    case 'project':
    case 'support':
    case 'readme':
    case 'webui':
      return preg_match('#^https?://#', $value) === 1;
    case 'icon':
      return preg_match('/^(https?:\/\/\S+|\.\/\S+|fa-[a-z0-9-]+|[A-Za-z0-9][A-Za-z0-9._-]*)$/', $value) === 1;
    case 'overview':
    case 'category':
    case 'author':
      return trim($value) !== '';
    default:
      return false;
  }
}

$schemaBad = 0;
$statedLeak = 0;
foreach ($answers as $a) {
  if (!zd_schema_pattern_ok($a['field'], (string)$a['value'])) {
    $schemaBad++;
    echo "FAIL   schema invariant: {$a['field']} = ".json_encode($a['value'])." does not pass the schema's own pattern\n";
  }
  if (($a['from'] === 'catalog' || $a['from'] === 'template') && $a['tier'] === 'stated') {
    $statedLeak++;
    echo "FAIL   tier invariant: {$a['field']} from '{$a['from']}' was labelled 'stated'\n";
  }
}
ok('invariant: every one of '.count($answers).' collected answers passes the schema\'s own pattern', $schemaBad === 0);
ok('invariant: no catalogue or template value was ever labelled stated', $statedLeak === 0);

printf("\n%s — %d failure%s\n", $fails ? 'FAILED' : 'passed', $fails, $fails === 1 ? '' : 's');
exit($fails ? 1 : 0);
