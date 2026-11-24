// @vitest-environment happy-dom
/**
 * The words beside a spinner, and their polite announcement.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock( '@wordpress/a11y', () => ( { speak: vi.fn() } ) );

const { speak } = await import( '@wordpress/a11y' );
const { loadingText, speakLoading, waiting } = await import( '../loading' );

describe( 'loading', () => {
	beforeEach( () => {
		speak.mockClear();
	} );

	it( 'names what a spinner means', () => {
		expect( loadingText() ).toBe( 'Loading…' );
	} );

	it( 'says it politely, so a screen reader hears it without moving to it', () => {
		speakLoading();

		expect( speak ).toHaveBeenCalledTimes( 1 );
		expect( speak ).toHaveBeenCalledWith( 'Loading…', 'polite' );
	} );

	it( 'shows the spinner while its view is open and neither the data nor an error has come', () => {
		expect( waiting( true, null, '' ) ).toBe( true );
		expect( waiting( false, null, '' ) ).toBe( false );
		expect( waiting( true, { total: 0 }, '' ) ).toBe( false );
		expect(
			waiting( true, null, 'The redirects couldn’t be loaded.' )
		).toBe( false );
	} );
} );
