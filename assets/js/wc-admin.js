/**
 * EAN & ISBN Barcodes – "Look up product data" next to the GTIN field in the WooCommerce product editor.
 */
( function () {
	'use strict';

	var cfg = window.eanbcWc;
	if ( ! cfg ) {
		return;
	}
	var t = cfg.i18n;

	function el( tag, cls, text ) {
		var node = document.createElement( tag );
		if ( cls ) {
			node.className = cls;
		}
		if ( undefined !== text ) {
			node.textContent = text;
		}
		return node;
	}

	function postId() {
		var input = document.getElementById( 'post_ID' );
		return input ? parseInt( input.value, 10 ) || 0 : 0;
	}

	function setTitle( name, box ) {
		var title = document.getElementById( 'title' );
		if ( ! title ) {
			return;
		}
		title.value = name;
		title.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		title.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		var prompt = document.getElementById( 'title-prompt-text' );
		if ( prompt ) {
			prompt.classList.add( 'screen-reader-text' );
		}
		box.querySelector( '.eanbc-wc-lookup__done' ).textContent = t.titleSet;
	}

	function showResult( box, data ) {
		box.innerHTML = '';
		box.className = 'eanbc-wc-lookup__result is-ok';
		var list = el( 'dl' );
		[ 'name', 'brand', 'authors', 'publisher', 'year', 'category', 'country' ].forEach( function ( key ) {
			if ( data[ key ] ) {
				list.appendChild( el( 'dt', '', t[ key ] ) );
				list.appendChild( el( 'dd', '', data[ key ] ) );
			}
		} );
		if ( data.provider_label ) {
			list.appendChild( el( 'dt', '', t.dataSource ) );
			list.appendChild( el( 'dd', '', data.provider_label ) );
		}
		box.appendChild( list );
		if ( document.getElementById( 'title' ) && data.name ) {
			var use = el( 'button', 'button', t.useTitle );
			use.type = 'button';
			use.addEventListener( 'click', function () {
				setTitle( data.name, box );
			} );
			box.appendChild( use );
		}
		box.appendChild( el( 'span', 'eanbc-wc-lookup__done' ) );
	}

	function showError( box, message, withSetup ) {
		box.innerHTML = '';
		box.className = 'eanbc-wc-lookup__result is-error';
		box.appendChild( el( 'span', '', message ) );
		if ( withSetup && cfg.sourcesUrl ) {
			box.appendChild( document.createTextNode( ' ' ) );
			var a = el( 'a', '', t.setup );
			a.href = cfg.sourcesUrl;
			box.appendChild( a );
		}
	}

	function attach( input ) {
		var row = input.closest( '.form-field' ) || input.parentNode;
		var wrap = el( 'p', 'form-field eanbc-wc-lookup' );
		var button = el( 'button', 'button', t.lookup );
		var box = el( 'div', 'eanbc-wc-lookup__result' );
		button.type = 'button';
		box.setAttribute( 'aria-live', 'polite' );
		wrap.appendChild( button );
		wrap.appendChild( box );
		row.parentNode.insertBefore( wrap, row.nextSibling );

		button.addEventListener( 'click', function () {
			var code = input.value.trim();
			if ( ! code ) {
				showError( box, t.empty );
				input.focus();
				return;
			}
			if ( ! cfg.hasSource ) {
				showError( box, t.noSource, true );
				return;
			}
			button.disabled = true;
			box.className = 'eanbc-wc-lookup__result';
			box.textContent = t.looking;
			var url = cfg.restUrl + ( cfg.restUrl.indexOf( '?' ) > -1 ? '&' : '?' ) + 'ean=' + encodeURIComponent( code ) + '&post_id=' + postId();
			fetch( url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } } )
				.then( function ( response ) {
					return response.json().then( function ( json ) {
						return { ok: response.ok, json: json };
					} );
				} )
				.then( function ( res ) {
					if ( res.ok ) {
						showResult( box, res.json );
					} else {
						showError( box, ( res.json && res.json.message ) || t.error );
					}
				} )
				[ 'catch' ]( function () {
					showError( box, t.error );
				} )
				.then( function () {
					button.disabled = false;
				} );
		} );
	}

	function init() {
		// One button is enough – at the first GTIN field on the page (WooCommerce's own field comes first).
		cfg.fields.some( function ( id ) {
			var input = document.getElementById( id );
			if ( input && 'INPUT' === input.tagName ) {
				attach( input );
				return true;
			}
			return false;
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
