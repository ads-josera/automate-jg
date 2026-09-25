<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Mail;

/**
 * Default designs of the editable emails (single source of truth).
 *
 * Used to seed the settings on install/update and as fallback when a setting
 * is empty, so an emptied textarea never sends a blank email. The team
 * notification is the exact design that was hard-coded in MailService.
 *
 * Variables use {{ name }}. Values coming from the client (names, sender,
 * subject) are HTML-escaped when inserted; the {{ lista_* }} and
 * {{ aviso_interno }} blocks are HTML built by the module.
 */
final class EmailTemplateDefaults {

  public const NOTIFICATION_SUBJECT = 'Nueva solicitud de aseguramiento';

  public const NOTIFICATION_BODY = <<<'HTML'
<div style="margin:0;padding:0;background:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#1f2933;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;background:#f4f6f8;">
    <tr>
      <td align="center" style="padding:28px 16px;">
        <div style="padding:10px 0 28px;text-align:center;">
          <img src="{{ logo_data_uri }}" width="190" alt="JG Mylard" style="display:inline-block;width:190px;max-width:70%;height:auto;border:0;outline:none;text-decoration:none;">
        </div>
        <table role="presentation" width="620" cellspacing="0" cellpadding="0" style="width:620px;max-width:100%;border-collapse:collapse;background:#ffffff;border:1px solid #d9dee5;">
          <tr>
            <td style="padding:24px 28px;background:#243a7b;color:#ffffff;">
              <div style="font-size:20px;font-weight:700;letter-spacing:.2px;">Nueva solicitud de aseguramiento</div>
              <div style="font-size:13px;margin-top:6px;opacity:.9;">{{ fecha }}</div>
            </td>
          </tr>
          <tr>
            <td style="padding:28px;">
              <p style="margin:0 0 18px;font-size:15px;line-height:1.6;">Se recibió una nueva solicitud del cliente. El formato original se adjunta para revisión interna.</p>
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:18px 0;background:#f8fafc;border:1px solid #e3e8ef;">
                <tr>
                  <td style="padding:12px 14px;font-size:13px;color:#52606d;">Remitente</td>
                  <td style="padding:12px 14px;font-size:13px;font-weight:700;color:#1f2933;">{{ remitente }}</td>
                </tr>
                <tr>
                  <td style="padding:12px 14px;font-size:13px;color:#52606d;border-top:1px solid #e3e8ef;">Asunto</td>
                  <td style="padding:12px 14px;font-size:13px;font-weight:700;color:#1f2933;border-top:1px solid #e3e8ef;">{{ asunto }}</td>
                </tr>
                <tr>
                  <td style="padding:12px 14px;font-size:13px;color:#52606d;border-top:1px solid #e3e8ef;">Archivos adjuntos</td>
                  <td style="padding:12px 14px;font-size:13px;font-weight:700;color:#1f2933;border-top:1px solid #e3e8ef;">{{ archivos }}</td>
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

  public const BATCH_SUBJECT_OK = 'Solicitud de aseguramiento: se generaron tus {{ total_constancias }} constancias';

  public const BATCH_SUBJECT_PARTIAL = 'Solicitud de aseguramiento: constancias generadas {{ total_constancias }} de {{ total_solicitudes }}';

  public const BATCH_SUBJECT_ERRORS = 'Solicitud de aseguramiento: requiere correcciones';

  public const BATCH_BODY = <<<'HTML'
<div style="margin:0;padding:0;background:#f4f6f8;font-family:Arial,Helvetica,sans-serif;color:#1f2933;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;background:#f4f6f8;">
    <tr>
      <td align="center" style="padding:28px 16px;">
        <div style="padding:10px 0 28px;text-align:center;">
          <img src="{{ logo_data_uri }}" width="190" alt="JG Mylard" style="display:inline-block;width:190px;max-width:70%;height:auto;border:0;">
        </div>
        <table role="presentation" width="620" cellspacing="0" cellpadding="0" style="width:620px;max-width:100%;border-collapse:collapse;background:#ffffff;border:1px solid #d9dee5;">
          <tr>
            <td style="padding:24px 28px;background:#243a7b;color:#ffffff;">
              <div style="font-size:20px;font-weight:700;">{{ titulo }}</div>
            </td>
          </tr>
          <tr>
            <td style="padding:28px 28px 6px;">
              {{ lista_constancias }}
              {{ lista_correcciones }}
              {{ aviso_interno }}
              <p style="margin:0 0 22px;font-size:13px;line-height:1.6;color:#52606d;">Este correo fue generado automáticamente. Para cualquier aclaración, responde a este mismo mensaje.</p>
            </td>
          </tr>
          <tr>
            <td style="padding:18px 28px;background:#f8fafc;border-top:1px solid #e3e8ef;font-size:12px;color:#697586;">Solicitud JG Mylard</td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</div>
HTML;

  /**
   * Setting key => default value, for install/update and fallbacks.
   */
  public static function settings(): array {
    return [
      'notification_subject' => self::NOTIFICATION_SUBJECT,
      'notification_body' => self::NOTIFICATION_BODY,
      'batch_subject_ok' => self::BATCH_SUBJECT_OK,
      'batch_subject_partial' => self::BATCH_SUBJECT_PARTIAL,
      'batch_subject_errors' => self::BATCH_SUBJECT_ERRORS,
      'batch_body' => self::BATCH_BODY,
    ];
  }

}
