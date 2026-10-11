/**
 * The Quick Edit warning when the slug or parent of a published page with
 * subpages changes, and the redirects after such a save.
 */
import { speak } from '@wordpress/a11y';
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
 * Prints and speaks a notice under the heading saying the save changed URLs.
 *
 * @param {number} postId Saved post.
 * @param {string} from   The old path the save recorded.
 */
function announce( postId, from ) {
	fetchPairs( { post: postId, from }, 1, 1 ).then( ( pairs ) => {
		const end = window.document.querySelector( '.wp-header-end' );
		if ( ! pairs.total || ! end ) {
			return;
		}
		const sentence = changedSentence(
			pairs.total,
			pairs.capped,
			config.serving,
			config.provider
		);
		const notice = window.document.createElement( 'div' );
		notice.className = `notice notice-${ changedStatus(
			config.serving,
			config.provider
		) } is-dismissible hierarchy-guard-urls`;
		const text = window.document.createElement( 'p' );
		text.textContent = sentence + ' ';
		if ( ! config.serving ) {
			const button = window.document.createElement( 'button' );
			button.type = 'button';
			button.className = 'button-link';
			button.setAttribute( 'aria-haspopup', 'dialog' );
			button.textContent = viewRedirectsLabel(
				pairs.total,
				pairs.capped
			);
			button.addEventListener( 'click', () => {
				const source = { post: postId, from };
				open( ( close ) => (
					<RedirectsModal source={ source } onClose={ close } />
				) );
			} );
			text.appendChild( button );
		}
		notice.appendChild( text );
		end.parentNode.insertBefore( notice, end.nextSibling );
		makeDismissible();
		speak( sentence, 'polite' );
	} );
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
			fetchHierarchy( postId ).then( ( data ) => {
				descendants = data.descendants;
				const row = watched.get( postId );
				if ( row ) {
					row.newest = data.old_paths[ 0 ]
						? data.old_paths[ 0 ].path
						: null;
				}
				update();
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
		fetchHierarchy( postId ).then( ( data ) => {
			const latest = data.old_paths[ 0 ]
				? data.old_paths[ 0 ].path
				: null;
			if ( latest && latest !== before.newest ) {
				announce( postId, latest );
			}
		} );
	} );
}
