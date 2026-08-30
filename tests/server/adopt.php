<?php
/* PLAN_102 Phase 1 — staxx_create_refusal(), the whole decision behind the
 * "save" endpoint's adoption gate: whether a new compose file may be written
 * into a directory, including one that already exists, when the caller
 * claims adoption of a fileless folder.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. Needs STORE_ROOT
 * pointed at /tmp/p102-store, the same way tests/server/files.php does it —
 * never the real store:
 *
 *     pscp tests/server/adopt.php root@<box>:/tmp/
 *     plink … '
 *       CFG=/boot/config/plugins/staxx/staxx.cfg
 *       cp $CFG /tmp/cfg.bak
 *       grep -q "^STORE_ROOT=" $CFG \
 *         && sed -i "s#^STORE_ROOT=.*#STORE_ROOT=\"/tmp/p102-store\"#" $CFG \
 *         || echo "STORE_ROOT=\"/tmp/p102-store\"" >> $CFG
 *       php /tmp/adopt.php; RC=$?
 *       cp /tmp/cfg.bak $CFG
 *       diff -q /tmp/cfg.bak $CFG && echo CONFIG_IDENTICAL
 *       exit $RC
 *     '
 *
 * Prints one line per case and exits non-zero on any failure. Creates and
 * removes its own stacks, "zzadopt…", under the temporary stack root. Runs
 * no docker command and pulls nothing — everything here is filesystem and
 * PHP-function calls.
 *
 * staxx_create_refusal() is the whole gate now — one pure function, so every
 * row of the table is provable directly, refusal wording included. The two
 * allowed rows assert it returns '' and then still drive staxx_save_stack()
 * for real, proving the save actually lands and loses nothing.
 *
 * WHAT THIS CANNOT PROVE: that action.php's 'save' case actually calls
 * staxx_create_refusal() with the right arguments and acts on what it
 * returns — that is one `if` in the endpoint itself, and proving it end to
 * end needs an HTTP request this suite has no way to make. Re-read this
 * file against action.php's 'save' case whenever that case changes.
 */

require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Record.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

if (staxx_stack_root() !== '/tmp/p102-store/stacks') {
  echo "FAIL   the temporary stack root is not in place (got ".staxx_stack_root().")\n";
  exit(1);
}

$root = staxx_stack_root();
@exec('rm -rf '.escapeshellarg($root));
mkdir($root, 0755, true);

$compose = "services:\n  a:\n    image: alpine:3.20\n";

/* ------------------------------------------------------- row 1: create ---- */
// New stack, directory absent -> created.

$freshRel = 'zzadoptfresh';
$freshDir = $root.'/'.$freshRel;

ok('row1: directory is genuinely absent beforehand', !is_dir($freshDir));
ok('row1: the gate allows it (adopt=false)', staxx_create_refusal($freshRel, false) === '');

$err = '';
ok('row1: a brand-new stack saves', staxx_save_stack($freshRel, $compose, $err), $err);
ok('row1: the compose file exists', file_exists($freshDir.'/compose.yaml'));
ok('row1: the content landed byte-for-byte',
   file_get_contents($freshDir.'/compose.yaml') === $compose);

/* -------------------------------------------- row 2: name clash, no claim ---- */
// New stack, directory exists, adoption not claimed -> refused, name-clash
// wording. Also proves the same directory WOULD be allowed had adoption been
// claimed and it held no compose file - the exact narrowing PLAN_102 asks
// for, both halves checked against the one real function.

$clashRel = 'zzadoptclash';
$clashDir = $root.'/'.$clashRel;
mkdir($clashDir, 0755, true);
file_put_contents($clashDir.'/.env', 'PRE_EXISTING=1');

$refusal = staxx_create_refusal($clashRel, false);
ok('row2: refused when adoption is not claimed', $refusal !== '');
ok('row2: it is the name-clash wording, naming the stack',
   strpos($refusal, 'already exists') !== false && strpos($refusal, $clashRel) !== false,
   $refusal);

ok('row2: the very same directory is allowed once adoption IS claimed '
 . '- the refusal is narrowed by the claim, not by the directory',
   staxx_create_refusal($clashRel, true) === '');

/* ------------------------------------------- row 3: adopt, folder absent ---- */
// Adoption claimed, directory absent -> refused, "no folder..." wording. Also
// proves the same absent directory is fine for an ordinary (non-adopting)
// new stack - claiming adoption of nothing is what turns absence into a
// refusal, not absence on its own.

$goneRel = 'zzadoptgone';
$goneDir = $root.'/'.$goneRel;
@exec('rm -rf '.escapeshellarg($goneDir));

ok('row3: the directory is genuinely absent', !is_dir($goneDir));
$refusal = staxx_create_refusal($goneRel, true);
ok('row3: refused when adoption is claimed against nothing', $refusal !== '');
ok('row3: it names the missing folder',
   strpos($refusal, 'no folder') !== false && strpos($refusal, $goneRel) !== false, $refusal);
ok('row3: the same absence is fine for an ordinary new stack',
   staxx_create_refusal($goneRel, false) === '');

/* ------------------------------------------------- row 4: adopt, allowed ---- */
// Adoption claimed, directory exists, holds no compose file -> allowed. The
// gate returns '', and staxx_save_stack() is then driven for real, plus the
// three "nothing lost" cases PLAN_102 calls out explicitly.

$adoptRel = 'zzadoptok';
$adoptDir = $root.'/'.$adoptRel;
mkdir($adoptDir, 0755, true);
$envBody = "PRE_EXISTING=1\nSECOND=two\n";
file_put_contents($adoptDir.'/.env', $envBody);

ok('row4: the directory exists beforehand', is_dir($adoptDir));
ok('row4: the gate allows adopting it', staxx_create_refusal($adoptRel, true) === '');

$err = '';
ok('row4: adopting an existing, fileless directory saves',
   staxx_save_stack($adoptRel, $compose, $err), $err);
ok('row4: the compose file now exists', file_exists($adoptDir.'/compose.yaml'));
ok('row4: its content landed byte-for-byte',
   file_get_contents($adoptDir.'/compose.yaml') === $compose);
ok('row4: the unrelated file already there is untouched, byte for byte',
   file_get_contents($adoptDir.'/.env') === $envBody);

// The "recorded from the start" case: a first save on an adopted directory
// keeps nothing yet - there was no compose file before it to keep, exactly
// like an ordinary brand-new stack's first save (see tests/server/record.php,
// "capture with no compose file present keeps nothing and still succeeds").
// A second save is what proves the point PLAN_102 makes: it captures the
// FIRST save's own content, so going back reaches all the way to what was
// originally adopted rather than starting one edit late.
ok('row4: the first save on an adopted directory keeps no history yet - '
 . 'same as any other stack first save',
   staxx_record_list($adoptRel) === []);

$secondBody = "services:\n  a:\n    image: alpine:3.21\n";
$err = '';
ok('row4: a second save on the adopted stack succeeds',
   staxx_save_stack($adoptRel, $secondBody, $err), $err);
$history = staxx_record_list($adoptRel);
ok('row4: exactly one version exists after the second save', count($history) === 1,
   'count='.count($history));
ok('row4: it holds the FIRST save content - going back reaches the start',
   count($history) === 1 && staxx_record_get($adoptRel, $history[0]['n']) === $compose);

/* --------------------------------------- row 5: adopt, file already there ---- */
// Adoption claimed, directory exists, holds a compose file -> refused,
// naming the file. One directory per accepted filename, because the plan
// asks this be proven for every name the lister accepts, not just
// compose.yaml - read from STAXX_COMPOSE_FILENAMES so a filename added
// there later is covered automatically rather than needing this file
// updated by hand.

foreach (STAXX_COMPOSE_FILENAMES as $filename) {
  $hasRel = 'zzadopthas-'.str_replace('.', '', $filename);
  $hasDir = $root.'/'.$hasRel;
  @exec('rm -rf '.escapeshellarg($hasDir));
  mkdir($hasDir, 0755, true);
  file_put_contents($hasDir.'/'.$filename, $compose);

  $refusal = staxx_create_refusal($hasRel, true);
  ok('row5: '.$filename.' - adopting it is refused', $refusal !== '');
  ok('row5: '.$filename.' - the refusal names it',
     strpos($refusal, $filename) !== false, $refusal);

  @exec('rm -rf '.escapeshellarg($hasDir));
}

/* ------------------------------------------------------------ cleanup ---- */

@exec('rm -rf '.escapeshellarg($root));

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
