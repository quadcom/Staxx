<?php
/* PLAN_92 Stage 2 / PLAN_92a Part B — self-hosted registries, proved on the
 * real box because that is the only place one can exist.
 *
 * THIS RUNS ON YOUR PRODUCTION UNRAID SERVER AND STARTS CONTAINERS. That was
 * authorised explicitly for this stage, with that consequence spelled out —
 * see PLAN_92a. It pulls two small images (registry:2, ~25MB, and zot,
 * ~20MB — roughly 45MB total) and removes both at the end. It is the only
 * suite in this folder that pulls anything at all. Every other rule about
 * this box still stands: NOTHING OF THE USER'S is started, stopped, altered
 * or removed. All three throwaway registries are bound to 127.0.0.1 only,
 * on high ports (45000-45002), with no volume and no bind mount, so nothing
 * is reachable from the network and nothing lands on a share. Their names
 * (staxx-selfhosted-*) cannot collide with anything a person would name a
 * real container. Teardown runs from a shutdown function — see below — and
 * PROVES its own cleanup rather than assuming it.
 *
 * Needs REGISTRY_TRUST set, in the REAL config, to exactly the three
 * loopback addresses below. THE REAL CONFIG IS THE STORE'S HALF, not the
 * flash one: staxx_cfg() layers <STORE_ROOT>/config/staxx.cfg over
 * /boot/config/plugins/staxx/staxx.cfg, and the store's copy already holds
 * an empty REGISTRY_TRUST that wins over anything seeded on flash — which
 * is exactly how a run on 2026-09-02 aborted on the first line. Read
 * STORE_ROOT off the flash file, then seed the store's file — a throwaway registry started for a few minutes
 * has no certificate, so this is PLAN_92a Part A's opt-in put to use. Same
 * idiom as tests/server/record.php: seed it before PHP starts (staxx_cfg()
 * memoises on first read, so changing the file mid-run is too late), and
 * put it back after. staxx_cfg() reads the file this script cannot pass an
 * override for, unlike STORE_ROOT — there is no key here that
 * substitutes for it.
 *
 *     pscp tests/server/registry_selfhosted.php root@<box>:/tmp/
 *     plink … '
 *       ROOT=$(sed -n "s/^STORE_ROOT=\"\(.*\)\"/\1/p" /boot/config/plugins/staxx/staxx.cfg)
 *       CFG=$ROOT/config/staxx.cfg
 *       cp $CFG /tmp/staxx-cfg.bak
 *       grep -q "^REGISTRY_TRUST=" $CFG \
 *         && sed -i "s#^REGISTRY_TRUST=.*#REGISTRY_TRUST=\"127.0.0.1:45000,127.0.0.1:45001,127.0.0.1:45002\"#" $CFG \
 *         || echo "REGISTRY_TRUST=\"127.0.0.1:45000,127.0.0.1:45001,127.0.0.1:45002\"" >> $CFG
 *       STAXX_SELFHOSTED=1 STAXX_SELFHOSTED_JSON=/tmp/selfhosted.json php /tmp/registry_selfhosted.php; RC=$?
 *       cp /tmp/staxx-cfg.bak $CFG
 *       diff -q /tmp/staxx-cfg.bak $CFG && echo CONFIG_IDENTICAL
 *       exit $RC
 *     '
 *
 * STAXX_SELFHOSTED_JSON is optional and off by default — unset, this suite
 * writes nothing anywhere. Set to a path, it also dumps the summary rows as
 * JSON, which feeds tests/registry_note.js to regenerate
 * tests/server/REGISTRY-BEHAVIOUR.md.
 *
 * The update-state file this suite necessarily writes to (a blocked-host
 * note, PLAN_90's per-host memory) is redirected to /tmp via the same
 * env-override Updates.php already offers for exactly this purpose — the
 * real updates.json on the flash drive is never opened.
 *
 * Test content is built ON THE BOX from busybox, already present on every
 * Unraid install — no pull for it, and if it is somehow missing this prints
 * a SKIP and exits rather than pulling it. The image carries a deliberately
 * absurd version label, 9.9.9-selfhosted, that no real registry could ever
 * serve — reading it back through StaXX's own code is the proof that a
 * question about this image went to the throwaway registry named in the
 * file and nowhere else, Docker Hub included.
 *
 * Discipline matches tests/server/registry_quirks.php: one line per case, a
 * closing per-registry summary table, and exit non-zero only on a genuine
 * failure. A registry that never came up, or a docker/curl command that is
 * unavailable, is a SKIP naming what was missing — never a FAIL. */

if (getenv('STAXX_SELFHOSTED') !== '1') {
  echo "SKIP   this starts three throwaway registry containers on this box (~45MB pulled, removed after).\n";
  echo "       Run it with:  STAXX_SELFHOSTED=1 php /tmp/registry_selfhosted.php\n";
  echo "       Needs REGISTRY_TRUST set first — see the header comment for the exact commands.\n";
  exit(0);
}

// Redirect the update-state file BEFORE Updates.php is ever required, same
// trick tests/server/autostart.php uses for STAXX_AUTOSTART_FILE — the real
// one on the flash drive must never be opened by this run.
putenv('STAXX_UPDATE_STATE=/tmp/staxx-selfhosted-updates.json');
@unlink('/tmp/staxx-selfhosted-updates.json');

require_once '/usr/local/emhttp/plugins/staxx/include/Updates.php';

if (!staxx_registry_trusted('127.0.0.1:45000') || !staxx_registry_trusted('127.0.0.1:45001')
    || !staxx_registry_trusted('127.0.0.1:45002')) {
  echo "FAIL   REGISTRY_TRUST is not set to the three loopback addresses this suite needs.\n";
  echo "       Expected: 127.0.0.1:45000,127.0.0.1:45001,127.0.0.1:45002 — see the header comment.\n";
  exit(1);
}

$docker = staxx_docker_bin();
$dcode = 1;
staxx_sh($docker.' version --format {{.Server.Version}}', 8, $dcode);
if ($dcode !== 0) {
  echo "SKIP   docker is not reachable on this box — nothing here can run.\n";
  exit(0);
}

$fails = 0;
$skips = 0;
$passes = 0;
$requests = 0;
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
function spend(int $n): void { global $requests; $requests += $n; }

/* ======================================================================= *
 * Teardown state and the shutdown function. Registered BEFORE anything is
 * created, and mutated by reference as setup proceeds, so a failure or a
 * fatal error mid-setup still leaves nothing behind — PLAN_92's own trap
 * about a leaked container being worse than no suite at all.
 * ======================================================================= */
$created = ['containers' => [], 'images' => [], 'tags' => [], 'dirs' => []];

$baselineCode = 1;
$baselineOut = staxx_sh($docker.' images -q', 10, $baselineCode);
$baselineCount = $baselineCode === 0
  ? count(array_unique(array_filter(explode("\n", trim($baselineOut)))))
  : null;

register_shutdown_function(function () use (&$created, $docker, $baselineCount) {
  echo "\n-- teardown --\n";
  foreach ($created['containers'] as $name) {
    staxx_sh($docker.' rm -f '.escapeshellarg($name).' >/dev/null 2>&1', 15);
    $code = 1;
    staxx_sh($docker.' inspect '.escapeshellarg($name).' >/dev/null 2>&1', 8, $code);
    ok("teardown: container $name is gone", $code !== 0);
  }
  foreach ($created['tags'] as $tag) {
    staxx_sh($docker.' rmi -f '.escapeshellarg($tag).' >/dev/null 2>&1', 15);
  }
  foreach ($created['images'] as $img) {
    staxx_sh($docker.' rmi -f '.escapeshellarg($img).' >/dev/null 2>&1', 20);
  }
  foreach ($created['dirs'] as $dir) {
    if (is_dir($dir)) staxx_sh('rm -rf '.escapeshellarg($dir), 8);
  }
  // Unconditional, because the push path signs in and a run that died between
  // the login and the logout would otherwise leave an entry for a registry
  // that no longer exists sitting in the user's own docker config.
  staxx_sh($docker.' logout 127.0.0.1:45001 >/dev/null 2>&1', 8);
  @unlink('/tmp/staxx-selfhosted-updates.json');

  if ($baselineCount === null) {
    skip('teardown: the box\'s own image count is unchanged', 'could not read the image list before setup began');
  } else {
    $code = 1;
    $out = staxx_sh($docker.' images -q', 10, $code);
    $now = $code === 0 ? count(array_unique(array_filter(explode("\n", trim($out))))) : null;
    if ($now === null) {
      skip('teardown: the box\'s own image count is unchanged', 'could not read the image list after teardown');
    } else {
      ok('teardown: the box\'s own image count matches what it was before setup',
         $now === $baselineCount, "before=$baselineCount after=$now");
    }
  }

  // The SOLE exit() call for this suite's normal and skip paths. Deciding
  // the exit code here, last, is deliberate: it is the only point that has
  // seen every assertion including the teardown proof above, so a leaked
  // container or a wrong image count is never masked by an exit code the
  // rest of the script had already committed to before teardown ran.
  global $fails, $passes, $skips, $summary;
  echo "\n".$passes.' passed, '.$fails.' FAILED, '.$skips.' skipped'."\n";

  /* ===================================================================== *
   * OPT-IN JSON dump, PLAN_92b Part A. Unset (the default), nothing is
   * written — this suite's "records nothing" property stays true even here.
   * This has to sit inside the shutdown function: the counts and $summary
   * are only final once every case (including teardown's own) has run, and
   * this closure is the sole exit() point for the whole suite. That also
   * means a run that dies part-way still dumps whatever rows it had — left
   * deliberate, because the non-zero fail count in that dump is exactly what
   * stops the generator treating a broken run as a clean one.
   * ===================================================================== */
  $jsonPath = getenv('STAXX_SELFHOSTED_JSON');
  if (is_string($jsonPath) && $jsonPath !== '') {
    // $summary is assigned well after this closure is registered, so a fatal
    // during setup reaches here with it never having existed at all.
    $rows = is_array($summary ?? null) ? $summary : [];
    $dump = [
      'suite'   => 'registry_selfhosted.php',
      'date'    => date('Y-m-d'),
      'passes'  => $passes,
      'fails'   => $fails,
      'skips'   => $skips,
      'rows'    => (object)$rows,
    ];
    $written = @file_put_contents($jsonPath, json_encode($dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    if ($written === false) {
      note("could not write the JSON dump to $jsonPath — a clean run without a note is still a clean run");
    } else {
      note("wrote JSON dump to $jsonPath");
    }
  }

  exit($fails ? 1 : 0);
});

/* ======================================================================= *
 * Small helpers used by setup and by more than one registry's assertions.
 * ======================================================================= */

/** Poll a URL until it answers with any HTTP status, or give up. */
function wait_up(string $url, int $maxSeconds): int {
  $deadline = time() + $maxSeconds;
  do {
    $code = 1;
    $out = staxx_sh('curl -sS -o /dev/null -w %{http_code} --max-time 3 '.escapeshellarg($url), 6, $code);
    $status = (int)trim($out);
    if ($status > 0) return $status;
    usleep(500000);
  } while (time() < $deadline);
  return 0;
}

/** Which manifest shape the Content-Type header names, for the summary table. */
function manifest_shape(string $host, string $repo, string $tag, string $token): string {
  $headers = ['Accept: '.staxx_registry_accept()];
  if ($token !== '') $headers[] = 'Authorization: Bearer '.$token;
  $cmd = 'curl -sS -o /dev/null -D /dev/stdout --max-time 6 -X HEAD';
  foreach ($headers as $h) $cmd .= ' -H '.escapeshellarg($h);
  $cmd .= ' '.escapeshellarg('http://'.$host.'/v2/'.$repo.'/manifests/'.rawurlencode($tag));
  $out = staxx_sh($cmd, 8);
  if (!preg_match('/^content-type:\s*(.+)$/im', $out, $m)) return 'unknown';
  $ct = strtolower(trim($m[1]));
  if (strpos($ct, 'oci.image.index') !== false) return 'OCI index';
  if (strpos($ct, 'manifest.list') !== false) return 'Docker manifest list';
  if (strpos($ct, 'oci.image.manifest') !== false) return 'OCI manifest (single)';
  if (strpos($ct, 'manifest.v2') !== false) return 'Docker manifest (single)';
  return $ct;
}

/* ======================================================================= *
 * Setup: the two pulls this whole suite makes.
 * ======================================================================= */

$bbCode = 1;
staxx_sh($docker.' image inspect busybox:1.36 --format {{.Id}}', 10, $bbCode);
if ($bbCode !== 0) {
  skip('the whole suite', 'busybox:1.36 is not present locally, and this suite never pulls it deliberately');
  exit(0);
}

/* An image is only ever queued for removal if THIS RUN is what put it on the
 * box. `docker pull` succeeds just as happily when the image is already
 * there, so recording it unconditionally would make teardown delete an image
 * the user had pulled themselves — the exact thing this suite promises never
 * to do. Asked before the pull, because afterwards the two cases are
 * indistinguishable. */
$pull = function (string $image, int &$code) use ($docker, &$created): void {
  $had = 1;
  staxx_sh($docker.' image inspect '.escapeshellarg($image).' >/dev/null 2>&1', 10, $had);
  $code = 1;
  staxx_sh($docker.' pull '.escapeshellarg($image), 60, $code);
  if ($code === 0 && $had !== 0) $created['images'][] = $image;
  if ($code === 0 && $had === 0) note($image.' was already on this box — it will be left exactly where it is');
};

$pullCode = 1;
$pull('registry:2', $pullCode);
if ($pullCode !== 0) skip('registry:2 pull', 'could not pull registry:2 — a registry hiccup, not a code fault');

$zotImage = 'ghcr.io/project-zot/zot-linux-amd64:latest';
$zotCode = 1;
$pull($zotImage, $zotCode);
if ($zotCode !== 0) skip('zot pull', 'could not pull the zot image — a registry hiccup, not a code fault');

/* Build the test content image once, locally, from busybox:1.36 already present.
 * The tag is exact, never :latest — asking for a tag this box does not
 * hold would turn a no-network build into a pull.
 * No network involved — --pull=false stops docker reaching for a newer
 * busybox tag it does not need. Only attempted when at least one of the two
 * pulls above actually landed — otherwise there is nothing for it to be
 * pushed to and this would just be an unused local image. */
$buildCode = 1;
$contentLocal = 'staxx-selfhosted-content:local';
if ($pullCode === 0 || $zotCode === 0) {
  $buildDir = sys_get_temp_dir().'/staxx-selfhosted-build-'.getmypid();
  @mkdir($buildDir, 0700, true);
  $created['dirs'][] = $buildDir;
  $dockerfile = "FROM busybox:1.36\n"
    ."LABEL org.opencontainers.image.version=\"9.9.9-selfhosted\" \\\n"
    ."      org.opencontainers.image.created=\"2026-01-01T00:00:00Z\" \\\n"
    ."      org.opencontainers.image.source=\"https://example.invalid/staxx-selfhosted-test\" \\\n"
    ."      org.opencontainers.image.revision=\"0000000000000000000000000000000000000000\"\n";
  file_put_contents($buildDir.'/Dockerfile', $dockerfile);

  staxx_sh($docker.' build --pull=false -t '.escapeshellarg($contentLocal)
    .' -f '.escapeshellarg($buildDir.'/Dockerfile').' '.escapeshellarg($buildDir), 30, $buildCode);
  if ($buildCode === 0) $created['images'][] = $contentLocal;
  ok('the labelled test image built from local busybox, no network', $buildCode === 0);
}
if ($buildCode !== 0) {
  skip('every registry assertion', 'no local registry was pulled, or the local test image failed to build');
}

$repo = 'staxx-test/content';
$tag  = '9.9.9-selfhosted';

/* ======================================================================= *
 * Open registry:2, port 45000.
 * ======================================================================= */
$summary = [];
$openHost = '127.0.0.1:45000';
if ($pullCode === 0 && $buildCode === 0) {
  staxx_sh($docker.' run -d --name staxx-selfhosted-open -p 127.0.0.1:45000:5000 registry:2', 20, $rc);
  $created['containers'][] = 'staxx-selfhosted-open';
  $status = wait_up('http://'.$openHost.'/v2/', 20);
  if ($status !== 200) {
    skip('open registry: everything', "the container never answered /v2/ (status $status) within 20 seconds");
  } else {
    $openRef = $openHost.'/'.$repo.':'.$tag;
    $tagCode = 1;
    staxx_sh($docker.' tag '.escapeshellarg($contentLocal).' '.escapeshellarg($openRef), 8, $tagCode);
    if ($tagCode === 0) $created['tags'][] = $openRef;
    $pushCode = 1;
    staxx_sh($docker.' push '.escapeshellarg($openRef), 20, $pushCode);
    ok('open registry: test content pushed', $pushCode === 0);

    if ($pushCode === 0) {
      $summary['open'] = check_open_style('open registry:2', $openHost, $openRef, $repo, $tag);
    }
  }
} else {
  skip('open registry: everything', 'registry:2 could not be pulled, or the test image could not be built');
}

/* ======================================================================= *
 * Password-protected registry:2, port 45001 — the valuable one.
 * ======================================================================= */
$authHost = '127.0.0.1:45001';
if ($pullCode === 0 && $buildCode === 0) {
  $authUser = 'staxxtest';
  $authPass = bin2hex(random_bytes(9));
  $hash = password_hash($authPass, PASSWORD_BCRYPT);
  $authDir = sys_get_temp_dir().'/staxx-selfhosted-auth-'.getmypid();
  @mkdir($authDir, 0700, true);
  $created['dirs'][] = $authDir;
  file_put_contents($authDir.'/htpasswd', $authUser.':'.$hash."\n");

  // The password file lands beside the registry's own config rather than in
  // a tidy /auth of its own: `docker cp` will not create a missing parent
  // directory, and with no bind mount allowed there is nothing else to make
  // one. /etc/docker/registry already exists in the image. Measured on the
  // box — copying to /auth/htpasswd fails outright.
  staxx_sh($docker.' create --name staxx-selfhosted-auth -p 127.0.0.1:45001:5000'
    .' -e REGISTRY_AUTH=htpasswd -e REGISTRY_AUTH_HTPASSWD_REALM='.escapeshellarg('StaXX self-test')
    .' -e REGISTRY_AUTH_HTPASSWD_PATH=/etc/docker/registry/htpasswd registry:2', 15, $rc);
  $created['containers'][] = 'staxx-selfhosted-auth';
  $cpCode = 1;
  staxx_sh($docker.' cp '.escapeshellarg($authDir.'/htpasswd')
    .' staxx-selfhosted-auth:/etc/docker/registry/htpasswd', 10, $cpCode);
  ok('auth registry: htpasswd copied into the (not yet started) container', $cpCode === 0);

  if ($cpCode === 0) {
    staxx_sh($docker.' start staxx-selfhosted-auth', 10, $rc);
    $status = wait_up('http://'.$authHost.'/v2/', 20);
    if ($status !== 401) {
      skip('auth registry: everything', "expected a 401 challenge on /v2/, got status $status");
    } else {
      $authRef = $authHost.'/'.$repo.':'.$tag;
      $tagCode = 1;
      staxx_sh($docker.' tag '.escapeshellarg($contentLocal).' '.escapeshellarg($authRef), 8, $tagCode);
      if ($tagCode === 0) $created['tags'][] = $authRef;

      // Log in ONLY long enough to push the fixture, then log out — the
      // whole point of this registry is proving what happens when NEITHER
      // StaXX nor the docker CLI holds a credential for it.
      $pwFile = $authDir.'/pw';
      file_put_contents($pwFile, $authPass);
      $loginCode = 1;
      staxx_sh($docker.' login '.escapeshellarg($authHost).' -u '.escapeshellarg($authUser)
        .' --password-stdin < '.escapeshellarg($pwFile), 10, $loginCode);
      @unlink($pwFile);
      $pushCode = 1;
      if ($loginCode === 0) staxx_sh($docker.' push '.escapeshellarg($authRef), 20, $pushCode);
      staxx_sh($docker.' logout '.escapeshellarg($authHost), 8, $lo);
      ok('auth registry: test content pushed while logged in, then logged out again',
         $loginCode === 0 && $pushCode === 0);

      if ($loginCode === 0 && $pushCode === 0) {
        $summary['auth'] = check_auth_style($authHost, $authRef, $repo, $tag);
      }
    }
  }
} else {
  skip('auth registry: everything', 'registry:2 could not be pulled, or the test image could not be built');
}

/* ======================================================================= *
 * zot, port 45002 — a second, independent implementation.
 * ======================================================================= */
$zotHost = '127.0.0.1:45002';
if ($zotCode === 0 && $buildCode === 0) {
  staxx_sh($docker.' run -d --name staxx-selfhosted-zot -p 127.0.0.1:45002:5000 '
    .escapeshellarg($zotImage), 20, $rc);
  $created['containers'][] = 'staxx-selfhosted-zot';
  $status = wait_up('http://'.$zotHost.'/v2/', 20);

  // zot's shipped default did not permit an anonymous read (401/403) rather
  // than never coming up at all — swap in a minimal config that does,
  // rather than fighting the default one. Recreated via create/cp/start,
  // the same shape the password-protected registry above uses.
  if ($status === 401 || $status === 403) {
    note('zot: the shipped default does not permit anonymous reading — swapping in a minimal config');
    staxx_sh($docker.' rm -f staxx-selfhosted-zot >/dev/null 2>&1', 10);
    $zotDir = sys_get_temp_dir().'/staxx-selfhosted-zot-'.getmypid();
    @mkdir($zotDir, 0700, true);
    $created['dirs'][] = $zotDir;
    $zotConfig = '{"distSpecVersion":"1.1.0","storage":{"rootDirectory":"/tmp/zot"},'
      .'"http":{"address":"0.0.0.0","port":"5000"},"log":{"level":"warn"}}';
    file_put_contents($zotDir.'/config.json', $zotConfig);
    staxx_sh($docker.' create --name staxx-selfhosted-zot -p 127.0.0.1:45002:5000 '
      .escapeshellarg($zotImage), 15, $rc);
    staxx_sh($docker.' cp '.escapeshellarg($zotDir.'/config.json')
      .' staxx-selfhosted-zot:/etc/zot/config.json', 10, $cpCode);
    if ($cpCode === 0) {
      staxx_sh($docker.' start staxx-selfhosted-zot', 10, $rc);
      $status = wait_up('http://'.$zotHost.'/v2/', 20);
    } else {
      $status = 0;
    }
  }

  if ($status !== 200) {
    skip('zot: everything', "the container never answered /v2/ anonymously (status $status) within 20 seconds");
  } else {
    $zotRef = $zotHost.'/'.$repo.':'.$tag;
    $tagCode = 1;
    staxx_sh($docker.' tag '.escapeshellarg($contentLocal).' '.escapeshellarg($zotRef), 8, $tagCode);
    if ($tagCode === 0) $created['tags'][] = $zotRef;
    $pushCode = 1;
    staxx_sh($docker.' push '.escapeshellarg($zotRef), 20, $pushCode);
    ok('zot: test content pushed', $pushCode === 0);

    if ($pushCode === 0) {
      $summary['zot'] = check_open_style('zot', $zotHost, $zotRef, $repo, $tag);
    }
  }
} else {
  skip('zot: everything', 'zot could not be pulled, or the test image could not be built');
}

/* ======================================================================= *
 * The two shared assertion routines, defined after use above (PHP hoists
 * function declarations, so this is fine) — one for a registry with no
 * authentication at all (open registry:2 and zot both take this route),
 * one for the password-protected registry:2.
 * ======================================================================= */

function check_open_style(string $label, string $host, string $image, string $repo, string $tag): array {
  $row = ['registry' => $label, 'challenge' => '?', 'digest_match' => '?', '304' => '?', 'shape' => '?'];

  $ref = staxx_registry_ref($image);
  ok("$label: reference splits to host $host, never rewritten to docker.io",
     $ref['host'] === $host, 'got '.var_export($ref['host'], true));

  spend(1);
  $chWhy = '';
  $challenge = staxx_registry_challenge($host, $chWhy);
  ok("$label: the challenge is understood or absent, and which one is recorded",
     $chWhy === '', 'why '.var_export($chWhy, true));
  $row['challenge'] = !empty($challenge['open']) ? 'none' : 'bearer';

  spend(1);
  $digWhy = '';
  $first = staxx_registry_digest($host, $repo, $tag, '', $digWhy);
  ok("$label: a header-only manifest request answers 200 with a real index digest",
     ($first['status'] ?? 0) === 200 && preg_match('/^sha256:[0-9a-f]{64}$/', (string)($first['digest'] ?? '')),
     'status '.($first['status'] ?? 0).' why '.var_export($digWhy, true));

  $code = 1;
  $cli = staxx_sh(
    staxx_docker_bin().' buildx imagetools inspect '.escapeshellarg($image)
      .' --format '.escapeshellarg('{{json .Manifest}}').' 2>&1',
    20, $code
  );
  $cliData = $code === 0 ? json_decode($cli, true) : null;
  $cliDigest = is_array($cliData) ? (string)($cliData['digest'] ?? '') : '';
  if ($cliDigest === '') {
    skip("$label: digest matches docker buildx imagetools inspect",
         'the CLI could not reach this plain-http loopback registry, or is unavailable — '
        .'its own insecure-registry handling is separate from the daemon\'s');
    $row['digest_match'] = 'skip';
  } else {
    $match = strtolower((string)($first['digest'] ?? '')) === strtolower($cliDigest);
    ok("$label: digest is byte-identical to docker buildx imagetools inspect", $match,
       ($first['digest'] ?? '?').' vs '.$cliDigest);
    $row['digest_match'] = $match ? 'yes' : 'NO';
  }

  $etag = (string)($first['etag'] ?? '');
  if ($etag === '') {
    skip("$label: the conditional re-ask", 'no entity tag was sent on the first answer to condition on');
    $row['304'] = 'no etag';
  } else {
    spend(1);
    $secWhy = '';
    $second = staxx_registry_digest($host, $repo, $tag, $etag, $secWhy);
    $status2 = $second['status'] ?? 0;
    if ($status2 === 304) {
      ok("$label: a re-ask with the stored entity tag answers 304", true);
      $row['304'] = 'yes';
    } elseif ($status2 === 200) {
      ok("$label: a re-ask with the stored entity tag answers 200 (does not honour If-None-Match)", true);
      $row['304'] = 'no (200)';
    } else {
      ok("$label: the conditional re-ask answers something other than 304 or 200", false, 'status '.$status2);
      $row['304'] = 'FAILED';
    }
  }

  spend(3);
  $labels = staxx_registry_labels($host, $repo, $tag);
  $version = (string)($labels['org.opencontainers.image.version'] ?? '');
  // THE SINGLE MOST IMPORTANT ASSERTION IN THIS FILE. Docker Hub could not
  // possibly return "9.9.9-selfhosted" for any reference — so reading it
  // back proves the version question went to THIS registry and nowhere else.
  ok("$label: the version read back is 9.9.9-selfhosted, proving the question went to this "
    ."registry and not to Docker Hub", $version === '9.9.9-selfhosted', 'got '.var_export($version, true));

  spend(1);
  $tokWhy = '';
  $token = !empty($challenge['open']) ? '' : staxx_registry_token($host, $repo, $tokWhy);
  $row['shape'] = manifest_shape($host, $repo, $tag, $token);
  spend(1);

  return $row;
}

function check_auth_style(string $host, string $image, string $repo, string $tag): array {
  $row = ['registry' => 'auth registry:2', 'challenge' => '?', 'auth' => '?', 'never_ok' => '?', 'remembered' => '?'];

  spend(1);
  $chWhy = '';
  staxx_registry_challenge($host, $chWhy);
  ok('auth registry: the challenge is refused as auth, not as a plain failure', $chWhy === 'auth',
     'why '.var_export($chWhy, true));
  $row['challenge'] = $chWhy;

  spend(1);
  $tokWhy = '';
  $token = staxx_registry_token($host, $repo, $tokWhy);
  ok('auth registry: no bearer token is obtained, and the reason is auth — never a digest',
     $token === '' && $tokWhy === 'auth', 'token '.var_export($token, true).' why '.var_export($tokWhy, true));
  $row['auth'] = ($token === '' && $tokWhy === 'auth') ? 'yes' : 'FAILED';

  spend(1);
  $digWhy = '';
  $digest = staxx_registry_digest($host, $repo, $tag, '', $digWhy);
  ok('auth registry: the manifest request is refused as auth, never a digest',
     $digWhy === 'auth' && ($digest['digest'] ?? '') === '', 'why '.var_export($digWhy, true));

  // The real update path, end to end — the entry point that decides whether
  // to fall through to the docker CLI. Neither StaXX nor the docker CLI
  // holds a credential for this host (logged out straight after the push
  // above), so this is the worst case the "never up to date" guarantee has
  // to hold against.
  spend(1);
  $imgWhy = '';
  $tags = null;
  $result = staxx_image_remote($image, $imgWhy, $tags, []);
  ok('auth registry: staxx_image_remote() never returns a digest here — it can never be '
    .'reported "up to date" for a registry this box has no credentials for',
     !isset($result['digest']), 'result '.json_encode($result).' why '.var_export($imgWhy, true));
  $row['never_ok'] = !isset($result['digest']) ? 'yes' : 'FAILED';

  ok('auth registry: the refusal is remembered per host, not re-probed every pass',
     staxx_update_host_blocked($host));
  $row['remembered'] = staxx_update_host_blocked($host) ? 'yes' : 'FAILED';

  return $row;
}

/* ======================================================================= *
 * The summary table.
 * ======================================================================= */
echo "\n";
if (isset($summary['open']) || isset($summary['zot'])) {
  printf("%-18s %-10s %-13s %-12s %-22s\n", 'registry', 'challenge', 'digest==CLI', '304', 'manifest shape');
  echo str_repeat('-', 18 + 1 + 10 + 1 + 13 + 1 + 12 + 1 + 22)."\n";
  foreach (['open', 'zot'] as $k) {
    if (!isset($summary[$k])) continue;
    $r = $summary[$k];
    printf("%-18s %-10s %-13s %-12s %-22s\n", $r['registry'], $r['challenge'], $r['digest_match'], $r['304'], $r['shape']);
  }
  echo "\n";
}
if (isset($summary['auth'])) {
  $r = $summary['auth'];
  printf("%-18s challenge=%-6s auth=%-5s never-up-to-date=%-5s remembered=%-5s\n",
    $r['registry'], $r['challenge'], $r['auth'], $r['never_ok'], $r['remembered']);
}

note('network cost of this run: at most '.$requests.' StaXX-side registry requests across three '
   .'throwaway registries, plus a couple more spent by the docker CLI comparisons.');

/* No exit() here on purpose. The final "passed/failed/skipped" line, the
 * teardown proof, and the exit code itself all come from the shutdown
 * function registered near the top of this file — the only point that has
 * seen every assertion, teardown's own included, so a fatal error or a
 * leaked container is never masked by a code this script committed to
 * earlier. */
