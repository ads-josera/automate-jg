<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Service;

use Drupal\aseguramiento_automation\Mail\EmailTemplateDefaults;
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

  public function sendConstancia(array $data, string $pdf_uri, array $settings, string $in_reply_to = ''): bool {
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
    $rendered = $this->renderClientReply($data, $settings);
    $params = [
      'subject' => $rendered['subject'],
      'body' => $rendered['body'],
      'is_html' => !empty($settings['email_reply_is_html']),
      'in_reply_to' => $in_reply_to,
      'bcc' => !empty($settings['copy_notifications_on_customer_reply']) ? $this->notificationEmails($settings) : [],
      'attachments' => [[
        'filepath' => $pdf_path,
        'filename' => basename($pdf_uri),
        'filemime' => 'application/pdf',
      ]],
    ];

    $sent = $this->deliver($to, $params);
    if ($sent) {
      $this->logger->info('[Aseguramiento] Correo de constancia enviado correctamente a @to.', ['@to' => $to]);
    }
    else {
      $this->logger->error('[Aseguramiento] Error al enviar constancia a @to. Detalle: el proveedor de correo devolvió resultado fallido.', ['@to' => $to]);
    }
    if (!empty($settings['debug_mode'])) {
      $this->logger->info('[Aseguramiento][Depuración] Envío terminado en @time ms.', [
        '@time' => number_format((microtime(TRUE) - $start) * 1000, 2),
      ]);
    }
    return $sent;
  }

  /**
   * Builds the reply for a single constancia (configured template).
   *
   * @return array{subject: string, body: string}
   */
  public function renderClientReply(array $data, array $settings): array {
    $data = $this->withSystemVariables($data);
    $is_html = !empty($settings['email_reply_is_html']);
    return [
      'subject' => $this->renderTemplate((string) ($settings['email_reply_subject'] ?? 'Constancia generada'), $data, FALSE),
      'body' => $this->renderTemplate((string) ($settings['email_reply_body'] ?? ''), $data, $is_html),
    ];
  }

  /**
   * Builds the team notification for a new inbound request.
   *
   * @return array{subject: string, body: string}
   */
  public function renderInboundNotification(array $message, int $attachment_count, array $settings): array {
    $data = $this->withSystemVariables([
      'remitente' => $this->decodeMimeHeader((string) ($message['from'] ?? 'Cliente')),
      'asunto' => $this->decodeMimeHeader((string) ($message['subject'] ?? 'Solicitud de aseguramiento')),
      'fecha' => date('d/m/Y H:i'),
      'archivos' => (string) $attachment_count,
    ]);
    return [
      'subject' => $this->renderTemplate($this->setting($settings, 'notification_subject'), $data, FALSE),
      'body' => $this->renderTemplate($this->setting($settings, 'notification_body'), $data, TRUE),
    ];
  }

  /**
   * Builds the single reply for an email with several results.
   *
   * @return array{subject: string, body: string}
   */
  public function renderBatchReply(array $ok, array $failed, array $settings): array {
    $data = $this->withSystemVariables([
      'total_constancias' => (string) count($ok),
      'total_solicitudes' => (string) (count($ok) + count($failed)),
      'titulo' => $ok === [] ? 'Tu solicitud requiere correcciones' : 'Constancias de aseguramiento',
    ]);
    $subject_key = match (TRUE) {
      $ok === [] => 'batch_subject_errors',
      $failed === [] => 'batch_subject_ok',
      default => 'batch_subject_partial',
    };
    return [
      'subject' => $this->renderTemplate($this->setting($settings, $subject_key), $data, FALSE),
      'body' => $this->renderTemplate($this->setting($settings, 'batch_body'), $data, TRUE, $this->batchSections($ok, $failed)),
    ];
  }

  /**
   * Makes a rendered email viewable in a browser (settings preview).
   *
   * Emails reference the logo as an inline attachment (cid:), which a
   * browser cannot resolve; the preview embeds the same image instead.
   */
  public function previewDocument(string $body, bool $is_html): string {
    if (!$is_html) {
      return '<pre style="font-family:Arial,Helvetica,sans-serif;white-space:pre-wrap;">' . htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>';
    }
    $logo = dirname(__DIR__, 2) . '/assets/logo-jgm.png';
    if (is_readable($logo)) {
      $body = str_replace('cid:jgmylard-logo', 'data:image/png;base64,' . base64_encode((string) file_get_contents($logo)), $body);
    }
    return $body;
  }

  /**
   * Sends ONE reply for an inbound email with several results.
   *
   * Used when the email produced more than one constancia or any failure; a
   * single valid constancia keeps using sendConstancia() and the configured
   * template.
   *
   * @param string $to
   *   Comma-separated client recipients.
   * @param array $ok
   *   Generated constancias: each with "folio", "nombre" and "pdf_uri".
   * @param array $failed
   *   Items without PDF: each with "file", "folio", "nombre", "fields"
   *   (sentences the client must fix) and "internal" (problems on our side).
   */
  public function sendBatchReply(string $to, array $ok, array $failed, array $settings, string $in_reply_to = ''): bool {
    $attachments = [];
    foreach ($ok as $item) {
      $path = $this->fileSystem->realpath((string) $item['pdf_uri']);
      if (!$path || !is_readable($path)) {
        throw new \RuntimeException(sprintf('No se puede enviar la respuesta: el PDF no se puede leer (%s).', $item['pdf_uri']));
      }
      $attachments[] = ['filepath' => $path, 'filename' => basename((string) $item['pdf_uri']), 'filemime' => 'application/pdf'];
    }
    $rendered = $this->renderBatchReply($ok, $failed, $settings);
    // The team always gets a copy when something needs follow-up.
    $bcc = ($failed !== [] || !empty($settings['copy_notifications_on_customer_reply'])) ? $this->notificationEmails($settings) : [];
    $sent = $this->deliver($to, [
      'subject' => $rendered['subject'],
      'body' => $rendered['body'],
      'is_html' => TRUE,
      'in_reply_to' => $in_reply_to,
      'bcc' => $bcc,
      'attachments' => $attachments,
    ]);
    $context = ['@to' => $to, '@ok' => count($ok), '@failed' => count($failed)];
    if ($sent) {
      $this->logger->info('[Aseguramiento] Respuesta agrupada enviada a @to. Constancias: @ok. Con corrección o revisión: @failed.', $context);
    }
    else {
      $this->logger->error('[Aseguramiento] Error al enviar la respuesta agrupada a @to. Constancias: @ok. Con corrección o revisión: @failed.', $context);
    }
    return $sent;
  }

  /**
   * HTML blocks of the batch reply, inserted as {{ lista_* }} variables.
   *
   * @return array<string, string>
   */
  private function batchSections(array $ok, array $failed): array {
    $e = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $cell = 'padding:10px 14px;font-size:13px;border-top:1px solid #e3e8ef;';
    $sections = ['lista_constancias' => '', 'lista_correcciones' => '', 'aviso_interno' => ''];

    if ($ok !== []) {
      $rows = '';
      foreach ($ok as $item) {
        $rows .= '<tr><td style="' . $cell . 'font-weight:700;color:#1f2933;">' . $e($item['folio']) . '</td><td style="' . $cell . 'color:#52606d;">' . $e($item['nombre']) . '</td></tr>';
      }
      $sections['lista_constancias'] = '<p style="margin:0 0 10px;font-size:15px;line-height:1.6;">Adjuntamos ' . (count($ok) === 1 ? 'la constancia generada' : 'las ' . count($ok) . ' constancias generadas') . ':</p>'
        . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:0 0 22px;background:#f8fafc;border:1px solid #e3e8ef;">' . $rows . '</table>';
    }

    $to_fix = array_filter($failed, static fn(array $item): bool => $item['fields'] !== []);
    $internal = array_filter($failed, static fn(array $item): bool => $item['fields'] === []);
    if ($to_fix !== []) {
      $blocks = '';
      foreach ($to_fix as $item) {
        $items = '';
        foreach ($item['fields'] as $sentence) {
          $items .= '<li style="margin:0 0 4px;">' . $e($sentence) . '</li>';
        }
        $who = trim($item['nombre']) !== '' ? ' (' . $e($item['nombre']) . ')' : '';
        $blocks .= '<div style="padding:12px 14px;border-top:1px solid #f3d6d6;"><div style="font-size:13px;font-weight:700;color:#1f2933;">' . $e($item['file']) . $who . '</div><ul style="margin:8px 0 0;padding-left:18px;font-size:13px;line-height:1.5;color:#52606d;">' . $items . '</ul></div>';
      }
      $sections['lista_correcciones'] = '<p style="margin:0 0 10px;font-size:15px;line-height:1.6;">' . (count($to_fix) === 1 ? 'Esta solicitud necesita corrección' : 'Estas solicitudes necesitan corrección') . ' antes de generar la constancia:</p>'
        . '<div style="margin:0 0 12px;background:#fdf6f6;border:1px solid #f3d6d6;">' . $blocks . '</div>'
        . '<p style="margin:0 0 22px;font-size:13px;line-height:1.6;color:#52606d;">' . ($ok !== []
          ? 'Corrige los datos indicados y responde a este mismo correo adjuntando solo el archivo corregido. Las constancias de arriba ya quedaron listas; no hace falta volver a mandarlas.'
          : 'Corrige los datos indicados y responde a este mismo correo adjuntando el archivo corregido.') . '</p>';
    }
    if ($internal !== []) {
      $names = implode(', ', array_map(static fn(array $item): string => $e($item['folio'] ?: $item['file']), $internal));
      $sections['aviso_interno'] = '<p style="margin:0 0 22px;font-size:13px;line-height:1.6;color:#52606d;">No pudimos terminar ' . (count($internal) === 1 ? 'la solicitud' : 'las solicitudes') . ' ' . $names . ' por un problema de nuestro lado. No necesitas corregir nada: nuestro equipo ya fue notificado y te contactará.</p>';
    }

    return $sections;
  }

  /**
   * Sends through the SMTP module path when active, else Drupal's mailer.
   */
  private function deliver(string $to, array $params): bool {
    if ($this->shouldSendWithSmtp($params)) {
      return $this->sendWithPhpMailer($to, $params);
    }
    $result = $this->mailManager->mail('aseguramiento_automation', 'constancia_pdf', $to, 'es', $params);
    return !empty($result['result']);
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
        $type = (string) ($file['type'] ?? '');
        $attachments[] = [
          'filepath' => $path,
          'filename' => (string) ($file['name'] ?? basename($path)),
          'filemime' => $type === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
      }
    }

    $rendered = $this->renderInboundNotification($message, count($attachments), $settings);

    $sent = $this->sendWithPhpMailer(implode(',', $recipients), [
      'subject' => $rendered['subject'],
      'body' => $rendered['body'],
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

  /**
   * Replaces {{ variable }} placeholders.
   *
   * @param bool $escape
   *   HTML-escape $data values (HTML bodies): they come from the client's
   *   email and form, and must not be able to inject markup.
   * @param array $raw
   *   Module-built HTML blocks, inserted as they are.
   */
  private function renderTemplate(string $template, array $data, bool $escape, array $raw = []): string {
    return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', static function (array $matches) use ($data, $escape, $raw): string {
      if (array_key_exists($matches[1], $raw)) {
        return (string) $raw[$matches[1]];
      }
      $value = (string) ($data[$matches[1]] ?? '');
      // The logo placeholder is a cid: URL generated by the module.
      return $escape && !in_array($matches[1], ['logo_src', 'logo_data_uri'], TRUE) ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $value;
    }, $template) ?? $template;
  }

  /**
   * Editable template, falling back to the default when left empty.
   */
  private function setting(array $settings, string $key): string {
    $value = trim((string) ($settings[$key] ?? ''));
    return $value !== '' ? (string) $settings[$key] : EmailTemplateDefaults::settings()[$key];
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
    // Without a hostname PHPMailer uses the server's ("default" on the
    // hosting), which ends up in Message-ID and HELO and scores as spam.
    $mailer->Hostname = (string) $smtp->get('smtp_client_hostname') ?: substr((string) strrchr($from, '@'), 1);
    $helo = (string) $smtp->get('smtp_client_helo');
    if ($helo !== '') {
      $mailer->Helo = $helo;
    }
    $reply_to_id = self::messageIdHeader((string) ($params['in_reply_to'] ?? ''));
    if ($reply_to_id !== '') {
      // Sent as an answer to the client's email: it threads with it, and
      // filters trust a reply more than a new message with attachments.
      $mailer->addCustomHeader('In-Reply-To', $reply_to_id);
      $mailer->addCustomHeader('References', $reply_to_id);
    }
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
    $mailer->AltBody = self::htmlToText((string) $params['body']);

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

  /**
   * Plain-text version of an HTML email, one line per block or row.
   *
   * Spam filters compare it with the HTML part; strip_tags() alone glued
   * every row together ("...ecb5cpepe 1AA-2026...").
   */
  public static function htmlToText(string $html): string {
    $html = preg_replace('#<(style|script|head)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
    $html = preg_replace('#<li\b[^>]*>#i', "\n- ", $html) ?? $html;
    $html = preg_replace('#</t[dh]>\s*<t[dh]\b[^>]*>#i', ' | ', $html) ?? $html;
    $html = preg_replace('#<br\s*/?>|</?(p|div|tr|table|ul|ol|h[1-6])\b[^>]*>#i', "\n", $html) ?? $html;
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $lines = array_map(static fn(string $line): string => trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $line) ?? $line), explode("\n", $text));
    return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)) ?? '');
  }

  /**
   * "<id@host>" for In-Reply-To/References, or '' when the id is unusable.
   */
  public static function messageIdHeader(string $id): string {
    $id = trim($id, " \t<>");
    return preg_match('/^[^\s<>@]+@[^\s<>@]+$/', $id) ? '<' . $id . '>' : '';
  }

  private function splitEmails(string $emails): array {
    return array_values(array_filter(
      array_map('trim', explode(',', $emails)),
      static fn(string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== FALSE,
    ));
  }

  private function decodeMimeHeader(string $value): string {
    // Only RFC 2047 encoded-words need decoding. Mail providers already hand
    // over decoded UTF-8, and iconv_mime_decode() drops its accents
    // ("Compañía" became "Compaa").
    if (!str_contains($value, '=?')) {
      return trim($value);
    }
    $decoded = iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
    if ($decoded !== FALSE && $decoded !== '') {
      return trim($decoded);
    }
    return trim($value);
  }

}
