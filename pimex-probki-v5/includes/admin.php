<?php
defined('ABSPATH') || exit;

final class Pimex_Samples_V5_Admin {
    public static function boot() {
        add_action('admin_post_pimex_samples_filters',array(__CLASS__,'save_filters'));
        add_action('admin_post_pimex_samples_menu', array(__CLASS__, 'add_menu'));
        add_action('admin_post_pimex_samples_catalog', array(__CLASS__, 'save_catalog'));
        add_action('woocommerce_product_options_general_product_data', array(__CLASS__, 'product_field'));
        add_action('woocommerce_admin_process_product_object', array(__CLASS__, 'save_product'));
    }

    public static function product_field() {
        woocommerce_wp_checkbox(array('id' => '_pimex_sample_disabled', 'label' => 'Próbka niedostępna',
            'description' => 'Oznacz próbki tego produktu i jego wariantów jako chwilowo niedostępne. Pozostają widoczne w katalogu.'));
    }

    public static function save_product($product) {
        $product->update_meta_data('_pimex_sample_disabled', !empty($_POST['_pimex_sample_disabled']) ? 'yes' : 'no');
        Pimex_Samples_V5_Catalog::changed_product($product->get_id());
    }

    public static function render() {
        if (!current_user_can('manage_woocommerce')) { return; }
        $o = Pimex_Samples_V5::options();
        $page_id = (int) $o['page'];
        $sample = Pimex_Samples_V5::sample_product();
        wp_enqueue_script('pimex-v5-admin', plugins_url('../assets/admin.js', __FILE__), array(), Pimex_Samples_V5::VERSION, true);
        $catalog = Pimex_Samples_V5_Catalog::all();
        $search = isset($_GET['ps_admin_search']) ? sanitize_text_field(wp_unslash($_GET['ps_admin_search'])) : '';
        $filtered = array_values(array_filter($catalog, function($s) use ($search) {
            return $search === '' || stripos(remove_accents($s['label'] . ' ' . $s['brand'] . ' ' . $s['color']), remove_accents($search)) !== false;
        }));
        $page = max(1, absint($_GET['ps_admin_page'] ?? 1));
        $rows = array_slice($filtered, ($page - 1) * 30, 30);
        ?>
        <div class="wrap" style="max-width:1200px">
            <h1>Próbki PIMEX <small style="font-size:14px;color:#666">wersja <?php echo esc_html(Pimex_Samples_V5::VERSION); ?></small></h1>
            <?php if (!empty($_GET['saved']) || !empty($_GET['catalog_saved']) || !empty($_GET['menu_saved'])) : ?>
                <div class="notice notice-success"><p>Zapisano zmiany.</p></div>
            <?php endif; ?>
            <p><strong><?php echo esc_html(Pimex_Samples_V5::price_label($o['price'])); ?> brutto / próbka · <?php echo esc_html(Pimex_Samples_V5::price_label($o['shipping'])); ?> brutto za wysyłkę zestawu w Polsce.</strong> Nazwy, zdjęcia główne i kolory są pobierane z istniejących produktów.</p>
            <p>Przy wejściu filtry są wyczyszczone, a poprzedni wybór próbek może zostać zachowany. Przycisk przy desce lub przejście z porównania może zaznaczyć próbki. Próbki są niezależne od stanów magazynowych pełnych desek.</p>
            <h2>1. Przygotuj i uruchom stronę</h2><p>Wersja 5 ma własny, izolowany układ. Najpierw przygotuj katalog i sprawdź podgląd przy wyłączonym przyjmowaniu zamówień.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="pimex_samples_save"><?php wp_nonce_field('pimex_samples_save'); ?>
                <table class="form-table">
                    <tr><th><label for="pms-price">Cena próbki brutto (zł)</label></th><td><input id="pms-price" name="sample_price" type="text" inputmode="decimal" pattern="[0-9]+([,.][0-9]{1,2})?" required value="<?php echo esc_attr(str_replace('.', ',', $o['price'])); ?>"><p class="description">Cena jednej próbki. Możesz wpisać np. 4,50. Kwota 0 oznacza bezpłatną próbkę.</p></td></tr>
                    <tr><th><label for="pms-shipping-price">Wysyłka zestawu brutto (zł)</label></th><td><input id="pms-shipping-price" name="sample_shipping" type="text" inputmode="decimal" pattern="[0-9]+([,.][0-9]{1,2})?" required value="<?php echo esc_attr(str_replace('.', ',', $o['shipping'])); ?>"><p class="description">Jedna opłata za cały zestaw próbek w Polsce. Kwota 0 oznacza bezpłatną wysyłkę. Dostawa pełnych desek jest liczona oddzielnie.</p></td></tr>
                    <tr><th>Limit różnych próbek</th><td><input name="sample_max" type="number" min="1" max="50" value="<?php echo esc_attr($o['max']); ?>"> <span class="description">Po jednej sztuce każdego modelu i koloru.</span></td></tr>
                    <tr><th>Starsze wykluczenia ID</th><td><input name="sample_excluded" class="regular-text" value="<?php echo esc_attr($o['excluded']); ?>"><p class="description">Zachowane z poprzedniej wersji. Wygodny wybór dostępności znajduje się niżej.</p></td></tr>
                    <tr><th>Zamawianie próbek</th><td><label><input type="checkbox" name="sample_enabled" value="1" <?php checked(!empty($o['enabled']) && get_option('pimex_samples_v5_live', false)); ?>> Włącz przyjmowanie zamówień</label></td></tr>
                </table>
                <?php submit_button($sample ? 'Zapisz ustawienia' : 'Przygotuj stronę i produkt próbek'); ?>
            </form>
            <?php if ($sample) : ?><p><a class="button" href="<?php echo esc_url(get_edit_post_link($sample->get_id())); ?>">Produkt próbek — podatki i wysyłka</a></p><?php endif; ?>
            <?php if ($page_id) : ?>
                <p><a class="button button-primary" href="<?php echo esc_url(get_edit_post_link($page_id)); ?>">Edytuj / opublikuj stronę</a>
                <a class="button" href="<?php echo esc_url(get_post_status($page_id) === 'publish' ? get_permalink($page_id) : get_preview_post_link($page_id)); ?>">Podgląd strony</a></p>
            <?php endif; ?>
            <p>Strona wyboru: <code>[pimex_probki]</code>. Kafelek na stronę główną: <code>[pimex_probki_kafelek]</code> — wstaw w widżecie Shortcode Elementora.</p>
            <p>Wyklucz stronę wyboru próbek z cache stron w WP Fastest Cache i ewentualnym CDN. Wysyłka pełnych desek pozostaje osobna od paczki próbek.</p>

            <?php self::builder_panel(); self::filter_editor(); ?>
            <h2>2. Dodaj „Zamów próbki” do menu</h2>
            <?php if (current_user_can('edit_theme_options')) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="pimex_samples_menu"><?php wp_nonce_field('pimex_samples_menu'); ?>
                    <label for="pms-menu">Wybierz istniejące menu:</label>
                    <select id="pms-menu" name="sample_menu" required><option value="">Wybierz menu</option>
                        <?php foreach (wp_get_nav_menus() as $menu) : ?><option value="<?php echo esc_attr($menu->term_id); ?>"><?php echo esc_html($menu->name); ?></option><?php endforeach; ?>
                    </select>
                    <button class="button" <?php disabled(!$page_id || get_post_status($page_id) !== 'publish'); ?>>Dodaj pozycję na końcu menu</button>
                    <p class="description">Najpierw opublikuj stronę. Powtórz dla osobnego menu mobilnego, jeśli go używasz. Pozostałe pozycje zachowują kolejność.</p>
                </form>
            <?php else : ?><p>Dodanie pozycji menu wymaga uprawnień administratora do edycji wyglądu.</p><?php endif; ?>

            <h2>3. Dostępność próbek i grupy odcieni</h2><p><strong>Dostępne próbki rozpoznane jako produkcja na wymiar: <?php echo esc_html(count(array_filter($catalog, function($s) { return !empty($s['available']) && !empty($s['custom_length']); }))); ?></strong></p><p>Aby przypisać odcień ręcznie, odznacz „Automatycznie” i zaznacz jedną lub więcej grup. Basalt i Antique wymagają sprawdzenia zdjęcia lub wzornika; nie przypisujemy ich automatycznie.</p>
            <p>Odznacz próbki, których chwilowo nie wysyłasz — na stronie pozostaną szare z napisem „Chwilowo niedostępne”. Katalog obejmuje <?php echo esc_html(count($catalog)); ?> modeli i kolorów. Pełne deski nie są modyfikowane.</p>
            <form method="get"><input type="hidden" name="page" value="pimex-probki">
                <input type="search" name="ps_admin_search" value="<?php echo esc_attr($search); ?>" placeholder="Szukaj modelu, marki lub koloru" style="width:320px;max-width:100%">
                <button class="button">Szukaj</button>
            </form><br>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="pimex_samples_catalog"><?php wp_nonce_field('pimex_samples_catalog'); ?>
                <input type="hidden" name="return_search" value="<?php echo esc_attr($search); ?>">
                <input type="hidden" name="return_page" value="<?php echo esc_attr($page); ?>">
                <table class="widefat striped"><thead><tr><th>Dostępna</th><th>Zdjęcie</th><th>Model / kolor</th><th>Producent</th><th>Grupy odcieni</th></tr></thead><tbody>
                <?php foreach ($rows as $s) : ?>
                    <tr><td><?php if (empty($s['locked'])) : ?><input type="hidden" name="visible_keys[]" value="<?php echo esc_attr($s['key']); ?>"><?php endif; ?>
                        <input type="checkbox" name="available_keys[]" value="<?php echo esc_attr($s['key']); ?>" <?php checked($s['available']); disabled(!empty($s['locked'])); ?> aria-label="<?php echo esc_attr('Próbka dostępna: ' . $s['label']); ?>"></td>
                        <td><img src="<?php echo esc_url($s['image_url']); ?>" alt="" width="48" height="48" style="object-fit:contain"></td>
                        <td><strong><?php echo esc_html($s['title']); ?></strong><br><?php echo esc_html($s['color'] . (!empty($s['profile']) ? ' / '.$s['profile'] : '')); ?><?php if (!empty($s['locked'])) : ?><br><em>Wyłączona w produkcie źródłowym lub starszych wykluczeniach ID.</em><?php endif; ?><br><a href="<?php echo esc_url(get_edit_post_link($s['parent'])); ?>">Edytuj produkt źródłowy</a></td>
                        <td><?php echo esc_html($s['brand']); ?></td><td>
                            <input type="hidden" name="shade_keys[]" value="<?php echo esc_attr($s['key']); ?>">
                            <label><input type="checkbox" name="shade_auto[<?php echo esc_attr($s['key']); ?>]" value="1" <?php checked(!array_key_exists($s['key'], get_option('pimex_samples_shades', array()))); ?>> Automatycznie</label><br>
                            <?php foreach (Pimex_Samples_V5_Catalog::shades() as $group => $label) : ?>
                                <label style="display:block"><input type="checkbox" name="shade_groups[<?php echo esc_attr($s['key']); ?>][]" value="<?php echo esc_attr($group); ?>" <?php checked(in_array($group, $s['shades'], true)); ?>><?php echo esc_html($label); ?></label>
                            <?php endforeach; ?>
                            <?php if (!$s['shades']) : ?><em>Nieprzypisany — widoczny w pełnym katalogu.</em><?php endif; ?>
                        </td></tr>
                <?php endforeach; if (!$rows) : ?><tr><td colspan="5">Brak próbek dla tych kryteriów.</td></tr><?php endif; ?>
                </tbody></table>
                <?php if ($rows) { submit_button('Zapisz dostępność i odcienie na tej stronie'); } ?>
            </form>
            <?php echo wp_kses_post(paginate_links(array('base' => add_query_arg(array('page' => 'pimex-probki', 'ps_admin_search' => $search, 'ps_admin_page' => '%#%'), admin_url('admin.php')), 'format' => '', 'current' => $page, 'total' => (int) ceil(count($filtered) / 30)))); ?>
            <h2>4. Kontrola danych katalogu</h2>
            <p>Braki wpływają na wyniki filtrów. Szerokość, długość, grubość i konstrukcję uzupełnij w atrybutach produktu, a odcień przypisz w tabeli powyżej. Raport nie zmienia produktów. Deski na wymiar nie wymagają liczbowej długości.</p>
            <?php $data_report = array(); foreach ($catalog as $entry) {
                $issues = Pimex_Samples_V5_Catalog::data_issues($entry);
                if ($issues) { $data_report[] = array('sample' => $entry, 'issues' => $issues); }
            } ?>
            <p><strong>Próbki wymagające sprawdzenia: <?php echo esc_html(count($data_report)); ?> / <?php echo esc_html(count($catalog)); ?></strong></p>
            <?php if ($data_report) : ?>
            <details><summary>Pokaż listę brakujących danych</summary>
                <table class="widefat striped"><thead><tr><th>Model / kolor</th><th>Do sprawdzenia</th><th>Produkt źródłowy</th></tr></thead><tbody>
                <?php foreach ($data_report as $row) : $entry = $row['sample']; ?>
                    <tr><td><?php echo esc_html($entry['label']); ?></td><td><?php echo esc_html(implode(', ', $row['issues'])); ?></td>
                        <td><a href="<?php echo esc_url(get_edit_post_link($entry['parent'])); ?>">Edytuj produkt #<?php echo esc_html($entry['parent']); ?></a></td></tr>
                <?php endforeach; ?>
                </tbody></table>
            </details>
            <?php else : ?><p>Wszystkie próbki mają dane potrzebne do tych filtrów.</p><?php endif; ?>
        </div>
        <?php
    }

    public static function builder_panel() {
        $snapshot = Pimex_Samples_V5_Builder::snapshot();
        $stale = !$snapshot || $snapshot['signature'] !== Pimex_Samples_V5_Catalog::signature();
        ?>
        <h2>Przygotowanie katalogu</h2>
        <p><?php echo $snapshot ? 'Opublikowano '.count($snapshot['items']).' wzorów. Ostatnia budowa: '.esc_html(wp_date('Y-m-d H:i', $snapshot['built'])).'.' : 'Katalog nie został jeszcze przygotowany.'; ?>
        <strong><?php echo $stale ? 'Wymaga przygotowania.' : 'Dane katalogu są aktualne.'; ?></strong></p>
        <p>Przygotowanie uruchamia tylko ten panel. Jedna partia obejmuje maksymalnie 10 produktów lub wariantów, z kontrolą czasu po każdym z nich. Nie uruchamiaj importów ani innych aktualizacji w trakcie. Zatrzymanie nie usuwa opublikowanego katalogu.</p>
        <form class="pimex-v5-builder" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
        <input type="hidden" name="action" value="pimex_v5_build"><?php wp_nonce_field('pimex_v5_build'); ?>
        <button type="button" data-operation="start" class="button button-primary">Przygotuj katalog</button>
        <button type="button" data-operation="resume" class="button">Wznów</button>
        <button type="button" data-operation="stop" class="button">Zatrzymaj po bieżącej partii</button>
        <button type="button" data-operation="cancel" class="button">Anuluj budowę</button>
        <p class="pimex-v5-progress" role="status" aria-live="polite"></p>
        </form>
        <?php
    }
    public static function filter_editor() {
        $enabled = array_keys(Pimex_Samples_V5_View::fields()); ?>
        <h2>Filtry strony próbek</h2><p>Zaznacz widoczne filtry. Kolejność zmienisz przyciskami w górę i w dół. Nie zmienia to porównywarki.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pimex-v5-filter-form">
        <input type="hidden" name="action" value="pimex_samples_filters"><?php wp_nonce_field('pimex_samples_filters'); ?>
        <input type="hidden" name="view_filter_order" value="[]"><ol class="pimex-v5-filter-list">
        <?php foreach (Pimex_Samples_V5_View::fields(false) as $key=>$label) : ?>
        <li data-field="<?php echo esc_attr($key); ?>" style="padding:6px"><label><input type="checkbox" name="view_filter_enabled[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key,$enabled,true)); ?>> <?php echo esc_html($label); ?></label>
        <button type="button" class="button" data-move="up" aria-label="<?php echo esc_attr('Przesuń w górę: '.$label); ?>">↑</button>
        <button type="button" class="button" data-move="down" aria-label="<?php echo esc_attr('Przesuń w dół: '.$label); ?>">↓</button></li>
        <?php endforeach; ?></ol><?php submit_button('Zapisz filtry'); ?></form><?php
    }
    public static function save_filters() {
        if (!current_user_can('manage_woocommerce')) { wp_die('Brak uprawnień.'); } check_admin_referer('pimex_samples_filters');
        $raw = $_POST['view_filter_order'] ?? '';
        if (!is_string($raw) || strlen($raw)>16384) { wp_die('Nieprawidłowa kolejność.'); }
        $order = json_decode(wp_unslash($raw),true); $visible = $_POST['view_filter_enabled'] ?? array();
        if (!is_array($order) || count($order)>100 || !is_array($visible) || count($visible)>100) { wp_die('Nieprawidłowe filtry.'); }
        $keys=array_keys(Pimex_Samples_V5_View::definitions());
        $clean=function($values) use ($keys) { return array_values(array_unique(array_filter($values,function($v) use ($keys) { return is_string($v) && in_array($v,$keys,true); }))); };
        $o=Pimex_Samples_V5::options(); $o['view_filter_order']=array_values(array_unique(array_merge($clean($order),$keys)));
        $o['view_filter_enabled']=$clean($visible); update_option(Pimex_Samples_V5::OPTION,$o,false);
        wp_safe_redirect(admin_url('admin.php?page=pimex-probki&saved=1')); exit;
    }

    public static function save_catalog() {
        if (!current_user_can('manage_woocommerce')) { wp_die('Brak uprawnień.'); }
        check_admin_referer('pimex_samples_catalog');
        $allowed = array_column(Pimex_Samples_V5_Catalog::all(), 'key');
        $clean = function($name) { $list = $_POST[$name] ?? array(); if (!is_array($list) || count($list) > 30) { wp_die('Nieprawidłowa lista próbek.'); } return array_values(array_map('sanitize_text_field', array_filter(wp_unslash($list), 'is_string'))); };
        $visible = array_intersect($allowed, $clean('visible_keys'));
        $available = array_intersect($visible, $clean('available_keys'));
        $manual = get_option('pimex_samples_shades', array());
        $shade_keys = array_intersect($allowed, $clean('shade_keys'));
        $auto = (array) wp_unslash($_POST['shade_auto'] ?? array());
        $groups = (array) wp_unslash($_POST['shade_groups'] ?? array());
        foreach ($shade_keys as $key) {
            if (!empty($auto[$key])) { unset($manual[$key]); }
            else { $posted = is_array($groups[$key] ?? null) ? array_filter($groups[$key], 'is_string') : array(); $manual[$key] = array_values(array_intersect(array_keys(Pimex_Samples_V5_Catalog::shades()), $posted)); }
        }
        update_option('pimex_samples_shades', $manual, false);
        $o = Pimex_Samples_V5::options();
        $o['excluded_keys'] = array_values(array_unique(array_merge(array_diff((array) $o['excluded_keys'], $visible), array_diff($visible, $available))));
        update_option(Pimex_Samples_V5::OPTION, $o, false);
        Pimex_Samples_V5_Catalog::invalidate();
        wp_safe_redirect(add_query_arg(array('page' => 'pimex-probki', 'catalog_saved' => 1,
            'ps_admin_page' => max(1, absint($_POST['return_page'] ?? 1)),
            'ps_admin_search' => sanitize_text_field(wp_unslash($_POST['return_search'] ?? ''))), admin_url('admin.php')));
        exit;
    }

    public static function add_menu() {
        if (!current_user_can('manage_woocommerce') || !current_user_can('edit_theme_options')) { wp_die('Brak uprawnień.'); }
        check_admin_referer('pimex_samples_menu');
        $o = Pimex_Samples_V5::options();
        $menu = wp_get_nav_menu_object(absint($_POST['sample_menu'] ?? 0));
        if (!$menu || is_wp_error($menu) || !$o['page'] || get_post_status($o['page']) !== 'publish') { wp_die('Wybierz istniejące menu i opublikuj stronę próbek.'); }
        $exists = false;
        foreach ((array) wp_get_nav_menu_items($menu->term_id) as $item) {
            if ($item->object === 'page' && (int) $item->object_id === (int) $o['page']) { $exists = true; break; }
        }
        if (!$exists) {
            $result = wp_update_nav_menu_item($menu->term_id, 0, array('menu-item-title' => 'Zamów próbki',
                'menu-item-object-id' => $o['page'], 'menu-item-object' => 'page',
                'menu-item-type' => 'post_type', 'menu-item-status' => 'publish'));
            if (is_wp_error($result)) { wp_die(esc_html($result->get_error_message())); }
        }
        wp_safe_redirect(admin_url('admin.php?page=pimex-probki&menu_saved=1'));
        exit;
    }
}
