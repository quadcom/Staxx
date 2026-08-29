<?php
/* PLAN_76/PLAN_98 — the export route. See PLAN_98.md for the stage table;
 * each stage lands its own section below, added by whichever agent builds
 * that stage, so read the section headings rather than assuming this file
 * is all one piece.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine.
 *
 * Stage 1's cases need no config keys at all: staxx_placeholders() takes a
 * file path directly, so its fixtures are plain files under /tmp. The
 * staxx_start_job() cases DO need STORE_ROOT, the same way tests/server/
 * record.php and files.php do it — pointed at /tmp/zze-store, never the
 * real store:
 *
 *     pscp tests/server/export.php root@<box>:/tmp/
 *     plink … '
 *       CFG=/boot/config/plugins/staxx/staxx.cfg
 *       cp $CFG /tmp/cfg.bak
 *       grep -q "^STORE_ROOT=" $CFG \
 *         && sed -i "s#^STORE_ROOT=.*#STORE_ROOT=\"/tmp/zze-store\"#" $CFG \
 *         || echo "STORE_ROOT=\"/tmp/zze-store\"" >> $CFG
 *       php /tmp/export.php; RC=$?
 *       cp /tmp/cfg.bak $CFG
 *       exit $RC
 *     '
 *
 * Prints one line per case and exits non-zero on any failure. Creates and
 * removes its own stacks, "zze…", under the temporary stack root. Stage 2
 * (the file sorter) and Stage 5 (the packer) reuse that same STORE_ROOT
 * rather than asking for a config of their own — a symlink fixture cannot
 * live on the flash drive (vfat) either way, so /tmp/zze-store is exactly
 * where those need to be built regardless.
 *
 * MUST NEVER RUN DOCKER. Every staxx_start_job() call below is arranged to
 * terminate in a refusal that returns before the function ever builds or
 * detaches a real command — either the placeholder refusal itself, or a
 * downstream refusal (an unknown service, or a verb with no form for the
 * scope asked) reached only because the placeholder gate let it through.
 * Deliberately not exercised here: a whole-stack "up" on a genuinely clean
 * file, because nothing safe stops it short of a real `docker compose up` —
 * staxx_placeholders() returning [] for that same fixture (asserted below)
 * is the proof that stands in for it. */

require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

/* ============================================================
 * Stage 1 — a half-filled recipe will not start
 * ============================================================ */

/* ---------------------------------------------- staxx_placeholders() ---- */
// Unit-level: a plain file path in, a list of descriptions out. No STORE_ROOT
// needed for any of these — the function never looks past the file it is handed.

$tmp = '/tmp/zze-placeholders';
@exec('rm -rf '.escapeshellarg($tmp));
mkdir($tmp, 0755, true);

$clean = $tmp.'/clean.yaml';
file_put_contents($clean, "services:\n  web:\n    image: nginx:1.27\n    ports:\n      - \"8080:80\"\n");
ok('a file with no placeholder yields nothing', staxx_placeholders($clean) === []);

$one = $tmp.'/one.yaml';
file_put_contents($one, "services:\n  db:\n    image: postgres:16\n    environment:\n      DB_PASSWORD: REPLACE-ME\n");
$found = staxx_placeholders($one);
ok('one placeholder yields one description', count($found) === 1, implode('; ', $found));
ok('it names the setting and the service',
   $found !== [] && stripos($found[0], 'password') !== false && stripos($found[0], 'db') !== false,
   $found[0] ?? '');

$several = $tmp.'/several.yaml';
file_put_contents($several,
  "services:\n".
  "  db:\n".
  "    image: postgres:16\n".
  "    environment:\n".
  "      DB_PASSWORD: REPLACE-ME\n".
  "      ADMIN_PASSWORD: REPLACE-ME\n".
  "  jellyfin:\n".
  "    image: jellyfin/jellyfin\n".
  "    volumes:\n".
  "      - MEDIA_FOLDER=REPLACE-ME\n"
);
$found = staxx_placeholders($several);
ok('three placeholders yield three descriptions', count($found) === 3, implode('; ', $found));
ok('each names its own service',
   $found !== [] &&
   count(array_filter($found, fn($d) => stripos($d, 'in service db') !== false)) === 2 &&
   count(array_filter($found, fn($d) => stripos($d, 'in service jellyfin') !== false)) === 1,
   implode('; ', $found));

$dup = $tmp.'/dup.yaml';
file_put_contents($dup,
  "services:\n".
  "  web:\n".
  "    image: nginx:1.27\n".
  "    ports:\n".
  "      - \"REPLACE-ME:80\"\n".
  "      - \"REPLACE-ME:443\"\n"
);
ok('the same setting repeated is reported once', staxx_placeholders($dup) === ['a port (in service web)'],
   implode('; ', staxx_placeholders($dup)));

ok('an unreadable file yields nothing rather than throwing', staxx_placeholders($tmp.'/does-not-exist.yaml') === []);

/* ------------------------------------------------- staxx_start_job() ---- */
// Needs STORE_ROOT — see the header. Every case here is refused, and every
// refusal is reached before staxx_start_job() would ever touch a shell; see
// the "MUST NEVER RUN DOCKER" note above for why each one is shaped as it is.

if (staxx_stack_root() !== '/tmp/zze-store/stacks') {
  echo "FAIL   the temporary stack root is not in place (got ".staxx_stack_root().")\n";
  exit(max($fails, 1));
}

$root = staxx_stack_root();
@exec('rm -rf '.escapeshellarg($root));
mkdir($root, 0755, true);

// A stack with one placeholder in it — used for the refusal cases below.
$filledRel = 'zzefilled';
$filledDir = $root.'/'.$filledRel;
mkdir($filledDir, 0755, true);
file_put_contents($filledDir.'/compose.yaml',
  "services:\n  a:\n    image: alpine:3.20\n    environment:\n      API_TOKEN: REPLACE-ME\n");

// A clean stack — used to prove the gate does not fire when there is nothing
// waiting, without ever letting a whole-stack "up" reach a real docker call.
$cleanRel = 'zzeclean';
$cleanDir = $root.'/'.$cleanRel;
mkdir($cleanDir, 0755, true);
file_put_contents($cleanDir.'/compose.yaml', "services:\n  a:\n    image: alpine:3.20\n");

$err = '';
$r = staxx_start_job($filledRel, 'up', $err, '');
ok('whole-stack start is refused on a filled placeholder',
   $r === '' && stripos($err, 'api token') !== false && stripos($err, 'in service a') !== false, $err);
ok('the whole-stack refusal says what to do next', stripos($err, 'Open the stack') !== false, $err);

$err = '';
$r = staxx_start_job($filledRel, 'up', $err, 'a');
ok('single-service start is refused on the same file',
   $r === '' && stripos($err, 'api token') !== false, $err);

// Several waiting values — the count word and the full list both matter.
$manyRel = 'zzemany';
$manyDir = $root.'/'.$manyRel;
mkdir($manyDir, 0755, true);
file_put_contents($manyDir.'/compose.yaml',
  "services:\n".
  "  db:\n".
  "    image: postgres:16\n".
  "    environment:\n".
  "      DB_PASSWORD: REPLACE-ME\n".
  "      ADMIN_PASSWORD: REPLACE-ME\n"
);
$err = '';
$r = staxx_start_job($manyRel, 'up', $err, '');
// The names are the author's own, softened into words rather than expanded:
// DB_PASSWORD reads back as "the db password", not "the database password".
// Guessing what an abbreviation stands for is how a message ends up confidently
// wrong about somebody else's file, so nothing here invents a longer word.
ok('two waiting values are both named and the count word is right',
   $r === '' && stripos($err, 'Two values still need filling in') !== false
   && stripos($err, 'db password') !== false && stripos($err, 'admin password') !== false, $err);

// `remove` has no whole-stack form at all, and `config` has no single-service
// form, so both are refused by the scope check itself — never even reach the
// placeholder gate — which is still proof they are unaffected by whatever is
// sitting in the file.
$err = '';
$r = staxx_start_job($filledRel, 'remove', $err, '');
ok('a verb that does not start anything is unaffected by the placeholder (whole-stack)',
   $r === '' && stripos($err, 'filling in') === false && stripos($err, 'whole stack') !== false, $err);

$err = '';
$r = staxx_start_job($filledRel, 'config', $err, 'a');
ok('a verb that does not start anything is unaffected by the placeholder (service)',
   $r === '' && stripos($err, 'filling in') === false && stripos($err, 'single container') !== false, $err);

// `down` DOES reach the placeholder gate (both scopes have a form for it) and
// is skipped there because it does not start anything — proved by landing on
// the real, safe refusal one step further on (an unknown service name)
// rather than on a whole-stack real invocation, which single-service scope
// lets this stop short of.
$err = '';
$r = staxx_start_job($filledRel, 'down', $err, 'does-not-exist');
ok('down reaches the placeholder gate and is waved through by it',
   $r === '' && stripos($err, 'filling in') === false
   && stripos($err, 'No service called') !== false, $err);

// The clean stack is never driven through a whole-stack "up" — see the header
// note. Single-service scope with a service name that does not exist gives a
// safe, real refusal downstream of the placeholder gate, which proves the
// gate did not fire for a file with nothing waiting in it.
ok('the clean fixture itself carries no placeholder', staxx_placeholders($cleanDir.'/compose.yaml') === []);
$err = '';
$r = staxx_start_job($cleanRel, 'up', $err, 'does-not-exist');
ok('a clean file is not refused for a placeholder (reaches the real service check instead)',
   $r === '' && stripos($err, 'filling in') === false
   && stripos($err, 'No service called') !== false, $err);

@exec('rm -rf '.escapeshellarg($tmp));

/* ============================================================
 * Stage 2 — sorting a stack's files
 * ============================================================
 *
 * staxx_export_sort() over a stack built to carry one of everything: the
 * hidden record folder, both redactable kinds, an ordinary text file, and
 * one of each refusal — plus the two cases that matter most, proved
 * separately below: a refused file the compose file also names stays
 * "needed" without becoming permitted, and a symlink named ".env" is
 * refused rather than treated as blankable.
 *
 * Reuses $root from Stage 1 above (STORE_ROOT is already pointed at
 * /tmp/zze-store for this whole run) rather than asking for it again.
 */

$sortRel = 'zzesort';
$sortDir = $root.'/'.$sortRel;
@exec('rm -rf '.escapeshellarg($sortDir));
mkdir($sortDir, 0755, true);

// The compose file names two companions: .env as an env_file, and the
// (refused) key file as a bind mount — both have to come back "needed".
file_put_contents($sortDir.'/compose.yaml',
  "services:\n".
  "  a:\n".
  "    image: alpine:3.20\n".
  "    env_file:\n".
  "      - .env\n".
  "    volumes:\n".
  "      - ./keyfile.pem:/certs/key.pem:ro\n"
);
file_put_contents($sortDir.'/.env', "TOKEN=secret\n");
file_put_contents($sortDir.'/plain.txt', "just some notes, nothing sensitive\n");
file_put_contents($sortDir.'/keyfile.pem', "not a real key, just named like one\n");
file_put_contents($sortDir.'/renamed-secret.txt',
  "-----BEGIN RSA PRIVATE KEY-----\nMIIBOgIBAAJBAK0=\n-----END RSA PRIVATE KEY-----\n");
file_put_contents($sortDir.'/binary.dat', "\x00\x01\x02binary-looking bytes");
file_put_contents($sortDir.'/big.txt', str_repeat('a', STAXX_FILE_MAX + 1));
mkdir($sortDir.'/subfolder', 0755, true);
symlink($sortDir.'/plain.txt', $sortDir.'/reglink');
mkdir($sortDir.'/'.STAXX_RECORD_DIR, 0755, true);
file_put_contents($sortDir.'/'.STAXX_RECORD_DIR.'/note.json', '{}');

$err = '';
$sorted = staxx_export_sort($sortRel, $err);
ok('the sorter runs over the fixture', $sorted !== null, $err);

$byName = [];
foreach ((array)$sorted as $row) $byName[$row['name']] = $row;

ok('the hidden record folder is absent from the listing entirely',
   !isset($byName[STAXX_RECORD_DIR]));

ok('the compose file is redactable', ($byName['compose.yaml']['kind'] ?? '') === 'redactable');
ok('and it carries needed => true', ($byName['compose.yaml']['needed'] ?? null) === true);

ok('.env is redactable', ($byName['.env']['kind'] ?? '') === 'redactable');
ok('.env carries needed => true because the compose file loads it as an env_file',
   ($byName['.env']['needed'] ?? null) === true
   && stripos($byName['.env']['needed_why'] ?? '', 'env_file') !== false,
   $byName['.env']['needed_why'] ?? '');

ok('an ordinary text file is "read"', ($byName['plain.txt']['kind'] ?? '') === 'read');
ok('and is not marked needed', ($byName['plain.txt']['needed'] ?? null) === false);

ok('a private key renamed to something innocuous is refused, caught by its first line',
   ($byName['renamed-secret.txt']['kind'] ?? '') === 'refused'
   && stripos($byName['renamed-secret.txt']['why'] ?? '', 'private key') !== false,
   $byName['renamed-secret.txt']['why'] ?? '');

ok('a key file is refused by its extension',
   ($byName['keyfile.pem']['kind'] ?? '') === 'refused'
   && stripos($byName['keyfile.pem']['why'] ?? '', 'key or certificate') !== false,
   $byName['keyfile.pem']['why'] ?? '');

ok('a binary file is refused',
   ($byName['binary.dat']['kind'] ?? '') === 'refused'
   && stripos($byName['binary.dat']['why'] ?? '', 'binary') !== false,
   $byName['binary.dat']['why'] ?? '');

ok('an oversized file is refused',
   ($byName['big.txt']['kind'] ?? '') === 'refused'
   && stripos($byName['big.txt']['why'] ?? '', 'KiB limit') !== false,
   $byName['big.txt']['why'] ?? '');

ok('a directory is refused',
   ($byName['subfolder']['kind'] ?? '') === 'refused'
   && stripos($byName['subfolder']['why'] ?? '', 'folder') !== false,
   $byName['subfolder']['why'] ?? '');

ok('a symlink is refused',
   ($byName['reglink']['kind'] ?? '') === 'refused'
   && stripos($byName['reglink']['why'] ?? '', 'link') !== false,
   $byName['reglink']['why'] ?? '');

ok('a refused file the compose file also names stays needed without becoming permitted',
   ($byName['keyfile.pem']['needed'] ?? null) === true
   && ($byName['keyfile.pem']['kind'] ?? '') === 'refused',
   json_encode($byName['keyfile.pem'] ?? null));

/* REGRESSION GUARD: refusals are settled before anything is called
 * blankable, in the source itself. Written the other way round — checking
 * for ".env" before checking for a link — a symlink named ".env" would be
 * treated as the redactable kind, and whatever it points at would leave
 * with the export instead of the file that was actually chosen. A symlink
 * fixture cannot live on the flash drive (vfat), so this — like the rest of
 * this file — lives entirely under /tmp; see tests/server/links.php. */
$envLinkRel = 'zzesortenvlink';
$envLinkDir = $root.'/'.$envLinkRel;
@exec('rm -rf '.escapeshellarg($envLinkDir).' /tmp/zze-outside');
mkdir($envLinkDir, 0755, true);
mkdir('/tmp/zze-outside', 0755, true);
file_put_contents('/tmp/zze-outside/target.env', "SECRET=should-not-leak\n");
file_put_contents($envLinkDir.'/compose.yaml', "services:\n  a:\n    image: alpine:3.20\n");
symlink('/tmp/zze-outside/target.env', $envLinkDir.'/.env');

$err = '';
$envSorted = staxx_export_sort($envLinkRel, $err);
$envByName = [];
foreach ((array)$envSorted as $row) $envByName[$row['name']] = $row;
ok('REGRESSION GUARD: a symlink named .env is refused, not treated as blankable',
   ($envByName['.env']['kind'] ?? '') === 'refused'
   && stripos($envByName['.env']['why'] ?? '', 'link') !== false,
   json_encode($envByName['.env'] ?? null));

@exec('rm -rf '.escapeshellarg($sortDir).' '.escapeshellarg($envLinkDir).' /tmp/zze-outside');

/* ============================================================
 * Stage 5 — packing what leaves
 * ============================================================
 *
 * staxx_export_pack() over name-and-contents pairs only. It is never handed
 * a folder, so proving it "never reads the real stack's folder" means
 * building a real stack on disk, handing the packer different content under
 * the same name, and checking that the different content is what comes out.
 */

/** Unzip $bytes into $outDir (wiped first) and return [exit code, output lines]. */
function zze_unzip(string $bytes, string $outDir): array {
  @exec('rm -rf '.escapeshellarg($outDir));
  mkdir($outDir, 0755, true);
  $zipFile = $outDir.'.zip';
  file_put_contents($zipFile, $bytes);
  exec('cd '.escapeshellarg($outDir).' && unzip -o -q '.escapeshellarg($zipFile).' 2>&1', $lines, $code);
  @unlink($zipFile);
  return [$code, $lines];
}

/** Every "export-*" working folder currently left under the packer's temp dir. */
function zze_pack_leftovers(): array {
  return glob(STAXX_CFILE_TMP.'/export-*') ?: [];
}

// The reason is asserted, not merely that it refused. Written the loose way,
// this case passed even with the name check deleted outright — "a/b.txt" then
// failed a step later, when writing into a folder that does not exist, and a
// refusal for the wrong reason reads exactly like a working guard. Proved by
// deleting the check and watching this case stay green.
$err = '';
$bad = staxx_export_pack([['name' => 'a/b.txt', 'content' => 'x']], 'zzepack', $err);
ok('the packer refuses a bad name, and refuses it AS a bad name',
   $bad === '' && stripos($err, 'is not a name this can export') !== false, $err);
ok('and nothing was left behind on that failure path', zze_pack_leftovers() === []);

// The name that matters most: one climbing out of the folder the packer built
// for itself. Refused by name, so it never reaches a write at all.
$err = '';
// One level up from the folder the packer builds in — which is where a name
// beginning "../" actually lands, not /tmp. Aimed at the wrong folder this
// assertion cannot fail, whatever the code does.
$escapeProbe = STAXX_CFILE_TMP.'/zze-escape-probe.txt';
@unlink($escapeProbe);
$out = staxx_export_pack([['name' => '../zze-escape-probe.txt', 'content' => 'x']], 'zzepack', $err);
ok('the packer refuses a name that climbs out of its own folder',
   $out === '' && stripos($err, 'is not a name this can export') !== false, $err);
ok('and nothing was written outside that folder', !file_exists($escapeProbe));
ok('and nothing was left behind on that failure path', zze_pack_leftovers() === []);

$err = '';
$overSize = [];
for ($i = 0; $i < 3; $i++) {
  $overSize[] = ['name' => 'big'.$i.'.txt', 'content' => str_repeat('x', 900000)];
}
$bad = staxx_export_pack($overSize, 'zzepack', $err);
ok('the packer refuses to exceed the total-size cap',
   $bad === '' && stripos($err, 'KiB limit') !== false, $err);
ok('and nothing was left behind on that failure path', zze_pack_leftovers() === []);

$err = '';
$tooMany = [];
for ($i = 0; $i < STAXX_EXPORT_FILES_MAX + 1; $i++) {
  $tooMany[] = ['name' => 'f'.$i.'.txt', 'content' => 'x'];
}
$bad = staxx_export_pack($tooMany, 'zzepack', $err);
ok('the packer refuses to exceed the file-count cap',
   $bad === '' && stripos($err, 'file limit') !== false, $err);
ok('and nothing was left behind on that failure path', zze_pack_leftovers() === []);

$err = '';
$pairs = [
  ['name' => 'compose.yaml', 'content' => "services:\n  a:\n    image: alpine:3.20\n"],
  ['name' => 'notes.txt',    'content' => "some plain notes\n"],
];
$zip = staxx_export_pack($pairs, 'zzepack', $err);
ok('the packer produces a zip on a clean set of pairs', $zip !== '', $err);
ok('and nothing was left behind on that success path', zze_pack_leftovers() === []);

[$code, $unzipOut] = zze_unzip($zip, '/tmp/zze-unpacked');
ok('the zip unpacks cleanly', $code === 0, implode(' ', $unzipOut));
$entries = array_values(array_diff(scandir('/tmp/zze-unpacked') ?: [], ['.', '..']));
sort($entries);
ok('the zip holds exactly the names it was handed and nothing else',
   $entries === ['compose.yaml', 'notes.txt'], implode(', ', $entries));
ok('the compose file content round-trips byte for byte',
   file_get_contents('/tmp/zze-unpacked/compose.yaml') === $pairs[0]['content']);
ok('the notes file content round-trips byte for byte',
   file_get_contents('/tmp/zze-unpacked/notes.txt') === $pairs[1]['content']);

// A real stack on disk, so the "never reads the real folder" case has
// something real to prove it did NOT read.
$realRel = 'zzepackreal';
$realDir = $root.'/'.$realRel;
@exec('rm -rf '.escapeshellarg($realDir));
mkdir($realDir, 0755, true);
$realContent = "services:\n  a:\n    image: THE-REAL-FILE-ON-DISK\n";
file_put_contents($realDir.'/compose.yaml', $realContent);

$err = '';
$differentContent = "services:\n  a:\n    image: WHAT-THE-CALLER-HANDED-IN\n";
$zip = staxx_export_pack([['name' => 'compose.yaml', 'content' => $differentContent]], $realRel, $err);
ok('a pair named after a compose filename is packed as given', $zip !== '', $err);

[$code, ] = zze_unzip($zip, '/tmp/zze-unpacked-real');
$got = @file_get_contents('/tmp/zze-unpacked-real/compose.yaml');
ok('the packer never read the real stack folder — the handed-in content came out, not the file on disk',
   $got === $differentContent && $got !== $realContent, (string)$got);

@exec('rm -rf '.escapeshellarg($realDir).' /tmp/zze-unpacked /tmp/zze-unpacked-real');

@exec('rm -rf '.escapeshellarg($root));

echo $fails === 0 ? "\nAll passed.\n" : "\n$fails failed.\n";
exit($fails === 0 ? 0 : 1);
