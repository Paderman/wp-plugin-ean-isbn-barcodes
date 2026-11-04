<?php
/**
 * Admin: menu, settings page, AJAX helpers, notices.
 *
 * @package EANBC
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI.
 */
class EANBC_Admin {

	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'welcome_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( EANBC_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'update_option_' . EANBC_Settings::OPTION, array( __CLASS__, 'after_save' ), 10, 2 );
		// First save when the option does not exist yet (e.g. deleted or never activated properly).
		add_action(
			'add_option_' . EANBC_Settings::OPTION,
			static function ( $option, $value ) {
				EANBC_Admin::after_save( array(), $value );
			},
			10,
			2
		);

		add_action( 'wp_ajax_eanbc_preview', array( __CLASS__, 'ajax_preview' ) );
		add_action( 'wp_ajax_eanbc_test_provider', array( __CLASS__, 'ajax_test_provider' ) );
		add_action( 'wp_ajax_eanbc_clear_cache', array( __CLASS__, 'ajax_clear_cache' ) );
	}

	/**
	 * Menu icon (monochrome SVG, colored by WordPress).
	 *
	 * @return string
	 */
	private static function menu_icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M1 3h2v14H1zm3 0h1v14H4zm2 0h2v14H6zm3 0h1v14H9zm2 0h2v14h-2zm3 0h1v14h-1zm2 0h1v14h-1zm2 0h1v14h-1z"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Register the page where the user wants it (top level by default).
	 */
	public static function menu() {
		$title = __( 'EAN & ISBN Barcodes', 'ean-isbn-barcodes' );
		$cb    = array( __CLASS__, 'render_page' );

		switch ( EANBC_Settings::get( 'menu_location' ) ) {
			case 'settings':
				self::$hook = add_options_page( $title, $title, 'manage_options', EANBC_SLUG, $cb );
				break;
			case 'tools':
				self::$hook = add_management_page( $title, $title, 'manage_options', EANBC_SLUG, $cb );
				break;
			default:
				// Right below "Settings" – easy to spot even with many plugins.
				self::$hook = add_menu_page( $title, $title, 'manage_options', EANBC_SLUG, $cb, self::menu_icon(), 81 );
		}
	}

	/**
	 * Settings API registration.
	 */
	public static function register_setting() {
		register_setting(
			'eanbc_settings_group',
			EANBC_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'EANBC_Settings', 'sanitize' ),
				'default'           => EANBC_Settings::defaults(),
			)
		);
	}

	/**
	 * After saving: invalidate API cache when relevant and follow a moved menu.
	 *
	 * @param array $previous Old value.
	 * @param array $saved    New value.
	 */
	public static function after_save( $previous, $saved ) {
		EANBC_Settings::flush();
		$previous = is_array( $previous ) ? $previous : array();

		// Other sources or tokens can give other answers – start with a fresh cache.
		if ( wp_json_encode( array( $previous['providers'] ?? null, $previous['provider_order'] ?? null ) ) !== wp_json_encode( array( $saved['providers'] ?? null, $saved['provider_order'] ?? null ) ) ) {
			EANBC_Settings::bump_cache_version();
		}

		// The Digital Link resolver adds or removes a rewrite rule.
		if ( ( $previous['qr_domain'] ?? 'gs1' ) !== ( $saved['qr_domain'] ?? 'gs1' ) ) {
			EANBC_Digital_Link::schedule_flush();
		}

		if ( ( $previous['menu_location'] ?? 'toplevel' ) !== ( $saved['menu_location'] ?? 'toplevel' ) ) {
			$target = EANBC_Settings::page_url( $saved['menu_location'] );
			add_filter(
				'wp_redirect',
				static function ( $location ) use ( $target ) {
					if ( false === strpos( $location, 'page=' . EANBC_SLUG ) ) {
						return $location;
					}
					$query = wp_parse_url( $location, PHP_URL_QUERY );
					parse_str( (string) $query, $args );
					unset( $args['page'] );
					return add_query_arg( array_merge( $args, array( 'moved' => 1 ) ), $target );
				}
			);
		}
	}

	/**
	 * "Settings" link in the plugin list.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift(
			$links,
			sprintf( '<a href="%s"><strong>%s</strong></a>', esc_url( EANBC_Settings::page_url() ), esc_html__( 'Settings', 'ean-isbn-barcodes' ) ),
			sprintf( '<a href="%s">%s</a>', esc_url( EANBC_Settings::page_url( null, 'help' ) ), esc_html__( 'Shortcodes', 'ean-isbn-barcodes' ) )
		);
		return $links;
	}

	/**
	 * One-time notice after activation telling the user where the settings are.
	 */
	public static function welcome_notice() {
		if ( ! get_option( EANBC_Settings::NOTICE ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( $screen && self::$hook === $screen->id ) {
			delete_option( EANBC_Settings::NOTICE );
			return;
		}
		if ( ! $screen || 'plugins' !== $screen->id ) {
			return;
		}
		delete_option( EANBC_Settings::NOTICE );
		printf(
			'<div class="notice notice-success is-dismissible"><p><strong>%1$s</strong> %2$s</p><p><a class="button button-primary" href="%3$s">%4$s</a></p></div>',
			esc_html__( 'EAN & ISBN Barcodes is ready.', 'ean-isbn-barcodes' ),
			esc_html__( 'You will find all settings in the left admin menu under “EAN & ISBN Barcodes” (directly below “Settings”). You can move the entry later if you prefer.', 'ean-isbn-barcodes' ),
			esc_url( EANBC_Settings::page_url() ),
			esc_html__( 'Open settings', 'ean-isbn-barcodes' )
		);
	}

	/**
	 * Enqueue admin assets on our page only.
	 *
	 * @param string $hook Current hook.
	 */
	public static function assets( $hook ) {
		if ( $hook !== self::$hook ) {
			return;
		}
		wp_enqueue_style( 'eanbc-admin', EANBC_URL . 'assets/css/admin.css', array(), eanbc_asset_ver( 'assets/css/admin.css' ) );
		wp_enqueue_style( 'eanbc-frontend', EANBC_URL . 'assets/css/frontend.css', array(), eanbc_asset_ver( 'assets/css/frontend.css' ) );
		wp_enqueue_script( 'eanbc-admin', EANBC_URL . 'assets/js/admin.js', array(), eanbc_asset_ver( 'assets/js/admin.js' ), true );
		wp_localize_script(
			'eanbc-admin',
			'eanbcAdmin',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'eanbc_admin' ),
				'previewInterval' => (int) EANBC_Settings::get( 'preview_interval' ),
				'previewFade'     => (int) EANBC_Settings::get( 'preview_fade' ),
				'defaults'        => self::reset_defaults(),
				'providerOrder'   => array_keys( EANBC_Providers::default_settings() ),
				'i18n'            => array(
					'copied'        => __( 'Copied!', 'ean-isbn-barcodes' ),
					'copy'          => __( 'Copy', 'ean-isbn-barcodes' ),
					'testing'       => __( 'Testing connection…', 'ean-isbn-barcodes' ),
					'clearing'      => __( 'Clearing…', 'ean-isbn-barcodes' ),
					'unsaved'       => __( 'You have unsaved changes.', 'ean-isbn-barcodes' ),
					'saved'         => __( 'All changes saved.', 'ean-isbn-barcodes' ),
					'error'         => __( 'Something went wrong. Please try again.', 'ean-isbn-barcodes' ),
					'show'          => __( 'Show', 'ean-isbn-barcodes' ),
					'hide'          => __( 'Hide', 'ean-isbn-barcodes' ),
					'leavePrompt'   => __( 'You have unsaved changes. Leave anyway?', 'ean-isbn-barcodes' ),
					'pauseExamples' => __( 'Pause examples', 'ean-isbn-barcodes' ),
					'playExamples'  => __( 'Play examples', 'ean-isbn-barcodes' ),
					'ownInput'      => __( 'Own input', 'ean-isbn-barcodes' ),
					'resetDone'     => __( 'Defaults restored. They apply once you click “Save settings”.', 'ean-isbn-barcodes' ),
					'resetBuilder'  => __( 'All builder fields reset.', 'ean-isbn-barcodes' ),
					'resetUndone'   => __( 'Previous values restored.', 'ean-isbn-barcodes' ),
					'resetNothing'  => __( 'Everything on this tab already uses the defaults.', 'ean-isbn-barcodes' ),
					'undo'          => __( 'Undo', 'ean-isbn-barcodes' ),
				),
			)
		);
	}

	/*
	 * ---------------------------------------------------------------------
	 * AJAX
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Common capability check (the nonce is checked in each handler).
	 */
	private static function verify() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ean-isbn-barcodes' ) ), 403 );
		}
	}

	/**
	 * Live preview with unsaved settings.
	 */
	public static function ajax_preview() {
		check_ajax_referer( 'eanbc_admin', 'nonce' );
		self::verify();
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
		$settings = EANBC_Settings::sanitize( isset( $_POST[ EANBC_Settings::OPTION ] ) ? wp_unslash( $_POST[ EANBC_Settings::OPTION ] ) : array() );
		$atts     = isset( $_POST['atts'] ) && is_array( $_POST['atts'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['atts'] ) ) : array();
		// phpcs:enable
		$mode = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : 'barcode';
		if ( empty( $atts['ean'] ) ) {
			$atts['ean'] = '4006381333931';
		}

		$html = EANBC_Settings::with(
			$settings,
			static function () use ( $atts, $mode ) {
				if ( 'product' === $mode ) {
					return EANBC_Renderer::product( $atts );
				}
				if ( 'name' === $mode ) {
					return EANBC_Renderer::name( $atts );
				}
				if ( 'qr' === $mode ) {
					return EANBC_Renderer::qr( $atts );
				}
				return EANBC_Renderer::barcode( $atts );
			}
		);

		if ( '' === trim( wp_strip_all_tags( $html, true ) ) && false === strpos( $html, '<svg' ) && false === strpos( $html, '<img' ) ) {
			$html = '<p class="eanbc-preview-empty">' . esc_html__( 'Nothing to show (no product data or invalid code).', 'ean-isbn-barcodes' ) . '</p>';
		}
		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * Test a data source (with an unsaved token, if any).
	 */
	public static function ajax_test_provider() {
		check_ajax_referer( 'eanbc_admin', 'nonce' );
		self::verify();
		$id       = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		$provider = EANBC_Providers::get( $id );
		if ( ! $provider ) {
			wp_send_json_error( array( 'message' => __( 'Unknown data source.', 'ean-isbn-barcodes' ) ) );
		}
		$token = isset( $_POST['token'] ) ? EANBC_Settings::sanitize_token( sanitize_text_field( wp_unslash( $_POST['token'] ) ) ) : '';
		if ( $provider->needs_token() && '' === $token ) {
			wp_send_json_error( array( 'message' => __( 'Please enter an API token first.', 'ean-isbn-barcodes' ) ) );
		}
		$result = $provider->test( $provider->needs_token() ? $token : null );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => $result ) );
	}

	/**
	 * Invalidate all cached API answers.
	 */
	public static function ajax_clear_cache() {
		check_ajax_referer( 'eanbc_admin', 'nonce' );
		self::verify();
		EANBC_Settings::bump_cache_version();
		wp_send_json_success( array( 'message' => __( 'Cache cleared. Product data will be fetched fresh.', 'ean-isbn-barcodes' ) ) );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Field helpers
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Name attribute for a setting.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	private static function name( $key ) {
		return EANBC_Settings::OPTION . '[' . $key . ']';
	}

	/**
	 * Open a field row.
	 *
	 * @param string $key   Key / id.
	 * @param string $label Label.
	 * @param string $desc  Description.
	 * @param string $extra Extra row attributes.
	 */
	private static function row_start( $key, $label, $desc = '', $extra = '' ) {
		printf(
			'<div class="eanbc-field" %4$s><div class="eanbc-field__label"><label for="eanbc-%1$s">%2$s</label>%3$s</div><div class="eanbc-field__control">',
			esc_attr( $key ),
			esc_html( $label ),
			$desc ? '<p>' . wp_kses(
				$desc,
				array(
					'a'      => array(
						'href'   => array(),
						'target' => array(),
						'rel'    => array(),
					),
					'code'   => array(),
					'strong' => array(),
				)
			) . '</p>' : '',
			$extra // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute strings.
		);
	}

	/**
	 * Close a field row.
	 */
	private static function row_end() {
		echo '</div></div>';
	}

	/**
	 * Toggle switch.
	 *
	 * @param string $key   Key.
	 * @param string $label Label.
	 * @param string $desc  Description.
	 */
	private static function toggle( $key, $label, $desc = '' ) {
		self::row_start( $key, $label, $desc );
		printf(
			'<label class="eanbc-switch"><input type="checkbox" id="eanbc-%1$s" name="%2$s" value="1" %3$s><span class="eanbc-switch__track" aria-hidden="true"></span></label>',
			esc_attr( $key ),
			esc_attr( self::name( $key ) ),
			checked( 1, (int) EANBC_Settings::get( $key ), false )
		);
		self::row_end();
	}

	/**
	 * Segmented control (radio group).
	 *
	 * @param string $key     Key.
	 * @param string $label   Label.
	 * @param array  $choices value => label.
	 * @param string $desc    Description.
	 */
	private static function segmented( $key, $label, $choices, $desc = '' ) {
		self::row_start( $key, $label, $desc );
		$current = (string) EANBC_Settings::get( $key );
		echo '<div class="eanbc-segmented" role="radiogroup" aria-label="' . esc_attr( $label ) . '">';
		$first = true;
		foreach ( $choices as $value => $text ) {
			printf(
				'<label><input type="radio" %1$s name="%2$s" value="%3$s" %4$s><span>%5$s</span></label>',
				$first ? 'id="eanbc-' . esc_attr( $key ) . '"' : '',
				esc_attr( self::name( $key ) ),
				esc_attr( $value ),
				checked( $current, (string) $value, false ),
				esc_html( $text )
			);
			$first = false;
		}
		echo '</div>';
		self::row_end();
	}

	/**
	 * Range slider with value bubble.
	 *
	 * @param string $key   Key.
	 * @param string $label Label.
	 * @param float  $min   Min.
	 * @param float  $max   Max.
	 * @param float  $step  Step.
	 * @param string $unit  Unit.
	 * @param string $desc  Description.
	 */
	private static function range( $key, $label, $min, $max, $step, $unit, $desc = '' ) {
		self::row_start( $key, $label, $desc );
		$value = EANBC_Settings::get( $key );
		printf(
			'<div class="eanbc-range"><input type="range" id="eanbc-%1$s" name="%2$s" min="%3$s" max="%4$s" step="%5$s" value="%6$s"><output for="eanbc-%1$s">%6$s</output><span class="eanbc-range__unit">%7$s</span></div>',
			esc_attr( $key ),
			esc_attr( self::name( $key ) ),
			esc_attr( $min ),
			esc_attr( $max ),
			esc_attr( $step ),
			esc_attr( $value ),
			esc_html( $unit )
		);
		self::row_end();
	}

	/**
	 * Color picker with hex input.
	 *
	 * @param string $key   Key.
	 * @param string $label Label.
	 * @param string $after Extra HTML after the control.
	 */
	private static function color( $key, $label, $after = '' ) {
		self::row_start( $key, $label );
		$value = EANBC_Settings::get( $key );
		printf(
			'<div class="eanbc-color"><input type="color" id="eanbc-%1$s" name="%2$s" value="%3$s"><code class="eanbc-color__hex">%3$s</code>%4$s</div>',
			esc_attr( $key ),
			esc_attr( self::name( $key ) ),
			esc_attr( $value ),
			$after // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		);
		self::row_end();
	}

	/**
	 * Select box.
	 *
	 * @param string $key     Key.
	 * @param string $label   Label.
	 * @param array  $choices value => label.
	 * @param string $desc    Description.
	 */
	private static function select( $key, $label, $choices, $desc = '' ) {
		self::row_start( $key, $label, $desc );
		printf( '<select id="eanbc-%s" name="%s" class="eanbc-input">', esc_attr( $key ), esc_attr( self::name( $key ) ) );
		foreach ( $choices as $value => $text ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $value ), selected( (string) EANBC_Settings::get( $key ), (string) $value, false ), esc_html( $text ) );
		}
		echo '</select>';
		self::row_end();
	}

	/**
	 * Card wrapper.
	 *
	 * @param string $title Title.
	 * @param string $icon  Dashicon name.
	 * @param string $intro Intro text.
	 */
	private static function card_start( $title, $icon, $intro = '' ) {
		printf(
			'<div class="eanbc-card"><div class="eanbc-card__head"><span class="dashicons dashicons-%s" aria-hidden="true"></span><div><h2>%s</h2>%s</div></div><div class="eanbc-card__body">',
			esc_attr( $icon ),
			esc_html( $title ),
			$intro ? '<p>' . esc_html( $intro ) . '</p>' : ''
		);
	}

	/**
	 * Close card.
	 */
	private static function card_end() {
		echo '</div></div>';
	}

	/*
	 * ---------------------------------------------------------------------
	 * Page
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Render the settings page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$ready = count( EANBC_Providers::ready() );
		$tabs  = array(
			'general'    => array( __( 'General', 'ean-isbn-barcodes' ), 'admin-generic' ),
			'appearance' => array( __( 'Appearance', 'ean-isbn-barcodes' ), 'art' ),
			'sources'    => array( __( 'Data sources', 'ean-isbn-barcodes' ), 'database' ),
		);
		if ( class_exists( 'WooCommerce' ) ) {
			$tabs['woocommerce'] = array( 'WooCommerce', 'cart' );
		}
		$tabs += array(
			'builder' => array( __( 'Shortcode builder', 'ean-isbn-barcodes' ), 'shortcode' ),
			'help'    => array( __( 'Help', 'ean-isbn-barcodes' ), 'editor-help' ),
		);
		?>
		<div class="wrap eanbc-wrap">
			<h1 class="screen-reader-text"><?php esc_html_e( 'EAN & ISBN Barcodes', 'ean-isbn-barcodes' ); ?></h1>

			<header class="eanbc-hero">
				<div class="eanbc-hero__brand">
					<span class="eanbc-hero__logo" aria-hidden="true">
						<svg viewBox="0 0 20 20"><path fill="currentColor" d="M1 3h2v14H1zm3 0h1v14H4zm2 0h2v14H6zm3 0h1v14H9zm2 0h2v14h-2zm3 0h1v14h-1zm2 0h1v14h-1zm2 0h1v14h-1z"/></svg>
					</span>
					<div>
						<p class="eanbc-hero__title"><?php esc_html_e( 'EAN & ISBN Barcodes', 'ean-isbn-barcodes' ); ?> <span class="eanbc-pill">v<?php echo esc_html( EANBC_VERSION ); ?></span></p>
						<p class="eanbc-hero__sub"><?php esc_html_e( 'Barcodes for posts, pages, products and books – with product data from several sources', 'ean-isbn-barcodes' ); ?></p>
					</div>
				</div>
				<div class="eanbc-hero__status">
					<span class="eanbc-badge <?php echo $ready ? 'is-ok' : 'is-muted'; ?>">
						<span class="eanbc-dot"></span>
						<?php
						echo $ready
							/* translators: %d: number of active data sources */
							? esc_html( sprintf( _n( '%d data source active', '%d data sources active', $ready, 'ean-isbn-barcodes' ), $ready ) )
							: esc_html__( 'No data source active', 'ean-isbn-barcodes' );
						?>
					</span>
				</div>
			</header>
			<hr class="wp-header-end">
			<?php
			// Pages below "Settings" print these automatically.
			if ( 'settings' !== EANBC_Settings::get( 'menu_location' ) ) {
				settings_errors();
			}
			?>

			<?php if ( isset( $_GET['moved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-info is-dismissible"><p><?php esc_html_e( 'The menu entry was moved. This page is now located at the new place in the admin menu.', 'ean-isbn-barcodes' ); ?></p></div>
			<?php endif; ?>

			<nav class="eanbc-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Settings sections', 'ean-isbn-barcodes' ); ?>">
				<?php foreach ( $tabs as $id => $tab ) : ?>
					<a href="#<?php echo esc_attr( $id ); ?>" role="tab" class="eanbc-tab" data-tab="<?php echo esc_attr( $id ); ?>" aria-controls="eanbc-panel-<?php echo esc_attr( $id ); ?>">
						<span class="dashicons dashicons-<?php echo esc_attr( $tab[1] ); ?>" aria-hidden="true"></span><?php echo esc_html( $tab[0] ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="eanbc-layout">
				<form method="post" action="options.php" class="eanbc-main" id="eanbc-form" autocomplete="off">
					<?php settings_fields( 'eanbc_settings_group' ); ?>

					<section class="eanbc-panel" id="eanbc-panel-general" role="tabpanel" data-panel="general">
						<?php
						self::card_start( __( 'This settings page', 'ean-isbn-barcodes' ), 'location', __( 'Too many plugins? Put this page where you look for it.', 'ean-isbn-barcodes' ) );
						self::segmented(
							'menu_location',
							__( 'Menu position', 'ean-isbn-barcodes' ),
							array(
								'toplevel' => __( 'Own menu item', 'ean-isbn-barcodes' ),
								'settings' => __( 'Under “Settings”', 'ean-isbn-barcodes' ),
								'tools'    => __( 'Under “Tools”', 'ean-isbn-barcodes' ),
							),
							__( 'The plugin list always contains a direct “Settings” link as well.', 'ean-isbn-barcodes' )
						);
						$intervals = array();
						foreach ( EANBC_Settings::PREVIEW_INTERVALS as $seconds ) {
							/* translators: %d: number of seconds. */
							$intervals[ $seconds ] = $seconds ? sprintf( _n( 'Every %d second', 'Every %d seconds', $seconds, 'ean-isbn-barcodes' ), $seconds ) : __( 'Off – show one example', 'ean-isbn-barcodes' );
						}
						self::select(
							'preview_interval',
							__( 'Change preview examples', 'ean-isbn-barcodes' ),
							$intervals,
							__( 'How often the live preview switches to the next code type. Pausing is always possible with the button next to the input field.', 'ean-isbn-barcodes' )
						);
						self::select(
							'preview_fade',
							__( 'Fade between examples', 'ean-isbn-barcodes' ),
							array(
								0    => __( 'Off – switch instantly', 'ean-isbn-barcodes' ),
								200  => __( 'Very fast (0.2 s)', 'ean-isbn-barcodes' ),
								350  => __( 'Fast (0.35 s)', 'ean-isbn-barcodes' ),
								600  => __( 'Normal (0.6 s)', 'ean-isbn-barcodes' ),
								1000 => __( 'Slow (1 s)', 'ean-isbn-barcodes' ),
								1500 => __( 'Very slow (1.5 s)', 'ean-isbn-barcodes' ),
							),
							__( 'How softly one example blends into the next. With “Reduce motion” in the operating system, the examples only cross-fade, without movement.', 'ean-isbn-barcodes' )
						);
						self::card_end();

						self::card_start( __( 'Links & behaviour', 'ean-isbn-barcodes' ), 'admin-links' );
						self::toggle( 'link', __( 'Link barcodes', 'ean-isbn-barcodes' ), __( 'Clicking a barcode opens a product page (see “Link target”).', 'ean-isbn-barcodes' ) );
						$targets = array( 'auto' => __( 'Automatic – matching data source', 'ean-isbn-barcodes' ) );
						foreach ( EANBC_Providers::all() as $provider ) {
							if ( '' !== $provider->product_url( EANBC_Barcode::parse( $provider->sample_code() ) ) ) {
								$targets[ $provider->id() ] = $provider->label();
							}
						}
						$targets['custom'] = __( 'Own URL template', 'ean-isbn-barcodes' );
						self::select( 'link_target', __( 'Link target', 'ean-isbn-barcodes' ), $targets, __( '“Automatic” links to the data source that delivered the product data. Without product data, books go to Open Library and other codes to ean-search.org (its product pages are public, no token needed). If a chosen site does not cover a code, “Automatic” is used.', 'ean-isbn-barcodes' ) );
						self::row_start( 'link_template', __( 'URL template', 'ean-isbn-barcodes' ), __( '<code>{code}</code> is replaced by the EAN/ISBN (13 digits), e.g. a search in your own shop.', 'ean-isbn-barcodes' ), 'data-eanbc-show-if="link_target=custom"' );
						printf( '<input type="url" class="eanbc-input" id="eanbc-link_template" name="%s" value="%s" placeholder="https://example.com/search?q={code}">', esc_attr( self::name( 'link_template' ) ), esc_attr( EANBC_Settings::get( 'link_template' ) ) );
						self::row_end();
						self::toggle( 'link_new_tab', __( 'Open links in a new tab', 'ean-isbn-barcodes' ) );
						self::toggle( 'link_nofollow', __( 'Add rel="nofollow"', 'ean-isbn-barcodes' ), __( 'Recommended for SEO when you show many barcodes.', 'ean-isbn-barcodes' ) );
						self::segmented(
							'invalid_display',
							__( 'Invalid codes', 'ean-isbn-barcodes' ),
							array(
								'admins'  => __( 'Hint for editors', 'ean-isbn-barcodes' ),
								'text'    => __( 'Show as text', 'ean-isbn-barcodes' ),
								'nothing' => __( 'Hide', 'ean-isbn-barcodes' ),
							),
							__( '“Hint for editors” shows the reason (e.g. wrong check digit) to logged-in editors only – visitors see nothing.', 'ean-isbn-barcodes' )
						);
						self::row_start( 'meta_key', __( 'Custom field for the EAN', 'ean-isbn-barcodes' ), __( 'Used when a shortcode has no <code>ean</code> or <code>isbn</code> attribute. The WooCommerce GTIN (also from Germanized, German Market and EAN for WooCommerce) and fields named <code>ean</code> or <code>isbn</code> are detected automatically.', 'ean-isbn-barcodes' ) );
						printf( '<input type="text" class="eanbc-input" id="eanbc-meta_key" name="%s" value="%s" placeholder="ean">', esc_attr( self::name( 'meta_key' ) ), esc_attr( EANBC_Settings::get( 'meta_key' ) ) );
						self::row_end();
						self::card_end();
						self::render_reset( 'general' );
						?>
					</section>

					<section class="eanbc-panel" id="eanbc-panel-appearance" role="tabpanel" data-panel="appearance">
						<?php
						self::card_start( __( 'Barcode', 'ean-isbn-barcodes' ), 'editor-justify', __( 'Changes are shown instantly in the preview.', 'ean-isbn-barcodes' ) );
						self::range( 'module_width', __( 'Bar width', 'ean-isbn-barcodes' ), 1, 6, 0.5, __( 'px / module', 'ean-isbn-barcodes' ), __( 'Thickness of the thinnest bar. 2 px is good for screens, 3 px+ for scanning from paper.', 'ean-isbn-barcodes' ) );
						self::range( 'bar_height', __( 'Bar height', 'ean-isbn-barcodes' ), 20, 300, 5, 'px' );
						self::toggle( 'show_number', __( 'Show digits below the bars', 'ean-isbn-barcodes' ) );
						self::toggle( 'show_isbn', __( 'Show “ISBN …”, “ISSN …” or “ISMN …” above the bars', 'ean-isbn-barcodes' ), __( 'Like on a book cover. ISBNs (978/979… or ISBN-10), ISSNs (977…) and ISMNs (979-0…) are detected automatically. Enter the number with hyphens to have them printed, e.g. 978-3-16-148410-0.', 'ean-isbn-barcodes' ) );
						self::color( 'bar_color', __( 'Bar color', 'ean-isbn-barcodes' ) );
						self::color(
							'bg_color',
							__( 'Background', 'ean-isbn-barcodes' ),
							sprintf(
								'<label class="eanbc-inline-check"><input type="checkbox" name="%s" value="1" %s> %s</label>',
								esc_attr( self::name( 'transparent_bg' ) ),
								checked( 1, (int) EANBC_Settings::get( 'transparent_bg' ), false ),
								esc_html__( 'transparent', 'ean-isbn-barcodes' )
							)
						);
						echo '<p class="eanbc-note">' . esc_html__( 'Tip: Scanners need good contrast – dark bars on a light background work best.', 'ean-isbn-barcodes' ) . '</p>';
						self::card_end();

						self::card_start( __( 'Layout', 'ean-isbn-barcodes' ), 'layout' );
						self::segmented(
							'align',
							__( 'Alignment', 'ean-isbn-barcodes' ),
							array(
								'none'   => __( 'Theme default', 'ean-isbn-barcodes' ),
								'left'   => __( 'Left', 'ean-isbn-barcodes' ),
								'center' => __( 'Center', 'ean-isbn-barcodes' ),
								'right'  => __( 'Right', 'ean-isbn-barcodes' ),
							)
						);
						self::segmented(
							'style',
							__( 'Style', 'ean-isbn-barcodes' ),
							array(
								'plain' => __( 'Plain', 'ean-isbn-barcodes' ),
								'card'  => __( 'Card', 'ean-isbn-barcodes' ),
							),
							__( '“Card” adds a subtle frame with rounded corners.', 'ean-isbn-barcodes' )
						);
						self::toggle( 'show_name', __( 'Show product name below the barcode', 'ean-isbn-barcodes' ), __( 'The name comes from the active data sources.', 'ean-isbn-barcodes' ) );
						self::card_end();

						self::card_start( __( 'Rendering', 'ean-isbn-barcodes' ), 'performance' );
						self::segmented(
							'source',
							__( 'Generate barcode', 'ean-isbn-barcodes' ),
							array(
								'local' => __( 'Locally (SVG, recommended)', 'ean-isbn-barcodes' ),
								'api'   => __( 'Via ean-search.org (PNG)', 'ean-isbn-barcodes' ),
							),
							__( 'Local SVGs are razor-sharp, fast and need no requests to other services. The ean-search.org image needs an ean-search.org token; if it fails, the local renderer is used automatically.', 'ean-isbn-barcodes' )
						);
						self::segmented(
							'output',
							__( 'Embed as', 'ean-isbn-barcodes' ),
							array(
								'inline' => __( 'Inline SVG', 'ean-isbn-barcodes' ),
								'img'    => __( 'Image (<img>)', 'ean-isbn-barcodes' ),
							),
							__( '“Image” lets visitors save the barcode via right click.', 'ean-isbn-barcodes' )
						);
						self::card_end();

						self::render_qr_card();
						self::render_reset( 'appearance' );
						?>
					</section>

					<section class="eanbc-panel" id="eanbc-panel-sources" role="tabpanel" data-panel="sources">
						<?php
						self::card_start( __( 'Data sources', 'ean-isbn-barcodes' ), 'database', __( 'Product names and product cards come from these services, asked from top to bottom. If a service does not know a code or does not answer, the next one is asked. Barcodes themselves never need a data source.', 'ean-isbn-barcodes' ) );
						echo '<ol class="eanbc-providers" data-eanbc-providers>';
						foreach ( EANBC_Providers::all() as $provider ) {
							self::provider_row( $provider );
						}
						echo '</ol>';
						echo '<p class="eanbc-note">' . esc_html__( 'Open databases are maintained by volunteers and may contain mistakes. In the block editor, a fetched name can be corrected before publishing.', 'ean-isbn-barcodes' ) . '</p>';
						self::card_end();

						self::card_start( __( 'Connection & cache', 'ean-isbn-barcodes' ), 'performance', __( 'Product data rarely changes. Caching saves requests and keeps your pages fast.', 'ean-isbn-barcodes' ) );
						self::range( 'timeout', __( 'Request timeout', 'ean-isbn-barcodes' ), 2, 30, 1, __( 'seconds', 'ean-isbn-barcodes' ) );
						self::range( 'cache_days', __( 'Keep product data for', 'ean-isbn-barcodes' ), 0, 365, 1, __( 'days', 'ean-isbn-barcodes' ), __( '0 disables the cache (not recommended).', 'ean-isbn-barcodes' ) );
						self::row_start( 'clear', __( 'Clear cache', 'ean-isbn-barcodes' ), __( 'Fetch all product data fresh on the next page view.', 'ean-isbn-barcodes' ) );
						echo '<button type="button" class="eanbc-btn eanbc-btn--ghost" id="eanbc-clear" data-eanbc-clear>' . esc_html__( 'Clear now', 'ean-isbn-barcodes' ) . '</button><div class="eanbc-result" data-eanbc-clear-result aria-live="polite"></div>';
						self::row_end();
						self::card_end();
						self::render_reset( 'sources' );
						?>
					</section>

					<?php self::render_woocommerce(); ?>

					<section class="eanbc-panel" id="eanbc-panel-builder" role="tabpanel" data-panel="builder">
						<?php
						self::render_builder();
						self::render_reset( 'builder' );
						?>
					</section>

					<section class="eanbc-panel" id="eanbc-panel-help" role="tabpanel" data-panel="help">
						<?php self::render_help(); ?>
					</section>

					<div class="eanbc-savebar">
						<span class="eanbc-savebar__state" data-eanbc-state><?php esc_html_e( 'All changes saved.', 'ean-isbn-barcodes' ); ?></span>
						<button type="submit" class="eanbc-btn eanbc-btn--primary"><?php esc_html_e( 'Save settings', 'ean-isbn-barcodes' ); ?></button>
					</div>
				</form>

				<aside class="eanbc-side">
					<div class="eanbc-card eanbc-preview">
						<div class="eanbc-card__head">
							<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
							<div><h2><?php esc_html_e( 'Live preview', 'ean-isbn-barcodes' ); ?></h2><p><?php esc_html_e( 'Uses your current, unsaved settings.', 'ean-isbn-barcodes' ); ?></p></div>
						</div>
						<div class="eanbc-card__body">
							<?php self::render_preview_examples(); ?>
							<div class="eanbc-preview__bar">
								<label class="screen-reader-text" for="eanbc-preview-ean"><?php esc_html_e( 'EAN or ISBN for the preview', 'ean-isbn-barcodes' ); ?></label>
								<input type="text" class="eanbc-input eanbc-input--mono" id="eanbc-preview-ean" value="4006381333931" placeholder="4006381333931 / 978-3-16-148410-0">
								<button type="button" class="eanbc-iconbtn eanbc-rotate" data-eanbc-rotate aria-pressed="true">
									<span class="dashicons dashicons-controls-pause" aria-hidden="true"></span>
									<span class="screen-reader-text" data-eanbc-rotate-label><?php esc_html_e( 'Pause examples', 'ean-isbn-barcodes' ); ?></span>
								</button>
							</div>
							<div class="eanbc-progress" data-eanbc-progress aria-hidden="true"><span></span></div>
							<div class="eanbc-stage" data-eanbc-preview aria-live="polite"></div>
							<p class="eanbc-preview__caption" data-eanbc-caption></p>
						</div>
					</div>
					<div class="eanbc-card eanbc-quick">
						<div class="eanbc-card__body">
							<p class="eanbc-quick__title"><?php esc_html_e( 'Quick start', 'ean-isbn-barcodes' ); ?></p>
							<?php self::copy_snippet( '[barcode ean="4006381333931"]' ); ?>
							<p class="eanbc-quick__hint"><?php esc_html_e( 'Paste into any post, page or widget. In the block editor, search for the “EAN & ISBN Barcode” block.', 'ean-isbn-barcodes' ); ?></p>
						</div>
					</div>
				</aside>
			</div>
		</div>
		<?php
	}

	/**
	 * Default value of every resettable form field, keyed by field name.
	 * API tokens are left out on purpose: a reset must never delete access data.
	 *
	 * @return array
	 */
	private static function reset_defaults() {
		$out = array();
		foreach ( EANBC_Settings::defaults() as $key => $value ) {
			if ( 'providers' === $key ) {
				foreach ( $value as $id => $provider ) {
					$out[ self::name( 'providers' ) . '[' . $id . '][enabled]' ] = (string) (int) $provider['enabled'];
				}
				continue;
			}
			if ( is_array( $value ) ) {
				continue; // provider_order: handled as row order in JS.
			}
			$out[ self::name( $key ) ] = (string) $value;
		}
		return $out;
	}

	/**
	 * "Reset this tab" bar at the end of a tab.
	 *
	 * @param string $panel Tab id.
	 */
	private static function render_reset( $panel ) {
		$hints = array(
			'sources' => __( 'Switches all data sources on and restores their order, the timeout and the cache time. API tokens are kept. Nothing changes until you save.', 'ean-isbn-barcodes' ),
			'builder' => __( 'Empties the builder fields and starts again with the example code.', 'ean-isbn-barcodes' ),
		);
		$hint  = isset( $hints[ $panel ] ) ? $hints[ $panel ] : __( 'Sets everything on this tab back to the defaults. Nothing changes until you save.', 'ean-isbn-barcodes' );
		printf(
			'<div class="eanbc-reset" data-eanbc-reset="%1$s"><button type="button" class="eanbc-btn eanbc-btn--ghost" data-eanbc-reset-btn><span class="dashicons dashicons-image-rotate" aria-hidden="true"></span>%2$s</button><p class="eanbc-reset__hint">%3$s</p><p class="eanbc-reset__status" data-eanbc-reset-status aria-live="polite"></p></div>',
			esc_attr( $panel ),
			esc_html__( 'Reset this tab', 'ean-isbn-barcodes' ),
			esc_html( $hint )
		);
	}

	/**
	 * Example chips of the live preview (one per code type; they rotate automatically).
	 */
	private static function render_preview_examples() {
		$examples = array(
			array( 'EAN-13', '4006381333931', 'barcode', __( 'EAN-13 – the usual product code in Europe', 'ean-isbn-barcodes' ) ),
			array( 'EAN-8', '96385074', 'barcode', __( 'EAN-8 – short code for small packages', 'ean-isbn-barcodes' ) ),
			array( 'UPC-A', '036000291452', 'barcode', __( 'UPC-A – product code in the USA and Canada', 'ean-isbn-barcodes' ) ),
			array( 'UPC-E', '04252614', 'barcode', __( 'UPC-E – short form of UPC-A', 'ean-isbn-barcodes' ) ),
			array( 'ISBN', '978-0-14-032872-1', 'barcode', __( 'ISBN – book with the “ISBN …” line', 'ean-isbn-barcodes' ) ),
			array( __( 'ISBN + price', 'ean-isbn-barcodes' ), '978-3-16-148410-0 52499', 'barcode', __( 'Book with a 5-digit price add-on (5 = US dollars, 24.99)', 'ean-isbn-barcodes' ) ),
			array( __( 'ISSN + issue', 'ean-isbn-barcodes' ), 'ISSN 0317-8471 05', 'barcode', __( 'ISSN – periodical with issue number 05 as add-on', 'ean-isbn-barcodes' ) ),
			array( 'ISMN', '979-0-2600-0043-8', 'barcode', __( 'ISMN – sheet music', 'ean-isbn-barcodes' ) ),
			array( __( 'QR code', 'ean-isbn-barcodes' ), '4006381333931', 'qr', __( 'QR code with the GS1 Digital Link of the product', 'ean-isbn-barcodes' ) ),
		);
		echo '<div class="eanbc-examples" role="group" aria-label="' . esc_attr__( 'Examples', 'ean-isbn-barcodes' ) . '">';
		foreach ( $examples as $i => $ex ) {
			printf(
				'<button type="button" class="eanbc-chip" data-eanbc-example="%s" data-mode="%s" data-caption="%s" aria-pressed="%s">%s</button>',
				esc_attr( $ex[1] ),
				esc_attr( $ex[2] ),
				esc_attr( $ex[3] ),
				0 === $i ? 'true' : 'false',
				esc_html( $ex[0] )
			);
		}
		echo '</div>';
	}

	/**
	 * Card "QR code (GS1 Digital Link)" in the appearance tab.
	 */
	private static function render_qr_card() {
		self::card_start( __( 'QR code (GS1 Digital Link)', 'ean-isbn-barcodes' ), 'smartphone', __( 'The QR code contains a web address with the GTIN. Phones open it, and 2D-capable checkout scanners read the GTIN from it – the successor of the classic barcode (GS1 “Sunrise 2027”).', 'ean-isbn-barcodes' ) );
		$sample = EANBC_Barcode::parse( '4006381333931' );
		self::select(
			'qr_domain',
			__( 'Web address in the QR code', 'ean-isbn-barcodes' ),
			array(
				'gs1'    => __( 'GS1 (id.gs1.org) – works without setup', 'ean-isbn-barcodes' ),
				'site'   => __( 'This website – leads to the product in the shop', 'ean-isbn-barcodes' ),
				'custom' => __( 'Own resolver domain', 'ean-isbn-barcodes' ),
			),
			sprintf(
				/* translators: 1: example address with GS1, 2: example address with this website */
				__( 'Example: %1$s. With “This website” the code reads %2$s; the plugin then forwards to the WooCommerce product with this GTIN, otherwise to the link target.', 'ean-isbn-barcodes' ),
				'<code>' . esc_html( EANBC_Digital_Link::GS1_RESOLVER . '/01/' . EANBC_Digital_Link::gtin14( $sample ) ) . '</code>',
				'<code>' . esc_html( untrailingslashit( home_url() ) . '/01/' . EANBC_Digital_Link::gtin14( $sample ) ) . '</code>'
			)
		);
		if ( ! EANBC_Digital_Link::site_possible() ) {
			self::row_start( 'qr_site_note', '', '', 'data-eanbc-show-if="qr_domain=site"' );
			printf(
				'<p class="eanbc-warning">%s <a href="%s">%s</a></p>',
				esc_html__( '“This website” needs speaking permalinks (not “Plain”). Until then, GS1 is used.', 'ean-isbn-barcodes' ),
				esc_url( admin_url( 'options-permalink.php' ) ),
				esc_html__( 'Change permalinks', 'ean-isbn-barcodes' )
			);
			self::row_end();
		}
		self::row_start( 'qr_custom', __( 'Resolver domain', 'ean-isbn-barcodes' ), __( 'A domain that answers GS1 Digital Link addresses (…/01/{GTIN}), e.g. from your GS1 organisation or ERP.', 'ean-isbn-barcodes' ), 'data-eanbc-show-if="qr_domain=custom"' );
		printf( '<input type="url" class="eanbc-input" id="eanbc-qr_custom" name="%s" value="%s" placeholder="https://id.example.com">', esc_attr( self::name( 'qr_custom' ) ), esc_attr( EANBC_Settings::get( 'qr_custom' ) ) );
		self::row_end();
		self::range( 'qr_module', __( 'QR code size', 'ean-isbn-barcodes' ), 1, 20, 1, __( 'px per square', 'ean-isbn-barcodes' ), __( 'For print, at least 0.4 mm per square is recommended.', 'ean-isbn-barcodes' ) );
		self::select(
			'qr_ecc',
			__( 'Error correction', 'ean-isbn-barcodes' ),
			array(
				'L' => __( 'L – smallest code (7 %)', 'ean-isbn-barcodes' ),
				'M' => __( 'M – recommended (15 %)', 'ean-isbn-barcodes' ),
				'Q' => __( 'Q – robust (25 %)', 'ean-isbn-barcodes' ),
				'H' => __( 'H – very robust (30 %)', 'ean-isbn-barcodes' ),
			),
			__( 'How much of the code may be damaged or covered and still be read.', 'ean-isbn-barcodes' )
		);
		self::card_end();
	}

	/**
	 * "WooCommerce" tab. Without WooCommerce its values are kept as hidden fields,
	 * so saving the other tabs does not reset them.
	 */
	private static function render_woocommerce() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			foreach ( array( 'wc_display', 'wc_code', 'wc_column', 'wc_lookup' ) as $key ) {
				printf( '<input type="hidden" name="%s" value="%s">', esc_attr( self::name( $key ) ), esc_attr( EANBC_Settings::get( $key ) ) );
			}
			return;
		}
		echo '<section class="eanbc-panel" id="eanbc-panel-woocommerce" role="tabpanel" data-panel="woocommerce">';

		self::card_start( __( 'Product page', 'ean-isbn-barcodes' ), 'cart', __( 'Shows the barcode of the product’s GTIN in the shop. For variable products, the barcode changes with the selected variation.', 'ean-isbn-barcodes' ) );
		self::select(
			'wc_display',
			__( 'Barcode on the product page', 'ean-isbn-barcodes' ),
			array(
				'none'          => __( 'Do not show', 'ean-isbn-barcodes' ),
				'meta'          => __( 'With SKU and categories', 'ean-isbn-barcodes' ),
				'after_summary' => __( 'Below the product summary', 'ean-isbn-barcodes' ),
			),
			__( 'Size and colours follow the “Appearance” tab. Products without a GTIN show nothing.', 'ean-isbn-barcodes' )
		);
		self::select(
			'wc_code',
			__( 'Code type', 'ean-isbn-barcodes' ),
			array(
				'barcode' => __( 'Barcode', 'ean-isbn-barcodes' ),
				'qr'      => __( 'QR code (GS1 Digital Link)', 'ean-isbn-barcodes' ),
				'both'    => __( 'Barcode and QR code', 'ean-isbn-barcodes' ),
			)
		);
		self::card_end();

		self::card_start( __( 'Product management', 'ean-isbn-barcodes' ), 'products' );
		self::toggle( 'wc_column', __( 'GTIN column in the product list', 'ean-isbn-barcodes' ), __( 'Small barcode with the code; invalid GTINs (e.g. wrong check digit) are marked in red.', 'ean-isbn-barcodes' ) );
		self::toggle( 'wc_lookup', __( 'Look up product data in the product editor', 'ean-isbn-barcodes' ), __( 'Button next to the GTIN field: shows name, brand or author from the data sources and can take the name over as product name.', 'ean-isbn-barcodes' ) );
		self::card_end();

		self::card_start( __( 'GTIN fields', 'ean-isbn-barcodes' ), 'database', __( 'The GTIN is read from these fields, top to bottom. Developers can add more via the “eanbc_gtin_meta_keys” filter.', 'ean-isbn-barcodes' ) );
		echo '<div class="eanbc-table-wrap"><table class="eanbc-table"><thead><tr><th>' . esc_html__( 'Plugin', 'ean-isbn-barcodes' ) . '</th><th>' . esc_html__( 'Field', 'ean-isbn-barcodes' ) . '</th><th>' . esc_html__( 'Products with a value', 'ean-isbn-barcodes' ) . '</th></tr></thead><tbody>';
		foreach ( EANBC_WooCommerce::sources() as $source ) {
			printf( '<tr><td>%s</td><td><code>%s</code></td><td>%s</td></tr>', esc_html( $source[1] ), esc_html( $source[0] ), esc_html( number_format_i18n( $source[2] ) ) );
		}
		echo '</tbody></table></div>';
		self::card_end();

		self::render_reset( 'woocommerce' );
		echo '</section>';
	}

	/**
	 * One data source in the "Data sources" list (enable, order, token, test).
	 *
	 * @param EANBC_Provider $provider Provider.
	 */
	private static function provider_row( $provider ) {
		$id       = $provider->id();
		$settings = $provider->settings();
		$base     = self::name( 'providers' ) . '[' . $id . ']';
		$label    = $provider->label();

		$links = array();
		if ( $provider->homepage() ) {
			$links[] = sprintf( '<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( $provider->homepage() ), esc_html__( 'Website', 'ean-isbn-barcodes' ) );
		}
		if ( $provider->terms_url() ) {
			$links[] = sprintf( '<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( $provider->terms_url() ), esc_html__( 'Terms of use', 'ean-isbn-barcodes' ) );
		}
		$tags = '';
		foreach ( $provider->tags() as $tag ) {
			$tags .= '<span class="eanbc-tag">' . esc_html( $tag ) . '</span>';
		}
		?>
		<li class="eanbc-provider" data-provider="<?php echo esc_attr( $id ); ?>">
			<input type="hidden" name="<?php echo esc_attr( self::name( 'provider_order' ) ); ?>[]" value="<?php echo esc_attr( $id ); ?>">
			<div class="eanbc-provider__head">
				<label class="eanbc-switch">
					<input type="checkbox" name="<?php echo esc_attr( $base ); ?>[enabled]" value="1" <?php checked( 1, (int) $settings['enabled'] ); ?> aria-label="<?php /* translators: %s: data source name */ echo esc_attr( sprintf( __( 'Use %s', 'ean-isbn-barcodes' ), $label ) ); ?>">
					<span class="eanbc-switch__track" aria-hidden="true"></span>
				</label>
				<div class="eanbc-provider__title">
					<strong><?php echo esc_html( $label ); ?></strong>
					<?php echo $tags; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
				</div>
				<div class="eanbc-provider__move">
					<button type="button" class="eanbc-iconbtn" data-eanbc-move="-1" aria-label="<?php /* translators: %s: data source name */ echo esc_attr( sprintf( __( 'Move %s up', 'ean-isbn-barcodes' ), $label ) ); ?>"><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button>
					<button type="button" class="eanbc-iconbtn" data-eanbc-move="1" aria-label="<?php /* translators: %s: data source name */ echo esc_attr( sprintf( __( 'Move %s down', 'ean-isbn-barcodes' ), $label ) ); ?>"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button>
				</div>
			</div>
			<p class="eanbc-provider__desc">
				<?php echo esc_html( $provider->description() ); ?>
				<?php echo $links ? ' ' . implode( ' · ', $links ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
			</p>
			<div class="eanbc-token">
				<?php if ( $provider->needs_token() ) : ?>
					<label class="screen-reader-text" for="eanbc-token-<?php echo esc_attr( $id ); ?>"><?php /* translators: %s: data source name */ echo esc_html( sprintf( __( 'API token for %s', 'ean-isbn-barcodes' ), $label ) ); ?></label>
					<input type="password" class="eanbc-input" id="eanbc-token-<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $base ); ?>[token]" value="<?php echo esc_attr( $settings['token'] ); ?>" placeholder="<?php esc_attr_e( 'API token', 'ean-isbn-barcodes' ); ?>" spellcheck="false" autocomplete="new-password" data-eanbc-token>
					<button type="button" class="eanbc-btn eanbc-btn--ghost" data-eanbc-toggle-token><?php esc_html_e( 'Show', 'ean-isbn-barcodes' ); ?></button>
				<?php endif; ?>
				<button type="button" class="eanbc-btn" data-eanbc-test><?php esc_html_e( 'Test connection', 'ean-isbn-barcodes' ); ?></button>
				<?php if ( $provider->needs_token() && $provider->token_url() ) : ?>
					<a class="eanbc-token__get" href="<?php echo esc_url( $provider->token_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Get a token', 'ean-isbn-barcodes' ); ?></a>
				<?php endif; ?>
			</div>
			<div class="eanbc-result" data-eanbc-test-result aria-live="polite"></div>
		</li>
		<?php
	}

	/**
	 * Copyable code snippet.
	 *
	 * @param string $code Shortcode.
	 */
	private static function copy_snippet( $code ) {
		printf(
			'<div class="eanbc-snippet"><code>%1$s</code><button type="button" class="eanbc-copy" data-eanbc-copy="%2$s" aria-label="%3$s"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span></button></div>',
			esc_html( $code ),
			esc_attr( $code ),
			esc_attr__( 'Copy', 'ean-isbn-barcodes' )
		);
	}

	/**
	 * Shortcode builder tab (fields have no name => not saved).
	 */
	private static function render_builder() {
		$yn = '<option value="">' . esc_html__( 'Default', 'ean-isbn-barcodes' ) . '</option><option value="yes">' . esc_html__( 'Yes', 'ean-isbn-barcodes' ) . '</option><option value="no">' . esc_html__( 'No', 'ean-isbn-barcodes' ) . '</option>';
		self::card_start( __( 'Shortcode builder', 'ean-isbn-barcodes' ), 'shortcode', __( 'Click your barcode together and copy the shortcode. Empty fields use the global settings.', 'ean-isbn-barcodes' ) );
		?>
		<div class="eanbc-builder" data-eanbc-builder>
			<div class="eanbc-grid">
				<label class="eanbc-grid__wide"><span><?php esc_html_e( 'EAN / ISBN / GTIN', 'ean-isbn-barcodes' ); ?></span>
					<input type="text" class="eanbc-input eanbc-input--mono" data-b="ean" value="4006381333931"></label>
				<label><span><?php esc_html_e( 'Type', 'ean-isbn-barcodes' ); ?></span>
					<select class="eanbc-input" data-b="__tag">
						<option value="barcode"><?php esc_html_e( 'Barcode', 'ean-isbn-barcodes' ); ?></option>
						<option value="ean_product"><?php esc_html_e( 'Product card', 'ean-isbn-barcodes' ); ?></option>
						<option value="ean_name"><?php esc_html_e( 'Product name (text)', 'ean-isbn-barcodes' ); ?></option>
						<option value="ean_qr"><?php esc_html_e( 'QR code (GS1 Digital Link)', 'ean-isbn-barcodes' ); ?></option>
					</select></label>
				<label><span><?php esc_html_e( 'Format', 'ean-isbn-barcodes' ); ?></span>
					<select class="eanbc-input" data-b="type">
						<option value=""><?php esc_html_e( 'Automatic', 'ean-isbn-barcodes' ); ?></option>
						<option value="ean13">EAN-13</option>
						<option value="ean8">EAN-8</option>
						<option value="upca">UPC-A</option>
						<option value="upce">UPC-E</option>
						<option value="isbn"><?php esc_html_e( 'ISBN (book)', 'ean-isbn-barcodes' ); ?></option>
						<option value="issn"><?php esc_html_e( 'ISSN (periodical)', 'ean-isbn-barcodes' ); ?></option>
						<option value="ismn"><?php esc_html_e( 'ISMN (sheet music)', 'ean-isbn-barcodes' ); ?></option>
					</select></label>
				<label><span><?php esc_html_e( 'Add-on', 'ean-isbn-barcodes' ); ?></span>
					<input type="text" class="eanbc-input eanbc-input--mono" data-b="addon" inputmode="numeric" maxlength="5" placeholder="52499"></label>
				<label><span><?php esc_html_e( 'Bar width (px)', 'ean-isbn-barcodes' ); ?></span>
					<input type="number" class="eanbc-input" data-b="width" min="1" max="6" step="0.5" placeholder="<?php echo esc_attr( EANBC_Settings::get( 'module_width' ) ); ?>"></label>
				<label><span><?php esc_html_e( 'Bar height (px)', 'ean-isbn-barcodes' ); ?></span>
					<input type="number" class="eanbc-input" data-b="height" min="20" max="300" step="5" placeholder="<?php echo esc_attr( EANBC_Settings::get( 'bar_height' ) ); ?>"></label>
				<label><span><?php esc_html_e( 'Digits', 'ean-isbn-barcodes' ); ?></span><select class="eanbc-input" data-b="number"><?php echo $yn; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></select></label>
				<label><span><?php esc_html_e( 'Product name', 'ean-isbn-barcodes' ); ?></span><select class="eanbc-input" data-b="name"><?php echo $yn; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></select></label>
				<label><span><?php esc_html_e( 'Link', 'ean-isbn-barcodes' ); ?></span><select class="eanbc-input" data-b="link"><?php echo $yn; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></select></label>
				<label><span><?php esc_html_e( 'Alignment', 'ean-isbn-barcodes' ); ?></span>
					<select class="eanbc-input" data-b="align">
						<option value=""><?php esc_html_e( 'Default', 'ean-isbn-barcodes' ); ?></option>
						<option value="left"><?php esc_html_e( 'Left', 'ean-isbn-barcodes' ); ?></option>
						<option value="center"><?php esc_html_e( 'Center', 'ean-isbn-barcodes' ); ?></option>
						<option value="right"><?php esc_html_e( 'Right', 'ean-isbn-barcodes' ); ?></option>
					</select></label>
				<label><span><?php esc_html_e( 'Style', 'ean-isbn-barcodes' ); ?></span>
					<select class="eanbc-input" data-b="style">
						<option value=""><?php esc_html_e( 'Default', 'ean-isbn-barcodes' ); ?></option>
						<option value="plain"><?php esc_html_e( 'Plain', 'ean-isbn-barcodes' ); ?></option>
						<option value="card"><?php esc_html_e( 'Card', 'ean-isbn-barcodes' ); ?></option>
					</select></label>
				<label><span><?php esc_html_e( 'Bar color', 'ean-isbn-barcodes' ); ?></span>
					<input type="text" class="eanbc-input eanbc-input--mono" data-b="color" placeholder="<?php echo esc_attr( EANBC_Settings::get( 'bar_color' ) ); ?>"></label>
				<label class="eanbc-grid__wide"><span><?php esc_html_e( 'Own product name', 'ean-isbn-barcodes' ); ?></span>
					<input type="text" class="eanbc-input" data-b="title"></label>
				<label class="eanbc-grid__wide"><span><?php esc_html_e( 'Link target (URL)', 'ean-isbn-barcodes' ); ?></span>
					<input type="url" class="eanbc-input" data-b="url" placeholder="https://"></label>
				<label><span><?php esc_html_e( 'Batch / lot', 'ean-isbn-barcodes' ); ?></span>
					<input type="text" class="eanbc-input eanbc-input--mono" data-b="batch" maxlength="20" placeholder="QR"></label>
				<label><span><?php esc_html_e( 'Serial number', 'ean-isbn-barcodes' ); ?></span>
					<input type="text" class="eanbc-input eanbc-input--mono" data-b="serial" maxlength="20" placeholder="QR"></label>
				<label class="eanbc-grid__wide"><span><?php esc_html_e( 'Caption', 'ean-isbn-barcodes' ); ?></span>
					<input type="text" class="eanbc-input" data-b="caption"></label>
			</div>
			<div class="eanbc-builder__out">
				<div class="eanbc-snippet eanbc-snippet--big"><code data-eanbc-builder-code></code><button type="button" class="eanbc-copy" data-eanbc-copy="" aria-label="<?php esc_attr_e( 'Copy', 'ean-isbn-barcodes' ); ?>"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span></button></div>
				<div class="eanbc-stage" data-eanbc-builder-preview aria-live="polite"></div>
			</div>
		</div>
		<?php
		self::card_end();
	}

	/**
	 * Help tab: shortcode reference.
	 */
	private static function render_help() {
		self::card_start( __( 'Shortcodes', 'ean-isbn-barcodes' ), 'shortcode' );
		$codes = array(
			array( '[barcode ean="4006381333931"]', __( 'Barcode. Aliases: [ean] and [ean_barcode] (the latter always works, even if another plugin uses [barcode]).', 'ean-isbn-barcodes' ) ),
			array( '[barcode 4006381333931]', __( 'Short form without attribute name.', 'ean-isbn-barcodes' ) ),
			array( '[barcode ean="00885909943074"]', __( 'GTIN-14 with leading zero is converted to EAN-13 automatically.', 'ean-isbn-barcodes' ) ),
			array( '[barcode]', __( 'Without EAN: uses the custom field of the current post or the WooCommerce GTIN.', 'ean-isbn-barcodes' ) ),
			array( '[ean_product ean="4006381333931"]', __( 'Product card with barcode, name and details from the data sources.', 'ean-isbn-barcodes' ) ),
			array( '[ean_name ean="4006381333931" fallback="Unknown product"]', __( 'Only the product name as text – great inside tables and lists.', 'ean-isbn-barcodes' ) ),
			array( '[isbn 978-3-16-148410-0]', __( 'Book barcode with the “ISBN …” line above the bars. Alias: [isbn_barcode] (always works, even if another plugin uses [isbn]).', 'ean-isbn-barcodes' ) ),
			array( '[isbn 3-16-148410-X]', __( 'ISBN-10 is converted to ISBN-13 (978…) automatically.', 'ean-isbn-barcodes' ) ),
			array( '[isbn_product isbn="9780140328721"]', __( 'Product card for a book: title, author, publisher, ISBN-13 and ISBN-10.', 'ean-isbn-barcodes' ) ),
			array( '[isbn_name isbn="9780140328721"]', __( 'Only the book title as text.', 'ean-isbn-barcodes' ) ),
			array( '[isbn 978-3-16-148410-0 addon="52499"]', __( 'With a 5-digit add-on, e.g. the price on books (5 = US dollars, 2499 = 24.99).', 'ean-isbn-barcodes' ) ),
			array( '[barcode issn="0317-8471" addon="05"]', __( 'Periodical: the ISSN becomes an EAN-13 with 977, the 2-digit add-on is the issue number.', 'ean-isbn-barcodes' ) ),
			array( '[barcode ismn="979-0-2600-0043-8"]', __( 'Sheet music (ISMN) with the “ISMN …” line. Old ISMNs starting with M are converted.', 'ean-isbn-barcodes' ) ),
			array( '[barcode ean="04252614"]', __( 'UPC-E – the short form of UPC-A for small packages. Use type="upce" to shorten a UPC-A.', 'ean-isbn-barcodes' ) ),
			array( '[ean_qr ean="4006381333931"]', __( 'QR code with the GS1 Digital Link: phones open the product’s web address, 2D-capable checkout scanners read the GTIN. Alias: [gs1_qr].', 'ean-isbn-barcodes' ) ),
			array( '[ean_qr ean="4006381333931" batch="L2026-11" serial="0001"]', __( 'QR code with batch and serial number, e.g. for labels.', 'ean-isbn-barcodes' ) ),
		);
		echo '<div class="eanbc-codes">';
		foreach ( $codes as $c ) {
			echo '<div class="eanbc-codes__row">';
			self::copy_snippet( $c[0] );
			echo '<p>' . esc_html( $c[1] ) . '</p></div>';
		}
		echo '</div>';
		self::card_end();

		self::card_start( __( 'Attributes', 'ean-isbn-barcodes' ), 'list-view', __( 'All attributes are optional and override the global settings for a single barcode.', 'ean-isbn-barcodes' ) );
		$attrs = array(
			array( 'ean', '4006381333931', __( 'EAN-13, EAN-8, UPC-A, UPC-E, ISBN-13, ISBN-10, ISSN, ISMN or GTIN-14 with leading zero. Spaces and dashes are ignored. An add-on can follow after a space or “+”.', 'ean-isbn-barcodes' ) ),
			array( 'isbn', '978-3-16-148410-0', __( 'Same as ean – use whichever reads better.', 'ean-isbn-barcodes' ) ),
			array( 'issn / ismn', '0317-8471', __( 'Like ean, but for an ISSN (periodical) or ISMN (sheet music).', 'ean-isbn-barcodes' ) ),
			array( 'type', 'ean13 | ean8 | upca | upce | isbn | issn | ismn', __( 'Force a format, e.g. show a UPC-A as EAN-13 or UPC-E, or a book ISBN as plain EAN-13 without the ISBN line.', 'ean-isbn-barcodes' ) ),
			array( 'addon', '12 | 52499', __( '2- or 5-digit add-on to the right of the barcode: issue number of periodicals or price on books.', 'ean-isbn-barcodes' ) ),
			array( 'width', '1 – 6', __( 'Bar width in px per module.', 'ean-isbn-barcodes' ) ),
			array( 'height', '20 – 300', __( 'Bar height in px.', 'ean-isbn-barcodes' ) ),
			array( 'number', 'yes | no', __( 'Show digits below the bars.', 'ean-isbn-barcodes' ) ),
			array( 'isbn_text', 'yes | no', __( 'Show “ISBN …”, “ISSN …” or “ISMN …” above the bars.', 'ean-isbn-barcodes' ) ),
			array( 'name', 'yes | no', __( 'Show the product name from the data sources.', 'ean-isbn-barcodes' ) ),
			array( 'link', 'yes | no', __( 'Link to a product page (see “Link target” in the settings).', 'ean-isbn-barcodes' ) ),
			array( 'url', 'https://…', __( 'Own link target for this barcode, e.g. your product page.', 'ean-isbn-barcodes' ) ),
			array( 'batch / serial', 'L2026-11', __( 'QR code only: batch/lot and serial number (max. 20 characters each).', 'ean-isbn-barcodes' ) ),
			array( 'size', '1 – 20', __( 'QR code only: size of one square in px.', 'ean-isbn-barcodes' ) ),
			array( 'ecc', 'L | M | Q | H', __( 'QR code only: error correction – higher survives more damage, but makes the code denser.', 'ean-isbn-barcodes' ) ),
			array( 'align', 'left | center | right', __( 'Alignment.', 'ean-isbn-barcodes' ) ),
			array( 'style', 'plain | card', __( 'Look.', 'ean-isbn-barcodes' ) ),
			array( 'color', '#111111', __( 'Bar color.', 'ean-isbn-barcodes' ) ),
			array( 'background', '#ffffff | none', __( 'Background color or transparent.', 'ean-isbn-barcodes' ) ),
			array( 'source', 'local | api', __( 'Render locally (SVG) or fetch a PNG from ean-search.org.', 'ean-isbn-barcodes' ) ),
			array( 'output', 'inline | img', __( 'Embed as inline SVG or as image.', 'ean-isbn-barcodes' ) ),
			array( 'title', __( 'any text', 'ean-isbn-barcodes' ), __( 'Own product name. Replaces the name from the data sources; no request is needed for it.', 'ean-isbn-barcodes' ) ),
			array( 'caption', __( 'any text', 'ean-isbn-barcodes' ), __( 'Caption below the barcode.', 'ean-isbn-barcodes' ) ),
			array( 'field', 'my_ean', __( 'Read the EAN from this custom field.', 'ean-isbn-barcodes' ) ),
			array( 'id', '123', __( 'Post ID to read the custom field from (default: current post).', 'ean-isbn-barcodes' ) ),
			array( 'class', 'my-class', __( 'Additional CSS class.', 'ean-isbn-barcodes' ) ),
			array( 'fallback', __( 'any text', 'ean-isbn-barcodes' ), __( 'Text if no product data is found ([ean_name], [ean_product], [isbn_name], [isbn_product]).', 'ean-isbn-barcodes' ) ),
		);
		echo '<div class="eanbc-table-wrap"><table class="eanbc-table"><thead><tr><th>' . esc_html__( 'Attribute', 'ean-isbn-barcodes' ) . '</th><th>' . esc_html__( 'Values', 'ean-isbn-barcodes' ) . '</th><th>' . esc_html__( 'Description', 'ean-isbn-barcodes' ) . '</th></tr></thead><tbody>';
		foreach ( $attrs as $a ) {
			printf( '<tr><td><code>%s</code></td><td><code>%s</code></td><td>%s</td></tr>', esc_html( $a[0] ), esc_html( $a[1] ), esc_html( $a[2] ) );
		}
		echo '</tbody></table></div>';
		self::card_end();
		self::render_about();
	}

	/**
	 * Small line at the end of the help tab: version, feedback links, privacy note.
	 */
	private static function render_about() {
		$locale      = determine_locale();
		$translation = EANBC_REPO_URL . '/issues/new?title=' . rawurlencode( 'Translation (' . $locale . '): ' );
		$links       = array(
			/* translators: %s: plugin version */
			'<span>' . esc_html( sprintf( __( 'Version %s', 'ean-isbn-barcodes' ), EANBC_VERSION ) ) . '</span>',
			sprintf( '<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( EANBC_REPO_URL . '/issues' ), esc_html__( 'Report a bug or suggest a feature', 'ean-isbn-barcodes' ) ),
		);
		if ( 'en_US' !== $locale ) {
			$links[] = sprintf( '<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( $translation ), esc_html__( 'Improve the translation', 'ean-isbn-barcodes' ) );
		}
		echo '<footer class="eanbc-about">';
		echo '<p class="eanbc-about__links">' . implode( '<span class="eanbc-about__sep" aria-hidden="true">·</span>', $links ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		echo '<p class="eanbc-about__privacy"><span class="dashicons dashicons-shield" aria-hidden="true"></span><span>';
		esc_html_e( 'Privacy: barcodes are created on this server. Product data is only requested from the data sources that are switched on – the requests contain the product code, never data about visitors.', 'ean-isbn-barcodes' );
		if ( current_user_can( 'manage_privacy_options' ) ) {
			printf( ' <a href="%s">%s</a>', esc_url( admin_url( 'options-privacy.php?tab=policyguide' ) ), esc_html__( 'Text for the privacy policy', 'ean-isbn-barcodes' ) );
		}
		echo '</span></p></footer>';
	}
}
