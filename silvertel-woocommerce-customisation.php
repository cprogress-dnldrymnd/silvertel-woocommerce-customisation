<?php

/**
 * Plugin Name: Silvertell WooCommerce Customisations
 * Description: Custom modifications for WooCommerce, including dynamic file sideloading, CPT document generation, rock-solid hierarchical taxonomy building, native repeater fields, conditional UI sections, and Advanced AJAX Evaluation Board Importer.
 * Version: 2.46.0
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
    /** @var array Per-request memo of build_product_range() results, keyed by product ID. */
    private $range_cache = [];

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

        // Frontend Single Product Tabs (rendered by the native WC tabs / Elementor Product Data Tabs widget)
        add_filter('woocommerce_product_tabs', [$this, 'register_frontend_product_tabs'], 98);
        add_action('wp_footer', [$this, 'inject_frontend_assets']);

        // Shop/archive grid: the theme strips the native loop button, so add a
        // "View Product" link back onto each card (standard WC loop-button hook).
        add_action('woocommerce_after_shop_loop_item', [$this, 'add_shop_loop_view_button'], 10);

        // Product `.product-brand` block (rendered by the theme via
        // wc_get_product_category_list): on a category archive show the category
        // currently being viewed, and on the single product page show only the
        // top-level parent category instead of the deepest subcategory.
        add_filter('term_links-product_cat', [$this, 'filter_grid_brand_to_current_category']);
        add_action('wp', [$this, 'maybe_override_single_product_brand']);

        // Related products: relate only by the product's deepest (leaf) subcategory —
        // never its broad top-level category — and drop child products from the results.
        // When nothing shares that subcategory WooCommerce returns an empty set, so the
        // related-products block renders nothing and the section hides itself.
        add_filter('woocommerce_get_related_product_cat_terms', [$this, 'related_products_subcategory_terms'], 10, 2);
        add_filter('woocommerce_product_related_posts_relate_by_tag', '__return_false');
        add_filter('woocommerce_related_products', [$this, 'exclude_child_products_from_related'], 10, 3);

        // Elementor: register the standalone "Product Features" and "Buy Samples" widgets.
        add_action('elementor/widgets/register', [$this, 'register_elementor_widgets']);

        // Child products (sub-range groups / variants) live inside their parent's page,
        // so they must not be reachable as standalone single pages.
        add_action('template_redirect', [$this, 'redirect_child_product_pages']);

        // Admin Products list: hide child products (sub-range groups / variants). They are
        // managed inside their parent product's edit screen via the "Child Products" meta
        // box below, not as standalone rows in the list. Also keep them out of the status
        // counts (All / Published / Trash) shown above the list.
        add_action('pre_get_posts', [$this, 'hide_child_products_from_list']);
        add_filter('wp_count_posts', [$this, 'exclude_children_from_count'], 10, 2);

        // Frontend shop/archive: exclude child products from all WooCommerce product
        // listings — they surface only inside their parent's Product Range tab.
        add_action('woocommerce_product_query', [$this, 'hide_child_products_from_shop']);

        // Sidebar category-attribute filters (nested under each product category in the
        // Elementor category widget): narrow the shop/archive query by ?sil_pa_<attr>=…,
        // and invalidate the cached category→attribute map whenever the underlying data
        // could have changed.
        add_action('woocommerce_product_query', [$this, 'filter_products_by_attributes']);
        add_action('save_post_product', [$this, 'flush_category_attribute_map']);
        add_action('deleted_post', [$this, 'flush_category_attribute_map']);
        add_action('edited_term', [$this, 'flush_category_attribute_map']);
        add_action('delete_term', [$this, 'flush_category_attribute_map']);

        // Child Products manager on the product edit screen (tree list + add/delete + a
        // popup editor for Title / Content / Categories / SKU / Attributes / Buy Samples).
        add_action('add_meta_boxes', [$this, 'register_child_products_meta_box']);
        add_action('admin_footer', [$this, 'print_child_product_modal']);
        add_action('wp_ajax_silvertell_child_form', [$this, 'ajax_child_form']);
        add_action('wp_ajax_silvertell_child_save', [$this, 'ajax_child_save']);
        add_action('wp_ajax_silvertell_child_delete', [$this, 'ajax_child_delete']);

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
                    <div id="dd-eb-progress-fill" style="background: var( --e-global-color-c172edd ); height: 100%; width: 0%; transition: width 0.3s ease;"></div>
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
        echo '<span class="dd-file-display" style="font-weight:500; color:var( --e-global-color-c172edd ); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:300px;">' . esc_html($filename_display) . '</span>';
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

    private static function get_sample_providers()
    {
        $defaults = [
            ['name' => 'Farnell', 'meta_key' => '_farnell_url', 'logo' => ''],
            ['name' => 'Mouser', 'meta_key' => '_mouser_url', 'logo' => ''],
            ['name' => 'Digikey', 'meta_key' => '_digikey_url', 'logo' => ''],
            ['name' => 'Symmetry', 'meta_key' => '_symmetry_url', 'logo' => ''],
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
        $logo_url   = is_numeric($logo) ? wp_get_attachment_url($logo) : $logo;
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

        // Tab 1: Buy Samples — one URL per provider.
        echo '<div id="silvertell_buy_samples_data" class="panel woocommerce_options_panel dd-panel-wrapper">';
        $providers = $this->get_sample_providers();
        if (! empty($providers)) {
            echo '<div class="options_group" style="padding:12px 12px 4px;">';
            foreach ($providers as $provider) {
                $key   = $provider['meta_key'];
                $links = $this->get_provider_links($post->ID, $provider);
                $val   = ! empty($links) ? $links[0]['url'] : '';
                echo '<p class="form-field">';
                echo '<label>' . esc_html($provider['name']) . '</label>';
                echo '<input type="url" name="buy_samples[' . esc_attr($key) . ']" class="short" value="' . esc_attr($val) . '" />';
                echo '</p>';
            }
            echo '</div>';
        } else {
            echo '<p style="padding:15px;">No sample providers configured. Add them in WooCommerce &gt; Silvertell Settings.</p>';
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

    // ==============================================================================
    // FRONTEND SINGLE PRODUCT TABS
    //
    // Renders the admin-captured data on the product single page as native WooCommerce
    // product tabs. Elementor Pro's "Product Data Tabs" widget (or the theme's default
    // WC tabs) outputs these automatically via the woocommerce_product_tabs filter.
    // ==============================================================================

    public function register_frontend_product_tabs($tabs)
    {
        // Hide the Reviews tab and push Additional information to the end,
        // regardless of whether a product object is available in context.
        unset($tabs['reviews']);
        if (isset($tabs['additional_information'])) {
            $tabs['additional_information']['priority'] = 100;
        }

        global $product;
        if (! $product instanceof WC_Product) return $tabs;

        $product_id = $product->get_id();

        // NOTE: Features are no longer rendered as a product tab — they are output
        // via the dedicated "Product Features" Elementor widget (see
        // register_elementor_widgets()).

        if (! empty($this->build_product_range($product_id))) {
            $tabs['silvertell_product_range'] = [
                'title'    => __('Product Range', 'silvertell-wc-customisation'),
                'priority' => 20,
                'callback' => [$this, 'render_product_range_tab'],
            ];
        }

        $docs = get_post_meta($product_id, '_linked_documents', true);
        if (! empty($docs) && is_array($docs)) {
            $tabs['silvertell_documents'] = [
                'title'    => __('Documents', 'silvertell-wc-customisation'),
                'priority' => 25,
                'callback' => [$this, 'render_documents_tab'],
            ];
        }

        if (! empty($this->get_linked_eval_board_ids($product_id))) {
            $tabs['silvertell_eval_boards'] = [
                'title'    => __('Evaluation Boards', 'silvertell-wc-customisation'),
                'priority' => 30,
                'callback' => [$this, 'render_evaluation_boards_tab'],
            ];
        }

        return $tabs;
    }

    /**
     * A simple product with a parent is a sub-range group or variant whose content is
     * surfaced inside its top-level product's tabs, so it has no page of its own. Redirect
     * any direct hit on such a URL to the top-level ancestor product (301).
     */
    public function redirect_child_product_pages()
    {
        if (is_admin() || ! is_singular('product')) return;

        $post = get_queried_object();
        if (! $post instanceof WP_Post || empty($post->post_parent)) return;

        $ancestors = get_post_ancestors($post->ID); // immediate parent ... top-most
        $top_id    = ! empty($ancestors) ? end($ancestors) : (int) $post->post_parent;

        $url = get_permalink($top_id);
        if ($url) {
            wp_safe_redirect($url, 301);
            exit;
        }
    }

    // ==============================================================================
    // ADMIN PRODUCTS LIST — HIERARCHICAL (INDENTED) DISPLAY
    // ==============================================================================

    /**
     * Is the supplied query the main admin Products list, viewed plainly (no search,
     * filter or custom sort)? Only then do we hide child products — when the user is
     * searching/filtering/sorting we leave the list untouched so children stay findable.
     */
    private function is_plain_product_list_query($query)
    {
        if (! is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query()) return false;
        if (($GLOBALS['pagenow'] ?? '') !== 'edit.php') return false;
        if (($_GET['post_type'] ?? '') !== 'product') return false;

        // Bail out if the user is searching, filtering or sorting — show flat results.
        foreach (['s', 'orderby', 'product_cat', 'product_type', 'product_brand', 'stock_status'] as $key) {
            if (! empty($_GET[$key])) return false;
        }

        return true;
    }

    /**
     * Hide child products (any product with a post_parent) from the main Products list —
     * they are managed from inside their parent's edit screen. Searching/filtering still
     * shows everything so children remain findable.
     */
    public function hide_child_products_from_list($query)
    {
        if (! $this->is_plain_product_list_query($query)) return;

        $query->set('post_parent', 0);
    }

    /**
     * Exclude child products from frontend shop/archive/search listings. Called via
     * the `woocommerce_product_query` hook which fires only for WC's main product query.
     */
    public function hide_child_products_from_shop($query)
    {
        $query->set('post_parent', 0);
    }

    /**
     * Read the sidebar attribute filters from the URL: ?sil_pa_<attribute>=<slug>[,<slug>].
     * A dedicated `sil_` prefix (not WooCommerce's own `filter_<attr>`) so WC_Query never
     * builds its own tax_query for these — that would look for terms directly on the
     * top-level product, but the terms actually live on its hidden child/variant products.
     * Returns [ 'pa_x' => ['5v', '12v'], ... ], restricted to real global attributes.
     */
    private function get_active_attribute_filters()
    {
        $choices = $this->get_attribute_taxonomy_choices();
        $active  = [];
        foreach ($_GET as $key => $value) {
            if (! preg_match('/^sil_(pa_[a-z0-9_\-]+)$/', (string) $key, $m)) continue;
            $tax = $m[1];
            if (! isset($choices[$tax])) continue;
            $slugs = array_values(array_filter(array_map('sanitize_title', explode(',', (string) $value)), 'strlen'));
            if (! empty($slugs)) $active[$tax] = $slugs;
        }
        return $active;
    }

    /**
     * Narrow the shop/category product query to top-level products whose branch (self or
     * any descendant child/variant product) carries the selected attribute terms. OR
     * within one attribute's selected values, AND across different attributes.
     */
    public function filter_products_by_attributes($query)
    {
        $active = $this->get_active_attribute_filters();
        if (empty($active)) return;

        $matched_root_ids = null;
        foreach ($active as $tax => $slugs) {
            $terms = get_terms(['taxonomy' => $tax, 'slug' => $slugs, 'hide_empty' => false, 'fields' => 'ids']);
            if (is_wp_error($terms) || empty($terms)) {
                $matched_root_ids = [];
                break;
            }

            $object_ids = get_objects_in_term($terms, $tax);
            if (is_wp_error($object_ids) || empty($object_ids)) {
                $matched_root_ids = [];
                break;
            }

            $root_ids = [];
            foreach ($object_ids as $object_id) {
                $root_ids[$this->get_root_product_id($object_id)] = true;
            }
            $root_ids = array_keys($root_ids);

            $matched_root_ids = is_null($matched_root_ids) ? $root_ids : array_intersect($matched_root_ids, $root_ids);
            if (empty($matched_root_ids)) break;
        }

        $matched_root_ids = empty($matched_root_ids) ? [0] : array_values($matched_root_ids);

        $existing = $query->get('post__in');
        if (! empty($existing) && is_array($existing)) {
            $matched_root_ids = array_values(array_intersect($existing, $matched_root_ids));
            if (empty($matched_root_ids)) $matched_root_ids = [0];
        }
        $query->set('post__in', $matched_root_ids);
    }

    /**
     * Append/replace the sil_pa_* query args on an archive URL for a given set of
     * [ 'pa_x' => ['slug1','slug2'] ] filters. Empty filter arrays are omitted entirely.
     */
    private function build_filtered_archive_url($base_url, $filters)
    {
        $args = [];
        foreach ($filters as $tax => $slugs) {
            if (! empty($slugs)) $args['sil_' . $tax] = implode(',', $slugs);
        }
        return empty($args) ? $base_url : add_query_arg($args, $base_url);
    }

    /**
     * Build the JSON payload consumed by the sidebar attribute-filter injection script:
     * for every product_cat with attribute data, its archive URL plus, for each attribute,
     * its terms with a pre-computed href (toggling the term on/off when this is the
     * category currently being viewed, or a fresh single-value filter otherwise).
     */
    private function build_category_attribute_sidebar_payload()
    {
        $map = $this->get_category_attribute_map();
        if (empty($map)) return [];

        $queried_term_id = 0;
        if (function_exists('is_product_category') && is_product_category()) {
            $queried = get_queried_object();
            if ($queried instanceof WP_Term) $queried_term_id = (int) $queried->term_id;
        }
        $active_filters = $this->get_active_attribute_filters();

        $payload = [];
        foreach ($map as $cat_id => $taxes) {
            $term = get_term($cat_id, 'product_cat');
            if (! $term || is_wp_error($term)) continue;

            $archive_url = get_term_link($term);
            if (is_wp_error($archive_url)) continue;

            $is_current = ($cat_id === $queried_term_id);
            $cat_attrs  = [];

            foreach ($taxes as $tax => $data) {
                $terms_out = [];
                foreach ($data['terms'] as $slug => $term_data) {
                    $is_active = $is_current && ! empty($active_filters[$tax]) && in_array($slug, $active_filters[$tax], true);

                    if ($is_current) {
                        $new_filters = $active_filters;
                        $current_slugs = $new_filters[$tax] ?? [];
                        if ($is_active) {
                            $current_slugs = array_values(array_diff($current_slugs, [$slug]));
                        } else {
                            $current_slugs = array_values(array_unique(array_merge($current_slugs, [$slug])));
                        }
                        $new_filters[$tax] = $current_slugs;
                        $href = $this->build_filtered_archive_url($archive_url, $new_filters);
                    } else {
                        $href = $this->build_filtered_archive_url($archive_url, [$tax => [$slug]]);
                    }

                    $terms_out[] = [
                        'slug'   => $slug,
                        'name'   => $term_data['name'],
                        'count'  => $term_data['count'],
                        'active' => $is_active,
                        'href'   => $href,
                    ];
                }
                if (! empty($terms_out)) {
                    $cat_attrs[] = ['label' => $data['label'], 'terms' => $terms_out];
                }
            }

            if (empty($cat_attrs)) continue;

            $payload[$cat_id] = [
                'term_id'   => (int) $cat_id,
                'slug'      => $term->slug,
                'current'   => $is_current,
                'clear_url' => ($is_current && ! empty($active_filters)) ? $archive_url : '',
                'attrs'     => $cat_attrs,
            ];
        }

        return $payload;
    }

    /**
     * On the Products list screen, recompute the per-status counts (All / Published /
     * Trash …) to count only top-level products, matching the hidden-children list.
     */
    public function exclude_children_from_count($counts, $type)
    {
        if ($type !== 'product' || ! is_admin()) return $counts;
        if (($GLOBALS['pagenow'] ?? '') !== 'edit.php' || ($_GET['post_type'] ?? '') !== 'product') return $counts;

        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_status, COUNT(*) AS num FROM {$wpdb->posts} WHERE post_type = %s AND post_parent = 0 GROUP BY post_status",
                $type
            ),
            ARRAY_A
        );

        $map = [];
        foreach ($rows as $row) {
            $map[$row['post_status']] = (int) $row['num'];
        }

        foreach ($counts as $status => $value) {
            $counts->$status = isset($map[$status]) ? $map[$status] : 0;
        }

        return $counts;
    }

    // ==============================================================================
    // CHILD PRODUCTS MANAGER (parent edit screen + popup editor)
    // ==============================================================================

    public function register_child_products_meta_box()
    {
        add_meta_box(
            'silvertell_child_products',
            __('Child Products', 'silvertell-wc-customisation'),
            [$this, 'render_child_products_meta_box'],
            'product',
            'normal',
            'high'
        );
    }

    public function render_child_products_meta_box($post)
    {
        wp_nonce_field('silvertell_child_ajax', 'silvertell_child_nonce');
        echo '<div class="dd-children-manager" data-parent="' . esc_attr($post->ID) . '">';
        echo '<p class="description" style="margin:0 0 10px;">'
            . esc_html__('Sub-range groups and variants of this product. Edit them in a popup, or add/remove them here — they will not appear as separate rows in the Products list.', 'silvertell-wc-customisation')
            . '</p>';
        echo '<div class="dd-child-list-wrap">' . $this->render_child_products_list($post->ID) . '</div>';
        echo '<p style="margin-top:12px;"><button type="button" class="button button-primary dd-child-add" data-parent="' . esc_attr($post->ID) . '">'
            . esc_html__('Add Child Product', 'silvertell-wc-customisation') . '</button></p>';
        echo '</div>';
    }

    /**
     * Render the descendant tree of a product as nested rows with edit/add/delete actions.
     */
    private function render_child_products_list($parent_id, $depth = 0)
    {
        $children = get_children([
            'post_parent' => $parent_id,
            'post_type'   => 'product',
            'post_status' => ['publish', 'pending', 'draft', 'future', 'private'],
            'numberposts' => -1,
            'orderby'     => 'menu_order title',
            'order'       => 'ASC',
        ]);

        if (empty($children)) {
            return $depth === 0 ? '<p class="dd-child-empty">' . esc_html__('No child products yet.', 'silvertell-wc-customisation') . '</p>' : '';
        }

        $html = $depth === 0 ? '<div class="dd-child-list">' : '';
        foreach ($children as $child) {
            $product = wc_get_product($child->ID);
            $sku     = $product ? $product->get_sku() : '';
            $status  = $child->post_status !== 'publish' ? ' (' . esc_html($child->post_status) . ')' : '';

            $html .= '<div class="dd-child-row" data-id="' . esc_attr($child->ID) . '">';
            $html .= '<span class="dd-child-name" style="padding-left:' . ($depth * 22) . 'px;">'
                . ($depth ? '<span class="dd-child-twig">' . str_repeat('— ', $depth) . '</span>' : '')
                . esc_html($child->post_title) . $status . '</span>';
            $html .= '<span class="dd-child-sku">' . ($sku !== '' ? esc_html($sku) : '&mdash;') . '</span>';
            $html .= '<span class="dd-child-actions">';
            $html .= '<button type="button" class="button dd-child-edit" data-id="' . esc_attr($child->ID) . '">' . esc_html__('Edit', 'silvertell-wc-customisation') . '</button> ';
            $html .= '<button type="button" class="button dd-child-add" data-parent="' . esc_attr($child->ID) . '">' . esc_html__('Add Child', 'silvertell-wc-customisation') . '</button> ';
            $html .= '<button type="button" class="button dd-child-delete" data-id="' . esc_attr($child->ID) . '">' . esc_html__('Delete', 'silvertell-wc-customisation') . '</button>';
            $html .= '</span>';
            $html .= '</div>';

            $html .= $this->render_child_products_list($child->ID, $depth + 1);
        }
        if ($depth === 0) $html .= '</div>';

        return $html;
    }

    /**
     * AJAX: return the popup form for a child product (id = 0 for a new child).
     */
    public function ajax_child_form()
    {
        check_ajax_referer('silvertell_child_ajax', 'nonce');
        if (! current_user_can('edit_products')) wp_send_json_error(['message' => 'permission']);

        require_once ABSPATH . 'wp-admin/includes/template.php';
        if (! class_exists('Walker_Category_Checklist')) {
            require_once ABSPATH . 'wp-admin/includes/class-walker-category-checklist.php';
        }

        $child_id  = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $parent_id = isset($_POST['parent']) ? absint($_POST['parent']) : 0;
        $product   = $child_id ? wc_get_product($child_id) : null;

        $title   = $product ? $product->get_name() : '';
        $content = $product ? $product->get_description() : '';
        $short   = $product ? $product->get_short_description() : '';
        $sku     = $product ? $product->get_sku() : '';

        ob_start();
    ?>
        <input type="hidden" name="child_id" value="<?php echo esc_attr($child_id); ?>">
        <input type="hidden" name="parent_id" value="<?php echo esc_attr($parent_id); ?>">

        <div class="dd-modal-field">
            <label><?php esc_html_e('Title', 'silvertell-wc-customisation'); ?></label>
            <input type="text" name="post_title" class="dd-full-width" value="<?php echo esc_attr($title); ?>">
        </div>

        <div class="dd-modal-field">
            <label><?php esc_html_e('Description', 'silvertell-wc-customisation'); ?></label>
            <textarea name="post_content" rows="4" class="dd-full-width"><?php echo esc_textarea($content); ?></textarea>
        </div>

        <div class="dd-modal-field">
            <label><?php esc_html_e('Subtext', 'silvertell-wc-customisation'); ?></label>
            <textarea name="post_excerpt" rows="3" class="dd-full-width"><?php echo esc_textarea($short); ?></textarea>
        </div>

        <div class="dd-modal-field">
            <label><?php esc_html_e('SKU', 'silvertell-wc-customisation'); ?></label>
            <input type="text" name="_sku" class="dd-full-width" value="<?php echo esc_attr($sku); ?>">
        </div>

        <div class="dd-modal-field">
            <label><?php esc_html_e('Product Categories', 'silvertell-wc-customisation'); ?></label>
            <ul class="dd-cat-checklist">
                <?php wp_terms_checklist($child_id, ['taxonomy' => 'product_cat']); ?>
            </ul>
        </div>

        <div class="dd-modal-field">
            <label><?php esc_html_e('Attributes', 'silvertell-wc-customisation'); ?></label>
            <p class="description" style="margin:0 0 8px;"><?php esc_html_e('Pick a global attribute and its value(s), or choose "Custom" to type a name and value (use "|" for multiple values).', 'silvertell-wc-customisation'); ?></p>
            <div class="dd-attr-rows">
                <?php
                $index = 0;
                if ($product) {
                    foreach ($product->get_attributes() as $attr) {
                        if (! is_object($attr)) continue;
                        if (method_exists($attr, 'is_taxonomy') && $attr->is_taxonomy()) {
                            $this->render_child_attr_row($index, $attr->get_name(), '', '', $attr->get_options());
                        } else {
                            $this->render_child_attr_row($index, '', $attr->get_name(), implode(' | ', $attr->get_options()), []);
                        }
                        $index++;
                    }
                }
                ?>
            </div>
            <button type="button" class="button dd-attr-add" style="margin-top:6px;"><?php esc_html_e('Add Attribute', 'silvertell-wc-customisation'); ?></button>
            <script type="text/template" class="dd-attr-template"><?php $this->render_child_attr_row('__INDEX__'); ?></script>
        </div>

        <div class="dd-modal-field">
            <label><?php esc_html_e('Buy Samples', 'silvertell-wc-customisation'); ?></label>
            <?php foreach ($this->get_sample_providers() as $provider) :
                // The modal stores a single URL per provider; coerce in case the product
                // also has a multi-link array from the full-editor Buy Samples tab.
                $links = $child_id ? $this->get_provider_links($child_id, $provider) : [];
                $val   = ! empty($links) ? $links[0]['url'] : ''; ?>
                <div class="dd-modal-subfield">
                    <span class="dd-modal-sublabel"><?php echo esc_html($provider['name']); ?></span>
                    <input type="url" name="buy_samples[<?php echo esc_attr($provider['meta_key']); ?>]" class="dd-full-width" value="<?php echo esc_attr($val); ?>">
                </div>
            <?php endforeach; ?>
        </div>
    <?php
        wp_send_json_success(['html' => ob_get_clean()]);
    }

    /**
     * Render one attribute row: a global-attribute (taxonomy) selector with a multi-select
     * of its terms, plus name/value inputs used when "Custom" is chosen.
     * * @param string|int $index The array index for the posted attrs array.
     * @param string $tax The taxonomy slug if global.
     * @param string $name The custom attribute name.
     * @param string $value The custom attribute values (pipe separated).
     * @param array $selected_terms Array of term IDs.
     */
    private function render_child_attr_row($index, $tax = '', $name = '', $value = '', $selected_terms = [])
    {
        $choices        = $this->get_attribute_taxonomy_choices();
        $is_global      = $tax !== '' && isset($choices[$tax]);
        $selected_terms = array_map('intval', (array) $selected_terms);
        $base           = 'attrs[' . $index . ']';
    ?>
        <div class="dd-attr-row">
            <div class="dd-attr-head">
                <select name="<?php echo esc_attr($base); ?>[tax]" class="dd-attr-tax">
                    <option value=""><?php esc_html_e('— Custom —', 'silvertell-wc-customisation'); ?></option>
                    <?php foreach ($choices as $slug => $label) : ?>
                        <option value="<?php echo esc_attr($slug); ?>" <?php selected($tax, $slug); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="<?php echo esc_attr($base); ?>[name]" class="dd-attr-name" placeholder="<?php esc_attr_e('Name', 'silvertell-wc-customisation'); ?>" value="<?php echo esc_attr($name); ?>" <?php echo $is_global ? ' style="display:none;"' : ''; ?>>
                <button type="button" class="button dd-attr-del" title="<?php esc_attr_e('Remove', 'silvertell-wc-customisation'); ?>">
                    <span class="dashicons dashicons-trash" style="margin-top: 3px;"></span>
                </button>
            </div>
            <div class="dd-attr-body">
                <input type="text" name="<?php echo esc_attr($base); ?>[value]" class="dd-attr-value" placeholder="<?php esc_attr_e('Value (use | for multiple)', 'silvertell-wc-customisation'); ?>" value="<?php echo esc_attr($value); ?>" <?php echo $is_global ? ' style="display:none;"' : ''; ?>>
                <div class="dd-attr-value-global" <?php echo $is_global ? '' : ' style="display:none;"'; ?>>
                    <label class="dd-attr-value-label"><?php esc_html_e('Value(s):', 'silvertell-wc-customisation'); ?></label>
                    <select name="<?php echo esc_attr($base); ?>[terms][]" class="dd-attr-terms" multiple data-placeholder="<?php esc_attr_e('Select values', 'silvertell-wc-customisation'); ?>">
                        <?php
                        if ($is_global) {
                            $terms = get_terms(['taxonomy' => $tax, 'hide_empty' => false]);
                            if (! is_wp_error($terms)) {
                                foreach ($terms as $term) {
                                    echo '<option value="' . esc_attr($term->term_id) . '" ' . selected(in_array((int) $term->term_id, $selected_terms, true), true, false) . '>' . esc_html($term->name) . '</option>';
                                }
                            }
                        }
                        ?>
                    </select>
                    <div class="dd-attr-term-actions">
                        <button type="button" class="button dd-attr-select-all"><?php esc_html_e('Select all', 'silvertell-wc-customisation'); ?></button>
                        <button type="button" class="button dd-attr-select-none"><?php esc_html_e('Select none', 'silvertell-wc-customisation'); ?></button>
                        <button type="button" class="button button-primary dd-attr-create"><?php esc_html_e('Create value', 'silvertell-wc-customisation'); ?></button>
                    </div>
                </div>
            </div>
        </div>
    <?php
    }

    /**
     * [ taxonomy slug => label ] for every registered global product attribute.
     */
    private function get_attribute_taxonomy_choices()
    {
        $choices = [];
        if (! function_exists('wc_get_attribute_taxonomies')) return $choices;
        foreach (wc_get_attribute_taxonomies() as $tax) {
            $slug = wc_attribute_taxonomy_name($tax->attribute_name);
            $choices[$slug] = $tax->attribute_label ? $tax->attribute_label : $tax->attribute_name;
        }
        return $choices;
    }

    /**
     * [ taxonomy slug => [ ['id'=>, 'name'=>], ... ] ] of terms, for the popup's JS to
     * repopulate the term selector when the chosen global attribute changes.
     */
    private function get_attribute_terms_map()
    {
        $map = [];
        foreach (array_keys($this->get_attribute_taxonomy_choices()) as $slug) {
            $map[$slug] = [];
            $terms = get_terms(['taxonomy' => $slug, 'hide_empty' => false]);
            if (is_wp_error($terms)) continue;
            foreach ($terms as $term) {
                $map[$slug][] = ['id' => (int) $term->term_id, 'name' => $term->name];
            }
        }
        return $map;
    }

    /**
     * AJAX: create or update a child product from the popup form, then return the
     * refreshed child list for the root parent.
     */
    public function ajax_child_save()
    {
        check_ajax_referer('silvertell_child_ajax', 'nonce');
        if (! current_user_can('edit_products')) wp_send_json_error(['message' => 'permission']);

        $child_id  = isset($_POST['child_id']) ? absint($_POST['child_id']) : 0;
        $parent_id = isset($_POST['parent_id']) ? absint($_POST['parent_id']) : 0;
        $root      = isset($_POST['root']) ? absint($_POST['root']) : 0;

        $product = $child_id ? wc_get_product($child_id) : new WC_Product_Simple();
        if (! $product) wp_send_json_error(['message' => 'not_found']);

        $product->set_name(sanitize_text_field(wp_unslash($_POST['post_title'] ?? '')));
        $product->set_description(wp_kses_post(wp_unslash($_POST['post_content'] ?? '')));
        $product->set_short_description(wp_kses_post(wp_unslash($_POST['post_excerpt'] ?? '')));
        $product->set_status('publish');

        if (! $child_id && $parent_id) {
            $product->set_parent_id($parent_id);
        }

        // SKU (guard against duplicates).
        $sku = sanitize_text_field(wp_unslash($_POST['_sku'] ?? ''));
        try {
            $product->set_sku($sku);
        } catch (Exception $e) {
            wp_send_json_error(['message' => sprintf(__('SKU "%s" is already in use.', 'silvertell-wc-customisation'), $sku)]);
        }

        // Categories.
        $cat_ids = [];
        if (isset($_POST['tax_input']['product_cat']) && is_array($_POST['tax_input']['product_cat'])) {
            $cat_ids = array_values(array_filter(array_map('absint', $_POST['tax_input']['product_cat'])));
        }
        $product->set_category_ids($cat_ids);

        // Attributes — each row is either a global (taxonomy) attribute with selected
        // terms, or a custom name/value pair. All saved as visible, non-variation.
        $attrs_in     = isset($_POST['attrs']) && is_array($_POST['attrs']) ? wp_unslash($_POST['attrs']) : [];
        $attributes   = [];
        $tax_term_map = [];
        foreach ($attrs_in as $row) {
            if (! is_array($row)) continue;
            $tax = isset($row['tax']) ? sanitize_text_field($row['tax']) : '';

            if ($tax !== '' && taxonomy_exists($tax)) {
                $term_ids = isset($row['terms']) ? array_values(array_filter(array_map('absint', (array) $row['terms']))) : [];
                if (empty($term_ids)) continue;

                $attribute = new WC_Product_Attribute();
                $attribute->set_id(wc_attribute_taxonomy_id_by_name($tax));
                $attribute->set_name($tax);
                $attribute->set_options($term_ids);
                $attribute->set_position(count($attributes));
                $attribute->set_visible(true);
                $attribute->set_variation(false);
                $attributes[] = $attribute;

                $tax_term_map[$tax] = array_merge($tax_term_map[$tax] ?? [], $term_ids);
            } else {
                $name = isset($row['name']) ? sanitize_text_field($row['name']) : '';
                if ($name === '') continue;
                $raw_val = isset($row['value']) ? $row['value'] : '';
                $options = array_values(array_filter(array_map('trim', explode('|', $raw_val)), 'strlen'));

                $attribute = new WC_Product_Attribute();
                $attribute->set_id(0);
                $attribute->set_name($name);
                $attribute->set_options($options);
                $attribute->set_position(count($attributes));
                $attribute->set_visible(true);
                $attribute->set_variation(false);
                $attributes[] = $attribute;
            }
        }
        $product->set_attributes($attributes);

        $saved_id = $product->save();
        if (! $saved_id) wp_send_json_error(['message' => 'save_failed']);

        // Assign/clear the term relationships for every global attribute taxonomy so the
        // frontend (get_attribute) resolves them to term names.
        foreach (array_keys($this->get_attribute_taxonomy_choices()) as $slug) {
            wp_set_object_terms($saved_id, $tax_term_map[$slug] ?? [], $slug, false);
        }

        // Buy Samples meta.
        $providers   = $this->get_sample_providers();
        $valid_keys  = wp_list_pluck($providers, 'meta_key');
        $buy_samples = isset($_POST['buy_samples']) && is_array($_POST['buy_samples']) ? wp_unslash($_POST['buy_samples']) : [];
        foreach ($buy_samples as $key => $url) {
            if (! in_array($key, $valid_keys, true)) continue;
            update_post_meta($saved_id, $key, sanitize_url($url));
        }

        if (! $root) $root = $parent_id ?: $saved_id;
        $this->flush_category_attribute_map();
        wp_send_json_success(['list' => $this->render_child_products_list($root)]);
    }

    /**
     * AJAX: permanently delete a child product (and its descendants), then return the
     * refreshed list.
     */
    public function ajax_child_delete()
    {
        check_ajax_referer('silvertell_child_ajax', 'nonce');
        if (! current_user_can('delete_products')) wp_send_json_error(['message' => 'permission']);

        $child_id = isset($_POST['id']) ? absint($_POST['id']) : 0;
        $root     = isset($_POST['root']) ? absint($_POST['root']) : 0;
        if (! $child_id) wp_send_json_error(['message' => 'no_id']);

        $this->delete_product_branch($child_id);

        $this->flush_category_attribute_map();
        wp_send_json_success(['list' => $this->render_child_products_list($root)]);
    }

    /**
     * Permanently delete a product and all of its descendant products (depth-first) so
     * none are left orphaned.
     */
    private function delete_product_branch($product_id)
    {
        $children = get_children([
            'post_parent' => $product_id,
            'post_type'   => 'product',
            'post_status' => 'any',
            'numberposts' => -1,
            'fields'      => 'ids',
        ]);
        foreach ($children as $cid) {
            $this->delete_product_branch($cid);
        }
        wp_delete_post($product_id, true);
    }

    /**
     * Print the shared popup markup, styles and behaviour once on the product edit screen.
     */
    public function print_child_product_modal()
    {
        $screen = get_current_screen();
        if (! $screen || $screen->post_type !== 'product' || $screen->base !== 'post') return;
    ?>
        <div id="dd-child-modal" class="dd-child-modal" style="display:none;">
            <div class="dd-child-modal-overlay"></div>
            <div class="dd-child-modal-box">
                <div class="dd-child-modal-head">
                    <h2 class="dd-child-modal-title"><?php esc_html_e('Edit Child Product', 'silvertell-wc-customisation'); ?></h2>
                    <button type="button" class="dd-child-modal-close" aria-label="Close">&times;</button>
                </div>
                <form class="dd-child-modal-form">
                    <div class="dd-child-modal-body"></div>
                </form>
                <div class="dd-child-modal-foot">
                    <span class="dd-child-modal-msg"></span>
                    <button type="button" class="button button-primary dd-child-modal-save"><?php esc_html_e('Save', 'silvertell-wc-customisation'); ?></button>
                    <button type="button" class="button dd-child-modal-cancel"><?php esc_html_e('Cancel', 'silvertell-wc-customisation'); ?></button>
                </div>
            </div>
        </div>

        <style>
            .dd-child-list-wrap {
                margin-bottom: 8px;
            }

            .dd-child-row {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 8px 6px;
                border-bottom: 1px solid #f0f0f1;
            }

            .dd-child-row:hover {
                background: #f6f7f7;
            }

            .dd-child-name {
                flex: 1;
                font-weight: 600;
            }

            .dd-child-twig {
                color: #a0a5aa;
                font-weight: 400;
            }

            .dd-child-sku {
                width: 140px;
                color: #646970;
            }

            .dd-child-actions {
                white-space: nowrap;
            }

            .dd-child-empty {
                color: #646970;
                font-style: italic;
            }

            /* Modal Core */
            .dd-child-modal {
                position: fixed;
                inset: 0;
                z-index: 100000;
            }

            .dd-child-modal-overlay {
                position: absolute;
                inset: 0;
                background: rgba(0, 0, 0, .6);
            }

            .dd-child-modal-box {
                position: relative;
                max-width: 700px;
                margin: 5vh auto;
                background: #fff;
                border-radius: 6px;
                box-shadow: 0 10px 40px rgba(0, 0, 0, .3);
                display: flex;
                flex-direction: column;
                max-height: 90vh;
            }

            .dd-child-modal-head {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 16px 24px;
                border-bottom: 1px solid #dcdcde;
                background: #fcfcfc;
                border-radius: 6px 6px 0 0;
            }

            .dd-child-modal-head h2 {
                margin: 0;
                font-size: 16px;
                font-weight: 600;
            }

            .dd-child-modal-close {
                background: none;
                border: none;
                font-size: 24px;
                line-height: 1;
                cursor: pointer;
                color: #646970;
                transition: color 0.2s;
            }

            .dd-child-modal-close:hover {
                color: #d63638;
            }

            .dd-child-modal-form {
                overflow: auto;
            }

            .dd-child-modal-body {
                padding: 24px;
            }

            .dd-child-modal-foot {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 16px 24px;
                border-top: 1px solid #dcdcde;
                background: #fcfcfc;
                border-radius: 0 0 6px 6px;
            }

            .dd-child-modal-foot .dd-child-modal-msg {
                flex: 1;
                color: #d63638;
                font-weight: 500;
            }

            .dd-child-modal.is-saving .dd-child-modal-box {
                opacity: .6;
                pointer-events: none;
            }

            /* General Fields */
            .dd-modal-field {
                margin-bottom: 20px;
            }

            .dd-modal-field>label {
                display: block;
                font-weight: 600;
                margin-bottom: 6px;
                color: #1d2327;
            }

            .dd-full-width {
                width: 100%;
            }

            .dd-cat-checklist {
                max-height: 180px;
                overflow: auto;
                border: 1px solid #dcdcde;
                padding: 12px;
                margin: 0;
                border-radius: 4px;
                background: #fff;
            }

            .dd-cat-checklist ul.children {
                margin-left: 18px;
            }

            .dd-modal-subfield {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 8px;
            }

            .dd-modal-sublabel {
                width: 100px;
                color: #50575e;
                font-weight: 500;
            }

            /* Styled Attribute Repeater Row */
            .dd-attr-row {
                border: 1px solid #dcdcde;
                border-radius: 4px;
                margin-bottom: 15px;
                background: #fff;
                box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
                overflow: hidden;
            }

            .dd-attr-head {
                display: flex;
                gap: 12px;
                align-items: center;
                padding: 10px 16px;
                background: #f6f7f7;
                border-bottom: 1px solid #dcdcde;
            }

            .dd-attr-head .dd-attr-tax {
                flex: 0 0 220px;
                max-width: 50%;
            }

            .dd-attr-head .dd-attr-name {
                flex: 1;
            }

            .dd-attr-head .dd-attr-del {
                margin-left: auto;
                color: #d63638;
                border-color: transparent;
                background: transparent;
                padding: 0 6px;
            }

            .dd-attr-head .dd-attr-del:hover {
                background: #d63638;
                color: #fff;
                border-color: #d63638;
            }

            .dd-attr-body {
                padding: 16px;
            }

            .dd-attr-body .dd-attr-value {
                width: 100%;
            }

            .dd-attr-value-label {
                display: block;
                font-weight: 600;
                margin-bottom: 8px;
                color: #1d2327;
            }

            /* Attribute Terms & Buttons */
            .dd-attr-terms {
                width: 100%;
            }

            .dd-attr-term-actions {
                margin-top: 12px;
                display: flex;
                gap: 8px;
                align-items: center;
            }

            .dd-attr-term-actions .button {
                font-size: 12px;
                padding: 0 10px;
                min-height: 26px;
                line-height: 24px;
            }

            .dd-attr-term-actions .dd-attr-create {
                margin-left: auto;
            }

            .select2-container--default .select2-selection--multiple {
                border-color: #dcdcde;
                border-radius: 4px;
                min-height: 34px;
            }
        </style>

        <script>
            (function($) {
                var nonce = $('#silvertell_child_nonce').val();
                var $modal = $('#dd-child-modal');
                var $manager = $('.dd-children-manager');
                var rootParent = $manager.data('parent');
                var ddAttrTerms = <?php echo wp_json_encode($this->get_attribute_terms_map()); ?>;
                var wcAddAttrNonce = '<?php echo esc_js(wp_create_nonce('add-attribute')); ?>';
                var attrIdx = 0;

                /**
                 * Dynamically evaluate the select2 instance function.
                 */
                function getS2Fn() {
                    return $.fn.selectWoo ? 'selectWoo' : ($.fn.select2 ? 'select2' : null);
                }

                /**
                 * Initialize the select2/selectWoo interface.
                 * @param {jQuery} $sel The select element.
                 */
                function s2Init($sel) {
                    var s2fn = getS2Fn();
                    if (!s2fn) return;
                    $sel[s2fn]({
                        width: '100%',
                        placeholder: $sel.data('placeholder') || ''
                    });
                }

                /**
                 * Destroy the select2/selectWoo instance.
                 * @param {jQuery} $sel The select element.
                 */
                function s2Destroy($sel) {
                    var s2fn = getS2Fn();
                    if (s2fn && $sel.hasClass('select2-hidden-accessible')) {
                        try {
                            $sel[s2fn]('destroy');
                        } catch (e) {}
                    }
                }

                function initAttrRows() {
                    $modal.find('.dd-attr-row').each(function() {
                        var $row = $(this);
                        if ($row.find('.dd-attr-tax').val()) s2Init($row.find('.dd-attr-terms'));
                    });
                }

                function syncAttrRow($row) {
                    var tax = $row.find('.dd-attr-tax').val();
                    var $name = $row.find('.dd-attr-name');
                    var $value = $row.find('.dd-attr-value');
                    var $global = $row.find('.dd-attr-value-global');
                    var $terms = $row.find('.dd-attr-terms');

                    s2Destroy($terms);
                    $terms.empty();

                    if (tax) {
                        (ddAttrTerms[tax] || []).forEach(function(o) {
                            $terms.append(new Option(o.name, o.id, false, false));
                        });
                        $name.hide();
                        $value.hide();
                        $global.show();
                        s2Init($terms);
                    } else {
                        $global.hide();
                        $name.show();
                        $value.show();
                    }
                }

                function openModal(id, parent, title) {
                    $modal.find('.dd-child-modal-title').text(title);
                    $modal.find('.dd-child-modal-msg').text('');
                    $modal.find('.dd-child-modal-body').html('<p>Loading…</p>');
                    $modal.show();

                    $.post(ajaxurl, {
                        action: 'silvertell_child_form',
                        nonce: nonce,
                        id: id,
                        parent: parent
                    }, function(res) {
                        if (res && res.success) {
                            $modal.find('.dd-child-modal-body').html(res.data.html);
                            initAttrRows();
                        } else {
                            $modal.find('.dd-child-modal-body').html('<p>Could not load the form.</p>');
                        }
                    });
                }

                function closeModal() {
                    $modal.hide();
                }

                $manager.on('click', '.dd-child-edit', function() {
                    openModal($(this).data('id'), 0, '<?php echo esc_js(__('Edit Child Product', 'silvertell-wc-customisation')); ?>');
                });

                $manager.on('click', '.dd-child-add', function() {
                    openModal(0, $(this).data('parent'), '<?php echo esc_js(__('Add Child Product', 'silvertell-wc-customisation')); ?>');
                });

                $manager.on('click', '.dd-child-delete', function() {
                    if (!confirm('<?php echo esc_js(__('Permanently delete this child product and its sub-items? This cannot be undone.', 'silvertell-wc-customisation')); ?>')) return;
                    $.post(ajaxurl, {
                        action: 'silvertell_child_delete',
                        nonce: nonce,
                        id: $(this).data('id'),
                        root: rootParent
                    }, function(res) {
                        if (res && res.success) $('.dd-child-list-wrap').html(res.data.list);
                    });
                });

                $modal.on('click', '.dd-child-modal-close, .dd-child-modal-cancel, .dd-child-modal-overlay', closeModal);

                $modal.on('click', '.dd-attr-add', function() {
                    var tpl = $('.dd-attr-template').html().replace(/__INDEX__/g, 'new' + (attrIdx++));
                    $(this).closest('.dd-modal-field').find('.dd-attr-rows').append(tpl);
                });

                $modal.on('click', '.dd-attr-del', function() {
                    var $row = $(this).closest('.dd-attr-row');
                    s2Destroy($row.find('.dd-attr-terms'));
                    $row.remove();
                });

                $modal.on('change', '.dd-attr-tax', function() {
                    syncAttrRow($(this).closest('.dd-attr-row'));
                });

                $modal.on('click', '.dd-attr-select-all', function() {
                    var $t = $(this).closest('.dd-attr-row').find('.dd-attr-terms');
                    $t.find('option').prop('selected', true);
                    $t.trigger('change');
                });

                $modal.on('click', '.dd-attr-select-none', function() {
                    var $t = $(this).closest('.dd-attr-row').find('.dd-attr-terms');
                    $t.find('option').prop('selected', false);
                    $t.trigger('change');
                });

                $modal.on('click', '.dd-attr-create', function() {
                    var $row = $(this).closest('.dd-attr-row');
                    var tax = $row.find('.dd-attr-tax').val();
                    if (!tax) return;

                    var name = window.prompt('<?php echo esc_js(__('Enter a new value name:', 'silvertell-wc-customisation')); ?>');
                    if (!name) return;

                    var $t = $row.find('.dd-attr-terms');

                    $.post(ajaxurl, {
                        action: 'woocommerce_add_new_attribute',
                        taxonomy: tax,
                        term: name,
                        security: wcAddAttrNonce
                    }, function(res) {
                        if (res && res.term_id) {
                            $t.append(new Option(res.name, res.term_id, true, true)).trigger('change');
                            (ddAttrTerms[tax] = ddAttrTerms[tax] || []).push({
                                id: res.term_id,
                                name: res.name
                            });
                        } else {
                            var errorMsg = (res && res.data && res.data.error) ? res.data.error : '<?php echo esc_js(__('Could not create the value.', 'silvertell-wc-customisation')); ?>';
                            alert(errorMsg);
                        }
                    });
                });

                $modal.on('click', '.dd-child-modal-save', function() {
                    var $btn = $(this);
                    var data = $modal.find('.dd-child-modal-form').serialize();

                    data += '&action=silvertell_child_save&nonce=' + encodeURIComponent(nonce) + '&root=' + encodeURIComponent(rootParent);
                    $modal.addClass('is-saving');
                    $modal.find('.dd-child-modal-msg').text('');

                    $.post(ajaxurl, data, function(res) {
                        $modal.removeClass('is-saving');
                        if (res && res.success) {
                            $('.dd-child-list-wrap').html(res.data.list);
                            closeModal();
                        } else {
                            $modal.find('.dd-child-modal-msg').text((res && res.data && res.data.message) ? res.data.message : 'Save failed.');
                        }
                    });
                });
            })(jQuery);
        </script>
    <?php
    }

    /**
     * Resolve a product's linked evaluation board IDs, honouring the legacy
     * single-value `_linked_eval_board` meta as a fallback (mirrors the admin panel).
     */
    private function get_linked_eval_board_ids($product_id)
    {
        $eb_ids = get_post_meta($product_id, '_linked_eval_boards', true);
        if (! is_array($eb_ids) || empty($eb_ids)) {
            $old_single = get_post_meta($product_id, '_linked_eval_board', true);
            $eb_ids = $old_single ? [(int) $old_single] : [];
        }
        return array_values(array_filter(array_map('intval', (array) $eb_ids)));
    }

    /**
     * Build the HTML for a product's feature list. Shared by the "Product Features"
     * Elementor widget (the features are no longer rendered as a WooCommerce tab).
     * Returns an empty string when the product has no usable features.
     */
    public static function get_features_list_html($product_id)
    {
        $features = get_post_meta($product_id, '_features', true);
        if (empty($features) || ! is_array($features)) return '';

        $items = '';
        foreach ($features as $feature) {
            if (trim($feature) === '') continue;
            $items .= '<li>' . esc_html($feature) . '</li>';
        }
        if ($items === '') return '';

        return '<div class="dd-tab-content dd-features-tab"><ul class="dd-feature-list">' . $items . '</ul></div>';
    }

    /**
     * Register the standalone "Product Features" Elementor widget. Fired on
     * `elementor/widgets/register`, so \Elementor\Widget_Base is guaranteed loaded.
     */
    public function register_elementor_widgets($widgets_manager)
    {
        if (! class_exists('\Elementor\Widget_Base')) return;

        // The widget class is declared by a top-level function (PHP forbids
        // declaring a class inside a class method) that runs lazily here, once
        // Elementor's base class is guaranteed to exist.
        silvertell_define_features_elementor_widget();
        silvertell_define_buy_samples_elementor_widget();
        silvertell_define_document_link_elementor_widget();

        if (class_exists('Silvertell_Features_Elementor_Widget')) {
            $widgets_manager->register(new Silvertell_Features_Elementor_Widget());
        }
        if (class_exists('Silvertell_Buy_Samples_Elementor_Widget')) {
            $widgets_manager->register(new Silvertell_Buy_Samples_Elementor_Widget());
        }
        if (class_exists('Silvertell_Document_Link_Elementor_Widget')) {
            $widgets_manager->register(new Silvertell_Document_Link_Elementor_Widget());
        }
    }

    public function render_documents_tab()
    {
        global $product;
        if (! $product instanceof WC_Product) return;

        $doc_ids = get_post_meta($product->get_id(), '_linked_documents', true);
        if (empty($doc_ids) || ! is_array($doc_ids)) return;

        $docs = [];
        foreach ($doc_ids as $doc_id) {
            $doc_id = (int) $doc_id;
            if (! $doc_id) continue;

            $file_val = get_post_meta($doc_id, 'pdf_file', true);
            $file_url = is_numeric($file_val) ? wp_get_attachment_url($file_val) : $file_val;
            if (empty($file_url)) continue;

            $docs[] = ['title' => get_the_title($doc_id), 'url' => $file_url];
        }

        if (empty($docs)) return;

        echo '<div class="dd-tab-content dd-documents-tab">';
        echo '<div class="dd-doc-grid">';
        foreach ($docs as $doc) {
            echo $this->render_download_button($doc['title'], $doc['url']);
        }
        echo '</div>';
        echo '</div>';
    }

    /**
     * A button-style download link with a leading download icon. Shared by the Documents
     * tab and the Evaluation Board manual.
     */
    private function render_download_button($label, $url)
    {
        $icon = '<svg class="dd-doc-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>';

        // PDFs open inline in a new tab; other file types keep force-downloading.
        $download_attr = silvertell_url_is_pdf($url) ? '' : ' download';

        return '<a class="dd-doc-btn" href="' . esc_url($url) . '" target="_blank" rel="noopener"' . $download_attr . '>'
            . $icon
            . '<span class="dd-doc-btn-label">' . esc_html($label) . '</span></a>';
    }

    public function render_evaluation_boards_tab()
    {
        global $product;
        if (! $product instanceof WC_Product) return;

        $eb_ids = $this->get_linked_eval_board_ids($product->get_id());
        if (empty($eb_ids)) return;

        echo '<div class="dd-tab-content dd-eval-boards-tab">';
        foreach ($eb_ids as $eb_id) {
            $this->render_eval_board_card($eb_id);

            // Orderable child boards (e.g. per-voltage variants), grouped by category.
            $children = get_children([
                'post_parent' => $eb_id,
                'post_type'   => 'evaluation-board',
                'post_status' => 'publish',
                'numberposts' => -1,
                'orderby'     => 'menu_order title',
                'order'       => 'ASC',
            ]);
            if (! empty($children)) {
                $this->render_eval_board_children_table($children);
            }
        }
        echo '</div>';
    }

    private function render_eval_board_card($eb_id)
    {
        $post = get_post($eb_id);
        if (! $post) return;

        echo '<div class="dd-eb-card">';

        $thumb = get_the_post_thumbnail($eb_id, 'large', ['class' => 'dd-eb-image']);
        if ($thumb) echo '<div class="dd-eb-image-wrap">' . $thumb . '</div>';

        echo '<div class="dd-eb-body">';
        echo '<h3 class="dd-eb-title">' . esc_html($post->post_title) . '</h3>';

        if (trim($post->post_content) !== '') {
            echo '<div class="dd-eb-desc">' . wp_kses_post(wpautop($post->post_content)) . '</div>';
        }

        $notes = get_post_meta($eb_id, '_notes', true);
        if (! empty($notes)) {
            echo '<div class="dd-eb-notes">' . wp_kses_post(wpautop($notes)) . '</div>';
        }

        $manual     = get_post_meta($eb_id, '_manual', true);
        $manual_url = is_numeric($manual) ? wp_get_attachment_url($manual) : $manual;
        if (! empty($manual_url)) {
            echo '<div class="dd-eb-manual dd-doc-grid">' . $this->render_download_button(__('User Manual (PDF)', 'silvertell-wc-customisation'), $manual_url) . '</div>';
        }

        $buttons = $this->render_provider_buttons($eb_id);
        if (! empty($buttons)) echo '<div class="dd-buy-wrap">' . $buttons . '</div>';

        echo '</div></div>';
    }

    private function render_eval_board_children_table($children)
    {
        // Group children by their (first) evaluation-board-category term.
        $groups = [];
        foreach ($children as $child) {
            $terms = get_the_terms($child->ID, 'evaluation-board-category');
            $key   = (! empty($terms) && ! is_wp_error($terms)) ? $terms[0]->name : '';
            $groups[$key][] = $child;
        }

        // When the boards fall into more than one named group (e.g. New Preferred /
        // Extended Range), present them as sub-tabs, mirroring the Product Range tab.
        // The .dd-range-tabs markup is shared so the same switcher JS/CSS drives both.
        $named = array_filter(array_keys($groups), function ($k) {
            return $k !== '';
        });
        if (count($named) > 1) {
            $uid = 'dd-ebrange-' . uniqid();
            echo '<div class="dd-range-tabs dd-eb-range-tabs" id="' . esc_attr($uid) . '">';

            echo '<ul class="dd-range-nav">';
            $first = true;
            foreach ($groups as $group_name => $boards) {
                $display = $group_name !== '' ? $group_name : __('Range', 'silvertell-wc-customisation');
                echo '<li class="dd-range-nav-item' . ($first ? ' active' : '') . '" data-target="' . esc_attr(sanitize_title($group_name)) . '">' . esc_html($display) . '</li>';
                $first = false;
            }
            echo '</ul>';

            $first = true;
            foreach ($groups as $group_name => $boards) {
                echo '<div class="dd-range-panel' . ($first ? ' active' : '') . '" data-panel="' . esc_attr(sanitize_title($group_name)) . '">';
                $this->render_eb_board_table($boards);
                echo '</div>';
                $first = false;
            }

            echo '</div>';
            return;
        }

        // Single group (or all uncategorised): render heading + table inline.
        foreach ($groups as $group_name => $boards) {
            if ($group_name !== '') {
                echo '<h4 class="dd-eb-group-title">' . esc_html($group_name) . '</h4>';
            }
            $this->render_eb_board_table($boards);
        }
    }

    /** Render one evaluation-board variant table (name + buy buttons). */
    private function render_eb_board_table($boards)
    {
        $buy_samples_label = __('Buy Samples', 'silvertell-wc-customisation');

        echo '<table class="dd-eb-table"><tbody>';
        foreach ($boards as $board) {
            $buttons = $this->render_provider_buttons($board->ID);
            echo '<tr>';
            echo '<td class="dd-eb-name">' . esc_html($board->post_title) . '</td>';
            echo '<td class="dd-eb-buy" data-label="' . esc_attr($buy_samples_label) . '">' . ($buttons ?: '&mdash;') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    public function render_product_range_tab()
    {
        global $product;
        if (! $product instanceof WC_Product) return;

        $range = $this->build_product_range($product->get_id());
        if (empty($range)) return;

        echo '<div class="dd-tab-content dd-product-range-tab">';

        if (! empty($range['groups'])) {
            // Only show the sub-tab switcher when there's genuinely more than one group;
            // a lone "Extended Range" tab looks broken, so render its sections directly.
            if (count($range['groups']) > 1) {
                $this->render_range_subtabs($range['groups']);
            } else {
                foreach (reset($range['groups']) as $section) {
                    $this->render_range_section($section);
                }
            }
        }

        if (! empty($range['flat'])) {
            $this->render_range_table($range['flat']);
        }

        echo '</div>';
    }

    /**
     * Build the Product Range structure from the post_parent hierarchy.
     *
     * The `Parent` CSV column (a SKU) is imported as the product's post_parent, giving up
     * to three levels:
     *   L1  the viewed product (e.g. Ag9900)
     *   L2  sub-range "group" products (e.g. Ag9900-MTB) — a heading + description, tagged
     *       with a "Product Range > X" category that becomes the sub-tab (New Preferred /
     *       Extended Range)
     *   L3  orderable variants (e.g. Ag9924-MTB) — the table rows, with visible attributes
     *       and distributor buy links
     *
     * Returns ['groups' => [subtab_label => [section, ...]], 'flat' => [variant posts]] or
     * null when there is nothing to show. A "section" is
     * ['title', 'description', 'note', 'variants']. Products whose children are variants
     * directly (2-level, e.g. Ag9700) populate 'flat'; a childless product that has its own
     * attributes/buy links renders itself as a single flat row.
     */
    private function build_product_range($product_id)
    {
        if (isset($this->range_cache[$product_id])) {
            return $this->range_cache[$product_id];
        }

        $children = $this->get_child_products($product_id);

        $groups = [];
        $flat   = [];

        if (! empty($children)) {
            foreach ($children as $child) {
                $grandchildren = $this->get_child_products($child->ID);

                if (! empty($grandchildren)) {
                    $label = $this->get_product_range_subcategory($child->ID);
                    $groups[$label][] = [
                        'title'       => $child->post_title,
                        'description' => $child->post_content,
                        'note'        => $child->post_excerpt,
                        'variants'    => $grandchildren,
                    ];
                } else {
                    $flat[] = $child;
                }
            }
        } else {
            // Standalone product (no children): show itself if it carries spec/buy data.
            $self = wc_get_product($product_id);
            if ($self && ($this->product_has_visible_attributes($self) || $this->render_provider_buttons($product_id) !== '')) {
                $flat[] = get_post($product_id);
            }
        }

        $range = (empty($groups) && empty($flat)) ? null : ['groups' => $groups, 'flat' => $flat];
        $this->range_cache[$product_id] = $range;
        return $range;
    }

    /**
     * Published child products of a post, ordered by menu order then import order (ID) so
     * the CSV row order (e.g. 24V, 12V, 5V, 3V) is preserved.
     */
    private function get_child_products($parent_id)
    {
        return get_posts([
            'post_type'   => 'product',
            'post_status' => 'publish',
            'post_parent' => $parent_id,
            'numberposts' => -1,
            'orderby'     => ['menu_order' => 'ASC', 'ID' => 'ASC'],
        ]);
    }

    /**
     * The sub-range product's child category under "Product Range" (e.g. "New Preferred
     * Range" / "Extended Range"), used as the Product Range sub-tab label. Empty when none.
     */
    private static function get_product_range_subcategory($product_id)
    {
        $terms = get_the_terms($product_id, 'product_cat');
        if (empty($terms) || is_wp_error($terms)) return '';

        $parent_term = get_term_by('name', 'Product Range', 'product_cat');
        if ($parent_term) {
            foreach ($terms as $term) {
                if ((int) $term->parent === (int) $parent_term->term_id) {
                    return $term->name;
                }
            }
        }
        return '';
    }

    /**
     * A product's own ID plus every descendant product ID (children, grandchildren, …),
     * built on get_child_products(). Used to pull attribute terms from a whole "range"
     * branch even though the terms live only on hidden child/variant products.
     */
    private function collect_descendant_product_ids($product_id)
    {
        $ids = [(int) $product_id];
        foreach ($this->get_child_products($product_id) as $child) {
            $ids = array_merge($ids, $this->collect_descendant_product_ids($child->ID));
        }
        return $ids;
    }

    /**
     * Walk post_parent up to the top-level ancestor. Child/variant products are hidden
     * from every frontend listing, so any attribute match on a descendant must resolve
     * back up to the product that actually appears in the grid.
     */
    private function get_root_product_id($product_id)
    {
        $id = (int) $product_id;
        for ($i = 0; $i < 20; $i++) {
            $parent = (int) wp_get_post_parent_id($id);
            if (! $parent) break;
            $id = $parent;
        }
        return $id;
    }

    /**
     * [ product_cat term_id => [ 'pa_x' => [ 'label' => 'X', 'terms' => [ slug => ['name'=>,'count'=>] ] ] ] ]
     * for every global attribute with at least one value inside that category (including
     * values that live only on hidden child/variant products of a top-level product).
     * Categories aggregate the attributes of every descendant category too. Built once
     * per 12h in a transient — this walks the whole catalogue and must not run per request.
     */
    private function get_category_attribute_map()
    {
        $cached = get_transient('silvertell_cat_attr_map');
        if (is_array($cached)) return $cached;

        $attr_choices = $this->get_attribute_taxonomy_choices();
        $map = [];
        if (empty($attr_choices)) {
            set_transient('silvertell_cat_attr_map', $map, 12 * HOUR_IN_SECONDS);
            return $map;
        }

        $top_level_ids = get_posts([
            'post_type'   => 'product',
            'post_status' => 'publish',
            'post_parent' => 0,
            'numberposts' => -1,
            'fields'      => 'ids',
        ]);

        foreach ($top_level_ids as $product_id) {
            $cat_ids = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);
            if (empty($cat_ids) || is_wp_error($cat_ids)) continue;

            $expanded_cat_ids = [];
            foreach ($cat_ids as $cat_id) {
                $expanded_cat_ids[$cat_id] = true;
                foreach (get_ancestors($cat_id, 'product_cat', 'taxonomy') as $ancestor_id) {
                    $expanded_cat_ids[$ancestor_id] = true;
                }
            }

            $branch_ids = $this->collect_descendant_product_ids($product_id);

            foreach ($attr_choices as $tax => $label) {
                $terms = wp_get_object_terms($branch_ids, $tax, ['fields' => 'all']);
                if (empty($terms) || is_wp_error($terms)) continue;

                $seen_slugs = [];
                foreach ($terms as $term) {
                    $seen_slugs[$term->slug] = $term->name;
                }

                foreach (array_keys($expanded_cat_ids) as $cat_id) {
                    if (! isset($map[$cat_id][$tax])) {
                        $map[$cat_id][$tax] = ['label' => $label, 'terms' => []];
                    }
                    foreach ($seen_slugs as $slug => $name) {
                        if (! isset($map[$cat_id][$tax]['terms'][$slug])) {
                            $map[$cat_id][$tax]['terms'][$slug] = ['name' => $name, 'count' => 0];
                        }
                        $map[$cat_id][$tax]['terms'][$slug]['count']++;
                    }
                }
            }
        }

        foreach ($map as $cat_id => $taxes) {
            foreach ($taxes as $tax => $data) {
                uasort($map[$cat_id][$tax]['terms'], function ($a, $b) {
                    return strnatcasecmp($a['name'], $b['name']);
                });
            }
        }

        set_transient('silvertell_cat_attr_map', $map, 12 * HOUR_IN_SECONDS);
        return $map;
    }

    /**
     * Drop the cached category→attribute map so the next sidebar render rebuilds it.
     * Hooked to product saves/deletes and product_cat term edits.
     */
    public function flush_category_attribute_map()
    {
        delete_transient('silvertell_cat_attr_map');
    }

    private function product_has_visible_attributes($product)
    {
        foreach ($product->get_attributes() as $attr) {
            if (! is_object($attr) || ! method_exists($attr, 'get_visible') || $attr->get_visible()) {
                return true;
            }
        }
        return false;
    }

    private function render_range_subtabs($groups)
    {
        $uid = 'dd-range-' . uniqid();
        echo '<div class="dd-range-tabs" id="' . esc_attr($uid) . '">';

        echo '<ul class="dd-range-nav">';
        $first = true;
        foreach ($groups as $label => $sections) {
            $display = $label !== '' ? $label : __('Range', 'silvertell-wc-customisation');
            echo '<li class="dd-range-nav-item' . ($first ? ' active' : '') . '" data-target="' . esc_attr(sanitize_title($label)) . '">' . esc_html($display) . '</li>';
            $first = false;
        }
        echo '</ul>';

        $first = true;
        foreach ($groups as $label => $sections) {
            echo '<div class="dd-range-panel' . ($first ? ' active' : '') . '" data-panel="' . esc_attr(sanitize_title($label)) . '">';
            foreach ($sections as $section) {
                $this->render_range_section($section);
            }
            echo '</div>';
            $first = false;
        }

        echo '</div>';
    }

    private function render_range_section($section)
    {
        echo '<div class="dd-range-section">';
        echo '<h3 class="dd-range-title">' . esc_html($section['title']) . '</h3>';
        if (trim($section['description']) !== '') {
            echo '<div class="dd-range-desc">' . wp_kses_post(wpautop($section['description'])) . '</div>';
        }
        $this->render_range_table($section['variants']);
        if (trim($section['note']) !== '') {
            echo '<p class="dd-range-note">' . esc_html($section['note']) . '</p>';
        }
        echo '</div>';
    }

    /**
     * Render a variant table. Accepts an array of WP_Post objects or product IDs. Columns
     * are the union of the variants' visible attributes, plus a Buy Samples column.
     */
    private function render_range_table($variants)
    {
        $products = [];
        foreach ($variants as $v) {
            $pid = is_object($v) ? $v->ID : (int) $v;
            $p   = wc_get_product($pid);
            if ($p) $products[] = $p;
        }
        if (empty($products)) return;

        // Union of visible attribute labels, preserving first-seen order, for the columns.
        $attr_labels = [];
        foreach ($products as $p) {
            foreach ($p->get_attributes() as $attr_key => $attr) {
                if (is_object($attr) && method_exists($attr, 'get_visible') && ! $attr->get_visible()) continue;
                $name = (is_object($attr) && method_exists($attr, 'get_name')) ? $attr->get_name() : $attr_key;
                $attr_labels[$attr_key] = wc_attribute_label($name, $p);
            }
        }

        $part_number_label = __('Part Number', 'silvertell-wc-customisation');
        $buy_samples_label = __('Buy Samples', 'silvertell-wc-customisation');

        echo '<table class="dd-range-table"><thead><tr>';
        echo '<th>' . esc_html($part_number_label) . '</th>';
        foreach ($attr_labels as $label) {
            echo '<th>' . esc_html($label) . '</th>';
        }
        echo '<th>' . esc_html($buy_samples_label) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($products as $p) {
            $pid = $p->get_id();
            echo '<tr>';
            echo '<td class="dd-range-name">' . esc_html($p->get_name()) . '</td>';
            foreach ($attr_labels as $attr_key => $label) {
                $val = $p->get_attribute($attr_key);
                echo '<td data-label="' . esc_attr($label) . '">' . ($val !== '' ? esc_html($val) : '&mdash;') . '</td>';
            }
            $buttons = $this->render_provider_buttons($pid);
            echo '<td class="dd-range-buy" data-label="' . esc_attr($buy_samples_label) . '">' . ($buttons ?: '&mdash;') . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Normalise a post's stored "buy samples" value for one provider into a list of
     * ['label' => , 'url' => ] rows. Supports both the new repeater array format and the
     * legacy single-URL string still written by the eval-board meta box and child modal.
     */
    private static function get_provider_links($post_id, $provider)
    {
        $raw   = get_post_meta($post_id, $provider['meta_key'], true);
        $links = [];

        if (is_array($raw)) {
            foreach ($raw as $row) {
                if (! is_array($row)) continue;
                $url = isset($row['url']) ? trim($row['url']) : '';
                if ($url === '') continue;
                $links[] = ['label' => isset($row['label']) ? $row['label'] : '', 'url' => $url];
            }
        } elseif (is_string($raw) && trim($raw) !== '') {
            $links[] = ['label' => '', 'url' => trim($raw)];
        }

        return $links;
    }

    /**
     * Output the distributor "buy" links for a single post (product or eval board), driven
     * by the dynamic sample-provider config. A provider with a single link renders a direct
     * anchor; a provider with multiple links renders a button that opens a popup list
     * (see the modal markup/JS in inject_frontend_assets). Returns '' when none are set.
     *
     * Static so the "Buy Samples" Elementor widget can call it without an instance.
     */
    public static function render_provider_buttons($post_id)
    {
        $entries = [];
        foreach (self::get_sample_providers() as $provider) {
            $links = self::get_provider_links($post_id, $provider);
            if (! empty($links)) {
                $entries[] = ['provider' => $provider, 'links' => $links];
            }
        }
        return self::render_provider_link_buttons($entries);
    }

    /**
     * Like render_provider_buttons(), but for a whole product "range": it aggregates the
     * links from the product itself and every descendant variant, grouped per provider.
     * Each link keeps its range context (sub-tab label + sub-range group title) so the
     * popup can show headings (New Preferred Range > Ag9900-MTB > …) instead of a flat
     * list. Each link is labelled by its own label if set, otherwise by the source
     * product's title (e.g. the variant part number). Duplicate URLs are collapsed.
     */
    public static function render_aggregated_provider_buttons($product_id)
    {
        $sources = self::collect_range_link_sources($product_id);

        $entries = [];
        foreach (self::get_sample_providers() as $provider) {
            $links = [];
            $seen  = [];
            foreach ($sources as $src) {
                foreach (self::get_provider_links($src['id'], $provider) as $link) {
                    if (isset($seen[$link['url']])) continue;
                    $seen[$link['url']] = true;
                    $label   = $link['label'] !== '' ? $link['label'] : get_the_title($src['id']);
                    $links[] = [
                        'label'   => $label,
                        'url'     => $link['url'],
                        'tab'     => $src['tab'],
                        'section' => $src['section'],
                    ];
                }
            }
            if (! empty($links)) {
                $entries[] = ['provider' => $provider, 'links' => $links];
            }
        }
        return self::render_provider_link_buttons($entries);
    }

    /**
     * Ordered list of the link sources that make up a range, each carrying its display
     * context: ['id', 'tab' (sub-tab / "Product Range" subcategory), 'section' (sub-range
     * group title)]. Mirrors build_product_range()'s structure (L1 product, L2 sub-range
     * groups, L3 variants) and groups by sub-tab so same-tab entries stay contiguous,
     * which lets the popup print each heading once.
     */
    private static function collect_range_link_sources($product_id)
    {
        // The product's own links first, ungrouped (covers simple, childless products).
        $ordered = [['id' => (int) $product_id, 'tab' => '', 'section' => '']];

        $l2 = self::get_child_product_ids($product_id);
        if (empty($l2)) return $ordered;

        // Bucket L2 groups by their sub-tab label, preserving first-seen order.
        $by_tab    = [];
        $tab_order = [];
        foreach ($l2 as $gid) {
            $tab = self::get_product_range_subcategory($gid);
            $l3  = self::get_child_product_ids($gid);
            if (! in_array($tab, $tab_order, true)) $tab_order[] = $tab;
            if (! empty($l3)) {
                // A sub-range group (e.g. Ag9900-MTB): its variants become the rows.
                $by_tab[$tab][] = ['title' => get_the_title($gid), 'ids' => $l3];
            } else {
                // A flat variant directly under the product: no section heading.
                $by_tab[$tab][] = ['title' => '', 'ids' => [(int) $gid]];
            }
        }

        foreach ($tab_order as $tab) {
            foreach ($by_tab[$tab] as $section) {
                foreach ($section['ids'] as $vid) {
                    $ordered[] = ['id' => (int) $vid, 'tab' => $tab, 'section' => $section['title']];
                }
            }
        }
        return $ordered;
    }

    /** Published immediate child product IDs, in menu/import order. */
    private static function get_child_product_ids($parent_id)
    {
        return get_posts([
            'post_type'   => 'product',
            'post_status' => 'publish',
            'post_parent' => $parent_id,
            'numberposts' => -1,
            'fields'      => 'ids',
            'orderby'     => ['menu_order' => 'ASC', 'ID' => 'ASC'],
        ]);
    }

    /**
     * Render the distributor pills from a list of ['provider' => , 'links' => [...]] entries.
     * Shared by render_provider_buttons() (single post) and render_aggregated_provider_buttons()
     * (a whole range). Single-link providers are a direct anchor; multi-link providers are a
     * button that opens the popup list. Returns '' when there are no entries.
     */
    private static function render_provider_link_buttons($entries)
    {
        if (empty($entries)) return '';

        $cart_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-cart3" viewBox="0 0 16 16"> <path d="M0 1.5A.5.5 0 0 1 .5 1H2a.5.5 0 0 1 .485.379L2.89 3H14.5a.5.5 0 0 1 .49.598l-1 5a.5.5 0 0 1-.465.401l-9.397.472L4.415 11H13a.5.5 0 0 1 0 1H4a.5.5 0 0 1-.491-.408L2.01 3.607 1.61 2H.5a.5.5 0 0 1-.5-.5M3.102 4l.84 4.479 9.144-.459L13.89 4zM5 12a2 2 0 1 0 0 4 2 2 0 0 0 0-4m7 0a2 2 0 1 0 0 4 2 2 0 0 0 0-4m-7 1a1 1 0 1 1 0 2 1 1 0 0 1 0-2m7 0a1 1 0 1 1 0 2 1 1 0 0 1 0-2"/> </svg>';

        $out = '';
        foreach ($entries as $entry) {
            $provider = $entry['provider'];
            $links    = $entry['links'];
            $name     = $provider['name'];
            $logo     = isset($provider['logo']) ? $provider['logo'] : '';
            $logo_url = is_numeric($logo) ? wp_get_attachment_url($logo) : $logo;

            if ($logo_url) {
                // Logo present: show the logo only, inside a bordered pill.
                $inner = '<img src="' . esc_url($logo_url) . '" alt="' . esc_attr($name) . '" />';
                $cls   = 'dd-buy-btn dd-buy-btn--logo';
            } else {
                // No logo: fall back to the provider name with a cart icon.
                $inner = $cart_svg . esc_html($name);
                $cls   = 'dd-buy-btn';
            }

            if (count($links) === 1) {
                // Single link: open it directly.
                $out .= '<a class="' . $cls . '" href="' . esc_url($links[0]['url']) . '" target="_blank" rel="noopener nofollow" title="' . esc_attr($name) . '">' . $inner . '</a>';
            } else {
                // Multiple links: a button that opens a popup. The popup content lives in an
                // adjacent hidden span the modal JS copies from.
                $content = self::build_buy_popup_content($links);
                $out .= '<button type="button" class="' . $cls . ' dd-buy-btn--multi" title="' . esc_attr($name) . '" aria-haspopup="dialog">' . $inner . '<span class="dd-buy-count">' . count($links) . '</span></button>';
                $out .= '<span class="dd-buy-links-data" hidden data-provider="' . esc_attr($name) . '">' . $content . '</span>';
            }
        }
        return $out;
    }

    /**
     * Build the popup body for a multi-link provider. Links carry range context
     * ('section' = sub-range group title, 'tab' = sub-tab label). They are grouped by the
     * deepest available label; with two or more named groups the body is an accordion (one
     * collapsible item per sub-range group, e.g. Ag9900M / Ag9900MT / Ag9900LP), preceded
     * by the sub-tab label (Extended Range / New Preferred Range) as a heading whenever it
     * changes. With fewer named groups it falls back to a plain list.
     */
    private static function build_buy_popup_content($links)
    {
        // Bucket links by group, preserving first-seen order, remembering each group's tab.
        $groups = [];
        $order  = [];
        foreach ($links as $link) {
            $section = isset($link['section']) ? $link['section'] : '';
            $tab     = isset($link['tab']) ? $link['tab'] : '';
            $label   = $section !== '' ? $section : $tab; // '' when neither
            $key     = $tab . '||' . $label;             // same section under different tabs stays distinct
            if (! isset($groups[$key])) {
                $groups[$key] = ['label' => $label, 'tab' => $tab, 'links' => []];
                $order[]      = $key;
            }
            $groups[$key]['links'][] = $link;
        }

        $named = array_filter($order, function ($k) use ($groups) {
            return $groups[$k]['label'] !== '';
        });

        // Fewer than two named groups → a plain list is clearer than an accordion.
        if (count($named) < 2) {
            return self::build_buy_links_list($links);
        }

        $html    = '<div class="dd-buy-acc">';
        $cur_tab = null;
        $first   = true;
        foreach ($order as $key) {
            $group = $groups[$key];

            if ($group['tab'] !== $cur_tab) {
                if ($group['tab'] !== '') {
                    $html .= '<div class="dd-buy-acc-tab">' . esc_html($group['tab']) . '</div>';
                }
                $cur_tab = $group['tab'];
            }

            $label      = $group['label'] !== '' ? $group['label'] : __('Other', 'silvertell-wc-customisation');
            $open       = $first ? ' is-open' : '';
            $body_style = $first ? ' style="display:block;"' : ''; // first open; lets the slide animate from here
            $html .= '<div class="dd-buy-acc-item' . $open . '">'
                . '<button type="button" class="dd-buy-acc-head" aria-expanded="' . ($first ? 'true' : 'false') . '">'
                . '<span class="dd-buy-acc-label">' . esc_html($label) . '</span>'
                . '<span class="dd-buy-acc-icon" aria-hidden="true"></span>'
                . '</button>'
                . '<div class="dd-buy-acc-body"' . $body_style . '>' . self::build_buy_links_list($group['links']) . '</div>'
                . '</div>';
            $first = false;
        }
        $html .= '</div>';
        return $html;
    }

    /** A <ul> of buy links (label → URL). */
    private static function build_buy_links_list($links)
    {
        $items = '';
        foreach ($links as $link) {
            $label  = $link['label'] !== '' ? $link['label'] : $link['url'];
            $items .= '<li><a href="' . esc_url($link['url']) . '" target="_blank" rel="noopener nofollow">' . esc_html($label) . '</a></li>';
        }
        return '<ul>' . $items . '</ul>';
    }

    /**
     * Re-add a "View Product" button to each card in the shop/archive grid.
     *
     * The theme removes WooCommerce's native loop button, so this hooks the same
     * `woocommerce_after_shop_loop_item` slot and outputs a permalink button. It
     * carries its own `dd-view-product` class (styled below) as well as the core
     * `button` class, and the inline CSS is printed once on the first render so it
     * works regardless of which template/Elementor widget builds the grid.
     */
    public function add_shop_loop_view_button()
    {
        global $product;
        if (! $product) return;

        $features = get_post_meta($product->get_id(), '_features', true);
        if (! empty($features) && is_array($features)) {
            $items = '';
            $shown = 0;
            foreach ($features as $feature) {
                if (trim($feature) === '') continue;
                $items .= '<li>' . esc_html($feature) . '</li>';
                if (++$shown >= 5) break;
            }
            if ($items !== '') {
                echo '<ul class="dd-loop-features">' . $items . '</ul>';
            }
        }

        printf(
            '<div class="dd-view-product-holder"><a href="%s" class="button dd-view-product">%s</a></div>',
            esc_url(get_permalink($product->get_id())),
            esc_html__('View Product', 'silvertell-wc-customisation')
        );
    }

    /**
     * Rework the grid `.product-brand` list for a product card. The theme builds it
     * via wc_get_product_category_list → get_the_term_list, whose link array runs
     * through this `term_links-product_cat` filter, so this is the single choke point
     * for every product grid (native shop/archive loops *and* Elementor Products
     * widgets, which run their own query — hence we can't rely on `in_the_loop()`).
     *
     *   - On a product-category archive: show the category currently being viewed.
     *   - On any other product grid (main shop page, custom/Elementor pages): show
     *     the top-level parent category instead of the deepest subcategory.
     *
     * Scoped by the card's post type (`product`) so category term links rendered
     * elsewhere (breadcrumbs, subcategory tiles, non-product content) are untouched.
     * The single product page is handled by render_single_product_parent_brand(),
     * which prints its markup directly and never reaches this filter — we also bail
     * on is_product() as a guard.
     *
     * @param string[] $links Array of category anchor HTML for the current product.
     * @return string[]
     */
    public function filter_grid_brand_to_current_category($links)
    {
        if (is_admin()) return $links;
        if (function_exists('is_product') && is_product()) return $links;

        // Only touch category lists belonging to a product card.
        $post_id = get_the_ID();
        if (! $post_id || get_post_type($post_id) !== 'product') return $links;

        // Category archive: collapse to the single category being viewed.
        if (function_exists('is_product_category') && is_product_category()) {
            $term = get_queried_object();
            if (! ($term instanceof WP_Term)) return $links;

            $url = get_term_link($term);
            if (is_wp_error($url)) return $links;

            return ['<a href="' . esc_url($url) . '" rel="tag">' . esc_html($term->name) . '</a>'];
        }

        // Everywhere else: show the top-level parent category, not the subcategory.
        return $this->top_level_category_links($post_id, $links);
    }

    /**
     * Build category anchors from each of a product's assigned categories reduced to
     * its top-level ancestor (walking `parent` up to the root), de-duplicated so a
     * product filed under several subcategories of one parent shows that parent once.
     * Returns $fallback unchanged if nothing resolves.
     *
     * @param int      $product_id
     * @param string[] $fallback Links to return if no top-level category resolves.
     * @return string[]
     */
    private function top_level_category_links($product_id, $fallback = [])
    {
        $terms = get_the_terms($product_id, 'product_cat');
        if (empty($terms) || is_wp_error($terms)) return $fallback;

        $tops = [];
        foreach ($terms as $term) {
            $top = $term;
            while ($top->parent) {
                $parent = get_term($top->parent, 'product_cat');
                if (! $parent || is_wp_error($parent)) break;
                $top = $parent;
            }
            $tops[$top->term_id] = $top;
        }

        $links = [];
        foreach ($tops as $top) {
            $url = get_term_link($top);
            if (is_wp_error($url)) continue;
            $links[] = '<a href="' . esc_url($url) . '" rel="tag">' . esc_html($top->name) . '</a>';
        }

        return empty($links) ? $fallback : $links;
    }

    /**
     * On the single product page, replace the theme's `.product-brand` output with
     * the product's top-level parent category instead of the deepest subcategory.
     *
     * The theme prints the brand via the plain function `fynode_single_product_brand`
     * on `woocommerce_single_product_summary` (priority 4). We swap it for our own
     * renderer at the same priority — but only if the theme's action is actually
     * registered, so this stays a no-op should the theme change.
     */
    public function maybe_override_single_product_brand()
    {
        if (! function_exists('is_product') || ! is_product()) return;

        if (remove_action('woocommerce_single_product_summary', 'fynode_single_product_brand', 4)) {
            add_action('woocommerce_single_product_summary', [$this, 'render_single_product_parent_brand'], 4);
        }
    }

    /**
     * Render the `.product-brand` block for the single product page using each
     * assigned category's top-level ancestor (walking `parent` up to the root),
     * de-duplicated so a product filed under several subcategories of the same
     * parent shows that parent once.
     */
    public function render_single_product_parent_brand()
    {
        global $product;
        if (! $product) return;

        $links = $this->top_level_category_links($product->get_id());
        if (empty($links)) return;

        echo '<div class="product-brand">' . implode(', ', $links) . '</div>';
    }

    /**
     * Restrict WooCommerce's related-products category matching to the product's deepest
     * (leaf) subcategories — the same "subcategory" notion used for the `.product-brand`
     * block. WooCommerce would otherwise relate by *every* assigned category, including the
     * broad top-level one, pulling in loosely-related products; matching only the leaf
     * subcategory keeps related products specific to it.
     *
     * A term is a leaf when no other assigned term is its descendant (WooCommerce/importers
     * often assign the whole ancestry, so both "Isolators" and "Isolators > Digital
     * Isolators" may be present — we keep only the latter). If a product is filed solely
     * under top-level categories, those remain (the deepest it has) so related products
     * still work rather than always hiding.
     *
     * @param int[] $term_ids   product_cat term IDs WooCommerce will match on.
     * @param int   $product_id The product being viewed.
     * @return int[]
     */
    public function related_products_subcategory_terms($term_ids, $product_id)
    {
        if (empty($term_ids) || ! is_array($term_ids)) return $term_ids;

        $term_ids = array_values(array_unique(array_map('intval', $term_ids)));

        $leaves = [];
        foreach ($term_ids as $tid) {
            $is_ancestor = false;
            foreach ($term_ids as $other) {
                if ($other === $tid) continue;
                if (in_array($tid, get_ancestors($other, 'product_cat'), true)) {
                    $is_ancestor = true;
                    break;
                }
            }
            if (! $is_ancestor) $leaves[] = $tid;
        }

        return ! empty($leaves) ? $leaves : $term_ids;
    }

    /**
     * Remove child products (sub-range groups / variants — any product with a post_parent)
     * from the related-products results. They surface only inside their parent's Product
     * Range tab and 301-redirect on the frontend, so they must not appear as related cards.
     *
     * @param int[] $related_posts Related product IDs.
     * @param int   $product_id    The product being viewed.
     * @param array $args          WooCommerce related-products args.
     * @return int[]
     */
    public function exclude_child_products_from_related($related_posts, $product_id, $args)
    {
        if (empty($related_posts) || ! is_array($related_posts)) return $related_posts;

        return array_values(array_filter($related_posts, function ($id) {
            return ! wp_get_post_parent_id((int) $id);
        }));
    }

    public function inject_frontend_assets()
    {
        if (! function_exists('is_woocommerce') || ! is_woocommerce()) return;
    ?>
        <style>
            /* ---- Single-product tab navigation (horizontal, underlined active) ---- */
            .woocommerce-tabs ul.tabs.wc-tabs {
                display: flex;
                flex-wrap: wrap;
                gap: 28px;
                list-style: none;
                margin: 0 0 28px;
                padding: 0;
                border-bottom: 1px solid #e0e0e0;
            }

            .woocommerce-tabs ul.tabs.wc-tabs:before,
            .woocommerce-tabs ul.tabs.wc-tabs:after {
                content: none;
                display: none;
            }

            .woocommerce-tabs ul.tabs.wc-tabs li {
                list-style: none;
                margin: 0;
                padding: 0;
                background: none;
                border: 0;
                border-radius: 0;
            }

            .woocommerce-tabs ul.tabs.wc-tabs li:before,
            .woocommerce-tabs ul.tabs.wc-tabs li:after {
                content: none;
                display: none;
            }

            .woocommerce-tabs ul.tabs.wc-tabs li a {
                display: block;
                padding: 0 0 14px;
                margin-bottom: -1px;
                border-bottom: 2px solid transparent;
                color: #777;
                font-weight: 500;
                font-size: 15px;
                line-height: 1.4;
                text-decoration: none;
                box-shadow: none;
                background: none;
            }

            .woocommerce-tabs ul.tabs.wc-tabs li a:hover {
                color: #111;
            }

            .woocommerce-tabs ul.tabs.wc-tabs li.active a,
            .woocommerce-tabs ul.tabs.wc-tabs li[aria-selected="true"] a {
                color: #111;
                border-bottom-color: #111;
            }

            .woocommerce-tabs .woocommerce-Tabs-panel {
                padding-top: 5px;
            }

            .shop-products-wrapper .product-cart-button {
                display: none !important;
            }

            .shop-products-wrapper .product-buttons {
                display: none !important;

            }

            .products .product {
                background-color: #F4F4F4;
                border-radius: 15px;
                padding: 2.375rem;
                display: flex;
                flex-direction: column;
                border: 1px solid #0089FF;
            }

            .product .product-wrapper.product-background .product-content-wrapper {
                padding: 0;
            }

            .products .product-title {
                min-height: unset;
            }

            .products .product-content-header {
                display: none;
            }

            .products .product-content-footer {
                display: none;
            }

            .products .product-thumbnail {
                aspect-ratio: 1 / 1;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .dd-view-product-holder {
                text-align: center;
                margin-top: auto;
            }

            .dd-view-product-holder .dd-view-product,
            .products.product-list .product-inner .product-content-wrapper .button {
                display: inline-block !important;
                line-height: 1.2;
                text-decoration: none;
                text-align: center;
                cursor: pointer;
                background-color: #0089FF;
                color: #fff;
                margin: 0;
                margin-top: 0px;
                margin-right: 0px;
                margin-bottom: 0px;
                margin-left: 0px;
                padding: 12px;
                width: 100%;
                font-size: 16px;
                font-weight: 500;
                height: auto !important;
                border-radius: 50px !important;
                transition: 400ms;
            }

            .dd-view-product-holder .dd-view-product:hover,
            .products.product-list .product-inner .product-content-wrapper .button:hover {
                opacity: 0.8;
            }

            .dd-loop-features {
                font-size: 13px;
                color: #71717A;
                letter-spacing: -0.75px;
                padding: 0;
                list-style: none;
            }

            .dd-loop-features li {
                display: flex;
                align-items: flex-start;
                gap: 8px;
                margin-bottom: 6px;
            }

            .dd-loop-features li::before {
                content: "\2713";
                color: var(--e-global-color-c172edd);
                font-weight: 700;
                flex: 0 0 18px;
                width: 18px;
                height: 18px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 4px;
                font-size: 14px;
            }

            /* ---- Single-product tabs become an accordion below 768px ---- */
            @media (max-width: 768px) {

                .woocommerce-tabs ul.tabs.wc-tabs {
                    display: block;
                    gap: 0;
                    margin: 0 0 10px;
                    border-bottom: 0;
                }

                .woocommerce-tabs ul.tabs.wc-tabs li {
                    border-bottom: 1px solid #e0e0e0;
                }

                .woocommerce-tabs ul.tabs.wc-tabs li a {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    gap: 10px;
                    padding: 14px 0;
                    margin-bottom: 0;
                    border-bottom: 0;
                    font-weight: 600;
                }

                .woocommerce-tabs ul.tabs.wc-tabs li a::after {
                    content: '+';
                    flex: 0 0 auto;
                    font-size: 20px;
                    font-weight: 400;
                    line-height: 1;
                    color: #777;
                }

                .woocommerce-tabs ul.tabs.wc-tabs li.active a,
                .woocommerce-tabs ul.tabs.wc-tabs li[aria-selected="true"] a {
                    border-bottom-color: transparent;
                    color: #111;
                }

                .woocommerce-tabs ul.tabs.wc-tabs li.active a::after,
                .woocommerce-tabs ul.tabs.wc-tabs li[aria-selected="true"] a::after {
                    content: '\2212';
                }

                .woocommerce-tabs .dd-accordion-panel {
                    padding: 4px 0 20px;
                }
            }

            .dd-tab-content {
                margin: 0 0 10px;
            }

            .dd-feature-list {
                margin: 0 0 15px;
                padding: 0;
                list-style: none;
                font-size: 0.875rem;
            }

            .dd-features-widget-title {
                margin: 0 0 12px;
            }

            .dd-feature-list li {
                position: relative;
                margin-bottom: 8px;
                display: flex;
                gap: 10px;
            }

            .dd-feature-list li::before {
                content: "\2713";
                background-color: var(--e-global-color-c172edd);
                font-weight: 700;
                width: 20px;
                height: 20px;
                fleX: 0 0 20px;
                display: flex;
                align-items: center;
                justify-content: center;
                color: #fff;
                border-radius: 5px;
                font-size: 10px;
            }

            @media (min-width: 768px) {
                .dd-feature-list {
                    column-count: 2;
                    column-gap: 40px;
                }

                .dd-feature-list li {
                    break-inside: avoid;
                }
            }

            .dd-range-group-title,
            .dd-eb-group-title {
                margin: 25px 0 12px;
                font-size: 18px;
            }

            .dd-range-group-title:first-child {
                margin-top: 0;
            }

            .dd-doc-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
                gap: 12px;
                margin-bottom: 15px;
            }

            .dd-doc-btn {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 14px 16px;
                background: #f6f7f7;
                border: 1px solid #d8dadc;
                border-radius: 6px;
                color: #1d2327;
                text-decoration: none;
                font-weight: 600;
                font-size: 14px;
                line-height: 1.3;
                transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
            }

            .dd-doc-btn:hover {
                background: var(--e-global-color-c172edd);
                border-color: var(--e-global-color-c172edd);
                color: #fff;
            }

            .dd-doc-icon {
                flex: 0 0 auto;
                color: var(--e-global-color-c172edd);
                transition: color 0.15s ease;
            }

            .dd-doc-btn:hover .dd-doc-icon {
                color: #fff;
            }

            .dd-doc-btn-label {
                flex: 1;
            }

            .dd-range-table,
            .dd-eb-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 25px;
            }

            .dd-range-table th,
            .dd-range-table td,
            .dd-eb-table td {
                border: 1px solid #e0e0e0;
                padding: 10px 12px;
                text-align: left;
                vertical-align: middle;
                font-size: 14px;
            }

            .dd-range-table thead th {
                background: #f6f7f7;
                font-weight: 600;
            }

            /* ---- Responsive tables: stack into cards below 768px ---- */
            @media (max-width: 768px) {

                .dd-range-table,
                .dd-eb-table {
                    border: 0;
                }

                .dd-range-table thead {
                    display: none;
                }

                .dd-range-table tr,
                .dd-eb-table tr {
                    display: block;
                    margin-bottom: 12px;
                    border: 1px solid #e0e0e0;
                    border-radius: 6px;
                    overflow: hidden;
                }

                .dd-range-table td,
                .dd-eb-table td {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    gap: 12px;
                    padding: 10px 12px;
                    border: 0;
                    border-bottom: 1px solid #f0f0f0;
                }

                .dd-range-table tr>td:last-child,
                .dd-eb-table tr>td:last-child {
                    border-bottom: 0;
                }

                .dd-range-table td[data-label]::before,
                .dd-eb-table td[data-label]::before {
                    content: attr(data-label);
                    font-weight: 600;
                    color: #50575e;
                    flex: 0 0 auto;
                }

                .dd-range-table td.dd-range-name,
                .dd-eb-table td.dd-eb-name {
                    background: #f6f7f7;
                    font-weight: 600;
                    font-size: 15px;
                }

                .dd-range-table td.dd-range-buy,
                .dd-eb-table td.dd-eb-buy {
                    display: block;
                }

                .dd-range-table td.dd-range-buy::before,
                .dd-eb-table td.dd-eb-buy::before {
                    display: block;
                    margin-bottom: 8px;
                }
            }

            .dd-buy-btn {
                display: inline-flex;
                align-items: center;
                gap: 5px;
                margin: 2px 6px 2px 0;
                padding: 10px 25px;
                background: var(--e-global-color-c172edd);
                color: #fff;
                border-radius: 50px;
                font-size: 13px;
                line-height: 1.4;
            }

            .dd-buy-btn:hover {
                background: #135e96;
                color: #fff;
            }

            /* Logo variant: white bordered pill containing the distributor logo only. */
            .dd-buy-btn--logo {
                background: #fff;
                border: 1px solid #d5d9dd;
                padding: 10px 18px;
                box-shadow: 0 1px 2px rgba(0, 0, 0, 0.06);
                height: auto;
            }

            .dd-buy-btn--logo:hover {
                background: #fff;
                border-color: var(--e-global-color-c172edd);
                box-shadow: 0 1px 4px rgba(0, 0, 0, 0.12);
            }

            .dd-buy-btn img {
                max-height: 22px;
                width: auto;
                display: block;
                height: 22px;
            }

            /* Multi-link distributor pill: a <button> styled like the anchor pills. */
            button.dd-buy-btn {
                font: inherit;
                cursor: pointer;
                border: 0;
            }

            button.dd-buy-btn--logo {
                border: 1px solid #d5d9dd;
                padding: 10px 18px !important;
                border-radius: 50px !important;
                height: auto !important;
            }

            .dd-buy-btn--multi {
                position: relative;
            }

            .dd-buy-count {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-width: 18px;
                height: 18px;
                margin-left: 8px;
                padding: 0 5px;
                border-radius: 9px;
                background: var(--e-global-color-c172edd);
                color: #fff;
                font-size: 11px;
                font-weight: 600;
                line-height: 1;
            }

            .dd-buy-links-data {
                display: none !important;
            }

            /* ---- Buy Samples popup ---- */
            .dd-buy-modal {
                position: fixed;
                inset: 0;
                z-index: 99999;
                display: flex;
                align-items: center;
                justify-content: center;
                opacity: 0;
                visibility: hidden;
                pointer-events: none;
                transition: opacity 0.22s ease, visibility 0.22s ease;
            }

            .dd-buy-modal.is-open {
                opacity: 1;
                visibility: visible;
                pointer-events: auto;
            }

            .dd-buy-modal-overlay {
                position: absolute;
                inset: 0;
                background: rgba(0, 0, 0, 0.5);
            }

            .dd-buy-modal-box {
                position: relative;
                background: #fff;
                border-radius: 8px;
                width: calc(100% - 40px);
                max-width: 420px;
                max-height: 80vh;
                overflow: auto;
                padding: 26px 26px 20px;
                box-shadow: 0 12px 40px rgba(0, 0, 0, 0.25);
                transform: translateY(14px) scale(0.97);
                transition: transform 0.28s cubic-bezier(0.2, 0.8, 0.25, 1);
            }

            .dd-buy-modal.is-open .dd-buy-modal-box {
                transform: translateY(0) scale(1);
            }

            .dd-buy-modal-title {
                margin: 0 0 16px;
                font-size: 18px;
                padding-right: 24px;
            }

            .dd-buy-modal-close {
                position: absolute;
                top: 12px;
                right: 14px;
                border: 0;
                background: none;
                font-size: 24px;
                line-height: 1;
                cursor: pointer;
                color: #777;
            }

            .dd-buy-modal-close:hover {
                color: #111;
            }

            .dd-buy-modal-list ul {
                list-style: none;
                margin: 0;
                padding: 0;
            }

            .dd-buy-modal-list li {
                margin: 0 0 8px;
            }

            /* Sub-range group accordion inside the popup. */
            .dd-buy-acc-tab {
                margin: 16px 0 8px;
                font-size: 12px;
                font-weight: 700;
                letter-spacing: 0.04em;
                text-transform: uppercase;
                color: var(--e-global-color-c172edd);
            }

            .dd-buy-acc-tab:first-child {
                margin-top: 0;
            }

            .dd-buy-acc-item {
                margin: 0 0 6px;
            }

            .dd-buy-acc-head.dd-buy-acc-head.dd-buy-acc-head.dd-buy-acc-head {
                width: 100%;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 10px;
                padding: 11px 14px;
                background: #f4f6f8;
                border: 1px solid #e0e0e0;
                border-radius: 6px;
                cursor: pointer;
                font-size: 14px;
                font-weight: bold;
                color: #222;
                text-align: left;
                letter-spacing: 1px;
                text-transform: uppercase;
            }

            .dd-buy-acc-head.dd-buy-acc-head.dd-buy-acc-head.dd-buy-acc-head[aria-expanded="true"] {
                border-radius: 6px 6px 0 0;
                background-color: var(--e-global-color-c172edd);
                color: #fff;
            }

            .dd-buy-acc-head:hover {
                background: #eef2f6;
            }

            .dd-buy-acc-item.is-open .dd-buy-acc-head {
                border-radius: 6px 6px 0 0;
                border-bottom-color: transparent;
            }

            .dd-buy-acc-icon {
                flex: 0 0 auto;
                width: 8px;
                height: 8px;
                margin-top: -3px;
                border-right: 2px solid currentColor;
                border-bottom: 2px solid currentColor;
                transform: rotate(45deg);
                transition: transform 0.2s ease;
            }

            .dd-buy-acc-item.is-open .dd-buy-acc-icon {
                margin-top: 3px;
                transform: rotate(-135deg);
            }

            /* Hidden by default; visibility is driven by jQuery slideUp/slideDown (the open
               item is rendered with an inline display:block so the slide can animate). The
               .is-open class only drives the header/chevron styling, never display, so it
               doesn't make jQuery treat the body as already-shown (which would skip the
               animation). */
            .dd-buy-acc-body {
                display: none;
                border: 1px solid #e0e0e0;
                border-top: 0;
                border-radius: 0 0 6px 6px;
            }

            /* Links inside an accordion body: a clean divided list, not nested pills. */
            .dd-buy-modal-list .dd-buy-acc-body li {
                margin: 0;
            }

            .dd-buy-modal-list .dd-buy-acc-body li:not(:last-child) {
                border-bottom: 1px solid #eee;
            }

            .dd-buy-modal-list .dd-buy-acc-body li a {
                padding: 11px;
                border: 0;
                border-radius: 0;
                box-shadow: none;
                font-size: 14px;
            }

            .dd-buy-modal-list .dd-buy-acc-body li:last-child a {
                border-bottom: 0;
            }

            .dd-buy-modal-list .dd-buy-acc-body li a:hover {
                background: #f5faff;
            }

            .dd-buy-modal-list li a {
                display: block;
                padding: 12px 16px;
                border: 1px solid #e0e0e0;
                border-radius: 6px;
                color: #111;
                text-decoration: none;
                word-break: break-word;
                transition: border-color 0.15s, background 0.15s;
                font-size: 14px;
            }

            .dd-buy-modal-list li a:hover {
                border-color: var(--e-global-color-c172edd);
                background: #f5faff;
            }

            body.dd-buy-modal-open {
                overflow: hidden;
            }


            @media (prefers-reduced-motion: reduce) {

                .dd-buy-modal,
                .dd-buy-modal-box,
                .dd-buy-acc-icon {
                    transition: none !important;
                }

                .dd-buy-modal-box {
                    transform: none !important;
                }
            }

            .dd-eb-card {
                display: flex;
                flex-wrap: wrap;
                gap: 25px;
                margin-bottom: 30px;
                align-items: flex-start;
            }

            .dd-eb-image-wrap {
                flex: 0 0 420px;
                max-width: 420px;
            }

            .dd-eb-image-wrap img {
                width: 100%;
                max-width: 100%;
                height: auto;
                display: block;
                border-radius: 6px;
            }

            @media (max-width: 782px) {
                .dd-eb-image-wrap {
                    flex-basis: 100%;
                    max-width: 100%;
                }
            }

            .dd-eb-body {
                flex: 1;
                min-width: 280px;
            }

            .dd-eb-title {
                margin-top: 0;
            }

            .dd-buy-wrap {
                margin-top: 12px;
            }

            .dd-range-tabs {
                margin-bottom: 10px;
            }

            .dd-range-nav {
                list-style: none;
                margin: 0 0 28px;
                padding: 0;
                display: flex;
                flex-wrap: wrap;
                gap: 4px;
                border-bottom: 1px solid #e0e0e0;
            }

            .dd-range-nav-item.dd-range-nav-item.dd-range-nav-item {
                cursor: pointer;
                padding: 12px 22px;
                font-weight: 600;
                letter-spacing: 0.02em;
                text-transform: uppercase;
                font-size: 14px;
                color: #50575e;
                border: 1px solid transparent;
                border-bottom: 2px solid transparent;
                border-radius: 6px 6px 0 0;
                margin-bottom: -1px;
                transition: color 0.15s ease, background 0.15s ease, border-color 0.15s ease;
            }

            .dd-range-nav-item.dd-range-nav-item.dd-range-nav-item:hover {
                color: var(--e-global-color-c172edd);
                background: #f6f7f7;
            }

            .dd-range-nav-item.dd-range-nav-item.dd-range-nav-item.active {
                color: var(--e-global-color-c172edd);
                background: #fff;
                border-color: #e0e0e0;
                border-bottom-color: var(--e-global-color-c172edd);
            }

            /* ---- Range sub-tab nav: same underlined tabs as desktop, just tighter ---- */
            @media (max-width: 768px) {

                .dd-range-nav {
                    gap: 2px;
                    margin-bottom: 18px;
                }

                .dd-range-nav-item.dd-range-nav-item.dd-range-nav-item {
                    padding: 8px 12px;
                    font-size: 12px;
                    letter-spacing: 0;
                }
            }

            .dd-range-panel {
                display: none;
            }

            .dd-range-panel.active {
                display: block;
                animation: dd-range-fade 0.2s ease;
            }

            @keyframes dd-range-fade {
                from {
                    opacity: 0;
                }

                to {
                    opacity: 1;
                }
            }

            .dd-range-section {
                margin-bottom: 35px;
            }

            .dd-range-title {
                margin: 0 0 5px;
            }

            .dd-range-desc {
                color: #50575e;
                margin-bottom: 15px;
            }

            .dd-range-note {
                color: #787c82;
                font-style: italic;
                font-size: 13px;
                margin-top: 8px;
            }

            .woocommerce-Tabs-panel>h2 {
                display: none;
            }

            .woocommerce div.product.elementor ul.tabs li a {
                padding-left: 0;
                padding-right: 0;
            }

            .woocommerce div.product.elementor .woocommerce-tabs .panel {
                padding-left: 0;
                padding-right: 0;
            }

            /* Nested attribute filters injected under each product category in the
               sidebar "Product Categories" widget. */
            .dd-cat-attrs {
                margin: 4px 0 10px 18px;
                padding-left: 10px;
                border-left: 2px solid #e2e2e2;
            }

            .dd-cat-attr {
                margin-bottom: 6px;
            }

            .dd-cat-attr-toggle {
                background: none;
                border: none;
                padding: 2px 0;
                margin: 0;
                font-size: 12px;
                font-weight: 600;
                color: #50575e;
                cursor: pointer;
                text-align: left;
            }

            .dd-cat-attr-toggle::before {
                content: "+";
                display: inline-block;
                width: 12px;
            }

            .dd-cat-attr.is-open>.dd-cat-attr-toggle::before {
                content: "\2212";
            }

            .dd-cat-attr-values {
                list-style: none;
                margin: 2px 0 0 12px;
                padding: 0;
                display: none;
            }

            .dd-cat-attr.is-open>.dd-cat-attr-values {
                display: block;
            }

            .dd-cat-attr-values li {
                margin: 0;
                padding: 2px 0;
            }

            .dd-attr-opt {
                font-size: 12px;
                color: #50575e;
                text-decoration: none;
            }

            .dd-attr-opt:hover {
                text-decoration: underline;
            }

            .dd-attr-opt.is-active {
                font-weight: 700;
                color: var(--e-global-color-primary, #1d1d1d);
            }

            .dd-attr-opt.is-active::before {
                content: "\2713";
                display: inline-block;
                width: 14px;
                color: var(--e-global-color-primary, #1d1d1d);
            }

            .dd-attr-count {
                color: #8c8f94;
            }

            .dd-cat-attr-clear {
                display: inline-block;
                margin: 4px 0 0;
                font-size: 12px;
                text-decoration: underline;
                color: #50575e;
            }
        </style>
        <script>
            /* Tab switcher: ensures only the active panel is shown on desktop, and
               rearranges into a single-open accordion (panel directly under its
               header) below 768px. Idempotent with WooCommerce's native tabs.js
               when that is present; provides the behaviour when the theme/Elementor
               context does not. */
            (function($) {
                $(function() {
                    var mobileMQ = window.matchMedia('(max-width: 768px)');

                    $('.woocommerce-tabs').each(function() {
                        var $wrap = $(this);
                        var $tabsList = $wrap.find('ul.tabs.wc-tabs').first();
                        var $tabs = $tabsList.find('> li');
                        var $panels = $wrap.find('.woocommerce-Tabs-panel, .wc-tab');
                        if (!$tabs.length || !$panels.length) return;

                        $panels.addClass('dd-accordion-panel');

                        var isAccordion = false;

                        function getPanel($li) {
                            var target = $li.find('a').attr('href');
                            return target ? $wrap.find(target) : $();
                        }

                        function showTab($li) {
                            $tabs.removeClass('active').attr('aria-selected', 'false');
                            $panels.hide();
                            $li.addClass('active').attr('aria-selected', 'true');
                            getPanel($li).show();
                        }

                        function toggleAccordion($li) {
                            var wasActive = $li.hasClass('active');
                            $tabs.removeClass('active').attr('aria-selected', 'false');
                            $panels.hide();
                            if (!wasActive) {
                                $li.addClass('active').attr('aria-selected', 'true');
                                getPanel($li).show();
                            }
                        }

                        function enterAccordion() {
                            if (isAccordion) return;
                            isAccordion = true;
                            $tabs.each(function() {
                                var $li = $(this);
                                var $panel = getPanel($li);
                                if ($panel.length) $li.after($panel);
                            });
                        }

                        function enterTabs() {
                            if (!isAccordion) return;
                            isAccordion = false;
                            var $after = $tabsList;
                            $tabs.each(function() {
                                var $panel = getPanel($(this));
                                if ($panel.length) {
                                    $after.after($panel);
                                    $after = $panel;
                                }
                            });
                            var $active = $tabs.filter('.active').first();
                            showTab($active.length ? $active : $tabs.first());
                        }

                        function applyMode() {
                            if (mobileMQ.matches) enterAccordion();
                            else enterTabs();
                        }

                        var $start = $tabs.filter('.active').first();
                        showTab($start.length ? $start : $tabs.first());
                        applyMode();

                        if (mobileMQ.addEventListener) mobileMQ.addEventListener('change', applyMode);
                        else mobileMQ.addListener(applyMode);

                        $tabsList.on('click', '> li', function(e) {
                            e.preventDefault();
                            if (isAccordion) {
                                // Block WooCommerce's own tab handler (delegated on
                                // document) so it can't re-open the panel we just closed.
                                e.stopPropagation();
                                toggleAccordion($(this));
                            } else {
                                showTab($(this));
                            }
                        });
                    });

                    // Product Range sub-tabs (New Preferred Range / Extended Range).
                    $('.dd-range-tabs').each(function() {
                        var $wrap = $(this);
                        var $nav = $wrap.find('.dd-range-nav-item.dd-range-nav-item.dd-range-nav-item');
                        var $panels = $wrap.find('.dd-range-panel');

                        $nav.on('click', function() {
                            var target = $(this).data('target');
                            $nav.removeClass('active');
                            $(this).addClass('active');
                            $panels.each(function() {
                                $(this).toggleClass('active', String($(this).data('panel')) === String(target));
                            });
                        });
                    });
                });
            })(jQuery);
        </script>

        <div class="dd-buy-modal" id="dd-buy-modal" role="dialog" aria-modal="true" aria-hidden="true">
            <div class="dd-buy-modal-overlay" data-dd-buy-close></div>
            <div class="dd-buy-modal-box">
                <button type="button" class="dd-buy-modal-close" data-dd-buy-close aria-label="<?php esc_attr_e('Close', 'silvertell-wc-customisation'); ?>">&times;</button>
                <h3 class="dd-buy-modal-title"></h3>
                <div class="dd-buy-modal-list"></div>
            </div>
        </div>
        <script>
            /* Buy Samples popup: a distributor logo with multiple purchase links opens a
               modal listing them (single-link logos still navigate directly). */
            (function($) {
                $(function() {
                    var $modal = $('#dd-buy-modal');
                    if (!$modal.length) return;

                    function openModal(provider, listHtml) {
                        var title = provider ?
                            provider + ' — <?php echo esc_js(__('choose a part', 'silvertell-wc-customisation')); ?>' :
                            '<?php echo esc_js(__('Choose a part', 'silvertell-wc-customisation')); ?>';
                        $modal.find('.dd-buy-modal-title').text(title);
                        $modal.find('.dd-buy-modal-list').html(listHtml);
                        $modal.addClass('is-open').attr('aria-hidden', 'false');
                        $('body').addClass('dd-buy-modal-open');
                    }

                    function closeModal() {
                        $modal.removeClass('is-open').attr('aria-hidden', 'true');
                        $('body').removeClass('dd-buy-modal-open');
                    }

                    $(document).on('click', '.dd-buy-btn--multi', function(e) {
                        e.preventDefault();
                        var $data = $(this).next('.dd-buy-links-data');
                        openModal($data.data('provider'), $data.html());
                    });

                    $modal.on('click', '[data-dd-buy-close]', closeModal);
                    $(document).on('keydown', function(e) {
                        if (e.key === 'Escape' && $modal.hasClass('is-open')) closeModal();
                    });

                    // Sub-range group accordion inside the popup (delegated, as the content
                    // is injected at open time). Single-open: opening one slides the rest
                    // shut. slideUp/Down give the height animation; the class toggle drives
                    // the chevron + header styling.
                    $modal.on('click', '.dd-buy-acc-head', function() {
                        var $item = $(this).closest('.dd-buy-acc-item');
                        var $acc = $(this).closest('.dd-buy-acc');
                        var wasOpen = $item.hasClass('is-open');

                        $acc.find('.dd-buy-acc-item.is-open').each(function() {
                            $(this).removeClass('is-open')
                                .children('.dd-buy-acc-head').attr('aria-expanded', 'false');
                            $(this).children('.dd-buy-acc-body').stop(true, true).slideUp(200);
                        });

                        if (!wasOpen) {
                            $item.addClass('is-open');
                            $(this).attr('aria-expanded', 'true');
                            $item.children('.dd-buy-acc-body').stop(true, true).slideDown(220);
                        }
                    });
                });
            })(jQuery);
        </script>
        <?php
        // On the main shop page, Elementor's Products widget links product categories
        // to an on-page filter (?filter_cat=<term_id>). Rewrite those links to the real
        // /product-category/<slug>/ archive URL so they navigate like the category-archive
        // template — no server redirect, and always the base archive (page 1), never a
        // carried-over /page/N/. Only runs on the shop page.
        if (function_exists('is_shop') && is_shop()) {
            $cat_terms = get_terms([
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
            ]);
            $cat_links = [];
            if (! is_wp_error($cat_terms)) {
                foreach ($cat_terms as $cat_term) {
                    $link = get_term_link($cat_term);
                    if (! is_wp_error($link)) {
                        $cat_links[(string) $cat_term->term_id] = $link;
                    }
                }
            }
        ?>
            <script>
                (function() {
                    var catLinks = <?php echo wp_json_encode($cat_links); ?>;

                    // Map a filter_cat link's href to its category-archive URL, or null.
                    // Single category only — multi-category filters (e.g. filter_cat=72,88
                    // from the .Filter panel) have no single archive, so leave them be.
                    function archiveFor(href) {
                        if (!href) return null;
                        var m = href.match(/[?&]filter_cat=([^&#]+)/);
                        if (!m) return null;
                        var val = decodeURIComponent(m[1]);
                        if (!/^\d+$/.test(val)) return null;
                        return catLinks[val] || null;
                    }

                    // Rewrite the visible hrefs so hover / copy-link / open-in-new-tab all
                    // use the real archive URL. Re-run after Elementor AJAX pagination or
                    // filtering re-injects links (MutationObserver below).
                    function rewrite() {
                        var links = document.querySelectorAll('a[href*="filter_cat="]');
                        for (var i = 0; i < links.length; i++) {
                            var target = archiveFor(links[i].getAttribute('href'));
                            if (target && links[i].getAttribute('href') !== target) {
                                links[i].setAttribute('href', target);
                            }
                        }
                    }

                    // Intercept plain left-clicks so Elementor's own filter handler can't
                    // re-apply ?filter_cat via AJAX before a rewrite pass catches the link;
                    // force a normal navigation to the archive instead. Modified clicks
                    // (new tab, etc.) fall through to the rewritten href.
                    document.addEventListener('click', function(e) {
                        if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
                        var a = e.target && e.target.closest ? e.target.closest('a[href*="filter_cat="]') : null;
                        if (!a) return;
                        var target = archiveFor(a.getAttribute('href'));
                        if (!target) return;
                        e.preventDefault();
                        e.stopPropagation();
                        window.location.href = target;
                    }, true);

                    function start() {
                        rewrite();
                        if (window.MutationObserver && document.body) {
                            var scheduled = false;
                            new MutationObserver(function() {
                                if (scheduled) return;
                                scheduled = true;
                                setTimeout(function() {
                                    scheduled = false;
                                    rewrite();
                                }, 50);
                            }).observe(document.body, {
                                childList: true,
                                subtree: true
                            });
                        }
                    }

                    if (document.readyState !== 'loading') start();
                    else document.addEventListener('DOMContentLoaded', start);
                })();
            </script>
            <?php
        }

        // Nested attribute filters under each product category in the sidebar's
        // "Product Categories" widget (Elementor/theme addon — no server-side hook into
        // it, so this injects into its rendered markup client-side, same approach as the
        // filter_cat rewrite above). Shown on the shop page and every category archive.
        if ((function_exists('is_shop') && is_shop()) || (function_exists('is_product_category') && is_product_category())) {
            $cat_attr_payload = $this->build_category_attribute_sidebar_payload();
            if (! empty($cat_attr_payload)) {
            ?>
                <script>
                    (function() {
                        var catData = <?php echo wp_json_encode($cat_attr_payload); ?>;
                        var bySlug = {};
                        Object.keys(catData).forEach(function(id) {
                            bySlug[catData[id].slug] = id;
                        });

                        // Resolve an anchor's href to a product_cat term id we have data for, via
                        // either ?filter_cat=<id> (shop page) or /product-category/.../<slug>/
                        // (category archive widgets link straight to the archive).
                        function findCatId(href) {
                            if (!href) return null;
                            var m = href.match(/[?&]filter_cat=([^&#]+)/);
                            if (m) {
                                var val = decodeURIComponent(m[1]);
                                return (/^\d+$/.test(val) && catData[val]) ? val : null;
                            }
                            m = href.match(/\/product-category\/([^?#]+)/);
                            if (m) {
                                var parts = m[1].split('/').filter(Boolean);
                                var slug = parts[parts.length - 1];
                                return (slug && bySlug[slug]) ? bySlug[slug] : null;
                            }
                            return null;
                        }

                        function buildAttrsBlock(data) {
                            var wrap = document.createElement('div');
                            wrap.className = 'dd-cat-attrs';

                            data.attrs.forEach(function(attr) {
                                var group = document.createElement('div');
                                group.className = 'dd-cat-attr' + (data.current ? ' is-open' : '');

                                var toggle = document.createElement('button');
                                toggle.type = 'button';
                                toggle.className = 'dd-cat-attr-toggle';
                                toggle.setAttribute('aria-expanded', data.current ? 'true' : 'false');
                                toggle.textContent = attr.label;
                                group.appendChild(toggle);

                                var list = document.createElement('ul');
                                list.className = 'dd-cat-attr-values';
                                attr.terms.forEach(function(term) {
                                    var item = document.createElement('li');
                                    var link = document.createElement('a');
                                    link.className = 'dd-attr-opt' + (term.active ? ' is-active' : '');
                                    link.setAttribute('href', term.href);
                                    link.appendChild(document.createTextNode(term.name + ' '));
                                    var count = document.createElement('span');
                                    count.className = 'dd-attr-count';
                                    count.textContent = '(' + term.count + ')';
                                    link.appendChild(count);
                                    item.appendChild(link);
                                    list.appendChild(item);
                                });
                                group.appendChild(list);
                                wrap.appendChild(group);
                            });

                            if (data.clear_url) {
                                var clear = document.createElement('a');
                                clear.className = 'dd-cat-attr-clear';
                                clear.setAttribute('href', data.clear_url);
                                clear.textContent = '<?php echo esc_js(__('Clear filters', 'silvertell-wc-customisation')); ?>';
                                wrap.appendChild(clear);
                            }

                            return wrap;
                        }

                        function inject() {
                            var anchors = document.querySelectorAll('a[href]');
                            for (var i = 0; i < anchors.length; i++) {
                                var a = anchors[i];
                                if (a.closest('.products') || a.closest('.woocommerce-breadcrumb') || a.closest('.dd-cat-attrs')) continue;
                                var li = a.closest('li');
                                if (!li || li.getAttribute('data-dd-attrs')) continue;
                                var catId = findCatId(a.getAttribute('href'));
                                if (!catId) continue;
                                li.setAttribute('data-dd-attrs', '1');
                                li.appendChild(buildAttrsBlock(catData[catId]));
                            }
                        }

                        // Expand/collapse an attribute's value list (independent, not single-open).
                        document.addEventListener('click', function(e) {
                            var toggle = e.target && e.target.closest ? e.target.closest('.dd-cat-attr-toggle') : null;
                            if (!toggle) return;
                            e.preventDefault();
                            var group = toggle.closest('.dd-cat-attr');
                            var open = group.classList.toggle('is-open');
                            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                        }, false);

                        // Force a normal navigation on value/clear clicks — same capture-phase
                        // guard as the filter_cat rewrite, so neither Elementor's own filter
                        // handler nor the theme's PJAX AjaxFilter swallows the click.
                        document.addEventListener('click', function(e) {
                            if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
                            var a = e.target && e.target.closest ? e.target.closest('a.dd-attr-opt, a.dd-cat-attr-clear') : null;
                            if (!a) return;
                            e.preventDefault();
                            e.stopPropagation();
                            window.location.href = a.getAttribute('href');
                        }, true);

                        function start() {
                            inject();
                            if (window.MutationObserver && document.body) {
                                var scheduled = false;
                                new MutationObserver(function() {
                                    if (scheduled) return;
                                    scheduled = true;
                                    setTimeout(function() {
                                        scheduled = false;
                                        inject();
                                    }, 50);
                                }).observe(document.body, {
                                    childList: true,
                                    subtree: true
                                });
                            }
                        }

                        if (document.readyState !== 'loading') start();
                        else document.addEventListener('DOMContentLoaded', start);
                    })();
                </script>
        <?php
            }
        }
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
        echo '<span class="dd-file-display" style="font-weight:500; color:var( --e-global-color-c172edd ); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:300px;">' . esc_html($filename_display) . '</span>';
        echo '</span>';
        if ($description || $display_file) {
            echo '<span class="description" style="display:block; margin-top:8px;">';
            if ($description) echo esc_html($description) . '<br>';
            if ($display_file) echo '<a href="' . esc_url($display_file) . '" target="_blank">Preview Current File</a>';
            echo '</span>';
        }
        echo '</p></div>';
    }

    /**
     * Render one "Buy Samples" link row (label + URL) for a provider's repeater on the
     * product editor. Inputs are named buy_links[<meta_key>][label][] / [url][].
     */
    private function render_buy_link_row($meta_key, $label = '', $url = '', $is_template = false)
    {
        $row_class  = $is_template ? 'dd-repeater-row dd-template' : 'dd-repeater-row';
        $input_attr = $is_template ? 'disabled="disabled"' : '';
        $base       = 'buy_links[' . $meta_key . ']';
        $title      = $label !== '' ? $label : ($url !== '' ? $url : 'New Link');
        ?>
        <div class="<?php echo esc_attr($row_class); ?>">
            <div class="dd-repeater-header">
                <div class="dd-header-left">
                    <span class="dashicons dashicons-menu dd-drag-handle"></span>
                    <span class="dd-row-title"><?php echo esc_html($title); ?></span>
                </div>
                <div class="dd-header-right dd-repeater-actions">
                    <span class="dashicons dashicons-arrow-down-alt2 dd-collapse-row" title="Toggle"></span>
                    <span class="dashicons dashicons-admin-page dd-duplicate-row" title="Duplicate"></span>
                    <span class="dashicons dashicons-trash dd-delete-row" title="Delete"></span>
                </div>
            </div>
            <div class="dd-repeater-content">
                <div class="dd-field-group">
                    <label><?php esc_html_e('Label (e.g. part number — shown in the popup)', 'silvertell-wc-customisation'); ?></label>
                    <input type="text" name="<?php echo esc_attr($base); ?>[label][]" class="dd-bind-title dd-full-width" value="<?php echo esc_attr($label); ?>" <?php echo $input_attr; ?> />
                </div>
                <div class="dd-field-group">
                    <label><?php esc_html_e('URL', 'silvertell-wc-customisation'); ?></label>
                    <input type="url" name="<?php echo esc_attr($base); ?>[url][]" class="dd-full-width" value="<?php echo esc_attr($url); ?>" <?php echo $input_attr; ?> />
                </div>
            </div>
        </div>
    <?php
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

        // 1. Save Buy Samples — one URL per provider (matches child modal format).
        if (isset($_POST['buy_samples']) && is_array($_POST['buy_samples'])) {
            $providers  = $this->get_sample_providers();
            $valid_keys = wp_list_pluck($providers, 'meta_key');
            $buy_samples = wp_unslash($_POST['buy_samples']);
            foreach ($buy_samples as $key => $url) {
                if (! in_array($key, $valid_keys, true)) continue;
                $url = esc_url_raw(trim($url));
                if ($url !== '') {
                    update_post_meta($post_id, $key, sanitize_url($url));
                } else {
                    delete_post_meta($post_id, $key);
                }
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
                color: var(--e-global-color-c172edd);
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
                color: var(--e-global-color-c172edd);
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
                margin: 0;
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
                float: none !important;
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
                color: var(--e-global-color-c172edd);
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

/**
 * Declare the "Product Features" Elementor widget class.
 *
 * Kept as a top-level function (not a class method) because PHP does not allow
 * a class declaration to be nested inside another class. It is called lazily
 * from Silvertell_Woocommerce_Customisation::register_elementor_widgets() on the
 * `elementor/widgets/register` hook, so \Elementor\Widget_Base is loaded by then.
 * The class_exists guards make it safe to call more than once.
 */
function silvertell_define_features_elementor_widget()
{
    if (class_exists('Silvertell_Features_Elementor_Widget')) return;
    if (! class_exists('\Elementor\Widget_Base')) return;

    class Silvertell_Features_Elementor_Widget extends \Elementor\Widget_Base
    {
        public function get_name()
        {
            return 'silvertell_product_features';
        }

        public function get_title()
        {
            return __('Product Features', 'silvertell-wc-customisation');
        }

        public function get_icon()
        {
            return 'eicon-bullet-list';
        }

        public function get_categories()
        {
            return ['woocommerce-elements-single', 'woocommerce-elements', 'general'];
        }

        public function get_keywords()
        {
            return ['features', 'product', 'woocommerce', 'silvertell', 'list'];
        }

        protected function register_controls()
        {
            $this->start_controls_section('content_section', [
                'label' => __('Content', 'silvertell-wc-customisation'),
                'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
            ]);

            $this->add_control('heading', [
                'label'       => __('Heading', 'silvertell-wc-customisation'),
                'type'        => \Elementor\Controls_Manager::TEXT,
                'default'     => __('Features', 'silvertell-wc-customisation'),
                'placeholder' => __('Leave blank to hide the heading', 'silvertell-wc-customisation'),
                'label_block' => true,
            ]);

            $this->add_control('heading_tag', [
                'label'   => __('Heading HTML Tag', 'silvertell-wc-customisation'),
                'type'    => \Elementor\Controls_Manager::SELECT,
                'default' => 'h2',
                'options' => [
                    'h1' => 'H1',
                    'h2' => 'H2',
                    'h3' => 'H3',
                    'h4' => 'H4',
                    'h5' => 'H5',
                    'h6' => 'H6',
                ],
                'condition' => ['heading!' => ''],
            ]);

            $this->add_control('no_features_notice', [
                'type'            => \Elementor\Controls_Manager::RAW_HTML,
                'raw'             => __('This widget outputs the current product\'s Features (set on the product\'s Features tab in wp-admin). It is only visible when the product has features.', 'silvertell-wc-customisation'),
                'content_classes' => 'elementor-descriptor',
            ]);

            $this->end_controls_section();
        }

        protected function render()
        {
            if (! function_exists('wc_get_product')) return;

            global $product;
            $current = $product instanceof WC_Product ? $product : wc_get_product(get_the_ID());
            if (! $current instanceof WC_Product) {
                if (\Elementor\Plugin::$instance->editor->is_edit_mode()) {
                    echo '<p>' . esc_html__('No product in context — preview on a product page.', 'silvertell-wc-customisation') . '</p>';
                }
                return;
            }

            $html = Silvertell_Woocommerce_Customisation::get_features_list_html($current->get_id());
            if ($html === '') {
                if (\Elementor\Plugin::$instance->editor->is_edit_mode()) {
                    echo '<p>' . esc_html__('This product has no features yet.', 'silvertell-wc-customisation') . '</p>';
                }
                return;
            }

            $settings = $this->get_settings_for_display();
            $heading  = isset($settings['heading']) ? trim($settings['heading']) : '';

            echo '<div class="dd-features-widget">';
            if ($heading !== '') {
                $tag = isset($settings['heading_tag']) ? $settings['heading_tag'] : 'h2';
                $tag = in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true) ? $tag : 'h2';
                echo '<' . $tag . ' class="dd-features-widget-title">' . esc_html($heading) . '</' . $tag . '>';
            }
            echo $html; // already escaped in get_features_list_html()
            echo '</div>';
        }
    }
}

/**
 * Declare the "Buy Samples" Elementor widget class.
 *
 * Mirrors the Product Features widget: a top-level function (PHP forbids nesting a class
 * in a method) called lazily from register_elementor_widgets() once Elementor's base
 * class exists. Outputs the current product's distributor buy links; a distributor with
 * multiple links opens a popup list (see render_provider_buttons + inject_frontend_assets).
 */
function silvertell_define_buy_samples_elementor_widget()
{
    if (class_exists('Silvertell_Buy_Samples_Elementor_Widget')) return;
    if (! class_exists('\Elementor\Widget_Base')) return;

    class Silvertell_Buy_Samples_Elementor_Widget extends \Elementor\Widget_Base
    {
        public function get_name()
        {
            return 'silvertell_buy_samples';
        }

        public function get_title()
        {
            return __('Buy Samples', 'silvertell-wc-customisation');
        }

        public function get_icon()
        {
            return 'eicon-cart-medium';
        }

        public function get_categories()
        {
            return ['woocommerce-elements-single', 'woocommerce-elements', 'general'];
        }

        public function get_keywords()
        {
            return ['buy', 'samples', 'distributor', 'product', 'woocommerce', 'silvertell'];
        }

        protected function register_controls()
        {
            $this->start_controls_section('content_section', [
                'label' => __('Content', 'silvertell-wc-customisation'),
                'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
            ]);

            $this->add_control('heading', [
                'label'       => __('Heading', 'silvertell-wc-customisation'),
                'type'        => \Elementor\Controls_Manager::TEXT,
                'default'     => __('Buy Samples', 'silvertell-wc-customisation'),
                'placeholder' => __('Leave blank to hide the heading', 'silvertell-wc-customisation'),
                'label_block' => true,
            ]);

            $this->add_control('heading_tag', [
                'label'   => __('Heading HTML Tag', 'silvertell-wc-customisation'),
                'type'    => \Elementor\Controls_Manager::SELECT,
                'default' => 'h3',
                'options' => [
                    'h1' => 'H1',
                    'h2' => 'H2',
                    'h3' => 'H3',
                    'h4' => 'H4',
                    'h5' => 'H5',
                    'h6' => 'H6',
                ],
                'condition' => ['heading!' => ''],
            ]);

            $this->add_control('no_links_notice', [
                'type'            => \Elementor\Controls_Manager::RAW_HTML,
                'raw'             => __('This widget outputs the distributor buy links for the current product and all its variants (set on each product\'s Buy Samples tab in wp-admin). Each distributor shows once; clicking it lists every variant\'s link. Only visible when there is at least one link.', 'silvertell-wc-customisation'),
                'content_classes' => 'elementor-descriptor',
            ]);

            $this->end_controls_section();
        }

        protected function render()
        {
            if (! function_exists('wc_get_product')) return;

            global $product;
            $current = $product instanceof WC_Product ? $product : wc_get_product(get_the_ID());
            if (! $current instanceof WC_Product) {
                if (\Elementor\Plugin::$instance->editor->is_edit_mode()) {
                    echo '<p>' . esc_html__('No product in context — preview on a product page.', 'silvertell-wc-customisation') . '</p>';
                }
                return;
            }

            // Aggregate the product's own links plus every variant's, so a range parent
            // (Ag9900) still shows each distributor once with all the variant links in the popup.
            $buttons = Silvertell_Woocommerce_Customisation::render_aggregated_provider_buttons($current->get_id());
            if ($buttons === '') {
                if (\Elementor\Plugin::$instance->editor->is_edit_mode()) {
                    echo '<p>' . esc_html__('This product (and its variants) have no buy links yet.', 'silvertell-wc-customisation') . '</p>';
                }
                return;
            }

            $settings = $this->get_settings_for_display();
            $heading  = isset($settings['heading']) ? trim($settings['heading']) : '';

            echo '<div class="dd-buy-samples-widget">';
            if ($heading !== '') {
                $tag = isset($settings['heading_tag']) ? $settings['heading_tag'] : 'h3';
                $tag = in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true) ? $tag : 'h3';
                echo '<' . $tag . ' class="dd-buy-samples-widget-title">' . esc_html($heading) . '</' . $tag . '>';
            }
            echo '<div class="dd-buy-wrap">' . $buttons . '</div>'; // buttons already escaped
            echo '</div>';
        }
    }
}

/**
 * Whether a file URL points at a PDF (by extension). PDFs are opened inline in a
 * new tab rather than force-downloaded by the document buttons.
 */
function silvertell_url_is_pdf($url)
{
    $path = parse_url((string) $url, PHP_URL_PATH);
    if (! is_string($path) || $path === '') {
        $path = (string) $url;
    }
    return strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) === 'pdf';
}

/**
 * Declare the "Document Link" Elementor widget class.
 *
 * Lets the editor pick any product-support post and set custom button text;
 * renders a single download-button link to that post's pdf_file.
 */
function silvertell_define_document_link_elementor_widget()
{
    if (class_exists('Silvertell_Document_Link_Elementor_Widget')) return;
    if (! class_exists('\Elementor\Widget_Base')) return;

    class Silvertell_Document_Link_Elementor_Widget extends \Elementor\Widget_Base
    {
        public function get_name()
        {
            return 'silvertell_document_link';
        }

        public function get_title()
        {
            return __('Document Link', 'silvertell-wc-customisation');
        }

        public function get_icon()
        {
            return 'eicon-download-button';
        }

        public function get_categories()
        {
            return ['woocommerce-elements-single', 'woocommerce-elements', 'general'];
        }

        public function get_keywords()
        {
            return ['document', 'pdf', 'download', 'product', 'support', 'silvertell'];
        }

        protected function register_controls()
        {
            $this->start_controls_section('content_section', [
                'label' => __('Content', 'silvertell-wc-customisation'),
                'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
            ]);

            $this->add_control('button_text', [
                'label'       => __('Button Text', 'silvertell-wc-customisation'),
                'type'        => \Elementor\Controls_Manager::TEXT,
                'default'     => __('Download', 'silvertell-wc-customisation'),
                'placeholder' => __('Leave blank to use the document title', 'silvertell-wc-customisation'),
                'label_block' => true,
            ]);

            $this->add_control('loop_notice', [
                'type'            => \Elementor\Controls_Manager::RAW_HTML,
                'raw'             => __('Place this widget inside a loop over product-support posts. It reads the current post in the loop automatically.', 'silvertell-wc-customisation'),
                'content_classes' => 'elementor-descriptor',
            ]);

            $this->end_controls_section();
        }

        protected function render()
        {
            $settings    = $this->get_settings_for_display();
            $document_id = get_the_ID();
            $button_text = trim($settings['button_text'] ?? '');
            $is_editor   = \Elementor\Plugin::$instance->editor->is_edit_mode();

            if (! $document_id) {
                if ($is_editor) {
                    echo '<p>' . esc_html__('No post in context — place this widget inside a loop.', 'silvertell-wc-customisation') . '</p>';
                }
                return;
            }

            $file_val = get_post_meta($document_id, 'pdf_file', true);
            $file_url = is_numeric($file_val) ? wp_get_attachment_url((int) $file_val) : $file_val;

            if (empty($file_url)) {
                if ($is_editor) {
                    echo '<p>' . esc_html__('This post has no file attached (pdf_file meta is empty).', 'silvertell-wc-customisation') . '</p>';
                }
                return;
            }

            if ($button_text === '') {
                $button_text = get_the_title($document_id) ?: __('Download', 'silvertell-wc-customisation');
            }

            $icon = '<svg class="dd-doc-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>';

            // PDFs open inline in a new tab; other file types keep force-downloading.
            $download_attr = silvertell_url_is_pdf($file_url) ? '' : ' download';

            echo '<a class="dd-doc-btn" href="' . esc_url($file_url) . '" target="_blank" rel="noopener"' . $download_attr . '>'
                . $icon
                . '<span class="dd-doc-btn-label">' . esc_html($button_text) . '</span></a>';
        }
    }
}
