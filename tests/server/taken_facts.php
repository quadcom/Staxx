<?php
/* PLAN_190 item 2 — staxx_import_taken_facts()'s own parsing, checked
 * without Docker at all: hand-written rows fed straight to its new
 * ?array $rows parameter (the same injectable-input pattern
 * staxx_unraid_templates_at_risk(?array $containers) already uses), in the
 * shape staxx_docker_inspect_rows() returns — one string[10] per container,
 * in the shared template's own field order (id, network mode, IPs,
 * bindings, exposed ports, name, ports-with-\x1f, mounts-with-\x1f,
 * project, "end"). staxx_import_taken_facts() reads fields 0, 5, 6, 7, 8 of
 * each and ignores the rest.
 *
 * When $rows is given, the function skips its own request memo and the
 * `ss` listener read, so 'host' is always [] here — this suite is about the
 * ports/paths parsing, not the machine's own listening sockets.
 *
 * THIS SUITE BUILDS NOTHING, STARTS NOTHING, PULLS NOTHING AND REMOVES
 * NOTHING. No config keys needed, and no Docker call is made at all: every
 * row below is written by hand. Runs ON THE SERVER — there is no PHP on the
 * dev machine:
 *
 *     pscp tests/server/taken_facts.php root@<box>:/tmp/
 *     plink … "php /tmp/taken_facts.php"
 *
 * Prints one line per case and exits non-zero on any failure.
 */

require_once '/usr/local/emhttp/plugins/staxx/include/Import.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-4s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? "  ($note)" : '');
}

// A row builder matching the shared template's ten fields, so each case
// below only has to state what it is actually testing.
function row(string $id, string $name, string $ports, string $mounts, string $project): array {
  return [$id, 'bridge', '', '', '', $name, $ports, $mounts, $project, 'end'];
}

/* ---- a port published on both 0.0.0.0 and :: arrives once ---- */

$rows = [
  row('id1', '/webapp', '80/tcp=0.0.0.0:8080'."\x1f".'80/tcp=[::]:8080'."\x1f", '', 'mystack'),
];
$facts = staxx_import_taken_facts($rows);
ok('a port on 0.0.0.0 and :: arrives once',
  count($facts['ports']) === 1
  && $facts['ports'][0]['port'] === '8080'
  && $facts['ports'][0]['proto'] === 'tcp'
  && $facts['ports'][0]['container'] === 'webapp'
  && $facts['ports'][0]['project'] === 'mystack');

/* ---- no ports, no mounts: nothing, and not an error ---- */

$rows = [ row('id2', '/quiet', '', '', 'mystack') ];
$facts = staxx_import_taken_facts($rows);
ok('no ports and no mounts yields nothing', $facts['ports'] === [] && $facts['paths'] === []);

/* ---- a read-only bind mount is left out (the template only ever emits
 * a writable one, so a hand-written row standing in for a read-only mount
 * is simply one with nothing in the mounts field) ---- */

$rows = [ row('id3', '/reader', '', '', 'mystack') ];
$facts = staxx_import_taken_facts($rows);
ok('a read-only mount (never in the mounts field) is left out', $facts['paths'] === []);

/* ---- a host path containing a space survives whole ---- */

$rows = [ row('id4', '/withspace', '', '/mnt/user/appdata/my app'."\x1f", 'mystack') ];
$facts = staxx_import_taken_facts($rows);
ok('a host path with a space survives whole',
  count($facts['paths']) === 1 && $facts['paths'][0]['path'] === '/mnt/user/appdata/my app');

/* ---- no compose label: project reports as '' ---- */

$rows = [ row('id5', '/handstarted', '80/tcp=0.0.0.0:9000'."\x1f", '', '') ];
$facts = staxx_import_taken_facts($rows);
ok('no compose label reports project as empty',
  count($facts['ports']) === 1 && $facts['ports'][0]['project'] === '');

/* ---- a row with nine fields (the trailing "end" sentinel missing) is
 * dropped, exactly as an exec()-trimmed line from the real command would
 * be ---- */

$short = ['id6', 'bridge', '', '', '', '/short', '80/tcp=0.0.0.0:1234'."\x1f", '', 'mystack'];
ok('a nine-field row has fewer than ten fields', count($short) === 9);
$facts = staxx_import_taken_facts([$short]);
ok('a nine-field row is dropped', $facts['ports'] === [] && $facts['paths'] === []);

/* ---- $rows given: no real ss read, so 'host' is always [] ---- */

$facts = staxx_import_taken_facts([]);
ok('an injected (even empty) $rows skips the ss read', $facts['host'] === []);

/* ---- everything together, one call, in Docker's own order ---- */

$rows = [
  row('idA', '/one', '80/tcp=0.0.0.0:8080'."\x1f".'80/tcp=[::]:8080'."\x1f", '/data/one'."\x1f", 'stackone'),
  row('idB', '/two', '', '/data/two'."\x1f".'/data/two/sub'."\x1f", ''),
];
$facts = staxx_import_taken_facts($rows);
ok('a mixed batch keeps both containers\' own facts',
  count($facts['ports']) === 1 && count($facts['paths']) === 3
  && $facts['paths'][0]['container'] === 'one' && $facts['paths'][0]['project'] === 'stackone'
  && $facts['paths'][1]['container'] === 'two' && $facts['paths'][1]['project'] === ''
  && $facts['paths'][2]['container'] === 'two');

echo $fails === 0 ? "\nAll checks passed.\n" : "\n$fails check(s) FAILED.\n";
exit($fails === 0 ? 0 : 1);
