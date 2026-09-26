<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Util;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;

/**
 * Branded header and footer shared by the panel pages (dashboard,
 * constancias, detail, export, confirmations).
 *
 * One place for the logo, the navigation buttons and "Cerrar sesión", so the
 * pages cannot drift apart. The "gestor" role sees these pages without the
 * admin toolbar: the logout button here is its only way out.
 */
final class PageShell {

  /**
   * Navigation buttons, in display order: [label, route, primary].
   */
  private const LINKS = [
    'dashboard' => ['Panel', 'aseguramiento_automation.dashboard', FALSE],
    'constancias' => ['Constancias', 'entity.aseguramiento_constancia.collection', FALSE],
    'export' => ['Exportar', 'aseguramiento_automation.export', TRUE],
  ];

  /**
   * @param string[] $links
   *   Keys of self::LINKS to show; "Cerrar sesión" is always added.
   */
  public static function hero(string $kicker, string $title, array $links, bool $compact = TRUE): array {
    $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $actions = ['#type' => 'container', '#attributes' => ['class' => ['aseguramiento-dashboard-hero__actions']]];
    foreach ($links as $key) {
      [$label, $route, $primary] = self::LINKS[$key];
      $actions[$key] = [
        '#type' => 'link',
        // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
        '#title' => new TranslatableMarkup($label),
        '#url' => Url::fromRoute($route),
        '#attributes' => ['class' => $primary ? ['aseguramiento-action-button', 'aseguramiento-action-button--primary'] : ['aseguramiento-action-button']],
      ];
    }
    $actions['logout'] = [
      '#type' => 'link',
      '#title' => new TranslatableMarkup('Cerrar sesión'),
      '#url' => Url::fromRoute('user.logout'),
      '#attributes' => ['class' => ['aseguramiento-action-button', 'aseguramiento-action-button--logout']],
    ];
    return [
      '#type' => 'container',
      '#attributes' => ['class' => $compact ? ['aseguramiento-dashboard-hero', 'aseguramiento-dashboard-hero--compact'] : ['aseguramiento-dashboard-hero']],
      'brand' => [
        '#markup' => '<div class="aseguramiento-dashboard-hero__brand"><img src="/modules/custom/aseguramiento_automation/assets/login/logo-jg-white.svg" alt="JG Mylard"><div><span>' . $e($kicker) . '</span><strong>' . $e($title) . '</strong></div></div>',
      ],
      'actions' => $actions,
    ];
  }

  public static function footer(): array {
    return ['#markup' => '<footer class="aseguramiento-powered-footer">Powered by Josera MKT</footer>'];
  }

}
