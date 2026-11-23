<?php
/**
 * The container YoastSEO() hands out in tests.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use RuntimeException;
use Yoast\WP\SEO\Integrations\Watchers\Indexable_Post_Watcher;

/**
 * Hands out one stub watcher, the only service the compat class asks for.
 */
final class Yoast_Container {

	/**
	 * The watcher.
	 *
	 * @var Indexable_Post_Watcher
	 */
	private Indexable_Post_Watcher $watcher;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->watcher = new Indexable_Post_Watcher();
	}

	/**
	 * The service of a class.
	 *
	 * @param string $name Class name.
	 * @return Indexable_Post_Watcher
	 * @throws RuntimeException For any other class.
	 */
	public function get( string $name ): Indexable_Post_Watcher {
		if ( Indexable_Post_Watcher::class !== $name ) {
			throw new RuntimeException( 'No such service.' );
		}
		return $this->watcher;
	}
}
