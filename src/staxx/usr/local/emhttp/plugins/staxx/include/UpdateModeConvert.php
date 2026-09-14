<?PHP
/* StaXX — PLAN_150 phase 7: the one-pass conversion of the old update.mode
 * spellings ('off', 'notify') to the current one ('manual').
 * Copyright 2026, StaXX contributors.
 *
 * The reader has accepted 'off' and 'notify' as 'manual' since phase 1, for
 * good — nothing here is what makes an old file work. This is tidying, run
 * once at plugin install/upgrade (see staxx.plg), never on a settings save
 * or a page load. Adrian chose a one-pass rewrite over converting each stack
 * on its next save (the plan file's "The conversion pass" records the
 * disagreement); this file is that choice, built as safely as a rewrite of
 * every stack on the box can be built: nothing is touched without docker
 * compose itself having accepted the file first, only the exact `mode:`
 * line is ever rewritten, and every original is kept for a plain undo.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/UpdateRun.php';

if (defined('STAXX_UPDATE_CONVERT_LOADED')) return;
define('STAXX_UPDATE_CONVERT_LOADED', true);

/* --------------------------------------------------------------- record -- */

/** Where this pass keeps its own record — separate from a stack's ordinary
 * .staxx history, because that history prunes its oldest unnamed versions
 * and an upgrade-wide undo must not depend on nobody having saved 20 times
 * since. '' when no store has been chosen, same as every other store path. */
function staxx_update_convert_dir(): string {
  $cfg = staxx_config_root();
  return $cfg === '' ? '' : $cfg.'/update-mode-convert';
}

function staxx_update_convert_record_path(): string {
  $dir = staxx_update_convert_dir();
  return $dir === '' ? '' : $dir.'/record.json';
}

/** The decoded record, or [] for anything not well-formed — missing,
 * unreadable, bad JSON, wrong "v". Best-effort like Record.php: a damaged
 * record reads as "never run", never a fatal. */
function staxx_update_convert_read(): array {
  $path = staxx_update_convert_record_path();
  if ($path === '' || !@is_file($path)) return [];
  $raw = @file_get_contents($path);
  if ($raw === false || $raw === '') return [];
  $data = json_decode($raw, true);
  if (!is_array($data) || ($data['v'] ?? null) !== 1) return [];
  if (!is_array($data['files'] ?? null)) $data['files'] = [];
  return $data;
}

/** Has the pass already run on this store? The gate that makes it a ONE
 * pass — checked before anything else, so a store with no old spellings
 * left, or one this already visited, costs nothing beyond this one read. */
function staxx_update_convert_done(): bool {
  return !empty(staxx_update_convert_read()['done']);
}

/** Was this particular stack one the pass rewrote? Read by the editor (via
 * action.php's 'read') to decide whether to show the foot-of-editor line.
 * Best-effort: a missing or damaged record reads as "no", never an error. */
function staxx_update_convert_was_converted(string $rel): bool {
  foreach (staxx_update_convert_read()['files'] ?? [] as $f) {
    if (($f['stack'] ?? null) === $rel) return true;
  }
  return false;
}

/** Append one converted file's before/after to the record, atomically, as
 * soon as it is written — not batched to the end of the run. A pass across
 * every stack on the box that died partway must still leave every file it
 * already rewrote undoable; only "done" itself is set once, at the very
 * end, so a run cut short simply finds nothing left to do on retry (every
 * file it already touched now reads 'manual', not 'off'/'notify') rather
 * than converting anything twice. */
function staxx_update_convert_append(array $entry): void {
  $dir = staxx_update_convert_dir();
  if ($dir === '') return;
  if (!is_dir($dir.'/before') && !@mkdir($dir.'/before', 0755, true)) return;

  $record = staxx_update_convert_read();
  $files  = $record['files'] ?? [];
  $files[] = $entry;
  $json = json_encode(['v' => 1, 'done' => (bool)($record['done'] ?? false),
                        'done_at' => $record['done_at'] ?? null, 'files' => $files],
                       JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  if ($json === false) return;
  staxx_record_atomic_write($dir.'/record.json', $json."\n");
}

function staxx_update_convert_mark_done(): void {
  $dir = staxx_update_convert_dir();
  if ($dir === '' || (!is_dir($dir) && !@mkdir($dir, 0755, true))) return;
  $record = staxx_update_convert_read();
  $json = json_encode(['v' => 1, 'done' => true, 'done_at' => time(),
                        'files' => $record['files'] ?? []],
                       JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  if ($json === false) return;
  staxx_record_atomic_write($dir.'/record.json', $json."\n");
}

/* ---------------------------------------------------------------- scan --- */

/**
 * Find the exact line, in a stack's own RAW text, holding one x-unraid
 * update.mode value — never `docker compose config`'s canonical rewrite,
 * which would lose comments, quoting and anchors. Returns a map of the
 * requested paths (each a list like ['x-unraid','update','mode'] or
 * ['services','web','x-unraid','update','mode'], joined here with "\0" —
 * the same separator staxx_yaml_flatten() uses) to a 0-based line index.
 * A path missing from the return is one this scanner declined to guess
 * at, never a wrong line.
 *
 * This is NOT a YAML parser. It tracks only what it needs to walk down to
 * `update:` under `x-unraid:` — indentation, and which lines open a nested
 * block with nothing else on them — and it refuses the WHOLE file (returns
 * []) the instant it sees a construct it does not model: a tab, a second
 * YAML document, or a flow collection (`{`/`[`) outside a quoted string
 * anywhere in the file. A block scalar (`|`/`>`) is skipped over by its own
 * indentation rule rather than causing a refusal — a multi-line description
 * is the single most common thing in these files, and refusing every stack
 * that has one would defeat the point of this pass.
 *
 * @return array<string,int>
 */
function staxx_update_convert_locate(string $yaml, array $wantPaths): array {
  if (strpos($yaml, "\t") !== false) return [];
  if (preg_match('/(^|\n)\s*---\s*(\n|$)/', $yaml)) return [];

  $want = [];
  foreach ($wantPaths as $p) $want[implode("\0", $p)] = true;

  $lines = explode("\n", $yaml);
  $found = [];
  $stack = [];           // list of [indent, key], the open blocks above the current line
  $blockScalarIndent = null;

  foreach ($lines as $i => $raw) {
    $line = rtrim($raw, "\r");

    if ($blockScalarIndent !== null) {
      if (trim($line) === '') continue;
      $indent = strlen($line) - strlen(ltrim($line, ' '));
      if ($indent > $blockScalarIndent) continue;  // still the scalar's own text
      $blockScalarIndent = null;                    // falls through — a real line again
    }

    $trimmed = ltrim($line, ' ');
    if ($trimmed === '' || $trimmed[0] === '#') continue;

    // A flow collection character outside a quoted string anywhere is
    // where this scanner stops trusting its own bookkeeping. Quoted
    // segments are stripped first so a JSON value stashed as a quoted
    // string (this plugin writes several) does not itself trigger the
    // refusal.
    $stripped = preg_replace(["/'(?:[^'\\\\]|\\\\.)*'/", '/"(?:[^"\\\\]|\\\\.)*"/'], '', $line);
    if ($stripped === null) return [];
    if (strpos($stripped, '{') !== false || strpos($stripped, '[') !== false) return [];

    if (!preg_match('/^( *)([A-Za-z0-9_.\-]+):(.*)$/', $line, $m)) continue; // not a bare key — a list item, most often; irrelevant to the paths tracked here

    $indent = strlen($m[1]);
    $key    = $m[2];
    $rest   = rtrim($m[3]);

    while ($stack && $stack[count($stack) - 1][0] >= $indent) array_pop($stack);

    $path = array_map(fn($e) => $e[1], $stack);
    $path[] = $key;
    $pathKey = implode("\0", $path);

    if ($rest === '' || preg_match('/^\s*#/', $rest)) {
      $stack[] = [$indent, $key]; // a bare key: opens a nested block
      continue;
    }

    if (preg_match('/^\s*[|>][+\-0-9]*\s*(#.*)?$/', $rest)) {
      $blockScalarIndent = $indent; // everything more indented than this belongs to its text
      continue;
    }

    if ($key === 'mode' && isset($want[$pathKey]) && !isset($found[$pathKey])) {
      $found[$pathKey] = $i;
    }
  }

  return $found;
}

/**
 * One stack: read what `docker compose config` says its update.mode values
 * are (stack scope and every service), work out which — if any — are still
 * the old spelling, then locate and rewrite only those exact lines in the
 * RAW file. Returns null when there is nothing to do, compose could not
 * read the file (never write into a file the parser only partly
 * understood — the same guard every other splicing writer here carries),
 * or the raw scanner could not safely locate what compose says is there.
 *
 * @return array{stack:string,path:string,scopes:string[],before:string,after:string}|null
 */
function staxx_update_convert_file(string $rel, string $file): ?array {
  $meta = staxx_compose_meta($file);
  if (!$meta['ok']) return null;

  $want = [];
  $stackMode = (string)($meta['x']['update.mode'] ?? '');
  if ($stackMode === 'off' || $stackMode === 'notify') $want[] = ['x-unraid', 'update', 'mode'];
  foreach ($meta['services'] as $svc => $sMeta) {
    $svcMode = (string)($sMeta['x']['update.mode'] ?? '');
    if ($svcMode === 'off' || $svcMode === 'notify') $want[] = ['services', $svc, 'x-unraid', 'update', 'mode'];
  }
  if (!$want) return null; // the common case: nothing here uses the old spelling

  $yaml = @file_get_contents($file);
  if ($yaml === false) return null;

  $foundLines = staxx_update_convert_locate($yaml, $want);
  if (!$foundLines) return null;

  $lines  = explode("\n", $yaml);
  $scopes = [];
  foreach ($foundLines as $pathKey => $lineIdx) {
    $line = $lines[$lineIdx];
    // Touch only the value token, keeping the same quoting (or none) and
    // anything after it — a trailing inline comment travels with the line
    // unchanged, since only the word itself was ever wrong.
    if (!preg_match('/^(\s*mode:\s*)([\'"]?)(off|notify)\2(\s*(?:#.*)?)$/', $line, $m)) continue;
    $lines[$lineIdx] = $m[1].$m[2].'manual'.$m[2].$m[4];
    $parts = explode("\0", $pathKey);
    $scopes[] = $parts[0] === 'services' ? 'service:'.$parts[1] : 'stack';
  }
  if (!$scopes) return null;

  return [
    'stack'  => $rel,
    'path'   => $file,
    'scopes' => $scopes,
    'before' => $yaml,
    'after'  => implode("\n", $lines),
  ];
}

/**
 * The pass itself. Runs once — staxx_update_convert_done() above is checked
 * first and nothing further happens if it is already true. Requires a real
 * `docker compose` to ask (the only way this file can know a compose file
 * was actually understood, not just guessed at); without one, nothing is
 * converted and "done" is left false, so a later boot with compose
 * installed gets another chance. Called from staxx.plg's post-install
 * step, after apply_settings.
 *
 * @return array{ran:bool,converted:array}
 */
function staxx_update_mode_convert_run(): array {
  if (staxx_update_convert_done()) return ['ran' => false, 'converted' => []];
  if (!staxx_store_ready() || !is_dir(staxx_stack_root())) return ['ran' => false, 'converted' => []];
  if (staxx_compose_cmd() === '') return ['ran' => false, 'converted' => []];

  $dir = staxx_update_convert_dir();
  if ($dir === '' || (!is_dir($dir.'/before') && !@mkdir($dir.'/before', 0755, true))) {
    return ['ran' => false, 'converted' => []]; // nowhere to keep an undo copy — do not rewrite anything without one
  }

  $converted = [];
  foreach (staxx_scan_stacks()['stacks'] as $found) {
    $rel  = $found['rel'];
    $file = staxx_find_compose_file($found['dir']);
    if ($file === '') continue;

    $change = staxx_update_convert_file($rel, $file);
    if ($change === null) continue;

    if (@file_put_contents($change['path'], $change['after']) === false) continue;

    // Keep the pre-conversion text findable through the stack's own ordinary
    // history too, same as any other write to the file — a courtesy on top
    // of the dedicated undo below, not a substitute for it.
    $recordNote = '';
    staxx_record_capture($rel, basename($change['path']), $recordNote);
    if ($recordNote !== '') {
      error_log('StaXX update-mode conversion: history not kept for '.$rel.': '.$recordNote);
    }

    $n = count(staxx_update_convert_read()['files'] ?? []) + 1;
    $beforeRel = 'before/'.sprintf('%04d', $n).'.yaml';
    if (!staxx_record_atomic_write($dir.'/'.$beforeRel, $change['before'])) {
      // The rewrite already landed but nothing to undo it with was kept —
      // report it plainly rather than silently dropping the undo for this
      // one file.
      error_log('StaXX update-mode conversion: could not keep an undo copy for '.$rel);
    }

    staxx_update_convert_append([
      'stack'       => $rel,
      'path'        => $change['path'],
      'scopes'      => $change['scopes'],
      'before_file' => $beforeRel,
      'before_hash' => hash('sha256', $change['before']),
      'after_hash'  => hash('sha256', $change['after']),
    ]);

    $converted[] = ['stack' => $rel, 'scopes' => $change['scopes']];
  }

  staxx_update_convert_mark_done();

  if ($converted) staxx_update_convert_notify($converted);

  return ['ran' => true, 'converted' => $converted];
}

/* -------------------------------------------------------------- notice --- */

/**
 * One message through Unraid's own notifier, naming every stack the pass
 * touched — never merely that something was. Deliberately NOT gated on the
 * three update-notify switches in Settings: those govern update messages
 * (an update was found, installed, or failed), and this is not one — it is
 * a one-off note about a file having been tidied, unrelated to whether
 * someone wants to hear about updates at all.
 */
function staxx_update_convert_notify_body(array $converted): string {
  $names = array_map(fn($c) => $c['stack'], $converted);
  $n = count($names);
  return ($n === 1 ? 'One stack had' : $n.' stacks had')
    . ' an older spelling of the update setting tidied up to the current one — nothing about how '
    . ($n === 1 ? 'it behaves' : 'they behave') . ' has changed: ' . implode(', ', $names) . '.';
}

function staxx_update_convert_notify(array $converted): void {
  if (!$converted) return;
  $body = staxx_update_convert_notify_body($converted);

  // tests/server/update_mode_convert.php sets this so a suite run on
  // Adrian's real box never puts a real desktop notification up — same
  // reasoning tests/server/updaterun.php gives for calling
  // staxx_update_notify() nowhere in its own two notify-message cases.
  if (getenv('STAXX_UPDATE_CONVERT_NO_NOTIFY')) return;

  staxx_sh(
    '/usr/local/emhttp/webGui/scripts/notify'
      .' -e '.escapeshellarg('StaXX')
      .' -s '.escapeshellarg('StaXX tidied up an older update setting')
      .' -d '.escapeshellarg($body)
      .' -i '.escapeshellarg('normal'),
    10
  );
}

/* ----------------------------------------------------------------- undo --- */

/**
 * Undo the whole pass. Restores only a file that still reads exactly as
 * the pass left it (checked by hash) — a hand edit made since is never
 * clobbered, and is reported back rather than silently skipped. Not wired
 * to anything in the UI; run it the way "Verifying server-side logic
 * without the UI" in CLAUDE.md describes, or via
 * scripts/update-mode-convert-undo.
 *
 * @return array{ok:bool,restored:array,skipped:array}
 */
function staxx_update_mode_convert_undo(string &$note): array {
  $note = '';
  $record = staxx_update_convert_read();
  if (empty($record['done'])) {
    $note = 'The update-options conversion has not run on this store, so there is nothing to undo.';
    return ['ok' => false, 'restored' => [], 'skipped' => []];
  }

  $dir = staxx_update_convert_dir();
  $restored = [];
  $skipped  = [];

  foreach ($record['files'] ?? [] as $entry) {
    $path       = $entry['path'] ?? '';
    $beforeFile = $entry['before_file'] ?? '';
    if ($path === '' || $beforeFile === '') { $skipped[] = $entry + ['reason' => 'this entry is not shaped correctly']; continue; }

    $beforeBytes = @file_get_contents($dir.'/'.$beforeFile);
    if ($beforeBytes === false) { $skipped[] = $entry + ['reason' => 'the original copy is missing']; continue; }

    $current = @file_get_contents($path);
    if ($current === false) { $skipped[] = $entry + ['reason' => 'the file no longer exists']; continue; }

    if (hash('sha256', $current) !== ($entry['after_hash'] ?? '')) {
      $skipped[] = $entry + ['reason' => 'this file has been changed since the conversion, so it was left alone'];
      continue;
    }

    $recordNote = '';
    staxx_record_capture($entry['stack'], basename($path), $recordNote); // keep the converted form findable too

    if (@file_put_contents($path, $beforeBytes) === false) {
      $skipped[] = $entry + ['reason' => 'the original could not be written back'];
      continue;
    }
    $restored[] = $entry;
  }

  return ['ok' => true, 'restored' => $restored, 'skipped' => $skipped];
}
?>
