// @vitest-environment happy-dom
/**
 * The tree grid on core's list table.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';
import apiFetch from '@wordpress/api-fetch';
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
vi.mock( '@wordpress/api-fetch', () => ( { default: vi.fn() } ) );

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
							number < 3,
							false,
							( number - 1 ) * 50
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
			[
				'2',
				'1',
				'3',
				'iedit author-other post-5 type-page status-publish hentry level-1 hierarchy-guard-loaded',
			],
			[
				'2',
				'2',
				'3',
				'iedit author-other post-6 type-page status-publish hentry level-1 hierarchy-guard-loaded',
			],
			[
				'2',
				'3',
				'3',
				'iedit author-other post-7 type-page status-draft hentry level-1 hierarchy-guard-loaded',
			],
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
		expect( more.firstElementChild.colSpan ).toBe( 4 );
		expect(
			more.querySelector( 'button' ).getAttribute( 'aria-label' )
		).toBe( 'Show more subpages of “Locations”' );

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
		expect(
			rows()[ 1 ].querySelector( 'button' ).getAttribute( 'aria-label' )
		).toBe( 'Try again loading subpages of “Company”' );
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
		const failure = document.querySelector( '.hierarchy-guard-failed' );
		// Where the failure is, 50 rows below the toggle: the keyboard stays by it.
		expect( document.activeElement ).toBe(
			failure.querySelector( 'button' )
		);
		expect( tabStops() ).toEqual( [ '' ] );
		expect( failure.tabIndex ).toBe( 0 );
		failure.querySelector( 'button' ).click();
		await settle();
		expect( fetchChildren ).toHaveBeenLastCalledWith( 9, 2 );
		expect( row( 1050 ) ).not.toBe( null );
		expect( document.querySelector( '.hierarchy-guard-failed' ) ).toBe(
			null
		);
	} );

	it( 'leaves the status rows out of the tree’s levels and places for assistive technology', async () => {
		const tree = bindTree( document, fetchChildren );

		await tree.toggle( row( 9 ) );

		const more = document.querySelector( '.hierarchy-guard-more' );
		expect( more.hasAttribute( 'aria-level' ) ).toBe( false );
		expect( more.hasAttribute( 'aria-posinset' ) ).toBe( false );
		expect( more.hasAttribute( 'aria-setsize' ) ).toBe( false );
		expect( more.classList.contains( 'level-1' ) ).toBe( true );
		// It still sits under its row: closing the row takes it away.
		tree.toggle( row( 9 ) );
		expect( document.querySelector( '.hierarchy-guard-more' ) ).toBe(
			null
		);
	} );

	it( 'names an untitled page “(no title)”, as the server does, without the excerpt WordPress 7.1 adds', async () => {
		mountScreen(
			coreRow( {
				id: 9,
				title: '(no title)',
				excerpt: 'Body text of the page',
				children: 128,
			} )
		);
		const tree = bindTree( document, fetchChildren );

		const button = row( 9 ).querySelector(
			'button.hierarchy-guard-toggle'
		);
		expect( button.getAttribute( 'aria-label' ) ).toBe(
			'Show the subpages of “(no title)”'
		);
		await tree.toggle( row( 9 ) );
		expect( row( 1000 ).querySelector( '.hidden' ).textContent ).toBe(
			'Child of (no title)'
		);
		expect(
			document
				.querySelector( '.hierarchy-guard-more button' )
				.getAttribute( 'aria-label' )
		).toBe( 'Show more subpages of “(no title)”' );
	} );

	it( 'leaves the keyboard where the user took it when a later page fails, with Tab on Try again', async () => {
		const tree = bindTree( document, fetchChildren );
		await tree.toggle( row( 9 ) );
		let fail;
		fetchChildren.mockImplementationOnce(
			() =>
				new Promise( ( resolve, reject ) => {
					fail = reject;
				} )
		);
		document.querySelector( '.hierarchy-guard-more button' ).click();
		const toggleButton = row( 9 ).querySelector(
			'.hierarchy-guard-toggle'
		);
		// Back up to the opened row while the next page loads.
		toggleButton.focus();

		fail( new Error() );
		await settle();

		expect( document.activeElement ).toBe( toggleButton );
		expect(
			document.querySelector( '.hierarchy-guard-failed' ).tabIndex
		).toBe( 0 );
		expect( row( 9 ).tabIndex ).toBe( -1 );
	} );

	it( 'leaves the keyboard alone when the user clicked away from Show more before it failed', async () => {
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
		// A click on the page's heading, which takes focus nowhere.
		more.blur();
		expect( document.activeElement ).toBe( document.body );

		fail( new Error() );
		await settle();

		expect( document.activeElement ).toBe( document.body );
		expect(
			document.querySelector( '.hierarchy-guard-failed' ).tabIndex
		).toBe( 0 );
	} );

	it( 'moves the keyboard to Try again when nothing had it as Show more was pressed', async () => {
		const tree = bindTree( document, fetchChildren );
		await tree.toggle( row( 9 ) );
		let fail;
		fetchChildren.mockImplementationOnce(
			() =>
				new Promise( ( resolve, reject ) => {
					fail = reject;
				} )
		);
		// A browser that doesn't focus a button it clicks, as Safari does.
		document.activeElement.blur();
		document.querySelector( '.hierarchy-guard-more button' ).click();

		fail( new Error() );
		await settle();

		expect( document.activeElement ).toBe(
			document.querySelector( '.hierarchy-guard-failed button' )
		);
	} );

	it( 'counts a status row’s level from 0, as its class and the cells do', async () => {
		const tree = bindTree( document, fetchChildren );

		await tree.toggle( row( 9 ) );

		const more = document.querySelector( '.hierarchy-guard-more' );
		expect( more.dataset.level ).toBe( '1' );
		expect( more.classList.contains( 'level-1' ) ).toBe( true );
		expect(
			row( 1000 ).querySelector( '.hierarchy-guard-cell' ).dataset.level
		).toBe( '1' );
		// Left from its button still goes to the opened row.
		more.querySelector( 'button' ).focus();
		press( more.querySelector( 'button' ), 'ArrowLeft' );
		expect( document.activeElement ).toBe( row( 9 ) );
	} );

	it( 'says a subpage of an untitled page is “Child of” no one, as core does', async () => {
		const tree = bindTree( document, () =>
			Promise.resolve( {
				...page( [ child( 5, 'About' ) ], 1 ),
				untitled: true,
			} )
		);

		await tree.toggle( row( 4 ) );

		expect( row( 5 ).querySelector( '.hidden' ).textContent ).toBe(
			'Child of '
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

	it( 'moves down from the Show more button to the next row', async () => {
		mountScreen(
			coreRow( { id: 9, title: 'Locations', children: 128 } ) +
				coreRow( { id: 8, title: 'Contact', position: 2 } )
		);
		const tree = bindTree( document, fetchChildren );
		await tree.toggle( row( 9 ) );
		const more = document.querySelector( '.hierarchy-guard-more button' );
		more.focus();

		press( more, 'ArrowDown' );

		expect( document.activeElement ).toBe( row( 8 ) );
	} );

	it( 'leaves keys with Alt, Control or Command to the browser', () => {
		bindTree( document, fetchChildren );
		row( 8 ).focus();

		const back = new window.KeyboardEvent( 'keydown', {
			key: 'ArrowLeft',
			altKey: true,
			bubbles: true,
			cancelable: true,
		} );
		row( 8 ).dispatchEvent( back );
		const end = new window.KeyboardEvent( 'keydown', {
			key: 'End',
			ctrlKey: true,
			bubbles: true,
			cancelable: true,
		} );
		row( 8 ).dispatchEvent( end );

		expect( back.defaultPrevented ).toBe( false );
		expect( end.defaultPrevented ).toBe( false );
		expect( document.activeElement ).toBe( row( 8 ) );
	} );

	it( 'opens a row afresh when it was closed and opened while Show more loaded', async () => {
		const tree = bindTree( document, fetchChildren );
		await tree.toggle( row( 9 ) );
		const answer = fetchChildren.getMockImplementation();
		let release;
		fetchChildren.mockImplementationOnce(
			( id, number ) =>
				new Promise( ( resolve ) => {
					release = () => resolve( answer( id, number ) );
				} )
		);
		document.querySelector( '.hierarchy-guard-more button' ).click();

		tree.toggle( row( 9 ) );
		const reopening = tree.toggle( row( 9 ) );
		release();
		await reopening;

		expect( fetchChildren ).toHaveBeenLastCalledWith( 9, 1 );
		expect( row( 9 ).nextElementSibling ).toBe( row( 1000 ) );
		expect( row( 1000 ).getAttribute( 'aria-posinset' ) ).toBe( '1' );
		expect( row( 1050 ) ).toBe( null );
		expect(
			document.querySelector( '.hierarchy-guard-more' ).textContent
		).toBe( 'Show more · 50 of 128 subpages shown' );
		expect( rows() ).toHaveLength( 54 );
	} );

	it( 'says a failure to a row closed and opened again while it loaded', async () => {
		let fail;
		const load = vi.fn(
			() =>
				new Promise( ( resolve, reject ) => {
					fail = reject;
				} )
		);
		const tree = bindTree( document, load );

		tree.toggle( row( 4 ) );
		tree.toggle( row( 4 ) );
		const reopening = tree.toggle( row( 4 ) );
		fail( new Error() );
		await reopening;

		expect( load ).toHaveBeenCalledTimes( 1 );
		expect( row( 4 ).getAttribute( 'aria-expanded' ) ).toBe( 'true' );
		expect(
			rows()[ 1 ].classList.contains( 'hierarchy-guard-failed' )
		).toBe( true );
		expect( rows()[ 1 ].textContent ).toBe(
			'The subpages couldn’t be loaded. Try again'
		);
		expect( rows() ).toHaveLength( 4 );
	} );

	it( 'hands the keyboard from the loading row to the first subpage', async () => {
		let answer;
		const load = vi.fn(
			() =>
				new Promise( ( resolve ) => {
					answer = resolve;
				} )
		);
		bindTree( document, load );
		row( 4 ).focus();

		press( row( 4 ), 'ArrowRight' );
		press( row( 4 ), 'ArrowRight' );
		expect( document.activeElement ).toBe( rows()[ 1 ] );
		answer( page( [ child( 5, 'About' ), child( 6, 'History' ) ], 2 ) );
		await settle();

		expect( document.activeElement ).toBe( row( 5 ) );
		expect( tabStops() ).toEqual( [ 'post-5' ] );
	} );

	it( 'shows each loaded row at the place the server gives it, as without JavaScript', async () => {
		// Team's sibling History is one the user may not read: it keeps its place, unseen.
		const tree = bindTree( document, () =>
			Promise.resolve(
				page( [ child( 6, 'Team', { position: 2, setsize: 2 } ) ], 2 )
			)
		);

		await tree.toggle( row( 4 ) );

		expect( row( 6 ).getAttribute( 'aria-posinset' ) ).toBe( '2' );
		expect( row( 6 ).getAttribute( 'aria-setsize' ) ).toBe( '2' );
		const cell = row( 6 ).querySelector( '.hierarchy-guard-cell' );
		expect( [ cell.dataset.position, cell.dataset.setsize ] ).toEqual( [
			'2',
			'2',
		] );
	} );

	it( 'reads a loaded title as core’s row reads the same title', async () => {
		mountScreen(
			coreRow( {
				id: 4,
				title: '&lt;b&gt;Bold&lt;/b&gt; &amp; Co',
				children: 1,
			} )
		);
		const tree = bindTree( document, () =>
			Promise.resolve( page( [ child( 5, '<b>Bold</b> & Co' ) ], 1 ) )
		);

		await tree.toggle( row( 4 ) );

		const title = ( id ) =>
			row( id ).querySelector( '.row-title' ).textContent;
		expect( title( 5 ) ).toBe( '<b>Bold</b> & Co' );
		expect( title( 5 ) ).toBe( title( 4 ) );
		expect( row( 5 ).querySelector( 'b' ) ).toBe( null );
	} );

	it( 'shows a row whose subpages have all gone as having none', async () => {
		// Core's row actions, "Trash | View subpages", as the list prints them.
		row( 4 ).querySelector( '.row-actions' ).innerHTML =
			'<span class="trash"><a href="post.php?post=4&amp;action=trash" class="submitdelete">Trash</a> | </span><span class="hierarchy_guard_subpages"><a href="edit.php?post_type=page&amp;hierarchy_guard_under=4">View subpages</a></span>';
		const tree = bindTree( document, () =>
			Promise.resolve( page( [], 0 ) )
		);
		row( 4 ).querySelector( '.hierarchy-guard-toggle' ).focus();

		await tree.toggle( row( 4 ) );

		expect( rows().map( ( item ) => item.id ) ).toEqual( [
			'post-4',
			'post-8',
			'post-9',
		] );
		expect( row( 4 ).hasAttribute( 'aria-expanded' ) ).toBe( false );
		expect( row( 4 ).querySelector( '.hierarchy-guard-toggle' ) ).toBe(
			null
		);
		const cell = row( 4 ).querySelector( '.hierarchy-guard-cell' );
		expect( cell.innerHTML ).toBe(
			'<span aria-hidden="true">—</span><span class="screen-reader-text">No subpages</span>'
		);
		expect( cell.dataset.children ).toBe( '0' );
		expect( row( 4 ).querySelector( '.row-actions' ).textContent ).toBe(
			'Trash'
		);
		expect( document.activeElement ).toBe( row( 4 ) );
		expect( tabStops() ).toEqual( [ 'post-4' ] );
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

	it( 'keeps a row open, with its toggle and count, and says so when the user can’t view its subpages', async () => {
		const tree = bindTree( document, () =>
			Promise.resolve( page( [], 3 ) )
		);
		const toggleButton = row( 4 ).querySelector(
			'.hierarchy-guard-toggle'
		);
		toggleButton.focus();

		await tree.toggle( row( 4 ) );

		expect( row( 4 ).getAttribute( 'aria-expanded' ) ).toBe( 'true' );
		expect( row( 4 ).querySelector( '.hierarchy-guard-toggle' ) ).toBe(
			toggleButton
		);
		const cell = row( 4 ).querySelector( '.hierarchy-guard-cell' );
		expect( cell.dataset.children ).toBe( '3' );
		expect( cell.textContent ).toBe( '3 subpages' );
		const notice = rows()[ 1 ];
		expect( notice.className ).toBe(
			'level-1 hierarchy-guard-status hierarchy-guard-hidden'
		);
		expect( notice.textContent ).toBe( 'You can’t view these subpages.' );
		expect( document.activeElement ).toBe( toggleButton );
	} );

	it( 'leads on past a page the user can’t view to the subpages they can', async () => {
		const load = vi.fn( ( id, number ) =>
			Promise.resolve(
				1 === number
					? page( [], 52, true )
					: page( [ child( 60, 'Open' ) ], 52, false, false, 50 )
			)
		);
		const tree = bindTree( document, load );

		await tree.toggle( row( 9 ) );

		expect( rows()[ 3 ].textContent ).toBe(
			'You can’t view these subpages.'
		);
		const more = document.querySelector( '.hierarchy-guard-more' );
		expect( more.textContent ).toBe( 'Show more · 0 of 52 subpages shown' );
		more.querySelector( 'button' ).click();
		await settle();
		expect( load ).toHaveBeenLastCalledWith( 9, 2 );
		expect( row( 60 ).getAttribute( 'aria-posinset' ) ).toBe( '51' );
		expect(
			document.querySelectorAll( '.hierarchy-guard-hidden' )
		).toHaveLength( 1 );
	} );

	it( 'says once that the user can’t view a row’s subpages, however many pages bring none', async () => {
		const load = vi.fn( ( id, number ) =>
			Promise.resolve( page( [], 151, number < 4 ) )
		);
		const tree = bindTree( document, load );

		await tree.toggle( row( 9 ) );
		document.querySelector( '.hierarchy-guard-more button' ).click();
		await settle();

		expect( load ).toHaveBeenLastCalledWith( 9, 2 );
		expect(
			document.querySelectorAll( '.hierarchy-guard-hidden' )
		).toHaveLength( 1 );
	} );

	it( 'gives an opened row its own notice, beside a subpage’s, and the keyboard goes to it', async () => {
		const load = vi.fn( ( id, number ) => {
			if ( 1000 === id ) {
				return Promise.resolve( page( [], 2 ) );
			}
			return Promise.resolve(
				1 === number
					? page(
							[ child( 1000, 'City 1', { children_count: 2 } ) ],
							51,
							true
						)
					: page( [], 51, false, false, 50 )
			);
		} );
		const tree = bindTree( document, load );
		await tree.toggle( row( 9 ) );
		await tree.toggle( row( 1000 ) );
		expect(
			document.querySelector( '.hierarchy-guard-hidden' ).dataset.parent
		).toBe( '1000' );

		const more = document.querySelector( '.hierarchy-guard-more button' );
		more.focus();
		more.click();
		await settle();

		const notices = Array.from(
			document.querySelectorAll( '.hierarchy-guard-hidden' )
		);
		expect( notices.map( ( item ) => item.dataset.parent ) ).toEqual( [
			'1000',
			'9',
		] );
		expect( document.activeElement ).toBe( notices[ 1 ] );
	} );

	it( 'keeps the row the server opened onto subpages the user can’t view, and says so in a row', () => {
		mountScreen(
			coreRow( {
				id: 9,
				title: 'Locations',
				children: 128,
				open: 1,
				extra: '<span aria-hidden="true"> · </span><span class="hierarchy-guard-hidden">You can’t view these subpages.</span><span aria-hidden="true"> · </span><a class="hierarchy-guard-more" href="edit.php?hierarchy_guard_children_page=2" data-page="2">Show more</a>',
			} ) + coreRow( { id: 8, title: 'Contact', position: 2 } )
		);

		bindTree( document, fetchChildren );

		expect( row( 9 ).getAttribute( 'aria-expanded' ) ).toBe( 'true' );
		expect(
			row( 9 ).querySelector( '.hierarchy-guard-cell' ).textContent
		).toBe( '128 subpages' );
		expect( rows().map( ( item ) => item.textContent ) ).toEqual( [
			expect.any( String ),
			'You can’t view these subpages.',
			'Show more · 0 of 128 subpages shown',
			expect.any( String ),
		] );
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

	it( 'loads a page of 50 subpages from the route, without the editor panel’s fields', async () => {
		apiFetch.mockResolvedValue( page( [ child( 5, 'About' ) ], 1 ) );
		const tree = bindTree( document );

		await tree.toggle( row( 4 ) );

		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/hierarchy-guard/v1/posts/4/hierarchy?children_page=1&children_per_page=50&tree=1',
		} );
		expect( row( 5 ) ).not.toBe( null );
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
