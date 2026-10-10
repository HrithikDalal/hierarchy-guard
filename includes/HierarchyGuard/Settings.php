<?php
/**
 * Plugin settings.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard;

/**
 * Reads, defaults and sanitizes the single settings option.
 */
final class Settings {

	public const OPTION = 'hierarchy_guard_settings';

	public const POLICY_REPARENT       = 'reparent';
	public const POLICY_TRASH_CHILDREN = 'trash_children';
	public const POLICY_KEEP           = 'keep';

	public const POLICIES = [
		self::POLICY_REPARENT,
		self::POLICY_TRASH_CHILDREN,
		self::POLICY_KEEP,
	];

	/**
	 * Default values. post_types null means "every public hierarchical type".
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return [
			'post_types'      => null,
			'trash_policy'    => self::POLICY_REPARENT,
			'serve_redirects' => false,
		];
	}

	/**
	 * Stored values merged over the defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( string $key ) {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Whether the built-in redirect server is on. Reads the strings "false" and "0" as off.
	 */
	public static function serve_redirects(): bool {
		return rest_sanitize_boolean( self::get( 'serve_redirects' ) );
	}

	/**
	 * The policy used when no dialog could ask the editor.
	 */
	public static function trash_policy(): string {
		$policy = (string) self::get( 'trash_policy' );

		/**
		 * Filters the default trash policy.
		 *
		 * @param string $policy One of Settings::POLICIES.
		 */
		$policy = (string) apply_filters( 'hierarchy_guard_default_trash_policy', $policy );

		return in_array( $policy, self::POLICIES, true ) ? $policy : self::POLICY_REPARENT;
	}

	/**
	 * Sanitize callback for register_setting().
	 *
	 * @param mixed $input Raw option value.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $input ): array {
		$input  = is_array( $input ) ? $input : [];
		$public = array_values(
			get_post_types(
				[
					'public'       => true,
					'hierarchical' => true,
				],
				'names'
			)
		);

		// Absent or null keeps null: every public hierarchical type. The form always
		// submits the key through a hidden input, so "none checked" arrives as [ '' ].
		$types = null;
		if ( isset( $input['post_types'] ) ) {
			$types = array_map( 'sanitize_key', (array) $input['post_types'] );
			$types = array_filter( $types, static fn( string $type ): bool => '' !== $type );
			$types = array_values( array_intersect( $types, $public ) );
		}

		$policy = isset( $input['trash_policy'] ) ? sanitize_key( (string) $input['trash_policy'] ) : self::POLICY_REPARENT;
		if ( ! in_array( $policy, self::POLICIES, true ) ) {
			$policy = self::POLICY_REPARENT;
		}

		return [
			'post_types'      => $types,
			'trash_policy'    => $policy,
			'serve_redirects' => rest_sanitize_boolean( $input['serve_redirects'] ?? false ),
		];
	}
}
