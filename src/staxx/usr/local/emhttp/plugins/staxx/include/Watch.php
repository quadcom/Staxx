<?PHP
/* StaXX — watching what an image's own publisher actually ships as an example.
 * Copyright 2026, StaXX contributors.
 *
 * WHAT THIS FILE IS FOR
 *
 * PLAN_62, Stage 1 only. For an image whose tag is rolling — pinned versions
 * are never even looked at, see staxx_watch_rolling_tag() — this finds the
 * one compose file its publisher's own GitHub repository holds up as the
 * example, or the single fenced YAML block in its README when no file wins
 * outright. Nothing here compares that file against anything of ours; that
 * is Stage 2, through the same YAML parser the browser uses, because PHP has
 * no YAML extension worth trusting for it. This stage only answers "does an
 * example exist, and where".
 *
 * Every fetch is conditional wherever GitHub's API allows one: quoting the
 * ETag seen last time costs GitHub nothing against the hourly limit when it
 * answers 304, which is the whole reason discovery is affordable to keep
 * asking about every pass. GitHub's own project pages are the only source
 * ever read — never Docker Hub's, which would spend the separate allowance
 * the update check itself depends on.
 *
 * PLAN_62 Stage 2 adds the comparison itself. It runs through node, calling
 * the exact YAML parser the browser uses (scripts/watch-compare.js, which
 * requires compose-model.js directly) — never a second, cruder PHP reader,
 * and never `docker compose config`, which would resolve env_file/extends
 * against a third party's paths. See the plan's "load-bearing decision"
 * section. There is no write path anywhere in this file: applying a finding
 * is never done, by design.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/staxx/include/Defines.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Links.php';

if (defined('STAXX_WATCH_DIR')) return;

// Fetched bodies only — reducible downloads, not worth surviving a reboot,
// the same reasoning STAXX_CA_DIR is built on. Only the stamps, the resolved
// path and the refusal reason are worth a flash write, and those live on the
// existing per-image entry in updates.json instead.
const STAXX_WATCH_DIR = '/tmp/staxx/watch';

/**
 * Is this image on a tag that could plausibly change under it? A pinned
 * version is a promise this exact thing was chosen deliberately, so it is
 * never even fetched — this is the gate that keeps every function below out
 * of the network for most of an ordinary server's images.
 *
 * Delegates the actual "does this word look like a version" question to
 * staxx_links_rolling_tag(), so a plain image with no tag written at all
 * defaults to Docker's own "latest" and is rolling by the same rule anyone
 * else reading this file would expect. A digest pin (@sha256:...) is the
 * strongest possible pin there is and is refused outright, before the tag is
 * even inspected.
 */
function staxx_watch_rolling_tag(string $image): bool {
  if (strpos($image, '@sha256:') !== false) return false;
  return staxx_links_rolling_tag(staxx_image_tag_part($image));
}

/**
 * Where this image's publisher is, for this feature's purposes only — never
 * a guess dressed up as one. A declared org.opencontainers.image.source
 * label always wins, because the publisher wrote it themselves; failing
 * that, and only for an image actually hosted at ghcr.io, the matching
 * GitHub address, which staxx_links_derive_ghcr() already treats as a good
 * guess rather than a fact. Nothing else is tried — a plain image like
 * alpine correctly comes back empty.
 */
function staxx_watch_home(string $image): string {
  $images = staxx_update_state()['images'] ?? [];
  $label  = staxx_links_url((string)($images[$image]['source'] ?? ''));
  if ($label !== '') return $label;
  return staxx_links_derive_ghcr($image);
}

/**
 * owner/repo out of a project home address, or [] when it is not a GitHub
 * repository at all — GitLab and anything else are explicitly out of scope
 * for this stage (PLAN_62's "Open, and deliberately left open" item 2).
 */
function staxx_watch_github_repo(string $home): array {
  if (!preg_match('#^https://github\.com/([^/]+)/([^/]+)#i', trim($home), $m)) return [];
  $owner = $m[1];
  $repo  = preg_replace('/\.git$/i', '', $m[2]);
  if ($owner === '' || $repo === '') return [];
  return [$owner, $repo];
}

/**
 * One conditional GET, headers and body both handed back. -i rather than a
 * separate -D file: a single stream is simpler to parse and there is only
 * ever one response here (no -L, so no intermediate redirect header block
 * to split out) — GitHub's API and raw content host do not redirect these
 * URLs in normal use, and a host that suddenly does gets treated as failed
 * rather than silently followed somewhere unexpected.
 *
 * @return array{code:int, etag:string, body:string}
 */
function staxx_watch_curl(string $url, array $headers, int $maxBytes, int $maxTime = 8): array {
  $cmd = 'curl -sS -i --proto \'=https,http\' --connect-timeout 5 --max-time '.$maxTime
       . ' --max-filesize '.$maxBytes.' -A '.escapeshellarg('StaXX (Unraid plugin)');
  foreach ($headers as $header) $cmd .= ' -H '.escapeshellarg($header);
  $cmd .= ' '.escapeshellarg($url);

  // A little above curl's own --max-time, same margin staxx_hub_json() uses,
  // so curl reports the failure itself rather than being killed mid-flight.
  $out = staxx_sh($cmd, $maxTime + 4);

  [$head, $body] = array_pad(preg_split('/\r?\n\r?\n/', $out, 2), 2, '');
  $code = 0;
  if (preg_match('#^HTTP/\S+\s+(\d+)#', $head, $m)) $code = (int)$m[1];
  $etag = '';
  if (preg_match('/^etag:\s*(.+?)\s*$/mi', $head, $m)) $etag = trim($m[1]);

  return ['code' => $code, 'etag' => $etag, 'body' => $body];
}

/**
 * The conventionally-named compose files inside a decoded git-trees reply —
 * split out of staxx_watch_discover() so this, the part with real judgement
 * in it, is testable against a captured real reply with no network at all.
 *
 * @param  array $tree  the API reply's own 'tree' array, entry objects with
 *                       'type' and 'path'
 * @return string[] every matching blob path, in the order the API gave them
 */
function staxx_watch_discover_candidates(array $tree): array {
  $hits = [];
  foreach ($tree as $entry) {
    if (($entry['type'] ?? '') !== 'blob') continue;
    $path = (string)($entry['path'] ?? '');
    if (preg_match('#(^|/)(docker-)?compose\.ya?ml$#i', $path)) $hits[] = $path;
  }
  return $hits;
}

/**
 * One file listing per repository — the git trees API, recursive, which is a
 * single request for the whole tree rather than one request per candidate
 * directory. Conditional on the tree's own ETag, so a repository that has
 * not changed since the last pass costs nothing against GitHub's hourly
 * ceiling: a 304 is free, proved against the real API (see the report for
 * this plan's verification), which is what makes asking every pass
 * affordable.
 *
 * Exactly one conventionally-named compose file (docker-compose.yml/.yaml,
 * compose.yml/.yaml, anywhere in the tree — including under examples/ or
 * docs/) wins. Several is a refusal carrying the count, so the caller's copy
 * can say how many rather than silently picking one and presenting a CI
 * fixture as the author's word. None is a refusal too, distinguished from
 * "several" only by the count, so the caller can fall through to the README.
 *
 * @return array{ok:bool, unchanged:bool, etag:string, path:string,
 *               candidates:int, reason:string}
 */
function staxx_watch_discover(string $home, string $priorEtag = ''): array {
  $empty = ['ok' => false, 'unchanged' => false, 'etag' => $priorEtag, 'path' => '', 'candidates' => 0, 'reason' => ''];

  $parts = staxx_watch_github_repo($home);
  if ($parts === []) return array_merge($empty, ['reason' => 'not a GitHub repository']);
  [$owner, $repo] = $parts;

  $headers = ['Accept: application/vnd.github+json'];
  if ($priorEtag !== '') $headers[] = 'If-None-Match: '.$priorEtag;

  $url  = 'https://api.github.com/repos/'.rawurlencode($owner).'/'.rawurlencode($repo).'/git/trees/HEAD?recursive=1';
  $resp = staxx_watch_curl($url, $headers, 8 * 1024 * 1024);

  if ($resp['code'] === 304) return array_merge($empty, ['ok' => true, 'unchanged' => true]);
  if ($resp['code'] === 404) return array_merge($empty, ['reason' => 'the repository could not be found']);
  if ($resp['code'] === 403 || $resp['code'] === 429) return array_merge($empty, ['reason' => 'rate limited']);
  if ($resp['code'] !== 200) return array_merge($empty, ['reason' => 'could not list the repository']);

  // A real captured body is what this is tested against, decoded properly —
  // never a string search for a quoted field name, which is the mistake
  // PLAN_62 itself records making on this exact reply shape.
  $data = json_decode($resp['body'], true);
  if (!is_array($data) || !isset($data['tree']) || !is_array($data['tree'])) {
    return array_merge($empty, ['reason' => 'could not read the repository listing']);
  }

  $hits = staxx_watch_discover_candidates($data['tree']);

  if (count($hits) === 0) {
    return array_merge($empty, ['ok' => true, 'etag' => $resp['etag'], 'reason' => 'this author publishes no compose file']);
  }
  if (count($hits) > 1) {
    return array_merge($empty, ['ok' => true, 'etag' => $resp['etag'], 'candidates' => count($hits),
                      'reason' => 'this author publishes several examples']);
  }
  return array_merge($empty, ['ok' => true, 'etag' => $resp['etag'], 'path' => $hits[0]]);
}

/**
 * The conditional fetch itself — one request, quoting last time's ETag if
 * there is one, for a single file's raw content off GitHub's raw-content
 * host. Never Docker Hub's page: see the file header.
 *
 * @return array{ok:bool, unchanged:bool, etag:string, body:string, reason:string}
 */
function staxx_watch_fetch(string $home, string $path, string $priorEtag = ''): array {
  $empty = ['ok' => false, 'unchanged' => false, 'etag' => $priorEtag, 'body' => '', 'reason' => ''];

  $parts = staxx_watch_github_repo($home);
  if ($parts === []) return array_merge($empty, ['reason' => 'not a GitHub repository']);
  [$owner, $repo] = $parts;

  $encodedPath = implode('/', array_map('rawurlencode', explode('/', $path)));
  $url = 'https://raw.githubusercontent.com/'.rawurlencode($owner).'/'.rawurlencode($repo).'/HEAD/'.$encodedPath;

  $headers = [];
  if ($priorEtag !== '') $headers[] = 'If-None-Match: '.$priorEtag;

  // A megabyte is generous for a compose file or a README; anything past
  // that is not something this feature was ever meant to read.
  $resp = staxx_watch_curl($url, $headers, 1024 * 1024);

  if ($resp['code'] === 304) return array_merge($empty, ['ok' => true, 'unchanged' => true]);
  if ($resp['code'] === 404) return array_merge($empty, ['reason' => 'not found']);
  if ($resp['code'] === 403 || $resp['code'] === 429) return array_merge($empty, ['reason' => 'rate limited']);
  if ($resp['code'] !== 200) return array_merge($empty, ['reason' => 'could not read the file']);

  return array_merge($empty, ['ok' => true, 'etag' => $resp['etag'], 'body' => $resp['body']]);
}

/**
 * Lift a single fenced YAML block holding a compose-shaped services list out
 * of a README's raw markdown. Pure — no network, so this is the one function
 * in this file testable without touching GitHub at all, and PLAN_62 calls
 * for its test to be written first for exactly that reason.
 *
 * Only a block whose top level actually declares `services:` counts as a
 * candidate — a fenced snippet that merely shows an environment variable or
 * two is not "the" example, and must not be picked. Two or more such blocks
 * — the common README that shows a compose file and then a plain `docker
 * run` alternative, or two variants side by side — is a refusal, not a
 * guess at which one is meant.
 *
 * @return array{ok:bool, body:string, count:int, reason:string}
 */
function staxx_watch_readme_block(string $readme): array {
  $empty = ['ok' => false, 'body' => '', 'count' => 0, 'reason' => ''];

  if (!preg_match_all('/```[ \t]*[a-zA-Z]*[ \t]*\r?\n(.*?)```/s', $readme, $matches)) {
    return array_merge($empty, ['reason' => 'this author publishes no example']);
  }

  $candidates = [];
  foreach ($matches[1] as $block) {
    // Anchored with no leading whitespace: a real compose file declares
    // `services:` at column zero. An indented mention inside some other
    // structure (a Kubernetes manifest, a nested example) is not this.
    if (preg_match('/^services:\s*$/m', $block)) $candidates[] = $block;
  }

  if (count($candidates) === 0) return array_merge($empty, ['reason' => 'this author publishes no example']);
  if (count($candidates) > 1) {
    return array_merge($empty, ['count' => count($candidates), 'reason' => 'this author publishes several examples']);
  }
  return array_merge($empty, ['ok' => true, 'body' => $candidates[0]]);
}

/**
 * Where a fetched body is cached, keyed on the image reference so two stacks
 * sharing one image share one file — same reasoning as every other cache
 * under /tmp/staxx: a reducible download, not worth a flash write, and the
 * state file only ever needs to remember the stamps and the resolved path.
 */
function staxx_watch_body_path(string $image): string {
  return STAXX_WATCH_DIR.'/'.sha1($image).'.txt';
}

/** Write-then-rename, same as every other cache write in this plugin. */
function staxx_watch_store_body(string $image, string $body): void {
  if (!is_dir(STAXX_WATCH_DIR) && !@mkdir(STAXX_WATCH_DIR, 0755, true)) return;
  $path = staxx_watch_body_path($image);
  $tmp  = $path.'.'.getmypid().'.tmp';
  if (@file_put_contents($tmp, $body) === false) return;
  @rename($tmp, $path);
}

/**
 * Which node binary this box has, or '' when it has none — probed once and
 * remembered in the state file, exactly as staxx_docker_inspector() probes
 * for an inspection route, and for the same reason: node arrives via an
 * optional Unraid package, not the base system, and asking per image would
 * be sixty pointless processes on a busy server.
 *
 * Unlike staxx_docker_inspector() (docker is always present, so '' never
 * means anything but "not probed yet"), '' is itself a real, cacheable
 * answer here — so the short-circuit below tests the timestamp, not whether
 * the stored string is empty.
 */
function staxx_watch_node_bin(): string {
  static $cached = null;
  if ($cached !== null) return $cached;

  $state    = staxx_update_state();
  $stored   = (string)($state['node_bin'] ?? '');
  $storedAt = (int)($state['node_bin_at'] ?? 0);
  if ($storedAt > 0 && (time() - $storedAt) < STAXX_UPDATE_ASK_TTL) return $cached = $stored;

  $found = '';
  foreach (['/usr/local/bin/node', '/usr/bin/node'] as $path) {
    if (!is_file($path) || !is_executable($path)) continue;
    $code = 1;
    staxx_sh(escapeshellarg($path).' --version', 5, $code);
    if ($code === 0) { $found = $path; break; }
  }

  staxx_update_state_save(['node_bin' => $found, 'node_bin_at' => time()]);
  return $cached = $found;
}

/**
 * Stage 2 itself: hand one stack's compose file and the image's cached
 * example off to scripts/watch-compare.js, through staxx_sh() so a stuck
 * node process cannot hang the pass. Called once per stack that uses
 * $image, never once per image — two stacks sharing an image must each be
 * compared against their own file, not shown the other's findings. Cheap
 * and local (no network), so the caller does not need to budget it the way
 * discovery is budgeted. Read-only in every direction — nothing here ever
 * writes to $localFile or anywhere else a stack lives.
 *
 * @return array{findings:array, compare_error:string}
 */
function staxx_watch_compare(string $localFile, string $image): array {
  $empty = ['findings' => [], 'compare_error' => ''];
  if ($localFile === '' || !is_file($localFile)) return $empty;

  // Never a silent zero. "No findings" and "there was nothing to compare
  // against" look identical on screen, and the second one dressed as the
  // first would read as "your file matches the author's" when in truth the
  // cached copy is gone. staxx_watch_check() above stops this arising by
  // refetching, so reaching here at all means something else went wrong.
  $remoteFile = staxx_watch_body_path($image);
  if (!is_file($remoteFile)) {
    return array_merge($empty, ['compare_error' => "the author's example is not on this server "
                                                 . 'at the moment; it will be fetched again on the next check']);
  }

  $node = staxx_watch_node_bin();
  if ($node === '') return array_merge($empty, ['compare_error' => 'the comparison cannot run on this server']);

  $script = '/usr/local/emhttp/plugins/staxx/scripts/watch-compare.js';
  $cmd = escapeshellarg($node).' '.escapeshellarg($script).' '.escapeshellarg($localFile).' '.escapeshellarg($remoteFile);
  $code = 1;
  $out = staxx_sh($cmd, 10, $code);
  $data = $code === 0 ? json_decode($out, true) : null;

  if (!is_array($data) || !($data['ok'] ?? false)) {
    $reason = is_array($data) ? (string)($data['reason'] ?? '') : '';
    return array_merge($empty, ['compare_error' => $reason !== '' ? $reason : 'could not compare the two files']);
  }
  return ['findings' => $data['findings'] ?? [], 'compare_error' => ''];
}

/**
 * The whole Stage-1 answer for one image, folded into the single entry the
 * per-image loop in staxx_update_check() stores on the existing state file —
 * see the call site there for why this is guarded by function_exists()
 * rather than required directly.
 *
 * Discovery and the fetch are properties of the image's own upstream
 * project, not of any one stack, so this takes no local file and does no
 * comparing — an image used by no stack at all still discovers and fetches
 * exactly the same. The comparison itself is per stack (PLAN_62's
 * correction: two stacks sharing an image must never be shown a finding
 * computed from the other stack's file), and runs separately, per stack,
 * through staxx_watch_compare() at the call site once a body is cached here.
 *
 * $prior is this image's previous 'watch' entry, so an unchanged discovery
 * or fetch can carry its resolved path and reason forward without asking
 * again. $spent is set to how many GitHub requests this call actually made,
 * so the caller can enforce one shared per-pass ceiling across every image
 * — GitHub's 60-an-hour unauthenticated limit is a separate counter from
 * Docker Hub's, but it is still a limit, and a forced recheck of every image
 * in one pass must stop well short of it rather than retrying into a wall.
 *
 * @return array{} | array{home?:string, discover_etag?:string, path?:string,
 *   candidates?:int, fetch_etag?:string, body_saved?:bool, reason?:string}
 *   [] means "pinned — nothing to say, nothing stored".
 */
function staxx_watch_check(string $image, array $prior, int &$spent): array {
  $spent = 0;
  if (!staxx_watch_rolling_tag($image)) return [];

  $home = staxx_watch_home($image);
  if ($home === '') return ['reason' => 'no known project home for this image'];

  $discover = staxx_watch_discover($home, (string)($prior['discover_etag'] ?? ''));
  $spent++;

  if ($discover['unchanged']) {
    $out = $prior;
    $out['home'] = $home;
  } elseif (!$discover['ok']) {
    // A refused listing must never clear what was already known — a
    // transient network failure must not read as "the author changed
    // nothing", but it must also not erase a real finding from last time.
    return $prior !== [] ? $prior : ['home' => $home, 'reason' => $discover['reason']];
  } else {
    $out = [
      'home'          => $home,
      'discover_etag' => $discover['etag'],
      'path'          => $discover['path'],
      'candidates'    => $discover['candidates'],
      'reason'        => $discover['reason'],
    ];
  }

  // The stamp we would quote lives on the flash drive; the body it refers to
  // lives in /tmp, which a reboot wipes. Quoting a stamp for a body we no
  // longer hold earns a cheap "unchanged" and leaves nothing to compare
  // against — silently, and until the author happens to edit their file,
  // which may be never. So the stamp is only offered while the body is
  // actually still here; otherwise ask afresh and pay for one real fetch.
  $haveBody  = is_file(staxx_watch_body_path($image));
  $priorEtag = $haveBody ? (string)($prior['fetch_etag'] ?? '') : '';

  $path = (string)($out['path'] ?? '');
  if ($path !== '') {
    $fetch = staxx_watch_fetch($home, $path, $priorEtag);
    $spent++;
    if ($fetch['unchanged']) {
      $out['fetch_etag'] = $priorEtag;
      $out['body_saved'] = true;   // only reachable while the body is still here
    } elseif ($fetch['ok']) {
      staxx_watch_store_body($image, $fetch['body']);
      $out['fetch_etag'] = $fetch['etag'];
      $out['body_saved'] = true;
    } else {
      $out['reason'] = $fetch['reason'] !== '' ? $fetch['reason'] : 'could not read the example file';
    }
    return $out;
  }

  // No single winning file. Only fall through to the README when discovery
  // genuinely found nothing (never after a several-candidates refusal —
  // that refusal is the honest answer and a README guess would undercut it).
  if ((int)($out['candidates'] ?? 0) === 0) {
    $readme = staxx_watch_fetch($home, 'README.md', $priorEtag);
    $spent++;
    if ($readme['unchanged']) {
      $out['fetch_etag'] = $priorEtag;
      $out['body_saved'] = true;   // same reasoning as the file branch above
    } elseif ($readme['ok']) {
      $block = staxx_watch_readme_block($readme['body']);
      if ($block['ok']) {
        staxx_watch_store_body($image, $block['body']);
        $out['path']       = 'README.md';
        $out['fetch_etag'] = $readme['etag'];
        $out['body_saved'] = true;
        $out['reason']     = '';
      } else {
        $out['fetch_etag'] = $readme['etag'];
        $out['body_saved'] = false;
        $out['reason']     = $block['reason'];
      }
    } else {
      $out['reason'] = $out['reason'] !== '' ? $out['reason'] : ($readme['reason'] ?: 'this author publishes no example');
    }
  }

  return $out;
}
?>
