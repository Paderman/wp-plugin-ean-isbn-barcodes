<?php
/**
 * Registry of product data providers and the lookup chain.
 *
 * @package EANBC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Provider registry.
 */
class EANBC_Providers {

	/**
	 * Instances keyed by id.
	 *
	 * @var EANBC_Provider[]|null
	 */
	private static $providers = null;

	/**
	 * Built-in providers and their default settings, in default order.
	 * Books first (Open Library), then the paid all-rounder, then food.
	 * Static on purpose: used by EANBC_Settings::defaults() before init.
	 *
	 * @return array id => array( enabled, token )
	 */
	public static function default_settings() {
		return array(
			'open_library'    => array(
				'enabled' => 1,
				'token'   => '',
			),
			'ean_search'      => array(
				'enabled' => 1,
				'token'   => '',
			),
			'open_food_facts' => array(
				'enabled' => 1,
				'token'   => '',
			),
		);
	}

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'privacy_policy' ) );
	}

	/**
	 * All registered providers in the configured order.
	 *
	 * @return EANBC_Provider[] id => provider
	 */
	public static function all() {
		if ( null === self::$providers ) {
			$list = array(
				new EANBC_Provider_Open_Library(),
				new EANBC_Provider_Ean_Search(),
				new EANBC_Provider_Open_Food_Facts(),
			);
			/**
			 * Register additional product data providers.
			 *
			 * @param EANBC_Provider[] $list Provider instances.
			 */
			$list = apply_filters( 'eanbc_providers', $list );

			$by_id = array();
			foreach ( (array) $list as $provider ) {
				if ( $provider instanceof EANBC_Provider && preg_match( '/^[a-z0-9_]+$/', $provider->id() ) ) {
					$by_id[ $provider->id() ] = $provider;
				}
			}
			self::$providers = $by_id;
		}

		$sorted = array();
		foreach ( self::order( array_keys( self::$providers ) ) as $id ) {
			$sorted[ $id ] = self::$providers[ $id ];
		}
		return $sorted;
	}

	/**
	 * Saved order, completed with providers that are not in it yet.
	 *
	 * @param string[] $known Known ids.
	 * @return string[]
	 */
	public static function order( $known ) {
		$saved = (array) EANBC_Settings::get( 'provider_order' );
		$order = array_values( array_intersect( $saved, $known ) );
		return array_merge( $order, array_values( array_diff( $known, $order ) ) );
	}

	/**
	 * One provider.
	 *
	 * @param string $id Id.
	 * @return EANBC_Provider|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Stored settings of one provider merged with defaults.
	 *
	 * @param string $id Id.
	 * @return array enabled, token
	 */
	public static function provider_settings( $id ) {
		$saved    = (array) EANBC_Settings::get( 'providers' );
		$defaults = self::default_settings();
		$base     = isset( $defaults[ $id ] ) ? $defaults[ $id ] : array(
			'enabled' => 0,
			'token'   => '',
		);
		return wp_parse_args( isset( $saved[ $id ] ) && is_array( $saved[ $id ] ) ? $saved[ $id ] : array(), $base );
	}

	/**
	 * Providers that are enabled and configured, in order.
	 *
	 * @return EANBC_Provider[]
	 */
	public static function ready() {
		return array_filter(
			self::all(),
			static function ( $provider ) {
				return $provider->is_ready();
			}
		);
	}

	/**
	 * Whether at least one provider can be asked.
	 *
	 * @return bool
	 */
	public static function has_any() {
		return (bool) self::ready();
	}

	/**
	 * Ask the ready providers one after another until one knows the code.
	 * A provider that is down or over its limit is simply skipped.
	 *
	 * @param array $parsed Parsed code (EANBC_Barcode::parse()).
	 * @return array|WP_Error Normalized product.
	 */
	public static function lookup( $parsed ) {
		$errors = array();
		$asked  = false;
		foreach ( self::ready() as $provider ) {
			if ( ! $provider->covers( $parsed ) ) {
				continue;
			}
			$asked  = true;
			$result = $provider->lookup( $parsed );
			if ( ! is_wp_error( $result ) ) {
				return $result;
			}
			$errors[] = $result;
		}

		if ( ! $asked ) {
			return new WP_Error( 'eanbc_no_provider', __( 'No active data source covers this code. Data sources can be set up in the plugin settings.', 'ean-isbn-barcodes' ) );
		}
		// Report a real problem (token, connection, limit) rather than "not found".
		foreach ( $errors as $error ) {
			if ( 'eanbc_not_found' !== $error->get_error_code() ) {
				return $error;
			}
		}
		return new WP_Error( 'eanbc_not_found', __( 'Product not found in the active data sources.', 'ean-isbn-barcodes' ) );
	}

	/**
	 * Suggested text for the site's privacy policy (Settings → Privacy).
	 */
	public static function privacy_policy() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}
		$items = '';
		foreach ( self::ready() as $provider ) {
			$items .= sprintf(
				'<li>%s – <a href="%s">%s</a></li>',
				esc_html( $provider->label() ),
				esc_url( $provider->terms_url() ? $provider->terms_url() : $provider->homepage() ),
				esc_html( $provider->terms_url() ? $provider->terms_url() : $provider->homepage() )
			);
		}
		if ( '' === $items ) {
			return;
		}
		$content  = '<p>' . esc_html__( 'Barcodes are generated on this server. To show product names and product cards, this website can ask the following data services for information about the shown product codes (EAN/ISBN). The requests are made by the web server and contain only the product code and, if required, an access key of this website – no data about visitors. Results are stored temporarily on this server.', 'ean-isbn-barcodes' ) . '</p>';
		$content .= '<ul>' . $items . '</ul>';
		wp_add_privacy_policy_content( __( 'EAN & ISBN Barcodes', 'ean-isbn-barcodes' ), wp_kses_post( $content ) );
	}
}
