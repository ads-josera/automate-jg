<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Service;

use Drupal\aseguramiento_automation\Util\DateNormalizer;
use Drupal\aseguramiento_automation\Util\SumaAsegurada;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Validates normalized constancia data.
 */
final class ValidationService {

  /**
   * Required request fields; the forms mark the same ones (red in the Excel,
   * asterisk and red background in the PDF). "Suma asegurada total" is not
   * among them: it is computed (see SumaAsegurada).
   */
  public const REQUIRED = [
    'solicitante',
    'beneficiario_nombre',
    'mercancia_asegurada',
    'fecha_inicio_seguro',
    'origen_ciudad',
    'destino_ciudad',
    'medio_transporte',
    'moneda',
    'valor_factura',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AmountLimits $amountLimits,
  ) {
  }

  /**
   * Validates a request row.
   *
   * @param int|null $exclude_id
   *   Constancia the row belongs to, when validating it again: its own
   *   policy number must not count as already existing.
   */
  public function validateRow(array $row, ?int $exclude_id = NULL): array {
    $errors = [];
    foreach (self::REQUIRED as $required) {
      if (trim((string) ($row[$required] ?? '')) === '') {
        $errors[$required][] = 'El campo obligatorio está vacío.';
      }
    }
    // Amounts are only meaningful with their currency (and amount limits
    // depend on it): only the two options of the request form.
    $currency = mb_strtolower(trim((string) ($row['moneda'] ?? '')));
    if ($currency !== '' && !in_array($currency, ['usd', 'pesos'], TRUE)) {
      $errors['moneda'][] = 'La moneda no es válida.';
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
    foreach (SumaAsegurada::COMPONENTS as $amount_field) {
      if (trim((string) ($row[$amount_field] ?? '')) === '') {
        continue;
      }
      $amount = SumaAsegurada::amount($row[$amount_field]);
      if ($amount === NULL) {
        $errors[$amount_field][] = 'El monto debe ser numérico.';
      }
      elseif ($amount < 0) {
        $errors[$amount_field][] = 'El monto no puede ser negativo.';
      }
    }
    // The insured total (always computed) must be within the limits of its
    // currency; limits themselves are accepted.
    $total = SumaAsegurada::total($row);
    $code = SumaAsegurada::currencyCode((string) ($row['moneda'] ?? ''));
    $limits = $code !== '' ? $this->amountLimits->forCurrency($code) : NULL;
    if ($total !== NULL && $limits !== NULL && !isset($errors['moneda'])) {
      if ($total < $limits['min']) {
        $errors['suma_asegurada_total'][] = sprintf('%s es menor que el mínimo de %s.', SumaAsegurada::money($total, $code), SumaAsegurada::money($limits['min'], $code));
      }
      elseif ($total > $limits['max']) {
        $errors['suma_asegurada_total'][] = sprintf('%s supera el máximo de %s.', SumaAsegurada::money($total, $code), SumaAsegurada::money($limits['max'], $code));
      }
    }
    foreach (['vigencia_inicio', 'vigencia_fin', 'solicitud_fecha', 'fecha_inicio_seguro'] as $date_field) {
      if (!empty($row[$date_field]) && DateNormalizer::toIso($row[$date_field]) === NULL) {
        $errors[$date_field][] = 'La fecha no es válida.';
      }
    }
    if (!empty($row['poliza']) && $this->policyExists((string) $row['poliza'], (string) ($row['aseguradora'] ?? ''), $exclude_id)) {
      $errors['poliza'][] = 'La póliza ya existe.';
    }
    return ['valid' => $errors === [], 'errors' => $errors];
  }

  private function policyExists(string $policy, string $insurer, ?int $exclude_id = NULL): bool {
    $query = $this->entityTypeManager->getStorage('aseguramiento_constancia')->getQuery()
      ->accessCheck(FALSE)
      ->condition('poliza', $policy);
    if ($exclude_id !== NULL) {
      $query->condition('id', $exclude_id, '<>');
    }
    if ($insurer !== '') {
      $query->condition('aseguradora', $insurer);
    }
    return (bool) $query->range(0, 1)->execute();
  }

}
