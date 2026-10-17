// @vitest-environment happy-dom
/**
 * The Tree view's indents, from the list-table stylesheet.
 */
import { resolve } from 'node:path';
import { beforeAll, describe, expect, it } from 'vitest';
import { compile } from 'sass';

/**
 * The indent of a row's title cell, or of a status row's one cell, at a level.
 *
 * @param {number}  level  Level, from 0.
 * @param {boolean} status Whether it is a status row.
 * @return {string} Its padding-inline-start.
 */
const indent = ( level, status = false ) => {
	document.getElementById( 'the-list' ).innerHTML = status
		? `<tr class="level-${ level } hierarchy-guard-status"><td class="colspanchange">Show more</td></tr>`
		: `<tr class="iedit author-self level-${ level } post-9 type-page status-publish hentry"><td class="title column-title">Page</td></tr>`;
	return window
		.getComputedStyle( document.querySelector( '#the-list td' ) )
		.getPropertyValue( 'padding-inline-start' );
};

/**
 * A CSS length of pixels, vw, calc() and min(), in pixels at a screen width,
 * worked out as the browser would: happy-dom keeps the expression, but lays
 * nothing out.
 *
 * @param {string} value CSS length.
 * @param {number} width Screen width in pixels.
 * @return {number} Pixels.
 */
const pixels = ( value, width ) => {
	const expression = value
		.replace(
			/([\d.]+)vw/g,
			( match, number ) => `(${ number } * ${ width } / 100)`
		)
		.replace( /px/g, '' )
		.replace( /calc\(/g, '(' )
		.replace( /min\(/g, 'Math.min(' );
	if (
		! /^[\d.\s+\-*/(),]*$/.test( expression.replace( /Math\.min/g, '' ) )
	) {
		throw new Error( `Not a length this test reads: ${ value }` );
	}

	return Function( `return ${ expression };` )();
};

/**
 * The indents of levels 0 to 21 at a screen width, in pixels.
 *
 * @param {number} width Screen width in pixels.
 * @return {number[]} Pixels.
 */
const indents = ( width ) => {
	window.happyDOM.setViewport( { width, height: 768 } );
	try {
		return Array.from( { length: 22 }, ( unused, level ) =>
			pixels( indent( level ), width )
		);
	} finally {
		window.happyDOM.setViewport( { width: 1024, height: 768 } );
	}
};

describe( 'the indent per level', () => {
	beforeAll( () => {
		const style = document.createElement( 'style' );
		style.textContent = compile(
			resolve( __dirname, '../../list-table/list-table.scss' )
		).css;
		document.head.appendChild( style );
		document.body.className = 'hierarchy-guard-tree-view';
		document.body.innerHTML =
			'<table><tbody id="the-list"></tbody></table>';
	} );

	it.each( [ 1024, 800 ] )(
		'steps at every level to 20, 24 pixels first, and stops at a fifth of a %i-pixel screen',
		( width ) => {
			const steps = indents( width );
			steps
				.slice( 1, 21 )
				.forEach( ( offset, index ) =>
					expect( offset ).toBeGreaterThan( steps[ index ] )
				);
			expect( steps[ 1 ] - steps[ 0 ] ).toBeCloseTo( 24 );
			expect( steps[ 20 ] ).toBeLessThanOrEqual( 0.2 * width + 0.001 );
			expect( steps[ 21 ] ).toBe( steps[ 20 ] );
		}
	);

	it( 'keeps 24-pixel steps for more levels on a wider screen, and never passes 514 pixels', () => {
		const wide = indents( 1440 );
		expect( wide.slice( 0, 6 ) ).toEqual( [ 34, 58, 82, 106, 130, 154 ] );
		expect( indents( 3000 )[ 20 ] ).toBe( 514 );
	} );

	it( 'lines a status row deeper than 20 up with its level-20 subpages', () => {
		expect(
			[ 1, 20, 21, 40 ].map( ( level ) => indent( level, true ) )
		).toEqual( [ 1, 20, 20, 20 ].map( ( level ) => indent( level ) ) );
	} );

	it( 'halves the step on a phone, and a row deeper than 20 stops as level 20 does', () => {
		window.happyDOM.setViewport( { width: 600, height: 800 } );
		try {
			expect( [ 1, 20, 21 ].map( ( level ) => indent( level ) ) ).toEqual(
				[ 'min(46px, 50%)', 'min(274px, 50%)', 'min(274px, 50%)' ]
			);
		} finally {
			window.happyDOM.setViewport( { width: 1024, height: 768 } );
		}
	} );
} );
