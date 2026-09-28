<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Service;

/**
 * Sanitizes and formats PDF overlay mappings.
 */
final class CoordinateMappingService {

  public function normalizeMappings(array $mappings): array {
    $normalized = [];
    foreach ($mappings as $mapping) {
      $field = preg_replace('/[^a-zA-Z0-9_]+/', '', (string) ($mapping['field'] ?? ''));
      if ($field === '') {
        continue;
      }
      $normalized[] = [
        'field' => $field,
        'page' => max(1, (int) ($mapping['page'] ?? 1)),
        'x' => max(0, (float) ($mapping['x'] ?? 0)),
        'y' => max(0, (float) ($mapping['y'] ?? 0)),
        'font' => preg_replace('/[^a-zA-Z0-9_-]+/', '', (string) ($mapping['font'] ?? 'helvetica')) ?: 'helvetica',
        'size' => max(4, min(72, (float) ($mapping['size'] ?? 10))),
        'color' => preg_match('/^#[0-9a-f]{6}$/i', (string) ($mapping['color'] ?? '')) ? $mapping['color'] : '#000000',
        'align' => in_array(($mapping['align'] ?? 'L'), ['L', 'C', 'R', 'J'], TRUE) ? $mapping['align'] : 'L',
        'width' => max(0, (float) ($mapping['width'] ?? 0)),
        'multiline' => !empty($mapping['multiline']),
        'format' => trim((string) ($mapping['format'] ?? '')),
      ];
    }
    return $normalized;
  }

  private static function currencyCode(string $currency): string {
    return match (mb_strtolower(trim($currency))) {
      'usd' => 'USD',
      'pesos', 'mxn' => 'MXN',
      default => '',
    };
  }

  public function valueFor(array $mapping, array $data): string {
    $value = (string) ($data[$mapping['field']] ?? '');
    return match ($mapping['format'] ?? '') {
      // "$165,000.00 USD": in an insurance document "$" alone is ambiguous.
      'currency' => is_numeric(str_replace([',', '$'], '', $value))
        ? trim('$' . number_format((float) str_replace([',', '$'], '', $value), 2) . ' ' . self::currencyCode((string) ($data['moneda'] ?? '')))
        : $value,
      'date' => strtotime($value) ? date('d/m/Y', strtotime($value)) : $value,
      'uppercase' => mb_strtoupper($value),
      default => $value,
    };
  }

}

