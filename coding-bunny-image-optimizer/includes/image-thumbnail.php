<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function cbio_disable_intermediate_image_sizes($sizes) {
    $enabled_image_sizes = get_option('cbio_enabled_image_sizes', false);
    if (!is_array($enabled_image_sizes)) {
        return $sizes;
    }

    if (empty($enabled_image_sizes)) {
        return [];
    }

    return array_intersect_key(
        $sizes,
        array_flip($enabled_image_sizes)
    );
}
add_filter('intermediate_image_sizes_advanced', 'cbio_disable_intermediate_image_sizes', 99);

function cbio_image_sizes() {
    $sizes = [];
    $additional_sizes = wp_get_additional_image_sizes();
    $image_sizes = get_intermediate_image_sizes();

    foreach ( $image_sizes as $size ) {
        if ( isset( $additional_sizes[ $size ] ) ) {
            $sizes[ $size ] = [
                'width'  => $additional_sizes[ $size ]['width'],
                'height' => $additional_sizes[ $size ]['height'],
                'crop'   => isset($additional_sizes[ $size ]['crop']) ? $additional_sizes[ $size ]['crop'] : false,
            ];
        } else {
            $sizes[ $size ] = [
                'width'  => (int) get_option( $size . '_size_w', 0 ),
                'height' => (int) get_option( $size . '_size_h', 0 ),
                'crop'   => (bool) get_option( $size . '_crop', false ),
            ];
        }
    }
    return $sizes;
}