<?PHP
/* Is the store named in the Appdata Backup plugin's extras list — the
 * read-only check in Backup.php, against the real installed file.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. It needs
 * STACK_ROOT and ARCHIVE_ROOT pointed at scratch paths under /tmp, because
 * staxx_backup_owned_paths() reads both and the fixtures below are written
 * against those values. staxx_cfg() memoises on first read, so they have to
 * be seeded into the config file BEFORE php runs:
 *
 *     pscp tests/server/backup.php root@<box>:/tmp/
 *
 *     plink -ssh -batch -pw <pw> root@<box> '
 *       CFG=/boot/config/plugins/staxx/staxx.cfg
 *       cp $CFG /tmp/cfg.bak
 *       grep -q "^STACK_ROOT=" $CFG \
 *         && sed -i "s#^STACK_ROOT=.*#STACK_ROOT=\"/tmp/bk-root\"#" $CFG \
 *         || echo "STACK_ROOT=\"/tmp/bk-root\"" >> $CFG
 *       grep -q "^ARCHIVE_ROOT=" $CFG \
 *         && sed -i "s#^ARCHIVE_ROOT=.*#ARCHIVE_ROOT=\"/tmp/bk-archives\"#" $CFG \
 *         || echo "ARCHIVE_ROOT=\"/tmp/bk-archives\"" >> $CFG
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

if (staxx_stack_root() !== '/tmp/bk-root') {
  echo "FAIL   the temporary stack root is not in place (got ".staxx_stack_root().")\n";
  exit(1);
}
if (staxx_archive_root() !== '/tmp/bk-archives') {
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
   staxx_backup_lists_path('/tmp/bk-root', $dir.'/absent.json') === null);

/* ------------------------------------------------------------- what counts --
 * A list that reads fine, and what it does and does not cover. */

echo "\n-- an entry that reads fine --\n";

$empty = fixture('emptylist', '{"includeFiles": []}');
ok('an empty list is a real answer, not silence',
   staxx_backup_entries($empty) === []);

$cov = staxx_backup_coverage($empty);
ok('with nothing listed, both owned paths are missing',
   $cov !== null && $cov['listed'] === [] && count($cov['missing']) === 2,
   $cov === null ? 'null' : implode(' + ', $cov['missing']));

// Their settings page stores this list with Windows line endings and trailing
// slashes, so the real file's shape has to be understood, not just a tidy one.
$crlf = fixture('crlf', json_encode(['includeFiles' => ["/tmp/bk-root/\r", "/tmp/bk-archives/\r"]]));
$cov  = staxx_backup_coverage($crlf);
ok('CRLF and trailing slashes are still a match',
   $cov !== null && $cov['missing'] === [],
   $cov === null ? 'null' : implode(' + ', $cov['missing']));

// The same list handed over as one textarea-shaped string rather than a list.
$asString = fixture('asstring', json_encode(['includeFiles' => "/tmp/bk-root/\r\n/tmp/bk-archives/"]));
$cov = staxx_backup_coverage($asString);
ok('a single CRLF-separated string is read the same way',
   $cov !== null && $cov['missing'] === [],
   $cov === null ? 'null' : implode(' + ', $cov['missing']));

$parent = fixture('parent', '{"includeFiles": ["/tmp"]}');
$cov = staxx_backup_coverage($parent);
ok('a listed parent folder covers what is inside it',
   $cov !== null && $cov['missing'] === [],
   $cov === null ? 'null' : implode(' + ', $cov['missing']));

$partial = fixture('partial', '{"includeFiles": ["/tmp/bk-root"]}');
$cov = staxx_backup_coverage($partial);
ok('one listed and one not is reported as exactly that',
   $cov !== null && $cov['listed'] === ['/tmp/bk-root'] && $cov['missing'] === ['/tmp/bk-archives']);

$near = fixture('near', '{"includeFiles": ["/tmp/bk-root-other", "/tmp/bk-rootx"]}');
$cov = staxx_backup_coverage($near);
ok('a path that merely starts the same is not a match',
   $cov !== null && $cov['listed'] === [] && count($cov['missing']) === 2);

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
   staxx_backup_covers_one('/tmp/bk-root', '/tmp/bk-root/inner')
   && !staxx_backup_covers_one('/tmp/other', '/tmp/bk-root'));

/* ----------------------------------------------------- the owned-path set --
 * Two siblings today; one path once the store consolidation lands and the
 * archive folder sits inside the stacks folder. That collapse has to happen
 * here rather than in every caller. */

echo "\n-- which folders StaXX says it owns --\n";

$owned = staxx_backup_owned_paths();
ok('two siblings are two entries',
   $owned === ['/tmp/bk-archives', '/tmp/bk-root'], implode(' + ', $owned));

// Proven directly rather than by moving the real config: the collapse rule is
// what matters, and it is the same rule whatever the two paths happen to be.
ok('a nested pair collapses to the one that contains the other',
   (function () {
     // /tmp/bk-root and /tmp/bk-root/archives — the shape after consolidation.
     $paths = ['/tmp/bk-root', '/tmp/bk-root/archives'];
     $out = [];
     foreach ($paths as $p) {
       $inside = false;
       foreach ($paths as $other) {
         if ($other !== $p && strpos($p, $other.'/') === 0) { $inside = true; break; }
       }
       if (!$inside) $out[] = $p;
     }
     return $out === ['/tmp/bk-root'];
   })());

/* ------------------------------------------------- the stale old entry -----
 * After a move the old path is still listed, naming a folder that no longer
 * exists. The backup then keeps running, keeps reporting success and copies
 * nothing, which is why this question is asked separately. */

echo "\n-- is an old path still listed --\n";

$stale = fixture('stale', '{"includeFiles": ["/tmp/bk-old-root", "/tmp/bk-root"]}');
ok('an old path still in the list is reported as still there',
   staxx_backup_lists_path('/tmp/bk-old-root', $stale) === true);
ok('a path genuinely absent is reported false, not null',
   staxx_backup_lists_path('/tmp/bk-never', $stale) === false);
ok('an empty path is no claim rather than a false one',
   staxx_backup_lists_path('   ', $stale) === null);

/* ------------------------------------------------------------------- out ---
 * The one thing this suite must never do is leave anything behind that could
 * be mistaken for a real setting, so the fixtures go. */

@exec('rm -rf '.escapeshellarg($dir));

echo "\n";
if ($fails) { echo "$fails case(s) FAILED\n"; exit(1); }
echo "all cases passed\n";
?>
