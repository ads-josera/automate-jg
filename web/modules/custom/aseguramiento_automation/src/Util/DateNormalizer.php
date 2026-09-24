<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Util;

/**
 * Converts request dates to ISO (Y-m-d) using the Mexican day/month order.
 *
 * Excel stores a typed date as a real date only when it matches the locale of
 * the computer; otherwise "05/09/2026" arrives as text. strtotime() reads
 * slashes as month/day (US), which silently turned 5 September into 9 May
 * and rejected any day above 12. The request template is dd/mm/yyyy, so
 * numeric text dates are always read as day/month/year here.
 *
 * Used by both validation and storage so they can never disagree.
 */
final class DateNormalizer {

  /**
   * Returns the date as Y-m-d, or NULL when empty or not a real date.
   */
  public static function toIso(mixed $value): ?string {
    if ($value instanceof \DateTimeInterface) {
      return $value->format('Y-m-d');
    }
    $value = trim((string) $value);
    if ($value === '') {
      return NULL;
    }

    // ISO first: the Excel parser already converts real Excel dates to it.
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T].*)?$/', $value, $m)) {
      return self::build((int) $m[1], (int) $m[2], (int) $m[3]);
    }

    // Numeric day/month/year with "/", "-" or "." separators.
    if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2}|\d{4})$#', $value, $m)) {
      $year = (int) $m[3];
      if (strlen($m[3]) === 2) {
        $year += 2000;
      }
      return self::build($year, (int) $m[2], (int) $m[1]);
    }

    // Anything else ("5-sep-2026", "September 5, 2026") has no day/month
    // ambiguity; let PHP parse it.
    $timestamp = strtotime($value);
    return $timestamp === FALSE ? NULL : date('Y-m-d', $timestamp);
  }

  private static function build(int $year, int $month, int $day): ?string {
    return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : NULL;
  }

}
