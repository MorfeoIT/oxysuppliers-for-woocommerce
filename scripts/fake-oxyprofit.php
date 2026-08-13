<?php
// The bare minimum of OxyProfit, so the bench can answer the question the CI
// cannot: what happens on a site where OxyProfit *is* installed.
//
// Only what our cost source touches. Installing the real plugin here would test
// their code as much as ours, and the seam is the whole point: if this is
// enough for the integration to work, the integration really does depend on
// nothing more than the published interface.

namespace Oxysoft\OxyProfit\Domain;

final class Money {

	private function __construct( public readonly int $minor, public readonly string $currency ) {
	}

	public static function from_minor( int $minor, string $currency ): self {
		return new self( $minor, strtoupper( $currency ) );
	}
}

namespace Oxysoft\OxyProfit\Engine;

final class CostQuery {

	public function __construct(
		public readonly int $product_id,
		public readonly int $variation_id,
		public readonly string $date,
		public readonly string $currency
	) {
	}

	public function is_variation(): bool {
		return $this->variation_id > 0;
	}
}

interface CostSource {

	public function name(): string;

	public function unit_cost( CostQuery $query ): ?\Oxysoft\OxyProfit\Domain\Money;
}
