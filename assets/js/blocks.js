/* Pratcom – Études de cas : editor side of the dynamic blocks (no build step). */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var registerBlockType = wp.blocks.registerBlockType;
	var be = wp.blockEditor;
	var c = wp.components;
	var ServerSideRender = wp.serverSideRender;
	var data = window.pedcBlocks || { sectors: [], services: [] };

	function preview( name, props, extraArgs ) {
		return el( ServerSideRender, {
			block: name,
			attributes: props.attributes,
			urlQueryArgs: extraArgs || {},
			EmptyResponsePlaceholder: function () {
				return el( c.Placeholder, {
					icon: 'portfolio',
					label: __( 'Nothing to show yet', 'pratcom-etudes-de-cas' ),
					instructions: __( 'This block displays content once case studies (or project sheet fields) are filled in.', 'pratcom-etudes-de-cas' )
				} );
			}
		} );
	}

	function termOptions( list ) {
		return [ { value: '', label: __( 'All', 'pratcom-etudes-de-cas' ) } ].concat( list );
	}

	registerBlockType( 'pratcom/fiche-projet', {
		edit: function ( props ) {
			var a = props.attributes;
			var postId = props.context && props.context.postId;
			return el( 'div', be.useBlockProps(),
				el( be.InspectorControls, {},
					el( c.PanelBody, { title: __( 'Sections', 'pratcom-etudes-de-cas' ) },
						el( c.ToggleControl, { label: __( 'Client, sector and services', 'pratcom-etudes-de-cas' ), checked: a.showFacts, onChange: function ( v ) { props.setAttributes( { showFacts: v } ); } } ),
						el( c.ToggleControl, { label: __( 'Key results', 'pratcom-etudes-de-cas' ), checked: a.showResults, onChange: function ( v ) { props.setAttributes( { showResults: v } ); } } ),
						el( c.ToggleControl, { label: __( 'Testimonial', 'pratcom-etudes-de-cas' ), checked: a.showTestimonial, onChange: function ( v ) { props.setAttributes( { showTestimonial: v } ); } } )
					)
				),
				preview( 'pratcom/fiche-projet', props, postId ? { post_id: postId } : {} )
			);
		},
		save: function () { return null; }
	} );

	registerBlockType( 'pratcom/etudes-de-cas', {
		edit: function ( props ) {
			var a = props.attributes;
			var set = function ( k ) { return function ( v ) { var o = {}; o[ k ] = v; props.setAttributes( o ); }; };
			return el( 'div', be.useBlockProps(),
				el( be.InspectorControls, {},
					el( c.PanelBody, { title: __( 'Settings', 'pratcom-etudes-de-cas' ) },
						el( c.RangeControl, { label: __( 'Number of case studies', 'pratcom-etudes-de-cas' ), min: 1, max: 24, value: a.number, onChange: set( 'number' ) } ),
						el( c.RangeControl, { label: __( 'Columns', 'pratcom-etudes-de-cas' ), min: 1, max: 4, value: a.columns, onChange: set( 'columns' ) } ),
						el( c.SelectControl, { label: __( 'Sector', 'pratcom-etudes-de-cas' ), value: a.sector, options: termOptions( data.sectors ), onChange: set( 'sector' ) } ),
						el( c.SelectControl, { label: __( 'Service', 'pratcom-etudes-de-cas' ), value: a.service, options: termOptions( data.services ), onChange: set( 'service' ) } ),
						el( c.SelectControl, {
							label: __( 'Order', 'pratcom-etudes-de-cas' ),
							value: a.orderby,
							options: [
								{ value: 'date', label: __( 'Most recent', 'pratcom-etudes-de-cas' ) },
								{ value: 'title', label: __( 'Title (A–Z)', 'pratcom-etudes-de-cas' ) },
								{ value: 'rand', label: __( 'Random', 'pratcom-etudes-de-cas' ) }
							],
							onChange: set( 'orderby' )
						} )
					)
				),
				preview( 'pratcom/etudes-de-cas', props )
			);
		},
		save: function () { return null; }
	} );

	registerBlockType( 'pratcom/etudes-filtre', {
		edit: function ( props ) {
			return el( 'div', be.useBlockProps(), preview( 'pratcom/etudes-filtre', props ) );
		},
		save: function () { return null; }
	} );

	registerBlockType( 'pratcom/etudes-connexes', {
		edit: function ( props ) {
			var postId = props.context && props.context.postId;
			return el( 'div', be.useBlockProps(), preview( 'pratcom/etudes-connexes', props, postId ? { post_id: postId } : {} ) );
		},
		save: function () { return null; }
	} );
	registerBlockType( 'pratcom/carte-etude', {
		edit: function ( props ) {
			var postId = props.context && props.context.postId;
			return el( 'div', be.useBlockProps(), preview( 'pratcom/carte-etude', props, postId ? { post_id: postId } : {} ) );
		},
		save: function () { return null; }
	} );
}( window.wp ) );
