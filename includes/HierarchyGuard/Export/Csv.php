<?php
/**
 * CSV files of redirect pairs in a redirect plugin's import layout.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Export;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the CSV a detected redirect plugin imports. Each layout maps a header
 * to what its cells hold: the old URL, the new one, the regex flag, the code
 * or Yoast's format. A plugin whose import format isn't documented gets the
 * plain generic layout. The code is the one the built-in server answers
 * with: 302 while a restore from the trash could still undo the move a
 * redirect follows, 301 otherwise.
 */
final class Csv {

	public const LAYOUTS = [
		'redirection' => [
			'source' => 'old',
			'target' => 'new',
			'regex'  => 'regex',
			'code'   => 'code',
		],
		'srm'         => [
			'source' => 'old',
			'target' => 'new',
			'regex'  => 'regex',
			'code'   => 'code',
		],
		'yoast'       => [
			'Origin' => 'old',
			'Target' => 'new',
			'Type'   => 'code',
			'Format' => 'format',
		],
		'rank-math'   => [
			'source'      => 'old',
			'destination' => 'new',
			'type'        => 'code',
		],
		'simple-301'  => [
			'request'     => 'old',
			'destination' => 'new',
		],
		'generic'     => [
			'source' => 'old',
			'target' => 'new',
			'code'   => 'code',
		],
	];

	/**
	 * Layouts whose importer refuses Windows line endings.
	 */
	private const UNIX_LINES = [ 'yoast' ];

	/**
	 * Layouts whose plugin matches the whole request path, so their paths
	 * carry the site's home path: /blog/about/ on a site at /blog/.
	 */
	private const FROM_ROOT = [ 'redirection' ];

	/**
	 * Layouts whose plugin strips the home path from the request but sends a
	 * path target from the domain root, so their targets are full URLs.
	 */
	private const FULL_TARGETS = [ 'srm' ];

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
	 * The whole file: a header row, then one row per pair. A protocol-relative
	 * target gets the home URL's scheme, since Yoast SEO Premium and Simple
	 * 301 Redirects would read it as a path.
	 *
	 * @param string                                                  $layout Layout name.
	 * @param array<int,array{old:string,new:string,temporary?:bool}> $pairs  Old URLs relative to the home URL, new ones relative too or a URL target as set, and whether each is temporary.
	 * @return string
	 */
	public static function build( string $layout, array $pairs ): string {
		$layout = self::layout( $layout );
		// The home option's scheme, not the request's: home_url() follows is_ssl(), as in an admin forced to SSL.
		$scheme = (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_SCHEME );
		$home   = home_url( '/', $scheme );
		$base   = in_array( $layout, self::FROM_ROOT, true ) ? untrailingslashit( (string) wp_parse_url( $home, PHP_URL_PATH ) ) : '';
		$full   = in_array( $layout, self::FULL_TARGETS, true );
		$lines  = [ self::line( array_keys( self::LAYOUTS[ $layout ] ) ) ];
		foreach ( $pairs as $pair ) {
			$old = $base . (string) $pair['old'];
			$new = (string) $pair['new'];
			if ( str_starts_with( $new, '//' ) ) {
				$new = $scheme . ':' . $new;
			} elseif ( str_starts_with( $new, '/' ) ) {
				$new = $full ? home_url( $new, $scheme ) : $base . $new;
			}
			$lines[] = self::line( self::row( $layout, $old, $new, ! empty( $pair['temporary'] ) ) );
		}
		$end = in_array( $layout, self::UNIX_LINES, true ) ? "\n" : "\r\n";
		return implode( $end, $lines ) . $end;
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
			'old'    => $old_url,
			'new'    => $new_url,
			'regex'  => '0',
			'code'   => $temporary ? '302' : '301',
			'format' => 'plain',
		];
		return array_values( array_map( static fn( string $field ): string => $cells[ $field ], self::LAYOUTS[ self::layout( $layout ) ] ) );
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
