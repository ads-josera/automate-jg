<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Service;

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Normalizes inbound messages and persists accepted attachments.
 */
final class EmailParserService {

  private const EXCEL_MIME_TYPES = [
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-excel.sheet.macroEnabled.12',
    'application/vnd.ms-excel',
    'application/octet-stream',
  ];

  private const PDF_MIME_TYPES = [
    'application/pdf',
    'application/acrobat',
    'application/x-pdf',
    'applications/vnd.pdf',
    'application/octet-stream',
    'text/pdf',
    'text/x-pdf',
  ];

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    private readonly FileRepositoryInterface $fileRepository,
    private readonly LoggerInterface $logger,
  ) {
  }

  public function filterMessage(array $message, array $settings): array {
    $errors = [];
    $from = strtolower((string) ($message['from'] ?? ''));
    $domain = substr(strrchr($from, '@') ?: '', 1);
    $allowed_domains = array_map('strtolower', (array) ($settings['allowed_sender_domains'] ?? []));
    if ($allowed_domains && !in_array($domain, $allowed_domains, TRUE)) {
      $errors[] = sprintf('Sender domain "%s" is not allowed.', $domain);
    }

    $keywords = array_filter(array_map(fn(string $keyword): string => $this->normalizeText($keyword), (array) ($settings['required_subject_keywords'] ?? [])));
    $subject = $this->normalizeText((string) ($message['subject'] ?? ''));
    if ($keywords && !$this->containsAny($subject, $keywords)) {
      $errors[] = sprintf('El asunto "%s" no contiene una palabra requerida.', (string) ($message['subject'] ?? ''));
    }

    return ['accepted' => $errors === [], 'errors' => $errors];
  }

  public function persistProcessableAttachments(array $attachments, string $account_id): array {
    $directory = 'private://aseguramiento/inbound/' . preg_replace('/[^a-z0-9_]+/i', '_', $account_id);
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $files = [];

    foreach ($attachments as $attachment) {
      $name = (string) ($attachment['name'] ?? '');
      $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
      $mime = (string) ($attachment['mime'] ?? '');
      $type = match (TRUE) {
        in_array($extension, ['xls', 'xlsx', 'xlsm'], TRUE) && in_array($mime, self::EXCEL_MIME_TYPES, TRUE) => 'excel',
        $extension === 'pdf' && in_array($mime, self::PDF_MIME_TYPES, TRUE) => 'pdf',
        default => '',
      };
      if ($type === '') {
        continue;
      }
      $safe_name = preg_replace('/[^a-zA-Z0-9._-]+/', '_', basename($name)) ?: 'attachment.' . $extension;
      $this->logger->info('[Aseguramiento] Procesando archivo: @file', ['@file' => $safe_name]);
      $file = $this->fileRepository->writeData((string) $attachment['content'], "{$directory}/{$safe_name}", FileExists::Rename);
      $file->setPermanent();
      $file->save();
      $files[] = [
        'fid' => $file->id(),
        'uri' => $file->getFileUri(),
        'name' => $file->getFilename(),
        'type' => $type,
      ];
    }

    if (!$files) {
      $this->logger->warning('[Aseguramiento] No se encontraron archivos Excel o PDF rellenable válidos para procesar en la cuenta @account.', ['@account' => $account_id]);
    }
    return $files;
  }

  public function persistExcelAttachments(array $attachments, string $account_id): array {
    return array_values(array_filter(
      $this->persistProcessableAttachments($attachments, $account_id),
      static fn(array $file): bool => ($file['type'] ?? '') === 'excel',
    ));
  }

  private function containsAny(string $subject, array $keywords): bool {
    foreach ($keywords as $keyword) {
      if ($keyword !== '' && str_contains($subject, $keyword)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  private function normalizeText(string $value): string {
    $value = mb_strtolower(trim($value));
    $value = strtr($value, [
      'á' => 'a',
      'é' => 'e',
      'í' => 'i',
      'ó' => 'o',
      'ú' => 'u',
      'ü' => 'u',
      'ñ' => 'n',
    ]);
    return preg_replace('/\s+/', ' ', $value) ?: '';
  }

}
