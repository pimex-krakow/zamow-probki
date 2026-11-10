<?php
/**
 * Plugin Name: PIMEX — Zamów próbki 5
 * Description: Wybór próbek desek i lameli na podstawie istniejących produktów WooCommerce.
 * Version: 5.0.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * License: GPL-2.0-or-later
 * Author: PIMEX
 */
defined('ABSPATH') || exit;

require_once __DIR__ . '/includes/catalog.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/visibility.php';
require_once __DIR__ . '/includes/builder.php';
require_once __DIR__ . '/includes/view.php';

final class Pimex_Samples_V5 {
    const VERSION = '5.0.0';
    const OPTION = 'pimex_samples_1';
    private static $adding = false;

    public static function boot() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function() { echo '<div class="notice notice-error"><p>PIMEX Próbki wymaga aktywnej wtyczki WooCommerce.</p></div>'; });
            return;
        }
        if (class_exists('Pimex_Samples_1') || class_exists('Pimex_Compare')) {
            add_action('admin_notices', function() { echo '<div class="notice notice-error"><p>Próbki 5 nie zostały uruchomione: wyłącz starszą wtyczkę próbek i porównywarkę PIMEX. Nie uruchamiaj ich jednocześnie.</p></div>'; });
            return;
        }
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_pimex_samples_save', array(__CLASS__, 'save'));
        add_shortcode('pimex_probki', array(__CLASS__, 'shortcode'));
        add_shortcode('pimex_probki_kafelek', array(__CLASS__, 'home_tile'));
        Pimex_Samples_V5_Admin::boot();
        Pimex_Samples_V5_Visibility::boot();
        Pimex_Samples_V5_Builder::boot();
        foreach (array('save_post_product', 'save_post_product_variation', 'woocommerce_new_product', 'woocommerce_update_product', 'woocommerce_new_product_variation', 'woocommerce_update_product_variation') as $hook) {
            add_action($hook, array('Pimex_Samples_V5_Catalog', 'changed_product'));
        }
        foreach (array('created_term', 'edited_term', 'delete_term') as $hook) {
            add_action($hook, array('Pimex_Samples_V5_Catalog', 'changed_term'), 10, 3);
        }
        add_action('set_object_terms', function($id, $terms, $tt_ids, $taxonomy) { Pimex_Samples_V5_Catalog::changed_term(0, 0, $taxonomy); }, 10, 4);
        add_action('deleted_post', array('Pimex_Samples_V5_Catalog', 'deleted_post'), 10, 2);
        add_filter('template_include', function($template) {
            $o = self::options();
            if (!is_admin() && is_page() && (int) get_queried_object_id() === (int) $o['page']) {
                return __DIR__ . '/templates/page.php';
            }
            return $template;
        }, 99);
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_if_needed'));
        add_action('woocommerce_before_calculate_totals', array(__CLASS__, 'enforce_price'), 20);
        add_action('template_redirect', array(__CLASS__, 'submit'), 15);
        // Claim sample submissions before WooCommerce handles the board form at priority 20.
        add_action('wp_loaded', array(__CLASS__, 'direct_submit'), 15);
        add_action('woocommerce_after_add_to_cart_button', array(__CLASS__, 'cta'));
        add_filter('woocommerce_add_to_cart_validation', array(__CLASS__, 'validate'), 10, 6);
        add_filter('woocommerce_get_item_data', array(__CLASS__, 'item_data'), 10, 2);
        add_filter('woocommerce_cart_item_name', array(__CLASS__, 'cart_name'), 10, 3);
        add_filter('woocommerce_cart_item_thumbnail', array(__CLASS__, 'thumbnail'), 10, 3);
        add_filter('woocommerce_cart_item_permalink', array(__CLASS__, 'permalink'), 10, 3);
        add_action('woocommerce_checkout_create_order_line_item', array(__CLASS__, 'order_item'), 10, 4);
        add_action('woocommerce_check_cart_items', array(__CLASS__, 'check_cart'));
        add_filter('woocommerce_cart_shipping_packages', array(__CLASS__, 'packages'), 90);
        add_filter('woocommerce_package_rates', array(__CLASS__, 'shipping'), 999, 2);
        add_filter('woocommerce_coupon_is_valid_for_product', array(__CLASS__, 'coupon'), 10, 4);
        add_action('wp_footer', array(__CLASS__, 'clear_selection'));
    }

    public static function options() {
        return wp_parse_args(get_option(self::OPTION, array()), array(
            'product' => 0, 'page' => 0, 'price' => '4.00', 'shipping' => '14.00', 'max' => 12,
            'excluded' => '', 'excluded_keys' => array(), 'enabled' => false
        ));
    }

    public static function parse_amount($value) {
        if (!is_string($value) || !preg_match('/^\d+(?:[.,]\d{1,2})?$/', trim($value))) { return false; }
        $amount = str_replace(',', '.', trim($value));
        if (!is_finite((float) $amount)) { return false; }
        return wc_format_decimal($amount, 2);
    }

    public static function price_label($amount) {
        return number_format((float) $amount, 2, ',', ' ') . ' zł';
    }

    public static function sample_product() {
        $o = self::options();
        $p = wc_get_product((int) $o['product']);
        return $p && $p->get_meta('_pimex_samples_owned') === 'yes' ? $p : false;
    }

    public static function ready() {
        $o = self::options();
        $p = self::sample_product();
        return (bool) get_option('pimex_samples_v5_live', false) && get_woocommerce_currency() === 'PLN' && $o['enabled'] && is_numeric($o['price']) && (float) $o['price'] >= 0
            && is_numeric($o['shipping']) && (float) $o['shipping'] >= 0 && $p
            && $p->is_purchasable() && $p->is_in_stock();
    }

    private static function categories() {
        return array('deski-kompozytowe', 'deski-drewniane', 'kompozytowe-elewacje', 'lamele-kompozytowe');
    }

    public static function allowed($p) {
        if (!$p || $p->get_meta('_pimex_samples_owned') === 'yes' || $p->get_status() !== 'publish' || $p->get_catalog_visibility() === 'hidden'
            || !in_array($p->get_type(), array('simple', 'variable'), true)) { return false; }
        $exclude = array('legary', 'legary-tarasowe', 'wsporniki-tarasowe', 'elementy-montazowe',
            'elementy-wykonczeniowe', 'elementy-montazowe-deski-elewacyjne',
            'elementy-wykonczeniowe-deski-elewacyjne', 'klipsy', 'listwy-wykonczeniowe');
        $slugs = Pimex_Samples_V5_Catalog::category_slugs($p);
        return array_intersect($slugs, self::categories()) && !array_intersect($slugs, $exclude);
    }

    // Źródło i opis zawsze odczytywane po stronie serwera, nigdy z ceny/nazwy przesłanej przez klienta.
    public static function source($id, $include_unavailable = false) {
        $p = wc_get_product(absint($id));
        if (!$p) { return false; }
        $parent = $p->is_type('variation') ? wc_get_product($p->get_parent_id()) : $p;
        if (!self::allowed($parent)) { return false; }
        $o = self::options();
        $excluded = wp_parse_id_list($o['excluded']);
        $locked = in_array($p->get_id(), $excluded, true) || in_array($parent->get_id(), $excluded, true)
            || $parent->get_meta('_pimex_sample_disabled') === 'yes';
        $available = !$locked;
        if (!$available && !$include_unavailable) { return false; }
        $dimension_source = $p;
        $color = array();
        if ($p->is_type('variation')) {
            if ($p->get_status() !== 'publish') { return false; }
            foreach ($p->get_attributes() as $taxonomy => $slug) {
                $label = sanitize_title(wc_attribute_label($taxonomy, $parent));
                if ($label === 'kolor' || $label === 'color') {
                    if ($slug === '') { return false; }
                    $term = taxonomy_exists($taxonomy) ? get_term_by('slug', $slug, $taxonomy) : false;
                    $color[$taxonomy] = $term && !is_wp_error($term) ? $term->name : $slug;
                }
            }
            // Warianty długości nie tworzą nowych próbek tego samego koloru.
            // Keep this variation ID; the key, not the ID, groups different lengths.
        } elseif ($parent->is_type('variable')) {
            foreach ($parent->get_attributes() as $taxonomy => $attribute) {
                if (in_array(sanitize_title(wc_attribute_label($taxonomy, $parent)), array('kolor','color'), true)
                    && $attribute->get_variation()) { return false; }
            }
        }
        ksort($color);
        $label = $parent->get_name();
        if ($color) { $label .= ' — ' . implode(', ', $color); }
        $source = array(
            'id' => $p->get_id(), 'parent' => $parent->get_id(),
            'key' => $parent->get_id() . ':' . md5(wp_json_encode($color)),
            'label' => $label, 'image' => $parent->get_image_id(),
            'url' => $parent->get_permalink(), 'available' => $available, 'locked' => $locked
        );
        $source = Pimex_Samples_V5_Catalog::describe($source, $parent, $color, $dimension_source);
        if ($dimension_source->is_type('variation')) {
            foreach (array('width' => 'widths', 'length' => 'lengths', 'thickness' => 'thicknesses') as $axis => $field) {
                $values = Pimex_Samples_V5_Catalog::product_dimensions($dimension_source, $axis);
                if ($values) { $source[$field] = $values; }
            }
        }
        $legacy_key = $source['key'];
        // Inne profile/powierzchnie/konstrukcje nie są wariantami długości.
        $profile = array();
        if ($dimension_source->is_type('variation')) {
            foreach ($dimension_source->get_attributes() as $name => $value) {
                $label = sanitize_title(wc_attribute_label($name, $parent));
                if ($value !== '' && preg_match('/^(profil|konstrukcja|powierzchnia|material|szerokosc|grubosc)(-|$)/', $label)) {
                    $profile[$name] = $value;
                }
            }
        }
        if ($profile) {
            ksort($profile);
            $suffix = ':variant:' . md5(wp_json_encode($profile));
            $source['key'] .= $suffix;
            $source['legacy_variant_key'] = ($source['legacy_profile_key'] ?? $legacy_key) . $suffix;
            $profile_labels = array();
            foreach ($profile as $name => $value) { $profile_labels[] = wc_attribute_label($name, $parent) . ': ' . ($dimension_source->get_attribute($name) ?: $value); }
            $source['profile'] = implode(' / ', $profile_labels);
            $source['label'] .= ' — ' . $source['profile'];
            $manual = (array)get_option('pimex_samples_shades', array());
            $shade_key = array_key_exists($source['key'], $manual) ? $source['key'] : (array_key_exists($source['legacy_variant_key'], $manual) ? $source['legacy_variant_key'] : ($source['legacy_profile_key'] ?? $source['key']));
            $source['shades'] = Pimex_Samples_V5_Catalog::color_groups($source['color'], $shade_key);
        }
        if (in_array($source['legacy_variant_key'] ?? '', (array)$o['excluded_keys'], true) || in_array($source['legacy_profile_key'] ?? $legacy_key, (array)$o['excluded_keys'], true) || in_array($legacy_key, (array) $o['excluded_keys'], true) || in_array($source['key'], (array) $o['excluded_keys'], true)) { $source['available'] = false; }
        return !$source['available'] && !$include_unavailable ? false : $source;
    }

    /** Compatibility helper reads only the prepared snapshot, never get_children(). */
    public static function cards($p, $include_unavailable = false) {
        return array_values(array_filter(Pimex_Samples_V5_Catalog::all(), function($s) use ($p, $include_unavailable) {
            return (int)$s['parent'] === (int)$p->get_id() && ($include_unavailable || !empty($s['available']));
        }));
    }

    public static function menu() {
        add_submenu_page('woocommerce', 'Próbki PIMEX', 'Próbki PIMEX', 'manage_woocommerce',
            'pimex-probki', array('Pimex_Samples_V5_Admin', 'render'));
    }

    public static function save() {
        if (!current_user_can('manage_woocommerce')) { wp_die('Brak uprawnień.'); }
        check_admin_referer('pimex_samples_save');
        if (get_woocommerce_currency() !== 'PLN') { wp_die('Wymagana waluta sklepu to PLN.'); }
        $o = self::options();
        $previous_excluded = $o['excluded'];
        $price = isset($_POST['sample_price']) ? self::parse_amount(wp_unslash($_POST['sample_price'])) : $o['price'];
        $shipping = isset($_POST['sample_shipping']) ? self::parse_amount(wp_unslash($_POST['sample_shipping'])) : $o['shipping'];
        if ($price === false || $shipping === false) {
            wp_die('Wpisz nieujemne kwoty brutto z maksymalnie dwoma miejscami po przecinku, np. 4,50. Ustawienia nie zostały zmienione.');
        }
        $o['price'] = $price;
        $o['shipping'] = $shipping;
        $o['max'] = max(1, min(50, absint(isset($_POST['sample_max']) ? $_POST['sample_max'] : 12)));
        $o['excluded'] = implode(',', wp_parse_id_list(isset($_POST['sample_excluded']) ? wp_unslash($_POST['sample_excluded']) : ''));
        $o['enabled'] = !empty($_POST['sample_enabled']);
        $p = self::sample_product();
        if (!$p) {
            $p = new WC_Product_Simple();
            $p->set_name('Próbka materiału PIMEX');
            $p->set_catalog_visibility('hidden');
            $p->set_virtual(false);
            $p->set_manage_stock(false);
            $p->set_description('Próbka wybranego materiału. Wybrany model i kolor zapisujemy w pozycji zamówienia.');
            $p->update_meta_data('_pimex_samples_owned', 'yes');
            $term = term_exists('probki-pimex', 'product_shipping_class');
            if (!$term) { $term = wp_insert_term('Próbki PIMEX', 'product_shipping_class', array('slug' => 'probki-pimex')); }
            if ($term && !is_wp_error($term)) { $p->set_shipping_class_id(is_array($term) ? $term['term_id'] : $term); }
        }
        $tax = wc_tax_enabled() && $p->get_tax_status() === 'taxable' && !wc_prices_include_tax()
            ? array_sum(WC_Tax::calc_inclusive_tax((float) $o['price'], WC_Tax::get_base_tax_rates($p->get_tax_class()))) : 0;
        $stored_price = wc_format_decimal((float) $o['price'] - $tax, wc_get_rounding_precision());
        $p->set_sold_individually(true);
        $p->set_regular_price($stored_price);
        $p->set_sale_price('');
        $p->set_price($stored_price);
        $p->set_status($o['enabled'] ? 'publish' : 'draft');
        $o['product'] = $p->save();
        if (!$o['page'] || get_post_type($o['page']) !== 'page' || get_post_status($o['page']) === 'trash') {
            $page = wp_insert_post(array('post_type' => 'page', 'post_status' => 'draft',
                'post_title' => 'Zamów próbki desek i lameli', 'post_name' => 'zamow-probki', 'post_content' => '[pimex_probki]'), true);
            if (is_wp_error($page)) { wp_die(esc_html($page->get_error_message())); }
            $o['page'] = $page;
            update_post_meta($page, '_pimex_samples_page', 'yes');
        }
        update_option(self::OPTION, $o, false);
        update_option('pimex_samples_v5_live', !empty($_POST['sample_enabled']), false);
        if ($previous_excluded !== $o['excluded']) { Pimex_Samples_V5_Catalog::invalidate(); }
        wp_safe_redirect(admin_url('admin.php?page=pimex-probki&saved=1'));
        exit;
    }

    public static function enqueue_if_needed() {
        $id = (int)get_queried_object_id();
        if (($id && $id === (int)self::options()['page']) || (is_singular() && has_shortcode((string)get_post_field('post_content', $id), 'pimex_probki'))) { self::assets(); }
    }
    public static function assets($interactive = true) {
        if (!$interactive) {
            wp_enqueue_style('pimex-v5-product', plugins_url('assets/product-3.0.2.min.css', __FILE__), array(), self::VERSION);
            return;
        }
        wp_enqueue_script('pimex-v5-view', plugins_url('assets/view.js', __FILE__), array(), self::VERSION, true);
    }
    public static function shortcode() { return Pimex_Samples_V5_View::render(); }

    public static function home_tile() {
        $o = self::options();
        if (!$o['page'] || get_post_status($o['page']) !== 'publish' || !self::ready()) { return ''; }
        self::assets(false);
        return '<a class="pimex-samples-home" href="' . esc_url(get_permalink($o['page'])) . '">'
            . '<span class="pms-home-eyebrow">Próbki desek i lameli</span><strong>Zobacz materiał przed zakupem</strong>'
            . '<span>Porównaj kolory i strukturę w swoim domu. Próbka ' . esc_html(self::price_label($o['price'])) . ' · wysyłka zestawu ' . esc_html(self::price_label($o['shipping'])) . '.</span>'
            . '<span class="pms-home-link">Wybierz próbki →</span></a>';
    }

    public static function enforce_price($cart) {
        if (!$cart || (is_admin() && !wp_doing_ajax())) { return; }
        $o = self::options();
        foreach ($cart->get_cart() as $item) {
            if ((int) $item['product_id'] !== (int) $o['product'] || empty($item['pimex_sample'])) { continue; }
            $p = $item['data'];
            $tax = wc_tax_enabled() && $p->get_tax_status() === 'taxable' && !wc_prices_include_tax()
                ? array_sum(WC_Tax::calc_inclusive_tax((float) $o['price'], WC_Tax::get_base_tax_rates($p->get_tax_class()))) : 0;
            $p->set_price((float) $o['price'] - $tax);
        }
    }

    public static function submit() {
        if (is_singular() && ((int) get_queried_object_id() === (int) self::options()['page'] || has_shortcode((string) get_post_field('post_content', get_queried_object_id()), 'pimex_probki'))) {
            if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
            nocache_headers();
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || empty($_POST['pimex_samples_action'])) { return; }
        if (!isset($_POST['pimex_samples_nonce']) || !is_string($_POST['pimex_samples_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['pimex_samples_nonce'])), 'pimex_samples_order')) {
            wp_die('Sesja formularza wygasła. Odśwież stronę próbek i spróbuj ponownie.');
        }
        $o = self::options();
        if (!WC()->cart) { wc_load_cart(); }
        $redirect = $o['page'] ? get_permalink($o['page']) : wc_get_cart_url();
        $raw = $_POST['pimex_samples_ids'] ?? null;
        $ids = is_string($raw) && strlen($raw) <= 2048 ? json_decode(wp_unslash($raw), true) : null;
        if (!self::ready() || !is_array($ids) || !$ids || count($ids) > min(50, (int) $o['max'])) {
            wc_add_notice('Nie można dodać zestawu. Sprawdź dostępność próbek i liczbę zaznaczeń.', 'error');
            wp_safe_redirect($redirect); exit;
        }
        $result = self::add_samples($ids);
        if ($result !== true) {
            wc_add_notice($result, 'error'); wp_safe_redirect($redirect); exit;
        }
        wc_add_notice('Wybrane próbki są w koszyku. Każdy model i kolor ma osobną pozycję.', 'success');
        wp_safe_redirect(add_query_arg('probki_dodane', '1', wc_get_cart_url())); exit;
    }

    /** Shared server-side checks and atomic cart addition for both entry points. */
    public static function add_samples($ids) {
        $o = self::options();
        if (!self::ready() || !is_array($ids) || !$ids || count($ids) > min(50, (int) $o['max'])) {
            return 'Nie można dodać próbek. Sprawdź dostępność i limit zestawu.';
        }
        $items = array();
        foreach ($ids as $id) {
            if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]{0,17}$/D', (string) $id)) { $items = array(); break; }
            $s = self::source(absint($id));
            if (!$s) { $items = array(); break; }
            $items[$s['key']] = $s;
        }
        $in_cart = array(); $sample_count = 0;
        foreach (WC()->cart->get_cart() as $item) {
            if ((int)$item['product_id'] === (int)$o['product'] && !empty($item['pimex_sample']['id'])) {
                if (++$sample_count > 50) { return 'Przekroczono limit próbek w koszyku.'; }
                $current = self::source($item['pimex_sample']['id']);
                if ($current) { $in_cart[$current['key']] = true; }
            }
        }
        if (!$items || count(array_unique(array_merge(array_keys($items), array_keys($in_cart)))) > (int) $o['max']) {
            return 'Wybrana próbka jest niedostępna albo przekroczono limit różnych próbek w koszyku.';
        }
        $before = array();
        foreach (WC()->cart->get_cart() as $key => $item) { $before[$key] = $item['quantity']; }
        $added = array();
        $ok = true;
        self::$adding = true;
        try {
            foreach ($items as $s) {
                if (isset($in_cart[$s['key']])) { continue; }
                $key = WC()->cart->add_to_cart($o['product'], 1, 0, array(), array('pimex_sample' => $s));
                if (!$key) { $ok = false; break; }
                $added[] = $key;
            }
        } catch (Throwable $e) {
            if (function_exists('wc_get_logger')) { wc_get_logger()->error($e->getMessage(), array('source' => 'pimex-samples')); }
            $ok = false;
        } finally { self::$adding = false; }
        if (!$ok) {
            foreach ($added as $key) {
                if (isset($before[$key])) { WC()->cart->set_quantity($key, $before[$key], false); }
                else { WC()->cart->remove_cart_item($key); }
            }
            WC()->cart->calculate_totals();
            return 'Nie udało się dodać całego zestawu. Spróbuj ponownie.';
        }
        return true;
    }

    public static function direct_submit() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_POST['pimex_samples_direct'])) { return; }
        // Every outcome ends this request, so the board's hidden add-to-cart field is never processed.
        if (!WC()->cart) { wc_load_cart(); }
        $parent_id = is_scalar($_POST['pimex_samples_direct']) ? absint($_POST['pimex_samples_direct']) : 0;
        $parent = $parent_id ? wc_get_product($parent_id) : false;
        $redirect = $parent ? get_permalink($parent_id) : wc_get_cart_url();
        $nonce = isset($_POST['pimex_sample_direct_nonce']) && is_string($_POST['pimex_sample_direct_nonce'])
            ? sanitize_text_field(wp_unslash($_POST['pimex_sample_direct_nonce'])) : '';
        $error = '';
        $id = $parent_id;
        if (!$nonce || !wp_verify_nonce($nonce, 'pimex_sample_direct')) {
            $error = 'Sesja formularza wygasła. Odśwież kartę produktu i spróbuj ponownie.';
        } elseif (!$parent || !self::allowed($parent)) {
            $error = 'Próbka tego produktu jest niedostępna.';
        } elseif ($parent->is_type('variable')) {
            $id = isset($_POST['variation_id']) && is_scalar($_POST['variation_id']) ? absint($_POST['variation_id']) : 0;
            if ($id) {
                $variation = wc_get_product($id);
                if (!$variation || !$variation->is_type('variation') || (int) $variation->get_parent_id() !== $parent_id) {
                    $error = 'Wybierz poprawny wariant produktu, a następnie zamów próbkę.';
                }
            } else {
                $items = self::cards($parent, true);
                // Length-only variants may share one sample; distinct colors/profiles require a choice.
                if (count($items) === 1 && !empty($items[0]['available'])) { $id = $items[0]['id']; }
                else { $error = 'Wybierz kolor lub wariant produktu, a następnie zamów próbkę.'; }
            }
        }
        if (!$error) {
            $result = self::add_samples(array($id));
            if ($result !== true) { $error = $result; }
        }
        if ($error) { wc_add_notice($error, 'error'); }
        else {
            wc_add_notice('Próbka jest w koszyku.', 'success');
            $redirect = wc_get_cart_url();
        }
        wp_safe_redirect($redirect, 303, 'PIMEX Samples'); exit;
    }

    public static function validate($passed, $pid, $qty, $vid = 0, $variations = array(), $data = array()) {
        $o = self::options();
        if ((int) $pid === (int) $o['product'] && !self::$adding) {
            wc_add_notice('Wybierz model i kolor na stronie próbek.', 'error'); return false;
        }
        return $passed;
    }

    public static function item_data($data, $item) {
        if (!empty($item['pimex_sample'])) {
            $data[] = array('key' => 'Wybrana próbka', 'value' => $item['pimex_sample']['label']);
        }
        return $data;
    }
    public static function cart_name($name, $item, $key) {
        return !empty($item['pimex_sample']) ? esc_html('Próbka — ' . $item['pimex_sample']['label']) : $name;
    }
    public static function thumbnail($html, $item, $key) {
        return !empty($item['pimex_sample']['image']) ? wp_get_attachment_image($item['pimex_sample']['image'], 'woocommerce_thumbnail') : $html;
    }
    public static function permalink($url, $item, $key) {
        $o = self::options();
        return !empty($item['pimex_sample']) && $o['page'] ? get_permalink($o['page']) : $url;
    }
    public static function order_item($item, $key, $values, $order) {
        if (empty($values['pimex_sample'])) { return; }
        $s = $values['pimex_sample'];
        $item->set_name('Próbka — ' . $s['label']);
        $item->add_meta_data('Wybrana próbka', $s['label'], true);
        $item->add_meta_data('_pimex_sample_source', $s['id'], true);
        $item->add_meta_data('_pimex_sample_key', $s['key'], true);
        $item->add_meta_data('_pimex_sample_product', $s['parent'], true);
    }
    public static function check_cart() {
        $o = self::options();
        $keys = array(); $sample_count = 0;
        foreach (WC()->cart->get_cart() as $item) {
            if ((int) $item['product_id'] !== (int) $o['product']) { continue; }
            if (++$sample_count > 50) { wc_add_notice('Przekroczono limit próbek w koszyku.', 'error'); return; }
            $s = !empty($item['pimex_sample']['id']) ? self::source($item['pimex_sample']['id']) : false;
            if (!self::ready() || !$s || (int) $item['quantity'] !== 1) {
                wc_add_notice('Jedna z próbek jest niedostępna. Usuń ją z koszyka lub skontaktuj się z PIMEX.', 'error');
                return;
            }
            $keys[$s['key']] = true;
        }
        if (count($keys) > (int) $o['max']) { wc_add_notice('Przekroczono limit różnych próbek w koszyku.', 'error'); }
    }

    public static function cta() {
        global $product;
        $o = self::options();
        if (!$product || !self::allowed($product) || !self::ready()) { return; }
        // Rendering the button must not scan all product variations.
        if ($product->get_meta('_pimex_sample_disabled') === 'yes'
            || in_array((int) $product->get_id(), wp_parse_id_list($o['excluded']), true)
            || Pimex_Samples_V5_Catalog::available_on_product($product->get_id()) === false) { return; }
        self::assets(false);
        wp_enqueue_script('pimex-product-302', plugins_url('assets/product-3.0.2.min.js', __FILE__), array(), self::VERSION, true);
        $price = preg_replace('/,00(?= zł$)/u', '', self::price_label($o['price']));
        echo '<button type="submit" class="button pimex-sample-cta" name="pimex_samples_direct" value="' . (int) $product->get_id()
            . '" data-pimex-direct-url="' . esc_url(wc_get_cart_url()) . '" formnovalidate>Zamów próbkę (' . esc_html($price) . ')</button>';
        wp_nonce_field('pimex_sample_direct', 'pimex_sample_direct_nonce', false);
    }

    // Wydzielenie jednej paczki próbek bez nadpisywania transportu pełnych desek.
    public static function packages($packages) {
        $result = array(); $samples = array(); $template = null;
        $o = self::options();
        foreach ($packages as $package) {
            if ($template === null) { $template = $package; }
            foreach ($package['contents'] as $key => $item) {
                if ((int) $item['product_id'] === (int) $o['product'] && !empty($item['pimex_sample'])) {
                    $samples[$key] = $item;
                    unset($package['contents'][$key]);
                }
            }
            if ($package['contents']) {
                $package['contents_cost'] = array_sum(wp_list_pluck($package['contents'], 'line_total'));
                $result[] = $package;
            }
        }
        if ($samples && $template !== null) {
            $template['contents'] = $samples;
            $template['contents_cost'] = array_sum(wp_list_pluck($samples, 'line_total'));
            $template['pimex_samples_package'] = true;
            // Zmiana kwoty zmienia hash paczki WooCommerce i odświeża stawkę w sesji.
            $template['pimex_samples_shipping_gross'] = (string) $o['shipping'];
            unset($template['rates']);
            $result[] = $template;
        }
        return $result;
    }

    public static function shipping($rates, $package) {
        if (empty($package['pimex_samples_package'])) { return $rates; }
        $o = self::options();
        // Brak automatycznej stawki zagranicznej: zakres sprzedaży próbek to Polska.
        if (($package['destination']['country'] ?? '') !== 'PL') { return array(); }
        $sample = self::sample_product();
        $customer = WC()->customer;
        $tax_rates = wc_tax_enabled() && $sample && $sample->get_tax_status() === 'taxable'
            && (!$customer || !$customer->get_is_vat_exempt())
            ? WC_Tax::get_shipping_tax_rates($sample->get_tax_class()) : array();
        $taxes = $tax_rates ? WC_Tax::calc_inclusive_tax((float) $o['shipping'], $tax_rates) : array();
        $rate = new WC_Shipping_Rate('pimex_samples_shipping', 'Wysyłka zestawu próbek',
            (float) $o['shipping'] - array_sum($taxes), $taxes, 'pimex_samples_shipping');
        return array('pimex_samples_shipping' => $rate);
    }

    public static function coupon($valid, $product, $coupon, $values) {
        $o = self::options();
        return (int) $product->get_id() === (int) $o['product'] ? false : $valid;
    }

    public static function clear_selection() {
        if (!function_exists('is_cart') || !is_cart() || empty($_GET['probki_dodane'])) { return; }
        $keys = array('pimex-samples-v5-' . get_current_blog_id(), 'pimex-samples-v1-' . get_current_blog_id(), 'pimex-samples-v2-' . get_current_blog_id());
        echo '<script>try{' . wp_json_encode($keys) . '.forEach(function(k){localStorage.removeItem(k);});}catch(e){}</script>';
    }
}
add_action('plugins_loaded', array('Pimex_Samples_V5', 'boot'));

add_action('before_woocommerce_init', function() {
    if (class_exists('\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});
