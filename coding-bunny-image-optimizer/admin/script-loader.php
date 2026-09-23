<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( get_option( 'cbio_convert_method', 'server' ) !== 'browser' ) {
	return;
}

// Gutenberg
add_action( 'enqueue_block_editor_assets', 'cbio_enqueue_browser_convert_script' );
add_action( 'enqueue_block_assets', 'cbio_enqueue_browser_convert_script' );

// Elementor
add_action( 'elementor/editor/before_enqueue_scripts', 'cbio_enqueue_browser_convert_script' );
add_action( 'elementor/preview/enqueue_styles', 'cbio_enqueue_browser_convert_script' );
add_action( 'elementor/frontend/after_enqueue_styles', 'cbio_enqueue_browser_convert_script' );

// Oxygen Builder
add_action( 'oxygen_enqueue_ui_scripts', 'cbio_enqueue_browser_convert_script' );

// Media Library
add_action( 'admin_enqueue_scripts', 'cbio_enqueue_browser_convert_admin' );

// Bricks Builder
add_action( 'bricks/setup', 'cbio_enqueue_browser_convert_bricks' );
add_action( 'bricks/builder/enqueue_scripts', 'cbio_enqueue_browser_convert_script' );

// Beaver Builder
add_action( 'fl_builder_ui_enqueue_scripts', 'cbio_enqueue_browser_convert_script' );

// Divi Builder
add_action( 'et_builder_ready', 'cbio_enqueue_browser_convert_script' );

// WPBakery
add_action( 'vc_backend_editor_enqueue_js_css', 'cbio_enqueue_browser_convert_script' );
add_action( 'vc_frontend_editor_enqueue_js_css', 'cbio_enqueue_browser_convert_script' );

// Thrive Architect
add_action( 'tve_editor_enqueue_scripts', 'cbio_enqueue_browser_convert_script' );

// Cornerstone (X Theme)
add_action( 'cornerstone_enqueue_custom_admin_scripts', 'cbio_enqueue_browser_convert_script' );

// Fusion Builder (Avada)
add_action( 'fusion_builder_enqueue_live_scripts', 'cbio_enqueue_browser_convert_script' );

// Live Composer
add_action( 'dslc_hook_register_modules', 'cbio_enqueue_browser_convert_script' );

// Customizer
add_action( 'customize_controls_enqueue_scripts', 'cbio_enqueue_browser_convert_script' );
add_action( 'wp_enqueue_scripts', 'cbio_enqueue_browser_convert_frontend' );
add_action( 'wp_ajax_upload-attachment', 'cbio_setup_ajax_scripts', 1 );
add_action( 'init', 'cbio_detect_page_builders' );

// Forms
add_action( 'wpcf7_enqueue_scripts', 'cbio_enqueue_for_forms' );
add_action( 'gform_enqueue_scripts', 'cbio_enqueue_for_forms' );
add_action( 'wpforms_wp_footer_end', 'cbio_enqueue_for_forms' );

add_action( 'wp_loaded', 'cbio_fix_js_mime_type' );

function cbio_locate_browser_convert_asset() {
	static $asset = null;

	if ( null !== $asset ) {
		return $asset;
	}

	$plugin_root_dir = trailingslashit( dirname( plugin_dir_path( __FILE__ ) ) );
	$plugin_root_url = trailingslashit( dirname( plugin_dir_url( __FILE__ ) ) );

	$candidates = [
		'assets/js/browser-convert.js',
		'js/browser-convert.js',
	];

	foreach ( $candidates as $rel ) {
		$abs = $plugin_root_dir . ltrim( $rel, '/' );
		if ( file_exists( $abs ) ) {
			$asset = [
				'path' => $abs,
				'url'  => $plugin_root_url . ltrim( $rel, '/' ),
			];
			return $asset;
		}
	}

	$file_dir = trailingslashit( plugin_dir_path( __FILE__ ) );
	$file_url = trailingslashit( plugin_dir_url( __FILE__ ) );

	$fallbacks = [
		'assets/js/browser-convert.js',
		'../assets/js/browser-convert.js',
		'js/browser-convert.js',
		'../js/browser-convert.js',
	];

	foreach ( $fallbacks as $rel ) {
		$abs = $file_dir . ltrim( $rel, '/' );
		if ( file_exists( $abs ) ) {
			$asset = [
				'path' => $abs,
				'url'  => $file_url . ltrim( $rel, '/' ),
			];
			return $asset;
		}
	}

	return null;
}

function cbio_enqueue_browser_convert_script() {
	if ( get_option( 'cbio_convert_method', 'server' ) !== 'browser' ) {
		return;
	}

	if ( wp_script_is( 'cbio-browser-convert', 'enqueued' ) || wp_script_is( 'cbio-browser-convert', 'registered' ) ) {
		return;
	}

	$asset = cbio_locate_browser_convert_asset();
	if ( empty( $asset ) || empty( $asset['path'] ) || empty( $asset['url'] ) ) {
		return;
	}

	$format_opt = get_option( 'cbio_convert_format', 'webp' );
	$format     = in_array( $format_opt, [ 'webp', 'avif' ], true ) ? $format_opt : 'webp';

	$quality_webp = (int) get_option( 'cbio_quality_webp', 80 );
	$quality_avif = (int) get_option( 'cbio_quality_avif', 60 );

	$quality_webp = max( 1, min( 100, $quality_webp ) );
	$quality_avif = max( 1, min( 100, $quality_avif ) );

	$enable_conversion = ( get_option( 'cbio_enable_conversion', '1' ) === '1' );

	$version = (string) @filemtime( $asset['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	if ( '' === $version ) {
		$version = null;
	}

	wp_register_script(
		'cbio-browser-convert',
		$asset['url'],
		[ 'jquery' ],
		$version,
		true
	);

	if ( function_exists( 'wp_set_script_translations' ) ) {
		wp_set_script_translations( 'cbio-browser-convert', 'coding-bunny-image-optimizer' );
	}

	wp_enqueue_script( 'cbio-browser-convert' );

	wp_localize_script(
		'cbio-browser-convert',
		'cbioBrowserConvertData',
		[
			'method'          => 'browser',
			'format'          => $format,
			'quality_webp'    => $quality_webp,
			'quality_avif'    => $quality_avif,
			'enable_conversion' => $enable_conversion,
			'nonce'           => wp_create_nonce( 'cbio_convert_nonce' ),
			'fallback_server' => true,
			'i18n'            => [
				'preparing'   => __( 'Preparing images...', 'coding-bunny-image-optimizer' ),
				'converted'   => __( 'Converted in browser', 'coding-bunny-image-optimizer' ),
				'skipped'     => __( 'Skipped (not convertible)', 'coding-bunny-image-optimizer' ),
				'failed'      => __( 'Browser conversion failed, using original.', 'coding-bunny-image-optimizer' ),
				'unsupported' => __( 'Format not supported in this browser, using original.', 'coding-bunny-image-optimizer' ),
			],
		]
	);
}

function cbio_enqueue_browser_convert_admin( $hook ) {
	$allowed_pages = [
		'upload.php',
		'media-new.php',
		'post.php',
		'post-new.php',
		'edit.php',
		'media.php',
	];

	$hook = (string) $hook;

	$matches =
		in_array( $hook, $allowed_pages, true ) ||
		strpos( $hook, 'page_' ) !== false ||
		strpos( $hook, 'elementor' ) !== false ||
		strpos( $hook, 'oxygen' ) !== false ||
		strpos( $hook, 'bricks' ) !== false;

	if ( $matches ) {
		cbio_enqueue_browser_convert_script();
	}
}

function cbio_enqueue_browser_convert_bricks() {
	if ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() ) {
		cbio_enqueue_browser_convert_script();
		return;
	}

	if ( cbio_request_has_param( 'bricks' ) || cbio_request_has_param( 'bricks-preview' ) ) {
		cbio_enqueue_browser_convert_script();
	}
}

function cbio_enqueue_browser_convert_frontend() {
	$can_upload = is_user_logged_in() && current_user_can( 'upload_files' );

	if ( $can_upload || cbio_is_page_builder_active() ) {
		cbio_enqueue_browser_convert_script();
	}
}

function cbio_is_page_builder_active() {
	return (
		cbio_request_has_param( 'elementor-preview' ) ||
		cbio_request_has_param( 'elementor_library' ) ||
		cbio_request_has_param( 'ct_builder' ) ||
		cbio_request_has_param( 'oxygen_iframe' ) ||
		cbio_request_has_param( 'bricks' ) ||
		cbio_request_has_param( 'bricks-preview' ) ||
		cbio_request_has_param( 'fl_builder' ) ||
		cbio_request_has_param( 'fl_builder_ui' ) ||
		cbio_request_has_param( 'et_fb' ) ||
		cbio_request_has_param( 'et_pb_preview' ) ||
		cbio_request_has_param( 'vc_editable' ) ||
		cbio_request_has_param( 'vc_action' ) ||
		cbio_request_has_param( 'tve' ) ||
		cbio_request_has_param( 'tve_preview' )
	);
}

function cbio_detect_page_builders() {
	if ( cbio_is_page_builder_active() ) {
		add_action( 'wp_enqueue_scripts', 'cbio_enqueue_browser_convert_script', 1 );
		add_action( 'admin_enqueue_scripts', 'cbio_enqueue_browser_convert_script', 1 );
	}
}

function cbio_setup_ajax_scripts() {
	if ( ! check_ajax_referer( 'media-form', '_wpnonce', false ) ) {
		if ( ! check_ajax_referer( 'media-form', false, false ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid nonce.', 'coding-bunny-image-optimizer' ) ], 400 );
		}
	}

	if ( get_option( 'cbio_convert_method', 'server' ) !== 'browser' ) {
		return;
	}

	cbio_enqueue_browser_convert_script();
}

function cbio_enqueue_for_forms() {
	if ( is_admin() || cbio_is_page_builder_active() ) {
		cbio_enqueue_browser_convert_script();
	}
}

function cbio_fix_js_mime_type() {
	if ( headers_sent() ) {
		return;
	}

	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	if ( '' === $request_uri ) {
		return;
	}

	$path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );

	if ( 'browser-convert.js' !== basename( $path ) ) {
		return;
	}

	$asset = cbio_locate_browser_convert_asset();
	if ( empty( $asset ) ) {
		return;
	}

	$asset_path_part = (string) wp_parse_url( $asset['url'], PHP_URL_PATH );
	if ( ( $asset_path_part && str_ends_with( $path, $asset_path_part ) ) || strpos( $path, 'browser-convert.js' ) !== false ) {
		header( 'Content-Type: application/javascript; charset=utf-8' );
	}
}

function cbio_request_has_param( $key ) {
	$key = (string) $key;
	return null !== filter_input( INPUT_GET, $key, FILTER_DEFAULT );
}