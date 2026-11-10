<?php
defined('ABSPATH') || exit;

final class Pimex_Samples_V5_Catalog {
    const CACHE_SCHEMA = 'v5-parent-scoped';
    private static $reading = false;
    private static $objects = array();
    private static $rows = array();
    private static $values = array();
    private static $attribute_lists = array();
    private static $terms = array();
    private static $ancestors = array();
    private static $slugs = array();
    private static $images = array();

    private static function begin_read() { self::$reading = true; }
    private static function end_read() {
        self::$reading = false;
        self::$objects = self::$rows = self::$values = self::$attribute_lists = self::$terms = self::$ancestors = self::$slugs = self::$images = array();
    }
    private static function object_key($product) {
        $key = spl_object_hash($product);
        if (self::$reading) { self::$objects[$key] = $product; }
        return $key;
    }
    private static function attribute_rows($product) {
        $key = self::object_key($product);
        if (self::$reading && isset(self::$rows[$key])) { return self::$rows[$key]; }
        $rows = array();
        foreach ($product->get_attributes() as $name => $attribute) {
            $label = wc_attribute_label($name, $product);
            $rows[] = array('name' => $name, 'attribute' => $attribute, 'label' => sanitize_title(str_replace('_', '-', $label)),
                'key' => preg_replace('/^pa-/', '', sanitize_title(str_replace('_', '-', $name))),
                'normalized' => self::normalize($label));
        }
        if (self::$reading) { self::$rows[$key] = $rows; }
        return $rows;
    }
    private static function attribute_value($product, $name) {
        $key = self::object_key($product) . ':' . $name;
        if (self::$reading && array_key_exists($key, self::$values)) { return self::$values[$key]; }
        $value = trim(wp_strip_all_tags($product->get_attribute($name)));
        if (self::$reading) { self::$values[$key] = $value; }
        return $value;
    }
    public static function category_ancestors($id) {
        if (self::$reading && isset(self::$ancestors[$id])) { return self::$ancestors[$id]; }
        $value = get_ancestors($id, 'product_cat', 'taxonomy');
        if (self::$reading) { self::$ancestors[$id] = $value; }
        return $value;
    }
    public static function category_term($id) {
        if (self::$reading && array_key_exists($id, self::$terms)) { return self::$terms[$id]; }
        $value = get_term($id, 'product_cat');
        if (self::$reading) { self::$terms[$id] = $value; }
        return $value;
    }
    public static function category_slugs($product) {
        $ids = $product->get_category_ids(); $key = implode(',', $ids);
        if (self::$reading && isset(self::$slugs[$key])) { return self::$slugs[$key]; }
        $slugs = array();
        foreach ($ids as $id) {
            foreach (array_merge(array($id), self::category_ancestors($id)) as $tid) {
                $term = self::category_term($tid);
                if ($term && !is_wp_error($term)) { $slugs[] = $term->slug; }
            }
        }
        if (self::$reading) { self::$slugs[$key] = $slugs; }
        return $slugs;
    }
    private static function image_url($id) {
        if (self::$reading && isset(self::$images[$id])) { return self::$images[$id]; }
        $url = $id ? wp_get_attachment_image_url($id, 'woocommerce_thumbnail') : wc_placeholder_img_src();
        $url = $url ?: wc_placeholder_img_src();
        if (self::$reading) { self::$images[$id] = $url; }
        return $url;
    }

    public static function changed_term($term_id, $tt_id, $taxonomy) {
        if (in_array($taxonomy, array('product_cat', 'product_brand'), true) || strpos($taxonomy, 'pa_') === 0) { self::invalidate(); }
    }
    public static function deleted_post($id, $post = null) {
        if (!$post || in_array($post->post_type, array('product', 'product_variation', 'attachment'), true)) { self::invalidate(); }
    }

    public static function prime_products($ids) {
        if ($ids && function_exists('_prime_post_caches')) { _prime_post_caches($ids, true, true); }
    }

    public static function changed_product($id) {
        if ($id && get_post_meta($id, '_pimex_samples_owned', true) === 'yes') { return; }
        self::invalidate();
    }

    public static function invalidate() {
        update_option('pimex_samples_catalog_revision', microtime(true), false);
        // Deliberately no background task: an administrator starts each build.
    }

    public static function signature() {
        $o = Pimex_Samples_V5::options();
        return md5(self::CACHE_SCHEMA . get_locale() . get_option('pimex_samples_catalog_revision', '0')
            . $o['excluded'] . wp_json_encode($o['excluded_keys']));
    }

    public static function is_waiting() { return !Pimex_Samples_V5_Builder::snapshot(); }
    public static function available_on_product($id) {
        $snapshot = Pimex_Samples_V5_Builder::snapshot();
        if (!$snapshot || ($snapshot['signature'] ?? '') !== self::signature()) { return null; }
        foreach ($snapshot['items'] as $s) { if ((int)$s['parent'] === (int)$id && !empty($s['available'])) { return true; } }
        return false;
    }

    public static function attribute($product, $labels) {
        foreach (self::attribute_rows($product) as $row) {
            if (in_array($row['label'], $labels, true) || in_array($row['key'], $labels, true)) {
                return self::attribute_value($product, $row['name']);
            }
        }
        return '';
    }

    public static function attribute_values($product, $labels) {
        $cache_key = self::object_key($product) . ':' . implode('|', $labels);
        if (self::$reading && array_key_exists($cache_key, self::$attribute_lists)) { return self::$attribute_lists[$cache_key]; }
        foreach (self::attribute_rows($product) as $row) {
            if (!in_array($row['label'], $labels, true) && !in_array($row['key'], $labels, true)) { continue; }
            $attribute = $row['attribute'];
            if (is_object($attribute) && method_exists($attribute, 'is_taxonomy')) {
                if ($attribute->is_taxonomy() && method_exists($attribute, 'get_terms')) {
                    $terms = $attribute->get_terms();
                    $values = $terms && !is_wp_error($terms) ? wp_list_pluck($terms, 'name') : array();
                } else {
                    $values = method_exists($attribute, 'get_options') ? $attribute->get_options() : array();
                }
            } else {
                $values = preg_split('/\s*[,|]\s*/u', self::attribute_value($product, $row['name']));
            }
            $values = array_values(array_unique(array_filter(array_map(function($value) {
                return trim(wp_strip_all_tags((string) $value));
            }, $values), function($value) { return $value !== ''; })));
            if (self::$reading) { self::$attribute_lists[$cache_key] = $values; }
            return $values;
        }
        if (self::$reading) { self::$attribute_lists[$cache_key] = array(); }
        return array();
    }

    public static function shades() {
        return array('grey' => 'Szarości i antracyty', 'brown' => 'Brązy i odcienie drewna',
            'beige' => 'Beże i kremy', 'red' => 'Rudości i czerwienie', 'black' => 'Czernie');
    }

    public static function normalize($text) {
        return strtolower(remove_accents(wp_strip_all_tags($text)));
    }

    public static function color_groups($color, $key) {
        $manual = get_option('pimex_samples_shades', array());
        if (array_key_exists($key, $manual)) { return array_values(array_intersect((array) $manual[$key], array_keys(self::shades()))); }
        $text = self::normalize($color);
        // Te nazwy zawsze wymagają jawnej decyzji administratora.
        if (preg_match('/\\b(basalt|antique)\\b/', $text)) { return array(); }
        $rules = array(
            'grey' => 'anthracite|anthracyt|antracyt|graphite|grey|gray|szary|szara|grafitowy|grafit',
            'brown' => 'teak|walnut|oak|dab|debowy|orzech|braz|brazowy',
            'beige' => 'cream latte|cream|bezowy|bez|kremowy|krem',
            'red' => 'copper|red cedar|oranz|miedziany|czerwony|rudy',
            'black' => 'ebony|czarny|black|burnt wood');
        $groups = array();
        foreach ($rules as $group => $words) {
            if (preg_match('/(?<![a-z])(?:' . $words . ')(?![a-z])/', $text)) { $groups[] = $group; }
        }
        return $groups;
    }

    public static function dimensions($text, $default_unit = 'mm') {
        // Jednostka atrybutu lub jawna jednostka po liczbie; brak danych pozostaje pusty.
        $text = self::normalize($text);
        if (preg_match('/(mm|cm|m)\\s*$/', $text, $unit_match)) { $default_unit = $unit_match[1]; }
        preg_match_all('/(\\d+(?:[.,]\\d+)?)\\s*(mm|cm|m)?(?![a-z])/', $text, $matches, PREG_SET_ORDER);
        $values = array();
        foreach ($matches as $match) {
            $unit = !empty($match[2]) ? $match[2] : $default_unit;
            $value = (float) str_replace(',', '.', $match[1]) * ($unit === 'm' ? 1000 : ($unit === 'cm' ? 10 : 1));
            if ($value > 0) { $values[] = round($value, 3); }
        }
        return array_values(array_unique($values));
    }

    public static function product_dimensions($product, $axis) {
        $values = array();
        foreach (self::attribute_rows($product) as $row) {
            $label = $row['normalized'];
            $names = array('width' => 'szerokosc', 'length' => 'dlugosc', 'thickness' => 'grubosc');
            if (!isset($names[$axis])) { return array(); }
            $needle = $names[$axis];
            if ($axis === 'width' || $axis === 'thickness') {
                // Tylko szerokość deski, nigdy szerokość krycia/montażowa.
                if (!in_array(sanitize_title($label), array($needle, $needle . '-mm', $needle . '-cm', $needle . '-m'), true)) { continue; }
            } elseif (strpos($label, $needle) === false) { continue; }
            $unit = preg_match('/(?<![a-z])(mm|cm|m)(?![a-z])/', $label, $m) ? $m[1] : 'mm';
            $values = array_merge($values, self::dimensions(self::attribute_value($product, $row['name']), $unit));
        }
        return array_values(array_unique($values));
    }

    public static function custom_length($product) {
        foreach (self::attribute_rows($product) as $row) {
            $label = $row['label']; $key = $row['key'];
            $value = trim(self::normalize(self::attribute_value($product, $row['name'])));
            $flags = array('na-wymiar', 'produkcja-na-wymiar', 'dlugosc-na-wymiar');
            if (in_array($label, $flags, true) || in_array($key, $flags, true)) {
                // WooCommerce łączy wybrane terminy, np. „do 6m, Tak”.
                // Sprawdzamy każdy termin osobno, nie całą połączoną etykietę.
                foreach (preg_split('/[|;,]/u', $value) as $term) {
                    if (in_array(trim($term), array('tak', 'yes', 'true', '1'), true)) { return true; }
                }
            }
            if (strpos($label, 'dlugosc') !== false || strpos($key, 'dlugosc') !== false) {
                // Niektóre katalogi przechowują Na wymiar jako wartość atrybutu Długość.
                foreach (preg_split('/[|;,]/', $value) as $term) {
                    if (preg_match('/^(?:na[ -]+wymiar|produkcja[ -]+na[ -]+wymiar)(?:\s*\([^)]*\))?$/u', trim($term))) { return true; }
                }
            }
        }
        return false;
    }

    public static function data_issues($sample) {
        $issues = array();
        if (empty($sample['widths'])) { $issues[] = 'Brak szerokości'; }
        if (empty($sample['thicknesses'])) { $issues[] = 'Brak grubości'; }
        if (empty($sample['lengths']) && empty($sample['custom_length'])) { $issues[] = 'Brak długości'; }
        if (empty($sample['construction'])) { $issues[] = 'Brak konstrukcji'; }
        if (empty($sample['shades'])) { $issues[] = 'Nieprzypisany odcień'; }
        return $issues;
    }

    public static function merge($a, $b) {
        $chosen = !$a['available'] && $b['available'] ? $b : $a;
        foreach (array('widths', 'lengths', 'thicknesses') as $axis) {
            $chosen[$axis] = array_values(array_unique(array_merge((array) ($a[$axis] ?? array()), (array) ($b[$axis] ?? array()))));
            sort($chosen[$axis], SORT_NUMERIC);
        }
        $chosen['custom_length'] = !empty($a['custom_length']) || !empty($b['custom_length']);
        return $chosen;
    }

    private static function group($product) {
        $slugs = self::category_slugs($product);
        if (in_array('lamele-kompozytowe', $slugs, true)) { return 'lamele'; }
        if (in_array('kompozytowe-elewacje', $slugs, true)) { return 'elewacje'; }
        return 'tarasy';
    }

    public static function describe($source, $parent, $variation_colors, $detail = null) {
        $own_read = !self::$reading;
        if ($own_read) { self::begin_read(); }
        try {
            $brand = self::attribute($parent, array('producent', 'producent-marka', 'marka'));
            if ($brand === '' && taxonomy_exists('product_brand')) {
                $terms = get_the_terms($parent->get_id(), 'product_brand');
                if ($terms && !is_wp_error($terms)) { $brand = implode(' / ', wp_list_pluck($terms, 'name')); }
            }
            if ($brand === '') {
                foreach ($parent->get_category_ids() as $id) {
                    foreach (self::category_ancestors($id) as $ancestor) {
                        $term = self::category_term($ancestor);
                        if ($term && !is_wp_error($term) && $term->slug === 'producenci') {
                            $leaf = self::category_term($id);
                            if ($leaf && !is_wp_error($leaf)) { $brand = $leaf->name; break 2; }
                        }
                    }
                }
            }
            $color = $variation_colors ? implode(', ', $variation_colors)
                : self::attribute($parent, array('kolor', 'color'));
            $series = self::attribute($parent, array('seria-system', 'seria', 'kolekcja'));
            $source['brand'] = $brand ?: 'Pozostałe';
            $types = $detail ? self::attribute_values($detail, array('typ-elementu')) : array();
            if (!$types) { $types = self::attribute_values($parent, array('typ-elementu')); }
            $source['product_type'] = implode(' / ', $types);
            $source['color'] = $color;
            $source['series'] = $series;
            $source['title'] = $series ?: $parent->get_name();
            $source['group'] = self::group($parent);
            $source['image_url'] = self::image_url($source['image']);
            $source['sku'] = $parent->get_sku();
            foreach (array('surfaces' => 'powierzchnia', 'materials' => 'material') as $field => $attribute) {
                $values = $detail ? self::attribute_values($detail, array($attribute)) : array();
                $source[$field] = $values ?: self::attribute_values($parent, array($attribute));
            }
            // Konserwatywne usuwanie duplikatów osobnych długości: wymagane wszystkie dane profilu.
            $width = self::attribute($parent, array('szerokosc'));
            $thickness = self::attribute($parent, array('grubosc'));
            if ($series !== '' && $color !== '' && $width !== '' && $thickness !== '') {
                $identity = array($source['brand'], $series, $color, $width, $thickness, $source['group'],
                    self::attribute($parent, array('konstrukcja')), self::attribute($parent, array('powierzchnia')));
                $source['legacy_profile_key'] = 'profile:' . md5(wp_json_encode($identity));
            }
            $construction_value = $detail ? self::attribute($detail, array('konstrukcja')) : '';
            $construction = self::normalize($construction_value ?: self::attribute($parent, array('konstrukcja')));
            $source['construction'] = preg_match('/\\b(pelna|pelne|solid)\\b/', $construction) ? 'solid'
                : (preg_match('/\\b(komorowa|komorowe|hollow)\\b/', $construction) ? 'hollow' : '');
            $material_value = $detail ? self::attribute($detail, array('material')) : '';
            $material = self::normalize($material_value ?: self::attribute($parent, array('material')));
            $composite = strpos($material, 'kompozyt') !== false || preg_match('/\\bwpc\\b/', $material);
            if (!$composite && $material === '') {
                $composite = (bool) array_intersect(self::category_slugs($parent), array('deski-kompozytowe', 'kompozytowe-elewacje', 'lamele-kompozytowe'));
            }
            $coating = self::normalize(self::attribute($detail ?: $parent, array('powloka','powloka-polimerowa')));
            $source['coating'] = in_array($coating, array('tak','yes','z powloka','powloka polimerowa'), true) ? 'coated'
                : (in_array($coating, array('nie','no','bez powloki'), true) ? 'uncoated' : '');
            $manual = (array)get_option('pimex_samples_shades', array());
            $shade_key = array_key_exists($source['key'], $manual) ? $source['key'] : ($source['legacy_profile_key'] ?? $source['key']);
            $source['shades'] = self::color_groups($color, $shade_key);
            $source['widths'] = self::product_dimensions($parent, 'width');
            $source['lengths'] = self::product_dimensions($parent, 'length');
            $source['thicknesses'] = self::product_dimensions($parent, 'thickness');
            $source['custom_length'] = self::custom_length($parent) || ($detail && self::custom_length($detail));
            return $source;
        } finally { if ($own_read) { self::end_read(); } }
    }

    /** View-only projection; full source metadata stays on the server for checkout. */
    public static function browser_items($items) {
        $keys = array_fill_keys(array('id', 'key', 'label', 'image_url', 'url', 'available', 'brand', 'color',
            'title', 'profile', 'product_type', 'group', 'sku', 'series', 'shades', 'widths', 'lengths', 'thicknesses', 'surfaces',
            'materials', 'construction', 'coating', 'custom_length'), true);
        $result = array();
        foreach ($items as $item) {
            $view = array_intersect_key($item, $keys);
            if (($view['available'] ?? null) === true) { unset($view['available']); }
            if (!isset($view['series']) || $view['series'] === '' || $view['series'] === ($view['title'] ?? '')) { unset($view['series']); }
            foreach (array('sku', 'color', 'product_type', 'shades', 'widths', 'lengths', 'thicknesses', 'surfaces', 'materials', 'construction', 'coating', 'custom_length') as $field) {
                if (isset($view[$field]) && ($view[$field] === '' || $view[$field] === array() || $view[$field] === false)) { unset($view[$field]); }
            }
            $result[] = $view;
        }
        return $result;
    }

    /** Read-only: no product queries, cron, rebuild, transient expiry or AJAX polling. */
    public static function all($unused = false) {
        $snapshot = Pimex_Samples_V5_Builder::snapshot();
        return $snapshot ? $snapshot['items'] : array();
    }
}
