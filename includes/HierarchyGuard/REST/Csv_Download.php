<?php
/**
 * Serves a CSV body from a REST route.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\REST;

use HierarchyGuard\Export\Csv;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The REST server encodes every response as JSON. A CSV route returns its
 * body as a string flagged with a header, and this class prints it as is.
 * Download links are the one place the plugin sends a file.
 */
final class Csv_Download {

	public const FLAG = 'X-Hierarchy-Guard-Csv';

	/**
	 * Hooks into core: before core's `_fields` filter, which runs at priority
	 * 10 of rest_post_dispatch, and after core's own CORS headers, which run at
	 * priority 10 of rest_pre_serve_request.
	 */
	public function register(): void {
		add_filter( 'rest_post_dispatch', [ $this, 'drop_fields' ], 9, 3 );
		add_filter( 'rest_pre_serve_request', [ $this, 'serve' ], 11, 3 );
	}

	/**
	 * Ignores `_fields` on a CSV response: core would filter the body as an
	 * array of fields, and the body is a string.
	 *
	 * @param mixed $result  The response.
	 * @param mixed $server  The REST server.
	 * @param mixed $request The request.
	 * @return mixed The response, unchanged.
	 */
	public function drop_fields( $result, $server, $request ) {
		if ( $result instanceof WP_HTTP_Response && $request instanceof WP_REST_Request && ! empty( $result->get_headers()[ self::FLAG ] ) ) {
			unset( $request['_fields'] );
		}
		return $result;
	}

	/**
	 * A CSV response for pairs in a layout.
	 *
	 * @param string                                  $layout Layout name.
	 * @param array<int,array{old:string,new:string}> $pairs  Pairs.
	 * @return WP_REST_Response
	 */
	public static function response( string $layout, array $pairs ): WP_REST_Response {
		$response = new WP_REST_Response( Csv::build( $layout, $pairs ) );
		$response->header( 'Content-Type', 'text/csv; charset=utf-8' );
		$response->header( 'Content-Disposition', 'attachment; filename="' . Csv::filename( $layout ) . '"' );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( self::FLAG, '1' );
		return $response;
	}

	/**
	 * Prints a flagged CSV body instead of letting the server JSON-encode it.
	 *
	 * @param mixed $served  Whether the request was served already.
	 * @param mixed $result  The response.
	 * @param mixed $request The request.
	 * @return mixed
	 */
	public function serve( $served, $result, $request ) {
		if ( $served || ! $result instanceof WP_HTTP_Response || ! $request instanceof WP_REST_Request ) {
			return $served;
		}
		$headers = $result->get_headers();
		if ( empty( $headers[ self::FLAG ] ) || 200 !== $result->get_status() || ! is_string( $result->get_data() ) ) {
			return $served;
		}
		if ( 'HEAD' !== $request->get_method() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A text/csv body built by Csv::build(), sent with nosniff by the REST server.
			echo $result->get_data();
		}
		return true;
	}
}
