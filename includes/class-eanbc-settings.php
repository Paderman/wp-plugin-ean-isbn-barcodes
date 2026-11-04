<?php
/**
 * Settings storage, defaults and sanitizing.
 *
 * @package EANBC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Central access point for all plugin options (stored in one array option).
 */
class EANBC_Settings {

	const OPTION        = 'eanbc_settings';
	const CACHE_VERSION = 'eanbc_cache_version';
	const NOTICE        = 'eanbc_show_welcome';

	/**
	 * Selectable seconds between the live preview examples (0 = no rotation).
	 */
	const PREVIEW_INTERVALS = array( 0, 3, 4, 6, 8, 10 );

	/**
	 * Selectable fade durations of the live preview in ms (0 = no fade).
	 */
	const PREVIEW_FADES = array( 0, 200, 350, 600, 1000, 1500 );

	/**
	 * Request-level cache of the merged settings.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Temporary override (live preview in the admin).
	 *
	 * @var array|null
	 */
	private static $override = null;

	/**
	 * Default values. Keep this free of translated strings (it may run before init).
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// General.
			'menu_location'    => 'toplevel', // toplevel | settings | tools.
			'preview_interval' => 4,         // Seconds between preview examples, 0 = no rotation.
			'preview_fade'     => 600,        // Cross-fade between preview examples in ms, 0 = none.
			'invalid_display'  => 'admins',   // admins | text | nothing.
			'meta_key'         => '',         // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- setting name (custom field used when no EAN is given), not a query.
			'link'             => 1,
			'link_new_tab'     => 1,
			'link_nofollow'    => 1,
			'link_target'      => 'auto',     // auto | custom | provider id.
			'link_template'    => '',         // Own URL with {code}.
			// Appearance.
			'source'           => 'local',    // local | api.
			'output'           => 'inline',   // inline | img.
			'style'            => 'plain',    // plain | card.
			'module_width'     => 2,
			'bar_height'       => 70,
			'bar_color'        => '#111111',
			'bg_color'         => '#ffffff',
			'transparent_bg'   => 0,
			'show_number'      => 1,
			'show_isbn'        => 1,          // "ISBN …" line above book barcodes.
			'show_name'        => 0,
			'align'            => 'none',     // none | left | center | right.
			// Data sources & cache.
			'providers'        => EANBC_Providers::default_settings(),
			'provider_order'   => array_keys( EANBC_Providers::default_settings() ),
			'cache_days'       => 30,
			'timeout'          => 8,
			// QR code (GS1 Digital Link).
			'qr_domain'        => 'gs1',      // gs1 | site | custom.
			'qr_custom'        => '',         // Own resolver domain for "custom".
			'qr_module'        => 4,          // px per module.
			'qr_ecc'           => 'M',        // L | M | Q | H.
			// WooCommerce.
			'wc_display'       => 'none',     // none | meta | after_summary.
			'wc_code'          => 'barcode',  // barcode | qr | both.
			'wc_column'        => 1,          // GTIN column in the product list.
			'wc_lookup'        => 1,          // "Look up product data" in the product editor.
		);
	}

	/**
	 * Get all settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null !== self::$override ) {
			return self::$override;
		}
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, array() );
			$stored = is_array( $stored ) ? $stored : array();
			// Settings of the predecessor plugin: a single ean-search.org token.
			if ( ! empty( $stored['api_token'] ) && empty( $stored['providers']['ean_search']['token'] ) ) {
				$stored['providers']['ean_search'] = array(
					'enabled' => 1,
					'token'   => $stored['api_token'],
				);
			}
			unset( $stored['api_token'] );
			self::$cache = wp_parse_args( $stored, self::defaults() );
		}
		return self::$cache;
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Run a callback with temporary (unsaved) settings.
	 *
	 * @param array    $settings Sanitized settings.
	 * @param callable $callback Callback.
	 * @return mixed
	 */
	public static function with( $settings, $callback ) {
		self::$override = $settings;
		try {
			return call_user_func( $callback );
		} finally {
			self::$override = null;
		}
	}

	/**
	 * Reset the request cache (after updates).
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Activation: store defaults and show the welcome notice once.
	 */
	public static function activate() {
		if ( false === get_option( self::OPTION ) ) {
			add_option( self::OPTION, self::defaults() );
		}
		update_option( self::NOTICE, 1, false );
		EANBC_Digital_Link::schedule_flush();
	}

	/**
	 * Admin URL of the settings page for a given menu location.
	 *
	 * @param string|null $location Menu location, defaults to current setting.
	 * @param string      $tab      Optional tab anchor.
	 * @return string
	 */
	public static function page_url( $location = null, $tab = '' ) {
		$location = $location ? $location : self::get( 'menu_location' );
		$bases    = array(
			'toplevel' => 'admin.php',
			'settings' => 'options-general.php',
			'tools'    => 'tools.php',
		);
		$base     = isset( $bases[ $location ] ) ? $bases[ $location ] : 'admin.php';
		$url      = admin_url( $base . '?page=' . EANBC_SLUG );
		return $tab ? $url . '#' . $tab : $url;
	}

	/**
	 * Sanitize the full settings array coming from the settings form or the preview.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		// Note: options.php already unslashes; AJAX callers unslash before calling.
		$input = is_array( $input ) ? $input : array();
		$d     = self::defaults();
		$out   = array();

		$choice = static function ( $key, $allowed ) use ( $input, $d ) {
			return ( isset( $input[ $key ] ) && in_array( $input[ $key ], $allowed, true ) ) ? $input[ $key ] : $d[ $key ];
		};
		$number = static function ( $key, $min, $max, $decimal = false ) use ( $input, $d ) {
			if ( ! isset( $input[ $key ] ) || '' === $input[ $key ] ) {
				return $d[ $key ];
			}
			$v = $decimal ? round( (float) $input[ $key ], 1 ) : (int) $input[ $key ];
			return max( $min, min( $max, $v ) );
		};
		$flag   = static function ( $key ) use ( $input ) {
			return empty( $input[ $key ] ) ? 0 : 1;
		};
		$color  = static function ( $key ) use ( $input, $d ) {
			$c = isset( $input[ $key ] ) ? sanitize_hex_color( $input[ $key ] ) : '';
			return $c ? $c : $d[ $key ];
		};

		$out['menu_location']    = $choice( 'menu_location', array( 'toplevel', 'settings', 'tools' ) );
		$interval                = isset( $input['preview_interval'] ) ? (int) $input['preview_interval'] : $d['preview_interval'];
		$out['preview_interval'] = in_array( $interval, self::PREVIEW_INTERVALS, true ) ? $interval : $d['preview_interval'];
		$fade                    = isset( $input['preview_fade'] ) ? (int) $input['preview_fade'] : $d['preview_fade'];
		$out['preview_fade']     = in_array( $fade, self::PREVIEW_FADES, true ) ? $fade : $d['preview_fade'];
		$out['invalid_display']  = $choice( 'invalid_display', array( 'admins', 'text', 'nothing' ) );
		$out['meta_key']         = isset( $input['meta_key'] ) ? sanitize_key( $input['meta_key'] ) : ''; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- setting name, not a query.
		$out['link']             = $flag( 'link' );
		$out['link_new_tab']     = $flag( 'link_new_tab' );
		$out['link_nofollow']    = $flag( 'link_nofollow' );
		$out['link_target']      = ( isset( $input['link_target'] ) && in_array( $input['link_target'], array_merge( array( 'auto', 'custom' ), array_keys( EANBC_Providers::all() ) ), true ) ) ? $input['link_target'] : 'auto';
		$out['link_template']    = isset( $input['link_template'] ) ? self::sanitize_url_template( $input['link_template'] ) : '';

		$out['source']         = $choice( 'source', array( 'local', 'api' ) );
		$out['output']         = $choice( 'output', array( 'inline', 'img' ) );
		$out['style']          = $choice( 'style', array( 'plain', 'card' ) );
		$out['module_width']   = $number( 'module_width', 1, 6, true );
		$out['bar_height']     = $number( 'bar_height', 20, 300 );
		$out['bar_color']      = $color( 'bar_color' );
		$out['bg_color']       = $color( 'bg_color' );
		$out['transparent_bg'] = $flag( 'transparent_bg' );
		$out['show_number']    = $flag( 'show_number' );
		$out['show_isbn']      = $flag( 'show_isbn' );
		$out['show_name']      = $flag( 'show_name' );
		$out['align']          = $choice( 'align', array( 'none', 'left', 'center', 'right' ) );

		// Data sources: enabled flag + token per provider, and their order.
		$known            = array_keys( EANBC_Providers::all() );
		$raw              = isset( $input['providers'] ) && is_array( $input['providers'] ) ? $input['providers'] : array();
		$out['providers'] = array();
		foreach ( $known as $id ) {
			$p                       = isset( $raw[ $id ] ) && is_array( $raw[ $id ] ) ? $raw[ $id ] : array();
			$out['providers'][ $id ] = array(
				'enabled' => empty( $p['enabled'] ) ? 0 : 1,
				'token'   => isset( $p['token'] ) ? self::sanitize_token( $p['token'] ) : '',
			);
		}
		$order                 = isset( $input['provider_order'] ) && is_array( $input['provider_order'] ) ? array_map( 'sanitize_key', $input['provider_order'] ) : array();
		$order                 = array_values( array_unique( array_intersect( $order, $known ) ) );
		$out['provider_order'] = array_merge( $order, array_values( array_diff( $known, $order ) ) );

		$out['cache_days'] = $number( 'cache_days', 0, 365 );
		$out['timeout']    = $number( 'timeout', 2, 30 );

		$out['qr_domain'] = $choice( 'qr_domain', array( 'gs1', 'site', 'custom' ) );
		$custom           = isset( $input['qr_custom'] ) ? trim( (string) $input['qr_custom'] ) : '';
		$out['qr_custom'] = preg_match( '#^https?://#i', $custom ) ? untrailingslashit( esc_url_raw( $custom, array( 'http', 'https' ) ) ) : '';
		$out['qr_module'] = $number( 'qr_module', 1, 20 );
		$out['qr_ecc']    = $choice( 'qr_ecc', array( 'L', 'M', 'Q', 'H' ) );

		$out['wc_display'] = $choice( 'wc_display', array( 'none', 'meta', 'after_summary' ) );
		$out['wc_code']    = $choice( 'wc_code', array( 'barcode', 'qr', 'both' ) );
		$out['wc_column']  = $flag( 'wc_column' );
		$out['wc_lookup']  = $flag( 'wc_lookup' );

		return $out;
	}

	/**
	 * Sanitize a URL template while keeping the {code} placeholder (esc_url_raw() would drop braces).
	 *
	 * @param mixed $template Raw template.
	 * @return string
	 */
	public static function sanitize_url_template( $template ) {
		$template = trim( (string) $template );
		if ( ! preg_match( '#^https?://#i', $template ) ) {
			return '';
		}
		$url = esc_url_raw( str_replace( '{code}', 'EANBCCODE', $template ), array( 'http', 'https' ) );
		return str_replace( 'EANBCCODE', '{code}', $url );
	}

	/**
	 * Keep only characters that appear in API tokens.
	 *
	 * @param mixed $token Raw token.
	 * @return string
	 */
	public static function sanitize_token( $token ) {
		return preg_replace( '/[^A-Za-z0-9_\-.]/', '', (string) $token );
	}

	/**
	 * Invalidate every cached API response by bumping the cache generation.
	 */
	public static function bump_cache_version() {
		update_option( self::CACHE_VERSION, (int) get_option( self::CACHE_VERSION, 1 ) + 1, false );
	}
}
