/**
 * What Quick Edit shows and says: the warning in its form, and the
 * announcement after a save.
 */
import { speak } from '@wordpress/a11y';
import { __ } from '@wordpress/i18n';
import config from '../common/config';
import { saveWarning } from '../common/copy';

/**
 * The warning in the Quick Edit form of a page whose subpages' URLs a save
 * would change, with what happens to the old URLs, as in the editors.
 *
 * @param {{count: number, capped: boolean}} descendants The page's subpages at every level.
 * @return {string} Two sentences.
 */
export function warningText( descendants ) {
	return saveWarning( descendants.count, descendants.capped, config.serving );
}

/**
 * Speaks a sentence after a Quick Edit save. Core says "Changes saved." once
 * the saved row has faded in, and in the same polite region a later message
 * replaces an earlier one. While the row is still fading in, the sentence
 * waits for it and carries core's words too, so both are said in one. Once
 * the fade is over, core has already spoken, so the sentence is said alone.
 *
 * @param {number} postId   The saved row's post.
 * @param {string} sentence What to say.
 */
export function speakAfterSave( postId, sentence ) {
	const row = window.jQuery( `#post-${ postId }` );
	if ( row.length && row.is( ':animated' ) ) {
		row.promise().done( () =>
			speak(
				`${ __( 'Changes saved.', 'hierarchy-guard' ) } ${ sentence }`,
				'polite'
			)
		);
	} else {
		speak( sentence, 'polite' );
	}
}
