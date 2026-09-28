<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Service;

use Drupal\aseguramiento_automation\Exception\RequestFileException;
use Drupal\aseguramiento_automation\Pdf\AcroFormReader;
use Psr\Log\LoggerInterface;

/**
 * Extracts values from fillable PDF AcroForm fields.
 *
 * Reading the PDF structure is delegated to AcroFormReader (pure PHP; no
 * pdftk on the server).
 */
final class PdfFormParserService {

  public function __construct(private readonly LoggerInterface $logger) {
  }

  /**
   * Parses a fillable PDF and returns one normalized request row.
   *
   * Field names are the request keys (see scripts/build_pdf_form.php), so
   * the row is validated like an Excel one.
   */
  public function parse(string $real_path): array {
    if (!is_readable($real_path)) {
      throw new \RuntimeException('No fue posible leer el PDF rellenable.');
    }

    $fields = (new AcroFormReader())->read((string) file_get_contents($real_path));
    if ($fields === []) {
      $this->logger->warning('[Aseguramiento] El PDF no tiene campos rellenables. Archivo: @file', [
        '@file' => basename($real_path),
      ]);
      throw new RequestFileException('El PDF no tiene campos rellenables. Usa el formato de solicitud en PDF y llénalo con Adobe Acrobat Reader.');
    }

    $this->logger->info('[Aseguramiento] Extracción de datos del PDF completada correctamente. Campos detectados: @count.', [
      '@count' => count($fields),
    ]);

    return [$this->normalizeFields($fields) + ['_source' => 'pdf', '_file' => basename($real_path)]];
  }

  private function normalizeFields(array $fields): array {
    $normalized = [];
    foreach ($fields as $field => $value) {
      $key = $this->normalizeFieldName((string) $field);
      if ($key === '') {
        continue;
      }
      $normalized[$key] = is_bool($value) ? ($value ? 'si' : '') : trim((string) $value);
    }
    return $normalized;
  }

  private function normalizeFieldName(string $field): string {
    $field = mb_strtolower(trim($field));
    $field = strtr($field, [
      'á' => 'a',
      'é' => 'e',
      'í' => 'i',
      'ó' => 'o',
      'ú' => 'u',
      'ü' => 'u',
      'ñ' => 'n',
    ]);
    $field = preg_replace('/[^a-z0-9_]+/', '_', $field) ?: '';
    return trim($field, '_');
  }

}
