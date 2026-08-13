<?php
/**
 * A delivery arriving.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Domain;

/**
 * One moment of goods arriving against a purchase order.
 *
 * The idempotency key is what makes receiving safe, and it is worth
 * understanding why it is on the receipt rather than anywhere else. It is
 * generated when the **form is drawn**, not when it is submitted, so pressing
 * the button twice, reloading, going back, or a browser retrying a timed-out
 * request all carry the same key. The unique index refuses the second one.
 *
 * A receipt is never edited and never deleted. Getting it wrong is corrected by
 * a reversing receipt that points back at this one.
 */
final class Receipt {

	/**
	 * Hold a receipt.
	 *
	 * @param int               $id             Row id, 0 when not yet stored.
	 * @param int               $po_id          The order it is against.
	 * @param string            $idempotency_key The key that makes it happen once.
	 * @param list<ReceiptLine> $lines          What arrived.
	 * @param int|null          $reverses_id    The receipt this one undoes, if any.
	 * @param string            $reference      Their delivery note number.
	 * @param string            $notes          Anything worth saying.
	 * @param bool              $stock_applied  Whether stock was moved.
	 * @param string            $received_at    When, "Y-m-d H:i:s".
	 * @param int               $received_by    Who.
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $po_id,
		public readonly string $idempotency_key,
		public readonly array $lines = array(),
		public readonly ?int $reverses_id = null,
		public readonly string $reference = '',
		public readonly string $notes = '',
		public readonly bool $stock_applied = false,
		public readonly string $received_at = '',
		public readonly int $received_by = 0
	) {
	}

	/**
	 * Whether this receipt undoes another one.
	 *
	 * @return bool
	 */
	public function is_reversal(): bool {
		return null !== $this->reverses_id;
	}

	/**
	 * How many units it moves, in total.
	 *
	 * @return int
	 */
	public function total_quantity(): int {
		$total = 0;

		foreach ( $this->lines as $line ) {
			$total += $line->quantity;
		}

		return $total;
	}

	/**
	 * Whether there is anything in it.
	 *
	 * A receipt with nothing on it is somebody pressing the button without
	 * filling anything in, which is not a delivery.
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		foreach ( $this->lines as $line ) {
			if ( 0 !== $line->quantity ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The same receipt with an id.
	 *
	 * @param int $id Row id.
	 * @return self
	 */
	public function with_id( int $id ): self {
		return new self(
			$id,
			$this->po_id,
			$this->idempotency_key,
			$this->lines,
			$this->reverses_id,
			$this->reference,
			$this->notes,
			$this->stock_applied,
			$this->received_at,
			$this->received_by
		);
	}

	/**
	 * The same receipt with these lines.
	 *
	 * @param list<ReceiptLine> $lines The lines.
	 * @return self
	 */
	public function with_lines( array $lines ): self {
		return new self(
			$this->id,
			$this->po_id,
			$this->idempotency_key,
			$lines,
			$this->reverses_id,
			$this->reference,
			$this->notes,
			$this->stock_applied,
			$this->received_at,
			$this->received_by
		);
	}

	/**
	 * The receipt that undoes this one.
	 *
	 * A new key, because it is a new thing happening: the original key belongs
	 * to the delivery and must go on refusing a repeat of it.
	 *
	 * @param string $key Idempotency key for the reversal.
	 * @return self
	 */
	public function reversal( string $key ): self {
		$lines = array();

		foreach ( $this->lines as $line ) {
			$lines[] = $line->reversed();
		}

		return new self(
			0,
			$this->po_id,
			$key,
			$lines,
			$this->id,
			$this->reference,
			'',
			false,
			'',
			0
		);
	}
}
