<?php
/**
 * REST routes about the current user's last change.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\REST;

use HierarchyGuard\Export\Csv;
use HierarchyGuard\Redirects\Pairs;
use HierarchyGuard\Subtree;
use HierarchyGuard\Trash\Change_Log;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Backs the "View redirects" link of the outcome notice, after a trash or a
 * save that changed URLs. A change is the current user's own and lives for
 * thirty minutes, so these routes read nothing another user wrote.
 */
final class Changes_Controller {

	/**
	 * Change log.
	 *
	 * @var Change_Log
	 */
	private Change_Log $log;

	/**
	 * Pairs.
	 *
	 * @var Pairs
	 */
	private Pairs $pairs;

	/**
	 * Constructor.
	 *
	 * @param Change_Log $log   Change log.
	 * @param Pairs      $pairs Pairs.
	 */
	public function __construct( Change_Log $log, Pairs $pairs ) {
		$this->log   = $log;
		$this->pairs = $pairs;
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
			'type'     => 'string',
			'format'   => 'uuid',
			'required' => true,
		];

		register_rest_route(
			Hierarchy_Controller::NAMESPACE,
			'/changes/(?P<id>[0-9a-f-]{36})/pairs',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'pairs' ],
				'permission_callback' => 'is_user_logged_in',
				'args'                => [
					'id'       => $id,
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
			Hierarchy_Controller::NAMESPACE,
			'/changes/(?P<id>[0-9a-f-]{36})/csv',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'csv' ],
				'permission_callback' => 'is_user_logged_in',
				'args'                => [
					'id'     => $id,
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
	 * GET /changes/{id}/pairs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function pairs( WP_REST_Request $request ) {
		$collected = $this->collected( (string) $request['id'] );
		if ( $collected instanceof WP_Error ) {
			return $collected;
		}
		return new WP_REST_Response( $this->pairs->page( $collected, (int) $request['page'], (int) $request['per_page'] ) );
	}

	/**
	 * GET /changes/{id}/csv.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function csv( WP_REST_Request $request ) {
		$collected = $this->collected( (string) $request['id'] );
		if ( $collected instanceof WP_Error ) {
			return $collected;
		}
		return Csv_Download::response( (string) $request['format'], $this->pairs->all( $collected ) );
	}

	/**
	 * The pairs of the current user's change with this ID. When the change is
	 * gone, the message says a later change replaced it only when the user
	 * has one; a change that expired, or that the cache dropped, has nothing
	 * newer. Its list is gone too once a page it lists redirects for is
	 * deleted for good: the page a save moved or renamed, or a trashed parent
	 * whose subpages moved up.
	 *
	 * @param string $change_id Change ID.
	 * @return array<string,mixed>|WP_Error
	 */
	private function collected( string $change_id ) {
		$change = $this->log->latest( get_current_user_id() );
		if ( null === $change || $change_id !== $change['id'] ) {
			$minutes  = (int) ( Change_Log::TTL / MINUTE_IN_SECONDS );
			$message  = null !== $change ? __( 'A later change replaced them.', 'hierarchy-guard' ) . ' ' : '';
			$message .= sprintf(
				/* translators: %s: number of minutes. */
				_n( 'They are kept for %s minute.', 'They are kept for %s minutes.', $minutes, 'hierarchy-guard' ),
				number_format_i18n( $minutes )
			);
			return self::gone( $message );
		}

		$collected = $this->pairs->collect_change( $change );
		if ( ! empty( $collected['gone'] ) ) {
			/* translators: Follows "These redirects are no longer available."; "they" are those redirects. */
			return self::gone( __( 'A page they belong to was deleted for good.', 'hierarchy-guard' ) );
		}
		return $collected;
	}

	/**
	 * The answer for redirects that are no longer available, and why.
	 *
	 * @param string $why What happened to them.
	 * @return WP_Error
	 */
	private static function gone( string $why ): WP_Error {
		return new WP_Error( 'hierarchy_guard_change_gone', __( 'These redirects are no longer available.', 'hierarchy-guard' ) . ' ' . $why, [ 'status' => 404 ] );
	}
}
