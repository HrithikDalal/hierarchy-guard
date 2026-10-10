<?php
/**
 * Detects installed redirect plugins.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Redirects;

/**
 * Known redirect plugins: a presence check, the URL of their redirects screen,
 * and which CSV layout they import.
 */
final class Providers {

	/**
	 * Every known provider, active or not.
	 *
	 * @return array<string,array{name:string,active:bool,url:string,csv:string}>
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
			],
			'yoast-premium'         => [
				'name'   => 'Yoast SEO Premium',
				'active' => defined( 'WPSEO_PREMIUM_VERSION' ),
				'url'    => admin_url( 'admin.php?page=wpseo_redirects' ),
				'csv'    => 'generic',
			],
			'rank-math'             => [
				'name'   => 'Rank Math',
				'active' => class_exists( 'RankMath\\Helper' ) && is_callable( [ 'RankMath\\Helper', 'is_module_active' ] ) && (bool) call_user_func( [ 'RankMath\\Helper', 'is_module_active' ], 'redirections' ),
				'url'    => admin_url( 'admin.php?page=rank-math-redirections' ),
				'csv'    => 'generic',
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
				'csv'    => 'generic',
			],
		];

		/**
		 * Filters the redirect plugins Hierarchy Guard can detect.
		 *
		 * @param array<string,array{name:string,active:bool,url:string,csv:string}> $providers Keyed by slug.
		 */
		return (array) apply_filters( 'hierarchy_guard_redirect_providers', $providers );
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
