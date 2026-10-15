/**
 * What a Delete on the Redirects tab asks, and what Cancel does.
 */
import { describe, expect, it, vi } from 'vitest';
import { bulkQuestion, guard, linkQuestion } from '../confirm';

const ONE = 'Delete the redirect from /about/team/? This can’t be undone.';
const MANY = 'Delete the selected redirects? This can’t be undone.';

/**
 * A stand-in element with attributes.
 *
 * @param {Object} attrs Attributes.
 * @return {Object} Element.
 */
const element = ( attrs ) => ( {
	hasAttribute: ( key ) => key in attrs,
	getAttribute: ( key ) => ( key in attrs ? attrs[ key ] : null ),
} );

/**
 * A stand-in of the table's form.
 *
 * @param {Object} options          The form's state.
 * @param {string} options.action   The top selector's value.
 * @param {string} options.action2  The bottom selector's value.
 * @param {number} options.ticked   How many rows are ticked.
 * @param {string} options.question The form's own question.
 * @return {Object} Form.
 */
const form = ( {
	action = '-1',
	action2 = '-1',
	ticked = 0,
	question = MANY,
} ) => {
	const boxes = Array.from( { length: ticked }, ( _, i ) =>
		element( {
			'data-hierarchy-guard-confirm-one': `Delete the redirect from /row-${ i }/? This can’t be undone.`,
		} )
	);
	return {
		...element( { 'data-hierarchy-guard-confirm': question } ),
		querySelector: ( selector ) =>
			( {
				'select[name="action"]': { value: action },
				'select[name="action2"]': { value: action2 },
			} )[ selector ] || null,
		querySelectorAll: () => boxes,
	};
};

describe( 'a Delete link', () => {
	it( 'asks the question it carries', () => {
		const link = element( { 'data-hierarchy-guard-confirm': ONE } );
		const target = { closest: () => link };
		expect( linkQuestion( target ) ).toBe( ONE );
	} );

	it( 'asks nothing for any other click', () => {
		expect( linkQuestion( { closest: () => null } ) ).toBe( null );
		expect( linkQuestion( null ) ).toBe( null );
		expect( linkQuestion( {} ) ).toBe( null );
	} );
} );

describe( 'a bulk Delete', () => {
	it( 'asks once for the selection, from either selector', () => {
		expect(
			bulkQuestion( form( { action: 'delete', ticked: 3 } ), {
				id: 'doaction',
			} )
		).toBe( MANY );
		expect(
			bulkQuestion( form( { action2: 'delete', ticked: 2 } ), {
				id: 'doaction2',
			} )
		).toBe( MANY );
	} );

	it( 'asks about the row when one is ticked', () => {
		expect(
			bulkQuestion( form( { action: 'delete', ticked: 1 } ), {
				id: 'doaction',
			} )
		).toBe( 'Delete the redirect from /row-0/? This can’t be undone.' );
	} );

	it( 'asks whatever submits the form: Enter in the search or the page number too', () => {
		const ticked = form( { action: 'delete', ticked: 2 } );
		expect( bulkQuestion( ticked, { id: 'search-submit' } ) ).toBe( MANY );
		expect( bulkQuestion( ticked, null ) ).toBe( MANY );
		expect( bulkQuestion( ticked, undefined ) ).toBe( MANY );
	} );

	it( 'reads the action as the server does: the top selector, else the bottom one', () => {
		expect(
			bulkQuestion( form( { action2: 'delete', ticked: 2 } ), {
				id: 'doaction',
			} )
		).toBe( MANY );
		expect(
			bulkQuestion(
				form( { action: '-1', action2: 'delete', ticked: 2 } ),
				{
					id: 'search-submit',
				}
			)
		).toBe( MANY );
		expect(
			bulkQuestion(
				form( { action: 'other', action2: 'delete', ticked: 2 } ),
				{ id: 'doaction2' }
			)
		).toBe( null );
	} );

	it( 'asks nothing for Filter, another action or no rows', () => {
		const ticked = form( { action: 'delete', ticked: 2 } );
		expect(
			bulkQuestion( ticked, {
				id: 'hierarchy-guard-filter',
				name: 'filter_action',
			} )
		).toBe( null );
		expect(
			bulkQuestion( form( { action: 'delete' } ), { id: 'doaction' } )
		).toBe( null );
		expect(
			bulkQuestion( form( { action: 'other', ticked: 2 } ), {
				id: 'doaction',
			} )
		).toBe( null );
		expect( bulkQuestion( element( {} ), { id: 'doaction' } ) ).toBe(
			null
		);
		expect( bulkQuestion( null, { id: 'doaction' } ) ).toBe( null );
	} );
} );

describe( 'the answer', () => {
	it( 'cancels the Delete on Cancel', () => {
		const event = { preventDefault: vi.fn() };
		const ask = vi.fn( () => false );
		expect( guard( event, ONE, ask ) ).toBe( false );
		expect( ask ).toHaveBeenCalledWith( ONE );
		expect( event.preventDefault ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'lets the Delete go on on OK', () => {
		const event = { preventDefault: vi.fn() };
		expect( guard( event, ONE, () => true ) ).toBe( true );
		expect( event.preventDefault ).not.toHaveBeenCalled();
	} );

	it( 'asks nothing and stops nothing without a question', () => {
		const event = { preventDefault: vi.fn() };
		const ask = vi.fn();
		expect( guard( event, null, ask ) ).toBe( true );
		expect( ask ).not.toHaveBeenCalled();
		expect( event.preventDefault ).not.toHaveBeenCalled();
	} );
} );
