/**
 * Field — File Upload (Pro) — editor component.
 */
import { useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	CheckboxControl,
	PanelBody,
	RangeControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';

// File-type presets shown as checkboxes. Keys are the canonical
// extensions persisted in `allowedTypes`; the server maps them to the
// matching mime types and rejects everything else.
const TYPE_PRESETS = [
	{ ext: 'pdf', label: 'PDF' },
	{ ext: 'jpg', label: 'JPG/JPEG' },
	{ ext: 'png', label: 'PNG' },
	{ ext: 'webp', label: 'WebP' },
	{ ext: 'gif', label: 'GIF' },
	{ ext: 'doc', label: 'DOC/DOCX' },
	{ ext: 'xls', label: 'XLS/XLSX' },
	{ ext: 'txt', label: 'TXT' },
	{ ext: 'csv', label: 'CSV' },
	{ ext: 'zip', label: 'ZIP' },
];

function generateFieldName( prefix ) {
	return `${ prefix }_${ Math.random().toString( 36 ).slice( 2, 8 ) }`;
}

export default function Edit( { attributes, setAttributes } ) {
	const { label, required, helpText, fieldName, allowedTypes, maxSizeMb, attachToEmail } = attributes;
	const blockProps = useBlockProps( { className: 'flinkform-field flinkform-field--file is-enhanced' } );

	useEffect( () => {
		if ( ! fieldName ) {
			setAttributes( { fieldName: generateFieldName( 'file' ) } );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const types = Array.isArray( allowedTypes ) ? allowedTypes : [];

	const toggleType = ( ext, checked ) => {
		const next = checked
			? [ ...types, ext ]
			: types.filter( ( t ) => t !== ext );
		setAttributes( { allowedTypes: next } );
	};

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Field Settings', 'flinkform-pro' ) }>
					<TextControl
						label={ __( 'Label', 'flinkform-pro' ) }
						value={ label }
						onChange={ ( v ) => setAttributes( { label: v } ) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<ToggleControl
						label={ __( 'Required', 'flinkform-pro' ) }
						checked={ !! required }
						onChange={ ( v ) => setAttributes( { required: v } ) }
						__nextHasNoMarginBottom
					/>
					<TextControl
						label={ __( 'Help Text', 'flinkform-pro' ) }
						value={ helpText }
						onChange={ ( v ) => setAttributes( { helpText: v } ) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<TextControl
						label={ __( 'Field Name', 'flinkform-pro' ) }
						help={ __( 'Key used in submission data. Auto-generated; change with care.', 'flinkform-pro' ) }
						value={ fieldName }
						onChange={ ( v ) => setAttributes( { fieldName: v } ) }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</PanelBody>
				<PanelBody title={ __( 'Allowed Files', 'flinkform-pro' ) }>
					{ TYPE_PRESETS.map( ( preset ) => (
						<CheckboxControl
							key={ preset.ext }
							label={ preset.label }
							checked={ types.includes( preset.ext ) }
							onChange={ ( checked ) => toggleType( preset.ext, checked ) }
							__nextHasNoMarginBottom
						/>
					) ) }
					{ types.length === 0 && (
						<p style={ { color: '#b32d2e' } }>
							{ __( 'Select at least one file type — with none selected, every upload is rejected.', 'flinkform-pro' ) }
						</p>
					) }
					<RangeControl
						label={ __( 'Maximum size (MB)', 'flinkform-pro' ) }
						value={ typeof maxSizeMb === 'number' ? maxSizeMb : 5 }
						onChange={ ( v ) => setAttributes( { maxSizeMb: v } ) }
						min={ 1 }
						max={ 64 }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<ToggleControl
						label={ __( 'Attach file to notification email', 'flinkform-pro' ) }
						help={ __( 'The uploaded file is attached to the admin notification (up to 8 MB; larger files arrive as a link). Combine with a Data Retention period on the form so the server copy cleans itself up.', 'flinkform-pro' ) }
						checked={ attachToEmail !== false }
						onChange={ ( v ) => setAttributes( { attachToEmail: v } ) }
						__nextHasNoMarginBottom
					/>
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<label className="flinkform-field__label">
					{ label }
					{ required && <span className="flinkform-field__required" aria-hidden="true"> *</span> }
				</label>
				<div className="flinkform-field__dropzone">
					<div className="flinkform-field__dropzone-idle" aria-hidden="true">
						<span className="flinkform-field__dropzone-icon">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" focusable="false">
								<path d="M21 15v3a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3v-3" />
								<path d="M12 16V4" />
								<path d="m7 9 5-5 5 5" />
							</svg>
						</span>
						<span className="flinkform-field__dropzone-text">
							<strong>{ __( 'Choose a file', 'flinkform-pro' ) }</strong>
							{ ' ' + __( 'or drag it here', 'flinkform-pro' ) }
						</span>
					</div>
				</div>
				<p className="flinkform-field__help">
					{ types.length > 0
						? sprintf(
							/* translators: 1: allowed extensions list, 2: max size in MB */
							__( 'Allowed: %1$s · max. %2$d MB', 'flinkform-pro' ),
							types.join( ', ' ).toUpperCase(),
							typeof maxSizeMb === 'number' ? maxSizeMb : 5
						)
						: __( 'No file types allowed yet', 'flinkform-pro' ) }
				</p>
				{ helpText && <p className="flinkform-field__help">{ helpText }</p> }
			</div>
		</>
	);
}
