<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for versioned corporate PDF templates.
 */
final class PdfTemplateForm extends EntityForm {

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
  ) {
  }

  public static function create(ContainerInterface $container): self {
    return new self($container->get('file_system'));
  }

  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $entity = $this->entity;
    $upload_location = 'private://aseguramiento/templates';
    $this->fileSystem->prepareDirectory($upload_location, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Nombre'),
      '#required' => TRUE,
      '#default_value' => $entity->label(),
    ];
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $entity->id(),
      '#machine_name' => ['exists' => '\Drupal\aseguramiento_automation\Entity\PdfTemplate::load'],
      '#disabled' => !$entity->isNew(),
    ];
    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Activa'),
      '#default_value' => $entity->status(),
    ];

    foreach ([
      'company_id' => 'ID de empresa',
      'insurer' => 'Aseguradora',
      'document_type' => 'Tipo de documento',
      'version' => 'Versión',
    ] as $key => $label) {
      $form[$key] = [
        '#type' => 'textfield',
        '#title' => $this->t($label),
        '#default_value' => $entity->get($key),
      ];
    }
    $form['weight'] = [
      '#type' => 'number',
      '#title' => $this->t('Peso'),
      '#default_value' => $entity->get('weight') ?: 0,
    ];
    $form['pdf'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('PDF corporativo base'),
      '#upload_location' => $upload_location,
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'pdf'],
      ],
      '#description' => $entity->getFileUri() ? $this->t('Archivo actual: @uri', ['@uri' => $entity->getFileUri()]) : '',
    ];
    $form['pages'] = [
      '#type' => 'number',
      '#title' => $this->t('Páginas'),
      '#min' => 1,
      '#default_value' => $entity->get('pages') ?: 1,
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $fid = $form_state->getValue('pdf')[0] ?? NULL;
    if ($fid && ($file = File::load($fid))) {
      $file->setPermanent();
      $file->save();
      $this->entity->set('file_uri', $file->getFileUri());
    }
    parent::submitForm($form, $form_state);
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('La plantilla PDF %label fue guardada.', ['%label' => $this->entity->label()]));
    $form_state->setRedirect('aseguramiento_automation.pdf_templates');
    return $result;
  }

}
