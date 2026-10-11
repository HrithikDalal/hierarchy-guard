/**
 * Every sentence the scripts show, in one place so it can be tested.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import config from './config';

export const POLICIES = [ 'reparent', 'trash_children', 'keep' ];

/**
 * A number in the user's locale, as PHP's number_format_i18n() writes it:
 * thousands always grouped, even where the locale's own rule leaves 1000
 * alone. A WordPress locale that is not a valid language tag, such as
 * pt-PT-ao90, falls back to its language and region, then to the default.
 *
 * @param {number} count Number.
 * @return {string} Formatted number.
 */
function formatNumber( count ) {
	const locale = config.locale || undefined;
	const tags = locale
		? [ locale, locale.split( '-' ).slice( 0, 2 ).join( '-' ) ]
		: [];
	for ( const tag of tags ) {
		try {
			return new Intl.NumberFormat( tag, { useGrouping: true } ).format(
				count
			);
		} catch {
			// Not a tag Intl accepts: try the next one.
		}
	}
	return new Intl.NumberFormat( undefined, { useGrouping: true } ).format(
		count
	);
}

/**
 * A count for a sentence: 1,000 stays 1,000, a capped count becomes 1,000+.
 *
 * @param {number}  count  Count.
 * @param {boolean} capped Whether more exist.
 * @return {string} Formatted count.
 */
export function formatCount( count, capped = false ) {
	const number = formatNumber( Number( count ) );
	return capped
		? sprintf(
				/* translators: %s: a number that is a lower bound, such as 1,000. */
				__( '%s+', 'hierarchy-guard' ),
				number
			)
		: number;
}

/**
 * "3 subpages".
 *
 * @param {number}  count  Count.
 * @param {boolean} capped Whether more exist.
 * @return {string} Label.
 */
export function subpagesLabel( count, capped = false ) {
	return sprintf(
		/* translators: %s: number of subpages, such as 3 or 1,000+. */
		_n( '%s subpage', '%s subpages', count, 'hierarchy-guard' ),
		formatCount( count, capped )
	);
}

/**
 * "4 direct · 12 in total".
 *
 * @param {Object} data Hierarchy from the REST route.
 * @return {string} Line.
 */
export function countsLine( data ) {
	return sprintf(
		/* translators: 1: number of direct subpages, 2: number of subpages at every level. */
		__( '%1$s direct · %2$s in total', 'hierarchy-guard' ),
		formatCount( data.children_total, data.children_capped ),
		formatCount( data.descendants.count, data.descendants.capped )
	);
}

/**
 * "View all 12 subpages in the list".
 *
 * @param {number}  count  Subpages at every level.
 * @param {boolean} capped Whether more exist.
 * @return {string} Label.
 */
export function viewAllLabel( count, capped ) {
	return sprintf(
		/* translators: %s: number of subpages in all, such as 12 or 1,000+. */
		_n(
			'View all %s subpage in the list',
			'View all %s subpages in the list',
			count,
			'hierarchy-guard'
		),
		formatCount( count, capped )
	);
}

/**
 * The URL a kept subpage gets while its parent is in the trash.
 *
 * @param {Object} page A page from the trash preview.
 * @return {string} URL relative to the home URL, or an empty string.
 */
export function keptUrl( page ) {
	if ( ! page.example || ! page.path ) {
		return '';
	}
	// With or without a trailing slash, as the permalink structure has it.
	const base = page.path.replace( /\/$/, '' );
	return page.example.from.startsWith( base + '/' )
		? base + '__trashed' + page.example.from.slice( base.length )
		: '';
}

/**
 * The dialog's title, known when the Trash link is clicked.
 *
 * @param {number} selected Pages selected.
 * @return {string} Title.
 */
export function dialogTitle( selected ) {
	return 1 === selected
		? __( 'Move this page to the Trash?', 'hierarchy-guard' )
		: sprintf(
				/* translators: %s: number of selected pages. */
				_n(
					'Move %s page to the Trash?',
					'Move %s pages to the Trash?',
					selected,
					'hierarchy-guard'
				),
				formatCount( selected )
			);
}

/**
 * What a policy does, without any page's details.
 *
 * @param {string} policy Policy.
 * @return {string} Sentence.
 */
function genericTail( policy ) {
	const tails = {
		reparent: __(
			'The subpages move up one level and get new URLs.',
			'hierarchy-guard'
		),
		trash_children: __(
			'The subpages go to the Trash too, and come back with their statuses if you restore their parent.',
			'hierarchy-guard'
		),
		keep: __(
			'The subpages stay where they are, and their URLs will contain “__trashed” until you move them.',
			'hierarchy-guard'
		),
	};
	return tails[ policy ] || tails.reparent;
}

/**
 * The sentence shown while the dialog checks the subpages, from the row alone.
 *
 * @param {string} title    Page title.
 * @param {number} children Direct subpages, from the Hierarchy column.
 * @param {string} policy   Policy.
 * @return {string} Sentence.
 */
export function initialSentence( title, children, policy ) {
	return (
		sprintf(
			/* translators: 1: page title, 2: number of subpages. */
			_n(
				'“%1$s” has %2$s subpage.',
				'“%1$s” has %2$s subpages.',
				Math.min( children, config.cap ),
				'hierarchy-guard'
			),
			title,
			formatCount(
				Math.min( children, config.cap ),
				children > config.cap
			)
		) +
		' ' +
		genericTail( policy )
	);
}

/**
 * What trashing one page does to its subpages under a policy.
 *
 * @param {Object} page   A page from the trash preview.
 * @param {string} policy Policy.
 * @param {number} cap    Count cap.
 * @return {string} Sentence.
 */
export function trashSentence( page, policy, cap ) {
	const children = page.children;
	const all = page.descendants.count;
	const head =
		all > children
			? sprintf(
					/* translators: 1: page title, 2: number of direct subpages, 3: number of subpages at every level. */
					_n(
						'“%1$s” has %2$s subpage directly under it, %3$s in all.',
						'“%1$s” has %2$s subpages directly under it, %3$s in all.',
						children,
						'hierarchy-guard'
					),
					page.title,
					formatCount( children ),
					formatCount( all, page.descendants.capped )
				)
			: sprintf(
					/* translators: 1: page title, 2: number of subpages. */
					_n(
						'“%1$s” has %2$s subpage.',
						'“%1$s” has %2$s subpages.',
						children,
						'hierarchy-guard'
					),
					page.title,
					formatCount( children, page.descendants.capped )
				);

	let tail;
	if ( 'trash_children' === policy && true === page.fallback ) {
		tail = sprintf(
			/* translators: %s: the most subpages trashed together with a page, such as 1,000. */
			__(
				'That is more than %s, so they’ll move up one level instead of going to the Trash.',
				'hierarchy-guard'
			),
			formatCount( cap )
		);
	} else if ( 'trash_children' === policy ) {
		tail = sprintf(
			/* translators: %s: page title. */
			_n(
				'It goes to the Trash too, and comes back with its status if you restore “%s”.',
				'They go to the Trash too, and come back with their statuses if you restore “%s”.',
				all,
				'hierarchy-guard'
			),
			page.title
		);
	} else if ( 'keep' === policy ) {
		tail = _n(
			'It stays where it is, and its URL changes until you move it.',
			'They stay where they are, and their URLs change until you move them.',
			children,
			'hierarchy-guard'
		);
	} else if ( page.to ) {
		tail = sprintf(
			/* translators: %s: title of the page the subpages move under. */
			_n(
				'It will move up under “%s” and get a new URL.',
				'They’ll move up under “%s” and get new URLs.',
				children,
				'hierarchy-guard'
			),
			page.to.title
		);
	} else {
		tail = _n(
			'It will become a top-level page and get a new URL.',
			'They’ll become top-level pages and get new URLs.',
			children,
			'hierarchy-guard'
		);
	}
	return head + ' ' + tail;
}

/**
 * What a bulk trash does to the subpages of the selected pages.
 *
 * @param {Object} preview      Trash preview.
 * @param {string} policy       Policy.
 * @param {number} withChildren Selected pages with subpages, counted on the screen.
 * @param {number} cap          Count cap.
 * @return {string} Sentence.
 */
export function bulkSentence( preview, policy, withChildren, cap ) {
	const parents = Math.max( withChildren, preview.pages.length );
	let out =
		sprintf(
			/* translators: 1: number of selected pages with subpages, 2: number of subpages at every level, such as 19 or 1,000+. */
			_n(
				'%1$s of the selected pages has subpages, %2$s in all.',
				'%1$s of the selected pages have subpages, %2$s in all.',
				parents,
				'hierarchy-guard'
			),
			formatCount( parents ),
			formatCount(
				preview.total.count,
				preview.total.capped || preview.more > 0
			)
		) +
		' ' +
		genericTail( policy );
	if (
		'trash_children' === policy &&
		preview.pages.some( ( page ) => false !== page.fallback )
	) {
		out +=
			' ' +
			sprintf(
				/* translators: %s: the most subpages trashed together with a page, such as 1,000. */
				__(
					'A page with more than %s subpages has them moved up instead.',
					'hierarchy-guard'
				),
				formatCount( cap )
			);
	}
	return out;
}

/**
 * The label of a policy option in the trash dialog.
 *
 * @param {string}      policy Policy.
 * @param {Object|null} page   The only page with subpages, or null for several.
 * @return {string} Label.
 */
export function optionLabel( policy, page ) {
	if ( 'trash_children' === policy ) {
		return page
			? sprintf(
					/* translators: %s: number of subpages at every level. */
					_n(
						'Trash its %s subpage too',
						'Trash all %s subpages too',
						page.descendants.count,
						'hierarchy-guard'
					),
					formatCount(
						page.descendants.count,
						page.descendants.capped
					)
				)
			: __( 'Trash the subpages too', 'hierarchy-guard' );
	}
	if ( 'keep' === policy ) {
		return __( 'Leave subpages where they are', 'hierarchy-guard' );
	}
	if ( page && page.to ) {
		return sprintf(
			/* translators: %s: title of the page the subpages move under. */
			__( 'Move the subpages up under “%s”', 'hierarchy-guard' ),
			page.to.title
		);
	}
	return page
		? __( 'Make the subpages top-level pages', 'hierarchy-guard' )
		: __( 'Move subpages up one level', 'hierarchy-guard' );
}

/**
 * The description under a policy option. A sentence with URLs in it is a
 * template: <from /> and <to /> mark where the dialog puts the URLs.
 *
 * @param {string}      policy Policy.
 * @param {Object|null} page   The only page with subpages, or null for several.
 * @param {number}      cap    Count cap.
 * @return {string} Description or template.
 */
export function optionDescription( policy, page, cap ) {
	if ( 'trash_children' === policy ) {
		if ( page && true === page.fallback ) {
			return sprintf(
				/* translators: %s: the most subpages trashed together with a page, such as 1,000. */
				__(
					'Not available: there are more than %s.',
					'hierarchy-guard'
				),
				formatCount( cap )
			);
		}
		return page
			? sprintf(
					/* translators: %s: page title. */
					__(
						'They come back with their statuses if you restore “%s”.',
						'hierarchy-guard'
					),
					page.title
				)
			: __(
					'They come back with their statuses if you restore their parent.',
					'hierarchy-guard'
				);
	}
	if ( 'keep' === policy ) {
		return page && keptUrl( page )
			? __(
					'Their URLs change until you move them, for example <from /> becomes <to />.',
					'hierarchy-guard'
				)
			: __(
					'Their URLs will contain “__trashed” until you move them.',
					'hierarchy-guard'
				);
	}
	if ( page && page.example ) {
		return __(
			'Their URLs change, for example <from /> becomes <to />.',
			'hierarchy-guard'
		);
	}
	return __( 'Their URLs change.', 'hierarchy-guard' );
}

/**
 * The trash dialog's note when some subpages can't be moved or trashed.
 *
 * @param {string} policy Policy.
 * @return {string} Sentence.
 */
export function deniedSentence( policy ) {
	const first =
		'trash_children' === policy
			? __( 'You can’t delete some of the subpages.', 'hierarchy-guard' )
			: __( 'You can’t edit some of the subpages.', 'hierarchy-guard' );
	return (
		first + ' ' + __( 'They’ll stay where they are.', 'hierarchy-guard' )
	);
}

/**
 * What continuing does when the subpages couldn't be checked.
 *
 * @param {string} policy Policy that will apply.
 * @return {string} Sentence.
 */
export function previewError( policy ) {
	const outcomes = {
		reparent: __(
			'If you continue, they’ll move up one level.',
			'hierarchy-guard'
		),
		trash_children: __(
			'If you continue, they’ll go to the Trash too.',
			'hierarchy-guard'
		),
		keep: __(
			'If you continue, they’ll stay where they are.',
			'hierarchy-guard'
		),
	};
	return (
		__( 'The subpages couldn’t be checked.', 'hierarchy-guard' ) +
		' ' +
		( outcomes[ policy ] || outcomes.reparent )
	);
}

/**
 * The editor's warning before a save changes the URLs of a page with subpages.
 *
 * @param {number}  count   Subpages at every level.
 * @param {boolean} capped  Whether more exist.
 * @param {boolean} serving Whether built-in redirects are on.
 * @return {string} Sentence.
 */
export function saveWarning( count, capped, serving ) {
	return (
		quickEditWarning( count, capped ) +
		' ' +
		( serving
			? __(
					'The old URLs will redirect to the new ones.',
					'hierarchy-guard'
				)
			: __(
					'You’ll get the redirects to add after saving.',
					'hierarchy-guard'
				) )
	);
}

/**
 * The classic editor's confirmation before a save changes subpage URLs.
 *
 * @param {number}  count   Subpages at every level.
 * @param {boolean} capped  Whether more exist.
 * @param {boolean} serving Whether built-in redirects are on.
 * @return {string} Sentence ending in a question.
 */
export function classicConfirm( count, capped, serving ) {
	return (
		saveWarning( count, capped, serving ) +
		' ' +
		__( 'Continue?', 'hierarchy-guard' )
	);
}

/**
 * The warning when the slug or parent of a page with subpages changes.
 *
 * @param {number}  count  Subpages at every level.
 * @param {boolean} capped Whether more exist.
 * @return {string} Sentence.
 */
export function quickEditWarning( count, capped ) {
	return sprintf(
		/* translators: %s: number of subpages, such as 12 or 1,000+. */
		_n(
			'Saving changes this page’s URL and the URL of %s subpage.',
			'Saving changes this page’s URL and the URLs of %s subpages.',
			count,
			'hierarchy-guard'
		),
		formatCount( count, capped )
	);
}

/**
 * The notice status for URLs a change moved, by what handles redirects.
 *
 * @param {boolean}     serving  Whether built-in redirects are on.
 * @param {Object|null} provider Detected redirect plugin.
 * @return {string} success, info or warning.
 */
export function changedStatus( serving, provider ) {
	if ( serving ) {
		return 'success';
	}
	return provider ? 'info' : 'warning';
}

/**
 * The sentence after a save changed URLs, by what handles redirects.
 *
 * @param {number}      count    Old URLs that now lead somewhere new.
 * @param {boolean}     capped   Whether more exist.
 * @param {boolean}     serving  Whether built-in redirects are on.
 * @param {Object|null} provider Detected redirect plugin.
 * @return {string} Sentence.
 */
export function changedSentence( count, capped, serving, provider ) {
	const shown = formatCount( count, capped );
	if ( serving ) {
		return sprintf(
			/* translators: %s: number of old URLs, such as 12 or 1,000+. */
			_n(
				'%s old URL now redirects to its new page.',
				'%s old URLs now redirect to their new pages.',
				count,
				'hierarchy-guard'
			),
			shown
		);
	}
	if ( provider ) {
		const changed = sprintf(
			/* translators: %s: number of URLs, such as 12 or 1,000+. */
			_n(
				'%s URL changed.',
				'%s URLs changed.',
				count,
				'hierarchy-guard'
			),
			shown
		);
		const add = provider.name
			? sprintf(
					/* translators: %s: name of the redirect plugin. */
					_n(
						'Add its redirect in %s.',
						'Add their redirects in %s.',
						count,
						'hierarchy-guard'
					),
					provider.name
				)
			: _n(
					'Add its redirect in your redirect plugin.',
					'Add their redirects in your redirect plugin.',
					count,
					'hierarchy-guard'
				);
		return changed + ' ' + add;
	}
	return sprintf(
		/* translators: %s: number of old URLs, such as 12 or 1,000+. */
		_n(
			'%s old URL now shows “Page not found”.',
			'%s old URLs now show “Page not found”.',
			count,
			'hierarchy-guard'
		),
		shown
	);
}

/**
 * "View redirects (12)".
 *
 * @param {number}  count  Pairs.
 * @param {boolean} capped Whether more exist.
 * @return {string} Label.
 */
export function viewRedirectsLabel( count, capped ) {
	return sprintf(
		/* translators: %s: number of redirects, such as 12 or 1,000+. */
		__( 'View redirects (%s)', 'hierarchy-guard' ),
		formatCount( count, capped )
	);
}

/**
 * What handles the redirects of a page's pairs: the server redirects them
 * whenever it serves the type, even when the page itself is not viewable,
 * since the pairs list only pages it redirects to. A page's own old URL
 * follows the page's own `serving` instead.
 *
 * @param {{serving: boolean, pairs_served: (boolean|undefined), provider: (Object|null)}} redirects The page's redirects from the REST route, or the site's.
 * @return {{serving: boolean, provider: (Object|null)}} Redirects for the pairs.
 */
export function pairsRedirects( redirects ) {
	return {
		serving: Boolean(
			undefined !== redirects.pairs_served
				? redirects.pairs_served
				: redirects.serving
		),
		provider: redirects.provider || null,
	};
}

/**
 * What an old URL's redirect looks like now, by what handles redirects.
 *
 * @param {boolean}     serving  Whether built-in redirects reach this page.
 * @param {Object|null} provider Detected redirect plugin.
 * @param {string}      state    The old URL's state from the REST route: `in_use`, `trash`, `none` or `ok`.
 * @return {string} Short state.
 */
export function oldUrlState( serving, provider, state = 'ok' ) {
	if ( 'in_use' === state ) {
		return __( 'Used by another page', 'hierarchy-guard' );
	}
	if ( 'trash' === state ) {
		return __( 'In the trash', 'hierarchy-guard' );
	}
	if ( 'none' === state ) {
		return __( 'Not redirected', 'hierarchy-guard' );
	}
	if ( serving ) {
		return __( 'Redirects here', 'hierarchy-guard' );
	}
	if ( provider && provider.name ) {
		return sprintf(
			/* translators: %s: name of the redirect plugin. */
			__( 'Add in %s', 'hierarchy-guard' ),
			provider.name
		);
	}
	return __( 'Not redirected', 'hierarchy-guard' );
}

/**
 * What trashing a page would do to its subpages, for the editor panel.
 *
 * @param {Object} data Hierarchy from the REST route.
 * @return {string} Sentence, or an empty string without subpages.
 */
export function trashNote( data ) {
	const children = data.children_total;
	const all = data.descendants.count;
	if ( ! children ) {
		return '';
	}
	if ( 'trash_children' === data.trash_policy ) {
		return sprintf(
			/* translators: %s: number of subpages at every level. */
			_n(
				'Trashing this page moves its %s subpage to the trash too.',
				'Trashing this page moves its %s subpages to the trash too.',
				all,
				'hierarchy-guard'
			),
			formatCount( all, data.descendants.capped )
		);
	}
	if ( 'keep' === data.trash_policy ) {
		return sprintf(
			/* translators: %s: number of direct subpages. */
			_n(
				'Trashing this page leaves its %s subpage where it is.',
				'Trashing this page leaves its %s subpages where they are.',
				children,
				'hierarchy-guard'
			),
			formatCount( children, data.children_capped )
		);
	}
	if ( data.trash_to ) {
		return sprintf(
			/* translators: 1: number of direct subpages, 2: title of the page they would move under. */
			_n(
				'Trashing this page moves its %1$s subpage under “%2$s”.',
				'Trashing this page moves its %1$s subpages under “%2$s”.',
				children,
				'hierarchy-guard'
			),
			formatCount( children, data.children_capped ),
			data.trash_to.title
		);
	}
	return sprintf(
		/* translators: %s: number of direct subpages. */
		_n(
			'Trashing this page makes its %s subpage a top-level page.',
			'Trashing this page makes its %s subpages top-level pages.',
			children,
			'hierarchy-guard'
		),
		formatCount( children, data.children_capped )
	);
}

/**
 * Why an old URL has no new one.
 *
 * @param {Object}  row   Unresolved row from the pairs route.
 * @param {boolean} lower Whether to say "trash" in lower case, as the block editor does.
 * @return {string} Sentence.
 */
export function unresolvedSentence( row, lower = false ) {
	if ( 'trash' === row.reason && lower ) {
		return sprintf(
			/* translators: %s: an old URL. */
			__(
				'%s is in the trash, so it has no new URL.',
				'hierarchy-guard'
			),
			row.old
		);
	}
	if ( 'trash' === row.reason ) {
		return sprintf(
			/* translators: %s: an old URL. */
			__(
				'%s is in the Trash, so it has no new URL.',
				'hierarchy-guard'
			),
			row.old
		);
	}
	if ( 'in_use' === row.reason ) {
		return sprintf(
			/* translators: %s: an old URL. */
			__(
				'%s belongs to another page now, so it needs no redirect.',
				'hierarchy-guard'
			),
			row.old
		);
	}
	if ( 'unpublished' === row.reason ) {
		return sprintf(
			/* translators: %s: an old URL. */
			__( '%s leads to a page that isn’t published.', 'hierarchy-guard' ),
			row.old
		);
	}
	return sprintf(
		/* translators: %s: an old URL. */
		__( '%s has no new URL.', 'hierarchy-guard' ),
		row.old
	);
}

/**
 * What "Copy as text" says it copied.
 *
 * @param {number}  count  Pairs copied.
 * @param {boolean} capped Whether more exist.
 * @return {string} Sentence.
 */
export function copiedSentence( count, capped ) {
	return capped
		? sprintf(
				/* translators: %s: number of redirects copied, such as 1,000. */
				__( 'Copied the first %s.', 'hierarchy-guard' ),
				formatCount( count )
			)
		: __( 'Copied.', 'hierarchy-guard' );
}

/**
 * Pairs as plain text, one "old new" line each.
 *
 * @param {Array<{old: string, new: string}>} pairs Pairs.
 * @return {string} Text.
 */
export function pairsText( pairs ) {
	return pairs.map( ( pair ) => pair.old + ' ' + pair.new ).join( '\n' );
}
