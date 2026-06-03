<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Defines an enterprise mail account configuration.
 *
 * @ConfigEntityType(
 *   id = "aseguramiento_mail_account",
 *   label = @Translation("Cuenta de correo"),
 *   label_collection = @Translation("Cuentas de correo"),
 *   handlers = {
 *     "list_builder" = "Drupal\aseguramiento_automation\Entity\MailAccountListBuilder",
 *     "form" = {
 *       "add" = "Drupal\aseguramiento_automation\Form\MailAccountForm",
 *       "edit" = "Drupal\aseguramiento_automation\Form\MailAccountForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm"
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider"
 *     }
 *   },
 *   config_prefix = "mail_account",
 *   admin_permission = "administer aseguramiento automation",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "status" = "status"
 *   },
 *   links = {
 *     "add-form" = "/admin/config/aseguramiento/mail-accounts/add",
 *     "edit-form" = "/admin/config/aseguramiento/mail-accounts/{aseguramiento_mail_account}",
 *     "delete-form" = "/admin/config/aseguramiento/mail-accounts/{aseguramiento_mail_account}/delete",
 *     "collection" = "/admin/config/aseguramiento/mail-accounts"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "status",
 *     "provider",
 *     "tenant_id",
 *     "client_id",
 *     "client_secret",
 *     "mailbox",
 *     "folder",
 *     "processed_folder",
 *     "error_folder",
 *     "imap_host",
 *     "imap_port",
 *     "imap_encryption",
 *     "username",
 *     "password",
 *     "company_id",
 *     "insurer",
 *     "options"
 *   }
 * )
 */
final class MailAccount extends ConfigEntityBase {

  protected string $id;

  protected string $label;

  protected string $provider = 'microsoft_graph';

  protected string $tenant_id = '';

  protected string $client_id = '';

  protected string $client_secret = '';

  protected string $mailbox = '';

  protected string $folder = 'Inbox';

  protected string $processed_folder = 'Processed';

  protected string $error_folder = 'Errors';

  protected string $imap_host = '';

  protected int $imap_port = 993;

  protected string $imap_encryption = 'ssl';

  protected string $username = '';

  protected string $password = '';

  protected string $company_id = '';

  protected string $insurer = '';

  protected array $options = [];

  public function getProvider(): string {
    return $this->provider;
  }

  public function toProviderConfig(): array {
    return [
      'id' => $this->id(),
      'label' => $this->label(),
      'provider' => $this->provider,
      'tenant_id' => $this->tenant_id,
      'client_id' => $this->client_id,
      'client_secret' => $this->client_secret,
      'mailbox' => $this->mailbox,
      'folder' => $this->folder,
      'processed_folder' => $this->processed_folder,
      'error_folder' => $this->error_folder,
      'imap_host' => $this->imap_host,
      'imap_port' => $this->imap_port,
      'imap_encryption' => $this->imap_encryption,
      'username' => $this->username,
      'password' => $this->password,
      'company_id' => $this->company_id,
      'insurer' => $this->insurer,
      'options' => $this->options,
    ];
  }

}
