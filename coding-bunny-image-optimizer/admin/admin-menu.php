<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function cbio_menu() {
	add_menu_page(
		esc_html__( 'CodingBunny Image Optimizer', 'coding-bunny-image-optimizer' ),
		esc_html__( 'Image Optimizer', 'coding-bunny-image-optimizer' ),
		'manage_options',
		'coding-bunny-image-optimizer',
		'cbio_settings_page',
		'data:image/svg+xml;base64,' . base64_encode(file_get_contents(plugin_dir_path(__FILE__) . '../assets/images/cbio-icon.svg')),
		11
	);
	
	add_submenu_page(
		'coding-bunny-image-optimizer',
		esc_html__( 'Image Optimizer', 'coding-bunny-image-optimizer' ),
		esc_html__( 'Optimizer', 'coding-bunny-image-optimizer' ),
		'manage_options',
		'coding-bunny-image-optimizer',
		'cbio_settings_page'
	);
}
add_action( 'admin_menu', 'cbio_menu' );

function cbio_reorder_submenus() {
    global $submenu;
    if (isset($submenu['coding-bunny-image-optimizer'])) {
        $ordine = array(
            'coding-bunny-image-optimizer',
            'coding-bunny-image-cleaner',
            'coding-bunny-image-watermark',
        );
        $nuovo_submenu = array();
        foreach ($ordine as $slug) {
            foreach ($submenu['coding-bunny-image-optimizer'] as $item) {
                if ($item[2] === $slug) {
                    $nuovo_submenu[] = $item;
                }
            }
        }
        foreach ($submenu['coding-bunny-image-optimizer'] as $item) {
            if (!in_array($item[2], $ordine, true)) {
                $nuovo_submenu[] = $item;
            }
        }
        $submenu['coding-bunny-image-optimizer'] = $nuovo_submenu;
    }
}
add_action('admin_menu', 'cbio_reorder_submenus', 999);