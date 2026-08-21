<?PHP
/* StaXX — where an image's project and support pages live.
 * Copyright 2026, StaXX contributors.
 *
 * WHAT THIS FILE IS FOR
 *
 * Most images can name their own home page without ever asking a registry:
 * it is written into the compose file already, or it was read off the image's
 * own labels during an update check, or Community Applications already knows
 * it, or the address itself names an owner. staxx_project_links() tries each
 * of those in turn and stops at the first one that answers — see PLAN_55,
 * "Part B — find the project and forum links, and store them".
 *
 * NOTHING HERE REACHES THE NETWORK. Every source is something already on
 * disk: the compose file's own x-unraid block, the update-check state file
 * Updates.php already caches, and the Community Applications index CA.php
 * already caches. That is what makes this cheap enough to call from a
 * row-menu action.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/staxx/include/Updates.php';
require_once '/usr/local/emhttp/plugins/staxx/include/CA.php';

if (defined('STAXX_LINKS_BASE_IMAGES')) return;

/**
 * Docker Hub's curated "Official Images" that are genuinely base layers —
 * the handful an ordinary Dockerfile starts FROM. Deliberately not every
 * single-segment name Docker would resolve under library/: a service built
 * locally with no image: line at all still ends up with a plain, single-
 * segment name (see staxx_compose_meta()), and that name is not on Docker
 * Hub just because it happens to look like one that is. Never inventing a
 * link (see staxx_project_links() below) matters more here than covering
 * every base image that exists, so this stays a short, named list rather
 * than "any bare name" — which is also why it is not exhaustive.
 */
const STAXX_LINKS_BASE_IMAGES = [
  'alpine', 'debian', 'ubuntu', 'fedora', 'centos', 'rockylinux', 'almalinux',
  'opensuse', 'busybox', 'scratch',
  'node', 'python', 'golang', 'ruby', 'rust', 'openjdk', 'eclipse-temurin', 'php', 'perl',
];

/** Trim a value and hand it back only if it looks like a real address —
 *  never invented, and never a bare hostname or a javascript: string. */
function staxx_links_url(string $value): string {
  $value = trim($value);
  return strpos($value, 'https://') === 0 ? $value : '';
}

/**
 * An image reference with its tag or digest cut away — the part every source
 * below actually joins on.
 */
function staxx_links_strip_tag(string $image): string {
  $bare = trim($image);
  if ($bare === '') return '';
  $bare = preg_replace('/@sha256:[0-9a-f]+$/', '', $bare);
  $bare = preg_replace('/:[^\/]*$/', '', $bare);
  return $bare;
}

/**
 * The repository path out of an image reference — everything except a
 * leading registry host and the tag/digest already stripped above. Same
 * rules as repositoryPath() in javascript/ca-convert.js, kept in step with
 * it deliberately: this is the PHP side of the same join.
 */
function staxx_links_repo_path(string $image): string {
  $bare = staxx_links_strip_tag($image);
  if ($bare === '') return '';
  $segments = explode('/', $bare);
  if (count($segments) > 1 && preg_match('/[.:]/', $segments[0])) {
    $segments = array_slice($segments, 1);
  }
  return implode('/', $segments);
}

/**
 * The registry host half of an image reference, lower-cased, '' when none is
 * written — the same split staxx_links_repo_path() uses, so the two can never
 * disagree about where the host ends and the path begins.
 *
 * Docker Hub's own aliases (docker.io, index.docker.io, registry-1.docker.io)
 * fold to '', the same as writing no host at all — otherwise an image
 * written `docker.io/x/y` would misread as "moved" against a catalogue entry
 * that carries no host, purely because one of the two spells Hub out and the
 * other does not.
 */
function staxx_links_image_host(string $image): string {
  $bare = staxx_links_strip_tag($image);
  if ($bare === '') return '';
  $segments = explode('/', $bare);
  if (count($segments) < 2 || !preg_match('/[.:]/', $segments[0])) return '';
  $host = strtolower($segments[0]);
  if (in_array($host, ['docker.io', 'index.docker.io', 'registry-1.docker.io'], true)) return '';
  return $host;
}

/**
 * PLAN_61 — has this catalogue app's template moved to a different registry
 * since this compose file was written? Reads only the catalogue index
 * already decoded by staxx_ca_index_data() (see staxx_links_ca_map()); no
 * seek into apps.jsonl and no network call, so this is cheap enough for the
 * per-image loop in staxx_update_check() to call on every pass.
 *
 * What counts as a move is deliberately narrow: the same repository path,
 * a different registry host. An account rename is never offered here and
 * needs no branch to refuse it — under Community Applications' own rules,
 * changing the account or app name after the fact gets the app pulled from
 * the catalogue, so a legitimate rename cannot happen. An apparent one is
 * either a different app entirely (a different repository path, so it is
 * simply never found in the map below) or an app CA has already withdrawn.
 * Either way there is nothing to offer, and nothing to test for.
 *
 * @return array{} | array{host:string, image:string} the new host and the
 *   registry-free path rejoined against it, or [] for not-catalogued, same
 *   host, or anything else.
 */
function staxx_links_move_candidate(string $image): array {
  $repo = strtolower(staxx_links_repo_path($image));
  if ($repo === '') return [];

  $ordinal = staxx_links_ca_map()[$repo] ?? null;
  if ($ordinal === null) return [];

  // The catalogue's own current address, straight off the index row already
  // held in memory — not staxx_ca_app(), which would seek into apps.jsonl for
  // detail this join does not need.
  $catalogueImage = (string)(staxx_ca_index_data()['apps'][$ordinal]['r'] ?? '');
  $catalogueHost  = staxx_links_image_host($catalogueImage);
  if ($catalogueHost === '' || $catalogueHost === staxx_links_image_host($image)) return [];

  return ['host' => $catalogueHost, 'image' => $catalogueHost.'/'.$repo];
}

/**
 * Community Applications' repository field, joined once per request into an
 * ordinal any image can look itself up against. staxx_ca_search() ranks free
 * text and is not this — there is no exact join over the feed today, so this
 * builds the one this file needs, from the same small index search already
 * decodes. First entry for a given repository wins a tie.
 *
 * @return array<string,int> repository path (lowercase) => ordinal
 */
function staxx_links_ca_map(): array {
  static $map = null;
  if ($map !== null) return $map;

  $map = [];
  foreach (staxx_ca_index_data()['apps'] as $i => $app) {
    $repo = strtolower(staxx_links_repo_path((string)($app['r'] ?? '')));
    if ($repo === '' || isset($map[$repo])) continue;
    $map[$repo] = $i;
  }
  return $map;
}

/** ghcr.io/<owner>/<name> → https://github.com/<owner>/<name>, or '' when the
 *  image is not on that host. Marked 'derived' by the caller: a package can
 *  live in a differently-named repository, so this is a good guess, not a
 *  fact. */
function staxx_links_derive_ghcr(string $image): string {
  $bare     = staxx_links_strip_tag($image);
  $segments = explode('/', $bare);
  if (count($segments) < 3 || strtolower($segments[0]) !== 'ghcr.io') return '';
  $owner = $segments[1];
  $name  = $segments[2];
  if ($owner === '' || $name === '') return '';
  return 'https://github.com/'.$owner.'/'.$name;
}

/** An official base image's own Docker Hub page, or '' when this is not one
 *  — see STAXX_LINKS_BASE_IMAGES above for why that is a named list rather
 *  than "any bare name". */
function staxx_links_base_image(string $image): string {
  $bare = staxx_links_strip_tag($image);
  if ($bare === '' || strpos($bare, '/') !== false) return '';
  $bare = strtolower($bare);
  if (!in_array($bare, STAXX_LINKS_BASE_IMAGES, true)) return '';
  return 'https://hub.docker.com/_/'.$bare;
}

/**
 * Where this image's project and support pages are, tried in order until one
 * answers. $stackX and $serviceX are the x-unraid blocks staxx_compose_meta()
 * already parsed for this stack and this one service — 'x' and
 * services[$service]['x'] in its return shape.
 *
 * Project and support are resolved independently, each taking the first
 * source below that offers a value for it — so a stored project alongside a
 * catalogue-only support link is not a contradiction, it is two different
 * questions answered by two different sources. `from` names whichever source
 * supplied the project link (or, failing that, the support link), so the
 * caller's wording can match how confident that source is: a stored answer
 * is a fact, a derived one is a guess.
 *
 * Never invents anything: a source only counts when its value starts
 * `https://`. An image with no registry behind it at all — a locally built
 * one, say — comes back with every field empty.
 *
 * @return array{project:string, support:string, from:string}
 */
function staxx_project_links(string $image, array $stackX, array $serviceX): array {
  $empty = ['project' => '', 'support' => '', 'from' => ''];
  if (trim($image) === '') return $empty;

  $hits = [];

  // 1. a stored answer — the service's own x-unraid block, then the stack's.
  // A stored answer always wins, whichever of the two keys it actually sets.
  $svcProject = staxx_links_url((string)($serviceX['project'] ?? ''));
  $svcSupport = staxx_links_url((string)($serviceX['support'] ?? ''));
  if ($svcProject !== '' || $svcSupport !== '') {
    $hits[] = ['project' => $svcProject, 'support' => $svcSupport, 'from' => 'stored'];
  }
  $stkProject = staxx_links_url((string)($stackX['project'] ?? ''));
  $stkSupport = staxx_links_url((string)($stackX['support'] ?? ''));
  if ($stkProject !== '' || $stkSupport !== '') {
    $hits[] = ['project' => $stkProject, 'support' => $stkSupport, 'from' => 'stored'];
  }

  // 2. the image's org.opencontainers.image.source label, already read by
  // staxx_update_labels_meta() and cached in the update-check state file —
  // one JSON read, already held by staxx_update_state()'s own request cache.
  $images = staxx_update_state()['images'] ?? [];
  $label  = staxx_links_url((string)($images[$image]['source'] ?? ''));
  if ($label !== '') $hits[] = ['project' => $label, 'support' => '', 'from' => 'label'];

  // 3. the Community Applications feed, joined on repository path.
  $repo = strtolower(staxx_links_repo_path($image));
  if ($repo !== '') {
    $ordinal = staxx_links_ca_map()[$repo] ?? null;
    if ($ordinal !== null) {
      $app = staxx_ca_app($ordinal);
      if (is_array($app)) {
        $caProject = staxx_links_url((string)($app['Project'] ?? ''));
        $caSupport = staxx_links_url((string)($app['Support'] ?? ''));
        if ($caProject !== '' || $caSupport !== '') {
          $hits[] = ['project' => $caProject, 'support' => $caSupport, 'from' => 'catalog'];
        }
      }
    }
  }

  // 4. derived from a ghcr.io address.
  $derived = staxx_links_derive_ghcr($image);
  if ($derived !== '') $hits[] = ['project' => $derived, 'support' => '', 'from' => 'derived'];

  // 5. an official base image's own registry page.
  $base = staxx_links_base_image($image);
  if ($base !== '') $hits[] = ['project' => $base, 'support' => '', 'from' => 'registry'];

  $project = ''; $support = ''; $projectFrom = ''; $supportFrom = '';
  foreach ($hits as $hit) {
    if ($project === '' && $hit['project'] !== '') { $project = $hit['project']; $projectFrom = $hit['from']; }
    if ($support === '' && $hit['support'] !== '') { $support = $hit['support']; $supportFrom = $hit['from']; }
  }

  return [
    'project' => $project,
    'support' => $support,
    'from'    => $project !== '' ? $projectFrom : $supportFrom,
  ];
}
?>
