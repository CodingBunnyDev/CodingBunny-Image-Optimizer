<?php

if (!defined('ABSPATH')) {
    exit();
}

require_once plugin_dir_path(__DIR__) . '/includes/image-exif.php';

function cbio_convert_images($file_path, $output_path, $format)
{
    try {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return false;
        }

        $quality_webp = (int) get_option('cbio_quality_webp', 80);
        $quality_avif = (int) get_option('cbio_quality_avif', 80);

        $image_info = getimagesize($file_path);

        $quality = ($format === 'webp' ? $quality_webp : $quality_avif);

        if (extension_loaded('imagick')) {
            $imagick = new Imagick($file_path);

            $profiles = $imagick->getImageProfiles('icc', true);

            if (!empty($profiles) || $imagick->getImageColorspace() !== Imagick::COLORSPACE_SRGB) {
                $imagick->transformImageColorspace(Imagick::COLORSPACE_SRGB);
            }

            if ($format === 'avif') {
                $formats = Imagick::queryFormats('AVIF');
                if (empty($formats)) {
                    $imagick->clear();
                    $imagick->destroy();
                } else {
                    $imagick->setImageCompressionQuality($quality);
                    $imagick->setImageFormat('avif');
                    $result = $imagick->writeImage($output_path);
                    $imagick->clear();
                    $imagick->destroy();

                    if ($result && get_option('cbio_remove_metadata', '0') === '1') {
                        cbio_remove_image_metadata($output_path);
                    }

                    return $result;
                }
            } else {
                $imagick->setOption('webp:method', '6');
                $imagick->setOption('webp:low-memory', 'true');
                $imagick->setOption('webp:alpha-compression', '1');
                $imagick->setImageCompressionQuality($quality);
                $imagick->setImageFormat('webp');
                $result = $imagick->writeImage($output_path);
                $imagick->clear();
                $imagick->destroy();

                if ($result && get_option('cbio_remove_metadata', '0') === '1') {
                    cbio_remove_image_metadata($output_path);
                }

                return $result;
            }
        }

        if (extension_loaded('gd')) {
            $image = null;
            switch ($image_info[2]) {
                case IMAGETYPE_JPEG:
                    $image = imagecreatefromjpeg($file_path);
                    break;
                case IMAGETYPE_PNG:
                    $image = imagecreatefrompng($file_path);
                    break;
                case IMAGETYPE_GIF:
                    $image = imagecreatefromgif($file_path);
                    break;
                default:
                    return false;
            }

            if (!$image) {
                return false;
            }

            $result = false;
            if ($format === 'webp' && function_exists('imagewebp')) {
                $result = imagewebp($image, $output_path, $quality);
            } elseif ($format === 'avif' && function_exists('imageavif')) {
                $quality = min($quality, 63);
                $result = imageavif($image, $output_path, $quality);
            }

            imagedestroy($image);

            if ($result && get_option('cbio_remove_metadata', '0') === '1') {
                cbio_remove_image_metadata($output_path);
            }

            return $result;
        }

        return false;
    } catch (Exception $e) {
        error_log('CBIO cbio_convert_images exception: ' . $e->getMessage());
        return false;
    }
}

function cbio_convert_image($upload)
{
    $enable_conversion = get_option('cbio_enable_conversion', '1');
    if ($enable_conversion !== '1') {
        return $upload;
    }

    $convert_method = get_option('cbio_convert_method', 'server');
    if ($convert_method === 'browser') {
        return $upload;
    }

    $supported_types = ['image/jpeg', 'image/png', 'image/gif', 'image/heic', 'image/webp', 'image/avif'];
    if (!isset($upload['type']) || !in_array($upload['type'], $supported_types)) {
        return $upload;
    }

    $attachment_id = attachment_url_to_postid($upload['url']);
    if ($attachment_id && get_post_meta($attachment_id, '_cbio_optimized', true) === 'yes') {
        return $upload;
    }

    $original_upload = $upload;

    add_filter(
        'wp_generate_attachment_metadata',
        function ($metadata, $att_id) use ($original_upload) {
            static $done = [];
            if (isset($done[$att_id])) {
                return $metadata;
            }
            $done[$att_id] = true;

            $convert_format = get_option('cbio_convert_format', 'webp');
            $delete_original = get_option('cbio_delete_original', '0');

            $file_path = $original_upload['file'];
            $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));

            if ($extension === $convert_format && !in_array($extension, ['webp', 'avif'])) {
                update_post_meta($att_id, '_cbio_optimized', 'yes');
                return $metadata;
            }

            $dirname = pathinfo($file_path, PATHINFO_DIRNAME);
            $filename = pathinfo($file_path, PATHINFO_FILENAME);

            $unique_filename = wp_unique_filename($dirname, $filename . '.' . $convert_format);
            $output_path = $dirname . '/' . $unique_filename;
            $result = cbio_convert_images($file_path, $output_path, $convert_format);

            if ($result && file_exists($output_path) && filesize($output_path) > 0) {
                if ($delete_original === '1') {
                    wp_delete_file($file_path);
                }

                update_attached_file($att_id, $output_path);
                update_post_meta($att_id, '_cbio_optimized', 'yes');

                wp_update_post([
                    'ID' => $att_id,
                    'post_mime_type' => 'image/' . $convert_format,
                ]);

                $metadata['file'] = str_replace(basename($file_path), basename($output_path), $metadata['file']);

                if (!empty($metadata['sizes'])) {
                    foreach ($metadata['sizes'] as $size => &$size_data) {
                        $thumb_path = $dirname . '/' . $size_data['file'];
                        $thumb_name = pathinfo($thumb_path, PATHINFO_FILENAME);
                        $new_thumb = $dirname . '/' . $thumb_name . '.' . $convert_format;

                        $thumb_result = cbio_convert_images($thumb_path, $new_thumb, $convert_format);
                        if ($thumb_result && file_exists($new_thumb) && filesize($new_thumb) > 0) {
                            if ($delete_original === '1') {
                                wp_delete_file($thumb_path);
                            }
                            $size_data['file'] = basename($new_thumb);
                            $size_data['mime-type'] = 'image/' . $convert_format;
                        }
                    }
                    unset($size_data);
                }
            }

            return $metadata;
        },
        10,
        2
    );

    return $upload;
}
add_filter('wp_handle_upload', 'cbio_convert_image');

add_action('wp_ajax_get_all_image_ids', function () {
    check_ajax_referer('convert_webp_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => __('Unauthorized', 'coding-bunny-image-optimizer')]);
    }

    $args = [
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'post_mime_type' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
    ];
    $ids = get_posts($args);

    wp_send_json_success(is_array($ids) ? $ids : []);
});

add_action('add_attachment', function ($post_ID) {
    if (get_post_type($post_ID) !== 'attachment') {
        return;
    }

    $file = get_attached_file($post_ID);
    if (!$file || !file_exists($file)) {
        return;
    }

    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (in_array($extension, ['webp', 'avif'])) {
        update_post_meta($post_ID, '_cbio_optimized', 'yes');
    }
});

add_filter('manage_upload_columns', function ($columns) {
    $columns['cbio_optimized'] = __('Status', 'coding-bunny-image-optimizer');
    return $columns;
});

add_action(
    'manage_media_custom_column',
    function ($column_name, $post_id) {
        if ($column_name === 'cbio_optimized') {
            $is_optimized = get_post_meta($post_id, '_cbio_optimized', true);
            if ($is_optimized === 'yes') {
                echo '<span style="color:#34A853;">' . esc_html__('Optimized', 'coding-bunny-image-optimizer') . '</span>';
            } else {
                echo '<span style="color:#EA4335;">' . esc_html__('Not Optimized', 'coding-bunny-image-optimizer') . '</span>';
            }
        }
    },
    10,
    2
);

add_filter('manage_upload_sortable_columns', function ($columns) {
    $columns['cbio_optimized'] = 'cbio_optimized';
    return $columns;
});

add_action('pre_get_posts', function ($query) {
    if (!is_admin() || !$query->is_main_query()) {
        return;
    }
    if ($query->get('post_type') === 'attachment' && $query->get('orderby') === 'cbio_optimized') {
        $query->set('meta_key', '_cbio_optimized');
        $query->set('orderby', 'meta_value');
    }
});