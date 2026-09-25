<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Form;

use Drupal\aseguramiento_automation\Mail\EmailTemplateDefaults;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Global automation settings form.
 */
final class AutomationSettingsForm extends ConfigFormBase {

  protected function getEditableConfigNames(): array {
    return ['aseguramiento_automation.settings'];
  }

  public function getFormId(): string {
    return 'aseguramiento_automation_settings_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('aseguramiento_automation.settings');

    $form['processing'] = [
      '#type' => 'details',
      '#title' => $this->t('Procesamiento'),
      '#open' => TRUE,
    ];
    $form['processing']['cron_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Consultar cuentas de correo en cron'),
      '#default_value' => (bool) $config->get('cron_enabled'),
    ];
    $form['processing']['cron_mail_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Límite de correos por cuenta'),
      '#min' => 1,
      '#max' => 500,
      '#default_value' => (int) $config->get('cron_mail_limit') ?: 25,
    ];
    $form['processing']['queue_batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Tamaño de lote de cola'),
      '#min' => 1,
      '#max' => 1000,
      '#default_value' => (int) $config->get('queue_batch_size') ?: 50,
    ];
    $form['processing']['debug_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Modo de depuración'),
      '#description' => $this->t('Registra tiempos de ejecución, identificadores internos, rutas temporales y detalles técnicos del procesamiento. Mantener desactivado en operación normal.'),
      '#default_value' => (bool) $config->get('debug_mode'),
    ];

    $form['filters'] = [
      '#type' => 'details',
      '#title' => $this->t('Filtros de entrada'),
      '#open' => TRUE,
    ];
    $form['filters']['allowed_sender_domains'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Dominios remitentes permitidos'),
      '#description' => $this->t('Un dominio por línea. Dejar vacío para permitir todos los dominios.'),
      '#default_value' => implode("\n", (array) $config->get('allowed_sender_domains')),
    ];
    $form['filters']['required_subject_keywords'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Palabras requeridas en el asunto'),
      '#description' => $this->t('Una palabra por línea. Dejar vacío para permitir cualquier asunto.'),
      '#default_value' => implode("\n", (array) $config->get('required_subject_keywords')),
    ];

    $form['storage'] = [
      '#type' => 'details',
      '#title' => $this->t('Almacenamiento y seguridad'),
      '#open' => TRUE,
    ];
    $form['storage']['storage_private_scheme'] = [
      '#type' => 'select',
      '#title' => $this->t('Esquema de almacenamiento de archivos'),
      '#options' => ['private' => 'private://', 'public' => 'public://'],
      '#default_value' => $config->get('storage_private_scheme') ?: 'private',
    ];
    $form['storage']['token_encryption_key'] = [
      '#type' => 'password',
      '#title' => $this->t('Clave de cifrado de tokens'),
      '#description' => $this->t('Se usa para tokens sensibles de proveedores. Si se deja vacío, se conserva el valor actual.'),
      '#attributes' => ['autocomplete' => 'new-password'],
    ];

    $form['#attached']['library'][] = 'aseguramiento_automation/email_preview';
    $form['#attached']['drupalSettings']['aseguramientoEmailPreview'] = [
      'url' => Url::fromRoute('aseguramiento_automation.email_preview')->toString(),
      'tokenUrl' => Url::fromRoute('system.csrftoken')->toString(),
    ];

    $form['reply'] = [
      '#type' => 'details',
      '#title' => $this->t('Correo al cliente: una constancia'),
      '#description' => $this->t('Se envía cuando el correo del cliente trae una sola solicitud y se generó su constancia.'),
      '#open' => TRUE,
    ];
    $form['reply']['email_reply_subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Asunto'),
      '#default_value' => $config->get('email_reply_subject') ?: 'Constancia generada',
    ];
    $form['reply']['email_reply_body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Cuerpo'),
      '#description' => $this->t('Puedes usar HTML y variables como {{ nombre }}, {{ folio }}, {{ aseguradora }}, {{ tipo_documento }}, {{ logo_data_uri }} y cualquier campo de la solicitud (por ejemplo {{ solicitante }} o {{ medio_transporte }}).'),
      '#default_value' => $config->get('email_reply_body'),
      '#rows' => 14,
    ];
    $form['reply']['email_reply_is_html'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enviar el cuerpo como HTML'),
      '#default_value' => (bool) $config->get('email_reply_is_html'),
    ];
    $form['reply']['preview'] = $this->previewWidget('client');

    $form['team_email'] = [
      '#type' => 'details',
      '#title' => $this->t('Correo al encargado: nueva solicitud'),
      '#description' => $this->t('Se envía a los correos de notificación cuando llega una solicitud, con los archivos del cliente adjuntos.'),
      '#open' => TRUE,
    ];
    $form['team_email']['notification_subject'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Asunto'),
      '#default_value' => $config->get('notification_subject') ?: EmailTemplateDefaults::NOTIFICATION_SUBJECT,
    ];
    $form['team_email']['notification_body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Cuerpo (HTML)'),
      '#description' => $this->t('Variables: {{ remitente }}, {{ asunto }}, {{ fecha }}, {{ archivos }} (cantidad de adjuntos) y {{ logo_data_uri }} (logo). Si lo dejas vacío se usa el diseño original.'),
      '#default_value' => $config->get('notification_body') ?: EmailTemplateDefaults::NOTIFICATION_BODY,
      '#rows' => 14,
    ];
    $form['team_email']['preview'] = $this->previewWidget('team');

    $form['batch_email'] = [
      '#type' => 'details',
      '#title' => $this->t('Correo al cliente: varias constancias o correcciones'),
      '#description' => $this->t('Se envía cuando el correo del cliente trae varias solicitudes o alguna necesita corrección. El encargado recibe copia oculta cuando hay correcciones.'),
      '#open' => TRUE,
    ];
    $form['batch_email']['batch_subject_ok'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Asunto: todas se generaron'),
      '#default_value' => $config->get('batch_subject_ok') ?: EmailTemplateDefaults::BATCH_SUBJECT_OK,
    ];
    $form['batch_email']['batch_subject_partial'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Asunto: algunas requieren corrección'),
      '#default_value' => $config->get('batch_subject_partial') ?: EmailTemplateDefaults::BATCH_SUBJECT_PARTIAL,
    ];
    $form['batch_email']['batch_subject_errors'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Asunto: ninguna se pudo generar'),
      '#default_value' => $config->get('batch_subject_errors') ?: EmailTemplateDefaults::BATCH_SUBJECT_ERRORS,
    ];
    $form['batch_email']['batch_body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Cuerpo (HTML)'),
      '#description' => $this->t('Variables: {{ titulo }}, {{ lista_constancias }} (constancias adjuntas), {{ lista_correcciones }} (qué corregir y en qué archivo), {{ aviso_interno }} (problemas de nuestro lado), {{ total_constancias }}, {{ total_solicitudes }} y {{ logo_data_uri }}. Las listas se arman solas; si quitas una variable, esa parte no aparecerá. Si lo dejas vacío se usa el diseño original.'),
      '#default_value' => $config->get('batch_body') ?: EmailTemplateDefaults::BATCH_BODY,
      '#rows' => 14,
    ];
    $form['batch_email']['preview'] = $this->previewWidget('batch');

    $emails = array_values((array) $config->get('notification_emails'));
    $form['notifications'] = [
      '#type' => 'details',
      '#title' => $this->t('Notificaciones internas'),
      '#open' => TRUE,
    ];
    $form['notifications']['notify_on_inbound_request'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Notificar cuando llegue una solicitud'),
      '#default_value' => $config->get('notify_on_inbound_request') !== FALSE,
    ];
    $form['notifications']['copy_notifications_on_customer_reply'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Copiar estos correos cuando se envíe la constancia al cliente'),
      '#default_value' => $config->get('copy_notifications_on_customer_reply') !== FALSE,
    ];
    for ($i = 0; $i < 3; $i++) {
      $form['notifications']['notification_email_' . ($i + 1)] = [
        '#type' => 'email',
        '#title' => $this->t('Correo de notificación @number', ['@number' => $i + 1]),
        '#default_value' => $emails[$i] ?? '',
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $emails = $this->notificationEmails($form_state);
    if (count($emails) > 3) {
      $form_state->setErrorByName('notification_email_3', $this->t('Solo puedes configurar hasta 3 correos de notificación.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('aseguramiento_automation.settings');
    $config
      ->set('cron_enabled', (bool) $form_state->getValue('cron_enabled'))
      ->set('cron_mail_limit', (int) $form_state->getValue('cron_mail_limit'))
      ->set('queue_batch_size', (int) $form_state->getValue('queue_batch_size'))
      ->set('debug_mode', (bool) $form_state->getValue('debug_mode'))
      ->set('allowed_sender_domains', $this->linesToList((string) $form_state->getValue('allowed_sender_domains')))
      ->set('required_subject_keywords', $this->linesToList((string) $form_state->getValue('required_subject_keywords')))
      ->set('storage_private_scheme', $form_state->getValue('storage_private_scheme'))
      ->set('email_reply_subject', $form_state->getValue('email_reply_subject'))
      ->set('email_reply_body', $form_state->getValue('email_reply_body'))
      ->set('email_reply_is_html', (bool) $form_state->getValue('email_reply_is_html'))
      ->set('notification_emails', $this->notificationEmails($form_state))
      ->set('notify_on_inbound_request', (bool) $form_state->getValue('notify_on_inbound_request'))
      ->set('copy_notifications_on_customer_reply', (bool) $form_state->getValue('copy_notifications_on_customer_reply'));
    foreach (array_keys(EmailTemplateDefaults::settings()) as $key) {
      $config->set($key, (string) $form_state->getValue($key));
    }

    if (($key = trim((string) $form_state->getValue('token_encryption_key'))) !== '') {
      $config->set('token_encryption_key', $key);
    }

    $config->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * "Vista previa" button and the panel where the rendered email appears.
   *
   * The button is type="button": it never submits the form. The panel is
   * filled by js/email-preview.js with what is currently typed.
   */
  private function previewWidget(string $template): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['aa-email-preview'], 'data-aa-email-preview' => $template],
      'button' => [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => $this->t('Vista previa'),
        '#attributes' => ['type' => 'button', 'class' => ['button', 'aa-email-preview__button']],
      ],
      'status' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#attributes' => ['class' => ['aa-email-preview__status'], 'aria-live' => 'polite'],
      ],
      'result' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#attributes' => ['class' => ['aa-email-preview__result'], 'hidden' => 'hidden'],
      ],
    ];
  }

  private function linesToList(string $value): array {
    return array_values(array_filter(array_map('trim', preg_split('/\R/', $value) ?: [])));
  }

  private function notificationEmails(FormStateInterface $form_state): array {
    $emails = [];
    for ($i = 1; $i <= 3; $i++) {
      $email = trim((string) $form_state->getValue('notification_email_' . $i));
      if ($email !== '') {
        $emails[] = $email;
      }
    }
    return array_values(array_unique($emails));
  }

}
