<?php
/* PLAN_131 C — one order for the top level: staxx_start_root_tokens(), the
 * shared helper staxx_folder_layout() and staxx_autostart_project() both walk
 * to interleave folders and loose stacks, plus the `root` scope in
 * staxx_start_order_set() and the rename/removal bookkeeping that keeps
 * `root` agreeing with the older `folders` and `stacks['']` lists.
 *
 * The boot projection is not exercised here — staxx_autostart_project()
 * needs a live Docker and Unraid's own boot file, which this suite must
 * never touch (see clash.php's own note on the same point). It calls
 * exactly the same staxx_start_root_tokens() this file drives directly, so
 * testing that one shared function is what makes the layout and the
 * projection unable to disagree in the first place.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. Needs STORE_ROOT
 * pointed at /tmp/zzstartorder-store, the same way tests/server/clash.php
 * does it:
 *
 *     pscp tests/server/startorder.php root@<box>:/tmp/
 *     plink … '
 *       CFG=/boot/config/plugins/staxx/staxx.cfg
 *       cp $CFG /tmp/cfg.bak
 *       grep -q "^STORE_ROOT=" $CFG \
 *         && sed -i "s#^STORE_ROOT=.*#STORE_ROOT=\"/tmp/zzstartorder-store\"#" $CFG \
 *         || echo "STORE_ROOT=\"/tmp/zzstartorder-store\"" >> $CFG
 *       php /tmp/startorder.php; RC=$?
 *       cp /tmp/cfg.bak $CFG
 *       exit $RC
 *     '
 *
 * Prints one line per case and exits non-zero on any failure. Creates and
 * removes its own folders and stacks, all named "zzso…", under the scratch
 * stacks folder.
 */

require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Folders.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

if (staxx_stack_root() !== '/tmp/zzstartorder-store/stacks') {
  echo "FAIL   the temporary stack root is not in place (got ".staxx_stack_root().")\n";
  exit(1);
}

$root = staxx_stack_root();
@exec('rm -rf '.escapeshellarg(dirname($root)));
mkdir($root, 0755, true);
// folders.json lives under <store>/config, and the save's lock is a mkdir
// beside it — without this folder every save reports "could not get an
// exclusive lock", which reads like a concurrency fault and is not one.
mkdir(staxx_config_root(), 0755, true);
mkdir($root.'/zzsoA', 0755, true);
mkdir($root.'/zzsoB', 0755, true);
// The loose "stacks" below are never read from disk by anything this suite
// calls — staxx_folder_layout() takes its stack list as an argument, and
// only the real folders above are looked up on disk (via
// staxx_folder_names()) — so these two exist only as names, not directories.
staxx_scan_stacks_reset();

/* ----------------------------------------------- staxx_start_root_tokens -- */

// No root key: folders first, then loose stacks, exactly today's shape.
$tokens = staxx_start_root_tokens(['zzsoA', 'zzsoB'], ['zzsoloose1', 'zzsoloose2'], []);
ok('no root key: folders first, then loose stacks',
   $tokens === ['folder:zzsoA', 'folder:zzsoB', 'stack:zzsoloose1', 'stack:zzsoloose2']);

// An interleaving root list is honoured.
$tokens = staxx_start_root_tokens(
  ['zzsoA', 'zzsoB'], ['zzsoloose1', 'zzsoloose2'],
  ['stack:zzsoloose2', 'folder:zzsoA', 'stack:zzsoloose1', 'folder:zzsoB']
);
ok('a root list interleaving folders and loose stacks is honoured',
   $tokens === ['stack:zzsoloose2', 'folder:zzsoA', 'stack:zzsoloose1', 'folder:zzsoB']);

// A token root mentions that no longer exists is skipped, not fatal.
$tokens = staxx_start_root_tokens(
  ['zzsoA'], ['zzsoloose1'],
  ['folder:zzsoGone', 'stack:zzsoloose1', 'folder:zzsoA']
);
ok('an unknown token in root is skipped rather than failing',
   $tokens === ['stack:zzsoloose1', 'folder:zzsoA']);

/* --------------------------------------------------- staxx_folder_layout -- */

$stacks = [
  ['folder' => 'zzsoA', 'leaf' => 'zzsoA-member', 'name' => 'zzsoA/zzsoA-member', 'running' => false],
  ['folder' => 'zzsoB', 'leaf' => 'zzsoB-member', 'name' => 'zzsoB/zzsoB-member', 'running' => false],
  ['folder' => '',      'leaf' => 'zzsoloose1',   'name' => 'zzsoloose1',        'running' => false],
  ['folder' => '',      'leaf' => 'zzsoloose2',   'name' => 'zzsoloose2',        'running' => false],
];

// staxx_scan_stacks() does not know about zzsoA-member/zzsoB-member unless
// they exist on disk — staxx_folder_layout() only reads $stacks for content,
// but staxx_folder_names() (called inside it) still comes from the real
// scan, so the two real folders made above are what it will find.
$rows = staxx_folder_layout($stacks);
$order = array_map(fn($r) => $r['type'] === 'folder' ? 'folder:'.$r['id'] : 'stack:'.$r['stack']['leaf'],
                    array_values(array_filter($rows, fn($r) => $r['type'] === 'folder' || $r['folder'] === '')));
ok('layout with no root key: folders first, then loose stacks',
   $order === ['folder:zzsoA', 'folder:zzsoB', 'stack:zzsoloose1', 'stack:zzsoloose2'],
   implode(',', $order));

$err = '';
ok('save a root order interleaving folders and loose stacks',
   staxx_start_order_set('root', '', [
     'stack:zzsoloose2', 'folder:zzsoB', 'stack:zzsoloose1', 'folder:zzsoA',
   ], $err), $err);

$rows = staxx_folder_layout($stacks);
$order = array_map(fn($r) => $r['type'] === 'folder' ? 'folder:'.$r['id'] : 'stack:'.$r['stack']['leaf'],
                    array_values(array_filter($rows, fn($r) => $r['type'] === 'folder' || $r['folder'] === '')));
ok('the saved root order is honoured by the layout',
   $order === ['stack:zzsoloose2', 'folder:zzsoB', 'stack:zzsoloose1', 'folder:zzsoA'],
   implode(',', $order));

// Saving root rewrites the two older lists to agree with it.
$start = staxx_start_load();
ok('saving root rewrites the folder list', $start['folders'] === ['zzsoB', 'zzsoA'], implode(',', $start['folders']));
ok('saving root rewrites the top-level stack list',
   $start['stacks'][''] === ['zzsoloose2', 'zzsoloose1'], implode(',', $start['stacks']['']));
ok('the root list itself is stored verbatim',
   $start['root'] === ['stack:zzsoloose2', 'folder:zzsoB', 'stack:zzsoloose1', 'folder:zzsoA']);

/* ----------------------------------------------------- refusals on save -- */

$err = '';
ok('a token naming a folder that does not exist is refused',
   !staxx_start_order_set('root', '', ['folder:zzsoNoSuchFolder'], $err) && $err !== '', $err);

$err = '';
ok('a token that is not folder: or stack: is refused',
   !staxx_start_order_set('root', '', ['banana:zzsoA'], $err) && $err !== '', $err);

$err = '';
ok('an invalid stack leaf is refused',
   !staxx_start_order_set('root', '', ['stack:../escape'], $err) && $err !== '', $err);

$err = '';
ok('root takes no parent', !staxx_start_order_set('root', 'zzsoA', ['folder:zzsoA'], $err) && $err !== '', $err);

// A refused save must not have touched anything already on disk.
$start = staxx_start_load();
ok('a refused save left root untouched',
   $start['root'] === ['stack:zzsoloose2', 'folder:zzsoB', 'stack:zzsoloose1', 'folder:zzsoA']);

/* --------------------------------------------- rename and removal keep place -- */

// Put root back to a known, simple shape before exercising rename/removal.
$err = '';
staxx_start_order_set('root', '', ['folder:zzsoA', 'folder:zzsoB', 'stack:zzsoloose1', 'stack:zzsoloose2'], $err);

$err = '';
ok('rename a folder', staxx_folder_rename('zzsoA', 'zzsoRenamed', $err), $err);
$start = staxx_start_load();
ok('the rename keeps the folder\'s place in root',
   $start['root'] === ['folder:zzsoRenamed', 'folder:zzsoB', 'stack:zzsoloose1', 'stack:zzsoloose2'],
   implode(',', $start['root']));

$err = '';
ok('delete an empty folder', staxx_folder_delete('zzsoB', $err), $err);
$start = staxx_start_load();
ok('deleting an empty folder drops its place in root, nothing left behind',
   $start['root'] === ['folder:zzsoRenamed', 'stack:zzsoloose1', 'stack:zzsoloose2'],
   implode(',', $start['root']));

// A folder holding a stack: deleting it must fold the stack's own token in
// where the folder's token was.
mkdir($root.'/zzsoRenamed/zzsoheld', 0755, true);
staxx_scan_stacks_reset();
$err = '';
ok('order the folder\'s own member before deleting it',
   staxx_start_order_set('stacks', 'zzsoRenamed', ['zzsoheld'], $err), $err);

$err = '';
ok('delete a folder holding one stack', staxx_folder_delete('zzsoRenamed', $err), $err);
$start = staxx_start_load();
ok('the moved stack takes the folder\'s old place in root',
   $start['root'] === ['stack:zzsoheld', 'stack:zzsoloose1', 'stack:zzsoloose2'],
   implode(',', $start['root']));

@exec('rm -rf '.escapeshellarg(dirname($root)));

echo $fails === 0 ? "\nAll good.\n" : "\n$fails case(s) failed.\n";
exit($fails === 0 ? 0 : 1);
