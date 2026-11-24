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
