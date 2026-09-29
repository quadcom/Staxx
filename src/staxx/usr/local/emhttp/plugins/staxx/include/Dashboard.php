<?PHP
/* StaXX — the Dashboard tile: its layout, its own icon library and the
 * per-stack facts the tile and the editor both draw from.
 * Copyright 2026, StaXX contributors.
 *
 * PLAN_183. The tile's layout (folders, loose stacks, their positions) is
 * `dashboard.json`, read and normalised here and never trusted as typed —
 * `dash_save` (action.php) is the only writer, and it always runs the saved
 * shape back through staxx_dash_normalise_layout() before it reaches disk.
 *
 * Folder and loose-stack icons can come from four outside picture sets, only
 * ever fetched at the user's own request from the picker (never during a
 * page render): two animated-icon collections, one app-logo collection and
 * one line-icon collection. Downloads are restricted to exactly the four
 * raw.githubusercontent.com prefixes staxx_dash_allowed_prefix() returns —
 * nothing else is ever asked for — and every downloaded file is proved to
 * really be a picture the same way include/Icons.php already proves one
 * (STAXX_ICON_EXTS, the SVG sniff that refuses an "SVG" that is really HTML)
 * before it is kept.
 *
 * Credits, because they are a requirement and not a courtesy (Adrian,
 * 2026-09-25): the animated folder icons are hernandito's
 * (github.com/hernandito/unRAID-Docker-Folder-Animated-Icons---Alternate-Colors,
 * branch `master`) — that repository carries no licence file, and Adrian was
 * given the author's permission to use them privately in 2026-09; that
 * permission is not stated anywhere in the author's own repository as of
 * 2026-09-25, so it is recorded here instead. The second animated set is
 * ground7's unraid-animated-svgs (MIT, (c) 2020 Josiah Hutchinson, `Always
 * Animate/` only — its `Animate on Hover/` icons do not play by themselves).
 * App logos come from homarr-labs/dashboard-icons (Apache-2.0; the logos
 * themselves stay their owners' trademarks). Line icons come from
 * tabler/tabler-icons (MIT, (c) 2020-2026 Pawel Kuna), `icons/outline/` only.
 * A downloaded icon is kept exactly as fetched, so its licence notice
 * travels with it on this box.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/staxx/include/Defines.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/StacksTable.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Icons.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Devices.php';

if (defined('STAXX_DASHBOARD_LOADED')) return;
define('STAXX_DASHBOARD_LOADED', true);

/* --------------------------------------------------------------- layout -- */

// Fixed-width limits section 2.2 states in the editor's own hint text.
define('STAXX_DASH_COLS_MIN', 2);
define('STAXX_DASH_COLS_MAX', 8);
define('STAXX_DASH_ROWS_MIN', 1);
define('STAXX_DASH_ROWS_MAX', 8);
define('STAXX_DASH_NAME_MAX', 60);

// The built-in orange glyphs the "Plain" picker tab offers — Font Awesome 4
// names, which Unraid already loads, so nothing extra ships for this.
define('STAXX_DASH_GLYPHS', ['folder', 'folder-open', 'archive', 'cube', 'cubes',
  'server', 'database', 'film', 'music', 'download', 'shield', 'home']);

/**
 * Where dashboard.json lives: the store's config folder, beside
 * updates.json — '' when no store has been chosen yet. STAXX_DASH_STATE is
 * an env override read once, the same trick staxx_update_state_file() uses,
 * so a server suite can point this at /tmp without ever touching a real box.
 */
function staxx_dash_state_file(): string {
  static $override = null;
  if ($override === null) {
    $env = getenv('STAXX_DASH_STATE');
    $override = ($env !== false && $env !== '') ? $env : '';
  }
  if ($override !== '') return $override;

  $cfg = staxx_config_root();
  return $cfg === '' ? '' : $cfg.'/dashboard.json';
}

/** The defaults every layout is filled against — an empty tile, never a
 *  partial array. */
function staxx_dash_layout_defaults(): array {
  return ['version' => 1, 'cols' => 5, 'rows' => 3, 'items' => []];
}

/**
 * dashboard.json, decoded and always complete. A missing, unreadable or
 * corrupt file reads as an empty tile — never a partial array and never an
 * exception — exactly staxx_update_state()'s own reasoning for the same
 * shape of file.
 */
function staxx_dash_layout_load(): array {
  $defaults = staxx_dash_layout_defaults();
  $file     = staxx_dash_state_file();
  $raw      = $file === '' ? false : @file_get_contents($file);
  $data     = $raw === false ? null : json_decode($raw, true);
  return is_array($data) ? array_merge($defaults, $data) : $defaults;
}

/** A short, safe id for a new folder — not a re-key, just something to hang
 *  a rename on. */
function staxx_dash_new_id(): string {
  try {
    return 'f'.bin2hex(random_bytes(4));
  } catch (\Throwable $e) {
    return 'f'.substr(md5(uniqid('', true)), 0, 8);
  }
}

/** A folder id cleaned to safe characters, or a freshly generated one when
 *  what came in is blank or unsafe. */
function staxx_dash_clean_id(string $id): string {
  $id = preg_replace('/[^A-Za-z0-9_-]/', '', $id) ?? '';
  return ($id !== '' && strlen($id) <= 40) ? $id : staxx_dash_new_id();
}

/** A folder's name, trimmed and capped at STAXX_DASH_NAME_MAX bytes — cut at
 *  the last whole UTF-8 character, the same reasoning staxx_utf8_trim_tail()
 *  exists for elsewhere, since this is stored as JSON and a cut mid-character
 *  would fail json_encode() outright. */
function staxx_dash_clean_name(string $name): string {
  $name = trim($name);
  if ($name === '') return 'New folder';
  if (strlen($name) <= STAXX_DASH_NAME_MAX) return $name;
  return staxx_utf8_trim_tail(substr($name, 0, STAXX_DASH_NAME_MAX));
}

/**
 * A folder's icon field, coerced to one of the three shapes section 7
 * allows — 'auto', 'plain:<glyph>' for a known glyph, or a bare file name
 * that is actually sitting in the dash icon folder. Anything else (a typo,
 * a picture since deleted from under it) falls back to 'auto' rather than
 * refusing the whole save over one stale field.
 *
 * @param string[] $existingIconFiles file names actually on disk right now
 */
function staxx_dash_clean_icon(string $icon, array $existingIconFiles): string {
  $icon = trim($icon);
  if ($icon === '' || $icon === 'auto') return 'auto';
  if (strpos($icon, 'plain:') === 0) {
    $glyph = substr($icon, 6);
    return in_array($glyph, STAXX_DASH_GLYPHS, true) ? $icon : 'auto';
  }
  if (staxx_valid_filename($icon) && in_array($icon, $existingIconFiles, true)) return $icon;
  return 'auto';
}

/** #rrggbb, lower-cased, or '' to mean "no colour set" (the usual grey). */
function staxx_dash_clean_bg($bg): string {
  $bg = is_string($bg) ? trim($bg) : '';
  return preg_match('/^#[0-9a-fA-F]{6}$/', $bg) ? strtolower($bg) : '';
}

/**
 * The whole of section 7's save-time contract: cols/rows in range (refused
 * otherwise — the one refusal an out-of-range grid earns), no two items in
 * one cell and none outside the grid (also refused), a project kept at most
 * once anywhere on the tile (first occurrence in item order wins, silently),
 * and a project dropped outright once it no longer exists (also silent —
 * "nothing warns, because the stack is gone").
 *
 * Pure and DI'd on purpose — $existingProjects and $existingIconFiles are
 * handed in rather than read from disk here, so this can be proved in the
 * server suite with no store and no network at all.
 *
 * @param string[] $existingProjects   every compose project name currently on the box
 * @param string[] $existingIconFiles  every file name currently in the dash icon folder
 * @return array|null the normalised layout, or null with $error set to a full sentence
 */
function staxx_dash_normalise_layout(array $raw, array $existingProjects, array $existingIconFiles, string &$error = ''): ?array {
  $error = '';

  $cols = (int)($raw['cols'] ?? 5);
  $rows = (int)($raw['rows'] ?? 3);
  if ($cols < STAXX_DASH_COLS_MIN || $cols > STAXX_DASH_COLS_MAX) {
    $error = 'The tile can only be '.STAXX_DASH_COLS_MIN.' to '.STAXX_DASH_COLS_MAX.' columns wide. Choose a width in that range and try again.';
    return null;
  }
  if ($rows < STAXX_DASH_ROWS_MIN || $rows > STAXX_DASH_ROWS_MAX) {
    $error = 'The tile can only be '.STAXX_DASH_ROWS_MIN.' to '.STAXX_DASH_ROWS_MAX.' rows tall. Choose a height in that range and try again.';
    return null;
  }

  $itemsIn = is_array($raw['items'] ?? null) ? $raw['items'] : [];

  $seenCells    = [];
  $seenProjects = [];
  $out          = [];

  foreach ($itemsIn as $item) {
    if (!is_array($item)) continue;
    $type = (string)($item['type'] ?? '');
    if ($type !== 'folder' && $type !== 'stack') continue; // an unrecognised item is dropped, not refused

    $c = (int)($item['c'] ?? -1);
    $r = (int)($item['r'] ?? -1);
    if ($c < 0 || $c >= $cols || $r < 0 || $r >= $rows) {
      $error = 'An item sits outside the tile\'s '.$cols.' by '.$rows.' grid. Move it onto the grid, or make the tile bigger, and save again.';
      return null;
    }
    $cellKey = $c.','.$r;
    if (isset($seenCells[$cellKey])) {
      $error = 'Two items are placed in the same square. Move one of them before saving.';
      return null;
    }
    $seenCells[$cellKey] = true;

    if ($type === 'stack') {
      $project = trim((string)($item['stack'] ?? ''));
      if ($project === '' || !in_array($project, $existingProjects, true)) continue; // gone stack, dropped silently
      if (isset($seenProjects[$project])) continue; // already placed earlier in the file — first wins
      $seenProjects[$project] = true;
      $out[] = ['type' => 'stack', 'c' => $c, 'r' => $r, 'stack' => $project];
      continue;
    }

    // A folder.
    $stacksIn  = is_array($item['stacks'] ?? null) ? $item['stacks'] : [];
    $stacksOut = [];
    foreach ($stacksIn as $st) {
      $st = trim((string)$st);
      if ($st === '' || !in_array($st, $existingProjects, true)) continue;
      if (isset($seenProjects[$st])) continue;
      $seenProjects[$st] = true;
      $stacksOut[] = $st;
    }

    $folder = [
      'type'   => 'folder',
      'c'      => $c,
      'r'      => $r,
      'id'     => staxx_dash_clean_id((string)($item['id'] ?? '')),
      'name'   => staxx_dash_clean_name((string)($item['name'] ?? 'New folder')),
      'icon'   => staxx_dash_clean_icon((string)($item['icon'] ?? 'auto'), $existingIconFiles),
      'stacks' => $stacksOut,
    ];
    $bg = staxx_dash_clean_bg($item['bg'] ?? null);
    if ($bg !== '') $folder['bg'] = $bg;

    $out[] = $folder;
  }

  return ['version' => 1, 'cols' => $cols, 'rows' => $rows, 'items' => $out];
}

/** Every compose project name currently on the box — what
 *  staxx_dash_normalise_layout() is allowed to keep a reference to. A stack
 *  with a blank project (never yet matched against docker) falls back to
 *  the same guess the table itself uses elsewhere. */
function staxx_dash_existing_projects(): array {
  $out = [];
  foreach (staxx_list_stacks() as $s) {
    if ($s['review']) continue; // a locked stack is not offered on the tile at all
    $project = $s['project'] !== '' ? $s['project'] : staxx_project_name($s['leaf']);
    if ($project !== '') $out[] = $project;
  }
  return array_values(array_unique($out));
}

/** Every file name actually sitting in the dash icon folder right now, or []
 *  when there is nowhere to look (no store, or the folder does not exist
 *  yet — a fresh install with nothing ever picked). */
function staxx_dash_icon_files_on_disk(): array {
  $dir = staxx_dash_icons_dir();
  if ($dir === '' || !is_dir($dir)) return [];
  $out = [];
  foreach (scandir($dir) ?: [] as $f) {
    if ($f === '.' || $f === '..' || $f === '.cache') continue;
    if (is_file($dir.'/'.$f)) $out[] = $f;
  }
  return $out;
}

/**
 * Which icon files are still spoken for after a save — every bare file name
 * $layout's own folders reference, plus (best effort) whatever
 * config/folders.json's own 'icons' key still points at, since that file's
 * icons live in the same folder and phase 6 (Stacks-page folder icons)
 * shares it. Reading folders.json here is optional and never fails the
 * save: staxx_folders_load() already defends its own file against being
 * missing or corrupt.
 */
function staxx_dash_icons_still_used(array $layout): array {
  $used = [];
  foreach ($layout['items'] ?? [] as $item) {
    $icon = (string)($item['icon'] ?? '');
    if ($icon !== '' && $icon !== 'auto' && strpos($icon, 'plain:') !== 0) $used[$icon] = true;
  }
  if (function_exists('staxx_folders_load')) {
    $folders = staxx_folders_load();
    foreach ((array)($folders['icons'] ?? []) as $file) {
      if (is_string($file) && $file !== '') $used[$file] = true;
    }
  }
  return array_keys($used);
}

/** Delete every icon file that neither the just-saved layout nor
 *  folders.json's own icons still reference. Never touches .cache/, which
 *  holds the picker's own listing cache, not adopted pictures. */
function staxx_dash_icons_prune(array $layout): void {
  $dir = staxx_dash_icons_dir();
  if ($dir === '' || !is_dir($dir)) return;
  $keep = staxx_dash_icons_still_used($layout);
  foreach (staxx_dash_icon_files_on_disk() as $f) {
    if (!in_array($f, $keep, true)) @unlink($dir.'/'.$f);
  }
}

/**
 * The whole of dash_save (action.php): decode, normalise against what
 * actually exists right now, write, then sweep unused icon files. Returns
 * the normalised layout on success, or null with $error set.
 */
function staxx_dash_save_layout(string $rawJson, string &$error = ''): ?array {
  $error = '';
  $decoded = json_decode($rawJson, true);
  if (!is_array($decoded)) { $error = 'That layout could not be read. Reopen the editor and try again.'; return null; }

  $layout = staxx_dash_normalise_layout(
    $decoded, staxx_dash_existing_projects(), staxx_dash_icon_files_on_disk(), $error
  );
  if ($layout === null) return null;

  $encoded = json_encode($layout, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  if ($encoded === false) { $error = 'The layout could not be saved.'; return null; }

  $file = staxx_dash_state_file();
  if ($file === '') { $error = 'No data store has been chosen yet.'; return null; }

  $dir = dirname($file);
  if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { $error = 'The layout could not be saved.'; return null; }
  if (!staxx_atomic_write($file, $encoded, 0644)) { $error = 'The layout could not be saved.'; return null; }

  staxx_dash_icons_prune($layout);

  return $layout;
}

/* ------------------------------------------------------------- icon sets -- */

/**
 * The four picture sets the picker offers, and everything else about them
 * this file, action.php and the About section (a different phase) all read
 * from — one table so a set's credit line can never drift from the address
 * it is actually fetched from. `branch` is fixed for two of them and null
 * for the other two, whose default branch is asked from GitHub once and
 * cached alongside their listing (staxx_dash_default_branch()).
 */
function staxx_dash_icon_sets(): array {
  return [
    'hernandito' => [
      'tab'     => 'Animated (hernandito)',
      'owner'   => 'hernandito',
      'repo'    => 'unRAID-Docker-Folder-Animated-Icons---Alternate-Colors',
      'branch'  => 'master',
      'folder'  => '',
      'repoUrl' => 'https://github.com/hernandito/unRAID-Docker-Folder-Animated-Icons---Alternate-Colors',
      // Private note, never shown: permission was given privately in 2026-09
      // and is not stated in the author's repository as of 2026-09-25.
      'licence' => "No licence file; used with the author's permission.",
      'label'   => "hernandito's animated folder icons",
      'credit'  => 'Animated folder icons by hernandito, used with permission.',
    ],
    'ground7' => [
      'tab'     => 'Animated (ground7)',
      'owner'   => 'ground7',
      'repo'    => 'unraid-animated-svgs',
      'branch'  => null,
      'folder'  => 'Always Animate/',
      'repoUrl' => 'https://github.com/ground7/unraid-animated-svgs',
      'licence' => 'MIT, copyright 2020 Josiah Hutchinson.',
      'label'   => "ground7's animated icons",
      'credit'  => 'Animated icons by ground7 (Josiah Hutchinson), MIT licence.',
    ],
    'logos' => [
      'tab'     => 'App logos',
      'owner'   => 'homarr-labs',
      'repo'    => 'dashboard-icons',
      'branch'  => 'main',
      'folder'  => 'svg/',
      'repoUrl' => 'https://github.com/homarr-labs/dashboard-icons',
      'licence' => 'Apache-2.0. Logos stay their owners\' trademarks.',
      'label'   => 'Dashboard Icons (homarr-labs)',
      'credit'  => 'App logos from Dashboard Icons by homarr-labs, Apache-2.0. Logos are their owners\' trademarks.',
    ],
    'topics' => [
      'tab'     => 'Topics',
      'owner'   => 'tabler',
      'repo'    => 'tabler-icons',
      'branch'  => null,
      'folder'  => 'icons/outline/',
      'repoUrl' => 'https://github.com/tabler/tabler-icons',
      'licence' => 'MIT, copyright 2020-2026 Pawel Kuna.',
      'label'   => 'Tabler Icons',
      'credit'  => 'Topic icons from Tabler Icons by Pawel Kuna, MIT licence.',
    ],
  ];
}

/** {id, tab, credit, repo, licence} for every set — the dash_state 'sets'
 *  field, and (later) the About section's own table. */
function staxx_dash_sets_summary(): array {
  $out = [];
  foreach (staxx_dash_icon_sets() as $id => $set) {
    $out[] = ['id' => $id, 'tab' => $set['tab'], 'credit' => $set['credit'],
              'repo' => $set['repoUrl'], 'licence' => $set['licence']];
  }
  return $out;
}

/**
 * Everything StaXX owes credit to, for the Settings "About" tab: the four
 * icon sets read straight from staxx_dash_icon_sets() (so a licence can never
 * differ from the picker's own credit line), then the fixed entries below.
 * `services` are only asked questions of, so they carry no licence line.
 *
 * @return array{credits:list<array{name:string,url:string,use:string,licence:string}>,
 *               services:list<array{name:string,use:string}>}
 */
function staxx_about_credits(): array {
  $credits = [];
  foreach (staxx_dash_icon_sets() as $set) {
    $credits[] = ['name' => $set['label'], 'url' => $set['repoUrl'],
                  'use' => 'Folder and stack icons offered in the Dashboard tile picker ('.$set['tab'].').',
                  'licence' => $set['licence']];
  }
  $credits[] = ['name' => 'selfh.st icons', 'url' => 'https://selfh.st/icons/',
                'use' => 'App icons for stacks, served by jsDelivr.',
                'licence' => 'CC-BY-4.0.'];
  $credits[] = ['name' => 'Docker Compose', 'url' => 'https://github.com/docker/compose',
                'use' => 'Runs every stack. StaXX can install it when the server has none.',
                'licence' => 'Apache-2.0, Docker Inc.'];
  $credits[] = ['name' => 'Community Applications', 'url' => 'https://ca.unraid.net/',
                'use' => 'The app feed and images the importer reads.',
                'licence' => 'Feed and images belong to Community Applications and their authors.'];
  $credits[] = ['name' => 'Unraid webGUI', 'url' => 'https://github.com/unraid/webgui',
                'use' => 'The web interface StaXX is built into.',
                'licence' => 'GPL-2.0, Lime Technology. StaXX follows the same licence.'];
  $services = [
    ['name' => 'Docker Hub and other image registries', 'use' => 'Asked whether a newer image exists when StaXX checks for updates.'],
    ['name' => 'GitHub API', 'use' => 'Asked for update checks and for the icon lists in the Dashboard tile picker.'],
  ];
  return ['credits' => $credits, 'services' => $services];
}

/**
 * A set's real branch — its own fixed value, or (ground7, tabler) whatever
 * GitHub's repo API reports as the default, asked once and cached for as
 * long as that set's own listing cache is fresh. '' only when the set is
 * unknown or the lookup could not be made at all (no network reaches this
 * function except through here).
 */
function staxx_dash_default_branch(array $set): string {
  if ($set['branch'] !== null) return (string)$set['branch'];

  $data = staxx_hub_json(
    'https://api.github.com/repos/'.$set['owner'].'/'.$set['repo'],
    ['User-Agent: StaXX'], 6, 8
  );
  $branch = is_array($data) ? (string)($data['default_branch'] ?? '') : '';
  return $branch !== '' ? $branch : 'main'; // a sane guess beats refusing the whole listing over one failed lookup
}

/** The exact raw.githubusercontent.com prefix a picked file from this set
 *  must start with — section 10's allowlist, spelled out in code so
 *  staxx_dash_icon_pick() can refuse anything else outright. Space in a
 *  path segment is written %20, matching how GitHub itself encodes it. */
function staxx_dash_allowed_prefix(string $setId, string $branch): string {
  $sets = staxx_dash_icon_sets();
  if (!isset($sets[$setId])) return '';
  $set = $sets[$setId];
  $folder = str_replace(' ', '%20', $set['folder']);
  return 'https://raw.githubusercontent.com/'.$set['owner'].'/'.$set['repo'].'/'.rawurlencode($branch).'/'.$folder;
}

/* ------------------------------------------------------------- listings -- */

/** Where each set's cached listing (and the picker's downloaded pictures)
 *  live — '' when there is no store to keep them in yet. */
function staxx_dash_icons_dir(): string {
  $cfg = staxx_config_root();
  return $cfg === '' ? '' : $cfg.'/icons/dash';
}
function staxx_dash_icons_cache_dir(): string {
  $dir = staxx_dash_icons_dir();
  return $dir === '' ? '' : $dir.'/.cache';
}

/** Tabler's own tags.json at the repository root, if it has one — {file
 *  path (without icons/outline/) => space-separated tags}. [] on anything
 *  going wrong; a set with no keyword file is just searched by file name. */
function staxx_dash_tabler_tags(string $branch): array {
  $url = 'https://raw.githubusercontent.com/tabler/tabler-icons/'.rawurlencode($branch).'/tags.json';
  $data = staxx_hub_json($url, ['User-Agent: StaXX'], 8, 10, 2 * 1024 * 1024);
  if (!is_array($data)) return [];
  $out = [];
  foreach ($data as $name => $tags) {
    if (is_array($tags)) $out[(string)$name] = implode(' ', array_map('strval', $tags));
  }
  return $out;
}

/** Dashboard Icons' own metadata.json at the repository root — {base name
 *  => space-separated aliases}. [] on anything going wrong. */
function staxx_dash_logos_aliases(string $branch): array {
  $url = 'https://raw.githubusercontent.com/homarr-labs/dashboard-icons/'.rawurlencode($branch).'/metadata.json';
  $data = staxx_hub_json($url, ['User-Agent: StaXX'], 8, 10, 4 * 1024 * 1024);
  if (!is_array($data)) return [];
  $out = [];
  foreach ($data as $name => $meta) {
    $aliases = is_array($meta) ? (array)($meta['aliases'] ?? []) : [];
    if ($aliases !== []) $out[(string)$name] = implode(' ', array_map('strval', $aliases));
  }
  return $out;
}

/**
 * One set's listing — every picture file under its own folder, kept in one
 * cache file per set for 24 hours (GitHub's tree API is one request for
 * potentially thousands of files, which is exactly why this is cached and
 * exactly why it is never asked on every picker keystroke).
 *
 * @return array{fetchedAt:int, branch:string, files:string[], keywords:array<string,string>}
 */
function staxx_dash_icon_listing(string $setId, string &$error = ''): array {
  $error = '';
  $empty = ['fetchedAt' => 0, 'branch' => '', 'files' => [], 'keywords' => []];

  $sets = staxx_dash_icon_sets();
  if (!isset($sets[$setId])) { $error = 'Unknown icon set.'; return $empty; }
  $set = $sets[$setId];

  $dir  = staxx_dash_icons_cache_dir();
  $file = $dir === '' ? '' : $dir.'/'.$setId.'.json';

  $cached = null;
  if ($file !== '') {
    $raw = @file_get_contents($file);
    $decoded = $raw === false ? null : json_decode($raw, true);
    if (is_array($decoded)) $cached = $decoded;
  }
  if ($cached !== null && (time() - (int)($cached['fetchedAt'] ?? 0)) < 86400) return $cached;

  $branch = staxx_dash_default_branch($set);

  $url  = 'https://api.github.com/repos/'.$set['owner'].'/'.$set['repo']
        . '/git/trees/'.rawurlencode($branch).'?recursive=1';
  $data = staxx_hub_json($url, ['User-Agent: StaXX'], 10, 15, 8 * 1024 * 1024);

  if (!is_array($data) || !isset($data['tree']) || !is_array($data['tree'])) {
    $error = 'Could not reach GitHub to list this set\'s icons. Check that the server can get online, then open the picker again.';
    return $cached ?? $empty; // a stale cache still beats an empty picker
  }

  $prefix = $set['folder'];
  $files  = [];
  foreach ($data['tree'] as $node) {
    if (!is_array($node) || ($node['type'] ?? '') !== 'blob') continue;
    $path = (string)($node['path'] ?? '');
    if ($prefix !== '' && strncmp($path, $prefix, strlen($prefix)) !== 0) continue;
    $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($ext, ['svg', 'png'], true)) continue;
    if ($setId === 'hernandito' && (strpos($path, '/') === false || strncmp($path, 'deprecated/', 11) === 0)) continue;
    $files[] = $path;
  }

  $keywords = [];
  if ($setId === 'topics') $keywords = staxx_dash_tabler_tags($branch);
  if ($setId === 'logos')  $keywords = staxx_dash_logos_aliases($branch);

  $result = ['fetchedAt' => time(), 'branch' => $branch, 'files' => $files, 'keywords' => $keywords];

  if ($file !== '') {
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { /* cache is a courtesy, not a requirement */ }
    else staxx_atomic_write($file, json_encode($result), 0644);
  }

  return $result;
}

/** hernandito's own top-level folders, minus 'deprecated/' — its collection
 *  menu. [] for every other set (section 4: only hernandito has one). */
function staxx_dash_icon_collections(string $setId): array {
  if ($setId !== 'hernandito') return [];
  $listing = staxx_dash_icon_listing($setId);
  $seen = [];
  foreach ($listing['files'] as $path) {
    $slash = strpos($path, '/');
    if ($slash === false) continue;
    $top = substr($path, 0, $slash);
    if ($top !== '' && $top !== 'deprecated') $seen[$top] = true;
  }
  return array_keys($seen);
}

/** A file's own display name — its bare stem, hyphens and underscores
 *  turned to spaces, so "app-plex" reads as "app plex" in the picker. */
function staxx_dash_display_name(string $path): string {
  $stem = pathinfo($path, PATHINFO_FILENAME);
  return trim(preg_replace('/[-_]+/', ' ', $stem));
}

/**
 * dash_icons (action.php): one set's listing, optionally narrowed to one of
 * hernandito's own collections, in the shape the picker draws straight
 * from — the browser loads every thumbnail itself, straight from GitHub.
 *
 * @return array{ok:bool, set:string, collections:string[], files:array}
 */
function staxx_dash_icons_reply(string $setId, string $collection = ''): array {
  $sets = staxx_dash_icon_sets();
  if (!isset($sets[$setId])) return ['ok' => false, 'error' => 'Unknown icon set.'];

  $error   = '';
  $listing = staxx_dash_icon_listing($setId, $error);
  if ($listing['files'] === [] && $error !== '') return ['ok' => false, 'error' => $error];
  $branch  = $listing['branch'] !== '' ? $listing['branch'] : staxx_dash_default_branch($sets[$setId]);
  $prefix  = staxx_dash_allowed_prefix($setId, $branch);

  $files = [];
  foreach ($listing['files'] as $path) {
    if ($setId === 'hernandito' && $collection !== '' && strncmp($path, $collection.'/', strlen($collection) + 1) !== 0) continue;
    // Each hernandito folder holds an overview collage named after the folder; it is not an icon.
    if ($setId === 'hernandito' && strcasecmp(staxx_dash_display_name($path), staxx_dash_display_name(dirname($path))) === 0) continue;
    $files[] = [
      'file'     => $path,
      'name'     => staxx_dash_display_name($path),
      'thumb'    => $prefix.str_replace(' ', '%20', $path),
      'keywords' => (string)($listing['keywords'][$path] ?? ''),
    ];
  }

  return [
    'ok'          => true,
    'set'         => $setId,
    'collections' => staxx_dash_icon_collections($setId),
    'files'       => $files,
  ];
}

/* --------------------------------------------------------------- pick/upload -- */

/** The local name a downloaded or uploaded dash icon is saved under —
 *  '<prefix>-<safe stem>.<ext>', clashing bytes kept, clashing content
 *  suffixed by a short hash so two different pictures never overwrite each
 *  other under the same name. */
function staxx_dash_icon_local_name(string $prefix, string $stem, string $ext, string $body): string {
  $stem = strtolower((string)preg_replace('/[^a-z0-9._-]+/i', '-', $stem));
  $stem = trim($stem, '-.');
  if ($stem === '') $stem = 'icon';

  $dir  = staxx_dash_icons_dir();
  $base = $prefix.'-'.$stem;
  $name = $base.'.'.$ext;

  if ($dir !== '' && is_file($dir.'/'.$name)) {
    if (md5_file($dir.'/'.$name) === md5($body)) return $name; // identical bytes already there — no new file
    $name = $base.'-'.substr(md5($body), 0, 8).'.'.$ext;
  }
  return $name;
}

/** Write $body under $name inside the dash icon folder — the same
 *  write-beside-and-rename staxx_icon_write() uses, so a fetch or upload
 *  interrupted half way never leaves a broken file in place. */
function staxx_dash_icon_save(string $name, string $body, string &$error = ''): bool {
  $error = '';
  $dir = staxx_dash_icons_dir();
  if ($dir === '') { $error = 'No data store has been chosen yet.'; return false; }
  if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) { $error = 'The icon could not be saved.'; return false; }
  if (!staxx_atomic_write($dir.'/'.$name, $body, 0644)) { $error = 'The icon could not be saved.'; return false; }
  return true;
}

/**
 * dash_icon_pick (action.php): download exactly the one file the picker
 * offered, refusing anything whose address is not built from one of the
 * four allowed prefixes (staxx_dash_allowed_prefix()) or that carries a
 * '..' segment — so this endpoint can never be turned into a fetch of
 * something else. Every body is proved to really be the picture its
 * extension claims (staxx_icon_is_picture(), the same check a service icon
 * gets) before it is kept.
 *
 * @return array{icon:string, url:string}|null null with $error set on any refusal
 */
function staxx_dash_icon_pick(string $setId, string $path, string &$error = ''): ?array {
  $error = '';
  $sets = staxx_dash_icon_sets();
  if (!isset($sets[$setId])) { $error = 'Unknown icon set.'; return null; }
  if ($path === '' || $path[0] === '/' || strpos($path, '..') !== false) {
    $error = 'That is not a picture this picker offers.'; return null;
  }

  $set     = $sets[$setId];
  if ($set['folder'] !== '' && strncmp($path, $set['folder'], strlen($set['folder'])) !== 0) {
    $error = 'That is not a picture this picker offers.'; return null;
  }

  $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
  if (!in_array($ext, ['svg', 'png'], true)) { $error = 'That is not a picture this picker offers.'; return null; }

  $branch = staxx_dash_default_branch($set);
  $prefix = staxx_dash_allowed_prefix($setId, $branch);
  $url    = $prefix.str_replace(' ', '%20', $path);

  if (strncmp($url, $prefix, strlen($prefix)) !== 0) { $error = 'That address is not one of the picker\'s own sources.'; return null; }

  $body = staxx_icon_get($url, 12);
  if ($body === null || !staxx_icon_is_picture($ext, $body)) {
    $error = 'Could not fetch that icon. Try again, or pick a different one.';
    return null;
  }

  $name = staxx_dash_icon_local_name($setId, pathinfo($path, PATHINFO_FILENAME), $ext, $body);
  if (!staxx_dash_icon_save($name, $body, $error)) return null;

  return ['icon' => $name, 'url' => staxx_dash_icon_url($name)];
}

/**
 * dash_icon_upload (action.php): a picture handed over as base64 text in
 * the ordinary urlencoded post the page already uses everywhere else —
 * never multipart, which hangs on this box. Same size cap and format check
 * as a dropped service icon (STAXX_ICON_DROP_MAX_BYTES,
 * staxx_icon_is_picture()); SVG, PNG or WebP only, matching section 4's
 * upload tab.
 */
function staxx_dash_icon_upload(string $filename, string $body, string &$error = ''): ?array {
  $error = '';
  if (strlen($body) > STAXX_ICON_DROP_MAX_BYTES) { $error = 'Too big — icons must be under 512 KB.'; return null; }

  $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
  if (!in_array($ext, ['svg', 'png', 'webp'], true) || !staxx_icon_is_picture($ext, $body)) {
    $error = 'That is not a picture StaXX can use. Upload an SVG, PNG or WebP file.';
    return null;
  }

  $name = staxx_dash_icon_local_name('upload', pathinfo($filename, PATHINFO_FILENAME), $ext, $body);
  if (!staxx_dash_icon_save($name, $body, $error)) return null;

  return ['icon' => $name, 'url' => staxx_dash_icon_url($name)];
}

/* --------------------------------------------------------------- serving -- */

/** The address the browser loads a locally-saved dash icon from —
 *  include/icon.php's own ?dash= parameter, added alongside its existing
 *  ?stack=/?file= form (section 10). Carries the file's own mtime so a
 *  replaced picture never serves stale out of the browser's cache. */
function staxx_dash_icon_url(string $file): string {
  if (!staxx_valid_filename($file)) return '';
  $dir = staxx_dash_icons_dir();
  $path = $dir === '' ? '' : $dir.'/'.$file;
  $mtime = ($path !== '' && is_file($path)) ? (int)@filemtime($path) : 0;
  return '/plugins/'.STAXX_PLUGIN.'/include/icon.php?dash='.rawurlencode($file).'&v='.$mtime;
}

/**
 * The real file include/icon.php would stream for ?dash=<file> — '' to
 * refuse. Refuses a name that is not a bare, safe file (staxx_valid_
 * filename(), which already rules out '/' and '..'), an extension outside
 * the picture set this folder ever holds, and — resolved through
 * realpath(), which is what actually catches a symlink — anything whose
 * real parent directory is not this folder exactly (so .cache/ is never
 * reachable this way).
 */
function staxx_dash_icon_serve_path(string $file): string {
  if (!staxx_valid_filename($file) || $file[0] === '.') return '';
  $ext = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
  if (!in_array($ext, ['svg', 'png', 'webp'], true)) return '';

  $dir = staxx_dash_icons_dir();
  if ($dir === '') return '';

  $real   = @realpath($dir.'/'.$file);
  $dirReal = @realpath($dir);
  if ($real === false || $dirReal === false) return '';
  if (dirname($real) !== $dirReal) return '';
  if (!is_file($real) || is_link($dir.'/'.$file)) return '';

  return $real;
}

/**
 * Every locally-saved icon a layout's own folders reference, resolved to
 * its serving URL — the dash_state 'iconUrls' field, so the tile and the
 * editor never have to build the address themselves.
 */
function staxx_dash_icon_urls_for_layout(array $layout): array {
  $out = [];
  foreach ($layout['items'] ?? [] as $item) {
    $icon = (string)($item['icon'] ?? '');
    if ($icon !== '' && $icon !== 'auto' && strpos($icon, 'plain:') !== 0 && !isset($out[$icon])) {
      $out[$icon] = staxx_dash_icon_url($icon);
    }
  }
  return $out;
}

/* --------------------------------------------------------------- state -- */

/**
 * "up 5 days" — the longest-running container's own age, in Docker's own
 * words, for the statistics window's sub-line (section 6). Read straight
 * off $kids' own 'status' text (already in hand — no extra docker call),
 * which docker itself writes as "Up 5 days", "Up About a minute" and so
 * on; reworded to a plain "up <n> <unit>" so "About a minute" and "a
 * minute" both read the same way. '' when nothing is running.
 *
 * "Longest-running" is worked out by comparing the parsed (count, unit)
 * pairs as seconds — cheap, and exact enough for a sub-line nobody is
 * meant to read to the second.
 */
function staxx_dash_uptime_words(array $kids): string {
  $unitSeconds = ['second' => 1, 'minute' => 60, 'hour' => 3600, 'day' => 86400,
    'week' => 604800, 'month' => 2629800, 'year' => 31557600];

  $best = '';
  $bestSeconds = -1;
  foreach ($kids as $k) {
    if (strtolower((string)($k['state'] ?? '')) !== 'running') continue;
    $status = (string)($k['status'] ?? '');
    if (!preg_match('/^Up\s+(?:About\s+)?(?:an?|(\d+))\s+(second|minute|hour|day|week|month|year)s?/i', $status, $m)) continue;

    $n    = (isset($m[1]) && $m[1] !== '') ? (int)$m[1] : 1;
    $unit = strtolower($m[2]);
    $seconds = $n * ($unitSeconds[$unit] ?? 0);
    if ($seconds > $bestSeconds) {
      $bestSeconds = $seconds;
      $best = 'up '.$n.' '.$unit.($n === 1 ? '' : 's');
    }
  }
  return $best;
}

/**
 * One stack's own entry in dash_state's 'stacks' map, built from exactly the
 * same functions the Stacks page's own row and its cheap refresh use
 * (staxx_list_stacks(), staxx_stack_children(), staxx_stack_containers(),
 * staxx_compose_gpu_vendors(), staxx_icon_resolve()) so the tile and the
 * page can never disagree about a stack's own state.
 */
function staxx_dash_stack_entry(array $s): array {
  $kids = $s['parses'] ? staxx_stack_children($s) : [];

  $running = 0;
  $failed  = false;
  foreach ($kids as $k) {
    $state = strtolower((string)($k['state'] ?? ''));
    if ($state === 'running') { $running++; continue; }
    if (in_array($state, ['restarting', 'dead'], true)) $failed = true;
    if (($k['exists'] ?? false) && ($k['health'] ?? 'none') === 'unhealthy') $failed = true;
  }
  $total = count($kids);
  if ($failed)                             $tileState = 'failed';
  elseif ($total > 0 && $running === $total) $tileState = 'running';
  elseif ($running > 0)                    $tileState = 'partial';
  else                                      $tileState = 'stopped';

  $gpuVendors = ($s['parses'] && $s['file'] !== '')
    ? staxx_compose_gpu_vendors((string)@file_get_contents($s['file'])) : [];

  // Icons belong to services, so the stack's picture is its first service's,
  // found the same way the Stacks row finds it (staxx_stack_icon_tiles()).
  $icon = ['url' => ''];
  foreach ($kids as $k) {
    $icon = staxx_service_icon((string)$k['icon'], $s['dir'], (string)$k['image'],
                               (string)$k['service'], $s['name']);
    if ($icon['url'] !== '') break;
  }

  $webKids = array_values(array_filter($kids, fn($k) => ($k['webui'] ?? '') !== ''));
  $webui   = count($webKids) === 1 ? (string)$webKids[0]['webui'] : '';

  $webuiById = [];
  foreach ($kids as $k) if (($k['id'] ?? '') !== '') $webuiById[$k['id']] = (string)($k['webui'] ?? '');
  // Plain text: the tile and the statistics window print it, they do not
  // splice page markup. Same addresses the row shows, as "label:port, port".
  $parts = [];
  foreach (staxx_merged_addresses(staxx_stack_containers($s), $webuiById) as $a) {
    $label = (string)($a['label'] ?? ($a['ip'] ?? ''));
    if ($label === '') continue;
    $ports = array_map('strval', $a['ports'] ?? []);
    $parts[] = $ports ? $label.':'.implode(', ', $ports) : $label;
  }
  $address = implode(' · ', $parts);

  $services = [];
  foreach ($kids as $k) {
    $services[] = [
      'name'  => (string)($k['service'] ?? ''),
      'image' => (string)(($k['image'] ?? '') !== '' ? $k['image'] : ($k['declared'] ?? '')),
    ];
  }

  return [
    'path'     => $s['name'],
    'name'     => $s['leaf'],
    'folder'   => $s['folder'],
    'icon'     => $icon['url'],
    'state'    => $tileState,
    'running'  => $running,
    'total'    => $total,
    'address'  => $address,
    'webui'    => $webui,
    'services' => $services,
    'gpu'      => $gpuVendors !== [],
    'uptime'   => staxx_dash_uptime_words($kids),
  ];
}

/** Every stack, keyed by compose project name — dash_state's 'stacks' map.
 *  A locked/unreviewed stack is left out entirely, same as the editor's own
 *  left-hand list would have nothing sensible to show for one; a project
 *  clash keeps whichever staxx_list_stacks() lists first, same rule the
 *  layout normaliser itself uses. */
function staxx_dash_stacks_map(): array {
  $out = [];
  foreach (staxx_list_stacks() as $s) {
    if ($s['review']) continue;
    $project = $s['project'] !== '' ? $s['project'] : staxx_project_name($s['leaf']);
    if ($project === '' || isset($out[$project])) continue;
    $out[$project] = staxx_dash_stack_entry($s);
  }
  return $out;
}

/** dash_state (action.php): the whole reply — layout, resolved icon
 *  addresses, every stack's live facts, whether anything can be started at
 *  all, and the picker's own set summary. Polled every few seconds while
 *  the Dashboard is open, so this reads what is already cheap to read
 *  (staxx_list_stacks(), one `docker ps -a`) rather than anything that
 *  itself runs a command per stack. */
function staxx_dash_state(): array {
  $layout = staxx_dash_layout_load();
  return [
    'ok'       => true,
    'layout'   => $layout,
    'iconUrls' => staxx_dash_icon_urls_for_layout($layout),
    'stacks'   => staxx_dash_stacks_map(),
    'canRun'   => staxx_can_run(),
    'sets'     => staxx_dash_sets_summary(),
  ];
}
?>
