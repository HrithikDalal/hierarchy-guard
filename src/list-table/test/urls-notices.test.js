/**
 * The URLs notices on the list screen once a later change replaces theirs.
 */
import { describe, expect, it } from 'vitest';
import { retireUrlsNotices } from '../urls-notices';

/**
 * A stand-in node that can be removed from its parent's children.
 *
 * @param {string} name   What it is, for the assertions.
 * @param {Object} attrs  Its attributes.
 * @param {Array}  parent Its parent's children.
 * @return {Object} Node.
 */
const node = ( name, attrs, parent ) => {
	const item = {
		name,
		getAttribute: ( key ) => ( key in attrs ? attrs[ key ] : null ),
		get nextElementSibling() {
			return parent[ parent.indexOf( item ) + 1 ] || null;
		},
		remove: () => parent.splice( parent.indexOf( item ), 1 ),
	};
	parent.push( item );
	return item;
};

/**
 * A notice's children: its View redirects link or button, the divider, and
 * the settings link.
 *
 * @param {string} view Tag of the View link: a, or button once converted.
 * @return {Array} Children.
 */
const notice = ( view ) => {
	const children = [];
	node( view, { class: 'hierarchy-guard-view-redirects' }, children );
	node( 'span', { 'aria-hidden': 'true' }, children );
	node( 'a', { href: '/settings' }, children );
	return children;
};

describe( 'the URLs notices a later change replaced', () => {
	it( 'lose the links to their redirects and keep what they say', () => {
		const server = notice( 'button' );
		const earlier = notice( 'button' );
		const doc = {
			querySelectorAll: ( selector ) => {
				expect( selector ).toBe(
					'.hierarchy-guard-urls .hierarchy-guard-view-redirects'
				);
				return [ server[ 0 ], earlier[ 0 ] ];
			},
		};

		retireUrlsNotices( doc );

		expect( server.map( ( item ) => item.name ) ).toEqual( [ 'a' ] );
		expect( earlier.map( ( item ) => item.name ) ).toEqual( [ 'a' ] );
		expect( server[ 0 ].getAttribute( 'href' ) ).toBe( '/settings' );
	} );

	it( 'lose a CSV link not yet turned into a button, and a link with no divider after it', () => {
		const csv = notice( 'a' );
		const alone = [];
		node( 'button', { class: 'hierarchy-guard-view-redirects' }, alone );
		const doc = { querySelectorAll: () => [ csv[ 0 ], alone[ 0 ] ] };

		retireUrlsNotices( doc );

		expect( csv.map( ( item ) => item.name ) ).toEqual( [ 'a' ] );
		expect( alone ).toEqual( [] );
	} );
} );
