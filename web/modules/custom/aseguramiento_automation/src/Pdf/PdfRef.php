<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Pdf;

/**
 * An indirect reference (N G R) to another object.
 */
final class PdfRef {

  public function __construct(public readonly int $number) {
  }

}
