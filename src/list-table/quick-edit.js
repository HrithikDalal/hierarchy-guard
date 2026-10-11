/**
 * The Quick Edit warning when the slug or parent of a published page with
 * subpages changes.
 */
import { speak } from '@wordpress/a11y';
import { fetchHierarchy } from '../common/api';
import { quickEditWarning } from '../common/copy';

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
	if ( ! inline || ! inline.edit ) {
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
}
