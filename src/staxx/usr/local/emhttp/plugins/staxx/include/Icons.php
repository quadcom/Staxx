<?PHP
/* StaXX — icons for stacks and containers.
 * Copyright 2026, StaXX contributors.
 *
 * WHAT THIS FILE IS FOR
 *
 * Every row in the table used to show the same grey cube, which is the one
 * thing an icon must not do. This finds a real logo for a row, in this order:
 *
 *   1. Whatever the compose file says, in `x-unraid: icon:`.
 *   2. A match against the selfh.st icon collection, worked out from the
 *      container's image name.
 *   3. Nothing — and the caller draws a coloured tile with the row's initials.
 *
 * The collection lives at https://selfh.st/icons and is served from GitHub over
 * the jsDelivr CDN. It is CC-BY-4.0, and the credit sits on the settings page.
 *
 * NOTHING HERE REACHES THE NETWORK DURING A PAGE RENDER. Downloading twenty
 * icons at roughly a tenth of a second each is a two-second page, and it would
 * happen on the one render where the user is least willing to wait — the first.
 * The page draws with whatever is already cached; action=icons then fetches the
 * rest in the background and the browser swaps them in.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/staxx/include/Defines.php';

/** Where the collection is served from. */
const STAXX_ICON_CDN = 'https://cdn.jsdelivr.net/gh/selfhst/icons';

/**
 * The keeper, on the flash device.
 *
 * Unraid puts its own container icons on the Docker vdisk instead. Flash is
 * used here because it is always mounted: /var/lib/docker is not, when Docker
 * is stopped, and writing there while it is stopped puts the file into RAM
 * without saying so — it then vanishes at the next reboot and every icon is
 * downloaded again. A cached icon is about a kilobyte of SVG, so a few hundred
 * of them are no burden on the flash device.
 */
const STAXX_ICON_STORE = STAXX_CFG_DIR.'/icons';

/**
 * The copy the browser actually loads.
 *
 * /usr/local/emhttp/state is a symlink to /var/local/emhttp, so anything under
 * here is served as a plain static file at /state/... with no PHP involved.
 * This is exactly the split Unraid's own Docker page uses for its icons.
 */
const STAXX_ICON_SERVE = '/var/local/emhttp/plugins/'.STAXX_PLUGIN.'/icons';
const STAXX_ICON_BASE  = '/state/plugins/'.STAXX_PLUGIN.'/icons';

/** The reduced collection index. Underscore-prefixed so it can never collide
 *  with an icon file: no reference in the collection starts with one. */
const STAXX_ICON_INDEX = STAXX_ICON_STORE.'/_index.json';

/** How stale the index may get before it is fetched again. */
const STAXX_ICON_INDEX_TTL = 7 * 86400;

/** File extensions that may be written into the cache. */
const STAXX_ICON_EXTS = ['svg', 'png', 'webp', 'jpg', 'jpeg', 'gif', 'ico'];

/* ------------------------------------------------------------- settings -- */

/** Whether the plugin is allowed to fetch icons from the internet. */
function staxx_icon_fetching(): bool {
  return (staxx_cfg()['ICON_FETCH'] ?? 'true') !== 'false';
}

/* ---------------------------------------------------------------- names -- */

/**
 * The collection's own naming rule: lower-case, and every run of anything that
 * is not a letter or a digit becomes a single hyphen.
 */
function staxx_icon_norm(string $s): string {
  return trim(strtolower((string)preg_replace('/[^a-z0-9]+/i', '-', $s)), '-');
}

/**
 * Is this safe to use as a filename?
 *
 * Every cache path is built from a reference, so this is the only thing
 * standing between a compose file and a path of its choosing. Deliberately
 * stricter than "no slashes": a reference is lower-case alphanumerics and
 * hyphens, starting with an alphanumeric, and nothing else is ever written.
 */
function staxx_icon_safe_ref(string $ref): bool {
  return (bool)preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $ref);
}

/* ---------------------------------------------------------------- index -- */

/**
 * The collection index, reduced to what matching needs.
 *
 * The published index.json is 846 KB of records describing 2,868 icons, most
 * of which is of no use here. Decoding that on every page render would be
 * wasteful, so it is reduced ONCE at download time to three small maps and only
 * the reduction is stored:
 *
 *   refs    reference => 's', 'p' or 'sp'  — which formats exist
 *   alias   another spelling => reference  — display names, and the
 *           de-hyphenated form, so `actualbudget` finds `actual-budget`
 *   order   every reference, sorted, for the prefix rule below
 *
 * Returns an empty index if it has never been downloaded. Everything below
 * copes with that by finding no match, which is the correct behaviour on a
 * server with no internet access.
 *
 * @return array{refs:array<string,string>, alias:array<string,string>, order:string[]}
 */
function staxx_icon_index(): array {
  static $index = null;
  if ($index !== null) return $index;

  $index = ['refs' => [], 'alias' => [], 'order' => []];

  $raw = @file_get_contents(STAXX_ICON_INDEX);
  if ($raw === false) return $index;

  $data = json_decode($raw, true);
  if (!is_array($data) || !isset($data['refs']) || !is_array($data['refs'])) return $index;

  $index['refs']  = $data['refs'];
  $index['alias'] = is_array($data['alias'] ?? null) ? $data['alias'] : [];
  // Stored already sorted, so the keys come back in order and no sort is needed
  // on a page render.
  $index['order'] = array_keys($data['refs']);

  return $index;
}

/** True when the index is missing or old enough to be worth fetching again. */
function staxx_icon_index_stale(): bool {
  $when = @filemtime(STAXX_ICON_INDEX);
  return $when === false || (time() - $when) > STAXX_ICON_INDEX_TTL;
}

/**
 * Download the collection index and store the reduction described above.
 *
 * Called from the background endpoint only, never from a page render.
 */
function staxx_icon_index_refresh(): bool {
  if (!staxx_icon_fetching()) return false;

  $raw = staxx_icon_get(STAXX_ICON_CDN.'/index.json', 20);
  if ($raw === null) return false;

  $list = json_decode($raw, true);
  if (!is_array($list) || !$list) return false;

  $refs = [];
  $alias = [];
  foreach ($list as $entry) {
    if (!is_array($entry)) continue;
    $ref = strtolower(trim((string)($entry['Reference'] ?? '')));
    if (!staxx_icon_safe_ref($ref)) continue;

    // Formats, shortest first — an SVG is about a kilobyte where the PNG of the
    // same icon is thirty-seven, and it stays sharp at any size.
    $have = '';
    if (($entry['SVG'] ?? '') === 'Yes') $have .= 's';
    if (($entry['PNG'] ?? '') === 'Yes') $have .= 'p';
    if ($have === '') continue;

    $refs[$ref] = $have;

    // The display name, normalised: "Actual Budget" => actual-budget.
    $name = staxx_icon_norm((string)($entry['Name'] ?? ''));
    if ($name !== '' && $name !== $ref && !isset($alias[$name])) $alias[$name] = $ref;

    // And the hyphens taken out, because image authors run words together far
    // more often than the collection does.
    $squashed = str_replace('-', '', $ref);
    if ($squashed !== $ref && !isset($alias[$squashed])) $alias[$squashed] = $ref;
  }

  if (!$refs) return false;

  // Sorted on the way in, so no page render ever pays to sort it.
  ksort($refs);

  return staxx_icon_write(STAXX_ICON_INDEX,
    (string)json_encode(['refs' => $refs, 'alias' => $alias]));
}

/* -------------------------------------------------------------- matching -- */

/**
 * Noise that vendors bolt onto an image name but never onto the product name.
 * Stripping these turns binhex/arch-prowlarr into prowlarr and
 * guniv/coolercontrol-docker into coolercontrol.
 */
const STAXX_ICON_LEAD = '/^(arch|container|docker|unraid)-/';
const STAXX_ICON_TAIL = '/-(docker|server|app|aio|ce|oss|community|amd64|arm64)$/';

/**
 * References beginning with $prefix, giving up as soon as there are two.
 *
 * The list is sorted, so everything sharing a prefix is contiguous: find where
 * the prefix would be inserted and look at the next two entries. Doing this by
 * scanning all 2,868 references for every candidate of every row was the one
 * part of matching that showed up in a page render.
 *
 * @return string[] at most two, which is all the caller needs to know
 */
function staxx_icon_prefixed(array $order, string $prefix): array {
  $lo = 0;
  $hi = count($order);
  while ($lo < $hi) {
    $mid = intdiv($lo + $hi, 2);
    if (strcmp($order[$mid], $prefix) < 0) $lo = $mid + 1; else $hi = $mid;
  }

  $out = [];
  for ($i = $lo; $i < count($order) && count($out) < 2; $i++) {
    if (strncmp($order[$i], $prefix, strlen($prefix)) !== 0) break;
    $out[] = $order[$i];
  }
  return $out;
}

/**
 * One candidate word, looked up every way that cannot be wrong.
 *
 * Exact matches first. The prefix rule last, and only when EXACTLY ONE
 * reference in the whole collection starts with the candidate — that is what
 * turns `postgres` into `postgresql` and `alpine` into `alpine-linux` without
 * guessing. Two matches means the word is ambiguous and no icon is better than
 * a coin toss: `node` begins six of them, and picking one would put NodeBB's
 * logo on a plain Node.js container.
 *
 * There is deliberately no fuzzy or substring matching anywhere in this file.
 * A wrong icon is worse than no icon: no icon reads as "not recognised", while
 * a wrong one reads as a bug in the page, and it is the sort of bug that gets
 * reported as "my containers are mixed up".
 */
function staxx_icon_lookup(string $candidate): string {
  $index = staxx_icon_index();
  if (!$index['refs']) return '';

  $c = staxx_icon_norm($candidate);
  if ($c === '') return '';

  if (isset($index['refs'][$c]))  return $c;
  if (isset($index['alias'][$c])) return $index['alias'][$c];

  // Hyphen-terminated first: a candidate that is a whole word of a longer
  // reference is a better bet than one that merely starts it.
  $hits = staxx_icon_prefixed($index['order'], $c.'-');
  if (count($hits) === 1) return $hits[0];

  $hits = staxx_icon_prefixed($index['order'], $c);
  if (count($hits) === 1) return $hits[0];

  return '';
}

/**
 * Every spelling of an image name worth trying, best first.
 *
 * `lscr.io/linuxserver/jellyfin:latest` gives up `jellyfin`; the owner segment
 * is tried too, because `vaultwarden/server` names the product on the left and
 * says nothing on the right. A segment containing a dot is skipped — that is a
 * registry host (lscr.io, ghcr.io), never a product.
 *
 * @return string[]
 */
function staxx_icon_candidates(string $image): array {
  // A digest, then a tag. The tag pattern insists on no slash after the colon
  // so that a registry with a port — 192.168.1.10:5000/thing — keeps its path.
  $bare = (string)preg_replace('/@sha256:.*$/', '', $image);
  $bare = (string)preg_replace('/:[^\/]*$/', '', $bare);

  $segments = explode('/', $bare);
  $out      = [];

  $push = function (string $s) use (&$out) {
    $s = staxx_icon_norm($s);
    if ($s === '') return;
    $out[] = $s;
    if (preg_match(STAXX_ICON_LEAD, $s)) $out[] = (string)preg_replace(STAXX_ICON_LEAD, '', $s);
    if (preg_match(STAXX_ICON_TAIL, $s)) $out[] = (string)preg_replace(STAXX_ICON_TAIL, '', $s);
    $out[] = str_replace('-', '', $s);
  };

  $push((string)end($segments));
  if (count($segments) > 1) {
    $owner = $segments[count($segments) - 2];
    if (strpos($owner, '.') === false) $push($owner);
  }

  return array_values(array_unique(array_filter($out, 'strlen')));
}

/**
 * The collection reference for a row, or '' if nothing matches.
 *
 * The image name is tried first because it names the software; the service and
 * stack names are the backstop, and they are what rescue a container built on a
 * generic base image — `node:22-alpine` says nothing, but the service it runs
 * under is usually called after the thing it is.
 */
function staxx_icon_match(string $image, string $service = '', string $stack = ''): string {
  $tries = staxx_icon_candidates($image);
  foreach ([$service, $stack] as $extra) {
    if ($extra !== '') $tries[] = staxx_icon_norm($extra);
  }

  foreach (array_unique($tries) as $candidate) {
    $ref = staxx_icon_lookup($candidate);
    if ($ref !== '') return $ref;
  }
  return '';
}

/* ----------------------------------------------------------------- cache -- */

/** Write a file, creating its directory, without ever leaving a half file. */
function staxx_icon_write(string $path, string $body): bool {
  $dir = dirname($path);
  if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return false;

  // Written beside the target and moved into place, so a download interrupted
  // half way never becomes a cached icon that is permanently broken.
  $tmp = $path.'.'.getmypid().'.tmp';
  if (@file_put_contents($tmp, $body) === false) return false;
  if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
  return true;
}

/** Fetch a URL, or null. Time-limited, because nothing here may hang. */
function staxx_icon_get(string $url, int $seconds = 10): ?string {
  if (!preg_match('#^https?://#i', $url)) return null;

  $ch = curl_init($url);
  if ($ch === false) return null;

  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 3,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => $seconds,
    CURLOPT_USERAGENT      => 'StaXX (Unraid plugin)',
    // An icon is kilobytes. A URL from a compose file is not necessarily an
    // icon, and a plugin that will happily download a DVD image because a
    // compose file asked it to is a plugin that fills the flash device.
    CURLOPT_MAXFILESIZE    => 2 * 1024 * 1024,
  ]);

  $body = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);

  if ($body === false || $body === '' || $code < 200 || $code > 299) return null;
  return (string)$body;
}

/**
 * Where the browser can load a cached icon from, or '' if it is not cached.
 *
 * The served copy lives in RAM and therefore disappears at every reboot, while
 * the keeper on flash does not. Rather than a boot script to put them back,
 * that is repaired here the first time each icon is asked for: a local copy of
 * a one-kilobyte file is not worth arranging anything more elaborate around.
 */
function staxx_icon_url(string $ref): string {
  if (!staxx_icon_safe_ref($ref)) return '';

  foreach (STAXX_ICON_EXTS as $ext) {
    $served = STAXX_ICON_SERVE.'/'.$ref.'.'.$ext;
    if (is_file($served)) return STAXX_ICON_BASE.'/'.$ref.'.'.$ext;

    $kept = STAXX_ICON_STORE.'/'.$ref.'.'.$ext;
    if (is_file($kept)) {
      if (!is_dir(STAXX_ICON_SERVE)) @mkdir(STAXX_ICON_SERVE, 0755, true);
      if (@copy($kept, $served)) return STAXX_ICON_BASE.'/'.$ref.'.'.$ext;
    }
  }
  return '';
}

/** Store one icon under $ref, in both places. */
function staxx_icon_store(string $ref, string $ext, string $body): bool {
  if (!staxx_icon_safe_ref($ref)) return false;
  if (!in_array($ext, STAXX_ICON_EXTS, true)) return false;

  if (!staxx_icon_write(STAXX_ICON_STORE.'/'.$ref.'.'.$ext, $body)) return false;
  staxx_icon_write(STAXX_ICON_SERVE.'/'.$ref.'.'.$ext, $body);
  return true;
}

/* --------------------------------------------------------------- fetching -- */

/**
 * Download one icon and cache it. Returns the URL to load it from, or ''.
 *
 * $remote is where to get it: for a collection icon the caller does not supply
 * one and it is worked out from the index, which is also what stops a reference
 * that is not in the collection from being fetched at all.
 */
function staxx_icon_fetch(string $ref, string $remote = ''): string {
  if (!staxx_icon_fetching()) return '';
  if (!staxx_icon_safe_ref($ref)) return '';

  $cached = staxx_icon_url($ref);
  if ($cached !== '') return $cached;

  $sources = [];
  if ($remote !== '') {
    $ext = strtolower((string)pathinfo((string)parse_url($remote, PHP_URL_PATH), PATHINFO_EXTENSION));
    if (!in_array($ext, STAXX_ICON_EXTS, true)) $ext = 'png';
    $sources[] = [$remote, $ext];
  } else {
    $have = staxx_icon_index()['refs'][$ref] ?? '';
    if ($have === '') return '';
    if (strpos($have, 's') !== false) $sources[] = [STAXX_ICON_CDN.'/svg/'.$ref.'.svg', 'svg'];
    if (strpos($have, 'p') !== false) $sources[] = [STAXX_ICON_CDN.'/png/'.$ref.'.png', 'png'];
  }

  foreach ($sources as [$url, $ext]) {
    $body = staxx_icon_get($url);
    if ($body === null) continue;
    if (staxx_icon_store($ref, $ext, $body)) return STAXX_ICON_BASE.'/'.$ref.'.'.$ext;
  }
  return '';
}

/**
 * Fetch a batch of icons, under a time limit.
 *
 * This is the only thing in the plugin that waits on the internet, and it runs
 * where waiting is free: after the page has already drawn. It still keeps a
 * budget, because a server whose DNS is broken answers every request with a
 * five-second timeout, and eighty of those is a request that never returns.
 * Whatever is left over is simply picked up by the next sweep.
 *
 * @param array $wanted list of ['ref' => string, 'remote' => string]
 * @return array{icons:array<string,string>, done:bool}
 *         icons  reference => URL, for everything fetched this time
 *         done   false when the budget ran out with work still to do, which is
 *                the browser's cue to ask again
 */
function staxx_icon_sweep(array $wanted, int $budget = 10): array {
  if (!staxx_icon_fetching()) return ['icons' => [], 'done' => true];

  $deadline = time() + $budget;

  // Refreshed here rather than on a schedule: this is the one moment the plugin
  // is already allowed to be slow, and a stale index only ever means a missing
  // icon, never a wrong one.
  if (staxx_icon_index_stale()) staxx_icon_index_refresh();

  $icons = [];
  $done  = true;

  foreach ($wanted as $item) {
    if (time() >= $deadline) { $done = false; break; }

    $ref = (string)($item['ref'] ?? '');
    if ($ref === '' || isset($icons[$ref])) continue;

    $url = staxx_icon_fetch($ref, (string)($item['remote'] ?? ''));
    if ($url !== '') $icons[$ref] = $url;
  }

  return ['icons' => $icons, 'done' => $done];
}

/* --------------------------------------------------------------- resolving -- */

/**
 * Decide what one row's icon is.
 *
 * @param string $icon    the x-unraid `icon:` value, or ''
 * @param string $dir     the stack directory, for a relative path
 * @param string $image   the container image, for automatic matching
 * @param string $service the service name, tried after the image
 * @param string $stack   the stack name, tried last
 *
 * @return array{fa:string, ref:string, url:string, remote:string}
 *   fa      a Font Awesome glyph to draw instead of a picture, or ''
 *   ref     the cache key, or '' when there is no icon to be had at all
 *   url     where the browser can load it right now, or '' if not cached yet
 *   remote  where the server should fetch it from; '' means the collection
 *           knows, and staxx_icon_fetch() works it out from $ref
 */
function staxx_icon_resolve(string $icon, string $dir = '', string $image = '',
                               string $service = '', string $stack = ''): array {
  $none = ['fa' => '', 'ref' => '', 'url' => '', 'remote' => ''];
  $icon = trim($icon);

  if ($icon !== '') {
    // A Font Awesome glyph, which is what Unraid's own templates accept. Kept
    // working here so a template converted into a compose file does not lose
    // the icon it already had.
    if (preg_match('/^fa-[a-z0-9-]+$/i', $icon)) {
      return ['fa' => strtolower($icon), 'ref' => '', 'url' => '', 'remote' => ''];
    }

    // A URL. Cached under a hash of it rather than its filename, because two
    // stacks are perfectly likely to both point at something called icon.png.
    if (preg_match('#^https?://#i', $icon)) {
      $ref = 'url-'.md5($icon);
      return ['fa' => '', 'ref' => $ref, 'url' => staxx_icon_url($ref), 'remote' => $icon];
    }

    // A path relative to the stack directory. Copied into the cache rather than
    // served from where it sits: the stack directory is not inside the web
    // root, and putting it there would publish the whole directory.
    if ($dir !== '' && strpos($icon, '..') === false) {
      $path = $dir.'/'.ltrim($icon, '/');
      $ext  = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
      if (in_array($ext, STAXX_ICON_EXTS, true) && is_file($path)) {
        $ref = 'local-'.md5($path.'|'.(string)@filemtime($path));
        $url = staxx_icon_url($ref);
        if ($url === '') {
          $body = @file_get_contents($path);
          if ($body !== false && staxx_icon_store($ref, $ext, $body)) {
            $url = STAXX_ICON_BASE.'/'.$ref.'.'.$ext;
          }
        }
        // No remote: this one is copied from disk, never downloaded, so it is
        // resolved here and there is nothing for the background fetch to do.
        return ['fa' => '', 'ref' => $ref, 'url' => $url, 'remote' => ''];
      }
    }

    // Anything else is taken as a collection name, so `icon: jellyfin` works.
    $ref = staxx_icon_norm($icon);
    if (staxx_icon_safe_ref($ref) && isset(staxx_icon_index()['refs'][$ref])) {
      return ['fa' => '', 'ref' => $ref, 'url' => staxx_icon_url($ref), 'remote' => ''];
    }
    return $none;
  }

  $ref = staxx_icon_match($image, $service, $stack);
  if ($ref === '') return $none;

  return ['fa' => '', 'ref' => $ref, 'url' => staxx_icon_url($ref), 'remote' => ''];
}

/* -------------------------------------------------------------- fallback -- */

/**
 * How many colours the initials tile picks between. Must match the number of
 * .staxx-tile--N rules in the stylesheet.
 */
const STAXX_ICON_COLOURS = 10;

/**
 * The tile shown when there is no logo to show.
 *
 * Up to two letters and a colour, both worked out from the name itself, so the
 * same container is the same colour on every reload and on every server. A
 * random colour would shuffle the whole table on every refresh, which is worse
 * than the grey cube this replaces.
 *
 * @return array{text:string, colour:int}
 */
function staxx_icon_initials(string $name): array {
  // Split on the separators people actually use in service names, so
  // "media-server" gives MS while "postgres" gives PO.
  $words = preg_split('/[^A-Za-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

  if (count($words) >= 2) {
    $text = strtoupper(substr($words[0], 0, 1).substr($words[1], 0, 1));
  } elseif ($words) {
    $text = strtoupper(substr($words[0], 0, 2));
  } else {
    $text = '?';
  }

  // crc32 rather than a sum of characters: anagrams and near-identical names
  // are exactly the ones that most need telling apart, and a sum gives them the
  // same colour.
  return ['text' => $text, 'colour' => (int)(crc32(strtolower($name)) % STAXX_ICON_COLOURS)];
}
?>
