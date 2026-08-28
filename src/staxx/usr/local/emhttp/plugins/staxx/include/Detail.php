<?PHP
/* StaXX — what can this server find out about a stack's presentation details.
 * Copyright 2026, StaXX contributors.
 *
 * WHAT THIS FILE IS FOR
 *
 * PLAN_84 Phase 2. One entry point, staxx_detail_discover(), that asks every
 * existing source — image labels, the Community Applications catalogue, a
 * local Unraid template, Docker Hub, a registry, a live probe — what they
 * know about a stack's icon, description, category, author, links and web
 * page address, and hands back an answer for each field with a plain-English
 * sentence saying where it came from and how much to trust it.
 *
 * NOTHING IS WRITTEN HERE. This is discovery only — see include/action.php's
 * 'detail' case for why the write stays a separate call in the browser.
 *
 * THREE TIERS, deliberately not two, because the sentence shown for each
 * genuinely differs three ways:
 *   stated  — the image's own publisher said it (a label baked into the
 *             image, Docker Hub's own description for the repository).
 *   claimed — somebody else said it (the Community Applications catalogue,
 *             a local Unraid template).
 *   guess   — we worked it out (a ghcr.io address, a unique icon match, a
 *             published port).
 * No catalogue or template value may ever be labelled 'stated' — that is the
 * assertion that protects rule 2 (CLAUDE.md) and the first thing the test
 * suite for this file checks.
 *
 * THE ONE HARD RULE: staxx_detail_schema_ok() applies the schema's own
 * patterns to every candidate before it can appear in the result. A value
 * that fails is dropped as though the source never answered, and the cascade
 * simply moves on to the next one — never a value the schema would refuse.
 *
 * THE SUPPRESSION RULE, shared by every field: a candidate that turns out to
 * equal what the file already holds is not "found new" at all, so it never
 * becomes an answer — this is what keeps a field that is only in the file
 * because staxx_project_links() itself reads it back out of the cascade. It
 * is also exactly the 2026-08-27 revision's "a field that is empty and has a
 * value found is written; one that already holds a different value is a
 * conflict" — an identical value is neither, and is left alone entirely.
 *
 * NETWORK DESIGN. Local `docker inspect` first, always — free and offline,
 * and it closes a real gap: an image pulled but never update-checked has its
 * labels sitting on disk where staxx_project_links() cannot see them. One
 * Docker Hub request only if that came up empty. The four-request registry
 * chain only for an image that was never pulled at all, capped at three
 * chains per run. One deadline for the whole run, checked before every
 * network step — running out means fewer suggestions, never an error.
 * GitHub's API is deliberately never touched here: the author-example
 * feature (Watch.php) budgets that allowance carefully, and spending it on
 * this feature too would break it quietly. Results are cached for six hours,
 * keyed on the image, under /tmp — never the flash drive, the same reasoning
 * every other reducible-download cache in this plugin already follows.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/staxx/include/Defines.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Links.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Watch.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Icons.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Import.php';

if (defined('STAXX_DETAIL_DIR')) return;

// A reducible download, the same reasoning STAXX_WATCH_DIR is built on — not
// worth surviving a reboot, and never the flash drive.
const STAXX_DETAIL_DIR = '/tmp/staxx/detail';

// How long a gathered image's labels/Hub answer are trusted before asking
// again — long enough that opening the "fill in details" bar twice in a
// session never pays for the network twice, short enough that a publisher
// fixing their labels is reflected the same day.
const STAXX_DETAIL_CACHE_TTL = 6 * 3600;

// The whole run's budget, checked before every network step. Running out
// means fewer suggestions, never an error and never a wrong one — see the
// file header.
const STAXX_DETAIL_BUDGET_SECONDS = 20;

// At most this many not-pulled images pay for the four-request registry
// chain in one run — a multi-service stack could otherwise spend a dozen
// chains discovering details for services nobody asked about yet.
const STAXX_DETAIL_MAX_CHAINS = 3;

/* ------------------------------------------------------------------ schema -- */

/**
 * THE hard rule (see file header): does this candidate value pass the
 * x-unraid schema's own pattern for this field, AND the write-safety rule
 * that a value holding a line break cannot go through the compose editor's
 * value writer? Patterns copied from schema/x-unraid.schema.json — kept
 * here rather than loaded from the file itself because this runs on every
 * request and the schema is only ever a self-test's concern, not a runtime
 * dependency of the plugin.
 *
 * Anything failing this is dropped as though the source never answered, and
 * the caller's cascade simply tries its next source — never surfaced as a
 * refusal, because a malformed label or a stray template field is not
 * something the person looking at the chooser needs to be told about.
 */
function staxx_detail_schema_ok(string $field, string $value): bool {
  if ($value === '' || strpos($value, "\n") !== false) return false;

  switch ($field) {
    case 'project':
    case 'support':
    case 'readme':
    case 'webui':
      return preg_match('#^https?://#', $value) === 1;
    case 'icon':
      return preg_match('/^(https?:\/\/\S+|\.\/\S+|fa-[a-z0-9-]+|[A-Za-z0-9][A-Za-z0-9._-]*)$/', $value) === 1;
    case 'overview':
    case 'category':
    case 'author':
      return trim($value) !== '';
    default:
      return false;
  }
}

/** Collapse a found description to the one line the compose editor's value
 *  writer can actually accept (PLAN_84's defect 3), and cap its length —
 *  300 characters is generous for what is meant to be a one-line summary
 *  next to a field, not the README, and matches the order of magnitude
 *  Community Applications itself caps its own index blurb at (160, doubled
 *  for a fuller Hub description). Word-boundary truncation mirrors
 *  truncateWords() in javascript/ca-convert.js so the two never disagree in
 *  spirit, though this file has no reason to share its exact code. */
function staxx_detail_collapse(string $s, int $limit = 300): string {
  $s = trim((string)preg_replace('/\s+/', ' ', $s));
  // Counted and cut in CHARACTERS, not bytes. A byte-wise substr() through
  // the middle of a multi-byte character produces text json_encode() then
  // refuses outright, which would fail the whole reply rather than shorten
  // one description — and an accented description is not unusual.
  if ($s === '' || mb_strlen($s, 'UTF-8') <= $limit) return $s;
  $cut = mb_substr($s, 0, $limit, 'UTF-8');
  $sp  = mb_strrpos($cut, ' ', 0, 'UTF-8');
  if ($sp !== false && $sp > 40) $cut = mb_substr($cut, 0, $sp, 'UTF-8');
  return rtrim($cut, " .,;:").'…';
}

/* ------------------------------------------------------------------ cache -- */

function staxx_detail_cache_path(string $image): string {
  return STAXX_DETAIL_DIR.'/'.sha1($image).'.json';
}

/** The gathered bundle for one image, or null when there is nothing cached
 *  or it has gone stale. Write-then-rename on the way in, same as every
 *  other cache under /tmp/staxx — a reader must never see half a file. */
function staxx_detail_cache_read(string $image): ?array {
  $path = staxx_detail_cache_path($image);
  $data = @json_decode((string)@file_get_contents($path), true);
  if (!is_array($data) || !isset($data['at'])) return null;
  if ((time() - (int)$data['at']) > STAXX_DETAIL_CACHE_TTL) return null;
  return $data;
}

function staxx_detail_cache_write(string $image, array $bundle): void {
  if (!is_dir(STAXX_DETAIL_DIR) && !@mkdir(STAXX_DETAIL_DIR, 0755, true)) return;
  $bundle['at'] = time();
  $path = staxx_detail_cache_path($image);
  $tmp  = $path.'.'.getmypid().'.tmp';
  if (@file_put_contents($tmp, json_encode($bundle)) === false) return;
  @rename($tmp, $path);
}

/* ------------------------------------------------------------- one image -- */

/**
 * Everything this run is willing to learn about one image: its own labels
 * (local inspect first, the registry chain only when it was never pulled at
 * all), the ports it declares, and — lazily, see staxx_detail_hub() below —
 * Docker Hub's short description and its own page address.
 *
 * $budget is shared across every image asked about in one run: 'deadline'
 * (a microtime(true) past which no further network step is attempted),
 * 'chains' (how many registry chains have been spent so far) and 'offline'
 * (the "may we ask Docker Hub" setting, read once).
 *
 * @return array{labels:array<string,string>, ports:string[], pulled:bool}
 */
function staxx_detail_image_facts(string $image, array &$budget): array {
  static $memo = [];
  if (isset($memo[$image])) return $memo[$image];

  $cached = staxx_detail_cache_read($image);
  if ($cached !== null && isset($cached['labels'])) {
    return $memo[$image] = ['labels' => $cached['labels'], 'ports' => $cached['ports'] ?? [], 'pulled' => (bool)($cached['pulled'] ?? false)];
  }

  // Local docker inspect, free and offline — see the file header for why
  // this is worth reading here even though staxx_update_labels_meta()
  // already reads three of these same labels for a narrower purpose.
  $local = staxx_local_image_config($image);
  $pulled = $local !== [];
  $labels = $pulled ? ($local['labels'] ?? []) : [];
  $ports  = $pulled ? ($local['ports'] ?? []) : [];

  if (!$pulled && !$budget['offline'] && $budget['chains'] < STAXX_DETAIL_MAX_CHAINS
      && microtime(true) < $budget['deadline']) {
    // Not pulled at all — the only case worth the four-request chain. Asked
    // through staxx_registry_ref()+staxx_registry_labels() rather than
    // staxx_registry_config(), which only ever talks to Docker Hub: a ghcr
    // or lscr image asked there would either get an unrelated Hub answer or
    // none at all (see Updates.php's own comment on this exact trap).
    $ref = staxx_registry_ref($image);
    if ($ref['repo'] !== '') {
      $budget['chains']++;
      $config = staxx_registry_labels($ref['host'], $ref['repo'], $ref['tag']);
      // staxx_registry_labels() returns the label map directly (see its own
      // return type) rather than the {ports,volumes,labels} shape
      // staxx_registry_config() uses, so there are no ports to read here.
      $labels = $config;
    }
  }

  $bundle = ['labels' => $labels, 'ports' => $ports, 'pulled' => $pulled];
  staxx_detail_cache_write($image, $bundle);
  return $memo[$image] = $bundle;
}

/** Docker Hub's own short description for an image, asked at most once per
 *  image per run and only when a caller actually needs it — the "one Hub
 *  request only if [the field] came up empty" rule lives here, simply by
 *  this being the only place staxx_hub_repo() is ever called from this
 *  file. Never staxx_hub_repo()'s 'readme' key: that is up to 256KB of
 *  markdown, and wiring it to a one-line field is exactly the mistake
 *  PLAN_84's defect 4 exists to avoid. */
function staxx_detail_hub_description(string $image, array &$budget): string {
  static $memo = [];
  if (array_key_exists($image, $memo)) return $memo[$image];

  if ($budget['offline'] || microtime(true) >= $budget['deadline']) return $memo[$image] = '';

  $cached = staxx_detail_cache_read($image);
  if ($cached !== null && isset($cached['hub_description'])) {
    return $memo[$image] = (string)$cached['hub_description'];
  }

  $repo = staxx_hub_repo($image);
  $desc = (string)($repo['description'] ?? '');

  // Folded into the same on-disk bundle staxx_detail_image_facts() writes,
  // so a second call within the TTL costs nothing even after a page reload.
  // `hub_known` records the separate fact that Docker Hub answered about
  // this repository AT ALL — which is not the same as it having a
  // description, and is the only evidence we ever get that the repository
  // is really there. staxx_detail_readme() below depends on it.
  $bundle = staxx_detail_cache_read($image) ?? [];
  $bundle['hub_known']       = $repo !== [];
  $bundle['hub_description'] = $desc;
  $bundle += staxx_detail_image_facts($image, $budget);
  staxx_detail_cache_write($image, $bundle);

  return $memo[$image] = $desc;
}

/**
 * Has Docker Hub actually confirmed this repository exists? Only ever true
 * once staxx_detail_hub_description() has asked and been answered, so it is
 * false when lookups are switched off, when the budget ran out, and when the
 * repository simply is not there.
 *
 * Its one caller is the documentation link's last resort, which builds a Hub
 * page address out of the image's own name. That address is a guess about
 * where a page lives, and without this it was also a guess about whether the
 * page exists at all — so a fictional or private image was handed a
 * confident link to a 404. A dead documentation link is worse than none:
 * nothing on the page says it was never checked.
 */
function staxx_detail_hub_known(string $image, array &$budget): bool {
  staxx_detail_hub_description($image, $budget);   // memoised; asks at most once
  $cached = staxx_detail_cache_read($image);
  return (bool)($cached['hub_known'] ?? false);
}

/** The address of an image's own Docker Hub page, pure — no network. '' for
 *  anything not hosted on Docker Hub itself. */
function staxx_detail_hub_url(string $image): string {
  if (staxx_links_image_host($image) !== '') return '';   // hosted elsewhere
  $repo = staxx_hub_repo_path($image);
  if ($repo === '') return '';
  return strpos($repo, 'library/') === 0
    ? 'https://hub.docker.com/_/'.substr($repo, strlen('library/')).'/'
    : 'https://hub.docker.com/r/'.$repo.'/';
}

/* ---------------------------------------------------------------- template -- */

/**
 * A light, targeted scan of the local Unraid templates for the handful of
 * presentation fields this file cares about, joined on repository path —
 * the same join staxx_watch_template_claims() uses, and deliberately not
 * staxx_import_templates(), which also loads every container and folder
 * view neither of them needs. Widened from Watch.php's Repository+Project
 * pair to the fields this feature's cascades ask for.
 *
 * @return array{icon:string, overview:string, category:string, author:string, webui:string}|null
 */
// $dir defaults to the real template folder, and every caller in this plugin
// uses that default — only a test passes another one, the same arrangement
// staxx_watch_template_claims() already uses and for the same reason: without
// it the whole template half of these cascades can only be exercised against
// whatever templates happen to be on the live server.
function staxx_detail_template_match(string $image, string $dir = STAXX_IMPORT_TEMPLATES_DIR): ?array {
  static $cache = [];
  $repo = strtolower(staxx_links_repo_path($image));
  if ($repo === '') return null;
  // Keyed on the folder as well as the image: a test pointing this at its own
  // fixtures must not be served an answer computed from the real folder.
  $key = $dir."|".$repo;
  if (array_key_exists($key, $cache)) return $cache[$key];

  $found = null;
  foreach ((array)@scandir($dir) as $file) {
    // *.xml only — the folder also holds a .bak of whatever was last
    // overwritten, and it would parse just as happily as a real template.
    if (!preg_match('/\.xml$/i', $file)) continue;
    $path = $dir.'/'.$file;
    if (!is_file($path)) continue;

    $xml = @simplexml_load_file($path);
    if ($xml === false) continue;
    if (strtolower(staxx_links_repo_path((string)($xml->Repository ?? ''))) !== $repo) continue;

    $found = [
      'icon'     => trim((string)($xml->Icon ?? '')),
      'overview' => trim((string)($xml->Overview ?? '')),
      'category' => trim((string)($xml->Category ?? '')),
      'author'   => trim((string)($xml->Author ?? '')),
      'webui'    => trim((string)($xml->WebUI ?? '')),
    ];
    break;   // first entry for a repeated repository wins, same rule as elsewhere
  }

  return $cache[$key] = $found;
}

/* ------------------------------------------------------------------- category -- */

// Kept in step with normaliseCategory() in javascript/ca-convert.js — the
// SAME rule, written twice because one copy runs in the browser and one
// here, so this list and the split-on-'-' logic below must never drift from
// its copy there. tests/server/updateeconomy.php-style drift guards belong
// in the new server-only suite for this plan, comparing the two lists.
const STAXX_DETAIL_CATEGORY_HEADS = [
  'AI', 'Backup', 'Cloud', 'Crypto', 'Downloaders', 'Drivers',
  'GameServers', 'HomeAutomation', 'MediaApp', 'MediaServer', 'Network', 'Plugins',
  'Productivity', 'Security', 'Tools', 'Other',
];

/** The same "Head:Rest" split ca-convert.js's normaliseCategory() applies,
 *  taking only the first category when several are space-separated (a
 *  template packs them that way; the catalogue's CategoryList is handed in
 *  pre-split by the caller). */
function staxx_detail_normalise_category(string $raw): string {
  $first = preg_split('/\s+/', trim($raw))[0] ?? '';
  if ($first === '') return '';
  $dash = strpos($first, '-');
  if ($dash === false) return $first;
  $head = substr($first, 0, $dash);
  return in_array($head, STAXX_DETAIL_CATEGORY_HEADS, true) ? $head.':'.substr($first, $dash + 1) : $first;
}

/* --------------------------------------------------------------- one answer -- */

/**
 * A picture the chooser can actually show for one icon value, resolved the
 * same way the table resolves the icons it draws — never by the browser
 * guessing a public address for itself. The chooser puts the stored icon
 * beside the found one as two real pictures (PLAN_84's 2026-08-27 revision),
 * and that comparison is the whole reason the icon is in the chooser at all,
 * so a guessed URL that silently fails to load would quietly remove the one
 * safeguard the icon has.
 *
 * Fetches when it must and the setting allows it, exactly as the table's own
 * sweep does; a picture that cannot be had comes back as '' and the chooser
 * says so in words rather than showing a broken image.
 *
 * @return array{fa:string, url:string}
 */
function staxx_detail_icon_preview(string $icon, string $dir, string $image): array {
  if (trim($icon) === '') return ['fa' => '', 'url' => ''];
  $r = staxx_icon_resolve($icon, $dir, $image);
  if ($r['fa'] !== '') return ['fa' => $r['fa'], 'url' => ''];
  if ($r['url'] === '' && $r['ref'] !== '' && staxx_icon_fetching()) {
    $r['url'] = staxx_icon_fetch($r['ref'], $r['remote']);
  }
  return ['fa' => '', 'url' => (string)$r['url']];
}

/** One answer, or null when there is nothing to offer — either no source
 *  answered, or the only source that did equals what the file already
 *  holds (see the file header's suppression rule). */
function staxx_detail_answer(string $value, string $from, string $tier, string $why, string $current): ?array {
  if ($value === '' || $value === trim($current)) return null;
  return ['value' => $value, 'from' => $from, 'tier' => $tier, 'why' => $why];
}

/* -------------------------------------------------------------- per field -- */

/** project and support together — they share one cascade and one existing
 *  function that must not be reimplemented. */
function staxx_detail_project_support(
  string $image, array $stackX, array $serviceX, array &$budget
): array {
  $currentProject = staxx_links_url((string)($serviceX['project'] ?? $stackX['project'] ?? ''));
  $currentSupport = staxx_links_url((string)($serviceX['support'] ?? $stackX['support'] ?? ''));

  // staxx_project_links() tries, in order: 1) the stored value itself, 2) the
  // image's source label (from the update-check cache, which may be empty
  // for an image never checked), 3) the catalogue, 4) a derived ghcr.io
  // address, 5) an official base image's Hub page. Reused whole, not
  // reimplemented, per PLAN_84 Phase 2 — but asked with the stored blocks
  // deliberately EMPTIED.
  //
  // Why: that function answers "what is this stack's project link?", and a
  // stored value is rightly the winner of that question, reported as
  // from 'stored'. This file is asking a different question — "what would
  // this server say if the file said nothing?" — because that is the only
  // answer worth putting beside the stored one in the chooser. Asked the
  // ordinary way, every stack that already holds a project link reports
  // 'stored' and no conflict could ever be offered, which is precisely the
  // case the chooser exists for. The stored values are read directly above
  // and are what the found answer is compared against.
  $pl = staxx_project_links($image, [], []);

  $tierOf = ['label' => 'stated', 'catalog' => 'claimed', 'derived' => 'guess', 'registry' => 'guess'];
  $whyOf = [
    'label'    => "This is the image's own source-code label — its publisher wrote this in.",
    'catalog'  => 'The Community Applications catalogue lists this for the image.',
    'derived'  => "Guessed from the image's ghcr.io address, which is usually its GitHub repository.",
    'registry' => "This is one of Docker Hub's own official base images, so this is its page there.",
  ];

  $project = null;
  if ($pl['project'] !== '' && isset($tierOf[$pl['from']])) {
    $project = staxx_detail_answer($pl['project'], $pl['from'], $tierOf[$pl['from']], $whyOf[$pl['from']], $currentProject);
  }
  // support can only ever have come from 'catalog' — label/derived/registry
  // never set it, and 'stored' is out of reach now the blocks are emptied,
  // see staxx_project_links()'s own source.
  $support = null;
  if ($pl['support'] !== '') {
    $support = staxx_detail_answer($pl['support'], 'catalog', 'claimed', $whyOf['catalog'], $currentSupport);
  }

  // Nothing from the cascade above — try the raw label directly (closes the
  // "pulled but never update-checked" gap staxx_project_links() cannot see),
  // then a claimed template repository that survives the ownership guard.
  // Both run whether or not the file already holds a project: a stored value
  // is what the found one is offered AGAINST, never a reason not to look.
  // staxx_detail_answer() drops anything equal to what is already there.
  if ($project === null) {
    $facts = staxx_detail_image_facts($image, $budget);
    $source = staxx_links_url((string)($facts['labels']['org.opencontainers.image.source'] ?? ''));
    if ($source !== '') {
      $project = staxx_detail_answer($source, 'label', 'stated', $whyOf['label'], $currentProject);
    }
  }
  if ($project === null) {
    [$home, $homeFrom] = staxx_watch_claimed_home($image);
    if ($home !== '') {
      $project = staxx_detail_answer(
        $home, $homeFrom, 'claimed',
        $homeFrom === 'template'
          ? 'A local Unraid template names this as the project home, and it resembles this image closely enough to trust.'
          : $whyOf['catalog'],
        $currentProject
      );
    }
  }

  return ['project' => $project, 'support' => $support, 'currentProject' => $currentProject, 'currentSupport' => $currentSupport];
}

function staxx_detail_icon(
  string $image, array $stackX, string $leadService, string $stackLeaf, ?array $template
): ?array {
  $current = trim((string)($stackX['icon'] ?? ''));

  $repo = strtolower(staxx_links_repo_path($image));
  $catIcon = '';
  if ($repo !== '') {
    $ordinal = staxx_links_ca_map()[$repo] ?? null;
    if ($ordinal !== null) {
      $app = staxx_ca_app($ordinal);
      $catIcon = is_array($app) ? trim((string)($app['Icon'] ?? '')) : '';
    }
  }
  if ($catIcon !== '' && staxx_detail_schema_ok('icon', $catIcon)) {
    return staxx_detail_answer($catIcon, 'catalog', 'claimed',
      'The Community Applications catalogue lists this icon for the image.', $current);
  }

  // Template icons are only ever taken when they are a real URL — a
  // template naming an absolute local path on its own author's server is
  // meaningless here, because the compose file this writes into must run
  // unmodified anywhere (rule 1). This is narrower than the schema itself,
  // which also allows a bare selfh.st name or a relative ./path from a
  // template, because a template's own path convention cannot be trusted to
  // mean the same thing once it is somebody else's stack.
  $tplIcon = trim((string)($template['icon'] ?? ''));
  if ($tplIcon !== '' && preg_match('#^https?://#', $tplIcon) && staxx_detail_schema_ok('icon', $tplIcon)) {
    return staxx_detail_answer($tplIcon, 'template', 'claimed',
      'A local Unraid template names this as the icon.', $current);
  }

  // The unique-hit matcher — a guess, and the one answer here that is
  // written with a trailing comment saying so (see 'auto_comment' below).
  // Icons.php's own header explains why a wrong icon is worse than none.
  $matched = staxx_icon_match($image, $leadService, $stackLeaf);
  if ($matched !== '' && staxx_detail_schema_ok('icon', $matched)) {
    $answer = staxx_detail_answer($matched, 'matcher', 'guess',
      "Matched automatically from the image's name — exactly one icon in StaXX's collection fits.",
      $current);
    if ($answer !== null) $answer['auto_comment'] = true;
    return $answer;
  }

  return null;
}

function staxx_detail_overview(
  string $image, array $stackX, ?array $template, array &$budget
): ?array {
  $current = trim((string)($stackX['overview'] ?? ''));

  $facts = staxx_detail_image_facts($image, $budget);
  $label = staxx_detail_collapse((string)($facts['labels']['org.opencontainers.image.description'] ?? ''));
  if (staxx_detail_schema_ok('overview', $label)) {
    return staxx_detail_answer($label, 'label', 'stated',
      "This is the image's own description label — its publisher wrote this in.", $current);
  }

  $repo = strtolower(staxx_links_repo_path($image));
  if ($repo !== '') {
    $ordinal = staxx_links_ca_map()[$repo] ?? null;
    if ($ordinal !== null) {
      $app = staxx_ca_app($ordinal);
      $catOverview = staxx_detail_collapse(is_array($app) ? (string)($app['Overview'] ?? '') : '');
      if (staxx_detail_schema_ok('overview', $catOverview)) {
        return staxx_detail_answer($catOverview, 'catalog', 'claimed',
          'The Community Applications catalogue describes the image this way.', $current);
      }
    }
  }

  $tplOverview = staxx_detail_collapse((string)($template['overview'] ?? ''));
  if (staxx_detail_schema_ok('overview', $tplOverview)) {
    return staxx_detail_answer($tplOverview, 'template', 'claimed',
      'A local Unraid template describes the image this way.', $current);
  }

  // Never staxx_hub_repo()'s 'readme' key — see the file header and PLAN_84
  // defect 4. Only the short description, and only after everything above
  // came up empty, so the one Hub request this can spend is not wasted.
  $hub = staxx_detail_collapse(staxx_detail_hub_description($image, $budget));
  if (staxx_detail_schema_ok('overview', $hub)) {
    return staxx_detail_answer($hub, 'hub', 'stated',
      "This is Docker Hub's own short description for the image.", $current);
  }

  return null;
}

function staxx_detail_category(string $image, array $stackX, ?array $template): ?array {
  $current = trim((string)($stackX['category'] ?? ''));

  $repo = strtolower(staxx_links_repo_path($image));
  if ($repo !== '') {
    $ordinal = staxx_links_ca_map()[$repo] ?? null;
    if ($ordinal !== null) {
      $app = staxx_ca_app($ordinal);
      if (is_array($app)) {
        $list = is_array($app['CategoryList'] ?? null) ? $app['CategoryList'] : [];
        $raw  = $list !== [] ? (string)$list[0] : (string)($app['Category'] ?? '');
        $cat  = staxx_detail_normalise_category($raw);
        if (staxx_detail_schema_ok('category', $cat)) {
          return staxx_detail_answer($cat, 'catalog', 'claimed',
            'The Community Applications catalogue files the image under this category.', $current);
        }
      }
    }
  }

  $tplCat = staxx_detail_normalise_category((string)($template['category'] ?? ''));
  if (staxx_detail_schema_ok('category', $tplCat)) {
    return staxx_detail_answer($tplCat, 'template', 'claimed',
      'A local Unraid template files the image under this category.', $current);
  }

  // No third source — a Docker Hub image has no Unraid category, and
  // inventing one would be exactly the fuzzy guessing Icons.php's own
  // header refuses to do for icons. See PLAN_84 Phase 2.
  return null;
}

function staxx_detail_author(
  string $image, array $stackX, ?array $template, array &$budget
): ?array {
  $current = trim((string)($stackX['author'] ?? ''));

  $facts = staxx_detail_image_facts($image, $budget);
  $labelAuthor = trim((string)(
    $facts['labels']['org.opencontainers.image.authors'] ?? $facts['labels']['org.opencontainers.image.vendor'] ?? ''
  ));
  if (staxx_detail_schema_ok('author', $labelAuthor)) {
    return staxx_detail_answer($labelAuthor, 'label', 'stated',
      "This is the image's own author/vendor label — its publisher wrote this in.", $current);
  }

  $repo = strtolower(staxx_links_repo_path($image));
  if ($repo !== '') {
    $ordinal = staxx_links_ca_map()[$repo] ?? null;
    if ($ordinal !== null) {
      $app = staxx_ca_app($ordinal);
      // The catalogue feed has no dedicated author field for most entries;
      // 'Repo' is the closest it carries (the maintaining account), so it is
      // tried defensively rather than assumed present.
      $catAuthor = trim(is_array($app) ? (string)($app['Author'] ?? $app['Repo'] ?? '') : '');
      if (staxx_detail_schema_ok('author', $catAuthor)) {
        return staxx_detail_answer($catAuthor, 'catalog', 'claimed',
          'The Community Applications catalogue names this as the maintainer.', $current);
      }
    }
  }

  $tplAuthor = trim((string)($template['author'] ?? ''));
  if (staxx_detail_schema_ok('author', $tplAuthor)) {
    return staxx_detail_answer($tplAuthor, 'template', 'claimed',
      'A local Unraid template names this as the author.', $current);
  }

  $path = staxx_links_repo_path($image);
  $segments = $path !== '' ? explode('/', $path) : [];
  $namespace = count($segments) > 1 ? $segments[0] : '';
  if ($namespace !== '' && $namespace !== 'library' && staxx_detail_schema_ok('author', $namespace)) {
    return staxx_detail_answer($namespace, 'registry', 'guess',
      "This is who PUBLISHES the image on its registry — not necessarily who wrote the software.",
      $current);
  }

  return null;
}

function staxx_detail_readme(
  string $image, array $stackX, ?array $template, array &$budget
): ?array {
  $current = trim((string)($stackX['readme'] ?? ''));

  $facts = staxx_detail_image_facts($image, $budget);
  $label = staxx_links_url((string)($facts['labels']['org.opencontainers.image.documentation'] ?? ''));
  if ($label !== '') {
    return staxx_detail_answer($label, 'label', 'stated',
      "This is the image's own documentation label — its publisher wrote this in.", $current);
  }

  // The catalogue feed and Unraid templates carry no dedicated documentation
  // field in practice — Project/Support already cover "home page" and
  // "where to ask for help" — so these two steps exist for a well-formed
  // future entry rather than anything seen in today's feed, and are
  // ordinarily no-ops.
  $repo = strtolower(staxx_links_repo_path($image));
  if ($repo !== '') {
    $ordinal = staxx_links_ca_map()[$repo] ?? null;
    if ($ordinal !== null) {
      $app = staxx_ca_app($ordinal);
      $catReadme = staxx_links_url(is_array($app) ? (string)($app['Readme'] ?? '') : '');
      if ($catReadme !== '') {
        return staxx_detail_answer($catReadme, 'catalog', 'claimed',
          'The Community Applications catalogue links to this as documentation.', $current);
      }
    }
  }
  $tplReadme = staxx_links_url((string)($template['readme'] ?? ''));
  if ($tplReadme !== '') {
    return staxx_detail_answer($tplReadme, 'template', 'claimed',
      'A local Unraid template links to this as documentation.', $current);
  }

  // Last resort, and only for a repository Hub has actually confirmed — see
  // staxx_detail_hub_known(). Building the address is free; vouching for it
  // is not.
  $hubUrl = staxx_detail_hub_url($image);
  if ($hubUrl !== '' && staxx_detail_hub_known($image, $budget)) {
    return staxx_detail_answer($hubUrl, 'hub', 'guess',
      "Docker Hub hosts this image; this is the address of its own page there.", $current);
  }

  return null;
}


/** The image's own declared port, but only when it names exactly one — the
 *  same unique-hit rule the icon matcher applies, for the same reason: a
 *  coin toss between several is not a suggestion, it is a guess dressed up
 *  as one. */
function staxx_detail_unique_declared_port(array $facts): string {
  $declared = [];
  foreach ((array)($facts['ports'] ?? []) as $p) {
    $bare = explode('/', (string)$p)[0];
    if ($bare !== '' && !in_array($bare, $declared, true)) $declared[] = $bare;
  }
  return count($declared) === 1 ? $declared[0] : '';
}

/**
 * A running container is worth an actual probe — never suppressing the
 * suggestion when it fails (a stopped container proves nothing either way,
 * and a service that simply does not answer HTTP yet is still worth
 * offering), only raising confidence in the sentence when it succeeds.
 */
function staxx_detail_webui_probe(
  string $why, array $service, string $candidate, array &$budget, string $rel, string $serviceName
): string {
  if (microtime(true) >= $budget['deadline']) return $why;

  $s = ['name' => $rel, 'file' => '', 'leaf' => staxx_path_leaf($rel), 'project' => ''];
  $container = null;
  foreach (staxx_stack_containers($s) as $c) {
    $matched = $c['service'] !== '' ? $c['service'] : $c['name'];
    if ($matched === $serviceName) { $container = $c; break; }
  }
  if ($container === null || $container['state'] !== 'running') return $why;

  $net = staxx_container_net()[$container['id']] ?? [];
  $probeService = $service; $probeService['x']['webui'] = $candidate;
  $resolved = staxx_webui_url(
    $probeService, staxx_host_ip(),
    (string)($net['addresses'][0]['ip'] ?? ''), (string)($net['mode'] ?? ''), (string)($net['driver'] ?? '')
  );
  if ($resolved !== '' && staxx_webui_try($resolved)) {
    $why .= ' A quick check just now found something answering there.';
  }
  return $why;
}

/**
 * The interesting one — see the file header. Scheme and path come from a
 * local template's own WebUI field when one exists (nothing else on the box
 * can know a path such as /admin), but the port is always taken from the
 * compose file's own mapping, because measured across 85 real templates the
 * token's own number named the host port 10 times, the container port 15,
 * and neither 3 times. The template's WebUI is only trusted when it is
 * written in Unraid's own `scheme://[IP]...` shape — anything else names an
 * address this file cannot safely reuse for a different container.
 *
 * With no usable template the plan's own probe rule ("for a running
 * service, actually probe the candidate") only makes sense if a candidate
 * can exist independently of one — so a bare `http://[IP]:<port>/` is still
 * offered, but only while the port itself is unambiguous: one published
 * port, or failing that exactly one port the image itself declares. Two or
 * more published ports is refused outright, the same coin-toss the icon
 * matcher and the port-fallback above both already refuse.
 */
function staxx_detail_webui(
  array $service, ?array $template, array &$budget, array $facts,
  string $rel, string $serviceName, string $current
): ?array {
  $kind = staxx_service_net_kind($service);
  $firstPort = $service['firstPort'] ?? [];
  $port = $kind === 'bridge' ? (string)($firstPort['published'] ?? '') : (string)($firstPort['target'] ?? '');

  // The template path — unchanged from before this correction, and still
  // the better answer whenever it is available, because it is the only
  // source that can know a path such as /admin.
  if ($template !== null) {
    $tplWebui = trim((string)($template['webui'] ?? ''));
    if (preg_match('#^(https?)://\[IP\](?::[^/]*)?(/.*)?$#', $tplWebui, $m)) {
      $scheme = $m[1];
      $path   = $m[2] ?? '';
      $tplPort = $port;
      if ($tplPort === '') $tplPort = staxx_detail_unique_declared_port($facts);

      if ($tplPort !== '' && ctype_digit($tplPort)) {
        $candidate = $scheme.'://[IP]:'.$tplPort.$path;
        if (staxx_detail_schema_ok('webui', $candidate)) {
          $why = 'The scheme and path come from a local Unraid template; the port is this stack\'s own published port.';
          $why = staxx_detail_webui_probe($why, $service, $candidate, $budget, $rel, $serviceName);
          return staxx_detail_answer($candidate, 'ports', 'guess', $why, $current);
        }
      }
    }
  }

  // No usable template — the plan's own webui bullet still wants a probe of
  // "a port is published" turning into "something answers there", which
  // only makes sense if a candidate can exist without one. The scheme and
  // path are unknown here, so the address itself is a guess and the
  // sentence says so plainly — but a plain "/" is still worth offering when
  // the port is unambiguous. More than one published port is exactly the
  // coin toss the unique-hit rule refuses, so that case offers nothing.
  // How many ports this service publishes, taken from the ONE reader that
  // already walks compose's own resolved output for them — not a second
  // pass of our own over the raw file, which would be a cruder reader
  // disagreeing with the real one the day a file is written unusually.
  $publishedCount = (int)($service['firstPort']['count'] ?? 0);
  if ($publishedCount > 1) return null;

  $bareFrom = 'ports';
  $barePort = $port;
  if ($barePort === '') {
    $barePort = staxx_detail_unique_declared_port($facts);
    $bareFrom = 'image-port';
  }
  if ($barePort === '' || !ctype_digit($barePort)) return null;

  $candidate = 'http://[IP]:'.$barePort.'/';
  if (!staxx_detail_schema_ok('webui', $candidate)) return null;

  $why = 'This stack publishes one port, so this is the likely address, but nothing here says what page it opens on.';
  $why = staxx_detail_webui_probe($why, $service, $candidate, $budget, $rel, $serviceName);

  return staxx_detail_answer($candidate, $bareFrom, 'guess', $why, $current);
}

/* --------------------------------------------------------------- the entry -- */

/**
 * What can this server find out about one stack's presentation details? See
 * the file header for the shape of an answer and the rules that protect it.
 *
 * @return array{ok:bool, error?:string, image?:string, lead_service?:string,
 *   lead_note?:string, budget_exhausted?:bool,
 *   stack?:array<string,array{current:string,answer:?array,skipped:?string}>,
 *   services?:array<string,array<string,array{current:string,answer:?array,skipped:?string}>>}
 */
function staxx_detail_discover(string $stackName): array {
  if (!staxx_valid_path($stackName)) return ['ok' => false, 'error' => 'That stack name is not valid.'];

  $dir  = staxx_stack_dir($stackName);
  $file = staxx_find_compose_file($dir);
  if ($file === '') return ['ok' => false, 'error' => 'No compose file was found for that stack.'];

  $meta = staxx_compose_meta($file);
  if (!$meta['ok']) return ['ok' => false, 'error' => 'This stack\'s compose file could not be read.'];

  $services = $meta['services'];
  if ($services === []) return ['ok' => false, 'error' => 'This stack declares no services.'];

  // The lead service: the one whose name matches the stack, else the only
  // one, else none — and a note, rather than a guess. See PLAN_84 Phase 2.
  $leaf = staxx_path_leaf($stackName);
  $lead = isset($services[$leaf]) ? $leaf : (count($services) === 1 ? array_key_first($services) : '');
  $leadNote = $lead === '' ? 'This stack has more than one service and none is named after the stack, so stack-level details have no single image to come from.' : '';

  $budget = [
    'deadline' => microtime(true) + STAXX_DETAIL_BUDGET_SECONDS,
    'chains'   => 0,
    'offline'  => (staxx_cfg()['IMAGE_LOOKUP'] ?? 'true') === 'false',
  ];

  $stack = [];
  if ($lead !== '') {
    $image = (string)$services[$lead]['image'];
    $stackX = $meta['x'];
    $serviceX = $services[$lead]['x'];
    $template = staxx_detail_template_match($image);

    $ps = staxx_detail_project_support($image, $stackX, $serviceX, $budget);
    $stack['project']  = ['current' => $ps['currentProject'], 'answer' => $ps['project'],  'skipped' => null];
    $stack['support']  = ['current' => $ps['currentSupport'], 'answer' => $ps['support'],  'skipped' => null];

    $iconAnswer = staxx_detail_icon($image, $stackX, $lead, $leaf, $template);
    $currentIcon = trim((string)($stackX['icon'] ?? ''));
    $stack['icon'] = [
      'current' => $currentIcon,
      'answer'  => $iconAnswer,
      'skipped' => null,
      // Both sides as real pictures, resolved here rather than in the browser.
      'preview'        => staxx_detail_icon_preview($currentIcon, $dir, $image),
      'answer_preview' => $iconAnswer === null
        ? ['fa' => '', 'url' => '']
        : staxx_detail_icon_preview((string)$iconAnswer['value'], $dir, $image),
    ];

    $overviewCurrent = trim((string)($stackX['overview'] ?? ''));
    $overviewAnswer  = staxx_detail_overview($image, $stackX, $template, $budget);
    $overviewSkipped = null;
    // The one explicit "found but could not be offered" case this file
    // records — see the file header: everything else fails silently and the
    // cascade just moves on, but a description that was found and then
    // could not be collapsed/validated is worth telling the person about,
    // per the 2026-08-27 revision's chooser summary.
    if ($overviewAnswer === null) {
      $rawLabel = staxx_detail_image_facts($image, $budget)['labels']['org.opencontainers.image.description'] ?? '';
      if (trim((string)$rawLabel) !== '' && staxx_detail_collapse((string)$rawLabel) === '') {
        $overviewSkipped = 'A description label was found but could not be turned into something writable.';
      }
    }
    $stack['overview'] = ['current' => $overviewCurrent, 'answer' => $overviewAnswer, 'skipped' => $overviewSkipped];

    $stack['category'] = ['current' => trim((string)($stackX['category'] ?? '')),
                           'answer' => staxx_detail_category($image, $stackX, $template), 'skipped' => null];
    $stack['author'] = ['current' => trim((string)($stackX['author'] ?? '')),
                         'answer' => staxx_detail_author($image, $stackX, $template, $budget), 'skipped' => null];
    $stack['readme'] = ['current' => trim((string)($stackX['readme'] ?? '')),
                         'answer' => staxx_detail_readme($image, $stackX, $template, $budget), 'skipped' => null];
  }


  $svcOut = [];
  foreach ($services as $name => $service) {
    $image = (string)$service['image'];
    $template = staxx_detail_template_match($image);
    $current = trim((string)($service['x']['webui'] ?? ''));
    $facts = staxx_detail_image_facts($image, $budget);
    $answer = staxx_detail_webui($service, $template, $budget, $facts, $stackName, $name, $current);
    $svcOut[$name] = ['webui' => ['current' => $current, 'answer' => $answer, 'skipped' => null]];
  }

  // THE ONE HARD RULE, applied here rather than trusted to every cascade:
  // no value the schema would reject may leave this function. Each cascade
  // already checks its own candidates, but a rule enforced in fourteen
  // places is a rule one new source can be added without — and the whole
  // safety argument for writing any of this into somebody's compose file
  // rests on it holding without exception. A value failing here is dropped
  // as though the source never answered, exactly as it would have been
  // upstream.
  foreach ($stack as $field => $entry) {
    if (is_array($entry['answer'] ?? null) &&
        !staxx_detail_schema_ok($field, (string)$entry['answer']['value'])) {
      $stack[$field]['answer'] = null;
    }
  }
  foreach ($svcOut as $name => $entry) {
    if (is_array($entry['webui']['answer'] ?? null) &&
        !staxx_detail_schema_ok('webui', (string)$entry['webui']['answer']['value'])) {
      $svcOut[$name]['webui']['answer'] = null;
    }
  }

  return [
    'ok'               => true,
    'image'            => $lead !== '' ? (string)$services[$lead]['image'] : '',
    'lead_service'     => $lead,
    'lead_note'        => $leadNote,
    'budget_exhausted' => microtime(true) >= $budget['deadline'],
    'stack'            => $stack,
    'services'         => $svcOut,
  ];
}
?>
