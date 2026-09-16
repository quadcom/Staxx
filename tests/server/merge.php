<?php
/* PLAN_155 — the server side of merging several stacks into a brand new
 * third one: staxx_merge_files() (the nested companion-file listing, its
 * exclusions, keyLike/referenced flags, and the 10MB "large" stop),
 * staxx_merge_stacks() itself (every refusal before a byte moves, taking
 * every source down, copying with modes preserved, writing the new stack,
 * retiring every source last), and the retirement wording every run-verb
 * refusal now carries. PLAN_155 C11 added: an inside-pointing symlink is
 * recreated verbatim rather than copied or refused; an outside-pointing one
 * is refused by the file walk before any source is taken down; and a
 * failure from the copy step onward undoes the new folder and brings every
 * source this call took down back up again. PLAN_155 C14 added: taking a
 * source down is `down --remove-orphans`, not `stop` — a stopped container
 * keeps its name, so a source carrying `container_name:` cannot start
 * beside a merely stopped original — and the rollback brings sources back
 * with `up -d`, not `start`, since `down` removed them outright. PLAN_155
 * C13 added: a folder entry
 * copies only the children the plan does not name on their own, whether
 * those are renamed or left behind, and a symlink entry that the folder
 * copy already recreated counts as done instead of failing on EEXIST.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. Needs STORE_ROOT
 * pointed at /tmp/zzc155-store, the same way tests/server/clash.php does
 * it — never the real store:
 *
 *     pscp tests/server/merge.php root@<box>:/tmp/
 *     plink … '
 *       CFG=/boot/config/plugins/staxx/staxx.cfg
 *       cp $CFG /tmp/cfg.bak
 *       grep -q "^STORE_ROOT=" $CFG \
 *         && sed -i "s#^STORE_ROOT=.*#STORE_ROOT=\"/tmp/zzc155-store\"#" $CFG \
 *         || echo "STORE_ROOT=\"/tmp/zzc155-store\"" >> $CFG
 *       php /tmp/merge.php; RC=$?
 *       cp /tmp/cfg.bak $CFG
 *       exit $RC
 *     '
 *
 * Prints one line per case and exits non-zero on any failure. Creates and
 * removes its own stacks, all named "zzc155…", under the scratch stacks
 * folder. Nothing here starts or pulls anything by default — the one case
 * that touches Docker at all is the successful merge's down step, which is
 * run against a stack that was never started (so it is taking nothing down)
 * purely to prove the step itself works; if this box has no compose or no
 * Docker daemon, that one case is skipped rather than failed — see SKIP
 * below.
 *
 * PLAN_155 "Step 6 as settled" added two flags, $stop and $start, to
 * staxx_merge_stacks(). Proving $stop actually gates the down step needs a
 * source whose "down" would fail while its OWN validation still passes —
 * and since validation runs the identical compose command against the
 * identical file and directory, the only place those two genuinely diverge
 * is when there is no compose to run at all: staxx_validate_compose()
 * accepts anything when it has nothing to check against, but the merge's own
 * down step still refuses outright. So that pair of cases only runs on a box
 * with no compose installed and is skipped, not failed, everywhere else —
 * StaXX brings its own compose (PLAN_136), so this is expected to skip on a
 * real Unraid box and is here for a box that genuinely lacks one.
 *
 * $start actually launching the new stack's "up" job is a real pull-and-run
 * against whatever Docker this box has, which must never happen against
 * Adrian's own server by accident — so that one case is opt-in behind
 * STAXX_MERGE_LIVE_START=1, the same shape as this project's other
 * opt-in suites (STAXX_LIVE_COMPOSE, STAXX_LIVE_REGISTRY, …), and skipped by
 * default with the images it would use named plainly.
 */

require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Merge.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}
function skip(string $what, string $why): void {
  printf("%-6s %s  (skipped — %s)\n", 'SKIP', $what, $why);
}

if (staxx_stack_root() !== '/tmp/zzc155-store/stacks') {
  echo "FAIL   the temporary stack root is not in place (got ".staxx_stack_root().")\n";
  exit(1);
}

$root = staxx_stack_root();
@exec('rm -rf '.escapeshellarg($root));
mkdir($root, 0755, true);

function mkStack(string $root, string $rel, string $compose, array $extras = []): void {
  $dir = $root.'/'.$rel;
  mkdir($dir, 0755, true);
  file_put_contents($dir.'/compose.yaml', $compose);
  foreach ($extras as $name => $body) {
    $path = $dir.'/'.$name;
    if (strpos($name, '/') !== false) @mkdir(dirname($path), 0755, true);
    file_put_contents($path, $body);
  }
}

/* ========================================================================
 * A. staxx_merge_files() — the nested listing
 * ======================================================================== */

mkStack($root, 'zzc155files', "services:\n  app:\n    image: nginx:latest\n    # uses ./cert.pem\n", [
  '.env'                => "FOO=bar\n",
  'cert.pem'             => "-----BEGIN CERTIFICATE-----\n",
  'certs/other.crt'      => "-----BEGIN CERTIFICATE-----\n",
  'plain.txt'            => "nothing special\n",
  '.staxx/icon.png'      => "\x89PNG fake",
  '.staxx/note.txt'      => "not an image, must not be offered\n",
]);
mkdir($root.'/zzc155files/.staxx/versions', 0755, true);
file_put_contents($root.'/zzc155files/.staxx/versions/1.yaml', "services: {}\n");
file_put_contents($root.'/zzc155files/NEEDS-REVIEW.md', "irrelevant text — only used to prove exclusion\n");
file_put_contents($root.'/zzc155files/HANDOVER.md', "irrelevant text — only used to prove exclusion\n");
staxx_scan_stacks_reset();

$err = ''; $listing = staxx_merge_files('zzc155files', $err);
ok('staxx_merge_files() reads a stack folder', $listing !== null, $err);
$byPath = [];
foreach (($listing['files'] ?? []) as $entry) $byPath[$entry['path']] = $entry;

ok('the compose file itself is never offered', !isset($byPath['compose.yaml']));
ok('.env is never offered', !isset($byPath['.env']));
ok('NEEDS-REVIEW.md is never offered', !isset($byPath['NEEDS-REVIEW.md']));
ok('HANDOVER.md is never offered', !isset($byPath['HANDOVER.md']));
ok('an image file directly inside .staxx IS offered', isset($byPath['.staxx/icon.png']));
ok('a non-image file inside .staxx is not offered', !isset($byPath['.staxx/note.txt']));
ok('nothing below .staxx is walked at all', !isset($byPath['.staxx/versions/1.yaml']));
ok('a plain file is offered', isset($byPath['plain.txt']));
ok('cert.pem is flagged key-like by its extension', ($byPath['cert.pem']['keyLike'] ?? false) === true);
ok('a file under certs/ is flagged key-like by its folder', ($byPath['certs/other.crt']['keyLike'] ?? false) === true);
ok('an ordinary file is not flagged key-like', ($byPath['plain.txt']['keyLike'] ?? true) === false);
ok('cert.pem is flagged as referenced — the compose file names it',
   ($byPath['cert.pem']['referenced'] ?? false) === true);
ok('plain.txt is not referenced — nothing in the compose file names it',
   ($byPath['plain.txt']['referenced'] ?? true) === false);
ok('no large folder was found in an ordinary stack', array_key_exists('large', $listing) && $listing['large'] === null);

// A0.5 — PLAN_156 F12: a file under a folder the compose file actually
// mounts is referenced even though its own path never appears in the text;
// a loose file beside it, named nowhere, still is not.
mkStack($root, 'zzc155mount', "services:\n  app:\n    image: nginx:latest\n    volumes:\n      - ./data:/data\n", [
  'data/inside.txt' => "never named directly\n",
  'loose.txt'       => "named nowhere at all\n",
]);
staxx_scan_stacks_reset();
$err = ''; $mountListing = staxx_merge_files('zzc155mount', $err);
$mountByPath = [];
foreach (($mountListing['files'] ?? []) as $entry) $mountByPath[$entry['path']] = $entry;
ok('a file under a mounted folder is referenced',
   ($mountByPath['data/inside.txt']['referenced'] ?? false) === true);
ok('a loose file beside it is not referenced',
   ($mountByPath['loose.txt']['referenced'] ?? true) === false);

// A1 — the 10MB guard, and that the walk stops there.
mkdir($root.'/zzc155big/bulk', 0755, true);
file_put_contents($root.'/zzc155big/compose.yaml', "services:\n  app:\n    image: nginx:latest\n");
$fh = fopen($root.'/zzc155big/bulk/huge.bin', 'w');
ftruncate($fh, 11 * 1024 * 1024);
fclose($fh);
file_put_contents($root.'/zzc155big/bulk/small.txt', "after the big file\n");
staxx_scan_stacks_reset();

$err = ''; $bigListing = staxx_merge_files('zzc155big', $err);
ok('the 10MB guard fires on a folder that crosses it',
   is_array($bigListing['large'] ?? null) && ($bigListing['large']['path'] ?? '') === 'bulk');

// A2 — a relative symlink resolving outside the stack folder.
mkdir($root.'/zzc155link', 0755, true);
file_put_contents($root.'/zzc155link/compose.yaml', "services:\n  app:\n    image: nginx:latest\n");
symlink('../../outside-the-store', $root.'/zzc155link/escapes');
symlink('./compose.yaml', $root.'/zzc155link/inside-link');
staxx_scan_stacks_reset();

$err = ''; $linkListing = staxx_merge_files('zzc155link', $err);
$linkByPath = [];
foreach (($linkListing['files'] ?? []) as $entry) $linkByPath[$entry['path']] = $entry;
ok('a symlink resolving outside the stack folder is flagged outside',
   ($linkByPath['escapes']['outside'] ?? false) === true);
ok('a symlink resolving inside the stack folder is not flagged outside',
   ($linkByPath['inside-link']['outside'] ?? true) === false);

/* ========================================================================
 * B. Refusals — every one leaves every stack, and the disk, exactly as it
 *    was before the call
 * ======================================================================== */

mkStack($root, 'zzc155db', "services:\n  db:\n    image: mariadb:11\n");
mkStack($root, 'zzc155web', "services:\n  web:\n    image: nginx:latest\n");
staxx_scan_stacks_reset();
$dbFp  = staxx_stack_fingerprint('zzc155db');
$webFp = staxx_stack_fingerprint('zzc155web');
$goodFps = ['zzc155db' => $dbFp, 'zzc155web' => $webFp];
$mergedBody = "# Made by joining zzc155db and zzc155web, 2026-09-15.\n"
            . "services:\n  db:\n    image: mariadb:11\n  web:\n    image: nginx:latest\n";

// B1 — the new name already exists.
$err = ''; $facts = null;
$okExists = staxx_merge_stacks('zzc155db', ['zzc155db', 'zzc155web'], $goodFps, $mergedBody, null, [], [], false, false, $err, $facts);
ok('a new name already in use is refused', !$okExists);
ok('the reason says so', stripos($err, 'already') !== false, $err);

// B2 — fewer than two sources.
$err = ''; $facts = null;
$okFew = staxx_merge_stacks('zzc155appB2', ['zzc155db'], $goodFps, $mergedBody, null, [], [], false, false, $err, $facts);
ok('fewer than two sources is refused', !$okFew);

// B3 — the same stack named twice as a source. (A source sharing the new
// stack's own NAME is also refused, but only ever via the "already exists"
// check above — a valid source is by definition a real directory, so it
// can never equal a newRel that has just been proven not to exist.)
$err = ''; $facts = null;
$okDupe = staxx_merge_stacks('zzc155appB3', ['zzc155db', 'zzc155db'], $goodFps, $mergedBody, null, [], [], false, false, $err, $facts);
ok('naming the same stack twice as a source is refused', !$okDupe);

// B4 — a fingerprint mismatch is reported as a conflict.
$err = ''; $facts = null;
$badFps = ['zzc155db' => 'not-the-real-fingerprint', 'zzc155web' => $webFp];
$okConflict = staxx_merge_stacks('zzc155appB4', ['zzc155db', 'zzc155web'], $badFps, $mergedBody, null, [], [], false, false, $err, $facts);
ok('a stale fingerprint is refused', !$okConflict);
ok('it is reported as a conflict, not a plain refusal', ($facts['conflict'] ?? false) === true);

// B5 — a destination that would pair with a compose file.
$err = ''; $facts = null;
$filesPair = [['from' => 'zzc155db', 'path' => 'compose.yaml', 'to' => 'docker-compose.override.yml']];
// compose.yaml is excluded from staxx_merge_files() itself, so this also
// proves the "path must be in that source's own list" refusal fires first —
// there is no path where naming the compose file directly gets anywhere.
$okPairSelf = staxx_merge_stacks('zzc155appB5', ['zzc155db', 'zzc155web'], $goodFps, $mergedBody, null, $filesPair, [], false, false, $err, $facts);
ok('a path not in the source\'s own file list is refused', !$okPairSelf);

mkStack($root, 'zzc155dbf', "services:\n  db:\n    image: mariadb:11\n", ['note.txt' => "hi\n"]);
staxx_scan_stacks_reset();
$dbfFp = staxx_stack_fingerprint('zzc155dbf');
$fpsB = ['zzc155dbf' => $dbfFp, 'zzc155web' => staxx_stack_fingerprint('zzc155web')];

$err = ''; $facts = null;
$filesDangerous = [['from' => 'zzc155dbf', 'path' => 'note.txt', 'to' => 'docker-compose.override.yml']];
$okDangerous = staxx_merge_stacks('zzc155appB5b', ['zzc155dbf', 'zzc155web'], $fpsB, $mergedBody, null, $filesDangerous, [], false, false, $err, $facts);
ok('a destination name that would pair with a compose file is refused', !$okDangerous);

// B6 — a destination path carrying "..".
$err = ''; $facts = null;
$filesDots = [['from' => 'zzc155dbf', 'path' => 'note.txt', 'to' => '../escaped.txt']];
$okDots = staxx_merge_stacks('zzc155appB6', ['zzc155dbf', 'zzc155web'], $fpsB, $mergedBody, null, $filesDots, [], false, false, $err, $facts);
ok('a destination path carrying ".." is refused', !$okDots);

// B7 — a path that is not in the source's own list.
$err = ''; $facts = null;
$filesMissing = [['from' => 'zzc155dbf', 'path' => 'nope.txt', 'to' => 'nope.txt']];
$okMissing = staxx_merge_stacks('zzc155appB7', ['zzc155dbf', 'zzc155web'], $fpsB, $mergedBody, null, $filesMissing, [], false, false, $err, $facts);
ok('a path the source does not actually offer is refused', !$okMissing);

// B8 — two entries with the same destination.
$err = ''; $facts = null;
mkStack($root, 'zzc155web2b', "services:\n  web:\n    image: nginx:latest\n", ['note2.txt' => "hi\n"]);
staxx_scan_stacks_reset();
$fpsC = ['zzc155dbf' => staxx_stack_fingerprint('zzc155dbf'), 'zzc155web2b' => staxx_stack_fingerprint('zzc155web2b')];
$filesClash2 = [
  ['from' => 'zzc155dbf',    'path' => 'note.txt',  'to' => 'shared.txt'],
  ['from' => 'zzc155web2b',  'path' => 'note2.txt', 'to' => 'shared.txt'],
];
$okClashTo = staxx_merge_stacks('zzc155appB8', ['zzc155dbf', 'zzc155web2b'], $fpsC, $mergedBody, null, $filesClash2, [], false, false, $err, $facts);
ok('two files both destined for the same name is refused', !$okClashTo);

ok('none of the refusals above left a folder behind',
   !is_dir($root.'/zzc155appB2') && !is_dir($root.'/zzc155appB3') && !is_dir($root.'/zzc155appB4')
   && !is_dir($root.'/zzc155appB5') && !is_dir($root.'/zzc155appB5b') && !is_dir($root.'/zzc155appB6')
   && !is_dir($root.'/zzc155appB7') && !is_dir($root.'/zzc155appB8'));
ok('the sources themselves were never touched by any refusal above',
   file_get_contents($root.'/zzc155dbf/compose.yaml') === "services:\n  db:\n    image: mariadb:11\n"
   && file_get_contents($root.'/zzc155web/compose.yaml') === "services:\n  web:\n    image: nginx:latest\n");

/* ========================================================================
 * C. A succeeding merge — needs compose AND a running Docker daemon, since
 *    the merge stops every source before copying anything. Skipped, not
 *    failed, when this box has neither.
 * ======================================================================== */

if (staxx_compose_cmd() === '' || !staxx_docker_running()) {
  skip('a succeeding merge writes the new stack and retires both sources',
       'no compose, or no Docker daemon, on this box');
} else {
  mkStack($root, 'zzc155srcdb', "services:\n  db:\n    image: mariadb:11\n    # uses ./cert.pem\n",
    ['cert.pem' => "-----BEGIN CERTIFICATE-----\nfake\n"]);
  chmod($root.'/zzc155srcdb/cert.pem', 0600);
  mkStack($root, 'zzc155srcweb', "services:\n  web:\n    image: nginx:latest\n",
    ['note.txt' => "carried into a nested folder\n"]);
  staxx_scan_stacks_reset();

  $srcFps = [
    'zzc155srcdb'  => staxx_stack_fingerprint('zzc155srcdb'),
    'zzc155srcweb' => staxx_stack_fingerprint('zzc155srcweb'),
  ];
  $newBody = "# Made by joining zzc155srcdb and zzc155srcweb, ".date('Y-m-d').".\n"
           . "services:\n"
           . "  # From zzc155srcdb\n  db:\n    image: mariadb:11\n"
           . "  # From zzc155srcweb\n  web:\n    image: nginx:latest\n";
  $copyFiles = [
    ['from' => 'zzc155srcdb',  'path' => 'cert.pem',  'to' => 'cert.pem'],
    ['from' => 'zzc155srcweb', 'path' => 'note.txt',  'to' => 'notes/web-note.txt'],
  ];
  $date = date('Y-m-d');
  $retiredDb = "# Retired into zzc155app, $date — every service carries the \"retired\" profile so "
             . "plain docker compose up starts nothing.\n"
             . "services:\n  db:\n    image: mariadb:11\n    # uses ./cert.pem\n    profiles: [\"retired\"]\n";
  $retiredWeb = "# Retired into zzc155app, $date — every service carries the \"retired\" profile so "
              . "plain docker compose up starts nothing.\n"
              . "services:\n  web:\n    image: nginx:latest\n    profiles: [\"retired\"]\n";
  $retiredMap = ['zzc155srcdb' => $retiredDb, 'zzc155srcweb' => $retiredWeb];

  $err = ''; $facts = null;
  $okGood = staxx_merge_stacks('zzc155app', ['zzc155srcdb', 'zzc155srcweb'], $srcFps, $newBody, null,
    $copyFiles, $retiredMap, true, false, $err, $facts);
  ok('a clean merge into a brand new stack succeeds', $okGood, $err);
  ok('with start off, no job is offered back', !isset($facts['job']));

  ok('the new folder was created', is_dir($root.'/zzc155app'));
  ok('the new stack\'s compose file is exactly the body handed to it',
     @file_get_contents($root.'/zzc155app/compose.yaml') === $newBody);
  ok('a copied file is present at the destination given for it',
     is_file($root.'/zzc155app/notes/web-note.txt')
     && file_get_contents($root.'/zzc155app/notes/web-note.txt') === "carried into a nested folder\n");
  ok('a source file copied at a tighter mode than the share default keeps that mode',
     is_file($root.'/zzc155app/cert.pem') && (fileperms($root.'/zzc155app/cert.pem') & 0777) === 0600);

  ok('the first source\'s own file now reads as its retirement text',
     @file_get_contents($root.'/zzc155srcdb/compose.yaml') === $retiredDb);
  ok('the second source\'s own file now reads as its retirement text',
     @file_get_contents($root.'/zzc155srcweb/compose.yaml') === $retiredWeb);
  ok('the first source\'s NEEDS-REVIEW.md names the new stack',
     strpos((string)@file_get_contents($root.'/zzc155srcdb/NEEDS-REVIEW.md'), 'zzc155app') !== false);
  ok('the first source\'s NEEDS-REVIEW.md uses the "Retired into" wording',
     strpos((string)@file_get_contents($root.'/zzc155srcdb/NEEDS-REVIEW.md'), 'Retired into') !== false);

  $mark = staxx_record_merged_into('zzc155srcdb');
  ok('the first source\'s own record now says it was retired into the new stack',
     is_array($mark) && ($mark['host'] ?? '') === 'zzc155app' && is_int($mark['at'] ?? null));
  $mark2 = staxx_record_merged_into('zzc155srcweb');
  ok('the second source\'s own record says the same',
     is_array($mark2) && ($mark2['host'] ?? '') === 'zzc155app');

  $startErr = '';
  staxx_start_job('zzc155srcdb', 'up', $startErr);
  ok('starting a retired stack is refused with the retirement wording, not the review wording',
     stripos($startErr, 'retired into') !== false && stripos($startErr, 'imported') === false, $startErr);

  // C11a — a symlink whose target resolves INSIDE its own stack folder is
  // recreated in the new folder as a symlink carrying the same relative
  // target, never copied as a file's bytes. The outside case is proved
  // separately below, without needing Docker at all.
  mkStack($root, 'zzc155linksrc', "services:\n  app:\n    image: nginx:latest\n",
    ['conf/default.conf' => "server { listen 80; }\n"]);
  symlink('./default.conf', $root.'/zzc155linksrc/conf/current.conf');
  mkStack($root, 'zzc155linksrc2', "services:\n  app2:\n    image: nginx:latest\n");
  staxx_scan_stacks_reset();

  $linkFps  = ['zzc155linksrc'  => staxx_stack_fingerprint('zzc155linksrc'),
               'zzc155linksrc2' => staxx_stack_fingerprint('zzc155linksrc2')];
  $linkBody = "services:\n  app:\n    image: nginx:latest\n  app2:\n    image: nginx:latest\n";
  $linkFiles = [
    ['from' => 'zzc155linksrc', 'path' => 'conf/default.conf', 'to' => 'conf/default.conf'],
    ['from' => 'zzc155linksrc', 'path' => 'conf/current.conf', 'to' => 'conf/current.conf'],
  ];

  $err = ''; $facts = null;
  $okLink = staxx_merge_stacks('zzc155linkapp', ['zzc155linksrc', 'zzc155linksrc2'], $linkFps, $linkBody,
    null, $linkFiles, [], true, false, $err, $facts);
  ok('a merge carrying an inside-pointing symlink succeeds', $okLink, $err);
  ok('the symlink is recreated as a symlink, not a copy of what it points at',
     is_link($root.'/zzc155linkapp/conf/current.conf'));
  ok('the recreated symlink carries the same relative target the source had',
     @readlink($root.'/zzc155linkapp/conf/current.conf') === './default.conf');

  // C11b — a failure from the copy step onward must undo the new folder AND
  // start the sources back up, not just leave them stopped. Forced without
  // any test-only hook: the file list itself asks for "blocker" as a plain
  // file, then for a second file underneath "blocker/" as though it were a
  // folder — the exact shape the live bug report hit (a symlink's own
  // parent folder missing), reproduced here with two ordinary files so it
  // needs nothing but this function's own inputs. Both sources are never
  // actually started (same reasoning as the merge above, and as this
  // section's own header), so "stop" and the "start" this failure should
  // trigger are both genuine no-ops on this box — which is exactly what
  // lets this run without touching Docker for real.
  mkStack($root, 'zzc155rollA', "services:\n  a:\n    image: nginx:latest\n",
    ['marker.txt' => "occupies the name a later copy needs as a folder\n"]);
  mkStack($root, 'zzc155rollB', "services:\n  b:\n    image: nginx:latest\n",
    ['inner.txt' => "never actually copied — the merge fails before reaching it\n"]);
  staxx_scan_stacks_reset();

  $rollFps    = ['zzc155rollA' => staxx_stack_fingerprint('zzc155rollA'),
                 'zzc155rollB' => staxx_stack_fingerprint('zzc155rollB')];
  $rollOrigA  = @file_get_contents($root.'/zzc155rollA/compose.yaml');
  $rollOrigB  = @file_get_contents($root.'/zzc155rollB/compose.yaml');
  $rollBody   = "services:\n  a:\n    image: nginx:latest\n  b:\n    image: nginx:latest\n";
  $rollFiles  = [
    ['from' => 'zzc155rollA', 'path' => 'marker.txt', 'to' => 'blocker'],
    ['from' => 'zzc155rollB', 'path' => 'inner.txt',  'to' => 'blocker/inner.txt'],
  ];

  $err = ''; $facts = null;
  $okRoll = staxx_merge_stacks('zzc155rollapp', ['zzc155rollA', 'zzc155rollB'], $rollFps, $rollBody,
    null, $rollFiles, [], true, false, $err, $facts);
  ok('a copy failure after the stop step is refused', !$okRoll);
  ok('the new folder was not left behind', !is_dir($root.'/zzc155rollapp'));
  ok('the refusal says the originals were started again',
     stripos($err, 'started again') !== false, $err);
  ok('neither source was retired by the failed merge',
     @file_get_contents($root.'/zzc155rollA/compose.yaml') === $rollOrigA
     && @file_get_contents($root.'/zzc155rollB/compose.yaml') === $rollOrigB);
}

// C11c — a link pointing OUTSIDE its own stack folder is refused by the
// file walk BEFORE anything is stopped. Proved the same way D2/D3 below
// prove $stop itself: with compose installed, a refusal reached via the
// walk and one reached via the stop step read the same either way, so this
// needs a box with no compose installed, where the stop step's own error
// ("Compose is not installed…") would be unmistakable if the walk ran
// second. Getting the LINK wording instead — never the compose wording —
// is what proves the walk ran first, with no source ever stopped.
if (staxx_compose_cmd() !== '') {
  skip('a link pointing outside its stack is refused before any stop is attempted',
       'compose is installed on this box, so a walk-first refusal and a stop-first one would read the same');
} else {
  mkdir($root.'/zzc155outsidesrc', 0755, true);
  file_put_contents($root.'/zzc155outsidesrc/compose.yaml', "services:\n  app:\n    image: nginx:latest\n");
  symlink('../../outside-the-store', $root.'/zzc155outsidesrc/escapes');
  mkStack($root, 'zzc155outsidesrc2', "services:\n  app2:\n    image: nginx:latest\n");
  staxx_scan_stacks_reset();

  $outFps   = ['zzc155outsidesrc'  => staxx_stack_fingerprint('zzc155outsidesrc'),
               'zzc155outsidesrc2' => staxx_stack_fingerprint('zzc155outsidesrc2')];
  $outBody  = "services:\n  app:\n    image: nginx:latest\n  app2:\n    image: nginx:latest\n";
  $outFiles = [
    ['from' => 'zzc155outsidesrc', 'path' => 'escapes', 'to' => 'escapes'],
  ];

  $err = ''; $facts = null;
  $okOut = staxx_merge_stacks('zzc155outsideapp', ['zzc155outsidesrc', 'zzc155outsidesrc2'], $outFps,
    $outBody, null, $outFiles, [], true, false, $err, $facts);
  ok('a link pointing outside its stack is refused', !$okOut);
  ok('the refusal names the link, not the missing compose install',
     stripos($err, 'outside') !== false && stripos($err, 'Compose is not installed') === false, $err);
  ok('nothing was written for the refused merge', !is_dir($root.'/zzc155outsideapp'));
}

/* ========================================================================
 * D. The two switches — PLAN_155 "Step 6 as settled": $stop and $start
 * ======================================================================== */

mkStack($root, 'zzc155stopa', "services:\n  a:\n    image: nginx:latest\n");
mkStack($root, 'zzc155stopb', "services:\n  b:\n    image: nginx:latest\n");
staxx_scan_stacks_reset();
$stopFps  = ['zzc155stopa' => staxx_stack_fingerprint('zzc155stopa'),
             'zzc155stopb' => staxx_stack_fingerprint('zzc155stopb')];
$stopBody = "services:\n  a:\n    image: nginx:latest\n  b:\n    image: nginx:latest\n";

// D1 — start set with stop clear is refused outright, before anything on
// disk changes, on every box regardless of what it has installed: the
// browser already locks the two switches together, so this can only be a
// bug or a bypass reaching the server, never a considered choice.
$err = ''; $facts = null;
$okStartOnly = staxx_merge_stacks('zzc155appD1', ['zzc155stopa', 'zzc155stopb'], $stopFps, $stopBody,
  null, [], [], false, true, $err, $facts);
ok('start without stop is refused', !$okStartOnly);
ok('the reason names stopping', stripos($err, 'stop') !== false, $err);
ok('nothing was written for the refused start-only attempt', !is_dir($root.'/zzc155appD1'));

// D2/D3 — proving $stop actually gates the down step. See this file's own
// header for why that needs a box with no compose installed at all: with
// one installed, the source's own validation would refuse an invalid file
// before the down step is ever reached, on EITHER setting of $stop, which
// would prove nothing about the flag itself.
if (staxx_compose_cmd() !== '') {
  skip('stop=false skips the down step, and stop=true genuinely runs it',
       'compose is installed on this box, so the two paths cannot be told apart without really taking something down');
} else {
  $err = ''; $facts = null;
  $okStopOff = staxx_merge_stacks('zzc155appD2', ['zzc155stopa', 'zzc155stopb'], $stopFps, $stopBody,
    null, [], [], false, false, $err, $facts);
  ok('stop=false succeeds with no compose installed to take anything down with', $okStopOff, $err);

  // Left un-retired (no $retired entries were passed), so the sources are
  // untouched and the same fingerprints are still good for the next call.
  $err = ''; $facts = null;
  $okStopOn = staxx_merge_stacks('zzc155appD3', ['zzc155stopa', 'zzc155stopb'], $stopFps, $stopBody,
    null, [], [], true, false, $err, $facts);
  ok('stop=true refuses with the down error when compose is not installed', !$okStopOn);
  ok('the refusal names compose, not the file', stripos($err, 'Compose is not installed') !== false, $err);
}

// D3b — PLAN_155 C14: with $stop on and compose actually installed and
// running, no container of either source's project may survive a
// successful merge, and each source's named volume must — `down` releases
// containers, `-v` was never passed. Opt-in for the same reason as D4:
// this really pulls and starts images against whatever Docker the box has.
if (getenv('STAXX_MERGE_LIVE_START') !== '1') {
  skip('stop=true takes the source down (not merely stops it) and its named volume survives',
       'set STAXX_MERGE_LIVE_START=1 to run this — it really pulls and starts mariadb');
} elseif (staxx_compose_cmd() === '' || !staxx_docker_running()) {
  skip('stop=true takes the source down (not merely stops it) and its named volume survives',
       'no compose, or no Docker daemon, on this box');
} else {
  mkStack($root, 'zzc155downsrc', "services:\n  db:\n    image: mariadb:11\n"
    . "    container_name: zzc155downsrc-db\n    volumes:\n      - dbdata:/var/lib/mysql\n"
    . "volumes:\n  dbdata:\n");
  staxx_scan_stacks_reset();
  $downJob = ''; $downErr = '';
  $downJob = staxx_start_job('zzc155downsrc', 'up', $downErr);
  ok('the source is brought up so the merge has something to take down', $downJob !== '', $downErr);
  sleep(3); // give the container a moment to actually exist before the merge stops it

  mkStack($root, 'zzc155downother', "services:\n  web:\n    image: nginx:latest\n");
  staxx_scan_stacks_reset();
  $downFps = ['zzc155downsrc'   => staxx_stack_fingerprint('zzc155downsrc'),
              'zzc155downother' => staxx_stack_fingerprint('zzc155downother')];
  $downBody = "services:\n  db:\n    image: mariadb:11\n    container_name: zzc155downsrc-db\n"
            . "    volumes:\n      - dbdata:/var/lib/mysql\n  web:\n    image: nginx:latest\n"
            . "volumes:\n  dbdata:\n";

  $err = ''; $facts = null;
  $okDown = staxx_merge_stacks('zzc155downapp', ['zzc155downsrc', 'zzc155downother'], $downFps, $downBody,
    null, [], [], true, false, $err, $facts);
  ok('the merge with stop=true succeeds against a running source', $okDown, $err);

  $psOut = ''; $psCode = 0;
  $psOut = staxx_sh('docker ps -aq --filter "name=zzc155downsrc-db" 2>&1', 30, $psCode);
  ok('no container of the source\'s project remains after the merge', trim($psOut) === '', $psOut);

  $volOut = ''; $volCode = 0;
  $volOut = staxx_sh('docker volume ls -q --filter "name=zzc155downsrc_dbdata" 2>&1', 30, $volCode);
  ok('the source\'s named volume survives the merge', trim($volOut) !== '', $volOut);

  // Clean up what this case itself created — not part of $root's own
  // teardown, since these went through a real 'up', not just mkStack().
  staxx_sh('docker volume rm zzc155downsrc_dbdata 2>&1', 30, $psCode);
}

// D4 — $start actually launching the new stack's "up" job. Opt-in only: a
// real pass here pulls nginx and mariadb and starts them, which must never
// happen against Adrian's own server by accident (see this file's header).
if (getenv('STAXX_MERGE_LIVE_START') !== '1') {
  skip('start=true launches the new stack\'s own "up" job and the reply carries it',
       'set STAXX_MERGE_LIVE_START=1 to run this — it really pulls and starts nginx and mariadb');
} elseif (staxx_compose_cmd() === '' || !staxx_docker_running()) {
  skip('start=true launches the new stack\'s own "up" job and the reply carries it',
       'no compose, or no Docker daemon, on this box');
} else {
  mkStack($root, 'zzc155srcdb3', "services:\n  db:\n    image: mariadb:11\n");
  mkStack($root, 'zzc155srcweb3', "services:\n  web:\n    image: nginx:latest\n");
  staxx_scan_stacks_reset();
  $liveFps = ['zzc155srcdb3'  => staxx_stack_fingerprint('zzc155srcdb3'),
              'zzc155srcweb3' => staxx_stack_fingerprint('zzc155srcweb3')];
  $liveBody = "services:\n  db:\n    image: mariadb:11\n  web:\n    image: nginx:latest\n";

  $err = ''; $facts = null;
  $okLive = staxx_merge_stacks('zzc155applive', ['zzc155srcdb3', 'zzc155srcweb3'], $liveFps, $liveBody,
    null, [], [], true, true, $err, $facts);
  ok('a merge with start=true writes the new stack', $okLive, $err);
  ok('the reply carries the "up" job it started', isset($facts['job']) && $facts['job'] !== '');
}

/* ========================================================================
 * E. C3 — a two-file source (main + paired override) merges as the pair
 *    it is, per PLAN_155's "Corrections from PLAN_156's dry run": the
 *    refusal is gone, staxx_merge_files() reports the override and never
 *    offers it as a companion, and staxx_merge_stacks() writes the override
 *    its own retirement text over the paired file, same as the main one.
 * ======================================================================== */

mkStack($root, 'zzc155pair2', "services:\n  db:\n    image: mariadb:11\n", [
  'compose.override.yaml' => "services:\n  db:\n    ports:\n      - \"3307:3306\"\n",
]);
mkStack($root, 'zzc155pairweb', "services:\n  web:\n    image: nginx:latest\n");
staxx_scan_stacks_reset();

$err = '';
ok('a two-file source is no longer refused outright', staxx_merge_check_stack('zzc155pair2') === '', $err);

$err = ''; $pairListing = staxx_merge_files('zzc155pair2', $err);
ok('staxx_merge_files() names the paired override by its basename',
   ($pairListing['override'] ?? null) === 'compose.override.yaml', $err);
$pairByPath = [];
foreach (($pairListing['files'] ?? []) as $entry) $pairByPath[$entry['path']] = $entry;
ok('the override itself is never offered as a companion file', !isset($pairByPath['compose.override.yaml']));

$err = '';
$soloListing = staxx_merge_files('zzc155pairweb', $err);
ok('a source with no override reports it as null, not an empty string',
   array_key_exists('override', $soloListing) && $soloListing['override'] === null, $err);

$pair2Fps = [
  'zzc155pair2'   => staxx_stack_fingerprint('zzc155pair2'),
  'zzc155pairweb' => staxx_stack_fingerprint('zzc155pairweb'),
];
// The body a browser would have built after applying the override onto
// zzc155pair2's own main file — the port it published moves inside the
// merged file exactly as C3 describes for a rewired address; here it is
// simply carried through unchanged, since nothing else clashes.
$pair2Body = "# Made by joining zzc155pair2 and zzc155pairweb, ".date('Y-m-d').".\n"
           . "services:\n  db:\n    image: mariadb:11\n    ports:\n      - \"3307:3306\"\n"
           . "  web:\n    image: nginx:latest\n";
$pair2RetiredMain = "services:\n  db:\n    image: mariadb:11\n    profiles: [\"retired\"]\n";
$pair2RetiredOverride = "services:\n  db:\n    ports:\n      - \"3307:3306\"\n    profiles: [\"retired\"]\n";
$pair2RetiredWeb = "services:\n  web:\n    image: nginx:latest\n    profiles: [\"retired\"]\n";

$err = ''; $facts = null;
$okPair2 = staxx_merge_stacks('zzc155pairapp', ['zzc155pair2', 'zzc155pairweb'], $pair2Fps, $pair2Body, null, [],
  ['zzc155pair2' => $pair2RetiredMain, 'zzc155pairweb' => $pair2RetiredWeb], false, false, $err, $facts,
  ['zzc155pair2' => $pair2RetiredOverride]);
ok('a two-file source merges cleanly alongside an ordinary one', $okPair2, $err);
ok('the new stack\'s file carries the override\'s port, exactly as sent',
   strpos((string)@file_get_contents($root.'/zzc155pairapp/compose.yaml'), '3307:3306') !== false);
ok('the main file is retired with the text handed to it',
   @file_get_contents($root.'/zzc155pair2/compose.yaml') === $pair2RetiredMain);
ok('the override is ALSO retired, with the text handed to it in $retiredOverride',
   @file_get_contents($root.'/zzc155pair2/compose.override.yaml') === $pair2RetiredOverride);
ok('the retired override still carries its own port, exactly as sent',
   strpos((string)@file_get_contents($root.'/zzc155pair2/compose.override.yaml'), '3307:3306') !== false);
ok('the override is never copied into the new stack\'s own folder',
   !is_file($root.'/zzc155pairapp/compose.override.yaml'));

/* ========================================================================
 * F. C13 — a folder entry copies only children with no entry of their own
 *    in the same plan; a symlink already recreated by an earlier folder
 *    copy counts as done rather than an EEXIST failure.
 * ======================================================================== */

// F1 — a plan naming a folder AND one child under a different name produces
// exactly one copy of that child, at the renamed path — not the old name
// left behind by the folder copy as well.
mkStack($root, 'zzc155c13a', "services:\n  web:\n    image: nginx:latest\n", [
  'conf/default.conf' => "server { listen 80; }\n",
  'conf/extra.conf'   => "# extra\n",
]);
mkStack($root, 'zzc155c13b', "services:\n  db:\n    image: mariadb:11\n");
staxx_scan_stacks_reset();

$c13aFps  = ['zzc155c13a' => staxx_stack_fingerprint('zzc155c13a'),
             'zzc155c13b' => staxx_stack_fingerprint('zzc155c13b')];
$c13aBody = "services:\n  web:\n    image: nginx:latest\n  db:\n    image: mariadb:11\n";
$c13aFiles = [
  ['from' => 'zzc155c13a', 'path' => 'conf',              'to' => 'conf'],
  ['from' => 'zzc155c13a', 'path' => 'conf/default.conf',  'to' => 'conf/renamed.conf'],
];

$err = ''; $facts = null;
$okC13a = staxx_merge_stacks('zzc155c13app', ['zzc155c13a', 'zzc155c13b'], $c13aFps, $c13aBody,
  null, $c13aFiles, [], false, false, $err, $facts);
ok('a folder entry alongside a renamed child of its own succeeds', $okC13a, $err);
ok('the renamed child lands only at its new name',
   is_file($root.'/zzc155c13app/conf/renamed.conf'));
ok('the renamed child is not ALSO left at its old name by the folder copy',
   !is_file($root.'/zzc155c13app/conf/default.conf'));
ok('a sibling not named on its own still comes through the folder copy',
   is_file($root.'/zzc155c13app/conf/extra.conf'));

// F2 — a plan of files only (no folder entry at all) still recreates the
// whole folder tree, one file at a time.
mkStack($root, 'zzc155c13c', "services:\n  web:\n    image: nginx:latest\n", [
  'conf/default.conf' => "server { listen 80; }\n",
]);
mkStack($root, 'zzc155c13d', "services:\n  db:\n    image: mariadb:11\n");
staxx_scan_stacks_reset();

$c13cFps  = ['zzc155c13c' => staxx_stack_fingerprint('zzc155c13c'),
             'zzc155c13d' => staxx_stack_fingerprint('zzc155c13d')];
$c13cBody = "services:\n  web:\n    image: nginx:latest\n  db:\n    image: mariadb:11\n";
$c13cFiles = [
  ['from' => 'zzc155c13c', 'path' => 'conf/default.conf', 'to' => 'conf/default.conf'],
];

$err = ''; $facts = null;
$okC13c = staxx_merge_stacks('zzc155c13app2', ['zzc155c13c', 'zzc155c13d'], $c13cFps, $c13cBody,
  null, $c13cFiles, [], false, false, $err, $facts);
ok('a files-only plan with no folder entry still succeeds', $okC13c, $err);
ok('the folder is recreated from the file entry alone',
   is_file($root.'/zzc155c13app2/conf/default.conf'));

// F3 — an empty-folder entry (every child left behind, "to": null) produces
// the folder itself rather than nothing at all — a bind-mounted empty
// folder still has to exist in the new stack.
mkStack($root, 'zzc155c13e', "services:\n  web:\n    image: nginx:latest\n", [
  'data/placeholder.txt' => "leave this behind\n",
]);
mkStack($root, 'zzc155c13f', "services:\n  db:\n    image: mariadb:11\n");
staxx_scan_stacks_reset();

$c13eFps  = ['zzc155c13e' => staxx_stack_fingerprint('zzc155c13e'),
             'zzc155c13f' => staxx_stack_fingerprint('zzc155c13f')];
$c13eBody = "services:\n  web:\n    image: nginx:latest\n  db:\n    image: mariadb:11\n";
$c13eFiles = [
  ['from' => 'zzc155c13e', 'path' => 'data',                  'to' => 'data'],
  ['from' => 'zzc155c13e', 'path' => 'data/placeholder.txt',  'to' => null],
];

$err = ''; $facts = null;
$okC13e = staxx_merge_stacks('zzc155c13app3', ['zzc155c13e', 'zzc155c13f'], $c13eFps, $c13eBody,
  null, $c13eFiles, [], false, false, $err, $facts);
ok('a folder entry with its only child left behind succeeds', $okC13e, $err);
ok('the empty folder is still created', is_dir($root.'/zzc155c13app3/data'));
ok('the left-behind child inside it is not carried through by the folder copy',
   !is_file($root.'/zzc155c13app3/data/placeholder.txt'));

// F4 — a symlink named explicitly, INSIDE a folder that is ALSO named
// whole: the exact shape the live bug report hit. The folder copy recreates
// the link first; the explicit symlink entry for the same path must count
// as already done rather than fail on EEXIST.
mkStack($root, 'zzc155c13g', "services:\n  web:\n    image: nginx:latest\n", [
  'conf/default.conf' => "server { listen 80; }\n",
]);
symlink('./default.conf', $root.'/zzc155c13g/conf/current.conf');
mkStack($root, 'zzc155c13h', "services:\n  db:\n    image: mariadb:11\n");
staxx_scan_stacks_reset();

$c13gFps  = ['zzc155c13g' => staxx_stack_fingerprint('zzc155c13g'),
             'zzc155c13h' => staxx_stack_fingerprint('zzc155c13h')];
$c13gBody = "services:\n  web:\n    image: nginx:latest\n  db:\n    image: mariadb:11\n";
$c13gFiles = [
  ['from' => 'zzc155c13g', 'path' => 'conf',              'to' => 'conf'],
  ['from' => 'zzc155c13g', 'path' => 'conf/current.conf', 'to' => 'conf/current.conf'],
];

$err = ''; $facts = null;
$okC13g = staxx_merge_stacks('zzc155c13app4', ['zzc155c13g', 'zzc155c13h'], $c13gFps, $c13gBody,
  null, $c13gFiles, [], false, false, $err, $facts);
ok('a folder entry naming its own symlink again does not fail on EEXIST', $okC13g, $err);
ok('the symlink is still a symlink, not overwritten as a plain file',
   is_link($root.'/zzc155c13app4/conf/current.conf'));
ok('it still carries the same relative target',
   @readlink($root.'/zzc155c13app4/conf/current.conf') === './default.conf');

echo "\n".($fails === 0 ? "All checks passed.\n" : "$fails check(s) FAILED.\n");
exit($fails === 0 ? 0 : 1);
