<?php
/**
 * Shared fixtures for the trash-flow tests.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Trash\Markers;

/**
 * Builds a small page tree and moves posts in and out of the trash without
 * running hooks, so a test can exercise one unit at a time.
 */
trait Trash_Fixtures {

	/**
	 * Company / about / { team / leadership, history }.
	 *
	 * @return array<string,int> Page IDs keyed by slug.
	 */
	protected function make_tree(): array {
		$ids               = [];
		$ids['company']    = $this->page( 'company' );
		$ids['about']      = $this->page( 'about', $ids['company'] );
		$ids['team']       = $this->page( 'team', $ids['about'] );
		$ids['history']    = $this->page( 'history', $ids['about'] );
		$ids['leadership'] = $this->page( 'leadership', $ids['team'] );
		return $ids;
	}

	/**
	 * Creates a page.
	 *
	 * @param string $slug      Slug.
	 * @param int    $parent_id Parent page ID.
	 * @param string $status    Post status.
	 * @return int Page ID.
	 */
	protected function page( string $slug, int $parent_id = 0, string $status = 'publish' ): int {
		return self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_name'   => $slug,
				'post_title'  => ucfirst( $slug ),
				'post_parent' => $parent_id,
				'post_status' => $status,
			]
		);
	}

	/**
	 * Puts a post in the trash with core's trash meta and suffixed slug, without running hooks.
	 *
	 * @param int $post_id Post ID.
	 */
	protected function trash_raw( int $post_id ): void {
		global $wpdb;
		$post = get_post( $post_id );
		add_post_meta( $post_id, '_wp_desired_post_slug', $post->post_name );
		add_post_meta( $post_id, '_wp_trash_meta_status', $post->post_status );
		$wpdb->update(
			$wpdb->posts,
			[
				'post_status' => 'trash',
				'post_name'   => $post->post_name . '__trashed',
			],
			[ 'ID' => $post_id ]
		);
		clean_post_cache( $post_id );
	}

	/**
	 * Takes a post out of the trash with its previous status and slug, without
	 * running hooks. Core's own untrash would restore it as a draft.
	 *
	 * @param int $post_id Post ID.
	 */
	protected function untrash_raw( int $post_id ): void {
		global $wpdb;
		$slug   = (string) get_post_meta( $post_id, '_wp_desired_post_slug', true );
		$status = (string) get_post_meta( $post_id, '_wp_trash_meta_status', true );
		$wpdb->update(
			$wpdb->posts,
			[
				'post_status' => '' !== $status ? $status : 'publish',
				'post_name'   => $slug,
			],
			[ 'ID' => $post_id ]
		);
		delete_post_meta( $post_id, '_wp_desired_post_slug' );
		delete_post_meta( $post_id, '_wp_trash_meta_status' );
		clean_post_cache( $post_id );
	}

	/**
	 * Makes every moved marker of a root look older, so a restore treats the
	 * move as one visitors may have bookmarked.
	 *
	 * @param int $root_id Root page.
	 * @param int $seconds How much older.
	 */
	protected function age_moved_markers( int $root_id, int $seconds ): void {
		foreach ( Markers::rows( Markers::moved_key( $root_id ), 1000 ) as $row ) {
			$moved = Markers::decode_moved( $row['value'] );
			$value = Markers::encode_moved( $moved['to'], $moved['original'], $moved['assigned'], $moved['time'] - $seconds );
			Markers::remove( [ $row ] );
			Markers::add( $row['post_id'], Markers::moved_key( $root_id ), $value );
		}
	}

	/**
	 * Post IDs carrying a marker key.
	 *
	 * @param string $key Marker key.
	 * @return int[]
	 */
	protected function marked_ids( string $key ): array {
		return array_map( static fn( array $row ): int => $row['post_id'], Markers::rows( $key, 1000 ) );
	}

	/**
	 * Makes the request look like core's bulk action on the Pages list.
	 *
	 * @param string $action  trash or untrash.
	 * @param int[]  $ids     Selected IDs.
	 * @param bool   $as_list Pass them the way Undo does, as a comma-separated ids parameter.
	 */
	protected function start_core_bulk( string $action, array $ids, bool $as_list = false ): void {
		$GLOBALS['pagenow'] = 'edit.php';
		$_REQUEST['action'] = $action;
		if ( $as_list ) {
			$_REQUEST['ids'] = implode( ',', $ids );
		} else {
			$_REQUEST['post'] = array_map( 'strval', $ids );
		}
	}

	/**
	 * Undoes start_core_bulk().
	 */
	protected function end_core_bulk(): void {
		$GLOBALS['pagenow'] = 'index.php';
		unset( $_REQUEST['action'], $_REQUEST['ids'], $_REQUEST['post'], $_REQUEST['filter_action'], $_REQUEST['bulk_edit'] );
	}

	/**
	 * Runs a save as the classic editor's form sends it: to post.php, as editpost.
	 *
	 * @param callable $save The save.
	 * @return mixed What the save returns.
	 */
	protected function as_classic_save( callable $save ) {
		$GLOBALS['pagenow'] = 'post.php';
		$_REQUEST['action'] = 'editpost';
		try {
			return $save();
		} finally {
			$GLOBALS['pagenow'] = 'index.php';
			unset( $_REQUEST['action'] );
		}
	}

	/**
	 * Runs a save as the Pages list's Quick Edit sends it: admin-ajax.php's inline-save.
	 *
	 * @param callable $save The save.
	 * @return mixed What the save returns.
	 */
	protected function as_quick_edit( callable $save ) {
		add_filter( 'wp_doing_ajax', '__return_true' );
		$_REQUEST['action'] = 'inline-save';
		try {
			return $save();
		} finally {
			remove_filter( 'wp_doing_ajax', '__return_true' );
			unset( $_REQUEST['action'] );
		}
	}

	/**
	 * Saves a page's slug as the block editor does, over the REST API.
	 *
	 * @param int    $post_id Page.
	 * @param string $slug    New slug.
	 * @return int The response status.
	 */
	protected function rest_save( int $post_id, string $slug ): int {
		$request = new \WP_REST_Request( 'POST', '/wp/v2/pages/' . $post_id );
		$request->set_body_params( [ 'slug' => $slug ] );
		return rest_do_request( $request )->get_status();
	}

	/**
	 * Saves pages the way the Pages list's Bulk Edit does: core's
	 * bulk_edit_posts() in edit.php, on the selection in the list's order,
	 * giving them a new parent.
	 *
	 * @param int[] $ids       Selected pages, in the list's order.
	 * @param int   $parent_id New parent, 0 for the top level.
	 * @return array<string,int[]> What core reports, by updated, skipped and locked.
	 */
	protected function bulk_edit( array $ids, int $parent_id ): array {
		require_once ABSPATH . 'wp-admin/includes/post.php';
		$this->start_core_bulk( 'edit', $ids );
		$_REQUEST['bulk_edit'] = 'Update';
		try {
			return bulk_edit_posts(
				[
					'post_type'   => 'page',
					'_status'     => '-1',
					'post'        => array_map( 'strval', $ids ),
					'post_parent' => (string) $parent_id,
				]
			);
		} finally {
			$this->end_core_bulk();
		}
	}
}
