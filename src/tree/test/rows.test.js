// @vitest-environment happy-dom
/**
 * The rows the Tree view adds: a loaded subpage reads as one core prints.
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { childRow, placeUrl, safeHref, statusRow } from '../rows';
import { child, coreRow, mountScreen } from './fixture';

const PLACE =
	'https://example.org/wp-admin/edit.php?post_type=page&hierarchy_guard_view=tree&paged=2';
const context = ( overrides = {} ) => ( {
	level: 1,
	parent: 4,
	page: 1,
	position: 2,
	setsize: 3,
	place: PLACE,
	type: 'page',
	...overrides,
} );

describe( 'a loaded subpage’s row', () => {
	let template;

	beforeEach( () => {
		mountScreen( coreRow( { id: 4, title: 'Company', children: 3 } ) );
		template = document.getElementById( 'post-4' );
	} );

	it( 'carries core’s row classes, with its level', () => {
		const row = childRow(
			template,
			child( 5, 'About', {
				status: 'private',
				locked_by: 'Priya',
				classes: [
					'iedit',
					'author-self',
					'post-5',
					'type-page',
					'status-private',
					'post-password-protected',
					'hentry',
				],
			} ),
			context()
		);

		expect( row.className ).toBe(
			'iedit author-self post-5 type-page status-private post-password-protected hentry level-1 hierarchy-guard-loaded wp-locked'
		);
	} );

	it( 'has the list’s columns, in core’s cells, hidden ones hidden', () => {
		const row = childRow( template, child( 5, 'About' ), context() );

		expect( row.id ).toBe( 'post-5' );
		expect(
			Array.from( row.children ).map( ( cell ) => [
				cell.tagName,
				cell.className,
				cell.dataset.colname,
			] )
		).toEqual( [
			[
				'TH',
				'title column-title has-row-actions column-primary page-title',
				'Title',
			],
			[ 'TD', 'hierarchy_guard column-hierarchy_guard', 'Hierarchy' ],
			[ 'TD', 'author column-author', 'Author' ],
			[ 'TD', 'comments column-comments hidden', 'Comments' ],
			[ 'TD', 'date column-date', 'Date' ],
		] );
		expect( row.children[ 0 ].getAttribute( 'scope' ) ).toBe( 'row' );
		expect( row.children[ 2 ].textContent ).toBe( 'Priya' );
		expect( row.children[ 4 ].innerHTML ).toBe(
			'Published<br>2026/07/19 at 9:00 am'
		);
	} );

	it( 'carries Edit, Trash, View and View subpages, and the title links to Edit', () => {
		const row = childRow(
			template,
			child( 5, 'About', { children_count: 4 } ),
			context()
		);

		const title = row.querySelector( 'strong .row-title' );
		expect( title.getAttribute( 'href' ) ).toBe(
			'https://example.org/wp-admin/post.php?post=5&action=edit'
		);
		expect(
			Array.from( row.querySelectorAll( '.row-actions > span' ) ).map(
				( item ) => [
					item.className,
					item.textContent,
					item.firstChild.getAttribute( 'aria-label' ),
				]
			)
		).toEqual( [
			[ 'edit', 'Edit | ', 'Edit “About”' ],
			[ 'trash', 'Trash | ', 'Move “About” to the Trash' ],
			[ 'view', 'View | ', 'View “About”' ],
			[
				'hierarchy_guard_subpages',
				'View subpages',
				'View subpages of “About”',
			],
		] );
		expect(
			row
				.querySelector( '.hierarchy_guard_subpages a' )
				.getAttribute( 'href' )
		).toBe(
			'https://example.org/wp-admin/edit.php?post_type=page&hierarchy_guard_under=5'
		);
		expect( row.querySelector( '.trash a' ).className ).toBe(
			'submitdelete'
		);
		expect( row.querySelector( 'button.toggle-row' ).textContent ).toBe(
			'Show more details'
		);
	} );

	it( 'links the subpage count, as View subpages, to the list on the same admin screen', () => {
		const row = childRow(
			template,
			child( 5, 'About', { children_count: 2 } ),
			context()
		);

		expect(
			row
				.querySelector( '.hierarchy-guard-cell a' )
				.getAttribute( 'href' )
		).toBe(
			'https://example.org/wp-admin/edit.php?post_type=page&hierarchy_guard_under=5'
		);
	} );

	it( 'links only to web addresses and paths, as esc_url() leaves core’s links', () => {
		const row = childRow(
			template,
			child( 5, 'About', {
				edit_link: 'javascript:alert(1)',
				view_link: ' JaVa\tScRiPt:alert(1)',
				trash_link: 'data:text/html,<b>x</b>',
			} ),
			context()
		);

		expect(
			Array.from( row.querySelectorAll( '.column-title a' ) ).map(
				( link ) => link.getAttribute( 'href' )
			)
		).toEqual( [ '', '', '', '' ] );
		expect( safeHref( '/about/team/' ) ).toBe( '/about/team/' );
		expect( safeHref( 'edit.php?post_type=page&x=a:b' ) ).toBe(
			'edit.php?post_type=page&x=a:b'
		);
		expect( safeHref( 'HTTPS://example.org/' ) ).toBe(
			'HTTPS://example.org/'
		);
		expect( safeHref( 'mailto:a@example.org' ) ).toBe( '' );
	} );

	it( 'copies WordPress 6.6’s title cell, a plain cell with no name', () => {
		mountScreen(
			coreRow( { id: 4, title: 'Company', children: 3, wp66: true } )
		);

		const cell = childRow(
			document.getElementById( 'post-4' ),
			child( 5, 'About' ),
			context( { parentTitle: 'Company' } )
		).children[ 0 ];

		expect( cell.tagName ).toBe( 'TD' );
		expect( cell.hasAttribute( 'scope' ) ).toBe( false );
		expect( cell.hasAttribute( 'aria-label' ) ).toBe( false );
		expect( cell.querySelector( '.row-title' ).textContent ).toBe(
			'About'
		);
	} );

	it( 'sends the trash back to the tree, opened at the parent, at the subpage’s page', () => {
		const row = childRow(
			template,
			child( 60, 'Late' ),
			context( { page: 2 } )
		);

		const href = new window.URL(
			row.querySelector( '.trash a' ).getAttribute( 'href' )
		);
		expect( href.searchParams.get( '_wpnonce' ) ).toBe( 'abc' );
		expect( href.searchParams.get( '_wp_http_referer' ) ).toBe(
			PLACE + '&hierarchy_guard_open=4&hierarchy_guard_children_page=2'
		);
	} );

	it( 'puts the subpage count in the Hierarchy column, with the row’s place', () => {
		const row = childRow(
			template,
			child( 5, 'About', {
				children_count: 1000,
				children_capped: true,
			} ),
			context( { position: null, setsize: null } )
		);

		const cell = row.querySelector( '.hierarchy-guard-cell' );
		expect( cell.textContent ).toBe( '1,000+ subpages' );
		expect( cell.querySelector( 'a' ).getAttribute( 'aria-label' ) ).toBe(
			'View the 1,000+ subpages of “About”'
		);
		expect( { ...cell.dataset } ).toEqual( {
			id: '5',
			children: '1001',
			status: 'publish',
			level: '1',
			parent: '4',
			page: '1',
		} );
	} );

	it( 'shows a draft’s status as text and a page the user may not edit as plain text', () => {
		const row = childRow(
			template,
			child( 7, 'Press', {
				status: 'draft',
				status_label: 'Draft',
				edit_link: null,
				view_link: null,
				trash_link: null,
				author: '',
			} ),
			context()
		);

		expect( row.querySelector( 'strong' ).textContent ).toBe(
			'Press — Draft'
		);
		expect( row.querySelector( '.row-title' ) ).toBe( null );
		expect( row.querySelector( '.row-actions' ) ).toBe( null );
		expect( row.children[ 1 ].textContent ).toBe( '—No subpages' );
		expect( row.children[ 2 ].textContent ).toBe( '—(no author)' );
	} );

	it( 'shows a title with markup or entities as text', () => {
		const title = '<img src=x onerror=alert(1)> Tom & “Jerry”';
		const row = childRow(
			template,
			child( 8, title, { children_count: 2 } ),
			context()
		);

		expect( row.querySelector( '.row-title' ).textContent ).toBe( title );
		expect( row.querySelector( 'img' ) ).toBe( null );
		expect(
			row.querySelector( '.edit a' ).getAttribute( 'aria-label' )
		).toBe( `Edit “${ title }”` );
	} );

	it( 'names the row by its title as WordPress 7.1 does, and says whose child it is', () => {
		const row = childRow(
			template,
			child( 5, 'About' ),
			context( { parentTitle: 'Company' } )
		);

		expect( row.children[ 0 ].getAttribute( 'aria-label' ) ).toBe(
			'About'
		);
		const title = row.querySelector( '.row-title' );
		expect( title.getAttribute( 'aria-describedby' ) ).toBe(
			'post-hierarchy-5'
		);
		const description = row.querySelector( '#post-hierarchy-5' );
		expect( description.className ).toBe( 'hidden' );
		expect( description.textContent ).toBe( 'Child of Company' );

		template.children[ 0 ].removeAttribute( 'aria-label' );
		expect(
			childRow(
				template,
				child( 6, 'History' ),
				context()
			).children[ 0 ].hasAttribute( 'aria-label' )
		).toBe( false );
	} );

	it( 'carries core’s lock notice, and who holds the lock', () => {
		const free = childRow( template, child( 5, 'About' ), context() );
		expect( free.querySelector( '.locked-info' ).innerHTML ).toBe(
			'<span class="locked-avatar"></span> <span class="locked-text"></span>'
		);
		expect( free.classList.contains( 'wp-locked' ) ).toBe( false );

		const held = childRow(
			template,
			child( 6, 'History', { locked_by: 'Priya' } ),
			context()
		);
		expect( held.classList.contains( 'wp-locked' ) ).toBe( true );
		expect( held.querySelector( '.locked-text' ).textContent ).toBe(
			'Priya is currently editing'
		);
	} );

	it( 'lists core’s post states after the title', () => {
		const row = childRow(
			template,
			child( 3, 'Privacy', {
				status: 'draft',
				states: [ 'Draft', 'Privacy Policy Page' ],
			} ),
			context()
		);

		expect(
			Array.from( row.querySelectorAll( 'strong .post-state' ) ).map(
				( state ) => state.textContent
			)
		).toEqual( [ 'Draft, ', 'Privacy Policy Page' ] );
		expect( row.querySelector( 'strong' ).textContent ).toBe(
			'Privacy — Draft, Privacy Policy Page'
		);
	} );

	it( 'shows a missed schedule in core’s error style', () => {
		const row = childRow(
			template,
			child( 9, 'Launch', {
				status: 'future',
				date: {
					label: 'Missed schedule',
					date: '2026/01/01 at 9:00 am',
					missed: true,
				},
			} ),
			context()
		);

		expect( row.children[ 4 ].innerHTML ).toBe(
			'<strong class="error-message">Missed schedule</strong><br>2026/01/01 at 9:00 am'
		);
	} );

	it( 'has no “Show more details” without row actions, as core prints none', () => {
		const row = childRow(
			template,
			child( 7, 'Press', {
				edit_link: null,
				view_link: null,
				trash_link: null,
			} ),
			context()
		);

		expect( row.querySelector( '.row-actions' ) ).toBe( null );
		expect( row.querySelector( '.toggle-row' ) ).toBe( null );
	} );

	it( 'says whose child it is on a page the user may not edit', () => {
		const row = childRow(
			template,
			child( 7, 'Press', {
				edit_link: null,
				view_link: null,
				trash_link: null,
			} ),
			context( { parentTitle: 'Company' } )
		);

		const description = row.querySelector( '#post-hierarchy-7' );
		expect( description.className ).toBe( 'screen-reader-text' );
		expect( description.textContent ).toBe( ' (Child of Company)' );
		expect( description.parentElement.parentElement.tagName ).toBe(
			'STRONG'
		);
		expect( row.querySelector( '[aria-describedby]' ) ).toBe( null );
		expect( row.querySelector( 'strong' ).textContent ).toBe(
			'Press (Child of Company)'
		);
	} );

	it( 'prints no place among siblings that the caller left out', () => {
		const place = context();
		delete place.position;
		delete place.setsize;
		const row = childRow( template, child( 5, 'About' ), place );

		const cell = row.querySelector( '.hierarchy-guard-cell' );
		expect( cell.hasAttribute( 'data-position' ) ).toBe( false );
		expect( cell.hasAttribute( 'data-setsize' ) ).toBe( false );
		expect(
			childRow(
				template,
				child( 6, 'History' ),
				context()
			).querySelector( '.hierarchy-guard-cell' ).dataset
		).toMatchObject( { position: '2', setsize: '3' } );
	} );

	it( 'leaves another plugin’s column in its place, empty', () => {
		const seo = document.createElement( 'td' );
		seo.className = 'seo column-seo';
		seo.dataset.colname = 'SEO';
		seo.textContent = 'Good';
		const plain = document.createElement( 'td' );
		plain.className = 'plain';
		plain.textContent = 'Note';
		template.append( seo, plain );
		const row = childRow( template, child( 5, 'About' ), context() );

		expect(
			Array.from( row.children )
				.slice( 5 )
				.map( ( cell ) => [
					cell.className,
					cell.dataset.colname,
					cell.innerHTML,
				] )
		).toEqual( [
			[ 'seo column-seo', 'SEO', '' ],
			[ 'plain', undefined, '' ],
		] );
	} );

	it( 'keeps the tree’s place when nothing is opened', () => {
		expect( placeUrl( PLACE, 0 ) ).toBe( PLACE );
		expect( placeUrl( PLACE, 9 ) ).toBe(
			PLACE + '&hierarchy_guard_open=9'
		);
	} );
} );

describe( 'a status row under an opened page', () => {
	it( 'is one cell across the visible columns, at its subpages’ level', () => {
		mountScreen( coreRow( { id: 4, title: 'Company', children: 3 } ) );
		const { row, cell } = statusRow(
			document.getElementById( 'post-4' ),
			4,
			1
		);

		expect( row.className ).toBe( 'level-1 hierarchy-guard-status' );
		expect( row.dataset.parent ).toBe( '4' );
		expect( Array.from( row.children ) ).toEqual( [ cell ] );
		expect( cell.tagName ).toBe( 'TD' );
		expect( cell.className ).toBe( 'colspanchange' );
		// Five columns, one hidden in Screen Options.
		expect( cell.colSpan ).toBe( 4 );
	} );
} );
