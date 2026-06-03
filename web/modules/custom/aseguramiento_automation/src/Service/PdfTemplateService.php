<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Service;

use Drupal\aseguramiento_automation\Entity\PdfTemplate;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Resolves active PDF templates for a constancia context.
 */
final class PdfTemplateService {

  public function __construct(private readonly EntityTypeManagerInterface $entityTypeManager) {
  }

  public function resolve(array $context): ?PdfTemplate {
    $templates = $this->entityTypeManager->getStorage('aseguramiento_pdf_template')->loadMultiple();
    usort($templates, static fn(PdfTemplate $a, PdfTemplate $b): int => ((int) $a->get('weight')) <=> ((int) $b->get('weight')));
    foreach ($templates as $template) {
      if ($template instanceof PdfTemplate && $template->matches($context)) {
        return $template;
      }
    }
    return NULL;
  }

}

