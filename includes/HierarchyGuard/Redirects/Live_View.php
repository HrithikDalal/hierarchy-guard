<?php
/**
 * The facts the rules read, live.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Redirects;

use HierarchyGuard\Ledger;
use HierarchyGuard\Paths;
use WP_Post;

/**
 * Reads each fact when the rules ask: the ledger's cached lookups and core's.
 */
final class Live_View implements Ledger_View {

	/**
	 * Ledger.
	 *
	 * @var Ledger
	 */
	private Ledger $ledger;

	/**
	 * Constructor.
	 *
	 * @param Ledger $ledger Ledger.
	 */
	public function __construct( Ledger $ledger ) {
		$this->ledger = $ledger;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $post_type Post type name.
	 * @param string $prefix    Normalized path.
	 */
	public function find( string $post_type, string $prefix ): ?int {
		return $this->ledger->find( $post_type, $prefix );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $post_type Post type name.
	 * @param string $prefix    Normalized path.
	 */
	public function held_in_trash( string $post_type, string $prefix ): bool {
		return Ledger::held_in_trash( $post_type, $prefix );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $post_type Post type name.
	 * @param string $prefix    Normalized path.
	 */
	public function newest_in_trash( string $post_type, string $prefix ): bool {
		return Ledger::newest_in_trash( $post_type, $prefix );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id Post ID.
	 */
	public function post( int $id ): ?WP_Post {
		$post = get_post( $id );
		return $post instanceof WP_Post ? $post : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param WP_Post $post Post.
	 */
	public function uri( WP_Post $post ): string {
		return Paths::uri( $post );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $candidate Normalized path.
	 * @param string $post_type Post type name.
	 */
	public function page_by_path( string $candidate, string $post_type ): ?WP_Post {
		$post = get_page_by_path( $candidate, OBJECT, [ $post_type ] );
		return $post instanceof WP_Post ? $post : null;
	}
}
