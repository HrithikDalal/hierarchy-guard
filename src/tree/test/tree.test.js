// @vitest-environment happy-dom
/**
 * The tree grid on core's list table.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { speak } from '@wordpress/a11y';
import { setLocaleData } from '@wordpress/i18n';
import bindTree from '..';
import { child, coreRow, mountScreen, page } from './fixture';

const rows = () => Array.from( document.querySelectorAll( '#the-list > tr' ) );
const row = ( id ) => document.getElementById( `post-${ id }` );
const press = ( target, key ) =>
	target.dispatchEvent(
		new window.KeyboardEvent( 'keydown', { key, bubbles: true } )
	);
const settle = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
const tabStops = () =>
	rows()
		.filter( ( item ) => 0 === item.tabIndex )
		.map( ( item ) => item.id );

vi.mock( '@wordpress/a11y', () => ( { speak: vi.fn() } ) );

/**
 * Company (3 subpages), Contact, Locations (128 subpages) on page 2 of the top level.
 *
 * @return {string} Markup.
 */
const topLevel = () =>
	coreRow( { id: 4, title: 'Company', children: 3, position: 21 } ) +
	coreRow( { id: 8, title: 'Contact', position: 22 } ) +
	coreRow( { id: 9, title: 'Locations', children: 128, position: 23 } );

describe( 'the tree grid', () => {
	let fetchChildren;

	beforeEach( () => {
		fetchChildren = vi.fn( ( id, number ) =>
			Promise.resolve(
				4 === id
					? page(
							[
								child( 5, 'About', { children_count: 4 } ),
								child( 6, 'History' ),
								child( 7, 'Press', {
									status: 'draft',
									status_label: 'Draft',
									view_link: null,
								} ),
							],
							3
						)
					: page(
							Array.from( { length: 50 }, ( unused, index ) =>
								child(
									1000 + ( number - 1 ) * 50 + index,
									`City ${ ( number - 1 ) * 50 + index + 1 }`
								)
							),
							128,
							number < 3
						)
			)
		);
		mountScreen( topLevel() );
	} );

	it( 'puts tree grid roles on core’s table and rows', () => {
		bindTree( document, fetchChildren );

		expect( document.querySelector( 'table' ).getAttribute( 'role' ) ).toBe(
			'treegrid'
		);
		expect(
			rows().map( ( item ) => [
				item.getAttribute( 'aria-level' ),
				item.getAttribute( 'aria-expanded' ),
				item.getAttribute( 'aria-posinset' ),
				item.getAttribute( 'aria-setsize' ),
			] )
		).toEqual( [
			[ '1', 'false', '21', '3' ],
			[ '1', null, '22', '3' ],
			[ '1', 'false', '23', '3' ],
		] );
		expect( rows().map( ( item ) => item.tabIndex ) ).toEqual( [
			0, -1, -1,
		] );
	} );

	it( 'turns the toggle links into core button-link buttons before the title', () => {
		bindTree( document, fetchChildren );

		const button = row( 4 ).querySelector(
			'.column-title strong'
		).firstElementChild;
		expect( button.tagName ).toBe( 'BUTTON' );
		expect( button.className ).toBe( 'button-link hierarchy-guard-toggle' );
		expect( button.getAttribute( 'aria-label' ) ).toBe(
			'Show the subpages of “Company”'
		);
		expect(
			row( 4 ).querySelector( '.hierarchy-guard-cell' ).textContent
		).toBe( '3 subpages' );
		expect(
			document.querySelector( 'button.hierarchy-guard-collapse' )
				.className
		).toBe( 'button-link hierarchy-guard-collapse' );
	} );

	it( 'opens a row with its subpages one level down, 50 at a time', async () => {
		const tree = bindTree( document, fetchChildren );

		await tree.toggle( row( 4 ) );

		expect( fetchChildren ).toHaveBeenCalledWith( 4, 1 );
		expect( rows().map( ( item ) => item.id ) ).toEqual( [
			'post-4',
			'post-5',
			'post-6',
			'post-7',
			'post-8',
			'post-9',
		] );
		expect( row( 4 ).getAttribute( 'aria-expanded' ) ).toBe( 'true' );
		expect(
			row( 4 )
				.querySelector( '.hierarchy-guard-toggle' )
				.getAttribute( 'aria-label' )
		).toBe( 'Hide the subpages of “Company”' );
		expect(
			[ 5, 6, 7 ].map( ( id ) => [
				row( id ).getAttribute( 'aria-level' ),
				row( id ).getAttribute( 'aria-posinset' ),
				row( id ).getAttribute( 'aria-setsize' ),
				row( id ).className,
			] )
		).toEqual( [
			[ '2', '1', '3', 'level-1 status-publish hierarchy-guard-loaded' ],
			[ '2', '2', '3', 'level-1 status-publish hierarchy-guard-loaded' ],
			[ '2', '3', '3', 'level-1 status-draft hierarchy-guard-loaded' ],
		] );
		expect( row( 5 ).getAttribute( 'aria-expanded' ) ).toBe( 'false' );
		expect( row( 6 ).hasAttribute( 'aria-expanded' ) ).toBe( false );
	} );

	it( 'closes a row without a request', async () => {
		const tree = bindTree( document, fetchChildren );
		await tree.toggle( row( 4 ) );

		tree.toggle( row( 4 ) );

		expect( rows().map( ( item ) => item.id ) ).toEqual( [
			'post-4',
			'post-8',
			'post-9',
		] );
		expect( row( 4 ).getAttribute( 'aria-expanded' ) ).toBe( 'false' );
		expect( fetchChildren ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'shows more, 50 at a time, and says how many are shown', async () => {
		const tree = bindTree( document, fetchChildren );
		await tree.toggle( row( 9 ) );

		const more = document.querySelector( '.hierarchy-guard-more' );
		expect( more.textContent ).toBe(
			'Show more · 50 of 128 subpages shown'
		);
		expect( more.getAttribute( 'aria-level' ) ).toBe( '2' );
		expect( more.firstElementChild.colSpan ).toBe( 4 );

		more.querySelector( 'button' ).click();
		await settle();

		expect( fetchChildren ).toHaveBeenLastCalledWith( 9, 2 );
		expect( row( 1050 ).getAttribute( 'aria-posinset' ) ).toBe( '51' );
		expect( document.activeElement ).toBe( row( 1050 ) );
		expect(
			document.querySelector( '.hierarchy-guard-more' ).textContent
		).toBe( 'Show more · 100 of 128 subpages shown' );
		expect( rows().pop().id ).toBe( '' );
	} );

	it( 'says past the cap that only the first are shown', async () => {
		fetchChildren = vi.fn( () =>
			Promise.resolve(
				page( [ child( 30, 'Last' ) ], 1000, false, true )
			)
		);
		const tree = bindTree( document, fetchChildren );

		await tree.toggle( row( 9 ) );

		expect( row( 30 ).hasAttribute( 'aria-setsize' ) ).toBe( false );
		expect( row( 30 ).hasAttribute( 'aria-posinset' ) ).toBe( false );
		expect(
			document.querySelector( '.hierarchy-guard-capped' ).textContent
		).toBe( 'Only the first 1,000 subpages are shown.' );
	} );

	it( 'collapses every open row without a request', async () => {
		const tree = bindTree( document, fetchChildren );
		await tree.toggle( row( 4 ) );
		await tree.toggle( row( 5 ) );
		await tree.toggle( row( 9 ) );
		fetchChildren.mockClear();

		document.querySelector( 'button.hierarchy-guard-collapse' ).click();

		expect( rows().map( ( item ) => item.id ) ).toEqual( [
			'post-4',
			'post-8',
			'post-9',
		] );
		expect(
			rows().map( ( item ) => item.getAttribute( 'aria-expanded' ) )
		).toEqual( [ 'false', null, 'false' ] );
		expect( fetchChildren ).not.toHaveBeenCalled();
	} );

	it( 'moves between rows with up and down', () => {
		bindTree( document, fetchChildren );
		row( 4 ).focus();

		press( row( 4 ), 'ArrowDown' );
		expect( document.activeElement ).toBe( row( 8 ) );
		expect( row( 8 ).tabIndex ).toBe( 0 );
		expect( row( 4 ).tabIndex ).toBe( -1 );

		press( row( 8 ), 'ArrowUp' );
		expect( document.activeElement ).toBe( row( 4 ) );
		press( row( 4 ), 'ArrowUp' );
		expect( document.activeElement ).toBe( row( 4 ) );
	} );

	it( 'opens a row with right, then moves into its first subpage', async () => {
		bindTree( document, fetchChildren );
		row( 4 ).focus();

		press( row( 4 ), 'ArrowRight' );
		await settle();
		expect( row( 4 ).getAttribute( 'aria-expanded' ) ).toBe( 'true' );
		expect( document.activeElement ).toBe( row( 4 ) );

		press( row( 4 ), 'ArrowRight' );
		expect( document.activeElement ).toBe( row( 5 ) );

		press( row( 6 ), 'ArrowRight' );
		expect( fetchChildren ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'moves to the parent with left, then closes it', async () => {
		bindTree( document, fetchChildren );
		row( 4 ).focus();
		press( row( 4 ), 'ArrowRight' );
		await settle();
		row( 6 ).focus();

		press( row( 6 ), 'ArrowLeft' );
		expect( document.activeElement ).toBe( row( 4 ) );

		press( row( 4 ), 'ArrowLeft' );
		expect( row( 4 ).getAttribute( 'aria-expanded' ) ).toBe( 'false' );
		expect( row( 6 ) ).toBe( null );
	} );

	it( 'leaves the keys of a link inside a row to the browser', () => {
		bindTree( document, fetchChildren );
		const link = row( 4 ).querySelector( '.row-title' );
		link.focus();

		press( link, 'ArrowDown' );

		expect( document.activeElement ).toBe( link );
	} );

	it( 'says when the subpages could not be loaded, and tries again without closing', async () => {
		const load = vi
			.fn()
			.mockRejectedValueOnce( new Error() )
			.mockResolvedValue( page( [ child( 5, 'About' ) ], 1 ) );
		const tree = bindTree( document, load );
		const toggleButton = row( 4 ).querySelector(
			'.hierarchy-guard-toggle'
		);
		toggleButton.focus();

		await tree.toggle( row( 4 ) );

		expect( rows()[ 1 ].textContent ).toBe(
			'The subpages couldn’t be loaded. Try again'
		);
		expect( rows()[ 1 ].getAttribute( 'aria-level' ) ).toBe( '2' );
		expect( document.activeElement ).toBe( toggleButton );
		expect( row( 4 ).getAttribute( 'aria-expanded' ) ).toBe( 'true' );

		rows()[ 1 ].querySelector( 'button' ).click();
		await settle();

		expect( rows().map( ( item ) => item.id ) ).toEqual( [
			'post-4',
			'post-5',
			'post-8',
			'post-9',
		] );
		expect( document.activeElement ).toBe( row( 5 ) );
	} );

	it( 'keeps the keyboard on Show more while it loads, and keeps the rows when it fails', async () => {
		const tree = bindTree( document, fetchChildren );
		await tree.toggle( row( 9 ) );
		let fail;
		fetchChildren.mockImplementationOnce(
			() =>
				new Promise( ( resolve, reject ) => {
					fail = reject;
				} )
		);
		const more = document.querySelector( '.hierarchy-guard-more button' );
		more.focus();

		more.click();

		expect( document.activeElement ).toBe( more );
		expect( more.getAttribute( 'aria-disabled' ) ).toBe( 'true' );
		expect(
			document
				.querySelector( '.hierarchy-guard-more' )
				.getAttribute( 'aria-busy' )
		).toBe( 'true' );

		fail( new Error() );
		await settle();

		expect(
			rows().filter( ( item ) => item.id.startsWith( 'post-10' ) )
		).toHaveLength( 50 );
		expect( document.activeElement ).toBe(
			row( 9 ).querySelector( '.hierarchy-guard-toggle' )
		);
		const failure = document.querySelector( '.hierarchy-guard-failed' );
		failure.querySelector( 'button' ).click();
		await settle();
		expect( fetchChildren ).toHaveBeenLastCalledWith( 9, 2 );
		expect( row( 1050 ) ).not.toBe( null );
		expect( document.querySelector( '.hierarchy-guard-failed' ) ).toBe(
			null
		);
	} );

	it( 'moves the tab stop to the closed row when its subpages go', async () => {
		const tree = bindTree( document, fetchChildren );
		await tree.toggle( row( 4 ) );
		await tree.toggle( row( 5 ) );
		row( 6 ).focus();
		row( 6 ).blur();
		expect( tabStops() ).toEqual( [ 'post-6' ] );

		tree.toggle( row( 4 ) );

		expect( tabStops() ).toEqual( [ 'post-4' ] );
	} );

	it( 'leaves a tab stop after Collapse all', async () => {
		const tree = bindTree( document, fetchChildren );
		await tree.toggle( row( 9 ) );
		row( 1003 ).focus();
		row( 1003 ).blur();

		tree.collapseAll();

		expect( tabStops() ).toEqual( [ 'post-9' ] );
	} );

	it( 'moves to the first and last rows with Home and End', () => {
		bindTree( document, fetchChildren );
		row( 8 ).focus();

		press( row( 8 ), 'End' );
		expect( document.activeElement ).toBe( row( 9 ) );

		press( row( 9 ), 'Home' );
		expect( document.activeElement ).toBe( row( 4 ) );
	} );

	it( 'moves between rows from the Show more button', async () => {
		const tree = bindTree( document, fetchChildren );
		await tree.toggle( row( 9 ) );
		const more = document.querySelector( '.hierarchy-guard-more button' );
		more.focus();

		press( more, 'ArrowUp' );
		expect( document.activeElement ).toBe( row( 1049 ) );

		document.querySelector( '.hierarchy-guard-more button' ).focus();
		press(
			document.querySelector( '.hierarchy-guard-more button' ),
			'ArrowLeft'
		);
		expect( document.activeElement ).toBe( row( 9 ) );
	} );

	it( 'says how many are shown only when some are held back', async () => {
		const tree = bindTree( document, fetchChildren );
		speak.mockClear();

		await tree.toggle( row( 4 ) );
		expect( speak ).not.toHaveBeenCalled();

		await tree.toggle( row( 9 ) );
		expect( speak ).toHaveBeenCalledWith(
			'50 of 128 subpages shown',
			'polite'
		);
	} );

	it( 'points a closed toggle toward the title right to left', () => {
		setLocaleData( { 'text direction\u0004ltr': [ 'rtl' ] } );
		try {
			bindTree( document, fetchChildren );

			expect(
				row( 4 ).querySelector( '.hierarchy-guard-toggle .dashicons' )
					.className
			).toBe( 'dashicons dashicons-arrow-left-alt2' );
		} finally {
			setLocaleData( { 'text direction\u0004ltr': [ 'ltr' ] } );
		}
	} );

	it( 'shows a row once whatever the clicks while it loads', async () => {
		let answer;
		const load = vi.fn(
			() =>
				new Promise( ( resolve ) => {
					answer = resolve;
				} )
		);
		const tree = bindTree( document, load );

		const opening = tree.toggle( row( 4 ) );
		tree.toggle( row( 4 ) );
		answer( page( [ child( 5, 'About' ) ], 1 ) );
		await opening;
		expect( rows().map( ( item ) => item.id ) ).toEqual( [
			'post-4',
			'post-8',
			'post-9',
		] );

		const again = tree.toggle( row( 4 ) );
		tree.toggle( row( 4 ) );
		const third = tree.toggle( row( 4 ) );
		answer( page( [ child( 5, 'About' ) ], 1 ) );
		await Promise.all( [ again, third ] );
		expect( rows().map( ( item ) => item.id ) ).toEqual( [
			'post-4',
			'post-5',
			'post-8',
			'post-9',
		] );
		expect( load ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'gives a loaded page the user may not edit no toggle', async () => {
		const tree = bindTree( document, () =>
			Promise.resolve(
				page(
					[
						child( 5, 'About', {
							children_count: 4,
							edit_link: null,
						} ),
					],
					1
				)
			)
		);

		await tree.toggle( row( 4 ) );

		expect( row( 5 ).hasAttribute( 'aria-expanded' ) ).toBe( false );
		expect( row( 5 ).querySelector( '.hierarchy-guard-toggle' ) ).toBe(
			null
		);
		expect(
			row( 5 ).querySelector( '.hierarchy-guard-cell' ).textContent
		).toBe( '4 subpages' );
	} );

	it( 'still finds the toggles when the Hierarchy column is hidden', () => {
		document
			.querySelectorAll( '.column-hierarchy_guard' )
			.forEach( ( cell ) => cell.classList.add( 'hidden' ) );

		bindTree( document, fetchChildren );

		expect(
			row( 4 ).querySelector( '.column-title .hierarchy-guard-toggle' )
		).not.toBe( null );
		expect( row( 4 ).getAttribute( 'aria-expanded' ) ).toBe( 'false' );
	} );

	it( 'keeps the levels the server opened, with their Show more', () => {
		mountScreen(
			coreRow( {
				id: 9,
				title: 'Locations',
				children: 128,
				open: 1,
				extra: '<span aria-hidden="true"> · </span><a class="hierarchy-guard-more" href="edit.php?hierarchy_guard_children_page=2" data-page="2">Show more</a>',
			} ) +
				coreRow( {
					id: 11,
					title: 'Amsterdam',
					level: 1,
					parent: 9,
					setsize: 128,
				} ) +
				coreRow( { id: 8, title: 'Contact', position: 2 } )
		);
		const load = vi.fn( () => Promise.resolve( page( [], 128 ) ) );

		bindTree( document, load );

		expect( row( 9 ).getAttribute( 'aria-expanded' ) ).toBe( 'true' );
		expect( row( 11 ).getAttribute( 'aria-level' ) ).toBe( '2' );
		expect(
			row( 9 ).querySelector( '.hierarchy-guard-cell' ).textContent
		).toBe( '128 subpages' );
		const more = rows()[ 2 ];
		expect( more.textContent ).toBe(
			'Show more · 1 of 128 subpages shown'
		);
		more.querySelector( 'button' ).click();
		expect( load ).toHaveBeenCalledWith( 9, 2 );
	} );

	it( 'puts “Back to the tree” beside a search’s heading', () => {
		document.body.innerHTML =
			'<div class="wrap"><h1>Pages</h1><span class="subtitle">Search results for: <strong>team</strong></span><span class="hierarchy-guard-back"><a href="tree">Back to the tree</a></span></div>';

		expect( bindTree( document, fetchChildren ) ).toBe( null );

		expect( document.querySelector( '.subtitle' ).textContent ).toBe(
			'Search results for: team · Back to the tree'
		);
		expect( document.querySelector( '.hierarchy-guard-back' ) ).toBe(
			null
		);
	} );

	it( 'does nothing outside the Tree view', () => {
		document.body.innerHTML =
			'<table><tbody id="the-list"><tr id="post-4"><td>Company</td></tr></tbody></table>';

		expect( bindTree( document, fetchChildren ) ).toBe( null );
		expect( document.querySelector( 'table' ).hasAttribute( 'role' ) ).toBe(
			false
		);
	} );
} );
