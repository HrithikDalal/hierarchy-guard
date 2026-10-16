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
use HierarchyGuard\Trash\Change_Log;
use HierarchyGuard\Trash\Trash_Handler;
use WP_Post;

/**
 * Builds the hierarchy summary of one post. Children are paged, counts are
 * direct, and every total stops at the count cap. It says where the post is
 * now, not its past URLs; the current user's latest change, when it is a
 * save of this post, tells the editor which redirects that save needs.
 */
final class Overview {

	public const CHILDREN_PER_PAGE     = 10;
	public const MAX_CHILDREN_PER_PAGE = 50;

	/**
	 * Providers.
	 *
	 * @var Providers
	 */
	private Providers $providers;

	/**
	 * Change log.
	 *
	 * @var Change_Log
	 */
	private Change_Log $log;

	/**
	 * Constructor.
	 *
	 * @param Providers  $providers Redirect plugin detection.
	 * @param Change_Log $log       Change log.
	 */
	public function __construct( Providers $providers, Change_Log $log ) {
		$this->providers = $providers;
		$this->log       = $log;
	}

	/**
	 * The summary of one post.
	 *
	 * @param WP_Post $post          Post.
	 * @param int     $children_page Page of children, from 1.
	 * @param int     $per_page      Children per page, at most MAX_CHILDREN_PER_PAGE.
	 * @return array<string,mixed>
	 */
	public function describe( WP_Post $post, int $children_page = 1, int $per_page = self::CHILDREN_PER_PAGE ): array {
		$id            = (int) $post->ID;
		$type          = (string) $post->post_type;
		$cap           = Subtree::count_cap();
		$children_page = max( 1, $children_page );
		$per_page      = max( 1, min( self::MAX_CHILDREN_PER_PAGE, $per_page ) );

		$children_total = Subtree::count_live_children( $id, $type, $cap + 1 );
		$descendants    = $children_total > 0 ? Subtree::count_live_descendants( $id, $type, $cap ) : 0;
		$children       = $this->children( $id, $type, $children_page, $per_page );
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
			'has_more'        => $children_page * $per_page < min( $children_total, $cap ),
			'descendants'     => [
				'count'  => min( $descendants, $cap ),
				'capped' => $descendants > $cap,
			],
			'latest_change'   => $this->latest_change( $id ),
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
	 * The current user's latest change ID when it is a save of this post.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null
	 */
	private function latest_change( int $post_id ): ?string {
		$change = $this->log->latest( get_current_user_id() );
		if ( null === $change || 'save' !== ( $change['action'] ?? '' ) || ! isset( $change['roots'][ $post_id ] ) ) {
			return null;
		}
		return (string) $change['id'];
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
	 * One page of live children the current user may read, each with its own
	 * child count and what the list's rows show: the post states and edit lock
	 * by the title, the author, the date as core's Date column words it, the
	 * View link core prints, and the trash link when the user may trash it.
	 *
	 * @param int    $parent_id Parent post ID.
	 * @param string $type      Post type name.
	 * @param int    $page      Page number, from 1.
	 * @param int    $per_page  Children per page.
	 * @return array<int,array<string,mixed>>
	 */
	private function children( int $parent_id, string $type, int $page, int $per_page ): array {
		$cap    = Subtree::count_cap();
		$offset = ( $page - 1 ) * $per_page;
		if ( $offset >= $cap ) {
			return [];
		}

		// The first $cap children in menu order, read without sorting every child of a large parent.
		$rows = array_slice( Subtree::ordered_children( [ $parent_id ], $type, $cap ), $offset, $per_page );
		$ids  = array_map( static fn( array $row ): int => (int) $row['ID'], $rows );
		// Their meta too, in one query, so each row's edit lock is read from the cache.
		_prime_post_caches( $ids, false, true );
		$counts = Subtree::child_counts( $ids, $type, $cap + 1 );
		$posts  = array_values( array_filter( array_map( 'get_post', $ids ) ) );
		update_post_author_caches( $posts );
		// Core's helpers for the states and the edit lock live with its list screens.
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/post.php';
		// A display_post_states callback may ask for the screen, as on core's list; a REST request has none, so it gets null.
		if ( ! function_exists( 'get_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}

		$out = [];
		foreach ( $posts as $child ) {
			$child_id = (int) $child->ID;
			if ( ! current_user_can( 'read_post', $child_id ) ) {
				continue;
			}
			$status = (string) $child->post_status;
			$object = get_post_status_object( $status );
			$author = get_userdata( (int) $child->post_author );
			$out[]  = self::ref( $child_id ) + [
				'status'          => $status,
				'status_label'    => null !== $object ? (string) $object->label : $status,
				'children_count'  => min( $counts[ $child_id ] ?? 0, $cap ),
				'children_capped' => ( $counts[ $child_id ] ?? 0 ) > $cap,
				// get_userdata(), not get_the_author_meta(), which reads the loop's author for a post_author of 0.
				'author'          => false !== $author ? (string) $author->display_name : '',
				'states'          => array_values( array_map( 'wp_strip_all_tags', (array) get_post_states( $child ) ) ),
				'locked_by'       => self::locked_by( $child ),
				'date'            => self::date( $child ),
				// As core's row: View for a page anyone can view, and for a private page of a viewable type, which this user may read.
				'view_link'       => is_post_publicly_viewable( $child ) || ( 'private' === $status && is_post_type_viewable( $type ) ) ? (string) get_permalink( $child ) : null,
				'trash_link'      => self::trash_link( $child ),
			];
		}
		return $out;
	}

	/**
	 * A post's date as core's Date column words it: the status it is dated by,
	 * then the date and time.
	 *
	 * @param WP_Post $post Post.
	 * @return array{label:string,date:string,missed:bool}
	 */
	public static function date( WP_Post $post ): array {
		if ( '0000-00-00 00:00:00' === $post->post_date ) {
			$date = __( 'Unpublished', 'hierarchy-guard' );
			$late = false;
		} else {
			$date = sprintf(
				/* translators: 1: post date, 2: post time. */
				__( '%1$s at %2$s', 'hierarchy-guard' ),
				/* translators: Post date format. See https://www.php.net/manual/datetime.format.php */
				get_the_time( __( 'Y/m/d', 'hierarchy-guard' ), $post ),
				/* translators: Post time format. See https://www.php.net/manual/datetime.format.php */
				get_the_time( __( 'g:i a', 'hierarchy-guard' ), $post )
			);
			$late = time() > (int) get_post_timestamp( $post );
		}

		$missed = 'future' === $post->post_status && $late;
		if ( 'publish' === $post->post_status ) {
			$label = __( 'Published', 'hierarchy-guard' );
		} elseif ( 'future' === $post->post_status ) {
			$label = $missed ? __( 'Missed schedule', 'hierarchy-guard' ) : __( 'Scheduled', 'hierarchy-guard' );
		} else {
			$label = __( 'Last Modified', 'hierarchy-guard' );
		}

		return [
			'label'  => $label,
			'date'   => $date,
			'missed' => $missed,
		];
	}

	/**
	 * Who holds the edit lock on a post the current user may edit, as core's
	 * row says "… is currently editing", or null.
	 *
	 * @param WP_Post $post Post.
	 * @return string|null Display name.
	 */
	public static function locked_by( WP_Post $post ): ?string {
		if ( 'trash' === $post->post_status || ! current_user_can( 'edit_post', (int) $post->ID ) ) {
			return null;
		}
		$holder = wp_check_post_lock( $post );
		$user   = false !== $holder ? get_userdata( (int) $holder ) : false;
		return false !== $user ? (string) $user->display_name : null;
	}

	/**
	 * The link that moves a post to the Trash, signed for the current user, or
	 * null when they may not trash it or the site deletes posts outright.
	 *
	 * @param WP_Post $post Post.
	 * @return string|null
	 */
	public static function trash_link( WP_Post $post ): ?string {
		if ( ! EMPTY_TRASH_DAYS || 'trash' === $post->post_status ) {
			return null;
		}
		$link = get_delete_post_link( $post );
		// Core escapes the link for HTML; the route sends it raw, as it sends every other link.
		return is_string( $link ) && '' !== $link ? html_entity_decode( $link, ENT_QUOTES, 'UTF-8' ) : null;
	}
}
