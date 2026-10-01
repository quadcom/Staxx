<?php
/* PLAN_213 — include/Feedback.php, the bug button's server half, against a
 * stand-in for the feedback board: connecting (start, pending, connected once),
 * the token file's mode, the picture upload, the card and its footer, and the
 * board's refusals (401 ends the connection, 429 passes its own message on).
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. Needs nothing in
 * the config: it points the board address at a throwaway `php -S` on
 * 127.0.0.1 and the connection file at a fresh /tmp folder, both defined
 * before Feedback.php loads. It never touches the real STORE_ROOT, settings
 * or connection file, and nothing leaves this machine. The stand-in server
 * and the temporary folder are removed on every exit path.
 *
 *     pscp tests/server/feedback.php root@<box>:/tmp/
 *     plink … 'php /tmp/feedback.php'
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */

$dir = sys_get_temp_dir().'/zzfeedback-'.getmypid();
@mkdir($dir, 0700, true);

// A free port: ask the system for one, then let it go.
$sock = stream_socket_server('tcp://127.0.0.1:0', $en, $es);
$port = (int)explode(':', stream_socket_get_name($sock, false))[1];
fclose($sock);

define('STAXX_FEEDBACK_BASE', 'http://127.0.0.1:'.$port);
define('STAXX_FEEDBACK_FILE', $dir.'/feedback.json');

$server = null;
function cleanup(): void {
  global $server, $dir;
  if (is_resource($server)) { proc_terminate($server); proc_close($server); $server = null; }
  foreach (glob($dir.'/*') ?: [] as $f) @unlink($f);
  foreach (glob($dir.'/.*') ?: [] as $f) if (is_file($f)) @unlink($f);
  @rmdir($dir);
}
register_shutdown_function('cleanup');

require_once '/usr/local/emhttp/plugins/staxx/include/Feedback.php';

$fails = 0;
function check(string $what, bool $ok, ?string $detail = null): void {
  global $fails;
  if (!$ok) $fails++;
  printf("%-4s %s\n", $ok ? 'ok' : 'FAIL', $what);
  if (!$ok && $detail !== null) echo '     got: '.$detail."\n";
}
function finish(): void { global $fails; exit($fails > 0 ? 1 : 0); }

// Every reply is kept so the secrets can be searched for in all of them at the end.
$replies = [];
function r(array $reply): array { global $replies; $replies[] = json_encode($reply); return $reply; }

/* ---------------------------------------------------- the stand-in board -- */

$router = $dir.'/router.php';
file_put_contents($router, <<<'PHPEOF'
<?php
$d = getenv('ZZ_FB_DIR');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$m = $_SERVER['REQUEST_METHOD'];
function out(int $code, $data, array $h = []): void {
  http_response_code($code);
  foreach ($h as $x) header($x);
  header('Content-Type: application/json');
  echo json_encode($data);
  exit;
}
$mode = trim((string)@file_get_contents($d.'/mode'));
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) $body = [];

if ($path === '/api/connect/start' && $m === 'POST') {
  out(201, ['deviceCode' => 'DEVICE-CODE-SECRET-0123', 'userCode' => 'WDJB-MJHT',
    'verificationUri' => 'http://x/connect', 'verificationUriComplete' => 'http://x/connect?code=WDJB-MJHT',
    'interval' => 5, 'expiresIn' => 600, 'expiresAt' => gmdate('c', time() + 600)]);
}
if ($path === '/api/connect/poll' && $m === 'POST') {
  if (($body['deviceCode'] ?? '') !== 'DEVICE-CODE-SECRET-0123') out(200, ['status' => 'expired']);
  $n = (int)@file_get_contents($d.'/polls'); file_put_contents($d.'/polls', (string)($n + 1));
  if ($n === 0) out(200, ['status' => 'pending', 'interval' => 5]);
  if ($n === 1) out(200, ['status' => 'connected', 'token' => 'TOKEN-SECRET-123',
    'expiresAt' => gmdate('c', time() + 86400 * 365), 'connectionId' => 'c1',
    'user' => ['id' => 'u1', 'name' => 'Test Person', 'email' => 'test@example.com']]);
  out(200, ['status' => 'expired']);
}
if ($path === '/api/connect/current' && $m === 'DELETE') {
  file_put_contents($d.'/deleted', $auth);
  http_response_code(204); exit;
}
if ($auth !== 'Bearer TOKEN-SECRET-123') out(401, ['statusCode' => 401, 'message' => 'Authentication required']);
if ($path === '/api/upload' && $m === 'POST') {
  if (!isset($_FILES['file'])) out(400, ['message' => 'No file.']);
  file_put_contents($d.'/upload-name', $_FILES['file']['name'].'|'.$_FILES['file']['type']);
  out(200, ['key' => 'uploads/org/abc.png']);
}
if ($path === '/api/boards' && $m === 'GET') {
  out(200, ['data' => [['id' => 'board-0', 'name' => 'Ideas'], ['id' => 'board-1', 'name' => 'Bug Report'],
    ['id' => 'board-2', 'name' => 'Feature Requests'], ['id' => 'board-3', 'name' => 'Improvements']]]);
}
if ($path === '/api/posts' && $m === 'POST') {
  if ($mode === '401') out(401, ['statusCode' => 401, 'message' => 'Authentication required']);
  if ($mode === '429') out(429, ['statusCode' => 429, 'message' => 'You have sent 10 reports this hour. Try again later.'], ['Retry-After: 60']);
  file_put_contents($d.'/lastpost.json', json_encode($body));
  out(201, ['id' => 'p1', 'slug' => 'abc123']);
}
if (preg_match('#^/api/posts/([A-Za-z0-9_-]+)/attachments$#', $path, $mm) && $m === 'POST') {
  if ($mode === '415') out(415, ['statusCode' => 415, 'message' => 'Attach a .txt, .log, .json or .zip file.']);
  if (!isset($_FILES['file'])) out(400, ['message' => 'No file provided']);
  file_put_contents($d.'/attach', $mm[1].'|'.$_FILES['file']['name'].'|'.$_FILES['file']['type'].'|'.$_FILES['file']['size']);
  out(201, ['id' => 'att1', 'filename' => $_FILES['file']['name'], 'contentType' => $_FILES['file']['type'],
    'size' => (int)$_FILES['file']['size'], 'createdAt' => gmdate('c'), 'expiresAt' => gmdate('c', time() + 86400 * 90)]);
}
out(404, ['message' => 'Not found']);
PHPEOF);

$php  = staxx_php_bin();
$pipes = [];
// auto_prepend_file off: Unraid's php.ini prepends the webGUI's CSRF check to
// every script, which answers the stand-in's POSTs with an empty 500 or 200.
$server = proc_open([$php, '-d', 'auto_prepend_file=', '-S', '127.0.0.1:'.$port, $router], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
  $pipes, $dir, ['ZZ_FB_DIR' => $dir] + getenv());
$up = false;
for ($i = 0; $i < 50 && !$up; $i++) {
  $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
  if ($c) { fclose($c); $up = true; } else usleep(100000);
}
if (!$up) { echo "FAIL   the stand-in board did not start\n"; exit(1); }

/* ----------------------------------------------------------------- checks -- */

$s = r(staxx_feedback_status());
check('status starts not connected', $s['ok'] === true && $s['connected'] === false && !isset($s['pending']));

$u = r(staxx_feedback_upload(base64_encode("\x89PNG\r\n\x1a\nxxxx"), 'image/png'));
check('an upload with no connection says to connect', ($u['ok'] ?? true) === false && ($u['reconnect'] ?? false) === true);

$c = r(staxx_feedback_connect());
check('connect replies with the code and address', ($c['ok'] ?? false) === true
  && $c['userCode'] === 'WDJB-MJHT' && strpos($c['verificationUriComplete'], 'WDJB-MJHT') !== false);
check('connect never replies with the pairing secret', !isset($c['deviceCode']));

$s = r(staxx_feedback_status());
check('status shows the pending pairing', isset($s['pending']['userCode']) && !isset($s['pending']['deviceCode']));

$p = r(staxx_feedback_poll());
check('first poll is pending', ($p['status'] ?? '') === 'pending');
$p = r(staxx_feedback_poll());
check('second poll is connected, with the name', ($p['status'] ?? '') === 'connected' && ($p['name'] ?? '') === 'Test Person');
check('the token is stored', strpos((string)@file_get_contents(STAXX_FEEDBACK_FILE), 'TOKEN-SECRET-123') !== false);
check('the connection file is mode 600', substr(sprintf('%o', fileperms(STAXX_FEEDBACK_FILE)), -4) === '0600');
$s = r(staxx_feedback_status());
check('status is connected and the pairing is gone', $s['connected'] === true && $s['name'] === 'Test Person' && !isset($s['pending']));

$u = r(staxx_feedback_upload(base64_encode('just some text, not a picture'), 'image/png'));
check('a non-image upload is refused', ($u['ok'] ?? true) === false);
$u = r(staxx_feedback_upload('%%% not base64 %%%', 'image/png'));
check('unreadable base64 is refused', ($u['ok'] ?? true) === false);
$u = r(staxx_feedback_upload(base64_encode("\x89PNG\r\n\x1a\nxxxx"), 'text/plain'));
check('a real PNG goes through and returns the key', ($u['ok'] ?? false) === true && $u['key'] === 'uploads/org/abc.png');
check('the picture is sent as screenshot.png, image/png', trim((string)@file_get_contents($dir.'/upload-name')) === 'screenshot.png|image/png');

$x = r(staxx_feedback_send('', 'body', ''));
check('an empty title is refused', ($x['ok'] ?? true) === false);
$x = r(staxx_feedback_send('It broke', 'Nothing starts. ![screenshot](attachment:uploads/org/abc.png)', 'Editor'));
check('send returns the card address', ($x['ok'] ?? false) === true && $x['url'] === STAXX_FEEDBACK_BASE.'/p/abc123', json_encode($x));
$post = json_decode((string)@file_get_contents($dir.'/lastpost.json'), true) ?: [];
check('the card goes to the Bug Report board', ($post['boardId'] ?? '') === 'board-1');
check('the card carries the footer', (bool)preg_match('/\n\n---\nStaXX .+ · Unraid .+ · Screen: Editor$/u', (string)($post['content'] ?? '')), (string)($post['content'] ?? ''));
check('the board id is remembered', strpos((string)@file_get_contents(STAXX_FEEDBACK_FILE), 'board-1') !== false);

$x = r(staxx_feedback_send('Add a thing', 'Please', '', 'bug'));
$post = json_decode((string)@file_get_contents($dir.'/lastpost.json'), true) ?: [];
check('kind bug goes to Bug Report', ($post['boardId'] ?? '') === 'board-1');
$x = r(staxx_feedback_send('Add a thing', 'Please', '', 'feature'));
$post = json_decode((string)@file_get_contents($dir.'/lastpost.json'), true) ?: [];
check('kind feature goes to Feature Requests', ($x['ok'] ?? false) === true && ($post['boardId'] ?? '') === 'board-2', json_encode($x));
$x = r(staxx_feedback_send('Do it better', 'Please', '', 'improvement'));
$post = json_decode((string)@file_get_contents($dir.'/lastpost.json'), true) ?: [];
check('kind improvement goes to Improvements', ($x['ok'] ?? false) === true && ($post['boardId'] ?? '') === 'board-3', json_encode($x));
@unlink($dir.'/lastpost.json');
$x = r(staxx_feedback_send('Other', 'Please', '', 'other'));
check('an unknown kind is refused with a sentence', ($x['ok'] ?? true) === false && strpos((string)($x['error'] ?? ''), 'Feature Requests') !== false, json_encode($x));
check('an unknown kind never reaches the board', !file_exists($dir.'/lastpost.json'));

$long = staxx_feedback_send('Long',str_repeat('a', 12000), '');
r($long);
$post = json_decode((string)@file_get_contents($dir.'/lastpost.json'), true) ?: [];
check('a long report is trimmed to 10000 with the footer kept', mb_strlen((string)($post['content'] ?? '')) === 10000
  && strpos((string)$post['content'], 'Screen: Stacks list') !== false);

/* ------------------------------------------------------------ the cleaner -- */

function flat(array $segs): string { return implode('', array_map(fn($s) => $s['tag'] ?? $s['t'], $segs)); }
function tags(array $segs): array  { return array_values(array_map(fn($s) => $s['tag'].'='.$s['orig'], array_filter($segs, fn($s) => isset($s['tag'])))); }
function clean1(string $t): array  { return staxx_feedback_clean(['x' => $t])['x']; }

$t = clean1("    ADMIN_PASSWORD: hunter2   # the login password -!S\n    TZ: Europe/London\n");
check('-!S: the value is hidden, the rest kept', flat($t) === "    ADMIN_PASSWORD: <secret 1>   # the login password -!S\n    TZ: Europe/London\n", flat($t));
$t = clean1("    WEBUI_NAME: plain   # note -!S\n");
check('-!S hides a value whose key names nothing secret', flat($t) === "    WEBUI_NAME: <secret 1>   # note -!S\n", flat($t));

$t = clean1("environment:\n  DB_PASSWORD: abc123\n  - API_TOKEN=xyz789\n  SESSION_KEY: \"quoted one\"\n  TZ: UTC\n");
check('secret-named keys: colon, equals and list forms', flat($t) === "environment:\n  DB_PASSWORD: <secret 1>\n  - API_TOKEN=<secret 2>\n  SESSION_KEY: <secret 3>\n  TZ: UTC\n", flat($t));
check('the tag carries the original value', tags($t) === ['<secret 1>=abc123', '<secret 2>=xyz789', '<secret 3>="quoted one"'], json_encode(tags($t)));
$t = clean1("  DB_PASSWORD: \${DB_PASSWORD}\n  API_TOKEN=\${TOKEN:-x}\n  - \"COOKIE=abc\"\n");
check('a ${…} value stays; a quoted list item loses its value only', flat($t) === "  DB_PASSWORD: \${DB_PASSWORD}\n  API_TOKEN=\${TOKEN:-x}\n  - \"COOKIE=<secret 1>\"\n", flat($t));
$t = clean1("image: nginx\nports:\n  - 80:80\nsecrets:\n  a: {}\n");
check('a key that only names a section holds nothing to hide', flat($t) === "image: nginx\nports:\n  - 80:80\nsecrets:\n  a: {}\n", flat($t));

$t = clean1("DATABASE_URL: postgres://bob:s3cr3t@db.example.org:5432/app\n");
check('user:password in a URL', flat($t) === "DATABASE_URL: postgres://<secret 1>@db.example.org:5432/app\n" && tags($t) === ['<secret 1>=bob:s3cr3t'], flat($t));

$t = clean1('mail me: jo.bloggs+x@example.com or jo.bloggs+x@example.com, or a@b.co');
check('emails, same value same tag', flat($t) === 'mail me: <email 1> or <email 1>, or <email 2>', flat($t));

$t = clean1('a 192.168.1.5 b 10.0.0.1 c 172.16.0.9 d 172.32.0.1 e 100.64.1.1 f 169.254.1.1 g 8.8.8.8 h 192.168.1.5');
check('home ranges are home addresses, others addresses', flat($t) === 'a <home address 1> b <home address 2> c <home address 3> d <address 1> e <home address 4> f <home address 5> g <address 2> h <home address 1>', flat($t));
$t = clean1('bind 0.0.0.0 and 127.0.0.1 and 127.5.5.5 and 255.255.255.255 and 999.1.1.1 and 1.2.3');
check('0.0.0.0, 127.*, 255.255.255.255 and non-addresses stay', flat($t) === 'bind 0.0.0.0 and 127.0.0.1 and 127.5.5.5 and 255.255.255.255 and 999.1.1.1 and 1.2.3', flat($t));

$t = clean1('mac 02:42:ac:11:00:02 and 02-42-ac-11-00-02 here');
check('MAC addresses', flat($t) === 'mac <hardware address 1> and <hardware address 2> here', flat($t));

$t = clean1("  NC_ADMIN_PW: hunter22\n  DB_PWD=hunter33\n  PW_HASH: hunter44\n  UPWARD: yes\n");
check('PW and PWD name a secret only as a whole part; UPWARD does not', flat($t) === "  NC_ADMIN_PW: <secret 1>\n  DB_PWD=<secret 2>\n  PW_HASH: <secret 3>\n  UPWARD: yes\n", flat($t));

$t = clean1("TRUSTED_PROXIES: fd12:3456:789a::10\nfe80::1ff:fe23:4567:890a and 2001:db8::5");
check('IPv6: private ranges are home addresses, others addresses', flat($t) === "TRUSTED_PROXIES: <home address 1>\n<home address 2> and <address 1>", flat($t));
$t = clean1('listen [2001:db8::5]:8080 and 2001:db8::5 again');
check('IPv6 inside brackets, same value same tag', flat($t) === 'listen [<address 1>]:8080 and <address 1> again', flat($t));
$t = clean1('Proxy is fd12:3456:789a::10.');
check('an IPv6 address at the end of a sentence is still hidden', flat($t) === 'Proxy is <home address 1>.', flat($t));
$two = staxx_feedback_clean(['a' => 'proxy fd12:3456:789a::10', 'b' => 'seen fd12:3456:789a::10']);
check('the same IPv6 value in two items gets the same tag', flat($two['a']) === 'proxy <home address 1>' && flat($two['b']) === 'seen <home address 1>', flat($two['a']).' | '.flat($two['b']));
$keep = 'bind :: and ::1 at 21:14:02 on 8443:443 with redis:7-alpine';
check('::, ::1, times, port pairs and image tags are kept', flat(clean1($keep)) === $keep, flat(clean1($keep)));
$t = clean1('nic aa:bb:cc:dd:ee:ff up');
check('a MAC is a hardware address, not IPv6', flat($t) === 'nic <hardware address 1> up', flat($t));

$t = clean1('nas cloud.nas.lan and printer.local, see github.com');
check('names under a private suffix are host names; public names stay', flat($t) === 'nas <host name 1> and <host name 2>, see github.com', flat($t));
$t = clean1('cloud.nas.lan and cloud.nas.lan');
check('the same host name gets the same tag', flat($t) === '<host name 1> and <host name 1>', flat($t));

$t = clean1('key Zm9vYmFyMTIzNDU2Nzg5MDEyMzQ1Ng== and word abcdefghijklmnopqrstuvwxyzabcdef and 123456789012345678901234567890 end');
check('a long mixed letters-and-digits string is a secret; all letters or all digits is not', flat($t) === 'key <secret 1> and word abcdefghijklmnopqrstuvwxyzabcdef and 123456789012345678901234567890 end', flat($t));
$dig = 'sha256:'.str_repeat('ab12', 16);
$t = clean1("image: nginx@$dig\nid $dig");
check('an image digest after sha256: is kept', flat($t) === "image: nginx@$dig\nid $dig", flat($t));

$t = clean1("API_TOKEN=abcdefghij0123456789abcdefghij0123\nmail admin@example.com");
check('an overlap is tagged once, by the earlier rule', flat($t) === "API_TOKEN=<secret 1>\nmail <email 1>" && count(tags($t)) === 2, flat($t));
$t = clean1('postgres://bob:pw1@h.com and x@h.com');
check('a URL login is not also read as an email', flat($t) === 'postgres://<secret 1>@h.com and <email 1>', flat($t));

$two = staxx_feedback_clean(['a' => "host 192.168.1.5\nDB_PASSWORD: one", 'b' => "from 10.0.0.2 then 192.168.1.5\nDB_PASSWORD: one\nAPI_TOKEN=two"]);
check('one table across items: same value, same tag', flat($two['b']) === "from <home address 2> then <home address 1>\nDB_PASSWORD: <secret 1>\nAPI_TOKEN=<secret 2>" && strpos(flat($two['a']), '<home address 1>') !== false, flat($two['b']));
$sp = staxx_feedback_clean(['c' => "DB_PASSWORD: hunter22\nTZ: UTC\nshort: abc\nAPI_TOKEN=abc", 'l' => "login failed, password hunter22 for abc\nretry hunter22"]);
check('a matched value is hidden bare in another item, same tag', flat($sp['c']) === "DB_PASSWORD: <secret 1>\nTZ: UTC\nshort: abc\nAPI_TOKEN=<secret 2>", flat($sp['c']));
check('the bare copy in the log carries the same tag', flat($sp['l']) === "login failed, password <secret 1> for abc\nretry <secret 1>", flat($sp['l']));
check('a value under 4 characters is not spread', strpos(flat($sp['l']), 'for abc') !== false && strpos(flat($sp['c']), 'short: abc') !== false);
check('an item with nothing private is one plain segment', staxx_feedback_clean(['z' => 'hello'])['z'] === [['t' => 'hello']]);

/* ------------------------------------------------- details and attaching -- */

$d = staxx_feedback_details('', "first error\n\nsecond at 192.168.1.9\n", 'Mozilla/5.0 Test');
$keys = array_map(fn($i) => $i['key'], $d['items'] ?? []);
check('no stack: only the errors and the versions', ($d['ok'] ?? false) === true && $keys === ['errors', 'versions'], json_encode($keys));
check('the errors item is labelled with its count and file', ($d['items'][0]['label'] ?? '') === 'Errors this page has run into (2)' && ($d['items'][0]['file'] ?? '') === 'page-errors.txt');
check('the errors are cleaned', strpos(flat($d['items'][0]['segments']), '<home address 1>') !== false && strpos(flat($d['items'][0]['segments']), '192.168.1.9') === false);
check('the versions carry the browser', strpos(flat($d['items'][1]['segments']), 'Browser: Mozilla/5.0 Test') !== false
  && ($d['items'][1]['label'] ?? '') === 'Docker and Docker Compose versions' && ($d['items'][1]['file'] ?? '') === 'versions.txt');
$d = staxx_feedback_details('../etc', '', 'UA');
check('an invalid stack and no errors: just the versions', count($d['items']) === 1 && $d['items'][0]['key'] === 'versions');

$a = r(staxx_feedback_attach('p1', 'passwd.txt', 'x'));
check('attach refuses a file name that is not one of the five', ($a['ok'] ?? true) === false);
$a = r(staxx_feedback_attach('p1/../x', 'versions.txt', 'x'));
check('attach refuses a card id with odd characters', ($a['ok'] ?? true) === false);
$a = r(staxx_feedback_attach('p1', 'versions.txt', ''));
check('attach refuses an empty file', ($a['ok'] ?? true) === false);
$a = r(staxx_feedback_attach('p1', 'versions.txt', "Docker: 27.0\n"));
check('attach goes through', ($a['ok'] ?? false) === true && $a['id'] === 'att1' && $a['filename'] === 'versions.txt', json_encode($a));
check('attach reply carries the size of the file', ($a['size'] ?? -1) === 13, json_encode($a));
check('the file goes up with its own name, as text/plain, to the card', trim((string)@file_get_contents($dir.'/attach')) === 'p1|versions.txt|text/plain|13', (string)@file_get_contents($dir.'/attach'));
file_put_contents($dir.'/mode', '415');
$a = r(staxx_feedback_attach('p1', 'compose.txt', 'x'));
check('the board\'s own message passes through', ($a['ok'] ?? true) === false && $a['error'] === 'Attach a .txt, .log, .json or .zip file.', json_encode($a));
file_put_contents($dir.'/mode', '');

$x = r(staxx_feedback_send('With id', 'Body', 'Editor'));
check('send returns the card id and the footer line', ($x['id'] ?? '') === 'p1' && strpos((string)($x['footer'] ?? ''), 'Screen: Editor') !== false, json_encode($x));

file_put_contents($dir.'/mode', '429');
$x = r(staxx_feedback_send('Again', 'More', ''));
check('a 429 passes the board\'s message and wait on', ($x['ok'] ?? true) === false
  && $x['error'] === 'You have sent 10 reports this hour. Try again later.' && ($x['retryAfter'] ?? 0) === 60, json_encode($x));
check('a 429 keeps the connection', staxx_feedback_status()['connected'] === true);

file_put_contents($dir.'/mode', '401');
$x = r(staxx_feedback_send('Again', 'More', ''));
check('a 401 asks to reconnect', ($x['ok'] ?? true) === false && ($x['reconnect'] ?? false) === true, json_encode($x));
$s = r(staxx_feedback_status());
check('a 401 clears the stored connection', $s['connected'] === false
  && strpos((string)@file_get_contents(STAXX_FEEDBACK_FILE), 'TOKEN-SECRET-123') === false);

// Connect again (the stand-in hands the token over on its second poll) to test Disconnect.
file_put_contents($dir.'/mode', '');
file_put_contents($dir.'/polls', '0');
r(staxx_feedback_connect());
r(staxx_feedback_poll());
$p = r(staxx_feedback_poll());
check('connecting again works', ($p['status'] ?? '') === 'connected');
$d = r(staxx_feedback_disconnect());
check('disconnect is ok and tells the board', ($d['ok'] ?? false) === true
  && trim((string)@file_get_contents($dir.'/deleted')) === 'Bearer TOKEN-SECRET-123');
$s = r(staxx_feedback_status());
check('disconnect clears the connection', $s['connected'] === false
  && strpos((string)@file_get_contents(STAXX_FEEDBACK_FILE), 'TOKEN-SECRET-123') === false);

$p = r(staxx_feedback_poll());
check('polling with nothing pending says expired', ($p['status'] ?? '') === 'expired');

$all = implode("\n", $GLOBALS['replies']);
check('no reply ever carried the token', strpos($all, 'TOKEN-SECRET-123') === false);
check('no reply ever carried the pairing secret', strpos($all, 'DEVICE-CODE-SECRET-0123') === false);

echo $fails === 0 ? "\nAll checks passed.\n" : "\n$fails check(s) FAILED.\n";
finish();
