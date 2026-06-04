<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\EventSubscriber;

use Drupal\aseguramiento_automation\Entity\MailAccount;
use Drupal\aseguramiento_automation\Service\MailProviderManagerService;
use Drupal\aseguramiento_automation\Service\QueueManagerService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Coordinates scheduled discovery and lightweight request termination tasks.
 */
final class CronSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly MailProviderManagerService $mailProviderManager,
    private readonly QueueManagerService $queueManager,
    private readonly LoggerInterface $logger,
  ) {
  }

  public static function getSubscribedEvents(): array {
    return [KernelEvents::TERMINATE => 'onTerminate'];
  }

  public function onTerminate(TerminateEvent $event): void {
    // Kept intentionally light; heavy work belongs to cron and Queue API.
  }

  public function pollMailAccounts(): void {
    $start = microtime(TRUE);
    $settings = $this->configFactory->get('aseguramiento_automation.settings');
    if (!$settings->get('cron_enabled')) {
      $this->logger->info('[Aseguramiento] Consulta de buzones omitida porque el cron del módulo está desactivado.');
      return;
    }
    $this->logger->info('[Aseguramiento] Iniciando consulta de buzones configurados.');
    $debug = (bool) $settings->get('debug_mode');
    $found = 0;
    $queued = 0;
    $errors = 0;
    $accounts = $this->entityTypeManager->getStorage('aseguramiento_mail_account')->loadMultiple();
    foreach ($accounts as $account) {
      if (!$account instanceof MailAccount || !$account->status()) {
        continue;
      }
      try {
        $config = $account->toProviderConfig();
        $messages = $this->mailProviderManager->fetchForAccount($config);
        $found += count($messages);
        foreach ($messages as $message) {
          $this->queueManager->enqueue(QueueManagerService::EMAIL_QUEUE, [
            'account' => $config,
            'message' => $message,
          ]);
          $queued++;
        }
      }
      catch (\Throwable $e) {
        $errors++;
        $this->logger->error('[Aseguramiento] Error al consultar el buzón @account. Detalle: @message', [
          '@account' => $account->id(),
          '@message' => $e->getMessage(),
        ]);
      }
    }
    $this->logger->info("[Aseguramiento] Resumen de consulta de buzones:\nCorreos encontrados: @found\nCorreos enviados a cola: @queued\nErrores: @errors", [
      '@found' => $found,
      '@queued' => $queued,
      '@errors' => $errors,
    ]);
    if ($debug) {
      $this->logger->info('[Aseguramiento][Depuración] Consulta de buzones finalizada en @time ms. Cuentas configuradas: @accounts.', [
        '@time' => number_format((microtime(TRUE) - $start) * 1000, 2),
        '@accounts' => count($accounts),
      ]);
    }
  }

}
