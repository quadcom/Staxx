<?php
/* PLAN_70 stage 5 — the cross-stack matcher and its one-target credentials
 * lookup (include/CrossLinks.php).
 *
 * Server-only: it shells out to `docker compose config` for every stack it
 * touches. Copy up and run:
 *
 *   php /tmp/links_match.php
 *
 * Needs STACK_ROOT pointed at /tmp/lk70-root, the same way tests/server/
 * files.php points it at /tmp/b1-root — every fixture here is a synthetic
 * stack under that root, so the real stacks on the box are never read or
 * touched. The caller sets STACK_ROOT and puts the config back:
 *
 *     pscp tests/server/links_match.php root@<box>:/tmp/
 *     plink … '
 *       CFG=/boot/config/plugins/staxx/staxx.cfg
 *       cp $CFG /tmp/cfg.bak
 *       sed -i "s#^STACK_ROOT=.*#STACK_ROOT=\"/tmp/lk70-root\"#" $CFG
 *       php /tmp/links_match.php; RC=$?
 *       cp /tmp/cfg.bak $CFG
 *       exit $RC
 *     '
 *
 * Prints one line per case and exits non-zero on any failure. The negative
 * cases matter more than the positive ones — a matcher that says yes when
 * the two containers cannot actually reach each other is worse than one that
 * says nothing at all.
 */

require_once '/usr/local/emhttp/plugins/staxx/include/CrossLinks.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

if (staxx_stack_root() !== '/tmp/lk70-root') {
  echo "FAIL   the temporary stack root is not in place (got ".staxx_stack_root().")\n";
  exit(1);
}
if (staxx_compose_cmd() === '') {
  echo "FAIL   docker compose is not available — this suite cannot run without it\n";
  exit(1);
}

/* ---------------------------------------------------------------- fixtures ---- */

$root = staxx_stack_root();
$mk = function (string $rel, string $yaml) use ($root): void {
  $dir = $root.'/'.$rel;
  @exec('rm -rf '.escapeshellarg($dir));
  mkdir($dir, 0755, true);
  file_put_contents($dir.'/compose.yaml', $yaml);
};

// appA and dbX share an external network — the positive name-match case.
$mk('lk70-appA', <<<YAML
networks:
  lk70net:
    external: true
services:
  app:
    image: alpine:3.20
    networks:
      - lk70net
YAML
);

$mk('lk70-dbX', <<<YAML
networks:
  lk70net:
    external: true
services:
  db:
    image: mariadb:10.6
    container_name: lk70_dbX_sql
    networks:
      - lk70net
    environment:
      MARIADB_USER: appuser
      MARIADB_PASSWORD: xSecretOne1!
      MARIADB_ROOT_PASSWORD: rootSecretOne1!
      MARIADB_DATABASE: appdb
YAML
);

// appB shares no external network with anything — the negative case for the
// very same service name "db".
$mk('lk70-appB', <<<YAML
services:
  app:
    image: alpine:3.20
YAML
);

$mk('lk70-dbY', <<<YAML
networks:
  lk70netB:
    external: true
services:
  db:
    image: mariadb:10.6
    networks:
      - lk70netB
    environment:
      MARIADB_USER: otheruser
      MARIADB_PASSWORD: ySecretTwo2!
      MARIADB_ROOT_PASSWORD: rootSecretTwo2!
      MARIADB_DATABASE: otherdb
YAML
);

// A published port, for the server-address-plus-port route. No shared
// network at all — that route needs none.
$mk('lk70-dbport', <<<YAML
services:
  db2:
    image: mariadb:10.6
    ports:
      - "25432:3306"
    environment:
      MYSQL_USER: portuser
      MYSQL_PASSWORD: portSecret3!
YAML
);

// A service reachable only by its container_name, on the same network as
// appA — proves the container-name route independently of the service-name
// route.
$mk('lk70-dbcn', <<<YAML
networks:
  lk70net:
    external: true
services:
  sqldb:
    image: mysql:8.0
    container_name: lk70_company_sql
    networks:
      - lk70net
YAML
);

// An image nothing in the table recognises.
$mk('lk70-unknown', <<<YAML
services:
  app:
    image: busybox:1.36
YAML
);

// An unrecognised image that DOES have settings — for the names-only reply
// and the "ask once, remember" flow (PLAN_70 10.2's second layer).
$mk('lk70-unknownvals', <<<YAML
services:
  db:
    image: lk70test/customdb1
    environment:
      APP_DB_USER: someuser
      APP_DB_PASS: itsSecretPass1!
YAML
);

$hostIp = staxx_host_ip();

/* --------------------------------------------------------------- matching ---- */

// 1. service name + shared network — matches.
$r = staxx_crosslinks_match('lk70-appA', 'app', 'db');
ok('service name on a shared network matches', $r['kind'] === 'match'
  && in_array(['stack' => 'lk70-dbX', 'service' => 'db', 'via' => 'service-name', 'network' => 'lk70net'],
              $r['candidates'], true),
  json_encode($r));

// 2. same service name, no shared network — does not match.
$r = staxx_crosslinks_match('lk70-appB', 'app', 'db');
ok('the same name with no shared network does not match', $r['kind'] === 'none' && $r['candidates'] === [],
  json_encode($r));

// 3. container_name + shared network — matches.
$r = staxx_crosslinks_match('lk70-appA', 'app', 'lk70_company_sql');
ok('a container_name on a shared network matches', $r['kind'] === 'match'
  && in_array(['stack' => 'lk70-dbcn', 'service' => 'sqldb', 'via' => 'container-name', 'network' => 'lk70net'],
              $r['candidates'], true),
  json_encode($r));

// 3b. the same names in the wrong case — still match, because Docker's own
// resolution ignores case: a container answers to its name however it is
// written, confirmed on the box by asking a running container to look one up
// both ways. A name typed in the wrong case is a working address, so not
// recognising it was a miss rather than caution.
$r = staxx_crosslinks_match('lk70-appA', 'app', 'LK70_Company_SQL');
ok('a container_name in the wrong case still matches', $r['kind'] === 'match'
  && in_array(['stack' => 'lk70-dbcn', 'service' => 'sqldb', 'via' => 'container-name', 'network' => 'lk70net'],
              $r['candidates'], true),
  json_encode($r));

$r = staxx_crosslinks_match('lk70-appA', 'app', 'DB');
ok('a service name in the wrong case still matches', $r['kind'] === 'match'
  && in_array(['stack' => 'lk70-dbX', 'service' => 'db', 'via' => 'service-name', 'network' => 'lk70net'],
              $r['candidates'], true),
  json_encode($r));

// 3c. the case-insensitive name match must not become a fuzzy one — a name
// that merely resembles a real service is still nothing.
$r = staxx_crosslinks_match('lk70-appA', 'app', 'dbs');
ok('a name that is merely similar still does not match', $r['kind'] === 'none' && $r['candidates'] === [],
  json_encode($r));

// 4. this server's address plus a published port — matches.
$r = staxx_crosslinks_match('lk70-appB', '', $hostIp.':25432');
ok('this server\'s address plus a published port matches', $r['kind'] === 'match'
  && in_array(['stack' => 'lk70-dbport', 'service' => 'db2', 'via' => 'port', 'port' => '25432'],
              $r['candidates'], true),
  json_encode($r));

// 5. the same port, not published anywhere — does not match.
$r = staxx_crosslinks_match('lk70-appB', '', $hostIp.':25999');
ok('a port nothing publishes does not match', $r['kind'] === 'none' && $r['candidates'] === [],
  json_encode($r));

// 6. localhost and 127.0.0.1 — each their own explicit answer, not a silent
// no-match, and never falling through to the port route even when the port
// given happens to be a real one.
$r = staxx_crosslinks_match('lk70-appB', '', 'localhost:25432');
ok('localhost gets its own explicit answer', $r['kind'] === 'self' && stripos($r['reason'], 'localhost') !== false,
  json_encode($r));

$r = staxx_crosslinks_match('lk70-appB', '', '127.0.0.1:25432');
ok('127.0.0.1 gets its own explicit answer', $r['kind'] === 'self' && stripos($r['reason'], '127.0.0.1') !== false,
  json_encode($r));

// 7. a URL with the host buried in it — matches the same as case 1.
$r = staxx_crosslinks_match('lk70-appA', 'app', 'mysql://db:3306/appdb');
ok('a URL with the host buried in it matches', $r['kind'] === 'match'
  && in_array(['stack' => 'lk70-dbX', 'service' => 'db', 'via' => 'service-name', 'network' => 'lk70net'],
              $r['candidates'], true),
  json_encode($r));

/* --------------------------------------------------------------- refusals ---- */

// An invalid stack path never produces a match, however the value is typed.
$r = staxx_crosslinks_match('../etc', 'app', 'db');
ok('an invalid stack path yields no match', $r['kind'] !== 'match', json_encode($r));

$r = staxx_crosslinks_match('lk70-appA/../../etc', 'app', 'db');
ok('a path trying to escape the stack root yields no match', $r['kind'] !== 'match', json_encode($r));

/* ----------------------------------------------------------- credentials ---- */

// A confirmed target on a known image returns its own settings.
$c = staxx_crosslinks_credentials('lk70-dbX', 'db');
ok('credentials for a known image come back with the right values',
  $c['ok'] === true && $c['known'] === true
  && ($c['fields']['user']['value']     ?? '') === 'appuser'
  && ($c['fields']['password']['value'] ?? '') === 'xSecretOne1!'
  && ($c['fields']['rootPassword']['value'] ?? '') === 'rootSecretOne1!'
  && ($c['fields']['database']['value']     ?? '') === 'appdb',
  json_encode($c));

// The one rule: a reply carries values for the NAMED target only. dbY uses
// the same image and the same setting names with DIFFERENT values — proof
// this is not quietly answering about the wrong stack.
$c2 = staxx_crosslinks_credentials('lk70-dbY', 'db');
ok('a second stack on the same image returns its OWN values, not the first\'s',
  $c2['ok'] === true && ($c2['fields']['password']['value'] ?? '') === 'ySecretTwo2!'
  && ($c2['fields']['password']['value'] ?? '') !== ($c['fields']['password']['value'] ?? ''),
  json_encode($c2));

// An image the table does not recognise.
$c3 = staxx_crosslinks_credentials('lk70-unknown', 'app');
ok('an unrecognised image reports known:false, no fields',
  $c3['ok'] === true && $c3['known'] === false && !isset($c3['fields']), json_encode($c3));

// Refusal: a service the named stack does not have.
$c4 = staxx_crosslinks_credentials('lk70-dbX', 'no-such-service');
ok('an unknown service name is refused', $c4['ok'] === false && $c4['error'] !== '', json_encode($c4));

// Refusal: an invalid stack path.
$c5 = staxx_crosslinks_credentials('../etc', 'db');
ok('an invalid stack path is refused', $c5['ok'] === false && $c5['error'] !== '', json_encode($c5));

// Refusal: a path trying to escape the stack root.
$c6 = staxx_crosslinks_credentials('lk70-dbX/../../etc', 'db');
ok('a path escaping the stack root is refused', $c6['ok'] === false && $c6['error'] !== '', json_encode($c6));

// Refusal: a stack with no compose file at all.
$mk('lk70-empty', '');
@unlink($root.'/lk70-empty/compose.yaml');
$c7 = staxx_crosslinks_credentials('lk70-empty', 'db');
ok('a stack with no compose file is refused', $c7['ok'] === false && $c7['error'] !== '', json_encode($c7));

/* ---- names-only reply for an unrecognised image (PLAN_70 10.2/10.3) ---- */

// An unrecognised image WITH settings returns their NAMES, never a value —
// not even one shaped exactly like the password this stack actually holds.
$c8 = staxx_crosslinks_credentials('lk70-unknownvals', 'db');
ok('an unrecognised image with settings returns names only, no fields, no values',
  $c8['ok'] === true && $c8['known'] === false && !isset($c8['fields'])
  && is_array($c8['settingNames'] ?? null)
  && in_array('APP_DB_USER', $c8['settingNames'], true)
  && in_array('APP_DB_PASS', $c8['settingNames'], true)
  && strpos(json_encode($c8), 'itsSecretPass1!') === false,
  json_encode($c8));

/* ---------------------------------------------------------- learn() ---- */

// Refusal: a setting name the service does not actually have.
$learnBad = staxx_crosslinks_learn('lk70-unknownvals', 'db', ['password' => 'NO_SUCH_SETTING']);
ok('learn() refuses a setting name the service does not have',
  $learnBad['ok'] === false && $learnBad['error'] !== '', json_encode($learnBad));

// Refusal: neither box pointed at learns nothing.
$learnEmpty = staxx_crosslinks_learn('lk70-unknownvals', 'db', ['user' => '', 'password' => '']);
ok('learn() refuses when neither box is pointed at',
  $learnEmpty['ok'] === false && $learnEmpty['error'] !== '', json_encode($learnEmpty));

// Refusal: same shape as credentials()'s own path refusals.
$learnBadStack = staxx_crosslinks_learn('../etc', 'db', ['password' => 'APP_DB_PASS']);
ok('learn() refuses an invalid stack path', $learnBadStack['ok'] === false, json_encode($learnBadStack));

// The real pick: resolves 'fields' at once, no second round trip needed.
$learn = staxx_crosslinks_learn('lk70-unknownvals', 'db', ['user' => 'APP_DB_USER', 'password' => 'APP_DB_PASS']);
ok('learn() accepts a real pick and resolves fields immediately',
  $learn['ok'] === true
  && ($learn['fields']['user']['value']     ?? '') === 'someuser'
  && ($learn['fields']['password']['value'] ?? '') === 'itsSecretPass1!',
  json_encode($learn));

// The taught image is now known on its own — nobody has to be asked again.
$c9 = staxx_crosslinks_credentials('lk70-unknownvals', 'db');
ok('the taught image now reports known:true, with the same fields',
  $c9['ok'] === true && $c9['known'] === true
  && ($c9['fields']['user']['value']     ?? '') === 'someuser'
  && ($c9['fields']['password']['value'] ?? '') === 'itsSecretPass1!',
  json_encode($c9));

// "...including for the next application that connects to the same
// database" (10.2) — a SECOND, different stack on the same taught image is
// known too, and gets its OWN values, never the first stack's.
$mk('lk70-unknownvals2', <<<YAML
services:
  db:
    image: lk70test/customdb1
    environment:
      APP_DB_USER: seconduser
      APP_DB_PASS: secondPass2!
YAML
);
$c10 = staxx_crosslinks_credentials('lk70-unknownvals2', 'db');
ok('a second stack on the same taught image is known too, with its own values',
  $c10['ok'] === true && $c10['known'] === true
  && ($c10['fields']['user']['value']     ?? '') === 'seconduser'
  && ($c10['fields']['password']['value'] ?? '') === 'secondPass2!'
  && ($c10['fields']['password']['value'] ?? '') !== ($c9['fields']['password']['value'] ?? ''),
  json_encode($c10));

// The shipped table is consulted FIRST and a learned entry never overrides
// it — teaching a name for an image the table already recognises changes
// nothing about what credentials() actually returns for it.
$learnShipped = staxx_crosslinks_learn('lk70-dbX', 'db', ['user' => 'MARIADB_USER', 'password' => 'MARIADB_PASSWORD']);
ok('learn() accepts a pick even for an already-known image (nothing ever reads it back)',
  $learnShipped['ok'] === true, json_encode($learnShipped));
$cShipped = staxx_crosslinks_credentials('lk70-dbX', 'db');
ok('the shipped table still wins — its own rootPassword/database slots (no learned entry names them) are still there',
  $cShipped['ok'] === true && $cShipped['known'] === true
  && ($cShipped['fields']['rootPassword']['value'] ?? '') === 'rootSecretOne1!'
  && ($cShipped['fields']['database']['value']     ?? '') === 'appdb',
  json_encode($cShipped));

// A learned entry for one image must never leak into a different one.
$mk('lk70-unknownother', <<<YAML
services:
  app:
    image: lk70test/customdb2
    environment:
      APP_DB_USER: notlearned
      APP_DB_PASS: notlearnedSecret1!
YAML
);
$c11 = staxx_crosslinks_credentials('lk70-unknownother', 'app');
ok('a different, never-taught image stays unknown — nothing leaked from the one just taught',
  $c11['ok'] === true && $c11['known'] === false && !isset($c11['fields']), json_encode($c11));

echo "\n".($fails === 0 ? "all checks passed\n" : "$fails check(s) FAILED\n");
exit($fails === 0 ? 0 : 1);
