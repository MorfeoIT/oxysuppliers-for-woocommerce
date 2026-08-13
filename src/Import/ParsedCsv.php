<?php
/**
 * What came out of a CSV file.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Import;

/**
 * The rows, and everything wrong with them.
 *
 * Rows that cannot be used are kept rather than dropped: somebody looking at the
 * preview needs to see that line 47 was skipped and why, or they will believe
 * the import did more than it did.
 */
final class ParsedCsv {

	/**
	 * Hold the result.
	 *
	 * @param list<string>              $header   The heading row as it was written.
	 * @param list<array<string,mixed>> $rows     The rows, each with its own errors.
	 * @param list<string>              $problems What is wrong with the file itself.
	 */
	public function __construct(
		public readonly array $header,
		public readonly array $rows,
		public readonly array $problems
	) {
	}

	/**
	 * Whether the file can be used at all.
	 *
	 * @return bool
	 */
	public function is_usable(): bool {
		return array() === $this->problems && array() !== $this->rows;
	}

	/**
	 * The rows that can be imported.
	 *
	 * @return list<array<string,mixed>>
	 */
	public function good_rows(): array {
		return array_values(
			array_filter(
				$this->rows,
				static fn ( array $row ): bool => array() === ( $row['errors'] ?? array() )
			)
		);
	}

	/**
	 * How many rows will be skipped, and why they are still shown.
	 *
	 * @return int
	 */
	public function skipped(): int {
		return count( $this->rows ) - count( $this->good_rows() );
	}
}
