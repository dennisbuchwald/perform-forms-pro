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
	const { label, required, helpText, fieldName, allowedTypes, maxSizeMb } = attributes;
	const blockProps = useBlockProps( { className: 'flinkform-field flinkform-field--file' } );

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
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				<label className="flinkform-field__label">
					{ label }
					{ required && <span className="flinkform-field__required" aria-hidden="true"> *</span> }
				</label>
				<input
					type="file"
					className="flinkform-field__input"
					disabled
					aria-disabled="true"
				/>
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
