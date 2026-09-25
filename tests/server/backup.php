<?PHP
/* Is the store named in the Appdata Backup plugin's extras list — the
 * read-only check in Backup.php, against the real installed file.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. It needs
 * STORE_ROOT pointed at a scratch path under /tmp, because
 * staxx_backup_owned_paths() (PLAN_186: one path, the store itself) and
 * staxx_backup_covers_store()'s subfolder fallback both derive from it, and
 * the fixtures below are written against those values. staxx_cfg() memoises
 * on first read, so it has to be seeded into the config file BEFORE php runs:
 *
 *     pscp tests/server/backup.php root@<box>:/tmp/
 *
 *     plink -ssh -batch -pw <pw> root@<box> '
 *       CFG=/boot/config/plugins/staxx/staxx.cfg
 *       cp $CFG /tmp/cfg.bak
 *       grep -q "^STORE_ROOT=" $CFG \
 *         && sed -i "s#^STORE_ROOT=.*#STORE_ROOT=\"/tmp/bk-store\"#" $CFG \
 *         || echo "STORE_ROOT=\"/tmp/bk-store\"" >> $CFG
 *       php /tmp/backup.php; RC=$?
 *       cp /tmp/cfg.bak $CFG
 *       diff -q /tmp/cfg.bak $CFG && echo CONFIG_IDENTICAL
 *       exit $RC
 *     '
 *
 * NEVER reads the real /boot/config/plugins/appdata.backup/config.json, and
 * nothing anywhere in StaXX writes it. Every case here builds its own fixture
 * config under /tmp and passes the path in, which is the only reason that
 * file is a parameter at all.
 *
 * The NEGATIVE cases are the point of this suite. A format change on the
 * other plugin's side has to make this feature go quiet, never make it shout:
 * a missing file, a truncated one, a renamed key and a key of the wrong type
 * must each produce null — "say nothing" — and any one of them returning a
 * "not listed" verdict instead would put a false alarm in front of somebody
 * whose backups are fine. */

require_once '/usr/local/emhttp/plugins/staxx/include/Backup.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

if (staxx_stack_root() !== '/tmp/bk-store/stacks') {
  echo "FAIL   the temporary stack root is not in place (got ".staxx_stack_root().")\n";
  exit(1);
}
if (staxx_archive_root() !== '/tmp/bk-store/archives') {
  echo "FAIL   the temporary archive root is not in place (got ".staxx_archive_root().")\n";
  exit(1);
}

$dir = '/tmp/bk-fixtures';
@exec('rm -rf '.escapeshellarg($dir));
@mkdir($dir, 0777, true);

/** Write one fixture config and hand back its path. */
function fixture(string $name, string $body): string {
  global $dir;
  $p = $dir.'/'.$name.'.json';
  file_put_contents($p, $body);
  return $p;
}

/* ---------------------------------------------------------------- silence --
 * Every one of these must be null. Not "[]", not "everything missing" — null,
 * the value that means no claim is available. */

echo "-- nothing can be said (must all be silent) --\n";

ok('a config file that does not exist',
   staxx_backup_entries($dir.'/absent.json') === null);

ok('a file that is not JSON at all',
   staxx_backup_entries(fixture('garbage', "not json {{{\n")) === null);

ok('valid JSON truncated mid-object',
   staxx_backup_entries(fixture('truncated', '{"includeFiles": ["/mnt/user/appdata/x"')) === null);

ok('valid JSON with the key renamed',
   staxx_backup_entries(fixture('renamed', '{"extraFiles": ["/mnt/user/appdata/x"]}')) === null);

ok('the key holding a number',
   staxx_backup_entries(fixture('number', '{"includeFiles": 42}')) === null);

ok('the key holding a list with a non-string in it',
   staxx_backup_entries(fixture('mixed', '{"includeFiles": ["/mnt/user/appdata/x", 7]}')) === null);

ok('an empty file',
   staxx_backup_entries(fixture('empty', '')) === null);

ok('coverage is silent for all of those too',
   staxx_backup_coverage($dir.'/absent.json') === null
   && staxx_backup_coverage(fixture('renamed2', '{"extraFiles": []}')) === null);

ok('the single-path question is silent for those too',
   staxx_backup_lists_path('/tmp/bk-store/stacks', $dir.'/absent.json') === null);

/* ------------------------------------------------------------- what counts --
 * A list that reads fine, and what it does and does not cover. */

echo "\n-- an entry that reads fine --\n";

$empty = fixture('emptylist', '{"includeFiles": []}');
ok('an empty list is a real answer, not silence',
   staxx_backup_entries($empty) === []);

$cov = staxx_backup_coverage($empty);
ok('with nothing listed, the store is missing',
   $cov !== null && $cov['listed'] === [] && $cov['missing'] === ['/tmp/bk-store'],
   $cov === null ? 'null' : implode(' + ', $cov['missing']));

// Their settings page stores this list with Windows line endings and trailing
// slashes, so the real file's shape has to be understood, not just a tidy one.
$direct = fixture('direct', json_encode(['includeFiles' => ["/tmp/bk-store/\r"]]));
$cov = staxx_backup_coverage($direct);
ok('the store named directly (CRLF and a trailing slash tolerated) is covered',
   $cov !== null && $cov['missing'] === [],
   $cov === null ? 'null' : implode(' + ', $cov['missing']));

// The same, handed over as one textarea-shaped string rather than a list.
$asString = fixture('asstring', json_encode(['includeFiles' => "/tmp/bk-store/\r\n"]));
$cov = staxx_backup_coverage($asString);
ok('a single CRLF-separated string is read the same way',
   $cov !== null && $cov['missing'] === [],
   $cov === null ? 'null' : implode(' + ', $cov['missing']));

$parent = fixture('parent', '{"includeFiles": ["/tmp"]}');
$cov = staxx_backup_coverage($parent);
ok('a listed parent folder covers the store',
   $cov !== null && $cov['missing'] === [],
   $cov === null ? 'null' : implode(' + ', $cov['missing']));

// PLAN_186: naming all three subfolders separately — the shape the dialog
// used to ask for — still has to read as the store being covered, so nobody
// who did it that way is told they are missing something.
$allThree = fixture('allthree',
  '{"includeFiles": ["/tmp/bk-store/stacks", "/tmp/bk-store/archives", "/tmp/bk-store/config"]}');
$cov = staxx_backup_coverage($allThree);
ok('all three subfolders listed separately still count as the store covered',
   $cov !== null && $cov['missing'] === [],
   $cov === null ? 'null' : implode(' + ', $cov['missing']));

// Today's common shape on an install that followed the old advice: stacks
// and archives named, but not config — so the store as a whole is missing.
$partial = fixture('partial', '{"includeFiles": ["/tmp/bk-store/stacks", "/tmp/bk-store/archives"]}');
$cov = staxx_backup_coverage($partial);
ok('stacks and archives listed but not config: the store is reported missing',
   $cov !== null && $cov['listed'] === [] && $cov['missing'] === ['/tmp/bk-store']);

$near = fixture('near', '{"includeFiles": ["/tmp/bk-store-other", "/tmp/bk-storex"]}');
$cov = staxx_backup_coverage($near);
ok('a path that merely starts the same is not a match',
   $cov !== null && $cov['listed'] === [] && $cov['missing'] === ['/tmp/bk-store']);

/* ------------------------------------------------- the share-layer twin ----
 * /mnt/user/appdata/x and /mnt/<pool>/appdata/x are one directory when that
 * share lives on that pool, so an entry written either way has to count. Two
 * DIFFERENT pools sharing a tail are not the same place, and treating them as
 * one would be the silence that hides a real gap. */

echo "\n-- the /mnt/user twin of a pool path --\n";

ok('a listed /mnt/user path covers the pool path',
   staxx_backup_covers_one('/mnt/user/appdata/staxx-stacks', '/mnt/m2cache/appdata/staxx-stacks'));

ok('and the other way round',
   staxx_backup_covers_one('/mnt/m2cache/appdata/staxx-stacks', '/mnt/user/appdata/staxx-stacks'));

ok('a listed /mnt/user ANCESTOR covers the pool path inside it',
   staxx_backup_covers_one('/mnt/user/appdata', '/mnt/m2cache/appdata/staxx-stacks'));

ok('two different pools sharing a tail are NOT the same place',
   !staxx_backup_covers_one('/mnt/cache-big/appdata/staxx-stacks', '/mnt/m2cache/appdata/staxx-stacks'));

ok('a different tail on the share layer is not a match',
   !staxx_backup_covers_one('/mnt/user/appdata/something-else', '/mnt/m2cache/appdata/staxx-stacks'));

ok('a path outside /mnt is compared literally and nothing else',
   staxx_backup_covers_one('/tmp/bk-store/stacks', '/tmp/bk-store/stacks/inner')
   && !staxx_backup_covers_one('/tmp/other', '/tmp/bk-store/stacks'));

/* ----------------------------------------------------- the owned-path set --
 * PLAN_186: staxx_backup_owned_paths() names the store itself, not its
 * stacks/archives subfolders separately — one path covers config too, which
 * the old two-path answer never asked about at all. */

echo "\n-- which folder StaXX says it owns --\n";

$owned = staxx_backup_owned_paths();
ok('the store itself is the one owned path',
   $owned === ['/tmp/bk-store'], implode(' + ', $owned));

/* ------------------------------------------------- the stale old entry -----
 * After a move the old path is still listed, naming a folder that no longer
 * exists. The backup then keeps running, keeps reporting success and copies
 * nothing, which is why this question is asked separately. */

echo "\n-- is an old path still listed --\n";

// PLAN_186: the old path asked about here is now the old STORE folder — a
// move relocates the whole store, not just its stacks subfolder, so that is
// what action.php now hands to this question after one completes.
$stale = fixture('stale', '{"includeFiles": ["/tmp/bk-old-store", "/tmp/bk-store"]}');
ok('an old path still in the list is reported as still there',
   staxx_backup_lists_path('/tmp/bk-old-store', $stale) === true);
ok('a path genuinely absent is reported false, not null',
   staxx_backup_lists_path('/tmp/bk-never', $stale) === false);
ok('an empty path is no claim rather than a false one',
   staxx_backup_lists_path('   ', $stale) === null);

/* --------------------------------------------------- the partial-setup notice --
 * staxx_backup_partial_notice(): true only for an install that followed the
 * OLD advice (stacks and/or archives named separately) and has not yet
 * covered the store as a whole. $installed is passed explicitly so these
 * cases do not depend on whether the box running this suite happens to have
 * the Appdata Backup plugin installed. */

echo "\n-- the partial-setup notice --\n";

ok('stacks and archives named but not the store: the notice fires',
   staxx_backup_partial_notice(
     fixture('notice-partial', '{"includeFiles": ["/tmp/bk-store/stacks", "/tmp/bk-store/archives"]}'),
     true
   ) === true);

ok('the store itself named: no notice — it is already covered',
   staxx_backup_partial_notice(fixture('notice-full', '{"includeFiles": ["/tmp/bk-store"]}'), true) === false);

ok('nothing of StaXX named: no notice — that is "not set up", not "set up the old way"',
   staxx_backup_partial_notice(fixture('notice-none', '{"includeFiles": ["/tmp/unrelated"]}'), true) === false);

ok('the plugin not installed: no notice, whatever the list says',
   staxx_backup_partial_notice(
     fixture('notice-wouldfire', '{"includeFiles": ["/tmp/bk-store/stacks"]}'),
     false
   ) === false);

ok('an unreadable config: no notice',
   staxx_backup_partial_notice($dir.'/absent.json', true) === false);

/* ------------------------------------------------------------------- out ---
 * The one thing this suite must never do is leave anything behind that could
 * be mistaken for a real setting, so the fixtures go. */

@exec('rm -rf '.escapeshellarg($dir));

echo "\n";
if ($fails) { echo "$fails case(s) FAILED\n"; exit(1); }
echo "all cases passed\n";
?>
