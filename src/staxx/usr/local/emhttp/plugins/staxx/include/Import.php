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

    $out[] = [
      'source'  => 'template',
      'id'      => $file,
      'name'    => $name,
      'folder'  => $folder,
      'exists'  => $exists,
      'running' => $running,
      'taken'   => $takenNow,
      'notes'   => $notes,
      'app'     => $app,
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
    ];
  }

  return $out;
}

/* --------------------------------------------------------------------- all -- */

/** Everything the import panel shows, in one call. */
function staxx_import_list(): array {
  return [
    'templates' => staxx_import_templates(),
    'projects'  => staxx_import_projects(),
    'loose'     => staxx_import_loose(),
  ];
}
?>
