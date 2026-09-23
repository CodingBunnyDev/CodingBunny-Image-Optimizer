<?php

/**
 * Plugin Name: CodingBunny Image Optimizer
 * Description: Speed up your site! Compress and optimize images automatically.
 * Version:     3.1.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author:      CodingBunny
 * Text Domain: coding-bunny-image-optimizer
 * Domain Path: /languages
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CBIO_VERSION', '3.1.0' );
define( 'CBIO_PLUGIN_FILE', __FILE__ );

class CodingBunnyImageOptimizer {

    private $admin_dir;

    public function __construct() {
        $this->admin_dir = plugin_dir_path( __FILE__ ) . 'admin/';
        $this->load_dependencies();
        $this->register_hooks();
    }

    private function load_dependencies() {
        $files_to_include = [
            'admin-menu.php',
            'image-optimizer.php',
            'bulk-edit.php',
            'image-watermark.php',
            'enqueue-scripts.php',
            'script-loader.php',
        ];

        foreach ( $files_to_include as $file ) {
            $file_path = $this->admin_dir . $file;
            if ( file_exists( $file_path ) ) {
                require_once $file_path;
            }
        }
    }

    private function register_hooks() {
        add_action( 'plugins_loaded', [ $this, 'load_textdomain' ] );
        add_action( 'admin_init', [ $this, 'remove_legacy_data' ] );
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ $this, 'add_action_links' ] );
    }

    public function load_textdomain() {
		// phpcs:ignore
        load_plugin_textdomain( 'coding-bunny-image-optimizer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
    }

    public function remove_legacy_data() {
        if ( '1' === get_option( 'cbio_legacy_data_removed', '0' ) ) {
            return;
        }

        wp_clear_scheduled_hook( 'cbio_check_license' );

        $data = get_option( 'cbio_licence_data', [] );
        if ( is_array( $data ) && ! empty( $data['key'] ) ) {
            $key    = (string) $data['key'];
            $email  = isset( $data['email'] ) ? (string) $data['email'] : '';
            $domain = (string) wp_parse_url( get_site_url(), PHP_URL_HOST );
            delete_transient( 'cbio_licence_validation_' . md5( $key . $email ) );
            delete_transient( 'cbio_domain_status_' . md5( $key . $domain ) );
        }

        delete_option( 'cbio_licence_data' );
        delete_transient( 'cbio_deactivated_addons_checked' );

        delete_option( 'cbio_image_stats_persistent' );
        delete_option( 'cbio_image_stats_persistent_light' );
        delete_option( 'cbio_total_images_persistent' );
        delete_option( 'cbio_stats_last_updated' );
        delete_transient( 'cbio_image_stats_full' );
        delete_transient( 'cbio_image_stats_light' );

        update_option( 'cbio_legacy_data_removed', '1', false );
    }

    public function add_action_links( $links ) {
        if ( is_array( $links ) ) {
            $settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=coding-bunny-image-optimizer' ) ) . '">' . esc_html__( 'Settings', 'coding-bunny-image-optimizer' ) . '</a>';
            array_unshift( $links, $settings_link );
        }
        return $links;
    }
}

$cbio_coding_bunny_image_optimizer = new CodingBunnyImageOptimizer();
