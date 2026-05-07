<?php
/**
 * Plugin Name: Silvertell WooCommerce Customisations
 * Description: Custom modifications for WooCommerce, including dynamic file sideloading during CSV imports, custom product tabs, and native repeater fields.
 * Version: 2.0.0
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 * Text Domain: silvertell-wc-customisation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Silvertell_Woocommerce_Customisation
 * * Encapsulates custom WooCommerce modifications, including dynamic file sideloading during CSV imports,
 * administrative settings interfaces, and bespoke product data tabs with repeater functionalities.
 */
class Silvertell_Woocommerce_Customisation {

	/**
	 * Constructor.
	 * * Initializes the class and registers all necessary WordPress and WooCommerce hooks.
	 */
	public function __construct() {
		$this->register_hooks();
	}

	/**
	 * Registers all action and filter hooks for the customisations.
	 * * @return void
	 */
	private function register_hooks() {
		// CSV Import Interception
		add_filter( 'woocommerce_product_import_pre_insert_product_object', [ $this, 'intercept_meta_for_sideload' ], 10, 2 );

		// Settings Page
		add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );

		// Custom Product Data Tabs UI
		add_filter( 'woocommerce_product_data_tabs', [ $this, 'add_custom_product_data_tabs' ] );
		add_action( 'woocommerce_product_data_panels', [ $this, 'render_custom_product_data_panels' ] );
		add_action( 'woocommerce_process_product_meta', [ $this, 'save_custom_product_data' ] );

		// Enqueue scripts and styles for the repeater fields
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_repeater_assets' ] );
	}

	/**
	 * Adds a custom submenu page under the main WooCommerce admin menu.
	 * * @return void
	 */
	public function add_settings_page() {
		add_submenu_page(
			'woocommerce',
			'File Import Settings',
			'File Import Settings',
			'manage_woocommerce',
			'silvertell-file-importer',
			[ $this, 'render_settings_page' ]
		);
	}

	/**
	 * Registers the plugin settings using the WordPress Settings API.
	 * * @return void
	 */
	public function register_settings() {
		register_setting( 'silvertell_file_importer_group', 'silvertell_file_meta_keys' );
	}

	/**
	 * Renders the HTML frontend for the custom settings page in the WordPress admin.
	 * * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$current_keys = get_option( 'silvertell_file_meta_keys', '_manual' );
		?>
		<div class="wrap">
			<h1>WooCommerce File Importer Settings</h1>
			<p>Specify which meta keys the importer should scan for file URLs. The system will automatically download these files (PDFs, Images, Docs, etc.) to the Media Library and replace the URL with the integer Attachment ID.</p>
			
			<form method="post" action="options.php">
				<?php settings_fields( 'silvertell_file_importer_group' ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="silvertell_file_meta_keys">Target Meta Keys</label></th>
							<td>
								<input type="text" id="silvertell_file_meta_keys" name="silvertell_file_meta_keys" value="<?php echo esc_attr( $current_keys ); ?>" class="regular-text" style="width: 100%; max-width: 500px;">
								<p class="description">Enter comma-separated meta keys. Example: <code>_manual, _datasheet</code>. Note: <code>_document_file_X</code> and <code>_gallery_image_X</code> are handled automatically.</p>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button( 'Save Options' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Adds custom tabs to the WooCommerce Product Data meta box.
	 * * @param array $tabs Existing WooCommerce product data tabs.
	 * @return array Modified array of tabs.
	 */
	public function add_custom_product_data_tabs( $tabs ) {
		$tabs['buy_samples'] = [
			'label'    => __( 'Buy Samples', 'silvertell-wc-customisation' ),
			'target'   => 'silvertell_buy_samples_data',
			'class'    => [ 'show_if_simple', 'show_if_variable' ],
			'priority' => 80,
		];
		$tabs['documents'] = [
			'label'    => __( 'Documents', 'silvertell-wc-customisation' ),
			'target'   => 'silvertell_documents_data',
			'class'    => [ 'show_if_simple', 'show_if_variable' ],
			'priority' => 81,
		];
		$tabs['features'] = [
			'label'    => __( 'Features', 'silvertell-wc-customisation' ),
			'target'   => 'silvertell_features_data',
			'class'    => [ 'show_if_simple', 'show_if_variable' ],
			'priority' => 82,
		];
		return $tabs;
	}

	/**
	 * Renders the HTML panels for the custom product data tabs.
	 * * @return void
	 */
	public function render_custom_product_data_panels() {
		global $post;

		// Tab 1: Buy Samples (Standard URL Fields)
		echo '<div id="silvertell_buy_samples_data" class="panel woocommerce_options_panel">';
		woocommerce_wp_text_input( [
			'id'    => '_farnel_url',
			'label' => __( 'Farnell URL', 'silvertell-wc-customisation' ),
			'type'  => 'url',
		] );
		woocommerce_wp_text_input( [
			'id'    => '_mouser_url',
			'label' => __( 'Mouser URL', 'silvertell-wc-customisation' ),
			'type'  => 'url',
		] );
		woocommerce_wp_text_input( [
			'id'    => '_digikey_url',
			'label' => __( 'Digikey URL', 'silvertell-wc-customisation' ),
			'type'  => 'url',
		] );
		echo '</div>';

		// Tab 2: Documents (Repeater)
		echo '<div id="silvertell_documents_data" class="panel woocommerce_options_panel dd-repeater-wrapper">';
		echo '<div class="options_group"><p class="form-field"><strong>' . __( 'Product Documents', 'silvertell-wc-customisation' ) . '</strong></p>';
		echo '<div class="dd-repeater-container" data-type="documents">';
		
		$i = 1;
		while ( get_post_meta( $post->ID, '_document_name_' . $i, true ) || get_post_meta( $post->ID, '_document_file_' . $i, true ) ) {
			$name_val = get_post_meta( $post->ID, '_document_name_' . $i, true );
			$file_val = get_post_meta( $post->ID, '_document_file_' . $i, true );
			
			// Display filename if it's an attachment ID
			$display_file = is_numeric($file_val) ? wp_get_attachment_url($file_val) : $file_val;

			$this->render_document_row( $name_val, $display_file, $file_val );
			$i++;
		}
		
		// If empty, render one blank row
		if ( $i === 1 ) {
			$this->render_document_row();
		}
		
		echo '</div>';
		echo '<button type="button" class="button dd-add-row">' . __( 'Add Document', 'silvertell-wc-customisation' ) . '</button>';
		echo '</div></div>';

		// Tab 3: Features (Repeater)
		echo '<div id="silvertell_features_data" class="panel woocommerce_options_panel dd-repeater-wrapper">';
		echo '<div class="options_group"><p class="form-field"><strong>' . __( 'Product Features', 'silvertell-wc-customisation' ) . '</strong></p>';
		echo '<div class="dd-repeater-container" data-type="features">';
		
		$j = 1;
		while ( get_post_meta( $post->ID, '_featured_' . $j, true ) ) {
			$feature_val = get_post_meta( $post->ID, '_featured_' . $j, true );
			$this->render_feature_row( $feature_val );
			$j++;
		}
		
		// If empty, render one blank row
		if ( $j === 1 ) {
			$this->render_feature_row();
		}
		
		echo '</div>';
		echo '<button type="button" class="button dd-add-row">' . __( 'Add Feature', 'silvertell-wc-customisation' ) . '</button>';
		echo '</div></div>';
	}

	/**
	 * Helper function to render a single row for the Documents repeater.
	 */
	private function render_document_row( $name = '', $display_file = '', $raw_file = '' ) {
		?>
		<div class="dd-repeater-row">
			<div class="dd-repeater-header">
				<span class="dashicons dashicons-menu dd-drag-handle"></span>
				<span class="dd-row-title"><?php echo $name ? esc_html($name) : 'New Document'; ?></span>
				<div class="dd-repeater-actions">
					<span class="dashicons dashicons-arrow-down-alt2 dd-collapse-row"></span>
					<span class="dashicons dashicons-admin-page dd-duplicate-row" title="Duplicate"></span>
					<span class="dashicons dashicons-trash dd-delete-row" title="Delete"></span>
				</div>
			</div>
			<div class="dd-repeater-content">
				<p class="form-field">
					<label>Document Name</label>
					<input type="text" name="dd_doc_names[]" class="short dd-bind-title" value="<?php echo esc_attr( $name ); ?>" />
				</p>
				<p class="form-field">
					<label>Document File URL/ID</label>
					<input type="text" name="dd_doc_files[]" class="short" value="<?php echo esc_attr( $raw_file ); ?>" />
					<?php if ( $display_file && $display_file !== $raw_file ) echo '<span class="description">Current File: <a href="'.esc_url($display_file).'" target="_blank">View File</a></span>'; ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Helper function to render a single row for the Features repeater.
	 */
	private function render_feature_row( $feature = '' ) {
		?>
		<div class="dd-repeater-row">
			<div class="dd-repeater-header">
				<span class="dashicons dashicons-menu dd-drag-handle"></span>
				<span class="dd-row-title"><?php echo $feature ? esc_html( wp_trim_words( $feature, 5 ) ) : 'New Feature'; ?></span>
				<div class="dd-repeater-actions">
					<span class="dashicons dashicons-arrow-down-alt2 dd-collapse-row"></span>
					<span class="dashicons dashicons-admin-page dd-duplicate-row" title="Duplicate"></span>
					<span class="dashicons dashicons-trash dd-delete-row" title="Delete"></span>
				</div>
			</div>
			<div class="dd-repeater-content">
				<p class="form-field">
					<label>Feature Text</label>
					<textarea name="dd_feature_texts[]" class="short dd-bind-title"><?php echo esc_textarea( $feature ); ?></textarea>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Saves the custom product data meta fields and rebuilds the numeric discrete keys required for CSV compatibility.
	 * * @param int $post_id The ID of the post being saved.
	 * @return void
	 */
	public function save_custom_product_data( $post_id ) {
		// Save simple URL fields
		$url_fields = [ '_farnel_url', '_mouser_url', '_digikey_url' ];
		foreach ( $url_fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_post_meta( $post_id, $field, sanitize_url( $_POST[ $field ] ) );
			}
		}

		// Rebuild Documents Data (_document_name_1, _document_file_1, etc.)
		$this->clear_dynamic_meta_keys( $post_id, '_document_name_' );
		$this->clear_dynamic_meta_keys( $post_id, '_document_file_' );
		
		if ( ! empty( $_POST['dd_doc_names'] ) ) {
			$doc_names = array_values( $_POST['dd_doc_names'] );
			$doc_files = array_values( $_POST['dd_doc_files'] );
			
			$index = 1;
			for ( $i = 0; $i < count( $doc_names ); $i++ ) {
				// Only save if at least one field has data
				if ( ! empty( $doc_names[$i] ) || ! empty( $doc_files[$i] ) ) {
					update_post_meta( $post_id, '_document_name_' . $index, sanitize_text_field( $doc_names[$i] ) );
					update_post_meta( $post_id, '_document_file_' . $index, sanitize_text_field( $doc_files[$i] ) );
					$index++;
				}
			}
		}

		// Rebuild Features Data (_featured_1, _featured_2, etc.)
		$this->clear_dynamic_meta_keys( $post_id, '_featured_' );
		
		if ( ! empty( $_POST['dd_feature_texts'] ) ) {
			$features = array_values( $_POST['dd_feature_texts'] );
			
			$index = 1;
			foreach ( $features as $feature ) {
				if ( ! empty( trim( $feature ) ) ) {
					update_post_meta( $post_id, '_featured_' . $index, sanitize_textarea_field( $feature ) );
					$index++;
				}
			}
		}
	}

	/**
	 * Deletes all sequential meta keys starting with a specific prefix.
	 * Necessary for cleaning up old repeater rows before saving the newly ordered layout.
	 * * @param int    $post_id The post ID.
	 * @param string $prefix  The meta key prefix (e.g., '_featured_').
	 * @return void
	 */
	private function clear_dynamic_meta_keys( $post_id, $prefix ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s", $post_id, $wpdb->esc_like( $prefix ) . '%' ) );
	}

	/**
	 * Intercepts the product object before it is saved during a CSV import.
	 * Processes specific manual meta keys, and dynamically catches _document_file_X and _gallery_image_X
	 * * @param WC_Product $product The WooCommerce product object being created or updated.
	 * @param array      $data    The raw associative array of CSV data mapped for this row.
	 * @return WC_Product         The modified product object.
	 */
	public function intercept_meta_for_sideload( $product, $data ) {
		// Retrieve base manual keys from options
		$meta_keys_string = get_option( 'silvertell_file_meta_keys', '_manual' );
		$explicit_keys = array_map( 'trim', explode( ',', $meta_keys_string ) );

		$all_meta = $product->get_meta_data();
		$gallery_image_ids = [];

		foreach ( $all_meta as $meta_obj ) {
			$meta_key   = $meta_obj->key;
			$meta_value = $meta_obj->value;

			// Check if key matches explicit settings, OR matches _document_file_1, _gallery_image_1 formats
			$is_explicit    = in_array( $meta_key, $explicit_keys );
			$is_doc_file    = preg_match( '/^_document_file_\d+$/', $meta_key );
			$is_gallery_img = preg_match( '/^_gallery_image_\d+$/', $meta_key );

			if ( ( $is_explicit || $is_doc_file || $is_gallery_img ) && ! empty( $meta_value ) && filter_var( $meta_value, FILTER_VALIDATE_URL ) ) {
				error_log( "Silvertell Import: Processing dynamic file URL for key [{$meta_key}] - {$meta_value}" );
				
				$attachment_id = $this->sideload_file_to_media_library( $meta_value, 0 );

				if ( ! is_wp_error( $attachment_id ) ) {
					// Save the ID in place of the URL
					$product->update_meta_data( $meta_key, $attachment_id );
					
					// If this is a gallery image, aggregate the ID
					if ( $is_gallery_img ) {
						$gallery_image_ids[] = $attachment_id;
					}
				} else {
					error_log( "Silvertell Import FATAL for [{$meta_key}]: " . $attachment_id->get_error_message() );
					$product->delete_meta_data( $meta_key );
				}
			}
		}

		// Append collected gallery image IDs to the native WooCommerce product gallery
		if ( ! empty( $gallery_image_ids ) ) {
			$existing_gallery = $product->get_gallery_image_ids();
			// Merge, deduplicate, and set
			$merged_gallery = array_unique( array_merge( $existing_gallery, $gallery_image_ids ) );
			$product->set_gallery_image_ids( $merged_gallery );
		}

		return $product;
	}

	/**
	 * Handles the secure downloading and insertion of a remote file into the WP Media Library.
	 * * @param string $url       The remote URL of the file to download.
	 * @param int    $parent_id The post ID to attach the media to (defaults to 0).
	 * @return int|WP_Error     The numeric Attachment ID on success, or a WP_Error object on failure.
	 */
	public function sideload_file_to_media_library( $url, $parent_id = 0 ) {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		global $wpdb;

		$existing_attachment_id = $wpdb->get_var( 
			$wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_source_url' AND meta_value = %s LIMIT 1", $url ) 
		);

		if ( $existing_attachment_id ) {
			return (int) $existing_attachment_id;
		}

		add_filter( 'http_request_timeout', function(){ return 60; } );
		
		$tmp_file = download_url( $url );
		if ( is_wp_error( $tmp_file ) ) return $tmp_file;

		$file_array = [
			'name'     => basename( wp_parse_url( $url, PHP_URL_PATH ) ),
			'tmp_name' => $tmp_file
		];

		$attachment_id = media_handle_sideload( $file_array, $parent_id );

		if ( ! is_wp_error( $attachment_id ) ) {
			update_post_meta( $attachment_id, '_source_url', $url );
		}

		return $attachment_id;
	}

	/**
	 * Enqueues required styles and scripts directly on the WooCommerce product edit screen.
	 * Handles Repeater sorting, duplication, collapsing, and deletion.
	 * * @param string $hook The current admin page.
	 * @return void
	 */
	public function enqueue_repeater_assets( $hook ) {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) return;
		global $post_type;
		if ( 'product' !== $post_type ) return;

		// Ensure jQuery UI Sortable is available
		wp_enqueue_script( 'jquery-ui-sortable' );

		// Inline Styles for custom repeater UI
		$css = "
			.dd-repeater-wrapper { padding: 15px; }
			.dd-repeater-row { border: 1px solid #dfdfdf; margin-bottom: 10px; background: #fff; border-radius: 4px; }
			.dd-repeater-header { padding: 10px; background: #f9f9f9; border-bottom: 1px solid #dfdfdf; display: flex; align-items: center; cursor: pointer; }
			.dd-drag-handle { cursor: grab; color: #aaa; margin-right: 10px; }
			.dd-row-title { flex-grow: 1; font-weight: 600; }
			.dd-repeater-actions span { cursor: pointer; margin-left: 10px; color: #a0a5aa; transition: color 0.2s; }
			.dd-repeater-actions span:hover { color: #007cba; }
			.dd-repeater-actions .dd-delete-row:hover { color: #d63638; }
			.dd-repeater-content { padding: 15px; }
		";
		wp_add_inline_style( 'woocommerce_admin_styles', $css );

		// Inline JS for Repeater Logic
		$js = "
		jQuery(document).ready(function($) {
			// Initialize Sortable
			$('.dd-repeater-container').sortable({
				handle: '.dd-drag-handle',
				axis: 'y',
				update: function(event, ui) {
					// Logic triggers naturally on save via order in DOM
				}
			});

			// Collapse/Expand Row
			$(document).on('click', '.dd-collapse-row, .dd-repeater-header', function(e) {
				if($(e.target).hasClass('dd-delete-row') || $(e.target).hasClass('dd-duplicate-row')) return;
				var row = $(this).closest('.dd-repeater-row');
				row.find('.dd-repeater-content').slideToggle();
				row.find('.dd-collapse-row').toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2');
			});

			// Delete Row
			$(document).on('click', '.dd-delete-row', function() {
				if(confirm('Are you sure you want to remove this row?')) {
					$(this).closest('.dd-repeater-row').fadeOut(300, function() { $(this).remove(); });
				}
			});

			// Duplicate Row
			$(document).on('click', '.dd-duplicate-row', function() {
				var row = $(this).closest('.dd-repeater-row');
				var clone = row.clone();
				// Optional: Clear inputs if you prefer empty duplicates, else keep values
				clone.hide().insertAfter(row).slideDown();
			});

			// Add New Row Template Logic
			$('.dd-add-row').on('click', function() {
				var container = $(this).siblings('.dd-repeater-container');
				var type = container.data('type');
				// Clone the last row as a template
				var newRow = container.find('.dd-repeater-row').last().clone();
				
				// Clear inputs and reset title
				newRow.find('input[type=\"text\"], textarea').val('');
				newRow.find('.dd-row-title').text('New Row');
				newRow.find('.description').remove(); // remove old links
				
				// Append and open
				container.append(newRow.hide().slideDown());
				newRow.find('.dd-repeater-content').slideDown();
			});

			// Bind title dynamically based on input
			$(document).on('input', '.dd-bind-title', function() {
				var text = $(this).val();
				var title = text.length > 0 ? text.substring(0, 30) : 'New Row';
				$(this).closest('.dd-repeater-row').find('.dd-row-title').text(title);
			});
		});
		";
		wp_add_inline_script( 'woocommerce_admin', $js );
	}
}

new Silvertell_Woocommerce_Customisation();