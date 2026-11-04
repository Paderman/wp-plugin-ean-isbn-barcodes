<?php
/**
 * Shortcode registration.
 *
 * [barcode ean="4006381333931"]        – barcode (aliases: [ean], [ean_barcode])
 * [ean_product ean="4006381333931"]    – product card (data from the active data sources)
 * [ean_name ean="4006381333931"]       – product name as text
 * [isbn 978-3-16-148410-0]             – book barcode with ISBN line (alias: [isbn_barcode])
 * [isbn_product] / [isbn_name]         – like [ean_product] / [ean_name], forced to ISBN
 * [ean_qr ean="4006381333931"]         – QR code with the GS1 Digital Link (alias: [gs1_qr])
 *
 * @package EANBC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shortcodes.
 */
class EANBC_Shortcodes {

	/**
	 * Register shortcodes.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Register all tags. [barcode] is skipped if another plugin owns it already;
	 * [ean_barcode] always works.
	 */
	public static function register() {
		foreach ( array( 'barcode', 'ean', 'ean_barcode' ) as $tag ) {
			if ( 'ean_barcode' === $tag || ! shortcode_exists( $tag ) ) {
				add_shortcode( $tag, array( 'EANBC_Renderer', 'barcode' ) );
			}
		}
		add_shortcode( 'ean_product', array( 'EANBC_Renderer', 'product' ) );
		add_shortcode( 'ean_name', array( 'EANBC_Renderer', 'name' ) );
		add_shortcode( 'ean_qr', array( 'EANBC_Renderer', 'qr' ) );
		add_shortcode( 'gs1_qr', array( 'EANBC_Renderer', 'qr' ) );

		// Books. [isbn] is skipped if another plugin owns it; [isbn_barcode] always works.
		if ( ! shortcode_exists( 'isbn' ) ) {
			add_shortcode( 'isbn', array( 'EANBC_Renderer', 'isbn_barcode' ) );
		}
		add_shortcode( 'isbn_barcode', array( 'EANBC_Renderer', 'isbn_barcode' ) );
		add_shortcode( 'isbn_product', array( 'EANBC_Renderer', 'isbn_product' ) );
		add_shortcode( 'isbn_name', array( 'EANBC_Renderer', 'isbn_name' ) );
	}
}
