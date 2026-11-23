<?php
/**
 * Detects installed redirect plugins.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Redirects;

use HierarchyGuard\Export\Csv;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Known redirect plugins: a presence check, the URL of their redirects screen,
 * which CSV layout they import, and what to know before importing it.
 */
final class Providers {

	/**
	 * Every known provider, active or not. A provider with the generic layout
	 * has no documented import format, so its download isn't named after it.
	 *
	 * @return array<string,array{name:string,active:bool,url:string,csv:string,note?:string}>
	 */
	public function all(): array {
		$providers = [
			'redirection'           => [
				'name'   => 'Redirection',
				'active' => defined( 'REDIRECTION_VERSION' ),
				'url'    => admin_url( 'tools.php?page=redirection.php' ),
				'csv'    => 'redirection',
			],
			'safe-redirect-manager' => [
				'name'   => 'Safe Redirect Manager',
				'active' => function_exists( 'srm_create_redirect' ),
				'url'    => admin_url( 'edit.php?post_type=redirect_rule' ),
				'csv'    => 'srm',
				/* translators: %s: a WP-CLI command, which stays as it is. */
				'note'   => sprintf( __( 'Safe Redirect Manager imports a CSV only from WP-CLI: %s', 'hierarchy-guard' ), 'wp safe-redirect-manager import <file>' ),
			],
			'yoast-premium'         => [
				'name'   => 'Yoast SEO Premium',
				'active' => defined( 'WPSEO_PREMIUM_VERSION' ),
				'url'    => admin_url( 'admin.php?page=wpseo_redirects' ),
				'csv'    => 'yoast',
			],
			'rank-math'             => [
				'name'   => 'Rank Math',
				'active' => class_exists( 'RankMath\\Helper' ) && is_callable( [ 'RankMath\\Helper', 'is_module_active' ] ) && (bool) call_user_func( [ 'RankMath\\Helper', 'is_module_active' ], 'redirections' ),
				'url'    => admin_url( 'admin.php?page=rank-math-redirections' ),
				'csv'    => 'rank-math',
				'note'   => __( 'Rank Math imports a CSV only in its PRO version.', 'hierarchy-guard' ),
			],
			'aioseo'                => [
				'name'   => 'All in One SEO',
				'active' => class_exists( 'AIOSEO\\Plugin\\Addon\\Redirects\\Redirects' ),
				'url'    => admin_url( 'admin.php?page=aioseo-redirects' ),
				'csv'    => 'generic',
			],
			'seopress-pro'          => [
				'name'   => 'SEOPress PRO',
				'active' => defined( 'SEOPRESS_PRO_VERSION' ),
				'url'    => admin_url( 'edit.php?post_type=seopress_404' ),
				'csv'    => 'generic',
			],
			'simple-301'            => [
				'name'   => 'Simple 301 Redirects',
				'active' => class_exists( 'Simple301Redirects' ),
				'url'    => admin_url( 'options-general.php?page=301options' ),
				'csv'    => 'simple-301',
				'note'   => __( 'Simple 301 Redirects imports every row as a 301.', 'hierarchy-guard' ),
			],
		];

		/**
		 * Filters the redirect plugins Hierarchy Guard can detect.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string,array{name:string,active:bool,url:string,csv:string,note?:string}> $providers Keyed by slug: the plugin's name, whether it is active, its redirects screen, one of the Csv::LAYOUTS names, and an optional sentence shown before importing.
		 */
		return (array) apply_filters( 'hierarchy_guard_redirect_providers', $providers );
	}

	/**
	 * What the scripts and the hierarchy route say about a detected provider.
	 *
	 * @param array<string,mixed>|null $provider From detect().
	 * @return array{name:string,url:string,csv:string,note:string}|null
	 */
	public static function described( ?array $provider ): ?array {
		if ( null === $provider ) {
			return null;
		}
		return [
			'name' => (string) ( $provider['name'] ?? '' ),
			'url'  => esc_url_raw( (string) ( $provider['url'] ?? '' ) ),
			'csv'  => Csv::layout( (string) ( $provider['csv'] ?? 'generic' ) ),
			'note' => is_string( $provider['note'] ?? null ) ? $provider['note'] : '',
		];
	}

	/**
	 * The first active provider, with its slug, or null.
	 *
	 * @return array{slug:string,name:string,active:bool,url:string,csv:string}|null
	 */
	public function detect(): ?array {
		foreach ( $this->all() as $slug => $provider ) {
			if ( is_array( $provider ) && ! empty( $provider['active'] ) ) {
				return [ 'slug' => (string) $slug ] + $provider;
			}
		}
		return null;
	}
}
