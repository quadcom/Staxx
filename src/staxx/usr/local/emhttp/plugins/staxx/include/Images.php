<?PHP
/* StaXX — the "Scan stored images" window on the Storage tab: finding every
 * Docker image no container uses, sorting it into the groups the window
 * shows (clutter only — PLAN_181 item 9), removing only what was ticked,
 * the capacity bar's figures (item 9), and the facts and removal for a
 * container `docker inspect` cannot read (item 10). PLAN_180 Part 1. Also
 * the daily storage-alert pass (PLAN_181 Part D) that reads this same
 * listing and tells the page's notice bar when clutter is worth a look —
 * nothing here is ever removed on a schedule; only a person ticking a box
 * in this window removes anything.
 * Copyright 2026, StaXX contributors.
 *
 * Every image this file will ever offer for removal is first checked
 * against staxx_update_keep_digests() (UpdateRun.php), the one definition
 * of "wanted for a rollback", so a digest that function protects is
 * protected here too, by the same key. This file never edits that
 * function's keep-set logic; it only calls it.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/staxx/include/Defines.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/ImageHistory.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Updates.php';
require_once '/usr/local/emhttp/plugins/staxx/include/UpdateRun.php';

if (defined('STAXX_IMAGES_LOADED')) return;
define('STAXX_IMAGES_LOADED', true);

/**
 * Its own resolved php binary, the same three lines Crypt.php carries for
 * the same reason (see staxx_crypt_php_bin()): PHP's environment here is not
 * a login shell, so PATH cannot be trusted, and three duplicated lines are
 * cheaper than a dependency on a file this one has no other reason to need.
 */
function staxx_images_php_bin(): string {
  static $bin = null;
  if ($bin !== null) return $bin;
  foreach (['/usr/bin/php', '/usr/local/bin/php'] as $path) {
    if (is_file($path) && is_executable($path)) return $bin = $path;
  }
  return $bin = 'php';
}

/**
 * Docker's own data root ("docker info"'s DockerRootDir) — needed for the
 * capacity bar's figures (item 9) and to find a broken container's own
 * files under <root>/containers/<id>/, which `docker inspect` cannot read
 * for it (item 10). Cached for the life of the request; falls back to
 * Docker's own usual default when `docker info` itself cannot be asked,
 * since a wrong guess only ever costs a missing bar or missing facts
 * downstream, never a wrong command.
 * PLAN_181 item 9 / item 10.
 */
function staxx_images_docker_root(): string {
  static $root = null;
  if ($root !== null) return $root;
  $code = 1;
  $out = trim(staxx_sh(staxx_docker_bin().' info --format '.escapeshellarg('{{.DockerRootDir}}'), 10, $code));
  return $root = ($code === 0 && $out !== '') ? $out : '/var/lib/docker';
}

/**
 * PLAN_181 item 9 — the capacity bar's numbers. `df` on Docker's own data
 * root: on Unraid that is the docker.img loop file's filesystem, or in
 * directory mode the pool it sits on. Null on any failure — the page draws
 * no bar rather than a wrong one.
 */
function staxx_images_storage(): ?array {
  $code = 1;
  $out = trim(staxx_sh('df -B1 --output=size,avail '.escapeshellarg(staxx_images_docker_root()), 10, $code));
  if ($code !== 0 || $out === '') return null;
  $lines = explode("\n", $out);
  $data  = trim($lines[1] ?? '');
  if (!preg_match('/^(\d+)\s+(\d+)$/', $data, $m)) return null;
  return ['total' => (int)$m[1], 'free' => (int)$m[2]];
}

/**
 * PLAN_181 "layer counting" build — Docker's storage driver ("docker
 * info"'s Driver, e.g. "btrfs" on the box), needed to find where each
 * layer's own size is recorded. Cached for the life of the request.
 */
function staxx_images_docker_driver(): string {
  static $driver = null;
  if ($driver !== null) return $driver;
  $code = 1;
  $out = trim(staxx_sh(staxx_docker_bin().' info --format '.escapeshellarg('{{.Driver}}'), 10, $code));
  return $driver = ($code === 0 && $out !== '') ? $out : '';
}

/**
 * An image's layer chain. Proved on the box 2026-09-25: `docker history`
 * does NOT align with RootFS.Layers (pmd:local has 10 layers but only 7
 * non-zero history rows), so it cannot be used to attribute size to a
 * layer — Docker's own layer store can. A chain ID is not the layer's own
 * diff ID except for the first one; each later one folds in everything
 * before it, which is exactly what lets two images that share a leading
 * run of layers also share that run's chain IDs, and so its sizes.
 *
 * @param array $diffIds RootFS.Layers, in order ("sha256:<hex>" each)
 * @return array chain IDs, same order, one per diff ID
 */
function staxx_images_chain_ids(array $diffIds): array {
  $chains = [];
  $prev = null;
  foreach ($diffIds as $diffId) {
    $diffId = (string)$diffId;
    $chain = $prev === null ? $diffId : 'sha256:'.hash('sha256', $prev.' '.$diffId);
    $chains[] = $chain;
    $prev = $chain;
  }
  return $chains;
}

/**
 * One layer's own size, straight from Docker's layer store — a plain file
 * under DockerRootDir, root-only, no `docker` call needed:
 * <DockerRootDir>/image/<Driver>/layerdb/sha256/<hex>/size. Proved on the
 * box: for pmd:local the ten layers' sizes summed to exactly its own
 * reported Size, 195110997. Null on any failure (no driver, missing file,
 * unreadable, not a number): the caller falls back to full image sizes for
 * the whole reply rather than mixing layer-accurate and guessed figures.
 */
function staxx_images_layer_size(string $chainId): ?int {
  $driver = staxx_images_docker_driver();
  if ($driver === '' || strncmp($chainId, 'sha256:', 7) !== 0) return null;
  $hex  = substr($chainId, 7);
  $path = staxx_images_docker_root().'/image/'.$driver.'/layerdb/sha256/'.$hex.'/size';
  $raw  = @file_get_contents($path);
  if ($raw === false) return null;
  $raw = trim($raw);
  return ctype_digit($raw) ? (int)$raw : null;
}

/**
 * Read one of Docker's own internal JSON files (config.v2.json,
 * hostconfig.json). Not a supported interface — used only for the fields
 * `docker ps`/`docker inspect` cannot give for a container they cannot
 * read (item 10) — so any failure (missing file, bad JSON) is silent: the
 * caller leaves the field out rather than showing a guess.
 */
function staxx_images_read_json(string $path): ?array {
  $raw = @file_get_contents($path);
  if ($raw === false) return null;
  $data = json_decode($raw, true);
  return is_array($data) ? $data : null;
}

/**
 * PLAN_181 item 10 — the only environment values a broken container's
 * notice will ever show. Everything else in Config.Env (passwords among
 * them) is never read into a reply.
 */
function staxx_images_db_env_allowlist(): array {
  return [
    'POSTGRES_DB', 'POSTGRES_USER', 'PG_VERSION',
    'MYSQL_DATABASE', 'MYSQL_USER', 'MYSQL_VERSION',
    'MARIADB_DATABASE', 'MARIADB_USER', 'MARIADB_VERSION',
  ];
}

/**
 * A bind source with any trailing "/" removed (never the root "/" itself),
 * so "/mnt/user/appdata/postgres15/" from hostconfig.json's Binds and
 * "/mnt/user/appdata/postgres15" from a running container's own Mounts
 * compare and display as the same path.
 */
function staxx_images_norm_path(string $path): string {
  return $path !== '/' ? rtrim($path, '/') : $path;
}

/**
 * Name, mounted-folder sources and network addresses for every RUNNING
 * container — gathered once per scan and reused for every broken
 * container's "who else uses this" comparisons (item 10, folders' usedBy
 * and networks' heldBy), rather than one extra `docker inspect` per folder
 * or network being compared.
 */
function staxx_images_running_facts(array $runningIds): array {
  $out = [];
  if (!$runningIds) return $out;
  $idsArg = implode(' ', array_map('escapeshellarg', $runningIds));
  $raw = staxx_sh(staxx_docker_bin().' inspect --format '.escapeshellarg('{{json .}}').' '.$idsArg, 20);
  foreach (explode("\n", trim($raw)) as $line) {
    $line = trim($line);
    if ($line === '') continue;
    $info = json_decode($line, true);
    if (!is_array($info)) continue;

    $sources = [];
    foreach ((array)($info['Mounts'] ?? []) as $m) {
      if (!empty($m['Source'])) $sources[] = staxx_images_norm_path((string)$m['Source']);
    }
    $nets = [];
    foreach ((array)($info['NetworkSettings']['Networks'] ?? []) as $netName => $net) {
      if (!empty($net['IPAddress'])) $nets[$netName] = (string)$net['IPAddress'];
    }
    $out[] = [
      'name'    => ltrim((string)($info['Name'] ?? ''), '/'),
      'sources' => $sources,
      'nets'    => $nets,
    ];
  }
  return $out;
}

/**
 * Everything the window shows for one container `docker inspect` cannot
 * read (PLAN_181 item 10), replacing the old warning sentence. `docker ps`
 * has already named its image by the time this is called; everything else
 * is read from Docker's own internal files and is entirely optional — a
 * source that fails or does not parse just leaves that field out.
 *
 * @param array $ps      this container's row from the batch `docker ps -a`
 *                        (id, name, created, state, image — no size; see
 *                        below for why that is fetched separately here)
 * @param bool  $imagePresent whether $ps['image'] still resolves to a
 *                        local image
 * @param array $running  every running container's name/mounts/networks,
 *                        from staxx_images_running_facts()
 */
function staxx_images_broken_entry(array $ps, bool $imagePresent, array $running): array {
  $id       = $ps['id'];
  $rawImage = trim((string)($ps['image'] ?? ''));

  $entry = [
    'id'           => $id,
    'name'         => $ps['name'],
    'state'        => $ps['state'],
    'running'      => $ps['state'] === 'running',
    'imageShort'   => strncmp($rawImage, 'sha256:', 7) === 0
                         ? staxx_update_short_id($rawImage) : $rawImage,
    'imagePresent' => $imagePresent,
  ];

  // Docker's CreatedAt carries a numeric offset AND a trailing zone name
  // ("-0500 EST"); the name after the offset sometimes trips strtotime(),
  // so it is dropped — the offset alone is enough to place the moment.
  $createdRaw = preg_replace('/\s+[A-Za-z]+$/', '', trim((string)($ps['created'] ?? '')));
  $ts = $createdRaw !== '' ? strtotime($createdRaw) : false;
  if ($ts !== false) $entry['created'] = date('c', $ts);

  // --size over ALL containers measured at 10.2s on a box with 81 of them —
  // past staxx_images_unused()'s own 10s timeout on that batch call — so
  // this one broken container's size is asked for on its own instead,
  // filtered to its id (0.013s measured), never as part of the batch.
  $sizeOut = trim(staxx_sh(
    staxx_docker_bin().' ps -a --no-trunc --size --filter '.escapeshellarg('id='.$id)
      .' --format '.escapeshellarg('{{.Size}}'), 8
  ));
  $firstSize = explode(' ', $sizeOut)[0] ?? '';
  $entry['empty'] = strcasecmp($firstSize, '0B') === 0;

  if ($entry['running']) {
    $statCode = 1;
    $statOut  = trim(staxx_sh(
      staxx_docker_bin().' stats --no-stream --format '.escapeshellarg('{{.CPUPerc}}'."\t".'{{.MemUsage}}')
        .' '.escapeshellarg($id), 8, $statCode
    ));
    if ($statCode === 0 && $statOut !== '') {
      $cols = explode("\t", $statOut);
      if (isset($cols[0]) && trim($cols[0]) !== '') $entry['cpu'] = trim($cols[0]);
      if (isset($cols[1]) && trim($cols[1]) !== '') $entry['mem'] = trim($cols[1]);
    }
  }

  $root   = staxx_images_docker_root();
  $config = staxx_images_read_json($root.'/containers/'.$id.'/config.v2.json');
  $host   = staxx_images_read_json($root.'/containers/'.$id.'/hostconfig.json');

  if (is_array($config)) {
    $cfgImage = trim((string)($config['Config']['Image'] ?? ''));
    if ($cfgImage !== '') $entry['imageRef'] = $cfgImage;

    $started = (string)($config['State']['StartedAt'] ?? '');
    if ($started !== '') $entry['neverStarted'] = strncmp($started, '0001-', 5) === 0;

    $labels = (array)($config['Config']['Labels'] ?? []);
    $entry['unraidTemplate'] = ($labels['net.unraid.docker.managed'] ?? '') === 'dockerman';

    $allow = array_flip(staxx_images_db_env_allowlist());
    $vars  = [];
    foreach ((array)($config['Config']['Env'] ?? []) as $line) {
      $eq = strpos((string)$line, '=');
      if ($eq === false) continue;
      $key = substr((string)$line, 0, $eq);
      if (isset($allow[$key])) $vars[$key] = substr((string)$line, $eq + 1);
    }
    if ($vars) {
      $db      = $vars['POSTGRES_DB']      ?? $vars['MYSQL_DATABASE']   ?? $vars['MARIADB_DATABASE'] ?? '';
      $user    = $vars['POSTGRES_USER']    ?? $vars['MYSQL_USER']       ?? $vars['MARIADB_USER']     ?? '';
      $version = $vars['PG_VERSION']       ?? $vars['MARIADB_VERSION']  ?? $vars['MYSQL_VERSION']    ?? '';
      // Docker Hub's PG_VERSION carries the package build too
      // ("15.10-1.pgdg120+1") — only the part a person would recognise is shown.
      $version = explode('-', $version)[0];

      $engine = '';
      if (isset($vars['PG_VERSION']) || isset($vars['POSTGRES_DB']) || isset($vars['POSTGRES_USER'])) {
        $engine = 'PostgreSQL';
      } elseif (isset($vars['MARIADB_DATABASE']) || isset($vars['MARIADB_USER']) || isset($vars['MARIADB_VERSION'])) {
        $engine = 'MariaDB';
      } elseif (isset($vars['MYSQL_DATABASE']) || isset($vars['MYSQL_USER']) || isset($vars['MYSQL_VERSION'])) {
        $engine = 'MySQL';
      }

      if ($db !== '' || $user !== '' || $version !== '' || $engine !== '') {
        $entry['database'] = ['engine' => $engine, 'db' => $db, 'user' => $user, 'version' => $version];
      }
    }

    $ports = array_keys((array)($config['Config']['ExposedPorts'] ?? []));
    if ($ports) $entry['ports'] = $ports;

    // On Unraid, a container fixed to a custom network shows up under TWO
    // names with the same address — the network's own name, and again under
    // HostConfig.NetworkMode (on the box, "br0.2" and "eth0.2"). Only the
    // name that is not NetworkMode is kept, so the window lists the address
    // once. heldBy checks a running container's address on ANY of its
    // networks, not just the one with this name, for the same reason.
    //
    // Only IPAMConfig.IPv4Address is ever shown here — that is the FIXED
    // address a person configured. A container that never started has no
    // live NetworkSettings.IPAddress to fall back to anyway, and even where
    // one happened to be readable, a dynamic address must never be reported
    // as fixed. A network with no fixed address still appears, with ip ''.
    $mac         = (string)($config['Config']['MacAddress'] ?? '');
    $networkMode = is_array($host) ? (string)($host['NetworkMode'] ?? '') : '';
    $byIp        = [];  // fixed ip => network name, deduplicated
    $noFixedIp   = [];  // network name => true, kept one row each, never merged
    foreach ((array)($config['NetworkSettings']['Networks'] ?? []) as $netName => $net) {
      if ($mac === '') $mac = (string)($net['MacAddress'] ?? '');
      $ip = (string)($net['IPAMConfig']['IPv4Address'] ?? '');
      if ($ip === '') { $noFixedIp[$netName] = true; continue; }

      if (!isset($byIp[$ip])) {
        $byIp[$ip] = $netName;
      } elseif ($byIp[$ip] === $networkMode && $netName !== $networkMode) {
        $byIp[$ip] = $netName;
      }
    }
    $networks = [];
    foreach ($byIp as $ip => $netName) {
      $heldBy = '';
      foreach ($running as $r) {
        if (in_array($ip, $r['nets'], true)) { $heldBy = $r['name']; break; }
      }
      $row = ['name' => $netName, 'ip' => $ip];
      if ($heldBy !== '') $row['heldBy'] = $heldBy;
      $networks[] = $row;
    }
    foreach (array_keys($noFixedIp) as $netName) {
      $networks[] = ['name' => $netName, 'ip' => ''];
    }
    if ($networks) $entry['networks'] = $networks;
    $entry['mac'] = $mac;
  }

  if (is_array($host)) {
    $entry['limits'] = [
      'memory'     => (int)($host['Memory'] ?? 0),
      'nanoCpus'   => (int)($host['NanoCpus'] ?? 0),
      'devices'    => count((array)($host['Devices'] ?? [])),
      'privileged' => (bool)($host['Privileged'] ?? false),
    ];

    $folders = [];
    foreach ((array)($host['Binds'] ?? []) as $bind) {
      $parts  = explode(':', (string)$bind);
      $source = staxx_images_norm_path((string)($parts[0] ?? ''));
      $dest   = $parts[1] ?? '';
      if ($source === '') continue;

      $row = ['source' => $source, 'dest' => $dest];

      // A network or remote mount is never measured: on this box `du` over
      // a dead network share wedged the whole process in uninterruptible
      // sleep, which even `timeout` cannot kill. `-x` keeps any ordinary
      // du call from wandering onto one it was not warned about.
      $isRemote = (bool)preg_match('#^/mnt/(remotes|rootshare|addons)/#', $source);
      if ($isRemote) {
        $row['remote'] = true;
        $row['bytes']  = null;
      } else {
        $duCode = 1;
        $duOut  = trim(staxx_sh('du -sbx '.escapeshellarg($source), 10, $duCode));
        $row['bytes'] = ($duCode === 0 && preg_match('/^(\d+)/', $duOut, $m)) ? (int)$m[1] : null;
      }

      $mtime = @filemtime($source);
      if ($mtime !== false) $row['changed'] = date('c', $mtime);

      $usedBy = [];
      foreach ($running as $r) {
        if (in_array($source, $r['sources'], true)) $usedBy[] = $r['name'];
      }
      if ($usedBy) $row['usedBy'] = $usedBy;

      $folders[] = $row;
    }
    if ($folders) $entry['folders'] = $folders;
  }

  $templatePath = '/boot/config/plugins/dockerMan/templates-user/my-'.$entry['name'].'.xml';
  $entry['templateFile'] = is_file($templatePath) ? basename($templatePath) : '';

  return $entry;
}

/**
 * Every digest staxx_update_keep_digests() protects, flattened into one flat
 * set — rule 4a says a roll-back image is matched by digest ALONE, whatever
 * repository key the keep-set happens to file it under, so the repository
 * grouping that function returns is deliberately thrown away here.
 */
function staxx_images_keep_digest_set(string $excludeStack = ''): array {
  $flat = [];
  foreach (staxx_update_keep_digests($excludeStack) as $digests) {
    foreach ($digests as $d) $flat[$d] = true;
  }
  return $flat;
}

/**
 * PLAN_181 Part B — the images an archived stack's own version history held,
 * still on disk, that nothing else on this server now needs: given the
 * (repository, digest) pairs its history recorded — gathered by the caller
 * BEFORE the stack's folder is touched, since that history lives inside it
 * (see staxx_archive_stack_history_pairs() in Stacks.php) — resolves each to
 * a local image, drops anything a current stack's keep-set (Part A) still
 * protects or any container still uses, and returns the rest in the same
 * {id, tags, size} shape staxx_images_do_remove() already accepts.
 *
 * $excludeStack is the stack whose own archive this is — passed straight
 * through to the keep-set so the DRY RUN, asked before anything is actually
 * removed, agrees with what the real removal (asked after) finds: this
 * stack's own compose file and history are still on disk at dry-run time and
 * must not protect its own images from themselves.
 *
 * Unlike staxx_images_unused(), which can lean on `docker rmi` (never `-f`)
 * as its own last-resort safety net for a container it could not fully
 * account for, this function acts entirely on its own — nothing here asks
 * before removing. So staxx_images_used_scan()'s tolerant retry is treated
 * strictly for the one case that actually matters: if `docker ps` itself
 * could not be asked, or a container `docker ps` could not even name an
 * image for, nothing is offered for removal at all, rather than trusting a
 * used-set that might be missing exactly the container that matters.
 * 'unresolved' alone is NOT treated as a reason to refuse, deliberately —
 * see below.
 */
function staxx_archive_removable_images(array $pairs, string $excludeStack): array {
  $pairs = array_values(array_filter($pairs, function ($p) {
    return is_array($p) && !empty($p['repo']) && !empty($p['digest']);
  }));
  if (!$pairs || !staxx_docker_running()) return [];

  $keepFlat = staxx_images_keep_digest_set($excludeStack);

  // scan['unresolved'] means some container's own image was NAMED by
  // `docker ps` but nothing local answers to it — the box's postgresql15
  // case. That image is not on disk at all, so there is nothing here for it
  // to protect: refusing over it would mean this half of the archive never
  // removes anything for as long as that one broken container exists.
  $scan = staxx_images_used_scan();
  if (!$scan['ok']) return [];
  $used = $scan['used'];

  $rows = []; // keyed by image id, so two services (or two versions) resolving
              // to the same local image are only ever offered once
  foreach ($pairs as $p) {
    if (isset($keepFlat[$p['digest']])) continue;

    $code = 1;
    $out  = staxx_sh(
      staxx_docker_bin().' image inspect --format '.escapeshellarg('{{json .}}')
        .' '.escapeshellarg($p['repo'].'@'.$p['digest']),
      10, $code
    );
    if ($code !== 0) continue; // no longer present locally — nothing to remove

    $info = json_decode(trim($out), true);
    if (!is_array($info)) continue;
    $id = (string)($info['Id'] ?? '');
    if ($id === '' || isset($used[staxx_update_short_id($id)]) || isset($rows[$id])) continue;

    $tags = [];
    foreach ((array)($info['RepoTags'] ?? []) as $rt) {
      if ($rt !== '<none>:<none>') $tags[] = $rt;
    }
    $rows[$id] = ['id' => $id, 'tags' => $tags, 'size' => (int)($info['Size'] ?? 0)];
  }

  return array_values($rows);
}

/**
 * Which stack and service each kept digest belongs to, so the "Kept so
 * ... can be rolled back" row can name it. Walks the same history sources
 * staxx_update_keep_digests() does (staxx_image_history_all() plus the
 * central file's own 'history' half) but keeps the attribution that
 * function's flat return throws away — a second, small walk rather than a
 * change to that safety-critical function. Never touches the "local
 * pointer" half of the keep-set (the currently-pulled image for each known
 * ref): that entry is not a rollback record, and an image still in that
 * role is already excluded here for a simpler reason — a container is using
 * it, or it is the row a stack's own compose file names.
 */
function staxx_images_keep_owners(): array {
  $owners = [];
  $state  = staxx_update_state();

  $historyKeys = array_unique(array_merge(
    array_keys(staxx_image_history_all()),
    array_keys((array)($state['history'] ?? []))
  ));

  $stacksByName = [];
  foreach (staxx_list_stacks() as $s) $stacksByName[$s['name']] = $s;

  foreach ($historyKeys as $key) {
    [$stack, $service] = array_pad(explode('::', $key, 2), 2, '');
    if (!isset($stacksByName[$stack])) continue;
    $label = $stack.' › '.$service;
    foreach (staxx_update_history($stack, $service) as $d) {
      if (!isset($owners[$d])) $owners[$d] = $label;
    }
  }
  return $owners;
}

/**
 * Every stack's own resolved images, by digest where one is already known
 * locally and by reference always — rule 6: "named by a stack" means the
 * value docker compose config actually resolves after interpolation, the
 * same path staxx_update_keep_digests() already trusts for this, never a
 * raw read of the YAML where "${TAG}" would match nothing.
 *
 * @return array{refs: array<string,array{stack:string, running:bool}>}
 *         refs is keyed on the resolved "image:" string.
 */
function staxx_images_stack_refs(): array {
  $refs = [];
  foreach (staxx_list_stacks() as $s) {
    if ($s['file'] === '') continue;
    $meta = staxx_compose_meta($s['file']);
    foreach ($meta['services'] as $service) {
      $ref = trim((string)($service['image'] ?? ''));
      if ($ref === '') continue;
      // First stack found naming a reference wins — good enough for the
      // note's wording, and two stacks sharing one image is not a case this
      // window has to adjudicate between.
      if (!isset($refs[$ref])) $refs[$ref] = ['stack' => $s['name'], 'running' => $s['running']];
    }
  }
  return $refs;
}

/**
 * Every container's own image, read tolerantly of the one Docker itself
 * cannot inspect (rule 3 — see staxx_images_unused()'s own docblock): a
 * batch call first, and only when that cannot account for every container
 * does this fall back to asking about each one on its own, naming an
 * unreadable container's image via `docker ps` instead (which still answers
 * for a container in a broken state) rather than giving up on it outright.
 *
 * Shared by staxx_images_unused() (the scan window, which still shows its
 * list around a container it cannot fully account for — `docker rmi`
 * without `-f` is its own safety net there, so a candidate the check below
 * cannot resolve is only ever a warning) and staxx_archive_removable_images()
 * (which removes without ever asking, so cannot lean on that same net — see
 * its own caller for how it treats 'unresolved').
 *
 * @return array{
 *   ok: bool,                 // false only when nothing below can be trusted at all
 *   reason: string,           // '' | 'ps-failed' | 'no-image-named' | 'stale-batch' — which refusal fired
 *   name: string, cid: string,// only set for 'no-image-named'
 *   used: array<string,bool>, // short image id => true; meaningful even when a retry was needed
 *   unresolved: bool,         // true if some container's own image could not be resolved at all,
 *                             // even though `docker ps` could name it — still not a hard failure,
 *                             // since it is exactly what "protected by name instead" (rule 3) means
 *   brokenRaw: array,         // ['row' => ps row, 'imagePresent' => bool][] — PLAN_181 item 10's
 *                             // raw material for staxx_images_unused()'s own "broken" entries
 *   psAll: array,             // cid => ps row, only populated when a retry happened
 * }
 */
function staxx_images_used_scan(): array {
  $none = ['ok' => true, 'reason' => '', 'name' => '', 'cid' => '',
           'used' => [], 'unresolved' => false, 'brokenRaw' => [], 'psAll' => []];

  $psCode = 1;
  $psOut  = staxx_sh(staxx_docker_bin().' ps -aq --no-trunc', 10, $psCode);
  if ($psCode !== 0) return ['ok' => false, 'reason' => 'ps-failed'] + $none;

  $cids = array_values(array_filter(array_map('trim', explode("\n", $psOut))));
  if (!$cids) return $none;

  // --no-trunc so a broken entry's own id is the full 64 hex the page needs
  // to send back.
  $idsArg = implode(' ', array_map('escapeshellarg', $cids));
  $inCode = 1;
  $inOut  = staxx_sh(
    staxx_docker_bin().' inspect --format '.escapeshellarg('{{.Image}}').' '.$idsArg,
    20, $inCode
  );
  $inLines = array_values(array_filter(explode("\n", $inOut), function ($l) { return trim($l) !== ''; }));

  $used = [];
  if ($inCode === 0 && count($inLines) === count($cids)) {
    foreach ($inLines as $line) $used[staxx_update_short_id(trim($line))] = true;
    return ['used' => $used] + $none;
  }

  // The batch could not account for every container. Rather than refuse
  // over one unreadable container, inspect each on its own: a container that
  // reads fine still counts as using its image; one that does not is
  // protected by name instead, via `docker ps`. Only a container `docker ps`
  // itself cannot name an image for is still worth refusing everything over.
  //
  // --size left out deliberately: measured on the box at 10.2s over 81
  // containers, past this call's own 10s timeout, which made $psAll come
  // back empty and every broken container fall through to the refusal
  // below. Without it the same call takes 0.03s; a broken container's own
  // size is fetched on its own, filtered to one id, in
  // staxx_images_broken_entry().
  $psAllOut = staxx_sh(
    staxx_docker_bin().' ps -a --no-trunc --format '.escapeshellarg(
      '{{.ID}}'."\t".'{{.Names}}'."\t".'{{.CreatedAt}}'."\t".'{{.State}}'."\t".'{{.Image}}'
    ), 10
  );
  $psAll = [];
  foreach (explode("\n", $psAllOut) as $line) {
    $cols = explode("\t", $line);
    if (count($cols) < 5) continue;
    $psAll[$cols[0]] = [
      'id' => $cols[0], 'name' => $cols[1], 'created' => $cols[2],
      'state' => $cols[3], 'image' => $cols[4],
    ];
  }

  $sawFailure = false;
  $unresolved = false;
  $brokenRaw  = []; // ['row' => psAll row, 'imagePresent' => bool]
  foreach ($cids as $cid) {
    $oneCode = 1;
    $oneOut  = staxx_sh(staxx_docker_bin().' inspect --format '.escapeshellarg('{{.Image}}').' '.escapeshellarg($cid), 8, $oneCode);
    if ($oneCode === 0) {
      $used[staxx_update_short_id(trim($oneOut))] = true;
      continue;
    }
    $sawFailure = true;
    $row  = $psAll[$cid] ?? null;
    $name = $row['name'] ?? $cid;
    $ref  = trim((string)($row['image'] ?? ''));

    if ($row === null || $ref === '') {
      // `docker ps` cannot even name an image for it — nothing to protect
      // on disk, so this is the one case still worth refusing everything
      // over.
      return ['ok' => false, 'reason' => 'no-image-named', 'name' => $name, 'cid' => $cid] + $none;
    }

    $resolveCode = 1;
    $resolvedId  = trim(staxx_sh(
      staxx_docker_bin().' image inspect --format '.escapeshellarg('{{.Id}}').' '.escapeshellarg($ref),
      8, $resolveCode
    ));
    $imagePresent = $resolveCode === 0 && $resolvedId !== '';
    // Whether or not the container itself could be read, its image is
    // protected either way — by being added to $used when it resolves.
    if ($imagePresent) $used[staxx_update_short_id($resolvedId)] = true;
    else $unresolved = true;

    $brokenRaw[] = ['row' => $row, 'imagePresent' => $imagePresent];
  }

  if (!$sawFailure) {
    // Could not reproduce the failure a second time — still refuse, since
    // the count genuinely disagreed once and a stale answer is worse than a
    // repeated check.
    return ['ok' => false, 'reason' => 'stale-batch'] + $none;
  }

  return [
    'ok' => true, 'reason' => '', 'name' => '', 'cid' => '',
    'used' => $used, 'unresolved' => $unresolved, 'brokenRaw' => $brokenRaw, 'psAll' => $psAll,
  ];
}

/**
 * The grouped list the window shows. Every rule in PLAN_180 lives here:
 *
 *  3.  a container `docker inspect` cannot read no longer refuses the whole
 *      list: its image is protected by name instead, via `docker ps`, and it
 *      gets its own "broken" entry (PLAN_181 item 10) instead of a warning.
 *      Only a container `docker ps` itself cannot name an image for still
 *      fails the list closed.
 *  4a. a roll-back image is matched by digest alone.
 *  4b. fails closed on three conditions that would otherwise make an
 *      in-use or roll-back image read as unused (see the three guards
 *      immediately below).
 *  4c. StaXX's own images (the crypt container) are excluded by their own
 *      label, never by guessing at a name.
 *  6.  a stack's images are its compose file's resolved "image:" values.
 *  7.  "built here" means no RepoDigests (a pulled image always has one).
 *
 * PLAN_181 item 9 — the main groups carry only clutter (dangling/older/
 * unused); a stack's own "still wanted" images are no longer a group at
 * all, just bytes folded into totals.wantedBytes.
 *
 * @return array{ok:bool, groups?:array, totals?:array, broken?:array, storage?:?array}
 */
function staxx_images_unused(string &$error): array {
  $error = '';

  // 4b, first third: an "an update is running or queued" guard — the
  // keep-set below is built from the state file, which only catches up
  // once a job finishes, so a pull just started or about to start is
  // invisible to it while it runs.
  foreach ((array)(staxx_update_queue_state()['items'] ?? []) as $item) {
    if (in_array($item['state'] ?? '', ['running', 'waiting'], true)) {
      $error = 'An update is running or queued, so cleanup was skipped. Try again once it finishes.';
      return ['ok' => false];
    }
  }

  // 4b, second third: nothing below can be asked of Docker at all otherwise,
  // and an empty answer would look identical to "nothing is in use".
  if (!staxx_docker_running()) {
    $error = 'The Docker service is not running, so nothing was removed.';
    return ['ok' => false];
  }

  // 4b, last third: without the stacks the keep-set is empty and every
  // roll-back image would read as unused, which a dry listing must not
  // claim any more confidently than a real removal would act on.
  if (!staxx_stacks_visible()) {
    $error = 'StaXX cannot see the stacks right now, so nothing was worked out or removed. '
           . 'Check the array is started, then try again.';
    return ['ok' => false];
  }

  // Rule 3 — every container's own image, tolerant of one Docker itself
  // cannot inspect; see staxx_images_used_scan()'s own docblock for the
  // batch-then-retry shape and why only a container `docker ps` cannot even
  // name an image for still refuses the whole listing.
  $scan = staxx_images_used_scan();
  if (!$scan['ok']) {
    if ($scan['reason'] === 'ps-failed') {
      $error = 'Docker could not be asked which containers exist, so nothing was worked out.';
    } elseif ($scan['reason'] === 'no-image-named') {
      $error = 'Docker could not read the container "'.$scan['name'].'" ('.$scan['cid'].'), so StaXX '
             . 'cannot tell which images are in use. Nothing will be removed until that container '
             . 'is fixed or deleted.';
    } else { // 'stale-batch'
      $error = 'Docker could not be asked about every container, so StaXX cannot tell which images '
             . 'are in use. Nothing will be removed — try again in a moment.';
    }
    return ['ok' => false];
  }
  $used = $scan['used'];

  // Item 10's facts, gathered once for every running container and reused
  // for each broken entry's "who else uses this" comparisons — only needed
  // when the scan above actually had to fall back to a per-container retry.
  $broken = [];
  if ($scan['brokenRaw']) {
    $runningIds = [];
    foreach ($scan['psAll'] as $r) { if (($r['state'] ?? '') === 'running') $runningIds[] = $r['id']; }
    $runningFacts = staxx_images_running_facts($runningIds);
    foreach ($scan['brokenRaw'] as $b) {
      $broken[] = staxx_images_broken_entry($b['row'], $b['imagePresent'], $runningFacts);
    }
  }

  // The candidates: one image per Docker image ID, its tags (if any) kept
  // together on the same row.
  //
  // Two calls, not one: measured on the box (Docker 29.5.3), plain
  // `docker images` no longer lists untagged (dangling) images at all — a
  // fresh `docker import` with no tag is absent from it, present only under
  // `-a` or `--filter dangling=true`. `-a` also lists intermediate build
  // layers, which have children and can never be removed, so the narrower
  // dangling filter is used instead and its rows merged in below. Missing
  // this cost the window most of what it had to show: the box had 64 such
  // images (Docker's own reclaimable figure ~35 GB) the window never saw.
  $lsFormat = escapeshellarg('{{.ID}}'."\t".'{{.Repository}}'."\t".'{{.Tag}}');
  $lsOut = staxx_sh(staxx_docker_bin().' images --no-trunc --format '.$lsFormat, 20)
    ."\n".staxx_sh(staxx_docker_bin().' images --no-trunc --filter dangling=true --format '.$lsFormat, 20);
  // The repository name (everything before the tag) of every image a
  // container is actually using — read from the SAME listing, before it is
  // filtered down to candidates, since a used row is exactly what an
  // "older release of something running" row has to be compared against.
  $usedRepos = [];
  $byId = [];
  // Every image id a container is actually using — kept full-length (not
  // shortened), so the layer-counting build below can ask Docker for their
  // RootFS.Layers too: a layer only an in-use image needs still has to
  // count as "in use", not clutter, even though the image itself is never
  // a candidate row.
  $usedFullIds = [];
  // Its own tags, kept alongside — so a "rebuilt" leftover can be named
  // after a RUNNING app too (the common case: 28 of the box's rebuild
  // leftovers are earlier builds of pmd:local, which is in use, so is
  // never itself a candidate row and would otherwise never be a match
  // target at all).
  $usedTags = [];
  foreach (explode("\n", $lsOut) as $line) {
    $cols = explode("\t", $line);
    if (count($cols) < 3 || trim($cols[0]) === '') continue;
    $id = trim($cols[0]);
    $hasTag = $cols[1] !== '<none>' && $cols[2] !== '<none>';

    if (isset($used[staxx_update_short_id($id)])) {
      if ($hasTag) {
        $usedRepos[$cols[1]] = true;
        $usedTags[$id][] = $cols[1].':'.$cols[2];
      }
      $usedFullIds[$id] = true;
      continue; // rule: never an image a container uses
    }
    if (!isset($byId[$id])) $byId[$id] = [];
    if ($hasTag) $byId[$id][] = $cols[1].':'.$cols[2];
  }
  if (!$byId) {
    return ['ok' => true,
             'groups' => ['keep' => [], 'dangling' => [], 'rebuilt' => [], 'older' => [], 'unused' => []],
             'totals' => ['removableCount' => 0, 'removableBytes' => 0, 'keptCount' => 0, 'keptBytes' => 0, 'wantedBytes' => 0],
             'sizing' => 'layers', 'layers' => [],
             'broken' => $broken, 'storage' => staxx_images_storage()];
  }

  // Rules 4a/4c/6/7 all need docker image inspect's own view — RepoDigests,
  // labels, layers (below) and byte size — asked once over every candidate
  // ID, not one call per image.
  $idsArg = implode(' ', array_map('escapeshellarg', array_keys($byId)));
  $inspectOut = staxx_sh(
    staxx_docker_bin().' image inspect --format '.escapeshellarg('{{json .}}').' '.$idsArg, 30
  );

  $keepDigests = staxx_images_keep_digest_set();
  $keepOwners  = staxx_images_keep_owners();
  $stackRefs   = staxx_images_stack_refs();

  // Pass 1 — every candidate's own facts, and which group it belongs to,
  // without yet building the rows the window is sent: naming a "rebuilt"
  // row (below) needs every TAGGED candidate's layers already known, which
  // is only true once this pass has finished.
  $records          = [];  // id => facts, keyed for the layer pass below
  $taggedCandidates = [];  // every image WITH a tag, whatever its group —
                            // what a "rebuilt" row is compared against
  $protectedLayers  = [];  // layer lists of images excluded before grouping
                            // (today: staxx.crypt) — never clutter, never a row

  foreach (explode("\n", trim($inspectOut)) as $jsonLine) {
    $jsonLine = trim($jsonLine);
    if ($jsonLine === '') continue;
    $info = json_decode($jsonLine, true);
    if (!is_array($info)) continue;

    $id   = (string)($info['Id'] ?? '');
    if ($id === '' || !isset($byId[$id])) continue;
    $tags = $byId[$id];
    $size = (int)($info['Size'] ?? 0);

    // Rule 4c — StaXX's own images (today, only the cryptography
    // container), excluded by the label that container is built with,
    // never by name — see staxx_crypt_images() for the same filter. Never
    // a candidate row, but a layer it still needs is not clutter just
    // because nothing else is watching it: its own layers are recorded
    // before the skip and fed into the "in use" pass below, the same way a
    // running container's are, so a clutter image sharing one is never
    // shown as freeing space this image still holds onto.
    $labels = (array)($info['Config']['Labels'] ?? []);
    if (($labels['staxx.crypt'] ?? '') === '1') {
      $protectedLayers[] = array_values((array)($info['RootFS']['Layers'] ?? []));
      continue;
    }

    $digests     = [];
    $repoDigests = [];  // {repo, digest} pairs — item 11's "dangling" naming needs the repo half too
    foreach ((array)($info['RepoDigests'] ?? []) as $rd) {
      $at = strrpos($rd, '@');
      if ($at !== false) {
        $digests[]     = substr($rd, $at + 1);
        $repoDigests[] = ['repo' => substr($rd, 0, $at), 'digest' => substr($rd, $at + 1)];
      }
    }

    $layers     = array_values((array)($info['RootFS']['Layers'] ?? []));
    $cmd        = $info['Config']['Cmd']        ?? null;
    $entrypoint = $info['Config']['Entrypoint'] ?? null;

    $rec = [
      'id' => $id, 'tags' => $tags, 'size' => $size, 'note' => '',
      'layers' => $layers, 'cmd' => $cmd, 'entrypoint' => $entrypoint,
    ];
    if ($tags) {
      $taggedCandidates[] = ['tag' => $tags[0], 'layers' => $layers, 'cmd' => $cmd, 'entrypoint' => $entrypoint];
    }

    // Rule 4a — roll-back protection, by digest alone.
    $keptDigest = null;
    foreach ($digests as $d) { if (isset($keepDigests[$d])) { $keptDigest = $d; break; } }
    if ($keptDigest !== null) {
      $owner = $keepOwners[$keptDigest] ?? '';
      $rec['note'] = $owner !== ''
        ? 'Kept so "'.$owner.'" can be rolled back.'
        : 'Kept for rolling back an update.';
      $rec['group'] = 'keep';
      $records[$id] = $rec;
      continue;
    }

    // No tag at all — left behind by an update or a rebuild. Item 11's
    // naming split: one WITH a RepoDigest is an update leftover (rule 7 —
    // a pulled image always has one), named by the repository the digest
    // belonged to; one WITHOUT is a local rebuild's leftover, named below
    // (once every tagged candidate's layers are known) against whichever
    // tagged image it most resembles.
    if (!$tags) {
      if ($repoDigests) {
        $rec['group'] = 'dangling';
        $rec['repo']  = $repoDigests[0]['repo'];
      } else {
        $rec['group'] = 'rebuilt';
      }
      $records[$id] = $rec;
      continue;
    }

    // Rule 6/7 — does a stack still name this exact reference, or does it
    // have no RepoDigests at all (built here)? Either way it is "still
    // wanted" — item 9 folds it into the capacity bar's in-use slice and
    // never turns it into a removable row.
    $namedBy = null;
    foreach ($tags as $ref) {
      if (isset($stackRefs[$ref])) { $namedBy = $stackRefs[$ref]; break; }
    }
    $builtLocally = !$digests;
    if ($namedBy !== null || $builtLocally) {
      $rec['group'] = 'wanted';
      $records[$id] = $rec;
      continue;
    }

    // Older release of something still running: this row's own repository
    // (the part of the tag before the colon) is one $usedRepos already
    // named — built above from the SAME `docker images` listing, so a
    // repository whose local name differs from its hub path (the Part 2
    // fault this plan sits beside) is compared under its own name on both
    // sides and still matches correctly.
    $isOlder = false;
    foreach ($tags as $ref) {
      $c = strrpos($ref, ':');
      $repo = $c !== false ? substr($ref, 0, $c) : $ref;
      if (isset($usedRepos[$repo])) { $isOlder = true; break; }
    }
    $rec['note'] = $isOlder
      ? 'An older release of an app that is running, older than the ones kept for rolling back.'
      : 'No stack uses this. It will download again if you ever need it.';
    $rec['group'] = $isOlder ? 'older' : 'unused';
    $records[$id] = $rec;
  }

  // Pass 1a — every RUNNING (in-use) image's own layers, Cmd and
  // Entrypoint, one more batched inspect over the ids $usedFullIds
  // collected above (never a candidate row, since a container is using
  // them). Needed before naming below: the common real-world "rebuilt"
  // case is a leftover from rebuilding an app that is CURRENTLY RUNNING —
  // on the box, 28 leftovers are earlier builds of pmd:local, itself never
  // a candidate — so the match pool below has to include these, not just
  // the clutter/keep/wanted candidates already in $taggedCandidates.
  $usedLayersById = [];
  if ($usedFullIds) {
    $usedIdsArg = implode(' ', array_map('escapeshellarg', array_keys($usedFullIds)));
    $usedInspectOut = staxx_sh(
      staxx_docker_bin().' image inspect --format '.escapeshellarg('{{json .}}').' '.$usedIdsArg, 30
    );
    foreach (explode("\n", trim($usedInspectOut)) as $jsonLine) {
      $jsonLine = trim($jsonLine);
      if ($jsonLine === '') continue;
      $info = json_decode($jsonLine, true);
      if (!is_array($info)) continue;
      $uid = (string)($info['Id'] ?? '');
      if ($uid === '') continue;
      $uLayers = array_values((array)($info['RootFS']['Layers'] ?? []));
      $usedLayersById[$uid] = $uLayers;
      $uTags = $usedTags[$uid] ?? [];
      if ($uTags) {
        $taggedCandidates[] = [
          'tag' => $uTags[0], 'layers' => $uLayers,
          'cmd' => $info['Config']['Cmd'] ?? null, 'entrypoint' => $info['Config']['Entrypoint'] ?? null,
        ];
      }
    }
  }

  // Pass 2a — every layer's own chain ID and byte size, needed both for
  // naming (below) and for counting (further below), so it is done once,
  // before naming, rather than fetched twice. Every candidate's and every
  // in-use image's layers are already known (above).
  $chainIdsById = [];
  foreach ($records as $rid => $rec) $chainIdsById[$rid] = staxx_images_chain_ids($rec['layers']);
  foreach ($usedLayersById as $uid => $layers) $chainIdsById[$uid] = staxx_images_chain_ids($layers);
  // staxx.crypt images are excluded before grouping and so never reach
  // $records — chained here by their own list position, not an id, purely
  // so their layers still enter $allChainIds and the "in use" pass below.
  $protectedChainSets = [];
  foreach ($protectedLayers as $pl) $protectedChainSets[] = staxx_images_chain_ids($pl);

  $allChainIds = [];
  foreach ($chainIdsById as $chains) { foreach ($chains as $c) $allChainIds[$c] = true; }
  foreach ($protectedChainSets as $chains) { foreach ($chains as $c) $allChainIds[$c] = true; }

  // If ANY layer any of this needs cannot be sized, the whole reply falls
  // back to full image sizes rather than mixing layer-accurate and guessed
  // figures within the same list.
  $layerBytes = [];
  $sizingOk   = true;
  foreach (array_keys($allChainIds) as $chainId) {
    $bytes = staxx_images_layer_size($chainId);
    if ($bytes === null) { $sizingOk = false; continue; }
    $layerBytes[$chainId] = $bytes;
  }

  // Pass 2b — naming a "rebuilt" row: the tagged image — candidate OR
  // in-use, from the combined pool just built — whose layers share the
  // strictly longest leading run with it AND whose Cmd and Entrypoint
  // match exactly, including when both are null/empty (an image with no
  // Cmd only ever matches another image with no Cmd). Never a guess on a
  // tie — two tagged images reaching the same length leave buildOf unset.
  //
  // A shared run proves nothing on its own where every layer in it is
  // empty: Docker gives every all-zero-byte layer (a no-op WORKDIR/ENV
  // instruction, or this suite's own empty-tar test fixture) the SAME
  // diff ID, so an empty leading layer alone would "match" almost any
  // image. The run is trimmed back to its last layer over 0 bytes, and at
  // least one such layer is required, or the candidate is skipped outright.
  //
  // The loop variable below is $named, not $rec — $rec is used BY VALUE
  // elsewhere in this function (Pass 1 above, the row-building pass
  // below), and this is the one place it would be bound BY REFERENCE, so a
  // shared name risks the classic PHP foreach-by-reference alias surviving
  // past the loop. `unset()` already guards against that; the separate
  // name removes the risk regardless of future edits nearby. This is a
  // defensive rename only — it fixes no observed fault. The apparent
  // "match" seen on the box on 2026-09-25 (this suite's own empty-layer
  // fixture reported as an earlier build of a real image) turned out to be
  // the SUITE'S OWN TEST wrongly picking up a real box image before ever
  // reaching this naming code at all — see tests/server/images_unused.php.
  // This naming code was not at fault.
  foreach ($records as $rid => &$named) {
    if ($named['group'] !== 'rebuilt') continue;
    $recChainIds = $chainIdsById[$rid];
    $bestLen = 0; $bestTag = null; $tie = false;
    foreach ($taggedCandidates as $tc) {
      if ($tc['cmd'] !== $named['cmd'] || $tc['entrypoint'] !== $named['entrypoint']) continue;
      $max = min(count($named['layers']), count($tc['layers']));
      $len = 0;
      while ($len < $max && $named['layers'][$len] === $tc['layers'][$len]) $len++;
      if ($len < 1) continue;

      // A 0-byte layer must disqualify, never count as non-zero: $bytes is
      // either a genuine positive integer or exactly null (never 0 read as
      // truthy) — checked explicitly rather than a bare truthiness test,
      // which a real 0-byte layer would also fail but for the wrong reason.
      $effLen = 0;
      for ($i = 0; $i < $len; $i++) {
        $bytes = $layerBytes[$recChainIds[$i]] ?? null;
        if ($bytes !== null && $bytes > 0) $effLen = $i + 1;
      }
      if ($effLen < 1) continue;

      if ($effLen > $bestLen)      { $bestLen = $effLen; $bestTag = $tc['tag']; $tie = false; }
      elseif ($effLen === $bestLen) { $tie = true; }
    }
    if ($bestTag !== null && !$tie) $named['buildOf'] = $bestTag;
  }
  unset($named);

  // Pass 2c — attribution, each layer once, in this priority: a layer any WANTED
  // (still in use, item 9), genuinely running, or excluded-before-grouping
  // (staxx.crypt) image needs is in use; else one any KEEP (roll-back)
  // image needs is kept; else it is clutter.
  $category = [];
  if ($sizingOk) {
    foreach ($records as $rid => $rec) {
      if ($rec['group'] !== 'wanted') continue;
      foreach ($chainIdsById[$rid] as $c) $category[$c] = 'in-use';
    }
    foreach ($usedLayersById as $uid => $layers) {
      foreach ($chainIdsById[$uid] as $c) $category[$c] = 'in-use';
    }
    foreach ($protectedChainSets as $chains) {
      foreach ($chains as $c) $category[$c] = 'in-use';
    }
    foreach ($records as $rid => $rec) {
      if ($rec['group'] !== 'keep') continue;
      foreach ($chainIdsById[$rid] as $c) { if (!isset($category[$c])) $category[$c] = 'kept'; }
    }
    foreach ($records as $rid => $rec) {
      if (!in_array($rec['group'], ['dangling', 'rebuilt', 'older', 'unused'], true)) continue;
      foreach ($chainIdsById[$rid] as $c) { if (!isset($category[$c])) $category[$c] = 'clutter'; }
    }
  }

  $groups = ['keep' => [], 'dangling' => [], 'rebuilt' => [], 'older' => [], 'unused' => []];
  $removableCount = 0; $keptCount = 0; $wantedBytes = 0;
  $removableBytes = 0; $keptBytes = 0;
  $layersOut = [];

  foreach ($records as $rid => $rec) {
    $group = $rec['group'];
    if ($group === 'wanted') { $wantedBytes += $rec['size']; continue; }

    $row = ['id' => $rid, 'tags' => $rec['tags'], 'note' => $rec['note']];
    if (isset($rec['repo']))    $row['repo']    = $rec['repo'];
    if (isset($rec['buildOf'])) $row['buildOf'] = $rec['buildOf'];

    if ($sizingOk) {
      if ($group === 'keep') {
        $bytes = 0;
        foreach ($chainIdsById[$rid] as $c) { if (($category[$c] ?? '') === 'kept') $bytes += $layerBytes[$c]; }
        $row['size'] = $bytes;
      } else {
        $clutter = []; $bytes = 0;
        foreach ($chainIdsById[$rid] as $c) {
          if (($category[$c] ?? '') === 'clutter') { $clutter[] = $c; $bytes += $layerBytes[$c]; }
        }
        $row['size']          = $bytes;
        $row['clutterLayers'] = $clutter;
      }
    } else {
      $row['size'] = $rec['size'];
      if ($group === 'keep') { $keptBytes += $rec['size']; } else { $removableBytes += $rec['size']; }
    }

    $groups[$group][] = $row;
    if ($group === 'keep') { $keptCount++; } else { $removableCount++; }
  }

  if ($sizingOk) {
    // Each layer counted exactly once, globally — never the sum of the
    // rows above, which would count a layer twice wherever two rows share it.
    foreach ($layerBytes as $c => $bytes) {
      $cat = $category[$c] ?? null;
      if ($cat === 'clutter') { $removableBytes += $bytes; $layersOut[$c] = $bytes; }
      elseif ($cat === 'kept') { $keptBytes += $bytes; }
    }
  }

  // Largest first within each group.
  foreach ($groups as &$g) {
    usort($g, function ($a, $b) { return $b['size'] <=> $a['size']; });
  }
  unset($g);

  return [
    'ok'      => true,
    'groups'  => $groups,
    'totals'  => [
      'removableCount' => $removableCount, 'removableBytes' => $removableBytes,
      'keptCount'      => $keptCount,      'keptBytes'      => $keptBytes,
      'wantedBytes'    => $wantedBytes,
    ],
    'sizing'  => $sizingOk ? 'layers' : 'approximate',
    'layers'  => $sizingOk ? $layersOut : [],
    'broken'  => $broken,
    'storage' => staxx_images_storage(),
  ];
}

/**
 * Starts the removal as a detached job, following staxx_crypt_start_job()'s
 * own setsid/log/STAXX_JOB_END shape exactly, so the page's existing `job`
 * poller can follow it unmodified.
 *
 * Rule 2 — the page sends a list of image IDs; this rebuilds the candidate
 * list itself, right now, and silently drops any ID not on it (an ID from a
 * stale window, or a crafted one) rather than trusting the caller. An ID is
 * also checked for shape before anything else touches it.
 */
function staxx_images_remove_job(array $ids, string &$error): string {
  $error = '';

  $listing = staxx_images_unused($error);
  if (!$listing['ok']) return ''; // $error already set, with the right sentence

  // PLAN_181 item 9 — "wanted" is no longer offered for removal at all, so
  // it is dropped from the allowlist along with everything else that never
  // was: "keep" rows, and any id not on the server's own current list.
  // "rebuilt" (leftovers from a local rebuild) is offered like every other
  // clutter group and is ticked by default on the page.
  $allowed = [];
  foreach (['dangling', 'rebuilt', 'older', 'unused'] as $g) {
    foreach ($listing['groups'][$g] as $row) $allowed[$row['id']] = $row;
  }

  $rows = [];
  foreach ($ids as $id) {
    $id = (string)$id;
    if (!preg_match('/^sha256:[0-9a-f]{64}$/', $id)) continue;
    if (isset($allowed[$id])) $rows[] = $allowed[$id];
  }
  if (!$rows) {
    $error = 'None of the selected images could be removed — this list may be out of date. '
           . 'Close this window and open it again.';
    return '';
  }

  if (!staxx_private_dir(STAXX_JOB_DIR)) { $error = 'Could not create '.STAXX_JOB_DIR; return ''; }

  $job = bin2hex(random_bytes(8));
  $log = STAXX_JOB_DIR.'/'.$job.'.log';

  // Handed to the detached process as plain data via var_export(), the same
  // pattern staxx_crypt_start_job() uses to run one of this plugin's own
  // functions in the background — never shell arguments built from user
  // input, since the ids were already checked against the server's own
  // list above.
  $php = staxx_images_php_bin().' -r '.escapeshellarg(
    'require '.var_export(__DIR__.'/Images.php', true).'; '
    .'exit(staxx_images_do_remove('.var_export($rows, true).') ? 0 : 1);'
  );
  $inner = $php.' 2>&1; echo "'.STAXX_JOB_END.' $?"';

  @file_put_contents($log, '$ removing the selected images'."\n\n");
  @chmod($log, 0600);

  @exec('setsid sh -c '.escapeshellarg($inner).' </dev/null >> '.escapeshellarg($log).' 2>&1 &');

  return $job;
}

/**
 * PLAN_181 item 10 — clears Docker's own broken record for one container
 * `docker inspect` cannot read. `docker rm -f`, never `-v`, so any volume
 * it names is left alone — deleting data is a separate, deliberate act this
 * button never performs. Runs inline rather than as a detached job: `rm -f`
 * on a container that is not really running returns in well under a second.
 *
 * The id must be in a FRESH staxx_images_unused()'s `broken` list — never
 * trusted from the caller — the same "check the server's own live list"
 * rule staxx_images_remove_job() follows for image ids.
 */
function staxx_images_remove_broken(string $id, string &$error): bool {
  $error = '';
  if (!preg_match('/^[0-9a-f]{64}$/', $id)) {
    $error = 'That is not a container id StaXX recognises.';
    return false;
  }

  $listError = '';
  $listing = staxx_images_unused($listError);
  if (!$listing['ok']) { $error = $listError; return false; }

  $found = null;
  foreach ($listing['broken'] as $b) {
    if ($b['id'] === $id) { $found = $b; break; }
  }
  if ($found === null) {
    $error = 'That container is no longer listed as broken — close this window and open it again.';
    return false;
  }

  $code = 1;
  staxx_sh(staxx_docker_bin().' rm -f '.escapeshellarg($id), 20, $code);
  if ($code !== 0) {
    $error = 'Docker would not remove the container "'.$found['name'].'" either. Restarting Docker '
           . '(Settings → Docker: set Enable Docker to No, apply, then Yes) often clears a record '
           . 'like this — then scan again.';
    return false;
  }
  return true;
}

/**
 * The job body: rule 5, one row at a time — every repo:tag first (never
 * `-f`; a multi-tag image refuses on its ID until each tag is gone), then
 * the ID for whatever tags it had none of, or to catch a dangling row that
 * had no tag at all. Echoes progress the way every StaXX job does, and the
 * one summary line the window reads its "freed" total from.
 *
 * Only ever called from the detached process staxx_images_remove_job()
 * starts — never anywhere else in this file — so the rows it is handed have
 * already been checked against the live candidate list at the moment the
 * job was started.
 */
function staxx_images_do_remove(array $rows): bool {
  $docker  = staxx_docker_bin();
  $freed   = 0;
  $removed = 0;
  $ok      = true;

  foreach ($rows as $row) {
    $label = $row['tags'] ? implode(', ', $row['tags']) : $row['id'];
    echo 'Removing '.$label."...\n";

    foreach ($row['tags'] as $tag) {
      $code = 1;
      $out = staxx_sh($docker.' rmi '.escapeshellarg($tag).' 2>&1', 20, $code);
      if ($code !== 0) echo '  '.trim($out)."\n";
    }
    $code = 1;
    $out = staxx_sh($docker.' rmi '.escapeshellarg($row['id']).' 2>&1', 20, $code);

    $stillCode = 1;
    staxx_sh($docker.' image inspect '.escapeshellarg($row['id']).' >/dev/null 2>&1', 8, $stillCode);
    if ($stillCode !== 0) {
      $removed++;
      $freed += (int)$row['size'];
    } else {
      $ok = false;
      $reason = trim($out) !== '' ? trim($out) : 'Docker refused to remove it.';
      echo 'Kept '.$label.' — '.$reason."\n";
    }
  }

  echo "\n".'Removed '.$removed.' image'.($removed === 1 ? '' : 's').' and freed '.staxx_images_human_bytes($freed).".\n";
  return $ok;
}

/** "1.2 GB" / "512 MB" — the same rough shape used throughout the page. */
function staxx_images_human_bytes(int $bytes): string {
  if ($bytes <= 0) return '0 MB';
  $units = ['B', 'KB', 'MB', 'GB', 'TB'];
  $i = 0; $n = (float)$bytes;
  while ($n >= 1024 && $i < count($units) - 1) { $n /= 1024; $i++; }
  return ($i >= 2 ? number_format($n, 1) : number_format($n, 0)).' '.$units[$i];
}

/* ---------------------------------------------------------- storage alert -- */

/**
 * PLAN_181 Part D — where the alert's two small state files live, same
 * reasoning as staxx_update_state_file(): a function rather than a constant
 * so a missing store never becomes a real-looking path at the root of the
 * filesystem, and each one is a NEW file (no shape carried over from the
 * removed weekly cleanup).
 *
 * clutter-since.json remembers the first day each image was seen sitting in
 * a clutter group, keyed by id, so the alert can fire on age as well as on
 * how full the storage is; storage-alert.json is what the page's notice
 * actually reads, refreshed by staxx_storage_alert_refresh() below.
 */
function staxx_clutter_since_file(): string {
  $cfg = staxx_config_root();
  return $cfg === '' ? '' : $cfg.'/clutter-since.json';
}

function staxx_storage_alert_file(): string {
  $cfg = staxx_config_root();
  return $cfg === '' ? '' : $cfg.'/storage-alert.json';
}

/**
 * The merge rule as a pure function, proved directly rather than through a
 * real scan: an id already remembered keeps its date; a new id is stamped
 * with today; an id no longer in the clutter is dropped. $existing and
 * $currentIds never touch a file here, so a test can hand both in by hand.
 *
 * @param array<string,string> $existing    id => ISO date, as read from disk
 * @param string[]              $currentIds every id in this scan's clutter groups
 */
function staxx_clutter_since_merge(array $existing, array $currentIds, string $today): array {
  $out = [];
  foreach ($currentIds as $id) {
    $out[$id] = $existing[$id] ?? $today;
  }
  return $out;
}

/**
 * Adrian's rule, 2026-09-25: an alert that fires just because a fixed-size
 * image store is naturally most-full is worthless noise, so it is gated on
 * there being clutter at all, not on fullness alone — a server that never
 * has any never sees this, however full its image store normally runs.
 */
function staxx_storage_alert_rule(
  float $percent, int $clutterBytes, int $oldestDays, int $pctLimit, int $dayLimit
): bool {
  return $clutterBytes > 0 && ($percent >= $pctLimit || $oldestDays >= $dayLimit);
}

/**
 * The daily pass (scripts/update-check storage): runs the same listing the
 * Scan stored images window shows, keeps clutter-since.json current, and
 * writes storage-alert.json for StacksPage.php to read. Never removes
 * anything itself — that is what replacing the weekly cleanup with an alert
 * means (PLAN_181 decision 1).
 *
 * If staxx_images_unused() refuses (Docker down, stacks unreachable, an
 * update running), nothing new is written — the page keeps showing whatever
 * the last successful pass found, rather than an alert built on a blank
 * slate.
 *
 * @return array{ok:bool, alert?:bool, percent?:float, clutterBytes?:int, oldestDays?:int}
 */
function staxx_storage_alert_refresh(string &$error): array {
  $error = '';

  $listing = staxx_images_unused($error);
  if (!$listing['ok']) return ['ok' => false];

  $today = date('Y-m-d');

  $currentIds = [];
  foreach (['dangling', 'rebuilt', 'older', 'unused'] as $g) {
    foreach ($listing['groups'][$g] as $row) $currentIds[] = $row['id'];
  }

  $sinceFile = staxx_clutter_since_file();
  $existing  = [];
  if ($sinceFile !== '') {
    $raw  = @file_get_contents($sinceFile);
    $data = $raw === false ? null : json_decode($raw, true);
    if (is_array($data)) $existing = $data;
  }
  $since = staxx_clutter_since_merge($existing, $currentIds, $today);

  $oldestDays = 0;
  foreach ($since as $date) {
    $days = (int)floor((strtotime($today) - strtotime($date)) / 86400);
    if ($days > $oldestDays) $oldestDays = $days;
  }

  $storage = $listing['storage'];
  $percent = 0.0;
  if (is_array($storage) && ($storage['total'] ?? 0) > 0) {
    $used    = max(0, (int)$storage['total'] - (int)$storage['free']);
    $percent = $used / (int)$storage['total'] * 100;
  }

  $clutterBytes = (int)$listing['totals']['removableBytes'];

  $cfg      = staxx_cfg();
  $pctLimit = (int)($cfg['STORAGE_ALERT_PERCENT'] ?? 85);
  if ($pctLimit < 50 || $pctLimit > 99) $pctLimit = 85;
  $dayLimit = (int)($cfg['STORAGE_ALERT_DAYS'] ?? 30);
  if ($dayLimit < 1 || $dayLimit > 365) $dayLimit = 30;

  $alert = staxx_storage_alert_rule($percent, $clutterBytes, $oldestDays, $pctLimit, $dayLimit);

  // The hash keys the page's dismiss button — a different clutter set
  // (something removed, something new left behind) gets a different hash,
  // so a dismissal from before that change never silently hides a new one.
  sort($currentIds);
  $hash = substr(sha1(implode(',', $currentIds)), 0, 12);

  $result = [
    'alert' => $alert, 'percent' => round($percent, 1), 'clutterBytes' => $clutterBytes,
    'oldestDays' => $oldestDays, 'hash' => $hash,
  ];

  if ($sinceFile !== '') {
    $dir = dirname($sinceFile);
    if (is_dir($dir) || @mkdir($dir, 0755, true)) {
      $tmp = $dir.'/.'.basename($sinceFile).'.'.getmypid().'.tmp';
      if (@file_put_contents($tmp, json_encode($since, JSON_PRETTY_PRINT)) !== false) {
        @rename($tmp, $sinceFile);
        @chmod($sinceFile, 0600);
      }
    }
  }

  $alertFile = staxx_storage_alert_file();
  if ($alertFile !== '') {
    $dir = dirname($alertFile);
    if (is_dir($dir) || @mkdir($dir, 0755, true)) {
      $tmp = $dir.'/.'.basename($alertFile).'.'.getmypid().'.tmp';
      if (@file_put_contents($tmp, json_encode($result, JSON_PRETTY_PRINT)) !== false) {
        @rename($tmp, $alertFile);
        @chmod($alertFile, 0600);
      }
    }
  }

  return ['ok' => true] + $result;
}

/**
 * What StacksPage.php reads to decide whether to show the notice — the file
 * staxx_storage_alert_refresh() last wrote, or a quiet default when nothing
 * has run yet (a fresh install, or a store just chosen with no daily pass
 * behind it) so the page never treats "no file yet" as "alert".
 */
function staxx_storage_alert_state(): array {
  $default = ['alert' => false, 'percent' => 0.0, 'clutterBytes' => 0, 'oldestDays' => 0, 'hash' => ''];
  $file = staxx_storage_alert_file();
  if ($file === '') return $default;
  $raw  = @file_get_contents($file);
  $data = $raw === false ? null : json_decode($raw, true);
  return is_array($data) ? array_merge($default, $data) : $default;
}
