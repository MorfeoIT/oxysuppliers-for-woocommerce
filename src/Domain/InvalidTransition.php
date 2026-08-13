<?php
/**
 * A state an order is not allowed to reach from where it is.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Domain;

use RuntimeException;

/**
 * Thrown when something asks an order to make a move the state machine forbids.
 *
 * An exception rather than a false return, because every caller either knows
 * the move is legal or has to stop. Somewhere between "cancelled" and "sent"
 * there is a supplier who would be surprised.
 */
final class InvalidTransition extends RuntimeException {
}
