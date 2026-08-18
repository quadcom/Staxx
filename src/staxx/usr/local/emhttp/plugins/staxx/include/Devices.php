<?PHP
/* StaXX — what hardware this server can hand to a container.
 * Copyright 2026, StaXX contributors.
 *
 * A device mapping is the one setting in a compose file that cannot be guessed
 * from the file itself: /dev/dri means nothing until you know the machine has an
 * Intel card in it, and a USB stick answers to a different name after it is
 * replugged. So this file asks the machine, and names what it finds.
 *
 * Nothing here runs an external command. /sys and /dev already hold every answer
 * — the vendor of each graphics card, the make and model of each USB serial
 * device — and shelling out to lsusb or nvidia-smi to be told the same thing
 * would only add a command that can hang.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';
require_once '/usr/local/emhttp/plugins/staxx/include/Stats.php';

if (defined('STAXX_SERIAL_DIR')) return;

// Where the kernel keeps a stable name for every USB serial device. This is the
// most useful directory on the machine for our purposes: the filename carries
// the make and model, and it survives a reboot or a replug, which /dev/ttyUSB0
// does not.
define('STAXX_SERIAL_DIR', '/dev/serial/by-id');

/* ------------------------------------------------------- naming what we find -- */

/** Vendor id from staxx_gpu_nodes() to something a person would say. */
const STAXX_GPU_LABELS = [
  'intel'  => 'Intel graphics',
  'amd'    => 'AMD graphics',
  'nvidia' => 'NVIDIA graphics',
];

/**
 * A readable name for a /dev/serial/by-id filename.
 *
 *   usb-Silicon_Labs_slae.sh_cc2652rb_stick_00_12_4B_00_24_C2_9A_2F-if00-port0
 *   ->  Silicon Labs slae.sh cc2652rb stick
 *
 * The interface number and the hex serial are dropped because they identify
 * which socket the thing is in, not what it is, and they are the two halves of
 * the name that make it unreadable. Everything else is left alone — guessing
 * harder would mean cutting words out of names we have not seen.
 */
function staxx_device_pretty(string $file): string {
  $name = preg_replace('/^(usb|pci|platform)-/', '', $file);
  $name = preg_replace('/-if[0-9a-f]+(-port\d+)?$/i', '', $name);
  $name = str_replace('_', ' ', $name);

  // A trailing MAC-style or hex serial, as four or more pairs.
  $name = preg_replace('/(\s+[0-9A-Fa-f]{2}){4,}$/', '', $name);

  $name = trim(preg_replace('/\s+/', ' ', $name));
  return $name === '' ? $file : $name;
}

/**
 * The devices this server has, grouped, in the order they are worth offering.
 *
 * Everything is returned, including the entries marked `risky` — whole disks and
 * the entire USB bus. The picker keeps those behind a "show everything" link
 * rather than in the ordinary list, but it needs them regardless: a file that
 * already maps /dev/sdb has a row to name, and a catalogue that had left it out
 * would report the disk as hardware this server does not have.
 *
 * @return array<int, array{key:string, title:string, note:string,
 *                          devices:array<int, array{host:string, container:string,
 *                            label:string, hint:string, risky:bool,
 *                            companions:array<int,array{key:string,value:string,why:string}>}>}>
 */
function staxx_device_catalogue(): array {
  static $cached = null;
  if ($cached !== null) return $cached;

  $groups = [];

  /* ------------------------------------------------------------- graphics -- */

  $nodes   = staxx_gpu_nodes();
  $render  = [];
  $vendors = [];
  foreach ($nodes as $node => $vendor) {
    // card0 is the display side of the same card; renderD128 is the one that
    // does the work a container wants. Offering both would be two entries that
    // look like two cards.
    if (strncmp($node, 'renderD', 7) !== 0) continue;
    if (!file_exists('/dev/dri/'.$node)) continue;
    $render[$node]    = $vendor;
    $vendors[$vendor] = true;
  }

  $gfx = [];
  if ($render) {
    $named = [];
    foreach (array_keys($vendors) as $v) $named[] = STAXX_GPU_LABELS[$v] ?? $v;

    $gfx[] = [
      'host'       => '/dev/dri',
      'container'  => '/dev/dri',
      'label'      => count($named) === 1 ? $named[0] : 'All graphics cards',
      'hint'       => 'Hardware video transcoding. Found: '.implode(', ', $named)
                    . '. Most apps expect the whole folder, so start here.',
      'risky'      => false,
      'companions' => [],
    ];

    // Listed even on a machine with one card. Someone who has already written
    // /dev/dri/renderD128 into a file has a row that deserves a name, and giving
    // one container a specific card is a real thing to want.
    foreach ($render as $node => $vendor) {
      $gfx[] = [
        'host'       => '/dev/dri/'.$node,
        'container'  => '/dev/dri/'.$node,
        'label'      => (STAXX_GPU_LABELS[$vendor] ?? $vendor).' only ('.$node.')',
        'hint'       => 'This one card and nothing else.',
        'risky'      => false,
        'companions' => [],
      ];
    }
  }

  // Nvidia is deliberately not offered as a device mapping, because it is not
  // one — the container toolkit hands the card over through the runtime, and a
  // /dev/nvidia0 mapping on its own produces a container that starts and then
  // cannot see the card.
  $note = '';
  if (@glob('/dev/nvidia[0-9]*')) {
    $note = 'An NVIDIA card was found. It is not added as a device: it needs '
          . '"runtime: nvidia" instead, which you can add in the Compose view.';
  }

  if ($gfx || $note !== '') {
    $groups[] = ['key' => 'graphics', 'title' => 'Graphics', 'note' => $note, 'devices' => $gfx];
  }

  /* ------------------------------------------------- USB serial, by name -- */

  $serial = [];
  $seen   = [];
  foreach ((array)@glob(STAXX_SERIAL_DIR.'/*') as $link) {
    $short = (string)@realpath($link);
    if ($short === '') continue;
    $seen[$short] = true;

    $serial[] = [
      'host'      => $link,
      // The app inside the container is nearly always configured to look for the
      // short name, so that is what it gets to see. The stable path stays on the
      // host side, where the replug problem actually lives.
      'container' => $short,
      'label'     => staxx_device_pretty(basename($link)),
      'hint'      => 'Keeps working after a reboot or a replug. Appears inside the '
                   . 'container as '.$short.'.',
      'risky'     => false,
      'companions' => [],
    ];
  }
  if ($serial) {
    $groups[] = [
      'key' => 'serial', 'title' => 'USB devices', 'note' => '', 'devices' => $serial,
    ];
  }

  /* ------------------------------------- serial ports with no stable name -- */

  $plain = [];
  foreach (['/dev/ttyUSB*', '/dev/ttyACM*'] as $pattern) {
    foreach ((array)@glob($pattern) as $path) {
      if (isset($seen[$path])) continue;          // already offered by its real name
      $plain[] = [
        'host'      => $path,
        'container' => $path,
        'label'     => basename($path),
        'hint'      => 'This name can change when the device is replugged or the '
                     . 'server reboots.',
        'risky'     => false,
        'companions' => [],
      ];
    }
  }
  if ($plain) {
    $groups[] = [
      'key'   => 'plain',
      'title' => 'Other serial ports',
      'note'  => 'These have no stable name, so prefer one from the list above where '
               . 'the same device appears there.',
      'devices' => $plain,
    ];
  }

  /* ---------------------------------------------------------- accelerators -- */

  $accel = [];
  foreach ((array)@glob('/dev/apex_[0-9]*') as $path) {
    $accel[] = [
      'host' => $path, 'container' => $path,
      'label' => 'Coral TPU ('.basename($path).')',
      'hint'  => 'A Google Coral accelerator, for object detection.',
      'risky' => false, 'companions' => [],
    ];
  }
  if (file_exists('/dev/kfd')) {
    $accel[] = [
      'host' => '/dev/kfd', 'container' => '/dev/kfd',
      'label' => 'AMD compute (ROCm)',
      'hint'  => 'Machine learning on an AMD card. Needs /dev/dri as well.',
      'risky' => false, 'companions' => [],
    ];
  }
  if ($accel) {
    $groups[] = ['key' => 'accel', 'title' => 'Accelerators', 'note' => '', 'devices' => $accel];
  }

  /* ---------------------------------------------------------------- system -- */

  $system = [];
  if (file_exists('/dev/net/tun')) {
    $system[] = [
      'host' => '/dev/net/tun', 'container' => '/dev/net/tun',
      'label' => 'VPN tunnel',
      'hint'  => 'Needed by anything that makes its own VPN connection.',
      'risky' => false,
      'companions' => [[
        'key' => 'cap_add', 'value' => 'NET_ADMIN',
        'why' => 'A tunnel is useless without permission to configure the network.',
      ]],
    ];
  }
  if (file_exists('/dev/fuse')) {
    $system[] = [
      'host' => '/dev/fuse', 'container' => '/dev/fuse',
      'label' => 'Mount filesystems inside the container',
      'hint'  => 'Used by rclone, mergerfs and similar.',
      'risky' => false,
      'companions' => [
        ['key' => 'cap_add', 'value' => 'SYS_ADMIN',
         'why' => 'Mounting anything requires it.'],
        ['key' => 'security_opt', 'value' => 'apparmor:unconfined',
         'why' => 'AppArmor blocks the mount without this.'],
      ],
    ];
  }
  if (is_dir('/dev/snd')) {
    $system[] = [
      'host' => '/dev/snd', 'container' => '/dev/snd',
      'label' => 'Sound cards',
      'hint'  => 'For a container that plays or records audio.',
      'risky' => false, 'companions' => [],
    ];
  }
  if ($system) {
    $groups[] = ['key' => 'system', 'title' => 'System', 'note' => '', 'devices' => $system];
  }

  /* ------------------------------------------------------- everything else -- */

  $rest = [];

  if (is_dir('/dev/bus/usb')) {
    $rest[] = [
      'host' => '/dev/bus/usb', 'container' => '/dev/bus/usb',
      'label' => 'Every USB device on the server',
      'hint'  => 'Hands over the whole USB bus, including anything plugged in later.',
      'risky' => true, 'companions' => [],
    ];
  }

  // Whole disks only. A partition is never the right answer here, and listing
  // every one of them would bury the disks themselves.
  foreach (['#^/dev/sd[a-z]+$#' => '/dev/sd*', '#^/dev/nvme\d+n\d+$#' => '/dev/nvme*n*'] as $shape => $pattern) {
    foreach ((array)@glob($pattern) as $path) {
      if (!preg_match($shape, $path)) continue;
      $rest[] = [
        'host' => $path, 'container' => $path,
        'label' => 'Disk '.basename($path),
        'hint'  => 'The whole disk. A container given this can overwrite it.',
        'risky' => true, 'companions' => [],
      ];
    }
  }

  // Every Linux box reports 32 of these and has at most one or two, so they are
  // down here rather than beside the USB serial devices that do exist.
  foreach ((array)@glob('/dev/ttyS*') as $path) {
    if (!preg_match('#^/dev/ttyS\d+$#', $path)) continue;
    $rest[] = [
      'host' => $path, 'container' => $path,
      'label' => 'Built-in serial port '.basename($path),
      'hint'  => 'Most of these are reported by the kernel but not physically present.',
      'risky' => true, 'companions' => [],
    ];
  }

  if ($rest) {
    $groups[] = [
      'key'   => 'rest',
      'title' => 'Everything else',
      'note'  => 'Handing any of these to a container gives it far more than one '
               . 'piece of hardware. Only pick one if you know why you need it.',
      'devices' => $rest,
    ];
  }

  return $cached = $groups;
}

/* ------------------------------------------------------------- who has what -- */

/**
 * The host path out of one short-form device entry, or '' if it is not one.
 *
 * Long-form entries (`- source: ... target: ...`) return '' and are simply not
 * counted. They are rare, and a claim badge that is missing is a smaller problem
 * than one that is wrong.
 */
function staxx_device_host(string $item): string {
  $item = trim($item);

  if ($item !== '' && ($item[0] === '"' || $item[0] === "'")) {
    $end = strpos($item, $item[0], 1);
    $item = $end === false ? substr($item, 1) : substr($item, 1, $end - 1);
  } else {
    // An unquoted trailing comment. YAML needs whitespace before the '#', which
    // is what keeps this from cutting a path that contains one.
    $item = preg_replace('/\s+#.*$/', '', $item);
  }

  $at   = strpos($item, ':');
  $host = rtrim($at === false ? $item : substr($item, 0, $at), '/');
  return strncmp($host, '/dev/', 5) === 0 ? $host : '';
}

/**
 * Host device paths mapped by one compose file, keyed by service name.
 *
 * Its own indentation walk rather than staxx_yaml_flatten(), which discards
 * every sequence it meets — that is where device entries live, so it cannot
 * answer this. Widening it instead would change what staxx_compose_meta()
 * sees, for one caller's benefit.
 *
 * The raw file is read, not `docker compose config`, so a file that builds its
 * devices out of an anchor or an `extends` is not counted. This feeds an
 * advisory badge; 60ms of compose per stack every time the picker opens is not
 * worth paying for it.
 *
 * @return array<string, string[]>
 */
function staxx_compose_devices(string $yaml): array {
  $out     = [];
  $inSvc   = false;   // inside the services: block
  $svcAt   = -1;      // indent at which service names sit
  $service = '';      // the service currently open
  $keyAt   = -1;      // indent of that service's own keys
  $devAt   = -1;      // indent of that service's devices: key, or -1

  foreach (explode("\n", $yaml) as $raw) {
    $line = rtrim($raw, "\r");
    if (trim($line) === '' || preg_match('/^\s*#/', $line)) continue;

    $indent = strlen($line) - strlen(ltrim($line, ' '));
    $body   = ltrim($line, ' ');
    $isItem = strncmp($body, '- ', 2) === 0 || $body === '-';

    if ($isItem) {
      if ($devAt >= 0 && $indent > $devAt) {
        $host = staxx_device_host(substr($body, 1));
        if ($host !== '') $out[$service][] = $host;
      }
      continue;                                  // an item never opens a key
    }

    if (!preg_match('/^("[^"]*"|\'[^\']*\'|[^:#]+):/', $body, $m)) continue;
    $key = trim($m[1], " \"'");

    // Stepping back out to this level or further closes the devices: list.
    if ($devAt >= 0 && $indent <= $devAt) $devAt = -1;

    if (!$inSvc) {
      if ($indent === 0 && $key === 'services') $inSvc = true;
      continue;
    }
    if ($indent === 0) {                         // a new top-level key ends services:
      $inSvc = false; $svcAt = -1; $service = ''; $keyAt = -1; $devAt = -1;
      continue;
    }

    if ($svcAt < 0) $svcAt = $indent;            // the first key in there names a service
    if ($indent === $svcAt) { $service = $key; $keyAt = -1; $devAt = -1; continue; }
    if ($keyAt < 0) $keyAt = $indent;            // and the first key inside that service

    // A DIRECT child of the service, and nothing deeper. Compose has a second,
    // unrelated devices: key down at deploy.resources.reservations.devices,
    // which reserves a GPU and maps nothing — counting its entries as claims
    // would report hardware as taken by a stack that never asked for a path.
    if ($service !== '' && $key === 'devices' && $indent === $keyAt) $devAt = $indent;
  }

  return $out;
}

/**
 * Which stack and service has already claimed each device.
 *
 * Two containers holding one Zigbee stick is a setup that looks fine and never
 * works, so the picker says who has it. Advisory only — sharing /dev/dri between
 * two transcoders is perfectly reasonable.
 *
 * @return array<string, string[]> host path => ["Media/jellyfin/jellyfin", ...]
 */
function staxx_device_claims(): array {
  $out = [];

  foreach (staxx_scan_stacks()['stacks'] as $s) {
    $file = staxx_find_compose_file($s['dir']);
    if ($file === '') continue;

    $yaml = (string)@file_get_contents($file);
    if ($yaml === '') continue;

    foreach (staxx_compose_devices($yaml) as $service => $paths) {
      foreach (array_unique($paths) as $path) {
        $out[$path][] = $s['rel'].' / '.$service;
      }
    }
  }

  return $out;
}

/**
 * Every device path that exists on this server.
 *
 * Deliberately separate from the catalogue. That is a curated list of hardware
 * worth offering; this is the blunt question the form actually needs answered —
 * is this path here? A file written on another machine naming
 * /dev/bus/usb/001/004 is the usual reason a container will not start, and the
 * row should say so even though nothing would ever have suggested that path.
 *
 * Testing catalogue membership instead would report every specific USB node and
 * every disk-by-id path as missing hardware, which is worse than saying nothing.
 *
 * @return string[]
 */
function staxx_device_paths(): array {
  $out = [];

  // The directories come back from /dev/* in their own right, because handing
  // over a whole folder — /dev/dri, /dev/snd — is an ordinary mapping.
  foreach (['/dev/*', '/dev/dri/*', '/dev/net/*', '/dev/snd/*', '/dev/serial/by-id/*',
            '/dev/bus/*', '/dev/bus/usb/*', '/dev/bus/usb/*/*', '/dev/disk/by-id/*'] as $pattern) {
    foreach ((array)@glob($pattern) as $path) $out[] = $path;
  }

  // Everything the catalogue offers is present by construction — it was built by
  // looking at this machine. Folding those paths in is what stops the two lists
  // from ever disagreeing: /dev/bus/usb is a directory two levels down that no
  // /dev/* glob reaches, so the picker offered it while the form called it
  // missing hardware.
  foreach (staxx_device_catalogue() as $group) {
    foreach ($group['devices'] as $d) $out[] = $d['host'];
  }

  $out = array_values(array_unique($out));
  sort($out);
  return $out;
}

/** Everything the picker needs, in one reply. */
function staxx_devices(): array {
  return [
    'groups'  => staxx_device_catalogue(),
    'claims'  => staxx_device_claims(),
    'present' => staxx_device_paths(),
  ];
}
?>
