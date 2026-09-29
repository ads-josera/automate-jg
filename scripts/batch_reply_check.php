<?php

/**
 * @file
 * End-to-end check of the single reply per inbound email ("lote").
 *
 * Sends real emails to a GreenMail test mailbox, runs the four queues the
 * way `drush aseguramiento:procesar-correo` does, and inspects what the
 * client receives in Mailpit:
 * - one valid Excel: the configured template with its PDF (unchanged);
 * - four Excel, one invalid: ONE email, three PDFs, what to fix, team copy,
 *   threaded as a reply, with a real Message-ID domain and a readable
 *   plain-text part (the three things that sent it to Yahoo's spam);
 * - only invalid: one "requires corrections" email without attachments;
 * - an unreadable file next to a valid one: PDF plus "could not read";
 * - answering our reply with the corrected file: the old constancia becomes
 *   "Corregida" and is linked to the new one; a new email (not an answer)
 *   or an ambiguous answer links nothing;
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

$template = DRUPAL_ROOT . '/../docs/solicitud_aseguramiento_formato.xlsx';
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
    'D30' => 1000, 'D54' => 'SI',
  ];
  foreach ($values as $ref => $value) {
    $sheet->setCellValue($ref, $value);
  }
  $path = sys_get_temp_dir() . '/' . preg_replace('/\W+/', '_', $name) . '.xlsx';
  IOFactory::createWriter($book, 'Xlsx')->save($path);
  return $path;
};

$send = static function (array $attachments, string $message_id = '', array $answers = []) use ($client): void {
  $mailer = new PHPMailer(TRUE);
  if ($message_id !== '') {
    $mailer->MessageID = '<' . $message_id . '>';
  }
  // $answers: ['in_reply_to' => '<id>', 'references' => '<a> <b>'].
  if (!empty($answers['in_reply_to'])) {
    $mailer->addCustomHeader('In-Reply-To', $answers['in_reply_to']);
  }
  if (!empty($answers['references'])) {
    $mailer->addCustomHeader('References', $answers['references']);
  }
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
        'text' => (string) ($detail['Text'] ?? ''),
        'message_id' => (string) ($detail['MessageID'] ?? ''),
        'from' => (string) ($detail['From']['Address'] ?? ''),
        'headers' => $mailpit('GET', '/api/v1/message/' . $message['ID'] . '/headers'),
      ];
    }
  }
  return $out;
};

// Emails addressed to the team (the result summary; the reply copy goes to
// the client with the team in Bcc).
$toTeam = static function (array $team) use ($mailpit): array {
  $out = [];
  foreach ($mailpit()['messages'] ?? [] as $message) {
    if ($team !== [] && array_intersect($team, array_column($message['To'], 'Address')) !== []) {
      $detail = $mailpit('GET', '/api/v1/message/' . $message['ID']);
      $out[] = ['subject' => $message['Subject'], 'html' => (string) ($detail['HTML'] ?? ''), 'attachments' => array_column($detail['Attachments'] ?? [], 'FileName')];
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
], $lote_b_id = 'lote-b-' . uniqid() . '@cliente.example.com');
$run();
$mails = $toClient();
$check(count($mails) === 1, 'El cliente recibe UN solo correo (recibió ' . count($mails) . ')');
$check(($mails[0]['attachments'] ?? 0) === 3, 'Con los 3 PDF adjuntos (tiene ' . ($mails[0]['attachments'] ?? 0) . ')');
$check(str_contains($mails[0]['html'] ?? '', 'Medio de transporte: falta llenarlo'), 'Dice qué corregir: "Medio de transporte: falta llenarlo"');
$check(str_contains($mails[0]['html'] ?? '', 'solicitud_4.xlsx'), 'Indica en qué archivo está el error');
$check($team === [] || array_intersect($team, $mails[0]['bcc'] ?? []) !== [], 'El encargado recibe copia oculta');
// A unique id per run: the processed-mail registry never takes the same
// Message-ID twice.
$check(($mails[0]['headers']['In-Reply-To'][0] ?? '') === '<' . $lote_b_id . '>', 'Va como respuesta al correo del cliente (In-Reply-To: ' . ($mails[0]['headers']['In-Reply-To'][0] ?? 'ninguno') . ')');
$from_domain = substr((string) strrchr($mails[0]['from'] ?? '', '@'), 1);
$check($from_domain !== '' && str_ends_with($mails[0]['message_id'] ?? '', '@' . $from_domain), 'Message-ID con el dominio del remitente (' . ($mails[0]['message_id'] ?? '') . ')');
$check((bool) preg_match('/^AA-\S+ \| Cliente B1\r?$/m', $mails[0]['text'] ?? ''), 'Texto plano: una constancia por renglón');
$check((bool) preg_match('/^- Medio de transporte: falta llenarlo\r?$/m', $mails[0]['text'] ?? ''), 'Texto plano: cada corrección en su renglón');
$check(str_contains($mails[0]['html'] ?? '', 'adjuntando solo el archivo corregido'), 'Pide responder solo con el archivo corregido');

echo PHP_EOL . 'Escenario I: el cliente responde con el archivo corregido' . PHP_EOL;
$our_reply_b = $mails[0]['message_id'] ?? '';
[$old_b4] = array_values($constancias->loadByProperties(['solicitante' => 'Cliente B4'])) + [NULL];
$check($old_b4 !== NULL && $old_b4->get('status')->value === 'error', 'Antes: la constancia de solicitud_4.xlsx está en error');
$mailpit('DELETE');
$send(['solicitud_4.xlsx' => $excel('Cliente B4', [])], 'correccion-' . uniqid() . '@cliente.example.com', [
  // Only our reply's id: proves the reply's Message-ID was remembered (K
  // covers finding the client's own original email in References).
  'in_reply_to' => '<' . $our_reply_b . '>',
  'references' => '<' . $our_reply_b . '>',
]);
$run();
$mails = $toClient();
$check(count($mails) === 1 && ($mails[0]['attachments'] ?? 0) === 1, 'El cliente recibe su constancia');
$constancias->resetCache();
$old_b4 = $old_b4 ? $constancias->load($old_b4->id()) : NULL;
$new_b4 = array_values(array_filter($constancias->loadByProperties(['solicitante' => 'Cliente B4']), static fn($c): bool => $c->get('status')->value === 'sent'))[0] ?? NULL;
$check($old_b4 && $old_b4->get('status')->value === 'corrected', 'La anterior pasa a "Corregida" (' . ($old_b4 ? $old_b4->get('status')->value : 'no existe') . ')');
$check($old_b4 && $new_b4 && (int) $old_b4->get('corregida_por')->target_id === (int) $new_b4->id(), 'La anterior apunta a la nueva');
$check($old_b4 && $new_b4 && (int) $new_b4->get('corrige_a')->target_id === (int) $old_b4->id(), 'La nueva apunta a la anterior');
$check($old_b4 && str_contains((string) $old_b4->get('logs')->value, 'Corregida: el cliente respondió'), 'Queda en la bitácora');
$summary = $toTeam($team)[0] ?? ['html' => ''];
$check($team === [] || ($old_b4 && str_contains($summary['html'], '(corrige ' . $old_b4->label() . ')')), 'El resumen al encargado dice qué folio corrige');

echo PHP_EOL . 'Escenario J: el corregido llega en un correo NUEVO (no es respuesta)' . PHP_EOL;
$mailpit('DELETE');
$send(['solicitud_j.xlsx' => $excel('Cliente J', ['I23' => ''])]);
$run();
$mailpit('DELETE');
$send(['solicitud_j.xlsx' => $excel('Cliente J', [])]);
$run();
$constancias->resetCache();
$j = $constancias->loadByProperties(['solicitante' => 'Cliente J']);
$j_status = array_map(static fn($c): string => $c->get('status')->value, $j);
sort($j_status);
$check($j_status === ['error', 'sent'], 'No se adivina: la anterior sigue en error (' . implode(', ', $j_status) . ')');
$check(!str_contains(($toTeam($team)[0]['html'] ?? ''), '(corrige '), 'El resumen no menciona corrección');

echo PHP_EOL . 'Escenario K: respuesta ambigua (dos errores, nombres distintos, mismo beneficiario)' . PHP_EOL;
$mailpit('DELETE');
$send(['k1.xlsx' => $excel('Cliente K1', ['I23' => '']), 'k2.xlsx' => $excel('Cliente K2', ['I23' => ''])], $lote_k_id = 'lote-k-' . uniqid() . '@cliente.example.com');
$run();
$our_reply_k = $toClient()[0]['message_id'] ?? '';
$mailpit('DELETE');
// Only References (some clients send no In-Reply-To).
$send(['k1_v2.xlsx' => $excel('Cliente K1', []), 'k2_v2.xlsx' => $excel('Cliente K2', [])], '', ['references' => '<' . $lote_k_id . '> <' . $our_reply_k . '>']);
$run();
$constancias->resetCache();
$k_new = array_values(array_filter(array_merge($constancias->loadByProperties(['solicitante' => 'Cliente K1']), $constancias->loadByProperties(['solicitante' => 'Cliente K2'])), static fn($c): bool => $c->get('status')->value === 'sent'));
$k_old = array_values(array_filter(array_merge($constancias->loadByProperties(['solicitante' => 'Cliente K1']), $constancias->loadByProperties(['solicitante' => 'Cliente K2'])), static fn($c): bool => $c->get('status')->value !== 'sent'));
$new_batch = $k_new ? \Drupal::service('aseguramiento_automation.solicitud_batch')->get((string) $k_new[0]->get('lote')->value) : NULL;
$check(($new_batch['corrige_lote'] ?? '') !== '', 'Se reconoce que responde al correo anterior (solo por References)');
$check(count($k_new) === 2 && count($k_old) === 2 && array_unique(array_map(static fn($c): string => $c->get('status')->value, $k_old)) === ['error'], 'Sin forma segura de emparejar: las dos anteriores siguen en error');

echo PHP_EOL . 'Escenario C: solo un Excel con error de fecha' . PHP_EOL;
$mailpit('DELETE');
$send(['solicitud_c.xlsx' => $excel('Cliente C', ['D23' => '24 petiembre 2026', 'D29' => ''])]);
$run();
$mails = $toClient();
$check(count($mails) === 1, 'El cliente recibe 1 correo (recibió ' . count($mails) . ')');
$check(($mails[0]['attachments'] ?? -1) === 0, 'Sin adjuntos');
$check(str_starts_with($mails[0]['subject'] ?? '', strtok((string) \Drupal::config('aseguramiento_automation.settings')->get('batch_subject_errors'), '{')), 'Asunto de corrección ("' . ($mails[0]['subject'] ?? '') . '")');
$check(str_contains($mails[0]['html'] ?? '', 'la fecha no es válida; escríbela como 24/09/2026'), 'Explica cómo escribir la fecha');
$check(str_contains($mails[0]['html'] ?? '', 'Moneda: falta llenarlo'), 'La moneda es obligatoria');

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

echo PHP_EOL . 'Escenario G: suma asegurada fuera de rango' . PHP_EOL;
$mailpit('DELETE');
$send([
  'solicitud_fuera_rango.xlsx' => $excel('Cliente G1', ['D29' => 'USD', 'D30' => 500000, 'I30' => 150000, 'D31' => 30000, 'I31' => 20000]),
  'solicitud_en_el_limite.xlsx' => $excel('Cliente G2', ['D29' => 'USD', 'D30' => 300000, 'I30' => 200000, 'I31' => 100000]),
]);
$run();
$mails = $toClient();
$check(count($mails) === 1, 'El cliente recibe UN solo correo (recibió ' . count($mails) . ')');
$check(($mails[0]['attachments'] ?? 0) === 1, 'Constancia solo para la del límite exacto de 600,000 USD (adjuntos: ' . ($mails[0]['attachments'] ?? 0) . ')');
$check(str_contains($mails[0]['html'] ?? '', 'Suma asegurada total: $700,000.00 USD supera el máximo de $600,000.00 USD'), 'Explica que 500,000 + 150,000 + 30,000 + 20,000 = $700,000.00 USD supera el máximo');
$summaries = $toTeam($team);
$check(count($summaries) === 1, 'El encargado recibe UN resumen, sin aviso previo de "llegó" (recibió ' . count($summaries) . ')');
$summary = $summaries[0] ?? ['subject' => '', 'html' => '', 'attachments' => []];
$check(str_contains($summary['subject'], '1 constancia, 1 por corregir'), 'Asunto con el resultado ("' . $summary['subject'] . '")');
$check(str_contains($summary['html'], 'solicitud_fuera_rango.xlsx') && str_contains($summary['html'], 'Por corregir') && str_contains($summary['html'], 'supera el máximo de $600,000.00 USD'), 'Dice qué archivo hay que corregir y por qué');
$check(str_contains($summary['html'], 'solicitud_en_el_limite.xlsx') && str_contains($summary['html'], 'Constancia AA-'), 'Dice qué archivo generó constancia y su folio');
$check(str_contains($summary['html'], 'El cliente ya recibió su respuesta'), 'Dice que el cliente ya fue respondido');
$check(count($summary['attachments']) === 2, 'Lleva los 2 formatos originales del cliente (' . implode(', ', $summary['attachments']) . ')');

echo PHP_EOL . 'Escenario H: correo sin archivos de solicitud' . PHP_EOL;
$mailpit('DELETE');
$send([]);
$run();
$summaries = $toTeam($team);
$check(count($summaries) === 1 && str_contains($summaries[0]['subject'], 'sin archivos de solicitud') && str_contains($summaries[0]['html'], 'no traía archivos de solicitud'), 'El encargado sabe que llegó un correo sin Excel ni PDF y que el cliente no recibió respuesta');
$check($toClient() === [], 'Al cliente no se le responde');

echo PHP_EOL . 'Resumen al encargado cuando no se pudo responder al cliente' . PHP_EOL;
$mail_service = \Drupal::service('aseguramiento_automation.mail');
$settings_raw = \Drupal::config('aseguramiento_automation.settings')->getRawData();
$row = [['file' => 'solicitud.xlsx', 'status' => 'ok', 'detail' => 'Constancia AA-1']];
$failed_reply = $mail_service->renderTeamSummary(['from' => $client, 'files' => 1, 'rows' => $row, 'reply' => 'failed'], $settings_raw);
$check(str_contains($failed_reply['body'], 'No se pudo enviar la respuesta al cliente') && str_contains($failed_reply['body'], 'manualmente'), 'Le pide enviar la respuesta a mano');
$no_email = $mail_service->renderTeamSummary(['from' => $client, 'files' => 1, 'rows' => $row, 'reply' => 'no_recipient'], $settings_raw);
$check(str_contains($no_email['body'], 'No hay un correo válido del cliente'), 'Avisa que no hay correo válido del cliente');
$internal = $mail_service->renderTeamSummary(['from' => $client, 'files' => 1, 'rows' => [['file' => 'x.xlsx', 'status' => 'internal', 'detail' => 'No fue posible generar el PDF']], 'reply' => 'sent'], $settings_raw);
$check(str_contains($internal['subject'], '1 con error interno') && str_contains($internal['body'], 'Error interno'), 'Un error nuestro se distingue de uno del cliente ("' . $internal['subject'] . '")');

echo PHP_EOL . 'Escenario F: formato PDF rellenable (uno bien, uno incompleto, uno protegido)' . PHP_EOL;
$mailpit('DELETE');
$fixtures = DRUPAL_ROOT . '/../scripts/fixtures/pdf-form/';
$send([
  'solicitud_pdf_bien.pdf' => $fixtures . 'A_pypdf_completo.pdf',
  'solicitud_pdf_incompleta.pdf' => $fixtures . 'H_sin_medio_transporte.pdf',
  'solicitud_pdf_protegida.pdf' => $fixtures . 'F_protegido.pdf',
]);
$run();
$mails = $toClient();
$check(count($mails) === 1, 'El cliente recibe UN solo correo (recibió ' . count($mails) . ')');
$check(($mails[0]['attachments'] ?? 0) === 1, 'Con la constancia del PDF correcto (adjuntos: ' . ($mails[0]['attachments'] ?? 0) . ')');
$check(str_contains($mails[0]['html'] ?? '', 'Medio de transporte: falta llenarlo'), 'Dice qué falta en el PDF incompleto');
$check(str_contains($mails[0]['html'] ?? '', 'protegido con contraseña'), 'Explica que el PDF protegido debe guardarse sin contraseña');
$created = $constancias->loadByProperties(['solicitante' => 'José Pérez (Prueba) \\ Ñ', 'status' => 'sent']);
$check($created !== [], 'La constancia guarda el nombre con acentos y paréntesis tal cual');

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
