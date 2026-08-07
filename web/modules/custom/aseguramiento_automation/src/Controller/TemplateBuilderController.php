<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Controller;

use Drupal\aseguramiento_automation\Entity\ConstanciaEntity;
use Drupal\aseguramiento_automation\Entity\PdfTemplate;
use Drupal\aseguramiento_automation\Service\CoordinateMappingService;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * Visual overlay mapping builder.
 */
final class TemplateBuilderController extends ControllerBase {

  private const BUILDER_CSRF_KEY = 'aseguramiento_automation_template_builder';

  public function __construct(
    private readonly CoordinateMappingService $mappingService,
    private readonly CsrfTokenGenerator $csrfToken,
    private readonly FileSystemInterface $fileSystem,
  ) {
  }

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('aseguramiento_automation.coordinate_mapping'),
      $container->get('csrf_token'),
      $container->get('file_system'),
    );
  }

  public function title(PdfTemplate $aseguramiento_pdf_template): string {
    return $this->t('Constructor de plantilla: @label', ['@label' => $aseguramiento_pdf_template->label()])->render();
  }

  public function builder(PdfTemplate $aseguramiento_pdf_template): array {
    $mappings = $aseguramiento_pdf_template->getMappings();
    $fields = [];
    foreach ($mappings as $mapping) {
      $fields[] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => '{{ ' . $mapping['field'] . ' }}',
        '#attributes' => [
          'class' => ['aa-builder__field'],
          'data-field' => $mapping['field'],
          'data-page' => (string) ($mapping['page'] ?? 1),
          'data-font' => $mapping['font'] ?? 'helvetica',
          'data-size' => (string) ($mapping['size'] ?? 10),
          'data-color' => $mapping['color'] ?? '#000000',
          'data-align' => $mapping['align'] ?? 'L',
          'data-width' => (string) ($mapping['width'] ?? 0),
          'data-multiline' => !empty($mapping['multiline']) ? '1' : '0',
          'data-format' => $mapping['format'] ?? '',
          'style' => sprintf('left:%spx;top:%spx;font-size:%spx;color:%s;', (float) ($mapping['x'] ?? 20), (float) ($mapping['y'] ?? 20), (float) ($mapping['size'] ?? 10), $mapping['color'] ?? '#000000'),
        ],
      ];
    }

    $save_url = Url::fromRoute('aseguramiento_automation.template_builder_save', ['aseguramiento_pdf_template' => $aseguramiento_pdf_template->id()])->toString();
    $preview_url = Url::fromRoute('aseguramiento_automation.template_preview', ['aseguramiento_pdf_template' => $aseguramiento_pdf_template->id()])->toString();
    $page_style = $this->pageStyle($aseguramiento_pdf_template);
    return [
      '#attached' => [
        'library' => ['aseguramiento_automation/template_builder'],
        'drupalSettings' => [
          'aseguramientoAutomation' => [
            'saveUrl' => $save_url,
            'csrfToken' => $this->csrfToken->get(self::BUILDER_CSRF_KEY),
          ],
        ],
      ],
      'builder' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['aa-builder']],
        'canvas' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['aa-builder__canvas']],
          'page' => [
            '#type' => 'container',
            '#attributes' => [
              'class' => ['aa-builder__page'],
              'style' => $page_style,
            ],
            'preview' => [
              '#type' => 'html_tag',
              '#tag' => 'iframe',
              '#attributes' => [
                'class' => ['aa-builder__pdf'],
                'src' => $preview_url . '#toolbar=0&navpanes=0&scrollbar=0',
                'aria-label' => $this->t('Vista previa del PDF base'),
              ],
            ],
            'fields' => $fields,
          ],
        ],
        'panel' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['aa-builder__panel']],
          'summary' => ['#markup' => '<strong>' . $this->t('PDF base') . '</strong><br>' . htmlspecialchars($aseguramiento_pdf_template->getFileUri(), ENT_QUOTES, 'UTF-8')],
          'actions' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['aa-builder__panel-actions']],
            'save' => [
              '#type' => 'html_tag',
              '#tag' => 'button',
              '#value' => $this->t('Guardar posiciones'),
              '#attributes' => [
                'type' => 'button',
                'class' => ['aa-builder__save-fields', 'button', 'button--primary'],
              ],
            ],
            'preview_mapped' => [
              '#type' => 'link',
              '#title' => $this->t('Vista previa con campos'),
              '#url' => Url::fromRoute('aseguramiento_automation.template_preview_mapped', ['aseguramiento_pdf_template' => $aseguramiento_pdf_template->id()]),
              '#attributes' => [
                'class' => ['button'],
                'target' => '_blank',
                'rel' => 'noopener',
              ],
            ],
            'preview_base' => [
              '#type' => 'link',
              '#title' => $this->t('PDF base'),
              '#url' => Url::fromRoute('aseguramiento_automation.template_preview', ['aseguramiento_pdf_template' => $aseguramiento_pdf_template->id()]),
              '#attributes' => [
                'class' => ['button'],
                'target' => '_blank',
                'rel' => 'noopener',
              ],
            ],
          ],
          'save_status' => [
            '#markup' => '<div class="aa-builder__save-status" aria-live="polite"></div>',
          ],
          'preview_link' => [
            '#type' => 'link',
            '#title' => $this->t('Abrir PDF base'),
            '#url' => Url::fromRoute('aseguramiento_automation.template_preview', ['aseguramiento_pdf_template' => $aseguramiento_pdf_template->id()]),
            '#attributes' => [
              'class' => ['visually-hidden'],
              'target' => '_blank',
              'rel' => 'noopener',
            ],
          ],
          'fields_title' => [
            '#markup' => '<h3>' . $this->t('Campos disponibles') . '</h3>',
          ],
          'fields' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['aa-builder__palette']],
            'items' => $this->fieldPalette($mappings),
          ],
          'clear' => [
            '#type' => 'html_tag',
            '#tag' => 'button',
            '#value' => $this->t('Limpiar campos'),
            '#attributes' => [
              'type' => 'button',
              'class' => ['aa-builder__clear-fields'],
            ],
          ],
          'edit' => Link::createFromRoute($this->t('Editar plantilla'), 'entity.aseguramiento_pdf_template.edit_form', ['aseguramiento_pdf_template' => $aseguramiento_pdf_template->id()])->toRenderable(),
        ],
      ],
    ];
  }

  public function save(Request $request, PdfTemplate $aseguramiento_pdf_template): JsonResponse {
    if (!$this->csrfToken->validate((string) $request->headers->get('X-CSRF-Token'), self::BUILDER_CSRF_KEY)) {
      return new JsonResponse(['error' => 'Token de seguridad inválido. Recarga la página e intenta de nuevo.'], 403);
    }

    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload)) {
      return new JsonResponse(['error' => 'Contenido JSON inválido.'], 400);
    }
    $mappings = $this->mappingService->normalizeMappings((array) ($payload['mappings'] ?? []));
    $aseguramiento_pdf_template->setMappings($mappings)->save();
    return new JsonResponse(['saved' => TRUE, 'count' => count($mappings)]);
  }

  public function preview(PdfTemplate $aseguramiento_pdf_template): Response {
    $path = $this->fileSystem->realpath($aseguramiento_pdf_template->getFileUri());
    if (!$path || !is_readable($path)) {
      throw new NotFoundHttpException('PDF template file not found.');
    }

    $response = new Response((string) file_get_contents($path));
    $response->headers->set('Content-Type', 'application/pdf');
    $response->headers->set('Content-Disposition', 'inline; filename="' . basename($path) . '"');
    $response->headers->set('Content-Length', (string) filesize($path));
    $response->headers->set('Cache-Control', 'private, max-age=0, must-revalidate');
    return $response;
  }

  public function previewMapped(PdfTemplate $aseguramiento_pdf_template): Response {
    $path = $this->fileSystem->realpath($aseguramiento_pdf_template->getFileUri());
    if (!$path || !is_readable($path) || !class_exists(Fpdi::class)) {
      throw new NotFoundHttpException('PDF template file not found.');
    }

    $pdf = new Fpdi('P', 'pt');
    $pdf->setPrintHeader(FALSE);
    $pdf->setPrintFooter(FALSE);
    $page_count = $pdf->setSourceFile($path);

    for ($page = 1; $page <= $page_count; $page++) {
      $template_id = $pdf->importPage($page);
      $size = $pdf->getTemplateSize($template_id);
      $pdf->AddPage(($size['width'] > $size['height']) ? 'L' : 'P', [$size['width'], $size['height']]);
      $pdf->useTemplate($template_id);

      foreach ($aseguramiento_pdf_template->getMappings() as $mapping) {
        if ((int) ($mapping['page'] ?? 1) !== $page) {
          continue;
        }
        [$r, $g, $b] = sscanf((string) ($mapping['color'] ?? '#000000'), '#%02x%02x%02x');
        $pdf->SetTextColor($r, $g, $b);
        $pdf->SetFont((string) ($mapping['font'] ?? 'helvetica'), '', (float) ($mapping['size'] ?? 10));
        $pdf->SetXY((float) $mapping['x'], (float) $mapping['y']);
        $value = $this->fieldLabel((string) ($mapping['field'] ?? ''));
        $width = (float) ($mapping['width'] ?? 0);
        if (!empty($mapping['multiline']) || $width > 0) {
          $pdf->MultiCell($width ?: 120, 0, $value, 0, (string) ($mapping['align'] ?? 'L'));
        }
        else {
          $pdf->Cell(0, 0, $value, 0, 0, (string) ($mapping['align'] ?? 'L'));
        }
      }
    }

    $response = new Response($pdf->Output('', 'S'));
    $response->headers->set('Content-Type', 'application/pdf');
    $response->headers->set('Content-Disposition', 'inline; filename="vista-previa-campos.pdf"');
    $response->headers->set('Cache-Control', 'private, max-age=0, must-revalidate');
    return $response;
  }

  private function availableFields(): array {
    return array_merge([
      'folio',
      'nombre',
      'aseguradora',
      'fecha_emision',
    ], ConstanciaEntity::solicitudPdfFields());
  }

  private function fieldPalette(array $mappings): array {
    $selected = array_column($mappings, 'field');
    $items = [];
    foreach ($this->availableFields() as $field) {
      $attributes = [
        'type' => 'button',
        'class' => ['aa-builder__add-field'],
        'data-field' => $field,
        'data-label' => $this->fieldLabel($field),
      ];
      if (in_array($field, $selected, TRUE)) {
        $attributes['disabled'] = 'disabled';
      }
      $items[$field] = [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#value' => $this->fieldLabel($field),
        '#attributes' => $attributes,
      ];
    }
    return $items;
  }

  private function fieldLabel(string $field): string {
    $labels = [
      'folio' => 'Folio',
      'nombre' => 'Nombre',
      'aseguradora' => 'Aseguradora',
      'fecha_emision' => 'Fecha de emisión',
      'mercancia_asegurada' => 'Mercancía asegurada',
      'mercancia_estado' => 'Mercancía',
      'moneda' => 'Moneda',
      'acepta_informacion_veridica' => 'Aceptación de veracidad',
    ];
    $labels += array_map(static fn($label): string => (string) $label, ConstanciaEntity::solicitudStringFields() + ConstanciaEntity::solicitudLongTextFields() + ConstanciaEntity::solicitudDateFields() + ConstanciaEntity::solicitudAmountFields());
    return $labels[$field] ?? $field;
  }

  private function pageStyle(PdfTemplate $template): string {
    $path = $this->fileSystem->realpath($template->getFileUri());
    if (!$path || !is_readable($path) || !class_exists(Fpdi::class)) {
      return 'width:612px;height:792px;';
    }

    try {
      $pdf = new Fpdi('P', 'pt');
      $pdf->setSourceFile($path);
      $page = $pdf->importPage(1);
      $size = $pdf->getTemplateSize($page);
      return sprintf('width:%spx;height:%spx;', (float) $size['width'], (float) $size['height']);
    }
    catch (\Throwable) {
      return 'width:612px;height:792px;';
    }
  }

}
