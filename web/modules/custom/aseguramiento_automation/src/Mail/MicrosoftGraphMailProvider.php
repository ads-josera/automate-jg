<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Mail;

use Drupal\aseguramiento_automation\Service\MicrosoftGraphService;

/**
 * Microsoft 365 provider backed by Microsoft Graph.
 */
final class MicrosoftGraphMailProvider implements MailProviderInterface {

  public function __construct(private readonly MicrosoftGraphService $graph) {
  }

  public function id(): string {
    return 'microsoft_graph';
  }

  public function fetchMessages(array $account, int $limit = 25): array {
    return $this->graph->fetchMessages($account, $limit);
  }

  public function downloadAttachments(array $account, array $message): array {
    return $this->graph->downloadAttachments($account, $message);
  }

  public function markProcessed(array $account, array $message): void {
    $this->graph->markRead($account, (string) $message['id']);
  }

  public function moveMessage(array $account, array $message, string $folder): void {
    $this->graph->moveMessage($account, (string) $message['id'], $folder);
  }

}

