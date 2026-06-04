<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Drush\Commands;

use Drupal\aseguramiento_automation\EventSubscriber\CronSubscriber;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\aseguramiento_automation\Service\QueueManagerService;
use Drush\Commands\DrushCommands;
use Psr\Log\LoggerInterface;

/**
 * Drush commands for local assurance automation testing.
 */
final class AseguramientoAutomationCommands extends DrushCommands {

  public function __construct(
    private readonly CronSubscriber $cronSubscriber,
    private readonly QueueWorkerManagerInterface $queueWorkerManager,
    private readonly QueueFactory $queueFactory,
    private readonly LoggerInterface $automationLogger,
  ) {
    parent::__construct();
  }

  /**
   * Runs mail polling and all automation queues in order.
   *
   * @command aseguramiento:procesar-correo
   * @aliases aseguramiento-procesar-correo
   * @usage drush aseguramiento:procesar-correo
   *   Poll configured mailboxes, parse Excel attachments, generate PDFs, and send replies.
   */
  public function procesarCorreo(): int {
    $this->io()->title('Aseguramiento Automation');
    $this->io()->section('Consultando buzones');
    $this->cronSubscriber->pollMailAccounts();

    $summary = [];
    foreach ([
      QueueManagerService::EMAIL_QUEUE => 'Procesamiento de correos',
      QueueManagerService::EXCEL_QUEUE => 'Lectura de Excel',
      QueueManagerService::PDF_QUEUE => 'Generación de PDF',
      QueueManagerService::MAIL_QUEUE => 'Envío de correo',
    ] as $queue_name => $label) {
      $summary[$queue_name] = $this->runQueue($queue_name, $label);
    }

    $this->automationLogger->info("[Aseguramiento] Ejecución finalizada correctamente.\nResumen:\nCorreos procesados: @emails\nLecturas de archivo: @excels\nPDFs generados: @pdfs\nCorreos enviados: @sent\nErrores: @errors", [
      '@emails' => $summary[QueueManagerService::EMAIL_QUEUE]['processed'] ?? 0,
      '@excels' => $summary[QueueManagerService::EXCEL_QUEUE]['processed'] ?? 0,
      '@pdfs' => $summary[QueueManagerService::PDF_QUEUE]['processed'] ?? 0,
      '@sent' => $summary[QueueManagerService::MAIL_QUEUE]['processed'] ?? 0,
      '@errors' => array_sum(array_column($summary, 'errors')),
    ]);
    $this->io()->success('Ciclo de prueba completado.');
    return self::EXIT_SUCCESS;
  }

  /**
   * Runs every available item in a Drupal queue.
   */
  private function runQueue(string $queue_name, string $label): array {
    $this->io()->section($label);
    $queue = $this->queueFactory->get($queue_name);
    $worker = $this->queueWorkerManager->createInstance($queue_name);
    $processed = 0;
    $errors = 0;

    while ($item = $queue->claimItem(60)) {
      try {
        $worker->processItem($item->data);
        $queue->deleteItem($item);
        $processed++;
      }
      catch (RequeueException) {
        $queue->releaseItem($item);
        $this->io()->warning(sprintf('Un elemento fue regresado a la cola %s.', $queue_name));
        break;
      }
      catch (SuspendQueueException $e) {
        $queue->releaseItem($item);
        $this->io()->warning(sprintf('Cola suspendida: %s', $e->getMessage()));
        break;
      }
      catch (\Throwable $e) {
        $errors++;
        $queue->deleteItem($item);
        $this->io()->error(sprintf('Error en %s: %s', $queue_name, $e->getMessage()));
        $this->automationLogger->error('[Aseguramiento] Error al ejecutar la cola @queue. Detalle: @error', [
          '@queue' => $queue_name,
          '@error' => $e->getMessage(),
        ]);
      }
    }

    $this->io()->writeln(sprintf('Elementos procesados: %d', $processed));
    return ['processed' => $processed, 'errors' => $errors];
  }

}
