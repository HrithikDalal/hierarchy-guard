/**
 * Splits a URL path into the pieces Url prints: each slug with the slash
 * after it, the leading slash on the first slug, and the last character of
 * each piece kept with its slash so a slash never starts or ends a line alone.
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
		const text =
			( 0 === index && path.startsWith( '/' ) ? '/' : '' ) +
			piece +
			( index < last || path.endsWith( '/' ) ? '/' : '' );
		const cut = text.endsWith( '/' ) ? -2 : -1;
		return { head: text.slice( 0, cut ), end: text.slice( cut ) };
	} );
}
