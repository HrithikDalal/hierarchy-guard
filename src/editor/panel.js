/**
 * The Hierarchy plugin for the block editor: a warning under the Summary
 * before a save changes subpage URLs, a notice after it did, and a panel
 * with what is above and below the page.
 */
import {
	BaseControl,
	Button,
	Notice,
	Spinner,
	VisuallyHidden,
} from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import {
	PluginDocumentSettingPanel,
	PluginPostStatusInfo,
	store as editorStore,
} from '@wordpress/editor';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';
import { fetchHierarchy, fetchPairs } from '../common/api';
import config from '../common/config';
import {
	changedSentence,
	changedStatus,
	countsLine,
	oldUrlState,
	saveWarning,
	subpagesLabel,
	trashNote,
	viewAllLabel,
	viewRedirectsLabel,
} from '../common/copy';
import RedirectsModal from '../common/redirects-modal';
import Url from '../common/url';
import { decodePath } from '../common/url-parts';

const PANEL = 'hierarchy-guard/hierarchy-guard';
const NOTICE = 'hierarchy-guard-urls-changed';

/**
 * A title linked to its editor when the user may edit it.
 *
 * @param {Object} props      Props.
 * @param {Object} props.item Post reference from the REST route.
 * @return {Element} Link or text.
 */
function Title( { item } ) {
	return item.edit_link ? (
		<a href={ item.edit_link }>{ item.title }</a>
	) : (
		<span>{ item.title }</span>
	);
}

/**
 * A section heading with core's label styling.
 *
 * @param {Object}  props          Props.
 * @param {Element} props.children Text.
 * @return {Element} Heading.
 */
function Heading( { children } ) {
	return (
		<BaseControl.VisualLabel
			as="h3"
			className="hierarchy-guard-panel__heading"
		>
			{ children }
		</BaseControl.VisualLabel>
	);
}

/**
 * What the panel shows once the hierarchy is loaded.
 *
 * @param {Object}                 props        Props.
 * @param {Object}                 props.data   Hierarchy from the REST route.
 * @param {(from: string) => void} props.onView Opens the redirects of an old path.
 * @return {Element} Sections.
 */
function Overview( { data, onView } ) {
	const trash = trashNote( data );
	return (
		<div className="hierarchy-guard-panel__body">
			{ data.ancestors.length > 1 && (
				<div className="hierarchy-guard-panel__section">
					<Heading>
						{ __( 'Parent pages', 'hierarchy-guard' ) }
					</Heading>
					<p>
						{ data.ancestors.map( ( item, index ) => (
							<span key={ item.id }>
								{ index > 0 && (
									<span aria-hidden="true"> › </span>
								) }
								<Title item={ item } />
							</span>
						) ) }
					</p>
				</div>
			) }

			<div className="hierarchy-guard-panel__section">
				<Heading>{ __( 'Subpages', 'hierarchy-guard' ) }</Heading>
				{ 0 === data.children_total ? (
					<p>{ __( 'No subpages.', 'hierarchy-guard' ) }</p>
				) : (
					<>
						<p className="description">{ countsLine( data ) }</p>
						<ul className="hierarchy-guard-panel__list">
							{ data.children.map( ( child ) => (
								<li key={ child.id }>
									<Title item={ child } />
									{ 'publish' !== child.status &&
										' — ' + child.status_label }
									{ child.children_count > 0 &&
										' · ' +
											subpagesLabel(
												child.children_count,
												child.children_capped
											) }
								</li>
							) ) }
						</ul>
						{ ( data.has_more ||
							data.descendants.capped ||
							data.descendants.count > data.children.length ) && (
							<p>
								<a href={ data.list_url }>
									{ viewAllLabel(
										data.descendants.count,
										data.descendants.capped
									) }
								</a>
							</p>
						) }
					</>
				) }
			</div>

			{ data.old_paths.length > 0 && (
				<div className="hierarchy-guard-panel__section">
					<Heading>{ __( 'Old URLs', 'hierarchy-guard' ) }</Heading>
					<ul className="hierarchy-guard-panel__list">
						{ data.old_paths.map( ( old ) => (
							<li key={ old.path }>
								<Url value={ old.url } />
								<span className="hierarchy-guard-panel__meta">
									{ oldUrlState(
										data.redirects.serving,
										data.redirects.provider,
										old.state
									) }
									{ ' · ' }
									<Button
										variant="link"
										onClick={ () => onView( old.path ) }
									>
										<span aria-hidden="true">
											{ __(
												'View redirects',
												'hierarchy-guard'
											) }
										</span>
										<VisuallyHidden as="span">
											{ sprintf(
												/* translators: %s: an old URL, such as /about-us/. Starts with the visible button text "View redirects". */
												__(
													'View redirects for %s',
													'hierarchy-guard'
												),
												decodePath( old.url )
											) }
										</VisuallyHidden>
									</Button>
								</span>
							</li>
						) ) }
					</ul>
				</div>
			) }

			{ trash && (
				<p className="hierarchy-guard-panel__note">
					{ trash }{ ' ' }
					{ config.policyUrl && (
						<a href={ config.policyUrl }>
							{ __(
								'Change this in Settings',
								'hierarchy-guard'
							) }
						</a>
					) }
				</p>
			) }
		</div>
	);
}

/**
 * The plugin.
 *
 * @return {Element|null} Its parts, or null for unsupported types.
 */
export default function HierarchyPlugin() {
	const {
		postId,
		postType,
		status,
		urlEdited,
		saving,
		autosaving,
		saved,
		opened,
	} = useSelect( ( select ) => {
		const editor = select( editorStore );
		return {
			postId: editor.getCurrentPostId(),
			postType: editor.getCurrentPostType(),
			status: editor.getCurrentPostAttribute( 'status' ),
			urlEdited:
				editor.getEditedPostAttribute( 'slug' ) !==
					editor.getCurrentPostAttribute( 'slug' ) ||
				( editor.getEditedPostAttribute( 'parent' ) || 0 ) !==
					( editor.getCurrentPostAttribute( 'parent' ) || 0 ),
			saving: editor.isSavingPost(),
			autosaving: editor.isAutosavingPost(),
			saved: editor.didPostSaveRequestSucceed(),
			opened: editor.isEditorPanelOpened( PANEL ),
		};
	}, [] );
	const { createNotice, removeNotice } = useDispatch( noticesStore );

	const supported = config.postTypes.includes( postType );
	const published = 'publish' === status;
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ modal, setModal ] = useState( null );
	const requested = useRef( false );
	const newest = useRef( undefined );
	const savingUrl = useRef( false );

	const load = useCallback(
		() =>
			fetchHierarchy( postId )
				.then( ( result ) => {
					setData( result );
					setError( '' );
					if ( undefined === newest.current ) {
						newest.current = result.old_paths[ 0 ]
							? result.old_paths[ 0 ].path
							: null;
					}
					return result;
				} )
				.catch( ( failure ) => {
					setError(
						failure.message ||
							__(
								'The hierarchy couldn’t be loaded.',
								'hierarchy-guard'
							)
					);
					return null;
				} ),
		[ postId ]
	);

	// Load once something shows the data: the panel, or the warning before a save.
	const needed =
		supported && !! postId && ( opened || ( published && urlEdited ) );
	useEffect( () => {
		if ( needed && ! requested.current ) {
			requested.current = true;
			load().then( ( result ) => {
				// A failed first load is asked again at the next trigger.
				if ( ! result ) {
					requested.current = false;
				}
			} );
		}
	}, [ needed, load ] );

	// A save that changed the slug or parent may have recorded an old URL.
	useEffect( () => {
		if ( saving && ! autosaving ) {
			savingUrl.current = savingUrl.current || ( published && urlEdited );
			return;
		}
		if ( saving || ! savingUrl.current ) {
			return;
		}
		savingUrl.current = false;
		if ( ! saved ) {
			return;
		}
		removeNotice( NOTICE );
		// Without a baseline, any old URL on the page is new to this session.
		const baseline = newest.current;
		load().then( ( result ) => {
			const latest =
				result && result.old_paths[ 0 ]
					? result.old_paths[ 0 ].path
					: null;
			if ( ! latest || latest === baseline ) {
				return;
			}
			newest.current = latest;
			fetchPairs( { post: postId, from: latest }, 1, 1 )
				.then( ( pairs ) => {
					if ( ! pairs.total ) {
						return;
					}
					const { serving, provider } = result.redirects;
					const actions = [];
					if ( ! serving ) {
						actions.push( {
							label: viewRedirectsLabel(
								pairs.total,
								pairs.capped
							),
							onClick: () =>
								setModal( { post: postId, from: latest } ),
						} );
					}
					if ( ! serving && ! provider && config.settingsUrl ) {
						actions.push( {
							label: __( 'Set up redirects', 'hierarchy-guard' ),
							url: config.settingsUrl,
						} );
					}
					createNotice(
						changedStatus( serving, provider ),
						changedSentence(
							pairs.total,
							pairs.capped,
							serving,
							provider
						),
						{ id: NOTICE, isDismissible: true, actions }
					);
				} )
				.catch( () => {
					createNotice(
						'warning',
						__(
							'This save changed URLs, but their redirects couldn’t be loaded.',
							'hierarchy-guard'
						),
						{
							id: NOTICE,
							isDismissible: true,
							actions: [
								{
									label: __(
										'View redirects',
										'hierarchy-guard'
									),
									onClick: () =>
										setModal( {
											post: postId,
											from: latest,
										} ),
								},
							],
						}
					);
				} );
		} );
	}, [
		saving,
		autosaving,
		saved,
		published,
		urlEdited,
		load,
		postId,
		createNotice,
		removeNotice,
	] );

	if ( ! supported ) {
		return null;
	}

	return (
		<>
			{ data && published && urlEdited && data.descendants.count > 0 && (
				<PluginPostStatusInfo className="hierarchy-guard-save-warning">
					<Notice status="warning" isDismissible={ false }>
						{ saveWarning(
							data.descendants.count,
							data.descendants.capped,
							data.redirects.serving
						) }
					</Notice>
				</PluginPostStatusInfo>
			) }
			<PluginDocumentSettingPanel
				name="hierarchy-guard"
				title={ __( 'Hierarchy', 'hierarchy-guard' ) }
				className="hierarchy-guard-panel"
			>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				{ ! data && ! error && <Spinner /> }
				{ data && (
					<Overview
						data={ data }
						onView={ ( from ) =>
							setModal( { post: postId, from } )
						}
					/>
				) }
			</PluginDocumentSettingPanel>
			{ modal && (
				<RedirectsModal
					source={ modal }
					editor
					onClose={ () => setModal( null ) }
				/>
			) }
		</>
	);
}
