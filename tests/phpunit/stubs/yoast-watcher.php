<?php
/**
 * Yoast SEO's post watcher, as the compat class calls it: it records the
 * posts whose indexable it was asked to build.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace Yoast\WP\SEO\Integrations\Watchers; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Yoast's own namespace, stubbed for tests.

/**
 * Records the posts whose indexable it was asked to build.
 */
class Indexable_Post_Watcher {

	/**
	 * Post IDs, in the order asked.
	 *
	 * @var int[]
	 */
	public array $built = [];

	/**
	 * Whether build_indexable() throws, as a changed Yoast might.
	 *
	 * @var bool
	 */
	public bool $broken = false;

	/**
	 * Posts whose build throws an Error, as a page's content filters might.
	 *
	 * @var int[]
	 */
	public array $broken_for = [];

	/**
	 * Records one build.
	 *
	 * @param int $post_id Post ID.
	 * @throws \RuntimeException When broken.
	 * @throws \Error For a post in $broken_for.
	 */
	public function build_indexable( $post_id ): void {
		if ( $this->broken ) {
			throw new \RuntimeException( 'Changed internals.' );
		}
		if ( in_array( (int) $post_id, $this->broken_for, true ) ) {
			throw new \Error( 'A content filter failed.' );
		}
		$this->built[] = (int) $post_id;
	}
}
