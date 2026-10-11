<?php
/**
 * Seeded random histories against the resolver: every pair listed is one the
 * site serves, and every old URL the site serves to a page is listed somewhere.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

// phpcs:disable WordPress.WP.AlternativeFunctions.rand_mt_rand,WordPress.WP.AlternativeFunctions.rand_seeding_mt_srand -- A seeded history must replay from its seed.

use HierarchyGuard\Batch\Queue;
use HierarchyGuard\Ledger;
use HierarchyGuard\Overview;
use HierarchyGuard\Paths;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Live_View;
use HierarchyGuard\Redirects\Pairs;
use HierarchyGuard\Redirects\Providers;
use HierarchyGuard\Redirects\Resolver;
use HierarchyGuard\Redirects\Rules;
use HierarchyGuard\Redirects\Server;
use HierarchyGuard\Settings;
use HierarchyGuard\Trash\Change_Log;
use HierarchyGuard\Trash\Markers;
use HierarchyGuard\Trash\Trash_Handler;
use WP_Post;
use WP_UnitTestCase;

/**
 * Each history starts from Company › { About › { Team › Leadership, History },
 * Services } and a top-level Studio, all published, and runs a few random
 * renames, moves, moves with a rename in one save, new pages, trashes,
 * restores and deletes, each in a request of its own and small enough to run
 * inline. It also makes pages drafts or private and publishes them again,
 * adds draft twins (a draft at a published page's path: core keeps a draft's
 * slug as it is), blog posts named as a page's old one-segment path and
 * attachments named as the last segment of an old two-segment path. Then it
 * collects every list the admin screens offer: each live page's pairs for
 * each of its old paths, and the admin's latest change.
 *
 * - Correct: every listed pair's old URL is a 404 the resolver sends to that
 *   pair's page, and a logged-out request for it is redirected there by the
 *   Server on a real main query.
 * - Complete: every URL a live page had at the end of a step, and has no
 *   more, that is a 404 the resolver sends to that page is on some list.
 * - Told: each live page's old URLs read `ok` exactly when the resolver sends
 *   them to that page.
 *
 * The resolver runs only on a 404, so a URL a published page, a blog post or
 * an attachment uses now is served by it and is no redirect at all, on
 * either side. Only a page with no subpage at all is made a draft or private,
 * and nothing is put under one: a published page under a draft (P7, declined:
 * the resolver's prefix walk can stop at the draft) never arises.
 *
 * A served old URL no list has is excluded only when it belongs to one of the
 * engine classes named at excluded(): the ledger, the trash flow or core keep
 * nothing a bounded read of the pairs could list it from. Each exclusion is
 * counted, and printed with its seed when HG_FUZZ_REPORT is set.
 *
 * Reproduce one history: --filter 'PairsFuzzTest.*seed 17"'.
 *
 * @covers \HierarchyGuard\Redirects\Pairs
 * @covers \HierarchyGuard\Redirects\Batch_Resolver
 * @covers \HierarchyGuard\Overview
 */
final class PairsFuzzTest extends WP_UnitTestCase {

	use Trash_Fixtures;

	/**
	 * Histories run.
	 */
	private const SEEDS = 150;

	/**
	 * Steps per history.
	 */
	private const STEPS = 8;

	/**
	 * Slugs a rename or a new page may take, so collisions happen and pages
	 * come to sit at other pages' old paths.
	 */
	private const POOL = [ 'about', 'team', 'history', 'services', 'studio', 'leadership', 'alpha' ];

	/**
	 * Pages the history created, in creation order, labelled.
	 *
	 * @var array<int,string>
	 */
	private array $made = [];

	/**
	 * Every path each page had at the end of a step, oldest first.
	 *
	 * @var array<int,string[]>
	 */
	private array $history = [];

	/**
	 * What the history did, one line per step.
	 *
	 * @var string[]
	 */
	private array $steps = [];

	/**
	 * The step at which each page's history entry was recorded, alongside $history.
	 *
	 * @var array<int,int[]>
	 */
	private array $when = [];

	/**
	 * The step at which each ledger entry of each page first showed up.
	 *
	 * @var array<int,array<string,int>>
	 */
	private array $entered = [];

	/**
	 * Pages deleted for good.
	 *
	 * @var int[]
	 */
	private array $deleted = [];

	/**
	 * The step at which a restore last moved each page back under the page restored.
	 *
	 * @var array<int,int>
	 */
	private array $restored = [];

	private int $step = 0;

	/**
	 * The blog posts and attachments the history added, for the story.
	 *
	 * @var string[]
	 */
	private array $others = [];

	/**
	 * Real requests made, for the report.
	 *
	 * @var int
	 */
	private static int $requests = 0;

	/**
	 * Steps run, by kind, for the report.
	 *
	 * @var array<string,int>
	 */
	private static array $kinds = [];

	/**
	 * Served old URLs no list has, by engine class, for the report.
	 *
	 * @var array<string,string[]>
	 */
	private static array $excluded = [];

	private int $seed = 0;

	private int $fresh = 0;

	private int $admin;

	private Pairs $pairs;

	private Change_Log $changes;

	public function set_up(): void {
		parent::set_up();
		// The Server answers the real requests only while it serves redirects.
		update_option( Settings::OPTION, [ 'serve_redirects' => true ] );
		delete_option( Ledger::ROOT_OPTION );
		Post_Types::reset();
		Change_Log::reset_request();
		$this->set_permalink_structure( '/%postname%/' );
		$this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin );
		// A restored page comes back with the status it had, so every page stays published.
		add_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10, 3 );
		$this->pairs   = new Pairs();
		$this->changes = new Change_Log();
	}

	public function tear_down(): void {
		$this->end_core_bulk();
		wp_unschedule_hook( Queue::HOOK );
		$this->set_permalink_structure( '' );
		parent::tear_down();
	}

	public static function tear_down_after_class(): void {
		if ( getenv( 'HG_FUZZ_REPORT' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- A test report on stderr.
			fwrite( STDERR, sprintf( "real requests: %d\nsteps: %s\n", self::$requests, wp_json_encode( self::$kinds ) ) );
			foreach ( self::$excluded as $class => $lines ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- A test report on stderr.
				fwrite( STDERR, sprintf( "%s: %d URLs\n  %s\n", $class, count( $lines ), implode( "\n  ", $lines ) ) );
			}
		}
		parent::tear_down_after_class();
	}

	/**
	 * The seeds, one history each.
	 *
	 * @return array<string,array{int}>
	 */
	public function seeds(): array {
		$out = [];
		// HG_FUZZ_SEEDS runs more histories, to explore; the suite runs SEEDS.
		$count = (int) getenv( 'HG_FUZZ_SEEDS' );
		$count = $count > 0 ? $count : self::SEEDS;
		for ( $seed = 1; $seed <= $count; $seed++ ) {
			$out[ 'seed ' . $seed ] = [ $seed ];
		}
		return $out;
	}

	/**
	 * One seeded history.
	 *
	 * @dataProvider seeds
	 *
	 * @param int $seed Seed for mt_rand().
	 */
	public function test_a_history_lists_what_the_resolver_serves( int $seed ): void {
		$this->seed = $seed;
		mt_srand( $seed );
		$company = $this->make( 'Company', 'company' );
		$about   = $this->make( 'About', 'about', $company );
		$team    = $this->make( 'Team', 'team', $about );
		$this->make( 'Leadership', 'leadership', $team );
		$this->make( 'History', 'history', $about );
		$this->make( 'Services', 'services', $company );
		$this->make( 'Studio', 'studio' );
		$this->remember();

		for ( $i = 0; $i < self::STEPS; $i++ ) {
			$this->step = $i + 1;
			Change_Log::reset_request();
			$this->handler()->reset_request_budget();
			$this->steps[]        = $this->act();
			$kind                 = (string) preg_replace( '/ (?:[A-Z]\w*#|#|\/).*$/', '', (string) end( $this->steps ) );
			self::$kinds[ $kind ] = ( self::$kinds[ $kind ] ?? 0 ) + 1;
			if ( [] !== ( new Queue() )->roots() ) {
				$this->fail( 'Everything runs inline. ' . $this->story( $seed ) );
			}
			$this->remember();
		}

		$this->assertSame( [], $this->violations(), $this->story( $seed ) );
	}

	/**
	 * Runs one random step.
	 *
	 * @return string What it did.
	 */
	private function act(): string {
		for ( $try = 0; $try < 50; $try++ ) {
			$roll = mt_rand( 1, 132 );
			if ( $roll <= 24 ) {
				$line = $this->rename();
			} elseif ( $roll <= 48 ) {
				$line = $this->move();
			} elseif ( $roll <= 60 ) {
				$line = $this->create();
			} elseif ( $roll <= 78 ) {
				$line = $this->trash();
			} elseif ( $roll <= 87 ) {
				$line = $this->restore();
			} elseif ( $roll <= 94 ) {
				$line = $this->delete();
			} elseif ( $roll <= 100 ) {
				$line = $this->bulk_trash();
			} elseif ( $roll <= 108 ) {
				$line = $this->move( true );
			} elseif ( $roll <= 114 ) {
				$line = $this->unpublish();
			} elseif ( $roll <= 118 ) {
				$line = $this->republish();
			} elseif ( $roll <= 124 ) {
				$line = $this->twin();
			} elseif ( $roll <= 128 ) {
				$line = $this->blog_post();
			} else {
				$line = $this->attachment();
			}
			if ( null !== $line ) {
				return $line;
			}
		}
		return 'nothing';
	}

	/**
	 * Renames a live page, to a fresh slug or to one from the pool.
	 *
	 * @return string|null What it did, or null when it could not.
	 */
	private function rename(): ?string {
		$live = $this->live();
		if ( [] === $live ) {
			return null;
		}
		$id   = $this->pick( $live );
		$slug = mt_rand( 0, 1 ) ? self::POOL[ mt_rand( 0, count( self::POOL ) - 1 ) ] : 'fresh-' . ( ++$this->fresh );
		$from = $this->path( $id );
		wp_update_post(
			[
				'ID'        => $id,
				'post_name' => $slug,
			]
		);
		return sprintf( 'rename %s %s to %s: %s', $this->made[ $id ], $from, $slug, $this->path( $id ) );
	}

	/**
	 * Moves a live page under another live page that is not its descendant, or
	 * to the top level; with $rename, renames it in the same save, as Quick Edit does.
	 *
	 * @param bool $rename Whether the same save renames it.
	 * @return string|null What it did, or null when it could not.
	 */
	private function move( bool $rename = false ): ?string {
		$live = $this->live();
		if ( [] === $live ) {
			return null;
		}
		$id      = $this->pick( $live );
		$current = (int) get_post( $id )->post_parent;
		$parents = array_values(
			array_filter(
				array_merge( [ 0 ], $live ),
				fn( int $candidate ): bool => $candidate !== $id && $candidate !== $current && ( 0 === $candidate || ! in_array( $id, array_map( 'intval', get_post_ancestors( $candidate ) ), true ) )
			)
		);
		if ( [] === $parents ) {
			return null;
		}
		$parent = $this->pick( $parents );
		$from   = $this->path( $id );
		$update = [
			'ID'          => $id,
			'post_parent' => $parent,
		];
		if ( $rename ) {
			$update['post_name'] = mt_rand( 0, 1 ) ? self::POOL[ mt_rand( 0, count( self::POOL ) - 1 ) ] : 'fresh-' . ( ++$this->fresh );
		}
		wp_update_post( $update );
		return sprintf( '%s %s %s under %s%s: %s', $rename ? 'move and rename' : 'move', $this->made[ $id ], $from, 0 === $parent ? 'the top level' : $this->made[ $parent ], $rename ? ' to ' . $update['post_name'] : '', $this->path( $id ) );
	}

	/**
	 * Creates a published page with a pool slug under a live page.
	 *
	 * @return string|null What it did, or null when it could not.
	 */
	private function create(): ?string {
		$live = $this->live();
		if ( [] === $live ) {
			return null;
		}
		$parent = $this->pick( $live );
		$slug   = self::POOL[ mt_rand( 0, count( self::POOL ) - 1 ) ];
		$id     = $this->make( 'New' . ( count( $this->made ) + 1 ), $slug, $parent );
		return sprintf( 'create %s under %s: %s', $this->made[ $id ], $this->made[ $parent ], $this->path( $id ) );
	}

	/**
	 * Puts a live page in the trash.
	 *
	 * @return string|null What it did, or null when it could not.
	 */
	private function trash(): ?string {
		$live = $this->live();
		if ( [] === $live ) {
			return null;
		}
		$id   = $this->pick( $live );
		$from = $this->path( $id );
		wp_trash_post( $id );
		return sprintf( 'trash %s %s', $this->made[ $id ], $from );
	}

	/**
	 * Trashes a live page and one of its subpages in one bulk request of the
	 * Pages list, the subpage's row first or the parent's.
	 *
	 * @return string|null What it did, or null when it could not.
	 */
	private function bulk_trash(): ?string {
		$parents = array_values( array_filter( $this->live(), fn( int $id ): bool => [] !== $this->children( $id ) ) );
		if ( [] === $parents ) {
			return null;
		}
		$parent = $this->pick( $parents );
		$child  = $this->pick( $this->children( $parent ) );
		$ids    = mt_rand( 0, 1 ) ? [ $child, $parent ] : [ $parent, $child ];
		$line   = sprintf( 'bulk trash %s %s', $this->made[ $ids[0] ], $this->made[ $ids[1] ] );

		$this->start_core_bulk( 'trash', $ids );
		foreach ( $ids as $id ) {
			wp_trash_post( $id );
		}
		$this->end_core_bulk();
		return $line;
	}

	/**
	 * Restores a page from the trash.
	 *
	 * @return string|null What it did, or null when it could not.
	 */
	private function restore(): ?string {
		$trashed = $this->in_trash();
		if ( [] === $trashed ) {
			return null;
		}
		$id      = $this->pick( $trashed );
		$parents = [];
		foreach ( array_keys( $this->made ) as $page ) {
			$post             = get_post( $page );
			$parents[ $page ] = $post instanceof WP_Post ? (int) $post->post_parent : -1;
		}
		wp_untrash_post( $id );
		foreach ( $parents as $page => $parent ) {
			$post = get_post( $page );
			if ( $page !== $id && $parent >= 0 && $post instanceof WP_Post && (int) $post->post_parent !== $parent ) {
				$this->restored[ $page ] = $this->step;
			}
		}
		return sprintf( 'restore %s: %s', $this->made[ $id ], $this->path( $id ) );
	}

	/**
	 * Deletes a page in the trash for good.
	 *
	 * @return string|null What it did, or null when it could not.
	 */
	private function delete(): ?string {
		$trashed = $this->in_trash();
		if ( [] === $trashed ) {
			return null;
		}
		$id              = $this->pick( $trashed );
		$this->deleted[] = $id;
		wp_delete_post( $id, true );
		return sprintf( 'delete %s', $this->made[ $id ] );
	}

	/**
	 * Makes a published page with no subpage at all a draft or private, so no
	 * published page ever sits under one (P7).
	 *
	 * @return string|null What it did, or null when it could not.
	 */
	private function unpublish(): ?string {
		$leaves = array_values(
			array_filter(
				$this->live(),
				static fn( int $id ): bool => [] === get_posts(
					[
						'post_parent' => $id,
						'post_type'   => 'page',
						'post_status' => array_keys( get_post_stati() ),
						'fields'      => 'ids',
						'numberposts' => 1,
					]
				)
			)
		);
		if ( [] === $leaves ) {
			return null;
		}
		$id     = $this->pick( $leaves );
		$status = mt_rand( 0, 1 ) ? 'draft' : 'private';
		wp_update_post(
			[
				'ID'          => $id,
				'post_status' => $status,
			]
		);
		return sprintf( 'make %s %s %s', $this->made[ $id ], $status, $this->path( $id ) );
	}

	/**
	 * Publishes a draft or private page again.
	 *
	 * @return string|null What it did, or null when it could not.
	 */
	private function republish(): ?string {
		$hidden = array_values( array_filter( array_keys( $this->made ), static fn( int $id ): bool => in_array( get_post_status( $id ), [ 'draft', 'private' ], true ) ) );
		if ( [] === $hidden ) {
			return null;
		}
		$id = $this->pick( $hidden );
		wp_update_post(
			[
				'ID'          => $id,
				'post_status' => 'publish',
			]
		);
		return sprintf( 'publish %s: %s', $this->made[ $id ], $this->path( $id ) );
	}

	/**
	 * Adds a draft at a live page's path: core keeps a draft's slug as it is,
	 * so two pages share the full path, the draft the later.
	 *
	 * @return string|null What it did, or null when it could not.
	 */
	private function twin(): ?string {
		$live = $this->live();
		if ( [] === $live ) {
			return null;
		}
		$of                = $this->pick( $live );
		$post              = get_post( $of );
		$id                = $this->page( (string) $post->post_name, (int) $post->post_parent, 'draft' );
		$this->made[ $id ] = 'Draft' . ( count( $this->made ) + 1 ) . '#' . $id;
		return sprintf( 'draft %s at %s, beside %s', $this->made[ $id ], $this->path( $id ), $this->made[ $of ] );
	}

	/**
	 * Publishes a blog post named as a page's old one-segment path, or with a pool slug.
	 *
	 * @return string|null What it did.
	 */
	private function blog_post(): ?string {
		$slug           = $this->old_segment( 1 );
		$id             = self::factory()->post->create(
			[
				'post_type'   => 'post',
				'post_name'   => $slug,
				'post_status' => 'publish',
			]
		);
		$this->others[] = sprintf( 'post #%d /%s/', $id, get_post( $id )->post_name );
		return sprintf( 'blog post #%d %s', $id, get_post( $id )->post_name );
	}

	/**
	 * Attaches a file to a live page, named as the last segment of an old
	 * two-segment path, or with a pool slug.
	 *
	 * @return string|null What it did.
	 */
	private function attachment(): ?string {
		$live           = $this->live();
		$parent         = [] !== $live ? $this->pick( $live ) : 0;
		$slug           = $this->old_segment( 2 );
		$id             = self::factory()->attachment->create(
			[
				'post_name'      => $slug,
				'post_title'     => $slug,
				'post_parent'    => $parent,
				'post_mime_type' => 'image/jpeg',
				'file'           => $slug . '.jpg',
			]
		);
		$this->others[] = sprintf( 'attachment #%d %s under %s', $id, get_post( $id )->post_name, $this->name( $parent ) );
		return sprintf( 'attachment #%d %s under %s', $id, get_post( $id )->post_name, $this->name( $parent ) );
	}

	/**
	 * The last segment of a path of so many segments some page had and has no
	 * more, or a pool slug.
	 *
	 * @param int $length Segments.
	 * @return string
	 */
	private function old_segment( int $length ): string {
		$olds = [];
		foreach ( $this->history as $id => $paths ) {
			foreach ( $paths as $path ) {
				if ( substr_count( $path, '/' ) + 1 === $length && $path !== $this->path( $id ) ) {
					$olds[] = (string) substr( (string) strrchr( '/' . $path, '/' ), 1 );
				}
			}
		}
		$olds = array_values( array_unique( $olds ) );
		return [] !== $olds && mt_rand( 0, 3 ) > 0 ? $olds[ mt_rand( 0, count( $olds ) - 1 ) ] : self::POOL[ mt_rand( 0, count( self::POOL ) - 1 ) ];
	}

	/**
	 * Every violation of the three rules, one line each.
	 *
	 * @return string[]
	 */
	private function violations(): array {
		$resolver = new Resolver( new Ledger() );
		$out      = [];
		$listed   = [];
		$asked    = [];

		foreach ( $this->lists() as $name => $collected ) {
			foreach ( $collected['pairs'] as $pair ) {
				$served = $this->served( $resolver, $pair['old'] );
				if ( $served !== $pair['id'] ) {
					$out[] = sprintf( 'A: %s lists /%s/ -> %s, the site serves %s [%s]', $name, $pair['old'], $this->name( $pair['id'] ), $this->name( $served ), $this->describe( $pair['old'] ) );
				}
				$asked[ $pair['old'] ][ $pair['id'] ] = $name;
			}
			foreach ( $this->pairs->all( $collected ) as $row ) {
				$listed[ $row['old'] ] = true;
			}
		}

		$doubts = [];
		foreach ( $this->history as $id => $paths ) {
			$post = get_post( $id );
			if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
				continue;
			}
			$current = Paths::uri( $post );
			if ( Paths::has_trashed_segment( $current ) ) {
				continue;
			}
			foreach ( array_unique( $paths ) as $path ) {
				if ( $path === $current || isset( $listed[ Paths::relative( Paths::url( 'page', $path ) ) ] ) ) {
					continue;
				}
				if ( $this->served( $resolver, $path ) !== $id ) {
					// A blog post or an attachment the 404 test says is shown there: a real request says whether it is.
					$there = get_page_by_path( $path, OBJECT, 'page' );
					if ( ! ( $there instanceof WP_Post && is_post_publicly_viewable( $there ) ) && $this->resolved( $resolver, $path ) === $id ) {
						$doubts[ $path ] = $id;
					}
					continue;
				}
				$class = $this->excluded( $path, $id );
				if ( '' !== $class ) {
					self::$excluded[ $class ][] = sprintf( 'seed %d: /%s/ -> %s [%s]', $this->seed, $path, $this->made[ $id ], $this->describe( $path ) );
					continue;
				}
				$out[] = sprintf( 'B: the site serves /%s/ -> %s, no list has it [%s]', $path, $this->made[ $id ], $this->describe( $path ) );
			}
		}

		$overview = new Overview( new Ledger(), new Providers() );
		foreach ( array_keys( $this->made ) as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof WP_Post || 'trash' === $post->post_status ) {
				continue;
			}
			foreach ( $overview->describe( $post )['old_paths'] as $row ) {
				$served = $this->served( $resolver, $row['path'] );
				if ( ( 'ok' === $row['state'] ) !== ( $served === $id ) ) {
					$out[] = sprintf( 'C: %s says /%s/ is %s, the site serves %s [%s]', $this->made[ $id ], $row['path'], $row['state'], $this->name( $served ), $this->describe( $row['path'] ) );
				}
			}
		}

		// Last, real requests: each resets the query globals and flushes the object cache.
		$user = get_current_user_id();
		wp_set_current_user( 0 );
		$server = new Server( new Resolver( new Ledger() ) );
		foreach ( $asked as $old => $ids ) {
			$got = $this->request( $server, (string) $old );
			foreach ( $ids as $id => $name ) {
				++self::$requests;
				if ( Paths::relative( (string) get_permalink( $id ) ) !== $got ) {
					$out[] = sprintf( 'A: %s lists /%s/ -> %s, a request for it gets %s', $name, $old, $this->name( $id ), '' !== $got ? $got : 'no redirect' );
				}
			}
		}
		foreach ( $doubts as $path => $id ) {
			++self::$requests;
			if ( Paths::relative( (string) get_permalink( $id ) ) === $this->request( $server, (string) $path ) ) {
				$out[] = sprintf( 'B: a request for /%s/ is redirected to %s, no list has it [%s]', $path, $this->made[ $id ], $this->describe( (string) $path ) );
			}
		}
		wp_set_current_user( $user );

		return $out;
	}

	/**
	 * Where the Server redirects a logged-out request for a path, on a real main query.
	 *
	 * @param Server $server Server.
	 * @param string $path   Normalized path.
	 * @return string The target relative to the home URL, or an empty string for none.
	 */
	private function request( Server $server, string $path ): string {
		$this->go_to( Paths::url( 'page', $path ) );
		$target = $server->target_for_current_request();
		return null === $target ? '' : Paths::relative( $target['url'] );
	}

	/**
	 * The engine class a served old URL no list has belongs to, or none.
	 *
	 * - `root-list`: the resolver rewrites a prefix through the root list
	 *   (Ledger::ROOT_OPTION), which the trash flow writes for subpages moved
	 *   to the top level. No post holds that path, so only its trash change
	 *   could list it, and a change keeps only the root's own path, and its
	 *   markers only while the root is in the trash. Not excluded when the
	 *   prefix is the own path of a root of the latest change still in the trash.
	 * - `deleted-below`: the URL runs through the path of a page deleted for
	 *   good, below the old path the resolver rewrote first. The markers of its
	 *   trash go with it (Operations::step_cleanup()), and core moves the
	 *   subpages it still had up without a record (wp_delete_post()), so the
	 *   tree under it then cannot be rebuilt.
	 * - `left-subtree`: the resolver reaches the page through the entry of a
	 *   page that has left the pages the list of the old path it rewrote first
	 *   walks (or the page itself has): the post's subtree, or the subpages a
	 *   page in the trash moved up when it wrote the path on the post. Finding
	 *   a page that left them means looking ledger entries up by path prefix,
	 *   which the ledger's hashed keys cannot do.
	 * - `restored-back`: since the page had the URL, a restore moved it, or a
	 *   page above it, back under the page restored. Within an hour of the move
	 *   up the restore records no interim path (Operations::INTERIM_AGE), and it
	 *   deletes the markers, so where it lived meanwhile is not recorded.
	 * - `pruned-return`: since the page had the URL, it, or a page above it,
	 *   came back to a path it had had, and the ledger dropped its entry for
	 *   that path (Ledger::prune()), so where it lived before is not recorded.
	 * - `second-stay`: the post whose entry the resolver rewrites first was at
	 *   that path twice, and the page had the URL during the second stay.
	 *   Ledger::record() writes a path once per post, so only the first stay is
	 *   dated, and the pages under the path are rebuilt as they were then.
	 * - `restore-kept-url`: since the page had the URL, a restore that could
	 *   not move it, or a page above it, back recorded the URL it had before
	 *   the trash on it (Operations::keep_original_url()), dated at the restore,
	 *   and deleted the markers that dated the move. That entry reads as a move
	 *   made at the restore.
	 *
	 * @param string $path Normalized path the resolver sends to the page.
	 * @param int    $id   The page.
	 * @return string The class, or an empty string.
	 */
	private function excluded( string $path, int $id ): string {
		$rounds = $this->rounds( $path );
		if ( [] === $rounds ) {
			return '';
		}

		$change = $this->changes->latest( $this->admin );
		$roots  = [];
		foreach ( (array) ( $change['roots'] ?? [] ) as $root ) {
			if ( 'trash' === ( $change['action'] ?? '' ) && Settings::POLICY_REPARENT === ( $root['policy'] ?? '' ) && 'trash' === get_post_status( (int) $root['root'] ) ) {
				$roots[ Paths::normalize( (string) $root['path'] ) ] = (int) $root['root'];
			}
		}
		foreach ( $rounds as [ $prefix, $holder ] ) {
			if ( 0 === $holder && ! isset( $roots[ $prefix ] ) ) {
				return 'root-list';
			}
		}

		[ $prefix, $first ] = $rounds[0];
		foreach ( $this->deleted as $deleted ) {
			foreach ( $this->history[ $deleted ] ?? [] as $was ) {
				if ( strlen( $was ) > strlen( $prefix ) && str_starts_with( $path, $was . '/' ) ) {
					return 'deleted-below';
				}
			}
		}

		// The pages the list of the old path the resolver rewrote first walks: the
		// post's subtree, or the subpages moved up by the page in the trash that
		// wrote it on the post as its alias, or by the change's root.
		$movers = $first > 0 ? array_filter( Ledger::trash_holders( 'page', $prefix ), fn( int $holder ): bool => $this->wrote( $holder, $first, $prefix ) ) : [ $roots[ $prefix ] ];
		$tops   = [ $first ];
		if ( [] !== $movers ) {
			$tops = [];
			foreach ( $movers as $mover ) {
				foreach ( Markers::rows( Markers::moved_key( $mover ), 1000 ) as $row ) {
					$tops[] = $row['post_id'];
				}
			}
		}
		$inside = static function ( int $page ) use ( $tops ): bool {
			return [] !== array_intersect( $tops, array_merge( [ $page ], array_map( 'intval', get_post_ancestors( $page ) ) ) );
		};
		if ( ! $inside( $id ) ) {
			return 'left-subtree';
		}
		foreach ( array_slice( $rounds, 1 ) as [ , $holder ] ) {
			if ( $holder > 0 && ! $inside( $holder ) ) {
				return 'left-subtree';
			}
		}

		// When the page first had the URL, and what moved on the way since without a record.
		$since = $this->when[ $id ][ (int) array_search( $path, $this->history[ $id ], true ) ];
		$pages = array_unique( array_merge( [ $id ], array_map( 'intval', get_post_ancestors( $id ) ), array_filter( array_column( $rounds, 1 ) ) ) );
		foreach ( $pages as $page ) {
			if ( ( $this->restored[ $page ] ?? 0 ) > $since ) {
				return 'restored-back';
			}
		}
		foreach ( $pages as $page ) {
			$had = [];
			foreach ( $this->history[ $page ] ?? [] as $i => $was ) {
				if ( isset( $had[ $was ] ) && $this->when[ $page ][ $i ] > $since ) {
					return 'pruned-return';
				}
				$had[ $was ] = true;
			}
		}

		if ( $first > 0 ) {
			// The first post's stays at the path: a second one is dated by nothing.
			$stays = [];
			foreach ( $this->history[ $first ] ?? [] as $i => $was ) {
				if ( $was === $prefix && ( 0 === $i || $this->history[ $first ][ $i - 1 ] !== $prefix ) ) {
					$stays[] = $this->when[ $first ][ $i ];
				}
			}
			if ( count( $stays ) > 1 && $since >= $stays[1] ) {
				return 'second-stay';
			}
		}

		// An entry a restore wrote on a page on the way, for a path it had left before, since the page had the URL.
		foreach ( $pages as $page ) {
			foreach ( $this->entered[ $page ] ?? [] as $entry => $step ) {
				if ( $step > $since && str_starts_with( $this->steps[ $step - 1 ] ?? '', 'restore ' ) && $this->path_at( $page, $step - 1 ) !== $entry ) {
					return 'restore-kept-url';
				}
			}
		}
		return '';
	}

	/**
	 * A page's path at the end of a step, from its history.
	 *
	 * @param int $id   Page ID.
	 * @param int $step Step, 0 for the start.
	 * @return string
	 */
	private function path_at( int $id, int $step ): string {
		$path = '';
		foreach ( $this->history[ $id ] ?? [] as $i => $was ) {
			if ( $this->when[ $id ][ $i ] > $step ) {
				break;
			}
			$path = $was;
		}
		return $path;
	}

	/**
	 * Whether a page in the trash wrote an old path on a post as its alias.
	 *
	 * @param int    $holder Page in the trash.
	 * @param int    $post   Post.
	 * @param string $path   Normalized path.
	 * @return bool
	 */
	private function wrote( int $holder, int $post, string $path ): bool {
		foreach ( (array) get_post_meta( $holder, Markers::ALIASES, true ) as $alias ) {
			$to  = is_array( $alias ) ? (int) ( $alias['to'] ?? 0 ) : 0;
			$was = is_array( $alias ) ? Paths::normalize( (string) ( $alias['path'] ?? '' ) ) : '';
			if ( $to === $post && $was === $path ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The resolver's rounds for a path, as Rules::rewrite_once() takes them on
	 * the live ledger: the prefix it rewrote and the post that holds it, 0 for
	 * the root list. Only used to name the engine class of a served URL no list has.
	 *
	 * @param string $path Normalized path.
	 * @return array<int,array{0:string,1:int,2:string}> Prefix, holder and the path it was rewritten to.
	 */
	private function rounds( string $path ): array {
		$trace     = [];
		$temporary = false;
		Rules::resolve( new Live_View( new Ledger() ), 'page', $path, $temporary, $trace );
		return $trace;
	}

	/**
	 * The resolver's rounds for a path, for a failure message.
	 *
	 * @param string $path Normalized path.
	 * @return string
	 */
	private function describe( string $path ): string {
		return implode(
			' ; ',
			array_map( fn( array $round ): string => sprintf( '%s via %s to %s', $round[0], 0 === $round[1] ? 'the root list' : $this->name( $round[1] ), $round[2] ), $this->rounds( $path ) )
		);
	}

	/**
	 * Every list the admin screens offer: each page's pairs for each of its
	 * old paths, drafts and private pages too, and the admin's latest change.
	 *
	 * @return array<string,array<string,mixed>> Collected pairs by list.
	 */
	private function lists(): array {
		$ledger = new Ledger();
		$out    = [];
		foreach ( array_keys( $this->made ) as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof WP_Post || 'trash' === $post->post_status ) {
				continue;
			}
			foreach ( $ledger->paths_for( $id ) as $from ) {
				$out[ sprintf( '%s at /%s/', $this->made[ $id ], $from ) ] = $this->pairs->collect_post( $post, $from );
			}
		}
		$change = $this->changes->latest( $this->admin );
		if ( null !== $change ) {
			$out['the latest change'] = $this->pairs->collect_change( $change );
		}
		return $out;
	}

	/**
	 * The page a request for a path is redirected to: none when WordPress
	 * shows something there, as Rules::is_404() tells it on live lookups,
	 * since only a 404 reaches the resolver.
	 *
	 * @param Resolver $resolver Resolver.
	 * @param string   $path     Normalized path.
	 * @return int Page ID, 0 for none.
	 */
	private function served( Resolver $resolver, string $path ): int {
		if ( ! Live_Model::is_404( $path ) ) {
			return 0;
		}
		return $this->resolved( $resolver, $path );
	}

	/**
	 * The page the resolver sends a path to, 404 or not.
	 *
	 * @param Resolver $resolver Resolver.
	 * @param string   $path     Normalized path.
	 * @return int Page ID, 0 for none.
	 */
	private function resolved( Resolver $resolver, string $path ): int {
		$target = $resolver->resolve( 'page', $path );
		return $target instanceof WP_Post ? (int) $target->ID : 0;
	}

	/**
	 * Creates a published page.
	 *
	 * @param string $label     Label for the story.
	 * @param string $slug      Slug.
	 * @param int    $parent_id Parent page ID.
	 * @return int Page ID.
	 */
	private function make( string $label, string $slug, int $parent_id = 0 ): int {
		$id                = $this->page( $slug, $parent_id );
		$this->made[ $id ] = $label . '#' . $id;
		return $id;
	}

	/**
	 * Adds each live page's path to its history.
	 */
	private function remember(): void {
		foreach ( $this->live() as $id ) {
			$path = $this->path( $id );
			$seen = $this->history[ $id ] ?? [];
			if ( end( $seen ) !== $path ) {
				$this->history[ $id ][] = $path;
				$this->when[ $id ][]    = $this->step;
			}
		}
		$ledger = new Ledger();
		foreach ( array_keys( $this->made ) as $id ) {
			foreach ( $ledger->paths_for( $id ) as $entry ) {
				$this->entered[ $id ][ $entry ] = $this->entered[ $id ][ $entry ] ?? $this->step;
			}
		}
	}

	/**
	 * Published pages of the history that sit outside the trash.
	 *
	 * @return int[]
	 */
	private function live(): array {
		return array_values(
			array_filter(
				array_keys( $this->made ),
				fn( int $id ): bool => 'publish' === get_post_status( $id ) && ! Paths::has_trashed_segment( $this->path( $id ) )
			)
		);
	}

	/**
	 * Pages of the history in the trash.
	 *
	 * @return int[]
	 */
	private function in_trash(): array {
		return array_values( array_filter( array_keys( $this->made ), static fn( int $id ): bool => 'trash' === get_post_status( $id ) ) );
	}

	/**
	 * Live subpages of a page.
	 *
	 * @param int $parent_id Page ID.
	 * @return int[]
	 */
	private function children( int $parent_id ): array {
		return array_values( array_filter( $this->live(), static fn( int $id ): bool => (int) get_post( $id )->post_parent === $parent_id ) );
	}

	/**
	 * A random element.
	 *
	 * @param int[] $ids Choices.
	 * @return int
	 */
	private function pick( array $ids ): int {
		return $ids[ mt_rand( 0, count( $ids ) - 1 ) ];
	}

	/**
	 * A page's path now.
	 *
	 * @param int $id Page ID.
	 * @return string
	 */
	private function path( int $id ): string {
		$post = get_post( $id );
		return $post instanceof WP_Post ? Paths::uri( $post ) : '';
	}

	/**
	 * A page's label, or none.
	 *
	 * @param int $id Page ID, 0 for none.
	 * @return string
	 */
	private function name( int $id ): string {
		return 0 === $id ? 'nothing' : ( $this->made[ $id ] ?? '#' . $id );
	}

	/**
	 * The seed, what the history did, the state it left and every list, for a failure message.
	 *
	 * @param int $seed Seed.
	 * @return string
	 */
	private function story( int $seed ): string {
		$ledger = new Ledger();
		$lines  = [ 'seed ' . $seed . ':' ];
		foreach ( $this->steps as $i => $step ) {
			$lines[] = sprintf( '  %d. %s', $i + 1, $step );
		}
		$lines[] = 'state:';
		foreach ( $this->made as $id => $label ) {
			$status  = (string) get_post_status( $id );
			$lines[] = sprintf(
				'  %s %s /%s/ history [%s] ledger [%s]',
				$label,
				'' !== $status ? $status : 'deleted',
				$this->path( $id ),
				implode( ', ', $this->history[ $id ] ?? [] ),
				implode( ', ', $ledger->paths_for( $id ) )
			);
		}
		foreach ( $this->others as $other ) {
			$lines[] = '  ' . $other;
		}
		$roots   = get_option( Ledger::ROOT_OPTION, [] );
		$lines[] = '  root list [' . implode( ', ', (array) ( $roots['page'] ?? [] ) ) . ']';
		$lines[] = 'lists:';
		foreach ( $this->lists() as $name => $collected ) {
			$lines[] = sprintf(
				'  %s: %s | unresolved %s',
				$name,
				implode( ', ', array_map( fn( array $pair ): string => $pair['old'] . ' > ' . $this->name( $pair['id'] ), $collected['pairs'] ) ),
				implode( ', ', array_map( static fn( array $row ): string => $row['old'] . ' (' . $row['reason'] . ')', $collected['unresolved'] ) )
			);
		}
		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * The handler the plugin registered.
	 *
	 * @return Trash_Handler
	 */
	private function handler(): Trash_Handler {
		global $wp_filter;
		foreach ( $wp_filter['trashed_post']->callbacks[10] ?? [] as $callback ) {
			if ( is_array( $callback['function'] ) && $callback['function'][0] instanceof Trash_Handler ) {
				return $callback['function'][0];
			}
		}
		$this->fail( 'No Trash_Handler registered on trashed_post.' );
	}
}
