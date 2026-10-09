<?PHP
/* StaXX — Unraid's own labels on every StaXX container (PLAN_236).
 * Copyright 2026, StaXX contributors.
 *
 * Unraid's Docker page and Dashboard read `net.unraid.docker.*` labels off a
 * running container: the icon, the WebUI link and who manages it. StaXX works
 * those out from the compose file each time it starts something and hands
 * them to Compose as one extra file, written to RAM and passed as the LAST
 * `-f`. Nothing is ever written into the author's compose file, so the file
 * still runs unchanged under plain `docker compose up`.
 *
 * Labels must never stop a stack starting: every failure here returns '' and
 * the caller carries on with the files it already had.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/staxx/include/Defines.php';

// Redefinable before this file loads, so a server suite can point them at /tmp.
// Above the guard below: PHP declares this file's functions before running
// any of it, so the guard is already true on the first load and would skip them.
if (!defined('STAXX_LABELS_DIR'))       define('STAXX_LABELS_DIR', '/tmp/staxx-labels');
// Unraid keeps a downloaded icon here (kept) and a copy in RAM (lost at reboot).
if (!defined('STAXX_UNRAID_ICON_DISK')) define('STAXX_UNRAID_ICON_DISK', '/var/lib/docker/unraid/images');
if (!defined('STAXX_UNRAID_ICON_RAM'))  define('STAXX_UNRAID_ICON_RAM', '/usr/local/emhttp/state/plugins/dynamix.docker.manager/images');

if (function_exists('staxx_labels_file')) return;

/**
 * What each service's labels are, worked out from the compose files.
 *
 * Only values that exist are given. `managed` is always 'composeman', never
 * 'dockerman': that value means Unraid's own templates (Images.php relies on it).
 *
 * @return array<string, array{labels:array<string,string>, src:string, container:string}>
 *   src is the address or file the icon comes from, kept so an SVG can be drawn.
 */
function staxx_labels_build(string $main): array {
  $meta = staxx_compose_meta($main);
  if (!$meta['ok'] || !$meta['services']) return [];

  $dir     = dirname($main);
  $project = staxx_stack_project_guess($main, basename($dir));
  $out     = [];

  foreach ($meta['services'] as $svc => $m) {
    $svc    = (string)$svc;
    $labels = ['net.unraid.docker.managed' => 'composeman'];

    $webui = trim((string)($m['x']['webui'] ?? ''));
    if ($webui !== '') $labels['net.unraid.docker.webui'] = $webui;

    // A picture in the stack's own .staxx folder is named by path, not looked up
    // through the page's serving address, so it works wherever the store is.
    $icon = trim((string)($m['x']['icon'] ?? ''));
    $src  = '';
    if (preg_match('#^(?:\./)?'.preg_quote(STAXX_RECORD_DIR, '#').'/([^/]+)$#', $icon, $f)) {
      $path = $dir.'/'.STAXX_RECORD_DIR.'/'.$f[1];
      $ext  = strtolower((string)pathinfo($f[1], PATHINFO_EXTENSION));
      if ($f[1][0] !== '.' && in_array($ext, STAXX_ICON_EXTS, true) && is_file($path) && !is_link($path)) {
        $src = $path;
        $labels['net.unraid.docker.icon'] = 'file://'.$path;
      }
    } else {
      $r = $icon !== '' ? staxx_icon_resolve($icon, $dir)
                        : staxx_icon_resolve('', $dir, trim((string)($m['image'] ?? '')), $svc, basename($dir));
      if (preg_match('#^https?://#i', $r['url'])) {
        $src = $r['url'];
        $labels['net.unraid.docker.icon'] = $src;
      }
    }

    $named = trim((string)($m['container_name'] ?? ''));
    $out[$svc] = ['labels' => $labels, 'src' => $src,
                  'container' => $named !== '' ? $named : $project.'-'.$svc.'-1'];
  }
  return $out;
}

/**
 * Unraid stores whatever it fetches as <container>-icon.png and never fetches
 * again while a copy exists. So before a start: an SVG is drawn to a PNG and
 * put straight into the kept copy; any other icon has both exact files removed
 * so Unraid fetches the current one. Always done, not only on a change: the
 * running container's old label is not read here, and a refetch is cheap.
 * Exact paths from a charset-checked name, never a glob.
 */
function staxx_labels_refresh_icons(array $built): void {
  foreach ($built as $d) {
    $name = $d['container'];
    if (!isset($d['labels']['net.unraid.docker.icon']) || !preg_match('/^[A-Za-z0-9_.-]+$/', $name)) continue;

    $disk  = STAXX_UNRAID_ICON_DISK.'/'.$name.'-icon.png';
    $ram   = STAXX_UNRAID_ICON_RAM.'/'.$name.'-icon.png';
    $wrote = false;

    if (strtolower((string)pathinfo((string)parse_url($d['src'], PHP_URL_PATH), PATHINFO_EXTENSION)) === 'svg') {
      // staxx_notify_draw_svg() takes a file's path, so an address is saved to a
      // temporary file first.
      $file = $d['src'];
      $tmp  = '';
      if (preg_match('#^https?://#i', $file)) {
        $body = staxx_icon_get($file);
        $tmp  = is_string($body) && $body !== '' ? (string)@tempnam(sys_get_temp_dir(), 'staxx-lbl') : '';
        $file = ($tmp !== '' && @file_put_contents($tmp, $body) !== false) ? $tmp : '';
      }
      if ($file !== '') {
        if (!function_exists('staxx_notify_draw_svg')) require_once __DIR__.'/Notify.php';
        $png = staxx_notify_draw_svg($file);
        if ($png !== '' && (is_dir(STAXX_UNRAID_ICON_DISK) || @mkdir(STAXX_UNRAID_ICON_DISK, 0755, true))) {
          $wrote = @file_put_contents($disk, $png) !== false;
        }
      }
      if ($tmp !== '') @unlink($tmp);
    }
    if (!$wrote) @unlink($disk);
    @unlink($ram);
  }
}

/**
 * Write the labels file for a stack and return its path, or '' when there is
 * nothing to write or it cannot be written. $prepare is set only by callers
 * that are about to create containers: it also refreshes Unraid's icon copies.
 * Read-only callers (the restart check) leave it off so a page render never
 * touches Unraid's files or the network.
 */
function staxx_labels_file(string $main, bool $prepare = false): string {
  if ($main === '') return '';
  $built = staxx_labels_build($main);
  if ($built === []) return '';

  // A JSON string is a valid YAML double-quoted string. Values are the
  // compose file's own raw text, '$$' escapes included, so Compose reads them
  // here exactly as it reads them in the stack's own file.
  $q = function (string $s): string {
    return (string)json_encode($s, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  };
  $yaml = "services:\n";
  foreach ($built as $svc => $d) {
    $yaml .= '  '.json_encode((string)$svc).":\n    labels:\n";
    foreach ($d['labels'] as $k => $v) {
      $val = $q($v);
      if ($val !== '') $yaml .= '      '.json_encode($k).': '.$val."\n";   // '' = would not encode (bad UTF-8): left out
    }
  }

  $path = STAXX_LABELS_DIR.'/'.md5($main).'.yaml';
  if ((string)@file_get_contents($path) !== $yaml) {
    if (!is_dir(STAXX_LABELS_DIR) && !@mkdir(STAXX_LABELS_DIR, 0755, true) && !is_dir(STAXX_LABELS_DIR)) return '';
    if (!staxx_atomic_write($path, $yaml)) return '';
  }
  if ($prepare) staxx_labels_refresh_icons($built);
  return $path;
}

/**
 * The compose files to run a command that CREATES containers with: the stack's
 * own files, then the labels file last. Just the stack's files when there is
 * no labels file, which is exactly what ran before.
 *
 * @return string[]
 */
function staxx_run_files(string $main): array {
  $files = staxx_compose_files($main);
  $l = staxx_labels_file($main, true);
  if ($l !== '') $files[] = $l;
  return $files;
}
