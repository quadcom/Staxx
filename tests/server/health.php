<?php
/* PLAN_108 steps 1 and 2 — reading an image's own health check, and the
 * trial that decides whether a candidate check may ever be offered.
 *
 * staxx_local_image_config() itself needs a real image to inspect, so its
 * health parsing is covered instead through staxx_parse_image_healthcheck(),
 * the tiny pure function it hands the decoded `.Config.Healthcheck` JSON to
 * — fed made-up shapes here, never a real image. The negative cases matter
 * most: an absent key and Docker's own `["NONE"]` must both read as "not
 * declared", exactly the way a health offer needs them to.
 *
 * staxx_health_trial()'s refusals are the one thing this plan calls unsafe
 * to skip: every one of them must return false with a plain-sentence $why,
 * and NEVER "offer anyway". Every case below trips before staxx_sh() would
 * ever run a real `docker exec` — a bad mode, an empty command and a bad
 * container name all fail validation first, and a nonexistent container
 * name is refused at the read-only "is it running" check. What an exit
 * code as such (127 not-found, 124/137 timed out, anything else non-zero)
 * gets turned into is covered separately through
 * staxx_health_trial_verdict(), the pure mapping the trial hands its real
 * exit code to — fed made-up numbers here, never a real `docker exec`.
 *
 * THIS SUITE BUILDS NOTHING, STARTS NOTHING, PULLS NOTHING AND REMOVES
 * NOTHING. No config keys needed. Runs ON THE SERVER — there is no PHP on
 * the dev machine:
 *
 *     pscp tests/server/health.php root@<box>:/tmp/
 *     plink … "php /tmp/health.php"
 *
 * Prints one line per case and exits non-zero on any failure.
 */

require_once '/usr/local/emhttp/plugins/staxx/include/Defines.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Detail.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

/* ---------------------------------------- reading the image's own check -- */

$absent = staxx_parse_image_healthcheck(null);
ok('no Healthcheck key at all reads as not declared',
   $absent === ['test' => [], 'declared' => false]);

$none = staxx_parse_image_healthcheck(['Test' => ['NONE']]);
ok('["NONE"] — the author explicitly cancelled it — reads as not declared',
   $none === ['test' => [], 'declared' => false]);

$cmd = staxx_parse_image_healthcheck(['Test' => ['CMD', 'mysqladmin', 'ping']]);
ok('a real CMD check is declared, and the test survives whole',
   $cmd === ['test' => ['CMD', 'mysqladmin', 'ping'], 'declared' => true]);

$shell = staxx_parse_image_healthcheck(['Test' => ['CMD-SHELL', 'curl -f http://localhost/ || exit 1']]);
ok('a real CMD-SHELL check is declared',
   $shell['declared'] === true && $shell['test'] === ['CMD-SHELL', 'curl -f http://localhost/ || exit 1']);

$bareCmd = staxx_parse_image_healthcheck(['Test' => ['CMD']]);
ok('CMD with nothing after it is not a real check',
   $bareCmd === ['test' => [], 'declared' => false]);

/* -------------------------------------------------------- the trial ------ */

$why = 'unset';
ok('a bad mode is refused',
   staxx_health_trial('anything', ['SHELL', 'true'], $why) === false && $why !== '' && $why !== 'unset');

$why = 'unset';
ok('not an array at all is refused',
   staxx_health_trial('anything', 'CMD true', $why) === false && $why !== '' && $why !== 'unset');

$why = 'unset';
ok('a mode with no command after it is refused',
   staxx_health_trial('anything', ['CMD'], $why) === false && $why !== '' && $why !== 'unset');

$why = 'unset';
ok('CMD-SHELL with no command after it is refused',
   staxx_health_trial('anything', ['CMD-SHELL'], $why) === false && $why !== '' && $why !== 'unset');

$why = 'unset';
ok('a container name that is not shaped like one is refused',
   staxx_health_trial('; rm -rf /', ['CMD', 'true'], $why) === false && $why !== '' && $why !== 'unset');

$why = 'unset';
ok('an empty container name is refused',
   staxx_health_trial('', ['CMD', 'true'], $why) === false && $why !== '' && $why !== 'unset');

// A container name that is well-shaped but names nothing real: `docker
// inspect` answers "no such object", which is neither "true" nor a code-0
// running check, so this reads as "not running" — proven against a name
// that certainly is not a real container on this or any box, never a real
// one, so nothing here depends on what happens to be installed.
$why = 'unset';
$fakeName = 'staxx-health-suite-does-not-exist-'.bin2hex(random_bytes(6));
ok('a syntactically fine but nonexistent container is refused as "not running"',
   staxx_health_trial($fakeName, ['CMD', 'true'], $why) === false && $why !== '' && $why !== 'unset');

// staxx_health_tools() shares the same container-name gate.
$why = 'unset';
ok('staxx_health_tools() refuses the same bad container name',
   staxx_health_tools('; rm -rf /', $why) === [] && $why !== '' && $why !== 'unset');

/* ------------------------------------------- the command builder's shape - */

// staxx_health_valid_container() is exercised directly too, since it is the
// one gate both functions above share.
ok('a plain docker-shaped name validates',    staxx_health_valid_container('jellyfin') === true);
ok('an empty name does not validate',         staxx_health_valid_container('') === false);
ok('a name carrying shell metacharacters does not validate',
   staxx_health_valid_container('foo; touch /tmp/x') === false);

/* ------------------------------- undoing compose's own $$ substitution --- */

// The whole point: a recipe's own credential reference, exactly as it has
// to be written in the compose file, must come out as the shell variable a
// real client tool would read.
ok('$$MYSQL_ROOT_PASSWORD collapses to a real variable reference',
   staxx_health_collapse_dollars('-p$$MYSQL_ROOT_PASSWORD') === '-p$MYSQL_ROOT_PASSWORD');

ok('a single, unpaired dollar is left alone',
   staxx_health_collapse_dollars('$NOT_DOUBLED') === '$NOT_DOUBLED');

ok('two pairs collapse to two singles, left to right',
   staxx_health_collapse_dollars('$$$$') === '$$');

ok('a string with no dollar at all is untouched',
   staxx_health_collapse_dollars('mysqladmin ping') === 'mysqladmin ping');

/* ------------------------------------- the exit-code verdict, in isolation - */

ok('exit 0 is success — no refusal sentence', staxx_health_trial_verdict(0) === '');
ok('exit 127 says plainly there is nothing inside to check with',
   strpos(staxx_health_trial_verdict(127), 'nothing inside it to check with') !== false);
ok('exit 124 (timeout\'s own code) reads as timed out',
   strpos(staxx_health_trial_verdict(124), 'did not answer in time') !== false);
ok('exit 137 (SIGKILL after the grace period) also reads as timed out',
   strpos(staxx_health_trial_verdict(137), 'did not answer in time') !== false);
ok('any other non-zero code reads as "failed just now", not invented as healthy',
   staxx_health_trial_verdict(1) !== '' && strpos(staxx_health_trial_verdict(1), 'failed just now') !== false);

/* ------------------------------------------- PLAN_108 source 2's own reader - */

// No network anywhere below — see the suite header. Every case hands
// staxx_health_published_extract() text it already has in hand, exactly as
// action.php would once staxx_watch_curl() has fetched it.

$goodExample = "services:\n  app:\n    image: someone/thing:latest\n"
             . "    healthcheck:\n      test: [\"CMD-SHELL\", \"curl -f http://localhost/ || exit 1\"]\n"
             . "      interval: 10s\n      timeout: 3s\n      retries: 5\n      start_period: 20s\n";

$found = staxx_health_published_extract($goodExample, 'someone/thing:v2');
ok('a flow-style test is read, matched by repository not by tag',
   $found !== null && $found['test'] === ['CMD-SHELL', 'curl -f http://localhost/ || exit 1']);
ok('the author\'s own cadence is read alongside it',
   $found['interval'] === '10s' && $found['timeout'] === '3s' && $found['retries'] === 5 && $found['start_period'] === '20s');

$bareShorthand = "services:\n  app:\n    image: someone/thing\n"
               . "    healthcheck:\n      test: curl -f http://localhost/ || exit 1\n";
$bare = staxx_health_published_extract($bareShorthand, 'someone/thing');
ok('compose\'s own bare-string shorthand becomes CMD-SHELL',
   $bare !== null && $bare['test'] === ['CMD-SHELL', 'curl -f http://localhost/ || exit 1']);
ok('an unwritten cadence falls back to Docker\'s own real defaults',
   $bare['interval'] === '30s' && $bare['timeout'] === '30s' && $bare['retries'] === 3 && $bare['start_period'] === '0s');

ok('not YAML at all reads as nothing found',
   staxx_health_published_extract("this is just some prose, not a compose file at all\n", 'someone/thing') === null);

ok('YAML with no services key at all reads as nothing found',
   staxx_health_published_extract("version: \"3\"\nnetworks:\n  default:\n", 'someone/thing') === null);

$noMatch = "services:\n  other:\n    image: somebody/else\n    healthcheck:\n      test: [\"CMD\", \"true\"]\n";
ok('a file with no service naming this image reads as nothing found',
   staxx_health_published_extract($noMatch, 'someone/thing') === null);

$noCheck = "services:\n  app:\n    image: someone/thing\n    ports:\n      - \"80:80\"\n";
ok('the right service, but with no healthcheck at all, reads as nothing found',
   staxx_health_published_extract($noCheck, 'someone/thing') === null);

$multiline = "services:\n  app:\n    image: someone/thing\n"
           . "    healthcheck:\n      test:\n        - CMD\n        - curl\n        - -f\n        - http://localhost/\n";
ok('a multi-line test sequence — a shape this reader does not understand — gives up cleanly',
   staxx_health_published_extract($multiline, 'someone/thing') === null);

$disabled = "services:\n  app:\n    image: someone/thing\n"
          . "    healthcheck:\n      test: [\"NONE\"]\n";
ok('["NONE"] in a published example reads as the author disabling it, not a check to offer',
   staxx_health_published_extract($disabled, 'someone/thing') === null);

$looseQuoting = "services:\n  app:\n    image: someone/thing\n"
              . "    healthcheck:\n      test: [CMD, mysqladmin, ping]\n";
ok('an unquoted flow list is refused rather than patched up',
   staxx_health_published_extract($looseQuoting, 'someone/thing') === null);

$oversized = "services:\n  app:\n    image: someone/thing\n    healthcheck:\n      test: [\"CMD\", \"true\"]\n"
           . str_repeat('#'.str_repeat('x', 78)."\n", 1000);
ok('an oversized file is abandoned rather than parsed',
   strlen($oversized) > 64 * 1024 &&
   staxx_health_published_extract($oversized, 'someone/thing') === null);

exit($fails > 0 ? 1 : 0);
