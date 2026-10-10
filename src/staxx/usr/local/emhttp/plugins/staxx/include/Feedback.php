<?PHP
/* StaXX — the bug button's server half: connecting to the feedback board,
 * sending a picture and sending a report.
 * Copyright 2026, StaXX contributors.
 *
 * WHAT THIS FILE IS FOR
 *
 * The browser never talks to the feedback board (a FeedLog server) itself.
 * It asks this file, and this file makes the calls, so the board's address
 * lives in one place and the person's access token never reaches the page.
 *
 * Connecting works like pairing a TV: StaXX asks the board for a short code,
 * the person approves it on the board's own page in their browser, and
 * StaXX, polling in the meantime, is handed a token once. The token is kept
 * in its own file under the store's config folder (mode 600) and is outside
 * the settings file, so a settings save can never overwrite or echo it. No
 * reply from here ever carries the token or the pairing secret.
 *
 * Every function returns the array the endpoint replies with: 'ok' plus
 * fields, or 'ok' => false and a sentence for the person; 'reconnect' => true
 * says the stored connection is gone and the window should offer Connect.
 * The board's contract is in plans/PLAN_213-bug-button.md.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/staxx/include/Defines.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/ImportLog.php';

if (!defined('STAXX_FEEDBACK_BASE'))  define('STAXX_FEEDBACK_BASE', 'https://staxxfb.quadcom.ca');
// Kind of report (what the window sends) => the board section of that exact name.
// There is deliberately no kind for the board's "Other" section.
if (!defined('STAXX_FEEDBACK_BOARDS')) define('STAXX_FEEDBACK_BOARDS', [
  'bug'         => 'Bug Report',
  'feature'     => 'Feature Requests',
  'improvement' => 'Improvements',
]);

/** The connection file: STAXX_FEEDBACK_FILE when a suite set it, else inside
 *  the store's config folder — known only at run time, hence a function.
 *  '' when no store has been chosen. */
function staxx_feedback_file(): string {
  if (defined('STAXX_FEEDBACK_FILE')) return (string)STAXX_FEEDBACK_FILE;
  $root = staxx_config_root();
  return $root === '' ? '' : $root.'/feedback.json';
}

function staxx_feedback_no_store(): array {
  return ['ok' => false, 'error' => 'Choose a data store first; StaXX keeps its connection to the feedback board there.'];
}

/* ------------------------------------------------------------------ state -- */

function staxx_feedback_load(): array {
  $file = staxx_feedback_file();
  if ($file === '') return [];
  $raw = @file_get_contents($file);
  $got = $raw === false ? null : json_decode($raw, true);
  return is_array($got) ? $got : [];
}

/** Written beside the target and moved into place, mode 600 from the start. */
function staxx_feedback_save(array $state): bool {
  $file = staxx_feedback_file();
  if ($file === '') return false;
  $dir = dirname($file);
  if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) return false;
  if (!staxx_atomic_write($file, json_encode($state, JSON_PRETTY_PRINT), 0600)) return false;
  @chmod($file, 0600);
  return true;
}

/** The token, or '' when there is none or its year has run out. */
function staxx_feedback_token(array $state): string {
  $token = (string)($state['token'] ?? '');
  if ($token === '') return '';
  $exp = strtotime((string)($state['expiresAt'] ?? ''));
  return ($exp !== false && $exp <= time()) ? '' : $token;
}

/** The connection forgotten; a pending pairing and the cached board id stay. */
function staxx_feedback_forget_connection(array $state): array {
  unset($state['token'], $state['expiresAt'], $state['connectionId'], $state['user']);
  return $state;
}

/* ------------------------------------------------------------------- http -- */

/**
 * One call to the board. $json is sent as the JSON body; $file (a CURLFile)
 * is sent as a multipart upload instead. Returns [status, decoded JSON or [],
 * Retry-After seconds]. A status of 0 means curl itself failed.
 */
function staxx_feedback_call(string $method, string $path, ?array $json = null, ?CURLFile $file = null, string $token = ''): array {
  $ch = curl_init(STAXX_FEEDBACK_BASE.$path);
  if ($ch === false) return [0, [], 0];

  $retry   = 0;
  $headers = ['Accept: application/json'];
  if ($token !== '') $headers[] = 'Authorization: Bearer '.$token;
  $opts = [
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_USERAGENT      => 'StaXX (Unraid plugin)',
    // Not followed: a redirect would carry the bearer token to wherever it points.
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$retry): int {
      if (stripos($line, 'Retry-After:') === 0) $retry = max(0, (int)trim(substr($line, 12)));
      return strlen($line);
    },
  ];
  if ($file !== null) {
    $opts[CURLOPT_POSTFIELDS] = ['file' => $file]; // curl sets the multipart header itself
  } elseif ($json !== null) {
    $headers[] = 'Content-Type: application/json';
    $opts[CURLOPT_POSTFIELDS] = json_encode($json);
  }
  $opts[CURLOPT_HTTPHEADER] = $headers;
  curl_setopt_array($ch, $opts);

  $body = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);

  if ($body === false) return [0, [], 0];
  $data = json_decode((string)$body, true);
  return [$code, is_array($data) ? $data : [], $retry];
}

/**
 * The reply for a call that was not a success, or null when it was (2xx).
 * The 401 case also forgets the stored connection, since a burnt, ended,
 * expired or banned token cannot be told apart and all mean "connect again".
 */
function staxx_feedback_refusal(int $code, array $data, int $retry): ?array {
  if ($code >= 200 && $code < 300) return null;
  $message = trim((string)($data['message'] ?? ''));

  if ($code === 401) {
    staxx_feedback_save(staxx_feedback_forget_connection(staxx_feedback_load()));
    return ['ok' => false, 'reconnect' => true,
      'error' => 'The connection to the feedback board has ended. Connect again to send this report; what you typed is kept.'];
  }
  if ($code === 429) {
    return ['ok' => false, 'retryAfter' => $retry,
      'error' => $message !== '' ? $message : 'The feedback board is asking for a pause. Try again in a little while.'];
  }
  if ($code === 0 || $code >= 500) {
    return ['ok' => false, 'error' => 'The feedback board could not be reached. Check this server can reach the internet, then try again.'];
  }
  return ['ok' => false, 'error' => $message !== '' ? $message : 'The feedback board did not accept that. Try again.'];
}

/** The stored token for an authenticated call, or the refusal to reply with. */
function staxx_feedback_need_token(?string &$token): ?array {
  if (staxx_feedback_file() === '') return staxx_feedback_no_store();
  $token = staxx_feedback_token(staxx_feedback_load());
  if ($token === '') {
    return ['ok' => false, 'reconnect' => true,
      'error' => 'Connect to the feedback board first to send this report; what you typed is kept.'];
  }
  return null;
}

/* ----------------------------------------------------------------- status -- */

function staxx_feedback_status(): array {
  $state = staxx_feedback_load();
  $reply = ['ok' => true, 'connected' => false];

  if (staxx_feedback_token($state) !== '') {
    $reply['connected'] = true;
    $reply['name']      = (string)($state['user']['name'] ?? '');
    $reply['email']     = (string)($state['user']['email'] ?? '');
    $reply['expiresAt'] = (string)($state['expiresAt'] ?? '');
  }
  $p = $state['pending'] ?? null;
  if (is_array($p) && ($t = strtotime((string)($p['expiresAt'] ?? ''))) !== false && $t > time()) {
    $reply['pending'] = [
      'userCode'                => (string)($p['userCode'] ?? ''),
      'verificationUriComplete' => (string)($p['verificationUriComplete'] ?? ''),
      'interval'                => (int)($p['interval'] ?? 5),
      'expiresAt'               => (string)$p['expiresAt'],
    ];
  }
  return $reply;
}

/* ---------------------------------------------------------------- connect -- */

function staxx_feedback_connect(): array {
  if (staxx_feedback_file() === '') return staxx_feedback_no_store();

  $vars  = @parse_ini_file('/var/local/emhttp/var.ini') ?: [];
  $label = trim((string)($vars['NAME'] ?? ''));
  if ($label === '') $label = 'Unraid server';
  $label = mb_substr($label, 0, 80);

  [$code, $data, $retry] = staxx_feedback_call('POST', '/api/connect/start', ['app' => 'StaXX', 'label' => $label]);
  if ($code !== 201 && $code !== 200) {
    return staxx_feedback_refusal($code, $data, $retry)
      ?? ['ok' => false, 'error' => 'The feedback board did not accept that. Try again.'];
  }
  $deviceCode = (string)($data['deviceCode'] ?? '');
  $complete   = (string)($data['verificationUriComplete'] ?? '');
  if ($deviceCode === '' || $complete === '') {
    return ['ok' => false, 'error' => 'The feedback board gave an answer StaXX could not use. Try again in a little while.'];
  }

  $pending = [
    'deviceCode'              => $deviceCode,
    'userCode'                => (string)($data['userCode'] ?? ''),
    'verificationUriComplete' => $complete,
    'interval'                => max(1, (int)($data['interval'] ?? 5)),
    'expiresAt'               => (string)($data['expiresAt'] ?? gmdate('c', time() + (int)($data['expiresIn'] ?? 600))),
  ];
  $state = staxx_feedback_load();
  $state['pending'] = $pending;
  if (!staxx_feedback_save($state)) {
    return ['ok' => false, 'error' => 'StaXX could not save the connection in its data store. Check the store is writable, then try again.'];
  }
  unset($pending['deviceCode']);
  return ['ok' => true] + $pending;
}

function staxx_feedback_poll(): array {
  if (staxx_feedback_file() === '') return staxx_feedback_no_store();
  $state      = staxx_feedback_load();
  $deviceCode = (string)($state['pending']['deviceCode'] ?? '');
  if ($deviceCode === '') return ['ok' => true, 'status' => 'expired'];

  [$code, $data, $retry] = staxx_feedback_call('POST', '/api/connect/poll', ['deviceCode' => $deviceCode]);
  if ($code === 429) return ['ok' => true, 'status' => 'pending', 'interval' => max(5, $retry)]; // too quick: wait and carry on
  if ($code !== 200) {
    return staxx_feedback_refusal($code, $data, $retry)
      ?? ['ok' => false, 'error' => 'The feedback board did not accept that. Try again.'];
  }

  $status = (string)($data['status'] ?? '');
  if ($status === 'pending') return ['ok' => true, 'status' => 'pending', 'interval' => max(1, (int)($data['interval'] ?? 5))];

  if ($status === 'connected' && (string)($data['token'] ?? '') !== '') {
    unset($state['pending']);
    $state['token']        = (string)$data['token'];
    $state['expiresAt']    = (string)($data['expiresAt'] ?? '');
    $state['connectionId'] = (string)($data['connectionId'] ?? '');
    $state['user']         = ['name'  => (string)($data['user']['name'] ?? ''),
                              'email' => (string)($data['user']['email'] ?? '')];
    // The board hands the token over exactly once, so a failed save is final.
    if (!staxx_feedback_save($state)) {
      return ['ok' => false, 'error' => 'StaXX could not save the connection in its data store. Check the store is writable, then connect again.'];
    }
    return ['ok' => true, 'status' => 'connected', 'name' => $state['user']['name']];
  }

  // Denied, expired, or anything unrecognised: the pairing is finished either way.
  unset($state['pending']);
  staxx_feedback_save($state);
  return ['ok' => true, 'status' => $status === 'denied' ? 'denied' : 'expired'];
}

/** Ends the token on the board (result ignored: it may already be dead) and forgets it here. */
function staxx_feedback_disconnect(): array {
  $state = staxx_feedback_load();
  $token = (string)($state['token'] ?? '');
  if ($token !== '') staxx_feedback_call('DELETE', '/api/connect/current', null, null, $token);
  staxx_feedback_save(staxx_feedback_forget_connection($state));
  return ['ok' => true];
}

/* ---------------------------------------------------------------- picture -- */

/**
 * Sends one picture, handed over as base64, and returns its key for the
 * card's markdown. The real type is read from the picture's first bytes; the
 * type the browser claimed ($type) is accepted for the endpoint's sake and ignored.
 */
function staxx_feedback_upload(string $b64, string $type = ''): array {
  $token = '';
  if (($refused = staxx_feedback_need_token($token)) !== null) return $refused;

  $b64 = preg_replace('/\s+/', '', preg_replace('#^data:[^,]*,#', '', $b64));
  $raw = $b64 === '' ? false : base64_decode($b64, true);
  if ($raw === false || $raw === '') return ['ok' => false, 'error' => 'That picture could not be read. Paste it again.'];
  if (strlen($raw) > 5 * 1024 * 1024) return ['ok' => false, 'error' => 'That picture is bigger than 5 MB. Paste a smaller one.'];

  if (strncmp($raw, "\x89PNG\r\n\x1a\n", 8) === 0)                          { $ext = 'png';  $mime = 'image/png'; }
  elseif (strncmp($raw, "\xFF\xD8\xFF", 3) === 0)                           { $ext = 'jpg';  $mime = 'image/jpeg'; }
  elseif (strncmp($raw, 'GIF87a', 6) === 0 || strncmp($raw, 'GIF89a', 6) === 0) { $ext = 'gif';  $mime = 'image/gif'; }
  elseif (strncmp($raw, 'RIFF', 4) === 0 && substr($raw, 8, 4) === 'WEBP') { $ext = 'webp'; $mime = 'image/webp'; }
  else return ['ok' => false, 'error' => 'Only PNG, JPEG, GIF and WebP pictures can be attached.'];

  $tmp = tempnam(sys_get_temp_dir(), 'staxxfb');
  if ($tmp === false) return ['ok' => false, 'error' => 'StaXX could not prepare that picture. Try again.'];
  try {
    if (@file_put_contents($tmp, $raw) !== strlen($raw)) return ['ok' => false, 'error' => 'StaXX could not prepare that picture. Try again.'];
    [$code, $data, $retry] = staxx_feedback_call('POST', '/api/upload', null, new CURLFile($tmp, $mime, 'screenshot.'.$ext), $token);
  } finally {
    @unlink($tmp);
  }
  if (($refused = staxx_feedback_refusal($code, $data, $retry)) !== null) return $refused;

  $key = (string)($data['key'] ?? '');
  if ($key === '') return ['ok' => false, 'error' => 'The feedback board did not accept that picture. Try again.'];
  return ['ok' => true, 'key' => $key];
}

/* ------------------------------------------------------------------- send -- */

/** The Unraid release, from its own version file; '' when unreadable. */
function staxx_feedback_unraid_version(): string {
  $raw = @file_get_contents('/etc/unraid-version');
  return ($raw !== false && preg_match('/version="([^"]*)"/', $raw, $m)) ? $m[1] : '';
}

/** The id of the board section named $name, remembered per name after the first lookup. '' with $refusal set to the reply when it cannot be had. */
function staxx_feedback_board_id(array &$state, string $token, string $name, ?array &$refusal): string {
  $refusal = null;
  if ((string)($state['boardIds'][$name] ?? '') !== '') return (string)$state['boardIds'][$name];

  [$code, $data, $retry] = staxx_feedback_call('GET', '/api/boards', null, null, $token);
  if (($refusal = staxx_feedback_refusal($code, $data, $retry)) !== null) return '';
  foreach ((array)($data['data'] ?? []) as $board) {
    if (is_array($board) && ($board['name'] ?? null) === $name && (string)($board['id'] ?? '') !== '') {
      $state['boardIds'][$name] = (string)$board['id'];
      staxx_feedback_save($state);
      return $state['boardIds'][$name];
    }
  }
  $refusal = ['ok' => false, 'error' => 'The feedback board has no "'.$name.'" section to send this to. Please tell the StaXX maintainer.'];
  return '';
}

/** Sends a report to the board section for $kind ('bug', 'feature' or 'improvement'). */
function staxx_feedback_send(string $title, string $content, string $screen = '', string $kind = 'bug'): array {
  if (!isset(STAXX_FEEDBACK_BOARDS[$kind])) return ['ok' => false, 'error' => 'That kind of report is not one StaXX can send. Choose Bug Report, Feature Requests or Improvements.'];
  $boardName = STAXX_FEEDBACK_BOARDS[$kind];
  $token = '';
  if (($refused = staxx_feedback_need_token($token)) !== null) return $refused;

  $title   = mb_substr(trim($title), 0, 200);
  $content = trim($content);
  if ($title === '')   return ['ok' => false, 'error' => 'Give the report a title.'];
  if ($content === '') return ['ok' => false, 'error' => 'Say what happened before sending.'];
  $screen = mb_substr(trim($screen), 0, 80);
  if ($screen === '') $screen = 'Stacks list';

  $state   = staxx_feedback_load();
  $boardId = staxx_feedback_board_id($state, $token, $boardName, $refusal);
  if ($boardId === '') return $refusal;

  $facts  = staxx_manifest_facts();
  $line   = 'StaXX '.($facts['version'] !== '' ? $facts['version'] : 'unknown')
          . ' · Unraid '.(($u = staxx_feedback_unraid_version()) !== '' ? $u : 'unknown')
          . ' · Screen: '.$screen;
  $footer = "\n\n---\n".$line;
  // The card is capped at 10000 characters; the footer is kept whole and the typed part is trimmed.
  $content = mb_substr($content, 0, max(0, 10000 - mb_strlen($footer))).$footer;

  [$code, $data, $retry] = staxx_feedback_call('POST', '/api/posts',
    ['title' => $title, 'content' => $content, 'boardId' => $boardId], null, $token);

  if ($code === 404 || ($code === 400 && stripos((string)($data['message'] ?? ''), 'board') !== false)) {
    // The stored id for this section may be stale: look it up again next time.
    unset($state['boardIds'][$boardName]);
    staxx_feedback_save($state);
  }
  if (($refused = staxx_feedback_refusal($code, $data, $retry)) !== null) return $refused;

  $slug = (string)($data['slug'] ?? '');
  if ($slug === '') return ['ok' => false, 'error' => 'The feedback board did not confirm the report. Try again.'];
  // 'footer' is the line just added, so the sent view shows exactly what the card carries.
  // 'id' is what files are attached to; the address uses the slug.
  return ['ok' => true, 'id' => (string)($data['id'] ?? ''), 'url' => STAXX_FEEDBACK_BASE.'/p/'.$slug, 'footer' => $line];
}

/* --------------------------------------------------------------- details -- */
/* The diagnostic files offered with a report (PLAN_213 phase 2): gathered here,
 * cleaned of anything private in one pass, shown to the person as segments so
 * the preview can mark each replacement, and uploaded as the person left them. */

const STAXX_FEEDBACK_ITEM_MAX   = 1048576; // per item, read from the server
const STAXX_FEEDBACK_ATTACH_MAX = 5242880; // FeedLog's own limit for one file
const STAXX_FEEDBACK_FILES = ['compose.txt', 'last-start-or-update.log', 'container-logs.log', 'page-errors.txt', 'versions.txt', 'import.log', 'self-test.txt'];

/** [[text, byte offset], …] for every match of $re (group $g) in $text. */
function staxx_feedback_find(string $re, string $text, int $g = 0): array {
  if (@preg_match_all($re, $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) return [];
  $out = [];
  foreach ($m as $set) if (isset($set[$g]) && $set[$g][1] >= 0) $out[] = [$set[$g][0], $set[$g][1]];
  return $out;
}

/**
 * Takes [key => text] and returns [key => segments]; a segment is ['t' => text]
 * or ['tag' => '<secret 1>', 'kind' => 'secret', 'orig' => the value].
 * One table serves every item, so a value has the same tag wherever it appears;
 * tags are numbered per kind in order of first appearance. The rules run in
 * order and a later rule never re-matches text an earlier one replaced.
 */
function staxx_feedback_clean(array $items): array {
  $words = ['secret' => 'secret', 'email' => 'email', 'home' => 'home address', 'address' => 'address', 'mac' => 'hardware address', 'host' => 'host name', 'maybe' => 'possible address'];
  $table = []; // kind, NUL, value => tag
  $count = [];
  $out   = [];
  $ref   = '/^["\']?\$\{[^}]*\}["\']?$/'; // a ${…} reference names a variable and holds nothing
  // PW and PWD count only as a whole part of the name, so UPWARD is left alone.
  $sens  = '/PASS|SECRET|TOKEN|KEY|AUTH|CREDENTIAL|PRIVATE|SALT|COOKIE|SESSION|(?<![A-Za-z0-9])PWD?(?![A-Za-z0-9])/i';
  $skip  = fn(string $v): bool => $v === '' || preg_match($ref, $v) === 1 || preg_match('/^([|>][-+]?|\[\]|\{\})$/', $v) === 1;

  // Returns $acc with the candidates that overlap nothing already in it added.
  $merge = function (array $acc, array $cands): array {
    usort($cands, fn($a, $b) => $a[0] <=> $b[0]);
    $j = 0; $n = count($acc); $keep = []; $last = 0;
    foreach ($cands as $c) {
      while ($j < $n && $acc[$j][1] <= $c[0]) $j++;
      if ($j < $n && $acc[$j][0] < $c[1]) continue;
      if ($c[0] < $last) continue; // overlaps a candidate just kept
      $last = $c[1];
      $keep[] = $c;
    }
    if ($keep) { $acc = array_merge($acc, $keep); usort($acc, fn($a, $b) => $a[0] <=> $b[0]); }
    return $acc;
  };
  $accs  = []; // per item: accepted [start, end, kind, value], sorted by start
  $texts = [];
  $found = []; // kind, NUL, value => [kind, value], every value any rule matched

  // Pass 1: the rules, item by item.
  foreach ($items as $key => $text) {
    $text = (string)$text;
    $acc  = [];
    $add  = function (array $cands) use (&$acc, $merge): void { $acc = $merge($acc, $cands); };

    // 1. A compose line marked -!S: its value.
    $c = [];
    foreach (staxx_feedback_find('/^([ \t]*(?:-[ \t]+)?[^\s:=#][^\s:=]*[ \t]*[:=][ \t]*)([^\r\n]*?)([ \t]+#[^\r\n]*-!S[^\r\n]*)(?=\r?$)/m', $text, 2) as [$v, $o]) {
      if ($v !== '') $c[] = [$o, $o + strlen($v), 'secret', $v];
    }
    $add($c);

    // 2. The value of a key that names a secret: KEY: value, KEY=value, - KEY=value.
    $c = [];
    if (@preg_match_all('/^([ \t]*(?:-[ \t]+)?(["\']?))([A-Za-z_][\w.-]*)((?:[ \t]*=[ \t]*)|(?:[ \t]*:(?=[ \t]|\r|$)[ \t]*))([^\r\n]*)/m', $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
      foreach ($m as $set) {
        if (!preg_match($sens, $set[3][0])) continue;
        $v = $set[5][0]; $o = $set[5][1];
        if (preg_match('/^("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\']|\'\')*\')/', $v, $q)) {
          $v = $q[1];
        } else {
          $v = preg_replace('/[ \t]+#.*$/', '', $v);
          $qc = $set[2][0];
          if ($qc !== '' && substr($v, -1) === $qc) $v = substr($v, 0, -1); // the list item's own closing quote
        }
        $v = rtrim($v);
        if (!$skip($v)) $c[] = [$o, $o + strlen($v), 'secret', $v];
      }
    }
    $add($c);
    $c = [];
    foreach (staxx_feedback_find('/(?<![\w.-])([A-Za-z_][\w.-]*)=("[^"\r\n]*"|\'[^\'\r\n]*\'|[^\s"\']+)/', $text) as [$v, $o]) {
      $eq = strpos($v, '=');
      if (!preg_match($sens, substr($v, 0, $eq))) continue;
      $val = substr($v, $eq + 1);
      if (!$skip($val)) $c[] = [$o + $eq + 1, $o + strlen($v), 'secret', $val];
    }
    $add($c);

    // 3. user:password in scheme://user:password@host.
    $c = [];
    if (@preg_match_all('#[A-Za-z][A-Za-z0-9+.-]*://([^\s/:@]+:([^\s/@]+))@#', $text, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
      foreach ($m as $set) {
        if (!preg_match($ref, $set[2][0])) $c[] = [$set[1][1], $set[1][1] + strlen($set[1][0]), 'secret', $set[1][0]];
      }
    }
    $add($c);

    // 4. Email addresses.
    $c = [];
    // user@8.8.8.8 is a login to an address, left for rule 5 rather than called an email.
    foreach (staxx_feedback_find('/[A-Za-z0-9._%+-]+@(?!\d{1,3}(?:\.\d{1,3}){3}(?![\d-]|\.[\w-]))[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+/', $text) as [$v, $o]) {
      $c[] = [$o, $o + strlen($v), 'email', $v];
    }
    $add($c);

    // 5. IPv4 addresses: home network ranges, then any other except the unspecific ones.
    $c = [];
    foreach (staxx_feedback_find('/(?<![\d.])(?:\d{1,3}\.){3}\d{1,3}(?!\.?\d)/', $text) as [$v, $o]) {
      $p = array_map('intval', explode('.', $v));
      if (max($p) > 255 || $v === '0.0.0.0' || $v === '255.255.255.255' || $p[0] === 127) continue;
      $home = $p[0] === 10 || ($p[0] === 172 && $p[1] >= 16 && $p[1] <= 31) || ($p[0] === 192 && $p[1] === 168)
           || ($p[0] === 100 && $p[1] >= 64 && $p[1] <= 127) || ($p[0] === 169 && $p[1] === 254);
      if ($home) { $c[] = [$o, $o + strlen($v), 'home', $v]; continue; }
      // A public-looking number is often a version (nextcloud 29.0.4.1), so the text
      // around it decides: version cue keeps it, address cue hides it for sure, an image
      // tag's colon keeps it, anything else is only a possible address.
      $b = substr($text, max(0, $o - 40), min(40, $o));
      $b = preg_replace('/^.*[\r\n]/s', '', $b); // this line only
      // A product/version pair (Chrome/131.0.6778.85) is a version; a single slash only, so http:// stays an address cue.
      if (preg_match('/[vV]$/', $b) || preg_match('/[A-Za-z][\w.-]*\/$/', $b) || preg_match('/(?<![A-Za-z0-9_])(?:version|ver)[ \t]*[:=]?[ \t]*$/i', $b)) continue;
      $after = substr($text, $o + strlen($v), 8);
      if (preg_match('/(?:@|\/\/|=[ \t]*)$/', $b)
          || preg_match('/(?<![A-Za-z0-9_])(?:ip|addr|address|host|server|peer|client|gateway|dns|proxy|remote)[ \t]*[:=]?[ \t]*$/i', $b)
          || preg_match('/^(?::\d|\/\d{1,2}(?!\d))/', $after)) {
        $kind = 'address';
      } elseif (preg_match('/[\w.-]:$/', $b)) {
        continue;
      } else {
        $kind = 'maybe';
      }
      $c[] = [$o, $o + strlen($v), $kind, $v];
    }
    $add($c);

    // 5b. IPv6 addresses. A run of hex digits and colons is only a candidate; filter_var
    // decides, which keeps times, MACs, port pairs and image tags out.
    $c = [];
    foreach (staxx_feedback_find('/(?<![\w:.])[0-9A-Fa-f:]{2,}(?![\w:]|\.\w)/', $text) as [$v, $o]) {
      if (substr_count($v, ':') < 2 || $v === '::' || $v === '::1') continue;
      if (filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) continue;
      $home = preg_match('/^(?:f[cd]|fe[89ab])/i', $v) === 1; // fc00::/7 and fe80::/10
      $c[] = [$o, $o + strlen($v), $home ? 'home' : 'address', $v];
    }
    $add($c);

    // 6. MAC addresses.
    $c = [];
    foreach (staxx_feedback_find('/(?<![0-9A-Fa-f:-])(?:[0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}(?![0-9A-Fa-f:-])/', $text) as [$v, $o]) {
      $c[] = [$o, $o + strlen($v), 'mac', $v];
    }
    $add($c);

    // 6b. Computer names on the home network: names under a private suffix, and the
    // server's own name. Public domain names are left alone.
    $c = [];
    foreach (staxx_feedback_find('/(?<![\w.@-])(?:[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?\.)+(?:home\.arpa|lan|local|home|internal|localdomain|intranet|private)(?![\w-]|\.[A-Za-z0-9])/i', $text) as [$v, $o]) {
      $c[] = [$o, $o + strlen($v), 'host', $v];
    }
    $me = (string)gethostname();
    if (strlen($me) >= 3) {
      foreach (staxx_feedback_find('/(?<![\w-])'.preg_quote($me, '/').'(?![\w-])/i', $text) as [$v, $o]) {
        $c[] = [$o, $o + strlen($v), 'host', $v];
      }
    }
    $add($c);

    // 7. Anything that looks like a generated key, except an image digest.
    $c = [];
    foreach (staxx_feedback_find('#(?<![A-Za-z0-9+/_=-])[A-Za-z0-9+/_=-]{24,}(?![A-Za-z0-9+/_=-])#', $text) as [$v, $o]) {
      if (!preg_match('/[A-Za-z]/', $v) || !preg_match('/\d/', $v)) continue;
      if ($o >= 7 && substr($text, $o - 7, 7) === 'sha256:') continue;
      if ($v[0] === '/' || substr_count($v, '/') >= 3) continue; // a path, not a key
      $c[] = [$o, $o + strlen($v), 'secret', $v];
    }
    $add($c);

    $accs[$key] = $acc; $texts[$key] = $text;
    foreach ($acc as [, , $kind, $val]) {
      if (strlen($val) >= 4) $found[$kind."\0".$val] = [$kind, $val];
    }
  }

  // Pass 2: a value found anywhere is hidden everywhere it appears, bare or not.
  // A value found as an address anywhere is an address everywhere, never also a maybe.
  // Longer values first, so one that contains another wins.
  // Matches already accepted as a maybe are relabelled too, since they sit in $accs.
  foreach ($found as $id => [$kind, $val]) {
    if ($kind === 'address') unset($found['maybe'."\0".$val]);
  }
  foreach ($accs as $key => $acc) {
    foreach ($acc as $i => [, , $kind, $val]) {
      if ($kind === 'maybe' && isset($found['address'."\0".$val])) $accs[$key][$i][2] = 'address';
    }
  }
  uasort($found, fn($a, $b) => strlen($b[1]) <=> strlen($a[1]));
  foreach ($accs as $key => $acc) {
    $text = $texts[$key];
    $c = [];
    foreach ($found as [$kind, $val]) {
      for ($o = strpos($text, $val); $o !== false; $o = strpos($text, $val, $o + strlen($val))) {
        $c[] = [$o, $o + strlen($val), $kind, $val];
      }
    }
    // One merge per value, longest first, so the order above decides overlaps.
    $byLen = [];
    foreach ($c as $x) $byLen[strlen($x[3])][] = $x;
    krsort($byLen);
    foreach ($byLen as $group) $acc = $merge($acc, $group);
    $accs[$key] = $acc;
  }

  // Emit. Numbers are handed out here, in reading order, across every item.
  foreach ($accs as $key => $acc) {
    $text = $texts[$key];
    $segs = []; $at = 0;
    foreach ($acc as [$s, $e, $kind, $val]) {
      if ($s > $at) $segs[] = ['t' => substr($text, $at, $s - $at)];
      $id = $kind."\0".$val;
      if (!isset($table[$id])) {
        $count[$kind] = ($count[$kind] ?? 0) + 1;
        $table[$id] = '<'.$words[$kind].' '.$count[$kind].'>';
      }
      $segs[] = ['tag' => $table[$id], 'kind' => $kind, 'orig' => $val];
      $at = $e;
    }
    if ($at < strlen($text)) $segs[] = ['t' => substr($text, $at)];
    $out[$key] = $segs;
  }
  return $out;
}

/** The first STAXX_FEEDBACK_ITEM_MAX bytes of a file, '' when unreadable. */
function staxx_feedback_head(string $file): string {
  $raw = @file_get_contents($file, false, null, 0, STAXX_FEEDBACK_ITEM_MAX);
  return $raw === false ? '' : $raw;
}

/** The last STAXX_FEEDBACK_ITEM_MAX bytes of a text. */
function staxx_feedback_tail(string $text): string {
  return strlen($text) > STAXX_FEEDBACK_ITEM_MAX ? substr($text, -STAXX_FEEDBACK_ITEM_MAX) : $text;
}

/** What happened the last time the stack started or updated: its newest job log from the last hour, '' when none. */
function staxx_feedback_job_log(string $rel): string {
  $project = staxx_project_name(staxx_path_leaf($rel));
  $files = [];
  foreach (glob(STAXX_JOB_DIR.'/*.log') ?: [] as $f) {
    $t = @filemtime($f);
    if ($t !== false && $t >= time() - 3600) $files[$f] = $t;
  }
  arsort($files);
  foreach (array_keys($files) as $f) {
    $h = @fopen($f, 'rb');
    if ($h === false) continue;
    $first = (string)fgets($h, 4096);
    fclose($h);
    $mine = strpos($first, $rel) !== false
         || ($project !== '' && preg_match('/(?<![a-z0-9_-])'.preg_quote($project, '/').'(?![a-z0-9_-])/i', $first) === 1);
    if (!$mine) continue;
    $raw = @file_get_contents($f);
    if ($raw === false) continue;
    $raw = preg_replace('/^[^\n]*'.preg_quote(STAXX_JOB_END, '/').'[^\n]*\n?/m', '', $raw);
    return staxx_feedback_tail((string)$raw);
  }
  return '';
}

/** The last 200 lines of each of the stack's containers, under a heading each; '' when it has none. */
function staxx_feedback_container_logs(string $rel): string {
  $docker = escapeshellarg(staxx_docker_bin());
  $parts  = [];
  foreach (staxx_project_containers($rel) as $c) {
    $name = (string)$c['name'];
    if ($name === '') continue;
    $parts[] = '== '.$name." ==\n".rtrim(staxx_sh($docker.' logs --tail 200 --timestamps '.escapeshellarg($name).' 2>&1', 10));
  }
  return $parts ? staxx_feedback_tail(implode("\n\n", $parts)."\n") : '';
}

function staxx_feedback_versions(string $browser): string {
  $docker = trim(staxx_sh(escapeshellarg(staxx_docker_bin()).' version --format '.escapeshellarg('{{.Server.Version}}'), 10));
  $comp   = staxx_compose();
  $cv     = (string)($comp['version'] ?? '');
  if ($cv !== '' && is_file('/usr/local/lib/docker/cli-plugins/docker-compose.staxx')) $cv .= ' (installed by StaXX)';
  $facts  = staxx_manifest_facts();
  $browser = trim(preg_replace('/\s+/', ' ', mb_substr($browser, 0, 300)));
  $unraid  = staxx_feedback_unraid_version();
  return 'Docker: '.($docker !== '' ? $docker : 'unknown')."\n"
       . 'Docker Compose: '.($cv !== '' ? $cv : 'unknown')."\n"
       . 'StaXX: '.(($facts['version'] ?? '') !== '' ? $facts['version'] : 'unknown')."\n"
       . 'Unraid: '.($unraid !== '' ? $unraid : 'unknown')."\n"
       . 'Browser: '.($browser !== '' ? $browser : 'unknown')."\n";
}

/**
 * The files offered with a report, cleaned and split into segments. $stack is
 * the stack's path ('' or invalid: only the page errors and versions are
 * offered); $errors is the page's own error list, sent as it stands.
 */
function staxx_feedback_details(string $stack, string $errors, string $browser): array {
  $texts = []; $labels = []; $files = [];
  $add = function (string $key, string $file, string $label, string $text) use (&$texts, &$labels, &$files): void {
    if ($text === '') return;
    $texts[$key] = $text; $labels[$key] = $label; $files[$key] = $file;
  };

  if (staxx_valid_path($stack) && is_dir(staxx_stack_dir($stack))) {
    $leaf = staxx_path_leaf($stack);
    $cf   = staxx_find_compose_file(staxx_stack_dir($stack));
    $add('compose', 'compose.txt', $leaf.'’s compose file', $cf === '' ? '' : staxx_feedback_head($cf));
    $add('job', 'last-start-or-update.log', 'What happened the last time '.$leaf.' started or updated', staxx_feedback_job_log($stack));
    $add('logs', 'container-logs.log', 'The last 200 lines of '.$leaf.'’s container logs', staxx_feedback_container_logs($stack));
  }
  if (trim($errors) !== '') {
    $n = count(array_filter(preg_split('/\R/', $errors), fn($l) => trim($l) !== ''));
    $add('errors', 'page-errors.txt', 'Errors this page has run into ('.$n.')', staxx_feedback_tail($errors));
  }
  // Offered with or without a stack: an Import that failed has no stack yet.
  $add('import', 'import.log', 'What happened the last few times you used Import', staxx_import_log_recent(7));
  $add('versions', 'versions.txt', 'Docker and Docker Compose versions', staxx_feedback_versions($browser));
  $add('selftest', 'self-test.txt', 'StaXX self-test', staxx_feedback_selftest());

  $clean = staxx_feedback_clean($texts);
  $items = [];
  foreach (array_keys($texts) as $key) {
    $items[] = ['key' => $key, 'label' => $labels[$key], 'file' => $files[$key], 'segments' => $clean[$key]];
  }
  return ['ok' => true, 'items' => $items];
}

/** The Settings page's self-test as plain lines, one check per line, with a
 *  failing one marked. It runs no command, so it cannot hang a report, and it is
 *  already built to leave out the server's name and address. */
function staxx_feedback_selftest(): string {
  $out = '';
  foreach (staxx_selftest() as $label => $e) {
    if ($label === 'endpoint reachable') continue;   // "you are reading its reply" is true only on the page
    $out .=$label.': '.($e['detail'] ?? '').(($e['state'] ?? '') === 'bad' ? '   <- needs attention' : '')."\n";
  }
  return $out;
}

/** Uploads one of the seven files to the card $postId (its id, not its slug). */
function staxx_feedback_attach(string $postId, string $file, string $text): array {
  $token = '';
  if (($refused = staxx_feedback_need_token($token)) !== null) return $refused;

  if (!in_array($file, STAXX_FEEDBACK_FILES, true)) return ['ok' => false, 'error' => 'That file is not one StaXX attaches to a report.'];
  if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $postId)) return ['ok' => false, 'error' => 'That report could not be found to attach the file to.'];
  if ($text === '') return ['ok' => false, 'error' => 'That file is empty, so it was not attached.'];
  if (strlen($text) > STAXX_FEEDBACK_ATTACH_MAX) return ['ok' => false, 'error' => 'That file is bigger than 5 MB, so it was not attached.'];

  $tmp = tempnam(sys_get_temp_dir(), 'staxxfb');
  if ($tmp === false) return ['ok' => false, 'error' => 'StaXX could not prepare that file. Try again.'];
  try {
    if (@file_put_contents($tmp, $text) !== strlen($text)) return ['ok' => false, 'error' => 'StaXX could not prepare that file. Try again.'];
    [$code, $data, $retry] = staxx_feedback_call('POST', '/api/posts/'.$postId.'/attachments', null, new CURLFile($tmp, 'text/plain', $file), $token);
  } finally {
    @unlink($tmp);
  }
  if (($refused = staxx_feedback_refusal($code, $data, $retry)) !== null) return $refused;
  return ['ok' => true, 'id' => (string)($data['id'] ?? ''), 'filename' => (string)($data['filename'] ?? $file),
    'size' => (int)($data['size'] ?? strlen($text))];
}
?>
