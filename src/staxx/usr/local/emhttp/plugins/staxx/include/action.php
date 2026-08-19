<?PHP
/* StaXX — the endpoint the Stacks page talks to.
 * Copyright 2026, StaXX contributors.
 *
 * Answers JSON, always. A PHP notice printed before the JSON would make the
 * reply unparseable and the page would report nothing useful, so output is
 * buffered and anything stray is handed back inside the reply where it can be
 * seen instead of silently breaking things.
 *
 * Every request must carry Unraid's CSRF token, which is what stops another
 * website in another browser tab from driving this page on your behalf. Every
 * action is named explicitly below — there is no path from user input to a
 * command that is not on this list.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
// Errors belong in the reply and the syslog, never printed into the JSON.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ob_start();

// A fatal error kills the script before any reply is sent, which the page sees
// as an empty response. Catch it on the way out and say what happened.
register_shutdown_function(function () {
  $fatal = error_get_last();
  if (!$fatal || !in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    return;
  }
  while (ob_get_level() > 0) ob_end_clean();
  if (!headers_sent()) {
    http_response_code(500);
    header('Content-Type: application/json');
  }
  echo json_encode([
    'ok'    => false,
    'error' => 'PHP error: '.$fatal['message']
             . ' (in '.basename($fatal['file']).' line '.$fatal['line'].')',
  ]);
});

require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Folders.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Autostart.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Stats.php';
require_once '/usr/local/emhttp/plugins/staxx/include/StacksTable.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Devices.php';
require_once '/usr/local/emhttp/plugins/staxx/include/CA.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Settings.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Import.php';

function staxx_reply(array $payload, int $status = 200): void {
  $stray = '';
  while (ob_get_level() > 0) $stray .= (string)ob_get_clean();
  $stray = trim($stray);
  if ($stray !== '') $payload['stray'] = $stray;

  http_response_code($status);
  header('Content-Type: application/json');
  header('Cache-Control: no-store');
  echo json_encode($payload);
  exit;
}

/* ------------------------------------------------------------------ auth -- */

/*
 * The CSRF token is checked by Unraid, not here.
 *
 * /etc/php.ini sets auto_prepend_file to webGui/include/local_prepend.php,
 * which runs before every PHP script on the system. On any POST it requires a
 * valid csrf_token, kills the request outright if it is missing or wrong, and
 * then does this:
 *
 *     unset($_POST['csrf_token']);
 *
 * So by the time this file runs, a POST has already been verified and the
 * token is gone. Checking it again here cannot succeed — the field no longer
 * exists — which is a bug this plugin shipped with and which made every
 * request look rejected.
 *
 * The one thing that check DOES leave to us: Unraid only guards POST. A GET
 * request never passes through that gate at all, so accepting parameters from
 * the query string would hand anyone a way around it. Everything below is
 * POST-only for that reason.
 */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  staxx_reply([
    'ok'    => false,
    'error' => 'This endpoint only accepts POST. Unraid\'s security check does not '
             . 'cover GET requests, so allowing them would bypass it.',
  ], 405);
}

/* --------------------------------------------------------------- actions -- */

$action = (string)($_POST['action'] ?? '');
$name   = (string)($_POST['name']   ?? '');
$error  = '';

switch ($action) {

  // ---- read one stack's compose file, for the editor ----
  case 'read':
    $body = staxx_read_stack($name, $error);
    if ($body === null) staxx_reply(['ok' => false, 'error' => $error]);
    staxx_reply(['ok' => true, 'name' => $name, 'body' => $body]);

  // ---- create a new stack, or overwrite an existing one ----
  case 'save':
    $body   = (string)($_POST['body'] ?? '');
    $isNew  = ($_POST['new'] ?? '') === '1';
    $exists = is_dir(staxx_stack_dir($name));

    if ($isNew && $exists) {
      staxx_reply([
        'ok'    => false,
        'error' => 'A stack called "'.$name.'" already exists. Pick another name, '
                 . 'or edit the existing one.',
      ]);
    }
    if (!staxx_save_stack($name, $body, $error)) {
      staxx_reply(['ok' => false, 'error' => $error]);
    }
    // Report back what actually landed on disk, so "saved" is a fact rather
    // than an assumption.
    $written = staxx_find_compose_file(staxx_stack_dir($name));
    staxx_reply([
      'ok'      => true,
      'name'    => $name,
      'file'    => $written,
      'bytes'   => $written !== '' ? (int)@filesize($written) : 0,
    ]);

  /* ---- check a compose file without saving it ----
   *
   * Same function the save path itself runs, just earlier and without
   * writing anything. `ok` means the check ran at all; `valid` means compose
   * accepted the text — the two are not the same thing, and the caller
   * relies on that: a check that could not run must not read as a file that
   * failed. An unrecognised or missing name is not an error here, since
   * typing is exactly when the name may not exist yet or not resolve — the
   * check still runs, just without --project-directory.
   */
  case 'check':
    $body = (string)($_POST['body'] ?? '');
    $dir  = staxx_valid_path($name) ? staxx_stack_dir($name) : '';
    $ok   = staxx_validate_compose($body, $error, $dir, $warnings);
    staxx_reply(['ok' => true, 'valid' => $ok, 'error' => $error, 'warnings' => $warnings]);

  /* ---- delete a stack ----
   *
   * The confirmation is asked for here, not inside staxx_delete_stack().
   * What is about to be destroyed has to reach the user BEFORE anything is
   * removed, and a function that both asks and acts cannot do that — so this
   * case looks first, replies with the list if the folder holds more than
   * the compose file, and only calls the delete once the browser sends the
   * same request back with confirm=1.
   */
  case 'delete':
    $confirmed = ($_POST['confirm'] ?? '') === '1';
    if (!$confirmed) {
      $extras = staxx_stack_extras($name, $error);
      if ($extras === null) staxx_reply(['ok' => false, 'error' => $error]);
      if ($extras) staxx_reply(['ok' => false, 'needsConfirm' => true, 'entries' => $extras]);
    }
    if (!staxx_delete_stack($name, $error, $confirmed)) {
      staxx_reply(['ok' => false, 'error' => $error]);
    }

    // Nothing left on disk to keep a place for — drop the stack from the
    // stored order and its own service/wait entries with it. Same layering
    // reason as stack-rename above: this has to happen here, not inside
    // staxx_delete_stack().
    $folder = staxx_path_folder($name);
    $data   = staxx_folders_load();
    $start  = $data['start'];
    $start['stacks'][$folder] = staxx_start_list_remove($start['stacks'][$folder] ?? [], staxx_path_leaf($name));
    staxx_start_drop($start, $name);
    $data['start'] = $start;
    staxx_folders_save($data);

    staxx_reply(['ok' => true]);

  /* ------------------------------------------------------ companion files --
   *
   * A stack folder may hold more than its compose file now — a .env, a
   * certificate, a config snippet — and these five actions are how the
   * editor reaches them: list what's there, read one, save one, delete one,
   * rename one. Every helper below confines itself to the one stack's own
   * folder; see staxx_stack_file() in Stacks.php.
   */

  case 'files':
    $files = staxx_list_files($name, $error);
    if ($files === null) staxx_reply(['ok' => false, 'error' => $error]);
    staxx_reply(['ok' => true, 'files' => $files]);

  case 'file-read':
    $file = (string)($_POST['file'] ?? '');
    $read = staxx_read_file($name, $file, $error);
    if ($read === null) staxx_reply(['ok' => false, 'error' => $error]);
    staxx_reply(['ok' => true, 'name' => $name, 'file' => $file] + $read);

  case 'file-save':
    $file     = (string)($_POST['file'] ?? '');
    $body     = (string)($_POST['body'] ?? '');
    $encoding = (string)($_POST['encoding'] ?? '');

    if ($encoding === 'base64') {
      $decoded = base64_decode($body, true);
      if ($decoded === false) {
        staxx_reply([
          'ok'    => false,
          'error' => 'That file did not arrive intact. Try uploading it again.',
        ]);
      }
      $body = $decoded;
    }

    if (!staxx_write_file($name, $file, $body, $encoding !== 'base64', $error)) {
      staxx_reply(['ok' => false, 'error' => $error]);
    }
    staxx_reply(['ok' => true]);

  case 'file-delete':
    if (!staxx_delete_file($name, (string)($_POST['file'] ?? ''), $error)) {
      staxx_reply(['ok' => false, 'error' => $error]);
    }
    staxx_reply(['ok' => true]);

  case 'file-rename':
    if (!staxx_rename_file($name, (string)($_POST['file'] ?? ''),
                              (string)($_POST['to'] ?? ''), $error)) {
      staxx_reply(['ok' => false, 'error' => $error]);
    }
    staxx_reply(['ok' => true]);

  // ---- run a compose command in the background ----
  case 'run':
    $verb    = (string)($_POST['verb'] ?? '');
    $service = (string)($_POST['service'] ?? '');
    $job     = staxx_start_job($name, $verb, $error, $service);
    if ($job === '') staxx_reply(['ok' => false, 'error' => $error]);
    staxx_prune_jobs();
    staxx_reply([
      'ok'    => true,
      'job'   => $job,
      // Service scope names what was actually acted on, since that is what
      // the output panel's title shows: "Logs — multi-tier / demo-cache"
      // rather than a stack-scoped title that leaves out the one container
      // this command actually touched.
      'title' => (staxx_job_verbs()[$verb]['label'] ?? $verb).' — '.$name
               . ($service !== '' ? ' / '.$service : ''),
    ]);

  // ---- follow a running command's output ----
  case 'job':
    staxx_reply(['ok' => true] + staxx_job_log((string)($_POST['job'] ?? '')));

  /* ---------------------------------------------------------------------
   * The handover — taking over an imported stack's container name.
   *
   * Every command above is shaped as "compose, against this stack's own
   * file, do X". A handover cannot be said that way: switching off,
   * renaming and eventually deleting a container that belongs to no
   * compose file at all — the one Unraid's template built — needs its own
   * narrow route rather than a new entry on the run-verb list. Letting a
   * verb bring its own opening command would turn the one place here with
   * no path from typed input to an arbitrary command into a place with
   * several, so the three cases below call Stacks.php's own fixed sequence
   * instead of assembling anything themselves. See PLAN_42.
   * ------------------------------------------------------------------- */

  // ---- what a handover would replace, and whether one is already open ----
  //
  // Read-only: runs nothing beyond what staxx_handover_targets() and
  // staxx_handover_active() already do, so it is safe to call just to draw
  // the confirmation window.
  case 'handover-check':
    staxx_reply([
      'ok'      => true,
      'targets' => staxx_handover_targets($name),
      'active'  => staxx_handover_active($name),
    ]);

  // ---- begin a handover: set the old container aside, start this one ----
  case 'handover-start':
    $job = staxx_start_handover($name, $error);
    if ($job === '') staxx_reply(['ok' => false, 'error' => $error]);
    staxx_reply(['ok' => true, 'job' => $job]);

  /* ---- answer whether the handover worked ----
   *
   * Read the same defensive way every other boolean here is read: anything
   * other than exactly '1' is treated as "no". That is the safe direction —
   * it is the branch that puts the old container back rather than the one
   * that deletes it.
   */
  case 'handover-finish':
    $worked = ($_POST['worked'] ?? '') === '1';
    $job    = staxx_finish_handover($name, $worked, $error);
    if ($job === '') staxx_reply(['ok' => false, 'error' => $error]);
    staxx_reply(['ok' => true, 'job' => $job]);

  // ---- the stack table, re-rendered as data ----
  case 'list':
    staxx_reply(['ok' => true, 'stacks' => staxx_list_stacks()]);

  /* ---- refreshing the table without reloading the page ----
   *
   * Two sizes, because they cost very different amounts.
   *
   * `state` is the one used after every start, stop and restart. It costs a
   * single `compose ls` for the whole machine and returns only what can have
   * changed: whether each stack is up, what compose is calling the project, and
   * the ready-made contents of the State cell.
   *
   * `rows` re-renders the entire table body and is only needed when the set of
   * rows itself changes — a stack added or deleted, a folder created, renamed
   * or reordered. It re-reads every compose file, so it is the expensive one
   * and is not used for anything a start or a stop can do.
   */
  case 'state':
    staxx_reply(['ok' => true] + staxx_state_snapshot());

  case 'rows':
    $stacks = staxx_list_stacks();
    // Reconcile with Unraid's boot list before drawing anything, so a switch
    // flipped on its own Docker page shows up here rather than being quietly
    // overwritten the next time something is dragged.
    staxx_autostart_sync($stacks);
    staxx_reply([
      'ok'      => true,
      'html'    => staxx_render_rows(staxx_folder_layout($stacks), staxx_can_run()),
      // The "Move to folder" list in the context menu is built from this, so it
      // has to travel with the rows or it goes stale after a folder is added.
      // A folder's id IS its name — staxx_folder_names() returns plain
      // strings, so {id, name} is built here rather than indexed off one.
      'folders' => array_map(
        fn($f) => ['id' => $f, 'name' => $f],
        staxx_folder_names()
      ),
    ]);

  /* ---- download the icons the table is still missing ----
   *
   * The only thing in the plugin that waits on the internet, and it is asked
   * for after the page has drawn, never during. Answers with a map of
   * reference => URL for whatever it got; `done` is false if it ran out of time
   * with work left, and the browser asks again.
   *
   * `scope=import` is the one thing that changes here: it sweeps the import
   * panel's rows instead of the main table's, for the panel to ask for on its
   * own. Any other value — including none — is the main page's sweep exactly
   * as it always was, so the main page never pays for the import list it may
   * never have opened.
   */
  case 'icons':
    $wanted = ($_POST['scope'] ?? '') === 'import' ? staxx_import_icon_wanted() : staxx_icon_wanted();
    staxx_reply(['ok' => true] + staxx_icon_sweep($wanted));

  // ---- self-test: pure PHP, runs no commands, cannot hang ----
  case 'ping':
    staxx_reply([
      'ok'     => true,
      'report' => staxx_selftest(),
      'probes' => array_map(fn($p) => $p['label'], staxx_probes()),
    ]);

  // ---- live figures for the table ----
  //
  // Reads files a background collector wrote; runs no docker command itself,
  // so it answers in a millisecond however many containers exist. Asking is
  // also what keeps the collector alive — see Stats.php.
  case 'stats':
    staxx_reply(['ok' => true] + staxx_stats_snapshot());

  /* ---- list the folders inside one directory, for the volume picker ----
   *
   * The only action that takes a free-form path rather than a validated name.
   * It runs no command and writes nothing; staxx_browse_dirs() resolves the
   * path and refuses anything that lands outside /mnt once resolved. A refusal
   * comes back as ok with an error message, because "you cannot look there" is
   * something to show inside the picker, not a failed request.
   */
  case 'browse':
    staxx_reply(['ok' => true] + staxx_browse_dirs((string)($_POST['path'] ?? '')));

  /* ---- make one folder inside the one the picker is showing ----
   *
   * The only path-based write in here. It creates a single directory, never a
   * tree: the name is validated by staxx_valid_name(), which forbids a
   * slash, and the parent is resolved and checked against /mnt by the same
   * rule browsing uses. A refusal comes back as ok with a message, as above.
   */
  case 'browse-mkdir':
    $made = staxx_browse_mkdir((string)($_POST['path'] ?? ''),
                                  (string)($_POST['folderName'] ?? ''), $error);
    if ($made === '') staxx_reply(['ok' => true, 'error' => $error]);
    staxx_reply(['ok' => true, 'path' => $made]);

  /* ---- what's really on disk for the paths in a compose file ----
   *
   * Called while someone is typing a volumes: entry, so the Form view can
   * show whether a bind-mount source exists yet. staxx_check_paths() does
   * all the safety work; this only shapes the request into what it expects
   * and refuses anything that isn't a JSON array of strings before it gets
   * there. Never slow: no shell, no directory listing, one stat per path.
   */
  case 'paths':
    $paths = json_decode((string)($_POST['paths'] ?? ''), true);
    if (!is_array($paths) || array_filter($paths, fn($p) => !is_string($p))) {
      staxx_reply([
        'ok'    => false,
        'error' => 'Send the paths to check as a JSON array of strings.',
      ]);
    }
    // The browser says whether this is a stack being created — only then is an
    // existing, non-empty folder worth flagging as "inuse" rather than "ok".
    $checkInUse = (string)($_POST['isNew'] ?? '') === '1';
    staxx_reply(['ok' => true, 'paths' => staxx_check_paths($paths, $name, $checkInUse)]);

  /* ---- make the host folder for one or more volume paths ----
   *
   * The server half of the editor's "Create the folder" prompt. Each path is
   * resolved with the exact same rule staxx_check_paths() uses — see
   * staxx_resolve_host_path() — so what gets created is exactly what the
   * editor underlined, then staxx_make_path() does the actual mkdir. No
   * command is run: this is mkdir, not a job, so there is nothing to
   * allow-list and nothing to poll.
   */
  case 'make-paths':
    $paths = json_decode((string)($_POST['paths'] ?? ''), true);
    if (!is_array($paths) || array_filter($paths, fn($p) => !is_string($p))) {
      staxx_reply([
        'ok'    => false,
        'error' => 'Send the paths to create as a JSON array of strings.',
      ]);
    }

    $unique = [];
    foreach ($paths as $p) $unique[$p] = true; // de-duplicate, first occurrence wins
    $unique = array_slice(array_keys($unique), 0, 20);

    $results = [];
    foreach ($unique as $p) {
      $resolved = staxx_resolve_host_path($p, $name);
      if ($resolved === null) {
        $results[$p] = [
          'status' => 'error',
          'error'  => 'That path could not be resolved to somewhere this plugin can create.',
        ];
        continue;
      }

      // Checked before creating, since staxx_make_path() itself cannot say
      // afterwards whether it did any work or found the folder already there.
      $existed  = is_dir($resolved['path']);
      $pathErr  = '';
      if (!staxx_make_path($resolved['path'], $pathErr)) {
        $results[$p] = ['status' => 'error', 'error' => $pathErr];
      } else {
        $results[$p] = ['status' => $existed ? 'exists' : 'made'];
      }
    }

    staxx_reply(['ok' => true, 'results' => $results]);

  /* ---- every timezone, for the picker on a TZ variable ----
   *
   * Asked for once, the first time the picker opens, and held in the page
   * after that. Runs no command and reads no file — PHP's own copy of the IANA
   * database is the whole source.
   */
  case 'timezones':
    staxx_reply(['ok' => true] + staxx_timezones());

  /* ---- the hardware this server can hand to a container ----
   *
   * Asked for when the editor opens, so existing device rows can be named, and
   * again each time the picker opens, because plugging a stick in is exactly the
   * moment someone reaches for it. Runs no command: /sys and /dev hold every
   * answer, so this is a handful of glob() calls and nothing more.
   *
   * Takes no parameters. Whole disks and the USB bus come back like everything
   * else, marked `risky`, and the picker is what decides to keep those behind a
   * "show everything" link.
   */
  case 'devices':
    staxx_reply(['ok' => true] + staxx_devices());

  /* ---- this server's own docker networks ----
   *
   * Asked for when the editor opens, so the Form view's Network mode
   * dropdown can offer them alongside bridge/host/none. Takes no parameters.
   */
  case 'networks':
    staxx_reply(['ok' => true, 'networks' => staxx_docker_networks()]);

  /* ---- images this server's docker has already pulled ----
   *
   * Asked for when the editor opens, so the Form view's `image:` field can
   * suggest a repository:tag someone has used before. Takes no parameters.
   */
  case 'images':
    staxx_reply(['ok' => true, 'images' => staxx_docker_images()]);

  /* ---- tags Docker Hub has published for a repository ----
   *
   * Asked for as the `image:` field's repository half settles, so the tag
   * half can offer real values instead of a guess. Takes `repo` (the
   * repository name typed so far); an unrecognised or host-qualified shape
   * returns an empty list rather than an error, and the field falls back to
   * a plain text box.
   */
  case 'tags':
    staxx_reply(['ok' => true, 'tags' => staxx_image_tags((string)($_POST['repo'] ?? ''))]);

  /* ---- Docker Hub's own search, the Apps dialog's second source ----
   *
   * Unlike ca-search there is no catalogue behind this one to cache, so it
   * reaches the network on every call — which is also why the browser side
   * asks less often than it does for the catalogue. Docker Hub has no
   * equivalent of CA's "has anything changed" stamp to check first. Takes
   * `q` (the search box text); a failure here returns an empty hit list
   * rather than an error, because a search that still finds Community
   * Applications results is more useful than one that reports a Docker Hub
   * outage.
   */
  case 'hub-search':
    staxx_reply(['ok' => true, 'hits' => staxx_hub_search((string)($_POST['q'] ?? ''))]);

  /* ---- what an image and its own documentation say about themselves ----
   *
   * Asked for when Add is pressed on a Docker Hub or local image, so the
   * editor can open with more than a name and a comment. Takes `image` and
   * `source` ('local' or otherwise); `config` is '1' when the caller also
   * wants the registry fallback for ports/volumes/labels, which the common
   * path skips because it is four chained requests. Every field is missing
   * rather than an error when its source failed or was never asked for.
   */
  case 'image-facts':
    staxx_reply(['ok' => true, 'facts' => staxx_image_facts(
      (string)($_POST['image'] ?? ''),
      (string)($_POST['source'] ?? ''),
      ($_POST['config'] ?? '') === '1')]);

  /* ---- start a catalogue rebuild early, quietly ----
   *
   * This is what the Stacks page calls a couple of seconds after it loads, so
   * the catalogue is normally already current by the time anyone presses
   * Apps, and a server that has just booted starts its first build while the
   * stack list is being read rather than when the dialog opens. It reports
   * NOTHING — no state, no message, no count — because a failure here belongs
   * in the Apps dialog, where somebody actually asked for the catalogue; a
   * warning on the Stacks page for a dialog nobody opened would be noise.
   * staxx_ca_refresh_start()'s own atomic-mkdir lock is what stops several
   * tabs starting several downloads.
   */
  case 'ca-refresh':
    $status = staxx_ca_status();
    if (!$status['usable'] || $status['state'] === 'stale') staxx_ca_refresh_start();
    staxx_reply(['ok' => true]);

  /* ---- search the Community Applications catalogue ----
   *
   * The catalogue is a 4000+ entry index built in the background by
   * scripts/ca-index.php — never here, since a page render or a keystroke is
   * exactly the moment nothing may wait on a 24 MB download. A missing or
   * stale cache kicks off that rebuild and replies with an empty result list
   * straight away rather than blocking; the page polls this same action again
   * in a few seconds, same done:false-style protocol as staxx_icon_sweep().
   */
  case 'ca-search':
    $status = staxx_ca_status();

    // A cache past its TTL is refreshed behind the user rather than in front
    // of them. It is still 4,000 apps and still right about nearly all of
    // them, so replacing it with a progress message would cost a working
    // search to gain nothing — and the refresh itself is usually 37 bytes and
    // a tenth of a second, since the catalogue publishes a timestamp that says
    // whether anything has actually changed. See scripts/ca-index.php.
    if ($status['state'] === 'stale' && $status['usable']) staxx_ca_refresh_start();

    // Whatever is going on behind it — a refresh running, a refresh that
    // failed, a cache a week old — a catalogue that exists is served. Only a
    // catalogue that has never been built has nothing to show, which is the
    // one case the two replies below are for.
    if ($status['usable']) $status['state'] = 'ready';

    // A failure with no cache to fall back on is reported as a failure, and
    // nothing is restarted. The page stops polling and shows why, which is the
    // only useful thing to do for a server with no route to the internet — and
    // it is what stops a three-second poll turning one failure into a download
    // attempt every three seconds for as long as the dialog stays open.
    if ($status['state'] === 'failed') {
      staxx_reply([
        'ok'         => true,
        'state'      => 'failed',
        'apps'       => [],
        'categories' => [],
        'message'    => $status['message']
                     ?: 'The applications catalogue could not be downloaded. Check this server can reach the internet, then try again.',
      ]);
    }

    if ($status['state'] !== 'ready') {
      if ($status['state'] !== 'building') staxx_ca_refresh_start();
      staxx_reply([
        'ok'         => true,
        'state'      => 'building',
        'apps'       => [],
        'categories' => [],
        // No duration promised. The whole build is a second or two on a decent
        // connection, but it is a 24 MB download and the honest answer on a
        // slow one is "longer than that" rather than a number that will be
        // wrong for somebody.
        'message'    => 'Fetching the applications catalogue. This happens the first time only — results will appear here on their own.',
      ]);
    }

    staxx_reply([
      'ok'         => true,
      'state'      => 'ready',
      'apps'       => staxx_ca_search((string)($_POST['q'] ?? ''), (string)($_POST['cat'] ?? '')),
      'categories' => staxx_ca_categories(),
      'count'      => $status['count'],
      'built'      => $status['built'],
    ]);

  /* ---- the Apps dialog's front page ----
   *
   * Spotlight, Recently Added and Top Trending — three rows built once at
   * index time (see scripts/ca-index.php) and just read back here, so opening
   * the dialog costs no more than a search does. Takes no parameters. Mirrors
   * ca-search's building/failed/ready state machine exactly, because it reads
   * the same cache and can be stale, building or missing for the same reasons.
   */
  case 'ca-home':
    $status = staxx_ca_status();

    if ($status['state'] === 'stale' && $status['usable']) staxx_ca_refresh_start();
    if ($status['usable']) $status['state'] = 'ready';

    if ($status['state'] === 'failed') {
      staxx_reply([
        'ok'         => true,
        'state'      => 'failed',
        'spot'       => [],
        'new'        => [],
        'trend'      => [],
        'categories' => [],
        'message'    => $status['message']
                     ?: 'The applications catalogue could not be downloaded. Check this server can reach the internet, then try again.',
      ]);
    }

    if ($status['state'] !== 'ready') {
      if ($status['state'] !== 'building') staxx_ca_refresh_start();
      staxx_reply([
        'ok'         => true,
        'state'      => 'building',
        'spot'       => [],
        'new'        => [],
        'trend'      => [],
        'categories' => [],
        'message'    => 'Fetching the applications catalogue. This happens the first time only — results will appear here on their own.',
      ]);
    }

    $home = staxx_ca_home();
    staxx_reply([
      'ok'         => true,
      'state'      => 'ready',
      'spot'       => $home['spot'],
      'new'        => $home['new'],
      'trend'      => $home['trend'],
      'categories' => staxx_ca_categories(),
      'count'      => $status['count'],
      'built'      => $status['built'],
    ]);

  /* ---- one app's full catalogue entry ----
   *
   * A single line read out of apps.jsonl by byte offset — see
   * staxx_ca_app() — so opening a result costs nothing more than that,
   * whichever of the 4000+ entries it is. `i` is the ordinal a ca-search
   * reply handed out, never a path or filename, so a tampered value is only
   * ever a missing array key, not a read of an arbitrary file.
   */
  case 'ca-app':
    $app = staxx_ca_app((int)($_POST['i'] ?? -1));
    if ($app === null) {
      staxx_reply(['ok' => false, 'error' => 'That app is no longer in the catalogue. Try searching again.']);
    }
    staxx_reply(['ok' => true, 'app' => $app]);

  // ---- run one external command and report how it went ----
  case 'probe':
    staxx_reply(['ok' => true, 'result' => staxx_run_probe((string)($_POST['probe'] ?? ''))]);

  /* ------------------------------------------------------------ folders -- */

  case 'folder-list':
    // Same {id, name} shape as the "rows" reply's folder list — a folder's id
    // IS its name now, so both are built off staxx_folder_names()'s plain
    // strings the same way.
    staxx_reply(['ok' => true, 'folders' => array_map(
      fn($f) => ['id' => $f, 'name' => $f], staxx_folder_names()
    )]);

  // A folder's id IS its name now — there is a directory behind it and nothing
  // else it could be called.
  case 'folder-create':
    $id = staxx_folder_create((string)($_POST['folderName'] ?? ''), $error);
    if ($id === '') staxx_reply(['ok' => false, 'error' => $error]);
    staxx_reply(['ok' => true, 'id' => $id]);

  case 'folder-rename':
    if (!staxx_folder_rename((string)($_POST['folder'] ?? ''),
                                (string)($_POST['folderName'] ?? ''), $error)) {
      staxx_reply(['ok' => false, 'error' => $error]);
    }
    staxx_reply(['ok' => true]);

  case 'folder-delete':
    if (!staxx_folder_delete((string)($_POST['folder'] ?? ''), $error)) {
      staxx_reply(['ok' => false, 'error' => $error]);
    }
    staxx_reply(['ok' => true]);

  // Moving a stack between folders moves its directory, so its identity
  // changes with it. The new one goes back to the page, which would otherwise
  // still be holding the old path the next time it acted on that row.
  case 'folder-assign':
    if (!staxx_valid_path($name)) {
      staxx_reply(['ok' => false, 'error' => 'Invalid stack name.']);
    }
    $moved = staxx_folder_assign($name, (string)($_POST['folder'] ?? ''), $error);
    if ($moved === '') staxx_reply(['ok' => false, 'error' => $error]);
    staxx_reply(['ok' => true, 'name' => $moved]);

  // Renaming a stack moves its directory, so its identity changes with it —
  // same reasoning as folder-assign above, and the new rel goes back the
  // same way. The page sequences down/rename/up itself for a running stack;
  // this action is only ever the instant directory move.
  case 'stack-rename':
    if (!staxx_valid_path($name)) {
      staxx_reply(['ok' => false, 'error' => 'Invalid stack name.']);
    }
    $renamed = staxx_rename_stack($name, (string)($_POST['stackName'] ?? ''), $error);
    if ($renamed === '') staxx_reply(['ok' => false, 'error' => $error]);

    // staxx_rename_stack() lives in Stacks.php, which sits below Folders.php
    // in the include order and must not depend on it — so the stored order
    // is kept pointed at the new name here instead, or the drag position a
    // rename inherits would silently be lost.
    if ($renamed !== $name) {
      $folder = staxx_path_folder($name);
      $data   = staxx_folders_load();
      $start  = $data['start'];
      $list   = $start['stacks'][$folder] ?? [];
      $pos    = array_search(staxx_path_leaf($name), $list, true);
      if ($pos !== false) $list[$pos] = staxx_path_leaf($renamed);
      $start['stacks'][$folder] = $list;
      staxx_start_rekey($start, $name, $renamed);
      $data['start'] = $start;
      staxx_folders_save($data);
    }

    staxx_reply(['ok' => true, 'name' => $renamed]);

  // Collapsing is saved on the server rather than in the browser, so the
  // layout is the same on every device you open the page from.
  case 'folder-collapse':
    if (!staxx_folder_collapse((string)($_POST['folder'] ?? ''),
                                  ($_POST['collapsed'] ?? '') === '1', $error)) {
      staxx_reply(['ok' => false, 'error' => $error]);
    }
    staxx_reply(['ok' => true]);

  /* -------------------------------------------------- boot order and start --
   *
   * The order is one thing, saved here; what actually starts at boot is
   * another, and lives only in Unraid's own list. See Autostart.php.
   */

  // One sibling group's order after a drag, posted whole rather than as a
  // move: the browser already knows the finished order, and a whole list
  // cannot land half-applied the way "move item 3 to position 5" can.
  case 'start-order':
    $names = array_values(array_filter(
      array_map('trim', explode(';', (string)($_POST['names'] ?? ''))),
      fn($n) => $n !== ''
    ));
    if (!staxx_start_order_set((string)($_POST['scope'] ?? ''),
                               (string)($_POST['parent'] ?? ''), $names, $error)) {
      staxx_reply(['ok' => false, 'error' => $error]);
    }
    // The stored order is only half the job — Unraid boots from its own file,
    // so that has to follow. A refusal here still leaves the order saved,
    // which is why the reply says what happened rather than failing outright.
    $projected = staxx_autostart_project(staxx_list_stacks(), $error);
    staxx_reply(['ok' => true, 'boot' => $projected, 'error' => $projected ? '' : $error]);

  // Start at boot, for a whole stack ('' service) or one service of it.
  case 'autostart':
    if (!staxx_autostart_set(staxx_list_stacks(), $name,
                             (string)($_POST['service'] ?? ''),
                             ($_POST['on'] ?? '') === '1', $error)) {
      staxx_reply(['ok' => false, 'error' => $error]);
    }
    staxx_reply(['ok' => true]);

  // How long to wait after a folder, a stack or one service before the next
  // thing starts.
  case 'autostart-wait':
    if (!staxx_autostart_wait(staxx_list_stacks(), (string)($_POST['scope'] ?? ''),
                              (string)($_POST['key'] ?? ''),
                              (int)($_POST['wait'] ?? 0), $error)) {
      staxx_reply(['ok' => false, 'error' => $error]);
    }
    staxx_reply(['ok' => true]);

  // Run one command across every stack in a folder.
  case 'folder-run':
    $verb  = (string)($_POST['verb'] ?? '');
    $id    = (string)($_POST['folder'] ?? '');
    $jobs  = [];
    foreach (staxx_list_stacks() as $s) {
      if (($s['folder'] ?? '') !== $id) continue;
      if (!$s['parses']) continue;
      $job = staxx_start_job($s['name'], $verb, $error);
      if ($job !== '') $jobs[] = ['name' => $s['name'], 'job' => $job];
    }
    if (!$jobs) {
      staxx_reply(['ok' => false, 'error' => $error ?: 'Nothing in this folder can be run.']);
    }
    staxx_reply(['ok' => true, 'jobs' => $jobs]);

  /* ------------------------------------------------------------ settings -- */

  // ---- the panel's current values ----
  case 'settings':
    staxx_reply(['ok' => true, 'settings' => staxx_settings_read()]);

  /* ---- validate and write a settings save ----
   *
   * $settings in the reply comes from staxx_settings_save()'s own $saved
   * out-param, never from a fresh staxx_settings_read() call: staxx_cfg()
   * caches the parsed config in a per-request static, so a second read in this
   * same request would still hand back the values from before this write.
   */
  case 'settings-save':
    $reload = false;
    $saved  = null;
    if (!staxx_settings_save($_POST, $error, $reload, $saved)) {
      staxx_reply(['ok' => false, 'error' => $error]);
    }
    staxx_reply(['ok' => true, 'settings' => $saved, 'reload' => $reload]);

  /* ---- everything that could be imported: templates, projects, loose ----
   *
   * Read-only — see Import.php. It writes nothing and changes nothing, but it
   * is not free: it asks docker what containers exist, and asks compose what
   * each Compose Manager project holds, so it costs roughly one compose call
   * per project. Fine for a panel somebody opened on purpose; the reason the
   * self-test counts these from the disk instead.
   *
   * 'root' is the full path stacks land in, so the panel can say so before it
   * writes anything — copying somebody's template settings, API keys among
   * them, into a named place is worth stating out loud rather than leaving
   * implicit. 'existing' is every stack's folder+leaf, in the same shape
   * staxx_scan_stacks() already uses, so the browser can tell whether a name
   * is taken WITHIN THE FOLDER THE USER CHOSE — the per-entry 'taken' flag
   * above only ever checked the top level, which is wrong once a destination
   * folder is offered.
   */
  case 'import-list':
    $existing = array_map(
      fn($s) => ['folder' => $s['folder'], 'leaf' => $s['leaf']],
      staxx_scan_stacks()['stacks']
    );
    staxx_reply([
      'ok'       => true,
      'root'     => staxx_stack_root(),
      'existing' => $existing,
    ] + staxx_import_list());

  /* ---- write one imported stack ----
   *
   * One call per stack — the browser drives the loop over however many rows
   * were ticked, because the converter that turns a template into a compose
   * file runs in the browser and the server has no way to produce it itself.
   * See staxx_import_write() for the refuse-don't-overwrite rule and the
   * lock-before-file ordering; this case only shapes the request into what it
   * expects. 'about' is a JSON object built by the browser — see Import.php's
   * staxx_import_note() for the fields it reads out of it.
   */
  case 'import-write':
    $body  = (string)($_POST['body']  ?? '');
    $about = json_decode((string)($_POST['about'] ?? ''), true);
    if (!is_array($about)) $about = [];

    if (!staxx_import_write($name, $body, $about, $error)) {
      staxx_reply(['ok' => false, 'error' => $error]);
    }
    staxx_reply(['ok' => true, 'name' => $name]);
}

staxx_reply(['ok' => false, 'error' => 'Unknown action "'.$action.'".'], 400);
?>
