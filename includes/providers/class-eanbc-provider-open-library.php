<?php
/**
 * Provider: Open Library (books, free, no token).
 *
 * @see https://openlibrary.org/dev/docs/api/books
 * @package EANBC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Open Library Books API.
 */
class EANBC_Provider_Open_Library extends EANBC_Provider {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'open_library';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return 'Open Library';
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Open book catalogue of the Internet Archive: title, authors, publisher and year for ISBNs.', 'ean-isbn-barcodes' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function tags() {
		return array( __( 'Books (ISBN)', 'ean-isbn-barcodes' ), __( 'Free, no token', 'ean-isbn-barcodes' ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function homepage() {
		return 'https://openlibrary.org/';
	}

	/**
	 * {@inheritDoc}
	 */
	public function terms_url() {
		return 'https://openlibrary.org/developers/api';
	}

	/**
	 * Books only: ISBN-13 starts with 978 or 979 (979-0 is ISMN, sheet music).
	 *
	 * @param array $parsed Parsed code.
	 * @return bool
	 */
	public function covers( $parsed ) {
		return (bool) preg_match( '/^(978|979[1-9])/', $parsed['lookup'] );
	}

	/**
	 * {@inheritDoc}
	 */
	public function sample_code() {
		return '9780140328721';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $parsed Parsed code.
	 */
	public function product_url( $parsed ) {
		return 'https://openlibrary.org/isbn/' . rawurlencode( $parsed['lookup'] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $parsed Parsed code.
	 */
	protected function fetch( $parsed ) {
		$key  = 'ISBN:' . $parsed['lookup'];
		$data = EANBC_Http::get_json(
			add_query_arg(
				array(
					'bibkeys' => rawurlencode( $key ),
					'jscmd'   => 'data',
					'format'  => 'json',
				),
				'https://openlibrary.org/api/books'
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data[ $key ] ) || ! is_array( $data[ $key ] ) ) {
			return $this->not_found();
		}
		$book = $data[ $key ];

		$title = isset( $book['title'] ) ? trim( (string) $book['title'] ) : '';
		preg_match( '/\b(1[5-9]\d\d|20\d\d)\b/', isset( $book['publish_date'] ) ? (string) $book['publish_date'] : '', $year );
		$publishers = isset( $book['publishers'] ) && is_array( $book['publishers'] ) ? array_slice( $book['publishers'], 0, 1 ) : array();

		return array(
			'name'      => $title,
			'authors'   => self::names( isset( $book['authors'] ) ? $book['authors'] : array() ),
			'publisher' => self::names( $publishers ),
			'year'      => isset( $year[1] ) ? $year[1] : '',
			'image'     => isset( $book['cover']['medium'] ) ? (string) $book['cover']['medium'] : '',
			'url'       => isset( $book['url'] ) ? set_url_scheme( (string) $book['url'], 'https' ) : 'https://openlibrary.org/isbn/' . $parsed['lookup'],
		);
	}

	/**
	 * Join the "name" entries of a list of objects.
	 *
	 * @param mixed $items List like [ { "name": "…" }, … ].
	 * @return string
	 */
	private static function names( $items ) {
		$out = array();
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			if ( is_array( $item ) && ! empty( $item['name'] ) ) {
				$out[] = trim( (string) $item['name'] );
			}
		}
		return implode( ', ', $out );
	}
}
