<?php

/**
 * Plugin Name: Silvertell WooCommerce Customisations
 * Description: Custom modifications for WooCommerce, including intercepting the CSV import to sideload PDF URLs from the '_manual' meta into the Media Library.
 * Version: 1.0.0
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 * Text Domain: silvertell-wc-customisation
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class Silvertell_Woocommerce_Customisation
 * 
 * Encapsulates all custom WooCommerce modifications and hooks for the Silvertell project.
 * Designed to be extensible for future methods and integrations.
 */
class Silvertell_Woocommerce_Customisation
{

    /**
     * Constructor.
     * 
     * Initializes the class and registers all necessary WordPress/WooCommerce hooks.
     */
    public function __construct()
    {
        $this->register_hooks();
    }

    /**
     * Registers all action and filter hooks for the customisations.
     * 
     * @return void
     */
    private function register_hooks()
    {
        // Hook into the WooCommerce product import process before the product is saved to the database.
        add_action('woocommerce_product_import_pre_insert_product_object', [$this, 'intercept_manual_meta_for_sideload'], 10, 2);
    }

    /**
     * Intercepts the product object before it is saved during a CSV import.
     * Checks for the '_manual' meta field, and if it contains a valid URL, sideloads the remote file.
     *
     * @param WC_Product $product The WooCommerce product object being created or updated.
     * @param array      $data    The raw associative array of CSV data mapped for this row.
     * @return void
     */
    public function intercept_manual_meta_for_sideload($product, $data)
    {
        // Retrieve the current mapped value for the _manual meta key.
        $manual_meta = $product->get_meta('_manual');

        // Execute only if the meta exists and is a valid URL to prevent processing existing integer IDs.
        if (! empty($manual_meta) && filter_var($manual_meta, FILTER_VALIDATE_URL)) {

            // Attempt to sideload the file and retrieve the attachment ID.
            // Passing 0 as parent ID because the product ID may not exist yet on initial creation.
            $attachment_id = $this->sideload_file_to_media_library($manual_meta, 0);

            // If sideload is successful, overwrite the meta data with the integer ID.
            if (! is_wp_error($attachment_id)) {
                $product->update_meta_data('_manual', $attachment_id);
            } else {
                // Delete the meta if the download fails to prevent storing an invalid URL or generating subsequent errors.
                $product->delete_meta_data('_manual');
            }
        }
    }

    /**
     * Handles the secure downloading and insertion of a remote file into the WordPress Media Library.
     * Implements idempotency by checking if the specific URL was previously downloaded, returning the existing ID if found.
     *
     * @param string $url       The remote URL of the file (e.g., PDF) to download.
     * @param int    $parent_id The post ID to attach the media to (defaults to 0).
     * @return int|WP_Error     The numeric Attachment ID on success, or a WP_Error object on failure.
     */
    public function sideload_file_to_media_library($url, $parent_id = 0)
    {
        // Require core WordPress file handling functions if they are not already loaded in the current context.
        if (! function_exists('media_handle_sideload')) {
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

        if ($existing_attachment_id) {
            return (int) $existing_attachment_id;
        }

        // Download the remote file to the local temporary directory.
        $tmp_file = download_url($url);

        // Bail early and return the error if the download failed (e.g., 404 error, server timeout).
        if (is_wp_error($tmp_file)) {
            return $tmp_file;
        }

        // Construct the file array required by the media_handle_sideload function.
        $file_array = [
            'name'     => basename(wp_parse_url($url, PHP_URL_PATH)),
            'tmp_name' => $tmp_file
        ];

        // Execute the sideload, moving the temp file to the uploads directory and creating the database attachment record.
        $attachment_id = media_handle_sideload($file_array, $parent_id);

        // If successful, tag the attachment with its source URL to satisfy future idempotency checks.
        if (! is_wp_error($attachment_id)) {
            update_post_meta($attachment_id, '_source_url', $url);
        }

        return $attachment_id;
    }
}

// Initialize the class to engage the hooks.
new Silvertell_Woocommerce_Customisation();
