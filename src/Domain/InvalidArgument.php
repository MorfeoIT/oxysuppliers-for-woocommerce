<?php
/**
 * The domain's own argument exception.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Domain;

use InvalidArgumentException;

/**
 * Thrown when a value object is handed something it cannot represent.
 *
 * Its message is developer-facing and is never echoed to a browser: user-facing
 * validation returns codes that the admin layer translates, because the domain
 * does not know what language anybody speaks.
 */
final class InvalidArgument extends InvalidArgumentException {
}
