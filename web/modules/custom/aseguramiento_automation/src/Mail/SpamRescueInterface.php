<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Mail;

/**
 * A mail provider that can bring requests back from the spam folders.
 */
interface SpamRescueInterface {

  /**
   * Moves the requests found in the account's spam folders to its inbox.
   *
   * @param callable(array): bool $isRequest
   *   Decides from the message description plus "attachment_names".
   *
   * @return int
   *   Number of emails moved.
   */
  public function rescueFromSpam(array $account, callable $isRequest): int;

}
