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
      throw new \InvalidArgumentException(sprintf('Proveedor de correo no soportado: "%s".', $provider_id));
    }
    return $this->providers[$provider_id];
  }

  public function fetchForAccount(array $account, ?int $limit = NULL): array {
    $provider = $this->getProvider($account['provider'] ?? 'microsoft_graph');
    $settings = $this->configFactory->get('aseguramiento_automation.settings');
    $limit ??= (int) $settings->get('cron_mail_limit') ?: 25;
    $debug = (bool) $settings->get('debug_mode');
    $account_id = (string) ($account['id'] ?? $account['mailbox'] ?? 'desconocido');
    $start = microtime(TRUE);

    $messages = $provider->fetchMessages($account, $limit);
    $count = count($messages);
    if ($count > 0) {
      $this->logger->info('[Aseguramiento] Se encontraron @count correos nuevos para procesar en el buzón @account.', [
        '@count' => $count,
        '@account' => $account_id,
      ]);
    }
    else {
      $this->logger->info('[Aseguramiento] No se encontraron correos pendientes de procesamiento en el buzón @account.', [
        '@account' => $account_id,
      ]);
    }
    if ($debug) {
      $this->logger->info('[Aseguramiento][Depuración] Consulta de buzón terminada. Proveedor: @provider. Límite: @limit. Tiempo: @time ms.', [
        '@provider' => $provider->id(),
        '@limit' => $limit,
        '@time' => number_format((microtime(TRUE) - $start) * 1000, 2),
      ]);
    }
    return $messages;
  }

}
