<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Service;

use Drupal\Core\Queue\QueueFactory;
use Psr\Log\LoggerInterface;

/**
 * Queue facade with stable queue names.
 */
final class QueueManagerService {

  public const EMAIL_QUEUE = 'aseguramiento_email_processing';
  public const EXCEL_QUEUE = 'aseguramiento_excel_parsing';
  public const PDF_QUEUE = 'aseguramiento_pdf_generation';
  public const MAIL_QUEUE = 'aseguramiento_mail_sending';

  public function __construct(
    private readonly QueueFactory $queueFactory,
    private readonly LoggerInterface $logger,
  ) {
  }

  public function enqueue(string $queue_name, array $payload): void {
    $this->queueFactory->get($queue_name)->createItem($payload + ['queued_at' => time()]);
    $this->logger->info('[Aseguramiento] Elemento agregado a la cola @queue.', ['@queue' => $queue_name]);
  }

}
