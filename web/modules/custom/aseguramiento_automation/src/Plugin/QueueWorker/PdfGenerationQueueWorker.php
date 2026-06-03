<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Plugin\QueueWorker;

use Drupal\aseguramiento_automation\Entity\ConstanciaEntity;
use Drupal\aseguramiento_automation\Service\CoordinateMappingService;
use Drupal\aseguramiento_automation\Service\PdfOverlayService;
use Drupal\aseguramiento_automation\Service\PdfTemplateService;
use Drupal\aseguramiento_automation\Service\QueueManagerService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Generates final PDFs from corporate base templates.
 *
 * @QueueWorker(
 *   id = "aseguramiento_pdf_generation",
 *   title = @Translation("Aseguramiento PDF generation"),
 *   cron = {"time" = 120}
 * )
 */
final class PdfGenerationQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly PdfTemplateService $templateService,
    private readonly PdfOverlayService $pdfOverlayService,
    private readonly CoordinateMappingService $mappingService,
    private readonly QueueManagerService $queueManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('aseguramiento_automation.pdf_template'),
      $container->get('aseguramiento_automation.pdf_overlay'),
      $container->get('aseguramiento_automation.coordinate_mapping'),
      $container->get('aseguramiento_automation.queue_manager'),
    );
  }

  public function processItem($data): void {
    $entity = $this->entityTypeManager->getStorage('aseguramiento_constancia')->load($data['constancia_id'] ?? NULL);
    if (!$entity) {
      throw new \RuntimeException('Constancia not found.');
    }
    $row = [];
    foreach (array_merge(['folio', 'nombre', 'poliza', 'suma_asegurada', 'rfc', 'email', 'telefono', 'aseguradora', 'tipo_documento', 'company_id'], ConstanciaEntity::solicitudPdfFields()) as $field) {
      $row[$field] = (string) $entity->get($field)->value;
    }
    $row['vigencia_inicio'] = (string) $entity->get('vigencia')->value;
    $row['vigencia_fin'] = (string) $entity->get('vigencia')->end_value;
    $template = $this->templateService->resolve($row);
    if (!$template) {
      $entity->set('status', 'error');
      $entity->set('errores', trim((string) $entity->get('errores')->value . "\nNo se encontró una plantilla PDF activa para este registro."));
      $entity->save();
      return;
    }
    $generated = $this->pdfOverlayService->generate($template, $row, $this->mappingService);
    $entity->set('plantilla_usada', $template->id());
    $entity->set('pdf_generado', $generated['uri']);
    $entity->set('status', 'pdf_generated');
    $entity->save();
    $this->queueManager->enqueue(QueueManagerService::MAIL_QUEUE, ['constancia_id' => $entity->id()]);
  }

}
