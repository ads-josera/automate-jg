<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Service;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Psr\Log\LoggerInterface;

/**
 * Parses Excel files into normalized domain rows.
 */
final class ExcelParserService {

  private const COLUMN_ALIASES = [
    'nombre' => ['nombre', 'name', 'cliente', 'asegurado'],
    'poliza' => ['poliza', 'póliza', 'policy', 'numero poliza', 'número póliza'],
    'suma_asegurada' => ['suma asegurada', 'suma_asegurada', 'monto', 'importe'],
    'vigencia_inicio' => ['vigencia inicio', 'inicio vigencia', 'fecha inicio'],
    'vigencia_fin' => ['vigencia fin', 'fin vigencia', 'fecha fin'],
    'rfc' => ['rfc'],
    'email' => ['email', 'correo', 'correo electronico', 'correo electrónico'],
    'telefono' => ['telefono', 'teléfono', 'phone'],
    'aseguradora' => ['aseguradora', 'insurer'],
    'tipo_documento' => ['tipo documento', 'documento', 'tipo'],
    'solicitante' => ['solicitante', 'cliente solicitante'],
    'solicitante_telefono' => ['telefono solicitante', 'teléfono solicitante', 'solicitante telefono', 'solicitante teléfono'],
    'solicitud_fecha' => ['fecha', 'fecha solicitud', 'fecha de solicitud'],
    'beneficiario_nombre' => ['beneficiario nombre', 'nombre beneficiario', 'beneficiario del seguro', 'beneficiario'],
    'beneficiario_domicilio' => ['beneficiario domicilio', 'domicilio beneficiario'],
    'beneficiario_contacto' => ['beneficiario contacto', 'contacto beneficiario'],
    'mercancia_estado' => ['mercancia estado', 'mercancía estado', 'mercancia', 'mercancía', 'nueva usada'],
    'mercancia_asegurada' => ['mercancia asegurada', 'mercancía asegurada', 'mercancias aseguradas', 'mercancías aseguradas', 'mercancia(s) asegurada(s)', 'mercancía(s) asegurada(s)', 'descripcion mercancia', 'descripción mercancía'],
    'mercancia_referencia' => ['referencia', 'referencia mercancia', 'referencia mercancía'],
    'fecha_inicio_seguro' => ['fecha inicio seguro', 'fecha de inicio del seguro', 'inicio seguro'],
    'origen_ciudad' => ['ciudad origen', 'ciudad de origen', 'origen ciudad'],
    'origen_pais' => ['pais origen', 'país origen', 'pais de origen', 'país de origen', 'origen pais', 'origen país'],
    'destino_ciudad' => ['ciudad destino', 'ciudad de destino', 'ciudad destino', 'destino ciudad'],
    'destino_pais' => ['pais destino', 'país destino', 'pais de destino', 'país de destino', 'destino pais', 'destino país'],
    'medio_transporte' => ['medio transporte', 'medio de transporte', 'transporte'],
    'moneda' => ['moneda', 'conceptos por asegurar en', 'asegurar en', 'divisa'],
    'valor_factura' => ['valor factura', 'factura', 'importe factura'],
    'gastos_fletes' => ['gastos fletes', 'gastos de fletes', 'fletes', 'gasto flete'],
    'gastos_incrementales' => ['gastos incrementales', 'incrementales'],
    'seguro_contenedor' => ['seguro contenedor', 'seguro del contenedor'],
    'suma_asegurada_total' => ['suma asegurada total', 'total suma asegurada', 'suma total'],
    'consignatario_nombre' => ['consignatario nombre', 'nombre consignatario', 'consignatario'],
    'consignatario_domicilio' => ['consignatario domicilio', 'domicilio consignatario'],
    'consignatario_contacto' => ['consignatario contacto', 'contacto consignatario'],
    'proveedor_nombre' => ['proveedor nombre', 'nombre proveedor', 'proveedor'],
    'proveedor_domicilio' => ['proveedor domicilio', 'domicilio proveedor'],
    'proveedor_contacto' => ['proveedor contacto', 'contacto proveedor'],
    'ref_terrestre' => ['terrestre', 'referencia terrestre'],
    'ref_talon_embarque' => ['talon de embarque', 'talón de embarque'],
    'ref_contenedor_caja' => ['no contenedor caja', 'n° de contenedor o caja', 'numero contenedor caja', 'número de contenedor o caja', 'contenedor o caja'],
    'ref_maritimo_bl' => ['b/l', 'bl', 'bill of lading'],
    'ref_maritimo_contenedor' => ['contenedor maritimo', 'contenedor marítimo'],
    'ref_aereo_guia' => ['no guia', 'n° de guia', 'n° de guía', 'numero guia', 'número guía', 'guia aerea', 'guía aérea'],
    'ref_aereo_linea' => ['l.aerea', 'l. aérea', 'linea aerea', 'línea aérea', 'aerolinea', 'aerolínea'],
    'acepta_informacion_veridica' => ['acepto informacion veridica', 'acepto información verídica', 'informacion veridica', 'información verídica'],
  ];

  public function __construct(private readonly LoggerInterface $logger) {
  }

  public function parse(string $real_path): array {
    if (!class_exists(IOFactory::class)) {
      throw new \RuntimeException('PhpSpreadsheet es requerido para leer archivos Excel.');
    }
    $reader = IOFactory::createReaderForFile($real_path);
    $reader->setReadDataOnly(TRUE);
    $spreadsheet = $reader->load($real_path);
    $rows = [];
    $worksheets = $spreadsheet->sheetNameExists('Datos')
      ? [$spreadsheet->getSheetByName('Datos')]
      : iterator_to_array($spreadsheet->getWorksheetIterator());

    foreach ($worksheets as $sheet) {
      if (!$sheet) {
        continue;
      }
      $highest_row = $sheet->getHighestDataRow();
      $highest_column = $sheet->getHighestDataColumn();
      if ($highest_row < 2) {
        continue;
      }
      $headers = $sheet->rangeToArray("A1:{$highest_column}1", NULL, TRUE, TRUE, TRUE)[1] ?? [];
      $map = $this->buildHeaderMap($headers);
      for ($row_number = 2; $row_number <= $highest_row; $row_number++) {
        $row = ['_sheet' => $sheet->getTitle(), '_row' => $row_number];
        foreach ($map as $field => $column) {
          $row[$field] = $this->normalizeValue($sheet->getCell($column . $row_number)->getCalculatedValue(), $field);
        }
        $mapped_values = array_intersect_key($row, $map);
        if (array_filter($mapped_values, static fn($value): bool => $value !== '' && $value !== NULL)) {
          $rows[] = $row;
        }
      }
    }

    $this->logger->info('[Aseguramiento] Extracción de datos del archivo completada correctamente. Registros detectados: @count.', ['@count' => count($rows)]);
    return $rows;
  }

  private function buildHeaderMap(array $headers): array {
    $normalized = [];
    foreach ($headers as $column => $label) {
      $normalized[$column] = $this->normalizeHeader((string) $label);
    }
    $map = [];
    foreach (self::COLUMN_ALIASES as $field => $aliases) {
      $field_aliases = array_merge([$this->normalizeHeader($field)], $aliases);
      foreach ($normalized as $column => $label) {
        if (in_array($label, $field_aliases, TRUE)) {
          $map[$field] = $column;
          break;
        }
      }
    }
    return $map;
  }

  private function normalizeHeader(string $value): string {
    $value = mb_strtolower(trim($value));
    $value = str_replace('_', ' ', $value);
    return preg_replace('/\s+/', ' ', $value) ?: '';
  }

  private function normalizeValue(mixed $value, string $field = ''): string {
    if ($value instanceof \DateTimeInterface) {
      return $value->format('Y-m-d');
    }
    if ($value === 0 && !in_array($field, ['valor_factura', 'gastos_fletes', 'gastos_incrementales', 'seguro_contenedor', 'suma_asegurada_total', 'suma_asegurada'], TRUE)) {
      return '';
    }
    if (in_array($field, ['solicitud_fecha', 'fecha_inicio_seguro', 'vigencia_inicio', 'vigencia_fin'], TRUE) && is_numeric($value)) {
      try {
        return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
      }
      catch (\Throwable) {
        return trim((string) $value);
      }
    }
    return trim((string) $value);
  }

}
