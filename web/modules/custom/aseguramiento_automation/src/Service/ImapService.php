<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Service;

use Psr\Log\LoggerInterface;

/**
 * Legacy IMAP fallback implementation.
 */
final class ImapService {

  public function __construct(private readonly LoggerInterface $logger) {
  }

  public function fetchMessages(array $account, int $limit = 25): array {
    if (!function_exists('imap_open')) {
      throw new \RuntimeException('The PHP IMAP extension is not installed.');
    }

    $mailbox = $this->mailboxString($account);
    $connection = @imap_open($mailbox, (string) $account['username'], (string) $account['password']);
    if (!$connection) {
      throw new \RuntimeException('Unable to open IMAP mailbox: ' . imap_last_error());
    }

    $ids = array_slice(imap_search($connection, 'UNSEEN') ?: [], 0, $limit);
    $messages = [];
    foreach ($ids as $number) {
      $overview = imap_fetch_overview($connection, (string) $number, 0)[0] ?? NULL;
      if (!$overview) {
        continue;
      }
      $messages[] = [
        'id' => (string) $number,
        'provider' => 'imap',
        'subject' => $this->decodeMimeHeader((string) ($overview->subject ?? '')),
        'from' => $this->decodeMimeHeader((string) ($overview->from ?? '')),
        'received' => $overview->date ?? '',
        'headers' => ['message_id' => $overview->message_id ?? ''],
        'raw' => ['number' => $number],
      ];
    }
    imap_close($connection);
    return $messages;
  }

  public function downloadAttachments(array $account, array $message): array {
    $connection = @imap_open($this->mailboxString($account), (string) $account['username'], (string) $account['password']);
    if (!$connection) {
      throw new \RuntimeException('Unable to open IMAP mailbox: ' . imap_last_error());
    }
    $number = (int) $message['id'];
    $structure = imap_fetchstructure($connection, $number);
    $attachments = [];
    $this->collectParts($connection, $number, $structure, '', $attachments);
    imap_close($connection);
    return $attachments;
  }

  public function markProcessed(array $account, array $message): void {
    $connection = @imap_open($this->mailboxString($account), (string) $account['username'], (string) $account['password']);
    if ($connection) {
      imap_setflag_full($connection, (string) $message['id'], '\\Seen');
      imap_close($connection);
    }
  }

  public function moveMessage(array $account, array $message, string $folder): void {
    if ($folder === '') {
      return;
    }
    $connection = @imap_open($this->mailboxString($account), (string) $account['username'], (string) $account['password']);
    if ($connection) {
      imap_mail_move($connection, (string) $message['id'], $folder);
      imap_expunge($connection);
      imap_close($connection);
    }
  }

  private function mailboxString(array $account): string {
    $flags = match ($account['imap_encryption'] ?? 'ssl') {
      'tls' => '/tls',
      'none' => '/novalidate-cert',
      default => '/ssl',
    };
    return sprintf('{%s:%d/imap%s}%s', $account['imap_host'], (int) ($account['imap_port'] ?? 993), $flags, $account['folder'] ?? 'INBOX');
  }

  private function collectParts($connection, int $number, object $part, string $prefix, array &$attachments): void {
    foreach (($part->parts ?? []) as $index => $subpart) {
      $part_number = $prefix === '' ? (string) ($index + 1) : $prefix . '.' . ($index + 1);
      $filename = $this->filename($subpart);
      if ($filename !== '') {
        $body = imap_fetchbody($connection, $number, $part_number);
        if (($subpart->encoding ?? 0) === ENCBASE64) {
          $body = base64_decode($body, TRUE) ?: '';
        }
        elseif (($subpart->encoding ?? 0) === ENCQUOTEDPRINTABLE) {
          $body = quoted_printable_decode($body);
        }
        $attachments[] = [
          'name' => $filename,
          'mime' => 'application/octet-stream',
          'content' => $body,
        ];
      }
      if (!empty($subpart->parts)) {
        $this->collectParts($connection, $number, $subpart, $part_number, $attachments);
      }
    }
  }

  private function filename(object $part): string {
    foreach (['dparameters', 'parameters'] as $property) {
      foreach (($part->{$property} ?? []) as $parameter) {
        if (strtolower((string) $parameter->attribute) === 'filename' || strtolower((string) $parameter->attribute) === 'name') {
          return $this->decodeMimeHeader((string) $parameter->value);
        }
      }
    }
    return '';
  }

  private function decodeMimeHeader(string $value): string {
    $decoded = iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
    if ($decoded !== FALSE && $decoded !== '') {
      return trim($decoded);
    }
    return trim(imap_utf8($value));
  }

}
