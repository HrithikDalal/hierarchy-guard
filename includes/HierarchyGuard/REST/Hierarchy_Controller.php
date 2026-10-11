<?php
/**
 * REST routes about one post's hierarchy.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\REST;

use HierarchyGuard\Export\Csv;
use HierarchyGuard\Ledger;
use HierarchyGuard\Overview;
use HierarchyGuard\Paths;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Pairs;
use HierarchyGuard\Settings;
use HierarchyGuard\Subtree;
use HierarchyGuard\Trash\Operations;
use HierarchyGuard\Trash\Trash_Handler;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Backs the editor panel, the Quick Edit warning, the trash dialog and the
 * redirects modal. Every route is read-only and needs edit rights on the
 * post it reads.
 */
final class Hierarchy_Controller {

	public const NAMESPACE   = 'hierarchy-guard/v1';
	public const MAX_PREVIEW = 50;

	/**
	 * Overview builder.
	 *
	 * @var Overview
	 */
	private Overview $overview;

	/**
	 * Ledger.
	 *
	 * @var Ledger
	 */
	private Ledger $ledger;

	/**
	 * Pairs.
	 *
	 * @var Pairs
	 */
	private Pairs $pairs;

	/**
	 * Constructor.
	 *
	 * @param Overview $overview Overview builder.
	 * @param Ledger   $ledger   Ledger.
	 * @param Pairs    $pairs    Pairs.
	 */
	public function __construct( Overview $overview, Ledger $ledger, Pairs $pairs ) {
		$this->overview = $overview;
		$this->ledger   = $ledger;
		$this->pairs    = $pairs;
	}

	/**
	 * Hooks into core.
	 */
	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	/**
	 * Registers the routes.
	 */
	public function routes(): void {
		$id = [
			'type'     => 'integer',
			'minimum'  => 1,
			'required' => true,
		];

		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<id>\d+)/hierarchy',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'hierarchy' ],
				'permission_callback' => [ $this, 'can_edit' ],
				'args'                => [
					'id'            => $id,
					'children_page' => [
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<id>\d+)/pairs',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'pairs' ],
				'permission_callback' => [ $this, 'can_edit' ],
				'args'                => [
					'id'       => $id,
					'from'     => [
						'type'     => 'string',
						'required' => true,
					],
					'page'     => [
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					],
					'per_page' => [
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => max( Subtree::COUNT_CAP, Subtree::count_cap() ),
						'default' => Pairs::PER_PAGE,
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/posts/(?P<id>\d+)/csv',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'csv' ],
				'permission_callback' => [ $this, 'can_edit' ],
				'args'                => [
					'id'     => $id,
					'from'   => [
						'type'     => 'string',
						'required' => true,
					],
					'format' => [
						'type'    => 'string',
						'enum'    => array_keys( Csv::LAYOUTS ),
						'default' => 'generic',
					],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/trash-preview',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'trash_preview' ],
				'permission_callback' => 'is_user_logged_in',
				'args'                => [
					'ids' => [
						'type'     => 'array',
						'items'    => [
							'type'    => 'integer',
							'minimum' => 1,
						],
						// A list screen shows at most 999 rows; the preview reads the first 50.
						'maxItems' => 1000,
						'required' => true,
					],
				],
			]
		);
	}

	/**
	 * Whether the current user may edit the requested post of a supported type.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function can_edit( WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post instanceof WP_Post || ! Post_Types::is_supported( (string) $post->post_type ) ) {
			return new WP_Error( 'rest_post_invalid_id', __( 'Invalid post ID.', 'hierarchy-guard' ), [ 'status' => 404 ] );
		}
		if ( ! current_user_can( 'edit_post', (int) $post->ID ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to edit this post.', 'hierarchy-guard' ), [ 'status' => rest_authorization_required_code() ] );
		}
		return true;
	}

	/**
	 * GET /posts/{id}/hierarchy.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function hierarchy( WP_REST_Request $request ): WP_REST_Response {
		$post = get_post( (int) $request['id'] );
		return new WP_REST_Response( $this->overview->describe( $post, (int) $request['children_page'] ) );
	}

	/**
	 * GET /posts/{id}/pairs: the redirects an old path recorded on the post needs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function pairs( WP_REST_Request $request ) {
		$collected = $this->collected( $request );
		if ( $collected instanceof WP_Error ) {
			return $collected;
		}
		return new WP_REST_Response( $this->pairs->page( $collected, (int) $request['page'], (int) $request['per_page'] ) );
	}

	/**
	 * GET /posts/{id}/csv: the same pairs as a download.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function csv( WP_REST_Request $request ) {
		$collected = $this->collected( $request );
		if ( $collected instanceof WP_Error ) {
			return $collected;
		}
		return Csv_Download::response( (string) $request['format'], $this->pairs->all( $collected ) );
	}

	/**
	 * GET /trash-preview: what trashing each of the given pages would do to its subpages.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function trash_preview( WP_REST_Request $request ): WP_REST_Response {
		$cap    = Subtree::count_cap();
		$ids    = array_values( array_unique( array_map( 'absint', (array) $request['ids'] ) ) );
		$more   = max( 0, count( $ids ) - self::MAX_PREVIEW );
		$ids    = array_slice( $ids, 0, self::MAX_PREVIEW );
		$pages  = [];
		$total  = 0;
		$capped = $more > 0;
		// The trash moves at most one batch inline across every page it trashes; check no more.
		$checks = Operations::threshold();

		_prime_post_caches( $ids, false, false );
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof WP_Post || 'trash' === $post->post_status || ! Post_Types::is_supported( (string) $post->post_type ) || ! current_user_can( 'delete_post', $id ) ) {
				continue;
			}
			$type     = (string) $post->post_type;
			$children = Subtree::count_live_children( $id, $type, $cap + 1 );
			if ( 0 === $children ) {
				continue;
			}

			// Every page shares one budget, so a large selection stays one capped walk.
			$budget      = max( 1, $cap - $total );
			$descendants = Subtree::count_live_descendants( $id, $type, $budget );
			$total      += min( $descendants, $budget );
			$capped      = $capped || $descendants > $budget;

			$pages[] = $this->preview_page( $post, $children, $descendants, $budget, $checks );
		}

		return new WP_REST_Response(
			[
				'pages'  => $pages,
				'more'   => $more,
				'total'  => [
					'count'  => min( $total, $cap ),
					'capped' => $capped || $total > $cap,
				],
				'policy' => Settings::trash_policy(),
				'cap'    => $cap,
			]
		);
	}

	/**
	 * The preview of one page with subpages.
	 *
	 * @param WP_Post $post        Page.
	 * @param int     $children    Live children, up to the cap plus one.
	 * @param int     $descendants Live descendants, up to the budget plus one.
	 * @param int     $budget      The cap the descendants were counted against.
	 * @param int     $checks      Capability checks left for this request; reduced here.
	 * @return array<string,mixed>
	 */
	private function preview_page( WP_Post $post, int $children, int $descendants, int $budget, int &$checks ): array {
		$id   = (int) $post->ID;
		$type = (string) $post->post_type;
		$cap  = Subtree::count_cap();
		$to   = Subtree::nearest_live_ancestor( $post );
		$path = Paths::url( $type, Paths::untrashed_uri( $post ) );

		// The trash leaves a subpage the user can't edit (moving) or delete (trashing) where it is.
		$checked = $checks > 0 ? Subtree::live_children( $id, $type, $checks ) : [];
		$checks -= count( $checked );
		_prime_post_caches( $checked, false, false );
		$denied       = false;
		$denied_trash = false;
		foreach ( $checked as $child_id ) {
			$denied       = $denied || ! current_user_can( 'edit_post', $child_id );
			$denied_trash = $denied_trash || ! current_user_can( 'delete_post', $child_id );
		}

		// The first published subpage shows what an URL change looks like; a draft has no URL to change.
		$shown   = null;
		$example = null;
		foreach ( [] !== $checked ? $checked : Subtree::live_children( $id, $type, 1 ) as $candidate ) {
			$child = get_post( $candidate );
			if ( $child instanceof WP_Post && 'publish' === $child->post_status && '' !== $child->post_name ) {
				$shown = $child;
				break;
			}
		}
		if ( null !== $shown && '' !== $path ) {
			$slug    = (string) $shown->post_name;
			$base    = $to > 0 ? Paths::uri( get_post( $to ) ) . '/' : '';
			$example = [
				'from' => Paths::relative( Paths::url( $type, Paths::untrashed_uri( $post ) . '/' . $slug ) ),
				'to'   => Paths::relative( Paths::url( $type, $base . $slug ) ),
			];
		}

		if ( $descendants <= $budget ) {
			$fallback = $descendants > $cap;
		} else {
			// Counted against what was left of the shared budget, so only a full cap can tell.
			$fallback = $budget >= $cap ? true : null;
		}

		return [
			'id'           => $id,
			'title'        => Overview::title( $id ),
			'path'         => '' !== $path ? Paths::relative( $path ) : '',
			'policy'       => Trash_Handler::policy_for( $post ),
			'children'     => min( $children, $cap ),
			'descendants'  => [
				'count'  => min( $descendants, $budget ),
				'capped' => $descendants > $budget,
			],
			'fallback'     => $fallback,
			'to'           => $to > 0 ? Overview::ref( $to ) : null,
			'example'      => $example,
			'denied'       => $denied,
			'denied_trash' => $denied_trash,
		];
	}

	/**
	 * The pairs behind the request's old path, if it is recorded on the post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array<string,mixed>|WP_Error
	 */
	private function collected( WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		$from = Paths::normalize( (string) $request['from'] );
		if ( '' === $from || ! in_array( $from, $this->ledger->paths_for( (int) $post->ID ), true ) ) {
			return new WP_Error( 'hierarchy_guard_unknown_path', __( 'This old URL is not recorded for this page.', 'hierarchy-guard' ), [ 'status' => 404 ] );
		}
		return $this->pairs->collect_post( $post, $from );
	}
}
