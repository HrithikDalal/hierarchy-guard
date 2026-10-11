/**
 * The Quick Edit warning when the slug or parent of a published page with
 * subpages changes, and the redirects after such a save.
 */
import { speak } from '@wordpress/a11y';
import { __ } from '@wordpress/i18n';
import { fetchHierarchy, fetchPairs } from '../common/api';
import config from '../common/config';
import {
	changedSentence,
	changedStatus,
	quickEditWarning,
	viewRedirectsLabel,
} from '../common/copy';
import { open } from '../common/mount';
import RedirectsModal from '../common/redirects-modal';

/**
 * Rows whose Quick Edit is open, with what they had when it opened.
 *
 * @type {Map<number, {slug: string, parent: string, newest: string|null|undefined}>}
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
 * @return {{serving: boolean, provider: Object|null}} Redirects.
 */
function siteRedirects() {
	return { serving: config.serving, provider: config.provider };
}

/**
 * Prints and speaks a notice under the heading.
 *
 * @param {string}      status    Notice status: info, warning and so on.
 * @param {string}      sentence  What to say.
 * @param {Object|null} source    Redirects source for the button, or null for none.
 * @param {string}      label     The button's label.
 * @param {Object}      redirects Whether the page is redirected to, and the redirect plugin.
 */
function printNotice( status, sentence, source, label, redirects ) {
	const end = window.document.querySelector( '.wp-header-end' );
	if ( ! end ) {
		return;
	}
	const notice = window.document.createElement( 'div' );
	notice.className = `notice notice-${ status } is-dismissible hierarchy-guard-urls`;
	const text = window.document.createElement( 'p' );
	text.textContent = sentence + ' ';
	if ( source ) {
		const button = window.document.createElement( 'button' );
		button.type = 'button';
		button.className = 'button-link';
		button.setAttribute( 'aria-haspopup', 'dialog' );
		button.textContent = label;
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
		// Never offered while redirects are on for the site, even when this page isn't served.
		if ( ! config.serving && ! redirects.provider && config.settingsUrl ) {
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
	notice.appendChild( text );
	end.parentNode.insertBefore( notice, end.nextSibling );
	makeDismissible();
	speak( sentence, 'polite' );
}

/**
 * Says the save changed URLs but their redirects could not be loaded.
 *
 * @param {Object|null} source    Redirects source for the button, or null.
 * @param {Object}      redirects The page's redirects, or the site's when the page's failed to load.
 */
function printFailure( source, redirects = siteRedirects() ) {
	printNotice(
		'warning',
		__(
			'This save changed URLs, but their redirects couldn’t be loaded.',
			'hierarchy-guard'
		),
		source,
		__( 'View redirects', 'hierarchy-guard' ),
		redirects
	);
}

/**
 * Prints and speaks a notice under the heading saying the save changed URLs.
 *
 * @param {number} postId    Saved post.
 * @param {string} from      The old path the save recorded.
 * @param {Object} redirects The page's redirects, from its hierarchy.
 */
function announce( postId, from, redirects ) {
	const source = { post: postId, from };
	const { serving, provider } = redirects;
	fetchPairs( source, 1, 1 )
		.then( ( pairs ) => {
			if ( ! pairs.total ) {
				return;
			}
			printNotice(
				changedStatus( serving, provider ),
				changedSentence( pairs.total, pairs.capped, serving, provider ),
				serving ? null : source,
				viewRedirectsLabel( pairs.total, pairs.capped ),
				redirects
			);
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
	watched.set( postId, { ...before, newest: undefined } );

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
						row.newest = data.old_paths[ 0 ]
							? data.old_paths[ 0 ].path
							: null;
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
				const latest = data.old_paths[ 0 ]
					? data.old_paths[ 0 ].path
					: null;
				if ( latest && latest !== before.newest ) {
					announce(
						postId,
						latest,
						data.redirects || siteRedirects()
					);
				}
			} )
			.catch( () => printFailure( null ) );
	} );
}
