<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Plugin\QueueWorker;

use Drupal\aseguramiento_automation\Entity\ConstanciaEntity;
use Drupal\aseguramiento_automation\Service\ExcelParserService;
use Drupal\aseguramiento_automation\Service\QueueManagerService;
use Drupal\aseguramiento_automation\Service\ValidationService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Parses Excel rows and creates auditable constancia records.
 *
 * @QueueWorker(
 *   id = "aseguramiento_excel_parsing",
 *   title = @Translation("Aseguramiento Excel parsing"),
 *   cron = {"time" = 90}
 * )
 */
final class ExcelParsingQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly FileSystemInterface $fileSystem,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ExcelParserService $excelParser,
    private readonly ValidationService $validationService,
    private readonly QueueManagerService $queueManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('file_system'),
      $container->get('entity_type.manager'),
      $container->get('aseguramiento_automation.excel_parser'),
      $container->get('aseguramiento_automation.validation'),
      $container->get('aseguramiento_automation.queue_manager'),
    );
  }

  public function processItem($data): void {
    $file = (array) ($data['file'] ?? []);
    $real_path = $this->fileSystem->realpath((string) ($file['uri'] ?? ''));
    if (!$real_path) {
      throw new \RuntimeException('Excel file path could not be resolved.');
    }
    $storage = $this->entityTypeManager->getStorage('aseguramiento_constancia');
    foreach ($this->excelParser->parse($real_path) as $row) {
      $account = (array) ($data['account'] ?? []);
      $message = (array) ($data['message'] ?? []);
      $validation = $this->validationService->validateRow($row);
      $values = [
        'folio' => $this->folio($row),
        'nombre' => $row['nombre'] ?? $row['solicitante'] ?? $row['beneficiario_nombre'] ?? '',
        'poliza' => $row['poliza'] ?? '',
        'suma_asegurada' => $this->amount($row['suma_asegurada'] ?? ''),
        'vigencia' => [
          'value' => $this->date($row['vigencia_inicio'] ?? ''),
          'end_value' => $this->date($row['vigencia_fin'] ?? ''),
        ],
        'rfc' => strtoupper((string) ($row['rfc'] ?? '')),
        'email' => $this->recipientEmail($row['email'] ?? '', $message['from'] ?? ''),
        'telefono' => $row['telefono'] ?? $row['solicitante_telefono'] ?? '',
        'aseguradora' => $row['aseguradora'] ?? ($account['insurer'] ?? ''),
        'tipo_documento' => $row['tipo_documento'] ?? '',
        'company_id' => $account['company_id'] ?? '',
        'status' => $validation['valid'] ? 'validated' : 'error',
        'correo_origen' => $message['id'] ?? '',
        'excel_original' => $file['uri'] ?? '',
        'provider_correo' => $account['provider'] ?? '',
        'metadata' => ['source_row' => $row, 'message' => $message],
        'errores' => $validation['valid'] ? '' : json_encode($validation['errors'], JSON_UNESCAPED_UNICODE),
      ];
      $values += $this->solicitudValues($row);
      $entity = $storage->create($values);
      $entity->save();
      if ($validation['valid']) {
        $this->queueManager->enqueue(QueueManagerService::PDF_QUEUE, ['constancia_id' => $entity->id()]);
      }
    }
  }

  private function folio(array $row): string {
    return 'AA-' . date('Ymd') . '-' . substr(hash('sha256', implode('|', [$row['poliza'] ?? '', $row['_sheet'] ?? '', $row['_row'] ?? '', microtime(TRUE)])), 0, 10);
  }

  private function amount(mixed $value): ?string {
    $amount = str_replace([',', '$', ' '], '', (string) $value);
    return is_numeric($amount) ? number_format((float) $amount, 2, '.', '') : NULL;
  }

  private function date(mixed $value): ?string {
    $timestamp = strtotime((string) $value);
    return $timestamp ? date('Y-m-d', $timestamp) : NULL;
  }

  private function solicitudValues(array $row): array {
    $values = [];
    foreach (array_keys(ConstanciaEntity::solicitudStringFields() + ConstanciaEntity::solicitudLongTextFields()) as $field) {
      $values[$field] = $row[$field] ?? '';
    }
    foreach (array_keys(ConstanciaEntity::solicitudDateFields()) as $field) {
      $values[$field] = $this->date($row[$field] ?? '');
    }
    foreach (array_keys(ConstanciaEntity::solicitudAmountFields()) as $field) {
      $values[$field] = $this->amount($row[$field] ?? '');
    }
    $values['mercancia_estado'] = $this->normalizeOption($row['mercancia_estado'] ?? '', ['nueva', 'usada']);
    $values['moneda'] = $this->normalizeOption($row['moneda'] ?? '', ['usd', 'pesos']);
    $values['acepta_informacion_veridica'] = $this->truthy($row['acepta_informacion_veridica'] ?? FALSE);
    return $values;
  }

  private function normalizeOption(mixed $value, array $allowed): ?string {
    $normalized = mb_strtolower(trim((string) $value));
    $normalized = str_replace(['ú', 'ü'], 'u', $normalized);
    return in_array($normalized, $allowed, TRUE) ? $normalized : NULL;
  }

  private function truthy(mixed $value): bool {
    return in_array(mb_strtolower(trim((string) $value)), ['1', 'si', 'sí', 'yes', 'true', 'x', 'acepto'], TRUE);
  }

  private function senderEmail(mixed $from): string {
    $from = (string) $from;
    if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $from, $matches)) {
      return $matches[0];
    }
    return '';
  }

  private function recipientEmail(mixed $email, mixed $from): string {
    $email = trim((string) $email);
    return $email !== '' ? $email : $this->senderEmail($from);
  }

}
