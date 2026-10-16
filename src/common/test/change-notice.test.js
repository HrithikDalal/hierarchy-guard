/**
 * What the editors say after a save that changed URLs.
 */
import { afterEach, beforeAll, describe, expect, it } from 'vitest';
import {
	afterSave,
	changeNotice,
	loadSequence,
	newChange,
	requestCounter,
} from '../change-notice';
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

describe( 'what a save that edited the URL says once the page is reloaded', () => {
	const page = ( latest ) => ( { latest_change: latest } );

	it( 'announces a change new to the editor', () => {
		expect( afterSave( 'a', page( 'b' ) ) ).toEqual( {
			action: 'announce',
			change: 'b',
		} );
		expect( afterSave( undefined, page( 'b' ) ).change ).toBe( 'b' );
	} );

	it( 'keeps the notice on screen when the save recorded no new change', () => {
		expect( afterSave( 'a', page( 'a' ) ) ).toEqual( {
			action: 'keep',
			change: null,
		} );
		expect( afterSave( 'a', page( null ) ).action ).toBe( 'keep' );
	} );

	it( 'warns when the page could not be reloaded', () => {
		expect( afterSave( 'a', null ) ).toEqual( {
			action: 'failed',
			change: null,
		} );
	} );

	it( 'leaves an answer a later request superseded to that request', () => {
		expect( afterSave( 'a', undefined ) ).toEqual( {
			action: 'ignore',
			change: null,
		} );
	} );
} );

describe( 'a request counter', () => {
	it( 'says whether a later request was made since', () => {
		const loads = requestCounter();
		const first = loads.next();
		expect( loads.isLatest( first ) ).toBe( true );
		const second = loads.next();
		expect( loads.isLatest( first ) ).toBe( false );
		expect( loads.isLatest( second ) ).toBe( true );
	} );

	it( 'counts each kind of request on its own', () => {
		const loads = requestCounter();
		const other = requestCounter();
		const ticket = loads.next();
		other.next();
		expect( loads.isLatest( ticket ) ).toBe( true );
	} );
} );

describe( 'the loads of a page’s hierarchy', () => {
	it( 'shows only the latest load’s answer', () => {
		const loads = loadSequence();
		const first = loads.start();
		const second = loads.start();
		expect( first.shows() ).toBe( false );
		expect( first.answers() ).toBe( false );
		expect( second.shows() ).toBe( true );
		expect( second.answers() ).toBe( true );
	} );

	it( 'keeps a load after a save current for that save when a plain load starts later', () => {
		const loads = loadSequence();
		const save = loads.start( true );
		const plain = loads.start();
		expect( save.answers() ).toBe( true );
		expect( save.shows() ).toBe( false );
		expect( plain.shows() ).toBe( true );
	} );

	it( 'lets a later save’s load supersede an earlier save’s', () => {
		const loads = loadSequence();
		const first = loads.start( true );
		loads.start();
		const second = loads.start( true );
		expect( first.answers() ).toBe( false );
		expect( second.answers() ).toBe( true );
		expect( second.shows() ).toBe( true );
	} );

	it( 'lets a load after a save supersede an earlier plain load', () => {
		const loads = loadSequence();
		const plain = loads.start();
		loads.start( true );
		expect( plain.shows() ).toBe( false );
		expect( plain.answers() ).toBe( false );
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
		config.settingsUrl =
			'/wp-admin/options-general.php?page=hierarchy-guard#hierarchy-guard-redirects';
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
		config.settingsUrl =
			'/wp-admin/options-general.php?page=hierarchy-guard#hierarchy-guard-redirects';
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
		config.settingsUrl =
			'/wp-admin/options-general.php?page=hierarchy-guard#hierarchy-guard-redirects';
		expect( changeNotice( pairs( 1 ), redirects ).setup ).toBe( true );
	} );
} );
