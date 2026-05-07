<?php
/**
 * Plugin Name: Silvertell WooCommerce Customisations
 * Description: Custom modifications for WooCommerce, including a dynamic CSV import interceptor to sideload file URLs (PDFs, Images, Docs) from specified meta fields into the Media Library.
 * Version: 1.2.0
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 * Text Domain: silvertell-wc-customisation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class Silvertell_Woocommerce_Customisation
 * * Encapsulates custom WooCommerce modifications, including dynamic file sideloading during CSV imports
 * and the administrative settings interface to manage targeted meta keys.
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
		// Hook into the WooCommerce product import process before the product is saved to the database.
		add_filter( 'woocommerce_product_import_pre_insert_product_object', [ $this, 'intercept_meta_for_sideload' ], 10, 2 );

		// Hook into the admin menu to create the settings page.
		add_action( 'admin_menu', [ $this, 'add_settings_page' ] );

		// Hook into admin initialization to register our custom settings.
		add_action( 'admin_init', [ $this, 'register_settings' ] );
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
		// Prevent unauthorized access
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Retrieve current settings or default to '_manual'
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
								<p class="description">Enter comma-separated meta keys. Example: <code>_manual, _datasheet, _schematic_image</code></p>
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
	 * Intercepts the product object before it is saved during a CSV import.
	 * Loops through the admin-defined meta keys, checks if they contain valid URLs, and sideloads the files.
	 *
	 * @param WC_Product $product The WooCommerce product object being created or updated.
	 * @param array      $data    The raw associative array of CSV data mapped for this row.
	 * @return WC_Product         The modified product object.
	 */
	public function intercept_meta_for_sideload( $product, $data ) {
		// Retrieve the comma-separated string from the database and convert it into a clean array.
		$meta_keys_string = get_option( 'silvertell_file_meta_keys', '_manual' );
		$meta_keys = array_map( 'trim', explode( ',', $meta_keys_string ) );

		// Iterate over each meta key defined in the settings.
		foreach ( $meta_keys as $meta_key ) {
			if ( empty( $meta_key ) ) {
				continue;
			}

			// Retrieve the current mapped value for the specific meta key.
			$meta_value = $product->get_meta( $meta_key );

			// Execute only if the meta exists and is a valid URL.
			if ( ! empty( $meta_value ) && filter_var( $meta_value, FILTER_VALIDATE_URL ) ) {
				error_log( "Silvertell Import: Attempting to process URL for key [{$meta_key}] - {$meta_value}" );
				
				// Attempt to sideload the file and retrieve the attachment ID.
				$attachment_id = $this->sideload_file_to_media_library( $meta_value, 0 );

				// If sideload is successful, overwrite the meta data with the integer ID.
				if ( ! is_wp_error( $attachment_id ) ) {
					error_log( "Silvertell Import: Success! Attachment ID generated for [{$meta_key}]: {$attachment_id}" );
					$product->update_meta_data( $meta_key, $attachment_id );
				} else {
					// Log the error and delete the meta to prevent storing an invalid URL.
					error_log( "Silvertell Import FATAL for [{$meta_key}]: " . $attachment_id->get_error_message() );
					$product->delete_meta_data( $meta_key );
				}
			} elseif ( ! empty( $meta_value ) ) {
				error_log( "Silvertell Import Warning: Meta for [{$meta_key}] exists but is NOT a valid URL: {$meta_value}" );
			}
		}

		// Return the product object back to WooCommerce so it can be saved.
		return $product;
	}

	/**
	 * Handles the secure downloading and insertion of a remote file into the WordPress Media Library.
	 * Implements idempotency by checking if the specific URL was previously downloaded, returning the existing ID if found.
	 *
	 * @param string $url       The remote URL of the file to download.
	 * @param int    $parent_id The post ID to attach the media to (defaults to 0).
	 * @return int|WP_Error     The numeric Attachment ID on success, or a WP_Error object on failure.
	 */
	public function sideload_file_to_media_library( $url, $parent_id = 0 ) {
		// Require core WordPress file handling functions if they are not already loaded in the current context.
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		global $wpdb;

		// Idempotency Check: Prevent duplicate downloads on re-imports by checking the _source_url meta.
		$existing_attachment_id = $wpdb->get_var( 
			$wpdb->prepare( 
				"SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_source_url' AND meta_value = %s LIMIT 1", 
				$url 
			) 
		);

		if ( $existing_attachment_id ) {
			error_log( 'Silvertell Import: Idempotency check passed. Using existing ID: ' . $existing_attachment_id );
			return (int) $existing_attachment_id;
		}

		// Temporarily increase the timeout specifically for downloading large remote files.
		add_filter( 'http_request_timeout', function(){ return 60; } );
		
		error_log( 'Silvertell Import: Initiating download_url()...' );
		
		// Download the remote file to the local temporary directory.
		$tmp_file = download_url( $url );

		// Bail early and return the error if the download failed.
		if ( is_wp_error( $tmp_file ) ) {
			error_log( 'Silvertell Import Error: download_url() failed. Reason: ' . $tmp_file->get_error_message() );
			return $tmp_file;
		}

		error_log( 'Silvertell Import: Download successful. Temp file created at: ' . $tmp_file );

		// Construct the file array required by the media_handle_sideload function.
		$file_array = [
			'name'     => basename( wp_parse_url( $url, PHP_URL_PATH ) ),
			'tmp_name' => $tmp_file
		];

		error_log( 'Silvertell Import: Initiating media_handle_sideload()...' );
		
		// Execute the sideload, moving the temp file to the uploads directory.
		$attachment_id = media_handle_sideload( $file_array, $parent_id );

		// If successful, tag the attachment with its source URL to satisfy future idempotency checks.
		if ( ! is_wp_error( $attachment_id ) ) {
			update_post_meta( $attachment_id, '_source_url', $url );
		} else {
			error_log( 'Silvertell Import Error: media_handle_sideload() failed. Reason: ' . $attachment_id->get_error_message() );
		}

		return $attachment_id;
	}

}

// Initialize the class to engage the hooks.
new Silvertell_Woocommerce_Customisation();