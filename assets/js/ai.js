/* Pratcom Études de cas: AI tools page (list, correction, translation). No build step. */
( function ( wp, cfg ) {
	'use strict';
	if ( ! wp || ! cfg ) {
		return;
	}
	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var Button = wp.components.Button;
	var Spinner = wp.components.Spinner;
	var Notice = wp.components.Notice;
	var TextareaControl = wp.components.TextareaControl;
	var apiFetch = wp.apiFetch;

	function t( key ) {
		return ( cfg.i18n && cfg.i18n[ key ] ) || key;
	}
	function api( path, data ) {
		var opts = { path: '/' + cfg.ns + path };
		if ( data ) {
			opts.method = 'POST';
			opts.data = data;
		}
		return apiFetch( opts );
	}
	function errText( e ) {
		return ( e && e.message ) || t( 'failed' );
	}
	function langName( code ) {
		var found = ( cfg.languages || [] ).filter( function ( l ) { return l.code === code; } )[ 0 ];
		return found ? found.name : code;
	}
	function statusLabel( s ) {
		var k = 'status_' + s;
		return t( k ) !== k ? t( k ) : s;
	}
	function setUrl( postId ) {
		try {
			var url = new URL( window.location.href );
			if ( postId ) {
				url.searchParams.set( 'post', postId );
			} else {
				url.searchParams.delete( 'post' );
			}
			window.history.pushState( {}, '', url.toString() );
		} catch ( e ) {}
	}

	function Badge( props ) {
		return el( 'span', { className: 'pedc-ai-badge' + ( props.muted ? ' is-muted' : '' ), title: props.title || '' }, props.children );
	}

	function Langs( props ) {
		var item = props.item;
		if ( ! cfg.wpml ) {
			return null;
		}
		return el( 'span', { className: 'pedc-ai-langs' },
			el( Badge, { title: t( 'original' ) }, item.lang.toUpperCase() ),
			item.translations.map( function ( tr ) {
				return el( Badge, { key: tr.lang, muted: true, title: langName( tr.lang ) + ' : ' + statusLabel( tr.status ) }, tr.lang.toUpperCase() );
			} )
		);
	}

	/* List */

	function Home( props ) {
		var s = useState( { items: [], page: 1, pages: 0, total: 0 } ), data = s[ 0 ], setData = s[ 1 ];
		var q = useState( '' ), query = q[ 0 ], setQuery = q[ 1 ];
		var a = useState( '' ), applied = a[ 0 ], setApplied = a[ 1 ];
		var p = useState( 1 ), page = p[ 0 ], setPage = p[ 1 ];
		var l = useState( true ), loading = l[ 0 ], setLoading = l[ 1 ];
		var e = useState( '' ), error = e[ 0 ], setError = e[ 1 ];

		useEffect( function () {
			setLoading( true );
			api( '/etudes?page=' + page + '&search=' + encodeURIComponent( applied ) ).then( function ( r ) {
				setData( r );
				setError( '' );
			} ).catch( function ( err ) {
				setError( errText( err ) );
			} ).finally( function () {
				setLoading( false );
			} );
		}, [ page, applied ] );

		return el( 'div', { className: 'pedc-ai-home' },
			el( 'form', {
				className: 'pedc-ai-tools',
				onSubmit: function ( ev ) {
					ev.preventDefault();
					setPage( 1 );
					setApplied( query );
				}
			},
				el( 'input', { type: 'search', className: 'regular-text', placeholder: t( 'searchPh' ), value: query, onChange: function ( ev ) { setQuery( ev.target.value ); } } ),
				el( Button, { variant: 'secondary', type: 'submit' }, t( 'search' ) ),
				el( 'span', { className: 'pedc-ai-count' }, data.total + ' ' + t( 'count' ) )
			),
			error ? el( Notice, { status: 'error', isDismissible: false }, error ) : null,
			loading ? el( 'p', null, el( Spinner ) ) :
				( data.items.length === 0 ? el( 'p', { className: 'pedc-ai-empty' }, applied ? t( 'noResults' ) : t( 'none' ) ) :
					el( 'ul', { className: 'pedc-ai-list' }, data.items.map( function ( item ) {
						return el( 'li', { key: item.id, className: 'pedc-ai-row' },
							item.thumb ? el( 'img', { className: 'pedc-ai-thumb', src: item.thumb, alt: '' } ) : el( 'span', { className: 'pedc-ai-thumb is-empty' } ),
							el( 'div', { className: 'pedc-ai-row__main' },
								el( 'a', { className: 'pedc-ai-row__title', href: item.edit }, item.title ),
								el( 'div', { className: 'pedc-ai-row__meta' },
									item.client ? el( 'span', null, item.client ) : null,
									el( 'span', null, statusLabel( item.status ) ),
									el( 'span', null, t( 'modified' ) + ' ' + item.modified.slice( 0, 10 ) ),
									el( Langs, { item: item } )
								)
							),
							el( 'div', { className: 'pedc-ai-row__actions' },
								el( Button, { variant: 'secondary', href: item.edit }, t( 'edit' ) ),
								el( Button, { variant: 'primary', onClick: function () { props.open( item.id ); } }, cfg.wpml ? t( 'correct' ) + ' / ' + t( 'translate' ) : t( 'correct' ) )
							)
						);
					} ) )
				),
			data.pages > 1 ? el( 'div', { className: 'pedc-ai-pager' },
				el( Button, { variant: 'secondary', disabled: page <= 1, onClick: function () { setPage( page - 1 ); } }, t( 'prev' ) ),
				el( 'span', null, t( 'page' ) + ' ' + page + ' / ' + data.pages ),
				el( Button, { variant: 'secondary', disabled: page >= data.pages, onClick: function () { setPage( page + 1 ); } }, t( 'next' ) )
			) : null
		);
	}

	/* Correction */

	function Compare( props ) {
		return el( 'div', { className: 'pedc-ai-compare' },
			el( 'div', null, el( 'h4', null, t( 'before' ) ), props.before ),
			el( 'div', null, el( 'h4', null, t( 'after' ) ), props.after )
		);
	}

	function resultsTable( rows ) {
		if ( ! rows || ! rows.length ) {
			return el( 'p', { className: 'description' }, '-' );
		}
		return el( 'table', { className: 'widefat striped' }, el( 'tbody', null, rows.map( function ( r, i ) {
			return el( 'tr', { key: i }, el( 'td', { className: 'pedc-ai-value' }, r.value ), el( 'td', null, r.label ) );
		} ) ) );
	}

	function Correction( props ) {
		var item = props.item;
		var s = useState( null ), prop = s[ 0 ], setProp = s[ 1 ];
		var x = useState( '' ), excerpt = x[ 0 ], setExcerpt = x[ 1 ];
		var b = useState( '' ), busy = b[ 0 ], setBusy = b[ 1 ];
		var e = useState( '' ), error = e[ 0 ], setError = e[ 1 ];
		var d = useState( [] ), done = d[ 0 ], setDone = d[ 1 ];

		function start() {
			setBusy( t( 'correcting' ) );
			setError( '' );
			setDone( [] );
			api( '/revise', { post_id: item.id } ).then( function ( r ) {
				setProp( r );
				setExcerpt( r.excerpt );
			} ).catch( function ( err ) {
				setError( errText( err ) );
			} ).finally( function () {
				setBusy( '' );
			} );
		}

		function apply() {
			setBusy( t( 'busy' ) );
			setError( '' );
			api( '/revise/apply', { post_id: item.id, excerpt: excerpt } ).then( function ( r ) {
				var msgs = [ t( 'applied' ) ];
				setProp( null );
				setDone( msgs.slice() );
				// Existing translations are redone from the corrected text, one by one.
				var langs = r.translations || [];
				var chain = Promise.resolve();
				langs.forEach( function ( code ) {
					chain = chain.then( function () {
						setBusy( t( 'retranslating' ) + ' (' + langName( code ) + ')…' );
						return api( '/translate', { post_id: item.id, target: code } ).then( function () {
							msgs.push( t( 'retranslated' ) + ' ' + langName( code ) );
						} ).catch( function ( err ) {
							msgs.push( langName( code ) + ' : ' + errText( err ) );
						} ).then( function () {
							setDone( msgs.slice() );
						} );
					} );
				} );
				return chain.then( function () {
					props.reload();
				} );
			} ).catch( function ( err ) {
				setError( errText( err ) );
			} ).finally( function () {
				setBusy( '' );
			} );
		}

		var st = prop && prop.stats;
		return el( 'section', { className: 'pedc-ai-card' },
			el( 'h2', null, t( 'correctTitle' ) ),
			el( 'p', null, t( 'correctIntro' ) ),
			'publish' === item.status ? el( Notice, { status: 'warning', isDismissible: false }, t( 'published' ) ) : null,
			error ? el( Notice, { status: 'error', isDismissible: false }, error ) : null,
			done.length ? el( Notice, { status: 'success', isDismissible: false }, done.map( function ( m, i ) { return el( 'div', { key: i }, m ); } ) ) : null,
			busy ? el( 'p', { className: 'pedc-ai-busy' }, el( Spinner ), ' ', busy ) : null,
			! prop && ! busy ? el( 'p', null, el( Button, { variant: 'primary', onClick: start }, t( 'correctStart' ) ) ) : null,
			prop && ! busy ? el( 'div', null,
				el( Notice, { status: 'info', isDismissible: false }, t( 'ready' ) ),
				el( 'ul', { className: 'pedc-ai-stats' },
					el( 'li', null, t( 'sections' ) + ' : ' + st.h2 ),
					el( 'li', null, t( 'words' ) + ' : ' + st.words_before + ' → ' + st.words_after ),
					st.links_total ? el( 'li', null, t( 'linksKept' ) + ' : ' + st.links_kept + ' / ' + st.links_total + ( st.links_missing.length ? ' (' + t( 'toCheck' ) + ' ' + st.links_missing.join( ', ' ) + ')' : '' ) ) : null,
					st.keeps ? el( 'li', null, t( 'mediaKept' ) + ' : ' + st.keeps + ( st.keeps_moved ? ' (' + st.keeps_moved + ' ' + t( 'mediaMoved' ) + ')' : '' ) ) : null,
					prop.model ? el( 'li', null, t( 'model' ) + ' ' + prop.model ) : null
				),
				el( TextareaControl, { label: t( 'excerpt' ), help: t( 'excerptHelp' ), value: excerpt, onChange: setExcerpt, rows: 3 } ),
				el( 'h3', null, t( 'text' ) ),
				el( Compare, {
					before: el( 'div', { className: 'pedc-ai-preview', dangerouslySetInnerHTML: { __html: prop.before_html } } ),
					after: el( 'div', { className: 'pedc-ai-preview', dangerouslySetInnerHTML: { __html: prop.after_html } } )
				} ),
				prop.results.before.length ? el( 'div', null, el( 'h3', null, t( 'results' ) ), el( Compare, { before: resultsTable( prop.results.before ), after: resultsTable( prop.results.after ) } ) ) : null,
				prop.testimonial.before ? el( 'div', null, el( 'h3', null, t( 'testimonial' ) ), el( Compare, { before: el( 'blockquote', null, prop.testimonial.before ), after: el( 'blockquote', null, prop.testimonial.after ) } ) ) : null,
				el( 'p', { className: 'pedc-ai-actions' },
					el( Button, { variant: 'primary', onClick: apply }, t( 'apply' ) ),
					el( Button, { variant: 'secondary', onClick: start }, t( 'again' ) )
				),
				el( 'p', { className: 'description' }, t( 'history' ) )
			) : null
		);
	}

	/* Translation */

	function Translation( props ) {
		var item = props.item;
		var b = useState( '' ), busy = b[ 0 ], setBusy = b[ 1 ];
		var m = useState( [] ), msgs = m[ 0 ], setMsgs = m[ 1 ];

		if ( ! cfg.wpml ) {
			return el( 'section', { className: 'pedc-ai-card' }, el( 'h2', null, t( 'translateTitle' ) ), el( 'p', null, t( 'noWpml' ) ) );
		}
		if ( item.original ) {
			return el( 'section', { className: 'pedc-ai-card' }, el( 'h2', null, t( 'translateTitle' ) ), el( 'p', null, t( 'isTranslation' ) ),
				el( Button, { variant: 'secondary', onClick: function () { props.open( item.original ); } }, t( 'openOriginal' ) ) );
		}
		var targets = ( cfg.languages || [] ).filter( function ( l ) { return l.code !== item.lang; } );
		var existing = {};
		item.translations.forEach( function ( tr ) { existing[ tr.lang ] = tr; } );

		function run( codes ) {
			var out = [];
			setMsgs( [] );
			var chain = Promise.resolve();
			codes.forEach( function ( code ) {
				chain = chain.then( function () {
					setBusy( t( 'translating' ) + ' (' + langName( code ) + ')' );
					return api( '/translate', { post_id: item.id, target: code } ).then( function ( r ) {
						var line = langName( code ) + ' : ' + ( r.updated ? t( 'updatedTr' ) : t( 'translated' ) );
						if ( r.created_terms && r.created_terms.length ) {
							line += ' ' + t( 'createdTerms' ) + ' ' + r.created_terms.join( ', ' );
						}
						if ( r.meta_failed ) {
							line += ' ' + t( 'metaFailed' );
						}
						out.push( { ok: true, text: line } );
					} ).catch( function ( err ) {
						out.push( { ok: false, text: langName( code ) + ' : ' + errText( err ) } );
					} ).then( function () {
						setMsgs( out.slice() );
					} );
				} );
			} );
			chain.then( function () {
				setBusy( '' );
				props.reload();
			} );
		}

		function one( code ) {
			if ( existing[ code ] && ! window.confirm( t( 'retranslateConf' ) ) ) {
				return;
			}
			run( [ code ] );
		}

		var missing = targets.filter( function ( l ) { return ! existing[ l.code ]; } ).map( function ( l ) { return l.code; } );

		return el( 'section', { className: 'pedc-ai-card' },
			el( 'h2', null, t( 'translateTitle' ) ),
			el( 'p', null, t( 'translateIntro' ) ),
			msgs.map( function ( msg, i ) {
				return el( Notice, { key: i, status: msg.ok ? 'success' : 'error', isDismissible: false }, msg.text );
			} ),
			busy ? el( 'p', { className: 'pedc-ai-busy' }, el( Spinner ), ' ', busy ) : null,
			el( 'table', { className: 'widefat striped pedc-ai-langtable' }, el( 'tbody', null, targets.map( function ( l ) {
				var tr = existing[ l.code ];
				return el( 'tr', { key: l.code },
					el( 'td', null, el( 'strong', null, l.name ) ),
					el( 'td', null, tr ? el( 'a', { href: tr.edit }, statusLabel( tr.status ) ) : el( 'span', { className: 'description' }, t( 'noTranslation' ) ) ),
					el( 'td', { className: 'pedc-ai-right' }, el( Button, { variant: tr ? 'secondary' : 'primary', disabled: !! busy, onClick: function () { one( l.code ); } }, tr ? t( 'retranslate' ) : t( 'translateTo' ) ) )
				);
			} ) ) ),
			missing.length > 1 ? el( 'p', null, el( Button, { variant: 'primary', disabled: !! busy, onClick: function () { run( missing ); } }, t( 'translateAll' ) ) ) : null
		);
	}

	/* One study */

	function Study( props ) {
		var s = useState( null ), item = s[ 0 ], setItem = s[ 1 ];
		var e = useState( '' ), error = e[ 0 ], setError = e[ 1 ];

		function load() {
			return api( '/etudes?id=' + props.id ).then( function ( r ) {
				setItem( r.items[ 0 ] || null );
			} ).catch( function ( err ) {
				setError( errText( err ) );
			} );
		}
		useEffect( function () { load(); }, [ props.id ] );

		return el( 'div', { className: 'pedc-ai-study' },
			el( 'p', null, el( Button, { variant: 'link', onClick: props.back }, t( 'back' ) ) ),
			error ? el( Notice, { status: 'error', isDismissible: false }, error ) : null,
			! item && ! error ? el( Spinner ) : null,
			item ? el( 'div', null,
				el( 'div', { className: 'pedc-ai-study__head' },
					el( 'h2', null, item.title ),
					el( 'div', { className: 'pedc-ai-row__meta' },
						item.client ? el( 'span', null, item.client ) : null,
						el( 'span', null, statusLabel( item.status ) ),
						el( Langs, { item: item } ),
						el( 'a', { href: item.edit }, t( 'edit' ) ),
						item.view ? el( 'a', { href: item.view, target: '_blank', rel: 'noopener' }, t( 'view' ) ) : null
					)
				),
				el( Correction, { item: item, reload: load } ),
				el( Translation, { item: item, reload: load, open: props.open } )
			) : null
		);
	}

	function App() {
		var s = useState( parseInt( cfg.postId, 10 ) || 0 ), id = s[ 0 ], setId = s[ 1 ];
		useEffect( function () {
			function onPop() {
				try {
					setId( parseInt( new URL( window.location.href ).searchParams.get( 'post' ) || '0', 10 ) );
				} catch ( e ) {}
			}
			window.addEventListener( 'popstate', onPop );
			return function () { window.removeEventListener( 'popstate', onPop ); };
		}, [] );
		function open( postId ) {
			setUrl( postId );
			setId( postId );
			window.scrollTo( 0, 0 );
		}
		return id ? el( Study, { key: id, id: id, open: open, back: function () { open( 0 ); } } ) : el( Home, { open: open } );
	}

	var target = document.getElementById( 'pedc-ai-app' );
	if ( target ) {
		if ( wp.element.createRoot ) {
			wp.element.createRoot( target ).render( el( App ) );
		} else {
			wp.element.render( el( App ), target );
		}
	}
}( window.wp, window.pedcAI ) );
