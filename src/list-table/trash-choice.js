/**
 * What the trash dialog sends and shows, kept out of the component so it can
 * be tested.
 */

/**
 * The policy to send when the dialog is confirmed, or null to leave each page
 * to its own. A bulk dialog shows one policy, so it sends it whenever a
 * previewed page has another of its own.
 *
 * @param {Object|null} preview The trash preview, or null when it failed.
 * @param {Object|null} single  The only page with subpages, or null.
 * @param {string}      policy  The policy chosen in the dialog.
 * @param {string}      initial The policy the dialog opened with.
 * @return {string|null} Policy to send.
 */
export function confirmedPolicy( preview, single, policy, initial ) {
	const mixed =
		! single &&
		!! preview &&
		preview.pages.some( ( page ) => page.policy !== policy );
	return policy !== initial || mixed ? policy : null;
}

/**
 * Whether to say that some subpages can't be moved or trashed. Leaving the
 * subpages where they are needs no rights over them.
 *
 * @param {Object|null} preview The trash preview, or null.
 * @param {string}      policy  The policy chosen in the dialog.
 * @return {boolean} Whether the note applies.
 */
export function showsDenied( preview, policy ) {
	return (
		!! preview &&
		'keep' !== policy &&
		preview.pages.some( ( page ) =>
			'trash_children' === policy ? page.denied_trash : page.denied
		)
	);
}

/**
 * A row title without the dashes WordPress 6.6 prints inside the title link
 * to show a subpage's level.
 *
 * @param {string} text The title link's text.
 * @return {string} Title.
 */
export function plainTitle( text ) {
	return String( text )
		.trim()
		.replace( /^(?:—\s*)+/, '' )
		.trim();
}
