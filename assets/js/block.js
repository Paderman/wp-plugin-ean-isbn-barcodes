/**
 * EAN & ISBN Barcode block (plain ES5, no build step needed).
 */
( function ( wp, cfg ) {
	'use strict';

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var be = wp.blockEditor;
	var c = wp.components;
	var SSR = wp.serverSideRender;
	var t = cfg.i18n;

	var icon = el(
		'svg',
		{ viewBox: '0 0 24 24', xmlns: 'http://www.w3.org/2000/svg' },
		el( 'path', { d: 'M2 4h2v16H2zm3 0h1v16H5zm2 0h2v16H7zm3 0h1v16h-1zm3 0h2v16h-2zm3 0h1v16h-1zm2 0h1v16h-1zm2 0h2v16h-2z' } )
	);

	function triState( label, value, onChange ) {
		return el( c.SelectControl, {
			label: label,
			value: value,
			options: [
				{ label: t[ 'default' ], value: '' },
				{ label: t.yes, value: 'yes' },
				{ label: t.no, value: 'no' }
			],
			onChange: onChange,
			__nextHasNoMarginBottom: true
		} );
	}

	function select( label, value, options, onChange, help ) {
		return el( c.SelectControl, {
			label: label,
			value: value,
			options: [ { label: t[ 'default' ], value: '' } ].concat( options ),
			help: help,
			onChange: onChange,
			__nextHasNoMarginBottom: true
		} );
	}

	function text( label, value, onChange, help, extra ) {
		var props = {
			label: label,
			value: value,
			help: help,
			onChange: onChange,
			__nextHasNoMarginBottom: true
		};
		for ( var k in extra || {} ) {
			props[ k ] = extra[ k ];
		}
		return el( c.TextControl, props );
	}

	/**
	 * ID of the post being edited (0 in the site editor / widgets).
	 *
	 * @return {number} Post ID.
	 */
	function currentPostId() {
		var editor = wp.data.select( 'core/editor' );
		return ( editor && editor.getCurrentPostId && parseInt( editor.getCurrentPostId(), 10 ) ) || 0;
	}

	/**
	 * Own product name + button to take it over once from ean-search.org.
	 *
	 * @param {Object} props Block props.
	 * @return {Object} Element.
	 */
	function TitleControl( props ) {
		var a = props.attributes;
		var busyState = useState( false );
		var noticeState = useState( null );
		var busy = busyState[ 0 ];
		var notice = noticeState[ 0 ];

		function fetchName() {
			busyState[ 1 ]( true );
			noticeState[ 1 ]( null );
			var path = '/eanbc/v1/lookup?ean=' + encodeURIComponent( a.ean || '' ) +
				'&type=' + encodeURIComponent( a.type || '' ) +
				'&post_id=' + currentPostId();
			wp.apiFetch( { path: path } ).then( function ( data ) {
				props.setAttributes( { title: data.name } );
				var details = [];
				[ [ 'authors', t.authors ], [ 'brand', t.brand ], [ 'category', t.category ], [ 'country', t.country ], [ 'provider_label', t.dataSource ] ].forEach( function ( f ) {
					if ( data[ f[ 0 ] ] ) {
						details.push( f[ 1 ] + ': ' + data[ f[ 0 ] ] );
					}
				} );
				noticeState[ 1 ]( { status: 'success', text: t.fetched + ( details.length ? ' (' + details.join( ', ' ) + ')' : '' ) } );
			} ).catch( function ( err ) {
				noticeState[ 1 ]( { status: 'error', text: ( err && err.message ) || String( err ) } );
			} ).then( function () {
				busyState[ 1 ]( false );
			} );
		}

		return el(
			'div',
			{ className: 'eanbc-title-control' },
			text( t.title, a.title, function ( v ) {
				props.setAttributes( { title: v } );
			}, t.titleHelp ),
			el(
				'div',
				{ style: { margin: '8px 0 16px' } },
				el( c.Button, {
					variant: 'secondary',
					isBusy: busy,
					disabled: busy || ! cfg.hasSource,
					onClick: fetchName,
					__next40pxDefaultSize: true,
					style: { width: '100%', justifyContent: 'center', height: 'auto', minHeight: '40px', whiteSpace: 'normal', textAlign: 'center' }
				}, t.fetchName ),
				! cfg.hasSource ? el(
					'p',
					{ className: 'components-base-control__help' },
					t.fetchNeedsToken,
					cfg.sourcesUrl ? ' ' : null,
					cfg.sourcesUrl ? el( 'a', { href: cfg.sourcesUrl, target: '_blank', rel: 'noopener' }, t.enterToken ) : null
				) : null,
				notice ? el( c.Notice, {
					status: notice.status,
					isDismissible: true,
					onRemove: function () {
						noticeState[ 1 ]( null );
					}
				}, notice.text ) : null
			)
		);
	}

	wp.blocks.registerBlockType( 'eanbc/barcode', {
		title: t.blockTitle,
		icon: icon,
		edit: function ( props ) {
			var a = props.attributes;
			var set = function ( key ) {
				return function ( v ) {
					var o = {};
					o[ key ] = v;
					props.setAttributes( o );
				};
			};
			var blockProps = be.useBlockProps();
			var isQr = 'qr' === a.mode;
			var isBarcode = 'product' !== a.mode && 'name' !== a.mode && ! isQr;
			var hasGraphic = 'name' !== a.mode;
			var hasBars = hasGraphic && ! isQr;
			var transparent = 'none' === a.background;

			var main = el(
				c.PanelBody,
				{ title: t.barcode, initialOpen: true },
				text( t.ean, a.ean, set( 'ean' ), t.eanHelp ),
				el( c.SelectControl, {
					label: t.format,
					value: a.type,
					options: [
						{ label: t.formatAuto, value: '' },
						{ label: 'EAN-13', value: 'ean13' },
						{ label: 'EAN-8', value: 'ean8' },
						{ label: 'UPC-A', value: 'upca' },
						{ label: 'UPC-E', value: 'upce' },
						{ label: t.formatIsbn, value: 'isbn' },
						{ label: t.formatIssn, value: 'issn' },
						{ label: t.formatIsmn, value: 'ismn' }
					],
					onChange: set( 'type' ),
					__nextHasNoMarginBottom: true
				} ),
				el( c.SelectControl, {
					label: t.mode,
					value: a.mode,
					options: [
						{ label: t.modeBarcode, value: 'barcode' },
						{ label: t.modeProduct, value: 'product' },
						{ label: t.modeName, value: 'name' },
						{ label: t.modeQr, value: 'qr' }
					],
					help: isQr ? t.qrHelp : ( ( 'barcode' !== a.mode && ! cfg.hasSource ) ? t.needsToken : undefined ),
					onChange: set( 'mode' ),
					__nextHasNoMarginBottom: true
				} ),
				hasBars ? text( t.addon, a.addon, set( 'addon' ), t.addonHelp, { inputMode: 'numeric', maxLength: 5, placeholder: '52499' } ) : null,
				isQr ? text( t.batch, a.batch, set( 'batch' ), undefined, { maxLength: 20 } ) : null,
				isQr ? text( t.serial, a.serial, set( 'serial' ), undefined, { maxLength: 20 } ) : null,
				isQr ? null : el( TitleControl, props ),
				hasBars ? text( t.moduleWidth, a.width, set( 'width' ), undefined, { type: 'number', min: 1, max: 6, step: 0.5, placeholder: t[ 'default' ] } ) : null,
				hasBars ? text( t.height, a.height, set( 'height' ), undefined, { type: 'number', min: 20, max: 300, placeholder: t[ 'default' ] } ) : null,
				hasGraphic ? triState( isQr ? t.qrNumber : t.number, a.number, set( 'number' ) ) : null,
				isBarcode ? triState( t.name, a.name, set( 'name' ) ) : null,
				triState( t.link, a.link, set( 'link' ) ),
				isBarcode ? select( t.style, a.look, [
					{ label: t.stylePlain, value: 'plain' },
					{ label: t.styleCard, value: 'card' }
				], set( 'look' ) ) : null,
				isBarcode ? text( t.caption, a.caption, set( 'caption' ) ) : null,
				el( 'p', { className: 'components-base-control__help' }, t.globalHint ),
				cfg.settingsUrl ? el( c.ExternalLink, { href: cfg.settingsUrl }, t.openSettings ) : null
			);

			var colors = hasGraphic ? el( be.PanelColorSettings, {
				title: t.colors,
				initialOpen: false,
				colorSettings: [
					{ label: t.barColor, value: a.color || undefined, onChange: function ( v ) {
						props.setAttributes( { color: v || '' } );
					} }
				].concat( transparent ? [] : [
					{ label: t.bgColor, value: a.background || undefined, onChange: function ( v ) {
						props.setAttributes( { background: v || '' } );
					} }
				] )
			}, el( c.ToggleControl, {
				label: t.transparent,
				checked: transparent,
				onChange: function ( on ) {
					props.setAttributes( { background: on ? 'none' : '' } );
				},
				__nextHasNoMarginBottom: true
			} ) ) : null;

			var advanced = el(
				c.PanelBody,
				{ title: t.advanced, initialOpen: false },
				hasBars ? triState( t.isbnText, a.isbnText, set( 'isbnText' ) ) : null,
				hasBars ? select( t.source, a.source, [
					{ label: t.sourceLocal, value: 'local' },
					{ label: t.sourceApi, value: 'api' }
				], set( 'source' ) ) : null,
				hasGraphic ? select( t.output, a.output, [
					{ label: t.outputInline, value: 'inline' },
					{ label: t.outputImg, value: 'img' }
				], set( 'output' ) ) : null,
				! isBarcode ? text( t.fallback, a.fallback, set( 'fallback' ), t.fallbackHelp ) : null,
				'no' !== a.link ? text( t.url, a.url, set( 'url' ), t.urlHelp, { type: 'url', placeholder: 'https://' } ) : null,
				isBarcode ? el( 'p', { className: 'components-base-control__help' }, t.globalHint ) : null
			);

			var body = el( SSR, { block: 'eanbc/barcode', attributes: a } );

			return el(
				Fragment,
				null,
				el( be.InspectorControls, null, main, colors, advanced ),
				el(
					'div',
					blockProps,
					a.ean
						? body
						: el(
							c.Placeholder,
							{ icon: icon, label: t.blockTitle },
							el( c.TextControl, {
								value: a.ean,
								placeholder: t.placeholder,
								onChange: set( 'ean' ),
								__nextHasNoMarginBottom: true
							} ),
							el( 'div', { style: { width: '100%', marginTop: '12px' } }, body )
						)
				)
			);
		},
		save: function () {
			return null;
		}
	} );
}( window.wp, window.eanbcBlock ) );
