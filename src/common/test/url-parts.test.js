/**
 * How a URL path is split for printing.
 */
import { describe, expect, it } from 'vitest';
import urlParts from '../url-parts';

describe( 'urlParts', () => {
	it( 'keeps each slash with its slug and the last character with the slash', () => {
		expect( urlParts( '/about/team/' ) ).toEqual( [
			{ head: '/abou', end: 't/' },
			{ head: 'tea', end: 'm/' },
		] );
	} );

	it( 'renders a lone slash as one piece', () => {
		expect( urlParts( '/' ) ).toEqual( [ { head: '', end: '/' } ] );
	} );

	it( 'leaves the last slug without a slash when the path has none', () => {
		expect( urlParts( '/about/team' ) ).toEqual( [
			{ head: '/abou', end: 't/' },
			{ head: 'tea', end: 'm' },
		] );
	} );

	it( 'has no lone or doubled slash in a one-letter slug', () => {
		expect( urlParts( '/a/' ) ).toEqual( [ { head: '/', end: 'a/' } ] );
	} );

	it( 'reproduces the input when joined', () => {
		[
			'/about/team/',
			'/about/team',
			'/',
			'/hg-studio-2/hg-crew-3/hg-ada/',
		].forEach( ( path ) => {
			expect(
				urlParts( path )
					.map( ( { head, end } ) => head + end )
					.join( '' )
			).toBe( path );
		} );
	} );
} );
