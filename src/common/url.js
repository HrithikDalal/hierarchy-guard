/**
 * A URL path that breaks only after a slash; a slug longer than the line breaks inside.
 */
import { Fragment } from '@wordpress/element';

/**
 * The path in a code element, each segment with its trailing slash.
 *
 * @param {Object} props       Props.
 * @param {string} props.value Path, such as /about/team/.
 * @return {Element} Code.
 */
export default function Url( { value } ) {
	const parts = String( value ).split( '/' );
	const last = parts.length - 1;
	const segments = [];
	parts.forEach( ( part, index ) => {
		const text =
			( 0 === index ? '/' : '' ) + part + ( index < last ? '/' : '' );
		// The empty piece after a trailing slash adds nothing; "/" alone is one part.
		if ( '' === part && index > 0 ) {
			return;
		}
		if ( '' === part && 0 === index && 0 === last ) {
			return;
		}
		segments.push( text );
	} );
	return (
		<code className="hierarchy-guard-url">
			{ segments.map( ( text, index ) => (
				<Fragment key={ index }>
					{ index > 0 && <wbr /> }
					<span className="hierarchy-guard-url__part">{ text }</span>
				</Fragment>
			) ) }
		</code>
	);
}
