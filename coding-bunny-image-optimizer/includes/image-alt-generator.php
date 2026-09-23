<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function cbio_get_first_post_title_for_attachment($attachment_id, $deep_scan = false) {
    $cache_key = 'cbio_post_title_' . $attachment_id;
    $post_title = wp_cache_get($cache_key, 'cbio_alt_text');
    if (false === $post_title) {
        $posts = get_posts(array(
            'post_type' => 'any',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_key' => '_thumbnail_id',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            'meta_value' => $attachment_id,
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false
        ));
        if (!empty($posts)) {
            $post = get_post($posts[0]);
            $post_title = $post ? $post->post_title : '';
            wp_cache_set($cache_key, $post_title, 'cbio_alt_text', HOUR_IN_SECONDS);
            return $post_title;
        }

        if ($deep_scan) {
            $file = get_post_meta($attachment_id, '_wp_attached_file', true);
            $filename = $file ? wp_basename($file) : '';
            if ($filename && strlen($filename) > 6) {
                $posts = get_posts(array(
                    'post_type' => 'any',
                    'post_status' => 'publish',
                    'posts_per_page' => 1,
                    's' => $filename,
                    'fields' => 'ids',
                    'no_found_rows' => true,
                    'update_post_meta_cache' => false,
                    'update_post_term_cache' => false
                ));
                if (!empty($posts)) {
                    $post = get_post($posts[0]);
                    $post_title = $post ? $post->post_title : '';
                    wp_cache_set($cache_key, $post_title, 'cbio_alt_text', HOUR_IN_SECONDS);
                    return $post_title;
                }
            }
        }

        $attachment = get_post($attachment_id);
        if ($attachment && $attachment->post_parent) {
            $parent = get_post($attachment->post_parent);
            if ($parent && 'publish' === $parent->post_status) {
                $post_title = $parent->post_title;
                wp_cache_set($cache_key, $post_title, 'cbio_alt_text', HOUR_IN_SECONDS);
                return $post_title;
            }
        }
        wp_cache_set($cache_key, '', 'cbio_alt_text', HOUR_IN_SECONDS);
    }
    return $post_title ? $post_title : '';
}

function cbio_generate_alt_from_filename( $filename ) {
    $filename_no_ext = preg_replace( '/\\.[^.]+$/', '', $filename );
    $filename_clean = str_replace( array( '-', '_' ), ' ', $filename_no_ext );
    $filename_clean = preg_replace( '/\s*\d+\s*/', ' ', $filename_clean );
    $filename_clean = preg_replace( '/\b(img|pic|thumb)\b/i', '', $filename_clean );
    $filename_clean = trim( preg_replace( '/\s+/', ' ', $filename_clean ) );
    $filename_clean = ucwords( strtolower( $filename_clean ) );
    return ! empty( $filename_clean ) ? $filename_clean : __( 'Image', 'coding-bunny-image-optimizer' );
}

function cbio_get_sku_for_attachment( $attachment_id ) {
    $sku = '';
    $attachment = get_post( $attachment_id );
    if ( ! $attachment ) return $sku;
    $parent_id = $attachment->post_parent;
    if ( ! $parent_id ) return $sku;
    if ( ! function_exists( 'wc_get_product' ) ) return $sku;
    $product = wc_get_product( $parent_id );
    if ( $product ) $sku = $product->get_sku();
    return $sku;
}

function cbio_generate_alt_text_with_template( $attachment_id, $deep_scan = false ) {
    $template = get_option( 'cbio_alt_text_template', '{post_title}' );
    $attachment = get_post( $attachment_id );
    $file = get_post_meta( $attachment_id, '_wp_attached_file', true );
    $filename = $file ? wp_basename( $file ) : '';
    $post_title = cbio_get_first_post_title_for_attachment( $attachment_id, $deep_scan );
    $title = $attachment ? $attachment->post_title : '';
    if ( $title ) {
        $title = str_replace( array( '-', '_' ), ' ', $title );
        $title = ucwords( strtolower( $title ) );
    }
    $caption = $attachment ? $attachment->post_excerpt : '';
    $site_title = get_bloginfo( 'name' );
    $filename_clean = cbio_generate_alt_from_filename( $filename );
    $sku = cbio_get_sku_for_attachment( $attachment_id );

    $replacements = array(
        '{post_title}'  => $post_title,
        '{title}'       => $title,
        '{caption}'     => $caption,
        '{filename}'    => $filename_clean,
        '{site_title}'  => $site_title,
        '{sku}'         => $sku,
    );
    $alt_text = $template;
    foreach ( $replacements as $tag => $value ) {
        $alt_text = str_replace( $tag, $value, $alt_text );
    }
    $alt_text = trim( $alt_text );
    if ( empty( $alt_text ) ) $alt_text = $filename_clean;
    return esc_html( $alt_text );
}

add_action( 'wp_ajax_cbio_generate_bulk_alt_text', 'cbio_generate_bulk_alt_text_callback_batch' );
function cbio_generate_bulk_alt_text_callback_batch() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied', 'coding-bunny-image-optimizer' ) ) );
    }

    $force_overwrite = '1' === get_option( 'cbio_force_overwrite_alt_text', '0' );
    $batch_size = 50;

    $progress_key = 'cbio_bulk_alt_progress_' . get_current_user_id();
    $progress = get_transient($progress_key);

    if ( ! is_array($progress) || !isset($progress['batch_index']) ) {
        // Calculate (and cache) total images just once at the beginning
        $count_query = new WP_Query(array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'post_mime_type' => 'image',
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'no_found_rows'  => false,
        ));
        $total_images = $count_query->found_posts;
        $progress = array(
            'batch_index' => 0,
            'updated'     => 0,
            'total'       => $total_images,
        );
    }

    $batch_index = intval($progress['batch_index']);
    $updated = intval($progress['updated']);
    $total_images = intval($progress['total']);

    $args = array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'post_mime_type' => 'image',
        'fields'         => 'ids',
        'posts_per_page' => $batch_size,
        'offset'         => $batch_index * $batch_size,
        'no_found_rows'  => true,
    );
    $attachments = get_posts( $args );

    foreach ( $attachments as $attachment_id ) {
        $alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
        if ( $force_overwrite || empty( $alt ) ) {
            $auto_alt = cbio_generate_alt_text_with_template( $attachment_id, false );
            if ( ! empty( $auto_alt ) ) {
                update_post_meta( $attachment_id, '_wp_attachment_image_alt', $auto_alt );
                $updated++;
            }
        }
    }

    $progress['batch_index']++;
    $progress['updated'] = $updated;

    $done = false;
    if ( count($attachments) < $batch_size || ( $progress['batch_index'] * $batch_size ) >= $total_images ) {
        $done = true;
        delete_transient($progress_key);
    } else {
        set_transient($progress_key, $progress, 30 * MINUTE_IN_SECONDS);
    }

    wp_send_json_success( array(
        'updated'    => $updated,
        'progress'   => $total_images ? min( 100, intval( round( ( ( $progress['batch_index'] * $batch_size ) / $total_images ) * 100 ) ) ) : 100,
        'completed'  => $done,
        'batch_total'=> $total_images
    ));
}

add_action( 'wp_ajax_cbio_generate_alt_text_single', 'cbio_generate_alt_text_single_callback' );
function cbio_generate_alt_text_single_callback() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied', 'coding-bunny-image-optimizer' ) ) );
    }
    if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'cbio_generate_alt_text_single' ) ) {
        wp_send_json_error( array( 'message' => __( 'Invalid nonce', 'coding-bunny-image-optimizer' ) ) );
    }
    $attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
    if ( ! $attachment_id ) {
        wp_send_json_error( array( 'message' => __( 'No attachment ID', 'coding-bunny-image-optimizer' ) ) );
    }
    $auto_alt = cbio_generate_alt_text_with_template( $attachment_id, true );
    update_post_meta( $attachment_id, '_wp_attachment_image_alt', $auto_alt );
    wp_cache_delete( 'cbio_post_title_' . $attachment_id, 'cbio_alt_text' );
    wp_send_json_success( array( 'alt' => $auto_alt ) );
}

add_action( 'add_attachment', 'cbio_generate_auto_alt_text_for_image' );
function cbio_generate_auto_alt_text_for_image( $post_ID ) {
    if ( defined('WP_CLI') && WP_CLI ) return;
    if ( function_exists('wp_doing_cron') && wp_doing_cron() ) return;

    $enable_auto_alt_text = get_option( 'cbio_enable_auto_alt_text', '0' );
    if ( '1' !== $enable_auto_alt_text ) return;
    $force_overwrite = '1' === get_option( 'cbio_force_overwrite_alt_text', '0' );
    $attachment = get_post( $post_ID );
    if ( ! $attachment || 'attachment' !== $attachment->post_type ) return;
    $mimetype = get_post_mime_type( $post_ID );
    if ( 0 !== strpos( $mimetype, 'image/' ) ) return;
    $alt = get_post_meta( $post_ID, '_wp_attachment_image_alt', true );
    if ( ! empty( $alt ) && ! $force_overwrite ) return;
    // Allow deep scan on upload (single)
    $auto_alt = cbio_generate_alt_text_with_template( $post_ID, true );
    update_post_meta( $post_ID, '_wp_attachment_image_alt', $auto_alt );
    wp_cache_delete( 'cbio_post_title_' . $post_ID, 'cbio_alt_text' );
}

add_filter( 'manage_upload_columns', 'cbio_add_alt_text_column' );
function cbio_add_alt_text_column( $columns ) {
    $columns['cbio_alt_text'] = __( 'Alt Text', 'coding-bunny-image-optimizer' );
    return $columns;
}

add_action( 'manage_media_custom_column', 'cbio_display_alt_text_column', 10, 2 );
function cbio_display_alt_text_column( $column_name, $post_id ) {
    if ( 'cbio_alt_text' === $column_name ) {
        $alt = get_post_meta( $post_id, '_wp_attachment_image_alt', true );
        if ( ! $alt ) {
            $alt = '<em>' . __( '(not set)', 'coding-bunny-image-optimizer' ) . '</em>';
        }
        echo '<button class="cbio-generate-alt-btn button-primary" type="button" data-attachment-id="' . esc_attr( $post_id ) . '" style="margin-bottom:4px;">'
            . esc_html__( 'Generate ALT TEXT', 'coding-bunny-image-optimizer' ) . '</button><br/>';
        echo '<span class="cbio-alt-text-display" id="cbio-alt-text-' . esc_attr( $post_id ) . '">' . wp_kses_post( $alt ) . '</span>';
    }
}

add_action( 'admin_enqueue_scripts', 'cbio_enqueue_alt_text_generator_script' );
function cbio_enqueue_alt_text_generator_script( $hook ) {
    if ( 'upload.php' !== $hook && false === strpos($hook, 'image-optimizer') ) return;
    $js_path = plugin_dir_url( dirname( __FILE__ ) ) . 'assets/js/image-alt-generator.js';
    wp_enqueue_script( 'image-alt-generator', $js_path, array(), '1.0.1', true );
    wp_localize_script(
        'image-alt-generator',
        'cbioAltColumn',
        array(
            'nonce'    => wp_create_nonce( 'cbio_generate_alt_text_single' ),
            'ajax_url' => admin_url( 'admin-ajax.php' ),
        )
    );
    wp_localize_script(
        'image-alt-generator',
        'cbioAltTextAjax',
        array(
            'nonce' => wp_create_nonce( 'cbio_generate_bulk_alt_text' ),
            'i18n' => array(
                'confirm'    => __('Generate alt text for all images that do not have it?', 'coding-bunny-image-optimizer'),
                'generating' => __('Generating...', 'coding-bunny-image-optimizer'),
                'generated'  => __('Alt text generated for', 'coding-bunny-image-optimizer'),
                'images'     => __('images.', 'coding-bunny-image-optimizer'),
                'error'      => __('Error generating alt text.', 'coding-bunny-image-optimizer')
            )
        )
    );
}