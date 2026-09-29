<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Util;

/**
 * The insured total and money formatting shared by every request format.
 *
 * "Suma asegurada total" is not typed by the client: it is always the sum of
 * the four amounts below. It is computed here, on the server, whatever the
 * form says (a viewer without scripts leaves the PDF total empty, and an
 * Excel total could have been overwritten), so the validated and printed
 * total can never disagree with its parts.
 */
final class SumaAsegurada {

  /**
   * Amounts that make up the total; each one may be empty (counts as 0).
   */
  public const COMPONENTS = ['valor_factura', 'gastos_fletes', 'gastos_incrementales', 'seguro_contenedor'];

  /**
   * An amount as typed ("150,000.50", "$ 1000"), or NULL if empty/not a number.
   */
  public static function amount(mixed $value): ?float {
    $clean = str_replace([',', '$', ' ', "\u{00A0}"], '', trim((string) $value));
    return $clean !== '' && is_numeric($clean) ? (float) $clean : NULL;
  }

  /**
   * Sum of the components, or NULL when none of them has an amount.
   */
  public static function total(array $row): ?float {
    $total = NULL;
    foreach (self::COMPONENTS as $field) {
      $amount = self::amount($row[$field] ?? '');
      if ($amount !== NULL) {
        $total = ($total ?? 0.0) + $amount;
      }
    }
    return $total === NULL ? NULL : round($total, 2);
  }

  /**
   * "USD" or "MXN" for the request's currency ("usd", "PESOS", …), or ''.
   */
  public static function currencyCode(string $currency): string {
    return match (mb_strtolower(trim($currency))) {
      'usd' => 'USD',
      'pesos', 'mxn' => 'MXN',
      default => '',
    };
  }

  /**
   * "$600,000.00 USD".
   */
  public static function money(float $amount, string $code = ''): string {
    return trim('$' . number_format($amount, 2) . ' ' . $code);
  }

}
