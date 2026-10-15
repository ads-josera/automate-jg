<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Service;

use Drupal\aseguramiento_automation\Pdf\AcroFormReader;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Decides what to keep from an email sent by the JG Mylard team.
 *
 * The team answers clients with "reply all" and the request mailbox is in
 * the thread, so invoices and replies reached the system and were answered
 * as broken requests (October 2026: four in the first week). From a team
 * address only real request forms count: the official Excel (it carries
 * the hidden "Datos" sheet) or the fillable PDF (it has form fields). Any
 * other file is dropped, and an email with nothing else is archived with no
 * reply and no team summary.
 *
 * Client emails never go through here: they keep being answered with what
 * to fix, whatever they send.
 */
final class TeamMailFilter {

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * Whether the sender's domain is one of the team's (settings).
   */
  public function isTeamSender(string $from, array $settings): bool {
    $domain = strtolower(substr((string) strrchr(trim($from), '@'), 1));
    $team = array_map(static fn($value): string => strtolower(trim((string) $value)), (array) ($settings['internal_sender_domains'] ?? []));
    return $domain !== '' && in_array($domain, $team, TRUE);
  }

  /**
   * Splits persisted attachments into request forms and everything else.
   *
   * @return array{0: array, 1: array}
   *   [request forms, other files], both lists of persisted files.
   */
  public function splitRequestForms(array $files): array {
    $forms = [];
    $others = [];
    foreach ($files as $file) {
      if ($this->isRequestForm($file)) {
        $forms[] = $file;
      }
      else {
        $others[] = $file;
      }
    }
    return [$forms, $others];
  }

  /**
   * Deletes persisted files that will not be processed.
   */
  public function discard(array $files): void {
    $storage = $this->entityTypeManager->getStorage('file');
    foreach ($files as $file) {
      if (!empty($file['fid']) && ($entity = $storage->load($file['fid']))) {
        $entity->delete();
      }
    }
  }

  private function isRequestForm(array $file): bool {
    $path = $this->fileSystem->realpath((string) ($file['uri'] ?? ''));
    if (!$path || !is_readable($path)) {
      return FALSE;
    }
    try {
      return match ($file['type'] ?? '') {
        'excel' => in_array('Datos', IOFactory::createReaderForFile($path)->listWorksheetNames($path), TRUE),
        'pdf' => (new AcroFormReader())->read((string) file_get_contents($path)) !== [],
        default => FALSE,
      };
    }
    catch (\Throwable) {
      // Unreadable or protected: from the team it is not a request.
      return FALSE;
    }
  }

}
