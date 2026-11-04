<?php
/**
 * Provider: Open Food Facts (food, free, no token, ODbL).
 *
 * Read limit: 15 product requests per minute and IP – results are cached.
 *
 * @see https://openfoodfacts.github.io/openfoodfacts-server/api/
 * @package EANBC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Open Food Facts API v3.
 */
class EANBC_Provider_Open_Food_Facts extends EANBC_Provider {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'open_food_facts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return 'Open Food Facts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Open, community-maintained database of food products: name, brand and category.', 'ean-isbn-barcodes' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function tags() {
		return array( __( 'Food', 'ean-isbn-barcodes' ), __( 'Free, no token', 'ean-isbn-barcodes' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function homepage() {
		return 'https://world.openfoodfacts.org/';
	}

	/**
	 * {@inheritDoc}
	 */
	public function terms_url() {
		return 'https://world.openfoodfacts.org/terms-of-use';
	}

	/**
	 * {@inheritDoc}
	 */
	public function license() {
		return 'ODbL';
	}

	/**
	 * Everything except books, sheet music (979-0) and periodicals (977).
	 *
	 * @param array $parsed Parsed code.
	 * @return bool
	 */
	public function covers( $parsed ) {
		return ! preg_match( '/^(97[789])/', $parsed['lookup'] );
	}

	/**
	 * {@inheritDoc}
	 */
	public function sample_code() {
		return '3017620422003';
	}

	/**
	 * Only food is listed – without data from here a link would mostly end on "not found".
	 *
	 * @return bool
	 */
	public function link_without_data() {
		return false;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $parsed Parsed code.
	 */
	public function product_url( $parsed ) {
		return 'https://world.openfoodfacts.org/product/' . rawurlencode( $parsed['lookup'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $parsed Parsed code.
	 */
	protected function fetch( $parsed ) {
		$lang = strtolower( substr( determine_locale(), 0, 2 ) );
		$lang = preg_match( '/^[a-z]{2}$/', $lang ) ? $lang : 'en';
		$data = EANBC_Http::get_json(
			add_query_arg(
				array(
					'fields' => 'product_name,product_name_' . $lang . ',brands,categories,image_front_small_url',
					'lc'     => $lang,
				),
				'https://world.openfoodfacts.org/api/v3/product/' . rawurlencode( $parsed['lookup'] ) . '.json'
			)
		);

		if ( is_wp_error( $data ) ) {
			$status = (int) ( $data->get_error_data()['status'] ?? 0 );
			if ( 404 === $status ) {
				return $this->not_found();
			}
			if ( 429 === $status || 503 === $status ) {
				return new WP_Error( 'eanbc_quota', __( 'Open Food Facts is busy or the request limit was reached. Please try again later.', 'ean-isbn-barcodes' ) );
			}
			return $data;
		}
		if ( empty( $data['product'] ) || ! is_array( $data['product'] ) ) {
			return $this->not_found();
		}
		$p = $data['product'];

		$name = ! empty( $p[ 'product_name_' . $lang ] ) ? $p[ 'product_name_' . $lang ] : ( isset( $p['product_name'] ) ? $p['product_name'] : '' );

		// Categories come as a comma list, partly with language prefixes ("fr:…") – take the first plain one.
		$category = '';
		foreach ( explode( ',', isset( $p['categories'] ) ? (string) $p['categories'] : '' ) as $cat ) {
			$cat = trim( $cat );
			if ( '' !== $cat && ! preg_match( '/^[a-z]{2}:/', $cat ) ) {
				$category = $cat;
				break;
			}
		}

		return array(
			'name'     => trim( (string) $name ),
			'brand'    => isset( $p['brands'] ) ? trim( (string) $p['brands'] ) : '',
			'category' => $category,
			'image'    => isset( $p['image_front_small_url'] ) ? (string) $p['image_front_small_url'] : '',
			'url'      => 'https://world.openfoodfacts.org/product/' . rawurlencode( $parsed['lookup'] ),
		);
	}
}
