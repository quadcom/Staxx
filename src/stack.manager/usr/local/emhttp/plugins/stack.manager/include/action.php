<?PHP
/* Stack Manager — the endpoint the Stacks page talks to.
 * Copyright 2026, Stack Manager contributors.
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

require_once '/usr/local/emhttp/plugins/stack.manager/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/stack.manager/include/Folders.php';
require_once '/usr/local/emhttp/plugins/stack.manager/include/Stats.php';
require_once '/usr/local/emhttp/plugins/stack.manager/include/StacksTable.php';
require_once '/usr/local/emhttp/plugins/stack.manager/include/Devices.php';

function stackman_reply(array $payload, int $status = 200): void {
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
  stackman_reply([
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
    $body = stackman_read_stack($name, $error);
    if ($body === null) stackman_reply(['ok' => false, 'error' => $error]);
    stackman_reply(['ok' => true, 'name' => $name, 'body' => $body]);

  // ---- create a new stack, or overwrite an existing one ----
  case 'save':
    $body   = (string)($_POST['body'] ?? '');
    $isNew  = ($_POST['new'] ?? '') === '1';
    $exists = is_dir(stackman_stack_dir($name));

    if ($isNew && $exists) {
      stackman_reply([
        'ok'    => false,
        'error' => 'A stack called "'.$name.'" already exists. Pick another name, '
                 . 'or edit the existing one.',
      ]);
    }
    if (!stackman_save_stack($name, $body, $error)) {
      stackman_reply(['ok' => false, 'error' => $error]);
    }
    // Report back what actually landed on disk, so "saved" is a fact rather
    // than an assumption.
    $written = stackman_find_compose_file(stackman_stack_dir($name));
    stackman_reply([
      'ok'      => true,
      'name'    => $name,
      'file'    => $written,
      'bytes'   => $written !== '' ? (int)@filesize($written) : 0,
    ]);

  /* ---- delete a stack ----
   *
   * The confirmation is asked for here, not inside stackman_delete_stack().
   * What is about to be destroyed has to reach the user BEFORE anything is
   * removed, and a function that both asks and acts cannot do that — so this
   * case looks first, replies with the list if the folder holds more than
   * the compose file, and only calls the delete once the browser sends the
   * same request back with confirm=1.
   */
  case 'delete':
    $confirmed = ($_POST['confirm'] ?? '') === '1';
    if (!$confirmed) {
      $extras = stackman_stack_extras($name, $error);
      if ($extras === null) stackman_reply(['ok' => false, 'error' => $error]);
      if ($extras) stackman_reply(['ok' => false, 'needsConfirm' => true, 'entries' => $extras]);
    }
    if (!stackman_delete_stack($name, $error, $confirmed)) {
      stackman_reply(['ok' => false, 'error' => $error]);
    }
    stackman_reply(['ok' => true]);

  /* ------------------------------------------------------ companion files --
   *
   * A stack folder may hold more than its compose file now — a .env, a
   * certificate, a config snippet — and these five actions are how the
   * editor reaches them: list what's there, read one, save one, delete one,
   * rename one. Every helper below confines itself to the one stack's own
   * folder; see stackman_stack_file() in Stacks.php.
   */

  case 'files':
    $files = stackman_list_files($name, $error);
    if ($files === null) stackman_reply(['ok' => false, 'error' => $error]);
    stackman_reply(['ok' => true, 'files' => $files]);

  case 'file-read':
    $file = (string)($_POST['file'] ?? '');
    $read = stackman_read_file($name, $file, $error);
    if ($read === null) stackman_reply(['ok' => false, 'error' => $error]);
    stackman_reply(['ok' => true, 'name' => $name, 'file' => $file] + $read);

  case 'file-save':
    $file     = (string)($_POST['file'] ?? '');
    $body     = (string)($_POST['body'] ?? '');
    $encoding = (string)($_POST['encoding'] ?? '');

    if ($encoding === 'base64') {
      $decoded = base64_decode($body, true);
      if ($decoded === false) {
        stackman_reply([
          'ok'    => false,
          'error' => 'That file did not arrive intact. Try uploading it again.',
        ]);
      }
      $body = $decoded;
    }

    if (!stackman_write_file($name, $file, $body, $encoding !== 'base64', $error)) {
      stackman_reply(['ok' => false, 'error' => $error]);
    }
    stackman_reply(['ok' => true]);

  case 'file-delete':
    if (!stackman_delete_file($name, (string)($_POST['file'] ?? ''), $error)) {
      stackman_reply(['ok' => false, 'error' => $error]);
    }
    stackman_reply(['ok' => true]);

  case 'file-rename':
    if (!stackman_rename_file($name, (string)($_POST['file'] ?? ''),
                              (string)($_POST['to'] ?? ''), $error)) {
      stackman_reply(['ok' => false, 'error' => $error]);
    }
    stackman_reply(['ok' => true]);

  // ---- run a compose command in the background ----
  case 'run':
    $verb    = (string)($_POST['verb'] ?? '');
    $service = (string)($_POST['service'] ?? '');
    $job     = stackman_start_job($name, $verb, $error, $service);
    if ($job === '') stackman_reply(['ok' => false, 'error' => $error]);
    stackman_prune_jobs();
    stackman_reply([
      'ok'    => true,
      'job'   => $job,
      // Service scope names what was actually acted on, since that is what
      // the output panel's title shows: "Logs — multi-tier / demo-cache"
      // rather than a stack-scoped title that leaves out the one container
      // this command actually touched.
      'title' => (stackman_job_verbs()[$verb]['label'] ?? $verb).' — '.$name
               . ($service !== '' ? ' / '.$service : ''),
    ]);

  // ---- follow a running command's output ----
  case 'job':
    stackman_reply(['ok' => true] + stackman_job_log((string)($_POST['job'] ?? '')));

  // ---- the stack table, re-rendered as data ----
  case 'list':
    stackman_reply(['ok' => true, 'stacks' => stackman_list_stacks()]);

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
    stackman_reply(['ok' => true] + stackman_state_snapshot());

  case 'rows':
    $stacks = stackman_list_stacks();
    stackman_reply([
      'ok'      => true,
      'html'    => stackman_render_rows(stackman_folder_layout($stacks), stackman_can_run()),
      // The "Move to folder" list in the context menu is built from this, so it
      // has to travel with the rows or it goes stale after a folder is added.
      // A folder's id IS its name — stackman_folder_names() returns plain
      // strings, so {id, name} is built here rather than indexed off one.
      'folders' => array_map(
        fn($f) => ['id' => $f, 'name' => $f],
        stackman_folder_names()
      ),
    ]);

  /* ---- download the icons the table is still missing ----
   *
   * The only thing in the plugin that waits on the internet, and it is asked
   * for after the page has drawn, never during. Answers with a map of
   * reference => URL for whatever it got; `done` is false if it ran out of time
   * with work left, and the browser asks again.
   */
  case 'icons':
    stackman_reply(['ok' => true] + stackman_icon_sweep(stackman_icon_wanted()));

  // ---- self-test: pure PHP, runs no commands, cannot hang ----
  case 'ping':
    stackman_reply([
      'ok'     => true,
      'report' => stackman_selftest(),
      'probes' => array_map(fn($p) => $p['label'], stackman_probes()),
    ]);

  // ---- live figures for the table ----
  //
  // Reads files a background collector wrote; runs no docker command itself,
  // so it answers in a millisecond however many containers exist. Asking is
  // also what keeps the collector alive — see Stats.php.
  case 'stats':
    stackman_reply(['ok' => true] + stackman_stats_snapshot());

  /* ---- list the folders inside one directory, for the volume picker ----
   *
   * The only action that takes a free-form path rather than a validated name.
   * It runs no command and writes nothing; stackman_browse_dirs() resolves the
   * path and refuses anything that lands outside /mnt once resolved. A refusal
   * comes back as ok with an error message, because "you cannot look there" is
   * something to show inside the picker, not a failed request.
   */
  case 'browse':
    stackman_reply(['ok' => true] + stackman_browse_dirs((string)($_POST['path'] ?? '')));

  /* ---- make one folder inside the one the picker is showing ----
   *
   * The only path-based write in here. It creates a single directory, never a
   * tree: the name is validated by stackman_valid_name(), which forbids a
   * slash, and the parent is resolved and checked against /mnt by the same
   * rule browsing uses. A refusal comes back as ok with a message, as above.
   */
  case 'browse-mkdir':
    $made = stackman_browse_mkdir((string)($_POST['path'] ?? ''),
                                  (string)($_POST['folderName'] ?? ''), $error);
    if ($made === '') stackman_reply(['ok' => true, 'error' => $error]);
    stackman_reply(['ok' => true, 'path' => $made]);

  /* ---- what's really on disk for the paths in a compose file ----
   *
   * Called while someone is typing a volumes: entry, so the Form view can
   * show whether a bind-mount source exists yet. stackman_check_paths() does
   * all the safety work; this only shapes the request into what it expects
   * and refuses anything that isn't a JSON array of strings before it gets
   * there. Never slow: no shell, no directory listing, one stat per path.
   */
  case 'paths':
    $paths = json_decode((string)($_POST['paths'] ?? ''), true);
    if (!is_array($paths) || array_filter($paths, fn($p) => !is_string($p))) {
      stackman_reply([
        'ok'    => false,
        'error' => 'Send the paths to check as a JSON array of strings.',
      ]);
    }
    stackman_reply(['ok' => true, 'paths' => stackman_check_paths($paths, $name)]);

  /* ---- every timezone, for the picker on a TZ variable ----
   *
   * Asked for once, the first time the picker opens, and held in the page
   * after that. Runs no command and reads no file — PHP's own copy of the IANA
   * database is the whole source.
   */
  case 'timezones':
    stackman_reply(['ok' => true] + stackman_timezones());

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
    stackman_reply(['ok' => true] + stackman_devices());

  /* ---- this server's own docker networks ----
   *
   * Asked for when the editor opens, so the Form view's Network mode
   * dropdown can offer them alongside bridge/host/none. Takes no parameters.
   */
  case 'networks':
    stackman_reply(['ok' => true, 'networks' => stackman_docker_networks()]);

  /* ---- images this server's docker has already pulled ----
   *
   * Asked for when the editor opens, so the Form view's `image:` field can
   * suggest a repository:tag someone has used before. Takes no parameters.
   */
  case 'images':
    stackman_reply(['ok' => true, 'images' => stackman_docker_images()]);

  /* ---- tags Docker Hub has published for a repository ----
   *
   * Asked for as the `image:` field's repository half settles, so the tag
   * half can offer real values instead of a guess. Takes `repo` (the
   * repository name typed so far); an unrecognised or host-qualified shape
   * returns an empty list rather than an error, and the field falls back to
   * a plain text box.
   */
  case 'tags':
    stackman_reply(['ok' => true, 'tags' => stackman_image_tags((string)($_POST['repo'] ?? ''))]);

  // ---- run one external command and report how it went ----
  case 'probe':
    stackman_reply(['ok' => true, 'result' => stackman_run_probe((string)($_POST['probe'] ?? ''))]);

  /* ------------------------------------------------------------ folders -- */

  case 'folder-list':
    // Same {id, name} shape as the "rows" reply's folder list — a folder's id
    // IS its name now, so both are built off stackman_folder_names()'s plain
    // strings the same way.
    stackman_reply(['ok' => true, 'folders' => array_map(
      fn($f) => ['id' => $f, 'name' => $f], stackman_folder_names()
    )]);

  // A folder's id IS its name now — there is a directory behind it and nothing
  // else it could be called.
  case 'folder-create':
    $id = stackman_folder_create((string)($_POST['folderName'] ?? ''), $error);
    if ($id === '') stackman_reply(['ok' => false, 'error' => $error]);
    stackman_reply(['ok' => true, 'id' => $id]);

  case 'folder-rename':
    if (!stackman_folder_rename((string)($_POST['folder'] ?? ''),
                                (string)($_POST['folderName'] ?? ''), $error)) {
      stackman_reply(['ok' => false, 'error' => $error]);
    }
    stackman_reply(['ok' => true]);

  case 'folder-delete':
    if (!stackman_folder_delete((string)($_POST['folder'] ?? ''), $error)) {
      stackman_reply(['ok' => false, 'error' => $error]);
    }
    stackman_reply(['ok' => true]);

  // Moving a stack between folders moves its directory, so its identity
  // changes with it. The new one goes back to the page, which would otherwise
  // still be holding the old path the next time it acted on that row.
  case 'folder-assign':
    if (!stackman_valid_path($name)) {
      stackman_reply(['ok' => false, 'error' => 'Invalid stack name.']);
    }
    $moved = stackman_folder_assign($name, (string)($_POST['folder'] ?? ''), $error);
    if ($moved === '') stackman_reply(['ok' => false, 'error' => $error]);
    stackman_reply(['ok' => true, 'name' => $moved]);

  // Renaming a stack moves its directory, so its identity changes with it —
  // same reasoning as folder-assign above, and the new rel goes back the
  // same way. The page sequences down/rename/up itself for a running stack;
  // this action is only ever the instant directory move.
  case 'stack-rename':
    if (!stackman_valid_path($name)) {
      stackman_reply(['ok' => false, 'error' => 'Invalid stack name.']);
    }
    $renamed = stackman_rename_stack($name, (string)($_POST['stackName'] ?? ''), $error);
    if ($renamed === '') stackman_reply(['ok' => false, 'error' => $error]);
    stackman_reply(['ok' => true, 'name' => $renamed]);

  // Collapsing is saved on the server rather than in the browser, so the
  // layout is the same on every device you open the page from.
  case 'folder-collapse':
    if (!stackman_folder_collapse((string)($_POST['folder'] ?? ''),
                                  ($_POST['collapsed'] ?? '') === '1', $error)) {
      stackman_reply(['ok' => false, 'error' => $error]);
    }
    stackman_reply(['ok' => true]);

  // Run one command across every stack in a folder.
  case 'folder-run':
    $verb  = (string)($_POST['verb'] ?? '');
    $id    = (string)($_POST['folder'] ?? '');
    $jobs  = [];
    foreach (stackman_list_stacks() as $s) {
      if (($s['folder'] ?? '') !== $id) continue;
      if (!$s['parses']) continue;
      $job = stackman_start_job($s['name'], $verb, $error);
      if ($job !== '') $jobs[] = ['name' => $s['name'], 'job' => $job];
    }
    if (!$jobs) {
      stackman_reply(['ok' => false, 'error' => $error ?: 'Nothing in this folder can be run.']);
    }
    stackman_reply(['ok' => true, 'jobs' => $jobs]);
}

stackman_reply(['ok' => false, 'error' => 'Unknown action "'.$action.'".'], 400);
?>
