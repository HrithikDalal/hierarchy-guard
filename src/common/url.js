/**
 * A URL path that breaks only after a slash; a slug longer than the line breaks inside.
 */
import { Fragment } from '@wordpress/element';
import urlParts from './url-parts';

/**
 * The path in a code element, each slug with its trailing slash.
 *
 * @param {Object} props       Props.
 * @param {string} props.value Path, such as /about/team/.
 * @return {Element} Code.
 */
export default function Url( { value } ) {
	return (
		<code className="hierarchy-guard-url">
			{ urlParts( value ).map( ( { head, end }, index ) => (
				<Fragment key={ index }>
					{ index > 0 && <wbr /> }
					<span className="hierarchy-guard-url__part">
						{ head }
						<span className="hierarchy-guard-url__end">
							{ end }
						</span>
					</span>
				</Fragment>
			) ) }
		</code>
	);
}
