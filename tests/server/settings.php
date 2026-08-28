<?php
/* The settings allowlist, validator and atomic writer, checked against the
 * real installed Settings.php.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine:
 *
 *     pscp tests/server/settings.php root@<box>:/tmp/
 *     plink … "php /tmp/settings.php"
 *
 * Prints one line per case and exits non-zero on any failure.
 *
 * THIS TEST WRITES TO THE REAL CONFIG. Adrian runs this on his production
 * server, and PLAN_97 Phase 4 split that config in two: the flash pointer
 * file (/boot/config/plugins/staxx/staxx.cfg, holding only STORE_ROOT and
 * the two menu keys) and, wherever this box's own STORE_ROOT already points,
 * the data store's own settings file. The writer tests back up BOTH before
 * touching either and restore both afterwards — including on a failing
 * exit, via a shutdown function, so a crash partway through cannot leave
 * his settings changed. The three-child section further down additionally
 * proves how staxx_cfg() layers a flash file, a store file and the shipped
 * defaults together, using scratch flash-file states of its own rather than
 * this box's real one, since staxx_cfg() memoises for the life of a process
 * and a genuinely fresh state needs a genuinely fresh one. */

require_once '/usr/local/emhttp/plugins/staxx/include/Settings.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

/* ------------------------------------------------------------- keys ---- */

$keys = staxx_settings_keys();
// Named rather than counted. A count has to be edited every time a setting is
// added, which means it fails for the one reason that is never interesting
// while proving nothing about WHICH keys are there — this list is the thing
// worth asserting, and a key disappearing from it is a real regression.
foreach (['HEADER_MENU', 'TAKEOVER_DOCKER_TAB', 'STORE_ROOT',
          'ICON_FETCH', 'IMAGE_LOOKUP', 'SHELL_ENABLED',
          'SHELL_WARNED', 'HUB_USER', 'HUB_TOKEN', 'UPDATE_CHECK',
          'UPDATE_CHECK_TIME', 'UPDATE_MODE', 'UPDATE_DELAY_HOURS',
          'UPDATE_WINDOW', 'UPDATE_WINDOW_START', 'UPDATE_WINDOW_END',
          'UPDATE_NOTIFY', 'UPDATE_RETAIN', 'UPDATE_CLEANUP'] as $k) {
  ok('has '.$k, array_key_exists($k, $keys));
}

// The invariant that matters about read(): exactly the allowlist, no more and
// no fewer. A key the panel can see but the allowlist does not know about
// would be one nothing validates on the way back in.
$read = staxx_settings_read();
ok('read returns exactly the allowlisted keys',
   array_keys($read) === array_keys($keys),
   implode(',', array_diff(array_keys($read), array_keys($keys)))
   . '|' . implode(',', array_diff(array_keys($keys), array_keys($read))));

/* ------------------------------------------------- the derived folders ---- */
// staxx_stack_root() and staxx_archive_root() no longer read a setting of
// their own — they derive from STORE_ROOT, read once for the whole box. What
// this box actually has right now decides which of the two shapes gets
// proved: blank means neither folder is knowable yet, and both must say so
// by returning '' rather than guessing at a flash-drive default the way the
// old fallback did; chosen means both derive cleanly underneath it.
if (staxx_store_ready()) {
  $store = staxx_store_root();
  ok('a chosen store is not blank', $store !== '');
  ok('the stacks folder derives as <store>/stacks', staxx_stack_root() === $store.'/stacks');
  ok('the archive folder derives as <store>/archives', staxx_archive_root() === $store.'/archives');
} else {
  ok('an unchosen store leaves the stacks folder blank', staxx_stack_root() === '');
  ok('an unchosen store leaves the archive folder blank', staxx_archive_root() === '');
}

/* --------------------------------------------------------- validator ---- */

// Validated directly, with no write involved — staxx_settings_validate()
// and staxx_settings_validate_path() are pure functions.

$err = '';
$badRoots = ['', '..', 'relative/path', '/', '/etc', '/mnt/../etc'];
foreach ($badRoots as $bad) {
  $v = staxx_settings_validate('STORE_ROOT', $keys['STORE_ROOT'], $bad, $err);
  ok('rejects STORE_ROOT '.var_export($bad, true), $v === '' && $err !== '', $err);
}

$err = '';
$v = staxx_settings_validate('STORE_ROOT', $keys['STORE_ROOT'], '/mnt/user"quote', $err);
ok('rejects a STORE_ROOT containing a quote', $v === '' && $err !== '', $err);

$err = '';
$v = staxx_settings_validate('HEADER_MENU', $keys['HEADER_MENU'], 'yes', $err);
ok('rejects HEADER_MENU "yes"', $v === '' && $err !== '', $err);

$err = '';
$v = staxx_settings_validate('ICON_FETCH', $keys['ICON_FETCH'], '', $err);
ok('rejects ICON_FETCH ""', $v === '' && $err !== '', $err);

// A good root under a real share whose parent exists. Deliberately NOT
// directly under /mnt any more, which this case used to use: /mnt is a tmpfs
// holding nothing but mount points, so a folder made there is gone at the
// next reboot, and the validator now refuses it — see the cases below.
$err  = '';
$good = '/mnt/user/appdata/zzb1-settings-test-'.getmypid();
$v    = staxx_settings_validate('STORE_ROOT', $keys['STORE_ROOT'], $good, $err);
ok('accepts a STORE_ROOT under a real share whose parent exists', $v === $good, $err);

// The refusal staxx_path_in_memory() exists for. Such a path looks entirely
// normal and is writable right now, which is exactly why nothing else in the
// validator can catch it: the loss happens at the next reboot.
$err = '';
$v = staxx_settings_validate('STORE_ROOT', $keys['STORE_ROOT'], '/mnt/zzb1-notashare', $err);
ok('refuses a STORE_ROOT that would land on a filesystem living in memory',
   $v === '' && strpos($err, 'lives in memory') !== false, $err);

ok('/mnt itself is reported as living in memory', staxx_path_in_memory('/mnt'));
ok('a real user share is not', !staxx_path_in_memory('/mnt/user'));
// The case a plain "is anything mounted here?" test gets wrong: /mnt/disks
// IS a mount, and is still a one-megabyte tmpfs holding mount points for
// drives that come and go. SKIPped where Unassigned Devices is not installed.
if (is_dir('/mnt/disks')) {
  ok('a tmpfs that exists only to hold mount points is not a home for stacks',
     staxx_path_in_memory('/mnt/disks'));
} else {
  echo "SKIP   /mnt/disks case — Unassigned Devices is not installed on this box\n";
}

// One key now, so the old two-key overlap ("ARCHIVE_ROOT cannot be the
// stacks folder" and its mirror) is gone. What replaces it: a PROPOSED new
// store must not be, or sit inside, the CURRENT store's own stacks or
// archives folder — nesting it there is how an archive zip gets read back
// as a stack, or a stack folder gets read back as an archive.
//
// Proven against whatever this box's real current store already is —
// staxx_cfg() memoises on first read (staxx_settings_read() above already
// triggered it), so a save made from inside this same process can never
// change what staxx_store_root() reports for the rest of the run; the only
// honest "current store" to test against is the one this process started
// with. staxx_store_ready() is the guard: on a fresh box where STORE_ROOT
// ships blank and nothing has chosen one yet, there is no current store to
// collide with, and these cases are skipped rather than faked.
if (staxx_store_ready()) {
  $err = '';
  $v = staxx_settings_validate_path('STORE_ROOT', staxx_stack_root(), $err);
  ok('rejects a new store that IS the current stacks folder', $v === '' && $err !== '', $err);

  $err = '';
  $v = staxx_settings_validate_path('STORE_ROOT', staxx_stack_root().'/nested', $err);
  ok('rejects a new store nested inside the current stacks folder', $v === '' && $err !== '', $err);

  $err = '';
  $v = staxx_settings_validate_path('STORE_ROOT', staxx_archive_root(), $err);
  ok('rejects a new store that IS the current archive folder', $v === '' && $err !== '', $err);

  $err = '';
  $v = staxx_settings_validate_path('STORE_ROOT', staxx_archive_root().'/nested', $err);
  ok('rejects a new store nested inside the current archive folder', $v === '' && $err !== '', $err);

  $err  = '';
  $good = '/mnt/user/appdata/zzb1-settings-new-store-'.getmypid();
  $v = staxx_settings_validate_path('STORE_ROOT', $good, $err);
  ok('accepts a new store that sits beside the current one, not inside it', $v === $good, $err);
} else {
  echo "SKIP   the current-store overlap cases — no store is chosen on this box yet\n";

  // With no store chosen at all there is nothing to collide with, so the
  // refusal must not fire — an otherwise sensible path is accepted outright.
  $err  = '';
  $good = '/mnt/user/appdata/zzb1-settings-unchosen-'.getmypid();
  $v = staxx_settings_validate_path('STORE_ROOT', $good, $err);
  ok('accepts any sensible path when no store is chosen yet — nothing to overlap', $v === $good, $err);
}

foreach ($keys as $k => $spec) {
  if ($spec['type'] !== 'choice') continue;
  foreach ($spec['choices'] as $choice) {
    $err = '';
    $v = staxx_settings_validate($k, $spec, $choice, $err);
    ok('accepts '.$k.'='.$choice, $v === $choice, $err);
  }
}

$err = '';
$v = staxx_settings_validate('UPDATE_CHECK', $keys['UPDATE_CHECK'], 'hourly', $err);
ok('rejects UPDATE_CHECK "hourly"', $v === '' && $err !== '', $err);
$err = '';
$v = staxx_settings_validate('UPDATE_CHECK', $keys['UPDATE_CHECK'], '', $err);
ok('rejects UPDATE_CHECK ""', $v === '' && $err !== '', $err);

// UPDATE_CHECK_TIME — weighted towards refusals, since the shape (HH:MM,
// leading zero, 24-hour) has far more ways to be wrong than right.
$goodTimes = ['04:00', '23:59'];
foreach ($goodTimes as $good) {
  $err = '';
  $v = staxx_settings_validate('UPDATE_CHECK_TIME', $keys['UPDATE_CHECK_TIME'], $good, $err);
  ok('accepts UPDATE_CHECK_TIME '.var_export($good, true), $v === $good, $err);
}
$badTimes = ['4:00', '24:00', '04:60', '0400', '04:00 ', '', '04:00; rm -rf /'];
foreach ($badTimes as $bad) {
  $err = '';
  $v = staxx_settings_validate('UPDATE_CHECK_TIME', $keys['UPDATE_CHECK_TIME'], $bad, $err);
  ok('rejects UPDATE_CHECK_TIME '.var_export($bad, true), $v === '' && $err !== '', $err);
}

/* --------------------------------------------------- writer, guarded ---- */

$cfgFile = STAXX_CFG;
$backup  = @file_get_contents($cfgFile); // false if the file does not exist yet
$hadFile = $backup !== false;

// PLAN_97 Phase 4 split the config in two: a save can now also land in the
// STORE's own settings file, wherever this box's real STORE_ROOT already
// points. Backed up and restored exactly like the flash file, and by the
// same shutdown function, so a case further down that posts a non-flash key
// (ICON_FETCH, still used below for the reload check) cannot leave a real
// setting changed on a box that already has a store — whether or not it
// actually gets reached depends on this box's live state, which this suite
// does not get to choose, so covering it here rather than assuming it away
// is the only safe option. '' when no store is chosen: nothing to back up.
$storeFile    = staxx_settings_file();
$storeBackup  = $storeFile !== '' ? @file_get_contents($storeFile) : false;
$storeHadFile = $storeBackup !== false;

function staxx_test_restore_cfg(): void {
  global $cfgFile, $backup, $hadFile, $storeFile, $storeBackup, $storeHadFile;
  if ($hadFile) file_put_contents($cfgFile, $backup);
  else @unlink($cfgFile);
  if ($storeFile !== '') {
    if ($storeHadFile) file_put_contents($storeFile, $storeBackup);
    else @unlink($storeFile);
  }
}
register_shutdown_function('staxx_test_restore_cfg');

// A marker key untouched by the allowlist, so its survival proves unknown
// keys are preserved rather than dropped. Posted alongside a FLASH key
// (TAKEOVER_DOCKER_TAB) rather than a store one: this case has to succeed
// whether or not this box currently has a reachable store, and a store key
// would be refused outright on a box where the store is not reachable right
// now — see the "unreachable, refused" case further down for that behaviour
// proven on purpose instead.
$marker = 'ZZB1TEST_UNKNOWN_KEY="keep-me"';
$seed   = ($hadFile ? rtrim((string)$backup, "\n")."\n" : '').$marker."\n";
file_put_contents($cfgFile, $seed);

$curTakeover  = trim((string)(@parse_ini_file($cfgFile)['TAKEOVER_DOCKER_TAB'] ?? 'false'));
$flipTakeover = $curTakeover === 'true' ? 'false' : 'true';

$err = ''; $reload = null; $saved = null;
$posted = [
  'action'              => 'settings-save', // not an allowlisted key — must be ignored, not error
  'TAKEOVER_DOCKER_TAB' => $flipTakeover,
];
$okSave = staxx_settings_save($posted, $err, $reload, $saved);
ok('save with an unknown posted key succeeds', $okSave, $err);
ok('unknown posted key is not in the saved map', $okSave && !array_key_exists('action', (array)$saved));

$after = @parse_ini_file($cfgFile) ?: [];
ok('the unknown cfg key survives the write', ($after['ZZB1TEST_UNKNOWN_KEY'] ?? '') === 'keep-me');
ok('the submitted key was actually written', ($after['TAKEOVER_DOCKER_TAB'] ?? '') === $flipTakeover);

$tmpGlob = glob($cfgFile.'.tmp-*');
ok('no temp file left behind', $tmpGlob === [] || $tmpGlob === false, implode(',', (array)$tmpGlob));
ok('the cfg still parses', is_array(@parse_ini_file($cfgFile)));

// A refused value must leave the file exactly as the last good write left it.
$before = file_get_contents($cfgFile);
$err = ''; $reload = null; $saved = null;
$okBad = staxx_settings_save(['HEADER_MENU' => 'yes'], $err, $reload, $saved);
ok('save refuses an invalid choice', !$okBad, $err);
ok('file is untouched by a refused save', file_get_contents($cfgFile) === $before);
$tmpGlob = glob($cfgFile.'.tmp-*');
ok('no temp file left behind after a refusal', $tmpGlob === [] || $tmpGlob === false);

// One key now, so the old "two paths posted together must be checked against
// each other's NEW value" case is gone — there is only ever one path in a
// single save, and it is checked against the on-disk current store, proven
// above. What is still worth proving here is that a genuine save to a fresh,
// non-overlapping location actually succeeds and reaches the file.
$pairOkStore = '/mnt/user/appdata/zzb1-settings-save-store-'.getmypid();
$err = ''; $reload = null; $saved = null;
$okStore = staxx_settings_save(['STORE_ROOT' => $pairOkStore], $err, $reload, $saved);
ok('save accepts a fresh, non-overlapping data store', $okStore, $err);
ok('...and the new value comes back in $saved', ($saved['STORE_ROOT'] ?? '') === $pairOkStore);
// STORE_ROOT is one of the three page-affecting keys (see staxx_settings_save()'s
// own comment) — changing it must ask for a reload, the same as HEADER_MENU
// or TAKEOVER_DOCKER_TAB.
ok('changing STORE_ROOT asks for a reload', $reload === true, $err);

// Cleared on the way out rather than here. The accepted save above leaves
// STORE_ROOT naming a throwaway path for the rest of this process — staxx_cfg()
// memoises, so it cannot be put back mid-run — and the folder gets created on
// demand by whatever asks for it next. Removing it inline just means it comes
// straight back.
//
// rmdir() alone was not enough and left folders behind on a real box: whatever
// next asks for the store makes its stacks and archives folders inside it, and
// rmdir refuses a folder that is not empty — so the scratch path survived every
// run, in Adrian's real appdata share. Removed whole instead, exactly the way
// store.php and relocate.php remove theirs, and only ever this one built path.
register_shutdown_function(function () use ($pairOkStore) {
  @exec('rm -rf '.escapeshellarg($pairOkStore));
});

// Worth knowing when reading a failure here: staxx_cfg() caches the parsed
// config in a per-request static, and this whole file is one request. So the
// "before" values staxx_settings_save() compares against are the ones that
// were on disk when the very first read above ran, not the ones the previous
// save just wrote. The reload checks below still hold — they turn on whether a
// page-affecting key was submitted at all — but do not add a case here that
// depends on reading back what an earlier save in this same run wrote.

// A change to one of the three page-affecting keys must ask for a reload;
// one that doesn't must not.
$err = ''; $reload = null; $saved = null;
staxx_settings_save(['ICON_FETCH' => 'true'], $err, $reload, $saved);
ok('a non-page-affecting change does not ask for a reload', $reload === false, $err);

// TAKEOVER_DOCKER_TAB rather than HEADER_MENU deliberately: apply_settings
// (as it stands before Step 4) reads HEADER_MENU and would flip the marker
// that decides where StaXX's own page appears, live, on the box this
// runs on. TAKEOVER_DOCKER_TAB is a no-op for apply_settings until Step 4
// builds the shadow-page mechanism, so toggling it here has no live effect —
// but it is still one of the three keys staxx_settings_save() treats as
// page-affecting, so it proves the same reload logic just as well.
// Flipped against $curTakeover, the value read before ANY save in this run —
// not against $after, which is what the file holds now. This is the very trap
// the note above describes: staxx_settings_save() decides "did this change?"
// against the memoised original, so flipping relative to the current file
// hands it back the value it already believes is in force, and it correctly
// reports no change. Reading that as a broken reload flag would have been
// exactly the wrong conclusion.
$err = ''; $reload = null; $saved = null;
staxx_settings_save(['TAKEOVER_DOCKER_TAB' => $flipTakeover], $err, $reload, $saved);
ok('changing TAKEOVER_DOCKER_TAB asks for a reload', $reload === true, $err);
// Put it back so the restore below has less to undo, though the shutdown
// function restores the original file regardless.
staxx_settings_save(['TAKEOVER_DOCKER_TAB' => $curTakeover], $err, $reload, $saved);

/* ------------------------------------ where the data store may NOT live ---
 * The data store is allowed on a pool or inside a share, and nowhere else
 * under /mnt. Refused in the validator rather than only discouraged in the
 * chooser, because every saved value comes through here whatever route it
 * took. One key now, so this rule applies unconditionally — there is no
 * second, laxer key (the old ARCHIVE_ROOT) that used to skip it.
 *
 * Read-only cases: each calls the validator directly and writes nothing, so
 * none of these touch the real config. */

$refused = [
  '/mnt/disk3/stacks'        => 'a single array disk',
  '/mnt/disk12/stacks'       => 'a two-digit array disk',
  '/mnt/user0/stacks'        => 'the array behind the share layer',
  '/mnt/disks/mydrive/x'     => 'an unassigned drive',
  '/mnt/remotes/nas_share/x' => 'a network mount',
];
foreach ($refused as $path => $what) {
  $err = '';
  $got = staxx_settings_validate_path('STORE_ROOT', $path, $err);
  ok('the data store is refused on '.$what.' ('.$path.')',
     $got === '' && $err !== '', $err);
  // A refusal has to say where to go instead, not just "no".
  ok('...and the refusal names somewhere to put it instead',
     strpos($err, 'share') !== false || strpos($err, 'pool') !== false, $err);
}

// The paths that must keep working, so the refusals above cannot have been
// written too broadly. Deliberately NOT this box's real current stack root —
// that now trips the overlap refusal proven earlier instead, for an entirely
// different reason, and would make this case mean the wrong thing.
foreach (['/mnt/user/appdata/staxx'] as $good) {
  $err = '';
  ok('still accepted: '.$good,
     staxx_settings_validate_path('STORE_ROOT', $good, $err) !== '', $err);
}

/* The near misses. "disk" with no number is a share name; a share called
 * "disks-archive" is somebody's real share. Catching either would refuse a
 * perfectly good location, and a regex written the obvious way does exactly
 * that — which is why these are here rather than trusted. Asserted as "not
 * refused for THIS reason", since a fictional share can fail the older
 * parent-must-exist check on any given box. */
foreach (['/mnt/user/disk/x', '/mnt/user/disks-archive/x', '/mnt/user/remotes-old/x'] as $good) {
  $err = '';
  staxx_settings_validate_path('STORE_ROOT', $good, $err);
  ok('a share whose name merely starts like a disk is not refused for it: '.$good,
     strpos($err, 'no redundancy of its own') === false
     && strpos($err, 'missing at the next boot') === false, $err);
}

/* ---------------------------- PLAN_97 Phase 4 — the flash/store split ---
 * staxx_cfg() memoises for the life of one process, and this script is
 * already several hundred lines into being one — its own STORE_ROOT was
 * cached the moment staxx_settings_keys()/read() first ran, at the very top
 * of this file. Proving how three DIFFERENT flash-file states get layered
 * needs three genuinely fresh processes, the same as three separate page
 * loads would give for free. staxx_test_child() gives each one its own
 * flash file, runs a small throwaway script in a real child `php` process,
 * and puts the flash file straight back to whatever this process had it as
 * a moment before — so nothing here can leak into the read-only tests still
 * to come, or survive past this section at all. */

function staxx_test_child(string $flashIni, string $php): array {
  global $cfgFile;
  $prior    = @file_get_contents($cfgFile);
  $hadPrior = $prior !== false;
  file_put_contents($cfgFile, $flashIni);

  $tmp  = tempnam(sys_get_temp_dir(), 'zzb1settingschild');
  file_put_contents($tmp, "<?php\n".$php);
  $code = 1;
  $out  = staxx_sh('php '.escapeshellarg($tmp), 15, $code);
  @unlink($tmp);

  if ($hadPrior) file_put_contents($cfgFile, $prior);
  else @unlink($cfgFile);

  return [$out, $code];
}

// Child 1 — a store that is chosen AND reachable: the layering order, the
// flash file's filter to just the three keys, and (since this is the one
// state where a non-flash key can actually be saved) the positive save
// split and the STORE_ROOT-plus-another-key refusal, all in the one process
// so the split save's own result is what the refusal case is checked
// against.
$storeDir1 = '/tmp/zzb1-settings-child1-'.getmypid();
@exec('rm -rf '.escapeshellarg($storeDir1));
mkdir($storeDir1.'/config', 0755, true);
file_put_contents($storeDir1.'/config/staxx.cfg',
  'ICON_FETCH="false"'."\n"
  .'UPDATE_CHECK="weekly"'."\n"
  .'TAKEOVER_DOCKER_TAB="storeval"'."\n");
register_shutdown_function(function () use ($storeDir1) {
  @exec('rm -rf '.escapeshellarg($storeDir1));
});

$flashIni1 = 'STORE_ROOT="'.$storeDir1.'"'."\n"
           . 'HEADER_MENU="true"'."\n"
           // A non-flash key, sitting in the flash file with a value that
           // differs from both the shipped default and the store's own —
           // it must be ignored outright, not merely lose a tie-break.
           . 'ICON_FETCH="bogus-should-be-ignored"'."\n";

$child1 = <<<'EOT'
require '/usr/local/emhttp/plugins/staxx/include/Defines.php';
$fails = 0;
function check($cond, $label) { global $fails; if (!$cond) { echo "FAIL child: $label\n"; $fails++; } }

$c = staxx_cfg();
check(($c['HEADER_MENU'] ?? null) === 'true', 'a flash key overrides both the store and the default');
check(($c['ICON_FETCH'] ?? null) === 'false', 'the store value wins; the stray flash-file entry for a non-flash key is ignored entirely');
check(($c['UPDATE_CHECK'] ?? null) === 'weekly', 'the store overrides the shipped default when the flash file says nothing');
check(($c['TAKEOVER_DOCKER_TAB'] ?? null) === 'storeval', 'the store value is used for a flash key too, when the flash file itself has no opinion');
check(($c['CATCH_INSTALLS'] ?? null) === 'true', 'the shipped default flows through untouched when nothing overrides it');
check(staxx_store_reachable() === true, 'a present, readable store reads as reachable');
check(staxx_settings_degraded() === false, 'a reachable store is never reported as degraded');

require '/usr/local/emhttp/plugins/staxx/include/Settings.php';

// The positive split: a flash key and a non-flash key saved in the same
// call must land in the two different files, each holding only its own kind.
$err = ''; $reload = null; $saved = null;
$ok = staxx_settings_save(['HEADER_MENU' => 'false', 'ICON_FETCH' => 'true'], $err, $reload, $saved);
check($ok, 'a save mixing a flash and a non-flash key succeeds while the store is reachable: '.$err);

$flashText = (string)@file_get_contents(STAXX_CFG);
$storeText = (string)@file_get_contents(staxx_settings_file());
check(strpos($flashText, 'HEADER_MENU="false"') !== false, 'the flash key landed in the flash file');
// The flash file already held a stray ICON_FETCH line from the fixture above
// (planted to prove it gets IGNORED on read) — cfg_write_keys() rightly
// leaves keys it does not know about alone, so its mere presence proves
// nothing here. What proves the split is that the NEW value never reached
// it: the save routed ICON_FETCH to the store file, not this one.
check(strpos($flashText, 'ICON_FETCH="true"') === false, 'the save did not write the non-flash key into the flash file');
check(strpos($storeText, 'ICON_FETCH="true"') !== false, 'the non-flash key landed in the store file');
check(strpos($storeText, 'HEADER_MENU') === false, 'the flash key did NOT land in the store file');

// Moving the store in the same save as another setting is refused, and
// nothing at all is written — checked against the two files exactly as the
// split save above left them.
$flashBefore = $flashText;
$storeBefore = $storeText;
$err = ''; $reload = null; $saved = null;
$newStore = '/mnt/user/appdata/zzb1-settings-storepair-'.getmypid();
$ok2 = staxx_settings_save(['STORE_ROOT' => $newStore, 'ICON_FETCH' => 'false'], $err, $reload, $saved);
check(!$ok2 && $err !== '', 'moving STORE_ROOT alongside another key is refused: '.$err);
check(strpos($err, 'moved') !== false, 'the refusal explains it is the combination that is the problem');
check((string)@file_get_contents(STAXX_CFG) === $flashBefore, 'the flash file is untouched by the refused save');
check((string)@file_get_contents(staxx_settings_file()) === $storeBefore, 'the store file is untouched by the refused save');

exit($fails ? 1 : 0);
EOT;

[$out1, $code1] = staxx_test_child($flashIni1, $child1);
if (trim($out1) !== '') echo $out1;
ok('Phase 4 child 1 (reachable store: layering, filtering, save split, store-move refusal)', $code1 === 0);

// Child 2 — a store that is chosen but NOT reachable (the folder was never
// created): the degraded state, the refusal with nothing written, the
// unchanged repost that must NOT be refused, and the emergency route.
$storeDir2 = '/tmp/zzb1-settings-child2-'.getmypid(); // deliberately never created
$flashIni2 = 'STORE_ROOT="'.$storeDir2.'"'."\n"
           . 'HEADER_MENU="false"'."\n"
           . 'TAKEOVER_DOCKER_TAB="false"'."\n";

$child2 = <<<'EOT'
require '/usr/local/emhttp/plugins/staxx/include/Defines.php';
$fails = 0;
function check($cond, $label) { global $fails; if (!$cond) { echo "FAIL child: $label\n"; $fails++; } }

check(staxx_store_ready() === true, 'a non-blank STORE_ROOT still reads as chosen even though it cannot be reached');
check(staxx_store_reachable() === false, 'a chosen but missing store reads as not reachable');
check(staxx_settings_degraded() === true, 'chosen-but-missing is the one degraded state');

require '/usr/local/emhttp/plugins/staxx/include/Settings.php';

$flashBefore = (string)@file_get_contents(STAXX_CFG);
$before = staxx_settings_read();
check(($before['ICON_FETCH'] ?? null) === 'true', 'a degraded read falls back to the shipped default');

// A non-flash key that actually changes value has nowhere to go and is
// refused, writing nothing at all.
$err = ''; $reload = null; $saved = null;
$ok = staxx_settings_save(['ICON_FETCH' => 'false'], $err, $reload, $saved);
check(!$ok && $err !== '', 'a changed non-flash key is refused while the store cannot be reached: '.$err);
check((string)@file_get_contents(STAXX_CFG) === $flashBefore, 'the flash file is untouched by the refusal');
check(!is_dir(staxx_config_root()), 'nothing was created for the store either');

// The very same key, reposted UNCHANGED, is not a save attempt at all —
// this is what lets a settings page that posts every field on every save
// still use the emergency route below.
$err = ''; $reload = null; $saved = null;
$ok2 = staxx_settings_save(['ICON_FETCH' => 'true'], $err, $reload, $saved);
check($ok2 && $err === '', 'reposting an unchanged non-flash key is not refused: '.$err);

// The emergency route itself: a flash key still saves.
$err = ''; $reload = null; $saved = null;
$ok3 = staxx_settings_save(['HEADER_MENU' => 'true'], $err, $reload, $saved);
check($ok3, 'a flash key still saves while the store cannot be reached: '.$err);
check(strpos((string)@file_get_contents(STAXX_CFG), 'HEADER_MENU="true"') !== false,
  'the flash key change actually reached the flash file');

exit($fails ? 1 : 0);
EOT;

[$out2, $code2] = staxx_test_child($flashIni2, $child2);
if (trim($out2) !== '') echo $out2;
ok('Phase 4 child 2 (unreachable store: refusal, unchanged repost, emergency route)', $code2 === 0);
@exec('rm -rf '.escapeshellarg($storeDir2)); // nothing should have made it, but leave no trace either way

// Child 3 — nothing chosen at all: blank is the first-run state, not degraded.
$flashIni3 = 'STORE_ROOT=""'."\n".'HEADER_MENU="false"'."\n".'TAKEOVER_DOCKER_TAB="false"'."\n";

$child3 = <<<'EOT'
require '/usr/local/emhttp/plugins/staxx/include/Defines.php';
$fails = 0;
function check($cond, $label) { global $fails; if (!$cond) { echo "FAIL child: $label\n"; $fails++; } }
check(staxx_store_ready() === false, 'a blank STORE_ROOT reads as not chosen');
check(staxx_store_reachable() === false, 'nothing is reachable when nothing has been chosen');
check(staxx_settings_degraded() === false, 'unchosen is the first-run state, not degraded');
exit($fails ? 1 : 0);
EOT;

[$out3, $code3] = staxx_test_child($flashIni3, $child3);
if (trim($out3) !== '') echo $out3;
ok('Phase 4 child 3 (no store chosen: unchosen, not degraded)', $code3 === 0);

staxx_test_restore_cfg();

// Prove the restore actually happened rather than trusting the shutdown
// function blindly — this suite rewrites the real flash file, and now the
// real store's settings file too, more than any other on Adrian's own
// production server.
ok('the flash file matches what it held before this run',
   @file_get_contents($cfgFile) === $backup);
if ($storeFile !== '') {
  ok('the store settings file matches what it held before this run',
     @file_get_contents($storeFile) === $storeBackup);
}

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
