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

  private const SPANISH_MONTHS = [
    'enero' => 1, 'ene' => 1,
    'febrero' => 2, 'feb' => 2,
    'marzo' => 3, 'mar' => 3,
    'abril' => 4, 'abr' => 4,
    'mayo' => 5, 'may' => 5,
    'junio' => 6, 'jun' => 6,
    'julio' => 7, 'jul' => 7,
    'agosto' => 8, 'ago' => 8,
    'septiembre' => 9, 'setiembre' => 9, 'sep' => 9, 'sept' => 9, 'set' => 9,
    'octubre' => 10, 'oct' => 10,
    'noviembre' => 11, 'nov' => 11,
    'diciembre' => 12, 'dic' => 12,
  ];

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

    // Spanish month names, as clients type them: "29-octubre-26",
    // "30 de octubre de 2026", "1-ene-2027" (strtotime() only knows English).
    if (preg_match('/^(\d{1,2})(?:\s+de)?[\s\/.\-]+(\p{L}+)\.?(?:\s+(?:de|del))?[\s\/.\-]+(\d{2}|\d{4})$/iu', $value, $m)) {
      $month = self::SPANISH_MONTHS[mb_strtolower($m[2])] ?? NULL;
      if ($month !== NULL) {
        $year = (int) $m[3] + (strlen($m[3]) === 2 ? 2000 : 0);
        return self::build($year, $month, (int) $m[1]);
      }
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
