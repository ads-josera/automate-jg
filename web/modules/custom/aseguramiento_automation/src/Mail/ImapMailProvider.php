<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Mail;

use Drupal\aseguramiento_automation\Service\ImapService;

/**
 * IMAP provider (pure PHP client, identifies messages by UID).
 */
final class ImapMailProvider implements MailProviderInterface, SpamRescueInterface {

  public function __construct(private readonly ImapService $imap) {
  }

  public function id(): string {
    return 'imap';
  }

  public function fetchMessages(array $account, int $limit = 25): array {
    return $this->imap->fetchMessages($account, $limit);
  }

  public function rescueFromSpam(array $account, callable $isRequest): int {
    return $this->imap->rescueFromSpam($account, $isRequest);
  }

  public function downloadAttachments(array $account, array $message): array {
    return $this->imap->downloadAttachments($account, $message);
  }

  public function markProcessed(array $account, array $message): void {
    $this->imap->markProcessed($account, $message);
  }

  public function moveMessage(array $account, array $message, string $folder): void {
    $this->imap->moveMessage($account, $message, $folder);
  }

}

