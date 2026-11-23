// @vitest-environment happy-dom
/**
 * The Bulk Edit warning before a new parent changes subpage URLs.
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock( '@wordpress/a11y', () => ( { speak: vi.fn() } ) );

const { speak } = await import( '@wordpress/a11y' );
const { default: bindBulkEdit, updateBulkWarning } =
	await import( '../bulk-edit' );

/**
 * Core's list rows, each with its Hierarchy cell, and its Bulk Edit form.
 *
 * @param {Array<{id: number, children: number, status: string, checked: boolean}>} rows Rows.
 */
function render( rows ) {
	window.document.body.innerHTML = `
		<table><tbody id="the-list">
			<tr id="bulk-edit"><td>
				<ul id="bulk-titles">${ rows
					.filter( ( row ) => row.checked )
					.map(
						( row ) =>
							`<li class="ntdelitem"><button type="button" id="_${ row.id }" class="button-link ntdelbutton">×</button></li>`
					)
					.join( '' ) }</ul>
				<select name="post_parent"><option value="-1">— No Change —</option><option value="0">Main Page (no parent)</option><option value="7">Company</option></select>
				<div class="submit inline-edit-save"><input type="submit" id="bulk_edit" value="Update"></div>
			</td></tr>
			${ rows
				.map(
					( row ) =>
						`<tr id="post-${ row.id }"><th><input type="checkbox" name="post[]" value="${ row.id }"${ row.checked ? ' checked' : '' }></th><td><span class="hierarchy-guard-cell" data-id="${ row.id }" data-children="${ row.children }" data-status="${ row.status }"></span></td></tr>`
				)
				.join( '' ) }
		</tbody></table>`;
}

/**
 * Chooses a parent in the Bulk Edit form.
 *
 * @param {string} value Option value.
 */
function choose( value ) {
	window.document.querySelector( 'select[name="post_parent"]' ).value = value;
	updateBulkWarning( window.document );
}

const box = () => window.document.getElementById( 'hierarchy-guard-bulk-edit' );

describe( 'the Bulk Edit warning', () => {
	beforeEach( () => {
		speak.mockClear();
		render( [
			{ id: 1, children: 3, status: 'publish', checked: true },
			{ id: 2, children: 0, status: 'publish', checked: true },
			{ id: 3, children: 5, status: 'draft', checked: true },
			{ id: 4, children: 2, status: 'publish', checked: false },
			{ id: 5, children: 1, status: 'publish', checked: true },
		] );
	} );

	it( 'counts the selected published pages with subpages once a parent is chosen', () => {
		choose( '7' );

		expect( box().hidden ).toBe( false );
		expect( box().textContent ).toBe(
			'Saving will change the URLs of the selected pages and of the subpages under 2 of them. You’ll get the redirects to add after saving.'
		);
		expect(
			window.document
				.querySelector( 'select[name="post_parent"]' )
				.getAttribute( 'aria-describedby' )
		).toBe( 'hierarchy-guard-bulk-edit' );
		expect( speak ).toHaveBeenCalledTimes( 1 );
		expect(
			box().parentNode.classList.contains( 'inline-edit-save' )
		).toBe( true );
	} );

	it( 'says nothing while the parent is left unchanged, or no selected page has subpages', () => {
		choose( '-1' );
		expect( box() ).toBeNull();

		choose( '7' );
		choose( '-1' );
		expect( box().hidden ).toBe( true );

		window.document
			.querySelectorAll( 'input[name="post[]"]' )
			.forEach( ( input ) => {
				input.checked = '2' === input.value;
			} );
		choose( '0' );
		expect( box().hidden ).toBe( true );
	} );

	it( 'counts again when core removes a page from the selection with its × button', async () => {
		bindBulkEdit( window.document );
		choose( '7' );
		const button = window.document.getElementById( '_5' );
		// What core's own handler on the button does before the click reaches the document.
		button.addEventListener( 'click', () => {
			window.document.querySelector( 'input[value="5"]' ).checked = false;
			button.parentNode.remove();
		} );

		button.dispatchEvent(
			new window.MouseEvent( 'click', { bubbles: true } )
		);
		await new Promise( ( resolve ) => window.setTimeout( resolve, 0 ) );

		expect( box().textContent ).toContain( 'under 1 of them' );
	} );

	it( 'counts again when a row is ticked while Bulk Edit is open', () => {
		bindBulkEdit( window.document );
		choose( '7' );
		const row = window.document.querySelector( 'input[value="4"]' );
		row.checked = true;

		row.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );

		expect( box().textContent ).toContain( 'under 3 of them' );
	} );

	it( 'speaks again only when the sentence changes', () => {
		choose( '7' );
		choose( '0' );
		expect( speak ).toHaveBeenCalledTimes( 1 );

		window.document.querySelector( 'input[value="5"]' ).checked = false;
		choose( '0' );
		expect( speak ).toHaveBeenCalledTimes( 2 );
		expect( box().textContent ).toContain( 'under 1 of them' );
	} );
} );
