/**
 * Settings › Hierarchy Guard › Redirects: Delete asks first, from a row, the
 * Edit view or in bulk, as core asks before deleting a term, a middle-click
 * on a Delete link included. Without JavaScript the Delete runs, as in core.
 */
import { listen } from './confirm';

// eslint-disable-next-line no-alert
listen( window.document, ( question ) => window.confirm( question ) );
