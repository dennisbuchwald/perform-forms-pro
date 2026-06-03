/**
 * PerForm Pro — block-editor extensions.
 *
 * Docks onto the free core's editor extension points. As of slice M-c-c this
 * registers a single proof panel on the form-container inspector to demonstrate
 * the mechanism end to end; the real Pro panels (webhooks integrations,
 * conditional logic, multi-step) attach through the same filter from M-c-d on.
 *
 * Deliberately hand-written against the global `wp` object (no JSX, no build
 * step): the mechanism is identical whether the fill is built or vanilla, so we
 * keep the Pro plugin lean until a module ships JSX that warrants a pipeline.
 *
 * Contract: `perform.formContainer.inspectorPanels` — see the free core's
 * includes/Bridge/README.md (frozen once Pro ships).
 *
 * @package PerFormPro
 * @since 0.2.2
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.hooks || ! wp.element || ! wp.components || ! wp.i18n ) {
		return;
	}

	var el = wp.element.createElement;
	var PanelBody = wp.components.PanelBody;
	var __ = wp.i18n.__;

	/**
	 * Append Pro panels to the form-container inspector.
	 *
	 * @param {Array}  panels Panels collected so far (React elements).
	 * @param {Object} props  Editing context: attributes, setAttributes, clientId, formId, formFields.
	 * @return {Array} Panels including the Pro additions.
	 */
	function addProPanels( panels, props ) {
		var proof = el(
			PanelBody,
			{
				title: __( 'PerForm Pro', 'perform-forms-pro' ),
				initialOpen: false,
			},
			el(
				'p',
				null,
				__(
					'PerForm Pro is active and docked onto this form. Conditional logic, multi-step and webhook panels attach here in upcoming releases.',
					'perform-forms-pro'
				)
			)
		);

		return panels.concat( [ proof ] );
	}

	wp.hooks.addFilter(
		'perform.formContainer.inspectorPanels',
		'perform-forms-pro/proof-panel',
		addProPanels
	);
} )( window.wp );
