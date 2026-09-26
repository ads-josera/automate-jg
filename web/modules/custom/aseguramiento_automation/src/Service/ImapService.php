<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Service;

use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\MessageInterface;
use Drupal\Core\State\StateInterface;
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
 *
 * Which emails are new does not depend on the "seen" flag alone (anyone
 * reading the request in webmail first used to hide it): every email that
 * arrived after the folder was first read is listed, read or not, and
 * ProcessedMailRegistry keeps each one from being queued twice. The UID the
 * folder had on that first read is kept in state, so emails that were
 * already in the mailbox (old tests, already answered requests) are never
 * picked up.
 */
final class ImapService {

  /**
   * State entry with the UID marks of every folder read, per account.
   */
  private const UID_MARKS = 'aseguramiento_automation.imap_uid_marks';

  /**
   * Read emails are only listed within this window (unread ones always are).
   */
  public const RECENT_DAYS = 60;

  /**
   * Open mailbox connections for the current process, keyed per account.
   *
   * The drush runner polls and then drains every queue in the same process,
   * so reusing one connection per account avoids a login per operation.
   *
   * @var array<string, \DirectoryTree\ImapEngine\Mailbox>
   */
  private array $mailboxes = [];

  public function __construct(
    private readonly LoggerInterface $logger,
    private readonly StateInterface $state,
  ) {
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

    // Unread emails, as always, plus read ones that arrived after the first
    // run: someone may have opened a request in webmail before this runs.
    $mark = $this->uidMark($account, $folder, FALSE);
    $found = [];
    foreach ($folder->messages()->unseen()->withHeaders()->oldest()->limit($limit)->get() as $message) {
      $found[$message->uid()] = $message;
    }
    if ($mark !== NULL) {
      // Wider than $limit: settled emails that could not be moved out of the
      // inbox are listed again (the registry skips them) and must not use up
      // the places of new ones.
      $recent = $folder->messages()->uid($mark, INF)->since(new \DateTimeImmutable('-' . self::RECENT_DAYS . ' days'))->withHeaders()->oldest()->limit(max(200, $limit))->get();
      foreach ($recent as $message) {
        // "N:*" also matches the last email when every UID is below N.
        if ($message->uid() >= $mark) {
          $found[$message->uid()] = $message;
        }
      }
    }
    // Oldest first, so requests are answered in the order they arrived.
    ksort($found);

    $messages = [];
    foreach ($found as $message) {
      $messages[] = $this->describe($message, $folder);
    }
    return $messages;
  }

  /**
   * Moves requests that landed in the spam folders back to the inbox.
   *
   * Only emails that arrived in those folders after they were first read
   * are looked at, each one once. The criteria decides from the subject,
   * sender and attachment names (no download); everything else stays in
   * spam untouched. A rescued email is then read from the inbox like any
   * other.
   *
   * @param callable(array): bool $isRequest
   *   Receives the message description plus "attachment_names".
   *
   * @return int
   *   Number of emails moved to the inbox.
   */
  public function rescueFromSpam(array $account, callable $isRequest, int $limit = 50): int {
    $names = array_filter(array_map('trim', explode(',', (string) ($account['spam_folders'] ?? ''))));
    if ($names === []) {
      return 0;
    }
    $mailbox = $this->mailbox($account);
    $source = $this->sourceFolder($account);
    $rescued = 0;
    foreach ($names as $name) {
      $folder = $this->findFolder($mailbox, $name);
      if (!$folder || $folder->path() === $source->path()) {
        continue;
      }
      $mark = $this->uidMark($account, $folder, TRUE);
      if ($mark === NULL) {
        continue;
      }
      $next = $mark;
      $found = $folder->messages()->uid($mark, INF)->withHeaders()->withBodyStructure()->oldest()->limit($limit)->get();
      foreach ($found as $message) {
        $uid = $message->uid();
        if ($uid < $mark) {
          continue;
        }
        $attachment_names = array_values(array_filter(array_map(
          static fn($part): string => (string) $part->filename(),
          $message->bodyStructure()?->attachments() ?? [],
        )));
        $description = $this->describe($message, $folder) + ['attachment_names' => $attachment_names];
        if ($isRequest($description)) {
          try {
            $folder->messages()->uid($uid)->move($source->path(), TRUE);
          }
          catch (\Throwable $e) {
            // Keep the mark on it, so the next run tries again.
            $this->logger->warning('[Aseguramiento] No fue posible sacar de "@folder" la solicitud de @from. Detalle: @error', [
              '@folder' => $folder->path(),
              '@from' => $description['from'],
              '@error' => $e->getMessage(),
            ]);
            break;
          }
          $rescued++;
          $this->logger->notice('[Aseguramiento] Solicitud rescatada de la carpeta "@folder" y movida a la bandeja de entrada. Remitente: @from. Asunto: @subject.', [
            '@folder' => $folder->path(),
            '@from' => $description['from'],
            '@subject' => $description['subject'],
          ]);
        }
        $next = $uid + 1;
      }
      if ($next > $mark) {
        $this->saveUidMark($account, $folder, $next);
      }
    }
    return $rescued;
  }

  public function downloadAttachments(array $account, array $message): array {
    // MIME parsing needs the headers too: they declare the multipart boundary.
    $found = $this->messageFolder($account, $message)->messages()->withHeaders()->withBody()->find($this->uid($message));
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
    $this->messageFolder($account, $message)->messages()->uid($this->uid($message))->markRead();
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
      $this->messageFolder($account, $message)->messages()->uid($this->uid($message))->move($destination->path(), TRUE);
    }
    catch (\Throwable $e) {
      $this->logger->warning('[Aseguramiento] No fue posible mover el correo @id a la carpeta "@folder". Detalle: @error', [
        '@id' => $message['id'] ?? '',
        '@folder' => $destination->path(),
        '@error' => $e->getMessage(),
      ]);
    }
  }

  private function describe(MessageInterface $message, FolderInterface $folder): array {
    $from = $message->from();
    return [
      'id' => (string) $message->uid(),
      'provider' => 'imap',
      'subject' => (string) $message->subject(),
      'from' => $from ? $from->email() : '',
      'received' => $message->date()?->toIso8601String() ?? '',
      'headers' => ['message_id' => (string) $message->messageId()],
      'raw' => ['uid' => $message->uid(), 'folder' => $folder->path()],
    ];
  }

  /**
   * First UID to look at in a folder, or NULL on its first read.
   *
   * On the first read (or when the server renumbered the folder, which
   * changes UIDVALIDITY) the folder's next UID is stored and NULL returned:
   * what is already there is left alone.
   *
   * @param bool $advancing
   *   FALSE keeps the first mark forever (inbox: read emails after it are
   *   listed on every run); TRUE for marks that saveUidMark() moves forward
   *   (spam folders: each email is looked at once).
   */
  private function uidMark(array $account, FolderInterface $folder, bool $advancing): ?int {
    $status = $folder->status();
    $validity = (int) ($status['UIDVALIDITY'] ?? 0);
    $key = $this->markKey($account, $folder);
    $marks = $this->state->get(self::UID_MARKS, []);
    $mark = $marks[$key] ?? NULL;
    if ($mark !== NULL && (int) $mark['validity'] === $validity) {
      return (int) $mark['uid'];
    }
    $marks[$key] = ['validity' => $validity, 'uid' => (int) ($status['UIDNEXT'] ?? 1), 'advancing' => $advancing];
    $this->state->set(self::UID_MARKS, $marks);
    $this->logger->info('[Aseguramiento] Primera lectura de la carpeta "@folder" del buzón @account: los correos que ya estaban no se procesan.', [
      '@folder' => $folder->path(),
      '@account' => $account['id'] ?? '',
    ]);
    return NULL;
  }

  private function saveUidMark(array $account, FolderInterface $folder, int $uid): void {
    $marks = $this->state->get(self::UID_MARKS, []);
    $key = $this->markKey($account, $folder);
    if (isset($marks[$key])) {
      $marks[$key]['uid'] = $uid;
      $this->state->set(self::UID_MARKS, $marks);
    }
  }

  private function markKey(array $account, FolderInterface $folder): string {
    return implode('|', [$account['id'] ?? '', $account['imap_host'] ?? '', $account['username'] ?? '', $folder->path()]);
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
   * Folder the email was listed from (queue items carry it in raw.folder).
   */
  private function messageFolder(array $account, array $message): FolderInterface {
    $path = (string) ($message['raw']['folder'] ?? '');
    if ($path === '') {
      return $this->sourceFolder($account);
    }
    return $this->findFolder($this->mailbox($account), $path)
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
    $folder = $inbox ? $mailbox->folders()->find('INBOX' . $inbox->delimiter() . $path) : NULL;
    if ($folder) {
      return $folder;
    }
    // Webmail clients differ in case ("spam", "Spam", "Junk", "junk").
    foreach ($mailbox->folders()->get() as $candidate) {
      $name = $inbox ? preg_replace('/^INBOX' . preg_quote($inbox->delimiter(), '/') . '/i', '', $candidate->path()) : $candidate->path();
      if (strcasecmp($candidate->path(), $path) === 0 || strcasecmp((string) $name, $path) === 0) {
        return $candidate;
      }
    }
    return NULL;
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
