<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Allowed range of the insured total per currency (settings, editable).
 *
 * The server validation, the Excel form and the PDF form all read the
 * limits from here, so they cannot contradict each other. Limits are
 * inclusive: exactly the minimum or the maximum is accepted.
 */
final class AmountLimits {

  public function __construct(private readonly ConfigFactoryInterface $configFactory) {
  }

  /**
   * @return array{min: float, max: float}|null
   *   NULL when no limits are configured for the currency.
   */
  public function forCurrency(string $code): ?array {
    $key = match ($code) {
      'USD' => 'usd',
      'MXN' => 'mxn',
      default => '',
    };
    if ($key === '') {
      return NULL;
    }
    $config = $this->configFactory->get('aseguramiento_automation.settings');
    $min = $config->get("amount_limits.{$key}_min");
    $max = $config->get("amount_limits.{$key}_max");
    if (!is_numeric($min) || !is_numeric($max)) {
      return NULL;
    }
    return ['min' => (float) $min, 'max' => (float) $max];
  }

  /**
   * Limits of every currency, keyed by code ("USD", "MXN").
   *
   * @return array<string, array{min: float, max: float}>
   */
  public function all(): array {
    return array_filter(['USD' => $this->forCurrency('USD'), 'MXN' => $this->forCurrency('MXN')]);
  }

}
