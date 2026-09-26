<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Controller;

use Drupal\aseguramiento_automation\Entity\ConstanciaEntityInterface;
use Drupal\aseguramiento_automation\Service\ConstanciaReprocessService;
use Drupal\aseguramiento_automation\Util\PageShell;
use Drupal\aseguramiento_automation\Util\SolicitudErrorFormatter;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Displays constancia detail pages and generated PDFs.
 */
final class ConstanciaController extends ControllerBase {

  public function __construct(
    private readonly DateFormatterInterface $dateFormatter,
    private readonly FileSystemInterface $fileSystem,
    private readonly ConstanciaReprocessService $reprocess,
  ) {
  }

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('date.formatter'),
      $container->get('file_system'),
      $container->get('aseguramiento_automation.reprocess'),
    );
  }

  public function view(ConstanciaEntityInterface $aseguramiento_constancia): array {
    $pdf_uri = (string) $aseguramiento_constancia->get('pdf_generado')->value;
    $excel_uri = (string) $aseguramiento_constancia->get('excel_original')->value;
    $status = (string) $aseguramiento_constancia->get('status')->value;
    $cliente = trim((string) $aseguramiento_constancia->get('nombre')->value);
    $titulo = $cliente !== '' ? $cliente : (string) $aseguramiento_constancia->label();

    $build = [
      '#attached' => ['library' => ['aseguramiento_automation/admin']],
      '#type' => 'container',
      '#attributes' => ['class' => \aseguramiento_automation_standalone_classes(['aseguramiento-dashboard', 'aseguramiento-detail-page'])],
      'hero' => PageShell::hero('Detalle de constancia', $titulo, ['dashboard', 'constancias']),
    ];

    $build['summary'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['aseguramiento-panel', 'aseguramiento-detail']],
      'header' => [
        '#markup' => '<div class="aseguramiento-panel__header"><div><span>Información capturada</span><h2>Resumen de solicitud</h2></div></div>',
      ],
      'actions' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['aseguramiento-detail__actions']],
      ],
    ];

    if ($pdf_uri !== '') {
      $build['summary']['actions']['pdf'] = [
        '#type' => 'link',
        '#title' => $this->t('Abrir PDF generado'),
        '#url' => Url::fromRoute('aseguramiento_automation.constancia_pdf', ['aseguramiento_constancia' => $aseguramiento_constancia->id()]),
        '#attributes' => [
          'class' => ['aseguramiento-link-button'],
          'target' => '_blank',
          'rel' => 'noopener noreferrer',
        ],
      ];
    }

    $reprocess = $status === 'error' ? $this->reprocess->check($aseguramiento_constancia) : ['allowed' => FALSE, 'fields' => []];
    if ($reprocess['allowed'] && $this->currentUser()->hasPermission('reprocess aseguramiento constancia')) {
      $build['summary']['actions']['reprocess'] = [
        '#type' => 'link',
        '#title' => $this->t('Reprocesar y enviar'),
        '#url' => Url::fromRoute('aseguramiento_automation.reprocess', ['aseguramiento_constancia' => $aseguramiento_constancia->id()]),
        '#attributes' => ['class' => ['aseguramiento-link-button']],
      ];
    }
    if ($reprocess['fields'] !== []) {
      $items = implode('', array_map(fn(string $field): string => '<li>' . $this->escape($field) . '</li>', $reprocess['fields']));
      $build['summary']['correction'] = [
        '#markup' => '<div class="aseguramiento-detail__notice" role="note"><strong>' . $this->t('El cliente debe corregir la solicitud') . '</strong><p>' . $this->t('No se puede reprocesar: el PDF saldría con los mismos datos. Pídele que envíe de nuevo el formato corregido respondiendo al correo que recibió.') . '</p><ul>' . $items . '</ul></div>',
      ];
    }

    $rows = [
      [$this->t('Folio'), $aseguramiento_constancia->label()],
      [$this->t('Estado'), $this->statusLabel($status)],
      [$this->t('Nombre'), $aseguramiento_constancia->get('nombre')->value],
      [$this->t('Correo'), $aseguramiento_constancia->get('email')->value],
      [$this->t('Aseguradora'), $aseguramiento_constancia->get('aseguradora')->value],
      [$this->t('Tipo de documento'), $aseguramiento_constancia->get('tipo_documento')->value],
      [$this->t('Plantilla usada'), $aseguramiento_constancia->get('plantilla_usada')->value],
      [$this->t('PDF generado'), $pdf_uri ?: $this->t('No generado')],
      [$this->t('Excel original'), $excel_uri ?: $this->t('No disponible')],
      [$this->t('Proveedor de correo'), $aseguramiento_constancia->get('provider_correo')->value],
      [$this->t('Creado'), $this->dateFormatter->format((int) $aseguramiento_constancia->get('created')->value, 'short')],
      [$this->t('Actualizado'), $this->dateFormatter->format((int) $aseguramiento_constancia->getChangedTime(), 'short')],
    ];

    $build['summary']['table'] = [
      '#type' => 'table',
      '#prefix' => '<div class="aseguramiento-table-scroll">',
      '#suffix' => '</div>',
      '#attributes' => ['class' => ['aseguramiento-table', 'aseguramiento-detail-table']],
      '#header' => [$this->t('Campo'), $this->t('Valor')],
      '#rows' => array_map(static fn(array $row): array => [
        'data' => [
          ['data' => ['#markup' => '<strong>' . htmlspecialchars((string) $row[0], ENT_QUOTES, 'UTF-8') . '</strong>']],
          ['data' => ['#plain_text' => (string) $row[1]]],
        ],
      ], $rows),
    ];

    // Stored as JSON per field plus free lines; shown as sentences. Field
    // errors already listed in the correction notice are not repeated.
    $described = SolicitudErrorFormatter::describe((string) $aseguramiento_constancia->get('errores')->value);
    $lines = array_merge($reprocess['fields'] === [] ? $described['fields'] : [], $described['internal']);
    if ($lines !== []) {
      $build['errors'] = [
        '#type' => 'details',
        '#attributes' => ['class' => ['aseguramiento-panel', 'aseguramiento-error-panel']],
        '#title' => $this->t('Errores'),
        '#open' => TRUE,
        'content' => ['#markup' => '<ul>' . implode('', array_map(fn(string $line): string => '<li>' . $this->escape($line) . '</li>', $lines)) . '</ul>'],
      ];
    }

    if ($pdf_uri !== '') {
      $pdf_src = Url::fromRoute('aseguramiento_automation.constancia_pdf', ['aseguramiento_constancia' => $aseguramiento_constancia->id()], ['absolute' => TRUE])->toString();
      $build['preview'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['aseguramiento-panel', 'aseguramiento-pdf-preview']],
        'title' => ['#markup' => '<div class="aseguramiento-panel__header"><div><span>Documento final</span><h2>' . $this->t('Vista previa del PDF generado') . '</h2></div></div>'],
        'iframe' => ['#markup' => '<object data="' . $this->escape($pdf_src) . '#toolbar=1&navpanes=0" type="application/pdf" class="aseguramiento-pdf-object"><p>' . $this->t('Tu navegador no pudo mostrar la vista previa del PDF.') . '</p><p><a class="aseguramiento-link-button" href="' . $this->escape($pdf_src) . '" target="_blank" rel="noopener noreferrer">' . $this->t('Abrir PDF generado') . '</a></p></object>'],
      ];
    }

    $build['footer'] = PageShell::footer();

    return $build;
  }

  public function pdf(ConstanciaEntityInterface $aseguramiento_constancia): Response {
    $uri = (string) $aseguramiento_constancia->get('pdf_generado')->value;
    $path = $uri !== '' ? $this->fileSystem->realpath($uri) : FALSE;
    if (!$path || !is_readable($path)) {
      throw new NotFoundHttpException('PDF generado no disponible.');
    }

    $response = new Response((string) file_get_contents($path));
    $response->headers->set('Content-Type', 'application/pdf');
    $response->headers->set('Content-Disposition', 'inline; filename="' . basename($path) . '"');
    $response->headers->set('Content-Length', (string) filesize($path));
    return $response;
  }

  private function statusLabel(string $status): string {
    return match ($status) {
      'pending' => (string) $this->t('Pendiente'),
      'queued' => (string) $this->t('En cola'),
      'validating' => (string) $this->t('Validando'),
      'validated' => (string) $this->t('Validado'),
      'pdf_generated' => (string) $this->t('PDF generado'),
      'sent' => (string) $this->t('Enviado'),
      'error' => (string) $this->t('Error'),
      default => $status,
    };
  }

  private function escape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

}
