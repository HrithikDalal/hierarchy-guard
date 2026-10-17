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

	// A table cell's percentage padding resolves against the table, so the ceiling is the screen's:
	// at 1024 pixels a title cell is about 342 wide, and 20vw leaves a deep title 137 of them.
	it( 'steps by level up to a fifth of the screen, and a row deeper than 20 indents as level 20', () => {
		expect(
			[ 0, 1, 20, 21, 25, 120 ].map( ( level ) => indent( level ) )
		).toEqual( [
			'34px',
			'min(58px, 20vw)',
			'min(514px, 20vw)',
			'min(514px, 20vw)',
			'min(514px, 20vw)',
			'min(514px, 20vw)',
		] );
	} );

	it( 'lines a status row deeper than 20 up with its level-20 subpages', () => {
		expect(
			[ 1, 20, 21, 40 ].map( ( level ) => indent( level, true ) )
		).toEqual( [
			'min(58px, 20vw)',
			'min(514px, 20vw)',
			'min(514px, 20vw)',
			'min(514px, 20vw)',
		] );
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
