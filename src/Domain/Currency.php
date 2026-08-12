<?php
/**
 * Currency codes and how many decimals they have.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Domain;

/*
 * A developer-facing exception message in a layer with no WordPress in it:
 * esc_html() does not exist here, and nothing here is ever echoed. Said in the
 * code rather than in phpcs.xml.dist, because Plugin Check ignores that file.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * The little that this plugin needs to know about currencies.
 *
 * Not a currency table: only the code, and how many minor units make a major
 * one. That second thing matters because a yen has none, and a plugin that
 * assumes two would move every Japanese price by a factor of a hundred.
 */
final class Currency {

	/**
	 * Currencies whose minor unit is not one hundredth.
	 *
	 * ISO 4217 exponents. Anything not listed here has two decimals, which is
	 * true of every currency this plugin is likely to meet.
	 *
	 * @var array<string,int>
	 */
	private const EXPONENTS = array(
		'BIF' => 0,
		'CLP' => 0,
		'DJF' => 0,
		'GNF' => 0,
		'ISK' => 0,
		'JPY' => 0,
		'KMF' => 0,
		'KRW' => 0,
		'PYG' => 0,
		'RWF' => 0,
		'UGX' => 0,
		'UYI' => 0,
		'VND' => 0,
		'VUV' => 0,
		'XAF' => 0,
		'XOF' => 0,
		'XPF' => 0,
		'BHD' => 3,
		'IQD' => 3,
		'JOD' => 3,
		'KWD' => 3,
		'LYD' => 3,
		'OMR' => 3,
		'TND' => 3,
	);

	/**
	 * The default number of decimals.
	 */
	private const DEFAULT_EXPONENT = 2;

	/**
	 * Uppercase a code and check it looks like one.
	 *
	 * @param string $code Currency code.
	 * @return string
	 * @throws InvalidArgument When the code is not three letters.
	 */
	public static function normalise( string $code ): string {
		$normalised = strtoupper( trim( $code ) );

		if ( 1 !== preg_match( '/^[A-Z]{3}$/', $normalised ) ) {
			throw new InvalidArgument( 'A currency code is three letters, got: ' . $code );
		}

		return $normalised;
	}

	/**
	 * Whether a code looks like a currency code.
	 *
	 * @param string $code Currency code.
	 * @return bool
	 */
	public static function is_valid( string $code ): bool {
		return 1 === preg_match( '/^[A-Za-z]{3}$/', trim( $code ) );
	}

	/**
	 * How many decimal places this currency has.
	 *
	 * @param string $code Currency code, already normalised.
	 * @return int
	 */
	public static function exponent( string $code ): int {
		return self::EXPONENTS[ self::normalise( $code ) ] ?? self::DEFAULT_EXPONENT;
	}
}
