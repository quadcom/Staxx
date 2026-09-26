<?PHP
/* StaXX — serves one service's icon straight out of its stack folder.
 * Copyright 2026, StaXX contributors.
 *
 * GET only, and the only thing it ever answers with is bytes or a bare 404 —
 * never a body explaining the refusal, since the one thing worth telling an
 * attacker here is nothing. Reached as
 * /plugins/staxx/include/icon.php?stack=<name>&file=<icon file>&v=<mtime>.
 * `stack` is the same identity every other action on this page uses
 * (staxx_list_stacks()'s own 'name'); `file` is the bare picture inside that
 * stack's .staxx folder, nothing else; `v` only busts the browser's cache
 * when the picture changes and is never checked against anything.
 *
 * `path` is given instead of `file` for the merge wizard's file-tree hover
 * preview — a picture sitting loose anywhere else in the stack's own folder
 * tree, addressed relative to the stack's directory rather than to .staxx.
 *
 * What counts as safe to serve is decided once, in staxx_icon_serve_path()
 * and staxx_icon_serve_tree_path() (include/Icons.php), so this page and
 * its own test suite can never quietly disagree about it.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/staxx/include/Stacks.php';

function staxx_icon_serve_fail(): void {
  http_response_code(404);
  header('Content-Type: text/plain');
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') staxx_icon_serve_fail();

$stack = (string)($_GET['stack'] ?? '');
$real  = isset($_GET['path']) && $_GET['path'] !== ''
  ? staxx_icon_serve_tree_path($stack, (string)$_GET['path'])
  : staxx_icon_serve_path($stack, (string)($_GET['file'] ?? ''));
if ($real === '') staxx_icon_serve_fail();

$types = [
  'svg'  => 'image/svg+xml',
  'png'  => 'image/png',
  'webp' => 'image/webp',
  'jpg'  => 'image/jpeg',
  'jpeg' => 'image/jpeg',
  'gif'  => 'image/gif',
  'ico'  => 'image/x-icon',
];
$ext = strtolower((string)pathinfo($real, PATHINFO_EXTENSION));

header('Content-Type: '.$types[$ext]);
header('X-Content-Type-Options: nosniff');
// An SVG can carry a <script>; refusing to run one, or load anything else,
// is cheaper and more durable than trying to sanitise every way a script
// can be smuggled into one. Harmless for every other format, so it is sent
// for all of them rather than only for svg.
header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'');
// The URL itself changes (the ?v= mtime) whenever the file does, so the
// cached copy can be kept for as long as the browser likes.
header('Cache-Control: public, max-age=31536000, immutable');
header('Content-Length: '.(string)filesize($real));

readfile($real);
exit;
?>
