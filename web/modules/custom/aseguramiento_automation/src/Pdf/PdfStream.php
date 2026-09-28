<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Pdf;

/**
 * A PDF stream: its dictionary and the still-encoded bytes.
 */
final class PdfStream {

  public function __construct(
    public readonly array $dict,
    public readonly string $raw,
  ) {
  }

}
