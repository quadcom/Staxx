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
 * THIS TEST WRITES TO THE REAL CONFIG FILE. Adrian runs this on his
 * production server, so the writer tests back up
 * /boot/config/plugins/staxx/staxx.cfg before touching it and
 * restore it afterwards — including on a failing exit, via a shutdown
 * function, so a crash partway through cannot leave his settings changed. */

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

function staxx_test_restore_cfg(): void {
  global $cfgFile, $backup, $hadFile;
  if ($hadFile) file_put_contents($cfgFile, $backup);
  else @unlink($cfgFile);
}
register_shutdown_function('staxx_test_restore_cfg');

// A marker key untouched by the allowlist, so its survival proves unknown
// keys are preserved rather than dropped.
$marker = 'ZZB1TEST_UNKNOWN_KEY="keep-me"';
$seed   = ($hadFile ? rtrim((string)$backup, "\n")."\n" : '').$marker."\n";
file_put_contents($cfgFile, $seed);

$err = ''; $reload = null; $saved = null;
$posted = [
  'action'     => 'settings-save', // not an allowlisted key — must be ignored, not error
  'ICON_FETCH' => 'false',
];
$okSave = staxx_settings_save($posted, $err, $reload, $saved);
ok('save with an unknown posted key succeeds', $okSave, $err);
ok('unknown posted key is not in the saved map', $okSave && !array_key_exists('action', (array)$saved));

$after = @parse_ini_file($cfgFile) ?: [];
ok('the unknown cfg key survives the write', ($after['ZZB1TEST_UNKNOWN_KEY'] ?? '') === 'keep-me');
ok('the submitted key was actually written', ($after['ICON_FETCH'] ?? '') === 'false');

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
$err = ''; $reload = null; $saved = null;
$targetTakeover = ($after['TAKEOVER_DOCKER_TAB'] ?? 'false') === 'true' ? 'false' : 'true';
staxx_settings_save(['TAKEOVER_DOCKER_TAB' => $targetTakeover], $err, $reload, $saved);
ok('changing TAKEOVER_DOCKER_TAB asks for a reload', $reload === true, $err);
// Put it back so the restore below has less to undo, though the shutdown
// function restores the original file regardless.
staxx_settings_save(['TAKEOVER_DOCKER_TAB' => $after['TAKEOVER_DOCKER_TAB'] ?? 'false'], $err, $reload, $saved);

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


staxx_test_restore_cfg();

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
