/**
 * Requests to the plugin's REST routes.
 */
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import config from './config';

const base = () => '/' + config.namespace;

/**
 * The route of one post's hierarchy.
 *
 * @param {number} id      Post ID.
 * @param {number} page    Page of children.
 * @param {number} perPage Children per page; the route's own 10 when omitted.
 * @param {string} context 'tree' for the subpages alone, or '' for the whole summary.
 * @return {string} Path.
 */
export function hierarchyPath( id, page = 1, perPage = 0, context = '' ) {
	return addQueryArgs( `${ base() }/posts/${ id }/hierarchy`, {
		children_page: page,
		...( perPage ? { children_per_page: perPage } : {} ),
		...( context ? { context } : {} ),
	} );
}

/**
 * The hierarchy of one post.
 *
 * @param {number} id      Post ID.
 * @param {number} page    Page of children.
 * @param {number} perPage Children per page; the route's own 10 when omitted.
 * @param {string} context 'tree' for the subpages alone, or '' for the whole summary.
 * @return {Promise<Object>} Hierarchy.
 */
export function fetchHierarchy( id, page = 1, perPage = 0, context = '' ) {
	return apiFetch( { path: hierarchyPath( id, page, perPage, context ) } );
}

/**
 * The route of a change's pairs or CSV.
 *
 * @param {{change: string}} source The change.
 * @param {string}           kind   pairs or csv.
 * @return {string} Route.
 */
export function sourceRoute( source, kind ) {
	return `${ config.namespace }/changes/${ source.change }/${ kind }`;
}

/**
 * One page of a change's pairs.
 *
 * @param {{change: string}} source  The change.
 * @param {number}           page    Page number.
 * @param {number}           perPage Pairs per page.
 * @return {Promise<Object>} Page of pairs.
 */
export function fetchPairs( source, page = 1, perPage = 20 ) {
	return apiFetch( {
		path: addQueryArgs( '/' + sourceRoute( source, 'pairs' ), {
			page,
			per_page: perPage,
		} ),
	} );
}

/**
 * The CSV download URL for a change, signed for the REST cookie check.
 *
 * @param {{change: string}} source The change.
 * @param {string}           format CSV layout.
 * @return {string} URL.
 */
export function csvUrl( source, format ) {
	const route = sourceRoute( source, 'csv' );
	const root = config.restUrl.includes( '?' )
		? addQueryArgs( config.restUrl, { rest_route: '/' + route } )
		: config.restUrl.replace( /\/?$/, '/' ) + route;
	return addQueryArgs( root, {
		format,
		// apiFetch renews its nonce when it expires; the one printed at page load does not.
		_wpnonce:
			globalThis.wp?.apiFetch?.nonceMiddleware?.nonce || config.restNonce,
	} );
}

/**
 * What trashing the given pages would do to their subpages.
 *
 * @param {number[]} ids Page IDs.
 * @return {Promise<Object>} Preview.
 */
export function trashPreview( ids ) {
	return apiFetch( {
		path: addQueryArgs( `${ base() }/trash-preview`, { ids } ),
	} );
}

/**
 * Hides the redirects nudge for the current user, in core's dismissed-pointers list.
 *
 * @return {Promise<Response>} The Ajax response.
 */
export function dismissNudge() {
	return window.fetch( window.ajaxurl, {
		method: 'POST',
		credentials: 'same-origin',
		body: new window.URLSearchParams( {
			action: 'dismiss-wp-pointer',
			pointer: 'hierarchy_guard_nudge',
		} ),
	} );
}
