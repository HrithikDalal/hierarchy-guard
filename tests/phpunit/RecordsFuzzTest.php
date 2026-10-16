<?php
/**
 * Seeded random record sets against the server: what each Redirects tab row
 * reads is what the server does at its old URL, with a second hierarchical
 * type whose rewrite base page entries can start with.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

// phpcs:disable WordPress.WP.AlternativeFunctions.rand_mt_rand,WordPress.WP.AlternativeFunctions.rand_seeding_mt_srand -- A seeded set must replay from its seed.

use HierarchyGuard\Ledger;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Records;
use HierarchyGuard\Redirects\Resolver;
use HierarchyGuard\Redirects\Rules;
use HierarchyGuard\Redirects\Server;
use HierarchyGuard\Settings;
use WP_Post;
use WP_UnitTestCase;

/**
 * Each set starts from Company › About › { Team › Leadership, History }, a
 * top-level Solo, a draft, and a `docs` type with Guide › A. It records ten
 * random paths, drawn from segments that include the type's base, as page
 * entries, docs entries or root-list entries; gives some rows a URL target
 * off the site or on it (another recorded path), or a chosen code; may add a
 * page at a recorded path, and may put a page in the Trash, raw or through
 * core. Then, for every row on the first page:
 *
 * - Active: the server serves its old URL; with a URL target, to that URL;
 *   with a chosen code, with that code.
 * - Not active: the server does not serve the row's own URL target there,
 *   and an unpublished or refused row is not served at all, unless another
 *   record holds the same old URL, its own holder is in the Trash, the
 *   server's model can't tell what WordPress shows there (then the row
 *   reads refused), or the redirect goes round back to that old URL.
 * - In use: the old URL is not a 404 that nothing answers.
 * - Live vs batch: where the 404 model tells, Rules::is_404() on live
 *   lookups says what the real main query says.
 *
 * HG_FUZZ_SEEDS runs more sets. Reproduce one: --filter 'RecordsFuzzTest.*seed 17"'.
 *
 * @covers \HierarchyGuard\Redirects\Records
 * @covers \HierarchyGuard\Redirects\Rules
 * @covers \HierarchyGuard\Redirects\Batch_Resolver
 */
final class RecordsFuzzTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	/**
	 * Sets run.
	 */
	private const SEEDS = 40;

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		delete_option( Ledger::ROOT_OPTION );
		delete_option( Ledger::ROOT_TARGETS );
		delete_option( Ledger::RECENT );
		update_option( Settings::OPTION, [ 'serve_redirects' => true ] );
		add_filter( 'hierarchy_guard_trash_policy', static fn(): string => Settings::POLICY_KEEP );
		// A type adds its rewrite rules only once the site has pretty permalinks.
		$this->set_permalink_structure( '/%postname%/' );
		register_post_type(
			'hg_docs',
			[
				'public'       => true,
				'hierarchical' => true,
				'rewrite'      => [ 'slug' => 'docs' ],
			]
		);
		Post_Types::reset();
		flush_rewrite_rules( false );
	}

	public function tear_down(): void {
		unregister_post_type( 'hg_docs' );
		Post_Types::reset();
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	/**
	 * The seeds, one set each.
	 *
	 * @return array<string,array{int}>
	 */
	public function seeds(): array {
		$count = (int) getenv( 'HG_FUZZ_SEEDS' );
		$count = $count > 0 ? $count : self::SEEDS;
		$out   = [];
		for ( $seed = 1; $seed <= $count; $seed++ ) {
			$out[ 'seed ' . $seed ] = [ $seed ];
		}
		return $out;
	}

	/**
	 * One seeded set.
	 *
	 * @dataProvider seeds
	 *
	 * @param int $seed Seed for mt_rand().
	 */
	public function test_each_row_reads_what_the_server_does( int $seed ): void {
		mt_srand( $seed );
		$ledger  = new Ledger();
		$records = new Records( $ledger );
		$ids     = $this->make_tree();
		$solo    = $this->page( 'solo' );
		$pages   = array_merge( array_values( $ids ), [ $solo, $this->page( 'dr', 0, 'draft' ) ] );
		$guide   = self::factory()->post->create(
			[
				'post_type' => 'hg_docs',
				'post_name' => 'guide',
			]
		);
		$docs    = [
			$guide,
			self::factory()->post->create(
				[
					'post_type'   => 'hg_docs',
					'post_name'   => 'a',
					'post_parent' => $guide,
				]
			),
		];
		$story   = [];
		$paths   = [];
		$segs    = [ 'a', 'b', 'docs', 'about', 'guide', 'company', 'solo', 'x' ];
		for ( $i = 0; $i < 10; $i++ ) {
			$path = [];
			for ( $j = mt_rand( 1, 3 ); $j > 0; $j-- ) {
				$path[] = $segs[ mt_rand( 0, count( $segs ) - 1 ) ];
			}
			$paths[] = implode( '/', $path );
		}
		foreach ( $paths as $path ) {
			$pick = mt_rand( 0, 9 );
			if ( $pick < 2 ) {
				$type = mt_rand( 0, 1 ) ? 'page' : 'hg_docs';
				$ledger->record_root( $type, $path );
				$story[] = "root $type $path";
			} else {
				$holder = $pick < 5 ? $docs[ mt_rand( 0, 1 ) ] : $pages[ mt_rand( 0, count( $pages ) - 1 ) ];
				$ledger->record( $holder, $path );
				$story[] = "record $holder $path";
			}
		}
		foreach ( $records->page( '', '', 1 )['rows'] as $row ) {
			$pick = mt_rand( 0, 9 );
			if ( $pick < 2 ) {
				$ledger->set_target( $row['post_type'], $row['path'], $row['holder'], 'https://example.net/' . $row['path'] . '/', [ 0, 301, 302 ][ mt_rand( 0, 2 ) ] );
				$story[] = "off-site {$row['id']}";
			} elseif ( $pick < 3 ) {
				$to = $paths[ mt_rand( 0, count( $paths ) - 1 ) ];
				$ledger->set_target( $row['post_type'], $row['path'], $row['holder'], home_url( '/' . $to . '/' ), 0 );
				$story[] = "on-site {$row['id']} -> $to";
			} elseif ( $pick < 4 ) {
				$ledger->set_target( $row['post_type'], $row['path'], $row['holder'], '', 302 );
				$story[] = "code {$row['id']}";
			}
		}
		if ( 0 === mt_rand( 0, 2 ) ) {
			$slug   = [ 'a', 'b', 'x', 'c' ][ mt_rand( 0, 3 ) ];
			$status = [ 'publish', 'private', 'draft' ][ mt_rand( 0, 2 ) ];
			$this->page( $slug, 0, $status );
			$story[] = "page $slug $status";
		}
		if ( 0 === mt_rand( 0, 2 ) ) {
			$gone = $pages[ mt_rand( 0, count( $pages ) - 1 ) ];
			$this->trash_raw( $gone );
			$story[] = "trash_raw $gone";
		}
		if ( 0 === mt_rand( 0, 2 ) ) {
			$gone = $pages[ mt_rand( 0, count( $pages ) - 1 ) ];
			wp_trash_post( $gone );
			$story[] = "trash $gone";
		}
		wp_cache_flush();

		$rows     = $records->page( '', '', 1 )['rows'];
		$statuses = $records->statuses( $rows );
		$problems = [];
		$under    = 'publish' === get_post_status( $solo );
		foreach ( $rows as $row ) {
			$status   = (string) ( $statuses[ $row['id'] ] ?? '' );
			$problems = array_merge( $problems, $this->check( $ledger, $row, $status, $under ) );
		}

		$this->assertSame( [], $problems, "seed $seed:\n  " . implode( "\n  ", $story ) );
	}

	/**
	 * What is wrong with a row's status, as the server and WordPress answer
	 * its old URL.
	 *
	 * @param Ledger              $ledger Ledger.
	 * @param array<string,mixed> $row    Row.
	 * @param string              $status Its status.
	 * @param bool                $solo   Whether the top-level page Solo is published, for the URLs under a top-level root-list entry.
	 * @return string[]
	 */
	private function check( Ledger $ledger, array $row, string $status, bool $solo ): array {
		global $wpdb;

		$top = Records::is_top_level( $row );
		if ( $top && ! $solo ) {
			return [];
		}
		$old               = (string) $row['old'];
		[ $is_404, $sent ] = $this->request( $ledger, $top ? $old . 'solo/' : $old );
		$url               = null !== $row['target'] ? (string) $row['target']['url'] : '';
		$code              = null !== $row['target'] ? (int) $row['target']['code'] : 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A test oracle's point lookup.
		$holders = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", Ledger::key( (string) $row['post_type'], (string) $row['path'] ) ) );
		$shared  = $holders + ( in_array( $row['path'], (array) ( get_option( Ledger::ROOT_OPTION )[ $row['post_type'] ] ?? [] ), true ) ? 1 : 0 ) > 1;
		$trashed = 0 !== (int) $row['holder'] && 'trash' === get_post_status( (int) $row['holder'] );
		$route   = Rules::route( (string) $row['post_type'], (string) $row['path'] );
		$at      = get_page_by_path( (string) $row['path'], OBJECT, (string) $row['post_type'] );
		$tells   = Rules::tells( $route, $at instanceof WP_Post ? $at : null );
		$name    = "$old h{$row['holder']} ($status)";
		$out     = [];

		if ( ! $top && $tells && Live_Model::is_404( (string) $row['path'], (string) $row['post_type'] ) !== $is_404 ) {
			$out[] = "$name: the 404 model says " . ( $is_404 ? 'shown' : '404' ) . ', the main query the other.';
		}
		if ( $top ) {
			return $out;
		}
		if ( Records::STATUS_ACTIVE === $status ) {
			if ( null === $sent ) {
				$out[] = "$name: Active, but nothing is sent.";
			} elseif ( '' !== $url && $sent['url'] !== $url ) {
				$out[] = "$name: Active, but sent to {$sent['url']}, not its URL target.";
			} elseif ( $code > 0 && $sent['status'] !== $code ) {
				$out[] = "$name: Active with code $code, but sent with {$sent['status']}.";
			}
			return $out;
		}
		if ( $shared || $trashed ) {
			return $out;
		}
		$round = null !== $sent && $this->goes_round( $ledger, $old, (string) $sent['url'] );
		if ( '' !== $url && null !== $sent && $sent['url'] === $url && ! $round ) {
			$out[] = "$name: sent to its own URL target.";
		}
		if ( in_array( $status, [ Records::STATUS_UNPUBLISHED, Records::STATUS_REFUSED ], true ) && null !== $sent && $tells && ! $round ) {
			$out[] = "$name: sent to {$sent['url']}.";
		}
		if ( Records::STATUS_IN_USE === $status && $is_404 && null === $sent ) {
			$out[] = "$name: a 404 nothing answers.";
		}
		return $out;
	}

	/**
	 * Whether the server, sent on from a URL, comes back to an old URL within
	 * Rules::MAX_ROUNDS redirects.
	 *
	 * @param Ledger $ledger Ledger.
	 * @param string $old    Old URL relative to the home URL.
	 * @param string $url    The URL the server sent the old URL to.
	 * @return bool
	 */
	private function goes_round( Ledger $ledger, string $old, string $url ): bool {
		for ( $hop = 0; $hop < Rules::MAX_ROUNDS && '' !== $url; $hop++ ) {
			if ( ! Server::is_here( $url ) ) {
				return false;
			}
			if ( Server::same_path( $url, home_url( $old ) ) ) {
				return true;
			}
			$this->go_to( $url );
			$sent = ( new Server( new Resolver( $ledger ) ) )->target_for_current_request();
			$url  = null !== $sent ? (string) $sent['url'] : '';
		}
		return false;
	}

	/**
	 * A request for an old URL: whether it is a 404, and what the server sends.
	 *
	 * @param Ledger $ledger Ledger.
	 * @param string $old    Old URL relative to the home URL.
	 * @return array{0:bool,1:array{url:string,status:int}|null}
	 */
	private function request( Ledger $ledger, string $old ): array {
		$this->go_to( home_url( implode( '/', array_map( 'rawurlencode', explode( '/', $old ) ) ) ) );
		return [ is_404(), ( new Server( new Resolver( $ledger ) ) )->target_for_current_request() ];
	}
}
