<?php
/* PLAN_150 phase 7 — the one-pass conversion of the old update.mode
 * spellings ('off', 'notify') to the current one ('manual'), checked
 * against the real installed UpdateModeConvert.php.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. Needs STORE_ROOT
 * pointed at /tmp/zzumc150-store, the same way tests/server/clash.php and
 * updateeconomy.php do theirs — never the real store:
 *
 *     pscp tests/server/update_mode_convert.php root@<box>:/tmp/
 *     plink … '
 *       CFG=/boot/config/plugins/staxx/staxx.cfg
 *       cp $CFG /tmp/cfg.bak
 *       grep -q "^STORE_ROOT=" $CFG \
 *         && sed -i "s#^STORE_ROOT=.*#STORE_ROOT=\"/tmp/zzumc150-store\"#" $CFG \
 *         || echo "STORE_ROOT=\"/tmp/zzumc150-store\"" >> $CFG
 *       php /tmp/update_mode_convert.php; RC=$?
 *       cp /tmp/cfg.bak $CFG
 *       diff -q /tmp/cfg.bak $CFG && echo "config restored, byte-identical"
 *       exit $RC
 *     '
 *
 * Prints one line per case and exits non-zero on any failure. Creates and
 * removes its own stacks, all named "zzumc150…", under the scratch stacks
 * folder — never the real one.
 *
 * Needs a real `docker compose`: the pass refuses to run at all without one
 * (see UpdateModeConvert.php's own comment on why), so this whole suite
 * SKIPS rather than fails on a machine without it — there would be nothing
 * left to prove.
 *
 * NEVER STARTS, PULLS OR TOUCHES DOCKER beyond `compose config`, which reads
 * a file and prints YAML — no image is ever fetched, no container ever
 * created.
 */

// Never puts a real notification on the box this runs on — see
// staxx_update_convert_notify()'s own comment on this env var.
putenv('STAXX_UPDATE_CONVERT_NO_NOTIFY=1');

require_once '/usr/local/emhttp/plugins/staxx/include/UpdateModeConvert.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

if (staxx_stack_root() !== '/tmp/zzumc150-store/stacks') {
  echo "FAIL   the temporary stack root is not in place (got ".staxx_stack_root().")\n";
  exit(1);
}

if (staxx_compose_cmd() === '') {
  echo "SKIPPED — no docker compose on this machine, every case here needs a real one\n";
  exit(0);
}

$root = staxx_stack_root();
@exec('rm -rf '.escapeshellarg(dirname($root)));
mkdir($root, 0755, true);

function write_stack(string $root, string $name, string $yaml): void {
  $dir = $root.'/'.$name;
  mkdir($dir, 0755, true);
  file_put_contents($dir.'/compose.yaml', $yaml);
}

// A service that carries a description with a colon and a comment on the
// mode line itself, so the scanner is proven to leave everything around the
// one word alone, not just the word.
$svcOnly = <<<YAML
services:
  web:
    image: alpine:3.20
    x-unraid:
      description: "a stack with only a service-scope old spelling"
      update:
        mode: off  # legacy spelling
        delay: 5
YAML;

$stackOnly = <<<YAML
x-unraid:
  update:
    mode: notify
services:
  web:
    image: alpine:3.20
YAML;

$both = <<<YAML
x-unraid:
  update:
    mode: off
services:
  web:
    image: alpine:3.20
    x-unraid:
      update:
        mode: notify
  helper:
    image: alpine:3.20
YAML;

$neither = <<<YAML
services:
  web:
    image: alpine:3.20
    x-unraid:
      update:
        mode: auto
        delay: 0
YAML;

// A block scalar sitting right next to the real key, to prove it is walked
// past rather than mistaken for one — the multi-line text below deliberately
// contains a line that reads exactly like a mode: line would.
$withBlockScalar = <<<YAML
x-unraid:
  description: |
    Not a real setting:
    mode: off
  update:
    mode: off
services:
  web:
    image: alpine:3.20
YAML;

// Malformed on purpose — an undeclared network reference — so `docker
// compose config` refuses it and this stack must be left entirely alone.
$broken = <<<YAML
services:
  web:
    image: alpine:3.20
    networks:
      - nowhere
    x-unraid:
      update:
        mode: off
YAML;

write_stack($root, 'zzumc150svc',   $svcOnly);
write_stack($root, 'zzumc150stack', $stackOnly);
write_stack($root, 'zzumc150both',  $both);
write_stack($root, 'zzumc150none',  $neither);
write_stack($root, 'zzumc150block', $withBlockScalar);
write_stack($root, 'zzumc150broken', $broken);

$r = staxx_update_mode_convert_run();
ok('the pass reports it ran', $r['ran']);

$convertedNames = array_map(fn($c) => $c['stack'], $r['converted']);
ok('service-scope stack was converted', in_array('zzumc150svc', $convertedNames, true));
ok('stack-scope stack was converted',   in_array('zzumc150stack', $convertedNames, true));
ok('the both-scopes stack was converted', in_array('zzumc150both', $convertedNames, true));
ok('the block-scalar stack was converted', in_array('zzumc150block', $convertedNames, true));
ok('the untouched stack was left out of the list', !in_array('zzumc150none', $convertedNames, true));
ok('the broken stack was left out of the list', !in_array('zzumc150broken', $convertedNames, true));

$svcText = file_get_contents($root.'/zzumc150svc/compose.yaml');
ok('service scope: mode is manual now', strpos($svcText, "mode: manual  # legacy spelling") !== false, $svcText);
ok('service scope: the delay line is untouched', strpos($svcText, "delay: 5") !== false);
ok('service scope: the description line is untouched', strpos($svcText, 'a stack with only a service-scope old spelling') !== false);

$stackText = file_get_contents($root.'/zzumc150stack/compose.yaml');
ok('stack scope: mode is manual now', preg_match('/^\s*mode:\s*manual\s*$/m', $stackText) === 1, $stackText);

$bothText = file_get_contents($root.'/zzumc150both/compose.yaml');
ok('both scopes: exactly two mode lines, both manual',
   preg_match_all('/mode:\s*manual/', $bothText) === 2
   && strpos($bothText, 'off') === false && strpos($bothText, 'notify') === false,
   $bothText);

$noneText = file_get_contents($root.'/zzumc150none/compose.yaml');
ok('the untouched file is byte-identical', $noneText === $neither);

$blockText = file_get_contents($root.'/zzumc150block/compose.yaml');
ok('the block scalar text was not rewritten', strpos($blockText, "Not a real setting:\n    mode: off") !== false, $blockText);
ok('the real mode line was rewritten', preg_match('/\n  update:\n    mode: manual\n/', $blockText) === 1, $blockText);

$brokenText = file_get_contents($root.'/zzumc150broken/compose.yaml');
ok('the broken file is byte-identical', $brokenText === $broken);

// The notice names what was altered, not merely that something was.
$noticeBody = staxx_update_convert_notify_body($r['converted']);
foreach ($convertedNames as $cn) {
  ok('the notice names '.$cn, strpos($noticeBody, $cn) !== false);
}

// -------------------------------------------------------- the record ----
ok('the pass now reports done', staxx_update_convert_done());
ok('the editor flag reads true for a converted stack', staxx_update_convert_was_converted('zzumc150svc'));
ok('the editor flag reads false for the untouched stack', !staxx_update_convert_was_converted('zzumc150none'));

// --------------------------------------------------- not running twice ----
// Revert one file by hand, back to the old spelling, and run again — a
// second pass must be a pure no-op (the "done" gate above everything else),
// so the hand-reverted file must NOT come back to "manual" on its own.
file_put_contents($root.'/zzumc150svc/compose.yaml', $svcOnly);
$r2 = staxx_update_mode_convert_run();
ok('a second run does nothing', !$r2['ran'] && $r2['converted'] === []);
ok('a file reverted by hand after the pass stays reverted', file_get_contents($root.'/zzumc150svc/compose.yaml') === $svcOnly);
// Put the converted text back so the undo case below has something real to restore.
file_put_contents($root.'/zzumc150svc/compose.yaml', $svcText);

// -------------------------------------------------------------- undo ----
$note = '';
$undo = staxx_update_mode_convert_undo($note);
ok('undo reports ok', $undo['ok'] && $note === '');
ok('undo restored every convertible file', count($undo['restored']) === 4, count($undo['restored']));

ok('service scope file restored byte-identical', file_get_contents($root.'/zzumc150svc/compose.yaml') === $svcOnly);
ok('stack scope file restored byte-identical', file_get_contents($root.'/zzumc150stack/compose.yaml') === $stackOnly);
ok('both-scopes file restored byte-identical', file_get_contents($root.'/zzumc150both/compose.yaml') === $both);
ok('block-scalar file restored byte-identical', file_get_contents($root.'/zzumc150block/compose.yaml') === $withBlockScalar);

// A second undo must be a pure no-op too — "done" was never cleared by
// undoing, and every file now matches its own before_hash again, not its
// after_hash, so nothing should be touched a second time.
$note2 = '';
$undo2 = staxx_update_mode_convert_undo($note2);
ok('a second undo restores nothing further', count($undo2['restored']) === 0);
ok('a second undo reports every entry skipped, not failed', count($undo2['skipped']) === 4);

@exec('rm -rf '.escapeshellarg(dirname($root)));

echo $fails === 0 ? "\nAll good.\n" : "\n$fails case(s) FAILED.\n";
exit($fails === 0 ? 0 : 1);
