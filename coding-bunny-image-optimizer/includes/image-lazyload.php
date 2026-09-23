<?php
if (!defined('ABSPATH')) exit;

function cbio_mark_first_img_above_fold($content) {
    if (strpos($content, '<img') === false) return $content;
    static $processed_for_post = [];
    $post_id = get_the_ID();
    if (isset($processed_for_post[$post_id])) return $content;
    $processed_for_post[$post_id] = true;
    $pattern = '/(<img)(\s+[^>]*?)(>)/i';
    $content = preg_replace_callback(
        $pattern,
        function($matches) {
            static $first = true;
            if (!$first) return $matches[0];
            $first = false;
            $attributes = $matches[2];
            if (preg_match('/class=["\']([^"\']*)["\']/', $attributes, $class_match)) {
                $new_class = trim($class_match[1] . ' cbio-above-fold');
                $attributes = preg_replace(
                    '/class=["\']([^"\']*)["\']/',
                    'class="' . esc_attr($new_class) . '"',
                    $attributes,
                    1
                );
            } else {
                $attributes .= ' class="cbio-above-fold"';
            }
            return $matches[1] . $attributes . $matches[3];
        },
        $content,
        1
    );
    return $content;
}
add_filter('the_content', 'cbio_mark_first_img_above_fold', 7);

function cbio_control_content_lazyload($value, $image, $context) {
    if (get_option('cbio_enable_lazyload', '0') !== '1') return false;
    if ($context === 'the_post_thumbnail') return false;
    if (is_string($image) && strpos($image, 'cbio-above-fold') !== false) return false;
    if (is_string($image) && (strpos($image, 'loading="eager"') !== false || strpos($image, "loading='eager'") !== false)) return false;
    return 'lazy';
}
add_filter('wp_img_tag_add_loading_attr', 'cbio_control_content_lazyload', 10, 3);

function cbio_init_first_thumbnail_flag($query) {
    if ($query->is_main_query() && (is_archive() || is_home())) {
        global $cbio_first_thumbnail_in_loop;
        $cbio_first_thumbnail_in_loop = true;
    }
}
add_action('pre_get_posts', 'cbio_init_first_thumbnail_flag');

function cbio_control_attachment_lazyload($attr, $attachment, $size) {
    global $cbio_first_thumbnail_in_loop;
    if (get_option('cbio_enable_lazyload', '0') !== '1') {
        $attr['loading'] = 'eager';
        return $attr;
    }
    if (isset($attr['class']) && strpos($attr['class'], 'cbio-above-fold') !== false) {
        $attr['loading'] = 'eager';
        return $attr;
    }
    if (is_singular() && in_the_loop() && get_post_thumbnail_id() === $attachment->ID) {
        $attr['loading'] = 'eager';
        return $attr;
    }
    if (!empty($cbio_first_thumbnail_in_loop) && (is_archive() || is_home()) && in_the_loop()) {
        if (get_post_thumbnail_id() === $attachment->ID) {
            $attr['loading'] = 'eager';
            $cbio_first_thumbnail_in_loop = false;
            return $attr;
        }
    }
    if (!isset($attr['loading'])) $attr['loading'] = 'lazy';
    return $attr;
}
add_filter('wp_get_attachment_image_attributes', 'cbio_control_attachment_lazyload', 10, 3);

function cbio_preload_lcp_image() {
    if (!is_singular() || is_admin()) return;
    if (get_option('cbio_enable_lcp_preload', '0') !== '1') return;
    global $post;
    $thumb_id = get_post_thumbnail_id($post->ID);
    if ($thumb_id) {
        $image_data = wp_get_attachment_image_src($thumb_id, 'full');
        if ($image_data && !empty($image_data[0])) {
            $image_url = $image_data[0];
            $srcset = wp_get_attachment_image_srcset($thumb_id, 'full');
            $sizes = wp_get_attachment_image_sizes($thumb_id, 'full');
            cbio_output_preload_link($image_url, $srcset, $sizes);
            return;
        }
    }
    if (preg_match('/<img[^>]+cbio-above-fold[^>]*>/i', $post->post_content, $img_match)) {
        $img_tag = $img_match[0];
        if (preg_match('/src=["\']([^"\']+)["\']/i', $img_tag, $src_match)) {
            $image_url = $src_match[1];
            $srcset = '';
            if (preg_match('/srcset=["\']([^"\']+)["\']/i', $img_tag, $srcset_match)) {
                $srcset = $srcset_match[1];
            }
            $sizes = '100vw';
            if (preg_match('/sizes=["\']([^"\']+)["\']/i', $img_tag, $sizes_match)) {
                $sizes = $sizes_match[1];
            }
            cbio_output_preload_link($image_url, $srcset, $sizes);
        }
    }
}
add_action('wp_head', 'cbio_preload_lcp_image', 1);

function cbio_output_preload_link($url, $srcset = '', $sizes = '') {
    if (empty($url)) return;
    echo '<link rel="preload" as="image" href="' . esc_url($url) . '"';
    if ($srcset) echo ' imagesrcset="' . esc_attr($srcset) . '"';
    if ($sizes) echo ' imagesizes="' . esc_attr($sizes) . '"';
    echo ' fetchpriority="high">' . "\n";
}

function cbio_fetchpriority_featured_image($attr, $attachment, $size) {
    if (!is_singular() || is_admin()) return $attr;
    if (!in_the_loop() || $attachment->ID !== get_post_thumbnail_id()) return $attr;
    if (get_option('cbio_enable_lcp_preload', '0') === '1') {
        $attr['fetchpriority'] = 'high';
        if (isset($attr['loading'])) unset($attr['loading']);
    } else {
        if (isset($attr['fetchpriority'])) unset($attr['fetchpriority']);
    }
    return $attr;
}
add_filter('wp_get_attachment_image_attributes', 'cbio_fetchpriority_featured_image', 20, 3);

function cbio_fetchpriority_above_fold_content_image($content) {
    if (!is_singular() || is_admin()) return $content;
    static $processed = false;
    if ($processed) return $content;
    $lcp_preload_enabled = get_option('cbio_enable_lcp_preload', '0') === '1';
    $content = preg_replace_callback(
        '/<img([^>]*cbio-above-fold[^>]*)>/i',
        function($matches) use ($lcp_preload_enabled) {
            static $is_first = true;
            if (!$is_first) return $matches[0];
            $is_first = false;
            $img = $matches[0];
            if ($lcp_preload_enabled) {
                $img = preg_replace('/\s+loading=["\']lazy["\']/i', '', $img);
                if (strpos($img, 'fetchpriority=') === false) {
                    $img = preg_replace('/(\/?>)$/', ' fetchpriority="high"$1', $img);
                }
            } else {
                $img = preg_replace('/\s+fetchpriority=["\']high["\']/i', '', $img);
            }
            return $img;
        },
        $content,
        1
    );
    $processed = true;
    return $content;
}
add_filter('the_content', 'cbio_fetchpriority_above_fold_content_image', 21);