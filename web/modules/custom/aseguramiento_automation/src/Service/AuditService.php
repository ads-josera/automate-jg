<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Service;

use Drupal\aseguramiento_automation\Entity\ConstanciaEntityInterface;
use Psr\Log\LoggerInterface;

/**
 * Central audit writer for traceability.
 */
final class AuditService {

  public function __construct(private readonly LoggerInterface $logger) {
  }

  public function append(ConstanciaEntityInterface $entity, string $message, array $context = []): void {
    $line = sprintf('[%s] %s %s', date(DATE_ATOM), $message, $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '');
    $existing = (string) $entity->get('logs')->value;
    $entity->set('logs', trim($existing . "\n" . $line));
    $entity->save();
    $this->logger->info($message, $context);
  }

  public function error(ConstanciaEntityInterface $entity, string $message, array $context = []): void {
    $line = sprintf('[%s] %s %s', date(DATE_ATOM), $message, $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '');
    $entity->set('errores', trim((string) $entity->get('errores')->value . "\n" . $line));
    $entity->set('status', 'error');
    $entity->save();
    $this->logger->error($message, $context);
  }

}

