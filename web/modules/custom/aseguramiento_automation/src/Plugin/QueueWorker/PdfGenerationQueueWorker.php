<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Plugin\QueueWorker;

use Drupal\aseguramiento_automation\Entity\ConstanciaEntity;
use Drupal\aseguramiento_automation\Service\CoordinateMappingService;
use Drupal\aseguramiento_automation\Service\PdfOverlayService;
use Drupal\aseguramiento_automation\Service\PdfTemplateService;
use Drupal\aseguramiento_automation\Service\QueueManagerService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Psr\Log\LoggerInterface;
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
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
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
      $container->get('config.factory'),
      $container->get('logger.channel.aseguramiento_automation'),
    );
  }

  public function processItem($data): void {
    $start = microtime(TRUE);
    $debug = (bool) $this->configFactory->get('aseguramiento_automation.settings')->get('debug_mode');

    $entity = NULL;
    try {
      $entity = $this->entityTypeManager->getStorage('aseguramiento_constancia')->load($data['constancia_id'] ?? NULL);
      if (!$entity) {
        throw new \RuntimeException('No se encontró la constancia solicitada.');
      }
      $this->logger->info('[Aseguramiento] Iniciando generación de PDF para el registro @id. Folio: @folio.', [
        '@id' => $entity->id(),
        '@folio' => (string) $entity->get('folio')->value,
      ]);
      $row = [];
      foreach (array_merge(['folio', 'nombre', 'poliza', 'suma_asegurada', 'rfc', 'email', 'telefono', 'aseguradora', 'tipo_documento', 'company_id'], ConstanciaEntity::solicitudPdfFields()) as $field) {
        $row[$field] = (string) $entity->get($field)->value;
      }
      $row['vigencia_inicio'] = (string) $entity->get('vigencia')->value;
      $row['vigencia_fin'] = (string) $entity->get('vigencia')->end_value;
      $row['fecha_emision'] = date('j/m/Y g:iA');
      $row['fecha_emision_iso'] = date('c');
      $template = $this->templateService->resolve($row);
      if (!$template) {
        $entity->set('status', 'error');
        $entity->set('errores', trim((string) $entity->get('errores')->value . "\nNo se encontró una plantilla PDF activa para este registro."));
        $entity->save();
        $this->logger->warning('[Aseguramiento] No se encontró una plantilla PDF activa para el registro @id.', ['@id' => $entity->id()]);
        return;
      }
      if ($debug) {
        $this->logger->info('[Aseguramiento][Depuración] Plantilla seleccionada: @template. Campos disponibles: @fields.', [
          '@template' => $template->id(),
          '@fields' => implode(', ', array_keys($row)),
        ]);
      }
      $generated = $this->pdfOverlayService->generate($template, $row, $this->mappingService);
      $entity->set('plantilla_usada', $template->id());
      $entity->set('pdf_generado', $generated['uri']);
      $entity->set('status', 'pdf_generated');
      $entity->save();
      $this->logger->info('[Aseguramiento] PDF asociado correctamente al registro @id. Archivo: @uri.', [
        '@id' => $entity->id(),
        '@uri' => $generated['uri'],
      ]);
      if ($debug) {
        $this->logger->info('[Aseguramiento][Depuración] Generación de PDF finalizada en @time ms.', [
          '@time' => number_format((microtime(TRUE) - $start) * 1000, 2),
        ]);
      }
      // Constancias from a batch are answered together by the batch reply.
      if ((string) $entity->get('lote')->value === '') {
        $this->queueManager->enqueue(QueueManagerService::MAIL_QUEUE, ['constancia_id' => $entity->id()]);
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('[Aseguramiento] Error durante la generación del PDF. Detalle: @error', [
        '@error' => $e->getMessage(),
      ]);
      if (!$entity) {
        throw $e;
      }
      // Never leave it "validated": its batch would wait for a PDF forever.
      $entity->set('status', 'error');
      $entity->set('errores', trim((string) $entity->get('errores')->value . "\nNo fue posible generar el PDF: " . $e->getMessage()));
      $entity->save();
      if ((string) $entity->get('lote')->value === '') {
        throw $e;
      }
    }
  }

}
