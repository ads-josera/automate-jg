<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form for enterprise mail provider accounts.
 */
final class MailAccountForm extends EntityForm {

  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $entity = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Nombre'),
      '#required' => TRUE,
      '#default_value' => $entity->label(),
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $entity->id(),
      '#machine_name' => [
        'exists' => '\Drupal\aseguramiento_automation\Entity\MailAccount::load',
      ],
      '#disabled' => !$entity->isNew(),
    ];
    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Activa'),
      '#default_value' => $entity->status(),
    ];
    $form['provider'] = [
      '#type' => 'select',
      '#title' => $this->t('Proveedor'),
      '#options' => [
        'microsoft_graph' => $this->t('Microsoft Graph'),
        'imap' => $this->t('IMAP'),
      ],
      '#default_value' => $entity->get('provider') ?: 'microsoft_graph',
    ];

    $form['tenant'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Contexto de empresa'),
    ];
    foreach (['company_id' => 'ID de empresa', 'insurer' => 'Aseguradora predeterminada'] as $key => $label) {
      $form['tenant'][$key] = [
        '#type' => 'textfield',
        '#title' => $this->t($label),
        '#default_value' => $entity->get($key),
      ];
    }

    $form['microsoft_graph'] = [
      '#type' => 'details',
      '#title' => $this->t('Microsoft Graph / Office 365'),
      '#open' => TRUE,
      '#states' => ['visible' => [':input[name="provider"]' => ['value' => 'microsoft_graph']]],
    ];
    foreach ([
      'tenant_id' => 'Tenant ID',
      'client_id' => 'Client ID',
      'client_secret' => 'Secreto del cliente',
      'mailbox' => 'Buzón',
      'folder' => 'Carpeta',
      'processed_folder' => 'Carpeta de procesados',
      'error_folder' => 'Carpeta de errores',
    ] as $key => $label) {
      $form['microsoft_graph'][$key] = [
        '#type' => $key === 'client_secret' ? 'password' : 'textfield',
        '#title' => $this->t($label),
        '#default_value' => $key === 'client_secret' ? '' : $entity->get($key),
        '#attributes' => $key === 'client_secret' ? ['autocomplete' => 'new-password'] : [],
      ];
    }

    $form['imap'] = [
      '#type' => 'details',
      '#title' => $this->t('IMAP secundario'),
      '#open' => TRUE,
      '#states' => ['visible' => [':input[name="provider"]' => ['value' => 'imap']]],
    ];
    $form['imap']['imap_host'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Servidor'),
      '#default_value' => $entity->get('imap_host'),
    ];
    $form['imap']['imap_port'] = [
      '#type' => 'number',
      '#title' => $this->t('Puerto'),
      '#default_value' => $entity->get('imap_port') ?: 993,
    ];
    $form['imap']['imap_encryption'] = [
      '#type' => 'select',
      '#title' => $this->t('Cifrado'),
      '#options' => [
        'ssl' => $this->t('SSL/TLS (puerto 993)'),
        'tls' => $this->t('STARTTLS (puerto 143)'),
        'none' => $this->t('Ninguno, sin validar certificado (solo redes de confianza)'),
      ],
      '#default_value' => $entity->get('imap_encryption') ?: 'ssl',
    ];
    foreach (['username' => 'Usuario', 'password' => 'Contraseña'] as $key => $label) {
      $form['imap'][$key] = [
        '#type' => $key === 'password' ? 'password' : 'textfield',
        '#title' => $this->t($label),
        '#default_value' => $key === 'password' ? '' : $entity->get($key),
        '#attributes' => $key === 'password' ? ['autocomplete' => 'new-password'] : [],
      ];
    }

    return $form;
  }

  public function save(array $form, FormStateInterface $form_state): int {
    foreach (['client_secret', 'password'] as $secret_key) {
      if (!$form_state->getValue($secret_key) && !$this->entity->isNew()) {
        $original = $this->entityTypeManager->getStorage($this->entity->getEntityTypeId())->load($this->entity->id());
        $this->entity->set($secret_key, $original?->get($secret_key));
      }
    }

    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('La cuenta de correo %label fue guardada.', ['%label' => $this->entity->label()]));
    $form_state->setRedirect('aseguramiento_automation.mail_accounts');
    return $result;
  }

}
