<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Pdf;

/**
 * A PDF name object (/Name), with #xx escapes decoded.
 */
final class PdfName {

  public function __construct(public readonly string $name) {
  }

}
