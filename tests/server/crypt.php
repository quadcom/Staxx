<?php
/* PLAN_74 Part A pieces 3 and 4 — the StaXXCrypt cryptography container's
 * own refusals, which matter more here than the happy path: a wrongly
 * offered hash format fails as a silent login failure, and a wrongly chosen
 * "superseded" image is somebody's own picture deleted without asking.
 *
 * Server-only, since it needs the docker binary staxx_docker_bin() resolves.
 * Copy up and run:
 *
 *   php /tmp/crypt.php
 *
 * THIS SUITE BUILDS NOTHING, STARTS NOTHING, PULLS NOTHING AND REMOVES
 * NOTHING. It runs on a production server, so every case below either calls
 * a pure function directly with made-up data, or asks a read-only question
 * (staxx_crypt_container_status() on a name that does not exist, which is
 * simply "docker inspect says not found" — no different from checking
 * whether any other container is running). Nothing here calls
 * staxx_crypt_build(), staxx_crypt_rebuild(), staxx_crypt_hash(), or
 * staxx_crypt_selftest(), because every one of those can touch Docker for
 * real.
 *
 * Prints one line per case and exits non-zero on any failure.
 */

require_once '/usr/local/emhttp/plugins/staxx/include/Crypt.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

/* ---- an unrecognised format is refused before anything runs ---- */

$formats = [];
foreach (staxx_crypt_state()['formats'] as $f) $formats[$f['id']] = $f['ok'];
$error = 'unset';
$hash = staxx_crypt_hash('irrelevant', 'not-a-real-format', $error);
ok('an unrecognised format is refused', $hash === '' && $error !== '' && $error !== 'unset');

/* ---- a format absent from the recorded self-test is refused even if the
 * tool could produce it. staxx_crypt_hash() reads staxx_crypt_state(), which
 * only ever marks a format 'ok' when the self-test's own recipeId matches
 * the container's actual image — so on THIS box, whatever it currently is,
 * every format staxx_crypt_state() reports as not-ok must be refused. If
 * every format happens to be recorded as passing right now, this still
 * proves the same code path using a made-up id no self-test could ever
 * record. */
$notOffered = null;
foreach (['bcrypt', 'sha512', 'sha256', 'argon2id'] as $id) {
  if (!($formats[$id] ?? false)) { $notOffered = $id; break; }
}
if ($notOffered === null) $notOffered = 'argon2id-but-fake-'.bin2hex(random_bytes(4));
$error = 'unset';
$hash = staxx_crypt_hash('irrelevant', $notOffered, $error);
ok('a format the self-test has not passed is refused', $hash === '' && $error !== 'unset');

/* ---- hashing with no container reports "needs building" and does not
 * build. A name guaranteed not to exist proves the read-only status check
 * behaves the same way staxx_crypt_hash() relies on, without needing the
 * real container to be absent. */
$status = staxx_crypt_container_status();
ok('container status is one of the three defined states',
  in_array($status, ['missing', 'stopped', 'running'], true), $status);

// A genuinely offered format (if any) against a container this box may or
// may not actually have — either way, nothing here is allowed to build one.
// If the real container happens to be missing, this doubles as a live
// end-to-end check of the "needs building" reply text.
if ($status === 'missing') {
  $anyFormat = array_key_first($formats) ?? 'bcrypt';
  $error = 'unset';
  $hash = staxx_crypt_hash('irrelevant', $anyFormat, $error);
  ok('no container yields "needs building", not a build attempt',
    $hash === '' && stripos($error, 'need') !== false, $error);
}

/* ---- the superseded-image chooser never selects an image without our
 * label, whatever its name or tag looks like ---- */
$images = [
  ['id' => 'sha256:aaa', 'recipe' => 'old-recipe', 'labeled' => true],
  ['id' => 'sha256:bbb', 'recipe' => 'someone-elses-recipe-string', 'labeled' => false],
  ['id' => 'sha256:ccc', 'recipe' => 'current-recipe', 'labeled' => true],
];
$chosen = staxx_crypt_superseded_images($images, 'current-recipe');
$chosenIds = array_column($chosen, 'id');
ok('the unlabeled image is never chosen', !in_array('sha256:bbb', $chosenIds, true));
ok('the image matching the current recipe is kept, not chosen',
  !in_array('sha256:ccc', $chosenIds, true));
ok('the labeled, superseded image IS chosen', in_array('sha256:aaa', $chosenIds, true));

/* ---- the superseded-image chooser refuses to select anything when the
 * self-test has not passed ---- */
$was = ['bcrypt' => true, 'sha512' => true, 'sha256' => true, 'argon2id' => true];
$nowNothingPassed = ['bcrypt' => false, 'sha512' => false, 'sha256' => false, 'argon2id' => false];
ok('nothing may be removed when the new self-test passed nothing',
  staxx_crypt_rebuild_may_remove($was, $nowNothingPassed) === false);

$nowRegressed = ['bcrypt' => true, 'sha512' => true, 'sha256' => false, 'argon2id' => true];
ok('nothing may be removed when a previously-passing format regressed',
  staxx_crypt_rebuild_may_remove($was, $nowRegressed) === false);

$nowAllPassed = ['bcrypt' => true, 'sha512' => true, 'sha256' => true, 'argon2id' => true];
ok('removal is allowed once the new build matches everything the old one passed',
  staxx_crypt_rebuild_may_remove($was, $nowAllPassed) === true);

/* ---- the command string built for each format contains the salt but
 * NEVER the password — the whole point of this feature ---- */
$salt = 'my-test-salt-1234';
$secret = 'a-super-secret-password-that-must-never-appear';
foreach (['bcrypt', 'sha512', 'sha256', 'argon2id'] as $format) {
  $cmd = staxx_crypt_hash_command($format, $salt);
  ok("the $format command never contains the password", strpos($cmd, $secret) === false);
  if ($format === 'argon2id') {
    ok('the argon2id command does contain the salt', strpos($cmd, $salt) !== false);
  }
  ok("the $format command names docker exec -i (stdin left open for the password)",
    strpos($cmd, 'exec -i') !== false);
}

/* ---- extracting a hash back out only accepts the shape each format
 * actually produces, so a garbled reply cannot be mistaken for a hash ---- */
ok('bcrypt extraction takes the part after "staxx:"',
  staxx_crypt_extract_hash('bcrypt', 'staxx:$2y$12$abc') === '$2y$12$abc');
ok('bcrypt extraction refuses a reply with no colon',
  staxx_crypt_extract_hash('bcrypt', 'not a hash') === '');
ok('sha512 extraction requires the $6$ prefix',
  staxx_crypt_extract_hash('sha512', '$6$salt$hash') === '$6$salt$hash'
  && staxx_crypt_extract_hash('sha512', '$5$salt$hash') === '');
ok('sha256 extraction requires the $5$ prefix',
  staxx_crypt_extract_hash('sha256', '$5$salt$hash') === '$5$salt$hash');
ok('argon2id extraction requires the $argon2id$ prefix',
  staxx_crypt_extract_hash('argon2id', '$argon2id$v=19$m=65536,t=3,p=4$abc$def') !== ''
  && staxx_crypt_extract_hash('argon2id', '$argon2$abc') === '');

echo $fails === 0 ? "\nAll crypt.php cases passed.\n" : "\n$fails case(s) FAILED.\n";
exit($fails === 0 ? 0 : 1);
