<?php
/**
 * The facts a batch of old paths is resolved on.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Redirects\Batch_Resolver;
use HierarchyGuard\Redirects\Prefetched_View;
use InvalidArgumentException;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers \HierarchyGuard\Redirects\Prefetched_View
 * @covers \HierarchyGuard\Redirects\Batch_Resolver
 */
final class BatchResolverTest extends TestCase {

	public function test_a_view_answers_for_its_own_type_only(): void {
		$facts = [];
		$view  = new Prefetched_View( 'page', $facts );

		$this->expectException( InvalidArgumentException::class );
		$view->find( 'hg_docs', 'company' );
	}

	public function test_a_path_shown_and_a_path_not_looked_up_are_told_apart(): void {
		$this->assertNotSame( Batch_Resolver::NOT_404, Batch_Resolver::UNKNOWN );
	}
}
