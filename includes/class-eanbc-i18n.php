<?php
/**
 * Translation loading with regional fallback.
 *
 * Primary language is English (source strings). Further languages are added by
 * dropping a "ean-isbn-barcodes-{locale}.mo" file into /languages.
 * The language follows WordPress: the user's profile language in the admin and
 * the site language (or the one set by a multilingual plugin) on the frontend.
 *
 * @package EANBC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Loads the bundled translations.
 */
class EANBC_I18n {

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'load' ), 1 );
	}

	/**
	 * Locales shipped in /languages (detected from the .mo files).
	 *
	 * @return string[]
	 */
	private static function available_locales() {
		static $locales = null;
		if ( null === $locales ) {
			$locales = array();
			foreach ( (array) glob( EANBC_DIR . 'languages/' . EANBC_TEXTDOMAIN . '-*.mo' ) as $file ) {
				if ( preg_match( '/-([a-z]{2,3}(?:_[A-Z]{2})?(?:_[a-z]+)?)\.mo$/', $file, $m ) ) {
					$locales[] = $m[1];
				}
			}
			sort( $locales );
		}
		return $locales;
	}

	/**
	 * Load the textdomain for the current locale and fall back to a related
	 * locale (e.g. de_AT -> de_DE) when there is no exact match.
	 */
	public static function load() {
		$locale = determine_locale();
		if ( 'en_US' === $locale ) {
			return; // Source language.
		}

		$file = self::resolve_file( $locale );
		if ( $file ) {
			load_textdomain( EANBC_TEXTDOMAIN, $file, $locale );
		}
	}

	/**
	 * Find the best matching .mo file for a locale.
	 *
	 * @param string $locale Locale.
	 * @return string|null
	 */
	private static function resolve_file( $locale ) {
		$dir = EANBC_DIR . 'languages/' . EANBC_TEXTDOMAIN . '-';

		// 1. Translations installed globally (e.g. from translate.wordpress.org) win.
		$global = WP_LANG_DIR . '/plugins/' . EANBC_TEXTDOMAIN . '-' . $locale . '.mo';
		if ( is_readable( $global ) ) {
			return $global;
		}
		// 2. Exact bundled match.
		if ( is_readable( $dir . $locale . '.mo' ) ) {
			return $dir . $locale . '.mo';
		}
		// 3. Same region without variant (de_CH_informal -> de_CH, de_DE_formal -> de_DE).
		$region = implode( '_', array_slice( explode( '_', $locale ), 0, 2 ) );
		if ( $region !== $locale && is_readable( $dir . $region . '.mo' ) ) {
			return $dir . $region . '.mo';
		}
		// 4. Same language, other region (de_AT, de_DE_formal -> de_DE; fr_CA -> fr_FR).
		// The main variant (de_DE, fr_FR …) comes first, so Austria never gets the Swiss spelling.
		$lang       = strtok( $locale, '_' );
		$candidates = array_filter(
			self::available_locales(),
			static function ( $candidate ) use ( $lang ) {
				return strtok( $candidate, '_' ) === $lang;
			}
		);
		$main       = $lang . '_' . strtoupper( $lang );
		if ( in_array( $main, $candidates, true ) ) {
			return $dir . $main . '.mo';
		}
		return $candidates ? $dir . reset( $candidates ) . '.mo' : null;
	}
}
