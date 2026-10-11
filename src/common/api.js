/**
 * Requests to the plugin's REST routes.
 */
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import config from './config';

const base = () => '/' + config.namespace;

/**
 * The hierarchy of one post.
 *
 * @param {number} id   Post ID.
 * @param {number} page Page of children.
 * @return {Promise<Object>} Hierarchy.
 */
export function fetchHierarchy( id, page = 1 ) {
	return apiFetch( {
		path: addQueryArgs( `${ base() }/posts/${ id }/hierarchy`, {
			children_page: page,
		} ),
	} );
}

/**
 * The route and arguments for a pairs source.
 *
 * @param {Object} source Either { change } or { post, from }.
 * @param {string} kind   pairs or csv.
 * @return {{route: string, args: Object}} Route and arguments.
 */
export function sourceRoute( source, kind ) {
	if ( source.change ) {
		return {
			route: `${ config.namespace }/changes/${ source.change }/${ kind }`,
			args: {},
		};
	}
	return {
		route: `${ config.namespace }/posts/${ source.post }/${ kind }`,
		args: { from: source.from },
	};
}

/**
 * One page of pairs.
 *
 * @param {Object} source  Either { change } or { post, from }.
 * @param {number} page    Page number.
 * @param {number} perPage Pairs per page.
 * @return {Promise<Object>} Page of pairs.
 */
export function fetchPairs( source, page = 1, perPage = 20 ) {
	const { route, args } = sourceRoute( source, 'pairs' );
	return apiFetch( {
		path: addQueryArgs( '/' + route, {
			...args,
			page,
			per_page: perPage,
		} ),
	} );
}

/**
 * The CSV download URL for a source, signed for the REST cookie check.
 *
 * @param {Object} source Either { change } or { post, from }.
 * @param {string} format CSV layout.
 * @return {string} URL.
 */
export function csvUrl( source, format ) {
	const { route, args } = sourceRoute( source, 'csv' );
	const root = config.restUrl.includes( '?' )
		? addQueryArgs( config.restUrl, { rest_route: '/' + route } )
		: config.restUrl.replace( /\/?$/, '/' ) + route;
	return addQueryArgs( root, {
		...args,
		format,
		_wpnonce: config.restNonce,
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
