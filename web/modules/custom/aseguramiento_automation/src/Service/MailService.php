<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Mail\MailManagerInterface;
use PHPMailer\PHPMailer\PHPMailer;
use Psr\Log\LoggerInterface;

/**
 * Sends outbound automation replies.
 */
final class MailService {

  public function __construct(
    private readonly MailManagerInterface $mailManager,
    private readonly FileSystemInterface $fileSystem,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {
  }

  public function sendConstancia(array $data, string $pdf_uri, array $settings): bool {
    $start = microtime(TRUE);
    $data = $this->withSystemVariables($data);
    $to = (string) ($data['email'] ?? '');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
      throw new \InvalidArgumentException('No se puede enviar la constancia: el correo del destinatario no es válido.');
    }
    $pdf_path = $this->fileSystem->realpath($pdf_uri);
    if (!$pdf_path || !is_readable($pdf_path)) {
      throw new \RuntimeException(sprintf('No se puede enviar la constancia: el PDF no se puede leer (%s).', $pdf_uri));
    }
    if (!empty($settings['debug_mode'])) {
      $this->logger->info('[Aseguramiento][Depuración] Preparando correo de constancia. Destinatario: @to. PDF URI: @uri. Ruta real: @path.', [
        '@to' => $to,
        '@uri' => $pdf_uri,
        '@path' => $pdf_path,
      ]);
    }
    $params = [
      'subject' => $this->renderTemplate((string) ($settings['email_reply_subject'] ?? 'Constancia generada'), $data),
      'body' => $this->renderTemplate((string) ($settings['email_reply_body'] ?? ''), $data),
      'is_html' => !empty($settings['email_reply_is_html']),
      'bcc' => !empty($settings['copy_notifications_on_customer_reply']) ? $this->notificationEmails($settings) : [],
      'attachments' => [[
        'filepath' => $pdf_path,
        'filename' => basename($pdf_uri),
        'filemime' => 'application/pdf',
      ]],
    ];

    if ($this->shouldSendWithSmtp($params)) {
      $sent = $this->sendWithPhpMailer($to, $params);
      if ($sent) {
        $this->logger->info('[Aseguramiento] Correo de constancia enviado correctamente a @to.', ['@to' => $to]);
      }
      else {
        $this->logger->error('[Aseguramiento] Error al enviar constancia a @to. Detalle: el proveedor SMTP devolvió resultado fallido.', ['@to' => $to]);
      }
      if (!empty($settings['debug_mode'])) {
        $this->logger->info('[Aseguramiento][Depuración] Envío SMTP terminado en @time ms.', [
          '@time' => number_format((microtime(TRUE) - $start) * 1000, 2),
        ]);
      }
      return $sent;
    }

    $result = $this->mailManager->mail('aseguramiento_automation', 'constancia_pdf', $to, 'es', $params);
    $sent = !empty($result['result']);
    if ($sent) {
      $this->logger->info('[Aseguramiento] Correo de constancia enviado correctamente a @to.', ['@to' => $to]);
    }
    else {
      $this->logger->error('[Aseguramiento] Error al enviar constancia a @to. Detalle: el sistema de correo de Drupal devolvió resultado fallido.', ['@to' => $to]);
    }
    return $sent;
  }

  public function sendInboundRequestNotification(array $message, array $files, array $settings): bool {
    if (empty($settings['notify_on_inbound_request'])) {
      $this->logger->info('[Aseguramiento] Notificación interna omitida porque está desactivada en configuración.');
      return TRUE;
    }

    $recipients = $this->notificationEmails($settings);
    if ($recipients === []) {
      $this->logger->warning('[Aseguramiento] Notificación interna omitida porque no hay destinatarios configurados.');
      return TRUE;
    }

    $attachments = [];
    foreach ($files as $file) {
      $uri = (string) ($file['uri'] ?? '');
      $path = $uri !== '' ? $this->fileSystem->realpath($uri) : FALSE;
      if ($path && is_readable($path)) {
        $attachments[] = [
          'filepath' => $path,
          'filename' => (string) ($file['name'] ?? basename($path)),
          'filemime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
      }
    }

    $from = htmlspecialchars($this->decodeMimeHeader((string) ($message['from'] ?? 'Cliente')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $subject = htmlspecialchars($this->decodeMimeHeader((string) ($message['subject'] ?? 'Solicitud de aseguramiento')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $date = date('d/m/Y H:i');
    $attachment_count = count($attachments);
    $body = <<<HTML
<div style="margin:0;padding:0;background:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#1f2933;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;background:#f4f6f8;">
    <tr>
      <td align="center" style="padding:28px 16px;">
        <div style="padding:10px 0 28px;text-align:center;">
          <img src="cid:jgmylard-logo" width="190" alt="JG Mylard" style="display:inline-block;width:190px;max-width:70%;height:auto;border:0;outline:none;text-decoration:none;">
        </div>
        <table role="presentation" width="620" cellspacing="0" cellpadding="0" style="width:620px;max-width:100%;border-collapse:collapse;background:#ffffff;border:1px solid #d9dee5;">
          <tr>
            <td style="padding:24px 28px;background:#243a7b;color:#ffffff;">
              <div style="font-size:20px;font-weight:700;letter-spacing:.2px;">Nueva solicitud de aseguramiento</div>
              <div style="font-size:13px;margin-top:6px;opacity:.9;">{$date}</div>
            </td>
          </tr>
          <tr>
            <td style="padding:28px;">
              <p style="margin:0 0 18px;font-size:15px;line-height:1.6;">Se recibió una nueva solicitud del cliente. El formato original se adjunta para revisión interna.</p>
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:18px 0;background:#f8fafc;border:1px solid #e3e8ef;">
                <tr>
                  <td style="padding:12px 14px;font-size:13px;color:#52606d;">Remitente</td>
                  <td style="padding:12px 14px;font-size:13px;font-weight:700;color:#1f2933;">{$from}</td>
                </tr>
                <tr>
                  <td style="padding:12px 14px;font-size:13px;color:#52606d;border-top:1px solid #e3e8ef;">Asunto</td>
                  <td style="padding:12px 14px;font-size:13px;font-weight:700;color:#1f2933;border-top:1px solid #e3e8ef;">{$subject}</td>
                </tr>
                <tr>
                  <td style="padding:12px 14px;font-size:13px;color:#52606d;border-top:1px solid #e3e8ef;">Archivos adjuntos</td>
                  <td style="padding:12px 14px;font-size:13px;font-weight:700;color:#1f2933;border-top:1px solid #e3e8ef;">{$attachment_count}</td>
                </tr>
              </table>
              <p style="margin:18px 0 0;font-size:13px;line-height:1.6;color:#52606d;">Este correo fue generado automáticamente por el sistema de automatización documental.</p>
            </td>
          </tr>
          <tr>
            <td style="padding:18px 28px;background:#f8fafc;border-top:1px solid #e3e8ef;font-size:12px;color:#697586;">
              Solicitud JG Mylard
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</div>
HTML;

    $sent = $this->sendWithPhpMailer(implode(',', $recipients), [
      'subject' => 'Nueva solicitud de aseguramiento',
      'body' => $body,
      'is_html' => TRUE,
      'attachments' => $attachments,
    ]);
    if ($sent) {
      $this->logger->info('[Aseguramiento] Correo de notificación interna enviado correctamente a @to.', [
        '@to' => implode(', ', $recipients),
      ]);
    }
    else {
      $this->logger->error('[Aseguramiento] Error al enviar notificación interna a @to.', [
        '@to' => implode(', ', $recipients),
      ]);
    }
    return $sent;
  }

  private function renderTemplate(string $template, array $data): string {
    return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', static fn(array $matches): string => (string) ($data[$matches[1]] ?? ''), $template) ?? $template;
  }

  private function withSystemVariables(array $data): array {
    $logo_path = dirname(__DIR__, 2) . '/assets/logo-jgm.png';
    if (is_readable($logo_path)) {
      $data['logo_src'] = 'cid:jgmylard-logo';
      $data['logo_data_uri'] = 'cid:jgmylard-logo';
    }
    return $data;
  }

  private function shouldSendWithSmtp(array $params): bool {
    return !empty($params['is_html'])
      && class_exists(PHPMailer::class)
      && (bool) $this->configFactory->get('smtp.settings')->get('smtp_on');
  }

  private function sendWithPhpMailer(string $to, array $params): bool {
    $smtp = $this->configFactory->get('smtp.settings');
    $site = $this->configFactory->get('system.site');
    $mailer = new PHPMailer(TRUE);

    $mailer->isSMTP();
    $mailer->Host = implode(';', array_filter([
      (string) $smtp->get('smtp_host'),
      (string) $smtp->get('smtp_hostbackup'),
    ]));
    $mailer->Port = (int) $smtp->get('smtp_port');
    $mailer->SMTPAutoTLS = (bool) $smtp->get('smtp_autotls');
    $mailer->Timeout = (int) $smtp->get('smtp_timeout') ?: 30;

    $protocol = (string) $smtp->get('smtp_protocol');
    if (in_array($protocol, ['ssl', 'tls'], TRUE)) {
      $mailer->SMTPSecure = $protocol;
    }

    $username = (string) $smtp->get('smtp_username');
    $password = (string) $smtp->get('smtp_password');
    if ($username !== '' && $password !== '') {
      $mailer->SMTPAuth = TRUE;
      $mailer->Username = $username;
      $mailer->Password = $password;
    }

    $from = (string) ($smtp->get('smtp_from') ?: $site->get('mail'));
    $from_name = (string) ($smtp->get('smtp_fromname') ?: $site->get('name'));
    $mailer->setFrom($from, $from_name);
    $mailer->Sender = $from;
    foreach ($this->splitEmails($to) as $recipient) {
      $mailer->addAddress($recipient);
    }
    foreach ((array) ($params['cc'] ?? []) as $recipient) {
      if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        $mailer->addCC($recipient);
      }
    }
    foreach ((array) ($params['bcc'] ?? []) as $recipient) {
      if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        $mailer->addBCC($recipient);
      }
    }
    $mailer->CharSet = 'UTF-8';
    $mailer->Subject = (string) $params['subject'];
    $mailer->isHTML(TRUE);
    $mailer->Body = (string) $params['body'];
    $mailer->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", (string) $params['body'])));

    $logo_path = dirname(__DIR__, 2) . '/assets/logo-jgm.png';
    if (is_readable($logo_path)) {
      $mailer->addEmbeddedImage($logo_path, 'jgmylard-logo', 'logo-jgm.png', 'base64', 'image/png');
    }

    foreach ((array) ($params['attachments'] ?? []) as $attachment) {
      if (!empty($attachment['filepath']) && is_readable($attachment['filepath'])) {
        $mailer->addAttachment(
          $attachment['filepath'],
          $attachment['filename'] ?? basename((string) $attachment['filepath']),
          'base64',
          $attachment['filemime'] ?? 'application/octet-stream',
        );
      }
    }

    return $mailer->send();
  }

  private function notificationEmails(array $settings): array {
    return array_values(array_filter(
      array_map('trim', (array) ($settings['notification_emails'] ?? [])),
      static fn(string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== FALSE,
    ));
  }

  private function splitEmails(string $emails): array {
    return array_values(array_filter(
      array_map('trim', explode(',', $emails)),
      static fn(string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== FALSE,
    ));
  }

  private function decodeMimeHeader(string $value): string {
    $decoded = iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
    if ($decoded !== FALSE && $decoded !== '') {
      return trim($decoded);
    }
    return trim($value);
  }

}
