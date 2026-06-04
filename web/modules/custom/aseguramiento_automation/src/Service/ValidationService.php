<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Validates normalized constancia data.
 */
final class ValidationService {

  public function __construct(private readonly EntityTypeManagerInterface $entityTypeManager) {
  }

  public function validateRow(array $row): array {
    $errors = [];
    foreach (['solicitante', 'beneficiario_nombre', 'mercancia_asegurada', 'fecha_inicio_seguro', 'origen_ciudad', 'destino_ciudad', 'medio_transporte', 'valor_factura', 'suma_asegurada_total'] as $required) {
      if (trim((string) ($row[$required] ?? '')) === '') {
        $errors[$required][] = 'El campo obligatorio está vacío.';
      }
    }
    if (!empty($row['email']) && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
      $errors['email'][] = 'El correo electrónico no es válido.';
    }
    if (!empty($row['rfc']) && !preg_match('/^[A-Z&Ñ]{3,4}\d{6}[A-Z0-9]{3}$/i', (string) $row['rfc'])) {
      $errors['rfc'][] = 'El RFC no es válido.';
    }
    if (($row['suma_asegurada'] ?? '') !== '' && !is_numeric(str_replace([',', '$'], '', (string) $row['suma_asegurada']))) {
      $errors['suma_asegurada'][] = 'El monto debe ser numérico.';
    }
    foreach (['valor_factura', 'gastos_fletes', 'gastos_incrementales', 'seguro_contenedor', 'suma_asegurada_total'] as $amount_field) {
      if (($row[$amount_field] ?? '') !== '' && !is_numeric(str_replace([',', '$', ' '], '', (string) $row[$amount_field]))) {
        $errors[$amount_field][] = 'El monto debe ser numérico.';
      }
    }
    foreach (['vigencia_inicio', 'vigencia_fin', 'solicitud_fecha', 'fecha_inicio_seguro'] as $date_field) {
      if (!empty($row[$date_field]) && strtotime((string) $row[$date_field]) === FALSE) {
        $errors[$date_field][] = 'La fecha no es válida.';
      }
    }
    if (!empty($row['poliza']) && $this->policyExists((string) $row['poliza'], (string) ($row['aseguradora'] ?? ''))) {
      $errors['poliza'][] = 'La póliza ya existe.';
    }
    return ['valid' => $errors === [], 'errors' => $errors];
  }

  private function policyExists(string $policy, string $insurer): bool {
    $query = $this->entityTypeManager->getStorage('aseguramiento_constancia')->getQuery()
      ->accessCheck(FALSE)
      ->condition('poliza', $policy);
    if ($insurer !== '') {
      $query->condition('aseguradora', $insurer);
    }
    return (bool) $query->range(0, 1)->execute();
  }

}
