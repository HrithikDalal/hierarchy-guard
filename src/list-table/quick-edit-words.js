/**
 * What Quick Edit shows and says: the warning in its form, and the
 * announcement after a save.
 */
import { speak } from '@wordpress/a11y';
import { __ } from '@wordpress/i18n';
import { quickEditWarning } from '../common/copy';

/**
 * The warning in the Quick Edit form of a page whose subpages' URLs a save
 * would change.
 *
 * @param {{count: number, capped: boolean}} descendants The page's subpages at every level.
 * @return {string} Sentence.
 */
export function warningText( descendants ) {
	return quickEditWarning( descendants.count, descendants.capped );
}

/**
 * Speaks a sentence after core's own "Changes saved.", which core speaks
 * once the saved row has faded in. In the same polite region the later
 * message replaces the earlier, so both are said in one.
 *
 * @param {number} postId   The saved row's post.
 * @param {string} sentence What to say.
 */
export function speakAfterSave( postId, sentence ) {
	const say = () =>
		speak(
			`${ __( 'Changes saved.', 'hierarchy-guard' ) } ${ sentence }`,
			'polite'
		);
	const row = window.jQuery( `#post-${ postId }` );
	if ( row.length ) {
		row.promise().done( say );
	} else {
		say();
	}
}
