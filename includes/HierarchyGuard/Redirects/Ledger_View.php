<?php
/**
 * What the resolver's rules read.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Redirects;

use WP_Post;

/**
 * The facts Rules asks for: the ledger, posts, core's path lookups and the
 * main query's 404. The server reads them live; a list of pairs reads them
 * ahead for every path at once.
 */
interface Ledger_View {

	/**
	 * What a path used to lead to, as Ledger::find() answers it: the newest
	 * post not in the trash that holds it or, with none, the root list.
	 *
	 * @param string $post_type Post type name.
	 * @param string $prefix    Normalized path.
	 * @return int|null Post ID, Rules::ROOT when the path is in the root list, null when unknown.
	 */
	public function find( string $post_type, string $prefix ): ?int;

	/**
	 * Whether a post in the trash holds a path, as Ledger::held_in_trash() answers it.
	 *
	 * @param string $post_type Post type name.
	 * @param string $prefix    Normalized path.
	 * @return bool
	 */
	public function held_in_trash( string $post_type, string $prefix ): bool;

	/**
	 * A post, or null when there is none.
	 *
	 * @param int $id Post ID.
	 * @return WP_Post|null
	 */
	public function post( int $id ): ?WP_Post;

	/**
	 * A post's path, as Paths::uri() builds it.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public function uri( WP_Post $post ): string;

	/**
	 * The post get_page_by_path( $candidate, OBJECT, [ $post_type ] ) finds.
	 *
	 * @param string $candidate Normalized path.
	 * @param string $post_type Post type name.
	 * @return WP_Post|null
	 */
	public function page_by_path( string $candidate, string $post_type ): ?WP_Post;

	/**
	 * Whether a request for a path of the type is a 404, as WP's main query
	 * answers it: the resolver only ever runs on one.
	 *
	 * @param string $path      Normalized path.
	 * @param string $post_type Post type name.
	 * @return bool
	 */
	public function is_404( string $path, string $post_type ): bool;
}
