<?php
/**
 * Re-entrancy guard.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Trash\Guard;
use RuntimeException;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Trash\Guard
 */
final class GuardTest extends WP_UnitTestCase {

	public function test_run_covers_only_the_given_ids_and_returns_the_callback_value(): void {
		$seen   = [];
		$result = Guard::run(
			[ 5, 6 ],
			static function () use ( &$seen ): int {
				$seen = [ Guard::covers( 5 ), Guard::covers( 6 ), Guard::covers( 7 ) ];
				return 42;
			}
		);

		$this->assertSame( 42, $result );
		$this->assertSame( [ true, true, false ], $seen );
		$this->assertFalse( Guard::covers( 5 ) );
	}

	public function test_nested_runs_release_an_id_only_at_the_outermost_level(): void {
		$inner = null;
		Guard::run(
			[ 5 ],
			static function () use ( &$inner ): void {
				Guard::run( [ 5 ], static fn() => null );
				$inner = Guard::covers( 5 );
			}
		);

		$this->assertTrue( $inner, 'The outer run still covers the ID after an inner one returns.' );
		$this->assertFalse( Guard::covers( 5 ) );
	}

	public function test_an_exception_releases_the_ids(): void {
		try {
			Guard::run(
				[ 5 ],
				static function (): void {
					throw new RuntimeException( 'boom' );
				}
			);
			$this->fail( 'The exception must reach the caller.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}

		$this->assertFalse( Guard::covers( 5 ) );
	}
}
