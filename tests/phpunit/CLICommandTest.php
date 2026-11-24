<?php
/**
 * WP-CLI output lines.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

use HierarchyGuard\Batch\CLI_Command;
use HierarchyGuard\Batch\Processor;
use HierarchyGuard\Batch\Queue;
use HierarchyGuard\Ledger;
use HierarchyGuard\Trash\Change_Log;
use HierarchyGuard\Trash\Mover;
use HierarchyGuard\Trash\Operations;
use WP_CLI;
use WP_UnitTestCase;

/**
 * @covers \HierarchyGuard\Batch\CLI_Command
 */
final class CLICommandTest extends WP_UnitTestCase {

	/**
	 * A job with counts.
	 *
	 * @param string            $type   Job type.
	 * @param int               $to     New parent.
	 * @param array<string,int> $counts Counts.
	 * @return array<string,mixed>
	 */
	private function job( string $type, int $to, array $counts ): array {
		$job           = Operations::new_job( $type, 12, 'page', 0, [ 'to' => $to ] );
		$job['counts'] = array_merge( $job['counts'], $counts );
		return $job;
	}

	public function test_describes_each_kind_of_job(): void {
		$this->assertSame( 'Page 12: moved 1,200 subpages up under page 7.', CLI_Command::describe( $this->job( Operations::TYPE_REPARENT, 7, [ 'moved' => 1200 ] ) ) );
		$this->assertSame( 'Page 12: moved 1 subpage to the top level.', CLI_Command::describe( $this->job( Operations::TYPE_REPARENT, 0, [ 'moved' => 1 ] ) ) );
		$this->assertSame( 'Page 12: trashed 40 subpages.', CLI_Command::describe( $this->job( Operations::TYPE_TRASH, 0, [ 'trashed' => 40 ] ) ) );
		$this->assertSame( 'Page 12: moved 4 subpages back.', CLI_Command::describe( $this->job( Operations::TYPE_RESTORE, 0, [ 'moved' => 4 ] ) ) );
		$this->assertSame( 'Page 12: restored 3 subpages from the trash.', CLI_Command::describe( $this->job( Operations::TYPE_RESTORE, 0, [ 'restored' => 3 ] ) ) );
		$this->assertSame(
			'Page 12: moved 4 subpages back and restored 3 from the trash.',
			CLI_Command::describe(
				$this->job(
					Operations::TYPE_RESTORE,
					0,
					[
						'moved'    => 4,
						'restored' => 3,
					]
				)
			)
		);
		$this->assertSame( 'Page 12: cleaned up after the deleted page.', CLI_Command::describe( $this->job( Operations::TYPE_CLEANUP, 0, [] ) ) );
	}

	/**
	 * In a process of its own, so the stub WP-CLI it loads never reaches
	 * another test.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_row_that_does_not_decode_is_dropped_and_drained_as_no_job(): void {
		global $wpdb;
		require_once __DIR__ . '/stubs/wp-cli.php';
		$wpdb->insert(
			$wpdb->options,
			[
				'option_name'  => Queue::job_option( 10 ),
				'option_value' => 'a:1:{s:4:"root";',
				'autoload'     => 'off',
			]
		);
		$queue   = new Queue();
		$command = new CLI_Command( $queue, new Processor( $queue, new Operations( new Ledger(), new Mover() ), new Change_Log() ) );

		$command->process_jobs( [], [ 'sleep' => '0' ] );

		$this->assertSame( [ 'Success: No queued jobs.' ], WP_CLI::$lines, 'A root with nothing to step is not a job, and the drain stops at once.' );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Queue::job_option( 10 ) ) ), 'The row is dropped.' );
	}
}
