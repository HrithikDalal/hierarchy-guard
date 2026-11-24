/**
 * The trash dialog's policy options, as the server prints them.
 */
import { afterEach, describe, expect, it, vi } from 'vitest';

/**
 * The options, read with this config printed before the bundle.
 *
 * @param {Object|undefined} printed The printed config.
 * @return {Promise<string[]>} Options.
 */
async function optionsWith( printed ) {
	vi.resetModules();
	globalThis.hierarchyGuard = printed;
	const { policyOptions } = await import( '../trash-choice' );
	return policyOptions();
}

describe( 'the trash dialog’s policy options', () => {
	afterEach( () => {
		delete globalThis.hierarchyGuard;
	} );

	it( 'are the policies the server prints, in its order', async () => {
		expect(
			await optionsWith( {
				policies: [ 'reparent', 'trash_children', 'keep' ],
			} )
		).toEqual( [ 'reparent', 'trash_children', 'keep' ] );
		expect( await optionsWith( { policies: [ 'keep' ] } ) ).toEqual( [
			'keep',
		] );
	} );

	it( 'keep no list of their own', async () => {
		expect( await optionsWith( undefined ) ).toEqual( [] );
	} );
} );
