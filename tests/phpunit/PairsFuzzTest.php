<?php
/**
 * Seeded random histories against the resolver: every pair a change lists is
 * one the site serves, a save or a trash lists the URLs it changed, and a
 * list is gone once the page a save moved, or a trashed parent whose subpages
 * moved up, is deleted for good.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Tests;

// phpcs:disable WordPress.WP.AlternativeFunctions.rand_mt_rand,WordPress.WP.AlternativeFunctions.rand_seeding_mt_srand -- A seeded history must replay from its seed.

use HierarchyGuard\Batch\Queue;
use HierarchyGuard\Ledger;
use HierarchyGuard\Paths;
use HierarchyGuard\Post_Types;
use HierarchyGuard\Redirects\Live_View;
use HierarchyGuard\Redirects\Pairs;
use HierarchyGuard\Redirects\Resolver;
use HierarchyGuard\Redirects\Rules;
use HierarchyGuard\Redirects\Server;
use HierarchyGuard\Settings;
use HierarchyGuard\Trash\Change_Log;
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
 * attachments named as the last segment of an old two-segment path. The
 * renames and moves are saves through wp_update_post() as the admin, so each
 * records a change, as a trash does. Every change is collected right after
 * the step that made it, and the admin's latest change again at the end.
 *
 * - Correct: every listed pair's old URL is a 404 the resolver sends to that
 *   pair's page, and a logged-out request for it is redirected there by the
 *   Server on a real main query.
 * - A save lists what it changed: the saved page and every published subpage
 *   under it, each at the URL it had right before the save, unless the
 *   server would not redirect that URL to it.
 * - A trash lists what it changed, while its pages are in the Trash: every
 *   published page under them, at the URL it had right before the trash,
 *   unless the server would not redirect that URL to it.
 * - Gone: at the end, the latest change's list is gone exactly when a page
 *   it lists redirects for was deleted for good: a save's page, or a trashed
 *   parent that moved subpages up.
 * - Taken: after each step, a URL a page took with its own save, creation,
 *   publish or restore, and showed last, leads by its own ledger entry to
 *   that page or to no page: no other page's entry for it is left. A page
 *   that came to a URL only because a page above it moved takes nothing,
 *   and where a shorter prefix leads is not checked.
 *
 * The resolver runs only on a 404, so a URL a published page, a blog post or
 * an attachment uses now is served by it and is no redirect at all, on
 * either side. Only a page with no subpage at all is made a draft or private,
 * and nothing is put under one: a published page under a draft (P7, declined:
 * the resolver's prefix walk can stop at the draft) never arises.
 *
 * Reproduce one history: --filter 'PairsFuzzTest.*seed 17"'.
 *
 * @covers \HierarchyGuard\Redirects\Pairs
 * @covers \HierarchyGuard\Redirects\Pairs_Blocks
 * @covers \HierarchyGuard\Redirects\Batch_Resolver
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
	 * The page that showed each path last, and whether it took the path with
	 * its own save, creation, publish or restore.
	 *
	 * @var array<string,array{0:int,1:bool}>
	 */
	private array $last = [];

	/**
	 * Pages this step saved, created, published or restored.
	 *
	 * @var int[]
	 */
	private array $actors = [];

	/**
	 * What the history did, one line per step.
	 *
	 * @var string[]
	 */
	private array $steps = [];

	private int $step = 0;

	/**
	 * The save this step made: the page, and its subtree by the paths it had
	 * right before the save; null when the step saved no URL.
	 *
	 * @var array{id:int,before:array<string,int>}|null
	 */
	private ?array $saved = null;

	/**
	 * The trash this step made: the published pages under the pages it
	 * trashed, by the paths they had right before it; null when the step
	 * trashed nothing.
	 *
	 * @var array<string,int>|null
	 */
	private ?array $trashed = null;

	/**
	 * The changes checked so far, by ID.
	 *
	 * @var array<string,true>
	 */
	private array $checked = [];

	/**
	 * What the checks found, step by step.
	 *
	 * @var string[]
	 */
	private array $failures = [];

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
	 * Changes checked by action, pairs checked, and the URLs saves changed,
	 * with those the server would not redirect, for the report.
	 *
	 * @var array<string,int>
	 */
	private static array $tally = [];

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
			fwrite( STDERR, sprintf( "real requests: %d\nsteps: %s\nchecked: %s\n", self::$requests, wp_json_encode( self::$kinds ), wp_json_encode( self::$tally ) ) );
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
			$this->failures = array_merge( $this->failures, $this->check_change(), $this->check_last_holders() );
		}

		$this->assertSame( [], array_merge( $this->failures, $this->violations() ), $this->story( $seed ) );
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
		$tree = $this->subtree( $id );
		wp_update_post(
			[
				'ID'        => $id,
				'post_name' => $slug,
			]
		);
		$this->actors[] = $id;
		$this->saved( $id, $from, $tree );
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
		$tree = $this->subtree( $id );
		wp_update_post( $update );
		$this->actors[] = $id;
		$this->saved( $id, $from, $tree );
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
		$this->trashing( [ $id ] );
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

		$this->trashing( $ids );
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
		$id = $this->pick( $trashed );
		wp_untrash_post( $id );
		$this->actors[] = $id;
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
		$id = $this->pick( $trashed );
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
		$this->actors[] = $id;
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
	 * The page and every published subpage under it, by the path each has now.
	 *
	 * @param int $root Page.
	 * @return array<string,int> Page IDs by path.
	 */
	private function subtree( int $root ): array {
		$out = [];
		foreach ( array_keys( $this->made ) as $id ) {
			$post = get_post( $id );
			if ( $post instanceof WP_Post && 'publish' === $post->post_status && ( $id === $root || in_array( $root, array_map( 'intval', get_post_ancestors( $post ) ), true ) ) ) {
				$out[ Paths::uri( $post ) ] = $id;
			}
		}
		return $out;
	}

	/**
	 * Notes the save this step made, when it changed the page's URL.
	 *
	 * @param int               $id   Page saved.
	 * @param string            $from Its path right before the save.
	 * @param array<string,int> $tree Its subtree by the paths it had right before the save.
	 */
	private function saved( int $id, string $from, array $tree ): void {
		if ( $this->path( $id ) !== $from ) {
			$this->saved = [
				'id'     => $id,
				'before' => $tree,
			];
		}
	}

	/**
	 * Notes the trash this step makes, right before it.
	 *
	 * @param int[] $roots Pages it trashes.
	 */
	private function trashing( array $roots ): void {
		$before = [];
		foreach ( $roots as $root ) {
			$before += $this->subtree( $root );
		}
		$this->trashed = array_filter( $before, static fn( int $id ): bool => ! in_array( $id, $roots, true ) );
	}

	/**
	 * Checks the admin's latest change right after the step that made it,
	 * while it is fresh: every pair it lists, and, for a save or a trash,
	 * that it lists what the step changed.
	 *
	 * @return string[] One line per failure.
	 */
	private function check_change(): array {
		$saved         = $this->saved;
		$trashed       = $this->trashed;
		$this->saved   = null;
		$this->trashed = null;
		$change        = $this->changes->latest( $this->admin );
		if ( null !== $saved && ( null === $change || 'save' !== $change['action'] || ! isset( $change['roots'][ $saved['id'] ] ) ) ) {
			return [ sprintf( 'B: step %d saved %s and recorded no change', $this->step, $this->made[ $saved['id'] ] ) ];
		}
		if ( null === $change || isset( $this->checked[ (string) $change['id'] ] ) ) {
			return [];
		}
		$this->checked[ (string) $change['id'] ] = true;

		$name      = sprintf( 'the %s of step %d', $change['action'], $this->step );
		$collected = $this->pairs->collect_change( $change );
		$this->tally( $change['action'] . ' changes' );
		$this->tally( $change['action'] . ' pairs', count( $collected['pairs'] ) );
		$out = $this->check_pairs( [ $name => $collected ] );
		if ( null !== $saved ) {
			$out = array_merge( $out, $this->check_complete( 'save', $name, $collected, $saved['before'] ) );
		}
		if ( null !== $trashed && 'trash' === $change['action'] ) {
			$out = array_merge( $out, $this->check_complete( 'trash', $name, $collected, $trashed ) );
		}
		return $out;
	}

	/**
	 * Whether a save's or a trash's pairs have every page it changed at the
	 * URL it had right before the step, wherever the site redirects that URL
	 * to it: for a save, the saved page and every published subpage; for a
	 * trash, every published page under the pages it trashed.
	 *
	 * @param string              $kind      save or trash.
	 * @param string              $name      The list's name.
	 * @param array<string,mixed> $collected The change's pairs.
	 * @param array<string,int>   $before    The pages by the paths they had right before the step.
	 * @return string[] One line per URL the site redirects and the list lacks.
	 */
	private function check_complete( string $kind, string $name, array $collected, array $before ): array {
		$code   = 'save' === $kind ? 'B' : 'T';
		$listed = [];
		foreach ( $collected['pairs'] as $pair ) {
			$listed[ $pair['old'] ][ $pair['id'] ] = true;
		}

		$resolver = new Resolver( new Ledger() );
		$out      = [];
		$doubts   = [];
		foreach ( $before as $old => $id ) {
			$old = (string) $old;
			$this->tally( $kind . ' URLs' );
			if ( isset( $listed[ $old ][ $id ] ) ) {
				continue;
			}
			$this->tally( $kind . ' URLs not listed' );
			if ( $this->served( $resolver, $old ) === $id ) {
				$out[] = sprintf( '%s: %s lacks /%s/ -> %s, which the site serves [%s]', $code, $name, $old, $this->name( $id ), $this->describe( $old ) );
				continue;
			}
			// A blog post or an attachment the 404 test says is shown there: a real request says whether it is.
			$there = get_page_by_path( $old, OBJECT, 'page' );
			if ( ! ( $there instanceof WP_Post && is_post_publicly_viewable( $there ) ) && $this->resolved( $resolver, $old ) === $id ) {
				$doubts[ $old ] = $id;
			}
		}
		if ( [] === $doubts ) {
			return $out;
		}

		$user = get_current_user_id();
		wp_set_current_user( 0 );
		$server = new Server( new Resolver( new Ledger() ) );
		foreach ( $doubts as $old => $id ) {
			++self::$requests;
			if ( Paths::relative( (string) get_permalink( $id ) ) === $this->request( $server, (string) $old ) ) {
				$out[] = sprintf( '%s: %s lacks /%s/ -> %s, and a request for it is redirected there [%s]', $code, $name, $old, $this->name( $id ), $this->describe( (string) $old ) );
			}
		}
		wp_set_current_user( $user );
		return $out;
	}

	/**
	 * Adds to a count of the report.
	 *
	 * @param string $key What is counted.
	 * @param int    $by  How many.
	 */
	private function tally( string $key, int $by = 1 ): void {
		self::$tally[ $key ] = ( self::$tally[ $key ] ?? 0 ) + $by;
	}

	/**
	 * Every pair the admin's latest change lists, as the redirects modal
	 * would show it at the end of the history, that the site does not serve,
	 * and whether the list is gone when it should be.
	 *
	 * @return string[]
	 */
	private function violations(): array {
		$lists = $this->lists();
		$out   = $this->check_pairs( $lists );
		if ( [] === $lists ) {
			return $out;
		}
		$change   = $this->changes->latest( $this->admin );
		$expected = $this->deleted_for_good( $change );
		if ( $expected ) {
			$this->tally( 'gone ' . $change['action'] . ' changes at the end' );
		}
		if ( $expected !== $lists['the latest change']['gone'] ) {
			$out[] = sprintf( 'G: the latest change, a %s, is %s', $change['action'], $expected ? 'not gone, though a page it lists redirects for was deleted for good' : 'gone, though no page it lists redirects for was deleted for good' );
		}
		return $out;
	}

	/**
	 * Whether a page a change lists redirects for no longer exists: a save's
	 * page, or a trashed parent whose subpages moved up.
	 *
	 * @param array<string,mixed> $change A change from Change_Log.
	 * @return bool
	 */
	private function deleted_for_good( array $change ): bool {
		foreach ( $change['roots'] as $root ) {
			$lists = 'save' === $change['action'] || ( 'trash' === $change['action'] && Settings::POLICY_REPARENT === $root['policy'] && '' !== $root['path'] && $root['counts']['moved'] > 0 );
			if ( $lists && false === get_post_status( (int) $root['root'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Every listed pair the site does not serve, or serves with another
	 * status than its CSV row gives it, one line each.
	 *
	 * @param array<string,array<string,mixed>> $lists Collected pairs by list.
	 * @return string[]
	 */
	private function check_pairs( array $lists ): array {
		$resolver = new Resolver( new Ledger() );
		$out      = [];
		$asked    = [];
		$codes    = [];

		foreach ( $lists as $name => $collected ) {
			foreach ( $collected['pairs'] as $pair ) {
				$served = $this->served( $resolver, $pair['old'] );
				if ( $served !== $pair['id'] ) {
					$out[] = sprintf( 'A: %s lists /%s/ -> %s, the site serves %s [%s]', $name, $pair['old'], $this->name( $pair['id'] ), $this->name( $served ), $this->describe( $pair['old'] ) );
				}
				$asked[ $pair['old'] ][ $pair['id'] ] = $name;
				$codes[ $pair['old'] ][ $pair['id'] ] = ! empty( $pair['temporary'] ) ? 302 : 301;
			}
		}
		if ( [] === $asked ) {
			return $out;
		}

		// Last, real requests: each resets the query globals and flushes the object cache.
		$user = get_current_user_id();
		wp_set_current_user( 0 );
		$server = new Server( new Resolver( new Ledger() ) );
		foreach ( $asked as $old => $ids ) {
			$got = $this->request( $server, (string) $old, $status );
			foreach ( $ids as $id => $name ) {
				++self::$requests;
				if ( Paths::relative( (string) get_permalink( $id ) ) !== $got ) {
					$out[] = sprintf( 'A: %s lists /%s/ -> %s, a request for it gets %s', $name, $old, $this->name( $id ), '' !== $got ? $got : 'no redirect' );
				} elseif ( $codes[ $old ][ $id ] !== $status ) {
					$out[] = sprintf( 'C: %s lists /%s/ -> %s as a %d, a request for it gets a %d [%s]', $name, $old, $this->name( $id ), $codes[ $old ][ $id ], $status, $this->describe( (string) $old ) );
				}
				$this->tally( 302 === $status ? 'pairs served 302' : 'pairs served 301' );
			}
		}
		wp_set_current_user( $user );

		return $out;
	}

	/**
	 * Where the Server redirects a logged-out request for a path, on a real main query.
	 *
	 * @param Server   $server Server.
	 * @param string   $path   Normalized path.
	 * @param int|null $status Set to the redirect's status, 0 for none.
	 * @return string The target relative to the home URL, or an empty string for none.
	 */
	private function request( Server $server, string $path, ?int &$status = null ): string {
		$this->go_to( Paths::url( 'page', $path ) );
		$target = $server->target_for_current_request();
		$status = null === $target ? 0 : (int) $target['status'];
		return null === $target ? '' : Paths::relative( $target['url'] );
	}

	/**
	 * The resolver's rounds for a path, as Rules::rewrite_once() takes them on
	 * the live ledger: the prefix it rewrote and the post that holds it, 0 for
	 * the root list.
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
	 * The list the redirects modal offers at the end of the history: the
	 * admin's latest change.
	 *
	 * @return array<string,array<string,mixed>> Collected pairs by list.
	 */
	private function lists(): array {
		$change = $this->changes->latest( $this->admin );
		return null === $change ? [] : [ 'the latest change' => $this->pairs->collect_change( $change ) ];
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
		$this->actors[]    = $id;
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
			}
			if ( ! isset( $this->last[ $path ] ) || $this->last[ $path ][0] !== $id || in_array( $id, $this->actors, true ) ) {
				$this->last[ $path ] = [ $id, in_array( $id, $this->actors, true ) ];
			}
		}
		$this->actors = [];
	}

	/**
	 * Every URL a page took with its own save and showed last whose own
	 * ledger entry leads to another page.
	 *
	 * @return string[] One line per such URL.
	 */
	private function check_last_holders(): array {
		$ledger = new Ledger();
		$live   = $this->live();
		$out    = [];
		foreach ( $this->last as $path => [ $holder, $own ] ) {
			$path = (string) $path;
			if ( ! $own || ! in_array( $holder, $live, true ) || $this->path( $holder ) === $path ) {
				continue;
			}
			$this->tally( 'taken URLs checked' );
			$found = (int) $ledger->find( 'page', $path );
			if ( $found > 0 && $holder !== $found ) {
				$out[] = sprintf( 'L: step %d: /%s/ last showed %s, which took it itself, and the ledger gives it to %s [%s]', $this->step, $path, $this->name( $holder ), $this->name( $found ), $this->describe( $path ) );
			}
		}
		return $out;
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
