/**
 * Pratcom Études de cas: AI assistant page. No build step (wp.* globals).
 *
 * Home (all original case studies), then seven tabs on a real case study:
 * Project sheet, Text, Image, Linking, SEO, Translation, Publish. The AI
 * never invents the story: it sorts the author's notes, corrects, and
 * marks empty sections "To complete".
 */
( function ( wp, cfg ) {
	'use strict';
	var root = document.getElementById( 'pedc-ai-app' );
	if ( ! root || ! wp || ! wp.element || ! wp.components || ! wp.apiFetch || ! cfg ) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var C = wp.components;
	var apiFetch = wp.apiFetch;
	var addQueryArgs = ( wp.url && wp.url.addQueryArgs ) || function ( path, args ) {
		var q = Object.keys( args || {} ).map( function ( k ) { return encodeURIComponent( k ) + '=' + encodeURIComponent( args[ k ] ); } ).join( '&' );
		return q ? path + ( path.indexOf( '?' ) === -1 ? '?' : '&' ) + q : path;
	};

	// wp_localize_script turns numbers and booleans into strings.
	cfg.postId = parseInt( cfg.postId, 10 ) || 0;
	[ 'wpml', 'yoast', 'imageReady', 'canPublish', 'canUpload', 'fr' ].forEach( function ( k ) { cfg[ k ] = !! cfg[ k ] && '0' !== cfg[ k ] && '' !== cfg[ k ]; } );
	var ns = '/' + cfg.ns;

	function t( key ) {
		return ( cfg.i18n && cfg.i18n[ key ] ) || key;
	}
	function get( path, args ) {
		return apiFetch( { path: addQueryArgs( ns + path, args || {} ) } );
	}
	function post( path, data ) {
		return apiFetch( { path: ns + path, method: 'POST', data: data || {} } );
	}
	function errText( e ) {
		return ( e && e.message ) || t( 'failed' );
	}
	function statusLabel( s ) {
		var k = 'status_' + s;
		return t( k ) !== k ? t( k ) : s;
	}
	function langName( code ) {
		var found = ( cfg.languages || [] ).filter( function ( l ) { return l.code === code; } )[ 0 ];
		return found ? found.name : code;
	}
	function setUrl( id ) {
		try { window.history.replaceState( {}, '', addQueryArgs( cfg.pageUrl, id ? { post: id } : {} ) ); } catch ( e ) {}
	}

	// Crop to 16:9, max 1600 px wide, light JPEG, in the browser (the server stores WebP).
	function processImageTo169( dataUri ) {
		return new Promise( function ( resolve ) {
			try {
				var img = new Image();
				img.onload = function () {
					var ratio = 16 / 9, w = img.naturalWidth, h = img.naturalHeight;
					if ( ! w || ! h ) { resolve( dataUri ); return; }
					var cw = w, ch = Math.round( w / ratio );
					if ( ch > h ) { ch = h; cw = Math.round( h * ratio ); }
					var sx = Math.round( ( w - cw ) / 2 ), sy = Math.round( ( h - ch ) / 2 );
					var ow = cw, oh = ch;
					if ( ow > 1600 ) { oh = Math.round( oh * 1600 / ow ); ow = 1600; }
					var canvas = document.createElement( 'canvas' );
					canvas.width = ow; canvas.height = oh;
					var ctx = canvas.getContext && canvas.getContext( '2d' );
					if ( ! ctx ) { resolve( dataUri ); return; }
					ctx.drawImage( img, sx, sy, cw, ch, 0, 0, ow, oh );
					try { resolve( canvas.toDataURL( 'image/jpeg', 0.85 ) ); } catch ( e ) { resolve( dataUri ); }
				};
				img.onerror = function () { resolve( dataUri ); };
				img.src = dataUri;
			} catch ( e ) { resolve( dataUri ); }
		} );
	}

	// WordPress media library (choose or upload).
	function pickMedia( title, button, cb ) {
		if ( ! wp.media ) { return; }
		var frame = wp.media( { title: title, button: { text: button }, library: { type: 'image' }, multiple: false } );
		frame.on( 'select', function () {
			var a = frame.state().get( 'selection' ).first();
			if ( a ) { cb( a.toJSON() ); }
		} );
		frame.open();
	}
	function mediaUrl( a, size ) {
		return ( a.sizes && a.sizes[ size ] && a.sizes[ size ].url ) || a.url;
	}

	function Actions( props ) {
		return el( 'div', { className: 'pedc-actions' }, props.children );
	}
	function Btn( props ) {
		var busy = props.busyKey && props.busy === props.busyKey;
		return el( C.Button, {
			variant: props.variant || 'secondary',
			onClick: props.onClick,
			href: props.href,
			target: props.target,
			disabled: !! props.disabled || ( !! props.busy && ! props.href ),
			isDestructive: props.destructive,
			'aria-busy': busy
		}, busy ? el( C.Spinner, {} ) : null, props.children );
	}
	function Counter( props ) {
		var n = ( props.value || '' ).length;
		var warn = ( props.max && n > props.max ) || ( props.min && n > 0 && n < props.min );
		return el( 'span', { className: 'pedc-counter' + ( warn ? ' is-warn' : '' ) }, n + ( props.max ? ' / ' + props.max : '' ) + ' ' + t( 'chars' ) );
	}
	function LangBadge( props ) {
		return el( 'span', { className: 'pedc-lang' + ( props.muted ? ' is-tr' : '' ), title: props.title || '' }, ( props.code || '' ).toUpperCase() );
	}
	function Status( props ) {
		return el( 'span', { className: 'pedc-status is-' + props.status }, statusLabel( props.status ) );
	}

	/* ------------------------------------------------------------ */
	/* Home                                                          */
	/* ------------------------------------------------------------ */

	function Home( props ) {
		var s = useState( null ), data = s[ 0 ], setData = s[ 1 ];
		var q = useState( '' ), query = q[ 0 ], setQuery = q[ 1 ];
		var a = useState( '' ), applied = a[ 0 ], setApplied = a[ 1 ];
		var p = useState( 1 ), page = p[ 0 ], setPage = p[ 1 ];
		var e = useState( '' ), error = e[ 0 ], setError = e[ 1 ];

		useEffect( function () {
			setData( null );
			get( '/etudes', { page: page, search: applied } ).then( function ( r ) { setData( r ); setError( '' ); } ).catch( function ( err ) { setError( errText( err ) ); } );
		}, [ page, applied ] );

		var items = ( data && data.items ) || [];
		return el( 'div', { className: 'pedc-home' },
			el( 'div', { className: 'pedc-home-head' },
				el( 'h2', {}, t( 'allStudies' ) ),
				el( C.Button, { variant: 'primary', onClick: function () { props.open( 0 ); } }, t( 'newStudy' ) )
			),
			el( 'form', { className: 'pedc-home-tools', onSubmit: function ( ev ) { ev.preventDefault(); setPage( 1 ); setApplied( query.trim() ); } },
				el( 'input', { type: 'search', className: 'pedc-search', placeholder: t( 'searchPh' ), value: query, onChange: function ( ev ) { setQuery( ev.target.value ); } } ),
				el( C.Button, { variant: 'secondary', type: 'submit' }, t( 'search' ) ),
				data ? el( 'span', { className: 'pedc-muted' }, data.total + ' ' + t( 'count' ) ) : null
			),
			error ? el( C.Notice, { status: 'error', isDismissible: false }, error ) : null,
			! data ? ( error ? null : el( C.Spinner, {} ) ) : ( ! items.length
				? el( 'p', { className: 'pedc-empty' }, applied ? t( 'noResults' ) : t( 'none' ) )
				: el( 'ul', { className: 'pedc-list' }, items.map( function ( item ) {
					return el( 'li', { key: item.id, className: 'pedc-row' },
						item.thumb ? el( 'img', { className: 'pedc-thumb', src: item.thumb, alt: '' } ) : el( 'span', { className: 'pedc-thumb is-empty' } ),
						el( 'div', { className: 'pedc-row__main' },
							el( 'button', { type: 'button', className: 'pedc-row__title', onClick: function () { props.open( item.id ); } }, item.title ),
							el( 'div', { className: 'pedc-row__meta' },
								item.client ? el( 'span', {}, item.client ) : null,
								el( Status, { status: item.status } ),
								el( 'span', {}, t( 'modified' ) + ' ' + ( item.modified || '' ).slice( 0, 10 ) ),
								cfg.wpml ? el( 'span', {},
									el( LangBadge, { code: item.lang, title: t( 'original' ) } ),
									( item.translations || [] ).map( function ( tr ) { return el( LangBadge, { key: tr.lang, code: tr.lang, muted: true, title: langName( tr.lang ) + ' : ' + statusLabel( tr.status ) } ); } )
								) : null
							)
						),
						el( 'div', { className: 'pedc-row__actions' },
							el( C.Button, { variant: 'secondary', href: item.edit }, t( 'edit' ) ),
							el( C.Button, { variant: 'primary', onClick: function () { props.open( item.id ); } }, t( 'open' ) )
						)
					);
				} ) ) ),
			data && data.pages > 1 ? el( 'div', { className: 'pedc-pager' },
				el( C.Button, { variant: 'secondary', disabled: page <= 1, onClick: function () { setPage( page - 1 ); } }, '← ' + t( 'prev' ) ),
				el( 'span', { className: 'pedc-muted' }, t( 'page' ) + ' ' + page + ' / ' + data.pages ),
				el( C.Button, { variant: 'secondary', disabled: page >= data.pages, onClick: function () { setPage( page + 1 ); } }, t( 'next' ) + ' →' )
			) : null
		);
	}

	/* ------------------------------------------------------------ */
	/* Wizard                                                        */
	/* ------------------------------------------------------------ */

	var TABS = [ 'sheet', 'text', 'image', 'links', 'seo', 'translate', 'publish' ];

	function Wizard( props ) {
		var pi = useState( props.postId || 0 ), postId = pi[ 0 ], setPostId = pi[ 1 ];
		var ss = useState( null ), session = ss[ 0 ], setSession = ss[ 1 ];
		var tb = useState( 'sheet' ), tab = tb[ 0 ], setTab = tb[ 1 ];
		var bs = useState( '' ), busy = bs[ 0 ], setBusy = bs[ 1 ];
		var ns2 = useState( null ), notice = ns2[ 0 ], setNotice = ns2[ 1 ];
		var ld = useState( !! props.postId ), loading = ld[ 0 ], setLoading = ld[ 1 ];
		// Tab whose main AI task starts by itself (set by the previous step's main button).
		var au = useState( '' ), auto = au[ 0 ], setAuto = au[ 1 ];
		var le = useState( '' ), loadError = le[ 0 ], setLoadError = le[ 1 ];
		var busyRef = useRef( '' );
		busyRef.current = busy;

		function info( text, type ) {
			setNotice( text ? { type: type || 'info', text: text } : null );
		}
		function fail( err ) {
			try { window.console.error( '[Pratcom Études de cas]', err ); } catch ( e ) {}
			info( errText( err ), 'error' );
		}
		function run( key, message, promise ) {
			setBusy( key );
			info( message, 'info' );
			return promise.finally( function () { setBusy( '' ); } );
		}
		function applySession( s, keepTab ) {
			setSession( s );
			setPostId( s.post_id );
			if ( ! keepTab ) {
				var next = 'publish';
				for ( var i = 1; i < TABS.length - 1; i++ ) {
					if ( ! s.steps[ TABS[ i ] ] ) { next = TABS[ i ]; break; }
				}
				setTab( next );
			}
		}
		function refresh() {
			if ( ! postId ) { return Promise.resolve(); }
			return get( '/session', { post_id: postId } ).then( function ( s ) { applySession( s, true ); } ).catch( fail );
		}

		useEffect( function () {
			if ( props.postId ) {
				get( '/session', { post_id: props.postId } ).then( function ( s ) { applySession( s, false ); } ).catch( function ( e ) {
					setLoadError( errText( e ) );
				} ).finally( function () { setLoading( false ); } );
			}
		}, [] );

		// Back from the WordPress editor tab: reload the study without a click.
		useEffect( function () {
			function onFocus() {
				if ( postId && ! busyRef.current ) { refresh(); }
			}
			window.addEventListener( 'focus', onFocus );
			return function () { window.removeEventListener( 'focus', onFocus ); };
		}, [ postId ] );

		function go( key, autoStart ) {
			setTab( key );
			setAuto( autoStart ? key : '' );
			try { window.scrollTo( 0, 0 ); } catch ( e ) {}
		}
		function takeAuto( key ) {
			if ( auto !== key ) { return false; }
			setAuto( '' );
			return true;
		}

		var steps = ( session && session.steps ) || {};
		var header = el( 'div', { className: 'pedc-wizard-head' },
			el( C.Button, { variant: 'link', onClick: props.onHome, disabled: !! busy }, '← ' + t( 'back' ) ),
			session ? el( 'span', { className: 'pedc-head-title' },
				el( 'strong', {}, session.title ),
				cfg.wpml ? el( LangBadge, { code: session.lang } ) : null,
				el( Status, { status: session.status } ),
				el( 'a', { href: session.edit_link, target: '_blank', rel: 'noopener' }, t( 'openEditor' ) + ' ↗' ),
				session.status === 'publish' ? el( 'a', { href: session.view_link, target: '_blank', rel: 'noopener' }, t( 'view' ) + ' ↗' ) : null
			) : ( props.postId ? null : el( 'span', { className: 'pedc-head-title' }, el( 'strong', {}, t( 'newStudy' ) ) ) )
		);

		if ( loading ) {
			return el( 'div', { className: 'pedc-wizard' }, header, el( C.Spinner, {} ) );
		}
		if ( loadError ) {
			return el( 'div', { className: 'pedc-wizard' }, header, el( C.Notice, { status: 'error', isDismissible: false }, loadError ) );
		}
		if ( session && session.original ) {
			return el( 'div', { className: 'pedc-wizard' }, header,
				el( 'div', { className: 'pedc-panel' },
					el( 'p', {}, t( 'isTranslation' ) ),
					el( Actions, {}, el( C.Button, { variant: 'primary', onClick: function () { props.open( session.original ); } }, t( 'openOriginal' ) ) )
				)
			);
		}

		var tabBar = el( 'div', { className: 'pedc-tabs', role: 'tablist' },
			TABS.map( function ( key, i ) {
				var locked = ! postId && i > 0;
				var done = 'sheet' === key ? !! postId : !! steps[ key ];
				return el( 'button', {
					key: key,
					type: 'button',
					role: 'tab',
					'aria-selected': tab === key ? 'true' : 'false',
					className: 'pedc-tab' + ( tab === key ? ' is-active' : '' ) + ( done ? ' is-done' : '' ) + ( locked ? ' is-locked' : '' ),
					disabled: locked || !! busy,
					onClick: function () { setTab( key ); setAuto( '' ); info( null ); }
				},
					el( 'span', { className: 'pedc-tab-num' }, done ? '✓' : String( i + 1 ) ),
					el( 'span', { className: 'pedc-tab-label' }, t( 'tab_' + key ) )
				);
			} )
		);

		var noticeEl = notice ? el( 'div', {
			className: 'pedc-notice notice inline notice-' + ( { error: 'error', success: 'success', warning: 'warning' }[ notice.type ] || 'info' ),
			role: 'error' === notice.type ? 'alert' : 'status'
		},
			el( 'p', {}, busy ? el( C.Spinner, {} ) : null, notice.text ),
			! busy ? el( 'button', { type: 'button', className: 'pedc-notice-close', 'aria-label': t( 'close' ), onClick: function () { info( null ); } }, '×' ) : null
		) : null;

		var shared = {
			postId: postId, session: session, busy: busy, run: run, info: info, fail: fail,
			applySession: applySession, refresh: refresh, go: go, auto: auto, takeAuto: takeAuto
		};
		function panel( key, component ) {
			return el( 'div', { key: key, className: 'pedc-panel', role: 'tabpanel', hidden: tab !== key }, el( component, shared ) );
		}
		var ready = !! postId && !! session;

		return el( 'div', { className: 'pedc-wizard' },
			header,
			tabBar,
			noticeEl,
			panel( 'sheet', SheetTab ),
			ready ? panel( 'text', TextTab ) : null,
			ready ? panel( 'image', ImageTab ) : null,
			ready ? panel( 'links', LinksTab ) : null,
			ready ? panel( 'seo', SeoTab ) : null,
			ready ? panel( 'translate', TranslateTab ) : null,
			ready ? panel( 'publish', PublishTab ) : null
		);
	}

	/* ------------------------------------------------------------ */
	/* 1. Project sheet                                              */
	/* ------------------------------------------------------------ */

	var EMPTY_SHEET = { title: '', client: '', client_url: '', client_logo: 0, client_logo_url: '', period: '', testimonial: '', testimonial_author: '', results: [], sectors: [], services: [] };

	function sheetFrom( s ) {
		if ( ! s ) { return Object.assign( {}, EMPTY_SHEET, { results: [], sectors: [], services: [] } ); }
		return Object.assign( {}, EMPTY_SHEET, s.sheet, {
			title: s.title,
			results: ( s.sheet.results || [] ).map( function ( r ) { return { value: r.value, label: r.label }; } ),
			sectors: ( s.sheet.sectors || [] ).slice(),
			services: ( s.sheet.services || [] ).slice()
		} );
	}

	function SheetTab( p ) {
		var s = p.session;
		var fs = useState( sheetFrom( s ) ), form = fs[ 0 ], setForm = fs[ 1 ];
		var ls = useState( s ? s.lang : ( cfg.defaultLang || '' ) ), lang = ls[ 0 ], setLang = ls[ 1 ];
		var ts = useState( { sectors: [], services: [] } ), terms = ts[ 0 ], setTerms = ts[ 1 ];
		var nw = useState( [] ), newSectors = nw[ 0 ], setNewSectors = nw[ 1 ];
		var nn = useState( '' ), newName = nn[ 0 ], setNewName = nn[ 1 ];

		useEffect( function () { setForm( sheetFrom( s ) ); setNewSectors( [] ); }, [ s && s.post_id, s && s.modified ] );
		useEffect( function () {
			get( '/terms', { lang: lang } ).then( setTerms ).catch( p.fail );
		}, [ lang, s && s.modified ] );

		function set( key ) {
			return function ( v ) {
				var n = Object.assign( {}, form );
				n[ key ] = v;
				setForm( n );
			};
		}
		function setRow( i, key, v ) {
			var rows = form.results.map( function ( r ) { return Object.assign( {}, r ); } );
			rows[ i ][ key ] = v;
			set( 'results' )( rows );
		}
		function toggleSector( id, on ) {
			var list = form.sectors.filter( function ( x ) { return x !== id; } );
			if ( on ) { list.push( id ); }
			set( 'sectors' )( list );
		}
		function addSector() {
			var name = newName.trim();
			if ( ! name ) { return; }
			var exists = terms.sectors.filter( function ( tm ) { return tm.name.toLowerCase() === name.toLowerCase(); } )[ 0 ];
			if ( exists ) {
				toggleSector( exists.id, true );
			} else if ( newSectors.indexOf( name ) === -1 ) {
				setNewSectors( newSectors.concat( [ name ] ) );
			}
			setNewName( '' );
		}

		function save() {
			if ( ! form.title.trim() ) { p.info( t( 'noTitle' ), 'error' ); return; }
			var data = Object.assign( {}, form, { post_id: p.postId || 0, lang: lang, new_sectors: newSectors } );
			var isNew = ! p.postId;
			p.run( 'sheet', isNew ? t( 'creating' ) : t( 'busy' ), post( '/sheet', data ).then( function ( r ) {
				p.applySession( r, true );
				setUrl( r.post_id );
				p.info( null );
				p.go( 'text' );
			} ).catch( p.fail ) );
		}

		// Sector tree.
		var byParent = {}, known = {}, rows = [];
		terms.sectors.forEach( function ( c ) { known[ c.id ] = true; ( byParent[ c.parent ] = byParent[ c.parent ] || [] ).push( c ); } );
		( function walk( parent, depth ) {
			( byParent[ parent ] || [] ).forEach( function ( c ) { rows.push( { id: c.id, name: c.name, depth: depth } ); walk( c.id, depth + 1 ); } );
		} )( 0, 0 );
		terms.sectors.forEach( function ( c ) { if ( c.parent && ! known[ c.parent ] ) { rows.push( { id: c.id, name: c.name, depth: 0 } ); } } );

		var langs = cfg.languages || [];
		return el( 'div', { className: 'pedc-grid' },
			el( 'div', { className: 'pedc-col' },
				! p.postId ? el( 'p', { className: 'pedc-intro' }, t( 'sheetIntro' ) ) : null,
				! p.postId && cfg.wpml ? el( C.SelectControl, {
					__next40pxDefaultSize: true, __nextHasNoMarginBottom: true, label: t( 'language' ), value: lang,
					options: langs.map( function ( l ) { return { value: l.code, label: l.name }; } ),
					onChange: function ( v ) { setLang( v ); set( 'sectors' )( [] ); setNewSectors( [] ); }
				} ) : null,
				el( C.TextControl, { __next40pxDefaultSize: true, __nextHasNoMarginBottom: true, label: t( 'title' ), help: t( 'titleHelp' ), value: form.title, onChange: set( 'title' ) } ),
				el( 'div', { className: 'pedc-row2' },
					el( C.TextControl, { __next40pxDefaultSize: true, __nextHasNoMarginBottom: true, label: t( 'client' ), value: form.client, onChange: set( 'client' ) } ),
					el( C.TextControl, { __next40pxDefaultSize: true, __nextHasNoMarginBottom: true, type: 'url', label: t( 'clientUrl' ), value: form.client_url, onChange: set( 'client_url' ) } )
				),
				el( 'div', { className: 'pedc-row2' },
					el( C.TextControl, { __next40pxDefaultSize: true, __nextHasNoMarginBottom: true, label: t( 'period' ), help: t( 'periodHelp' ), value: form.period, onChange: set( 'period' ) } ),
					el( 'div', { className: 'pedc-logo' },
						el( 'span', { className: 'pedc-label' }, t( 'logo' ) ),
						form.client_logo_url ? el( 'img', { src: form.client_logo_url, alt: '' } ) : null,
						el( Actions, {},
							el( C.Button, { variant: 'secondary', disabled: ! wp.media, onClick: function () {
								pickMedia( t( 'logo' ), t( 'choose' ), function ( a ) {
									setForm( Object.assign( {}, form, { client_logo: a.id, client_logo_url: mediaUrl( a, 'thumbnail' ) } ) );
								} );
							} }, form.client_logo ? t( 'replace' ) : t( 'chooseLogo' ) ),
							form.client_logo ? el( C.Button, { variant: 'link', isDestructive: true, onClick: function () { setForm( Object.assign( {}, form, { client_logo: 0, client_logo_url: '' } ) ); } }, t( 'remove' ) ) : null
						)
					)
				),
				el( 'div', {},
					el( 'span', { className: 'pedc-label' }, t( 'results' ) ),
					el( 'p', { className: 'pedc-help' }, t( 'resultsHelp' ) ),
					form.results.map( function ( r, i ) {
						return el( 'div', { key: i, className: 'pedc-result' },
							el( C.TextControl, { __next40pxDefaultSize: true, __nextHasNoMarginBottom: true, label: t( 'resultValue' ), hideLabelFromVision: i > 0, placeholder: '+45 %', value: r.value, onChange: function ( v ) { setRow( i, 'value', v ); } } ),
							el( C.TextControl, { __next40pxDefaultSize: true, __nextHasNoMarginBottom: true, label: t( 'resultLabel' ), hideLabelFromVision: i > 0, value: r.label, onChange: function ( v ) { setRow( i, 'label', v ); } } ),
							el( C.Button, { variant: 'tertiary', isDestructive: true, label: t( 'remove' ), onClick: function () { set( 'results' )( form.results.filter( function ( x, j ) { return j !== i; } ) ); } }, '×' )
						);
					} ),
					form.results.length < 8 ? el( C.Button, { variant: 'secondary', onClick: function () { set( 'results' )( form.results.concat( [ { value: '', label: '' } ] ) ); } }, t( 'addResult' ) ) : null
				),
				el( C.TextareaControl, { __nextHasNoMarginBottom: true, label: t( 'testimonial' ), help: t( 'testimonialHelp' ), value: form.testimonial, rows: 4, onChange: set( 'testimonial' ) } ),
				el( C.TextControl, { __next40pxDefaultSize: true, __nextHasNoMarginBottom: true, label: t( 'testimonialAuthor' ), value: form.testimonial_author, onChange: set( 'testimonial_author' ) } )
			),
			el( 'div', { className: 'pedc-col pedc-side' },
				el( 'div', {},
					el( 'span', { className: 'pedc-label' }, t( 'sectors' ) ),
					el( 'div', { className: 'pedc-terms' },
						! rows.length && ! newSectors.length ? el( 'p', { className: 'pedc-muted' }, t( 'noSectors' ) ) : null,
						rows.map( function ( r ) {
							return el( 'div', { key: r.id, style: { paddingLeft: ( r.depth * 18 ) + 'px' } },
								el( C.CheckboxControl, { __nextHasNoMarginBottom: true, label: r.name, checked: form.sectors.indexOf( r.id ) !== -1, onChange: function ( v ) { toggleSector( r.id, v ); } } ) );
						} ),
						newSectors.map( function ( n ) {
							return el( 'div', { key: 'new-' + n, className: 'pedc-new-term' },
								el( C.CheckboxControl, { __nextHasNoMarginBottom: true, label: n + ' (' + t( 'newTerm' ) + ')', checked: true, onChange: function () { setNewSectors( newSectors.filter( function ( x ) { return x !== n; } ) ); } } ) );
						} )
					),
					el( 'div', { className: 'pedc-inline' },
						el( 'input', { type: 'text', className: 'pedc-input', placeholder: t( 'addSectorPh' ), value: newName, onChange: function ( ev ) { setNewName( ev.target.value ); }, onKeyDown: function ( ev ) { if ( 'Enter' === ev.key ) { ev.preventDefault(); addSector(); } } } ),
						el( C.Button, { variant: 'secondary', onClick: addSector }, t( 'add' ) )
					)
				),
				el( C.FormTokenField, {
					__next40pxDefaultSize: true, __nextHasNoMarginBottom: true, label: t( 'services' ), value: form.services,
					suggestions: terms.services.map( function ( x ) { return x.name; } ),
					onChange: function ( v ) { set( 'services' )( v.map( function ( x ) { return 'string' === typeof x ? x : x.value; } ) ); }
				} ),
				el( 'p', { className: 'pedc-help' }, t( 'servicesHelp' ) ),
				el( Actions, {},
					el( Btn, { variant: 'primary', onClick: save, busy: p.busy, busyKey: 'sheet' }, ( p.postId ? t( 'saveContinue' ) : t( 'createContinue' ) ) + ' →' )
				)
			)
		);
	}

	/* ------------------------------------------------------------ */
	/* 2. Text                                                       */
	/* ------------------------------------------------------------ */

	function Compare( props ) {
		return el( 'div', { className: 'pedc-compare' },
			el( 'div', {}, el( 'h4', {}, t( 'before' ) ), props.before ),
			el( 'div', {}, el( 'h4', {}, t( 'after' ) ), props.after )
		);
	}
	function Html( props ) {
		return el( 'div', { className: 'pedc-preview', dangerouslySetInnerHTML: { __html: props.html } } );
	}
	function resultsTable( rows ) {
		return el( 'table', { className: 'widefat striped pedc-results' }, el( 'tbody', {}, ( rows || [] ).map( function ( r, i ) {
			return el( 'tr', { key: i }, el( 'td', { className: 'pedc-value' }, r.value ), el( 'td', {}, r.label ) );
		} ) ) );
	}

	function TextTab( p ) {
		var s = p.session;
		var ex = useState( s.excerpt || '' ), excerpt = ex[ 0 ], setExcerpt = ex[ 1 ];
		var md = useState( '' ), mode = md[ 0 ], setMode = md[ 1 ];
		var nt = useState( '' ), notes = nt[ 0 ], setNotes = nt[ 1 ];
		var pr = useState( null ), prop = pr[ 0 ], setProp = pr[ 1 ];
		var px = useState( '' ), propExcerpt = px[ 0 ], setPropExcerpt = px[ 1 ];

		useEffect( function () { setExcerpt( s.excerpt || '' ); }, [ s.post_id, s.excerpt ] );
		// A study without text opens on the notes.
		useEffect( function () { if ( s.words < 30 && ! mode ) { setMode( 'notes' ); } }, [ s.post_id ] );

		function organize() {
			if ( notes.trim().length < 40 ) { p.info( t( 'notesShort' ), 'error' ); return; }
			setProp( null );
			p.run( 'notes', t( 'organizing' ), post( '/notes', { post_id: p.postId, notes: notes } ).then( function ( r ) {
				setProp( { kind: 'notes', data: r } );
				setPropExcerpt( r.excerpt || '' );
				p.info( r.empty_sections.length ? t( 'notesReadyTodo' ) + ' ' + r.empty_sections.join( ', ' ) : t( 'notesReady' ), r.empty_sections.length ? 'warning' : 'success' );
			} ).catch( p.fail ) );
		}
		function correct() {
			setProp( null );
			setMode( 'correct' );
			p.run( 'revise', t( 'correcting' ), post( '/revise', { post_id: p.postId } ).then( function ( r ) {
				setProp( { kind: 'revise', data: r } );
				setPropExcerpt( r.excerpt || '' );
				p.info( t( 'correctReady' ), 'success' );
			} ).catch( p.fail ) );
		}
		function apply() {
			var kind = prop.kind;
			var path = 'notes' === kind ? '/notes/apply' : '/revise/apply';
			p.run( 'apply', t( 'busy' ), post( path, { post_id: p.postId, excerpt: propExcerpt } ).then( function ( r ) {
				setProp( null );
				setMode( '' );
				setNotes( '' );
				if ( 'notes' === kind ) {
					p.applySession( r, true );
					var msg = t( 'notesApplied' );
					if ( r.filled && r.filled.length ) { msg += ' ' + t( 'sheetFilled' ); }
					p.info( msg, 'success' );
					return null;
				}
				// Correction: existing translations are redone from the corrected text.
				var langs = r.translations || [];
				var msgs = [ t( 'applied' ) ];
				var chain = Promise.resolve();
				langs.forEach( function ( code ) {
					chain = chain.then( function () {
						p.info( t( 'retranslating' ) + ' (' + langName( code ) + ')…', 'info' );
						return post( '/translate', { post_id: p.postId, target: code } ).then( function () {
							msgs.push( t( 'retranslated' ) + ' ' + langName( code ) + '.' );
						} ).catch( function ( err ) { msgs.push( langName( code ) + ' : ' + errText( err ) ); } );
					} );
				} );
				return chain.then( function () {
					p.info( msgs.join( ' ' ), 'success' );
					return p.refresh();
				} );
			} ).catch( p.fail ) );
		}
		function saveAndContinue() {
			var save = excerpt !== ( s.excerpt || '' )
				? post( '/fields', { post_id: p.postId, excerpt: excerpt } ).then( function ( r ) { p.applySession( r, true ); return r; } )
				: Promise.resolve( s );
			p.run( 'fields', t( 'busy' ), save.catch( function ( e ) { p.fail( e ); return null; } ) ).then( function ( r ) {
				if ( r ) { p.info( null ); p.go( 'image', ! r.featured && cfg.imageReady ); }
			} );
		}

		var tools = el( Actions, {},
			el( Btn, { variant: 'notes' === mode ? 'primary' : 'secondary', onClick: function () { setProp( null ); setMode( 'notes' === mode ? '' : 'notes' ); }, busy: p.busy }, t( 'organizeNotes' ) ),
			el( Btn, { onClick: correct, busy: p.busy, busyKey: 'revise', disabled: s.words < 10 }, t( 'correct' ) ),
			el( Btn, { href: s.edit_link, target: '_blank' }, t( 'openEditor' ) + ' ↗' )
		);

		if ( prop ) {
			var d = prop.data;
			return el( 'div', { className: 'pedc-single is-wide' },
				tools,
				'publish' === s.status ? el( C.Notice, { status: 'warning', isDismissible: false }, t( 'publishedWarn' ) ) : null,
				'notes' === prop.kind && d.replaces_text ? el( C.Notice, { status: 'warning', isDismissible: false }, t( 'replacesText' ) ) : null,
				'notes' === prop.kind && d.empty_sections.length ? el( C.Notice, { status: 'warning', isDismissible: false }, t( 'emptySections' ) + ' ' + d.empty_sections.join( ', ' ) ) : null,
				'revise' === prop.kind ? el( 'ul', { className: 'pedc-stats' },
					el( 'li', {}, t( 'words' ) + ' : ' + d.stats.words_before + ' → ' + d.stats.words_after ),
					d.stats.links_total ? el( 'li', {}, t( 'linksKept' ) + ' : ' + d.stats.links_kept + ' / ' + d.stats.links_total ) : null,
					d.stats.keeps ? el( 'li', {}, t( 'mediaKept' ) + ' : ' + d.stats.keeps ) : null
				) : null,
				d.model ? el( 'p', { className: 'pedc-muted' }, t( 'model' ) + ' ' + d.model ) : null,
				el( C.TextareaControl, { __nextHasNoMarginBottom: true, label: t( 'excerpt' ), help: t( 'excerptHelp' ), value: propExcerpt, rows: 3, onChange: setPropExcerpt } ),
				el( Counter, { value: propExcerpt, min: 120, max: 350 } ),
				'notes' === prop.kind && ! d.replaces_text
					? el( Fragment, {}, el( 'h3', { className: 'pedc-h3' }, t( 'preview' ) ), el( Html, { html: d.after_html } ) )
					: el( Compare, { before: el( Html, { html: d.before_html } ), after: el( Html, { html: d.after_html } ) } ),
				'notes' === prop.kind && d.results.length ? el( 'div', {},
					el( 'h3', { className: 'pedc-h3' }, t( 'results' ) ),
					el( 'p', { className: 'pedc-help-plain' }, d.fill_results ? t( 'willFillResults' ) : t( 'keepsResults' ) ),
					resultsTable( d.results )
				) : null,
				'notes' === prop.kind && d.testimonial ? el( 'div', {},
					el( 'h3', { className: 'pedc-h3' }, t( 'testimonial' ) ),
					el( 'p', { className: 'pedc-help-plain' }, d.fill_testimonial ? t( 'willFillTestimonial' ) : t( 'keepsTestimonial' ) ),
					el( 'blockquote', { className: 'pedc-quote' }, d.testimonial, d.testimonial_author ? el( 'cite', {}, d.testimonial_author ) : null )
				) : null,
				'revise' === prop.kind && d.results.before.length ? el( 'div', {}, el( 'h3', { className: 'pedc-h3' }, t( 'results' ) ), el( Compare, { before: resultsTable( d.results.before ), after: resultsTable( d.results.after ) } ) ) : null,
				'revise' === prop.kind && d.testimonial.before ? el( 'div', {}, el( 'h3', { className: 'pedc-h3' }, t( 'testimonial' ) ), el( Compare, { before: el( 'blockquote', { className: 'pedc-quote' }, d.testimonial.before ), after: el( 'blockquote', { className: 'pedc-quote' }, d.testimonial.after ) } ) ) : null,
				el( Actions, {},
					el( Btn, { variant: 'primary', onClick: apply, busy: p.busy, busyKey: 'apply' }, t( 'apply' ) ),
					el( Btn, { onClick: function () { setProp( null ); p.info( null ); }, busy: p.busy }, t( 'cancel' ) )
				),
				el( 'p', { className: 'pedc-help-plain' }, t( 'history' ) )
			);
		}

		return el( 'div', { className: 'pedc-grid' },
			el( 'div', { className: 'pedc-col' },
				tools,
				'notes' === mode ? el( 'div', { className: 'pedc-box' },
					el( 'h3', { className: 'pedc-h3' }, t( 'organizeNotes' ) ),
					el( 'p', {}, t( 'notesIntro' ) ),
					el( C.TextareaControl, { __nextHasNoMarginBottom: true, label: t( 'notesLabel' ), hideLabelFromVision: true, placeholder: t( 'notesPh' ), value: notes, rows: 12, onChange: setNotes } ),
					el( Actions, {}, el( Btn, { variant: 'primary', onClick: organize, busy: p.busy, busyKey: 'notes' }, t( 'organize' ) ) )
				) : null,
				el( 'h3', { className: 'pedc-h3' }, t( 'preview' ), el( 'span', { className: 'pedc-muted' }, ' · ' + s.words + ' ' + t( 'wordsLower' ) ) ),
				el( Html, { html: s.content_html } )
			),
			el( 'div', { className: 'pedc-col pedc-side' },
				el( 'h3', { className: 'pedc-h3' }, t( 'sections' ) ),
				s.sections.length ? el( 'ul', { className: 'pedc-sections' }, s.sections.map( function ( sec, i ) {
					var ok = sec.words > 0 && ! sec.todo;
					return el( 'li', { key: i, className: ok ? 'is-ok' : 'is-todo' },
						el( 'span', { className: 'pedc-check-icon' }, ok ? '✓' : '!' ),
						el( 'span', {}, sec.text || '-', el( 'small', {}, ok ? sec.words + ' ' + t( 'wordsLower' ) : t( 'toComplete' ) ) )
					);
				} ) ) : el( 'p', { className: 'pedc-muted' }, t( 'noSections' ) ),
				el( C.TextareaControl, { __nextHasNoMarginBottom: true, label: t( 'excerpt' ), help: t( 'excerptHelp' ), value: excerpt, rows: 4, onChange: setExcerpt } ),
				el( Counter, { value: excerpt, min: 120, max: 350 } ),
				el( Actions, {}, el( Btn, { variant: 'primary', onClick: saveAndContinue, busy: p.busy, busyKey: 'fields' }, t( 'saveContinue' ) + ' →' ) ),
				el( 'p', { className: 'pedc-help-plain' }, t( 'editorHint' ) )
			)
		);
	}

	/* ------------------------------------------------------------ */
	/* 3. Image                                                      */
	/* ------------------------------------------------------------ */

	function ImageTab( p ) {
		var s = p.session;
		var img = s.image || {};
		var st = useState( img.style || 'realistic' ), style = st[ 0 ], setStyle = st[ 1 ];
		var mc = useState( !! img.match_colors ), colors = mc[ 0 ], setColors = mc[ 1 ];
		var pr = useState( img.image_prompt || '' ), prompt = pr[ 0 ], setPrompt = pr[ 1 ];
		var al = useState( ( s.featured && s.featured.alt ) || img.image_alt || '' ), alt = al[ 0 ], setAlt = al[ 1 ];
		// Candidates: { uri } for an AI image, { id, url } for a media library image.
		var cs = useState( [] ), cands = cs[ 0 ], setCands = cs[ 1 ];
		var ix = useState( 0 ), idx = ix[ 0 ], setIdx = ix[ 1 ];
		var mo = useState( '' ), model = mo[ 0 ], setModel = mo[ 1 ];

		useEffect( function () {
			if ( s.featured && s.featured.alt && ! alt ) { setAlt( s.featured.alt ); }
		}, [ s.featured && s.featured.id ] );

		function add( c ) {
			var list = cands.concat( [ c ] );
			setCands( list );
			setIdx( list.length - 1 );
		}
		function doSuggest() {
			return post( '/image/suggest', { post_id: p.postId, style: style, match_colors: colors } ).then( function ( r ) {
				setPrompt( r.image_prompt || '' );
				if ( r.image_alt ) { setAlt( r.image_alt ); }
				return r.image_prompt || '';
			} );
		}
		function doGenerate( pr2 ) {
			return post( '/image/generate', { post_id: p.postId, prompt: pr2 } ).then( function ( r ) {
				if ( ! r || ! r.image ) { throw new Error( t( 'failed' ) ); }
				setModel( r.model || '' );
				return processImageTo169( r.image ).then( function ( uri ) {
					add( { uri: uri } );
					p.info( t( 'imageReadyMsg' ), 'success' );
				} );
			} );
		}
		function suggest() {
			p.run( 'suggest', t( 'suggestingPrompt' ), doSuggest().then( function () { p.info( null ); } ).catch( p.fail ) );
		}
		function generate() {
			var x = ( prompt || '' ).trim();
			if ( ! x ) {
				p.run( 'image', t( 'suggestingPrompt' ), doSuggest().then( function ( y ) {
					if ( ! y ) { throw new Error( t( 'noPrompt' ) ); }
					p.info( t( 'generatingImage' ), 'info' );
					return doGenerate( y );
				} ).catch( p.fail ) );
				return;
			}
			p.run( 'image', t( 'generatingImage' ), doGenerate( x ).catch( p.fail ) );
		}
		function library() {
			pickMedia( t( 'pickTitle' ), t( 'choose' ), function ( a ) {
				add( { id: a.id, url: mediaUrl( a, 'large' ) } );
				if ( a.alt ) { setAlt( a.alt ); }
				p.info( null );
			} );
		}

		// Arriving from the Text tab with no featured image: generate right away.
		useEffect( function () {
			if ( p.auto === 'image' && p.takeAuto( 'image' ) && ! s.featured && ! cands.length && cfg.imageReady ) { generate(); }
		}, [ p.auto ] );

		function next( r ) {
			p.info( null );
			p.go( 'links', ! ( r || s ).steps.links );
		}
		function useAndContinue() {
			var c = cands[ idx ];
			if ( ! c ) { return; }
			var req = c.id
				? post( '/image/select', { post_id: p.postId, attachment_id: c.id, alt: alt } )
				: post( '/image/featured', { post_id: p.postId, image: c.uri, alt: alt } );
			p.run( 'featured', t( 'busy' ), req.then( function ( r ) {
				p.applySession( r, true );
				setCands( [] );
				return r;
			} ).catch( function ( e ) { p.fail( e ); return null; } ) ).then( function ( r ) { if ( r ) { next( r ); } } );
		}
		function keepCurrent() {
			if ( s.featured && alt !== ( s.featured.alt || '' ) ) {
				p.run( 'alt', t( 'busy' ), post( '/image/alt', { post_id: p.postId, alt: alt } ).then( function ( r ) {
					p.applySession( r, true );
					return r;
				} ).catch( function ( e ) { p.fail( e ); return null; } ) ).then( function ( r ) { if ( r ) { next( r ); } } );
				return;
			}
			next();
		}

		var cur = cands[ idx ];
		var curSrc = cur ? ( cur.uri || cur.url ) : '';
		return el( 'div', { className: 'pedc-grid' },
			el( 'div', { className: 'pedc-col' },
				el( 'div', { className: 'pedc-box' },
					el( 'h3', { className: 'pedc-h3' }, t( 'ownImage' ) ),
					el( 'p', {}, t( 'ownImageHelp' ) ),
					el( Actions, {}, el( Btn, { onClick: library, busy: p.busy, disabled: ! wp.media }, t( 'pickImage' ) ) )
				),
				el( 'div', { className: 'pedc-box' },
					el( 'h3', { className: 'pedc-h3' }, t( 'aiImage' ) ),
					! cfg.imageReady ? el( C.Notice, { status: 'warning', isDismissible: false }, t( 'noImageProvider' ) ) : null,
					el( 'div', { className: 'pedc-row2' },
						el( C.SelectControl, { __next40pxDefaultSize: true, __nextHasNoMarginBottom: true, label: t( 'imageStyle' ), value: style, options: cfg.imageStyles || [], onChange: setStyle } ),
						el( C.CheckboxControl, { __nextHasNoMarginBottom: true, label: t( 'matchColors' ), checked: colors, onChange: setColors } )
					),
					el( C.TextareaControl, { __nextHasNoMarginBottom: true, label: t( 'imagePrompt' ), help: t( 'imagePromptHelp' ), value: prompt, rows: 4, onChange: setPrompt } ),
					el( Actions, {},
						el( Btn, { onClick: suggest, busy: p.busy, busyKey: 'suggest' }, t( 'suggestPrompt' ) ),
						el( Btn, { variant: 'primary', onClick: generate, busy: p.busy, busyKey: 'image', disabled: ! cfg.imageReady }, cands.some( function ( c ) { return c.uri; } ) ? t( 'generateAnother' ) : t( 'generateImage' ) )
					)
				),
				cur ? el( 'img', { className: 'pedc-image', src: curSrc, alt: '' } ) : null,
				cur && cur.uri && model ? el( 'p', { className: 'pedc-muted' }, t( 'model' ) + ' ' + model ) : null,
				cands.length > 1 ? el( Fragment, {},
					el( 'p', { className: 'pedc-help-plain' }, t( 'imagesSession' ) ),
					el( 'div', { className: 'pedc-thumbs' }, cands.map( function ( c, i ) {
						return el( 'button', { key: i, type: 'button', className: 'pedc-thumb-btn' + ( i === idx ? ' is-current' : '' ), onClick: function () { setIdx( i ); } }, el( 'img', { src: c.uri || c.url, alt: '' } ) );
					} ) )
				) : null
			),
			el( 'div', { className: 'pedc-col pedc-side' },
				el( C.TextControl, { __next40pxDefaultSize: true, __nextHasNoMarginBottom: true, label: t( 'imageAlt' ), help: t( 'imageAltHelp' ), value: alt, onChange: setAlt } ),
				el( Actions, {},
					cur ? el( Btn, { variant: 'primary', onClick: useAndContinue, busy: p.busy, busyKey: 'featured' }, t( 'useImageContinue' ) + ' →' )
						: ( s.featured ? el( Btn, { variant: 'primary', onClick: keepCurrent, busy: p.busy, busyKey: 'alt' }, t( 'continue' ) + ' →' )
							: el( Btn, { onClick: function () { next(); }, busy: p.busy }, t( 'skip' ) + ' →' ) ),
					cur && s.featured ? el( Btn, { onClick: keepCurrent, busy: p.busy, busyKey: 'alt' }, t( 'keepCurrent' ) + ' →' ) : null
				),
				s.featured ? el( Fragment, {},
					el( 'h3', { className: 'pedc-h3' }, t( 'currentFeatured' ) ),
					el( 'img', { className: 'pedc-image', src: s.featured.url, alt: s.featured.alt || '' } ),
					el( 'p', { className: 'pedc-muted' }, 'alt : ' + ( s.featured.alt || '-' ) )
				) : null,
				cfg.wpml ? el( 'p', { className: 'pedc-help-plain' }, t( 'imageShared' ) ) : null
			)
		);
	}

	/* ------------------------------------------------------------ */
	/* 4. Internal links                                             */
	/* ------------------------------------------------------------ */

	function LinksTab( p ) {
		var lk = useState( null ), links = lk[ 0 ], setLinks = lk[ 1 ];
		var sl = useState( {} ), sel = sl[ 0 ], setSel = sl[ 1 ];

		function suggest() {
			setLinks( null );
			p.run( 'links', t( 'suggestingLinks' ), post( '/links/suggest', { post_id: p.postId } ).then( function ( r ) {
				var list = ( r && r.links ) || [];
				var x = {};
				list.forEach( function ( l, i ) { x[ i ] = true; } );
				setLinks( list );
				setSel( x );
				p.info( list.length ? null : t( 'noLinks' ), 'info' );
			} ).catch( p.fail ) );
		}
		useEffect( function () {
			if ( p.auto === 'links' && p.takeAuto( 'links' ) ) { suggest(); }
		}, [ p.auto ] );

		function toSeo( r ) {
			p.go( 'seo', ! ( r || p.session ).steps.seo );
		}
		function skip() {
			if ( p.session.links_done ) { toSeo(); return; }
			p.run( 'skip', t( 'busy' ), post( '/links/skip', { post_id: p.postId } ).then( function ( r ) {
				p.applySession( r, true );
				p.info( null );
				return r;
			} ).catch( function ( e ) { p.fail( e ); return null; } ) ).then( function ( r ) { if ( r ) { toSeo( r ); } } );
		}
		function insert() {
			var chosen = ( links || [] ).filter( function ( l, i ) { return sel[ i ]; } );
			if ( ! chosen.length ) { skip(); return; }
			p.run( 'insert', t( 'busy' ), post( '/links/insert', { post_id: p.postId, links: chosen } ).then( function ( r ) {
				p.applySession( r, true );
				setLinks( null );
				var msg = r.added + ' ' + t( 'linksInserted' );
				if ( r.missing && r.missing.length ) { msg += ' ' + t( 'linksMissing' ) + ' ' + r.missing.join( ', ' ); }
				p.info( msg, r.missing && r.missing.length ? 'warning' : 'success' );
				return r;
			} ).catch( function ( e ) { p.fail( e ); return null; } ) ).then( function ( r ) {
				if ( r && ! ( r.missing && r.missing.length ) ) { toSeo( r ); }
			} );
		}

		return el( 'div', { className: 'pedc-single' },
			el( 'p', {}, t( 'linksIntro' ) ),
			p.session.links_done ? el( 'p', { className: 'pedc-ok' }, '✓ ' + t( 'linksDone' ) ) : null,
			el( Actions, {},
				el( Btn, { variant: links && links.length ? 'secondary' : 'primary', onClick: suggest, busy: p.busy, busyKey: 'links' }, t( 'suggestLinks' ) ),
				el( Btn, { onClick: skip, busy: p.busy, busyKey: 'skip' }, ( p.session.links_done ? t( 'continue' ) : t( 'skip' ) ) + ' →' )
			),
			links && links.length ? el( 'div', { className: 'pedc-links' },
				el( 'p', {}, t( 'linksFound' ) ),
				links.map( function ( l, i ) {
					return el( 'div', { key: i, className: 'pedc-link' },
						el( C.CheckboxControl, {
							__nextHasNoMarginBottom: true,
							label: '« ' + l.anchor + ' » → ' + l.title + ' (' + t( 'kind_' + l.kind.replace( ' ', '_' ) ) + ')',
							checked: !! sel[ i ],
							onChange: function ( v ) { var n = Object.assign( {}, sel ); n[ i ] = v; setSel( n ); }
						} ),
						el( 'a', { href: l.url, target: '_blank', rel: 'noopener', className: 'pedc-muted' }, l.url )
					);
				} ),
				el( Actions, {}, el( Btn, { variant: 'primary', onClick: insert, busy: p.busy, busyKey: 'insert' }, t( 'insertContinue' ) + ' →' ) )
			) : null
		);
	}

	/* ------------------------------------------------------------ */
	/* 5. SEO                                                        */
	/* ------------------------------------------------------------ */

	var SEO_FIELDS = [
		{ key: 'seo_title', max: 60 },
		{ key: 'meta_description', max: 155, area: true },
		{ key: 'focus_keyphrase' },
		{ key: 'slug' },
		{ key: 'og_title' },
		{ key: 'og_description', area: true },
		{ key: 'twitter_title' },
		{ key: 'twitter_description', area: true }
	];

	function SeoTab( p ) {
		var fs = useState( Object.assign( {}, p.session.seo || {} ) ), fields = fs[ 0 ], setFields = fs[ 1 ];
		useEffect( function () { setFields( Object.assign( {}, p.session.seo || {} ) ); }, [ p.session.post_id ] );

		function generate() {
			p.run( 'seo', t( 'generatingSeo' ), post( '/seo/generate', { post_id: p.postId } ).then( function ( r ) {
				setFields( Object.assign( {}, fields, r ) );
				p.info( t( 'seoReady' ), 'success' );
			} ).catch( p.fail ) );
		}
		useEffect( function () {
			if ( p.auto === 'seo' && p.takeAuto( 'seo' ) && ! p.session.steps.seo ) { generate(); }
		}, [ p.auto ] );

		function save() {
			p.run( 'seo-save', t( 'busy' ), post( '/seo/save', { post_id: p.postId, fields: fields } ).then( function ( r ) {
				p.applySession( r, true );
				p.info( t( 'seoSaved' ), 'success' );
				return r;
			} ).catch( function ( e ) { p.fail( e ); return null; } ) ).then( function ( r ) {
				if ( ! r ) { return; }
				if ( cfg.wpml ) { p.go( 'translate', true ); } else { p.go( 'publish' ); }
			} );
		}

		return el( 'div', { className: 'pedc-single' },
			! cfg.yoast ? el( C.Notice, { status: 'warning', isDismissible: false }, t( 'noYoast' ) ) : null,
			el( Actions, {}, el( Btn, { onClick: generate, busy: p.busy, busyKey: 'seo' }, p.session.steps.seo || fields.seo_title ? t( 'regenerateSeo' ) : t( 'generateSeo' ) ) ),
			el( 'div', { className: 'pedc-seo-grid' }, SEO_FIELDS.map( function ( f ) {
				if ( ! cfg.yoast && 'slug' !== f.key ) { return null; }
				var props = {
					__nextHasNoMarginBottom: true, label: t( 'seo_' + f.key ), value: fields[ f.key ] || '',
					onChange: function ( v ) { var n = Object.assign( {}, fields ); n[ f.key ] = v; setFields( n ); }
				};
				return el( 'div', { key: f.key, className: 'pedc-seo-field' },
					f.area ? el( C.TextareaControl, Object.assign( { rows: 3 }, props ) ) : el( C.TextControl, Object.assign( { __next40pxDefaultSize: true }, props ) ),
					f.max ? el( Counter, { value: fields[ f.key ] || '', max: f.max } ) : null
				);
			} ) ),
			el( Actions, {}, el( Btn, { variant: 'primary', onClick: save, busy: p.busy, busyKey: 'seo-save' }, ( cfg.yoast ? t( 'saveSeoContinue' ) : t( 'saveContinue' ) ) + ' →' ) )
		);
	}

	/* ------------------------------------------------------------ */
	/* 6. Translation                                                */
	/* ------------------------------------------------------------ */

	function TranslateTab( p ) {
		var s = p.session;
		var targets = ( cfg.languages || [] ).filter( function ( l ) { return l.code !== s.lang; } );
		var existing = {};
		( s.translations || [] ).forEach( function ( tr ) { existing[ tr.lang ] = tr; } );
		var missing = targets.filter( function ( l ) { return ! existing[ l.code ]; } ).map( function ( l ) { return l.code; } );

		function run( codes, thenPublish ) {
			var notes = [], warn = false;
			var chain = Promise.resolve();
			codes.forEach( function ( code ) {
				chain = chain.then( function () {
					p.info( t( 'translating' ) + ' (' + langName( code ) + ')', 'info' );
					return post( '/translate', { post_id: p.postId, target: code } ).then( function ( r ) {
						var line = langName( code ) + ' : ' + ( r.updated ? t( 'updatedTr' ) : t( 'translated' ) );
						if ( r.created_terms && r.created_terms.length ) { line += ' ' + t( 'createdTerms' ) + ' ' + r.created_terms.join( ', ' ) + '.'; }
						if ( r.meta_failed ) { warn = true; line += ' ' + t( 'metaFailed' ); }
						notes.push( line );
					} ).catch( function ( err ) { warn = true; notes.push( langName( code ) + ' : ' + errText( err ) ); } );
				} );
			} );
			p.run( 'translate', t( 'translating' ), chain.then( function () { return p.refresh(); } ) ).then( function () {
				if ( thenPublish && ! warn ) { p.go( 'publish' ); }
				p.info( notes.join( ' ' ), warn ? 'warning' : 'success' );
			} );
		}
		function one( code ) {
			if ( existing[ code ] && ! window.confirm( t( 'retranslateConf' ) ) ) { return; }
			run( [ code ], false );
		}

		// Arriving from the SEO tab: translate every language not done yet, then publish.
		useEffect( function () {
			if ( p.auto !== 'translate' || ! p.takeAuto( 'translate' ) ) { return; }
			if ( cfg.wpml && missing.length ) { run( missing, true ); }
		}, [ p.auto ] );

		if ( ! cfg.wpml ) {
			return el( 'div', { className: 'pedc-single' }, el( C.Notice, { status: 'info', isDismissible: false }, t( 'noWpml' ) ),
				el( Actions, {}, el( Btn, { onClick: function () { p.go( 'publish' ); } }, t( 'continue' ) + ' →' ) ) );
		}
		return el( 'div', { className: 'pedc-single' },
			el( 'p', {}, t( 'translateIntro' ) ),
			el( 'table', { className: 'widefat striped pedc-langtable' }, el( 'tbody', {}, targets.map( function ( l ) {
				var tr = existing[ l.code ];
				return el( 'tr', { key: l.code },
					el( 'td', {}, el( LangBadge, { code: l.code } ), ' ', el( 'strong', {}, l.name ) ),
					el( 'td', {}, tr ? el( Fragment, {}, tr.title, ' ', el( Status, { status: tr.status } ) ) : el( 'span', { className: 'pedc-muted' }, t( 'noTranslation' ) ) ),
					el( 'td', { className: 'pedc-right' },
						tr ? el( C.Button, { variant: 'link', href: tr.edit, target: '_blank' }, t( 'edit' ) ) : null, ' ',
						tr && tr.view ? el( C.Button, { variant: 'link', href: tr.view, target: '_blank' }, t( 'view' ) ) : null, ' ',
						el( Btn, { variant: tr ? 'secondary' : 'primary', onClick: function () { one( l.code ); }, busy: p.busy }, tr ? t( 'retranslate' ) : t( 'translateTo' ) )
					)
				);
			} ) ) ),
			el( Actions, {},
				missing.length > 1 ? el( Btn, { variant: 'primary', onClick: function () { run( missing, true ); }, busy: p.busy, busyKey: 'translate' }, t( 'translateAll' ) + ' →' ) : null,
				el( Btn, { onClick: function () { p.go( 'publish' ); }, busy: p.busy }, ( missing.length ? t( 'skip' ) : t( 'continue' ) ) + ' →' )
			),
			el( 'p', { className: 'pedc-help-plain' }, t( 'imageShared' ) )
		);
	}

	/* ------------------------------------------------------------ */
	/* 7. Publish                                                    */
	/* ------------------------------------------------------------ */

	function tomorrowAt8() {
		var d = new Date();
		d.setDate( d.getDate() + 1 );
		d.setHours( 8, 0, 0, 0 );
		function pad( n ) { return n < 10 ? '0' + n : String( n ); }
		return d.getFullYear() + '-' + pad( d.getMonth() + 1 ) + '-' + pad( d.getDate() ) + 'T08:00';
	}

	function PublishTab( p ) {
		var s = p.session;
		var ck = useState( null ), checks = ck[ 0 ], setChecks = ck[ 1 ];
		var md = useState( cfg.canPublish ? 'publish' : 'draft' ), mode = md[ 0 ], setMode = md[ 1 ];
		var dt = useState( tomorrowAt8() ), date = dt[ 0 ], setDate = dt[ 1 ];
		var ic = useState( true ), inc = ic[ 0 ], setInc = ic[ 1 ];
		var dn = useState( null ), done = dn[ 0 ], setDone = dn[ 1 ];

		function load() {
			return get( '/checks', { post_id: p.postId } ).then( setChecks ).catch( p.fail );
		}
		useEffect( function () { load(); }, [ s.post_id, s.modified, s.status, ( s.translations || [] ).length, s.featured && s.featured.alt ] );

		var hasErrors = false;
		( ( checks && checks.posts ) || [] ).forEach( function ( cp ) {
			cp.items.forEach( function ( it ) { if ( ! it.ok && 'error' === it.level ) { hasErrors = true; } } );
		} );

		function apply() {
			setDone( null );
			p.run( 'publish', t( 'publishing' ), post( '/publish', { post_id: p.postId, mode: mode, date: date, include_translations: inc } ).then( function ( r ) {
				p.applySession( r, true );
				setDone( r.published || [] );
				p.info( t( 'publishedDone' ), 'success' );
				load();
			} ).catch( p.fail ) );
		}

		var modes = [ { value: 'draft', label: t( 'modeDraft' ) } ];
		if ( cfg.canPublish ) {
			modes.unshift( { value: 'future', label: t( 'modeFuture' ) } );
			modes.unshift( { value: 'publish', label: t( 'modePublish' ) } );
		}
		return el( 'div', { className: 'pedc-grid' },
			el( 'div', { className: 'pedc-col' },
				el( 'h3', { className: 'pedc-h3' }, t( 'checklist' ) ),
				! checks ? el( C.Spinner, {} ) : checks.posts.map( function ( cp ) {
					return el( 'div', { key: cp.post_id, className: 'pedc-checks' },
						el( 'h4', {}, cfg.wpml ? el( LangBadge, { code: cp.lang } ) : null, ' ', cp.title, ' ', el( Status, { status: cp.status } ) ),
						el( 'ul', {}, cp.items.map( function ( it ) {
							var extra = '';
							if ( 'excerpt' === it.key || ( ! it.ok && ( 'dashes' === it.key || 'anchors' === it.key || 'blocks' === it.key ) ) ) { extra = ' (' + it.value + ')'; }
							if ( 'todo' === it.key && ! it.ok ) { extra = ' : ' + it.value; }
							if ( 'results' === it.key && it.ok ) { extra = ' (' + it.value + ')'; }
							return el( 'li', { key: it.key, className: it.ok ? 'is-ok' : 'is-' + it.level },
								el( 'span', { className: 'pedc-check-icon' }, it.ok ? '✓' : ( 'error' === it.level ? '✗' : '!' ) ),
								t( 'check_' + it.key ) + extra );
						} ) )
					);
				} )
			),
			el( 'div', { className: 'pedc-col pedc-side' },
				hasErrors ? el( C.Notice, { status: 'warning', isDismissible: false }, t( 'errorsBlock' ) ) : null,
				el( C.RadioControl, { label: t( 'publishMode' ), selected: mode, options: modes, onChange: setMode } ),
				'future' === mode ? el( C.TextControl, { __next40pxDefaultSize: true, __nextHasNoMarginBottom: true, type: 'datetime-local', label: t( 'date' ), value: date, onChange: setDate } ) : null,
				( s.translations || [] ).length ? el( C.CheckboxControl, { __nextHasNoMarginBottom: true, label: t( 'includeTranslations' ), checked: inc, onChange: setInc } ) : null,
				el( Actions, {}, el( Btn, { variant: 'primary', onClick: apply, busy: p.busy, busyKey: 'publish' }, t( 'apply' ) ) ),
				done && done.length ? el( 'ul', { className: 'pedc-done' }, done.map( function ( d ) {
					return el( 'li', { key: d.post_id },
						cfg.wpml ? el( LangBadge, { code: d.lang } ) : null, ' ',
						el( Status, { status: d.status } ), ' ',
						'future' === d.status ? d.date.slice( 0, 16 ) + ' ' : '',
						el( 'a', { href: d.view, target: '_blank', rel: 'noopener' }, t( 'view' ) )
					);
				} ) ) : null
			)
		);
	}

	/* ------------------------------------------------------------ */
	/* App                                                           */
	/* ------------------------------------------------------------ */

	function App() {
		var vs = useState( cfg.postId ? { name: 'wizard', postId: cfg.postId, key: 1 } : { name: 'home' } ), view = vs[ 0 ], setView = vs[ 1 ];
		function open( id ) {
			setUrl( id );
			setView( { name: 'wizard', postId: id, key: Date.now() } );
			try { window.scrollTo( 0, 0 ); } catch ( e ) {}
		}
		function home() {
			setUrl( 0 );
			setView( { name: 'home' } );
		}
		if ( 'home' === view.name ) {
			return el( Home, { open: open } );
		}
		return el( Wizard, { key: view.key, postId: view.postId, onHome: home, open: open } );
	}

	if ( wp.element.createRoot ) {
		wp.element.createRoot( root ).render( el( App ) );
	} else {
		wp.element.render( el( App ), root );
	}
}( window.wp, window.pedcAI ) );
