<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Plugin\QueueWorker;

use Drupal\aseguramiento_automation\Entity\ConstanciaEntity;
use Drupal\aseguramiento_automation\Service\MailService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sends generated PDFs back to recipients.
 *
 * @QueueWorker(
 *   id = "aseguramiento_mail_sending",
 *   title = @Translation("Aseguramiento mail sending"),
 *   cron = {"time" = 90}
 * )
 */
final class MailSendingQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly MailService $mailService,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('aseguramiento_automation.mail'),
    );
  }

  public function processItem($data): void {
    $entity = $this->entityTypeManager->getStorage('aseguramiento_constancia')->load($data['constancia_id'] ?? NULL);
    if (!$entity) {
      throw new \RuntimeException('Constancia not found.');
    }
    $row = [];
    foreach (array_merge(['folio', 'nombre', 'poliza', 'suma_asegurada', 'rfc', 'email', 'telefono', 'aseguradora', 'tipo_documento'], ConstanciaEntity::solicitudPdfFields()) as $field) {
      $row[$field] = (string) $entity->get($field)->value;
    }
    $sent = $this->mailService->sendConstancia($row, (string) $entity->get('pdf_generado')->value, $this->configFactory->get('aseguramiento_automation.settings')->getRawData());
    $entity->set('status', $sent ? 'sent' : 'error');
    if (!$sent) {
      $entity->set('errores', trim((string) $entity->get('errores')->value . "\nEmail delivery failed."));
    }
    $entity->save();
  }

}
