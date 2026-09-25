<?php

/**
 * @file
 * End-to-end check of the single reply per inbound email ("lote").
 *
 * Sends real emails to a GreenMail test mailbox, runs the four queues the
 * way `drush aseguramiento:procesar-correo` does, and inspects what the
 * client receives in Mailpit:
 * - one valid Excel: the configured template with its PDF (unchanged);
 * - four Excel, one invalid: ONE email, three PDFs, what to fix, team copy;
 * - only invalid: one "requires corrections" email without attachments;
 * - an unreadable file next to a valid one: PDF plus "could not read";
 * - running again sends nothing more.
 *
 * Usage (local DDEV only, needs the GreenMail container; see
 * imap_integration_check.php):
 * @code
 * ddev drush php:script scripts/batch_reply_check.php
 * @endcode
 */

declare(strict_types=1);

use Drupal\Core\Queue\RequeueException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPMailer\PHPMailer\PHPMailer;

$template = DRUPAL_ROOT . '/../docs/solicitud_aseguramiento_formato_jr_preparado.xlsx';
$client = 'cliente-lote@example.com';
$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
  echo ($ok ? '  OK    ' : '  FALLA ') . $label . PHP_EOL;
  $failures += $ok ? 0 : 1;
};

$mailpit = static function (string $method = 'GET', string $path = '/api/v1/messages'): array {
  $context = stream_context_create(['http' => ['method' => $method]]);
  $json = file_get_contents('http://localhost:8025' . $path, FALSE, $context);
  return json_decode((string) $json, TRUE) ?: [];
};

$excel = static function (string $name, array $overrides) use ($template): string {
  $book = IOFactory::load($template);
  $sheet = $book->getSheetByName('Solicitud');
  $values = $overrides + [
    'C8' => $name, 'C12' => 'Beneficiario', 'D18' => 'Mercancía', 'D23' => 46290,
    'I23' => 'Terrestre', 'D24' => 'Origen', 'D25' => 'Destino', 'D29' => 'PESOS',
    'D30' => 1000, 'D32' => 1000, 'D54' => 'SI',
  ];
  foreach ($values as $ref => $value) {
    $sheet->setCellValue($ref, $value);
  }
  $path = sys_get_temp_dir() . '/' . preg_replace('/\W+/', '_', $name) . '.xlsx';
  IOFactory::createWriter($book, 'Xlsx')->save($path);
  return $path;
};

$send = static function (array $attachments) use ($client): void {
  $mailer = new PHPMailer(TRUE);
  $mailer->isSMTP();
  $mailer->Host = 'greenmail-jg';
  $mailer->Port = 3025;
  $mailer->SMTPAutoTLS = FALSE;
  $mailer->setFrom($client);
  $mailer->addAddress('buzon@local.test');
  $mailer->Subject = 'Solicitud de aseguramiento prueba lote';
  $mailer->Body = 'Solicitud';
  foreach ($attachments as $name => $path) {
    $mailer->addAttachment($path, $name);
  }
  $mailer->send();
  sleep(1);
};

// Same order and error handling as AseguramientoAutomationCommands.
$run = static function (): void {
  $settings = \Drupal::config('aseguramiento_automation.settings');
  $account = \Drupal::entityTypeManager()->getStorage('aseguramiento_mail_account')->load('greenmail_prueba')->toProviderConfig();
  foreach (\Drupal::service('aseguramiento_automation.mail_provider_manager')->fetchForAccount($account) as $message) {
    \Drupal::service('aseguramiento_automation.queue_manager')->enqueue('aseguramiento_email_processing', ['account' => $account, 'message' => $message]);
  }
  foreach (['aseguramiento_email_processing', 'aseguramiento_excel_parsing', 'aseguramiento_pdf_generation', 'aseguramiento_mail_sending'] as $name) {
    $queue = \Drupal::queue($name);
    $worker = \Drupal::service('plugin.manager.queue_worker')->createInstance($name);
    while ($item = $queue->claimItem(60)) {
      try {
        $worker->processItem($item->data);
        $queue->deleteItem($item);
      }
      catch (RequeueException) {
        $queue->releaseItem($item);
        break;
      }
      catch (\Throwable $e) {
        $queue->deleteItem($item);
        echo '    (error en cola ' . $name . ': ' . $e->getMessage() . ')' . PHP_EOL;
      }
    }
  }
};

$toClient = static function () use ($mailpit, $client): array {
  $out = [];
  foreach ($mailpit()['messages'] ?? [] as $message) {
    if (in_array($client, array_column($message['To'], 'Address'), TRUE)) {
      $detail = $mailpit('GET', '/api/v1/message/' . $message['ID']);
      $out[] = [
        'subject' => $message['Subject'],
        'attachments' => count($detail['Attachments'] ?? []),
        'bcc' => array_column($message['Bcc'] ?? [], 'Address'),
        'html' => (string) ($detail['HTML'] ?? ''),
      ];
    }
  }
  return $out;
};

// Test mailbox account (removed at the end).
$accounts = \Drupal::entityTypeManager()->getStorage('aseguramiento_mail_account');
if (!$accounts->load('greenmail_prueba')) {
  $accounts->create([
    'id' => 'greenmail_prueba', 'label' => 'GreenMail prueba', 'status' => TRUE, 'provider' => 'imap',
    'imap_host' => 'greenmail-jg', 'imap_port' => 3143, 'imap_encryption' => 'none',
    'username' => 'buzon', 'password' => 'secreto', 'folder' => 'INBOX',
    'processed_folder' => 'Processed', 'error_folder' => 'Errors', 'company_id' => 'JG Mylard',
  ])->save();
}
$constancias = \Drupal::entityTypeManager()->getStorage('aseguramiento_constancia');
$first_id = (int) current($constancias->getQuery()->accessCheck(FALSE)->sort('id', 'DESC')->range(0, 1)->execute() ?: [0]);
$team = array_values(array_filter((array) \Drupal::config('aseguramiento_automation.settings')->get('notification_emails')));

echo PHP_EOL . 'Escenario A: un Excel válido' . PHP_EOL;
$mailpit('DELETE');
$send(['solicitud_a.xlsx' => $excel('Cliente A', [])]);
$run();
$mails = $toClient();
$check(count($mails) === 1, 'El cliente recibe 1 correo (recibió ' . count($mails) . ')');
$check(($mails[0]['attachments'] ?? 0) === 1, 'Con 1 PDF adjunto');
$check(($mails[0]['subject'] ?? '') === \Drupal::config('aseguramiento_automation.settings')->get('email_reply_subject'), 'Usa el asunto configurado de siempre ("' . ($mails[0]['subject'] ?? '') . '")');

echo PHP_EOL . 'Escenario B: cuatro Excel, uno sin medio de transporte' . PHP_EOL;
$mailpit('DELETE');
$send([
  'solicitud_1.xlsx' => $excel('Cliente B1', []),
  'solicitud_2.xlsx' => $excel('Cliente B2', []),
  'solicitud_3.xlsx' => $excel('Cliente B3', []),
  'solicitud_4.xlsx' => $excel('Cliente B4', ['I23' => '']),
]);
$run();
$mails = $toClient();
$check(count($mails) === 1, 'El cliente recibe UN solo correo (recibió ' . count($mails) . ')');
$check(($mails[0]['attachments'] ?? 0) === 3, 'Con los 3 PDF adjuntos (tiene ' . ($mails[0]['attachments'] ?? 0) . ')');
$check(str_contains($mails[0]['html'] ?? '', 'Medio de transporte: falta llenarlo'), 'Dice qué corregir: "Medio de transporte: falta llenarlo"');
$check(str_contains($mails[0]['html'] ?? '', 'solicitud_4.xlsx'), 'Indica en qué archivo está el error');
$check($team === [] || array_intersect($team, $mails[0]['bcc'] ?? []) !== [], 'El encargado recibe copia oculta');

echo PHP_EOL . 'Escenario C: solo un Excel con error de fecha' . PHP_EOL;
$mailpit('DELETE');
$send(['solicitud_c.xlsx' => $excel('Cliente C', ['D23' => '24 petiembre 2026'])]);
$run();
$mails = $toClient();
$check(count($mails) === 1, 'El cliente recibe 1 correo (recibió ' . count($mails) . ')');
$check(($mails[0]['attachments'] ?? -1) === 0, 'Sin adjuntos');
$check(($mails[0]['subject'] ?? '') === 'Tu solicitud de aseguramiento requiere correcciones', 'Asunto de corrección');
$check(str_contains($mails[0]['html'] ?? '', 'la fecha no es válida; escríbela como 24/09/2026'), 'Explica cómo escribir la fecha');

echo PHP_EOL . 'Escenario D: un archivo dañado junto a uno válido' . PHP_EOL;
$mailpit('DELETE');
$broken = sys_get_temp_dir() . '/danado.xlsx';
file_put_contents($broken, random_bytes(2048));
$send(['solicitud_d.xlsx' => $excel('Cliente D', []), 'danado.xlsx' => $broken]);
$run();
$mails = $toClient();
$check(count($mails) === 1, 'El cliente recibe 1 correo (recibió ' . count($mails) . ')');
$check(($mails[0]['attachments'] ?? 0) === 1, 'Con el PDF del archivo válido');
$check(str_contains($mails[0]['html'] ?? '', 'No pudimos leer el archivo'), 'Avisa que el archivo dañado no se pudo leer');

echo PHP_EOL . 'Escenario E: volver a correr el proceso' . PHP_EOL;
$mailpit('DELETE');
$run();
$check($toClient() === [], 'No se envía ningún correo repetido');

// Clean up: test constancias, their files, the test account and queues.
$ids = $constancias->getQuery()->accessCheck(FALSE)->condition('id', $first_id, '>')->execute();
$files = \Drupal::entityTypeManager()->getStorage('file');
foreach ($constancias->loadMultiple($ids) as $entity) {
  foreach (['pdf_generado', 'excel_original'] as $field) {
    foreach ($files->loadByProperties(['uri' => (string) $entity->get($field)->value]) as $file) {
      $file->delete();
    }
  }
  $entity->delete();
}
$accounts->load('greenmail_prueba')->delete();
\Drupal::database()->delete('queue')->condition('name', 'aseguramiento_%', 'LIKE')->execute();

if ($failures) {
  throw new \RuntimeException("RESULTADO: {$failures} comprobaciones fallaron.");
}
echo PHP_EOL . 'RESULTADO: todas las comprobaciones pasaron.' . PHP_EOL;
