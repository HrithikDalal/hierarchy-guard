/**
 * What the editors say after a save that changed URLs.
 */
import { afterEach, beforeAll, describe, expect, it } from 'vitest';
import { changeNotice, newChange } from '../change-notice';
import config from '../config';

const pairs = ( total, capped = false ) => ( { total, capped } );

beforeAll( () => {
	// Numbers print the same under any machine locale.
	config.locale = 'en-US';
} );

afterEach( () => {
	config.serving = false;
	config.settingsUrl = '';
} );

describe( 'the change a save made', () => {
	it( 'is the latest change when it is new to the editor', () => {
		expect( newChange( 'a', 'b' ) ).toBe( 'b' );
		expect( newChange( null, 'b' ) ).toBe( 'b' );
		expect( newChange( undefined, 'b' ) ).toBe( 'b' );
	} );

	it( 'is none when the save recorded nothing new', () => {
		expect( newChange( 'a', 'a' ) ).toBe( null );
		expect( newChange( 'a', null ) ).toBe( null );
		expect( newChange( undefined, null ) ).toBe( null );
	} );
} );

describe( 'the notice after a save', () => {
	it( 'is not raised when no URL needs a redirect', () => {
		const redirects = { serving: false, provider: null };
		expect( changeNotice( pairs( 0 ), redirects ) ).toBe( null );
		expect( changeNotice( null, redirects ) ).toBe( null );
	} );

	it( 'says the old URLs redirect when the server serves them, with nothing to do', () => {
		config.serving = true;
		config.settingsUrl = '/wp-admin/options-writing.php';
		expect(
			changeNotice( pairs( 12 ), { serving: true, provider: null } )
		).toEqual( {
			status: 'success',
			sentence: '12 old URLs now redirect to their new pages.',
			view: '',
			setup: false,
		} );
	} );

	it( 'follows the pairs, not the page, when the page itself is not redirected to', () => {
		config.serving = true;
		expect(
			changeNotice( pairs( 3 ), {
				serving: false,
				pairs_served: true,
				provider: null,
			} ).status
		).toBe( 'success' );
	} );

	it( 'names the redirect plugin and offers the redirects', () => {
		config.settingsUrl = '/wp-admin/options-writing.php';
		expect(
			changeNotice( pairs( 1000, true ), {
				serving: false,
				provider: { name: 'Redirection', csv: 'redirection' },
			} )
		).toEqual( {
			status: 'info',
			sentence:
				'1,000+ URLs changed. Add their redirects in Redirection.',
			view: 'View redirects (1,000+)',
			setup: false,
		} );
	} );

	it( 'warns when nothing handles redirects, and offers to set them up to those who can', () => {
		const redirects = { serving: false, provider: null };
		expect( changeNotice( pairs( 1 ), redirects ) ).toEqual( {
			status: 'warning',
			sentence: '1 old URL now shows “Page not found”.',
			view: 'View redirects (1)',
			setup: false,
		} );
		config.settingsUrl = '/wp-admin/options-writing.php';
		expect( changeNotice( pairs( 1 ), redirects ).setup ).toBe( true );
	} );
} );
