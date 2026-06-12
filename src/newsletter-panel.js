/**
 * Newsletter inspector panel (Pro).
 *
 * Per-form signup config for Brevo / Mailchimp / CleverReach. The matching
 * `newsletter` attribute is registered server-side by
 * FlinkformPro\Newsletter\Module; global API credentials live on the
 * Flinkform → Newsletter settings page.
 *
 * Consent is mandatory by design: without a mapped consent field the
 * server never forwards a signup, so the panel pushes hard towards
 * selecting one.
 *
 * @package FlinkformPro
 * @since 0.4.0
 */
import { __ } from '@wordpress/i18n';
import { Notice, PanelBody, SelectControl, TextControl, ToggleControl } from '@wordpress/components';

const PROVIDERS = [
	{ value: 'brevo', label: 'Brevo' },
	{ value: 'mailchimp', label: 'Mailchimp' },
	{ value: 'cleverreach', label: 'CleverReach' },
];

const LIST_LABELS = {
	brevo: __( 'List ID (number)', 'flinkform-pro' ),
	mailchimp: __( 'Audience ID', 'flinkform-pro' ),
	cleverreach: __( 'Group ID', 'flinkform-pro' ),
};

export default function NewsletterPanel( { attributes, setAttributes, formFields } ) {
	const config = attributes.newsletter ?? {};
	const update = ( patch ) => setAttributes( { newsletter: { ...config, ...patch } } );

	const fields = Array.isArray( formFields ) ? formFields : [];
	const fieldOptions = ( placeholder ) => [
		{ value: '', label: placeholder },
		...fields.map( ( f ) => ( {
			value: f.name,
			label: f.label ? `${ f.label } (${ f.name })` : f.name,
		} ) ),
	];

	const enabled = !! config.enabled;
	const provider = config.provider ?? 'brevo';

	return (
		<PanelBody title={ __( 'Newsletter', 'flinkform-pro' ) } initialOpen={ false }>
			<ToggleControl
				label={ __( 'Subscribe to a newsletter list', 'flinkform-pro' ) }
				checked={ enabled }
				onChange={ ( v ) => update( { enabled: v } ) }
				__nextHasNoMarginBottom
			/>
			{ enabled && (
				<>
					<SelectControl
						label={ __( 'Provider', 'flinkform-pro' ) }
						value={ provider }
						options={ PROVIDERS }
						onChange={ ( v ) => update( { provider: v } ) }
						help={ __( 'API credentials are configured under Flinkform → Newsletter.', 'flinkform-pro' ) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<TextControl
						label={ LIST_LABELS[ provider ] ?? __( 'List ID', 'flinkform-pro' ) }
						value={ config.listId ?? '' }
						onChange={ ( v ) => update( { listId: v } ) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<SelectControl
						label={ __( 'Email field', 'flinkform-pro' ) }
						value={ config.emailField ?? '' }
						options={ fieldOptions( __( '— Select the email field —', 'flinkform-pro' ) ) }
						onChange={ ( v ) => update( { emailField: v } ) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<SelectControl
						label={ __( 'Consent field (required)', 'flinkform-pro' ) }
						value={ config.consentField ?? '' }
						options={ fieldOptions( __( '— Select the consent field —', 'flinkform-pro' ) ) }
						onChange={ ( v ) => update( { consentField: v } ) }
						help={ __( 'Visitors are only subscribed when they tick this field. Without a mapping, nothing is ever sent — GDPR-safe by design.', 'flinkform-pro' ) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					{ ! config.consentField && (
						<Notice status="warning" isDismissible={ false }>
							{ __( 'No consent field mapped — signups are disabled. Add a Toggle or Consent field (e.g. "Subscribe me to the newsletter") and select it above.', 'flinkform-pro' ) }
						</Notice>
					) }
					<SelectControl
						label={ __( 'First name field (optional)', 'flinkform-pro' ) }
						value={ config.firstNameField ?? '' }
						options={ fieldOptions( __( '— None —', 'flinkform-pro' ) ) }
						onChange={ ( v ) => update( { firstNameField: v } ) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<SelectControl
						label={ __( 'Last name field (optional)', 'flinkform-pro' ) }
						value={ config.lastNameField ?? '' }
						options={ fieldOptions( __( '— None —', 'flinkform-pro' ) ) }
						onChange={ ( v ) => update( { lastNameField: v } ) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<ToggleControl
						label={ __( 'Double opt-in', 'flinkform-pro' ) }
						checked={ !! config.doubleOptIn }
						onChange={ ( v ) => update( { doubleOptIn: v } ) }
						help={
							'cleverreach' === provider
								? __( 'Sends the activation email of the DOI form configured under Flinkform → Newsletter.', 'flinkform-pro' )
								: 'mailchimp' === provider
									? __( 'New contacts are created as "pending" and Mailchimp sends its confirmation email.', 'flinkform-pro' )
									: __( 'For Brevo, configure double opt-in inside Brevo (automation on list entry).', 'flinkform-pro' )
						}
						__nextHasNoMarginBottom
					/>
				</>
			) }
		</PanelBody>
	);
}
