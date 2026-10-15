<?php
/**
 * The outcome notice after a trash, a restore or a URL-changing save, background progress, and the redirects nudge.
 *
 * @package HierarchyGuard
 */

declare( strict_types=1 );

namespace HierarchyGuard\Admin;

use HierarchyGuard\Batch\Queue;
use HierarchyGuard\Export\Csv;
use HierarchyGuard\Ledger;
use HierarchyGuard\Overview;
use HierarchyGuard\Post_Types;
use HierarchyGuard\REST\Hierarchy_Controller;
use HierarchyGuard\Redirects\Pairs;
use HierarchyGuard\Redirects\Providers;
use HierarchyGuard\Settings;
use HierarchyGuard\Subtree;
use HierarchyGuard\Trash\Change_Log;
use WP_Post;
use WP_Screen;

/**
 * Trashing from the editor or the Pages list lands on core's "moved to the
 * Trash" notice. This adds what happened to the subpages to it once per
 * change, keeping core's Undo link last, and prints the URLs the trash
 * changed as a notice of its own, styled by what handles them. A Bulk Edit
 * that changed URLs gets the same URLs notice on the list core returns to,
 * and a save in the classic editor on the edit screen it lands on, each
 * only there. While a background batch runs, the list screen says how far
 * it got.
 */
final class Notices {

	public const NUDGE_POINTER = 'hierarchy_guard_nudge';
	public const MAX_PROGRESS  = 3;

	/**
	 * Change log.
	 *
	 * @var Change_Log
	 */
	private Change_Log $log;

	/**
	 * Queue.
	 *
	 * @var Queue
	 */
	private Queue $queue;

	/**
	 * Pairs.
	 *
	 * @var Pairs
	 */
	private Pairs $pairs;

	/**
	 * Providers.
	 *
	 * @var Providers
	 */
	private Providers $providers;

	/**
	 * The change core's notice showed on this request, if any.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $shown = null;

	/**
	 * Constructor.
	 *
	 * @param Change_Log $log       Change log.
	 * @param Queue      $queue     Queue.
	 * @param Pairs      $pairs     Pairs.
	 * @param Providers  $providers Redirect plugin detection.
	 */
	public function __construct( Change_Log $log, Queue $queue, Pairs $pairs, Providers $providers ) {
		$this->log       = $log;
		$this->queue     = $queue;
		$this->pairs     = $pairs;
		$this->providers = $providers;
	}

	/**
	 * Hooks into core.
	 */
	public function register(): void {
		add_filter( 'bulk_post_updated_messages', [ $this, 'append' ], 10, 2 );
		add_action( 'admin_notices', [ $this, 'render' ] );
	}

	/**
	 * Adds what happened to the subpages to core's trashed or untrashed message.
	 *
	 * @param mixed $messages Messages by post type, then by action.
	 * @param mixed $counts   Counts by action.
	 * @return mixed
	 */
	public function append( $messages, $counts ) {
		if ( ! is_array( $messages ) || ! is_array( $counts ) || ( empty( $counts['trashed'] ) && empty( $counts['untrashed'] ) ) ) {
			return $messages;
		}
		$type = (string) ( $GLOBALS['typenow'] ?? '' );
		if ( '' === $type || ! Post_Types::is_supported( $type ) ) {
			return $messages;
		}

		$user   = get_current_user_id();
		$change = $this->log->latest( $user );
		// A save's notice is its own, on the screen the save was made from.
		if ( null === $change || ! empty( $change['shown'] ) || $type !== $change['post_type'] || ! in_array( $change['action'], [ 'trash', 'restore' ], true ) ) {
			return $messages;
		}
		$slot = 'trash' === $change['action'] ? 'trashed' : 'untrashed';
		if ( empty( $counts[ $slot ] ) || ! $this->matches( $change ) ) {
			return $messages;
		}
		$key = isset( $messages[ $type ][ $slot ] ) ? $type : 'post';
		if ( ! isset( $messages[ $key ][ $slot ] ) ) {
			return $messages;
		}

		$this->log->mark_shown( $user, (string) $change['id'] );
		$this->shown = $change;

		$extra = array_map( 'esc_html', self::sentences( $change ) );
		if ( [] === $extra ) {
			return $messages;
		}

		$roots = array_values( (array) $change['roots'] );
		$first = (string) $messages[ $key ][ $slot ];
		if ( 1 === (int) $counts[ $slot ] && 1 === count( $roots ) ) {
			$title = esc_html( Overview::title( (int) $roots[0]['root'] ) );
			$first = 'trashed' === $slot
				/* translators: %s: page title. */
				? sprintf( __( '“%s” moved to the Trash.', 'hierarchy-guard' ), $title )
				/* translators: %s: page title. */
				: sprintf( __( '“%s” restored from the Trash.', 'hierarchy-guard' ), $title );
			// Core runs the message through sprintf() with the count.
			$first = str_replace( '%', '%%', $first );
		}

		$messages[ $key ][ $slot ] = $first . ' ' . str_replace( '%', '%%', implode( ' ', $extra ) );

		return $messages;
	}

	/**
	 * The notice about the URLs a trash or a save changed: its type and its HTML.
	 *
	 * @param array<string,mixed> $change A trash or save change.
	 * @return array{type:string,message:string}|null Null when no URL changed.
	 */
	public function urls_notice( array $change ): ?array {
		if ( ! in_array( $change['action'] ?? '', [ 'trash', 'save' ], true ) ) {
			return null;
		}
		$count = $this->pairs->count( $this->pairs->collect_change( $change ) );
		$n     = $count['count'];
		if ( 0 === $n ) {
			return null;
		}
		$shown = Subtree::format_count( $n, $count['capped'] );

		if ( Settings::serve_redirects() ) {
			return [
				'type'    => 'success',
				/* translators: %s: number of old URLs, such as 12 or 1,000+. */
				'message' => esc_html( sprintf( _n( '%s old URL now redirects to its new page.', '%s old URLs now redirect to their new pages.', $n, 'hierarchy-guard' ), $shown ) ),
			];
		}

		$provider = $this->providers->detect();
		$layout   = null !== $provider ? Csv::layout( (string) ( $provider['csv'] ?? 'generic' ) ) : 'generic';
		$view     = sprintf(
			'<a href="%1$s" class="hierarchy-guard-view-redirects" data-change="%2$s" data-label="%3$s" download>%4$s</a>',
			esc_url( self::change_csv_url( (string) $change['id'], $layout ) ),
			esc_attr( (string) $change['id'] ),
			/* translators: %s: number of redirects, such as 12 or 1,000+. */
			esc_attr( sprintf( __( 'View redirects (%s)', 'hierarchy-guard' ), $shown ) ),
			esc_html__( 'Download redirects (CSV)', 'hierarchy-guard' )
		);

		if ( null !== $provider ) {
			$name = trim( (string) ( $provider['name'] ?? '' ) );
			/* translators: %s: number of URLs, such as 12 or 1,000+. */
			$text  = sprintf( _n( '%s URL changed.', '%s URLs changed.', $n, 'hierarchy-guard' ), $shown ) . ' ';
			$text .= '' !== $name
				/* translators: %s: name of the redirect plugin. */
				? sprintf( _n( 'Add its redirect in %s.', 'Add their redirects in %s.', $n, 'hierarchy-guard' ), $name )
				: _n( 'Add its redirect in your redirect plugin.', 'Add their redirects in your redirect plugin.', $n, 'hierarchy-guard' );
			return [
				'type'    => 'info',
				'message' => esc_html( $text ) . ' ' . $view,
			];
		}

		/* translators: %s: number of old URLs, such as 12 or 1,000+. */
		$message = esc_html( sprintf( _n( '%s old URL now shows “Page not found”.', '%s old URLs now show “Page not found”.', $n, 'hierarchy-guard' ), $shown ) ) . ' ' . $view;
		if ( current_user_can( 'manage_options' ) ) {
			$message .= sprintf( '<span aria-hidden="true"> ·</span> <a href="%1$s">%2$s</a>', esc_url( Settings_Page::redirects_url() ), esc_html__( 'Set up redirects', 'hierarchy-guard' ) );
		}
		return [
			'type'    => 'warning',
			'message' => $message,
		];
	}

	/**
	 * The download URL for a change's pairs, signed for the REST cookie check.
	 *
	 * @param string $change_id Change ID.
	 * @param string $layout    CSV layout.
	 * @return string
	 */
	public static function change_csv_url( string $change_id, string $layout ): string {
		return add_query_arg(
			[
				'format'   => $layout,
				'_wpnonce' => wp_create_nonce( 'wp_rest' ),
			],
			rest_url( Hierarchy_Controller::NAMESPACE . '/changes/' . rawurlencode( $change_id ) . '/csv' )
		);
	}

	/**
	 * Prints the URLs notice of a trash or a Bulk Edit, background progress and
	 * the redirects nudge on supported list screens, and a save's URLs notice
	 * on the classic edit screen.
	 */
	public function render(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof WP_Screen || ! Post_Types::is_supported( (string) $screen->post_type ) ) {
			return;
		}
		if ( 'post' === $screen->base ) {
			$this->render_save( $screen );
			return;
		}
		if ( 'edit' !== $screen->base ) {
			return;
		}

		if ( null !== $this->shown ) {
			$notice = $this->urls_notice( $this->shown );
			if ( null !== $notice ) {
				do_action( 'hierarchy_guard_list_script' );
				self::print_urls( $notice );
			}
			// The trash notice on this load already says what is still running and what to do.
			return;
		}
		if ( $this->render_list_save( (string) $screen->post_type ) ) {
			return;
		}

		foreach ( $this->progress_sentences( (string) $screen->post_type ) as $sentence ) {
			wp_admin_notice(
				esc_html( $sentence ),
				[
					'type'               => 'info',
					'additional_classes' => [ 'hierarchy-guard-progress' ],
				]
			);
		}

		if ( $this->wants_nudge() ) {
			do_action( 'hierarchy_guard_list_script' );
			wp_admin_notice(
				sprintf(
					'%1$s <a href="%2$s">%3$s</a>',
					esc_html__( 'Hierarchy Guard has recorded old page URLs, but nothing on this site redirects them yet.', 'hierarchy-guard' ),
					esc_url( Settings_Page::redirects_url() ),
					esc_html__( 'Set up redirects', 'hierarchy-guard' )
				),
				[
					'type'               => 'info',
					'dismissible'        => true,
					'additional_classes' => [ 'hierarchy-guard-nudge' ],
				]
			);
		}
	}

	/**
	 * Prints the URLs notice of the user's latest change on the classic edit
	 * screen, once, when that change is a save of the post being edited made
	 * in the classic editor: the screen that save lands on. Its link
	 * downloads the CSV, which needs no script. The block editor and Quick
	 * Edit raise their own notice, and a Bulk Edit's prints on the Pages list.
	 *
	 * @param WP_Screen $screen Edit screen.
	 */
	private function render_save( WP_Screen $screen ): void {
		$post = get_post();
		if ( $screen->is_block_editor() || ! $post instanceof WP_Post ) {
			return;
		}
		$user   = get_current_user_id();
		$change = $this->log->latest( $user );
		if ( null === $change || 'save' !== $change['action'] || 'classic' !== ( $change['origin'] ?? '' ) || ! empty( $change['shown'] ) || ! isset( $change['roots'][ (int) $post->ID ] ) ) {
			return;
		}

		$notice = $this->urls_notice( $change );
		$this->log->mark_shown( $user, (string) $change['id'] );
		if ( null !== $notice ) {
			self::print_urls( $notice );
		}
	}

	/**
	 * Prints the URLs notice of the user's latest change on the list screen
	 * of its type, once, when that change is an unshown save made on the
	 * list: a Bulk Edit's, as core redirects back here after it. Quick Edit
	 * shows its own change in place and records it as shown.
	 *
	 * @param string $post_type List screen's post type.
	 * @return bool Whether it printed the notice.
	 */
	private function render_list_save( string $post_type ): bool {
		$user   = get_current_user_id();
		$change = $this->log->latest( $user );
		if ( null === $change || 'save' !== $change['action'] || 'list' !== ( $change['origin'] ?? '' ) || ! empty( $change['shown'] ) || $post_type !== $change['post_type'] ) {
			return false;
		}

		$notice = $this->urls_notice( $change );
		$this->log->mark_shown( $user, (string) $change['id'] );
		if ( null === $notice ) {
			return false;
		}
		// The list script turns its download link into the modal's button.
		do_action( 'hierarchy_guard_list_script' );
		self::print_urls( $notice );
		return true;
	}

	/**
	 * Prints a URLs notice.
	 *
	 * @param array{type:string,message:string} $notice From urls_notice().
	 */
	private static function print_urls( array $notice ): void {
		wp_admin_notice(
			$notice['message'],
			[
				'type'               => $notice['type'],
				'dismissible'        => true,
				'additional_classes' => [ 'hierarchy-guard-urls' ],
			]
		);
	}

	/**
	 * One sentence per root of the user's change whose batch is still running,
	 * for the first few, then one for the rest.
	 *
	 * @param string $post_type List screen's post type.
	 * @return string[]
	 */
	public function progress_sentences( string $post_type ): array {
		$change = $this->log->latest( get_current_user_id() );
		if ( null !== $this->shown || null === $change || $post_type !== $change['post_type'] ) {
			return [];
		}

		$running = array_values( array_filter( (array) $change['roots'], static fn( array $root ): bool => ! empty( $root['job'] ) ) );
		$first   = array_slice( $running, 0, self::MAX_PROGRESS );
		_prime_post_caches( array_map( static fn( array $root ): int => (int) $root['root'], $first ), false, false );

		$out = [];
		foreach ( $first as $root ) {
			$job = $this->queue->load( (int) $root['root'] );
			if ( null === $job || $job['change'] !== $change['id'] ) {
				continue;
			}
			$counts = (array) $job['counts'];
			$done   = (int) $counts['moved'] + (int) $counts['trashed'] + (int) $counts['restored'] + (int) $counts['kept'] + (int) $counts['left'];
			$title  = Overview::title( (int) $root['root'] );
			$total  = (int) $job['total'];
			$known  = $total > 0 && $total < Subtree::count_cap();

			if ( 'restore' === $change['action'] ) {
				$out[] = $known
					/* translators: 1: page title, 2: subpages done, 3: subpages in all. */
					? sprintf( __( 'Still restoring the subpages of “%1$s” in the background: %2$s of %3$s done.', 'hierarchy-guard' ), $title, number_format_i18n( $done ), number_format_i18n( $total ) )
					/* translators: 1: page title, 2: subpages done. */
					: sprintf( __( 'Still restoring the subpages of “%1$s” in the background: %2$s done so far.', 'hierarchy-guard' ), $title, number_format_i18n( $done ) );
			} elseif ( Settings::POLICY_TRASH_CHILDREN === $root['policy'] ) {
				$out[] = $known
					/* translators: 1: page title, 2: subpages done, 3: subpages in all. */
					? sprintf( __( 'Still moving the subpages of “%1$s” to the Trash in the background: %2$s of %3$s done.', 'hierarchy-guard' ), $title, number_format_i18n( $done ), number_format_i18n( $total ) )
					/* translators: 1: page title, 2: subpages done. */
					: sprintf( __( 'Still moving the subpages of “%1$s” to the Trash in the background: %2$s done so far.', 'hierarchy-guard' ), $title, number_format_i18n( $done ) );
			} else {
				$out[] = $known
					/* translators: 1: page title, 2: subpages done, 3: subpages in all. */
					? sprintf( __( 'Still moving the subpages of “%1$s” in the background: %2$s of %3$s done.', 'hierarchy-guard' ), $title, number_format_i18n( $done ), number_format_i18n( $total ) )
					/* translators: 1: page title, 2: subpages done. */
					: sprintf( __( 'Still moving the subpages of “%1$s” in the background: %2$s done so far.', 'hierarchy-guard' ), $title, number_format_i18n( $done ) );
			}
		}

		$rest = count( $running ) - count( $first );
		if ( $rest > 0 && [] !== $out ) {
			/* translators: %s: number of pages. */
			$out[] = sprintf( _n( 'The subpages of %s more page are still being worked on in the background.', 'The subpages of %s more pages are still being worked on in the background.', $rest, 'hierarchy-guard' ), number_format_i18n( $rest ) );
		}
		return $out;
	}

	/**
	 * Whether to nudge this user to turn on redirects: old URLs were recorded,
	 * nothing on the site serves them, the user can change that, and has not
	 * dismissed the nudge.
	 *
	 * @return bool
	 */
	public function wants_nudge(): bool {
		if ( Settings::serve_redirects() || ! current_user_can( 'manage_options' ) || null !== $this->providers->detect() ) {
			return false;
		}
		$dismissed = explode( ',', (string) get_user_meta( get_current_user_id(), 'dismissed_wp_pointers', true ) );
		return ! in_array( self::NUDGE_POINTER, $dismissed, true ) && self::has_old_urls();
	}

	/**
	 * Whether the ledger holds any old URL. One index probe, cached until the ledger changes.
	 *
	 * @return bool
	 */
	public static function has_old_urls(): bool {
		global $wpdb;

		$key = 'has_old_urls:' . wp_cache_get_last_changed( Ledger::CACHE_GROUP );
		$hit = wp_cache_get( $key, Ledger::CACHE_GROUP );
		if ( false !== $hit ) {
			return (bool) $hit;
		}

		$roots = get_option( Ledger::ROOT_OPTION, [] );
		$found = is_array( $roots ) && [] !== array_filter( $roots );
		if ( ! $found ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- A prefix range on the meta_key index, LIMIT 1, cached below.
			$found = null !== $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key LIKE %s LIMIT 1", $wpdb->esc_like( Ledger::KEY_PREFIX ) . '%' ) );
		}
		wp_cache_set( $key, $found ? 1 : 0, Ledger::CACHE_GROUP, HOUR_IN_SECONDS );

		return $found;
	}

	/**
	 * What happened to the subpages, one translatable sentence each.
	 *
	 * @param array<string,mixed> $change A change from Change_Log.
	 * @return string[]
	 */
	public static function sentences( array $change ): array {
		$roots  = array_values( (array) $change['roots'] );
		$single = 1 === count( $roots ) ? $roots[0] : null;
		$counts = array_map( 'intval', (array) $change['counts'] );
		$out    = [];

		if ( 'restore' === $change['action'] ) {
			$out = array_merge( $out, self::restore_sentences( $counts, $single ) );
		} else {
			$out = array_merge( $out, self::trash_sentences( $counts, $single, $roots ) );
		}

		foreach ( $roots as $root ) {
			if ( ! empty( $root['job'] ) ) {
				$out[] = Settings::POLICY_TRASH_CHILDREN === $root['policy']
					? __( 'The rest are still going to the Trash in the background.', 'hierarchy-guard' )
					: __( 'The rest are still moving in the background.', 'hierarchy-guard' );
				break;
			}
		}

		return $out;
	}

	/**
	 * Sentences for a trash.
	 *
	 * @param array<string,int>                    $counts Summed counts.
	 * @param array<string,mixed>|null             $single The only root, or null for several.
	 * @param array<int,array<string,mixed>>       $roots  Every root.
	 * @return string[]
	 */
	private static function trash_sentences( array $counts, ?array $single, array $roots ): array {
		$out = [];

		if ( $counts['moved'] > 0 ) {
			if ( null !== $single && (int) $single['to'] > 0 ) {
				/* translators: 1: number of subpages, 2: title of the page they now sit under. */
				$out[] = sprintf( _n( '%1$s subpage moved up under “%2$s”.', '%1$s subpages moved up under “%2$s”.', $counts['moved'], 'hierarchy-guard' ), number_format_i18n( $counts['moved'] ), Overview::title( (int) $single['to'] ) );
			} elseif ( null !== $single ) {
				/* translators: %s: number of subpages. */
				$out[] = sprintf( _n( '%s subpage is now a top-level page.', '%s subpages are now top-level pages.', $counts['moved'], 'hierarchy-guard' ), number_format_i18n( $counts['moved'] ) );
			} else {
				/* translators: %s: number of subpages. */
				$out[] = sprintf( _n( '%s subpage moved up one level.', '%s subpages moved up one level.', $counts['moved'], 'hierarchy-guard' ), number_format_i18n( $counts['moved'] ) );
			}
		}

		if ( $counts['trashed'] > 0 ) {
			/* translators: %s: number of subpages. */
			$out[] = sprintf( _n( '%s subpage moved to the Trash too.', '%s subpages moved to the Trash too.', $counts['trashed'], 'hierarchy-guard' ), number_format_i18n( $counts['trashed'] ) );
		}

		$stayed = $counts['kept'] - $counts['denied'];
		if ( $stayed > 0 ) {
			if ( null !== $single && Settings::POLICY_KEEP === $single['policy'] ) {
				/* translators: %s: number of subpages. */
				$out[] = sprintf( _n( 'Its %s subpage stayed where it is.', 'Its %s subpages stayed where they are.', $stayed, 'hierarchy-guard' ), number_format_i18n( $stayed ) );
			} else {
				/* translators: %s: number of subpages. */
				$out[] = sprintf( _n( '%s subpage stayed where it is.', '%s subpages stayed where they are.', $stayed, 'hierarchy-guard' ), number_format_i18n( $stayed ) );
			}
		}

		if ( $counts['denied'] > 0 ) {
			/* translators: %s: number of subpages. */
			$out[] = sprintf( _n( '%s subpage you don’t have permission to change stayed where it is.', '%s subpages you don’t have permission to change stayed where they are.', $counts['denied'], 'hierarchy-guard' ), number_format_i18n( $counts['denied'] ) );
		}

		foreach ( $roots as $root ) {
			if ( ! empty( $root['fallback'] ) ) {
				$cap = Subtree::count_cap();
				/* translators: %s: the most subpages the plugin trashes together with a page, such as 1,000. */
				$out[] = sprintf( _n( 'There was more than %s subpage, so they moved up instead of going to the Trash.', 'There were more than %s subpages, so they moved up instead of going to the Trash.', $cap, 'hierarchy-guard' ), number_format_i18n( $cap ) );
				break;
			}
		}

		return $out;
	}

	/**
	 * Sentences for a restore.
	 *
	 * @param array<string,int>        $counts Summed counts.
	 * @param array<string,mixed>|null $single The only root, or null for several.
	 * @return string[]
	 */
	private static function restore_sentences( array $counts, ?array $single ): array {
		$out = [];

		if ( $counts['moved'] > 0 ) {
			if ( null !== $single ) {
				/* translators: 1: number of subpages, 2: title of the restored page. */
				$out[] = sprintf( _n( '%1$s subpage moved back under “%2$s”.', '%1$s subpages moved back under “%2$s”.', $counts['moved'], 'hierarchy-guard' ), number_format_i18n( $counts['moved'] ), Overview::title( (int) $single['root'] ) );
			} else {
				/* translators: %s: number of subpages. */
				$out[] = sprintf( _n( '%s subpage moved back under its parent.', '%s subpages moved back under their parents.', $counts['moved'], 'hierarchy-guard' ), number_format_i18n( $counts['moved'] ) );
			}
		}

		if ( $counts['restored'] > 0 ) {
			$out[] = null !== $single
				/* translators: %s: number of subpages. */
				? sprintf( _n( '%s subpage was restored with it.', '%s subpages were restored with it.', $counts['restored'], 'hierarchy-guard' ), number_format_i18n( $counts['restored'] ) )
				/* translators: %s: number of subpages. */
				: sprintf( _n( '%s subpage was restored too.', '%s subpages were restored too.', $counts['restored'], 'hierarchy-guard' ), number_format_i18n( $counts['restored'] ) );
		}

		if ( $counts['left'] > 0 ) {
			/* translators: %s: number of subpages. */
			$out[] = sprintf( _n( '%s subpage couldn’t be brought back and stayed where it is.', '%s subpages couldn’t be brought back and stayed where they are.', $counts['left'], 'hierarchy-guard' ), number_format_i18n( $counts['left'] ) );
		}

		if ( $counts['renamed'] > 0 ) {
			/* translators: %s: number of subpages. */
			$out[] = sprintf( _n( '%s subpage couldn’t get its old URL back.', '%s subpages couldn’t get their old URLs back.', $counts['renamed'], 'hierarchy-guard' ), number_format_i18n( $counts['renamed'] ) );
		}

		return $out;
	}

	/**
	 * Whether the notice on screen belongs to this change.
	 *
	 * @param array<string,mixed> $change Change.
	 * @return bool
	 */
	private function matches( array $change ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read only, to pick the notice text.
		$raw = isset( $_REQUEST['ids'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['ids'] ) ) : '';
		if ( '' === $raw ) {
			// Core's bulk restore redirects without the IDs; only a change made moments ago belongs to it.
			return time() - (int) $change['time'] <= MINUTE_IN_SECONDS;
		}
		$ids = array_map( 'absint', explode( ',', $raw ) );
		return [] !== array_intersect( $ids, array_map( 'intval', array_keys( (array) $change['roots'] ) ) );
	}
}
