<?php

/**
 * @file
 * Deletes old constancias (tests) together with their PDF and Excel files.
 *
 * Lists by default; deletes only with "--borrar". A file is kept when a
 * constancia that stays still points to it (several requests can come from
 * the same Excel). Take a database backup before deleting.
 *
 * Usage:
 * @code
 * drush php:script scripts/purge_constancias.php -- 2026-09-01
 * drush php:script scripts/purge_constancias.php -- 2026-09-01 --borrar
 * @endcode
 * The date is exclusive and read in America/Mexico_City: "2026-09-01" means
 * created before September 1st.
 */

declare(strict_types=1);

$args = $extra ?? [];
$date = (string) ($args[0] ?? '');
$delete = in_array('--borrar', $args, TRUE);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
  throw new \InvalidArgumentException('Indica la fecha límite, por ejemplo: -- 2026-09-01');
}
$before = (new \DateTimeImmutable($date . ' 00:00:00', new \DateTimeZone('America/Mexico_City')))->getTimestamp();

$storage = \Drupal::entityTypeManager()->getStorage('aseguramiento_constancia');
$files = \Drupal::entityTypeManager()->getStorage('file');
$file_system = \Drupal::service('file_system');
$ids = $storage->getQuery()->accessCheck(FALSE)->condition('created', $before, '<')->sort('id')->execute();
$constancias = $storage->loadMultiple($ids);

// Files the remaining constancias still use are never deleted.
$keep = [];
$others = $storage->getQuery()->accessCheck(FALSE)->condition('created', $before, '>=')->execute();
foreach (array_chunk($others, 200) as $chunk) {
  foreach ($storage->loadMultiple($chunk) as $other) {
    foreach (['pdf_generado', 'excel_original'] as $field) {
      $keep[(string) $other->get($field)->value] = TRUE;
    }
  }
}

echo sprintf('%s: %d constancias creadas antes del %s.', $delete ? 'BORRANDO' : 'SOLO LISTA', count($constancias), $date) . PHP_EOL . PHP_EOL;
echo sprintf("%-6s %-24s %-28s %-10s %-10s %s", 'ID', 'Folio', 'Cliente', 'Estado', 'Creada', 'Archivos') . PHP_EOL;
$uris = [];
foreach ($constancias as $constancia) {
  $own = [];
  foreach (['pdf_generado', 'excel_original'] as $field) {
    $uri = (string) $constancia->get($field)->value;
    if (str_starts_with($uri, 'private://aseguramiento/') && !isset($keep[$uri])) {
      $own[] = $uri;
      $uris[$uri] = TRUE;
    }
  }
  echo sprintf("%-6s %-24s %-28s %-10s %-10s %d",
    $constancia->id(),
    mb_strimwidth((string) $constancia->label(), 0, 24),
    mb_strimwidth((string) $constancia->get('nombre')->value, 0, 28, '…'),
    (string) $constancia->get('status')->value,
    date('Y-m-d', (int) $constancia->get('created')->value),
    count($own),
  ) . PHP_EOL;
}
echo PHP_EOL . sprintf('Archivos que se borrarían: %d (se conservan los que usa una constancia más reciente).', count($uris)) . PHP_EOL;

if (!$delete) {
  echo 'No se borró nada. Para borrar, repite con --borrar después de respaldar la base de datos.' . PHP_EOL;
  return;
}

$deleted_files = 0;
foreach (array_keys($uris) as $uri) {
  $managed = $files->loadByProperties(['uri' => $uri]);
  if ($managed) {
    // Deletes the record and the file on disk.
    $files->delete($managed);
    $deleted_files++;
  }
  elseif (file_exists($uri)) {
    $file_system->delete($uri);
    $deleted_files++;
  }
}
$storage->delete($constancias);
\Drupal::logger('aseguramiento_automation')->notice('[Aseguramiento] Limpieza: se borraron @n constancias creadas antes del @date y @f archivos.', [
  '@n' => count($constancias),
  '@date' => $date,
  '@f' => $deleted_files,
]);
echo sprintf('Listo: %d constancias y %d archivos borrados.', count($constancias), $deleted_files) . PHP_EOL;
