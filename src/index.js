/**
 * Flinkform Pro — block-editor extensions entry.
 *
 * Docks Pro inspector panels onto the free core's form-container via the
 * `flinkform.formContainer.inspectorPanels` filter (the M-c-c seam). Built with
 * @wordpress/scripts; the compiled bundle + its asset manifest are enqueued by
 * FlinkformPro\Editor\Extensions on enqueue_block_editor_assets.
 *
 * @package FlinkformPro
 * @since 0.2.4
 */
import { addFilter } from '@wordpress/hooks';

import IntegrationsPanel from './integrations-panel';
import SpamPanel from './spam-panel';
import CustomCssPanel from './custom-css-panel';
import NewsletterPanel from './newsletter-panel';

/**
 * Append Pro inspector panels to the form-container inspector.
 *
 * @param {Array}  panels Panels collected so far (React elements).
 * @param {Object} props  Editing context: { attributes, setAttributes, clientId, formId, formFields }.
 * @return {Array} Panels including the Pro additions.
 */
addFilter(
	'flinkform.formContainer.inspectorPanels',
	'flinkform-pro/panels',
	( panels, props ) => [
		...panels,
		<SpamPanel
			key="flinkform-pro-spam"
			attributes={ props.attributes }
			setAttributes={ props.setAttributes }
		/>,
		<CustomCssPanel
			key="flinkform-pro-custom-css"
			attributes={ props.attributes }
			setAttributes={ props.setAttributes }
			formId={ props.formId }
		/>,
		<IntegrationsPanel
			key="flinkform-pro-integrations"
			formId={ props.formId }
			formFields={ props.formFields }
		/>,
		<NewsletterPanel
			key="flinkform-pro-newsletter"
			attributes={ props.attributes }
			setAttributes={ props.setAttributes }
			formFields={ props.formFields }
		/>,
	]
);
