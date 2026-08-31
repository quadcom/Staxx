<?PHP
/* StaXX — compose-native Docker management for Unraid.
 * Copyright 2026, StaXX contributors.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
if (defined('STAXX_PLUGIN')) return;

define('STAXX_PLUGIN',  'staxx');
define('STAXX_ROOT',    '/usr/local/emhttp/plugins/'.STAXX_PLUGIN);
define('STAXX_CFG_DIR', '/boot/config/plugins/'.STAXX_PLUGIN);

// PLAN_97 Phase 4 narrowed this from "the settings file" to "the flash
// pointer file": it now holds only the three keys STAXX_FLASH_KEYS names.
// Everything else lives in staxx_settings_file(), inside the data store.
// Kept on flash, and kept this small, because these are the only settings
// that must be readable before the array is up, or when the store itself
// cannot be reached — STORE_ROOT names the store, and the other two decide
// where StaXX's menu appears, including the emergency route back to
// Unraid's own Docker tab when StaXX's page cannot be reached at all.
define('STAXX_CFG',     STAXX_CFG_DIR.'/'.STAXX_PLUGIN.'.cfg');

// The only keys the flash pointer file may hold. One list, so Defines.php,
// Settings.php and scripts/apply_settings all agree on what "a flash key"
// means rather than each hard-coding its own copy that could drift apart.
define('STAXX_FLASH_KEYS', ['STORE_ROOT', 'HEADER_MENU', 'TAKEOVER_DOCKER_TAB']);

// Release-notes cap, characters. A stack's own record must not quietly grow
// by a megabyte of vendor prose because one release included a full changelog.
define('STAXX_NOTES_MAX', 4000);

// StaXXCrypt, the container StaXX hashes with (PLAN_74, include/Crypt.php).
// Its name lives here rather than beside the rest of that file's constants
// because staxx_docker_ps_raw() below has to know it, and Crypt.php requires
// this file — putting it the other way round would be a circle.
define('STAXX_CRYPT_CONTAINER', 'StaXXCrypt');

// Projection of the HEADER_MENU setting onto a file, written by
// scripts/apply_settings. The .page Cond expressions test for this instead of
// parsing the config, because Cond runs on every render of every page.
define('STAXX_MARKER_HEADER_MENU', STAXX_CFG_DIR.'/header_menu');

// Same projection, for TAKEOVER_DOCKER_TAB. Stacks.page and StaXX.page's own
// Cond expressions test both marker files directly rather than the config, so
// staxx_view_url() below does the same — reading STAXX_CFG here instead would
// risk disagreeing with which page Cond actually put live.
define('STAXX_MARKER_TAKEOVER_DOCKER_TAB', STAXX_CFG_DIR.'/takeover_docker_tab');

// The one directory the volume picker will look inside. Everything a container
// should be given lives under here, and confining the picker to it means the
// only path the browser can ask about is one the user could already see in
// Unraid's own file browser. See staxx_browse_dirs().
define('STAXX_BROWSE_ROOT', '/mnt');

/**
 * Where Unraid keeps container application data, read from its own config so
 * the folder-creation button offers the same default Community Applications
 * does rather than inventing one of its own.
 *
 * That file belongs to Unraid, not this plugin, so every way of failing to
 * read it — missing, unreadable, parse_ini_file() returning false, a relative
 * or empty value — falls back to the path Unraid ships by default, quietly,
 * rather than raising an error.
 */
function staxx_appdata_root(): string {
  static $root = null;
  if ($root !== null) return $root;

  $fallback = '/mnt/user/appdata/';
  $cfg  = @parse_ini_file('/boot/config/docker.cfg') ?: [];
  $path = trim((string)($cfg['DOCKER_APP_CONFIG_PATH'] ?? ''));

  if ($path === '' || strpos($path, '/mnt/') !== 0) return $root = $fallback;
  return $root = rtrim($path, '/').'/';
}

/**
 * Will Unraid's mover carry this share's files off onto the array?
 *
 * The one question that matters wherever a destination is judged, so it lives
 * in one place: the suggestion builder asks it, and so does the check on a
 * path somebody typed or browsed to, which used to skip it entirely and so
 * accepted a share the mover was going to drain. That gap was found by
 * choosing such a share by hand and being told the path could be used.
 *
 * Only "yes" drains a share onto the array. "prefer" moves the other way,
 * array -> pool; "only" and "no" move nothing between the two; and a share
 * with no settings file at all is never examined by the mover, which loops
 * over /boot/config/shares/*.cfg and nothing else.
 *
 * @return string '' when the mover will leave it alone, else why not.
 */
function staxx_share_drain_reason(string $share, string $sharesDir = '/boot/config/shares'): string {
  $cfg = @parse_ini_file($sharesDir.'/'.$share.'.cfg', false, INI_SCANNER_RAW);
  if ($cfg === false || !isset($cfg['shareUseCache'])) return '';
  if (trim((string)$cfg['shareUseCache'], '"') !== 'yes') return '';

  /* A clause, not a sentence: every caller wraps it in its own wording, and
   * one that already carried advice and a full stop produced "...choose a
   * different location.. Choose a folder inside a share...". Lower case and
   * no trailing stop, matching the other clauses staxx_placement_risk()
   * returns, so all three read the same after an em dash. */
  return 'the "'.$share.'" share is set to be moved onto the array by the mover, so the stacks '
       . 'would not stay on this pool';
}

/**
 * Does the plugin guide where the stacks may live, or get out of the way?
 *
 * 'guided' (the default) marks the roots the stacks cannot live on in the
 * folder picker and refuses a risky location outright. 'open' shows
 * everything and allows it, saying what the risk is instead of enforcing it.
 *
 * Two refusals ignore this setting entirely, because neither is a risk
 * anybody can sensibly accept: a filesystem living in memory loses the stacks
 * at the next reboot for no benefit, and a whole share as the stack root makes
 * every folder in that share read as a stack, which is wrong rather than
 * daring.
 */
function staxx_placement_guided(): bool {
  return trim((string)(staxx_cfg()['PLACEMENT_RULES'] ?? 'guided')) !== 'open';
}

/**
 * Why this path is a risky home for the stacks, or '' if it is not.
 *
 * One place computes it and the two modes present it differently: guided turns
 * it into a refusal, open shows it as a warning beside the box. Written this
 * way round so the two can never drift into disagreeing about what counts as
 * risky — which is exactly what happened before, when the suggestions checked
 * a share's mover setting and a typed path did not.
 */
function staxx_placement_risk(string $path): string {
  $norm = rtrim(trim($path), '/');
  if (strpos($norm, '/mnt/') !== 0) return '';

  $first = explode('/', substr($norm, strlen('/mnt/')), 2)[0];
  if ($first === 'user0' || preg_match('/^disk[0-9]+$/', $first)) {
    return 'that is one disk of the array, which has no redundancy of its own and bypasses the '
         . 'rules the array uses to decide where files go';
  }
  if ($first === 'disks' || $first === 'remotes') {
    return 'that is '.($first === 'disks' ? 'an unassigned drive' : 'a network mount')
         . ', which can be missing at the next boot with the stacks inside it';
  }

  // The share the path lands in, which is the second segment under /mnt for a
  // pool path and for the share-layer form alike.
  if (preg_match('#^/mnt/[^/]+/([^/]+)#', $norm, $m)) {
    $drain = staxx_share_drain_reason($m[1]);
    if ($drain !== '') return $drain;
  }
  return '';
}

/**
 * The one folder StaXX keeps everything in — stacks, archives and (from
 * Phase 4) its own settings and state. A single setting rather than one per
 * folder, because that is one place somebody can open and understand, and
 * because the folder names beneath it are fixed rather than configured.
 *
 * '' means nobody has chosen where StaXX's data lives yet. That is not a
 * missing default to paper over — it is the signal the first-run dialog and
 * every gate ahead of a derived folder key off, so it must never be turned
 * into a fallback path here.
 */
function staxx_store_root(): string {
  $v = trim((string)(staxx_cfg()['STORE_ROOT'] ?? ''));
  return $v === '' ? '' : rtrim($v, '/');
}

/**
 * Has a data store been chosen? The gate every call site ahead of a derived
 * folder (staxx_stack_root(), staxx_archive_root(), and the config folder
 * from Phase 4) checks before touching it, so "unchosen" never reaches code
 * that assumes a real path.
 */
function staxx_store_ready(): bool {
  return staxx_store_root() !== '';
}

/**
 * Where a removed stack's zip goes. '' when no store has been chosen —
 * callers must check staxx_store_ready() rather than treating an empty
 * string as a workable path.
 */
function staxx_archive_root(): string {
  $store = staxx_store_root();
  return $store === '' ? '' : $store.'/archives';
}

/**
 * Where StaXX writes everything for itself — settings, icons, state. ''
 * when no store has been chosen, same as staxx_stack_root() and
 * staxx_archive_root().
 */
function staxx_config_root(): string {
  $store = staxx_store_root();
  return $store === '' ? '' : $store.'/config';
}

/** The real settings file, inside the store. '' when no store is chosen. */
function staxx_settings_file(): string {
  $root = staxx_config_root();
  return $root === '' ? '' : $root.'/'.STAXX_PLUGIN.'.cfg';
}

/**
 * Is the store not just chosen, but actually there right now? Different
 * from staxx_store_ready(), which only asks whether a value has been typed
 * in — a store on a pool is simply not there yet while the array is still
 * coming up, and a caller that needs to read or write real settings has to
 * tell that apart from "nobody has chosen one".
 */
function staxx_store_reachable(): bool {
  $root = staxx_store_root();
  return $root !== '' && is_dir($root) && is_readable($root);
}

/**
 * Is StaXX quietly running on shipped defaults instead of the settings the
 * user actually chose? True only once a store exists to disagree with —
 * an unchosen store isn't "degraded", it's the first-run state, which is a
 * different message with a different remedy.
 */
function staxx_settings_degraded(): bool {
  return staxx_store_ready() && !staxx_store_reachable();
}

/**
 * Read the plugin config: the shipped defaults, then the store's own
 * settings file (once the store is reachable), then the flash pointer file
 * — but only the three keys STAXX_FLASH_KEYS names are taken from flash.
 * Later layers override earlier ones. Any other key found in the flash file
 * is ignored rather than obeyed, so a stale key an older build left behind,
 * or a hand edit, cannot quietly override the real setting sitting in the
 * store. Returns a flat key => string map.
 */
function staxx_cfg(): array {
  static $cfg = null;
  if ($cfg !== null) return $cfg;

  // INI_SCANNER_RAW: every value this plugin ever writes is already quoted,
  // so the raw scanner changes nothing for a file it wrote itself. What it
  // fixes is a hand-edited line with no quotes at all — the normal scanner
  // rewrites a bare true/false/on/off/yes/no to a PHP bool and interpolates
  // ${...}, so an unquoted "ICON_FETCH=false" came back as PHP false, and
  // (string) that is '' rather than the literal word "false" every reader
  // here compares against — leaving a setting switched off read as still on.
  // The same reasoning applies to the store's settings file below, which is
  // written the same way and can carry the same hand edits.
  $defaults = @parse_ini_file(STAXX_ROOT.'/default.cfg', false, INI_SCANNER_RAW) ?: [];

  $flashRaw = @parse_ini_file(STAXX_CFG, false, INI_SCANNER_RAW) ?: [];
  $flash    = array_intersect_key($flashRaw, array_flip(STAXX_FLASH_KEYS));

  // The store's own path is worked out from the flash file directly here,
  // rather than by calling staxx_store_root()/staxx_settings_file() — those
  // read STORE_ROOT through this very function, and calling them while it is
  // still building its own cache would recurse forever.
  $store    = [];
  $storeRoot = rtrim(trim((string)($flashRaw['STORE_ROOT'] ?? '')), '/');
  if ($storeRoot !== '' && is_dir($storeRoot) && is_readable($storeRoot)) {
    $store = @parse_ini_file($storeRoot.'/config/'.STAXX_PLUGIN.'.cfg', false, INI_SCANNER_RAW) ?: [];
  }

  return $cfg = array_merge($defaults, $store, $flash);
}

function staxx_cfg_bool(string $key): bool {
  return (staxx_cfg()[$key] ?? 'false') === 'true';
}

/**
 * Where a request that wants StaXX's own view of the world should land —
 * '/StaXX' once either the header-menu button or the Docker-tab takeover is
 * on, '/Docker/Stacks' otherwise. Matches the marker-file test Stacks.page
 * and StaXX.page's own Cond expressions make, so a caller such as the
 * AddContainer shadow does not re-derive it and risk landing on a page that
 * Cond has actually made unavailable.
 */
function staxx_view_url(): string {
  return (is_file(STAXX_MARKER_HEADER_MENU) || is_file(STAXX_MARKER_TAKEOVER_DOCKER_TAB))
    ? '/StaXX' : '/Docker/Stacks';
}

/**
 * The depth this request currently holds each lock at, shared between
 * staxx_mkdir_lock() and staxx_mkdir_unlock() via a reference to the same
 * static array — what makes the lock re-entrant within one request (below).
 */
function &staxx_mkdir_lock_depth(): array {
  static $depth = [];
  return $depth;
}

/**
 * A short-lived mutex for a read-modify-write against one file, the same
 * atomic-mkdir trick staxx_update_lock() uses for its own, longer-lived pass:
 * mkdir() either succeeds or fails outright, with no race window either way.
 * Callers here only ever hold it across a read plus a write, so a lock older
 * than 5 seconds is read as abandoned by a request that died mid-write —
 * never one that is still going — and is taken over instead.
 *
 * Re-entrant within one request: a function that already holds this path's
 * lock and calls another function that asks for the same one (projection
 * called from a toggle it is already locked for, say) gets it back
 * immediately rather than waiting out its own lock and timing out.
 */
function staxx_mkdir_lock(string $path, string &$error, int $tries = 40): bool {
  $error = '';
  $depth = &staxx_mkdir_lock_depth();

  if (($depth[$path] ?? 0) > 0) { $depth[$path]++; return true; }

  $lock = $path.'.lock';
  for ($i = 0; $i < $tries; $i++) {
    if (@mkdir($lock, 0700)) { $depth[$path] = 1; return true; }

    $age = is_dir($lock) ? (time() - (int)@filemtime($lock)) : 0;
    if ($age > 5) { @rmdir($lock); continue; }

    usleep(50000); // 50ms — a read-modify-write here is over almost instantly
  }

  $error = 'Could not get an exclusive lock on '.$path.' — another save may be in progress. Try again.';
  return false;
}

/** Release a lock taken with staxx_mkdir_lock(). Safe to call even if it was never held. */
function staxx_mkdir_unlock(string $path): void {
  $depth = &staxx_mkdir_lock_depth();
  if (($depth[$path] ?? 0) <= 0) return;

  if (--$depth[$path] > 0) return;
  unset($depth[$path]);
  @rmdir($path.'.lock');
}

/**
 * Run a command and return its output, with a hard time limit.
 *
 * Nothing here may hang. A web request that waits forever on `docker` is worse
 * than one that fails: the page sits there saying nothing, and the failure is
 * invisible. `timeout` kills the command and we carry on with what we have.
 *
 * @param string $cmd     already-quoted command line
 * @param int    $seconds how long to allow before killing it
 * @param int|null $code  set to the exit status; 124 means it timed out
 */
function staxx_sh(string $cmd, int $seconds = 10, ?int &$code = null): string {
  $lines = [];
  $code  = 1;

  // The command is handed to `sh -c` as a single argument rather than pasted
  // after `timeout`. Written the other way, `timeout 120 cd /somewhere && foo`
  // parses as "time-limit the cd" — and since cd is a shell builtin with no
  // program to run, it fails, the && short-circuits, and foo never executes
  // while the whole thing still reports success. That silently skipped the
  // "stop the containers" step of deleting a stack.
  //
  // stdin is closed explicitly too: a command that decides to prompt would
  // otherwise wait forever on a terminal that does not exist, holding a PHP
  // worker open with it.
  @exec(
    'timeout -k 2 '.(int)$seconds.' sh -c '.escapeshellarg($cmd).' </dev/null 2>/dev/null',
    $lines,
    $code
  );
  return implode("\n", $lines);
}

/**
 * Absolute path to the docker binary. PHP's environment is not a login shell,
 * so PATH cannot be relied on — resolve it once and call it explicitly.
 */
function staxx_docker_bin(): string {
  static $bin = null;
  if ($bin !== null) return $bin;
  foreach (['/usr/bin/docker', '/usr/local/bin/docker'] as $path) {
    if (is_file($path) && is_executable($path)) return $bin = $path;
  }
  return $bin = 'docker';
}

/**
 * Every location compose is known to install to.
 *
 * The first five are Docker's own CLI plugin search path, in the order Docker
 * itself uses. The last two are the older standalone `docker-compose` command,
 * which is a separate program rather than a Docker plugin.
 *
 * @return string[]
 */
function staxx_compose_paths(): array {
  return [
    '/root/.docker/cli-plugins/docker-compose',
    '/usr/local/lib/docker/cli-plugins/docker-compose',
    '/usr/local/libexec/docker/cli-plugins/docker-compose',
    '/usr/lib/docker/cli-plugins/docker-compose',
    '/usr/libexec/docker/cli-plugins/docker-compose',
    '/usr/local/bin/docker-compose',
    '/usr/bin/docker-compose',
  ];
}

/**
 * What compose is available, and in what form.
 *
 * Searching known directories is a guess — another plugin may have installed
 * compose somewhere this list does not cover, and the list will rot. So the
 * decision rests on running it: if `docker compose version` answers, compose
 * works, wherever it happens to live. The path is then looked up only so the
 * UI can say where it found it.
 *
 * Unraid does not ship compose itself, but the Compose Manager plugin does,
 * and a user may have installed it by hand. Any of those count.
 *
 * @return array{available:bool, version:string, path:string, form:string}
 *         form is 'plugin' (`docker compose`), 'standalone' (`docker-compose`)
 *         or '' when unavailable.
 */
function staxx_compose(): array {
  static $info = null;
  if ($info !== null) return $info;

  $docker = escapeshellarg(staxx_docker_bin());
  $info = ['available' => false, 'version' => '', 'path' => '', 'form' => ''];

  $version = trim(staxx_sh($docker.' compose version --short', 8));
  if ($version !== '') {
    $info['available'] = true;
    $info['version']   = $version;
    $info['form']      = 'plugin';
  } else {
    $version = trim(staxx_sh('docker-compose version --short', 8));
    if ($version !== '') {
      $info['available'] = true;
      $info['version']   = $version;
      $info['form']      = 'standalone';
    }
  }

  foreach (staxx_compose_paths() as $path) {
    if (is_file($path) && is_executable($path)) { $info['path'] = $path; break; }
  }
  if ($info['path'] === '') {
    $found = trim(staxx_sh('command -v docker-compose', 5));
    if ($found !== '' && is_file($found)) $info['path'] = $found;
  }

  return $info;
}

/** Absolute path to compose, or '' if it could not be located on disk. */
function staxx_compose_bin(): string {
  return staxx_compose()['path'];
}

function staxx_compose_version(): string {
  return staxx_compose()['version'];
}

function staxx_docker_running(): bool {
  $pidfile = '/var/run/dockerd.pid';
  if (!is_file($pidfile)) return false;
  return is_dir('/proc/'.trim((string)@file_get_contents($pidfile)));
}

/**
 * Every network this server's docker knows about. The Form view's Network
 * mode dropdown offers these alongside the three built-ins (bridge/host/none),
 * which still work with an empty list — so a Docker that is down is not an
 * error here, just nothing extra to offer.
 *
 * A network compose made for a stack of its own is left out. Those come and
 * go with the stack that owns them, so offering "multi-tier_default" as
 * somewhere to attach a container is offering a name that may not exist
 * tomorrow. They are told apart by the label compose stamps on them, not by
 * their "_default" name, which anyone is free to use by hand.
 *
 * @return array<int, array{name:string, driver:string}>
 */
function staxx_docker_networks(): array {
  if (!staxx_docker_running()) return [];

  $fmt = '{{.Name}}|{{.Driver}}|{{.Labels}}';
  $out = staxx_sh(
    escapeshellarg(staxx_docker_bin()).' network ls --format '.escapeshellarg($fmt), 10
  );

  $networks = [];
  foreach (explode("\n", trim($out)) as $line) {
    if ($line === '') continue;
    [$name, $driver, $labels] = array_pad(explode('|', $line, 3), 3, '');
    $name = trim($name);
    if ($name === '' || strpos($labels, 'com.docker.compose.project') !== false) continue;
    $networks[] = ['name' => $name, 'driver' => trim($driver)];
  }
  return $networks;
}

/**
 * Every image:tag this server's docker has already pulled, offered while
 * typing an `image:` field so a common case does not need retyping.
 *
 * `<none>` tags mark dangling layers and half-finished builds — never
 * something anyone would type into an `image:` field — so they are filtered
 * out rather than shown as noise.
 *
 * @return string[] sorted, de-duplicated "repository:tag" strings
 */
function staxx_docker_images(): array {
  if (!staxx_docker_running()) return [];

  $out = staxx_sh(
    escapeshellarg(staxx_docker_bin()).' images --format '.escapeshellarg('{{.Repository}}:{{.Tag}}'), 10
  );

  $images = [];
  foreach (explode("\n", trim($out)) as $line) {
    $line = trim($line);
    if ($line === '' || strpos($line, '<none>') !== false) continue;
    $images[$line] = true; // de-duplicate as we go
  }
  $images = array_keys($images);
  sort($images);
  return $images;
}

/**
 * Build a GET request to another server, run it and decode its JSON body.
 * Factored out of staxx_image_tags(), staxx_hub_search() and
 * staxx_hub_repo(), which all did this by hand.
 *
 * Every header is passed through escapeshellarg() same as the URL — nothing
 * here trusts a caller's string more than it trusts a query parameter.
 *
 * @param  string   $url        already-built, unescaped
 * @param  string[] $headers    each a whole "Name: value" line
 * @param  int      $maxTime    curl's own --max-time, seconds
 * @param  int      $shSeconds  staxx_sh()'s hard limit, seconds
 * @param  int      $maxBytes   refuse a body larger than this; 0 for no limit
 * @return array|null decoded body, or null on anything wrong at all —
 *                     no network, DNS, a non-JSON body, an HTTP error, a
 *                     body over $maxBytes
 */
function staxx_hub_json(string $url, array $headers = [], int $maxTime = 8, int $shSeconds = 12,
                        int $maxBytes = 0): ?array {
  // --proto bounds the transport whatever the caller passes: a token realm
  // read out of a remote registry's own reply reaches this function, and
  // without this a hostile registry could name file:// or any other scheme
  // curl happens to support and have the server read it back.
  $cmd = 'curl -fsSL --proto \'=https,http\' --max-time '.$maxTime;

  // Some answers are far bigger than the question. GitHub's commit-comparison
  // reply carries every changed file's whole diff alongside the commit list,
  // so asking "which commits" downloads the code as well — 145 KB for nine
  // commits, measured, and its ceiling is 250. The whole body is held in
  // memory by staxx_sh() and then again by json_decode(), on a machine whose
  // webGUI has to stay responsive, so a caller that knows its answer should
  // be small says so and curl refuses a bigger one outright.
  if ($maxBytes > 0) $cmd .= ' --max-filesize '.$maxBytes;

  foreach ($headers as $header) $cmd .= ' -H '.escapeshellarg($header);
  $cmd .= ' '.escapeshellarg($url);

  // A timeout a little above curl's own --max-time, so curl reports the
  // failure itself rather than being killed mid-flight by staxx_sh().
  $out  = staxx_sh($cmd, $shSeconds);
  $data = json_decode($out, true);
  return is_array($data) ? $data : null;
}

/**
 * Canonical Docker Hub repository path for an image reference — the
 * normalisation staxx_image_tags() and staxx_hub_repo() both need, so
 * it lives here once rather than a third time. A digest and a tag are
 * stripped first: a reference arriving here from an `image:` line will
 * usually carry one, and the shape check below rejects a colon.
 *
 * A bare single-segment name (`postgres`) is Docker's own shorthand for
 * `library/postgres`, where Hub keeps official images. `lscr.io/linuxserver/x`
 * and `ghcr.io/linuxserver/x` are mirrors of the same image Hub already
 * indexes under `linuxserver/x`, and worth the two lines because linuxserver
 * is what most Unraid users run. Any other host-qualified name — a private
 * registry, a self-hosted one, anything else — is refused outright: this
 * plugin does not guess at credentials or API shapes it has never seen.
 *
 * @return string canonical "namespace/name", or '' when refused
 */
function staxx_hub_repo_path(string $image): string {
  $repo = trim($image);
  if ($repo === '') return '';

  $repo = preg_replace('/@sha256:[0-9a-f]+$/', '', $repo);
  $slash = strrpos($repo, '/');
  $colon = strrpos($repo, ':');
  if ($colon !== false && ($slash === false || $colon > $slash)) $repo = substr($repo, 0, $colon);

  $parts = explode('/', $repo);
  if (count($parts) === 3 && $parts[1] === 'linuxserver' && in_array($parts[0], ['lscr.io', 'ghcr.io'], true)) {
    $repo = $parts[1].'/'.$parts[2];
  } elseif (strpos($parts[0], '.') !== false || strpos($parts[0], ':') !== false) {
    // Host-qualified and not one of the two mirrors above — decline rather
    // than guess at a registry we know nothing about.
    return '';
  } elseif (strpos($repo, '/') === false) {
    $repo = 'library/'.$repo;
  }

  // Shape: [namespace/]name, lowercase alphanumerics with . _ - separators,
  // at most one slash. The 'D' modifier keeps $ from also matching just
  // before a trailing newline, which is PCRE's default and would otherwise
  // let a name end in "\n<anything>" slip past this check.
  if (!preg_match('#^[a-z0-9]+(?:[._-][a-z0-9]+)*(?:/[a-z0-9]+(?:[._-][a-z0-9]+)*)?$#D', $repo)) {
    return '';
  }
  return $repo;
}

/**
 * Tags Docker Hub has published for a repository, offered while typing an
 * `image:` field's tag half. This is the only thing in the plugin that talks
 * to a server other than this one, so the repo string is checked twice before
 * it gets anywhere near a URL: first for shape (inside staxx_hub_repo_path()),
 * then quoted on top of that — belt and braces, not either on its own.
 *
 * Every failure — no network, DNS, a non-JSON body, an HTTP error, a reply
 * shaped nothing like tags — returns the same empty array rather than an
 * exception or a message. This call runs while someone is mid-keystroke;
 * surfacing a registry error in the middle of typing would be noise for a
 * field that still works perfectly well as free text.
 *
 * @return string[] up to 50 tag names, most recently updated first
 */
function staxx_image_tags(string $repo): array {
  $repo = staxx_hub_repo_path($repo);
  if ($repo === '') return [];

  $url  = 'https://hub.docker.com/v2/repositories/'.$repo.'/tags?page_size=50&ordering=last_updated';
  $data = staxx_hub_json($url);
  if ($data === null || !isset($data['results']) || !is_array($data['results'])) return [];

  $tags = [];
  foreach ($data['results'] as $result) {
    if (!is_array($result) || !isset($result['name']) || !is_string($result['name'])) continue;
    $tags[] = $result['name'];
    if (count($tags) >= 50) break;
  }
  return $tags;
}

/**
 * Docker Hub's own search, offered in the Apps dialog as a second source
 * alongside the Community Applications catalogue — CA is a curated list, not
 * an index of everything on Hub, so a query that misses there can still land
 * here. Takes the text typed into the search box.
 *
 * Same shape check, same `curl` through staxx_sh() and the same "return
 * an empty array for every failure" contract as staxx_image_tags(), and
 * for the same reason: this runs on every keystroke, live against Hub, with
 * no catalogue behind it to fall back on. A dropped connection, a non-JSON
 * body, an HTTP error — all of it comes back as no results rather than an
 * error, because a search that quietly finds nothing is better than one that
 * interrupts typing to complain.
 *
 * @return array<int, array{name:string, desc:string, stars:int, pulls:int, official:bool}> up to 25 hits
 */
function staxx_hub_search(string $q): array {
  // Lower-cased before the shape check, not after: a repository name on Hub
  // is always lower case, so "Jellyfin" is the same search as "jellyfin" —
  // but the check below rejects a capital letter, which would have made a
  // capitalised query silently return nothing while the catalogue beside it
  // went on matching perfectly.
  $q = strtolower(trim($q));
  if (strlen($q) < 3) return [];

  // Same shape a repository name is held to: lowercase alphanumerics with
  // . _ - separators, at most one slash. A search box invites more than
  // that, but anything wider is not a repository fragment worth sending to
  // this particular endpoint. The 'D' modifier keeps $ from also matching
  // just before a trailing newline, PCRE's default.
  if (!preg_match('#^[a-z0-9]+(?:[._-][a-z0-9]+)*(?:/[a-z0-9]+(?:[._-][a-z0-9]+)*)?$#D', $q)) {
    return [];
  }

  $url  = 'https://hub.docker.com/v2/search/repositories/?query='.rawurlencode($q).'&page_size=25';
  $data = staxx_hub_json($url);
  if ($data === null || !isset($data['results']) || !is_array($data['results'])) return [];

  $hits = [];
  foreach ($data['results'] as $result) {
    if (!is_array($result) || !isset($result['repo_name']) || !is_string($result['repo_name'])) continue;
    $hits[] = [
      'name'     => $result['repo_name'],
      'desc'     => trim((string)($result['short_description'] ?? '')),
      'stars'    => (int)($result['star_count'] ?? 0),
      'pulls'    => (int)($result['pull_count'] ?? 0),
      'official' => (bool)($result['is_official'] ?? false),
    ];
    if (count($hits) >= 25) break;
  }
  return $hits;
}

/**
 * Docker Hub's own description of a repository, offered as an image's
 * starting documentation when nothing more specific exists. One request,
 * same failure contract as staxx_hub_search(): anything wrong at all —
 * an unresolvable name, no network, an HTTP error, a non-JSON body — comes
 * back as an empty array.
 *
 * The README is capped at 256 KB: it is third-party text of unbounded size
 * and it is about to be handed to a browser.
 *
 * @return array{readme?:string, description?:string}
 */
function staxx_hub_repo(string $image): array {
  $repo = staxx_hub_repo_path($image);
  if ($repo === '') return [];

  $data = staxx_hub_json('https://hub.docker.com/v2/repositories/'.$repo.'/');
  if ($data === null) return [];

  return [
    'readme'      => substr((string)($data['full_description'] ?? ''), 0, 256 * 1024),
    'description' => (string)($data['description'] ?? ''),
  ];
}

/**
 * Ports, volumes and labels straight out of the image's own registry
 * manifest — the fallback used only when the image's Hub README gave
 * nothing usable, since it is four chained requests rather than one: a
 * short-lived anonymous pull token, the multi-architecture manifest index,
 * the amd64/linux entry's own manifest, then the config blob it points at.
 *
 * A single-architecture image answers the manifest-index request with no
 * "manifests" list at all, just its own "config" — that reply is used
 * directly rather than treated as a failure. Attestation entries in a
 * multi-architecture index report architecture/os "unknown" and are skipped
 * while looking for amd64/linux.
 *
 * The blob request redirects to a CDN, so following redirects matters —
 * staxx_hub_json() always does (curl -fsSL), which is exactly why: without
 * it the blob comes back empty and the whole chain looks broken for no
 * visible reason.
 *
 * Never returns Env: those are the image's build-time internals (PATH,
 * S6_VERBOSITY, ...) baked in when it was built, and writing them into a
 * compose file pins a snapshot that breaks the day the image changes them.
 *
 * @return array{ports?:string[], volumes?:string[], labels?:array<string,string>}
 */
function staxx_registry_config(string $image): array {
  $repo = staxx_hub_repo_path($image);
  if ($repo === '') return [];

  $tag = 'latest';
  $ref = trim($image);
  $slash = strrpos($ref, '/');
  $colon = strrpos($ref, ':');
  if ($colon !== false && ($slash === false || $colon > $slash)) $tag = substr($ref, $colon + 1);

  $token = staxx_hub_json(
    'https://auth.docker.io/token?service=registry.docker.io&scope=repository:'.$repo.':pull',
    [], 6, 8
  );
  if ($token === null || (string)($token['token'] ?? '') === '') return [];
  $bearer = 'Authorization: Bearer '.$token['token'];

  $index = staxx_hub_json(
    'https://registry-1.docker.io/v2/'.$repo.'/manifests/'.rawurlencode($tag),
    [$bearer, 'Accept: application/vnd.oci.image.index.v1+json,'
             .'application/vnd.docker.distribution.manifest.list.v2+json,'
             .'application/vnd.docker.distribution.manifest.v2+json'],
    6, 8
  );
  if ($index === null) return [];

  $manifest = $index;
  if (isset($index['manifests']) && is_array($index['manifests'])) {
    $digest = '';
    foreach ($index['manifests'] as $entry) {
      $platform = is_array($entry['platform'] ?? null) ? $entry['platform'] : [];
      if (($platform['architecture'] ?? '') === 'amd64' && ($platform['os'] ?? '') === 'linux') {
        $digest = (string)($entry['digest'] ?? '');
        break;
      }
    }
    if ($digest === '') return [];

    $manifest = staxx_hub_json(
      'https://registry-1.docker.io/v2/'.$repo.'/manifests/'.$digest,
      [$bearer, 'Accept: application/vnd.oci.image.manifest.v1+json'],
      6, 8
    );
    if ($manifest === null) return [];
  }

  $configDigest = (string)($manifest['config']['digest'] ?? '');
  if ($configDigest === '') return [];

  $blob = staxx_hub_json(
    'https://registry-1.docker.io/v2/'.$repo.'/blobs/'.$configDigest,
    [$bearer], 6, 8
  );
  if ($blob === null) return [];

  $config = is_array($blob['config'] ?? null) ? $blob['config'] : [];
  return [
    'ports'   => array_keys(is_array($config['ExposedPorts'] ?? null) ? $config['ExposedPorts'] : []),
    'volumes' => array_keys(is_array($config['Volumes'] ?? null) ? $config['Volumes'] : []),
    'labels'  => is_array($config['Labels'] ?? null) ? $config['Labels'] : [],
  ];
}

/**
 * PLAN_90 Stage 1: the Accept list a manifest request must send, factored
 * out to one definition so staxx_registry_digest() and
 * staxx_registry_config() above can never drift apart. Order is
 * load-bearing — the OCI/multi-architecture index has to come first, or a
 * registry hands back a single-architecture manifest whose digest never
 * matches the multi-architecture one docker reports locally, and every
 * update pass invents a phantom update out of nothing.
 */
function staxx_registry_accept(): string {
  return 'application/vnd.oci.image.index.v1+json,'
        .'application/vnd.docker.distribution.manifest.list.v2+json,'
        .'application/vnd.docker.distribution.manifest.v2+json';
}

/**
 * A short, stable fingerprint of staxx_registry_accept(), stored alongside a
 * remembered entity tag (PLAN_90 Stage 2) so that changing the Accept list
 * later invalidates every stored tag instead of silently comparing it
 * against a manifest shape it was never actually issued for.
 */
function staxx_registry_accept_id(): string {
  return substr(sha1(staxx_registry_accept()), 0, 8);
}

/**
 * Split an image reference into registry host, repository path, tag and
 * digest — pure, no network, so it can be tested exhaustively. Docker Hub's
 * own shorthands still apply (`postgres` -> docker.io + library/postgres).
 *
 * The host test is the same one staxx_image_registry() in Updates.php uses
 * (a first path segment carrying a dot or a colon is a host name); the two
 * agree deliberately; a caller that asks "which registry" and a caller that
 * asks "split this reference" must never answer differently for the same
 * string. Unlike staxx_hub_repo_path(), a host-qualified reference keeps its
 * own host rather than being rewritten to Hub — that rewrite exists only so
 * the form editor can read linuxserver's config labels off Hub, and reusing
 * it here would ask the wrong registry for a digest comparison.
 *
 * Every value returned here is later spliced into a URL, so refusal is a
 * security boundary, not tidiness. Anything that fails the shape checks
 * comes back as every key '' — callers must test repo === '' for refusal
 * rather than trust a partial answer.
 *
 * @return array{host:string, repo:string, tag:string, digest:string}
 */
function staxx_registry_ref(string $image): array {
  $refused = ['host' => '', 'repo' => '', 'tag' => '', 'digest' => ''];

  $ref = trim($image);
  if ($ref === '') return $refused;

  $digest = '';
  if (preg_match('/@(sha256:[0-9a-f]{64})$/', $ref, $m)) {
    $digest = $m[1];
    $ref = substr($ref, 0, -strlen($m[0]));
  }

  // A tag sits after the last colon only when that colon comes after the
  // last slash — otherwise it is a host's own port, e.g. reg.example.com:5000.
  $slash = strrpos($ref, '/');
  $colon = strrpos($ref, ':');
  $tag = 'latest';
  if ($colon !== false && ($slash === false || $colon > $slash)) {
    $tag = substr($ref, $colon + 1);
    $ref = substr($ref, 0, $colon);
  }
  // These land in a URL path segment, so a slash, whitespace or control
  // character in a tag is refused rather than passed through.
  if ($tag === '' || preg_match('/[\/\s\x00-\x1f]/', $tag)) return $refused;

  $parts = explode('/', $ref);
  $first = $parts[0];
  if (strpos($first, '.') !== false || strpos($first, ':') !== false || $first === 'localhost') {
    $host = strtolower($first);
    $repo = implode('/', array_slice($parts, 1));
  } else {
    $host = 'docker.io';
    $repo = $ref;
  }

  // Docker's own shorthand: a bare name on the implied default registry is
  // an official image, kept under library/.
  if ($host === 'docker.io' && $repo !== '' && strpos($repo, '/') === false) {
    $repo = 'library/'.$repo;
  }

  if ($host === '' || $repo === '' || !preg_match('#^[a-z0-9._/-]+$#D', $repo)) return $refused;

  return ['host' => $host, 'repo' => $repo, 'tag' => $tag, 'digest' => $digest];
}

/**
 * The API host a registry host is actually reached at. Docker Hub's own
 * host (docker.io) never answers registry requests itself — the registry
 * lives at registry-1.docker.io — and every other host is used exactly as
 * written, including a private or self-hosted registry.
 */
function staxx_registry_api_host(string $host): string {
  return $host === 'docker.io' ? 'registry-1.docker.io' : $host;
}

/**
 * Has this server's owner vouched for $host? REGISTRY_TRUST is a
 * comma-separated list of exact host names (optionally host:port), matched
 * case-folded and nothing else — deliberately no wildcard, no prefix or
 * suffix matching, no subdomain inference. A host not named exactly is not
 * trusted, full stop; that is the whole safety property this setting has.
 */
function staxx_registry_trusted(string $host): bool {
  $list = trim((string)(staxx_cfg()['REGISTRY_TRUST'] ?? ''));
  if ($list === '') return false;
  $host = strtolower(trim($host));
  foreach (explode(',', $list) as $entry) {
    if (strtolower(trim($entry)) === $host) return true;
  }
  return false;
}

/**
 * Per-host memo of which scheme actually answered staxx_registry_challenge()
 * — https always, unless a trusted host only answered over plain http. Read
 * with no argument; written by passing $set, which is also what is returned,
 * so the challenge probe can record-and-return in one call. Same static-array
 * shape as the other per-host caches in this file.
 */
function staxx_registry_scheme(string $host, string $set = ''): string {
  static $scheme = [];
  if ($set !== '') { $scheme[$host] = $set; return $set; }
  return $scheme[$host] ?? 'https';
}

/** curl flags a trusted host earns: relaxed certificate checking only. */
function staxx_registry_curl_opts(string $host): string {
  return staxx_registry_trusted($host) ? ' -k' : '';
}

/**
 * The authentication challenge one registry host answers with, parsed —
 * realm, service, and whether it wants no authentication at all.
 *
 * Cached per HOST, deliberately, and that is the whole point of the
 * function: the challenge on /v2/ is a property of the registry, not of any
 * one repository, so folding it into the per-repository token cache cost one
 * extra round trip per image — sixty of them on a sixty-container box, in
 * the pass whose entire purpose is to stop making needless requests.
 *
 * $why is '' on success, 'auth' when the registry wants a scheme StaXX
 * cannot speak, 'failed' for no network or a malformed challenge.
 *
 * The challenge's own scope is not returned: it is host-level, and at least
 * one real registry fills it with a placeholder repository name. The caller
 * builds the scope from the repository it actually wants.
 *
 * @return array{realm:string, service:string, open:bool}
 */
function staxx_registry_challenge(string $host, string &$why = null): array {
  static $cache = [];
  $why = '';
  $empty = ['realm' => '', 'service' => '', 'open' => false];

  if (isset($cache[$host])) { $why = $cache[$host]['why']; return $cache[$host]['challenge']; }

  $keep = function (array $challenge, string $reason) use (&$cache, $host, &$why) {
    $cache[$host] = ['challenge' => $challenge, 'why' => $reason];
    $why = $reason;
    return $challenge;
  };

  $apiHost = staxx_registry_api_host($host);
  $trusted = staxx_registry_trusted($host);

  $probe = staxx_sh(
    'curl -sS -L --proto '.escapeshellarg('=https,http').' --max-time 6'.staxx_registry_curl_opts($host)
      .' -D /dev/stdout -o /dev/null -X GET '.escapeshellarg('https://'.$apiHost.'/v2/'),
    10
  );
  $scheme = 'https';
  // Only a trusted host gets a second try, and only when https got no
  // answer at all (a throwaway registry with no certificate) — an
  // untrusted host's request count and behaviour are unchanged from today.
  if ($probe === '' && $trusted) {
    $scheme = 'http';
    $probe = staxx_sh(
      'curl -sS -L --proto '.escapeshellarg('=https,http').' --max-time 6 -D /dev/stdout -o /dev/null -X GET '
        .escapeshellarg('http://'.$apiHost.'/v2/'),
      10
    );
  }
  staxx_registry_scheme($host, $scheme);
  if ($probe === '') return $keep($empty, 'failed');

  if (!preg_match('/^www-authenticate:\s*(.+)$/im', $probe, $hm)) {
    // No challenge at all. A registry that serves /v2/ with no 401 needs no
    // auth for an anonymous pull — the caller asks the manifest directly.
    if (preg_match('#^HTTP/\S+\s+200#', trim($probe))) {
      return $keep(['realm' => '', 'service' => '', 'open' => true], '');
    }
    return $keep($empty, 'failed');
  }

  $challenge = trim($hm[1]);
  if (stripos($challenge, 'bearer') !== 0) return $keep($empty, 'auth');

  $params = [];
  if (preg_match_all('/(\w+)="([^"]*)"/', $challenge, $pm, PREG_SET_ORDER)) {
    foreach ($pm as $p) $params[$p[1]] = $p[2];
  }

  // The realm is remote-controlled input about to become a URL. Refused
  // outright unless it is itself https:// — a plain-http realm would leak
  // the challenge, and no real registry uses one. This is a hostile-redirect
  // guard, not a transport preference, so a trusted host's plain-http opt-in
  // deliberately does not relax it.
  $realm = (string)($params['realm'] ?? '');
  if (!preg_match('#^https://#i', $realm)) return $keep($empty, 'failed');

  return $keep([
    'realm'   => $realm,
    'service' => (string)($params['service'] ?? ''),
    'open'    => false,
  ], '');
}

/**
 * Complete the OCI/Docker token challenge for one host+repo and return a
 * bearer token, or '' when none is available or needed. Cached per
 * host+repo for the life of the process, because a token costs as much as
 * the manifest question itself and would otherwise be paid once per image,
 * every pass, on a sixty-image box. The challenge it builds on is cached
 * separately, per host — see staxx_registry_challenge().
 *
 * $why distinguishes the reasons a caller must treat differently:
 *   ''      success — either a token was obtained, or the registry needs
 *           none at all (an unauthenticated manifest request is expected
 *           to work).
 *   'auth'  the registry demands credentials StaXX does not have. Not a
 *           failure to retry — the caller should fall through to the
 *           docker CLI, which may hold credentials of its own.
 *   'failed' anything else: no network, a malformed challenge, a token
 *           endpoint that refused for an unrelated reason.
 *
 * The realm named in the challenge is remote-controlled input that becomes
 * a URL. staxx_hub_json()'s own --proto guard is kept as a second line of
 * defence but is deliberately not relied on alone: a realm that is not
 * itself an https:// URL is refused outright, here, before it is ever
 * built into a request.
 */
function staxx_registry_token(string $host, string $repo, string &$why = null): string {
  static $cache = [];
  $key = $host."\0".$repo;
  if (isset($cache[$key])) { $why = $cache[$key]['why']; return $cache[$key]['token']; }

  $why = '';
  $store = function (string $token, string $why) use (&$cache, $key) {
    $cache[$key] = ['token' => $token, 'why' => $why];
    return $token;
  };

  $chWhy = '';
  $challenge = staxx_registry_challenge($host, $chWhy);
  if ($chWhy !== '') { $why = $chWhy; return $store('', $chWhy); }
  // An open registry needs no bearer at all — an empty token with an empty
  // reason means "ask the manifest directly", which is not a failure.
  if (!empty($challenge['open'])) return $store('', '');

  $realm   = (string)$challenge['realm'];
  $service = (string)$challenge['service'];
  // The scope is ALWAYS built from the repository actually being asked about,
  // and the challenge's own scope is deliberately ignored. The challenge is a
  // host-level answer, so any repository named in it is at best irrelevant
  // and at worst a decoy: ghcr.io answers /v2/ with the literal placeholder
  // scope "repository:user/image:pull", and trusting that asked for a token
  // scoped to a repository called "user/image" — which the registry then
  // rejected on the manifest request, reported as "needs credentials", for
  // every ghcr and lscr image on the box. That is most of a typical Unraid
  // server. This is also why the challenge may safely cache per host while
  // the token caches per host+repo.
  $scope   = 'repository:'.$repo.':pull';

  $query = [];
  if ($service !== '') $query['service'] = $service;
  $query['scope'] = $scope;
  $tokenUrl = $realm.(strpos($realm, '?') !== false ? '&' : '?').http_build_query($query);

  // Only docker.io has a credential store here (HUB_USER/HUB_TOKEN, the
  // same sign-in the settings page offers). No other host has one, so it is
  // always asked unauthenticated — the challenge itself decides whether
  // that is enough.
  //
  // -u is additionally gated on the resolved scheme being https, not merely
  // on the host being docker.io. Nothing reaches this branch today — Hub is
  // never http — but the day a per-host credential store exists, this stops
  // a password going out over a plain-http wire.
  $authFlag = '';
  if ($host === 'docker.io' && staxx_registry_scheme($host) === 'https') {
    $cfg  = staxx_cfg();
    $user = trim((string)($cfg['HUB_USER'] ?? ''));
    $pass = trim((string)($cfg['HUB_TOKEN'] ?? ''));
    if ($user !== '' && $pass !== '') $authFlag = ' -u '.escapeshellarg($user.':'.$pass);
  }

  $tokOut = staxx_sh(
    'curl -sS -L --proto '.escapeshellarg('=https,http').' --max-time 6'.$authFlag
      .staxx_registry_curl_opts($host).' -w '
      .escapeshellarg("\n%{http_code}").' '.escapeshellarg($tokenUrl),
    10
  );
  $lines  = explode("\n", $tokOut);
  $status = (int)trim((string)array_pop($lines));
  $data   = json_decode(implode("\n", $lines), true);
  $token  = is_array($data) ? (string)($data['token'] ?? $data['access_token'] ?? '') : '';

  if ($token === '') {
    $why = ($status === 401 || $status === 403) ? 'auth' : 'failed';
    return $store('', $why);
  }
  return $store($token, '');
}

/**
 * One header-only manifest request — the "has it changed?" question
 * PLAN_90 Stage 2 spends instead of a full digest fetch every pass.
 *
 * @param string $etag sent back verbatim as If-None-Match, quotes and all.
 *        Never synthesise this from a digest and never add or strip quotes
 *        — registries are inconsistent about quoting and weak validators,
 *        and on Docker Hub the entity tag happens to equal the index digest
 *        only by coincidence; relying on that breaks elsewhere.
 * @param string &$why '' on success, else 'limited', 'notfound', 'auth' or
 *        'failed' — never a digest and never a guess.
 * @return array{status:int, digest:string, etag:string, labels:array,
 *               limit?:array{remaining?:int, limit?:int}}
 *         status is the HTTP status (200, 304) or 0 when the request itself
 *         never got an answer. A 304 carries no body and therefore no
 *         labels — that is correct, not a gap, and callers must not read
 *         the empty labels array as "the publisher removed them".
 */
function staxx_registry_digest(string $host, string $repo, string $tag, string $etag = '',
                               string &$why = null): array {
  $refuse = ['status' => 0, 'digest' => '', 'etag' => '', 'labels' => []];
  $why = '';

  $tokWhy = '';
  $token  = staxx_registry_token($host, $repo, $tokWhy);
  if ($tokWhy !== '') { $why = $tokWhy; return $refuse; }

  $headers = ['Accept: '.staxx_registry_accept()];
  if ($token !== '') $headers[] = 'Authorization: Bearer '.$token;
  if ($etag !== '')  $headers[] = 'If-None-Match: '.$etag;

  $apiHost = staxx_registry_api_host($host);
  // The scheme is already known here — staxx_registry_token() above always
  // runs the challenge probe first, which is the only place that decides it.
  $url = staxx_registry_scheme($host).'://'.$apiHost.'/v2/'.$repo.'/manifests/'.rawurlencode($tag);
  $curlOpts = staxx_registry_curl_opts($host);

  $cmd = 'curl -sS -L --proto '.escapeshellarg('=https,http').' --max-time 8'.$curlOpts.' -D /dev/stdout -o /dev/null -X GET';
  foreach ($headers as $h) $cmd .= ' -H '.escapeshellarg($h);
  $cmd .= ' '.escapeshellarg($url);

  $out = staxx_sh($cmd, 12);
  if ($out === '') { $why = 'failed'; return $refuse; }

  // -L follows redirects, so the header dump can hold more than one
  // response; only the final block's status and headers describe what the
  // manifest request actually answered.
  $blocks = preg_split("/\r?\n\r?\n/", trim($out));
  $last   = $blocks ? (string)end($blocks) : '';

  $status = 0;
  $head   = [];
  foreach (explode("\n", $last) as $line) {
    $line = trim($line, "\r\n ");
    if ($line === '') continue;
    if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $line, $sm)) { $status = (int)$sm[1]; continue; }
    $p = strpos($line, ':');
    if ($p === false) continue;
    $head[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
  }

  if ($status === 304) return ['status' => 304, 'digest' => '', 'etag' => '', 'labels' => []];

  if ($status === 429 || stripos($out, 'toomanyrequests') !== false || stripos($out, 'too many requests') !== false) {
    $why = 'limited';
    return $refuse;
  }
  if ($status === 404) { $why = 'notfound'; return $refuse; }
  if ($status === 401 || $status === 403) { $why = 'auth'; return $refuse; }

  if ($status !== 200) { $why = 'failed'; return $refuse; }

  $digest = strtolower((string)($head['docker-content-digest'] ?? ''));
  if (!preg_match('/^sha256:[0-9a-f]{64}$/', $digest)) {
    // public.ecr.aws (Amazon's public gallery) answers 200 with neither a
    // docker-content-digest nor an ETag -- confirmed on the box 2026-08-27.
    // A manifest's digest is by definition the sha256 of its exact served
    // bytes, so re-asking with the same Accept/bearer and piping the body
    // straight into sha256sum reproduces it without the body ever becoming
    // a PHP string (which could trim or re-encode it and change the hash).
    // Measured against this same reference, the result matched
    // `docker buildx imagetools inspect` byte-for-byte.
    $shaCmd = 'curl -fsS -L --proto '.escapeshellarg('=https,http').' --max-time 8'.$curlOpts.' -X GET';
    foreach ($headers as $h) $shaCmd .= ' -H '.escapeshellarg($h);
    $shaCmd .= ' '.escapeshellarg($url).' | sha256sum';

    $shaOut = staxx_sh($shaCmd, 12);
    $hash   = strtolower(trim((string)strtok(trim($shaOut), " \t")));

    // curl -f gives no output on an HTTP error, and sha256sum of nothing is
    // still a valid-looking hash (the empty string's digest) -- both must
    // read as a refusal, never as a real answer.
    if ($hash === '' || $hash === 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'
        || !preg_match('/^[0-9a-f]{64}$/', $hash)) {
      $why = 'failed';
      return $refuse;
    }

    return [
      'status' => 200,
      'digest' => 'sha256:'.$hash,
      'etag'   => '', // no entity tag was ever sent for this registry
      'labels' => [],
      // Present only on this path — its absence means the header answered
      // directly, which is how tests/server/registry_quirks.php tells the
      // two apart in its summary table without a second request.
      // Not called 'source': that key already means the project's own web
      // address everywhere else in the update path, and the day this array is
      // merged rather than read key by key, 'computed' would be shown to a
      // user as a project link.
      'digestFrom' => 'computed',
    ];
  }

  $result = [
    'status' => 200,
    'digest' => $digest,
    'etag'   => (string)($head['etag'] ?? ''),
    'labels' => [],
  ];

  // Spent nowhere yet — PLAN_90 Stage 5 is what reads this. Kept to a
  // couple of lines because nothing else touches it.
  $limit = [];
  if (isset($head['ratelimit-remaining'])) $limit['remaining'] = (int)explode(';', $head['ratelimit-remaining'])[0];
  if (isset($head['ratelimit-limit']))     $limit['limit']     = (int)explode(';', $head['ratelimit-limit'])[0];
  if ($limit !== []) $result['limit'] = $limit;

  return $result;
}

/**
 * Version/created/source labels from the config blob of the image actually
 * named — the label counterpart to staxx_registry_digest(), asked only when
 * that digest has genuinely moved (see staxx_registry_remote_http()). Unlike
 * staxx_registry_config(), this works against ANY host staxx_registry_ref()
 * can parse: it is the fix for PLAN_90's gap where a ghcr-hosted image's
 * digest came from ghcr but its labels came from Docker Hub, and every other
 * host got no labels at all. staxx_registry_config()'s Hub-only path and its
 * lscr/ghcr->Hub rewrite are correct for the form editor and stay untouched;
 * this function exists so update-checking can ask the registry named in the
 * compose file instead.
 *
 * Same three-step walk staxx_registry_config() performs (index -> manifest ->
 * config blob), but against staxx_registry_api_host($host) and reusing
 * staxx_registry_token(), which is already cached per host+repo from the
 * digest question this follows — so on the only path that matters (the
 * digest just moved) this costs no extra handshake.
 *
 * Every failure — no token, no network, a non-JSON reply, no config digest —
 * returns [] rather than raising anything. A caller losing labels must never
 * turn into a caller losing the digest it already has.
 *
 * @return array<string,string> label name => value, or [] on any failure
 */
function staxx_registry_labels(string $host, string $repo, string $tag): array {
  $tokenWhy = '';
  $token = staxx_registry_token($host, $repo, $tokenWhy);
  if ($tokenWhy !== '') return [];

  $headers = [];
  if ($token !== '') $headers[] = 'Authorization: Bearer '.$token;

  $apiHost = staxx_registry_api_host($host);
  // The scheme is already known — staxx_registry_token() above ran the
  // challenge probe. staxx_hub_json() has no way to carry a relaxed-
  // certificate flag, so a trusted host with a self-made certificate (rather
  // than none at all) fails here and returns [] — losing labels only, never
  // the digest, which is fetched by staxx_registry_digest() instead and does
  // carry the flag. Not worth widening staxx_hub_json()'s signature for.
  $scheme = staxx_registry_scheme($host);

  $index = staxx_hub_json(
    $scheme.'://'.$apiHost.'/v2/'.$repo.'/manifests/'.rawurlencode($tag),
    array_merge(['Accept: '.staxx_registry_accept()], $headers),
    6, 8
  );
  if ($index === null) return [];

  $manifest = $index;
  if (isset($index['manifests']) && is_array($index['manifests'])) {
    $digest = '';
    foreach ($index['manifests'] as $entry) {
      $platform = is_array($entry['platform'] ?? null) ? $entry['platform'] : [];
      if (($platform['architecture'] ?? '') === 'amd64' && ($platform['os'] ?? '') === 'linux') {
        $digest = (string)($entry['digest'] ?? '');
        break;
      }
    }
    // No amd64/linux entry (attestation-only index, or an unusual registry) —
    // fall back to the first one rather than giving up. Every other route in
    // staxx_image_remote() reports against amd64, which is why amd64 is
    // preferred above: pairing one architecture's labels with another's
    // digest would be its own quiet bug.
    if ($digest === '' && $index['manifests'] !== []) {
      $digest = (string)($index['manifests'][0]['digest'] ?? '');
    }
    if ($digest === '') return [];

    $manifest = staxx_hub_json(
      $scheme.'://'.$apiHost.'/v2/'.$repo.'/manifests/'.$digest,
      // Both single-image shapes, not just the OCI one. staxx_registry_config()
      // asks for OCI alone and gets away with it because it only ever talks to
      // Docker Hub, which serves it. This function exists to talk to registries
      // nobody here has met, and one holding only Docker-format manifests would
      // refuse an OCI-only request outright.
      array_merge(['Accept: application/vnd.oci.image.manifest.v1+json,'
                  .'application/vnd.docker.distribution.manifest.v2+json'], $headers),
      6, 8
    );
    if ($manifest === null) return [];
  }

  $configDigest = (string)($manifest['config']['digest'] ?? '');
  if ($configDigest === '') return [];

  // A config blob is small (a few KB of JSON) — a caller that knows that
  // says so, the same reasoning staxx_hub_repo() applies to a README.
  $blob = staxx_hub_json(
    $scheme.'://'.$apiHost.'/v2/'.$repo.'/blobs/'.$configDigest,
    $headers, 6, 8, 256 * 1024
  );
  if ($blob === null) return [];

  $config = is_array($blob['config'] ?? null) ? $blob['config'] : [];
  $labels = is_array($config['Labels'] ?? null) ? $config['Labels'] : [];
  if ($labels === [] && is_array($blob['Labels'] ?? null)) $labels = $blob['Labels'];
  return $labels;
}

/**
 * Docker's own `.Config.Healthcheck.Test`, normalised into the shape
 * PLAN_108 needs rather than the shape Docker hands back: `["NONE"]` is an
 * image author's explicit "I have cancelled the inherited check", which
 * must read as "not declared" exactly the same as the key being absent —
 * only `CMD`/`CMD-SHELL` mean there is really something to run. A tiny pure
 * function purely so it can be tested with made-up JSON, never a real image.
 *
 * @return array{test:string[], declared:bool}
 */
function staxx_parse_image_healthcheck(?array $healthcheck): array {
  $test = is_array($healthcheck['Test'] ?? null) ? array_values($healthcheck['Test']) : [];
  $mode = (string)($test[0] ?? '');
  $declared = ($mode === 'CMD' || $mode === 'CMD-SHELL') && count($test) > 1;
  return ['test' => $declared ? $test : [], 'declared' => $declared];
}

/**
 * The same three fields read straight off an image already pulled onto this
 * server — no network at all, so a locally-sourced image is never made to
 * wait on Docker Hub for something it can already answer itself. Also
 * reports whether the image ships its own health check (PLAN_108 step 1):
 * an offer only ever has to be worked out for a container that is actually
 * running, so this — the pulled-image reader — is the only one of the two
 * config readers that needs it; staxx_registry_config() feeds the
 * not-yet-pulled route, which a health offer never reaches.
 *
 * The reference is checked for shape before it reaches the shell —
 * lowercase alphanumerics and `. _ - / :` only — on top of, not instead of,
 * escapeshellarg(). This is the first use of `--format json` outside
 * staxx_compose_state(); the other `docker inspect` in this plugin is
 * template-formatted and has its own unrelated whitespace trap.
 *
 * Never returns Env, for the same reason staxx_registry_config() does not.
 *
 * @return array{ports?:string[], volumes?:string[], labels?:array<string,string>,
 *               healthcheck?:array{test:string[], declared:bool}}
 */
function staxx_local_image_config(string $ref): array {
  $ref = trim($ref);
  if ($ref === '' || !preg_match('#^[a-z0-9][a-z0-9._/:-]*$#D', $ref)) return [];

  $docker = escapeshellarg(staxx_docker_bin());
  $out = staxx_sh(
    $docker.' image inspect --format '.escapeshellarg('{{json .Config}}').' '.escapeshellarg($ref),
    10
  );

  $config = json_decode($out, true);
  if (!is_array($config)) return [];

  return [
    'ports'       => array_keys(is_array($config['ExposedPorts'] ?? null) ? $config['ExposedPorts'] : []),
    'volumes'     => array_keys(is_array($config['Volumes'] ?? null) ? $config['Volumes'] : []),
    'labels'      => is_array($config['Labels'] ?? null) ? $config['Labels'] : [],
    'healthcheck' => staxx_parse_image_healthcheck(is_array($config['Healthcheck'] ?? null) ? $config['Healthcheck'] : null),
  ];
}

/**
 * This server's timezone, e.g. "America/Toronto", read from the symlink
 * Linux keeps at /etc/localtime. '' when it cannot be read or the target
 * doesn't look like a zone name — nothing downstream may guess a timezone
 * when this comes back empty.
 */
function staxx_server_timezone(): string {
  $out = trim(staxx_sh('readlink -f /etc/localtime', 5));
  if ($out === '') return '';

  $prefix = '/usr/share/zoneinfo/';
  if (strpos($out, $prefix) !== 0) return '';
  $zone = substr($out, strlen($prefix));

  // "Region/City", or one of the bare names also shipped (UTC, GMT, ...).
  if (!preg_match('#^[A-Za-z0-9_+-]+(?:/[A-Za-z0-9_+-]+)*$#D', $zone)) return '';
  return $zone;
}

/**
 * What an image and its own documentation say about themselves — the
 * orchestrator include/action.php's `image-facts` case calls. Always
 * returns an array; a field is left absent, not empty, when it was never
 * asked for, so the browser can tell "didn't ask" apart from "asked and
 * got nothing".
 *
 * $wantConfig gates staxx_registry_config() deliberately: it is four
 * chained requests and only worth paying for when the image's README gave
 * nothing usable, which only the browser can judge — it holds the compose
 * parser this file does not have. The common path never runs it; the
 * browser asks a second time with `config=1` once it knows it needs the
 * fallback. A local image's config is free and offline, so it is always
 * included regardless of $wantConfig.
 *
 * @return array{off?:bool, readme?:string, description?:string,
 *               ports?:string[], volumes?:string[], labels?:array<string,string>,
 *               appdata?:string, timezone?:string}
 */
function staxx_image_facts(string $image, string $source, bool $wantConfig = false): array {
  if ((staxx_cfg()['IMAGE_LOOKUP'] ?? 'true') === 'false') return ['off' => true];

  $facts = [];

  // Docker Hub knows nothing about an image that only exists on this server,
  // and an unsigned-in server gets roughly ten questions an hour — so the
  // local case must not spend one of them on an answer it cannot use.
  $repo = $source === 'local' ? [] : staxx_hub_repo($image);
  if (isset($repo['readme']))      $facts['readme']      = $repo['readme'];
  if (isset($repo['description'])) $facts['description'] = $repo['description'];

  if ($source === 'local') {
    $config = staxx_local_image_config($image);
  } elseif ($wantConfig) {
    $config = staxx_registry_config($image);
  } else {
    $config = [];
  }
  if (isset($config['ports']))   $facts['ports']   = $config['ports'];
  if (isset($config['volumes'])) $facts['volumes'] = $config['volumes'];
  if (isset($config['labels']))  $facts['labels']  = $config['labels'];

  $facts['appdata']  = staxx_appdata_root();
  $facts['timezone'] = staxx_server_timezone();

  return $facts;
}

/**
 * Docker prints an image's own health check as a suffix on the status text —
 * "Up 2 hours (healthy)", "Up 3 minutes (unhealthy)", "Up 5 seconds
 * (health: starting)" — and nothing at all when the image declares no check.
 * Matched on the END of the trimmed string, never by searching it: a
 * container or image name cannot reach that position, so nothing there can
 * be crafted to spoof a health word that Docker never actually reported.
 */
function staxx_health_from_status(string $status): string {
  $status = trim($status);
  if (str_ends_with($status, '(healthy)'))          return 'healthy';
  if (str_ends_with($status, '(unhealthy)'))         return 'unhealthy';
  if (str_ends_with($status, '(health: starting)'))  return 'starting';
  return 'none';
}

/**
 * One `docker ps -a` for the whole machine, every field the plugin's several
 * container readers each want, so they can share this single shell-out
 * instead of running their own near-identical one straight after it —
 * StacksPage.php used to trigger three of them in consecutive lines.
 *
 * `.Label "key"` looks up one label. The obvious-looking `index .Labels
 * "key"` does not work here: in a `docker ps` template .Labels is a list, not
 * a lookup table, and asking it for a named key fails the whole command —
 * which looks exactly like "no compose containers exist".
 *
 * The trailing "end" holds the last field open: PHP's exec() trims trailing
 * whitespace from each line, so a container whose final label is empty would
 * otherwise lose the tab before it and arrive one field short.
 *
 * `health` (PLAN_107) costs no extra call — it is read straight out of the
 * status text `{{.Status}}` already carried in this same line.
 *
 * @return array<int, array{id:string, name:string, state:string, status:string,
 *                          image:string, project:string, service:string,
 *                          configFiles:string, configHash:string, health:string}>
 */
function staxx_docker_ps_raw(): array {
  static $rows = null;
  if ($rows !== null) return $rows;

  $rows = [];
  if (!staxx_docker_running()) return $rows;

  // configHash is PLAN_71's "restart pending" fingerprint — the resolved
  // config compose created the container from. It goes before "end", the
  // same as every other label here, so it stays protected by the same
  // trailing-whitespace guard rather than needing one of its own.
  $fmt = '{{.ID}}\t{{.Names}}\t{{.State}}\t{{.Status}}\t{{.Image}}\t'
       . '{{.Label "com.docker.compose.project"}}\t{{.Label "com.docker.compose.service"}}\t'
       . '{{.Label "com.docker.compose.project.config_files"}}\t'
       . '{{.Label "com.docker.compose.config-hash"}}\tend';
  $out = staxx_sh(
    escapeshellarg(staxx_docker_bin()).' ps -a --no-trunc --format '.escapeshellarg($fmt), 15
  );

  foreach (explode("\n", $out) as $line) {
    if (trim($line) === '') continue;
    $c = explode("\t", $line);
    // One more required field than before (through configFiles) — a line
    // that arrives short of that is genuinely garbled, not just a container
    // with no labels, so it is skipped rather than kept with a guessed key.
    if (count($c) < 8) continue;
    // StaXXCrypt is left out of every list built from this,
    // which is all of them. It carries no compose project, so it would
    // otherwise collect in the ungrouped pile beside somebody's hand-made
    // containers — and a page about the containers you chose to run is the
    // wrong place for one you did not (PLAN_74). Matched on the exact name
    // we created it with: unlike the image removal in Crypt.php, the worst a
    // false match can do here is hide a row.
    if ($c[1] === STAXX_CRYPT_CONTAINER) continue;
    $rows[] = [
      'id'          => $c[0],
      'name'        => $c[1],
      'state'       => $c[2],
      'status'      => $c[3],
      'image'       => $c[4],
      'project'     => $c[5],
      'service'     => $c[6],
      'configFiles' => $c[7],
      'configHash'  => $c[8] ?? '',
      'health'      => staxx_health_from_status($c[3]),
    ];
  }
  return $rows;
}

/**
 * Containers on the system grouped by their compose project.
 *
 * This is the grouping key the whole stack presentation rests on: compose
 * stamps `com.docker.compose.project` on every container it creates, so stacks
 * group themselves with no folder configuration from the user. Containers with
 * no such label (i.e. created by Unraid templates or by hand) collect under ''.
 *
 * @return array<string, string[]> project name => container names
 */
function staxx_containers_by_project(): array {
  $projects = [];
  foreach (staxx_docker_ps_raw() as $row) {
    $projects[$row['project']][] = $row['name'];
  }
  ksort($projects);
  return $projects;
}
?>
