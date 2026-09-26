<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Form;

use Drupal\aseguramiento_automation\Service\ExportService;
use Drupal\aseguramiento_automation\Util\PageShell;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Filtered exports form.
 */
final class ExportForm extends FormBase {

  public function __construct(private readonly ExportService $exportService) {
  }

  public static function create(ContainerInterface $container): self {
    return new self($container->get('aseguramiento_automation.export'));
  }

  public function getFormId(): string {
    return 'aseguramiento_automation_export_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attached']['library'][] = 'aseguramiento_automation/admin';
    $form['#attributes']['class'] = \aseguramiento_automation_standalone_classes([
      ...($form['#attributes']['class'] ?? []),
      'aseguramiento-dashboard',
      'aseguramiento-export-page',
    ]);

    $form['hero'] = PageShell::hero('Reportes y datos', 'Exportaciones', ['dashboard', 'constancias']);

    $form['export_panel'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['aseguramiento-panel', 'aseguramiento-export-panel']],
      'header' => [
        '#markup' => '<div class="aseguramiento-panel__header"><div><span>Descarga de información</span><h2>Filtros de exportación</h2></div></div>',
      ],
      'body' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['aseguramiento-export-panel__body']],
      ],
    ];

    foreach (['aseguradora' => 'Aseguradora', 'status' => 'Estado', 'poliza' => 'Póliza', 'cliente' => 'Cliente'] as $key => $label) {
      $form['export_panel']['body'][$key] = [
        '#type' => 'textfield',
        '#title' => $this->t($label),
      ];
    }
    $form['export_panel']['body']['date_from'] = [
      '#type' => 'date',
      '#title' => $this->t('Fecha inicial'),
    ];
    $form['export_panel']['body']['date_to'] = [
      '#type' => 'date',
      '#title' => $this->t('Fecha final'),
    ];
    $form['export_panel']['body']['format'] = [
      '#type' => 'select',
      '#title' => $this->t('Formato'),
      '#options' => ['csv' => 'CSV', 'xlsx' => 'Excel'],
      '#default_value' => 'csv',
    ];
    $form['export_panel']['body']['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['aseguramiento-export-actions']],
    ];
    $form['export_panel']['body']['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Exportar'),
      '#attributes' => ['class' => ['aseguramiento-submit-button']],
    ];
    $form['footer'] = PageShell::footer();
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();
    if (isset($values['export_panel']['body']) && is_array($values['export_panel']['body'])) {
      $values = $values['export_panel']['body'];
    }
    $filters = array_intersect_key($values, array_flip(['aseguradora', 'status', 'poliza', 'cliente', 'date_from', 'date_to']));
    $format = (string) ($values['format'] ?? 'csv');

    if ($format === 'xlsx') {
      $response = new StreamedResponse(function () use ($filters): void {
        if (!class_exists(Spreadsheet::class)) {
          throw new \RuntimeException('PhpSpreadsheet is required for XLSX exports.');
        }
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $row_number = 1;
        foreach ($this->exportService->toRows($filters) as $row) {
          $sheet->fromArray($row, NULL, 'A' . $row_number++);
        }
        (new Xlsx($spreadsheet))->save('php://output');
      });
      $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
      $response->headers->set('Content-Disposition', 'attachment; filename="constancias.xlsx"');
    }
    else {
      $response = new StreamedResponse(function () use ($filters): void {
        $handle = fopen('php://output', 'wb');
        foreach ($this->exportService->toRows($filters) as $row) {
          fputcsv($handle, $row);
        }
        fclose($handle);
      });
      $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
      $response->headers->set('Content-Disposition', 'attachment; filename="constancias.csv"');
    }
    $form_state->setResponse($response);
  }

}
