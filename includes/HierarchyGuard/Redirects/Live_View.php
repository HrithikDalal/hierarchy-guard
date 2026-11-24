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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
	 * What Ledger::holders() last read, by prefix: the rules ask for each
	 * prefix of a path in turn, longest first, and one read covers the next
	 * Ledger::PREFIXES_PER_READ. Only the last read is kept, so memory stays
	 * bounded however deep the path. The holders' status is read on each
	 * ask.
	 *
	 * @var array<string,int|null>
	 */
	private array $found = [];

	/**
	 * The post type $found is for.
	 *
	 * @var string
	 */
	private string $found_type = '';

	/**
	 * The ledger's last-changed key $found was read under.
	 *
	 * @var string
	 */
	private string $found_under = '';

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
		$changed = (string) wp_cache_get_last_changed( Ledger::CACHE_GROUP );
		if ( $changed !== $this->found_under || $post_type !== $this->found_type || ! array_key_exists( $prefix, $this->found ) ) {
			$this->found       = $this->ledger->holders( $post_type, $prefix );
			$this->found_type  = $post_type;
			$this->found_under = $changed;
		}
		return $this->ledger->answer( $post_type, $prefix, $this->found[ $prefix ] ?? null );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $post_type Post type name.
	 * @param string $prefix    Normalized path.
	 * @param int    $hit       What find() answered.
	 */
	public function target( string $post_type, string $prefix, int $hit ): ?array {
		return $this->ledger->target( $post_type, $prefix, $hit );
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
	 * @param string $post_type Post type name.
	 * @param string $prefix    Normalized path.
	 */
	public function trashed_holders( string $post_type, string $prefix ): array {
		return Ledger::trashed_holders( $post_type, $prefix );
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
