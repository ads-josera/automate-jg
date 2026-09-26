<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Service;

use Drupal\aseguramiento_automation\Entity\ConstanciaEntityInterface;
use Drupal\aseguramiento_automation\Util\SolicitudErrorFormatter;
use Psr\Log\LoggerInterface;

/**
 * Generates and sends again a constancia that failed on our side.
 *
 * Only constancias in "error" whose request data still passes validation can
 * be reprocessed: the failure was internal (template, PDF, sending), so
 * running it again can succeed. When the client's data is what failed, a new
 * PDF would carry the same mistake; the client has to send the form again.
 */
final class ConstanciaReprocessService {

  public function __construct(
    private readonly ValidationService $validation,
    private readonly QueueManagerService $queueManager,
    private readonly LoggerInterface $logger,
  ) {
  }

  /**
   * Whether the constancia can be reprocessed, and why not.
   *
   * @return array{allowed: bool, fields: string[]}
   *   "fields": what the client has to correct (friendly sentences), when
   *   the request data is the reason it cannot be reprocessed.
   */
  public function check(ConstanciaEntityInterface $constancia): array {
    if ((string) $constancia->get('status')->value !== 'error') {
      return ['allowed' => FALSE, 'fields' => []];
    }
    $metadata = $constancia->get('metadata')->getValue()[0] ?? [];
    $row = $metadata['source_row'] ?? NULL;
    if (!is_array($row)) {
      // Created by hand, not from a request: nothing to validate against.
      return ['allowed' => TRUE, 'fields' => []];
    }
    $validation = $this->validation->validateRow($row, (int) $constancia->id());
    if ($validation['valid']) {
      return ['allowed' => TRUE, 'fields' => []];
    }
    $described = SolicitudErrorFormatter::describe(json_encode($validation['errors'], JSON_UNESCAPED_UNICODE));
    return ['allowed' => FALSE, 'fields' => $described['fields']];
  }

  /**
   * Queues the PDF again; the constancia is emailed on its own when ready.
   */
  public function reprocess(ConstanciaEntityInterface $constancia, string $by): void {
    if (!$this->check($constancia)['allowed']) {
      throw new \LogicException(sprintf('La constancia %s no se puede reprocesar.', $constancia->label()));
    }
    $previous = trim((string) $constancia->get('errores')->value);
    $constancia->set('status', 'validated');
    $constancia->set('errores', '');
    $constancia->save();
    // "reply": sent by itself even when it came in a batch, whose reply
    // already went out.
    $this->queueManager->enqueue(QueueManagerService::PDF_QUEUE, ['constancia_id' => $constancia->id(), 'reply' => TRUE]);
    $this->logger->notice('[Aseguramiento] @by reprocesó la constancia @folio. Error anterior: @error', [
      '@by' => $by,
      '@folio' => $constancia->label(),
      '@error' => $previous !== '' ? $previous : 'sin detalle',
    ]);
  }

}
