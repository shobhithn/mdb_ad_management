/**
 * MDB Ad Management: shows links marked with data-lightbox as a full-screen image.
 * Click anywhere or press Esc to close.
 */
jQuery( function ( $ ) {
	function close() {
		$( '.lb-overlay' ).remove();
		$( document ).off( 'keydown.mdbLightbox' );
	}

	$( document ).on( 'click', 'a[data-lightbox]', function ( event ) {
		event.preventDefault();
		close();

		var $image = $( '<img>' ).attr( {
			src: this.href,
			alt: $( this ).find( 'img' ).attr( 'alt' ) || ''
		} );

		$( '<div class="lb-overlay"><div class="lb-wrapper"><div class="lb-content"></div></div></div>' )
			.find( '.lb-content' ).append( $image ).end()
			.on( 'click', close )
			.appendTo( 'body' );

		$( document ).on( 'keydown.mdbLightbox', function ( e ) {
			if ( 'Escape' === e.key ) {
				close();
			}
		} );
	} );
} );
