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
    private static $log_messages = [];
    private static $essential_plugins_cache = null;

    /**
     * Get essential plugins list (dynamic, from database or scanner)
     *
     * @return array Essential plugin slugs
     */
    private static function get_essential_plugins() {
        if (self::$essential_plugins_cache !== null) {
            return self::$essential_plugins_cache;
        }

        $essential = apply_filters('tcwp_essential_plugins', null);

        if ($essential === null) {
            $essential = TurboChargeWP_Plugin_Scanner::get_essential_plugins();
        }

        if (empty($essential)) {
            $essential = ['elementor', 'jet-engine', 'jet-theme-core'];
        }

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
        self::$enabled = get_option('tcwp_enabled', false);

        // Load dependency map (for admin display purposes)
        self::load_dependency_map();

        // Setup admin hooks
        if (is_admin()) {
            add_action('admin_menu', [$this, 'register_admin_menu']);
            add_action('admin_init', [$this, 'register_settings']);
            add_action('admin_init', [$this, 'handle_clear_logs_request']);
        }

        // NOTE: Plugin filtering is now handled by MU-loader (tcwp-mu-loader.php)
        // The MU-loader runs BEFORE plugins load, which is required for actual filtering
        // This main plugin now only handles:
        // - Admin settings UI
        // - Scanner functionality
        // - Debug widget display
        // - Logging and statistics

        // Load debug widget on frontend if enabled (admin only for security)
        if (!is_admin() && get_option('tcwp_debug_enabled', false)) {
            add_action('wp_footer', [$this, 'render_debug_widget']);
            add_action('wp_enqueue_scripts', [$this, 'enqueue_debug_assets']);
        }

        // Cache invalidation hooks
        add_action('save_post', [$this, 'clear_post_cache'], 10, 1);
        add_action('activated_plugin', [$this, 'clear_all_detection_cache']);
        add_action('deactivated_plugin', [$this, 'clear_all_detection_cache']);

        // Content analysis on post save (for smart plugin detection)
        add_action('save_post', [$this, 'analyze_post_requirements'], 20, 2);
        add_action('delete_post', [$this, 'remove_post_requirements'], 10, 1);

        // Log MU-loader results for display
        if (!is_admin() && tcwp_is_mu_loader_active()) {
            add_action('wp_loaded', [$this, 'log_mu_filter_results']);
        }
    }

    /**
     * Log MU-loader filter results
     */
    public function log_mu_filter_results() {
        $data = tcwp_get_mu_filter_data();
        if (!$data || !$data['filtered']) {
            return;
        }

        // Sample only 10% of requests
        if (wp_rand(1, 10) !== 1) {
            return;
        }

        $log = [
            'timestamp' => current_time('mysql'),
            'url' => isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : 'unknown',
            'essential_detected' => array_slice($data['essential_plugins'], 0, 10),
            'plugins_loaded' => count($data['loaded_plugins']),
            'plugins_filtered' => $data['filtered_count'],
            'total_plugins' => $data['original_count'],
            'reduction_percent' => $data['reduction_percent'] . '%',
            'loaded_list' => array_slice($data['loaded_plugins'], 0, 20),
            'filtered_out_list' => [],
            'mu_loader' => true
        ];

        $logs = get_transient('tcwp_logs') ?: [];
        $logs[] = $log;
        set_transient('tcwp_logs', array_slice($logs, -50), HOUR_IN_SECONDS);
    }

    /**
     * Clear post-specific cache when post is saved
     */
    public function clear_post_cache($post_id) {
        TurboChargeWP_Detection_Cache::clear_post_cache($post_id);
    }

    /**
     * Clear all detection caches when plugins change
     */
    public function clear_all_detection_cache() {
        TurboChargeWP_Detection_Cache::clear_all_caches();
        TurboChargeWP_Requirements_Cache::clear();
        self::$essential_plugins_cache = null;
    }

    /**
     * Analyze post content and cache plugin requirements
     *
     * @param int $post_id Post ID
     * @param WP_Post $post Post object
     */
    public function analyze_post_requirements($post_id, $post) {
        // Skip revisions and autosaves
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        // Skip non-public post types
        if ($post->post_status !== 'publish') {
            return;
        }

        // Update requirements cache
        TurboChargeWP_Requirements_Cache::update_post_requirements($post_id);
    }

    /**
     * Remove post requirements from cache when post is deleted
     *
     * @param int $post_id Post ID
     */
    public function remove_post_requirements($post_id) {
        TurboChargeWP_Requirements_Cache::remove_post_requirements($post_id);
    }

    /**
     * Load the dependency map for all supported plugins
     */
    private static function load_dependency_map() {
        self::$dependency_map = [
            // JetEngine Ecosystem
            'jet-menu' => ['depends_on' => ['jet-engine'], 'plugins_depending' => []],
            'jet-engine' => [
                'depends_on' => [],
                'plugins_depending' => [
                    'jet-menu', 'jet-blocks', 'jet-elements', 'jet-tabs', 'jet-popup',
                    'jet-blog', 'jet-search', 'jet-reviews', 'jet-smart-filters',
                    'jet-compare-wishlist', 'jet-style-manager', 'jet-tricks',
                    'jetformbuilder', 'jet-woo-product-gallery', 'jet-woo-builder',
                    'jet-theme-core', 'crocoblock-wizard',
                    // JetEngine Extensions (provide additional functionality/callbacks)
                    'jet-engine-trim-callback', 'jet-engine-attachment-link-callback',
                    'jet-engine-custom-visibility-conditions', 'jet-engine-dynamic-charts-module',
                    'jet-engine-dynamic-tables-module', 'jet-engine-items-number-filter',
                    'jet-engine-layout-switcher', 'jet-engine-post-expiration-period'
                ],
            ],
            'jet-theme-core' => ['depends_on' => ['jet-engine'], 'plugins_depending' => []],
            'jet-blocks' => ['depends_on' => ['jet-engine'], 'plugins_depending' => []],
            'jet-elements' => ['depends_on' => ['jet-engine'], 'plugins_depending' => []],
            'jet-tabs' => ['depends_on' => ['jet-engine'], 'plugins_depending' => []],
            'jet-popup' => ['depends_on' => ['jet-engine'], 'plugins_depending' => []],
            'jet-blog' => ['depends_on' => ['jet-engine'], 'plugins_depending' => []],
            'jet-search' => ['depends_on' => ['jet-engine'], 'plugins_depending' => []],
            'jet-reviews' => ['depends_on' => ['jet-engine'], 'plugins_depending' => []],
            'jet-smart-filters' => ['depends_on' => ['jet-engine'], 'plugins_depending' => []],
            'jet-compare-wishlist' => ['depends_on' => ['jet-engine'], 'plugins_depending' => []],
            'jet-woo-builder' => ['depends_on' => ['jet-engine', 'woocommerce'], 'plugins_depending' => []],

            // JetEngine Extensions
            'jet-engine-trim-callback' => ['depends_on' => ['jet-engine'], 'plugins_depending' => []],
            'jet-engine-attachment-link-callback' => ['depends_on' => ['jet-engine'], 'plugins_depending' => []],
            'jet-engine-custom-visibility-conditions' => ['depends_on' => ['jet-engine'], 'plugins_depending' => []],
            'jet-engine-dynamic-charts-module' => ['depends_on' => ['jet-engine'], 'plugins_depending' => []],
            'jet-engine-dynamic-tables-module' => ['depends_on' => ['jet-engine'], 'plugins_depending' => []],
            'jet-engine-items-number-filter' => ['depends_on' => ['jet-engine'], 'plugins_depending' => []],
            'jet-engine-layout-switcher' => ['depends_on' => ['jet-engine'], 'plugins_depending' => []],
            'jet-engine-post-expiration-period' => ['depends_on' => ['jet-engine'], 'plugins_depending' => []],

            // WooCommerce Ecosystem
            'woocommerce' => [
                'depends_on' => [],
                'plugins_depending' => [
                    'woocommerce-memberships', 'woocommerce-subscriptions',
                    'woocommerce-product-bundles', 'woocommerce-smart-coupons',
                    'jet-woo-builder', 'jet-woo-product-gallery'
                ],
            ],
            'woocommerce-memberships' => ['depends_on' => ['woocommerce'], 'plugins_depending' => []],
            'woocommerce-subscriptions' => ['depends_on' => ['woocommerce'], 'plugins_depending' => []],

            // Elementor Ecosystem
            'elementor' => [
                'depends_on' => [],
                'plugins_depending' => ['elementor-pro', 'the-plus-addons-for-elementor-page-builder', 'thim-elementor-kit'],
            ],
            'elementor-pro' => ['depends_on' => ['elementor'], 'plugins_depending' => []],

            // Content Restriction
            'restrict-content-pro' => ['depends_on' => [], 'plugins_depending' => ['rcp-content-filter-utility']],
            'rcp-content-filter-utility' => ['depends_on' => ['restrict-content-pro'], 'plugins_depending' => []],

            // Forms
            'fluentform' => ['depends_on' => [], 'plugins_depending' => ['fluentformpro']],
            'fluentformpro' => ['depends_on' => ['fluentform'], 'plugins_depending' => []],
        ];
    }

    /**
     * Handle clear logs request
     */
    public function handle_clear_logs_request() {
        if (!isset($_POST['tcwp_action'])) {
            return;
        }

        $action = sanitize_text_field(wp_unslash($_POST['tcwp_action']));

        if ($action === 'clear_logs') {
            $this->clear_performance_logs();
        }

        if ($action === 'rebuild_cache') {
            $this->rebuild_requirements_cache();
        }
    }

    /**
     * Rebuild the requirements lookup cache
     */
    public function rebuild_requirements_cache() {
        if (!isset($_POST['tcwp_rebuild_cache_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tcwp_rebuild_cache_nonce'])), 'tcwp_rebuild_cache_action')) {
            wp_die('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_die('Access denied');
        }

        $count = TurboChargeWP_Requirements_Cache::rebuild_lookup_table();

        wp_safe_redirect(add_query_arg('tcwp_cache_rebuilt', $count, admin_url('options-general.php?page=tcwp-settings')));
        exit;
    }

    /**
     * Clear performance logs
     */
    public function clear_performance_logs() {
        if (!isset($_POST['tcwp_clear_logs_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['tcwp_clear_logs_nonce'])), 'tcwp_clear_logs_action')) {
            wp_die('Security check failed');
        }

        if (!current_user_can('manage_options')) {
            wp_die('Access denied');
        }

        delete_transient('tcwp_logs');
        self::$log_messages = [];

        wp_safe_redirect(add_query_arg('tcwp_logs_cleared', '1', admin_url('options-general.php?page=tcwp-settings')));
        exit;
    }

    /**
     * Register admin menu
     */
    public function register_admin_menu() {
        add_options_page(
            'Turbo Charge WP',
            'Turbo Charge WP',
            'manage_options',
            'tcwp-settings',
            [$this, 'render_settings_page']
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

        $active_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'settings';

        if ($active_tab === 'scanner') {
            $this->render_essential_plugins_page();
            return;
        }

        $enabled = get_option('tcwp_enabled', false);
        $debug_enabled = get_option('tcwp_debug_enabled', false);
        $logs = get_transient('tcwp_logs') ?: [];
        $mu_loader_active = tcwp_is_mu_loader_active();

        ?>
        <div class="wrap">
            <h1>Turbo Charge WP Settings</h1>

            <?php if (isset($_GET['tcwp_logs_cleared']) && sanitize_text_field(wp_unslash($_GET['tcwp_logs_cleared'])) === '1'): ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>Success!</strong> Performance logs have been cleared.</p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['tcwp_cache_rebuilt'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>Success!</strong> Requirements cache rebuilt. Analyzed <?php echo intval(sanitize_text_field(wp_unslash($_GET['tcwp_cache_rebuilt']))); ?> pages.</p>
                </div>
            <?php endif; ?>

            <!-- MU-Loader Status Banner -->
            <div style="background: <?php echo $mu_loader_active ? '#d4edda' : '#f8d7da'; ?>; padding: 20px; margin: 20px 0; border-left: 4px solid <?php echo $mu_loader_active ? '#28a745' : '#dc3545'; ?>; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2 style="margin-top: 0;">
                    <?php if ($mu_loader_active): ?>
                        ✅ MU-Loader Active - Real Filtering Enabled
                    <?php else: ?>
                        ⚠️ MU-Loader Not Installed - Filtering Won't Work
                    <?php endif; ?>
                </h2>
                <?php if ($mu_loader_active): ?>
                    <p style="color: #155724; margin-bottom: 0;">
                        The MU-loader is installed and filtering plugins <strong>before</strong> they load.
                        This is the correct setup for actual performance gains.
                    </p>
                <?php else: ?>
                    <p style="color: #721c24;">
                        <strong>Without the MU-loader, plugin filtering cannot work.</strong>
                        Regular plugins load too late to filter out other plugins.
                    </p>
                    <p>
                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('options-general.php?page=tcwp-settings&tcwp_install_mu=1'), 'tcwp_install_mu')); ?>"
                           class="button button-primary">
                            Install MU-Loader Now
                        </a>
                    </p>
                <?php endif; ?>
            </div>

            <!-- Scanner Section -->
            <div style="background: white; padding: 20px; margin: 20px 0; border-left: 4px solid #667eea; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2 style="margin-top: 0;">🔍 Intelligent Plugin Scanner</h2>
                <p>Use AI-powered heuristics to automatically detect which plugins are essential for your site. The scanner analyzes all active plugins and categorizes them as critical (page builders, theme cores), conditional (WooCommerce, forms), or optional (analytics, SEO).</p>
                <a href="<?php echo esc_url(admin_url('options-general.php?page=tcwp-settings&tab=scanner')); ?>" class="button button-primary button-large">
                    Manage Essential Plugins
                </a>
                <p class="description" style="margin-top: 10px;">View scanner results, customize the essential plugins list, and check cache statistics.</p>
            </div>

            <!-- Smart Content Detection -->
            <?php
            $cache_stats = TurboChargeWP_Requirements_Cache::get_stats();
            ?>
            <div style="background: white; padding: 20px; margin: 20px 0; border-left: 4px solid #17a2b8; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2 style="margin-top: 0;">🎯 Smart Content Detection</h2>
                <p>Analyzes page content (shortcodes, Elementor widgets, Gutenberg blocks) to detect which plugins each page needs. This enables O(1) lookup for maximum performance.</p>
                <div style="display: flex; gap: 15px; align-items: center; margin: 15px 0;">
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="tcwp_action" value="rebuild_cache" />
                        <?php wp_nonce_field('tcwp_rebuild_cache_action', 'tcwp_rebuild_cache_nonce'); ?>
                        <button type="submit" class="button button-secondary" onclick="return confirm('This will analyze all published pages. Continue?');">
                            🔄 Rebuild Requirements Cache
                        </button>
                    </form>
                    <span style="color: #666; font-size: 13px;">
                        <strong><?php echo esc_html($cache_stats['total_entries']); ?></strong> pages cached
                        (<?php echo esc_html($cache_stats['size_kb']); ?> KB)
                    </span>
                </div>
                <p class="description">Run this after bulk content changes or when conditional loading isn't working correctly.</p>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('tcwp_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="tcwp_enabled">Enable Plugin Filtering</label>
                        </th>
                        <td>
                            <input type="checkbox" id="tcwp_enabled" name="tcwp_enabled" value="1"
                                <?php checked($enabled); ?>
                                <?php echo !$mu_loader_active ? 'style="opacity: 0.5;"' : ''; ?> />
                            <?php if (!$mu_loader_active): ?>
                                <span style="color: #dc3545; font-weight: bold;">⚠️ Install MU-Loader first!</span>
                            <?php endif; ?>
                            <p class="description">
                                When enabled, loads only essential plugins per page for better performance.
                                <?php if (!$mu_loader_active): ?>
                                    <br><strong style="color: #dc3545;">Requires MU-Loader to actually work.</strong>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="tcwp_debug_enabled">Enable Debug Widget</label>
                        </th>
                        <td>
                            <input type="checkbox" id="tcwp_debug_enabled" name="tcwp_debug_enabled" value="1"
                                <?php checked($debug_enabled); ?> />
                            <p class="description">Show floating debug widget on frontend with performance stats (admins only).</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <?php if (!empty($logs)): ?>
                <hr>
                <h2>Recent Performance Logs</h2>
                <p class="description">
                    These logs show which plugins were loaded on each page request.
                    <?php if ($mu_loader_active): ?>
                        <span style="color: #28a745;">✓ Using MU-loader for real filtering</span>
                    <?php else: ?>
                        <span style="color: #dc3545;">⚠️ Logs show intended filtering, not actual (MU-loader not installed)</span>
                    <?php endif; ?>
                </p>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 15%;">Time</th>
                            <th style="width: 25%;">URL</th>
                            <th style="width: 8%;">Loaded</th>
                            <th style="width: 8%;">Filtered</th>
                            <th style="width: 10%;">Reduction</th>
                            <th style="width: 34%;">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_reverse(array_slice($logs, -20)) as $log): ?>
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
                                    <span style="background-color: <?php echo isset($log['mu_loader']) ? '#d4edda' : '#fff3cd'; ?>; padding: 2px 6px; border-radius: 3px;">
                                        <?php echo esc_html($log['reduction_percent']); ?>
                                    </span>
                                </td>
                                <td>
                                    <details style="font-size: 12px; cursor: pointer;">
                                        <summary style="cursor: pointer;">
                                            <?php
                                            $sample = array_slice($log['loaded_list'] ?? [], 0, 3);
                                            echo esc_html(implode(', ', array_map(function($p) {
                                                return explode('/', $p)[0];
                                            }, $sample)));
                                            ?>...
                                        </summary>
                                        <div style="margin-top: 10px; padding: 10px; background: #f5f5f5; border-radius: 3px;">
                                            <strong>Loaded Plugins:</strong>
                                            <ul style="margin: 5px 0; padding-left: 20px;">
                                                <?php foreach ($log['loaded_list'] ?? [] as $plugin): ?>
                                                    <li><?php echo esc_html($plugin); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    </details>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div style="margin-top: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="tcwp_action" value="clear_logs" />
                        <?php wp_nonce_field('tcwp_clear_logs_action', 'tcwp_clear_logs_nonce'); ?>
                        <button type="submit" class="button button-secondary" onclick="return confirm('Are you sure you want to clear all performance logs?');">
                            Clear Performance Logs
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <div style="padding: 20px; background: #f0f0f0; border: 1px solid #ddd; border-radius: 4px; margin-top: 20px;">
                    <p><em>No performance logs yet. Enable filtering and visit some pages to see stats.</em></p>
                </div>
            <?php endif; ?>

            <!-- Technical Info -->
            <div style="margin-top: 30px; padding: 20px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                <h3>Technical Information</h3>
                <ul>
                    <li><strong>Plugin Version:</strong> <?php echo esc_html(TCWP_VERSION); ?></li>
                    <li><strong>MU-Loader:</strong> <?php echo $mu_loader_active ? '✅ Active (v' . esc_html(TCWP_MU_LOADER_VERSION) . ')' : '❌ Not Installed'; ?></li>
                    <li><strong>Total Active Plugins:</strong> <?php echo count(get_option('active_plugins', [])); ?></li>
                    <li><strong>Essential Plugins Configured:</strong> <?php echo count(get_option('tcwp_essential_plugins', [])); ?></li>
                    <li><strong>Object Cache:</strong> <?php echo wp_using_ext_object_cache() ? '✅ Active (Redis/Memcached)' : '❌ Not Available'; ?></li>
                </ul>
            </div>
        </div>
        <?php
    }

    /**
     * Enqueue debug widget assets
     */
    public function enqueue_debug_assets() {
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
            $essential_plugins = isset($_POST['tcwp_essential']) ? array_map('sanitize_text_field', wp_unslash($_POST['tcwp_essential'])) : [];
            update_option('tcwp_essential_plugins', $essential_plugins);

            self::$essential_plugins_cache = null;
            TurboChargeWP_Detection_Cache::clear_all_caches();

            echo '<div class="notice notice-success is-dismissible"><p><strong>Essential plugins updated successfully!</strong></p></div>';
        }

        // Handle rescan
        if (isset($_POST['tcwp_rescan']) && check_admin_referer('tcwp_rescan_plugins', 'tcwp_rescan_nonce')) {
            TurboChargeWP_Plugin_Scanner::clear_cache();
            $analysis = TurboChargeWP_Plugin_Scanner::scan_active_plugins();
            update_option('tcwp_plugin_analysis', $analysis);

            TurboChargeWP_Plugin_Scanner::get_essential_plugins(true);

            self::$essential_plugins_cache = null;
            TurboChargeWP_Detection_Cache::clear_all_caches();

            echo '<div class="notice notice-success is-dismissible"><p><strong>Plugin scan completed!</strong> Found ' . count($analysis['critical']) . ' critical plugins and automatically marked them as essential.</p></div>';
        }

        $analysis = get_option('tcwp_plugin_analysis', false);
        if ($analysis === false) {
            $analysis = TurboChargeWP_Plugin_Scanner::scan_active_plugins();
        }

        $current_essential = get_option('tcwp_essential_plugins', []);
        $cache_stats = TurboChargeWP_Detection_Cache::get_cache_stats();

        ?>
        <div class="wrap">
            <h1>Turbo Charge WP - Essential Plugins</h1>

            <a href="<?php echo esc_url(admin_url('options-general.php?page=tcwp-settings')); ?>" class="button button-secondary" style="margin-bottom: 15px;">
                ← Back to Settings
            </a>

            <div class="notice notice-info">
                <p><strong>What are Essential Plugins?</strong></p>
                <p>Essential plugins are loaded on <strong>every page</strong> (header, footer, global elements). Plugins like page builders, theme cores, and global functionality should be marked as essential. Other plugins will be loaded conditionally based on page context.</p>
            </div>

            <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2>Plugin Load Strategy</h2>
                <p>Based on your selections and scanner analysis:</p>

                <?php
                // Calculate dynamic counts based on user selections
                $all_plugins = array_merge($analysis['critical'], $analysis['conditional'], $analysis['optional']);
                $essential_count = count($current_essential);
                $conditional_count = 0;
                $filtered_count = 0;

                foreach ($all_plugins as $plugin) {
                    $is_essential = in_array($plugin['slug'], $current_essential);
                    if (!$is_essential) {
                        // Not marked as essential by user
                        if ($plugin['score'] >= 40) {
                            $conditional_count++; // Will load based on page
                        } else {
                            $filtered_count++; // Will be filtered unless detected
                        }
                    }
                }
                ?>

                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin: 20px 0;">
                    <div style="padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px;">
                        <h3 style="margin: 0 0 5px 0; color: #155724;">Essential</h3>
                        <div style="font-size: 24px; font-weight: bold; color: #155724;"><?php echo esc_html($essential_count); ?></div>
                        <small>Always load on every page</small>
                    </div>
                    <div style="padding: 15px; background: #fff3cd; border: 1px solid #ffeeba; border-radius: 4px;">
                        <h3 style="margin: 0 0 5px 0; color: #856404;">Conditional</h3>
                        <div style="font-size: 24px; font-weight: bold; color: #856404;" id="tcwp-conditional-count"><?php echo esc_html($conditional_count); ?></div>
                        <small>Load based on page detection</small>
                    </div>
                    <div style="padding: 15px; background: #d1ecf1; border: 1px solid #bee5eb; border-radius: 4px;">
                        <h3 style="margin: 0 0 5px 0; color: #0c5460;">Filtered</h3>
                        <div style="font-size: 24px; font-weight: bold; color: #0c5460;" id="tcwp-filtered-count"><?php echo esc_html($filtered_count); ?></div>
                        <small>Filtered unless detected</small>
                    </div>
                </div>

                <details style="margin: 15px 0;">
                    <summary style="cursor: pointer; color: #666; font-size: 13px;">Scanner categorization (for reference)</summary>
                    <div style="margin-top: 10px; padding: 10px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 3px;">
                        <p style="margin: 5px 0; font-size: 13px;"><strong>Critical (score ≥80):</strong> <?php echo count($analysis['critical']); ?> plugins</p>
                        <p style="margin: 5px 0; font-size: 13px;"><strong>Conditional (score 40-79):</strong> <?php echo count($analysis['conditional']); ?> plugins</p>
                        <p style="margin: 5px 0; font-size: 13px;"><strong>Optional (score <40):</strong> <?php echo count($analysis['optional']); ?> plugins</p>
                    </div>
                </details>

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
                                                Score: <?php echo esc_html($plugin['score']); ?>
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
                    <a href="<?php echo esc_url(admin_url('options-general.php?page=tcwp-settings')); ?>" class="button button-secondary button-hero" style="margin-left: 10px;">
                        ← Back to Settings
                    </a>
                </p>
            </form>

            <div style="background: #f9f9f9; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
                <h3>Cache Statistics</h3>
                <ul>
                    <li><strong>URL Detection Cache:</strong> <?php echo esc_html($cache_stats['url_cache_entries']); ?> entries</li>
                    <li><strong>Content Scan Cache:</strong> <?php echo esc_html($cache_stats['content_cache_entries']); ?> entries</li>
                    <li><strong>Estimated Cache Size:</strong> <?php echo esc_html($cache_stats['estimated_size_kb']); ?> KB</li>
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
        if (!current_user_can('manage_options')) {
            return;
        }

        $mu_data = tcwp_get_mu_filter_data();
        $mu_loader_active = tcwp_is_mu_loader_active();

        ?>
        <div id="tcwp-debug-widget" class="tcwp-debug-widget">
            <div class="tcwp-debug-toggle">
                <span class="tcwp-debug-title">⚡ Turbo Charge</span>
            </div>
            <div class="tcwp-debug-content">
                <?php if ($mu_loader_active && $mu_data): ?>
                    <div class="tcwp-debug-stat" style="background: #d4edda; padding: 5px; border-radius: 3px; margin-bottom: 10px;">
                        <strong>✅ MU-Loader Active</strong>
                    </div>
                    <div class="tcwp-debug-stat">
                        <strong>Total Plugins:</strong> <?php echo esc_html($mu_data['original_count']); ?>
                    </div>
                    <div class="tcwp-debug-stat">
                        <strong>Loaded:</strong> <?php echo esc_html(count($mu_data['loaded_plugins'])); ?>
                    </div>
                    <div class="tcwp-debug-stat">
                        <strong>Filtered:</strong> <?php echo esc_html($mu_data['filtered_count']); ?>
                    </div>
                    <div class="tcwp-debug-stat highlight">
                        <strong>Reduction:</strong> <?php echo esc_html($mu_data['reduction_percent']); ?>%
                    </div>
                    <hr>
                    <div class="tcwp-debug-list">
                        <strong>Essential (Always Load):</strong>
                        <ul>
                            <?php foreach (array_slice($mu_data['essential_plugins'], 0, 8) as $plugin): ?>
                                <li><?php echo esc_html($plugin); ?></li>
                            <?php endforeach; ?>
                            <?php if (count($mu_data['essential_plugins']) > 8): ?>
                                <li><em>... and <?php echo count($mu_data['essential_plugins']) - 8; ?> more</em></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                <?php else: ?>
                    <div class="tcwp-debug-stat" style="background: #f8d7da; padding: 5px; border-radius: 3px; margin-bottom: 10px;">
                        <strong>⚠️ MU-Loader Not Active</strong>
                    </div>
                    <p style="color: #721c24; font-size: 12px;">
                        Plugin filtering is not working. Install the MU-Loader from Settings → Turbo Charge WP.
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
