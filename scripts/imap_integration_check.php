<?php

/**
 * @file
 * Integration check for the IMAP mail provider against a real IMAP server.
 *
 * Verifies the contract the email queue relies on: every queued message id
 * keeps pointing at the SAME message after other messages are downloaded,
 * marked as read and moved out of the inbox (which expunges them). With IMAP
 * sequence numbers this breaks: after the first move, id 2 points at the
 * third message. UIDs keep it stable.
 *
 * Usage (local DDEV only, needs the GreenMail test container):
 * @code
 * docker run -d --rm --name greenmail-jg --network ddev-automate-jg_default \
 *   -e GREENMAIL_OPTS='-Dgreenmail.setup.test.all -Dgreenmail.hostname=0.0.0.0 \
 *   -Dgreenmail.users=buzon:secreto@local.test -Dgreenmail.auth.disabled=false' \
 *   greenmail/standalone:2.1.3
 * ddev drush php:script scripts/imap_integration_check.php
 * @endcode
 *
 * Exits with a non-zero status when any check fails.
 */

declare(strict_types=1);

use DirectoryTree\ImapEngine\Mailbox;
use PHPMailer\PHPMailer\PHPMailer;

$host = getenv('IMAP_TEST_HOST') ?: 'greenmail-jg';
$account = [
  'id' => 'integration_check',
  'provider' => 'imap',
  'imap_host' => $host,
  'imap_port' => 3143,
  'imap_encryption' => 'none',
  'username' => 'buzon',
  'password' => 'secreto',
  'folder' => 'INBOX',
];
$failures = [];
$check = static function (bool $ok, string $label) use (&$failures): void {
  echo ($ok ? '  OK    ' : '  FALLA ') . $label . PHP_EOL;
  if (!$ok) {
    $failures[] = $label;
  }
};

$raw = new Mailbox([
  'host' => $host,
  'port' => 3143,
  'encryption' => null,
  'validate_cert' => FALSE,
  'username' => 'buzon',
  'password' => 'secreto',
]);

// Start from a known state: empty inbox and an empty "Processed" folder.
$raw->folders()->firstOrCreate('Processed');
foreach (['INBOX', 'Processed'] as $path) {
  $raw->folders()->findOrFail($path)->messages()->delete(TRUE);
}

// Three requests, each with an attachment whose name matches its subject.
foreach (['A', 'B', 'C'] as $letter) {
  $mailer = new PHPMailer(TRUE);
  $mailer->isSMTP();
  $mailer->Host = $host;
  $mailer->Port = 3025;
  $mailer->SMTPAutoTLS = FALSE;
  $mailer->setFrom("cliente{$letter}@example.com", "Cliente {$letter}");
  $mailer->addAddress('buzon@local.test');
  $mailer->Subject = "Solicitud de aseguramiento {$letter}";
  $mailer->Body = "Solicitud {$letter}";
  $mailer->addStringAttachment("contenido-{$letter}", "solicitud_{$letter}.xlsx", 'base64', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  $mailer->send();
}
sleep(1);

$imap = \Drupal::service('aseguramiento_automation.mail_provider_manager')->getProvider('imap');

echo PHP_EOL . 'Escenario 1: tres correos procesados en orden, con movimiento a Processed' . PHP_EOL;
$messages = $imap->fetchMessages($account, 25);
$check(count($messages) === 3, 'Se detectan 3 correos no leídos (detectados: ' . count($messages) . ')');

foreach ($messages as $message) {
  $letter = substr((string) $message['subject'], -1);
  try {
    $attachments = $imap->downloadAttachments($account, $message);
    $names = array_column($attachments, 'name');
    $check($names === ["solicitud_{$letter}.xlsx"], "El correo {$letter} descarga su propio adjunto (descargó: " . (implode(', ', $names) ?: 'nada') . ')');
    $check(($attachments[0]['content'] ?? '') === "contenido-{$letter}", "El contenido del adjunto {$letter} es íntegro");
    $imap->markProcessed($account, $message);
    $imap->moveMessage($account, $message, 'Processed');
  }
  catch (\Throwable $e) {
    $check(FALSE, "El correo {$letter} se procesa sin excepción (" . $e->getMessage() . ')');
  }
}

$raw->disconnect();
$raw->connect();
$inbox = $raw->folders()->findOrFail('INBOX')->messages()->withHeaders()->get();
$processed = $raw->folders()->findOrFail('Processed')->messages()->withHeaders()->withFlags()->get();
$check($inbox->count() === 0, 'La bandeja de entrada queda vacía (quedan: ' . $inbox->count() . ')');
$check($processed->count() === 3, 'Processed contiene los 3 correos (contiene: ' . $processed->count() . ')');
$seen = $processed->filter(static fn ($m) => $m->isSeen())->count();
$check($seen === 3, "Los 3 correos movidos están marcados como leídos (leídos: {$seen})");

echo PHP_EOL . 'Escenario 2: carpeta de destino inexistente' . PHP_EOL;
$mailer = new PHPMailer(TRUE);
$mailer->isSMTP();
$mailer->Host = $host;
$mailer->Port = 3025;
$mailer->SMTPAutoTLS = FALSE;
$mailer->setFrom('clienteD@example.com');
$mailer->addAddress('buzon@local.test');
$mailer->Subject = 'Solicitud de aseguramiento D';
$mailer->Body = 'Solicitud D';
$mailer->addStringAttachment('contenido-D', 'solicitud_D.xlsx');
$mailer->send();
sleep(1);
$messages = $imap->fetchMessages($account, 25);
$message = $messages[0] ?? NULL;
$check($message !== NULL, 'Se detecta el correo D');
if ($message) {
  try {
    $imap->markProcessed($account, $message);
    $imap->moveMessage($account, $message, 'CarpetaQueNoExiste');
    $check(TRUE, 'Mover a una carpeta inexistente no interrumpe el proceso');
  }
  catch (\Throwable $e) {
    $check(FALSE, 'Mover a una carpeta inexistente no interrumpe el proceso (' . $e->getMessage() . ')');
  }
  $check($imap->fetchMessages($account, 25) === [], 'El correo D ya no aparece como pendiente (quedó marcado como leído)');
}

if ($failures !== []) {
  throw new \RuntimeException('RESULTADO: ' . count($failures) . ' comprobaciones fallaron.');
}
echo PHP_EOL . 'RESULTADO: todas las comprobaciones pasaron.' . PHP_EOL;
