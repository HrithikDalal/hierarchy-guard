<?php
/**
 * WP-CLI access to the batch queue.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Batch;

use HierarchyGuard\Trash\Operations;

/**
 * Manages Hierarchy Guard background jobs.
 */
final class CLI_Command {

	/**
	 * Queue.
	 *
	 * @var Queue
	 */
	private Queue $queue;

	/**
	 * Processor.
	 *
	 * @var Processor
	 */
	private Processor $processor;

	/**
	 * Constructor.
	 *
	 * @param Queue     $queue     Queue.
	 * @param Processor $processor Processor.
	 */
	public function __construct( Queue $queue, Processor $processor ) {
		$this->queue     = $queue;
		$this->processor = $processor;
	}

	/**
	 * Runs every queued job to completion.
	 *
	 * Use this where WP-Cron is disabled or only runs on traffic.
	 *
	 * ## OPTIONS
	 *
	 * [--sleep=<milliseconds>]
	 * : Pause between batches, to spare database replicas.
	 * ---
	 * default: 250
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp hierarchy-guard process-jobs
	 *     wp hierarchy-guard process-jobs --sleep=0
	 *
	 * @subcommand process-jobs
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 */
	public function process_jobs( array $args, array $assoc_args ): void {
		$pause     = max( 0, (int) ( $assoc_args['sleep'] ?? 250 ) );
		$processed = 0;
		$skipped   = [];

		for ( $pass = 0; $pass < 100; $pass++ ) {
			$roots = array_diff( $this->queue->roots(), $skipped );
			if ( [] === $roots ) {
				break;
			}
			foreach ( $roots as $root_id ) {
				$result = $this->drain( (int) $root_id, $pause );
				if ( 'busy' === $result['state'] ) {
					\WP_CLI::log( sprintf( 'Page %d: skipped, another process is working on it.', $root_id ) );
					$skipped[] = $root_id;
					continue;
				}
				if ( null !== $result['job'] ) {
					\WP_CLI::log( self::describe( $result['job'] ) );
				}
				++$processed;
			}
		}

		if ( 0 === $processed && [] === $skipped ) {
			\WP_CLI::success( 'No queued jobs.' );
			return;
		}
		\WP_CLI::success( 1 === $processed ? 'Processed 1 job.' : sprintf( 'Processed %d jobs.', $processed ) );
	}

	/**
	 * One line about a finished job.
	 *
	 * @param array<string,mixed> $job Job.
	 * @return string
	 */
	public static function describe( array $job ): string {
		$root   = (int) $job['root'];
		$counts = (array) $job['counts'];

		switch ( $job['type'] ) {
			case Operations::TYPE_REPARENT:
				if ( (int) $job['to'] > 0 ) {
					return sprintf( 'Page %d: moved %s up under page %d.', $root, self::subpages( (int) $counts['moved'] ), (int) $job['to'] );
				}
				return sprintf( 'Page %d: moved %s to the top level.', $root, self::subpages( (int) $counts['moved'] ) );
			case Operations::TYPE_TRASH:
				return sprintf( 'Page %d: trashed %s.', $root, self::subpages( (int) $counts['trashed'] ) );
			case Operations::TYPE_RESTORE:
				if ( (int) $counts['restored'] > 0 && 0 === (int) $counts['moved'] ) {
					return sprintf( 'Page %d: restored %s from the trash.', $root, self::subpages( (int) $counts['restored'] ) );
				}
				if ( (int) $counts['restored'] > 0 ) {
					return sprintf( 'Page %d: moved %s back and restored %s from the trash.', $root, self::subpages( (int) $counts['moved'] ), number_format( (int) $counts['restored'] ) );
				}
				return sprintf( 'Page %d: moved %s back.', $root, self::subpages( (int) $counts['moved'] ) );
		}
		return sprintf( 'Page %d: cleaned up after the deleted page.', $root );
	}

	/**
	 * "1 subpage", "1,200 subpages".
	 *
	 * @param int $count Count.
	 * @return string
	 */
	public static function subpages( int $count ): string {
		return number_format( $count ) . ( 1 === $count ? ' subpage' : ' subpages' );
	}

	/**
	 * Runs a root's job until it is done or another process holds it.
	 *
	 * @param int $root_id Root page.
	 * @param int $pause   Pause between steps, in milliseconds.
	 * @return array{state:string,job:array<string,mixed>|null}
	 */
	private function drain( int $root_id, int $pause ): array {
		$result = $this->processor->run( $root_id, 0, $pause );
		for ( $i = 0; $i < 1000 && 'queued' === $result['state']; $i++ ) {
			$result = $this->processor->run( $root_id, 0, $pause );
		}
		return $result;
	}
}
