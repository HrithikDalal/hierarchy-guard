/**
 * The words beside a spinner, said politely as it appears, so a screen
 * reader hears that something is loading without moving to it.
 */
import { speak } from '@wordpress/a11y';
import { __ } from '@wordpress/i18n';

/**
 * What a spinner means.
 *
 * @return {string} The words.
 */
export function loadingText() {
	return __( 'Loading…', 'hierarchy-guard' );
}

/**
 * Says what a spinner that just appeared means, politely.
 */
export function speakLoading() {
	speak( loadingText(), 'polite' );
}

/**
 * Whether a spinner shows: while its view is open or loading, until the
 * data or an error comes.
 *
 * @param {boolean}     shown Whether the view is open, or loading.
 * @param {Object|null} data  The data, once it came.
 * @param {string}      error The error, once one came.
 * @return {boolean} Whether it shows.
 */
export function waiting( shown, data, error ) {
	return !! shown && ! data && ! error;
}
