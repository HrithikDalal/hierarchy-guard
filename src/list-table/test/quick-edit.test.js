// @vitest-environment happy-dom
/**
 * Quick Edit's announcements.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock( '@wordpress/a11y', () => ( { speak: vi.fn() } ) );

const { speak } = await import( '@wordpress/a11y' );
const { speakAfterSave } = await import( '../quick-edit-words' );

describe( 'the announcement after a Quick Edit save', () => {
	let finish;

	beforeEach( () => {
		speak.mockClear();
		finish = null;
		// A saved row whose fade-in core is still running.
		window.jQuery = ( selector ) =>
			'#post-7' === selector
				? {
						length: 1,
						promise: () => ( {
							done: ( callback ) => {
								finish = callback;
							},
						} ),
					}
				: { length: 0 };
	} );

	afterEach( () => {
		delete window.jQuery;
	} );

	it( 'waits for core’s row to fade in, then says core’s words and the sentence once', () => {
		speakAfterSave( 7, '4 old URLs now redirect to their new pages.' );
		expect( speak ).not.toHaveBeenCalled();

		finish();

		expect( speak ).toHaveBeenCalledTimes( 1 );
		expect( speak ).toHaveBeenCalledWith(
			'Changes saved. 4 old URLs now redirect to their new pages.',
			'polite'
		);
	} );

	it( 'says it at once when the row is gone', () => {
		speakAfterSave( 8, 'Sentence.' );

		expect( speak ).toHaveBeenCalledWith(
			'Changes saved. Sentence.',
			'polite'
		);
	} );
} );
