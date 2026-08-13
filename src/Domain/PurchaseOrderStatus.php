<?php
/**
 * Where a purchase order has got to.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Domain;

/**
 * The states of the specification (§8), and the one question the rest of the
 * plugin asks about them: is this order still expected to arrive?
 *
 * Defined now rather than with the purchase orders themselves, because the
 * reordering screen has to subtract goods that are on their way, and it can
 * only do that if it knows which orders those are.
 */
enum PurchaseOrderStatus: string {

	case DRAFT              = 'draft';
	case TO_SEND            = 'to_send';
	case SENT               = 'sent';
	case CONFIRMED          = 'confirmed';
	case PARTIALLY_RECEIVED = 'partially_received';
	case RECEIVED           = 'received';
	case CANCELLED          = 'cancelled';

	/**
	 * Whether goods on this order should still be counted as coming.
	 *
	 * A draft is not counted: it is a thought, not an order, and counting it
	 * would stop the shop from ever being told to order what it has been
	 * meaning to order. A cancelled one is not coming, and a fully received one
	 * has arrived.
	 *
	 * @return bool
	 */
	public function is_expected(): bool {
		return in_array( $this, array( self::TO_SEND, self::SENT, self::CONFIRMED, self::PARTIALLY_RECEIVED ), true );
	}

	/**
	 * The stored values of every status that is still expected.
	 *
	 * @return list<string>
	 */
	public static function expected_values(): array {
		return array_values(
			array_map(
				static fn ( self $status ): string => $status->value,
				array_filter( self::cases(), static fn ( self $status ): bool => $status->is_expected() )
			)
		);
	}

	/**
	 * Build from a stored value, falling back to draft.
	 *
	 * @param string $value Stored value.
	 * @return self
	 */
	public static function from_storage( string $value ): self {
		return self::tryFrom( $value ) ?? self::DRAFT;
	}
}
