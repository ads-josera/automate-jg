<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Service;

use Drupal\aseguramiento_automation\Entity\ConstanciaEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Closes constancias in error once their request is corrected.
 *
 * Automatically (link()) or by hand from the panel (resolve()). Either way
 * the constancia becomes "Corregida" and stops counting as pending.
 *
 * When a client answers our "please fix this" reply with the corrected file,
 * the new batch knows the earlier one (SolicitudBatchService::loteForThread).
 * Each constancia generated now is paired with an earlier constancia in
 * error, and that one becomes "Corregida" with a link to the new folio, so
 * the list shows what is really still pending.
 *
 * Pairing, in order, each step only over what is still unpaired:
 * 1. Same file name, one on each side.
 * 2. Same beneficiary, one on each side.
 * 3. Exactly one left on each side.
 * Anything else stays unpaired: a wrong link would hide a pending request,
 * which is worse than leaving it for the team to resolve by hand.
 */
final class ConstanciaCorrectionService {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly SolicitudBatchService $batchService,
    private readonly AuditService $audit,
  ) {
  }

  /**
   * Links the new constancias of a batch to the ones they correct.
   *
   * @param array $batch
   *   The batch just answered.
   * @param array $ok
   *   Its generated constancias: each with "entity" and "file".
   *
   * @return array<int, string>
   *   New constancia id => folio of the constancia it corrects.
   */
  public function link(array $batch, array $ok): array {
    $previous_lote = (string) ($batch['corrige_lote'] ?? '');
    if ($previous_lote === '' || $ok === []) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('aseguramiento_constancia');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('lote', $previous_lote)
      ->condition('status', 'error')
      ->sort('id')
      ->execute();
    if ($ids === []) {
      return [];
    }

    $previous_names = [];
    foreach ((array) ($this->batchService->get($previous_lote)['files'] ?? []) as $file) {
      foreach ((array) ($file['constancias'] ?? []) as $id) {
        $previous_names[(int) $id] = (string) $file['name'];
      }
    }
    $old = [];
    foreach ($storage->loadMultiple($ids) as $id => $entity) {
      $old[(int) $id] = [
        'entity' => $entity,
        'file' => $previous_names[(int) $id] ?? basename((string) $entity->get('excel_original')->value),
      ];
    }
    $new = [];
    foreach ($ok as $item) {
      $new[(int) $item['entity']->id()] = $item;
    }

    $old_entities = array_map(static fn(array $item) => $item['entity'], $old);
    $new_entities = array_map(static fn(array $item) => $item['entity'], $new);
    $pairs = [];
    $by_file = static fn(array $item): string => mb_strtolower(trim((string) $item['file']));
    $by_beneficiary = static fn(array $item): string => mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $item['entity']->get('beneficiario_nombre')->value)));
    foreach ([$by_file, $by_beneficiary] as $key) {
      foreach (self::uniquePairs($old, $new, $key) as $old_id => $new_id) {
        $pairs[$new_id] = $old_id;
        unset($old[$old_id], $new[$new_id]);
      }
    }
    if (count($old) === 1 && count($new) === 1) {
      $pairs[(int) array_key_first($new)] = (int) array_key_first($old);
    }

    $linked = [];
    foreach ($pairs as $new_id => $old_id) {
      $linked[$new_id] = $this->markCorrected($old_entities[$old_id], $new_entities[$new_id]);
    }
    return $linked;
  }

  /**
   * Whether a constancia can be marked as resolved by hand.
   */
  public static function canResolve(ConstanciaEntityInterface $constancia): bool {
    return (string) $constancia->get('status')->value === 'error';
  }

  /**
   * Marks a constancia in error as resolved by the team.
   *
   * For what could not be tied automatically: the client sent the fix in a
   * new email, by phone, or the request was simply dropped. Nothing is sent
   * to the client; who did it, when and why stay in the metadata and the
   * log.
   */
  public function resolve(ConstanciaEntityInterface $constancia, string $by, string $note): void {
    if (!self::canResolve($constancia)) {
      throw new \LogicException(sprintf('La constancia %s no tiene error; no hay nada que resolver.', $constancia->label()));
    }
    $note = trim($note);
    $metadata = $constancia->get('metadata')->getValue()[0] ?? [];
    $metadata['resuelta'] = ['por' => $by, 'fecha' => time(), 'nota' => $note];
    $constancia->set('metadata', $metadata);
    $constancia->set('status', 'corrected');
    $this->audit->append($constancia, sprintf('Marcada como resuelta por %s.%s', $by, $note !== '' ? ' Nota: ' . $note : ''));
  }

  /**
   * Marks $old as corrected by $new. Returns the folio of $old.
   */
  private function markCorrected(ConstanciaEntityInterface $old, ConstanciaEntityInterface $new): string {
    $old->set('status', 'corrected');
    $old->set('corregida_por', $new->id());
    $this->audit->append($old, sprintf('Corregida: el cliente respondió con el archivo corregido y se generó la constancia %s.', $new->label()));
    $new->set('corrige_a', $old->id());
    $this->audit->append($new, sprintf('Corrige la constancia %s, que tenía errores.', $old->label()));
    return (string) $old->label();
  }

  /**
   * Pairs whose key is unique on both sides (and not empty).
   *
   * @return array<int, int>
   *   Old id => new id.
   */
  private static function uniquePairs(array $old, array $new, callable $key): array {
    $group = static function (array $items) use ($key): array {
      $groups = [];
      foreach ($items as $id => $item) {
        $value = $key($item);
        if ($value !== '') {
          $groups[$value][] = $id;
        }
      }
      return $groups;
    };
    $new_groups = $group($new);
    $pairs = [];
    foreach ($group($old) as $value => $old_ids) {
      if (count($old_ids) === 1 && count($new_groups[$value] ?? []) === 1) {
        $pairs[$old_ids[0]] = $new_groups[$value][0];
      }
    }
    return $pairs;
  }

}
