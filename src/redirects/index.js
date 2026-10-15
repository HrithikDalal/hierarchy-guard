/**
 * Settings › Hierarchy Guard › Redirects: Delete asks first, from a row, the
 * Edit view or in bulk, as core asks before deleting a term. Without
 * JavaScript the Delete runs, as in core.
 */
import { bulkQuestion, guard, linkQuestion } from './confirm';

// eslint-disable-next-line no-alert
const ask = ( question ) => window.confirm( question );

window.document.addEventListener( 'click', ( event ) => {
	guard( event, linkQuestion( event.target ), ask );
} );

window.document.addEventListener( 'submit', ( event ) => {
	guard( event, bulkQuestion( event.target, event.submitter ), ask );
} );
