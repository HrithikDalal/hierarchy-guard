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
		$id       = (int) $post->ID;
		$type     = (string) $post->post_type;
		$cap      = Subtree::count_cap();
		$subpages = $this->subpages( $post, $children_page, $per_page );

		$descendants = $subpages['children_total'] > 0 ? Subtree::count_live_descendants( $id, $type, $cap ) : 0;
		$policy      = Trash_Handler::policy_for( $post );
		$fallback    = Settings::POLICY_TRASH_CHILDREN === $policy && $descendants > $cap;
		$to          = Subtree::nearest_live_ancestor( $post );
		$provider    = $this->providers->detect();

		return [
			'id'        => $id,
			'status'    => (string) $post->post_status,
			'ancestors' => $this->ancestors( $post ),
		] + $subpages + [
			'descendants'    => [
				'count'  => min( $descendants, $cap ),
				'capped' => $descendants > $cap,
			],
			'latest_change'  => $this->latest_change( $id ),
			'redirects'      => [
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
			'trash_policy'   => $fallback ? Settings::POLICY_REPARENT : $policy,
			'trash_fallback' => $fallback,
			'trash_to'       => $to > 0 ? self::ref( $to ) : null,
			'list_url'       => List_Table::filter_url( $type, $id ),
		];
	}

	/**
	 * One page of a post's subpages and their count, the part of the summary
	 * the Tree view reads.
	 *
	 * @param WP_Post $post          Post.
	 * @param int     $children_page Page of children, from 1.
	 * @param int     $per_page      Children per page, at most MAX_CHILDREN_PER_PAGE.
	 * @return array{id:int,child_of:string,children:array<int,array<string,mixed>>,children_total:int,children_capped:bool,has_more:bool}
	 */
	public function subpages( WP_Post $post, int $children_page = 1, int $per_page = self::CHILDREN_PER_PAGE ): array {
		$id            = (int) $post->ID;
		$type          = (string) $post->post_type;
		$cap           = Subtree::count_cap();
		$children_page = max( 1, $children_page );
		$per_page      = max( 1, min( self::MAX_CHILDREN_PER_PAGE, $per_page ) );
		$total         = Subtree::count_live_children( $id, $type, $cap + 1 );

		return [
			'id'              => $id,
			'child_of'        => self::child_of( $post ),
			'children'        => $this->children( $id, $type, $children_page, $per_page, $total > $cap ? null : $total ),
			'children_total'  => min( $total, $cap ),
			'children_capped' => $total > $cap,
			'has_more'        => $children_page * $per_page < min( $total, $cap ),
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
	 * @param int  $post_id Post ID.
	 * @param bool $strip   Whether markup in the title is stripped. Unstripped, it reads as core's list prints it: the markup as text, entities decoded.
	 * @return string
	 */
	public static function title( int $post_id, bool $strip = true ): string {
		// No "Private:" or "Protected:" prefix outside wp-admin; the status travels on its own.
		$plain = static fn(): string => '%s';
		add_filter( 'private_title_format', $plain );
		add_filter( 'protected_title_format', $plain );
		$raw = get_the_title( $post_id );
		remove_filter( 'private_title_format', $plain );
		remove_filter( 'protected_title_format', $plain );
		$title = html_entity_decode( $raw, ENT_QUOTES, 'UTF-8' );
		$title = $strip ? wp_strip_all_tags( $title ) : $title;
		// As _draft_or_post_title() tests the title before any markup is stripped: "0" is no title, "<b>0</b>" is one; and a name is never empty.
		return empty( $raw ) || '' === $title ? __( '(no title)', 'hierarchy-guard' ) : $title;
	}

	/**
	 * The name core's list gives a post in its subpages' "Child of", made as
	 * core makes it: the title through the_title with the list's esc_html,
	 * stripped of the markup a later filter adds, as the browser reads it.
	 * There is no "(no title)": "0" stays "0" and "" names no one.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function child_of( WP_Post $post ): string {
		// Core's list escapes every title it prints, as here.
		$added = false === has_filter( 'the_title', 'esc_html' ) && add_filter( 'the_title', 'esc_html' );
		try {
			/** This filter is documented in wp-includes/post-template.php */
			$title = (string) apply_filters( 'the_title', (string) $post->post_title, (int) $post->ID ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own filter, as core's list applies it.
		} finally {
			// Even when another filter throws, no later title is escaped.
			if ( $added ) {
				remove_filter( 'the_title', 'esc_html' );
			}
		}
		return html_entity_decode( wp_strip_all_tags( $title ), ENT_QUOTES, 'UTF-8' );
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
		return [
			'id'        => $post_id,
			'title'     => current_user_can( 'read_post', $post_id ) ? self::title( $post_id ) : __( '(private page)', 'hierarchy-guard' ),
			'edit_link' => 'trash' !== get_post_status( $post_id ) ? self::edit_link( $post_id ) : null,
		];
	}

	/**
	 * A post's raw edit link, or null when the user may not edit it.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null
	 */
	private static function edit_link( int $post_id ): ?string {
		$link = get_edit_post_link( $post_id, 'raw' );
		return is_string( $link ) && '' !== $link ? $link : null;
	}

	/**
	 * Text WordPress keeps or builds as HTML, such as a display name or a post
	 * state, as the browser reads it where core prints it.
	 *
	 * @param string $html Text with entities.
	 * @return string
	 */
	private static function text( string $html ): string {
		return html_entity_decode( $html, ENT_QUOTES, 'UTF-8' );
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
	 * child count and what the list's rows show: the title as core's list
	 * prints it, the row's classes, the post states and edit lock by the
	 * title, the author, the date as core's Date column words it, the View
	 * link core prints, the trash link when the user may trash it, and its
	 * place among all its siblings, as the Tree view numbers it without
	 * JavaScript: a child the user may not read keeps its place, unseen.
	 *
	 * @param int      $parent_id Parent post ID.
	 * @param string   $type      Post type name.
	 * @param int      $page      Page number, from 1.
	 * @param int      $per_page  Children per page.
	 * @param int|null $total     Live children, or null past the count cap, where no place is known.
	 * @return array<int,array<string,mixed>>
	 */
	private function children( int $parent_id, string $type, int $page, int $per_page, ?int $total ): array {
		$cap    = Subtree::count_cap();
		$offset = ( $page - 1 ) * $per_page;
		if ( $offset >= $cap ) {
			return [];
		}

		// The first $cap children in menu order, read without sorting every child of a large parent.
		$rows = array_slice( Subtree::ordered_children( [ $parent_id ], $type, $cap ), $offset, $per_page );
		$ids  = array_map( static fn( array $row ): int => (int) $row['ID'], $rows );
		// Their meta and terms too, in one query each, so each row's edit lock and classes are read from the cache.
		_prime_post_caches( $ids, true, true );
		$counts  = Subtree::child_counts( $ids, $type, $cap + 1 );
		$posts   = array_filter( array_map( 'get_post', $ids ) );
		$holders = [];
		foreach ( $posts as $post ) {
			$holders[ (int) $post->ID ] = self::lock_holder( $post );
		}
		// The authors and the lock holders, in one query, found or not.
		$names = self::user_names( array_merge( array_map( static fn( WP_Post $post ): int => (int) $post->post_author, $posts ), $holders ) );
		// Core's helper for the states lives with its list screens.
		require_once ABSPATH . 'wp-admin/includes/template.php';
		// A display_post_states callback may ask for the screen, as on core's list; a REST request has none, so it gets null.
		if ( ! function_exists( 'get_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/screen.php';
		}

		$out = [];
		foreach ( $posts as $index => $child ) {
			$child_id = (int) $child->ID;
			if ( ! current_user_can( 'read_post', $child_id ) ) {
				continue;
			}
			$status = (string) $child->post_status;
			$object = get_post_status_object( $status );
			$out[]  = [
				'id'              => $child_id,
				'title'           => self::title( $child_id, false ),
				// Core's row names an untitled post "no title" for assistive technology.
				'untitled'        => empty( $child->post_title ),
				// A live child, never in the Trash.
				'edit_link'       => self::edit_link( $child_id ),
				'status'          => $status,
				'status_label'    => null !== $object ? (string) $object->label : $status,
				'position'        => null !== $total ? $offset + $index + 1 : null,
				'setsize'         => $total,
				'classes'         => self::row_classes( $child ),
				'children_count'  => min( $counts[ $child_id ] ?? 0, $cap ),
				'children_capped' => ( $counts[ $child_id ] ?? 0 ) > $cap,
				// Not get_the_author_meta(), which reads the loop's author for a post_author of 0.
				'author'          => $names[ (int) $child->post_author ] ?? '',
				// Core prints each state as HTML; the script sets text, so it gets the text the browser reads.
				'states'          => array_values( array_map( static fn( $state ): string => self::text( wp_strip_all_tags( (string) $state ) ), (array) get_post_states( $child ) ) ),
				'locked_by'       => $names[ $holders[ $child_id ] ] ?? null,
				'date'            => self::date( $child ),
				// As core's row: View for a page anyone can view, and for a private page of a viewable type, which this user may read.
				'view_link'       => is_post_publicly_viewable( $child ) || ( 'private' === $status && is_post_type_viewable( $type ) ) ? (string) get_permalink( $child ) : null,
				'trash_link'      => self::trash_link( $child ),
			];
		}
		return $out;
	}

	/**
	 * Display names by user ID, read in one query whether or not each user
	 * still exists; a missing one is left out. Core's user cache keeps no
	 * misses, so a deleted author or lock holder read per row would cost a
	 * query a row.
	 *
	 * @param int[] $ids User IDs; 0 is none.
	 * @return array<int,string>
	 */
	private static function user_names( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( [] === $ids ) {
			return [];
		}
		$users = get_users(
			[
				'include' => $ids,
				// Any user of the network, as core's Author column names them, not only this site's.
				'blog_id' => 0,
				'fields'  => [ 'ID', 'display_name' ],
			]
		);
		$names = [];
		foreach ( $users as $user ) {
			$names[ (int) $user->ID ] = self::text( (string) $user->display_name );
		}
		return $names;
	}

	/**
	 * The user holding the edit lock on a post the current user may edit, as
	 * core's row says "… is currently editing", or 0. It reads the lock as
	 * wp_check_post_lock() does, but leaves finding the user, who may have
	 * been deleted, to the page's one read of names.
	 *
	 * @param WP_Post $post Post.
	 * @return int User ID, or 0.
	 */
	private static function lock_holder( WP_Post $post ): int {
		$id = (int) $post->ID;
		if ( 'trash' === $post->post_status || ! current_user_can( 'edit_post', $id ) ) {
			return 0;
		}
		$lock = explode( ':', (string) get_post_meta( $id, '_edit_lock', true ) );
		$time = (int) $lock[0];
		// A lock without a user is the last editor's.
		$user = isset( $lock[1] ) ? (int) $lock[1] : (int) get_post_meta( $id, '_edit_last', true );
		/** This filter is documented in wp-admin/includes/ajax-actions.php */
		$window = (int) apply_filters( 'wp_check_post_lock_window', 150 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own filter.
		return $time > time() - $window && get_current_user_id() !== $user ? $user : 0;
	}

	/**
	 * The classes core's list gives a post's row, but its level-N, which
	 * depends on where the row sits: get_post_class()'s own classes as
	 * wp-admin prints them, built here without its post_class filters, which
	 * a theme may write for the front-end loop and which a REST request must
	 * not run.
	 *
	 * @param WP_Post $post Post.
	 * @return string[]
	 */
	public static function row_classes( WP_Post $post ): array {
		$id      = (int) $post->ID;
		$type    = (string) $post->post_type;
		$classes = [
			'iedit',
			'author-' . ( get_current_user_id() === (int) $post->post_author ? 'self' : 'other' ),
			'post-' . $id,
			'type-' . $type,
			'status-' . $post->post_status,
		];
		if ( post_type_supports( $type, 'post-formats' ) ) {
			$format    = get_post_format( $post );
			$classes[] = is_string( $format ) && '' !== $format ? 'format-' . sanitize_html_class( $format ) : 'format-standard';
		}
		$locked = post_password_required( $post );
		if ( $locked ) {
			$classes[] = 'post-password-required';
		} elseif ( '' !== (string) $post->post_password ) {
			$classes[] = 'post-password-protected';
		}
		if ( current_theme_supports( 'post-thumbnails' ) && has_post_thumbnail( $post ) && ! $locked ) {
			$classes[] = 'has-post-thumbnail';
		}
		if ( is_sticky( $id ) ) {
			// As wp-admin marks a sticky post.
			$classes[] = 'status-sticky';
		}
		$classes[] = 'hentry';
		foreach ( get_taxonomies( [ 'public' => true ] ) as $taxonomy ) {
			if ( ! is_object_in_taxonomy( $type, $taxonomy ) ) {
				continue;
			}
			$terms = get_the_terms( $post, $taxonomy );
			foreach ( is_array( $terms ) ? $terms : [] as $term ) {
				// As core: a slug of "0" is as empty as none.
				if ( empty( $term->slug ) ) {
					continue;
				}
				$term_class = sanitize_html_class( $term->slug, (string) $term->term_id );
				if ( is_numeric( $term_class ) || '' === trim( $term_class, '-' ) ) {
					$term_class = (string) $term->term_id;
				}
				// As core: 'post_tag' takes the 'tag' prefix.
				$classes[] = 'post_tag' === $taxonomy ? 'tag-' . $term_class : sanitize_html_class( $taxonomy . '-' . $term_class, $taxonomy . '-' . $term->term_id );
			}
		}
		return array_values( array_unique( array_map( 'esc_attr', $classes ) ) );
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
