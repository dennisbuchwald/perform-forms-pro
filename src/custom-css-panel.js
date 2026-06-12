/**
 * Custom CSS inspector panel (Pro).
 *
 * Allows form authors to add scoped CSS rules per form. The attribute is
 * dynamically registered by FlinkformPro\CustomCss\Module on the server side;
 * the render output is handled via a render_block filter.
 *
 * @package FlinkformPro
 * @since 0.2.10
 */
import { __ } from '@wordpress/i18n';
import { PanelBody, TextareaControl } from '@wordpress/components';
import { Fragment } from '@wordpress/element';

export default function CustomCssPanel( { attributes, setAttributes, formId } ) {
	const customCSS = attributes.customCSS ?? '';

	return (
		<Fragment>
			<PanelBody
				title={ __( 'Custom CSS', 'flinkform-pro' ) }
				initialOpen={ false }
			>
				<TextareaControl
					label={ __( 'CSS rules', 'flinkform-pro' ) }
					help={ __( 'Scope rules to this form by prefixing selectors with [data-flinkform-id="<id>"]. Otherwise the rules apply to every form on the page.', 'flinkform-pro' ) }
					value={ customCSS }
					onChange={ ( value ) => setAttributes( { customCSS: value } ) }
					rows={ 10 }
					className="flinkform-custom-css-input"
					__nextHasNoMarginBottom
				/>
				{ formId && (
					<p style={ { fontSize: '12px', opacity: 0.7, marginTop: '8px' } }>
						{ __( 'Form ID for scoping:', 'flinkform-pro' ) }
						<br />
						<code style={ { userSelect: 'all', wordBreak: 'break-all' } }>
							{ `[data-flinkform-id="${ formId }"]` }
						</code>
					</p>
				) }
			</PanelBody>
			{ customCSS && (
				<style dangerouslySetInnerHTML={ { __html: customCSS } } />
			) }
		</Fragment>
	);
}
