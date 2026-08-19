<?PHP
/* StaXX — reading what could be imported.
 * Copyright 2026, StaXX contributors.
 *
 * WHAT THIS FILE IS FOR
 *
 * Three things on the server could become a StaXX stack: an Unraid template
 * (an XML file describing one container), a Compose Manager project (a
 * folder on the flash drive holding a compose file Compose Manager already
 * runs), and a container that belongs to neither — started by hand, or by
 * something else entirely.
 *
 * This file only READS. It never writes a stack, never touches a container,
 * and never runs compose. The one thing it previews — the compose file StaXX
 * would write for a template — is generated in the BROWSER, by the same
 * converter the Apps dialog already uses; this file hands it the decoded
 * template and nothing more.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
if (defined('STAXX_IMPORT_TEMPLATES_DIR')) return;
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Icons.php';

/** Where Unraid keeps the templates behind every Docker container it made. */
const STAXX_IMPORT_TEMPLATES_DIR = '/boot/config/plugins/dockerMan/templates-user';

/** Where Compose Manager keeps one folder per project it runs. */
const STAXX_IMPORT_PROJECTS_DIR = '/boot/config/plugins/compose.manager/projects';

/** Every override filename Compose Manager is known to write, base and
 *  extension both — StaXX passes compose exactly one file and never merges
 *  one of these in, so a project that has one would run differently once
 *  imported. */
const STAXX_IMPORT_OVERRIDE_NAMES = [
  'docker-compose.override.yml', 'docker-compose.override.yaml',
  'compose.override.yml', 'compose.override.yaml',
];

/* ------------------------------------------------------------------ names -- */

/**
 * Turn an arbitrary display name into something staxx_valid_name() accepts —
 * a space, an ampersand, anything outside its safe set becomes a dash, and a
 * leading run of punctuation (which cannot start a name) is dropped. Returns
 * the fallback 'stack' only if nothing usable survives that, which in
 * practice never happens for a real container or project name.
 */
function staxx_import_safe_name(string $raw): string {
  $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($raw));
  $name = ltrim((string)$name, '.-_');
  $name = str_replace('..', '-', $name); // staxx_valid_name() also refuses ".."
  $name = substr($name, 0, 63);
  return staxx_valid_name($name) ? $name : 'stack';
}

/** Top-level stack folder names that already exist, so an import candidate
 *  can say whether the name it would use is taken. */
function staxx_import_taken_names(): array {
  static $names = null;
  if ($names !== null) return $names;

  $names = [];
  foreach (staxx_scan_stacks()['stacks'] as $s) {
    if ($s['folder'] === '') $names[] = $s['leaf'];
  }
  return $names;
}

/* ------------------------------------------------------------ FolderView3 -- */

/** Where the FolderView3 plugin keeps its own folder-to-container mapping,
 *  if that plugin is installed at all. */
const STAXX_IMPORT_FOLDERVIEW3_FILE = '/boot/config/plugins/folder.view3/docker.json';

/**
 * FolderView3's own folders, reduced to what an import needs: which
 * container belongs to which folder, and which folders had to be left out.
 *
 * The plugin not being installed is a normal, silent case — the file is
 * simply absent and this returns empty, the same as a mapping that matched
 * nothing. NEVER WRITTEN TO: this reads somebody else's settings, it does
 * not take them over.
 *
 * A folder built on a REGEX decides membership by a pattern rather than a
 * list, and evaluating that pattern here would mean re-implementing
 * FolderView3's own matching rules with no way to be sure they still agree —
 * mis-filing a container because a regex was read slightly wrong is worse
 * than leaving it at the top level. Those folders are skipped and named in
 * 'skipped' instead, so the caller can say so rather than staying quiet
 * about it.
 *
 * $path is a parameter, not always the constant above, purely so a test can
 * point this at a fixture without ever touching the real settings file.
 *
 * @return array{containers:array<string,string>, skipped:string[]}
 */
function staxx_import_folderview3(string $path = STAXX_IMPORT_FOLDERVIEW3_FILE): array {
  static $cache = [];
  if (isset($cache[$path])) return $cache[$path];

  $out = ['containers' => [], 'skipped' => []];

  $raw = @file_get_contents($path);
  if ($raw === false) return $cache[$path] = $out;

  $data = json_decode($raw, true);
  if (!is_array($data)) return $cache[$path] = $out;

  foreach ($data as $folder) {
    if (!is_array($folder)) continue;
    $name = trim((string)($folder['name'] ?? ''));
    if ($name === '') continue;

    if (trim((string)($folder['regex'] ?? '')) !== '') {
      $out['skipped'][] = $name;
      continue;
    }

    foreach ((array)($folder['containers'] ?? []) as $container) {
      $container = trim((string)$container);
      if ($container !== '') $out['containers'][$container] = $name;
    }
  }

  return $cache[$path] = $out;
}

/* ---------------------------------------------------------------- icons -- */

/**
 * One row's icon: the app's own word for it, the picture Unraid already
 * downloaded for that container, then the public collection matched on the
 * image name. Every step reaches only staxx_icon_resolve()/
 * staxx_icon_unraid(), so nothing here downloads anything.
 *
 * WHAT ALREADY-ON-DISK OUTRANKS. Nearly every template names its icon as a
 * web address, which resolves to "nothing to show yet, fetch this later" —
 * so taking the app's own word first and stopping there left a list of 85
 * rows with 7 pictures on it and 77 downloads pending, on a panel whose
 * whole justification was that this server already holds the pictures.
 * Unraid downloaded one per container long ago, and it is the same picture
 * from the same address.
 *
 * So a source that can be drawn RIGHT NOW beats one that has to be fetched,
 * whoever named it. The pending address is kept as the fallback rather than
 * discarded, which is what covers a container Unraid never downloaded one
 * for.
 *
 * @return array{fa:string, ref:string, url:string, remote:string}
 */
function staxx_import_icon(string $iconField, string $dir, string $containerName, string $image): array {
  $found = staxx_icon_resolve($iconField, $dir);

  // Drawable now: a glyph, or a file already in the cache.
  $ready = fn(array $i) => $i['fa'] !== '' || $i['url'] !== '';

  if (!$ready($found) && $containerName !== '') {
    $unraid = staxx_icon_unraid($containerName);
    if ($ready($unraid)) return $unraid;
    // Nothing of Unraid's either — keep whatever the app named, including a
    // download still to come, rather than falling through to a worse guess.
    if ($found['ref'] !== '') return $found;
    if ($unraid['ref'] !== '') return $unraid;
  }

  if ($found['ref'] === '' && $found['fa'] === '' && $image !== '') {
    $found = staxx_icon_resolve('', '', $image);
  }

  return $found;
}

/* -------------------------------------------------------------- containers -- */

/**
 * Every container docker knows about, keyed by name — INCLUDING the ones
 * with no compose label, which staxx_container_index() deliberately leaves
 * out because every other caller only cares about compose-managed
 * containers. A template's container carries no compose label at all, and a
 * "loose" container is defined as having neither a template nor a compose
 * project — so both of the things this file needs to find are exactly the
 * containers that index throws away. One extra `docker ps -a`, the same
 * invocation staxx_container_index() already runs, is the cheapest way to
 * get them back.
 *
 * @return array<string, array{state:string, status:string, project:string}>
 */
function staxx_import_all_containers(): array {
  static $byName = null;
  if ($byName !== null) return $byName;

  $byName = [];
  if (!staxx_docker_running()) return $byName;

  $fmt = '{{.Names}}\t{{.State}}\t{{.Status}}\t'
       . '{{.Label "com.docker.compose.project"}}\tend';
  $out = staxx_sh(
    escapeshellarg(staxx_docker_bin()).' ps -a --no-trunc --format '.escapeshellarg($fmt), 15
  );

  foreach (explode("\n", $out) as $line) {
    if (trim($line) === '') continue;
    $c = explode("\t", $line);
    if (count($c) < 4 || $c[0] === '') continue;
    $byName[$c[0]] = ['state' => $c[1], 'status' => $c[2], 'project' => $c[3]];
  }
  return $byName;
}

/* ---------------------------------------------------------------- templates -- */

/**
 * Simplexml's JSON round-trip turns an EMPTY element (`<PostArgs/>`) into an
 * empty PHP array. PHP treats that as falsy, but this array is handed to the
 * browser as JSON, where `[]` is truthy — and the converter that turns a
 * template into a compose file runs in the browser. Left alone this writes
 * `command: ""` into 83 of the 85 real templates, silently overriding the
 * image's own start-up command. Proven against the real templates, not
 * inferred.
 *
 * Coercing every empty array to an empty string as the file is read fixes it
 * in the one place nothing can bypass. Walked recursively, not just at the
 * top level: a Config entry's own empty fields (an unset Default, say) need
 * exactly the same fix.
 */
function staxx_import_coerce_empty(&$node): void {
  if (!is_array($node)) return;
  if ($node === []) { $node = ''; return; }
  foreach ($node as &$child) {
    staxx_import_coerce_empty($child);
  }
  unset($child);
}

/**
 * Decode one template XML file into the flat array shape ca-convert.js
 * expects, or null if it could not be read at all.
 */
function staxx_import_read_template(string $path): ?array {
  $xml = @simplexml_load_file($path);
  if ($xml === false) return null;

  $decoded = json_decode(json_encode($xml), true);
  if (!is_array($decoded)) return null;

  staxx_import_coerce_empty($decoded);

  // Every <Config> row is rebuilt from the XML by hand, because the JSON
  // round-trip above DESTROYS them.
  //
  // A setting is written `<Config Name="Web UI" Target="5006" Type="Port"
  // …>5006</Config>` — everything that matters is in the attributes. Encode
  // that element on its own and the attributes survive. Encode the whole
  // document, which is what happens here, and each repeated child collapses
  // to its text alone: `"5006"`, and the name, target and type are gone.
  //
  // The converter then sees a setting with no type and skips it, saying so.
  // Measured before this existed: 1,154 settings skipped across 73 of the 85
  // templates on the box, including one that lost all 74 of its own. The
  // import would have looked like it worked and produced containers with no
  // ports, no paths and no variables.
  //
  // The shape built here — attributes under '@attributes', the element's text
  // under 'value' — is the shape the Community Applications feed supplies in
  // its own JSON, which is what the converter already reads. A lone <Config>
  // needs no special case either, since a foreach over none, one or many is
  // the same loop.
  $config = [];
  foreach ($xml->Config as $row) {
    $attrs = [];
    foreach ($row->attributes() as $k => $v) $attrs[(string)$k] = (string)$v;
    $config[] = ['@attributes' => $attrs, 'value' => trim((string)$row)];
  }
  $decoded['Config'] = $config;

  // A template packs every category into one space-separated string
  // ("Network:Management Productivity: Tools:Utilities"); the catalogue
  // supplies a list instead, and normaliseCategory() in ca-convert.js
  // already prefers that shape. Split on whitespace so both sources feed the
  // converter the same way.
  $category = (string)($decoded['Category'] ?? '');
  $decoded['CategoryList'] = array_values(array_filter(
    preg_split('/\s+/', trim($category)) ?: [],
    fn($c) => $c !== ''
  ));

  return $decoded;
}

/**
 * The 85-odd Unraid templates on this server, each carrying the decoded,
 * normalised template under 'app' for the browser to convert.
 */
function staxx_import_templates(): array {
  static $out = null;
  if ($out !== null) return $out;

  $out = [];
  $dir = STAXX_IMPORT_TEMPLATES_DIR;
  if (!is_dir($dir)) return $out;

  $containers = staxx_import_all_containers();
  $taken      = staxx_import_taken_names();
  $fv3        = staxx_import_folderview3();

  foreach ((array)@scandir($dir) as $file) {
    // The folder also holds a .bak of whatever was last overwritten — it
    // parses just as happily as a real template and would otherwise be
    // offered as one.
    if (!preg_match('/\.xml$/i', $file)) continue;
    $path = $dir.'/'.$file;
    if (!is_file($path)) continue;

    $app = staxx_import_read_template($path);
    if ($app === null) {
      $out[] = [
        'source'  => 'template',
        'id'      => $file,
        'name'    => $file,
        'folder'  => '',
        'exists'  => false,
        'running' => false,
        'taken'   => false,
        'notes'   => ['This template could not be read and was skipped.'],
        'app'     => null,
      ];
      continue;
    }

    $name   = (string)($app['Name'] ?? pathinfo($file, PATHINFO_FILENAME));
    $folder = staxx_import_safe_name($name);
    $notes  = [];
    if ($folder !== $name) {
      $notes[] = 'The name "'.$name.'" is not usable as a stack folder name; '
               . 'StaXX would call it "'.$folder.'" instead.';
    }

    // A template's container carries no compose label, so it is matched by
    // its own name — the same name docker gave the container when the
    // template created it.
    $container = $containers[$name] ?? null;
    $exists    = $container !== null;
    $running   = $exists && strtolower($container['state']) === 'running';

    $takenNow = in_array($folder, $taken, true);
    if ($takenNow) $notes[] = 'A stack called "'.$folder.'" already exists.';

    // An imported app is one container, so its Docker folder — if it has
    // one — is exactly the folder its container is already filed in.
    $dockerFolder  = $fv3['containers'][$name] ?? '';
    $folderName    = $dockerFolder !== '' ? staxx_import_safe_name($dockerFolder) : '';
    $folderRenamed = $folderName !== '' && $folderName !== $dockerFolder;

    $out[] = [
      'source'         => 'template',
      'id'             => $file,
      'name'           => $name,
      'folder'         => $folder,
      'exists'         => $exists,
      'running'        => $running,
      'taken'          => $takenNow,
      'notes'          => $notes,
      'app'            => $app,
      'icon'           => staxx_import_icon((string)($app['Icon'] ?? ''), '', $name,
                                               (string)($app['Repository'] ?? '')),
      'dockerFolder'   => $dockerFolder,
      'folderName'     => $folderName,
      'folderRenamed'  => $folderRenamed,
    ];
  }

  return $out;
}

/* ----------------------------------------------------------------- projects -- */

/**
 * Where a Compose Manager project's compose file actually lives, and how
 * that was worked out — the tier that answered matters more than the path
 * itself, since it is the thing most likely to be wrong.
 *
 * @return array{0:string, 1:string} [file, via] — via is 'indirect', 'label'
 *                                     or 'flash'; file is '' if none found.
 */
function staxx_import_resolve_project_file(string $dir, string $project): array {
  // Tier 1: an `indirect` file, when present, names the real folder — used
  // exactly as written, never rebuilt from the project's own folder name,
  // because the two are allowed to disagree (PenPot_Complete's indirect
  // points at a differently-capitalised Penpot_Complete).
  $indirect = $dir.'/indirect';
  if (is_file($indirect)) {
    $target = rtrim(trim((string)@file_get_contents($indirect)), '/');
    if ($target !== '') {
      foreach (STAXX_COMPOSE_FILENAMES as $f) {
        if (is_file($target.'/'.$f)) return [$target.'/'.$f, 'indirect'];
      }
      // indirect exists and was read, but nothing compose-shaped is there
      // (yet, or any more) — the tier is still the honest answer.
      return ['', 'indirect'];
    }
  }

  // Tier 2: a running container's own label says which file started it.
  // staxx_container_index() only stores this label as the key it grouped
  // rows under, so it is recovered by finding a byFile group that holds a
  // row for this project rather than reading it off the row directly.
  foreach (staxx_container_index()['byFile'] as $path => $rows) {
    foreach ($rows as $row) {
      if ($row['project'] === $project) return [$path, 'label'];
    }
  }

  // Tier 3: the project's own folder on the flash drive.
  $flash = staxx_find_compose_file($dir);
  if ($flash !== '') return [$flash, 'flash'];

  return ['', ''];
}

/**
 * The Compose Manager projects on this server. Unlike a template's container,
 * these ARE compose-managed, so staxx_container_index() answers exists/running
 * for them directly.
 */
function staxx_import_projects(): array {
  $out  = [];
  $root = STAXX_IMPORT_PROJECTS_DIR;
  if (!is_dir($root)) return $out;

  $taken = staxx_import_taken_names();
  $index = staxx_container_index();

  foreach ((array)@scandir($root) as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    $dir = $root.'/'.$entry;
    if (!is_dir($dir)) continue;

    $displayName = trim((string)@file_get_contents($dir.'/name'));
    if ($displayName === '') $displayName = $entry;

    // Compose's own guess at the project name from the folder, the same
    // fallback staxx_compose_state() uses to survive a stack being moved —
    // see its note on byName. Good enough here for the same reason: it is
    // what compose itself would have called this project unless something
    // overrode it.
    $project = staxx_project_name($entry);

    [$file, $via] = staxx_import_resolve_project_file($dir, $project);

    $folder = staxx_import_safe_name($displayName);
    $notes  = [];
    if ($folder !== $displayName) {
      $notes[] = 'The name "'.$displayName.'" is not usable as a stack folder name; '
               . 'StaXX would call it "'.$folder.'" instead.';
    }

    if ($file === '') {
      $notes[] = 'No compose file could be found for this project.';
    } elseif (count(staxx_service_names($file)) === 0) {
      $notes[] = 'This project\'s compose file holds no services — importing it '
               . 'would create an empty stack.';
    }

    $override = '';
    foreach (STAXX_IMPORT_OVERRIDE_NAMES as $name) {
      if (is_file($dir.'/'.$name)) { $override = $dir.'/'.$name; break; }
    }
    if ($override !== '') {
      $notes[] = 'This project has an override file that StaXX would not use — '
               . 'importing and starting it would run the base file alone.';
    }

    $env = is_file($dir.'/.env') ? $dir.'/.env' : '';

    $rows    = $index['byProject'][$project] ?? [];
    $exists  = count($rows) > 0;
    $running = false;
    foreach ($rows as $row) {
      if (strtolower($row['state']) === 'running') { $running = true; break; }
    }

    $takenNow = in_array($folder, $taken, true);
    if ($takenNow) $notes[] = 'A stack called "'.$folder.'" already exists.';

    // Whatever the compose file itself already says about its own icon —
    // same 'x-unraid: icon:' key a stack's own tile reads — falling back to
    // whichever service names an image first, so an unstarted project still
    // gets a real logo instead of initials.
    $meta = $file !== '' ? staxx_compose_meta($file) : ['x' => [], 'services' => []];
    $image = '';
    foreach ($meta['services'] as $svc) {
      if (($svc['image'] ?? '') !== '') { $image = $svc['image']; break; }
    }
    $icon = staxx_import_icon((string)($meta['x']['icon'] ?? ''),
                                 $file !== '' ? dirname($file) : '', '', $image);

    $out[] = [
      'source'   => 'project',
      'id'       => $entry,
      'name'     => $displayName,
      'folder'   => $folder,
      'exists'   => $exists,
      'running'  => $running,
      'taken'    => $takenNow,
      'notes'    => $notes,
      'file'     => $file,
      'via'      => $via,
      'override' => $override,
      'env'      => $env,
      'icon'     => $icon,
    ];
  }

  return $out;
}

/* -------------------------------------------------------------------- loose -- */

/**
 * Containers that belong to neither a template nor a compose project — no
 * compose label, and no template on disk claiming their name.
 */
function staxx_import_loose(): array {
  $out        = [];
  $containers = staxx_import_all_containers();
  if (!$containers) return $out;

  $claimed = [];
  foreach (staxx_import_templates() as $t) $claimed[$t['name']] = true;

  $taken = staxx_import_taken_names();

  foreach ($containers as $name => $info) {
    if ($info['project'] !== '') continue;   // compose-managed — not loose
    if (isset($claimed[$name])) continue;    // a template's own container

    $folder = staxx_import_safe_name($name);
    $notes  = [];
    if ($folder !== $name) {
      $notes[] = 'The name "'.$name.'" is not usable as a stack folder name; '
               . 'StaXX would call it "'.$folder.'" instead.';
    }

    $takenNow = in_array($folder, $taken, true);
    if ($takenNow) $notes[] = 'A stack called "'.$folder.'" already exists.';

    $out[] = [
      'source'  => 'loose',
      'id'      => $name,
      'name'    => $name,
      'folder'  => $folder,
      'exists'  => true,
      'running' => strtolower($info['state']) === 'running',
      'taken'   => $takenNow,
      'notes'   => $notes,
      // A loose row's only clue is its own container name, so that is the
      // only source tried — Unraid's downloaded copy, or nothing.
      'icon'    => staxx_import_icon('', '', $name, ''),
    ];
  }

  return $out;
}

/* --------------------------------------------------------------------- all -- */

/** A row's icon as the browser is allowed to see it: 'remote' is where the
 *  server would fetch it from, which is nobody's business but the sweep's. */
function staxx_import_icon_public(array $icon): array {
  return ['fa' => $icon['fa'], 'url' => $icon['url'], 'ref' => $icon['ref']];
}

/** Everything the import panel shows, in one call. */
function staxx_import_list(): array {
  $strip = function (array $rows): array {
    foreach ($rows as &$row) {
      if (isset($row['icon'])) $row['icon'] = staxx_import_icon_public($row['icon']);
    }
    unset($row);
    return $rows;
  };

  return [
    'templates'   => $strip(staxx_import_templates()),
    'projects'    => $strip(staxx_import_projects()),
    'loose'       => $strip(staxx_import_loose()),
    // Folders FolderView3 could not be trusted to file by, named so the
    // panel can say so rather than staying quiet about it.
    'folderRules' => staxx_import_folderview3()['skipped'],
  ];
}

/**
 * Every icon the import panel would like to show but does not have yet, in
 * the same shape staxx_icon_wanted() already hands staxx_icon_sweep() for
 * the main page — see action.php's 'icons' case, which is what asks for
 * this under the import scope.
 *
 * @return array<int, array{ref:string, remote:string}>
 */
function staxx_import_icon_wanted(): array {
  $wanted = [];

  $add = function (array $icon) use (&$wanted) {
    if ($icon['ref'] === '' || $icon['url'] !== '') return;
    $wanted[$icon['ref']] = ['ref' => $icon['ref'], 'remote' => $icon['remote']];
  };

  foreach (array_merge(staxx_import_templates(), staxx_import_projects(), staxx_import_loose()) as $row) {
    if (isset($row['icon'])) $add($row['icon']);
  }

  return array_values($wanted);
}

/* --------------------------------------------------------------------- write -- */

/**
 * The plain-text note written beside every imported stack's compose file.
 *
 * $about carries what it needs to say, keyed to match the shape the readers
 * above already use where that overlaps:
 *   'source'           — 'template', 'project' or 'loose', same values the
 *                         list rows carry.
 *   'id'                — the source's own identifier (a template's file name).
 *   'name'              — its display name.
 *   'container'         — the container name this stack's project will produce.
 *   'containerExists'   — whether docker reports a container of that name now.
 *   'containerRunning'  — whether that container is running now.
 *   'warnings', 'notes' — the converter's own two lists: what it could not
 *                         translate, and what it filled in instead. These are
 *                         shown once today, in a dialog that then closes, so
 *                         this file is the only place they end up written down.
 */
function staxx_import_note(array $about): string {
  $kind = [
    'template' => 'an Unraid template',
    'project'  => 'a Compose Manager project',
    'loose'    => 'an existing container',
  ][(string)($about['source'] ?? '')] ?? 'an import';

  $name = (string)($about['name'] ?? '');
  $id   = (string)($about['id']   ?? '');

  $lines = [];
  $lines[] = '# This stack needs a look before it can run';
  $lines[] = '';
  $lines[] = 'Imported from '.$kind.' "'.$name.'"'
           . ($id !== '' && $id !== $name ? ' ('.$id.')' : '')
           . ' on '.date('Y-m-d').'.';
  $lines[] = '';
  $lines[] = 'This stack will not start while this file is here — StaXX refuses every '
           . 'Start, Stop and Restart button on it.';
  $lines[] = '';
  $lines[] = 'When you have read this and want to go ahead, choose "Take over and start" from '
           . 'this stack\'s own menu on the Stacks page. That switches the old container off, '
           . 'sets it aside under another name, starts this stack in its place, and then asks '
           . 'you whether it works — so there is a way back if it does not.';
  $lines[] = '';
  $lines[] = 'Deleting this file by hand only removes the lock. It does none of the above, so '
           . 'the stack would then fail to start against whatever still holds its container '
           . 'name. Use the menu.';
  $lines[] = '';
  $lines[] = '## The container this stack would use';
  $lines[] = '';

  $container = (string)($about['container'] ?? '');
  if (!empty($about['containerExists'])) {
    $state = !empty($about['containerRunning']) ? 'and is currently running' : 'but is currently stopped';
    $lines[] = 'A container called "'.$container.'" already exists, '.$state.'. It has not '
             . 'been touched by this import and is still exactly as it was. Starting this '
             . 'stack before that container is dealt with would fail, because Docker will '
             . 'not let two containers share one name — handing this stack over is what '
             . 'deals with it.';
  } else {
    $lines[] = 'No container called "'.$container.'" exists on this server right now, so '
             . 'there is nothing here for this stack to replace.';
  }

  $lines[] = '';
  $lines[] = '## What could not be brought across';
  $lines[] = '';
  $warnings = array_values(array_filter(
    (array)($about['warnings'] ?? []), fn($w) => trim((string)$w) !== ''
  ));
  if ($warnings) {
    foreach ($warnings as $w) $lines[] = '- '.$w;
  } else {
    $lines[] = 'Nothing — everything in the original converted cleanly.';
  }

  $lines[] = '';
  $lines[] = '## What was filled in for you';
  $lines[] = '';
  $notes = array_values(array_filter(
    (array)($about['notes'] ?? []), fn($n) => trim((string)$n) !== ''
  ));
  if ($notes) {
    foreach ($notes as $n) $lines[] = '- '.$n;
  } else {
    $lines[] = 'Nothing needed filling in.';
  }

  $lines[] = '';
  return implode("\n", $lines);
}

/**
 * Write one imported stack: the review note first, the compose file second.
 *
 * THE ORDER MATTERS. Between writing the compose file and writing the lock
 * there is an instant where a folder holding a compose file — one whose
 * project name happens to match containers someone else's template or
 * project still runs — reads as an ordinary, unlocked stack. A page render
 * landing in that instant shows a green row offering to stop them, which is
 * the exact accident the review lock (see staxx_review_file() in Stacks.php)
 * exists to prevent. Writing the note first closes that window rather than
 * narrowing it.
 *
 * Refuses outright if anything already exists at the target. staxx_save_stack()
 * alone will happily overwrite — the refusal on an ordinary save lives in
 * action.php's own "save" case, which this path does not go through — so this
 * function has to make that check itself or an import could silently destroy
 * a hand-authored compose file.
 *
 * Any failure after the folder is created is rolled back: the note and the
 * folder are removed, so a refused import leaves nothing behind. The folder
 * removed is always the one this call just made — the refusal above already
 * guarantees nothing existed at $rel beforehand.
 */
function staxx_import_write(string $rel, string $yaml, array $about, string &$error): bool {
  $error = '';
  if (!staxx_valid_path($rel)) {
    $error = 'Stack names may contain letters, numbers, dots, dashes and underscores, '
           . 'must start with a letter or number, and must be 63 characters or fewer.';
    return false;
  }

  // Same rule staxx_save_stack() applies: a stack may sit one folder down, but
  // that folder has to be there already, chosen from the existing list — this
  // import never invents one.
  $folder = staxx_path_folder($rel);
  if ($folder !== '' && !is_dir(staxx_stack_root().'/'.$folder)) {
    $error = 'There is no folder called "'.$folder.'".';
    return false;
  }

  $dir = staxx_stack_dir($rel);
  if (file_exists($dir)) {
    $error = 'A stack called "'.$rel.'" already exists. Pick a different name, or delete '
           . 'the existing stack first.';
    return false;
  }

  if (!@mkdir($dir, 0755, true)) {
    $error = 'Could not create '.$dir;
    return false;
  }

  // Resolved once, here, because staxx_rmtree() compares its containment
  // root against a realpath()'d candidate — hand it an unresolved path and a
  // single symlinked component anywhere above the stack root makes every
  // rollback below silently refuse, leaving exactly the half-written folder
  // they exist to clear away. Same pairing staxx_delete_stack() uses.
  $real = (string)@realpath($dir);

  $notePath = $dir.'/'.STAXX_REVIEW_FILE;
  if (@file_put_contents($notePath, staxx_import_note($about)) === false) {
    $error = 'Could not write the review note into '.$dir;
    staxx_rmtree($real, $real);
    return false;
  }
  @chmod($notePath, 0644);

  if (!staxx_save_stack($rel, $yaml, $error)) {
    staxx_rmtree($real, $real);
    return false;
  }

  return true;
}
?>
