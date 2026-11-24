/**
 * The Tree view's bundle, loaded after the list screen's, only in that view.
 */
import domReady from '@wordpress/dom-ready';
import bindTree from '.';

domReady( () => {
	bindTree();
} );
