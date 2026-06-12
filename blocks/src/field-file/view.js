/**
 * File Upload field — frontend enhancement (Pro).
 *
 * Upgrades the native file input to a dropzone: shows the selected file
 * as a card (name + size + remove), highlights on drag-over, and keeps
 * everything functional without JavaScript (the enhancement only kicks
 * in once this script adds `.is-enhanced`).
 *
 * All visible strings are rendered (translated) by render.php; this
 * script only toggles classes and fills in name/size.
 */

( function () {
	function formatSize( bytes ) {
		if ( ! bytes || bytes <= 0 ) {
			return '';
		}
		if ( bytes < 1024 * 1024 ) {
			return Math.max( 1, Math.round( bytes / 1024 ) ) + ' KB';
		}
		return ( bytes / ( 1024 * 1024 ) ).toFixed( 1 ).replace( '.0', '' ) + ' MB';
	}

	function enhance( field ) {
		const zone  = field.querySelector( '[data-flinkform-dropzone]' );
		const input = zone ? zone.querySelector( 'input[type="file"]' ) : null;
		if ( ! zone || ! input ) {
			return;
		}

		const nameEl   = zone.querySelector( '[data-flinkform-file-name]' );
		const sizeEl   = zone.querySelector( '[data-flinkform-file-size]' );
		const removeEl = zone.querySelector( '[data-flinkform-file-remove]' );

		field.classList.add( 'is-enhanced' );

		const update = () => {
			const file = input.files && input.files[ 0 ] ? input.files[ 0 ] : null;
			field.classList.toggle( 'has-file', !! file );
			if ( nameEl ) {
				nameEl.textContent = file ? file.name : '';
			}
			if ( sizeEl ) {
				sizeEl.textContent = file ? formatSize( file.size ) : '';
			}
		};

		input.addEventListener( 'change', update );

		// Drag-over highlight — the drop itself is handled natively by
		// the input element that covers the whole zone.
		[ 'dragenter', 'dragover' ].forEach( ( type ) =>
			input.addEventListener( type, () => zone.classList.add( 'is-dragover' ) )
		);
		[ 'dragleave', 'drop' ].forEach( ( type ) =>
			input.addEventListener( type, () => zone.classList.remove( 'is-dragover' ) )
		);

		if ( removeEl ) {
			removeEl.addEventListener( 'click', () => {
				input.value = '';
				update();
				input.focus();
			} );
		}

		update();
	}

	function init() {
		document.querySelectorAll( '.flinkform-field--file' ).forEach( enhance );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
