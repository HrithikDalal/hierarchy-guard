/**
 * What the trash dialog sends and shows, kept out of the component so it can
 * be tested.
 */
import config from '../common/config';
import { bulkSentence, initialSentence, trashSentence } from '../common/copy';

/**
 * The only page with subpages, when one page was selected and previewed.
 *
 * @param {Object|null} preview  The trash preview, or null.
 * @param {number}      selected Pages selected in all.
 * @return {Object|null} The page, or null.
 */
export function singlePage( preview, selected ) {
	return preview && 1 === selected && 1 === preview.pages.length
		? preview.pages[ 0 ]
		: null;
}

/**
 * The policy the dialog opens with. One page opens at its own policy. A bulk
 * dialog opens at the policy every previewed page shares, when every selected
 * page with subpages was previewed; otherwise at the site's default.
 *
 * @param {Object} preview      The trash preview, with at least one page.
 * @param {number} selected     Pages selected in all.
 * @param {number} withChildren Selected pages with subpages.
 * @param {number} previewed    How many of them the preview was asked about.
 * @return {string} Policy.
 */
export function openingPolicy( preview, selected, withChildren, previewed ) {
	const single = singlePage( preview, selected );
	if ( single ) {
		return single.policy;
	}
	const first = preview.pages[ 0 ].policy;
	const shared = preview.pages.every( ( page ) => page.policy === first );
	return shared && withChildren <= previewed ? first : preview.policy;
}

/**
 * The policy to send when the dialog is confirmed, or null to leave each page
 * to its own. A changed policy is always sent. Otherwise the dialog sends the
 * policy it states unless it knows every page has it of its own: one
 * previewed page does, and a bulk selection does only when every selected
 * page with subpages was previewed and shares it. After a failed preview it
 * sends what it stated.
 *
 * @param {Object|null} preview     The trash preview, or null when it failed.
 * @param {Object|null} single      The only page with subpages, or null.
 * @param {string}      policy      The policy chosen in the dialog.
 * @param {string}      initial     The policy the dialog opened with.
 * @param {boolean}     unpreviewed Whether some selected pages with subpages were not previewed.
 * @return {string|null} Policy to send.
 */
export function confirmedPolicy(
	preview,
	single,
	policy,
	initial,
	unpreviewed = false
) {
	const mixed =
		! single &&
		( ! preview ||
			unpreviewed ||
			preview.pages.some( ( page ) => page.policy !== policy ) );
	return policy !== initial || mixed ? policy : null;
}

/**
 * The dialog's sentence: what trashing does under the policy.
 *
 * @param {Object}      args              Arguments.
 * @param {Object|null} args.preview      The trash preview, or null before it arrives or when it failed.
 * @param {string}      args.policy       The policy shown.
 * @param {number}      args.selected     Pages selected in all.
 * @param {number}      args.withChildren Selected pages with subpages.
 * @param {number}      args.previewed    How many of them the preview was asked about.
 * @param {string}      args.title        The row's title, for a single page.
 * @param {number}      args.children     The row's direct subpages, for a single page.
 * @return {string} Sentence, or '' for a bulk selection not previewed.
 */
export function dialogSentence( {
	preview,
	policy,
	selected,
	withChildren,
	previewed,
	title,
	children,
} ) {
	const single = singlePage( preview, selected );
	if ( single ) {
		return trashSentence( single, policy, config.cap );
	}
	if ( preview ) {
		return bulkSentence(
			{
				...preview,
				more: preview.more + Math.max( 0, withChildren - previewed ),
			},
			policy,
			withChildren,
			config.cap
		);
	}
	if ( 1 === selected ) {
		return initialSentence( title, children, policy );
	}
	return '';
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
 * to show a subpage's level: each an em dash and a space. A title that is
 * only dashes stays as it is.
 *
 * @param {string} text The title link's text.
 * @return {string} Title.
 */
export function plainTitle( text ) {
	const raw = String( text ).trim();
	const title = raw.replace( /^(?:— )+/, '' ).trim();
	return '' !== title ? title : raw;
}

/**
 * A row's title. Core prints the raw title in the row's Quick Edit data for
 * anyone who may edit the page; otherwise the title link's text is read.
 *
 * @param {Document} doc The document.
 * @param {number}   id  Post ID.
 * @return {string} Title, or '' when the row is not on the screen.
 */
export function rowTitle( doc, id ) {
	const raw = doc.querySelector( `#inline_${ id } .post_title` );
	if ( raw && '' !== raw.textContent.trim() ) {
		return raw.textContent.trim();
	}
	const link = doc.querySelector( `#post-${ id } .row-title` );
	return link ? plainTitle( link.textContent ) : '';
}
