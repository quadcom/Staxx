<?php
/* PLAN_187 — staxx_icon_serve_path(), the one place include/icon.php decides
 * what is safe to stream for an already-adopted service icon, and its
 * refusals: an unknown stack, "..", a subfolder inside .staxx, a dotfile, a
 * non-picture extension, and a symlink pointing outside .staxx. Also
 * staxx_icon_serve_tree_path(), the wider sibling that serves the merge
 * wizard's file-tree hover preview — any picture in the stack's own tree —
 * and its own refusals: an absolute path, "..", a dot-folder anywhere in the
 * path, a non-picture extension, and a symlink pointing outside the stack.
 * Server-only: it needs staxx_list_stacks() and a real stack directory to
 * test against.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. Needs STORE_ROOT
 * pointed at /tmp/zzicons-store, set in the flash pointer file BEFORE php
 * starts (staxx_cfg() memoises on first read, so changing it from inside
 * this script is already too late — same reasoning tests/server/detail.php
 * gives for STORE_ROOT):
 *
 *     pscp tests/server/icon_serve.php root@<box>:/tmp/
 *     plink … '
 *       CFG=/boot/config/plugins/staxx/staxx.cfg
 *       cp $CFG /tmp/cfg.bak
 *       grep -q "^STORE_ROOT=" $CFG \
 *         && sed -i "s#^STORE_ROOT=.*#STORE_ROOT=\"/tmp/zzicons-store\"#" $CFG \
 *         || echo "STORE_ROOT=\"/tmp/zzicons-store\"" >> $CFG
 *       php /tmp/icon_serve.php; RC=$?
 *       cp /tmp/cfg.bak $CFG
 *       exit $RC
 *     '
 *
 * Never touches the real store: everything here lives under the scratch
 * STORE_ROOT this file sets up and removes on exit. Needs `docker compose`
 * on the PATH — staxx_list_stacks() reads a stack through
 * staxx_compose_meta(), which shells out to it — so this aborts early with
 * a plain message rather than silently testing nothing if it is missing.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */

require_once '/usr/local/emhttp/plugins/staxx/include/Defines.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';

if (staxx_stack_root() !== '/tmp/zzicons-store/stacks') {
  echo "FAIL   the temporary stack root is not in place (got ".staxx_stack_root().") — "
     . "see this file's header for how STORE_ROOT must be set before php starts\n";
  exit(1);
}
if (staxx_compose_cmd() === '') {
  echo "FAIL   docker compose is not on the PATH — staxx_list_stacks() cannot read a stack "
     . "without it, so this suite cannot run here\n";
  exit(1);
}

$fails = 0;
function check(string $what, bool $ok, ?string $detail = null): void {
  global $fails;
  if (!$ok) $fails++;
  printf("%-4s %s\n", $ok ? 'ok' : 'FAIL', $what);
  if (!$ok && $detail !== null) echo '     got: '.$detail."\n";
}

$stackDir = staxx_stack_dir('zzicon-stack');
@mkdir($stackDir.'/'.STAXX_RECORD_DIR, 0755, true);
file_put_contents($stackDir.'/compose.yaml', "services:\n  a:\n    image: busybox\n");
file_put_contents($stackDir.'/'.STAXX_RECORD_DIR.'/a.svg', "<svg xmlns='http://www.w3.org/2000/svg'></svg>");
file_put_contents($stackDir.'/'.STAXX_RECORD_DIR.'/history.json', '{}'); // a non-picture name, refused on extension alone
@mkdir($stackDir.'/'.STAXX_RECORD_DIR.'/sub', 0755, true);
file_put_contents($stackDir.'/'.STAXX_RECORD_DIR.'/sub/b.svg', '<svg></svg>');
file_put_contents($stackDir.'/'.STAXX_RECORD_DIR.'/.c.svg', '<svg></svg>');

// A symlink whose target sits outside .staxx entirely — the case realpath()
// resolution exists to catch, not merely is_link() on the link's own name.
@mkdir($stackDir.'/outside', 0755, true);
file_put_contents($stackDir.'/outside/evil.svg', '<svg></svg>');
@symlink($stackDir.'/outside/evil.svg', $stackDir.'/'.STAXX_RECORD_DIR.'/escape.svg');

$got = staxx_icon_serve_path('zzicon-stack', 'a.svg');
check('a real stack with a real picture serves',
  $got === realpath($stackDir.'/'.STAXX_RECORD_DIR.'/a.svg'), $got);

$got = staxx_icon_serve_path('zzicon-nope', 'a.svg');
check('an unknown stack is refused', $got === '', $got);

$got = staxx_icon_serve_path('zzicon-stack', '../a.svg');
check('".." in the file name is refused', $got === '', $got);

$got = staxx_icon_serve_path('zzicon-stack', 'sub/b.svg');
check('a subfolder inside .staxx is refused', $got === '', $got);

$got = staxx_icon_serve_path('zzicon-stack', '.c.svg');
check('a dotfile is refused', $got === '', $got);

$got = staxx_icon_serve_path('zzicon-stack', 'history.json');
check('a non-picture extension is refused', $got === '', $got);

$got = staxx_icon_serve_path('zzicon-stack', 'escape.svg');
check('a symlink pointing outside .staxx is refused', $got === '', $got);

$got = staxx_icon_serve_path('zzicon-stack', 'nowhere.svg');
check('a name that does not exist at all is refused', $got === '', $got);

/* ---- staxx_icon_serve_tree_path() — the merge wizard's hover preview ---- */

file_put_contents($stackDir.'/loose.png', "\x89PNG\r\n\x1a\nfake-but-good-enough");
@mkdir($stackDir.'/nested', 0755, true);
file_put_contents($stackDir.'/nested/pic.svg', '<svg></svg>');
file_put_contents($stackDir.'/nested/notes.txt', 'not a picture');
@mkdir($stackDir.'/.hidden', 0755, true);
file_put_contents($stackDir.'/.hidden/pic.svg', '<svg></svg>');
@symlink($stackDir.'/outside/evil.svg', $stackDir.'/nested/escape.svg');

$got = staxx_icon_serve_tree_path('zzicon-stack', 'nested/pic.svg');
check('a picture nested anywhere in the stack tree is served',
  $got === realpath($stackDir.'/nested/pic.svg'), $got);

$got = staxx_icon_serve_tree_path('zzicon-stack', '../loose.png');
check('".." in the path is refused', $got === '', $got);

$got = staxx_icon_serve_tree_path('zzicon-stack', '.hidden/pic.svg');
check('a dot-folder anywhere in the path is refused', $got === '', $got);

$got = staxx_icon_serve_tree_path('zzicon-stack', '.staxx/a.svg');
check('.staxx itself is refused here too — only staxx_icon_serve_path() reaches it',
  $got === '', $got);

$got = staxx_icon_serve_tree_path('zzicon-stack', '/etc/passwd');
check('an absolute path is refused', $got === '', $got);

$got = staxx_icon_serve_tree_path('zzicon-stack', 'nested/notes.txt');
check('a non-picture extension is refused', $got === '', $got);

$got = staxx_icon_serve_tree_path('zzicon-stack', 'nested/escape.svg');
check('a symlink pointing outside the stack is refused', $got === '', $got);

$got = staxx_icon_serve_tree_path('zzicon-stack', 'loose.png');
check('a loose picture sitting beside the compose file is served',
  $got === realpath($stackDir.'/loose.png'), $got);

@unlink($stackDir.'/outside/evil.svg');
@rmdir($stackDir.'/outside');
staxx_rmtree('/tmp/zzicons-store', '/tmp/zzicons-store');

echo "\n".($fails === 0 ? "all checks passed\n" : "$fails check(s) FAILED\n");
exit($fails === 0 ? 0 : 1);
