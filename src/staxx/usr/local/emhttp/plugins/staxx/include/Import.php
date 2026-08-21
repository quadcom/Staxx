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
 * The override file paired with a Compose Manager project's resolved compose
 * file, if it has one. Looked for beside the resolved file first, and only
 * then the project's own folder on the flash drive — three projects on the
 * development box run from a folder in appdata while an older copy of both
 * files still sits on flash, and taking the override from there would quietly
 * carry settings the project itself stopped using. The flash folder is still
 * worth trying second, because one project keeps its compose file in appdata
 * and its override on flash.
 *
 * Shared by staxx_import_projects() and staxx_import_drift() so the two can
 * never drift apart on where they look — pure disk checks, nothing here
 * shells out.
 */
function staxx_import_find_override(string $file, string $dir): string {
  $lookIn = $file !== '' ? [dirname($file), $dir] : [$dir];
  foreach ($lookIn as $where) {
    foreach (STAXX_IMPORT_OVERRIDE_NAMES as $name) {
      if (is_file($where.'/'.$name)) return $where.'/'.$name;
    }
  }
  return '';
}

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

    // Compose Manager lowercases a project's name and turns hyphens into
    // underscores when it starts one; StaXX keeps hyphens. So the folder
    // this import writes has to make that same swap, or Docker would see
    // two projects — the one Compose Manager already runs, and a second
    // one under the hyphenated name this plugin would otherwise invent —
    // instead of the one stack we mean it to become.
    $dest    = staxx_import_safe_name(str_replace('-', '_', $entry));
    $project = staxx_project_name($dest);

    [$file, $via] = staxx_import_resolve_project_file($dir, $project);

    $folder = staxx_import_safe_name($displayName);
    $notes  = [];
    // Deliberately nothing here about the display name. The folder this lands
    // in is decided by $dest above — Docker's own name for the project — and
    // a note offering some tidied-up version of the display name instead
    // would be describing something that does not happen.

    // Said once, here, rather than left for the page to work out by reading
    // the sentences below: a note is prose that may be reworded any day, and
    // a row that becomes tickable because a wording changed would import
    // something this list already knows it cannot.
    $ready = true;

    if ($file === '') {
      $notes[] = 'No compose file could be found for this project.';
      $ready = false;
    } else {
      // Told apart on purpose: a file compose REJECTS and a file compose reads
      // but finds nothing in are different problems with different fixes, and
      // one of the projects on the development box is the first kind — a
      // ten-byte file — which the softer wording described as merely empty.
      $meta = staxx_compose_meta($file);
      if (!empty($meta['error'])) {
        $notes[] = 'Compose cannot read this project\'s file, so it cannot be imported. '
                 . 'Compose says: '.$meta['error'];
        $ready = false;
      } elseif (count($meta['services']) === 0) {
        $notes[] = 'This project\'s compose file holds no services — importing it '
                 . 'would create an empty stack.';
        $ready = false;
      }
    }
    if ($dest === '') {
      $notes[] = 'This project\'s name cannot be turned into a stack folder name, '
               . 'so it cannot be imported.';
      $ready = false;
    }

    // Beside the compose file FIRST, and only then the project's own folder —
    // see staxx_import_find_override() for why that order matters.
    $override = staxx_import_find_override($file, $dir);
    if ($override !== '') {
      $notes[] = 'This project has an override file, which will be copied and used.';
    }

    // Same order and the same reason as the override above: compose reads the
    // settings file out of the folder it runs the project from, so the copy
    // beside the compose file is the one in use and a copy left behind on
    // flash may be older. Its own list, deliberately — borrowing the
    // override helper's would tie two lookups together that only happen to
    // agree today.
    $env = '';
    foreach (($file !== '' ? [dirname($file), $dir] : [$dir]) as $where) {
      if (is_file($where.'/.env')) { $env = $where.'/.env'; break; }
    }

    // Matched by the resolved FILE, not by the project name guessed above —
    // a project Compose Manager was told to run under some other name would
    // never show up under our guess, and that mismatch is exactly what
    // 'label'/'matches' below exist to catch.
    $rows    = $file !== '' ? ($index['byFile'][$file] ?? []) : ($index['byProject'][$project] ?? []);
    $exists  = count($rows) > 0;
    $running = false;
    foreach ($rows as $row) {
      if (strtolower($row['state']) === 'running') { $running = true; break; }
    }

    // The project name Docker itself reports for those containers, so it
    // can be compared against the name this import would use. Empty when
    // nothing is running to compare against.
    $label   = $rows ? $rows[0]['project'] : '';
    $matches = $label === '' || $label === $project;
    if (!$matches) {
      $notes[] = 'Docker is currently running this project as "'.$label.'", not "'.$project
               . '" — importing it under this name would not line up with those containers.';
    }

    $takenNow = in_array($dest, $taken, true);
    if ($takenNow) $notes[] = 'A stack called "'.$dest.'" already exists.';

    // Everything else sitting in the project's own folder, so the review
    // note can say what is being left behind. A project resolved through
    // 'indirect' keeps its real files in an appdata folder that holds live
    // data, not project bookkeeping — that folder is never scanned.
    $extras = [];
    if ($via !== 'indirect') {
      $skip = array_merge(
        ['name', 'indirect', 'autostart', 'description', '.env'],
        $file     !== '' ? [basename($file)]     : [],
        $override !== '' ? [basename($override)] : []
      );
      foreach ((array)@scandir($dir) as $extraEntry) {
        if ($extraEntry === '.' || $extraEntry === '..') continue;
        if (in_array($extraEntry, $skip, true)) continue;
        $extras[] = $extraEntry;
      }
      sort($extras);
    }
    if ($extras) {
      $notes[] = 'This project\'s folder also holds: '.implode(', ', $extras)
               . ' — these will not be copied across.';
    }

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
      'dest'     => $dest,
      'ready'    => $ready,
      'project'  => $project,
      'label'    => $label,
      'matches'  => $matches,
      'extras'   => $extras,
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

/* -------------------------------------------------------------------- drift -- */

/**
 * Whether two files differ — sizes first, since these are always small
 * files but there is never a reason to read one twice when its size alone
 * already answers the question. Missing on either side counts as "differs",
 * which only happens here for a project file that has since been removed.
 */
function staxx_import_file_differs(string $a, string $b): bool {
  $sizeA = @filesize($a);
  $sizeB = @filesize($b);
  if ($sizeA === false || $sizeB === false || $sizeA !== $sizeB) return true;
  return @file_get_contents($a) !== @file_get_contents($b);
}

/**
 * Where a Compose Manager project's real compose file is, without asking
 * Docker or compose anything new — unlike staxx_import_resolve_project_file(),
 * which this deliberately does NOT call, because its label tier shells out to
 * `docker ps` on every miss.
 *
 * Same three tiers, cheapest first:
 *
 *   1. An `indirect` file, disk only — see staxx_import_resolve_project_file().
 *   2. staxx_compose_state(), which the rows render has already built and
 *      cached for the whole request — reading it again costs nothing. This is
 *      what catches a project whose real files sit in appdata while a stale
 *      copy is left on flash: three of the seven projects here do exactly
 *      that, and skipping this tier would report drift that is not real.
 *   3. The project's own folder on the flash drive.
 */
function staxx_import_project_file(string $dir, string $project): string {
  $indirect = $dir.'/indirect';
  if (is_file($indirect)) {
    $target = rtrim(trim((string)@file_get_contents($indirect)), '/');
    if ($target !== '') return staxx_find_compose_file($target);
  }

  // byFile lists each project's config files in the order compose reported
  // them — main file first, override second — so the first match found here
  // is the main file, not whichever happens to sort first.
  foreach (staxx_compose_state()['byFile'] as $file => $entry) {
    if (strtolower((string)($entry['name'] ?? '')) === $project && is_file($file)) return $file;
  }

  return staxx_find_compose_file($dir);
}

/**
 * Whether an imported stack's copy still matches the Compose Manager project
 * it was copied from — worked out live, every time, because storing that
 * fact would be exactly the second copy this plugin's whole design refuses
 * to keep (see the module comment in Stacks.php on the stack model).
 *
 * Matched by project name alone: an imported project deliberately keeps the
 * same one, which is the only thing tying a stack back to where it came from.
 *
 * Deliberately its own, cheap resolution rather than staxx_import_projects() —
 * that also validates every project's compose file and resolves an icon for
 * it, which between them cost the better part of a second measured on the
 * server. This row marker cannot afford that on every table render, so it
 * calls neither staxx_import_projects() nor staxx_compose_meta().
 *
 * @return array<string,string> stack rel path => a sentence saying what has
 *                               changed, for every stack whose source has
 *                               drifted. Silent for a match, and silent for
 *                               a project that no longer exists.
 */
function staxx_import_drift(): array {
  $out  = [];
  $root = STAXX_IMPORT_PROJECTS_DIR;
  if (!is_dir($root)) return $out;

  $stacksByProject = [];
  foreach (staxx_scan_stacks()['stacks'] as $found) {
    $stacksByProject[staxx_project_name($found['leaf'])] = $found;
  }
  if (!$stacksByProject) return $out;

  foreach ((array)@scandir($root) as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    $dir = $root.'/'.$entry;
    if (!is_dir($dir)) continue;

    // Same normalisation staxx_import_projects() gives the folder it would
    // write to — Compose Manager's own hyphen-to-underscore swap included.
    $dest    = staxx_import_safe_name(str_replace('-', '_', $entry));
    $project = staxx_project_name($dest);

    $stack = $stacksByProject[$project] ?? null;
    if ($stack === null) continue;   // no stack carries this project's name

    $file = staxx_import_project_file($dir, $project);
    if ($file === '') continue;      // nothing on the project's side to compare

    $ownFile = staxx_find_compose_file($stack['dir']);
    if ($ownFile === '') continue;

    $changed = [];
    if (staxx_import_file_differs($ownFile, $file)) $changed[] = 'its compose file';

    $override    = staxx_import_find_override($file, $dir);
    $ownOverride = staxx_compose_files($ownFile)[1] ?? '';
    if ($ownOverride !== '' && $override !== ''
        && staxx_import_file_differs($ownOverride, $override)) {
      $changed[] = 'its override file';
    }

    if (!$changed) continue;

    $out[$stack['rel']] = 'The Compose Manager project this stack was copied from has changed '
      . 'since — '.implode(' and ', $changed).' here no longer '
      . (count($changed) === 1 ? 'matches' : 'match').' what that project holds.';
  }

  return $out;
}

/* --------------------------------------------------------------------- write -- */

/**
 * The plain-text note written beside every imported stack's compose file.
 *
 * $about carries what it needs to say, keyed to match the shape the readers
 * above already use where that overlaps:
 *   'source'           — 'template', 'project' or 'loose', same values the
 *                         list rows carry.
 *   'id'                — the source's own identifier (a template's file name,
 *                         or a project's directory entry).
 *   'name'              — its display name.
 *   'container'         — the container name this stack's project will produce.
 *   'containerExists'   — whether docker reports a container of that name now.
 *   'containerRunning'  — whether that container is running now.
 *   'warnings', 'notes' — the converter's own two lists: what it could not
 *                         translate, and what it filled in instead. These are
 *                         shown once today, in a dialog that then closes, so
 *                         this file is the only place they end up written down.
 *
 * For 'source' === 'project', staxx_import_write_project() fills in its own
 * set instead, all read from its own trusted lookup of the project rather
 * than anything the browser sent:
 *   'via'          — how the compose file was found: 'indirect', 'label' or
 *                    'flash', matching staxx_import_resolve_project_file().
 *   'file'         — the resolved compose file's path.
 *   'settings'     — whether a .env was copied across.
 *   'overrideSrc'  — the override's original path, or '' if it had none.
 *   'overrideDest' — the filename it was copied under, renamed to pair with
 *                    the compose file — see staxx_compose_files().
 *   'extras'       — filenames left behind in the project's own folder.
 *   'matches'      — whether Docker's own running project name agrees with
 *                    the name this import gives it.
 *   'label'        — Docker's own name for it, when 'matches' is false.
 *   'project'      — the project name this import will produce.
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

  // A project's containers are already running under the same name this
  // import gave the stack, so what needs saying is entirely different from
  // a template's or a loose container's single-container handover — the
  // rest of that story (rename the old container aside, start in its
  // place) does not apply, and describing the menu action itself is left
  // to whoever built it.
  if ((string)($about['source'] ?? '') === 'project') {
    $via     = (string)($about['via'] ?? '');
    $file    = (string)($about['file'] ?? '');
    $foundBy = [
      'indirect' => 'a file inside the project\'s own folder that points at where it actually lives',
      'label'    => 'the label on its own running containers, because the copy on the flash drive '
                  . 'was not the one actually in use',
      'flash'    => 'the project\'s own folder on the flash drive',
    ][$via] ?? 'the project\'s own folder on the flash drive';

    $lines[] = 'Its compose file was found by '.$foundBy.($file !== '' ? ', at "'.$file.'"' : '').'.';
    $lines[] = '';

    $copied = ['its compose file'];
    if (!empty($about['settings'])) $copied[] = 'its settings file (.env)';
    $overrideSrc  = (string)($about['overrideSrc']  ?? '');
    $overrideDest = (string)($about['overrideDest'] ?? '');
    if ($overrideSrc !== '') {
      $renamed = $overrideDest !== '' && basename($overrideSrc) !== $overrideDest
        ? ', renamed to "'.$overrideDest.'" so Docker actually pairs it with the compose file '
        . '(Compose Manager\'s own name for it would not)'
        : '';
      $copied[] = 'its override file'.$renamed;
    }
    $lines[] = 'Copied across: '.implode(', ', $copied).'.';
    $lines[] = '';

    $extras = array_values(array_filter((array)($about['extras'] ?? []), fn($e) => trim((string)$e) !== ''));
    // Honest about what was looked at: for a project that runs from a folder
    // elsewhere, only its own folder on the flash drive was listed. The other
    // one holds live data, and reading a list of that back here would say
    // nothing useful about the project.
    $lines[] = $extras
      ? 'Left behind in the project\'s own folder: '.implode(', ', $extras).'.'
      : ($via === 'flash'
          ? 'Nothing else was left behind — the project\'s folder held only its own bookkeeping.'
          : 'Nothing else was copied from the project\'s own folder. The folder its compose '
          . 'file actually lives in was not listed, because that is where its data lives.');
    $lines[] = '';

    if (empty($about['matches'])) {
      $lines[] = 'Docker is currently running this project as "'.(string)($about['label'] ?? '')
               . '", not "'.(string)($about['project'] ?? '').'" — this stack would not line up '
               . 'with those containers.';
      $lines[] = '';
    }

    $lines[] = 'This project is still listed in Compose Manager, and nothing about it has been '
             . 'touched or removed. Starting it from there while this stack also exists means '
             . 'two places can act on the same containers, so pick one.';
    $lines[] = '';
    $lines[] = 'This stack was given the exact same project name Docker already knows these '
             . 'containers by, so taking it over rebuilds the containers already running in '
             . 'place, rather than starting a second set alongside them.';
    $lines[] = '';
    $lines[] = 'Deleting this file by hand only removes the lock — it does not touch Compose '
             . 'Manager, the running containers, or anything else here.';
    $lines[] = '';
    return implode("\n", $lines);
  }

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
 * The checks and the folder creation every import writer needs before it
 * puts anything down: a usable path, a destination folder that already
 * exists, and nothing already sitting at the target. Shared by
 * staxx_import_write() and staxx_import_write_project() so the two guards
 * can never drift apart.
 *
 * @return string the new stack directory, or '' with $error set on refusal.
 */
function staxx_import_prepare_dir(string $rel, string &$error): string {
  $error = '';
  if (!staxx_valid_path($rel)) {
    $error = 'Stack names may contain letters, numbers, dots, dashes and underscores, '
           . 'must start with a letter or number, and must be 63 characters or fewer.';
    return '';
  }

  // Same rule staxx_save_stack() applies: a stack may sit one folder down, but
  // that folder has to be there already, chosen from the existing list — this
  // import never invents one.
  $folder = staxx_path_folder($rel);
  if ($folder !== '' && !is_dir(staxx_stack_root().'/'.$folder)) {
    $error = 'There is no folder called "'.$folder.'".';
    return '';
  }

  $dir = staxx_stack_dir($rel);
  if (file_exists($dir)) {
    $error = 'A stack called "'.$rel.'" already exists. Pick a different name, or delete '
           . 'the existing stack first.';
    return '';
  }

  if (!@mkdir($dir, 0755, true)) {
    $error = 'Could not create '.$dir;
    return '';
  }

  // The tree's shape just changed on disk; see staxx_scan_stacks_reset().
  staxx_scan_stacks_reset();

  return $dir;
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
  $dir = staxx_import_prepare_dir($rel, $error);
  if ($dir === '') return false;

  // Resolved once, here, because staxx_rmtree() compares its containment
  // root against a realpath()'d candidate — hand it an unresolved path and a
  // single symlinked component anywhere above the stack root makes every
  // rollback below silently refuse, leaving exactly the half-written folder
  // they exist to clear away. Same pairing staxx_archive_stack() uses.
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

/**
 * Write a Compose Manager project in as a stack. Unlike staxx_import_write(),
 * this never routes through staxx_save_stack() — that imposes its own
 * filename and validates the text alone, and the whole point here is a byte
 * copy validated as the pair it will actually run.
 *
 * $id is trusted only as far as it names one of staxx_import_projects()'s own
 * rows, looked up fresh rather than accepting anything the browser calls a
 * path — the one thing this function must never do is act on a location the
 * client named itself.
 *
 * The order is PLAN_46 Part B's, and it is not optional: the folder, then the
 * review note (closing the same unlocked-row window staxx_import_write()'s
 * note does), then the settings file and the override — byte for byte, no
 * validation — and only then the compose file, because a project that fills
 * values in from its own .env cannot validate without the .env already being
 * where compose would look for it. Any failure rolls the half-written folder
 * back, same as staxx_import_write().
 */
function staxx_import_write_project(string $rel, string $id, array $about, string &$error): bool {
  $error = '';

  $project = null;
  foreach (staxx_import_projects() as $row) {
    if ($row['id'] === $id) { $project = $row; break; }
  }
  if ($project === null) {
    $error = 'That Compose Manager project could not be found. Reopen the import panel and try again.';
    return false;
  }
  if ($project['file'] === '') {
    $error = 'No compose file could be found for this project, so there is nothing to import.';
    return false;
  }

  $dir = staxx_import_prepare_dir($rel, $error);
  if ($dir === '') return false;

  // Same pairing staxx_import_write() and staxx_archive_stack() use — see the
  // note there on why this must be resolved before any rollback below.
  $real = (string)@realpath($dir);

  $mainName = basename($project['file']);

  // The override may not be named to pair with the main file the way
  // Compose Manager left it — so it is written under the name that DOES
  // pair, same extension as the main file, and staxx_compose_files() is
  // asked to confirm the pairing once it is down rather than trusted blind.
  $overrideDest = '';
  if ($project['override'] !== '') {
    $dot  = strrpos($mainName, '.');
    $ext  = $dot !== false ? strtolower(substr($mainName, $dot + 1)) : 'yaml';
    $base = $dot !== false ? substr($mainName, 0, $dot) : $mainName;
    $overrideDest = $base.'.override.'.($ext === 'yml' ? 'yml' : 'yaml');
  }

  $about = array_merge($about, [
    'source'       => 'project',
    'id'           => $id,
    'name'         => $project['name'],
    'via'          => $project['via'],
    'file'         => $project['file'],
    'settings'     => $project['env'] !== '',
    'overrideSrc'  => $project['override'],
    'overrideDest' => $overrideDest,
    'extras'       => $project['extras'],
    'matches'      => $project['matches'],
    'label'        => $project['label'],
    'project'      => $project['project'],
  ]);

  $notePath = $dir.'/'.STAXX_REVIEW_FILE;
  if (@file_put_contents($notePath, staxx_import_note($about)) === false) {
    $error = 'Could not write the review note into '.$dir;
    staxx_rmtree($real, $real);
    return false;
  }
  @chmod($notePath, 0644);

  // The settings file and the override, byte for byte — no validation, no
  // reformatting, because Compose Manager's own copy is what the project
  // already runs on and nothing here is entitled to change a character of it.
  if ($project['env'] !== '') {
    $envText = @file_get_contents($project['env']);
    if ($envText === false || @file_put_contents($dir.'/.env', $envText) === false) {
      $error = 'Could not copy the settings file into '.$dir;
      staxx_rmtree($real, $real);
      return false;
    }
    @chmod($dir.'/.env', 0644);
  }

  if ($overrideDest !== '') {
    $overrideText = @file_get_contents($project['override']);
    if ($overrideText === false || @file_put_contents($dir.'/'.$overrideDest, $overrideText) === false) {
      $error = 'Could not copy the override file into '.$dir;
      staxx_rmtree($real, $real);
      return false;
    }
    @chmod($dir.'/'.$overrideDest, 0644);

    if ((staxx_compose_files($dir.'/'.$mainName)[1] ?? '') === '') {
      $error = 'The override file could not be paired with the compose file.';
      staxx_rmtree($real, $real);
      return false;
    }
  }

  // The compose file itself, last: validated as the pair Docker will
  // actually run — the real folder as the project directory, the override
  // (now correctly named) laid over it — but written byte for byte
  // regardless of what that check finds worth warning about.
  $yaml = @file_get_contents($project['file']);
  if ($yaml === false) {
    $error = 'Could not read this project\'s compose file.';
    staxx_rmtree($real, $real);
    return false;
  }

  $overridePath = $overrideDest !== '' ? $dir.'/'.$overrideDest : '';
  $warnings = null;
  if (!staxx_validate_compose($yaml, $error, $dir, $warnings, '', $overridePath)) {
    if ($overridePath !== '') {
      $error .= "\n\nThis project has an override file, and the two are checked together.";
    }
    staxx_rmtree($real, $real);
    return false;
  }

  if (@file_put_contents($dir.'/'.$mainName, $yaml) === false) {
    $error = 'Could not write '.$dir.'/'.$mainName;
    staxx_rmtree($real, $real);
    return false;
  }
  @chmod($dir.'/'.$mainName, 0644);

  return true;
}

/* --------------------------------------------------------------------- handoff -- */

/**
 * A one-shot bridge from the Add Container page to StaXX's own view: the
 * shadowed page decodes an Unraid template and needs to hand it to a
 * different page load. Not a query-string path, on purpose — a filesystem
 * path in a URL is a traversal surface on the receiving end; the source XML
 * may not outlive Community Applications' own temp folder; the original XML
 * is needed again, untouched, when the template is stamped back at save
 * time; and an id that names nothing fails cleanly, as an empty form rather
 * than a broken one.
 *
 * `/tmp`, not the flash drive: this is a few seconds of state and flash
 * writes are worth being stingy with.
 */
const STAXX_HANDOFF_DIR = '/tmp/staxx/handoff';
const STAXX_HANDOFF_TTL = 3600;

/**
 * Deletes every handoff file older than STAXX_HANDOFF_TTL. Called only from
 * staxx_handoff_write() — no timer, and none is wanted, the same discipline
 * the stats collector uses to stop sampling on its own once nobody is asking.
 */
function staxx_handoff_sweep(): void {
  foreach ((array)@glob(STAXX_HANDOFF_DIR.'/*.json') as $file) {
    $mtime = @filemtime($file);
    if ($mtime !== false && (time() - $mtime) > STAXX_HANDOFF_TTL) {
      @unlink($file);
    }
  }
}

/**
 * Stores one decoded template plus its original XML text under a fresh id,
 * returning the id — or '' with $error set. Written to a temp name first and
 * rename()'d into place, so a reader never sees a half-written file, the
 * same pairing the stats collector uses for its own snapshots.
 *
 * $kind is which of the three routes AddContainer.page.tmpl caught this
 * through — 'default' (a fresh install), 'user' (Reinstall From Previous
 * Apps) or 'edit' (converted off the Phase E offer). Carried along only so
 * the browser can word the banner it shows differently and, for 'user',
 * dodge a name the stack it already runs is using — nothing on the server
 * reads it back.
 *
 * $xmlTemplate is the original query value, verbatim — "default:/tmp/...".
 * Unlike $xml, this one IS the browser's business: it is what the "Let
 * Unraid install this instead" escape hatch on the caught-install banner
 * rebuilds Unraid's own URL from (PLAN_63 section 16).
 */
function staxx_handoff_write(
  array $record, string $xml, string &$error, string $kind = 'default', string $xmlTemplate = ''
): string {
  if (!is_dir(STAXX_HANDOFF_DIR) && !@mkdir(STAXX_HANDOFF_DIR, 0700, true)) {
    $error = 'Could not create '.STAXX_HANDOFF_DIR.'.';
    return '';
  }

  staxx_handoff_sweep();

  $id   = bin2hex(random_bytes(16));
  $path = STAXX_HANDOFF_DIR.'/'.$id.'.json';
  $tmp  = $path.'.tmp';

  $json = json_encode(['app' => $record, 'xml' => $xml, 'kind' => $kind, 'xmlTemplate' => $xmlTemplate]);
  if ($json === false || @file_put_contents($tmp, $json) === false) {
    $error = 'Could not write the handoff file.';
    @unlink($tmp);
    return '';
  }
  @chmod($tmp, 0600);

  if (!@rename($tmp, $path)) {
    $error = 'Could not put the handoff file in place.';
    @unlink($tmp);
    return '';
  }

  return $id;
}

/**
 * Reads back a handoff written by staxx_handoff_write(), or null for a
 * malformed id, an id nothing wrote, an expired file, or JSON that will not
 * decode. The id is checked against the hex pattern BEFORE it is ever
 * concatenated into a path — it arrives from a URL and is treated as
 * hostile, never trusted to a name that could only ever be "../../etc".
 *
 * Deliberately not deleted here: Phase D's save step reads the same record
 * again to stamp the Unraid template, so this stays a read, not a
 * read-and-consume. Expiry via STAXX_HANDOFF_TTL is what eventually clears it.
 */
function staxx_handoff_read(string $id): ?array {
  if (!preg_match('/^[0-9a-f]{32}$/', $id)) return null;

  $path  = STAXX_HANDOFF_DIR.'/'.$id.'.json';
  $mtime = @filemtime($path);
  if ($mtime === false || (time() - $mtime) > STAXX_HANDOFF_TTL) return null;

  $json = @file_get_contents($path);
  if ($json === false) return null;

  $decoded = json_decode($json, true);
  return is_array($decoded) ? $decoded : null;
}

/**
 * Whether the file behind a stored xmlTemplate value ("default:/tmp/...")
 * is still there for Unraid to read, checked fresh rather than trusted from
 * the moment the handoff was written — the whole point of asking is whether
 * it is STILL there, since Community Applications may have tidied up its
 * temp copy in the meantime. The escape hatch must never offer a link to a
 * path that has gone; this is what lets it refuse instead of failing silently.
 */
function staxx_handoff_template_available(string $xmlTemplate): bool {
  $colon = strpos($xmlTemplate, ':');
  if ($colon === false) return false;
  $path = substr($xmlTemplate, $colon + 1);
  return $path !== '' && is_file($path) && is_readable($path);
}

/**
 * Phase D — the exit route. Stamps the original template XML back into
 * Unraid's own template folder, the moment a caught install is first saved
 * as a stack, so Apps still sees the app as installed and there is a way
 * back to plain Unraid Docker if StaXX is ever abandoned.
 *
 * Written once, at save, and never revisited: not updated when the stack is
 * later edited, not deleted when the stack is removed. Keeping it in step
 * would mean writing an Unraid template on every stack edit, in a format
 * that cannot express everything a compose file can — a second source of
 * truth by any other name. Nothing in StaXX ever reads this file back; the
 * proper fix for it going stale is a translator that reads the stack as it
 * stands, which is a future possibility and not this.
 *
 * No handoff, or one with no XML in it, is not an error: it just means this
 * save had no caught install behind it. Only a handoff that exists and has
 * to be acted on but fails to write is worth a sentence back.
 */
function staxx_import_stamp_template(string $handoffId, string &$error): string {
  $error = '';

  $handoff = staxx_handoff_read($handoffId);
  $xml     = (string)($handoff['xml'] ?? '');
  if ($handoff === null || $xml === '') return '';

  // The filename CA itself looks for is "my-<Name>.xml" where <Name> is the
  // app's own template field — not the stack's folder name, which may differ
  // if the stack was renamed on the way in. staxx_import_safe_name() slugs an
  // unusable name into something else entirely (falling back to "stack"),
  // which is right for choosing a folder but wrong here: this filename has to
  // be the exact name CA checks for, or not written at all. staxx_valid_name()
  // is the plain yes/no test that fits — it also already refuses "..", a bare
  // slash-free path segment, and an empty string.
  $name = (string)($handoff['app']['Name'] ?? '');
  if (!staxx_valid_name($name)) {
    $error = 'The caught install\'s name was not usable as a filename, so no template was left behind.';
    return '';
  }

  if (!is_dir(STAXX_IMPORT_TEMPLATES_DIR) && !@mkdir(STAXX_IMPORT_TEMPLATES_DIR, 0700, true)) {
    $error = 'Could not create '.STAXX_IMPORT_TEMPLATES_DIR.'.';
    return '';
  }

  $path = STAXX_IMPORT_TEMPLATES_DIR.'/my-'.$name.'.xml';

  // Never overwrite. A file already at that name belongs to an app somebody
  // already has installed, and that template is their own exit route — the
  // collision is reported, not overridden, and nothing is written.
  if (file_exists($path)) {
    $error = 'A template called "my-'.$name.'.xml" already exists, so this install was not '
           . 'stamped as one — the existing file is left exactly as it was.';
    return '';
  }

  // Byte for byte: this is Community Applications' own template text, not a
  // re-serialisation of what staxx_import_read_template() decoded from it.
  if (@file_put_contents($path, $xml) === false) {
    $error = 'Could not write '.$path.'.';
    return '';
  }
  // No chmod: /boot is a vfat mount where every file is owner-only regardless
  // of the mode requested, so a chmod call here would do nothing.

  return $path;
}
?>
