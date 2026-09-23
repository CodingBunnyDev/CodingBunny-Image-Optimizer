<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

include_once plugin_dir_path(__DIR__) . 'includes/image-convert.php';
include_once plugin_dir_path(__DIR__) . 'includes/image-resize.php';
include_once plugin_dir_path(__DIR__) . 'includes/image-thumbnail.php';
include_once plugin_dir_path(__DIR__) . 'includes/image-alt-generator.php';
include_once plugin_dir_path(__DIR__) . 'includes/image-exif.php';
include_once plugin_dir_path(__DIR__) . 'includes/image-lazyload.php';
include_once plugin_dir_path(__DIR__) . 'includes/fix-attachments.php';

function cbio_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$active_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'conversion';

	if ( isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' ) {
		check_admin_referer('coding_bunny_image_settings_update');
		$current_tab = isset($_POST['tab']) ? sanitize_text_field(wp_unslash($_POST['tab'])) : $active_tab;

		if ( $current_tab === 'conversion' ) {
			$convert_format     = isset( $_POST['convert_format'] ) ? sanitize_text_field( wp_unslash( $_POST['convert_format'] ) ) : 'webp';
			$enable_conversion  = isset( $_POST['enable_conversion'] ) ? '1' : '0';
			$quality_webp       = isset( $_POST['quality_webp'] ) ? absint( $_POST['quality_webp'] ) : 80;
			$quality_avif       = isset( $_POST['quality_avif'] ) ? absint( $_POST['quality_avif'] ) : 80;
			$remove_metadata_in = isset( $_POST['remove_metadata'] ) ? '1' : '0';
			$delete_original    = isset( $_POST['delete_original'] ) ? '1' : '0';
			$convert_method     = isset( $_POST['convert_method'] ) ? sanitize_text_field( wp_unslash($_POST['convert_method']) ) : 'server';
			if ( ! in_array( $convert_method, array('server','browser'), true ) ) {
				$convert_method = 'server';
			}

			update_option( 'cbio_convert_format', $convert_format );
			update_option( 'cbio_enable_conversion', $enable_conversion );
			update_option( 'cbio_quality_webp', $quality_webp );
			update_option( 'cbio_quality_avif', $quality_avif );
			update_option( 'cbio_remove_metadata', $remove_metadata_in );
			update_option( 'cbio_delete_original', $delete_original );
			update_option( 'cbio_convert_method', $convert_method );
		}
		elseif ( $current_tab === 'resizing' ) {
			$max_width                  = isset( $_POST['max_width'] ) ? absint( $_POST['max_width'] ) : 1000;
			$max_height                 = isset( $_POST['max_height'] ) ? absint( $_POST['max_height'] ) : 1000;
			$enable_resize              = isset( $_POST['enable_resize'] ) ? '1' : '0';
			$disable_big_image_threshold = isset( $_POST['disable_big_image_threshold'] ) ? '1' : '0';

			update_option( 'cbio_max_width', $max_width );
			update_option( 'cbio_max_height', $max_height );
			update_option( 'cbio_enable_resize', $enable_resize );
			update_option( 'cbio_disable_big_image_threshold', $disable_big_image_threshold );
		}
		elseif ( $current_tab === 'bulk' ) {
			$show_bulk_options_in = isset( $_POST['show_bulk_options'] ) ? '1' : '0';
			$batch_size_in        = isset( $_POST['batch_size'] ) ? absint( $_POST['batch_size'] ) : 5;
			$force_reoptimize = isset($_POST['cbio_force_reoptimize']) ? '1' : '0';

			update_option( 'cbio_show_bulk_options', $show_bulk_options_in );
			update_option( 'cbio_batch_size', $batch_size_in );
			update_option('cbio_force_reoptimize', $force_reoptimize);
		}
		elseif ( $current_tab === 'alttext' ) {
			$enable_auto_alt_text_in     = isset( $_POST['enable_auto_alt_text'] ) ? '1' : '0';
			$force_overwrite_alt_text_in = isset( $_POST['cbio_force_overwrite_alt_text'] ) ? '1' : '0';
			update_option( 'cbio_enable_auto_alt_text', $enable_auto_alt_text_in );
			update_option( 'cbio_force_overwrite_alt_text', $force_overwrite_alt_text_in );
			if ( isset($_POST['cbio_alt_text_template']) ) {
				$template = sanitize_text_field( wp_unslash($_POST['cbio_alt_text_template']) );
				update_option('cbio_alt_text_template', $template);
			}
		}
		elseif ( $current_tab === 'sizes' ) {
			$enabled_image_sizes = isset( $_POST['enabled_image_sizes'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['enabled_image_sizes'] ) ) : [];
			update_option( 'cbio_enabled_image_sizes', $enabled_image_sizes );
		}
		elseif ( $current_tab === 'lazy' ) {
			$enable_lazyload    = isset( $_POST['enable_lazyload'] ) ? '1' : '0';
			$enable_lcp_preload = isset( $_POST['enable_lcp_preload'] ) ? '1' : '0';

			update_option( 'cbio_enable_lazyload', $enable_lazyload );
			update_option( 'cbio_enable_lcp_preload', $enable_lcp_preload );
		}
	}

	$max_width                = get_option( 'cbio_max_width', 1000 );
	$max_height               = get_option( 'cbio_max_height', 1000 );
	$convert_format           = get_option( 'cbio_convert_format', 'webp' );
	$enable_resize            = get_option( 'cbio_enable_resize', '1' );
	$enable_conversion        = get_option( 'cbio_enable_conversion', '1' );
	$intermediate_sizes       = cbio_image_sizes();
	$enabled_image_sizes      = get_option( 'cbio_enabled_image_sizes', false );
	$using_gd                 = extension_loaded( 'gd' );
	$using_imagick            = extension_loaded( 'imagick' );
	$delete_original          = get_option( 'cbio_delete_original', '1' );
	$disable_big_image_threshold = get_option( 'cbio_disable_big_image_threshold', '0' );	
	$batch_size               = get_option( 'cbio_batch_size', '5' );
	$enable_auto_alt_text     = get_option( 'cbio_enable_auto_alt_text', '0' );
	$force_overwrite_alt_text = get_option( 'cbio_force_overwrite_alt_text', '0' );
	$remove_metadata          = get_option( 'cbio_remove_metadata', '0' );
	$show_bulk_options        = get_option( 'cbio_show_bulk_options', '1' );
	$enable_lcp_preload       = get_option( 'cbio_enable_lcp_preload', '0' );
	$enable_lazyload          = get_option( 'cbio_enable_lazyload', '0' );
	$alt_text_template        = get_option('cbio_alt_text_template', '{filename}');
	$convert_method           = get_option('cbio_convert_method', 'server');

	if ( ! is_array( $enabled_image_sizes ) ) {
		$enabled_image_sizes = array_keys( $intermediate_sizes );
	}
	?>
<div class="wrap cbio-dashboard">
		<h1 class="screen-reader-text">CodingBunny Image Optimizer</h1>
	<div class="cbio-header">
	<?php $logo_url = plugins_url( 'assets/images/cbio-logo.svg', dirname( __DIR__ ) . '/coding-bunny-image-optimizer.php' ); ?>
	<div class="cbio-header-left">
		<img src="<?php echo esc_url( $logo_url ); ?>"
			 alt="<?php echo esc_attr__( 'CodingBunny logo', 'coding-bunny-image-optimizer' ); ?>"
			 class="cbio-logo" />
		<div class="cbio-title">
			<p>
				<?php esc_html_e( 'CodingBunny Image Optimizer', 'coding-bunny-image-optimizer' ); ?>
				<span class="cbio-version">
					v<?php echo defined( 'CBIO_VERSION' ) ? esc_html( CBIO_VERSION ) : ''; ?>
				</span>
			</p>
		</div>
	</div>
</div>
		<div class="cbio-ic-wrap">
			<nav class="cbio-ic-tabs" aria-label="<?php esc_attr_e('Image Optimizer tabs', 'coding-bunny-image-optimizer'); ?>">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=coding-bunny-image-optimizer&tab=conversion' ) ); ?>" 
					class="cbio-ic-tab<?php echo ($active_tab === 'conversion') ? ' cbio-ic-tab-active' : ''; ?>">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e('Conversion', 'coding-bunny-image-optimizer'); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=coding-bunny-image-optimizer&tab=resizing' ) ); ?>" 
						class="cbio-ic-tab<?php echo ($active_tab === 'resizing') ? ' cbio-ic-tab-active' : ''; ?>">
					<span class="dashicons dashicons-editor-contract"></span>
						<?php esc_html_e('Resizing', 'coding-bunny-image-optimizer'); ?>
					</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=coding-bunny-image-optimizer&tab=sizes' ) ); ?>" 
					class="cbio-ic-tab<?php echo ($active_tab === 'sizes') ? ' cbio-ic-tab-active' : ''; ?>">
					<span class="dashicons dashicons-forms"></span>
					<?php esc_html_e('Image Sizes', 'coding-bunny-image-optimizer'); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=coding-bunny-image-optimizer&tab=bulk' ) ); ?>" 
					class="cbio-ic-tab<?php echo ($active_tab === 'bulk') ? ' cbio-ic-tab-active' : ''; ?>">
					<span class="dashicons dashicons-images-alt2"></span>
					<?php esc_html_e('Bulk Optimization', 'coding-bunny-image-optimizer'); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=coding-bunny-image-optimizer&tab=alttext' ) ); ?>"
					class="cbio-ic-tab<?php echo ($active_tab === 'alttext') ? ' cbio-ic-tab-active' : ''; ?>">
					<span class="dashicons dashicons-editor-spellcheck"></span>
					<?php esc_html_e('Alternative Text', 'coding-bunny-image-optimizer'); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=coding-bunny-image-optimizer&tab=lazy' ) ); ?>" 
					class="cbio-ic-tab<?php echo ($active_tab === 'lazy') ? ' cbio-ic-tab-active' : ''; ?>">
					<span class="dashicons dashicons-dashboard"></span>
					<?php esc_html_e('Lazy Load & Preload', 'coding-bunny-image-optimizer'); ?>
				</a>
			</nav>
			<div class="cbio-ic-content">
				<form method="post" action="">
					<?php wp_nonce_field( 'coding_bunny_image_settings_update' ); ?>
					<input type="hidden" name="tab" value="<?php echo esc_attr($active_tab); ?>">

					<?php if ( $active_tab === 'conversion' ): ?>
						<table class="cbio-form-table">
							<tr valign="top">
								<th scope="row"><label for="enable_conversion"><?php esc_html_e( 'Automatic Conversion', 'coding-bunny-image-optimizer' ); ?></label></th>
								<td>
									<label class="cbio-toggle-label">
										<input type="checkbox" class="cbio-toggle" id="enable_conversion" name="enable_conversion" value="1" <?php checked( $enable_conversion, '1' ); ?> />
										<span class="cbio-slider"></span>
										<?php esc_html_e( 'Images uploaded to your site are automatically converted to the WebP or AVIF format.', 'coding-bunny-image-optimizer' ); ?>
									</label>
								</td>
							</tr>
							<tr valign="top">
								<th scope="row">
									<label for="convert_method_server"><?php esc_html_e('Conversion Engine', 'coding-bunny-image-optimizer'); ?></label>
								</th>
								<td>
									<div class="cbio-radio-button-wrapper">
										<input type="radio" id="convert_method_server" name="convert_method" value="server" <?php checked( $convert_method, 'server' ); ?> />
										<label for="convert_method_server" class="cbio-radio-label">
											<span class="dashicons dashicons-database"></span>
											<?php esc_html_e('Server', 'coding-bunny-image-optimizer'); ?>
										</label>
									</div>
									<div class="cbio-radio-button-wrapper">
										<input type="radio" id="convert_method_browser" name="convert_method" value="browser" <?php checked( $convert_method, 'browser' ); ?> />
										<label for="convert_method_browser" class="cbio-radio-label">
											<span class="dashicons dashicons-admin-site-alt3"></span>
											<?php esc_html_e('Browser', 'coding-bunny-image-optimizer'); ?>
										</label>
									</div>
									<p class="cbio-notes">
										<?php esc_html_e('Choose whether to automatically convert images via server or browser.', 'coding-bunny-image-optimizer'); ?>
									</p>
								</td>													
							</tr>
							<tr valign="top">
								<td colspan="2">
									<div class="cbio-info">
										<?php esc_html_e('INFO: Browser mode reduces the load on the server, but requires that the browser supports conversion to WebP. Currently supported browsers are: Chrome, Firefox, Edge, and Opera.' , 'coding-bunny-image-optimizer'); ?>
									</div>
									<div class="cbio-warning">
										<?php esc_html_e('WARNING: Browser conversion works only when you upload new images. To convert the ones already in your library, please use the server mode.' , 'coding-bunny-image-optimizer'); ?>
									</div>
								</td>
							</tr>
							<tr valign="top">
								<th scope="row">
									<label for="convert_format_webp"><?php esc_html_e( 'Conversion Format', 'coding-bunny-image-optimizer' ); ?></label>
								</th>
								<td>
									<div class="cbio-radio-button-wrapper">
										<input type="radio" id="convert_format_webp" name="convert_format" value="webp" <?php checked( $convert_format, 'webp' ); ?> />
										<label for="convert_format_webp" class="cbio-radio-label"><?php esc_html_e( 'WebP', 'coding-bunny-image-optimizer' ); ?></label>
									</div>
									<div class="cbio-radio-button-wrapper">
										<input type="radio" id="convert_format_avif" name="convert_format" value="avif" <?php checked( $convert_format, 'avif' ); ?> />
										<label for="convert_format_avif" class="cbio-radio-label"><?php esc_html_e( 'AVIF', 'coding-bunny-image-optimizer' ); ?></label>
									</div>
									<p class="cbio-notes">
										<?php esc_html_e('Set the conversion format between WebP and AVIF. Only JPEG, PNG or GIF images will be converted.', 'coding-bunny-image-optimizer'); ?>
									</p>
								</td>
							</tr>
							<tr valign="top">
								<th scope="row"><label for="quality_webp"><?php esc_html_e( 'WEBP Quality', 'coding-bunny-image-optimizer' ); ?></label></th>
								<td>
									<?php $quality_webp = get_option('cbio_quality_webp', 80); ?>
									<input type="radio" id="quality_webp_lossy" name="quality_webp_preset" value="60" class="cbio-quality-checkbox" <?php checked( $quality_webp, 60 ); ?> />
									<label for="quality_webp_lossy" class="cbio-radio-label"><?php esc_html_e( 'Lossy', 'coding-bunny-image-optimizer' ); ?></label>

									<input type="radio" id="quality_webp_glossy" name="quality_webp_preset" value="80" class="cbio-quality-checkbox" <?php checked( $quality_webp, 80 ); ?> />
									<label for="quality_webp_glossy" class="cbio-radio-label"><?php esc_html_e( 'Glossy', 'coding-bunny-image-optimizer' ); ?></label>

									<input type="radio" id="quality_webp_lossless" name="quality_webp_preset" value="100" class="cbio-quality-checkbox" <?php checked( $quality_webp, 100 ); ?> />
									<label for="quality_webp_lossless" class="cbio-radio-label"><?php esc_html_e( 'Lossless', 'coding-bunny-image-optimizer' ); ?></label>

									<input type="number" id="quality_webp" name="quality_webp" min="10" max="100"
       value="<?php echo esc_attr( $quality_webp ); ?>" />
								</td>
							</tr>
							<tr valign="top">
								<th scope="row"><label for="quality_avif"><?php esc_html_e( 'AVIF Quality', 'coding-bunny-image-optimizer' ); ?></label></th>
								<td>
									<?php $quality_avif = get_option('cbio_quality_avif', 60); ?>
									<input type="radio" id="quality_avif_lossy" name="quality_avif_preset" value="60" class="cbio-quality-checkbox" <?php checked( $quality_avif, 60 ); ?> />
									<label for="quality_avif_lossy" class="cbio-radio-label"><?php esc_html_e( 'Lossy', 'coding-bunny-image-optimizer' ); ?></label>

									<input type="radio" id="quality_avif_glossy" name="quality_avif_preset" value="80" class="cbio-quality-checkbox" <?php checked( $quality_avif, 80 ); ?> />
									<label for="quality_avif_glossy" class="cbio-radio-label"><?php esc_html_e( 'Glossy', 'coding-bunny-image-optimizer' ); ?></label>

									<input type="radio" id="quality_avif_lossless" name="quality_avif_preset" value="100" class="cbio-quality-checkbox" <?php checked( $quality_avif, 100 ); ?> />
									<label for="quality_avif_lossless" class="cbio-radio-label"><?php esc_html_e( 'Lossless', 'coding-bunny-image-optimizer' ); ?></label>

									<input type="number" id="quality_avif" name="quality_avif" min="10" max="100"
       value="<?php echo esc_attr( $quality_avif ); ?>" />
									<p class="cbio-notes"><?php esc_html_e('Choose the level of compression that suits your needs. ', 'coding-bunny-image-optimizer'); ?></p>
								</td>							
							</tr>
							<tr valign="top">
								<th scope="row"><label for="remove_metadata"><?php esc_html_e( 'Remove Metadata', 'coding-bunny-image-optimizer' ); ?></label></th>
								<td>
									<label class="cbio-toggle-label <?php echo ( ! $using_imagick ) ? 'cbio-disabled-label' : ''; ?>">
										<input type="checkbox" class="cbio-toggle" id="remove_metadata" name="remove_metadata" value="1"
										<?php checked( $remove_metadata, '1' ); ?>
										<?php disabled( ! $using_imagick ); ?>
										/>
										<span class="cbio-slider"></span>
										<?php esc_html_e( 'Automatically remove unnecessary metadata from images (Requires Imagick).', 'coding-bunny-image-optimizer' ); ?>
									</label>
									<p class="cbio-notes" style="margin-top:6px;">
										<?php esc_html_e('This data adds to the size of the image. While this information might be important to photographers, it’s unnecessary for most users and safe to remove.', 'coding-bunny-image-optimizer'); ?>
									</p>
								</td>
							</tr>
							<tr valign="top">
								<th scope="row"><label for="delete_original"><?php esc_html_e('Delete Original Images', 'coding-bunny-image-optimizer'); ?></label></th>
								<td>
									<label class="cbio-toggle-label">
										<input type="checkbox" class="cbio-toggle" id="delete_original" name="delete_original" value="1" <?php checked( $delete_original, '1' ); ?> />
										<span class="cbio-slider"></span>
										<?php esc_html_e('Delete the original images after conversion.', 'coding-bunny-image-optimizer'); ?>
									</label>
									<p class="cbio-notes"><?php esc_html_e('Disable this option to keep a copy of the original images so that you can restore them at any time. Note: Keeping a copy of the original images can significantly increase disk usage.', 'coding-bunny-image-optimizer'); ?></p>
								</td>
							</tr>
						</table>
						<div class="cbio-warning">
							<?php esc_html_e('WARNING: Before deleting any images, make sure you have made a complete backup of your site and files. Deletion is irreversible.', 'coding-bunny-image-optimizer'); ?>
						</div>
						<?php submit_button( esc_html__('Save Settings', 'coding-bunny-image-optimizer') ); ?>

					<?php elseif ( $active_tab === 'resizing' ): ?>
						<table class="cbio-form-table">
							<tr valign="top">
								<th scope="row"><label for="enable_resize"><?php esc_html_e( 'Automatic Resizing', 'coding-bunny-image-optimizer' ); ?></label></th>
								<td>
									<label class="cbio-toggle-label">
										<input type="checkbox" class="cbio-toggle" id="enable_resize" name="enable_resize" value="1" <?php checked( $enable_resize, '1' ); ?> />
										<span class="cbio-slider"></span>
										<?php esc_html_e( 'Images uploaded to your site are automatically resized.', 'coding-bunny-image-optimizer' ); ?>
									</label>
								</td>
							</tr>
							<tr valign="top">
								<th scope="row">
									<label for="max_width"><?php esc_html_e( 'Max. width (px)', 'coding-bunny-image-optimizer' ); ?></label>
								</th>
								<td>
									<input type="number" id="max_width" name="max_width" value="<?php echo esc_attr( $max_width ); ?>" min="0" />
								</td>
							</tr>
							<tr valign="top">
								<th scope="row">
									<label for="max_height"><?php esc_html_e( 'Max. height (px)', 'coding-bunny-image-optimizer' ); ?></label>
								</th>
								<td>
									<input type="number" id="max_height" name="max_height" value="<?php echo esc_attr( $max_height ); ?>" min="0" />
									<p class="cbio-notes"><?php esc_html_e('Set maximum dimensions for height and width of your images.', 'coding-bunny-image-optimizer'); ?></p>
								</td>
							</tr>
							<tr valign="top">
								<th scope="row"><label for="disable_big_image_threshold"><?php esc_html_e('Disable Big Image Threshold', 'coding-bunny-image-optimizer'); ?></label></th>
								<td>
									<label class="cbio-toggle-label">
										<input type="checkbox" class="cbio-toggle" id="disable_big_image_threshold" name="disable_big_image_threshold" value="1" <?php checked($disable_big_image_threshold, '1'); ?> />
										<span class="cbio-slider"></span>
										<?php esc_html_e('As of WordPress 5.3, large uploaded images are resized to a maximum width and height of 2560px. If larger images are desired, enable this setting.', 'coding-bunny-image-optimizer'); ?>
									</label>
								</td>
							</tr>
						</table>
						<?php submit_button( esc_html__('Save Settings', 'coding-bunny-image-optimizer') ); ?>

					<?php elseif ( $active_tab === 'sizes' ): ?>
						<div class="cbio-info">
							<?php esc_html_e('INFO: WordPress generates multiple thumbnails for each uploaded image. Choose which of these thumbnails you want to include when the images are optimized.', 'coding-bunny-image-optimizer'); ?>
						</div>
						<table class="cbio-form-table">
							<tr valign="top">
								<th scope="row"><?php esc_html_e( 'Image Sizes', 'coding-bunny-image-optimizer' ); ?></th>
								<td>
									<?php
									foreach ( $intermediate_sizes as $size => $data ) {
										$width  = isset( $data['width'] ) ? $data['width'] : '';
										$height = isset( $data['height'] ) ? $data['height'] : '';
										?>
										<label class="cbio-toggle-label">
											<input type="checkbox" name="enabled_image_sizes[]" value="<?php echo esc_attr( $size ); ?>"
											<?php checked( in_array( $size, $enabled_image_sizes, true ), true ); ?>
											class="cbio-toggle" />
											<span class="cbio-slider"></span>
											<span class="cbio-toggle-label"><?php echo esc_html( $size ); ?> (<?php echo esc_html( $width . ' x ' . $height . ' px' ); ?>)</span>
										</label>
										<br>
										<?php
									}
									?>
								</td>
							</tr>
						</table>
						<?php submit_button( esc_html__('Save Settings', 'coding-bunny-image-optimizer') ); ?>

					<?php elseif ( $active_tab === 'bulk' ): ?>
						<table class="cbio-form-table">
							<tr valign="top">
								<th scope="row"><label for="show_bulk_toolbar"><?php esc_html_e( 'Bulk Action', 'coding-bunny-image-optimizer' ); ?></label></th>
								<td>
									<label class="cbio-toggle-label">
										<input type="checkbox" class="cbio-toggle" id="show_bulk_options" name="show_bulk_options" value="1"
										<?php checked( $show_bulk_options, '1' ); ?> />
										<span class="cbio-slider"></span>
										<?php esc_html_e( 'Enable "Optimize Selected Images" in the media library bulk actions menu.', 'coding-bunny-image-optimizer' ); ?>
									</label>
									<p class="cbio-notes"><?php esc_html_e('This option allows you to optimize several selected images in the media library at the same time.', 'coding-bunny-image-optimizer'); ?></p>
								</td>
							</tr>
							<tr valign="top">
    <th scope="row">
        <label for="cbio_force_reoptimize"><?php esc_html_e('Force Re-Optimization', 'coding-bunny-image-optimizer'); ?></label>
    </th>
    <td>
        <label class="cbio-toggle-label">
            <input type="checkbox" class="cbio-toggle" id="cbio_force_reoptimize" name="cbio_force_reoptimize" value="1" <?php checked(get_option('cbio_force_reoptimize', '0'), '1'); ?> />
            <span class="cbio-slider"></span>
            <?php esc_html_e('Optimize images even if already in WebP or AVIF format', 'coding-bunny-image-optimizer'); ?>
        </label>
        <p class="cbio-notes"><?php esc_html_e('If checked, bulk optimization will convert also images that have already been optimized.', 'coding-bunny-image-optimizer'); ?></p>
    </td>
</tr>
							<tr valign="top">
								<th scope="row"><label for="batch_size"><?php esc_html_e( 'Images Batch Size', 'coding-bunny-image-optimizer' ); ?></label></th>
								<td>
									<div class="cbio-field-group">
	<input type="number" id="batch_size" name="batch_size" min="1" max="100" step="1"
	       value="<?php echo esc_attr( (int) $batch_size ); ?>" />
										<p class="cbio-notes"><?php esc_html_e('Number of images to process at once when optimizing images. Lower values are safer but slower, higher values are faster but may cause timeouts on limited hosting.', 'coding-bunny-image-optimizer'); ?></p>
									</div>
								</td>
							</tr>
							<tr valign="top">
								<th scope="row">
									<label for="cbio_optimize_all_images">
										<?php esc_html_e('Bulk Image Optimization', 'coding-bunny-image-optimizer'); ?>
									</label>
								</th>
								<td>
									<button type="button" id="cbio_optimize_all_images" 
									class="button button-primary">
									<?php esc_html_e('Optimize All Images', 'coding-bunny-image-optimizer'); ?>
								</button>
								<span id="cbio_optimize_all_images_status" style="margin-left:10px;"></span>
								<div id="cbio_optimize_progress" style="margin-top:10px; display:none;">
									<div style="background:#f1f1f1; border-radius:10px; padding:3px;">
										<div id="cbio_optimize_progress_bar" style="background:#33a854; height:20px; border-radius:7px; width:0%; transition:width 0.3s;"></div>
									</div>
									<p id="cbio_optimize_progress_text" style="margin:5px 0 0 0; font-size:12px;"></p>
								</div>
								<p class="cbio-notes" style="margin-top:6px;">
									<?php esc_html_e('Click to optimize all images in the media library. This process may take some time depending on the number of images.', 'coding-bunny-image-optimizer'); ?>
								</p>
							</td>
						</tr>
						<tr valign="top">
							<th scope="row">
								<label for="cbio_fix_attachments">
									<?php esc_html_e('Fix Image Attachments', 'coding-bunny-image-optimizer'); ?>
								</label>
							</th>
							<td>
								<button type="button" id="cbio_fix_attachments"
								class="button button-primary">
								<?php esc_html_e('Fix Image Attachments', 'coding-bunny-image-optimizer'); ?>
							</button>
							<span id="cbio_fix_attachments_result" style="margin-left:10px;"></span>
							<div id="cbio_fix_attachments_progress" style="margin-top:10px; display:none;">
								<div style="background:#f1f1f1; border-radius:10px; padding:3px;">
									<div id="cbio_fix_attachments_progress_bar" style="background:#33a854; height:20px; border-radius:7px; width:0%; transition:width 0.3s;"></div>
								</div>
								<p id="cbio_fix_attachments_progress_text" style="margin:5px 0 0 0; font-size:12px;"></p>
							</div>

							<p class="cbio-notes" style="margin-top:6px;">
								<?php esc_html_e('Click to fix all image attachments in the media library. This process may take some time depending on the number of images.', 'coding-bunny-image-optimizer'); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( esc_html__('Save Settings', 'coding-bunny-image-optimizer') ); ?>

			<?php elseif ( $active_tab === 'alttext' ): ?>
				<div class="cbio-info">
					<?php esc_html_e('The alt text is generated based on a customizable template. You can use the following tags:', 'coding-bunny-image-optimizer'); ?>
					<ul style="margin:6px 0 0 1.5em;">
						<li><code>{post_title}</code> – <?php esc_html_e('Title of the post/page/product where the image is inserted or attached', 'coding-bunny-image-optimizer'); ?></li>
						<li><code>{title}</code> – <?php esc_html_e('Image title', 'coding-bunny-image-optimizer'); ?></li>
						<li><code>{caption}</code> – <?php esc_html_e('Image caption', 'coding-bunny-image-optimizer'); ?></li>
						<li><code>{filename}</code> – <?php esc_html_e('Formatted file name', 'coding-bunny-image-optimizer'); ?></li>
						<li><code>{site_title}</code> – <?php esc_html_e('Site name', 'coding-bunny-image-optimizer'); ?></li>
						<li><code>{sku}</code> – <?php esc_html_e('Product SKU (WooCommerce required)', 'coding-bunny-image-optimizer'); ?></li>
					</ul>
					<?php esc_html_e('Example: Photo of {post_title} - {site_title}', 'coding-bunny-image-optimizer'); ?>
				</div>
				<table class="cbio-form-table">
					<tr valign="top">
						<th scope="row">
							<label for="cbio_alt_text_template"><?php esc_html_e('Alt Text Template', 'coding-bunny-image-optimizer'); ?></label>
						</th>
						<td>
							<input type="text" style="width: 100%; max-width: 400px;" id="cbio_alt_text_template" name="cbio_alt_text_template"
							value="<?php echo esc_attr($alt_text_template); ?>" />
							<p class="cbio-notes" style="margin-top:6px;">
								<?php esc_html_e('Customize the alt text template using the tags above.', 'coding-bunny-image-optimizer'); ?>
							</p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label for="enable_auto_alt_text"><?php esc_html_e('Auto-generate Alt Text on Upload', 'coding-bunny-image-optimizer'); ?></label>
						</th>
						<td>
							<label class="cbio-toggle-label">
								<input type="checkbox" class="cbio-toggle" id="enable_auto_alt_text" name="enable_auto_alt_text" value="1"
								<?php checked( $enable_auto_alt_text, '1' ); ?> />
								<span class="cbio-slider"></span>
								<?php esc_html_e("Automatically generate alt text when uploading new images.", 'coding-bunny-image-optimizer'); ?>
							</label>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label for="cbio_force_overwrite_alt_text"><?php esc_html_e('Force Overwrite Existing Alt Text', 'coding-bunny-image-optimizer'); ?></label>
						</th>
						<td>
							<label class="cbio-toggle-label">
								<input type="checkbox" class="cbio-toggle" id="cbio_force_overwrite_alt_text" name="cbio_force_overwrite_alt_text" value="1"
								<?php checked( $force_overwrite_alt_text, '1' ); ?> />
								<span class="cbio-slider"></span>
								<?php esc_html_e("If enabled, existing alt texts will always be overwritten by the generator.", 'coding-bunny-image-optimizer'); ?>
							</label>
							<p class="cbio-notes" style="margin-top:6px;">
								<?php esc_html_e('Warning: This will replace current alt texts for all processed images.', 'coding-bunny-image-optimizer'); ?>
							</p>
						</td>
					</tr>
					<tr valign="top">
						<th scope="row">
							<label for="cbio_generate_bulk_alt_text">
								<?php esc_html_e('Bulk Alt Text Generation', 'coding-bunny-image-optimizer'); ?>
							</label>
						</th>
						<td>
							<button type="button" id="cbio_generate_bulk_alt_text"
							class="button button-primary">
							<?php esc_html_e('Generate Alt Text', 'coding-bunny-image-optimizer'); ?>
						</button>
						<span id="cbio_generate_bulk_alt_text_status" style="margin-left:10px;"></span>
						<div id="cbio_generate_bulk_alt_text_progress" style="margin-top:10px; display:none;">
							<div style="background:#f1f1f1; border-radius:10px; padding:3px;">
								<div id="cbio_generate_bulk_alt_text_progress_bar" style="background:#33a854; height:20px; border-radius:7px; width:0%; transition:width 0.3s;"></div>
							</div>
							<p id="cbio_generate_bulk_alt_text_progress_text" style="margin:5px 0 0 0; font-size:12px;"></p>
						</div>
						<p class="cbio-notes" style="margin-top:6px;">
							<?php esc_html_e('Automatically generate alt text for all images without it, using the selected template.', 'coding-bunny-image-optimizer'); ?>
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button( esc_html__('Save Settings', 'coding-bunny-image-optimizer') ); ?>

		<?php elseif ( $active_tab === 'lazy' ): ?>
			<table class="cbio-form-table">
				<tr valign="top">
					<th scope="row"><label for="enable_lazyload"><?php esc_html_e( 'Lazy Loading', 'coding-bunny-image-optimizer' ); ?></label></th>
					<td>
						<label class="cbio-toggle-label">
							<input type="checkbox" class="cbio-toggle" id="enable_lazyload" name="enable_lazyload" value="1" <?php checked( $enable_lazyload, '1' ); ?> />
							<span class="cbio-slider"></span>
							<?php esc_html_e( 'Your images load only when they are visible to the user.', 'coding-bunny-image-optimizer' ); ?>
						</label>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row"><label for="enable_lcp_preload"><?php esc_html_e('Preload Critical Images', 'coding-bunny-image-optimizer'); ?></label></th>
					<td>
						<label class="cbio-toggle-label">
							<input type="checkbox" class="cbio-toggle" id="enable_lcp_preload" name="enable_lcp_preload" value="1" <?php checked($enable_lcp_preload, '1'); ?> />
							<span class="cbio-slider"></span>
							<?php esc_html_e('Preload the main image for better LCP and Core Web Vitals scores.', 'coding-bunny-image-optimizer'); ?>
						</label>
					</td>
				</tr>
			</table>
			<div class="cbio-info">
				<?php esc_html_e('INFO: Images that are "above the fold" (i.e., immediately visible when the page loads) will NOT be lazy loaded.', 'coding-bunny-image-optimizer'); ?>
			</div>
			<?php submit_button( esc_html__('Save Settings', 'coding-bunny-image-optimizer') ); ?>

		<?php endif; ?>
	</form>
</div>
</div>
</div>
<?php
}

add_action('admin_enqueue_scripts', function($hook) {
if (strpos($hook, 'coding-bunny-image-optimizer') !== false) {
wp_enqueue_script(
'cbio-scripts',
plugin_dir_url(__DIR__) . 'assets/js/cbio-scripts.js',
[],
'1.0',
true
);
wp_enqueue_script(
'cbio-alt-generator',
plugin_dir_url(__DIR__) . 'assets/js/image-alt-generator.js',
[],
'1.0',
true
);
wp_enqueue_script(
'cbio-fix-attachments-js',
plugin_dir_url(__DIR__) . 'assets/js/fix-attachments.js',
[],
'1.0',
true
);

wp_localize_script('cbio-alt-generator', 'cbioAltTextAjax', [
'nonce' => wp_create_nonce('cbio_generate_bulk_alt_text'),
'i18n'  => [
'confirm'    => __('Generate alt text for all images that do not have it?', 'coding-bunny-image-optimizer'),
'generating' => __('Generating...', 'coding-bunny-image-optimizer'),
'generated'  => __('Alt text generated for', 'coding-bunny-image-optimizer'),
'images'     => __('images.', 'coding-bunny-image-optimizer'),
'error'      => __('Error generating alt text.', 'coding-bunny-image-optimizer'),
]
]);

$show_bulk_options = get_option('cbio_show_bulk_options', '1') === '1';

wp_localize_script('cbio-scripts', 'cb_bulk_options_data', [
'ajax_url'             => admin_url('admin-ajax.php'),
'nonce'                => wp_create_nonce('convert_webp_nonce'),
'batch_size'           => (int) get_option('cbio_batch_size', 5),
'convert_format'       => get_option('cbio_convert_format', 'webp'),
'delete_original'      => get_option('cbio_delete_original', '0'),
'enable_auto_alt_text' => get_option('cbio_enable_auto_alt_text', '0') === '1',
'show_bulk_options'    => $show_bulk_options,
'optimize_label'       => __('Optimize Selected Images', 'coding-bunny-image-optimizer'),
]);

wp_localize_script('cbio-fix-attachments-js', 'cbioFixAttachments', [
'ajax_url' => admin_url('admin-ajax.php'),
'nonce'    => wp_create_nonce('cbio_fix_attachments')
]);
}

$convert_method = get_option('cbio_convert_method', 'server');
if ($convert_method === 'browser') {
$allowed = ['upload.php', 'media-new.php', 'post.php', 'post-new.php'];
if (in_array($hook, $allowed, true)) {
$js_file_path = plugin_dir_path(__DIR__) . 'assets/js/browser-convert.js';
if (file_exists($js_file_path)) {
	$js_file_url = plugin_dir_url(__DIR__) . 'assets/js/browser-convert.js';
	wp_enqueue_script(
	'cbio-browser-convert',
	$js_file_url,
	['jquery', 'plupload-all', 'media-views'],
	filemtime($js_file_path),
	true
);

wp_localize_script('cbio-browser-convert', 'cbioBrowserConvertData', [
	'method'            => $convert_method,
	'format'            => get_option('cbio_convert_format', 'webp'),
	'quality_webp'      => (int) get_option('cbio_quality_webp', 80),
	'quality_avif'      => (int) get_option('cbio_quality_avif', 80),
	'enable_conversion' => get_option('cbio_enable_conversion', '1'),
	'force_reoptimize' => get_option('cbio_force_reoptimize', '0') === '1',
	'fallback_server'   => true,
	'i18n'              => [
	'preparing'   => __('Preparing images...', 'coding-bunny-image-optimizer'),
	'converted'   => __('Converted in browser', 'coding-bunny-image-optimizer'),
	'skipped'     => __('Skipped (not convertible)', 'coding-bunny-image-optimizer'),
	'failed'      => __('Browser conversion failed, using original.', 'coding-bunny-image-optimizer'),
	'unsupported' => __('Format not supported in this browser, using original.', 'coding-bunny-image-optimizer'),
],
]);
}
}
}
});