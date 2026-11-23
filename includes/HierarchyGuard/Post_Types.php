<?php
/**
 * Supported post types.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The hierarchical post types the plugin acts on. Memoised per request.
 */
final class Post_Types {

	/**
	 * Memoised list.
	 *
	 * @var string[]|null
	 */
	private static ?array $cache = null;

	/**
	 * Supported post type names.
	 *
	 * @return string[]
	 */
	public static function supported(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$public     = array_values(
			get_post_types(
				[
					'public'       => true,
					'hierarchical' => true,
				],
				'names'
			)
		);
		$configured = Settings::get( 'post_types' );
		$types      = null === $configured ? $public : array_values( array_intersect( (array) $configured, $public ) );

		/**
		 * Filters the post types Hierarchy Guard acts on.
		 *
		 * @param string[] $types Post type names.
		 */
		$types = (array) apply_filters( 'hierarchy_guard_post_types', $types );

		self::$cache = array_values( array_unique( array_filter( array_map( 'strval', $types ) ) ) );

		return self::$cache;
	}

	/**
	 * Whether a post type is supported.
	 *
	 * @param string $type Post type name.
	 * @return bool
	 */
	public static function is_supported( string $type ): bool {
		return in_array( $type, self::supported(), true );
	}

	/**
	 * One of a post type's own labels, as it registered them, such as
	 * all_items or parent_item_colon, or the fallback when it has none.
	 *
	 * @param string $type     Post type name.
	 * @param string $label    Label key.
	 * @param string $fallback Label for a type without one.
	 * @return string
	 */
	public static function label( string $type, string $label, string $fallback ): string {
		$object = get_post_type_object( $type );
		$value  = null !== $object && isset( $object->labels->{$label} ) ? (string) $object->labels->{$label} : '';
		return '' !== $value ? $value : $fallback;
	}

	/**
	 * Clears the memoised list. Hooked to post type registration and settings saves.
	 */
	public static function reset(): void {
		self::$cache = null;
	}
}
