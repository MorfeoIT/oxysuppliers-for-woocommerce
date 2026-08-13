<?php
/**
 * Sending a table as a file.
 *
 * @package Oxysoft\OxySuppliers
 */

declare(strict_types=1);

namespace Oxysoft\OxySuppliers\Export;

/**
 * Writes a CSV straight to the browser.
 *
 * Two things it does that a naive version does not, and both come from the same
 * place — the file is opened in a spreadsheet, not by a parser:
 *
 * - a UTF-8 byte order mark, without which Excel reads accented names as
 *   mojibake;
 * - a leading apostrophe on anything that starts with =, +, - or @, because a
 *   spreadsheet treats those as formulas. A supplier called "=cmd" is not a
 *   thing that should be able to run on somebody's machine.
 */
final class CsvWriter {

	/**
	 * Send the file and stop.
	 *
	 * @param string             $basename Name without the extension.
	 * @param list<string>       $columns  Header row.
	 * @param list<list<string>> $rows     The data.
	 * @return void
	 */
	public function send( string $basename, array $columns, array $rows ): void {
		$filename = sanitize_file_name( $basename . '-' . gmdate( 'Y-m-d' ) . '.csv' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		echo "\xEF\xBB\xBF"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Byte order mark, not content.

		/*
		 * WP_Filesystem is the house alternative and has nothing to say here:
		 * this writes to the response, not to a file on disk. There is no path,
		 * no permissions and nothing left behind.
		 */
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$handle = fopen( 'php://output', 'w' );

		if ( false === $handle ) {
			exit;
		}

		fputcsv( $handle, array_map( array( $this, 'defuse' ), $columns ) );

		foreach ( $rows as $row ) {
			fputcsv( $handle, array_map( array( $this, 'defuse' ), $row ) );
		}

		fclose( $handle );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		exit;
	}

	/**
	 * Stop a spreadsheet reading a value as a formula.
	 *
	 * @param string $value One cell.
	 * @return string
	 */
	private function defuse( string $value ): string {
		if ( '' === $value ) {
			return $value;
		}

		return in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true )
			? "'" . $value
			: $value;
	}
}
