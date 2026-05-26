<?php
/**
 * Plugin Name: Samybaxy's Hyperdrive - MU Loader
 * Plugin URI: https://github.com/samybaxy/samybaxy-hyperdrive
 * Description: High-performance plugin filter using blacklist architecture. Loads everything by default, only restricts known-heavy plugins when not needed. Requires the main Samybaxy's Hyperdrive plugin.
 * Version: 6.1.4
 * Author: samybaxy
 * Author URI: https://github.com/samybaxy
 * License: GPL v2 or later
 *
 * This file MUST be placed in wp-content/mu-plugins/ to work.
 *
 * ARCHITECTURE (v6.2.0 - Blacklist Model + Single-Payload Read):
 * - Loads ALL plugins by default (safe)
 * - Only restricts plugins in the "restrictable set" (built by scanner)
 * - Restrictable plugins load when their URL/content conditions match
 * - Lightweight plugins, page builders, utilities always load
 * - No hardcoded plugin lists — everything is DB-driven
 * - Yields control to page-cache plugins during warmups so cached HTML
 *   reflects a full plugin set
 *
 * PERFORMANCE:
 * - 1 get_option() read for the combined shypdr_mu_payload (autoloaded —
 *   piggybacks on the alloptions cache; zero extra DB queries)
 * - O(1) hash lookups for URL matching
 * - O(m) plugin filtering where m = active plugins
 *
 * @package SamybaxyHyperdrive
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define constants FIRST so main plugin knows MU-loader is installed
if (!defined('SHYPDR_MU_LOADER_ACTIVE')) {
    define('SHYPDR_MU_LOADER_ACTIVE', true);
    define('SHYPDR_MU_LOADER_VERSION', '6.1.4');
}

// CRITICAL: Never filter on admin, AJAX, REST, CRON, CLI
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

// Fast URI checks for admin paths, REST API, and AJAX (string operations only, no regex)
// CRITICAL: REST API and AJAX must bypass filtering for checkout payment gateways
// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Read-only early detection, no actions performed
$shypdr_request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
if (strpos($shypdr_request_uri, '/wp-admin') !== false ||
    strpos($shypdr_request_uri, '/wp-login') !== false ||
    strpos($shypdr_request_uri, '/wp-json/') !== false ||
    strpos($shypdr_request_uri, 'rest_route=') !== false ||
    strpos($shypdr_request_uri, 'admin-ajax.php') !== false ||
    strpos($shypdr_request_uri, 'wc-ajax=') !== false ||
    strpos($shypdr_request_uri, 'wp-activate.php') !== false ||
    strpos($shypdr_request_uri, 'wp-signup.php') !== false ||
    strpos($shypdr_request_uri, 'xmlrpc.php') !== false) {
    return;
}

// Fast action parameter check
// phpcs:ignore WordPress.Security.NonceVerification -- Read-only early detection, prevents plugin loading conflicts
$shypdr_action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : (isset($_POST['action']) ? sanitize_key(wp_unslash($_POST['action'])) : '');
if ($shypdr_action && in_array($shypdr_action, ['activate', 'deactivate', 'activate-selected', 'deactivate-selected'], true)) {
    return;
}

// Cache-plugin coexistence: when a page cache is mid-warmup, return the
// full plugin set so the cached HTML reflects every feature a real visitor
// could need. Without this, warmers and on-demand visits diverge — the
// cache plugin warms a "minimal" page that lacks plugins the actual
// visitor needed, causing cascading cache misses.
$shypdr_warming = false;

if (defined('WP_IMPORTING') && WP_IMPORTING) {
    $shypdr_warming = true;
}
if (defined('WPSC_CACHE_PRELOAD_IN_PROGRESS') && WPSC_CACHE_PRELOAD_IN_PROGRESS) {
    $shypdr_warming = true;
}
if (defined('WP_ROCKET_PRELOAD') && WP_ROCKET_PRELOAD) {
    $shypdr_warming = true;
}
if (defined('LSCACHE_IS_ESI') && LSCACHE_IS_ESI) {
    // LiteSpeed ESI requests should not be filtered.
    $shypdr_warming = true;
}

if (!$shypdr_warming && isset($_SERVER['HTTP_USER_AGENT'])) {
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Read-only UA sniff
    $shypdr_ua = (string) $_SERVER['HTTP_USER_AGENT'];
    $shypdr_warming_uas = [
        'WP Rocket/Preload',
        'wprocket',
        'lscache_runner',
        'lscache_walker',
        'lsrunner',
        'lscache_runner',
        'Cache Crawler',
        'cache-warmer',
        'Super Cache Preload',
        'NitroPack',
        'ShortPixel',
    ];
    foreach ($shypdr_warming_uas as $shypdr_needle) {
        if (stripos($shypdr_ua, $shypdr_needle) !== false) {
            $shypdr_warming = true;
            break;
        }
    }
}

if ($shypdr_warming) {
    return;
}

// Safety net: the MU-loader file may survive a deactivation (e.g., the
// mu-plugins directory wasn't writable, or it was placed manually).
// WordPress loads MU-plugins unconditionally, so without this check a
// deactivated Hyperdrive could keep filtering plugin loads. We bail
// unless the main plugin is currently in active_plugins (or, on
// multisite, in active_sitewide_plugins). active_plugins is autoloaded
// so this is a cache lookup — zero extra DB queries.
$shypdr_main_basename = 'samybaxy-hyperdrive/samybaxy-hyperdrive.php';
$shypdr_main_is_active = in_array(
    $shypdr_main_basename,
    (array) get_option('active_plugins', []),
    true
);

if (!$shypdr_main_is_active && function_exists('is_multisite') && is_multisite()) {
    $shypdr_network_plugins = (array) get_site_option('active_sitewide_plugins', []);
    if (isset($shypdr_network_plugins[$shypdr_main_basename])) {
        $shypdr_main_is_active = true;
    }
}

if (!$shypdr_main_is_active) {
    return;
}

// Pull the combined MU-loader payload. It's autoloaded, so this read
// rides on the same alloptions query WordPress was going to do anyway —
// no extra DB hit. The five separate $wpdb->get_var() calls used in
// 6.1.x have been collapsed into this single get_option() call.
$shypdr_payload = get_option('shypdr_mu_payload', null);

if (!is_array($shypdr_payload) || empty($shypdr_payload['enabled'])) {
    // Legacy fallback for installs upgrading from 6.1.x before the
    // first admin page view has rebuilt the payload. After the main
    // plugin's upgrade hook fires once, this branch is never used.
    $shypdr_payload_enabled = get_option('shypdr_enabled', false);
    if (!$shypdr_payload_enabled) {
        return;
    }
    $shypdr_payload = [
        'enabled'      => true,
        'restrictable' => get_option('shypdr_restrictable_plugins', []),
        'rules'        => get_option('shypdr_restriction_rules', []),
        'lookup'       => get_option('shypdr_url_requirements', []),
        'dep_map'      => get_option('shypdr_dependency_map', []),
    ];
}

/**
 * Blacklist-Based Plugin Filter
 *
 * Instead of whitelisting what to load, this blacklists what to RESTRICT.
 * Everything loads by default. Only known-heavy ecosystems get conditionally
 * restricted when their page conditions aren't met.
 *
 * Data sources (all DB-driven, built by main plugin's scanner):
 * - shypdr_restrictable_plugins: slugs that CAN be restricted
 * - shypdr_restriction_rules: ecosystem => conditions for loading
 * - shypdr_url_requirements: per-page plugin requirements (content-analyzed)
 * - shypdr_dependency_map: plugin dependency graph
 */
class SHYPDR_Early_Filter {

    private static $filtered = false;
    private static $original_count = 0;
    private static $filtered_count = 0;
    private static $loaded_plugins = [];
    private static $filtering_active = false;
    private static $restricted_plugins = [];
    private static $needed_plugins = [];
    private static $original_plugins = [];

    // DB-driven caches (populated once per request)
    private static $restrictable_set = null;
    private static $restriction_rules = null;
    private static $lookup_table = null;
    private static $dependency_map = null;

    /**
     * Initialize early filtering
     */
    public static function init() {
        add_filter('option_active_plugins', [__CLASS__, 'filter_plugins'], 1, 1);
        add_filter('site_option_active_sitewide_plugins', [__CLASS__, 'filter_sitewide_plugins'], 1, 1);
        add_action('plugins_loaded', [__CLASS__, 'store_filter_data'], 1);
    }

    /**
     * Filter active plugins using blacklist architecture
     *
     * Logic: load everything EXCEPT restrictable plugins whose
     * page conditions are NOT met.
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
        self::$original_plugins = $plugins;

        try {
            // Step 1: Get restrictable set from DB
            $restrictable = self::get_restrictable_set();

            // SAFE DEFAULT: No restrictable set = no filtering
            if (empty($restrictable)) {
                self::$loaded_plugins = $plugins;
                self::$filtered_count = 0;
                self::$filtered = true;
                self::$filtering_active = false;
                return $plugins;
            }

            // Step 2: Determine which restrictable plugins ARE needed on this page
            $needed = self::detect_needed_plugins();

            // Step 3: Expand needed set with dependencies
            // If woocommerce is needed, all its children are also needed
            $needed = self::expand_with_children($needed, $plugins);

            self::$needed_plugins = $needed;

            // Step 4: Build restriction set = restrictable - needed
            $restrictable_flip = array_flip($restrictable);
            $needed_flip = array_flip($needed);
            $restrict_set = array_diff_key($restrictable_flip, $needed_flip);

            // Step 5: Filter — remove only restricted plugins, keep everything else
            $filtered_plugins = [];
            $restricted_list = [];
            foreach ($plugins as $plugin_path) {
                $slug = self::get_plugin_slug($plugin_path);
                if (isset($restrict_set[$slug])) {
                    $restricted_list[] = $slug;
                } else {
                    $filtered_plugins[] = $plugin_path;
                }
            }

            self::$restricted_plugins = $restricted_list;

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

            // Prevent persistent object caches (e.g., WP Engine Redis) from
            // storing the filtered list under the 'active_plugins' key. Without
            // this, a subsequent admin request that hits the cache gets the
            // reduced list, making filtered plugins appear fully deactivated.
            wp_cache_delete('active_plugins', 'options');
            wp_cache_delete('alloptions', 'options');

            return self::$loaded_plugins;

        } catch (Exception $e) {
            self::$filtering_active = false;
            return $plugins;
        }
    }

    /**
     * Detect which restrictable plugins are needed on this page.
     *
     * Checks:
     * 1. URL lookup table (content-analyzed per-page requirements)
     * 2. Restriction rules (keyword/URL matching)
     * 3. Search query detection
     *
     * @return array Plugin slugs needed on this page
     */
    private static function detect_needed_plugins() {
        $needed = [];
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Read-only URL parsing for plugin detection
        $request_uri = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';

        // Normalize and extract slug
        $uri = strtok($request_uri, '?');
        $uri = rtrim($uri, '/');
        $parts = explode('/', $uri);
        $slug = end($parts);
        $parent_slug = count($parts) > 1 ? $parts[count($parts) - 2] : '';

        // --- Source 1: Content-analyzed lookup table ---
        $lookup = self::get_lookup_table();

        // Homepage detection
        if (empty($slug) && (empty($uri) || $uri === '/')) {
            foreach (['home', 'front-page', 'homepage', 'frontpage'] as $fp_slug) {
                if (isset($lookup[$fp_slug])) {
                    $needed = array_merge($needed, $lookup[$fp_slug]);
                }
            }
        }

        // Slug match
        if (!empty($slug) && isset($lookup[$slug])) {
            $needed = array_merge($needed, $lookup[$slug]);
        }

        // Path match for hierarchical pages
        $path_key = 'path:' . trim($uri, '/');
        if (isset($lookup[$path_key])) {
            $needed = array_merge($needed, $lookup[$path_key]);
        }

        // --- Source 2: Restriction rules (keyword matching) ---
        $rules = self::get_restriction_rules();

        foreach ($rules as $ecosystem_slug => $rule) {
            // Skip logged_in_only rules for logged-out users
            if (!empty($rule['logged_in_only']) && !self::is_user_logged_in_early()) {
                continue;
            }

            // Check keywords against URI
            if (!empty($rule['keywords'])) {
                if (self::uri_matches_keywords($uri, $slug, $parent_slug, $rule['keywords'])) {
                    $needed[] = $ecosystem_slug;
                }
            }
        }

        // --- Source 3: Search detection ---
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only plugin detection
        if (isset($_GET['s']) || strpos($uri, '/search/') !== false) {
            // Search pages may need various ecosystems — load all restrictable
            // that have content on the site. This is a broad match but safe.
            $needed = array_merge($needed, array_keys($rules));
        }

        return array_unique($needed);
    }

    /**
     * Expand a "needed" set to include all ecosystem children.
     *
     * If woocommerce is needed, also include woocommerce-subscriptions,
     * woocommerce-memberships, jet-woo-builder, etc.
     *
     * Uses the dependency map to resolve parent→child relationships.
     *
     * @param array $needed Plugin slugs that are needed
     * @param array $active_plugins Full list of active plugin paths
     * @return array Expanded needed set
     */
    private static function expand_with_children($needed, $active_plugins) {
        $dep_map = self::get_dependency_map();
        $needed_set = array_flip($needed);

        // Build active slugs set
        $active_slugs = [];
        foreach ($active_plugins as $plugin_path) {
            $active_slugs[] = self::get_plugin_slug($plugin_path);
        }
        $active_set = array_flip($active_slugs);

        // For each needed ecosystem parent, find children via dependency map
        // A child is any plugin whose depends_on includes a needed parent
        foreach ($dep_map as $child_slug => $data) {
            if (isset($needed_set[$child_slug])) {
                continue; // Already needed
            }
            if (!isset($active_set[$child_slug])) {
                continue; // Not active
            }
            if (!empty($data['depends_on'])) {
                foreach ($data['depends_on'] as $dep) {
                    if (isset($needed_set[$dep])) {
                        $needed_set[$child_slug] = true;
                        break;
                    }
                }
            }
        }

        // Also do prefix-based child detection for plugins not in the dep map
        // e.g., "woocommerce-subscriptions" starts with "woocommerce-"
        foreach ($active_slugs as $active_slug) {
            if (isset($needed_set[$active_slug])) {
                continue;
            }
            foreach ($needed as $parent) {
                if (strpos($active_slug, $parent . '-') === 0 ||
                    strpos($active_slug, $parent . '_') === 0) {
                    $needed_set[$active_slug] = true;
                    break;
                }
            }
            // Also check woo-/wc- prefixes for WooCommerce children
            if (isset($needed_set['woocommerce'])) {
                if (strpos($active_slug, 'woo-') === 0 || strpos($active_slug, 'wc-') === 0) {
                    $needed_set[$active_slug] = true;
                }
                if (strpos($active_slug, 'jet-woo') === 0) {
                    $needed_set[$active_slug] = true;
                }
            }
        }

        return array_keys($needed_set);
    }

    /**
     * Check if URI matches any keyword (handles nested paths)
     *
     * @param string $uri Full URI path
     * @param string $slug Last segment of URI
     * @param string $parent_slug Second-to-last segment
     * @param array  $keywords Keywords to check
     * @return bool
     */
    private static function uri_matches_keywords($uri, $slug, $parent_slug, $keywords) {
        if (in_array($slug, $keywords, true) || in_array($parent_slug, $keywords, true)) {
            return true;
        }

        foreach ($keywords as $keyword) {
            if (strpos($uri, '/' . $keyword) !== false ||
                strpos($uri, $keyword . '/') !== false ||
                $uri === $keyword) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user is logged in (cookie check)
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
     * Inject the pre-built MU-payload so the getters skip any further
     * DB work. Called once at the bottom of this file after the
     * single get_option() read.
     */
    public static function set_payload(array $payload) {
        self::$restrictable_set = isset($payload['restrictable']) && is_array($payload['restrictable'])
            ? $payload['restrictable'] : [];
        self::$restriction_rules = isset($payload['rules']) && is_array($payload['rules'])
            ? $payload['rules'] : [];
        self::$lookup_table = isset($payload['lookup']) && is_array($payload['lookup'])
            ? $payload['lookup'] : [];
        self::$dependency_map = isset($payload['dep_map']) && is_array($payload['dep_map'])
            ? $payload['dep_map'] : [];
    }

    private static function get_restrictable_set() {
        return self::$restrictable_set ?? [];
    }

    private static function get_restriction_rules() {
        return self::$restriction_rules ?? [];
    }

    private static function get_lookup_table() {
        return self::$lookup_table ?? [];
    }

    private static function get_dependency_map() {
        return self::$dependency_map ?? [];
    }

    /**
     * Extract plugin slug from path
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
        $GLOBALS['shypdr_mu_filter_data'] = [
            'filtered' => self::$filtered,
            'original_count' => self::$original_count,
            'filtered_count' => self::$filtered_count,
            'loaded_plugins' => self::$loaded_plugins,
            'restricted_plugins' => self::$restricted_plugins,
            'needed_plugins' => self::$needed_plugins,
            'original_plugins' => self::$original_plugins,
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
            'restricted_plugins' => self::$restricted_plugins,
            'needed_plugins' => self::$needed_plugins,
            'loaded_plugins' => self::$loaded_plugins
        ];
    }
}

// Inject the pre-built payload and initialize early filtering.
SHYPDR_Early_Filter::set_payload($shypdr_payload);
SHYPDR_Early_Filter::init();
