/* Pratcom – Études de cas : project sheet meta box (results rows, client logo). */
( function ( $ ) {
	'use strict';

	var $box = $( '#pedc-sheet' );
	if ( ! $box.length ) {
		return;
	}

	// Results rows.
	function renumber() {
		$box.find( '.pedc-results-table tbody tr' ).each( function ( i ) {
			$( this ).find( 'input' ).each( function () {
				this.name = this.name.replace( /pedc\[results\]\[\d+\]/, 'pedc[results][' + i + ']' );
			} );
		} );
	}

	$box.on( 'click', '.pedc-row-add', function () {
		var $body = $box.find( '.pedc-results-table tbody' );
		if ( $body.children().length >= 8 ) {
			return;
		}
		var $row = $body.children().first().clone();
		$row.find( 'input' ).val( '' );
		$body.append( $row );
		renumber();
		$row.find( 'input' ).first().trigger( 'focus' );
	} );

	$box.on( 'click', '.pedc-row-remove', function () {
		var $body = $box.find( '.pedc-results-table tbody' );
		var $row = $( this ).closest( 'tr' );
		if ( $body.children().length > 1 ) {
			$row.remove();
		} else {
			$row.find( 'input' ).val( '' );
		}
		renumber();
	} );

	// Client logo.
	var frame;
	$box.on( 'click', '.pedc-logo__pick', function ( e ) {
		e.preventDefault();
		if ( ! frame ) {
			frame = wp.media( {
				title: window.pedcAdmin.chooseLogo,
				button: { text: window.pedcAdmin.useLogo },
				library: { type: 'image' },
				multiple: false
			} );
			frame.on( 'select', function () {
				var att = frame.state().get( 'selection' ).first().toJSON();
				var src = ( att.sizes && att.sizes.thumbnail ) ? att.sizes.thumbnail.url : att.url;
				$box.find( '.pedc-logo__id' ).val( att.id );
				$box.find( '.pedc-logo__preview' ).empty().append( $( '<img>', { src: src, alt: '' } ) );
				$box.find( '.pedc-logo__remove' ).prop( 'hidden', false );
			} );
		}
		frame.open();
	} );

	$box.on( 'click', '.pedc-logo__remove', function ( e ) {
		e.preventDefault();
		$box.find( '.pedc-logo__id' ).val( '' );
		$box.find( '.pedc-logo__preview' ).empty();
		$( this ).prop( 'hidden', true );
	} );
}( jQuery ) );
