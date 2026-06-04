<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Plugin\QueueWorker;

use Drupal\aseguramiento_automation\Entity\ConstanciaEntity;
use Drupal\aseguramiento_automation\Service\MailService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Psr\Log\LoggerInterface;
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
    private readonly LoggerInterface $logger,
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
      $container->get('logger.channel.aseguramiento_automation'),
    );
  }

  public function processItem($data): void {
    $start = microtime(TRUE);
    $settings = $this->configFactory->get('aseguramiento_automation.settings')->getRawData();
    $debug = !empty($settings['debug_mode']);

    try {
      $entity = $this->entityTypeManager->getStorage('aseguramiento_constancia')->load($data['constancia_id'] ?? NULL);
      if (!$entity) {
        throw new \RuntimeException('No se encontró la constancia solicitada.');
      }
      $row = [];
      foreach (array_merge(['folio', 'nombre', 'poliza', 'suma_asegurada', 'rfc', 'email', 'telefono', 'aseguradora', 'tipo_documento'], ConstanciaEntity::solicitudPdfFields()) as $field) {
        $row[$field] = (string) $entity->get($field)->value;
      }
      $this->logger->info('[Aseguramiento] Iniciando envío de constancia al cliente @to. Folio: @folio.', [
        '@to' => $row['email'] ?? 'sin correo',
        '@folio' => $row['folio'] ?? '',
      ]);
      if ($debug) {
        $this->logger->info('[Aseguramiento][Depuración] PDF a enviar: @pdf.', [
          '@pdf' => (string) $entity->get('pdf_generado')->value,
        ]);
      }
      $sent = $this->mailService->sendConstancia($row, (string) $entity->get('pdf_generado')->value, $settings);
      $entity->set('status', $sent ? 'sent' : 'error');
      if (!$sent) {
        $entity->set('errores', trim((string) $entity->get('errores')->value . "\nNo fue posible enviar el correo al cliente."));
        $this->logger->error('[Aseguramiento] Error al enviar notificación. Detalle: el proveedor de correo devolvió resultado fallido.');
      }
      else {
        $this->logger->info('[Aseguramiento] Correo de notificación enviado correctamente a @to', ['@to' => $row['email'] ?? '']);
      }
      $entity->save();
      if ($debug) {
        $this->logger->info('[Aseguramiento][Depuración] Envío de correo finalizado en @time ms.', [
          '@time' => number_format((microtime(TRUE) - $start) * 1000, 2),
        ]);
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('[Aseguramiento] Error al enviar notificación. Detalle: @error', [
        '@error' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

}
