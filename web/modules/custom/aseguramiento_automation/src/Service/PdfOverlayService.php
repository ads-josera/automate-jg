<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Service;

use Drupal\aseguramiento_automation\Entity\PdfTemplate;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileRepositoryInterface;
use Psr\Log\LoggerInterface;
use setasign\Fpdi\Tcpdf\Fpdi;

/**
 * Generates PDFs by overlaying dynamic data over corporate base PDFs.
 */
final class PdfOverlayService {

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    private readonly FileRepositoryInterface $fileRepository,
    private readonly LoggerInterface $logger,
  ) {
  }

  public function generate(PdfTemplate $template, array $data, CoordinateMappingService $mappingService): array {
    if (!class_exists(Fpdi::class)) {
      throw new \RuntimeException('FPDI/TCPDF es requerido para generar PDFs.');
    }
    $source = $this->fileSystem->realpath($template->getFileUri());
    if (!$source || !is_readable($source)) {
      throw new \RuntimeException('El PDF base no existe o no se puede leer.');
    }

    $pdf = new Fpdi('P', 'pt');
    $pdf->setPrintHeader(FALSE);
    $pdf->setPrintFooter(FALSE);
    $page_count = $pdf->setSourceFile($source);

    for ($page = 1; $page <= $page_count; $page++) {
      $template_id = $pdf->importPage($page);
      $size = $pdf->getTemplateSize($template_id);
      $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
      $pdf->AddPage($orientation, [$size['width'], $size['height']]);
      $pdf->useTemplate($template_id);
      foreach ($template->getMappings() as $mapping) {
        if ((int) ($mapping['page'] ?? 1) !== $page) {
          continue;
        }
        $value = $mappingService->valueFor($mapping, $data);
        if ($value === '') {
          continue;
        }
        [$r, $g, $b] = sscanf((string) ($mapping['color'] ?? '#000000'), '#%02x%02x%02x');
        $pdf->SetTextColor($r, $g, $b);
        $pdf->SetFont((string) ($mapping['font'] ?? 'helvetica'), '', (float) ($mapping['size'] ?? 10));
        $pdf->SetXY((float) $mapping['x'], (float) $mapping['y']);
        $width = (float) ($mapping['width'] ?? 0);
        if (!empty($mapping['multiline']) || $width > 0) {
          $pdf->MultiCell($width ?: 80, 0, $value, 0, (string) ($mapping['align'] ?? 'L'));
        }
        else {
          $pdf->Cell(0, 0, $value, 0, 0, (string) ($mapping['align'] ?? 'L'));
        }
      }
    }

    $directory = 'private://aseguramiento/generated/' . date('Y/m');
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $filename = preg_replace('/[^a-zA-Z0-9._-]+/', '_', ($data['folio'] ?? uniqid('constancia_', TRUE)) . '.pdf');
    $binary = $pdf->Output('', 'S');
    $file = $this->fileRepository->writeData($binary, "{$directory}/{$filename}", FileExists::Rename);
    $file->setPermanent();
    $file->save();
    $this->logger->info('[Aseguramiento] PDF generado correctamente. Archivo: @uri. Plantilla: @template.', ['@uri' => $file->getFileUri(), '@template' => $template->id()]);

    return ['fid' => $file->id(), 'uri' => $file->getFileUri(), 'filename' => $file->getFilename()];
  }

}
