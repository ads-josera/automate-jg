<?php

/**
 * @file
 * Check of the fillable PDF reader against PDFs saved by different tools.
 *
 * Fixtures in scripts/fixtures/pdf-form were filled with valores.json:
 * - A: full rewrite; B: incremental update ("Guardar" in Acrobat);
 * - C: object streams + compressed cross-reference (newer writers);
 * - D: macOS PDFKit (Vista Previa); an empty field keeps an empty value;
 * - E: three incremental saves, the last one changes two values.
 * Empty fields must stay empty (not take the next field's value), accents,
 * parentheses and backslashes must survive, and the latest save wins.
 *
 * Usage: ddev drush php:script scripts/pdf_form_check.php
 */

declare(strict_types=1);

$dir = DRUPAL_ROOT . '/../scripts/fixtures/pdf-form/';
$expected = json_decode((string) file_get_contents($dir . 'valores.json'), TRUE);
$parser = \Drupal::service('aseguramiento_automation.pdf_form_parser');
$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
  echo ($ok ? '  OK    ' : '  FALLA ') . $label . PHP_EOL;
  $failures += $ok ? 0 : 1;
};

$cases = [
  'A_pypdf_completo.pdf' => [],
  'B_pypdf_incremental.pdf' => [],
  'C_objstm_comprimido.pdf' => [],
  'D_vista_previa_mac.pdf' => [],
  'E_dos_guardados.pdf' => ['origen_ciudad' => 'Guadalajara', 'moneda' => 'PESOS'],
];
foreach ($cases as $file => $changes) {
  echo PHP_EOL . $file . PHP_EOL;
  try {
    $row = $parser->parse($dir . $file)[0];
  }
  catch (\Throwable $e) {
    $check(FALSE, 'Se puede leer (' . $e->getMessage() . ')');
    continue;
  }
  $want = $changes + $expected;
  $wrong = [];
  foreach ($want as $key => $value) {
    if (($row[$key] ?? NULL) !== $value) {
      $wrong[] = "$key = " . var_export($row[$key] ?? NULL, TRUE) . " (esperado " . var_export($value, TRUE) . ")";
    }
  }
  $check($wrong === [], 'Los 15 campos llenos se leen igual' . ($wrong ? ': ' . implode('; ', array_slice($wrong, 0, 4)) : ''));
  $leaked = array_filter(['solicitante_telefono', 'beneficiario_domicilio', 'origen_pais', 'gastos_fletes', 'ref_aereo_linea'], static fn(string $key): bool => ($row[$key] ?? '') !== '');
  $check($leaked === [], 'Los campos vacíos siguen vacíos' . ($leaked ? ' (con valor: ' . implode(', ', array_map(static fn($k) => "$k=" . $row[$k], $leaked)) . ')' : ''));
  $check(count(array_filter(array_keys($row), static fn($k) => $k[0] !== '_')) === 39, 'Se detectan los 39 campos, 2 de ellos fijos (detectados: ' . count(array_filter(array_keys($row), static fn($k) => $k[0] !== '_')) . ')');
  $check(($row['aseguradora'] ?? '') === 'Seguros Atlas' && ($row['tipo_documento'] ?? '') === 'Constancia', 'Trae la aseguradora y el tipo de documento fijos del formato');
}

echo PHP_EOL . 'I_vista_previa_real.pdf (llenado y guardado por el usuario en la Vista Previa de Mac)' . PHP_EOL;
$row = $parser->parse($dir . 'I_vista_previa_real.pdf')[0];
$check(($row['beneficiario_nombre'] ?? '') === 'José Raúl Perea Herrera' && ($row['mercancia_referencia'] ?? '') === 'son de 27”', 'Acentos y comillas tipográficas intactos');
$check(\Drupal\aseguramiento_automation\Util\DateNormalizer::toIso($row['fecha_inicio_seguro'] ?? '') === '2026-10-30' && \Drupal\aseguramiento_automation\Util\DateNormalizer::toIso($row['solicitud_fecha'] ?? '') === '2026-10-29', 'Fechas escritas "30-octubre-2026" y "29-octubre-26" se entienden');
$check(\Drupal::service('aseguramiento_automation.validation')->validateRow($row)['valid'], 'La solicitud pasa la validación');

echo PHP_EOL . 'El formato para repartir trae sus ayudas (Acrobat Reader)' . PHP_EOL;
$form = (string) file_get_contents(DRUPAL_ROOT . '/../docs/solicitud_aseguramiento_rellenable.pdf');
// TCPDF writes the document script as a UTF-16BE text string.
$utf16 = static fn(string $text): string => mb_convert_encoding($text, 'UTF-16BE', 'UTF-8');
$check(str_contains($form, '/JavaScript') && str_contains($form, $utf16('aaMarcarTodos')) && str_contains($form, $utf16('var aaDoc = this;')), 'Script del fondo rojo en obligatorios vacíos (sin depender de "this" dentro de funciones)');
$check(substr_count($form, 'AFDate_FormatEx') === 2, 'Formato de fecha dd/mm/yyyy en las 2 fechas');
// 10 required + 1 optional date + 3 optional amounts + 2 optional lists.
$check(substr_count($form, '/TU') === 16, 'Texto de ayuda en fechas, montos, listas y obligatorios (' . substr_count($form, '/TU') . ' de 16 campos)');

echo PHP_EOL . 'G_xref_danada.pdf (la tabla de objetos apunta a un lugar equivocado)' . PHP_EOL;
$row = $parser->parse($dir . 'G_xref_danada.pdf')[0];
$check(($row['solicitante'] ?? '') === $expected['solicitante'] && ($row['solicitante_telefono'] ?? 'x') === '', 'Se lee igual recorriendo los objetos del archivo');

echo PHP_EOL . 'Casos que deben rechazarse con un mensaje claro' . PHP_EOL;
foreach ([
  'F_protegido.pdf' => 'contraseña',
  '../../../docs/Solicitud_aseguramiento_formato.pdf' => 'no tiene campos rellenables',
] as $file => $words) {
  try {
    $parser->parse($dir . $file);
    $check(FALSE, basename($file) . ' se rechaza');
  }
  catch (\RuntimeException $e) {
    $check(str_contains($e->getMessage(), $words), basename($file) . ': "' . $e->getMessage() . '"');
  }
}
$blank = $parser->parse(DRUPAL_ROOT . '/../docs/solicitud_aseguramiento_rellenable.pdf')[0];
$check(count(array_filter($blank, static fn($v, $k) => $k[0] !== '_' && $v === '', ARRAY_FILTER_USE_BOTH)) === 37, 'El formato en blanco da 37 campos vacíos (más los 2 fijos)');

if ($failures) {
  throw new \RuntimeException("RESULTADO: {$failures} comprobaciones fallaron.");
}
echo PHP_EOL . 'RESULTADO: todas las comprobaciones pasaron.' . PHP_EOL;
