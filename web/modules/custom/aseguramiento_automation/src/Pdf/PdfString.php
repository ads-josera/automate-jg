<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Pdf;

/**
 * A PDF string object; raw bytes, decoded by the reader.
 */
final class PdfString {

  public function __construct(public readonly string $bytes) {
  }

}
