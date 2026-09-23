<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

function cbio_enqueue_admin_assets( $hook ) {
	if ( ! defined( 'CBIO_PLUGIN_FILE' ) ) {
		return;
	}

	$allowed_pages = array(
		'coding-bunny-image-optimizer',
		'coding-bunny-image-watermark',
		'coding-bunny-image-cleaner',
	);

	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! in_array( $page, $allowed_pages, true ) ) {
		return;
	}

	$plugin_root = dirname( __DIR__ );
	$assets_dir  = $plugin_root . '/assets';
	$assets_url  = plugin_dir_url( CBIO_PLUGIN_FILE ) . 'assets';

	$load = static function( $type, $handle, $relative, $deps = array(), $in_footer = true ) use ( $assets_dir, $assets_url ) {
		$file = $assets_dir . '/' . $relative;
		if ( ! file_exists( $file ) ) {
			return;
		}
		$version = (string) filemtime( $file );
		$url     = $assets_url . '/' . $relative;
		if ( 'style' === $type ) {
			wp_enqueue_style( $handle, $url, $deps, $version );
		} elseif ( 'script' === $type ) {
			wp_enqueue_script( $handle, $url, $deps, $version, $in_footer );
		}
	};

	$load( 'style',  'coding-bunny-admin-styles',     'css/cbio-styles.css' );
	$load( 'script', 'coding-bunny-admin-script',     'js/cbio-scripts.js', array( 'jquery' ) );
}
add_action( 'admin_enqueue_scripts', 'cbio_enqueue_admin_assets' );