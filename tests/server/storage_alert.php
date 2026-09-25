<?php
/* PLAN_181 Part D — the storage alert that replaced the weekly image
 * cleanup (Adrian's decision, 2026-09-25: "due to the sensitivity of the
 * content with these items, I don't think we should schedule a weekly
 * automatic cleanup... we could set up alerts based on a storage usage
 * threshold instead").
 *
 * Covers the two pure functions in include/Images.php:
 *
 *   staxx_storage_alert_rule()   — alert = clutter > 0 AND (percent >= pct
 *                                  limit OR oldest days >= day limit).
 *   staxx_clutter_since_merge()  — an id already remembered keeps its date,
 *                                  a new id is stamped with today, an id no
 *                                  longer in the clutter is dropped.
 *
 * Both are proved over in-memory arrays only. No real image is touched, no
 * image is removed, and STORE_ROOT is never redirected — neither function
 * reads the store or calls Docker.
 *
 * Runs ON THE SERVER — there is no PHP on the dev machine:
 *
 *     pscp tests/server/storage_alert.php root@<box>:/tmp/
 *     plink … "php /tmp/storage_alert.php"
 *
 * Prints one line per case and exits non-zero on any failure.
 */

require_once '/usr/local/emhttp/plugins/staxx/include/Images.php';

$fails = 0;
function ok(string $what, bool $pass, string $note = ''): void {
  global $fails;
  if (!$pass) $fails++;
  printf("%-6s %s%s\n", $pass ? 'ok' : 'FAIL', $what, $note !== '' ? '  ('.$note.')' : '');
}

/* ------------------------------------------------------- the alert rule -- */

ok('no clutter never alerts, however full the storage is',
   staxx_storage_alert_rule(99.0, 0, 500, 85, 30) === false);

ok('clutter alone, below both limits, does not alert',
   staxx_storage_alert_rule(50.0, 1000, 5, 85, 30) === false);

ok('clutter plus storage at the percent limit alerts',
   staxx_storage_alert_rule(85.0, 1000, 0, 85, 30) === true);

ok('clutter plus storage just under the percent limit does not alert on percent alone',
   staxx_storage_alert_rule(84.9, 1000, 0, 85, 30) === false);

ok('clutter plus clutter old enough alerts even with plenty of storage free',
   staxx_storage_alert_rule(10.0, 1000, 30, 85, 30) === true);

ok('clutter just under the day limit does not alert on age alone',
   staxx_storage_alert_rule(10.0, 1000, 29, 85, 30) === false);

ok('either condition is enough — both together still alerts',
   staxx_storage_alert_rule(90.0, 1000, 40, 85, 30) === true);

/* ------------------------------------------------- the clutter-since merge -- */

$today = '2026-09-25';

$merged = staxx_clutter_since_merge([], ['sha256:aaa'], $today);
ok('an id seen for the first time is stamped with today',
   ($merged['sha256:aaa'] ?? null) === $today, json_encode($merged));

$merged = staxx_clutter_since_merge(['sha256:aaa' => '2026-08-01'], ['sha256:aaa'], $today);
ok('an id already remembered keeps its own date, not today\'s',
   ($merged['sha256:aaa'] ?? null) === '2026-08-01', json_encode($merged));

$merged = staxx_clutter_since_merge(['sha256:aaa' => '2026-08-01'], [], $today);
ok('an id no longer in the clutter is dropped',
   !array_key_exists('sha256:aaa', $merged), json_encode($merged));

$merged = staxx_clutter_since_merge(
  ['sha256:aaa' => '2026-08-01', 'sha256:bbb' => '2026-09-01'],
  ['sha256:aaa', 'sha256:ccc'],
  $today
);
ok('a mixed pass keeps the still-clutter id\'s date, drops the one that left, and stamps the new one',
   $merged === ['sha256:aaa' => '2026-08-01', 'sha256:ccc' => $today], json_encode($merged));

echo "\n".($fails ? $fails.' FAILED' : 'all passed')."\n";
exit($fails ? 1 : 0);
