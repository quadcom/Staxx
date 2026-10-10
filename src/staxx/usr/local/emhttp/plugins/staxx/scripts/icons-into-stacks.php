<?PHP
/* StaXX — puts existing stacks' icons where every new one keeps its own:
 * inside that stack's .staxx folder.
 * Copyright 2026, StaXX contributors.
 *
 * Runs staxx_icons_into_stacks() (see include/Icons.php for what it moves
 * and why) and prints its report. The same pass also runs itself once,
 * automatically, the first time any page load reaches it — see
 * staxx_icons_into_stacks_auto() — so this script exists for the one thing
 * that needs a person to look first: a real run on a server that already
 * has stacks on it, reviewed as a dry run before anything is removed.
 *
 *   php /usr/local/emhttp/plugins/staxx/scripts/icons-into-stacks.php --dry-run
 *   php /usr/local/emhttp/plugins/staxx/scripts/icons-into-stacks.php
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */

if (php_sapi_name() !== 'cli') {
  fwrite(STDERR, "This script only runs from the command line.\n");
  exit(1);
}

require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';

$dryRun = in_array('--dry-run', $argv, true);

if (!staxx_store_reachable()) {
  fwrite(STDERR, "The data store cannot be reached — nothing to do.\n");
  exit(1);
}

$report = staxx_icons_into_stacks($dryRun);

echo $dryRun ? "Dry run — nothing was changed.\n\n" : "\n";

if (!$report['moved']) {
  echo "No service needed its icon moved.\n";
} else {
  foreach ($report['moved'] as $item) {
    echo ($dryRun ? 'would move ' : 'moved ').$item['stack'].' / '.$item['service']
       . ': '.$item['from'].' -> '.$item['to']."\n";
  }
}

if ($report['removed']) {
  echo "\n".($dryRun ? 'would remove ' : 'removed ').count($report['removed'])
     . " file(s) from the old shared icon folder".($dryRun ? '' : ', and the folder itself').".\n";
}

if ($report['errors']) {
  echo "\nNot moved:\n";
  foreach ($report['errors'] as $err) echo '  '.$err."\n";
}

exit($report['errors'] ? 1 : 0);
?>
