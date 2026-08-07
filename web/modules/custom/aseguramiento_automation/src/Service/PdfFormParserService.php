<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Service;

use Psr\Log\LoggerInterface;

/**
 * Extracts values from fillable PDF AcroForm fields.
 */
final class PdfFormParserService {

  public function __construct(private readonly LoggerInterface $logger) {
  }

  /**
   * Parses a fillable PDF and returns one normalized request row.
   */
  public function parse(string $real_path): array {
    if (!is_readable($real_path)) {
      throw new \RuntimeException('No fue posible leer el PDF rellenable.');
    }

    $fields = $this->parseWithPdftk($real_path);
    if ($fields === []) {
      $fields = $this->parseRawAcroForm($real_path);
    }

    if ($fields === []) {
      $this->logger->warning('[Aseguramiento] No fue posible extraer información del PDF. Archivo: @file', [
        '@file' => basename($real_path),
      ]);
      throw new \RuntimeException('No fue posible extraer campos rellenables del PDF. Verifica que sea un PDF AcroForm y que esté guardado con valores.');
    }

    $this->logger->info('[Aseguramiento] Extracción de datos del PDF completada correctamente. Campos detectados: @count.', [
      '@count' => count($fields),
    ]);

    return [$this->normalizeFields($fields) + ['_source' => 'pdf', '_file' => basename($real_path)]];
  }

  /**
   * Uses pdftk when available because it is the most reliable option.
   */
  private function parseWithPdftk(string $real_path): array {
    if (!function_exists('shell_exec')) {
      return [];
    }
    $binary = trim((string) shell_exec('command -v pdftk 2>/dev/null'));
    if ($binary === '') {
      return [];
    }

    $output = shell_exec(escapeshellcmd($binary) . ' ' . escapeshellarg($real_path) . ' dump_data_fields_utf8 2>/dev/null');
    if (!is_string($output) || trim($output) === '') {
      return [];
    }

    $fields = [];
    $current = [];
    foreach (preg_split('/\R/', $output) ?: [] as $line) {
      if (trim($line) === '---') {
        $this->addPdftkField($fields, $current);
        $current = [];
        continue;
      }
      if (str_contains($line, ':')) {
        [$key, $value] = explode(':', $line, 2);
        $current[trim($key)] = trim($value);
      }
    }
    $this->addPdftkField($fields, $current);
    return $fields;
  }

  private function addPdftkField(array &$fields, array $current): void {
    $name = (string) ($current['FieldName'] ?? '');
    if ($name === '') {
      return;
    }
    $fields[$name] = (string) ($current['FieldValue'] ?? '');
  }

  /**
   * Fallback reader for common uncompressed AcroForm dictionaries.
   */
  private function parseRawAcroForm(string $real_path): array {
    $content = (string) file_get_contents($real_path);
    $content .= "\n" . $this->inflatedStreams($content);
    $fields = [];

    if (preg_match_all('/\/T\s*(\((?:\\\\.|[^\\\\)])*\)|<[0-9A-Fa-f]+>).*?\/V\s*(\((?:\\\\.|[^\\\\)])*\)|<[0-9A-Fa-f]+>|\/[^\s<>\\[\\]\\/]+)/s', $content, $matches, PREG_SET_ORDER)) {
      foreach ($matches as $match) {
        $name = $this->decodePdfToken($match[1]);
        $value = $this->decodePdfToken($match[2]);
        if ($name !== '') {
          $fields[$name] = $value;
        }
      }
    }
    if (preg_match_all('/<<[^\r\n]*\/T\s*(\((?:\\\\.|[^\\\\)])*\)|<[0-9A-Fa-f]+>)[^\r\n]*>>/', $content, $matches, PREG_SET_ORDER)) {
      foreach ($matches as $match) {
        $name = $this->decodePdfToken($match[1]);
        if ($name === '' || isset($fields[$name])) {
          continue;
        }
        $value = '';
        if (preg_match('/\/V\s*(\((?:\\\\.|[^\\\\)])*\)|<[0-9A-Fa-f]+>|\/[^\s<>\\[\\]\\/]+)/', $match[0], $value_match)) {
          $value = $this->decodePdfToken($value_match[1]);
        }
        $fields[$name] = $value;
      }
    }

    return $fields;
  }

  private function inflatedStreams(string $content): string {
    $inflated = '';
    if (!preg_match_all('/<<(?:.|\R)*?\/Filter\s*\/FlateDecode(?:.|\R)*?>>\s*stream\R?(.*?)\R?endstream/s', $content, $matches)) {
      return $inflated;
    }
    foreach ($matches[1] as $stream) {
      $decoded = @gzuncompress($stream);
      if ($decoded === FALSE) {
        $decoded = @gzdecode($stream);
      }
      if ($decoded !== FALSE) {
        $inflated .= "\n" . $decoded;
      }
    }
    return $inflated;
  }

  private function decodePdfToken(string $token): string {
    $token = trim($token);
    if ($token === '') {
      return '';
    }
    if ($token[0] === '/') {
      return urldecode(substr($token, 1));
    }
    if ($token[0] === '<' && str_ends_with($token, '>')) {
      $hex = substr($token, 1, -1);
      $binary = @hex2bin(strlen($hex) % 2 === 0 ? $hex : '0' . $hex);
      if ($binary === FALSE) {
        return '';
      }
      if (str_starts_with($binary, "\xFE\xFF")) {
        $converted = @mb_convert_encoding(substr($binary, 2), 'UTF-8', 'UTF-16BE');
        return is_string($converted) ? trim($converted) : '';
      }
      return trim($binary);
    }
    if ($token[0] === '(' && str_ends_with($token, ')')) {
      return trim(strtr(substr($token, 1, -1), [
        '\\(' => '(',
        '\\)' => ')',
        '\\\\' => '\\',
        '\\n' => "\n",
        '\\r' => "\r",
        '\\t' => "\t",
      ]));
    }
    return trim($token);
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
