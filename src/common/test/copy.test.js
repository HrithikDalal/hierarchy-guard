/**
 * The sentences the scripts show.
 */
import { describe, expect, it } from 'vitest';
import {
	bulkSentence,
	classicConfirm,
	changedSentence,
	changedStatus,
	copiedSentence,
	countsLine,
	deniedSentence,
	dialogTitle,
	formatCount,
	initialSentence,
	keptUrl,
	oldUrlState,
	optionDescription,
	optionLabel,
	pairsText,
	previewError,
	quickEditWarning,
	saveWarning,
	subpagesLabel,
	trashNote,
	trashSentence,
	unresolvedSentence,
	viewAllLabel,
	viewRedirectsLabel,
} from '../copy';

const team = {
	title: 'Team',
	path: '/studio/team/',
	policy: 'reparent',
	children: 2,
	descendants: { count: 3, capped: false },
	fallback: false,
	to: { id: 7, title: 'Studio' },
	example: { from: '/studio/team/ada/', to: '/studio/ada/' },
	denied: false,
	denied_trash: false,
};

describe( 'counts', () => {
	it( 'formats a capped count with a plus', () => {
		expect( formatCount( 1000, true ) ).toBe( '1,000+' );
		expect( formatCount( 3 ) ).toBe( '3' );
	} );

	it( 'labels subpages in the singular and the plural', () => {
		expect( subpagesLabel( 1 ) ).toBe( '1 subpage' );
		expect( subpagesLabel( 1000, true ) ).toBe( '1,000+ subpages' );
		expect( viewAllLabel( 12, false ) ).toBe( 'View all 12 in the list' );
	} );

	it( 'says how many subpages sit directly below and at every level', () => {
		expect(
			countsLine( {
				children_total: 4,
				children_capped: false,
				descendants: { count: 12, capped: false },
			} )
		).toBe( '4 direct · 12 in total' );
	} );
} );

describe( 'the trash dialog', () => {
	it( 'knows its title when the link is clicked', () => {
		expect( dialogTitle( 1 ) ).toBe( 'Move this page to the Trash?' );
		expect( dialogTitle( 3 ) ).toBe( 'Move 3 pages to the Trash?' );
	} );

	it( 'says something useful before the subpages are checked', () => {
		expect( initialSentence( 'Team', 2, 'reparent' ) ).toBe(
			'“Team” has 2 subpages. The subpages move up one level and get new URLs.'
		);
	} );

	it( 'names what each number counts', () => {
		expect( trashSentence( team, 'reparent', 1000 ) ).toBe(
			'“Team” has 2 subpages directly under it, 3 in all. They’ll move up under “Studio” and get new URLs.'
		);
		expect( optionLabel( 'trash_children', team ) ).toBe(
			'Trash all 3 subpages too'
		);
	} );

	it( 'leaves out the total when every subpage sits directly below', () => {
		expect(
			trashSentence(
				{
					...team,
					children: 1,
					descendants: { count: 1, capped: false },
				},
				'reparent',
				1000
			)
		).toBe(
			'“Team” has 1 subpage. It will move up under “Studio” and get a new URL.'
		);
	} );

	it( 'says when the subpages become top-level pages', () => {
		expect( trashSentence( { ...team, to: null }, 'reparent', 1000 ) ).toBe(
			'“Team” has 2 subpages directly under it, 3 in all. They’ll become top-level pages and get new URLs.'
		);
	} );

	it( 'describes trashing the subpages too, and the fallback past the cap', () => {
		expect( trashSentence( team, 'trash_children', 1000 ) ).toBe(
			'“Team” has 2 subpages directly under it, 3 in all. They go to the Trash too, and come back with their statuses if you restore “Team”.'
		);
		expect(
			trashSentence( { ...team, fallback: true }, 'trash_children', 1000 )
		).toBe(
			'“Team” has 2 subpages directly under it, 3 in all. That is more than 1,000, so they’ll move up one level instead of going to the Trash.'
		);
	} );

	it( 'shows a capped direct count with a plus', () => {
		expect(
			initialSentence( 'Team', 1001, 'reparent' ).startsWith(
				'“Team” has 1,000+ subpages.'
			)
		).toBe( true );
		expect(
			trashSentence(
				{
					...team,
					children: 1000,
					descendants: { count: 1000, capped: true },
				},
				'reparent',
				1000
			)
		).toContain( '“Team” has 1,000+ subpages.' );
	} );

	it( 'shows the URL a kept subpage gets', () => {
		expect( keptUrl( team ) ).toBe( '/studio/team__trashed/ada/' );
		expect( optionDescription( 'keep', team, 1000 ) ).toBe(
			'Their URLs change until you move them, for example <from /> becomes <to />.'
		);
	} );

	it( 'sums a bulk selection, counting pages it did not preview', () => {
		expect(
			bulkSentence(
				{
					pages: [ team, team ],
					total: { count: 19, capped: false },
					more: 0,
				},
				'reparent',
				3,
				1000
			)
		).toBe(
			'3 of the selected pages have subpages, 19 in all. The subpages move up one level and get new URLs.'
		);
		expect(
			bulkSentence(
				{
					pages: [ team, { ...team, fallback: null } ],
					total: { count: 1000, capped: true },
					more: 0,
				},
				'trash_children',
				2,
				1000
			)
		).toBe(
			'2 of the selected pages have subpages, 1,000+ in all. The subpages go to the Trash too, and come back with their statuses if you restore their parent. A page with more than 1,000 subpages has them moved up instead.'
		);
	} );

	it( 'labels and describes each option like the settings', () => {
		expect( optionLabel( 'reparent', team ) ).toBe(
			'Move the subpages up under “Studio”'
		);
		expect( optionLabel( 'reparent', null ) ).toBe(
			'Move subpages up one level'
		);
		expect( optionLabel( 'keep', null ) ).toBe(
			'Leave subpages where they are'
		);
		expect( optionDescription( 'reparent', team, 1000 ) ).toBe(
			'Their URLs change, for example <from /> becomes <to />.'
		);
		expect(
			optionDescription(
				'trash_children',
				{ ...team, fallback: true },
				1000
			)
		).toBe( 'Not available: there are more than 1,000.' );
	} );

	it( 'names the right that is missing, and what an error leads to', () => {
		expect( deniedSentence( 'reparent' ) ).toBe(
			'You can’t edit some of the subpages. They’ll stay where they are.'
		);
		expect( deniedSentence( 'trash_children' ) ).toBe(
			'You can’t delete some of the subpages. They’ll stay where they are.'
		);
		expect( previewError( 'keep' ) ).toBe(
			'The subpages couldn’t be checked. If you continue, they’ll stay where they are.'
		);
	} );
} );

describe( 'warnings and outcomes', () => {
	it( 'warns before a save changes subpage URLs', () => {
		expect( saveWarning( 12, false, false ) ).toBe(
			'Saving changes this page’s URL and the URLs of 12 subpages. You’ll get the redirects to add after saving.'
		);
		expect( saveWarning( 1, false, true ) ).toBe(
			'Saving changes this page’s URL and the URL of 1 subpage. The old URLs will redirect to the new ones.'
		);
		expect( quickEditWarning( 5, false ) ).toBe(
			'Saving changes this page’s URL and the URLs of 5 subpages.'
		);
	} );

	it( 'composes the classic confirmation from the save warning', () => {
		expect( classicConfirm( 12, false, false ) ).toBe(
			'Saving changes this page’s URL and the URLs of 12 subpages. You’ll get the redirects to add after saving. Continue?'
		);
		expect( classicConfirm( 1, false, true ) ).toBe(
			'Saving changes this page’s URL and the URL of 1 subpage. The old URLs will redirect to the new ones. Continue?'
		);
	} );

	it( 'says what handles the changed URLs, with a status to match', () => {
		expect( changedSentence( 12, false, true, null ) ).toBe(
			'12 old URLs now redirect to their new pages.'
		);
		expect(
			changedSentence( 12, false, false, { name: 'Redirection' } )
		).toBe( '12 URLs changed. Add their redirects in Redirection.' );
		expect( changedSentence( 2, false, false, { name: '' } ) ).toBe(
			'2 URLs changed. Add their redirects in your redirect plugin.'
		);
		expect( changedSentence( 1, false, false, null ) ).toBe(
			'1 old URL now shows “Page not found”.'
		);
		expect( changedStatus( true, null ) ).toBe( 'success' );
		expect( changedStatus( false, { name: 'Redirection' } ) ).toBe(
			'info'
		);
		expect( changedStatus( false, null ) ).toBe( 'warning' );
		expect( viewRedirectsLabel( 1000, true ) ).toBe(
			'View redirects (1,000+)'
		);
	} );

	it( 'says how each old URL is handled', () => {
		expect( oldUrlState( true, null ) ).toBe( 'Redirects here' );
		expect( oldUrlState( false, { name: 'Redirection' } ) ).toBe(
			'Add in Redirection'
		);
		expect( oldUrlState( false, null ) ).toBe( 'Not redirected' );
		expect( oldUrlState( true, null, true ) ).toBe(
			'Used by another page'
		);
	} );

	it( 'says what trashing the page would do, by policy', () => {
		const data = {
			children_total: 4,
			children_capped: false,
			descendants: { count: 12, capped: false },
			trash_policy: 'reparent',
			trash_to: { title: 'Company' },
		};
		expect( trashNote( data ) ).toBe(
			'Trashing this page moves its 4 subpages under “Company”.'
		);
		expect( trashNote( { ...data, trash_to: null } ) ).toBe(
			'Trashing this page makes its 4 subpages top-level pages.'
		);
		expect( trashNote( { ...data, trash_policy: 'trash_children' } ) ).toBe(
			'Trashing this page moves its 12 subpages to the trash too.'
		);
		expect( trashNote( { ...data, trash_policy: 'keep' } ) ).toBe(
			'Trashing this page leaves its 4 subpages where they are.'
		);
		expect( trashNote( { ...data, children_total: 0 } ) ).toBe( '' );
		expect(
			trashNote( {
				...data,
				children_total: 1000,
				children_capped: true,
				trash_policy: 'keep',
			} )
		).toBe(
			'Trashing this page leaves its 1,000+ subpages where they are.'
		);
	} );

	it( 'explains an old URL without a new one', () => {
		expect(
			unresolvedSentence( { old: '/about/', reason: 'trash' } )
		).toBe( '/about/ is in the Trash, so it has no new URL.' );
		expect(
			unresolvedSentence( { old: '/about/', reason: 'in_use' } )
		).toBe(
			'/about/ belongs to another page now, so it needs no redirect.'
		);
		expect(
			unresolvedSentence( { old: '/about/', reason: 'unpublished' } )
		).toBe( '/about/ leads to a page that isn’t published.' );
	} );

	it( 'says what it copied', () => {
		expect( copiedSentence( 3, false ) ).toBe( 'Copied.' );
		expect( copiedSentence( 1000, true ) ).toBe(
			'Copied the first 1,000.'
		);
		expect(
			pairsText( [
				{ old: '/a/', new: '/b/' },
				{ old: '/a/c/', new: '/b/c/' },
			] )
		).toBe( '/a/ /b/\n/a/c/ /b/c/' );
	} );
} );
