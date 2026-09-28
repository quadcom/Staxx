<?php
/* PLAN_192 item 8, step 8a — coverage for the two compose readers behind the
 * device badge and the GPU column, staxx_compose_devices() and
 * staxx_compose_gpu_vendors() (include/Devices.php), written BEFORE either
 * is touched. Every case here must pass on the code as it stands; only once
 * it does does PLAN_192 merge the two into one shared walk (step 8b).
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. Needs no config
 * key and starts no Docker: both functions take a YAML string and a
 * filesystem read (Devices.php requires Stacks.php and Stats.php, but
 * nothing here calls a function that reads STORE_ROOT or shells out). The
 * one exception is the /dev/dri/renderD128 case, which asks
 * staxx_gpu_nodes() what this actual box's hardware is and SKIPs itself
 * when the box has no such node, rather than asserting a vendor no box
 * anywhere is guaranteed to have.
 *
 *     pscp tests/server/devices.php root@<box>:/tmp/
 *     plink … 'php /tmp/devices.php'
 *
 * Prints one line per case and exits non-zero on any failure.
 */

require_once '/usr/local/emhttp/plugins/staxx/include/Devices.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}
function skip(string $what, string $reason): void {
  printf("%-6s %s  (%s)\n", 'SKIP', $what, $reason);
}

/* ==================================================================== *
 * 1. short and long device forms, one service and two services.
 * ==================================================================== */

$shortOne = "services:\n  app:\n    image: nginx:latest\n    devices:\n      - /dev/dri:/dev/dri\n";
ok('short-form device entry is counted (one service)',
   staxx_compose_devices($shortOne) === ['app' => ['/dev/dri']],
   json_encode(staxx_compose_devices($shortOne)));

$longOne = "services:\n  app:\n    image: nginx:latest\n    devices:\n      - source: /dev/dri\n        target: /dev/dri\n";
ok('long-form device entry (source:/target:) is not counted — it is rare and a missing badge '
  .'beats a wrong one, per staxx_device_host()\'s own docblock',
   staxx_compose_devices($longOne) === [], json_encode(staxx_compose_devices($longOne)));

$twoServices = "services:\n  app1:\n    devices:\n      - /dev/dri:/dev/dri\n"
             . "  app2:\n    devices:\n      - /dev/dri:/dev/dri1\n";
ok('short-form devices are counted per service across two services',
   staxx_compose_devices($twoServices) === ['app1' => ['/dev/dri'], 'app2' => ['/dev/dri']],
   json_encode(staxx_compose_devices($twoServices)));

$mixedTwo = $shortOne."  app2:\n    devices:\n      - source: /dev/dri\n        target: /dev/dri\n";
ok('a second service using the long form contributes nothing beside the first service\'s short form',
   staxx_compose_devices($mixedTwo) === ['app' => ['/dev/dri']], json_encode(staxx_compose_devices($mixedTwo)));

/* ==================================================================== *
 * 2. deploy.resources.reservations.devices — absent from
 *    staxx_compose_devices() on purpose; nvidia from
 *    staxx_compose_gpu_vendors() for either "driver:" or "capabilities:".
 * ==================================================================== */

$reservDriver = "services:\n  app:\n    image: nginx:latest\n    deploy:\n      resources:\n"
              . "        reservations:\n          devices:\n            - driver: nvidia\n"
              . "              capabilities: [gpu]\n";
ok('a reservations devices: list is absent from staxx_compose_devices() (it is not a path list)',
   staxx_compose_devices($reservDriver) === [], json_encode(staxx_compose_devices($reservDriver)));
ok('...but staxx_compose_gpu_vendors() reads "driver: nvidia" off it',
   staxx_compose_gpu_vendors($reservDriver) === ['app' => ['nvidia']],
   json_encode(staxx_compose_gpu_vendors($reservDriver)));

$reservCaps = "services:\n  app:\n    image: nginx:latest\n    deploy:\n      resources:\n"
            . "        reservations:\n          devices:\n            - capabilities: [gpu]\n";
ok('a reservations devices: list is absent from staxx_compose_devices() (capabilities: [gpu] form)',
   staxx_compose_devices($reservCaps) === [], json_encode(staxx_compose_devices($reservCaps)));
ok('...but staxx_compose_gpu_vendors() reads "capabilities: [gpu]" off it with no driver: line at all',
   staxx_compose_gpu_vendors($reservCaps) === ['app' => ['nvidia']],
   json_encode(staxx_compose_gpu_vendors($reservCaps)));

/* ==================================================================== *
 * 3. runtime: nvidia and a bare gpus: all.
 * ==================================================================== */

$runtime = "services:\n  app:\n    image: nginx:latest\n    runtime: nvidia\n";
ok('runtime: nvidia is read as an nvidia claim, with no devices: entry at all',
   staxx_compose_gpu_vendors($runtime) === ['app' => ['nvidia']], json_encode(staxx_compose_gpu_vendors($runtime)));
ok('...and staxx_compose_devices() reports nothing for the same file',
   staxx_compose_devices($runtime) === [], json_encode(staxx_compose_devices($runtime)));

$gpus = "services:\n  app:\n    image: nginx:latest\n    gpus: all\n";
ok('a bare gpus: key is read as an nvidia claim',
   staxx_compose_gpu_vendors($gpus) === ['app' => ['nvidia']], json_encode(staxx_compose_gpu_vendors($gpus)));

/* ==================================================================== *
 * 4. /dev/nvidia0, and a /dev/dri node this box's own hardware answers for.
 * ==================================================================== */

$nvidiaDev = "services:\n  app:\n    devices:\n      - /dev/nvidia0:/dev/nvidia0\n";
ok('a /dev/nvidia0 device path is counted by staxx_compose_devices()',
   staxx_compose_devices($nvidiaDev) === ['app' => ['/dev/nvidia0']], json_encode(staxx_compose_devices($nvidiaDev)));
ok('...and read as an nvidia claim by staxx_compose_gpu_vendors()',
   staxx_compose_gpu_vendors($nvidiaDev) === ['app' => ['nvidia']], json_encode(staxx_compose_gpu_vendors($nvidiaDev)));

$nodes = staxx_gpu_nodes();
if ($nodes === []) {
  skip('a /dev/dri/<node> path is read as the vendor this box\'s own hardware reports',
       'this box has no /sys/class/drm nodes at all — nothing to check the vendor against');
} else {
  $node   = array_key_first($nodes);
  $vendor = $nodes[$node];
  $driNode = "services:\n  app:\n    devices:\n      - /dev/dri/$node:/dev/dri/$node\n";
  ok("a /dev/dri/$node path is counted by staxx_compose_devices()",
     staxx_compose_devices($driNode) === ['app' => ["/dev/dri/$node"]], json_encode(staxx_compose_devices($driNode)));
  ok("...and read as this box's own vendor ($vendor) by staxx_compose_gpu_vendors()",
     staxx_compose_gpu_vendors($driNode) === ['app' => [$vendor]], json_encode(staxx_compose_gpu_vendors($driNode)));
}

/* ==================================================================== *
 * 5. robustness: comments, blank lines, CRLF endings, quoted keys, a
 *    second top-level key ending the walk, a sibling key ending the list.
 * ==================================================================== */

$messy = "# a top comment\r\n"
       . "\r\n"
       . "services:\r\n"
       . "  app:\r\n"
       . "    image: nginx:latest\r\n"
       . "\r\n"
       . "    # a comment inside the service\r\n"
       . "    \"devices\":\r\n"
       . "      - /dev/dri:/dev/dri\r\n"
       . "    ports:\r\n"
       . "      - \"8080:80\"\r\n"
       . "volumes:\r\n"
       . "  data:\r\n";
ok('comments, blank lines, CRLF endings and a quoted "devices" key are all read the same as the plain form',
   staxx_compose_devices($messy) === ['app' => ['/dev/dri']], json_encode(staxx_compose_devices($messy)));
ok('a sibling key (ports:) at the same indent as devices: ends the devices list, not swallows it',
   staxx_compose_gpu_vendors($messy) === [], json_encode(staxx_compose_gpu_vendors($messy)));

$secondTop = "services:\n  app:\n    devices:\n      - /dev/dri:/dev/dri\n"
           . "networks:\n  default:\n    devices:\n      - /dev/fake:/dev/fake\n";
ok('a second top-level key after services: ends the walk — a devices: list under it is never counted',
   staxx_compose_devices($secondTop) === ['app' => ['/dev/dri']], json_encode(staxx_compose_devices($secondTop)));

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
