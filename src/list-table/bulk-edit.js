/**
 * The Bulk Edit warning: a new parent for selected pages that have subpages
 * changes the URLs of those subpages too.
 */
import { speak } from '@wordpress/a11y';
import config from '../common/config';
import { bulkEditWarning } from '../common/copy';

const BOX = 'hierarchy-guard-bulk-edit';

/**
 * How many selected rows are published pages with subpages that the chosen
 * parent moves, from their Hierarchy cells and core's inline data, which
 * holds each row's current parent.
 *
 * @param {Document} doc    The list screen.
 * @param {string}   chosen The parent chosen in the form.
 * @return {number} Count.
 */
function parentsSelected( doc, chosen ) {
	let count = 0;
	doc.querySelectorAll( '#the-list input[name="post[]"]:checked' ).forEach(
		( box ) => {
			const cell = doc.querySelector(
				`#post-${ box.value } .hierarchy-guard-cell`
			);
			const current = doc.querySelector(
				`#inline_${ box.value } .post_parent`
			);
			if (
				cell &&
				Number( cell.dataset.children || 0 ) > 0 &&
				'publish' === cell.dataset.status &&
				! ( current && current.textContent.trim() === chosen )
			) {
				count++;
			}
		}
	);
	return count;
}

/**
 * Shows, updates or hides the warning above the Bulk Edit form's Update
 * button, as the Parent field and the selection stand.
 *
 * @param {Document} doc The list screen.
 */
export function updateBulkWarning( doc = window.document ) {
	const form = doc.getElementById( 'bulk-edit' );
	const parent = form && form.querySelector( 'select[name="post_parent"]' );
	const bar = form && form.querySelector( '.inline-edit-save' );
	if ( ! parent || ! bar ) {
		return;
	}
	const pages =
		'-1' !== parent.value ? parentsSelected( doc, parent.value ) : 0;
	let box = doc.getElementById( BOX );
	if ( ! pages ) {
		if ( box ) {
			box.hidden = true;
			parent.removeAttribute( 'aria-describedby' );
		}
		return;
	}
	if ( ! box ) {
		box = doc.createElement( 'div' );
		box.id = BOX;
		box.className = 'notice notice-warning notice-alt inline';
		box.hidden = true;
		box.appendChild( doc.createElement( 'p' ) );
		bar.insertBefore( box, bar.firstChild );
	}
	const text = bulkEditWarning( pages, config.serving );
	const changed = box.hidden || box.firstChild.textContent !== text;
	box.firstChild.textContent = text;
	box.hidden = false;
	parent.setAttribute( 'aria-describedby', BOX );
	if ( changed ) {
		speak( text, 'polite' );
	}
}

/**
 * Watches the Bulk Edit form's Parent field, and the selection it edits.
 *
 * @param {Document} doc The list screen.
 */
export default function bindBulkEdit( doc = window.document ) {
	doc.addEventListener( 'change', ( event ) => {
		if (
			event.target.matches(
				'#bulk-edit select[name="post_parent"], #the-list input[name="post[]"], .check-column input[id^="cb-select-all-"]'
			)
		) {
			updateBulkWarning( doc );
		}
	} );
	doc.addEventListener( 'click', ( event ) => {
		// Core's own handler on the button takes the page out of the
		// selection and removes its line first, so the button is detached
		// by now and only its own class still matches.
		if ( event.target.closest( '.ntdelbutton' ) ) {
			window.setTimeout( () => updateBulkWarning( doc ), 0 );
		}
	} );
}
