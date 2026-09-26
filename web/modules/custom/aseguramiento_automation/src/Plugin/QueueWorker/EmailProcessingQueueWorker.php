<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Plugin\QueueWorker;

use Drupal\aseguramiento_automation\Service\EmailParserService;
use Drupal\aseguramiento_automation\Service\MailService;
use Drupal\aseguramiento_automation\Service\MailProviderManagerService;
use Drupal\aseguramiento_automation\Service\ProcessedMailRegistry;
use Drupal\aseguramiento_automation\Service\QueueManagerService;
use Drupal\aseguramiento_automation\Service\SolicitudBatchService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Downloads and validates inbound message attachments.
 *
 * @QueueWorker(
 *   id = "aseguramiento_email_processing",
 *   title = @Translation("Aseguramiento email processing"),
 *   cron = {"time" = 60}
 * )
 */
final class EmailProcessingQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly MailProviderManagerService $providerManager,
    private readonly EmailParserService $emailParser,
    private readonly QueueManagerService $queueManager,
    private readonly MailService $mailService,
    private readonly SolicitudBatchService $batchService,
    private readonly ProcessedMailRegistry $registry,
    private readonly LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('aseguramiento_automation.mail_provider_manager'),
      $container->get('aseguramiento_automation.email_parser'),
      $container->get('aseguramiento_automation.queue_manager'),
      $container->get('aseguramiento_automation.mail'),
      $container->get('aseguramiento_automation.solicitud_batch'),
      $container->get('aseguramiento_automation.processed_mail'),
      $container->get('logger.channel.aseguramiento_automation'),
    );
  }

  public function processItem($data): void {
    $start = microtime(TRUE);
    $account = (array) ($data['account'] ?? []);
    $message = (array) ($data['message'] ?? []);
    $settings = $this->configFactory->get('aseguramiento_automation.settings')->getRawData();
    $debug = !empty($settings['debug_mode']);
    $account_id = (string) ($account['id'] ?? $account['mailbox'] ?? 'desconocido');
    $message_id = (string) ($message['headers']['message_id'] ?? $message['headers']['internetMessageId'] ?? $message['id'] ?? '');

    try {
      $this->logger->info('[Aseguramiento] Procesando correo recibido de @from. Asunto: @subject.', [
        '@from' => $message['from'] ?? 'remitente desconocido',
        '@subject' => $message['subject'] ?? 'sin asunto',
      ]);
      $this->logger->info('[Aseguramiento] Fecha del correo: @date. Identificador: @id.', [
        '@date' => $message['received'] ?? 'no disponible',
        '@id' => $message_id !== '' ? $message_id : 'no disponible',
      ]);
      if ($debug) {
        $this->logger->info('[Aseguramiento][Depuración] Cuenta: @account. Proveedor: @provider. ID interno del mensaje: @id.', [
          '@account' => $account_id,
          '@provider' => $account['provider'] ?? 'microsoft_graph',
          '@id' => $message['id'] ?? 'no disponible',
        ]);
      }

      $filter = $this->emailParser->filterMessage($message, $settings);
      $provider = $this->providerManager->getProvider($account['provider'] ?? 'microsoft_graph');

      if (!$filter['accepted']) {
        $provider->markProcessed($account, $message);
        $provider->moveMessage($account, $message, (string) ($account['error_folder'] ?? $settings['default_error_folder'] ?? 'Errors'));
        $this->registry->done($account, $message, 'rejected');
        $this->logger->warning('[Aseguramiento] Correo rechazado. Identificador: @id. Asunto: @subject. Motivo: @errors', [
          '@id' => $message_id !== '' ? $message_id : ($message['id'] ?? ''),
          '@subject' => $message['subject'] ?? '',
          '@errors' => implode('; ', $filter['errors']),
        ]);
        return;
      }

      $attachments = $provider->downloadAttachments($account, $message);
      if ($attachments === []) {
        $this->logger->warning('[Aseguramiento] No se encontraron archivos adjuntos en el correo.');
      }
      else {
        $this->logger->info('[Aseguramiento] Se encontraron @count archivos adjuntos.', ['@count' => count($attachments)]);
      }

      $files = $this->emailParser->persistProcessableAttachments($attachments, (string) ($account['id'] ?? 'default'));
      $excel_count = count(array_filter($files, static fn(array $file): bool => ($file['type'] ?? '') === 'excel'));
      $pdf_count = count(array_filter($files, static fn(array $file): bool => ($file['type'] ?? '') === 'pdf'));
      $this->logger->info('[Aseguramiento] Archivos listos para procesamiento. Excel: @excel. PDF rellenable: @pdf.', [
        '@excel' => $excel_count,
        '@pdf' => $pdf_count,
      ]);
      $this->mailService->sendInboundRequestNotification($message, $files, $settings);
      // One batch per email: the client gets a single reply for all files.
      $lote = $files !== [] ? $this->batchService->start($account, $message, $files) : NULL;
      foreach (array_values($files) as $index => $file) {
        if ($debug) {
          $this->logger->info('[Aseguramiento][Depuración] Archivo persistido para cola Excel. FID: @fid. URI: @uri.', [
            '@fid' => $file['fid'] ?? 'no disponible',
            '@uri' => $file['uri'] ?? 'no disponible',
          ]);
        }
        $this->queueManager->enqueue(QueueManagerService::EXCEL_QUEUE, [
          'account' => $account,
          'message' => $message,
          'file' => $file,
          'lote' => $lote,
          'file_key' => SolicitudBatchService::fileKey($file, $index),
        ]);
      }
      $provider->markProcessed($account, $message);
      $this->registry->done($account, $message, 'processed');
      $provider->moveMessage($account, $message, (string) ($account['processed_folder'] ?? $settings['default_processed_folder'] ?? 'Processed'));
      if ($debug) {
        $this->logger->info('[Aseguramiento][Depuración] Procesamiento de correo finalizado en @time ms.', [
          '@time' => number_format((microtime(TRUE) - $start) * 1000, 2),
        ]);
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('[Aseguramiento] Error durante el procesamiento del correo. Detalle: @error', [
        '@error' => $e->getMessage(),
      ]);
      $this->settleFailure($account, $message, $settings);
      throw $e;
    }
  }

  /**
   * Schedules a retry, or gives up and moves the email to the error folder.
   */
  private function settleFailure(array $account, array $message, array $settings): void {
    $context = ['@from' => $message['from'] ?? 'remitente desconocido', '@subject' => $message['subject'] ?? 'sin asunto'];
    try {
      if ($this->registry->failed($account, $message)) {
        $this->logger->warning('[Aseguramiento] El correo de @from (@subject) se reintentará en @minutes minutos.', $context + ['@minutes' => ProcessedMailRegistry::RETRY_DELAY / 60]);
        return;
      }
      $this->logger->error('[Aseguramiento] Se abandonó el correo de @from (@subject) tras @n intentos fallidos. Revísalo en la carpeta de errores del buzón.', $context + ['@n' => ProcessedMailRegistry::MAX_ATTEMPTS]);
      $this->providerManager->getProvider($account['provider'] ?? 'microsoft_graph')
        ->moveMessage($account, $message, (string) ($account['error_folder'] ?? $settings['default_error_folder'] ?? 'Errors'));
    }
    catch (\Throwable $e) {
      // The original error is the one that matters; this one is only logged.
      $this->logger->warning('[Aseguramiento] No se pudo registrar el fallo del correo de @from. Detalle: @error', $context + ['@error' => $e->getMessage()]);
    }
  }

}
