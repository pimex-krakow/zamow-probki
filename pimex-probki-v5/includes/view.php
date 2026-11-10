<?php
defined('ABSPATH') || exit;

final class Pimex_Samples_V5_View {
    public static function definitions() {
        $out = array('brand'=>'Producent','shade'=>'Kolor','material'=>'Materiał','construction'=>'Konstrukcja',
            'coating'=>'Powłoka','surface'=>'Powierzchnia','width'=>'Szerokość deski','length'=>'Długość deski',
            'thickness'=>'Grubość deski','price_mb'=>'Cena deski za mb','price_m2'=>'Cena deski za m²');
        foreach ((array)wc_get_attribute_taxonomies() as $a) {
            if (count($out) >= 100) { break; }
            $out['attr_pa_' . sanitize_title($a->attribute_name)] = wp_strip_all_tags($a->attribute_label ?: $a->attribute_name);
        }
        return $out;
    }
    public static function fields($enabled = true) {
        $definitions = self::definitions(); $o = Pimex_Samples_V5::options();
        $order = array_values(array_unique(array_merge((array)($o['view_filter_order'] ?? array()), array_keys($definitions))));
        $selected = $o['view_filter_enabled'] ?? array('brand','shade','material','construction','surface','width','length','thickness');
        $out = array();
        foreach ($order as $key) { if (isset($definitions[$key]) && (!$enabled || in_array($key, (array)$selected, true))) { $out[$key] = $definitions[$key]; } }
        return $out;
    }
    public static function attributes($p, $parent_id) {
        $parent = $p->is_type('variation') ? wc_get_product($parent_id) : $p;
        $out = array();
        foreach (self::definitions() as $key => $label) {
            if (strpos($key,'attr_') !== 0) { continue; }
            $name = substr($key, 5); $values = array();
            $raw = $p->get_attribute($name);
            if ($raw === '') { $raw = $parent->get_attribute($name); }
            foreach (preg_split('/\s*[,|]\s*/u', (string)$raw) as $value) {
                $term = taxonomy_exists($name) ? get_term_by('slug', $value, $name) : false;
                $text = trim(wp_strip_all_tags($term && !is_wp_error($term) ? $term->name : $value));
                if ($text !== '') { $values[] = $text; }
            }
            $out[$key] = array_values(array_unique($values));
        }
        return $out;
    }
    /** Rates are an index for filters, computed only during explicit preparation. All board prices are per piece. */
    public static function rate($p, $s) {
        $out = array('mb'=>null, 'm2'=>null);
        if (!is_numeric($p->get_price()) || count($s['lengths']) !== 1 || (!empty($s['custom_length']) && !$p->is_type('variation'))) { return $out; }
        $meters = $s['lengths'][0] / 1000;
        if ($meters <= 0) { return $out; }
        // Use store base tax, never the administrator's billing/shipping location.
        $price = (float)$p->get_price();
        if (wc_tax_enabled() && $p->get_tax_status() === 'taxable' && !wc_prices_include_tax()) {
            $price += array_sum(WC_Tax::calc_tax($price, WC_Tax::get_base_tax_rates($p->get_tax_class()), false));
        }
        if (!is_finite($price) || $price < 0) { return $out; }
        $out['mb'] = round($price / $meters, 2);
        $parent = $p->is_type('variation') ? wc_get_product($p->get_parent_id()) : $p;
        $cover = Pimex_Samples_V5_Catalog::attribute($p, array('szerokosc-krycia','szerokosc-krycia-mm','szerokosc-krycia-cm','szerokosc-krycia-m'));
        $unit = 'mm';
        foreach ($p->get_attributes() as $name=>$unused) {
            $label = sanitize_title(wc_attribute_label($name, $p));
            if (strpos($label, 'szerokosc-krycia') === 0) { if (preg_match('/-(mm|cm|m)$/', $label, $m)) { $unit=$m[1]; } break; }
        }
        if ($cover === '' && $parent !== $p) {
            $cover = Pimex_Samples_V5_Catalog::attribute($parent, array('szerokosc-krycia','szerokosc-krycia-mm','szerokosc-krycia-cm','szerokosc-krycia-m'));
            foreach ($parent->get_attributes() as $name=>$unused) { $label=sanitize_title(wc_attribute_label($name,$parent)); if (strpos($label,'szerokosc-krycia')===0 && preg_match('/-(mm|cm|m)$/',$label,$m)) { $unit=$m[1]; break; } }
        }
        $widths = $cover !== '' ? Pimex_Samples_V5_Catalog::dimensions($cover, $unit) : $s['widths'];
        if (count($widths) === 1 && $widths[0] > 0) { $out['m2'] = round($price / ($meters * $widths[0] / 1000), 2); }
        return $out;
    }
    public static function render() {
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
        nocache_headers();
        $snapshot = Pimex_Samples_V5_Builder::snapshot();
        if (!$snapshot) { return '<p role="status">Katalog próbek jest w przygotowaniu. Skontaktuj się z PIMEX w sprawie próbek.</p>'; }
        Pimex_Samples_V5::assets(); $o = Pimex_Samples_V5::options();
        $items = array();
        foreach ($snapshot['items'] as $s) {
            $item = Pimex_Samples_V5_Catalog::browser_items(array($s))[0];
            $item['aliases'] = $s['aliases'] ?? array($s['id']); $item['parent'] = (int)$s['parent'];
            $item['attrs'] = $s['attrs'] ?? array(); $item['unit_options'] = $s['unit_options'] ?? array();
            $items[] = $item;
        }
        $picks = array();
        foreach (array('pimex_pick','pimex_picks') as $key) {
            $raw = $_GET[$key] ?? '';
            if (is_string($raw) && strlen($raw) <= 512 && preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*)*$/D', $raw)) { $picks = array_merge($picks, array_map('intval', explode(',', $raw))); }
        }
        $config = array('items'=>$items, 'enabled'=>Pimex_Samples_V5::ready(), 'max'=>min(50, max(1, (int)$o['max'])),
            'price'=>(float)$o['price'], 'shipping'=>(float)$o['shipping'], 'picks'=>array_slice(array_unique($picks),0,50),
            'storage'=>'pimex-samples-v5-'.get_current_blog_id(), 'fields'=>self::fields());
        ob_start(); wc_print_notices(); include dirname(__DIR__).'/templates/selector.php'; return ob_get_clean();
    }
}
