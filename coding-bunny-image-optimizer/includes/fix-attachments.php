<?php
if ( ! defined( 'ABSPATH' ) ) exit;

global $cbio_attachment_cache;
$cbio_attachment_cache = [];

global $cbio_url_to_id_cache;
$cbio_url_to_id_cache = [];

function cbio_extract_attachment_ids_from_content( $content ) {
    if ( empty( $content ) ) {
        return [];
    }
    
    $attachments = [];
    
    if ( preg_match_all( '/wp-image-(\d+)/i', $content, $matches ) ) {
        $attachments = array_merge( $attachments, array_map( 'absint', $matches[1] ) );
    }
    
    if ( preg_match_all( '/<!--\s*wp:[^>]*"id"\s*:\s*(\d+)/i', $content, $matches ) ) {
        $attachments = array_merge( $attachments, array_map( 'absint', $matches[1] ) );
    }
    
    if ( preg_match_all( '/<!--\s*wp:[^>]*"mediaId"\s*:\s*(\d+)/i', $content, $matches ) ) {
        $attachments = array_merge( $attachments, array_map( 'absint', $matches[1] ) );
    }
    
    if ( preg_match_all( '/"ids"\s*:\s*\[([^\]]+)\]/i', $content, $matches ) ) {
        foreach ( $matches[1] as $ids_string ) {
            $ids = array_filter( array_map( 'absint', explode( ',', $ids_string ) ) );
            $attachments = array_merge( $attachments, $ids );
        }
    }
    
    if ( preg_match_all( '/\[gallery[^\]]*ids=["\']([^"\']+)["\']/i', $content, $matches ) ) {
        foreach ( $matches[1] as $ids_string ) {
            $ids = array_filter( array_map( function( $id ) {
                return absint( trim( $id ) );
            }, explode( ',', $ids_string ) ) );
            $attachments = array_merge( $attachments, $ids );
        }
    }
    
    if ( preg_match_all( '/data-[a-z-]*id=["\'](\d+)["\']/i', $content, $matches ) ) {
        $attachments = array_merge( $attachments, array_map( 'absint', $matches[1] ) );
    }
    
    if ( preg_match_all( '/"id"\s*:\s*(\d+)\s*,\s*"url"\s*:\s*"[^"]*(?:uploads|wp-content)[^"]*"/i', $content, $matches ) ) {
        $attachments = array_merge( $attachments, array_map( 'absint', $matches[1] ) );
    }
    
    if ( preg_match_all( '/"url"\s*:\s*"[^"]*(?:uploads|wp-content)[^"]*"\s*,\s*"id"\s*:\s*(\d+)/i', $content, $matches ) ) {
        $attachments = array_merge( $attachments, array_map( 'absint', $matches[1] ) );
    }
    
    if ( preg_match_all( '/"attachment_id"\s*:\s*(\d+)/i', $content, $matches ) ) {
        $attachments = array_merge( $attachments, array_map( 'absint', $matches[1] ) );
    }
    
    if ( preg_match_all( '/"image_id"\s*:\s*(\d+)/i', $content, $matches ) ) {
        $attachments = array_merge( $attachments, array_map( 'absint', $matches[1] ) );
    }
    
    return array_values( array_unique( array_filter( $attachments, function( $id ) {
        return $id > 0;
    } ) ) );
}

function cbio_extract_attachments_from_urls( $content, $already_found = [] ) {
    global $wpdb, $cbio_url_to_id_cache;
    
    if ( empty( $content ) ) {
        return [];
    }
    
    $attachments = [];
    $filenames_to_check = [];
    
    $upload_dir = wp_get_upload_dir();
    $upload_baseurl = $upload_dir['baseurl'];
    $site_url = site_url();
    
    $all_urls = [];
    
    if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $matches ) ) {
        $all_urls = array_merge( $all_urls, $matches[1] );
    }
    
    if ( preg_match_all( '/<img[^>]+data-src=["\']([^"\']+)["\']/i', $content, $matches ) ) {
        $all_urls = array_merge( $all_urls, $matches[1] );
    }
    
    if ( preg_match_all( '/<img[^>]+data-lazy-src=["\']([^"\']+)["\']/i', $content, $matches ) ) {
        $all_urls = array_merge( $all_urls, $matches[1] );
    }
    
    if ( preg_match_all( '/srcset=["\']([^"\']+)["\']/i', $content, $matches ) ) {
        foreach ( $matches[1] as $srcset ) {
            $srcset_parts = preg_split( '/\s*,\s*/', $srcset );
            foreach ( $srcset_parts as $part ) {
                $url = preg_split( '/\s+/', trim( $part ) )[0];
                if ( ! empty( $url ) ) {
                    $all_urls[] = $url;
                }
            }
        }
    }
    
    if ( preg_match_all( '/background(?:-image)?\s*:\s*url\(["\']?([^"\')\s]+)["\']?\)/i', $content, $matches ) ) {
        $all_urls = array_merge( $all_urls, $matches[1] );
    }
    
    if ( preg_match_all( '/"(?:url|src|image|background|thumbnail)"\s*:\s*"([^"]+)"/i', $content, $matches ) ) {
        $all_urls = array_merge( $all_urls, $matches[1] );
    }
    
    if ( preg_match_all( '/<source[^>]+srcset=["\']([^"\']+)["\']/i', $content, $matches ) ) {
        foreach ( $matches[1] as $srcset ) {
            $url = preg_split( '/\s+/', trim( $srcset ) )[0];
            if ( ! empty( $url ) ) {
                $all_urls[] = $url;
            }
        }
    }
    
    if ( preg_match_all( '/<a[^>]+href=["\']([^"\']+\.(?:jpg|jpeg|png|gif|webp|avif|svg))["\']/i', $content, $matches ) ) {
        $all_urls = array_merge( $all_urls, $matches[1] );
    }
    
    $all_urls = array_unique( array_filter( $all_urls ) );
    
    foreach ( $all_urls as $url ) {
        $url = html_entity_decode( $url );
        
        if ( empty( $url ) || strpos( $url, 'data:' ) === 0 ) {
            continue;
        }
        
        $is_local = false;
        
        if ( strpos( $url, $upload_baseurl ) !== false ) {
            $is_local = true;
        } elseif ( strpos( $url, '/wp-content/uploads/' ) !== false ) {
            $is_local = true;
        } elseif ( strpos( $url, $site_url ) !== false && strpos( $url, 'uploads' ) !== false ) {
            $is_local = true;
        } elseif ( preg_match( '#^/wp-content/uploads/#', $url ) ) {
            $is_local = true;
        }
        
        if ( ! $is_local ) {
            continue;
        }
        
        $parsed_url = wp_parse_url( $url );
		$filename = isset( $parsed_url['path'] ) ? basename( $parsed_url['path'] ) : '';
		$clean_filename = preg_replace( '/-\d+x\d+(?=\.[a-z]{3,5}$)/i', '', $filename );
		$filename_no_ext = pathinfo( $clean_filename, PATHINFO_FILENAME );
        
        if ( empty( $filename_no_ext ) ) {
            continue;
        }
        
        $cache_key = md5( $clean_filename );
        
        if ( isset( $cbio_url_to_id_cache[ $cache_key ] ) ) {
            $cached_id = $cbio_url_to_id_cache[ $cache_key ];
            if ( $cached_id > 0 && ! in_array( $cached_id, $already_found ) ) {
                $attachments[] = $cached_id;
            }
            continue;
        }
        
        $filenames_to_check[ $cache_key ] = [
            'filename' => $clean_filename,
            'filename_no_ext' => $filename_no_ext,
            'original_url' => $url
        ];
    }
    
    if ( ! empty( $filenames_to_check ) ) {
        $chunks = array_chunk( $filenames_to_check, 30, true );
        
        foreach ( $chunks as $chunk ) {
            $like_conditions = [];
            $like_values = [];
            
            foreach ( $chunk as $data ) {
    $like_conditions[] = "pm.meta_value LIKE %s";
    $like_values[] = '%' . $wpdb->esc_like( $data['filename'] ) . '%';

    if ( strlen( $data['filename_no_ext'] ) > 3 ) {
        $like_conditions[] = "pm.meta_value LIKE %s";
        $like_values[] = '%' . $wpdb->esc_like( $data['filename_no_ext'] ) . '.%';
    }
}

if ( empty( $like_conditions ) ) {
    continue;
}

$where = implode( ' OR ', $like_conditions );
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
$sql = $wpdb->prepare("SELECT p.ID, pm.meta_value FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id WHERE p.post_type = 'attachment' AND pm.meta_key = '_wp_attached_file' AND ( $where ) LIMIT 200", ...$like_values);

// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
$results = $wpdb->get_results( $sql );
            
            foreach ( $chunk as $cache_key => $data ) {
                $found_id = 0;
                
                foreach ( $results as $row ) {
                    $row_filename = basename( $row->meta_value );
                    $row_filename_no_ext = pathinfo( $row_filename, PATHINFO_FILENAME );
                    
                    if ( $row_filename === $data['filename'] ) {
                        $found_id = (int) $row->ID;
                        break;
                    }
                    
                    $row_clean = preg_replace( '/-\d+x\d+(?=\.[a-z]{3,5}$)/i', '', $row_filename );
                    if ( $row_clean === $data['filename'] ) {
                        $found_id = (int) $row->ID;
                        break;
                    }
                    
                    if ( $row_filename_no_ext === $data['filename_no_ext'] ) {
                        $found_id = (int) $row->ID;
                        break;
                    }
                }
                
                $cbio_url_to_id_cache[ $cache_key ] = $found_id;
                
                if ( $found_id > 0 && ! in_array( $found_id, $already_found ) && ! in_array( $found_id, $attachments ) ) {
                    $attachments[] = $found_id;
                }
            }
            
            foreach ( $chunk as $cache_key => $data ) {
                if ( ! isset( $cbio_url_to_id_cache[ $cache_key ] ) ) {
                    $cbio_url_to_id_cache[ $cache_key ] = 0;
                }
            }
        }
    }
    
    return array_values( array_unique( array_filter( $attachments ) ) );
}

function cbio_validate_attachment_ids( $ids ) {
    global $wpdb, $cbio_attachment_cache;
    
    if ( empty( $ids ) ) {
        return [];
    }
    
    $ids = array_unique( array_filter( array_map( 'absint', $ids ) ) );
    $valid = [];
    $to_check = [];
    
    foreach ( $ids as $id ) {
        if ( isset( $cbio_attachment_cache[ $id ] ) ) {
            if ( $cbio_attachment_cache[ $id ] ) {
                $valid[] = $id;
            }
        } else {
            $to_check[] = $id;
        }
    }
    
    if ( ! empty( $to_check ) ) {
        $placeholders = implode( ',', array_fill( 0, count( $to_check ), '%d' ) );
        $sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            "SELECT ID FROM {$wpdb->posts} WHERE ID IN ($placeholders) AND post_type = 'attachment'",
            ...$to_check
        );
        
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $found = $wpdb->get_col( $sql );
        $found = array_map( 'intval', $found );
        
        foreach ( $to_check as $id ) {
            $is_valid = in_array( $id, $found );
            $cbio_attachment_cache[ $id ] = $is_valid;
            if ( $is_valid ) {
                $valid[] = $id;
            }
        }
    }
    
    return $valid;
}

function cbio_get_attachment_parents_batch( $attachment_ids ) {
    global $wpdb;
    
    if ( empty( $attachment_ids ) ) {
        return [];
    }
    
    $attachment_ids = array_unique( array_filter( array_map( 'absint', $attachment_ids ) ) );
    
    $placeholders = implode( ',', array_fill( 0, count( $attachment_ids ), '%d' ) );
    $sql = $wpdb->prepare(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        "SELECT ID, post_parent FROM {$wpdb->posts} WHERE ID IN ($placeholders)",
        ...$attachment_ids
    );
    
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $results = $wpdb->get_results( $sql );
    $parents = [];
    
    foreach ( $results as $row ) {
        $parents[ (int) $row->ID ] = (int) $row->post_parent;
    }
    
    return $parents;
}

function cbio_get_attached_images_batch( $post_ids ) {
    global $wpdb;
    
    if ( empty( $post_ids ) ) {
        return [];
    }
    
    $post_ids = array_unique( array_filter( array_map( 'absint', $post_ids ) ) );
    
    $placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
    $sql = $wpdb->prepare( "SELECT ID, post_parent FROM {$wpdb->posts} WHERE post_parent IN ($placeholders) AND post_type = 'attachment'", ...$post_ids );
    
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $results = $wpdb->get_results( $sql );
    
    $attached = [];
    foreach ( $post_ids as $post_id ) {
        $attached[ $post_id ] = [];
    }
    
    foreach ( $results as $row ) {
        $parent_id = (int) $row->post_parent;
        if ( isset( $attached[ $parent_id ] ) ) {
            $attached[ $parent_id ][] = (int) $row->ID;
        }
    }
    
    return $attached;
}

function cbio_update_attachment_parents_batch( $updates ) {
    global $wpdb;
    
    if ( empty( $updates ) ) {
        return 0;
    }
    
    $count = 0;
    
    $by_parent = [];
    foreach ( $updates as $attachment_id => $parent_id ) {
        $by_parent[ $parent_id ][] = $attachment_id;
    }
    
    foreach ( $by_parent as $parent_id => $attachment_ids ) {
        $placeholders = implode( ',', array_fill( 0, count( $attachment_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        $sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "UPDATE {$wpdb->posts} SET post_parent = %d WHERE ID IN ($placeholders)",
            $parent_id,
            ...$attachment_ids
        );
        
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery
        $count += $wpdb->query( $sql );
    }
    
    foreach ( array_keys( $updates ) as $id ) {
        clean_post_cache( $id );
    }
    
    return $count;
}

add_action( 'wp_ajax_cbio_fix_attachments_batch', function() {
    check_ajax_referer( 'cbio_fix_attachments', 'nonce' );
    
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => __( 'Unauthorized', 'coding-bunny-image-optimizer' ) ] );
    }
    
    if ( function_exists( 'wp_raise_memory_limit' ) ) {
        wp_raise_memory_limit( 'admin' );
    }
    
    $batch_size = isset( $_POST['batch_size'] ) ? min( absint( $_POST['batch_size'] ), 100 ) : 50;
    $offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
    $total = isset( $_POST['total'] ) ? absint( $_POST['total'] ) : 0;
    
    $post_types = apply_filters( 'cbio_fix_attachments_post_types', [ 'post', 'page', 'product' ] );
    
    global $wpdb;
    
    $post_types_placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
    
    if ( $offset === 0 || $total === 0 ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
        $count_sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts}  WHERE post_type IN ($post_types_placeholders) AND post_status IN ('publish', 'draft', 'private', 'pending')", ...$post_types );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $total = (int) $wpdb->get_var( $count_sql );
    }
    
	// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $batch_sql = $wpdb->prepare( "SELECT ID, post_type, post_content FROM {$wpdb->posts} WHERE post_type IN ($post_types_placeholders) AND post_status IN ('publish', 'draft', 'private', 'pending') ORDER BY ID ASC LIMIT %d OFFSET %d", ...array_merge( $post_types, [ $batch_size, $offset ] ) );
    
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $posts = $wpdb->get_results( $batch_sql );
    
    $fixed = 0;
    $detached = 0;
    
    $post_ids = wp_list_pluck( $posts, 'ID' );
    
    if ( ! empty( $post_ids ) ) {
        update_meta_cache( 'post', $post_ids );
        
        $currently_attached = cbio_get_attached_images_batch( $post_ids );
        
        $all_should_be_attached = [];
        $all_attachment_ids = [];
        
        foreach ( $posts as $post ) {
            $post_id = (int) $post->ID;
            $should_be_attached = [];
            
            $featured_id = (int) get_post_meta( $post_id, '_thumbnail_id', true );
            if ( $featured_id > 0 ) {
                $should_be_attached[] = $featured_id;
            }
            
            if ( $post->post_type === 'product' ) {
                $gallery_meta = get_post_meta( $post_id, '_product_image_gallery', true );
                if ( ! empty( $gallery_meta ) ) {
                    $gallery_ids = array_filter( array_map( 'absint', explode( ',', $gallery_meta ) ) );
                    $should_be_attached = array_merge( $should_be_attached, $gallery_ids );
                }
            }
            
            if ( ! empty( $post->post_content ) ) {
                $content_ids = cbio_extract_attachment_ids_from_content( $post->post_content );
                $should_be_attached = array_merge( $should_be_attached, $content_ids );
            }
            
            $should_be_attached = array_unique( array_filter( $should_be_attached ) );
            
            if ( ! empty( $post->post_content ) ) {
                $url_ids = cbio_extract_attachments_from_urls( $post->post_content, $should_be_attached );
                $should_be_attached = array_merge( $should_be_attached, $url_ids );
                $should_be_attached = array_unique( $should_be_attached );
            }
            
            $all_should_be_attached[ $post_id ] = $should_be_attached;
            $all_attachment_ids = array_merge( $all_attachment_ids, $should_be_attached );
        }
        
        $all_attachment_ids = array_unique( $all_attachment_ids );
        $valid_attachments = cbio_validate_attachment_ids( $all_attachment_ids );
        
        $current_parents = [];
        if ( ! empty( $valid_attachments ) ) {
            $current_parents = cbio_get_attachment_parents_batch( $valid_attachments );
        }
        
        $already_assigned_in_batch = [];
        $updates_to_attach = [];
        $updates_to_detach = [];
        
        foreach ( $posts as $post ) {
            $post_id = (int) $post->ID;
            $should_be_attached = $all_should_be_attached[ $post_id ] ?? [];
            $should_be_attached = array_intersect( $should_be_attached, $valid_attachments );
            
            foreach ( $should_be_attached as $img_id ) {
                $current_parent = $current_parents[ $img_id ] ?? 0;
                
                if ( $current_parent === 0 && ! isset( $already_assigned_in_batch[ $img_id ] ) ) {
                    $updates_to_attach[ $img_id ] = $post_id;
                    $already_assigned_in_batch[ $img_id ] = $post_id;
                }
            }
            
            $post_currently_attached = $currently_attached[ $post_id ] ?? [];
            
            foreach ( $post_currently_attached as $img_id ) {
                if ( ! in_array( $img_id, $should_be_attached ) ) {
                    $updates_to_detach[ $img_id ] = 0;
                }
            }
        }
        
        if ( ! empty( $updates_to_attach ) ) {
            $fixed = cbio_update_attachment_parents_batch( $updates_to_attach );
        }
        
        if ( ! empty( $updates_to_detach ) ) {
            $detached = cbio_update_attachment_parents_batch( $updates_to_detach );
        }
    }
    
    $new_offset = $offset + $batch_size;
    $done = $new_offset >= $total;
    
    $progress = $total > 0 ? min( round( ( $new_offset / $total ) * 100 ), 100 ) : 100;
    
    wp_send_json_success( [
        'fixed'    => $fixed,
        'detached' => $detached,
        'offset'   => $new_offset,
        'total'    => $total,
        'done'     => $done,
        'progress' => $progress,
        'message'  => sprintf(
			// translators: 1: processed posts, 2: total posts, 3: attachments fixed, 4: attachments detached.
            __( 'Processed %1$d/%2$d posts.  %3$d attachments fixed, %4$d detached.', 'coding-bunny-image-optimizer' ),
            min( $new_offset, $total ),
            $total,
            $fixed,
            $detached
        )
    ] );
} );

add_action( 'save_post', function( $post_id, $post, $update ) {
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    
    if ( wp_is_post_revision( $post_id ) ) {
        return;
    }
    
    $supported_types = apply_filters( 'cbio_fix_attachments_post_types', [ 'post', 'page', 'product' ] );
    if ( ! in_array( $post->post_type, $supported_types ) ) {
        return;
    }
    
    if ( ! wp_next_scheduled( 'cbio_fix_single_post_attachments', [ $post_id ] ) ) {
        wp_schedule_single_event( time() + 5, 'cbio_fix_single_post_attachments', [ $post_id ] );
    }
}, 10, 3 );

add_action( 'cbio_fix_single_post_attachments', function( $post_id ) {
    $post = get_post( $post_id );
    if ( ! $post ) {
        return;
    }
    
    $should_be_attached = [];
    
    $featured_id = (int) get_post_thumbnail_id( $post_id );
    if ( $featured_id > 0 ) {
        $should_be_attached[] = $featured_id;
    }
    
    if ( $post->post_type === 'product' ) {
        $gallery_meta = get_post_meta( $post_id, '_product_image_gallery', true );
        if ( ! empty( $gallery_meta ) ) {
            $should_be_attached = array_merge( 
                $should_be_attached, 
                array_filter( array_map( 'absint', explode( ',', $gallery_meta ) ) )
            );
        }
    }
    
    if ( ! empty( $post->post_content ) ) {
        $should_be_attached = array_merge( 
            $should_be_attached, 
            cbio_extract_attachment_ids_from_content( $post->post_content )
        );
        $should_be_attached = array_merge(
            $should_be_attached,
            cbio_extract_attachments_from_urls( $post->post_content, $should_be_attached )
        );
    }
    
    $should_be_attached = array_unique( array_filter( $should_be_attached ) );
    $valid_attachments = cbio_validate_attachment_ids( $should_be_attached );
    
    $current_parents = cbio_get_attachment_parents_batch( $valid_attachments );
    
    $updates = [];
    foreach ( $valid_attachments as $img_id ) {
        $current_parent = $current_parents[ $img_id ] ?? 0;
        if ( $current_parent === 0 ) {
            $updates[ $img_id ] = $post_id;
        }
    }
    
    if ( ! empty( $updates ) ) {
        cbio_update_attachment_parents_batch( $updates );
    }
} );