<?PHP
/* StaXX — image update detection: asking the registry, remembering the answer.
 * Copyright 2026, StaXX contributors.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';

if (defined('STAXX_UPDATES_LOADED')) return;
define('STAXX_UPDATES_LOADED', true);

/**
 * Where the update-check state lives: <store>/config/updates.json, or ''
 * when no data store has been chosen — a function rather than a constant
 * because that answer can change (and must never be a bare path built from
 * an empty string, which would otherwise be a real, writable-looking
 * directory at the root of the filesystem). The env override — same trick
 * STAXX_AUTOSTART_FILE uses, so a server test can point this at /tmp
 * without ever touching the real file — is read once and always wins,
 * store or no store.
 */
function staxx_update_state_file(): string {
  static $override = null;
  if ($override === null) {
    $env = getenv('STAXX_UPDATE_STATE');
    $override = ($env !== false && $env !== '') ? $env : '';
  }
  if ($override !== '') return $override;

  $cfg = staxx_config_root();
  return $cfg === '' ? '' : $cfg.'/updates.json';
}

// The check pass's lock lives here, not in the data store — it is only ever
// meaningful for as long as the pass itself runs, and is worthless after a
// reboot.
define('STAXX_UPDATE_DIR', '/tmp/staxx/updates');

// Changelog caps, companions to STAXX_NOTES_MAX in Defines.php and there for
// the same reason: the list is stored as JSON in a stack's own record, so
// neither a project with a thousand commits between builds nor one absurd
// commit message may bloat it. Lines, then bytes per line.
define('STAXX_CHANGES_MAX', 50);
define('STAXX_CHANGES_LINE_MAX', 200);

// How long a remembered answer is trusted before it is asked again — six
// hours, so three stacks sharing an image cost the registry one question a
// day and not sixty.
define('STAXX_UPDATE_ASK_TTL', 21600);

// How many images one pass may ask about when there is NOTHING on record —
// see the stampede guard in staxx_update_check(). Deliberately well under a
// busy box's image count, so a memory that has gone missing rebuilds over
// several passes instead of in one burst against an allowance shared with
// the user's own pulls.
define('STAXX_UPDATE_REBUILD_CAP', 20);

// How long StaXX will keep asking the cheap "has it changed since <tag>?"
// question before insisting on the full one instead.
//
// The cheap question is only as trustworthy as the tag the registry hands
// back. Every registry tested makes that tag the content's own fingerprint,
// so it is sound — but a registry that reused a stale tag would answer
// "nothing changed" for ever and StaXX would believe it. That is the one
// failure here that HIDES an update instead of complaining about it, so the
// stored tag is deliberately thrown away this often and the whole question
// asked from scratch. A fortnight costs one extra full ask per image per two
// weeks, which is nothing against the saving.
define('STAXX_UPDATE_FRESH_EVERY', 14 * 86400);

/** The defaults every state array is filled against — never a partial array. */
function staxx_update_state_defaults(): array {
  return [
    'checked'   => 0,
    'ok'        => true,
    'error'     => '',
    'inspector' => '',
    'inspector_at' => 0,
    'paused'    => false,
    // PLAN_112 Phase B — the spend ledger, keyed by registry host. Replaces
    // the old 'limited'/'limitedBy' pair: staxx_spend_refusers() reads this
    // (falling back to the old shape for one pass on an existing box) to
    // say who is refusing right now, instead of one flag good for the whole
    // machine.
    'spend'     => [],
    'images'    => [],
    // PLAN_90 Stage 1 — per registry host, whether the direct HTTP route
    // works there at all, and whether a 304 has ever actually been seen —
    // see staxx_update_host_blocked()/staxx_update_host_note().
    'hosts'     => [],
    'history'   => [],
    // When the one-off baseline of "what is every service running right now"
    // was recorded into each stack's own history — 0 until it has. See
    // staxx_update_seed_history() in UpdateRun.php for why it runs once.
    'seeded'    => 0,
    'bases'     => [],
    'rebuilds'  => [],
    // PLAN_62 — Stage 2's findings, keyed by stack then by image. Discovery
    // (what the author publishes) is a property of the image and stays under
    // 'images'; the comparison result is a property of one stack's own file
    // and must never be shared with another stack that happens to run the
    // same image — see the plan's correction for the bug this fixes.
    'stacks'    => [],
  ];
}

/**
 * The single cache slot shared by staxx_update_state() and
 * staxx_update_state_save(), by reference, so a save this request is
 * immediately visible to the next read without going back to disk.
 * $set is null to just read the slot, or a value to overwrite it.
 */
function &staxx_update_state_cache(?array $set = null) {
  static $slot = null;
  if ($set !== null) $slot = $set;
  return $slot;
}

/**
 * The state file, decoded and always complete — a missing, unreadable or
 * corrupt file returns the same defaults a fresh install would, never a
 * partial array and never an exception.
 */
function staxx_update_state(): array {
  $cached = staxx_update_state_cache();
  if ($cached !== null) return $cached;

  $defaults = staxx_update_state_defaults();
  $file     = staxx_update_state_file();
  $raw      = $file === '' ? false : @file_get_contents($file);
  $data     = $raw === false ? null : json_decode($raw, true);
  $result   = is_array($data) ? array_merge($defaults, $data) : $defaults;

  staxx_update_state_cache($result);
  return $result;
}

/**
 * Write the state file, merged over the defaults so a save that only touches
 * one key can never drop the rest. Written temp-then-rename, same as
 * staxx_autostart_write(), and skipped entirely when the encoded content is
 * byte-identical to what is already there — flash has finite writes, and this
 * runs after every single image on every check.
 */
function staxx_update_state_save(array $state): bool {
  $merged  = array_merge(staxx_update_state_defaults(), staxx_update_state(), $state);
  $encoded = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  if ($encoded === false) return false;

  $file = staxx_update_state_file();
  if ($file === '') return false; // nowhere to keep it yet — the cached copy above still serves this request

  $current = @file_get_contents($file);
  if ($current !== false && $current === $encoded) {
    staxx_update_state_cache($merged);
    return true;
  }

  $dir = dirname($file);
  if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;

  $tmp = $dir.'/.'.basename($file).'.'.getmypid().'.tmp';
  if (@file_put_contents($tmp, $encoded) === false) return false;
  if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
  @chmod($file, 0600);

  staxx_update_state_cache($merged);
  return true;
}

/**
 * PLAN_90 Stage 1 — which docker CLI subcommand this box can use as the
 * FALLBACK when the direct HTTP route (staxx_registry_token()/digest()) does
 * not answer for a given image — probed once, because trying each route per
 * image would be sixty pointless processes on a busy server. No longer picks
 * the primary route: staxx_image_remote() always tries HTTP first, per
 * image, and only reaches for this when that particular image's registry
 * needs credentials StaXX does not have (the daemon might).
 * The answer is remembered in the state file so the next request (a fresh
 * PHP process, no static survives that) does not have to probe again, and
 * short-circuits on any non-empty stored value.
 */
function staxx_docker_inspector(): string {
  static $cached = '';
  if ($cached !== '') return $cached;

  $state    = staxx_update_state();
  $stored   = (string)($state['inspector'] ?? '');
  $storedAt = (int)($state['inspector_at'] ?? 0);
  // Same TTL as the digest cache — a transient buildx/manifest failure right
  // after install must not downgrade this box to the slow route permanently.
  if ($stored !== '' && (time() - $storedAt) < STAXX_UPDATE_ASK_TTL) return $cached = $stored;

  $bin = staxx_docker_bin();
  $code = 1;

  staxx_sh($bin.' buildx imagetools --help', 8, $code);
  $found = $code === 0 ? 'imagetools' : null;

  if ($found === null) {
    staxx_sh($bin.' manifest inspect --help', 8, $code);
    $found = $code === 0 ? 'manifest' : 'hub';
  }

  staxx_update_state_save(['inspector' => $found, 'inspector_at' => time()]);
  return $cached = $found;
}

/**
 * Version/source/created/revision out of a config's OCI-ish labels — shared
 * by every route in staxx_image_remote(), since the label names are the same
 * regardless of how the config was fetched.
 */
function staxx_update_labels_meta(array $labels): array {
  $out = [];

  $version = $labels['org.opencontainers.image.version']
    ?? $labels['build_version']
    ?? $labels['org.label-schema.version']
    ?? '';
  if ($version !== '') $out['version'] = (string)$version;

  $source = (string)($labels['org.opencontainers.image.source'] ?? '');
  if (strpos($source, 'https://') === 0) $out['source'] = $source;

  $created = (string)($labels['org.opencontainers.image.created'] ?? '');
  if ($created !== '') {
    $ts = strtotime($created);
    if ($ts !== false) $out['created'] = $ts;
  }

  // The commit this build came from, which is what makes a changelog between
  // two builds possible at all. Only a commit-shaped value is kept: anything
  // else gets spliced into a request path later and produces a lookup that
  // silently never matches — the same shape of failure as the unpin bug,
  // where a name that looked plausible made the whole feature do nothing.
  $revision = (string)($labels['org.opencontainers.image.revision'] ?? '');
  if (preg_match('/^[0-9a-f]{7,40}$/', $revision)) $out['revision'] = $revision;

  return $out;
}

/**
 * Owner and repository name out of a project link, or [] when the link is
 * not a GitHub one — a documentation site or a Docker Hub page gets [].
 * Pure. Every address builder below asks this rather than carrying its own
 * copy of the pattern, so what counts as a GitHub project cannot drift
 * between them.
 *
 * @return array{0: string, 1: string}|array{}
 */
function staxx_github_project(string $project): array {
  if (!preg_match('#^https://github\.com/([A-Za-z0-9._-]+)/([A-Za-z0-9._-]+?)(?:\.git)?/?$#', $project, $m)) {
    return [];
  }
  return [$m[1], $m[2]];
}

/**
 * Candidate GitHub "release by tag" API addresses for a version, or [] when
 * there is nothing to ask — pure, no network. Only a GitHub project link
 * qualifies; a documentation site or a Docker Hub page gets [].
 *
 * The version comes from a label the image PUBLISHER wrote, so it is
 * untrusted input about to be spliced into a path — the same reasoning that
 * made the source link go through a guard applies here. A version holding a
 * '/', a '..', whitespace or a control character is refused outright.
 *
 * Two candidates because vendors are evenly split on the leading 'v': the
 * version as written, and the same with a 'v' added or removed. Collapsed to
 * one when that would be a duplicate (an already-'v'-prefixed version has
 * nothing to add, and vice versa).
 */
function staxx_release_notes_urls(string $project, string $version): array {
  $repo = staxx_github_project($project);
  if ($repo === []) return [];
  [$owner, $name] = $repo;

  if ($version === '' || preg_match('#[/\x00-\x1f\x7f]|\.\.|\s#', $version)) return [];

  $alt = (strpos($version, 'v') === 0) ? substr($version, 1) : 'v'.$version;
  $tags = ($alt === $version) ? [$version] : [$version, $alt];

  $urls = [];
  foreach ($tags as $tag) {
    $urls[] = 'https://api.github.com/repos/'.$owner.'/'.$name.'/releases/tags/'.rawurlencode($tag);
  }
  return $urls;
}

/**
 * Cap a release body at STAXX_NOTES_MAX, cut at the last line break before
 * the cap so it never stops mid-word — falling back to a hard cut only when
 * the window has no break at all. Pure; the only change made to the text
 * itself is normalising line endings, because it is the vendor's own words
 * and altering them further would make the notes a lie about what was
 * written.
 *
 * @return array{notes: string, cut: bool}
 */
function staxx_release_notes_trim(string $body): array {
  $text = str_replace(["\r\n", "\r"], "\n", $body);
  if (strlen($text) <= STAXX_NOTES_MAX) return ['notes' => $text, 'cut' => false];

  $window = substr($text, 0, STAXX_NOTES_MAX);
  $break  = strrpos($window, "\n");
  if ($break !== false) return ['notes' => substr($window, 0, $break), 'cut' => true];

  // No line break to cut at, so the cap lands wherever it lands — and a cap
  // counted in bytes can land in the middle of a multi-byte character. That is
  // not merely untidy: the record is stored as JSON, json_encode() returns
  // false outright on a malformed string, and staxx_record_write_index() would
  // then fail the whole write silently, taking this stack's compose history
  // down with it. Drop any trailing incomplete sequence.
  while ($window !== '' && (ord($window[strlen($window) - 1]) & 0xc0) === 0x80) {
    $window = substr($window, 0, -1);
  }
  if ($window !== '' && (ord($window[strlen($window) - 1]) & 0x80) !== 0) {
    $window = substr($window, 0, -1);
  }
  return ['notes' => $window, 'cut' => true];
}

/**
 * Fetch a GitHub release's notes for one version. The only part of this
 * trio that touches the network — everything else is provably pure.
 * Returns a blank, uncut result on anything at all going wrong: no network,
 * DNS, a 404 on both the 'v' and bare-version candidates, a body that isn't
 * JSON. Callers never need to distinguish "no notes" from "fetch failed".
 *
 * GitHub refuses an unauthenticated request outright without a User-Agent
 * header — this is not guessable from the code, so it is recorded here.
 *
 * Markdown is deliberately not converted: there are no client-side libraries
 * in this project, and showing the vendor's text exactly as written is the
 * honest presentation of it.
 *
 * @return array{notes: string, url: string, cut: bool}
 */
function staxx_release_notes_fetch(string $project, string $version): array {
  $empty = ['notes' => '', 'url' => '', 'cut' => false];

  foreach (staxx_release_notes_urls($project, $version) as $url) {
    $data = staxx_hub_json($url, ['User-Agent: StaXX'], 6, 8);
    if (!is_array($data)) continue;
    $body = (string)($data['body'] ?? '');
    if ($body === '') continue;

    $trim = staxx_release_notes_trim($body);
    return [
      'notes' => $trim['notes'],
      'url'   => is_string($data['html_url'] ?? null) ? $data['html_url'] : '',
      'cut'   => $trim['cut'],
    ];
  }

  return $empty;
}

/**
 * GitHub's "compare two commits" API address, or '' when one cannot be
 * built — pure, no network. Only a GitHub project link qualifies, and both
 * ids must be commit-shaped, because both are spliced into the path.
 *
 * A commit compared with itself returns '' too: there is nothing between a
 * build and the same build, and asking would spend an allowance to be told
 * so.
 */
function staxx_compare_commits_url(string $project, string $from, string $to): string {
  $repo = staxx_github_project($project);
  if ($repo === []) return '';
  [$owner, $name] = $repo;

  if (!preg_match('/^[0-9a-f]{7,40}$/', $from)) return '';
  if (!preg_match('/^[0-9a-f]{7,40}$/', $to)) return '';
  if ($from === $to) return '';

  return 'https://api.github.com/repos/'.$owner.'/'.$name
       . '/compare/'.rawurlencode($from).'...'.rawurlencode($to);
}

/**
 * The commit subjects between two builds — what actually went in since the
 * last build seen. Returns the all-empty answer on anything at all going
 * wrong, and makes no request when no address could be built, so callers
 * never need to distinguish "nothing to show" from "the ask failed".
 *
 * Subject lines only, in the order the project gives them, never re-worded
 * and never filtered. In particular merge commits are kept: a merge is noise
 * in one project and the only signal in another, and picking between them is
 * editorialising somebody else's history — the same reasoning that stops the
 * notes trimmer touching a vendor's prose.
 *
 * @return array{changes: string[], url: string, cut: bool}
 */
function staxx_changelog_fetch(string $project, string $from, string $to): array {
  $empty = ['changes' => [], 'url' => '', 'cut' => false];

  $url = staxx_compare_commits_url($project, $from, $to);
  if ($url === '') return $empty;

  // Size-capped, unlike every other ask here: the answer carries each changed
  // file's whole diff alongside the commit list, so the reply is far bigger
  // than the question — 145 KB measured for nine commits, and GitHub's own
  // ceiling is 250. Eight megabytes fits any realistic comparison and stops
  // the pathological one being held in memory twice on a NAS.
  $data = staxx_hub_json($url, ['User-Agent: StaXX'], 6, 8, 8 * 1024 * 1024);
  if (!is_array($data)) return $empty;

  $commits = is_array($data['commits'] ?? null) ? $data['commits'] : [];

  $changes = [];
  $cut     = false;
  foreach ($commits as $commit) {
    if (count($changes) >= STAXX_CHANGES_MAX) { $cut = true; break; }

    $message = is_array($commit) ? (string)($commit['commit']['message'] ?? '') : '';
    $nl      = strpos($message, "\n");
    $subject = trim($nl === false ? $message : substr($message, 0, $nl));
    if ($subject === '') continue;

    if (strlen($subject) > STAXX_CHANGES_LINE_MAX) {
      // Hard cut, no ellipsis, for the same reason the notes trimmer does not
      // add one: the text is somebody else's words and a marker we invented
      // would read as part of them. And a cap counted in bytes can land in
      // the middle of a multi-byte character, which json_encode() refuses
      // outright — taking the whole record write down with it. Drop any
      // trailing incomplete sequence, exactly as staxx_release_notes_trim().
      $subject = substr($subject, 0, STAXX_CHANGES_LINE_MAX);
      while ($subject !== '' && (ord($subject[strlen($subject) - 1]) & 0xc0) === 0x80) {
        $subject = substr($subject, 0, -1);
      }
      if ($subject !== '' && (ord($subject[strlen($subject) - 1]) & 0x80) !== 0) {
        $subject = substr($subject, 0, -1);
      }
      if ($subject === '') continue;
      $cut = true;
    }

    $changes[] = $subject;
  }

  // GitHub caps its own commits array at 250 and reports the real count
  // separately, so a comparison spanning more than that arrives already
  // shortened — say so rather than presenting a partial list as the whole.
  $total = (int)($data['total_commits'] ?? 0);
  if ($total > count($commits)) $cut = true;

  if ($changes === []) return $empty;

  return [
    'changes' => $changes,
    'url'     => is_string($data['html_url'] ?? null) ? $data['html_url'] : '',
    'cut'     => $cut,
  ];
}

/**
 * The release tag sitting on one commit, or '' when none does — one request,
 * '' with no request at all when the project link is not GitHub or the id is
 * not commit-shaped.
 *
 * The tag LISTING is asked rather than the git ref API on purpose. An
 * annotated tag does not point at a commit, it points at a tag object, so
 * dereferencing one by hand costs a second lookup that is easy to get wrong
 * and easy to not notice being wrong. This listing reports each tag's
 * already-dereferenced commit, so the trap cannot be walked into and the
 * whole thing stays at one request.
 */
function staxx_release_tag_at_commit(string $project, string $commit): string {
  $repo = staxx_github_project($project);
  if ($repo === []) return '';
  [$owner, $name] = $repo;

  if (!preg_match('/^[0-9a-f]{7,40}$/', $commit)) return '';

  $data = staxx_hub_json(
    'https://api.github.com/repos/'.$owner.'/'.$name.'/tags',
    ['User-Agent: StaXX'], 6, 8
  );
  if (!is_array($data)) return '';

  foreach ($data as $tag) {
    if (!is_array($tag)) continue;
    $sha = (string)($tag['commit']['sha'] ?? '');
    // The label may carry a 7-character id where the API gives all 40, so
    // this is a prefix match, and case-insensitive because neither side
    // promises which it writes.
    if ($sha === '' || stripos($sha, $commit) !== 0) continue;
    $found = (string)($tag['name'] ?? '');
    if ($found !== '') return $found;
  }

  return '';
}

/**
 * The tag half of an image reference, 'latest' when none is written — the
 * same default Docker itself applies.
 */
function staxx_image_tag_part(string $image): string {
  $trimmed = trim($image);
  $slash = strrpos($trimmed, '/');
  $colon = strrpos($trimmed, ':');
  if ($colon !== false && ($slash === false || $colon > $slash)) return substr($trimmed, $colon + 1);
  return 'latest';
}

/**
 * Tags a repository has published, from any registry — bare tag names, []
 * on any failure, the same contract as staxx_image_tags() this delegates to
 * for Docker Hub and its two linuxserver mirrors.
 *
 * For everything else, the standard registry conversation: ask the host's
 * /v2/ root, read the WWW-Authenticate challenge it answers with for the
 * token realm and service, fetch a pull-scoped token from that realm (many
 * public hosts need none at all — a host with no challenge is already
 * anonymous), then list the repository's tags. Registry v2 answers in
 * lexical order with no dates, unlike Hub's "most recently pushed" — so
 * nothing here or downstream may assume recency for this route.
 *
 * @return string[]
 */
function staxx_registry_tags(string $image): array {
  if (staxx_hub_repo_path($image) !== '') return staxx_image_tags($image);

  $ref = trim($image);
  $ref = preg_replace('/@sha256:[0-9a-f]+$/', '', $ref);
  $slash = strrpos($ref, '/');
  $colon = strrpos($ref, ':');
  if ($colon !== false && ($slash === false || $colon > $slash)) $ref = substr($ref, 0, $colon);

  $slash = strpos($ref, '/');
  if ($slash === false) return []; // no host and not Hub-eligible — nothing to ask
  $host = substr($ref, 0, $slash);
  $repo = substr($ref, $slash + 1);
  if ($host === '' || $repo === '') return [];

  // Same shape a Hub repository name is held to, just without the one-slash
  // limit — a generic registry allows deeper paths (ghcr.io/org/team/name).
  if (!preg_match('#^[a-z0-9]+(?:[._-][a-z0-9]+)*(?:/[a-z0-9]+(?:[._-][a-z0-9]+)*)+$#D', $repo)) {
    return [];
  }

  // The ping needs the response headers, not the body, so it goes through
  // curl directly rather than staxx_hub_json() (which is -f and throws the
  // body away on anything but 2xx). A host with no challenge at all is
  // already anonymous, so an empty realm below is not itself a failure.
  $headers = staxx_sh(
    'curl -sS -L --max-time 6 -D /dev/stdout -o /dev/null '.escapeshellarg('https://'.$host.'/v2/'), 8
  );

  $realm = '';
  $service = '';
  foreach (explode("\n", $headers) as $line) {
    if (preg_match('/^www-authenticate:\s*(.+)$/i', trim($line), $m)) {
      // The realm is whatever the remote registry says it is — a hostile one
      // could name a file:// path or an internal address, so only an actual
      // http(s) realm is accepted before it is ever used to build a URL.
      if (preg_match('/realm="([^"]+)"/i', $m[1], $rm) && preg_match('#^https?://#i', $rm[1])) {
        $realm = $rm[1];
      }
      if (preg_match('/service="([^"]+)"/i', $m[1], $sm)) $service = $sm[1];
      break;
    }
  }

  $bearer = [];
  if ($realm !== '') {
    $tokenUrl = $realm.'?scope='.rawurlencode('repository:'.$repo.':pull');
    if ($service !== '') $tokenUrl .= '&service='.rawurlencode($service);
    $token = staxx_hub_json($tokenUrl, [], 6, 8);
    $bearerToken = (string)($token['token'] ?? ($token['access_token'] ?? ''));
    if ($bearerToken !== '') $bearer = ['Authorization: Bearer '.$bearerToken];
  }

  $data = staxx_hub_json('https://'.$host.'/v2/'.$repo.'/tags/list', $bearer, 6, 8);
  if ($data === null || !isset($data['tags']) || !is_array($data['tags'])) return [];

  $tags = [];
  foreach ($data['tags'] as $t) {
    if (is_string($t)) $tags[] = $t;
  }
  return $tags;
}

/**
 * A replacement to offer for a tag the registry no longer has, at most three
 * and best first. A guess is still advice, not an instruction — decision 1
 * of the memos plan leaves the actual change to the editor, where the real
 * tag list is in view.
 *
 * 1. a conventional rolling tag that still exists, preferred over any
 *    version number — "stable" outranks a higher number on the theory that
 *    someone who followed a rolling tag before probably still wants one;
 * 2. otherwise the highest version-looking tag, compared numerically —
 *    version_compare() treats "30" as text against "9" and gets 0.30 vs 0.9
 *    backwards, so segments are compared as integers by hand instead;
 * 3. never a tag that reads as a test build — those are worse than no
 *    suggestion at all.
 *
 * @return string[] at most three
 */
function staxx_tag_suggestions(string $missing, array $tags): array {
  $missing = trim($missing);
  $testish = ['rc', 'alpha', 'beta', 'canary', 'nightly', 'dev', 'edge'];

  $candidates = [];
  foreach ($tags as $tag) {
    $tag = trim((string)$tag);
    if ($tag === '' || $tag === $missing) continue;
    $isTestBuild = false;
    foreach ($testish as $word) {
      if (stripos($tag, $word) !== false) { $isTestBuild = true; break; }
    }
    if ($isTestBuild) continue;
    $candidates[] = $tag;
  }

  $suggestions = [];

  foreach (['stable', 'release', 'main', 'latest'] as $rolling) {
    if (in_array($rolling, $candidates, true)) { $suggestions[] = $rolling; break; }
  }

  $versionish = array_values(array_filter($candidates, function (string $tag): bool {
    return preg_match('/^v?\d+(\.\d+)*$/', $tag) === 1;
  }));
  if ($versionish) {
    usort($versionish, function (string $a, string $b): int {
      $na = array_map('intval', explode('.', ltrim($a, 'vV')));
      $nb = array_map('intval', explode('.', ltrim($b, 'vV')));
      $len = max(count($na), count($nb));
      for ($i = 0; $i < $len; $i++) {
        $cmp = ($na[$i] ?? 0) <=> ($nb[$i] ?? 0);
        if ($cmp !== 0) return -$cmp; // highest first
      }
      return 0;
    });
    if (!in_array($versionish[0], $suggestions, true)) $suggestions[] = $versionish[0];
  }

  foreach ($candidates as $tag) {
    if (count($suggestions) >= 3) break;
    if (!in_array($tag, $suggestions, true)) $suggestions[] = $tag;
  }

  return array_slice($suggestions, 0, 3);
}

/**
 * Classify a route's raw output so the caller can tell "the registry said no
 * for a reason that will still be true in five minutes" from "ask again
 * later" — checked on plain text, since a 429 reply is never JSON.
 *
 * On a plain 'notfound', and only when $image is given, one extra request
 * asks the registry for the repository's whole tag list — an image that
 * answered normally must never trigger it. Two callers ask for a tag list:
 * this one, and the `image:` field's own lookup as it is typed in. The
 * second is affordable because the browser debounces it by 400ms and
 * caches the answer per repository for the session, so a repository is
 * asked about once however long someone spends editing.
 *
 * Tags returned and ours absent
 * means the tag itself was withdrawn ('tagmissing'), with the list handed
 * back through $tags so the caller does not have to ask again to store it.
 * Tags returned and ours still among them means something stranger is
 * going on than a withdrawn tag, and a guess there would be worse than
 * silence — stays 'notfound'. No tags, or the lookup itself failing, also
 * stays 'notfound': a private registry answering nothing is correct, not
 * evidence of anything.
 */
function staxx_remote_failure_reason(string $out, string $image = '', ?array &$tags = null): string {
  if (preg_match('/429/', $out) && stripos($out, 'Too Many Requests') !== false) return 'limited';
  if (stripos($out, 'toomanyrequests') !== false) return 'limited';
  if (stripos($out, 'not found') !== false
    || stripos($out, 'manifest unknown') !== false
    || stripos($out, 'no such') !== false) {
    if ($image !== '') {
      $registryTags = staxx_registry_tags($image);
      if ($registryTags !== [] && !in_array(staxx_image_tag_part($image), $registryTags, true)) {
        $tags = $registryTags;
        return 'tagmissing';
      }
    }
    return 'notfound';
  }
  return 'failed';
}

/**
 * Which registry an image reference is actually asked of — Docker Hub is the
 * implied default, and everything else names its own host as the first path
 * segment. A rate-limit refusal must only silence the registry that actually
 * refused, never the whole pass, so staxx_update_check() needs to know which
 * one that is for each image.
 */
function staxx_image_registry(string $image): string {
  $ref = preg_replace('/@sha256:[0-9a-f]+$/', '', trim($image));
  $parts = explode('/', $ref);
  $first = $parts[0];
  // A first segment carrying a dot or a colon is a host name — but only when
  // something follows it. In a one-segment reference such as nginx:alpine the
  // colon introduces the tag, not a port, and reading it as a host gave that
  // image a registry of its own in the spend ledger (seen on the box
  // 2026-09-02). Anything else is Docker Hub's implied default.
  return (count($parts) > 1 && (strpos($first, '.') !== false || strpos($first, ':') !== false))
    ? strtolower($first) : 'docker.io';
}

/**
 * PLAN_90 Stage 1 — whether the direct HTTP route has already proved itself
 * unusable at this host (a challenge needing credentials StaXX does not
 * have) recently enough that trying it again is pointless — a private or
 * self-hosted registry the box has no token for must not be re-probed on
 * every single image, every single pass. Same re-probe window as
 * staxx_docker_inspector()'s own memory, and for the same reason: a
 * transient failure right after the registry comes back up must not lock
 * this box out of the fast route forever.
 */
function staxx_update_host_blocked(string $host): bool {
  $hosts = (array)(staxx_update_state()['hosts'] ?? []);
  $entry = $hosts[$host] ?? null;
  if (!is_array($entry) || ($entry['http'] ?? true) !== false) return false;
  return (time() - (int)($entry['at'] ?? 0)) < STAXX_UPDATE_ASK_TTL;
}

/**
 * Merge a fact about one registry host into the small 'hosts' state block —
 * 'http' (false once a credential challenge has been seen there, with a
 * fresh 'at' stamp so staxx_update_host_blocked() can re-probe after the
 * same window inspector_at uses) and 'saw304' (true forever once this box
 * has actually seen this host honour a conditional request — nothing reads
 * it yet, but it is the only way a future readout can tell the user which
 * registries are actually saving them anything).
 */
function staxx_update_host_note(string $host, array $patch): void {
  $hosts = (array)(staxx_update_state()['hosts'] ?? []);
  $hosts[$host] = array_merge((array)($hosts[$host] ?? []), $patch);
  staxx_update_state_save(['hosts' => $hosts]);
}

/**
 * PLAN_112 Phase B — the ledger. One pure function folds a single event
 * ('free', 'paid', 'refused' or 'cli') into one host's own bucket of
 * $spend, which is keyed by registry host and never touches another host's
 * entry. Pure and canned-clock so it can be tested without a real pass.
 *
 * An hour bucket ('hour', 'free', 'paid', 'refused', 'cli') rolls to zero
 * the moment $now lands in a new hour; a day bucket ('day', 'dayFree',
 * 'dayPaid') rolls the same way but only counts the two kinds a person
 * would call "asked" (a refusal or a CLI question, which StaXX cannot see
 * the price of, are not part of that count). The registry's own reported
 * ceiling — 'limit', 'remaining', 'window' — is carried forward untouched
 * when $event omits it, so an unmetered ask does not erase what the last
 * metered one learned.
 *
 * @param array $event ['kind'=>'free'|'paid'|'refused'|'cli', 'limit'=>?array,
 *                       'headfree'=>?bool]
 */
function staxx_spend_record(array $spend, string $host, array $event, int $now): array {
  $hour = intdiv($now, 3600);
  $day  = intdiv($now, 86400);

  $entry = (array)($spend[$host] ?? []);
  if ((int)($entry['hour'] ?? -1) !== $hour) {
    $entry['hour'] = $hour;
    $entry['free'] = 0;
    $entry['paid'] = 0;
    $entry['refused'] = 0;
    $entry['cli'] = 0;
  }
  if ((int)($entry['day'] ?? -1) !== $day) {
    $entry['day'] = $day;
    $entry['dayFree'] = 0;
    $entry['dayPaid'] = 0;
  }

  $kind = (string)($event['kind'] ?? '');
  switch ($kind) {
    case 'free':
      $entry['free']++;
      $entry['dayFree']++;
      break;
    case 'paid':
      $entry['paid']++;
      $entry['dayPaid']++;
      break;
    case 'refused':
      $entry['refused']++;
      $entry['refusedAt'] = $now;
      break;
    case 'cli':
      $entry['cli']++;
      break;
  }

  if (isset($event['limit']) && is_array($event['limit'])) {
    $limit = $event['limit'];
    if (isset($limit['limit']))     $entry['limit']     = (int)$limit['limit'];
    if (isset($limit['remaining'])) $entry['remaining'] = (int)$limit['remaining'];
    $entry['window'] = (int)($limit['window'] ?? ($entry['window'] ?? 3600));
  }
  if (isset($event['headfree'])) $entry['headfree'] = (bool)$event['headfree'];

  $spend[$host] = $entry;
  return $spend;
}

/**
 * The ceiling a host's own reported limit implies StaXX may spend on
 * CHARGED questions in one hour — half of it, so a real pull the user
 * makes always has room. With no reported limit, Docker Hub is assumed to
 * allow fifty (half of the anonymous hundred), every other host is treated
 * as unmetered (PHP_INT_MAX), and 'assumed' says which of those two guesses
 * this is, for the readout to say so honestly.
 *
 * @return array{ceiling:int, assumed:bool}
 */
function staxx_spend_ceiling(array $hostSpend, string $host): array {
  if (isset($hostSpend['limit'])) {
    return ['ceiling' => intdiv((int)$hostSpend['limit'], 2), 'assumed' => false];
  }
  if ($host === 'docker.io') {
    return ['ceiling' => 50, 'assumed' => true];
  }
  return ['ceiling' => PHP_INT_MAX, 'assumed' => false];
}

/**
 * May a CHARGED question be sent to this host right now? Free questions
 * never consult this — the whole point of the free form is that it costs
 * nothing to ask. Prefers the registry's own reported 'remaining' while it
 * is still this hour's figure; otherwise falls back to counting what this
 * box itself has paid this hour, which is the only figure left once the
 * registry's own count has gone stale.
 */
function staxx_spend_may_pay(array $hostSpend, string $host, int $now): bool {
  $ceiling = staxx_spend_ceiling($hostSpend, $host)['ceiling'];
  $hour = intdiv($now, 3600);

  if (isset($hostSpend['remaining']) && (int)($hostSpend['hour'] ?? -1) === $hour) {
    return (int)$hostSpend['remaining'] > $ceiling;
  }

  $paid = ((int)($hostSpend['hour'] ?? -1) === $hour) ? (int)($hostSpend['paid'] ?? 0) : 0;
  return $paid < $ceiling;
}

/**
 * Which hosts are refusing questions right now — a 'refusedAt' inside the
 * last hour. The one place both the current 'spend' shape and the old
 * 'limited'/'limitedBy' pair are read: a state file with no 'spend' key at
 * all yet falls back to the old flags, purely so a box updated mid-hour
 * does not show a broken notice for the hour it takes the next pass to
 * fill the ledger in — there is no installed base here to migrate for real.
 */
function staxx_spend_refusers(array $state, int $now): array {
  if (!isset($state['spend'])) {
    return !empty($state['limited']) ? (array)($state['limitedBy'] ?? ['docker.io']) : [];
  }
  $refusers = [];
  foreach ((array)$state['spend'] as $host => $entry) {
    if (($now - (int)($entry['refusedAt'] ?? 0)) < 3600 && isset($entry['refusedAt'])) {
      $refusers[] = $host;
    }
  }
  return $refusers;
}

/**
 * The readout — one row per host in the ledger, sorted docker.io first
 * (the one everybody recognises) then alphabetically. What the settings
 * panel draws and what the pass prints a summary line from, so the keys
 * here are the whole contract: stale hour/day buckets read as zero rather
 * than showing a figure from the hour before last.
 *
 * @return list<array{host:string, askedHour:int, askedDay:int, freeHour:int,
 *   freeDay:int, paidHour:int, paidDay:int, refused:int, cli:int,
 *   remaining:?int, limit:?int, window:?int, headfree:bool, refusedAt:int,
 *   ceiling:?int, assumed:bool}>
 */
function staxx_spend_report(array $state, int $now): array {
  $hour = intdiv($now, 3600);
  $day  = intdiv($now, 86400);

  $hosts = array_keys((array)($state['spend'] ?? []));
  usort($hosts, function ($a, $b) {
    if ($a === 'docker.io') return -1;
    if ($b === 'docker.io') return 1;
    return strcmp($a, $b);
  });

  $rows = [];
  foreach ($hosts as $host) {
    $entry = (array)$state['spend'][$host];
    $curHour = (int)($entry['hour'] ?? -1) === $hour;
    $curDay  = (int)($entry['day']  ?? -1) === $day;

    $freeHour = $curHour ? (int)($entry['free'] ?? 0) : 0;
    $paidHour = $curHour ? (int)($entry['paid'] ?? 0) : 0;
    $ceilingInfo = staxx_spend_ceiling($entry, $host);

    $rows[] = [
      'host'      => $host,
      'askedHour' => $freeHour + $paidHour,
      'askedDay'  => $curDay ? ((int)($entry['dayFree'] ?? 0) + (int)($entry['dayPaid'] ?? 0)) : 0,
      'freeHour'  => $freeHour,
      'freeDay'   => $curDay ? (int)($entry['dayFree'] ?? 0) : 0,
      'paidHour'  => $paidHour,
      'paidDay'   => $curDay ? (int)($entry['dayPaid'] ?? 0) : 0,
      'refused'   => $curHour ? (int)($entry['refused'] ?? 0) : 0,
      'cli'       => $curHour ? (int)($entry['cli'] ?? 0) : 0,
      'remaining' => (isset($entry['remaining']) && $curHour) ? (int)$entry['remaining'] : null,
      'limit'     => isset($entry['limit']) ? (int)$entry['limit'] : null,
      'window'    => isset($entry['window']) ? (int)$entry['window'] : null,
      'headfree'  => (bool)($entry['headfree'] ?? true),
      'refusedAt' => (int)($entry['refusedAt'] ?? 0),
      'ceiling'   => $ceilingInfo['ceiling'] === PHP_INT_MAX ? null : $ceilingInfo['ceiling'],
      'assumed'   => $ceilingInfo['assumed'],
    ];
  }
  return $rows;
}

/**
 * "5 hours", "2 days" — a duration in words, plain and small on purpose:
 * this only ever fills a gap in one sentence (staxx_updates_pill_for_image()'s
 * repeated-failure notice), so it does not need stacks.js's timeAgoWords()
 * richness, just the same units.
 */
function staxx_time_span_words(int $seconds): string {
  if ($seconds < 60) return 'less than a minute';
  $mins = intdiv($seconds, 60);
  if ($mins < 60) return $mins.' '.($mins === 1 ? 'minute' : 'minutes');
  $hours = intdiv($mins, 60);
  if ($hours < 24) return $hours.' '.($hours === 1 ? 'hour' : 'hours');
  $days = intdiv($hours, 24);
  return $days.' '.($days === 1 ? 'day' : 'days');
}

/**
 * PLAN_90 Stage 3 — how long a remembered answer for this image is trusted
 * before it is worth asking again. A one-liner over staxx_update_cadence_why()
 * so there is exactly one cadence rule and no call site here has to change
 * shape for PLAN_112 Phase C's sentences to exist.
 *
 * A digest-pinned reference never reaches here — staxx_update_check() already
 * handles that as 'pinned' before the cadence is ever asked about.
 */
function staxx_update_cadence(string $image, array $entry): int {
  return staxx_update_cadence_why($image, $entry)['interval'];
}

/**
 * PLAN_112 Phase C — the same rule as staxx_update_cadence(), but returning
 * the reasoning as a plain sentence alongside the interval, so the settings
 * readout and the pill's tooltip can say WHY an image is checked as often as
 * it is without re-deriving the rule and risking the two drifting apart.
 *
 * @return array{interval:int, why:string}
 */
function staxx_update_cadence_why(string $image, array $entry): array {
  $floor   = STAXX_UPDATE_ASK_TTL; // 6 hours
  $daily   = 86400;
  $ceiling = 14 * 86400;

  // A digest-pinned reference names one exact build, so asking could only
  // ever fetch the same build again.
  if (strpos($image, '@sha256:') !== false) {
    return ['interval' => $ceiling,
            'why' => 'Pinned to one exact build, so never checked — pulling it could only fetch the same build again.'];
  }

  // Override 1 — no baseline yet, so there is nothing to compare a slower
  // interval against. Ask promptly.
  if ((int)($entry['asked'] ?? 0) === 0) {
    return ['interval' => $floor, 'why' => 'Not compared yet, so asked at the next pass.'];
  }

  // Override 2 — an errored image retries fast so it clears itself the
  // moment the registry answers again, UNLESS Stage 3b has already flagged
  // it as persistently dead (five ask failures in a row — staxx_image_remote()
  // only counts the transient-or-unknown 'failed' reason toward this, never
  // a rate refusal, a withdrawn tag, or a permanent 'not found'/'unsupported'
  // answer), in which case daily is plenty.
  if ((string)($entry['error'] ?? '') !== '') {
    if ((int)($entry['fails'] ?? 0) >= 5) {
      return ['interval' => $daily,
              'why' => 'Five checks in a row have failed, so asked once a day until the registry answers again.'];
    }
    return ['interval' => $floor,
            'why' => 'The last check did not get an answer, so asked again within six hours.'];
  }

  $tag = (string)(staxx_registry_ref($image)['tag'] ?? '');
  $rolling = ['latest', 'main', 'master', 'develop', 'nightly', 'edge', 'stable', 'beta', 'dev'];
  if ($tag === '' || in_array(strtolower($tag), $rolling, true)) {
    $interval = $floor;
    $why = 'A moving tag, so checked every six hours.';
  } elseif (preg_match('/^v?[0-9]+(\.[0-9]+){0,4}(-[A-Za-z0-9]+)?$/', $tag)) {
    $interval = 7 * 86400;
    $why = 'A version-numbered tag, so checked once a week — a numbered release does not quietly change under the same number.';
  } else {
    $interval = $daily;
    $why = 'Neither a version number nor a moving tag, so checked once a day.';
  }

  // Churn modulation — 'moves' is a ring of at most the last 5 timestamps at
  // which the digest actually changed (see staxx_update_check()'s decisive
  // write). The two rules are mutually exclusive by construction: an image
  // cannot have moved twice in the last 14 days and also have nothing newer
  // than 90 days.
  $now = time();
  $newestMove = 0;
  $recentMoves = 0;
  foreach ((array)($entry['moves'] ?? []) as $m) {
    $m = (int)$m;
    if ($m > $newestMove) $newestMove = $m;
    if ($now - $m < 14 * 86400) $recentMoves++;
  }
  $quiet = false;
  if ($recentMoves >= 2) {
    $interval = $floor;
    $why = 'Changed twice in the last fortnight, so checked every six hours.';
  } elseif ($newestMove > 0 && ($now - $newestMove) > 90 * 86400) {
    // Only stretched on evidence. An empty ring is not proof of a quiet
    // image, it is proof of a short memory — every image has one the pass
    // after it is first recorded, and stretching on that would quietly halve
    // how often a rolling tag is checked, which is not what the tag shape
    // above promises.
    $interval *= 2;
    $quiet = true;
  }

  $interval = max($floor, min($interval, $ceiling));
  // Said against the interval actually returned (after the clamp), not the
  // pre-doubling one, so the sentence never names an interval the rule
  // cannot actually produce.
  if ($quiet) $why = 'Has not changed in over three months, so checked every '.staxx_time_span_words($interval).'.';

  return ['interval' => $interval, 'why' => $why];
}

/**
 * PLAN_127 — staxx_update_cadence_why()'s interval, spelled out the way its
 * own hand-written sentences already say it ("every six hours") rather than
 * as a bare number, for the hover card's "Checked" row. Values this function
 * does not recognise (the doubled interval a quiet image earns) fall back to
 * staxx_time_span_words() in numeral form — still readable, just not hand-
 * tuned prose.
 */
function staxx_update_interval_words(int $seconds): string {
  $named = [
    STAXX_UPDATE_ASK_TTL => 'six hours',
    86400                => 'a day',
    7 * 86400            => 'a week',
    14 * 86400           => 'a fortnight',
  ];
  return 'every '.($named[$seconds] ?? staxx_time_span_words($seconds));
}

/**
 * PLAN_127 — staxx_update_cadence_why()'s sentence, split at its own "so
 * checked every…" join into the two short phrases the hover card shows as
 * separate rows ("Checked" / "Why") instead of one long one that overflowed
 * the card in the first mockup. Matched by hand against that function's own
 * fixed sentences, so a new sentence added there needs a line added here too
 * — there is no way to split English prose apart mechanically that would
 * survive that function's wording ever changing.
 *
 * @return array{interval:string, why:string} why is '' when no sentence here
 *              recognises the one just returned, rather than guessing.
 */
function staxx_update_cadence_why_short(string $image, array $entry): array {
  $full = staxx_update_cadence_why($image, $entry);
  $why  = $full['why'];
  $short = '';
  if (strpos($why, 'Pinned to one exact build') === 0) {
    $short = 'pinned to one exact build';
  } elseif (strpos($why, 'Not compared yet') === 0) {
    $short = 'not compared yet';
  } elseif (strpos($why, 'Five checks in a row have failed') === 0) {
    $short = 'checks have been failing';
  } elseif (strpos($why, 'The last check did not get an answer') === 0) {
    $short = 'the last check found no answer';
  } elseif (strpos($why, 'A moving tag') === 0) {
    $short = 'a moving tag';
  } elseif (strpos($why, 'A version-numbered tag') === 0) {
    $short = 'a version-numbered tag';
  } elseif (strpos($why, 'Neither a version number') === 0) {
    $short = 'neither a version number nor a moving tag';
  } elseif (strpos($why, 'Changed twice in the last fortnight') === 0) {
    $short = 'changed twice in a fortnight';
  } elseif (strpos($why, 'Has not changed in over three months') === 0) {
    $short = 'unchanged for over three months';
  }
  return ['interval' => staxx_update_interval_words($full['interval']), 'why' => $short];
}

/**
 * PLAN_127 — the same three clock facts staxx_update_when_words() composes
 * into one sentence, returned instead as the short phrases the hover card's
 * table rows want. Empty strings throughout when never asked, exactly
 * mirroring staxx_update_when_words()'s own '' — a caller drops a row whose
 * value is '' rather than showing it blank.
 *
 * @return array{asked:string, next:string, interval:string, why:string}
 */
function staxx_update_clock_words(string $image, array $entry, int $now): array {
  $asked = (int)($entry['asked'] ?? 0);
  if ($asked === 0) return ['asked' => '', 'next' => '', 'interval' => '', 'why' => ''];

  $nextDue = (int)($entry['nextDue'] ?? 0);
  $next = $nextDue > $now
    ? 'in '.staxx_time_span_words($nextDue - $now)
    : 'at the next pass';

  $cadence = staxx_update_cadence_why_short($image, $entry);
  return [
    'asked'    => staxx_time_span_words($now - $asked).' ago',
    'next'     => $next,
    'interval' => $cadence['interval'],
    'why'      => $cadence['why'],
  ];
}

/**
 * What the registry currently has for one image reference — digest, version,
 * source and creation time, every key present only when actually known.
 * Returns [] on any failure at all: a failure must never be mistaken for
 * "up to date", so the caller records "could not check" instead of comparing
 * against nothing. $why is set to why, one of 'limited', 'unsupported',
 * 'notfound', 'tagmissing', 'failed', or '' on success — so a caller hitting
 * Docker Hub's hourly ceiling can stop asking instead of burning the rest of
 * it. $tags is filled with the repository's tag list only when $why comes
 * back 'tagmissing', so the caller can store it without asking again.
 *
 * PLAN_90 Stage 1/2 — the direct HTTP route is tried first, per image, using
 * $prev (this image's own stored state) to send a conditional request when
 * one is safe to send. Only when that route cannot be used at all — a
 * credential challenge this box cannot answer, or a host already remembered
 * as one where that keeps happening — does control fall through to the
 * docker CLI, exactly as it behaved before this route existed. A private or
 * self-hosted registry is a first-class case here, not an afterthought: the
 * daemon may hold credentials StaXX itself does not.
 *
 * $prev['etag']/$prev['accept'] are only trusted when the Accept list they
 * were recorded against still matches staxx_registry_accept_id() — a
 * changed list would otherwise compare a stored tag against the wrong
 * manifest shape and silently miss a real update.
 */
function staxx_image_remote(string $image, string &$why = null, ?array &$tags = null, array $prev = []): array {
  $why = '';
  $ref = staxx_registry_ref($image);
  $host = (string)($ref['host'] ?? '');
  $repo = (string)($ref['repo'] ?? '');
  $tag  = (string)($ref['tag']  ?? '');
  if ($repo === '') { $why = 'unsupported'; return []; }

  $triedHttp = false;
  if (!staxx_update_host_blocked($host)) {
    $triedHttp = true;
    $result = staxx_registry_remote_http($host, $repo, $tag, $image, $why, $tags, $prev);
    // 'auth' is the one answer that means "try the CLI instead", because the
    // daemon may hold credentials this box's own token handshake does not.
    // Every other answer here — including a plain network failure — is this
    // route's final word: retrying the same question through a different
    // door would not change what the registry just said.
    if ($why !== 'auth') return $result;
    staxx_update_host_note($host, ['http' => false, 'at' => time()]);
  }

  // Fallback — the docker CLI, using whichever subcommand this box actually
  // has. 'hub' means neither exists, i.e. no CLI route at all: HTTP was this
  // image's only chance, and it has already been spent above.
  $route = staxx_docker_inspector();
  if ($route !== 'imagetools' && $route !== 'manifest') {
    $why = 'unsupported';
    return [];
  }

  $why = '';
  $refArg = escapeshellarg($image);

  if ($route === 'imagetools') {
    // Docker prints a 429 refusal to stderr, and staxx_sh() throws stderr away
    // (2>/dev/null on the outer shell) — merge it into what staxx_sh() returns
    // so staxx_remote_failure_reason() actually sees the words "Too Many
    // Requests" instead of an empty string. This inner redirect lands on the
    // inner shell's own stdout, so the outer discard never touches it.
    $out = staxx_sh(
      staxx_docker_bin().' buildx imagetools inspect '.$refArg
        .' --format '.escapeshellarg('{"manifest":{{json .Manifest}},"image":{{json .Image}}}')
        .' 2>&1',
      20
    );
    $data = json_decode($out, true);
    if (!is_array($data)) { $why = staxx_remote_failure_reason($out, $image, $tags); return []; }

    $digest = (string)($data['manifest']['digest'] ?? '');
    if ($digest === '') { $why = staxx_remote_failure_reason($out, $image, $tags); return []; }

    $imageObj = $data['image'] ?? null;
    $config   = [];
    if (is_array($imageObj)) {
      // A multi-platform reference answers with a map keyed by platform
      // string ("linux/amd64" => {...}) rather than one config object —
      // prefer linux/amd64, since that is what every other route here reports
      // against, and fall back to whatever is first when it is missing.
      if (isset($imageObj['config']) || isset($imageObj['Labels'])) {
        $config = $imageObj;
      } elseif (isset($imageObj['linux/amd64']) && is_array($imageObj['linux/amd64'])) {
        $config = $imageObj['linux/amd64'];
      } else {
        $first = reset($imageObj);
        if (is_array($first)) $config = $first;
      }
    }

    $labels = $config['config']['Labels'] ?? ($config['Labels'] ?? []);
    $labels = is_array($labels) ? $labels : [];

    return ['digest' => $digest, 'spent' => ['kind' => 'cli']] + staxx_update_labels_meta($labels);
  }

  // Only 'manifest' left — imagetools was handled and returned above, and
  // the guard just before this block already refused anything else.
  // 2>&1 here for the same reason as the imagetools route above.
  $out  = staxx_sh(staxx_docker_bin().' manifest inspect --verbose '.$refArg.' 2>&1', 20);
  $data = json_decode($out, true);
  if (!is_array($data)) { $why = staxx_remote_failure_reason($out, $image, $tags); return []; }

  // A multi-architecture index decodes to a JSON list, not an object — that
  // reply has no index digest in it at all, only one Descriptor per
  // architecture, and reporting one of those as THE digest would compare a
  // single-arch fingerprint against a multi-arch RepoDigests entry and
  // invent a phantom update. So a list is refused outright rather than
  // guessed at — see PLAN_45's digest-comparison risk. Permanent for this
  // image on this route, so it is 'unsupported' rather than a transient fail.
  if (staxx_array_is_list($data)) { $why = 'unsupported'; return []; }

  $digest = (string)($data['Descriptor']['digest'] ?? '');
  if ($digest === '') { $why = staxx_remote_failure_reason($out, $image, $tags); return []; }

  // No labels available on this route.
  return ['digest' => $digest, 'spent' => ['kind' => 'cli']];
}

/**
 * PLAN_90 Stage 1/2 — the direct HTTP half of staxx_image_remote(): one
 * conditional manifest request, and only when that actually returns a fresh
 * body does it go on to ask for labels. Split out of staxx_image_remote()
 * itself only so that function's CLI-fallback shape does not have to nest
 * around it — this is not meant to be called from anywhere else.
 *
 * Step zero of PLAN_90 measured that Docker Hub counts a 304 exactly like a
 * 200, so this saves no requests against Hub's own ceiling — what it saves
 * is the label chain: a `304` genuinely cannot carry a body, so there is
 * nothing to read labels from, and the three requests that used to follow
 * every digest check (token, index manifest, config blob) are skipped
 * entirely. That is the whole saving, and it is real: two thirds of the
 * counted requests per unchanged image, gone.
 */
function staxx_registry_remote_http(
  string $host, string $repo, string $tag, string $image,
  string &$why, ?array &$tags, array $prev
): array {
  $why = '';

  $tokenWhy = '';
  $token = staxx_registry_token($host, $repo, $tokenWhy);
  if ($token === '') { $why = $tokenWhy !== '' ? $tokenWhy : 'failed'; return []; }

  // Only trust a stored entity tag while it was recorded against the same
  // Accept list this ask is about to send — a changed list would otherwise
  // compare a stored tag against a manifest shape it was never validated
  // against, and a mismatched 304 would carry over the wrong digest.
  $acceptId = staxx_registry_accept_id();
  $etag = ($prev['accept'] ?? '') === $acceptId ? (string)($prev['etag'] ?? '') : '';
  // ...and only while the last full, unconditional ask is recent enough. See
  // STAXX_UPDATE_FRESH_EVERY: this is what stops a registry with a broken
  // entity tag hiding a real update behind an endless run of 304s.
  if ($etag !== '' && (time() - (int)($prev['fresh'] ?? 0)) > STAXX_UPDATE_FRESH_EVERY) {
    $etag = '';
  }

  $digestWhy = '';
  $result = staxx_registry_digest($host, $repo, $tag, $etag, $digestWhy);
  $status = (int)($result['status'] ?? 0);

  if ($status === 304) {
    staxx_update_host_note($host, ['saw304' => true]);
    // A 304 carries no 'charged' key at all — staxx_registry_digest() only
    // sets it on a 200 — so the price is read off the same memo that
    // decided which form was actually sent: free while the header-only
    // form still works here, paid on a host that has fallen back to a full
    // GET for everything.
    $kind = staxx_registry_headfree($host) ? 'free' : 'paid';
    return ['unchanged' => true, 'spent' => ['kind' => $kind]];
  }

  if ($status !== 200) {
    $why = $digestWhy !== '' ? $digestWhy : 'failed';
    // A plain 404 is ambiguous between "gone for good" and "the tag itself
    // moved" — the same distinction staxx_remote_failure_reason() already
    // draws for the CLI routes, reused here rather than duplicated so a
    // withdrawn tag tells the same better story on every route.
    if ($why === 'notfound' && $image !== '') {
      $registryTags = staxx_registry_tags($image);
      if ($registryTags !== [] && !in_array(staxx_image_tag_part($image), $registryTags, true)) {
        $tags = $registryTags;
        $why = 'tagmissing';
      }
    }
    return [];
  }

  $digest = (string)($result['digest'] ?? '');
  if ($digest === '') { $why = 'failed'; return []; }

  // The registry answered fresh (either it does not honour If-None-Match, or
  // there was nothing stored yet to send) but the digest is exactly what was
  // already on record — nothing has actually moved, so there is nothing for
  // a label re-fetch to have changed either. Treated as the plain digest+etag
  // answer below, just without spending anything on labels no one asked for.
  $prevRemote = (string)($prev['remote'] ?? '');
  // 'fresh' true means this answer came from a full ask with no stored
  // entity tag sent — the caller stamps the clock that governs when the next
  // one is due.
  $out = ['digest' => $digest, 'etag' => (string)($result['etag'] ?? ''), 'accept' => $acceptId,
          'fresh' => $etag === '',
          'spent' => ['kind' => ($result['charged'] ?? false) ? 'paid' : 'free']];
  if (isset($result['limit'])) $out['limit'] = $result['limit'];
  if ($prevRemote !== '' && $prevRemote === $digest) return $out;

  // The digest has genuinely moved, so the version labels are worth the
  // extra requests — and they have to be asked for separately, because
  // staxx_registry_digest() is a header-only question and deliberately never
  // walks index→manifest→config-blob itself. This is the chain Stage 2 exists
  // to SKIP on every unchanged image; reaching it means something changed.
  //
  // staxx_registry_labels() asks the SAME registry the digest above came
  // from — host, repo and tag, no rewriting. staxx_registry_config() is
  // Docker Hub's own path and would answer a ghcr/lscr image's labels from
  // Hub, and every other host's not at all; that was fine for the form
  // editor, which only ever wants Hub's opinion, but wrong here, where a
  // compose file naming ghcr must never be answered by Hub about anything.
  $labels = staxx_registry_labels($host, $repo, $tag);
  // The index and manifest fetches this walk makes are charged; the config
  // blob at the end of it is not — measured 2026-09-02, same pass as the
  // header-only form itself.
  $out['spent']['extraPaid'] = 2;
  return $out + staxx_update_labels_meta($labels);
}

/**
 * array_is_list() needs PHP 8.1; Unraid 7.2 ships 8.x but this keeps the
 * check working even a minor version earlier than that promises.
 */
function staxx_array_is_list(array $arr): bool {
  return function_exists('array_is_list') ? array_is_list($arr) : $arr === array_values($arr);
}

/**
 * Sorts `docker image inspect`'s raw output into what it actually said,
 * pulled out as its own pure function so the two very different reasons for
 * an empty answer can be told apart without a live docker call: a decoded
 * JSON object means the image is on disk; "No such image" (the daemon's own
 * wording, case-insensitive) means it genuinely is not; anything else —
 * empty output, a socket error, a timeout — means the question itself
 * failed and nothing has been learned either way.
 */
function staxx_image_local_verdict(string $out): array {
  $data = json_decode($out, true);
  if (is_array($data)) return $data;
  if (stripos($out, 'No such image') !== false) return [];
  return ['unknown' => true];
}

/**
 * What is actually sitting on disk for one image reference — the digest
 * Docker recorded when it pulled it, comparable to staxx_image_remote()'s
 * digest with no conversion either side.
 *
 * Not present locally → []. Present but with no RepoDigests (built here or
 * side-loaded, never pulled) → ['built' => true] with no digest, so the
 * caller does not mistake a locally-built image for one that is up to date.
 * The inspect call itself failed — socket down, timeout, garbage back —
 * → ['unknown' => true]: unlike [], this is not an answer, and callers that
 * treat an absent digest as "nothing to compare" already handle it the same
 * as [] without any change, but staxx_update_check() treats the two apart.
 */
function staxx_image_local(string $image): array {
  // 2>&1 here for the same reason as the imagetools route above.
  $out = staxx_sh(
    staxx_docker_bin().' image inspect '.escapeshellarg($image).' --format '.escapeshellarg('{{json .}}').' 2>&1',
    15
  );
  $data = staxx_image_local_verdict($out);
  if ($data === [] || !empty($data['unknown'])) return $data;

  $repo = staxx_hub_repo_path($image);
  // The reference's own repository half, used to pick the matching
  // RepoDigests entry — a locally cached image can hold digests from more
  // than one tag/repo alias.
  $wantRepo = $repo !== '' ? $repo : preg_replace('/:[^\/]*$/', '', trim($image));

  $digest = '';
  foreach ((array)($data['RepoDigests'] ?? []) as $entry) {
    $at = strrpos((string)$entry, '@');
    if ($at === false) continue;
    $entryRepo = substr((string)$entry, 0, $at);
    if ($entryRepo === $wantRepo || staxx_hub_repo_path($entryRepo) === $wantRepo) {
      $digest = substr((string)$entry, $at + 1);
      break;
    }
  }

  if ($digest === '') {
    if (empty($data['RepoDigests'])) return ['built' => true];
    return [];
  }

  $labels = $data['Config']['Labels'] ?? [];
  $labels = is_array($labels) ? $labels : [];

  $result = ['digest' => $digest] + staxx_update_labels_meta($labels);
  // staxx_update_labels_meta() reads 'created' from a label; fall back to the
  // image's own Created field when no label supplied one.
  if (!isset($result['created']) && !empty($data['Created'])) {
    $ts = strtotime((string)$data['Created']);
    if ($ts !== false) $result['created'] = $ts;
  }
  return $result;
}

/**
 * Every distinct image reference in scope, mapped to the stack::service rows
 * that use it. Collecting distinct images first is the point of decision 4 —
 * three stacks sharing an image must cost one registry question, not three.
 *
 * @param string $scope 'all', a folder name, or one stack's path
 * @return array<string, string[]>
 */
function staxx_update_images(string $scope): array {
  $images = [];

  foreach (staxx_list_stacks() as $stack) {
    if ($scope === 'all') {
      // every stack
    } elseif ($stack['name'] === $scope) {
      // exact stack match
    } elseif (strpos($stack['name'], $scope.'/') === 0) {
      // folder match
    } else {
      continue;
    }

    if ($stack['file'] === '') continue;
    $meta = staxx_compose_meta($stack['file']);
    if (!$meta['ok']) continue;

    foreach ($meta['services'] as $svc => $svcMeta) {
      $image = trim((string)($svcMeta['image'] ?? ''));
      if ($image === '') continue;
      $images[$image][] = $stack['name'].'::'.$svc;
    }
  }

  return $images;
}

/**
 * Stack name -> compose file path, for every stack — built once per pass so
 * PLAN_62 Stage 2 can hand staxx_watch_compare() a real local file for every
 * stack that uses a watched image, without re-walking staxx_list_stacks()
 * once per stack.
 *
 * @return array<string, string>
 */
function staxx_update_stack_files(): array {
  $files = [];
  foreach (staxx_list_stacks() as $stack) {
    if ($stack['file'] !== '') $files[$stack['name']] = $stack['file'];
  }
  return $files;
}

/**
 * Take the check pass's lock. An atomic mkdir either succeeds or fails
 * outright — no race window either way, the same trick the stats collector
 * uses. A lock directory older than 30 minutes is treated as abandoned by a
 * pass that was killed rather than one that finished cleanly, and may be
 * taken over.
 */
function staxx_update_lock(string &$error): bool {
  $error = '';
  if (!is_dir(STAXX_UPDATE_DIR) && !@mkdir(STAXX_UPDATE_DIR, 0755, true)) {
    $error = 'Could not create '.STAXX_UPDATE_DIR;
    return false;
  }

  $lock = STAXX_UPDATE_DIR.'/lock';
  if (@mkdir($lock, 0755)) return true;

  $age = is_dir($lock) ? (time() - (int)@filemtime($lock)) : 0;
  if ($age > 1800) {
    @rmdir($lock);
    if (@mkdir($lock, 0755)) return true;
  }

  $error = 'An update check is already running.';
  return false;
}

function staxx_update_unlock(): void {
  @rmdir(STAXX_UPDATE_DIR.'/lock');
}

/**
 * Work out the truth again for one stack's images after a job has just
 * changed it — a pull, an update, a rebuild, a rollback. First re-reads what
 * is actually on disk, same as before. But a corrected local digest can
 * still disagree with a REMEMBERED registry answer that has since gone
 * stale — the tag moved, or moved back, since the last six-hourly check —
 * and leaving that half untouched is how "update ready" survives installing
 * the update. So where the two still disagree after the local half is
 * fixed, the registry is asked again (capped, see below) and folded in the
 * same way the check pass does.
 *
 * @return bool whether anything was actually corrected
 */
function staxx_update_refresh_after_run(string $stack, string $service = ''): bool {
  if (!staxx_valid_path($stack)) return false;

  $file = staxx_find_compose_file(staxx_stack_dir($stack));
  if ($file === '') return false;
  $meta = staxx_compose_meta($file);
  if (!$meta['ok']) return false;

  // A check pass already holds the whole images map in memory and will
  // write it back wholesale, so a write under it would just be overwritten.
  // Skipped rather than queued: that pass reads the local digest itself and
  // so arrives at the same answer anyway.
  $lockError = '';
  if (!staxx_update_lock($lockError)) return false;

  $images = (array)staxx_update_state()['images'];
  $dirty  = false;

  foreach ($meta['services'] as $svc => $svcMeta) {
    if ($service !== '' && $svc !== $service) continue;

    $image = trim((string)($svcMeta['image'] ?? ''));
    // An image no check pass has ever recorded is left alone — with no
    // registry digest to compare against, a local one on its own says
    // nothing, and inventing an entry here would only look like an answer.
    if ($image === '' || !isset($images[$image])) continue;

    $local = staxx_image_local($image);
    if (empty($local['digest'])) continue; // built here, or not installed — nothing to correct

    $entry = $images[$image];
    if (($entry['local'] ?? '') === $local['digest']) continue;

    $entry['local'] = $local['digest'];
    // Both of these are statements about what is on disk, and what is on
    // disk has just been re-measured — an image that was absent or built
    // here has plainly been pulled since. Every other remembered error is a
    // statement about the registry and is none of this function's business.
    if (($entry['error'] ?? '') === 'not installed') {
      unset($entry['error']);
      // The 'not installed' short-circuit stamps 'asked'/'nextDue' so the
      // ask itself is skipped while the image stays absent — but the image
      // has plainly just appeared, so that cadence is stale the moment it
      // is cleared. Left in place, the next check pass would wait out
      // whatever interval was stamped while there was nothing to ask about,
      // instead of asking the registry promptly now there is something to
      // compare against.
      unset($entry['asked'], $entry['nextDue']);
    }
    if (!empty($entry['built'])) unset($entry['built'], $entry['error']);
    // Up to date now, so the countdown running against that version has
    // nothing left to fire on — cleared by the same four keys
    // staxx_update_check() clears, so the two can never disagree.
    if (($entry['remote'] ?? '') === $local['digest']) {
      unset($entry['seen'], $entry['was'], $entry['wasCreated'], $entry['seenDigest']);
    }

    $images[$image] = $entry;
    $dirty = true;
  }

  // Second pass: still disagreeing after the local digest above was fixed
  // means the REGISTRY half may be the stale one. Left alone, an update that
  // plainly succeeded keeps reading as pending until the next six-hourly
  // check pass. Capped at 4 re-asks per call — each is a network round trip
  // with its own 20-second ceiling, and the page is waiting on this request,
  // so a stack with a dozen services must not turn one click into a
  // two-minute page load.
  $toReask = [];
  foreach ($meta['services'] as $svc => $svcMeta) {
    if ($service !== '' && $svc !== $service) continue;

    $image = trim((string)($svcMeta['image'] ?? ''));
    if ($image === '' || !isset($images[$image]) || isset($toReask[$image])) continue;

    $entry  = $images[$image];
    $local  = (string)($entry['local'] ?? '');
    $remote = (string)($entry['remote'] ?? '');
    if ($local === '' || $remote === '' || $local === $remote) continue;

    $toReask[$image] = true;
  }

  $reasked = 0;
  foreach (array_keys($toReask) as $image) {
    if ($reasked >= 4) break;
    $reasked++;

    $why   = '';
    $freshTags = null;
    $entry = $images[$image];
    $fresh = staxx_image_remote($image, $why, $freshTags, $entry);
    // A registry that could not be reached leaves the previous answer
    // standing — nothing invented, nothing cleared, no new error recorded
    // here; staxx_update_check() already owns error reporting for that.
    if ($fresh === []) continue;

    // A 304 confirms the remote digest already on record is still current —
    // the disagreement this loop exists to chase is real, not stale data, so
    // there is nothing to fix here. Still worth stamping 'asked': the check
    // pass's own cadence counts from this answer, not from whenever it last
    // happened to ask.
    if (!empty($fresh['unchanged'])) {
      $entry['asked'] = time();
      $images[$image] = $entry;
      $dirty = true;
      continue;
    }

    $entry['remote'] = $fresh['digest'] ?? '';
    if (isset($fresh['etag']))    $entry['etag']    = $fresh['etag'];
    if (isset($fresh['accept']))  $entry['accept']  = $fresh['accept'];
    if (!empty($fresh['fresh']))  $entry['fresh']   = time();
    if (isset($fresh['version'])) $entry['version'] = $fresh['version'];
    if (isset($fresh['source']))  $entry['source']  = $fresh['source'];
    if (isset($fresh['created'])) $entry['created'] = $fresh['created'];
    // Stamped so the check pass's own six-hour TTL counts from this answer,
    // not from whenever it last happened to ask.
    $entry['asked'] = time();

    // Up to date now — cleared by the same four keys the first pass and
    // staxx_update_check() clear, so the countdown cannot outlive the
    // thing it was counting towards.
    if (($entry['local'] ?? '') === $entry['remote']) {
      unset($entry['seen'], $entry['was'], $entry['wasCreated'], $entry['seenDigest']);
    }

    $images[$image] = $entry;
    $dirty = true;
  }

  if ($dirty) staxx_update_state_save(['images' => $images]);
  staxx_update_unlock();
  return $dirty;
}

/**
 * Absolute path to the php binary, same reasoning as staxx_docker_bin(): PHP's
 * environment is not a login shell, so PATH cannot be relied on.
 */
function staxx_php_bin(): string {
  static $bin = null;
  if ($bin !== null) return $bin;
  foreach (['/usr/bin/php', '/usr/local/bin/php'] as $path) {
    if (is_file($path) && is_executable($path)) return $bin = $path;
  }
  return $bin = 'php';
}

/**
 * The whole check pass: ask the registry about every distinct image in
 * scope, one at a time, and fold the answers into the state file.
 *
 * @return array{asked:int, skipped:int, updates:int, failed:int, built:int, missing:int, tagmissing:int, unchecked:int, pinned:int, unchanged:int, ok:bool, error:string, limited:bool}
 */
function staxx_update_check(string $scope, bool $force): array {
  $result = ['asked' => 0, 'skipped' => 0, 'updates' => 0, 'failed' => 0, 'built' => 0, 'missing' => 0, 'tagmissing' => 0, 'unchecked' => 0, 'pinned' => 0, 'unchanged' => 0, 'ok' => true, 'error' => '', 'limited' => false];

  $lockError = '';
  if (!staxx_update_lock($lockError)) {
    $result['ok'] = false;
    $result['error'] = $lockError;
    return $result;
  }
  // Backstop only, for a pass that is killed outright — the normal path is
  // the explicit staxx_update_unlock() call before every successful return
  // below. Registered once per process; the same handler twice is pointless.
  static $registered = false;
  if (!$registered) {
    register_shutdown_function('staxx_update_unlock');
    $registered = true;
  }

  $state  = staxx_update_state();
  $images = (array)$state['images'];
  $rebuilds = (array)$state['rebuilds'];
  // PLAN_62 Stage 2 findings, keyed by stack — see staxx_update_state_defaults().
  $stacksState = (array)$state['stacks'];
  // PLAN_112 Phase B — the spend ledger, updated as this pass asks and read
  // before every charged ask to decide whether this host may still be paid.
  $spend  = (array)($state['spend'] ?? []);
  // PLAN_112 Phase C — staxx_registry_headfree()'s memory is a static array,
  // reset every process, but the ledger itself remembers a host's last
  // known answer across passes. Without seeding it back in here, every pass
  // would re-probe a host that has already proved it refuses the free form,
  // and the guard rail just below — which only ever consults hosts whose
  // memory reads false — could never bite a second time.
  foreach ($spend as $host => $spendEntry) {
    if (isset($spendEntry['headfree']) && !$spendEntry['headfree']) {
      staxx_registry_headfree($host, false);
    }
  }
  $now    = time();
  $failedNames = [];
  $newlyFound = 0; // images whose 'seen' clock started fresh THIS pass, for the "found" notification

  $refs = staxx_update_images($scope);
  // PLAN_62 Stage 2 — a real file on disk to compare the author's example
  // against, keyed by stack name so the loop below can look one up per image
  // from $rows without re-walking every stack's folder each time.
  $stackFiles = staxx_update_stack_files();

  // Ask the least recently asked first. staxx_update_images() returns the
  // same order every time (disk order), and the rate ceiling always cuts
  // the pass off at the same point in that order — so without this, the
  // images past the cut are never asked at all, no matter how many passes
  // run. An image with no 'asked' yet counts as older than any that has
  // been asked, so brand-new images are not left behind either.
  uksort($refs, function ($a, $b) use ($images) {
    $aAsked = (int)($images[$a]['asked'] ?? 0);
    $bAsked = (int)($images[$b]['asked'] ?? 0);
    return $aAsked <=> $bAsked;
  });

  $first = true;
  $limited = false;
  // PLAN_62 Stage 1 — GitHub's unauthenticated ceiling (60/hour) is a
  // separate counter from Docker Hub's, shared by nothing else on this box,
  // but a forced recheck of every image in one pass must still stop well
  // short of it rather than retrying into a wall. Spent by staxx_watch_check()
  // below, never reset mid-pass.
  $watchBudget = 20;
  $limitedRegistries = [];
  $unchecked = [];
  // PLAN_90's stampede guard. An empty record with stacks still on disk is
  // what a data folder that was not mounted at boot looks like: every image
  // reads as never-asked at once. Ask a handful and leave the rest entirely
  // alone — no state, no 'asked' stamp — so the next pass reaches them
  // first, exactly as the rate stand-down below already relies on. A forced
  // pass is the user asking on purpose and is never capped.
  $rebuildCap = (!$force && $images === [] && count($refs) > STAXX_UPDATE_REBUILD_CAP)
    ? STAXX_UPDATE_REBUILD_CAP : 0;
  if ($rebuildCap > 0) {
    echo 'nothing on record for '.count($refs).' images — asking '.$rebuildCap
       . " this pass and the rest on the next
";
  }
  // PLAN_90 Stage 1 — the HTTP route now asks each image's own registry
  // directly (no more funnelling everything through Hub), so the host that
  // actually answers is always the one the reference names.
  foreach ($refs as $image => $rows) {
    $existing = $images[$image] ?? [];

    // PLAN_90 Stage 3 — the flat six-hour TTL is now a computed interval:
    // a version-numbered tag waits a week, a rolling one still asks every
    // six hours, and an image that keeps actually changing is asked sooner
    // than one that has not moved in months. staxx_update_cadence() is pure,
    // so recomputing it here every pass costs nothing and self-heals if the
    // entry it reads (moves, error, fails) has changed since the last ask.
    if (!$force && ($existing['asked'] ?? 0) > 0
        && ($now - (int)$existing['asked']) < staxx_update_cadence($image, $existing)) {
      $result['skipped']++;
      continue;
    }

    // The stampede guard, spending only real asks: a digest-pinned image
    // costs nothing and is not counted against it.
    if ($rebuildCap > 0 && $result['asked'] >= $rebuildCap) {
      $result['unchecked']++;
      $unchecked[] = $image;
      continue;
    }

    $registry = staxx_image_registry($image);
    // This registry has already refused once this pass, so asking again would
    // only spend an allowance that is gone — and every other registry carries
    // on being asked, which is the whole point. Left entirely alone: no state
    // written and no 'asked' stamp, so the next pass reaches these first.
    if (isset($limitedRegistries[$registry])) {
      $result['unchecked']++;
      $unchecked[] = $image;
      continue;
    }

    // The gap only softens bursts — Docker Hub's ceiling is per hour, not per
    // second, so it is the remembered answer and the stop-below that actually
    // protect the allowance, not this pause.
    if (!$first) usleep(1500000);
    $first = false;

    // PLAN_61 — has this catalogue app's template moved registries? Unset
    // first so a fact that stops being true (the repo left the catalogue, or
    // the two addresses converged) disappears rather than lingering from a
    // stale pass. Guarded by function_exists() because Links.php requires
    // this file, so the reverse would be a cycle — see staxx_rebuild_due()'s
    // own guard just below for the same reason. The out-of-band spawns that
    // actually run this pass (staxx_update_check_start() below, and
    // scripts/update-check) require Links.php directly, so the guard is
    // never false there; only a stray caller that requires Updates.php alone
    // would see it skipped, and no such caller exists.
    unset($existing['move']);
    if (function_exists('staxx_links_move_candidate')) {
      $candidate = staxx_links_move_candidate($image);
      if ($candidate !== []) {
        $tagInUse = staxx_image_tag_part($image);
        $newTags  = staxx_registry_tags($candidate['image'].':'.$tagInUse);
        // Proved, not assumed: an offer that 404s at the new address is worse
        // than no offer at all, so the fact is only kept when the tag this
        // service is actually running is confirmed present there.
        if (in_array($tagInUse, $newTags, true)) {
          // staxx_registry_tags() answers newest-first only when it routes
          // through Hub's own API (a bare name, or the lscr.io/ghcr.io
          // linuxserver mirrors) — everything else, this new address
          // included, answers lexically. staxx_tag_suggestions()'s backfill
          // walks candidates in the order given, so passing it lexical order
          // here would offer the three OLDEST tags for anything date- or
          // hash-tagged. Reversed here, at this call site only — the
          // withdrawn-tag path must keep behaving exactly as it does today.
          $ordered = staxx_hub_repo_path($candidate['image']) !== '' ? $newTags : array_reverse($newTags);
          // '' as $missing: nothing is being replaced here, so nothing should
          // be excluded — the tag in use is welcome to be the top pick, which
          // is exactly right when it is itself a rolling tag like "latest".
          //
          // Named as Docker Hub specifically when that is the old address —
          // staxx_links_image_host() folds a written host down to '' for Hub
          // and its two aliases, so '' here means Hub, not "unknown host".
          $oldHostName = staxx_links_image_host($image) === '' ? 'Docker Hub' : 'the old address';
          $existing['move'] = [
            'host'   => $candidate['host'],
            'tag'    => $tagInUse,
            'tags'   => staxx_tag_suggestions('', $ordered),
            'reason' => 'The template for this app now publishes at '.$candidate['host'].'. '
                      . ucfirst($oldHostName).' still answers, but it is no longer where updates '
                      . 'are pushed.',
          ];
        }
      }
    }

    // PLAN_62 Stage 1 — does this image's own publisher hold up a compose
    // example worth comparing against later? Guarded by function_exists()
    // for the same reason as the move check above: Watch.php requires
    // Links.php, which requires this file, so the reverse would be a cycle.
    // Both out-of-band spawn sites that actually run this pass —
    // staxx_update_check_start() below, and scripts/update-check — require
    // Watch.php directly, so the guard is never false where it matters; see
    // PLAN_61's own note just above for why that distinction is load-bearing.
    if (staxx_cfg_bool('WATCH_EXAMPLES') && function_exists('staxx_watch_check') && $watchBudget > 0) {
      $spent = 0;
      // Discovery and the fetch are a property of the image's own upstream
      // project, never of any one stack — this costs the same one GitHub
      // question whichever, or however many, stacks use $image.
      $watch = staxx_watch_check($image, (array)($existing['watch'] ?? []), $spent);
      $watchBudget -= $spent;
      if ($watch === []) {
        unset($existing['watch']);
      } else {
        $existing['watch'] = $watch;
      }

      // The comparison is the opposite: local, free, and belongs to each
      // stack's own file. Run it per stack rather than once for whichever
      // stack happened to be first — that "stands in for all of them"
      // shortcut is the exact bug this loop used to have (PLAN_62's
      // correction): a second stack sharing the image would be shown
      // findings computed against the first stack's file. Only runs when a
      // body was actually cached to compare against.
      if (function_exists('staxx_watch_compare') && ($watch['body_saved'] ?? false)) {
        $stackNames = [];
        foreach ($rows as $holder) $stackNames[explode('::', $holder, 2)[0]] = true;
        foreach (array_keys($stackNames) as $stackName) {
          $localFile = $stackFiles[$stackName] ?? '';
          if ($localFile === '') continue;
          $compare = staxx_watch_compare($localFile, $image);
          $stacksState[$stackName]['watch'][$image] = $compare;
        }
      }
    }

    $why    = '';
    $tags   = null;
    $local  = staxx_image_local($image);

    // Genuinely absent — never the 'unknown' shape, which means the
    // question itself failed rather than answering "no". With no local
    // digest, no answer from the registry could change anything, so the
    // ask is skipped and
    // that skip is stamped like any other, the same shape as the
    // digest-pinned short-circuit just below. Without this every image
    // nobody has installed was asked about on every single pass, for an
    // answer thrown away a few lines down, and never earned a cadence of
    // its own.
    if ($local === []) {
      $existing['error'] = 'not installed';
      unset($existing['local']);
      $existing['asked'] = $now;
      $existing['nextDue'] = $now + staxx_update_cadence($image, $existing);
      $images[$image] = $existing;
      $result['missing']++;
      echo $image." — not installed here, not asked\n";
      continue;
    }

    // repo:tag@sha256:<digest> names one exact build — the registry can only
    // ever answer with the digest already written in the reference, so
    // asking is guaranteed waste, and on a limited allowance that waste is
    // what stops a real image being checked.
    if (preg_match('/@(sha256:[0-9a-f]{64})$/', $image, $pm)) {
      $remote = ['digest' => $pm[1]];
      foreach (['version', 'source', 'created'] as $carry) {
        if (isset($local[$carry])) $remote[$carry] = $local[$carry];
      }
      $result['pinned']++;
    } elseif (!staxx_registry_headfree($registry)
           && !staxx_spend_may_pay($spend[$registry] ?? [], $registry, $now)) {
      // PLAN_112 Phase B's guard rail. Only reached once a host has fallen
      // back to the charged form everywhere — the free header-only form
      // never consults this at all. StaXX must never be the reason a real
      // pull the user makes is refused, so it stops asking a charged host
      // once its own half of that host's allowance is spent, and leaves the
      // rest of this pass's images against it for next time, exactly like
      // the per-registry stand-down above.
      $result['unchecked']++;
      $unchecked[] = $image;
      echo $image.' — '.$registry.' only answers the charged way here now, and StaXX is keeping '
         . "half its allowance free for you; asking again next pass\n";
      continue;
    } else {
      $remote = staxx_image_remote($image, $why, $tags, $existing);
      $result['asked']++;
      if (isset($remote['spent']) && is_array($remote['spent'])) {
        $spentEvent = $remote['spent'];
        $spend = staxx_spend_record($spend, $registry, [
          'kind'     => $spentEvent['kind'] ?? 'free',
          'limit'    => $remote['limit'] ?? null,
          'headfree' => staxx_registry_headfree($registry),
        ], $now);
        // The label walk this ask triggered is charged twice more — record
        // those as their own paid events rather than folding them into the
        // one above, so the hourly count reflects what was actually asked.
        for ($extra = (int)($spentEvent['extraPaid'] ?? 0); $extra > 0; $extra--) {
          $spend = staxx_spend_record($spend, $registry, ['kind' => 'paid'], $now);
        }
      }
    }

    // PLAN_90 Stage 2 — the registry confirmed nothing has moved. 'asked' and
    // the computed cadence are the only things this touches: remote, version,
    // created and every countdown key stay exactly as they were, because an
    // unmoved image cannot have changed labels either — and re-deriving the
    // local-vs-remote comparison here, on a path with no fresh digest to
    // compare, is exactly the kind of thing that would restart a 'seen'
    // countdown that must only ever start once per real update.
    if (!empty($remote['unchanged'])) {
      $storedRemote = (string)($existing['remote'] ?? '');
      // The shortcut is only honest while the LOCAL side is also where it was
      // left. A 304 says the registry has not moved; it says nothing about an
      // image that has since been removed, rebuilt, or pulled by hand, and
      // taking the cheap path anyway would leave the row claiming to be up to
      // date with nothing installed.
      $localSame = $local !== [] && empty($local['built'])
                && (string)($local['digest'] ?? '') === (string)($existing['local'] ?? '');

      if ($storedRemote !== '' && $localSame) {
        unset($existing['error']);
        $existing['fails'] = 0;
        unset($existing['failedSince']);
        $existing['asked'] = $now;
        $existing['nextDue'] = $now + staxx_update_cadence($image, $existing);
        $images[$image] = $existing;
        $result['unchanged']++;
        echo $image." — unchanged since last check\n";
        continue;
      }

      // Something on this box has changed even though the registry has not.
      // A 304 still tells us the remote digest is exactly the one already on
      // record, so carry it forward — with the labels it was recorded
      // alongside — and let the ordinary comparison below run properly
      // rather than reporting a stale answer.
      if ($storedRemote === '') {
        // A 304 with nothing stored to stand on should be impossible: the
        // entity tag that earned it came from a stored answer. Refuse rather
        // than invent a digest.
        $remote = [];
        $why = 'failed';
      } else {
        $remote = ['digest' => $storedRemote];
        foreach (['version', 'source', 'created'] as $carry) {
          if (isset($existing[$carry])) $remote[$carry] = $existing[$carry];
        }
      }
    }

    if ($remote === [] && $why === 'limited') {
      // Only this registry stands down for the rest of the pass — everything
      // asked of a different registry keeps going.
      $existing['error'] = 'rate limited';
      $images[$image] = $existing;
      $limited = true;
      $limitedRegistries[$registry] = true;
      $spend = staxx_spend_record($spend, $registry, ['kind' => 'refused'], $now);
      echo $image.' — '.$registry." is limiting how often this server may ask; "
         . "skipping the rest of its images this pass\n";
      continue;
    }

    if ($remote === []) {
      $message = $why === 'unsupported' ? 'cannot be checked here'
        : ($why === 'tagmissing' ? 'tag withdrawn'
        : ($why === 'notfound' ? 'not found in the registry' : 'could not check'));
      $existing['error'] = $message;

      if ($why === 'tagmissing') {
        // Store the filtered shortlist, not the registry's raw tag list: that
        // list can run to fifty entries, gets written to the flash drive
        // every pass, and would include the very test-build tags
        // staxx_tag_suggestions() exists to exclude — never something a menu
        // should offer back. 'suggest' is always this list's first entry.
        $suggestions = staxx_tag_suggestions(staxx_image_tag_part($image), (array)$tags);
        $existing['tags']    = $suggestions;
        $existing['suggest'] = $suggestions[0] ?? '';
      } else {
        unset($existing['tags'], $existing['suggest']);
      }

      // A withdrawn tag, a missing repository, or an image this box simply
      // cannot check will not fix themselves between now and the next pass —
      // stamp 'asked' so each honours the same remembered-answer window a
      // successful check gets, instead of being re-asked every single pass
      // for ever. 'unsupported' joins this list under PLAN_90 — previously
      // left unstamped and so re-asked forever, which is pure waste for a
      // permanent answer. 'failed' and 'limited' are transient and must keep
      // retrying promptly, so they are left unstamped.
      $stamped = $why === 'notfound' || $why === 'tagmissing' || $why === 'unsupported';
      if ($stamped) $existing['asked'] = $now;

      // PLAN_90 Stage 3b — only the transient-or-unknown 'failed' reason
      // counts toward the consecutive-failure notice. 'notfound' and
      // 'unsupported' are their own permanent, immediate answers (see
      // staxx_updates_pill_for_image()) and gain nothing from a count;
      // 'tagmissing' already tells a better story via its tag shortlist.
      if ($why === 'failed') {
        $existing['fails'] = (int)($existing['fails'] ?? 0) + 1;
        if (empty($existing['failedSince'])) $existing['failedSince'] = $now;
      }

      // Only when 'asked' was actually stamped just now — a due time must
      // track the ask it was computed for, not linger from an older one.
      if ($stamped) $existing['nextDue'] = $now + staxx_update_cadence($image, $existing);

      $images[$image] = $existing;

      // A withdrawn tag is a definite, permanent answer — the check itself
      // succeeded and learned something real, the same way 'built' and
      // 'missing' are not failures either. It gets its own counter so it
      // never inflates 'failed' and never poisons the pass's own $result['ok'].
      if ($why === 'tagmissing') {
        $result['tagmissing']++;
      } else {
        $result['failed']++;
        $failedNames[] = $image;
      }
      echo $image.' — '.$message."\n";
      continue;
    }

    // The registry answered fine but reading what is actually on disk
    // failed — a broken docker socket, a timeout, garbage back. Recording
    // this as "not installed" would be a lie the moment the image turns out
    // to be sitting right there; the previously recorded local digest (if
    // any) is left alone rather than overwritten with nothing learned.
    // Stamped and cadenced the same as any other completed ask, since the
    // registry question itself did succeed — only the local half is unknown.
    if (!empty($local['unknown'])) {
      $existing['error'] = 'local image could not be read';
      $existing['asked'] = $now;
      $existing['nextDue'] = $now + staxx_update_cadence($image, $existing);
      $images[$image] = $existing;
      $result['failed']++;
      echo $image." — local image could not be read\n";
      continue;
    }

    // A locally-built or side-loaded image has no pulled digest to compare —
    // saying "up to date" would be a lie. Phase 7 of PLAN_45 will read the
    // build recipe's base image properly; for now just say so, honestly.
    if (!empty($local['built'])) {
      $existing['built'] = true;
      $existing['error'] = 'built here — cannot be compared';
      unset($existing['local']);
      $images[$image] = $existing;
      $result['built']++;
      echo $image." — built here, not compared\n";

      // Phase 7: a locally-built image cannot be compared against a
      // registry itself, but the base its Dockerfile builds FROM can be —
      // staxx_rebuild_due() lives in UpdateRun.php, which this file must
      // never require (that would be circular), so it is only called when
      // present. $rows is already this image's list of "<stack>::<service>"
      // holders from staxx_update_images() above.
      if (function_exists('staxx_rebuild_due')) {
        foreach ($rows as $holder) {
          [$hStack, $hService] = array_pad(explode('::', $holder, 2), 2, '');
          if ($hStack === '' || $hService === '') continue;
          $rebuildWhy = '';
          $rebuilds[$holder] = [
            'due' => staxx_rebuild_due($hStack, $hService, $rebuildWhy),
            'why' => $rebuildWhy,
          ];
        }
      }
      continue;
    }

    // Unreachable in practice: the early exit above already sends a
    // genuinely absent image straight to `continue` before the registry is
    // ever asked, and nothing between there and here reassigns $local. Kept
    // as a guard rather than deleted, since a future reordering of the loop
    // could put an ask ahead of that exit again without anyone noticing.
    if ($local === []) {
      $existing['error'] = 'not installed';
      unset($existing['local']);
      $images[$image] = $existing;
      $result['missing']++;
      echo $image." — not installed here\n";
      continue;
    }

    unset($existing['error'], $existing['built'], $existing['tags'], $existing['suggest']);
    $existing['local']   = $local['digest'];
    $existing['remote']  = $remote['digest'] ?? '';
    if (isset($remote['version'])) $existing['version'] = $remote['version'];
    if (isset($remote['source']))  $existing['source']  = $remote['source'];
    if (isset($remote['created'])) $existing['created'] = $remote['created'];
    // PLAN_90 Stage 2 — the entity tag and the Accept fingerprint it was
    // recorded against, so the next ask can send a conditional request. Only
    // the HTTP route provides these; a CLI-route answer clears them rather
    // than leaving a stale tag behind that no longer describes anything this
    // route actually checked.
    if (isset($remote['etag']))   $existing['etag']   = $remote['etag'];   else unset($existing['etag']);
    if (isset($remote['accept'])) $existing['accept'] = $remote['accept']; else unset($existing['accept']);
    // Only stamped when the ask actually went out in full — a 304, or an
    // answer carried forward from one, must leave this alone or the fortnight
    // would never elapse.
    if (!empty($remote['fresh'])) $existing['fresh'] = $now;
    // No 'revision' is kept here on purpose, though the label is read. This
    // entry's version/created describe the REMOTE build, and the commit is
    // wanted for the one being replaced — which the record step re-reads from
    // the local image anyway. A key here would be the wrong half of the pair
    // under a name that does not say which half it is, and that is precisely
    // how 'wasCreated' came to pair a stale date with a current build.
    $existing['asked'] = $now;
    // A successful ask, whatever it found — the consecutive-failure notice
    // only ever describes an ongoing problem, never a historical one.
    $existing['fails'] = 0;
    unset($existing['failedSince']);

    $localDigest = $local['digest'] ?? '';
    $changed = $localDigest !== '' && $existing['remote'] !== '' && $localDigest !== $existing['remote'];

    // A dismissed version stays quiet until something NEWER than it turns
    // up — 'skip' has to match the remote digest just asked about, not
    // merely be set, or a later real update would be silenced by an old
    // skip that no longer describes what is actually on offer.
    $skipped = $changed && ($existing['skip'] ?? '') !== '' && $existing['skip'] === $existing['remote'];

    if ($changed && !$skipped) {
      // 'seen' starts the countdown and must never be touched again once
      // set for THIS remote digest — a re-check that kept restarting the
      // clock would mean an update never actually arrives. Only a fresh
      // digest (one 'seen' has not already been recorded against) resets it.
      if (($existing['seenDigest'] ?? '') !== $existing['remote']) {
        $priorVersion = $images[$image]['version'] ?? '';
        if ($priorVersion !== '') {
          $existing['was'] = $priorVersion;
        } elseif (($existing['was'] ?? '') === '') {
          // PLAN_127 — nothing was ever recorded as newest-seen before this
          // pass, which happens whenever the first check StaXX ever makes
          // already finds an update pending: there is no earlier "was" to
          // roll forward. Fall back to the running image's own declared
          // version label instead — staxx_image_local() already read it off
          // the local image above, no extra docker call needed. An image
          // that publishes no version label stays blank, which is honest.
          $existing['was'] = (string)($local['version'] ?? '');
        }
        // The running image's own build date — 'created' on $existing is the
        // REMOTE image's date and is overwritten every pass, so it cannot
        // serve as "before". $local is what 'was' is being read alongside,
        // so its date is the honest "before" to pair with it. No falling back
        // to a remembered one, unlike 'was' above: a date kept from an
        // earlier cycle describes whatever was running then, and pairing that
        // with today's "after" would state a time this image was never built.
        $existing['wasCreated'] = $local['created'] ?? '';
        $existing['seen']       = $now;
        $existing['seenDigest'] = $existing['remote'];
        // PLAN_90 Stage 3 — the digest genuinely changed, which is the one
        // event staxx_update_cadence() actually cares about: a ring of the
        // last 5 such moments, oldest trimmed off the front, is how it tells
        // a churny rolling tag from one that has sat still for months.
        $moves = (array)($existing['moves'] ?? []);
        $moves[] = $now;
        if (count($moves) > 5) $moves = array_slice($moves, -5);
        $existing['moves'] = array_values($moves);
        // A genuinely newer version has turned up — someone cancelling the
        // last one they saw must not be silently opted out of every version
        // that comes after it.
        unset($existing['hold']);
        $result['updates']++;
        $newlyFound++;
        echo $image.' — update available'
           . (($existing['was'] ?? '') !== '' && isset($existing['version'])
               ? ' ('.$existing['was'].' → '.$existing['version'].')' : '')
           . "\n";
      } else {
        $result['updates']++;
        echo $image." — update still pending\n";
      }
    } else {
      unset($existing['seen'], $existing['was'], $existing['wasCreated'], $existing['seenDigest']);
      echo $image.($skipped ? ' — update skipped, staying quiet' : ' — up to date')."\n";
    }

    // PLAN_90 Stage 3 — every path that reaches here just stamped 'asked'
    // above, so this is the one place that needs to compute nextDue: it
    // covers a fresh update, a still-pending one and plain "up to date"
    // alike, using the entry as it stands right now (including the 'moves'
    // ring just appended to, if this pass is what changed it).
    $existing['nextDue'] = $now + staxx_update_cadence($image, $existing);

    $images[$image] = $existing;
  }

  // Silence here would read as "everything was checked" — the exact thing
  // that made a stale answer look like a current one.
  if ($unchecked !== []) {
    echo count($unchecked).' image'.(count($unchecked) === 1 ? '' : 's')
       . " left unchecked this pass:\n";
    foreach ($unchecked as $skippedImage) echo '  '.$skippedImage."\n";
  }

  $result['limited'] = $limited;
  $result['ok'] = $result['failed'] === 0 && !$limited;
  // The rate-limited sentence takes precedence over the failed-images summary
  // when both apply, and is what the grid's last-checked line will show.
  if ($limited) {
    // PLAN_112 Phase B/C — the sentence now says what actually happened
    // instead of naming an assumed cause. Checking is free everywhere but
    // docker.io, so a stand-down on any other host means it is refusing
    // outright rather than metering; docker.io's own refusal is almost
    // always someone's real pulls on this address, not StaXX's own asking.
    $sentences = [];
    foreach (array_keys($limitedRegistries) as $refuser) {
      $sentences[] = $refuser === 'docker.io'
        ? 'Docker Hub has stopped answering questions from this address for now. Checking costs '
          . 'nothing, so this is usually caused by images being downloaded — by you, or by anything '
          . 'else on your network sharing this address. Anything StaXX did not get to still shows the '
          . 'answer it gave last time, and it will try again within the hour.'
        : $refuser.' has stopped answering questions from this server for now. Anything it was not '
          . 'asked about still shows the answer it gave last time; the next pass will ask again.';
    }
    $result['error'] = implode(' ', $sentences);
  } else {
    $result['error'] = $result['failed'] > 0 ? 'Could not check: '.implode(', ', $failedNames) : '';
  }

  // A stack that has been removed or renamed leaves findings behind, and
  // Stage 4's report is built from this file alone — so without this it would
  // list findings against stacks that no longer exist, and the file would grow
  // on the flash drive forever. Only a full pass may prune: a scoped one has
  // not looked at every stack and would delete what it simply did not visit.
  //
  // And only while the stack root can actually be read. staxx_scan_stacks()
  // used to return an empty list both when there are no stacks and when it
  // cannot look at all — an unmounted pool, an array that is not started —
  // and those two must never be treated alike. This pass runs from cron
  // regardless of array state, so without staxx_stacks_visible() below the
  // first check after a reboot would quietly delete every stack's history on
  // the grounds that no stack exists. staxx_stacks_visible() replaces the
  // is_dir() this guard used to run by hand: it also catches a root that
  // exists but will not scandir(), which is_dir() alone reports as fine.
  if ($scope === 'all' && staxx_stacks_visible()) {
    $live = staxx_update_stack_files();
    foreach (array_keys($stacksState) as $known) {
      if (!isset($live[$known])) unset($stacksState[$known]);
    }
  }

  staxx_update_state_save([
    'checked'  => $now,
    'ok'       => $result['ok'],
    'error'    => $result['error'],
    'spend'    => $spend,
    'images'   => $images,
    'rebuilds' => $rebuilds,
    'stacks'   => $stacksState,
  ]);

  staxx_update_unlock();

  // The shell readout — one line per host that has asked anything at all
  // this pass or before, so a log tail reads the same figures the settings
  // panel draws from staxx_spend_report().
  foreach (staxx_spend_report(['spend' => $spend], $now) as $row) {
    $bit = ($row['limit'] !== null && $row['remaining'] !== null)
      ? $row['remaining'].' of '.$row['limit'].' left'
      : 'no limit reported';
    echo 'spent: '.$row['host'].' — '.$row['askedHour'].' asked this hour, '
       . ($row['paidHour'] === 0 ? 'all free' : $row['paidHour'].' paid').', '.$bit."\n";
  }

  // One notification for the whole pass, never one per image — see the
  // matching reasoning on staxx_update_notify() itself. staxx_update_settings()
  // and staxx_update_notify() both live in UpdateRun.php, which this file
  // must never require (that would be circular), so both calls are guarded.
  if ($newlyFound > 0 && function_exists('staxx_update_notify') && function_exists('staxx_update_settings')) {
    $notify = staxx_update_settings()['notify'];
    if ($notify === 'found' || $notify === 'applied') {
      $waiting = 0;
      foreach (array_keys($images) as $img) {
        if (staxx_updates_pill_for_image($img, $images)['state'] === 'update') $waiting++;
      }
      staxx_update_notify(
        'StaXX image updates found',
        $waiting.' image'.($waiting === 1 ? '' : 's').' '.($waiting === 1 ? 'has' : 'have').' an update waiting.'
      );
    }
  }

  return $result;
}

/**
 * Run staxx_update_check() as a detached job, mirroring
 * staxx_start_job()'s own setsid/log/sentinel pattern so the existing `job`
 * action on the page can follow this one exactly the same way.
 */
function staxx_update_check_start(string $scope, bool $force, string &$error): string {
  $error = '';

  if ($scope !== 'all' && !staxx_valid_path($scope)) {
    $error = 'Invalid scope.';
    return '';
  }
  if (!staxx_docker_running()) {
    $error = 'The Docker service is not running.';
    return '';
  }

  if (!staxx_private_dir(STAXX_JOB_DIR)) {
    $error = 'Could not create '.STAXX_JOB_DIR;
    return '';
  }

  $job = bin2hex(random_bytes(8));
  $log = STAXX_JOB_DIR.'/'.$job.'.log';

  @file_put_contents($log, '$ checking updates for '.$scope."\n\n");
  @chmod($log, 0600);

  // Watch.php, not __FILE__ — it requires Links.php, which requires this
  // file, so the detached process also gets staxx_links_move_candidate()
  // (PLAN_61) and staxx_watch_check() (PLAN_62), and neither check inside
  // staxx_update_check() stays permanently guarded off. Requiring Updates.php
  // or even Links.php alone here would leave one or both function_exists()
  // tests false for every out-of-band pass, which is exactly the dead-code
  // trap PLAN_61 warns about and PLAN_62 repeats verbatim.
  //
  // UpdateRun.php on top of that, for staxx_update_seed_history() below —
  // Watch.php's chain does not reach it, and this is a fresh process, so
  // there is no cycle to create. Named with require_once so its own guard
  // and the shared files it pulls in stay loaded exactly once.
  //
  // The one-off baseline runs BEFORE the check, so a box that has never
  // recorded anything has a starting point in every stack's history from the
  // first pass. It costs no network at all (hence the false), and returns
  // zero stacks instantly once it has run.
  $php = staxx_php_bin().' -r '.escapeshellarg(
    'require '.var_export(__DIR__.'/Watch.php', true).'; '
    .'require_once '.var_export(__DIR__.'/UpdateRun.php', true).'; '
    .'$s = staxx_update_seed_history(); '
    .'if ($s["stacks"] > 0) echo "recorded the build now running in ".$s["stacks"]." stack".($s["stacks"] === 1 ? "" : "s")."\n"; '
    .'elseif (!$s["ok"]) echo "the stack folder could not be read, so nothing was recorded this time\n"; '
    .'$r = staxx_update_check('.var_export($scope, true).', '.($force ? 'true' : 'false').'); '
    .'echo "\nchecked ".$r["asked"]." asked, ".$r["skipped"]." skipped, "'
    .'.$r["updates"]." updates, ".$r["failed"]." failed\n";'
  );

  $inner = $php.' 2>&1; echo "'.STAXX_JOB_END.' $?"';

  @exec(
    'setsid sh -c '.escapeshellarg($inner).' </dev/null >> '.escapeshellarg($log).' 2>&1 &'
  );

  return $job;
}

/* ------------------------------------------------------------- Part H: the grid --
 *
 * Everything below answers "what does this row's pill say", from the state
 * file alone — no docker call, so the page can ask on every poll. The clock,
 * the pause switch, automatic updating and roll back are later phases; this
 * only reports what is already known.
 */

/**
 * PLAN_112 Phase C — one sentence saying when this image was last asked
 * about, when it is next due, and why that cadence applies, for the pill's
 * tooltip. Empty when never asked, so the "never checked" tip above is not
 * followed by a sentence describing a clock that has not started yet.
 */
function staxx_update_when_words(string $image, array $entry, int $now): string {
  $asked = (int)($entry['asked'] ?? 0);
  if ($asked === 0) return '';

  $nextDue = (int)($entry['nextDue'] ?? 0);
  $next = $nextDue > $now
    ? 'next check in '.staxx_time_span_words($nextDue - $now)
    : 'next check at the next pass';

  $why = staxx_update_cadence_why($image, $entry)['why'];
  return ' Last asked '.staxx_time_span_words($now - $asked).' ago; '.$next.'. '.$why;
}

/**
 * Classify one image reference against the state file — the one place that
 * turns a raw state-file entry into the six words a pill is allowed to say.
 * No key for this image at all is 'unknown', which must never be confused
 * with 'current': one means "never asked", the other means "asked, and
 * nothing has moved" — the whole reason this feature exists is to keep
 * those apart.
 *
 * @return array{state:string, label:string, source:string, tip:string,
 *               version?:string, was?:string}
 */
function staxx_updates_pill_for_image(string $image, array $images): array {
  $entry = $images[$image] ?? null;

  if ($entry === null) {
    return [
      'state'  => 'unknown',
      'label'  => 'never checked',
      'source' => '',
      'tip'    => 'This image has not been checked yet. Press Check to ask the registry.',
    ];
  }

  $source = (string)($entry['source'] ?? '');

  if (!empty($entry['built'])) {
    return [
      'state'  => 'built',
      'label'  => 'built here',
      'source' => $source,
      'tip'    => 'This image was built on this server rather than pulled, so it cannot yet '
                . 'be compared against a registry.',
    ];
  }

  $error = (string)($entry['error'] ?? '');
  if ($error === 'not installed') {
    return [
      'state'  => 'missing',
      'label'  => 'not installed',
      'source' => $source,
      'tip'    => 'This image has not been installed on this server yet, so there is nothing '
                . 'to compare it against.',
    ];
  }
  // Checked ahead of the catch-all below on purpose: a withdrawn tag is a
  // permanent, specific answer, not a passing glitch, and must never be
  // flattened into the same "could not check" words a network failure gets.
  if ($error === 'tag withdrawn') {
    $tag     = staxx_image_tag_part($image);
    $suggest = (string)($entry['suggest'] ?? '');
    $tip = $suggest !== ''
      ? 'The tag "'.$tag.'" is no longer published for this image. "'.$suggest.'" is offered '
      . 'instead — open the row menu to change it.'
      : 'The tag "'.$tag.'" is no longer published for this image. Open the row menu to pick a '
      . 'different one.';
    return ['state' => 'tagmissing', 'label' => 'tag withdrawn', 'source' => $source, 'tip' => $tip,
             'suggest' => $suggest];
  }

  if ($error !== '') {
    // PLAN_90 Stage 3b — 'why' is the short chip already shown beside the
    // pill (StacksTable.php renders it as a data attribute, terse on
    // purpose); 'note' is the full sentence, meant for a title attribute,
    // that says what is actually going on and what to do about it. Only
    // 'unsupported' and a run of five straight 'failed' asks get one — a
    // rate refusal and an ordinary early failure are not yet worth a notice.
    $why  = '';
    $note = '';
    if ($error === 'rate limited') {
      $tip = 'Checking was refused because too many questions were asked recently. Wait a while '
           . 'and check again, or sign in to Docker Hub under Settings to raise the limit.';
    } elseif ($error === 'not found in the registry') {
      $why  = 'not found';
      $note = 'The registry has no repository at this address. Check the image name in the '
            . 'compose file, or whether the publisher has moved it.';
      $tip  = $note;
    } elseif ($error === 'cannot be checked here') {
      // Never phrased as a failure of the image — this box's own limits, not
      // the publisher's, so no "try again" is offered because trying again
      // cannot change the answer.
      $why  = 'cannot check';
      $note = 'StaXX cannot check this image for updates on this server.';
      $tip  = $note;
    } else {
      $tip = 'This image could not be checked last time. Try checking again.';
      $fails = (int)($entry['fails'] ?? 0);
      if ($fails >= 5) {
        $why = 'check failing';
        $since = (int)($entry['failedSince'] ?? 0);
        $span  = $since > 0 ? staxx_time_span_words(max(0, time() - $since)) : 'a while';
        $note  = 'StaXX has tried to check this image for updates '.$fails.' times over the past '
               . $span.' and the registry has not answered. Check that the repository still '
               . 'exists and that this server can reach it.';
      }
    }
    return ['state' => 'error', 'label' => 'could not check', 'source' => $source, 'tip' => $tip,
             'why' => $why, 'note' => $note];
  }

  $local  = (string)($entry['local']  ?? '');
  $remote = (string)($entry['remote'] ?? '');
  // Present in the state file but with no digest either side — a check pass
  // that has not reached this image yet. Same "never checked" answer as no
  // entry at all, for the same reason: nothing has actually been compared.
  if ($local === '' || $remote === '') {
    return [
      'state'  => 'unknown',
      'label'  => 'never checked',
      'source' => $source,
      'tip'    => 'This image has not been checked yet. Press Check to ask the registry.',
    ];
  }

  // A dismissed version stays quiet only while it is still the newest thing
  // on offer — see the matching comment in staxx_update_check().
  $skipped = ($entry['skip'] ?? '') !== '' && $entry['skip'] === $remote;

  // PLAN_127 — the same three facts staxx_update_when_words() folds into the
  // tip sentence below, kept separately too for the hover card's own rows.
  $clock = staxx_update_clock_words($image, $entry, time());

  if ($local !== $remote && !$skipped) {
    $was = (string)($entry['was'] ?? '');
    $ver = (string)($entry['version'] ?? '');

    // A moving tag (e.g. "main") reports the same name for the running build
    // and the new one — the name alone cannot say anything changed. Only the
    // build dates can say that, and only when both are actually known; with
    // either missing this falls through to the plain "update ready" below
    // rather than printing a claim it cannot back up.
    if ($was !== '' && $ver !== '' && $was === $ver
        && !empty($entry['wasCreated']) && !empty($entry['created'])) {
      $whenWas = date('j M, H:i', (int)$entry['wasCreated']);
      $whenNew = date('j M, H:i', (int)$entry['created']);
      return ['state' => 'update', 'label' => $ver.' · new build', 'source' => $source,
               'tip' => 'This tag always points at the newest build, so its name never changes. '
                      . 'The one running was built '.$whenWas.', and the new one was built '.$whenNew.'. '
                      . 'Press this to fetch it and rebuild the container on it.'
                      . staxx_update_when_words($image, $entry, time()),
               'version' => $ver, 'was' => $was,
               'askedWords' => $clock['asked'], 'nextWords' => $clock['next'],
               'intervalWords' => $clock['interval'], 'cadenceWhy' => $clock['why']];
    }

    // PLAN_121 item 7: a from-to pair could run to twice the width of every
    // other pill (Jellyfin's read 40+ characters), so the label is always
    // the same plain words everyone else gets — the tag icon
    // (staxx_update_pill_html() below) is what tells this pill apart from
    // a plain "update ready" with no version known at all. The hover still
    // spells out both versions in a sentence; only the visible text changed.
    $versioned = $was !== '' && $ver !== '' && $was !== $ver;
    $label = 'update ready';
    $tip = $versioned
      ? 'A newer version, '.$ver.', is available; this is currently running '.$was.'. '
      . 'Press this to fetch it and rebuild the container on it.'
      : 'A newer version of this image is available. Press this to fetch it and rebuild the '
      . 'container on it.';
    $tip .= staxx_update_when_words($image, $entry, time());
    return ['state' => 'update', 'label' => $label, 'source' => $source, 'tip' => $tip,
             'version' => $ver, 'was' => $was, 'versioned' => $versioned,
             'askedWords' => $clock['asked'], 'nextWords' => $clock['next'],
             'intervalWords' => $clock['interval'], 'cadenceWhy' => $clock['why']];
  }

  return [
    'state'  => 'current',
    'label'  => 'up to date',
    'source' => $source,
    'tip'    => 'This is running the version currently published in the registry.'
              . staxx_update_when_words($image, $entry, time()),
    'askedWords' => $clock['asked'], 'nextWords' => $clock['next'],
    'intervalWords' => $clock['interval'], 'cadenceWhy' => $clock['why'],
  ];
}

/**
 * Fold a list of rows' pills into one — used for a whole-stack row (its
 * services) and a folder row (its stacks). The most actionable answer wins:
 * an update to act on beats a check that failed, which beats an image this
 * cannot compare at all, which beats one never asked about, which beats
 * everything actually being fine — because "up to date" must never be shown
 * over the top of something that could not be checked.
 */
function staxx_updates_aggregate(array $pills): array {
  if (!$pills) {
    return [
      'state' => 'unknown', 'label' => 'never checked', 'count' => 0,
      'image' => '', 'source' => '', 'suggest' => '',
      'tip' => 'There is nothing here to check for updates.',
      'due' => 0, 'hold' => false, 'why' => '',
    ];
  }

  // 'moved' ranks below built/missing and above unknown — a registry move is
  // worth knowing about, but it is never as pressing as something that could
  // not be checked at all or an image not even installed here.
  $rank = ['update' => 0, 'error' => 1, 'tagmissing' => 1, 'rebuild' => 2,
           'built' => 3, 'missing' => 3, 'moved' => 4, 'unknown' => 5, 'current' => 6];

  $best      = null;
  $bestRank  = 99;
  $updateCount     = 0;
  $tagMissingCount = 0;
  $movedCount      = 0;
  $source    = '';
  // The soonest clock among the children, and the first non-empty reason one
  // of them will not fire automatically — a folder or stack row speaks for
  // whichever service is closest to actually doing something, not for all
  // of them at once.
  $due  = 0;
  $hold = false;
  $why  = '';
  foreach ($pills as $p) {
    $state = $p['state'] ?? 'unknown';
    if ($state === 'update') $updateCount++;
    if ($state === 'tagmissing') $tagMissingCount++;
    if ($state === 'moved') $movedCount++;
    $r = $rank[$state] ?? 99;
    if ($r < $bestRank) { $bestRank = $r; $best = $p; }
    if ($source === '' && ($p['source'] ?? '') !== '') $source = $p['source'];

    $childDue = (int)($p['due'] ?? 0);
    if ($childDue > 0 && ($due === 0 || $childDue < $due)) { $due = $childDue; $hold = !empty($p['hold']); }
    if ($why === '' && ($p['why'] ?? '') !== '') $why = $p['why'];
  }

  $total = count($pills);
  $state = $best['state'] ?? 'current';

  switch ($state) {
    case 'update':
      $label = $updateCount === 1 ? $best['label'] : $updateCount.' updates ready';
      $tip   = $updateCount === 1
        ? $best['tip']
        : $updateCount.' of '.$total.' services here have an update available. Open the '
        . 'stack to update them.';
      break;
    case 'error':
      $label = 'could not check';
      $tip   = 'One or more services here could not be checked, so this is not shown as up '
             . 'to date. Try checking again.';
      break;
    case 'tagmissing':
      $label = 'tag withdrawn';
      $tip   = $tagMissingCount === 1
        ? $best['tip']
        : $tagMissingCount.' of '.$total.' services here are using a tag the registry no '
        . 'longer publishes. Use this row\'s menu to fix them.';
      break;
    case 'rebuild':
      $label = 'rebuild ready';
      $tip   = 'One or more services here were built on this server, and the base image they '
             . 'build from has moved on. Rebuild to pick it up.';
      break;
    case 'built':
      $label = 'built here';
      $tip   = 'One or more services here were built on this server, so they cannot be '
             . 'compared against a registry.';
      break;
    case 'missing':
      $label = 'not installed';
      $tip   = 'One or more services here have not been installed on this server yet, so '
             . 'there is nothing to compare.';
      break;
    case 'moved':
      $label = 'registry moved';
      $tip   = $movedCount === 1
        ? $best['tip']
        : $movedCount.' of '.$total.' services here now publish at a different registry than '
        . 'the one their template was written against. Open the stack to see where.';
      break;
    case 'unknown':
      $label = 'never checked';
      $tip   = 'This has not been checked yet. Press Check to ask the registry.';
      break;
    default:
      $state = 'current';
      $label = 'up to date';
      $tip   = 'Every service here is running the version currently published in the registry.';
  }

  return [
    'state'  => $state,
    'label'  => $label,
    'count'  => $updateCount,
    // A row speaking for several services cannot name one image; a row speaking
    // for exactly one can and does, which is what the menu's image-keyed items need.
    'image'  => $total === 1 ? (string)($pills[0]['image'] ?? '') : '',
    // Only meaningful when the row's own state is the withdrawn tag and it
    // speaks for exactly one such service — folding several together cannot
    // offer one replacement tag for all of them.
    'suggest' => ($state === 'tagmissing' && $tagMissingCount === 1)
      ? (string)($best['suggest'] ?? '') : '',
    'source' => $source,
    'tip'    => $tip,
    'due'    => $due,
    'hold'   => $hold,
    'why'    => $why,
    // PLAN_121 item 7: carried through only for the same single-service case
    // $label above reused $best['label'] for — a row rolling up several
    // updates into "N updates ready" never named one version and still
    // does not.
    'versioned' => ($state === 'update' && $updateCount === 1) ? !empty($best['versioned']) : false,
    // PLAN_127 — the hover card's own facts, carried through on the same
    // single-service condition as 'versioned' above: a roll-up of several
    // services cannot name one running/available version or one clock
    // between them, but a row that turns out to speak for exactly one still
    // has everything staxx_updates_pill_for_image() worked out for it.
    'version'       => $updateCount === 1 ? (string)($best['version'] ?? '') : '',
    'was'           => $updateCount === 1 ? (string)($best['was'] ?? '') : '',
    'askedWords'    => $total === 1 ? (string)($best['askedWords'] ?? '') : '',
    'nextWords'     => $total === 1 ? (string)($best['nextWords'] ?? '') : '',
    'intervalWords' => $total === 1 ? (string)($best['intervalWords'] ?? '') : '',
    'cadenceWhy'    => $total === 1 ? (string)($best['cadenceWhy'] ?? '') : '',
  ];
}

/**
 * One row's pill — a service (image looked up from the compose file), or a
 * whole stack (every service folded together by staxx_updates_aggregate()).
 * Reads the compose metadata staxx_compose_meta() already caches to disk and
 * the update state file; runs no command of its own.
 */
function staxx_updates_for_row(string $stack, string $service = ''): array {
  // staxx_list_stacks() works out compose metadata, run state, the review
  // lock and more for every stack, just so this could throw all of it away
  // bar one file path — costly when called once per stack row and once per
  // container row. staxx_scan_stacks() (request-cached) plus a compose-file
  // lookup answers the same question far more cheaply.
  $file = '';
  foreach (staxx_scan_stacks()['stacks'] as $s) {
    if ($s['rel'] === $stack) { $file = staxx_find_compose_file($s['dir']); break; }
  }

  $meta = $file !== '' ? staxx_compose_meta($file) : ['ok' => false, 'services' => []];
  if (!$meta['ok']) return staxx_updates_aggregate([]);

  $images = (array)staxx_update_state()['images'];

  if ($service !== '') {
    $image = trim((string)($meta['services'][$service]['image'] ?? ''));
    if ($image === '') return staxx_updates_aggregate([]);

    $pill = staxx_updates_pill_for_image($image, $images);
    $pill['image'] = $image;
    $pill['count'] = $pill['state'] === 'update' ? 1 : 0;
    staxx_updates_apply_service_state($pill, $stack, $service, $image);
    return $pill;
  }

  $pills = [];
  foreach ($meta['services'] as $svc => $svcMeta) {
    $image = trim((string)($svcMeta['image'] ?? ''));
    if ($image === '') continue;
    $pill = staxx_updates_pill_for_image($image, $images);
    // Carried on each pill the same way the single-service branch above does:
    // staxx_updates_pill_for_image() does not set it, and the aggregate below
    // hands it on when a stack turns out to have only one service.
    $pill['image'] = $image;
    staxx_updates_apply_service_state($pill, $stack, $svc, $image);
    $pills[] = $pill;
  }
  return staxx_updates_aggregate($pills);
}

/**
 * Fill in a service pill's due/hold/why (uniform on every pill, whatever its
 * state, so the browser never has to guard for a missing key) and, for a
 * locally-built image, override 'built' with 'rebuild' when Phase 7's check
 * pass has already recorded that this service's base image has moved on.
 * $pill is modified in place.
 */
function staxx_updates_apply_service_state(array &$pill, string $stack, string $service, string $image): void {
  $pill['due']  = $pill['due']  ?? 0;
  $pill['hold'] = $pill['hold'] ?? false;
  $pill['why']  = $pill['why']  ?? '';
  // Whether roll back has anything to offer at all. A plain state read, so it
  // is cheap enough for every row — and without it the row menu has to offer
  // roll back on every service and let the refusal explain itself, which is a
  // menu item that usually does nothing.
  $pill['back'] = !empty(staxx_update_state()['history'][$stack.'::'.$service]);

  // PLAN_61 — carried on every pill regardless of headline state: a service
  // has exactly one advisory state, so 'update' (and everything else) wins
  // the badge over a registry move — see staxx_updates_aggregate()'s ranking
  // for the same reasoning applied across a row's whole set of services.
  // Promoted to the headline state only from 'current', mirroring how
  // 'built' becomes 'rebuild' just below.
  $imageState = staxx_update_state()['images'][$image] ?? [];
  $moveFact   = $imageState['move'] ?? null;
  $moved      = is_array($moveFact) && ($moveFact['host'] ?? '') !== ''
              && ($imageState['skipMove'] ?? '') !== $moveFact['host'];
  $pill['moved'] = $moved;
  if ($moved && $pill['state'] === 'current') {
    $pill['state'] = 'moved';
    $pill['label'] = 'registry moved';
    $pill['tip']   = 'The template for this app now publishes at '.$moveFact['host'].'. Open the '
                    . 'row menu to see the details.';
  }

  if ($pill['state'] === 'built') {
    $rebuilds = (array)staxx_update_state()['rebuilds'];
    $recorded = $rebuilds[$stack.'::'.$service] ?? null;
    if (is_array($recorded) && !empty($recorded['due'])) {
      $pill['state'] = 'rebuild';
      $pill['label'] = 'rebuild ready';
      $pill['why']   = (string)($recorded['why'] ?? '');
    }
    return;
  }

  // The clock only has anything to say about a service actually offering an
  // update — asking for any other state would just report "no clock" for
  // reasons that have nothing to do with a countdown.
  if ($pill['state'] === 'update' && function_exists('staxx_update_clock')) {
    $clock = staxx_update_clock($stack, $service, $image);
    $pill['due']  = $clock['due'];
    $pill['hold'] = $clock['hold'];
    $pill['why']  = $clock['why'];
  }
}

/**
 * The same shape, summed over every stack whose path sits under one folder
 * — one level of nesting, the same shape the rest of the plugin uses for a
 * folder scope.
 */
function staxx_updates_for_folder(string $folder): array {
  // Names only, off the cheap scan — see staxx_updates_for_row() for why
  // staxx_list_stacks() is avoided here too.
  $pills = [];
  foreach (staxx_scan_stacks()['stacks'] as $s) {
    if (strpos($s['rel'], $folder.'/') !== 0) continue;
    $pills[] = staxx_updates_for_row($s['rel']);
  }
  return staxx_updates_aggregate($pills);
}

/**
 * The grid's last-checked line: when the last pass ran, whether it worked,
 * and how many distinct images have an answer at all versus an update
 * actually waiting. Reads the state file only.
 */
function staxx_updates_summary(): array {
  $state  = staxx_update_state();
  $images = (array)$state['images'];

  $known      = 0;
  $updates    = 0;
  $tagmissing = 0;
  foreach (array_keys($images) as $image) {
    $pillState = staxx_updates_pill_for_image($image, $images)['state'];
    if ($pillState === 'unknown') continue;
    $known++;
    if ($pillState === 'update') $updates++;
    // Reported on its own, same reasoning as staxx_update_check(): a
    // withdrawn tag is a definite answer, not a failure, so the grid can say
    // "3 updates waiting, 1 tag withdrawn" instead of folding it into either.
    if ($pillState === 'tagmissing') $tagmissing++;
  }

  // PLAN_62 Stage 4's own count, cheap for the same reason the rest of this
  // function is: staxx_watch_report() reads the state file alone. Carried as
  // a count plus a reason rather than folded into 'ok'/'error' above, since
  // "the array is not started" is a fact about this report specifically, not
  // about whether the update check itself ran.
  $watchReport = staxx_watch_report();

  return [
    'checked'     => (int)$state['checked'],
    'ok'          => (bool)$state['ok'],
    'error'       => (string)$state['error'],
    // PLAN_112 Phase B — read through staxx_spend_refusers() rather than the
    // old flat flag, so a state file from before the ledger existed still
    // shows correctly for the one pass it takes to fill it in.
    'limited'     => staxx_spend_refusers($state, time()) !== [],
    'updates'     => $updates,
    'tagmissing'  => $tagmissing,
    'known'       => $known,
    'watch'       => count($watchReport['items']),
    'watchReason' => $watchReport['reason'],
  ];
}

/**
 * Dismiss the version currently on offer for one image: remember its
 * current remote digest under 'skip', so staxx_updates_pill_for_image() (and
 * the check pass's own comparison) reports it as current until something
 * newer replaces that digest. The image has to already be a key in the state
 * file — that is the allowlist, since accepting any string here would let a
 * request invent entries in a file nothing else writes to freely. Refused
 * the same way when there is no remote digest recorded yet: skipping
 * "nothing in particular" is not a real action.
 */
function staxx_update_skip(string $image, string &$error): bool {
  $error = '';
  $state  = staxx_update_state();
  $images = (array)$state['images'];

  if (!array_key_exists($image, $images)) {
    $error = 'This image has not been checked yet, so there is nothing to skip.';
    return false;
  }

  $entry  = $images[$image];
  $remote = (string)($entry['remote'] ?? '');
  if ($remote === '') {
    $error = 'There is no newer version recorded for this image, so there is nothing to skip.';
    return false;
  }

  $entry['skip']   = $remote;
  $images[$image]  = $entry;

  staxx_update_state_save(['images' => $images]);
  return true;
}

/**
 * Dismiss the registry-move hint currently on offer for one image: remember
 * the host it points at under 'skipMove', the same self-expiring shape
 * staxx_update_skip() gives 'skip' — staxx_updates_moved_for_stack() honours
 * it only while the recorded move still points at that same host, so a
 * template that moves house a second time surfaces again rather than
 * staying silent for ever because of a dismissal of the first move. Same
 * allowlist as staxx_update_skip(): the image must already be a key in the
 * state file, so a request cannot invent an entry.
 */
function staxx_update_skip_move(string $image, string &$error): bool {
  $error = '';
  $state  = staxx_update_state();
  $images = (array)$state['images'];

  if (!array_key_exists($image, $images)) {
    $error = 'This image has not been checked yet, so there is nothing to skip.';
    return false;
  }

  $entry = $images[$image];
  $host  = (string)($entry['move']['host'] ?? '');
  if ($host === '') {
    $error = 'There is no registry move recorded for this image, so there is nothing to skip.';
    return false;
  }

  $entry['skipMove'] = $host;
  $images[$image]    = $entry;

  staxx_update_state_save(['images' => $images]);
  return true;
}

/**
 * PLAN_62 Stage 4 — dismiss one author-example finding: remember the
 * author's current value for this exact (stack, image, service, setting)
 * under 'skip' on the stack's own watch entry, so a later change to that
 * same setting speaks up once more. Third use of the self-expiring shape
 * staxx_update_skip()/staxx_update_skip_move() already establish above.
 *
 * Findings are per stack, not per image (PLAN_62's correction: two stacks
 * sharing an image must dismiss independently), so the key has to include
 * the stack — unlike the two functions above, whose allowlist is just the
 * image. The finding must currently be on offer, which is the allowlist
 * here: it stops a request inventing an entry in a file nothing else writes
 * to freely, the same reasoning staxx_update_skip() gives for refusing an
 * image with no remote digest recorded.
 */
function staxx_watch_skip(string $stack, string $image, string $service, string $setting, string &$error): bool {
  $error  = '';
  $state  = staxx_update_state();
  $stacks = (array)$state['stacks'];

  $entry = $stacks[$stack]['watch'][$image] ?? null;
  if (!is_array($entry)) {
    $error = 'This has not been checked yet, so there is nothing to skip.';
    return false;
  }

  $found = false;
  $value = null;
  foreach ((array)($entry['findings'] ?? []) as $f) {
    if ((string)($f['service'] ?? '') === $service && (string)($f['setting'] ?? '') === $setting) {
      $value = $f['value'] ?? null;
      $found = true;
      break;
    }
  }
  if (!$found) {
    $error = 'There is no finding recorded for this setting, so there is nothing to skip.';
    return false;
  }

  $entry['skip'][$service.'|'.$setting] = $value;
  $stacks[$stack]['watch'][$image]      = $entry;

  staxx_update_state_save(['stacks' => $stacks]);
  return true;
}

/**
 * One image's findings for one stack, with anything currently dismissed
 * filtered out first — shared by every reader (the row pill's count, the
 * field grafts, Stage 4's combined report) so the three can never disagree
 * about what a dismissal covers. A dismissal only holds while the author's
 * value for that setting has not moved since it was recorded; the moment it
 * has, the stored 'skip' entry is stale and the finding shows again.
 */
function staxx_watch_active_findings(array $entry): array {
  $skip = (array)($entry['skip'] ?? []);
  $all  = (array)($entry['findings'] ?? []);
  if ($skip === []) return $all;

  $out = [];
  foreach ($all as $f) {
    $key = (string)($f['service'] ?? '').'|'.(string)($f['setting'] ?? '');
    if (array_key_exists($key, $skip) && $skip[$key] === ($f['value'] ?? null)) continue;
    $out[] = $f;
  }
  return $out;
}

/**
 * PLAN_62 Stage 4 — every undismissed author-example finding, across every
 * stack, in one list. Reads the state file alone and parses no compose
 * file: PLAN_61's Stage 4 first tried walking every stack and re-reading its
 * file to build its own report, which would have blown the self-test's
 * fifteen-second budget on a cold cache and turned the button people press
 * when the page misbehaves into a blank screen. Same constraint, same answer.
 *
 * PLAN_68 Part C: an empty answer must carry why it is empty.
 * staxx_scan_stacks() reads empty both when there genuinely are no stacks
 * and when it cannot look at all — an unmounted pool, an array not started —
 * and this report must never present the second as the first.
 * staxx_stacks_visible() is the same cheap test the six-hourly prune above
 * uses for exactly this reason, replacing the is_dir() this guard used to
 * run by hand, which reports an unreadable-but-present root as fine.
 *
 * @return array{ok:bool, reason:string, items:array}
 */
function staxx_watch_report(): array {
  if (!staxx_stacks_visible()) {
    // The scan's own reason, not a guessed one: "the array is not started" is
    // the usual cause but not the only one, and telling somebody to start an
    // array that is already running would send them looking in the wrong place.
    return ['ok' => false, 'items' => [],
      'reason' => staxx_scan_stacks()['error'].' StaXX cannot show what has been found until it can.'];
  }

  $items = [];
  foreach ((array)staxx_update_state()['stacks'] as $stack => $entry) {
    foreach ((array)($entry['watch'] ?? []) as $image => $imageEntry) {
      if (!is_array($imageEntry)) continue;
      foreach (staxx_watch_active_findings($imageEntry) as $f) {
        $items[] = [
          'stack'   => (string)$stack,
          'image'   => (string)$image,
          'service' => (string)($f['service'] ?? ''),
          'setting' => (string)($f['setting'] ?? ''),
          'side'    => (string)($f['side'] ?? ''),
        ];
      }
    }
  }

  return ['ok' => true, 'reason' => '', 'items' => $items];
}

/**
 * The `moved` map for the `read` reply — see PLAN_61's wire contract. Per
 * service, the registry-move fact staxx_update_check() already proved and
 * stored, reshaped for the editor: repo (registry-free, so the browser can
 * notice a hand-edited line), host, the tag proved present there, up to
 * three newest tags, and a reason composed server-side because only the
 * server knows both registries. A pure read of cached compose metadata and
 * the state file — no network, so opening the editor stays cheap.
 *
 * Guarded by function_exists() for the same reason staxx_rebuild_due() is:
 * staxx_links_repo_path() lives in Links.php, which requires this file, so
 * requiring it back would be a cycle. action.php requires both, which is the
 * only place this is called from.
 *
 * @return array<string,array{repo:string,host:string,tag:string,tags:string[],reason:string}>
 */
function staxx_updates_moved_for_stack(string $stack): array {
  if (!function_exists('staxx_links_repo_path')) return [];

  // Same cheap lookup staxx_updates_for_row() uses, rather than
  // staxx_list_stacks() — see its own comment for why.
  $file = '';
  foreach (staxx_scan_stacks()['stacks'] as $s) {
    if ($s['rel'] === $stack) { $file = staxx_find_compose_file($s['dir']); break; }
  }
  $meta = $file !== '' ? staxx_compose_meta($file) : ['ok' => false, 'services' => []];
  if (!$meta['ok']) return [];

  $images = (array)staxx_update_state()['images'];
  $out = [];
  foreach ($meta['services'] as $svc => $svcMeta) {
    $image = trim((string)($svcMeta['image'] ?? ''));
    if ($image === '') continue;

    $move = $images[$image]['move'] ?? null;
    if (!is_array($move) || ($move['host'] ?? '') === '') continue;

    $skipped = ($images[$image]['skipMove'] ?? '') === $move['host'];
    if ($skipped) continue;

    $out[$svc] = [
      'repo'   => staxx_links_repo_path($image),
      'host'   => (string)$move['host'],
      'tag'    => (string)($move['tag'] ?? ''),
      'tags'   => array_values((array)($move['tags'] ?? [])),
      'reason' => (string)($move['reason'] ?? ''),
    ];
  }
  return $out;
}

/**
 * PLAN_61 stage 4 — a one-off list of every image, across every stack,
 * currently pointed at a registry its catalogue template has left: one line
 * each, naming the stack, the service, the address written in the file and
 * the address the template now uses. Calls staxx_updates_moved_for_stack()
 * per stack rather than re-deriving the join, so this can only ever report a
 * move the periodic update check has already proved (the tag named really
 * does answer at the new address) — never the raw join on its own, which
 * cannot promise that. Same reason it is safe inside the self-test's
 * no-network half: it is a read of the cached state file and cached compose
 * metadata, nothing else.
 *
 * @return string[] one line per drifted image, in stack order.
 */
function staxx_updates_moved_report(): array {
  if (!function_exists('staxx_links_repo_path')) return [];

  // Built from the state file alone — no stack walk, no compose parsing. The
  // stack/service attribution that would add costs the one property the
  // self-test promises: staxx_compose_meta() shells out to `compose config`
  // on a cache miss, so walking every stack from cold could spend 15 seconds
  // each and blow the 15-second budget the browser gives this call, leaving
  // the self-test blank exactly when someone is using it to find out why the
  // page is misbehaving. The image reference is the actionable identity
  // anyway, and the table already badges the row it belongs to.
  $lines = [];
  foreach ((array)staxx_update_state()['images'] as $image => $entry) {
    $move = is_array($entry) ? ($entry['move'] ?? null) : null;
    if (!is_array($move) || ($move['host'] ?? '') === '') continue;
    if ((string)($entry['skipMove'] ?? '') === (string)$move['host']) continue;

    $lines[] = $image.' -> '.$move['host'].'/'.staxx_links_repo_path((string)$image)
             . ':'.(string)($move['tag'] ?? '').'. '.(string)($move['reason'] ?? '');
  }
  sort($lines);
  return $lines;
}
?>
