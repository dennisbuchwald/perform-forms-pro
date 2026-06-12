/**
 * Field — File Upload (Pro) — block registration entry.
 */
import { registerBlockType } from '@wordpress/blocks';
import { addFilter } from '@wordpress/hooks';

import metadata from './block.json';
import Edit from './edit';

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );

// Dock onto the free core's allowed-blocks seam so the block is
// insertable inside the Form container.
addFilter(
	'flinkform.formContainer.allowedBlocks',
	'flinkform-pro/field-file',
	( blocks ) =>
		Array.isArray( blocks ) && ! blocks.includes( metadata.name )
			? [ ...blocks, metadata.name ]
			: blocks
);
