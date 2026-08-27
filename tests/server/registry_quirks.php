<?php
/* PLAN_92 Stage 1 — meeting a registry's quirks before a user does.
 *
 * PLAN_90 moved update checking off the docker CLI and onto the registry
 * protocol directly, which means StaXX now carries every per-registry quirk
 * the docker client used to carry for us — but only the ones we happen to
 * have met. We met exactly one, by luck: ghcr.io answers /v2/ with a literal
 * placeholder scope, and trusting it silently defeated the whole point of the
 * new route for every ghcr and lscr image on a box (see the long comment on
 * staxx_registry_token() in Defines.php). This file goes looking for the next
 * one deliberately, against nine real public registries, instead of waiting
 * for a bug report.
 *
 * Companion to tests/server/registry_live.php (proves the mechanism against
 * Docker Hub, ghcr and lscr) and updateeconomy.php (offline, pure-function
 * cases). This file is wider and shallower: one well-known public image per
 * registry, three-ish questions each, and — the actual point of the file — a
 * printed table of what each registry turned out to do. A quirk written down
 * is a documented fact; a quirk nobody wrote down is next month's bug report.
 *
 * OPT-IN, same pattern as every other live suite here:
 *
 *     pscp tests/server/registry_quirks.php root@<box>:/tmp/
 *     plink … 'STAXX_QUIRKS=1 php /tmp/registry_quirks.php'
 *
 * Needs NO config keys — no STACK_ROOT, no ARCHIVE_ROOT, no credentials.
 * Every question asked is read-only (a header-only manifest request or a
 * token fetch); nothing is pulled, started, or written. `docker buildx
 * imagetools inspect`, used to cross-check one digest per registry, is
 * itself read-only.
 *
 * Because this depends on nine external services staying as they are, a
 * failure here may mean a registry changed, or the chosen image was
 * withdrawn or retagged — not that the code is wrong. Every network
 * assertion says so in its own message, and an unreachable registry or a
 * missing image is a SKIP with a stated reason, never a FAIL: a suite that
 * goes red on every external hiccup gets ignored, and an ignored suite is
 * worse than none.
 *
 * REQUEST COUNT. Roughly three to four requests per registry (a challenge
 * probe, a token fetch where one is needed, a header-only manifest request,
 * a conditional re-ask) plus a couple more for the one-off label check —
 * call it 35 total across the whole run, counted as it goes and printed at
 * the end. Only Docker Hub's allowance is tight, and it is the only registry
 * that reports its own remaining figure in a response header — measured on a
 * real box as 100/hour anonymous and 200/hour signed in, shared with the
 * user's own pulls either way. Which is exactly why this file uses Docker Hub
 * for ONE image and no more. Every other registry here sends no such header,
 * so a local tally is the only way to know what has been spent.
 *
 * Prints one line per case, ends with a per-registry summary table, and
 * exits non-zero only on a genuine failure — never on a skip. */

if (getenv('STAXX_QUIRKS') !== '1') {
  echo "SKIP   this reaches out to nine real public registries over the network.\n";
  echo "       Run it with:  STAXX_QUIRKS=1 php /tmp/registry_quirks.php\n";
  exit(0);
}

require_once '/usr/local/emhttp/plugins/staxx/include/Updates.php';

$fails = 0;
$skips = 0;
$passes = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails, $passes;
  $pass ? $passes++ : $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}
function note(string $what): void {
  echo "note   $what\n";
}
function skip(string $what, string $reason): void {
  global $skips;
  $skips++;
  printf("%-6s %s  (%s)\n", 'SKIP', $what, $reason);
}

$requests = 0;
function spend(int $n): void { global $requests; $requests += $n; }

/* ======================================================================= *
 * One registry's worth of cases, steps 1-7 of PLAN_92's list, in order.
 * Returns a summary row for the closing table. $host is what the compose
 * file would name; $image is the full reference staxx_registry_ref() must
 * split back to that same host.
 * ======================================================================= */
function check_registry(string $label, string $host, string $image): array {
  $row = [
    'registry' => $label, 'challenge' => '?', 'token' => '?',
    '304' => '?', 'ratelimit' => '?', 'digest_match' => '?', 'digest_from' => '?',
  ];

  // --- 1. reference splits to the right host, never rewritten to Hub ---
  $ref = staxx_registry_ref($image);
  ok("$label: reference splits to host $host",
     $ref['host'] === $host, 'got '.var_export($ref['host'], true));
  if ($ref['repo'] === '') {
    ok("$label: repository parsed at all", false, $image);
    $row['challenge'] = 'parse failed';
    return $row;
  }
  if ($host !== 'docker.io') {
    ok("$label: host is never rewritten to docker.io",
       $ref['host'] !== 'docker.io', json_encode($ref));
  }
  $repo = $ref['repo'];
  $tag  = $ref['tag'];

  // --- 2. the challenge is understood or absent, and which is recorded ---
  $chWhy = '';
  spend(1);
  $challenge = staxx_registry_challenge($host, $chWhy);
  if ($chWhy === 'failed') {
    skip("$label: everything past the challenge probe",
         'the registry did not answer /v2/ at all — treated as an outage, not a code fault');
    $row['challenge'] = 'unreachable';
    return $row;
  }
  if ($chWhy === 'auth') {
    // None of the nine registries on this list are expected to demand a
    // non-bearer scheme (Basic, digest, ...) for an anonymous read. Recorded
    // rather than pushed through the rest of the flow, since the token and
    // digest helpers below only speak the bearer/open shapes.
    note("$label: challenge uses a scheme StaXX does not speak (recorded, not chased further)");
    $row['challenge'] = 'unsupported';
    return $row;
  }
  $row['challenge'] = !empty($challenge['open']) ? 'none' : 'bearer';
  note("$label: challenge is ".($row['challenge'] === 'none'
       ? 'absent — an anonymous read needs no token at all'
       : 'a bearer challenge (realm '.$challenge['realm'].')'));

  // --- 3. a token is obtained where one is needed ---
  // THE GHCR/CODEBERG REGRESSION GUARD. staxx_registry_token() builds its
  // scope from the repository actually being asked about and deliberately
  // ignores whatever repository the challenge itself names. ghcr.io answers
  // /v2/ with a placeholder scope, and Gitea's registry (which codeberg.org
  // runs) is the same shape of implementation. Undo that fix — build the
  // scope from the challenge's own placeholder instead — and these two cases
  // are the ones that go red: the token comes back scoped to the wrong
  // repository, the manifest request is refused, and staxx_registry_digest()
  // reports 'auth' instead of a digest.
  if ($row['challenge'] === 'bearer') {
    $tokWhy = '';
    spend(1);
    $token = staxx_registry_token($host, $repo, $tokWhy);
    ok("$label: a bearer token is obtained for the repository actually asked about",
       $tokWhy === '' && $token !== '', 'why '.var_export($tokWhy, true));
    $row['token'] = ($tokWhy === '' && $token !== '') ? 'yes' : 'FAILED';
    if ($tokWhy !== '') {
      skip("$label: everything past the token fetch", "token fetch reported '$tokWhy'");
      return $row;
    }
  } else {
    $row['token'] = 'n/a';
  }

  // --- 4. a header-only manifest request answers 200 with an index digest ---
  $digWhy = '';
  spend(1);
  $first = staxx_registry_digest($host, $repo, $tag, '', $digWhy);
  if ($digWhy === 'notfound') {
    skip("$label: the manifest request and everything after it",
         "tag '$tag' on $image was not found — it may have been retagged or withdrawn; "
        .'the fixture image needs revisiting, this is not a code fault');
    $row['digest_match'] = 'skip';
    return $row;
  }
  if ($digWhy === 'limited') {
    skip("$label: the manifest request and everything after it",
         'the registry answered 429 (rate limited) — an outage of allowance, not of code');
    $row['digest_match'] = 'skip';
    return $row;
  }
  // A NO-ANSWER, not a well-formed refusal: staxx_registry_digest() sets
  // 'failed' whenever the request itself never completed (empty curl output,
  // or a status it does not otherwise recognise) — unlike 'notfound' and
  // 'limited' above, this is not the registry telling us something, it is us
  // hearing nothing at all. Ask the docker CLI the same question before
  // deciding what that means: if docker also gets nothing, this is the
  // outage the rest of the suite already forgives; if docker succeeds, the
  // registry is plainly reachable and StaXX's own route is the one that
  // could not manage it — exactly the ghcr-shaped bug this suite exists to
  // catch, so that stays a hard FAIL. Fetched here, once, so step 5 below
  // (which needs the very same CLI answer for a working digest) never has to
  // ask twice.
  if ($digWhy === 'failed') {
    $code = 1;
    $cli = staxx_sh(
      staxx_docker_bin().' buildx imagetools inspect '.escapeshellarg($image)
        .' --format '.escapeshellarg('{{json .Manifest}}').' 2>&1',
      20, $code
    );
    $cliData = $code === 0 ? json_decode($cli, true) : null;
    $cliDigest = is_array($cliData) ? (string)($cliData['digest'] ?? '') : '';
    if ($cliDigest !== '') {
      ok("$label: header-only manifest request answers 200 with a well-formed index digest", false,
         "StaXX got no answer at all, but docker buildx imagetools inspect reached $host fine — "
        .'this is StaXX\'s own route failing, not a registry hiccup');
      $row['digest_match'] = 'FAILED';
    } else {
      skip("$label: everything past the manifest request",
           "neither StaXX nor docker could reach $host — the registry, this box's network, or the "
          .'docker CLI itself, not the plugin');
      $row['digest_match'] = 'unreachable';
    }
    return $row;
  }
  ok("$label: header-only manifest request answers 200 with a well-formed index digest",
     ($first['status'] ?? 0) === 200
       && preg_match('/^sha256:[0-9a-f]{64}$/', (string)($first['digest'] ?? '')),
     'status '.($first['status'] ?? 0).' why '.var_export($digWhy, true));
  if (($first['status'] ?? 0) !== 200) {
    skip("$label: everything after the manifest request", "status ".($first['status'] ?? 0)." why '$digWhy'");
    return $row;
  }
  // staxx_registry_digest() marks a digest 'computed' only when it had to
  // fall back to hashing the manifest body itself — public.ecr.aws sends
  // no docker-content-digest header at all, unlike the other eight.
  $row['digest_from'] = (($first['digestFrom'] ?? '') === 'computed') ? 'computed (no header)' : 'header';

  // --- 5. that digest matches docker buildx imagetools inspect, or skip ---
  $code = 1;
  $cli = staxx_sh(
    staxx_docker_bin().' buildx imagetools inspect '.escapeshellarg($image)
      .' --format '.escapeshellarg('{{json .Manifest}}').' 2>&1',
    20, $code
  );
  $cliData = $code === 0 ? json_decode($cli, true) : null;
  $cliDigest = is_array($cliData) ? (string)($cliData['digest'] ?? '') : '';
  if ($code !== 0 || $cliDigest === '') {
    skip("$label: digest matches docker buildx imagetools inspect",
         'the docker CLI is unavailable, or refused this image, on this box');
    $row['digest_match'] = 'skip';
  } else {
    $match = strtolower((string)$first['digest']) === strtolower($cliDigest);
    ok("$label: digest is byte-identical to docker buildx imagetools inspect",
       $match, $first['digest'].' vs '.$cliDigest);
    $row['digest_match'] = $match ? 'yes' : 'NO';
  }

  // --- 6. a conditional re-ask with the stored entity tag ---
  $etag = (string)($first['etag'] ?? '');
  if ($etag === '') {
    skip("$label: the conditional re-ask", 'no entity tag was sent on the first answer to condition on');
    $row['304'] = 'no etag sent';
  } else {
    $secWhy = '';
    spend(1);
    $second = staxx_registry_digest($host, $repo, $tag, $etag, $secWhy);
    $status2 = $second['status'] ?? 0;
    if ($status2 === 304) {
      ok("$label: a re-ask with the stored entity tag answers 304", true);
      $row['304'] = 'yes';
    } elseif ($status2 === 200) {
      // Not a failure — several registries do not implement conditional
      // manifest requests at all, and StaXX already copes with a plain 200.
      ok("$label: a re-ask with the stored entity tag answers 200 (does not honour If-None-Match)", true);
      $row['304'] = 'no (200)';
    } else {
      ok("$label: the conditional re-ask answers something other than 304 or 200",
         false, 'status '.$status2.' why '.var_export($secWhy, true));
      $row['304'] = 'FAILED';
    }
  }

  // --- 7. rate-limit headers, where sent, parse to sane numbers ---
  if (isset($first['limit'])) {
    $lim = $first['limit'];
    $remaining = $lim['remaining'] ?? null;
    $limit = $lim['limit'] ?? null;
    $sane = is_int($remaining) && is_int($limit) && $remaining >= 0 && $limit >= 0 && $remaining <= $limit;
    ok("$label: rate-limit headers parse to sane numbers", $sane,
       'remaining '.var_export($remaining, true).' limit '.var_export($limit, true));
    $row['ratelimit'] = $sane ? 'yes' : 'FAILED';
  } else {
    note("$label: no rate-limit headers were sent (not every registry sends them)");
    $row['ratelimit'] = 'not sent';
  }

  return $row;
}

/* ======================================================================= *
 * The nine registries. Docker Hub gets exactly one image — its allowance is
 * the only tight one and is shared with the user's own pulls on this box.
 * ======================================================================= */
$registries = [
  ['label' => 'docker.io',         'host' => 'docker.io',         'image' => 'busybox:latest'],
  ['label' => 'ghcr.io',           'host' => 'ghcr.io',           'image' => 'ghcr.io/linuxserver/sonarr:latest'],
  ['label' => 'lscr.io',           'host' => 'lscr.io',           'image' => 'lscr.io/linuxserver/sonarr:latest'],
  ['label' => 'quay.io',           'host' => 'quay.io',           'image' => 'quay.io/prometheus/prometheus:latest'],
  ['label' => 'public.ecr.aws',    'host' => 'public.ecr.aws',    'image' => 'public.ecr.aws/aws-cli/aws-cli:latest'],
  ['label' => 'mcr.microsoft.com', 'host' => 'mcr.microsoft.com', 'image' => 'mcr.microsoft.com/dotnet/runtime:latest'],
  ['label' => 'registry.k8s.io',   'host' => 'registry.k8s.io',   'image' => 'registry.k8s.io/pause:3.9'],
  ['label' => 'codeberg.org',      'host' => 'codeberg.org',      'image' => 'codeberg.org/forgejo/forgejo:13.0'],
  // GitLab's own runner image, confirmed readable anonymously on this box
  // rather than assumed. It earns its place twice over: GitLab names a token
  // realm on a DIFFERENT HOST from the registry (gitlab.com for a manifest on
  // registry.gitlab.com), which nothing else here does, and its challenge
  // carries no scope at all — so it exercises both the cross-host realm and
  // the build-the-scope-from-the-repository rule in one go.
  ['label' => 'registry.gitlab.com', 'host' => 'registry.gitlab.com',
   'image' => 'registry.gitlab.com/gitlab-org/gitlab-runner:latest'],
];

$summary = [];
foreach ($registries as $r) {
  $summary[] = check_registry($r['label'], $r['host'], $r['image']);
}

/* ======================================================================= *
 * Once, not per registry: labels come from the registry actually named, and
 * staxx_hub_repo_path() still rewrites the same reference to Hub — the
 * contrast IS the point, per PLAN_92's decision log. The digest route
 * (staxx_registry_digest / staxx_registry_ref) must never be asked of Hub for
 * a ghcr image; the form editor's config-reading route (staxx_hub_repo_path)
 * must ALWAYS rewrite one to Hub. Both must be true at once, or the fix
 * documented in PLAN_92's decision log has regressed.
 * ======================================================================= */
$labelImage = 'ghcr.io/linuxserver/sonarr:latest';
$labelRef = staxx_registry_ref($labelImage);
if ($labelRef['repo'] !== '') {
  spend(3);
  $labels = staxx_registry_labels($labelRef['host'], $labelRef['repo'], $labelRef['tag']);
  $version = (string)($labels['org.opencontainers.image.version'] ?? '');
  ok('a ghcr image\'s version label comes back from ghcr itself (staxx_registry_labels)',
     $version !== '', 'version '.var_export($version, true));
} else {
  ok('the ghcr reference used for the label check parses at all', false, $labelImage);
}
$hubPath = staxx_hub_repo_path($labelImage);
ok('...while staxx_hub_repo_path() still rewrites that SAME reference to Hub for the form editor',
   $hubPath === 'linuxserver/sonarr', 'got '.var_export($hubPath, true));
note('the contrast above is deliberate: the digest/label route asks ghcr because that is where the '
   .'image actually lives; the form-editor route asks Hub on purpose, because Hub mirrors '
   .'linuxserver\'s config and that is what staxx_registry_config() is built to read. Both are '
   .'correct for what they are used for, and confusing them is the bug PLAN_92\'s decision log fixed.');

/* ======================================================================= *
 * The summary table — this is half the point of the file. A quirk written
 * down here is a documented fact instead of a bug report six months from now.
 * ======================================================================= */
echo "\n";
printf("%-18s %-10s %-6s %-10s %-10s %-13s %-22s\n",
  'registry', 'challenge', 'token', '304', 'ratelimit', 'digest==CLI', 'digest from');
echo str_repeat('-', 18 + 1 + 10 + 1 + 6 + 1 + 10 + 1 + 10 + 1 + 13 + 1 + 22)."\n";
foreach ($summary as $row) {
  printf("%-18s %-10s %-6s %-10s %-10s %-13s %-22s\n",
    $row['registry'], $row['challenge'], $row['token'], $row['304'], $row['ratelimit'], $row['digest_match'],
    $row['digest_from'] ?? '?');
}

note('network cost of this run: at most '.$requests.' requests split across nine registries, plus a '
   .'few more spent by the docker CLI comparisons (which cost nothing against a registry\'s own pull '
   .'ceiling). Docker Hub is asked about exactly one image, against the ceiling it reports in the '
   .'table above — shared with any real pulls on this box.');

echo "\n".$passes.' passed, '.$fails.' FAILED, '.$skips.' skipped'."\n";
exit($fails ? 1 : 0);
