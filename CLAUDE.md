# CLAUDE.md

Guidance for Claude Code when working in this repository.

## Overview

A single-file WordPress plugin (`silvertel-woocommerce-customisation.php`) that extends
WooCommerce for Silvertell. It adds an `evaluation-board` custom post type, a bespoke
AJAX-chunked CSV importer that sideloads remote images while building hierarchical
categories, an interceptor that sideloads files during native WooCommerce product
imports, four custom product-data tabs, a parent/child "Product Range" system for
sub-range and variant products, and a self-contained "native repeater" admin UI (no ACF
or other field-plugin dependency).

## Commands

There is **no build, test, or lint tooling** — it's a plain PHP plugin with no
`composer.json`, `package.json`, or CI. Working with it means editing the one file and
deploying it to a WordPress install:

- Deploy: copy `silvertel-woocommerce-customisation.php` into
  `wp-content/plugins/silvertel-woocommerce-customisation/`, then activate it in
  **wp-admin → Plugins**.
- Requires an active **WooCommerce** install (the plugin assumes WC functions/hooks exist
  and gates its admin pages on the `manage_woocommerce` capability).
- Sanity-check syntax locally with `php -l silvertel-woocommerce-customisation.php`.

## Architecture

Everything lives in one class, `Silvertell_Woocommerce_Customisation`, instantiated at
the bottom of the file. **`register_hooks()` (top of the class) is the map of the whole
plugin** — read it first; every feature is a hook registered there pointing at a method.

The major subsystems:

- **Evaluation Board CPT + taxonomy** (`register_evaluation_board_cpt`): registers the
  hierarchical, private `evaluation-board` post type and `evaluation-board-category`
  taxonomy. Each board is keyed by a `_unique_code` meta value used for import matching
  and parent/child linking.

- **Two distinct importers** — don't confuse them:
  1. **Native WC product-import interceptor** (`intercept_meta_for_sideload`, on
     `woocommerce_product_import_pre_insert_product_object`): runs during WooCommerce's
     built-in CSV product importer. It rewrites incoming meta — sideloads URL values for
     configured keys and `_gallery_image_N`, folds `_document_*_N` columns into linked
     `product-support` posts, folds `_feature(d)_N` columns into a `_features` array, and
     resolves `_linked_eval_board(s)` codes/titles to post IDs.
  2. **Standalone AJAX Evaluation Board importer** (`render_eb_importer_page` →
     `ajax_import_chunk` → `ajax_import_hierarchy`): a separate two-step UI under
     *Evaluation Boards → Import CSV*. Step 1 uploads a CSV to the uploads dir; step 2
     processes it 5 rows at a time over AJAX with a live progress bar/log, then runs a
     final hierarchy pass. Parent links are **deferred**: child→parent code pairs are
     stashed in the `silvertell_eb_delayed_parents` transient during the row pass and
     resolved only after all rows exist, so forward references work. The temp CSV is
     `unlink()`ed at the end of the hierarchy pass.

- **Shared helpers** used by both importers:
  - `sideload_file_to_media_library($url, $parent_id)`: downloads a URL into the media
    library, **deduping by the `_source_url` postmeta** so re-imports don't duplicate
    attachments. Returns an attachment ID or `WP_Error`.
  - `assign_hierarchical_terms_to_post($post_id, $string, $taxonomy)`: parses category
    strings like `Parent > Child, Other Chain`, HTML-entity-decoding first, splitting on
    commas (independent chains) then `>` (depth), creating terms as needed and matching
    ampersand variants to avoid duplicates.

- **Custom product-data tabs** (`add_custom_product_data_tabs` /
  `render_custom_product_data_panels` / `save_custom_product_data`): adds **Buy Samples**
  (dynamic provider URL fields), **Documents** (multi-select of `product-support` posts →
  `_linked_documents`), **Features** (repeater → `_features`), and **Evaluation Boards**
  (multi-select → `_linked_eval_boards`) to the product editor.

- **Child Products manager + Product Range frontend tab**: simple products can have a
  `post_parent` (sub-range "group" products and orderable "variants"). On the parent
  product's edit screen, `register_child_products_meta_box` shows the descendant tree
  with Edit/Add Child/Delete; `print_child_product_modal` plus the
  `silvertell_child_form` / `silvertell_child_save` / `silvertell_child_delete` AJAX
  actions power a popup editor for Title, Description, Subtext (short description), SKU,
  Categories, Attributes (pick a global/taxonomy attribute + term(s) via a select2/
  selectWoo multi-select with select-all/none and a "Create value" button that calls
  WooCommerce's own `woocommerce_add_new_attribute` AJAX action, or a custom name/value
  pair with `|`-separated values), and Buy Samples. Saving assigns global-attribute terms
  via `wp_set_object_terms`; deleting (`delete_product_branch`) is **permanent and
  recursive** (`wp_delete_post($id, true)`, no trash). Children are hidden from the admin
  Products list and status counts (`hide_child_products_from_list`,
  `exclude_children_from_count`), excluded from frontend shop/archive/search queries
  (`hide_child_products_from_shop`, on `woocommerce_product_query`), and 301-redirect to
  their top-level ancestor on the frontend (`redirect_child_product_pages`). `register_frontend_product_tabs` then adds a
  "Product Range" tab (`build_product_range`) on the ancestor's page: L2 children with
  their own children render as sub-range sections (heading/description/note + variant
  table, grouped into sub-tabs by their "Product Range > X" category via
  `get_product_range_subcategory` — but only when there's more than one group; a single
  group renders its sections inline with no lone tab), L2 children without children render
  as flat variant rows, and a childless product with attributes/buy links renders itself
  as one row.

- **Meta boxes**: PDF config on `product-support` posts (`pdf_file` meta), and Evaluation
  Board details (`_unique_code`, `_manual`, and per-provider sample URLs).

- **Settings page** (*WooCommerce → Silvertell Settings*): edits the
  `silvertell_file_meta_keys` option (comma-separated meta keys the product-import
  interceptor will sideload) and the `silvertell_sample_providers` option (a repeater of
  name/meta-key/logo; defaults to Farnell/Mouser/Digikey). `render_provider_buttons`
  (static; shared by the Buy Samples Elementor widget, eval-board card, and Product Range
  table) renders a logo-only bordered pill (`.dd-buy-btn--logo`) when a provider has a
  logo, otherwise falls back to the provider name with a cart icon. Each provider can hold
  **multiple labelled links** per product (a repeater on the product's Buy Samples tab,
  stored as an array of `['label','url']` under the provider meta key — `get_provider_links`
  normalises both that array and the legacy single-URL string written by the eval-board
  meta box / child modal). A provider with one link is a direct anchor; with several, the
  pill becomes a `<button class="dd-buy-btn--multi">` that opens the `.dd-buy-modal` popup
  (markup/CSS/JS injected by `inject_frontend_assets`). The **Buy Samples Elementor widget**
  uses `render_aggregated_provider_buttons` instead: it walks the product **and all its
  variant descendants** (`collect_range_link_sources`, which mirrors `build_product_range`'s
  L1/L2/L3 structure) and merges each distributor's links into one logo whose popup lists
  every variant's link (labelled by variant title) — so a range parent like Ag9900, which
  has no links of its own, still shows the distributors. Each link keeps its `tab`
  (sub-tab / "Product Range" subcategory) and `section` (sub-range group title). The popup
  body (`build_buy_popup_content`) groups links by the deepest available label and, when
  there are 2+ named groups, renders an **accordion** (one collapsible item per sub-range
  group, e.g. Ag9900M / Ag9900MT / Ag9900LP) with the sub-tab label (Extended Range / New
  Preferred Range) as a heading above its groups; otherwise a plain list
  (`build_buy_links_list`). The accordion JS (single-open) is delegated off `#dd-buy-modal`
  since the content is injected at open time. `render_provider_link_buttons` is the shared
  pill/popup HTML builder.

- **Other frontend single-product tabs** (`register_frontend_product_tabs`, on
  `woocommerce_product_tabs`, priority 98): hides the Reviews tab, pushes "Additional
  information" last, and conditionally adds "Documents" (from `_linked_documents`) and
  "Evaluation Boards" (from `_linked_eval_boards`) tabs. Features are **not** a tab —
  they render via the separate "Product Features" Elementor widget; the distributor "Buy
  Samples" block is likewise its own Elementor widget (both registered in
  `register_elementor_widgets`, defined by top-level `silvertell_define_*_elementor_widget`
  functions). The Evaluation Boards tab
  (`render_evaluation_boards_tab`) renders a card per linked board
  (`render_eval_board_card`), plus a variant table for each board's orderable children
  grouped by `evaluation-board-category` (`render_eval_board_children_table` →
  `render_eb_board_table`). If those children span more than one named category, the
  groups render as `.dd-range-tabs` sub-tabs — the same markup/JS/CSS switcher used by
  the Product Range tab.

- **Shop/archive grid** (`add_shop_loop_view_button`, on
  `woocommerce_after_shop_loop_item`): the theme strips WooCommerce's native loop button,
  so this re-adds a "View Product" permalink button (`.dd-view-product`) to each card,
  printing its inline CSS once on first render.

- **Responsive layout** (`inject_frontend_assets`, below 768px): the native WooCommerce
  single-product tabs (`.woocommerce-tabs ul.tabs.wc-tabs`) become a single-open,
  closeable accordion — the tab switcher moves each panel to sit directly under its
  header tab on `matchMedia` change, clicking the open tab again collapses it (with
  `stopPropagation` to stop WooCommerce's own tab handler reopening it), and the DOM
  order reverts back to the normal tab layout above 768px. `.dd-range-table` and
  `.dd-eb-table` rows stack into cards, with each `<td data-label="...">` (set in
  `render_range_table` and `render_eb_board_table`) becoming the row's visible label via
  CSS `::before`. The `.dd-range-nav` sub-tabs (Product Range / Evaluation Boards
  sub-tabs) keep the same underlined-tab style as desktop on mobile, just with smaller
  font size and padding.

- **Native repeater UI** (`inject_repeater_assets`, printed to `admin_footer`): all the
  inline CSS/JS powering the `.dd-repeater-*` rows — add/duplicate/delete/collapse,
  jQuery-UI sortable drag handles, and `wp.media` file pickers (`.dd-upload-file` →
  `.dd-file-input`, with optional `data-sync` to mirror a value across inputs). This is
  the project's home-grown alternative to a field plugin.

## Conventions

- **Prefixes carry meaning.** `silvertell_`/`silvertell-` namespaces hooks, option names,
  nonces, page slugs, and AJAX actions. `dd-` (Digitally Disruptive) namespaces all custom
  admin CSS classes and the repeater JS. Meta keys are underscore-prefixed; user-entered
  provider keys are auto-prefixed with `_` in `sanitize_sample_providers`.
- **Sample providers are dynamic, never hardcoded.** Both the product "Buy Samples" tab
  and the eval-board meta box loop over `get_sample_providers()`. Add provider fields by
  editing the option via the settings page, not by adding code.
- **Save handlers follow the WP triad**: verify the nonce, bail on `DOING_AUTOSAVE`, check
  `current_user_can`, then sanitize before `update_post_meta`. Match this when adding
  fields.
- **Versioning**: bump the `Version:` header in the plugin docblock on every change — the
  git history is almost entirely "Update …php" version bumps, so that header is the de
  facto changelog.

## Gotchas

- **`product-support` CPT and `product-support-category` taxonomy are NOT registered in
  this file.** The Documents tab, the document importer, and the default taxonomy in
  `assign_hierarchical_terms_to_post` all assume they already exist (registered by the
  theme or another plugin). If documents silently fail to save or link, check that those
  are registered elsewhere.
- **`reduce_import_batch_size` forces a batch size of 5 for *all* WooCommerce product
  imports site-wide**, not just Silvertell ones — a deliberate throttle so sideloads don't
  time out, but it slows every product import.
- **Image sideloading is the main failure mode.** `download_url` is given a 60s HTTP
  timeout and the AJAX importer can still hit server timeouts on large/slow images (the JS
  surfaces a "Server Timeout" message). Expect slowness and partial-progress retries on
  big imports.
- **AJAX-importer CSV format** is column-driven: `Name` (required), `Unique Code`,
  `Description`, `Categories` (hierarchical string), `Images` (URL → featured image),
  `Parent` (a `_unique_code` to link to), and any `Meta: <key>` columns (a `Meta: _manual`
  URL value gets sideloaded). The first header cell is BOM-stripped.
- **Cross-request state lives in a transient.** Interrupting the AJAX import between the
  row pass and the hierarchy pass can leave `silvertell_eb_delayed_parents` set and the
  temp upload CSV un-deleted.
- A few paths use **direct `$wpdb` queries** (the `_source_url` dedup lookup and
  `clear_dynamic_meta_keys`); they're `prepare()`d, so keep them that way if edited.
