/**
 * What the trash dialog sends and shows.
 */
import { describe, expect, it } from 'vitest';
import {
	confirmedPolicy,
	dialogSentence,
	openingPolicy,
	plainTitle,
	rowTitle,
	showsDenied,
} from '../trash-choice';

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
		const kept = { pages: [ page( 'keep' ), page( 'keep' ) ] };
		expect( confirmedPolicy( kept, null, 'keep', 'keep' ) ).toBe( null );
	} );

	it( 'sends the policy it stated when the preview failed', () => {
		expect( confirmedPolicy( null, null, 'reparent', 'reparent' ) ).toBe(
			'reparent'
		);
	} );

	it( 'sends the policy shown when some selected parents were not previewed', () => {
		const preview = { pages: [ page( 'reparent' ), page( 'reparent' ) ] };
		expect(
			confirmedPolicy( preview, null, 'reparent', 'reparent', true )
		).toBe( 'reparent' );
	} );
} );

describe( 'the policy the dialog opens with', () => {
	const result = ( pages ) => ( { policy: 'reparent', pages } );

	it( 'is the page’s own for one page', () => {
		expect( openingPolicy( result( [ page( 'keep' ) ] ), 1, 1, 1 ) ).toBe(
			'keep'
		);
	} );

	it( 'is the policy every previewed page shares', () => {
		const pages = [ page( 'keep' ), page( 'keep' ) ];
		expect( openingPolicy( result( pages ), 2, 2, 2 ) ).toBe( 'keep' );
		expect(
			openingPolicy( result( [ page( 'keep' ) ] ), 3, 1, 1 ),
			'One parent among pages without subpages.'
		).toBe( 'keep' );
	} );

	it( 'is the site default for mixed policies, or when some parents were not previewed', () => {
		expect(
			openingPolicy(
				result( [ page( 'keep' ), page( 'trash_children' ) ] ),
				2,
				2,
				2
			)
		).toBe( 'reparent' );
		expect(
			openingPolicy(
				result( [ page( 'keep' ), page( 'keep' ) ] ),
				60,
				60,
				50
			)
		).toBe( 'reparent' );
	} );
} );

describe( 'the dialog’s sentence', () => {
	const preview = {
		policy: 'reparent',
		more: 0,
		total: { count: 5, capped: false },
		pages: [
			{
				id: 4,
				title: 'Studio',
				children: 2,
				descendants: { count: 5, capped: false },
				policy: 'reparent',
			},
		],
	};

	it( 'describes the previewed page', () => {
		expect(
			dialogSentence( {
				preview,
				policy: 'reparent',
				selected: 1,
				withChildren: 1,
				previewed: 1,
				title: 'Studio',
				children: 2,
			} )
		).toContain( '“Studio” has 2 subpages directly under it, 5 in all.' );
	} );

	it( 'counts the parents left out of the preview', () => {
		expect(
			dialogSentence( {
				preview,
				policy: 'reparent',
				selected: 3,
				withChildren: 3,
				previewed: 1,
				title: '',
				children: 0,
			} )
		).toBe(
			dialogSentence( {
				preview: { ...preview, more: 2 },
				policy: 'reparent',
				selected: 3,
				withChildren: 3,
				previewed: 3,
				title: '',
				children: 0,
			} )
		);
	} );

	it( 'falls back to the row before the preview, and to nothing for a bulk selection', () => {
		const before = {
			preview: null,
			policy: 'reparent',
			withChildren: 1,
			previewed: 1,
			title: 'Studio',
			children: 2,
		};
		expect( dialogSentence( { ...before, selected: 1 } ) ).toContain(
			'Studio'
		);
		expect( dialogSentence( { ...before, selected: 2 } ) ).toBe( '' );
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
	it( 'drops only the dashes WordPress 6.6 puts before a subpage’s title', () => {
		expect( plainTitle( '— — Team' ) ).toBe( 'Team' );
		expect( plainTitle( ' About — us ' ) ).toBe( 'About — us' );
		expect(
			plainTitle( '—Team' ),
			'Core’s pad is a dash and a space.'
		).toBe( '—Team' );
	} );

	it( 'keeps a title that is only a dash', () => {
		expect( plainTitle( '—' ) ).toBe( '—' );
		expect( plainTitle( '— —' ) ).toBe( '—' );
	} );

	it( 'reads the raw title core prints for Quick Edit first', () => {
		const doc = ( nodes ) => ( {
			querySelector: ( selector ) => {
				if ( undefined === nodes[ selector ] ) {
					return null;
				}
				return selector.startsWith( '#inline_' )
					? raw( [ nodes[ selector ] ] )
					: { textContent: nodes[ selector ] };
			},
		} );
		expect(
			rowTitle(
				doc( {
					'#inline_7 .post_title': '— Intro',
					'#post-7 .row-title': '— — — Intro',
				} ),
				7
			)
		).toBe( '— Intro' );
		expect(
			rowTitle( doc( { '#post-7 .row-title': '— Team' } ), 7 ),
			'Without the Quick Edit data, the link’s text without the pad.'
		).toBe( 'Team' );
		expect( rowTitle( doc( {} ), 7 ) ).toBe( '' );
	} );

	it( 'names a page titled 0 “(no title)” before and after the preview arrives, as core’s list does', () => {
		const doc = {
			querySelector: ( selector ) =>
				'#inline_7 .post_title' === selector ? raw( [ '0' ] ) : null,
		};
		const title = rowTitle( doc, 7 );
		expect( title ).toBe( '(no title)' );

		const before = dialogSentence( {
			preview: null,
			policy: 'reparent',
			selected: 1,
			withChildren: 1,
			previewed: 1,
			title,
			children: 2,
		} );
		const after = dialogSentence( {
			preview: {
				more: 0,
				total: { count: 2, capped: false },
				pages: [
					{
						id: 7,
						title: '(no title)',
						children: 2,
						descendants: { count: 2, capped: false },
						policy: 'reparent',
					},
				],
			},
			policy: 'reparent',
			selected: 1,
			withChildren: 1,
			previewed: 1,
			title,
			children: 2,
		} );
		expect( before ).toMatch( /^“\(no title\)” has 2 subpages/ );
		expect( after ).toMatch( /^“\(no title\)” has 2 subpages/ );
	} );

	it( 'says an untitled page has no title, whatever the link adds', () => {
		const doc = {
			querySelector: ( selector ) =>
				( {
					'#inline_7 .post_title': raw( [] ),
					// WordPress 7.1 adds the excerpt of an untitled page to its link.
					'#post-7 .row-title': {
						textContent:
							'(no title) Body words of the untitled page.',
					},
				} )[ selector ] || null,
		};
		expect( rowTitle( doc, 7 ) ).toBe( '(no title)' );
	} );

	it( 'reads the raw title as text, with its emoji', () => {
		// The raw title is printed escaped, so a stored entity shows as text.
		// Like a browser's textarea, the stand-in also decodes a legacy name
		// with no semicolon, as `&times` before a digit.
		const entities = { amp: '&', lt: '<', gt: '>', times: '×' };
		globalThis.document = {
			implementation: {
				createHTMLDocument: () => ( {
					createElement: () => {
						const area = { textContent: '' };
						Object.defineProperty( area, 'innerHTML', {
							set( html ) {
								area.textContent = html.replace(
									/&(amp|lt|gt|times);?/g,
									( match, name ) => entities[ name ]
								);
							},
						} );
						return area;
					},
				} ),
			},
		};
		try {
			const doc = ( parts ) => ( {
				querySelector: ( selector ) =>
					'#inline_7 .post_title' === selector ? raw( parts ) : null,
			} );
			expect( rowTitle( doc( [ 'Tom &amp; Jerry' ] ), 7 ) ).toBe(
				'Tom & Jerry'
			);
			expect(
				rowTitle( doc( [ 'Price&times2 &amp;times;' ] ), 7 ),
				'Only a complete reference is decoded, as core and the preview read the title.'
			).toBe( 'Price&times2 &times;' );
			expect(
				rowTitle( doc( [ { alt: '🇩🇪' }, ' Germany' ] ), 7 ),
				'An emoji wp-emoji turned into an image reads as its alt.'
			).toBe( '🇩🇪 Germany' );
		} finally {
			delete globalThis.document;
		}
	} );

	it( 'decodes only the references PHP decodes in the title', () => {
		// A browser's textarea, for a few names: it decodes a legacy name with
		// no semicolon too, HTML5 names, and &#128; as Windows-1252.
		const legacy = {
			amp: '&',
			times: '×',
			copy: '©',
			not: '¬',
			eacute: 'é',
		};
		const html5 = { apos: "'", check: '✓', lang: '⟨' };
		globalThis.document = {
			implementation: {
				createHTMLDocument: () => ( {
					createElement: () => {
						const area = { textContent: '' };
						Object.defineProperty( area, 'innerHTML', {
							set( html ) {
								area.textContent = html
									.replace(
										/&(apos|check|lang);/g,
										( match, name ) => html5[ name ]
									)
									.replace(
										/&(amp|times|copy|not|eacute);?/g,
										( match, name ) => legacy[ name ]
									)
									.replace( /&#(\d+);/g, ( match, code ) =>
										String.fromCodePoint(
											{ 0: 0xfffd, 128: 0x20ac }[
												code
											] ?? Number( code )
										)
									);
							},
						} );
						return area;
					},
				} ),
			},
		};
		try {
			const title = ( text ) =>
				rowTitle(
					{
						querySelector: ( selector ) =>
							'#inline_7 .post_title' === selector
								? raw( [ text ] )
								: null,
					},
					7
				);
			expect
				.soft( title( '&timesheet;' ), 'No such name.' )
				.toBe( '&timesheet;' );
			expect
				.soft( title( '&copyright; &notit;' ) )
				.toBe( '&copyright; &notit;' );
			expect
				.soft(
					title( '&check; &apos;q&apos; &AMP;' ),
					'Names HTML 4.01 has not, or not in that case: PHP leaves them.'
				)
				.toBe( '&check; &apos;q&apos; &AMP;' );
			expect
				.soft(
					title( '&#128; &#0; &#xD800;' ),
					'Code points PHP does not decode.'
				)
				.toBe( '&#128; &#0; &#xD800;' );
			expect
				.soft( title( '&eacute; &#233; &#xE9; &Eacute;' ) )
				.toBe( 'é é é É' );
			expect.soft( title( '&#39;&#x27;&quot;' ) ).toBe( `''"` );
			expect
				.soft(
					title( '&lang;x&rang;' ),
					'As PHP reads HTML 4.01, not as HTML5 does.'
				)
				.toBe( '〈x〉' );
			expect
				.soft( title( 'Tom &amp;amp; Jerry' ) )
				.toBe( 'Tom &amp; Jerry' );
		} finally {
			delete globalThis.document;
		}
	} );
} );

/**
 * A stand-in for the raw title node: text, and emoji images as `{ alt }`.
 *
 * @param {Array<string|Object>} parts The node's text and images.
 * @return {Object} Node.
 */
function raw( parts ) {
	const node = {
		parts: [ ...parts ],
		get textContent() {
			return this.parts
				.map( ( part ) => ( 'string' === typeof part ? part : '' ) )
				.join( '' );
		},
		cloneNode() {
			return raw( this.parts );
		},
		querySelectorAll( selector ) {
			return 'img' === selector
				? this.parts
						.filter( ( part ) => 'string' !== typeof part )
						.map( ( img ) => ( {
							alt: img.alt,
							replaceWith: ( text ) => {
								this.parts[ this.parts.indexOf( img ) ] = text;
							},
						} ) )
				: [];
		},
	};
	return node;
}
