<?php
/**
 * Whether a supplier is one we still buy from.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Domain;

/**
 * Active or not.
 *
 * There is no third state and no deletion: a supplier we have ordered from is
 * part of the history of those orders, so it is switched off rather than
 * removed.
 */
enum SupplierStatus: string {

	case ACTIVE   = 'active';
	case INACTIVE = 'inactive';

	/**
	 * Build from a stored value, falling back to active.
	 *
	 * A row whose status has been corrupted is more usefully treated as a
	 * supplier that exists than as one that has vanished.
	 *
	 * @param string $value Stored value.
	 * @return self
	 */
	public static function from_storage( string $value ): self {
		return self::tryFrom( $value ) ?? self::ACTIVE;
	}
}
