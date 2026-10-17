/**
 * The Tree view on core's list table: tree grid roles and arrow keys on
 * core's own rows, the toggles as core button-link buttons, subpages 50 at
 * a time with "Show more", and "Collapse all", which needs no request.
 */
import { speak } from '@wordpress/a11y';
import { __, isRTL } from '@wordpress/i18n';
import {
	treeCappedSentence,
	treeMoreLabel,
	treeRetryLabel,
	treeShownLine,
	treeToggleLabel,
} from '../common/copy';
import { childRow, statusRow } from './rows';

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
 * A row's level, from 1: a page row's aria-level, or for a status row, which
 * assistive technology doesn't count among the pages, its data-level plus 1,
 * since it counts from 0 as the row's class does.
 *
 * @param {HTMLTableRowElement} row Row.
 * @return {number} Level.
 */
export function levelOf( row ) {
	if ( row.hasAttribute( 'aria-level' ) ) {
		return Number( row.getAttribute( 'aria-level' ) );
	}
	return undefined !== row.dataset.level
		? Number( row.dataset.level ) + 1
		: 1;
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
 * A row's title, as its title link reads, without the excerpt WordPress 7.1
 * adds to an untitled page's: "(no title)", as the server names it.
 *
 * @param {HTMLTableRowElement} row Row.
 * @return {string} Title.
 */
function titleOf( row ) {
	const title = row.querySelector( '.row-title' );
	if ( ! title ) {
		return '';
	}
	const text = title.cloneNode( true );
	text.querySelectorAll( '.trimmed-post-excerpt' ).forEach( ( excerpt ) =>
		excerpt.remove()
	);
	return text.textContent.trim();
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
	// The load in progress under each opened row: its promise, status row, page and open.
	const pending = new Map();
	// How many times each row has closed, so a load knows the open it was for.
	const opens = new WeakMap();
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
		// At its subpages' level, from 0, but with no aria-level, so it isn't read as one of them.
		const status = statusRow( template, idOf( row ), levelOf( row ) );
		status.row.setAttribute( 'tabindex', '-1' );
		lastOf( row ).after( status.row );
		return status;
	};

	/**
	 * Says under an opened row that the user can't view its subpages: those
	 * on the page loaded, when none of them is readable. One such row is
	 * enough, however many pages bring none.
	 *
	 * @param {HTMLTableRowElement} row The opened row.
	 * @return {HTMLTableRowElement} The row that says so.
	 */
	const addHidden = ( row ) => {
		const said = descendantsOf( row ).find( ( other ) =>
			other.classList.contains( 'hierarchy-guard-hidden' )
		);
		if ( said ) {
			return said;
		}
		const status = addStatus( row );
		status.row.classList.add( 'hierarchy-guard-hidden' );
		status.cell.textContent = __(
			'You can’t view these subpages.',
			'hierarchy-guard'
		);
		return status.row;
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
			button.setAttribute(
				'aria-label',
				treeMoreLabel( titleOf( row ) )
			);
			button.addEventListener( 'click', () => {
				if ( pending.has( row ) ) {
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
	 * without closing the row. A failed first page gives the keyboard back
	 * to the toggle, just above. A failed "Show more" makes "Try again" the
	 * row Tab reaches, and moves the keyboard there only if it was still on
	 * the "Show more" row.
	 *
	 * @param {HTMLTableRowElement}                                    row    The opened row.
	 * @param {number}                                                 page   The page that failed.
	 * @param {{row: HTMLTableRowElement, cell: HTMLTableCellElement}} status Its status row.
	 */
	const failed = ( row, page, status ) => {
		// Still where the load started: on its status row, or with the keyboard nowhere.
		const atStatus =
			doc.activeElement === doc.body ||
			status.row.contains( doc.activeElement );
		const here = atStatus || row.contains( doc.activeElement );
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
		retry.setAttribute( 'aria-label', treeRetryLabel( titleOf( row ) ) );
		retry.addEventListener( 'click', () => {
			if ( ! pending.has( row ) ) {
				retry.setAttribute( 'aria-disabled', 'true' );
				load( row, page, true, status );
			}
		} );
		status.cell.appendChild( retry );
		speak(
			__( 'The subpages couldn’t be loaded.', 'hierarchy-guard' ),
			'assertive'
		);
		if ( page > 1 ) {
			// Tab reaches Try again; the keyboard moves there only if it hadn't left the Show more row.
			setTabStop( status.row );
			if ( atStatus ) {
				retry.focus();
			}
		} else if ( here ) {
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
	 * Says under an opened row that its subpages are loading.
	 *
	 * @param {HTMLTableRowElement} row The opened row.
	 * @return {{row: HTMLTableRowElement, cell: HTMLTableCellElement}} The status row.
	 */
	const addLoading = ( row ) => {
		const status = addStatus( row );
		status.row.setAttribute( 'aria-busy', 'true' );
		status.cell.textContent = __( 'Loading subpages…', 'hierarchy-guard' );
		return status;
	};

	/**
	 * Shows a row whose subpages have all gone since the screen loaded as a
	 * page without any: no toggle, no "View subpages", and the cell's "No
	 * subpages" as the server prints it.
	 *
	 * @param {HTMLTableRowElement} row      The row.
	 * @param {boolean}             hadFocus Whether a row removed under it had the keyboard.
	 * @param {boolean}             hadStop  Whether a row removed under it was the one Tab reached.
	 */
	const showEmpty = ( row, hadFocus, hadStop ) => {
		const button = row.querySelector( 'button.hierarchy-guard-toggle' );
		const lost = hadFocus || ( !! button && button === doc.activeElement );
		if ( button ) {
			button.remove();
		}
		row.removeAttribute( 'aria-expanded' );
		const cell = row.querySelector( '.hierarchy-guard-cell' );
		// "View subpages" would lead to an empty subtree; core leaves no separator after the last action.
		const subpages = row.querySelector(
			'.row-actions .hierarchy_guard_subpages'
		);
		if ( subpages ) {
			const before = subpages.previousElementSibling;
			const separator = before && before.lastChild;
			if (
				! subpages.nextElementSibling &&
				separator &&
				separator.nodeType === separator.TEXT_NODE &&
				'|' === separator.textContent.trim()
			) {
				separator.remove();
			}
			subpages.remove();
		}
		if ( cell ) {
			cell.dataset.children = '0';
			delete cell.dataset.open;
			const dash = doc.createElement( 'span' );
			dash.setAttribute( 'aria-hidden', 'true' );
			dash.textContent = '—';
			const text = doc.createElement( 'span' );
			text.className = 'screen-reader-text';
			text.textContent = __( 'No subpages', 'hierarchy-guard' );
			cell.replaceChildren( dash, text );
		}
		if ( lost ) {
			focus( row );
		} else if ( hadStop ) {
			setTabStop( row );
		}
	};

	/**
	 * Puts one loaded page of a row's subpages under it, in place of its
	 * status row, which hands them the keyboard or the tab stop it held.
	 *
	 * @param {HTMLTableRowElement}                                    row       The opened row.
	 * @param {number}                                                 page      Page of its subpages, from 1.
	 * @param {Object}                                                 result    The route's answer.
	 * @param {{row: HTMLTableRowElement, cell: HTMLTableCellElement}} status    Its status row.
	 * @param {boolean}                                                moveFocus Whether the first new row takes the keyboard.
	 */
	const show = ( row, page, result, status, moveFocus ) => {
		const hadFocus = status.row.contains( doc.activeElement );
		const hadStop = '0' === status.row.getAttribute( 'tabindex' );
		status.row.remove();
		const children = result.children || [];
		if ( 1 === page && ! children.length && ! result.children_total ) {
			showEmpty( row, hadFocus, hadStop );
			return;
		}

		const id = idOf( row );
		let anchor = lastOf( row );
		let first = null;
		children.forEach( ( child ) => {
			const node = childRow( template, child, {
				level: levelOf( row ),
				parent: id,
				// Core's "Child of" under an untitled page names no one.
				parentTitle: result.untitled ? '' : titleOf( row ),
				page,
				// The server's place, so a page reads the same opened either way; none past the cap.
				position: child.position,
				setsize: child.setsize,
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
		// Subpages exist, but none on this page the user may read: a row says so, once.
		const notice = first ? null : addHidden( row );
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
		if ( moveFocus || hadFocus ) {
			focus( first || notice || row );
		} else if ( hadStop ) {
			setTabStop( first || notice || row );
		}
	};

	/**
	 * Loads one page of a row's subpages under it. While it loads the row has
	 * no second load; the promise settles once what it loaded is shown.
	 *
	 * @param {HTMLTableRowElement}                                         row       The opened row.
	 * @param {number}                                                      page      Page of its subpages, from 1.
	 * @param {boolean}                                                     moveFocus Whether the first new row takes the keyboard.
	 * @param {{row: HTMLTableRowElement, cell: HTMLTableCellElement}|null} reuse     The Show more or failure row to keep while it loads.
	 * @return {Promise<void>} Done.
	 */
	const load = ( row, page, moveFocus = false, reuse = null ) => {
		if ( pending.has( row ) ) {
			return pending.get( row ).promise;
		}
		let status = reuse;
		if ( status ) {
			status.row.setAttribute( 'aria-busy', 'true' );
		} else {
			status = addLoading( row );
		}
		const entry = { status, page, open: opens.get( row ) || 0 };
		let request;
		try {
			request = Promise.resolve( fetchChildren( idOf( row ), page ) );
		} catch ( error ) {
			request = Promise.reject( error );
		}
		entry.promise = request.then(
			( result ) => settle( row, entry, result, moveFocus ),
			() => settle( row, entry, null, moveFocus )
		);
		pending.set( row, entry );
		return entry.promise;
	};

	/**
	 * Shows what a load brought, if the row still wants it.
	 *
	 * @param {HTMLTableRowElement} row       The opened row.
	 * @param {Object}              entry     The load: its status row, page and open.
	 * @param {Object|null}         result    The route's answer, or null when it failed.
	 * @param {boolean}             moveFocus Whether the first new row takes the keyboard.
	 * @return {Promise<void>|undefined} A fresh load, when one was needed.
	 */
	const settle = ( row, entry, result, moveFocus ) => {
		pending.delete( row );
		const { status, page } = entry;
		if (
			! row.isConnected ||
			'true' !== row.getAttribute( 'aria-expanded' )
		) {
			// Closed while it loaded, or gone with a closed parent.
			status.row.remove();
			return;
		}
		if ( entry.open !== ( opens.get( row ) || 0 ) && page > 1 ) {
			// Closed and opened again while a later page loaded: the new open starts at the first.
			return load(
				row,
				1,
				false,
				status.row.isConnected ? status : null
			);
		}
		if ( ! result ) {
			failed( row, page, status );
			return;
		}
		show( row, page, result, status, moveFocus );
	};

	const expand = ( row ) => {
		const entry = pending.get( row );
		if ( 'false' !== row.getAttribute( 'aria-expanded' ) ) {
			return entry ? entry.promise : Promise.resolve();
		}
		setOpen( row, true );
		if ( entry ) {
			// Opened again before the last open's load came back: it waits for that one.
			entry.status = addLoading( row );
			return entry.promise;
		}
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
		opens.set( row, ( opens.get( row ) || 0 ) + 1 );
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
	/**
	 * Takes a part the server printed in an opened row's cell out of it,
	 * with the separator before it.
	 *
	 * @param {Element} part The part.
	 */
	const takeOut = ( part ) => {
		const separator = part.previousElementSibling;
		if ( separator && separator.matches( '[aria-hidden="true"]' ) ) {
			separator.remove();
		}
		part.remove();
	};

	// The sentence on subpages the user can't view, "Show more" and the cap's sentence, printed in an opened row's cell, become rows under it.
	rows()
		.filter( ( row ) => 'true' === row.getAttribute( 'aria-expanded' ) )
		.forEach( ( row ) => {
			const cell = row.querySelector( '.hierarchy-guard-cell' );
			const hidden = cell.querySelector( '.hierarchy-guard-hidden' );
			if ( hidden ) {
				takeOut( hidden );
				addHidden( row );
			}
			const more = cell.querySelector(
				'a.hierarchy-guard-more, .hierarchy-guard-capped'
			);
			if ( ! more ) {
				return;
			}
			takeOut( more );
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
			// Alt+Left is the browser's Back; Control and Command keys are the browser's too.
			event.altKey ||
			event.ctrlKey ||
			event.metaKey ||
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
