<?php
/**
 * CSV files of redirect pairs in a redirect plugin's import layout.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Export;

/**
 * Builds the CSV a detected redirect plugin imports without editing.
 * Redirection and Safe Redirect Manager read source, target, regex and code;
 * every other plugin gets a plain source, target and code layout. The code
 * is the one the built-in server answers with: 302 while a restore from the
 * trash could still undo the move a redirect follows, 301 otherwise.
 */
final class Csv {

	public const LAYOUTS = [
		'redirection' => [ 'source', 'target', 'regex', 'code' ],
		'srm'         => [ 'source', 'target', 'regex', 'code' ],
		'generic'     => [ 'source', 'target', 'code' ],
	];

	/**
	 * A known layout name, falling back to generic.
	 *
	 * @param string $layout Requested layout.
	 * @return string
	 */
	public static function layout( string $layout ): string {
		return isset( self::LAYOUTS[ $layout ] ) ? $layout : 'generic';
	}

	/**
	 * The whole file: a header row, then one row per pair.
	 *
	 * @param string                                                  $layout Layout name.
	 * @param array<int,array{old:string,new:string,temporary?:bool}> $pairs  Old URLs relative to the home URL, new ones relative too or a URL target as set, and whether each is temporary.
	 * @return string
	 */
	public static function build( string $layout, array $pairs ): string {
		$layout = self::layout( $layout );
		$lines  = [ self::line( self::LAYOUTS[ $layout ] ) ];
		foreach ( $pairs as $pair ) {
			$lines[] = self::line( self::row( $layout, (string) $pair['old'], (string) $pair['new'], ! empty( $pair['temporary'] ) ) );
		}
		return implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * One pair's cells in a layout.
	 *
	 * @param string $layout    Layout name.
	 * @param string $old_url   Old URL.
	 * @param string $new_url   New URL.
	 * @param bool   $temporary Whether a restore from the trash could still undo it.
	 * @return string[]
	 */
	public static function row( string $layout, string $old_url, string $new_url, bool $temporary = false ): array {
		$cells = [
			'source' => $old_url,
			'target' => $new_url,
			'regex'  => '0',
			'code'   => $temporary ? '302' : '301',
		];
		return array_map( static fn( string $column ): string => $cells[ $column ], self::LAYOUTS[ self::layout( $layout ) ] );
	}

	/**
	 * One CSV line, quoting a cell only when it needs it (RFC 4180). A cell
	 * that a spreadsheet would read as a formula gets a leading apostrophe.
	 *
	 * @param string[] $cells Cells.
	 * @return string
	 */
	public static function line( array $cells ): string {
		return implode(
			',',
			array_map(
				static function ( string $cell ): string {
					if ( '' !== $cell && in_array( $cell[0], [ '=', '+', '-', '@', "\t", "\r" ], true ) ) {
						$cell = "'" . $cell;
					}
					if ( strpbrk( $cell, ",\"\r\n" ) !== false ) {
						return '"' . str_replace( '"', '""', $cell ) . '"';
					}
					return $cell;
				},
				$cells
			)
		);
	}

	/**
	 * The download's file name.
	 *
	 * @param string $layout Layout name.
	 * @return string
	 */
	public static function filename( string $layout ): string {
		return sprintf( 'hierarchy-guard-redirects-%s-%s.csv', self::layout( $layout ), gmdate( 'Y-m-d' ) );
	}
}
