<?php
/**
 * Turns shortcode / block attributes into HTML.
 *
 * @package EANBC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renderer shared by shortcodes, block and admin preview.
 */
class EANBC_Renderer {

	/**
	 * Current settings (respects a temporary override from the live preview).
	 *
	 * @return array
	 */
	private static function settings() {
		return EANBC_Settings::all();
	}

	/**
	 * Interpret yes/no style attribute values.
	 *
	 * @param mixed $value    Value.
	 * @param bool  $fallback Fallback when empty.
	 * @return bool
	 */
	public static function bool( $value, $fallback ) {
		if ( null === $value || '' === $value ) {
			return (bool) $fallback;
		}
		if ( is_bool( $value ) ) {
			return $value;
		}
		return in_array( strtolower( (string) $value ), array( '1', 'yes', 'true', 'on', 'ja', 'y' ), true );
	}

	/**
	 * Normalize attributes against settings.
	 *
	 * @param array $atts Raw attributes.
	 * @return array
	 */
	private static function options( $atts ) {
		$s    = self::settings();
		$atts = array_change_key_case( (array) $atts, CASE_LOWER );
		$get  = static function ( $key ) use ( $atts ) {
			return isset( $atts[ $key ] ) ? trim( (string) $atts[ $key ] ) : '';
		};

		$bg = $get( 'background' );
		if ( '' === $bg ) {
			$bg = $s['transparent_bg'] ? '' : $s['bg_color'];
		} elseif ( in_array( strtolower( $bg ), array( 'none', 'transparent' ), true ) ) {
			$bg = '';
		} else {
			$bg = sanitize_hex_color( $bg ) ? $bg : $s['bg_color'];
		}

		$align  = strtolower( $get( 'align' ) );
		$width  = $get( 'width' );
		$height = $get( 'height' );
		$type   = strtolower( $get( 'type' ) );
		$type   = in_array( $type, EANBC_Barcode::TYPES, true ) ? $type : 'auto';
		$ean    = '';
		// issn="…" / ismn="…" also set the type.
		foreach ( array( 'ean', 'isbn', 'issn', 'ismn', 'code', 'gtin' ) as $key ) {
			if ( '' !== $get( $key ) ) {
				$ean  = $get( $key );
				$type = ( 'auto' === $type && in_array( $key, array( 'issn', 'ismn' ), true ) ) ? $key : $type;
				break;
			}
		}
		if ( '' === $ean && isset( $atts[0] ) && preg_match( '/^(?:(?:ISBN|ISSN|ISMN)[\s\-:]*(?:1[03])?:?)?M?[\dXx\s\-+>]+$/i', (string) $atts[0] ) ) {
			$ean = trim( (string) $atts[0] ); // Short form: [barcode 4006381333931], [isbn 3-16-148410-X], [barcode 9783161484100+52499].
		}

		return array(
			'ean'          => $ean,
			'type'         => $type,
			'addon'        => $get( 'addon' ),
			'field'        => sanitize_key( $get( 'field' ) ),
			'post_id'      => absint( $get( 'id' ) ),
			'module_width' => '' !== $width ? max( 1, min( 6, (float) $width ) ) : (float) $s['module_width'],
			'bar_height'   => '' !== $height ? max( 20, min( 300, (int) $height ) ) : (int) $s['bar_height'],
			'bar_color'    => sanitize_hex_color( $get( 'color' ) ) ? $get( 'color' ) : $s['bar_color'],
			'bg_color'     => $bg,
			'show_number'  => self::bool( $get( 'number' ), $s['show_number'] ),
			'show_isbn'    => self::bool( $get( 'isbn_text' ), $s['show_isbn'] ),
			'show_name'    => self::bool( $get( 'name' ), $s['show_name'] ),
			'link'         => self::bool( $get( 'link' ), $s['link'] ),
			'url'          => preg_match( '#^https?://#i', $get( 'url' ) ) ? esc_url_raw( $get( 'url' ) ) : '',
			'align'        => in_array( $align, array( 'none', 'left', 'center', 'right' ), true ) ? $align : $s['align'],
			'source'       => in_array( $get( 'source' ), array( 'local', 'api' ), true ) ? $get( 'source' ) : $s['source'],
			'output'       => in_array( $get( 'output' ), array( 'inline', 'img' ), true ) ? $get( 'output' ) : $s['output'],
			'style'        => in_array( $get( 'style' ), array( 'plain', 'card' ), true ) ? $get( 'style' ) : $s['style'],
			'title'        => $get( 'title' ),
			'caption'      => $get( 'caption' ),
			'class'        => implode( ' ', array_map( 'sanitize_html_class', preg_split( '/\s+/', $get( 'class' ), -1, PREG_SPLIT_NO_EMPTY ) ) ),
			'fallback'     => $get( 'fallback' ),
			'batch'        => $get( 'batch' ),
			'serial'       => $get( 'serial' ),
			'ecc'          => in_array( strtoupper( $get( 'ecc' ) ), array( 'L', 'M', 'Q', 'H' ), true ) ? strtoupper( $get( 'ecc' ) ) : $s['qr_ecc'],
			'qr_module'    => '' !== $get( 'size' ) ? max( 1, min( 20, (float) $get( 'size' ) ) ) : (float) $s['qr_module'],
		);
	}

	/**
	 * Find the EAN: attribute > custom field > WooCommerce GTIN (incl. Germanized, German Market & co.) > common field names.
	 *
	 * @param array $o Options.
	 * @return string
	 */
	private static function resolve_ean( $o ) {
		if ( '' !== $o['ean'] ) {
			return $o['ean'];
		}
		$post_id = $o['post_id'] ? $o['post_id'] : get_the_ID();
		if ( ! $post_id ) {
			return '';
		}
		$s     = self::settings();
		$first = array_filter( array( $o['field'], $s['meta_key'] ) );
		foreach ( array_unique( $first ) as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return (string) $value;
			}
		}
		if ( class_exists( 'EANBC_WooCommerce' ) && function_exists( 'wc_get_product' ) && in_array( get_post_type( $post_id ), array( 'product', 'product_variation' ), true ) ) {
			$gtin = EANBC_WooCommerce::gtin( $post_id );
			if ( '' !== $gtin ) {
				return $gtin;
			}
		}
		foreach ( array( '_global_unique_id', 'ean', '_ean', 'isbn', '_isbn' ) as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return (string) $value;
			}
		}
		return '';
	}

	/**
	 * Error output according to the "invalid_display" setting.
	 *
	 * @param string $message Message.
	 * @param string $raw     The raw code.
	 * @return string
	 */
	private static function error( $message, $raw ) {
		$mode = self::settings()['invalid_display'];
		if ( 'text' === $mode && '' !== $raw ) {
			return '<span class="eanbc-plain">' . esc_html( $raw ) . '</span>';
		}
		if ( 'admins' === $mode && current_user_can( 'edit_posts' ) ) {
			self::enqueue();
			return sprintf(
				'<span class="eanbc-error" role="note"><strong>%s</strong> %s%s</span>',
				esc_html__( 'EAN & ISBN Barcode:', 'ean-isbn-barcodes' ),
				esc_html( $message ),
				'' !== $raw ? ' <code>' . esc_html( $raw ) . '</code>' : ''
			);
		}
		return '';
	}

	/**
	 * Enqueue frontend CSS (only on pages that actually use a barcode).
	 */
	private static function enqueue() {
		wp_enqueue_style( 'eanbc-frontend', EANBC_URL . 'assets/css/frontend.css', array(), eanbc_asset_ver( 'assets/css/frontend.css' ) );
	}

	/**
	 * The barcode graphic: local SVG (inline or as <img>), or optionally a PNG from ean-search.org.
	 *
	 * @param array $parsed Parsed code.
	 * @param array $o      Options.
	 * @return string
	 */
	private static function graphic( $parsed, $o ) {
		$code = $parsed['code'];
		/* translators: 1: barcode type (e.g. EAN-13), 2: the code */
		$alt = sprintf( __( '%1$s barcode %2$s', 'ean-isbn-barcodes' ), EANBC_Barcode::type_label( $parsed['type'] ), $code . ( '' !== $parsed['addon'] ? ' ' . $parsed['addon'] : '' ) );

		// The PNG service only knows the plain formats – everything else is drawn locally.
		$remote = EANBC_Providers::get( 'ean_search' );
		if ( 'api' === $o['source'] && $remote && $remote->is_ready() && '' === $parsed['addon'] && in_array( $parsed['type'], array( 'ean13', 'ean8', 'upca', 'isbn' ), true ) ) {
			$w   = (int) round( ( 'ean8' === $parsed['type'] ? 81 : 113 ) * $o['module_width'] );
			$h   = (int) round( $o['bar_height'] + ( $o['show_number'] ? 10 * $o['module_width'] : 0 ) );
			$png = $remote->image( $parsed['lookup'], $w, $h );
			if ( ! is_wp_error( $png ) ) {
				return sprintf( '<img class="eanbc-img" src="data:image/png;base64,%s" width="%d" height="%d" alt="%s" loading="lazy" decoding="async">', esc_attr( $png ), $w, $h, esc_attr( $alt ) );
			}
			// Silently fall back to the local renderer.
		}

		$headline       = array(
			'isbn' => 'ISBN ' . $parsed['isbn'],
			'issn' => 'ISSN ' . $parsed['issn'],
			'ismn' => 'ISMN ' . $parsed['ismn'],
		);
		$o['isbn_text'] = ( $o['show_isbn'] && isset( $headline[ $parsed['type'] ] ) ) ? $headline[ $parsed['type'] ] : '';
		$o['addon']     = $parsed['addon'];
		$svg            = EANBC_Barcode::svg( $code, $parsed['type'], $o );
		if ( 'img' === $o['output'] ) {
			preg_match( '/width="([\d.]+)" height="([\d.]+)"/', $svg, $dim );
			return sprintf(
				'<img class="eanbc-img" src="data:image/svg+xml;base64,%s" width="%s" height="%s" alt="%s" decoding="async">',
				base64_encode( $svg ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- data URI.
				esc_attr( $dim[1] ),
				esc_attr( $dim[2] ),
				esc_attr( $alt )
			);
		}
		return $svg;
	}

	/**
	 * Where a barcode links to: own URL > URL template > a data source's product page.
	 * Built from patterns only – a link never causes a request.
	 *
	 * @param array      $parsed  Parsed code.
	 * @param array      $o       Options.
	 * @param array|null $product Product data, if already fetched.
	 * @return array|null array( url, label ) – label is the site name or '' for own URLs.
	 */
	private static function link_target( $parsed, $o, $product = null ) {
		if ( ! $o['link'] ) {
			return null;
		}
		if ( '' !== $o['url'] ) {
			return array(
				'url'   => $o['url'],
				'label' => '',
			);
		}
		$s = self::settings();
		if ( 'custom' === $s['link_target'] && '' !== $s['link_template'] ) {
			return array(
				'url'   => str_replace( '{code}', rawurlencode( $parsed['lookup'] ), $s['link_template'] ),
				'label' => '',
			);
		}

		// Chosen site first, then the source that delivered the data, then the usual order
		// (only sites that make sense without data from them, e.g. not a food-only database).
		$candidates = array();
		$chosen     = EANBC_Providers::get( $s['link_target'] );
		if ( $chosen ) {
			$candidates[] = $chosen;
		}
		if ( is_array( $product ) && ! empty( $product['provider'] ) && EANBC_Providers::get( $product['provider'] ) ) {
			$candidates[] = EANBC_Providers::get( $product['provider'] );
		}
		$general = array_filter(
			array_merge( array_values( EANBC_Providers::ready() ), array_values( EANBC_Providers::all() ) ),
			static function ( $provider ) {
				return $provider->link_without_data();
			}
		);
		foreach ( array_merge( $candidates, $general ) as $provider ) {
			$url = $provider->covers( $parsed ) ? $provider->product_url( $parsed ) : '';
			if ( '' !== $url ) {
				return array(
					'url'   => $url,
					'label' => $provider->label(),
				);
			}
		}
		return null;
	}

	/**
	 * Wrap HTML in a link to the target (if any).
	 *
	 * @param string     $html   HTML.
	 * @param array|null $target Result of link_target().
	 * @return string
	 */
	private static function maybe_link( $html, $target ) {
		if ( ! $target ) {
			return $html;
		}
		$s   = self::settings();
		$rel = array( 'noopener' );
		if ( $s['link_nofollow'] ) {
			$rel[] = 'nofollow';
		}
		return sprintf(
			'<a class="eanbc-link" href="%s"%s rel="%s" title="%s">%s</a>',
			esc_url( $target['url'] ),
			$s['link_new_tab'] ? ' target="_blank"' : '',
			esc_attr( implode( ' ', $rel ) ),
			/* translators: %s: website name, e.g. "Open Library" */
			esc_attr( '' !== $target['label'] ? sprintf( __( 'Show product on %s', 'ean-isbn-barcodes' ), $target['label'] ) : __( 'Show product', 'ean-isbn-barcodes' ) ),
			$html
		);
	}

	/**
	 * [barcode] – the barcode itself, optional product name / caption.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function barcode( $atts ) {
		$o      = self::options( $atts );
		$raw    = self::resolve_ean( $o );
		$parsed = EANBC_Barcode::parse( $raw, $o['type'], $o['addon'] );
		if ( ! $parsed['valid'] ) {
			return self::error( $parsed['error'], $raw );
		}
		self::enqueue();
		$o['type'] = $parsed['type'];

		// Own title wins (no request); otherwise the name from the data sources.
		$name    = $o['title'];
		$product = null;
		if ( '' === $name && $o['show_name'] && EANBC_Providers::has_any() ) {
			$found = EANBC_Providers::lookup( $parsed );
			if ( ! is_wp_error( $found ) ) {
				$product = $found;
				$name    = $found['name'];
			}
		}
		$caption = '' !== $name ? '<span class="eanbc-name">' . esc_html( $name ) . '</span>' : '';
		if ( '' !== $o['caption'] ) {
			$caption .= '<span class="eanbc-caption-text">' . esc_html( $o['caption'] ) . '</span>';
		}

		return sprintf(
			'<figure class="%s">%s%s</figure>',
			esc_attr( self::classes( 'eanbc', $o ) ),
			self::maybe_link( self::graphic( $parsed, $o ), self::link_target( $parsed, $o, $product ) ),
			$caption ? '<figcaption class="eanbc-caption">' . $caption . '</figcaption>' : ''
		);
	}

	/**
	 * [ean_qr] – QR code with the GS1 Digital Link of the code (scannable by phones and by
	 * 2D-capable checkout scanners). Optional batch/serial number.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function qr( $atts ) {
		$o      = self::options( $atts );
		$raw    = self::resolve_ean( $o );
		$parsed = EANBC_Barcode::parse( $raw, $o['type'], $o['addon'] );
		if ( ! $parsed['valid'] ) {
			return self::error( $parsed['error'], $raw );
		}
		self::enqueue();

		$uri    = EANBC_Digital_Link::uri( $parsed, $o['batch'], $o['serial'] );
		$matrix = EANBC_QR::matrix( $uri, $o['ecc'] );
		if ( null === $matrix ) {
			return self::error( __( 'The web address is too long for a QR code.', 'ean-isbn-barcodes' ), $raw );
		}
		/* translators: %s: GTIN, e.g. 04006381333931 */
		$label = sprintf( __( 'QR code (GS1 Digital Link) for %s', 'ean-isbn-barcodes' ), EANBC_Digital_Link::gtin14( $parsed ) );
		$svg   = EANBC_QR::svg(
			$matrix,
			array(
				'module'     => $o['qr_module'],
				'color'      => $o['bar_color'],
				'background' => $o['bg_color'],
				'label'      => $label,
			)
		);
		if ( 'img' === $o['output'] ) {
			preg_match( '/width="([\d.]+)"/', $svg, $dim );
			$svg = sprintf(
				'<img class="eanbc-img" src="data:image/svg+xml;base64,%s" width="%2$s" height="%2$s" alt="%3$s" decoding="async">',
				base64_encode( $svg ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- data URI.
				esc_attr( $dim[1] ),
				esc_attr( $label )
			);
		}

		$caption = '';
		if ( $o['show_number'] ) {
			$caption .= '<span class="eanbc-hri">' . esc_html( EANBC_Digital_Link::hri( $parsed, $o['batch'], $o['serial'] ) ) . '</span>';
		}
		if ( '' !== $o['title'] ) {
			$caption .= '<span class="eanbc-name">' . esc_html( $o['title'] ) . '</span>';
		}
		if ( '' !== $o['caption'] ) {
			$caption .= '<span class="eanbc-caption-text">' . esc_html( $o['caption'] ) . '</span>';
		}

		return sprintf(
			'<figure class="%s">%s%s</figure>',
			esc_attr( self::classes( 'eanbc', $o ) . ' eanbc--qr' ),
			self::maybe_link( $svg, self::link_target( $parsed, $o ) ),
			$caption ? '<figcaption class="eanbc-caption">' . $caption . '</figcaption>' : ''
		);
	}

	/**
	 * Link target URL for a code with the global settings (used by the Digital Link resolver).
	 *
	 * @param array $parsed Parsed code.
	 * @return string
	 */
	public static function link_url( $parsed ) {
		$target = self::link_target( $parsed, self::options( array( 'link' => 'yes' ) ) );
		return $target ? $target['url'] : '';
	}

	/**
	 * [ean_product] – product card with barcode and details from the data sources.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function product( $atts ) {
		$o      = self::options( $atts );
		$raw    = self::resolve_ean( $o );
		$parsed = EANBC_Barcode::parse( $raw, $o['type'], $o['addon'] );
		if ( ! $parsed['valid'] ) {
			return self::error( $parsed['error'], $raw );
		}
		self::enqueue();
		$o['type'] = $parsed['type'];

		$o['style'] = 'plain';
		$product    = EANBC_Providers::lookup( $parsed );
		$target     = self::link_target( $parsed, $o, is_wp_error( $product ) ? null : $product );
		$graphic    = self::maybe_link( self::graphic( $parsed, $o ), $target );

		$details = '';
		if ( is_wp_error( $product ) ) {
			$fallback = '' !== $o['title'] ? $o['title'] : ( '' !== $o['fallback'] ? $o['fallback'] : $parsed['code'] );
			$details .= '<p class="eanbc-product__name">' . esc_html( $fallback ) . '</p>';
			if ( current_user_can( 'edit_posts' ) ) {
				$details .= '<p class="eanbc-error">' . esc_html( $product->get_error_message() ) . '</p>';
			}
		} else {
			$details .= '<p class="eanbc-product__name">' . esc_html( '' !== $o['title'] ? $o['title'] : $product['name'] ) . '</p><dl class="eanbc-product__meta">';
			$fields   = array(
				'authors'   => __( 'Author', 'ean-isbn-barcodes' ),
				'brand'     => __( 'Brand', 'ean-isbn-barcodes' ),
				'publisher' => __( 'Publisher', 'ean-isbn-barcodes' ),
				'year'      => __( 'Year', 'ean-isbn-barcodes' ),
				'category'  => __( 'Category', 'ean-isbn-barcodes' ),
			);
			foreach ( $fields as $key => $label ) {
				if ( '' !== $product[ $key ] ) {
					$details .= '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $product[ $key ] ) . '</dd>';
				}
			}
			if ( '' !== $product['country'] ) {
				$details .= '<dt>' . esc_html__( 'Issuing country', 'ean-isbn-barcodes' ) . '</dt><dd>' . self::flag( $product['country'] ) . esc_html( $product['country'] ) . '</dd>';
			}
			if ( 'isbn' === $parsed['type'] ) {
				$details .= '<dt>ISBN-13</dt><dd><code>' . esc_html( $parsed['isbn'] ) . '</code></dd>';
				if ( '' !== $parsed['isbn10'] ) {
					$details .= '<dt>ISBN-10</dt><dd><code>' . esc_html( $parsed['isbn10'] ) . '</code></dd>';
				}
			} elseif ( 'issn' === $parsed['type'] ) {
				$details .= '<dt>ISSN</dt><dd><code>' . esc_html( $parsed['issn'] ) . '</code></dd>';
				$details .= '<dt>EAN-13</dt><dd><code>' . esc_html( $parsed['code'] ) . '</code></dd>';
			} elseif ( 'ismn' === $parsed['type'] ) {
				$details .= '<dt>ISMN</dt><dd><code>' . esc_html( $parsed['ismn'] ) . '</code></dd>';
			} else {
				$details .= '<dt>' . esc_html( EANBC_Barcode::type_label( $parsed['type'] ) ) . '</dt><dd><code>' . esc_html( $parsed['code'] ) . '</code></dd>';
			}
			if ( '' !== $parsed['addon'] ) {
				$details .= '<dt>' . esc_html__( 'Add-on', 'ean-isbn-barcodes' ) . '</dt><dd><code>' . esc_html( $parsed['addon'] ) . '</code></dd>';
			}
			$details .= '</dl>';
		}
		if ( $target ) {
			/* translators: %s: website name, e.g. "Open Library" */
			$text     = '' !== $target['label'] ? sprintf( __( 'View on %s →', 'ean-isbn-barcodes' ), $target['label'] ) : __( 'More about this product →', 'ean-isbn-barcodes' );
			$details .= self::maybe_link( esc_html( $text ), $target );
		}
		if ( ! is_wp_error( $product ) ) {
			$details .= self::source_note( $product );
		}

		return sprintf(
			'<div class="%s"><div class="eanbc-product__code">%s</div><div class="eanbc-product__details">%s</div></div>',
			esc_attr( self::classes( 'eanbc-product', $o ) ),
			$graphic,
			$details
		);
	}

	/**
	 * [ean_name] – only the product name (or own title) as inline text.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function name( $atts ) {
		$o      = self::options( $atts );
		$raw    = self::resolve_ean( $o );
		$parsed = EANBC_Barcode::parse( $raw, $o['type'], $o['addon'] );
		if ( ! $parsed['valid'] ) {
			return self::error( $parsed['error'], $raw );
		}
		$product = null;
		if ( '' !== $o['title'] ) {
			$name = esc_html( $o['title'] ); // Own title: no request needed.
		} else {
			$product = EANBC_Providers::lookup( $parsed );
			if ( is_wp_error( $product ) ) {
				return '' !== $o['fallback'] ? esc_html( $o['fallback'] ) : '';
			}
			$name = esc_html( $product['name'] );
		}
		return self::maybe_link( $name, self::link_target( $parsed, $o, $product ) );
	}

	/**
	 * Product data for the block editor ("take over name" button).
	 *
	 * @param array $atts Attributes (ean, type, id).
	 * @return array|WP_Error Normalized product (see EANBC_Provider) plus "code".
	 */
	public static function lookup_data( $atts ) {
		$o      = self::options( $atts );
		$parsed = EANBC_Barcode::parse( self::resolve_ean( $o ), $o['type'], $o['addon'] );
		if ( ! $parsed['valid'] ) {
			return new WP_Error( 'eanbc_invalid', $parsed['error'] );
		}
		$product = EANBC_Providers::lookup( $parsed );
		if ( is_wp_error( $product ) ) {
			return $product;
		}
		$product['code'] = $parsed['code'];
		unset( $product['image'] );
		return $product;
	}

	/**
	 * "Data: <provider>" line below product cards (some open databases require attribution).
	 *
	 * @param array $product Normalized product.
	 * @return string
	 */
	private static function source_note( $product ) {
		$provider = EANBC_Providers::get( $product['provider'] );
		if ( ! $provider ) {
			return '';
		}
		$home  = $provider->homepage();
		$label = esc_html( $provider->label() );
		if ( $home ) {
			$label = sprintf( '<a href="%s" rel="noopener nofollow" target="_blank">%s</a>', esc_url( $home ), $label );
		}
		$license = $provider->license() ? ' (' . esc_html( $provider->license() ) . ')' : '';
		/* translators: %s: name of the data source, e.g. "Open Library" */
		return '<p class="eanbc-product__source">' . sprintf( esc_html__( 'Data: %s', 'ean-isbn-barcodes' ), $label . $license ) . '</p>';
	}

	/**
	 * Force the ISBN format unless the shortcode sets its own type.
	 *
	 * @param array|string $atts Attributes.
	 * @return array
	 */
	private static function as_isbn( $atts ) {
		$atts = (array) $atts;
		if ( empty( $atts['type'] ) ) {
			$atts['type'] = 'isbn';
		}
		return $atts;
	}

	/**
	 * [isbn] – book barcode (EAN-13 with "ISBN …" line).
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function isbn_barcode( $atts ) {
		return self::barcode( self::as_isbn( $atts ) );
	}

	/**
	 * [isbn_product] – product card for a book.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function isbn_product( $atts ) {
		return self::product( self::as_isbn( $atts ) );
	}

	/**
	 * [isbn_name] – book title as text.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function isbn_name( $atts ) {
		return self::name( self::as_isbn( $atts ) );
	}

	/**
	 * Build wrapper classes.
	 *
	 * @param string $base Base class.
	 * @param array  $o    Options.
	 * @return string
	 */
	private static function classes( $base, $o ) {
		$classes = array( $base, 'eanbc--align-' . $o['align'], 'eanbc--' . $o['style'] );
		if ( 'isbn' === $o['type'] ) {
			$classes[] = 'eanbc--isbn';
		}
		if ( $o['class'] ) {
			$classes[] = $o['class'];
		}
		return implode( ' ', $classes );
	}

	/**
	 * Emoji flag for a 2-letter country code.
	 *
	 * @param string $country Country code.
	 * @return string
	 */
	private static function flag( $country ) {
		$cc = strtoupper( (string) $country );
		$cc = 'UK' === $cc ? 'GB' : $cc;
		if ( ! preg_match( '/^[A-Z]{2}$/', $cc ) || ! function_exists( 'mb_chr' ) ) {
			return '';
		}
		return '<span class="eanbc-flag" aria-hidden="true">' . mb_chr( 0x1F1E6 + ord( $cc[0] ) - 65, 'UTF-8' ) . mb_chr( 0x1F1E6 + ord( $cc[1] ) - 65, 'UTF-8' ) . '</span> ';
	}
}
