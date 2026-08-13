<?php
/**
 * A purchase order.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Domain;

/*
 * Developer-facing exception messages in a layer with no WordPress in it.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * What we asked a supplier to send us.
 *
 * A document, not a record: once it has gone out, what it said is part of the
 * history of the purchase. That is why the totals are worked out from the lines
 * rather than typed, why the state can only move where the state machine allows,
 * and why nothing here deletes.
 */
final class PurchaseOrder {

	/**
	 * Hold an order.
	 *
	 * @param int                     $id                 Row id, 0 when not yet stored.
	 * @param string                  $number             The number on the document.
	 * @param int                     $supplier_id        Who it goes to.
	 * @param PurchaseOrderStatus     $status             Where it has got to.
	 * @param string                  $currency           Which money it is written in.
	 * @param string                  $order_date         Date of the order, "Y-m-d".
	 * @param string|null             $expected_date      When it should arrive, or null.
	 * @param string                  $supplier_reference Their reference for it.
	 * @param string                  $delivery_address   Where it should go.
	 * @param string                  $payment_terms      Agreed terms.
	 * @param string                  $internal_notes     Notes for us.
	 * @param string                  $supplier_notes     Notes printed for them.
	 * @param list<PurchaseOrderLine> $lines          What was ordered.
	 * @param string|null             $sent_at            When it was sent, or null.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $number,
		public readonly int $supplier_id,
		public readonly PurchaseOrderStatus $status,
		public readonly string $currency,
		public readonly string $order_date,
		public readonly ?string $expected_date = null,
		public readonly string $supplier_reference = '',
		public readonly string $delivery_address = '',
		public readonly string $payment_terms = '',
		public readonly string $internal_notes = '',
		public readonly string $supplier_notes = '',
		public readonly array $lines = array(),
		public readonly ?string $sent_at = null
	) {
	}

	/**
	 * The order without tax.
	 *
	 * @return Money
	 */
	public function subtotal(): Money {
		$total = Money::zero( $this->currency );

		foreach ( $this->lines as $line ) {
			$total = $total->add( $line->net_total() );
		}

		return $total;
	}

	/**
	 * The tax on the order.
	 *
	 * @return Money
	 */
	public function tax(): Money {
		$total = Money::zero( $this->currency );

		foreach ( $this->lines as $line ) {
			$total = $total->add( $line->tax_total() );
		}

		return $total;
	}

	/**
	 * What the order comes to.
	 *
	 * @return Money
	 */
	public function total(): Money {
		return $this->subtotal()->add( $this->tax() );
	}

	/**
	 * How many units are still to arrive.
	 *
	 * @return int
	 */
	public function outstanding(): int {
		$outstanding = 0;

		foreach ( $this->lines as $line ) {
			$outstanding += $line->outstanding();
		}

		return $outstanding;
	}

	/**
	 * Whether every line has arrived in full.
	 *
	 * An order with no lines is not complete: it is empty, which is a different
	 * thing and is why it cannot be sent.
	 *
	 * @return bool
	 */
	public function is_fully_received(): bool {
		if ( array() === $this->lines ) {
			return false;
		}

		foreach ( $this->lines as $line ) {
			if ( ! $line->is_fully_received() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether some of it has arrived and some has not.
	 *
	 * @return bool
	 */
	public function is_partially_received(): bool {
		$received = false;

		foreach ( $this->lines as $line ) {
			if ( $line->qty_received > 0 ) {
				$received = true;
			}
		}

		return $received && ! $this->is_fully_received();
	}

	/**
	 * The state the order has reached, judged by what has arrived.
	 *
	 * Called after a receipt: the receipt says what came, and this says what
	 * that makes the order. Never moves an order that has been cancelled.
	 *
	 * @return PurchaseOrderStatus
	 */
	public function status_after_receiving(): PurchaseOrderStatus {
		if ( PurchaseOrderStatus::CANCELLED === $this->status ) {
			return $this->status;
		}

		if ( $this->is_fully_received() ) {
			return PurchaseOrderStatus::RECEIVED;
		}

		if ( $this->is_partially_received() ) {
			return PurchaseOrderStatus::PARTIALLY_RECEIVED;
		}

		// Nothing has arrived, so a reversal has taken the order back to where
		// it was before anything did.
		return PurchaseOrderStatus::CONFIRMED === $this->status || PurchaseOrderStatus::SENT === $this->status
			? $this->status
			: PurchaseOrderStatus::CONFIRMED;
	}

	/**
	 * Whether the order can be sent to the supplier.
	 *
	 * @return bool
	 */
	public function can_be_sent(): bool {
		return array() !== $this->lines
			&& $this->status->can_move_to( PurchaseOrderStatus::SENT );
	}

	/**
	 * The same order in a new state.
	 *
	 * @param PurchaseOrderStatus $status Where it is going.
	 * @return self
	 * @throws InvalidTransition When the state machine does not allow it.
	 */
	public function with_status( PurchaseOrderStatus $status ): self {
		if ( ! $this->status->can_move_to( $status ) ) {
			throw new InvalidTransition(
				sprintf(
					'A purchase order cannot go from %s to %s.',
					$this->status->value,
					$status->value
				)
			);
		}

		return $this->copy( array( 'status' => $status ) );
	}

	/**
	 * The same order with an id, as it comes back from an insert.
	 *
	 * @param int $id Row id.
	 * @return self
	 */
	public function with_id( int $id ): self {
		return $this->copy( array( 'id' => $id ) );
	}

	/**
	 * The same order with a number.
	 *
	 * @param string $number The number on the document.
	 * @return self
	 */
	public function with_number( string $number ): self {
		return $this->copy( array( 'number' => $number ) );
	}

	/**
	 * The same order with these lines.
	 *
	 * @param list<PurchaseOrderLine> $lines The lines.
	 * @return self
	 */
	public function with_lines( array $lines ): self {
		return $this->copy( array( 'lines' => $lines ) );
	}

	/**
	 * The same order, marked as sent now.
	 *
	 * @param string $when Timestamp, "Y-m-d H:i:s".
	 * @return self
	 * @throws InvalidTransition When it cannot be sent.
	 */
	public function sent_at( string $when ): self {
		return $this->with_status( PurchaseOrderStatus::SENT )->copy( array( 'sent_at' => $when ) );
	}

	/**
	 * A copy with some fields changed.
	 *
	 * @param array<string,mixed> $changes Fields to change.
	 * @return self
	 */
	private function copy( array $changes ): self {
		return new self(
			(int) ( $changes['id'] ?? $this->id ),
			(string) ( $changes['number'] ?? $this->number ),
			(int) ( $changes['supplier_id'] ?? $this->supplier_id ),
			$changes['status'] ?? $this->status,
			(string) ( $changes['currency'] ?? $this->currency ),
			(string) ( $changes['order_date'] ?? $this->order_date ),
			array_key_exists( 'expected_date', $changes ) ? $changes['expected_date'] : $this->expected_date,
			(string) ( $changes['supplier_reference'] ?? $this->supplier_reference ),
			(string) ( $changes['delivery_address'] ?? $this->delivery_address ),
			(string) ( $changes['payment_terms'] ?? $this->payment_terms ),
			(string) ( $changes['internal_notes'] ?? $this->internal_notes ),
			(string) ( $changes['supplier_notes'] ?? $this->supplier_notes ),
			$changes['lines'] ?? $this->lines,
			array_key_exists( 'sent_at', $changes ) ? $changes['sent_at'] : $this->sent_at
		);
	}

	/**
	 * Start a draft for a supplier.
	 *
	 * @param int    $supplier_id Who it is for.
	 * @param string $currency    Which money it is written in.
	 * @param string $order_date  Date of the order, "Y-m-d".
	 * @return self
	 */
	public static function draft_for( int $supplier_id, string $currency, string $order_date ): self {
		return new self(
			0,
			'',
			$supplier_id,
			PurchaseOrderStatus::DRAFT,
			Currency::normalise( $currency ),
			$order_date
		);
	}
}
