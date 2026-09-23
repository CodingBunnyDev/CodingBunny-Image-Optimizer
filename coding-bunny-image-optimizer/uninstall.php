<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'cbio_convert_format' );
delete_option( 'cbio_enable_conversion' );
delete_option( 'cbio_quality_webp' );
delete_option( 'cbio_quality_avif' );
delete_option( 'cbio_delete_original' );
delete_option( 'cbio_max_width' );
delete_option( 'cbio_max_height' );
delete_option( 'cbio_enable_resize' );
delete_option( 'cbio_disable_big_image_threshold' );
delete_option( 'cbio_enabled_image_sizes' );
delete_option( 'cbio_show_bulk_options' );
delete_option( 'cbio_batch_size' );
delete_option( 'cbio_enable_auto_alt_text' );
delete_option( 'cbio_remove_metadata' );
delete_option( 'cbio_enable_lazyload' );
delete_option( 'cbio_disable_unused_images' );
delete_option( 'cbio_delete_product_images' );
delete_option( 'watermark_options' );
delete_option( 'cbio_licence_data' );
delete_option( 'cbio_enable_log' );

delete_option( 'cbio_show_bulk_toolbar' ); // legacy

delete_transient( 'cbio_image_stats' );
delete_transient( 'cbio_plugin_update_info' );

?>