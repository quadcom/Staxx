<?php
/* PLAN_148 phase 4 — the server side of merging several stacks into one:
 * staxx_merge_stacks() itself (the write, the companion-file copy, the one
 * named history entry, image history carried across, the leftover's own
 * record note) and every refusal it must produce before anything is
 * written.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. Needs STORE_ROOT
 * pointed at /tmp/zzc148-store, the same way tests/server/clash.php does
 * it — never the real store:
 *
 *     pscp tests/server/merge.php root@<box>:/tmp/
 *     plink … '
 *       CFG=/boot/config/plugins/staxx/staxx.cfg
 *       cp $CFG /tmp/cfg.bak
 *       grep -q "^STORE_ROOT=" $CFG \
 *         && sed -i "s#^STORE_ROOT=.*#STORE_ROOT=\"/tmp/zzc148-store\"#" $CFG \
 *         || echo "STORE_ROOT=\"/tmp/zzc148-store\"" >> $CFG
 *       php /tmp/merge.php; RC=$?
 *       cp /tmp/cfg.bak $CFG
 *       exit $RC
 *     '
 *
 * Prints one line per case and exits non-zero on any failure. Creates and
 * removes its own stacks, all named "zzc148…", under the scratch stacks
 * folder. Nothing here starts, stops or pulls anything — a merge's write
 * half never touches Docker at all, only files.
 */

require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Merge.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

if (staxx_stack_root() !== '/tmp/zzc148-store/stacks') {
  echo "FAIL   the temporary stack root is not in place (got ".staxx_stack_root().")\n";
  exit(1);
}

$root = staxx_stack_root();
@exec('rm -rf '.escapeshellarg($root));
mkdir($root, 0755, true);

function mkStack(string $root, string $rel, string $compose, array $extras = []): void {
  $dir = $root.'/'.$rel;
  mkdir($dir, 0755, true);
  file_put_contents($dir.'/compose.yaml', $compose);
  foreach ($extras as $name => $body) file_put_contents($dir.'/'.$name, $body);
}

/* ========================================================================
 * A. An ordinary, clean merge
 * ======================================================================== */

mkStack($root, 'zzc148web', "services:\n  web:\n    image: nginx:latest\n");
mkStack($root, 'zzc148db', "services:\n  db:\n    image: mariadb:11\n", ['note.txt' => "a companion file\n"]);
staxx_scan_stacks_reset();

$merged = "services:\n  web:\n    image: nginx:latest\n\n"
        . "  # folded in from zzc148db, 2026-09-14\n  db:\n    image: mariadb:11\n";

$fp = staxx_stack_fingerprint('zzc148web');
$error = ''; $facts = null;
$okMerge = staxx_merge_stacks('zzc148web', ['zzc148db'], $merged, null, [], $error, $facts);
ok('a clean merge succeeds', $okMerge, $error);

$written = @file_get_contents($root.'/zzc148web/compose.yaml');
ok('the host\'s file is exactly the merged text handed to it', $written === $merged);
ok('the companion file was copied into the host\'s folder',
   is_file($root.'/zzc148web/note.txt') && file_get_contents($root.'/zzc148web/note.txt') === "a companion file\n");
ok('the incoming stack\'s own file is untouched — a merge is not a move',
   is_file($root.'/zzc148db/compose.yaml') && is_file($root.'/zzc148db/note.txt'));

$versions = staxx_record_list('zzc148web');
$named = array_values(array_filter($versions, fn($v) => $v['name'] !== ''));
ok('exactly one history entry was named, for the merge', count($named) === 1);
ok('its name says what was merged in', strpos($named[0]['name'] ?? '', 'zzc148db') !== false);

$restored = staxx_record_get('zzc148web', $named[0]['n']);
ok('stepping back to that named version restores the exact pre-merge file',
   $restored === "services:\n  web:\n    image: nginx:latest\n");

$leftover = staxx_record_merged_into('zzc148db');
ok('the leftover\'s own record now says it was folded into the host',
   is_array($leftover) && ($leftover['host'] ?? '') === 'zzc148web' && is_int($leftover['at'] ?? null));

$rows = [];
foreach (staxx_list_stacks() as $row) $rows[$row['name']] = $row;
ok('staxx_list_stacks() surfaces the same fact on the leftover\'s own row',
   ($rows['zzc148db']['mergedInto']['host'] ?? '') === 'zzc148web');
// array_key_exists, not ??: the correct answer here IS null, and ?? cannot
// tell a null value from an absent key — written with ?? this case could
// never pass however right the code was.
ok('the host\'s own row carries no such mark — it was not folded into anything',
   array_key_exists('zzc148web', $rows)
   && array_key_exists('mergedInto', $rows['zzc148web'])
   && $rows['zzc148web']['mergedInto'] === null);

/* ========================================================================
 * B. Image history is carried across under the arriving service's own name
 * ======================================================================== */

staxx_image_history_push('zzc148db', 'db', 'sha256:'.str_repeat('a', 64), ['version' => '11.0.0', 'source' => 'test']);
$error = ''; $facts = null;
mkStack($root, 'zzc148web2', "services:\n  web:\n    image: nginx:latest\n");
mkStack($root, 'zzc148db2', "services:\n  db:\n    image: mariadb:11\n");
staxx_scan_stacks_reset();
staxx_image_history_push('zzc148db2', 'db', 'sha256:'.str_repeat('b', 64), ['version' => '11.1.0', 'source' => 'test']);

$merged2 = "services:\n  web:\n    image: nginx:latest\n\n  db_two:\n    image: mariadb:11\n";
$fp2 = staxx_stack_fingerprint('zzc148web2');
$okMerge2 = staxx_merge_stacks('zzc148web2', ['zzc148db2'], $merged2, null,
  ['zzc148db2' => ['db' => 'db_two']], $error, $facts);
ok('a merge carrying an image-history map succeeds', $okMerge2, $error);

$carriedHistory = staxx_image_history('zzc148web2', 'db_two');
ok('the arriving service\'s own image history landed under its final name',
   count($carriedHistory) === 1 && $carriedHistory[0]['digest'] === 'sha256:'.str_repeat('b', 64));
ok('the source stack\'s own history is untouched',
   count(staxx_image_history('zzc148db2', 'db')) === 1);

/* ========================================================================
 * C. Refusals — every one of them must leave every stack exactly as it was
 * ======================================================================== */

// C1 — a companion-file name already in use in the host's folder.
mkStack($root, 'zzc148hostc', "services:\n  web:\n    image: nginx:latest\n", ['shared.txt' => "host's own\n"]);
mkStack($root, 'zzc148inc', "services:\n  db:\n    image: mariadb:11\n", ['shared.txt' => "incoming's own\n"]);
staxx_scan_stacks_reset();
$before = file_get_contents($root.'/zzc148hostc/compose.yaml');
$error = ''; $facts = null;
$okClash = staxx_merge_stacks('zzc148hostc', ['zzc148inc'], "services:\n  web:\n  db:\n", null, [], $error, $facts);
ok('a companion-file name clash is refused', !$okClash);
ok('it names the file', strpos($error, 'shared.txt') !== false);
ok('the host\'s file was never touched', file_get_contents($root.'/zzc148hostc/compose.yaml') === $before);
ok('the host\'s own companion file still reads as it did',
   file_get_contents($root.'/zzc148hostc/shared.txt') === "host's own\n");

// C2 — an incoming stack under review.
mkStack($root, 'zzc148hostr', "services:\n  web:\n    image: nginx:latest\n");
mkStack($root, 'zzc148rev', "services:\n  db:\n    image: mariadb:11\n");
file_put_contents($root.'/zzc148rev/NEEDS-REVIEW.md', 'imported, not yet reviewed');
staxx_scan_stacks_reset();
$error = ''; $facts = null;
$okReview = staxx_merge_stacks('zzc148hostr', ['zzc148rev'], "services:\n  web:\n  db:\n", null, [], $error, $facts);
ok('a stack still under review is refused', !$okReview);
ok('the reason says so', strpos($error, 'review') !== false);

// C3 — an incoming stack carrying a second settings file (an override).
mkStack($root, 'zzc148hosto', "services:\n  web:\n    image: nginx:latest\n");
mkStack($root, 'zzc148ovr', "services:\n  db:\n    image: mariadb:11\n",
  ['compose.override.yaml' => "services:\n  db:\n    restart: always\n"]);
staxx_scan_stacks_reset();
$error = ''; $facts = null;
$okOverride = staxx_merge_stacks('zzc148hosto', ['zzc148ovr'], "services:\n  web:\n  db:\n", null, [], $error, $facts);
ok('a stack with an override is refused', !$okOverride);
ok('the reason names the second settings file', strpos($error, 'settings file') !== false);

// C4 — a stack cannot be folded into itself.
$error = ''; $facts = null;
$okSelf = staxx_merge_stacks('zzc148web', ['zzc148web'], "services:\n  web:\n", null, [], $error, $facts);
ok('a stack cannot be merged into itself', !$okSelf);

// C5 — an unparseable compose file.
mkStack($root, 'zzc148hostb', "services:\n  web:\n    image: nginx:latest\n");
mkStack($root, 'zzc148bad', "not: [valid, compose,\n");
staxx_scan_stacks_reset();
$error = ''; $facts = null;
$okBad = staxx_merge_stacks('zzc148hostb', ['zzc148bad'], "services:\n  web:\n", null, [], $error, $facts);
ok('a stack whose file will not parse is refused', !$okBad);

echo "\n".($fails === 0 ? "All checks passed.\n" : "$fails check(s) FAILED.\n");
exit($fails === 0 ? 0 : 1);
