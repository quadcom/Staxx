<?PHP
/* StaXX — the cryptography container: building it, and the one thing PHP
 * never does with a password, which is put it on a command line.
 * Copyright 2026, StaXX contributors.
 *
 * PLAN_74 Part A pieces 3 and 4. PHP on Unraid cannot make an argon2 hash by
 * any means (checked, not assumed — see the plan), and neither can the
 * browser under this project's own rule against client-side libraries. So
 * every hash StaXX produces, including the three PHP could manage on its
 * own, comes from one small container built on the machine from the recipe
 * at crypt/Dockerfile — one place to audit rather than three. PHP's job here
 * is only to build it, start and stop it, and carry a password to and from
 * it on its input stream. It performs no cryptography itself.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/staxx/include/Defines.php';
// For STAXX_JOB_DIR / STAXX_JOB_END / staxx_private_dir() — the same
// detached-job machinery staxx_start_job() uses, reused rather than
// duplicated. staxx_start_job() itself is not reusable here: every one of
// its jobs belongs to a stack, checked with staxx_valid_path(), and this
// container belongs to no stack at all.
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';

if (defined('STAXX_CRYPT_LOADED')) return;
define('STAXX_CRYPT_LOADED', true);

// STAXX_CRYPT_CONTAINER is defined in Defines.php, not here: the shared
// `docker ps` reader there has to leave this container out of every list it
// feeds, and this file requires that one.

/**
 * Where the proven-hash-formats record lives: <store>/config/crypt-selftest.json,
 * or '' when no data store has been chosen yet — a function rather than a
 * constant because that answer can change, and every caller below checks
 * for '' rather than building a path out of it. This record gates which
 * hash formats are trusted at all (staxx_crypt_state()), so an unreadable
 * or absent file must always be read as "nothing proven" — never as a pass.
 */
function staxx_crypt_selftest_file(): string {
  $cfg = staxx_config_root();
  return $cfg === '' ? '' : $cfg.'/crypt-selftest.json';
}
// A fixed, known, throwaway password used only to prove each format comes
// out right — never shown anywhere, never a real secret, so it is fine to
// keep it as a literal here.
define('STAXX_CRYPT_TEST_PASSWORD', 'Str0ng!SelfTest_Passphrase#42');

/* --------------------------------------------------------------- recipe -- */

/**
 * The recipe's own version IS the md5 of the Dockerfile's bytes, stamped
 * onto the image at build time as the staxx.crypt.recipe label. A hand-
 * maintained version number is a second thing to remember to bump, and it
 * can drift out of step with the file it is supposed to describe; a hash of
 * the file itself cannot — change one byte of the recipe and this changes
 * with it, with nobody having to remember anything.
 */
function staxx_crypt_recipe_id(): string {
  static $id = null;
  if ($id !== null) return $id;
  $bytes = @file_get_contents(STAXX_ROOT.'/crypt/Dockerfile');
  return $id = $bytes !== false ? md5($bytes) : '';
}

/** Every one of the four hash formats this container is meant to offer. */
function staxx_crypt_format_defs(): array {
  return [
    ['id' => 'bcrypt',   'label' => 'bcrypt'],
    ['id' => 'sha512',   'label' => 'SHA-512 crypt'],
    ['id' => 'sha256',   'label' => 'SHA-256 crypt'],
    ['id' => 'argon2id', 'label' => 'argon2id'],
  ];
}

/* -------------------------------------------------------------- reading -- */

/**
 * Every image StaXX built for this, found by ITS OWN LABEL — never by a name
 * or tag resembling ours. A person's own image called something similar
 * must be invisible here, which is exactly why this checks twice: once via
 * `docker images --filter`, which is Docker's own search, and once again by
 * reading the label straight off each match — belt and braces, since the
 * superseded-image removal in staxx_crypt_do_rebuild() depends on this list
 * being right.
 */
function staxx_crypt_images(): array {
  $out = staxx_sh(
    staxx_docker_bin().' images --filter '.escapeshellarg('label=staxx.crypt=1')
    .' --format '.escapeshellarg('{{.ID}}'),
    10
  );
  $rows = [];
  foreach (preg_split('/\r?\n/', trim($out)) as $id) {
    if ($id === '') continue;
    $labels = staxx_crypt_image_labels($id);
    $rows[] = [
      'id'      => $id,
      'recipe'  => (string)($labels['staxx.crypt.recipe'] ?? ''),
      'labeled' => ($labels['staxx.crypt'] ?? '') === '1',
    ];
  }
  return $rows;
}

function staxx_crypt_image_labels(string $imageId): array {
  $out = trim(staxx_sh(
    staxx_docker_bin().' inspect --format '.escapeshellarg('{{json .Config.Labels}}').' '.escapeshellarg($imageId),
    8
  ));
  $labels = json_decode($out, true);
  return is_array($labels) ? $labels : [];
}

/** 'missing' | 'stopped' | 'running', asked of Docker directly rather than assumed. */
function staxx_crypt_container_status(): string {
  $code = null;
  $out = trim(staxx_sh(
    staxx_docker_bin().' inspect -f '.escapeshellarg('{{.State.Running}}').' '
    .escapeshellarg(STAXX_CRYPT_CONTAINER),
    8, $code
  ));
  if ($code !== 0) return 'missing';
  return $out === 'true' ? 'running' : 'stopped';
}

/**
 * Read whatever the last self-test recorded. A missing or malformed file
 * reads as "nothing has passed" — the same fail-closed reading every other
 * best-effort file in this plugin gets — never as an error, since a self-
 * test simply not having run yet is the normal state before the first build.
 */
function staxx_crypt_selftest_read(): array {
  $empty = ['recipeId' => '', 'at' => 0, 'formats' => [], 'argon2Method' => ''];
  $file = staxx_crypt_selftest_file();
  // No store chosen, or the store's pool is not up yet: both read exactly
  // like a missing file below, and both must, since "the record cannot be
  // read" and "nothing has ever passed" have to mean the same thing here.
  $raw = $file === '' ? false : @file_get_contents($file);
  if ($raw === false) return $empty;
  $data = json_decode($raw, true);
  if (!is_array($data) || !is_string($data['recipeId'] ?? null) || !is_int($data['at'] ?? null)
      || !is_array($data['formats'] ?? null)) {
    return $empty;
  }
  $formats = [];
  foreach ($data['formats'] as $id => $ok) {
    if (is_string($id)) $formats[$id] = (bool)$ok;
  }
  return [
    'recipeId'     => $data['recipeId'],
    'at'           => $data['at'],
    'formats'      => $formats,
    'argon2Method' => is_string($data['argon2Method'] ?? null) ? $data['argon2Method'] : '',
  ];
}

function staxx_crypt_selftest_write(array $result): bool {
  $file = staxx_crypt_selftest_file();
  if ($file === '') return false; // nowhere to keep it — caller reports this as the self-test not being recorded
  $dir = dirname($file);
  if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;
  $json = json_encode($result, JSON_PRETTY_PRINT);
  if ($json === false) return false;
  $tmp = $file.'.tmp-'.getmypid();
  if (@file_put_contents($tmp, $json) === false) { @unlink($tmp); return false; }
  @chmod($tmp, 0600);
  if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
  return true;
}

/**
 * Everything Settings and the editor's hash panel need in one call. A
 * format's 'ok' is true only when the self-test on record was measured
 * against THIS CONTAINER'S OWN IMAGE — not merely against whatever recipe
 * the plugin currently ships — so a shipped recipe update that has not yet
 * been rebuilt correctly keeps offering only what the running container has
 * actually proven, while 'recipeCurrent' below is what tells Settings a
 * newer recipe is waiting.
 */
function staxx_crypt_state(): array {
  $recipeId      = staxx_crypt_recipe_id();
  $images        = staxx_crypt_images();
  $builtRecipeId = $images[0]['recipe'] ?? '';
  $selftest      = staxx_crypt_selftest_read();

  $formats = [];
  foreach (staxx_crypt_format_defs() as $def) {
    $ok = $builtRecipeId !== '' && $selftest['recipeId'] === $builtRecipeId
        && ($selftest['formats'][$def['id']] ?? false);
    // A pass that was proven a weaker way must not read the same as one
    // that was genuinely verified. argon2id is the only such case: the
    // argon2 tool has no verify mode, so its check is a correct prefix plus
    // byte-identical output from a repeated run, which shows the tool is
    // deterministic and shaped right without proving the hash means what it
    // should. Passing that distinction on is what lets the page say so
    // instead of quietly overclaiming.
    $weak = $ok && $def['id'] === 'argon2id'
         && ($selftest['argon2Method'] ?? '') !== 'verified';
    $formats[] = ['id' => $def['id'], 'label' => $def['label'], 'ok' => $ok, 'weak' => $weak];
  }

  return [
    'recipeId'      => $recipeId,
    'builtRecipeId' => $builtRecipeId,
    'built'         => $images !== [],
    'recipeCurrent' => $builtRecipeId !== '' && $builtRecipeId === $recipeId,
    'container'     => staxx_crypt_container_status(),
    'mode'          => (string)(staxx_cfg()['CRYPT_MODE'] ?? '') === 'always' ? 'always' : 'ondemand',
    'formats'       => $formats,
    'checkedAt'     => $selftest['recipeId'] === $builtRecipeId ? $selftest['at'] : 0,
  ];
}

/* --------------------------------------------------------- the commands -- */

/**
 * The exact shell command run INSIDE the container for one format — pure,
 * so it can be checked in a test without Docker: every one of the four must
 * carry the salt (argon2id only) and never the password, and this is what
 * makes that provable without running anything.
 *
 * The salt is not secret — only the password is — so passing it as a
 * command argument is the right call here, unlike the password below. It is
 * still escapeshellarg()'d rather than trusted, since checking costs
 * nothing and a salt is PHP's own random_bytes(), not something typed.
 */
function staxx_crypt_hash_command(string $format, string $salt): string {
  $docker = staxx_docker_bin();
  $name   = escapeshellarg(STAXX_CRYPT_CONTAINER);
  switch ($format) {
    case 'bcrypt':   return $docker.' exec -i '.$name.' htpasswd -niB -C 12 staxx';
    case 'sha512':   return $docker.' exec -i '.$name.' mkpasswd -m sha512';
    case 'sha256':   return $docker.' exec -i '.$name.' mkpasswd -m sha256';
    case 'argon2id': return $docker.' exec -i '.$name.' argon2 '.escapeshellarg($salt).' -id -t 3 -m 16 -p 4 -e';
    default:         return '';
  }
}

/** Pulls the hash itself out of each tool's raw stdout, and nothing else. */
function staxx_crypt_extract_hash(string $format, string $raw): string {
  $raw = trim($raw);
  if ($raw === '') return '';

  if ($format === 'bcrypt') {
    // htpasswd -ni prints "staxx:$2y$…" — the name we gave it, then the hash.
    $pos = strpos($raw, ':');
    if ($pos === false) return '';
    $hash = substr($raw, $pos + 1);
    return strpos($hash, '$2') === 0 ? $hash : '';
  }
  if ($format === 'sha512') return strpos($raw, '$6$') === 0 ? $raw : '';
  if ($format === 'sha256') return strpos($raw, '$5$') === 0 ? $raw : '';
  if ($format === 'argon2id') return strpos($raw, '$argon2id$') === 0 ? $raw : '';
  return '';
}

/**
 * Run one command inside the container with a password on its input stream
 * — never as part of the command itself. This cannot be staxx_sh(): that
 * helper explicitly redirects stdin from /dev/null, which is right for
 * every other command in the plugin and wrong for exactly this one. Same
 * protection staxx_sh() gives everything else — wrapped in `timeout -k 2 N`
 * so a tool that hangs waiting for input it never gets cannot hold a PHP
 * worker open forever.
 *
 * $stdin (the password) is written to the pipe and nowhere else — not
 * echoed, not logged, not folded into $error. $err (the process's own
 * stderr) is read so the pipe cannot block on a full buffer, but it is
 * discarded rather than surfaced, on the chance a misbehaving tool ever
 * echoed part of its input back on failure.
 */
function staxx_crypt_run(string $cmd, string $stdin, int $seconds, string &$error): string {
  $error = '';
  $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
  $full = 'timeout -k 2 '.(int)$seconds.' sh -c '.escapeshellarg($cmd);

  $proc = @proc_open($full, $descriptors, $pipes);
  if (!is_resource($proc)) { $error = 'Could not start the cryptography container.'; return ''; }

  fwrite($pipes[0], $stdin);
  fclose($pipes[0]);

  $out = stream_get_contents($pipes[1]);
  stream_get_contents($pipes[2]); // drained, not read — see the note above
  fclose($pipes[1]);
  fclose($pipes[2]);
  $code = proc_close($proc);

  if ($code !== 0 || $out === false) {
    $error = 'The cryptography container did not produce a hash.';
    return '';
  }
  return $out;
}

/* ------------------------------------------------------------- hashing -- */

/**
 * The one function the editor's Fill button actually calls. Refuses before
 * doing anything else if the format is not one the recorded self-test has
 * proven; if the container does not exist, hands back a plain "needs
 * building" so the caller can turn that into an offer rather than starting
 * anything on its own.
 *
 * On-demand mode starts the container if it is not already running, hashes,
 * then stops it again — and the stop happens on every exit from this
 * function once the container has been confirmed reachable, including the
 * failure paths, so a hash that errors can never quietly leave the mode
 * running for good.
 */
function staxx_crypt_hash(string $password, string $format, string &$error): string {
  $error = '';

  // The missing-container check comes FIRST, ahead of the format check, and
  // the order is the whole point rather than a detail. With no container
  // there is no self-test either, so every format reads as unconfirmed —
  // check formats first and the reply is "that format is not offered", which
  // is true but useless: it never says the thing that would fix it, so the
  // caller can never turn it into the offer to build. Answer the more
  // fundamental refusal first and the person is told what to actually do.
  $status = staxx_crypt_container_status();
  if ($status === 'missing') {
    $error = 'The cryptography container needs building before a hash can be made.';
    return '';
  }

  $passing = [];
  foreach (staxx_crypt_state()['formats'] as $f) if ($f['ok']) $passing[$f['id']] = true;
  if (!isset($passing[$format])) {
    $error = 'That hash format has not been confirmed to work on this container, so it is not offered.';
    return '';
  }

  $mode = (string)(staxx_cfg()['CRYPT_MODE'] ?? '') === 'always' ? 'always' : 'ondemand';

  if ($status === 'stopped') {
    $c = null;
    staxx_sh(staxx_docker_bin().' start '.escapeshellarg(STAXX_CRYPT_CONTAINER), 10, $c);
    if ($c !== 0) { $error = 'Could not start the cryptography container.'; return ''; }
  }

  // Deliberately no early return between here and the stop below — see the
  // file header on on-demand mode above.
  $salt = bin2hex(random_bytes(16)); // 32 characters, well over argon2's own 8-character minimum
  $cmd  = staxx_crypt_hash_command($format, $salt);

  $hash = '';
  $runError = '';
  $out = staxx_crypt_run($cmd, $password, 15, $runError);
  if ($out !== '') {
    $hash = staxx_crypt_extract_hash($format, $out);
    if ($hash === '') $runError = 'The cryptography container replied, but not with a hash StaXX recognises.';
  } elseif ($runError === '') {
    $runError = 'The cryptography container did not produce a hash.';
  }

  if ($mode !== 'always') {
    staxx_sh(staxx_docker_bin().' stop -t 2 '.escapeshellarg(STAXX_CRYPT_CONTAINER), 10);
  }

  if ($hash === '') { $error = $runError; return ''; }
  return $hash;
}

/* ------------------------------------------------------------ self-test -- */

/**
 * Hash the fixed throwaway password with every format and prove the answer,
 * then record the result against the recipe of the image the container
 * ACTUALLY runs right now — not the shipped recipe, which may already have
 * moved on. Only formats recorded here are ever offered from
 * staxx_crypt_hash() above.
 *
 * bcrypt and both SHA-crypt formats are genuinely verified with PHP's own
 * password_verify()/crypt(). argon2id cannot be: measured on the server
 * 2026-08-26, the argon2 CLI has no verify mode at all — its --help offers
 * only encoded (-e) and raw (-r) output, nothing that checks a password
 * against an existing hash. So argon2id's check is deliberately weaker and
 * says so in the recorded result: the `$argon2id$` prefix, plus running the
 * same salt and parameters a second time and requiring byte-identical
 * output (also confirmed reproducible on the server that day). That proves
 * the tool is deterministic and shaped right; it does not prove the hash is
 * semantically correct the way the other three are proven.
 */
function staxx_crypt_selftest(string &$error): array {
  $error = '';
  $empty = ['ok' => false, 'formats' => []];

  $imageCode = null;
  $imageId = trim(staxx_sh(
    staxx_docker_bin().' inspect --format '.escapeshellarg('{{.Image}}').' '.escapeshellarg(STAXX_CRYPT_CONTAINER),
    8, $imageCode
  ));
  if ($imageCode !== 0 || $imageId === '') {
    $error = 'The cryptography container does not exist.';
    return $empty;
  }
  $recipeId = (string)(staxx_crypt_image_labels($imageId)['staxx.crypt.recipe'] ?? '');

  $wasStopped = staxx_crypt_container_status() !== 'running';
  if ($wasStopped) {
    $c = null;
    staxx_sh(staxx_docker_bin().' start '.escapeshellarg(STAXX_CRYPT_CONTAINER), 10, $c);
    if ($c !== 0) { $error = 'Could not start the cryptography container to test it.'; return $empty; }
  }

  $formats = [];
  $argon2Method = '';
  foreach (staxx_crypt_format_defs() as $def) {
    $formats[$def['id']] = staxx_crypt_selftest_one($def['id'], $argon2Method);
  }

  if ($wasStopped) {
    staxx_sh(staxx_docker_bin().' stop -t 2 '.escapeshellarg(STAXX_CRYPT_CONTAINER), 10);
  }

  $result = ['recipeId' => $recipeId, 'at' => time(), 'formats' => $formats, 'argon2Method' => $argon2Method];
  if (!staxx_crypt_selftest_write($result)) {
    $error = 'The self-test ran, but its result could not be recorded, so no format will be offered '
           . 'until it is run again successfully.';
    return $empty;
  }

  return ['ok' => in_array(true, $formats, true), 'formats' => $formats];
}

/** One format's own pass/fail, run against STAXX_CRYPT_TEST_PASSWORD. */
function staxx_crypt_selftest_one(string $format, string &$argon2Method): bool {
  $salt = bin2hex(random_bytes(16));
  $err  = '';
  $out  = staxx_crypt_run(staxx_crypt_hash_command($format, $salt), STAXX_CRYPT_TEST_PASSWORD, 15, $err);
  $hash = staxx_crypt_extract_hash($format, $out);
  if ($hash === '') return false;

  if ($format === 'bcrypt') return password_verify(STAXX_CRYPT_TEST_PASSWORD, $hash);
  if ($format === 'sha512' || $format === 'sha256') return crypt(STAXX_CRYPT_TEST_PASSWORD, $hash) === $hash;

  if ($format === 'argon2id') {
    $argon2Method = 'reproduced'; // see staxx_crypt_selftest()'s header for why not "verified"
    $err2 = '';
    $again = staxx_crypt_run(staxx_crypt_hash_command('argon2id', $salt), STAXX_CRYPT_TEST_PASSWORD, 15, $err2);
    return staxx_crypt_extract_hash('argon2id', $again) === $hash;
  }

  return false;
}

/* --------------------------------------------------------------- guards --
 *
 * Both pure and Docker-free on purpose, so tests/server/crypt.php can prove
 * them without building, starting or removing anything.
 */

/**
 * Which of a label-filtered image list are safe to remove: StaXX's own
 * label present AND a recipe that is not the one currently in force. An
 * image without the label is never selected, whatever its name or tag looks
 * like — that check is repeated here even though staxx_crypt_images()
 * already filters on it, because this function must be safe to trust on its
 * own, not only when fed that one caller's output.
 */
function staxx_crypt_superseded_images(array $images, string $currentRecipe): array {
  $out = [];
  foreach ($images as $img) {
    if (!is_array($img) || ($img['labeled'] ?? false) !== true) continue;
    $recipe = (string)($img['recipe'] ?? '');
    if ($recipe === '' || $recipe === $currentRecipe) continue;
    $out[] = $img;
  }
  return $out;
}

/**
 * May the superseded image actually be removed? Only once the replacement
 * has done everything the one it would replace could — building a container
 * is not the same as it working. $was/$nowPassing are format id => bool,
 * the shape staxx_crypt_state()['formats'] reduces to.
 */
function staxx_crypt_rebuild_may_remove(array $wasPassing, array $nowPassing): bool {
  // A self-test that passed nothing at all is not a working container,
  // whatever the old one used to manage — refused regardless of $wasPassing.
  if (!in_array(true, $nowPassing, true)) return false;
  foreach ($wasPassing as $id => $ok) {
    if ($ok && !($nowPassing[$id] ?? false)) return false;
  }
  return true;
}

/* --------------------------------------------------------- build & jobs -- */

/**
 * The actual build: copy the shipped recipe into a scratch folder, build
 * and label the image, replace the container, run the self-test. Runs
 * inside a detached job (staxx_crypt_build()/staxx_crypt_rebuild() below) —
 * never called directly by anything that could reach it as a side effect of
 * an ordinary page load, per PLAN_74's rule that nothing here may build
 * without being asked. Progress is echoed to stdout, which lands in the
 * job's own log; nothing printed here ever touches a password.
 */
function staxx_crypt_do_build(string &$error): bool {
  $error = '';
  $recipeId = staxx_crypt_recipe_id();
  if ($recipeId === '') { $error = 'Could not read the recipe file.'; return false; }

  $scratch = '/tmp/staxx-crypt-build-'.bin2hex(random_bytes(6));
  if (!@mkdir($scratch, 0700, true)) { $error = 'Could not create a scratch build folder.'; return false; }
  if (!@copy(STAXX_ROOT.'/crypt/Dockerfile', $scratch.'/Dockerfile')) {
    staxx_rmtree($scratch, $scratch);
    $error = 'Could not copy the recipe into the scratch build folder.';
    return false;
  }

  $tag = 'staxxcrypt:'.substr($recipeId, 0, 12);
  echo "Building $tag ...\n";
  $code = null;
  echo staxx_sh(
    staxx_docker_bin().' build --label '.escapeshellarg('staxx.crypt=1')
    .' --label '.escapeshellarg('staxx.crypt.recipe='.$recipeId)
    .' -t '.escapeshellarg($tag).' '.escapeshellarg($scratch),
    600, $code
  )."\n";
  staxx_rmtree($scratch, $scratch); // best-effort tidy-up; a leftover scratch folder is harmless either way
  if ($code !== 0) { $error = 'The image failed to build.'; return false; }

  echo 'Creating the '.STAXX_CRYPT_CONTAINER." container ...\n";
  // A stopped container from an earlier, failed attempt would otherwise
  // block `docker create` on a name already in use — removed here, not
  // treated as the "never remove StaXXCrypt" case, because this only ever
  // runs from the explicit build/rebuild the person just asked for.
  staxx_sh(staxx_docker_bin().' rm -f '.escapeshellarg(STAXX_CRYPT_CONTAINER), 10);

  echo staxx_sh(
    staxx_docker_bin().' create --name '.escapeshellarg(STAXX_CRYPT_CONTAINER)
    // No volume, no port, no network, nothing mounted — it reads a password
    // and prints a hash, and anything more is a way in. --read-only was
    // confirmed on the server 2026-08-26 not to break any of the three
    // tools: none of them need to write anything.
    .' --network none --read-only '.escapeshellarg($tag),
    30, $code
  )."\n";
  if ($code !== 0) { $error = 'The container could not be created.'; return false; }

  echo "Running the self-test ...\n";
  $stErr = '';
  $result = staxx_crypt_selftest($stErr);
  if (!$result['ok']) {
    $error = $stErr !== '' ? $stErr : 'The self-test found no working hash format.';
    return false;
  }

  // A build leaves a freshly created container stopped, and the self-test
  // starts and stops it like any other hash. That is right for on-demand and
  // wrong for always-running: the mode promises the container is already up,
  // so without this the first hash after a build quietly pays the start cost
  // the person chose that mode to avoid.
  if ((string)(staxx_cfg()['CRYPT_MODE'] ?? '') === 'always') {
    echo "Starting it, because the mode is always-running ...\n";
    staxx_sh(staxx_docker_bin().' start '.escapeshellarg(STAXX_CRYPT_CONTAINER), 15);
  }

  echo "Done.\n";
  return true;
}

/**
 * Rebuild: the same build as above, but only removes the superseded image
 * once the new container has proven it can do at least everything the old
 * one could. The container itself is always replaced — that is what a
 * rebuild means — but the OLD IMAGE is kept on disk until that proof exists,
 * so it remains the way back if the new one built but hashed wrongly. This
 * is the one place StaXX deletes something without asking, defensible only
 * because what is deleted is an image StaXX itself built, for its own use,
 * identified by its own label rather than by its name — never `StaXXCrypt`
 * the container itself, which is never removed by StaXX at all.
 */
function staxx_crypt_do_rebuild(string &$error): bool {
  $error = '';

  $wasPassing = [];
  foreach (staxx_crypt_state()['formats'] as $f) $wasPassing[$f['id']] = $f['ok'];
  $oldImages = staxx_crypt_images();

  if (!staxx_crypt_do_build($error)) return false;

  $nowPassing = [];
  foreach (staxx_crypt_state()['formats'] as $f) $nowPassing[$f['id']] = $f['ok'];

  if (!staxx_crypt_rebuild_may_remove($wasPassing, $nowPassing)) {
    $error = 'The new cryptography container was built, but it did not reproduce every hash '
           . 'format the previous one could — the previous image has been left in place.';
    return false;
  }

  foreach (staxx_crypt_superseded_images($oldImages, staxx_crypt_recipe_id()) as $img) {
    echo 'Removing the superseded image '.$img['id']."...\n";
    staxx_sh(staxx_docker_bin().' rmi '.escapeshellarg($img['id']), 30);
  }

  return true;
}

/**
 * Start either the build or the rebuild as a detached job, following
 * staxx_start_job()'s own setsid/log/sentinel shape exactly, so the page's
 * existing `job` poller can follow this one unmodified. Neither of these two
 * functions is ever called anywhere else — see staxx_crypt_do_build()'s own
 * header for why that matters.
 */
function staxx_crypt_start_job(bool $rebuild, string &$error): string {
  $error = '';
  if (!staxx_docker_running()) { $error = 'The Docker service is not running.'; return ''; }
  if (!staxx_private_dir(STAXX_JOB_DIR)) { $error = 'Could not create '.STAXX_JOB_DIR; return ''; }

  $job = bin2hex(random_bytes(8));
  $log = STAXX_JOB_DIR.'/'.$job.'.log';

  // php -r with var_export(), the same pattern staxx_update_check_start()
  // (Updates.php) uses to run one of this plugin's own functions as a
  // detached process — never a password anywhere near this string, since
  // building involves none.
  $fn = $rebuild ? 'staxx_crypt_do_rebuild' : 'staxx_crypt_do_build';
  $php = staxx_crypt_php_bin().' -r '.escapeshellarg(
    'require '.var_export(__DIR__.'/Crypt.php', true).'; '
    .'$e = ""; '
    .'$ok = '.$fn.'($e); '
    .'if (!$ok) fwrite(STDERR, $e."\n"); '
    .'exit($ok ? 0 : 1);'
  );
  $inner = $php.' 2>&1; echo "'.STAXX_JOB_END.' $?"';

  @file_put_contents($log, '$ '.($rebuild ? 'rebuilding' : 'building').' the cryptography container'."\n\n");
  @chmod($log, 0600);

  @exec('setsid sh -c '.escapeshellarg($inner).' </dev/null >> '.escapeshellarg($log).' 2>&1 &');

  return $job;
}

function staxx_crypt_build(string &$error): string   { return staxx_crypt_start_job(false, $error); }
function staxx_crypt_rebuild(string &$error): string  { return staxx_crypt_start_job(true, $error); }

/**
 * Local copy of staxx_php_bin() (Updates.php): resolving the interpreter's
 * own absolute path, since PHP's environment here is not a login shell and
 * PATH cannot be relied on. Not shared with Updates.php on purpose — this
 * file has no other reason to require anything that heavy, and three lines
 * duplicated is cheaper than a dependency on an unrelated file.
 */
function staxx_crypt_php_bin(): string {
  static $bin = null;
  if ($bin !== null) return $bin;
  foreach (['/usr/bin/php', '/usr/local/bin/php'] as $path) {
    if (is_file($path) && is_executable($path)) return $bin = $path;
  }
  return $bin = 'php';
}
?>