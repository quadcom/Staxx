<?PHP
/* Stack Manager — shared body for the Stacks view.
 * Copyright 2026, Stack Manager contributors.
 *
 * Included by both stack.manager.page (tab under the Docker menu) and
 * Stacks.page (own button in the top navigation bar). Exactly one of those is
 * enabled at a time, decided by the HEADER_MENU config key, so this file is
 * only ever rendered once per request.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 */
?>
<?
require_once '/usr/local/emhttp/plugins/stack.manager/include/Defines.php';

$composeBin     = stackman_compose_bin();
$composeVersion = stackman_compose_version();
$dockerRunning  = stackman_docker_running();
$projects       = stackman_containers_by_project();

$stackCount     = count(array_filter(array_keys($projects), fn($p) => $p !== ''));
$unmanagedCount = count($projects[''] ?? []);

function stackman_status_row(string $label, bool $ok, string $detail): void {
  $icon = $ok ? 'fa-check green-text' : 'fa-times-circle red-text';
  echo '<tr>';
  echo   '<td><i class="fa ', $icon, '"></i> ', htmlspecialchars($label), '</td>';
  echo   '<td>', htmlspecialchars($detail), '</td>';
  echo '</tr>';
}
?>

<div class="stackman-scaffold">

  <p class="notice">
    <?= _('Stack Manager is pre-alpha') ?> —
    <?= _('this page currently reports environment readiness only. No stack management is implemented yet.') ?>
  </p>

  <table class="stackman-status">
    <thead>
      <tr><th><?= _('Check') ?></th><th><?= _('Result') ?></th></tr>
    </thead>
    <tbody>
      <?
        stackman_status_row(
          _('WebGUI page integration'),
          true,
          _('This page rendered, so .page discovery and menu ranking work as designed.')
        );

        stackman_status_row(
          _('Docker service'),
          $dockerRunning,
          $dockerRunning ? _('Running') : _('Not running')
        );

        stackman_status_row(
          _('Compose CLI plugin'),
          $composeBin !== '',
          $composeBin !== ''
            ? sprintf(_('v%s at %s'), $composeVersion ?: '?', $composeBin)
            : _('Not installed — Unraid does not ship it; Stack Manager will install it.')
        );

        stackman_status_row(
          _('Compose project labels'),
          $stackCount > 0,
          $stackCount > 0
            ? sprintf(_('%d compose stack(s) detected via com.docker.compose.project'), $stackCount)
            : _('No compose-managed containers found yet.')
        );
      ?>
    </tbody>
  </table>

  <? if ($projects): ?>
    <h3><?= _('Containers by stack') ?></h3>
    <p class="stackman-hint">
      <?= _('Grouping comes from the com.docker.compose.project label — stacks group themselves, with no folders to configure.') ?>
    </p>

    <table class="stackman-projects">
      <? foreach ($projects as $project => $containers): ?>
        <tr>
          <td class="stackman-project">
            <? if ($project === ''): ?>
              <i class="fa fa-cube"></i> <em><?= _('Not compose-managed') ?></em>
            <? else: ?>
              <i class="fa fa-cubes"></i> <?= htmlspecialchars($project) ?>
            <? endif; ?>
          </td>
          <td><?= htmlspecialchars(implode(', ', $containers)) ?></td>
        </tr>
      <? endforeach; ?>
    </table>

    <? if ($unmanagedCount): ?>
      <p class="stackman-hint">
        <?= sprintf(
              _('%d container(s) carry no compose project label. These were created by Unraid templates or by hand, and are what an import path would need to adopt.'),
              $unmanagedCount
            ) ?>
      </p>
    <? endif; ?>
  <? endif; ?>

</div>
