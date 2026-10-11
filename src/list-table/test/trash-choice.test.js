/**
 * What the trash dialog sends and shows.
 */
import { describe, expect, it } from 'vitest';
import { confirmedPolicy, plainTitle, showsDenied } from '../trash-choice';

const page = ( policy, denied = false, deniedTrash = false ) => ( {
	policy,
	denied,
	denied_trash: deniedTrash,
} );

describe( 'the policy the dialog sends', () => {
	it( 'leaves one page to its own policy unless another is chosen', () => {
		const preview = { pages: [ page( 'keep' ) ] };
		const single = preview.pages[ 0 ];
		expect( confirmedPolicy( preview, single, 'keep', 'keep' ) ).toBe(
			null
		);
		expect( confirmedPolicy( preview, single, 'reparent', 'keep' ) ).toBe(
			'reparent'
		);
	} );

	it( 'sends the policy shown when a selected page has another of its own', () => {
		const preview = { pages: [ page( 'reparent' ), page( 'keep' ) ] };
		expect( confirmedPolicy( preview, null, 'reparent', 'reparent' ) ).toBe(
			'reparent'
		);
	} );

	it( 'leaves a selection whose pages all share the policy shown to them', () => {
		const preview = { pages: [ page( 'reparent' ), page( 'reparent' ) ] };
		expect( confirmedPolicy( preview, null, 'reparent', 'reparent' ) ).toBe(
			null
		);
	} );

	it( 'sends nothing extra when the preview failed', () => {
		expect( confirmedPolicy( null, null, 'reparent', 'reparent' ) ).toBe(
			null
		);
	} );
} );

describe( 'the note about subpages the user can’t edit', () => {
	it( 'follows the rights the policy needs', () => {
		const preview = { pages: [ page( 'reparent', true, false ) ] };
		expect( showsDenied( preview, 'reparent' ) ).toBe( true );
		expect( showsDenied( preview, 'trash_children' ) ).toBe( false );
	} );

	it( 'is not shown when the subpages stay where they are', () => {
		const preview = { pages: [ page( 'keep', true, true ) ] };
		expect( showsDenied( preview, 'keep' ) ).toBe( false );
		expect( showsDenied( null, 'reparent' ) ).toBe( false );
	} );
} );

describe( 'a row title', () => {
	it( 'drops the dashes WordPress 6.6 puts before a subpage’s title', () => {
		expect( plainTitle( '— — Team' ) ).toBe( 'Team' );
		expect( plainTitle( '—Team' ) ).toBe( 'Team' );
		expect( plainTitle( ' About — us ' ) ).toBe( 'About — us' );
	} );
} );
