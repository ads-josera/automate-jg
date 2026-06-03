<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Entity;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * Lists configured enterprise mail accounts.
 */
final class MailAccountListBuilder extends ConfigEntityListBuilder {

  public function buildHeader(): array {
    return [
      'label' => $this->t('Cuenta'),
      'provider' => $this->t('Proveedor'),
      'mailbox' => $this->t('Buzón'),
      'company' => $this->t('Empresa'),
      'status' => $this->t('Activa'),
    ] + parent::buildHeader();
  }

  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof MailAccount);
    $config = $entity->toProviderConfig();
    return [
      'label' => $entity->label(),
      'provider' => $config['provider'],
      'mailbox' => $config['mailbox'] ?: $config['username'],
      'company' => $config['company_id'],
      'status' => $entity->status() ? $this->t('Sí') : $this->t('No'),
    ] + parent::buildRow($entity);
  }

}
