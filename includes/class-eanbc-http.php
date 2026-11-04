<?php
/**
 * Small HTTP helper shared by all data providers.
 *
 * @package EANBC
 */

defined( 'ABSPATH' ) || exit;

/**
 * JSON requests with consistent errors.
 */
class EANBC_Http {

	/**
	 * User agent sent to all providers (identifies the plugin and the site, never a person).
	 *
	 * @return string
	 */
	public static function user_agent() {
		return 'EAN-ISBN-Barcodes/' . EANBC_VERSION . ' (WordPress plugin; ' . home_url( '/' ) . ')';
	}

	/**
	 * GET a URL and decode the JSON answer.
	 *
	 * @param string $url     URL (already including query args).
	 * @param array  $headers Extra headers.
	 * @return array|WP_Error Decoded JSON or WP_Error. HTTP status codes other than 200
	 *                        become "eanbc_status" errors with the code in the error data.
	 */
	public static function get_json( $url, $headers = array() ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => (int) EANBC_Settings::get( 'timeout' ),
				'user-agent' => self::user_agent(),
				'headers'    => array_merge( array( 'Accept' => 'application/json' ), $headers ),
			)
		);

		if ( is_wp_error( $response ) ) {
			/* translators: %s: technical error message */
			return new WP_Error( 'eanbc_http', sprintf( __( 'Connection failed: %s', 'ean-isbn-barcodes' ), $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			return new WP_Error(
				'eanbc_status',
				/* translators: %d: HTTP status code */
				sprintf( __( 'The service answered with HTTP status %d.', 'ean-isbn-barcodes' ), $code ),
				array(
					'status' => $code,
					'body'   => $data,
				)
			);
		}
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'eanbc_bad_response', __( 'Unexpected answer from the service.', 'ean-isbn-barcodes' ) );
		}
		return $data;
	}
}
