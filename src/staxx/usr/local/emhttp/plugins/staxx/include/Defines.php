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
define('STAXX_CFG',     STAXX_CFG_DIR.'/'.STAXX_PLUGIN.'.cfg');

// Release-notes cap, characters. A stack's own record must not quietly grow
// by a megabyte of vendor prose because one release included a full changelog.
define('STAXX_NOTES_MAX', 4000);

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
 * Where a removed stack's zip goes. The setting if one is configured,
 * otherwise a fixed folder under wherever Unraid keeps appdata — so a blank
 * ARCHIVE_ROOT still lands somewhere sensible on any box, rather than
 * inventing a path that might not exist there.
 */
function staxx_archive_root(): string {
  $v = trim((string)(staxx_cfg()['ARCHIVE_ROOT'] ?? ''));
  if ($v !== '') return rtrim($v, '/');
  return rtrim(staxx_appdata_root().'staxx/archives', '/');
}

/**
 * Read the plugin config, falling back to the shipped defaults for any key the
 * user's config predates. Returns a flat key => string map.
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
  $defaults = @parse_ini_file(STAXX_ROOT.'/default.cfg', false, INI_SCANNER_RAW) ?: [];
  $user     = @parse_ini_file(STAXX_CFG, false, INI_SCANNER_RAW) ?: [];
  return $cfg = array_merge($defaults, $user);
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
 * @return array|null decoded body, or null on anything wrong at all —
 *                     no network, DNS, a non-JSON body, an HTTP error
 */
function staxx_hub_json(string $url, array $headers = [], int $maxTime = 8, int $shSeconds = 12): ?array {
  // --proto bounds the transport whatever the caller passes: a token realm
  // read out of a remote registry's own reply reaches this function, and
  // without this a hostile registry could name file:// or any other scheme
  // curl happens to support and have the server read it back.
  $cmd = 'curl -fsSL --proto \'=https,http\' --max-time '.$maxTime;
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
 * The same three fields read straight off an image already pulled onto this
 * server — no network at all, so a locally-sourced image is never made to
 * wait on Docker Hub for something it can already answer itself.
 *
 * The reference is checked for shape before it reaches the shell —
 * lowercase alphanumerics and `. _ - / :` only — on top of, not instead of,
 * escapeshellarg(). This is the first use of `--format json` outside
 * staxx_compose_state(); the other `docker inspect` in this plugin is
 * template-formatted and has its own unrelated whitespace trap.
 *
 * Never returns Env, for the same reason staxx_registry_config() does not.
 *
 * @return array{ports?:string[], volumes?:string[], labels?:array<string,string>}
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
    'ports'   => array_keys(is_array($config['ExposedPorts'] ?? null) ? $config['ExposedPorts'] : []),
    'volumes' => array_keys(is_array($config['Volumes'] ?? null) ? $config['Volumes'] : []),
    'labels'  => is_array($config['Labels'] ?? null) ? $config['Labels'] : [],
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
 * @return array<int, array{id:string, name:string, state:string, status:string,
 *                          image:string, project:string, service:string,
 *                          configFiles:string}>
 */
function staxx_docker_ps_raw(): array {
  static $rows = null;
  if ($rows !== null) return $rows;

  $rows = [];
  if (!staxx_docker_running()) return $rows;

  $fmt = '{{.ID}}\t{{.Names}}\t{{.State}}\t{{.Status}}\t{{.Image}}\t'
       . '{{.Label "com.docker.compose.project"}}\t{{.Label "com.docker.compose.service"}}\t'
       . '{{.Label "com.docker.compose.project.config_files"}}\tend';
  $out = staxx_sh(
    escapeshellarg(staxx_docker_bin()).' ps -a --no-trunc --format '.escapeshellarg($fmt), 15
  );

  foreach (explode("\n", $out) as $line) {
    if (trim($line) === '') continue;
    $c = explode("\t", $line);
    if (count($c) < 7) continue;
    $rows[] = [
      'id'          => $c[0],
      'name'        => $c[1],
      'state'       => $c[2],
      'status'      => $c[3],
      'image'       => $c[4],
      'project'     => $c[5],
      'service'     => $c[6],
      'configFiles' => $c[7] ?? '',
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
