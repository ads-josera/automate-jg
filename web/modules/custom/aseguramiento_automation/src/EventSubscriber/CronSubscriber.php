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
    $settings = $this->configFactory->get('aseguramiento_automation.settings');
    if (!$settings->get('cron_enabled')) {
      return;
    }
    $accounts = $this->entityTypeManager->getStorage('aseguramiento_mail_account')->loadMultiple();
    foreach ($accounts as $account) {
      if (!$account instanceof MailAccount || !$account->status()) {
        continue;
      }
      try {
        $config = $account->toProviderConfig();
        foreach ($this->mailProviderManager->fetchForAccount($config) as $message) {
          $this->queueManager->enqueue(QueueManagerService::EMAIL_QUEUE, [
            'account' => $config,
            'message' => $message,
          ]);
        }
      }
      catch (\Throwable $e) {
        $this->logger->error('Mail polling failed for account @account: @message', [
          '@account' => $account->id(),
          '@message' => $e->getMessage(),
        ]);
      }
    }
  }

}

