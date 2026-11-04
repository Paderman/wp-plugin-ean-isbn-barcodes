<?php
/**
 * Gutenberg block "EAN & ISBN Barcode" (no build step required).
 *
 * @package EANBC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Block registration.
 */
class EANBC_Block {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
	}

	/**
	 * REST route used by the block to fetch the product name once.
	 */
	public static function register_rest() {
		register_rest_route(
			'eanbc/v1',
			'/lookup',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_lookup' ),
				'permission_callback' => static function ( $request ) {
					$post_id = (int) $request['post_id'];
					return $post_id ? current_user_can( 'edit_post', $post_id ) : current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'ean'     => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'type'    => array(
						'type'    => 'string',
						'default' => '',
						'enum'    => array_merge( array( '' ), EANBC_Barcode::TYPES ),
					),
					'post_id' => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
				),
			)
		);
	}

	/**
	 * Look up a product (EAN from the request or from the post's custom field).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_lookup( $request ) {
		if ( ! EANBC_Providers::has_any() ) {
			return new WP_Error( 'eanbc_no_provider', __( 'No data source is active.', 'ean-isbn-barcodes' ), array( 'status' => 400 ) );
		}
		$data = EANBC_Renderer::lookup_data(
			array(
				'ean'  => $request['ean'],
				'type' => $request['type'],
				'id'   => (string) $request['post_id'],
			)
		);
		if ( is_wp_error( $data ) ) {
			return new WP_Error( $data->get_error_code(), $data->get_error_message(), array( 'status' => 400 ) );
		}
		return rest_ensure_response( $data );
	}

	/**
	 * Register script + block type.
	 */
	public static function register() {
		// Shared with the shortcodes; block.json loads it in the editor iframe, too.
		wp_register_style( 'eanbc-frontend', EANBC_URL . 'assets/css/frontend.css', array(), eanbc_asset_ver( 'assets/css/frontend.css' ) );
		wp_register_script(
			'eanbc-block-editor',
			EANBC_URL . 'assets/js/block.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-api-fetch', 'wp-data' ),
			eanbc_asset_ver( 'assets/js/block.js' ),
			true
		);
		wp_localize_script(
			'eanbc-block-editor',
			'eanbcBlock',
			array(
				'settingsUrl' => current_user_can( 'manage_options' ) ? EANBC_Settings::page_url() : '',
				'sourcesUrl'  => current_user_can( 'manage_options' ) ? EANBC_Settings::page_url( null, 'sources' ) : '',
				'hasSource'   => EANBC_Providers::has_any(),
				'i18n'        => array(
					'placeholder'     => __( 'Enter an EAN or ISBN, e.g. 4006381333931', 'ean-isbn-barcodes' ),
					'ean'             => __( 'EAN / ISBN / GTIN', 'ean-isbn-barcodes' ),
					'eanHelp'         => __( 'Leave empty to use the EAN or ISBN stored in the custom field of this post (e.g. WooCommerce GTIN).', 'ean-isbn-barcodes' ),
					'format'          => __( 'Format', 'ean-isbn-barcodes' ),
					'formatAuto'      => __( 'Automatic', 'ean-isbn-barcodes' ),
					'formatIsbn'      => __( 'ISBN (book)', 'ean-isbn-barcodes' ),
					'formatIssn'      => __( 'ISSN (periodical)', 'ean-isbn-barcodes' ),
					'formatIsmn'      => __( 'ISMN (sheet music)', 'ean-isbn-barcodes' ),
					'addon'           => __( 'Add-on', 'ean-isbn-barcodes' ),
					'addonHelp'       => __( 'Optional, 2 or 5 digits to the right of the barcode: issue number of periodicals or price on books.', 'ean-isbn-barcodes' ),
					'mode'            => __( 'Display', 'ean-isbn-barcodes' ),
					'modeBarcode'     => __( 'Barcode', 'ean-isbn-barcodes' ),
					'modeProduct'     => __( 'Product card', 'ean-isbn-barcodes' ),
					'modeName'        => __( 'Product name only', 'ean-isbn-barcodes' ),
					'modeQr'          => __( 'QR code (GS1 Digital Link)', 'ean-isbn-barcodes' ),
					'qrHelp'          => __( 'Phones open the product’s web address; 2D-capable checkout scanners read the GTIN. Domain and size: plugin settings → Appearance.', 'ean-isbn-barcodes' ),
					'batch'           => __( 'Batch / lot', 'ean-isbn-barcodes' ),
					'serial'          => __( 'Serial number', 'ean-isbn-barcodes' ),
					'qrNumber'        => __( 'Show GTIN below', 'ean-isbn-barcodes' ),
					'barcode'         => __( 'Barcode', 'ean-isbn-barcodes' ),
					'moduleWidth'     => __( 'Bar width (px per module)', 'ean-isbn-barcodes' ),
					'height'          => __( 'Bar height (px)', 'ean-isbn-barcodes' ),
					'number'          => __( 'Show digits', 'ean-isbn-barcodes' ),
					'name'            => __( 'Show product name', 'ean-isbn-barcodes' ),
					'link'            => __( 'Link to product page', 'ean-isbn-barcodes' ),
					'url'             => __( 'Link target (URL)', 'ean-isbn-barcodes' ),
					'urlHelp'         => __( 'Optional. Replaces the link target from the settings, e.g. with your own product page.', 'ean-isbn-barcodes' ),
					'style'           => __( 'Style', 'ean-isbn-barcodes' ),
					'stylePlain'      => __( 'Plain', 'ean-isbn-barcodes' ),
					'styleCard'       => __( 'Card', 'ean-isbn-barcodes' ),
					'title'           => __( 'Own product name', 'ean-isbn-barcodes' ),
					'titleHelp'       => __( 'Shown instead of the name from the data source.', 'ean-isbn-barcodes' ),
					'fetchNeedsToken' => __( 'No data source is active.', 'ean-isbn-barcodes' ),
					'enterToken'      => __( 'Set up data sources', 'ean-isbn-barcodes' ),
					'fetchName'       => __( 'Fetch product name', 'ean-isbn-barcodes' ),
					'fetched'         => __( 'Name taken over. It can be edited freely and is saved with the post.', 'ean-isbn-barcodes' ),
					'category'        => __( 'Category', 'ean-isbn-barcodes' ),
					'country'         => __( 'Issuing country', 'ean-isbn-barcodes' ),
					'authors'         => __( 'Author', 'ean-isbn-barcodes' ),
					'brand'           => __( 'Brand', 'ean-isbn-barcodes' ),
					'dataSource'      => __( 'Source', 'ean-isbn-barcodes' ),
					'caption'         => __( 'Caption', 'ean-isbn-barcodes' ),
					'advanced'        => __( 'More settings', 'ean-isbn-barcodes' ),
					'colors'          => __( 'Colors', 'ean-isbn-barcodes' ),
					'barColor'        => __( 'Bar color', 'ean-isbn-barcodes' ),
					'bgColor'         => __( 'Background', 'ean-isbn-barcodes' ),
					'transparent'     => __( 'Transparent background', 'ean-isbn-barcodes' ),
					'isbnText'        => __( 'Show “ISBN …”, “ISSN …” or “ISMN …” above the bars', 'ean-isbn-barcodes' ),
					'source'          => __( 'Generate barcode', 'ean-isbn-barcodes' ),
					'sourceLocal'     => __( 'Locally (SVG, recommended)', 'ean-isbn-barcodes' ),
					'sourceApi'       => __( 'Via ean-search.org (PNG)', 'ean-isbn-barcodes' ),
					'output'          => __( 'Embed as', 'ean-isbn-barcodes' ),
					'outputInline'    => __( 'Inline SVG', 'ean-isbn-barcodes' ),
					'outputImg'       => __( 'Image (<img>)', 'ean-isbn-barcodes' ),
					'fallback'        => __( 'Fallback text', 'ean-isbn-barcodes' ),
					'fallbackHelp'    => __( 'Shown when ean-search.org returns no product data.', 'ean-isbn-barcodes' ),
					'default'         => __( 'Default (global setting)', 'ean-isbn-barcodes' ),
					'yes'             => __( 'Yes', 'ean-isbn-barcodes' ),
					'no'              => __( 'No', 'ean-isbn-barcodes' ),
					'globalHint'      => __( 'Empty fields use the global settings.', 'ean-isbn-barcodes' ),
					'openSettings'    => __( 'Open global settings', 'ean-isbn-barcodes' ),
					'needsToken'      => __( 'No data source is active. Product data cannot be fetched.', 'ean-isbn-barcodes' ),
					'blockTitle'      => __( 'EAN & ISBN Barcode', 'ean-isbn-barcodes' ),
				),
			)
		);

		register_block_type(
			EANBC_DIR . 'blocks/barcode',
			array( 'render_callback' => array( __CLASS__, 'render' ) )
		);
	}

	/**
	 * Server side render.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render( $attributes ) {
		$map  = array(
			'ean'        => 'ean',
			'type'       => 'type',
			'addon'      => 'addon',
			'batch'      => 'batch',
			'serial'     => 'serial',
			'width'      => 'width',
			'height'     => 'height',
			'number'     => 'number',
			'name'       => 'name',
			'link'       => 'link',
			'style'      => 'look',
			'title'      => 'title',
			'caption'    => 'caption',
			'color'      => 'color',
			'background' => 'background',
			'isbn_text'  => 'isbnText',
			'source'     => 'source',
			'output'     => 'output',
			'fallback'   => 'fallback',
			'url'        => 'url',
			'align'      => 'align',
		);
		$atts = array();
		foreach ( $map as $att => $key ) {
			if ( isset( $attributes[ $key ] ) && is_scalar( $attributes[ $key ] ) && '' !== (string) $attributes[ $key ] ) {
				$atts[ $att ] = (string) $attributes[ $key ];
			}
		}

		$mode = isset( $attributes['mode'] ) ? $attributes['mode'] : 'barcode';
		if ( 'name' === $mode ) {
			$html = EANBC_Renderer::name( $atts );
			return '' === $html ? '' : sprintf( '<p %s>%s</p>', get_block_wrapper_attributes( array( 'class' => 'eanbc-block eanbc-block--name' ) ), $html );
		}

		if ( 'product' === $mode ) {
			$html = EANBC_Renderer::product( $atts );
		} elseif ( 'qr' === $mode ) {
			$html = EANBC_Renderer::qr( $atts );
		} else {
			$html = EANBC_Renderer::barcode( $atts );
		}
		if ( '' === $html ) {
			return '';
		}
		return sprintf( '<div %s>%s</div>', get_block_wrapper_attributes( array( 'class' => 'eanbc-block' ) ), $html );
	}
}
