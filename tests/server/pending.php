<?php
/* PLAN_71 — the restart-pending comparison, and above all its refusals.
 *
 * Server-only: it needs docker and compose. Copy up and run:
 *
 *   php /tmp/pending.php
 *
 * Takes no config keys and changes nothing. That is deliberate: the synthetic
 * cases below are handed explicit file paths under /tmp, so none of them needs
 * the store root moved — and on a live server moving it, even for one command,
 * makes every real stack vanish from the webGUI for as long as it is moved.
 * The sweep over the real stacks at the end only reads.
 *
 * The pass this cares about most is the negative one. A false "restart to
 * apply" on a stack nobody has touched is the failure that destroys the whole
 * feature, because a mark that cries wolf is a mark people learn to ignore.
 */

$root = '/tmp/p71-probe';

require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';

$fails = 0;
function check(string $what, bool $ok): void {
  global $fails;
  if (!$ok) $fails++;
  printf("%-4s %s\n", $ok ? 'ok' : 'FAIL', $what);
}

/* ---- the refusals, on stacks built for the purpose ---- */

@mkdir($root, 0755, true);

// A file compose will refuse outright. Unknown must not read as agreement.
$broken = $root.'/broken';
@mkdir($broken, 0755, true);
file_put_contents($broken.'/compose.yaml', "services:\n  a:\n   image: x\n  bad\n");
check('an unreadable file yields no chip',
  staxx_service_hashes($broken.'/compose.yaml') === null);

// A perfectly good file whose services have never been started. Nothing is
// running, so there is nothing to be running the wrong settings.
$idle = $root.'/idle';
@mkdir($idle, 0755, true);
file_put_contents($idle.'/compose.yaml', "services:\n  a:\n    image: busybox\n");
$p = staxx_restart_pending(['name' => 'idle', 'file' => $idle.'/compose.yaml']);
check('a stack with nothing running yields no chip', $p['state'] === '');

// A comment is not a change. This is the property that stops the chip crying
// wolf every time somebody annotates their own file.
//
// Each variant gets its OWN directory, deliberately. The in-memory cache is
// keyed on the file's PATH, so rewriting one file and asking again inside a
// single process returns the first answer — the check would pass without ever
// re-running compose. (Harmless in the plugin itself, where every web request
// is a fresh process; fatal to a test that does not allow for it.)
$mk = function (string $dir, string $body) use ($root): ?array {
  @mkdir($root.'/'.$dir, 0755, true);
  file_put_contents($root.'/'.$dir.'/compose.yaml', $body);
  return staxx_service_hashes($root.'/'.$dir.'/compose.yaml');
};

$plain    = $mk('plain',    "services:
  a:
    image: busybox
");
$comment  = $mk('comment',  "# annotated
services:
  a:
    image: busybox
");
$restart  = $mk('restart',  "services:
  a:
    image: busybox
    restart: always
");
$twosvc   = $mk('twosvc',   "services:
  a:
    image: busybox
  b:
    image: alpine
");

check('a good file yields one 64-hex fingerprint per service',
  is_array($plain) && count($plain) === 1 && preg_match('/^[0-9a-f]{64}$/', $plain['a'] ?? '') === 1);

check('adding a comment does not move the fingerprint',
  is_array($comment) && ($comment['a'] ?? 'x') === ($plain['a'] ?? 'y'));

check('a real settings change does move the fingerprint',
  is_array($restart) && ($restart['a'] ?? '') !== ($plain['a'] ?? ''));

check('a second service leaves the first fingerprint alone',
  is_array($twosvc) && ($twosvc['a'] ?? '') === ($plain['a'] ?? '') && isset($twosvc['b']));

/* ---- C6: a file-backed secret or config is a bind mount too ----
 *
 * A container using one is mounted at /run/secrets/<name> (a config at
 * /<name>), whether the author wrote the entry in short or long form. Before
 * this fix the file-side parser never expected that mount at all, so every
 * such stack wore "restart to apply" permanently — the false badge that
 * teaches people to ignore a true one. This calls the file-side parser
 * directly against a hand-built "docker compose config" text, so it needs no
 * docker and starts no container: the "live" side below is exactly what
 * `docker inspect` would report for a container actually started from these
 * secrets and this config, typed out rather than fetched.
 */
require_once '/usr/local/emhttp/plugins/staxx/include/Pending.php';

$secretsDir = $root.'/secrets';
@mkdir($secretsDir, 0755, true);
file_put_contents($secretsDir.'/s1.txt', 'a');
file_put_contents($secretsDir.'/s2.txt', 'b');
file_put_contents($secretsDir.'/c1.txt', 'c');

$cfgYaml = "secrets:
  s_short:
    file: ./s1.txt
  s_long:
    file: ./s2.txt
configs:
  c1:
    file: ./c1.txt
services:
  a:
    image: busybox
    secrets:
      - source: s_short
      - source: s_long
        target: /custom/path/s2
    configs:
      - source: c1
";

$parsed = staxx_pending_parse_file($cfgYaml, $secretsDir);

$expectShort = staxx_pending_mount_key((string)realpath($secretsDir.'/s1.txt'), '/run/secrets/s_short');
$expectLong  = staxx_pending_mount_key((string)realpath($secretsDir.'/s2.txt'), '/custom/path/s2');
$expectConf  = staxx_pending_mount_key((string)realpath($secretsDir.'/c1.txt'), '/c1');

check('a short-form file secret becomes an expected mount',
  in_array($expectShort, $parsed['a']['volumes'] ?? [], true));
check('a long-form file secret with an explicit target becomes an expected mount',
  in_array($expectLong, $parsed['a']['volumes'] ?? [], true));
check('a file-backed config becomes an expected mount',
  in_array($expectConf, $parsed['a']['volumes'] ?? [], true));

// What a container really started from this file would report — proving the
// comparison staxx_pending_detail() actually runs finds nothing pending.
$liveVolumes = [$expectShort, $expectLong, $expectConf];
check('a container with exactly these secrets/configs shows no pending mounts',
  array_diff($parsed['a']['volumes'], $liveVolumes) === []
  && array_diff($liveVolumes, $parsed['a']['volumes']) === []);

/* ---- the real stacks: nobody has edited them, so nothing may be marked ---- */

$marked = [];
foreach (staxx_list_stacks() as $s) {
  if ($s['file'] === '') continue;
  $p = staxx_restart_pending($s);
  printf("  %-28s %-8s changed=%-20s absent=%-16s leftover=%s\n",
    $s['name'], $p['state'] === '' ? '-' : $p['state'],
    implode(',', $p['changed']) ?: '-',
    implode(',', $p['absent']) ?: '-',
    implode(',', $p['leftover']) ?: '-');
  if ($p['state'] === 'pending') $marked[] = $s['name'];
}

// Not an assertion: a stack genuinely IS pending if somebody edited it and has
// not restarted, which is the whole point. It is reported loudly so the run can
// be judged rather than silently passed.
if ($marked !== []) {
  echo "\nNOTE: marked as pending — confirm each one really was edited since it started:\n      "
     . implode(', ', $marked)."\n";
}

echo "\n".($fails === 0 ? "all checks passed\n" : "$fails check(s) FAILED\n");
exit($fails === 0 ? 0 : 1);
