<?php
/* PLAN_76/PLAN_98 — the export route. See PLAN_98.md for the stage table;
 * each stage lands its own section below, added by whichever agent builds
 * that stage, so read the section headings rather than assuming this file
 * is all one piece.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine.
 *
 * Stage 1's cases need no config keys at all: staxx_placeholders() takes a
 * file path directly, so its fixtures are plain files under /tmp. The
 * staxx_start_job() cases DO need STORE_ROOT, the same way tests/server/
 * record.php and files.php do it — pointed at /tmp/zze-store, never the
 * real store:
 *
 *     pscp tests/server/export.php root@<box>:/tmp/
 *     plink … '
 *       CFG=/boot/config/plugins/staxx/staxx.cfg
 *       cp $CFG /tmp/cfg.bak
 *       grep -q "^STORE_ROOT=" $CFG \
 *         && sed -i "s#^STORE_ROOT=.*#STORE_ROOT=\"/tmp/zze-store\"#" $CFG \
 *         || echo "STORE_ROOT=\"/tmp/zze-store\"" >> $CFG
 *       php /tmp/export.php; RC=$?
 *       cp /tmp/cfg.bak $CFG
 *       exit $RC
 *     '
 *
 * Prints one line per case and exits non-zero on any failure. Creates and
 * removes its own stacks, "zze…", under the temporary stack root.
 *
 * MUST NEVER RUN DOCKER. Every staxx_start_job() call below is arranged to
 * terminate in a refusal that returns before the function ever builds or
 * detaches a real command — either the placeholder refusal itself, or a
 * downstream refusal (an unknown service, or a verb with no form for the
 * scope asked) reached only because the placeholder gate let it through.
 * Deliberately not exercised here: a whole-stack "up" on a genuinely clean
 * file, because nothing safe stops it short of a real `docker compose up` —
 * staxx_placeholders() returning [] for that same fixture (asserted below)
 * is the proof that stands in for it. */

require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

/* ============================================================
 * Stage 1 — a half-filled recipe will not start
 * ============================================================ */

/* ---------------------------------------------- staxx_placeholders() ---- */
// Unit-level: a plain file path in, a list of descriptions out. No STORE_ROOT
// needed for any of these — the function never looks past the file it is handed.

$tmp = '/tmp/zze-placeholders';
@exec('rm -rf '.escapeshellarg($tmp));
mkdir($tmp, 0755, true);

$clean = $tmp.'/clean.yaml';
file_put_contents($clean, "services:\n  web:\n    image: nginx:1.27\n    ports:\n      - \"8080:80\"\n");
ok('a file with no placeholder yields nothing', staxx_placeholders($clean) === []);

$one = $tmp.'/one.yaml';
file_put_contents($one, "services:\n  db:\n    image: postgres:16\n    environment:\n      DB_PASSWORD: REPLACE-ME\n");
$found = staxx_placeholders($one);
ok('one placeholder yields one description', count($found) === 1, implode('; ', $found));
ok('it names the setting and the service',
   $found !== [] && stripos($found[0], 'password') !== false && stripos($found[0], 'db') !== false,
   $found[0] ?? '');

$several = $tmp.'/several.yaml';
file_put_contents($several,
  "services:\n".
  "  db:\n".
  "    image: postgres:16\n".
  "    environment:\n".
  "      DB_PASSWORD: REPLACE-ME\n".
  "      ADMIN_PASSWORD: REPLACE-ME\n".
  "  jellyfin:\n".
  "    image: jellyfin/jellyfin\n".
  "    volumes:\n".
  "      - MEDIA_FOLDER=REPLACE-ME\n"
);
$found = staxx_placeholders($several);
ok('three placeholders yield three descriptions', count($found) === 3, implode('; ', $found));
ok('each names its own service',
   $found !== [] &&
   count(array_filter($found, fn($d) => stripos($d, 'in service db') !== false)) === 2 &&
   count(array_filter($found, fn($d) => stripos($d, 'in service jellyfin') !== false)) === 1,
   implode('; ', $found));

$dup = $tmp.'/dup.yaml';
file_put_contents($dup,
  "services:\n".
  "  web:\n".
  "    image: nginx:1.27\n".
  "    ports:\n".
  "      - \"REPLACE-ME:80\"\n".
  "      - \"REPLACE-ME:443\"\n"
);
ok('the same setting repeated is reported once', staxx_placeholders($dup) === ['a port (in service web)'],
   implode('; ', staxx_placeholders($dup)));

ok('an unreadable file yields nothing rather than throwing', staxx_placeholders($tmp.'/does-not-exist.yaml') === []);

/* ------------------------------------------------- staxx_start_job() ---- */
// Needs STORE_ROOT — see the header. Every case here is refused, and every
// refusal is reached before staxx_start_job() would ever touch a shell; see
// the "MUST NEVER RUN DOCKER" note above for why each one is shaped as it is.

if (staxx_stack_root() !== '/tmp/zze-store/stacks') {
  echo "FAIL   the temporary stack root is not in place (got ".staxx_stack_root().")\n";
  exit(max($fails, 1));
}

$root = staxx_stack_root();
@exec('rm -rf '.escapeshellarg($root));
mkdir($root, 0755, true);

// A stack with one placeholder in it — used for the refusal cases below.
$filledRel = 'zzefilled';
$filledDir = $root.'/'.$filledRel;
mkdir($filledDir, 0755, true);
file_put_contents($filledDir.'/compose.yaml',
  "services:\n  a:\n    image: alpine:3.20\n    environment:\n      API_TOKEN: REPLACE-ME\n");

// A clean stack — used to prove the gate does not fire when there is nothing
// waiting, without ever letting a whole-stack "up" reach a real docker call.
$cleanRel = 'zzeclean';
$cleanDir = $root.'/'.$cleanRel;
mkdir($cleanDir, 0755, true);
file_put_contents($cleanDir.'/compose.yaml', "services:\n  a:\n    image: alpine:3.20\n");

$err = '';
$r = staxx_start_job($filledRel, 'up', $err, '');
ok('whole-stack start is refused on a filled placeholder',
   $r === '' && stripos($err, 'api token') !== false && stripos($err, 'in service a') !== false, $err);
ok('the whole-stack refusal says what to do next', stripos($err, 'Open the stack') !== false, $err);

$err = '';
$r = staxx_start_job($filledRel, 'up', $err, 'a');
ok('single-service start is refused on the same file',
   $r === '' && stripos($err, 'api token') !== false, $err);

// Several waiting values — the count word and the full list both matter.
$manyRel = 'zzemany';
$manyDir = $root.'/'.$manyRel;
mkdir($manyDir, 0755, true);
file_put_contents($manyDir.'/compose.yaml',
  "services:\n".
  "  db:\n".
  "    image: postgres:16\n".
  "    environment:\n".
  "      DB_PASSWORD: REPLACE-ME\n".
  "      ADMIN_PASSWORD: REPLACE-ME\n"
);
$err = '';
$r = staxx_start_job($manyRel, 'up', $err, '');
ok('two waiting values are both named and the count word is right',
   $r === '' && stripos($err, 'Two values still need filling in') !== false
   && stripos($err, 'database password') !== false && stripos($err, 'admin password') !== false, $err);

// `remove` has no whole-stack form at all, and `config` has no single-service
// form, so both are refused by the scope check itself — never even reach the
// placeholder gate — which is still proof they are unaffected by whatever is
// sitting in the file.
$err = '';
$r = staxx_start_job($filledRel, 'remove', $err, '');
ok('a verb that does not start anything is unaffected by the placeholder (whole-stack)',
   $r === '' && stripos($err, 'filling in') === false && stripos($err, 'whole stack') !== false, $err);

$err = '';
$r = staxx_start_job($filledRel, 'config', $err, 'a');
ok('a verb that does not start anything is unaffected by the placeholder (service)',
   $r === '' && stripos($err, 'filling in') === false && stripos($err, 'single container') !== false, $err);

// `down` DOES reach the placeholder gate (both scopes have a form for it) and
// is skipped there because it does not start anything — proved by landing on
// the real, safe refusal one step further on (an unknown service name)
// rather than on a whole-stack real invocation, which single-service scope
// lets this stop short of.
$err = '';
$r = staxx_start_job($filledRel, 'down', $err, 'does-not-exist');
ok('down reaches the placeholder gate and is waved through by it',
   $r === '' && stripos($err, 'filling in') === false
   && stripos($err, 'No service called') !== false, $err);

// The clean stack is never driven through a whole-stack "up" — see the header
// note. Single-service scope with a service name that does not exist gives a
// safe, real refusal downstream of the placeholder gate, which proves the
// gate did not fire for a file with nothing waiting in it.
ok('the clean fixture itself carries no placeholder', staxx_placeholders($cleanDir.'/compose.yaml') === []);
$err = '';
$r = staxx_start_job($cleanRel, 'up', $err, 'does-not-exist');
ok('a clean file is not refused for a placeholder (reaches the real service check instead)',
   $r === '' && stripos($err, 'filling in') === false
   && stripos($err, 'No service called') !== false, $err);

@exec('rm -rf '.escapeshellarg($tmp).' '.escapeshellarg($root));

echo $fails === 0 ? "\nAll passed.\n" : "\n$fails failed.\n";
exit($fails === 0 ? 0 : 1);
