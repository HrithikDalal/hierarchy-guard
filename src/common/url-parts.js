/**
 * One path segment as people read it: percent-encoding decoded, the way core
 * shows permalinks. A segment that is not valid percent-encoding stays as it is.
 *
 * @param {string} segment Path segment.
 * @return {string} Decoded segment.
 */
function decodeSegment( segment ) {
	try {
		return decodeURIComponent( segment );
	} catch {
		return segment;
	}
}

/**
 * Splits a URL path into the pieces Url prints: each slug, decoded, with the
 * slash after it, the leading slash on the first slug, and the last character
 * of each piece kept with its slash so a slash never starts or ends a line alone.
 *
 * @param {string} value Path, such as /about/team/.
 * @return {Array<{head: string, end: string}>} Pieces; "/" alone is one piece.
 */
export default function urlParts( value ) {
	const path = String( value );
	const pieces = path.split( '/' ).filter( ( piece ) => '' !== piece );
	if ( ! pieces.length ) {
		return [ { head: '', end: path } ];
	}
	const last = pieces.length - 1;
	return pieces.map( ( piece, index ) => {
		// By code point, so a character outside the basic plane stays whole.
		const text = Array.from(
			( 0 === index && path.startsWith( '/' ) ? '/' : '' ) +
				decodeSegment( piece ) +
				( index < last || path.endsWith( '/' ) ? '/' : '' )
		);
		const cut = '/' === text[ text.length - 1 ] ? -2 : -1;
		return {
			head: text.slice( 0, cut ).join( '' ),
			end: text.slice( cut ).join( '' ),
		};
	} );
}
