<?php
/**
 * Turning shortages into orders, grouped by supplier.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Tests\Integration;

use Oxysoft\OxySuppliers\Domain\PurchaseOrderStatus;
use Oxysoft\OxySuppliers\Domain\RequirementContext;
use Oxysoft\OxySuppliers\Domain\Supplier;
use Oxysoft\OxySuppliers\Domain\SupplierProduct;
use Oxysoft\OxySuppliers\Engine\RequirementCalculator;
use Oxysoft\OxySuppliers\Engine\TargetStockStrategy;
use Oxysoft\OxySuppliers\Persistence\Migrator;
use Oxysoft\OxySuppliers\Persistence\PurchaseOrderRepository;
use Oxysoft\OxySuppliers\Persistence\SupplierRepository;
use Oxysoft\OxySuppliers\Persistence\Tables;
use Oxysoft\OxySuppliers\Service\ProposalBuilder;
use Oxysoft\OxySuppliers\Service\PurchaseOrderNumbers;
use WP_UnitTestCase;

/**
 * The grouping is the whole point: fifteen ticked articles from four suppliers
 * are four orders, not fifteen.
 */
final class ProposalBuilderTest extends WP_UnitTestCase {

	private ProposalBuilder $proposals;
	private PurchaseOrderRepository $orders;
	private SupplierRepository $suppliers;
	private RequirementCalculator $calculator;

	/**
	 * Empty tables and the collaborators.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		( new Migrator() )->migrate();

		foreach ( array( Tables::ORDER_ITEMS, Tables::PURCHASE_ORDERS, Tables::SUPPLIERS ) as $table ) {
			$name = Tables::name( $table );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test fixture, table name from a constant.
			$wpdb->query( "DELETE FROM {$name}" );
		}

		$this->orders     = new PurchaseOrderRepository( new PurchaseOrderNumbers() );
		$this->suppliers  = new SupplierRepository();
		$this->proposals  = new ProposalBuilder( $this->orders, $this->suppliers );
		$this->calculator = new RequirementCalculator( new TargetStockStrategy() );
	}

	/**
	 * Store a supplier.
	 *
	 * @param string $name      Their name.
	 * @param int    $lead_time Days from order to delivery.
	 * @return int
	 */
	private function supplier( string $name, int $lead_time = 3 ): int {
		return $this->suppliers->insert(
			Supplier::from_fields(
				array(
					'company_name'   => $name,
					'currency'       => 'EUR',
					'lead_time_days' => (string) $lead_time,
					'payment_terms'  => '30 giorni',
				)
			)
		);
	}

	/**
	 * A shortage of one article from one supplier.
	 *
	 * @param int    $supplier_id Who sells it.
	 * @param int    $product_id  Which article.
	 * @param string $sku         Our code.
	 * @param int    $stock       What is on the shelf.
	 * @param int    $multiple    The supplier's order multiple.
	 * @return \Oxysoft\OxySuppliers\Domain\Requirement
	 */
	private function shortage( int $supplier_id, int $product_id, string $sku, int $stock = 3, int $multiple = 1 ) {
		$listing = SupplierProduct::from_fields(
			array(
				'supplier_id'    => $supplier_id,
				'product_id'     => $product_id,
				'currency'       => 'EUR',
				'unit_cost'      => '10.00',
				'order_multiple' => (string) $multiple,
				'supplier_sku'   => 'S-' . $product_id,
				'lead_time_days' => '5',
			),
			$product_id
		);

		$context = new RequirementContext(
			$product_id,
			0,
			$sku,
			'Articolo ' . $product_id,
			$stock,
			0,
			0,
			9,
			18,
			0,
			0,
			0,
			$listing
		);

		return $this->calculator->calculate( $context );
	}

	/**
	 * Four suppliers, one order each.
	 *
	 * @return void
	 */
	public function test_groups_by_supplier(): void {
		$abc = $this->supplier( 'ABC Srl' );
		$def = $this->supplier( 'DEF Spa' );

		$made = $this->proposals->build(
			array(
				$this->shortage( $abc, 100, 'A-1' ),
				$this->shortage( $abc, 101, 'A-2' ),
				$this->shortage( $def, 102, 'B-1' ),
			),
			'2026-08-13'
		);

		$this->assertCount( 2, $made );

		$by_supplier = array();

		foreach ( $made as $order ) {
			$by_supplier[ $order->supplier_id ] = $order;
		}

		$this->assertCount( 2, $by_supplier[ $abc ]->lines );
		$this->assertCount( 1, $by_supplier[ $def ]->lines );
	}

	/**
	 * Everything it makes is a draft. A suggestion that posts itself is a
	 * suggestion nobody trusts.
	 *
	 * @return void
	 */
	public function test_everything_it_makes_is_a_draft(): void {
		$abc = $this->supplier( 'ABC Srl' );

		$made = $this->proposals->build( array( $this->shortage( $abc, 100, 'A-1' ) ), '2026-08-13' );

		$this->assertCount( 1, $made );
		$this->assertSame( PurchaseOrderStatus::DRAFT, $made[0]->status );
		$this->assertNull( $made[0]->sent_at );
	}

	/**
	 * The quantity is the one the supplier's terms allow.
	 *
	 * @return void
	 */
	public function test_the_quantity_respects_the_supplier_terms(): void {
		$abc = $this->supplier( 'ABC Srl' );

		$made = $this->proposals->build(
			array( $this->shortage( $abc, 100, 'A-1', 3, 10 ) ),
			'2026-08-13'
		);

		$this->assertCount( 1, $made );

		// Fifteen short, sold in tens, so twenty.
		$this->assertSame( 20, $made[0]->lines[0]->qty_ordered );
		$this->assertSame( '200.00', $made[0]->total()->to_decimal() );
	}

	/**
	 * The supplier's own code goes on the order, because that is what they will
	 * look for.
	 *
	 * @return void
	 */
	public function test_the_supplier_code_goes_on_the_line(): void {
		$abc = $this->supplier( 'ABC Srl' );

		$made = $this->proposals->build( array( $this->shortage( $abc, 100, 'A-1' ) ), '2026-08-13' );

		$this->assertSame( 'S-100', $made[0]->lines[0]->supplier_sku );
		$this->assertSame( 'A-1', $made[0]->lines[0]->sku );
	}

	/**
	 * When it should turn up, from the longest wait on the order.
	 *
	 * @return void
	 */
	public function test_works_out_when_it_should_arrive(): void {
		$abc = $this->supplier( 'ABC Srl', 3 );

		$made = $this->proposals->build( array( $this->shortage( $abc, 100, 'A-1' ) ), '2026-08-13' );

		// The article's own lead time is five days, longer than the supplier's
		// usual three, and the longer one wins.
		$this->assertSame( '2026-08-18', $made[0]->expected_date );
		$this->assertSame( '30 giorni', $made[0]->payment_terms );
	}

	/**
	 * Rows with nothing to order are left out rather than turned into empty
	 * paperwork.
	 *
	 * @return void
	 */
	public function test_leaves_out_what_does_not_need_ordering(): void {
		$abc = $this->supplier( 'ABC Srl' );

		$made = $this->proposals->build(
			array(
				// A full shelf: nothing missing.
				$this->shortage( $abc, 100, 'A-1', 40 ),
			),
			'2026-08-13'
		);

		$this->assertSame( array(), $made );
		$this->assertSame( 0, $this->orders->count() );
	}

	/**
	 * An article nobody sells cannot become an order.
	 *
	 * @return void
	 */
	public function test_leaves_out_what_has_no_supplier(): void {
		$context = new RequirementContext( 100, 0, 'A-1', 'Articolo', 3, 0, 0, 9, 18 );

		$made = $this->proposals->build( array( $this->calculator->calculate( $context ) ), '2026-08-13' );

		$this->assertSame( array(), $made );
	}

	/**
	 * A supplier that has been deleted since the screen was drawn does not
	 * produce an order for nobody.
	 *
	 * @return void
	 */
	public function test_a_supplier_that_no_longer_exists_makes_nothing(): void {
		$made = $this->proposals->build( array( $this->shortage( 9999, 100, 'A-1' ) ), '2026-08-13' );

		$this->assertSame( array(), $made );
	}

	/**
	 * Every order it makes is really in the database, with its own number.
	 *
	 * @return void
	 */
	public function test_the_orders_are_stored_and_numbered(): void {
		$abc = $this->supplier( 'ABC Srl' );
		$def = $this->supplier( 'DEF Spa' );

		$made = $this->proposals->build(
			array(
				$this->shortage( $abc, 100, 'A-1' ),
				$this->shortage( $def, 101, 'B-1' ),
			),
			'2026-08-13'
		);

		$this->assertCount( 2, $made );
		$this->assertSame( 2, $this->orders->count() );
		$this->assertNotSame( $made[0]->number, $made[1]->number );

		foreach ( $made as $order ) {
			$this->assertNotNull( $this->orders->find( $order->id ) );
		}
	}
}
