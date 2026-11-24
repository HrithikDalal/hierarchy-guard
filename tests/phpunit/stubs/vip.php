<?php
/**
 * WordPress VIP's edge cache purge, under its current name and the one it
 * deprecated, as the ledger calls them: each records the URLs it was asked
 * to purge.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- VIP's own names, stubbed for tests.

if ( ! function_exists( 'wpvip_purge_edge_cache_for_url' ) ) {
	/**
	 * Records one URL to purge.
	 *
	 * @param string $url URL.
	 */
	function wpvip_purge_edge_cache_for_url( $url ): void {
		$GLOBALS['hierarchy_guard_purged'][] = (string) $url;
	}

	/**
	 * Records one URL asked for under the deprecated name.
	 *
	 * @param string $url URL.
	 */
	function wpcom_vip_purge_edge_cache_for_url( $url ): void {
		$GLOBALS['hierarchy_guard_purged_deprecated'][] = (string) $url;
	}
}
