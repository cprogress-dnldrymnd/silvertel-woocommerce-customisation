<?php

/**
 * Plugin Name: Silvertell WooCommerce Customisations
 * Description: Custom modifications for WooCommerce, including dynamic file sideloading, CPT document generation, rock-solid hierarchical taxonomy building, native repeater fields, conditional UI sections, and Advanced AJAX Evaluation Board Importer.
 * Version: 2.24.0
 * Author: Digitally Disruptive - Donald Raymundo
 * Author URI: https://digitallydisruptive.co.uk/
 * Text Domain: silvertell-wc-customisation
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Class Silvertell_Woocommerce_Customisation
 */
class Silvertell_Woocommerce_Customisation
{
    public function __construct()
    {
        $this->register_hooks();
    }

    private function register_hooks()
    {
        // Core CPT Registrations
        add_action('init', [$this, 'register_evaluation_board_cpt']);

        // Core Importers & Sideload Logic
        add_filter('woocommerce_product_import_pre_insert_product_object', [$this, 'intercept_meta_for_sideload'], 10, 2);
        add_filter('woocommerce_product_import_batch_size', [$this, 'reduce_import_batch_size']);

        // Evaluation Board AJAX Importer Hooks
        add_action('admin_menu', [$this, 'add_eb_importer_submenu']);
        add_action('admin_init', [$this, 'handle_eb_csv_upload']);
        add_action('wp_ajax_silvertell_eb_import_chunk', [$this, 'ajax_import_chunk']);
        add_action('wp_ajax_silvertell_eb_import_hierarchy', [$this, 'ajax_import_hierarchy']);

        // Admin Settings
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);

        // Custom Product Support Meta Box
        add_action('add_meta_boxes', [$this, 'register_product_support_meta_box']);
        add_action('save_post_product-support', [$this, 'save_product_support_meta']);

        // Custom Evaluation Board Meta Box
        add_action('add_meta_boxes', [$this, 'register_eb_meta_box']);
        add_action('save_post_evaluation-board', [$this, 'save_eb_meta']);

        // Custom WooCommerce Tabs & Panels
        add_filter('woocommerce_product_data_tabs', [$this, 'add_custom_product_data_tabs']);
        add_action('woocommerce_product_data_panels', [$this, 'render_custom_product_data_panels']);
        add_action('woocommerce_process_product_meta', [$this, 'save_custom_product_data']);

        // Assets
        add_action('admin_footer', [$this, 'inject_repeater_assets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_core_assets']);
    }

    public function register_evaluation_board_cpt()
    {
        $labels = [
            'name'                  => _x('Evaluation Boards', 'Post Type General Name', 'silvertell-wc-customisation'),
            'singular_name'         => _x('Evaluation Board', 'Post Type Singular Name', 'silvertell-wc-customisation'),
            'menu_name'             => __('Evaluation Boards', 'silvertell-wc-customisation'),
            'all_items'             => __('All Evaluation Boards', 'silvertell-wc-customisation'),
            'add_new_item'          => __('Add New Evaluation Board', 'silvertell-wc-customisation'),
            'add_new'               => __('Add New', 'silvertell-wc-customisation'),
            'new_item'              => __('New Evaluation Board', 'silvertell-wc-customisation'),
            'edit_item'             => __('Edit Evaluation Board', 'silvertell-wc-customisation'),
            'update_item'           => __('Update Evaluation Board', 'silvertell-wc-customisation'),
            'view_item'             => __('View Evaluation Board', 'silvertell-wc-customisation'),
            'search_items'          => __('Search Evaluation Boards', 'silvertell-wc-customisation'),
        ];

        $args = [
            'label'                 => __('Evaluation Board', 'silvertell-wc-customisation'),
            'labels'                => $labels,
            'supports'              => ['title', 'editor', 'thumbnail', 'custom-fields', 'page-attributes'],
            'hierarchical'          => true,
            'public'                => false,
            'show_ui'               => true,
            'show_in_menu'          => true,
            'menu_position'         => 5,
            'menu_icon'             => 'dashicons-media-spreadsheet',
            'show_in_admin_bar'     => true,
            'show_in_nav_menus'     => false,
            'can_export'            => true,
            'has_archive'           => false,
            'exclude_from_search'   => true,
            'publicly_queryable'    => false,
            'rewrite'               => false,
        ];

        register_post_type('evaluation-board', $args);

        register_taxonomy('evaluation-board-category', ['evaluation-board'], [
            'labels' => [
                'name' => __('Evaluation Board Categories', 'silvertell-wc-customisation'),
                'singular_name' => __('Evaluation Board Category', 'silvertell-wc-customisation'),
            ],
            'hierarchical' => true,
            'public' => false,
            'show_ui' => true,
            'show_admin_column' => true,
            'show_in_nav_menus' => false,
            'show_tagcloud' => false,
        ]);
    }

    public function reduce_import_batch_size($size)
    {
        return 5;
    }

    public function enqueue_core_assets($hook)
    {
        if (in_array($hook, ['post.php', 'post-new.php', 'edit.php', 'woocommerce_page_silvertell-file-importer'], true)) {
            wp_enqueue_script('jquery-ui-sortable');
            wp_enqueue_media();
        }
    }

    // ==============================================================================
    // AJAX EVALUATION BOARD IMPORTER
    // ==============================================================================

    public function add_eb_importer_submenu()
    {
        add_submenu_page(
            'edit.php?post_type=evaluation-board',
            'Import Evaluation Boards',
            'Import CSV',
            'manage_woocommerce',
            'silvertell-eb-importer',
            [$this, 'render_eb_importer_page']
        );
    }

    public function handle_eb_csv_upload()
    {
        if (!isset($_POST['silvertell_eb_upload_submit']) || !isset($_FILES['eb_csv_file'])) return;
        if (!isset($_POST['silvertell_import_eb_nonce']) || !wp_verify_nonce($_POST['silvertell_import_eb_nonce'], 'silvertell_import_eb')) return;
        if (!current_user_can('manage_woocommerce')) return;

        $file = $_FILES['eb_csv_file'];
        if (empty($file['tmp_name'])) {
            wp_die('Error: No file selected or file exceeds maximum server upload size.');
        }

        $upload_dir = wp_upload_dir();
        $target_filename = 'eb_import_' . time() . '.csv';
        $target_path = $upload_dir['basedir'] . '/' . $target_filename;

        if (move_uploaded_file($file['tmp_name'], $target_path)) {
            delete_transient('silvertell_eb_delayed_parents');
            wp_redirect(admin_url('edit.php?post_type=evaluation-board&page=silvertell-eb-importer&step=2&file=' . urlencode($target_filename)));
            exit;
        } else {
            wp_die('Error: Could not move uploaded CSV file to the uploads directory. Check folder permissions.');
        }
    }

    public function render_eb_importer_page()
    {
        if (!current_user_can('manage_woocommerce')) return;

        $step = isset($_GET['step']) ? intval($_GET['step']) : 1;

        echo '<div class="wrap dd-panel-wrapper" style="max-width:900px; padding: 20px !important;">';
        echo '<h1 style="margin-bottom:20px;">Evaluation Board CSV Importer</h1>';

        if ($step === 1) {
?>
            <div style="background:#fff; border:1px solid #c3c4c7; padding:20px 25px; border-radius:4px; box-shadow:0 1px 1px rgba(0,0,0,0.04);">
                <p style="font-size:14px; margin-bottom:20px;">Upload your <code>eb.csv</code> configuration file below. The importer will run via AJAX, allowing you to track progress live while sideloading images and generating categories.</p>

                <form method="post" enctype="multipart/form-data" action="">
                    <?php wp_nonce_field('silvertell_import_eb', 'silvertell_import_eb_nonce'); ?>
                    <table class="form-table" role="presentation">
                        <tbody>
                            <tr>
                                <th scope="row"><label for="eb_csv_file" style="font-weight:600;">Choose a CSV file</label></th>
                                <td>
                                    <input type="file" id="eb_csv_file" name="eb_csv_file" accept=".csv" required style="border: 1px solid #ccc; padding: 5px; width: 100%; max-width: 400px; background: #fafafa;">
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="submit">
                        <button type="submit" name="silvertell_eb_upload_submit" class="button button-primary button-hero">Continue to Import</button>
                    </p>
                </form>
            </div>
        <?php
        } elseif ($step === 2 && !empty($_GET['file'])) {

            $file_name = sanitize_text_field($_GET['file']);
            $upload_dir = wp_upload_dir();
            $filepath = $upload_dir['basedir'] . '/' . $file_name;

            if (!file_exists($filepath)) {
                echo '<div class="notice notice-error"><p>Temporary file not found. Please try uploading again.</p></div>';
                return;
            }

            $handle = fopen($filepath, "r");
            $total_rows = 0;
            if ($handle) {
                while (fgetcsv($handle) !== FALSE) {
                    $total_rows++;
                }
                fclose($handle);
            }
            $data_rows = max(0, $total_rows - 1);

            if ($data_rows === 0) {
                echo '<div class="notice notice-error"><p>The uploaded CSV appears to be empty or improperly formatted.</p></div>';
                return;
            }
        ?>

            <div style="background:#fff; border:1px solid #c3c4c7; padding:20px 25px; border-radius:4px; box-shadow:0 1px 1px rgba(0,0,0,0.04);">
                <h2 style="margin-top:0;">Importing Data... Please wait.</h2>
                <p>Do not close this window until the import is complete.</p>

                <div style="background: #f0f0f1; border-radius: 20px; height: 24px; overflow: hidden; margin: 20px 0; width: 100%; position: relative;">
                    <div id="dd-eb-progress-fill" style="background: #2271b1; height: 100%; width: 0%; transition: width 0.3s ease;"></div>
                    <span id="dd-eb-progress-text" style="position: absolute; top: 0; left: 0; width: 100%; text-align: center; line-height: 24px; font-size: 12px; color: #fff; font-weight: bold; mix-blend-mode: difference;">0%</span>
                </div>

                <div id="dd-eb-log-box" style="background: #1d2327; color: #a7aaad; font-family: monospace; font-size: 13px; padding: 15px; height: 350px; overflow-y: auto; border-radius: 4px; text-align: left; line-height: 1.6;">
                    <div>> Initializing import for <?php echo esc_html($data_rows); ?> rows...</div>
                </div>
            </div>

            <script>
                jQuery(document).ready(function($) {
                    var currentOffset = 0;
                    var totalRows = <?php echo $data_rows; ?>;
                    var fileName = '<?php echo esc_js($file_name); ?>';
                    var nonce = '<?php echo wp_create_nonce('silvertell_eb_ajax_import'); ?>';

                    function scrollToBottom() {
                        var logBox = document.getElementById("dd-eb-log-box");
                        logBox.scrollTop = logBox.scrollHeight;
                    }

                    function appendLogs(logs) {
                        if (!logs || logs.length === 0) return;
                        logs.forEach(function(log) {
                            $('#dd-eb-log-box').append('<div>> ' + log + '</div>');
                        });
                        scrollToBottom();
                    }

                    function runImportChunk() {
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'silvertell_eb_import_chunk',
                                file: fileName,
                                offset: currentOffset,
                                _ajax_nonce: nonce
                            },
                            success: function(response) {
                                if (response.success) {
                                    currentOffset += response.data.processed;
                                    var percentage = Math.min(100, Math.round((currentOffset / totalRows) * 100));

                                    $('#dd-eb-progress-fill').css('width', percentage + '%');
                                    $('#dd-eb-progress-text').text(percentage + '%');
                                    appendLogs(response.data.logs);

                                    if (currentOffset < totalRows && response.data.processed > 0) {
                                        runImportChunk();
                                    } else {
                                        appendLogs(["<span style='color:#f1c40f;'>Mapping hierarchical relationships...</span>"]);
                                        runHierarchyPass();
                                    }
                                } else {
                                    appendLogs(["<span style='color:#e74c3c;'>Fatal Error: " + response.data + "</span>"]);
                                }
                            },
                            error: function() {
                                appendLogs(["<span style='color:#e74c3c;'>Server Timeout. The image sideloads may be taking too long.</span>"]);
                            }
                        });
                    }

                    function runHierarchyPass() {
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'silvertell_eb_import_hierarchy',
                                file: fileName,
                                _ajax_nonce: nonce
                            },
                            success: function(res) {
                                if (res.success) {
                                    appendLogs(res.data.logs);
                                    $('#dd-eb-progress-fill').css('background', '#46b450');
                                    appendLogs(["<span style='color:#46b450; font-weight:bold;'>Import Completed Successfully!</span>"]);
                                } else {
                                    appendLogs(["<span style='color:#e74c3c;'>Hierarchy Error: " + res.data + "</span>"]);
                                }
                            }
                        });
                    }

                    setTimeout(runImportChunk, 1000);
                });
            </script>
        <?php
        }
        echo '</div>';
    }

    public function ajax_import_chunk()
    {
        check_ajax_referer('silvertell_eb_ajax_import', '_ajax_nonce');

        $file_name = sanitize_text_field($_POST['file']);
        $offset = intval($_POST['offset']);
        $batch_size = 5;

        $upload_dir = wp_upload_dir();
        $filepath = $upload_dir['basedir'] . '/' . $file_name;

        if (!file_exists($filepath)) wp_send_json_error('Temporary file missing.');

        $handle = fopen($filepath, "r");
        $headers = fgetcsv($handle, 1000, ",");
        $headers[0] = trim($headers[0], "\xEF\xBB\xBF");

        for ($i = 0; $i < $offset; $i++) {
            fgetcsv($handle);
        }

        $logs = [];
        $processed = 0;
        $delayed_parents = get_transient('silvertell_eb_delayed_parents') ?: [];

        while (($data = fgetcsv($handle, 5000, ",")) !== FALSE) {
            if ($processed >= $batch_size) break;

            $row = [];
            foreach ($headers as $index => $key) {
                $row[trim($key)] = isset($data[$index]) ? trim($data[$index]) : '';
            }

            $name = $row['Name'] ?? '';
            $unique_code = $row['Unique Code'] ?? '';

            if (empty($name)) {
                $processed++;
                continue;
            }

            $existing_id = 0;
            if (!empty($unique_code)) {
                $existing = get_posts(['post_type' => 'evaluation-board', 'meta_key' => '_unique_code', 'meta_value' => $unique_code, 'fields' => 'ids', 'numberposts' => 1, 'post_status' => 'any']);
                if (!empty($existing)) $existing_id = $existing[0];
            }
            if (!$existing_id) {
                $existing = get_posts(['post_type' => 'evaluation-board', 'title' => $name, 'post_status' => 'any', 'fields' => 'ids', 'numberposts' => 1]);
                if (!empty($existing)) $existing_id = $existing[0];
            }

            $post_data = [
                'post_title'   => $name,
                'post_content' => $row['Description'] ?? '',
                'post_status'  => 'publish',
                'post_type'    => 'evaluation-board',
            ];

            if ($existing_id) {
                $post_data['ID'] = $existing_id;
                $post_id = wp_update_post($post_data);
                $action_label = "<span style='color:#72aee6;'>Updated</span>";
            } else {
                $post_id = wp_insert_post($post_data);
                $action_label = "<span style='color:#68de7c;'>Created</span>";
            }

            if ($post_id && !is_wp_error($post_id)) {
                $logs[] = "{$action_label}: " . esc_html($name) . " (ID: {$post_id})";

                if (!empty($unique_code)) update_post_meta($post_id, '_unique_code', $unique_code);

                foreach ($row as $col_key => $col_val) {
                    if (strpos($col_key, 'Meta: ') === 0 && !empty($col_val)) {
                        $meta_key = substr($col_key, 6);
                        if ($meta_key === '_manual' && filter_var($col_val, FILTER_VALIDATE_URL)) {
                            $attach_id = $this->sideload_file_to_media_library($col_val, $post_id);
                            if (!is_wp_error($attach_id)) {
                                update_post_meta($post_id, $meta_key, $attach_id);
                            } else {
                                update_post_meta($post_id, $meta_key, $col_val);
                            }
                        } else {
                            update_post_meta($post_id, $meta_key, $col_val);
                        }
                    }
                }

                if (!empty($row['Categories'])) {
                    $this->assign_hierarchical_terms_to_post($post_id, $row['Categories'], 'evaluation-board-category');
                }

                if (!empty($row['Images']) && filter_var($row['Images'], FILTER_VALIDATE_URL)) {
                    $attach_id = $this->sideload_file_to_media_library($row['Images'], $post_id);
                    if (!is_wp_error($attach_id)) {
                        set_post_thumbnail($post_id, $attach_id);
                    }
                }

                if (!empty($row['Parent'])) {
                    $delayed_parents[$post_id] = $row['Parent'];
                }
            } else {
                $logs[] = "<span style='color:#e74c3c;'>Failed</span>: " . esc_html($name);
            }

            $processed++;
        }

        fclose($handle);

        set_transient('silvertell_eb_delayed_parents', $delayed_parents, HOUR_IN_SECONDS);

        wp_send_json_success([
            'processed' => $processed,
            'logs'      => $logs
        ]);
    }

    public function ajax_import_hierarchy()
    {
        check_ajax_referer('silvertell_eb_ajax_import', '_ajax_nonce');

        $delayed_parents = get_transient('silvertell_eb_delayed_parents');
        $logs = [];

        if (!empty($delayed_parents) && is_array($delayed_parents)) {
            foreach ($delayed_parents as $child_id => $parent_code) {
                $parent_query = get_posts([
                    'post_type'   => 'evaluation-board',
                    'meta_key'    => '_unique_code',
                    'meta_value'  => $parent_code,
                    'fields'      => 'ids',
                    'numberposts' => 1,
                    'post_status' => 'any'
                ]);

                if (!empty($parent_query)) {
                    wp_update_post(['ID' => $child_id, 'post_parent' => $parent_query[0]]);
                    $logs[] = "Mapped Parent [<span style='color:#3498db;'>" . esc_html($parent_code) . "</span>] to Child ID {$child_id}";
                }
            }
        }

        delete_transient('silvertell_eb_delayed_parents');

        $file_name = sanitize_text_field($_POST['file']);
        if (!empty($file_name)) {
            $upload_dir = wp_upload_dir();
            $filepath = $upload_dir['basedir'] . '/' . $file_name;
            if (file_exists($filepath)) {
                unlink($filepath);
                $logs[] = "<span style='color:#7f8c8d;'>Temporary CSV file deleted.</span>";
            }
        }

        wp_send_json_success(['logs' => $logs]);
    }

    // ==============================================================================
    // CORE META BOXES & PANELS
    // ==============================================================================

    public function register_product_support_meta_box()
    {
        add_meta_box(
            'silvertell_product_support_pdf',
            __('Document File Configuration', 'silvertell-wc-customisation'),
            [$this, 'render_product_support_meta_box'],
            'product-support',
            'normal',
            'high'
        );
    }

    public function render_product_support_meta_box($post)
    {
        wp_nonce_field('silvertell_save_pdf_file', 'silvertell_pdf_file_nonce');
        $file_val = get_post_meta($post->ID, 'pdf_file', true);
        $display_file = is_numeric($file_val) ? wp_get_attachment_url($file_val) : $file_val;
        $filename_display = $display_file ? basename(wp_parse_url($display_file, PHP_URL_PATH)) : 'No file selected';

        echo '<div class="dd-panel-wrapper"><div class="dd-field-group">';
        echo '<label style="display:block; font-weight:600; margin-bottom:10px;">Select or Upload PDF File</label>';
        echo '<div class="dd-file-wrap" style="display:flex; gap:10px; align-items:center; width:100%; max-width:600px;">';
        echo '<input type="hidden" name="pdf_file" class="dd-file-input" value="' . esc_attr($file_val) . '" />';
        echo '<button type="button" class="button button-primary dd-upload-file">Select File</button>';
        echo '<span class="dd-file-display" style="font-weight:500; color:#2271b1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:300px;">' . esc_html($filename_display) . '</span>';
        echo '</div>';
        if ($display_file) echo '<p class="description" style="margin-top:10px;"><a href="' . esc_url($display_file) . '" target="_blank">Preview Current File</a></p>';
        echo '</div></div>';
    }

    public function save_product_support_meta($post_id)
    {
        if (! isset($_POST['silvertell_pdf_file_nonce']) || ! wp_verify_nonce($_POST['silvertell_pdf_file_nonce'], 'silvertell_save_pdf_file')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (! current_user_can('edit_post', $post_id)) return;

        if (isset($_POST['pdf_file'])) {
            update_post_meta($post_id, 'pdf_file', sanitize_text_field(wp_unslash($_POST['pdf_file'])));
        }
    }

    public function register_eb_meta_box()
    {
        add_meta_box(
            'silvertell_eb_details',
            __('Evaluation Board Details', 'silvertell-wc-customisation'),
            [$this, 'render_eb_meta_box'],
            'evaluation-board',
            'normal',
            'high'
        );
    }

    public function render_eb_meta_box($post)
    {
        wp_nonce_field('silvertell_save_eb_meta', 'silvertell_eb_meta_nonce');

        echo '<div class="dd-panel-wrapper" style="padding: 10px 0 !important;">';

        echo '<div class="dd-field-group">';
        echo '<label style="display:block; font-weight:600; margin-bottom:10px;">Unique Code</label>';
        $ucode = get_post_meta($post->ID, '_unique_code', true);
        echo '<input type="text" name="_unique_code" value="' . esc_attr($ucode) . '" style="max-width:400px; width:100%;" />';
        echo '<p class="description">Used as the primary identifier during importing.</p>';
        echo '</div><hr style="margin:20px 0;"/>';

        echo '<h3 style="margin-top:0;">Document Configuration</h3>';
        $this->render_single_file_upload_field('_manual', __('Manual File', 'silvertell-wc-customisation'), 'Upload the main evaluation board manual.', '_manual', $post->ID);

        echo '<hr style="margin:20px 0;"/>';
        echo '<h3 style="margin-top:0;">Buy Samples</h3>';

        $providers = $this->get_sample_providers();
        if (!empty($providers)) {
            foreach ($providers as $provider) {
                $val = get_post_meta($post->ID, $provider['meta_key'], true);
                echo '<div class="dd-field-group" style="margin-bottom:10px;">';
                echo '<label style="display:block; font-weight:600; margin-bottom:5px;">' . esc_html($provider['name']) . ' URL</label>';
                echo '<input type="url" name="' . esc_attr($provider['meta_key']) . '" value="' . esc_attr($val) . '" style="max-width:400px; width:100%;" />';
                echo '</div>';
            }
        } else {
            echo '<p>No sample providers configured. Add them in WooCommerce > Silvertell Settings.</p>';
        }

        echo '</div>';
    }

    public function save_eb_meta($post_id)
    {
        if (!isset($_POST['silvertell_eb_meta_nonce']) || !wp_verify_nonce($_POST['silvertell_eb_meta_nonce'], 'silvertell_save_eb_meta')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        if (isset($_POST['_unique_code'])) update_post_meta($post_id, '_unique_code', sanitize_text_field($_POST['_unique_code']));
        if (isset($_POST['_manual'])) update_post_meta($post_id, '_manual', sanitize_text_field($_POST['_manual']));

        $providers = $this->get_sample_providers();
        foreach ($providers as $provider) {
            $field = $provider['meta_key'];
            if (isset($_POST[$field])) {
                update_post_meta($post_id, $field, sanitize_url($_POST[$field]));
            }
        }
    }

    public function add_settings_page()
    {
        add_submenu_page('woocommerce', 'Silvertell Advanced Customisations', 'Silvertell Settings', 'manage_woocommerce', 'silvertell-file-importer', [$this, 'render_settings_page']);
    }

    public function register_settings()
    {
        register_setting('silvertell_file_importer_group', 'silvertell_file_meta_keys');
        register_setting('silvertell_file_importer_group', 'silvertell_sample_providers', [$this, 'sanitize_sample_providers']);
    }

    public function sanitize_sample_providers($input)
    {
        if (! is_array($input) || ! isset($input['name'])) return [];
        $providers = [];
        $names = array_values($input['name']);
        $keys  = array_values($input['key']);
        $logos = array_values($input['logo']);
        $count = count($names);
        for ($i = 0; $i < $count; $i++) {
            $name = sanitize_text_field($names[$i]);
            $key  = sanitize_text_field($keys[$i]);
            $logo = sanitize_text_field($logos[$i]);
            if (! empty($name) && ! empty($key)) {
                if (substr($key, 0, 1) !== '_') $key = '_' . $key;
                $providers[] = ['name' => $name, 'meta_key' => $key, 'logo' => $logo];
            }
        }
        return $providers;
    }

    private function get_sample_providers()
    {
        $defaults = [
            ['name' => 'Farnell', 'meta_key' => '_farnel_url', 'logo' => ''],
            ['name' => 'Mouser', 'meta_key' => '_mouser_url', 'logo' => ''],
            ['name' => 'Digikey', 'meta_key' => '_digikey_url', 'logo' => ''],
        ];
        return get_option('silvertell_sample_providers', $defaults);
    }

    public function render_settings_page()
    {
        if (! current_user_can('manage_woocommerce')) return;
        $current_keys = get_option('silvertell_file_meta_keys', '_manual');
        $providers    = $this->get_sample_providers();
        ?>
        <div class="wrap dd-panel-wrapper" style="padding:0 !important; max-width: 900px;">
            <h1>WooCommerce Advanced Customisations</h1>
            <form method="post" action="options.php">
                <?php settings_fields('silvertell_file_importer_group'); ?>
                <h2 class="title">Product Importer Logic</h2>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="silvertell_file_meta_keys">Target Sideload Meta Keys</label></th>
                            <td>
                                <input type="text" id="silvertell_file_meta_keys" name="silvertell_file_meta_keys" value="<?php echo esc_attr($current_keys); ?>" class="regular-text" style="width: 100%;">
                            </td>
                        </tr>
                    </tbody>
                </table>
                <hr style="margin: 30px 0;">
                <h2 class="title">Buy Samples Providers</h2>
                <p class="description" style="margin-bottom:15px;">Configure the dynamic providers for the "Buy Samples" product & evaluation board tab. Meta keys should be lowercase with underscores.</p>
                <div class="dd-repeater-container" data-type="providers">
                    <?php
                    if (! empty($providers)) {
                        foreach ($providers as $provider) {
                            $this->render_settings_provider_row($provider['name'], $provider['meta_key'], $provider['logo']);
                        }
                    }
                    $this->render_settings_provider_row('', '', '', true);
                    ?>
                </div>
                <div class="dd-repeater-footer" style="margin-bottom: 30px;">
                    <button type="button" class="button button-secondary dd-add-row">Add Provider</button>
                </div>
                <?php submit_button('Save All Settings', 'primary', 'submit', true, ['style' => 'font-size:16px; padding: 5px 30px;']); ?>
            </form>
        </div>
    <?php
    }

    private function render_settings_provider_row($name = '', $key = '', $logo = '', $is_template = false)
    {
        $row_class  = $is_template ? 'dd-repeater-row dd-template' : 'dd-repeater-row';
        $input_attr = $is_template ? 'disabled="disabled"' : '';
        $logo_url   = is_numeric($logo) ? wp_get_attachment_image_url($logo, 'thumbnail') : $logo;
        $logo_disp  = $logo_url ? 'Logo Selected' : 'No Logo';
    ?>
        <div class="<?php echo esc_attr($row_class); ?>">
            <div class="dd-repeater-header">
                <div class="dd-header-left">
                    <span class="dashicons dashicons-menu dd-drag-handle"></span>
                    <span class="dd-row-title"><?php echo $name ? esc_html($name) : 'New Provider'; ?></span>
                </div>
                <div class="dd-header-right dd-repeater-actions">
                    <span class="dashicons dashicons-arrow-down-alt2 dd-collapse-row" title="Toggle"></span>
                    <span class="dashicons dashicons-admin-page dd-duplicate-row" title="Duplicate"></span>
                    <span class="dashicons dashicons-trash dd-delete-row" title="Delete"></span>
                </div>
            </div>
            <div class="dd-repeater-content">
                <div style="display:flex; gap:20px;">
                    <div class="dd-field-group" style="flex:1;">
                        <label>Provider Name</label>
                        <input type="text" name="silvertell_sample_providers[name][]" class="dd-bind-title dd-full-width" value="<?php echo esc_attr($name); ?>" <?php echo $input_attr; ?> placeholder="e.g. Farnell" />
                    </div>
                    <div class="dd-field-group" style="flex:1;">
                        <label>Meta Key</label>
                        <input type="text" name="silvertell_sample_providers[key][]" class="dd-full-width" value="<?php echo esc_attr($key); ?>" <?php echo $input_attr; ?> placeholder="e.g. _farnel_url" />
                    </div>
                </div>
                <div class="dd-field-group" style="margin-top:15px;">
                    <label>Provider Logo</label>
                    <div class="dd-file-wrap">
                        <input type="hidden" name="silvertell_sample_providers[logo][]" class="dd-file-input" value="<?php echo esc_attr($logo); ?>" <?php echo $input_attr; ?> />
                        <button type="button" class="button dd-upload-file">Select Image</button>
                        <span class="dd-file-display"><?php echo esc_html($logo_disp); ?></span>
                    </div>
                    <?php if ($logo_url && !$is_template) echo '<div style="margin-top:10px;"><img src="' . esc_url($logo_url) . '" style="max-height:40px; border:1px solid #ddd; padding:3px; border-radius:4px;" /></div>'; ?>
                </div>
            </div>
        </div>
    <?php
    }

    public function add_custom_product_data_tabs($tabs)
    {
        $all_types = ['show_if_simple', 'show_if_variable', 'show_if_external', 'show_if_grouped'];
        $tabs['buy_samples'] = ['label' => __('Buy Samples', 'silvertell-wc-customisation'), 'target' => 'silvertell_buy_samples_data', 'class' => $all_types, 'priority' => 80];
        $tabs['documents']   = ['label' => __('Documents', 'silvertell-wc-customisation'), 'target' => 'silvertell_documents_data', 'class' => array_merge($all_types, ['dd-tab-docs']), 'priority' => 81];
        $tabs['features']    = ['label' => __('Features', 'silvertell-wc-customisation'), 'target' => 'silvertell_features_data', 'class' => array_merge($all_types, ['dd-tab-feats']), 'priority' => 82];
        $tabs['eval_boards'] = ['label' => __('Evaluation Boards', 'silvertell-wc-customisation'), 'target' => 'silvertell_eval_boards_data', 'class' => $all_types, 'priority' => 83];
        return $tabs;
    }

    public function render_custom_product_data_panels()
    {
        global $post;

        // Tab 1: Buy Samples
        echo '<div id="silvertell_buy_samples_data" class="panel woocommerce_options_panel">';
        $providers = $this->get_sample_providers();
        if (! empty($providers)) {
            foreach ($providers as $provider) {
                woocommerce_wp_text_input(['id' => esc_attr($provider['meta_key']), 'label' => esc_html($provider['name']), 'type'  => 'url']);
            }
        } else {
            echo '<p style="padding:15px;">No sample providers configured. Add them in WooCommerce > Silvertell Settings.</p>';
        }
        echo '</div>';

        // Tab 2: Documents
        echo '<div id="silvertell_documents_data" class="panel woocommerce_options_panel dd-panel-wrapper">';
        echo '<div class="options_group dd-additional-documents-wrapper" style="padding-top:15px;">';

        $current_linked_docs = get_post_meta($post->ID, '_linked_documents', true);
        if (! is_array($current_linked_docs)) $current_linked_docs = [];

        $support_posts = get_posts([
            'post_type'   => 'product-support',
            'numberposts' => -1,
            'post_status' => 'publish'
        ]);

        echo '<p class="form-field _linked_documents_field">';
        echo '<label for="_linked_documents">' . __('Linked Documents', 'silvertell-wc-customisation') . '</label>';
        echo '<select id="_linked_documents" name="_linked_documents[]" class="wc-enhanced-select" multiple="multiple" style="width: 50%;">';
        foreach ($support_posts as $sp) {
            $selected = in_array($sp->ID, $current_linked_docs) ? 'selected="selected"' : '';
            echo '<option value="' . esc_attr($sp->ID) . '" ' . $selected . '>' . esc_html($sp->post_title) . '</option>';
        }
        echo '</select>';
        echo wp_kses_post(wc_help_tip(__('Select Product Support documents to link to this product.', 'silvertell-wc-customisation')));
        echo '</p>';

        echo '</div></div>';

        // Tab 3: Features
        echo '<div id="silvertell_features_data" class="panel woocommerce_options_panel dd-panel-wrapper">';
        echo '<div class="options_group"><div class="dd-repeater-header-title"><strong>' . __('Product Features', 'silvertell-wc-customisation') . '</strong></div>';
        echo '<div class="dd-repeater-container" data-type="features">';
        $features = get_post_meta($post->ID, '_features', true);
        if (! empty($features) && is_array($features)) {
            foreach ($features as $feature_val) {
                $this->render_feature_row($feature_val, false);
            }
        }
        $this->render_feature_row('', true);
        echo '</div>';
        echo '<div class="dd-repeater-footer"><button type="button" class="button button-primary dd-add-row">' . __('Add Feature', 'silvertell-wc-customisation') . '</button></div>';
        echo '</div></div>';

        // Tab 4: Evaluation Boards
        echo '<div id="silvertell_eval_boards_data" class="panel woocommerce_options_panel dd-panel-wrapper">';
        echo '<div class="options_group" style="padding-top:15px;">';

        $current_linked_ebs = get_post_meta($post->ID, '_linked_eval_boards', true);
        if (! is_array($current_linked_ebs)) {
            $old_single = get_post_meta($post->ID, '_linked_eval_board', true);
            $current_linked_ebs = $old_single ? [(int)$old_single] : [];
        }

        $eval_boards = get_posts([
            'post_type'   => 'evaluation-board',
            'numberposts' => -1,
            'post_status' => 'publish'
        ]);

        echo '<p class="form-field _linked_eval_boards_field">';
        echo '<label for="_linked_eval_boards">' . __('Evaluation Boards', 'silvertell-wc-customisation') . '</label>';
        echo '<select id="_linked_eval_boards" name="_linked_eval_boards[]" class="wc-enhanced-select" multiple="multiple" style="width: 50%;">';
        foreach ($eval_boards as $board) {
            $code = get_post_meta($board->ID, '_unique_code', true);
            $label = $board->post_title . ($code ? ' (' . $code . ')' : '');
            $selected = in_array($board->ID, $current_linked_ebs) ? 'selected="selected"' : '';
            echo '<option value="' . esc_attr($board->ID) . '" ' . $selected . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo wp_kses_post(wc_help_tip(__('Select multiple Evaluation Boards to link to this product.', 'silvertell-wc-customisation')));
        echo '</p>';

        echo '</div></div>';
    }

    private function render_single_file_upload_field($meta_key, $label, $description = '', $html_name = null, $post_id = null)
    {
        if (!$post_id) {
            global $post;
            $post_id = $post ? $post->ID : 0;
        }

        $html_name = $html_name ? $html_name : $meta_key;
        $raw_val = get_post_meta($post_id, $meta_key, true);
        $display_file = is_numeric($raw_val) ? wp_get_attachment_url($raw_val) : $raw_val;
        $filename_display = $display_file ? basename(wp_parse_url($display_file, PHP_URL_PATH)) : 'No file selected';

        echo '<div class="options_group"><p class="form-field ' . esc_attr($html_name) . '_field" style="padding:0;">';
        echo '<label for="' . esc_attr($html_name) . '" style="display:block; font-weight:600; margin-bottom:5px;">' . esc_html($label) . '</label>';
        echo '<span class="dd-file-wrap" style="display:flex; align-items:center; gap:10px; width:100%;">';
        echo '<input type="hidden" name="' . esc_attr($html_name) . '" id="' . esc_attr($html_name) . '" class="dd-file-input" value="' . esc_attr($raw_val) . '" />';
        echo '<button type="button" class="button button-primary dd-upload-file">Select File</button>';
        echo '<span class="dd-file-display" style="font-weight:500; color:#2271b1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:300px;">' . esc_html($filename_display) . '</span>';
        echo '</span>';
        if ($description || $display_file) {
            echo '<span class="description" style="display:block; margin-top:8px;">';
            if ($description) echo esc_html($description) . '<br>';
            if ($display_file) echo '<a href="' . esc_url($display_file) . '" target="_blank">Preview Current File</a>';
            echo '</span>';
        }
        echo '</p></div>';
    }

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

    public function save_custom_product_data($post_id)
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

        // 1. Save Dynamic Buy Samples
        $providers = $this->get_sample_providers();
        foreach ($providers as $provider) {
            $field = $provider['meta_key'];
            if (isset($_POST[$field])) {
                update_post_meta($post_id, $field, sanitize_url(wp_unslash($_POST[$field])));
            }
        }

        // 2. Save Linked Documents (Multi-select array)
        if (isset($_POST['_linked_documents']) && is_array($_POST['_linked_documents'])) {
            $sanitized_ids = array_map('absint', $_POST['_linked_documents']);
            update_post_meta($post_id, '_linked_documents', $sanitized_ids);
        } else {
            update_post_meta($post_id, '_linked_documents', []);
        }

        // 3. Save Linked Evaluation Boards (Multi-select array)
        if (isset($_POST['_linked_eval_boards']) && is_array($_POST['_linked_eval_boards'])) {
            $sanitized_eb_ids = array_map('absint', $_POST['_linked_eval_boards']);
            update_post_meta($post_id, '_linked_eval_boards', $sanitized_eb_ids);
        } else {
            update_post_meta($post_id, '_linked_eval_boards', []);
        }

        // 4. Save Features Array
        $features_data = isset($_POST['dd_feature_texts']) && is_array($_POST['dd_feature_texts']) ? wp_unslash($_POST['dd_feature_texts']) : [];
        $features_data = array_values($features_data);
        $final_features = [];

        foreach ($features_data as $feature) {
            if (trim($feature) !== '') $final_features[] = sanitize_textarea_field($feature);
        }

        update_post_meta($post_id, '_features', $final_features);
        $this->clear_dynamic_meta_keys($post_id, '_feature_');
        $this->clear_dynamic_meta_keys($post_id, '_featured_');
    }

    private function clear_dynamic_meta_keys($post_id, $prefix)
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s", $post_id, $wpdb->esc_like($prefix) . '%'));
    }

    /**
     * Corrected Category Assigner:
     * 1. Safely decodes HTML entities FIRST so encoded `&gt;` converts to true `>`.
     * 2. Splits multiple distinct categories by comma.
     * 3. Splits hierarchical categories by greater-than sign (`>`).
     * 4. Double checks both raw and encoded strings to safely handle ampersands (`&`) without duplicating.
     */
    private function assign_hierarchical_terms_to_post($post_id, $category_string, $taxonomy = 'product-support-category')
    {
        if (empty($category_string) || ! taxonomy_exists($taxonomy)) {
            return;
        }

        // PRE-DECODE: Ensure the CSV string is properly interpreted before trying to split it
        $category_string = html_entity_decode($category_string, ENT_QUOTES, 'UTF-8');
        $category_string = str_replace(['&gt;', '&#62;', '&amp;'], ['>', '>', '&'], $category_string);

        // SPLIT 1: Commas separate independent hierarchy chains
        $chains = array_map('trim', explode(',', $category_string));
        $assigned_term_ids = [];

        foreach ($chains as $chain) {
            if (empty($chain)) continue;

            // SPLIT 2: Greater-than defines parent/child depth
            $terms = array_map('trim', explode('>', $chain));
            $parent_id = 0;

            foreach ($terms as $term_name) {
                if (empty($term_name)) continue;

                // Prepare encoded fallback for matching ampersand titles
                $encoded_name = str_replace('&', '&amp;', $term_name);

                $term_info = term_exists($term_name, $taxonomy, $parent_id);

                if (!$term_info) {
                    $term_info = term_exists($encoded_name, $taxonomy, $parent_id);
                }

                if ($term_info) {
                    $parent_id = (int) (is_array($term_info) ? $term_info['term_id'] : $term_info);
                } else {
                    $inserted = wp_insert_term($term_name, $taxonomy, ['parent' => $parent_id]);

                    if (! is_wp_error($inserted)) {
                        $parent_id = (int) $inserted['term_id'];
                        clean_term_cache($parent_id, $taxonomy);
                    } elseif ($inserted->get_error_code() === 'term_exists') {
                        $parent_id = (int) $inserted->get_error_data();
                    } else {
                        $salvage = term_exists($term_name, $taxonomy);
                        if ($salvage) {
                            $parent_id = (int) (is_array($salvage) ? $salvage['term_id'] : $salvage);
                        } else {
                            break;
                        }
                    }
                }

                if ($parent_id) {
                    $assigned_term_ids[] = $parent_id;
                }
            }
        }

        if (! empty($assigned_term_ids)) {
            wp_set_object_terms($post_id, array_unique($assigned_term_ids), $taxonomy, false);
        }
    }

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

            if ($meta_key === '_linked_eval_board' || $meta_key === '_linked_eval_boards') {
                $val = trim($meta_value);
                if (! empty($val)) {
                    $incoming_codes = array_map('trim', explode(',', $val));
                    $matched_ids = [];

                    foreach ($incoming_codes as $code_or_title) {
                        if (empty($code_or_title)) continue;

                        $board_posts = get_posts([
                            'post_type'   => 'evaluation-board',
                            'meta_key'    => '_unique_code',
                            'meta_value'  => $code_or_title,
                            'numberposts' => 1,
                            'post_status' => 'any'
                        ]);

                        if (! empty($board_posts)) {
                            $matched_ids[] = $board_posts[0]->ID;
                        } else {
                            $board_by_title = get_posts([
                                'post_type'   => 'evaluation-board',
                                'title'       => $code_or_title,
                                'numberposts' => 1,
                                'post_status' => 'any',
                                'fields'      => 'ids'
                            ]);
                            if (!empty($board_by_title)) {
                                $matched_ids[] = $board_by_title[0];
                            }
                        }
                    }

                    if (!empty($matched_ids)) {
                        $product->update_meta_data('_linked_eval_boards', array_unique($matched_ids));
                    } else {
                        $product->delete_meta_data('_linked_eval_boards');
                    }
                } else {
                    $product->delete_meta_data('_linked_eval_boards');
                }

                $product->delete_meta_data('_linked_eval_board');
                continue;
            }

            if (preg_match('/^_document_category_(\d+)$/', $meta_key, $matches)) {
                $has_doc_updates = true;
                $incoming_docs[$matches[1]]['category'] = $meta_value;
                $product->delete_meta_data($meta_key);
                continue;
            }

            if (preg_match('/^_document_file_(\d+)$/', $meta_key, $matches)) {
                $has_doc_updates = true;
                $index = $matches[1];
                if (! empty($meta_value) && filter_var($meta_value, FILTER_VALIDATE_URL)) {
                    $attachment_id = $this->sideload_file_to_media_library($meta_value, 0);
                    if (! is_wp_error($attachment_id)) $incoming_docs[$index]['file'] = $attachment_id;
                } elseif (is_numeric($meta_value)) {
                    $incoming_docs[$index]['file'] = $meta_value;
                }
                $product->delete_meta_data($meta_key);
                continue;
            }

            if (preg_match('/^_document_name_(\d+)$/', $meta_key, $matches)) {
                $has_doc_updates = true;
                $index = $matches[1];
                $incoming_docs[$index]['name'] = $meta_value;
                $product->delete_meta_data($meta_key);
                continue;
            }

            if (preg_match('/^_(?:feature|featured)_(\d+)$/', $meta_key, $matches)) {
                $has_feature_updates = true;
                $index = $matches[1];
                $incoming_features[$index] = $meta_value;
                $product->delete_meta_data($meta_key);
                continue;
            }

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

        if ($has_doc_updates) {
            ksort($incoming_docs);
            $linked_doc_ids = [];

            foreach ($incoming_docs as $doc) {
                $name = isset($doc['name']) ? $doc['name'] : '';
                $file = isset($doc['file']) ? $doc['file'] : '';
                $cat_string = isset($doc['category']) ? $doc['category'] : '';

                if (! empty($name) || ! empty($file)) {

                    if (! empty($name)) {
                        $existing_cpt = get_posts([
                            'post_type'      => 'product-support',
                            'title'          => $name,
                            'post_status'    => 'any',
                            'posts_per_page' => 1,
                            'fields'         => 'ids'
                        ]);

                        $support_post_id = 0;
                        if (! empty($existing_cpt)) {
                            $support_post_id = $existing_cpt[0];
                        } else {
                            $support_post_id = wp_insert_post([
                                'post_title'  => $name,
                                'post_type'   => 'product-support',
                                'post_status' => 'publish'
                            ]);
                        }

                        if ($support_post_id && ! is_wp_error($support_post_id)) {
                            if ($file) update_post_meta($support_post_id, 'pdf_file', $file);

                            if (! empty($cat_string)) {
                                $this->assign_hierarchical_terms_to_post($support_post_id, $cat_string);
                            }

                            $linked_doc_ids[] = $support_post_id;
                        }
                    }
                }
            }

            if (! empty($linked_doc_ids)) {
                $existing_linked = $product->get_meta('_linked_documents');
                if (! is_array($existing_linked)) $existing_linked = [];

                $merged = array_unique(array_merge($existing_linked, $linked_doc_ids));
                $product->update_meta_data('_linked_documents', $merged);
            }

            $product->delete_meta_data('_documents');
        }

        if ($has_feature_updates) {
            ksort($incoming_features);
            $final_features = [];
            foreach ($incoming_features as $feature) {
                if (trim($feature) !== '') $final_features[] = $feature;
            }
            $product->update_meta_data('_features', $final_features);
        }

        if (! empty($gallery_image_ids)) {
            $existing_gallery = $product->get_gallery_image_ids();
            $merged_gallery = array_unique(array_merge($existing_gallery, $gallery_image_ids));
            $product->set_gallery_image_ids($merged_gallery);
        }

        return $product;
    }

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

    public function inject_repeater_assets()
    {
        $screen = get_current_screen();
        if (! $screen || ! in_array($screen->id, ['product', 'product-support', 'evaluation-board', 'woocommerce_page_silvertell-file-importer'], true)) return;
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

                // --- REPEATER LOGIC ---
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
                    template.find('img').parent().remove();

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

                // --- UNIVERSAL WP MEDIA LIBRARY INTEGRATION ---
                var mediaUploader;
                var currentTargetInput;

                $(document).on('click.ddRepeater', '.dd-upload-file', function(e) {
                    e.preventDefault();
                    currentTargetInput = $(this).siblings('.dd-file-input');

                    if (mediaUploader) {
                        mediaUploader.open();
                        return;
                    }

                    mediaUploader = wp.media.frames.file_frame = wp.media({
                        title: 'Select or Upload Media',
                        button: {
                            text: 'Use this file'
                        },
                        multiple: false
                    });

                    mediaUploader.on('select', function() {
                        var attachment = mediaUploader.state().get('selection').first().toJSON();
                        var isImage = (attachment.type === 'image');
                        var displayString = isImage ? 'Image Selected' : attachment.filename;

                        var syncClass = currentTargetInput.attr('data-sync');
                        var targetInputs = syncClass ? $('.' + syncClass) : currentTargetInput;

                        targetInputs.each(function() {
                            var input = $(this);
                            input.val(attachment.id).trigger('input');
                            input.siblings('.dd-file-display').text(displayString);

                            if (isImage) {
                                var previewContainer = input.parent().siblings('.dd-image-preview');
                                if (previewContainer.length === 0) {
                                    input.parent().after('<div class="dd-image-preview" style="margin-top:10px;"><img src="' + attachment.url + '" style="max-height:40px; border:1px solid #ddd; padding:3px; border-radius:4px;" /></div>');
                                } else {
                                    previewContainer.find('img').attr('src', attachment.url);
                                }
                            }
                        });
                    });

                    mediaUploader.open();
                });
            });
        </script>
<?php
    }
}

new Silvertell_Woocommerce_Customisation();
