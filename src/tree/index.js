/**
 * The Tree view's script, part of the list-table bundle: the tree grid on
 * core's table, "Collapse all", and "Back to the tree" beside a search's
 * heading.
 */
import { fetchHierarchy } from '../common/api';
import config from '../common/config';
import { PER_PAGE } from './rows';
import { createTree } from './tree';

/**
 * One page of a page's subpages, 50 at a time, without the editor panel's fields.
 *
 * @param {number} id   Page ID.
 * @param {number} page Page of its subpages, from 1.
 * @return {Promise<Object>} The page's subpages.
 */
const fetchChildren = ( id, page ) =>
	fetchHierarchy( id, page, PER_PAGE, 'tree' );

/**
 * Sets up the Tree view, if the screen is one.
 *
 * @param {Document}                                      doc     The document.
 * @param {(id: number, page: number) => Promise<Object>} loadKin Loads one page of a page's subpages.
 * @return {Object|null} The tree's actions, or null.
 */
export default function bindTree(
	doc = window.document,
	loadKin = fetchChildren
) {
	// A search in the Tree view: "Back to the tree" goes beside core's "Search results for".
	const back = doc.querySelector( '.hierarchy-guard-back' );
	const subtitle = doc.querySelector( '.wrap .subtitle' );
	if ( back && subtitle ) {
		const separator = doc.createElement( 'span' );
		separator.setAttribute( 'aria-hidden', 'true' );
		separator.textContent = ' · ';
		subtitle.append( separator, ...back.childNodes );
		back.remove();
	}

	const data = doc.querySelector( '.hierarchy-guard-tree' );
	const body = doc.getElementById( 'the-list' );
	if ( ! data || ! body ) {
		return null;
	}
	const tree = createTree( {
		body,
		data: data.dataset,
		cap: config.cap,
		fetchChildren: loadKin,
	} );
	if ( ! tree ) {
		return null;
	}

	const collapse = doc.querySelector( 'a.hierarchy-guard-collapse' );
	if ( collapse ) {
		const button = doc.createElement( 'button' );
		button.type = 'button';
		button.className = 'button-link hierarchy-guard-collapse';
		button.textContent = collapse.textContent;
		button.addEventListener( 'click', () => tree.collapseAll() );
		collapse.replaceWith( button );
	}
	return tree;
}
