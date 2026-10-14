/**
 * The URLs notices on the list screen. Each offers the redirects of one
 * change, and a later change replaces it, so a new notice takes the earlier
 * notices' links to theirs.
 */

/**
 * Removes the link to its change's redirects from every URLs notice on the
 * screen: the View redirects button, or the CSV download it starts as, with
 * the divider after it. What each notice says stays.
 *
 * @param {Document} doc The document.
 */
export function retireUrlsNotices( doc ) {
	doc.querySelectorAll(
		'.hierarchy-guard-urls .hierarchy-guard-view-redirects'
	).forEach( ( link ) => {
		const next = link.nextElementSibling;
		if ( next && 'true' === next.getAttribute( 'aria-hidden' ) ) {
			next.remove();
		}
		link.remove();
	} );
}
