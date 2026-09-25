<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Plugin\QueueWorker;

use Drupal\aseguramiento_automation\Entity\ConstanciaEntity;
use Drupal\aseguramiento_automation\Service\MailService;
use Drupal\aseguramiento_automation\Service\SolicitudBatchService;
use Drupal\aseguramiento_automation\Util\SolicitudErrorFormatter;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueWorkerBase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Replies to the client with the generated PDFs.
 *
 * Items carry either a "lote" (one reply per inbound email, with every PDF
 * and what to fix in the files that failed) or, for items queued before
 * batches existed, a single "constancia_id".
 *
 * @QueueWorker(
 *   id = "aseguramiento_mail_sending",
 *   title = @Translation("Aseguramiento mail sending"),
 *   cron = {"time" = 90}
 * )
 */
final class MailSendingQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Longest wait for a batch to finish before answering with what is ready.
   */
  private const BATCH_MAX_WAIT = 1800;

  /**
   * Delivery attempts before giving up on a batch reply.
   */
  private const MAX_SEND_ATTEMPTS = 5;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly MailService $mailService,
    private readonly SolicitudBatchService $batchService,
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
      $container->get('aseguramiento_automation.solicitud_batch'),
      $container->get('logger.channel.aseguramiento_automation'),
    );
  }

  public function processItem($data): void {
    $settings = $this->configFactory->get('aseguramiento_automation.settings')->getRawData();
    if (!empty($data['lote'])) {
      $this->replyToBatch((string) $data['lote'], $settings);
      return;
    }
    $this->sendSingle($data, $settings);
  }

  /**
   * Sends one reply for every file received in the same email.
   */
  private function replyToBatch(string $lote, array $settings): void {
    $batch = $this->batchService->get($lote);
    if ($batch === NULL) {
      $this->logger->warning('[Aseguramiento] No se encontró el lote @lote para responder; pudo haber expirado.', ['@lote' => $lote]);
      return;
    }
    if ($batch['replied']) {
      return;
    }
    $waited_too_long = time() - (int) $batch['created'] > self::BATCH_MAX_WAIT;
    if (!$this->batchService->allFilesSettled($batch) && !$waited_too_long) {
      throw new DelayedRequeueException(60, 'El lote todavía tiene archivos en proceso.');
    }

    $storage = $this->entityTypeManager->getStorage('aseguramiento_constancia');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('lote', $lote)->sort('id')->execute();
    $entities = $storage->loadMultiple($ids);
    foreach ($entities as $entity) {
      if ($entity->get('status')->value === 'validated' && !$waited_too_long) {
        throw new DelayedRequeueException(60, 'El lote todavía tiene PDFs en generación.');
      }
    }

    $file_names = [];
    foreach ($batch['files'] as $file) {
      foreach ($file['constancias'] as $id) {
        $file_names[$id] = $file['name'];
      }
    }

    $ok = [];
    $failed = [];
    $recipients = [];
    foreach ($entities as $entity) {
      $email = trim((string) $entity->get('email')->value);
      if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $recipients[strtolower($email)] = $email;
      }
      $pdf = (string) $entity->get('pdf_generado')->value;
      $status = (string) $entity->get('status')->value;
      if ($pdf !== '' && in_array($status, ['pdf_generated', 'sent'], TRUE)) {
        $ok[] = ['entity' => $entity, 'folio' => $entity->label(), 'nombre' => $this->displayName($entity), 'pdf_uri' => $pdf];
        continue;
      }
      $errors = SolicitudErrorFormatter::describe($entity->get('errores')->value);
      if ($errors['fields'] === [] && $errors['internal'] === []) {
        $errors['internal'][] = 'La constancia no terminó de generarse a tiempo.';
      }
      $failed[] = [
        'file' => $file_names[(int) $entity->id()] ?? basename((string) $entity->get('excel_original')->value),
        'folio' => $entity->label(),
        'nombre' => $this->displayName($entity),
      ] + $errors;
    }
    foreach ($batch['files'] as $file) {
      if ($file['state'] === 'error') {
        $failed[] = ['file' => $file['name'], 'folio' => '', 'nombre' => '', 'fields' => [$file['error']], 'internal' => []];
      }
    }
    if ($ok === [] && $failed === []) {
      $this->batchService->markReplied($lote);
      return;
    }

    $sender = trim((string) $batch['from']);
    if ($recipients === [] && filter_var($sender, FILTER_VALIDATE_EMAIL)) {
      $recipients[strtolower($sender)] = $sender;
    }
    if ($recipients === []) {
      $this->logger->error('[Aseguramiento] El lote @lote no tiene un correo válido para responder al cliente.', ['@lote' => $lote]);
      $this->batchService->markReplied($lote);
      return;
    }

    // The common case (one valid request) keeps the configured template.
    $sent = (count($ok) === 1 && $failed === [])
      ? $this->mailService->sendConstancia($this->pdfRow($ok[0]['entity']), $ok[0]['pdf_uri'], $settings)
      : $this->mailService->sendBatchReply(implode(',', $recipients), $ok, $failed, $settings);

    if (!$sent) {
      $attempts = $this->batchService->countSendAttempt($lote);
      if ($attempts < self::MAX_SEND_ATTEMPTS) {
        throw new DelayedRequeueException(300, 'No se pudo enviar la respuesta del lote; se reintentará.');
      }
      $this->logger->error('[Aseguramiento] Se abandonó la respuesta del lote @lote tras @n intentos fallidos.', ['@lote' => $lote, '@n' => $attempts]);
      foreach ($ok as $item) {
        $item['entity']->set('status', 'error');
        $item['entity']->set('errores', trim((string) $item['entity']->get('errores')->value . "\nNo fue posible enviar el correo al cliente."));
        $item['entity']->save();
      }
      $this->batchService->markReplied($lote);
      return;
    }

    $this->batchService->markReplied($lote);
    foreach ($ok as $item) {
      $item['entity']->set('status', 'sent');
      $item['entity']->save();
    }
    if ($failed !== []) {
      $this->logger->info('[Aseguramiento] Se notificó al cliente qué corregir en el lote @lote: @n solicitudes sin constancia.', ['@lote' => $lote, '@n' => count($failed)]);
    }
  }

  /**
   * Previous behaviour: one email per constancia.
   */
  private function sendSingle(array $data, array $settings): void {
    $start = microtime(TRUE);
    $debug = !empty($settings['debug_mode']);

    try {
      $entity = $this->entityTypeManager->getStorage('aseguramiento_constancia')->load($data['constancia_id'] ?? NULL);
      if (!$entity) {
        throw new \RuntimeException('No se encontró la constancia solicitada.');
      }
      $row = $this->pdfRow($entity);
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

  /**
   * Values available to the reply template for one constancia.
   */
  private function pdfRow(ContentEntityInterface $entity): array {
    $row = [];
    foreach (array_merge(['folio', 'nombre', 'poliza', 'suma_asegurada', 'rfc', 'email', 'telefono', 'aseguradora', 'tipo_documento'], ConstanciaEntity::solicitudPdfFields()) as $field) {
      $row[$field] = (string) $entity->get($field)->value;
    }
    return $row;
  }

  private function displayName(ContentEntityInterface $entity): string {
    foreach (['solicitante', 'nombre', 'beneficiario_nombre'] as $field) {
      $value = trim((string) $entity->get($field)->value);
      if ($value !== '') {
        return $value;
      }
    }
    return '';
  }

}
