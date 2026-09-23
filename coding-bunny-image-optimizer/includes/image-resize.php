<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter('wp_image_editors', function($editors) {
    if (extension_loaded('gd')) {
        if (extension_loaded('imagick')) {
            return ['WP_Image_Editor_GD', 'WP_Image_Editor_Imagick'];
        }
        return ['WP_Image_Editor_GD'];
    } elseif (extension_loaded('imagick')) {
        return ['WP_Image_Editor_Imagick'];
    }
    return $editors;
});

function cbio_handle_upload_resize($file) {
	static $options_cache = null;
	static $image_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' );

	$options_cache = $options_cache ?? cbio_get_resize_options();

	if ( ! cbio_should_resize( $file, $options_cache, $image_extensions ) ) {
		return $file;
	}

	if (isset($file['file'])) {
		$attachment_id = cbio_get_attachment_id_by_path($file['file']);
		if ($attachment_id && get_post_meta($attachment_id, '_cbio_resized', true)) {
			return $file;
		}
	}

	$image_info = cbio_get_image_info( $file );
	if ( ! $image_info ) {
		return $file;
	}

	$new_dimensions = cbio_calculate_new_dimensions( $image_info, $options_cache );
	
	if ($new_dimensions['width'] === $image_info['width'] && $new_dimensions['height'] === $image_info['height']) {
		return $file;
	}

	$resized_file = cbio_resize_and_save_image( $file, $image_info, $new_dimensions );

	if ( $resized_file && isset($file['file']) ) {
		$attachment_id = cbio_get_attachment_id_by_path($file['file']);
		if ($attachment_id) {
			update_post_meta($attachment_id, '_cbio_resized', 1);
		}
	}

	return $resized_file ?? $file;
}
add_filter( 'wp_handle_upload', 'cbio_handle_upload_resize' );

function cbio_get_image_info( $file ) {
	static $file_info_cache = array();

	$file_path = $file['file'];
	$file_hash = md5( $file_path );

	if ( isset( $file_info_cache[ $file_hash ] ) ) {
		return $file_info_cache[ $file_hash ];
	}

	$image_size = @getimagesize( $file_path );
	if ( ! $image_size || ! isset( $image_size[0], $image_size[1] ) ) {
		$file_info_cache[ $file_hash ] = false;
		return false;
	}

	$file_info_cache[ $file_hash ] = array(
		'width'  => (int) $image_size[0],
		'height' => (int) $image_size[1],
		'mime'   => $image_size['mime'] ?? '',
	);

	if ( count( $file_info_cache ) > 500 ) {
		array_shift( $file_info_cache );
	}

	return $file_info_cache[ $file_hash ];
}

function cbio_image_within_limits( $image_info, $options_cache, $tolerance = 5 ) {
	return $image_info['width'] <= ($options_cache['max_width'] + $tolerance) && 
	       $image_info['height'] <= ($options_cache['max_height'] + $tolerance);
}

function cbio_get_resize_options() {
	static $cached_options = null;
	
	if ( null !== $cached_options ) {
		return $cached_options;
	}
	
	$cached_options = array(
		'enable_resize' => get_option( 'cbio_enable_resize', '1' ),
		'max_width'     => max( 100, (int) get_option( 'cbio_max_width', 1000 ) ),
		'max_height'    => max( 100, (int) get_option( 'cbio_max_height', 1000 ) ),
	);
	
	return apply_filters( 'cbio_resize_options_cache', $cached_options );
}

function cbio_should_resize( $file, $options_cache, $image_extensions ) {
	if ( $options_cache['enable_resize'] !== '1' ) {
		return false;
	}
	
	if ( ! isset( $file['file'] ) || ! file_exists( $file['file'] ) ) {
		return false;
	}
	
	$file_ext = strtolower( pathinfo( $file['file'], PATHINFO_EXTENSION ) );
	return in_array( $file_ext, $image_extensions, true );
}

function cbio_resize_and_save_image( $file, $image_info, $new_dimensions ) {
	$file_path = $file['file'];

	$image_editor = wp_get_image_editor( $file_path );
	if ( is_wp_error( $image_editor ) ) {
		return false;
	}

	$result = $image_editor->resize( $new_dimensions['width'], $new_dimensions['height'], false );
	if ( is_wp_error( $result ) ) {
		return false;
	}

	$saved = $image_editor->save( $file_path );
	if ( is_wp_error( $saved ) ) {
		return false;
	}

	clearstatcache( true, $file_path );
	$file['size'] = filesize( $file_path );
	
	if ( isset( $saved['mime-type'] ) ) {
		$file['type'] = $saved['mime-type'];
	}

	return $file;
}

function cbio_calculate_new_dimensions( $image_info, $options_cache ) {
	$original_width = $image_info['width'];
	$original_height = $image_info['height'];
	
	if ( $original_width <= $options_cache['max_width'] && $original_height <= $options_cache['max_height'] ) {
		return array(
			'width'  => $original_width,
			'height' => $original_height,
		);
	}
	
	$ratio = $original_width / $original_height;

	$new_width = min( $original_width, $options_cache['max_width'] );
	$new_height = $new_width / $ratio;

	if ( $new_height > $options_cache['max_height'] ) {
		$new_height = $options_cache['max_height'];
		$new_width = $new_height * $ratio;
	}

	return array(
		'width'  => max( 1, (int) round( $new_width ) ),
		'height' => max( 1, (int) round( $new_height ) ),
	);
}

function cbio_get_attachment_id_by_path($file_path) {
    $upload_dir = wp_get_upload_dir();
    $meta_value = ltrim( str_replace( $upload_dir['basedir'], '', $file_path ), '/' );
    $cache_key = 'cbio_att_id_' . md5( $meta_value );

    $attachment_id = wp_cache_get( $cache_key, 'cbio_image_optimizer' );
    if ( false !== $attachment_id ) {
        return intval( $attachment_id );
    }

    $attachment_id = attachment_url_to_postid( $file_path );
    
    if ( ! $attachment_id ) {
        $args = array(
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'fields'         => 'ids',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_key'       => '_wp_attached_file',
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            'meta_value'     => $meta_value,
            'no_found_rows'  => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        );

        $query = new WP_Query( $args );
        $attachment_id = ! empty( $query->posts ) ? intval( $query->posts[0] ) : 0;
    }

    wp_cache_set( $cache_key, $attachment_id, 'cbio_image_optimizer', 3600 );

    return intval( $attachment_id );
}

function cbio_disable_big_image_threshold( $threshold ) {
    static $disable_threshold = null;
    
    if ( null === $disable_threshold ) {
        $disable_threshold = get_option( 'cbio_disable_big_image_threshold', '0' );
    }
    
    if ( $disable_threshold === '1' ) {
        return false;
    }
    
    return $threshold;
}
add_filter( 'big_image_size_threshold', 'cbio_disable_big_image_threshold' );