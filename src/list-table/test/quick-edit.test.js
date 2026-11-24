// @vitest-environment happy-dom
/**
 * Quick Edit's announcements.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock( '@wordpress/a11y', () => ( { speak: vi.fn() } ) );

const { speak } = await import( '@wordpress/a11y' );
const { default: config } = await import( '../../common/config' );
const { speakAfterSave, warningText } = await import( '../quick-edit-words' );

describe( 'the Quick Edit warning', () => {
	afterEach( () => {
		config.serving = false;
	} );

	it( 'says what happens to the old URLs, as the editors do', () => {
		config.serving = false;
		expect( warningText( { count: 3, capped: false } ) ).toBe(
			'Saving will change the URLs of this page and of its 3 subpages. After you save, you’ll see the redirects to add.'
		);

		config.serving = true;
		expect( warningText( { count: 1, capped: false } ) ).toBe(
			'Saving will change the URL of this page and of its 1 subpage. The old URLs will redirect to the new ones.'
		);
	} );
} );

describe( 'the announcement after a Quick Edit save', () => {
	let finish;

	beforeEach( () => {
		speak.mockClear();
		finish = null;
		// Row 7 is still fading in; row 9 has finished, so core has already
		// said its words; any other row is gone.
		const rows = {
			'#post-7': true,
			'#post-9': false,
		};
		window.jQuery = ( selector ) =>
			selector in rows
				? {
						length: 1,
						is: ( state ) =>
							':animated' === state && rows[ selector ],
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

	it( 'says only the sentence, at once, once core’s row has faded in', () => {
		speakAfterSave( 9, 'Sentence.' );

		expect( finish ).toBeNull();
		expect( speak ).toHaveBeenCalledTimes( 1 );
		expect( speak ).toHaveBeenCalledWith( 'Sentence.', 'polite' );
	} );

	it( 'says only the sentence, at once, when the row is gone', () => {
		speakAfterSave( 8, 'Sentence.' );

		expect( speak ).toHaveBeenCalledTimes( 1 );
		expect( speak ).toHaveBeenCalledWith( 'Sentence.', 'polite' );
	} );
} );
