/**
 * The Tree view on core's list table: tree grid roles and arrow keys on
 * core's own rows, the toggles as core button-link buttons, subpages 50 at
 * a time with "Show more", and "Collapse all", which needs no request.
 */
import { speak } from '@wordpress/a11y';
import { __, isRTL } from '@wordpress/i18n';
import {
	treeCappedSentence,
	treeShownLine,
	treeToggleLabel,
} from '../common/copy';
import { PER_PAGE, childRow, statusRow } from './rows';

const OPEN = 'dashicons-arrow-down-alt2';
const RIGHT = 'dashicons-arrow-right-alt2';
const LEFT = 'dashicons-arrow-left-alt2';

/**
 * The closed toggle's arrow, pointing along the line toward the title.
 *
 * @return {string} Dashicon class.
 */
const closed = () => ( isRTL() ? LEFT : RIGHT );

/**
 * A row's level, from 1.
 *
 * @param {HTMLTableRowElement} row Row.
 * @return {number} Level.
 */
export function levelOf( row ) {
	return Number( row.getAttribute( 'aria-level' ) || 1 );
}

/**
 * The page a row shows, or 0 for a status row.
 *
 * @param {HTMLTableRowElement} row Row.
 * @return {number} Post ID.
 */
function idOf( row ) {
	return row.id.startsWith( 'post-' ) ? Number( row.id.slice( 5 ) ) : 0;
}

/**
 * The rows under a row, down to the next row at its level or above.
 *
 * @param {HTMLTableRowElement} row Row.
 * @return {HTMLTableRowElement[]} Rows.
 */
export function descendantsOf( row ) {
	const level = levelOf( row );
	const out = [];
	for (
		let next = row.nextElementSibling;
		next && levelOf( next ) > level;
		next = next.nextElementSibling
	) {
		out.push( next );
	}
	return out;
}

/**
 * The row of a row's parent page, or null at the top level.
 *
 * @param {HTMLTableRowElement} row Row.
 * @return {HTMLTableRowElement|null} Row.
 */
export function parentOf( row ) {
	const level = levelOf( row );
	for (
		let prev = row.previousElementSibling;
		prev;
		prev = prev.previousElementSibling
	) {
		if ( levelOf( prev ) < level ) {
			return prev;
		}
	}
	return null;
}

/**
 * A row's title, as its title link reads.
 *
 * @param {HTMLTableRowElement} row Row.
 * @return {string} Title.
 */
function titleOf( row ) {
	const title = row.querySelector( '.row-title' );
	return title ? title.textContent.trim() : '';
}

/**
 * Sets up the Tree view on a list table.
 *
 * @param {Object}                                        args               Arguments.
 * @param {HTMLTableSectionElement}                       args.body          Core's #the-list.
 * @param {DOMStringMap}                                  args.data          The view's data: type and place.
 * @param {number}                                        args.cap           The count cap.
 * @param {(id: number, page: number) => Promise<Object>} args.fetchChildren Loads one page of a page's subpages.
 * @return {Object} The tree's actions, for the toggles, keys and tests.
 */
export function createTree( { body, data, cap, fetchChildren } ) {
	const doc = body.ownerDocument;
	const loading = new Set();
	let template = null;

	// Children rather than rows and cells, which not every DOM keeps live.
	const rows = () =>
		Array.from( body.children ).filter( ( node ) => 'TR' === node.tagName );
	const isTreeRow = ( row ) => idOf( row ) > 0;

	/**
	 * Makes a row the one Tab reaches.
	 *
	 * @param {HTMLTableRowElement} row Row.
	 */
	const setTabStop = ( row ) =>
		rows().forEach( ( other ) =>
			other.setAttribute( 'tabindex', other === row ? '0' : '-1' )
		);

	/**
	 * Moves the keyboard to a row, which becomes the one Tab reaches.
	 *
	 * @param {HTMLTableRowElement|null} row Row.
	 */
	const focus = ( row ) => {
		if ( ! row ) {
			return;
		}
		setTabStop( row );
		row.focus();
	};

	const setOpen = ( row, open ) => {
		row.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		const button = row.querySelector( 'button.hierarchy-guard-toggle' );
		if ( button ) {
			button.setAttribute(
				'aria-label',
				treeToggleLabel( open, button.dataset.title )
			);
			const icon = button.querySelector( '.dashicons' );
			icon.classList.remove( OPEN, RIGHT, LEFT );
			icon.classList.add( open ? OPEN : closed() );
		}
	};

	/**
	 * Puts a toggle button before a row's title.
	 *
	 * @param {HTMLTableRowElement} row   Row.
	 * @param {string}              title Page title.
	 * @param {boolean}             open  Whether the row is open.
	 */
	const addToggle = ( row, title, open ) => {
		const cell = row.querySelector( '.column-title' );
		if ( ! cell ) {
			return;
		}
		const button = doc.createElement( 'button' );
		button.type = 'button';
		button.className = 'button-link hierarchy-guard-toggle';
		button.dataset.title = title;
		const icon = doc.createElement( 'span' );
		icon.className = 'dashicons';
		icon.setAttribute( 'aria-hidden', 'true' );
		button.appendChild( icon );
		button.addEventListener( 'click', () => toggle( row ) );
		// Inside core's title line, a block, so the toggle sits on it.
		const heading = cell.querySelector( 'strong' ) || cell;
		heading.insertBefore( button, heading.firstChild );
		setOpen( row, open );
	};

	/**
	 * The direct subpages of a row on the screen.
	 *
	 * @param {HTMLTableRowElement} row Row.
	 * @return {number} Count.
	 */
	const shownUnder = ( row ) =>
		descendantsOf( row ).filter(
			( other ) =>
				isTreeRow( other ) && levelOf( other ) === levelOf( row ) + 1
		).length;

	/**
	 * The row after which a row's next subpages go.
	 *
	 * @param {HTMLTableRowElement} row Row.
	 * @return {HTMLTableRowElement} Row.
	 */
	const lastOf = ( row ) => {
		const under = descendantsOf( row );
		return under.length ? under[ under.length - 1 ] : row;
	};

	/**
	 * Adds a row under an opened row that is not a page.
	 *
	 * @param {HTMLTableRowElement} row The opened row.
	 * @return {{row: HTMLTableRowElement, cell: HTMLTableCellElement}} The status row.
	 */
	const addStatus = ( row ) => {
		const status = statusRow( template, idOf( row ), levelOf( row ) );
		status.row.setAttribute( 'aria-level', String( levelOf( row ) + 1 ) );
		status.row.setAttribute( 'tabindex', '-1' );
		lastOf( row ).after( status.row );
		return status;
	};

	/**
	 * "Show more" under an opened row, or the cap's sentence past it. While
	 * the next page loads the row stays, busy, so the keyboard stays on it.
	 *
	 * @param {HTMLTableRowElement} row     The opened row.
	 * @param {number}              next    The next page of its subpages.
	 * @param {number}              total   Its subpages, at most the cap.
	 * @param {boolean}             capped  Whether more than the cap exist.
	 * @param {boolean}             hasMore Whether a next page exists.
	 */
	const addMore = ( row, next, total, capped, hasMore ) => {
		if ( hasMore ) {
			const status = addStatus( row );
			status.row.classList.add( 'hierarchy-guard-more' );
			const button = doc.createElement( 'button' );
			button.type = 'button';
			button.className = 'button-link';
			button.textContent = __( 'Show more', 'hierarchy-guard' );
			button.addEventListener( 'click', () => {
				if ( loading.has( idOf( row ) ) ) {
					return;
				}
				button.setAttribute( 'aria-disabled', 'true' );
				load( row, next, true, status );
			} );
			status.cell.appendChild( button );
			const line = doc.createElement( 'span' );
			line.textContent =
				' · ' + treeShownLine( shownUnder( row ), total, capped );
			status.cell.appendChild( line );
		} else if ( capped ) {
			const { row: end, cell } = addStatus( row );
			end.classList.add( 'hierarchy-guard-capped' );
			cell.textContent = treeCappedSentence( cap );
		}
	};

	/**
	 * Says a load failed, in its status row, with a button to try again
	 * without closing the row, and gives the keyboard back to the toggle.
	 *
	 * @param {HTMLTableRowElement}                                    row    The opened row.
	 * @param {number}                                                 page   The page that failed.
	 * @param {{row: HTMLTableRowElement, cell: HTMLTableCellElement}} status Its status row.
	 */
	const failed = ( row, page, status ) => {
		const here =
			doc.activeElement === doc.body ||
			status.row.contains( doc.activeElement ) ||
			row.contains( doc.activeElement );
		status.row.removeAttribute( 'aria-busy' );
		status.row.classList.remove( 'hierarchy-guard-more' );
		status.row.classList.add( 'hierarchy-guard-failed' );
		status.cell.textContent = __(
			'The subpages couldn’t be loaded.',
			'hierarchy-guard'
		);
		status.cell.appendChild( doc.createTextNode( ' ' ) );
		const retry = doc.createElement( 'button' );
		retry.type = 'button';
		retry.className = 'button-link';
		retry.textContent = __( 'Try again', 'hierarchy-guard' );
		retry.addEventListener( 'click', () => {
			if ( ! loading.has( idOf( row ) ) ) {
				retry.setAttribute( 'aria-disabled', 'true' );
				load( row, page, true, status );
			}
		} );
		status.cell.appendChild( retry );
		speak(
			__( 'The subpages couldn’t be loaded.', 'hierarchy-guard' ),
			'assertive'
		);
		if ( here ) {
			setTabStop( row );
			(
				row.querySelector( 'button.hierarchy-guard-toggle' ) || row
			).focus();
		}
	};

	/**
	 * Gives a row its place in the tree grid.
	 *
	 * @param {HTMLTableRowElement} row Row.
	 */
	const place = ( row ) => {
		const cell = row.querySelector( '.hierarchy-guard-cell' );
		if ( ! cell ) {
			return;
		}
		const facts = cell.dataset;
		row.setAttribute(
			'aria-level',
			String( Number( facts.level || 0 ) + 1 )
		);
		if ( facts.setsize && facts.position ) {
			row.setAttribute( 'aria-setsize', facts.setsize );
			row.setAttribute( 'aria-posinset', facts.position );
		}
		row.setAttribute( 'tabindex', '-1' );
	};

	/**
	 * Loads one page of a row's subpages under it.
	 *
	 * @param {HTMLTableRowElement}                                         row       The opened row.
	 * @param {number}                                                      page      Page of its subpages, from 1.
	 * @param {boolean}                                                     moveFocus Whether the first new row takes the keyboard.
	 * @param {{row: HTMLTableRowElement, cell: HTMLTableCellElement}|null} reuse     The Show more or failure row to keep while it loads.
	 * @return {Promise<void>} Done.
	 */
	const load = async ( row, page, moveFocus = false, reuse = null ) => {
		const id = idOf( row );
		if ( loading.has( id ) ) {
			return;
		}
		loading.add( id );
		const status = reuse || addStatus( row );
		status.row.setAttribute( 'aria-busy', 'true' );
		if ( ! reuse ) {
			status.cell.textContent = __(
				'Loading subpages…',
				'hierarchy-guard'
			);
		}
		let result;
		try {
			result = await fetchChildren( id, page );
		} catch {
			if ( status.row.isConnected ) {
				failed( row, page, status );
			}
			return;
		} finally {
			loading.delete( id );
		}
		status.row.remove();
		if ( 'true' !== row.getAttribute( 'aria-expanded' ) ) {
			// Closed while it loaded.
			return;
		}

		let anchor = lastOf( row );
		let first = null;
		( result.children || [] ).forEach( ( child, index ) => {
			const node = childRow( template, child, {
				level: levelOf( row ),
				parent: id,
				parentTitle: titleOf( row ),
				page,
				position: result.children_capped
					? null
					: ( page - 1 ) * PER_PAGE + index + 1,
				setsize: result.children_capped ? null : result.children_total,
				place: data.place,
				type: data.type,
			} );
			place( node );
			if ( child.children_count > 0 && child.edit_link ) {
				addToggle( node, child.title, false );
			}
			anchor.after( node );
			anchor = node;
			first = first || node;
		} );
		addMore(
			row,
			page + 1,
			result.children_total,
			result.children_capped,
			result.has_more
		);
		if ( result.has_more || result.children_capped ) {
			// Said only when some are held back; a row opened in full needs no count.
			speak(
				treeShownLine(
					shownUnder( row ),
					result.children_total,
					result.children_capped
				),
				'polite'
			);
		}
		if ( moveFocus ) {
			focus( first || row );
		}
	};

	const expand = ( row ) => {
		if ( 'false' !== row.getAttribute( 'aria-expanded' ) ) {
			return Promise.resolve();
		}
		setOpen( row, true );
		return load( row, 1 );
	};

	const collapse = ( row ) => {
		if ( 'true' !== row.getAttribute( 'aria-expanded' ) ) {
			return;
		}
		const under = descendantsOf( row );
		const lost = under.some( ( other ) =>
			other.contains( doc.activeElement )
		);
		const held = under.some(
			( other ) => '0' === other.getAttribute( 'tabindex' )
		);
		under.forEach( ( other ) => other.remove() );
		setOpen( row, false );
		if ( lost ) {
			focus( row );
		} else if ( held ) {
			// The row that Tab reached is gone: the closed row takes it.
			setTabStop( row );
		}
	};

	const toggle = ( row ) =>
		'true' === row.getAttribute( 'aria-expanded' )
			? collapse( row )
			: expand( row );

	const collapseAll = () => {
		rows()
			.filter( ( row ) => 'true' === row.getAttribute( 'aria-expanded' ) )
			.forEach( ( row ) => {
				if ( row.isConnected ) {
					collapse( row );
				}
			} );
		if (
			! rows().some( ( row ) => '0' === row.getAttribute( 'tabindex' ) )
		) {
			setTabStop( rows()[ 0 ] );
		}
	};

	// The rows core printed, with the levels the server opened.
	rows().forEach( ( row ) => {
		place( row );
		template = template || ( isTreeRow( row ) ? row : null );
	} );
	if ( ! template ) {
		return null;
	}
	body.closest( 'table' ).setAttribute( 'role', 'treegrid' );

	rows().forEach( ( row ) => {
		const cell = row.querySelector( '.hierarchy-guard-cell' );
		const toggleLink =
			cell && cell.querySelector( 'a.hierarchy-guard-toggle' );
		if ( toggleLink ) {
			const separator = toggleLink.nextElementSibling;
			if ( separator && separator.matches( '[aria-hidden="true"]' ) ) {
				separator.remove();
			}
			toggleLink.remove();
			addToggle( row, titleOf( row ), !! cell.dataset.open );
		}
	} );
	// "Show more" and the cap's sentence, printed in an opened row's cell, become rows under it.
	rows()
		.filter( ( row ) => 'true' === row.getAttribute( 'aria-expanded' ) )
		.forEach( ( row ) => {
			const cell = row.querySelector( '.hierarchy-guard-cell' );
			const more = cell.querySelector(
				'a.hierarchy-guard-more, .hierarchy-guard-capped'
			);
			if ( ! more ) {
				return;
			}
			const separator = more.previousElementSibling;
			if ( separator && separator.matches( '[aria-hidden="true"]' ) ) {
				separator.remove();
			}
			more.remove();
			const total = Number( cell.dataset.children || 0 );
			addMore(
				row,
				Number( more.dataset.page || 0 ),
				Math.min( total, cap ),
				total > cap,
				more.matches( 'a' )
			);
		} );

	const first = rows()[ 0 ];
	if ( first ) {
		first.setAttribute( 'tabindex', '0' );
	}

	body.addEventListener( 'focusin', ( event ) => {
		const row = event.target.closest( 'tr' );
		if (
			row &&
			row.parentNode === body &&
			'0' !== row.getAttribute( 'tabindex' )
		) {
			setTabStop( row );
		}
	} );

	body.addEventListener( 'keydown', ( event ) => {
		const row = event.target.closest( 'tr' );
		if (
			! row ||
			row.parentNode !== body ||
			( event.target !== row &&
				! event.target.classList.contains( 'hierarchy-guard-toggle' ) &&
				! event.target.closest( '.hierarchy-guard-status' ) )
		) {
			return;
		}
		const expanded = row.getAttribute( 'aria-expanded' );
		switch ( event.key ) {
			case 'ArrowDown':
				focus( row.nextElementSibling );
				break;
			case 'ArrowUp':
				focus( row.previousElementSibling );
				break;
			case 'Home':
				focus( rows()[ 0 ] );
				break;
			case 'End':
				focus( rows()[ rows().length - 1 ] );
				break;
			case 'ArrowRight':
				if ( 'false' === expanded ) {
					expand( row );
				} else if (
					'true' === expanded &&
					row.nextElementSibling &&
					levelOf( row.nextElementSibling ) > levelOf( row )
				) {
					focus( row.nextElementSibling );
				}
				break;
			case 'ArrowLeft':
				if ( 'true' === expanded ) {
					collapse( row );
				} else {
					focus( parentOf( row ) );
				}
				break;
			default:
				return;
		}
		event.preventDefault();
	} );

	return { expand, collapse, toggle, collapseAll, load };
}
