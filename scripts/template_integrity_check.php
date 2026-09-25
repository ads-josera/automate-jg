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
$filled = array_filter($inputs, static fn(string $ref): bool => !in_array($form->getCell($ref)->getValue(), [NULL, ''], TRUE));
$check($filled === [], 'Todas las celdas de captura están vacías' . ($filled ? ' (con datos: ' . implode(', ', $filled) . ')' : ''));
$locked = array_filter($inputs, static fn(string $ref): bool => $form->getStyle($ref)->getProtection()->getLocked() !== 'unprotected');
$check($locked === [], 'Todas las celdas de captura están desbloqueadas' . ($locked ? ' (bloqueadas: ' . implode(', ', $locked) . ')' : ''));
$protection = $form->getProtection();
$check((bool) $protection->getSheet(), 'La hoja está protegida');
$check(!$protection->getSelectUnlockedCells(), 'Se pueden seleccionar las celdas de captura');
$check($protection->getInsertRows() && $protection->getDeleteRows() && $protection->getInsertColumns() && $protection->getDeleteColumns(), 'No se pueden insertar ni borrar filas o columnas');
$check(count($form->getConditionalStylesCollection()) === 9, 'Alerta roja en los 9 campos obligatorios (hay ' . count($form->getConditionalStylesCollection()) . ')');
$dates = array_filter($form->getDataValidationCollection(), static fn($v): bool => $v->getType() === 'date');
$date_ranges = implode(' ', array_keys($dates));
$check(str_contains($date_ranges, 'J8') && str_contains($date_ranges, 'D23'), 'Validación de fecha en "Fecha" (J8) y "Fecha inicio seguro" (D23)');
$check(isset($inputs['ref_maritimo'], $inputs['ref_aereo']), 'Incluye las referencias marítima y aérea');
$check($data->getSheetState() === 'veryHidden', 'La hoja "Datos" sigue oculta');

if ($failures) {
  throw new \RuntimeException("RESULTADO: {$failures} comprobaciones fallaron. No repartas este formato.");
}
echo PHP_EOL . 'RESULTADO: el formato está limpio y completo.' . PHP_EOL;
