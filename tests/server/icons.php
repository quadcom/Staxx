<?php
/* PLAN_187 — each service keeps its own icon, downloaded straight into its
 * stack's own .staxx folder rather than a shared cache, and the
 * once-per-install move that puts stacks written before this shape existed
 * right (staxx_icons_into_stacks()). Server-only: it needs the plugin's
 * icon helpers and a scratch data store.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. Needs STORE_ROOT
 * pointed at /tmp/zzicons-store, set in the flash pointer file BEFORE php
 * starts (staxx_cfg() memoises on first read, so changing it from inside
 * this script is already too late — same reasoning tests/server/detail.php
 * gives for STORE_ROOT). ICON_FETCH is not a flash key (STAXX_FLASH_KEYS in
 * Defines.php), so this script seeds it into the scratch store's own
 * config/staxx.cfg itself, before Defines.php's first require:
 *
 *     pscp tests/server/icons.php root@<box>:/tmp/
 *     plink … '
 *       CFG=/boot/config/plugins/staxx/staxx.cfg
 *       cp $CFG /tmp/cfg.bak
 *       grep -q "^STORE_ROOT=" $CFG \
 *         && sed -i "s#^STORE_ROOT=.*#STORE_ROOT=\"/tmp/zzicons-store\"#" $CFG \
 *         || echo "STORE_ROOT=\"/tmp/zzicons-store\"" >> $CFG
 *       php /tmp/icons.php; RC=$?
 *       cp /tmp/cfg.bak $CFG
 *       exit $RC
 *     '
 *
 * Never touches the network and never touches the real store: everything
 * here lives under the scratch STORE_ROOT this file sets up and removes on
 * exit. ICON_FETCH is forced off for the WHOLE suite, seeded once before
 * Defines.php's first require and never touched again — staxx_cfg()
 * memoises on first read, so a mid-script rewrite of the config file is
 * silently ignored, the same trap tests/server/detail.php's own header
 * warns about for STORE_ROOT. staxx_icon_fetch_and_write() checks whether
 * there is even anything to try, and whether there is somewhere to put it,
 * before it ever asks whether fetching is allowed at all (see its own
 * comment), so every refusal case below is reachable with fetching off the
 * whole time and no network is ever reached, in any case. The one case
 * that DOES need real bytes — a local picture already sitting on disk,
 * migrated by staxx_icons_into_stacks() — is a file copy, never a fetch, so
 * it needs no network either.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */

@mkdir('/tmp/zzicons-store/config', 0755, true);
file_put_contents('/tmp/zzicons-store/config/staxx.cfg', "ICON_FETCH=\"false\"\n");

require_once '/usr/local/emhttp/plugins/staxx/include/Defines.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/StacksTable.php';

if (staxx_stack_root() !== '/tmp/zzicons-store/stacks') {
  echo "FAIL   the temporary stack root is not in place (got ".staxx_stack_root().") — "
     . "see this file's header for how STORE_ROOT must be set before php starts\n";
  exit(1);
}
if ((staxx_cfg()['ICON_FETCH'] ?? '') !== 'false') {
  echo "FAIL   ICON_FETCH is not forced off — this run would be free to touch the network\n";
  exit(1);
}
if (staxx_compose_cmd() === '') {
  echo "FAIL   docker compose is not on the PATH — this suite needs it to read a stack\n";
  exit(1);
}

$fails = 0;
function check(string $what, bool $ok, $detail = null): void {
  global $fails;
  if (!$ok) $fails++;
  printf("%-4s %s\n", $ok ? 'ok' : 'FAIL', $what);
  if (!$ok && $detail !== null) {
    echo '     got: '.(is_string($detail) ? $detail : json_encode($detail))."\n";
  }
}

$pngBytes = "\x89PNG\r\n\x1a\nfake-but-good-enough-for-this-test";
$scratch  = '/tmp/zzicons-store/stacks';

/* ---- staxx_icon_fetch_and_write() refusals, no network ever reached ---- */

$stack = staxx_stack_dir('zzicon-fw');
@mkdir($stack, 0755, true);

// A source is given in every case that is not itself testing "nothing to
// fetch" — otherwise that check, not fetching being off, would be first to
// refuse, and this suite would never actually prove the setting's own gate.
$error = '';
$result = staxx_icon_fetch_and_write($stack, 'a', 'https://example.com/x.png', '', 'zz-fw-1', $error);
check('switched off entirely is refused, with a source and a folder to write into',
  $result === '' && $error === 'Icon lookups are switched off.', $error);

$error = '';
$result = staxx_icon_fetch_and_write($stack, 'a', '', '', 'zz-fw-2', $error);
check('nothing to fetch (no remote, no collection match) is refused first, before the setting is even asked',
  $result === '' && $error === 'Nothing to fetch.', $error);

$error = '';
$result = staxx_icon_fetch_and_write($scratch.'/does-not-exist', 'a', 'https://example.com/x.png', '', 'zz-fw-3', $error);
check('an unwritable (non-existent) stack folder is refused, ahead of the setting too',
  $result === '' && $error === 'The stack folder cannot be written to.', $error);

/* ---- PLAN_105 — a stack has no icon of its own ---- */
// staxx_service_icon() (StacksTable.php) takes no stack-level icon at all,
// so there is no route left for a stack's own `icon:` field to reach a
// service — proven directly against the chain rather than through a
// rendered page.

$none = staxx_service_icon('', $stack, 'zzqqxx/zzqqxx-nothing', 'zzqqxx-nothing', 'zzqqxx-nothing');
check('with no service icon, an image matching nothing resolves to nothing',
  $none['fa'] === '' && $none['url'] === '' && $none['ref'] === '');

$ownFa = staxx_service_icon('fa-server', $stack, 'zzstaxxtest/neverexisted-plan105', 'app', 'stack');
check('a service that states its own icon still wins, regardless of anything at stack level',
  $ownFa['fa'] === 'fa-server');

/* ---- an already-adopted icon resolves through the serving page's own URL --- */

@mkdir($stack.'/'.STAXX_RECORD_DIR, 0755, true); // file_put_contents() never creates a missing parent
file_put_contents($stack.'/'.STAXX_RECORD_DIR.'/a.svg', "<svg xmlns='http://www.w3.org/2000/svg'></svg>");
$resolved = staxx_icon_resolve('./.staxx/a.svg', $stack);
check('a service icon already in .staxx resolves to the serving page, with the file\'s own mtime',
  strpos($resolved['url'], '/plugins/staxx/include/icon.php?stack=zzicon-fw&file=a.svg&v=') === 0, $resolved);

$resolvedMissing = staxx_icon_resolve('./.staxx/nope.svg', $stack);
check('a stated .staxx file that is not actually there resolves to nothing, not a dead link',
  $resolvedMissing['url'] === '' && $resolvedMissing['fa'] === '', $resolvedMissing);

/* ---- PLAN_149 phase 3 — a picture dropped straight in from the desktop ---- */
// Unchanged by this plan: staxx_icon_adopt_drop() never touched a shared
// cache and never fetches anything — the bytes are already in hand.

$dropDir = staxx_stack_dir('zzicon-drop');
@mkdir($dropDir, 0755, true);
$dropAbs = $dropDir.'/'.STAXX_RECORD_DIR.'/mylogo.png';

$error = '';
$file = staxx_icon_adopt_drop($dropDir, 'My Logo!!.PNG', $pngBytes, $error);
check('a genuine picture dropped in is accepted, and named from its own filename, cleaned up',
  $file === './'.STAXX_RECORD_DIR.'/mylogo.png' && is_file($dropAbs) && $error === '');

$mtimeDropFirst = @filemtime($dropAbs);
$error = '';
$again = staxx_icon_adopt_drop($dropDir, 'My Logo!!.PNG', $pngBytes, $error);
check('dropping the same picture a second time lands one file, not two',
  $again === $file && $error === '' && @filemtime($dropAbs) === $mtimeDropFirst);

$error = '';
check('a file whose contents are not a picture is refused, whatever its name claims',
  staxx_icon_adopt_drop($dropDir, 'fake.png', 'not actually a picture at all', $error) === ''
  && $error === 'Not a picture');

/* ---- staxx_icons_into_stacks() — putting an existing stack right ---- */

$oldStack = staxx_stack_dir('zzicon-old');
@mkdir($oldStack, 0755, true);
file_put_contents($oldStack.'/logo.png', $pngBytes); // sits loose beside the compose file, the old shape
file_put_contents($oldStack.'/compose.yaml',
  "services:\n  web:\n    image: busybox\n    x-unraid:\n      icon: ./logo.png\n");

// A leftover from the OLD shared icon folder — never read any more, but a
// real run has to clear it, and its old index, out entirely once every
// stack above is done.
@mkdir('/tmp/zzicons-store/config/icons', 0755, true);
file_put_contents('/tmp/zzicons-store/config/icons/leftover.png', $pngBytes);
file_put_contents('/tmp/zzicons-store/config/icons/_index.json', '{}'); // the OLD index location, now removed too

$dry = staxx_icons_into_stacks(true);
check('a dry run reports the move it would make',
  count(array_filter($dry['moved'], fn($m) => $m['stack'] === 'zzicon-old' && $m['service'] === 'web')) === 1);
check('a dry run reports every file in the old shared folder, including its old index',
  in_array('leftover.png', $dry['removed'], true) && in_array('_index.json', $dry['removed'], true));
check('a dry run writes nothing at all',
  !is_file($oldStack.'/'.STAXX_RECORD_DIR.'/web.png')
  && is_file('/tmp/zzicons-store/config/icons/leftover.png')
  && is_file('/tmp/zzicons-store/config/icons/_index.json')
  && strpos((string)file_get_contents($oldStack.'/compose.yaml'), 'icon: ./logo.png') !== false);

$real = staxx_icons_into_stacks(false);
check('a real run copies the picture into the stack\'s own .staxx folder, named for the service',
  is_file($oldStack.'/'.STAXX_RECORD_DIR.'/web.png')
  && md5_file($oldStack.'/'.STAXX_RECORD_DIR.'/web.png') === md5($pngBytes));
check('and rewrites the icon: line to point at it',
  strpos((string)file_get_contents($oldStack.'/compose.yaml'), 'icon: ./'.STAXX_RECORD_DIR.'/web.png') !== false);
check('and removes the old shared folder entirely, including its old index',
  !is_dir('/tmp/zzicons-store/config/icons'));

$again2 = staxx_icons_into_stacks(false);
check('running it again finds nothing left to move',
  count($again2['moved']) === 0, $again2);

echo "\n".($fails === 0 ? "all checks passed\n" : "$fails check(s) FAILED\n");
exit($fails === 0 ? 0 : 1);
