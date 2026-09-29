<?php

/**
 * @file
 * Applies the insured total rules to the Excel request form.
 *
 * - "Suma asegurada total" (D32) becomes a locked formula: the sum of valor
 *   factura, fletes, incrementales and seguro del contenedor.
 * - It turns red, and a message appears to its right (G32:K32), when it is
 *   outside the limits of the chosen currency. The limits are read from the
 *   settings, the same ones the server validates with.
 * - Each amount only accepts numbers of 0 or more.
 *
 * Idempotent: run it again after changing the limits, then check it with
 * scripts/template_integrity_check.php before handing the form out:
 * @code
 * ddev drush php:script scripts/build_excel_form.php
 * @endcode
 */

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Protection;

$path = DRUPAL_ROOT . '/../docs/solicitud_aseguramiento_formato_jr_preparado.xlsx';
$limits = \Drupal::service('aseguramiento_automation.amount_limits');
$usd = $limits->forCurrency('USD');
$mxn = $limits->forCurrency('MXN');
if (!$usd || !$mxn) {
  throw new \RuntimeException('Configura los montos permitidos (USD y MXN) antes de generar el formato.');
}

$book = IOFactory::load($path);
$sheet = $book->getSheetByName('Solicitud');
$components = ['D30', 'I30', 'D31', 'I31'];
$list = implode(',', $components);
// Excel number literal: no thousands separator, dot for decimals.
$n = static fn(float $value): string => rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
$human = static fn(float $value): string => number_format($value, floor($value) == $value ? 0 : 2);
$out_usd = sprintf('AND($D$29="USD",OR($D$32<%s,$D$32>%s))', $n($usd['min']), $n($usd['max']));
$out_mxn = sprintf('AND($D$29="PESOS",OR($D$32<%s,$D$32>%s))', $n($mxn['min']), $n($mxn['max']));

// 1. The total: a formula the client cannot overwrite.
$sheet->getCell('D32')->setValue(sprintf('=IF(COUNT(%1$s)=0,"",SUM(%1$s))', $list));
$sheet->setDataValidation('D32', NULL);
$total_style = $sheet->getStyle('D32:E32');
$total_style->getProtection()->setLocked(Protection::PROTECTION_PROTECTED);
// Light grey: reads as "computed", not as a box to fill.
$total_style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEF0F4');
// Same number format as the amounts it adds up ("$500,000.00").
$total_style->getNumberFormat()->setFormatCode($sheet->getStyle('D30')->getNumberFormat()->getFormatCode());

// 2. Red while outside the range of the chosen currency (replaces the old
// "required" alert: the total is not typed any more).
$alert = new Conditional();
$alert->setConditionType(Conditional::CONDITION_EXPRESSION);
$alert->setConditions([sprintf('AND(ISNUMBER($D$32),OR(%s,%s))', $out_usd, $out_mxn)]);
$alert->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FBD5D5');
$alert->getStyle()->getFill()->getEndColor()->setRGB('FBD5D5');
foreach (array_keys($sheet->getConditionalStylesCollection()) as $range) {
  if (str_starts_with($range, 'D32')) {
    $sheet->removeConditionalStyles($range);
  }
}
$sheet->setConditionalStyles('D32:E32', [$alert]);

// 3. The message next to it, with the range of that currency.
foreach (['G32:H32', 'I32:K32'] as $merged) {
  if (in_array($merged, $sheet->getMergeCells(), TRUE)) {
    $sheet->unmergeCells($merged);
  }
}
if (!in_array('G32:K32', $sheet->getMergeCells(), TRUE)) {
  $sheet->mergeCells('G32:K32');
}
$sheet->getCell('G32')->setValue(sprintf(
  '=IF(AND(ISNUMBER(D32),%s),"Fuera de rango: USD %s a %s",IF(AND(ISNUMBER(D32),%s),"Fuera de rango: MXN %s a %s",""))',
  str_replace('$', '', $out_usd), $human($usd['min']), $human($usd['max']),
  str_replace('$', '', $out_mxn), $human($mxn['min']), $human($mxn['max']),
));
$message_style = $sheet->getStyle('G32:K32');
$message_style->getFont()->setSize(10)->setItalic(TRUE)->getColor()->setRGB('B42352');
$message_style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_CENTER);
$message_style->getProtection()->setLocked(Protection::PROTECTION_PROTECTED);

// 4. Each amount: a number of 0 or more.
foreach ($components as $cell) {
  $validation = $sheet->getDataValidation($cell);
  $validation->setErrorTitle('Monto no válido');
  $validation->setError('Captura una cantidad igual o mayor a 0, por ejemplo 150000.00.');
}

IOFactory::createWriter($book, 'Xlsx')->save($path);
echo sprintf('Formato Excel: suma calculada y rango USD %s a %s, MXN %s a %s.', $human($usd['min']), $human($usd['max']), $human($mxn['min']), $human($mxn['max'])) . PHP_EOL;
