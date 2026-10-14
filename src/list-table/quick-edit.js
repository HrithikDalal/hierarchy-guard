/**
 * The Quick Edit warning when the slug or parent of a published page with
 * subpages changes, and the redirects of the change such a save recorded.
 */
import { speak } from '@wordpress/a11y';
import { __ } from '@wordpress/i18n';
import { fetchHierarchy, fetchPairs } from '../common/api';
import { changeNotice, newChange } from '../common/change-notice';
import config from '../common/config';
import { quickEditWarning } from '../common/copy';
import { open } from '../common/mount';
import RedirectsModal from '../common/redirects-modal';
import { retireUrlsNotices } from './urls-notices';

/**
 * Rows whose Quick Edit is open, with what they had when it opened and the
 * page's latest change before the save, once loaded.
 *
 * @type {Map<number, {slug: string, parent: string, latest: string|null|undefined}>}
 */
const watched = new Map();

/**
 * Tells core's notice script to add a dismiss button. WordPress 6.6 listens
 * for the first event, 6.7 and later for both.
 */
function makeDismissible() {
	window
		.jQuery( window.document )
		.trigger( 'wp-updates-notice-added' )
		.trigger( 'wp-notice-added' );
}

/**
 * What handles redirects for the site, used when the page's own answer could
 * not be loaded.
 *
 * @return {{serving: boolean, pairs_served: boolean, provider: Object|null}} Redirects.
 */
function siteRedirects() {
	return {
		serving: config.serving,
		pairs_served: config.serving,
		provider: config.provider,
	};
}

/**
 * Prints and speaks a notice under the heading. The change it is about
 * replaced the one any earlier URLs notice offers, so those lose their links.
 *
 * @param {{status: string, sentence: string, view: string, setup: boolean}} notice    What to say, the button's label (none when empty) and whether to offer setting up redirects.
 * @param {Object|null}                                                      source    The change the button opens, or null.
 * @param {Object}                                                           redirects The page's redirects, for the modal.
 */
function printNotice( notice, source, redirects ) {
	const end = window.document.querySelector( '.wp-header-end' );
	if ( ! end ) {
		return;
	}
	const box = window.document.createElement( 'div' );
	box.className = `notice notice-${ notice.status } is-dismissible hierarchy-guard-urls`;
	const text = window.document.createElement( 'p' );
	text.textContent = notice.sentence + ' ';
	if ( source && notice.view ) {
		const button = window.document.createElement( 'button' );
		button.type = 'button';
		button.className = 'button-link hierarchy-guard-view-redirects';
		button.setAttribute( 'aria-haspopup', 'dialog' );
		button.textContent = notice.view;
		button.addEventListener( 'click', () => {
			open( ( close ) => (
				<RedirectsModal
					source={ source }
					redirects={ redirects }
					onClose={ close }
				/>
			) );
		} );
		text.appendChild( button );
		if ( notice.setup ) {
			// Set apart the way the server notice sets its link apart.
			const divider = window.document.createElement( 'span' );
			divider.setAttribute( 'aria-hidden', 'true' );
			divider.textContent = ' ·';
			const setup = window.document.createElement( 'a' );
			setup.href = config.settingsUrl;
			setup.textContent = __( 'Set up redirects', 'hierarchy-guard' );
			text.appendChild( divider );
			text.appendChild( window.document.createTextNode( ' ' ) );
			text.appendChild( setup );
		}
	}
	box.appendChild( text );
	retireUrlsNotices( window.document );
	end.parentNode.insertBefore( box, end.nextSibling );
	makeDismissible();
	speak( notice.sentence, 'polite' );
}

/**
 * Says the save changed URLs but their redirects could not be loaded.
 *
 * @param {Object|null} source    The change, or null when it is not known.
 * @param {Object}      redirects The page's redirects, or the site's when the page's failed to load.
 */
function printFailure( source, redirects = siteRedirects() ) {
	printNotice(
		{
			status: 'warning',
			sentence: __(
				'This save changed URLs, but their redirects couldn’t be loaded.',
				'hierarchy-guard'
			),
			view: __( 'View redirects', 'hierarchy-guard' ),
			// Never offered while redirects are on for the site.
			setup:
				! config.serving &&
				! redirects.provider &&
				!! config.settingsUrl,
		},
		source,
		redirects
	);
}

/**
 * Prints and speaks a notice under the heading with the redirects of the
 * change a save recorded.
 *
 * @param {string} change    The change.
 * @param {Object} redirects The page's redirects, from its hierarchy.
 */
function announce( change, redirects ) {
	const source = { change };
	fetchPairs( source, 1, 1 )
		.then( ( pairs ) => {
			const notice = changeNotice( pairs, redirects );
			if ( notice ) {
				printNotice( notice, source, redirects );
			}
		} )
		.catch( () => printFailure( source, redirects ) );
}

/**
 * Adds the warning to a row's Quick Edit form.
 *
 * @param {number} postId Post being edited.
 */
function attach( postId ) {
	const cell = window.document.querySelector(
		`#post-${ postId } .hierarchy-guard-cell`
	);
	const form = window.document.getElementById( `edit-${ postId }` );
	if (
		! cell ||
		! form ||
		! Number( cell.dataset.children || 0 ) ||
		'publish' !== cell.dataset.status
	) {
		return;
	}
	const slug = form.querySelector( 'input[name="post_name"]' );
	const bar = form.querySelector( '.inline-edit-save' );
	if ( ! slug || ! bar ) {
		return;
	}
	const parent = form.querySelector( 'select[name="post_parent"]' );
	const before = { slug: slug.value, parent: parent ? parent.value : '' };
	watched.set( postId, { ...before, latest: undefined } );

	const box = window.document.createElement( 'div' );
	box.id = `hierarchy-guard-quick-edit-${ postId }`;
	box.className =
		'notice notice-warning notice-alt inline hierarchy-guard-quick-edit';
	box.hidden = true;
	box.appendChild( window.document.createElement( 'p' ) );
	bar.insertBefore( box, bar.firstChild );

	let descendants = null;
	let requested = false;
	let spoken = false;
	const fields = [ slug, parent ].filter( Boolean );

	const update = () => {
		const edited =
			slug.value !== before.slug ||
			( parent ? parent.value : '' ) !== before.parent;
		if ( edited && ! requested ) {
			// Ask for the count the first time it is needed, not on every open.
			requested = true;
			fetchHierarchy( postId )
				.then( ( data ) => {
					descendants = data.descendants;
					const row = watched.get( postId );
					if ( row ) {
						row.latest = data.latest_change || null;
					}
					update();
				} )
				.catch( () => {
					requested = false;
				} );
		}
		const show = edited && !! descendants && descendants.count > 0;
		box.hidden = ! show;
		fields.forEach( ( field ) =>
			show
				? field.setAttribute( 'aria-describedby', box.id )
				: field.removeAttribute( 'aria-describedby' )
		);
		if ( show ) {
			box.firstChild.textContent = quickEditWarning(
				descendants.count,
				descendants.capped
			);
			if ( ! spoken ) {
				spoken = true;
				speak( box.firstChild.textContent, 'polite' );
			}
		}
	};
	slug.addEventListener( 'input', update );
	if ( parent ) {
		parent.addEventListener( 'change', update );
	}
}

/**
 * Hooks into core's Quick Edit.
 */
export default function bindQuickEdit() {
	const inline = window.inlineEditPost;
	const $ = window.jQuery;
	if ( ! inline || ! inline.edit || ! $ ) {
		return;
	}

	const edit = inline.edit;
	inline.edit = function ( id, ...rest ) {
		const result = edit.call( this, id, ...rest );
		const postId = Number(
			'object' === typeof id ? inline.getId( id ) : id
		);
		if ( postId ) {
			attach( postId );
		}
		return result;
	};

	$( window.document ).on( 'ajaxSuccess', ( event, xhr, settings ) => {
		if ( 'string' !== typeof settings.data ) {
			return;
		}
		const params = new window.URLSearchParams( settings.data );
		const postId = Number( params.get( 'post_ID' ) );
		const before = watched.get( postId );
		if ( 'inline-save' !== params.get( 'action' ) || ! before ) {
			return;
		}
		watched.delete( postId );
		if (
			params.get( 'post_name' ) === before.slug &&
			( params.get( 'post_parent' ) || '' ) === before.parent
		) {
			return;
		}
		fetchHierarchy( postId )
			.then( ( data ) => {
				const change = newChange( before.latest, data.latest_change );
				if ( change ) {
					announce( change, data.redirects || siteRedirects() );
				}
			} )
			.catch( () => printFailure( null ) );
	} );
}
