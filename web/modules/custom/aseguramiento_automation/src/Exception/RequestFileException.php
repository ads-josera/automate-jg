<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Exception;

/**
 * A request file cannot be read for a reason the client can fix.
 *
 * Its message is written for the client and goes in the reply as is (for
 * example: the PDF is password protected). Any other exception is internal
 * and the client gets a generic sentence instead.
 */
final class RequestFileException extends \RuntimeException {
}
