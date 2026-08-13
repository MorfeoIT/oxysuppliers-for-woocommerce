<?php
/**
 * The REST routes.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Rest;

use Oxysoft\OxySuppliers\Domain\PurchaseOrderStatus;
use Oxysoft\OxySuppliers\Domain\Requirement;
use Oxysoft\OxySuppliers\Engine\RequirementCalculator;
use Oxysoft\OxySuppliers\Persistence\CatalogueRepository;
use Oxysoft\OxySuppliers\Persistence\PurchaseOrderRepository;
use Oxysoft\OxySuppliers\Persistence\SupplierRepository;
use Oxysoft\OxySuppliers\Support\Capabilities;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Read-only routes under `oxysuppliers/v1`.
 *
 * Read-only on purpose, for now. Everything this plugin writes moves stock or
 * sends an email to somebody outside the building, and both of those want the
 * guards that live in the screens — the idempotency key that a form carries, the
 * recipient shown before anything is sent. A write API that skipped them would
 * be a way around the care taken everywhere else.
 *
 * Every route has its own `permission_callback`, and none of them is
 * `__return_true`.
 */
final class Controller {

	/**
	 * The namespace.
	 */
	public const NAMESPACE = 'oxysuppliers/v1';

	/**
	 * Take the collaborators.
	 *
	 * @param CatalogueRepository     $catalogue  Stock, sales and thresholds.
	 * @param RequirementCalculator   $calculator The suggestion.
	 * @param PurchaseOrderRepository $orders     Storage for orders.
	 * @param SupplierRepository      $suppliers  Storage for suppliers.
	 */
	public function __construct(
		private readonly CatalogueRepository $catalogue,
		private readonly RequirementCalculator $calculator,
		private readonly PurchaseOrderRepository $orders,
		private readonly SupplierRepository $suppliers
	) {
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'add_routes' ) );
	}

	/**
	 * The routes themselves.
	 *
	 * @return void
	 */
	public function add_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/requirements',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'requirements' ),
				'permission_callback' => static fn (): bool => current_user_can( Capabilities::VIEW_ORDERS ),
				'args'                => array(
					'below'    => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/orders',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'purchase_orders' ),
				'permission_callback' => static fn (): bool => current_user_can( Capabilities::VIEW_ORDERS ),
				'args'                => array(
					'status'   => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/suppliers',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'suppliers' ),
				'permission_callback' => static fn (): bool => current_user_can( Capabilities::MANAGE_SUPPLIERS ),
			)
		);
	}

	/**
	 * What to reorder.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function requirements( WP_REST_Request $request ): WP_REST_Response {
		$query = array(
			'below_reorder_point' => (bool) $request->get_param( 'below' ),
			'per_page'            => max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) ),
			'page'                => max( 1, (int) $request->get_param( 'page' ) ),
		);

		$rows = $this->calculator->calculate_all( $this->catalogue->paginate( $query ) );

		$data = array_map(
			static function ( Requirement $row ): array {
				$article  = $row->context;
				$supplier = $article->supplier;

				return array(
					'product_id'    => $article->product_id,
					'variation_id'  => $article->variation_id,
					'sku'           => $article->sku,
					'name'          => $article->name,
					'available'     => $article->available(),
					'incoming'      => $article->incoming,
					'reorder_point' => $article->reorder_point,
					'target'        => $article->target,
					'sold'          => array(
						'7'  => $article->sold_7,
						'30' => $article->sold_30,
						'90' => $article->sold_90,
					),
					'needed'        => $row->needed,
					'suggested'     => $row->suggested,
					'status'        => $row->status->value,
					'supplier_id'   => null === $supplier ? null : $supplier->supplier_id,
					'unit_cost'     => null === $supplier ? null : $supplier->unit_cost->to_decimal(),
					'currency'      => null === $supplier ? null : $supplier->unit_cost->currency,
				);
			},
			$rows
		);

		return new WP_REST_Response(
			array(
				'total' => $this->catalogue->count( $query ),
				'items' => $data,
			)
		);
	}

	/**
	 * The purchase orders.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function purchase_orders( WP_REST_Request $request ): WP_REST_Response {
		$status = (string) $request->get_param( 'status' );

		$query = array(
			'status'   => null === PurchaseOrderStatus::tryFrom( $status ) ? array() : array( $status ),
			'per_page' => max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) ),
		);

		$orders = $this->orders->paginate( $query );
		$totals = $this->orders->totals_for( array_map( static fn ( $order ): int => $order->id, $orders ) );

		$data = array();

		foreach ( $orders as $order ) {
			$data[] = array(
				'id'            => $order->id,
				'number'        => $order->number,
				'supplier_id'   => $order->supplier_id,
				'status'        => $order->status->value,
				'order_date'    => $order->order_date,
				'expected_date' => $order->expected_date,
				'currency'      => $order->currency,
				'total'         => number_format( ( $totals[ $order->id ] ?? 0 ) / 100, 2, '.', '' ),
			);
		}

		return new WP_REST_Response(
			array(
				'total' => $this->orders->count( $query ),
				'items' => $data,
			)
		);
	}

	/**
	 * The suppliers.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 */
	public function suppliers( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$data = array();

		foreach ( $this->suppliers->paginate( array( 'per_page' => 200 ) ) as $supplier ) {
			$data[] = array(
				'id'             => $supplier->id,
				'name'           => $supplier->display_name(),
				'company_name'   => $supplier->company_name,
				'vat_number'     => $supplier->vat_number,
				'currency'       => $supplier->currency(),
				'lead_time_days' => $supplier->lead_time_days,
				'active'         => $supplier->is_active(),
			);
		}

		return new WP_REST_Response(
			array(
				'total' => $this->suppliers->count(),
				'items' => $data,
			)
		);
	}
}
