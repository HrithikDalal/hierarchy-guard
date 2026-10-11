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
use HierarchyGuard\Subtree;
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

	public const NAMESPACE = 'hierarchy-guard/v1';

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
