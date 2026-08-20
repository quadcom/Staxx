<?php
/* staxx_gpu_busy_percent() and staxx_stats_intel_gpu() — the Intel GPU
 * reading now comes from a millisecond idle-residency counter in sysfs
 * (rc6_residency_ms, under each Intel card's own gt directory in
 * /sys/class/drm, or under its power directory on older kernels) instead of
 * intel_gpu_top, which had to be started and killed under
 * a timeout every few seconds forever. This checks the busy-percentage maths
 * in isolation, and that the reader's public shape survives with no sample on
 * disk at all.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine:
 *
 *     pscp tests/server/gpu.php root@<box>:/tmp/
 *     plink … "php /tmp/gpu.php"
 *
 * Prints one line per case and exits non-zero on any failure. The closing
 * "for reading" block makes an actual call to staxx_stats_intel_gpu() so a
 * human can see what the real card on this box currently reports. */

require_once '/usr/local/emhttp/plugins/staxx/include/Stats.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

/* --------------------------------------------------- the busy calculation -- */

// Half busy: over a 1000ms interval the idle counter grew by 500ms, so the
// card was doing something the other half of the time.
ok('normal interval: half the time idle => 50% busy',
   staxx_gpu_busy_percent(0.0, 500.0, 1000.0) === 50.0);

// Two readings taken in the same instant: no time passed to measure a rate
// over, so this must read as "no data" (0), never divide by zero.
ok('zero interval: no crash, reads as 0',
   staxx_gpu_busy_percent(100.0, 100.0, 0.0) === 0.0);

// The counter went backwards — a card reset, or a counter wrap. Treated as no
// data rather than as a negative or wildly large busy figure.
ok('counter went backwards => 0, not negative',
   staxx_gpu_busy_percent(500.0, 100.0, 1000.0) === 0.0);

// Idle grew by MORE than the wall-clock interval — a clock or counter drift
// which, read literally, would make the card more-than-fully idle. Clamp to
// 0, not a negative busy percentage.
ok('idle growth exceeds elapsed time => clamped to 0',
   staxx_gpu_busy_percent(0.0, 1500.0, 1000.0) === 0.0);

// Full load: the idle counter did not move at all while real time passed, so
// the card was busy the entire interval.
ok('idle counter unchanged over a real interval => 100% busy',
   staxx_gpu_busy_percent(200.0, 200.0, 1000.0) === 100.0);

// The measured point from the real hardware: 2002ms of idle time out of a
// 2004ms interval reads as 0% busy (rounds down from a hair under 1%).
$measured = staxx_gpu_busy_percent(0.0, 2002.0, 2004.0);
ok('the reading actually measured on the box: idle 2002ms of 2004ms => ~0% busy',
   $measured >= 0.0 && $measured < 1.0, round($measured, 3).'%');

/* ------------------------------------------------- the reader's own shape -- */

// The real collector may already have written intel.raw/intel.prev on this
// box, so this moves them aside for the "no sample" case and puts them back
// afterwards, rather than assuming a clean directory.
$rawFile  = STAXX_STATS_DIR.'/intel.raw';
$prevFile = STAXX_STATS_DIR.'/intel.prev';
$rawSaved  = @file_get_contents($rawFile);
$prevSaved = @file_get_contents($prevFile);
@unlink($rawFile);
@unlink($prevFile);

// No sample file at all: present must be false, and every other key must
// still be the documented type rather than missing or null.
$empty = staxx_stats_intel_gpu();
ok('no sample on disk: present is false',
   $empty['present'] === false);
ok('no sample on disk: byContainer is an empty array',
   is_array($empty['byContainer']) && $empty['byContainer'] === []);
ok('no sample on disk: engines is an empty array',
   is_array($empty['engines']) && $empty['engines'] === []);
ok('no sample on disk: total is a float, zero',
   is_float($empty['total']) && $empty['total'] === 0.0);

// Restore whatever the real collector had written before this test ran.
if ($rawSaved  !== false) @file_put_contents($rawFile, $rawSaved);
if ($prevSaved !== false) @file_put_contents($prevFile, $prevSaved);

// engines is documented as always empty now — nothing in sysfs breaks usage
// down by render/video/enhance engine the way intel_gpu_top did.
ok('engines is always empty, regardless of sample state',
   $empty['engines'] === []);

/* ---------------------------------------------------------- for reading --- */

echo "\n       staxx_stats_intel_gpu(), for reading — the real card on this box:\n";
$live = staxx_stats_intel_gpu();
printf("       present: %s\n", $live['present'] ? 'yes' : 'no');
printf("       busy:    %.1f%%\n", $live['total']);
printf("       engines: %s\n", $live['engines'] ? json_encode($live['engines']) : '(none, expected)');

foreach (['/sys/class/drm/card0/gt/gt0', '/sys/class/drm/card1/gt/gt0'] as $gt) {
  if (!is_dir($gt)) continue;
  $freq = trim((string)@file_get_contents($gt.'/rps_act_freq_mhz'));
  $max  = trim((string)@file_get_contents($gt.'/rps_max_freq_mhz'));
  printf("       %-30s clock %s / %s MHz\n", $gt, $freq !== '' ? $freq : '?', $max !== '' ? $max : '?');
}

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
