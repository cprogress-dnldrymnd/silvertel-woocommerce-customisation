<?php
/**
 * Plugin Name: Silvertell WooCommerce Customisations
 * Description: Custom modifications for WooCommerce, including intercepting the CSV import to sideload PDF URLs from the '_manual' meta into the Media Library.
 * Version: 1.0.2
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 * Text Domain: silvertell-wc-customisation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class Silvertell_Woocommerce_Customisation {

	public function __construct() {
		$this->register_hooks();
	}

	private function register_hooks() {
        // Changed to add_filter for semantic correctness
		add_filter( 'woocommerce_product_import_pre_insert_product_object', [ $this, 'intercept_manual_meta_for_sideload' ], 10, 2 );
	}

	public function intercept_manual_meta_for_sideload( $product, $data ) {
		$manual_meta = $product->get_meta( '_manual' );

		if ( ! empty( $manual_meta ) && filter_var( $manual_meta, FILTER_VALIDATE_URL ) ) {
			error_log( 'Silvertell Import: Attempting to process URL - ' . $manual_meta );
			
			$attachment_id = $this->sideload_file_to_media_library( $manual_meta, 0 );

			if ( ! is_wp_error( $attachment_id ) ) {
				error_log( 'Silvertell Import: Success! Attachment ID generated: ' . $attachment_id );
				$product->update_meta_data( '_manual', $attachment_id );
			} else {
				error_log( 'Silvertell Import FATAL: ' . $attachment_id->get_error_message() );
				$product->delete_meta_data( '_manual' );
			}
		} elseif ( ! empty( $manual_meta ) ) {
            error_log( 'Silvertell Import Warning: Meta exists but is NOT a valid URL: ' . $manual_meta );
        }

        // THE FIX: We must return the product object back to WooCommerce so it can save it!
        return $product;
	}

	public function sideload_file_to_media_library( $url, $parent_id = 0 ) {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		global $wpdb;

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

		add_filter( 'http_request_timeout', function(){ return 60; } );
		
		error_log( 'Silvertell Import: Initiating download_url()...' );
		$tmp_file = download_url( $url );

		if ( is_wp_error( $tmp_file ) ) {
			error_log( 'Silvertell Import Error: download_url() failed. Reason: ' . $tmp_file->get_error_message() );
			return $tmp_file;
		}

		error_log( 'Silvertell Import: Download successful. Temp file created at: ' . $tmp_file );

		$file_array = [
			'name'     => basename( wp_parse_url( $url, PHP_URL_PATH ) ),
			'tmp_name' => $tmp_file
		];

		error_log( 'Silvertell Import: Initiating media_handle_sideload()...' );
		$attachment_id = media_handle_sideload( $file_array, $parent_id );

		if ( ! is_wp_error( $attachment_id ) ) {
			update_post_meta( $attachment_id, '_source_url', $url );
		} else {
            error_log( 'Silvertell Import Error: media_handle_sideload() failed. Reason: ' . $attachment_id->get_error_message() );
        }

		return $attachment_id;
	}
}

new Silvertell_Woocommerce_Customisation();