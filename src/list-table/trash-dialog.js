/**
 * The trash dialog: one sentence under the page's policy, and the other two
 * policies behind "Other options".
 */
import { Button, Modal, Notice, Spinner } from '@wordpress/components';
import {
	createInterpolateElement,
	useEffect,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { chevronDown, chevronUp } from '@wordpress/icons';
import { trashPreview } from '../common/api';
import config from '../common/config';
import {
	POLICIES,
	bulkSentence,
	deniedSentence,
	dialogTitle,
	initialSentence,
	keptUrl,
	optionDescription,
	optionLabel,
	previewError,
	trashSentence,
} from '../common/copy';
import Url from '../common/url';

export const MAX_PREVIEW = 50;

/**
 * An option's description, with any example URLs in code.
 *
 * @param {Object}      props        Props.
 * @param {string}      props.policy Policy.
 * @param {Object|null} props.page   The only page with subpages, or null.
 * @return {Element} Description.
 */
function Description( { policy, page } ) {
	const text = optionDescription( policy, page, config.cap );
	if ( ! page || ! page.example || ! text.includes( '<from />' ) ) {
		return text;
	}
	const to = 'keep' === policy ? keptUrl( page ) : page.example.to;
	return createInterpolateElement( text, {
		from: <Url value={ page.example.from } />,
		to: <Url value={ to } />,
	} );
}

/**
 * The dialog.
 *
 * @param {Object}                        props              Props.
 * @param {number[]}                      props.ids          Selected pages with subpages, at most MAX_PREVIEW.
 * @param {number}                        props.selected     Pages selected in all.
 * @param {number}                        props.withChildren Selected pages with subpages, counted on the screen.
 * @param {string}                        props.title        The row's title, for a single page.
 * @param {number}                        props.children     The row's direct subpages, for a single page.
 * @param {() => void}                    props.onCancel     Closes the dialog.
 * @param {(policy: string|null) => void} props.onConfirm    Trashes; null keeps the page's own policy.
 * @return {Element} Modal.
 */
export default function TrashDialog( {
	ids,
	selected,
	withChildren,
	title,
	children,
	onCancel,
	onConfirm,
} ) {
	const [ preview, setPreview ] = useState( null );
	const [ failed, setFailed ] = useState( false );
	const [ initial, setInitial ] = useState( config.policy );
	const [ policy, setPolicy ] = useState( config.policy );
	const [ expanded, setExpanded ] = useState( false );
	const [ busy, setBusy ] = useState( false );

	useEffect( () => {
		let live = true;
		trashPreview( ids )
			.then( ( result ) => {
				if ( ! live ) {
					return;
				}
				if ( ! result.pages.length ) {
					// The subpages are gone since the list loaded: nothing to ask.
					setBusy( true );
					onConfirm( null );
					return;
				}
				const own =
					1 === selected && 1 === result.pages.length
						? result.pages[ 0 ].policy
						: result.policy;
				setInitial( own );
				setPolicy( own );
				setPreview( result );
			} )
			.catch( () => {
				if ( live ) {
					setFailed( true );
				}
			} );
		return () => {
			live = false;
		};
		// The dialog asks once, for the pages it opened with.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const single =
		preview && 1 === selected && 1 === preview.pages.length
			? preview.pages[ 0 ]
			: null;

	let sentence;
	if ( single ) {
		sentence = trashSentence( single, policy, config.cap );
	} else if ( preview ) {
		sentence = bulkSentence(
			{
				...preview,
				more: preview.more + Math.max( 0, withChildren - ids.length ),
			},
			policy,
			withChildren,
			config.cap
		);
	} else if ( 1 === selected ) {
		sentence = initialSentence( title, children, policy );
	} else {
		sentence = '';
	}

	const denied = preview
		? preview.pages.some( ( page ) =>
				'trash_children' === policy ? page.denied_trash : page.denied
			)
		: false;

	const confirm = () => {
		setBusy( true );
		onConfirm( policy !== initial ? policy : null );
	};

	return (
		<Modal
			title={ dialogTitle( selected ) }
			onRequestClose={ busy ? () => {} : onCancel }
			isDismissible={ ! busy }
			shouldCloseOnEsc={ ! busy }
			shouldCloseOnClickOutside={ ! busy }
			className="hierarchy-guard-dialog"
			size="medium"
			aria={ { describedby: 'hierarchy-guard-trash-sentence' } }
		>
			<div id="hierarchy-guard-trash-sentence">
				{ sentence && <p>{ sentence }</p> }
				{ denied && <p>{ deniedSentence( policy ) }</p> }
			</div>
			{ ! failed && ! preview && (
				<p className="hierarchy-guard-dialog__loading">
					<Spinner />
					{ __( 'Checking the subpages…', 'hierarchy-guard' ) }
				</p>
			) }
			{ failed && (
				<Notice status="warning" isDismissible={ false }>
					{ previewError( policy ) }
				</Notice>
			) }
			{ preview && (
				<>
					<Button
						variant="link"
						icon={ expanded ? chevronUp : chevronDown }
						iconPosition="right"
						aria-expanded={ expanded }
						aria-controls="hierarchy-guard-options"
						onClick={ () => setExpanded( ! expanded ) }
					>
						{ __( 'Other options', 'hierarchy-guard' ) }
					</Button>
					<fieldset
						id="hierarchy-guard-options"
						className="hierarchy-guard-dialog__options"
						hidden={ ! expanded }
					>
						<legend className="screen-reader-text">
							{ __(
								'What happens to the subpages',
								'hierarchy-guard'
							) }
						</legend>
						{ POLICIES.map( ( value ) => {
							const id = `hierarchy-guard-policy-${ value }`;
							const disabled =
								'trash_children' === value &&
								!! single &&
								true === single.fallback;
							return (
								<div
									key={ value }
									className="hierarchy-guard-dialog__option"
								>
									<input
										type="radio"
										id={ id }
										name="hierarchy-guard-policy"
										value={ value }
										checked={ policy === value }
										disabled={ disabled || busy }
										aria-describedby={ `${ id }-description` }
										onChange={ () => setPolicy( value ) }
									/>
									<label htmlFor={ id }>
										{ optionLabel( value, single ) }
									</label>
									<p
										id={ `${ id }-description` }
										className="description"
									>
										<Description
											policy={ value }
											page={ single }
										/>
									</p>
								</div>
							);
						} ) }
					</fieldset>
				</>
			) }
			<div className="hierarchy-guard-modal__actions">
				<Button
					variant="tertiary"
					onClick={ onCancel }
					disabled={ busy }
				>
					{ __( 'Cancel', 'hierarchy-guard' ) }
				</Button>
				<Button
					variant="primary"
					isDestructive
					isBusy={ busy }
					disabled={ ( ! preview && ! failed ) || busy }
					onClick={ confirm }
				>
					{ __( 'Move to Trash', 'hierarchy-guard' ) }
				</Button>
			</div>
		</Modal>
	);
}
