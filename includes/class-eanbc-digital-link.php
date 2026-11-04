<?php
/**
 * GS1 Digital Link: web address for a GTIN (https://…/01/{GTIN-14}[/10/{batch}][/21/{serial}])
 * and an optional resolver on this site, so QR codes can point to the own domain.
 *
 * @see https://www.gs1.org/standards/gs1-digital-link
 * @package EANBC
 */

defined( 'ABSPATH' ) || exit;

/**
 * GS1 Digital Link.
 */
class EANBC_Digital_Link {

	const GS1_RESOLVER = 'https://id.gs1.org';
	const QUERY_VAR    = 'eanbc_gtin';
	const FLUSH_OPTION = 'eanbc_flush_rewrite';

	/**
	 * Hook in.
	 */
	public static function init() {
		// Runs always, so switching the resolver off removes the rule again.
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
		if ( 'site' !== EANBC_Settings::get( 'qr_domain' ) ) {
			return;
		}
		add_action( 'init', array( __CLASS__, 'add_rewrite_rule' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'resolve' ), 1 );
	}

	/**
	 * GTIN-14 of a parsed code (EAN-13, ISBN … get a leading 0, EAN-8 and UPC more).
	 *
	 * @param array $parsed Parsed code.
	 * @return string
	 */
	public static function gtin14( $parsed ) {
		return str_pad( $parsed['lookup'], 14, '0', STR_PAD_LEFT );
	}

	/**
	 * Keep only characters allowed in GS1 batch / serial numbers (max. 20).
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function clean_ai( $value ) {
		return substr( preg_replace( '/[^A-Za-z0-9!"%&\'()*+,\-.\/:;<=>?_]/', '', (string) $value ), 0, 20 );
	}

	/**
	 * Domain the QR codes point to (without trailing slash).
	 *
	 * @return string
	 */
	public static function base() {
		$domain = EANBC_Settings::get( 'qr_domain' );
		if ( 'site' === $domain && self::site_possible() ) {
			return untrailingslashit( home_url() );
		}
		if ( 'custom' === $domain && '' !== EANBC_Settings::get( 'qr_custom' ) ) {
			return untrailingslashit( EANBC_Settings::get( 'qr_custom' ) );
		}
		return self::GS1_RESOLVER;
	}

	/**
	 * The own site can only answer /01/… with "pretty" permalinks.
	 *
	 * @return bool
	 */
	public static function site_possible() {
		return '' !== (string) get_option( 'permalink_structure' );
	}

	/**
	 * Digital Link URI.
	 *
	 * @param array  $parsed Parsed code.
	 * @param string $batch  Batch/lot (AI 10).
	 * @param string $serial Serial number (AI 21).
	 * @return string
	 */
	public static function uri( $parsed, $batch = '', $serial = '' ) {
		$uri    = self::base() . '/01/' . self::gtin14( $parsed );
		$batch  = self::clean_ai( $batch );
		$serial = self::clean_ai( $serial );
		if ( '' !== $batch ) {
			$uri .= '/10/' . rawurlencode( $batch );
		}
		if ( '' !== $serial ) {
			$uri .= '/21/' . rawurlencode( $serial );
		}
		/**
		 * Filters the GS1 Digital Link URI encoded in QR codes.
		 *
		 * @param string $uri    URI.
		 * @param array  $parsed Parsed code.
		 */
		return (string) apply_filters( 'eanbc_digital_link', $uri, $parsed );
	}

	/**
	 * Human readable text below the QR code: "(01) 04006381333931 (10) ABC".
	 *
	 * @param array  $parsed Parsed code.
	 * @param string $batch  Batch.
	 * @param string $serial Serial.
	 * @return string
	 */
	public static function hri( $parsed, $batch = '', $serial = '' ) {
		$text   = '(01) ' . self::gtin14( $parsed );
		$batch  = self::clean_ai( $batch );
		$serial = self::clean_ai( $serial );
		if ( '' !== $batch ) {
			$text .= ' (10) ' . $batch;
		}
		if ( '' !== $serial ) {
			$text .= ' (21) ' . $serial;
		}
		return $text;
	}

	/*
	 * ---------------------------------------------------------------------
	 * Resolver on this site
	 * ---------------------------------------------------------------------
	 */

	/**
	 * /01/{14 digits}[/anything] → index.php?eanbc_gtin=…
	 */
	public static function add_rewrite_rule() {
		add_rewrite_rule( '^01/([0-9]{14})(?:/.*)?/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	/**
	 * Rebuild the rewrite rules once after the resolver setting changed.
	 */
	public static function maybe_flush() {
		if ( get_option( self::FLUSH_OPTION ) ) {
			delete_option( self::FLUSH_OPTION );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Register the query var.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Ask WordPress to rebuild its rewrite rules on the next request.
	 */
	public static function schedule_flush() {
		update_option( self::FLUSH_OPTION, 1, true );
	}

	/**
	 * Redirect /01/{GTIN} to the product in the shop, otherwise to the link target, otherwise to GS1.
	 */
	public static function resolve() {
		$gtin = (string) get_query_var( self::QUERY_VAR );
		if ( ! preg_match( '/^\d{14}$/', $gtin ) ) {
			return;
		}
		$target = self::target( $gtin );
		wp_redirect( $target, 302, 'EAN & ISBN Barcodes' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- targets come from settings, products or GS1.
		exit;
	}

	/**
	 * Where a GTIN-14 leads.
	 *
	 * @param string $gtin GTIN-14.
	 * @return string
	 */
	public static function target( $gtin ) {
		$target = '';
		$id     = self::find_product( $gtin );
		if ( $id ) {
			$target = get_permalink( $id );
			if ( function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $id );
				$target  = $product ? $product->get_permalink() : $target;
			}
		}
		if ( ! $target ) {
			$parsed = EANBC_Barcode::parse( $gtin );
			if ( $parsed['valid'] ) {
				$link   = EANBC_Renderer::link_url( $parsed );
				$target = $link ? $link : '';
			}
		}
		if ( ! $target ) {
			$target = self::GS1_RESOLVER . '/01/' . $gtin;
		}
		/**
		 * Filters where the built-in resolver sends a GTIN.
		 *
		 * @param string $target URL.
		 * @param string $gtin   GTIN-14.
		 */
		return (string) apply_filters( 'eanbc_resolver_target', $target, $gtin );
	}

	/**
	 * Published product (or variation) with this GTIN, in any common GTIN field.
	 *
	 * @param string $gtin GTIN-14.
	 * @return int
	 */
	public static function find_product( $gtin ) {
		if ( ! post_type_exists( 'product' ) ) {
			return 0;
		}
		// GTIN-14 → the forms shops usually store: 13, 12 and 8 digits.
		$values = array( $gtin );
		foreach ( array( 1, 2, 6 ) as $zeros ) {
			if ( str_repeat( '0', $zeros ) === substr( $gtin, 0, $zeros ) ) {
				$values[] = substr( $gtin, $zeros );
			}
		}
		$keys = array_merge( array( '_global_unique_id' ), class_exists( 'EANBC_WooCommerce' ) ? EANBC_WooCommerce::meta_keys() : array() );
		$meta = array( 'relation' => 'OR' );
		foreach ( array_unique( $keys ) as $key ) {
			$meta[] = array(
				'key'     => $key,
				'value'   => $values,
				'compare' => 'IN',
			);
		}
		$query = new WP_Query(
			array(
				'post_type'              => array( 'product', 'product_variation' ),
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- only on /01/… requests.
			)
		);
		return $query->posts ? (int) $query->posts[0] : 0;
	}
}
