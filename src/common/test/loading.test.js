// @vitest-environment happy-dom
/**
 * The words beside a spinner, and their polite announcement.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock( '@wordpress/a11y', () => ( { speak: vi.fn() } ) );

const { speak } = await import( '@wordpress/a11y' );
const { loadingText, speakLoading } = await import( '../loading' );

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
} );
