/**
 * The classic editor: a plain confirmation when the form is submitted with a
 * changed slug or parent on a published page with subpages.
 */
import domReady from '@wordpress/dom-ready';
import { __, _n, sprintf } from '@wordpress/i18n';
import { formatCount } from '../common/copy';

domReady( () => {
	const box = window.document.querySelector( '.hierarchy-guard-box' );
	const $ = window.jQuery;
	if ( ! box || ! $ ) {
		return;
	}
	const count = Number( box.dataset.descendants || 0 );
	if ( ! count || 'publish' !== box.dataset.status ) {
		return;
	}

	const slug = window.document.getElementById( 'post_name' );
	const parent = window.document.getElementById( 'parent_id' );
	const initial = {
		slug: slug ? slug.value : '',
		parent: parent ? parent.value : '',
	};

	// A jQuery handler: core re-binds its own submit handler on every click of
	// a submit button, so it always runs after this one and sees the cancel.
	$( '#post' ).on( 'submit.hierarchy-guard', ( event ) => {
		const edited =
			( slug ? slug.value : '' ) !== initial.slug ||
			( parent ? parent.value : '' ) !== initial.parent;
		if ( ! edited ) {
			return;
		}
		const message = [
			sprintf(
				/* translators: %s: number of subpages, such as 12 or 1,000+. */
				_n(
					'Saving changes this page’s URL and the URL of %s subpage.',
					'Saving changes this page’s URL and the URLs of %s subpages.',
					count,
					'hierarchy-guard'
				),
				formatCount( count, '1' === box.dataset.capped )
			),
			__(
				'You’ll get the redirects to add in the Hierarchy box after saving.',
				'hierarchy-guard'
			),
			__( 'Continue?', 'hierarchy-guard' ),
		].join( ' ' );
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( message ) ) {
			event.preventDefault();
		}
	} );
} );
