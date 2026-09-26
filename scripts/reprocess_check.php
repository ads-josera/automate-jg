<?php

/**
 * @file
 * Check of the "Reprocesar" action on constancias.
 *
 * - Only constancias in "error" whose request data is still valid (the
 *   failure was ours) can be reprocessed; a data error explains what the
 *   client must fix instead.
 * - Opening the URL (GET) only shows the confirmation; nothing is sent.
 * - Reprocessing emails the constancia even when it came in a batch whose
 *   reply already went out.
 *
 * Works on copies of an existing sent constancia and deletes them at the
 * end. Usage (local DDEV only; the reply is checked in Mailpit):
 * @code
 * ddev drush php:script scripts/reprocess_check.php
 * @endcode
 */

declare(strict_types=1);

use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\Request;

$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
  echo ($ok ? '  OK    ' : '  FALLA ') . $label . PHP_EOL;
  $failures += $ok ? 0 : 1;
};
$mailpit = static function (string $method = 'GET'): array {
  $context = stream_context_create(['http' => ['method' => $method]]);
  return json_decode((string) file_get_contents('http://localhost:8025/api/v1/messages', FALSE, $context), TRUE) ?: [];
};

$storage = \Drupal::entityTypeManager()->getStorage('aseguramiento_constancia');
$service = \Drupal::service('aseguramiento_automation.reprocess');
$source = NULL;
foreach ($storage->loadByProperties(['status' => 'sent']) as $candidate) {
  if (is_array($candidate->get('metadata')->getValue()[0]['source_row'] ?? NULL)) {
    $source = $candidate;
  }
}
if (!$source) {
  throw new \RuntimeException('Hace falta una constancia enviada creada desde un Excel (corre antes batch_reply_check.php).');
}

$copy = static function (array $values, ?callable $alter_row = NULL) use ($source) {
  $entity = $source->createDuplicate();
  $metadata = $source->get('metadata')->getValue()[0];
  if ($alter_row) {
    $metadata['source_row'] = $alter_row($metadata['source_row']);
  }
  $entity->set('metadata', $metadata);
  $entity->set('folio', 'PRUEBA-REPROCESO-' . uniqid());
  $entity->set('email', 'reproceso@example.com');
  foreach ($values as $field => $value) {
    $entity->set($field, $value);
  }
  $entity->save();
  return $entity;
};
$internal = $copy(['status' => 'error', 'errores' => 'No fue posible generar el PDF: prueba', 'lote' => 'lote-ya-contestado', 'pdf_generado' => '']);
$data = $copy(['status' => 'error', 'errores' => '{"medio_transporte":["El campo obligatorio está vacío."]}'], static fn(array $row): array => ['medio_transporte' => ''] + $row);
$sent = $copy(['status' => 'sent']);

$gestor = current(\Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name' => 'Gestor Aseguramiento']));
$anonymous = \Drupal::entityTypeManager()->getStorage('user')->load(0);
$route_access = static function ($entity, AccountInterface $account): bool {
  return \Drupal\Core\Url::fromRoute('aseguramiento_automation.reprocess', ['aseguramiento_constancia' => $entity->id()])->access($account);
};

try {
  echo PHP_EOL . 'Qué se puede reprocesar' . PHP_EOL;
  $check($service->check($internal)['allowed'], 'Error interno (PDF): se puede reprocesar');
  $data_check = $service->check($data);
  $check(!$data_check['allowed'] && in_array('Medio de transporte: falta llenarlo', $data_check['fields'], TRUE), 'Error en los datos del cliente: no se puede, y dice qué corregir (' . implode('; ', $data_check['fields']) . ')');
  $check(!$service->check($sent)['allowed'], 'Una constancia enviada no se reprocesa');

  echo PHP_EOL . 'Quién puede' . PHP_EOL;
  $check($gestor && $route_access($internal, $gestor), 'El gestor puede reprocesar la de error interno');
  $check($gestor && !$route_access($data, $gestor), 'El gestor no puede reprocesar la de datos del cliente');
  $check(!$route_access($internal, $anonymous), 'Un visitante sin sesión no puede');

  echo PHP_EOL . 'Abrir el enlace no envía nada' . PHP_EOL;
  \Drupal::service('account_switcher')->switchTo($gestor);
  $mailpit('DELETE');
  $request = Request::create('/admin/aseguramiento/constancia/' . $internal->id() . '/reprocess', 'GET');
  $response = \Drupal::service('http_kernel')->handle($request, \Symfony\Component\HttpKernel\HttpKernelInterface::SUB_REQUEST);
  $storage->resetCache([$internal->id()]);
  $check($response->getStatusCode() === 200 && str_contains((string) $response->getContent(), 'Reprocesar y enviar'), 'GET muestra la confirmación (HTTP ' . $response->getStatusCode() . ')');
  $check($storage->load($internal->id())->get('status')->value === 'error', 'y la constancia sigue igual');
  \Drupal::service('account_switcher')->switchBack();

  echo PHP_EOL . 'Reprocesar envía la constancia aunque venga de un lote' . PHP_EOL;
  $service->reprocess($storage->load($internal->id()), 'prueba');
  foreach (['aseguramiento_pdf_generation', 'aseguramiento_mail_sending'] as $name) {
    $queue = \Drupal::queue($name);
    $worker = \Drupal::service('plugin.manager.queue_worker')->createInstance($name);
    while ($item = $queue->claimItem(60)) {
      $worker->processItem($item->data);
      $queue->deleteItem($item);
    }
  }
  $storage->resetCache([$internal->id()]);
  $after = $storage->load($internal->id());
  $check($after->get('status')->value === 'sent', 'Queda como enviada (estado: ' . $after->get('status')->value . ')');
  $to = array_merge(...array_map(static fn(array $m): array => array_column($m['To'], 'Address'), $mailpit()['messages'] ?? []));
  $check(in_array('reproceso@example.com', $to, TRUE), 'El cliente recibe su PDF');
  $check((string) $after->get('pdf_generado')->value !== '', 'Tiene PDF generado');
}
finally {
  $storage->delete([$internal, $data, $sent]);
}

if ($failures) {
  throw new \RuntimeException("RESULTADO: {$failures} comprobaciones fallaron.");
}
echo PHP_EOL . 'RESULTADO: todas las comprobaciones pasaron.' . PHP_EOL;
