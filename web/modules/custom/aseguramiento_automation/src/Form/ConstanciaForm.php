<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form controller for constancia records.
 */
final class ConstanciaForm extends ContentEntityForm {

  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('La constancia %label fue guardada.', ['%label' => $this->entity->label()]));
    $form_state->setRedirect('entity.aseguramiento_constancia.collection');
    return $result;
  }

}
