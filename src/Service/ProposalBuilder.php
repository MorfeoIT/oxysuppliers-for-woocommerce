<?php
/**
 * Turning "what to reorder" into purchase orders.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Service;

use Oxysoft\OxySuppliers\Domain\ExpectedDate;
use Oxysoft\OxySuppliers\Domain\PurchaseOrder;
use Oxysoft\OxySuppliers\Domain\PurchaseOrderLine;
use Oxysoft\OxySuppliers\Domain\Requirement;
use Oxysoft\OxySuppliers\Persistence\PurchaseOrderRepository;
use Oxysoft\OxySuppliers\Persistence\SupplierRepository;

/**
 * Groups a screenful of shortages into one draft order per supplier.
 *
 * The grouping is the whole point. Somebody scanning the reordering screen
 * ticks fifteen articles that happen to come from four suppliers; what they
 * want is four orders, not fifteen, and certainly not to work out which is
 * which by hand.
 *
 * Everything it makes is a **draft**. Nothing is sent, and nothing leaves the
 * building, because a suggestion that posts itself is a suggestion nobody
 * trusts.
 */
final class ProposalBuilder {

	/**
	 * Take the collaborators.
	 *
	 * @param PurchaseOrderRepository $orders    Storage for orders.
	 * @param SupplierRepository      $suppliers Storage for suppliers.
	 */
	public function __construct(
		private readonly PurchaseOrderRepository $orders,
		private readonly SupplierRepository $suppliers
	) {
	}

	/**
	 * Build one draft order per supplier from these shortages.
	 *
	 * Rows with nothing to order, or with nobody to order from, are left out
	 * rather than turned into an empty order: they are on the screen so they
	 * can be dealt with, not so they can become paperwork.
	 *
	 * @param list<Requirement> $requirements What the screen suggested.
	 * @param string            $order_date   Date to put on the orders, "Y-m-d".
	 * @return list<PurchaseOrder> The drafts that were stored.
	 */
	public function build( array $requirements, string $order_date ): array {
		$grouped = array();

		foreach ( $requirements as $requirement ) {
			if ( ! $requirement->is_orderable() ) {
				continue;
			}

			$listing = $requirement->context->supplier;

			if ( null === $listing ) {
				continue;
			}

			$grouped[ $listing->supplier_id ][] = $requirement;
		}

		$made = array();

		foreach ( $grouped as $supplier_id => $rows ) {
			$order = $this->order_for( (int) $supplier_id, $rows, $order_date );

			if ( null !== $order ) {
				$made[] = $order;
			}
		}

		return $made;
	}

	/**
	 * One supplier's draft.
	 *
	 * @param int               $supplier_id  Who it is for.
	 * @param list<Requirement> $requirements Their share of the shortages.
	 * @param string            $order_date   Date to put on the order.
	 * @return PurchaseOrder|null
	 */
	private function order_for( int $supplier_id, array $requirements, string $order_date ): ?PurchaseOrder {
		$supplier = $this->suppliers->find( $supplier_id );

		if ( null === $supplier ) {
			return null;
		}

		$lines     = array();
		$lead_time = $supplier->lead_time_days;

		foreach ( $requirements as $position => $requirement ) {
			$listing = $requirement->context->supplier;

			if ( null === $listing ) {
				continue;
			}

			$lines[] = PurchaseOrderLine::from_listing(
				$listing,
				$requirement->suggested,
				$requirement->context->sku,
				$requirement->context->name,
				$position
			);

			$lead_time = max( $lead_time, $listing->lead_time_days );
		}

		if ( array() === $lines ) {
			return null;
		}

		$draft = PurchaseOrder::draft_for( $supplier_id, $supplier->currency(), $order_date )
			->with_lines( $lines );

		// When it should turn up, worked out from the longest wait on the
		// order. Better than an empty field somebody has to guess at, and it
		// can be changed.
		$expected = ExpectedDate::after( $order_date, $lead_time );

		$draft = new PurchaseOrder(
			0,
			'',
			$supplier_id,
			$draft->status,
			$draft->currency,
			$order_date,
			$expected,
			'',
			'',
			$supplier->payment_terms,
			'',
			'',
			$lines
		);

		$stored = $this->orders->create( $draft );

		if ( null === $stored ) {
			return null;
		}

		/**
		 * Fires when a draft purchase order has been proposed from the
		 * reordering screen.
		 *
		 * @since 0.1.0
		 *
		 * @param PurchaseOrder $stored The draft as stored.
		 */
		do_action( 'oxysuppliers_purchase_order_created', $stored );

		return $stored;
	}
}
