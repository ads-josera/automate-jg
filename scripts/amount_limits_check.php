<?php

/**
 * @file
 * Check of the insured total rules (server side, every request format).
 *
 * - The total is always computed: valor factura + fletes + incrementales +
 *   seguro del contenedor; what the form says in "suma asegurada total" is
 *   ignored.
 * - It must be within the limits of its currency (settings); the limits
 *   themselves are accepted.
 * - No amount may be negative.
 * - The client reads each problem as a sentence with amounts and currency.
 *
 * Usage: ddev drush php:script scripts/amount_limits_check.php
 */

declare(strict_types=1);

use Drupal\aseguramiento_automation\Util\SolicitudErrorFormatter;
use Drupal\aseguramiento_automation\Util\SumaAsegurada;

$validation = \Drupal::service('aseguramiento_automation.validation');
$limits = \Drupal::service('aseguramiento_automation.amount_limits');
$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
  echo ($ok ? '  OK    ' : '  FALLA ') . $label . PHP_EOL;
  $failures += $ok ? 0 : 1;
};

$base = [
  'solicitante' => 'Cliente', 'beneficiario_nombre' => 'Beneficiario', 'mercancia_asegurada' => 'Mercancía',
  'fecha_inicio_seguro' => '24/09/2026', 'origen_ciudad' => 'Monterrey', 'destino_ciudad' => 'CDMX',
  'medio_transporte' => 'Terrestre',
];
// Sentences the client would read for the insured total / amounts.
$sentences = static function (array $row) use ($validation): array {
  $result = $validation->validateRow($row);
  return $result['valid'] ? [] : SolicitudErrorFormatter::describe(json_encode($result['errors'], JSON_UNESCAPED_UNICODE))['fields'];
};

echo 'Límites configurados' . PHP_EOL;
$check($limits->forCurrency('USD') === ['min' => 25.0, 'max' => 600000.0], 'USD 25 – 600,000');
$check($limits->forCurrency('MXN') === ['min' => 500.0, 'max' => 12000000.0], 'MXN 500 – 12,000,000');

echo PHP_EOL . 'La suma se calcula siempre' . PHP_EOL;
$check(SumaAsegurada::total(['valor_factura' => '300,000', 'gastos_fletes' => '$200,000.00', 'gastos_incrementales' => '', 'seguro_contenedor' => '100000']) === 600000.0, '300,000 + $200,000.00 + (vacío) + 100000 = 600,000');
$check(SumaAsegurada::total(['valor_factura' => '', 'gastos_fletes' => '']) === NULL, 'Sin montos no hay suma');
$check($sentences($base + ['moneda' => 'USD', 'valor_factura' => '1000', 'suma_asegurada_total' => '99999999']) === [], 'Una suma escrita a mano (99,999,999) se ignora: cuenta la de los montos');

echo PHP_EOL . 'Rango en USD (los límites se aceptan)' . PHP_EOL;
foreach ([['25', TRUE], ['600000', TRUE], ['24.99', FALSE], ['600000.01', FALSE]] as [$amount, $valid]) {
  $check(($sentences($base + ['moneda' => 'USD', 'valor_factura' => $amount]) === []) === $valid, "$amount USD " . ($valid ? 'se acepta' : 'se rechaza'));
}
$check(($sentences($base + ['moneda' => 'USD', 'valor_factura' => '300000', 'gastos_fletes' => '200000', 'seguro_contenedor' => '100000.01']) === []) === FALSE, 'Se compara la suma, no cada monto: 300,000 + 200,000 + 100,000.01 USD se rechaza');

echo PHP_EOL . 'Rango en MXN' . PHP_EOL;
foreach ([['500', TRUE], ['12,000,000', TRUE], ['499.99', FALSE], ['12000000.01', FALSE]] as [$amount, $valid]) {
  $check(($sentences($base + ['moneda' => 'PESOS', 'valor_factura' => $amount]) === []) === $valid, "$amount MXN " . ($valid ? 'se acepta' : 'se rechaza'));
}
$check(($sentences($base + ['moneda' => 'PESOS', 'valor_factura' => '600000']) === []), 'La misma cantidad cambia según la moneda: 600,000 MXN sí es válido');

echo PHP_EOL . 'Lo que lee el cliente' . PHP_EOL;
$over = $sentences($base + ['moneda' => 'USD', 'valor_factura' => '700000']);
$check($over === ['Suma asegurada total: $700,000.00 USD supera el máximo de $600,000.00 USD.'], 'Máximo: "' . implode(' | ', $over) . '"');
$under = $sentences($base + ['moneda' => 'PESOS', 'valor_factura' => '100']);
$check($under === ['Suma asegurada total: $100.00 MXN es menor que el mínimo de $500.00 MXN.'], 'Mínimo: "' . implode(' | ', $under) . '"');
$negative = $sentences($base + ['moneda' => 'USD', 'valor_factura' => '1000', 'gastos_fletes' => '-50']);
$check(in_array('Gastos de fletes: no puede ser negativo', $negative, TRUE), 'Negativo: "' . implode(' | ', $negative) . '"');
$currency = $sentences($base + ['moneda' => 'Euros', 'valor_factura' => '1000']);
$check($currency === ['Moneda: elige USD o PESOS'], 'Moneda inválida: solo ese aviso, sin rango ("' . implode(' | ', $currency) . '")');

if ($failures) {
  throw new \RuntimeException("RESULTADO: {$failures} comprobaciones fallaron.");
}
echo PHP_EOL . 'RESULTADO: todas las comprobaciones pasaron.' . PHP_EOL;
