<?php
/**
 * Provider: ean-search.org (commercial, API token required).
 *
 * @see https://www.ean-search.org/ean-database-api.html
 * @package EANBC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Data source ean-search.org (API client).
 */
class EANBC_Provider_Ean_Search extends EANBC_Provider {

	const ENDPOINT = 'https://api.ean-search.org/api';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'ean_search';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return 'ean-search.org';
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Large commercial database for all kinds of products and books. Also offers PNG barcode images.', 'ean-isbn-barcodes' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function tags() {
		return array( __( 'All products', 'ean-isbn-barcodes' ), __( 'Paid, API token', 'ean-isbn-barcodes' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function homepage() {
		return 'https://www.ean-search.org/';
	}

	/**
	 * {@inheritDoc}
	 */
	public function terms_url() {
		return 'https://www.ean-search.org/ean-database-api.html';
	}

	/**
	 * {@inheritDoc}
	 */
	public function needs_token() {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function token_url() {
		return 'https://www.ean-search.org/ean-database-api.html';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $parsed Parsed code.
	 */
	public function product_url( $parsed ) {
		return 'https://www.ean-search.org/ean/' . rawurlencode( $parsed['lookup'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $parsed Parsed code.
	 */
	protected function fetch( $parsed ) {
		$row = $this->request( 'barcode-lookup', array( 'ean' => $parsed['lookup'] ) );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		return array(
			'name'     => isset( $row['name'] ) ? $row['name'] : '',
			'category' => isset( $row['categoryName'] ) ? $row['categoryName'] : '',
			'country'  => isset( $row['issuingCountry'] ) ? $row['issuingCountry'] : '',
			'url'      => 'https://www.ean-search.org/ean/' . rawurlencode( $parsed['lookup'] ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string|null $token Unsaved token to test.
	 */
	public function test( $token = null ) {
		$row = $this->request( 'account-status', array(), $token );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$msg = __( 'Connection successful – the token is valid.', 'ean-isbn-barcodes' );
		if ( isset( $row['requests'], $row['requestlimit'] ) && (int) $row['requestlimit'] ) {
			/* translators: 1: used requests, 2: request limit */
			$msg .= ' ' . sprintf( __( 'Requests used: %1$s of %2$s.', 'ean-isbn-barcodes' ), number_format_i18n( (int) $row['requests'] ), number_format_i18n( (int) $row['requestlimit'] ) );
		}
		return $msg;
	}

	/**
	 * Barcode PNG rendered by ean-search.org (cached like product data).
	 *
	 * @param string $ean    EAN.
	 * @param int    $width  Width in px.
	 * @param int    $height Height in px.
	 * @return string|WP_Error Base64 encoded PNG.
	 */
	public function image( $ean, $width, $height ) {
		$days = (int) EANBC_Settings::get( 'cache_days' );
		$key  = 'eanbc_' . (int) get_option( EANBC_Settings::CACHE_VERSION, 1 ) . '_img_' . md5( $ean . '|' . $width . '|' . $height . '|' . $this->token() );
		if ( $days > 0 ) {
			$hit = get_transient( $key );
			if ( is_string( $hit ) && '' !== $hit ) {
				return $hit;
			}
		}
		$row = $this->request(
			'barcode-image',
			array(
				'ean'    => $ean,
				'width'  => (int) $width,
				'height' => (int) $height,
			)
		);
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( empty( $row['barcode'] ) ) {
			return new WP_Error( 'eanbc_no_image', __( 'ean-search.org did not return an image.', 'ean-isbn-barcodes' ) );
		}
		$png = preg_replace( '/[^A-Za-z0-9+\/=]/', '', $row['barcode'] );
		if ( $days > 0 ) {
			set_transient( $key, $png, $days * DAY_IN_SECONDS );
		}
		return $png;
	}

	/**
	 * Perform an API request and return the first result row.
	 *
	 * @param string      $op     API operation.
	 * @param array       $params Query parameters.
	 * @param string|null $token  Token override (connection test).
	 * @return array|WP_Error
	 */
	private function request( $op, $params, $token = null ) {
		$token = null === $token ? $this->token() : (string) $token;
		if ( '' === $token ) {
			return new WP_Error( 'eanbc_no_token', __( 'No ean-search.org API token configured.', 'ean-isbn-barcodes' ) );
		}

		$url  = add_query_arg(
			array_map(
				'rawurlencode',
				array_merge(
					array(
						'token'  => $token,
						'op'     => $op,
						'format' => 'json',
					),
					$params
				)
			),
			self::ENDPOINT
		);
		$data = EANBC_Http::get_json( $url );

		if ( is_wp_error( $data ) ) {
			$status = (int) ( $data->get_error_data()['status'] ?? 0 );
			if ( 401 === $status || 403 === $status ) {
				return new WP_Error( 'eanbc_token', __( 'ean-search.org rejected the API token. Please check it.', 'ean-isbn-barcodes' ) );
			}
			return $data;
		}

		$row = ( isset( $data[0] ) && is_array( $data[0] ) ) ? $data[0] : $data;
		if ( ! empty( $row['error'] ) ) {
			$message = (string) $row['error'];
			if ( false !== stripos( $message, 'not found' ) ) {
				return $this->not_found();
			}
			$code = preg_match( '/limit|quota|credits/i', $message ) ? 'eanbc_quota' : 'eanbc_api';
			return new WP_Error( $code, 'ean-search.org: ' . $message );
		}
		return $row;
	}
}
