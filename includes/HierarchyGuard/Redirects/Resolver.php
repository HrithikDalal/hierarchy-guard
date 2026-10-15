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
	 * The publicly viewable post an old path now leads to, the URL a URL
	 * target sends it to, or null.
	 *
	 * @param string    $post_type Post type the request was for.
	 * @param string    $path      Requested path, any form.
	 * @param bool|null $temporary Set to true when a restore from the trash could still undo the move the answer follows.
	 * @param int|null  $code      Set to the 301 or 302 chosen on the Redirects tab, or 0 for the automatic code.
	 * @return WP_Post|string|null A post, an http(s) URL, or null.
	 */
	public function resolve( string $post_type, string $path, ?bool &$temporary = null, ?int &$code = null ): WP_Post|string|null {
		$temporary = false;
		$code      = 0;
		$trace     = null;
		$target    = Rules::resolve( $this->view, $post_type, Paths::normalize( $path ), $temporary, $trace, $code );
		if ( null === $target || is_string( $target ) ) {
			return $target;
		}

		// The target, or a page above it, sits where the trash flow moved it: a restore moves it back.
		foreach ( array_merge( [ $target->ID ], get_post_ancestors( $target ) ) as $id ) {
			$temporary = $temporary || Markers::has_moved_row( (int) $id );
		}
		return $target;
	}
}
