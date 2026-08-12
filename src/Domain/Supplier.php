<?php
/**
 * A supplier.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Domain;

/**
 * Who we buy from.
 *
 * A plain record with the rules that make it a valid one. It knows nothing
 * about WordPress, which is what lets every rule in here be tested without a
 * database.
 */
final class Supplier {

	/**
	 * How long each text field may be, matching the columns that hold them.
	 *
	 * Checked here rather than left to MySQL: outside strict mode MySQL
	 * truncates quietly, and a supplier whose name lost its last twenty
	 * characters looks like a typing mistake rather than a bug.
	 *
	 * @var array<string,int>
	 */
	public const MAX_LENGTHS = array(
		'company_name'  => 191,
		'trade_name'    => 191,
		'vat_number'    => 32,
		'tax_code'      => 32,
		'address'       => 191,
		'postcode'      => 20,
		'city'          => 100,
		'state'         => 100,
		'order_email'   => 191,
		'billing_email' => 191,
		'phone'         => 40,
		'contact_name'  => 191,
		'website'       => 191,
		'payment_terms' => 191,
		'notes'         => 65535,
	);

	/**
	 * Hold a supplier.
	 *
	 * @param int            $id             Row id, 0 when not yet stored.
	 * @param string         $company_name   Registered name.
	 * @param string         $trade_name     Trading name.
	 * @param string         $vat_number     VAT number.
	 * @param string         $tax_code       Tax code.
	 * @param string         $address        Street address.
	 * @param string         $postcode       Postcode.
	 * @param string         $city           City.
	 * @param string         $state          Province or state.
	 * @param string         $country        Two-letter country code, or empty.
	 * @param string         $order_email    Where purchase orders are sent.
	 * @param string         $billing_email  Where invoices come from.
	 * @param string         $phone          Phone number.
	 * @param string         $contact_name   Person to ask for.
	 * @param string         $website        Website.
	 * @param string         $payment_terms  Payment terms, free text in the free plugin.
	 * @param int            $lead_time_days Usual days between order and delivery.
	 * @param Money          $min_order_value Minimum order value.
	 * @param string         $notes          Internal notes.
	 * @param SupplierStatus $status         Whether we still buy from them.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $company_name,
		public readonly string $trade_name,
		public readonly string $vat_number,
		public readonly string $tax_code,
		public readonly string $address,
		public readonly string $postcode,
		public readonly string $city,
		public readonly string $state,
		public readonly string $country,
		public readonly string $order_email,
		public readonly string $billing_email,
		public readonly string $phone,
		public readonly string $contact_name,
		public readonly string $website,
		public readonly string $payment_terms,
		public readonly int $lead_time_days,
		public readonly Money $min_order_value,
		public readonly string $notes,
		public readonly SupplierStatus $status
	) {
	}

	/**
	 * The currency this supplier prices in.
	 *
	 * It lives on the minimum order value because an amount without a currency
	 * is not an amount, and there is no second currency to disagree with it.
	 *
	 * @return string
	 */
	public function currency(): string {
		return $this->min_order_value->currency;
	}

	/**
	 * The name to show, which is the trading name when there is one.
	 *
	 * @return string
	 */
	public function display_name(): string {
		return '' !== $this->trade_name ? $this->trade_name : $this->company_name;
	}

	/**
	 * Whether we still buy from them.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return SupplierStatus::ACTIVE === $this->status;
	}

	/**
	 * The same supplier with an id, as it comes back from an insert.
	 *
	 * @param int $id Row id.
	 * @return self
	 */
	public function with_id( int $id ): self {
		return new self(
			$id,
			$this->company_name,
			$this->trade_name,
			$this->vat_number,
			$this->tax_code,
			$this->address,
			$this->postcode,
			$this->city,
			$this->state,
			$this->country,
			$this->order_email,
			$this->billing_email,
			$this->phone,
			$this->contact_name,
			$this->website,
			$this->payment_terms,
			$this->lead_time_days,
			$this->min_order_value,
			$this->notes,
			$this->status
		);
	}

	/**
	 * Build from validated field values.
	 *
	 * The values are expected to have passed SupplierValidator: this is the
	 * assembly step, not a second gate.
	 *
	 * @param array<string,mixed> $fields Field values keyed by name.
	 * @param int                 $id     Row id, 0 for a new supplier.
	 * @return self
	 * @throws InvalidArgument When the currency or amount cannot be represented.
	 */
	public static function from_fields( array $fields, int $id = 0 ): self {
		$currency = Currency::normalise( (string) ( $fields['currency'] ?? 'EUR' ) );

		return new self(
			$id,
			trim( (string) ( $fields['company_name'] ?? '' ) ),
			trim( (string) ( $fields['trade_name'] ?? '' ) ),
			trim( (string) ( $fields['vat_number'] ?? '' ) ),
			trim( (string) ( $fields['tax_code'] ?? '' ) ),
			trim( (string) ( $fields['address'] ?? '' ) ),
			trim( (string) ( $fields['postcode'] ?? '' ) ),
			trim( (string) ( $fields['city'] ?? '' ) ),
			trim( (string) ( $fields['state'] ?? '' ) ),
			strtoupper( trim( (string) ( $fields['country'] ?? '' ) ) ),
			trim( (string) ( $fields['order_email'] ?? '' ) ),
			trim( (string) ( $fields['billing_email'] ?? '' ) ),
			trim( (string) ( $fields['phone'] ?? '' ) ),
			trim( (string) ( $fields['contact_name'] ?? '' ) ),
			trim( (string) ( $fields['website'] ?? '' ) ),
			trim( (string) ( $fields['payment_terms'] ?? '' ) ),
			max( 0, (int) ( $fields['lead_time_days'] ?? 0 ) ),
			Money::from_decimal( '' === trim( (string) ( $fields['min_order_value'] ?? '' ) ) ? '0' : trim( (string) $fields['min_order_value'] ), $currency ),
			trim( (string) ( $fields['notes'] ?? '' ) ),
			SupplierStatus::from_storage( (string) ( $fields['status'] ?? SupplierStatus::ACTIVE->value ) )
		);
	}
}
