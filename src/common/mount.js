/**
 * Renders a component into its own root at the end of the page.
 */
import { createRoot } from '@wordpress/element';

/**
 * Opens a component until it calls close().
 *
 * @param {(close: () => void) => Element} render Receives close() and returns an element.
 * @return {() => void} close().
 */
export function open( render ) {
	const node = document.createElement( 'div' );
	document.body.appendChild( node );
	const root = createRoot( node );
	const close = () => {
		root.unmount();
		node.remove();
	};
	root.render( render( close ) );
	return close;
}
