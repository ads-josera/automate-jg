<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Controller;

use Drupal\aseguramiento_automation\Entity\ConstanciaEntityInterface;
use Drupal\aseguramiento_automation\Service\QueueManagerService;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Administrative operations dashboard.
 */
final class DashboardController extends ControllerBase {

  private const STATUS_LABELS = [
    'pending' => 'Pendientes',
    'queued' => 'En cola',
    'validating' => 'Validando',
    'validated' => 'Validadas',
    'pdf_generated' => 'PDF generado',
    'sent' => 'Enviadas',
    'error' => 'Con error',
  ];

  private const STAT_LABELS = [
    'pending' => 'Pendientes',
    'validated' => 'Validadas',
    'pdf_generated_total' => 'PDF generado',
    'sent' => 'Enviadas',
    'error' => 'Con error',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $automationEntityTypeManager,
    private readonly QueueManagerService $queueManager,
    private readonly DateFormatterInterface $dateFormatter,
  ) {
  }

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('aseguramiento_automation.queue_manager'),
      $container->get('date.formatter'),
    );
  }

  public function dashboard(): array {
    $storage = $this->automationEntityTypeManager->getStorage('aseguramiento_constancia');
    $stats = [];
    foreach (['pending', 'validated', 'sent', 'error'] as $status) {
      $stats[$status] = $storage->getQuery()->accessCheck(TRUE)->condition('status', $status)->count()->execute();
    }
    $stats = [
      'pending' => $stats['pending'],
      'validated' => $stats['validated'],
      'pdf_generated_total' => $storage->getQuery()
        ->accessCheck(TRUE)
        ->exists('pdf_generado')
        ->condition('pdf_generado', '', '<>')
        ->count()
        ->execute(),
      'sent' => $stats['sent'],
      'error' => $stats['error'],
    ];
    $latest_ids = $storage->getQuery()->accessCheck(TRUE)->sort('changed', 'DESC')->range(0, 10)->execute();
    $generated_ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->exists('pdf_generado')
      ->condition('pdf_generado', '', '<>')
      ->sort('changed', 'DESC')
      ->range(0, 10)
      ->execute();

    $rows = [];
    foreach ($storage->loadMultiple($latest_ids) as $entity) {
      $status = (string) $entity->get('status')->value;
      $rows[] = [
        [
          'data' => [
            '#type' => 'link',
            '#title' => $entity->label(),
            '#url' => Url::fromRoute('entity.aseguramiento_constancia.canonical', ['aseguramiento_constancia' => $entity->id()]),
          ],
        ],
        $entity->get('nombre')->value,
        $entity->get('poliza')->value,
        ['data' => ['#markup' => '<span class="aseguramiento-badge aseguramiento-badge--' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string) $this->statusLabel($status), ENT_QUOTES, 'UTF-8') . '</span>']],
        $this->dateFormatter->format((int) $entity->getChangedTime(), 'short'),
      ];
    }

    $generated_rows = [];
    foreach ($storage->loadMultiple($generated_ids) as $entity) {
      assert($entity instanceof ConstanciaEntityInterface);
      $status = (string) $entity->get('status')->value;
      $generated_rows[] = [
        [
          'data' => [
            '#type' => 'link',
            '#title' => $entity->label(),
            '#url' => Url::fromRoute('entity.aseguramiento_constancia.canonical', ['aseguramiento_constancia' => $entity->id()]),
          ],
        ],
        $entity->get('nombre')->value ?: $this->t('Sin nombre'),
        $entity->get('aseguradora')->value ?: $this->t('Sin aseguradora'),
        ['data' => ['#markup' => '<span class="aseguramiento-badge aseguramiento-badge--' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars((string) $this->statusLabel($status), ENT_QUOTES, 'UTF-8') . '</span>']],
        $this->dateFormatter->format((int) $entity->getChangedTime(), 'short'),
        [
          'data' => [
            '#type' => 'link',
            '#title' => $this->t('Abrir PDF'),
            '#url' => Url::fromRoute('aseguramiento_automation.constancia_pdf', ['aseguramiento_constancia' => $entity->id()]),
            '#attributes' => [
              'target' => '_blank',
              'rel' => 'noopener noreferrer',
              'class' => ['aseguramiento-link-button'],
            ],
          ],
        ],
      ];
    }

    return [
      '#attached' => ['library' => ['aseguramiento_automation/admin']],
      '#type' => 'container',
      '#attributes' => ['class' => \aseguramiento_automation_standalone_classes(['aseguramiento-dashboard'])],
      'hero' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['aseguramiento-dashboard-hero']],
        'brand' => [
          '#markup' => '<div class="aseguramiento-dashboard-hero__brand"><img src="/modules/custom/aseguramiento_automation/assets/login/logo-jg-white.svg" alt="JG Mylard"><div><span>Automatización documental</span><strong>Panel de aseguramiento</strong></div></div>',
        ],
        'actions' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['aseguramiento-dashboard-hero__actions']],
          'constancias' => [
            '#type' => 'link',
            '#title' => $this->t('Constancias'),
            '#url' => Url::fromRoute('entity.aseguramiento_constancia.collection'),
            '#attributes' => ['class' => ['aseguramiento-action-button']],
          ],
          'export' => [
            '#type' => 'link',
            '#title' => $this->t('Exportar'),
            '#url' => Url::fromRoute('aseguramiento_automation.export'),
            '#attributes' => ['class' => ['aseguramiento-action-button', 'aseguramiento-action-button--primary']],
          ],
          'logout' => [
            '#type' => 'link',
            '#title' => $this->t('Cerrar sesión'),
            '#url' => Url::fromRoute('user.logout'),
            '#attributes' => ['class' => ['aseguramiento-action-button', 'aseguramiento-action-button--logout']],
          ],
        ],
      ],
      'stats' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['aseguramiento-stats']],
        'items' => array_map(fn(string $status, int $count): array => [
          '#type' => 'container',
          '#attributes' => ['class' => ['aseguramiento-stat', 'aseguramiento-stat--' . str_replace('_', '-', $status)]],
          'label' => ['#markup' => '<span class="aseguramiento-stat__label">' . htmlspecialchars((string) $this->statLabel($status), ENT_QUOTES, 'UTF-8') . '</span>'],
          'value' => ['#markup' => '<span class="aseguramiento-stat__value">' . $count . '</span>'],
          'hint' => ['#markup' => '<span class="aseguramiento-stat__hint">' . htmlspecialchars((string) $this->statHint($status), ENT_QUOTES, 'UTF-8') . '</span>'],
        ], array_keys($stats), $stats),
      ],
      'generated_section' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['aseguramiento-panel']],
        'header' => ['#markup' => '<div class="aseguramiento-panel__header"><div><span>Documentos</span><h2>Constancias generadas</h2></div></div>'],
        'table' => [
          '#type' => 'table',
          '#attributes' => ['class' => ['aseguramiento-table']],
          '#header' => [$this->t('Folio'), $this->t('Cliente'), $this->t('Aseguradora'), $this->t('Estado'), $this->t('Actualizado'), $this->t('PDF')],
          '#rows' => $generated_rows,
          '#empty' => $this->t('Todavía no hay constancias generadas.'),
        ],
      ],
      'latest_section' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['aseguramiento-panel', 'aseguramiento-panel--secondary']],
        'header' => ['#markup' => '<div class="aseguramiento-panel__header"><div><span>Actividad</span><h2>Últimos movimientos</h2></div></div>'],
        'table' => [
          '#type' => 'table',
          '#attributes' => ['class' => ['aseguramiento-table']],
          '#header' => [$this->t('Folio'), $this->t('Nombre'), $this->t('Póliza'), $this->t('Estado'), $this->t('Actualizado')],
          '#rows' => $rows,
          '#empty' => $this->t('Todavía no hay registros de automatización.'),
        ],
      ],
      'footer' => [
        '#markup' => '<footer class="aseguramiento-powered-footer">Powered by Josera MKT</footer>',
      ],
    ];
  }

  public function reprocess(ConstanciaEntityInterface $aseguramiento_constancia): RedirectResponse {
    $aseguramiento_constancia->set('status', 'validated');
    $aseguramiento_constancia->set('errores', '');
    $aseguramiento_constancia->save();
    $this->queueManager->enqueue(QueueManagerService::PDF_QUEUE, ['constancia_id' => $aseguramiento_constancia->id()]);
    $this->messenger()->addStatus($this->t('La constancia @folio fue enviada a reproceso.', ['@folio' => $aseguramiento_constancia->label()]));
    return $this->redirect('entity.aseguramiento_constancia.collection');
  }

  private function statusLabel(string $status): string {
    return (string) $this->t(self::STATUS_LABELS[$status] ?? $status);
  }

  private function statLabel(string $status): string {
    return (string) $this->t(self::STAT_LABELS[$status] ?? self::STATUS_LABELS[$status] ?? $status);
  }

  private function statHint(string $status): string {
    return match ($status) {
      'pending' => (string) $this->t('Solicitudes en espera'),
      'validated' => (string) $this->t('Listas para PDF'),
      'pdf_generated_total' => (string) $this->t('Archivos disponibles'),
      'sent' => (string) $this->t('Respuestas al cliente'),
      'error' => (string) $this->t('Requieren revisión'),
      default => '',
    };
  }

}
