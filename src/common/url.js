/**
 * A URL path that breaks only after a slash, never inside a slug.
 */
import { Fragment } from '@wordpress/element';

/**
 * The path in a code element.
 *
 * @param {Object} props       Props.
 * @param {string} props.value Path, such as /about/team/.
 * @return {Element} Code.
 */
export default function Url( { value } ) {
	const parts = String( value ).split( '/' );
	return (
		<code className="hierarchy-guard-url">
			{ parts.map( ( part, index ) => (
				<Fragment key={ index }>
					{ '' !== part && (
						<span className="hierarchy-guard-url__part">
							{ part }
						</span>
					) }
					{ index < parts.length - 1 && (
						<>
							/<wbr />
						</>
					) }
				</Fragment>
			) ) }
		</code>
	);
}
