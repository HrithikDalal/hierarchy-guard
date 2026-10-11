<?php
/**
 * What sits above and below a page, for the editor panel and the meta box.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard;

use HierarchyGuard\Admin\List_Table;
use HierarchyGuard\Export\Csv;
use HierarchyGuard\Redirects\Providers;
use HierarchyGuard\Trash\Trash_Handler;
use WP_Post;

/**
 * Builds the hierarchy summary of one post. Children are paged, counts are
 * direct, and every total stops at the count cap.
 */
final class Overview {

	public const CHILDREN_PER_PAGE = 10;
	public const MAX_OLD_PATHS     = 20;

	/**
	 * Ledger.
	 *
	 * @var Ledger
	 */
	private Ledger $ledger;

	/**
	 * Providers.
	 *
	 * @var Providers
	 */
	private Providers $providers;

	/**
	 * Constructor.
	 *
	 * @param Ledger    $ledger    Ledger.
	 * @param Providers $providers Redirect plugin detection.
	 */
	public function __construct( Ledger $ledger, Providers $providers ) {
		$this->ledger    = $ledger;
		$this->providers = $providers;
	}

	/**
	 * The summary of one post.
	 *
	 * @param WP_Post $post          Post.
	 * @param int     $children_page Page of children, from 1.
	 * @return array<string,mixed>
	 */
	public function describe( WP_Post $post, int $children_page = 1 ): array {
		$id   = (int) $post->ID;
		$type = (string) $post->post_type;
		$cap  = Subtree::count_cap();

		$children_total = Subtree::count_live_children( $id, $type, $cap + 1 );
		$descendants    = $children_total > 0 ? Subtree::count_live_descendants( $id, $type, $cap ) : 0;
		$children       = $this->children( $id, $type, max( 1, $children_page ) );
		$policy         = Trash_Handler::policy_for( $post );
		$fallback       = Settings::POLICY_TRASH_CHILDREN === $policy && $descendants > $cap;
		$to             = Subtree::nearest_live_ancestor( $post );
		$provider       = $this->providers->detect();

		return [
			'id'              => $id,
			'status'          => (string) $post->post_status,
			'ancestors'       => $this->ancestors( $post ),
			'children'        => $children,
			'children_total'  => min( $children_total, $cap ),
			'children_capped' => $children_total > $cap,
			'has_more'        => $children_page * self::CHILDREN_PER_PAGE < min( $children_total, $cap ),
			'descendants'     => [
				'count'  => min( $descendants, $cap ),
				'capped' => $descendants > $cap,
			],
			'old_paths'       => $this->old_paths( $post ),
			'redirects'       => [
				// The server redirects only to a page anyone can view, outside the trash.
				'serving'      => Settings::serve_redirects() && is_post_publicly_viewable( $post ) && ! Paths::has_trashed_segment( Paths::uri( $post ) ),
				// The pairs list only pages the server redirects to, so it serves them whenever it serves the type.
				'pairs_served' => Settings::serve_redirects() && is_post_type_viewable( $type ),
				'provider'     => null === $provider ? null : [
					'name' => (string) ( $provider['name'] ?? '' ),
					'url'  => esc_url_raw( (string) ( $provider['url'] ?? '' ) ),
					'csv'  => Csv::layout( (string) ( $provider['csv'] ?? 'generic' ) ),
				],
			],
			'trash_policy'    => $fallback ? Settings::POLICY_REPARENT : $policy,
			'trash_fallback'  => $fallback,
			'trash_to'        => $to > 0 ? self::ref( $to ) : null,
			'list_url'        => List_Table::filter_url( $type, $id ),
		];
	}

	/**
	 * A post's title as plain text, never empty, with no "Private:" or "Protected:" prefix.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function title( int $post_id ): string {
		// No "Private:" or "Protected:" prefix outside wp-admin; the status travels on its own.
		$plain = static fn(): string => '%s';
		add_filter( 'private_title_format', $plain );
		add_filter( 'protected_title_format', $plain );
		$title = wp_strip_all_tags( html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ) );
		remove_filter( 'private_title_format', $plain );
		remove_filter( 'protected_title_format', $plain );
		return '' !== $title ? $title : __( '(no title)', 'hierarchy-guard' );
	}

	/**
	 * A short reference to a post. A post the user may not read keeps its
	 * title to itself, as core's REST routes do. A post in the Trash has no
	 * edit link, because core refuses to edit it.
	 *
	 * @param int $post_id Post ID.
	 * @return array{id:int,title:string,edit_link:string|null}
	 */
	public static function ref( int $post_id ): array {
		$link = get_edit_post_link( $post_id, 'raw' );
		return [
			'id'        => $post_id,
			'title'     => current_user_can( 'read_post', $post_id ) ? self::title( $post_id ) : __( '(private page)', 'hierarchy-guard' ),
			'edit_link' => 'trash' !== get_post_status( $post_id ) && is_string( $link ) && '' !== $link ? $link : null,
		];
	}

	/**
	 * Ancestors, top level first.
	 *
	 * @param WP_Post $post Post.
	 * @return array<int,array{id:int,title:string,edit_link:string|null}>
	 */
	private function ancestors( WP_Post $post ): array {
		$ids = array_reverse( array_map( 'intval', get_post_ancestors( $post ) ) );
		_prime_post_caches( $ids, false, false );

		$out = [];
		foreach ( $ids as $ancestor_id ) {
			$out[] = self::ref( $ancestor_id );
		}
		return $out;
	}

	/**
	 * One page of live children the current user may read, each with its own child count.
	 *
	 * @param int    $parent_id Parent post ID.
	 * @param string $type      Post type name.
	 * @param int    $page      Page number, from 1.
	 * @return array<int,array<string,mixed>>
	 */
	private function children( int $parent_id, string $type, int $page ): array {
		$cap    = Subtree::count_cap();
		$offset = ( $page - 1 ) * self::CHILDREN_PER_PAGE;
		if ( $offset >= $cap ) {
			return [];
		}

		// The first $cap children in menu order, read without sorting every child of a large parent.
		$rows = array_slice( Subtree::ordered_children( [ $parent_id ], $type, $cap ), $offset, self::CHILDREN_PER_PAGE );
		$ids  = array_map( static fn( array $row ): int => (int) $row['ID'], $rows );
		_prime_post_caches( $ids, false, false );
		$counts = Subtree::child_counts( $ids, $type, $cap + 1 );

		$out = [];
		foreach ( $ids as $child_id ) {
			if ( ! current_user_can( 'read_post', $child_id ) ) {
				continue;
			}
			$status = (string) get_post_status( $child_id );
			$object = get_post_status_object( $status );
			$out[]  = self::ref( $child_id ) + [
				'status'          => $status,
				'status_label'    => null !== $object ? (string) $object->label : $status,
				'children_count'  => min( $counts[ $child_id ] ?? 0, $cap ),
				'children_capped' => ( $counts[ $child_id ] ?? 0 ) > $cap,
				'view_link'       => is_post_publicly_viewable( $child_id ) ? (string) get_permalink( $child_id ) : null,
			];
		}
		return $out;
	}

	/**
	 * Old paths recorded on the post, newest first, as URLs relative to the
	 * home URL. A path the post is at again, as after a trash moved it back,
	 * is its URL, not an old one.
	 *
	 * Each has a state: `in_use` when another live page is at the path or is
	 * the newest live page to hold it, which the resolver sends it to; `none`
	 * when that newest holder sits under the trash, which the resolver passes
	 * over; `trash` when a page in the trash holds it; `none` when this page
	 * is not viewable or sits under the trash so nothing can redirect here;
	 * and `ok` otherwise.
	 *
	 * @param WP_Post $post Post.
	 * @return array<int,array{path:string,url:string,state:string}>
	 */
	private function old_paths( WP_Post $post ): array {
		$post_id   = (int) $post->ID;
		$type      = (string) $post->post_type;
		$current   = Paths::uri( $post );
		$reachable = is_post_publicly_viewable( $post ) && ! Paths::has_trashed_segment( $current );
		$paths     = array_values( array_diff( array_reverse( $this->ledger->paths_for( $post_id ) ), [ $current ] ) );

		$out = [];
		foreach ( array_slice( $paths, 0, self::MAX_OLD_PATHS ) as $path ) {
			$url = Paths::url( $type, $path );
			// Without pretty permalinks, or a permastruct for the type, no URL ever held the path.
			if ( '' === $url ) {
				continue;
			}
			$owner  = get_page_by_path( $path, OBJECT, $type );
			$holder = $this->ledger->find( $type, $path );
			$other  = null !== $holder && $holder > 0 && $holder !== $post_id ? get_post( $holder ) : null;
			if ( $owner instanceof WP_Post && (int) $owner->ID !== $post_id && is_post_publicly_viewable( $owner ) ) {
				// A live page at the old path is reached before any redirect.
				$state = 'in_use';
			} elseif ( $other instanceof WP_Post ) {
				// The resolver follows the newest live page to hold the path, and passes over one under the trash.
				$state = Paths::has_trashed_segment( Paths::uri( $other ) ) ? 'none' : 'in_use';
			} elseif ( Ledger::held_in_trash( $type, $path ) ) {
				$state = 'trash';
			} elseif ( ! $reachable ) {
				$state = 'none';
			} else {
				$state = 'ok';
			}
			$out[] = [
				'path'  => $path,
				'url'   => Paths::relative( $url ),
				'state' => $state,
			];
		}
		return $out;
	}
}
