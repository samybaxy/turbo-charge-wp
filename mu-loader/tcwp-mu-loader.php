<?php
/**
 * Turbo Charge WP - MU-Plugin Loader (High Performance Edition)
 *
 * This file MUST be placed in wp-content/mu-plugins/ to work.
 * It intercepts plugin loading BEFORE WordPress loads regular plugins.
 *
 * PERFORMANCE OPTIMIZATIONS:
 * - Single database query for lookup table (O(1) amortized)
 * - Hash-based slug lookup (O(1))
 * - No regex in hot path for cached pages
 * - Minimal processing before WordPress loads
 *
 * @package TurboChargeWP
 * @version 5.2.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define constants FIRST so main plugin knows MU-loader is installed
if (!defined('TCWP_MU_LOADER_ACTIVE')) {
    define('TCWP_MU_LOADER_ACTIVE', true);
    define('TCWP_MU_LOADER_VERSION', '5.2.0');
}

// CRITICAL: Never filter on admin, AJAX, REST, CRON, CLI
// These checks are VERY fast (just constant checks)
if (is_admin()) {
    return;
}

if (defined('DOING_AJAX') && DOING_AJAX) {
    return;
}

if (defined('REST_REQUEST') && REST_REQUEST) {
    return;
}

if (defined('DOING_CRON') && DOING_CRON) {
    return;
}

if (defined('WP_CLI') && WP_CLI) {
    return;
}

// Fast URI checks for admin paths (string operations only, no regex)
$request_uri = $_SERVER['REQUEST_URI'] ?? '';
if (strpos($request_uri, '/wp-admin') !== false ||
    strpos($request_uri, '/wp-login') !== false ||
    strpos($request_uri, 'wp-activate.php') !== false ||
    strpos($request_uri, 'wp-signup.php') !== false ||
    strpos($request_uri, 'xmlrpc.php') !== false) {
    return;
}

// Fast action parameter check
$action = $_GET['action'] ?? $_POST['action'] ?? '';
if ($action && in_array($action, ['activate', 'deactivate', 'activate-selected', 'deactivate-selected'], true)) {
    return;
}

// Check if filtering is enabled (single DB query)
global $wpdb;
$enabled = $wpdb->get_var(
    $wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
        'tcwp_enabled'
    )
);

if ($enabled != '1') {
    return;
}

/**
 * High-Performance Plugin Filter
 *
 * Time Complexity:
 * - Lookup table fetch: O(1) amortized (cached in static)
 * - Slug extraction: O(n) where n = URL length (typically < 100 chars)
 * - Hash lookup: O(1)
 * - Plugin filtering: O(m) where m = number of active plugins
 *
 * Total: O(m) per request, which is optimal since we must iterate plugins anyway
 */
class TCWP_Early_Filter {

    private static $filtered = false;
    private static $original_count = 0;
    private static $filtered_count = 0;
    private static $essential_plugins = [];
    private static $loaded_plugins = [];
    private static $filtering_active = false;
    private static $detected_plugins = [];

    // Cache these for the entire request
    private static $lookup_table = null;
    private static $post_type_requirements = null;

    /**
     * Initialize early filtering
     */
    public static function init() {
        add_filter('option_active_plugins', [__CLASS__, 'filter_plugins'], 1, 1);
        add_filter('site_option_active_sitewide_plugins', [__CLASS__, 'filter_sitewide_plugins'], 1, 1);
        add_action('plugins_loaded', [__CLASS__, 'store_filter_data'], 1);
    }

    /**
     * Filter active plugins before WordPress loads them
     */
    public static function filter_plugins($plugins) {
        // Recursion guard
        if (self::$filtering_active) {
            return $plugins;
        }

        // Only filter once per request
        if (self::$filtered) {
            return self::$loaded_plugins;
        }

        if (!is_array($plugins)) {
            return $plugins;
        }

        self::$filtering_active = true;
        self::$original_count = count($plugins);

        try {
            // Step 1: Get essential plugins (O(1) - cached)
            self::$essential_plugins = self::get_essential_plugins();

            if (empty(self::$essential_plugins)) {
                self::$loaded_plugins = $plugins;
                self::$filtered_count = 0;
                self::$filtered = true;
                self::$filtering_active = false;
                return $plugins;
            }

            // Step 2: Detect page-specific requirements (O(1) lookup + O(n) URL parsing)
            self::$detected_plugins = self::detect_required_plugins_fast();

            // Step 3: Merge essential + detected
            $required_slugs = array_unique(array_merge(
                self::$essential_plugins,
                self::$detected_plugins
            ));

            // Always include turbo-charge-wp
            $required_slugs[] = 'turbo-charge-wp';

            // Step 4: Resolve dependencies (O(k) where k = required plugins)
            $to_load = self::resolve_dependencies($required_slugs, $plugins);

            // Step 5: Filter plugins (O(m) where m = active plugins)
            $to_load_set = array_flip($to_load); // Convert to set for O(1) lookup

            $filtered_plugins = [];
            foreach ($plugins as $plugin_path) {
                $slug = self::get_plugin_slug($plugin_path);
                if (isset($to_load_set[$slug]) || isset($to_load_set[$plugin_path])) {
                    $filtered_plugins[] = $plugin_path;
                }
            }

            // Safety: Never return fewer than 3 plugins
            if (count($filtered_plugins) < 3) {
                self::$loaded_plugins = $plugins;
                self::$filtered_count = 0;
            } else {
                self::$loaded_plugins = $filtered_plugins;
                self::$filtered_count = self::$original_count - count($filtered_plugins);
            }

            self::$filtered = true;
            self::$filtering_active = false;
            return self::$loaded_plugins;

        } catch (Exception $e) {
            self::$filtering_active = false;
            return $plugins;
        }
    }

    /**
     * Get essential plugins from database
     */
    private static function get_essential_plugins() {
        global $wpdb;

        $essential = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                'tcwp_essential_plugins'
            )
        );

        if ($essential) {
            $essential = maybe_unserialize($essential);
            if (is_array($essential) && !empty($essential)) {
                return $essential;
            }
        }

        // Fallback defaults
        return [
            'elementor', 'elementor-pro', 'jet-engine', 'jet-theme-core',
            'jet-menu', 'jet-blocks', 'jet-elements', 'header-footer-code-manager', 'nitropack'
        ];
    }

    /**
     * HIGH-PERFORMANCE page requirement detection
     *
     * Strategy:
     * 1. Extract URL slug (O(n) string operations)
     * 2. Check cached lookup table (O(1) hash lookup)
     * 3. Fallback to post type detection (O(1) hash lookup)
     * 4. Fallback to lightweight URL patterns (only if not cached)
     */
    private static function detect_required_plugins_fast() {
        global $wpdb;
        $detected = [];
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';

        // Normalize and extract slug (fast string operations)
        $uri = strtok($request_uri, '?');
        $uri = rtrim($uri, '/');
        $parts = explode('/', $uri);
        $slug = end($parts);

        // Parent slug for hierarchical pages
        $parent_slug = count($parts) > 1 ? $parts[count($parts) - 2] : '';

        // Step 1: Check pre-computed lookup table (O(1) hash lookup)
        $lookup = self::get_lookup_table();

        if (!empty($slug) && isset($lookup[$slug])) {
            return $lookup[$slug];
        }

        // Check by path for hierarchical pages
        $path_key = 'path:' . trim($uri, '/');
        if (isset($lookup[$path_key])) {
            return $lookup[$path_key];
        }

        // Step 2: Check post type from query vars (if available)
        if (isset($_GET['post_type'])) {
            $pt_requirements = self::get_post_type_requirements();
            $post_type = sanitize_key($_GET['post_type']);
            if (isset($pt_requirements[$post_type])) {
                $detected = array_merge($detected, $pt_requirements[$post_type]);
            }
        }

        // Step 3: Fast keyword detection (string operations, no regex for common paths)
        $detected = array_merge($detected, self::detect_from_keywords($uri, $slug, $parent_slug));

        // Step 4: User state detection
        if (self::is_user_logged_in_early()) {
            $detected[] = 'fluent-crm';
            $detected[] = 'fluentcrm-pro';
        }

        return array_unique($detected);
    }

    /**
     * Fast keyword-based detection (O(1) per keyword check)
     */
    private static function detect_from_keywords($uri, $slug, $parent_slug) {
        $detected = [];

        // WooCommerce keywords (O(1) each)
        static $woo_keywords = ['shop', 'product', 'products', 'cart', 'checkout', 'my-account', 'order-received', 'order-pay'];
        if (in_array($slug, $woo_keywords, true) || in_array($parent_slug, $woo_keywords, true)) {
            $detected[] = 'woocommerce';
            $detected[] = 'woocommerce-stripe-gateway';
            $detected[] = 'woocommerce-gateway-stripe';
            $detected[] = 'jet-woo-builder';
            $detected[] = 'jet-woo-product-gallery';

            // Additional for checkout/cart
            if ($slug === 'checkout' || $slug === 'cart') {
                $detected[] = 'woocommerce-memberships';
                $detected[] = 'woocommerce-subscriptions';
                $detected[] = 'woocommerce-smart-coupons';
            }
        }

        // LearnPress keywords
        static $lp_keywords = ['courses', 'course', 'lessons', 'lesson', 'quiz', 'quizzes', 'instructor', 'become-instructor'];
        if (in_array($slug, $lp_keywords, true) || in_array($parent_slug, $lp_keywords, true) || strpos($uri, '/lp-') !== false) {
            $detected[] = 'learnpress';
        }

        // Membership keywords
        static $member_keywords = ['members', 'member', 'account', 'subscription', 'register', 'login', 'profile', 'dashboard'];
        if (in_array($slug, $member_keywords, true) || in_array($parent_slug, $member_keywords, true)) {
            $detected[] = 'restrict-content-pro';
            $detected[] = 'rcp-content-filter-utility';
        }

        // Affiliate keywords
        static $affiliate_keywords = ['affiliate', 'affiliates', 'referral', 'partner'];
        if (in_array($slug, $affiliate_keywords, true)) {
            $detected[] = 'affiliatewp';
            $detected[] = 'affiliate-wp';
        }

        // Form keywords
        static $form_keywords = ['contact', 'form', 'apply', 'submit', 'booking', 'appointment', 'schedule'];
        if (in_array($slug, $form_keywords, true)) {
            $detected[] = 'fluentform';
            $detected[] = 'fluentformpro';
            $detected[] = 'jetformbuilder';
        }

        // Blog/Archive keywords
        static $blog_keywords = ['blog', 'news', 'articles', 'article', 'posts'];
        if (in_array($slug, $blog_keywords, true) || strpos($uri, '/category/') !== false || strpos($uri, '/tag/') !== false) {
            $detected[] = 'jet-blog';
            $detected[] = 'jet-smart-filters';
        }

        // Search detection
        if (isset($_GET['s']) || strpos($uri, '/search/') !== false) {
            $detected[] = 'jet-search';
            $detected[] = 'jet-smart-filters';
        }

        // Events keywords
        static $event_keywords = ['events', 'event', 'calendar', 'tribe-events'];
        if (in_array($slug, $event_keywords, true) || in_array($parent_slug, $event_keywords, true)) {
            $detected[] = 'the-events-calendar';
        }

        // Forum keywords
        static $forum_keywords = ['forums', 'forum', 'topics', 'topic', 'community', 'discussion'];
        if (in_array($slug, $forum_keywords, true)) {
            $detected[] = 'bbpress';
        }

        return $detected;
    }

    /**
     * Get lookup table (cached for request lifetime)
     */
    private static function get_lookup_table() {
        if (self::$lookup_table !== null) {
            return self::$lookup_table;
        }

        global $wpdb;

        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                'tcwp_url_requirements'
            )
        );

        if ($result) {
            self::$lookup_table = maybe_unserialize($result);
            if (is_array(self::$lookup_table)) {
                return self::$lookup_table;
            }
        }

        self::$lookup_table = [];
        return self::$lookup_table;
    }

    /**
     * Get post type requirements (cached for request lifetime)
     */
    private static function get_post_type_requirements() {
        if (self::$post_type_requirements !== null) {
            return self::$post_type_requirements;
        }

        // Default mappings (no DB query needed)
        self::$post_type_requirements = [
            'product' => ['woocommerce', 'jet-woo-builder', 'jet-woo-product-gallery'],
            'lp_course' => ['learnpress'],
            'lp_lesson' => ['learnpress'],
            'lp_quiz' => ['learnpress'],
            'tribe_events' => ['the-events-calendar'],
            'forum' => ['bbpress'],
            'topic' => ['bbpress'],
        ];

        return self::$post_type_requirements;
    }

    /**
     * Check if user is logged in (cookie check - O(n) where n = cookie count)
     */
    private static function is_user_logged_in_early() {
        foreach ($_COOKIE as $name => $value) {
            if (strpos($name, 'wordpress_logged_in_') === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve plugin dependencies (O(k) where k = required plugins)
     */
    private static function resolve_dependencies($required_slugs, $active_plugins) {
        // Dependency map
        static $dependencies = [
            'jet-menu' => ['jet-engine'],
            'jet-blocks' => ['jet-engine'],
            'jet-elements' => ['jet-engine'],
            'jet-tabs' => ['jet-engine'],
            'jet-popup' => ['jet-engine'],
            'jet-blog' => ['jet-engine'],
            'jet-search' => ['jet-engine'],
            'jet-reviews' => ['jet-engine'],
            'jet-smart-filters' => ['jet-engine'],
            'jet-compare-wishlist' => ['jet-engine'],
            'jet-tricks' => ['jet-engine'],
            'jet-woo-builder' => ['jet-engine', 'woocommerce'],
            'jet-woo-product-gallery' => ['jet-engine', 'woocommerce'],
            'jet-theme-core' => ['jet-engine'],
            'jetformbuilder' => ['jet-engine'],
            'elementor-pro' => ['elementor'],
            'the-plus-addons-for-elementor-page-builder' => ['elementor'],
            'thim-elementor-kit' => ['elementor'],
            'woocommerce-memberships' => ['woocommerce'],
            'woocommerce-subscriptions' => ['woocommerce'],
            'woocommerce-product-bundles' => ['woocommerce'],
            'woocommerce-smart-coupons' => ['woocommerce'],
            'woocommerce-stripe-gateway' => ['woocommerce'],
            'woocommerce-gateway-stripe' => ['woocommerce'],
            'rcp-content-filter-utility' => ['restrict-content-pro'],
            'fluentformpro' => ['fluentform'],
            'fluent-forms-pro' => ['fluent-forms'],
            'fluentcrm-pro' => ['fluent-crm'],
            'uncanny-automator-pro' => ['uncanny-automator'],
        ];

        // Reverse dependencies
        static $reverse_deps = [
            'jet-engine' => ['jet-menu', 'jet-blocks', 'jet-theme-core'],
            'elementor' => ['elementor-pro'],
        ];

        // Build active plugins set for O(1) lookup
        $active_set = [];
        foreach ($active_plugins as $plugin_path) {
            $slug = self::get_plugin_slug($plugin_path);
            $active_set[$slug] = true;
        }

        $to_load = [];
        $queue = $required_slugs;

        while (!empty($queue)) {
            $slug = array_shift($queue);

            if (isset($to_load[$slug])) {
                continue;
            }

            $to_load[$slug] = true;

            // Add dependencies
            if (isset($dependencies[$slug])) {
                foreach ($dependencies[$slug] as $dep) {
                    if (!isset($to_load[$dep])) {
                        $queue[] = $dep;
                    }
                }
            }

            // Add reverse dependencies (if active)
            if (isset($reverse_deps[$slug])) {
                foreach ($reverse_deps[$slug] as $rdep) {
                    if (!isset($to_load[$rdep]) && isset($active_set[$rdep])) {
                        $queue[] = $rdep;
                    }
                }
            }
        }

        return array_keys($to_load);
    }

    /**
     * Extract plugin slug from path (O(n) where n = path length)
     */
    private static function get_plugin_slug($plugin_path) {
        $pos = strpos($plugin_path, '/');
        return $pos !== false ? substr($plugin_path, 0, $pos) : $plugin_path;
    }

    /**
     * Filter sitewide plugins for multisite
     */
    public static function filter_sitewide_plugins($plugins) {
        if (!is_array($plugins)) {
            return $plugins;
        }

        $plugin_list = array_keys($plugins);
        $filtered = self::filter_plugins($plugin_list);

        $result = [];
        foreach ($filtered as $plugin) {
            if (isset($plugins[$plugin])) {
                $result[$plugin] = $plugins[$plugin];
            }
        }

        return $result;
    }

    /**
     * Store filter data for main plugin
     */
    public static function store_filter_data() {
        $GLOBALS['tcwp_mu_filter_data'] = [
            'filtered' => self::$filtered,
            'original_count' => self::$original_count,
            'filtered_count' => self::$filtered_count,
            'loaded_plugins' => self::$loaded_plugins,
            'essential_plugins' => self::$essential_plugins,
            'detected_plugins' => self::$detected_plugins,
            'reduction_percent' => self::$original_count > 0
                ? round((self::$filtered_count / self::$original_count) * 100, 1)
                : 0
        ];
    }

    /**
     * Get filter statistics
     */
    public static function get_stats() {
        return [
            'original_count' => self::$original_count,
            'loaded_count' => count(self::$loaded_plugins),
            'filtered_count' => self::$filtered_count,
            'reduction_percent' => self::$original_count > 0
                ? round((self::$filtered_count / self::$original_count) * 100, 1)
                : 0,
            'essential_plugins' => self::$essential_plugins,
            'detected_plugins' => self::$detected_plugins,
            'loaded_plugins' => self::$loaded_plugins
        ];
    }
}

// Initialize early filtering
TCWP_Early_Filter::init();
