<?php
/* PLAN_90 Stages 1 and 2 — the registry conversation, against REAL
 * registries.
 *
 * The companion file, tests/server/updateeconomy.php, is deliberately
 * offline: this project keeps no stub of a registry's HTTP replies (see
 * that file's own header, and tests/server/watch.php's identical note
 * about GitHub), so anything that can only be proven against a real 200
 * turning into a real 304, or a real challenge turning into a real bearer
 * token, belongs here instead. This file is that — it is a LIVE check that
 * talks to Docker Hub, GHCR and the linuxserver mirror, over the network,
 * from this box.
 *
 * That is why it is OPT-IN. It refuses to run unless STAXX_LIVE_REGISTRY=1
 * is set in the environment, so nobody runs it by accident as part of a
 * sweep of the server suites.
 *
 *     pscp tests/server/registry_live.php root@<box>:/tmp/
 *     plink … 'STAXX_LIVE_REGISTRY=1 php /tmp/registry_live.php'
 *
 * Needs NO config keys at all — no STACK_ROOT, no ARCHIVE_ROOT, no
 * STAXX_UPDATE_STATE. It creates no stacks, writes no state file and never
 * calls staxx_update_state_save() or any other function that records
 * anything. Every question it asks is read-only — a header-only manifest
 * request or a token fetch — and it never runs `docker pull`, `docker run`
 * or anything else that changes what is on this box. `docker buildx
 * imagetools inspect` and `docker manifest inspect`, used below to get an
 * independent answer to compare against, are themselves read-only.
 *
 * Because it depends on external registries staying as they are, a failure
 * here may mean a registry, or the image it was asked about, changed —
 * a tag retagged or removed, a registry's challenge shape updated — rather
 * than the code being wrong. Check the registry before chasing a bug in
 * the plugin.
 *
 * THE RATE CEILING. Docker Hub allows 100 pulls an hour, unauthenticated,
 * per IP — and PLAN_90's own step zero measured that a 304 costs exactly
 * as much as a 200 against that ceiling, so a conditional request is not
 * free. This file's own asks (not counting the docker CLI comparisons,
 * which spend nothing against Hub's pull ceiling) are counted as they go
 * and printed at the end, and are kept deliberately small: at most a
 * handful of token fetches and manifest requests, not a sweep. GHCR's own
 * ceiling is separate and far more generous for anonymous pulls, but the
 * same counting discipline is kept anyway.
 *
 * Prints one line per case and exits non-zero on any failure. */

/* The gate comes BEFORE any require, so a machine that never opted in does
 * not even load the plugin. */
if (getenv('STAXX_LIVE_REGISTRY') !== '1') {
  echo "SKIP   this is a LIVE check that reaches out to real registries over the network.\n";
  echo "       Run it with:  STAXX_LIVE_REGISTRY=1 php /tmp/registry_live.php\n";
  exit(0);
}

require_once '/usr/local/emhttp/plugins/staxx/include/Updates.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}
function note(string $what): void {
  echo "note   $what\n";
}
function skip(string $what, string $reason): void {
  printf("%-6s %s  (%s)\n", 'SKIP', $what, $reason);
}

/* Every manifest/token request this file spends against a real registry,
 * counted as it goes. staxx_registry_digest() makes up to three requests of
 * its own on a cold cache — the challenge probe, the token fetch, and the
 * manifest request itself — so 3 is charged per call here, the worst case,
 * which is the number that matters against a shared allowance. The token is
 * cached per host+repo for the life of the process, so a second call
 * against the same repository is cheaper than this in practice; the count
 * printed at the end is deliberately the pessimistic one. */
$requests = 0;
function live_digest(string $host, string $repo, string $tag, string $etag, string &$why = null): array {
  global $requests;
  $requests += 3;
  return staxx_registry_digest($host, $repo, $tag, $etag, $why);
}

/* ======================================================================= *
 * A — a real 304: ask once, keep the entity tag, ask again with it.
 * ======================================================================= */

$refA = staxx_registry_ref('postgres:latest');
ok('reference parses to docker.io / library/postgres / latest',
   $refA['host'] === 'docker.io' && $refA['repo'] === 'library/postgres' && $refA['tag'] === 'latest',
   json_encode($refA));

$whyFirst = '';
$first = live_digest($refA['host'], $refA['repo'], $refA['tag'], '', $whyFirst);
ok('first ask against a real, unchanged tag answers 200 with a digest',
   ($first['status'] ?? 0) === 200 && preg_match('/^sha256:[0-9a-f]{64}$/', (string)($first['digest'] ?? '')),
   json_encode($first));
ok('...and carries a non-empty entity tag to remember',
   (string)($first['etag'] ?? '') !== '', json_encode($first));

if (($first['status'] ?? 0) === 200 && (string)($first['etag'] ?? '') !== '') {
  $whySecond = '';
  $second = live_digest($refA['host'], $refA['repo'], $refA['tag'], $first['etag'], $whySecond);
  ok('the same ask, WITH the stored entity tag, comes back 304 — the mechanism Stage 2 depends on',
     ($second['status'] ?? 0) === 304, json_encode($second));
  ok('...and a 304 carries no digest of its own (no body to read one from)',
     (string)($second['digest'] ?? '') === '', json_encode($second));
} else {
  skip('the conditional re-ask', 'the first ask did not return a usable 200+etag to condition on');
}

/* ======================================================================= *
 * B — the digest matches what the docker CLI reports, for one Hub image,
 * one ghcr image and one lscr image. Skipped cleanly, per image, when the
 * CLI itself is unavailable — a missing fixture is not a fault in the code.
 * ======================================================================= */

function live_cli_digest(string $image): ?string {
  $code = 1;
  $out = staxx_sh(
    staxx_docker_bin().' buildx imagetools inspect '.escapeshellarg($image)
      .' --format '.escapeshellarg('{{json .Manifest}}').' 2>&1',
    20, $code
  );
  if ($code !== 0) return null;
  $data = json_decode($out, true);
  $digest = is_array($data) ? (string)($data['digest'] ?? '') : '';
  return $digest !== '' ? $digest : null;
}

$compareCases = [
  'Docker Hub' => 'postgres:latest',
  'ghcr'       => 'ghcr.io/linuxserver/sonarr:latest',
  'lscr'       => 'lscr.io/linuxserver/sonarr:latest',
];

foreach ($compareCases as $label => $image) {
  $ref = staxx_registry_ref($image);
  if ($ref['repo'] === '') {
    ok("$label: reference parses at all", false, $image);
    continue;
  }

  $cliDigest = live_cli_digest($image);
  if ($cliDigest === null) {
    skip("$label ($image): digest matches the docker CLI's own answer",
         'docker buildx imagetools inspect is unavailable or refused on this box for this image');
    continue;
  }

  $why = '';
  $answer = live_digest($ref['host'], $ref['repo'], $ref['tag'], '', $why);
  if (($answer['status'] ?? 0) !== 200) {
    ok("$label ($image): got a usable answer to compare against the CLI", false,
       'status '.($answer['status'] ?? 0).' why '.$why);
    continue;
  }

  ok("$label ($image): staxx_registry_digest()'s answer matches docker buildx imagetools inspect exactly",
     strtolower((string)$answer['digest']) === strtolower($cliDigest),
     $answer['digest'].' vs '.$cliDigest);
}

/* ======================================================================= *
 * C — a ghcr-hosted image is asked of ghcr, never of Hub.
 * ======================================================================= */

$ghcrRef = staxx_registry_ref('ghcr.io/linuxserver/sonarr:latest');
ok('a ghcr-hosted reference names ghcr.io as its host, not docker.io',
   $ghcrRef['host'] === 'ghcr.io', json_encode($ghcrRef));

$ghcrWhy = '';
$ghcrAnswer = live_digest('ghcr.io', $ghcrRef['repo'], $ghcrRef['tag'], '', $ghcrWhy);
ok('...and asking staxx_registry_digest() with that host actually reaches ghcr — a real digest comes back',
   ($ghcrAnswer['status'] ?? 0) === 200 && preg_match('/^sha256:[0-9a-f]{64}$/', (string)($ghcrAnswer['digest'] ?? '')),
   json_encode($ghcrAnswer));
note('this is asked of the ghcr.io API host directly — nothing in this file ever passes '
   . '"docker.io" for a ghcr or lscr reference, which is the live confirmation that waste #3 '
   . '(asking the wrong registry) cannot happen at the transport level tested here');

/* ---------------------------------------------------------------------- */

note('network cost of this run: at most '.$requests.' requests, split between Docker Hub '
   . '(ceiling 100/hour unauthenticated, shared with any real pulls on this box) and ghcr/lscr '
   . '(a separate, more generous ceiling). The docker-CLI comparisons in section B spend nothing '
   . "against either registry's pull allowance.");

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
