<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Service;

use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Low-level Microsoft Graph client for mail automation.
 */
final class MicrosoftGraphService {

  private const GRAPH = 'https://graph.microsoft.com/v1.0';

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly LoggerInterface $logger,
  ) {
  }

  public function fetchMessages(array $account, int $limit = 25): array {
    $mailbox = rawurlencode((string) ($account['mailbox'] ?: 'me'));
    $folder = rawurlencode((string) ($account['folder'] ?: 'Inbox'));
    $token = $this->accessToken($account);
    $url = self::GRAPH . "/users/{$mailbox}/mailFolders/{$folder}/messages";
    $response = $this->httpClient->request('GET', $url, [
      'headers' => ['Authorization' => "Bearer {$token}"],
      'query' => [
        '$top' => $limit,
        '$orderby' => 'receivedDateTime desc',
        '$filter' => 'isRead eq false and hasAttachments eq true',
        '$select' => 'id,subject,from,toRecipients,ccRecipients,receivedDateTime,internetMessageId,hasAttachments',
      ],
      'timeout' => 30,
    ]);

    $payload = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    return array_map(static fn(array $message): array => [
      'id' => $message['id'],
      'provider' => 'microsoft_graph',
      'subject' => $message['subject'] ?? '',
      'from' => $message['from']['emailAddress']['address'] ?? '',
      'received' => $message['receivedDateTime'] ?? '',
      'headers' => ['internetMessageId' => $message['internetMessageId'] ?? ''],
      'raw' => $message,
    ], $payload['value'] ?? []);
  }

  public function downloadAttachments(array $account, array $message): array {
    $mailbox = rawurlencode((string) ($account['mailbox'] ?: 'me'));
    $message_id = rawurlencode((string) $message['id']);
    $token = $this->accessToken($account);
    $response = $this->httpClient->request('GET', self::GRAPH . "/users/{$mailbox}/messages/{$message_id}/attachments", [
      'headers' => ['Authorization' => "Bearer {$token}"],
      'timeout' => 30,
    ]);
    $payload = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    $attachments = [];
    foreach ($payload['value'] ?? [] as $attachment) {
      if (($attachment['@odata.type'] ?? '') !== '#microsoft.graph.fileAttachment') {
        continue;
      }
      $attachments[] = [
        'name' => $attachment['name'] ?? 'attachment.bin',
        'mime' => $attachment['contentType'] ?? 'application/octet-stream',
        'content' => base64_decode((string) ($attachment['contentBytes'] ?? ''), TRUE) ?: '',
      ];
    }
    return $attachments;
  }

  public function markRead(array $account, string $message_id): void {
    $mailbox = rawurlencode((string) ($account['mailbox'] ?: 'me'));
    $this->httpClient->request('PATCH', self::GRAPH . "/users/{$mailbox}/messages/" . rawurlencode($message_id), [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->accessToken($account),
        'Content-Type' => 'application/json',
      ],
      'json' => ['isRead' => TRUE],
      'timeout' => 15,
    ]);
  }

  public function moveMessage(array $account, string $message_id, string $folder): void {
    if ($folder === '') {
      return;
    }
    $mailbox = rawurlencode((string) ($account['mailbox'] ?: 'me'));
    $this->httpClient->request('POST', self::GRAPH . "/users/{$mailbox}/messages/" . rawurlencode($message_id) . '/move', [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->accessToken($account),
        'Content-Type' => 'application/json',
      ],
      'json' => ['destinationId' => $folder],
      'timeout' => 15,
    ]);
  }

  private function accessToken(array $account): string {
    foreach (['tenant_id', 'client_id', 'client_secret'] as $required) {
      if (empty($account[$required])) {
        throw new \InvalidArgumentException(sprintf('La cuenta de Microsoft Graph no tiene configurado el dato requerido: %s.', $required));
      }
    }
    $response = $this->httpClient->request('POST', 'https://login.microsoftonline.com/' . rawurlencode((string) $account['tenant_id']) . '/oauth2/v2.0/token', [
      'form_params' => [
        'client_id' => $account['client_id'],
        'client_secret' => $account['client_secret'],
        'scope' => 'https://graph.microsoft.com/.default',
        'grant_type' => 'client_credentials',
      ],
      'timeout' => 20,
    ]);
    $payload = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    if (empty($payload['access_token'])) {
      $this->logger->error('[Aseguramiento] La respuesta de Microsoft Graph no incluyó token de acceso.');
      throw new \RuntimeException('Falló la autenticación con Microsoft Graph.');
    }
    return (string) $payload['access_token'];
  }

}
