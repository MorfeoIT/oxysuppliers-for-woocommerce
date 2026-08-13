<?php
/**
 * Reading a supplier price list out of a CSV file.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Import;

/**
 * Turns a file somebody exported from a spreadsheet into rows this plugin
 * understands, and says what is wrong with each one.
 *
 * No WordPress in here, so every rule below can be tested without a database.
 * It is also the layer that has to be forgiving: the file comes from a supplier
 * or from Excel, and it will have a byte order mark, semicolons instead of
 * commas, comma decimals, headers in Italian, and empty rows at the bottom.
 * Refusing all of that would be correct and useless.
 */
final class SupplierCsvParser {

	/**
	 * The columns, and what a file might call them.
	 *
	 * @var array<string,list<string>>
	 */
	private const COLUMNS = array(
		'company_name'   => array( 'company', 'company name', 'supplier', 'ragione sociale', 'fornitore' ),
		'vat_number'     => array( 'vat', 'vat number', 'partita iva', 'p.iva', 'piva' ),
		'order_email'    => array( 'email', 'e-mail', 'orders email', 'email ordini' ),
		'supplier_sku'   => array( 'supplier code', 'supplier sku', 'their code', 'codice fornitore', 'codice' ),
		'sku'            => array( 'sku', 'our sku', 'codice articolo', 'codice interno' ),
		'unit_cost'      => array( 'cost', 'price', 'unit cost', 'costo', 'prezzo' ),
		'min_order_qty'  => array( 'moq', 'minimum', 'min order', 'minimo' ),
		'order_multiple' => array( 'multiple', 'order multiple', 'multiplo' ),
		'pack_qty'       => array( 'pack', 'pack qty', 'confezione' ),
		'lead_time_days' => array( 'lead time', 'lead time days', 'lead', 'giorni', 'tempi di consegna' ),
	);

	/**
	 * Read a file.
	 *
	 * @param string $contents The whole file.
	 * @return ParsedCsv
	 */
	public function parse( string $contents ): ParsedCsv {
		// Excel writes one, and it turns the first header into something that
		// matches nothing.
		$contents = preg_replace( '/^\xEF\xBB\xBF/', '', $contents ) ?? $contents;

		$lines = preg_split( '/\r\n|\r|\n/', $contents );

		if ( ! is_array( $lines ) || array() === $lines ) {
			return new ParsedCsv( array(), array(), array( __( 'The file is empty.', 'oxysuppliers-for-woocommerce' ) ) );
		}

		$separator = $this->separator( (string) $lines[0] );

		// str_getcsv gives null for an empty field, which is a different thing
		// from an empty heading only to a type checker.
		$header = array_map(
			static fn ( $value ): string => (string) $value,
			str_getcsv( (string) array_shift( $lines ), $separator )
		);

		$map = $this->map_columns( $header );

		$missing = array();

		foreach ( array( 'company_name', 'sku' ) as $required ) {
			if ( ! isset( $map[ $required ] ) ) {
				$missing[] = $required;
			}
		}

		if ( array() !== $missing ) {
			return new ParsedCsv(
				$header,
				array(),
				array(
					sprintf(
						/* translators: %s: list of column names. */
						__( 'The file needs at least a supplier column and an SKU column. Missing: %s.', 'oxysuppliers-for-woocommerce' ),
						implode( ', ', $missing )
					),
				)
			);
		}

		$rows = array();

		foreach ( $lines as $number => $line ) {
			if ( '' === trim( (string) $line ) ) {
				continue;
			}

			$values = str_getcsv( (string) $line, $separator );
			$row    = array( 'line' => $number + 2 );

			foreach ( $map as $field => $index ) {
				$row[ $field ] = isset( $values[ $index ] ) ? trim( (string) $values[ $index ] ) : '';
			}

			$row['errors'] = $this->problems( $row );

			$rows[] = $row;
		}

		if ( array() === $rows ) {
			return new ParsedCsv( $header, array(), array( __( 'The file has a heading row and nothing else.', 'oxysuppliers-for-woocommerce' ) ) );
		}

		return new ParsedCsv( $header, $rows, array() );
	}

	/**
	 * Which character separates the fields.
	 *
	 * A spreadsheet saved in a country that uses the comma as a decimal
	 * separator writes semicolons, and it does not say so anywhere.
	 *
	 * @param string $header The heading row.
	 * @return string
	 */
	private function separator( string $header ): string {
		$semicolons = substr_count( $header, ';' );
		$commas     = substr_count( $header, ',' );
		$tabs       = substr_count( $header, "\t" );

		if ( $tabs > $semicolons && $tabs > $commas ) {
			return "\t";
		}

		return $semicolons > $commas ? ';' : ',';
	}

	/**
	 * Work out which column is which.
	 *
	 * @param list<string> $header The heading row.
	 * @return array<string,int>
	 */
	private function map_columns( array $header ): array {
		$map = array();

		foreach ( $header as $index => $label ) {
			$normalised = strtolower( trim( (string) $label ) );

			foreach ( self::COLUMNS as $field => $names ) {
				if ( ! isset( $map[ $field ] ) && in_array( $normalised, $names, true ) ) {
					$map[ $field ] = (int) $index;
				}
			}
		}

		return $map;
	}

	/**
	 * What is wrong with one row.
	 *
	 * @param array<string,mixed> $row The row.
	 * @return list<string>
	 */
	private function problems( array $row ): array {
		$problems = array();

		if ( '' === (string) ( $row['company_name'] ?? '' ) ) {
			$problems[] = __( 'no supplier', 'oxysuppliers-for-woocommerce' );
		}

		if ( '' === (string) ( $row['sku'] ?? '' ) ) {
			$problems[] = __( 'no SKU', 'oxysuppliers-for-woocommerce' );
		}

		$cost = self::number( (string) ( $row['unit_cost'] ?? '' ) );

		if ( '' !== (string) ( $row['unit_cost'] ?? '' ) && null === $cost ) {
			$problems[] = __( 'the cost is not a number', 'oxysuppliers-for-woocommerce' );
		}

		foreach ( array( 'min_order_qty', 'order_multiple', 'pack_qty', 'lead_time_days' ) as $field ) {
			$value = (string) ( $row[ $field ] ?? '' );

			if ( '' !== $value && 1 !== preg_match( '/^\d+$/', $value ) ) {
				$problems[] = sprintf(
					/* translators: %s: column name. */
					__( '%s is not a whole number', 'oxysuppliers-for-woocommerce' ),
					$field
				);
			}
		}

		$email = (string) ( $row['order_email'] ?? '' );

		if ( '' !== $email && ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			$problems[] = __( 'the email address is not one', 'oxysuppliers-for-woocommerce' );
		}

		return $problems;
	}

	/**
	 * A number written the way a spreadsheet writes it.
	 *
	 * Accepts "11,80" and "1.234,56" as well as "11.80": the file comes from
	 * somebody else's computer, set to somebody else's country.
	 *
	 * @param string $value As written.
	 * @return string|null Canonical decimal, or null when it is not a number.
	 */
	public static function number( string $value ): ?string {
		$value = trim( $value );

		if ( '' === $value ) {
			return null;
		}

		$comma = strrpos( $value, ',' );
		$dot   = strrpos( $value, '.' );

		if ( false !== $comma && ( false === $dot || $comma > $dot ) ) {
			// The comma is the decimal separator, so the dots are thousands.
			$value = str_replace( '.', '', $value );
			$value = str_replace( ',', '.', $value );
		} else {
			// The dot is the decimal separator, so the commas are thousands.
			$value = str_replace( ',', '', $value );
		}

		return 1 === preg_match( '/^\d+(?:\.\d+)?$/', $value ) ? $value : null;
	}
}
