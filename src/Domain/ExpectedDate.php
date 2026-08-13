<?php
/**
 * When an order should turn up.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The order date plus the wait.
 *
 * Small enough to look unnecessary, and here for two reasons. It is the same
 * calculation in two screens, and `strtotime()` returns false on a date it does
 * not understand — which `gmdate()` then reads as the first of January 1970,
 * quietly putting a delivery fifty-six years in the past.
 */
final class ExpectedDate {

	/**
	 * The date a given number of days after another.
	 *
	 * @param string $from Starting date, "Y-m-d".
	 * @param int    $days How many days to wait. Negative is treated as none.
	 * @return string The date, or the starting date when it cannot be read.
	 */
	public static function after( string $from, int $days ): string {
		$start = DateTimeImmutable::createFromFormat( '!Y-m-d', $from, new DateTimeZone( 'UTC' ) );

		if ( false === $start ) {
			return $from;
		}

		return $start->modify( '+' . max( 0, $days ) . ' days' )->format( 'Y-m-d' );
	}

	/**
	 * Whether a string is a date this plugin can work with.
	 *
	 * @param string $value Candidate.
	 * @return bool
	 */
	public static function is_valid( string $value ): bool {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return false;
		}

		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new DateTimeZone( 'UTC' ) );

		// The format check passes for the 31st of February; this catches it.
		return false !== $parsed && $parsed->format( 'Y-m-d' ) === $value;
	}
}
