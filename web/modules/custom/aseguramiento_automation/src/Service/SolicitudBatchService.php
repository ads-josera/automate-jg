<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Service;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Psr\Log\LoggerInterface;

/**
 * Tracks every inbound email as one batch ("lote") of request files.
 *
 * A client may attach several Excel/PDF files to one email and must get ONE
 * reply with every generated PDF plus what to fix in the files that failed.
 * The batch records which files are still being read, so the reply is only
 * sent once every file is settled.
 *
 * Batches are coordination data, not the audit record: constancias keep the
 * batch id in their "lote" field. Entries expire after self::TTL.
 */
final class SolicitudBatchService {

  private const COLLECTION = 'aseguramiento_automation.lote';

  private const TTL = 30 * 86400;

  public function __construct(
    private readonly KeyValueExpirableFactoryInterface $keyValueFactory,
    private readonly LockBackendInterface $lock,
    private readonly UuidInterface $uuid,
    private readonly QueueManagerService $queueManager,
    private readonly LoggerInterface $logger,
  ) {
  }

  /**
   * Registers an inbound email and its processable files.
   *
   * @param array $files
   *   Persisted files, each with at least "name"; keyed in the batch by the
   *   value returned by fileKey().
   *
   * @return string
   *   The batch id to carry in every queue item of this email.
   */
  public function start(array $account, array $message, array $files): string {
    $id = $this->uuid->generate();
    $batch = [
      'id' => $id,
      'account_id' => (string) ($account['id'] ?? ''),
      'from' => (string) ($message['from'] ?? ''),
      'subject' => (string) ($message['subject'] ?? ''),
      'message_id' => (string) ($message['id'] ?? ''),
      'files' => [],
      'reply_queued' => FALSE,
      'replied' => FALSE,
      'send_attempts' => 0,
      'created' => time(),
    ];
    foreach (array_values($files) as $index => $file) {
      $batch['files'][self::fileKey($file, $index)] = [
        'name' => (string) ($file['name'] ?? ''),
        'state' => 'pending',
        'error' => '',
        'constancias' => [],
      ];
    }
    $this->store()->setWithExpire($id, $batch, self::TTL);
    return $id;
  }

  /**
   * Stable key of a file inside its batch.
   */
  public static function fileKey(array $file, int $index): string {
    return isset($file['fid']) ? 'fid:' . $file['fid'] : 'n:' . $index;
  }

  public function get(string $id): ?array {
    $batch = $this->store()->get($id);
    return is_array($batch) ? $batch : NULL;
  }

  /**
   * Records the constancias created from one file.
   */
  public function fileDone(string $id, string $file_key, array $constancia_ids): void {
    $this->settleFile($id, $file_key, 'done', '', $constancia_ids);
  }

  /**
   * Records a file that could not be read at all.
   */
  public function fileFailed(string $id, string $file_key, string $error): void {
    $this->settleFile($id, $file_key, 'error', $error, []);
  }

  public function allFilesSettled(array $batch): bool {
    foreach ($batch['files'] as $file) {
      if ($file['state'] === 'pending') {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Marks the batch as answered. Returns FALSE if it already was.
   */
  public function markReplied(string $id): bool {
    return (bool) $this->update($id, static function (array &$batch): bool {
      if ($batch['replied']) {
        return FALSE;
      }
      $batch['replied'] = TRUE;
      return TRUE;
    });
  }

  /**
   * Counts a failed delivery and returns the attempts made so far.
   */
  public function countSendAttempt(string $id): int {
    return (int) $this->update($id, static function (array &$batch): int {
      return ++$batch['send_attempts'];
    });
  }

  private function settleFile(string $id, string $file_key, string $state, string $error, array $constancia_ids): void {
    $queue_reply = $this->update($id, function (array &$batch) use ($file_key, $state, $error, $constancia_ids): bool {
      if (!isset($batch['files'][$file_key])) {
        $this->logger->warning('[Aseguramiento] El archivo @file no pertenece al lote @lote.', ['@file' => $file_key, '@lote' => $batch['id']]);
        return FALSE;
      }
      $batch['files'][$file_key]['state'] = $state;
      $batch['files'][$file_key]['error'] = $error;
      $batch['files'][$file_key]['constancias'] = array_values(array_map('intval', $constancia_ids));
      // The last file to settle queues the single reply of the batch.
      if (!$batch['reply_queued'] && $this->allFilesSettled($batch)) {
        $batch['reply_queued'] = TRUE;
        return TRUE;
      }
      return FALSE;
    });
    if ($queue_reply) {
      $this->queueManager->enqueue(QueueManagerService::MAIL_QUEUE, ['lote' => $id]);
    }
  }

  /**
   * Read-modify-write of a batch under a lock.
   *
   * @return mixed
   *   Whatever the callback returns, or NULL if the batch does not exist.
   */
  private function update(string $id, callable $callback): mixed {
    $lock_name = 'aseguramiento_lote:' . $id;
    if (!$this->lock->acquire($lock_name, 10.0)) {
      $this->lock->wait($lock_name, 10);
      if (!$this->lock->acquire($lock_name, 10.0)) {
        throw new \RuntimeException(sprintf('No fue posible bloquear el lote %s.', $id));
      }
    }
    try {
      $batch = $this->get($id);
      if ($batch === NULL) {
        $this->logger->warning('[Aseguramiento] El lote @lote no existe o ya expiró.', ['@lote' => $id]);
        return NULL;
      }
      $result = $callback($batch);
      $this->store()->setWithExpire($id, $batch, self::TTL);
      return $result;
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  private function store(): KeyValueStoreExpirableInterface {
    return $this->keyValueFactory->get(self::COLLECTION);
  }

}
