<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Pdf;

use Drupal\aseguramiento_automation\Exception\RequestFileException;

/**
 * Reads the values of the form fields (AcroForm) of a PDF.
 *
 * Follows the file structure instead of searching text, because every tool
 * saves forms differently:
 * - incremental updates (Acrobat "Guardar"): each save appends a new
 *   cross-reference section; the newest one wins for every object;
 * - object streams and compressed cross-reference streams (newer writers):
 *   the field dictionaries are inside Flate-compressed streams;
 * - text strings in PDFDocEncoding, UTF-16BE (macOS Vista Previa) or UTF-8,
 *   with octal and parenthesis escapes.
 * Fields are walked from the catalog (/AcroForm /Fields, /Kids, /Parent), so
 * an empty field can never take the value of another one.
 *
 * Pure PHP, no external binaries. Encrypted PDFs are rejected.
 */
final class AcroFormReader {

  private string $data;

  /**
   * Object number => byte offset, or [object stream number, index].
   *
   * @var array<int, int|array{0: int, 1: int}>
   */
  private array $xref = [];

  private array $trailer = [];

  /**
   * @var array<int, mixed>
   */
  private array $cache = [];

  /**
   * Parsed object streams: stream object number => [object number => value].
   *
   * @var array<int, array<int, mixed>>
   */
  private array $objectStreams = [];

  /**
   * Field values keyed by fully qualified field name.
   *
   * @return array<string, string>
   */
  public function read(string $data): array {
    $this->data = $data;
    $this->xref = [];
    $this->trailer = [];
    $this->cache = [];
    $this->objectStreams = [];

    if (!str_starts_with(ltrim(substr($data, 0, 1024)), '%PDF')) {
      throw new RequestFileException('El archivo no es un PDF válido; guárdalo de nuevo como PDF y envíalo otra vez.');
    }
    if (!$this->loadCrossReferences()) {
      $this->scanObjects();
    }
    if (isset($this->trailer['Encrypt'])) {
      throw new RequestFileException('El PDF está protegido con contraseña; guárdalo sin protección y envíalo de nuevo.');
    }

    $root = $this->resolve($this->trailer['Root'] ?? NULL);
    $form = is_array($root) ? $this->resolve($root['AcroForm'] ?? NULL) : NULL;
    $fields = is_array($form) ? $this->resolve($form['Fields'] ?? NULL) : NULL;
    if (!is_array($fields) || !array_is_list($fields)) {
      return [];
    }
    $values = [];
    foreach ($fields as $field) {
      $this->walkField($field, '', NULL, $values, 0);
    }
    return $values;
  }

  /**
   * Collects the value of a field and its descendants.
   */
  private function walkField(mixed $node, string $parent_name, mixed $inherited_value, array &$values, int $depth): void {
    $dict = $this->resolve($node);
    if (!is_array($dict) || array_is_list($dict) || $depth > 20) {
      return;
    }
    $partial = isset($dict['T']) ? $this->text($this->resolve($dict['T'])) : NULL;
    $name = $partial === NULL ? $parent_name : ($parent_name === '' ? $partial : $parent_name . '.' . $partial);
    $value = array_key_exists('V', $dict) ? $this->resolve($dict['V']) : $inherited_value;

    $kids = $this->resolve($dict['Kids'] ?? NULL);
    $has_field_kids = FALSE;
    if (is_array($kids) && array_is_list($kids)) {
      foreach ($kids as $kid) {
        $kid_dict = $this->resolve($kid);
        // Kids with a name are fields; kids without one are its widgets.
        if (is_array($kid_dict) && isset($kid_dict['T'])) {
          $has_field_kids = TRUE;
          $this->walkField($kid, $name, $value, $values, $depth + 1);
        }
      }
    }
    if (!$has_field_kids && $name !== '') {
      $values[$name] = $this->valueToString($value);
    }
  }

  private function valueToString(mixed $value): string {
    if ($value instanceof PdfName) {
      // Checkboxes and some choice fields store a name; "Off" is unchecked.
      return $value->name === 'Off' ? '' : $value->name;
    }
    if ($value instanceof PdfString) {
      return trim($this->text($value));
    }
    if (is_array($value) && array_is_list($value)) {
      return implode(', ', array_filter(array_map(fn($item): string => $this->valueToString($this->resolve($item)), $value)));
    }
    if (is_int($value) || is_float($value)) {
      return (string) $value;
    }
    return '';
  }

  /**
   * Decodes a PDF text string (UTF-16BE, UTF-8 or PDFDocEncoding).
   */
  private function text(mixed $value): string {
    if ($value instanceof PdfName) {
      return $value->name;
    }
    if (!$value instanceof PdfString) {
      return '';
    }
    $bytes = $value->bytes;
    if (str_starts_with($bytes, "\xFE\xFF")) {
      return (string) mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE');
    }
    if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
      return substr($bytes, 3);
    }
    return self::pdfDocToUtf8($bytes);
  }

  /**
   * PDFDocEncoding: Latin-1 except 0x18-0x1F and 0x80-0x9F.
   */
  private static function pdfDocToUtf8(string $bytes): string {
    static $special = [
      0x18 => 0x02D8, 0x19 => 0x02C7, 0x1A => 0x02C6, 0x1B => 0x02D9, 0x1C => 0x02DD, 0x1D => 0x02DB, 0x1E => 0x02DA, 0x1F => 0x02DC,
      0x80 => 0x2022, 0x81 => 0x2020, 0x82 => 0x2021, 0x83 => 0x2026, 0x84 => 0x2014, 0x85 => 0x2013, 0x86 => 0x0192, 0x87 => 0x2044,
      0x88 => 0x2039, 0x89 => 0x203A, 0x8A => 0x2212, 0x8B => 0x2030, 0x8C => 0x201E, 0x8D => 0x201C, 0x8E => 0x201D, 0x8F => 0x2018,
      0x90 => 0x2019, 0x91 => 0x201A, 0x92 => 0x2122, 0x93 => 0xFB01, 0x94 => 0xFB02, 0x95 => 0x0141, 0x96 => 0x0152, 0x97 => 0x0160,
      0x98 => 0x0178, 0x99 => 0x017D, 0x9A => 0x0131, 0x9B => 0x0142, 0x9C => 0x0153, 0x9D => 0x0161, 0x9E => 0x017E, 0xA0 => 0x20AC,
    ];
    $out = '';
    $length = strlen($bytes);
    for ($i = 0; $i < $length; $i++) {
      $byte = ord($bytes[$i]);
      $out .= mb_chr($special[$byte] ?? $byte, 'UTF-8');
    }
    return $out;
  }

  /**
   * Follows startxref and every /Prev section, newest first.
   *
   * @return bool
   *   FALSE when the cross-reference is missing or broken.
   */
  private function loadCrossReferences(): bool {
    if (!preg_match('/startxref\s+(\d+)\s+%%EOF\s*$/', substr($this->data, -2048), $match)) {
      return FALSE;
    }
    $offset = (int) $match[1];
    $seen = [];
    while ($offset > 0 && $offset < strlen($this->data) && !isset($seen[$offset])) {
      $seen[$offset] = TRUE;
      $section = substr($this->data, $offset, 4);
      $trailer = $section === 'xref' ? $this->readXrefTable($offset) : $this->readXrefStream($offset);
      if ($trailer === NULL) {
        return FALSE;
      }
      // Newest section first: keys already set are not overwritten.
      $this->trailer += $trailer;
      // Hybrid files: the table's trailer points to an extra xref stream.
      if (isset($trailer['XRefStm']) && is_int($trailer['XRefStm'])) {
        $this->readXrefStream($trailer['XRefStm']);
      }
      $offset = is_int($trailer['Prev'] ?? NULL) ? $trailer['Prev'] : 0;
    }
    return isset($this->trailer['Root']);
  }

  private function readXrefTable(int $offset): ?array {
    $position = $offset + 4;
    while (preg_match('/\G\s*(\d+)\s+(\d+)\s*\r?\n?/A', $this->data, $match, 0, $position)) {
      $position += strlen($match[0]);
      [$first, $count] = [(int) $match[1], (int) $match[2]];
      for ($i = 0; $i < $count; $i++) {
        $entry = substr($this->data, $position, 20);
        $position += 20;
        if (!preg_match('/^(\d{10}) (\d{5}) ([nf])/', $entry, $parts)) {
          return NULL;
        }
        if ($parts[3] === 'n' && !isset($this->xref[$first + $i])) {
          $this->xref[$first + $i] = (int) $parts[1];
        }
      }
    }
    $trailer_position = strpos($this->data, 'trailer', $position);
    if ($trailer_position === FALSE) {
      return NULL;
    }
    $parser = new PdfLexer($this->data, $trailer_position + 7);
    $trailer = $parser->value();
    return is_array($trailer) ? $trailer : NULL;
  }

  private function readXrefStream(int $offset): ?array {
    $parsed = $this->parseObjectAt($offset);
    $type = $parsed instanceof PdfStream ? ($parsed->dict['Type'] ?? NULL) : NULL;
    if (!$type instanceof PdfName || $type->name !== 'XRef') {
      return NULL;
    }
    $dict = $parsed->dict;
    $widths = $dict['W'] ?? NULL;
    $content = $this->decodeStream($parsed);
    if (!is_array($widths) || count($widths) !== 3 || $content === NULL) {
      return NULL;
    }
    $index = $dict['Index'] ?? [0, (int) ($dict['Size'] ?? 0)];
    $row = array_sum($widths);
    $position = 0;
    for ($i = 0; $i + 1 < count($index); $i += 2) {
      for ($number = (int) $index[$i], $last = $number + (int) $index[$i + 1]; $number < $last; $number++) {
        $fields = [];
        $cursor = $position;
        foreach ($widths as $width) {
          $fields[] = $width === 0 ? NULL : (int) hexdec(bin2hex(substr($content, $cursor, $width)));
          $cursor += $width;
        }
        $position += $row;
        $type = $fields[0] ?? 1;
        if (isset($this->xref[$number])) {
          continue;
        }
        if ($type === 1) {
          $this->xref[$number] = (int) $fields[1];
        }
        elseif ($type === 2) {
          $this->xref[$number] = [(int) $fields[1], (int) $fields[2]];
        }
      }
    }
    return $dict;
  }

  /**
   * Last resort for a broken cross-reference: the last "N G obj" wins.
   */
  private function scanObjects(): void {
    if (preg_match_all('/(?<![0-9])(\d+)\s+(\d+)\s+obj\b/', $this->data, $matches, PREG_OFFSET_CAPTURE)) {
      foreach ($matches[1] as $i => [$number]) {
        $this->xref[(int) $number] = $matches[0][$i][1];
      }
    }
    if (preg_match_all('/trailer\s*<</', $this->data, $matches, PREG_OFFSET_CAPTURE)) {
      foreach (array_reverse($matches[0]) as [, $position]) {
        $trailer = (new PdfLexer($this->data, $position + 7))->value();
        if (is_array($trailer)) {
          $this->trailer += $trailer;
        }
      }
    }
    if (!isset($this->trailer['Root'])) {
      // Compressed files without a readable xref: find the catalog itself.
      foreach ($this->xref as $number => $offset) {
        $object = is_int($offset) ? $this->parseObjectAt($offset) : NULL;
        if (is_array($object) && ($object['Type'] ?? NULL) instanceof PdfName && $object['Type']->name === 'Catalog') {
          $this->trailer['Root'] = new PdfRef($number);
        }
      }
    }
  }

  private function resolve(mixed $value, int $depth = 0): mixed {
    if (!$value instanceof PdfRef || $depth > 32) {
      return $value;
    }
    $number = $value->number;
    if (!array_key_exists($number, $this->cache)) {
      // Guard against reference cycles while resolving.
      $this->cache[$number] = NULL;
      $this->cache[$number] = $this->loadObject($number);
    }
    return $this->resolve($this->cache[$number], $depth + 1);
  }

  private function loadObject(int $number): mixed {
    $location = $this->xref[$number] ?? NULL;
    if (is_int($location)) {
      return $this->parseObjectAt($location);
    }
    if (is_array($location)) {
      [$stream_number] = $location;
      if (!isset($this->objectStreams[$stream_number])) {
        $this->objectStreams[$stream_number] = $this->parseObjectStream($stream_number);
      }
      return $this->objectStreams[$stream_number][$number] ?? NULL;
    }
    return NULL;
  }

  /**
   * @return array<int, mixed>
   */
  private function parseObjectStream(int $stream_number): array {
    $stream = $this->resolve(new PdfRef($stream_number));
    if (!$stream instanceof PdfStream) {
      return [];
    }
    $content = $this->decodeStream($stream);
    $count = (int) ($stream->dict['N'] ?? 0);
    $first = (int) ($stream->dict['First'] ?? 0);
    if ($content === NULL || $count <= 0) {
      return [];
    }
    $header = new PdfLexer(substr($content, 0, $first), 0);
    $pairs = [];
    for ($i = 0; $i < $count; $i++) {
      $pairs[] = [(int) $header->value(), (int) $header->value()];
    }
    $objects = [];
    foreach ($pairs as [$number, $offset]) {
      $objects[$number] = (new PdfLexer($content, $first + $offset))->value();
    }
    return $objects;
  }

  private function parseObjectAt(int $offset): mixed {
    if (!preg_match('/\G\s*\d+\s+\d+\s+obj\b/A', $this->data, $match, 0, $offset)) {
      return NULL;
    }
    $lexer = new PdfLexer($this->data, $offset + strlen($match[0]));
    $value = $lexer->value();
    if (!is_array($value) || !preg_match('/\G\s*stream\r?\n/A', $this->data, $stream_match, 0, $lexer->position())) {
      return $value;
    }
    $start = $lexer->position() + strlen($stream_match[0]);
    $length = $value['Length'] ?? NULL;
    if ($length instanceof PdfRef) {
      $length = $this->resolve($length);
    }
    // Trust /Length only when "endstream" follows it.
    if (!is_int($length) || !preg_match('/\G\s*endstream/A', $this->data, $unused, 0, $start + $length)) {
      $end = strpos($this->data, 'endstream', $start);
      $length = $end === FALSE ? 0 : strlen(rtrim(substr($this->data, $start, $end - $start), "\r\n"));
    }
    return new PdfStream($value, substr($this->data, $start, $length));
  }

  private function decodeStream(PdfStream $stream): ?string {
    $filters = $stream->dict['Filter'] ?? NULL;
    $filters = $filters === NULL ? [] : (is_array($filters) ? $filters : [$filters]);
    $params = $stream->dict['DecodeParms'] ?? NULL;
    $params = is_array($params) && array_is_list($params) ? $params : [$params];
    $content = $stream->raw;
    foreach ($filters as $i => $filter) {
      if (!$filter instanceof PdfName || !in_array($filter->name, ['FlateDecode', 'Fl'], TRUE)) {
        return NULL;
      }
      $decoded = @gzuncompress($content);
      if ($decoded === FALSE) {
        $decoded = @gzinflate(substr($content, 2));
      }
      if ($decoded === FALSE) {
        return NULL;
      }
      $content = $this->unpredict($decoded, is_array($params[$i] ?? NULL) ? $params[$i] : []);
    }
    return $content;
  }

  /**
   * Reverses PNG predictors (cross-reference streams use them).
   */
  private function unpredict(string $data, array $params): string {
    $predictor = (int) ($params['Predictor'] ?? 1);
    if ($predictor < 10) {
      return $data;
    }
    $columns = (int) ($params['Columns'] ?? 1);
    $row_length = $columns + 1;
    $previous = str_repeat("\0", $columns);
    $out = '';
    for ($offset = 0; $offset + $row_length <= strlen($data); $offset += $row_length) {
      $type = ord($data[$offset]);
      $row = substr($data, $offset + 1, $columns);
      $current = '';
      for ($i = 0; $i < $columns; $i++) {
        $raw = ord($row[$i]);
        $left = $i > 0 ? ord($current[$i - 1]) : 0;
        $up = ord($previous[$i]);
        $up_left = $i > 0 ? ord($previous[$i - 1]) : 0;
        $value = match ($type) {
          1 => $raw + $left,
          2 => $raw + $up,
          3 => $raw + intdiv($left + $up, 2),
          4 => $raw + self::paeth($left, $up, $up_left),
          default => $raw,
        };
        $current .= chr($value & 0xFF);
      }
      $out .= $current;
      $previous = $current;
    }
    return $out;
  }

  private static function paeth(int $a, int $b, int $c): int {
    $p = $a + $b - $c;
    $pa = abs($p - $a);
    $pb = abs($p - $b);
    $pc = abs($p - $c);
    return ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
  }

}
