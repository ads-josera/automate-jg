<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Entity;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Link;

/**
 * Lists PDF templates.
 */
final class PdfTemplateListBuilder extends ConfigEntityListBuilder {

  public function buildHeader(): array {
    return [
      'label' => $this->t('Plantilla'),
      'insurer' => $this->t('Aseguradora'),
      'document_type' => $this->t('Tipo de documento'),
      'version' => $this->t('Versión'),
      'status' => $this->t('Activa'),
      'builder' => $this->t('Constructor'),
    ] + parent::buildHeader();
  }

  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof PdfTemplate);
    return [
      'label' => $entity->label(),
      'insurer' => $entity->get('insurer'),
      'document_type' => $entity->get('document_type'),
      'version' => $entity->get('version'),
      'status' => $entity->status() ? $this->t('Sí') : $this->t('No'),
      'builder' => Link::createFromRoute($this->t('Abrir constructor'), 'aseguramiento_automation.template_builder', ['aseguramiento_pdf_template' => $entity->id()]),
    ] + parent::buildRow($entity);
  }

}
