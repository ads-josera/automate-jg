<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

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

    $form['reply'] = [
      '#type' => 'details',
      '#title' => $this->t('Correo de respuesta'),
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
      '#description' => $this->t('Puedes usar HTML y variables como {{ nombre }}, {{ folio }}, {{ aseguradora }} y {{ tipo_documento }}.'),
      '#default_value' => $config->get('email_reply_body'),
      '#rows' => 14,
    ];
    $form['reply']['email_reply_is_html'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enviar el cuerpo como HTML'),
      '#default_value' => (bool) $config->get('email_reply_is_html'),
    ];

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
      ->set('allowed_sender_domains', $this->linesToList((string) $form_state->getValue('allowed_sender_domains')))
      ->set('required_subject_keywords', $this->linesToList((string) $form_state->getValue('required_subject_keywords')))
      ->set('storage_private_scheme', $form_state->getValue('storage_private_scheme'))
      ->set('email_reply_subject', $form_state->getValue('email_reply_subject'))
      ->set('email_reply_body', $form_state->getValue('email_reply_body'))
      ->set('email_reply_is_html', (bool) $form_state->getValue('email_reply_is_html'))
      ->set('notification_emails', $this->notificationEmails($form_state))
      ->set('notify_on_inbound_request', (bool) $form_state->getValue('notify_on_inbound_request'))
      ->set('copy_notifications_on_customer_reply', (bool) $form_state->getValue('copy_notifications_on_customer_reply'));

    if (($key = trim((string) $form_state->getValue('token_encryption_key'))) !== '') {
      $config->set('token_encryption_key', $key);
    }

    $config->save();
    parent::submitForm($form, $form_state);
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
