<?php
/**
 * WordPress VIP's edge cache purge under the name it deprecated only, as an
 * older VIP platform has it: records the URLs it was asked to purge.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- VIP's own name, stubbed for tests.

if ( ! function_exists( 'wpcom_vip_purge_edge_cache_for_url' ) ) {
	/**
	 * Records one URL asked for under the deprecated name.
	 *
	 * @param string $url URL.
	 */
	function wpcom_vip_purge_edge_cache_for_url( $url ): void {
		$GLOBALS['hierarchy_guard_purged_deprecated'][] = (string) $url;
	}
}
