<?php

/**
 * Plugin Name: Silvertell WooCommerce Customisations
 * Description: Custom modifications for WooCommerce, including dynamic file sideloading, array-based meta storage for documents and features, and native repeater fields with Media Library integration.
 * Version: 2.4.0
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 * Text Domain: silvertell-wc-customisation
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class Silvertell_Woocommerce_Customisation
 * Encapsulates custom WooCommerce modifications, including dynamic file sideloading during CSV imports,
 * administrative settings interfaces, and bespoke product data tabs with advanced repeater functionalities.
 */
class Silvertell_Woocommerce_Customisation
{

    /**
     * Constructor.
     * Initializes the class and registers all necessary WordPress and WooCommerce hooks.
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
        add_filter('woocommerce_product_import_pre_insert_product_object', [$this, 'intercept_meta_for_sideload'], 10, 2);
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_filter('woocommerce_product_data_tabs', [$this, 'add_custom_product_data_tabs']);
        add_action('woocommerce_product_data_panels', [$this, 'render_custom_product_data_panels']);
        add_action('woocommerce_process_product_meta', [$this, 'save_custom_product_data']);
        add_action('admin_footer', [$this, 'inject_repeater_assets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_core_assets']);
    }

    /**
     * Ensures core WordPress UI and Media scripts are loaded on the product edit screen.
     *
     * @param string $hook The current admin page hook.
     * @return void
     */
    public function enqueue_core_assets($hook)
    {
        if (in_array($hook, ['post.php', 'post-new.php'], true)) {
            wp_enqueue_script('jquery-ui-sortable');
            wp_enqueue_media();
        }
    }

    /**
     * Adds a custom submenu page under the main WooCommerce admin menu.
     *
     * @return void
     */
    public function add_settings_page()
    {
        add_submenu_page('woocommerce', 'File Import Settings', 'File Import Settings', 'manage_woocommerce', 'silvertell-file-importer', [$this, 'render_settings_page']);
    }

    /**
     * Registers the plugin settings using the WordPress Settings API.
     *
     * @return void
     */
    public function register_settings()
    {
        register_setting('silvertell_file_importer_group', 'silvertell_file_meta_keys');
    }

    /**
     * Renders the HTML frontend for the custom settings page in the WordPress admin.
     *
     * @return void
     */
    public function render_settings_page()
    {
        if (! current_user_can('manage_woocommerce')) return;
        $current_keys = get_option('silvertell_file_meta_keys', '_manual');
?>
        <div class="wrap">
            <h1>WooCommerce File Importer Settings</h1>
            <p>Specify which meta keys the importer should scan for file URLs. Note: <code>_document_file_X</code>, <code>_feature_X</code>, and <code>_gallery_image_X</code> are handled automatically.</p>
            <form method="post" action="options.php">
                <?php settings_fields('silvertell_file_importer_group'); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="silvertell_file_meta_keys">Target Meta Keys</label></th>
                            <td>
                                <input type="text" id="silvertell_file_meta_keys" name="silvertell_file_meta_keys" value="<?php echo esc_attr($current_keys); ?>" class="regular-text" style="width: 100%; max-width: 500px;">
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button('Save Options'); ?>
            </form>
        </div>
    <?php
    }

    /**
     * Adds custom tabs to the WooCommerce Product Data meta box.
     *
     * @param array $tabs Existing WooCommerce product data tabs.
     * @return array Modified array of tabs.
     */
    public function add_custom_product_data_tabs($tabs)
    {
        $tabs['buy_samples'] = ['label' => __('Buy Samples', 'silvertell-wc-customisation'), 'target' => 'silvertell_buy_samples_data', 'class' => ['show_if_simple', 'show_if_variable'], 'priority' => 80];
        $tabs['documents']   = ['label' => __('Documents', 'silvertell-wc-customisation'), 'target' => 'silvertell_documents_data', 'class' => ['show_if_simple', 'show_if_variable'], 'priority' => 81];
        $tabs['features']    = ['label' => __('Features', 'silvertell-wc-customisation'), 'target' => 'silvertell_features_data', 'class' => ['show_if_simple', 'show_if_variable'], 'priority' => 82];
        return $tabs;
    }

    /**
     * Renders the HTML panels for the custom product data tabs.
     *
     * @return void
     */
    public function render_custom_product_data_panels()
    {
        global $post;

        // Tab 1: Buy Samples
        echo '<div id="silvertell_buy_samples_data" class="panel woocommerce_options_panel">';
        woocommerce_wp_text_input(['id' => '_farnel_url', 'label' => __('Farnell URL', 'silvertell-wc-customisation'), 'type' => 'url']);
        woocommerce_wp_text_input(['id' => '_mouser_url', 'label' => __('Mouser URL', 'silvertell-wc-customisation'), 'type' => 'url']);
        woocommerce_wp_text_input(['id' => '_digikey_url', 'label' => __('Digikey URL', 'silvertell-wc-customisation'), 'type' => 'url']);
        echo '</div>';

        // Tab 2: Documents (Repeater utilizing Single Meta Key Array)
        echo '<div id="silvertell_documents_data" class="panel woocommerce_options_panel dd-panel-wrapper">';
        echo '<div class="options_group"><div class="dd-repeater-header-title"><strong>' . __('Product Documents', 'silvertell-wc-customisation') . '</strong></div>';
        echo '<div class="dd-repeater-container" data-type="documents">';

        $documents = get_post_meta($post->ID, '_documents', true);

        // Legacy fallback if migrating from old numbered keys
        if (empty($documents) || ! is_array($documents)) {
            $documents = [];
            $i = 1;
            while (get_post_meta($post->ID, '_document_name_' . $i, true) || get_post_meta($post->ID, '_document_file_' . $i, true)) {
                $documents[] = [
                    'name' => get_post_meta($post->ID, '_document_name_' . $i, true),
                    'file' => get_post_meta($post->ID, '_document_file_' . $i, true)
                ];
                $i++;
            }
        }

        if (! empty($documents)) {
            foreach ($documents as $doc) {
                $name_val = isset($doc['name']) ? $doc['name'] : '';
                $file_val = isset($doc['file']) ? $doc['file'] : '';
                $display_file = is_numeric($file_val) ? wp_get_attachment_url($file_val) : $file_val;
                $this->render_document_row($name_val, $display_file, $file_val, false);
            }
        }

        $this->render_document_row('', '', '', true); // Hidden template
        echo '</div>';
        echo '<div class="dd-repeater-footer"><button type="button" class="button button-primary dd-add-row">' . __('Add Document', 'silvertell-wc-customisation') . '</button></div>';
        echo '</div></div>';

        // Tab 3: Features (Repeater utilizing Single Meta Key Array)
        echo '<div id="silvertell_features_data" class="panel woocommerce_options_panel dd-panel-wrapper">';
        echo '<div class="options_group"><div class="dd-repeater-header-title"><strong>' . __('Product Features', 'silvertell-wc-customisation') . '</strong></div>';
        echo '<div class="dd-repeater-container" data-type="features">';

        $features = get_post_meta($post->ID, '_features', true);

        // Legacy fallback if migrating from old numbered keys
        if (empty($features) || ! is_array($features)) {
            $features = [];
            $j = 1;
            while (get_post_meta($post->ID, '_featured_' . $j, true)) {
                $features[] = get_post_meta($post->ID, '_featured_' . $j, true);
                $j++;
            }
        }

        if (! empty($features)) {
            foreach ($features as $feature_val) {
                $this->render_feature_row($feature_val, false);
            }
        }

        $this->render_feature_row('', true); // Hidden template
        echo '</div>';
        echo '<div class="dd-repeater-footer"><button type="button" class="button button-primary dd-add-row">' . __('Add Feature', 'silvertell-wc-customisation') . '</button></div>';
        echo '</div></div>';
    }

    /**
     * Renders the Document Row. Features hidden ID storage and visual filename.
     *
     * @param string  $name         The document name.
     * @param string  $display_file Formatted display URL for existing files.
     * @param string  $raw_file     The raw database value (URL or ID).
     * @param boolean $is_template  If true, configures the row as a hidden JS clone template.
     */
    private function render_document_row($name = '', $display_file = '', $raw_file = '', $is_template = false)
    {
        $row_class = $is_template ? 'dd-repeater-row dd-template' : 'dd-repeater-row';
        $input_attr = $is_template ? 'disabled="disabled"' : '';

        $filename_display = 'No file selected';
        if ($display_file) {
            $filename_display = basename(wp_parse_url($display_file, PHP_URL_PATH));
        }
    ?>
        <div class="<?php echo esc_attr($row_class); ?>">
            <div class="dd-repeater-header">
                <div class="dd-header-left">
                    <span class="dashicons dashicons-menu dd-drag-handle"></span>
                    <span class="dd-row-title"><?php echo $name ? esc_html($name) : 'New Document'; ?></span>
                </div>
                <div class="dd-header-right dd-repeater-actions">
                    <span class="dashicons dashicons-arrow-down-alt2 dd-collapse-row" title="Toggle"></span>
                    <span class="dashicons dashicons-admin-page dd-duplicate-row" title="Duplicate"></span>
                    <span class="dashicons dashicons-trash dd-delete-row" title="Delete"></span>
                </div>
            </div>
            <div class="dd-repeater-content">
                <div class="dd-field-group">
                    <label>Document Name</label>
                    <input type="text" name="dd_doc_names[]" class="dd-bind-title dd-full-width" value="<?php echo esc_attr($name); ?>" <?php echo $input_attr; ?> />
                </div>
                <div class="dd-field-group">
                    <label>Document File</label>
                    <div class="dd-file-wrap">
                        <input type="hidden" name="dd_doc_files[]" class="dd-file-input" value="<?php echo esc_attr($raw_file); ?>" <?php echo $input_attr; ?> />
                        <button type="button" class="button dd-upload-file">Select File</button>
                        <span class="dd-file-display"><?php echo esc_html($filename_display); ?></span>
                    </div>
                    <?php if ($display_file && !$is_template) echo '<span class="description" style="display:block; margin-top:5px;"><a href="' . esc_url($display_file) . '" target="_blank">Preview Current File</a></span>'; ?>
                </div>
            </div>
        </div>
    <?php
    }

    /**
     * Renders a single row for the Features repeater.
     *
     * @param string  $feature     The feature text string.
     * @param boolean $is_template If true, configures the row as a hidden JS clone template.
     */
    private function render_feature_row($feature = '', $is_template = false)
    {
        $row_class = $is_template ? 'dd-repeater-row dd-template' : 'dd-repeater-row';
        $input_attr = $is_template ? 'disabled="disabled"' : '';
    ?>
        <div class="<?php echo esc_attr($row_class); ?>">
            <div class="dd-repeater-header">
                <div class="dd-header-left">
                    <span class="dashicons dashicons-menu dd-drag-handle"></span>
                    <span class="dd-row-title"><?php echo $feature ? esc_html(wp_trim_words($feature, 5)) : 'New Feature'; ?></span>
                </div>
                <div class="dd-header-right dd-repeater-actions">
                    <span class="dashicons dashicons-arrow-down-alt2 dd-collapse-row" title="Toggle"></span>
                    <span class="dashicons dashicons-admin-page dd-duplicate-row" title="Duplicate"></span>
                    <span class="dashicons dashicons-trash dd-delete-row" title="Delete"></span>
                </div>
            </div>
            <div class="dd-repeater-content">
                <div class="dd-field-group">
                    <label>Feature Text</label>
                    <textarea name="dd_feature_texts[]" class="dd-bind-title dd-full-width" rows="3" <?php echo $input_attr; ?>><?php echo esc_textarea($feature); ?></textarea>
                </div>
            </div>
        </div>
    <?php
    }

    /**
     * Saves the custom product data meta fields and builds the core array structures.
     *
     * @param int $post_id The ID of the product being saved.
     * @return void
     */
    public function save_custom_product_data($post_id)
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

        // 1. Save URL Fields
        $url_fields = ['_farnel_url', '_mouser_url', '_digikey_url'];
        foreach ($url_fields as $field) {
            if (isset($_POST[$field])) {
                update_post_meta($post_id, $field, sanitize_url(wp_unslash($_POST[$field])));
            }
        }

        // 2. Save Documents Array
        $doc_names = isset($_POST['dd_doc_names']) && is_array($_POST['dd_doc_names']) ? wp_unslash($_POST['dd_doc_names']) : [];
        $doc_files = isset($_POST['dd_doc_files']) && is_array($_POST['dd_doc_files']) ? wp_unslash($_POST['dd_doc_files']) : [];

        $doc_names = array_values($doc_names);
        $doc_files = array_values($doc_files);
        $max_docs = max(count($doc_names), count($doc_files));
        $final_docs = [];

        for ($i = 0; $i < $max_docs; $i++) {
            $name = isset($doc_names[$i]) ? sanitize_text_field($doc_names[$i]) : '';
            $file = isset($doc_files[$i]) ? sanitize_text_field($doc_files[$i]) : '';

            if ($name !== '' || $file !== '') {
                $final_docs[] = ['name' => $name, 'file' => $file];
            }
        }

        update_post_meta($post_id, '_documents', $final_docs);
        $this->clear_dynamic_meta_keys($post_id, '_document_name_');
        $this->clear_dynamic_meta_keys($post_id, '_document_file_');

        // 3. Save Features Array
        $features_data = isset($_POST['dd_feature_texts']) && is_array($_POST['dd_feature_texts']) ? wp_unslash($_POST['dd_feature_texts']) : [];
        $features_data = array_values($features_data);
        $final_features = [];

        foreach ($features_data as $feature) {
            if (trim($feature) !== '') {
                $final_features[] = sanitize_textarea_field($feature);
            }
        }

        update_post_meta($post_id, '_features', $final_features);
        $this->clear_dynamic_meta_keys($post_id, '_feature_');
        $this->clear_dynamic_meta_keys($post_id, '_featured_');
    }

    /**
     * Deletes all sequential meta keys starting with a specific prefix.
     *
     * @param int    $post_id The post ID.
     * @param string $prefix  The meta key prefix.
     * @return void
     */
    private function clear_dynamic_meta_keys($post_id, $prefix)
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s", $post_id, $wpdb->esc_like($prefix) . '%'));
    }

    /**
     * Interceptor: Translates numbered CSV columns into the _documents and _features arrays.
     *
     * @param WC_Product $product The WooCommerce product object.
     * @param array      $data    The raw associative array of CSV data.
     * @return WC_Product
     */
    public function intercept_meta_for_sideload($product, $data)
    {
        $meta_keys_string = get_option('silvertell_file_meta_keys', '_manual');
        $explicit_keys = array_map('trim', explode(',', $meta_keys_string));
        $all_meta = $product->get_meta_data();

        $gallery_image_ids = [];
        $incoming_docs = [];
        $has_doc_updates = false;

        $incoming_features = [];
        $has_feature_updates = false;

        foreach ($all_meta as $meta_obj) {
            $meta_key   = $meta_obj->key;
            $meta_value = $meta_obj->value;

            // Handle Document Files (_document_file_1)
            if (preg_match('/^_document_file_(\d+)$/', $meta_key, $matches)) {
                $has_doc_updates = true;
                $index = $matches[1];

                if (! empty($meta_value) && filter_var($meta_value, FILTER_VALIDATE_URL)) {
                    $attachment_id = $this->sideload_file_to_media_library($meta_value, 0);
                    if (! is_wp_error($attachment_id)) {
                        $incoming_docs[$index]['file'] = $attachment_id;
                    }
                } elseif (is_numeric($meta_value)) {
                    $incoming_docs[$index]['file'] = $meta_value;
                }

                $product->delete_meta_data($meta_key);
                continue;
            }

            // Handle Document Names (_document_name_1)
            if (preg_match('/^_document_name_(\d+)$/', $meta_key, $matches)) {
                $has_doc_updates = true;
                $index = $matches[1];
                $incoming_docs[$index]['name'] = $meta_value;
                $product->delete_meta_data($meta_key);
                continue;
            }

            // Handle Features (_feature_1 or _featured_1)
            if (preg_match('/^_(?:feature|featured)_(\d+)$/', $meta_key, $matches)) {
                $has_feature_updates = true;
                $index = $matches[1];
                $incoming_features[$index] = $meta_value;
                $product->delete_meta_data($meta_key);
                continue;
            }

            // Handle Explicit Custom Keys & Gallery Images
            $is_explicit    = in_array($meta_key, $explicit_keys);
            $is_gallery_img = preg_match('/^_gallery_image_\d+$/', $meta_key);

            if (($is_explicit || $is_gallery_img) && ! empty($meta_value) && filter_var($meta_value, FILTER_VALIDATE_URL)) {
                $attachment_id = $this->sideload_file_to_media_library($meta_value, 0);
                if (! is_wp_error($attachment_id)) {
                    $product->update_meta_data($meta_key, $attachment_id);
                    if ($is_gallery_img) $gallery_image_ids[] = $attachment_id;
                } else {
                    $product->delete_meta_data($meta_key);
                }
            }
        }

        // Rebuild the _documents array
        if ($has_doc_updates) {
            ksort($incoming_docs); // Ensure proper order mapping
            $final_docs = [];
            foreach ($incoming_docs as $doc) {
                if (! empty($doc['name']) || ! empty($doc['file'])) {
                    $final_docs[] = [
                        'name' => isset($doc['name']) ? $doc['name'] : '',
                        'file' => isset($doc['file']) ? $doc['file'] : ''
                    ];
                }
            }
            $product->update_meta_data('_documents', $final_docs);
        }

        // Rebuild the _features array
        if ($has_feature_updates) {
            ksort($incoming_features); // Ensure proper order mapping
            $final_features = [];
            foreach ($incoming_features as $feature) {
                if (trim($feature) !== '') {
                    $final_features[] = $feature;
                }
            }
            $product->update_meta_data('_features', $final_features);
        }

        // Update Native Gallery
        if (! empty($gallery_image_ids)) {
            $existing_gallery = $product->get_gallery_image_ids();
            $merged_gallery = array_unique(array_merge($existing_gallery, $gallery_image_ids));
            $product->set_gallery_image_ids($merged_gallery);
        }

        return $product;
    }

    /**
     * Handles the secure downloading and insertion of a remote file into the WP Media Library.
     *
     * @param string $url       The remote URL of the file.
     * @param int    $parent_id The parent post ID.
     * @return int|WP_Error
     */
    public function sideload_file_to_media_library($url, $parent_id = 0)
    {
        if (! function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        global $wpdb;
        $existing_attachment_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_source_url' AND meta_value = %s LIMIT 1", $url));
        if ($existing_attachment_id) return (int) $existing_attachment_id;
        add_filter('http_request_timeout', function () {
            return 60;
        });
        $tmp_file = download_url($url);
        if (is_wp_error($tmp_file)) return $tmp_file;
        $file_array = ['name' => basename(wp_parse_url($url, PHP_URL_PATH)), 'tmp_name' => $tmp_file];
        $attachment_id = media_handle_sideload($file_array, $parent_id);
        if (! is_wp_error($attachment_id)) update_post_meta($attachment_id, '_source_url', $url);
        return $attachment_id;
    }

    /**
     * Injects the custom CSS and JS specifically formatted to override WooCommerce's default float styles.
     * Also includes the JS logic for the WP Media Library frame with explicit scoping.
     *
     * @return void
     */
    public function inject_repeater_assets()
    {
        $screen = get_current_screen();
        if (! $screen || $screen->post_type !== 'product') return;
    ?>
        <style>
            .dd-panel-wrapper {
                padding: 10px 20px 20px !important;
            }

            .dd-repeater-header-title {
                margin-bottom: 15px;
                font-size: 14px;
                color: #2271b1;
            }

            .dd-repeater-container {
                margin-bottom: 15px;
            }

            .dd-repeater-row {
                border: 1px solid #c3c4c7;
                background: #fff;
                margin-bottom: 10px;
                border-radius: 4px;
                box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04);
            }

            .dd-repeater-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 12px 15px;
                background: #f6f7f7;
                border-bottom: 1px solid #c3c4c7;
                cursor: pointer;
                border-radius: 4px 4px 0 0;
            }

            .dd-header-left {
                display: flex;
                align-items: center;
                flex: 1;
            }

            .dd-drag-handle {
                cursor: grab;
                color: #8c8f94;
                margin-right: 12px;
            }

            .dd-row-title {
                font-weight: 600;
                color: #1d2327;
            }

            .dd-header-right {
                display: flex;
                gap: 8px;
            }

            .dd-repeater-actions span {
                color: #8c8f94;
                transition: color 0.15s ease-in-out;
            }

            .dd-repeater-actions span:hover {
                color: #2271b1;
            }

            .dd-repeater-actions .dd-delete-row:hover {
                color: #d63638;
            }

            .dd-repeater-content {
                padding: 15px 20px;
                display: none;
                background: #fcfcfc;
            }

            .dd-field-group {
                margin-bottom: 15px;
                display: block;
                clear: both;
            }

            .dd-field-group:last-child {
                margin-bottom: 0;
            }

            .dd-field-group label {
                display: block !important;
                float: none !important;
                width: auto !important;
                font-weight: 600;
                margin-bottom: 6px;
                color: #50575e;
            }

            .dd-field-group input[type="text"],
            .dd-field-group textarea {
                display: block;
                width: 100% !important;
                border: 1px solid #8c8f94;
                padding: 6px 8px;
                border-radius: 3px;
                background: #fff;
                box-sizing: border-box;
            }

            .dd-file-wrap {
                display: flex;
                gap: 10px;
                align-items: center;
                width: 100%;
            }

            .dd-file-wrap button {
                white-space: nowrap;
            }

            .dd-file-display {
                font-weight: 500;
                color: #2271b1;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
                max-width: 300px;
            }

            .dd-repeater-footer {
                padding-top: 5px;
            }

            .dd-template {
                display: none !important;
            }
        </style>
        <script>
            jQuery(document).ready(function($) {
                $(document).off('click.ddRepeater');
                $('.dd-repeater-container').sortable({
                    handle: '.dd-drag-handle',
                    axis: 'y'
                });

                $(document).on('click.ddRepeater', '.dd-repeater-header, .dd-collapse-row', function(e) {
                    if ($(e.target).hasClass('dd-delete-row') || $(e.target).hasClass('dd-duplicate-row')) return;
                    var row = $(this).closest('.dd-repeater-row');
                    row.find('.dd-repeater-content').slideToggle(200);
                    row.find('.dd-collapse-row').toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2');
                });

                $(document).on('click.ddRepeater', '.dd-delete-row', function() {
                    if (confirm('Are you sure you want to remove this row?')) {
                        $(this).closest('.dd-repeater-row').slideUp(200, function() {
                            $(this).remove();
                        });
                    }
                });

                $(document).on('click.ddRepeater', '.dd-duplicate-row', function() {
                    var row = $(this).closest('.dd-repeater-row');
                    var clone = row.clone();
                    clone.find('.dd-repeater-content').show();
                    clone.find('.dd-collapse-row').removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
                    clone.hide().insertAfter(row).slideDown(200);
                });

                $('.dd-add-row').on('click.ddRepeater', function() {
                    var container = $(this).parent().siblings('.dd-repeater-container');
                    var template = container.find('.dd-template').clone();
                    template.removeClass('dd-template');
                    template.find('input, textarea').removeAttr('disabled');

                    template.find('.dd-file-display').text('No file selected');
                    template.find('.description').remove();

                    container.append(template);
                    template.slideDown(200);
                    template.find('.dd-repeater-content').show();
                    template.find('.dd-collapse-row').removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
                });

                $(document).on('input.ddRepeater', '.dd-bind-title', function() {
                    var text = $(this).val();
                    var title = text.length > 0 ? text.substring(0, 40) + (text.length > 40 ? '...' : '') : 'New Row';
                    $(this).closest('.dd-repeater-row').find('.dd-row-title').text(title);
                });

                $('.dd-repeater-row:not(.dd-template)').each(function() {
                    $(this).find('.dd-repeater-content').show();
                    $(this).find('.dd-collapse-row').removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
                });

                // WP Media Library Integration for File Uploader (Fixed Scope)
                var mediaUploader;
                var currentTargetInput;
                var currentTargetDisplay;

                $(document).on('click.ddRepeater', '.dd-upload-file', function(e) {
                    e.preventDefault();

                    currentTargetInput = $(this).siblings('.dd-file-input');
                    currentTargetDisplay = $(this).siblings('.dd-file-display');

                    if (mediaUploader) {
                        mediaUploader.open();
                        return;
                    }

                    mediaUploader = wp.media.frames.file_frame = wp.media({
                        title: 'Select or Upload Document',
                        button: {
                            text: 'Use this file'
                        },
                        multiple: false
                    });

                    mediaUploader.on('select', function() {
                        var attachment = mediaUploader.state().get('selection').first().toJSON();

                        currentTargetInput.val(attachment.id).trigger('input');
                        currentTargetDisplay.text(attachment.filename);
                    });

                    mediaUploader.open();
                });
            });
        </script>
<?php
    }
}

new Silvertell_Woocommerce_Customisation();
