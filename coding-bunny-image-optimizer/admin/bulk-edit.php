<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'cbio_settings_page' ) ) {
	require_once plugin_dir_path( __FILE__ ) . 'image-optimizer.php';
}

require_once dirname(__DIR__) . '/includes/image-convert.php';
require_once dirname(__DIR__) . '/includes/image-resize.php';

add_action('admin_enqueue_scripts', function() {
	$screen = get_current_screen();
	if ($screen->id !== 'upload') {
		return;
	}

	$show_bulk_options = get_option('cbio_show_bulk_options', '1') === '1';

	wp_enqueue_script(
		'cbio-scripts',
		plugins_url('../assets/js/cbio-scripts.js', __FILE__),
		[],
		CBIO_VERSION,
		true
	);

	if ($show_bulk_options) {
		wp_enqueue_script(
			'cbio-media-grid-bulk',
			plugins_url('../assets/js/media-grid-bulk.js', __FILE__),
			['media-views', 'media-grid'],
			CBIO_VERSION,
			true
		);

		wp_localize_script('cbio-media-grid-bulk', 'cbioBulkMediaGrid', [
			'nonce' => wp_create_nonce('convert_webp_nonce'),
			'ajax_url' => esc_url(admin_url('admin-ajax.php')),
			'label' => __('Optimize Selected Images', 'coding-bunny-image-optimizer'),
		]);
	}

	wp_localize_script('cbio-scripts', 'cb_bulk_options_data', [
		'ajax_url'            => esc_url(admin_url('admin-ajax.php')),
		'nonce'               => wp_create_nonce('convert_webp_nonce'),
		'url_update_nonce'    => wp_create_nonce('cbio_update_images_urls'),
		'batch_size'          => (int) get_option('cbio_batch_size', 5),
		'convert_format'      => get_option('cbio_convert_format', 'webp'),
		'delete_original'     => get_option('cbio_delete_original', '0'),
		'enable_auto_alt_text'=> get_option('cbio_enable_auto_alt_text', '0') === '1',
		'show_bulk_options'   => $show_bulk_options,
		'force_reoptimize'    => get_option('cbio_force_reoptimize', '0') === '1'
	]);
});

add_filter('bulk_actions-upload', function($bulk_actions) {
	if (get_option('cbio_show_bulk_options', '1') === '1') {
		$bulk_actions['cbio_optimize_images'] = __('Optimize Selected Images', 'coding-bunny-image-optimizer');
	}
	return $bulk_actions;
});

add_filter('handle_bulk_actions-upload', 'cbio_handle_bulk_optimize_action', 10, 3);
function cbio_handle_bulk_optimize_action($redirect_to, $doaction, $post_ids) {
	if ($doaction !== 'cbio_optimize_images') {
		return $redirect_to;
	}

	if (!current_user_can('manage_options')) {
		return $redirect_to;
	}

	$image_ids = array();
	foreach ($post_ids as $post_id) {
		$post = get_post($post_id);
		$mime_type = get_post_mime_type($post_id);
		if ($post && $post->post_type === 'attachment' 
			&& strpos($post->post_mime_type, 'image/') === 0 
			&& $mime_type !== 'image/svg+xml') {
			$image_ids[] = $post_id;
		}
	}

	if (empty($image_ids)) {
		$redirect_to = add_query_arg('cbio_optimized', 0, $redirect_to);
		return $redirect_to;
	}

	$options = [
		'convert_format' => get_option('cbio_convert_format', 'webp'),
		'delete_original' => get_option('cbio_delete_original', '0'),
		'enable_conversion' => get_option('cbio_enable_conversion', '1') === '1',
		'enable_resize' => get_option('cbio_enable_resize', '1') === '1'
	];

	$force_reoptimize = get_option('cbio_force_reoptimize', '0') === '1';

	$results = cbio_process_image_batch(
		$image_ids,
		$options['convert_format'],
		$options['delete_original'],
		$options['enable_conversion'],
		$options['enable_resize'],
		$force_reoptimize
	);

	$optimized_count = $results['success'];
	$nonce = wp_create_nonce('cbio_bulk_notice');
	$redirect_to = add_query_arg(
		[
			'cbio_optimized' => $optimized_count,
			'cbio_notice_nonce' => $nonce
		],
		$redirect_to
	);
	return $redirect_to;
}

add_action('admin_notices', 'cbio_bulk_optimize_admin_notice');
function cbio_bulk_optimize_admin_notice() {
	if ( !  empty( $_REQUEST['cbio_optimized'] ) && ! empty( $_REQUEST['cbio_notice_nonce'] ) ) {
		$nonce = isset( $_REQUEST['cbio_notice_nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['cbio_notice_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'cbio_bulk_notice' ) ) {
			return;
		}
		$optimized_count = isset( $_REQUEST['cbio_optimized'] ) ? intval( $_REQUEST['cbio_optimized'] ) : 0;
		if ( $optimized_count > 0 ) {
			$message = sprintf(
				// translators: %d is the number of optimized images.
				_n(
					'Optimized %d image.',
					'Optimized %d images.',
					$optimized_count,
					'coding-bunny-image-optimizer'
				),
				$optimized_count
			);
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		} else {
			echo '<div class="notice notice-warning is-dismissible"><p>' . 
				esc_html__( 'No images were found to optimize.', 'coding-bunny-image-optimizer' ) .
				'</p></div>';
		}
	}
}

add_action('wp_ajax_cbio_get_total_images', 'cbio_get_total_images');
function cbio_get_total_images() {
	check_ajax_referer('convert_webp_nonce', 'nonce');
	if (!current_user_can('manage_options')) {
		wp_send_json_error(__('Unauthorized user', 'coding-bunny-image-optimizer'));
	}
	global $wpdb;
	$cache_key = 'cbio_total_images_count_v3';
	$total = get_transient($cache_key);
	if ($total === false) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = $wpdb->get_var(
			"SELECT COUNT(ID) 
			FROM {$wpdb->posts} 
			WHERE post_type = 'attachment' 
			AND post_status = 'inherit' 
			AND post_mime_type LIKE 'image/%' 
			AND post_mime_type != 'image/svg+xml'"
		);
		$total = (int) $total;
		set_transient($cache_key, $total, 1800);
	}
	wp_send_json_success(['total' => $total]);
}

add_action('wp_ajax_cbio_get_image_batch', 'cbio_get_image_batch');
function cbio_get_image_batch() {
	check_ajax_referer('convert_webp_nonce', 'nonce');
	if (!current_user_can('manage_options')) {
		wp_send_json_error(__('Unauthorized user', 'coding-bunny-image-optimizer'));
	}
	global $wpdb;
	$batch_size = (int) get_option('cbio_batch_size', 5);
	$offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
	$like_pattern = 'image/%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT ID 
			FROM {$wpdb->posts} 
			WHERE post_type = 'attachment' 
			AND post_status = 'inherit' 
			AND post_mime_type LIKE %s
			AND post_mime_type != %s
			ORDER BY ID DESC 
			LIMIT %d OFFSET %d",
			$like_pattern,
			'image/svg+xml',
			$batch_size,
			$offset
		)
	);
	$ids = array_map('intval', $ids);
	wp_send_json_success([
		'ids' => $ids,
		'count' => count($ids)
	]);
}

function cbio_process_image_batch($ids, $convert_format, $delete_original, $enable_conversion, $enable_resize, $force_reoptimize = false) {
	$results = array(
		'success' => 0,
		'failed' => 0,
		'skipped' => 0,
		'details' => array()
	);

	$upload_dir = wp_upload_dir();
	$should_delete_original = ($delete_original === '1');

	foreach ($ids as $post_id) {

		$original_file_path = get_attached_file($post_id);
		if (!$original_file_path || !file_exists($original_file_path)) {
			$results['failed']++;
			$results['details'][$post_id] = 'failed - file not found';
			continue;
		}

		$file_info = pathinfo($original_file_path);
		$extension = strtolower($file_info['extension']);
		if ($extension === 'jpeg') $extension = 'jpg';
		$mime_type = get_post_mime_type($post_id);

		if ($mime_type === 'image/svg+xml' || $extension === 'svg') {
			$results['skipped']++;
			$results['details'][$post_id] = 'skipped - SVG cannot be converted';
			continue;
		}

		$already_optimized_mimes = ['image/webp', 'image/avif'];
		$is_already_optimized = in_array($mime_type, $already_optimized_mimes) || $extension === $convert_format;

		$current_file_path = $original_file_path;
		$operations_performed = [];
		$metadata_needs_update = false;
		$files_to_delete = [];

		$was_optimized = get_post_meta($post_id, '_cbio_optimized', true) === 'yes';
		if ($was_optimized && !$force_reoptimize && $is_already_optimized && !$enable_resize) {
			$results['skipped']++;
			$results['details'][$post_id] = 'skipped - already optimized';
			continue;
		}

		if ($enable_resize) {
			$options_cache = cbio_get_resize_options();
			$image_info = cbio_get_image_info(['file' => $current_file_path]);
			if (
				$image_info &&
				(
					$image_info['width'] > $options_cache['max_width'] ||
					$image_info['height'] > $options_cache['max_height']
				)
			) {
				$resized_result = cbio_handle_upload_resize(['file' => $current_file_path]);
				if (is_array($resized_result) && isset($resized_result['file']) && file_exists($resized_result['file'])) {
					if ($resized_result['file'] !== $current_file_path) {
						if ($should_delete_original) {
							$files_to_delete[] = $current_file_path;
						}
						$current_file_path = $resized_result['file'];
						$file_info = pathinfo($current_file_path);
						$extension = strtolower($file_info['extension']);
					}
					$operations_performed[] = 'resized';
					$metadata_needs_update = true;
				}
			} else {
				$results['details'][$post_id] = 'skipped - already within target dimensions';
			}
		}

		if ($enable_conversion && ($force_reoptimize || !$is_already_optimized || $extension !== $convert_format)) {
			$output_path = $file_info['dirname'] . '/' . $file_info['filename'] . '.' . $convert_format;
			$conversion_result = cbio_convert_images($current_file_path, $output_path, $convert_format);

			if ($conversion_result && file_exists($output_path) && filesize($output_path) > 0) {
				if ($should_delete_original) {
					$files_to_delete[] = $current_file_path;
				}

				$current_file_path = $output_path;
				$operations_performed[] = 'converted';
				$metadata_needs_update = true;

				update_post_meta($post_id, '_cbio_optimized', 'yes');
				update_post_meta($post_id, '_cbio_optimized_format', $convert_format);
				update_post_meta($post_id, '_cbio_optimized_date', current_time('mysql'));

				$new_mime_type = $convert_format === 'avif' ? 'image/avif' : 'image/webp';
				wp_update_post(array(
					'ID' => $post_id,
					'post_mime_type' => $new_mime_type
				));
			}
		}

		if ($should_delete_original && in_array('converted', $operations_performed)) {
			$metadata = wp_get_attachment_metadata($post_id);
			$meta_updated = false;
			if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
				$base_dir = dirname($original_file_path);
				foreach ($metadata['sizes'] as $size => $info) {
					if (isset($info['file'])) {
						$thumb_path = $base_dir . '/' . $info['file'];
						$thumb_ext = strtolower(pathinfo($thumb_path, PATHINFO_EXTENSION));
						$thumb_name = pathinfo($thumb_path, PATHINFO_FILENAME);
						$converted_thumb_path = $base_dir . '/' . $thumb_name . '.' . $convert_format;

						if ($thumb_ext !== $convert_format && $thumb_ext !== 'svg' && file_exists($thumb_path)) {
							$conversion_result = cbio_convert_images($thumb_path, $converted_thumb_path, $convert_format);
							if ($conversion_result && file_exists($converted_thumb_path)) {
								wp_delete_file($thumb_path);
								$metadata['sizes'][$size]['file'] = basename($converted_thumb_path);
								$meta_updated = true;
							}
						}
					}
				}
				if ($meta_updated) {
					wp_update_attachment_metadata($post_id, $metadata);
				}
			}
		}

		if ($metadata_needs_update) {
			update_attached_file($post_id, $current_file_path);
			$metadata = wp_generate_attachment_metadata($post_id, $current_file_path);
			if ($metadata) {
				wp_update_attachment_metadata($post_id, $metadata);
			}
		}

		if ($should_delete_original && ! empty($files_to_delete)) {
			foreach ($files_to_delete as $file_to_delete) {
				if (file_exists($file_to_delete) && $file_to_delete !== $current_file_path) {
					wp_delete_file($file_to_delete);
				}
			}
		}

		if (in_array('converted', $operations_performed)) {
			$results['success']++;
			$results['details'][$post_id] = 'converted';
		} elseif (in_array('resized', $operations_performed)) {
			update_post_meta($post_id, '_cbio_resized', 1);
			update_post_meta($post_id, '_cbio_optimized', 'yes');
			$results['success']++;
			$results['details'][$post_id] = 'resized';
		} else {
			update_post_meta($post_id, '_cbio_optimized', 'yes');
			$results['skipped']++;
			$results['details'][$post_id] = 'skipped - no operation needed';
		}
	}

	return $results;
}

add_action('wp_ajax_convert_to_webp_multiple', 'cbio_convert_to_webp_multiple');
function cbio_convert_to_webp_multiple() {
	check_ajax_referer('convert_webp_nonce', 'nonce');
	if (! current_user_can('manage_options')) {
		wp_send_json_error(__('Unauthorized user', 'coding-bunny-image-optimizer'));
	}
	if (!isset($_POST['ids']) || !is_array($_POST['ids']) || empty($_POST['ids'])) {
		wp_send_json_error(__('No images selected', 'coding-bunny-image-optimizer'));
	}
	$ids = array_map('intval', $_POST['ids']);
	$ids = array_filter($ids, function($id) {
		return $id > 0;
	});
	$options = [
		'convert_format' => get_option('cbio_convert_format', 'webp'),
		'delete_original' => get_option('cbio_delete_original', '0'),
		'enable_conversion' => get_option('cbio_enable_conversion', '1') === '1',
		'enable_resize' => get_option('cbio_enable_resize', '1') === '1'
	];
	$force_reoptimize = get_option('cbio_force_reoptimize', '0') === '1';
	if (isset($_POST['force_reoptimize']) && $_POST['force_reoptimize'] == '1') {
		$force_reoptimize = true;
	}
	$results = cbio_process_image_batch(
		$ids,
		$options['convert_format'],
		$options['delete_original'],
		$options['enable_conversion'],
		$options['enable_resize'],
		$force_reoptimize
	);
	wp_send_json_success([
		'results' => $results
	]);
}

add_action('wp_ajax_cbio_generate_bulk_alt_text', 'cbio_generate_bulk_alt_text');
function cbio_generate_bulk_alt_text() {
	check_ajax_referer('cbio_generate_bulk_alt_text', 'nonce');

	if (!current_user_can('manage_options')) {
		wp_send_json_error(['message' => 'Unauthorized']);
	}

	if (get_option('cbio_enable_auto_alt_text', '0') !== '1') {
		wp_send_json_error(['message' => 'Option not enabled']);
	}

	$batch_size = 50;
	$offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;

	global $wpdb;
	
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$image_ids = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT p.ID 
		FROM {$wpdb->posts} p
		LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attachment_image_alt'
		WHERE p.post_type = 'attachment'
		AND p.post_status = 'inherit'
		AND p.post_mime_type LIKE %s
		AND p.post_mime_type != %s
		AND (pm.meta_value IS NULL OR pm.meta_value = '')
		LIMIT %d OFFSET %d",
		'image/%',
		'image/svg+xml',
		$batch_size,
		$offset
	)
);

	if (empty($image_ids)) {
		wp_send_json_success([
			'done' => true,
			'updated' => 0,
			'processed' => $offset
		]);
		return;
	}

	require_once dirname(__DIR__) . '/includes/image-alt-generator.php';

	$updated = 0;
	foreach ($image_ids as $img_id) {
		$file = get_attached_file($img_id);
		$filename = basename($file);
		$auto_alt = function_exists('cbio_generate_alt_from_filename')
			? cbio_generate_alt_from_filename($filename)
			: '';
		if (! empty($auto_alt)) {
			update_post_meta($img_id, '_wp_attachment_image_alt', $auto_alt);
			$updated++;
		}
	}

	wp_send_json_success([
		'done' => count($image_ids) < $batch_size,
		'updated' => $updated,
		'processed' => $offset + count($image_ids),
		'next_offset' => $offset + $batch_size
	]);
}

add_action('wp_ajax_cbio_update_images_urls', 'cbio_update_post_image_urls');
function cbio_update_post_image_urls() {
	$nonce_verified = false;
	
	if (isset($_POST['nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'cbio_update_images_urls')) {
		$nonce_verified = true;
	} elseif (isset($_POST['nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'convert_webp_nonce')) {
		$nonce_verified = true;
	}
	
	if (! $nonce_verified) {
		wp_send_json_error([
			'message' => __('Nonce verification failed', 'coding-bunny-image-optimizer')
		]);
		return;
	}

	if (! current_user_can('manage_options')) {
		wp_send_json_error([
			'message' => __('Unauthorized user', 'coding-bunny-image-optimizer')
		]);
		return;
	}

	$batch_size = 20;
	$offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;

	try {
		$result = cbio_update_post_image_urls_batch($offset, $batch_size);
		wp_send_json_success($result);
	} catch (Exception $e) {
		wp_send_json_error([
			'message' => $e->getMessage()
		]);
	}
}

function cbio_update_post_image_urls_batch($offset = 0, $batch_size = 20) {
	global $wpdb;
	
	$convert_format = get_option('cbio_convert_format', 'webp');
	
	$total_cache_key = 'cbio_total_posts_with_images_v2';
	$total = get_transient($total_cache_key);
	
	if ($total === false) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = $wpdb->get_var(
			"SELECT COUNT(ID) 
			FROM {$wpdb->posts} 
			WHERE post_type IN ('post', 'page')
			AND post_status IN ('publish', 'private', 'draft')
			AND post_content LIKE '%wp-content/uploads/%'"
		);
		$total = (int) $total;
		set_transient($total_cache_key, $total, 600);
	}
	
	if ($total === 0) {
		delete_transient($total_cache_key);
		return array(
			'done' => true,
			'processed' => 0,
			'total' => 0,
			'updated' => 0,
			'next_offset' => 0,
			'message' => __('No posts with images found', 'coding-bunny-image-optimizer')
		);
	}
	
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$post_ids = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT ID 
		FROM {$wpdb->posts} 
		WHERE post_type IN ('post', 'page')
		AND post_status IN ('publish', 'private', 'draft')
		AND post_content LIKE %s
		ORDER BY ID DESC
		LIMIT %d OFFSET %d",
		'%wp-content/uploads/%',
		$batch_size,
		$offset
	)
);
	
	$processed = $offset + count($post_ids);
	$is_done = (count($post_ids) === 0) || ($processed >= $total);
	
	if (empty($post_ids)) {
		delete_transient($total_cache_key);
		return array(
			'done' => true,
			'processed' => $offset,
			'total' => $total,
			'updated' => 0,
			'next_offset' => $offset,
			'message' => __('All posts processed', 'coding-bunny-image-optimizer')
		);
	}

	$upload_dir = wp_upload_dir();
	$upload_baseurl = $upload_dir['baseurl'];
	$upload_basedir = $upload_dir['basedir'];
	$updated_count = 0;

	foreach ($post_ids as $post_id) {

		$post = get_post($post_id);
		
		if (!$post || strpos($post->post_content, 'wp-content/uploads/') === false) {
			continue;
		}

		$content = $post->post_content;
		$original_content = $content;

	preg_match_all(
    '/<img[^>]+src=[\'"]([^\'"]+?\.(?:jpe?g|png|gif|webp|avif))(?:\?[^\'"]*)?[\'"][^>]*>/i',
    $content,
    $matches,
    PREG_SET_ORDER
);

foreach ($matches as $match) {
    $full_img_tag = $match[0];
    $img_src = $match[1];

    $normalized_upload_baseurl = preg_replace('#^https?:#', '', $upload_baseurl);
    $normalized_img_src = preg_replace('#^https?:#', '', $img_src);

    if (strpos($normalized_img_src, $normalized_upload_baseurl) !== 0) {
        continue;
    }

    $src_no_query = preg_replace('/\?.*$/', '', $img_src);
    $path_info = pathinfo($src_no_query);

    if (empty($path_info['filename'])) {
        continue;
    }

    $ext = strtolower($path_info['extension']);
    if ($ext === 'svg') {
        continue;
    }

    $new_src = $path_info['dirname'] . '/' . $path_info['filename'] . '.' . $convert_format;

    $new_file_path = wp_normalize_path(
        str_replace($upload_baseurl, $upload_basedir, $new_src)
    );

    if (file_exists($new_file_path)) {

        $parsed = wp_parse_url($img_src);
        if (isset($parsed['query'])) {
            $new_src .= '?' . $parsed['query'];
        }

        $new_tag = preg_replace(
            '/src=[\'"][^\'"]+[\'"]/',
            'src="' . esc_url($new_src) . '"',
            $full_img_tag
        );

        $content = str_replace($full_img_tag, $new_tag, $content);
    }
}

		if ($content !== $original_content) {
			wp_update_post(array(
				'ID' => $post->ID,
				'post_content' => $content,
			));
			$updated_count++;
		}
	}
	
	if ($is_done) {
		delete_transient($total_cache_key);
	}

	return array(
		'done' => $is_done,
		'processed' => $processed,
		'total' => $total,
		'updated' => $updated_count,
		'next_offset' => $offset + $batch_size,
		'message' => sprintf(
			/* translators: 1: processed posts, 2: total posts, 3: updated posts */
			__('Processed %1$d of %2$d posts (%3$d updated)', 'coding-bunny-image-optimizer'),
			$processed,
			$total,
			$updated_count
		)
	);
}

function cbio_update_post_image_urls_logic() {
	$offset = 0;
	$batch_size = 20;
	$total_updated = 0;
	$max_iterations = 100;
	$iterations = 0;
	
	do {		
		$result = cbio_update_post_image_urls_batch($offset, $batch_size);
		$total_updated += $result['updated'];
		$offset = $result['next_offset'];
		$iterations++;
		
		if ($iterations >= $max_iterations) {
			break;
		}
		
	} while (! $result['done']);
	
	return $total_updated;
}