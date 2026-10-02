<?PHP
/* StaXX — image update detection: the doing side. Settings, the clock,
 * holding and skipping, the queue, roll back and the keep-set the Scan
 * stored images window builds its own removals from.
 * Copyright 2026, StaXX contributors.
 *
 * include/Updates.php is the finding-out side: it asks the registry and
 * remembers the answer. Everything here acts on what it found — deciding
 * when a clock is allowed to run, and pressing the buttons that already
 * exist (staxx_start_job()'s job verbs) rather than inventing new ones.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/staxx/include/Defines.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Updates.php';
// The per-stack record that holds each stack's image history.
require_once '/usr/local/emhttp/plugins/staxx/include/ImageHistory.php';
// staxx_update_record_before_pull() looks up a project link so the release
// notes it fetches (PLAN_82 Part 2) come from the right place. action.php
// already requires this separately for its own use, but the cron passes
// include only this file directly, so it has to be named here too.
require_once '/usr/local/emhttp/plugins/staxx/include/Links.php';
// staxx_update_due() and the queue walk the grid in folder order, which lives
// in Folders.php rather than anywhere Stacks.php or Updates.php already pull
// in. action.php happens to require it first anyway, but scripts/update-check
// includes only this file directly for the cron passes, so it has to be
// named here too or staxx_folder_layout() is simply undefined there.
require_once '/usr/local/emhttp/plugins/staxx/include/Folders.php';
// staxx_update_notify() below is the low-level call; Notify.php decides what is said and when.
require_once '/usr/local/emhttp/plugins/staxx/include/Notify.php';

if (defined('STAXX_UPDATERUN_LOADED')) return;
define('STAXX_UPDATERUN_LOADED', true);

/* The quiet window is the one setting whose value only means anything in the
 * server's own timezone: someone typing 03:00 means three in the morning
 * where the box is. A web request already has that, because Unraid's
 * local_prepend.php sets it from /etc/localtime before any plugin code runs
 * — but the cron passes are plain CLI, where PHP falls back to UTC, and on a
 * box four hours behind that the window would open and close four hours out.
 * Read the same source Unraid reads, so both paths agree. '/usr/share/zoneinfo/'
 * is twenty characters, which is what the offset below skips. */
$staxx_update_tz = @readlink('/etc/localtime');
if (is_string($staxx_update_tz) && strpos($staxx_update_tz, '/usr/share/zoneinfo/') === 0) {
  @date_default_timezone_set(substr($staxx_update_tz, 20));
}
unset($staxx_update_tz);

/* --------------------------------------------------------------- settings --
 *
 * The global defaults, typed and range-checked. staxx_settings_keys() (Part F,
 * a different phase) is the browser-facing allowlist; this is what every
 * server-side decision in this file actually reads, so an unrecognised or
 * out-of-range value falls back to the same default the settings panel
 * shows rather than being trusted outright — a hand-edited cfg is not a
 * validated one.
 */

/**
 * @return array{mode:string, delay:int, window:bool, wstart:string, wend:string,
 *               notifyFound:bool, notifyInstalled:bool, notifyFailed:bool,
 *               notifyPinned:bool, retain:int, cleanup:string, keepImages:bool}
 *
 * mode is always returned as 'manual' or 'auto' — the config key may still
 * hold the older 'off'/'notify' spelling, normalised here rather than at
 * every reader. The four notifyXxx booleans are the server-wide switches for
 * being told about a found update, an installed one, a failed one, and
 * (PLAN_205) a pinned service's weekly reminder — see staxx_update_notify_map()
 * (Defines.php) for how a config that still only has the retired UPDATE_NOTIFY
 * choice is read.
 */
function staxx_update_settings(): array {
  $cfg = staxx_cfg();
  $time = '/^([01][0-9]|2[0-3]):[0-5][0-9]$/';

  // 'off' and 'notify' are the older three-way spelling — PLAN_150 found the
  // one caller that reads mode only ever asks "=== 'auto'", so the two of
  // them never behaved differently and both now read as 'manual'.
  $mode = (string)($cfg['UPDATE_MODE'] ?? 'notify');
  if (!in_array($mode, ['manual', 'off', 'notify', 'auto'], true)) $mode = 'notify';
  if ($mode === 'off' || $mode === 'notify') $mode = 'manual';

  $delay = $cfg['UPDATE_DELAY_HOURS'] ?? 24;
  $delay = (is_numeric($delay) && (int)$delay == $delay) ? (int)$delay : 24;
  if ($delay < 0 || $delay > 720) $delay = 24;

  $window = (string)($cfg['UPDATE_WINDOW'] ?? 'true') === 'true';

  $wstart = (string)($cfg['UPDATE_WINDOW_START'] ?? '03:00');
  if (!preg_match($time, $wstart)) $wstart = '03:00';

  $wend = (string)($cfg['UPDATE_WINDOW_END'] ?? '05:00');
  if (!preg_match($time, $wend)) $wend = '05:00';

  $notify = staxx_update_notify_map($cfg);

  $retain = $cfg['UPDATE_RETAIN'] ?? 2;
  $retain = (is_numeric($retain) && (int)$retain == $retain) ? (int)$retain : 2;
  if ($retain < 0 || $retain > 5) $retain = 2;

  // PLAN_181 Part C — default 'yes' keeps today's behaviour for every config
  // that predates this setting.
  $keepImages = (string)($cfg['UPDATE_KEEP_IMAGES'] ?? 'yes') !== 'no';

  return ['mode' => $mode, 'delay' => $delay, 'window' => $window, 'wstart' => $wstart,
          'wend' => $wend, 'notifyFound' => $notify['found'], 'notifyInstalled' => $notify['installed'],
          'notifyFailed' => $notify['failed'], 'notifyPinned' => $notify['pinned'], 'retain' => $retain,
          'keepImages' => $keepImages];
}

/**
 * Turns a raw x-unraid value into a real bool, or null when it is not one.
 * staxx_yaml_flatten() always hands back a string (it reads YAML a line at a
 * time and never types a scalar), but `docker compose config`'s own output —
 * what staxx_compose_meta() actually parses when compose is installed — is a
 * second route into the same array, so a genuine PHP bool is tolerated too
 * rather than assumed away.
 */
function staxx_update_bool($raw): ?bool {
  if (is_bool($raw)) return $raw;
  if ($raw === 'true') return true;
  if ($raw === 'false') return false;
  return null;
}

/**
 * The two independent axes that decide one service's behaviour — whether it
 * is applied for you (mode) and whether it is named in update messages
 * (notify) — resolved service first, then the stack itself, then the global
 * default. A scope wins mode and delay outright when it declares either one
 * — a stack that sets only 'update.delay' still inherits the global mode, it
 * does not fall through to the global delay too. An unrecognised mode, or a
 * non-numeric or out-of-range delay, is ignored at that scope exactly as it
 * would be at the global one, so a typo in a compose file cannot silently
 * turn automatic updates on or off for a service.
 *
 * mode is always returned as 'manual' or 'auto'. 'off' and 'notify' are the
 * older three-way spelling, read from a hand-written file for good, but they
 * never behaved differently from 'manual' — the only caller that reads mode
 * asks solely whether it is 'auto' — so both normalise to 'manual' here and
 * no caller downstream ever has to know the old spelling existed.
 *
 * notify (PLAN_154; a fourth event, 'pinned', added by PLAN_205) is NOT part
 * of that mode/delay handoff — a scope setting only 'update.notify' does not
 * thereby also decide mode and delay, and a scope setting mode/delay does not
 * thereby also decide notify. It resolves separately, per event (found/
 * installed/failed/pinned), service then stack: the
 * first of those two scopes that has an opinion about a given event wins it,
 * and a scope with no opinion about that event — because it set neither the
 * plain boolean nor that event's own key — leaves it null rather than
 * falling through to the server-wide switch itself. That last step is left
 * to the caller (see staxx_update_stack_wants_notify()) because "container
 * says nothing" and "container says follow the server" have to stay
 * distinguishable at this layer, even though today they resolve the same
 * way. A plain boolean `notify` (the older spelling) answers all three
 * events at once; the older reader ignores anything it does not recognise,
 * so a scope carrying an unrecognised shape at either key is treated as
 * having no opinion, same as an absent key.
 *
 * @return array{mode:string, delay:int, notifyFound:?bool, notifyInstalled:?bool, notifyFailed:?bool, notifyPinned:?bool, from:string}
 */
function staxx_update_policy(string $stack, string $service): array {
  $global = staxx_update_settings();

  if (!staxx_valid_path($stack)) return staxx_update_policy_fallback($global);

  $file = staxx_stack_compose_map()[$stack] ?? '';
  if ($file === '') return staxx_update_policy_fallback($global);

  $meta = staxx_compose_meta($file);
  if (!$meta['ok']) return staxx_update_policy_fallback($global);

  return staxx_update_policy_from_meta($meta, $service, $global);
}

/**
 * The global-only answer, when there is nothing more specific to read. Unlike
 * staxx_update_policy_from_meta()'s own notifyXxx keys, these are never null
 * — the server-wide switches are the bottom of the chain, so there is
 * nothing left for them to defer to.
 */
function staxx_update_policy_fallback(array $global): array {
  return ['mode' => $global['mode'], 'delay' => $global['delay'],
          'notifyFound' => $global['notifyFound'], 'notifyInstalled' => $global['notifyInstalled'],
          'notifyFailed' => $global['notifyFailed'], 'notifyPinned' => $global['notifyPinned'],
          'from' => 'global'];
}

/**
 * One scope's own opinion of one notify event — true, false, or null for "no
 * opinion here". A plain boolean at 'update.notify' (the older spelling)
 * answers every event; otherwise this event's own 'update.notify.<event>'
 * key is read on its own, so a container can set 'failed' and say nothing
 * about the other two.
 */
function staxx_update_notify_scope_value(array $x, string $event): ?bool {
  $whole = staxx_update_bool($x['update.notify'] ?? null);
  if ($whole !== null) return $whole;
  return staxx_update_bool($x['update.notify.'.$event] ?? null);
}

/**
 * The service → stack → global walk itself, split out of
 * staxx_update_policy() so a caller that already holds a stack's
 * staxx_compose_meta() result — the row table renders one per stack already,
 * see staxx_stack_children() — can resolve every one of its services without
 * the stack lookup staxx_update_policy() does first.
 *
 * @param array $meta staxx_compose_meta()'s return for one stack
 * @param array $global staxx_update_settings()'s return
 * @return array{mode:string, delay:int, notifyFound:?bool, notifyInstalled:?bool, notifyFailed:?bool, notifyPinned:?bool, from:string}
 */
function staxx_update_policy_from_meta(array $meta, string $service, array $global): array {
  $modes = ['manual', 'off', 'notify', 'auto'];
  $scopes = [
    'service' => (array)($meta['services'][$service]['x'] ?? []),
    'stack'   => (array)($meta['x'] ?? []),
  ];

  // mode and delay: the first scope that sets either one wins both, exactly
  // as before PLAN_154 — notify plays no part in this any more.
  $mode = null; $delay = null; $from = 'global';
  foreach ($scopes as $scopeName => $x) {
    $rawMode  = (string)($x['update.mode'] ?? '');
    $rawDelay = $x['update.delay'] ?? null;

    $scopeMode = in_array($rawMode, $modes, true) ? $rawMode : null;
    if ($scopeMode === 'off' || $scopeMode === 'notify') $scopeMode = 'manual';
    $scopeDelay = (is_numeric($rawDelay) && (int)$rawDelay == $rawDelay
              && (int)$rawDelay >= 0 && (int)$rawDelay <= 720) ? (int)$rawDelay : null;

    if ($scopeMode !== null || $scopeDelay !== null) {
      $mode = $scopeMode ?? $global['mode'];
      $delay = $scopeDelay ?? $global['delay'];
      $from = $scopeName;
      break;
    }
  }
  if ($mode === null) { $mode = $global['mode']; $delay = $global['delay']; }

  // notify: each event resolved on its own, service first, then stack — see
  // staxx_update_notify_scope_value() and the docblock above for why a scope
  // with nothing to say leaves an event null rather than borrowing the
  // server's switch itself.
  $events = ['found' => null, 'installed' => null, 'failed' => null, 'pinned' => null];
  foreach ($scopes as $x) {
    foreach ($events as $event => $resolved) {
      if ($resolved !== null) continue;
      $events[$event] = staxx_update_notify_scope_value($x, $event);
    }
  }

  return [
    'mode' => $mode, 'delay' => $delay,
    'notifyFound' => $events['found'], 'notifyInstalled' => $events['installed'],
    'notifyFailed' => $events['failed'], 'notifyPinned' => $events['pinned'], 'from' => $from,
  ];
}

/* ------------------------------------------------------------- the window -- */

/** "HH:MM" to minutes since midnight, for comparing against another clock time. */
function staxx_update_window_minutes(string $hhmm): int {
  $parts = array_map('intval', explode(':', $hhmm));
  return ($parts[0] ?? 0) * 60 + ($parts[1] ?? 0);
}

/**
 * Is $now inside the configured quiet window? Always true when the window is
 * switched off. Handles a window that crosses midnight (23:00–05:00) by
 * treating it as "everything except the gap between end and start", rather
 * than assuming start is always the smaller number.
 */
function staxx_update_window_ok(int $now): bool {
  $s = staxx_update_settings();
  if (!$s['window']) return true;

  $start = staxx_update_window_minutes($s['wstart']);
  $end   = staxx_update_window_minutes($s['wend']);
  if ($start === $end) return true; // a zero-width window restricts nothing

  $cur = (int)date('H', $now) * 60 + (int)date('i', $now);
  if ($start < $end) return $cur >= $start && $cur < $end;
  return $cur >= $start || $cur < $end; // wraps midnight
}

/**
 * The timestamp the window next opens — $now itself when it is already open,
 * so a caller can always show a time rather than branching separately on
 * staxx_update_window_ok(). 0 when the window is off.
 */
function staxx_update_window_next(int $now): int {
  $s = staxx_update_settings();
  if (!$s['window']) return 0;

  $start = staxx_update_window_minutes($s['wstart']);
  $end   = staxx_update_window_minutes($s['wend']);
  if ($start === $end) return 0;

  if (staxx_update_window_ok($now)) return $now;

  $midnight   = mktime(0, 0, 0, (int)date('n', $now), (int)date('j', $now), (int)date('Y', $now));
  $todayStart = $midnight + $start * 60;

  return $todayStart > $now ? $todayStart : $todayStart + 86400;
}

/* ------------------------------------------------------- being edited -- */

/**
 * Touch the marker that says "an editor for this stack has unsaved changes
 * right now" — the browser calls this while the editor is open. Fresh means
 * within 15 minutes, so a tab that was simply closed cannot freeze a stack's
 * updates for ever.
 */
function staxx_update_editing_mark(string $stack): bool {
  if (!staxx_valid_path($stack)) return false;

  $dir = STAXX_UPDATE_DIR.'/editing';
  if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return false;

  return @touch($dir.'/'.md5($stack)) !== false;
}

function staxx_update_editing(string $stack): bool {
  $path = STAXX_UPDATE_DIR.'/editing/'.md5($stack);
  if (!is_file($path)) return false;
  return (time() - (int)@filemtime($path)) < 900;
}

/* --------------------------------------------------------------- the clock -- */

/**
 * What one service's clock is doing right now. 'due' is the timestamp the
 * update would install itself at, computed fresh from 'seen' every call so a
 * page refresh can never restart it; 0 only when there genuinely is no clock
 * running (mode is off/notify, or there is nothing new to install).
 *
 * 'why' is populated whenever an automatic install will NOT actually happen
 * even though the clock is real — paused, held, unreviewed, being edited,
 * stopped, or waiting on the quiet window — so the countdown itself keeps
 * ticking honestly while the row still explains why pressing nothing will
 * not be enough.
 *
 * @return array{due:int, hold:bool, why:string}
 */
function staxx_update_clock(string $stack, string $service, string $image): array {
  $none = ['due' => 0, 'hold' => false, 'why' => ''];

  $policy = staxx_update_policy($stack, $service);
  if ($policy['mode'] !== 'auto') return $none;

  $state = staxx_update_state();
  $entry = (array)($state['images'][$image] ?? []);

  $remote = (string)($entry['remote'] ?? '');
  $seen   = (int)($entry['seen'] ?? 0);
  $skip   = (string)($entry['skip'] ?? '');
  if ($remote === '' || $seen === 0 || ($skip !== '' && $skip === $remote)) return $none;

  $hold = !empty($entry['hold']);
  $due  = $seen + $policy['delay'] * 3600;
  $now  = time();

  $why = '';
  if (!empty($state['paused'])) {
    $why = 'Automatic updates are paused for every stack. Turn the pause switch off to let them run again.';
  } elseif ($hold) {
    $why = 'This update was cancelled here. Press it again to let the clock run.';
  } elseif (staxx_review_locked($stack)) {
    $why = 'This stack was imported and has not been reviewed yet, so it will not update itself.';
  } elseif (staxx_update_editing($stack)) {
    $why = 'This stack is being edited right now, so it will not update itself until you are done.';
  } else {
    // Only "is this stack running" is needed here, so this is
    // staxx_list_stacks()'s own 'running' derivation, not the full per-stack
    // walk (compose metadata, review lock, handover, foreign holders) that
    // function does for every stack — costly when called once per pending
    // service. A locked stack reports no state, same as staxx_list_stacks()
    // itself, so an unreviewed import never reads as running here either.
    $running = false;
    foreach (staxx_scan_stacks()['stacks'] as $s) {
      if ($s['rel'] !== $stack) continue;
      if (staxx_review_file($s['dir']) === '') {
        $st = staxx_state_for($s['file'], $s['leaf']);
        $running = stripos($st['status'] ?? '', 'running') !== false;
      }
      break;
    }
    if (!$running) {
      $why = 'This stack is stopped, so it will not update itself.';
    } elseif ($due <= $now && !staxx_update_window_ok($now)) {
      $why = 'Waiting for the quiet window, opens at '.date('H:i', staxx_update_window_next($now)).'.';
    }
  }

  return ['due' => $due, 'hold' => $hold, 'why' => $why];
}

/**
 * Every service whose clock has run out AND is actually permitted to install
 * itself right now — every refusal staxx_update_clock() can report is a
 * refusal here too. Reads every stack's compose metadata, so it is not for a
 * fast path; the apply pass is the only caller that matters.
 *
 * @return array<int, array{stack:string, service:string, image:string, due:int}>
 */
function staxx_update_due(): array {
  $out = [];
  $now = time();

  foreach (staxx_folder_layout(staxx_stack_states()) as $row) {
    if ($row['type'] !== 'stack') continue;
    $stack = $row['stack'];
    if ($stack['file'] === '') continue;

    $meta = staxx_compose_meta($stack['file']);
    if (!$meta['ok']) continue;

    foreach ($meta['services'] as $svc => $svcMeta) {
      $image = trim((string)($svcMeta['image'] ?? ''));
      if ($image === '') continue;

      // A pinned image (repo:tag@sha256:...) can never move: pulling it
      // fetches exactly the build it already names. Skipped here, on the
      // acting list only — the checker still honestly reports "there is an
      // update" for a pinned service, and that reporting path is untouched.
      // Without this, an automatic update on a pinned service would recreate
      // the same container every pass for ever, concluding nothing had
      // changed and trying again the very next night.
      if (strpos($image, '@') !== false) continue;

      $clock = staxx_update_clock($stack['name'], $svc, $image);
      if ($clock['due'] > 0 && $clock['due'] <= $now && $clock['why'] === '') {
        $out[] = ['stack' => $stack['name'], 'service' => $svc, 'image' => $image, 'due' => $clock['due']];
      }
    }
  }

  return $out;
}

/* --------------------------------------------------------- pinned reminder -- */

// How often the pinned-service reminder may actually SEND a message (PLAN_205,
// decision P2 — fixed weekly, not a choice in Settings).
define('STAXX_PINNED_NOTICE_INTERVAL', 7 * 86400);

// How often the pass may WALK every stack to keep 'pinnedSince' current. Kept
// far shorter than the notice interval above — a pin date is only ever set or
// dropped when a walk actually runs, so tying it to the weekly send left it up
// to six days late. A day is frequent enough that the date shown is never
// meaningfully stale, and cheap enough that it costs nothing between the
// weekly sends the box actually notices.
define('STAXX_PINNED_WALK_INTERVAL', 86400);

/**
 * The weekly "these are still pinned" message (PLAN_205). Hooked into the
 * 15-minute apply pass rather than UPDATE_CHECK's own cadence, so the
 * reminder does not depend on checking being switched on at all — a pin is
 * never checked either way, so there is nothing for UPDATE_CHECK to gate here.
 *
 * Two clocks, kept deliberately apart:
 *  - 'pinnedWalkAt' gates the slow walk itself (STAXX_PINNED_WALK_INTERVAL,
 *    a day) and is all that keeps 'pinnedSince' current — between walks this
 *    function costs the one state read below and nothing else.
 *  - 'pinnedNoticeAt' gates SENDING (STAXX_PINNED_NOTICE_INTERVAL, a week),
 *    exactly as before; it only moves when a send decision is actually made.
 *
 * When the week is due, every pinned, opted-in service goes to
 * staxx_notify_pinned_due() as one list of 'pinned' events; the summary
 * (Notify.php) carries it, so this pass sends no message of its own.
 * Nothing is handed over, but 'pinnedNoticeAt' still moves on, when there is
 * nothing to report on a week that is due; an empty week must cost one walk,
 * not repeat the walk every 15 minutes until something changes.
 *
 * The very first walk this ever runs — 'pinnedNoticeAt' still unset, a fresh
 * install or the first pass after this feature shipped — records every
 * 'pinnedSince' it finds and starts both clocks from now, but sends nothing:
 * a server that has been pinned for years must not announce it the moment
 * this code first runs, so the first real reminder arrives a full week after
 * install, the same as for a pin made afterwards.
 *
 * 'pinnedSince' — when this pass first saw a given image's exact digest pin —
 * lives per image under the update state, set the first time it is seen and
 * dropped the moment that image is no longer pinned in any stack (the pin was
 * released or the image was changed), so a released pin does not silently
 * reappear with its old date if the same digest is ever pinned again later.
 *
 * The walk itself (which stacks are pinned right now) can take a while — it
 * parses every compose file. Whatever it finds is folded into the freshest
 * 'images' on disk immediately before saving, under the check pass's own
 * lock (staxx_update_lock()/staxx_update_unlock(), Updates.php) and re-read
 * straight from the state file rather than trusting the snapshot taken at
 * the top — otherwise a check pass that writes 'images' while this walk is
 * still running would have its own changes overwritten by this function's
 * stale copy, the same failure staxx_update_refresh_after_run() guards
 * against for the same reason. Only 'pinnedSince' is folded in; every other
 * key in the freshest 'images' is left exactly as that fresher read found it.
 *
 * If the lock cannot be taken at all — a check pass is mid-write right now —
 * this pass saves NOTHING and sends nothing: neither clock moves, today's
 * walk is simply discarded, and the next 15-minute apply pass tries the
 * whole thing again. Saving this walk's own stale snapshot instead would
 * risk exactly the lost-update failure the lock exists to prevent.
 */
function staxx_update_pinned_reminder_pass(): void {
  $now    = time();
  $state  = staxx_update_state(); // the one read this costs between walks
  $walkAt = (int)($state['pinnedWalkAt'] ?? 0);
  if ($walkAt !== 0 && ($now - $walkAt) < STAXX_PINNED_WALK_INTERVAL) return;

  $noticeAt = (int)($state['pinnedNoticeAt'] ?? 0);
  $firstRun = ($noticeAt === 0);
  $sendDue  = !$firstRun && ($now - $noticeAt) >= STAXX_PINNED_NOTICE_INTERVAL;

  $global = staxx_update_settings();
  $images = (array)($state['images'] ?? []);
  $stillPinned = [];
  $pinnedEvents = [];

  foreach (staxx_folder_layout(staxx_stack_states()) as $row) {
    if ($row['type'] !== 'stack') continue;
    $stack = $row['stack'];
    if ($stack['file'] === '') continue;

    $meta = staxx_compose_meta($stack['file']);
    if (!$meta['ok']) continue;

    foreach ($meta['services'] as $svc => $svcMeta) {
      $image = trim((string)($svcMeta['image'] ?? ''));
      $at = strpos($image, '@');
      if ($at === false || !preg_match('/^sha256:[0-9a-f]{64}$/', substr($image, $at + 1))) continue;

      $resolved = staxx_update_policy_from_meta($meta, $svc, $global)['notifyPinned'];
      if (!($resolved ?? $global['notifyPinned'])) continue;

      $stillPinned[$image] = true;
      $entry = (array)($images[$image] ?? []);
      $since = (int)($entry['pinnedSince'] ?? 0);
      if ($since === 0) { $since = $now; $entry['pinnedSince'] = $since; }
      $images[$image] = $entry;

      $pinnedEvents[] = ['kind' => 'pinned', 'stack' => $stack['name'], 'service' => (string)$svc,
                         'image' => $image, 'at' => $since];
    }
  }

  // An image no longer pinned anywhere loses its start date — a released
  // pin re-applied later starts the clock again rather than reusing the old
  // date, since it is a new decision to pin, not a continuation of the old one.
  foreach ($images as $img => $entry) {
    if (isset($entry['pinnedSince']) && !isset($stillPinned[$img])) {
      unset($entry['pinnedSince']);
      $images[$img] = $entry;
    }
  }

  // Fold ONLY the pinnedSince changes just found into the freshest 'images'
  // on disk, under the check pass's own lock — see this function's docblock
  // for why the snapshot taken at the top of this pass cannot be trusted by
  // the time the slow walk above has finished. A check pass already holds
  // this lock while it is writing 'images' itself, so failing to take it
  // means today's walk is discarded outright — see the docblock — rather
  // than risking the very lost-update failure the lock exists to prevent.
  $lockError = '';
  if (!staxx_update_lock($lockError)) return;

  // Re-read straight from the state file (staxx_update_state() would just
  // hand back this process's own stale cache) and push that fresh copy into
  // the cache slot so the staxx_update_state_save() call below merges over
  // it, not over the stale one.
  $file  = staxx_update_state_file();
  $raw   = $file === '' ? false : @file_get_contents($file);
  $data  = $raw === false ? null : json_decode($raw, true);
  $fresh = is_array($data) ? array_merge(staxx_update_state_defaults(), $data) : staxx_update_state_defaults();
  staxx_update_state_cache($fresh);

  $freshImages = (array)($fresh['images'] ?? []);
  foreach ($images as $img => $entry) {
    $freshEntry = (array)($freshImages[$img] ?? []);
    if (isset($entry['pinnedSince'])) $freshEntry['pinnedSince'] = $entry['pinnedSince'];
    else unset($freshEntry['pinnedSince']);
    $freshImages[$img] = $freshEntry;
  }
  $images = $freshImages;

  // The weekly reminder rides in the summary (Notify.php) rather than being a
  // message of its own; the clocks below are unchanged.
  if ($sendDue && $pinnedEvents) staxx_notify_pinned_due($pinnedEvents);

  $toSave = ['images' => $images, 'pinnedWalkAt' => $now];
  if ($sendDue || $firstRun) $toSave['pinnedNoticeAt'] = $now;
  // Unlocked unconditionally, whether or not the save itself succeeded —
  // the lock's only job is to stop a concurrent writer being overwritten,
  // and a failed save here leaves nothing else holding it.
  staxx_update_state_save($toSave);
  staxx_update_unlock();
}

/* ------------------------------------------------------------ pause / hold -- */

/** The one global switch, state rather than a setting — no settings save needed. */
function staxx_update_pause(bool $on): bool {
  return staxx_update_state_save(['paused' => $on]);
}

/**
 * Cancel (or un-cancel) the clock for one image. Refuses an image with no
 * entry at all, so a typo in a request cannot invent a fresh key in a file
 * nothing else writes to freely.
 */
function staxx_update_hold(string $image, bool $on, string &$error): bool {
  $error = '';
  $state  = staxx_update_state();
  $images = (array)$state['images'];

  if (!array_key_exists($image, $images)) {
    $error = 'This image has not been checked yet, so there is nothing to hold.';
    return false;
  }

  $entry = $images[$image];
  if ($on) $entry['hold'] = true; else unset($entry['hold']);
  $images[$image] = $entry;

  return staxx_update_state_save(['images' => $images]);
}

/* ------------------------------------------------------------------- history -- */

/**
 * Remember one service's fingerprint before an update runs, alongside the
 * version name and where it came from (PLAN_82 Part 1) — both commonly
 * absent, which is a normal answer, never a placeholder. Written straight
 * into the stack's own record: retention and the "never the same digest
 * twice running" rule are staxx_image_history_push()'s job, so they are
 * enforced in exactly one place.
 */
function staxx_update_history_push(string $stack, string $service, string $digest, array $meta = []): void {
  if ($digest === '') return;
  staxx_image_history_push($stack, $service, $digest, $meta);
}

/**
 * Record every service's fingerprint before a pull runs — whole stack when
 * $service is '', one named service otherwise (a name not present in this
 * stack's compose file simply matches nothing). Shared by the queue tick
 * and a hand-pressed Update (PLAN_82 Part 2), so both leave something to
 * roll back to even if the job itself never finishes. Best-effort
 * throughout: nothing calling this has anywhere to show a failure, so an
 * unreadable stack or a service with no local digest yet simply records
 * nothing, exactly as it always has.
 *
 * $lookups false means record the local facts only — digest, version, source
 * and commit — and make no outbound request whatsoever. That exists for the
 * one-off baseline seeding below: the notes budget further down is 12 seconds
 * *per call*, so eighty-odd stacks would be up to seventeen minutes of network
 * time and hundreds of requests against a ceiling of sixty an hour. A baseline
 * only answers "which build am I running"; notes and commit lists arrive with
 * the next real update, which is soon enough.
 */
function staxx_update_record_before_pull(string $stack, string $service = '', bool $lookups = true): void {
  $file = staxx_stack_compose_map()[$stack] ?? '';
  if ($file === '') return;

  $meta = staxx_compose_meta($file);
  if (!$meta['ok']) return;

  $cachedImages = (array)(staxx_update_state()['images'] ?? []);

  // A budget for the release-notes fetching below, and the reason it exists:
  // the Update button calls this inside its own request, and every other
  // network read in this plugin is deliberately kept out of a request (the
  // update check detaches itself into a job for exactly this reason). One
  // unreachable registry per service, at the fetch's own ceiling, would
  // otherwise leave the button hanging for a minute on a many-service stack.
  // Once the budget is gone the remaining services are still recorded, just
  // without notes — the next update picks them up.
  $notesUntil = time() + 12;

  foreach ($meta['services'] as $svc => $svcMeta) {
    if ($service !== '' && $svc !== $service) continue;

    $image = trim((string)($svcMeta['image'] ?? ''));
    if ($image === '') continue;
    $local = staxx_image_local($image);
    if (empty($local['digest'])) continue;

    // The version and source about to be superseded — read from the labels
    // cache staxx_image_remote()/staxx_update_labels_meta() already filled
    // in, the same cache Links.php reads from. Never a fresh docker call,
    // and never a placeholder when a label is simply absent, which is the
    // normal case for plenty of images.
    $cached = (array)($cachedImages[$image] ?? []);

    // The checker's own "did this actually change" test. 'version' is
    // overwritten with the *incoming* build on every check pass, and the
    // outgoing one is kept separately as 'was' — so an entry describing the
    // build being superseded must be stamped from 'was' when a change is
    // under way, not from 'version', or it carries the name of its own
    // replacement. Empty stays empty either way: absent is a normal answer
    // for plenty of images, and nothing is ever substituted for it. Entries
    // already on disk are not revisited — history is not retrospectively
    // corrected.
    $changed = ($cached['local'] ?? '') !== '' && ($cached['remote'] ?? '') !== ''
             && $cached['local'] !== $cached['remote'];
    $name = $changed ? (string)($cached['was'] ?? '') : (string)($cached['version'] ?? '');

    $svcMetaOut = [];
    if ($name !== '') $svcMetaOut['version'] = $name;
    if (($cached['source'] ?? '') !== '') $svcMetaOut['source'] = (string)$cached['source'];

    // The commit this outgoing build was made from, when the image labels say
    // so. Recorded unconditionally — it costs no network call and no guard
    // beyond its type — because it is the only thing the NEXT entry recorded
    // for this service can compare against to say what went into a build
    // whose tag never changes.
    $revision = is_string($local['revision'] ?? null) ? (string)$local['revision'] : '';
    if ($revision !== '') $svcMetaOut['revision'] = $revision;

    // Release notes: fetched at most once per version, right here, never
    // again on view — so there is no cache to expire, only this one guard
    // against paying for a second network call for the same entry. Needs a
    // project link and a digest that is not already on record with notes;
    // also skipped when the newest entry already carries this digest, since
    // the push below would refuse it as a duplicate anyway.
    //
    // Either a version name or a commit gets in here: an image whose version
    // label is absent still has a history worth reading by commit, and that
    // is exactly the rolling-tag case. The by-name lookup's own conditions
    // are all still tested where they were, so widening the door changes
    // nothing about when notes are fetched — only that the project link and
    // the recorded history, both local reads, are looked up in a few more
    // cases.
    // $lookups gates the whole block, not each fetch inside it: the project
    // link is resolved in here too, and a baseline pass must not even ask
    // that much.
    if ($lookups && ($name !== '' || $revision !== '') && time() < $notesUntil) {
      $links   = staxx_project_links($image, $meta['x'] ?? [], $meta['services'][$svc]['x'] ?? []);
      $project = (string)($links['project'] ?? '');
      if ($project !== '') {
        $history = staxx_image_history($stack, $svc);
        $skip = (($history[0]['digest'] ?? '') === $local['digest']);
        foreach ($history as $entry) {
          if ($skip) break;
          if (($entry['digest'] ?? '') === $local['digest'] && ($entry['notes'] ?? '') !== '') $skip = true;
        }
        if (!$skip && $name !== '') {
          $notes = staxx_release_notes_fetch($project, $name);
          if (($notes['notes'] ?? '') !== '') {
            $svcMetaOut['notes']    = (string)$notes['notes'];
            $svcMetaOut['notesUrl'] = (string)($notes['url'] ?? '');
            $svcMetaOut['notesCut'] = (bool)($notes['cut'] ?? false);
          }
        }

        // The version name found nothing, but the build says which commit it
        // came from — so ask the project about the commit instead. Same
        // budget as above, deliberately: it is one ceiling on how long a
        // record step may spend on the network, not one per question.
        if (!$skip && !isset($svcMetaOut['notes']) && $revision !== '' && time() < $notesUntil) {
          // A real release sitting on this very commit always beats a raw
          // list of commits, so that is asked first. The entry's stored
          // 'version' is deliberately left as it was: the notes link already
          // names the release, and rewriting the version here would make the
          // history disagree with the update chip for no gain.
          $tag = staxx_release_tag_at_commit($project, $revision);
          if ($tag !== '') {
            $notes = staxx_release_notes_fetch($project, $tag);
            if (($notes['notes'] ?? '') !== '') {
              $svcMetaOut['notes']    = (string)$notes['notes'];
              $svcMetaOut['notesUrl'] = (string)($notes['url'] ?? '');
              $svcMetaOut['notesCut'] = (bool)($notes['cut'] ?? false);
            }
          }

          // No release to name: what went into this build is then the commits
          // between the previously recorded build and this one. That needs
          // the previous entry's own commit, which is why it is stored — an
          // entry recorded before this existed has none, and then there is
          // simply nothing to say, which is the honest answer.
          if (!isset($svcMetaOut['notes']) && time() < $notesUntil) {
            $previous = is_string($history[0]['revision'] ?? null) ? (string)$history[0]['revision'] : '';
            if ($previous !== '' && $previous !== $revision) {
              $log = staxx_changelog_fetch($project, $previous, $revision);
              if (!empty($log['changes'])) {
                $svcMetaOut['changes']    = (array)$log['changes'];
                $svcMetaOut['changesUrl'] = (string)($log['url'] ?? '');
                $svcMetaOut['changesCut'] = (bool)($log['cut'] ?? false);
              }
            }
          }
        }
      }
    }

    staxx_update_history_push($stack, $svc, $local['digest'], $svcMetaOut);
  }
}

/**
 * Give every stack a starting point in its own history, once. Until this ran,
 * a stack only got an entry the first time one of its images was updated, so
 * the Versions tab was empty on almost everything and filled up over months.
 * Everything recorded here is already on the machine — the images' own labels
 * and digests — so this pulls nothing and touches no container.
 *
 * Deliberately once per install, not once per pass: it parses every compose
 * file and inspects every service's image, which is well over a hundred
 * shell-outs on a busy box. The timestamp under 'seeded' is what stops it
 * happening again, and staxx_image_history_push() refuses a digest that is
 * already newest anyway, so a repeat would be harmless — just wasteful.
 *
 * An unreadable stack root gives an EMPTY list rather than an error, so it is
 * tested for first: seeding against nothing and then filing the marker would
 * quietly cost every stack its baseline for good.
 */
function staxx_update_seed_history(): array {
  $out = ['stacks' => 0, 'ok' => true, 'error' => ''];

  if ((int)(staxx_update_state()['seeded'] ?? 0) > 0) return $out;

  if (!staxx_stacks_visible()) {
    $out['ok']    = false;
    $out['error'] = 'The stack folder could not be read, so nothing was recorded yet. '
                  . 'This runs again on the next check.';
    return $out;
  }

  foreach (array_keys(staxx_stack_compose_map()) as $rel) {
    staxx_update_record_before_pull($rel, '', false);
    $out['stacks']++;
  }

  staxx_update_state_save(['seeded' => time()]);
  return $out;
}

/**
 * The rollback's reader: every digest recorded for this service, newest
 * first, from the stack's own record.
 */
function staxx_update_history(string $stack, string $service): array {
  return staxx_image_history_digests($stack, $service);
}

/* ------------------------------------------------------------------- roll back -- */

/**
 * The name Docker keeps an image under locally, with any tag or existing
 * pin removed — e.g. "lscr.io/linuxserver/plex:latest" or an already-pinned
 * "lscr.io/linuxserver/plex@sha256:…" both become "lscr.io/linuxserver/plex".
 *
 * Deliberately NOT staxx_hub_repo_path(): that turns a registry mirror
 * address into the plain Docker Hub path it mirrors (e.g.
 * "lscr.io/linuxserver/plex" -> "linuxserver/plex"), which is the name the
 * image was fetched FROM, not the name Docker stored it under — `docker
 * image inspect linuxserver/plex@<digest>` finds nothing even though the
 * image is present as `lscr.io/linuxserver/plex@<digest>`. Using the
 * reference exactly as the compose file wrote it sidesteps that mismatch.
 * A bare Docker Hub name such as "redis" needs no rewriting either way.
 *
 * @param string $ref an image reference as it appears in a compose file,
 *   e.g. "repo:tag" or an already-pinned "repo@digest"
 */
function staxx_update_local_repo(string $ref): string {
  $ref = trim($ref);
  $at = strpos($ref, '@');
  if ($at !== false) $ref = substr($ref, 0, $at);
  return preg_replace('/:[^\/]*$/', '', $ref);
}

/**
 * Point one or more services' images back at a version each has run
 * before, and bring them all up in ONE recreate job. $targets is
 * service => digest, any version that service itself recorded — which the
 * Versions tab needs, since it lists them all rather than only the newest.
 * A single rollback is simply a one-entry map; there is no "roll back to
 * whatever came before" shorthand any more; the Versions tab is the only
 * real caller and it always knows the exact digest it is asking for, so
 * that guesswork is not worth the second code path.
 *
 * The compose file is the authority: the browser-side editor has already
 * rewritten each chosen service's image line to carry its digest, and this
 * function's job is to check that edit and save it through the normal path,
 * rather than to write YAML itself — the only round-trip-safe compose editor
 * is the browser's, and PHP must not grow a second one. $yaml is therefore
 * required in practice; an empty one is refused rather than falling back to
 * anything. See the checks below for why the supplied text is re-parsed
 * rather than trusted.
 *
 * Every check below runs for every target BEFORE anything is saved: a
 * request naming several services refuses as a whole on the first bad one,
 * naming which service failed, rather than saving some of them and quietly
 * dropping the rest.
 *
 * $note reports a save whose previous version could not be kept. It matters
 * here more than most places: what makes a pin acceptable is that it can be
 * undone from the file history, so a pin saved without one has to say so.
 *
 * @param array<string,string> $targets service name => digest to roll it back to
 * @return string a job id, or '' with $error set on refusal
 */

/**
 * PLAN_181 Part C — what one rollback target's presence check decides, pulled
 * out as a pure function of its answer so the branch itself can be proved
 * directly (tests/server/updaterun.php) without ever asking Docker anything —
 * every digest a test can hand it is one this server was never given to
 * begin with, so the docker call above this in staxx_update_rollback() can
 * only ever prove "absent", never "present", and so can never tell the two
 * settings apart on its own.
 *
 * @return string|null the refusal sentence, or null to proceed (whether or
 *   not the image is actually present — the caller tells those two apart
 *   itself, to decide whether a pull is needed)
 */
function staxx_update_rollback_presence_error(bool $present, bool $keepImages, string $service): ?string {
  if ($present || !$keepImages) return null;
  return 'The previous version for the "'.$service.'" service is no longer present on this server, so it cannot be rolled back to.';
}

/**
 * PLAN_181 Part C — when the image is not on this server and a pull will be
 * needed, `docker manifest inspect` asks the registry whether the digest
 * still exists there, without downloading anything. Its exit code alone
 * cannot be trusted: non-zero also covers a registry that is merely
 * unreachable (network down, timeout, login expired), and refusing a
 * rollback over that would block on a guess StaXX has no way to check. Only
 * the two phrases Docker itself uses for "this digest is gone" are treated
 * as a real refusal; every other non-zero exit goes ahead and lets the
 * actual pull, a few seconds later, report whatever really went wrong.
 *
 * @param int    $code    exit status from the manifest inspect
 * @param string $out     its combined stdout/stderr
 * @param string $service the service this target belongs to, for the message
 * @return string|null the refusal sentence, or null to proceed with the pull
 */
function staxx_update_rollback_source_error(int $code, string $out, string $service): ?string {
  if ($code === 0) return null;
  if (stripos($out, 'no such manifest') !== false || stripos($out, 'manifest unknown') !== false) {
    return 'This version is no longer available at the source, so it cannot be rolled back to.';
  }
  return null;
}

function staxx_update_rollback(string $stack, array $targets, string &$error, string $yaml = '', ?string &$note = null): string {
  $error = '';

  if (!staxx_valid_path($stack)) { $error = 'Invalid stack name.'; return ''; }
  if (empty($targets)) { $error = 'No service was named to roll back.'; return ''; }

  $file = staxx_stack_compose_map()[$stack] ?? '';
  if ($file === '') { $error = 'No compose file found in this stack.'; return ''; }

  $meta = staxx_compose_meta($file);
  if (!$meta['ok']) { $error = 'The compose file could not be read.'; return ''; }

  // Membership first, for every target, before $yaml is even looked at: a
  // shape check on a digest (does it look like "algo:hex"?) is not a
  // substitute for confirming it against this service's OWN recorded
  // history, or a request could re-tag a service's image to any digest
  // present anywhere on the server, not just one it has actually run. This
  // runs ahead of the "no file text" gate below so a request naming a
  // digest no service ever ran is refused for that reason even when no
  // $yaml was supplied at all.
  $images = [];
  foreach ($targets as $service => $target) {
    if (!isset($meta['services'][$service])) {
      $error = 'No service called "'.$service.'" in this stack.';
      return '';
    }

    $image = trim((string)($meta['services'][$service]['image'] ?? ''));
    if ($image === '') {
      $error = 'The "'.$service.'" service has no image set, so there is nothing to roll back.';
      return '';
    }

    $history = staxx_update_history($stack, $service);
    if (!in_array($target, $history, true)) {
      $error = 'That version is not one recorded for the "'.$service.'" service, so it cannot be rolled back to.';
      return '';
    }

    $images[$service] = $image;
  }

  // No file text, no rollback. The old behaviour re-pointed the image's local
  // TAG instead of editing the file, and both halves of that were wrong: a tag
  // is per-machine, so it rolled back every other stack sharing the image the
  // next time anything recreated them, and the file still said `latest`, so any
  // deliberate pull undid it. It is refused rather than kept as a fallback,
  // because a rollback that quietly does the weaker thing is worse than one
  // that says it cannot.
  if ($yaml === '') {
    $error = 'A rollback now pins the compose file, and no file text was supplied, so nothing was changed.';
    return '';
  }

  $tmp = tempnam(sys_get_temp_dir(), 'staxx-rb-');
  if ($tmp === false) {
    $error = 'Could not check the supplied file, so nothing was changed.';
    return '';
  }
  file_put_contents($tmp, $yaml);
  $checkMeta = staxx_compose_meta($tmp);
  @unlink($tmp);
  // staxx_compose_meta() caches its answer to disk keyed by this temp path's
  // own md5, same as any other file it reads — but this path is never read
  // again, so that cached copy would otherwise sit there forever.
  @unlink(STAXX_META_DIR.'/'.md5($tmp).'.json');
  if (!$checkMeta['ok']) {
    $error = 'The supplied file could not be checked, so nothing was changed.';
    return '';
  }

  // A save that claims to be a rollback while actually carrying an
  // unrelated edit is the exact failure this project cares about most, so
  // the text the browser posted is never trusted at face value: it is
  // parsed properly and checked to actually name the version this call was
  // asked to roll back to, for every service being rolled back — a plain
  // strpos() on the raw text would not do, since the digest could just as
  // easily appear inside a comment.
  foreach ($targets as $service => $target) {
    if (!isset($checkMeta['services'][$service])) {
      $error = 'The supplied file could not be checked, so nothing was changed.';
      return '';
    }
    $checkImage = (string)($checkMeta['services'][$service]['image'] ?? '');
    if (strpos($checkImage, '@'.$target) === false) {
      $error = 'The supplied file does not pin the "'.$service.'" service to the requested version, so nothing was changed.';
      return '';
    }
  }

  // This check sits ahead of the "is the image actually on disk" check for
  // every target, even though both are pure checks with no side effects and
  // could run in either order. Reachability is why: run after the presence
  // check, this could only ever be exercised on a machine that already has a
  // real matching image pulled — untestable from fixtures alone, and any
  // test that arranged one would then fall through into the save and the job
  // below, which a test run must never do.
  //
  // PLAN_181 Part C — with "keep the images" switched off, an absent image is
  // no longer a refusal: $needsPull is set instead, and staxx_start_job()
  // below is asked to pull the exact digest back down as part of the same
  // job, rather than trusting `up`'s own default "pull if missing" policy —
  // that way a registry that no longer has this digest fails on the step
  // named for it in the job's own log, before anything is recreated, with
  // nothing already touched (the compose file is not even saved yet). The
  // decision itself is staxx_update_rollback_presence_error() below, so it
  // can be proved directly — the docker call above it can only ever confirm
  // "not present" against a digest this server was never handed, which
  // proves nothing about which of the two settings is in force.
  //
  // When a pull will actually be needed, `docker manifest inspect` is asked
  // first, ahead of anything being saved — it is Docker's own no-download
  // check, so it can tell "the source no longer has this version" apart from
  // "not on this server yet" without pulling it to find out. The decision is
  // staxx_update_rollback_source_error() below, kept separate from the
  // presence check for the same reason: it can only be proved with a fixed
  // exit code and message, never with a real registry round trip.
  $needsPull = false;
  foreach ($targets as $service => $target) {
    $repo = staxx_update_local_repo($images[$service]);

    $checkCode = 1;
    staxx_sh(
      staxx_docker_bin().' image inspect '.escapeshellarg($repo.'@'.$target)
        .' --format '.escapeshellarg('{{.Id}}').' 2>&1',
      10, $checkCode
    );
    $presenceError = staxx_update_rollback_presence_error(
      $checkCode === 0, staxx_update_settings()['keepImages'], $service
    );
    if ($presenceError !== null) { $error = $presenceError; return ''; }

    if ($checkCode !== 0) {
      $needsPull = true;

      $manifestCode = 1;
      $manifestOut = staxx_sh(
        staxx_docker_bin().' manifest inspect '.escapeshellarg($repo.'@'.$target).' 2>&1',
        20, $manifestCode
      );
      $sourceError = staxx_update_rollback_source_error($manifestCode, $manifestOut, $service);
      if ($sourceError !== null) { $error = $sourceError; return ''; }
    }
  }

  // The file is the authority, so this is a save like any other — it lands in
  // the same edit history, which is what makes every pin undoable and the
  // whole reason the edit is saved here rather than left in the browser.
  // Nothing runs `docker tag`: see the refusal above for why that came out.
  //
  // $note carries "the file was saved but its previous version could not be
  // kept". That has to reach the person, because the confirmation they just
  // agreed to promised the old file would be in History.
  if (!staxx_save_stack($stack, $yaml, $error, $note)) {
    return '';
  }

  // Remember the version each service rolled back FROM as its image's skip
  // fingerprint, so the clock does not immediately try to reinstall the very
  // update just backed out of. Belt-and-braces on the $yaml path — the file
  // itself is what stops the next update now — but it is also what silences
  // the "an update is available" nag for a version deliberately declined.
  $state  = staxx_update_state();
  $stImages = (array)$state['images'];
  $changed  = false;
  foreach ($images as $image) {
    if (isset($stImages[$image]) && ($stImages[$image]['remote'] ?? '') !== '') {
      $stImages[$image]['skip'] = $stImages[$image]['remote'];
      $changed = true;
    }
  }
  if ($changed) staxx_update_state_save(['images' => $stImages]);

  return staxx_start_job($stack, $needsPull ? 'rollback-pull' : 'recreate', $error, array_keys($targets));
}

/* staxx_update_unpin() (release a pin by stripping "@sha256:…" back to plain
 * "repo:tag") and the 'update-unpin' action were removed 2026-09-26 (PLAN_188
 * part D): a pin is now released by picking a tag — any tag, not only the
 * one it was pinned from — through the image field or the tag picker, saved
 * through the ordinary 'save'/'file-save' actions like any other edit. That
 * function's own exact-match check (the release must land on precisely the
 * pre-pin "repo:tag", nothing else) is incompatible with picking a
 * different tag on purpose, which is the whole point of the new door — see
 * PLAN_188's own text. staxx_pin_resolve() above is what feeds a pin now;
 * nothing left in this file writes one back off.
 *
 * KNOWN GAP: the old function also cleared a stale "don't offer this again"
 * fingerprint left under an image's unpinned key once released (see
 * tests/server/pinned_due.php's own header for the detail) — the new,
 * generic save path has no hook to run that cleanup from. Rare, and a
 * decision for later rather than a guess made here.
 */

/**
 * PLAN_188 part D — what the Pinned choice needs: the exact build one
 * service is (or last was) on, as a registry digest.
 *
 * A container, running or stopped, answers off Docker's own record of what
 * it actually runs — one `docker inspect` reading both {{.Config.Image}}
 * (the reference) and {{.Image}} (the image ID: a tag can be re-pulled to a
 * newer build without the container moving, so reading the ID is what makes
 * this the build really on this container rather than whatever the tag now
 * means) via staxx_image_id_digest(). No container at all (never started,
 * or removed) falls back to the compose file's own image reference through
 * staxx_image_local() — the plan's own "no container" case, answered the
 * same way every other reader of a not-yet-running service's image already
 * is.
 *
 * Resolves its container through staxx_service_container() with
 * $runningOnly false, unlike the shell and file manager, which pass true:
 * the pin wants whatever build a service's container last ran, running or
 * not, and FILES_ENABLED's own gate (PLAN_188 part C) has nothing to do
 * with pinning an image either way, so a server with the file manager
 * switched off, or a service that merely is not running right now, must not
 * also lose the ability to pin.
 *
 * Refuses in a sentence for the two shapes with nothing to point at: an
 * image built on this server (no registry digest exists to pin to) and one
 * never pulled here (nothing local to read a digest off).
 *
 * @return array{ok:true, image:string, digest:string}|array{ok:false, error:string}
 */
function staxx_pin_resolve(string $stack, string $service, string &$error): array {
  $error = '';
  if (!staxx_valid_path($stack)) { $error = 'Invalid stack name.'; return ['ok' => false, 'error' => $error]; }

  if (!staxx_docker_running()) {
    $error = 'The Docker service is not running.';
    return ['ok' => false, 'error' => $error];
  }

  $file = staxx_find_compose_file(staxx_stack_dir($stack));
  if ($file === '') { $error = 'No compose file found in this stack.'; return ['ok' => false, 'error' => $error]; }

  $meta = staxx_compose_meta($file);
  if (!$meta['ok'] || !isset($meta['services'][$service])) {
    $error = 'No service called "'.$service.'" in this stack.';
    return ['ok' => false, 'error' => $error];
  }

  // Discarded on purpose when empty — "no container at all" is the plan's
  // own fallback case here, not a refusal of staxx_pin_resolve()'s own.
  $containerError = '';
  $container = staxx_service_container($file, staxx_path_leaf($stack), $service, false, $containerError);

  if ($container !== '') {
    // One inspect for both fields, a real tab between them (see
    // staxx_container_net()'s own comment on why `docker inspect --format`
    // must never be given the two characters \t).
    [$ref, $imageId] = array_pad(explode("\t", trim(staxx_sh(
      escapeshellarg(staxx_docker_bin()).' inspect '.escapeshellarg($container).
      ' --format '.escapeshellarg('{{.Config.Image}}'."\t".'{{.Image}}'),
      10
    ))), 2, '');
    if ($ref === '' || $imageId === '') {
      $error = 'Could not read what this container is running.';
      return ['ok' => false, 'error' => $error];
    }
    $local = staxx_image_id_digest($imageId, $ref);
  } else {
    $ref = trim((string)($meta['services'][$service]['image'] ?? ''));
    if ($ref === '') {
      $error = 'This service has no image set, so there is no build to pin to.';
      return ['ok' => false, 'error' => $error];
    }
    $local = staxx_image_local($ref);
  }

  if (!empty($local['built'])) {
    $error = 'This service is built here from a recipe, so there is no fixed build to pin to.';
    return ['ok' => false, 'error' => $error];
  }
  if (empty($local['digest'])) {
    $error = 'This image has never been downloaded here, so there is no build to pin to.';
    return ['ok' => false, 'error' => $error];
  }

  return ['ok' => true, 'image' => $ref, 'digest' => (string)$local['digest']];
}

/* ------------------------------------------------------------- keep-set -- */

/**
 * Every "image:" reference a current stack's compose file actually resolves
 * to, as a lookup set keyed by that exact string — the same shape
 * staxx_update_state()['images'] is keyed by, so the two can be compared
 * directly. Deliberately a small walk of its own rather than a call into
 * Images.php's staxx_images_stack_refs(): that file requires this one, so
 * the other direction would be circular.
 *
 * @param string $excludeStack PLAN_181 Part B — a stack's own compose file is
 *   still on disk while its archive confirmation is being worked out (nothing
 *   has been zipped or removed yet), so a stack named here is left out as if
 *   it were already gone, and the dry run agrees with what actually happens
 *   once the archive really has removed it.
 */
function staxx_update_current_refs(string $excludeStack = ''): array {
  $refs = [];
  foreach (staxx_stack_compose_map() as $rel => $file) {
    if ($excludeStack !== '' && $rel === $excludeStack) continue;
    if ($file === '') continue;
    $meta = staxx_compose_meta($file);
    foreach ((array)($meta['services'] ?? []) as $service) {
      $ref = trim((string)($service['image'] ?? ''));
      if ($ref !== '') $refs[$ref] = true;
    }
  }
  return $refs;
}

/**
 * Every digest worth keeping, grouped by repository: the live pointer for
 * each known image, plus whatever any service's history still remembers.
 *
 * Its own function so it can be proved directly rather than re-implemented
 * in a test and asserted about. This is the list that decides what the
 * Scan stored images window (include/Images.php) is allowed to offer for
 * removal, so "the test builds the same union by hand and it matches"
 * proves the test, not the code.
 *
 * The history half is every digest the per-stack records hold; a digest a
 * rollback still needs that went un-read would look unused and get removed.
 *
 * The "local" half (PLAN_181 item 8/A) only protects a ref some CURRENT
 * stack's compose still names — otherwise the current-pointer digest for a
 * service whose stack has since been archived or edited away is kept for
 * ever, with nothing left that can ever roll back to it. The history half
 * above is untouched: it is what a roll-back actually reads from.
 *
 * @param string $excludeStack PLAN_181 Part B — see staxx_update_current_refs();
 *   the same name is left out of the history half here too, so an archive's
 *   dry-run confirmation (asked before anything is actually removed) agrees
 *   with what is left once that stack really is gone.
 *
 * PLAN_181 Part C — with UPDATE_KEEP_IMAGES set to "no", the history half is
 * left out entirely: the version numbers stay recorded (image history is
 * unaffected — see ImageHistory.php), but nothing here protects the image
 * files any more, so the weekly-cleanup blind spot they used to be safe from
 * now applies to them too. staxx_update_rollback() pulls the exact digest
 * back down instead of refusing when it finds one gone. The local half is a
 * different thing — the currently-pulled pointer for a ref still in active
 * use — and is untouched by this setting.
 */
function staxx_update_keep_digests(string $excludeStack = ''): array {
  $state  = staxx_update_state();
  $images = (array)$state['images'];

  $currentRefs = staxx_update_current_refs($excludeStack);

  $keep = [];
  foreach ($images as $ref => $entry) {
    if (!isset($currentRefs[$ref])) continue;
    $repo = staxx_image_match_repo($ref);
    if (!empty($entry['local'])) $keep[$repo][] = $entry['local'];
  }

  if (!staxx_update_settings()['keepImages']) return $keep;

  $historyKeys = array_keys(staxx_image_history_all());
  $files = staxx_stack_compose_map();
  foreach ($historyKeys as $key) {
    [$stack, $service] = array_pad(explode('::', $key, 2), 2, '');
    if ($excludeStack !== '' && $stack === $excludeStack) continue;
    $file = $files[$stack] ?? '';
    if ($file === '') continue;
    $meta = staxx_compose_meta($file);
    $ref  = trim((string)($meta['services'][$service]['image'] ?? ''));
    if ($ref === '') continue;
    $repo = staxx_image_match_repo($ref);
    foreach (staxx_update_history($stack, $service) as $d) $keep[$repo][] = $d;
  }

  return $keep;
}

/**
 * Normalise a docker image id for comparison: strip an optional
 * 'sha256:' prefix and keep only the leading twelve characters, since
 * `docker image ls` prints the short form and `docker inspect` may print
 * the long one.
 */
function staxx_update_short_id(string $id): string {
  $id = trim($id);
  if (strncmp($id, 'sha256:', 7) === 0) $id = substr($id, 7);
  return substr($id, 0, 12);
}

/* ---------------------------------------------------------------------- queue -- */

function staxx_update_queue_path(): string {
  return STAXX_UPDATE_DIR.'/queue.json';
}

/** The queue file, decoded — an empty array when there is no queue at all. */
function staxx_update_queue_read(): array {
  $raw  = @file_get_contents(staxx_update_queue_path());
  $data = $raw === false ? null : json_decode($raw, true);
  return is_array($data) ? $data : [];
}

/**
 * Written temp-then-rename, the same as staxx_update_state_save() — but this
 * lives in /tmp, not on flash, so unlike that one there is no reason to skip
 * an unchanged write.
 */
function staxx_update_queue_write(array $queue): bool {
  if (!is_dir(STAXX_UPDATE_DIR) && !@mkdir(STAXX_UPDATE_DIR, 0755, true)) return false;

  $encoded = json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  if ($encoded === false) return false;

  return staxx_atomic_write(staxx_update_queue_path(), $encoded);
}

/**
 * The queue's own lock, same atomic-mkdir trick as staxx_update_lock() and
 * under its own name so a running check pass and a running queue tick can
 * never block each other.
 */
function staxx_update_queue_lock(string &$error): bool {
  $error = '';
  if (!is_dir(STAXX_UPDATE_DIR) && !@mkdir(STAXX_UPDATE_DIR, 0755, true)) {
    $error = 'Could not create '.STAXX_UPDATE_DIR;
    return false;
  }

  if (staxx_mkdir_lock_stale(STAXX_UPDATE_DIR.'/queue.lock')) return true;

  $error = 'The queue is already being updated.';
  return false;
}

function staxx_update_queue_unlock(): void {
  @rmdir(STAXX_UPDATE_DIR.'/queue.lock');
}

/**
 * The current queue, in the shape the page always expects — an empty queue
 * reads the same as one that finished and was never looked at again.
 */
function staxx_update_queue_state(): array {
  $queue = staxx_update_queue_read();
  if (!$queue) {
    return ['id' => '', 'scope' => '', 'stopped' => false, 'includeStopped' => false, 'items' => []];
  }
  return $queue;
}

/**
 * Build a fresh queue over one scope, in grid order, and write it — waiting,
 * not yet started; staxx_update_queue_tick() is what actually runs it.
 *
 * @param string $scope 'all', a folder name, or one stack's path
 */
function staxx_update_queue_start(string $scope, bool $includeStopped, string &$error): string {
  $error = '';

  // Held for the whole read-check-write below, so a manual start landing on
  // the cron tick can never pass "nothing running" against a queue the tick
  // is about to replace. staxx_update_queue_tick() takes this same lock, but
  // is never called from here, so there is no re-entrancy to worry about.
  if (!staxx_update_queue_lock($error)) return '';

  $current = staxx_update_queue_read();
  foreach ((array)($current['items'] ?? []) as $item) {
    if (in_array($item['state'] ?? '', ['running', 'waiting'], true) && !($current['stopped'] ?? false)) {
      $error = 'A queue is already running. Stop it before starting another.';
      staxx_update_queue_unlock();
      return '';
    }
  }

  if ($scope !== 'all' && !staxx_valid_path($scope)) {
    $error = 'Invalid scope.';
    staxx_update_queue_unlock();
    return '';
  }

  $images = (array)staxx_update_state()['images'];
  $items  = [];

  foreach (staxx_folder_layout(staxx_stack_states()) as $row) {
    if ($row['type'] !== 'stack') continue;
    $stack = $row['stack'];

    if ($scope === 'all') {
      // every stack
    } elseif ($stack['name'] === $scope) {
      // exact stack match
    } elseif (strpos($stack['name'], $scope.'/') === 0) {
      // folder match
    } else {
      continue;
    }

    if (!$includeStopped && !$stack['running']) continue;
    if ($stack['file'] === '') continue;

    $meta = staxx_compose_meta($stack['file']);
    if (!$meta['ok']) continue;

    $hasUpdate = false;
    foreach ($meta['services'] as $svcMeta) {
      $image = trim((string)($svcMeta['image'] ?? ''));
      if ($image === '') continue;
      if (staxx_updates_pill_for_image($image, $images)['state'] === 'update') { $hasUpdate = true; break; }
    }
    if (!$hasUpdate) continue;

    $items[] = ['stack' => $stack['name'], 'state' => 'waiting', 'job' => '', 'error' => ''];
  }

  if (!$items) {
    $error = 'Nothing in that scope has an update waiting.';
    staxx_update_queue_unlock();
    return '';
  }

  $id = (string)time();
  if (!staxx_update_queue_write([
    'id' => $id, 'scope' => $scope, 'stopped' => false,
    'includeStopped' => $includeStopped, 'items' => $items,
  ])) {
    $error = 'Could not write the update queue.';
    staxx_update_queue_unlock();
    return '';
  }

  staxx_update_queue_unlock();
  return $id;
}

/**
 * Advance the queue by exactly one step: notice a finished running item, then
 * start the next waiting one. Never more than one stack runs at a time.
 * Safe to call repeatedly and concurrently — a lock that cannot be taken
 * simply means someone else is already advancing it, so the current state is
 * returned unchanged rather than waited for.
 */
function staxx_update_queue_tick(): array {
  $lockError = '';
  if (!staxx_update_queue_lock($lockError)) return staxx_update_queue_state();

  $queue = staxx_update_queue_read();
  if (!$queue || !isset($queue['items'])) {
    staxx_update_queue_unlock();
    return staxx_update_queue_state();
  }

  $items   = $queue['items'];
  $changed = false;

  foreach ($items as $i => &$item) {
    if (($item['state'] ?? '') !== 'running') continue;
    $log = staxx_job_log((string)$item['job'], 0);
    if ($log['done']) {
      // A null exit means the job's own log is gone (pruned, or never
      // written) — not the same thing as an update that ran and failed, and
      // the message must not claim otherwise. Marked failed anyway, not
      // done, because there is nothing here to say it actually succeeded.
      if ($log['exit'] === null) {
        $item['state'] = 'failed';
        $item['error'] = 'The update job\'s log is gone, so its outcome could not be recorded. '
                       . 'Check the stack directly.';
        if (empty($item['reason'])) {
          $item['reason'] = staxx_update_failure_reason('', staxx_update_stack_image((string)($item['stack'] ?? '')));
        }
      } elseif ($log['exit'] === 0) {
        $item['state'] = 'done';
        // The queue has no browser to prompt a refresh, so without this the
        // local digest stays at its pre-pull value and the same stack looks
        // due again at the next tick, fifteen minutes from now. This also
        // re-asks the registry when the disk still disagrees with the
        // remembered answer, which is what stops a stale registry digest
        // re-queueing the same stack every fifteen minutes forever.
        staxx_update_refresh_after_run((string)($item['stack'] ?? ''));
      } else {
        $item['state'] = 'failed';
        $item['error'] = 'The update failed. Open the log for details.';
        // The log is pruned within the hour, so the plain-words reason is read
        // now and kept on the item for the message and the summary.
        if (empty($item['reason'])) {
          $item['reason'] = staxx_update_failure_reason((string)$log['text'], staxx_update_stack_image((string)($item['stack'] ?? '')));
        }
      }
      $changed = true;
    }
    break; // only ever one item running
  }
  unset($item);

  $hasRunning = false;
  foreach ($items as $item) if (($item['state'] ?? '') === 'running') $hasRunning = true;

  // PLAN_68 Part C: starting the next item looks the stack up by name in
  // staxx_list_stacks() twice below — once to push its rollback fingerprint,
  // once (inside staxx_start_job()) to actually run it — and an unreadable
  // root makes that list empty, not merely stale. Skipping this tick
  // entirely rather than proceeding means a stack that briefly can't be seen
  // never has its update started without its "before" digest recorded, and
  // never gets waved through staxx_start_job() against a root it cannot
  // read. The item stays 'waiting' and is picked up on a later tick once the
  // root is visible again — nothing here is lost, only delayed.
  if (!$hasRunning && !($queue['stopped'] ?? false) && staxx_stacks_visible()) {
    foreach ($items as $i => &$item) {
      if (($item['state'] ?? '') !== 'waiting') continue;

      // Every service in this stack has its fingerprint (and version, source
      // and release notes, where known) recorded before the pull runs — the
      // same "before, not after" ordering staxx_update_record_before_pull()
      // documents, so a roll back has something to roll back to even if the
      // update job itself never finishes cleanly.
      staxx_update_record_before_pull($item['stack']);

      $jobError = '';
      $job = staxx_start_job($item['stack'], 'update', $jobError);
      if ($job === '') {
        $item['state'] = 'failed';
        $item['error'] = $jobError !== '' ? $jobError : 'Could not start the update.';
        $item['reason'] = $item['error'];
      } else {
        $item['state'] = 'running';
        $item['job']   = $job;
      }
      $changed = true;
      break; // only ever one item started per tick
    }
    unset($item);
  }

  // Every item finished, one way or another — the whole pass hands its
  // finished items to staxx_notify_events() in ONE call, never one per stack,
  // and only once per queue. Notify.php decides what is said and whether it
  // goes now or waits for the summary; 'installed' and 'failed' stay
  // independent switches, so someone who wants the failure but not the
  // success still hears the one that broke.
  //
  // PLAN_154 — there is no global gate here before even asking:
  // staxx_update_queue_notify_names() resolves each stack's own
  // 'installed'/'failed' want per container, following the server's switch
  // for whichever event a container leaves unset, so a container that
  // overrides the server's switch upward is not silenced by it.
  $terminal = true;
  foreach ($items as $item) {
    if (!in_array($item['state'] ?? '', ['done', 'failed', 'skipped'], true)) { $terminal = false; break; }
  }
  if ($terminal && $changed && empty($queue['notified'])) {
    $settings    = staxx_update_settings();
    $names       = staxx_update_queue_notify_names($items, $settings);
    // A failed pull and a container that will not come back up both land
    // here as 'failed' — the job's own exit code does not say which, so
    // neither does the message; see the 'failed' state set above.
    $events = staxx_update_queue_events($items, $names, $settings);
    if ($events !== []) staxx_notify_events($events);
    // Set once the terminal state has been looked at, whether or not
    // anything wanted a message — otherwise a queue left idle would
    // re-evaluate this block on every tick for no reason, and a setting
    // changed later while the same queue is still sitting there would fire
    // a stale message for a run that finished earlier.
    $queue['notified'] = true;
  }

  if ($changed) {
    $queue['items'] = $items;
    staxx_update_queue_write($queue);
  }

  staxx_update_queue_unlock();
  return staxx_update_queue_state();
}

/** Let the running stack finish; mark everything still waiting as skipped. */
function staxx_update_queue_stop(): bool {
  $queue = staxx_update_queue_read();
  if (!$queue || !isset($queue['items'])) return false;

  foreach ($queue['items'] as &$item) {
    if (($item['state'] ?? '') === 'waiting') $item['state'] = 'skipped';
  }
  unset($item);
  $queue['stopped'] = true;

  return staxx_update_queue_write($queue);
}

/**
 * The 15-minute cron pass, and nothing else. Ticks a running queue along;
 * otherwise, when staxx_update_due() has found anything, starts a fresh
 * queue over exactly those stacks. Costs no network either way — every
 * digest it acts on was already fetched by a check pass.
 *
 * The pinned-service reminder (PLAN_205) rides along on this same pass,
 * first — it is its own weekly interval and never touches the queue, so it
 * runs whether or not anything else here is due. The summary is sent
 * straight after it, when due (staxx_notify_digest_pass()).
 */
function staxx_update_apply_pass(): array {
  staxx_update_pinned_reminder_pass();
  // The daily or weekly summary, when it is due: it carries the pinned list the
  // pass above just marked, so it runs second.
  staxx_notify_digest_pass();

  // Held only across the read-check-write below, never across a call to
  // staxx_update_queue_tick() — that function takes this same lock itself,
  // and a non-reentrant mkdir lock taken twice by one process would just
  // block on itself. If someone else holds the lock (a manual start, or
  // another pass), the queue is left exactly as it is rather than raced.
  $lockError = '';
  if (!staxx_update_queue_lock($lockError)) return staxx_update_queue_state();

  $queue = staxx_update_queue_read();
  $active = false;
  foreach ((array)($queue['items'] ?? []) as $item) {
    if (in_array($item['state'] ?? '', ['running', 'waiting'], true)) { $active = true; break; }
  }
  if ($active && !($queue['stopped'] ?? false)) {
    staxx_update_queue_unlock();
    return staxx_update_queue_tick();
  }

  $due = staxx_update_due();
  if (!$due) {
    staxx_update_queue_unlock();
    return staxx_update_queue_state();
  }

  // staxx_update_due() answers per service; the queue works a stack at a
  // time, so the due list collapses to its distinct stacks first.
  $wanted = [];
  foreach ($due as $d) $wanted[$d['stack']] = true;

  $items = [];
  foreach (staxx_folder_layout(staxx_stack_states()) as $row) {
    if ($row['type'] !== 'stack' || !isset($wanted[$row['stack']['name']])) continue;
    $items[] = ['stack' => $row['stack']['name'], 'state' => 'waiting', 'job' => '', 'error' => ''];
  }
  if (!$items) {
    staxx_update_queue_unlock();
    return staxx_update_queue_state();
  }

  staxx_update_queue_write([
    'id' => (string)time(), 'scope' => 'all', 'stopped' => false,
    'includeStopped' => false, 'items' => $items,
  ]);

  staxx_update_queue_unlock();
  return staxx_update_queue_tick();
}

/* ------------------------------------------------------------------ notify -- */

/**
 * One message through Unraid's own notifier — never one per container.
 * PLAN_154 — this no longer gates on the server's own three switches itself:
 * a container may override one of them upward (want a message the server's
 * own default would suppress), so every caller now works out first, per
 * event and with the server's switch only as the fallback for a container
 * that says nothing, whether anyone actually wants this message — and calls
 * here only once that list is non-empty.
 *
 * The binary is overridable through STAXX_NOTIFY_BIN — same trick as
 * STAXX_UPDATE_STATE — so a server suite can prove a message was actually
 * sent by pointing this at a throwaway stub instead of Unraid's real
 * notifier, never by sending a real notification from this box.
 */
function staxx_update_notify(string $subject, string $body, string $message = '', string $link = '',
                             string $importance = 'normal'): void {
  $bin = getenv('STAXX_NOTIFY_BIN');
  $bin = ($bin !== false && $bin !== '') ? $bin : '/usr/local/emhttp/webGui/scripts/notify';
  // $importance may carry Unraid's delivery bits after a space ("warning 5"),
  // which override the user's own for this one message; anything else is normal.
  if (!preg_match('/^(normal|warning|alert)( [0-7])?$/', $importance)) $importance = 'normal';
  staxx_sh(
    $bin
      .' -e '.escapeshellarg('StaXX')
      .' -s '.escapeshellarg($subject)
      .' -d '.escapeshellarg($body)
      .($message !== '' ? ' -m '.escapeshellarg($message) : '')
      .($link !== '' ? ' -l '.escapeshellarg($link) : '')
      .' -i '.escapeshellarg($importance),
    10
  );
}

/**
 * A stack name on its own when the service shares the stack's own leaf name
 * — the ordinary shape of a one-service stack — otherwise the stack with the
 * service named alongside it, since "jellyfin" on its own would not say
 * which container inside a multi-service stack is meant.
 */
function staxx_update_container_label(string $stack, string $service): string {
  $slash = strrpos($stack, '/');
  $leaf  = $slash === false ? $stack : substr($stack, $slash + 1);
  return $service === $leaf ? $stack : $stack.' ('.$service.')';
}

/**
 * Names every entry when there are few enough to read comfortably in a
 * single notification line, otherwise just says how many — an update
 * message is read at a glance, not studied, so a list of fifteen containers
 * is worse than no list at all. PLAN_150 items 2/3: this is the one place
 * that decides "few enough" for every update notice, so the three messages
 * that use it (found, installed, failed) never disagree on the cutoff.
 */
function staxx_update_name_or_count(array $names, string $singular, string $plural): string {
  $n = count($names);
  $word = $n === 1 ? $singular : $plural;
  return $n <= 5 ? $n.' '.$word.': '.implode(', ', $names) : $n.' '.$word;
}

/**
 * Whether any service in this stack's own compose file resolves to wanting a
 * mention in an update message for one event — 'found', 'installed' or
 * 'failed'. PLAN_154 split this per event: wanting to hear about a failure
 * and wanting to hear about a found update are different questions, and a
 * service that says nothing about an event follows the server's own switch
 * for that event, not a lumped-together answer. The completion and failure
 * notices below work a whole stack at a time — the queue always did, see
 * staxx_update_queue_start() — so there is no single "the" service to ask;
 * naming the stack when at least one service inside opted in is the honest
 * reading of a per-container switch applied to a stack-level report.
 */
function staxx_update_stack_wants_notify(string $event, array $meta, array $global): bool {
  $key = 'notify'.ucfirst($event);
  foreach (array_keys((array)($meta['services'] ?? [])) as $svc) {
    $resolved = staxx_update_policy_from_meta($meta, $svc, $global)[$key];
    if ($resolved ?? $global[$key]) return true;
  }
  return false;
}

/**
 * Labels for every container whose image is currently sitting in "update
 * waiting" AND whose own resolved say is yes — pulled out of
 * staxx_update_check() so a test can prove the filtering (including the
 * "everyone opted out" case) without ever calling staxx_update_notify().
 * $refs and $stackFiles are staxx_update_images()/staxx_update_stack_files()'s
 * own maps, already built once by the check pass this runs inside of, so
 * nothing here re-scans the stack root — staxx_update_policy_from_meta() is
 * used rather than staxx_update_policy() for the same reason.
 *
 * @param array $images staxx_update_state()'s own 'images' map
 * @param array $refs image => ["stack::service", …]
 * @param array $stackFiles stack name => compose file path
 * @param array $global staxx_update_settings()'s return
 * @return string[] container labels, de-duplicated
 */
function staxx_update_found_containers(array $images, array $refs, array $stackFiles, array $global): array {
  $wanted = [];
  foreach (staxx_update_found_events($images, $refs, $stackFiles, $global) as $e) {
    $wanted[] = staxx_update_container_label($e['stack'], $e['service']);
  }
  return array_values(array_unique($wanted));
}

/**
 * One 'found' event per container that has an update waiting and whose own
 * resolved say is yes — the filter staxx_update_found_containers() names its
 * labels from, with what the message needs: the running and waiting
 * versions, both digests and the waiting version's release notes (only the
 * keys the check stored; Notify.php ignores notes whose notesFor is another
 * version).
 *
 * @return array[] events for staxx_notify_events()
 */
function staxx_update_found_events(array $images, array $refs, array $stackFiles, array $global): array {
  $events    = [];
  $metaCache = [];

  foreach (array_keys($images) as $img) {
    if (staxx_updates_pill_for_image($img, $images)['state'] !== 'update') continue;

    foreach (($refs[$img] ?? []) as $ref) {
      $parts    = explode('::', $ref, 2);
      $refStack = $parts[0] ?? '';
      $refSvc   = $parts[1] ?? '';

      if (!isset($metaCache[$refStack])) {
        $file = $stackFiles[$refStack] ?? '';
        $metaCache[$refStack] = $file !== '' ? staxx_compose_meta($file) : ['ok' => false];
      }
      // This is the 'found' message specifically — a container's own null
      // (no opinion at either scope) falls to the server's own 'found'
      // switch, not to whatever it decided for 'installed' or 'failed'.
      $meta  = $metaCache[$refStack];
      $wants = $meta['ok'] ? (staxx_update_policy_from_meta($meta, $refSvc, $global)['notifyFound'] ?? $global['notifyFound'])
                           : $global['notifyFound'];
      if (!$wants) continue;

      $e = (array)$images[$img];
      $event = ['kind' => 'found', 'stack' => $refStack, 'service' => $refSvc, 'image' => (string)$img,
                'was' => (string)($e['was'] ?? ''), 'version' => (string)($e['version'] ?? ''),
                'digest' => (string)($e['remote'] ?? ''), 'wasDigest' => (string)($e['local'] ?? '')];
      foreach (['notes', 'notesUrl', 'notesCut', 'notesFor'] as $k) if (isset($e[$k])) $event[$k] = $e[$k];
      $events[] = $event;
    }
  }

  return $events;
}

/**
 * Which of a queue's finished items (state 'done' or 'failed') resolve to
 * wanting a mention, split by outcome — pulled out of
 * staxx_update_queue_tick() so a test can prove the filtering (including the
 * "everyone opted out" case) without ever calling staxx_update_notify(),
 * which would fire a real notification on this box if either switch happens
 * to be on. One scan of every stack's compose file, not one per queue item —
 * a queue only ever holds a handful of due stacks, so this costs the same
 * lookup the check pass already makes for the same reason.
 *
 * @param array $items staxx_update_queue_tick()'s own item list
 * @param array $global staxx_update_settings()'s return
 * @return array{done: string[], failed: string[]}
 */
function staxx_update_queue_notify_names(array $items, array $global): array {
  $stackFiles = staxx_update_stack_files();
  $metaCache  = [];
  $doneNames   = [];
  $failedNames = [];

  foreach ($items as $item) {
    $state = $item['state'] ?? '';
    if ($state !== 'done' && $state !== 'failed') continue;

    $stackName = (string)($item['stack'] ?? '');
    if (!isset($metaCache[$stackName])) {
      $file = $stackFiles[$stackName] ?? '';
      $metaCache[$stackName] = $file !== '' ? staxx_compose_meta($file) : ['ok' => false];
    }
    // 'done' asks about the 'installed' event, 'failed' about 'failed' — the
    // two are independent switches, not one shared "wants a message" answer.
    $meta  = $metaCache[$stackName];
    $event = $state === 'done' ? 'installed' : 'failed';
    $wants = $meta['ok'] ? staxx_update_stack_wants_notify($event, $meta, $global)
                         : $global['notify'.ucfirst($event)];
    if (!$wants) continue;

    if ($state === 'done') $doneNames[] = $stackName; else $failedNames[] = $stackName;
  }

  return ['done' => $doneNames, 'failed' => $failedNames];
}

/** The first service's image in a stack, or '' — what a failure message names the registry from. */
function staxx_update_stack_image(string $stack): string {
  $file = staxx_update_stack_files()[$stack] ?? '';
  $meta = $file !== '' ? staxx_compose_meta($file) : ['ok' => false];
  if (!$meta['ok']) return '';
  foreach ((array)$meta['services'] as $svcMeta) {
    $image = trim((string)($svcMeta['image'] ?? ''));
    if ($image !== '') return $image;
  }
  return '';
}

/**
 * An image's size on disk in bytes, from one local `docker image inspect`
 * (8 s at most, no registry request); 0 when it cannot be read, which the
 * message treats as "leave the size out". STAXX_DOCKER_BIN lets a suite
 * stand in for docker.
 */
function staxx_update_image_size(string $image): int {
  if ($image === '') return 0;
  $bin = getenv('STAXX_DOCKER_BIN');
  $bin = ($bin !== false && $bin !== '') ? $bin : staxx_docker_bin();
  $code = 1;
  $out = trim(staxx_sh($bin.' image inspect --format '.escapeshellarg('{{.Size}}').' '.escapeshellarg($image), 8, $code));
  return ($code === 0 && ctype_digit($out)) ? (int)$out : 0;
}

/**
 * The events for a finished queue, as staxx_notify_events() takes them: one
 * 'installed' event per updated service and one 'failed' event per failed
 * stack, only for the stacks staxx_update_queue_notify_names() ($names) let
 * through. Versions, digests and notes come from what the check already
 * stored (updates.json) and from the stack's image history, whose newest
 * entry is the build that was replaced; nothing is fetched here beyond the
 * one local size read per installed service.
 *
 * @param array $items the queue's items
 * @param array $names staxx_update_queue_notify_names()'s return
 * @param array $global staxx_update_settings()'s return
 * @return array[]
 */
function staxx_update_queue_events(array $items, array $names, array $global): array {
  $stackFiles = staxx_update_stack_files();
  $images     = (array)(staxx_update_state()['images'] ?? []);
  $events     = [];

  foreach ($items as $item) {
    $state = $item['state'] ?? '';
    $stack = (string)($item['stack'] ?? '');
    if ($state !== 'done' && $state !== 'failed') continue;
    if (!in_array($stack, $state === 'done' ? $names['done'] : $names['failed'], true)) continue;

    $file = $stackFiles[$stack] ?? '';
    $meta = $file !== '' ? staxx_compose_meta($file) : ['ok' => false];
    $services = $meta['ok'] ? (array)$meta['services'] : [];

    if ($state === 'failed') {
      $first = '';
      foreach ($services as $svcMeta) {
        $first = trim((string)($svcMeta['image'] ?? ''));
        if ($first !== '') break;
      }
      $events[] = ['kind' => 'failed', 'stack' => $stack, 'service' => '', 'image' => $first,
                   'reason' => (string)($item['reason'] ?? '')];
      continue;
    }

    $before = count($events);
    foreach ($services as $svc => $svcMeta) {
      $image = trim((string)($svcMeta['image'] ?? ''));
      if ($image === '') continue;
      if (!(staxx_update_policy_from_meta($meta, $svc, $global)['notifyInstalled'] ?? $global['notifyInstalled'])) continue;

      $entry = (array)($images[$image] ?? []);
      $prev  = (array)(staxx_image_history($stack, (string)$svc)[0] ?? []);
      if ($entry === [] && $prev === []) continue;

      $wasDigest = (string)($prev['digest'] ?? '');
      $digest    = (string)(($entry['local'] ?? '') !== '' ? $entry['local'] : ($entry['remote'] ?? ''));
      if ($wasDigest !== '' && $wasDigest === $digest) continue; // this service did not change

      $event = ['kind' => 'installed', 'stack' => $stack, 'service' => (string)$svc, 'image' => $image,
                'was' => (string)(($prev['version'] ?? '') !== '' ? $prev['version'] : ($entry['was'] ?? '')),
                'version' => (string)($entry['version'] ?? ''), 'digest' => $digest, 'wasDigest' => $wasDigest];
      foreach (['notes', 'notesUrl', 'notesCut', 'notesFor'] as $k) if (isset($entry[$k])) $event[$k] = $entry[$k];
      $size = staxx_update_image_size($image);
      if ($size > 0) $event['size'] = $size;
      $events[] = $event;
    }
    // Nothing singled out (no versions or history known): still say the stack updated.
    if (count($events) === $before) {
      $events[] = ['kind' => 'installed', 'stack' => $stack, 'service' => '', 'image' => ''];
    }
  }

  return $events;
}

/* ------------------------------------------------------------ locally built -- */

/**
 * The image named by the final stage's FROM in this service's build recipe,
 * with ARG defaults declared in the recipe substituted. '' when the recipe
 * cannot be found, when the FROM still holds an unresolved variable, or when
 * it names an earlier build stage rather than a real image — none of those
 * can honestly be compared against a registry.
 *
 * Runs `compose config` itself rather than going through
 * staxx_compose_meta(), which only keeps a handful of named fields and throws
 * the rest of the resolved YAML away — the build block was never one of them.
 */
function staxx_build_base(string $stack, string $service): string {
  if (!staxx_valid_path($stack)) return '';

  $dir  = staxx_stack_dir($stack);
  $file = staxx_find_compose_file($dir);
  if ($file === '') return '';

  $cmd = staxx_compose_cmd();
  if ($cmd === '') return '';

  $files = staxx_compose_files($file);
  $code  = 1;
  $yaml  = staxx_sh($cmd.' '.staxx_compose_file_args($files).' config 2>&1', 15, $code);
  if ($code !== 0) return '';

  $prefix = 'services'."\0".$service."\0".'build'."\0";
  $context = '';
  $dockerfile = 'Dockerfile';
  $args = [];

  foreach (staxx_yaml_flatten($yaml) as $path => $value) {
    if (strpos($path, $prefix) !== 0) continue;
    $rest  = substr($path, strlen($prefix));
    $parts = explode("\0", $rest);

    if ($parts[0] === 'context' && count($parts) === 1) $context = $value;
    elseif ($parts[0] === 'dockerfile' && count($parts) === 1) $dockerfile = $value;
    elseif ($parts[0] === 'args' && count($parts) === 2) $args[$parts[1]] = $value;
  }
  if ($context === '') return ''; // this service does not build an image at all

  $ctxDir = $context[0] === '/' ? $context : rtrim($dir, '/').'/'.$context;
  // A compose file setting `dockerfile: ""` explicitly is nonsense, but it
  // parses, and indexing an empty string below would otherwise be a warning
  // on every such call rather than the harmless fallback this defaulted to.
  if ($dockerfile === '') $dockerfile = 'Dockerfile';
  $recipePath = $dockerfile[0] === '/' ? $dockerfile : rtrim($ctxDir, '/').'/'.$dockerfile;

  $recipe = @file_get_contents($recipePath);
  if ($recipe === false) return '';

  // Only ARG defaults declared IN the recipe are ever substituted. A
  // --build-arg supplied only at build time, with no default here, cannot be
  // known without actually running the build, so it is left unresolved on
  // purpose rather than guessed at.
  $argDefaults = [];
  foreach (explode("\n", $recipe) as $line) {
    if (preg_match('/^\s*ARG\s+([A-Za-z_][A-Za-z0-9_]*)(?:=(.*))?\s*$/', $line, $m)) {
      $argDefaults[$m[1]] = trim($m[2] ?? '', "\"' ");
    }
  }
  // Compose's own build args, where set, win over the Dockerfile's defaults —
  // the same precedence an actual build applies.
  $vars = array_merge($argDefaults, $args);

  $stages = [];
  foreach (explode("\n", $recipe) as $line) {
    if (preg_match('/^\s*FROM\s+(\S+)(?:\s+[Aa][Ss]\s+(\S+))?\s*$/', $line, $m)) {
      $stages[] = ['from' => $m[1], 'name' => $m[2] ?? ''];
    }
  }
  if (!$stages) return '';

  $final = end($stages);
  $resolved = preg_replace_callback(
    '/\$\{?([A-Za-z_][A-Za-z0-9_]*)\}?/',
    function ($m) use ($vars) { return $vars[$m[1]] ?? $m[0]; },
    $final['from']
  );

  if (strpos($resolved, '$') !== false) return ''; // still unresolved
  foreach ($stages as $stage) {
    if ($stage['name'] !== '' && $stage['name'] === $resolved) return ''; // names an earlier stage, not a real image
  }

  return $resolved;
}

/**
 * True when the base a locally-built service is built from has moved on
 * since the last time this was checked. Records the base's current digest
 * the first time nothing is on file yet, and reports false that round —
 * calling a change on the very first look would be a guess dressed up as a
 * finding, not something actually observed.
 */
function staxx_rebuild_due(string $stack, string $service, string &$why): bool {
  $why = '';

  $base = staxx_build_base($stack, $service);
  if ($base === '') {
    $why = "This service's build recipe could not be read, so it cannot be checked.";
    return false;
  }

  $remoteWhy = '';
  $remote = staxx_image_remote($base, $remoteWhy);
  if (empty($remote['digest'])) {
    $why = 'The base image could not be checked.';
    return false;
  }

  $key   = $stack.'::'.$service;
  $state = staxx_update_state();
  $bases = (array)$state['bases'];
  $recorded = (string)($bases[$key] ?? '');

  if ($recorded === '') {
    $bases[$key] = $remote['digest'];
    staxx_update_state_save(['bases' => $bases]);
    return false;
  }

  if ($recorded === $remote['digest']) return false;

  $why = 'The base image this service is built from has moved on. Rebuild to pick it up.';
  return true;
}

/**
 * Record the base image's current registry digest as this service's new
 * baseline, so a rebuild just started stops being reported as due for ever
 * afterwards. Called BEFORE the job starts, not after — the same "before,
 * not after" ordering staxx_update_history_push() uses, since a slow build
 * could otherwise finish after the base has already moved on again. Quietly
 * does nothing when the base cannot be resolved or checked; the next check
 * pass's own call to staxx_rebuild_due() will pick it up once it can.
 */
function staxx_rebuild_baseline_reset(string $stack, string $service): void {
  $base = staxx_build_base($stack, $service);
  if ($base === '') return;

  $why = '';
  $remote = staxx_image_remote($base, $why);
  if (empty($remote['digest'])) return;

  $key   = $stack.'::'.$service;
  $state = staxx_update_state();
  $bases = (array)$state['bases'];
  $bases[$key] = $remote['digest'];
  staxx_update_state_save(['bases' => $bases]);
}
?>
