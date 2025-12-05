<?php
/**
 * Detection Result Caching for Turbo Charge WP
 *
 * Caches plugin detection results to avoid redundant analysis
 * on repeated URL patterns and post content.
 *
 * @package TurboChargeWP
 */

if (!defined('ABSPATH')) {
    exit;
}

class TurboChargeWP_Detection_Cache {

    /**
     * Cache group for URL pattern caching
     */
    const URL_CACHE_GROUP = 'tcwp_url_detection';

    /**
     * Cache group for content scanning
     */
    const CONTENT_CACHE_GROUP = 'tcwp_content_detection';

    /**
     * Cache expiration time (1 hour)
     */
    const CACHE_EXPIRATION = 3600;

    /**
     * Get cached detection result for URL pattern
     *
     * @param string $url Request URI
     * @return array|false Cached detection result or false
     */
    public static function get_url_detection($url) {
        $cache_key = self::generate_url_cache_key($url);

        // Try object cache first (Redis, Memcached)
        if (wp_using_ext_object_cache()) {
            $cached = wp_cache_get($cache_key, self::URL_CACHE_GROUP);
            if ($cached !== false) {
                return $cached;
            }
        }

        // Fall back to transient
        return get_transient($cache_key);
    }

    /**
     * Set cached detection result for URL pattern
     *
     * @param string $url Request URI
     * @param array $detected Array of detected plugins
     * @return bool Success
     */
    public static function set_url_detection($url, $detected) {
        $cache_key = self::generate_url_cache_key($url);

        // Save to object cache if available
        if (wp_using_ext_object_cache()) {
            wp_cache_set($cache_key, $detected, self::URL_CACHE_GROUP, self::CACHE_EXPIRATION);
        }

        // Also save to transient for persistence
        return set_transient($cache_key, $detected, self::CACHE_EXPIRATION);
    }

    /**
     * Get cached content scan result for post
     *
     * @param int $post_id Post ID
     * @return array|false Cached scan result or false
     */
    public static function get_content_scan($post_id) {
        // Check post meta first (persistent)
        $cached = get_post_meta($post_id, '_tcwp_required_plugins', true);

        if (!empty($cached) && is_array($cached)) {
            // Check if cache is still valid (not older than 1 week)
            $cached_time = get_post_meta($post_id, '_tcwp_cache_time', true);

            if ($cached_time && (current_time('timestamp') - $cached_time) < WEEK_IN_SECONDS) {
                return $cached;
            }
        }

        return false;
    }

    /**
     * Set cached content scan result for post
     *
     * @param int $post_id Post ID
     * @param array $detected Array of detected plugins
     * @return bool Success
     */
    public static function set_content_scan($post_id, $detected) {
        update_post_meta($post_id, '_tcwp_required_plugins', $detected);
        update_post_meta($post_id, '_tcwp_cache_time', current_time('timestamp'));
        return true;
    }

    /**
     * Generate cache key for URL pattern
     *
     * @param string $url Request URI
     * @return string Cache key
     */
    private static function generate_url_cache_key($url) {
        // Normalize URL (remove query params and hash)
        $url = strtok($url, '?');
        $url = strtok($url, '#');

        // Generate short hash
        return 'tcwp_url_' . md5($url);
    }

    /**
     * Clear all URL detection caches
     */
    public static function clear_url_cache() {
        global $wpdb;

        // Clear transients
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_tcwp_url_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_tcwp_url_%'");

        // Clear object cache if available
        if (wp_using_ext_object_cache()) {
            wp_cache_flush_group(self::URL_CACHE_GROUP);
        }
    }

    /**
     * Clear content scan cache for specific post
     *
     * @param int $post_id Post ID
     */
    public static function clear_post_cache($post_id) {
        delete_post_meta($post_id, '_tcwp_required_plugins');
        delete_post_meta($post_id, '_tcwp_cache_time');
    }

    /**
     * Clear all content scan caches
     */
    public static function clear_all_content_cache() {
        global $wpdb;

        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_tcwp_required_plugins'");
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_tcwp_cache_time'");
    }

    /**
     * Clear all caches (URL + Content)
     */
    public static function clear_all_caches() {
        self::clear_url_cache();
        self::clear_all_content_cache();
    }

    /**
     * Get cache statistics
     *
     * @return array Cache stats
     */
    public static function get_cache_stats() {
        global $wpdb;

        // Count URL cache entries
        $url_cache_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_tcwp_url_%'"
        );

        // Count content cache entries
        $content_cache_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_tcwp_required_plugins'"
        );

        // Calculate approximate cache size
        $cache_size = ($url_cache_count * 500) + ($content_cache_count * 300); // Bytes estimate

        return [
            'url_cache_entries' => (int) $url_cache_count,
            'content_cache_entries' => (int) $content_cache_count,
            'total_entries' => (int) ($url_cache_count + $content_cache_count),
            'estimated_size_kb' => round($cache_size / 1024, 2),
            'using_object_cache' => wp_using_ext_object_cache()
        ];
    }

    /**
     * Warm up cache for common URLs
     * Can be called during low-traffic periods
     *
     * @param array $urls Array of URLs to warm up
     * @param callable $detection_callback Callback to run detection
     */
    public static function warmup_cache($urls, $detection_callback) {
        if (!is_callable($detection_callback)) {
            return;
        }

        foreach ($urls as $url) {
            // Check if already cached
            if (self::get_url_detection($url) === false) {
                // Run detection and cache
                $detected = call_user_func($detection_callback, $url);
                self::set_url_detection($url, $detected);
            }
        }
    }

    /**
     * Auto-clear old caches (cleanup job)
     * Should be called by wp-cron
     */
    public static function cleanup_old_caches() {
        // Transients auto-expire, so just clean up orphaned timeout entries
        global $wpdb;

        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_timeout_tcwp_%'
             AND option_value < UNIX_TIMESTAMP()"
        );

        // Clean up old content caches (older than 1 month)
        $old_timestamp = current_time('timestamp') - (30 * DAY_IN_SECONDS);

        $wpdb->query($wpdb->prepare(
            "DELETE pm1, pm2
             FROM {$wpdb->postmeta} pm1
             LEFT JOIN {$wpdb->postmeta} pm2
                ON pm1.post_id = pm2.post_id
                AND pm2.meta_key = '_tcwp_required_plugins'
             WHERE pm1.meta_key = '_tcwp_cache_time'
             AND pm1.meta_value < %d",
            $old_timestamp
        ));
    }
}
