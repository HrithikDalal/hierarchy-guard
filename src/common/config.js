/**
 * The plugin's settings, printed by Admin\Assets before each bundle.
 */
const defaults = {
	namespace: 'hierarchy-guard/v1',
	serving: false,
	provider: null,
	settingsUrl: '',
	policyUrl: '',
	policy: 'reparent',
	postTypes: [],
	cap: 1000,
	locale: '',
	restNonce: '',
	restUrl: '/wp-json/',
};

export default { ...defaults, ...( globalThis.hierarchyGuard || {} ) };
