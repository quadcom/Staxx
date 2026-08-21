<?PHP
/* StaXX — Community Applications catalogue.
 * Copyright 2026, StaXX contributors.
 *
 * WHAT THIS FILE IS FOR
 *
 * Community Applications publishes one feed listing every app in its catalogue
 * — 4000+ entries, 24 MB of JSON. That is too large to decode on a page render
 * (or on every search), so it is fetched and reduced once, in the background,
 * by scripts/ca-index.php, into a cache this file only ever reads:
 *
 *   apps.jsonl   one app's full JSON per line, fat fields (screenshots, trend
 *                history, moderator notes) stripped, for staxx_ca_app()
 *   index.json   a small array of the fields search needs — name, repository,
 *                icon, category, downloads, a one-line overview — plus each
 *                app's byte offset into apps.jsonl
 *
 * NOTHING HERE REACHES THE NETWORK. Only scripts/ca-index.php downloads
 * anything; this file reads what it wrote, and asks it to run again when the
 * cache is missing or stale.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
if (defined('STAXX_CA_DIR')) return;
require_once '/usr/local/emhttp/plugins/staxx/include/Defines.php';

/** Where the cache lives. /tmp rather than the flash device: this is a
 *  reducible download, not something worth surviving a reboot, and flash
 *  writes are the one thing on Unraid worth being stingy with. */
const STAXX_CA_DIR    = '/tmp/staxx/ca';
const STAXX_CA_APPS   = STAXX_CA_DIR.'/apps.jsonl';
const STAXX_CA_INDEX  = STAXX_CA_DIR.'/index.json';
const STAXX_CA_STATUS = STAXX_CA_DIR.'/status.json';

/** Atomic-mkdir lock, the same trick scripts/stats-collector.sh uses.
 *  Outside STAXX_CA_DIR on purpose: the rebuild swap renames that whole
 *  directory away and deletes the old one, which would carry the lock off
 *  and release it before the new cache is actually in place. */
const STAXX_CA_LOCK = '/tmp/staxx/ca.lock';

/** How long a built index is trusted before a search triggers a rebuild. */
const STAXX_CA_TTL = 24 * 3600;

/**
 * The shape of what the indexer keeps. Bumped whenever a change to
 * scripts/ca-index.php means an existing cache no longer holds everything the
 * page needs — an old cache is otherwise indistinguishable from a current one,
 * since the freshness stamp describes CA's feed rather than our build of it.
 */
// v4 added the 'home' block (Spotlight/Recently Added/Top Trending ordinals).
const STAXX_CA_INDEX_VERSION = 4;

/**
 * index.json, decoded once per request and shared by every function below —
 * it is asked for on every keystroke of a search, and re-decoding a megabyte
 * of JSON per keystroke would make the search box feel sluggish for no
 * reason.
 *
 * @return array{built:int, count:int, categories:array<int,string>, apps:array<int,array>}
 */
function staxx_ca_index_data(): array {
  static $data = null;
  if ($data !== null) return $data;

  $empty = ['built' => 0, 'count' => 0, 'categories' => [], 'apps' => []];

  $raw = @file_get_contents(STAXX_CA_INDEX);
  if ($raw === false) return $data = $empty;

  $decoded = json_decode($raw, true);
  if (!is_array($decoded)) return $data = $empty;

  $merged = array_merge($empty, $decoded);
  // A corrupt cache file can carry 'apps' as anything JSON allows, not just an
  // array — and count() on a scalar is a PHP 8 TypeError, fatal for the whole
  // search box rather than just this one stale-looking result.
  if (!is_array($merged['apps'])) $merged['apps'] = [];
  return $data = $merged;
}

/**
 * Where the catalogue stands, for the search box to decide whether to show
 * results, a "downloading" message, or trigger a rebuild.
 *
 * A missing or stale index always wins over whatever status.json claims,
 * except for a build that is genuinely in progress right now — judged by
 * status.json's own age, not its content, because a build killed by a reboot
 * leaves a "building" status behind forever otherwise.
 *
 * `usable` is the one the caller usually wants: whether there is a catalogue
 * on disk worth showing, whatever `state` says about its age. A day-old cache
 * is still 4,000 apps, and making someone watch a progress message rather than
 * handing them results that are already there would be a worse page for no
 * gain — the refresh can happen behind it.
 *
 * @return array{state:string, message:string, built:int, count:int, usable:bool}
 */
function staxx_ca_status(): array {
  $raw    = @file_get_contents(STAXX_CA_STATUS);
  $status = $raw !== false ? json_decode($raw, true) : null;
  if (!is_array($status)) $status = ['state' => 'stale', 'message' => '', 'built' => 0];

  // Counted from the index itself, not from status.json — status.json is a
  // claim, and what matters here is whether there are actually apps to show.
  $usable = count(staxx_ca_index_data()['apps']) > 0;

  $count = (int)($status['count'] ?? 0);
  if (!$count) $count = (int)(staxx_ca_index_data()['count'] ?? 0);

  $indexTime = @filemtime(STAXX_CA_INDEX);
  $stale     = $indexTime === false || (time() - $indexTime) > STAXX_CA_TTL;

  // A cache built by an older indexer is also stale, even if it is young by
  // the clock — it may be missing a field the page now reads (screenshots,
  // say). Still USABLE, though: the existing "stale but usable" path below
  // rebuilds it behind the user, and replacing 3,600 working apps with a
  // progress message over one missing field would be a worse page.
  $stale = $stale || (int)(staxx_ca_index_data()['v'] ?? 0) !== STAXX_CA_INDEX_VERSION;

  if ($stale) {
    // A "building" status only counts while it is fresh — five minutes is
    // generous for a 24 MB download plus a split, and anything older than
    // that is a build nothing is still running, most likely one a reboot cut
    // off mid-way. Reporting it as stale lets the next search restart it,
    // rather than the feature being wedged until someone notices by hand.
    $statusTime = @filemtime(STAXX_CA_STATUS);
    $recent     = $statusTime !== false && (time() - $statusTime) < 300;
    $said       = (string)($status['state'] ?? '');

    // A failure is reported as a failure for the same five minutes, rather than
    // as "stale". Reading it as stale would have the page start a fresh
    // download on every poll — three seconds apart, forever, on a server that
    // has just told us it cannot reach the internet. After five minutes it
    // does go back to stale, so trying again is only ever a wait, never a
    // restart of the webGUI.
    $state = 'stale';
    if ($recent && ($said === 'building' || $said === 'failed')) $state = $said;

    return [
      'state'   => $state,
      'message' => (string)($status['message'] ?? ''),
      'built'   => (int)($status['built'] ?? 0),
      'count'   => $count,
      'usable'  => $usable,
    ];
  }

  // An index that is present and inside its TTL is usable, and that fact beats
  // anything status.json has to say. Two cases depend on this. A rebuild that
  // failed writes state=failed beside the PREVIOUS cache, which is still
  // perfectly good — reading that as a failure would restart a doomed download
  // on every keystroke and show the user nothing, while a working catalogue sat
  // on disk. And the swap that installs a new cache carries the old
  // status.json away with it, so there is a moment, and after a stray deletion
  // a permanent state, where index.json is fresh and status.json is absent.
  return [
    'state'   => 'ready',
    'message' => (string)($status['message'] ?? ''),
    'built'   => (int)($status['built'] ?: $indexTime),
    'count'   => $count,
    'usable'  => $usable,
  ];
}

/**
 * Kick off a rebuild in the background, unless one is already running.
 *
 * The lock is what stops two searches arriving seconds apart from starting
 * two downloads of the same 24 MB feed. It is released by ca-index.php
 * itself, once the rebuild finishes or fails — never here.
 */
function staxx_ca_refresh_start(): void {
  if (is_dir(STAXX_CA_LOCK)) {
    // Thirty minutes is far longer than a download-and-split ever takes, so a
    // lock still standing after that is left over from a build a reboot or a
    // kill -9 cut short, not one still working. Taking it over is what stops
    // a single interrupted build wedging the feature forever.
    if (time() - (int)@filemtime(STAXX_CA_LOCK) < 1800) return;
    @rmdir(STAXX_CA_LOCK);
  }

  // mkdir is the lock: if another request's mkdir won this same race, ours
  // fails here and we simply do not start a second download.
  if (!@mkdir(STAXX_CA_LOCK, 0755, true)) return;

  $log = '/tmp/staxx/ca-build.log';
  $cmd = 'php '.escapeshellarg(STAXX_ROOT.'/scripts/ca-index.php');

  // setsid detaches the build from this request exactly as staxx_start_job()
  // detaches a compose command — see Stacks.php — so a page load never waits
  // on a 24 MB download.
  @exec('setsid sh -c '.escapeshellarg($cmd).' </dev/null >> '.escapeshellarg($log).' 2>&1 &');
}

/**
 * One index entry, plus its ordinal, shaped into what the browser wants for
 * a card — shared by staxx_ca_search() and staxx_ca_home() so there is
 * exactly one place that decides what a hit looks like.
 *
 * @return array{i:int,n:string,r:string,a:string,ic:string,c:string[],d:int,ov:string,dep?:int,st?:int}
 */
function staxx_ca_row(array $app, int $i): array {
  $appCats = $app['c'] ?? [];
  if (!is_array($appCats)) $appCats = ($appCats === '' ? [] : [$appCats]);
  // o/len (the byte offset into apps.jsonl) never leave the server — the
  // client only ever sees the ordinal below, so a tampered value is a
  // missing array key in staxx_ca_app(), not an arbitrary file read.
  $row = [
    'i'  => $i,
    'n'  => (string)($app['n'] ?? ''),
    'r'  => (string)($app['r'] ?? ''),
    'a'  => (string)($app['a'] ?? ''),
    'ic' => (string)($app['ic'] ?? ''),
    'c'  => $appCats,
    'd'  => (int)($app['d'] ?? 0),
    'ov' => (string)($app['ov'] ?? ''),
  ];
  // Carried through, not ranked on — CA still shows deprecated apps (with a
  // notice), so a deprecated match should appear exactly where its text
  // match earns it, just flagged for the row to render a warning.
  if (isset($app['dep'])) $row['dep'] = $app['dep'];
  // Docker Hub stars, carried the same way and for the same reason: absent
  // on about half the catalogue, and absent means "not counted" rather than
  // "none", so the key is missing rather than zero. Not ranked on either —
  // a popular image is not the same thing as the app somebody searched for.
  if (isset($app['st'])) $row['st'] = (int)$app['st'];
  return $row;
}

/**
 * Search the catalogue.
 *
 * Ranked best first: an exact name match, a name prefix, the query at the
 * start of a later word of the name, a name substring anywhere, then the
 * query at the start of a word of the image or the author — ties broken by
 * download count, so of two equally good text matches the one more people
 * actually use comes first. An empty query lists by download count alone,
 * which is also what makes "browse this category" and "what's popular" the
 * same code path.
 *
 * @return array<int, array{i:int,n:string,r:string,a:string,ic:string,c:string[],d:int,ov:string,dep?:int}>
 */
function staxx_ca_search(string $q, string $cat, int $limit = 60): array {
  $apps = staxx_ca_index_data()['apps'];

  $q   = trim($q);
  $cat = trim($cat);
  $ql  = strtolower($q);

  // An image name is a compound string — binhex/arch-plexpass,
  // mintplexlabs/anythingllm — so a bare "is it in there" test cannot tell a
  // word from a fragment of one, and searching 'plex' returned AnythingLLM.
  // A match only counts at a word start: position 0, or after anything that
  // is not a letter or digit. Built once rather than per app, because the
  // loop below runs the length of the whole catalogue on every keystroke.
  // $ql is user input, so it is quoted; both haystacks are already lowercase.
  $pat = $ql === '' ? '' : '/(?<![a-z0-9])'.preg_quote($ql, '/').'/';

  $matches = [];
  foreach ($apps as $i => $app) {
    // 'c' is an array of every category the app claims, so filtering means
    // "is the chosen category anywhere in it" — not equality. Defensive
    // against a cache built before categories became arrays: a leftover
    // plain string from the previous version is read as a one-element one
    // rather than crashing in_array() on it.
    $appCats = $app['c'] ?? [];
    if (!is_array($appCats)) $appCats = ($appCats === '' ? [] : [$appCats]);
    if ($cat !== '' && !in_array($cat, $appCats, true)) continue;

    if ($ql === '') {
      $matches[] = ['i' => $i, 'rank' => 0, 'd' => (int)($app['d'] ?? 0),
                    'k' => strtolower((string)($app['n'] ?? ''))];
      continue;
    }

    $name = strtolower((string)($app['n'] ?? ''));
    $repo = strtolower((string)($app['r'] ?? ''));
    $auth = strtolower((string)($app['a'] ?? ''));

    // A name substring is NOT tightened with the rest, and keeps a rank of
    // its own below the word-start one. Requiring a word start here would
    // lose 'arr' → Radarr and Sonarr, which is a search people really run;
    // splitting the two is what puts binhex-plex and Music-Manager-for-Plex
    // above SimpleX-XFTP, which only matches at all because 'simplexchat'
    // happens to contain the letters.
    if ($name === $ql)                    $rank = 0;
    elseif (strpos($name, $ql) === 0)     $rank = 1;
    elseif (preg_match($pat, $name))      $rank = 2;
    elseif (strpos($name, $ql) !== false) $rank = 3;
    elseif (preg_match($pat, $repo))      $rank = 4;
    elseif (preg_match($pat, $auth))      $rank = 5;
    else continue;

    $matches[] = ['i' => $i, 'rank' => $rank, 'd' => (int)($app['d'] ?? 0), 'k' => $name];
  }

  // With something typed, download count breaks ties between equally good text
  // matches, and it is the right tie-break: of the several templates called
  // "jellyfin", the one nearly everybody runs should be first.
  //
  // With NOTHING typed it is the wrong sort entirely, and this is worth
  // spelling out because the obvious code is the bug. `d` is the Docker Hub
  // pull count of the app's IMAGE, not of the app — so `nginx:alpine` carries
  // the hundreds of millions of pulls of the official nginx image, and every
  // small template built on a popular base image outranks every purpose-built
  // one. Sorted that way, opening the dialog showed a wall of nginx and
  // postgres wrappers and nothing anybody came looking for. A-Z is plain,
  // predictable, and pairs with the category filter to make browsing work.
  usort($matches, $ql === ''
    ? fn($a, $b) => strcmp($a['k'], $b['k'])
    : fn($a, $b) => ($a['rank'] <=> $b['rank']) ?: ($b['d'] <=> $a['d']));

  $out = [];
  foreach (array_slice($matches, 0, $limit) as $m) {
    $out[] = staxx_ca_row($apps[$m['i']], $m['i']);
  }
  return $out;
}

/**
 * The Apps dialog's front page: Spotlight, Recently Added and Top Trending,
 * exactly as scripts/ca-index.php ordered and capped them at build time — this
 * only turns their ordinals back into rows.
 *
 * A v3 cache (built before this existed) has no 'home' key at all, which is
 * exactly what every server shows in the seconds after an upgrade while the
 * stale-but-usable cache is still being replaced — so that reads as three
 * empty rows rather than a notice.
 *
 * @return array{spot:array,new:array,trend:array}
 */
function staxx_ca_home(): array {
  $data = staxx_ca_index_data();
  $apps = $data['apps'];
  $home = $data['home'] ?? null;
  if (!is_array($home)) $home = ['spot' => [], 'new' => [], 'trend' => []];

  $spot = [];
  foreach ($home['spot'] ?? [] as $s) {
    $i = (int)($s['i'] ?? -1);
    if (!isset($apps[$i])) continue; // defensive: an ordinal the index no longer has
    $row = staxx_ca_row($apps[$i], $i);
    if (!empty($s['who'])) $row['who'] = (string)$s['who'];
    if (!empty($s['why'])) $row['why'] = (string)$s['why'];
    $spot[] = $row;
  }

  $new = [];
  foreach ($home['new'] ?? [] as $i) {
    $i = (int)$i;
    if (isset($apps[$i])) $new[] = staxx_ca_row($apps[$i], $i);
  }

  $trend = [];
  foreach ($home['trend'] ?? [] as $i) {
    $i = (int)$i;
    if (isset($apps[$i])) $trend[] = staxx_ca_row($apps[$i], $i);
  }

  return ['spot' => $spot, 'new' => $new, 'trend' => $trend];
}

/**
 * One app's full JSON entry, by the ordinal staxx_ca_search() gave out.
 *
 * Reads exactly that app's line out of apps.jsonl by byte offset, rather than
 * scanning the file or decoding the whole thing — the file holds 4000+ lines
 * and this is asked for every time someone opens one result.
 */
function staxx_ca_app(int $i): ?array {
  $entry = staxx_ca_index_data()['apps'][$i] ?? null;
  if (!is_array($entry)) return null;

  $offset = (int)($entry['o'] ?? -1);
  $length = (int)($entry['len'] ?? 0);
  if ($offset < 0 || $length <= 0) return null;

  $fh = @fopen(STAXX_CA_APPS, 'rb');
  if ($fh === false) return null;

  $line = '';
  if (@fseek($fh, $offset) === 0) $line = (string)@fread($fh, $length);
  fclose($fh);

  $app = json_decode($line, true);
  return is_array($app) ? $app : null;
}

/** The categories that actually occur in the catalogue, sorted — never the
 *  feed's own raw list, so the filter dropdown never offers a choice that
 *  matches nothing. */
function staxx_ca_categories(): array {
  $categories = staxx_ca_index_data()['categories'];
  return is_array($categories) ? $categories : [];
}
?>
