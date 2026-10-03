<?php
/* StaXX — update messages (PLAN_214, include/Notify.php): the failure-reason
 * table, the three text layouts, the agent length cut, the routing between
 * "straight away", the summary and quiet hours, and the summary's due rules.
 *
 * Copyright 2026, StaXX contributors. GPL-2.0.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine. Needs STORE_ROOT
 * pointed at a scratch store, via tests/server/run-with-store.sh:
 *
 *     pscp tests/server/run-with-store.sh tests/server/notify.php root@<box>:/tmp/
 *     plink … 'bash /tmp/run-with-store.sh /tmp/staxx-notify-store /tmp/notify.php'
 *
 * No real notification can leave: STAXX_NOTIFY_BIN is a recording stub, and
 * the digest file, Unraid's notify script, dynamix.cfg, the agents folder and
 * the clock (STAXX_NOW) are all scratch values under /tmp, set before
 * anything is loaded (the HTML email goes to STAXX_MAIL_FILE, never to
 * mail()). Settings are varied through STAXX_NOTIFY_OPTS (JSON).
 * The old-images line writes the scratch store's storage-alert.json and puts
 * it back afterwards.
 */

$dir = '/tmp/staxx-notify-test';
@exec('rm -rf '.escapeshellarg($dir));
mkdir($dir, 0755, true);
$log  = $dir.'/calls.log';
$stub = $dir.'/stub.sh';
file_put_contents($stub, "#!/bin/sh\nprintf 'CALL\\0' >> ".escapeshellarg($log)."\nprintf '%s\\0' \"\$@\" >> ".escapeshellarg($log)."\n");
chmod($stub, 0755);
file_put_contents($dir.'/script-with', "<?php\n\$entity = \$overrule===false ? \$x : \$overrule;\n");
file_put_contents($dir.'/script-without', "<?php\n\$entity = \$x;\n");
file_put_contents($dir.'/dynamix.cfg', "[notify]\nnormal=\"7\"\nwarning=\"7\"\nalert=\"7\"\n");
putenv('STAXX_NOTIFY_BIN='.$stub);
// Set before anything is loaded so no real email can ever leave: the HTML
// route writes its message here instead of calling mail().
putenv('STAXX_MAIL_FILE='.$dir.'/mail.eml');
mkdir($dir.'/var', 0755, true);
file_put_contents($dir.'/var/var.ini', "NAME=\"Tower\"\n");
file_put_contents($dir.'/var/nginx.ini', "NGINX_DEFAULTURL=\"http://tower.test\"\n");
putenv('STAXX_NOTIFY_VARDIR='.$dir.'/var');
putenv('STAXX_NOTIFY_DIGEST='.$dir.'/digest.json');
putenv('STAXX_DYNAMIX_CFG='.$dir.'/dynamix.cfg');
putenv('STAXX_NOTIFY_SCRIPT='.$dir.'/script-with');
putenv('STAXX_NOTIFY_AGENTS='.$dir.'/agents');
putenv('STAXX_NOW='.strtotime('2026-10-02 12:00:00'));
putenv('STAXX_NOTIFY_OPTS=');

require_once '/usr/local/emhttp/plugins/staxx/include/UpdateRun.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, (!$pass && $note !== '') ? '  ('.$note.')' : '');
}
function opts(array $o): void { putenv('STAXX_NOTIFY_OPTS='.json_encode($o)); }
function at(string $when): void { putenv('STAXX_NOW='.strtotime($when)); }
function has(string $hay, string $needle): bool { return strpos($hay, $needle) !== false; }

/** Every call the stub caught since the last reset: a list of ['-s'=>…, '-d'=>…, '-m'=>…, '-l'=>…, '-i'=>…]. */
function calls(): array {
  global $log;
  $raw = is_file($log) ? (string)file_get_contents($log) : '';
  $out = [];
  foreach (array_slice(explode("CALL\0", $raw), 1) as $chunk) {
    $a = explode("\0", $chunk); array_pop($a);
    $c = [];
    foreach ($a as $i => $p) if (in_array($p, ['-s', '-d', '-m', '-l', '-i', '-e'], true)) $c[$p] = $a[$i + 1] ?? '';
    $out[] = $c;
  }
  return $out;
}
function reset_all(): void {
  global $log, $dir;
  @unlink($log);
  @unlink($dir.'/digest.json');
  @unlink($dir.'/mail.eml');
  @rmdir('/tmp/staxx/notify-digest.lock');
  opts([]);
}
function digest(): array { return staxx_notify_digest_read(); }

$link = staxx_view_url().'#updates';
$notes = "## What's new\n- Adds **HDR** tone mapping for [Intel Arc](https://x.test/arc)\n\n* Fixes subtitles stuck on screen after seeking\n1. Third line\n- Fourth line is never shown";
$url  = 'https://github.com/jellyfin/jellyfin/releases/tag/v10.10.3';
$jelly = ['kind' => 'installed', 'stack' => 'jellyfin', 'service' => 'jellyfin', 'was' => '10.9.11', 'version' => '10.10.3',
          'notes' => $notes, 'notesUrl' => $url, 'notesFor' => '10.10.3'];
$sonarr = ['kind' => 'installed', 'stack' => 'sonarr', 'service' => 'sonarr', 'was' => '4.0.9', 'version' => '4.0.10'];
$immich = ['kind' => 'failed', 'stack' => 'immich', 'service' => 'immich', 'reason' => "Docker Hub's download limit was reached. It resets within six hours."];
$next   = ['kind' => 'found', 'stack' => 'nextcloud', 'service' => 'nextcloud', 'was' => '29.0.7', 'version' => '30.0.0'];
$plex   = ['kind' => 'found', 'stack' => 'plex', 'service' => 'plex', 'was' => '1.40.5', 'version' => '1.41.0'];

/* ===== 1. staxx_update_notify() argument shapes ===== */
reset_all();
staxx_update_notify('S', 'D');
$c = calls()[0] ?? [];
ok('two-argument call: no -m, no -l, importance normal', ($c['-i'] ?? '') === 'normal' && !isset($c['-m']) && !isset($c['-l']) && ($c['-s'] ?? '') === 'S' && ($c['-d'] ?? '') === 'D');
reset_all();
staxx_update_notify('S', 'D', "line1\nline2", '/StaXX#updates', 'warning 5');
$c = calls()[0] ?? [];
ok('long message, link and importance with bits reach notify', ($c['-m'] ?? '') === "line1\nline2" && ($c['-l'] ?? '') === '/StaXX#updates' && ($c['-i'] ?? '') === 'warning 5');
reset_all();
staxx_update_notify('S', 'D', '', '', 'bogus; rm');
ok('an importance that is not one of the three falls back to normal', (calls()[0]['-i'] ?? '') === 'normal');

/* ===== 2. every failure-reason row ===== */
$rows = [
  ["Error response from daemon: toomanyrequests: You have reached your pull rate limit", 'nginx:1.2', "Docker Hub's download limit was reached. It resets within six hours."],
  ["429 Too Many Requests", 'ghcr.io/o/app:1', "ghcr.io's download limit was reached."],
  ["manifest unknown", 'ghcr.io/o/paperless:2.11.6', 'The tag 2.11.6 no longer exists on ghcr.io. It was removed or renamed.'],
  ["manifest for foo/bar:9 not found: manifest unknown", 'foo/bar:9', 'The tag 9 no longer exists on Docker Hub. It was removed or renamed.'],
  ["Head: denied: requested access to the resource is denied", 'ghcr.io/o/app:1', 'ghcr.io refused the download. Check the sign-in for it in StaXX, Settings.'],
  ["unauthorized: authentication required", 'nginx', 'Docker Hub refused the download. Check the sign-in for it in StaXX, Settings.'],
  ["no matching manifest for linux/amd64 in the manifest list entries", 'x/y:1', "There is no build of this image for this server's processor."],
  ["dial tcp: lookup registry-1.docker.io: no such host", 'x/y:1', 'Docker Hub could not be reached.'],
  ["net/http: TLS handshake timeout", 'ghcr.io/o/a:1', 'ghcr.io could not be reached.'],
  ["connection refused", 'x/y:1', 'Docker Hub could not be reached.'],
  ["unexpected EOF", 'x/y:1', 'The download was cut off part way.'],
  ["read tcp: connection reset by peer", 'x/y:1', 'The download was cut off part way.'],
  ["received unexpected HTTP status: 502 Bad Gateway", 'quay.io/o/a:1', 'quay.io was having trouble.'],
  ["write /var/lib/docker: no space left on device", 'x/y:1', 'The server ran out of disk space during the download.'],
  ["Bind for 0.0.0.0:8080 failed: port is already allocated", 'x/y:1', 'Port 8080 is already used by something else.'],
  ["listen tcp4 0.0.0.0:9000: bind: address already in use", 'x/y:1', 'Port 9000 is already used by something else.'],
  ['Conflict. The container name "/media-web" is already in use by container "abc"', 'x/y:1', 'Another container already uses the name media-web.'],
  ["container web-1 exited with code 1", 'x/y:1', 'The app stopped right after it started.'],
  ["dependency failed to start: container db is unhealthy", 'x/y:1', 'The app stopped right after it started.'],
  ["Error: something odd happened", 'x/y:1', 'Error: something odd happened'],
  ["", 'x/y:1', 'The update stopped before it finished.'],
  ["Pulling…\nDone", 'x/y:1', 'The update stopped before it finished.'],
];
foreach ($rows as $i => [$logText, $img, $want]) {
  $got = staxx_update_failure_reason($logText, $img);
  ok('failure reason row '.($i + 1).': '.substr(trim($logText), 0, 40), $got === $want, $got);
}
$long = staxx_update_failure_reason('Error: '.str_repeat('x', 400), 'a/b');
ok('an unrecognised error is cut at 200 characters', mb_strlen($long) === 200);

/* ===== 3. Layout 1 ===== */
reset_all();
$t = staxx_notify_text([$jelly, $sonarr, $immich], '1');
ok('L1 subject with counts and icons', $t['subject'] === 'StaXX updated 2 stacks, 1 failed', $t['subject']);
ok('L1 description names what failed, then what updated', $t['description'] === 'Failed: immich. Updated: jellyfin, sonarr.', $t['description']);
ok('L1 importance is warning on any failure', $t['importance'] === 'warning');
ok('L1 link is the Updates view', $t['link'] === $link);
ok('L1 versions line', has($t['body'], "✅ jellyfin".str_repeat(' ', 8)."10.9.11 → 10.10.3\n"), $t['body']);
ok('L1 bullets: markdown reduced, three lines, link last', has($t['body'],
  "   • What's new\n   • Adds HDR tone mapping for Intel Arc\n   • Fixes subtitles stuck on screen after seeking\n   • Full release notes ↗ $url\n"), $t['body']);
ok('L1 fourth note line never shown', !has($t['body'], 'Fourth line') && !has($t['body'], 'Third line'));
ok('L1 failure shows its reason', has($t['body'], "❌ immich".str_repeat(' ', 10)."Docker Hub's download limit was reached."));
ok('L1 failure has no filler lines', !has($t['body'], 'still running') && !has($t['body'], 'job log'));
$t = staxx_notify_text([$sonarr], '1');
ok('L1 only-installed importance is normal, singular subject', $t['importance'] === 'normal' && $t['subject'] === 'StaXX updated 1 stack' && $t['description'] === 'sonarr.', $t['subject']);
$t = staxx_notify_text([$immich], '1');
ok('L1 failed only subject', $t['subject'] === 'StaXX failed to update 1 stack' && $t['description'] === 'Failed: immich.', $t['subject']);
opts(['UPDATE_NOTIFY_ICONS' => 'false']);
$t = staxx_notify_text([$jelly, $immich], '1');
ok('L1 icons off: no emoji anywhere', !preg_match('/[\x{2705}\x{274C}\x{1F514}\x{2B06}\x{26A0}\x{1F4CB}]/u', $t['subject'].$t['body']) && has($t['body'], 'jellyfin  ') && $t['subject'] === 'StaXX updated 1 stack, 1 failed', $t['subject']);
opts(['UPDATE_NOTIFY_NOTES' => 'link']);
$t = staxx_notify_text([$jelly], '1');
ok('Release notes: Link only gives one line, no bullets', has($t['body'], "\n   Release notes ↗ $url") && !has($t['body'], '•'), $t['body']);
opts(['UPDATE_NOTIFY_NOTES' => 'none']);
$t = staxx_notify_text([$jelly], '1');
ok('Release notes: Leave out shows nothing', !has($t['body'], '↗') && !has($t['body'], '•'));
opts([]);
$stale = $jelly; $stale['notesFor'] = '10.9.11';
ok('notes for another version are not shown', !has(staxx_notify_text([$stale], '1')['body'], '↗'));
$bare = $sonarr;
ok('no notes and no link: nothing extra under the app', substr_count(staxx_notify_text([$bare], '1')['body'], "\n") === 0);
$onlyUrl = $sonarr; $onlyUrl['notesUrl'] = $url;
ok('a link with no note lines is "Release notes ↗" on its own line', has(staxx_notify_text([$onlyUrl], '1')['body'], "\n   Release notes ↗ $url"));
$longNote = $sonarr; $longNote['notes'] = str_repeat('word ', 60);
ok('a note line is cut at 120 characters', mb_strlen(explode("\n", staxx_notify_text([$longNote], '1')['body'])[1] ?? '') <= 5 + 120);

$maj = staxx_notify_text([$next], '2')['body'];
ok('major version is marked', has($maj, 'major version'));
ok('a minor change is not marked', !has(staxx_notify_text([$plex], '2')['body'], 'major version'));
// The stored stack is a path with its folders; a message names only the last part.
ok('label: folder dropped, same service adds nothing', staxx_notify_label(['stack' => 'Media/plex', 'service' => 'plex']) === 'plex');
ok('label: service equal ignoring case adds nothing', staxx_notify_label(['stack' => 'Media/Lidatube', 'service' => 'lidatube']) === 'Lidatube');
ok('label: differing service is bracketed', staxx_notify_label(['stack' => 'Paperless/paperless-ngx', 'service' => 'webserver']) === 'paperless-ngx (webserver)');
$dotted = ['kind' => 'installed', 'stack' => 'a', 'service' => 'a', 'was' => '10.9', 'version' => '10.10'];
ok('10.9 to 10.10 is not major', !has(staxx_notify_text([$dotted], '1')['body'], 'major version'));
$sz = $sonarr; $sz['size'] = 1288490189;
ok('size follows the versions', has(staxx_notify_text([$sz], '1')['body'], '4.0.9 → 4.0.10 · 1.2 GB'), staxx_notify_text([$sz], '1')['body']);
$nov = ['kind' => 'installed', 'stack' => 'x', 'service' => 'x', 'digest' => 'sha256:'.str_repeat('b', 64), 'wasDigest' => 'sha256:'.str_repeat('a', 64)];
ok('no version: short digests', has(staxx_notify_text([$nov], '1')['body'], str_repeat('a', 12).' → '.str_repeat('b', 12)));
$same = ['kind' => 'installed', 'stack' => 'x', 'service' => 'x'];
ok('nothing to name: same tag, new build', has(staxx_notify_text([$same], '1')['body'], 'same tag, new build'));
ok('a service that differs from its stack is named beside it', has(staxx_notify_text([['kind' => 'installed', 'stack' => 'media', 'service' => 'web', 'was' => '1', 'version' => '2']], '1')['body'], 'media (web)'));

/* ===== 4. Layout 2 and Layout 3 ===== */
$t = staxx_notify_text([$plex, $next], '2');
ok('L2 subject and description', $t['subject'] === 'StaXX found 2 updates waiting' && $t['description'] === 'plex 1.41.0, nextcloud 30.0.0 (major version).', $t['subject'].' | '.$t['description']);
ok('L2 lines and footer', has($t['body'], '⬆️ plex') && has($t['body'], "\nOpen StaXX to update them: $link"));
ok('L2 carries release notes like L1 (Ruling 11)', has(staxx_notify_text([array_merge($plex, ['notesUrl' => $url])], '2')['body'], "Release notes ↗ $url"));

$jellyUrl = $jelly;
$pin = ['kind' => 'pinned', 'stack' => 'postgres17', 'service' => 'postgres17', 'at' => strtotime('2026-09-12 10:00')];
$t = staxx_notify_text([$jelly, $sonarr, $immich, $next, $pin, ['kind' => 'look', 'label' => 'updates paused', 'detail' => 'paused'], ['kind' => 'cleanup', 'size' => 2147483648]], '3');
ok('L3 subject: date and counts', $t['subject'] === 'StaXX daily summary · 2 Oct', $t['subject']);
ok('L3 description: counts, then names', $t['description'] === '2 updated, 1 waiting. Failed: immich.', $t['description']);
ok('L3 sections', has($t['body'], '✅ Updated (2)') && has($t['body'], '❌ Failed (1)') && has($t['body'], '🔔 Waiting for you (1)')
   && has($t['body'], "📌 Pinned (1)\n   postgres17, pinned since 12 Sep") && has($t['body'], "⚠️ Needs a look\n   updates paused: paused"), $t['body']);
ok('L3 never carries note lines, only the link', !has($t['body'], '•') && has($t['body'], "jellyfin 10.10.3 · Release notes ↗ $url") && has($t['body'], 'sonarr 4.0.10 · ') === false, $t['body']);
ok('L3 old-images line and footer', has($t['body'], '💾 2.0 GB of old images can be cleaned up.') && has($t['body'], "Open StaXX: $link"));
ok('L3 failure line has its reason', has($t['body'], "immich: Docker Hub's download limit was reached."));
$plain = staxx_notify_text([$sonarr, ['kind' => 'installed', 'stack' => 'mariadb', 'service' => 'mariadb', 'was' => '11.4.2', 'version' => '11.4.3']], '3');
ok('L3 without links joins apps on one line', has($plain['body'], '   sonarr 4.0.10 · mariadb 11.4.3'));
opts(['UPDATE_DIGEST_EVERY' => 'week']);
ok('L3 weekly subject', has(staxx_notify_text([$sonarr], '3')['subject'], 'StaXX weekly summary · week of 28 Sep'), staxx_notify_text([$sonarr], '3')['subject']);
opts(['UPDATE_NOTIFY_NOTES' => 'none']);
ok('L3 Leave out removes the links too', !has(staxx_notify_text([$jelly], '3')['body'], '↗'));
opts([]);

/* ===== 5. the agent length cut ===== */
reset_all();
mkdir($dir.'/agents', 0755, true);
file_put_contents($dir.'/agents/discord.sh', "#!/bin/sh\n");
$many = [];
for ($i = 1; $i <= 40; $i++) $many[] = ['kind' => 'installed', 'stack' => 'app'.$i, 'service' => 'app'.$i, 'was' => '1.0.'.$i, 'version' => '1.1.'.$i];
$t = staxx_notify_text($many, '1');
staxx_notify_send($t['subject'], $t['description'], $t['body'], $t['link'], $t['importance'], $many);
$cs = calls();
ok('agents + overrule: two calls', count($cs) === 2, (string)count($cs));
ok('agent call: bits 4 only, body within 1000 and ends with the count and link', ($cs[0]['-i'] ?? '') === 'normal 4' && mb_strlen($cs[0]['-m'] ?? '') <= 1000 && preg_match('/…and \d+ more\. Open StaXX: /', $cs[0]['-m'] ?? '') === 1);
ok('bell/email call keeps the full body with the user bits minus 4', ($cs[1]['-i'] ?? '') === 'normal 3' && ($cs[1]['-m'] ?? '') === $t['body']);
reset_all();
putenv('STAXX_NOTIFY_SCRIPT='.$dir.'/script-without'); staxx_notify_overrule_ok(true);
ok('overrule check: absent', staxx_notify_overrule_ok() === false);
staxx_notify_send($t['subject'], $t['description'], $t['body'], $t['link'], $t['importance'], $many);
$cs = calls();
ok('agents without overrule: one call, body cut for everyone', count($cs) === 1 && mb_strlen($cs[0]['-m']) <= 1000 && ($cs[0]['-i'] ?? '') === 'normal');
putenv('STAXX_NOTIFY_SCRIPT='.$dir.'/script-with'); staxx_notify_overrule_ok(true);
ok('overrule check: present', staxx_notify_overrule_ok() === true);
reset_all();
file_put_contents($dir.'/dynamix.cfg', "[notify]\nnormal=\"3\"\n");
staxx_notify_send($t['subject'], $t['description'], $t['body'], $t['link'], $t['importance'], $many);
ok('user bits without agents (no 4): one call, full body', count(calls()) === 1 && (calls()[0]['-m'] ?? '') === $t['body']);
file_put_contents($dir.'/dynamix.cfg', "[notify]\nnormal=\"7\"\nwarning=\"7\"\nalert=\"7\"\n");
@unlink($dir.'/agents/discord.sh');
reset_all();
staxx_notify_send($t['subject'], $t['description'], $t['body'], $t['link'], $t['importance'], $many);
ok('no agents: one call with the full body', count(calls()) === 1 && (calls()[0]['-m'] ?? '') === $t['body']);

/* ===== 6. routing: straight away, summary, quiet hours ===== */
reset_all();
opts(['UPDATE_NOTIFY_FOUND_WHEN' => 'now', 'UPDATE_NOTIFY_INSTALLED_WHEN' => 'now', 'UPDATE_NOTIFY_FAILED_WHEN' => 'now']);
staxx_notify_events([$jelly, $immich]);
$cs = calls();
ok('installed + failed, both now: one Layout 1 message', count($cs) === 1 && has($cs[0]['-s'] ?? '', 'updated') && ($cs[0]['-i'] ?? '') === 'warning');
reset_all();
opts(['UPDATE_NOTIFY_INSTALLED_WHEN' => 'summary']);
staxx_notify_events([$jelly]);
ok('summary kind: nothing sent, event waits in the digest', calls() === [] && count(digest()['events']) === 1);
reset_all();
staxx_notify_events([$plex]);
ok('found with default (now) goes at once as Layout 2', (calls()[0]['-s'] ?? '') === 'StaXX found 1 update waiting', calls()[0]['-s'] ?? '');

reset_all();
opts(['UPDATE_QUIET' => 'true', 'UPDATE_QUIET_START' => '22:00', 'UPDATE_QUIET_END' => '07:00', 'UPDATE_NOTIFY_INSTALLED_WHEN' => 'now']);
at('2026-10-02 23:30:00');
ok('quiet window across midnight: 23:30 inside', staxx_notify_quiet(staxx_notify_now()));
at('2026-10-03 06:59:00'); $a = staxx_notify_quiet(staxx_notify_now());
at('2026-10-03 07:00:00'); $b = staxx_notify_quiet(staxx_notify_now());
at('2026-10-02 21:59:00'); $c2 = staxx_notify_quiet(staxx_notify_now());
ok('06:59 inside, 07:00 and 21:59 outside', $a && !$b && !$c2);
at('2026-10-02 23:30:00');
staxx_notify_events([$plex, $sonarr]);
$d = digest();
ok('in quiet hours a straight-away message is held, not sent', calls() === [] && count($d['events']) === 2 && !empty($d['events'][0]['held']));
staxx_notify_events([$immich]);
$cs = calls();
ok('a failure is never held, and does not carry held events inside the window', count($cs) === 1 && has($cs[0]['-s'] ?? '', 'failed') && !has($cs[0]['-m'] ?? '', 'plex') && count(digest()['events']) === 2);
reset_all();
opts(['UPDATE_QUIET' => 'true', 'UPDATE_NOTIFY_INSTALLED_WHEN' => 'now']);
at('2026-10-02 23:30:00');
staxx_notify_events([$plex]);
at('2026-10-03 08:00:00');
staxx_notify_events([$sonarr]);
$cs = calls();
ok('outside the window the next message carries the held events', count($cs) === 1 && has($cs[0]['-m'] ?? '', 'plex') && has($cs[0]['-m'] ?? '', 'sonarr'), json_encode($cs));
ok('...and they are removed from the digest', digest()['events'] === []);
reset_all();
at('2026-10-02 12:00:00');

/* ===== 7. the summary ===== */
$mk = function (array $events, int $sentAt, bool $pinnedDue = false, array $pinned = []) use ($dir) {
  file_put_contents($dir.'/digest.json', json_encode(['sentAt' => $sentAt, 'events' => $events, 'pinned' => $pinned, 'pinnedDue' => $pinnedDue]));
};
opts(['UPDATE_DIGEST_EVERY' => 'day', 'UPDATE_DIGEST_TIME' => '08:00']);

reset_all(); $mk([$jelly, $immich], strtotime('2026-10-01 08:01'));
at('2026-10-02 07:59');
staxx_notify_digest_pass();
ok('daily: not due before the send time', calls() === []);
at('2026-10-02 08:01');
staxx_notify_digest_pass();
$cs = calls();
ok('daily: due after the send time, sent as Layout 3', count($cs) === 1 && has($cs[0]['-s'] ?? '', 'daily summary · 2 Oct') && ($cs[0]['-i'] ?? '') === 'warning', json_encode($cs));
ok('digest cleared and sentAt moved in the same write', digest()['events'] === [] && digest()['sentAt'] === strtotime('2026-10-02 08:01'));
staxx_notify_digest_pass();
ok('not sent twice', count(calls()) === 1);

reset_all(); $mk([$jelly], strtotime('2026-10-01 09:00'));
at('2026-10-02 15:30');
staxx_notify_digest_pass();
ok('a missed send time goes at the next pass', count(calls()) === 1);

reset_all(); $mk([], strtotime('2026-10-01 09:00'));
at('2026-10-02 09:00');
staxx_notify_digest_pass();
ok('quiet day: nothing sent, sentAt still moves', calls() === [] && digest()['sentAt'] === strtotime('2026-10-02 09:00'));

opts(['UPDATE_DIGEST_EVERY' => 'week', 'UPDATE_DIGEST_DAY' => '1', 'UPDATE_DIGEST_TIME' => '08:00']);
reset_all(); opts(['UPDATE_DIGEST_EVERY' => 'week', 'UPDATE_DIGEST_DAY' => '1', 'UPDATE_DIGEST_TIME' => '08:00']);
$mk([$jelly], strtotime('2026-09-28 08:30'));
at('2026-10-02 09:00');
staxx_notify_digest_pass();
ok('weekly: sent Monday 08:30, so not due again on Friday', calls() === []);
$mk([$jelly], strtotime('2026-09-27 09:00'));
staxx_notify_digest_pass();
ok('weekly: due when the last Monday 08:00 is after sentAt', count(calls()) === 1 && has(calls()[0]['-s'] ?? '', 'weekly summary'));

reset_all(); opts(['UPDATE_DIGEST_EVERY' => 'day', 'UPDATE_DIGEST_TIME' => '08:00']);
$pinList = [$pin];
$mk([$jelly], 0, true, $pinList);
at('2026-10-02 09:00');
staxx_notify_digest_pass();
ok('pinned rides along on its turn', has(calls()[0]['-m'] ?? '', '📌 Pinned (1)'));
ok('...and is not repeated the next day', digest()['pinnedDue'] === false);
reset_all(); opts(['UPDATE_DIGEST_EVERY' => 'day']);
$mk([$jelly], 0, false, $pinList);
staxx_notify_digest_pass();
ok('pinned stays out when it is not its turn', !has(calls()[0]['-m'] ?? '', 'Pinned'));
reset_all(); opts(['UPDATE_DIGEST_EVERY' => 'day']);
$mk([], 0, true, $pinList);
staxx_notify_digest_pass();
ok('pinned alone never makes a summary go out', calls() === []);
reset_all(); opts(['UPDATE_DIGEST_EVERY' => 'day']);
staxx_notify_pinned_due([$pin]);
ok('the pinned helper stores the list and marks it due', digest()['pinnedDue'] === true && count(digest()['pinned']) === 1);

// Needs a look: only beside other content.
$looks = staxx_notify_looks();
reset_all(); opts(['UPDATE_DIGEST_EVERY' => 'day']);
$mk([$jelly], 0);
staxx_notify_digest_pass();
ok('Needs a look appears beside other content exactly when the self-test has bad entries', has(calls()[0]['-m'] ?? '', 'Needs a look') === ($looks !== []), json_encode(array_column($looks, 'label')));
reset_all(); opts(['UPDATE_DIGEST_EVERY' => 'day']);
$mk([], 0);
staxx_notify_digest_pass();
ok('Needs a look never makes a summary go out on its own', calls() === []);

// The old-images line (needs the scratch store's config folder).
$alert = staxx_storage_alert_file();
if ($alert === '') {
  echo "skip   old-images line (no store)\n";
} else {
  @mkdir(dirname($alert), 0755, true);
  $saved = is_file($alert) ? file_get_contents($alert) : null;
  file_put_contents($alert, json_encode(['clutterBytes' => 2147483648]));
  reset_all(); opts(['UPDATE_DIGEST_EVERY' => 'day']); $mk([$jelly], 0);
  staxx_notify_digest_pass();
  ok('old-images line at 2 GB, beside other content', has(calls()[0]['-m'] ?? '', '2.0 GB of old images can be cleaned up.'));
  reset_all(); opts(['UPDATE_DIGEST_EVERY' => 'day']); $mk([], 0);
  staxx_notify_digest_pass();
  ok('old-images line never sends a summary alone', calls() === []);
  file_put_contents($alert, json_encode(['clutterBytes' => 500 * 1048576]));
  reset_all(); opts(['UPDATE_DIGEST_EVERY' => 'day']); $mk([$jelly], 0);
  staxx_notify_digest_pass();
  ok('below 1 GB the line is left out', !has(calls()[0]['-m'] ?? '', 'old images'));
  if ($saved === null) @unlink($alert); else file_put_contents($alert, $saved);
}

reset_all(); opts(['UPDATE_DIGEST_EVERY' => 'day']);
$held = $plex; $held['held'] = true;
$mk([$held], 0);
staxx_notify_digest_pass();
ok('the summary carries events held by quiet hours', has(calls()[0]['-m'] ?? '', 'plex'));

/* ===== 8. Send a test message ===== */
reset_all();
opts(['UPDATE_NOTIFY_INSTALLED_WHEN' => 'summary', 'UPDATE_QUIET' => 'true']);
at('2026-10-02 23:30:00');
$r = staxx_notify_test();
$cs = calls();
ok('test message: sent at once, ignoring the summary and quiet hours, subject prefixed', $r['ok'] === true && $r['message'] === 'A test message was sent.' && count($cs) === 1 && strpos($cs[0]['-s'] ?? '', 'Test: ') === 0, json_encode($r));
putenv('STAXX_NOTIFY_BIN='.$dir.'/missing');
$r = staxx_notify_test();
ok('test message: a missing notifier gives a reason, not a send', $r['ok'] === false && $r['message'] !== '');
putenv('STAXX_NOTIFY_BIN='.$stub);

/* ===== 9. the HTML email (advanced email on) ===== */
function mail_raw(): string { global $dir; return is_file($dir.'/mail.eml') ? (string)file_get_contents($dir.'/mail.eml') : ''; }
/** One base64 text part of the message, decoded. */
function mail_part(string $raw, string $type): string {
  if (!preg_match('~Content-Type: '.preg_quote($type, '~').'[^\n]*\nContent-Transfer-Encoding: base64\n\n([^-]*)~', $raw, $m)) return '';
  return (string)base64_decode(preg_replace('/\s+/', '', $m[1]));
}
function mail_header(string $raw, string $name): string {
  $head = preg_replace("/\n[ \t]+/", ' ', explode("\n\n", $raw, 2)[0]);
  return preg_match('~^'.preg_quote($name, '~').': (.*)$~m', $head, $m) ? mb_decode_mimeheader($m[1]) : '';
}
/** dynamix.cfg with the given delivery bits and a recipient list. */
function dyn(int $normal, int $warning, string $rcpt = 'a@x.test b@x.test'): void {
  global $dir;
  file_put_contents($dir.'/dynamix.cfg', "[notify]\nnormal=\"$normal\"\nwarning=\"$warning\"\nalert=\"7\"\n"
    ."[ssmtp]\nRcptTo=\"$rcpt\"\nroot=\"root@x.test\"\nSubject=\"Tower: \"\nSetEmailPriority=\"True\"\n");
}
$htmlOn = ['UPDATE_NOTIFY_HTML' => 'true', 'UPDATE_NOTIFY_INSTALLED_WHEN' => 'now'];
at('2026-10-02 12:00:00');

// A scratch stack whose services carry every kind of icon.
$sroot = staxx_stack_root();
$sd = $sroot === '' ? '' : $sroot.'/notifyhtml';
if ($sd === '') {
  echo "skip   HTML icon cases (no store)\n";
} else {
  @exec('rm -rf '.escapeshellarg($sd));
  mkdir($sd.'/.staxx', 0755, true);
  $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
  file_put_contents($sd.'/.staxx/web.png', $png);
  file_put_contents($sd.'/.staxx/two.png', $png);
  file_put_contents($sd.'/.staxx/vec.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
  file_put_contents($sd.'/.staxx/big.png', str_repeat('x', 120000));
  $svcYaml = static fn(string $n, string $icon): string => "  $n:\n    image: nginx\n    x-unraid:\n      icon: $icon\n";
  file_put_contents($sd.'/compose.yaml', "services:\n".$svcYaml('web', './.staxx/web.png').$svcYaml('two', './.staxx/two.png')
    .$svcYaml('vec', './.staxx/vec.svg').$svcYaml('big', './.staxx/big.png').$svcYaml('url', 'https://x.test/a.png').$svcYaml('glyph', 'fa-gear'));
  staxx_scan_stacks_reset();
}
$ev = static fn(string $svc, array $more = []): array => array_merge(
  ['kind' => 'installed', 'stack' => 'notifyhtml', 'service' => $svc, 'was' => '1.0', 'version' => '2.0'], $more);

// Delivery bits.
reset_all(); dyn(3, 3); opts($htmlOn);
staxx_notify_events([$ev('web')]);
$cs = calls();
ok('bits 3: one HTML email and notify with "normal 1"', mail_raw() !== '' && count($cs) === 1 && ($cs[0]['-i'] ?? '') === 'normal 1', json_encode($cs));
reset_all(); dyn(1, 1); opts($htmlOn);
staxx_notify_events([$ev('web')]);
ok('bits 1: no email, notify with "normal 1"', mail_raw() === '' && (calls()[0]['-i'] ?? '') === 'normal 1');
reset_all(); dyn(2, 2); opts($htmlOn);
staxx_notify_events([$ev('web')]);
ok('bits 2 only: the email and no notify call', mail_raw() !== '' && calls() === []);
reset_all(); dyn(3, 3); opts($htmlOn); putenv('STAXX_NOTIFY_SCRIPT='.$dir.'/script-without'); staxx_notify_overrule_ok(true);
staxx_notify_events([$ev('web')]);
$cs = calls();
ok('no overrule: plain text route, no email', mail_raw() === '' && count($cs) === 1 && ($cs[0]['-i'] ?? '') === 'normal');
putenv('STAXX_NOTIFY_SCRIPT='.$dir.'/script-with'); staxx_notify_overrule_ok(true);
reset_all(); dyn(3, 3); opts(['UPDATE_NOTIFY_INSTALLED_WHEN' => 'now']);
staxx_notify_events([$ev('web')]);
ok('advanced email off: text route unchanged, no email', mail_raw() === '' && (calls()[0]['-i'] ?? '') === 'normal');
reset_all(); dyn(3, 3, ''); opts($htmlOn);
staxx_notify_events([$ev('web')]);
ok('no recipient set: no email, the rest still goes', mail_raw() === '' && (calls()[0]['-i'] ?? '') === 'normal 1');

// The message itself.
reset_all(); dyn(3, 3); opts($htmlOn);
$e1 = $ev('web'); $e2 = $ev('two'); $e3 = $ev('web', ['was' => '0.9']);
$mixed = [$e1, $ev('vec'), $ev('big'), $ev('url'), $ev('glyph')];
staxx_notify_events($mixed);
$raw = mail_raw(); $html = mail_part($raw, 'text/html'); $txt = mail_part($raw, 'text/plain');
$all = staxx_notify_text($mixed, '1');
ok('To is the recipients joined with commas', mail_header($raw, 'To') === 'a@x.test,b@x.test', mail_header($raw, 'To'));
ok('Subject is the prefix then the subject', mail_header($raw, 'Subject') === 'Tower: '.$all['subject'], mail_header($raw, 'Subject'));
ok('From and Reply-To are the configured sender', mail_header($raw, 'From') === 'root@x.test' && mail_header($raw, 'Reply-To') === 'root@x.test');
ok('no priority headers on a normal message', !has($raw, 'X-Priority') && !has($raw, 'X-Mms-Priority'));
ok('MIME: related holding alternative (text then HTML)', has($raw, 'Content-Type: multipart/related') && has($raw, 'Content-Type: multipart/alternative')
   && strpos($raw, 'text/plain') < strpos($raw, 'text/html'));
ok('the plain-text part is the text layout', $txt === $all['body'], $txt);
ok('the logo goes by cid', has($html, 'src="cid:logo@staxx"') && has($raw, 'Content-ID: <logo@staxx>') && has($html, 'width="36" height="36"'));
ok('a PNG icon goes by cid, one copy', preg_match_all('~Content-ID: <icon-~', $raw) === 1 && has($html, 'src="cid:icon-'));
ok('SVG, over-100KB, URL and fa- icons get the letter tile, not a picture', substr_count($html, 'src="cid:icon-') === 1
   && substr_count($html, 'line-height:40px') === 4, (string)substr_count($html, 'line-height:40px'));
$tile = staxx_notify_label($ev('vec'));
ok('the tile is the first letter of the app name', has($html, '>'.mb_strtoupper(mb_substr($tile, 0, 1)).'</div>'));
ok('page and card are light with inline colours', has($html, 'background:#f4f4f5') && has($html, 'background:#ffffff') && has($html, 'width="640"') && !has($html, '<style'));
ok('footer and button', has($html, 'Sent by StaXX on Tower. Change these emails in StaXX, Settings, Updates.')
   && has($html, 'href="http://tower.test'.staxx_view_url().'#updates"') && has($html, '>Open StaXX</a>') && has($html, 'Tower · '));
reset_all(); dyn(3, 3); opts($htmlOn);
staxx_notify_events([$e1, $e3]);
ok('two events with the same icon file share one picture', preg_match_all('~Content-ID: <icon-~', mail_raw()) === 1);
reset_all(); dyn(3, 3); opts($htmlOn);
staxx_notify_events([$e1, $e2]);
ok('different icon files each get their own picture', preg_match_all('~Content-ID: <icon-~', mail_raw()) === 2);

// Priority.
reset_all(); dyn(3, 3); opts($htmlOn);
staxx_notify_events([$immich]);
$raw = mail_raw();
ok('warning with SetEmailPriority True: both priority headers', has($raw, "\nX-Priority: 1 (highest)\n") && has($raw, "\nX-Mms-Priority: High\n"));
ok('...and the failure reason is in the email', has(mail_part($raw, 'text/html'), 'Docker Hub&#039;s download limit was reached.'));
reset_all(); file_put_contents($dir.'/dynamix.cfg', "[notify]\nwarning=\"3\"\n[ssmtp]\nRcptTo=\"a@x.test\"\nSetEmailPriority=\"False\"\n"); opts($htmlOn);
staxx_notify_events([$immich]);
ok('SetEmailPriority False: no priority headers', mail_raw() !== '' && !has(mail_raw(), 'X-Priority'));

// Release notes in the HTML.
reset_all(); dyn(3, 3); opts($htmlOn);
$h1 = staxx_notify_html([$jelly], '1')['html'];
preg_match('~<ul[^>]*>(.*)</ul>~s', $h1, $ul);
$lis = isset($ul[1]) ? explode('<li', $ul[1]) : [];
ok('notes are a real list with disc bullets set inline', count($lis) === 5 && has($h1, 'list-style:disc'), (string)count($lis));
$lastLi = (string)end($lis);
ok('the full-notes link is the last bullet (Ruling 13)', has($lastLi, 'Full release notes&nbsp;↗') && has($lastLi, 'href="'.$url.'"'));
ok('bullets are escaped and reduced from markdown', has($h1, 'Adds HDR tone mapping for Intel Arc') && has($h1, 'What&#039;s new'));
opts($htmlOn + ['UPDATE_NOTIFY_NOTES' => 'link']);
$h2 = staxx_notify_html([$jelly], '1')['html'];
ok('Link only: "Release notes ↗" on its own line, no list', !has($h2, '<ul') && has($h2, '>Release notes&nbsp;↗</a>') && !has($h2, 'Full release notes'));
opts($htmlOn + ['UPDATE_NOTIFY_NOTES' => 'none']);
$h3n = staxx_notify_html([$jelly], '1')['html'];
ok('Leave out: nothing', !has($h3n, 'elease notes') && !has($h3n, '<ul'));
opts($htmlOn);
$hs1 = staxx_notify_html([$sonarr], '1')['html'];
ok('no notes, no link: nothing under the app', !has($hs1, '<ul') && !has($hs1, 'elease notes'));
$bad = $jelly; $bad['notesUrl'] = 'javascript:alert(1)';
ok('a link that is not http(s) is never put in an href', !has(staxx_notify_html([$bad], '1')['html'], 'javascript:'));
$x = $sonarr; $x['stack'] = 'a<b>'; $x['service'] = 'a<b>';
ok('names are escaped', !has(staxx_notify_html([$x], '1')['html'], 'a<b>') && has(staxx_notify_html([$x], '1')['html'], 'a&lt;b&gt;'));

// The summary and the found layout.
$sumEvents = [$jelly, $sonarr, $immich, $plex, $pin, ['kind' => 'look', 'label' => 'updates paused', 'detail' => 'paused'], ['kind' => 'cleanup', 'size' => 2147483648]];
$h3 = staxx_notify_html($sumEvents, '3')['html'];
ok('summary shows links only, never bullets (Ruling 10)', !has($h3, '<ul') && has($h3, '>Release notes&nbsp;↗</a>') && !has($h3, 'Full release notes'));
ok('summary sections and chips', has($h3, 'Updated') && has($h3, 'Failed') && has($h3, 'Waiting for you') && has($h3, 'Pinned') && has($h3, 'Needs a look')
   && has($h3, '1 pinned') && has($h3, 'Daily summary · 2 Oct'));
ok('old-images line above the button with its link', has($h3, '2.0 GB of old images can be cleaned up.') && has($h3, 'Clean up images&nbsp;↗')
   && strpos($h3, 'old images') < strpos($h3, '>Open StaXX</a>'));
$h2f = staxx_notify_html([array_merge($next, ['notesUrl' => $url])], '2')['html'];
ok('found layout: its own button, major mark, notes link', has($h2f, '>Update them in StaXX</a>') && has($h2f, 'major version') && has($h2f, '1 waiting') && has($h2f, 'Release notes&nbsp;↗'));
$sz = $sonarr; $sz['size'] = 1288490189;
ok('size follows the versions', has(staxx_notify_html([$sz], '1')['html'], '1.2 GB'));
opts($htmlOn + ['UPDATE_NOTIFY_ICONS' => 'false']);
ok('icons off: no emoji in the email', !preg_match('/[\x{2705}\x{274C}\x{1F514}\x{26A0}\x{1F4CC}\x{1F4BE}]/u', staxx_notify_html($sumEvents, '3')['html']));
opts($htmlOn);
$hs = staxx_notify_html([$jelly, $immich], '1')['html'];
ok('icons on: pictures, not emoji, lead the section headings', has($hs, 'src="cid:glyph-installed@staxx" alt="Updated" width="16" height="16"') && has($hs, 'alt="Failed"')
   && !preg_match('/[\x{2705}\x{274C}\x{1F514}\x{26A0}\x{1F4CC}\x{1F4BE}]/u', $hs));

// The summary and the test message take the HTML route too.
reset_all(); dyn(3, 3); opts($htmlOn);
$mk([$jelly], 0);
staxx_notify_digest_pass();
ok('the daily summary goes out as an HTML email too', has(mail_header(mail_raw(), 'Subject'), 'daily summary') && has(mail_part(mail_raw(), 'text/html'), 'Daily summary'));
reset_all(); dyn(3, 3); opts($htmlOn);
$r = staxx_notify_test();
ok('test message: HTML email, subject prefixed "Test: "', $r['ok'] === true && has(mail_header(mail_raw(), 'Subject'), 'Tower: Test: ') && (calls()[0]['-i'] ?? '') === 'warning 1', json_encode(calls()));

if ($sd !== '') { @exec('rm -rf '.escapeshellarg($sd)); staxx_scan_stacks_reset(); }

/* ===== 10. PLAN_222: words-only subjects, one capped line, StaXX's own pictures ===== */
$emoji = '/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}]/u';
$tdarr  = ['kind' => 'restarting', 'name' => 'tdarr', 'count' => 4, 'reason' => ''];
$paper  = ['kind' => 'unhealthy', 'name' => 'paperless-ngx', 'count' => 3, 'reason' => ''];
$plexS  = ['kind' => 'stopped', 'name' => 'plex', 'count' => 0, 'reason' => 'It ran out of memory.'];
$plexE  = ['kind' => 'stopped', 'name' => 'plex', 'count' => 0, 'reason' => 'It stopped with error code 3.'];
$healthy = ['kind' => 'healthy', 'name' => 'tdarr', 'count' => 0, 'reason' => ''];
$lookE  = ['kind' => 'look', 'label' => 'updates paused', 'detail' => 'paused'];
$cleanE = ['kind' => 'cleanup', 'size' => 2147483648];
$five = [];
foreach (['plex', 'sonarr', 'radarr', 'lidarr', 'bazarr'] as $n) $five[] = ['kind' => 'installed', 'stack' => $n, 'service' => $n, 'was' => '1', 'version' => '2'];

reset_all();
$t = staxx_notify_text([$tdarr], '1');
ok('one app restarting: named title, description without the name', $t['subject'] === 'StaXX: tdarr keeps restarting' && $t['description'] === 'It has restarted 4 times in the last hour.', $t['subject'].' | '.$t['description']);
$t = staxx_notify_text([$paper], '1');
ok('one app unhealthy', $t['subject'] === 'StaXX: paperless-ngx is unhealthy' && $t['description'] === 'It failed its health check 3 times in a row.', $t['description']);
$t = staxx_notify_text([$plexS], '1');
ok('one app stopped: the reason is the line', $t['subject'] === 'StaXX: plex stopped by itself' && $t['description'] === 'It ran out of memory.', $t['description']);
$t = staxx_notify_text([$tdarr, $paper, $plexS], '1');
ok('several apps: a count title and one short sentence each', $t['subject'] === 'StaXX: 3 apps need a look'
   && $t['description'] === 'tdarr keeps restarting. paperless-ngx is unhealthy. plex stopped: out of memory.', $t['description']);
ok('stopped reason shortens to the error code', has(staxx_notify_text([$tdarr, $plexE], '1')['description'], 'plex stopped: error code 3.'));
$t = staxx_notify_text([$sonarr, $plexS], '1');
ok('update news with trouble: update title plus the count', $t['subject'] === 'StaXX updated 1 stack, 1 needs a look' && $t['description'] === 'sonarr. Needs a look: plex.', $t['subject'].' | '.$t['description']);
$t = staxx_notify_text([$sonarr, $tdarr, $paper], '1');
ok('...and "need" for several apps', $t['subject'] === 'StaXX updated 1 stack, 2 need a look', $t['subject']);
$t = staxx_notify_text($five, '1');
ok('names are capped at three, then "and N more"', $t['description'] === 'plex, sonarr, radarr and 2 more.', $t['description']);
ok('the cap leaves three or fewer alone', staxx_notify_cap(['a', 'b', 'c']) === 'a, b, c' && staxx_notify_cap(['a']) === 'a');
$t = staxx_notify_text([$jelly, $immich, $sonarr], '3');
ok('summary: failed names in the description', $t['description'] === '2 updated. Failed: immich.', $t['description']);
$t = staxx_notify_text([$jelly, $plexS, $tdarr, $next], '3');
ok('summary: counts then needs a look', $t['description'] === '1 updated, 1 waiting. Needs a look: tdarr, plex.', $t['description']);
ok('summary with nothing countable', staxx_notify_text([$healthy], '3')['description'] === 'Nothing new to report.');
$t = staxx_notify_text([$jelly, $immich, $sonarr], '1');
ok('mixed update: failed first, then updated', $t['description'] === 'Failed: immich. Updated: jellyfin, sonarr.');

$layouts = [['1', [$jelly, $sonarr, $immich]], ['1', [$tdarr, $paper, $plexS]], ['1', [$sonarr, $plexS]], ['2', [$plex, $next]],
            ['3', [$jelly, $immich, $next, $pin, $lookE, $cleanE, $tdarr, $healthy]], ['3', []]];
foreach (['true', 'false'] as $iconSetting) {
  foreach (['day', 'week'] as $every) {
    opts(['UPDATE_NOTIFY_ICONS' => $iconSetting, 'UPDATE_DIGEST_EVERY' => $every]);
    $bad = [];
    foreach ($layouts as [$lay, $evs]) {
      $t = staxx_notify_text($evs, $lay);
      if (preg_match($emoji, $t['subject'].$t['description'])) $bad[] = $lay.': '.$t['subject'].' | '.$t['description'];
    }
    ok('no emoji in any subject or description (icons '.$iconSetting.', '.$every.')', $bad === [], implode('; ', $bad));
  }
}
opts([]);
ok('plain body keeps its emoji', has(staxx_notify_text([$jelly, $immich], '1')['body'], '✅ jellyfin') && has(staxx_notify_text([$jelly, $immich], '1')['body'], '❌ immich')
   && has(staxx_notify_text([$jelly, $next, $pin, $plexS], '3')['body'], '📌 Pinned') && has(staxx_notify_text([$plexS], '1')['body'], '🛑'));
opts(['UPDATE_NOTIFY_ICONS' => 'false']);
ok('icons off: subject and description are the same words', staxx_notify_text([$sonarr], '1')['subject'] === 'StaXX updated 1 stack');
opts([]);

$all = [$jelly, $sonarr, $immich, $plex, $pin, $lookE, $cleanE, $tdarr, $plexS, $healthy];
$hp = staxx_notify_html($all, '3'); $hh = $hp['html'];
$glyphs = ['installed', 'failed', 'found', 'warning', 'healthy', 'pinned', 'cleanup'];
$miss = array_filter($glyphs, static fn($g) => !has($hh, 'src="cid:glyph-'.$g.'@staxx"') || !isset($hp['images']['glyph-'.$g.'@staxx']));
ok('icons on: every heading and the cleanup line use an attached picture', $miss === [], implode(',', $miss));
ok('icons on: pictures are 16px PNGs with a plain-word alt', has($hh, 'alt="Updated" width="16" height="16" style="vertical-align:-2px;border:0;margin-right:4px"')
   && ($hp['images']['glyph-installed@staxx'][0] ?? '') === 'image/png' && strncmp($hp['images']['glyph-installed@staxx'][1] ?? '', "\x89PNG", 4) === 0);
ok('icons on: no emoji anywhere in the HTML', !preg_match($emoji, $hh));
ok('a picture is attached once however often it is used', count(array_filter(array_keys($hp['images']), static fn($k) => strpos($k, 'glyph-warning') === 0)) === 1);
$mj = $jelly; $mj['was'] = '9.0';
ok('the major-version mark is a picture too', has(staxx_notify_html([$mj], '1')['html'], 'alt="Warning"') && has(staxx_notify_html([$mj], '1')['html'], 'major version'));
opts(['UPDATE_NOTIFY_ICONS' => 'false']);
$hp = staxx_notify_html($all, '3');
ok('icons off: no pictures and no emoji in the email', !has($hp['html'], 'cid:glyph-') && !preg_match($emoji, $hp['html'])
   && array_filter(array_keys($hp['images']), static fn($k) => strpos($k, 'glyph-') === 0) === []);
opts([]);
$hb = staxx_notify_html([$jelly, $sonarr, $immich], '1')['html'];
ok('section headings are grey bands', has($hb, 'color:#333333;background:#e4e4e7;padding:9px 12px;border-radius:6px;margin:32px 0 6px') && !has($hb, 'margin:18px 0 8px'));
ok('no divider directly under a band', preg_match_all('~margin:32px 0 6px">(?:<img[^>]*> )?[^<]*</div><table[^>]*style=""~', $hb) === 2);
ok('dividers between rows stay', substr_count($hb, 'cellspacing="0" style="border-top:1px solid #eeeeee"') === 1);
$hf = staxx_notify_html([$plex], '2')['html'];
ok('found layout has no band, so its first row keeps its line', !has($hf, 'margin:32px 0 6px') && has($hf, 'cellspacing="0" style="border-top:1px solid #eeeeee"'));
reset_all(); dyn(3, 3); opts($htmlOn);
staxx_notify_events([$jelly, $immich]);
ok('the sent email carries the pictures by cid', has(mail_raw(), 'Content-ID: <glyph-installed@staxx>') && has(mail_raw(), 'Content-ID: <glyph-failed@staxx>')
   && !preg_match($emoji, mail_header(mail_raw(), 'Subject')));

/* ===== 11. PLAN_223: Docker itself stops answering, and is back ===== */
$dDown = ['kind' => 'dockerdown', 'stack' => '', 'service' => '', 'image' => '', 'name' => '', 'count' => 12];
$dBack = ['kind' => 'dockerback', 'stack' => '', 'service' => '', 'image' => '', 'name' => '', 'count' => 12];
reset_all();
$t = staxx_notify_text([$dDown], '1');
ok('docker down: subject, one line with the real minutes, alert', $t['subject'] === 'StaXX: Docker has stopped answering'
   && $t['description'] === 'Docker has not answered for 12 minutes, so your apps may not be running. Open Settings → Docker and check that Enable Docker is set to Yes, or restart the server.'
   && $t['importance'] === 'alert', $t['subject'].' | '.$t['description']);
$t = staxx_notify_text([$dBack], '1');
ok('docker back: subject, one line with the real minutes, normal', $t['subject'] === 'StaXX: Docker is back'
   && $t['description'] === 'Docker is answering again after 12 minutes. Apps that are not set to start by themselves may need starting.'
   && $t['importance'] === 'normal', $t['subject'].' | '.$t['description']);
ok('docker words: no emoji in subject or description, emoji kept in the plain body', !preg_match($emoji, staxx_notify_text([$dDown], '1')['subject'].staxx_notify_text([$dDown], '1')['description'])
   && has(staxx_notify_text([$dDown], '1')['body'], '⚠️'));
ok('docker words: one minute is singular', has(staxx_notify_text([['kind' => 'dockerdown', 'count' => 1]], '1')['description'], 'for 1 minute,'));
$hd = staxx_notify_html([$dDown], '1');
ok('docker down email: title, sentence, a picture and no emoji', has($hd['html'], 'Docker has stopped answering') && has($hd['html'], 'Open Settings → Docker')
   && has($hd['html'], 'cid:glyph-warning@staxx') && !preg_match($emoji, $hd['html']));
ok('docker back email uses the healthy picture', has(staxx_notify_html([$dBack], '1')['html'], 'cid:glyph-healthy@staxx'));

reset_all();
staxx_notify_events([$dDown]);
$cs = calls();
ok('docker down is sent straight away as one alert', count($cs) === 1 && ($cs[0]['-s'] ?? '') === 'StaXX: Docker has stopped answering' && ($cs[0]['-i'] ?? '') === 'alert', json_encode($cs));
reset_all();
staxx_notify_events([$dBack]);
$cs = calls();
ok('docker back is sent straight away as normal', count($cs) === 1 && ($cs[0]['-s'] ?? '') === 'StaXX: Docker is back' && ($cs[0]['-i'] ?? '') === 'normal', json_encode($cs));
reset_all(); opts(['APP_NOTIFY_DOCKER_WHEN' => 'off']);
staxx_notify_events([$dDown, $dBack]);
ok('APP_NOTIFY_DOCKER_WHEN off sends nothing and queues nothing', calls() === [] && digest()['events'] === []);
reset_all(); opts(['UPDATE_QUIET' => 'true', 'UPDATE_QUIET_START' => '22:00', 'UPDATE_QUIET_END' => '07:00']);
at('2026-10-02 23:30:00');
staxx_notify_events([$dDown]);
$cs = calls();
ok('quiet hours do not hold a docker message', count($cs) === 1 && ($cs[0]['-i'] ?? '') === 'alert' && digest()['events'] === [], json_encode($cs));
at('2026-10-02 12:00:00');
reset_all();
staxx_notify_events([$dDown, $tdarr]);
ok('a docker message travels alone, beside an app one', count(calls()) === 2);
reset_all(); dyn(3, 3); opts($htmlOn);
staxx_notify_events([$dDown]);
ok('the docker email is sent as HTML', has(mail_header(mail_raw(), 'Subject'), 'Docker has stopped answering') && has(mail_part(mail_raw(), 'text/html'), 'Docker has stopped answering'));

reset_all();
@exec('rm -rf '.escapeshellarg($dir));
echo $fails === 0 ? "\nAll passed.\n" : "\n$fails FAILED.\n";
exit($fails === 0 ? 0 : 1);
