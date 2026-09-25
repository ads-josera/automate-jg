<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Util;

use Drupal\aseguramiento_automation\Entity\ConstanciaEntity;

/**
 * Turns stored constancia errors into sentences a client can act on.
 *
 * The "errores" field holds either the JSON written by ValidationService
 * ({"field": ["message"]}: something the client must fix in the form) or
 * plain text lines appended by the pipeline (an internal problem the client
 * cannot fix). Both kinds are told apart so the reply never asks a client to
 * correct something that is not their fault.
 */
final class SolicitudErrorFormatter {

  private const MESSAGES = [
    'El campo obligatorio está vacío.' => 'falta llenarlo',
    'La fecha no es válida.' => 'la fecha no es válida; escríbela como 24/09/2026',
    'El monto debe ser numérico.' => 'debe ser una cantidad, por ejemplo 150000.00',
    'El correo electrónico no es válido.' => 'el correo electrónico no es válido',
    'El RFC no es válido.' => 'el RFC no es válido',
    'La póliza ya existe.' => 'esa póliza ya fue registrada',
  ];

  private const EXTRA_LABELS = [
    'email' => 'Correo electrónico',
    'rfc' => 'RFC',
    'poliza' => 'Póliza',
    'suma_asegurada' => 'Suma asegurada',
    'vigencia_inicio' => 'Inicio de vigencia',
    'vigencia_fin' => 'Fin de vigencia',
    'mercancia_estado' => 'Mercancía - Estado',
    'moneda' => 'Moneda',
    'acepta_informacion_veridica' => 'Acepto que la información es verídica',
  ];

  /**
   * Splits stored errors into client-fixable lines and internal problems.
   *
   * @return array{fields: string[], internal: string[]}
   *   "fields": one sentence per invalid form field; "internal": pipeline
   *   problems (kept for the team, never shown as something to correct).
   */
  public static function describe(?string $errores): array {
    $result = ['fields' => [], 'internal' => []];
    $errores = trim((string) $errores);
    if ($errores === '') {
      return $result;
    }
    $lines = preg_split('/\R/', $errores) ?: [];
    $first = array_shift($lines);
    $decoded = json_decode((string) $first, TRUE);
    if (is_array($decoded)) {
      foreach ($decoded as $field => $messages) {
        foreach ((array) $messages as $message) {
          $result['fields'][] = self::label((string) $field) . ': ' . (self::MESSAGES[$message] ?? mb_strtolower((string) $message));
        }
      }
    }
    else {
      array_unshift($lines, (string) $first);
    }
    foreach ($lines as $line) {
      if (trim($line) !== '') {
        $result['internal'][] = trim($line);
      }
    }
    return $result;
  }

  /**
   * Label of a field as the client sees it in the request form.
   */
  public static function label(string $field): string {
    $labels = array_map('strval', ConstanciaEntity::solicitudStringFields() + ConstanciaEntity::solicitudLongTextFields() + ConstanciaEntity::solicitudDateFields() + ConstanciaEntity::solicitudAmountFields()) + self::EXTRA_LABELS;
    return $labels[$field] ?? $field;
  }

}
