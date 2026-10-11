/**
 * What the block editor and Quick Edit say after a save that changed URLs:
 * which change it was, and the notice for that change's redirects.
 */
import config from './config';
import {
	changedSentence,
	changedStatus,
	pairsRedirects,
	viewRedirectsLabel,
} from './copy';

/**
 * The change a save made, when the page's latest change is new to the editor.
 *
 * @param {string|null|undefined} before The page's latest change before the save, undefined when it was not loaded.
 * @param {string|null}           after  The page's latest change after the save.
 * @return {string|null} Change ID, or null when the save recorded no change.
 */
export function newChange( before, after ) {
	return after && after !== before ? after : null;
}

/**
 * The notice for a change's redirects, from the first page of its pairs.
 *
 * @param {{total: number, capped: boolean}|null} pairs     The first page of the change's pairs.
 * @param {Object}                                redirects The page's redirects from the REST route, or the site's.
 * @return {{status: string, sentence: string, view: string, setup: boolean}|null} The notice's status and sentence, the "View redirects" label (empty when the server redirects them), and whether to offer setting up redirects; null when no URL needs a redirect.
 */
export function changeNotice( pairs, redirects ) {
	if ( ! pairs || ! pairs.total ) {
		return null;
	}
	// The notice is about the pairs, which the server may redirect even when it does not redirect to the page itself.
	const { serving, provider } = pairsRedirects( redirects );
	return {
		status: changedStatus( serving, provider ),
		sentence: changedSentence(
			pairs.total,
			pairs.capped,
			serving,
			provider
		),
		view: serving ? '' : viewRedirectsLabel( pairs.total, pairs.capped ),
		// Never offered while redirects are on for the site, even when this page isn't served.
		setup: ! config.serving && ! provider && !! config.settingsUrl,
	};
}
