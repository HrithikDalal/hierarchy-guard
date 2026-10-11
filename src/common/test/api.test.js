/**
 * Routes and download URLs.
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { csvUrl, sourceRoute } from '../api';
import config from '../config';

describe( 'routes', () => {
	beforeEach( () => {
		config.restNonce = 'abc';
	} );

	it( 'names the change and post routes', () => {
		expect( sourceRoute( { change: 'x-1' }, 'pairs' ) ).toEqual( {
			route: 'hierarchy-guard/v1/changes/x-1/pairs',
			args: {},
		} );
		expect( sourceRoute( { post: 42, from: 'about-us' }, 'csv' ) ).toEqual(
			{
				route: 'hierarchy-guard/v1/posts/42/csv',
				args: { from: 'about-us' },
			}
		);
	} );

	it( 'signs a download URL with pretty permalinks', () => {
		config.restUrl = 'https://example.org/wp-json/';
		expect( csvUrl( { change: 'x-1' }, 'redirection' ) ).toBe(
			'https://example.org/wp-json/hierarchy-guard/v1/changes/x-1/csv?format=redirection&_wpnonce=abc'
		);
	} );

	it( 'signs a download URL with the nonce apiFetch renewed', () => {
		config.restUrl = 'https://example.org/wp-json/';
		globalThis.wp = { apiFetch: { nonceMiddleware: { nonce: 'fresh' } } };
		try {
			expect( csvUrl( { change: 'x-1' }, 'generic' ) ).toBe(
				'https://example.org/wp-json/hierarchy-guard/v1/changes/x-1/csv?format=generic&_wpnonce=fresh'
			);
		} finally {
			delete globalThis.wp;
		}
	} );

	it( 'signs a download URL with plain permalinks', () => {
		config.restUrl = 'https://example.org/index.php?rest_route=/';
		expect( csvUrl( { post: 42, from: 'about-us' }, 'generic' ) ).toBe(
			'https://example.org/index.php?rest_route=%2Fhierarchy-guard%2Fv1%2Fposts%2F42%2Fcsv&from=about-us&format=generic&_wpnonce=abc'
		);
	} );
} );
