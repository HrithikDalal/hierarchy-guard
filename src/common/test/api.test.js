/**
 * Routes and download URLs.
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { csvUrl, hierarchyPath, sourceRoute } from '../api';
import config from '../config';

describe( 'routes', () => {
	beforeEach( () => {
		config.restNonce = 'abc';
	} );

	it( 'asks for a page of children, 50 at a time for the tree', () => {
		expect( hierarchyPath( 42 ) ).toBe(
			'/hierarchy-guard/v1/posts/42/hierarchy?children_page=1'
		);
		expect( hierarchyPath( 42, 2, 50 ) ).toBe(
			'/hierarchy-guard/v1/posts/42/hierarchy?children_page=2&children_per_page=50'
		);
		expect( hierarchyPath( 42, 2, 50, true ) ).toBe(
			'/hierarchy-guard/v1/posts/42/hierarchy?children_page=2&children_per_page=50&tree=1'
		);
	} );

	it( 'names the change routes', () => {
		expect( sourceRoute( { change: 'x-1' }, 'pairs' ) ).toBe(
			'hierarchy-guard/v1/changes/x-1/pairs'
		);
		expect( sourceRoute( { change: 'x-1' }, 'csv' ) ).toBe(
			'hierarchy-guard/v1/changes/x-1/csv'
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
		expect( csvUrl( { change: 'x-1' }, 'generic' ) ).toBe(
			'https://example.org/index.php?rest_route=%2Fhierarchy-guard%2Fv1%2Fchanges%2Fx-1%2Fcsv&format=generic&_wpnonce=abc'
		);
	} );
} );
