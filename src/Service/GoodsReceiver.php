<?php
/**
 * Receiving goods against a purchase order.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Service;

use Oxysoft\OxySuppliers\Domain\Money;
use Oxysoft\OxySuppliers\Domain\PurchaseOrder;
use Oxysoft\OxySuppliers\Domain\Receipt;
use Oxysoft\OxySuppliers\Domain\ReceiptLine;
use Oxysoft\OxySuppliers\Persistence\PurchaseOrderRepository;
use Oxysoft\OxySuppliers\Persistence\ReceiptRepository;
use Oxysoft\OxySuppliers\Support\Settings;

/**
 * The most dangerous thing this plugin does, and the most carefully guarded.
 *
 * A shop whose stock says four when there are none sells what it has not got,
 * apologises to a customer, and stops trusting the plugin. So receiving is
 * defended four times over, in this order:
 *
 * 1. **The idempotency key.** Written first, with a unique index behind it. A
 *    second press, a reload, a back button, a retried request — all carry the
 *    key the form was drawn with, and the database refuses the second one.
 * 2. **A lock on the order.** Two people receiving the same delivery at the
 *    same moment: one waits, and is told to.
 * 3. **The quantities are re-read inside the transaction**, from the receipts
 *    themselves rather than from the copy on the order line, and certainly not
 *    from the numbers the page was drawn with a minute ago.
 * 4. **A transaction** around everything the plugin owns.
 *
 * Stock is moved **after** the commit and outside the transaction, because it
 * is not ours: it belongs to WooCommerce, which has its own atomic way of
 * changing it. Reading it and writing it back would leave room for a customer's
 * order to slip in between.
 */
final class GoodsReceiver {

	/**
	 * Take the collaborators.
	 *
	 * @param ReceiptRepository       $receipts Storage for receipts.
	 * @param PurchaseOrderRepository $orders   Storage for orders.
	 * @param AuditLogger             $audit    The trail.
	 */
	public function __construct(
		private readonly ReceiptRepository $receipts,
		private readonly PurchaseOrderRepository $orders,
		private readonly AuditLogger $audit
	) {
	}

	/**
	 * Record a delivery.
	 *
	 * @param PurchaseOrder     $order      The order it is against.
	 * @param array<int,int>    $quantities How many arrived, keyed by order line id.
	 * @param array<int,string> $costs      What was really charged, keyed by order line id.
	 * @param string            $key        The idempotency key the form was drawn with.
	 * @param string            $reference  Their delivery note number.
	 * @param string            $notes      Anything worth saying.
	 * @return ReceiptOutcome
	 */
	public function receive(
		PurchaseOrder $order,
		array $quantities,
		array $costs,
		string $key,
		string $reference = '',
		string $notes = ''
	): ReceiptOutcome {
		global $wpdb;

		if ( '' === trim( $key ) ) {
			return new ReceiptOutcome( ReceiptOutcome::FAILED, null, __( 'The form was missing its key. Reload the page and try again.', 'oxysuppliers-for-woocommerce' ) );
		}

		$token = wp_generate_uuid4();

		if ( ! $this->receipts->lock( $order->id, $token ) ) {
			return new ReceiptOutcome(
				ReceiptOutcome::BUSY,
				null,
				__( 'Somebody else is receiving this order right now. Wait a moment and look again before entering it a second time.', 'oxysuppliers-for-woocommerce' )
			);
		}

		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control.
			$wpdb->query( 'START TRANSACTION' );

			$receipt_id = $this->receipts->claim(
				new Receipt( 0, $order->id, $key, array(), null, $reference, $notes )
			);

			if ( 0 === $receipt_id ) {
				// The key is taken, which means this exact delivery is already
				// recorded. Not an error: the right answer is the receipt that
				// exists.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control.
				$wpdb->query( 'ROLLBACK' );
				$this->receipts->unlock( $order->id, $token );

				return new ReceiptOutcome(
					ReceiptOutcome::ALREADY_RECORDED,
					$this->receipts->find_by_key( $key ),
					__( 'This delivery had already been recorded, so nothing was added a second time.', 'oxysuppliers-for-woocommerce' )
				);
			}

			$already = $this->receipts->received_by_line( $order->id );
			$lines   = array();
			$over    = array();

			foreach ( $order->lines as $line ) {
				$wanted = (int) ( $quantities[ $line->id ] ?? 0 );

				if ( 0 === $wanted ) {
					continue;
				}

				$received  = (int) ( $already[ $line->id ] ?? 0 );
				$remaining = $line->qty_ordered - $received;

				if ( $wanted > $remaining && ! $this->over_receipt_allowed() ) {
					$over[] = '' !== $line->description ? $line->description : $line->sku;

					continue;
				}

				$lines[] = new ReceiptLine(
					0,
					$line->id,
					$line->product_id,
					$line->variation_id,
					$wanted,
					$this->cost_of( $costs[ $line->id ] ?? '', $order->currency )
				);
			}

			if ( array() !== $over ) {
				$this->receipts->discard( $receipt_id );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control.
				$wpdb->query( 'ROLLBACK' );
				$this->receipts->unlock( $order->id, $token );

				return new ReceiptOutcome(
					ReceiptOutcome::TOO_MANY,
					null,
					sprintf(
						/* translators: %s: list of article names. */
						__( 'More was entered than is still outstanding for: %s. Nothing was recorded.', 'oxysuppliers-for-woocommerce' ),
						implode( ', ', $over )
					)
				);
			}

			if ( array() === $lines ) {
				$this->receipts->discard( $receipt_id );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control.
				$wpdb->query( 'ROLLBACK' );
				$this->receipts->unlock( $order->id, $token );

				return new ReceiptOutcome(
					ReceiptOutcome::NOTHING,
					null,
					__( 'No quantity was entered, so there was nothing to record.', 'oxysuppliers-for-woocommerce' )
				);
			}

			foreach ( $lines as $line ) {
				$this->receipts->add_line( $receipt_id, $line );
			}

			$this->settle( $order, $receipt_id );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control.
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $failure ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control.
			$wpdb->query( 'ROLLBACK' );
			$this->receipts->unlock( $order->id, $token );

			return new ReceiptOutcome( ReceiptOutcome::FAILED, null, $failure->getMessage() );
		}

		$this->receipts->unlock( $order->id, $token );

		$receipt = $this->receipts->find( $receipt_id );

		if ( null === $receipt ) {
			return new ReceiptOutcome( ReceiptOutcome::FAILED );
		}

		$this->apply_stock( $receipt );

		// Read back, so that what the caller is handed says whether the stock
		// moved. The object built a moment ago cannot know: the movement
		// happened after it was made.
		$receipt = $this->receipts->find( $receipt_id ) ?? $receipt;

		$this->audit->log(
			AuditLogger::OBJECT_RECEIPT,
			(string) $receipt_id,
			AuditLogger::ACTION_CREATED,
			null,
			array(
				'po_id'     => $order->id,
				'quantity'  => $receipt->total_quantity(),
				'reference' => $reference,
			),
			sprintf(
				/* translators: 1: number of units, 2: purchase order number. */
				__( 'Received %1$d units against %2$s', 'oxysuppliers-for-woocommerce' ),
				$receipt->total_quantity(),
				$order->number
			)
		);

		/**
		 * Fires when goods have been received.
		 *
		 * @since 0.1.0
		 *
		 * @param Receipt       $receipt The receipt.
		 * @param PurchaseOrder $order   The order it is against.
		 */
		do_action( 'oxysuppliers_receipt_created', $receipt, $order );

		return new ReceiptOutcome( ReceiptOutcome::RECORDED, $receipt );
	}

	/**
	 * Undo a delivery that should not have been recorded.
	 *
	 * A **compensating receipt**, not a deletion: the one that was wrong stays,
	 * and the one that corrects it points at it. A year later somebody can read
	 * what happened; with a deletion they would read a story with a page torn
	 * out.
	 *
	 * @param Receipt $receipt The receipt to undo.
	 * @param string  $key     Idempotency key for the reversal.
	 * @return ReceiptOutcome
	 */
	public function reverse( Receipt $receipt, string $key ): ReceiptOutcome {
		global $wpdb;

		if ( $receipt->is_reversal() ) {
			return new ReceiptOutcome(
				ReceiptOutcome::FAILED,
				null,
				__( 'That entry is already a correction of another one.', 'oxysuppliers-for-woocommerce' )
			);
		}

		$order = $this->orders->find( $receipt->po_id );

		if ( null === $order ) {
			return new ReceiptOutcome( ReceiptOutcome::FAILED );
		}

		$token = wp_generate_uuid4();

		if ( ! $this->receipts->lock( $order->id, $token ) ) {
			return new ReceiptOutcome(
				ReceiptOutcome::BUSY,
				null,
				__( 'Somebody else is working on this order right now.', 'oxysuppliers-for-woocommerce' )
			);
		}

		$reversal = $receipt->reversal( $key );

		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control.
			$wpdb->query( 'START TRANSACTION' );

			$reversal_id = $this->receipts->claim( $reversal );

			if ( 0 === $reversal_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control.
				$wpdb->query( 'ROLLBACK' );
				$this->receipts->unlock( $order->id, $token );

				return new ReceiptOutcome(
					ReceiptOutcome::ALREADY_RECORDED,
					$this->receipts->find_by_key( $key ),
					__( 'That correction had already been made.', 'oxysuppliers-for-woocommerce' )
				);
			}

			foreach ( $reversal->lines as $line ) {
				$this->receipts->add_line( $reversal_id, $line );
			}

			$this->settle( $order, $reversal_id );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control.
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $failure ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction control.
			$wpdb->query( 'ROLLBACK' );
			$this->receipts->unlock( $order->id, $token );

			return new ReceiptOutcome( ReceiptOutcome::FAILED, null, $failure->getMessage() );
		}

		$this->receipts->unlock( $order->id, $token );

		$stored = $this->receipts->find( $reversal_id );

		if ( null === $stored ) {
			return new ReceiptOutcome( ReceiptOutcome::FAILED );
		}

		// Only put the stock back if it was moved in the first place — and ask
		// the database, not the object the caller has been holding. That copy
		// was very likely read before the movement happened, and believing it
		// leaves the shelf saying more than there is.
		$original = $this->receipts->find( $receipt->id ) ?? $receipt;

		if ( $original->stock_applied ) {
			$this->apply_stock( $stored );
		}

		$this->audit->log(
			AuditLogger::OBJECT_RECEIPT,
			(string) $reversal_id,
			AuditLogger::ACTION_STOCK_MOVE,
			array( 'reverses' => $receipt->id ),
			array( 'quantity' => $stored->total_quantity() ),
			sprintf(
				/* translators: %d: the receipt being corrected. */
				__( 'Correction of receipt %d', 'oxysuppliers-for-woocommerce' ),
				$receipt->id
			)
		);

		return new ReceiptOutcome( ReceiptOutcome::RECORDED, $stored );
	}

	/**
	 * Bring the order's lines and state into line with what has been received.
	 *
	 * Runs inside the transaction. The quantities come from the receipts, which
	 * are the record; the column on the order line is a copy being refreshed.
	 *
	 * @param PurchaseOrder $order      The order.
	 * @param int           $receipt_id The receipt just written.
	 * @return void
	 */
	private function settle( PurchaseOrder $order, int $receipt_id ): void {
		unset( $receipt_id );

		$totals = $this->receipts->received_by_line( $order->id );
		$lines  = array();

		foreach ( $order->lines as $line ) {
			$received = (int) ( $totals[ $line->id ] ?? 0 );

			$this->orders->set_line_received( $line->id, $received );

			$lines[] = $line->with_received( $received );
		}

		$settled = $order->with_lines( $lines );
		$status  = $settled->status_after_receiving();

		if ( $status !== $order->status && $order->status->can_move_to( $status ) ) {
			$this->orders->set_status( $order, $status );
		}
	}

	/**
	 * Move the stock, and write down what moved.
	 *
	 * Outside the transaction and after it, on purpose. `wc_update_product_stock`
	 * changes the quantity in one atomic statement; reading it and writing it
	 * back would leave room for a customer's order to land in between and be
	 * overwritten.
	 *
	 * The value read just before is only for the log, and is labelled as such.
	 *
	 * @param Receipt $receipt The receipt.
	 * @return void
	 */
	private function apply_stock( Receipt $receipt ): void {
		if ( true !== Settings::get( 'update_stock_on_receipt' ) ) {
			foreach ( $receipt->lines as $line ) {
				$this->receipts->set_line_stock( $line->id, null, null, 'setting_off' );
			}

			return;
		}

		$moved = false;

		foreach ( $receipt->lines as $line ) {
			if ( 0 === $line->quantity ) {
				continue;
			}

			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $line->stock_id() ) : null;

			if ( ! $product instanceof \WC_Product ) {
				$this->receipts->set_line_stock( $line->id, null, null, 'no_product' );

				continue;
			}

			// An article WooCommerce is not counting has no stock to move. The
			// receipt still stands, and the log says why nothing happened.
			if ( ! $product->managing_stock() ) {
				$this->receipts->set_line_stock( $line->id, null, null, 'not_tracked' );

				continue;
			}

			$before = (float) $product->get_stock_quantity();
			$after  = wc_update_product_stock(
				$product,
				abs( $line->quantity ),
				$line->quantity > 0 ? 'increase' : 'decrease'
			);

			$this->receipts->set_line_stock( $line->id, $before, is_numeric( $after ) ? (float) $after : null, '' );

			$moved = true;

			$this->audit->log(
				AuditLogger::OBJECT_STOCK,
				(string) $line->stock_id(),
				AuditLogger::ACTION_STOCK_MOVE,
				array( 'stock' => $before ),
				array( 'stock' => is_numeric( $after ) ? (float) $after : null ),
				sprintf(
					/* translators: 1: quantity moved, 2: receipt id. */
					__( '%1$+d from receipt %2$d', 'oxysuppliers-for-woocommerce' ),
					$line->quantity,
					$receipt->id
				)
			);
		}

		if ( $moved ) {
			$this->receipts->mark_stock_applied( $receipt->id );
		}
	}

	/**
	 * Whether the shop allows more to arrive than was ordered.
	 *
	 * @return bool
	 */
	private function over_receipt_allowed(): bool {
		/**
		 * Filters whether more may be received than was ordered.
		 *
		 * Off by default. An over-delivery is usually a typing mistake, and the
		 * cost of being wrong is stock that says more than is on the shelf.
		 *
		 * @since 0.1.0
		 *
		 * @param bool $allowed Whether to allow it.
		 */
		return (bool) apply_filters( 'oxysuppliers_allow_over_receipt', false );
	}

	/**
	 * The cost actually charged, when somebody typed one.
	 *
	 * @param string $typed    What was typed.
	 * @param string $currency The order's currency.
	 * @return Money|null
	 */
	private function cost_of( string $typed, string $currency ): ?Money {
		$typed = trim( $typed );

		if ( '' === $typed ) {
			return null;
		}

		$canonical = function_exists( 'wc_format_decimal' ) ? (string) wc_format_decimal( $typed, false, true ) : $typed;

		if ( '' === $canonical || 1 !== preg_match( '/^\d+(?:\.\d+)?$/', $canonical ) ) {
			return null;
		}

		return Money::from_decimal( $canonical, $currency );
	}
}
