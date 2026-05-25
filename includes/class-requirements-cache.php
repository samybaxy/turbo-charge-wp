<?php
/**
 * High-Performance Requirements Cache for Samybaxy's Hyperdrive
 *
 * This class maintains a pre-computed lookup table for plugin requirements.
 * All analysis happens on post save, so runtime lookups are O(1).
 *
 * The lookup table is stored as a single serialized option for fast retrieval.
 * The MU-loader does ONE database query and ONE hash lookup per request.
 *
 * @package SamybaxyHyperdrive
 */

if (!defined('ABSPATH')) {
    exit;
}

class SHYPDR_Requirements_Cache {

    /**
     * Option name for the lookup table
     */
    const LOOKUP_TABLE_OPTION = 'shypdr_url_requirements';

    /**
     * Hard cap on lookup-table entries. Larger sites fall back to runtime
     * rule-based detection (still O(1) via the restriction rules) once the
     * cap is reached, keeping the serialized option payload well under the
     * autoload budget.
     */
    const LOOKUP_MAX_ENTRIES = 1500;

    /**
     * Combined autoloaded payload consumed by the MU-loader. Replaces five
     * separate direct DB reads with one cached alloptions hit.
     */
    const MU_PAYLOAD_OPTION = 'shypdr_mu_payload';
    const MU_PAYLOAD_VERSION = 1;

    /**
     * Option name for post type requirements
     */
    const POST_TYPE_OPTION = 'shypdr_post_type_requirements';

    /**
     * Get the full lookup table (for MU-loader to fetch once per request)
     *
     * @return array URL slug => plugins mapping
     */
    public static function get_lookup_table() {
        return get_option(self::LOOKUP_TABLE_OPTION, []);
    }

    /**
     * Get post type requirements
     *
     * @return array Post type => plugins mapping
     */
    public static function get_post_type_requirements() {
        return get_option(self::POST_TYPE_OPTION, self::get_default_post_type_requirements());
    }

    /**
     * Update requirements for a single page/post
     *
     * @param int $post_id Post ID
     * @return bool Success
     */
    public static function update_post_requirements($post_id) {
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish' || empty($post->post_name)) {
            return false;
        }

        // Skip revisions and autosaves
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return false;
        }

        // Analyze the post
        if (!class_exists('SHYPDR_Content_Analyzer')) {
            require_once dirname(__FILE__) . '/class-content-analyzer.php';
        }

        $required = SHYPDR_Content_Analyzer::analyze_post($post_id);

        // Update the lookup table atomically.
        $table = get_option(self::LOOKUP_TABLE_OPTION, []);

        // Move-to-end semantics: drop any existing entries for this post
        // before re-inserting so this post becomes "most recent" in PHP's
        // insertion-ordered array.
        unset(
            $table[$post->post_name],
            $table['id:' . $post_id]
        );

        $table[$post->post_name] = $required;
        $table['id:' . $post_id] = $required;

        if ($post->post_type === 'page') {
            $path = get_page_uri($post);
            if ($path && $path !== $post->post_name) {
                unset($table['path:' . $path]);
                $table['path:' . $path] = $required;
            }
        }

        // FIFO eviction by insertion order — oldest entries fall off first.
        if (count($table) > self::LOOKUP_MAX_ENTRIES) {
            $table = array_slice($table, -self::LOOKUP_MAX_ENTRIES, null, true);
        }

        return update_option(self::LOOKUP_TABLE_OPTION, $table, false);
    }

    /**
     * Remove requirements for a deleted post
     *
     * @param int $post_id Post ID
     * @return bool Success
     */
    public static function remove_post_requirements($post_id) {
        $post = get_post($post_id);

        $table = get_option(self::LOOKUP_TABLE_OPTION, []);

        // Remove by slug
        if ($post && !empty($post->post_name)) {
            unset($table[$post->post_name]);
        }

        // Remove by ID
        unset($table['id:' . $post_id]);

        // Remove path entries
        foreach ($table as $key => $value) {
            if (strpos($key, 'path:') === 0) {
                // Check if this path entry belongs to this post
                $path = substr($key, 5);
                $page_id = url_to_postid('/' . $path);
                if ($page_id === $post_id) {
                    unset($table[$key]);
                }
            }
        }

        return update_option(self::LOOKUP_TABLE_OPTION, $table, false);
    }

    /**
     * Rebuild the entire lookup table
     * Call this during plugin activation or after bulk changes
     *
     * @return int Number of pages processed
     */
    public static function rebuild_lookup_table() {
        global $wpdb;

        if (!class_exists('SHYPDR_Content_Analyzer')) {
            require_once dirname(__FILE__) . '/class-content-analyzer.php';
        }

        $table = [];
        $count = 0;

        // Cap by post count so the resulting serialized blob stays within
        // the autoload budget. Sites with more posts fall back to runtime
        // rule-based detection for the unindexed pages.
        $post_cap = (int) floor(self::LOOKUP_MAX_ENTRIES / 3);

        // Get most-recently-modified published posts.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only bulk rebuild operation, cache is being built
        $posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_name, post_type
                 FROM {$wpdb->posts}
                 WHERE post_status = 'publish'
                 AND post_name != ''
                 AND post_type IN ('post', 'page', 'product', 'lp_course')
                 ORDER BY post_modified_gmt DESC
                 LIMIT %d",
                $post_cap
            ),
            ARRAY_A
        );

        foreach ($posts as $post_data) {
            $required = SHYPDR_Content_Analyzer::analyze_post($post_data['ID']);

            if (!empty($required)) {
                $table[$post_data['post_name']] = $required;
                $table['id:' . $post_data['ID']] = $required;
                $count++;
            }
        }

        // Add WooCommerce pages explicitly
        $woo_pages = ['shop', 'cart', 'checkout', 'my-account'];
        $woo_plugins = ['woocommerce', 'woocommerce-stripe-gateway'];
        foreach ($woo_pages as $page) {
            if (!isset($table[$page])) {
                $table[$page] = $woo_plugins;
            }
        }

        // Add front page to lookup table (homepage '/' has empty slug in MU-loader)
        $front_page_id = get_option('page_on_front');
        if ($front_page_id) {
            $front_page = get_post($front_page_id);
            if ($front_page && !empty($front_page->post_name)) {
                $front_required = SHYPDR_Content_Analyzer::analyze_post($front_page_id);
                if (!empty($front_required)) {
                    $table[$front_page->post_name] = $front_required;
                    $table['id:' . $front_page_id] = $front_required;
                }
            }
        }

        // Final safety cap — if we somehow over-shot (e.g., explicit Woo
        // pages pushed past the limit), trim from the front.
        if (count($table) > self::LOOKUP_MAX_ENTRIES) {
            $table = array_slice($table, -self::LOOKUP_MAX_ENTRIES, null, true);
        }

        update_option(self::LOOKUP_TABLE_OPTION, $table, false);

        return $count;
    }

    /**
     * Get default post type to plugin requirements
     *
     * @return array Post type => plugins mapping
     */
    public static function get_default_post_type_requirements() {
        return [
            'product' => ['woocommerce', 'jet-woo-builder', 'jet-woo-product-gallery'],
            'product_cat' => ['woocommerce'],
            'product_tag' => ['woocommerce'],
            'lp_course' => ['learnpress'],
            'lp_lesson' => ['learnpress'],
            'lp_quiz' => ['learnpress'],
            'jet-popup' => ['jet-popup', 'jet-engine'],
            'jet-menu' => ['jet-menu', 'jet-engine'],
            'jet-smart-filters' => ['jet-smart-filters', 'jet-engine'],
            'tribe_events' => ['the-events-calendar'],
            'forum' => ['bbpress'],
            'topic' => ['bbpress'],
        ];
    }

    /**
     * Get requirements for a specific post type
     *
     * @param string $post_type Post type slug
     * @return array Required plugins
     */
    public static function get_for_post_type($post_type) {
        $requirements = self::get_post_type_requirements();
        return $requirements[$post_type] ?? [];
    }

    /**
     * Get requirements for a URL (fast O(1) lookup)
     *
     * @param string $url_slug URL slug to lookup
     * @return array Required plugins
     */
    public static function get_for_slug($url_slug) {
        $table = self::get_lookup_table();
        return $table[$url_slug] ?? [];
    }

    /**
     * Get cache statistics
     *
     * @return array Statistics
     */
    public static function get_stats() {
        $table = self::get_lookup_table();
        $size = strlen(serialize($table));

        return [
            'total_entries' => count($table),
            'size_bytes' => $size,
            'size_kb' => round($size / 1024, 2),
        ];
    }

    /**
     * Clear the entire cache
     */
    public static function clear() {
        delete_option(self::LOOKUP_TABLE_OPTION);
    }

    /**
     * Option names for restrictable data (consumed by MU-loader)
     */
    const RESTRICTABLE_OPTION = 'shypdr_restrictable_plugins';
    const RESTRICTION_RULES_OPTION = 'shypdr_restriction_rules';

    /**
     * Build and persist the combined MU-loader payload as a single
     * autoloaded option. The MU-loader reads ONLY this option, which means
     * it costs zero additional DB queries (it rides on the alloptions
     * cache that WordPress loads anyway).
     *
     * Call this from any path that mutates the individual source options
     * (rebuild_lookup_table, rebuild_restrictable_data, rebuild_dependency_map,
     * update_post_requirements, and the shypdr_enabled toggle).
     *
     * @return bool Success
     */
    public static function write_mu_payload() {
        $payload = [
            'v'            => self::MU_PAYLOAD_VERSION,
            'enabled'      => (bool) get_option('shypdr_enabled', false),
            'restrictable' => get_option(self::RESTRICTABLE_OPTION, []),
            'rules'        => get_option(self::RESTRICTION_RULES_OPTION, []),
            'lookup'       => get_option(self::LOOKUP_TABLE_OPTION, []),
            'dep_map'      => get_option('shypdr_dependency_map', []),
        ];

        // Sanity coercion — every key must be the expected type so the
        // MU-loader can trust the payload without re-validating.
        foreach (['restrictable', 'rules', 'lookup', 'dep_map'] as $k) {
            if (!is_array($payload[$k])) {
                $payload[$k] = [];
            }
        }

        // Final autoload safety cap on the lookup blob.
        if (count($payload['lookup']) > self::LOOKUP_MAX_ENTRIES) {
            $payload['lookup'] = array_slice(
                $payload['lookup'],
                -self::LOOKUP_MAX_ENTRIES,
                null,
                true
            );
        }

        return update_option(self::MU_PAYLOAD_OPTION, $payload, true);
    }

    /**
     * Get the restrictable plugins set (for MU-loader)
     *
     * @return array Plugin slugs that can be restricted
     */
    public static function get_restrictable_plugins() {
        return get_option(self::RESTRICTABLE_OPTION, []);
    }

    /**
     * Get the restriction rules (for MU-loader)
     *
     * @return array Ecosystem slug => rule definition
     */
    public static function get_restriction_rules() {
        return get_option(self::RESTRICTION_RULES_OPTION, []);
    }
}
