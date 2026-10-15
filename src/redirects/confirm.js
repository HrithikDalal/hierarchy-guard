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
 * The action a submit of the table's form runs, read as the server reads it:
 * the top selector's, unless it is empty or -1, then the bottom one's.
 *
 * @param {Object} form The form submitted.
 * @return {string} Action, or ''.
 */
function chosenAction( form ) {
	for ( const name of [ 'action', 'action2' ] ) {
		const select = form.querySelector( `select[name="${ name }"]` );
		const value = select ? String( select.value ) : '';
		if ( '' !== value && '-1' !== value ) {
			return value;
		}
	}
	return '';
}

/**
 * The question a bulk Delete asks, once for the selection, or null. Any
 * submit that deletes asks: Apply, and Enter in the search box or the page
 * number, which submits through the form's first button. Filter runs no
 * action, and neither does a submit with nothing ticked.
 *
 * @param {Object|null}           form      The form submitted.
 * @param {Object|null|undefined} submitter The button that submitted it, if any.
 * @return {string|null} Question.
 */
export function bulkQuestion( form, submitter ) {
	if (
		! form ||
		! form.hasAttribute ||
		! form.hasAttribute( 'data-hierarchy-guard-confirm' ) ||
		( submitter && 'filter_action' === submitter.name )
	) {
		return null;
	}
	const checked = form.querySelectorAll( 'input[name="redirect[]"]:checked' );
	if ( 'delete' !== chosenAction( form ) || 0 === checked.length ) {
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
