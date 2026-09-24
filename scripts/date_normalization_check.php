<?php

/**
 * @file
 * Checks how request dates typed in the Excel template end up stored.
 *
 * When the client's Excel locale does not recognise "05/09/2026" as a date,
 * the cell arrives as text. It must be read as day/month/year (the template
 * format is dd/mm/yyyy), never as the US month/day order.
 *
 * Runs the real Excel parsing queue worker on a filled copy of the template,
 * reads the stored constancia and deletes it afterwards.
 *
 * Usage (local DDEV only):
 * @code
 * ddev drush php:script scripts/date_normalization_check.php
 * @endcode
 */

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

$template = DRUPAL_ROOT . '/../docs/solicitud_aseguramiento_formato_jr_preparado.xlsx';
$base = [
  'C8' => 'Cliente prueba fechas', 'C12' => 'Beneficiario', 'D18' => 'Mercancia',
  'D24' => 'Origen', 'D25' => 'Destino', 'I23' => 'Terrestre',
  'D29' => 'PESOS', 'D30' => 1000, 'D32' => 1000, 'D54' => 'SI',
];
// Typed value in "Fecha inicio seguro" (D23) => expected stored date.
$cases = [
  'Fecha real de Excel' => [ExcelDate::PHPToExcel(new \DateTime('2026-09-05')), '2026-09-05'],
  'Texto 05/09/2026' => ['05/09/2026', '2026-09-05'],
  'Texto 25/09/2026' => ['25/09/2026', '2026-09-25'],
  'Texto 5/9/26' => ['5/9/26', '2026-09-05'],
  'Texto 05-09-2026' => ['05-09-2026', '2026-09-05'],
  'Texto 05.09.2026' => ['05.09.2026', '2026-09-05'],
  'Texto 5-sep-2026' => ['5-sep-2026', '2026-09-05'],
  'Texto 31/02/2026 (no existe)' => ['31/02/2026', NULL],
];

$storage = \Drupal::entityTypeManager()->getStorage('aseguramiento_constancia');
$worker = \Drupal::service('plugin.manager.queue_worker')->createInstance('aseguramiento_excel_parsing');
$fs = \Drupal::service('file_system');
$dir = 'private://aseguramiento/date-check';
$fs->prepareDirectory($dir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);
$failures = 0;

foreach ($cases as $label => [$typed, $expected]) {
  $book = IOFactory::load($template);
  $sheet = $book->getSheetByName('Solicitud');
  foreach ($base + ['D23' => $typed] as $ref => $value) {
    $sheet->setCellValue($ref, $value);
  }
  $uri = "{$dir}/check.xlsx";
  IOFactory::createWriter($book, 'Xlsx')->save($fs->realpath($uri));

  $before = (int) $storage->getQuery()->accessCheck(FALSE)->count()->execute();
  $max = (int) current($storage->getQuery()->accessCheck(FALSE)->sort('id', 'DESC')->range(0, 1)->execute() ?: [0]);
  // No PDF/mail side effects: the PDF queue item is removed below.
  $worker->processItem([
    'account' => ['company_id' => 'JG Mylard', 'provider' => 'imap'],
    'message' => ['id' => 'date-check', 'from' => 'prueba@example.com'],
    'file' => ['uri' => $uri, 'name' => 'check.xlsx', 'type' => 'excel'],
  ]);
  $ids = $storage->getQuery()->accessCheck(FALSE)->condition('id', $max, '>')->execute();
  $entity = $storage->load(reset($ids));
  $stored = $entity?->get('fecha_inicio_seguro')->value;
  $status = $entity?->get('status')->value;
  $ok = $stored === $expected && ($expected === NULL ? $status === 'error' : $status === 'validated');
  printf("  %-6s %-30s guardada=%-12s estado=%s\n", $ok ? 'OK' : 'FALLA', $label, $stored ?? 'NULL', $status);
  $failures += $ok ? 0 : 1;
  if ($entity) {
    $entity->delete();
  }
  \Drupal::database()->delete('queue')->condition('name', 'aseguramiento_pdf_generation')->execute();
}
$fs->deleteRecursive($dir);

if ($failures) {
  throw new \RuntimeException("RESULTADO: {$failures} casos fallaron.");
}
echo PHP_EOL . 'RESULTADO: todas las fechas se guardan correctamente.' . PHP_EOL;
