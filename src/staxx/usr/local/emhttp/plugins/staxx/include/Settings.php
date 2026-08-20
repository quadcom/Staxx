<?PHP
/* StaXX — the settings allowlist, and the only place that writes the config.
 * Copyright 2026, StaXX contributors.
 *
 * Before this file, nothing in the plugin wrote its own config at all: Unraid's
 * settings page posted straight to /update.php, which writes every non-#
 * field verbatim with no allowlist. This is that allowlist, plus the atomic
 * writer /update.php never had either.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/staxx/include/Defines.php';
// For staxx_stack_root(), which the ARCHIVE_ROOT validator checks against.
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';

if (defined('STAXX_SETTINGS_LOADED')) return;
define('STAXX_SETTINGS_LOADED', true);

// Shown to the browser in place of a saved Docker Hub token, and understood
// on save to mean "leave the stored token alone" — see the note in
// staxx_settings_save() below for why that second half matters.
const STAXX_HUB_TOKEN_MASK = '********';

/**
 * The allowlist, and the single source of truth for what a setting is.
 *
 * key => ['type' => 'choice'|'path'|'text'|'time'|'number', 'default' => string, 'choices' => [...]].
 * `choices` is absent for a path, text, time or number field, since their range is
 * not a fixed list. `text` is a free string, gated only by the shared character
 * rule (staxx_settings_char_rule()) and a 255-character length cap; an empty
 * value is always accepted, which is what HUB_USER/HUB_TOKEN use to mean
 * "signed out". `time` is a 24-hour "HH:MM" clock time, leading zero required.
 * `number` is a whole number, its range given by `min` and `max` in the spec.
 * Keep the defaults here matching default.cfg — they are not read from that
 * file, because the whole point is a value the browser can trust even if a
 * user's cfg predates a key or default.cfg itself failed to parse. The one
 * exception is ARCHIVE_ROOT: its default is computed, not a literal, because
 * the right answer depends on where this box's appdata actually lives.
 *
 * @return array<string, array{type:string, default:string, choices?:string[], min?:int, max?:int}>
 */
function staxx_settings_keys(): array {
  return [
    'HEADER_MENU'         => ['type' => 'choice', 'default' => 'false', 'choices' => ['false', 'true']],
    'TAKEOVER_DOCKER_TAB' => ['type' => 'choice', 'default' => 'false', 'choices' => ['false', 'true']],
    'STACK_ROOT'          => ['type' => 'path',   'default' => '/boot/config/plugins/staxx/stacks'],
    'ARCHIVE_ROOT'        => ['type' => 'path',   'default' => staxx_archive_root()],
    'ICON_FETCH'          => ['type' => 'choice', 'default' => 'true',  'choices' => ['true', 'false']],
    'IMAGE_LOOKUP'        => ['type' => 'choice', 'default' => 'true',  'choices' => ['true', 'false']],
    // Gates staxx_exec_start() on the server, not only the button in the
    // browser — see PLAN_44 section D4.
    'SHELL_ENABLED'       => ['type' => 'choice', 'default' => 'true',  'choices' => ['true', 'false']],
    // Set once, by the same settings-save path, the first time a shell is
    // opened — so the "changes vanish on rebuild" warning is shown once per
    // server rather than once per browser. Not in $reload below: nothing
    // that reads this needs the page itself to reload.
    'SHELL_WARNED'        => ['type' => 'choice', 'default' => 'false', 'choices' => ['true', 'false']],
    // Docker Hub sign-in for image update checking (PLAN_45 Part F). Neither
    // is read here for reload purposes — see the $reload list further down.
    'HUB_USER'            => ['type' => 'text', 'default' => ''],
    'HUB_TOKEN'           => ['type' => 'text', 'default' => ''],
    // When to check images for updates (PLAN_45 Part F). Neither key is read
    // at page load, so neither belongs in the $reload list below — the panel
    // just closes on save.
    'UPDATE_CHECK'        => ['type' => 'choice', 'default' => 'daily', 'choices' => ['off', 'daily', 'weekly']],
    'UPDATE_CHECK_TIME'   => ['type' => 'time',   'default' => '04:00'],
    // What happens once an update is found (global default; a stack or
    // service can override it — PLAN_45 Part G), and the rest of the update
    // pipeline's settings. None of these are read at page load either, so
    // none belong in $reload — the panel just closes on save.
    'UPDATE_MODE'         => ['type' => 'choice', 'default' => 'notify', 'choices' => ['off', 'notify', 'auto']],
    'UPDATE_DELAY_HOURS'  => ['type' => 'number', 'default' => '24', 'min' => 0, 'max' => 720],
    'UPDATE_WINDOW'       => ['type' => 'choice', 'default' => 'true', 'choices' => ['true', 'false']],
    'UPDATE_WINDOW_START' => ['type' => 'time',   'default' => '03:00'],
    'UPDATE_WINDOW_END'   => ['type' => 'time',   'default' => '05:00'],
    'UPDATE_NOTIFY'       => ['type' => 'choice', 'default' => 'off', 'choices' => ['off', 'found', 'applied']],
    'UPDATE_RETAIN'       => ['type' => 'number', 'default' => '2', 'min' => 0, 'max' => 5],
    'UPDATE_CLEANUP'      => ['type' => 'choice', 'default' => 'off', 'choices' => ['off', 'weekly']],
  ];
}

/**
 * The current value of every allowlisted setting, defaults filled in. This is
 * the only settings shape the browser ever sees — a key not named here does
 * not exist as far as the panel is concerned.
 *
 * @return array<string, string>
 */
function staxx_settings_read(): array {
  $cfg = staxx_cfg();
  $out = [];
  foreach (staxx_settings_keys() as $key => $spec) {
    $v = trim((string)($cfg[$key] ?? ''));
    $v = $v !== '' ? $v : $spec['default'];
    // The token itself never leaves the server once saved — the panel only
    // needs to know whether one is set, not what it is.
    if ($key === 'HUB_TOKEN') $v = $v !== '' ? STAXX_HUB_TOKEN_MASK : '';
    $out[$key] = $v;
  }
  return $out;
}

/** The message shown whenever a value contains a character the cfg cannot hold. */
function staxx_settings_char_rule(string $key): string {
  return 'The value for "'.$key.'" contains a quote mark, a backslash or a control character, '
       . 'none of which the settings file can hold. Remove it and try again.';
}

/**
 * Is this path setting acceptable, and if so what should actually be stored?
 * Shared by STACK_ROOT and ARCHIVE_ROOT — the rules are identical bar one
 * extra check that only makes sense for the archive folder (below), and the
 * wording, which names the field in plain words rather than its cfg key.
 *
 * Checked against the value as submitted, before any trimming, so that "/"
 * is judged on what it actually is rather than on what rtrim() would turn it
 * into — rtrim('/', '/') is '', and an empty string would then fail on the
 * wrong rule with a misleading message ("cannot be blank" instead of "must
 * sit under /mnt/ or the plugin's own config folder").
 *
 * @return string the normalised value to store, or '' when $error is set
 */
/**
 * Does this path sit on a filesystem that lives in memory, and therefore
 * loses everything in it at the next reboot? True for Unraid's own root
 * filesystem, and for the small tmpfs mounts under /mnt that exist only to
 * hold mount points (/mnt/disks, /mnt/remotes, /mnt/addons).
 *
 * Answered by finding the longest mount point in /proc/mounts that this path
 * sits under, which is by definition the filesystem it lands on, and reading
 * that mount's type. Fails OPEN: an unreadable /proc means no opinion, and
 * refusing every path because a kernel file could not be read would be a
 * worse outcome than the one this guards against.
 */
function staxx_path_in_memory(string $path): bool {
  $lines = @file('/proc/mounts', FILE_IGNORE_NEW_LINES);
  if ($lines === false) return false;

  $bestLen = -1;
  $type    = '';
  foreach ($lines as $line) {
    $cols = preg_split('/\s+/', trim($line));
    if (count($cols) < 3) continue;
    // /proc/mounts escapes a space in a mount point as \040, and octal
    // escapes are exactly what stripcslashes() undoes.
    $mount = stripcslashes($cols[1]);
    $under = $mount === '/' || $path === $mount
             || strpos($path, rtrim($mount, '/').'/') === 0;
    if (!$under) continue;
    // Longest match wins: /mnt/disks must beat /mnt, and a drive mounted
    // under /mnt/disks must in turn beat /mnt/disks itself.
    if (strlen($mount) > $bestLen) { $bestLen = strlen($mount); $type = $cols[2]; }
  }

  return in_array($type, ['tmpfs', 'ramfs', 'rootfs', 'devtmpfs'], true);
}

function staxx_settings_validate_path(string $key, string $v, string &$error): string {
  $label = ['STACK_ROOT' => 'stacks folder', 'ARCHIVE_ROOT' => 'archive folder'][$key] ?? 'folder';

  if ($v === '') {
    $error = 'Enter a path for the '.$label.' — it cannot be left blank.';
    return '';
  }
  if ($v[0] !== '/') {
    $error = 'The '.$label.' must be a full path starting with a forward slash, '
           . 'such as /mnt/user/appdata/stacks.';
    return '';
  }
  if (strpos($v, '..') !== false) {
    $error = 'The '.$label.' path must not contain ".." — enter the real path, not a relative one.';
    return '';
  }

  $underMnt = strpos($v, '/mnt/') === 0;
  $underCfg = $v === STAXX_CFG_DIR || strpos($v, STAXX_CFG_DIR.'/') === 0;
  if (!$underMnt && !$underCfg) {
    $error = 'The '.$label.' must be somewhere under /mnt/ (a share or disk) or under '
           . STAXX_CFG_DIR.' — choose a location there instead.';
    return '';
  }

  // Safe to trim trailing slashes now: everything that passed the check above
  // has real content left afterwards ("/mnt/" -> "/mnt", never "").
  $norm = rtrim($v, '/');

  // An archive sitting inside the stacks tree would be read back as a stack
  // or a folder — the model has no way to tell a zip apart from either.
  if ($key === 'ARCHIVE_ROOT') {
    $stackRoot = staxx_stack_root();
    if ($norm === $stackRoot || strpos($norm, $stackRoot.'/') === 0) {
      $error = 'The archive folder cannot be the stacks folder, or sit inside it — '
             . 'a zip file there would be mistaken for a stack. Choose a location outside it.';
      return '';
    }
  }

  if (!is_dir($norm) && !is_dir(dirname($norm))) {
    $error = 'That folder does not exist, and neither does its parent, so it could not be '
           . 'created. Check the path, or use the folder browser to pick one that exists.';
    return '';
  }

  // /mnt itself is not storage — it is where the shares and disks are
  // mounted. A path that only LOOKS like one of them, "/mnt/appdata" with
  // the "user/" left out or "/mnt/disks/mydrive" while that drive is not
  // mounted, lands on Unraid's root filesystem instead. That is a RAM disk:
  // everything written there is gone at the next reboot, having quietly
  // eaten memory until then. Nothing else in this validator can catch it,
  // because such a path exists, is writable, and behaves normally right up
  // to the reboot.
  //
  // The test asks what kind of filesystem the path would actually land on,
  // rather than whether something is mounted along it — because on Unraid the
  // two are not the same question. /mnt/disks and /mnt/remotes are each a
  // one-megabyte tmpfs holding nothing but mount points for drives and shares
  // that come and go, so "something is mounted here" is true of them and
  // still the wrong answer. Asking about the filesystem accepts a real share
  // or a genuinely mounted drive at any depth, and refuses /mnt itself, a
  // share name with a typo in it, and an Unassigned Devices path whose drive
  // is not currently mounted.
  if ($underMnt) {
    $deepest = $norm;
    while ($deepest !== '/mnt' && !is_dir($deepest)) $deepest = dirname($deepest);

    if (staxx_path_in_memory($deepest)) {
      $error = 'The '.$label.' would be created on a filesystem that lives in memory '
             . '("'.$deepest.'" is the deepest part of that path that exists), so everything '
             . 'in it would be lost at the next reboot. Put it under a share such as '
             . '/mnt/user/appdata, or on a drive that is actually mounted.';
      return '';
    }
  }

  return $norm;
}

/**
 * Validate one submitted value against its spec. Returns the value to store
 * (which may differ from $v — STACK_ROOT is normalised), or '' with $error
 * set when it is refused. A refused '' is never itself a valid stored value:
 * every spec here has a non-empty default and STACK_ROOT is refused outright
 * when empty, so an empty return unambiguously means "look at $error".
 */
function staxx_settings_validate(string $key, array $spec, string $v, string &$error): string {
  // Split into three plain checks rather than one dense regex — a quote or a
  // backslash would corrupt the KEY="value" line this is written into, and a
  // control character (this covers newlines too) has no business in a cfg
  // value at all.
  if (strpos($v, '"') !== false || strpos($v, '\\') !== false || preg_match('/[\x00-\x1F\x7F]/', $v)) {
    $error = staxx_settings_char_rule($key);
    return '';
  }

  if ($spec['type'] === 'path') {
    return staxx_settings_validate_path($key, $v, $error);
  }

  if ($spec['type'] === 'text') {
    // Empty is always accepted — HUB_USER/HUB_TOKEN use it to mean signed out.
    if (strlen($v) > 255) {
      $error = 'The value for "'.$key.'" is too long — keep it to 255 characters or fewer.';
      return '';
    }
    return $v;
  }

  if ($spec['type'] === 'time') {
    // Exactly HH:MM, 24-hour, leading zero required — anchored both ends so
    // trailing junk (a stray space, a shell operator) cannot ride along.
    if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $v)) {
      $error = 'Enter the time for "'.$key.'" as a 24-hour clock time, such as 04:00.';
      return '';
    }
    return $v;
  }

  if ($spec['type'] === 'number') {
    // A bare whole number only — no sign, no decimal point, no leading zero
    // padding tricks — then checked against the range the spec declares.
    if (!preg_match('/^[0-9]+$/', $v) || (int)$v < $spec['min'] || (int)$v > $spec['max']) {
      $error = 'Enter a whole number between '.$spec['min'].' and '.$spec['max'].' for "'.$key.'".';
      return '';
    }
    return $v;
  }

  if (!in_array($v, $spec['choices'], true)) {
    $error = '"'.$v.'" is not a valid value for "'.$key.'". Choose one of the options offered.';
    return '';
  }
  return $v;
}

/**
 * Validate a posted settings form and, if everything in it is acceptable,
 * write it — atomically, and without disturbing any key this plugin does not
 * know about. Nothing is written unless everything submitted validates,
 * because saving most settings and refusing one would leave the cfg in
 * a state nobody chose.
 *
 * $reload is set true when HEADER_MENU, TAKEOVER_DOCKER_TAB or STACK_ROOT
 * actually changed value — those three are read only at page load, so only
 * they need the browser to reload rather than just close the panel.
 * ARCHIVE_ROOT is deliberately not in that list: it is read fresh on every
 * removal, not cached at page load.
 *
 * $saved gets the full settings map as it now stands on disk. The
 * obvious way to get that back — call staxx_settings_read() again — does
 * NOT work: staxx_cfg() caches in a per-request static, so a second call
 * in the same request still returns what was on disk before this write. This
 * caller builds it directly from what was validated instead.
 */
function staxx_settings_save(
  array $posted, ?string &$error = null, ?bool &$reload = null, ?array &$saved = null
): bool {
  $error  = '';
  $reload = false;

  $keys    = staxx_settings_keys();
  $before  = staxx_settings_read();
  $overlay = [];

  // Validate everything first. One bad value must save none of them.
  foreach ($keys as $key => $spec) {
    if (!array_key_exists($key, $posted)) continue;
    $raw = $posted[$key];
    if (!is_string($raw)) { $error = 'The value for "'.$key.'" must be plain text.'; return false; }

    // The browser only ever sees the placeholder for a saved token, never the
    // token itself (staxx_settings_read() above). A form that posts it back
    // unchanged must not overwrite the real one with eight literal asterisks
    // — so this is read as "leave HUB_TOKEN alone", not as a value to store.
    if ($key === 'HUB_TOKEN' && $raw === STAXX_HUB_TOKEN_MASK) continue;

    // Not trimmed first: a leading or trailing newline is exactly the kind of
    // thing the character check below exists to catch, and trimming it away
    // beforehand would let it slip through unnoticed.
    $stored = staxx_settings_validate($key, $spec, $raw, $error);
    if ($error !== '') return false;
    $overlay[$key] = $stored;
  }

  if (!is_dir(STAXX_CFG_DIR) && !@mkdir(STAXX_CFG_DIR, 0755, true)) {
    $error = 'Could not create '.STAXX_CFG_DIR.'.';
    return false;
  }

  // The file as it exists on disk right now, unknown keys and all — this is
  // what gets overlaid and written straight back, so a key from a newer
  // version of the plugin survives a save made by an older one.
  $existing = @parse_ini_file(STAXX_CFG) ?: [];
  foreach ($overlay as $key => $value) $existing[$key] = $value;

  $lines = [];
  foreach ($existing as $key => $value) {
    if (!is_scalar($value)) continue; // parse_ini_file() array syntax; never used here
    $lines[] = $key.'="'.$value.'"';
  }

  $tmp = STAXX_CFG.'.tmp-'.getmypid();
  if (@file_put_contents($tmp, implode("\n", $lines)."\n") === false) {
    @unlink($tmp); // a partial write is possible even though the call reported failure
    $error = 'Could not write '.$tmp.'.';
    return false;
  }
  @chmod($tmp, 0644);
  if (!@rename($tmp, STAXX_CFG)) {
    @unlink($tmp);
    $error = 'Could not save the settings file — the temporary file could not be put in place.';
    return false;
  }

  $saved = $before;
  foreach ($overlay as $key => $value) $saved[$key] = $value;
  // $overlay['HUB_TOKEN'], when present, is the real new token in the clear —
  // it has to be, to be written above. It must not reach the browser that
  // way, so mask it again here exactly as staxx_settings_read() does.
  if (isset($saved['HUB_TOKEN'])) $saved['HUB_TOKEN'] = $saved['HUB_TOKEN'] !== '' ? STAXX_HUB_TOKEN_MASK : '';

  foreach (['HEADER_MENU', 'TAKEOVER_DOCKER_TAB', 'STACK_ROOT'] as $key) {
    if ($saved[$key] !== $before[$key]) { $reload = true; break; }
  }

  // Same script the Unraid settings form runs after a save, so the marker file
  // and (once built) the Docker-tab shadow page land exactly the way they do
  // from there. The config is already written by this point, so a failure here
  // is reported as "saved but not applied" rather than implying nothing happened.
  $code = 0;
  staxx_sh('bash '.escapeshellarg(STAXX_ROOT.'/scripts/apply_settings'), 15, $code);
  if ($code !== 0) {
    $error = 'Settings were saved, but applying them failed (apply_settings exited with status '
           . $code.'). Reboot the server, or run scripts/apply_settings by hand, to finish applying them.';
    return false;
  }

  return true;
}
?>
