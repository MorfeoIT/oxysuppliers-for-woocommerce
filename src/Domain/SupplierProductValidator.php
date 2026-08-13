<?php
/**
 * What makes a price list line valid.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Domain;

/**
 * Checks one line of a supplier's price list.
 *
 * Codes rather than sentences, for the same reason as the supplier validator:
 * the domain does not know what language the shop speaks.
 */
final class SupplierProductValidator {

	public const REQUIRED         = 'required';
	public const TOO_LONG         = 'too_long';
	public const INVALID_NUMBER   = 'invalid_number';
	public const INVALID_CURRENCY = 'invalid_currency';

	/**
	 * Everything wrong with these values, keyed by field.
	 *
	 * @param array<string,mixed> $fields Raw field values.
	 * @return array<string,string> Field name to error code; empty when valid.
	 */
	public function validate( array $fields ): array {
		$errors = array();
		$value  = static fn ( string $name ): string => trim( (string) ( $fields[ $name ] ?? '' ) );

		if ( (int) ( $fields['supplier_id'] ?? 0 ) <= 0 ) {
			$errors['supplier_id'] = self::REQUIRED;
		}

		if ( (int) ( $fields['product_id'] ?? 0 ) <= 0 ) {
			$errors['product_id'] = self::REQUIRED;
		}

		foreach ( SupplierProduct::MAX_LENGTHS as $field => $limit ) {
			if ( mb_strlen( $value( $field ) ) > $limit ) {
				$errors[ $field ] = self::TOO_LONG;
			}
		}

		if ( ! Currency::is_valid( $value( 'currency' ) ) ) {
			$errors['currency'] = self::INVALID_CURRENCY;
		}

		$cost = $value( 'unit_cost' );

		if ( '' !== $cost && 1 !== preg_match( '/^\d+(?:\.\d+)?$/', $cost ) ) {
			$errors['unit_cost'] = self::INVALID_NUMBER;
		}

		// Whole numbers, and never negative: half a pack is not a pack, and a
		// minimum of minus five is not a minimum.
		foreach ( array( 'min_order_qty', 'order_multiple', 'pack_qty', 'lead_time_days' ) as $field ) {
			$number = $value( $field );

			if ( '' !== $number && 1 !== preg_match( '/^\d+$/', $number ) ) {
				$errors[ $field ] = self::INVALID_NUMBER;
			}
		}

		return $errors;
	}
}
