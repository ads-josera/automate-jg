<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Entity;

use Drupal\aseguramiento_automation\Util\PageShell;
use Drupal\aseguramiento_automation\Util\SolicitudErrorFormatter;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds the admin listing for constancias.
 */
final class ConstanciaListBuilder extends EntityListBuilder {

  private const STATUS_LABELS = [
    'pending' => 'Pendiente',
    'queued' => 'En cola',
    'validating' => 'Validando',
    'validated' => 'Validado',
    'pdf_generated' => 'PDF generado',
    'sent' => 'Enviado',
    'error' => 'Error',
  ];

  private const LIMIT_OPTIONS = [
    20 => '20',
    50 => '50',
    100 => '100',
    200 => '200',
    500 => '500',
  ];

  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly RequestStack $requestStack,
  ) {
    parent::__construct($entity_type, $storage);
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('date.formatter'),
      $container->get('request_stack'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntityListQuery(): QueryInterface {
    $this->limit = $this->selectedLimit();

    $query = $this->getStorage()->getQuery()
      ->accessCheck(TRUE)
      ->sort('changed', 'DESC')
      ->sort('id', 'DESC');

    $search = $this->searchTerm();
    if ($search !== '') {
      $group = $query->orConditionGroup()
        ->condition('folio', $search, 'CONTAINS')
        ->condition('nombre', $search, 'CONTAINS')
        ->condition('email', $search, 'CONTAINS')
        ->condition('poliza', $search, 'CONTAINS')
        ->condition('aseguradora', $search, 'CONTAINS')
        ->condition('solicitante', $search, 'CONTAINS')
        ->condition('proveedor_nombre', $search, 'CONTAINS');
      $query->condition($group);
    }

    if ($this->limit) {
      $query->pager($this->limit);
    }

    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    return [
      'folio' => $this->t('Folio'),
      'nombre' => $this->t('Cliente'),
      'aseguradora' => $this->t('Aseguradora'),
      'status' => $this->t('Estado'),
      'changed' => $this->t('Actualizado'),
      'pdf' => $this->t('PDF'),
      'accion' => $this->t('Acción'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    assert($entity instanceof ConstanciaEntityInterface);
    $status = (string) $entity->get('status')->value;
    $row = [
      'folio' => [
        'data' => [
          '#type' => 'link',
          '#title' => $entity->label(),
          '#url' => Url::fromRoute('entity.aseguramiento_constancia.canonical', ['aseguramiento_constancia' => $entity->id()]),
        ],
      ],
      'nombre' => $entity->get('nombre')->value ?: $this->t('Sin nombre'),
      'aseguradora' => $entity->get('aseguradora')->value ?: $this->t('Sin aseguradora'),
      'status' => [
        'data' => [
          '#markup' => $this->statusBadge($entity, $status),
          '#allowed_tags' => ['span', 'button', 'strong', 'ul', 'li'],
        ],
      ],
      'changed' => $this->dateFormatter->format((int) $entity->getChangedTime(), 'short'),
      'pdf' => $this->pdfLink($entity),
      'accion' => $this->reprocessLink($entity, $status),
    ];

    return $row;
  }

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    $table = parent::render();
    $table['table']['#attributes']['class'][] = 'aseguramiento-table';
    $table['table']['#prefix'] = '<div class="aseguramiento-table-scroll">';
    $table['table']['#suffix'] = '</div>';

    $build = [
      '#attached' => ['library' => ['aseguramiento_automation/admin']],
      '#cache' => [
        'contexts' => ['url.query_args:q', 'url.query_args:limit', 'url.query_args:page'],
      ],
      '#type' => 'container',
      '#attributes' => ['class' => \aseguramiento_automation_standalone_classes(['aseguramiento-dashboard', 'aseguramiento-constancias-page'])],
      'hero' => PageShell::hero('Documentos generados', 'Constancias', ['dashboard', 'export']),
      'panel' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['aseguramiento-panel']],
        'header' => ['#markup' => '<div class="aseguramiento-panel__header"><div><span>Repositorio</span><h2>Constancias generadas</h2></div></div>'],
        'filters' => $this->filtersBuild(),
        'table' => $table,
      ],
      'footer' => PageShell::footer(),
    ];
    $build['#attached']['library'][] = 'aseguramiento_automation/admin';
    $build['#attached']['library'][] = 'aseguramiento_automation/status_tooltip';
    return $build;
  }

  private function selectedLimit(): int {
    $value = (int) $this->requestStack->getCurrentRequest()?->query->get('limit', 20);
    return array_key_exists($value, self::LIMIT_OPTIONS) ? $value : 20;
  }

  private function searchTerm(): string {
    $value = (string) $this->requestStack->getCurrentRequest()?->query->get('q', '');
    return mb_substr(trim($value), 0, 120);
  }

  private function filtersBuild(): array {
    return [
      '#markup' => $this->filtersMarkup(),
      '#allowed_tags' => ['form', 'label', 'span', 'input', 'select', 'option', 'div', 'button', 'a'],
    ];
  }

  private function filtersMarkup(): string {
    $action = htmlspecialchars(Url::fromRoute('entity.aseguramiento_constancia.collection')->toString(), ENT_QUOTES, 'UTF-8');
    $search = htmlspecialchars($this->searchTerm(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return '<form class="aseguramiento-list-filters" method="get" action="' . $action . '">'
      . '<label class="aseguramiento-list-filters__field aseguramiento-list-filters__field--search" for="aseguramiento-constancias-search">'
      . '<span>Buscar</span>'
      . '<input id="aseguramiento-constancias-search" type="search" name="q" value="' . $search . '" placeholder="Folio, cliente, correo, póliza..." autocomplete="off">'
      . '</label>'
      . '<label class="aseguramiento-list-filters__field aseguramiento-list-filters__field--limit" for="aseguramiento-constancias-limit">'
      . '<span>Mostrar</span>'
      . '<select id="aseguramiento-constancias-limit" name="limit">' . $this->limitOptionsMarkup() . '</select>'
      . '</label>'
      . '<div class="aseguramiento-list-filters__actions">'
      . '<button type="submit">Filtrar</button>'
      . '<a class="aseguramiento-list-filters__clear" href="' . $action . '">Limpiar</a>'
      . '</div>'
      . '</form>';
  }

  private function limitOptionsMarkup(): string {
    $limit = $this->selectedLimit();
    $options = '';
    foreach (self::LIMIT_OPTIONS as $value => $label) {
      $selected = $value === $limit ? ' selected' : '';
      $options .= '<option value="' . $value . '"' . $selected . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    return $options;
  }

  /**
   * Status badge; on "error" it also carries why (see js/status-tooltip.js).
   *
   * The reason comes from the stored errors, like the client emails, so the
   * list needs no extra query per row.
   */
  private function statusBadge(ConstanciaEntityInterface $entity, string $status): string {
    $e = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $label = $e((string) $this->t(self::STATUS_LABELS[$status] ?? $status));
    $class = 'aseguramiento-badge aseguramiento-badge--' . $e($status);
    if ($status !== 'error') {
      return '<span class="' . $class . '">' . $label . '</span>';
    }

    $described = SolicitudErrorFormatter::describe((string) $entity->get('errores')->value);
    [$title, $lines] = match (TRUE) {
      $described['fields'] !== [] => [$this->t('El cliente debe corregir la solicitud'), $described['fields']],
      $described['internal'] !== [] => [$this->t('Error interno (no es del cliente)'), $described['internal']],
      default => [$this->t('Sin detalle registrado'), []],
    };
    $shown = array_slice($lines, 0, 3);
    if (count($lines) > count($shown)) {
      $shown[] = (string) $this->t('y @n más; ábrela para ver todo.', ['@n' => count($lines) - count($shown)]);
    }
    $items = implode('', array_map(static fn(string $line): string => '<li>' . $e(mb_strimwidth($line, 0, 160, '…')) . '</li>', $shown));
    $id = 'aseguramiento-tip-' . $entity->id();
    return '<span class="aseguramiento-status-tip">'
      . '<button type="button" class="' . $class . ' aseguramiento-status-tip__trigger" aria-describedby="' . $id . '" aria-expanded="false">' . $label . '</button>'
      . '<span class="aseguramiento-status-tip__bubble" id="' . $id . '" role="tooltip"><strong>' . $e((string) $title) . '</strong>' . ($items !== '' ? '<ul>' . $items . '</ul>' : '') . '</span>'
      . '</span>';
  }

  /**
   * "Reprocesar" for constancias that failed on our side.
   *
   * Uses the route's own access check, so the button shows exactly when the
   * action is allowed (permission, "error" status, request data still valid).
   */
  private function reprocessLink(ConstanciaEntityInterface $entity, string $status): array {
    $url = Url::fromRoute('aseguramiento_automation.reprocess', ['aseguramiento_constancia' => $entity->id()]);
    if ($status !== 'error' || !$url->access()) {
      return ['data' => ['#markup' => '']];
    }
    return [
      'data' => [
        '#type' => 'link',
        '#title' => $this->t('Reprocesar'),
        '#url' => $url,
        '#attributes' => ['class' => ['aseguramiento-link-button']],
      ],
    ];
  }

  private function pdfLink(ConstanciaEntityInterface $entity): array {
    if ((string) $entity->get('pdf_generado')->value === '') {
      return ['data' => ['#markup' => '<span class="aseguramiento-muted">No disponible</span>']];
    }

    return [
      'data' => [
        '#type' => 'link',
        '#title' => $this->t('Abrir PDF'),
        '#url' => Url::fromRoute('aseguramiento_automation.constancia_pdf', ['aseguramiento_constancia' => $entity->id()]),
        '#attributes' => [
          'class' => ['aseguramiento-link-button'],
          'target' => '_blank',
          'rel' => 'noopener noreferrer',
        ],
      ],
    ];
  }

}
