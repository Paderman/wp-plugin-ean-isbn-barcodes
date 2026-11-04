<?php
/**
 * Base class for product data providers.
 *
 * A provider turns a parsed code into a normalized product array:
 *   name (required), brand, category, country, authors, publisher, year, image, url
 * plus "provider" / "provider_label", which the base class adds.
 *
 * Third-party plugins can add providers via the "eanbc_providers" filter.
 *
 * @package EANBC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Provider base class.
 */
abstract class EANBC_Provider {

	/**
	 * Unique id (lowercase, a-z0-9_).
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Display name.
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * One sentence about what the provider covers.
	 *
	 * @return string
	 */
	abstract public function description();

	/**
	 * Fetch product data (uncached).
	 *
	 * @param array $parsed Result of EANBC_Barcode::parse().
	 * @return array|WP_Error Normalized product or error ("eanbc_not_found" when the code is unknown).
	 */
	abstract protected function fetch( $parsed );

	/**
	 * Short tags shown in the settings (e.g. "Free", "Books").
	 *
	 * @return string[]
	 */
	public function tags() {
		return array();
	}

	/**
	 * Provider website.
	 *
	 * @return string
	 */
	public function homepage() {
		return '';
	}

	/**
	 * Terms of use / license page.
	 *
	 * @return string
	 */
	public function terms_url() {
		return '';
	}

	/**
	 * Data license or attribution note shown next to the data (may be empty).
	 *
	 * @return string
	 */
	public function license() {
		return '';
	}

	/**
	 * Whether the provider needs an API token.
	 *
	 * @return bool
	 */
	public function needs_token() {
		return false;
	}

	/**
	 * Where to get a token.
	 *
	 * @return string
	 */
	public function token_url() {
		return '';
	}

	/**
	 * Whether this provider can answer for this code at all (e.g. books only).
	 *
	 * @param array $parsed Parsed code.
	 * @return bool
	 */
	public function covers( $parsed ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- default for subclasses.
		return true;
	}

	/**
	 * Public product page for a code (built from a pattern, no request).
	 *
	 * @param array $parsed Parsed code.
	 * @return string Empty if the provider has no product pages.
	 */
	public function product_url( $parsed ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- default for subclasses.
		return '';
	}

	/**
	 * Whether "automatic" links may point here although the product data came from elsewhere
	 * (false for specialised databases that would show "not found" for most codes).
	 *
	 * @return bool
	 */
	public function link_without_data() {
		return true;
	}

	/**
	 * Code used for the connection test.
	 *
	 * @return string
	 */
	public function sample_code() {
		return '4006381333931';
	}

	/**
	 * Stored settings of this provider (enabled, token).
	 *
	 * @return array
	 */
	public function settings() {
		return EANBC_Providers::provider_settings( $this->id() );
	}

	/**
	 * Saved API token.
	 *
	 * @return string
	 */
	public function token() {
		$s = $this->settings();
		return (string) $s['token'];
	}

	/**
	 * Enabled in the settings.
	 *
	 * @return bool
	 */
	public function enabled() {
		$s = $this->settings();
		return ! empty( $s['enabled'] );
	}

	/**
	 * Enabled and usable (token present if needed).
	 *
	 * @return bool
	 */
	public function is_ready() {
		return $this->enabled() && ( ! $this->needs_token() || '' !== $this->token() );
	}

	/**
	 * Cached lookup.
	 *
	 * @param array $parsed Parsed code.
	 * @param bool  $cache  Use the cache.
	 * @return array|WP_Error
	 */
	public function lookup( $parsed, $cache = true ) {
		$days  = (int) EANBC_Settings::get( 'cache_days' );
		$cache = $cache && $days > 0;
		$key   = 'eanbc_' . (int) get_option( EANBC_Settings::CACHE_VERSION, 1 ) . '_' . md5( $this->id() . '|' . $parsed['lookup'] . '|' . $this->token() );

		if ( $cache ) {
			$hit = get_transient( $key );
			if ( is_array( $hit ) ) {
				return isset( $hit['__error'] ) ? new WP_Error( $hit['__error'], $hit['message'] ) : $hit;
			}
		}

		$result = $this->fetch( $parsed );

		if ( is_wp_error( $result ) ) {
			// Unknown codes stay unknown for a while; transient problems are retried soon.
			$ttl  = array(
				'eanbc_not_found'    => DAY_IN_SECONDS,
				'eanbc_quota'        => 5 * MINUTE_IN_SECONDS,
				'eanbc_http'         => 10 * MINUTE_IN_SECONDS,
				'eanbc_status'       => 10 * MINUTE_IN_SECONDS,
				'eanbc_bad_response' => 10 * MINUTE_IN_SECONDS,
			);
			$code = $result->get_error_code();
			if ( $cache && isset( $ttl[ $code ] ) ) {
				set_transient(
					$key,
					array(
						'__error' => $code,
						'message' => $result->get_error_message(),
					),
					$ttl[ $code ]
				);
			}
			return $result;
		}

		$result = array_merge(
			array(
				'name'      => '',
				'brand'     => '',
				'category'  => '',
				'country'   => '',
				'authors'   => '',
				'publisher' => '',
				'year'      => '',
				'image'     => '',
				'url'       => '',
			),
			array_map( 'strval', array_filter( $result, 'is_scalar' ) ),
			array(
				'provider'       => $this->id(),
				'provider_label' => $this->label(),
			)
		);
		if ( '' === trim( $result['name'] ) ) {
			return $this->not_found();
		}
		if ( $cache ) {
			set_transient( $key, $result, $days * DAY_IN_SECONDS );
		}
		return $result;
	}

	/**
	 * Connection test (settings page). Default: fresh lookup of the sample code.
	 *
	 * @param string|null $token Unsaved token to test.
	 * @return string|WP_Error Success message.
	 */
	public function test( $token = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- only providers with tokens use it.
		$parsed = EANBC_Barcode::parse( $this->sample_code() );
		$result = $this->fetch( $parsed );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		/* translators: %s: product name returned by the test request */
		return sprintf( __( 'Connection successful. Test product: %s', 'ean-isbn-barcodes' ), $result['name'] );
	}

	/**
	 * Standard "not found" error.
	 *
	 * @return WP_Error
	 */
	protected function not_found() {
		/* translators: %s: provider name */
		return new WP_Error( 'eanbc_not_found', sprintf( __( 'Product not found at %s.', 'ean-isbn-barcodes' ), $this->label() ) );
	}
}
