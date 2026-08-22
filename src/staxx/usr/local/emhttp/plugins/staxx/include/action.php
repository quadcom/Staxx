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
require_once '/usr/local/emhttp/plugins/staxx/include/Updates.php';
require_once '/usr/local/emhttp/plugins/staxx/include/UpdateRun.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Links.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Relocate.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Record.php';

function staxx_reply(array $payload, int $status = 200): void {
  $stray = '';
  while (ob_get_level() > 0) $stray .= (string)ob_get_clean();
  $stray = trim($stray);
  if ($stray !== '') $payload['stray'] = $stray;

  http_response_code($status);
  header('Content-Type: application/json');
  header('Cache-Control: no-store');

  // Invalid UTF-8 anywhere in $payload — a binary file read back as text, a
  // log line from a container with no encoding discipline at all — used to
  // make json_encode() fail outright, and the reply this function exists to
  // guarantee became exactly the empty body its own file header promises
  // never to send. JSON_INVALID_UTF8_SUBSTITUTE swaps the bad bytes for U+FFFD
  // instead of giving up; the plain fallback below is only for whatever that
  // still cannot save (a resource, NAN/INF, a cycle).
  $json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
  if ($json === false) {
    $json = json_encode(['ok' => false, 'error' => 'The reply could not be encoded as JSON.']);
  }
  echo $json;
  exit;
}

/* ----------------------------------------------------------- events push -- */

define('STAXX_EVENTS_DIR', '/tmp/staxx/events');
define('STAXX_EVENTS_SCRIPT', STAXX_ROOT.'/scripts/events-watcher.sh');

/**
 * Whether push actually works here, checked for real rather than assumed.
 * The page decides whether to open an EventSource on this answer, so a box
 * without nchan — a future Unraid release, or nginx simply not up yet — has
 * to come back false rather than be reported as working: the socket has to
 * exist, and a GET to the publish endpoint (which reports the audience but
 * publishes nothing) has to actually answer.
 */
function staxx_events_available(): bool {
  if (!file_exists('/var/run/nginx.socket')) return false;

  $cmd  = 'curl -s -o /dev/null -w '.escapeshellarg('%{http_code}').' --max-time 4 '
        . '--unix-socket '.escapeshellarg('/var/run/nginx.socket').' '
        . escapeshellarg('http://localhost/pub/staxx?buffer_length=1');

  // 404 counts as available, and getting this wrong made push unavailable for
  // good: nchan answers 404 for a channel that does not exist, which is the
  // state on every boot until something subscribes or publishes. Requiring
  // 200 meant the page never subscribed, so the channel was never created, so
  // the answer stayed 404 — a deadlock that looked exactly like "this server
  // cannot do push". What is being asked here is whether the endpoint is
  // there and answering, and both codes prove that; anything else (a refused
  // connection, no reply at all) does not.
  $code = trim(staxx_sh($cmd, 6));
  return $code === '200' || $code === '404';
}

/** Same "is the pid in the file still alive" test staxx_stats_collector_running() uses. */
function staxx_events_watcher_running(): bool {
  $pidFile = STAXX_EVENTS_DIR.'/watcher.pid';
  if (!is_file($pidFile)) return false;
  $pid = (int)trim((string)@file_get_contents($pidFile));
  return $pid > 0 && is_dir('/proc/'.$pid);
}

/**
 * Start the watcher if nothing is watching already, following exactly how
 * staxx_stats_touch() starts the stats collector in Stats.php: `setsid`
 * detaches it, and stdin/stdout/stderr are all disconnected so it outlives
 * this request instead of dying when PHP finishes answering it.
 */
function staxx_events_watch_start(): void {
  if (staxx_events_watcher_running()) return;
  if (!is_file(STAXX_EVENTS_SCRIPT)) return;
  if (!is_dir(STAXX_EVENTS_DIR)) @mkdir(STAXX_EVENTS_DIR, 0755, true);

  @exec('setsid sh '.escapeshellarg(STAXX_EVENTS_SCRIPT).' '
        .escapeshellarg(STAXX_EVENTS_DIR).' </dev/null >/dev/null 2>&1 &');
}

/* ------------------------------------------------------- loose-container watch --
 *
 * Phase F's safety net: the doorway is the main route in, but if a future
 * Unraid or Community Applications release stops matching it, an install
 * quietly goes back to the old way and nothing would ever say so. This is
 * what notices — no new watcher, just a comparison run every time the rows
 * refresh already reads the world.
 *
 * staxx_import_loose() (Import.php) is the same "belongs to no stack, and
 * no template already claims it" list the import panel itself shows, so
 * a container left behind on purpose via the Phase D exit route is never
 * mistaken for one that arrived the old way.
 *
 * The stamp is a single file holding last look's names, sorted, one per
 * line — not a database of what was seen or dismissed, just enough to ask
 * "is this different from last time". It lives under /tmp rather than the
 * flash drive: this box's writes are worth avoiding for something this
 * cheap, and losing the stamp on reboot only means the next look quietly
 * re-establishes a baseline instead of wrongly calling every already-there
 * container new.
 */
define('STAXX_LOOSE_WATCH_FILE', '/tmp/staxx/loose-seen');

/** @return string[] names that are loose now but were not in the last stamp. */
function staxx_loose_watch_new(): array {
  $now = array_map(fn($row) => $row['name'], staxx_import_loose());
  sort($now);

  $prev = @file_get_contents(STAXX_LOOSE_WATCH_FILE);

  if (!is_dir('/tmp/staxx')) @mkdir('/tmp/staxx', 0755, true);
  @file_put_contents(STAXX_LOOSE_WATCH_FILE, implode("\n", $now));

  // No stamp yet: nothing to compare against, so this look just sets the
  // baseline rather than reporting every pre-existing loose container as a
  // fresh arrival.
  if ($prev === false) return [];

  $before = $prev === '' ? [] : explode("\n", $prev);
  return array_values(array_diff($now, $before));
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

// Clear away abandoned log followers and shell sessions on EVERY request, not
// only on the log and exec actions that create them. Both die by heartbeat —
// asking for their output is what says somebody is still watching — but that
// only collects them when something asks, and a browser that crashed or was
// closed mid-session asks for nothing ever again. Any StaXX page open anywhere
// polls for state every few seconds, so putting this here means a stale
// session is collected within seconds rather than waiting for the next person
// to open a log. Both are a directory glob over a handful of files, which is
// far cheaper than one root shell left attached to a container.
staxx_log_reap();
staxx_exec_reap();

switch ($action) {

  // ---- read one stack's compose file, for the editor ----
  case 'read':
    $body = staxx_read_stack($name, $error);
    if ($body === null) staxx_reply(['ok' => false, 'error' => $error]);
    $reply = ['ok' => true, 'name' => $name, 'body' => $body, 'fingerprint' => md5($body)];
    // PLAN_61 — the registry-move facts for this stack's services, if any.
    // Absent entirely when there is nothing to say, per the wire contract:
    // an empty array would encode as `[]`, not the `{}` the contract expects,
    // so the key itself is left out rather than sent empty.
    $moved = staxx_updates_moved_for_stack($name);
    if ($moved !== []) $reply['moved'] = $moved;
    // PLAN_62 Stage 3 — this stack's author-example findings, same omit-when-
    // empty wire contract as 'moved' just above.
    $watch = staxx_watch_for_stack($name);
    if ($watch['findings'] !== [] || $watch['notes'] !== []) $reply['watch'] = $watch;
    staxx_reply($reply);

  // ---- create a new stack, or overwrite an existing one ----
  case 'save':
    $body        = (string)($_POST['body'] ?? '');
    $isNew       = ($_POST['new'] ?? '') === '1';
    $fingerprint = (string)($_POST['fingerprint'] ?? '');
    $exists      = is_dir(staxx_stack_dir($name));

    if ($isNew && $exists) {
      staxx_reply([
        'ok'    => false,
        'error' => 'A stack called "'.$name.'" already exists. Pick another name, '
                 . 'or edit the existing one.',
      ]);
    }

    // The last save always winning silently is the bug this closes: a save
    // that does not say what it started from is refused, and so is one that
    // started from a version this is no longer on disk — another tab, the
    // image updater, or a hand edit could all have landed in between. A new
    // stack is exempt, since there is nothing on disk yet to conflict with.
    if (!$isNew) {
      $onDisk = staxx_stack_fingerprint($name);
      if ($fingerprint === '' || ($onDisk !== '' && $onDisk !== $fingerprint)) {
        staxx_reply([
          'ok'       => false,
          'conflict' => true,
          'error'    => 'This file has changed since it was opened — by another tab, an image '
                      . 'update, or a hand edit on the server. Close the editor and open the '
                      . 'stack again to see the current version, then make your changes again.',
        ]);
      }
    }

    $historyNote = '';
    if (!staxx_save_stack($name, $body, $error, $historyNote)) {
      staxx_reply(['ok' => false, 'error' => $error]);
    }

    // Phase D — the exit route. Only a brand-new stack can be a caught
    // install, so an edit of an existing one never stamps a template even if
    // a stale 'handoff' field somehow arrived with it. Stamped only now that
    // the stack itself is confirmed on disk — a template for a save that
    // failed would be a lie — and a stamp failing must never fail the save
    // that is already sitting there working; it is reported, not rolled back.
    $templateNote = '';
    if ($isNew && ($_POST['handoff'] ?? '') !== '') {
      // staxx_import_stamp_template() reads the handoff through
      // staxx_handoff_read(), which is where the id's shape is actually
      // checked — a malformed id lands the same as one nothing wrote: no
      // template, no error, nothing to show.
      $templateError = '';
      $templatePath  = staxx_import_stamp_template((string)$_POST['handoff'], $templateError);
      if ($templatePath === '' && $templateError !== '') $templateNote = $templateError;
    }

    // Report back what actually landed on disk, so "saved" is a fact rather
    // than an assumption, and the fingerprint of what is now there so a
    // second save in the same session is not refused against its own write.
    $written = staxx_find_compose_file(staxx_stack_dir($name));
    staxx_reply([
      'ok'           => true,
      'name'         => $name,
      'file'         => $written,
      'bytes'        => $written !== '' ? (int)@filesize($written) : 0,
      'fingerprint'  => staxx_stack_fingerprint($name),
      'templateNote' => $templateNote,
      // Empty unless the previous version could not be kept — this save then
      // has no undo, which the person is entitled to know at the time.
      'historyNote'  => $historyNote,
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
   *
   * An optional `file` field says which of the stack's two files is being
   * typed. Absent (or '') means the main file, checked with its own override
   * (if it has one) layered after it. Anything else has to be exactly this
   * stack's derived override basename — the only other file a two-file
   * stack has — checked with the real main file placed before it; anything
   * else is refused outright rather than silently checked as if it were the
   * main file.
   */
  case 'check':
    $body   = (string)($_POST['body'] ?? '');
    $dir    = staxx_valid_path($name) ? staxx_stack_dir($name) : '';
    $target = (string)($_POST['file'] ?? '');

    $main = $dir !== '' ? staxx_find_compose_file($dir) : '';
    $pair = staxx_compose_files($main);

    $before = ''; $after = '';
    if ($target !== '') {
      // Derived from the main file's own name, not read off $pair — an
      // override that does not exist yet has no entry in $pair for that to
      // read, and without this its very first save skipped validation
      // entirely because nothing here recognised its name.
      $overrideName = isset($pair[1]) ? basename($pair[1]) : staxx_expected_override_basename($main);
      if ($overrideName === '' || $target !== $overrideName) {
        staxx_reply([
          'ok'    => true,
          'valid' => false,
          'error' => 'Only this stack\'s own override file can be checked here, '
                   . 'and this stack has none by that name.',
          'warnings' => [],
        ]);
      }
      $before = $main;          // checking the override: the real main file goes first
    } else {
      $after = $pair[1] ?? ''; // checking the main file: its override, if any, goes second
    }

    $ok = staxx_validate_compose($body, $error, $dir, $warnings, $before, $after);
    staxx_reply(['ok' => true, 'valid' => $ok, 'error' => $error, 'warnings' => $warnings]);

  /* ---- remove a stack: zip its folder, then take it out of the tree ----
   *
   * The confirmation is asked for here, not inside staxx_archive_stack().
   * What is about to be zipped and where it is going has to reach the user
   * BEFORE anything happens, and a function that both asks and acts cannot
   * do that — so this case looks first, always replies with the file list
   * and the planned archive folder, and only calls the archive once the
   * browser sends the same request back with confirm=1. Unlike the delete
   * this replaces, an empty file list still needs asking: there is no longer
   * a "nothing to lose" fast path, because containers always get stopped and
   * a zip always gets written.
   */
  case 'archive':
    $confirmed = ($_POST['confirm'] ?? '') === '1';
    if (!$confirmed) {
      $extras = staxx_stack_extras($name, $error);
      if ($extras === null) staxx_reply(['ok' => false, 'error' => $error]);
      staxx_reply([
        'ok'           => false,
        'needsConfirm' => true,
        'entries'      => $extras,
        'dir'          => staxx_archive_root(),
      ]);
    }
    if (!staxx_archive_stack($name, $error, $confirmed, $archive)) {
      staxx_reply(['ok' => false, 'error' => $error]);
    }

    // Nothing left on disk to keep a place for — drop the stack from the
    // stored order and its own service/wait entries with it. Same layering
    // reason as stack-rename above: this has to happen here, not inside
    // staxx_archive_stack().
    $folder = staxx_path_folder($name);
    $leaf   = staxx_path_leaf($name);
    staxx_folders_update(function (array $data) use ($folder, $leaf, $name): array {
      $start = $data['start'];
      $start['stacks'][$folder] = staxx_start_list_remove($start['stacks'][$folder] ?? [], $leaf);
      staxx_start_drop($start, $name);
      $data['start'] = $start;
      return $data;
    });

    staxx_reply(['ok' => true, 'archive' => $archive]);

  // ---- what has been archived, for the settings panel ----
  case 'archive-list':
    staxx_reply(['ok' => true, 'dir' => staxx_archive_root(), 'files' => staxx_archive_list()]);

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

    // The override is the one companion file this plugin actually runs, so
    // a save that would break it is refused the same way a bad main file's
    // save is — checked together with the real main file, never alone (see
    // staxx_validate_compose()). There is no lockout risk here: deleting a
    // companion file is always allowed, override included.
    $dir  = staxx_valid_path($name) ? staxx_stack_dir($name) : '';
    $main = $dir !== '' ? staxx_find_compose_file($dir) : '';
    $pair = staxx_compose_files($main);
    // An empty body is let through deliberately: the New file button creates
    // a file by saving nothing to it, and the validator rejects empty text
    // outright, so refusing this would leave no way to make an override at
    // all — the button would fail every time it was pressed. The live
    // check on its own tab says so immediately, and the stack's rows carry
    // compose's complaint until something valid is typed or the file is
    // deleted again — visible and reversible, which an impossible button is
    // not.
    // Derived from the main file's own name, not read off $pair — an
    // override that does not exist yet has no entry in $pair for that to
    // read, and without this its very first save skipped validation
    // entirely (nothing here recognised the name it was being saved under).
    $overrideName = isset($pair[1]) ? basename($pair[1]) : staxx_expected_override_basename($main);
    if ($overrideName !== '' && $overrideName === $file && trim($body) !== '') {
      if (!staxx_validate_compose($body, $error, $dir, $warnings, $main, '')) {
        staxx_reply(['ok' => false, 'error' => $error]);
      }
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
  //
  // Two shapes share this one action. `job`/`offset` is a single poll, kept
  // for anywhere only one job is ever in flight. `jobs`/`offsets` is a batch:
  // a folder can start one job per stack, and the page now follows every one
  // of them rather than only the last, so nine stacks would otherwise mean
  // nine requests a second where one now does.
  case 'job':
    if (isset($_POST['job'])) {
      staxx_reply(['ok' => true]
        + staxx_job_log((string)$_POST['job'], (int)($_POST['offset'] ?? 0)));
    }

    if (isset($_POST['jobs'])) {
      // Matched against the RAW, unfiltered lists so a blank or duplicate
      // entry earlier in `jobs` cannot shift every later id onto the wrong
      // offset — dropping and de-duplicating happens after the pairing, not
      // before it.
      $rawIds     = explode(',', (string)$_POST['jobs']);
      $rawOffsets = explode(',', (string)($_POST['offsets'] ?? ''));

      $jobs = [];
      $seen = [];
      foreach ($rawIds as $i => $id) {
        if ($id === '' || isset($seen[$id])) continue; // blank, or a duplicate already answered
        if (count($jobs) >= 64) break; // a crafted request cannot make this open thousands of logs
        $seen[$id] = true;
        // A missing or unparseable offset for this position reads as 0.
        // staxx_job_log() refuses an unknown or malformed id with its own
        // finished-with-null-exit answer rather than an error — every id
        // asked about gets an entry here for the same reason: the page is
        // waiting on an answer for each one, and a missing entry would leave
        // that one row polling for ever.
        $jobs[$id] = staxx_job_log($id, (int)($rawOffsets[$i] ?? 0));
      }
      staxx_reply(['ok' => true, 'jobs' => $jobs]);
    }

    staxx_reply(['ok' => true] + staxx_job_log(''));

  /* ---- the log pane: start, poll, stop and download a `compose logs` -----
   * follower. See staxx_log_start() in Stacks.php for the shape and why it
   * is not run through the usual timeout-wrapped helper. */
  case 'log-start':
    $service = (string)($_POST['service'] ?? '');
    $id      = staxx_log_start($name, $service, $error);
    if ($id === '') staxx_reply(['ok' => false, 'error' => $error]);
    staxx_reply(['ok' => true, 'id' => $id]);

  case 'log-read':
    staxx_reply(['ok' => true]
      + staxx_log_read((string)($_POST['id'] ?? ''), (int)($_POST['offset'] ?? 0)));

  case 'log-stop':
    staxx_log_stop((string)($_POST['id'] ?? ''));
    staxx_reply(['ok' => true]);

  case 'log-download':
    $service = (string)($_POST['service'] ?? '');
    $text    = staxx_log_download($name, $service, $error);
    if ($text === '' && $error !== '') staxx_reply(['ok' => false, 'error' => $error]);
    staxx_reply(['ok' => true, 'text' => $text]);

  /* ---- the shell: open, write to, read from and close a session in a
   * running container. See the "exec" section of Stacks.php for the
   * mechanism and the security rules this depends on — the container name is
   * resolved server-side; only the stack path and service name ever arrive
   * from the browser, and neither reaches a shell unchecked. */
  case 'exec-open':
    $service = (string)($_POST['service'] ?? '');
    $id      = staxx_exec_start($name, $service, $error);
    if ($id === '') staxx_reply(['ok' => false, 'error' => $error]);
    staxx_reply(['ok' => true, 'id' => $id]);

  case 'exec-write':
    if (!staxx_exec_write((string)($_POST['id'] ?? ''), (string)($_POST['bytes'] ?? ''), $error)) {
      staxx_reply(['ok' => false, 'error' => $error]);
    }
    staxx_reply(['ok' => true]);

  case 'exec-read':
    staxx_reply(['ok' => true]
      + staxx_exec_read((string)($_POST['id'] ?? ''), (int)($_POST['offset'] ?? 0)));

  case 'exec-close':
    staxx_exec_stop((string)($_POST['id'] ?? ''));
    staxx_reply(['ok' => true]);

  /* ---- the file manager: list, read, save, rename, delete and mkdir
   * inside a running container. See the "container files" section of
   * Stacks.php — the same SHELL_ENABLED switch and container-resolution
   * rules as the shell above gate every one of these. */
  case 'cfile-list':
    $service = (string)($_POST['service'] ?? '');
    $dir     = (string)($_POST['dir'] ?? '');
    $listing = staxx_cfile_list($name, $service, $dir, $error);
    if ($listing === null) staxx_reply(['ok' => false, 'error' => $error]);
    staxx_reply(['ok' => true] + $listing);

  case 'cfile-read':
    $service = (string)($_POST['service'] ?? '');
    $path    = (string)($_POST['path'] ?? '');
    $read    = staxx_cfile_read($name, $service, $path, $error);
    if ($read === null) staxx_reply(['ok' => false, 'error' => $error]);
    staxx_reply(['ok' => true, 'path' => $path] + $read);

  case 'cfile-save':
    $service  = (string)($_POST['service'] ?? '');
    $path     = (string)($_POST['path'] ?? '');
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

    if (!staxx_cfile_write($name, $service, $path, $body, $encoding !== 'base64', $error)) {
      staxx_reply(['ok' => false, 'error' => $error]);
    }
    staxx_reply(['ok' => true]);

  case 'cfile-rename':
    $service = (string)($_POST['service'] ?? '');
    if (!staxx_cfile_rename($name, $service, (string)($_POST['path'] ?? ''),
                                (string)($_POST['to'] ?? ''), $error)) {
      staxx_reply(['ok' => false, 'error' => $error]);
    }
    staxx_reply(['ok' => true]);

  case 'cfile-delete':
    $service = (string)($_POST['service'] ?? '');
    $recurse = (string)($_POST['recurse'] ?? '') === '1';
    if (!staxx_cfile_delete($name, $service, (string)($_POST['path'] ?? ''), $recurse, $error)) {
      staxx_reply(['ok' => false, 'error' => $error]);
    }
    staxx_reply(['ok' => true]);

  /* Owner and permissions in one action, because the button that asks for
   * them asks for both and either may be left alone. Two docker calls rather
   * than one combined command: each helper stays one job, and a chmod that
   * fails after a chown succeeded reports exactly that rather than hiding
   * behind a single exit code. */
  case 'cfile-chown':
    $service = (string)($_POST['service'] ?? '');
    $path    = (string)($_POST['path'] ?? '');
    $owner   = (string)($_POST['owner'] ?? '');
    $mode    = (string)($_POST['mode'] ?? '');
    if ($owner === '' && $mode === '') {
      staxx_reply(['ok' => false, 'error' => 'Nothing to change — give an owner, permissions, or both.']);
    }
    // Both answers are checked before either command runs. Left to the two
    // helpers' own checks, a bad mode alongside a good owner reported a
    // refusal that had already changed the owner.
    if ($owner !== '' && !staxx_cfile_valid_owner($owner)) {
      staxx_reply(['ok' => false, 'error' => 'The owner has to be a number, or a pair like 99:100 — not a name.']);
    }
    if ($mode !== '' && !staxx_cfile_valid_mode($mode)) {
      staxx_reply(['ok' => false, 'error' => 'Permissions have to be three or four digits from 0 to 7, like 755 — not u+x.']);
    }
    if ($owner !== '' && !staxx_cfile_chown($name, $service, $path, $owner, $error)) {
      staxx_reply(['ok' => false, 'error' => $error]);
    }
    if ($mode !== '' && !staxx_cfile_chmod($name, $service, $path, $mode, $error)) {
      staxx_reply(['ok' => false, 'error' => $error]);
    }
    staxx_reply(['ok' => true]);

  case 'cfile-mkdir':
    $service = (string)($_POST['service'] ?? '');
    if (!staxx_cfile_mkdir($name, $service, (string)($_POST['path'] ?? ''), $error)) {
      staxx_reply(['ok' => false, 'error' => $error]);
    }
    staxx_reply(['ok' => true]);

  case 'cfile-home':
    $service = (string)($_POST['service'] ?? '');
    $home    = staxx_cfile_home($name, $service, $error);
    if ($home === '' && $error !== '') staxx_reply(['ok' => false, 'error' => $error]);
    staxx_reply(['ok' => true, 'home' => $home]);

  /* ---- PLAN_44 D6: the line above the panes, and "show the environment" -
   * See the "PLAN_44 D6 jobs" section of Stacks.php — both share the file
   * manager's own container-resolution and SHELL_ENABLED gate. The third D6
   * job, "fix ownership", never reaches the server at all; it only builds a
   * command string in the browser and leaves it unrun in the shell. */
  case 'cstat':
    $service = (string)($_POST['service'] ?? '');
    $stat    = staxx_cstat($name, $service, $error);
    if ($stat === null) staxx_reply(['ok' => false, 'error' => $error]);
    staxx_reply(['ok' => true] + $stat);

  case 'cenv':
    $service = (string)($_POST['service'] ?? '');
    $text    = staxx_cenv($name, $service, $error);
    if ($text === null) staxx_reply(['ok' => false, 'error' => $error]);
    staxx_reply(['ok' => true, 'text' => $text]);

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

  /* ---- what taking this stack over would do, and whether one is open ----
   *
   * Read-only: runs nothing beyond what staxx_project_containers(),
   * staxx_handover_targets() and staxx_handover_active() already do, so it is
   * safe to call just to draw the confirmation window.
   *
   * `mode` says which of the two routes applies, so the browser does not
   * have to guess from `targets` and `rebuild` itself:
   *   - 'rebuild'  a Compose Manager project is already running under this
   *                stack's own project name — bring it up in place.
   *   - 'handover' no project match, but a container holds a name this
   *                stack's file pins — stop it, rename it aside, start fresh.
   *   - 'none'     neither applies; nothing here can be taken over.
   *
   * A project match wins over container-name targets: the imports that pin
   * their own container_name would otherwise be refused by the handover's
   * "already belongs to another compose project" check, when in fact compose
   * will simply reuse those very containers.
   */
  case 'handover-check':
    $rebuild = staxx_project_containers($name);
    $targets = staxx_handover_targets($name);
    staxx_reply([
      'ok'      => true,
      'mode'    => $rebuild ? 'rebuild' : ($targets ? 'handover' : 'none'),
      'targets' => $targets,
      'rebuild' => $rebuild,
      'project' => staxx_project_name(staxx_path_leaf($name)),
      'active'  => staxx_handover_active($name),
    ]);

  // ---- begin a handover: set the old container aside, start this one ----
  case 'handover-start':
    $job = staxx_start_handover($name, $error);
    if ($job === '') staxx_reply(['ok' => false, 'error' => $error]);
    staxx_reply(['ok' => true, 'job' => $job]);

  // ---- begin a takeover: bring the stack up in place of a running project ----
  case 'takeover-start':
    $job = staxx_start_takeover($name, $error);
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
    $reply = [
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
      // PLAN_65 — every host port and bind-mount path already taken by a
      // container, so the editor can warn about a clash. Rides this refresh
      // rather than 'state' (deliberately just one `compose ls`) or a new
      // action, because 'rows' already re-reads everything and is the one
      // meant to cost more.
      'taken'   => staxx_import_taken_facts(),
    ];
    // Phase F's safety net — see staxx_loose_watch_new() for why this rides
    // the expensive refresh rather than the cheap one. Left out entirely
    // when quiet, the same wire contract the 'read' action's 'moved' field
    // uses above.
    $looseNew = staxx_loose_watch_new();
    if ($looseNew !== []) $reply['looseNew'] = $looseNew;
    staxx_reply($reply);

  /* ---- PLAN_45 phase 3: every row's update pill, plus the summary line ----
   *
   * Cheap on purpose, so the page can ask on every poll: one read of the
   * update state file, plus the compose metadata staxx_compose_meta() already
   * caches to disk for the table itself. No docker call happens here at all —
   * that only ever happens in the detached check pass 'update-check' starts.
   * `rows` is keyed both by stack path ("Media/jellyfin") and by
   * "path::service" ("Media/jellyfin::jellyfin"), matching how the table
   * already addresses a service row; `folders` is keyed by folder name.
   */
  case 'updates':
    $rows = [];
    foreach (staxx_list_stacks() as $s) {
      $rows[$s['name']] = staxx_updates_for_row($s['name']);
      if ($s['file'] === '') continue;
      $meta = staxx_compose_meta($s['file']);
      if (!$meta['ok']) continue;
      foreach (array_keys($meta['services']) as $svc) {
        $rows[$s['name'].'::'.$svc] = staxx_updates_for_row($s['name'], $svc);
      }
    }
    $folders = [];
    foreach (staxx_folder_names() as $f) {
      $folders[$f] = staxx_updates_for_folder($f);
    }
    staxx_reply([
      'ok'      => true,
      'summary' => staxx_updates_summary(),
      'rows'    => $rows,
      'folders' => $folders,
      // The three additions from PLAN_45 phases 4-8: the pause switch, the
      // queue's current state (ticked here so a page left open still drives
      // it forward), and every clock that has already run out. staxx_update_due()
      // reads every stack's compose metadata, which is exactly what the loop
      // above already pays for, so nothing here costs a second sweep.
      'paused'  => (bool)(staxx_update_state()['paused'] ?? false),
      'queue'   => staxx_update_queue_tick(),
      'due'     => staxx_update_due(),
    ]);

  // ---- start a check pass; the page follows it with the existing 'job' action ----
  //
  // Always forced: a person pressing this button means "ask now", which is
  // the whole difference between this and the nightly pass — the remembered
  // six-hour answers exist to protect the registry's rate limit from an
  // automatic sweep, not from someone who deliberately asked again.
  case 'update-check':
    $scope = (string)($_POST['scope'] ?? 'all');
    if ($scope !== 'all' && !staxx_valid_path($scope)) {
      staxx_reply(['ok' => false, 'error' => 'Invalid scope.']);
    }
    $job = staxx_update_check_start($scope, true, $error);
    if ($job === '') staxx_reply(['ok' => false, 'error' => $error]);
    staxx_reply(['ok' => true, 'job' => $job]);

  /* ---- resolve one service's project and support links, for the row menu --
   *
   * Read-only and cheap: staxx_project_links() reads the compose metadata
   * already cached to disk, the update-check state file, and the Community
   * Applications index — no docker call and no network request happen here.
   * The service name is checked for membership in the compose file's own
   * services, the same real check staxx_start_job() runs, not just a shape
   * check on the string.
   */
  case 'links':
    $service = (string)($_POST['service'] ?? '');
    if (!staxx_valid_path($name)) {
      staxx_reply(['ok' => false, 'error' => 'Invalid stack name.']);
    }
    $dir  = staxx_stack_dir($name);
    $file = staxx_find_compose_file($dir);
    if ($file === '') {
      staxx_reply(['ok' => false, 'error' => 'No compose file found in this stack.']);
    }
    $meta = staxx_compose_meta($file);
    if (!$meta['ok']) {
      staxx_reply(['ok' => false, 'error' => 'This stack\'s compose file could not be read.']);
    }
    if (!isset($meta['services'][$service])) {
      staxx_reply(['ok' => false, 'error' => 'No service called "'.$service.'" in this stack.']);
    }
    $image = (string)$meta['services'][$service]['image'];
    $links = staxx_project_links($image, $meta['x'], $meta['services'][$service]['x']);
    staxx_reply(['ok' => true, 'name' => $name, 'service' => $service] + $links);

  // ---- dismiss the version currently on offer for one image ----
  case 'update-skip':
    if (!staxx_update_skip((string)($_POST['image'] ?? ''), $error)) {
      staxx_reply(['ok' => false, 'error' => $error]);
    }
    staxx_reply(['ok' => true]);

  // ---- dismiss the registry-move hint currently on offer for one image ----
  case 'update-skip-move':
    if (!staxx_update_skip_move((string)($_POST['image'] ?? ''), $error)) {
      staxx_reply(['ok' => false, 'error' => $error]);
    }
    staxx_reply(['ok' => true]);

  // ---- dismiss one author-example finding for one stack (PLAN_62 Stage 4) ----
  case 'watch-skip':
    if (!staxx_valid_path($name)) {
      staxx_reply(['ok' => false, 'error' => 'Invalid stack name.']);
    }
    if (!staxx_watch_skip(
      $name,
      (string)($_POST['image'] ?? ''),
      (string)($_POST['service'] ?? ''),
      (string)($_POST['setting'] ?? ''),
      $error
    )) {
      staxx_reply(['ok' => false, 'error' => $error]);
    }
    staxx_reply(['ok' => true]);

  // ---- every undismissed author-example finding, across every stack ----
  // staxx_watch_report() already answers with its own 'ok', so this is a
  // plain pass-through — not '+'-merged with a second ['ok' => true], which
  // is exactly the left-wins-on-collision trap PLAN_62 caught elsewhere.
  case 'watch-report':
    staxx_reply(staxx_watch_report());

  // ---- the grid-wide switch: stop every clock without touching a single one ----
  case 'update-pause':
    $on = ($_POST['on'] ?? '') === '1';
    staxx_update_pause($on);
    staxx_reply(['ok' => true, 'paused' => $on]);

  /* ---- cancel, or un-cancel, the clock for one image ----
   *
   * Refuses an image the state file has never heard of, so a typo in the
   * reference cannot quietly create a new entry for nothing to ever match.
   */
  case 'update-hold':
    if (!staxx_update_hold((string)($_POST['image'] ?? ''), ($_POST['on'] ?? '') === '1', $error)) {
      staxx_reply(['ok' => false, 'error' => $error]);
    }
    staxx_reply(['ok' => true]);

  /* ---- "update it now" — the same 'update' verb the clock would run itself --
   *
   * A whole stack when service is left out, one service when it is given —
   * except a stopped service, which runs 'pull' instead (see below).
   * staxx_start_job() is what actually checks the service name against this
   * stack's own compose services; nothing here needs to repeat that check.
   */
  case 'update-apply':
    if (!staxx_valid_path($name)) {
      staxx_reply(['ok' => false, 'error' => 'Invalid stack name.']);
    }
    $service = (string)($_POST['service'] ?? '');

    // Update must never be the thing that starts a container the reader
    // deliberately stopped. Whole-stack scope always updates; a single named
    // service only updates if it is already running — otherwise (stopped, or
    // never started at all) it is pulled instead, same as the menu's own
    // Pull, so its next start picks up the new image. Same container lookup
    // staxx_stack_containers() gives the row renderer, so this can never
    // disagree with what the row itself shows as running.
    $verb = 'update';
    if ($service !== '') {
      $rows = staxx_stack_containers([
        'name' => $name, 'leaf' => staxx_path_leaf($name), 'project' => '',
        'file' => staxx_find_compose_file(staxx_stack_dir($name)),
      ]);
      $running = false;
      foreach ($rows as $row) {
        if ($row['service'] === $service && strtolower($row['state']) === 'running') {
          $running = true;
          break;
        }
      }
      if (!$running) $verb = 'pull';
    }

    $job = staxx_start_job($name, $verb, $error, $service);
    if ($job === '') staxx_reply(['ok' => false, 'error' => $error]);
    staxx_prune_jobs();
    staxx_reply(['ok' => true, 'job' => $job]);

  /* ---- "rebuild ready" — build fresh from the (moved-on) base, then come up --
   *
   * Records the base's current registry digest as the new baseline for
   * every affected service BEFORE the job starts, not after — otherwise this
   * exact rebuild would keep reporting itself as due for ever once it runs,
   * since nothing else ever clears staxx_rebuild_due()'s recorded answer.
   * Whole-stack when service is left out, one service when it is given, the
   * same shape every other verb here takes.
   */
  case 'update-rebuild':
    if (!staxx_valid_path($name)) {
      staxx_reply(['ok' => false, 'error' => 'Invalid stack name.']);
    }
    $service = (string)($_POST['service'] ?? '');
    $dir  = staxx_stack_dir($name);
    $file = staxx_find_compose_file($dir);
    if ($file === '') {
      staxx_reply(['ok' => false, 'error' => 'No compose file found in this stack.']);
    }
    $meta = staxx_compose_meta($file);
    if (!$meta['ok']) {
      staxx_reply(['ok' => false, 'error' => 'This stack\'s compose file could not be read.']);
    }
    if ($service !== '' && !isset($meta['services'][$service])) {
      staxx_reply(['ok' => false, 'error' => 'No service called "'.$service.'" in this stack.']);
    }
    foreach (($service !== '' ? [$service] : array_keys($meta['services'])) as $svc) {
      staxx_rebuild_baseline_reset($name, $svc);
    }
    $job = staxx_start_job($name, 'rebuild', $error, $service);
    if ($job === '') staxx_reply(['ok' => false, 'error' => $error]);
    staxx_prune_jobs();
    staxx_reply(['ok' => true, 'job' => $job]);

  /* ---- put the previous image back, from this service's own history ----
   *
   * staxx_update_rollback() refuses outright when the digest history points
   * to has already been removed locally, rather than guessing at a pull.
   */
  case 'update-rollback':
    if (!staxx_valid_path($name)) {
      staxx_reply(['ok' => false, 'error' => 'Invalid stack name.']);
    }
    $job = staxx_update_rollback($name, (string)($_POST['service'] ?? ''), $error);
    if ($job === '') staxx_reply(['ok' => false, 'error' => $error]);
    staxx_reply(['ok' => true, 'job' => $job]);

  /* ---- start a queue over every stack in scope that actually has an update --
   *
   * $scope takes the same three shapes 'update-check' already accepts —
   * 'all', a folder name, or one stack's path — checked the same way.
   * staxx_update_queue_start() itself refuses a second queue on top of one
   * already running.
   */
  case 'update-queue-start':
    $scope = (string)($_POST['scope'] ?? 'all');
    if ($scope !== 'all' && !staxx_valid_path($scope)) {
      staxx_reply(['ok' => false, 'error' => 'Invalid scope.']);
    }
    $qid = staxx_update_queue_start($scope, ($_POST['stopped'] ?? '') === '1', $error);
    if ($qid === '') staxx_reply(['ok' => false, 'error' => $error]);
    staxx_reply(['ok' => true, 'queue' => staxx_update_queue_state()]);

  // ---- advance the queue one step, and say where it now stands ----
  //
  // There is no daemon behind this: a page watching a running queue is what
  // moves it forward between cron passes, exactly like the check pass this
  // mirrors, so this is the one place that has to call tick() rather than
  // just reading the state back.
  case 'update-queue':
    staxx_reply(['ok' => true, 'queue' => staxx_update_queue_tick()]);

  // ---- let the running stack finish, then mark the rest skipped ----
  case 'update-queue-stop':
    staxx_update_queue_stop();
    staxx_reply(['ok' => true, 'queue' => staxx_update_queue_state()]);

  /* ---- remove images nothing is using and no history still points to ----
   *
   * This one deletes things, so a dry run is the form the page can call
   * freely — it is refused only when it would actually remove something and
   * the cleanup setting is still off. Turning that setting on is the only
   * way past this, never a flag on the request.
   */
  case 'update-cleanup':
    $dry = ($_POST['dry'] ?? '') === '1';
    if (!$dry && staxx_update_settings()['cleanup'] === 'off') {
      staxx_reply([
        'ok'    => false,
        'error' => 'Turn on image cleanup in the update settings first. A dry run can '
                 . 'still be checked at any time without changing that.',
      ]);
    }
    $result = staxx_update_cleanup($dry, $error);
    if ($error !== '') staxx_reply(['ok' => false, 'error' => $error]);
    staxx_reply(['ok' => true, 'removed' => $result['removed'] ?? [], 'kept' => $result['kept'] ?? 0]);

  // ---- the browser saying an editor on this stack still has unsaved changes --
  //
  // Touched on a timer while the editor is open; a browser that vanished
  // simply stops touching it, and the mark goes stale on its own after 15
  // minutes rather than freezing the stack's clock for ever.
  case 'update-editing':
    if (!staxx_valid_path($name)) {
      staxx_reply(['ok' => false, 'error' => 'Invalid stack name.']);
    }
    staxx_update_editing_mark($name);
    staxx_reply(['ok' => true]);

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

  /* ---- start the event watcher, and say whether push works at all ----
   *
   * The watcher is self-terminating just like the stats collector — it
   * exits on its own once nobody is subscribed — so calling this on every
   * page load is safe; finding one already running is a no-op. Only started
   * when push is actually available, since a watcher with nowhere to
   * publish would do nothing but sit there. The page uses "available" to
   * decide whether to open its EventSource at all, so this has to be a real
   * check, not an assumption — see staxx_events_available().
   */
  case 'events-watch':
    $available = staxx_events_available();
    if ($available) staxx_events_watch_start();
    staxx_reply(['ok' => true, 'available' => $available]);

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

  /* ---- tags a registry has published for a repository ----
   *
   * Asked for as the `image:` field's repository half settles, so the tag
   * half can offer real values instead of a guess. Takes `repo` (the
   * repository name typed so far); staxx_registry_tags() handles both a
   * bare Docker Hub name and a host-qualified one (ghcr.io and the like), so
   * only a shape neither can parse returns an empty list, and the field
   * falls back to a plain text box.
   */
  case 'tags':
    staxx_reply(['ok' => true, 'tags' => staxx_registry_tags((string)($_POST['repo'] ?? ''))]);

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

  // ---- try one service's web address, exactly as its row's link would open it ----
  case 'webui-test':
    $url = staxx_webui_for($name, (string)($_POST['service'] ?? ''), $error);
    if ($url === '') staxx_reply(['ok' => false, 'error' => $error]);

    $answered = staxx_webui_try($url, $code);
    if (!$answered) {
      $message = 'Nothing answered at '.$url.' within four seconds. Check the port in '
               . 'the ports section against the port the application is actually listening on.';
    } elseif ($code < 400) {
      $message = 'Something answered at '.$url.' — the web page opens.';
    } elseif ($code < 500) {
      $message = $url.' replied with status '.$code.'. Something is listening there, but that '
               . 'is not the web page, so the port is probably right and the path in the web '
               . 'address wrong.';
    } else {
      $message = $url.' replied with status '.$code.'. Something answered with an error of its '
               . 'own, so the port is right and the application is unhappy.';
    }

    staxx_reply(['ok' => true, 'url' => $url, 'answered' => $answered, 'code' => $code, 'message' => $message]);

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
      staxx_folders_update(function (array $data) use ($folder, $name, $renamed): array {
        $start = $data['start'];
        $list  = $start['stacks'][$folder] ?? [];
        $pos   = array_search(staxx_path_leaf($name), $list, true);
        if ($pos !== false) $list[$pos] = staxx_path_leaf($renamed);
        $start['stacks'][$folder] = $list;
        staxx_start_rekey($start, $name, $renamed);
        $data['start'] = $start;
        return $data;
      });
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
    // 'run' prunes after starting; this case can start just as many jobs
    // (one per stack in the folder) and was missing the same housekeeping.
    staxx_prune_jobs();
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

  /* ---------------------------------------------------- moving the stacks --
   * PLAN_68 Part B piece 4: the one-time offer to move stacks off the flash
   * drive, and the remembered answer to it. See Relocate.php for the move
   * itself and the storage-options reader; nothing here re-implements either.
   */

  /* ---- what could the stacks folder move to, and has this been settled ----
   *
   * Read-only. 'onFlash' is true when the CURRENT stacks folder is /boot or
   * sits under it — the same test staxx_storage_options() itself makes when
   * deciding whether to offer flash as "where it already is".
   */
  case 'storage-options':
    $current = staxx_stack_root();
    $options = staxx_storage_options();
    staxx_reply([
      'ok'          => true,
      'current'     => $current,
      'onFlash'     => $current === '/boot' || strpos($current, '/boot/') === 0,
      'choice'      => staxx_settings_read()['STORAGE_CHOICE'],
      'offered'     => $options['offered'],
      'unavailable' => $options['unavailable'],
    ]);

  /* ---- would this destination be refused, without moving anything ----
   *
   * Read-only — calls the exact function staxx_relocate_start() calls before
   * it commits to anything, so the browser can tell someone the answer while
   * they are still typing instead of only after they press Move. 'ok' is
   * true whenever the question was answered at all; a path that would be
   * refused is a successful answer, not a failed request.
   */
  case 'relocate-check':
    $dest = staxx_relocate_refuse((string)($_POST['dest'] ?? ''), $error);
    if ($dest === '') staxx_reply(['ok' => true, 'ready' => false, 'error' => $error]);
    staxx_reply(['ok' => true, 'ready' => true, 'path' => $dest]);

  // ---- start moving the stacks folder to a new location ----
  case 'relocate':
    $job = staxx_relocate_start((string)($_POST['dest'] ?? ''), $error);
    if ($job === '') staxx_reply(['ok' => false, 'error' => $error]);
    staxx_reply(['ok' => true, 'job' => $job]);

  /* ---- remember that the storage question has been settled ----
   *
   * Only 'chosen' or 'declined' are ever accepted here — never 'ask', which
   * is the untouched default and must never be written back by this action.
   * Written through staxx_settings_save() so the allowlist check and the
   * atomic write are not duplicated here.
   */
  case 'store-choice':
    $choice = (string)($_POST['choice'] ?? '');
    if ($choice !== 'chosen' && $choice !== 'declined') {
      staxx_reply(['ok' => false, 'error' => 'Choice must be "chosen" or "declined".']);
    }
    if (!staxx_settings_save(['STORAGE_CHOICE' => $choice], $error)) {
      staxx_reply(['ok' => false, 'error' => $error]);
    }
    staxx_reply(['ok' => true]);

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

  /* ---- write one Compose Manager project in as a stack ----
   *
   * 'id' is the project's directory entry, never a path — see
   * staxx_import_write_project(), which looks it up fresh in its own list
   * rather than trusting anything the browser sent. 'about' is shaped the
   * same way 'import-write' takes it; staxx_import_write_project() fills in
   * the project-specific fields itself before staxx_import_note() reads them.
   */
  case 'import-project':
    $id    = (string)($_POST['id'] ?? '');
    $about = json_decode((string)($_POST['about'] ?? ''), true);
    if (!is_array($about)) $about = [];

    if (!staxx_import_write_project($name, $id, $about, $error)) {
      staxx_reply(['ok' => false, 'error' => $error]);
    }
    staxx_reply(['ok' => true, 'name' => $name]);

  /* ---- read back one Add Container handoff ----
   *
   * See staxx_handoff_read() in Import.php. Only the decoded template record
   * goes to the browser — that is all the converter needs, and the original
   * XML is the server's own business at save time, not the browser's. A
   * malformed or expired id is the normal case of a handoff nobody opened in
   * time, not a fault worth alarming anyone over.
   *
   * xmlTemplate/xmlTemplateAvailable (PLAN_63 section 16) ARE the browser's
   * business, unlike the XML: they are what the caught-install banner's
   * "Let Unraid install this instead" escape hatch is built from, and
   * whether it is shown at all.
   */
  case 'handoff-read':
    $app = staxx_handoff_read((string)($_POST['id'] ?? ''));
    // A file that read back without a template in it is as useless as no file
    // at all, so it is refused the same way rather than handing the converter
    // a null and letting it fail somewhere less obvious.
    if ($app === null || !is_array($app['app'] ?? null)) {
      staxx_reply(['ok' => false, 'error' => 'This install link has expired. Go back to Apps and '
                                            . 'install it again.']);
    }
    $xmlTemplate = (string)($app['xmlTemplate'] ?? '');
    staxx_reply([
      'ok' => true,
      'app' => $app['app'],
      'kind' => (string)($app['kind'] ?? 'default'),
      'xmlTemplate' => $xmlTemplate,
      'xmlTemplateAvailable' => $xmlTemplate !== '' && staxx_handoff_template_available($xmlTemplate),
    ]);

  /* ---- PLAN_68 Part A piece 3: list one stack's kept history ----
   *
   * Read-only and cheap: staxx_record_list() only ever reads the hidden
   * index, and reading it is never what creates it, so a stack with no
   * history at all is answered with an empty list, not an error. Hashes are
   * the record's own bookkeeping and are left out — the page has no use for
   * them.
   */
  case 'history-list':
    if (!staxx_valid_path($name)) {
      staxx_reply(['ok' => false, 'error' => 'Invalid stack name.']);
    }
    $versions = array_map(fn($v) => [
      'n'    => $v['n'],
      'at'   => $v['at'],
      'size' => $v['size'],
      'file' => $v['file'],
      'name' => $v['name'],
    ], staxx_record_list($name));
    staxx_reply(['ok' => true, 'versions' => $versions, 'keep' => STAXX_RECORD_KEEP]);

  /* ---- read one kept version's text, for the history panel ----
   *
   * staxx_record_get() returns null for two different reasons that must not
   * read the same to the person: the index no longer names this version at
   * all (pruned away), or it does but the stored bytes no longer match their
   * own recorded fingerprint (tampered with, or corrupted on disk). The
   * index is checked first so the right one of those two is reported.
   */
  case 'history-read':
    if (!staxx_valid_path($name)) {
      staxx_reply(['ok' => false, 'error' => 'Invalid stack name.']);
    }
    $n = (int)($_POST['n'] ?? 0);
    if ($n < 1) {
      staxx_reply(['ok' => false, 'error' => 'Invalid version number.']);
    }

    $entry = null;
    foreach (staxx_record_list($name) as $v) {
      if ($v['n'] === $n) { $entry = $v; break; }
    }
    if ($entry === null) {
      staxx_reply(['ok' => false, 'error' => 'That version is no longer kept.']);
    }

    $text = staxx_record_get($name, $n);
    if ($text === null) {
      staxx_reply([
        'ok'    => false,
        'error' => 'The stored copy of that version no longer matches what was recorded, '
                 . 'so it cannot be trusted and will not be shown.',
      ]);
    }
    staxx_reply(['ok' => true, 'text' => $text, 'file' => $entry['file'], 'at' => $entry['at']]);

  /* ---- name, or clear the name of, one kept version ----
   *
   * An empty label clears it. Replies with the refreshed list in the same
   * shape 'history-list' uses, so the page never has to ask twice for it and
   * can never end up drawing a stale one after the change.
   */
  case 'history-name':
    if (!staxx_valid_path($name)) {
      staxx_reply(['ok' => false, 'error' => 'Invalid stack name.']);
    }
    $n     = (int)($_POST['n'] ?? 0);
    $label = (string)($_POST['label'] ?? '');
    if ($n < 1) {
      staxx_reply(['ok' => false, 'error' => 'Invalid version number.']);
    }
    if (!staxx_record_name($name, $n, $label, $error)) {
      staxx_reply(['ok' => false, 'error' => $error]);
    }
    $versions = array_map(fn($v) => [
      'n'    => $v['n'],
      'at'   => $v['at'],
      'size' => $v['size'],
      'file' => $v['file'],
      'name' => $v['name'],
    ], staxx_record_list($name));
    staxx_reply(['ok' => true, 'versions' => $versions]);
}

staxx_reply(['ok' => false, 'error' => 'Unknown action "'.$action.'".'], 400);
?>
