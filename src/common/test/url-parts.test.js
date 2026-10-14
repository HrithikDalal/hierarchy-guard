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

	it( 'shows a percent-encoded slug decoded, the way core shows permalinks', () => {
		expect( urlParts( '/company/%e6%97%a5%e6%9c%ac/' ) ).toEqual( [
			{ head: '/compan', end: 'y/' },
			{ head: '日', end: '本/' },
		] );
	} );

	it( 'keeps a character outside the basic plane whole', () => {
		expect( urlParts( '/%f0%9f%98%80/' ) ).toEqual( [
			{ head: '/', end: '😀/' },
		] );
	} );

	it( 'leaves a segment that is not valid percent-encoding as it is', () => {
		expect( urlParts( '/a/%e0%a4%a/' ) ).toEqual( [
			{ head: '/', end: 'a/' },
			{ head: '%e0%a4%', end: 'a/' },
		] );
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
