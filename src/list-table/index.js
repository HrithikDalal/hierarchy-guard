/**
 * The list screen: the trash dialog, the Quick Edit warning, the redirects
 * modal behind the URLs notice, the filter subtitle and the nudge.
 */
import domReady from '@wordpress/dom-ready';
import { dismissNudge } from '../common/api';
import { open } from '../common/mount';
import RedirectsModal from '../common/redirects-modal';
import bindQuickEdit from './quick-edit';
import TrashDialog, { MAX_PREVIEW } from './trash-dialog';
import { rowTitle } from './trash-choice';
import '../common/modal.scss';
import './list-table.scss';

const POLICY_PARAM = 'hierarchy_guard_policy';

/**
 * A row's live child count, from the Hierarchy column's cell.
 *
 * @param {number} id Post ID.
 * @return {number} Count.
 */
function childCount( id ) {
	const cell = window.document.querySelector(
		`#post-${ id } .hierarchy-guard-cell`
	);
	return cell ? Number( cell.dataset.children || 0 ) : 0;
}

/**
 * Moves the "Subpages of …" sentence beside the heading, the way core shows
 * search results, and the URLs notice under core's trash notice.
 */
function arrangeHeader() {
	const end = window.document.querySelector( '.wp-header-end' );
	const under = window.document.querySelector( '.hierarchy-guard-under' );
	if ( under && end ) {
		under.classList.add( 'subtitle' );
		end.parentNode.insertBefore( under, end );
	}
	const urls = window.document.querySelector( '.hierarchy-guard-urls' );
	const message = window.document.getElementById( 'message' );
	if ( urls && message ) {
		message.after( urls );
	}
}

/**
 * Asks before the row action trashes a page with subpages.
 */
function bindRowTrash() {
	window.document.addEventListener( 'click', ( event ) => {
		const link = event.target.closest( '#the-list .row-actions .trash a' );
		if ( ! link || event.defaultPrevented ) {
			return;
		}
		const row = link.closest( 'tr' );
		const id = row ? Number( row.id.replace( 'post-', '' ) ) : 0;
		const children = id ? childCount( id ) : 0;
		if ( ! children ) {
			return;
		}
		event.preventDefault();
		open( ( close ) => (
			<TrashDialog
				ids={ [ id ] }
				selected={ 1 }
				withChildren={ 1 }
				title={ rowTitle( window.document, id ) }
				children={ children }
				onCancel={ close }
				onConfirm={ ( policy ) => {
					const url = new window.URL( link.href );
					if ( policy ) {
						url.searchParams.set( POLICY_PARAM, policy );
					}
					window.location.href = url.toString();
				} }
			/>
		) );
	} );
}

/**
 * Asks before the bulk action trashes pages with subpages.
 */
function bindBulkTrash() {
	const form = window.document.getElementById( 'posts-filter' );
	if ( ! form ) {
		return;
	}
	form.addEventListener( 'submit', ( event ) => {
		// Only the Apply buttons run a bulk action; Filter and Search submit the same form.
		const submitter = event.submitter;
		if (
			! submitter ||
			! [ 'doaction', 'doaction2' ].includes( submitter.id )
		) {
			return;
		}
		const action = form.querySelector( 'select[name="action"]' );
		if ( ! action || 'trash' !== action.value ) {
			return;
		}
		const selected = Array.from(
			form.querySelectorAll( 'input[name="post[]"]:checked' )
		).map( ( box ) => Number( box.value ) );
		const parents = selected.filter( ( id ) => childCount( id ) > 0 );
		if ( ! parents.length ) {
			return;
		}
		event.preventDefault();
		open( ( close ) => (
			<TrashDialog
				ids={ parents.slice( 0, MAX_PREVIEW ) }
				selected={ selected.length }
				withChildren={ parents.length }
				title={
					1 === selected.length
						? rowTitle( window.document, selected[ 0 ] )
						: ''
				}
				children={
					1 === selected.length ? childCount( selected[ 0 ] ) : 0
				}
				onCancel={ close }
				onConfirm={ ( policy ) => {
					if ( policy ) {
						const input = window.document.createElement( 'input' );
						input.type = 'hidden';
						input.name = POLICY_PARAM;
						input.value = policy;
						form.appendChild( input );
					}
					form.submit();
				} }
			/>
		) );
	} );
}

/**
 * Turns the URLs notice's download link into the button that opens the modal.
 */
function bindViewRedirects() {
	window.document
		.querySelectorAll( 'a.hierarchy-guard-view-redirects' )
		.forEach( ( link ) => {
			const button = window.document.createElement( 'button' );
			button.type = 'button';
			button.className = 'button-link hierarchy-guard-view-redirects';
			button.setAttribute( 'aria-haspopup', 'dialog' );
			button.textContent = link.dataset.label || link.textContent;
			const source = { change: link.dataset.change };
			button.addEventListener( 'click', () =>
				open( ( close ) => (
					<RedirectsModal source={ source } onClose={ close } />
				) )
			);
			link.replaceWith( button );
		} );
}

/**
 * Remembers that the nudge was dismissed.
 */
function bindNudge() {
	window.document.addEventListener( 'click', ( event ) => {
		if (
			event.target.closest( '.hierarchy-guard-nudge .notice-dismiss' )
		) {
			dismissNudge().catch( () => {} );
		}
	} );
}

domReady( () => {
	arrangeHeader();
	bindRowTrash();
	bindBulkTrash();
	bindViewRedirects();
	bindNudge();
	bindQuickEdit();
} );
