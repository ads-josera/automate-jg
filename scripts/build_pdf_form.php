<?php

/**
 * @file
 * Builds the fillable request PDF from the designer's PDF.
 *
 * The design (Illustrator export, no form fields) is imported untouched with
 * FPDI and TCPDF adds one AcroForm field on top of each light-blue box. Field
 * names are the request keys the system reads (the same as the hidden
 * "Datos" sheet of the Excel form), so a filled PDF is validated exactly like
 * an Excel.
 *
 * In Adobe Acrobat Reader a required field shows a light red background
 * while it is empty, like the Excel. Other viewers ignore the scripts; the red
 * asterisk of the design and the server validation still apply.
 *
 * When the design changes, measure the boxes again (coordinates are PDF
 * points from the top-left corner of the Letter page) and run:
 * @code
 * ddev drush php:script scripts/build_pdf_form.php
 * @endcode
 */

declare(strict_types=1);

use setasign\Fpdi\Tcpdf\Fpdi;

$source = DRUPAL_ROOT . '/../docs/Solicitud_aseguramiento_formato.pdf';
$target = DRUPAL_ROOT . '/../docs/solicitud_aseguramiento_rellenable.pdf';

// Same lists as the Excel form's data validations.
$lists = [
  'mercancia_estado' => ['Nueva', 'Usada'],
  'medio_transporte' => ['Terrestre', 'Marítimo', 'Aéreo', 'Multimodal'],
  'moneda' => ['USD', 'PESOS'],
  'acepta_informacion_veridica' => ['SI', 'NO'],
];
$dates = ['solicitud_fecha', 'fecha_inicio_seguro'];
$amounts = ['valor_factura', 'gastos_fletes', 'gastos_incrementales', 'seguro_contenedor', 'suma_asegurada_total'];
// ValidationService::validateRow() required fields, plus the currency (the
// design marks it; amount limits depend on it).
$required = ['solicitante', 'beneficiario_nombre', 'mercancia_asegurada', 'fecha_inicio_seguro', 'origen_ciudad', 'destino_ciudad', 'medio_transporte', 'valor_factura', 'suma_asegurada_total', 'moneda'];

// key => [x0, y0, x1, y1] of the box, measured on the design.
$boxes = [
  'solicitante' => [89.6, 152.7, 306.7, 168.4],
  'solicitante_telefono' => [360.8, 152.7, 438.7, 168.4],
  'solicitud_fecha' => [495.8, 152.7, 573.7, 168.4],
  'beneficiario_nombre' => [89.6, 218.7, 573.7, 234.4],
  'beneficiario_domicilio' => [89.6, 240.7, 573.7, 256.4],
  'beneficiario_contacto' => [89.6, 262.7, 573.7, 278.4],
  'mercancia_asegurada' => [131.5, 323.7, 573.7, 339.4],
  'mercancia_estado' => [131.5, 344.7, 207.9, 360.4],
  'mercancia_referencia' => [275.8, 344.7, 573.7, 360.4],
  'fecha_inicio_seguro' => [124.5, 403.7, 229.6, 419.4],
  'medio_transporte' => [124.2, 426.3, 229.6, 442.0],
  'origen_ciudad' => [123.5, 449.7, 294.9, 465.4],
  'origen_pais' => [123.6, 471.6, 294.9, 487.3],
  'destino_ciudad' => [123.5, 494.7, 294.9, 510.4],
  'destino_pais' => [124.5, 515.9, 294.9, 531.6],
  'moneda' => [358.4, 403.7, 417.3, 419.4],
  'valor_factura' => [417.3, 426.2, 572.7, 441.9],
  'gastos_incrementales' => [417.3, 447.7, 572.7, 463.4],
  'gastos_fletes' => [417.3, 469.7, 572.7, 485.4],
  'seguro_contenedor' => [417.4, 491.4, 573.6, 507.1],
  'suma_asegurada_total' => [417.4, 513.4, 573.6, 529.1],
  'consignatario_nombre' => [81.9, 574.7, 294.9, 590.4],
  'consignatario_domicilio' => [81.9, 596.7, 294.9, 612.4],
  'consignatario_contacto' => [81.9, 618.7, 294.9, 634.4],
  'proveedor_nombre' => [362.5, 574.7, 573.9, 590.4],
  'proveedor_domicilio' => [362.5, 596.7, 573.9, 612.4],
  'proveedor_contacto' => [362.5, 618.7, 573.9, 634.4],
  'ref_terrestre' => [80.5, 676.7, 148.6, 692.4],
  'ref_talon_embarque' => [108.7, 698.7, 149.1, 714.4],
  'ref_contenedor_caja' => [108.7, 720.7, 149.1, 736.4],
  'ref_maritimo' => [208.5, 676.7, 251.1, 692.4],
  'ref_maritimo_bl' => [208.5, 698.7, 251.1, 714.4],
  'ref_maritimo_contenedor' => [208.5, 720.7, 251.1, 736.4],
  'ref_aereo' => [295.5, 676.7, 338.1, 692.4],
  'ref_aereo_guia' => [295.5, 698.7, 338.1, 714.4],
  'ref_aereo_linea' => [295.5, 720.7, 338.1, 736.4],
  'acepta_informacion_veridica' => [382.4, 676.7, 425.0, 692.4],
];

// PDF literal string for an action script.
$js = static fn(string $code): string => '<< /S /JavaScript /JS (' . strtr($code, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']) . ') >>';

$pdf = new Fpdi('P', 'pt', 'LETTER', TRUE, 'UTF-8', FALSE);
$pdf->setPrintHeader(FALSE);
$pdf->setPrintFooter(FALSE);
$pdf->setMargins(0, 0, 0);
$pdf->setAutoPageBreak(FALSE, 0);
$pdf->setCreator('JG Mylard');
$pdf->setTitle('Solicitud de aseguramiento de mercancía');
$pdf->setSubject('Formato rellenable de solicitud');
$pdf->setSourceFile($source);
$pdf->AddPage('P', 'LETTER');
$pdf->useTemplate($pdf->importPage(1), 0, 0, 612, 792);
$pdf->setFont('helvetica', '', 9);
// Transparent fields: the design's light-blue boxes show through.
$pdf->setFormDefaultProp(['lineWidth' => 0, 'borderStyle' => 'solid', 'fillColor' => [], 'strokeColor' => []]);

// Light red (#FBD5D5, as in the Excel) while a required field is empty.
$pdf->IncludeJS(implode("\n", [
  'var AA_OBLIGATORIOS = ' . json_encode($required) . ';',
  'function aaColor(campo, valor) {',
  '  var vacio = (valor === undefined || valor === null || String(valor).replace(/\s+/g, "") === "");',
  '  campo.fillColor = vacio ? ["RGB", 0.984, 0.835, 0.835] : color.transparent;',
  '}',
  'function aaMarcarTodos() {',
  '  for (var i = 0; i < AA_OBLIGATORIOS.length; i++) {',
  '    var campo = this.getField(AA_OBLIGATORIOS[i]);',
  '    if (campo) { aaColor(campo, campo.value); }',
  '  }',
  '}',
  'aaMarcarTodos();',
]));

foreach ($boxes as $key => [$x0, $y0, $x1, $y1]) {
  $w = $x1 - $x0;
  $h = $y1 - $y0;
  $is_required = in_array($key, $required, TRUE);
  $prop = ['required' => $is_required];
  $actions = [];
  if ($is_required) {
    $actions['V'] = 'aaColor(event.target, event.value);';
  }
  if (in_array($key, $dates, TRUE)) {
    $actions['F'] = 'AFDate_FormatEx("dd/mm/yyyy");';
    $actions['K'] = 'AFDate_KeystrokeEx("dd/mm/yyyy");';
  }
  if (in_array($key, $amounts, TRUE)) {
    $actions['F'] = 'AFNumber_Format(2, 0, 0, 0, "", true);';
    $actions['K'] = 'AFNumber_Keystroke(2, 0, 0, 0, "", true);';
  }
  $aa = implode(' ', array_map(static fn(string $event, string $code): string => '/' . $event . ' ' . $js($code), array_keys($actions), $actions));
  $opt = $aa !== '' ? ['aa' => $aa] : [];

  if (isset($lists[$key])) {
    // First option empty: nothing is chosen until the client picks one. An
    // empty appearance too: TCPDF draws every option stacked by default.
    $pdf->ComboBox($key, $w, $h, array_merge([''], $lists[$key]), $prop, $opt + ['ap' => ['n' => '/Tx BMC EMC']], $x0, $y0);
  }
  else {
    $pdf->TextField($key, $w, $h, $prop, $opt, $x0, $y0);
  }
}

// Fixed values the Excel form also carries (hidden "Datos" sheet): they pick
// the constancia template. Hidden and read-only: part of the form, not of
// what the client fills.
foreach (['aseguradora' => 'Seguros Atlas', 'tipo_documento' => 'Constancia'] as $key => $value) {
  $pdf->TextField($key, 1, 1, ['readonly' => TRUE], ['v' => $value, 'f' => ['invisible', 'hidden']], 1, 1);
}

$pdf->Output($target, 'F');
echo 'Formato rellenable: ' . basename($target) . ' (' . count($boxes) . ' campos, ' . count($required) . ' obligatorios, 2 fijos ocultos)' . PHP_EOL;
