<?php
/**
 * REST routes about the current user's last trash.
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
 * Backs the "View redirects" link of the outcome notice. A change is the
 * current user's own and lives for thirty minutes, so these routes read
 * nothing another user wrote.
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
		$change = $this->change( (string) $request['id'] );
		if ( $change instanceof WP_Error ) {
			return $change;
		}
		return new WP_REST_Response( $this->pairs->page( $this->pairs->collect_change( $change ), (int) $request['page'], (int) $request['per_page'] ) );
	}

	/**
	 * GET /changes/{id}/csv.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function csv( WP_REST_Request $request ) {
		$change = $this->change( (string) $request['id'] );
		if ( $change instanceof WP_Error ) {
			return $change;
		}
		return Csv_Download::response( (string) $request['format'], $this->pairs->all( $this->pairs->collect_change( $change ) ) );
	}

	/**
	 * The current user's change with this ID.
	 *
	 * @param string $change_id Change ID.
	 * @return array<string,mixed>|WP_Error
	 */
	private function change( string $change_id ) {
		$change = $this->log->latest( get_current_user_id() );
		if ( null === $change || $change_id !== $change['id'] ) {
			$minutes = (int) ( Change_Log::TTL / MINUTE_IN_SECONDS );
			$message = __( 'These redirects are no longer available.', 'hierarchy-guard' ) . ' ' . sprintf(
				/* translators: %s: number of minutes. */
				_n( 'They are kept for %s minute.', 'They are kept for %s minutes.', $minutes, 'hierarchy-guard' ),
				number_format_i18n( $minutes )
			);
			return new WP_Error( 'hierarchy_guard_change_gone', $message, [ 'status' => 404 ] );
		}
		return $change;
	}
}
