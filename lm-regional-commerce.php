<?php
/**
 * Plugin Name: LM Regional Commerce
 * Description: Country-based WooCommerce pricing, currency and independent regional inventory (Saudi Arabia, UAE, Pakistan etc.). Prices are calculated with a fixed multiplier per region (no external API), with optional custom prices per product.
 * Version: 1.3.0
 * Author: DrSmoK3y
 * Plugin URI: https://github.com/DrSmoK3y/LM-Regional-Commerce/
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 7.0
 * Text Domain: lm-regional-commerce
 */
if (!defined('ABSPATH')) exit;

define('LMRC_VERSION', '1.3.0');

// HPOS + block checkout compatibility must be declared from the main file, early.
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

final class LM_Regional_Commerce {
    const OPT          = 'lmrc_regions';
    const DEFAULT_OPT  = 'lmrc_default_region';
    const MODAL_OPT    = 'lmrc_show_modal';
    const FLOAT_OPT    = 'lmrc_floating_button';
    const COOKIE       = 'lmrc_region';
    const META         = '_lmrc_regions';
    const NONCE        = 'lmrc_admin_nonce';

    private static $instance = null;
    private $regions = null;       // per-request cache of region settings
    private $current_key = null;   // per-request cache of visitor's region
    private $order_region = null;  // region forced while WooCommerce reduces/restores order stock

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        // Frontend region selection.
        add_action('init', [$this, 'maybe_set_region_cookie'], 5);
        add_action('wp_enqueue_scripts', [$this, 'frontend_assets']);
        add_action('wp_footer', [$this, 'render_modal']);
        add_action('wp_footer', [$this, 'render_floating_button']);
        add_shortcode('lmrc_region_switcher', [$this, 'switcher_shortcode']);
        add_filter('nav_menu_item_title', [$this, 'menu_item_title'], 20, 4);
        add_filter('nav_menu_css_class', [$this, 'menu_item_classes'], 20, 2);
        add_filter('nav_menu_link_attributes', [$this, 'menu_item_atts'], 20, 2);
        add_filter('get_custom_logo', [$this, 'filter_custom_logo'], 20);
        add_filter('rocket_cache_dynamic_cookies', [$this, 'cache_vary_cookie']); // WP Rocket: separate cache per region
        add_filter('litespeed_vary_cookies', [$this, 'cache_vary_cookie']);       // LiteSpeed Cache: separate cache per region

        // Currency.
        add_filter('woocommerce_currency', [$this, 'filter_currency'], 99);
        add_filter('woocommerce_currency_symbol', [$this, 'filter_currency_symbol'], 99, 2);
        add_filter('wc_get_price_decimals', [$this, 'filter_price_decimals'], 99);

        // Prices — simple products & variations (view context).
        foreach (['woocommerce_product_get_', 'woocommerce_product_variation_get_'] as $p) {
            add_filter($p . 'price', [$this, 'filter_price'], 99, 2);
            add_filter($p . 'regular_price', [$this, 'filter_regular_price'], 99, 2);
            add_filter($p . 'sale_price', [$this, 'filter_sale_price'], 99, 2);
            add_filter($p . 'stock_quantity', [$this, 'filter_stock_quantity'], 99, 2);
            add_filter($p . 'manage_stock', [$this, 'filter_manage_stock'], 99, 2);
        }
        // Variable product price ranges (these are read in 'edit' context, so the getters above don't apply).
        add_filter('woocommerce_variation_prices_price', [$this, 'filter_variation_prices_price'], 99, 3);
        add_filter('woocommerce_variation_prices_regular_price', [$this, 'filter_variation_prices_regular'], 99, 3);
        add_filter('woocommerce_variation_prices_sale_price', [$this, 'filter_variation_prices_sale'], 99, 3);
        add_filter('woocommerce_get_variation_prices_hash', [$this, 'variation_prices_hash'], 99, 3);

        // Stock & availability.
        add_filter('woocommerce_product_is_in_stock', [$this, 'filter_is_in_stock'], 99, 2);
        add_filter('woocommerce_is_purchasable', [$this, 'filter_purchasable'], 99, 2);
        add_filter('woocommerce_variation_is_purchasable', [$this, 'filter_purchasable'], 99, 2);
        add_filter('woocommerce_get_availability', [$this, 'filter_availability'], 99, 2);
        add_filter('woocommerce_quantity_input_args', [$this, 'filter_quantity_args'], 99, 2);
        add_filter('woocommerce_available_variation', [$this, 'filter_available_variation'], 99, 3);
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validate_add_to_cart'], 99, 4);
        add_action('woocommerce_check_cart_items', [$this, 'check_cart_items']);

        // Admin: product & variation fields.
        add_filter('woocommerce_product_data_tabs', [$this, 'product_tab']);
        add_action('woocommerce_product_data_panels', [$this, 'product_panel']);
        add_action('woocommerce_admin_process_product_object', [$this, 'save_product_object']);
        add_action('woocommerce_variation_options_pricing', [$this, 'variation_fields'], 20, 3);
        add_action('woocommerce_admin_process_variation_object', [$this, 'save_variation_object'], 10, 2);

        // Admin: settings page.
        add_action('admin_menu', [$this, 'admin_menu'], 99);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('admin_post_lmrc_save_regions', [$this, 'save_regions']);

        // Orders.
        add_action('woocommerce_checkout_create_order', [$this, 'save_order_region'], 20);
        add_action('woocommerce_store_api_checkout_update_order_from_request', [$this, 'save_order_region'], 20);
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'admin_order_region']);
        foreach (['processing', 'completed', 'on-hold'] as $s) add_action('woocommerce_order_status_' . $s, [$this, 'reduce_regional_stock'], 20);
        foreach (['cancelled', 'refunded', 'failed', 'pending'] as $s) add_action('woocommerce_order_status_' . $s, [$this, 'restore_regional_stock'], 20);

        // Keep WooCommerce's global stock untouched for regionally managed items.
        add_filter('woocommerce_can_reduce_order_stock', [$this, 'enter_order_context'], 999, 2);
        add_filter('woocommerce_can_restore_order_stock', [$this, 'enter_order_context'], 999, 2);
        add_action('woocommerce_reduce_order_stock', [$this, 'leave_order_context']);
        add_action('woocommerce_restore_order_stock', [$this, 'leave_order_context']);
    }

    /* ------------------------------------------------------------------ *
     * Region helpers
     * ------------------------------------------------------------------ */

    public function regions() {
        if ($this->regions !== null) return $this->regions;
        // NOTE: never call get_woocommerce_currency() here — it runs our own currency
        // filter, which calls regions() again (infinite loop = the old 503 error).
        $this->regions = [];
        $raw  = get_option(self::OPT, []);
        $base = (string) get_option('woocommerce_currency', 'USD');
        $clean = [];
        if (is_array($raw)) {
            foreach ($raw as $key => $r) {
                $slug = sanitize_key($key);
                if (!$slug || !is_array($r)) continue;
                $r = wp_parse_args($r, ['name' => ucfirst($slug), 'currency' => $base, 'symbol' => '', 'decimals' => '', 'rate' => '1', 'rounding' => 'none', 'flag' => '', 'logo' => '']);
                $r['currency'] = strtoupper((string) $r['currency']);
                $r['rate'] = ((float) $r['rate'] > 0) ? (string) $r['rate'] : '1';
                if (!isset(self::rounding_options()[$r['rounding']])) $r['rounding'] = 'none';
                $clean[$slug] = $r;
            }
        }
        $this->regions = $clean;
        return $clean;
    }

    private function default_region_key() {
        $regions = $this->regions();
        $d = sanitize_key((string) get_option(self::DEFAULT_OPT, ''));
        if (isset($regions[$d])) return $d;
        return $regions ? (string) array_key_first($regions) : '';
    }

    private function cookie_region_key() {
        if (empty($_COOKIE[self::COOKIE])) return '';
        $k = sanitize_key(wp_unslash($_COOKIE[self::COOKIE]));
        return isset($this->regions()[$k]) ? $k : '';
    }

    public function current_region_key() {
        if ($this->current_key !== null) return $this->current_key;
        $key = $this->cookie_region_key();
        if ($key === '') $key = $this->default_region_key();
        $this->current_key = $key;
        return $key;
    }

    public function current_region() {
        $key = $this->current_region_key();
        return $key !== '' ? ($this->regions()[$key] ?? null) : null;
    }

    /** True for wp-admin screens and admin-originated AJAX (product editor, order editor). */
    private function is_backend() {
        if (!is_admin()) return false;
        if (!wp_doing_ajax()) return true;
        $ref = wp_get_referer();
        return $ref && strpos($ref, admin_url()) === 0;
    }

    /** Region that should affect prices/stock right now ('' = none). */
    private function context_key() {
        if ($this->order_region !== null) return $this->order_region;
        if ($this->is_backend()) return '';
        return $this->current_region_key();
    }

    private function context_region() {
        $k = $this->context_key();
        return $k !== '' ? ($this->regions()[$k] ?? null) : null;
    }

    private function product_region_data($product) {
        $key = $this->context_key();
        if ($key === '' || !$product) return null;
        $id = is_object($product) ? (int) $product->get_id() : (int) $product;
        if (!$id) return null;
        $data = get_post_meta($id, self::META, true);
        if (!is_array($data) || empty($data[$key]) || !is_array($data[$key])) return null;
        return $data[$key];
    }

    /** Old data (before v1.2) had no custom_price flag: a filled price meant "custom". */
    private static function is_custom_row($d) {
        if (!is_array($d)) return false;
        if (array_key_exists('custom_price', $d)) return !empty($d['custom_price']);
        return (string) ($d['regular_price'] ?? '') !== '' || (string) ($d['sale_price'] ?? '') !== '';
    }

    public static function rounding_options() {
        return [
            'none'  => __('No rounding (keep decimals)', 'lm-regional-commerce'),
            'near1' => __('Nearest whole number (132.86 → 133)', 'lm-regional-commerce'),
            'up1'   => __('Round up to whole number (132.10 → 133)', 'lm-regional-commerce'),
            'up5'   => __('Round up to 5 (132.86 → 135)', 'lm-regional-commerce'),
            'up10'  => __('Round up to 10 (132.86 → 140)', 'lm-regional-commerce'),
            'up100' => __('Round up to 100 (2,341 → 2,400)', 'lm-regional-commerce'),
            'end99' => __('End in .99 (132.86 → 132.99)', 'lm-regional-commerce'),
            'end9'  => __('End in 9 (132.86 → 139)', 'lm-regional-commerce'),
        ];
    }

    private static function store_decimals() {
        return (int) get_option('woocommerce_price_num_decimals', 2);
    }

    /** Base (default WooCommerce) price × region multiplier, then rounded. */
    public static function convert_amount($amount, $region) {
        if ($amount === '' || $amount === null || !is_numeric($amount)) return $amount;
        $rate = (float) ($region['rate'] ?? 1);
        if ($rate <= 0) $rate = 1;
        $v = (float) $amount * $rate;
        $dec = ($region['decimals'] ?? '') !== '' ? (int) $region['decimals'] : self::store_decimals();
        $e = 1e-9; // protect against float noise (e.g. 133.0000001)
        switch ($region['rounding'] ?? 'none') {
            case 'near1': $v = round($v); break;
            case 'up1':   $v = ceil($v - $e); break;
            case 'up5':   $v = ceil($v / 5 - $e) * 5; break;
            case 'up10':  $v = ceil($v / 10 - $e) * 10; break;
            case 'up100': $v = ceil($v / 100 - $e) * 100; break;
            case 'end99': $v = ceil($v - $e) - 0.01; if ($v < 0) $v = 0; $dec = max($dec, 2); break;
            case 'end9':  $v = ceil(($v + 1) / 10 - $e) * 10 - 1; if ($v < 0) $v = 0; break;
            default:      $v = round($v, $dec);
        }
        return wc_format_decimal($v, $dec, false);
    }

    private static function region_converts($region) {
        return $region && ((float) $region['rate'] !== 1.0 || $region['rounding'] !== 'none');
    }

    /** Final price for the current region: custom price if ticked, otherwise base × multiplier. */
    private function region_price($product, $field, $base) {
        $region = $this->context_region();
        if (!$region) return $base;
        $custom = $this->regional_prices($product);
        if ($custom) return $custom[$field];
        if (!self::region_converts($region)) return $base;
        return self::convert_amount($base, $region);
    }

    private function regional_prices($product) {
        $d = $this->product_region_data($product);
        if (!$d || !self::is_custom_row($d)) return null;
        $regular = isset($d['regular_price']) ? (string) $d['regular_price'] : '';
        $sale    = isset($d['sale_price']) ? (string) $d['sale_price'] : '';
        if ($regular === '' && $sale === '') return null; // blank = use normal WooCommerce price
        if ($regular === '') $regular = $sale;
        if ($sale !== '' && (float) $sale >= (float) $regular) $sale = '';
        return ['regular' => $regular, 'sale' => $sale, 'price' => $sale !== '' ? $sale : $regular];
    }

    private function regional_stock($product) {
        $d = $this->product_region_data($product);
        if (!$d || empty($d['manage_stock'])) return null;
        return max(0, (int) ($d['stock'] ?? 0));
    }

    private function flag_html($region, $class = 'lmrc-flag') {
        if (!empty($region['flag'])) {
            return '<img class="' . esc_attr($class) . '" src="' . esc_url($region['flag']) . '" alt="" width="40" height="28" loading="lazy">';
        }
        return '<span class="' . esc_attr($class) . ' lmrc-flag--text">' . esc_html(substr($region['currency'], 0, 2)) . '</span>';
    }

    /* ------------------------------------------------------------------ *
     * Region cookie (no-JS fallback: ?lmrc_region=key)
     * ------------------------------------------------------------------ */

    public function maybe_set_region_cookie() {
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) return;
        if (!isset($_GET['lmrc_region'])) return;
        $key = sanitize_key(wp_unslash($_GET['lmrc_region']));
        if (!isset($this->regions()[$key])) return;
        $this->set_region_cookie($key);
        nocache_headers();
        wp_safe_redirect(remove_query_arg('lmrc_region'));
        exit;
    }

    private function set_region_cookie($key) {
        if (!headers_sent()) {
            setcookie(self::COOKIE, $key, [
                'expires'  => time() + YEAR_IN_SECONDS,
                'path'     => COOKIEPATH ?: '/',
                'domain'   => COOKIE_DOMAIN ?: '',
                'secure'   => is_ssl(),
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
        }
        $_COOKIE[self::COOKIE] = $key;
        $this->current_key = null;
    }

    public function cache_vary_cookie($cookies) {
        $cookies = is_array($cookies) ? $cookies : [];
        $cookies[] = self::COOKIE;
        return $cookies;
    }

    /* ------------------------------------------------------------------ *
     * Currency
     * ------------------------------------------------------------------ */

    public function filter_currency($currency) {
        $r = $this->context_region();
        return ($r && !empty($r['currency'])) ? $r['currency'] : $currency;
    }

    public function filter_currency_symbol($symbol, $currency) {
        $r = $this->context_region();
        if ($r && !empty($r['symbol']) && strtoupper((string) $currency) === $r['currency']) return $r['symbol'];
        return $symbol;
    }

    public function filter_price_decimals($decimals) {
        $r = $this->context_region();
        return ($r && $r['decimals'] !== '' && $r['decimals'] !== null) ? (int) $r['decimals'] : $decimals;
    }

    /* ------------------------------------------------------------------ *
     * Prices
     * ------------------------------------------------------------------ */

    public function filter_price($price, $product) {
        return $this->region_price($product, 'price', $price);
    }
    public function filter_regular_price($price, $product) {
        return $this->region_price($product, 'regular', $price);
    }
    public function filter_sale_price($price, $product) {
        // A custom regional price without a sale must not inherit the global sale (handled in regional_prices()).
        return $this->region_price($product, 'sale', $price);
    }
    public function filter_variation_prices_price($price, $variation, $product) {
        return $this->filter_price($price, $variation);
    }
    public function filter_variation_prices_regular($price, $variation, $product) {
        return $this->filter_regular_price($price, $variation);
    }
    public function filter_variation_prices_sale($price, $variation, $product) {
        return $this->filter_sale_price($price, $variation);
    }
    public function variation_prices_hash($hash, $product = null, $for_display = false) {
        $hash = is_array($hash) ? $hash : [$hash];
        $r = $this->context_region();
        $hash[] = 'lmrc:' . $this->context_key() . ':' . ($r ? $r['rate'] . '|' . $r['rounding'] . '|' . $r['decimals'] : '');
        return $hash;
    }

    /* ------------------------------------------------------------------ *
     * Stock
     * ------------------------------------------------------------------ */

    public function filter_stock_quantity($qty, $product) {
        $s = $this->regional_stock($product);
        return $s !== null ? $s : $qty;
    }

    /** Regional stock replaces global stock, so WooCommerce must not check/reduce the global number. */
    public function filter_manage_stock($manage, $product) {
        return $this->regional_stock($product) !== null ? false : $manage;
    }

    public function filter_is_in_stock($in_stock, $product) {
        $s = $this->regional_stock($product);
        return $s !== null ? ($s > 0) : $in_stock;
    }

    public function filter_purchasable($purchasable, $product) {
        $s = $this->regional_stock($product);
        return ($s !== null && $s <= 0) ? false : $purchasable;
    }

    public function filter_availability($availability, $product) {
        $s = $this->regional_stock($product);
        if ($s === null) return $availability;
        $availability['availability'] = $s > 0
            ? sprintf(_n('%s in stock', '%s in stock', $s, 'lm-regional-commerce'), wc_stock_amount($s))
            : __('Out of stock', 'lm-regional-commerce');
        $availability['class'] = $s > 0 ? 'in-stock' : 'out-of-stock';
        return $availability;
    }

    public function filter_quantity_args($args, $product) {
        $s = $this->regional_stock($product);
        if ($s !== null && $s > 0) $args['max_value'] = $s;
        return $args;
    }

    public function filter_available_variation($data, $product, $variation) {
        $s = $this->regional_stock($variation);
        if ($s !== null) {
            $data['max_qty']     = $s > 0 ? $s : '';
            $data['is_in_stock'] = $s > 0;
        }
        return $data;
    }

    private function qty_in_cart($product_id) {
        $qty = 0;
        if (function_exists('WC') && WC()->cart) {
            foreach (WC()->cart->get_cart() as $item) {
                if (!empty($item['data']) && (int) $item['data']->get_id() === (int) $product_id) $qty += (int) $item['quantity'];
            }
        }
        return $qty;
    }

    public function validate_add_to_cart($passed, $product_id, $quantity, $variation_id = 0) {
        $product = wc_get_product($variation_id ?: $product_id);
        $stock = $this->regional_stock($product);
        if ($stock === null) return $passed;
        if ($this->qty_in_cart($product->get_id()) + (int) $quantity > $stock) {
            $r = $this->current_region();
            wc_add_notice(sprintf(
                /* translators: 1: quantity, 2: product name, 3: region */
                __('Sorry, only %1$d of "%2$s" is available in %3$s.', 'lm-regional-commerce'),
                $stock, $product->get_name(), $r ? $r['name'] : ''
            ), 'error');
            return false;
        }
        return $passed;
    }

    public function check_cart_items() {
        if (!WC()->cart) return;
        $r = $this->current_region();
        foreach (WC()->cart->get_cart() as $item) {
            $product = $item['data'] ?? null;
            $stock = $this->regional_stock($product);
            if ($stock === null || (int) $item['quantity'] <= $stock) continue;
            wc_add_notice($stock > 0
                ? sprintf(__('Only %1$d of "%2$s" is available in %3$s. Please update your cart.', 'lm-regional-commerce'), $stock, $product->get_name(), $r ? $r['name'] : '')
                : sprintf(__('"%1$s" is out of stock in %2$s. Please remove it from your cart.', 'lm-regional-commerce'), $product->get_name(), $r ? $r['name'] : ''),
            'error');
        }
    }

    /* ------------------------------------------------------------------ *
     * Admin — product fields
     * ------------------------------------------------------------------ */

    private function product_region_rows($product_id) {
        $data = get_post_meta($product_id, self::META, true);
        $data = is_array($data) ? $data : [];
        $rows = [];
        foreach ($this->regions() as $key => $region) {
            $rows[$key] = wp_parse_args($data[$key] ?? [], [
                'regular_price' => '', 'sale_price' => '', 'price' => '',
                'manage_stock' => 0, 'stock' => 0, // default OFF — otherwise every product becomes "out of stock" on first save
            ]);
            $rows[$key]['custom_price'] = self::is_custom_row($data[$key] ?? []) ? 1 : 0;
        }
        return $rows;
    }

    /** $in must already be unslashed. */
    private function sanitize_row($in) {
        $regular = wc_format_decimal(wc_clean($in['regular_price'] ?? ''));
        $sale    = wc_format_decimal(wc_clean($in['sale_price'] ?? ''));
        if ($sale !== '' && $regular !== '' && (float) $sale >= (float) $regular) $sale = '';
        return [
            'regular_price' => $regular,
            'sale_price'    => $sale,
            'price'         => $sale !== '' ? $sale : $regular,
            'custom_price'  => (($in['custom_price'] ?? '') === 'yes' && ($regular !== '' || $sale !== '')) ? 1 : 0,
            'manage_stock'  => (($in['manage_stock'] ?? '') === 'yes') ? 1 : 0,
            'stock'         => max(0, (int) ($in['stock'] ?? 0)),
        ];
    }

    public function product_tab($tabs) {
        $tabs['lmrc'] = [
            'label'    => __('Regional prices', 'lm-regional-commerce'),
            'target'   => 'lmrc_product_data',
            'class'    => ['show_if_simple'],
            'priority' => 21,
        ];
        return $tabs;
    }

    public function product_panel() {
        global $post;
        if (!$post) return;
        $regions = $this->regions();
        echo '<div id="lmrc_product_data" class="panel woocommerce_options_panel hidden lmrc-panel">';
        if (!$regions) {
            echo '<p class="lmrc-help">' . wp_kses_post(sprintf(__('First add your regions in <a href="%s">WooCommerce → Regions</a>.', 'lm-regional-commerce'), esc_url(admin_url('admin.php?page=lmrc-regions')))) . '</p></div>';
            return;
        }
        echo '<p class="lmrc-help">' . esc_html__('Prices are calculated automatically from the normal price using each region\'s multiplier. Tick "Custom price" only if this product needs a fixed price in a region. Turn on "Manage stock" to give a region its own separate stock.', 'lm-regional-commerce') . '</p>';
        $rows = $this->product_region_rows($post->ID);
        $base_regular = get_post_meta($post->ID, '_regular_price', true);
        $base_sale    = get_post_meta($post->ID, '_sale_price', true);
        foreach ($regions as $key => $region) {
            $r = $rows[$key];
            $cur = $region['symbol'] ?: $region['currency'];
            echo '<div class="lmrc-region-card">';
            echo '<div class="lmrc-region-card__head">' . $this->flag_html($region) . '<span>' . esc_html($region['name']) . '</span><code>' . esc_html($region['currency']) . ' × ' . esc_html($region['rate']) . '</code></div>';
            echo '<p class="form-field lmrc-auto">' . $this->auto_price_text($region, $base_regular, $base_sale) . '</p>';
            woocommerce_wp_checkbox(['id' => "_lmrc_{$key}_custom_price", 'class' => 'checkbox lmrc-custom-input', 'label' => __('Custom price?', 'lm-regional-commerce'), 'description' => __('Use a fixed price for this region instead of the calculated one', 'lm-regional-commerce'), 'value' => $r['custom_price'] ? 'yes' : 'no']);
            woocommerce_wp_text_input(['id' => "_lmrc_{$key}_regular_price", 'label' => sprintf(__('Regular price (%s)', 'lm-regional-commerce'), $cur), 'data_type' => 'price', 'wrapper_class' => 'lmrc-price-field', 'value' => $r['regular_price']]);
            woocommerce_wp_text_input(['id' => "_lmrc_{$key}_sale_price", 'label' => sprintf(__('Sale price (%s)', 'lm-regional-commerce'), $cur), 'data_type' => 'price', 'wrapper_class' => 'lmrc-price-field', 'value' => $r['sale_price']]);
            woocommerce_wp_checkbox(['id' => "_lmrc_{$key}_manage_stock", 'class' => 'checkbox lmrc-manage-input', 'label' => __('Manage stock?', 'lm-regional-commerce'), 'description' => __('Track separate stock for this region', 'lm-regional-commerce'), 'value' => $r['manage_stock'] ? 'yes' : 'no']);
            woocommerce_wp_text_input(['id' => "_lmrc_{$key}_stock", 'label' => __('Stock quantity', 'lm-regional-commerce'), 'type' => 'number', 'wrapper_class' => 'lmrc-stock-field', 'custom_attributes' => ['min' => '0', 'step' => '1'], 'value' => $r['stock']]);
            echo '</div>';
        }
        echo '</div>';
    }

    private function format_region_amount($amount, $region) {
        if ($amount === '' || $amount === null) return '';
        $dec = $region['decimals'] !== '' ? (int) $region['decimals'] : self::store_decimals();
        if ($region['rounding'] === 'end99') $dec = max($dec, 2);
        return esc_html(($region['symbol'] ?: $region['currency']) . ' ' . number_format_i18n((float) $amount, $dec));
    }

    private function auto_price_text($region, $base_regular, $base_sale) {
        $base_cur = esc_html((string) get_option('woocommerce_currency'));
        if ($base_regular === '' || $base_regular === null) {
            return '<span class="lmrc-auto__label">' . esc_html__('Calculated:', 'lm-regional-commerce') . '</span> ' . esc_html__('save the normal regular price first', 'lm-regional-commerce');
        }
        $out = '<span class="lmrc-auto__label">' . esc_html__('Calculated:', 'lm-regional-commerce') . '</span> '
            . $base_cur . ' ' . esc_html(number_format_i18n((float) $base_regular, self::store_decimals())) . ' × ' . esc_html($region['rate'])
            . ' = <strong>' . $this->format_region_amount(self::convert_amount($base_regular, $region), $region) . '</strong>';
        if ($base_sale !== '' && $base_sale !== null) {
            $out .= ' · ' . esc_html__('sale', 'lm-regional-commerce') . ' <strong>' . $this->format_region_amount(self::convert_amount($base_sale, $region), $region) . '</strong>';
        }
        return $out;
    }

    public function save_product_object($product) {
        if ($product->is_type('variable')) return; // variations have their own fields
        $regions = $this->regions();
        if (!$regions) return;
        $data = $product->get_meta(self::META);
        $data = is_array($data) ? $data : [];
        $changed = false;
        foreach ($regions as $key => $region) {
            if (!isset($_POST["_lmrc_{$key}_regular_price"]) && !isset($_POST["_lmrc_{$key}_stock"])) continue;
            $data[$key] = $this->sanitize_row([
                'regular_price' => wp_unslash($_POST["_lmrc_{$key}_regular_price"] ?? ''),
                'sale_price'    => wp_unslash($_POST["_lmrc_{$key}_sale_price"] ?? ''),
                'custom_price'  => isset($_POST["_lmrc_{$key}_custom_price"]) ? 'yes' : '',
                'manage_stock'  => isset($_POST["_lmrc_{$key}_manage_stock"]) ? 'yes' : '',
                'stock'         => wp_unslash($_POST["_lmrc_{$key}_stock"] ?? 0),
            ]);
            $changed = true;
        }
        if ($changed) $product->update_meta_data(self::META, $data);
    }

    public function variation_fields($loop, $variation_data, $variation) {
        $regions = $this->regions();
        if (!$regions) return;
        $rows = $this->product_region_rows($variation->ID);
        $base_regular = get_post_meta($variation->ID, '_regular_price', true);
        $base_sale    = get_post_meta($variation->ID, '_sale_price', true);
        ?>
        <div class="lmrc-var form-row form-row-full">
            <p class="lmrc-var__title"><?php esc_html_e('Regional prices & stock', 'lm-regional-commerce'); ?> <span><?php esc_html_e('(calculated automatically — tick "Custom" for a fixed price)', 'lm-regional-commerce'); ?></span></p>
            <div class="lmrc-var__scroll">
                <table class="lmrc-var__table">
                    <thead><tr>
                        <th><?php esc_html_e('Region', 'lm-regional-commerce'); ?></th>
                        <th><?php esc_html_e('Calculated', 'lm-regional-commerce'); ?></th>
                        <th><?php esc_html_e('Custom', 'lm-regional-commerce'); ?></th>
                        <th><?php esc_html_e('Regular price', 'lm-regional-commerce'); ?></th>
                        <th><?php esc_html_e('Sale price', 'lm-regional-commerce'); ?></th>
                        <th><?php esc_html_e('Manage stock', 'lm-regional-commerce'); ?></th>
                        <th><?php esc_html_e('Stock', 'lm-regional-commerce'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($regions as $key => $region):
                        $r = $rows[$key];
                        $n = '_lmrc_variation[' . (int) $loop . '][' . $key . ']'; ?>
                        <tr>
                            <td class="lmrc-var__region"><?php echo $this->flag_html($region); ?> <span><?php echo esc_html($region['name']); ?><small><?php echo esc_html($region['currency'] . ' × ' . $region['rate']); ?></small></span></td>
                            <td class="lmrc-var__auto"><?php
                                if ($base_regular === '' || $base_regular === null) { echo '—'; }
                                else {
                                    echo $this->format_region_amount(self::convert_amount($base_regular, $region), $region);
                                    if ($base_sale !== '' && $base_sale !== null) echo '<small>' . esc_html__('sale', 'lm-regional-commerce') . ' ' . $this->format_region_amount(self::convert_amount($base_sale, $region), $region) . '</small>';
                                } ?></td>
                            <td class="lmrc-var__check"><input type="checkbox" class="lmrc-custom-input" name="<?php echo esc_attr($n); ?>[custom_price]" value="yes" <?php checked(!empty($r['custom_price'])); ?>></td>
                            <td class="lmrc-price-cell"><input type="text" class="wc_input_price" name="<?php echo esc_attr($n); ?>[regular_price]" value="<?php echo esc_attr(wc_format_localized_price($r['regular_price'])); ?>" placeholder="—"></td>
                            <td class="lmrc-price-cell"><input type="text" class="wc_input_price" name="<?php echo esc_attr($n); ?>[sale_price]" value="<?php echo esc_attr(wc_format_localized_price($r['sale_price'])); ?>" placeholder="—"></td>
                            <td class="lmrc-var__check"><input type="checkbox" class="lmrc-manage-input" name="<?php echo esc_attr($n); ?>[manage_stock]" value="yes" <?php checked(!empty($r['manage_stock'])); ?>></td>
                            <td><input type="number" class="lmrc-stock-input" min="0" step="1" name="<?php echo esc_attr($n); ?>[stock]" value="<?php echo esc_attr((int) $r['stock']); ?>"></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }

    public function save_variation_object($variation, $i) {
        if (!isset($_POST['_lmrc_variation'][$i]) || !is_array($_POST['_lmrc_variation'][$i])) return;
        $posted = wp_unslash($_POST['_lmrc_variation'][$i]);
        $data = $variation->get_meta(self::META);
        $data = is_array($data) ? $data : [];
        foreach ($this->regions() as $key => $region) {
            if (!isset($posted[$key]) || !is_array($posted[$key])) continue;
            $row = $posted[$key];
            $row['manage_stock'] = !empty($row['manage_stock']) ? 'yes' : '';
            $row['custom_price'] = !empty($row['custom_price']) ? 'yes' : '';
            $data[$key] = $this->sanitize_row($row);
        }
        $variation->update_meta_data(self::META, $data);
    }

    /* ------------------------------------------------------------------ *
     * Admin — Regions settings page
     * ------------------------------------------------------------------ */

    public function admin_menu() {
        add_submenu_page('woocommerce', __('Regions', 'lm-regional-commerce'), __('Regions', 'lm-regional-commerce'), 'manage_woocommerce', 'lmrc-regions', [$this, 'regions_page']);
    }

    public function admin_assets($hook) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if ($screen && $screen->id === 'product') {
            wp_register_style('lmrc-product', false, [], LMRC_VERSION);
            wp_enqueue_style('lmrc-product');
            wp_add_inline_style('lmrc-product', self::product_admin_css());
            wp_register_script('lmrc-product', false, ['jquery'], LMRC_VERSION, true);
            wp_enqueue_script('lmrc-product');
            wp_add_inline_script('lmrc-product', self::product_admin_js());
        }

        if ($hook !== 'woocommerce_page_lmrc-regions') return;
        wp_enqueue_media();
        wp_register_style('lmrc-admin', false, [], LMRC_VERSION);
        wp_enqueue_style('lmrc-admin');
        wp_add_inline_style('lmrc-admin', self::settings_admin_css());
        wp_register_script('lmrc-admin', false, ['jquery'], LMRC_VERSION, true);
        wp_enqueue_script('lmrc-admin');
        wp_add_inline_script('lmrc-admin', 'var lmrcAdmin = ' . wp_json_encode([
            'index'    => count($this->regions()),
            'base'     => (string) get_option('woocommerce_currency'),
            'baseDec'  => self::store_decimals(),
            'template' => $this->region_row_template(),
            'i18n'     => [
                'newRegion' => __('New region', 'lm-regional-commerce'),
                'confirm'   => __('Remove this region? Its product prices and stock will be hidden (not deleted) until you add a region with the same key again.', 'lm-regional-commerce'),
            ],
        ]) . ';', 'before');
        wp_add_inline_script('lmrc-admin', self::settings_admin_js());
    }

    public function regions_page() {
        if (!current_user_can('manage_woocommerce')) return;
        $regions = $this->regions();
        $default = $this->default_region_key();
        $base_cur = (string) get_option('woocommerce_currency');
        ?>
        <div class="wrap lmrc-wrap">
            <h1 class="lmrc-title"><?php esc_html_e('Regions & currency', 'lm-regional-commerce'); ?></h1>
            <p class="lmrc-intro"><?php echo esc_html(sprintf(__('Your normal WooCommerce prices are in %s. Each region converts them with a fixed multiplier (no exchange-rate API, so the site stays fast). Change a multiplier and every product price updates at once.', 'lm-regional-commerce'), $base_cur)); ?></p>
            <?php if (!empty($_GET['updated'])): ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Region settings saved.', 'lm-regional-commerce'); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="lmrc-regions-form">
                <input type="hidden" name="action" value="lmrc_save_regions">
                <?php wp_nonce_field('lmrc_save_regions', self::NONCE); ?>

                <div class="lmrc-card">
                    <h2 class="lmrc-card__title"><?php esc_html_e('General', 'lm-regional-commerce'); ?></h2>
                    <div class="lmrc-general">
                        <div class="lmrc-field">
                            <label for="lmrc-default-region"><?php esc_html_e('Default region', 'lm-regional-commerce'); ?></label>
                            <select name="default_region" id="lmrc-default-region">
                                <?php foreach ($regions as $key => $r): ?>
                                    <option value="<?php echo esc_attr($key); ?>" <?php selected($default, $key); ?>><?php echo esc_html($r['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="lmrc-hint"><?php esc_html_e('Used until the visitor picks a region.', 'lm-regional-commerce'); ?></span>
                        </div>
                        <div class="lmrc-checks">
                            <label class="lmrc-check"><input type="checkbox" name="show_modal" value="1" <?php checked(get_option(self::MODAL_OPT, '1'), '1'); ?>> <span><?php esc_html_e('Ask visitors to choose a region on their first visit (popup)', 'lm-regional-commerce'); ?></span></label>
                            <label class="lmrc-check"><input type="checkbox" name="floating_button" value="1" <?php checked(get_option(self::FLOAT_OPT, '1'), '1'); ?>> <span><?php esc_html_e('Show a small floating region button (bottom corner)', 'lm-regional-commerce'); ?></span></label>
                        </div>
                    </div>
                </div>

                <div class="lmrc-section-head">
                    <h2><?php esc_html_e('Your regions', 'lm-regional-commerce'); ?></h2>
                    <div class="lmrc-section-head__actions">
                        <button type="button" class="button" id="lmrc-add-presets"><?php esc_html_e('Add Saudi Arabia, UAE & Pakistan', 'lm-regional-commerce'); ?></button>
                        <button type="button" class="button button-secondary" id="lmrc-add-region">+ <?php esc_html_e('Add region', 'lm-regional-commerce'); ?></button>
                    </div>
                </div>
                <div id="lmrc-regions" class="lmrc-list">
                    <?php $i = 0; foreach ($regions as $key => $r) $this->render_region_row($key, $r, $i++); ?>
                </div>
                <p class="lmrc-empty" <?php echo $regions ? 'hidden' : ''; ?>><?php esc_html_e('No regions yet. Click "Add Saudi Arabia, UAE & Pakistan" to start.', 'lm-regional-commerce'); ?></p>

                <div class="lmrc-savebar"><?php submit_button(__('Save region settings', 'lm-regional-commerce'), 'primary large', 'submit', false); ?></div>
            </form>

            <div class="lmrc-card lmrc-help-box">
                <h2 class="lmrc-card__title"><?php esc_html_e('How it works', 'lm-regional-commerce'); ?></h2>
                <ol>
                    <li><?php echo esc_html(sprintf(__('Region price = normal %s price × multiplier, then rounded. Example: 10,000 × 0.0132864 = 132.86.', 'lm-regional-commerce'), $base_cur)); ?></li>
                    <li><?php esc_html_e('For the default region (same currency as the store) keep the multiplier at 1 and rounding off.', 'lm-regional-commerce'); ?></li>
                    <li><?php esc_html_e('Need a fixed price for one product? Simple products: "Regional prices" tab → tick "Custom price". Variable products: tick "Custom" in the variation\'s regional table.', 'lm-regional-commerce'); ?></li>
                    <li><?php esc_html_e('When "Manage stock" is on for a region, only that region\'s stock is used and reduced.', 'lm-regional-commerce'); ?></li>
                </ol>
                <h3><?php esc_html_e('Showing the region switcher', 'lm-regional-commerce'); ?></h3>
                <table class="lmrc-codes">
                    <tr><td><code>[lmrc_region_switcher]</code></td><td><?php esc_html_e('Flag + name + currency on desktop, only flag on mobile.', 'lm-regional-commerce'); ?></td></tr>
                    <tr><td><code>[lmrc_region_switcher style="flag"]</code></td><td><?php esc_html_e('Always only flag + arrow.', 'lm-regional-commerce'); ?></td></tr>
                    <tr><td><code>[lmrc_region_switcher style="full"]</code></td><td><?php esc_html_e('Always flag + name + currency.', 'lm-regional-commerce'); ?></td></tr>
                    <tr><td><code>#lmrc-region</code></td><td><?php esc_html_e('Menu: Appearance → Menus → Custom Links, URL = #lmrc-region (any link text). It shows the current flag and opens the popup. Any button or link with this URL opens the popup too.', 'lm-regional-commerce'); ?></td></tr>
                    <tr><td><code><?php echo esc_html(home_url('/#lmrc-region')); ?></code></td><td><?php esc_html_e('Direct link that opens the region popup.', 'lm-regional-commerce'); ?></td></tr>
                </table>
            </div>
        </div>
        <?php
    }

    private function region_row_template() {
        ob_start();
        $this->render_region_row('', ['name' => '', 'currency' => '', 'symbol' => '', 'decimals' => '', 'rate' => '1', 'rounding' => 'none', 'flag' => '', 'logo' => ''], '__INDEX__');
        return ob_get_clean();
    }

    private function render_region_row($key, $r, $index) {
        $i = esc_attr((string) $index);
        $base = 'regions[' . $i . ']';
        $base_cur = esc_html((string) get_option('woocommerce_currency'));
        ?>
        <div class="lmrc-region-row">
            <input type="hidden" class="lmrc-key" name="<?php echo $base; ?>[key]" value="<?php echo esc_attr($key); ?>" data-new="<?php echo $key === '' ? '1' : '0'; ?>">
            <input type="hidden" class="lmrc-delete-input" name="<?php echo $base; ?>[delete]" value="0">
            <div class="lmrc-row-head">
                <span class="lmrc-row-flag"><img id="lmrc-flag-<?php echo $i; ?>-preview" src="<?php echo esc_url($r['flag']); ?>" alt="" <?php echo empty($r['flag']) ? 'hidden' : ''; ?>></span>
                <span class="lmrc-row-title"><?php echo esc_html($r['name'] ?: __('New region', 'lm-regional-commerce')); ?></span>
                <code class="lmrc-row-key"><?php echo esc_html($key); ?></code>
                <button type="button" class="button-link button-link-delete lmrc-remove-region"><?php esc_html_e('Remove', 'lm-regional-commerce'); ?></button>
            </div>
            <div class="lmrc-row-body">
                <div class="lmrc-group">
                    <p class="lmrc-group__title"><?php esc_html_e('Region', 'lm-regional-commerce'); ?></p>
                    <div class="lmrc-fields">
                        <label class="lmrc-field lmrc-span-2"><span><?php esc_html_e('Region name', 'lm-regional-commerce'); ?></span><input class="lmrc-region-name" type="text" name="<?php echo $base; ?>[name]" value="<?php echo esc_attr($r['name']); ?>" placeholder="Saudi Arabia" required></label>
                        <label class="lmrc-field"><span><?php esc_html_e('Currency code', 'lm-regional-commerce'); ?></span><input class="lmrc-currency" type="text" maxlength="3" pattern="[A-Za-z]{3}" name="<?php echo $base; ?>[currency]" value="<?php echo esc_attr($r['currency']); ?>" placeholder="SAR" required></label>
                        <label class="lmrc-field"><span><?php esc_html_e('Currency symbol', 'lm-regional-commerce'); ?></span><input class="lmrc-symbol" type="text" name="<?php echo $base; ?>[symbol]" value="<?php echo esc_attr($r['symbol']); ?>" placeholder="ر.س"></label>
                    </div>
                </div>
                <div class="lmrc-group">
                    <p class="lmrc-group__title"><?php esc_html_e('Price conversion', 'lm-regional-commerce'); ?></p>
                    <div class="lmrc-fields">
                        <label class="lmrc-field"><span><?php echo esc_html(sprintf(__('Multiplier (1 %s = ?)', 'lm-regional-commerce'), get_option('woocommerce_currency'))); ?></span><input class="lmrc-rate" type="number" min="0.000001" step="any" name="<?php echo $base; ?>[rate]" value="<?php echo esc_attr($r['rate']); ?>" required></label>
                        <label class="lmrc-field lmrc-span-2"><span><?php esc_html_e('Rounding', 'lm-regional-commerce'); ?></span><select class="lmrc-rounding" name="<?php echo $base; ?>[rounding]">
                            <?php foreach (self::rounding_options() as $val => $label): ?><option value="<?php echo esc_attr($val); ?>" <?php selected($r['rounding'], $val); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
                        </select></label>
                        <label class="lmrc-field"><span><?php esc_html_e('Price decimals', 'lm-regional-commerce'); ?></span><input class="lmrc-decimals" type="number" min="0" max="4" step="1" name="<?php echo $base; ?>[decimals]" value="<?php echo esc_attr($r['decimals']); ?>" placeholder="<?php esc_attr_e('Store default', 'lm-regional-commerce'); ?>"></label>
                    </div>
                    <div class="lmrc-preview"><span class="lmrc-preview__label"><?php esc_html_e('Example', 'lm-regional-commerce'); ?></span> <span class="lmrc-preview__out">—</span></div>
                </div>
                <div class="lmrc-group">
                    <p class="lmrc-group__title"><?php esc_html_e('Images', 'lm-regional-commerce'); ?></p>
                    <div class="lmrc-fields">
                        <div class="lmrc-field lmrc-span-2"><label for="lmrc-flag-<?php echo $i; ?>"><?php esc_html_e('Flag image', 'lm-regional-commerce'); ?></label>
                            <span class="lmrc-media"><input class="lmrc-img-url lmrc-flag-url" id="lmrc-flag-<?php echo $i; ?>" type="url" name="<?php echo $base; ?>[flag]" value="<?php echo esc_url($r['flag']); ?>" placeholder="https://"><button type="button" class="button lmrc-media-button" data-target="lmrc-flag-<?php echo $i; ?>"><?php esc_html_e('Choose', 'lm-regional-commerce'); ?></button></span></div>
                        <div class="lmrc-field lmrc-span-2"><label for="lmrc-logo-<?php echo $i; ?>"><?php esc_html_e('Region logo (optional — replaces site logo)', 'lm-regional-commerce'); ?></label>
                            <span class="lmrc-media"><img class="lmrc-thumb" id="lmrc-logo-<?php echo $i; ?>-preview" src="<?php echo esc_url($r['logo']); ?>" alt="" <?php echo empty($r['logo']) ? 'hidden' : ''; ?>><input class="lmrc-img-url" id="lmrc-logo-<?php echo $i; ?>" type="url" name="<?php echo $base; ?>[logo]" value="<?php echo esc_url($r['logo']); ?>" placeholder="https://"><button type="button" class="button lmrc-media-button" data-target="lmrc-logo-<?php echo $i; ?>"><?php esc_html_e('Choose', 'lm-regional-commerce'); ?></button></span></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function save_regions() {
        if (!current_user_can('manage_woocommerce')) wp_die(esc_html__('Permission denied', 'lm-regional-commerce'));
        check_admin_referer('lmrc_save_regions', self::NONCE);
        $input = (isset($_POST['regions']) && is_array($_POST['regions'])) ? wp_unslash($_POST['regions']) : [];
        $regions = [];
        foreach ($input as $row) {
            if (!is_array($row) || (isset($row['delete']) && $row['delete'] === '1')) continue;
            $name = sanitize_text_field($row['name'] ?? '');
            $currency = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) ($row['currency'] ?? '')));
            if ($name === '' || strlen($currency) !== 3) continue;
            $key = sanitize_key($row['key'] ?? '');
            if ($key === '') $key = sanitize_key(sanitize_title($name));
            if ($key === '') continue;
            $base = $key; $n = 2;
            while (isset($regions[$key])) $key = $base . '-' . $n++;
            $dec = trim((string) ($row['decimals'] ?? ''));
            $regions[$key] = [
                'name'     => $name,
                'currency' => $currency,
                'symbol'   => sanitize_text_field($row['symbol'] ?? ''),
                'decimals' => $dec === '' ? '' : (string) min(4, max(0, (int) $dec)),
                'rate'     => ((float) ($row['rate'] ?? 0) > 0) ? (string) (float) wc_format_decimal($row['rate'] ?? '1') : '1',
                'rounding' => isset(self::rounding_options()[$row['rounding'] ?? '']) ? $row['rounding'] : 'none',
                'flag'     => esc_url_raw($row['flag'] ?? ''),
                'logo'     => esc_url_raw($row['logo'] ?? ''),
            ];
        }
        update_option(self::OPT, $regions, true);
        $default = sanitize_key(wp_unslash($_POST['default_region'] ?? ''));
        if (!isset($regions[$default])) $default = $regions ? (string) array_key_first($regions) : '';
        update_option(self::DEFAULT_OPT, $default, true);
        update_option(self::MODAL_OPT, empty($_POST['show_modal']) ? '0' : '1', true);
        update_option(self::FLOAT_OPT, empty($_POST['floating_button']) ? '0' : '1', true);
        $this->regions = null;
        wp_safe_redirect(add_query_arg(['page' => 'lmrc-regions', 'updated' => '1'], admin_url('admin.php')));
        exit;
    }

    /* ------------------------------------------------------------------ *
     * Frontend UI
     * ------------------------------------------------------------------ */

    public function frontend_assets() {
        $regions = $this->regions();
        if (!$regions) return;
        wp_register_style('lmrc-frontend', false, [], LMRC_VERSION);
        wp_enqueue_style('lmrc-frontend');
        wp_add_inline_style('lmrc-frontend', self::frontend_css());

        $public = [];
        foreach ($regions as $k => $r) {
            $public[$k] = ['name' => $r['name'], 'currency' => $r['currency'], 'symbol' => $r['symbol'], 'flag' => $r['flag']];
        }
        wp_register_script('lmrc-frontend', false, [], LMRC_VERSION, true);
        wp_enqueue_script('lmrc-frontend');
        wp_add_inline_script('lmrc-frontend', 'var lmrcData = ' . wp_json_encode([
            'regions'   => $public,
            'cookie'    => self::COOKIE,
            'path'      => COOKIEPATH ?: '/',
            'domain'    => COOKIE_DOMAIN ?: '',
            'def'       => $this->default_region_key(),
            'showModal' => get_option(self::MODAL_OPT, '1') === '1' && count($regions) > 1,
        ]) . ';', 'before');
        wp_add_inline_script('lmrc-frontend', self::frontend_js());
    }

    private function switcher_inner_html($r) {
        return '<span class="lmrc-switcher__flag" data-lmrc-cur-flag>' . $this->flag_html($r) . '</span>'
            . '<span class="lmrc-switcher__name" data-lmrc-cur-name>' . esc_html($r['name']) . '</span>'
            . '<span class="lmrc-switcher__cur" data-lmrc-cur-currency>' . esc_html($r['currency']) . '</span>'
            . '<svg class="lmrc-switcher__arrow" width="10" height="10" viewBox="0 0 10 10" aria-hidden="true" focusable="false"><path d="M1.5 3.5L5 7l3.5-3.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    }

    private function current_button_html($extra_class = '') {
        $r = $this->current_region();
        if (!$r) return '';
        return '<button type="button" class="lmrc-switcher ' . esc_attr($extra_class) . '" data-lmrc-open aria-haspopup="dialog" aria-label="' . esc_attr__('Change shopping region', 'lm-regional-commerce') . '">'
            . $this->switcher_inner_html($r) . '</button>';
    }

    /** [lmrc_region_switcher style="auto|flag|full"] — auto = full on desktop, flag only on mobile. */
    public function switcher_shortcode($atts = []) {
        if (count($this->regions()) < 2) return '';
        $atts = shortcode_atts(['style' => 'auto'], $atts, 'lmrc_region_switcher');
        $style = in_array($atts['style'], ['flag', 'full'], true) ? $atts['style'] : 'auto';
        return $this->current_button_html('lmrc-switcher--' . $style);
    }

    public function render_floating_button() {
        if (is_admin() || count($this->regions()) < 2 || get_option(self::FLOAT_OPT, '1') !== '1') return;
        echo $this->current_button_html('lmrc-switcher--floating lmrc-switcher--auto');
    }

    private static function is_region_menu_item($item) {
        return is_object($item) && !empty($item->url) && substr((string) $item->url, -strlen('#lmrc-region')) === '#lmrc-region';
    }

    /** Menu item with URL "#lmrc-region" shows the current flag and opens the popup. */
    public function menu_item_title($title, $item, $args = null, $depth = 0) {
        if (!self::is_region_menu_item($item) || count($this->regions()) < 2) return $title;
        $r = $this->current_region();
        return $r ? '<span class="lmrc-menu-link">' . $this->switcher_inner_html($r) . '</span>' : $title;
    }

    public function menu_item_classes($classes, $item) {
        if (self::is_region_menu_item($item)) $classes[] = 'lmrc-menu-item';
        return $classes;
    }

    public function menu_item_atts($atts, $item) {
        if (self::is_region_menu_item($item)) {
            $atts['data-lmrc-open'] = '';
            $atts['aria-haspopup'] = 'dialog';
            $atts['aria-label'] = __('Change shopping region', 'lm-regional-commerce');
            $atts['rel'] = 'nofollow';
        }
        return $atts;
    }

    public function render_modal() {
        $regions = $this->regions();
        if (is_admin() || count($regions) < 2) return;
        // Always printed hidden; JS decides whether to show it (works with page caching).
        ?>
        <div class="lmrc-modal" id="lmrc-modal" hidden>
            <div class="lmrc-modal__backdrop" data-lmrc-close></div>
            <div class="lmrc-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="lmrc-modal-title">
                <div class="lmrc-modal__top">
                    <button type="button" class="lmrc-modal__close" data-lmrc-close aria-label="<?php esc_attr_e('Close', 'lm-regional-commerce'); ?>"><svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true" focusable="false"><path d="M1 1l12 12M13 1L1 13" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></button>
                </div>
                <h2 class="lmrc-modal__title" id="lmrc-modal-title"><?php esc_html_e('Where are you shopping from?', 'lm-regional-commerce'); ?></h2>
                <p class="lmrc-modal__text"><?php esc_html_e('Choose your region to see local prices and availability.', 'lm-regional-commerce'); ?></p>
                <div class="lmrc-modal__grid">
                    <?php foreach ($regions as $key => $r): ?>
                        <a class="lmrc-choice" href="<?php echo esc_url(add_query_arg('lmrc_region', $key)); ?>" data-lmrc-region="<?php echo esc_attr($key); ?>" rel="nofollow">
                            <span class="lmrc-choice__flag"><?php echo $this->flag_html($r); ?></span>
                            <span class="lmrc-choice__name"><?php echo esc_html($r['name']); ?></span>
                            <span class="lmrc-choice__cur"><?php echo esc_html($r['currency'] . ($r['symbol'] ? ' · ' . $r['symbol'] : '')); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    public function filter_custom_logo($html) {
        if (is_admin()) return $html;
        $r = $this->current_region();
        if (!$r || empty($r['logo'])) return $html;
        return sprintf('<a href="%s" class="custom-logo-link" rel="home"><img src="%s" class="custom-logo" alt="%s"></a>',
            esc_url(home_url('/')), esc_url($r['logo']), esc_attr(get_bloginfo('name')));
    }

    /* ------------------------------------------------------------------ *
     * Orders
     * ------------------------------------------------------------------ */

    public function save_order_region($order) {
        if (!$order instanceof WC_Order) return;
        $key = $this->current_region_key();
        $r = $this->current_region();
        if ($key === '' || !$r) return;
        $order->update_meta_data('_lmrc_region', $key);
        $order->update_meta_data('_lmrc_region_name', $r['name']);
        $order->update_meta_data('_lmrc_currency', $r['currency']);
    }

    public function admin_order_region($order) {
        $name = $order->get_meta('_lmrc_region_name');
        if (!$name) return;
        echo '<p class="form-field form-field-wide"><strong>' . esc_html__('Shopping region:', 'lm-regional-commerce') . '</strong> ' . esc_html($name . ' (' . $order->get_meta('_lmrc_currency') . ')') . '</p>';
    }

    private function order_region_key($order) {
        return sanitize_key((string) $order->get_meta('_lmrc_region'));
    }

    public function enter_order_context($can, $order) {
        if ($can && $order instanceof WC_Order) {
            $k = $this->order_region_key($order);
            $this->order_region = $k !== '' ? $k : null;
        }
        return $can;
    }

    public function leave_order_context() {
        $this->order_region = null;
    }

    private function change_order_regional_stock($order_id, $direction) {
        $order = $order_id instanceof WC_Order ? $order_id : wc_get_order($order_id);
        if (!$order) return;
        $region = $this->order_region_key($order);
        if ($region === '') return;
        $processed = (bool) $order->get_meta('_lmrc_stock_processed');
        if ($direction === 'reduce' && $processed) return;
        if ($direction === 'restore' && !$processed) return;

        $notes = [];
        foreach ($order->get_items() as $item) {
            if (!$item instanceof WC_Order_Item_Product) continue;
            $product = $item->get_product();
            if (!$product) continue;
            $pid  = $product->get_id();
            $data = get_post_meta($pid, self::META, true);
            if (!is_array($data) || empty($data[$region]['manage_stock'])) continue;
            $qty = (int) $item->get_quantity();
            $old = max(0, (int) ($data[$region]['stock'] ?? 0));
            $new = $direction === 'reduce' ? max(0, $old - $qty) : $old + $qty;
            $data[$region]['stock'] = $new;
            update_post_meta($pid, self::META, $data);
            wc_delete_product_transients($pid);
            if ($product->get_parent_id()) wc_delete_product_transients($product->get_parent_id());
            $notes[] = sprintf('%s %d→%d', $product->get_name(), $old, $new);
        }

        $order->update_meta_data('_lmrc_stock_processed', $direction === 'reduce' ? 1 : 0);
        $order->save_meta_data();
        if ($notes) {
            $rname = $order->get_meta('_lmrc_region_name') ?: $region;
            $order->add_order_note(sprintf(
                $direction === 'reduce' ? __('Regional stock reduced (%1$s): %2$s', 'lm-regional-commerce') : __('Regional stock restored (%1$s): %2$s', 'lm-regional-commerce'),
                $rname, implode(', ', $notes)
            ));
        }
    }

    public function reduce_regional_stock($order_id) { $this->change_order_regional_stock($order_id, 'reduce'); }
    public function restore_regional_stock($order_id) { $this->change_order_regional_stock($order_id, 'restore'); }

    /* ------------------------------------------------------------------ *
     * Inline assets
     * ------------------------------------------------------------------ */

    private static function frontend_css() {
        // "html body" prefix = higher specificity than typical theme button/link styles.
        return <<<'CSS'
html body .lmrc-switcher{all:unset;box-sizing:border-box;display:inline-flex;align-items:center;gap:7px;height:36px;padding:0 12px;border:1px solid rgba(0,0,0,.14);border-radius:999px;background:#fff;color:#1d2327;font-family:inherit;font-size:14px;font-weight:500;line-height:1;white-space:nowrap;cursor:pointer;vertical-align:middle;transition:border-color .2s,box-shadow .2s}
html body .lmrc-switcher:hover{border-color:rgba(0,0,0,.35);box-shadow:0 2px 8px rgba(0,0,0,.08)}
html body .lmrc-switcher:focus-visible{outline:2px solid #2271b1;outline-offset:2px}
html body .lmrc-switcher__flag,html body .lmrc-menu-link .lmrc-switcher__flag{display:inline-flex;line-height:0}
html body .lmrc-switcher__cur{opacity:.6;font-size:12px}
html body .lmrc-switcher__arrow{flex:none;opacity:.7}
html body .lmrc-switcher--flag{gap:6px;padding:0 10px}
html body .lmrc-switcher--flag .lmrc-switcher__name,html body .lmrc-switcher--flag .lmrc-switcher__cur{display:none}
html body .lmrc-switcher--floating{position:fixed;left:16px;bottom:16px;z-index:99990;box-shadow:0 4px 18px rgba(0,0,0,.15)}
html body .lmrc-menu-link{display:inline-flex;align-items:center;gap:6px;line-height:1;white-space:nowrap}
html body .lmrc-menu-link .lmrc-switcher__cur{display:none}
html body .lmrc-flag{display:inline-block;width:22px;height:16px;object-fit:cover;border-radius:3px;box-shadow:0 0 0 1px rgba(0,0,0,.08);vertical-align:middle;margin:0;max-width:none}
html body .lmrc-flag--text{display:inline-flex;align-items:center;justify-content:center;background:#f0f0f1;color:#50575e;font-size:9px;font-weight:700;letter-spacing:.5px}
@media(max-width:768px){
  html body .lmrc-switcher--auto{gap:6px;padding:0 10px}
  html body .lmrc-switcher--auto .lmrc-switcher__name,html body .lmrc-switcher--auto .lmrc-switcher__cur,html body .lmrc-menu-link .lmrc-switcher__name{display:none}
}
html body .lmrc-modal[hidden]{display:none!important}
html body .lmrc-modal{position:fixed;inset:0;z-index:999999;display:flex;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;margin:0}
html body .lmrc-modal *{box-sizing:border-box}
html body .lmrc-modal__backdrop{position:absolute;inset:0;background:rgba(15,18,22,.6);-webkit-backdrop-filter:blur(3px);backdrop-filter:blur(3px)}
html body .lmrc-modal__dialog{position:relative;width:100%;max-width:560px;max-height:calc(100vh - 32px);overflow:auto;background:#fff;color:#1d2327;border-radius:18px;padding:14px 28px 28px;text-align:center;box-shadow:0 24px 70px rgba(0,0,0,.25);animation:lmrcIn .25s ease-out}
html body .lmrc-modal__top{display:flex;justify-content:flex-end;height:34px;margin:0 -14px 2px 0}
html body .lmrc-modal:not(.is-dismissible) .lmrc-modal__top{visibility:hidden}
html body .lmrc-modal__close{all:unset;box-sizing:border-box;display:flex;align-items:center;justify-content:center;flex:none;width:34px;height:34px;border-radius:50%;background:#f0f0f1;color:#50575e;cursor:pointer;transition:background .15s,color .15s}
html body .lmrc-modal__close:hover{background:#dcdcde;color:#1d2327}
html body .lmrc-modal__close:focus-visible{outline:2px solid #2271b1;outline-offset:2px}
html body .lmrc-modal__title{margin:0 0 6px;padding:0;font-size:22px;line-height:1.3;color:inherit;text-align:center}
html body .lmrc-modal__text{margin:0;color:#646970;font-size:15px;text-align:center}
html body .lmrc-modal__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-top:24px}
html body .lmrc-choice{display:flex;flex-direction:column;align-items:center;gap:8px;padding:20px 10px 16px;border:2px solid #e6e6e6;border-radius:14px;color:inherit;text-decoration:none;background:#fff;transition:transform .15s,border-color .15s,box-shadow .15s}
html body .lmrc-choice:hover,html body .lmrc-choice:focus-visible{border-color:#1d2327;transform:translateY(-2px);box-shadow:0 8px 22px rgba(0,0,0,.08);outline:none;color:inherit}
html body .lmrc-choice.is-current{border-color:#1d2327}
html body .lmrc-choice__flag{line-height:0}
html body .lmrc-choice__flag .lmrc-flag{width:56px;height:38px;border-radius:5px}
html body .lmrc-choice__flag .lmrc-flag--text{font-size:14px}
html body .lmrc-choice__name{font-weight:600;font-size:15px}
html body .lmrc-choice__cur{font-size:13px;color:#646970}
html.lmrc-lock,html.lmrc-lock body{overflow:hidden}
@keyframes lmrcIn{from{opacity:0;transform:translateY(12px) scale(.98)}to{opacity:1;transform:none}}
@media(max-width:480px){
  html body .lmrc-modal__dialog{padding:12px 18px 18px}
  html body .lmrc-modal__top{margin-right:-6px}
  html body .lmrc-modal__grid{grid-template-columns:1fr}
  html body .lmrc-choice{flex-direction:row;padding:14px 16px;text-align:left}
  html body .lmrc-choice__flag .lmrc-flag{width:40px;height:28px}
  html body .lmrc-choice__cur{margin-left:auto}
}
CSS;
    }

    private static function frontend_js() {
        return <<<'JS'
(function(){
  var d = window.lmrcData; if (!d) return;
  var modal = document.getElementById('lmrc-modal');
  function valid(k){ return !!k && Object.prototype.hasOwnProperty.call(d.regions, k); }
  function getCookie(){ var m = document.cookie.match(new RegExp('(?:^|; )' + d.cookie + '=([^;]*)')); return m ? decodeURIComponent(m[1]) : ''; }
  function esc(s){ return String(s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function flag(r){ return r.flag ? '<img class="lmrc-flag" src="' + esc(r.flag) + '" alt="" width="40" height="28">' : '<span class="lmrc-flag lmrc-flag--text">' + esc(r.currency.slice(0,2)) + '</span>'; }

  function setRegion(k, href){
    if (!valid(k)) return;
    var c = d.cookie + '=' + encodeURIComponent(k) + '; max-age=31536000; path=' + d.path + '; SameSite=Lax';
    if (d.domain) c += '; domain=' + d.domain;
    if (location.protocol === 'https:') c += '; Secure';
    document.cookie = c;
    // Clear cached mini-cart fragments so prices/currency refresh.
    try { for (var i = sessionStorage.length - 1; i >= 0; i--) { var s = sessionStorage.key(i); if (s && s.indexOf('wc_') === 0) sessionStorage.removeItem(s); } } catch(e){}
    if (getCookie() !== k) { if (href) location.href = href; return; } // cookie blocked: let the server try
    var u = new URL(location.href); u.searchParams.delete('lmrc_region'); u.hash = '';
    if (u.toString() === location.href) location.reload(); else location.replace(u.toString());
  }

  function open(dismissible){
    if (!modal) return;
    var cur = getCookie();
    modal.querySelectorAll('[data-lmrc-region]').forEach(function(a){ a.classList.toggle('is-current', a.getAttribute('data-lmrc-region') === cur); });
    modal.classList.toggle('is-dismissible', !!dismissible);
    modal.hidden = false;
    document.documentElement.classList.add('lmrc-lock');
    var first = modal.querySelector('.lmrc-choice.is-current') || modal.querySelector('.lmrc-choice');
    if (first) first.focus();
  }
  function close(){
    if (!modal || !modal.classList.contains('is-dismissible')) return;
    modal.hidden = true;
    document.documentElement.classList.remove('lmrc-lock');
  }

  document.addEventListener('click', function(e){
    var t = e.target;
    var choice = t.closest && t.closest('[data-lmrc-region]');
    if (choice){ e.preventDefault(); setRegion(choice.getAttribute('data-lmrc-region'), choice.href); return; }
    if (t.closest && t.closest('[data-lmrc-open], a[href$="#lmrc-region"]')){ e.preventDefault(); open(true); return; }
    if (t.closest && t.closest('[data-lmrc-close]')){ close(); }
  });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape') close(); });

  // Update switcher labels from the cookie (correct even on cached pages).
  var cur = getCookie(), key = valid(cur) ? cur : d.def;
  if (valid(key)) {
    var r = d.regions[key];
    document.querySelectorAll('[data-lmrc-cur-flag]').forEach(function(el){ el.innerHTML = flag(r); });
    document.querySelectorAll('[data-lmrc-cur-name]').forEach(function(el){ el.textContent = r.name; });
    document.querySelectorAll('[data-lmrc-cur-currency]').forEach(function(el){ el.textContent = r.currency; });
  }
  if (location.hash === '#lmrc-region') {
    try { history.replaceState(null, '', location.pathname + location.search); } catch(e){}
    open(valid(cur) || !d.showModal);
  } else if (!valid(cur) && d.showModal) open(false);
})();
JS;
    }

    private static function product_admin_css() {
        return <<<'CSS'
#woocommerce-product-data ul.wc-tabs li.lmrc_options a::before{content:"\f319"}
.lmrc-panel .lmrc-help{margin:12px!important;padding:10px 12px!important;background:#f0f6fc;border-left:4px solid #2271b1;color:#1d2327}
.lmrc-region-card{margin:12px;border:1px solid #dcdcde;border-radius:6px;overflow:hidden;background:#fff}
.lmrc-region-card__head{display:flex;align-items:center;gap:8px;padding:9px 12px;background:#f6f7f7;border-bottom:1px solid #dcdcde;font-weight:600}
.lmrc-region-card__head code{margin-left:auto;font-size:11px}
.lmrc-flag{display:inline-block;width:24px;height:17px;object-fit:cover;border-radius:2px;box-shadow:0 0 0 1px rgba(0,0,0,.1);vertical-align:middle}
.lmrc-flag--text{display:inline-flex;align-items:center;justify-content:center;background:#e0e0e0;font-size:9px;font-weight:700}
.lmrc-muted{opacity:.4}
.lmrc-panel .lmrc-auto{padding:4px 12px 0 162px!important;margin:8px 0 0!important;color:#3c434a}
.lmrc-auto__label{color:#787c82}
.lmrc-hide{display:none!important}
.lmrc-var__auto{white-space:nowrap;font-weight:600}
.lmrc-var__auto small{display:block;font-weight:400;color:#787c82}
.lmrc-price-cell.lmrc-muted input{pointer-events:none}
@media(max-width:782px){.lmrc-panel .lmrc-auto{padding-left:12px!important}}
.lmrc-var{border-top:1px solid #eee;margin-top:10px!important;padding-top:10px!important}
.lmrc-var__title{font-weight:600;margin:0 0 8px!important}
.lmrc-var__title span{font-weight:400;color:#787c82}
.lmrc-var__scroll{overflow-x:auto}
.lmrc-var__table{width:100%;border-collapse:collapse;min-width:760px}
.lmrc-var__table th{font-size:12px;text-align:left;color:#50575e;font-weight:600;padding:6px;border-bottom:1px solid #dcdcde}
.lmrc-var__table td{padding:6px;border-bottom:1px solid #f0f0f1;vertical-align:middle}
.lmrc-var__table input[type=text],.lmrc-var__table input[type=number]{width:100%!important;min-width:80px;margin:0!important}
.lmrc-var__region{white-space:nowrap}
.lmrc-var__region span{display:inline-flex;flex-direction:column;vertical-align:middle;margin-left:6px;line-height:1.2}
.lmrc-var__region small{color:#787c82}
.lmrc-var__check{text-align:center}
CSS;
    }

    private static function product_admin_js() {
        return <<<'JS'
jQuery(function($){
  function sync(cb){
    var $cb = $(cb), $scope = $cb.closest('.lmrc-region-card, tr');
    $scope.find('.lmrc-stock-field, .lmrc-stock-input').toggleClass('lmrc-muted', !$cb.is(':checked'));
  }
  function syncCustom(cb){
    var $cb = $(cb), on = $cb.is(':checked');
    if ($cb.closest('tr').length) $cb.closest('tr').find('.lmrc-price-cell').toggleClass('lmrc-muted', !on);
    else $cb.closest('.lmrc-region-card').find('.lmrc-price-field').toggleClass('lmrc-hide', !on);
  }
  function all(){
    $('.lmrc-manage-input').each(function(){ sync(this); });
    $('.lmrc-custom-input').each(function(){ syncCustom(this); });
  }
  $(document).on('change', '.lmrc-manage-input', function(){ sync(this); });
  $(document).on('change', '.lmrc-custom-input', function(){ syncCustom(this); });
  $(document).on('focus', '.lmrc-price-cell.lmrc-muted input', function(){
    $(this).closest('tr').find('.lmrc-custom-input').prop('checked', true).trigger('change');
  });
  all();
  $('#woocommerce-product-data').on('woocommerce_variations_loaded woocommerce_variations_added', all);
});
JS;
    }

    private static function settings_admin_css() {
        return <<<'CSS'
.lmrc-wrap{max-width:1080px}
.lmrc-wrap *,.lmrc-wrap *::before,.lmrc-wrap *::after{box-sizing:border-box}
.lmrc-wrap .lmrc-title{margin-bottom:4px}
.lmrc-intro{color:#50575e;font-size:14px;max-width:780px;margin:0 0 8px}
.lmrc-card{background:#fff;border:1px solid #c3c4c7;border-radius:8px;padding:18px 20px;margin:16px 0;box-shadow:0 1px 1px rgba(0,0,0,.04)}
.lmrc-wrap .lmrc-card__title{font-size:15px;margin:0 0 14px;padding:0}
.lmrc-general{display:grid;grid-template-columns:minmax(0,280px) minmax(0,1fr);gap:16px 32px;align-items:start}
.lmrc-checks{display:flex;flex-direction:column;gap:10px;padding-top:22px}
.lmrc-check{display:flex;align-items:flex-start;gap:8px;font-size:13px;line-height:1.5;cursor:pointer}
.lmrc-check input{margin:2px 0 0!important;flex:none}
.lmrc-hint{font-size:12px;font-weight:400;color:#787c82}
.lmrc-section-head{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin:28px 0 12px}
.lmrc-wrap .lmrc-section-head h2{margin:0;padding:0;font-size:18px}
.lmrc-section-head__actions{display:flex;gap:8px;flex-wrap:wrap}
.lmrc-list{display:flex;flex-direction:column;gap:16px}
.lmrc-region-row{background:#fff;border:1px solid #c3c4c7;border-radius:8px;box-shadow:0 1px 1px rgba(0,0,0,.04);overflow:hidden}
.lmrc-row-head{display:flex;align-items:center;gap:12px;padding:12px 18px;background:#f6f7f7;border-bottom:1px solid #dcdcde}
.lmrc-row-flag{flex:none;width:36px;height:24px;border-radius:3px;background:#dcdcde;overflow:hidden;box-shadow:inset 0 0 0 1px rgba(0,0,0,.08)}
.lmrc-row-flag img{display:block;width:100%;height:100%;object-fit:cover}
.lmrc-row-flag img[hidden]{display:none}
.lmrc-row-title{font-size:15px;font-weight:600;color:#1d2327;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.lmrc-row-key{font-size:11px;padding:2px 6px;background:#fff;border:1px solid #dcdcde;border-radius:3px}
.lmrc-row-key:empty{display:none}
.lmrc-wrap .lmrc-remove-region{margin-left:auto;text-decoration:none;flex:none}
.lmrc-row-body{padding:4px 18px 18px}
.lmrc-group{padding-top:14px}
.lmrc-group+.lmrc-group{margin-top:14px;border-top:1px solid #f0f0f1}
.lmrc-wrap .lmrc-group__title{margin:0 0 10px;font-size:11px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#787c82}
.lmrc-fields{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px 16px}
.lmrc-field{display:flex;flex-direction:column;gap:6px;min-width:0;margin:0}
.lmrc-field>span:first-child,.lmrc-field>label{font-size:12px;font-weight:600;color:#3c434a;line-height:1.3}
.lmrc-wrap .lmrc-field input[type=text],.lmrc-wrap .lmrc-field input[type=url],.lmrc-wrap .lmrc-field input[type=number],.lmrc-wrap .lmrc-field select{width:100%;max-width:100%;min-width:0;min-height:36px;margin:0;font-weight:400}
.lmrc-wrap .lmrc-general select{width:100%;max-width:100%;min-height:36px}
.lmrc-span-2{grid-column:span 2}
.lmrc-preview{margin-top:12px;padding:9px 12px;background:#f0f6fc;border:1px solid #c5d9ed;border-radius:6px;font-size:13px;color:#1d2327;overflow-wrap:anywhere}
.lmrc-preview__label{display:inline-block;margin-right:6px;font-size:11px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:#2271b1}
.lmrc-preview__out{font-weight:600;font-variant-numeric:tabular-nums}
.lmrc-media{display:flex;align-items:center;gap:6px;min-width:0}
.lmrc-wrap .lmrc-media input{flex:1 1 auto;width:auto!important}
.lmrc-wrap .lmrc-media .button{flex:none;min-height:36px;line-height:34px;margin:0}
.lmrc-thumb{flex:none;width:36px;height:36px;object-fit:contain;border:1px solid #dcdcde;border-radius:4px;background:#f6f7f7}
.lmrc-thumb[hidden]{display:none}
.lmrc-empty{padding:28px;background:#fff;border:1px dashed #c3c4c7;border-radius:8px;text-align:center;color:#646970;margin:0}
.lmrc-empty[hidden]{display:none}
.lmrc-savebar{position:sticky;bottom:0;z-index:5;margin-top:16px;padding:12px 0;background:linear-gradient(to top,#f0f0f1 75%,rgba(240,240,241,0))}
.lmrc-help-box ol{margin:0 0 8px 18px}
.lmrc-help-box li{margin-bottom:6px}
.lmrc-wrap .lmrc-help-box h3{font-size:13px;margin:16px 0 8px}
.lmrc-codes{border-collapse:collapse;width:100%}
.lmrc-codes td{padding:7px 10px 7px 0;border-top:1px solid #f0f0f1;vertical-align:top;font-size:13px}
.lmrc-codes td:first-child{white-space:nowrap;width:1%}
@media(max-width:960px){.lmrc-fields{grid-template-columns:repeat(2,minmax(0,1fr))}.lmrc-general{grid-template-columns:1fr}.lmrc-checks{padding-top:0}}
@media(max-width:600px){.lmrc-fields{grid-template-columns:1fr}.lmrc-span-2{grid-column:auto}.lmrc-codes td:first-child{white-space:normal}.lmrc-row-head{padding:10px 12px}.lmrc-row-body{padding:4px 12px 14px}}
CSS;
    }

    private static function settings_admin_js() {
        return <<<'JS'
jQuery(function($){
  var A = window.lmrcAdmin, index = A.index;
  var presets = [
    {name:'Pakistan', key:'pakistan', currency:'PKR', symbol:'Rs', decimals:'0', rate:'1', rounding:'none', flag:'https://flagcdn.com/w160/pk.png'},
    {name:'Saudi Arabia', key:'saudi-arabia', currency:'SAR', symbol:'ر.س', decimals:'2', rate:'0.0135791', rounding:'none', flag:'https://flagcdn.com/w160/sa.png'},
    {name:'United Arab Emirates', key:'uae', currency:'AED', symbol:'د.إ', decimals:'2', rate:'0.0132864', rounding:'none', flag:'https://flagcdn.com/w160/ae.png'}
  ];
  function convert(amount, rate, rounding, dec){
    var v = amount * rate, e = 1e-9;
    switch (rounding){
      case 'near1': v = Math.round(v); break;
      case 'up1':   v = Math.ceil(v - e); break;
      case 'up5':   v = Math.ceil(v/5 - e) * 5; break;
      case 'up10':  v = Math.ceil(v/10 - e) * 10; break;
      case 'up100': v = Math.ceil(v/100 - e) * 100; break;
      case 'end99': v = Math.max(0, Math.ceil(v - e) - 0.01); dec = Math.max(dec, 2); break;
      case 'end9':  v = Math.max(0, Math.ceil((v + 1)/10 - e) * 10 - 1); break;
    }
    return v.toLocaleString('en-US', {minimumFractionDigits: dec, maximumFractionDigits: dec});
  }
  function preview($row){
    var rate = parseFloat($row.find('.lmrc-rate').val()), cur = ($row.find('.lmrc-currency').val() || '').toUpperCase();
    var decVal = $row.find('.lmrc-decimals').val(), dec = decVal === '' ? A.baseDec : parseInt(decVal, 10);
    var sample = 10000, out = '—';
    if (rate > 0) out = A.base + ' ' + sample.toLocaleString('en-US') + ' × ' + rate + ' = ' + (cur || '') + ' ' + convert(sample, rate, $row.find('.lmrc-rounding').val(), dec);
    $row.find('.lmrc-preview__out').text(out);
  }
  $(document).on('input change', '.lmrc-rate, .lmrc-rounding, .lmrc-currency, .lmrc-decimals', function(){ preview($(this).closest('.lmrc-region-row')); });
  $('#lmrc-regions .lmrc-region-row').each(function(){ preview($(this)); });
  function slug(v){ return String(v).toLowerCase().trim().replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,''); }
  function toggleEmpty(){ $('.lmrc-empty').prop('hidden', $('#lmrc-regions .lmrc-region-row:visible').length > 0); }
  function syncDefault($row){
    var key = $row.find('.lmrc-key').val(), name = $row.find('.lmrc-region-name').val() || A.i18n.newRegion;
    if (!key) return;
    var $o = $('#lmrc-default-region option').filter(function(){ return this.value === key; });
    if (!$o.length) $o = $('<option>').val(key).appendTo('#lmrc-default-region');
    $o.text(name);
  }
  function addRow(p){
    var $row = $(A.template.replace(/__INDEX__/g, String(index++)));
    $('#lmrc-regions').append($row);
    if (p){
      $row.find('.lmrc-region-name').val(p.name);
      $row.find('.lmrc-key').val(p.key);
      $row.find('.lmrc-currency').val(p.currency);
      $row.find('.lmrc-symbol').val(p.symbol);
      $row.find('.lmrc-decimals').val(p.decimals);
      // Preset multipliers assume PKR is the store currency.
      $row.find('.lmrc-rate').val(A.base === 'PKR' ? p.rate : (p.currency === A.base ? '1' : ''));
      $row.find('.lmrc-rounding').val(p.rounding);
      preview($row);
      $row.find('.lmrc-flag-url').val(p.flag).trigger('input');
      $row.find('.lmrc-region-name').trigger('input');
    } else {
      $row.find('.lmrc-region-name').trigger('focus');
      preview($row);
    }
    toggleEmpty();
    return $row;
  }
  $('#lmrc-add-region').on('click', function(){ addRow(null); });
  $('#lmrc-add-presets').on('click', function(){
    var have = $('#lmrc-regions .lmrc-region-row:visible .lmrc-currency').map(function(){ return this.value.toUpperCase(); }).get();
    presets.forEach(function(p){ if (have.indexOf(p.currency) === -1) addRow(p); });
  });
  $(document).on('input', '.lmrc-region-name', function(){
    var $row = $(this).closest('.lmrc-region-row'), $key = $row.find('.lmrc-key');
    if (String($key.data('new')) === '1' && !$key.data('locked')) $key.val(slug($(this).val()));
    $row.find('.lmrc-row-title').text($(this).val() || A.i18n.newRegion);
    $row.find('.lmrc-row-key').text($key.val());
    syncDefault($row);
  });
  $(document).on('input change', '.lmrc-img-url', function(){
    var $img = $('#' + this.id + '-preview');
    if (this.value) $img.attr('src', this.value).prop('hidden', false); else $img.prop('hidden', true);
  });
  $(document).on('click', '.lmrc-remove-region', function(){
    if (!window.confirm(A.i18n.confirm)) return;
    var $row = $(this).closest('.lmrc-region-row'), key = $row.find('.lmrc-key').val();
    $('#lmrc-default-region option').filter(function(){ return this.value === key; }).remove();
    if (String($row.find('.lmrc-key').data('new')) === '1') { $row.remove(); }
    else { $row.find('[required]').prop('required', false); $row.find('.lmrc-delete-input').val('1'); $row.hide(); }
    toggleEmpty();
  });
  $(document).on('click', '.lmrc-media-button', function(e){
    e.preventDefault();
    var target = $(this).data('target');
    var frame = wp.media({ title: 'Choose image', button: { text: 'Use image' }, library: { type: 'image' }, multiple: false });
    frame.on('select', function(){ $('#' + target).val(frame.state().get('selection').first().toJSON().url).trigger('change'); });
    frame.open();
  });
});
JS;
    }
}

add_action('plugins_loaded', function () {
    if (class_exists('WooCommerce')) LM_Regional_Commerce::instance();
});
