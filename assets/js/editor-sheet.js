/* Pratcom – Études de cas : "Project sheet" panel in the block editor sidebar (no build step). */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var __ = wp.i18n.__;
	var c = wp.components;
	var useSelect = wp.data.useSelect;
	var useEntityProp = wp.coreData.useEntityProp;
	var be = wp.blockEditor;
	var Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	var POST_TYPE = 'etude_de_cas';
	var MAX_RESULTS = 8;

	if ( ! Panel || ! wp.plugins ) {
		return;
	}

	function SheetPanel() {
		var postType = useSelect( function ( s ) { return s( 'core/editor' ).getCurrentPostType(); }, [] );
		var entity = useEntityProp( 'postType', POST_TYPE, 'meta' );
		var meta = entity[ 0 ] || {};
		var setMeta = entity[ 1 ];
		var logoId = meta._pedc_client_logo || 0;
		var logo = useSelect( function ( s ) { return logoId ? s( 'core' ).getMedia( logoId ) : null; }, [ logoId ] );

		if ( postType !== POST_TYPE ) {
			return null;
		}

		function set( key ) {
			return function ( value ) {
				var patch = {};
				patch[ key ] = value;
				setMeta( Object.assign( {}, meta, patch ) );
			};
		}

		var results = Array.isArray( meta._pedc_results ) ? meta._pedc_results : [];
		function setResults( rows ) { set( '_pedc_results' )( rows ); }
		function setRow( i, key, value ) {
			var rows = results.map( function ( r ) { return Object.assign( {}, r ); } );
			rows[ i ][ key ] = value;
			setResults( rows );
		}

		var logoUrl = logo && ( ( logo.media_details && logo.media_details.sizes && logo.media_details.sizes.thumbnail && logo.media_details.sizes.thumbnail.source_url ) || logo.source_url );

		return el( Panel, { name: 'pedc-sheet-panel', title: __( 'Project sheet', 'pratcom-etudes-de-cas' ), className: 'pedc-sheet-panel' },
			el( c.TextControl, { __nextHasNoMarginBottom: true, __next40pxDefaultSize: true, label: __( 'Client', 'pratcom-etudes-de-cas' ), value: meta._pedc_client || '', onChange: set( '_pedc_client' ), placeholder: __( 'e.g. Diesel Spec Inc.', 'pratcom-etudes-de-cas' ) } ),
			el( 'div', { style: { height: 12 } } ),
			el( c.TextControl, { __nextHasNoMarginBottom: true, __next40pxDefaultSize: true, type: 'url', label: __( 'Client website', 'pratcom-etudes-de-cas' ), value: meta._pedc_client_url || '', onChange: set( '_pedc_client_url' ), placeholder: 'https://' } ),
			el( 'div', { style: { height: 12 } } ),
			el( c.TextControl, { __nextHasNoMarginBottom: true, __next40pxDefaultSize: true, label: __( 'Period', 'pratcom-etudes-de-cas' ), value: meta._pedc_period || '', onChange: set( '_pedc_period' ), placeholder: __( 'e.g. 2024 – 2026', 'pratcom-etudes-de-cas' ) } ),

			el( 'div', { className: 'pedc-panel__label' }, __( 'Client logo', 'pratcom-etudes-de-cas' ) ),
			logoUrl ? el( 'img', { src: logoUrl, alt: '', style: { display: 'block', maxWidth: 120, maxHeight: 60, marginBottom: 8, background: '#f6f7f7' } } ) : null,
			el( be.MediaUploadCheck, {},
				el( be.MediaUpload, {
					allowedTypes: [ 'image' ],
					value: logoId,
					onSelect: function ( media ) { set( '_pedc_client_logo' )( media.id ); },
					render: function ( o ) {
						return el( Fragment, {},
							el( c.Button, { variant: 'secondary', onClick: o.open, size: 'compact' }, logoId ? __( 'Replace', 'pratcom-etudes-de-cas' ) : __( 'Choose…', 'pratcom-etudes-de-cas' ) ),
							logoId ? el( c.Button, { variant: 'link', isDestructive: true, onClick: function () { set( '_pedc_client_logo' )( 0 ); }, style: { marginLeft: 8 } }, __( 'Remove', 'pratcom-etudes-de-cas' ) ) : null
						);
					}
				} )
			),

			el( 'div', { className: 'pedc-panel__label' }, __( 'Key results', 'pratcom-etudes-de-cas' ) ),
			el( 'div', { className: 'pedc-panel__help' }, __( 'The first one is shown on the cards.', 'pratcom-etudes-de-cas' ) ),
			results.map( function ( r, i ) {
				return el( 'div', { key: i, className: 'pedc-panel__row' },
					el( c.TextControl, { __nextHasNoMarginBottom: true, __next40pxDefaultSize: true, label: __( 'Figure', 'pratcom-etudes-de-cas' ), hideLabelFromVision: i > 0, value: r.value || '', onChange: function ( v ) { setRow( i, 'value', v ); }, placeholder: '+212 %' } ),
					el( c.TextControl, { __nextHasNoMarginBottom: true, __next40pxDefaultSize: true, label: __( 'Description', 'pratcom-etudes-de-cas' ), hideLabelFromVision: i > 0, value: r.label || '', onChange: function ( v ) { setRow( i, 'label', v ); }, placeholder: __( 'organic traffic in 12 months', 'pratcom-etudes-de-cas' ) } ),
					el( c.Button, { icon: 'no-alt', label: __( 'Remove this result', 'pratcom-etudes-de-cas' ), isDestructive: true, size: 'small', onClick: function () { setResults( results.filter( function ( x, j ) { return j !== i; } ) ); } } )
				);
			} ),
			results.length < MAX_RESULTS ? el( c.Button, { variant: 'secondary', size: 'compact', onClick: function () { setResults( results.concat( [ { value: '', label: '' } ] ) ); } }, __( 'Add a result', 'pratcom-etudes-de-cas' ) ) : null,

			el( 'div', { style: { height: 16 } } ),
			el( c.TextareaControl, { __nextHasNoMarginBottom: true, label: __( 'Client testimonial', 'pratcom-etudes-de-cas' ), value: meta._pedc_testimonial || '', onChange: set( '_pedc_testimonial' ), rows: 4 } ),
			el( 'div', { style: { height: 12 } } ),
			el( c.TextControl, { __nextHasNoMarginBottom: true, __next40pxDefaultSize: true, label: __( 'Author (name, title)', 'pratcom-etudes-de-cas' ), value: meta._pedc_testimonial_author || '', onChange: set( '_pedc_testimonial_author' ), placeholder: __( 'e.g. Ian, General Manager', 'pratcom-etudes-de-cas' ) } )
		);
	}

	wp.plugins.registerPlugin( 'pedc-sheet', { render: SheetPanel, icon: 'portfolio' } );
}( window.wp ) );
