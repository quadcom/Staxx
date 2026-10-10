<?php
/* PLAN_236 — Unraid's own labels, written to a generated compose file and
 * passed as the last -f: staxx_labels_file(), staxx_run_files(), the icon
 * copies Unraid keeps (staxx_labels_refresh_icons()) and the two-hash match in
 * staxx_service_hashes().
 *
 * Cases: a service with a WebUI and an https icon; a picture in the stack's
 * .staxx folder (file://); an SVG icon; no WebUI (label absent); an override
 * file (labels file last); a `$$` escape kept as written; the icon copies Unraid
 * keeps (exactly two files removed, a neighbour untouched; an SVG drawn to a
 * PNG when resvg is installed); and the hash with and without the labels.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine, and it needs
 * `docker compose` to answer (read-only `config` only):
 *
 *     pscp tests/server/labels.php root@<box>:/tmp/
 *     plink … "php /tmp/labels.php"
 *
 * Builds everything under /tmp and never touches the real store, so it does not
 * redirect STORE_ROOT. The labels folder and Unraid's two icon folders are
 * pointed at /tmp by constants defined before anything loads, so no real
 * container's icon is ever removed. Prints one line per case and exits
 * non-zero on any failure. */

$root = '/tmp/staxx-labels-test';
define('STAXX_LABELS_DIR', $root.'/labels');
define('STAXX_UNRAID_ICON_DISK', $root.'/unraid-disk');
define('STAXX_UNRAID_ICON_RAM', $root.'/unraid-ram');
define('STAXX_UNRAID_DOCKER_INFO', $root.'/unraid-ram/docker.json');

require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

shell_exec('rm -rf '.escapeshellarg($root));
foreach (['labels', 'unraid-disk', 'unraid-ram', 'demo/.staxx', 'plain'] as $d) mkdir($root.'/'.$d, 0755, true);

file_put_contents($root.'/demo/.staxx/web.png', "\x89PNG\r\n\x1a\n");
file_put_contents($root.'/demo/.staxx/vec.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10" fill="red"/></svg>');

$demo = $root.'/demo/compose.yaml';
file_put_contents($demo, <<<'YAML'
services:
  web:
    image: nginx:alpine
    x-unraid:
      webui: "http://[IP]:[PORT:80]/?a=$$b"
      icon: https://example.com/icons/web.png
  local:
    image: nginx:alpine
    container_name: my.local-1
    x-unraid:
      icon: ./.staxx/web.png
  vec:
    image: nginx:alpine
    x-unraid:
      icon: ./.staxx/vec.svg
  bare:
    image: nginx:alpine
    x-unraid:
      icon: fa-cube
YAML);

$path = staxx_labels_file($demo);
$text = (string)@file_get_contents($path);
ok('a labels file is written under the labels folder', $path !== '' && strpos($path, STAXX_LABELS_DIR.'/') === 0 && $text !== '');
ok('every service is marked composeman, never dockerman',
   substr_count($text, '"net.unraid.docker.managed": "composeman"') === 4 && strpos($text, 'dockerman') === false);
ok('the WebUI is passed as the compose text, $$ escape included',
   strpos($text, '"net.unraid.docker.webui": "http://[IP]:[PORT:80]/?a=$$b"') !== false);
ok('an https icon is passed as the address', strpos($text, '"net.unraid.docker.icon": "https://example.com/icons/web.png"') !== false);
ok('a .staxx picture is passed as file://<absolute path>',
   strpos($text, '"net.unraid.docker.icon": "file://'.$root.'/demo/.staxx/web.png"') !== false);
ok('an SVG icon still names the SVG', strpos($text, 'file://'.$root.'/demo/.staxx/vec.svg') !== false);
ok('no WebUI: the label is absent for that service', substr_count($text, 'net.unraid.docker.webui') === 1);
ok('a glyph icon writes no icon label', substr_count($text, 'net.unraid.docker.icon') === 3);

// Compose must accept the generated file as an override.
$cmd = staxx_compose_cmd();
$code = 1;
staxx_sh($cmd.' '.staxx_compose_file_args([$demo, $path]).' config 2>&1', 15, $code);
ok('Compose accepts the file beside the stack\'s own', $code === 0);
$out = staxx_sh($cmd.' '.staxx_compose_file_args([$demo, $path]).' config 2>&1', 15, $code);
ok('the labels reach the merged config', strpos($out, 'net.unraid.docker.managed: composeman') !== false);

// An override file: the labels file is the last -f.
file_put_contents($root.'/demo/compose.override.yaml', "services:\n  web:\n    environment:\n      A: \"1\"\n");
$run = staxx_run_files($demo);
ok('with an override the labels file comes last', count($run) === 3 && $run[2] === $path && basename($run[1]) === 'compose.override.yaml');
unlink($root.'/demo/compose.override.yaml');

// Nothing to write -> '' and the files are just the stack's own.
$bad = $root.'/plain/compose.yaml';
file_put_contents($bad, "services: [\n");
ok('a file Compose cannot read gives no labels file and the plain list',
   staxx_labels_file($bad) === '' && staxx_run_files($bad) === [$bad]);

// The icon copies Unraid keeps.
$disk = STAXX_UNRAID_ICON_DISK; $ram = STAXX_UNRAID_ICON_RAM;
foreach (['my.local-1', 'web-icon-neighbour', 'demo-web-1'] as $n) {
  file_put_contents("$disk/$n-icon.png", 'old'); file_put_contents("$ram/$n-icon.png", 'old');
}
file_put_contents(STAXX_UNRAID_DOCKER_INFO, json_encode(['demo-web-1' => ['icon' => '/q.png', 'url' => 'u'], 'other' => ['icon' => '/o.png']]));
staxx_labels_file($demo, true);
ok('a changed icon: exactly that container\'s two copies are removed',
   !is_file("$disk/my.local-1-icon.png") && !is_file("$ram/my.local-1-icon.png")
   && !is_file("$disk/demo-web-1-icon.png") && !is_file("$ram/demo-web-1-icon.png"));
$noted = json_decode((string)file_get_contents(STAXX_UNRAID_DOCKER_INFO), true);
ok('the icon note for that container is dropped, the rest of its entry and other containers kept',
   !isset($noted['demo-web-1']['icon']) && ($noted['demo-web-1']['url'] ?? '') === 'u' && ($noted['other']['icon'] ?? '') === '/o.png');
ok('a copy belonging to another container is left alone',
   is_file("$disk/web-icon-neighbour-icon.png") && is_file("$ram/web-icon-neighbour-icon.png"));
$resvg = getenv('STAXX_RESVG_BIN') ?: '/usr/local/lib/staxx/resvg';
if (is_executable($resvg)) {
  ok('an SVG is drawn to a PNG in the kept copy, and the RAM copy is cleared',
     substr((string)@file_get_contents("$disk/demo-vec-1-icon.png"), 0, 4) === "\x89PNG" && !is_file("$ram/demo-vec-1-icon.png"));
} else {
  ok('resvg not installed here: the SVG drawing case is skipped', true, 'skipped');
}
ok('no service name outside the safe characters ever reaches a path',
   (function () use ($disk) {
     $before = glob("$disk/*") ?: [];
     staxx_labels_refresh_icons(['x' => ['labels' => ['net.unraid.docker.icon' => 'https://e.x/a.png'],
                                         'src' => 'https://e.x/a.png', 'container' => '../evil']]);
     return (glob("$disk/*") ?: []) === $before;
   })());

// The two fingerprints.
$plain = staxx_service_hashes($demo);
$withL = staxx_service_hashes($demo, true);
ok('both fingerprints are known', is_array($plain) && is_array($withL) && isset($plain['web'], $withL['web']));
ok('labels change the fingerprint, so a container made without them has the plain one',
   ($plain['web'] ?? 'a') !== ($withL['web'] ?? 'a'));
// A container with either hash must read as matching; this is the rule restart_pending applies.
$accepts = fn(string $live, string $svc) => $live === ($plain[$svc] ?? '') || $live === ($withL[$svc] ?? '');
ok('a container carrying either fingerprint matches', $accepts($plain['web'], 'web') && $accepts($withL['web'], 'web'));
ok('a container carrying neither does not', !$accepts(str_repeat('0', 64), 'web'));

file_put_contents($demo, str_replace('web.png', 'web2.png', (string)file_get_contents($demo)));
staxx_compose_meta($demo, $metaErr, true);   // same second as the write above: drop the in-request copy
$after = staxx_service_hashes($demo, true);
ok('an edit to an icon address changes the with-labels fingerprint (cache key follows the labels)',
   ($after['web'] ?? 'a') !== ($withL['web'] ?? 'a'));

shell_exec('rm -rf '.escapeshellarg($root));
echo $fails === 0 ? "\nall passed\n" : "\n$fails failed\n";
exit($fails === 0 ? 0 : 1);
