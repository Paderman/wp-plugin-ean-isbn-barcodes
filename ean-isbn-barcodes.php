<?php
/**
 * Plugin Name:       EAN & ISBN Barcodes
 * Plugin URI:        https://github.com/Paderman/wp-plugin-ean-isbn-barcodes
 * Description:       EAN, UPC, ISBN, ISSN and ISMN barcodes as block or shortcode – with product names and product cards from several data sources (Open Library, Open Food Facts, ean-search.org).
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            pader-man
 * Author URI:        https://github.com/Paderman
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ean-isbn-barcodes
 * Domain Path:       /languages
 *
 * @package EANBC
 */

defined( 'ABSPATH' ) || exit;

define( 'EANBC_VERSION', '1.0.0' );
define( 'EANBC_FILE', __FILE__ );
define( 'EANBC_DIR', plugin_dir_path( __FILE__ ) );
define( 'EANBC_URL', plugin_dir_url( __FILE__ ) );
define( 'EANBC_SLUG', 'ean-isbn-barcodes' );
define( 'EANBC_TEXTDOMAIN', 'ean-isbn-barcodes' );
define( 'EANBC_REPO_URL', 'https://github.com/Paderman/wp-plugin-ean-isbn-barcodes' );

/**
 * Version string for a CSS/JS file: plugin version plus the file's modification time,
 * so browsers never keep an outdated copy after an update.
 *
 * @param string $rel Path relative to the plugin folder.
 * @return string
 */
function eanbc_asset_ver( $rel ) {
	$time = @filemtime( EANBC_DIR . $rel ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- missing file just means no suffix.
	return EANBC_VERSION . ( $time ? '.' . $time : '' );
}

require_once EANBC_DIR . 'includes/class-eanbc-settings.php';
require_once EANBC_DIR . 'includes/class-eanbc-i18n.php';
require_once EANBC_DIR . 'includes/class-eanbc-barcode.php';
require_once EANBC_DIR . 'includes/class-eanbc-qr.php';
require_once EANBC_DIR . 'includes/class-eanbc-digital-link.php';
require_once EANBC_DIR . 'includes/class-eanbc-http.php';
require_once EANBC_DIR . 'includes/providers/class-eanbc-provider.php';
require_once EANBC_DIR . 'includes/providers/class-eanbc-provider-ean-search.php';
require_once EANBC_DIR . 'includes/providers/class-eanbc-provider-open-library.php';
require_once EANBC_DIR . 'includes/providers/class-eanbc-provider-open-food-facts.php';
require_once EANBC_DIR . 'includes/class-eanbc-providers.php';
require_once EANBC_DIR . 'includes/class-eanbc-renderer.php';
require_once EANBC_DIR . 'includes/class-eanbc-shortcodes.php';
require_once EANBC_DIR . 'includes/class-eanbc-block.php';
require_once EANBC_DIR . 'includes/class-eanbc-admin.php';
require_once EANBC_DIR . 'includes/class-eanbc-woocommerce.php';

register_activation_hook( __FILE__, array( 'EANBC_Settings', 'activate' ) );
// Rewrite rules of the Digital Link resolver: rebuilt on activation, dropped on deactivation.
register_deactivation_hook(
	__FILE__,
	static function () {
		delete_option( 'rewrite_rules' );
	}
);

add_action(
	'plugins_loaded',
	static function () {
		EANBC_I18n::init();
		EANBC_Providers::init();
		EANBC_Shortcodes::init();
		EANBC_Block::init();
		EANBC_Digital_Link::init();
		if ( class_exists( 'WooCommerce' ) ) {
			EANBC_WooCommerce::init();
		}
		if ( is_admin() ) {
			EANBC_Admin::init();
		}
	}
);
