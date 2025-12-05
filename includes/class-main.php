<?php
/**
 * Main plugin class for Turbo Charge WP
 *
 * @package TurboChargeWP
 */

if (!defined('ABSPATH')) {
    exit;
}

class TurboChargeWP_Main {
    private static $instance = null;
    private static $enabled = false;
    private static $dependency_map = [];
    private static $reverse_deps = [];
    private static $log_messages = [];
    private static $filtering_in_progress = false;  // Prevent recursion
    private static $essential_plugins_cache = null; // Cache for essential plugins

    /**
     * Get essential plugins list (dynamic, from database or scanner)
     * Replaces hardcoded whitelist with intelligent scanning
     *
     * @return array Essential plugin slugs
     */
    private static function get_essential_plugins() {
        // Use cached value if available in this request
        if (self::$essential_plugins_cache !== null) {
            return self::$essential_plugins_cache;
        }

        // Allow filtering for custom implementations
        $essential = apply_filters('tcwp_essential_plugins', null);

        if ($essential === null) {
            // Get from scanner (uses cached analysis if available)
            $essential = TurboChargeWP_Plugin_Scanner::get_essential_plugins();
        }

        // Fallback to safe defaults if scanner returns empty
        if (empty($essential)) {
            $essential = ['elementor', 'jet-engine', 'jet-theme-core'];
        }

        // Cache for this request
        self::$essential_plugins_cache = $essential;

        return $essential;
    }

    /**
     * Initialize the plugin
     */
    public static function init() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
            self::$instance->setup();
        }
    }

    /**
     * Setup plugin hooks and components
     */
    private function setup() {
        // Check if plugin filtering is enabled
        self::$enabled = get_option('tcwp_enabled', false);

        // Load dependency map
        self::load_dependency_map();

        // Setup admin hooks
        if (is_admin()) {
            add_action('admin_menu', [$this, 'register_admin_menu']);
            add_action('admin_init', [$this, 'register_settings']);
            add_action('admin_init', [$this, 'handle_clear_logs_request']);
        }

        // Frontend filtering - only if enabled and not admin/AJAX/REST
        if (!is_admin() && !defined('DOING_AJAX') && !defined('REST_REQUEST')) {
            add_filter('option_active_plugins', [$this, 'filter_plugins'], 10, 1);
        }

        // Load debug widget on frontend if enabled (admin only for security)
        if (!is_admin() && get_option('tcwp_debug_enabled', false) && current_user_can('manage_options')) {
            add_action('wp_footer', [$this, 'render_debug_widget']);
            add_action('wp_enqueue_scripts', [$this, 'enqueue_debug_assets']);
        }

        // Cache invalidation hooks
        add_action('save_post', [$this, 'clear_post_cache'], 10, 1);
        add_action('activated_plugin', [$this, 'clear_all_detection_cache']);
        add_action('deactivated_plugin', [$this, 'clear_all_detection_cache']);
    }

    /**
     * Clear post-specific cache when post is saved
     *
     * @param int $post_id Post ID
     */
    public function clear_post_cache($post_id) {
        // Clear content scan cache for this post
        TurboChargeWP_Detection_Cache::clear_post_cache($post_id);
    }

    /**
     * Clear all detection caches when plugins change
     */
    public function clear_all_detection_cache() {
        TurboChargeWP_Detection_Cache::clear_all_caches();
        self::$essential_plugins_cache = null; // Clear request cache
    }

    /**
     * Load the dependency map for all supported plugins
     */
    private static function load_dependency_map() {
        self::$dependency_map = [
            // JetEngine Ecosystem
            'jet-menu' => [
                'depends_on' => ['jet-engine'],
                'plugins_depending' => [],
            ],
            'jet-engine' => [
                'depends_on' => [],
                'plugins_depending' => [
                    'jet-menu', 'jet-blocks', 'jet-elements', 'jet-tabs', 'jet-popup',
                    'jet-blog', 'jet-search', 'jet-reviews', 'jet-smart-filters',
                    'jet-compare-wishlist', 'jet-style-manager', 'jet-tricks',
                    'jetformbuilder', 'jet-woo-product-gallery', 'jet-woo-builder',
                    'jet-theme-core', 'crocoblock-wizard',
                    'jet-engine-custom-visibility-conditions', 'jet-engine-dynamic-charts-module',
                    'jet-engine-dynamic-tables-module', 'jet-engine-items-number-filter',
                    'jet-engine-layout-switcher', 'jet-engine-post-expiration-period',
                    'jet-engine-attachment-link-callback', 'jet-engine-trim-callback'
                ],
            ],
            'jet-theme-core' => [
                'depends_on' => ['jet-engine'],
                'plugins_depending' => [],
            ],
            'jet-blocks' => [
                'depends_on' => ['jet-engine'],
                'plugins_depending' => [],
            ],
            'jet-elements' => [
                'depends_on' => ['jet-engine'],
                'plugins_depending' => [],
            ],
            'jet-tabs' => [
                'depends_on' => ['jet-engine'],
                'plugins_depending' => [],
            ],
            'jet-popup' => [
                'depends_on' => ['jet-engine'],
                'plugins_depending' => [],
            ],
            'jet-blog' => [
                'depends_on' => ['jet-engine'],
                'plugins_depending' => [],
            ],
            'jet-search' => [
                'depends_on' => ['jet-engine'],
                'plugins_depending' => [],
            ],
            'jet-reviews' => [
                'depends_on' => ['jet-engine'],
                'plugins_depending' => [],
            ],
            'jet-smart-filters' => [
                'depends_on' => ['jet-engine'],
                'plugins_depending' => [],
            ],
            'jet-compare-wishlist' => [
                'depends_on' => ['jet-engine'],
                'plugins_depending' => [],
            ],
            'jet-woo-builder' => [
                'depends_on' => ['jet-engine', 'woocommerce'],
                'plugins_depending' => [],
            ],
            'crocoblock-wizard' => [
                'depends_on' => ['jet-engine'],
                'plugins_depending' => [
                    'jet-engine-custom-visibility-conditions',
                    'jet-engine-dynamic-charts-module',
                    'jet-engine-dynamic-tables-module',
                    'jet-engine-items-number-filter',
                    'jet-engine-layout-switcher',
                    'jet-engine-post-expiration-period'
                ],
            ],

            // WooCommerce Ecosystem
            'woocommerce' => [
                'depends_on' => [],
                'plugins_depending' => [
                    'woocommerce-memberships', 'woocommerce-subscriptions',
                    'woocommerce-product-bundles', 'woocommerce-smart-coupons',
                    'jet-woo-builder', 'jet-woo-product-gallery'
                ],
            ],
            'woocommerce-memberships' => [
                'depends_on' => ['woocommerce'],
                'plugins_depending' => [],
            ],
            'woocommerce-subscriptions' => [
                'depends_on' => ['woocommerce'],
                'plugins_depending' => [],
            ],
            'woocommerce-product-bundles' => [
                'depends_on' => ['woocommerce'],
                'plugins_depending' => [],
            ],
            'woocommerce-smart-coupons' => [
                'depends_on' => ['woocommerce'],
                'plugins_depending' => [],
            ],

            // Elementor Ecosystem
            'elementor' => [
                'depends_on' => [],
                'plugins_depending' => ['elementor-pro', 'the-plus-addons-for-elementor-page-builder', 'thim-elementor-kit'],
            ],
            'elementor-pro' => [
                'depends_on' => ['elementor'],
                'plugins_depending' => [],
            ],
            'the-plus-addons-for-elementor-page-builder' => [
                'depends_on' => ['elementor'],
                'plugins_depending' => [],
            ],
            'thim-elementor-kit' => [
                'depends_on' => ['elementor'],
                'plugins_depending' => [],
            ],

            // Content Restriction Ecosystem
            'restrict-content-pro' => [
                'depends_on' => [],
                'plugins_depending' => ['rcp-content-filter-utility', 'uncanny-automator-restrict-content'],
            ],
            'rcp-content-filter-utility' => [
                'depends_on' => ['restrict-content-pro'],
                'plugins_depending' => [],
            ],
            'uncanny-automator-restrict-content' => [
                'depends_on' => ['restrict-content-pro', 'uncanny-automator'],
                'plugins_depending' => [],
            ],

            // Automation Ecosystem
            'uncanny-automator' => [
                'depends_on' => [],
                'plugins_depending' => ['uncanny-automator-pro', 'uncanny-automator-restrict-content'],
            ],
            'uncanny-automator-pro' => [
                'depends_on' => ['uncanny-automator'],
                'plugins_depending' => [],
            ],
            'fluent-crm' => [
                'depends_on' => [],
                'plugins_depending' => [],
            ],

            // Form Ecosystem
            'fluentform' => [
                'depends_on' => [],
                'plugins_depending' => ['fluentformpro'],
            ],
            'fluentformpro' => [
                'depends_on' => ['fluentform'],
                'plugins_depending' => [],
            ],

            // Other plugins
            'learnpress' => [
                'depends_on' => [],
                'plugins_depending' => [],
            ],
            'affiliate-wp' => [
                'depends_on' => [],
                'plugins_depending' => [],
            ],
            'embedpress' => [
                'depends_on' => [],
                'plugins_depending' => [],
            ],
            'presto-player' => [
                'depends_on' => [],
                'plugins_depending' => [],
            ],
        ];

        // Build reverse dependency index for quick lookups
        self::build_reverse_deps();
    }

    /**
     * Build reverse dependency index
     */
    private static function build_reverse_deps() {
        self::$reverse_deps = [];
        foreach (self::$dependency_map as $plugin => $data) {
            self::$reverse_deps[$plugin] = $data['plugins_depending'];
        }
    }

    /**
     * Filter active plugins before WordPress loads them
     *
     * @param array $plugins List of active plugins
     * @return array Filtered list of plugins
     */
    public function filter_plugins($plugins) {
        // CRITICAL: Prevent infinite recursion by checking if we're already filtering
        if (self::$filtering_in_progress) {
            return $plugins;
        }

        // CRITICAL: Ensure $plugins is actually an array
        if (!is_array($plugins)) {
            return is_array($plugins) ? $plugins : [];
        }

        // Safety: Never filter if disabled
        if (!self::$enabled) {
            return $plugins;
        }

        // DIAGNOSTIC: Allow ?tcwp_debug_no_filter=1 to disable filtering on current page
        if (isset($_GET['tcwp_debug_no_filter']) && $_GET['tcwp_debug_no_filter'] === '1') {
            return $plugins;
        }

        // Set flag to prevent recursion
        self::$filtering_in_progress = true;

        try {
            // Detect essential plugins for this request
            $essential = $this->detect_essential_plugins($plugins);

            // Resolve all dependencies recursively
            $to_load = $this->resolve_dependencies($essential, $plugins);

            // Validate and return
            $filtered = $this->validate_and_prepare($to_load, $plugins);

            // Log the filtering
            $this->log_filter_result($plugins, $filtered, $essential, $to_load);

            return $filtered;
        } catch (Exception $e) {
            // Safety fallback: return all plugins on any error
            return $plugins;
        } finally {
            // Always reset the flag
            self::$filtering_in_progress = false;
        }
    }

    /**
     * Detect which plugins are essential for this request
     *
     * @param array $active_plugins List of active plugins
     * @return array List of essential plugins
     */
    private function detect_essential_plugins($active_plugins) {
        $essential = [];

        // ALWAYS include essential core plugins (from scanner/database)
        $essential = array_merge($essential, $this->match_essential_plugins($active_plugins));

        // Page type detection (NEW - most accurate)
        $essential = array_merge($essential, $this->detect_by_page_type($active_plugins));

        // URL-based detection
        $essential = array_merge($essential, $this->detect_by_url($active_plugins));

        // Content-based detection
        if (is_singular()) {
            $essential = array_merge($essential, $this->detect_by_content($active_plugins));
        }

        // User role detection
        $essential = array_merge($essential, $this->detect_by_user_role($active_plugins));

        // Only include plugins that are actually active
        $essential = array_intersect($essential, $active_plugins);

        return array_unique($essential);
    }

    /**
     * Match essential plugin slugs to actual plugin paths
     * OPTIMIZED: Pre-builds lookup table, reduces operations significantly
     * UPDATED: Now uses dynamic essential plugins from scanner/database
     */
    private function match_essential_plugins($active_plugins) {
        $essential_slugs = self::get_essential_plugins();

        // Pre-build lookup table (runs once per request)
        static $essential_lookup = null;
        if ($essential_lookup === null || self::$essential_plugins_cache === null) {
            $essential_lookup = [];
            foreach ($essential_slugs as $slug) {
                $essential_lookup[$slug . '/'] = true;
            }
        }

        // Single pass through active plugins
        $matched = [];
        foreach ($active_plugins as $plugin_path) {
            foreach ($essential_lookup as $prefix => $_) {
                if (strpos($plugin_path, $prefix) === 0) {
                    $matched[] = $plugin_path;
                    break;
                }
            }
        }

        // Allow filtering the matched essential plugins
        return apply_filters('tcwp_matched_essential_plugins', $matched, $active_plugins);
    }

    /**
     * Convert plugin slugs to full paths
     */
    private function slugs_to_paths($slugs, $active_plugins) {
        $paths = [];
        foreach ($slugs as $slug) {
            foreach ($active_plugins as $plugin_path) {
                if (strpos($plugin_path, $slug . '/') === 0) {
                    $paths[] = $plugin_path;
                    break;
                }
            }
        }
        return $paths;
    }

    /**
     * Detect plugins by current URL pattern
     * Enhanced with comprehensive URL coverage and caching
     */
    private function detect_by_url($active_plugins = []) {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';

        // Check cache first
        $cached = TurboChargeWP_Detection_Cache::get_url_detection($request_uri);
        if ($cached !== false) {
            return $cached;
        }

        $detected = [];

        // WooCommerce patterns
        if (preg_match('#/(shop|product|cart|checkout|my-account)(/|$)#i', $request_uri)) {
            $detected[] = 'woocommerce';
        }

        // Course patterns
        if (preg_match('#/(courses?|lessons?|quiz)(/|$)#i', $request_uri)) {
            $detected[] = 'learnpress';
        }

        // Membership patterns
        if (preg_match('#/(members?|subscription|restricted)(/|$)#i', $request_uri)) {
            $detected[] = 'restrict-content-pro';
        }

        // Blog patterns
        if (preg_match('#/(blog|news|articles?)(/|$)#i', $request_uri)) {
            $detected[] = 'jet-blog';
        }

        // Affiliate patterns
        if (preg_match('#/(affiliate|dashboard|referrals?)(/|$)#i', $request_uri)) {
            $detected[] = 'affiliate-wp';
        }

        // Form patterns
        if (preg_match('#/(contact|form|apply)(/|$)#i', $request_uri)) {
            $detected[] = 'fluentform';
        }

        // Allow filtering
        $detected = apply_filters('tcwp_url_detected_plugins', $detected, $request_uri);

        // Convert slugs to paths
        $result = $this->slugs_to_paths($detected, $active_plugins);

        // Cache the result
        TurboChargeWP_Detection_Cache::set_url_detection($request_uri, $result);

        return $result;
    }

    /**
     * Detect plugins based on WordPress query context
     * Most accurate detection using WordPress conditional tags
     */
    private function detect_by_page_type($active_plugins = []) {
        $detected = [];

        // Shop pages
        if (function_exists('is_shop') && is_shop()) {
            $detected[] = 'woocommerce';
            $detected[] = 'jet-woo-builder';
        }

        // Product pages
        if (function_exists('is_product') && is_product()) {
            $detected[] = 'woocommerce';
            $detected[] = 'jet-woo-builder';
            $detected[] = 'jet-woo-product-gallery';
            $detected[] = 'jet-reviews';
        }

        // Cart/Checkout
        if (function_exists('is_cart') && (is_cart() || is_checkout())) {
            $detected[] = 'woocommerce';
        }

        // Account pages
        if (function_exists('is_account_page') && is_account_page()) {
            $detected[] = 'woocommerce';
            $detected[] = 'restrict-content-pro';
        }

        // Search results
        if (is_search()) {
            $detected[] = 'jet-search';
            $detected[] = 'jet-smart-filters';
        }

        // Archives
        if (is_archive() || is_category() || is_tag()) {
            $detected[] = 'jet-blog';
            $detected[] = 'jet-smart-filters';
        }

        return $this->slugs_to_paths($detected, $active_plugins);
    }

    /**
     * Detect plugins by post content
     * Enhanced with faster detection, better shortcode coverage, and caching
     */
    private function detect_by_content($active_plugins = []) {
        $detected = [];

        if (!is_singular()) {
            return $detected;
        }

        $post = get_post();
        if (!$post) {
            return $detected;
        }

        // Check cache first
        $cached = TurboChargeWP_Detection_Cache::get_content_scan($post->ID);
        if ($cached !== false) {
            return $cached;
        }

        // Fast Elementor detection via post meta
        $is_elementor = get_post_meta($post->ID, '_elementor_edit_mode', true);
        if ($is_elementor === 'builder') {
            $detected[] = 'elementor';
            $detected[] = 'elementor-pro';
        }

        // Quick content scan (only if not Elementor page)
        if (!$is_elementor) {
            $content = $post->post_content;

            // WooCommerce shortcodes
            if (preg_match('/\[(products?|add_to_cart|woocommerce)/i', $content)) {
                $detected[] = 'woocommerce';
            }

            // JetEngine shortcodes
            if (preg_match('/\[jet[-_]/i', $content)) {
                $detected[] = 'jet-engine';
            }

            // Form shortcodes
            if (preg_match('/\[(fluent|contact)[-_]?form/i', $content)) {
                $detected[] = 'fluentform';
            }

            // Membership shortcodes
            if (preg_match('/\[rcp/i', $content)) {
                $detected[] = 'restrict-content-pro';
            }

            // Video shortcodes
            if (preg_match('/\[(embed|video|presto)/i', $content)) {
                $detected[] = 'embedpress';
                $detected[] = 'presto-player';
            }

            // Affiliate shortcodes
            if (preg_match('/\[affiliate/i', $content)) {
                $detected[] = 'affiliate-wp';
            }
        }

        // Allow filtering
        $detected = apply_filters('tcwp_content_detected_plugins', $detected, $post);

        // Convert slugs to paths
        $result = $this->slugs_to_paths($detected, $active_plugins);

        // Cache the result
        TurboChargeWP_Detection_Cache::set_content_scan($post->ID, $result);

        return $result;
    }

    /**
     * Detect plugins by user role
     * Enhanced with better role-to-plugin mapping
     */
    private function detect_by_user_role($active_plugins = []) {
        $detected = [];

        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            $roles = (array) $user->roles;

            // Members/subscribers
            if (in_array('member', $roles) || in_array('subscriber', $roles)) {
                $detected[] = 'restrict-content-pro';
            }

            // Customers
            if (in_array('customer', $roles)) {
                $detected[] = 'woocommerce';
            }

            // Affiliates
            if (in_array('affiliate', $roles)) {
                $detected[] = 'affiliate-wp';
            }

            // Students
            if (in_array('student', $roles) || in_array('lp_teacher', $roles)) {
                $detected[] = 'learnpress';
            }

            // All logged-in users (CRM tracking)
            $detected[] = 'fluent-crm';
        }

        // Convert slugs to paths
        return $this->slugs_to_paths($detected, $active_plugins);
    }

    /**
     * Recursively resolve all dependencies for given plugins
     * OPTIMIZED: Eliminates O(n²) array_shift, uses O(1) lookups
     *
     * @param array $essential List of essential plugins
     * @param array $active_plugins All active plugins
     * @return array All plugins needed (essential + dependencies)
     */
    private function resolve_dependencies($essential, $active_plugins) {
        $to_load = [];
        $queue = array_unique($essential);
        $queue_index = 0;  // Pointer instead of shifting

        // Convert to O(1) lookup
        $active_lookup = array_flip($active_plugins);

        while ($queue_index < count($queue)) {
            $plugin = $queue[$queue_index++];  // Just increment pointer

            // Skip if already processed
            if (isset($to_load[$plugin])) {
                continue;
            }

            // Check if plugin is active (O(1) lookup)
            if (!isset($active_lookup[$plugin])) {
                continue;
            }

            // Mark as loaded
            $to_load[$plugin] = true;

            // Get plugin slug for dependency lookup
            $slug = $this->get_plugin_slug($plugin);

            // Add dependencies to queue
            if (isset(self::$dependency_map[$slug]['depends_on'])) {
                foreach (self::$dependency_map[$slug]['depends_on'] as $dep_slug) {
                    $dep_path = $this->slug_to_path($dep_slug, $active_plugins);
                    if ($dep_path && !isset($to_load[$dep_path])) {
                        $queue[] = $dep_path;
                    }
                }
            }
        }

        return array_keys($to_load);
    }

    /**
     * Extract plugin slug from plugin path
     *
     * @param string $plugin_path e.g., "woocommerce/woocommerce.php"
     * @return string e.g., "woocommerce"
     */
    private function get_plugin_slug($plugin_path) {
        $parts = explode('/', $plugin_path);
        return $parts[0] ?? '';
    }

    /**
     * Convert slug to full plugin path (cached)
     *
     * @param string $slug e.g., "woocommerce"
     * @param array $active_plugins
     * @return string|null Full path or null if not found
     */
    private function slug_to_path($slug, $active_plugins) {
        static $cache = [];

        if (isset($cache[$slug])) {
            return $cache[$slug];
        }

        $prefix = $slug . '/';
        foreach ($active_plugins as $plugin_path) {
            if (strpos($plugin_path, $prefix) === 0) {
                $cache[$slug] = $plugin_path;
                return $plugin_path;
            }
        }

        $cache[$slug] = null;
        return null;
    }

    /**
     * Validate and prepare final plugin list
     *
     * @param array $to_load Plugins to load
     * @param array $all_plugins All active plugins
     * @return array Final validated plugin list
     */
    private function validate_and_prepare($to_load, $all_plugins) {
        // If filtering would remove too much, fallback to all
        if (count($to_load) < 3) {
            return $all_plugins;
        }

        // Create O(1) lookup
        $to_load_lookup = array_flip($to_load);

        // Maintain WordPress plugin order
        $result = [];
        foreach ($all_plugins as $plugin) {
            if (isset($to_load_lookup[$plugin])) {  // O(1) instead of in_array
                $result[] = $plugin;
            }
        }

        // Never return empty list
        return !empty($result) ? $result : $all_plugins;
    }

    /**
     * Log filter result
     * OPTIMIZED: Sample only 10% of requests to reduce DB writes
     */
    private function log_filter_result($original, $filtered, $essential, $loaded = []) {
        // Sample only 10% of requests to reduce DB writes
        if (mt_rand(1, 10) !== 1) {
            return;
        }

        $original_count = count($original);
        $filtered_count = count($filtered);
        $reduction = $original_count > 0 ? round(((($original_count - $filtered_count) / $original_count) * 100), 1) : 0;

        // Calculate which plugins were filtered out
        $filtered_out = array_diff($original, $filtered);

        $log = [
            'timestamp' => current_time('mysql'),
            'url' => $_SERVER['REQUEST_URI'] ?? 'unknown',
            'essential_detected' => array_slice($essential, 0, 10),  // Limit to 10 for storage
            'plugins_loaded' => $filtered_count,
            'plugins_filtered' => $original_count - $filtered_count,
            'total_plugins' => $original_count,
            'reduction_percent' => $reduction . '%',
            'loaded_list' => array_slice($filtered, 0, 20),  // Limit to 20
            'filtered_out_list' => array_values(array_slice($filtered_out, 0, 10)),  // Limit to 10
        ];

        self::$log_messages[] = $log;

        // Save to transient for display in debug widget
        $logs = get_transient('tcwp_logs') ?: [];
        $logs[] = $log;
        set_transient('tcwp_logs', array_slice($logs, -50), HOUR_IN_SECONDS);  // Reduce from 100 to 50
    }

    /**
     * Handle clear logs request
     */
    public function handle_clear_logs_request() {
        // Check if this is a clear logs request
        if (!isset($_POST['tcwp_action']) || $_POST['tcwp_action'] !== 'clear_logs') {
            return;
        }

        $this->clear_performance_logs();
    }

    /**
     * Clear performance logs
     */
    public function clear_performance_logs() {
        // Check nonce for security
        if (!isset($_POST['tcwp_clear_logs_nonce']) || !wp_verify_nonce($_POST['tcwp_clear_logs_nonce'], 'tcwp_clear_logs_action')) {
            wp_die('Security check failed');
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die('Access denied');
        }

        // Clear the transient logs
        delete_transient('tcwp_logs');
        self::$log_messages = [];

        // Redirect back to settings page with success message
        wp_safe_redirect(add_query_arg('tcwp_logs_cleared', '1', admin_url('options-general.php?page=tcwp-settings')));
        exit;
    }

    /**
     * Register admin menu
     */
    public function register_admin_menu() {
        // Main settings page
        add_options_page(
            'Turbo Charge WP',
            'Turbo Charge WP',
            'manage_options',
            'tcwp-settings',
            [$this, 'render_settings_page']
        );

        // Add submenu for managing essential plugins
        add_submenu_page(
            'options-general.php',
            'TCWP Essential Plugins',
            'TCWP Essential Plugins',
            'manage_options',
            'tcwp-essential-plugins',
            [$this, 'render_essential_plugins_page']
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('tcwp_settings', 'tcwp_enabled');
        register_setting('tcwp_settings', 'tcwp_debug_enabled');
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied');
        }

        $enabled = get_option('tcwp_enabled', false);
        $debug_enabled = get_option('tcwp_debug_enabled', false);
        $logs = get_transient('tcwp_logs') ?: [];

        ?>
        <div class="wrap">
            <h1>Turbo Charge WP Settings</h1>

            <div class="notice notice-info">
                <p><strong>✨ New in v5.0:</strong> <a href="<?php echo admin_url('options-general.php?page=tcwp-essential-plugins'); ?>" class="button button-primary" style="margin-left: 10px;">Manage Essential Plugins</a></p>
                <p>Use the intelligent scanner to automatically detect which plugins are critical for your site, then customize the list as needed.</p>
            </div>

            <?php if (isset($_GET['tcwp_logs_cleared']) && $_GET['tcwp_logs_cleared'] === '1'): ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>Success!</strong> Performance logs have been cleared.</p>
                </div>
            <?php endif; ?>
            <form method="post" action="options.php">
                <?php settings_fields('tcwp_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="tcwp_enabled">Enable Plugin Filtering</label>
                        </th>
                        <td>
                            <input type="checkbox" id="tcwp_enabled" name="tcwp_enabled" value="1"
                                <?php checked($enabled); ?> />
                            <p class="description">When enabled, loads only essential plugins per page for better performance.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="tcwp_debug_enabled">Enable Debug Widget</label>
                        </th>
                        <td>
                            <input type="checkbox" id="tcwp_debug_enabled" name="tcwp_debug_enabled" value="1"
                                <?php checked($debug_enabled); ?> />
                            <p class="description">Show floating debug widget on frontend with performance stats.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <?php if (!empty($logs)): ?>
                <hr>
                <h2>Recent Performance Logs</h2>
                <p class="description">
                    These logs show which plugins were loaded on each page request. Check this to debug why something isn't working.
                </p>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 15%;">Time</th>
                            <th style="width: 25%;">URL</th>
                            <th style="width: 8%;">Loaded</th>
                            <th style="width: 8%;">Filtered</th>
                            <th style="width: 10%;">Reduction</th>
                            <th style="width: 34%;">Loaded Plugins (Sample)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($logs, -20) as $log): ?>
                            <tr>
                                <td><?php echo esc_html($log['timestamp']); ?></td>
                                <td>
                                    <code style="font-size: 11px;">
                                        <?php echo esc_html(substr($log['url'], 0, 60)); ?>
                                    </code>
                                </td>
                                <td><strong><?php echo esc_html($log['plugins_loaded']); ?></strong></td>
                                <td><?php echo esc_html($log['plugins_filtered']); ?></td>
                                <td>
                                    <span style="background-color: #dcedc8; padding: 2px 6px; border-radius: 3px;">
                                        <?php echo esc_html($log['reduction_percent']); ?>
                                    </span>
                                </td>
                                <td>
                                    <details style="font-size: 12px; cursor: pointer;">
                                        <summary style="cursor: pointer; margin-bottom: 5px;">
                                            <?php
                                            $sample = array_slice($log['loaded_list'] ?? [], 0, 3);
                                            echo esc_html(implode(', ', $sample));
                                            if (count($log['loaded_list'] ?? []) > 3) {
                                                echo '... (' . (count($log['loaded_list']) - 3) . ' more)';
                                            }
                                            ?>
                                        </summary>
                                        <div style="margin-top: 10px; padding: 10px; background: #f5f5f5; border-radius: 3px;">
                                            <strong>Loaded Plugins:</strong>
                                            <ul style="margin: 5px 0; padding-left: 20px;">
                                                <?php foreach ($log['loaded_list'] ?? [] as $plugin): ?>
                                                    <li><?php echo esc_html($plugin); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                            <?php if (!empty($log['filtered_out_list'])): ?>
                                                <strong style="display: block; margin-top: 10px;">Filtered Out (Sample):</strong>
                                                <ul style="margin: 5px 0; padding-left: 20px; color: #666;">
                                                    <?php foreach (array_slice($log['filtered_out_list'], 0, 5) as $plugin): ?>
                                                        <li><?php echo esc_html($plugin); ?></li>
                                                    <?php endforeach; ?>
                                                    <?php if (count($log['filtered_out_list']) > 5): ?>
                                                        <li><em>... and <?php echo count($log['filtered_out_list']) - 5; ?> more</em></li>
                                                    <?php endif; ?>
                                                </ul>
                                            <?php endif; ?>
                                        </div>
                                    </details>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div style="margin-top: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                    <p style="margin-top: 0;">
                        <strong>Clear Logs:</strong> Remove all stored performance logs to start fresh.
                    </p>
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="tcwp_action" value="clear_logs" />
                        <?php wp_nonce_field('tcwp_clear_logs_action', 'tcwp_clear_logs_nonce'); ?>
                        <button type="submit" class="button button-secondary" onclick="return confirm('Are you sure you want to clear all performance logs? This cannot be undone.');">
                            Clear Performance Logs
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <div style="padding: 20px; background: #f0f0f0; border: 1px solid #ddd; border-radius: 4px;">
                    <p><em>No performance logs yet. Logs will appear here after visitors load pages on your site.</em></p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Enqueue debug widget assets
     */
    public function enqueue_debug_assets() {
        // Security: Only enqueue for admins
        if (!current_user_can('manage_options')) {
            return;
        }

        wp_enqueue_style('tcwp-debug', TCWP_URL . 'assets/css/debug-widget.css', [], TCWP_VERSION);
        wp_enqueue_script('tcwp-debug', TCWP_URL . 'assets/js/debug-widget.js', [], TCWP_VERSION, true);
    }

    /**
     * Render Essential Plugins management page
     */
    public function render_essential_plugins_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Access denied');
        }

        // Handle form submission
        if (isset($_POST['tcwp_save_essential']) && check_admin_referer('tcwp_essential_plugins', 'tcwp_essential_nonce')) {
            $essential_plugins = isset($_POST['tcwp_essential']) ? array_map('sanitize_text_field', $_POST['tcwp_essential']) : [];
            update_option('tcwp_essential_plugins', $essential_plugins);

            // Clear caches
            self::$essential_plugins_cache = null;
            TurboChargeWP_Detection_Cache::clear_all_caches();

            echo '<div class="notice notice-success is-dismissible"><p><strong>Essential plugins updated successfully!</strong></p></div>';
        }

        // Handle rescan
        if (isset($_POST['tcwp_rescan']) && check_admin_referer('tcwp_rescan_plugins', 'tcwp_rescan_nonce')) {
            TurboChargeWP_Plugin_Scanner::clear_cache();
            $analysis = TurboChargeWP_Plugin_Scanner::scan_active_plugins();
            update_option('tcwp_plugin_analysis', $analysis);

            echo '<div class="notice notice-success is-dismissible"><p><strong>Plugin scan completed!</strong> Found ' . count($analysis['critical']) . ' critical plugins.</p></div>';
        }

        // Get current analysis
        $analysis = get_option('tcwp_plugin_analysis', false);
        if ($analysis === false) {
            $analysis = TurboChargeWP_Plugin_Scanner::scan_active_plugins();
        }

        // Get current essential plugins
        $current_essential = get_option('tcwp_essential_plugins', []);
        $active_plugins = get_option('active_plugins', []);

        // Get cache stats
        $cache_stats = TurboChargeWP_Detection_Cache::get_cache_stats();

        ?>
        <div class="wrap">
            <h1>Turbo Charge WP - Essential Plugins</h1>

            <div class="notice notice-info">
                <p><strong>What are Essential Plugins?</strong></p>
                <p>Essential plugins are loaded on <strong>every page</strong> (header, footer, global elements). Plugins like page builders, theme cores, and global functionality should be marked as essential. Other plugins will be loaded conditionally based on page context.</p>
            </div>

            <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2>Intelligent Scanner Results</h2>
                <p>The intelligent scanner analyzed all <?php echo $analysis['total_plugins']; ?> active plugins using heuristics.</p>

                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin: 20px 0;">
                    <div style="padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px;">
                        <h3 style="margin: 0 0 5px 0; color: #155724;">Critical</h3>
                        <div style="font-size: 24px; font-weight: bold; color: #155724;"><?php echo count($analysis['critical']); ?></div>
                        <small>Always load (page builders, cores)</small>
                    </div>
                    <div style="padding: 15px; background: #fff3cd; border: 1px solid #ffeeba; border-radius: 4px;">
                        <h3 style="margin: 0 0 5px 0; color: #856404;">Conditional</h3>
                        <div style="font-size: 24px; font-weight: bold; color: #856404;"><?php echo count($analysis['conditional']); ?></div>
                        <small>Load based on page type</small>
                    </div>
                    <div style="padding: 15px; background: #d1ecf1; border: 1px solid #bee5eb; border-radius: 4px;">
                        <h3 style="margin: 0 0 5px 0; color: #0c5460;">Optional</h3>
                        <div style="font-size: 24px; font-weight: bold; color: #0c5460;"><?php echo count($analysis['optional']); ?></div>
                        <small>Can be filtered aggressively</small>
                    </div>
                </div>

                <form method="post" style="display: inline;">
                    <?php wp_nonce_field('tcwp_rescan_plugins', 'tcwp_rescan_nonce'); ?>
                    <button type="submit" name="tcwp_rescan" class="button button-secondary">
                        🔍 Rescan All Plugins
                    </button>
                </form>
                <small style="margin-left: 10px; color: #666;">Run this after installing/updating plugins</small>
            </div>

            <form method="post">
                <?php wp_nonce_field('tcwp_essential_plugins', 'tcwp_essential_nonce'); ?>

                <h2>Select Essential Plugins</h2>
                <p>Check the plugins that should <strong>always load</strong> on every page:</p>

                <style>
                    .tcwp-plugin-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 15px; margin: 20px 0; }
                    .tcwp-plugin-card { padding: 15px; background: white; border: 1px solid #ccd0d4; border-radius: 4px; }
                    .tcwp-plugin-card.critical { border-left: 4px solid #28a745; }
                    .tcwp-plugin-card.conditional { border-left: 4px solid #ffc107; }
                    .tcwp-plugin-card.optional { border-left: 4px solid #17a2b8; }
                    .tcwp-plugin-name { font-weight: bold; margin-bottom: 5px; }
                    .tcwp-plugin-score { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: bold; }
                    .tcwp-plugin-score.critical { background: #d4edda; color: #155724; }
                    .tcwp-plugin-score.conditional { background: #fff3cd; color: #856404; }
                    .tcwp-plugin-score.optional { background: #d1ecf1; color: #0c5460; }
                    .tcwp-plugin-desc { font-size: 13px; color: #666; margin: 5px 0; }
                    .tcwp-plugin-reasons { font-size: 12px; color: #999; margin-top: 5px; }
                </style>

                <?php
                $all_plugins_analyzed = array_merge($analysis['critical'], $analysis['conditional'], $analysis['optional']);

                foreach (['critical' => 'Critical Plugins', 'conditional' => 'Conditional Plugins', 'optional' => 'Optional Plugins'] as $category_key => $category_label):
                    $plugins_in_category = $analysis[$category_key];
                    if (empty($plugins_in_category)) continue;
                ?>
                    <h3><?php echo esc_html($category_label); ?> (<?php echo count($plugins_in_category); ?>)</h3>
                    <div class="tcwp-plugin-list">
                        <?php foreach ($plugins_in_category as $plugin): ?>
                            <div class="tcwp-plugin-card <?php echo esc_attr($plugin['category']); ?>">
                                <label style="display: flex; align-items: start; cursor: pointer;">
                                    <input type="checkbox"
                                           name="tcwp_essential[]"
                                           value="<?php echo esc_attr($plugin['slug']); ?>"
                                           <?php checked(in_array($plugin['slug'], $current_essential)); ?>
                                           style="margin: 4px 10px 0 0;">
                                    <div style="flex: 1;">
                                        <div class="tcwp-plugin-name">
                                            <?php echo esc_html($plugin['name']); ?>
                                            <span class="tcwp-plugin-score <?php echo esc_attr($plugin['category']); ?>">
                                                Score: <?php echo $plugin['score']; ?>
                                            </span>
                                        </div>
                                        <div class="tcwp-plugin-desc"><?php echo esc_html($plugin['description']); ?></div>
                                        <?php if (!empty($plugin['reasons'])): ?>
                                            <div class="tcwp-plugin-reasons">
                                                📊 <?php echo esc_html(implode(' • ', array_slice($plugin['reasons'], 0, 2))); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>

                <p class="submit">
                    <button type="submit" name="tcwp_save_essential" class="button button-primary button-hero">
                        💾 Save Essential Plugins
                    </button>
                </p>
            </form>

            <div style="background: #f9f9f9; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
                <h3>Cache Statistics</h3>
                <ul>
                    <li><strong>URL Detection Cache:</strong> <?php echo $cache_stats['url_cache_entries']; ?> entries</li>
                    <li><strong>Content Scan Cache:</strong> <?php echo $cache_stats['content_cache_entries']; ?> entries</li>
                    <li><strong>Estimated Cache Size:</strong> <?php echo $cache_stats['estimated_size_kb']; ?> KB</li>
                    <li><strong>Object Cache:</strong> <?php echo $cache_stats['using_object_cache'] ? '✓ Enabled (Redis/Memcached)' : '✗ Using transients'; ?></li>
                </ul>
            </div>
        </div>
        <?php
    }

    /**
     * Render floating debug widget
     */
    public function render_debug_widget() {
        // Security: Only show to admins to prevent exposing plugin info to frontend users
        if (!current_user_can('manage_options')) {
            return;
        }

        // Only show debug widget if filtering is actually enabled
        if (!self::$enabled) {
            return;
        }

        $logs = get_transient('tcwp_logs') ?: [];
        $last_log = !empty($logs) ? end($logs) : null;
        $active_plugins = get_option('active_plugins', []);

        ?>
        <div id="tcwp-debug-widget" class="tcwp-debug-widget">
            <div class="tcwp-debug-toggle">
                <span class="tcwp-debug-title">⚡ Turbo Charge</span>
            </div>
            <div class="tcwp-debug-content">
                <?php if ($last_log): ?>
                    <div class="tcwp-debug-stat">
                        <strong>Total Plugins:</strong> <?php echo esc_html($last_log['total_plugins']); ?>
                    </div>
                    <div class="tcwp-debug-stat">
                        <strong>Loaded:</strong> <?php echo esc_html($last_log['plugins_loaded']); ?>
                    </div>
                    <div class="tcwp-debug-stat">
                        <strong>Filtered:</strong> <?php echo esc_html($last_log['plugins_filtered']); ?>
                    </div>
                    <div class="tcwp-debug-stat highlight">
                        <strong>Reduction:</strong> <?php echo esc_html($last_log['reduction_percent']); ?>
                    </div>
                    <hr>
                    <div class="tcwp-debug-list">
                        <strong>Essential:</strong>
                        <ul>
                            <?php foreach ($last_log['essential_detected'] as $plugin): ?>
                                <li><?php echo esc_html($plugin); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <div class="tcwp-debug-list">
                        <strong>Loaded (<?php echo count($last_log['essential_detected']); ?> of <?php echo $last_log['total_plugins']; ?>):</strong>
                        <ul>
                            <?php foreach (array_slice($last_log['essential_detected'], 0, 10) as $plugin): ?>
                                <li><?php echo esc_html($plugin); ?></li>
                            <?php endforeach; ?>
                            <?php if (count($last_log['essential_detected']) > 10): ?>
                                <li><em>... and <?php echo count($last_log['essential_detected']) - 10; ?> more</em></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <div class="tcwp-debug-list">
                        <strong>Filtered Out (Sample):</strong>
                        <ul>
                            <?php
                            $filtered_plugins = array_diff($active_plugins, $last_log['essential_detected']);
                            foreach (array_slice($filtered_plugins, 0, 5) as $plugin):
                            ?>
                                <li><?php echo esc_html($plugin); ?></li>
                            <?php endforeach; ?>
                            <?php if (count($filtered_plugins) > 5): ?>
                                <li><em>... and <?php echo count($filtered_plugins) - 5; ?> more</em></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                <?php else: ?>
                    <p>No performance data yet. Check back after loading a page.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
