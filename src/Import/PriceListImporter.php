<?php
/**
 * Applying a parsed CSV.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Import;

use Oxysoft\OxySuppliers\Domain\Supplier;
use Oxysoft\OxySuppliers\Domain\SupplierProduct;
use Oxysoft\OxySuppliers\Persistence\SupplierProductRepository;
use Oxysoft\OxySuppliers\Persistence\SupplierRepository;

/**
 * Works out what an import would do, and then does exactly that.
 *
 * The same method answers both questions: `plan()` says what would happen to
 * each row, and `apply()` walks the same plan. That is what makes the preview
 * worth showing — a preview produced by different code from the import is a
 * guess with a table around it.
 */
final class PriceListImporter {

	public const CREATE_SUPPLIER = 'create_supplier';
	public const USE_SUPPLIER    = 'use_supplier';
	public const CREATE_LINE     = 'create_line';
	public const UPDATE_LINE     = 'update_line';
	public const SKIP            = 'skip';

	/**
	 * Take the collaborators.
	 *
	 * @param SupplierRepository        $suppliers Storage for suppliers.
	 * @param SupplierProductRepository $listings  Storage for price lists.
	 */
	public function __construct(
		private readonly SupplierRepository $suppliers,
		private readonly SupplierProductRepository $listings
	) {
	}

	/**
	 * What the import would do, row by row.
	 *
	 * @param ParsedCsv $file The parsed file.
	 * @return list<array<string,mixed>>
	 */
	public function plan( ParsedCsv $file ): array {
		$plan     = array();
		$invented = array();

		foreach ( $file->rows as $row ) {
			$entry = array(
				'line'     => (int) $row['line'],
				'supplier' => (string) ( $row['company_name'] ?? '' ),
				'sku'      => (string) ( $row['sku'] ?? '' ),
				'cost'     => (string) ( $row['unit_cost'] ?? '' ),
				'actions'  => array(),
				'errors'   => $row['errors'] ?? array(),
			);

			if ( array() !== $entry['errors'] ) {
				$entry['actions'][] = self::SKIP;
				$plan[]             = $entry;

				continue;
			}

			$product_id = $this->product_id( $entry['sku'] );

			if ( 0 === $product_id ) {
				$entry['errors'][]  = __( 'no product with that SKU', 'oxysuppliers-for-woocommerce' );
				$entry['actions'][] = self::SKIP;
				$plan[]             = $entry;

				continue;
			}

			$supplier = $this->find_supplier( $row );

			// A supplier named twice in one file is created once. Counting it
			// twice in the preview would promise something that will not happen.
			$key = $this->supplier_key( $row );

			if ( null === $supplier && ! isset( $invented[ $key ] ) ) {
				$entry['actions'][] = self::CREATE_SUPPLIER;
				$invented[ $key ]   = true;
			} elseif ( null !== $supplier ) {
				$entry['actions'][] = self::USE_SUPPLIER;
			}

			$existing = null;

			if ( null !== $supplier ) {
				foreach ( $this->listings->for_item( $product_id, 0 ) as $line ) {
					if ( $line->supplier_id === $supplier->id ) {
						$existing = $line;
					}
				}
			}

			$entry['actions'][]  = null === $existing ? self::CREATE_LINE : self::UPDATE_LINE;
			$entry['product_id'] = $product_id;
			$plan[]              = $entry;
		}

		return $plan;
	}

	/**
	 * Do it.
	 *
	 * @param ParsedCsv $file The parsed file.
	 * @return array{suppliers:int,lines:int,skipped:int}
	 */
	public function apply( ParsedCsv $file ): array {
		$made    = 0;
		$written = 0;
		$skipped = 0;

		foreach ( $file->rows as $row ) {
			if ( array() !== ( $row['errors'] ?? array() ) ) {
				++$skipped;

				continue;
			}

			$product_id = $this->product_id( (string) $row['sku'] );

			if ( 0 === $product_id ) {
				++$skipped;

				continue;
			}

			$supplier = $this->find_supplier( $row );

			if ( null === $supplier ) {
				$id = $this->suppliers->insert(
					Supplier::from_fields(
						array(
							'company_name' => (string) $row['company_name'],
							'vat_number'   => (string) ( $row['vat_number'] ?? '' ),
							'order_email'  => (string) ( $row['order_email'] ?? '' ),
							'currency'     => get_woocommerce_currency(),
						)
					)
				);

				if ( 0 === $id ) {
					++$skipped;

					continue;
				}

				$supplier = $this->suppliers->find( $id );
				++$made;
			}

			if ( null === $supplier ) {
				++$skipped;

				continue;
			}

			$cost = SupplierCsvParser::number( (string) ( $row['unit_cost'] ?? '' ) ) ?? '0';

			$stored = $this->listings->save(
				SupplierProduct::from_fields(
					array(
						'supplier_id'    => $supplier->id,
						'product_id'     => $product_id,
						'variation_id'   => 0,
						'supplier_sku'   => (string) ( $row['supplier_sku'] ?? '' ),
						'currency'       => $supplier->currency(),
						'unit_cost'      => $cost,
						'min_order_qty'  => (string) ( $row['min_order_qty'] ?? '' ),
						'order_multiple' => '' === (string) ( $row['order_multiple'] ?? '' ) ? '1' : (string) $row['order_multiple'],
						'pack_qty'       => '' === (string) ( $row['pack_qty'] ?? '' ) ? '1' : (string) $row['pack_qty'],
						'lead_time_days' => (string) ( $row['lead_time_days'] ?? '' ),
					)
				)
			);

			if ( $stored > 0 ) {
				++$written;
			} else {
				++$skipped;
			}
		}

		return array(
			'suppliers' => $made,
			'lines'     => $written,
			'skipped'   => $skipped,
		);
	}

	/**
	 * The supplier a row is about, if we already have them.
	 *
	 * Matched on the VAT number first, because two companies can share a name
	 * and no two share a VAT number.
	 *
	 * @param array<string,mixed> $row The row.
	 * @return Supplier|null
	 */
	private function find_supplier( array $row ): ?Supplier {
		$vat = trim( (string) ( $row['vat_number'] ?? '' ) );

		if ( '' !== $vat ) {
			foreach ( $this->suppliers->paginate(
				array(
					'search'   => $vat,
					'per_page' => 5,
				)
			) as $candidate ) {
				if ( strcasecmp( $candidate->vat_number, $vat ) === 0 ) {
					return $candidate;
				}
			}
		}

		$name = trim( (string) ( $row['company_name'] ?? '' ) );

		foreach ( $this->suppliers->paginate(
			array(
				'search'   => $name,
				'per_page' => 5,
			)
		) as $candidate ) {
			if ( strcasecmp( $candidate->company_name, $name ) === 0 ) {
				return $candidate;
			}
		}

		return null;
	}

	/**
	 * How a supplier is identified within one file.
	 *
	 * @param array<string,mixed> $row The row.
	 * @return string
	 */
	private function supplier_key( array $row ): string {
		$vat = trim( (string) ( $row['vat_number'] ?? '' ) );

		return '' !== $vat ? 'vat:' . strtolower( $vat ) : 'name:' . strtolower( trim( (string) $row['company_name'] ) );
	}

	/**
	 * The WooCommerce product with that SKU.
	 *
	 * @param string $sku Our code.
	 * @return int
	 */
	private function product_id( string $sku ): int {
		if ( '' === $sku || ! function_exists( 'wc_get_product_id_by_sku' ) ) {
			return 0;
		}

		return (int) wc_get_product_id_by_sku( $sku );
	}
}
