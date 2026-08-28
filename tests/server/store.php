<?php
/* PLAN_97 Phase 2 — the four functions in Store.php: whether the array has
 * started, the note written into a new store, reading what is already sitting
 * in a candidate folder, and creating the store itself. Checked against the
 * real installed Store.php.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. Needs STORE_ROOT
 * seeded to a scratch value first, so a stray run can never be mistaken for
 * one that inherited Adrian's real store; the caller sets it and puts the
 * config back:
 *
 *     pscp tests/server/store.php root@<box>:/tmp/
 *     plink … '
 *       CFG=/boot/config/plugins/staxx/staxx.cfg
 *       cp $CFG /tmp/cfg.bak
 *       grep -q "^STORE_ROOT=" $CFG \
 *         && sed -i "s#^STORE_ROOT=.*#STORE_ROOT=\"/tmp/zzstore-seed\"#" $CFG \
 *         || echo "STORE_ROOT=\"/tmp/zzstore-seed\"" >> $CFG
 *       php /tmp/store.php; RC=$?
 *       cp /tmp/cfg.bak $CFG
 *       exit $RC
 *     '
 *
 * Prints one line per case and exits non-zero on any failure.
 *
 * staxx_store_inspect() is read-only and every fixture for it lives under
 * /tmp — nothing it is pointed at is ever created, moved or deleted by the
 * function itself, only by this file's own setup and teardown.
 *
 * staxx_store_create() is not: it calls staxx_settings_validate_path(),
 * which refuses anything not under /mnt/ or under the flash config folder —
 * a /tmp path is refused by that rule alone, for the same "lives in memory"
 * reason /mnt itself is. So the cases that actually create a store use a
 * fresh, uniquely-named scratch folder nested INSIDE the real appdata share
 * (/mnt/user/appdata/zzstore-create-test/...), the same reasoning
 * tests/server/storage.php gives for its own pool fixtures — never a new
 * top-level entry under /mnt. Removed on every exit path, including a fatal
 * error. It also calls staxx_settings_save(), which writes the real config
 * file and runs apply_settings — exactly what tests/server/settings.php
 * already does — so this file backs up the config first and restores it
 * via a shutdown function, proving the restore at the end. */

require_once '/usr/local/emhttp/plugins/staxx/include/Store.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

if (staxx_store_root() !== '/tmp/zzstore-seed') {
  echo "FAIL   STORE_ROOT is not seeded to the scratch value (got '".staxx_store_root()."')\n";
  exit(1);
}

/* --------------------------------------------------------------- cleanup -- */

$cfgFile     = STAXX_CFG;
$cfgBackup   = @file_get_contents($cfgFile); // false if the file does not exist yet
$cfgHadFile  = $cfgBackup !== false;
$inspectRoot = '/tmp/zzstoreinspect-'.getmypid();
$createRoot  = '/mnt/user/appdata/zzstore-create-test-'.getmypid();

function staxx_test_restore_store_cfg(): void {
  global $cfgFile, $cfgBackup, $cfgHadFile, $inspectRoot, $createRoot;
  if ($cfgHadFile) file_put_contents($cfgFile, $cfgBackup);
  else @unlink($cfgFile);
  @exec('rm -rf '.escapeshellarg($inspectRoot));
  @exec('rm -rf '.escapeshellarg($createRoot));
}
register_shutdown_function('staxx_test_restore_store_cfg');

@exec('rm -rf '.escapeshellarg($inspectRoot));
@exec('rm -rf '.escapeshellarg($createRoot));
mkdir($inspectRoot, 0755, true);
/* The store is always made one level inside a folder that already exists —
 * staxx_settings_validate_path() refuses a path whose parent is missing, so a
 * fixture that skips this step tests the parent rule rather than the create. */
mkdir($createRoot, 0755, true);

/** Recursive snapshot of a tree: relative path => "size:mtime", so a write
 * staxx_store_inspect() is not supposed to make shows up whether it adds a
 * file, changes one, or merely touches its mtime. */
function staxx_test_snapshot(string $root): array {
  $out = [];
  $stack = [$root];
  while ($stack) {
    $dir = array_pop($stack);
    foreach ((array)@scandir($dir) as $entry) {
      if ($entry === '.' || $entry === '..') continue;
      $path = $dir.'/'.$entry;
      $rel  = substr($path, strlen($root) + 1);
      if (is_dir($path)) { $out[$rel] = 'dir:'.filemtime($path); $stack[] = $path; }
      else $out[$rel] = filesize($path).':'.filemtime($path);
    }
  }
  ksort($out);
  return $out;
}

/* ---------------------------------------------------------- array_started -- */
// No path parameter to hand it a fixture with — the function reads
// /var/local/emhttp/var.ini directly and takes no argument, so the "fails
// safe on an unreadable file" case cannot be exercised without touching that
// real system file, which this suite must never do. What IS provable without
// touching it: the function's answer agrees with a plain read of the same
// file done here, so at least it is asking the right question of the right
// file rather than guessing.
$realVars = @parse_ini_file('/var/local/emhttp/var.ini', false, INI_SCANNER_RAW);
$result   = staxx_array_started();
ok('returns a bool', is_bool($result));
if ($realVars === false) {
  ok('an unreadable var.ini reads as not started', $result === false);
} else {
  $expected = trim((string)($realVars['mdState'] ?? ''), '"') === 'STARTED';
  ok('agrees with a plain read of var.ini\'s own mdState', $result === $expected,
     'mdState='.var_export($realVars['mdState'] ?? null, true));
}

/* -------------------------------------------------------------- the note -- */

$note = staxx_store_note();
ok('the note is not empty', trim($note) !== '');
ok('names the header_menu marker\'s exact flash path',
   strpos($note, '/boot/config/plugins/staxx/header_menu') !== false);
ok('names the takeover_docker_tab marker\'s exact flash path',
   strpos($note, '/boot/config/plugins/staxx/takeover_docker_tab') !== false);
ok('mentions the stacks folder', strpos($note, 'stacks') !== false);
ok('mentions the archives folder', strpos($note, 'archives') !== false);
ok('mentions the config folder', strpos($note, 'config') !== false);

/* ----------------------------------------------------------- inspect() ---- */

// Case: missing — the path does not exist at all.
$missing = $inspectRoot.'/nosuchfolder';
$r = staxx_store_inspect($missing);
ok('a missing path reads as missing', $r['state'] === 'missing');
ok('...with no stacks listed', $r['stacks'] === []);

// Case: empty — exists, holds nothing.
$empty = $inspectRoot.'/empty';
mkdir($empty, 0755, true);
$r = staxx_store_inspect($empty);
ok('a bare empty folder reads as empty', $r['state'] === 'empty');

// Trap 1: a stacks folder sitting next to an archives folder is ours even
// when nothing inside stacks/ has its own hidden record yet. Built with one
// real project folder under stacks/ (see the note below the assertion) —
// without it $found stays empty and the fallback branch takes over instead,
// which is a separate case covered further down.
$storeShape = $inspectRoot.'/storeshape';
mkdir($storeShape.'/stacks/projA', 0755, true);
mkdir($storeShape.'/archives', 0755, true);
file_put_contents($storeShape.'/stacks/projA/compose.yaml', "services:\n  a:\n    image: alpine:3.20\n");
$r = staxx_store_inspect($storeShape);
ok('stacks beside archives is ours, even with no hidden records yet', $r['state'] === 'staxx');
ok('...and lists the project it found', in_array('projA', $r['stacks'], true));

/* The shape staxx_store_create() itself leaves behind: the three folders
 * made, and nothing in any of them yet. Reopening the dialog on a store
 * chosen a moment ago has to recognise it as ours — reading it as somebody
 * else's folder is how a person gets warned off their own store. The pair of
 * folders is what identifies it; holding no stacks yet says nothing about
 * whose it is. */
$freshStore = $inspectRoot.'/freshstore';
mkdir($freshStore.'/stacks', 0755, true);
mkdir($freshStore.'/archives', 0755, true);
mkdir($freshStore.'/config', 0755, true);
$r = staxx_store_inspect($freshStore);
ok('a store with nothing in it yet is still ours', $r['state'] === 'staxx', 'got '.$r['state']);
ok('...and says so rather than listing stacks it does not have', $r['stacks'] === []);

// The storeless shape: no archives folder at all, but a project sitting
// directly in the folder carries its own hidden record.
$recordShape = $inspectRoot.'/recordshape';
mkdir($recordShape.'/projB/'.STAXX_RECORD_DIR, 0755, true);
file_put_contents($recordShape.'/projB/compose.yaml', "services:\n  a:\n    image: alpine:3.20\n");
$r = staxx_store_inspect($recordShape);
ok('a hidden record with no archives folder is still ours', $r['state'] === 'staxx');
ok('...and lists the project', in_array('projB', $r['stacks'], true));

// Trap 2: a bare pile of compose files, with no hidden record and no
// archives folder, must never be mistaken for a StaXX store.
$compose = $inspectRoot.'/compose';
mkdir($compose.'/projC', 0755, true);
file_put_contents($compose.'/projC/docker-compose.yml', "services:\n  a:\n    image: alpine:3.20\n");
$r = staxx_store_inspect($compose);
ok('a bare pile of compose files is "compose", not "staxx"', $r['state'] === 'compose');
ok('...and lists the project', in_array('projC', $r['stacks'], true));

// Case: other — something is here, but it is neither shape.
$other = $inspectRoot.'/other';
mkdir($other, 0755, true);
file_put_contents($other.'/readme.txt', 'just a file, nothing StaXX would recognise');
$r = staxx_store_inspect($other);
ok('an unrelated folder reads as other', $r['state'] === 'other');

// The listing cap: more projects than STAXX_STORE_INSPECT_CAP must still be
// found, but only the first cap's worth are named.
$capped = $inspectRoot.'/capped';
$total  = STAXX_STORE_INSPECT_CAP + 5;
for ($i = 0; $i < $total; $i++) {
  $d = $capped.'/proj'.$i;
  mkdir($d, 0755, true);
  file_put_contents($d.'/compose.yaml', "services:\n  a:\n    image: alpine:3.20\n");
}
$r = staxx_store_inspect($capped);
ok('the cap limits how many stack names are listed',
   count($r['stacks']) === STAXX_STORE_INSPECT_CAP,
   'got '.count($r['stacks']));
ok('the detail says how many more there are', strpos($r['detail'], '5 more') !== false, $r['detail']);

// staxx_store_inspect() must write nothing. Proven against the shape with
// the most going on (storeShape), by snapshotting the whole fixture tree
// before and after, including every file's mtime.
$before = staxx_test_snapshot($inspectRoot);
staxx_store_inspect($storeShape);
staxx_store_inspect($compose);
staxx_store_inspect($recordShape);
$after = staxx_test_snapshot($inspectRoot);
ok('inspect() wrote nothing anywhere in the fixture tree', $before === $after);

/* ------------------------------------------------------------ create() ---- */

// The memory-filesystem refusal, going through staxx_settings_validate_path()
// rather than around it — the same path shape tests/server/settings.php
// already proves is refused for landing on a filesystem that lives in memory.
// One level directly under /mnt, not a deeper path: the validator checks
// "does the parent exist" before it checks "does this live in memory", and
// dirname() of a two-level path under a share that was never created would
// trip the first refusal instead — /mnt itself always exists, so a bare
// name directly under it reaches the memory check as intended.
$memPath = '/mnt/zzstore-notashare-'.getmypid();
$err = '';
$okMem = staxx_store_create($memPath, $err);
ok('refuses a store that would live in memory', !$okMem && $err !== '', $err);
ok('...and says so', strpos($err, 'lives in memory') !== false, $err);
ok('nothing was created', !is_dir($memPath));

// A placement refusal: straight onto a single array disk, which has no
// redundancy of its own.
$diskPath = '/mnt/disk3/zzstore-create-'.getmypid();
$err = '';
$okDisk = staxx_store_create($diskPath, $err);
ok('refuses a store placed straight on an array disk', !$okDisk && $err !== '', $err);
ok('nothing was created on the disk path', !is_dir($diskPath));

// The successful create: all three folders, the note, and the setting saved.
$goodPath = $createRoot.'/store';
$err = '';
$okGood = staxx_store_create($goodPath, $err);
ok('creates a store at a valid location', $okGood, $err);
ok('the stacks folder exists', is_dir($goodPath.'/stacks'));
ok('the archives folder exists', is_dir($goodPath.'/archives'));
ok('the config folder exists', is_dir($goodPath.'/config'));
$notePath = $goodPath.'/config/README.txt';
ok('the note was written', is_file($notePath));
$noteText = (string)@file_get_contents($notePath);
ok('the note names both flash marker paths',
   strpos($noteText, '/boot/config/plugins/staxx/header_menu') !== false
   && strpos($noteText, '/boot/config/plugins/staxx/takeover_docker_tab') !== false);

// staxx_cfg() memoises in a per-request static (see tests/server/settings.php's
// own comment on this), so staxx_store_root() in THIS process still reports
// the seeded scratch value after the save above — reading the config file
// straight off disk is the only honest way to prove the write landed.
$afterCfg = @parse_ini_file($cfgFile) ?: [];
ok('STORE_ROOT was actually saved to the config file',
   ($afterCfg['STORE_ROOT'] ?? '') === $goodPath);

// Adopting an existing store: running create() again over the same folder
// must disturb nothing already in it. A marker file stands in for a stack
// that has since been added, and the note's own mtime stands in for "was
// this rewritten for no reason" — staxx_store_create() only rewrites it when
// the text has actually changed.
$markerPath = $goodPath.'/stacks/zzalreadyhere/compose.yaml';
mkdir(dirname($markerPath), 0755, true);
file_put_contents($markerPath, "services:\n  a:\n    image: alpine:3.20\n");
$beforeAdopt = staxx_test_snapshot($goodPath);

$err = '';
$okAdopt = staxx_store_create($goodPath, $err);
ok('adopting an already-created store succeeds', $okAdopt, $err);

$afterAdopt = staxx_test_snapshot($goodPath);
ok('the second run disturbed nothing already in the store', $beforeAdopt === $afterAdopt);

staxx_test_restore_store_cfg();

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
