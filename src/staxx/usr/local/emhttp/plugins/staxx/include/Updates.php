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

if (defined('STAXX_UPDATE_STATE')) return;

// Same env-override trick as STAXX_AUTOSTART_FILE, so a server test can point
// this at /tmp without ever touching the real flash file.
$staxx_update_state_env = getenv('STAXX_UPDATE_STATE');
define('STAXX_UPDATE_STATE', $staxx_update_state_env !== false && $staxx_update_state_env !== ''
  ? $staxx_update_state_env
  : STAXX_CFG_DIR.'/updates.json');

// The check pass's lock lives here, not on flash — it is only ever meaningful
// for as long as the pass itself runs, and is worthless after a reboot.
define('STAXX_UPDATE_DIR', '/tmp/staxx/updates');

// How long a remembered answer is trusted before it is asked again — six
// hours, so three stacks sharing an image cost the registry one question a
// day and not sixty.
define('STAXX_UPDATE_ASK_TTL', 21600);

/** The defaults every state array is filled against — never a partial array. */
function staxx_update_state_defaults(): array {
  return [
    'checked'   => 0,
    'ok'        => true,
    'error'     => '',
    'inspector' => '',
    'inspector_at' => 0,
    'paused'    => false,
    'limited'   => false, // drives the page's rate-limited notice; cleared by the next pass that isn't refused
    'images'    => [],
    'history'   => [],
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
  $raw      = @file_get_contents(STAXX_UPDATE_STATE);
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

  $current = @file_get_contents(STAXX_UPDATE_STATE);
  if ($current !== false && $current === $encoded) {
    staxx_update_state_cache($merged);
    return true;
  }

  $dir = dirname(STAXX_UPDATE_STATE);
  if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;

  $tmp = $dir.'/.'.basename(STAXX_UPDATE_STATE).'.'.getmypid().'.tmp';
  if (@file_put_contents($tmp, $encoded) === false) return false;
  if (!@rename($tmp, STAXX_UPDATE_STATE)) { @unlink($tmp); return false; }
  @chmod(STAXX_UPDATE_STATE, 0600);

  staxx_update_state_cache($merged);
  return true;
}

/**
 * Which way this box can ask a registry about an image it does not already
 * have credentials baked in for by name — probed once, because trying each
 * route per image would be sixty pointless processes on a busy server.
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
 * Version/source/created out of a config's OCI-ish labels — shared by every
 * route in staxx_image_remote(), since the label names are the same
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

  return $out;
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
 * What the registry currently has for one image reference — digest, version,
 * source and creation time, every key present only when actually known.
 * Returns [] on any failure at all: a failure must never be mistaken for
 * "up to date", so the caller records "could not check" instead of comparing
 * against nothing. $why is set to why, one of 'limited', 'unsupported',
 * 'notfound', 'tagmissing', 'failed', or '' on success — so a caller hitting
 * Docker Hub's hourly ceiling can stop asking instead of burning the rest of
 * it. $tags is filled with the repository's tag list only when $why comes
 * back 'tagmissing', so the caller can store it without asking again.
 */
function staxx_image_remote(string $image, string &$why = null, ?array &$tags = null): array {
  $why   = '';
  $ref   = escapeshellarg($image);
  $route = staxx_docker_inspector();

  if ($route === 'imagetools') {
    // Docker prints a 429 refusal to stderr, and staxx_sh() throws stderr away
    // (2>/dev/null on the outer shell) — merge it into what staxx_sh() returns
    // so staxx_remote_failure_reason() actually sees the words "Too Many
    // Requests" instead of an empty string. This inner redirect lands on the
    // inner shell's own stdout, so the outer discard never touches it.
    $out = staxx_sh(
      staxx_docker_bin().' buildx imagetools inspect '.$ref
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

    return ['digest' => $digest] + staxx_update_labels_meta($labels);
  }

  if ($route === 'manifest') {
    // 2>&1 here for the same reason as the imagetools route above.
    $out  = staxx_sh(staxx_docker_bin().' manifest inspect --verbose '.$ref.' 2>&1', 20);
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
    return ['digest' => $digest];
  }

  // hub: only for images staxx_hub_repo_path() accepts — a reference it
  // rejects can never be answered by this route, on this box or any other.
  $repo = staxx_hub_repo_path($image);
  if ($repo === '') { $why = 'unsupported'; return []; }

  $tag = 'latest';
  $trimmed = trim($image);
  $slash = strrpos($trimmed, '/');
  $colon = strrpos($trimmed, ':');
  if ($colon !== false && ($slash === false || $colon > $slash)) $tag = substr($trimmed, $colon + 1);

  $token = staxx_hub_json(
    'https://auth.docker.io/token?service=registry.docker.io&scope=repository:'.$repo.':pull',
    [], 6, 8
  );
  if ($token === null || (string)($token['token'] ?? '') === '') { $why = 'failed'; return []; }
  $bearer = 'Authorization: Bearer '.$token['token'];

  // The same Accept list staxx_registry_config() sends for the manifest
  // request, so the digest header matches whichever shape the registry
  // actually served.
  $accept = 'Accept: application/vnd.oci.image.index.v1+json,'
          . 'application/vnd.docker.distribution.manifest.list.v2+json,'
          . 'application/vnd.docker.distribution.manifest.v2+json';

  $url = 'https://registry-1.docker.io/v2/'.$repo.'/manifests/'.rawurlencode($tag);
  $cmd = 'curl -sS -L --max-time 8 -D /dev/stdout -o /dev/null -X GET'
       . ' -H '.escapeshellarg($bearer)
       . ' -H '.escapeshellarg($accept)
       . ' '.escapeshellarg($url);
  $headers = staxx_sh($cmd, 12);

  $digest = '';
  foreach (explode("\n", $headers) as $line) {
    if (preg_match('/^docker-content-digest:\s*(sha256:[0-9a-f]{64})/i', trim($line), $m)) {
      $digest = $m[1];
      break;
    }
  }
  // The status line is the same headers reply the digest search above just
  // walked — a 429 there never carries a docker-content-digest, so checking
  // it only on failure is enough.
  if ($digest === '') { $why = staxx_remote_failure_reason($headers, $image, $tags); return []; }

  // Labels come from the config blob path already built for the form editor —
  // no point re-walking index/manifest/blob a second time just to get them.
  $labels = staxx_registry_config($image)['labels'] ?? [];
  $labels = is_array($labels) ? $labels : [];

  return ['digest' => $digest] + staxx_update_labels_meta($labels);
}

/**
 * array_is_list() needs PHP 8.1; Unraid 7.2 ships 8.x but this keeps the
 * check working even a minor version earlier than that promises.
 */
function staxx_array_is_list(array $arr): bool {
  return function_exists('array_is_list') ? array_is_list($arr) : $arr === array_values($arr);
}

/**
 * What is actually sitting on disk for one image reference — the digest
 * Docker recorded when it pulled it, comparable to staxx_image_remote()'s
 * digest with no conversion either side.
 *
 * Not present locally → []. Present but with no RepoDigests (built here or
 * side-loaded, never pulled) → ['built' => true] with no digest, so the
 * caller does not mistake a locally-built image for one that is up to date.
 */
function staxx_image_local(string $image): array {
  // 2>&1 here for the same reason as the imagetools route above.
  $out = staxx_sh(
    staxx_docker_bin().' image inspect '.escapeshellarg($image).' --format '.escapeshellarg('{{json .}}').' 2>&1',
    15
  );
  $data = json_decode($out, true);
  if (!is_array($data)) return [];

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
 * @return array{asked:int, skipped:int, updates:int, failed:int, built:int, missing:int, tagmissing:int, ok:bool, error:string, limited:bool}
 */
function staxx_update_check(string $scope, bool $force): array {
  $result = ['asked' => 0, 'skipped' => 0, 'updates' => 0, 'failed' => 0, 'built' => 0, 'missing' => 0, 'tagmissing' => 0, 'ok' => true, 'error' => '', 'limited' => false];

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
  $now    = time();
  $failedNames = [];
  $newlyFound = 0; // images whose 'seen' clock started fresh THIS pass, for the "found" notification

  $refs = staxx_update_images($scope);
  $total = count($refs);
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
  foreach ($refs as $image => $rows) {
    $existing = $images[$image] ?? [];

    if (!$force && ($existing['asked'] ?? 0) > 0 && ($now - (int)$existing['asked']) < STAXX_UPDATE_ASK_TTL) {
      $result['skipped']++;
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
    $remote = staxx_image_remote($image, $why, $tags);
    $local  = staxx_image_local($image);
    $result['asked']++;

    if ($remote === [] && $why === 'limited') {
      // The ceiling is already hit — every remaining image is left entirely
      // alone (no state written) rather than spending more of an allowance
      // that will just be refused again.
      $existing['error'] = 'rate limited';
      $images[$image] = $existing;
      $limited = true;
      echo $image." — rate limited, stopping this pass\n";
      $left = $total - $result['asked'] - $result['skipped'];
      if ($left > 0) echo $left.' image'.($left === 1 ? '' : 's')." left unchecked this pass\n";
      break;
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

      // A withdrawn tag or a missing repository will not fix itself between
      // now and the next pass — stamp 'asked' so it honours the same
      // six-hour memory a successful check gets, instead of being re-asked
      // every single pass for ever. 'failed' and 'limited' are transient and
      // must keep retrying promptly, so they are left unstamped.
      if ($why === 'notfound' || $why === 'tagmissing') $existing['asked'] = $now;

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

    // Not on this box at all — a service that has never been started. Leave
    // any previously-remembered seen/was/seenDigest alone; there is simply
    // nothing new to say this pass.
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
    $existing['asked'] = $now;

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
        $existing['was']        = $images[$image]['version'] ?? ($existing['was'] ?? '');
        $existing['seen']       = $now;
        $existing['seenDigest'] = $existing['remote'];
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
      unset($existing['seen'], $existing['was'], $existing['seenDigest']);
      echo $image.($skipped ? ' — update skipped, staying quiet' : ' — up to date')."\n";
    }

    $images[$image] = $existing;
  }

  $result['limited'] = $limited;
  $result['ok'] = $result['failed'] === 0 && !$limited;
  // The rate-limited sentence takes precedence over the failed-images summary
  // when both apply, and is what the grid's last-checked line will show.
  $result['error'] = $limited
    ? 'Docker Hub is limiting how often this server may ask about images. Sign in under Settings to raise the limit, or try again in an hour.'
    : ($result['failed'] > 0 ? 'Could not check: '.implode(', ', $failedNames) : '');

  // A stack that has been removed or renamed leaves findings behind, and
  // Stage 4's report is built from this file alone — so without this it would
  // list findings against stacks that no longer exist, and the file would grow
  // on the flash drive forever. Only a full pass may prune: a scoped one has
  // not looked at every stack and would delete what it simply did not visit.
  //
  // And only while the stack root can actually be read. staxx_scan_stacks()
  // returns an empty list both when there are no stacks and when it cannot
  // look at all — an unmounted pool, an array that is not started — and those
  // two must never be treated alike. This pass runs from cron regardless of
  // array state, so without the is_dir() below the first check after a reboot
  // would quietly delete every stack's history on the grounds that no stack
  // exists.
  if ($scope === 'all' && is_dir(staxx_stack_root())) {
    $live = staxx_update_stack_files();
    foreach (array_keys($stacksState) as $known) {
      if (!isset($live[$known])) unset($stacksState[$known]);
    }
  }

  staxx_update_state_save([
    'checked'  => $now,
    'ok'       => $result['ok'],
    'error'    => $result['error'],
    'limited'  => $limited,
    'images'   => $images,
    'rebuilds' => $rebuilds,
    'stacks'   => $stacksState,
  ]);

  staxx_update_unlock();

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

  if (!is_dir(STAXX_JOB_DIR) && !@mkdir(STAXX_JOB_DIR, 0755, true)) {
    $error = 'Could not create '.STAXX_JOB_DIR;
    return '';
  }

  $job = bin2hex(random_bytes(8));
  $log = STAXX_JOB_DIR.'/'.$job.'.log';

  @file_put_contents($log, '$ checking updates for '.$scope."\n\n");

  // Watch.php, not __FILE__ — it requires Links.php, which requires this
  // file, so the detached process also gets staxx_links_move_candidate()
  // (PLAN_61) and staxx_watch_check() (PLAN_62), and neither check inside
  // staxx_update_check() stays permanently guarded off. Requiring Updates.php
  // or even Links.php alone here would leave one or both function_exists()
  // tests false for every out-of-band pass, which is exactly the dead-code
  // trap PLAN_61 warns about and PLAN_62 repeats verbatim.
  $php = staxx_php_bin().' -r '.escapeshellarg(
    'require '.var_export(__DIR__.'/Watch.php', true).'; '
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
    $tip = $error === 'rate limited'
      ? 'Checking was refused because too many questions were asked recently. Wait a while '
      . 'and check again, or sign in to Docker Hub under Settings to raise the limit.'
      : ($error === 'not found in the registry'
        ? 'The registry no longer has this image, so it cannot be compared. Check the image '
        . 'name is still correct.'
        : 'This image could not be checked last time. Try checking again.');
    return ['state' => 'error', 'label' => 'could not check', 'source' => $source, 'tip' => $tip];
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

  if ($local !== $remote && !$skipped) {
    $was = (string)($entry['was'] ?? '');
    $ver = (string)($entry['version'] ?? '');
    $label = ($was !== '' && $ver !== '') ? $was.' → '.$ver : 'update ready';
    $tip = ($was !== '' && $ver !== '')
      ? 'A newer version, '.$ver.', is available; this is currently running '.$was.'. '
      . 'Press this to fetch it and rebuild the container on it.'
      : 'A newer version of this image is available. Press this to fetch it and rebuild the '
      . 'container on it.';
    return ['state' => 'update', 'label' => $label, 'source' => $source, 'tip' => $tip,
             'version' => $ver, 'was' => $was];
  }

  return [
    'state'  => 'current',
    'label'  => 'up to date',
    'source' => $source,
    'tip'    => 'This is running the version currently published in the registry.',
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

  return [
    'checked'    => (int)$state['checked'],
    'ok'         => (bool)$state['ok'],
    'error'      => (string)$state['error'],
    'limited'    => (bool)$state['limited'],
    'updates'    => $updates,
    'tagmissing' => $tagmissing,
    'known'      => $known,
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
