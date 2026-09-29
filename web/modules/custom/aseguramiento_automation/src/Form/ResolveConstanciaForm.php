<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Form;

use Drupal\aseguramiento_automation\Entity\ConstanciaEntityInterface;
use Drupal\aseguramiento_automation\Service\ConstanciaCorrectionService;
use Drupal\aseguramiento_automation\Util\PageShell;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Marks a constancia in error as resolved (status "Corregida").
 *
 * For what the automatic link cannot catch: the client sent the fix in a new
 * email instead of answering, or the request was dropped. Nothing is sent to
 * the client. A form (POST with a form token), like reprocessing.
 */
final class ResolveConstanciaForm extends ConfirmFormBase {

  private ConstanciaEntityInterface $constancia;

  public function __construct(private readonly ConstanciaCorrectionService $corrections) {
  }

  public static function create(ContainerInterface $container): self {
    return new self($container->get('aseguramiento_automation.correction'));
  }

  /**
   * Route access: same permission as reprocessing, and only on errors.
   */
  public static function access(ConstanciaEntityInterface $aseguramiento_constancia, AccountInterface $account): AccessResultInterface {
    return AccessResult::allowedIf(ConstanciaCorrectionService::canResolve($aseguramiento_constancia) && $account->hasPermission('reprocess aseguramiento constancia'))
      ->addCacheableDependency($aseguramiento_constancia)
      ->cachePerPermissions();
  }

  public function getFormId(): string {
    return 'aseguramiento_resolve_constancia';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?ConstanciaEntityInterface $aseguramiento_constancia = NULL): array {
    $this->constancia = $aseguramiento_constancia;
    $form = parent::buildForm($form, $form_state);

    $form['#attached']['library'][] = 'aseguramiento_automation/admin';
    $form['#attributes']['class'] = \aseguramiento_automation_standalone_classes([
      ...($form['#attributes']['class'] ?? []),
      'aseguramiento-dashboard',
      'aseguramiento-confirm-page',
    ]);
    $form['hero'] = PageShell::hero('Marcar como resuelta', (string) $this->constancia->label(), ['dashboard', 'constancias']) + ['#weight' => -100];
    $form['panel'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['aseguramiento-panel', 'aseguramiento-confirm']],
      'header' => ['#markup' => '<div class="aseguramiento-panel__header"><div><span>' . $this->t('Confirmación') . '</span><h2>' . $this->getQuestion() . '</h2></div></div>'],
      'description' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->getDescription(),
        '#attributes' => ['class' => ['aseguramiento-confirm__text']],
      ],
      'nota' => [
        '#type' => 'textarea',
        '#title' => $this->t('¿Cómo se resolvió? (opcional)'),
        '#description' => $this->t('Por ejemplo: «El cliente la mandó corregida en otro correo, folio AA-0123». Queda en la bitácora de la constancia.'),
        '#rows' => 3,
        '#maxlength' => 500,
      ],
      'actions' => $form['actions'],
    ];
    unset($form['description'], $form['actions']);
    $form['footer'] = PageShell::footer() + ['#weight' => 100];
    return $form;
  }

  public function getQuestion(): TranslatableMarkup {
    return $this->t('¿Marcar la constancia @folio como resuelta?', ['@folio' => $this->constancia->label()]);
  }

  public function getDescription(): TranslatableMarkup {
    return $this->t('Pasará a «Corregida» y dejará de contar como pendiente de revisión. No se envía nada al cliente.');
  }

  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Marcar como resuelta');
  }

  public function getCancelUrl(): Url {
    return $this->constancia->toUrl();
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->corrections->resolve($this->constancia, $this->currentUser()->getAccountName(), (string) $form_state->getValue('nota'));
    $this->messenger()->addStatus($this->t('La constancia @folio quedó marcada como resuelta.', ['@folio' => $this->constancia->label()]));
    $form_state->setRedirectUrl($this->constancia->toUrl());
  }

}
