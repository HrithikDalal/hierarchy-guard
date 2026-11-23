<?php
/**
 * Yoast SEO's main function, as the compat class calls it.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

if ( ! function_exists( 'YoastSEO' ) ) {
	/**
	 * An object whose `classes` container hands out the stub watcher.
	 *
	 * @return object
	 */
	function YoastSEO(): object { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound,WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- Yoast's own name, stubbed for tests.
		static $main = null;
		if ( null === $main ) {
			$main = (object) [ 'classes' => new HierarchyGuard\Tests\Yoast_Container() ];
		}
		return $main;
	}
}
