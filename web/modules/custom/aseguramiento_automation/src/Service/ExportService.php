<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Service;

use Drupal\aseguramiento_automation\Entity\ConstanciaEntity;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Builds filtered export data.
 */
final class ExportService {

  public function __construct(private readonly EntityTypeManagerInterface $entityTypeManager) {
  }

  public function toRows(array $filters): \Generator {
    $base_fields = ['folio', 'nombre', 'poliza', 'suma_asegurada', 'vigencia_inicio', 'vigencia_fin', 'rfc', 'email', 'telefono', 'aseguradora', 'tipo_documento'];
    $request_fields = ConstanciaEntity::solicitudPdfFields();
    yield array_merge($base_fields, $request_fields, ['status', 'provider_correo', 'fecha_procesamiento']);
    $storage = $this->entityTypeManager->getStorage('aseguramiento_constancia');
    $query = $storage->getQuery()->accessCheck(TRUE)->sort('created', 'DESC');
    foreach (['aseguradora', 'status', 'poliza'] as $field) {
      if (!empty($filters[$field])) {
        $query->condition($field, $filters[$field], 'CONTAINS');
      }
    }
    if (!empty($filters['cliente'])) {
      $query->condition('nombre', $filters['cliente'], 'CONTAINS');
    }
    if (!empty($filters['date_from'])) {
      $query->condition('created', strtotime((string) $filters['date_from']), '>=');
    }
    if (!empty($filters['date_to'])) {
      $query->condition('created', strtotime((string) $filters['date_to'] . ' 23:59:59'), '<=');
    }
    foreach ($storage->loadMultiple($query->execute()) as $entity) {
      $row = [
        $entity->get('folio')->value,
        $entity->get('nombre')->value,
        $entity->get('poliza')->value,
        $entity->get('suma_asegurada')->value,
        $entity->get('vigencia')->value,
        $entity->get('vigencia')->end_value,
        $entity->get('rfc')->value,
        $entity->get('email')->value,
        $entity->get('telefono')->value,
        $entity->get('aseguradora')->value,
        $entity->get('tipo_documento')->value,
      ];
      foreach ($request_fields as $field) {
        $row[] = $entity->get($field)->value;
      }
      $row[] = $entity->get('status')->value;
      $row[] = $entity->get('provider_correo')->value;
      $row[] = date(DATE_ATOM, (int) $entity->get('fecha_procesamiento')->value);
      yield $row;
    }
  }

}
