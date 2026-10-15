/**
 * What a Delete on the Redirects tab asks first. The questions come from the
 * page: a link's data-hierarchy-guard-confirm, and for a bulk Delete the
 * form's, or the one ticked row's data-hierarchy-guard-confirm-one.
 */

/**
 * The question a click asks, or null: a Delete link carries it.
 *
 * @param {Object|null} target What was clicked.
 * @return {string|null} Question.
 */
export function linkQuestion( target ) {
	const link =
		target && target.closest
			? target.closest( 'a[data-hierarchy-guard-confirm]' )
			: null;
	return link ? link.getAttribute( 'data-hierarchy-guard-confirm' ) : null;
}

/**
 * The question a bulk Delete asks, once for the selection, or null for any
 * other submit: another action, Filter, the search, or nothing ticked.
 *
 * @param {Object|null} form      The form submitted.
 * @param {Object|null} submitter The button that submitted it.
 * @return {string|null} Question.
 */
export function bulkQuestion( form, submitter ) {
	if (
		! form ||
		! form.hasAttribute ||
		! form.hasAttribute( 'data-hierarchy-guard-confirm' ) ||
		! submitter ||
		( 'doaction' !== submitter.id && 'doaction2' !== submitter.id )
	) {
		return null;
	}
	const select = form.querySelector(
		'doaction2' === submitter.id
			? 'select[name="action2"]'
			: 'select[name="action"]'
	);
	const checked = form.querySelectorAll( 'input[name="redirect[]"]:checked' );
	if ( ! select || 'delete' !== select.value || 0 === checked.length ) {
		return null;
	}
	return 1 === checked.length
		? checked[ 0 ].getAttribute( 'data-hierarchy-guard-confirm-one' )
		: form.getAttribute( 'data-hierarchy-guard-confirm' );
}

/**
 * Asks a question, and cancels the event when the answer is no.
 *
 * @param {Object}                        event    The click or submit.
 * @param {string|null}                   question What to ask; nothing is asked without one.
 * @param {(question: string) => boolean} ask      Asks and answers true or false.
 * @return {boolean} Whether the event goes on.
 */
export function guard( event, question, ask ) {
	if ( ! question || ask( question ) ) {
		return true;
	}
	event.preventDefault();
	return false;
}
