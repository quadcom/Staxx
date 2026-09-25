<?PHP
/* StaXX — icons for stacks and containers.
 * Copyright 2026, StaXX contributors.
 *
 * WHAT THIS FILE IS FOR
 *
 * Each service's icon is a picture living in its own stack's .staxx folder,
 * one per service — never a shared picture kept anywhere else. This finds
 * the picture for a row, in this order:
 *
 *   1. Whatever the compose file says, in `x-unraid: icon:` — a picture
 *      already sitting in that stack's .staxx folder, or an address.
 *   2. A match against the selfh.st icon collection, worked out from the
 *      container's image name, shown straight from the collection's own
 *      address rather than copied anywhere first.
 *   3. Nothing — and the caller draws a coloured tile with the row's initials.
 *
 * The collection lives at https://selfh.st/icons and is served from GitHub over
 * the jsDelivr CDN. It is CC-BY-4.0, and the credit sits on the settings page.
 *
 * NOTHING HERE REACHES THE NETWORK DURING A PAGE RENDER. A row's picture is
 * always either a file already on disk or a plain address the BROWSER loads —
 * this file never fetches anything to show a row, only to put a matched
 * picture into a stack's own folder once, in the background (see
 * staxx_icon_fetch_and_write()).
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

/** The reduced collection index — a list of names, never a picture, so it
 *  keeps living directly under StaXX's own config folder. '' when no store
 *  has been chosen yet. */
function staxx_icon_index_file(): string {
  $cfg = staxx_config_root();
  return $cfg === '' ? '' : $cfg.'/icon-index.json';
}

/** How stale the index may get before it is fetched again. */
const STAXX_ICON_INDEX_TTL = 7 * 86400;

/** File extensions a picture may be written and served under. */
const STAXX_ICON_EXTS = ['svg', 'png', 'webp', 'jpg', 'jpeg', 'gif', 'ico'];

/** PLAN_149 phase 3 — the size limit on a picture dropped straight in from
 *  the desktop, measured on the file's own decoded bytes. Generous on
 *  purpose: an icon is a kilobyte or two, so this refuses nothing anybody
 *  would sensibly drop on one while still stopping a photograph. */
const STAXX_ICON_DROP_MAX_BYTES = 512 * 1024;

/**
 * Where a failed download is remembered, so a broken source is not retried on
 * every sweep. Under /tmp, like STAXX_JOB_DIR and STAXX_STATS_DIR: this costs
 * no flash writes and clears itself at the next reboot, which is fine because
 * a reboot is exactly when it is safe to try again.
 */
const STAXX_ICON_MISS_DIR = '/tmp/staxx/icon-miss';

/** How long a failed download is remembered before it is tried again. */
const STAXX_ICON_MISS_TTL = 6 * 3600;

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

  $file = staxx_icon_index_file();
  $raw  = $file === '' ? false : @file_get_contents($file);
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

/**
 * True when the index is missing or old enough to be worth fetching again.
 * With nowhere to keep it yet, there is nothing worth asking for — a fetch
 * that cannot be saved is just a wasted request that will read as stale again
 * next time anyway — so this reads false rather than true.
 */
function staxx_icon_index_stale(): bool {
  $file = staxx_icon_index_file();
  if ($file === '') return false;
  $when = @filemtime($file);
  return $when === false || (time() - $when) > STAXX_ICON_INDEX_TTL;
}

/**
 * Download the collection index and store the reduction described above.
 *
 * Called from the background endpoint only, never from a page render.
 */
function staxx_icon_index_refresh(): bool {
  if (!staxx_icon_fetching()) return false;
  $file = staxx_icon_index_file();
  if ($file === '') return false;

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

  return staxx_icon_write($file,
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

/* ---------------------------------------------------------------- writing -- */

/** Write a file, creating its directory, without ever leaving a half file. */
function staxx_icon_write(string $path, string $body): bool {
  if ($path === '') return false; // no destination — never write to whatever dirname('') resolves to
  $dir = dirname($path);
  // An icon written inside the store must not be the thing that recreates a
  // store whose pool has gone — see the same guard in Folders.php.
  $storeRoot = staxx_store_root();
  if ($storeRoot !== '' && strpos($path, $storeRoot.'/') === 0 && !staxx_store_reachable()) return false;
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

  // CURLOPT_MAXFILESIZE only rejects a reply that *declares* itself too big
  // in Content-Length; a chunked reply carries no such header and would
  // otherwise write without limit to the flash device. The write callback
  // below is the backstop that actually enforces the cap byte-for-byte, so
  // RETURNTRANSFER is dropped in favour of building $body here instead.
  $cap  = 2 * 1024 * 1024;
  $body = '';
  $over = false;

  curl_setopt_array($ch, [
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 3,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => $seconds,
    CURLOPT_USERAGENT      => 'StaXX (Unraid plugin)',
    // An icon is kilobytes. A URL from a compose file is not necessarily an
    // icon, and a plugin that will happily download a DVD image because a
    // compose file asked it to is a plugin that fills the flash device.
    CURLOPT_MAXFILESIZE    => $cap,
    CURLOPT_WRITEFUNCTION  => function ($ch, string $chunk) use (&$body, &$over, $cap): int {
      $body .= $chunk;
      // Returning anything other than the chunk's own length tells curl the
      // write failed, which aborts the transfer immediately.
      if (strlen($body) > $cap) { $over = true; return -1; }
      return strlen($chunk);
    },
  ]);

  $ok   = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);

  if ($over || $ok === false || $body === '' || $code < 200 || $code > 299) return null;
  return $body;
}

/**
 * GitHub's page for a file, turned into the file itself.
 *
 * Copying an image's address out of GitHub gives the /blob/ link — the web
 * page that displays it — and that answers 200 with a quarter of a megabyte
 * of HTML. The fetch below correctly refuses it as not-a-picture and marks it
 * failed, so the icon silently never appears and never retries: an honest
 * refusal to an address the author was entitled to think was the picture.
 * Rewriting it is lossless, since the two address forms name the same file.
 *
 * Hashing happens AFTER this, so correcting an address already recorded as
 * failed produces a different cache reference and is tried again at once
 * rather than waiting for the failure marker to expire.
 */
function staxx_icon_raw_url(string $url): string {
  // The branch or tag can itself contain a slash, so the path is whatever
  // follows it — matched lazily up to /blob/ and then taken wholesale.
  if (preg_match('#^https?://github\.com/([^/]+)/([^/]+)/(?:blob|raw)/(.+)$#i', $url, $m)) {
    return 'https://raw.githubusercontent.com/'.$m[1].'/'.$m[2].'/'.$m[3];
  }
  return $url;
}

/**
 * The real file include/icon.php would stream for $stack/$file, or '' to
 * refuse — the one place both that page and its own test suite decide what
 * is safe to serve, so the two can never quietly disagree.
 *
 * Refuses, in order: a $stack staxx_list_stacks() does not itself report
 * (never merely a path that parses); a $file that is not a bare, safe name
 * with a picture extension; and — resolved through realpath(), which is
 * what actually catches a symlink anywhere in the way, not only the file's
 * own last component — anything whose real parent directory is not the
 * stack's own .staxx folder exactly (no subfolder, so STAXX_RECORD_DIR's own
 * history/ tree is never reachable this way) or that is not a plain file.
 */
function staxx_icon_serve_path(string $stack, string $file): string {
  if (!staxx_valid_path($stack) || !staxx_valid_filename($file)) return '';
  // staxx_valid_filename() allows one optional leading dot — right for a
  // companion file like ".env", wrong here: no icon this plugin ever writes
  // is named with one, so refusing it outright is one condition to keep
  // right rather than a name this function has to trust.
  if ($file[0] === '.') return '';

  $ext = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
  if (!in_array($ext, STAXX_ICON_EXTS, true)) return '';

  $dir = '';
  foreach (staxx_list_stacks() as $s) {
    if ($s['name'] === $stack) { $dir = $s['dir']; break; }
  }
  if ($dir === '') return '';

  $path = $dir.'/'.STAXX_RECORD_DIR.'/'.$file;
  $real = @realpath($path);
  $recordReal = @realpath($dir.'/'.STAXX_RECORD_DIR);
  if ($real === false || $recordReal === false) return '';
  if (dirname($real) !== $recordReal) return '';
  if (!is_file($real) || is_link($path)) return '';

  return $real;
}

/**
 * Where the browser loads an already-adopted picture from: the address that
 * reaches include/icon.php, which streams the file straight out of a
 * stack's own .staxx folder. '' when $file is not actually sitting there —
 * a stated icon field pointing at a file that no longer exists is exactly
 * what must fall through to initials rather than link to a 404.
 *
 * $dir is the stack's own directory; the identity the URL carries
 * (staxx_list_stacks()'s own 'name') is worked out from it here, against
 * staxx_stack_root(), rather than trusted from a caller that may not have
 * it to hand — staxx_icon_resolve()'s first, icon-only pass calls this with
 * nothing else.
 *
 * The address carries the file's own mtime so a replaced picture is never
 * served stale out of the browser's cache — nothing here ever has to be
 * invalidated by hand.
 */
function staxx_icon_serve_url(string $dir, string $file): string {
  if (!staxx_valid_filename($file) || $file[0] === '.') return '';
  $ext = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
  if (!in_array($ext, STAXX_ICON_EXTS, true)) return '';

  $path = $dir.'/'.STAXX_RECORD_DIR.'/'.$file;
  if (is_link($path) || !is_file($path)) return '';

  $root = staxx_stack_root();
  if ($root === '' || strncmp($dir, $root.'/', strlen($root) + 1) !== 0) return '';
  $stack = substr($dir, strlen($root) + 1);

  $mtime = (int)@filemtime($path);
  return '/plugins/'.STAXX_PLUGIN.'/include/icon.php?stack='.rawurlencode($stack)
       . '&file='.rawurlencode($file).'&v='.$mtime;
}

/**
 * The collection's own address for a matched reference — svg first, since it
 * is a fraction of the size and stays sharp at any zoom, png only when that
 * is all the collection holds. '' when $ref is not actually in the index, so
 * a stale or hand-typed reference never links to a picture that is not there.
 *
 * Loaded by the browser directly from jsDelivr; nothing here fetches or
 * keeps a copy of it. See staxx_icon_fetch_and_write() for the one place a
 * matched picture is ever downloaded, which happens purely to give the
 * compose file's own icon: line something inside the stack to point at.
 */
function staxx_icon_cdn_url(string $ref): string {
  if (!staxx_icon_safe_ref($ref)) return '';
  $have = staxx_icon_index()['refs'][$ref] ?? '';
  if ($have === '') return '';
  $ext = strpos($have, 's') !== false ? 'svg' : 'png';
  return STAXX_ICON_CDN.'/'.$ext.'/'.$ref.'.'.$ext;
}

/**
 * Find a picture for a service with none and write it straight into that
 * stack's own .staxx folder — the one place a matched or pasted icon is
 * ever downloaded to. Named for the service ($baseName), not the collection
 * reference or the address, so the folder reads sensibly to anyone who
 * opens it by hand.
 *
 * $remote, when given, is fetched as-is (a pasted or template address,
 * already run through staxx_icon_raw_url()); otherwise $ref must be a
 * collection match and the picture comes from staxx_icon_cdn_url().
 * Exactly one of the two is ever set — the caller decides which kind of
 * find this is.
 *
 * Returns the relative path to record as the compose file's icon: value
 * (e.g. './.staxx/sonarr.svg'), or '' with $error set to a sentence.
 * $failRef, always set on the way in by the caller, is what
 * staxx_icon_mark_missed() remembers a failure against, so a source that
 * cannot be reached is not retried on every single sweep.
 */
function staxx_icon_fetch_and_write(string $dir, string $baseName, string $remote,
                                     string $ref, string $failRef, string &$error): string {
  $error = '';

  // Structural checks first — is there even anything named to try, and is
  // there somewhere to put it — and only then the policy question of
  // whether fetching is allowed at all. Ordered this way rather than
  // fetching-first so each refusal names the thing actually wrong rather
  // than always reporting "switched off" first regardless of what else is
  // also missing; it makes no difference to what a real caller ever sees,
  // since a real caller only ever hits exactly one of these at a time.
  $sources = $remote !== '' ? [[$remote, '']] : [];
  if ($remote === '' && $ref !== '') {
    $have = staxx_icon_index()['refs'][$ref] ?? '';
    if (strpos($have, 's') !== false) $sources[] = [STAXX_ICON_CDN.'/svg/'.$ref.'.svg', 'svg'];
    if (strpos($have, 'p') !== false) $sources[] = [STAXX_ICON_CDN.'/png/'.$ref.'.png', 'png'];
  }
  if (!$sources) { $error = 'Nothing to fetch.'; return ''; }

  if (!is_dir($dir) || !is_writable($dir)) { $error = 'The stack folder cannot be written to.'; return ''; }

  if (!staxx_icon_fetching()) { $error = 'Icon lookups are switched off.'; return ''; }

  $stem = staxx_icon_norm($baseName);
  if ($stem === '') $stem = 'icon';

  foreach ($sources as [$url, $ext]) {
    $body = staxx_icon_get($url);
    if ($body === null) continue;

    if ($ext === '') {
      // An address typed or pasted by whoever wrote the compose file is not
      // evidence of what it actually points to — an SVG is left out of the
      // guesses entirely, same reasoning as the collection fetch below.
      foreach (STAXX_ICON_EXTS as $candidate) {
        if ($candidate === 'svg') continue;
        if (staxx_icon_is_picture($candidate, $body)) { $ext = $candidate; break; }
      }
      if ($ext === '') continue;
    } elseif (!staxx_icon_is_picture($ext, $body)) {
      continue;
    }

    $file     = $stem.'.'.$ext;
    $target   = $dir.'/'.STAXX_RECORD_DIR.'/'.$file;
    $relative = './'.STAXX_RECORD_DIR.'/'.$file;

    if (is_file($target)) {
      // Already there under this name. Identical bytes is a no-op success —
      // this must be safe to run twice — a different picture is left alone
      // rather than overwritten, since it is not this plugin's to replace.
      if (md5_file($target) === md5($body)) return $relative;
      $error = 'A different picture is already saved under that name for this stack.';
      return '';
    }

    if (!staxx_icon_write($target, $body)) { $error = 'The icon could not be written to the stack folder.'; return ''; }
    return $relative;
  }

  staxx_icon_mark_missed($failRef);
  $error = 'Could not fetch a picture.';
  return '';
}

/**
 * Write a picture handed over directly (dragged off the desktop, not an
 * address) into the stack's own .staxx folder. The bytes are already in
 * hand, sent as text in the ordinary post the page already uses rather
 * than a multipart upload, which hangs on this box. $body is therefore the
 * DECODED file — the caller's job, not this function's, since decoding is
 * about how the bytes travelled, and this is only about what they are.
 *
 * Refuses in this order, and returns '' with $error set to the exact
 * sentence PLAN_149 settled on for each:
 *
 *   - over STAXX_ICON_DROP_MAX_BYTES, measured on these bytes directly —
 *     the browser's own size check is a courtesy, never a guarantee;
 *   - the wrong shape for $filename's own extension, or an extension
 *     outside STAXX_ICON_EXTS at all — proved by staxx_icon_is_picture()
 *     against the actual bytes, never by the name alone;
 *   - no folder to write into — a stack that has never been saved has none,
 *     and one is never created behind the person's back to make room for
 *     this.
 *
 * Named from $filename's own stem, cleaned to safe characters and
 * lower-cased, falling back to a hash of the body when nothing survives the
 * clean, rather than a bare extension nobody could tell apart from another
 * dropped picture. There is no address to record beside it, so nothing is
 * ever appended as a comment; the plan is explicit that only a note of the
 * drop itself belongs there, and that note is the caller's to write, once,
 * into the compose file's own icon line (see action.php's 'icon-drop' case).
 *
 * Safe to call twice with the same picture: identical bytes already under
 * that name is a no-op success — needed for the same reason
 * staxx_icon_fetch_and_write() is, a repeated drop landing one file rather
 * than two.
 */
function staxx_icon_adopt_drop(string $dir, string $filename, string $body, string &$error): string {
  $error = '';

  if (strlen($body) > STAXX_ICON_DROP_MAX_BYTES) {
    $error = 'Too big — icons must be under 512 KB';
    return '';
  }

  $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
  if (!in_array($ext, STAXX_ICON_EXTS, true) || !staxx_icon_is_picture($ext, $body)) {
    $error = 'Not a picture';
    return '';
  }

  if (!is_dir($dir) || !is_writable($dir)) {
    $error = 'Save the stack first';
    return '';
  }

  $stem = strtolower(pathinfo($filename, PATHINFO_FILENAME));
  $stem = (string)preg_replace('/[^a-z0-9._-]+/', '', $stem);
  $stem = trim($stem, '.-');
  if ($stem === '') $stem = 'icon-'.substr(md5($body), 0, 8);
  $file = $stem.'.'.$ext;

  $recordDir = $dir.'/'.STAXX_RECORD_DIR;
  $target    = $recordDir.'/'.$file;
  $relative  = './'.STAXX_RECORD_DIR.'/'.$file;

  if (is_file($target)) {
    // Already there. Identical contents is a no-op success — a repeated
    // drop must land one file, not two.
    if (md5_file($target) === md5($body)) return $relative;
    $error = 'A different picture is already saved under that name for this stack. '
           . 'Rename the icon, or remove the one already there, and try again.';
    return '';
  }

  if (!staxx_icon_write($target, $body)) { $error = 'The icon could not be written to the stack folder.'; return ''; }
  return $relative;
}

/**
 * Does the body actually look like the picture format $ext claims?
 *
 * HTTP saying 2xx only means a server answered, not that it answered with a
 * picture — a GitHub error page saved as icon.png is exactly the failure this
 * catches. Checked by magic bytes rather than GD, which is one dependency
 * fewer and is exactly what tells a real PNG from HTML wearing a .png name.
 */
function staxx_icon_is_picture(string $ext, string $body): bool {
  switch ($ext) {
    case 'png':
      return substr($body, 0, 8) === "\x89PNG\r\n\x1a\n";
    case 'jpg':
    case 'jpeg':
      return substr($body, 0, 3) === "\xFF\xD8\xFF";
    case 'gif':
      return substr($body, 0, 6) === 'GIF87a' || substr($body, 0, 6) === 'GIF89a';
    case 'webp':
      return substr($body, 0, 4) === 'RIFF' && substr($body, 8, 4) === 'WEBP';
    case 'ico':
      return substr($body, 0, 4) === "\x00\x00\x01\x00";
    case 'svg':
      // Text, not magic bytes: an XML prolog, a comment or a DOCTYPE may
      // legitimately precede the <svg> tag itself.
      $head = strtolower(substr($body, 0, 1024));
      return strpos($head, '<svg') !== false && strpos($head, '<html') === false;
    default:
      return false;
  }
}

/* --------------------------------------------------------------- fetching -- */

/**
 * Was this ref tried recently and found not to work?
 *
 * The marker file is named after the ref with no extension. That is safe
 * because a ref only ever reaches here after staxx_icon_safe_ref() has
 * already restricted it to lower-case letters, digits and hyphens.
 */
function staxx_icon_missed(string $ref): bool {
  $when = @filemtime(STAXX_ICON_MISS_DIR.'/'.$ref);
  return $when !== false && (time() - $when) <= STAXX_ICON_MISS_TTL;
}

/** Record that a download for $ref failed, so the next sweep skips it. */
function staxx_icon_mark_missed(string $ref): void {
  if (!is_dir(STAXX_ICON_MISS_DIR)) @mkdir(STAXX_ICON_MISS_DIR, 0755, true);
  @touch(STAXX_ICON_MISS_DIR.'/'.$ref);
}

/* --------------------------------------------------------------- resolving -- */

/**
 * Decide what one row's icon is. Never fetches or copies anything: a
 * picture is either already sitting in the stack's own .staxx folder, or
 * loaded straight from wherever it is addressed (a pasted address, or the
 * collection's own CDN) — nothing here waits on the network, so this is
 * exactly as safe to call during a page render as it always was.
 *
 * @param string $icon    the x-unraid `icon:` value, or ''
 * @param string $dir     the stack directory, so a `./.staxx/...` value can
 *                        be checked against the file actually sitting there
 * @param string $image   the container image, for automatic matching
 * @param string $service the service name, tried after the image
 * @param string $stack   the stack name, tried last
 *
 * @return array{fa:string, ref:string, url:string, remote:string}
 *   fa      a Font Awesome glyph to draw instead of a picture, or ''
 *   ref     a collection reference or address hash, kept only so
 *           staxx_icon_missed() can be asked about it; '' when there is
 *           nothing to look for at all
 *   url     where the browser can load the picture right now, or '' when
 *           there genuinely is none
 *   remote  the address a picture with no url yet should be fetched from to
 *           adopt it into the stack — '' when $ref names a collection match
 *           instead, which staxx_icon_fetch_and_write() works out for itself
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

    // A URL — shown straight from the address itself, whoever pasted it.
    // Nothing is fetched here; staxx_icon_fetch_and_write() is what turns
    // this into a picture living inside the stack, in the background.
    if (preg_match('#^https?://#i', $icon)) {
      $icon = staxx_icon_raw_url($icon);
      $ref = 'url-'.md5($icon);
      return ['fa' => '', 'ref' => $ref, 'url' => $icon, 'remote' => $icon];
    }

    // A path into the stack's own .staxx folder — the shape every icon this
    // plugin has ever adopted is written as. Anything else (a bare filename
    // beside the compose file, an absolute path on this server) has no
    // picture to show: nothing outside .staxx is ever read or copied to
    // display an icon.
    $bare = preg_replace('#^\./#', '', $icon);
    $prefix = STAXX_RECORD_DIR.'/';
    if ($dir !== '' && strncmp($bare, $prefix, strlen($prefix)) === 0) {
      $file = substr($bare, strlen($prefix));
      $url  = staxx_icon_serve_url($dir, $file);
      if ($url !== '') return ['fa' => '', 'ref' => '', 'url' => $url, 'remote' => ''];
      return $none;
    }

    // Anything else is taken as a collection name, so `icon: jellyfin` works.
    $ref = staxx_icon_norm($icon);
    if (staxx_icon_safe_ref($ref) && isset(staxx_icon_index()['refs'][$ref])) {
      return ['fa' => '', 'ref' => $ref, 'url' => staxx_icon_cdn_url($ref), 'remote' => ''];
    }
    return $none;
  }

  $ref = staxx_icon_match($image, $service, $stack);
  if ($ref === '') return $none;

  return ['fa' => '', 'ref' => $ref, 'url' => staxx_icon_cdn_url($ref), 'remote' => ''];
}

/* ------------------------------------------------------------- migration -- */

/** Guards staxx_icons_into_stacks_auto() so it runs at most once per
 *  install — see that function's own comment. */
function staxx_icons_migrated_marker(): string {
  $cfg = staxx_config_root();
  return $cfg === '' ? '' : $cfg.'/.icons-in-stacks';
}

/**
 * Rewrite one service's `icon:` line in a compose file's own text, in place,
 * to $newValue — the one piece of hand-written text surgery this plugin's
 * migration needs, because nothing server-side otherwise parses and
 * reserialises a compose file the way the browser's own YAML editor does.
 *
 * Deliberately narrow rather than a general nested-key writer: it finds
 * $service's own top-level block under `services:` by name and indentation
 * (from that line down to the next line indented no further than it), and
 * inside that block only, a line reading `icon:` whose CURRENT value is
 * exactly $oldValue once quotes are stripped. Both conditions have to hold —
 * the right service AND the value staxx_compose_meta() already read for it —
 * so this can never rewrite a line it was not specifically looking for.
 * Returns the rewritten text, or null when the line could not be found
 * uniquely, which the caller treats as "leave this one for by hand".
 */
function staxx_icon_migrate_line(string $composeText, string $service, string $oldValue, string $newValue): ?string {
  $lines = explode("\n", $composeText);
  $n     = count($lines);

  // The service's own line: some amount of leading spaces, then its name and
  // a colon, nothing else worth arguing with. staxx_valid_name() already
  // limits what a service may be called, so this is not trying to parse
  // arbitrary YAML keys, only find the one already known to exist.
  $svcPattern = '/^(\s+)'.preg_quote($service, '/').':\s*(#.*)?$/';
  $start = -1;
  $indent = '';
  for ($i = 0; $i < $n; $i++) {
    if (preg_match($svcPattern, $lines[$i], $m)) { $start = $i; $indent = $m[1]; break; }
  }
  if ($start === -1) return null;

  $end = $n;
  for ($i = $start + 1; $i < $n; $i++) {
    $line = $lines[$i];
    if (trim($line) === '') continue;
    $lead = (string)preg_replace('/[^ ].*$/', '', $line);
    if (strlen($lead) <= strlen($indent)) { $end = $i; break; }
  }

  $target = -1;
  for ($i = $start + 1; $i < $end; $i++) {
    if (!preg_match('/^\s*icon:\s*["\']?([^"\'\r\n]*?)["\']?\s*$/', $lines[$i], $m)) continue;
    if (trim($m[1]) !== $oldValue) continue;
    if ($target !== -1) return null; // more than one match — not safe to guess between them
    $target = $i;
  }
  if ($target === -1) return null;

  $lead = (string)preg_replace('/[^ ].*$/', '', $lines[$target]);
  $lines[$target] = $lead.'icon: '.$newValue;
  return implode("\n", $lines);
}

/**
 * Put existing stacks right: every service whose icon is not already a
 * picture sitting in its own stack's .staxx folder gets one copied or
 * downloaded there, and its `icon:` line rewritten to point at it — through
 * staxx_save_stack(), so it lands in history and the notice says what
 * changed, exactly like any other edit this plugin makes on a stack's behalf.
 *
 * Three sources, tried in the order a service's own icon: value names one:
 *   - a plain `http(s)://` address — fetched, same as staxx_icon_fetch_and_write()
 *     does for a newly-matched service;
 *   - a name the collection recognises — downloaded from there;
 *   - a path to a picture already sitting on this server (relative to the
 *     stack's own folder, or absolute) — copied, never fetched.
 * A Font Awesome glyph, an empty field, or a value already inside .staxx
 * (whether or not the file is actually there) is left alone: the first two
 * name no picture to move, and the third is either already migrated or a
 * broken reference nothing here invented.
 *
 * $dryRun changes nothing and only reports what it would do — every entry
 * staxx_save_stack() would otherwise be asked to write, and every file under
 * the OLD shared icon folder (config/icons, kept only as long as this
 * function has not yet run for real) that a real run would go on to remove.
 * A real run removes that folder itself once every stack is done, keeping
 * only its collection index.
 *
 * @return array{moved: array<int, array{stack:string, service:string, from:string, to:string}>,
 *               removed: string[], errors: array<int, string>}
 */
function staxx_icons_into_stacks(bool $dryRun): array {
  $moved     = [];
  $removed   = [];
  $errors    = [];
  $anyWrites = false;

  $oldStore = staxx_config_root();
  $oldStore = $oldStore === '' ? '' : $oldStore.'/icons';

  foreach (staxx_list_stacks() as $s) {
    if (!$s['parses']) continue;
    $file = $s['file'];
    if ($file === '') continue;

    $meta = staxx_compose_meta($file);
    if (!$meta['ok']) continue;

    $text    = (string)@file_get_contents($file);
    $changed = false;

    foreach ($meta['services'] as $svc => $svcMeta) {
      $icon = trim((string)($svcMeta['x']['icon'] ?? ''));
      if ($icon === '' || preg_match('/^fa-[a-z0-9-]+$/i', $icon)) continue;

      $bare = preg_replace('#^\./#', '', $icon);
      if (strncmp($bare, STAXX_RECORD_DIR.'/', strlen(STAXX_RECORD_DIR) + 1) === 0) continue; // already migrated (or broken; not this pass's job)

      $written = '';
      $error   = '';

      if (preg_match('#^https?://#i', $icon)) {
        $remote  = staxx_icon_raw_url($icon);
        $failRef = 'url-'.md5($remote);
        if (!$dryRun) $written = staxx_icon_fetch_and_write($s['dir'], $svc, $remote, '', $failRef, $error);
        else          $written = './'.STAXX_RECORD_DIR.'/'.staxx_icon_norm($svc).'.svg'; // reported shape only
      } elseif (staxx_icon_safe_ref(staxx_icon_norm($icon))
                && isset(staxx_icon_index()['refs'][staxx_icon_norm($icon)])) {
        $ref = staxx_icon_norm($icon);
        if (!$dryRun) $written = staxx_icon_fetch_and_write($s['dir'], $svc, '', $ref, $ref, $error);
        else          $written = './'.STAXX_RECORD_DIR.'/'.staxx_icon_norm($svc).'.svg';
      } else {
        // A local path: relative to the stack's own folder, or absolute on
        // this server (some Unraid templates give one instead of an
        // address). Copied, never fetched — the picture is already here.
        $local = ($icon !== '' && $icon[0] === '/' && strpos($icon, '..') === false)
          ? $icon
          : ($s['dir'] !== '' && strpos($icon, '..') === false ? $s['dir'].'/'.ltrim($icon, '/') : '');
        $ext = strtolower((string)pathinfo($local, PATHINFO_EXTENSION));
        if ($local !== '' && is_file($local) && !is_link($local)
            && in_array($ext, STAXX_ICON_EXTS, true)) {
          $body = @file_get_contents($local);
          if ($body !== false && staxx_icon_is_picture($ext, $body)) {
            $stem   = staxx_icon_norm($svc) ?: 'icon';
            $target = $s['dir'].'/'.STAXX_RECORD_DIR.'/'.$stem.'.'.$ext;
            $rel    = './'.STAXX_RECORD_DIR.'/'.$stem.'.'.$ext;
            if (is_file($target) && md5_file($target) !== md5($body)) {
              $error = 'A different picture is already saved under that name.';
            } elseif ($dryRun) {
              $written = $rel;
            } elseif (is_file($target) || staxx_icon_write($target, $body)) {
              $written = $rel;
            } else {
              $error = 'Could not write the picture into the stack folder.';
            }
          }
        }
      }

      if ($written === '') {
        if ($error !== '') $errors[] = $s['name'].' / '.$svc.': '.$error;
        continue;
      }

      $moved[] = ['stack' => $s['name'], 'service' => $svc, 'from' => $icon, 'to' => $written];

      if (!$dryRun) {
        $rewritten = staxx_icon_migrate_line($text, $svc, $icon, $written);
        if ($rewritten === null) {
          $errors[] = $s['name'].' / '.$svc.': the picture was saved, but its icon: line could not be '
                    . 'found to rewrite — fix it by hand to "'.$written.'".';
          continue;
        }
        $text    = $rewritten;
        $changed = true;
      }
    }

    if ($changed) {
      $saveError = '';
      if (!staxx_save_stack($s['name'], $text, $saveError)) {
        $errors[] = $s['name'].': the stack could not be saved — '.$saveError;
      } else {
        $anyWrites = true;
      }
    }
  }

  // staxx_compose_meta() memoises per process, keyed on a file's contents —
  // exactly what a save just changed. Nothing here would otherwise notice
  // until the next request, which matters because this same function can
  // run again (a second install-time pass, or a script re-run) inside the
  // one process that just wrote these files. The same reset action.php's
  // 'import-list' case already makes after staxx_import_backfill() writes.
  if ($anyWrites) {
    $err = null;
    staxx_compose_meta('', $err, true);
    staxx_scan_stacks_reset();
  }

  // The old shared folder is only ever removed on a real run, and only once
  // every stack above has had its turn — a dry run must change nothing at
  // all, on disk or in the compose files.
  if (!$dryRun && $oldStore !== '' && is_dir($oldStore)) {
    foreach ((array)@scandir($oldStore) as $entry) {
      if ($entry === '.' || $entry === '..' || $entry === '_index.json') continue;
      $path = $oldStore.'/'.$entry;
      if (is_file($path)) { @unlink($path); $removed[] = $entry; }
    }
  } elseif ($dryRun && $oldStore !== '' && is_dir($oldStore)) {
    foreach ((array)@scandir($oldStore) as $entry) {
      if ($entry === '.' || $entry === '..' || $entry === '_index.json') continue;
      if (is_file($oldStore.'/'.$entry)) $removed[] = $entry;
    }
  }

  return ['moved' => $moved, 'removed' => $removed, 'errors' => $errors];
}

/**
 * Run staxx_icons_into_stacks() for real, but only the first time anything
 * asks — guarded by a marker file next to the (by then, removed) old icon
 * folder, so every page load and sweep after the first is a single
 * is_file() check. Called from the same place the icon-adopt sweep already
 * runs from (action.php's 'icon-todo' case), which is reached once per page
 * load and periodically thereafter, rather than adding a second hook for a
 * migration that only ever needs to run once.
 */
function staxx_icons_into_stacks_auto(): void {
  $marker = staxx_icons_migrated_marker();
  if ($marker === '' || is_file($marker)) return;
  staxx_icons_into_stacks(false);
  @touch($marker);
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
