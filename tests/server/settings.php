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
ok('exactly six keys', count($keys) === 6, implode(',', array_keys($keys)));
foreach (['HEADER_MENU', 'TAKEOVER_DOCKER_TAB', 'STACK_ROOT', 'ARCHIVE_ROOT',
          'ICON_FETCH', 'IMAGE_LOOKUP'] as $k) {
  ok('has '.$k, array_key_exists($k, $keys));
}

$read = staxx_settings_read();
ok('read returns exactly six keys', count($read) === 6);
foreach (array_keys($keys) as $k) ok('read has '.$k, array_key_exists($k, $read));

/* --------------------------------------------------------- validator ---- */

// Validated directly, with no write involved — staxx_settings_validate()
// and staxx_settings_validate_path() are pure functions.

$err = '';
$badRoots = ['', '..', 'relative/path', '/', '/etc', '/mnt/../etc'];
foreach ($badRoots as $bad) {
  $v = staxx_settings_validate('STACK_ROOT', $keys['STACK_ROOT'], $bad, $err);
  ok('rejects STACK_ROOT '.var_export($bad, true), $v === '' && $err !== '', $err);
}

$err = '';
$v = staxx_settings_validate('STACK_ROOT', $keys['STACK_ROOT'], '/mnt/user"quote', $err);
ok('rejects a STACK_ROOT containing a quote', $v === '' && $err !== '', $err);

$err = '';
$v = staxx_settings_validate('HEADER_MENU', $keys['HEADER_MENU'], 'yes', $err);
ok('rejects HEADER_MENU "yes"', $v === '' && $err !== '', $err);

$err = '';
$v = staxx_settings_validate('ICON_FETCH', $keys['ICON_FETCH'], '', $err);
ok('rejects ICON_FETCH ""', $v === '' && $err !== '', $err);

// A good root under /mnt/ whose parent exists — /mnt itself always does.
$err  = '';
$good = '/mnt/zzb1-settings-test-'.getmypid();
$v    = staxx_settings_validate('STACK_ROOT', $keys['STACK_ROOT'], $good, $err);
ok('accepts a STACK_ROOT under /mnt/ with an existing parent', $v === $good, $err);

// The archive folder shares every rule above, plus one of its own: a zip
// inside the stacks tree would be read back as a stack or a folder.
$err = '';
$v = staxx_settings_validate('ARCHIVE_ROOT', $keys['ARCHIVE_ROOT'], staxx_stack_root(), $err);
ok('rejects an ARCHIVE_ROOT that IS the stacks folder', $v === '' && $err !== '', $err);
$err = '';
$v = staxx_settings_validate('ARCHIVE_ROOT', $keys['ARCHIVE_ROOT'],
                             staxx_stack_root().'/archives', $err);
ok('rejects an ARCHIVE_ROOT inside the stacks folder', $v === '' && $err !== '', $err);
$err  = '';
$good = '/mnt/zzb1-archive-test-'.getmypid();
$v = staxx_settings_validate('ARCHIVE_ROOT', $keys['ARCHIVE_ROOT'], $good, $err);
ok('accepts an ARCHIVE_ROOT outside it', $v === $good, $err);

foreach ($keys as $k => $spec) {
  if ($spec['type'] !== 'choice') continue;
  foreach ($spec['choices'] as $choice) {
    $err = '';
    $v = staxx_settings_validate($k, $spec, $choice, $err);
    ok('accepts '.$k.'='.$choice, $v === $choice, $err);
  }
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

staxx_test_restore_cfg();

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
