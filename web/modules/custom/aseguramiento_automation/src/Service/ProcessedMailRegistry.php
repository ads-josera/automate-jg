<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Service;

use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;

/**
 * Remembers which inbound emails were already taken for processing.
 *
 * The mailbox "seen" flag is not a reliable marker: whoever opens the request
 * in webmail before the next run marks it as read, and it used to be ignored
 * forever. Every email is claimed here before it is queued, so it is queued
 * once whatever its flags, and a failed one is retried a limited number of
 * times instead of never (or every minute).
 *
 * Emails are identified by their Message-ID, which survives moving between
 * folders (the IMAP UID does not); the UID is only a fallback.
 */
final class ProcessedMailRegistry {

  private const COLLECTION = 'aseguramiento_automation.correo';

  /**
   * A claim not settled within this time (lost queue item) can be retaken.
   */
  private const CLAIM_TTL = 3600;

  /**
   * How long a settled email is remembered.
   *
   * Longer than the window in which the mailbox is re-read (see
   * ImapService::RECENT_DAYS), so an email that stays in the inbox because it
   * could not be moved is never processed twice.
   */
  private const DONE_TTL = 400 * 86400;

  public const MAX_ATTEMPTS = 3;

  public const RETRY_DELAY = 900;

  public function __construct(private readonly KeyValueExpirableFactoryInterface $keyValueFactory) {
  }

  /**
   * Takes an email for processing.
   *
   * @return bool
   *   TRUE when the caller must queue it; FALSE when it is already queued,
   *   settled, or waiting for its next retry.
   */
  public function claim(array $account, array $message): bool {
    $key = self::key($account, $message);
    $entry = $this->store()->get($key);
    if ($entry === NULL) {
      return $this->store()->setWithExpireIfNotExists($key, ['state' => 'queued', 'attempts' => 0], self::CLAIM_TTL);
    }
    if (($entry['state'] ?? '') === 'retry' && time() >= (int) ($entry['retry_after'] ?? 0)) {
      $this->store()->setWithExpire($key, ['state' => 'queued'] + $entry, self::CLAIM_TTL);
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Records that an email is settled (processed, rejected or abandoned).
   */
  public function done(array $account, array $message, string $outcome): void {
    $key = self::key($account, $message);
    $entry = (array) $this->store()->get($key);
    $this->store()->setWithExpire($key, ['state' => $outcome, 'attempts' => (int) ($entry['attempts'] ?? 0), 'at' => time()], self::DONE_TTL);
  }

  /**
   * Records a failed attempt.
   *
   * @return bool
   *   TRUE when it will be retried after RETRY_DELAY; FALSE when it reached
   *   MAX_ATTEMPTS and is now recorded as abandoned.
   */
  public function failed(array $account, array $message): bool {
    $key = self::key($account, $message);
    $attempts = (int) (($this->store()->get($key) ?? [])['attempts'] ?? 0) + 1;
    if ($attempts >= self::MAX_ATTEMPTS) {
      $this->store()->setWithExpire($key, ['state' => 'abandoned', 'attempts' => $attempts, 'at' => time()], self::DONE_TTL);
      return FALSE;
    }
    $this->store()->setWithExpire($key, ['state' => 'retry', 'attempts' => $attempts, 'retry_after' => time() + self::RETRY_DELAY], self::DONE_TTL);
    return TRUE;
  }

  /**
   * The registry entry of an email, or NULL when it was never claimed.
   */
  public function get(array $account, array $message): ?array {
    return $this->store()->get(self::key($account, $message));
  }

  public static function key(array $account, array $message): string {
    $id = trim((string) ($message['headers']['message_id'] ?? $message['headers']['internetMessageId'] ?? ''), " \t<>");
    if ($id === '') {
      $id = 'uid:' . ($message['raw']['folder'] ?? '') . ':' . ($message['id'] ?? '');
    }
    // Hashed: Message-IDs can exceed the 128 characters of the key column.
    return (string) ($account['id'] ?? $account['mailbox'] ?? '') . '|' . hash('sha256', mb_strtolower($id));
  }

  private function store(): KeyValueStoreExpirableInterface {
    return $this->keyValueFactory->get(self::COLLECTION);
  }

}
