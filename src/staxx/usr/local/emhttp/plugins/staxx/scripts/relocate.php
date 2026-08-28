<?PHP
/* StaXX — moves the whole data store to a new location.
 * Copyright 2026, StaXX contributors.
 *
 * Launched detached by staxx_relocate_start() (see include/Relocate.php),
 * never by a page render: copying somebody's whole data store — stacks,
 * archives and config alike — can take a long time, and a web request that
 * waited for it would simply time out. Can also be run by hand on the
 * server —
 *   php /usr/local/emhttp/plugins/staxx/scripts/relocate.php /mnt/user/appdata/staxx
 * — which is the easiest way to see what a run actually does; every step,
 * and every so often the progress through the copy and verify passes,
 * prints one line.
 *
 * Re-validates its own argument from scratch (staxx_relocate_run() calls
 * staxx_relocate_refuse() itself) rather than trusting that it arrived
 * unchanged: this runs as root, and the argument is just a string another
 * process built.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */

if (php_sapi_name() !== 'cli') {
  fwrite(STDERR, "This script only runs from the command line.\n");
  exit(1);
}

require_once '/usr/local/emhttp/plugins/staxx/include/Relocate.php';

$dest = $argv[1] ?? '';
if ($dest === '') {
  echo "No new location was given, so nothing was moved.\n";
  exit(1);
}

$error = '';
$ok = staxx_relocate_run($dest, function (string $line): void { echo $line."\n"; }, $error);

exit($ok ? 0 : 1);
?>
