<?php

/**
 * @file
 * Integrity check of the request template sent to clients.
 *
 * Guards against shipping a template with someone's test data in it (it
 * happened once: a filled test was saved over docs/ and committed) and
 * against losing the protections the template relies on.
 *
 * Usage (local DDEV):
 * @code
 * ddev drush php:script scripts/template_integrity_check.php
 * ddev drush php:script scripts/template_integrity_check.php -- path/to.xlsx
 * @endcode
 */

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

$path = $extra[0] ?? DRUPAL_ROOT . '/../docs/solicitud_aseguramiento_formato_jr_preparado.xlsx';
$book = IOFactory::load($path);
$form = $book->getSheetByName('Solicitud');
$data = $book->getSheetByName('Datos');
$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
  echo ($ok ? '  OK    ' : '  FALLA ') . $label . PHP_EOL;
  $failures += $ok ? 0 : 1;
};

// Input cells are the ones the hidden "Datos" sheet reads.
$inputs = [];
for ($col = 1; $col <= Coordinate::columnIndexFromString($data->getHighestColumn()); $col++) {
  $letter = Coordinate::stringFromColumnIndex($col);
  if (preg_match('/Solicitud!\$?([A-Z]+)\$?(\d+)/', (string) $data->getCell($letter . '2')->getValue(), $m)) {
    $inputs[(string) $data->getCell($letter . '1')->getValue()] = $m[1] . $m[2];
  }
}

echo 'Formato: ' . basename($path) . PHP_EOL;
$check(count($inputs) === 37, 'La hoja "Datos" lee 37 campos (lee ' . count($inputs) . ')');
// The insured total is computed (a locked formula), not captured.
$captured = array_diff_key($inputs, ['suma_asegurada_total' => TRUE]);
$filled = array_filter($captured, static fn(string $ref): bool => !in_array($form->getCell($ref)->getValue(), [NULL, ''], TRUE));
$check($filled === [], 'Todas las celdas de captura están vacías' . ($filled ? ' (con datos: ' . implode(', ', $filled) . ')' : ''));
$locked = array_filter($captured, static fn(string $ref): bool => $form->getStyle($ref)->getProtection()->getLocked() !== 'unprotected');
$check($locked === [], 'Todas las celdas de captura están desbloqueadas' . ($locked ? ' (bloqueadas: ' . implode(', ', $locked) . ')' : ''));
$protection = $form->getProtection();
$check((bool) $protection->getSheet(), 'La hoja está protegida');
$check(!$protection->getSelectUnlockedCells(), 'Se pueden seleccionar las celdas de captura');
$check($protection->getInsertRows() && $protection->getDeleteRows() && $protection->getInsertColumns() && $protection->getDeleteColumns(), 'No se pueden insertar ni borrar filas o columnas');
$required = \Drupal\aseguramiento_automation\Service\ValidationService::REQUIRED;
$alerted = [];
foreach (array_keys($form->getConditionalStylesCollection()) as $range) {
  $alerted[] = explode(':', $range)[0];
}
$missing = array_filter($required, static fn(string $key): bool => !in_array($inputs[$key] ?? '', $alerted, TRUE));
$check($missing === [], 'Alerta roja en los ' . count($required) . ' campos obligatorios' . ($missing ? ' (faltan: ' . implode(', ', $missing) . ')' : ''));
// Insured total: computed, locked, and the same limits as the settings.
$total_ref = $inputs['suma_asegurada_total'] ?? '';
$parts = array_map(static fn(string $key): string => $inputs[$key] ?? '?', \Drupal\aseguramiento_automation\Util\SumaAsegurada::COMPONENTS);
$formula = (string) $form->getCell($total_ref)->getValue();
$check($formula === sprintf('=IF(COUNT(%1$s)=0,"",SUM(%1$s))', implode(',', $parts)), "Suma asegurada total ($total_ref) = suma de valor factura, fletes, incrementales y seguro");
$check($form->getStyle($total_ref)->getProtection()->getLocked() !== 'unprotected', 'La suma está bloqueada (no se captura)');
$limits = \Drupal::service('aseguramiento_automation.amount_limits');
$range_alert = '';
foreach ($form->getConditionalStylesCollection() as $range => $conditions) {
  if (str_starts_with($range, $total_ref)) {
    $range_alert = implode(' ', $conditions[0]->getConditions());
  }
}
$n = static fn(float $value): string => rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
$expected = [];
foreach (['USD' => 'USD', 'MXN' => 'PESOS'] as $code => $option) {
  $l = $limits->forCurrency($code);
  $expected[] = $l ? sprintf('$D$29="%s",OR($D$32<%s,$D$32>%s)', $option, $n($l['min']), $n($l['max'])) : "(sin límites $code)";
}
$stale = array_filter($expected, static fn(string $part): bool => !str_contains($range_alert, $part));
$check($stale === [], 'Alerta roja de fuera de rango con los montos de la configuración' . ($stale ? ' (no coincide: ' . implode('; ', $stale) . '; vuelve a correr build_excel_form.php)' : ''));
$check(str_contains((string) $form->getCell('G32')->getValue(), 'Fuera de rango: USD'), 'Aviso "Fuera de rango" junto a la suma');
$negatives = array_filter(array_slice($parts, 0), static fn(string $ref): bool => !($form->getDataValidation($ref)->getOperator() === 'greaterThanOrEqual' && $form->getDataValidation($ref)->getFormula1() === '0'));
$check($negatives === [], 'Los 4 montos solo aceptan cantidades de 0 o más');
$footer = (string) $form->getCell('B56')->getValue();
$check(str_contains($footer, 'www.jgmylard.com') && !str_contains($footer, 'jgmylard.com.mx') && str_contains($footer, 'JG MYLARD'), 'Pie con JG MYLARD y www.jgmylard.com');
$dates = array_filter($form->getDataValidationCollection(), static fn($v): bool => $v->getType() === 'date');
$date_ranges = implode(' ', array_keys($dates));
$check(str_contains($date_ranges, 'J8') && str_contains($date_ranges, 'D23'), 'Validación de fecha en "Fecha" (J8) y "Fecha inicio seguro" (D23)');
$check(isset($inputs['ref_maritimo'], $inputs['ref_aereo']), 'Incluye las referencias marítima y aérea');
$check($data->getSheetState() === 'veryHidden', 'La hoja "Datos" sigue oculta');

if ($failures) {
  throw new \RuntimeException("RESULTADO: {$failures} comprobaciones fallaron. No repartas este formato.");
}
echo PHP_EOL . 'RESULTADO: el formato está limpio y completo.' . PHP_EOL;
