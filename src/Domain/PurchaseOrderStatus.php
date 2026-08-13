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

	/**
	 * Where an order in this state is allowed to go next.
	 *
	 * The rules live here rather than in the screen that draws the buttons,
	 * because a screen can only stop the transitions it knows about and this
	 * has to stop them all: the same order is also moved by a receipt, by an
	 * email being sent, and one day by an add-on.
	 *
	 * @return list<self>
	 */
	public function allowed_next(): array {
		return match ( $this ) {
			self::DRAFT => array( self::TO_SEND, self::SENT, self::CANCELLED ),
			self::TO_SEND => array( self::SENT, self::DRAFT, self::CANCELLED ),
			self::SENT => array( self::CONFIRMED, self::PARTIALLY_RECEIVED, self::RECEIVED, self::CANCELLED ),
			self::CONFIRMED => array( self::PARTIALLY_RECEIVED, self::RECEIVED, self::CANCELLED ),

			// Cancelling a partly received order is closing it short, which is
			// a real thing a shop does when the rest is never coming.
			self::PARTIALLY_RECEIVED => array( self::RECEIVED, self::CANCELLED ),

			// Not a dead end, and deliberately so: reversing a receipt has to
			// be able to put a fully received order back to partly received,
			// or the reversal would leave the order saying something untrue.
			self::RECEIVED => array( self::PARTIALLY_RECEIVED ),

			// A cancelled order stays cancelled. Re-opening one hides the fact
			// that it was cancelled; the shop makes a new order instead.
			self::CANCELLED => array(),
		};
	}

	/**
	 * Whether an order in this state may move to that one.
	 *
	 * Staying put is always allowed: saving an order without touching its
	 * state is not a transition.
	 *
	 * @param self $next Where it wants to go.
	 * @return bool
	 */
	public function can_move_to( self $next ): bool {
		return $this === $next || in_array( $next, $this->allowed_next(), true );
	}

	/**
	 * Whether the order can still be changed.
	 *
	 * Once anything has been received, the lines are part of a story that has
	 * already happened.
	 *
	 * @return bool
	 */
	public function is_editable(): bool {
		return in_array( $this, array( self::DRAFT, self::TO_SEND ), true );
	}

	/**
	 * Whether this is the end of the line.
	 *
	 * @return bool
	 */
	public function is_final(): bool {
		return self::CANCELLED === $this;
	}
}
