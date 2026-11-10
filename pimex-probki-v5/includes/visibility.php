<?php
defined('ABSPATH') || exit;

/** Keep the shared checkout product out of customer landing pages and search engines. */
final class Pimex_Samples_V5_Visibility {
    public static function boot() {
        add_action('template_redirect', array(__CLASS__, 'noindex_header'), 1);
        add_filter('wp_robots', array(__CLASS__, 'robots'));
        add_filter('wpseo_robots', array(__CLASS__, 'yoast_robots'), 99);
        add_filter('wpseo_exclude_from_sitemap_by_post_ids', array(__CLASS__, 'yoast_sitemap'));
        add_filter('wp_sitemaps_posts_query_args', array(__CLASS__, 'core_sitemap'), 10, 2);
    }

    private static function product_id() {
        $options = Pimex_Samples_V5::options();
        $id = (int) $options['product'];
        return $id > 0 && get_post_meta($id, '_pimex_samples_owned', true) === 'yes' ? $id : 0;
    }

    private static function is_product_page() {
        if (is_admin() || !is_singular('product')) { return false; }
        $id = self::product_id();
        return $id && (int) get_queried_object_id() === $id;
    }

    public static function noindex_header() {
        if (self::is_product_page() && !headers_sent()) { header('X-Robots-Tag: noindex, follow', true); }
    }

    public static function robots($robots) {
        if (!self::is_product_page()) { return $robots; }
        unset($robots['index'], $robots['nofollow']);
        $robots['noindex'] = true;
        $robots['follow'] = true;
        return $robots;
    }

    public static function yoast_robots($robots) {
        return self::is_product_page() ? 'noindex, follow' : $robots;
    }

    public static function yoast_sitemap($excluded) {
        $id = self::product_id();
        if ($id) { $excluded[] = $id; }
        return array_values(array_unique($excluded));
    }

    public static function core_sitemap($args, $post_type) {
        if ($post_type !== 'product') { return $args; }
        $id = self::product_id();
        if ($id) {
            $args['post__not_in'] = array_values(array_unique(array_merge((array) ($args['post__not_in'] ?? array()), array($id))));
        }
        return $args;
    }
}
