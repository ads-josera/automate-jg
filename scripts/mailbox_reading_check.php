<?php

/**
 * @file
 * End-to-end check of how the mailbox is read (inbox and spam folders).
 *
 * Runs the real cycle (poll + four queues) against a GreenMail test mailbox
 * and looks at what the client receives in Mailpit:
 * - first run: emails already in the mailbox (read, or in spam) are left
 *   alone, so old tests are never answered after a deploy;
 * - a request opened in webmail before the run is still processed;
 * - a request in the spam folder (keyword subject + Excel) is moved to the
 *   inbox and answered; other spam stays where it is;
 * - running again answers nothing twice, also when the email could not be
 *   moved out of the inbox;
 * - failed emails are retried a limited number of times.
 *
 * Usage (local DDEV only, needs the GreenMail container; see
 * imap_integration_check.php):
 * @code
 * ddev drush php:script scripts/mailbox_reading_check.php
 * @endcode
 */

declare(strict_types=1);

use DirectoryTree\ImapEngine\Mailbox;
use Drupal\aseguramiento_automation\Service\ProcessedMailRegistry;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPMailer\PHPMailer\PHPMailer;

$template = DRUPAL_ROOT . '/../docs/solicitud_aseguramiento_formato_jr_preparado.xlsx';
$account_id = 'greenmail_lectura';
$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
  echo ($ok ? '  OK    ' : '  FALLA ') . $label . PHP_EOL;
  $failures += $ok ? 0 : 1;
};

$mailpit = static function (string $method = 'GET', string $path = '/api/v1/messages'): array {
  $context = stream_context_create(['http' => ['method' => $method]]);
  return json_decode((string) file_get_contents('http://localhost:8025' . $path, FALSE, $context), TRUE) ?: [];
};
// Addresses that received a reply since the last reset.
$answered = static function () use ($mailpit): array {
  $to = [];
  foreach ($mailpit()['messages'] ?? [] as $message) {
    foreach ($message['To'] as $recipient) {
      $to[] = $recipient['Address'];
    }
  }
  return $to;
};

$raw = new Mailbox([
  'host' => 'greenmail-jg', 'port' => 3143, 'encryption' => NULL, 'validate_cert' => FALSE,
  'username' => 'buzon', 'password' => 'secreto',
]);
$folder = static fn(string $path) => $raw->folders()->firstOrCreate($path);
$subjects = static function (string $path) use ($raw): array {
  $raw->disconnect();
  $raw->connect();
  $out = [];
  foreach ($raw->folders()->findOrFail($path)->messages()->withHeaders()->get() as $message) {
    $out[] = (string) $message->subject();
  }
  return $out;
};

$excel = static function (string $name) use ($template): string {
  $book = IOFactory::load($template);
  $sheet = $book->getSheetByName('Solicitud');
  foreach ([
    'C8' => $name, 'C12' => 'Beneficiario', 'D18' => 'Mercancía', 'D23' => 46290,
    'I23' => 'Terrestre', 'D24' => 'Origen', 'D25' => 'Destino', 'D29' => 'PESOS',
    'D30' => 1000, 'D32' => 1000, 'D54' => 'SI',
  ] as $ref => $value) {
    $sheet->setCellValue($ref, $value);
  }
  $path = sys_get_temp_dir() . '/' . preg_replace('/\W+/', '_', $name) . '.xlsx';
  IOFactory::createWriter($book, 'Xlsx')->save($path);
  return $path;
};

// Builds an email; delivered by SMTP to the inbox, or appended to a folder.
$email = static function (string $from, string $subject, bool $with_excel, string $folder_path = '') use ($excel, $raw): void {
  $mailer = new PHPMailer(TRUE);
  $mailer->isSMTP();
  $mailer->Host = 'greenmail-jg';
  $mailer->Port = 3025;
  $mailer->SMTPAutoTLS = FALSE;
  $mailer->setFrom($from);
  $mailer->addAddress('buzon@local.test');
  $mailer->Subject = $subject;
  $mailer->Body = $subject;
  if ($with_excel) {
    $mailer->addAttachment($excel($subject), 'solicitud.xlsx');
  }
  if ($folder_path === '') {
    $mailer->send();
    sleep(1);
    return;
  }
  $mailer->preSend();
  $raw->folders()->findOrFail($folder_path)->messages()->append($mailer->getSentMIMEMessage());
};

$markRead = static function (string $subject) use ($raw): void {
  foreach ($raw->folders()->findOrFail('INBOX')->messages()->withHeaders()->get() as $message) {
    if ((string) $message->subject() === $subject) {
      $message->markSeen();
    }
  }
};

// Same order and error handling as AseguramientoAutomationCommands. Polls
// the test account directly: settings.local.php forces cron_enabled off.
$run = static function () use ($account_id): void {
  $account = \Drupal::entityTypeManager()->getStorage('aseguramiento_mail_account')->load($account_id)->toProviderConfig();
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
      catch (\Throwable $e) {
        $queue->deleteItem($item);
        echo '    (error en cola ' . $name . ': ' . $e->getMessage() . ')' . PHP_EOL;
      }
    }
  }
};

// Known state: empty folders, fresh account and UID marks.
foreach (['INBOX', 'Processed', 'Errors', 'spam'] as $path) {
  $folder($path)->messages()->delete(TRUE);
}
$accounts = \Drupal::entityTypeManager()->getStorage('aseguramiento_mail_account');
foreach (['greenmail_prueba', $account_id] as $id) {
  $accounts->load($id)?->delete();
}
$accounts->create([
  'id' => $account_id, 'label' => 'GreenMail lectura', 'status' => TRUE, 'provider' => 'imap',
  'imap_host' => 'greenmail-jg', 'imap_port' => 3143, 'imap_encryption' => 'none',
  'username' => 'buzon', 'password' => 'secreto', 'folder' => 'INBOX',
  'processed_folder' => 'Processed', 'error_folder' => 'Errors', 'spam_folders' => 'Junk, spam',
  'company_id' => 'JG Mylard',
])->save();
\Drupal::state()->delete('aseguramiento_automation.imap_uid_marks');

try {
  echo PHP_EOL . 'Escenario 1: primera lectura después de desplegar' . PHP_EOL;
  $email('viejo-leido@example.com', 'Solicitud de aseguramiento vieja leida', TRUE);
  $markRead('Solicitud de aseguramiento vieja leida');
  $email('viejo-spam@example.com', 'Solicitud de aseguramiento vieja en spam', TRUE, 'spam');
  $mailpit('DELETE');
  $run();
  $to = $answered();
  $check(!in_array('viejo-leido@example.com', $to, TRUE), 'No responde un correo que ya estaba leído en la bandeja');
  $check(!in_array('viejo-spam@example.com', $to, TRUE), 'No rescata un correo que ya estaba en spam');
  $check(in_array('Solicitud de aseguramiento vieja en spam', $subjects('spam'), TRUE), 'El correo viejo sigue en spam');

  echo PHP_EOL . 'Escenario 2: correos nuevos' . PHP_EOL;
  $email('abierto-webmail@example.com', 'Solicitud de aseguramiento abierta en webmail', TRUE);
  $markRead('Solicitud de aseguramiento abierta en webmail');
  $email('desde-spam@example.com', 'Solicitud de aseguramiento que cayo en spam', TRUE, 'spam');
  $email('spam-sin-archivo@example.com', 'Solicitud de aseguramiento sin archivo', FALSE, 'spam');
  $email('spam-otro@example.com', 'Oferta imperdible', TRUE, 'spam');
  $mailpit('DELETE');
  $run();
  $to = $answered();
  $check(in_array('abierto-webmail@example.com', $to, TRUE), 'Responde una solicitud aunque alguien la abrió antes en el webmail');
  $check(in_array('desde-spam@example.com', $to, TRUE), 'Rescata de spam y responde una solicitud con Excel');
  $spam = $subjects('spam');
  $check(!in_array('Solicitud de aseguramiento que cayo en spam', $spam, TRUE), 'La solicitud rescatada ya no está en spam');
  $check(in_array('Solicitud de aseguramiento sin archivo', $spam, TRUE) && !in_array('spam-sin-archivo@example.com', $to, TRUE), 'Deja en spam un correo con el asunto pero sin Excel ni PDF');
  $check(in_array('Oferta imperdible', $spam, TRUE) && !in_array('spam-otro@example.com', $to, TRUE), 'Deja en spam un correo sin el asunto de solicitud');
  $processed = $subjects('Processed');
  $check(in_array('Solicitud de aseguramiento que cayo en spam', $processed, TRUE) && in_array('Solicitud de aseguramiento abierta en webmail', $processed, TRUE), 'Las dos solicitudes terminan en Processed');

  echo PHP_EOL . 'Escenario 3: volver a correr' . PHP_EOL;
  $mailpit('DELETE');
  $run();
  $check($answered() === [], 'No se responde nada dos veces');

  echo PHP_EOL . 'Escenario 4: el correo no se puede mover de la bandeja' . PHP_EOL;
  $accounts->load($account_id)->set('processed_folder', 'CarpetaQueNoExiste')->save();
  $email('atorado@example.com', 'Solicitud de aseguramiento atorada', TRUE);
  $mailpit('DELETE');
  $run();
  $check(in_array('atorado@example.com', $answered(), TRUE), 'Se responde una vez');
  $check(in_array('Solicitud de aseguramiento atorada', $subjects('INBOX'), TRUE), 'Se queda en la bandeja (la carpeta no existe)');
  $mailpit('DELETE');
  $run();
  $run();
  $check($answered() === [], 'Aunque siga en la bandeja, no se vuelve a responder');

  echo PHP_EOL . 'Escenario 5: reintentos de un correo que falla' . PHP_EOL;
  $registry = \Drupal::service('aseguramiento_automation.processed_mail');
  $account = ['id' => $account_id];
  $message = ['headers' => ['message_id' => 'reintento-' . uniqid() . '@example.com']];
  $check($registry->claim($account, $message), 'Se toma la primera vez');
  $check(!$registry->claim($account, $message), 'No se toma dos veces mientras está en cola');
  $check($registry->failed($account, $message) && !$registry->claim($account, $message), 'Tras un fallo espera ' . ProcessedMailRegistry::RETRY_DELAY / 60 . ' minutos antes de reintentar');
  $store = \Drupal::service('keyvalue.expirable')->get('aseguramiento_automation.correo');
  $key = ProcessedMailRegistry::key($account, $message);
  $store->setWithExpire($key, ['retry_after' => time() - 1] + $store->get($key), 3600);
  $check($registry->claim($account, $message), 'Pasada la espera, se reintenta');
  $check($registry->failed($account, $message), 'Segundo fallo: todavía se reintentará');
  $check(!$registry->failed($account, $message) && ($registry->get($account, $message)['state'] ?? '') === 'abandoned', 'Al tercer fallo se abandona');
  $store->setWithExpire($key, ['retry_after' => time() - 1] + $store->get($key), 3600);
  $check(!$registry->claim($account, $message), 'Un correo abandonado no se vuelve a tomar');
}
finally {
  $accounts->load($account_id)?->delete();
}

if ($failures) {
  throw new \RuntimeException("RESULTADO: {$failures} comprobaciones fallaron.");
}
echo PHP_EOL . 'RESULTADO: todas las comprobaciones pasaron.' . PHP_EOL;
