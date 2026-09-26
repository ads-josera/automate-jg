<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Form;

use Drupal\aseguramiento_automation\Entity\ConstanciaEntityInterface;
use Drupal\aseguramiento_automation\Service\ConstanciaReprocessService;
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
 * Confirms reprocessing a constancia: it emails the client again.
 *
 * A form (POST with a form token) instead of a link, so no link opened by
 * accident, or planted in an email, can send anything to a client.
 */
final class ReprocessConstanciaForm extends ConfirmFormBase {

  private ConstanciaEntityInterface $constancia;

  public function __construct(private readonly ConstanciaReprocessService $reprocess) {
  }

  public static function create(ContainerInterface $container): self {
    return new self($container->get('aseguramiento_automation.reprocess'));
  }

  /**
   * Route access: the permission plus a constancia that can be reprocessed.
   */
  public static function access(ConstanciaEntityInterface $aseguramiento_constancia, AccountInterface $account): AccessResultInterface {
    $allowed = \Drupal::service('aseguramiento_automation.reprocess')->check($aseguramiento_constancia)['allowed'];
    return AccessResult::allowedIf($allowed && $account->hasPermission('reprocess aseguramiento constancia'))
      ->addCacheableDependency($aseguramiento_constancia)
      ->cachePerPermissions();
  }

  public function getFormId(): string {
    return 'aseguramiento_reprocess_constancia';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?ConstanciaEntityInterface $aseguramiento_constancia = NULL): array {
    $this->constancia = $aseguramiento_constancia;
    $form = parent::buildForm($form, $form_state);

    // Same branded page as the rest of the panel (the "gestor" role has no
    // admin theme or toolbar).
    $form['#attached']['library'][] = 'aseguramiento_automation/admin';
    $form['#attributes']['class'] = \aseguramiento_automation_standalone_classes([
      ...($form['#attributes']['class'] ?? []),
      'aseguramiento-dashboard',
      'aseguramiento-confirm-page',
    ]);
    $form['hero'] = PageShell::hero('Reprocesar constancia', (string) $this->constancia->label(), ['dashboard', 'constancias']) + ['#weight' => -100];
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
      'actions' => $form['actions'],
    ];
    unset($form['description'], $form['actions']);
    $form['footer'] = PageShell::footer() + ['#weight' => 100];
    return $form;
  }

  public function getQuestion(): TranslatableMarkup {
    return $this->t('¿Reprocesar la constancia @folio?', ['@folio' => $this->constancia->label()]);
  }

  public function getDescription(): TranslatableMarkup {
    return $this->t('Se generará de nuevo el PDF y se enviará a @email en uno o dos minutos.', ['@email' => $this->recipient()]);
  }

  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Reprocesar y enviar');
  }

  public function getCancelUrl(): Url {
    return $this->constancia->toUrl();
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->reprocess->reprocess($this->constancia, $this->currentUser()->getAccountName());
    $this->messenger()->addStatus($this->t('La constancia @folio se está reprocesando. El PDF se enviará a @email en uno o dos minutos.', [
      '@folio' => $this->constancia->label(),
      '@email' => $this->recipient(),
    ]));
    $form_state->setRedirectUrl($this->constancia->toUrl());
  }

  private function recipient(): string {
    return (string) $this->constancia->get('email')->value ?: (string) $this->t('el correo del cliente');
  }

}
