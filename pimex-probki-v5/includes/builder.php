<?php
defined('ABSPATH') || exit;

/** Explicit admin-only indexing. Each request processes at most ten source posts. */
final class Pimex_Samples_V5_Builder {
    const BATCH = 10;
    const MAX_SOURCES = 20000;
    const MAX_SAMPLES = 1500;
    const MAX_BYTES = 2000000;
    private static $snapshot;

    public static function key($suffix) { return 'pimex_v5_' . $suffix . '_' . md5(get_locale()); }
    public static function boot() {
        add_action('admin_post_pimex_v5_build', array(__CLASS__, 'request'));
    }
    public static function snapshot() {
        if (self::$snapshot === null) {
            $value = get_option(self::key('snapshot'), false);
            self::$snapshot = is_array($value) && isset($value['items'], $value['signature']) ? $value : false;
        }
        return self::$snapshot;
    }
    public static function reset_memory() { self::$snapshot = null; }

    /** Atomic compare-and-delete: never remove a replacement worker's lock. */
    private static function release($token) {
        global $wpdb;
        $name = self::key('lock');
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $token));
        wp_cache_delete($name, 'options');
        wp_cache_delete('notoptions', 'options');
    }
    private static function acquire() {
        $name = self::key('lock'); $old = get_option($name, '');
        if ($old && (int)$old < time() - 300) { self::release($old); }
        $token = time() . ':' . wp_generate_uuid4();
        if (!add_option($name, $token, '', false)) { throw new RuntimeException('Katalog jest już przygotowywany w innej karcie. Poczekaj na zakończenie bieżącej partii.'); }
        return $token;
    }
    public static function request() {
        if (!current_user_can('manage_woocommerce')) { wp_send_json_error(array('message'=>'Brak uprawnień.'), 403); }
        check_admin_referer('pimex_v5_build');
        try { if (($_POST['operation'] ?? '') === 'cancel') { self::cancel(); wp_send_json_success(array('done'=>true, 'cancelled'=>true)); } wp_send_json_success(self::step(($_POST['operation'] ?? '') === 'start')); }
        catch (Throwable $e) {
            if (function_exists('wc_get_logger')) { wc_get_logger()->error($e->getMessage(), array('source'=>'pimex-samples-v5')); }
            wp_send_json_error(array('message'=>$e->getMessage()), 409);
        }
    }
    public static function step($start = false) {
        global $wpdb;
        $token = self::acquire();
        try {
            $state = get_option(self::key('build'), false);
            if ($start) {
                if (is_array($state) && !empty($state['active']) && ($state['updated'] ?? 0) > time() - 300) {
                    throw new RuntimeException('Budowa już trwa. Użyj „Wznów”, zamiast rozpoczynać kolejną.');
                }
                $ceiling = (int)$wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_status = 'publish'");
                if ($wpdb->last_error) { throw new RuntimeException('Nie udało się odczytać początku katalogu.'); }
                $state = array('active'=>true, 'cursor'=>0, 'ceiling'=>$ceiling, 'scanned'=>0, 'items'=>array(),
                    'signature'=>Pimex_Samples_V5_Catalog::signature(), 'updated'=>time());
            }
            if (!is_array($state) || empty($state['active'])) { throw new RuntimeException('Najpierw rozpocznij przygotowanie katalogu.'); }
            if ($state['signature'] !== Pimex_Samples_V5_Catalog::signature()) { throw new RuntimeException('Produkty lub ustawienia zmieniły się podczas przygotowania. Rozpocznij nową budowę po 5 minutach lub anuluj bieżącą w panelu. Dotychczasowy katalog pozostaje dostępny.'); }
            $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND ID <= %d AND post_type IN ('product','product_variation') AND post_status = 'publish' ORDER BY ID ASC LIMIT %d", $state['cursor'], $state['ceiling'], self::BATCH));
            if ($wpdb->last_error) { throw new RuntimeException('Nie udało się odczytać produktów. Sprawdź log bazy danych.'); }
            $deadline = microtime(true) + 4; $processed = 0;
            foreach ($ids as $id) {
                if ($state['scanned'] >= self::MAX_SOURCES) { throw new RuntimeException('Osiągnięto limit 20 000 produktów i wariantów. Nie opublikowano częściowego katalogu. Potrzebne jest zawężenie źródeł.'); }
                $p = wc_get_product($id);
                // Variable parents are represented by individual variants, read in subsequent bounded batches.
                if ($p && !$p->is_type('variable')) {
                    $s = Pimex_Samples_V5::source($id, true);
                    if ($s) {
                        $s['aliases'] = array((int)$id);
                        $s['attrs'] = Pimex_Samples_V5_View::attributes($p, $s['parent']);
                        $s['unit_options'] = array(Pimex_Samples_V5_View::rate($p, $s));
                        $key = $s['key']; $previous = $state['items'][$key] ?? null;
                        if ($previous) {
                            $merged = Pimex_Samples_V5_Catalog::merge($previous, $s);
                            $merged['aliases'] = array_values(array_unique(array_merge($previous['aliases'], $s['aliases'])));
                            $merged['unit_options'] = array_values(array_unique(array_merge($previous['unit_options'], $s['unit_options']), SORT_REGULAR));
                            if (count($merged['unit_options']) > 100) { throw new RuntimeException('Ponad 100 różnych cen długości jednego wzoru. Katalog wymaga przeglądu danych.'); }
                            foreach ($s['attrs'] as $field => $values) { $merged['attrs'][$field] = array_values(array_unique(array_merge($previous['attrs'][$field] ?? array(), $values))); }
                            $s = $merged;
                        }
                        $state['items'][$key] = $s;
                    }
                }
                $state['cursor'] = (int)$id; ++$state['scanned']; ++$processed;
                if (microtime(true) >= $deadline) { break; }
            }
            if (count($state['items']) > self::MAX_SAMPLES || strlen(wp_json_encode($state['items'])) > self::MAX_BYTES) {
                throw new RuntimeException('Osiągnięto limit 1500 wzorów lub 2 MB danych. Nie opublikowano częściowego katalogu.');
            }
            $done = !$ids || ($processed === count($ids) && count($ids) < self::BATCH);
            if ($state['signature'] !== Pimex_Samples_V5_Catalog::signature()) { throw new RuntimeException('Dane produktów zmieniły się. Anuluj i uruchom katalog ponownie.'); }
            if (get_option(self::key('lock')) !== $token) { throw new RuntimeException('Utracono blokadę budowy. Nie zapisano częściowego wyniku.'); }
            $state['updated'] = time();
            if ($done) {
                $items = array_values($state['items']);
                usort($items, function($a,$b) { return strnatcasecmp($a['brand'].' '.$a['title'].' '.$a['color'], $b['brand'].' '.$b['title'].' '.$b['color']); });
                // One option publication: readers see either the previous complete catalog or the next complete one.
                $snapshot = array('signature'=>$state['signature'], 'built'=>time(), 'scanned'=>$state['scanned'], 'items'=>$items);
                update_option(self::key('snapshot'), $snapshot, false);
                if (get_option(self::key('snapshot')) !== $snapshot) { throw new RuntimeException('Nie udało się zapisać kompletnego katalogu.'); }
                self::reset_memory();
                delete_option(self::key('build'));
            } else {
                update_option(self::key('build'), $state, false);
                if (get_option(self::key('build')) !== $state) { throw new RuntimeException('Nie udało się zapisać postępu budowy.'); }
            }
            return array('done'=>$done, 'scanned'=>$state['scanned'], 'samples'=>count($state['items']), 'batch'=>$processed);
        } finally { self::release($token); }
    }
    public static function cancel() {
        $token = self::acquire();
        try { delete_option(self::key('build')); }
        finally { self::release($token); }
    }
}
