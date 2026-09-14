<?php
/* PLAN_140 — staxx_missing_external_networks() itself, against fake network
 * lists, and the refusal it feeds inside staxx_start_job(). PLAN_147 adds
 * cases for a compose-created network: it must count as present, and
 * staxx_docker_networks() itself must carry the `project` field that makes
 * that possible.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. STORE_ROOT ships
 * blank, so the refusal cases (which need a real, on-disk stack for
 * staxx_start_job() to find) need it pointed at /tmp instead, same as
 * override.php:
 *
 *     CFG=/boot/config/plugins/staxx/staxx.cfg
 *     cp $CFG /tmp/cfg.bak
 *     sed -i 's#^STORE_ROOT=.*#STORE_ROOT="/tmp/p140-networks"#' $CFG
 *     php /tmp/networks.php; RC=$?
 *     cp /tmp/cfg.bak $CFG
 *     exit $RC
 *
 * The question itself (staxx_missing_external_networks() with an explicit
 * $networks list) needs no config key at all and is checked first.
 *
 * The refusal cases are deliberately shallow: every verb that brings
 * something up is refused by the network check before staxx_start_job()
 * ever builds a command, so none of them reaches the shell — this box is
 * Adrian's own production server, so nothing here may start or pull
 * anything real. `pull` is checked against a stack that does not exist on
 * disk at all (the same trick console.php uses for its scope refusals): it
 * proves pull is refused for the ordinary "no compose file" reason rather
 * than the network one, without ever letting a real `pull` reach docker —
 * $startsSomething (and so both the placeholder check and this one) is
 * computed purely from the verb table, so a stack existing or not changes
 * nothing about whether pull would reach this check.
 *
 * Prints one line per case and exits non-zero on any failure.
 */

require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

/* ------------------------------------------------- the question itself -- */

// A plain `external: true` name absent from the fake list.
$m = staxx_missing_external_networks("networks:\n  eth0.2:\n    external: true\n", []);
ok('a plain external name missing from a fake list is reported',
   array_key_exists('eth0.2', $m) && $m['eth0.2'] === '');

// The modern `name:` form — the name actually checked is the `name:` value,
// not the mapping key.
$m = staxx_missing_external_networks(
  "networks:\n  mynet:\n    external: true\n    name: actual-net\n", []
);
ok('the name: form is checked against name:, not the mapping key',
   array_key_exists('actual-net', $m) && !array_key_exists('mynet', $m));

// The legacy `external: name: X` form.
$m = staxx_missing_external_networks(
  "networks:\n  mynet:\n    external:\n      name: actual-net2\n", []
);
ok('the legacy external: name: form is read the same way',
   array_key_exists('actual-net2', $m));

// Closest match: same suffix after the last dot, same driver.
$m = staxx_missing_external_networks(
  "networks:\n  eth0.2:\n    external: true\n",
  [['name' => 'br0.2', 'driver' => 'macvlan']]
);
ok('a same-suffix, same-driver network is offered as the closest match',
   ($m['eth0.2'] ?? null) === 'br0.2');

// Driver mismatch: no closest match offered, even with the same suffix.
$m = staxx_missing_external_networks(
  "networks:\n  eth0.2:\n    external: true\n    driver: macvlan\n",
  [['name' => 'br0.2', 'driver' => 'ipvlan']]
);
ok('a driver mismatch gives no closest match',
   ($m['eth0.2'] ?? null) === '');

// No dot in the name: never guessed at.
$m = staxx_missing_external_networks(
  "networks:\n  eth0:\n    external: true\n",
  [['name' => 'br0', 'driver' => 'macvlan']]
);
ok('a name with no dot never gets a guessed match',
   ($m['eth0'] ?? null) === '');

// A network that is not external is never reported, whatever the fake list.
$m = staxx_missing_external_networks(
  "networks:\n  mynet:\n    driver: bridge\n", []
);
ok('a non-external network is never reported', $m === []);

// No networks: block at all — nothing to check, so nothing is reported,
// distinct from the case above (a networks: block that names one non-
// external network) in that there is no declaration to walk at all.
$m = staxx_missing_external_networks(
  "services:\n  web:\n    image: busybox\n", []
);
ok('a file with no networks: block reports nothing', $m === []);

// PLAN_147 — a compose-created network (carrying a project label) counts
// as present, not just a hand-made one. Before this plan the label was used
// to drop the network from the list entirely, so this case would have
// reported it missing.
$m = staxx_missing_external_networks(
  "networks:\n  proxy_default:\n    external: true\n",
  [['name' => 'proxy_default', 'driver' => 'bridge', 'project' => 'proxy']]
);
ok('a compose-created network in the fake list is not reported missing', $m === []);

/* ---------------------------------------- the real list, PLAN_147 -------- */

// staxx_docker_networks() itself: every entry must carry all three keys, and
// — since every stack's own default network is compose-created — at least
// one entry on a box with any stack running must show a non-empty project.
// Skipped rather than failed when Docker is not running, or when nothing
// compose-created exists yet, since this reads whatever is really there
// rather than asserting a particular network's name.
if (!staxx_docker_running()) {
  echo "skip   staxx_docker_networks() shape check (docker not running)\n";
} else {
  $real = staxx_docker_networks();
  $shapeOk = true;
  $sawProject = false;
  foreach ($real as $n) {
    if (!array_key_exists('name', $n) || !array_key_exists('driver', $n) || !array_key_exists('project', $n)) {
      $shapeOk = false;
    }
    if (($n['project'] ?? '') !== '') $sawProject = true;
  }
  ok('every staxx_docker_networks() entry carries name, driver and project', $shapeOk);
  if ($sawProject) {
    ok('at least one real network shows a non-empty project', true);
  } else {
    echo "skip   no compose-created network found on this box right now\n";
  }
}

/* ------------------------------------------------------- the refusal ---- */

$cfg  = '/boot/config/plugins/staxx/staxx.cfg';
$want = '/tmp/p140-networks';
$have = staxx_stack_root();
if ($have !== $want.'/stacks') {
  echo "FAIL   STORE_ROOT is not seeded to $want — see this file's own header\n";
  echo "       (got '$have')\n";
  exit(1);
}

$name = 'netcheck-throwaway';
$dir  = staxx_stack_dir($name);
@mkdir($dir, 0755, true);
file_put_contents($dir.'/compose.yaml',
  "services:\n  web:\n    image: busybox\n" .
  "networks:\n  staxx-no-such-net-9:\n    external: true\n"
);

foreach (['up', 'restart', 'update', 'recreate', 'rebuild'] as $verb) {
  $error = '';
  $job   = staxx_start_job($name, $verb, $error);
  ok($verb.' is refused for the missing network, before reaching docker',
     $job === '' && strpos($error, 'This stack needs a network called') === 0,
     $error);
}

// pull against a stack that was never created on disk at all — refused for
// the ordinary "no compose file" reason, never this one, and never close
// enough to a real command to touch this box's docker.
$error = '';
$job = staxx_start_job('zzc140none', 'pull', $error);
ok('pull is not refused for the missing-network reason',
   $job === '' && strpos($error, 'This stack needs a network called') !== 0, $error);

exit($fails > 0 ? 1 : 0);
