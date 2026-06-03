<?php

declare(strict_types=1);

namespace Drupal\aseguramiento_automation\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Interface for constancia entities.
 */
interface ConstanciaEntityInterface extends ContentEntityInterface, EntityChangedInterface, EntityOwnerInterface {
}

