/**
 * The "Redirects needed" modal: old and new URLs, paged, with what to do next.
 */
import { speak } from '@wordpress/a11y';
import {
	Button,
	ExternalLink,
	Modal,
	Notice,
	Spinner,
} from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { csvUrl, fetchPairs } from './api';
import config from './config';
import {
	copiedSentence,
	formatCount,
	pairsText,
	unresolvedSentence,
} from './copy';
import Url from './url';

const PER_PAGE = 20;

/**
 * "Showing 1–20 of 45".
 *
 * @param {Object} data A page of pairs.
 * @return {string} Range.
 */
function rangeLabel( data ) {
	const first = ( data.page - 1 ) * PER_PAGE + 1;
	return sprintf(
		/* translators: 1: first pair shown, 2: last pair shown, 3: pairs in all. */
		__( 'Showing %1$s–%2$s of %3$s', 'hierarchy-guard' ),
		formatCount( first ),
		formatCount( first + data.pairs.length - 1 ),
		formatCount( data.total, data.capped )
	);
}

/**
 * The modal.
 *
 * @param {Object}     props         Props.
 * @param {Object}     props.source  Either { change } or { post, from }.
 * @param {boolean}    props.editor  Whether the block editor shows it, which says "trash" in lower case.
 * @param {() => void} props.onClose Closes the modal.
 * @return {Element} Modal.
 */
export default function RedirectsModal( { source, editor = false, onClose } ) {
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ copy, setCopy ] = useState( { busy: false, done: '', text: '' } );
	const previous = useRef();
	const next = useRef();
	const pressed = useRef( '' );
	const fallback = useRef();

	useEffect( () => {
		let live = true;
		setLoading( true );
		setError( '' );
		fetchPairs( source, page, PER_PAGE )
			.then( ( result ) => {
				if ( ! live ) {
					return;
				}
				setData( result );
				setLoading( false );
				if ( result.pages > 1 ) {
					speak( rangeLabel( result ), 'polite' );
				}
			} )
			.catch( ( failure ) => {
				if ( ! live ) {
					return;
				}
				setLoading( false );
				setError(
					failure.message ||
						__(
							'The redirects couldn’t be loaded.',
							'hierarchy-guard'
						)
				);
			} );
		return () => {
			live = false;
		};
	}, [ source, page ] );

	// A pager button that the new page disables would drop focus out of the modal.
	useEffect( () => {
		if ( ! data || loading || ! pressed.current ) {
			return;
		}
		if ( 'next' === pressed.current && data.page >= data.pages ) {
			previous.current?.focus();
		} else if ( 'previous' === pressed.current && data.page <= 1 ) {
			next.current?.focus();
		}
		pressed.current = '';
	}, [ data, loading ] );

	const turn = ( direction ) => {
		if ( loading ) {
			return;
		}
		pressed.current = direction;
		setPage( data.page + ( 'next' === direction ? 1 : -1 ) );
	};

	useEffect( () => {
		if ( copy.text ) {
			fallback.current?.focus();
		}
	}, [ copy.text ] );

	const provider = config.provider;
	const layout = provider ? provider.csv : 'generic';
	const name = provider && provider.name ? provider.name : '';

	const copyAll = () => {
		setCopy( { busy: true, done: '', text: '' } );
		fetchPairs( source, 1, config.cap )
			.then( ( all ) => {
				const lines = pairsText( all.pairs );
				const manual = () => {
					setCopy( { busy: false, done: '', text: lines } );
					speak(
						__( 'Select the text and copy it.', 'hierarchy-guard' ),
						'polite'
					);
				};
				if (
					! window.navigator.clipboard ||
					! window.isSecureContext
				) {
					manual();
					return;
				}
				const done = copiedSentence( all.pairs.length, all.capped );
				window.navigator.clipboard
					.writeText( lines )
					.then( () => {
						setCopy( { busy: false, done, text: '' } );
						speak( done, 'polite' );
						window.setTimeout(
							() =>
								setCopy( ( state ) => ( {
									...state,
									done: '',
								} ) ),
							3000
						);
					} )
					.catch( manual );
			} )
			.catch( () => {
				setCopy( { busy: false, done: '', text: '' } );
				setError(
					__( 'The list couldn’t be copied.', 'hierarchy-guard' )
				);
			} );
	};

	let intro;
	if ( config.serving ) {
		intro = __(
			'These old URLs redirect to their new pages.',
			'hierarchy-guard'
		);
	} else if ( name ) {
		intro = sprintf(
			/* translators: %s: name of the redirect plugin. */
			__( 'Add these in %s.', 'hierarchy-guard' ),
			name
		);
	} else if ( provider ) {
		intro = __( 'Add these in your redirect plugin.', 'hierarchy-guard' );
	} else {
		intro = __(
			'Nothing on this site redirects these old URLs yet.',
			'hierarchy-guard'
		);
	}

	return (
		<Modal
			title={
				config.serving
					? __( 'Redirects', 'hierarchy-guard' )
					: __( 'Redirects needed', 'hierarchy-guard' )
			}
			onRequestClose={ onClose }
			className="hierarchy-guard-modal"
			size="medium"
		>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ ! data && loading && <Spinner /> }
			{ data && (
				<>
					{ data.total > 0 && <p>{ intro }</p> }
					{ data.total > 0 ? (
						<table
							className="widefat striped hierarchy-guard-pairs"
							aria-busy={ loading }
						>
							<caption className="screen-reader-text">
								{ __(
									'Old URLs and their new URLs',
									'hierarchy-guard'
								) }
							</caption>
							<thead>
								<tr>
									<th scope="col">
										{ __( 'Old URL', 'hierarchy-guard' ) }
									</th>
									<th scope="col">
										{ __( 'New URL', 'hierarchy-guard' ) }
									</th>
								</tr>
							</thead>
							<tbody>
								{ data.pairs.map( ( pair ) => (
									<tr key={ pair.old }>
										<td>
											<Url value={ pair.old } />
										</td>
										<td>
											<Url value={ pair.new } />
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					) : (
						<p>
							{ __(
								'No old URL needs a redirect.',
								'hierarchy-guard'
							) }
						</p>
					) }
					{ data.unresolved.map( ( row ) => (
						<p key={ row.old } className="description">
							{ unresolvedSentence( row, editor ) }
						</p>
					) ) }
					{ data.pages > 1 && (
						<div className="hierarchy-guard-modal__pager">
							<span>{ rangeLabel( data ) }</span>
							<Button
								ref={ previous }
								variant="secondary"
								size="compact"
								disabled={ data.page <= 1 }
								isBusy={ loading && page < data.page }
								onClick={ () => turn( 'previous' ) }
							>
								{ __( 'Previous', 'hierarchy-guard' ) }
							</Button>
							<Button
								ref={ next }
								variant="secondary"
								size="compact"
								disabled={ data.page >= data.pages }
								isBusy={ loading && page > data.page }
								onClick={ () => turn( 'next' ) }
							>
								{ __( 'Next', 'hierarchy-guard' ) }
							</Button>
						</div>
					) }
					{ copy.text && (
						<textarea
							ref={ fallback }
							className="large-text code"
							rows={ 6 }
							readOnly
							value={ copy.text }
							aria-label={ __(
								'Redirects as text',
								'hierarchy-guard'
							) }
							onFocus={ ( event ) => event.target.select() }
						/>
					) }
					<div className="hierarchy-guard-modal__actions">
						{ ! config.serving && provider && provider.url && (
							<ExternalLink href={ provider.url }>
								{ name
									? sprintf(
											/* translators: %s: name of the redirect plugin. */
											__( 'Open %s', 'hierarchy-guard' ),
											name
										)
									: __(
											'Open the redirect plugin',
											'hierarchy-guard'
										) }
							</ExternalLink>
						) }
						{ data.total > 0 && (
							<Button
								variant="tertiary"
								onClick={ copyAll }
								isBusy={ copy.busy }
								disabled={ copy.busy }
							>
								{ copy.done ||
									__( 'Copy as text', 'hierarchy-guard' ) }
							</Button>
						) }
						{ data.total > 0 && (
							<Button
								variant="secondary"
								href={ csvUrl( source, layout ) }
								download
							>
								{ name
									? sprintf(
											/* translators: %s: name of the redirect plugin. */
											__(
												'Download CSV for %s',
												'hierarchy-guard'
											),
											name
										)
									: __( 'Download CSV', 'hierarchy-guard' ) }
							</Button>
						) }
						{ ! config.serving &&
							! provider &&
							config.settingsUrl && (
								<Button
									variant="primary"
									href={ config.settingsUrl }
								>
									{ __(
										'Set up redirects',
										'hierarchy-guard'
									) }
								</Button>
							) }
					</div>
				</>
			) }
		</Modal>
	);
}
