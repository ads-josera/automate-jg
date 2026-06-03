<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Service;

use Drupal\aseguramiento_automation\Mail\ImapMailProvider;
use Drupal\aseguramiento_automation\Mail\MailProviderInterface;
use Drupal\aseguramiento_automation\Mail\MicrosoftGraphMailProvider;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Provider registry/factory for enterprise mail integrations.
 */
final class MailProviderManagerService {

  /**
   * @var array<string, \Drupal\aseguramiento_automation\Mail\MailProviderInterface>
   */
  private array $providers;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    ClientInterface $httpClient,
    private readonly LoggerInterface $logger,
  ) {
    $graph = new MicrosoftGraphService($httpClient, $logger);
    $imap = new ImapService($logger);
    $this->providers = [
      'microsoft_graph' => new MicrosoftGraphMailProvider($graph),
      'imap' => new ImapMailProvider($imap),
    ];
  }

  public function getProvider(string $provider_id): MailProviderInterface {
    if (!isset($this->providers[$provider_id])) {
      throw new \InvalidArgumentException(sprintf('Unsupported mail provider "%s".', $provider_id));
    }
    return $this->providers[$provider_id];
  }

  public function fetchForAccount(array $account, ?int $limit = NULL): array {
    $provider = $this->getProvider($account['provider'] ?? 'microsoft_graph');
    $limit ??= (int) $this->configFactory->get('aseguramiento_automation.settings')->get('cron_mail_limit') ?: 25;
    $messages = $provider->fetchMessages($account, $limit);
    $this->logger->info('Fetched @count message(s) from @account via @provider.', [
      '@count' => count($messages),
      '@account' => $account['id'] ?? $account['mailbox'] ?? 'unknown',
      '@provider' => $provider->id(),
    ]);
    return $messages;
  }

}

