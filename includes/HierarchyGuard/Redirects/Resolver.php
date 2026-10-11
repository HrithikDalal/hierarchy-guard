<?php
/**
 * Resolves an old path to the post it now leads to.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Redirects;

use HierarchyGuard\Ledger;
use HierarchyGuard\Paths;
use HierarchyGuard\Trash\Markers;
use WP_Post;

/**
 * Resolves an old path through the shared rules, on the live ledger.
 */
final class Resolver {

	public const MAX_ROUNDS = Rules::MAX_ROUNDS;

	/**
	 * The ledger and core, read live.
	 *
	 * @var Live_View
	 */
	private Live_View $view;

	/**
	 * Constructor.
	 *
	 * @param Ledger $ledger Ledger.
	 */
	public function __construct( Ledger $ledger ) {
		$this->view = new Live_View( $ledger );
	}

	/**
	 * The publicly viewable post an old path now leads to, or null.
	 *
	 * @param string    $post_type Post type the request was for.
	 * @param string    $path      Requested path, any form.
	 * @param bool|null $temporary Set to true when a restore from the trash could still undo the move the answer follows.
	 * @return WP_Post|null
	 */
	public function resolve( string $post_type, string $path, ?bool &$temporary = null ): ?WP_Post {
		$temporary = false;
		$target    = Rules::resolve( $this->view, $post_type, Paths::normalize( $path ), $temporary );
		if ( null === $target ) {
			return null;
		}

		// The target, or a page above it, sits where the trash flow moved it: a restore moves it back.
		foreach ( array_merge( [ $target->ID ], get_post_ancestors( $target ) ) as $id ) {
			$temporary = $temporary || Markers::has_moved_row( (int) $id );
		}
		return $target;
	}
}
