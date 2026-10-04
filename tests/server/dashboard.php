<?php
/* PLAN_183 — the Dashboard tile's storage: dashboard.json's own normalising
 * rules (staxx_dash_normalise_layout(), pure, tested with no store and no
 * network at all) plus the parts that do need a store — writing the file,
 * pruning icons nothing references any more, and the picker's own address
 * allowlist. Server-only: it needs the plugin's own helpers and, for the
 * save/prune cases, a scratch data store with docker compose reachable.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. STORE_ROOT must
 * be pointed at /tmp/zzdash-store in the flash pointer file BEFORE php
 * starts (staxx_cfg() memoises on first read, same trap tests/server/
 * icons.php and detail.php both warn about):
 *
 *     pscp tests/server/run-with-store.sh tests/server/dashboard.php root@<box>:/tmp/
 *     plink … 'bash /tmp/run-with-store.sh /tmp/zzdash-store /tmp/dashboard.php'
 *
 * Never touches the network: every "pick" case here is refused before a
 * fetch would ever happen (a bad prefix, a '..' segment), and the "really
 * HTML" case is proved directly against staxx_icon_is_picture() with no
 * download at all. Removes its own scratch stack and dashboard.json on
 * exit; never touches the real store.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */

require_once '/usr/local/emhttp/plugins/staxx/include/Defines.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/StacksTable.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Icons.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Dashboard.php';

if (staxx_stack_root() !== '/tmp/zzdash-store/stacks') {
  echo "FAIL   the temporary stack root is not in place (got ".staxx_stack_root().") — "
     . "see this file's header for how STORE_ROOT must be set before php starts\n";
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

/* ------------------------------------------------- pure normalising rules -- */

$error = '';
$ok = staxx_dash_normalise_layout(
  ['cols' => 5, 'rows' => 3, 'items' => [
    ['type' => 'stack', 'c' => 0, 'r' => 0, 'stack' => 'plex'],
    ['type' => 'folder', 'c' => 1, 'r' => 0, 'name' => 'Media', 'stacks' => ['sonarr']],
  ]],
  ['plex', 'sonarr'], [], $error
);
check('a plain layout normalises with no error', $ok !== null && $error === '', $error);
check('the stack item keeps its project', ($ok['items'][0]['stack'] ?? '') === 'plex', $ok);
check('the folder keeps its stack', ($ok['items'][1]['stacks'][0] ?? '') === 'sonarr', $ok);
check('a new folder gets an id', ($ok['items'][1]['id'] ?? '') !== '', $ok);
check('a new folder defaults to auto', ($ok['items'][1]['icon'] ?? '') === 'auto', $ok);

$error = '';
$refused = staxx_dash_normalise_layout(
  ['cols' => 5, 'rows' => 3, 'items' => [
    ['type' => 'stack', 'c' => 2, 'r' => 1, 'stack' => 'plex'],
    ['type' => 'stack', 'c' => 2, 'r' => 1, 'stack' => 'sonarr'],
  ]],
  ['plex', 'sonarr'], [], $error
);
check('two items in one cell is refused', $refused === null && $error !== '', $error);

$error = '';
$refused = staxx_dash_normalise_layout(
  ['cols' => 5, 'rows' => 3, 'items' => [
    ['type' => 'stack', 'c' => 5, 'r' => 0, 'stack' => 'plex'],
  ]],
  ['plex'], [], $error
);
check('an item outside the grid is refused', $refused === null && $error !== '', $error);

$error = '';
$refused = staxx_dash_normalise_layout(['cols' => 1, 'rows' => 3, 'items' => []], [], [], $error);
check('too few columns is refused', $refused === null && $error !== '', $error);

$error = '';
$refused = staxx_dash_normalise_layout(['cols' => 5, 'rows' => 9, 'items' => []], [], [], $error);
check('too many rows is refused', $refused === null && $error !== '', $error);

$error = '';
$dup = staxx_dash_normalise_layout(
  ['cols' => 5, 'rows' => 3, 'items' => [
    ['type' => 'stack', 'c' => 0, 'r' => 0, 'stack' => 'plex'],
    ['type' => 'folder', 'c' => 1, 'r' => 0, 'name' => 'Media', 'stacks' => ['plex']],
  ]],
  ['plex'], [], $error
);
check('a project appearing twice keeps the first placement',
  $dup !== null
  && ($dup['items'][0]['stack'] ?? '') === 'plex'
  && ($dup['items'][1]['stacks'] ?? []) === [],
  $dup);

$error = '';
$gone = staxx_dash_normalise_layout(
  ['cols' => 5, 'rows' => 3, 'items' => [
    ['type' => 'stack', 'c' => 0, 'r' => 0, 'stack' => 'plex'],
    ['type' => 'stack', 'c' => 1, 'r' => 0, 'stack' => 'removed-stack'],
  ]],
  ['plex'], [], $error
);
check('a stack that no longer exists is dropped, not refused',
  $gone !== null && count($gone['items']) === 1 && ($gone['items'][0]['stack'] ?? '') === 'plex', $gone);

$error = '';
$icon = staxx_dash_normalise_layout(
  ['cols' => 5, 'rows' => 3, 'items' => [
    ['type' => 'folder', 'c' => 0, 'r' => 0, 'name' => 'X', 'icon' => 'plain:cube', 'stacks' => []],
    ['type' => 'folder', 'c' => 1, 'r' => 0, 'name' => 'Y', 'icon' => 'plain:not-a-real-glyph', 'stacks' => []],
    ['type' => 'folder', 'c' => 2, 'r' => 0, 'name' => 'Z', 'icon' => 'some-existing-file.svg', 'stacks' => []],
    ['type' => 'folder', 'c' => 3, 'r' => 0, 'name' => 'W', 'icon' => 'not-on-disk.svg', 'stacks' => []],
  ]],
  [], ['some-existing-file.svg'], $error
);
check('a known plain glyph is kept', ($icon['items'][0]['icon'] ?? '') === 'plain:cube', $icon);
check('an unknown plain glyph falls back to auto', ($icon['items'][1]['icon'] ?? '') === 'auto', $icon);
check('a file actually on disk is kept', ($icon['items'][2]['icon'] ?? '') === 'some-existing-file.svg', $icon);
check('a file not on disk falls back to auto', ($icon['items'][3]['icon'] ?? '') === 'auto', $icon);

/* ------------------------------------------------------------- pick guard -- */

$error = '';
$picked = staxx_dash_icon_pick('logos', '../../../etc/passwd', $error);
check('a pick path carrying .. is refused with no fetch attempted', $picked === null && $error !== '', $error);

$error = '';
$picked = staxx_dash_icon_pick('logos', 'not-svg/plex.png', $error);
check('a pick path outside the set\'s own folder is refused', $picked === null && $error !== '', $error);

$error = '';
$picked = staxx_dash_icon_pick('nosuchset', 'svg/plex.svg', $error);
check('an unknown set is refused', $picked === null && $error !== '', $error);

/* ------------------------------------------ picker listing from a fresh cache -- */

// Seed a fresh (under 24 h) cache file so the reply is built without the network;
// any real cache for the set is put back afterwards.
$cacheDir  = staxx_dash_icons_cache_dir();
$cacheFile = $cacheDir.'/logos.json';
if ($cacheDir === '') {
  echo "SKIP   no data store, so the cached-listing case cannot seed a cache file\n";
} else {
  @mkdir($cacheDir, 0755, true);
  $realCache = @file_get_contents($cacheFile);
  file_put_contents($cacheFile, json_encode([
    'fetchedAt' => time(), 'branch' => 'main',
    'files'     => ['svg/zz-one.svg', 'svg/zz-two.svg', 'png/zz-three.png'],
    'keywords'  => ['zz-one' => 'first'],
  ]));
  $reply = staxx_dash_icons_reply('logos');
  check('the picker reply from a fresh cache is ok', ($reply['ok'] ?? false) === true, $reply);
  check('the picker reply lists the cached files', count($reply['files'] ?? []) === 3
    && ($reply['files'][0]['file'] ?? '') === 'svg/zz-one.svg'
    && ($reply['files'][0]['keywords'] ?? '') === 'first', $reply);
  if ($realCache === false) @unlink($cacheFile); else file_put_contents($cacheFile, $realCache);

  // selfhst: the real listing is built from index.json; here the cache is seeded.
  $sFile = $cacheDir.'/selfhst.json';
  $realS = @file_get_contents($sFile);
  file_put_contents($sFile, json_encode([
    'fetchedAt' => time(), 'branch' => 'main',
    'files'     => ['svg/plex.svg', 'png/foo.png'],
    'keywords'  => ['plex' => 'Plex Media'],
  ]));
  $reply = staxx_dash_icons_reply('selfhst');
  check('the selfhst reply gives plex its svg address on the selfhst repository, with its keywords',
    ($reply['files'][0]['thumb'] ?? '') === 'https://raw.githubusercontent.com/selfhst/icons/main/svg/plex.svg'
    && ($reply['files'][0]['keywords'] ?? '') === 'Plex Media'
    && ($reply['files'][1]['thumb'] ?? '') === 'https://raw.githubusercontent.com/selfhst/icons/main/png/foo.png', $reply);
  if ($realS === false) @unlink($sFile); else file_put_contents($sFile, $realS);

  // hernandito: the folder-named collage is not listed, the icons are.
  $hFile = $cacheDir.'/hernandito.json';
  $realH = @file_get_contents($hFile);
  file_put_contents($hFile, json_encode([
    'fetchedAt' => time(), 'branch' => 'main',
    'files'     => ['Blue-Collection/Blue Collection.png', 'Blue-Collection/app-one.png', 'Blue-Collection/app-two.png'],
    'keywords'  => [],
  ]));
  $reply = staxx_dash_icons_reply('hernandito');
  $listed = array_column($reply['files'] ?? [], 'file');
  check('the hernandito reply drops the folder-named collage and keeps the icons',
    $listed === ['Blue-Collection/app-one.png', 'Blue-Collection/app-two.png'], $reply);
  if ($realH === false) @unlink($hFile); else file_put_contents($hFile, $realH);
}

/* -------------------------------------------------- "SVG" that is HTML -- */

$html = "<!DOCTYPE html>\n<html><body>not a picture</body></html>";
check('an "SVG" that is really HTML is not accepted as a picture',
  staxx_icon_is_picture('svg', $html) === false);

$error = '';
$upload = staxx_dash_icon_upload('icon.svg', $html, $error);
check('dash_icon_upload refuses an HTML body wearing an .svg name',
  $upload === null && $error !== '', $error);

/* --------------------------------------------------- save/load round trip -- */

if (staxx_compose_cmd() === '') {
  echo "SKIP   docker compose is not on the PATH — the round-trip and icon-prune "
     . "cases below need it to create a real scratch stack\n";
} else {
  $yaml = "services:\n  web:\n    image: nginx:alpine\n";
  $saveError = '';
  $saved = staxx_save_stack('zzdash1', $yaml, $saveError);
  check('the scratch stack for this suite saved', $saved, $saveError);

  $project = null;
  foreach (staxx_list_stacks() as $s) {
    if ($s['name'] === 'zzdash1') { $project = $s['project'] !== '' ? $s['project'] : staxx_project_name($s['leaf']); break; }
  }
  check('the scratch stack has a compose project name', is_string($project) && $project !== '', $project);

  if ($project !== null) {
    $iconsDir = staxx_dash_icons_dir();
    @mkdir($iconsDir, 0755, true);
    file_put_contents($iconsDir.'/used.svg', "<svg></svg>");
    file_put_contents($iconsDir.'/unused.svg', "<svg></svg>");

    $rawLayout = json_encode([
      'cols' => 5, 'rows' => 3,
      'items' => [
        ['type' => 'stack', 'c' => 0, 'r' => 0, 'stack' => $project],
        ['type' => 'folder', 'c' => 1, 'r' => 0, 'name' => 'Kept', 'icon' => 'used.svg', 'stacks' => []],
      ],
    ]);

    $saveErr = '';
    $written = staxx_dash_save_layout($rawLayout, $saveErr);
    check('dash_save writes a normalised layout', $written !== null, $saveErr);

    $reloaded = staxx_dash_layout_load();
    check('the layout round-trips through the state file',
      $reloaded['cols'] === 5 && count($reloaded['items']) === 2, $reloaded);

    check('a used icon file survives the save', is_file($iconsDir.'/used.svg'));
    check('an unused icon file is deleted on save', !is_file($iconsDir.'/unused.svg'));

    @unlink(staxx_dash_state_file());
  }
}

/* ------------------------------------------- icons that follow the theme (PLAN_225) -- */

// Unraid's theme is read from the ini named by STAXX_DYNAMIX_CFG on every call, so
// one /tmp file is rewritten between cases. No network: only files this block writes.
$themeCfg = '/tmp/zzdash-dynamix.cfg';
putenv('STAXX_DYNAMIX_CFG='.$themeCfg);
$setTheme = function (string $theme) use ($themeCfg): void {
  file_put_contents($themeCfg, "[display]\ntheme=\"$theme\"\n");
};

foreach (['black' => true, 'gray' => true, 'white' => false, 'azure' => false] as $theme => $dark) {
  $setTheme($theme);
  check("the $theme theme is ".($dark ? 'dark' : 'light'), staxx_unraid_theme_dark() === $dark);
}
@unlink($themeCfg);
check('a missing dynamix.cfg is light', staxx_unraid_theme_dark() === false);

$iconsDir = staxx_dash_icons_dir();
if ($iconsDir === '') {
  echo "SKIP   no data store, so the themed-icon cases have nowhere to put their files\n";
} else {
  @mkdir($iconsDir, 0755, true);
  $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"></svg>';
  $made = ['selfhst-x.svg', 'selfhst-x-light.svg', 'selfhst-x-dark.svg', 'logos-y.svg', 'logos-z.svg', 'logos-z-light.svg'];
  foreach ($made as $f) file_put_contents($iconsDir.'/'.$f, $svg);

  $setTheme('black');
  check('a dark theme picks the -light sibling', staxx_dash_icon_themed('selfhst-x.svg') === 'selfhst-x-light.svg',
    staxx_dash_icon_themed('selfhst-x.svg'));
  check('both address builders name the -light sibling on a dark theme',
    strpos(staxx_dash_icon_url('selfhst-x.svg'), 'dash=selfhst-x-light.svg') !== false
    && strpos(staxx_folder_pic_url('selfhst-x.svg'), 'dash=selfhst-x-light.svg') !== false,
    [staxx_dash_icon_url('selfhst-x.svg'), staxx_folder_pic_url('selfhst-x.svg')]);
  check('a file with no siblings stays itself on a dark theme', staxx_dash_icon_themed('logos-y.svg') === 'logos-y.svg');

  $setTheme('white');
  check('a light theme picks the -dark sibling', staxx_dash_icon_themed('selfhst-x.svg') === 'selfhst-x-dark.svg',
    staxx_dash_icon_themed('selfhst-x.svg'));
  check('both address builders name the -dark sibling on a light theme',
    strpos(staxx_dash_icon_url('selfhst-x.svg'), 'dash=selfhst-x-dark.svg') !== false
    && strpos(staxx_folder_pic_url('selfhst-x.svg'), 'dash=selfhst-x-dark.svg') !== false,
    [staxx_dash_icon_url('selfhst-x.svg'), staxx_folder_pic_url('selfhst-x.svg')]);
  check('a file with no siblings stays itself on a light theme', staxx_dash_icon_themed('logos-y.svg') === 'logos-y.svg');

  // Prune deletes every unreferenced file in the scratch dash folder, so the files
  // made above are all it may remove; it never touches .cache, where the marker lives.
  staxx_dash_icons_prune(['items' => [
    ['type' => 'folder', 'c' => 0, 'r' => 0, 'name' => 'T', 'icon' => 'selfhst-x.svg', 'stacks' => []],
  ]]);
  check('prune keeps a used icon and both its siblings',
    is_file($iconsDir.'/selfhst-x.svg') && is_file($iconsDir.'/selfhst-x-light.svg') && is_file($iconsDir.'/selfhst-x-dark.svg'));
  check('prune removes an unused icon together with its sibling',
    !is_file($iconsDir.'/logos-z.svg') && !is_file($iconsDir.'/logos-z-light.svg'));

  // TEMPORARY — goes with the backfill itself, no earlier than 00.05.02 (PLAN_226).
  $markerDir = staxx_dash_icons_cache_dir();
  @mkdir($markerDir, 0755, true);
  $marker = $markerDir.'/variants-backfilled';
  $hadMarker = is_file($marker);
  file_put_contents($marker, '');
  $before = scandir($iconsDir);
  staxx_dash_icon_variants_backfill_auto();
  check('the variants backfill returns at once when its marker exists, changing no icon file',
    scandir($iconsDir) === $before, scandir($iconsDir));
  if (!$hadMarker) @unlink($marker);

  foreach ($made as $f) @unlink($iconsDir.'/'.$f);
}
@unlink($themeCfg);
putenv('STAXX_DYNAMIX_CFG');

echo $fails === 0 ? "\nAll dashboard checks passed.\n" : "\n$fails check(s) failed.\n";
exit($fails === 0 ? 0 : 1);
