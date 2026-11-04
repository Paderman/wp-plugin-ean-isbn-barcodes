<?php
/**
 * WooCommerce integration: GTIN from WooCommerce and common GTIN plugins,
 * barcode on the product page (follows the selected variation),
 * GTIN column in the product list and a product data lookup in the product editor.
 *
 * Only loaded when WooCommerce is active.
 *
 * @package EANBC
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce integration.
 */
class EANBC_WooCommerce {

	/**
	 * Hook in.
	 */
	public static function init() {
		$display = EANBC_Settings::get( 'wc_display' );
		if ( 'meta' === $display ) {
			add_action( 'woocommerce_product_meta_end', array( __CLASS__, 'product_page' ) );
		} elseif ( 'after_summary' === $display ) {
			add_action( 'woocommerce_after_single_product_summary', array( __CLASS__, 'product_page' ), 5 );
		}

		if ( is_admin() ) {
			if ( EANBC_Settings::get( 'wc_column' ) ) {
				add_filter( 'manage_edit-product_columns', array( __CLASS__, 'add_column' ), 20 );
				add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
			}
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		}
	}

	/**
	 * Meta fields of GTIN plugins, read when the WooCommerce field is empty.
	 *
	 * Germanized: _ts_gtin (it also hands its value to WooCommerce's own field),
	 * German Market: _gm_gtin, EAN for WooCommerce: _alg_ean (configurable),
	 * Product GTIN (EAN, UPC, ISBN) for WooCommerce: _wpm_gtin_code.
	 *
	 * @return string[]
	 */
	public static function meta_keys() {
		$keys = array( '_ts_gtin', '_gm_gtin', (string) get_option( 'alg_wc_ean_meta_key', '_alg_ean' ), '_wpm_gtin_code' );
		/**
		 * Filters the meta keys that may hold a product's GTIN (after WooCommerce's own field).
		 *
		 * @param string[] $keys Meta keys, checked in this order.
		 */
		return array_values( array_unique( array_filter( array_map( 'strval', (array) apply_filters( 'eanbc_gtin_meta_keys', $keys ) ) ) ) );
	}

	/**
	 * GTIN of a product or variation (no fallback to the parent).
	 *
	 * @param WC_Product|int $product Product or ID.
	 * @return string
	 */
	public static function gtin( $product ) {
		if ( ! $product instanceof WC_Product ) {
			$product = wc_get_product( $product );
		}
		if ( ! $product ) {
			return '';
		}
		$value = method_exists( $product, 'get_global_unique_id' ) ? trim( (string) $product->get_global_unique_id() ) : '';
		if ( '' !== $value ) {
			return $value;
		}
		foreach ( self::meta_keys() as $key ) {
			$meta = get_post_meta( $product->get_id(), $key, true );
			if ( is_scalar( $meta ) && '' !== trim( (string) $meta ) ) {
				return trim( (string) $meta );
			}
		}
		return '';
	}

	/**
	 * GTIN fields that are read, with the plugin they belong to and how many
	 * products/variations have a value there (for the settings page).
	 *
	 * @return array[] list of array( key, label, count )
	 */
	public static function sources() {
		global $wpdb;
		$alg    = (string) get_option( 'alg_wc_ean_meta_key', '_alg_ean' );
		$labels = array(
			'_global_unique_id' => __( 'WooCommerce (field “GTIN, UPC, EAN or ISBN”)', 'ean-isbn-barcodes' ),
			'_ts_gtin'          => 'Germanized',
			'_gm_gtin'          => 'German Market',
			$alg                => 'EAN for WooCommerce',
			'_wpm_gtin_code'    => 'Product GTIN (EAN, UPC, ISBN) for WooCommerce',
		);
		$keys   = array_values( array_unique( array_merge( array( '_global_unique_id' ), self::meta_keys() ) ) );
		$counts = array();
		$in     = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one aggregate query on the settings page; $in only holds %s placeholders.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT pm.meta_key, COUNT(*) AS n FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type IN ('product','product_variation') AND p.post_status <> 'trash' AND pm.meta_value <> '' AND pm.meta_key IN ($in) GROUP BY pm.meta_key", $keys ) );
		foreach ( (array) $rows as $row ) {
			$counts[ $row->meta_key ] = (int) $row->n;
		}
		$out = array();
		foreach ( $keys as $key ) {
			$out[] = array( $key, isset( $labels[ $key ] ) ? $labels[ $key ] : $key, isset( $counts[ $key ] ) ? $counts[ $key ] : 0 );
		}
		return $out;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Product page
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Barcode on the single product page. Variable products get one barcode per
	 * variation; a small script shows the one of the selected variation.
	 */
	public static function product_page() {
		global $product;
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$items = array();
		$main  = self::gtin( $product );
		if ( '' !== $main ) {
			$items[0] = $main;
		}
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				$gtin = self::gtin( $child_id );
				if ( '' !== $gtin && $gtin !== $main ) {
					$items[ $child_id ] = $gtin;
				}
			}
		}
		if ( ! $items ) {
			return;
		}

		$meta = 'meta' === EANBC_Settings::get( 'wc_display' );
		$code = EANBC_Settings::get( 'wc_code' );
		$html = '';
		foreach ( $items as $id => $gtin ) {
			$atts    = array(
				'ean'  => $gtin,
				'link' => 'no',
				'name' => 'no',
			);
			$barcode = ( 'qr' !== $code ? EANBC_Renderer::barcode( $atts ) : '' ) . ( 'barcode' !== $code ? EANBC_Renderer::qr( $atts ) : '' );
			if ( '' === trim( $barcode ) ) {
				continue;
			}
			$label = '';
			if ( $meta ) {
				$parsed = EANBC_Barcode::parse( $gtin );
				$label  = '<span class="eanbc-wc__label">' . esc_html( ( $parsed['valid'] ? EANBC_Barcode::type_label( $parsed['type'] ) : 'GTIN' ) . ':' ) . '</span> ';
			}
			$html .= sprintf(
				'<div class="eanbc-wc__item" data-eanbc-variation="%d"%s>%s%s</div>',
				(int) $id,
				0 === $id ? '' : ' hidden',
				$label,
				$barcode
			);
		}
		if ( '' === $html ) {
			return;
		}

		printf( '<div class="eanbc-wc eanbc-wc--%s" data-eanbc-wc>%s</div>', $meta ? 'meta' : 'summary', $html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.

		if ( count( $items ) > 1 || ! isset( $items[0] ) ) {
			wp_enqueue_script( 'eanbc-wc-variations', EANBC_URL . 'assets/js/wc-variations.js', array( 'jquery' ), eanbc_asset_ver( 'assets/js/wc-variations.js' ), true );
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Admin: product list column
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Add the GTIN column after the SKU.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function add_column( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'sku' === $key ) {
				$out['eanbc_gtin'] = __( 'GTIN', 'ean-isbn-barcodes' );
			}
		}
		if ( ! isset( $out['eanbc_gtin'] ) ) {
			$out['eanbc_gtin'] = __( 'GTIN', 'ean-isbn-barcodes' );
		}
		return $out;
	}

	/**
	 * Column content: small barcode + code, or a warning for invalid codes.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Product ID.
	 */
	public static function render_column( $column, $post_id ) {
		if ( 'eanbc_gtin' !== $column ) {
			return;
		}
		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return;
		}
		$gtin = self::gtin( $product );

		if ( '' === $gtin ) {
			if ( $product->is_type( 'variable' ) ) {
				$children = $product->get_children();
				$with     = count( array_filter( array_map( array( __CLASS__, 'gtin' ), $children ) ) );
				if ( $with ) {
					/* translators: 1: variations with GTIN, 2: all variations */
					echo '<span class="eanbc-col-variations">' . esc_html( sprintf( __( '%1$d of %2$d variations', 'ean-isbn-barcodes' ), $with, count( $children ) ) ) . '</span>';
					return;
				}
			}
			echo '<span class="na">&ndash;</span>';
			return;
		}

		$parsed = EANBC_Barcode::parse( $gtin );
		if ( ! $parsed['valid'] ) {
			printf(
				'<span class="eanbc-col-invalid" title="%s"><span class="dashicons dashicons-warning" aria-hidden="true"></span><code>%s</code><span class="eanbc-col-error">%s</span></span>',
				esc_attr( $parsed['error'] ),
				esc_html( $gtin ),
				esc_html( $parsed['error'] )
			);
			return;
		}
		$svg = EANBC_Barcode::svg(
			$parsed['code'],
			$parsed['type'],
			array(
				'module_width' => 1,
				'bar_height'   => 22,
				'bar_color'    => '#1d2327',
				'bg_color'     => '',
				'show_number'  => 0,
				'addon'        => $parsed['addon'],
			)
		);
		printf(
			'<span class="eanbc-col">%s<code>%s</code><span class="eanbc-col-type">%s</span></span>',
			$svg, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated SVG, all parts escaped.
			esc_html( $parsed['code'] . ( '' !== $parsed['addon'] ? ' ' . $parsed['addon'] : '' ) ),
			esc_html( EANBC_Barcode::type_label( $parsed['type'] ) )
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * Admin: product editor lookup + list styles
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Styles for the list column, script for the product editor.
	 *
	 * @param string $hook Admin page.
	 */
	public static function admin_assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}
		wp_enqueue_style( 'eanbc-wc-admin', EANBC_URL . 'assets/css/wc-admin.css', array(), eanbc_asset_ver( 'assets/css/wc-admin.css' ) );

		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || ! EANBC_Settings::get( 'wc_lookup' ) ) {
			return;
		}
		wp_enqueue_script( 'eanbc-wc-admin', EANBC_URL . 'assets/js/wc-admin.js', array(), eanbc_asset_ver( 'assets/js/wc-admin.js' ), true );
		wp_localize_script(
			'eanbc-wc-admin',
			'eanbcWc',
			array(
				'restUrl'    => esc_url_raw( rest_url( 'eanbc/v1/lookup' ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'fields'     => array_merge( array( '_global_unique_id' ), self::meta_keys() ),
				'hasSource'  => EANBC_Providers::has_any(),
				'sourcesUrl' => current_user_can( 'manage_options' ) ? EANBC_Settings::page_url( null, 'sources' ) : '',
				'i18n'       => array(
					'lookup'     => __( 'Look up product data', 'ean-isbn-barcodes' ),
					'looking'    => __( 'Looking up…', 'ean-isbn-barcodes' ),
					'empty'      => __( 'Please enter a GTIN first.', 'ean-isbn-barcodes' ),
					'useTitle'   => __( 'Use as product name', 'ean-isbn-barcodes' ),
					'titleSet'   => __( 'Product name set. Do not forget to save the product.', 'ean-isbn-barcodes' ),
					'noSource'   => __( 'No data source is active.', 'ean-isbn-barcodes' ),
					'setup'      => __( 'Set up data sources', 'ean-isbn-barcodes' ),
					'error'      => __( 'Something went wrong. Please try again.', 'ean-isbn-barcodes' ),
					'name'       => __( 'Name', 'ean-isbn-barcodes' ),
					'brand'      => __( 'Brand', 'ean-isbn-barcodes' ),
					'authors'    => __( 'Author', 'ean-isbn-barcodes' ),
					'publisher'  => __( 'Publisher', 'ean-isbn-barcodes' ),
					'year'       => __( 'Year', 'ean-isbn-barcodes' ),
					'category'   => __( 'Category', 'ean-isbn-barcodes' ),
					'country'    => __( 'Issuing country', 'ean-isbn-barcodes' ),
					'dataSource' => __( 'Source', 'ean-isbn-barcodes' ),
				),
			)
		);
	}
}
