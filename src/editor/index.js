/**
 * The Hierarchy panel in the block editor's document settings.
 */
import { registerPlugin } from '@wordpress/plugins';
import HierarchyPanel from './panel';
import '../common/modal.scss';
import './editor.scss';

registerPlugin( 'hierarchy-guard', { render: HierarchyPanel } );
