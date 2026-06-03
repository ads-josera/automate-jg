<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Mail;

/**
 * Contract for pluggable enterprise mail providers.
 */
interface MailProviderInterface {

  /**
   * Returns the provider machine name.
   */
  public function id(): string;

  /**
   * Fetches unread/new messages in a normalized transport-neutral shape.
   *
   * @param array $account
   *   Mail account configuration.
   * @param int $limit
   *   Maximum messages to fetch.
   *
   * @return array<int, array<string, mixed>>
   *   Normalized messages.
   */
  public function fetchMessages(array $account, int $limit = 25): array;

  /**
   * Downloads attachments for a normalized message.
   *
   * @return array<int, array{name:string, mime:string, content:string}>
   *   Attachment records with binary content.
   */
  public function downloadAttachments(array $account, array $message): array;

  /**
   * Marks a message as processed.
   */
  public function markProcessed(array $account, array $message): void;

  /**
   * Moves a message into a provider folder.
   */
  public function moveMessage(array $account, array $message, string $folder): void;

}

