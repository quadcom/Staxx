<?PHP
/* Stack Manager — builds the Community Applications catalogue cache.
 * Copyright 2026, Stack Manager contributors.
 *
 * Run detached by stackman_ca_refresh_start() (see include/CA.php), never by a
 * page render: it downloads a 24 MB feed and can take the best part of a
 * minute. Can also be run by hand on the server —
 *   php /usr/local/emhttp/plugins/stack.manager/scripts/ca-index.php
 * — which is the easiest way to see what a build actually does; every step
 * prints one line.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */

if (php_sapi_name() !== 'cli') {
  fwrite(STDERR, "This script only runs from the command line.\n");
  exit(1);
}

require_once '/usr/local/emhttp/plugins/stack.manager/include/CA.php';

/** Write a file via write-then-rename, so a reader never sees a half write. */
function stackman_ca_index_write_json(string $path, array $data): void {
  $tmp = $path.'.'.getmypid().'.tmp';
  @file_put_contents($tmp, (string)json_encode($data));
  @rename($tmp, $path);
}

function stackman_ca_index_status(string $state, string $message, int $built = 0, int $count = 0): void {
  $data = ['state' => $state, 'message' => $message, 'built' => $built];
  if ($count) $data['count'] = $count;
  stackman_ca_index_write_json(STACKMAN_CA_STATUS, $data);
}

/** Recursive delete, for clearing out a stale ca.new or ca.old directory. */
function stackman_ca_index_rmtree(string $dir): void {
  if (!is_dir($dir) || is_link($dir)) { @unlink($dir); return; }
  foreach (scandir($dir) ?: [] as $item) {
    if ($item === '.' || $item === '..') continue;
    $path = $dir.'/'.$item;
    is_dir($path) && !is_link($path) ? stackman_ca_index_rmtree($path) : @unlink($path);
  }
  @rmdir($dir);
}

/**
 * Download a URL straight to a file handle — CURLOPT_FILE rather than
 * RETURNTRANSFER, because holding 24 MB as a second in-memory copy on top of
 * what curl already buffers is 24 MB this script has no need to spend.
 */
function stackman_ca_index_download(string $url, string $dest): bool {
  $fh = @fopen($dest, 'wb');
  if ($fh === false) return false;

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_FILE           => $fh,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 3,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 180,
    CURLOPT_USERAGENT      => 'Stack Manager (Unraid plugin)',
    // The feed is 23.9 MB today. 64 MB is headroom for it to grow, not an
    // invitation to trust a reply that has gone very wrong.
    CURLOPT_MAXFILESIZE    => 64 * 1024 * 1024,
  ]);
  $ok   = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);
  fclose($fh);

  if (!$ok || $code < 200 || $code > 299) { @unlink($dest); return false; }
  return true;
}

/**
 * When Community Applications last changed its catalogue, or 0 if that could
 * not be established.
 *
 * This is a 37-byte file — {"last_updated_timestamp":1786838684} — published
 * beside the feed itself, and it is the whole reason a rebuild is usually free.
 * The catalogue changes a few times a day; the TTL expires daily. Without this
 * check every expiry would re-download 23.9 MB to discover that nothing had
 * changed. With it, that costs 37 bytes.
 */
function stackman_ca_index_feed_stamp(): int {
  foreach ([
    'https://assets.ca.unraid.net/feed/applicationFeed-lastUpdated.json',
    'https://ca.unraid.net/assets/feed/applicationFeed-lastUpdated.json',
  ] as $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_MAXREDIRS      => 3,
      CURLOPT_CONNECTTIMEOUT => 5,
      CURLOPT_TIMEOUT        => 15,
      CURLOPT_USERAGENT      => 'Stack Manager (Unraid plugin)',
      CURLOPT_MAXFILESIZE    => 4096,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false || $code < 200 || $code > 299) continue;
    $data = json_decode((string)$body, true);
    $ts   = (int)($data['last_updated_timestamp'] ?? 0);
    if ($ts > 0) return $ts;
  }
  return 0;
}

/** The feed's own 16 top-level sections, without their trailing colon. */
const STACKMAN_CA_INDEX_SECTIONS = [
  'AI', 'Backup', 'Cloud', 'Crypto', 'Downloaders', 'Drivers', 'GameServers',
  'HomeAutomation', 'MediaApp', 'MediaServer', 'Network', 'Plugins',
  'Productivity', 'Security', 'Tools', 'Other',
];

/**
 * One CategoryList entry can itself hold several categories, so this returns
 * an array rather than a single string.
 *
 * `;` separates categories packed into one string ("Gaming-;Network-Web" is
 * two). Within each part, a leading or trailing `-` means "no subcategory"
 * and is stripped, then what remains is split on `-` and walked, peeling
 * known sections (STACKMAN_CA_INDEX_SECTIONS) off the front: two known
 * sections in a row are each standalone ("Crypto-AI-…" is "Crypto" then
 * "AI"), a known section followed by unknown tokens takes everything up to
 * the next known section as its subcategory ("MediaApp-Video" =>
 * "MediaApp:Video", "AI-Tools-Utilities" => "AI", "Tools:Utilities"). A part
 * that does not start with a known section is a plugin's own made-up scheme
 * ("MediaApplication-Video", "System-Monitoring") and passes through
 * unchanged rather than being mangled into something that looks structured
 * but is not.
 */
function stackman_ca_index_category(string $raw): array {
  $raw = trim($raw);
  if ($raw === '') return [];

  $out = [];
  foreach (explode(';', $raw) as $part) {
    $part = trim(trim($part), '-');
    if ($part === '') continue;

    $tokens = explode('-', $part);
    $n = count($tokens);
    $i = 0;
    while ($i < $n) {
      $token = $tokens[$i];

      if (!in_array($token, STACKMAN_CA_INDEX_SECTIONS, true)) {
        // Not a known section: pass the remainder through untouched and stop
        // — the feed made this scheme up, and reshaping it would be a guess.
        $out[] = implode('-', array_slice($tokens, $i));
        break;
      }

      if ($i + 1 >= $n) {
        // Last token: a bare section with no subcategory.
        $out[] = $token;
        break;
      }

      $next = $tokens[$i + 1];
      if (in_array($next, STACKMAN_CA_INDEX_SECTIONS, true)) {
        // Two known sections run together with no separator — each is its
        // own category, not one nested inside the other.
        $out[] = $token;
        $i++;
        continue;
      }

      // Collect everything up to (not including) the next known section as
      // this one's subcategory.
      $j = $i + 1;
      while ($j < $n && !in_array($tokens[$j], STACKMAN_CA_INDEX_SECTIONS, true)) $j++;
      $out[] = $token.':'.implode('-', array_slice($tokens, $i + 1, $j - $i - 1));
      $i = $j;
    }
  }
  return $out;
}

/**
 * A feed flag counted as "set", coping with the two shapes CA sends it in:
 * the JSON boolean true and the string "true". A bare (bool) cast would also
 * read the string "false" as set, since PHP treats any non-empty string as
 * truthy — this is the one place that distinction is checked once, rather
 * than repeated (and risked) at every call site.
 */
function stackman_ca_truthy($v): bool {
  if ($v === true || $v === 1 || $v === '1') return true;
  if (is_string($v)) return strtolower($v) === 'true';
  return false;
}

/**
 * Turn one decoded app object into what apps.jsonl and index.json each need,
 * or null for the 476 entries that should not be offered at all: 83 with no
 * Repository (language packs), 303 Unraid plugins, 83 blacklisted by CA
 * itself (broken, malicious or withdrawn) and 10 flagged not to display.
 * Neither the language packs nor the plugins have an `image:` to run; the
 * blacklisted and hidden ones do, but CA has disowned or hidden them, so
 * offering them for import here would be offering what CA itself will not.
 * 4,116 entries in, 3,637 out.
 *
 * @return array{line:string,n:string,r:string,a:string,ic:string,c:string[],d:int,ov:string,dep?:int}|null
 */
function stackman_ca_index_entry(array $app): ?array {
  $repo = trim((string)($app['Repository'] ?? ''));
  if ($repo === '') return null;

  // Unraid PLUGINS, not containers — 303 of them, and they have no image to
  // run. They are not distinguished by a missing Repository, which was the
  // obvious guess and catches none of them: a plugin's Repository holds the
  // URL of its .plg file, so it is present and looks perfectly normal until
  // it lands in an `image:` line as `https://….plg`. All three signals agree
  // on exactly the same 303 entries, so any one would do; the URL test is
  // the one that would still hold if the feed stopped setting the other two.
  if (preg_match('#^https?://#i', $repo)) return null;
  if (!empty($app['PluginURL']) || !empty($app['Plugin'])) return null;

  // CA's own blacklist (broken, malicious or withdrawn — 83 entries) and its
  // "don't display this" flags (10 entries). Offering either for import would
  // be offering something CA's own catalogue has disowned or hidden.
  if (stackman_ca_truthy($app['Blacklist'] ?? null) || stackman_ca_truthy($app['CABlacklist'] ?? null)) return null;
  if (stackman_ca_truthy($app['hideFromCA'] ?? null) || stackman_ca_truthy($app['HideFromCA'] ?? null) || stackman_ca_truthy($app['hideFromWeb'] ?? null)) return null;

  // Fat fields nobody searches or installs by: screenshots, moderator notes,
  // and the download-trend history CA keeps for its own graphs. Stripping
  // these is most of why apps.jsonl is a fraction of the feed's size.
  foreach (['trends', 'downloadtrend', 'trendsDate', 'pluginStats', 'Screenshot', 'Screenshots', 'CAComment', 'ModeratorComment'] as $k) {
    unset($app[$k]);
  }
  foreach (array_keys($app) as $k) {
    if (stripos($k, 'Moderator') === 0) unset($app[$k]);
  }

  // Every CategoryList entry must be indexed, not just the first — 2,528 apps
  // in the feed list more than one, and an app whose first category happens
  // to be "MediaApp:Video" is still findable under "MediaServer:Video" too.
  $catList = $app['CategoryList'] ?? null;
  if (!is_array($catList) || !$catList) {
    $catList = [(string)($app['Category'] ?? '')];
  }
  $categoriesOut = [];
  foreach ($catList as $catRaw) {
    foreach (stackman_ca_index_category((string)$catRaw) as $cat) {
      if (!in_array($cat, $categoriesOut, true)) $categoriesOut[] = $cat;
    }
  }

  // mb_substr, not substr. Plenty of overviews are not ASCII, and cutting one
  // mid-character leaves a broken UTF-8 sequence that json_encode refuses —
  // which would not fail this one entry, it would return false for the WHOLE
  // index.json and leave the cache empty for a reason nothing would explain.
  $overview = trim((string)preg_replace('/\s+/', ' ', (string)($app['Overview'] ?? '')));
  if (mb_strlen($overview, 'UTF-8') > 160) $overview = mb_substr($overview, 0, 160, 'UTF-8').'…';

  $line = json_encode($app);
  if ($line === false) return null;

  $entry = [
    'line' => $line,
    'n'    => (string)($app['Name'] ?? ''),
    'r'    => $repo,
    'a'    => (string)($app['Repo'] ?? ''),
    'ic'   => (string)($app['Icon'] ?? ''),
    'c'    => $categoriesOut,
    'd'    => (int)($app['downloads'] ?? 0),
    'ov'   => $overview,
  ];

  // CA still lists deprecated apps (with a notice) rather than hiding them, so
  // they stay in the index too — but only 154 of 3,730 carry the flag, and
  // index.json is read on every search, so 'dep' is omitted rather than
  // written as 0 onto every entry that does not need it.
  if (stackman_ca_truthy($app['Deprecated'] ?? null)) $entry['dep'] = 1;

  return $entry;
}

/**
 * The whole point of this script: split the feed into apps.jsonl and
 * index.json without ever holding more than one app's JSON in memory at a
 * time. json_decode of all 4116 entries at once was tried and cost several
 * hundred megabytes — a brace-depth scanner over the raw bytes costs one app.
 *
 * Finds "applist", then its opening [, then walks the array tracking brace
 * depth by hand — respecting string state and backslash escapes, since a
 * quoted brace inside a description must not be counted. Each depth 1 => 0
 * span is one app; only that span is ever handed to json_decode.
 *
 * @return array{count:int, categories:string[]}
 * @throws Exception on anything that looks like a malformed feed
 */
function stackman_ca_index_split(string $feedPath, string $outDir, int $feedStamp = 0): array {
  $in = fopen($feedPath, 'rb');
  if ($in === false) throw new Exception('Could not open the downloaded feed.');

  $out = fopen($outDir.'/apps.jsonl', 'wb');
  if ($out === false) { fclose($in); throw new Exception('Could not create apps.jsonl.'); }

  $chunkSize = 1024 * 1024;

  // ---- find "applist", then the [ that opens its array ----
  $needle = '"applist"';
  $carry  = '';
  $found  = false;
  $after  = '';

  while (!feof($in)) {
    $carry .= fread($in, $chunkSize);
    $pos = strpos($carry, $needle);
    if ($pos !== false) { $found = true; $after = substr($carry, $pos + strlen($needle)); break; }
    // Keep only enough of a tail for the needle to still be found if it
    // straddles this chunk boundary and the next one.
    $carry = substr($carry, -(strlen($needle) - 1));
  }
  if (!$found) {
    fclose($in); fclose($out);
    throw new Exception('The feed has no "applist" key — it does not look like the real feed.');
  }

  $buf = $after;
  while (($bracket = strpos($buf, '[')) === false) {
    if (feof($in)) { fclose($in); fclose($out); throw new Exception('Could not find the start of the applist array.'); }
    $buf .= fread($in, $chunkSize);
  }
  $buf = substr($buf, $bracket + 1);

  // ---- walk the array one app at a time ----
  $depth      = 0;
  $inString   = false;
  $escape     = false;
  $elem       = '';
  $sinceClose = 0;
  $offset     = 0;
  $count      = 0;
  $categories = [];
  $indexEntries = [];
  $closed     = false;

  for (;;) {
    if ($buf === '') {
      if (feof($in)) break;
      $buf = fread($in, $chunkSize);
      if ($buf === false || $buf === '') continue;
    }

    $len = strlen($buf);
    for ($p = 0; $p < $len; $p++) {
      $c = $buf[$p];

      if ($depth === 0) {
        if ($c === ']') { $closed = true; break 2; }
        if ($c === '{') { $depth = 1; $elem = '{'; $sinceClose = 1; }
        continue;
      }

      $elem .= $c;
      // A single entry running past a few megabytes without its closing
      // brace means the feed is not what this scanner expects — bail rather
      // than spin forever eating the rest of the file into one string.
      if (++$sinceClose > 5 * 1024 * 1024) {
        throw new Exception('An entry in the feed never closed — the feed looks malformed.');
      }

      if ($inString) {
        if ($escape)            $escape = false;
        elseif ($c === '\\')    $escape = true;
        elseif ($c === '"')     $inString = false;
        continue;
      }
      if ($c === '"') { $inString = true; continue; }
      if ($c === '{') { $depth++; continue; }
      if ($c === '}') {
        $depth--;
        if ($depth === 0) {
          $decoded = json_decode($elem, true);
          $built   = is_array($decoded) ? stackman_ca_index_entry($decoded) : null;
          if ($built !== null) {
            $lineLen = strlen($built['line']);
            fwrite($out, $built['line']."\n");
            $indexEntries[] = [
              'n' => $built['n'], 'r' => $built['r'], 'a' => $built['a'], 'ic' => $built['ic'],
              'c' => $built['c'], 'd' => $built['d'], 'ov' => $built['ov'],
              'o' => $offset, 'len' => $lineLen,
            ] + (isset($built['dep']) ? ['dep' => $built['dep']] : []);
            foreach ($built['c'] as $cat) $categories[$cat] = true;
            $offset += $lineLen + 1;
            $count++;
          }
          $elem = '';
        }
      }
    }
    $buf = '';
  }

  fclose($in);
  fclose($out);

  if (!$closed) throw new Exception('The applist array never closed — the feed looks malformed.');

  $categoryList = array_keys($categories);
  sort($categoryList);

  stackman_ca_index_write_json($outDir.'/index.json', [
    'built'      => time(),
    // What Community Applications said its catalogue's own timestamp was when
    // this was built. The next rebuild compares against it and skips the whole
    // download when it has not moved — see stackman_ca_index_feed_stamp().
    'feed_ts'    => $feedStamp,
    'count'      => $count,
    'categories' => $categoryList,
    'apps'       => $indexEntries,
  ]);

  return ['count' => $count, 'categories' => $categoryList];
}

/* ------------------------------------------------------------------ main -- */

if (!is_dir(STACKMAN_CA_DIR))  @mkdir(STACKMAN_CA_DIR, 0755, true);
if (!is_dir(STACKMAN_CA_LOCK)) @mkdir(STACKMAN_CA_LOCK, 0755, true);

echo "Stack Manager: rebuilding the Community Applications catalogue.\n";
stackman_ca_index_status('building', 'Downloading the applications catalogue.');

try {
  /* Ask what Community Applications' catalogue is stamped at before fetching
   * any of it. The catalogue is republished a few times a day and the cache
   * expires daily, so most rebuilds find nothing has changed — and answering
   * that with a 37-byte request rather than a 23.9 MB one is the difference
   * between a daily download per server and almost none at all.
   *
   * Only ever an optimisation: a stamp that cannot be fetched, or an index
   * with no stamp recorded (one built before this existed), falls through to
   * the full download rather than guessing the cache is still good. */
  $feedStamp = stackman_ca_index_feed_stamp();
  $haveStamp = 0;
  if (is_file(STACKMAN_CA_INDEX)) {
    $existing  = json_decode((string)@file_get_contents(STACKMAN_CA_INDEX), true);
    $haveStamp = is_array($existing) ? (int)($existing['feed_ts'] ?? 0) : 0;
  }

  if ($feedStamp > 0 && $feedStamp === $haveStamp) {
    echo "The catalogue has not changed since this cache was built. Nothing to download.\n";
    // touch() resets the TTL, so the next search reads the cache as fresh
    // rather than asking for this same check all over again.
    @touch(STACKMAN_CA_INDEX);
    $existingCount = is_array($existing ?? null) ? (int)($existing['count'] ?? 0) : 0;
    stackman_ca_index_status('ready', 'The applications catalogue is up to date.', time(), $existingCount);
    echo "Done.\n";
    // return, never exit(). PHP runs a finally block on a return but NOT on an
    // exit(), and the finally below is what releases the lock — leaving it held
    // would wedge every rebuild for the next half hour.
    return;
  }

  $feedTmp = STACKMAN_CA_DIR.'/feed.json.download';
  $sources = [
    'https://assets.ca.unraid.net/feed/applicationFeed.json',
    'https://raw.githubusercontent.com/Squidly271/AppFeed/master/applicationFeed.json',
  ];

  $downloaded = false;
  foreach ($sources as $url) {
    echo "Fetching $url ...\n";
    if (stackman_ca_index_download($url, $feedTmp)) { $downloaded = true; break; }
    echo "That source failed; trying the next one.\n";
  }
  if (!$downloaded) {
    throw new Exception(
      'Could not download the applications catalogue from either source. '
      .'Check the server has internet access and try again.'
    );
  }

  echo "Downloaded. Splitting the feed...\n";
  $newDir = STACKMAN_CA_DIR.'.new';
  stackman_ca_index_rmtree($newDir);
  mkdir($newDir, 0755, true);

  $result = stackman_ca_index_split($feedTmp, $newDir, $feedStamp);
  @unlink($feedTmp);
  echo 'Wrote '.$result['count']." apps across ".count($result['categories'])." categories.\n";

  // Swap the new cache into place. rename() over an existing directory fails
  // on Linux, so the old one is moved aside first and deleted after — a
  // reader only ever sees a fully-built cache or the previous one, never a
  // half-built one.
  $oldDir = STACKMAN_CA_DIR.'.old';
  stackman_ca_index_rmtree($oldDir);
  if (is_dir(STACKMAN_CA_DIR)) rename(STACKMAN_CA_DIR, $oldDir);
  rename($newDir, STACKMAN_CA_DIR);
  stackman_ca_index_rmtree($oldDir);

  stackman_ca_index_status('ready', 'The applications catalogue is up to date.', time(), $result['count']);
  echo "Done.\n";
} catch (Throwable $e) {
  echo 'Failed: '.$e->getMessage()."\n";
  stackman_ca_index_status(
    'failed',
    $e->getMessage().' Try searching again in a minute, or run this script by hand to see the full output.'
  );
} finally {
  // The 24 MB download and a part-built cache are both worth clearing on the
  // way out: /tmp is a RAM disk on Unraid, so anything left behind by a failed
  // build is memory the server does not get back until the next reboot.
  @unlink(STACKMAN_CA_DIR.'/feed.json.download');
  stackman_ca_index_rmtree(STACKMAN_CA_DIR.'.new');

  // Released here unconditionally: the swap above already carries the lock
  // directory away inside the old ca/ and deletes it, so this is a no-op on
  // success and the real release on any failure caught above.
  @rmdir(STACKMAN_CA_LOCK);
}
?>
