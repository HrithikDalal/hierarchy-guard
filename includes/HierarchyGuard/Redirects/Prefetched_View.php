<?php
/**
 * The facts the rules read, read ahead for many paths at once.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Redirects;

use HierarchyGuard\Ledger;
use HierarchyGuard\Paths;
use InvalidArgumentException;
use OutOfBoundsException;
use UnexpectedValueException;
use WP_Post;

/**
 * Answers the rules from facts a batch read for every path at once. A fact
 * not read yet is noted and stops the rules with an OutOfBoundsException:
 * the batch reads every noted fact in one query and runs the rules again.
 * A request lookup not read yet is answered as a 404 and noted, so it is
 * read together with the first rewritten paths; an answer that relied on it
 * is not final until it is read.
 */
final class Prefetched_View implements Ledger_View {

	/**
	 * Post type name.
	 *
	 * @var string
	 */
	private string $post_type;

	/**
	 * Ledger facts by path, shared with the batch.
	 *
	 * @var array<string,array{live:int,held:bool,newest:bool,trashed:int[]}>
	 */
	private array $facts;

	/**
	 * The root list as a set, read once when first needed.
	 *
	 * @var array<string,true>|null
	 */
	private ?array $roots = null;

	/**
	 * The post at each path looked up as a request, 0 for none, Batch_Resolver::UNKNOWN when core could not be asked.
	 *
	 * @var array<string,int>
	 */
	private array $requests = [];

	/**
	 * How WordPress routes each request path looked up, from Rules::route().
	 *
	 * @var array<string,array{0:bool,1:string,2:string}>
	 */
	private array $routes = [];

	/**
	 * Whether what those routes ask for is there, by kind and slug: a post
	 * anyone can view, or a term.
	 *
	 * @var array<string,array<string,bool>>
	 */
	private array $shown = [];

	/**
	 * The post at each rewritten path, 0 for none, Batch_Resolver::UNKNOWN when core could not be asked.
	 *
	 * @var array<string,int>
	 */
	private array $candidates = [];

	/**
	 * Each post's path, worked out once: the posts do not change during a batch.
	 *
	 * @var array<int,string>
	 */
	private array $uris = [];

	/**
	 * Facts, request paths and rewritten paths to read next.
	 *
	 * @var array{facts:array<string,true>,requests:array<string,true>,candidates:array<string,true>}
	 */
	private array $needs = [
		'facts'      => [],
		'requests'   => [],
		'candidates' => [],
	];

	/**
	 * Request paths the current run of the rules asked for and found unread.
	 *
	 * @var array<string,true>
	 */
	private array $asked = [];

	/**
	 * Constructor.
	 *
	 * @param string                                  $post_type Post type name.
	 * @param array<string,array{live:int,held:bool,newest:bool,trashed:int[]}> $facts     Ledger facts read so far, by path; the batch adds more.
	 */
	public function __construct( string $post_type, array &$facts ) {
		$this->post_type = $post_type;
		$this->facts     = &$facts;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $post_type Post type name.
	 * @param string $prefix    Normalized path.
	 */
	public function find( string $post_type, string $prefix ): ?int {
		$this->own( $post_type );
		$fact = $this->fact( $prefix );
		if ( $fact['live'] > 0 ) {
			return $fact['live'];
		}
		if ( null === $this->roots ) {
			$list        = get_option( Ledger::ROOT_OPTION, [] );
			$this->roots = array_fill_keys( array_map( 'strval', (array) ( is_array( $list ) ? ( $list[ $this->post_type ] ?? [] ) : [] ) ), true );
		}
		return isset( $this->roots[ $prefix ] ) ? Rules::ROOT : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $post_type Post type name.
	 * @param string $prefix    Normalized path.
	 */
	public function held_in_trash( string $post_type, string $prefix ): bool {
		$this->own( $post_type );
		return $this->fact( $prefix )['held'];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $post_type Post type name.
	 * @param string $prefix    Normalized path.
	 */
	public function newest_in_trash( string $post_type, string $prefix ): bool {
		$this->own( $post_type );
		return $this->fact( $prefix )['newest'] ?? false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $post_type Post type name.
	 * @param string $prefix    Normalized path.
	 */
	public function trashed_holders( string $post_type, string $prefix ): array {
		$this->own( $post_type );
		return $this->fact( $prefix )['trashed'] ?? [];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $id Post ID.
	 */
	public function post( int $id ): ?WP_Post {
		$post = get_post( $id );
		return $post instanceof WP_Post ? $post : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param WP_Post $post Post.
	 */
	public function uri( WP_Post $post ): string {
		$id = (int) $post->ID;
		if ( ! isset( $this->uris[ $id ] ) ) {
			$this->uris[ $id ] = Paths::uri( $post );
		}
		return $this->uris[ $id ];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $candidate Normalized path.
	 * @param string $post_type Post type name.
	 * @throws OutOfBoundsException When the path was not looked up yet.
	 * @throws UnexpectedValueException When core could not be asked which of the posts at the path it finds.
	 */
	public function page_by_path( string $candidate, string $post_type ): ?WP_Post {
		$this->own( $post_type );
		if ( ! isset( $this->candidates[ $candidate ] ) ) {
			$this->needs['candidates'][ $candidate ] = true;
			throw new OutOfBoundsException( 'Not looked up yet.' );
		}
		if ( Batch_Resolver::UNKNOWN === $this->candidates[ $candidate ] ) {
			throw new UnexpectedValueException( 'Not known.' );
		}
		return $this->candidates[ $candidate ] > 0 ? $this->post( $this->candidates[ $candidate ] ) : null;
	}

	/**
	 * Whether a request for a path of the type is a 404, by Rules::is_404()
	 * on the request lookups read. One not read yet is answered as a 404 and
	 * noted, for the batch to read next.
	 *
	 * @param string $path      Normalized path.
	 * @param string $post_type Post type name.
	 * @return bool
	 */
	public function is_404( string $path, string $post_type ): bool {
		$this->own( $post_type );
		if ( ! isset( $this->requests[ $path ] ) ) {
			$this->asked[ $path ] = true;
			return true;
		}
		$there = $this->requests[ $path ];
		if ( Batch_Resolver::UNKNOWN === $there ) {
			// Core was not asked about a path two pages share: taken as served by one of them.
			return false;
		}
		// A path with no route read is taken as showing something.
		return Rules::is_404( $this->routes[ $path ] ?? [ false, 'other', '' ], $there > 0 ? $this->post( $there ) : null, fn( string $kind, string $slug ): bool => $this->shown[ $kind ][ $slug ] ?? false );
	}

	/**
	 * Whether a request path was looked up.
	 *
	 * @param string $path Normalized path.
	 * @return bool
	 */
	public function knows( string $path ): bool {
		return isset( $this->requests[ $path ] );
	}

	/**
	 * Starts a run of the rules.
	 */
	public function begin(): void {
		$this->asked = [];
	}

	/**
	 * Whether the run answered a request lookup it has not read yet.
	 *
	 * @return bool
	 */
	public function guessed(): bool {
		return [] !== $this->asked;
	}

	/**
	 * Keeps the request lookups of the run, which goes on in the next one.
	 */
	public function keep(): void {
		$this->needs['requests'] += $this->asked;
		$this->asked              = [];
	}

	/**
	 * Takes what to read next, and forgets it.
	 *
	 * @return array{facts:string[],requests:string[],candidates:string[]}
	 */
	public function take_needs(): array {
		$out         = array_map( static fn( array $set ): array => array_map( 'strval', array_keys( $set ) ), $this->needs );
		$this->needs = [
			'facts'      => [],
			'requests'   => [],
			'candidates' => [],
		];
		return $out;
	}

	/**
	 * Adds the posts looked up.
	 *
	 * @param string[]                                       $requests   Paths looked up as a request.
	 * @param array<string,int>                              $at         The post found at each, by path; absent for none.
	 * @param string[]                                       $candidates Rewritten paths looked up.
	 * @param array<string,int>                              $there      The post found at each, by path; absent for none.
	 * @param array<string,array{0:bool,1:string,2:string}>  $routes     How WordPress routes each request path, by path.
	 * @param array<string,array<string,bool>>               $shown      Whether what the routes ask for is there, by kind and slug.
	 */
	public function add_lookups( array $requests, array $at, array $candidates, array $there, array $routes = [], array $shown = [] ): void {
		foreach ( $requests as $path ) {
			$this->requests[ $path ] = (int) ( $at[ $path ] ?? 0 );
		}
		foreach ( $candidates as $path ) {
			$this->candidates[ $path ] = (int) ( $there[ $path ] ?? 0 );
		}
		$this->routes += $routes;
		foreach ( $shown as $kind => $slugs ) {
			foreach ( $slugs as $slug => $there_now ) {
				$this->shown[ (string) $kind ][ (string) $slug ] = ( $this->shown[ (string) $kind ][ (string) $slug ] ?? false ) || $there_now;
			}
		}
	}

	/**
	 * Refuses a post type other than the view's: its facts are of that type only.
	 *
	 * @param string $post_type Post type name.
	 * @throws InvalidArgumentException For another type.
	 */
	private function own( string $post_type ): void {
		if ( $post_type !== $this->post_type ) {
			throw new InvalidArgumentException( 'The facts read are of another post type.' );
		}
	}

	/**
	 * The fact read for a path. A path not read yet is noted with its shorter
	 * prefixes, which the rules ask for next.
	 *
	 * @param string $prefix Normalized path.
	 * @return array{live:int,held:bool,newest:bool,trashed:int[]}
	 * @throws OutOfBoundsException When the path was not read yet.
	 */
	private function fact( string $prefix ): array {
		if ( isset( $this->facts[ $prefix ] ) ) {
			return $this->facts[ $prefix ];
		}
		$segments = explode( '/', $prefix );
		for ( $length = count( $segments ); $length >= 1; $length-- ) {
			$shorter = implode( '/', array_slice( $segments, 0, $length ) );
			if ( ! isset( $this->facts[ $shorter ] ) ) {
				$this->needs['facts'][ $shorter ] = true;
			}
		}
		throw new OutOfBoundsException( 'Not read yet.' );
	}
}
