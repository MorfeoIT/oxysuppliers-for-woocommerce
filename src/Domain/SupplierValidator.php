<?php
/**
 * What makes a supplier valid.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Domain;

/**
 * Checks a form's worth of values and says what is wrong with all of them.
 *
 * Returns codes, not sentences. The domain has no idea what language the shop
 * speaks, and a validator that returns translated text cannot be tested without
 * loading WordPress. The admin layer turns each code into a message.
 */
final class SupplierValidator {

	public const REQUIRED         = 'required';
	public const TOO_LONG         = 'too_long';
	public const INVALID_EMAIL    = 'invalid_email';
	public const INVALID_URL      = 'invalid_url';
	public const INVALID_COUNTRY  = 'invalid_country';
	public const INVALID_CURRENCY = 'invalid_currency';
	public const INVALID_NUMBER   = 'invalid_number';

	/**
	 * Everything wrong with these values, keyed by field.
	 *
	 * @param array<string,mixed> $fields Raw field values.
	 * @return array<string,string> Field name to error code; empty when valid.
	 */
	public function validate( array $fields ): array {
		$errors = array();
		$value  = static fn ( string $name ): string => trim( (string) ( $fields[ $name ] ?? '' ) );

		if ( '' === $value( 'company_name' ) ) {
			$errors['company_name'] = self::REQUIRED;
		}

		foreach ( Supplier::MAX_LENGTHS as $field => $limit ) {
			if ( ! isset( $errors[ $field ] ) && mb_strlen( $value( $field ) ) > $limit ) {
				$errors[ $field ] = self::TOO_LONG;
			}
		}

		foreach ( array( 'order_email', 'billing_email' ) as $field ) {
			$email = $value( $field );

			if ( ! isset( $errors[ $field ] ) && '' !== $email && ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
				$errors[ $field ] = self::INVALID_EMAIL;
			}
		}

		$website = $value( 'website' );

		if ( ! isset( $errors['website'] ) && '' !== $website && ! $this->is_web_url( $website ) ) {
			$errors['website'] = self::INVALID_URL;
		}

		$country = $value( 'country' );

		if ( '' !== $country && 1 !== preg_match( '/^[A-Za-z]{2}$/', $country ) ) {
			$errors['country'] = self::INVALID_COUNTRY;
		}

		if ( ! Currency::is_valid( $value( 'currency' ) ) ) {
			$errors['currency'] = self::INVALID_CURRENCY;
		}

		$lead_time = $value( 'lead_time_days' );

		if ( '' !== $lead_time && 1 !== preg_match( '/^\d+$/', $lead_time ) ) {
			$errors['lead_time_days'] = self::INVALID_NUMBER;
		}

		$minimum = $value( 'min_order_value' );

		if ( '' !== $minimum && 1 !== preg_match( '/^\d+(?:\.\d+)?$/', $minimum ) ) {
			$errors['min_order_value'] = self::INVALID_NUMBER;
		}

		return $errors;
	}

	/**
	 * Whether a string is an http or https address.
	 *
	 * FILTER_VALIDATE_URL alone accepts things like "javascript:" and "file:",
	 * which have no business in a field that ends up in an href.
	 *
	 * @param string $url Candidate address.
	 * @return bool
	 */
	private function is_web_url( string $url ): bool {
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		// wp_parse_url() is the house alternative, but this layer has no
		// WordPress in it by design: that is what makes it testable without one.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Domain layer is framework-free.
		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );

		return in_array( $scheme, array( 'http', 'https' ), true );
	}
}
