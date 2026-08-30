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
// For staxx_stack_root(), which the STORE_ROOT validator checks a proposed
// store against, so a new store can never be pointed inside the current one's
// own stacks or archives folder.
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
 * user's cfg predates a key or default.cfg itself failed to parse.
 *
 * @return array<string, array{type:string, default:string, choices?:string[], min?:int, max?:int}>
 */
function staxx_settings_keys(): array {
  return [
    'HEADER_MENU'         => ['type' => 'choice', 'default' => 'false', 'choices' => ['false', 'true']],
    'TAKEOVER_DOCKER_TAB' => ['type' => 'choice', 'default' => 'false', 'choices' => ['false', 'true']],
    // Three positions, not two: 'true' catches silently, 'prompt' asks first
    // (the same offer page an existing container's Edit already uses),
    // 'false' leaves Unraid's own route untouched. true/false are kept rather
    // than renamed to on/off because both are already written into every
    // config this has shipped to; 'prompt' is simply added beside them —
    // read as an oversight only if you don't know that.
    'CATCH_INSTALLS'      => ['type' => 'choice', 'default' => 'true',  'choices' => ['true', 'prompt', 'false']],
    // Where StaXX keeps everything — the stacks, the archived zips, and (from
    // Phase 4) its own settings and state. Ships blank on purpose: blank is
    // how the plugin knows nobody has chosen yet, and is what the first-run
    // dialog keys off. Nothing is offered as a default here, because any
    // default would put data somewhere before anybody agreed to it.
    'STORE_ROOT'          => ['type' => 'path',   'default' => ''],
    // PLAN_103 — a plain copy of every stack's compose file kept on the boot
    // drive, so losing the data store never means losing every stack's
    // definition. Not in STAXX_FLASH_KEYS: this only ever gates a copy step,
    // it is never needed before the store is reachable.
    'BOOT_COPY'           => ['type' => 'choice', 'default' => 'true',  'choices' => ['true', 'false']],
    /* Does the folder picker narrow itself down when choosing where the
     * stacks live, and are the placement rules refusals or warnings?
     *
     *   guided = the picker marks the roots the stacks cannot live on, and a
     *            risky location is refused; the default
     *   open   = nothing is marked, and a risky location is allowed with the
     *            reason said plainly instead
     *
     * "open" does NOT lift everything. Two refusals stay whatever this says,
     * because neither is a risk somebody can sensibly accept: a filesystem
     * living in memory loses the stacks at the next reboot for no benefit at
     * all, and a whole share as the stack root makes every folder in that
     * share read as a stack, which is simply wrong rather than daring.
     */
    'PLACEMENT_RULES'     => ['type' => 'choice', 'default' => 'guided', 'choices' => ['guided', 'open']],
    'ICON_FETCH'          => ['type' => 'choice', 'default' => 'true',  'choices' => ['true', 'false']],
    // PLAN_86 — record a matched icon into the compose file and copy its
    // picture into the stack's own folder, instead of guessing again on
    // every render. Not in $reload below: nothing that reads this needs the
    // page itself to reload.
    'ICON_ADOPT'          => ['type' => 'choice', 'default' => 'true',  'choices' => ['true', 'false']],
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
    // PLAN_92a Part A — registries this server's owner runs and vouches for.
    // Not read at page load, so not in $reload below.
    'REGISTRY_TRUST'      => ['type' => 'hosts', 'default' => ''],
    // When to check images for updates (PLAN_45 Part F). Neither key is read
    // at page load, so neither belongs in the $reload list below — the panel
    // just closes on save.
    'UPDATE_CHECK'        => ['type' => 'choice', 'default' => 'daily', 'choices' => ['off', 'daily', 'weekly']],
    'UPDATE_CHECK_TIME'   => ['type' => 'time',   'default' => '04:00'],
    // PLAN_62 — look at the publisher's own GitHub repository during the
    // same check pass. Not read at page load, so not in $reload below.
    'WATCH_EXAMPLES'      => ['type' => 'choice', 'default' => 'true', 'choices' => ['true', 'false']],
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
    // The password generator's own choices (PLAN_74 Part A) — a preference
    // that should follow the person to any browser, not a secret, and set
    // from the generator panel in the editor rather than the settings page.
    'PWGEN_MODE'          => ['type' => 'choice', 'default' => 'chars', 'choices' => ['chars', 'words']],
    'PWGEN_LENGTH'        => ['type' => 'number', 'default' => '20', 'min' => 8, 'max' => 128],
    'PWGEN_UPPER'         => ['type' => 'choice', 'default' => 'true', 'choices' => ['true', 'false']],
    'PWGEN_DIGITS'        => ['type' => 'choice', 'default' => 'true', 'choices' => ['true', 'false']],
    'PWGEN_PUNCT'         => ['type' => 'choice', 'default' => 'true', 'choices' => ['true', 'false']],
    'PWGEN_WORDS'         => ['type' => 'number', 'default' => '5', 'min' => 3, 'max' => 12],
    'PWGEN_SEP'           => ['type' => 'text', 'default' => '-'],
    // Whether the StaXXCrypt hashing container sits idle between hashes or
    // stays running (PLAN_74 Part A piece 3). On demand is the default: it
    // consumes nothing until asked, and the delay — a start and a stop, a
    // second or two — is unnoticeable for something most people use only a
    // handful of times a year.
    'CRYPT_MODE'          => ['type' => 'choice', 'default' => 'ondemand', 'choices' => ['ondemand', 'always']],
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
 * One key now, STORE_ROOT, so every rule below applies unconditionally —
 * there is no second, laxer key that used to skip some of them.
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
  $label = 'data store';

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

  // A new store must never sit inside the current one's own stacks or
  // archives folder: nesting it there is how an archive zip gets read back
  // as a stack, or a stack folder gets read back as an archive. Guarded on
  // staxx_store_ready() because an unchosen store has neither folder to
  // collide with.
  if (staxx_store_ready()) {
    $stackRoot   = staxx_stack_root();
    $archiveRoot = staxx_archive_root();
    if ($norm === $stackRoot || strpos($norm, $stackRoot.'/') === 0
        || $norm === $archiveRoot || strpos($norm, $archiveRoot.'/') === 0) {
      $error = 'The data store cannot be the current stacks folder or the current archive '
             . 'folder, or sit inside either — a zip file there would be mistaken for a stack. '
             . 'Choose a location outside them.';
      return '';
    }
  }

  /* The data store may only live on a pool or inside a share. Refused here
   * rather than merely discouraged in the chooser, because the chooser is
   * not the only way in — every saved value passes through this function.
   *
   * /mnt/diskN and /mnt/user0 are the array's own paths: one array disk has
   * no redundancy of its own, and writing straight to it goes behind the
   * array's placement rules instead of through them. /mnt/disks and
   * /mnt/remotes are unassigned drives and network mounts, either of which
   * can simply be absent at the next boot with the data inside it.
   *
   * Enforced only while the placement rules are set to guide. Turned to
   * 'open', the same risk is reported as a warning beside the destination box
   * instead — staxx_placement_risk() is the single place that decides what
   * counts as risky, so the two modes cannot drift apart.
   *
   * The memory-filesystem refusal below is NOT behind this switch: losing the
   * data at the next reboot is not a risk anybody accepts on purpose.
   *
   * An existing setting is never re-validated on read, so this refusal cannot
   * lock anyone out of an install they already have.
   */
  if ($underMnt && staxx_placement_guided()) {
    $risk = staxx_placement_risk($norm);
    if ($risk !== '') {
      $error = 'The data store cannot go here — '.$risk.'. Choose a folder inside a share, '
             . 'or on a cache pool, such as /mnt/user/appdata. If you mean to do this anyway, '
             . 'set the placement rules to "open" in Settings.';
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
 * (which may differ from $v — STORE_ROOT is normalised), or '' with $error
 * set when it is refused. A refused '' is never itself a valid stored value:
 * a blank STORE_ROOT is only meaningful as a stored state — it means nobody
 * has chosen yet — never as something submitted, so a save is refused outright
 * when it is empty, and an empty return unambiguously means "look at $error".
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

  if ($spec['type'] === 'hosts') {
    // Empty is always accepted — no registry is trusted. Otherwise every
    // comma-separated entry must be a bare host, optionally with a port; no
    // scheme, no path, no wildcard — deliberately, since this list is a
    // safety boundary, not a convenience.
    if (trim($v) === '') return '';
    $hosts = [];
    foreach (explode(',', $v) as $entry) {
      $entry = strtolower(trim($entry));
      if (!preg_match('/^[a-z0-9.-]+(:\d{1,5})?$/', $entry)) {
        $error = 'Enter "'.$key.'" as a comma-separated list of host names StaXX should trust, '
               . 'such as registry.home.lan or 192.168.1.20:5000 — no scheme and no wildcard.';
        return '';
      }
      $hosts[] = $entry;
    }
    return implode(',', $hosts);
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
 * $reload is set true when HEADER_MENU, TAKEOVER_DOCKER_TAB or STORE_ROOT
 * actually changed value — those three are read only at page load, so only
 * they need the browser to reload rather than just close the panel.
 *
 * $saved gets the full settings map as it now stands on disk. The
 * obvious way to get that back — call staxx_settings_read() again — does
 * NOT work: staxx_cfg() caches in a per-request static, so a second call
 * in the same request still returns what was on disk before this write. This
 * caller builds it directly from what was validated instead.
 */
/**
 * Merges $overlay into whatever $file already holds on disk and writes it
 * back atomically — unknown keys survive untouched, so a key nothing here
 * validates against (an older build's stray key, or CrossLinks.php's own
 * learned-images key, DB_IMAGES_LEARNED) is never dropped by a write that
 * does not know about it. The one place this plugin ever writes an ini
 * file of its own; staxx_settings_save() below and include/CrossLinks.php's
 * staxx_crosslinks_learn() both go through it — one call per file, the
 * flash pointer or the store's settings file — rather than each holding
 * their own copy of the read-merge-rename dance.
 *
 * The caller decides which file: this function has no opinion on flash
 * versus store, so it is exactly as safe to point at either.
 *
 * @return bool
 */
function staxx_cfg_write_keys(string $file, array $overlay, ?string &$error = null): bool {
  $error = '';

  $dir = dirname($file);
  if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
    $error = 'Could not create '.$dir.'.';
    return false;
  }

  // parse_ini_file() returns false, for the WHOLE file, on a single syntax
  // error — never an empty array — so `?: []` cannot tell "nothing there
  // yet" apart from "could not be read", and would otherwise silently drop
  // every key this write does not know about.
  $raw = @parse_ini_file($file, false, INI_SCANNER_RAW);
  if ($raw === false && is_file($file)) {
    $error = 'Could not read '.$file.' — it may have a syntax error. Fix it by hand, '
           . 'or remove it and let StaXX recreate it, before saving again.';
    return false;
  }
  $existing = $raw !== false ? $raw : [];
  foreach ($overlay as $key => $value) $existing[$key] = $value;

  $lines = [];
  foreach ($existing as $key => $value) {
    if (!is_scalar($value)) continue; // parse_ini_file() array syntax; never used here
    $lines[] = $key.'="'.$value.'"';
  }

  $tmp = $file.'.tmp-'.getmypid();
  if (@file_put_contents($tmp, implode("\n", $lines)."\n") === false) {
    @unlink($tmp); // a partial write is possible even though the call reported failure
    $error = 'Could not write '.$tmp.'.';
    return false;
  }
  // Intent is owner-only, since a settings file can hold the Docker Hub
  // token (HUB_TOKEN) in the clear — but the flash pointer lives on a vfat
  // mount with no concept of Unix permissions, so this call is a no-op
  // there: every file on that mount is already owner-only regardless of
  // what chmod reports. The store's settings file is off flash, though, so
  // this is what actually protects that one.
  @chmod($tmp, 0600);
  if (!@rename($tmp, $file)) {
    @unlink($tmp);
    $error = 'Could not save the settings file — the temporary file could not be put in place.';
    return false;
  }
  return true;
}

function staxx_settings_save(
  array $posted, ?string &$error = null, ?bool &$reload = null, ?array &$saved = null
): bool {
  $error  = '';
  $reload = false;

  $keys        = staxx_settings_keys();
  $before      = staxx_settings_read();
  $reachable   = staxx_store_reachable();
  $flashOverlay = [];
  $storeOverlay = [];

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

    if (in_array($key, STAXX_FLASH_KEYS, true)) {
      $flashOverlay[$key] = $stored;
      continue;
    }

    // Everything else lives in the store's own settings file, which can
    // only be written while the store can actually be reached. The settings
    // page posts every field on every save, not just the one somebody
    // touched, so a value that has not actually changed is not a save
    // attempt and is quietly skipped rather than refused — that is what
    // keeps the emergency route (flipping a flash key while the store is
    // unreachable) usable at all.
    if (!$reachable) {
      if ($stored === $before[$key]) continue;
      $error = 'The data store cannot be reached right now, so "'.$key.'" cannot be saved — only '
             . 'the data store location and the two menu settings can be changed until it comes '
             . 'back. Wait for the array (or the store\'s pool) to finish starting, then try again.';
      return false;
    }
    $storeOverlay[$key] = $stored;
  }

  /* Moving the store and changing something else in the same save cannot be
   * done coherently, so it is refused rather than half-done. staxx_cfg()
   * memoises for the life of the request, so staxx_settings_file() below
   * still names the OLD store — the other settings would be written there
   * while the pointer moved to the new one, and the store actually in use
   * afterwards would never have seen them. Pointing STORE_ROOT somewhere
   * else on its own is a different, coherent thing: that store brings its
   * own settings with it, which is exactly what adopting one means. */
  if (isset($flashOverlay['STORE_ROOT'])
      && $flashOverlay['STORE_ROOT'] !== $before['STORE_ROOT']
      && $storeOverlay !== []) {
    $error = 'The data store cannot be moved in the same save as another setting, because the '
           . 'other settings live inside the store itself. Change the location on its own, or '
           . 'use the move on the settings panel, which copies everything across first.';
    return false;
  }

  /* Two files, two writers: the three flash keys go to the pointer file on
   * flash, everything else to the store's own settings file. The store
   * write is skipped entirely rather than attempted with an empty overlay —
   * there is nothing to say when the store is unreachable, and nothing to
   * write when nothing changed.
   *
   * The store is written FIRST. A failed write there then leaves nothing
   * changed at all, where writing flash first would leave the pointer or
   * the menu already moved with the rest of the save never landing. */
  if ($storeOverlay !== []) {
    if (!staxx_cfg_write_keys(staxx_settings_file(), $storeOverlay, $error)) {
      return false;
    }
  }
  if (!staxx_cfg_write_keys(STAXX_CFG, $flashOverlay, $error)) {
    return false;
  }

  $saved = $before;
  foreach ($flashOverlay as $key => $value) $saved[$key] = $value;
  foreach ($storeOverlay as $key => $value) $saved[$key] = $value;
  // $storeOverlay['HUB_TOKEN'], when present, is the real new token in the
  // clear — it has to be, to be written above. It must not reach the browser
  // that way, so mask it again here exactly as staxx_settings_read() does.
  if (isset($saved['HUB_TOKEN'])) $saved['HUB_TOKEN'] = $saved['HUB_TOKEN'] !== '' ? STAXX_HUB_TOKEN_MASK : '';

  foreach (['HEADER_MENU', 'TAKEOVER_DOCKER_TAB', 'STORE_ROOT'] as $key) {
    if ($saved[$key] !== $before[$key]) { $reload = true; break; }
  }

  // Same script the Unraid settings form runs after a save, so the marker file
  // and (once built) the Docker-tab shadow page land exactly the way they do
  // from there. The config is already written by this point, so a failure here
  // is reported as "saved but not applied" rather than implying nothing happened.
  //
  // 25 seconds, not 15: the script itself now runs `docker info` and, at most,
  // one of `docker login`/`docker logout` in sequence, each under its own
  // 10-second timeout — 20 seconds worst case. This budget has to clear both
  // of those plus a few seconds of slack for the rest of the script, or a slow
  // network reads as a failed save even though apply_settings would have
  // finished fine given a little longer.
  $code = 0;
  staxx_sh('bash '.escapeshellarg(STAXX_ROOT.'/scripts/apply_settings'), 25, $code);
  if ($code !== 0) {
    $error = 'Settings were saved, but applying them failed (apply_settings exited with status '
           . $code.'). Reboot the server, or run scripts/apply_settings by hand, to finish applying them.';
    return false;
  }

  return true;
}
?>
