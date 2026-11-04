/**
 * EAN & ISBN Barcode – settings page.
 */
( function () {
	'use strict';

	var cfg = window.eanbcAdmin || {};
	var t = cfg.i18n || {};
	var form = document.getElementById( 'eanbc-form' );
	if ( ! form ) {
		return;
	}

	function $( sel, ctx ) {
		return ( ctx || document ).querySelector( sel );
	}
	function $$( sel, ctx ) {
		return Array.prototype.slice.call( ( ctx || document ).querySelectorAll( sel ) );
	}
	function debounce( fn, wait ) {
		var timer;
		return function () {
			var args = arguments;
			clearTimeout( timer );
			timer = setTimeout( function () {
				fn.apply( null, args );
			}, wait );
		};
	}
	function post( action, data ) {
		var body = data instanceof FormData ? data : new FormData();
		if ( ! ( data instanceof FormData ) && data ) {
			Object.keys( data ).forEach( function ( k ) {
				body.append( k, data[ k ] );
			} );
		}
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.json();
			} );
	}
	function result( el, ok, msg ) {
		el.className = 'eanbc-result ' + ( ok ? 'is-ok' : 'is-error' );
		el.textContent = msg;
	}

	/* ---------- Tabs ---------- */
	var tabs = $$( '.eanbc-tab' );
	var panels = $$( '.eanbc-panel' );
	var referer = $( 'input[name="_wp_http_referer"]', form );
	var side = $( '.eanbc-side' );

	function activate( id, focus ) {
		if ( ! $( '[data-panel="' + id + '"]' ) ) {
			id = 'general';
		}
		tabs.forEach( function ( tab ) {
			var on = tab.getAttribute( 'data-tab' ) === id;
			tab.classList.toggle( 'is-active', on );
			tab.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			tab.setAttribute( 'tabindex', on ? '0' : '-1' );
			if ( on && focus ) {
				tab.focus();
			}
		} );
		panels.forEach( function ( p ) {
			p.hidden = p.getAttribute( 'data-panel' ) !== id;
		} );
		// Builder and help have their own content; the side preview is not needed there.
		side.classList.toggle( 'is-hidden', 'builder' === id || 'help' === id );
		document.querySelector( '.eanbc-layout' ).classList.toggle( 'is-wide', 'builder' === id || 'help' === id );
		if ( preview ) {
			schedule(); // Pause or resume the rotating examples.
		}
		// Return to the same tab after saving.
		if ( referer ) {
			referer.value = referer.value.replace( /#.*$/, '' ).replace( /([?&])tab=[^&]*/, '$1' ).replace( /[?&]$/, '' ) + ( referer.value.indexOf( '?' ) > -1 ? '&' : '?' ) + 'tab=' + id;
		}
		if ( history.replaceState ) {
			history.replaceState( null, '', '#' + id );
		}
		try {
			localStorage.setItem( 'eanbcTab', id );
		} catch ( e ) {}
		if ( 'builder' === id ) {
			builderUpdate();
		}
	}

	tabs.forEach( function ( tab, i ) {
		tab.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			activate( tab.getAttribute( 'data-tab' ) );
		} );
		tab.addEventListener( 'keydown', function ( e ) {
			var next = null;
			if ( 'ArrowRight' === e.key ) {
				next = tabs[ ( i + 1 ) % tabs.length ];
			} else if ( 'ArrowLeft' === e.key ) {
				next = tabs[ ( i - 1 + tabs.length ) % tabs.length ];
			}
			if ( next ) {
				e.preventDefault();
				activate( next.getAttribute( 'data-tab' ), true );
			}
		} );
	} );

	/* ---------- Controls ---------- */
	$$( '.eanbc-range input' ).forEach( function ( input ) {
		var out = input.parentNode.querySelector( 'output' );
		input.addEventListener( 'input', function () {
			out.textContent = input.value;
		} );
	} );
	$$( '.eanbc-color input[type=color]' ).forEach( function ( input ) {
		var hex = input.parentNode.querySelector( '.eanbc-color__hex' );
		input.addEventListener( 'input', function () {
			hex.textContent = input.value;
		} );
	} );

	var transparent = $( 'input[name="eanbc_settings[transparent_bg]"]' );
	var bg = $( '#eanbc-bg_color' );
	function syncBg() {
		if ( transparent && bg ) {
			bg.disabled = transparent.checked;
			bg.parentNode.classList.toggle( 'is-disabled', transparent.checked );
		}
	}
	if ( transparent ) {
		transparent.addEventListener( 'change', syncBg );
		syncBg();
	}

	// Disabled inputs are not submitted – re-enable right before saving.
	form.addEventListener( 'submit', function () {
		if ( bg ) {
			bg.disabled = false;
		}
		dirty = false;
	} );

	/* ---------- Dirty state ---------- */
	var dirty = false;
	var state = $( '[data-eanbc-state]' );
	var savebar = $( '.eanbc-savebar' );
	form.addEventListener( 'input', function ( e ) {
		if ( e.target.name && 0 === e.target.name.indexOf( 'eanbc_settings' ) ) {
			dirty = true;
			state.textContent = t.unsaved;
			savebar.classList.add( 'is-dirty' );
		}
	} );
	form.addEventListener( 'change', function ( e ) {
		if ( e.target.name && 0 === e.target.name.indexOf( 'eanbc_settings' ) ) {
			dirty = true;
			state.textContent = t.unsaved;
			savebar.classList.add( 'is-dirty' );
		}
	} );
	window.addEventListener( 'beforeunload', function ( e ) {
		if ( dirty ) {
			e.preventDefault();
			e.returnValue = t.leavePrompt;
		}
	} );
	// Ctrl/Cmd + S saves.
	document.addEventListener( 'keydown', function ( e ) {
		if ( ( e.ctrlKey || e.metaKey ) && 's' === e.key.toLowerCase() ) {
			e.preventDefault();
			if ( bg ) {
				bg.disabled = false;
			}
			dirty = false;
			form.submit();
		}
	} );

	/* ---------- Preview ---------- */
	function settingsData() {
		var fd = new FormData();
		var wasDisabled = bg && bg.disabled;
		if ( bg ) {
			bg.disabled = false;
		}
		new FormData( form ).forEach( function ( v, k ) {
			if ( 0 === k.indexOf( 'eanbc_settings' ) ) {
				fd.append( k, v );
			}
		} );
		if ( bg ) {
			bg.disabled = wasDisabled;
		}
		return fd;
	}

	// Rendered HTML of a code with the current (unsaved) settings.
	function fetchPreview( atts, mode ) {
		var fd = settingsData();
		Object.keys( atts ).forEach( function ( k ) {
			if ( '' !== atts[ k ] ) {
				fd.append( 'atts[' + k + ']', atts[ k ] );
			}
		} );
		fd.append( 'mode', mode || 'barcode' );
		return post( 'eanbc_preview', fd )
			.then( function ( res ) {
				return res && res.success ? res.data.html : '<p class="eanbc-error">' + t.error + '</p>';
			}, function () {
				return '<p class="eanbc-error">' + t.error + '</p>';
			} );
	}

	function render( stage, atts, mode ) {
		stage.classList.add( 'is-loading' );
		fetchPreview( atts, mode ).then( function ( html ) {
			stage.innerHTML = html;
			stage.classList.remove( 'is-loading' );
		} );
	}

	/* ---------- Live preview with rotating examples ---------- */
	var previewStage = $( '[data-eanbc-preview]' );
	var previewEan = $( '#eanbc-preview-ean' );
	var examples = $$( '[data-eanbc-example]' );
	var rotateBtn = $( '[data-eanbc-rotate]' );
	var progress = $( '[data-eanbc-progress]' );
	var caption = $( '[data-eanbc-caption]' );
	var intervalField = $( '#eanbc-preview_interval' );
	var fadeField = $( '#eanbc-preview_fade' );
	var preview = {
		index: 0,
		mode: 'barcode',
		playing: false,
		hold: false,
		timer: null,
		cache: {},
		pending: {},
		gen: 0,
		seq: 0,
		captionSeq: 0,
		loadingTimer: null,
		heightAnim: null,
		interval: 4000
	};

	// Seconds between examples from the (unsaved) setting; 0 = no rotation.
	function applyInterval() {
		var sec = parseInt( intervalField ? intervalField.value : cfg.previewInterval, 10 );
		var auto;
		sec = isNaN( sec ) ? 4 : sec;
		auto = sec > 0 && examples.length > 1;
		preview.interval = sec * 1000;
		rotateBtn.hidden = ! auto;
		progress.hidden = ! auto;
		// The admin chose an interval; with "reduce motion" only the animations are off (CSS).
		setPlaying( auto );
	}

	// Fade duration in ms from the (unsaved) setting; 0 = switch instantly.
	function fadeMs() {
		var ms = parseInt( fadeField ? fadeField.value : cfg.previewFade, 10 );
		return isNaN( ms ) ? 350 : ms;
	}

	var reduceMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	function cacheKey( mode, ean ) {
		return mode + '|' + ean;
	}

	// One request per code and settings state; also used to preload the next example.
	function loadHtml( mode, ean ) {
		var key = cacheKey( mode, ean );
		var gen = preview.gen;
		if ( ! preview.pending[ key ] ) {
			preview.pending[ key ] = fetchPreview( { ean: ean }, mode ).then( function ( html ) {
				if ( gen === preview.gen ) {
					preview.cache[ key ] = html;
				}
				return html;
			} );
		}
		return preview.pending[ key ];
	}

	function prefetch( i ) {
		var btn = examples[ i ];
		if ( btn ) {
			loadHtml( btn.getAttribute( 'data-mode' ), btn.getAttribute( 'data-eanbc-example' ) );
		}
	}

	function stopAnimations( el ) {
		if ( el.getAnimations ) {
			el.getAnimations().forEach( function ( anim ) {
				anim.cancel();
			} );
		}
	}

	/*
	 * Show new HTML in the stage. With fade, the old example fades out while the new one
	 * fades in (both stacked in the same grid cell) and the height follows smoothly.
	 * Uses the Web Animations API; with "reduce motion" only the opacity changes.
	 */
	function putStage( html, fade ) {
		var ms = fade ? fadeMs() : 0;
		var layers = $$( '.eanbc-layer', previewStage );
		var current = layers[ layers.length - 1 ];
		var layer = document.createElement( 'div' );
		var from, h0, h1, out;
		clearTimeout( preview.loadingTimer );
		previewStage.classList.remove( 'is-loading' );
		layer.className = 'eanbc-layer';
		layer.innerHTML = html;
		if ( preview.heightAnim ) {
			h0 = previewStage.offsetHeight; // Height in the middle of a running change.
			preview.heightAnim.cancel();
			preview.heightAnim = null;
		}
		if ( ! ms || ! current || ! layer.animate ) {
			previewStage.innerHTML = '';
			previewStage.appendChild( layer );
			previewStage.classList.remove( 'is-swapping' );
			return;
		}
		layers.forEach( function ( el ) {
			if ( el !== current ) {
				previewStage.removeChild( el ); // Leftover of an unfinished change.
			}
		} );
		from = parseFloat( getComputedStyle( current ).opacity );
		stopAnimations( current );
		h0 = h0 || previewStage.offsetHeight;
		current.style.display = 'none';
		previewStage.appendChild( layer );
		h1 = previewStage.offsetHeight;
		current.style.display = '';
		current.classList.add( 'is-leaving' );
		current.setAttribute( 'aria-hidden', 'true' );

		out = current.animate( [ { opacity: from }, { opacity: 0 } ], { duration: ms, easing: 'ease-in-out', fill: 'forwards' } );
		out.onfinish = function () {
			if ( current.parentNode ) {
				current.parentNode.removeChild( current );
			}
		};
		layer.animate(
			reduceMotion ? [ { opacity: 0 }, { opacity: 1 } ] : [ { opacity: 0, transform: 'translateY(4px)' }, { opacity: 1, transform: 'none' } ],
			{ duration: ms, easing: 'ease-in-out' }
		);
		if ( ! reduceMotion && Math.abs( h1 - h0 ) > 1 ) {
			previewStage.classList.add( 'is-swapping' );
			preview.heightAnim = previewStage.animate( [ { height: h0 + 'px' }, { height: h1 + 'px' } ], { duration: ms, easing: 'ease-in-out' } );
			preview.heightAnim.onfinish = function () {
				preview.heightAnim = null;
				previewStage.classList.remove( 'is-swapping' );
			};
		}
	}

	// Caption: fade out, swap the text, fade in.
	function setCaption( text, fade ) {
		var ms = fade ? fadeMs() : 0;
		var token = ++preview.captionSeq;
		stopAnimations( caption );
		if ( ! ms || ! caption.animate || caption.textContent === text ) {
			caption.textContent = text;
			return;
		}
		caption.animate( [ { opacity: 1 }, { opacity: 0 } ], { duration: ms / 2, easing: 'ease-in', fill: 'forwards' } ).onfinish = function () {
			if ( token !== preview.captionSeq ) {
				return;
			}
			caption.textContent = text;
			stopAnimations( caption );
			caption.animate( [ { opacity: 0 }, { opacity: 1 } ], { duration: ms / 2, easing: 'ease-out' } );
		};
	}

	// fade: true when another example is shown; settings changes update without a fade.
	function showPreview( fade ) {
		var key = cacheKey( preview.mode, previewEan.value );
		var seq = ++preview.seq; // Only the newest answer may win.
		if ( Object.prototype.hasOwnProperty.call( preview.cache, key ) ) {
			putStage( preview.cache[ key ], fade );
			return;
		}
		// Dim only when loading takes noticeably long.
		clearTimeout( preview.loadingTimer );
		preview.loadingTimer = setTimeout( function () {
			previewStage.classList.add( 'is-loading' );
		}, 150 );
		loadHtml( preview.mode, previewEan.value ).then( function ( html ) {
			if ( seq === preview.seq ) {
				putStage( html, fade );
			}
		} );
	}

	function canRotate() {
		return preview.playing && ! preview.hold && ! document.hidden && ! side.classList.contains( 'is-hidden' );
	}

	function schedule() {
		clearTimeout( preview.timer );
		progress.classList.remove( 'is-running' );
		if ( ! canRotate() ) {
			return;
		}
		void progress.offsetWidth; // Restart the CSS animation.
		progress.style.setProperty( '--eanbc-interval', preview.interval + 'ms' );
		progress.classList.add( 'is-running' );
		prefetch( ( preview.index + 1 ) % examples.length ); // Ready before the change.
		preview.timer = setTimeout( function () {
			selectExample( ( preview.index + 1 ) % examples.length, false );
		}, preview.interval );
	}

	function selectExample( i, byUser ) {
		var btn = examples[ i ];
		preview.index = i;
		preview.mode = btn.getAttribute( 'data-mode' );
		previewEan.value = btn.getAttribute( 'data-eanbc-example' );
		examples.forEach( function ( b, j ) {
			b.setAttribute( 'aria-pressed', j === i ? 'true' : 'false' );
		} );
		setCaption( btn.getAttribute( 'data-caption' ), true );
		// Screen readers only announce changes the user asked for.
		previewStage.setAttribute( 'aria-live', byUser ? 'polite' : 'off' );
		showPreview( true );
		if ( byUser ) {
			setPlaying( false );
		} else {
			schedule();
		}
	}

	function setPlaying( on ) {
		preview.playing = on;
		rotateBtn.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		rotateBtn.querySelector( '.dashicons' ).className = 'dashicons dashicons-controls-' + ( on ? 'pause' : 'play' );
		rotateBtn.querySelector( '[data-eanbc-rotate-label]' ).textContent = on ? t.pauseExamples : t.playExamples;
		rotateBtn.title = on ? t.pauseExamples : t.playExamples;
		schedule();
	}

	var refreshPreview = debounce( function () {
		// Settings changed – every example looks different now.
		preview.cache = {};
		preview.pending = {};
		preview.gen++;
		showPreview();
	}, 250 );
	function onSettingChange( e ) {
		if ( intervalField && e.target === intervalField ) {
			applyInterval(); // Only the timing changed, the codes look the same.
			return;
		}
		if ( fadeField && e.target === fadeField ) {
			var cur = $$( '.eanbc-layer', previewStage ).pop();
			if ( 'change' === e.type && cur ) {
				putStage( cur.innerHTML, true ); // Show the chosen fade once.
			}
			return;
		}
		refreshPreview();
	}
	form.addEventListener( 'input', onSettingChange );
	form.addEventListener( 'change', onSettingChange );

	previewEan.addEventListener( 'input', function () {
		examples.forEach( function ( b ) {
			b.setAttribute( 'aria-pressed', 'false' );
		} );
		setCaption( t.ownInput, false );
		previewStage.setAttribute( 'aria-live', 'polite' );
		setPlaying( false );
		refreshPreview();
	} );
	examples.forEach( function ( btn, i ) {
		btn.addEventListener( 'click', function () {
			selectExample( i, true );
		} );
	} );
	rotateBtn.addEventListener( 'click', function () {
		var start = ! preview.playing;
		setPlaying( start );
		if ( start ) {
			selectExample( ( preview.index + 1 ) % examples.length, false );
		}
	} );
	// Hold only while the user is typing an own code; pausing is the job of the button.
	previewEan.addEventListener( 'focus', function () {
		preview.hold = true;
		schedule();
	} );
	previewEan.addEventListener( 'blur', function () {
		preview.hold = false;
		schedule();
	} );
	document.addEventListener( 'visibilitychange', schedule );

	/* ---------- Builder ---------- */
	var builder = $( '[data-eanbc-builder]' );
	var builderCode = $( '[data-eanbc-builder-code]' );
	var builderStage = $( '[data-eanbc-builder-preview]' );
	var builderRender = debounce( function ( atts, tag ) {
		var mode = /_product$/.test( tag ) ? 'product' : ( /_name$/.test( tag ) ? 'name' : ( /_qr$/.test( tag ) ? 'qr' : 'barcode' ) );
		render( builderStage, atts, mode );
	}, 250 );

	function builderUpdate() {
		if ( ! builder ) {
			return;
		}
		var tag = 'barcode';
		var atts = {};
		var parts = [];
		$$( '[data-b]', builder ).forEach( function ( f ) {
			var key = f.getAttribute( 'data-b' );
			var val = f.value.trim().replace( /"/g, '' );
			if ( '__tag' === key ) {
				tag = val;
				return;
			}
			if ( '' !== val ) {
				atts[ key ] = val;
				parts.push( key + '="' + val + '"' );
			}
		} );
		var sc = '[' + tag + ( parts.length ? ' ' + parts.join( ' ' ) : '' ) + ']';
		builderCode.textContent = sc;
		builderCode.parentNode.querySelector( '[data-eanbc-copy]' ).setAttribute( 'data-eanbc-copy', sc );
		builderRender( atts, tag );
	}
	if ( builder ) {
		builder.addEventListener( 'input', builderUpdate );
		builder.addEventListener( 'change', builderUpdate );
	}

	/* ---------- Copy ---------- */
	function copyText( text ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text );
		}
		return new Promise( function ( resolve ) {
			var ta = document.createElement( 'textarea' );
			ta.value = text;
			ta.style.position = 'fixed';
			ta.style.opacity = '0';
			document.body.appendChild( ta );
			ta.select();
			document.execCommand( 'copy' );
			document.body.removeChild( ta );
			resolve();
		} );
	}
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-eanbc-copy]' );
		if ( ! btn ) {
			return;
		}
		copyText( btn.getAttribute( 'data-eanbc-copy' ) ).then( function () {
			btn.classList.add( 'is-copied' );
			btn.setAttribute( 'data-tooltip', t.copied );
			setTimeout( function () {
				btn.classList.remove( 'is-copied' );
				btn.removeAttribute( 'data-tooltip' );
			}, 1400 );
		} );
	} );

	/* ---------- Conditional rows (data-eanbc-show-if="key=value") ---------- */
	$$( '[data-eanbc-show-if]' ).forEach( function ( row ) {
		var cond = row.getAttribute( 'data-eanbc-show-if' ).split( '=' );
		var field = $( '[name="eanbc_settings[' + cond[ 0 ] + ']"]', form );
		function sync() {
			row.hidden = ! field || field.value !== cond[ 1 ];
		}
		if ( field ) {
			field.addEventListener( 'change', sync );
		}
		sync();
	} );

	/* ---------- Data sources ---------- */
	var providerList = $( '[data-eanbc-providers]' );
	function markDirty() {
		dirty = true;
		state.textContent = t.unsaved;
		savebar.classList.add( 'is-dirty' );
	}
	$$( '.eanbc-provider' ).forEach( function ( row ) {
		var id = row.getAttribute( 'data-provider' );
		var tokenInput = $( '[data-eanbc-token]', row );
		var toggleBtn = $( '[data-eanbc-toggle-token]', row );
		var testBtn = $( '[data-eanbc-test]', row );
		var out = $( '[data-eanbc-test-result]', row );

		if ( toggleBtn && tokenInput ) {
			toggleBtn.addEventListener( 'click', function () {
				var show = 'password' === tokenInput.type;
				tokenInput.type = show ? 'text' : 'password';
				toggleBtn.textContent = show ? t.hide : t.show;
			} );
		}
		testBtn.addEventListener( 'click', function () {
			out.className = 'eanbc-result is-busy';
			out.textContent = t.testing;
			testBtn.disabled = true;
			post( 'eanbc_test_provider', { provider: id, token: tokenInput ? tokenInput.value : '' } )
				.then( function ( res ) {
					result( out, res.success, res.data && res.data.message ? res.data.message : t.error );
				} )
				.catch( function () {
					result( out, false, t.error );
				} )
				.then( function () {
					testBtn.disabled = false;
				} );
		} );
		$$( '[data-eanbc-move]', row ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var up = '-1' === btn.getAttribute( 'data-eanbc-move' );
				var sibling = up ? row.previousElementSibling : row.nextElementSibling;
				if ( ! sibling ) {
					return;
				}
				providerList.insertBefore( row, up ? sibling : sibling.nextElementSibling );
				btn.focus();
				markDirty();
				refreshPreview();
			} );
		} );
	} );

	/* ---------- Reset per tab ---------- */
	var defaults = cfg.defaults || {};
	var has = Object.prototype.hasOwnProperty;

	function isBuilderPanel( panel ) {
		return 'builder' === panel.getAttribute( 'data-panel' );
	}

	// Fields a reset touches: saved settings with a known default, or all builder fields.
	function resetFields( panel ) {
		if ( isBuilderPanel( panel ) ) {
			return $$( '[data-b]', panel );
		}
		return $$( '[name]', panel ).filter( function ( el ) {
			return has.call( defaults, el.name );
		} );
	}

	function wanted( el, builderMode ) {
		var opt;
		if ( ! builderMode ) {
			return defaults[ el.name ];
		}
		if ( 'SELECT' === el.tagName ) {
			opt = $$( 'option', el ).filter( function ( o ) {
				return o.defaultSelected;
			} )[ 0 ] || el.options[ 0 ];
			return opt ? opt.value : '';
		}
		return el.defaultValue;
	}

	function differs( el, want ) {
		if ( 'checkbox' === el.type ) {
			return el.checked !== ( '1' === want );
		}
		if ( 'radio' === el.type ) {
			return el.checked !== ( el.value === want );
		}
		return String( el.value ).toLowerCase() !== String( want ).toLowerCase();
	}

	function setField( el, value, checked ) {
		if ( 'checkbox' === el.type || 'radio' === el.type ) {
			el.checked = checked;
		} else {
			el.value = value;
		}
	}

	function notify( els ) {
		els.forEach( function ( el ) {
			el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );
	}

	function providerRows() {
		return providerList ? $$( '.eanbc-provider', providerList ) : [];
	}

	function rowOrder() {
		return providerRows().map( function ( r ) {
			return r.getAttribute( 'data-provider' );
		} );
	}

	// Default order: known providers first (default order), others keep their place behind.
	function defaultOrder() {
		var now = rowOrder();
		var first = ( cfg.providerOrder || [] ).filter( function ( id ) {
			return now.indexOf( id ) > -1;
		} );
		return first.concat( now.filter( function ( id ) {
			return first.indexOf( id ) < 0;
		} ) );
	}

	function setOrder( ids ) {
		ids.forEach( function ( id ) {
			var row = providerList.querySelector( '.eanbc-provider[data-provider="' + id + '"]' );
			if ( row ) {
				providerList.appendChild( row );
			}
		} );
	}

	function panelChanges( panel ) {
		var builderMode = isBuilderPanel( panel );
		var fields = resetFields( panel ).filter( function ( el ) {
			return differs( el, wanted( el, builderMode ) );
		} );
		var order = panel.contains( providerList ) && rowOrder().join() !== defaultOrder().join();
		return { fields: fields, order: order };
	}

	function updateResetButtons() {
		$$( '[data-eanbc-reset]' ).forEach( function ( bar ) {
			var c = panelChanges( bar.closest( '.eanbc-panel' ) );
			var btn = $( '[data-eanbc-reset-btn]', bar );
			btn.disabled = ! c.fields.length && ! c.order;
			btn.title = btn.disabled ? t.resetNothing : '';
		} );
	}

	$$( '[data-eanbc-reset]' ).forEach( function ( bar ) {
		var panel = bar.closest( '.eanbc-panel' );
		var btn = $( '[data-eanbc-reset-btn]', bar );
		var status = $( '[data-eanbc-reset-status]', bar );
		var builderMode = isBuilderPanel( panel );

		function say( text, undo ) {
			status.textContent = text + ' ';
			if ( undo ) {
				var link = document.createElement( 'button' );
				link.type = 'button';
				link.className = 'eanbc-linkbtn eanbc-reset__undo';
				link.textContent = t.undo;
				link.addEventListener( 'click', undo );
				status.appendChild( link );
			}
		}

		btn.addEventListener( 'click', function () {
			var c = panelChanges( panel );
			var fields = resetFields( panel );
			var snapshot = fields.map( function ( el ) {
				return { el: el, value: el.value, checked: el.checked };
			} );
			var order = rowOrder();
			if ( ! c.fields.length && ! c.order ) {
				say( t.resetNothing );
				return;
			}
			fields.forEach( function ( el ) {
				var want = wanted( el, builderMode );
				setField( el, want, 'radio' === el.type ? el.value === want : '1' === want );
			} );
			if ( c.order ) {
				setOrder( defaultOrder() );
				markDirty();
				refreshPreview();
			}
			notify( c.fields );
			if ( builderMode ) {
				builderUpdate();
			}
			updateResetButtons();
			say( builderMode ? t.resetBuilder : t.resetDone, function () {
				snapshot.forEach( function ( s ) {
					setField( s.el, s.value, s.checked );
				} );
				if ( c.order ) {
					setOrder( order );
					refreshPreview();
				}
				notify( c.fields );
				if ( builderMode ) {
					builderUpdate();
				}
				updateResetButtons();
				say( t.resetUndone );
				btn.focus();
			} );
		} );
	} );

	form.addEventListener( 'input', updateResetButtons );
	form.addEventListener( 'change', updateResetButtons );
	if ( providerList ) {
		providerList.addEventListener( 'click', function ( e ) {
			if ( e.target.closest( '[data-eanbc-move]' ) ) {
				updateResetButtons();
			}
		} );
	}
	updateResetButtons();

	/* ---------- Cache ---------- */
	var clearBtn = $( '[data-eanbc-clear]' );
	if ( clearBtn ) {
		clearBtn.addEventListener( 'click', function () {
			var out = $( '[data-eanbc-clear-result]' );
			out.className = 'eanbc-result is-busy';
			out.textContent = t.clearing;
			post( 'eanbc_clear_cache', {} )
				.then( function ( res ) {
					result( out, res.success, res.data && res.data.message ? res.data.message : t.error );
				} )
				.catch( function () {
					result( out, false, t.error );
				} );
		} );
	}

	/* ---------- Init ---------- */
	var initial = ( location.hash || '' ).replace( '#', '' );
	if ( ! initial ) {
		var m = location.search.match( /[?&]tab=([a-z]+)/ );
		initial = m ? m[ 1 ] : '';
	}
	if ( ! initial ) {
		try {
			initial = localStorage.getItem( 'eanbcTab' ) || 'general';
		} catch ( e ) {
			initial = 'general';
		}
	}
	activate( initial );
	applyInterval();
	selectExample( 0, false );
}() );
