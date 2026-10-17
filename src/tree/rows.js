/**
 * The rows the Tree view adds to core's table: a subpage the hierarchy route
 * loaded, with the list's columns, and the status rows under an opened page.
 */
import { addQueryArgs } from '@wordpress/url';
import {
	childOfLabel,
	lockedLabel,
	subpagesLabel,
	treeRowLabels,
	viewSubpagesLabel,
} from '../common/copy';

export const PER_PAGE = 50;

/**
 * An element with a class and text.
 *
 * @param {Document} doc       The document.
 * @param {string}   tag       Tag name.
 * @param {string}   className Class, or ''.
 * @param {string}   text      Text, or ''.
 * @return {HTMLElement} Element.
 */
function el( doc, tag, className = '', text = '' ) {
	const node = doc.createElement( tag );
	if ( className ) {
		node.className = className;
	}
	if ( text ) {
		node.textContent = text;
	}
	return node;
}

/**
 * A link's address when it is an http(s) address or a path, or '' for any
 * other scheme, javascript: included. That is on purpose stricter than core's
 * own rows, which print the View link unescaped, and than esc_url(), which
 * keeps mailto:, tel: and the other protocols WordPress allows.
 *
 * @param {string} href URL.
 * @return {string} The URL, or ''.
 */
export function safeHref( href ) {
	const value = String( href || '' );
	// Browsers drop spaces and control characters from a scheme, as in "java\tscript:".
	const bare = Array.from( value )
		.filter( ( char ) => char.charCodeAt( 0 ) > 32 )
		.join( '' );
	const scheme = bare.match( /^([a-z][a-z0-9+.-]*):/i );
	return ! scheme || /^https?$/i.test( scheme[ 1 ] ) ? value : '';
}

/**
 * A link.
 *
 * @param {Document} doc   The document.
 * @param {string}   href  URL.
 * @param {string}   text  Text.
 * @param {string}   label Accessible name, or ''.
 * @return {HTMLAnchorElement} Link.
 */
function link( doc, href, text, label = '' ) {
	const node = el( doc, 'a', '', text );
	node.setAttribute( 'href', safeHref( href ) );
	if ( label ) {
		node.setAttribute( 'aria-label', label );
	}
	return node;
}

/**
 * The tree's place to come back to after a trash: the view at its page and
 * sort, opened at a page, at the page of its subpages a row was on.
 *
 * @param {string} place  The view's URL at its page and sort.
 * @param {number} parent Page to open, or 0.
 * @param {number} page   Page of its subpages, from 1.
 * @return {string} URL.
 */
export function placeUrl( place, parent, page = 1 ) {
	if ( ! parent ) {
		return place;
	}
	return addQueryArgs( place, {
		hierarchy_guard_open: parent,
		...( page > 1 ? { hierarchy_guard_children_page: page } : {} ),
	} );
}

/**
 * The list filtered to a page's subtree, as "View subpages" links it: on the
 * admin screen of the tree's place, as the server's absolute links are.
 *
 * @param {string} place The view's URL at its page and sort.
 * @param {string} type  Post type.
 * @param {number} id    Page ID.
 * @return {string} URL.
 */
function subtreeUrl( place, type, id ) {
	return addQueryArgs( place.split( '?' )[ 0 ] || 'edit.php', {
		post_type: type,
		hierarchy_guard_under: id,
	} );
}

/**
 * Core's row actions, "Edit | Trash | View | View subpages".
 *
 * @param {Document}                doc     The document.
 * @param {Array<[string,Element]>} actions Action class and link.
 * @return {HTMLElement} The row actions.
 */
function rowActions( doc, actions ) {
	const box = el( doc, 'div', 'row-actions' );
	actions.forEach( ( [ name, node ], index ) => {
		const item = el( doc, 'span', name );
		item.appendChild( node );
		if ( index < actions.length - 1 ) {
			item.appendChild( doc.createTextNode( ' | ' ) );
		}
		box.appendChild( item );
	} );
	return box;
}

/**
 * The title cell's content as core prints it: the edit lock, the title with
 * its "Child of" description and its post states, and the row actions.
 *
 * @param {Document}              doc     The document.
 * @param {Object}                child   The subpage from the route.
 * @param {Object}                context Where it goes.
 * @param {Object<string,string>} labels  The row's labels, from treeRowLabels().
 * @return {DocumentFragment} Content.
 */
function titleContent( doc, child, context, labels ) {
	const out = doc.createDocumentFragment();
	const described = `post-hierarchy-${ child.id }`;
	if ( child.edit_link ) {
		// Core's lock notice, which its heartbeat fills when another user starts editing.
		const lock = el( doc, 'div', 'locked-info' );
		lock.appendChild( el( doc, 'span', 'locked-avatar' ) );
		lock.appendChild( doc.createTextNode( ' ' ) );
		lock.appendChild(
			el(
				doc,
				'span',
				'locked-text',
				child.locked_by ? lockedLabel( child.locked_by ) : ''
			)
		);
		out.appendChild( lock );
	}
	const strong = el( doc, 'strong' );
	if ( child.edit_link ) {
		const title = link( doc, child.edit_link, child.title );
		title.className = 'row-title';
		strong.appendChild( title );
		if ( 'string' === typeof context.parentTitle ) {
			title.setAttribute( 'aria-describedby', described );
			const description = el(
				doc,
				'span',
				'hidden',
				childOfLabel( context.parentTitle )
			);
			description.id = described;
			strong.appendChild( description );
		}
	} else {
		const title = el( doc, 'span', '', child.title );
		if ( 'string' === typeof context.parentTitle ) {
			const description = el(
				doc,
				'span',
				'screen-reader-text',
				` (${ childOfLabel( context.parentTitle ) })`
			);
			description.id = described;
			title.appendChild( description );
		}
		strong.appendChild( title );
	}
	const states =
		child.states ||
		( 'publish' !== child.status ? [ child.status_label ] : [] );
	if ( states.length ) {
		strong.appendChild( doc.createTextNode( ' — ' ) );
		states.forEach( ( state, index ) =>
			strong.appendChild(
				el(
					doc,
					'span',
					'post-state',
					state + ( index < states.length - 1 ? ', ' : '' )
				)
			)
		);
	}
	out.appendChild( strong );

	const actions = [];
	if ( child.edit_link ) {
		actions.push( [
			'edit',
			link( doc, child.edit_link, labels.edit, labels.editLabel ),
		] );
	}
	if ( child.trash_link ) {
		const trash = link(
			doc,
			addQueryArgs( child.trash_link, {
				_wp_http_referer: placeUrl(
					context.place,
					context.parent,
					context.page
				),
			} ),
			labels.trash,
			labels.trashLabel
		);
		trash.className = 'submitdelete';
		actions.push( [ 'trash', trash ] );
	}
	if ( child.view_link ) {
		const view = link(
			doc,
			child.view_link,
			labels.view,
			labels.viewLabel
		);
		view.rel = 'bookmark';
		actions.push( [ 'view', view ] );
	}
	if ( child.children_count > 0 ) {
		actions.push( [
			'hierarchy_guard_subpages',
			link(
				doc,
				subtreeUrl( context.place, context.type, child.id ),
				labels.subpages,
				labels.subpagesLabel
			),
		] );
	}
	if ( actions.length ) {
		out.appendChild( rowActions( doc, actions ) );
		// Core prints "Show more details" with the row actions, and neither without them.
		const details = el( doc, 'button', 'toggle-row' );
		details.type = 'button';
		details.appendChild(
			el( doc, 'span', 'screen-reader-text', labels.details )
		);
		out.appendChild( details );
	}
	return out;
}

/**
 * The Hierarchy cell: the subpage count as a link to the subtree, with the
 * row's place in the tree for the script, as the server prints it.
 *
 * @param {Document}              doc     The document.
 * @param {Object}                child   The subpage from the route.
 * @param {Object}                context Where it goes.
 * @param {Object<string,string>} labels  The row's labels, from treeRowLabels().
 * @return {HTMLElement} Cell content.
 */
function hierarchyContent( doc, child, context, labels ) {
	const cell = el( doc, 'span', 'hierarchy-guard-cell' );
	const count = child.children_count;
	const data = {
		id: child.id,
		children: child.children_capped ? count + 1 : count,
		status: child.status,
		level: context.level,
		parent: context.parent,
		page: context.page,
	};
	// A place left out or null is not printed, as the server leaves it off.
	if ( undefined !== context.position && null !== context.position ) {
		data.position = context.position;
	}
	if ( undefined !== context.setsize && null !== context.setsize ) {
		data.setsize = context.setsize;
	}
	Object.entries( data ).forEach( ( [ key, value ] ) => {
		cell.dataset[ key ] = String( value );
	} );
	if ( count > 0 ) {
		cell.appendChild(
			link(
				doc,
				subtreeUrl( context.place, context.type, child.id ),
				subpagesLabel( count, child.children_capped ),
				viewSubpagesLabel( count, child.children_capped, child.title )
			)
		);
	} else {
		const dash = el( doc, 'span', '', '—' );
		dash.setAttribute( 'aria-hidden', 'true' );
		cell.appendChild( dash );
		cell.appendChild(
			el( doc, 'span', 'screen-reader-text', labels.noSubpages )
		);
	}
	return cell;
}

/**
 * Fills one cell of a loaded row.
 *
 * @param {Document}              doc     The document.
 * @param {HTMLElement}           cell    The new cell.
 * @param {string}                column  Column name.
 * @param {Object}                child   The subpage from the route.
 * @param {Object}                context Where it goes.
 * @param {Object<string,string>} labels  The row's labels, from treeRowLabels().
 */
function fill( doc, cell, column, child, context, labels ) {
	if ( 'title' === column ) {
		cell.appendChild( titleContent( doc, child, context, labels ) );
	} else if ( 'hierarchy_guard' === column ) {
		cell.appendChild( hierarchyContent( doc, child, context, labels ) );
	} else if ( 'author' === column ) {
		if ( child.author ) {
			cell.textContent = child.author;
		} else {
			const dash = el( doc, 'span', '', '—' );
			dash.setAttribute( 'aria-hidden', 'true' );
			cell.appendChild( dash );
			cell.appendChild(
				el( doc, 'span', 'screen-reader-text', labels.noAuthor )
			);
		}
	} else if ( 'date' === column && child.date ) {
		cell.appendChild(
			child.date.missed
				? el( doc, 'strong', 'error-message', child.date.label )
				: doc.createTextNode( child.date.label )
		);
		cell.appendChild( el( doc, 'br' ) );
		cell.appendChild( doc.createTextNode( child.date.date ) );
	}
}

/**
 * A row for a subpage the route loaded. Its cells copy the columns of a row
 * core printed, so hidden columns stay hidden and another plugin's column
 * keeps its place, empty.
 *
 * @param {HTMLTableRowElement} template A row core printed.
 * @param {Object}              child    The subpage from the route.
 * @param {Object}              context  Where it goes: level (from 0), parent, parentTitle ('' for an untitled parent, left out for none), page, position and setsize (or null, or left out), place, type.
 * @return {HTMLTableRowElement} Row.
 */
export function childRow( template, child, context ) {
	const doc = template.ownerDocument;
	const labels = treeRowLabels( child.title );
	// Core's classes for the row, from the route, then the level and the lock, as core adds them.
	const row = el(
		doc,
		'tr',
		[
			...( child.classes || [ `status-${ child.status }` ] ),
			`level-${ context.level }`,
			'hierarchy-guard-loaded',
			...( child.locked_by ? [ 'wp-locked' ] : [] ),
		].join( ' ' )
	);
	row.id = `post-${ child.id }`;
	Array.from( template.children ).forEach( ( source ) => {
		const cell = doc.createElement( source.tagName.toLowerCase() );
		cell.className = source.className;
		if ( source.hasAttribute( 'scope' ) ) {
			cell.setAttribute( 'scope', source.getAttribute( 'scope' ) );
		}
		if ( source.dataset.colname ) {
			cell.dataset.colname = source.dataset.colname;
		}
		const column = Array.from( source.classList )
			.find( ( name ) => name.startsWith( 'column-' ) )
			?.slice( 'column-'.length );
		if ( 'title' === column && source.hasAttribute( 'aria-label' ) ) {
			// WordPress 7.1 names the row by its title alone, or "no title".
			cell.setAttribute(
				'aria-label',
				child.untitled ? labels.noTitle : child.title
			);
		}
		fill( doc, cell, column || '', child, context, labels );
		row.appendChild( cell );
	} );
	return row;
}

/**
 * A row under an opened page that is not a page: "Show more", the cap, a
 * load in progress or a failure. It spans the table and sits at the level
 * of the subpages it is about.
 *
 * @param {HTMLTableRowElement} template A row core printed.
 * @param {number}              parent   The opened page.
 * @param {number}              level    Level of its subpages, from 0.
 * @return {{row: HTMLTableRowElement, cell: HTMLTableCellElement}} The row and its one cell.
 */
export function statusRow( template, parent, level ) {
	const doc = template.ownerDocument;
	const row = el( doc, 'tr', `level-${ level } hierarchy-guard-status` );
	row.dataset.parent = String( parent );
	// Its level for the keys, from 0 as the row's class and the cells count it.
	row.dataset.level = String( level );
	const cell = el( doc, 'td', 'colspanchange' );
	cell.colSpan = Array.from( template.children ).filter(
		( source ) => ! source.classList.contains( 'hidden' )
	).length;
	row.appendChild( cell );
	return { row, cell };
}
