<?php
/**
 * The supplier rules.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Tests\Unit;

use Oxysoft\OxySuppliers\Domain\SupplierValidator;
use PHPUnit\Framework\TestCase;

/**
 * The validator returns codes for every bad field at once, not the first one.
 */
final class SupplierValidatorTest extends TestCase {

	/**
	 * A complete, sensible supplier.
	 *
	 * @param array<string,string> $overrides Values to change.
	 * @return array<string,string>
	 */
	private function valid( array $overrides = array() ): array {
		return array_merge(
			array(
				'company_name'    => 'ABC Srl',
				'trade_name'      => 'ABC',
				'vat_number'      => 'IT01234567890',
				'country'         => 'IT',
				'order_email'     => 'orders@example.test',
				'billing_email'   => 'accounts@example.test',
				'website'         => 'https://example.test',
				'currency'        => 'EUR',
				'lead_time_days'  => '3',
				'min_order_value' => '250.00',
			),
			$overrides
		);
	}

	/**
	 * Nothing wrong, nothing reported.
	 *
	 * @return void
	 */
	public function test_accepts_a_valid_supplier(): void {
		$this->assertSame( array(), ( new SupplierValidator() )->validate( $this->valid() ) );
	}

	/**
	 * Optional fields are optional.
	 *
	 * @return void
	 */
	public function test_accepts_a_supplier_with_only_a_name_and_a_currency(): void {
		$errors = ( new SupplierValidator() )->validate(
			array(
				'company_name' => 'ABC Srl',
				'currency'     => 'EUR',
			)
		);

		$this->assertSame( array(), $errors );
	}

	/**
	 * A supplier without a name is not a supplier.
	 *
	 * @return void
	 */
	public function test_requires_a_company_name(): void {
		$errors = ( new SupplierValidator() )->validate( $this->valid( array( 'company_name' => '   ' ) ) );

		$this->assertSame( SupplierValidator::REQUIRED, $errors['company_name'] );
	}

	/**
	 * Length is checked here rather than left to MySQL, which truncates in
	 * silence outside strict mode.
	 *
	 * @return void
	 */
	public function test_refuses_a_value_longer_than_its_column(): void {
		$errors = ( new SupplierValidator() )->validate(
			$this->valid( array( 'company_name' => str_repeat( 'a', 192 ) ) )
		);

		$this->assertSame( SupplierValidator::TOO_LONG, $errors['company_name'] );
	}

	/**
	 * Both addresses are checked, and both may be empty.
	 *
	 * @return void
	 */
	public function test_checks_email_addresses(): void {
		$validator = new SupplierValidator();

		$errors = $validator->validate( $this->valid( array( 'order_email' => 'not an address' ) ) );
		$this->assertSame( SupplierValidator::INVALID_EMAIL, $errors['order_email'] );

		$errors = $validator->validate( $this->valid( array( 'billing_email' => 'also@wrong@example.test' ) ) );
		$this->assertSame( SupplierValidator::INVALID_EMAIL, $errors['billing_email'] );

		$errors = $validator->validate( $this->valid( array( 'order_email' => '' ) ) );
		$this->assertArrayNotHasKey( 'order_email', $errors );
	}

	/**
	 * A website ends up in an href, so it has to be a web address.
	 *
	 * @return void
	 */
	public function test_refuses_a_website_that_is_not_http(): void {
		$validator = new SupplierValidator();

		foreach ( array( 'javascript:alert(1)', 'file:///etc/passwd', 'example.test' ) as $candidate ) {
			$errors = $validator->validate( $this->valid( array( 'website' => $candidate ) ) );

			$this->assertSame(
				SupplierValidator::INVALID_URL,
				$errors['website'] ?? '',
				'Should have refused: ' . $candidate
			);
		}

		$this->assertArrayNotHasKey(
			'website',
			$validator->validate( $this->valid( array( 'website' => 'http://example.test/path' ) ) )
		);
	}

	/**
	 * A country is two letters, or nothing.
	 *
	 * @return void
	 */
	public function test_checks_the_country_code(): void {
		$validator = new SupplierValidator();

		$this->assertSame(
			SupplierValidator::INVALID_COUNTRY,
			$validator->validate( $this->valid( array( 'country' => 'Italy' ) ) )['country']
		);

		$this->assertArrayNotHasKey( 'country', $validator->validate( $this->valid( array( 'country' => '' ) ) ) );
	}

	/**
	 * A currency is always needed: an amount without one is not an amount.
	 *
	 * @return void
	 */
	public function test_requires_a_currency(): void {
		$validator = new SupplierValidator();

		$this->assertSame(
			SupplierValidator::INVALID_CURRENCY,
			$validator->validate( $this->valid( array( 'currency' => '' ) ) )['currency']
		);

		$this->assertSame(
			SupplierValidator::INVALID_CURRENCY,
			$validator->validate( $this->valid( array( 'currency' => 'EURO' ) ) )['currency']
		);
	}

	/**
	 * Numbers are numbers, and a locale's comma has been dealt with before the
	 * value gets here.
	 *
	 * @return void
	 */
	public function test_checks_the_numbers(): void {
		$validator = new SupplierValidator();

		$this->assertSame(
			SupplierValidator::INVALID_NUMBER,
			$validator->validate( $this->valid( array( 'lead_time_days' => '-3' ) ) )['lead_time_days']
		);

		$this->assertSame(
			SupplierValidator::INVALID_NUMBER,
			$validator->validate( $this->valid( array( 'min_order_value' => '250,00' ) ) )['min_order_value']
		);

		$this->assertArrayNotHasKey(
			'min_order_value',
			$validator->validate( $this->valid( array( 'min_order_value' => '' ) ) )
		);
	}

	/**
	 * Everything wrong at once, so the form can show it all in one go.
	 *
	 * @return void
	 */
	public function test_reports_every_bad_field_together(): void {
		$errors = ( new SupplierValidator() )->validate(
			array(
				'company_name' => '',
				'order_email'  => 'nope',
				'currency'     => '',
				'country'      => 'Italy',
			)
		);

		$this->assertCount( 4, $errors );
	}
}
