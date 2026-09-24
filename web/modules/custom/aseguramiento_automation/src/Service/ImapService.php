<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Service;

use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\MessageInterface;
use Psr\Log\LoggerInterface;

/**
 * IMAP provider implementation on top of a pure-PHP IMAP client.
 *
 * Uses DirectoryTree ImapEngine instead of the PHP IMAP extension, which was
 * removed from PHP core in 8.4 and whose underlying c-client library is no
 * longer maintained.
 *
 * Message ids handed to the queues are IMAP UIDs, never sequence numbers:
 * sequence numbers shift as soon as another message is moved and expunged,
 * which made a queued id point at a different customer's email.
 */
final class ImapService {

  /**
   * Open mailbox connections for the current process, keyed per account.
   *
   * The drush runner polls and then drains every queue in the same process,
   * so reusing one connection per account avoids a login per operation.
   *
   * @var array<string, \DirectoryTree\ImapEngine\Mailbox>
   */
  private array $mailboxes = [];

  public function __construct(private readonly LoggerInterface $logger) {
  }

  public function __destruct() {
    foreach ($this->mailboxes as $mailbox) {
      try {
        $mailbox->disconnect();
      }
      catch (\Throwable) {
        // The server may already have closed the connection.
      }
    }
  }

  public function fetchMessages(array $account, int $limit = 25): array {
    $account_id = (string) ($account['id'] ?? $account['mailbox'] ?? $account['username'] ?? 'desconocido');
    $this->logger->info('[Aseguramiento] Iniciando conexión al buzón @account.', ['@account' => $account_id]);
    try {
      $folder = $this->sourceFolder($account);
    }
    catch (\Throwable $e) {
      $this->logger->error('[Aseguramiento] Error al conectar con el servidor IMAP. Detalle: @error', ['@error' => $e->getMessage()]);
      throw new \RuntimeException('No fue posible abrir el buzón IMAP: ' . $e->getMessage(), 0, $e);
    }
    $this->logger->info('[Aseguramiento] Conexión IMAP establecida correctamente con el buzón @account.', ['@account' => $account_id]);

    // Oldest first, so requests are answered in the order they arrived.
    $found = $folder->messages()
      ->unseen()
      ->withHeaders()
      ->oldest()
      ->limit($limit)
      ->get();

    $messages = [];
    foreach ($found as $message) {
      $from = $message->from();
      $messages[] = [
        'id' => (string) $message->uid(),
        'provider' => 'imap',
        'subject' => (string) $message->subject(),
        'from' => $from ? $from->email() : '',
        'received' => $message->date()?->toIso8601String() ?? '',
        'headers' => ['message_id' => (string) $message->messageId()],
        'raw' => ['uid' => $message->uid(), 'folder' => $folder->path()],
      ];
    }
    return $messages;
  }

  public function downloadAttachments(array $account, array $message): array {
    // MIME parsing needs the headers too: they declare the multipart boundary.
    $found = $this->sourceFolder($account)->messages()->withHeaders()->withBody()->find($this->uid($message));
    if (!$found instanceof MessageInterface) {
      throw new \RuntimeException(sprintf('El correo con UID %s ya no está en el buzón IMAP.', $message['id'] ?? ''));
    }

    $attachments = [];
    foreach ($found->attachments() as $attachment) {
      $attachments[] = [
        'name' => (string) ($attachment->filename() ?? ''),
        // Attachments are accepted by extension; EmailParserService allows
        // octet-stream for every type. The declared MIME type varies between
        // mail clients (e.g. application/zip for .xlsx) and passing it through
        // would reject files that are accepted today.
        'mime' => 'application/octet-stream',
        'content' => $attachment->contents(),
      ];
    }

    // Mark as read as soon as it is downloaded, as the legacy c-client
    // implementation did implicitly. If processing fails later the message is
    // not polled again, so a failure can never re-send notifications or create
    // duplicate constancias every cron run; the error is logged instead.
    $found->markSeen();

    if ($attachments === []) {
      $this->logger->warning('[Aseguramiento] No se encontraron archivos adjuntos en el correo @id.', ['@id' => $message['id'] ?? '']);
    }
    else {
      $this->logger->info('[Aseguramiento] Se encontraron @count archivos adjuntos en el correo @id.', [
        '@count' => count($attachments),
        '@id' => $message['id'] ?? '',
      ]);
    }
    return $attachments;
  }

  public function markProcessed(array $account, array $message): void {
    $this->sourceFolder($account)->messages()->uid($this->uid($message))->markRead();
  }

  public function moveMessage(array $account, array $message, string $folder): void {
    if ($folder === '') {
      return;
    }
    $mailbox = $this->mailbox($account);
    $destination = $this->findFolder($mailbox, $folder);
    if (!$destination) {
      // The message is already marked as read, so it will not be processed
      // again; a missing folder must not turn a processed request into an
      // error.
      $this->logger->warning('[Aseguramiento] No existe la carpeta IMAP "@folder" en el buzón @account. El correo @id se queda en la bandeja marcado como leído.', [
        '@folder' => $folder,
        '@account' => $account['id'] ?? '',
        '@id' => $message['id'] ?? '',
      ]);
      return;
    }
    try {
      $this->sourceFolder($account)->messages()->uid($this->uid($message))->move($destination->path(), TRUE);
    }
    catch (\Throwable $e) {
      $this->logger->warning('[Aseguramiento] No fue posible mover el correo @id a la carpeta "@folder". Detalle: @error', [
        '@id' => $message['id'] ?? '',
        '@folder' => $destination->path(),
        '@error' => $e->getMessage(),
      ]);
    }
  }

  private function mailbox(array $account): Mailbox {
    $key = implode('|', [
      $account['imap_host'] ?? '',
      $account['imap_port'] ?? '',
      $account['username'] ?? '',
    ]);
    if (!isset($this->mailboxes[$key])) {
      [$encryption, $validate_cert] = $this->transport((string) ($account['imap_encryption'] ?? 'ssl'));
      $this->mailboxes[$key] = new Mailbox([
        'host' => (string) ($account['imap_host'] ?? ''),
        'port' => (int) ($account['imap_port'] ?? 993),
        'encryption' => $encryption,
        'validate_cert' => $validate_cert,
        'username' => (string) ($account['username'] ?? ''),
        'password' => (string) ($account['password'] ?? ''),
        'timeout' => 30,
      ]);
    }
    return $this->mailboxes[$key];
  }

  /**
   * Maps the stored account option to the client transport.
   *
   * Keeps the meaning the options had with c-client: "tls" was STARTTLS on the
   * plain port (ImapEngine's "tls" means implicit TLS), and "none" skipped
   * certificate validation.
   *
   * @return array{0: ?string, 1: bool}
   *   Encryption transport and whether to validate the certificate.
   */
  private function transport(string $option): array {
    return match ($option) {
      'tls' => ['starttls', TRUE],
      'none' => [NULL, FALSE],
      default => ['ssl', TRUE],
    };
  }

  private function sourceFolder(array $account): FolderInterface {
    $path = (string) ($account['folder'] ?? '') ?: 'INBOX';
    $mailbox = $this->mailbox($account);
    return $this->findFolder($mailbox, $path)
      ?? throw new \RuntimeException(sprintf('No existe la carpeta IMAP "%s".', $path));
  }

  /**
   * Finds a folder by its configured name.
   *
   * INBOX is case-insensitive by RFC 9051. Servers with an "INBOX."
   * namespace (Dovecot on cPanel) list custom folders as "INBOX.<name>", so
   * a plain "Processed" is also looked up under the inbox.
   */
  private function findFolder(Mailbox $mailbox, string $path): ?FolderInterface {
    if (strcasecmp($path, 'INBOX') === 0) {
      return $mailbox->inbox();
    }
    $folder = $mailbox->folders()->find($path);
    if ($folder) {
      return $folder;
    }
    $inbox = $mailbox->inbox();
    return $inbox ? $mailbox->folders()->find('INBOX' . $inbox->delimiter() . $path) : NULL;
  }

  private function uid(array $message): int {
    // Items queued by the c-client implementation carry a sequence number
    // (raw.number) in "id"; reading it as a UID would target another email.
    if (!isset($message['raw']['uid']) && isset($message['raw']['number'])) {
      throw new \InvalidArgumentException('El correo fue encolado con un número de secuencia IMAP (versión anterior) y no se puede identificar de forma segura.');
    }
    $uid = (int) ($message['raw']['uid'] ?? $message['id'] ?? 0);
    if ($uid <= 0) {
      throw new \InvalidArgumentException('El correo IMAP no tiene un UID válido.');
    }
    return $uid;
  }

}
