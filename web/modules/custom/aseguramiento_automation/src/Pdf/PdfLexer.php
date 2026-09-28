<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Pdf;

/**
 * Parses PDF object syntax (ISO 32000-1, 7.3) from a position in a buffer.
 *
 * Dictionaries become associative arrays keyed by name (without the slash),
 * arrays become lists, and "N G R" becomes a PdfRef.
 */
final class PdfLexer {

  private const WHITESPACE = "\x00\x09\x0A\x0C\x0D\x20";

  private const DELIMITERS = '()<>[]{}/%';

  public function __construct(
    private readonly string $data,
    private int $position,
  ) {
  }

  public function position(): int {
    return $this->position;
  }

  /**
   * The next object, or NULL at the end of the data.
   */
  public function value(int $depth = 0): mixed {
    if ($depth > 64) {
      throw new \RuntimeException('PDF demasiado anidado.');
    }
    $this->skipWhitespace();
    $char = $this->data[$this->position] ?? '';
    if ($char === '') {
      return NULL;
    }
    if ($char === '/') {
      return $this->name();
    }
    if ($char === '(') {
      return new PdfString($this->literalString());
    }
    if ($char === '<') {
      if (($this->data[$this->position + 1] ?? '') === '<') {
        return $this->dictionary($depth);
      }
      return new PdfString($this->hexString());
    }
    if ($char === '[') {
      $this->position++;
      $items = [];
      while (TRUE) {
        $this->skipWhitespace();
        $next = $this->data[$this->position] ?? '';
        if ($next === ']' || $next === '') {
          $this->position++;
          return $items;
        }
        $items[] = $this->value($depth + 1);
      }
    }
    $token = $this->token();
    if (preg_match('/^[+-]?\d+$/', $token)) {
      // "N G R" is a reference; look ahead without consuming otherwise.
      if (preg_match('/\G\s+(\d+)\s+R(?![A-Za-z0-9])/A', $this->data, $match, 0, $this->position)) {
        $this->position += strlen($match[0]);
        return new PdfRef((int) $token);
      }
      return (int) $token;
    }
    if (preg_match('/^[+-]?(\d+\.\d*|\.\d+)$/', $token)) {
      return (float) $token;
    }
    return match ($token) {
      'true' => TRUE,
      'false' => FALSE,
      default => NULL,
    };
  }

  private function dictionary(int $depth): array {
    $this->position += 2;
    $dict = [];
    while (TRUE) {
      $this->skipWhitespace();
      if (substr($this->data, $this->position, 2) === '>>' || $this->position >= strlen($this->data)) {
        $this->position += 2;
        return $dict;
      }
      $key = $this->value($depth + 1);
      if (!$key instanceof PdfName) {
        // Malformed entry: skip the token and keep going.
        continue;
      }
      $dict[$key->name] = $this->value($depth + 1);
    }
  }

  private function name(): PdfName {
    $this->position++;
    $start = $this->position;
    while (($char = $this->data[$this->position] ?? '') !== '' && !str_contains(self::WHITESPACE . self::DELIMITERS, $char)) {
      $this->position++;
    }
    $raw = substr($this->data, $start, $this->position - $start);
    return new PdfName(preg_replace_callback('/#([0-9A-Fa-f]{2})/', static fn(array $m): string => chr((int) hexdec($m[1])), $raw) ?? $raw);
  }

  private function literalString(): string {
    $this->position++;
    $depth = 1;
    $out = '';
    $length = strlen($this->data);
    while ($this->position < $length) {
      $char = $this->data[$this->position++];
      if ($char === '\\') {
        $next = $this->data[$this->position++] ?? '';
        if ($next >= '0' && $next <= '7') {
          $octal = $next;
          for ($i = 0; $i < 2 && ($digit = $this->data[$this->position] ?? '') >= '0' && $digit <= '7'; $i++) {
            $octal .= $digit;
            $this->position++;
          }
          $out .= chr(octdec($octal) & 0xFF);
          continue;
        }
        $out .= match ($next) {
          'n' => "\n",
          'r' => "\r",
          't' => "\t",
          'b' => "\x08",
          'f' => "\x0C",
          // A backslash at the end of a line continues the string.
          "\r" => (($this->data[$this->position] ?? '') === "\n" ? $this->skipOne() : ''),
          "\n" => '',
          default => $next,
        };
        continue;
      }
      if ($char === '(') {
        $depth++;
      }
      elseif ($char === ')') {
        if (--$depth === 0) {
          break;
        }
      }
      $out .= $char;
    }
    return $out;
  }

  private function skipOne(): string {
    $this->position++;
    return '';
  }

  private function hexString(): string {
    $end = strpos($this->data, '>', $this->position);
    $end = $end === FALSE ? strlen($this->data) : $end;
    $hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($this->data, $this->position + 1, $end - $this->position - 1)) ?? '';
    $this->position = $end + 1;
    if (strlen($hex) % 2 === 1) {
      $hex .= '0';
    }
    return (string) hex2bin($hex);
  }

  private function token(): string {
    $start = $this->position;
    while (($char = $this->data[$this->position] ?? '') !== '' && !str_contains(self::WHITESPACE . self::DELIMITERS, $char)) {
      $this->position++;
    }
    if ($this->position === $start) {
      // A stray delimiter: consume it so parsing always advances.
      $this->position++;
    }
    return substr($this->data, $start, $this->position - $start);
  }

  private function skipWhitespace(): void {
    $length = strlen($this->data);
    while ($this->position < $length) {
      $char = $this->data[$this->position];
      if ($char === '%') {
        while ($this->position < $length && !in_array($this->data[$this->position], ["\r", "\n"], TRUE)) {
          $this->position++;
        }
        continue;
      }
      if (!str_contains(self::WHITESPACE, $char)) {
        return;
      }
      $this->position++;
    }
  }

}
