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

        // Load dependency map (dynamically detected or from database)
        self::$dependency_map = TurboChargeWP_Dependency_Detector::get_dependency_map();

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
        add_action('activated_plugin', [$this, 'handle_plugin_activation']);
        add_action('deactivated_plugin', [$this, 'handle_plugin_deactivation']);

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
     * Handle plugin activation - rebuild dependencies and clear caches
     */
    public function handle_plugin_activation() {
        // Rebuild dependency map to include newly activated plugin
        TurboChargeWP_Dependency_Detector::rebuild_dependency_map();

        // Clear caches
        TurboChargeWP_Detection_Cache::clear_all_caches();
        TurboChargeWP_Requirements_Cache::clear();
        self::$essential_plugins_cache = null;
        self::$dependency_map = [];
    }

    /**
     * Handle plugin deactivation - rebuild dependencies and clear caches
     */
    public function handle_plugin_deactivation() {
        // Rebuild dependency map to remove deactivated plugin
        TurboChargeWP_Dependency_Detector::rebuild_dependency_map();

        // Clear caches
        TurboChargeWP_Detection_Cache::clear_all_caches();
        TurboChargeWP_Requirements_Cache::clear();
        self::$essential_plugins_cache = null;
        self::$dependency_map = [];
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
     * NOTE: Dependency map is now auto-detected by TurboChargeWP_Dependency_Detector
     *
     * The dependency map is no longer hardcoded. Instead, it is:
     * 1. Automatically detected by scanning plugin headers and code
     * 2. Stored in database option 'tcwp_dependency_map'
     * 3. Rebuilt on plugin activation/deactivation
     * 4. Can be customized via filter: apply_filters('tcwp_dependency_map', $map)
     *
     * To add custom dependencies programmatically:
     *
     * add_filter('tcwp_dependency_map', function($map) {
     *     $map['my-plugin'] = [
     *         'depends_on' => ['parent-plugin'],
     *         'plugins_depending' => []
     *     ];
     *     return $map;
     * });
     *
     * Or use the admin UI: Settings → Turbo Charge WP → Dependencies
     */

    /**
     * Handle clear logs request
     */
    public function handle_clear_logs_request() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification handled in called methods based on action type
        if (!isset($_POST['tcwp_action'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification handled in called methods based on action type
        $action = sanitize_text_field( wp_unslash( $_POST['tcwp_action'] ) );

        if ( 'clear_logs' === $action ) {
            $this->clear_performance_logs();
        }

        if ( 'rebuild_cache' === $action ) {
            $this->rebuild_requirements_cache();
        }
    }

    /**
     * Rebuild the requirements lookup cache
     */
    public function rebuild_requirements_cache() {
        if ( ! isset( $_POST['tcwp_rebuild_cache_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tcwp_rebuild_cache_nonce'] ) ), 'tcwp_rebuild_cache_action' ) ) {
            wp_die( esc_html__( 'Security check failed', 'turbo-charge-wp' ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied', 'turbo-charge-wp' ) );
        }

        $count = TurboChargeWP_Requirements_Cache::rebuild_lookup_table();

        wp_safe_redirect(add_query_arg('tcwp_cache_rebuilt', $count, admin_url('options-general.php?page=tcwp-settings')));
        exit;
    }

    /**
     * Clear performance logs
     */
    public function clear_performance_logs() {
        if ( ! isset( $_POST['tcwp_clear_logs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tcwp_clear_logs_nonce'] ) ), 'tcwp_clear_logs_action' ) ) {
            wp_die( esc_html__( 'Security check failed', 'turbo-charge-wp' ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied', 'turbo-charge-wp' ) );
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
        register_setting('tcwp_settings', 'tcwp_enabled', [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false,
        ]);
        register_setting('tcwp_settings', 'tcwp_debug_enabled', [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false,
        ]);
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied', 'turbo-charge-wp' ) );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab parameter for display only, no action taken
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';

        if ( 'scanner' === $active_tab ) {
            $this->render_essential_plugins_page();
            return;
        }

        if ( 'dependencies' === $active_tab ) {
            $this->render_dependencies_page();
            return;
        }

        $enabled = get_option('tcwp_enabled', false);
        $debug_enabled = get_option('tcwp_debug_enabled', false);
        $logs = get_transient('tcwp_logs') ?: [];
        $mu_loader_active = tcwp_is_mu_loader_active();

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Turbo Charge WP Settings', 'turbo-charge-wp' ); ?></h1>

            <?php
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice, no action taken
            if ( isset( $_GET['tcwp_logs_cleared'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['tcwp_logs_cleared'] ) ) ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong><?php esc_html_e( 'Success!', 'turbo-charge-wp' ); ?></strong> <?php esc_html_e( 'Performance logs have been cleared.', 'turbo-charge-wp' ); ?></p>
                </div>
            <?php endif; ?>

            <?php
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice, no action taken
            if ( isset( $_GET['tcwp_cache_rebuilt'] ) ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong><?php esc_html_e( 'Success!', 'turbo-charge-wp' ); ?></strong>
                    <?php
                    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice, no action taken
                    $pages_count = intval( sanitize_text_field( wp_unslash( $_GET['tcwp_cache_rebuilt'] ) ) );
                    printf(
                        /* translators: %d: number of pages analyzed */
                        esc_html__( 'Requirements cache rebuilt. Analyzed %d pages.', 'turbo-charge-wp' ),
                        $pages_count
                    );
                    ?></p>
                </div>
            <?php endif; ?>

            <!-- MU-Loader Status Banner -->
            <div style="background: <?php echo esc_attr( $mu_loader_active ? '#d4edda' : '#f8d7da' ); ?>; padding: 20px; margin: 20px 0; border-left: 4px solid <?php echo esc_attr( $mu_loader_active ? '#28a745' : '#dc3545' ); ?>; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2 style="margin-top: 0;">
                    <?php if ( $mu_loader_active ) : ?>
                        <?php esc_html_e( 'MU-Loader Active - Real Filtering Enabled', 'turbo-charge-wp' ); ?>
                    <?php else : ?>
                        <?php esc_html_e( 'MU-Loader Not Installed - Filtering Won\'t Work', 'turbo-charge-wp' ); ?>
                    <?php endif; ?>
                </h2>
                <?php if ( $mu_loader_active ) : ?>
                    <p style="color: #155724; margin-bottom: 0;">
                        <?php esc_html_e( 'The MU-loader is installed and filtering plugins before they load. This is the correct setup for actual performance gains.', 'turbo-charge-wp' ); ?>
                    </p>
                <?php else : ?>
                    <p style="color: #721c24;">
                        <strong><?php esc_html_e( 'Without the MU-loader, plugin filtering cannot work.', 'turbo-charge-wp' ); ?></strong>
                        <?php esc_html_e( 'Regular plugins load too late to filter out other plugins.', 'turbo-charge-wp' ); ?>
                    </p>
                    <p>
                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'options-general.php?page=tcwp-settings&tcwp_install_mu=1' ), 'tcwp_install_mu' ) ); ?>"
                           class="button button-primary">
                            <?php esc_html_e( 'Install MU-Loader Now', 'turbo-charge-wp' ); ?>
                        </a>
                    </p>
                <?php endif; ?>
            </div>

            <!-- Scanner & Dependencies Section -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 20px 0;">
                <div style="background: white; padding: 20px; border-left: 4px solid #667eea; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                    <h2 style="margin-top: 0;"><?php esc_html_e( 'Intelligent Plugin Scanner', 'turbo-charge-wp' ); ?></h2>
                    <p><?php esc_html_e( 'Use AI-powered heuristics to automatically detect which plugins are essential for your site. The scanner analyzes all active plugins and categorizes them as critical, conditional, or optional.', 'turbo-charge-wp' ); ?></p>
                    <a href="<?php echo esc_url( admin_url( 'options-general.php?page=tcwp-settings&tab=scanner' ) ); ?>" class="button button-primary button-large">
                        <?php esc_html_e( 'Manage Essential Plugins', 'turbo-charge-wp' ); ?>
                    </a>
                </div>

                <div style="background: white; padding: 20px; border-left: 4px solid #28a745; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                    <h2 style="margin-top: 0;"><?php esc_html_e( 'Plugin Dependencies', 'turbo-charge-wp' ); ?></h2>
                    <p><?php esc_html_e( 'View automatically detected plugin dependencies. Dependencies are discovered by analyzing plugin headers, code patterns, and ecosystem relationships.', 'turbo-charge-wp' ); ?></p>
                    <a href="<?php echo esc_url( admin_url( 'options-general.php?page=tcwp-settings&tab=dependencies' ) ); ?>" class="button button-secondary button-large">
                        <?php esc_html_e( 'View Dependency Map', 'turbo-charge-wp' ); ?>
                    </a>
                </div>
            </div>

            <!-- Smart Content Detection -->
            <?php
            $cache_stats = TurboChargeWP_Requirements_Cache::get_stats();
            ?>
            <div style="background: white; padding: 20px; margin: 20px 0; border-left: 4px solid #17a2b8; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2 style="margin-top: 0;"><?php esc_html_e( 'Smart Content Detection', 'turbo-charge-wp' ); ?></h2>
                <p><?php esc_html_e( 'Analyzes page content (shortcodes, Elementor widgets, Gutenberg blocks) to detect which plugins each page needs. This enables O(1) lookup for maximum performance.', 'turbo-charge-wp' ); ?></p>
                <div style="display: flex; gap: 15px; align-items: center; margin: 15px 0;">
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="tcwp_action" value="rebuild_cache" />
                        <?php wp_nonce_field( 'tcwp_rebuild_cache_action', 'tcwp_rebuild_cache_nonce' ); ?>
                        <button type="submit" class="button button-secondary" onclick="return confirm('<?php echo esc_js( __( 'This will analyze all published pages. Continue?', 'turbo-charge-wp' ) ); ?>');">
                            <?php esc_html_e( 'Rebuild Requirements Cache', 'turbo-charge-wp' ); ?>
                        </button>
                    </form>
                    <span style="color: #666; font-size: 13px;">
                        <?php
                        printf(
                            /* translators: 1: number of pages cached, 2: cache size in KB */
                            esc_html__( '%1$s pages cached (%2$s KB)', 'turbo-charge-wp' ),
                            '<strong>' . esc_html( $cache_stats['total_entries'] ) . '</strong>',
                            esc_html( $cache_stats['size_kb'] )
                        );
                        ?>
                    </span>
                </div>
                <p class="description"><?php esc_html_e( 'Run this after bulk content changes or when conditional loading isn\'t working correctly.', 'turbo-charge-wp' ); ?></p>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields( 'tcwp_settings' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="tcwp_enabled"><?php esc_html_e( 'Enable Plugin Filtering', 'turbo-charge-wp' ); ?></label>
                        </th>
                        <td>
                            <input type="checkbox" id="tcwp_enabled" name="tcwp_enabled" value="1"
                                <?php checked( $enabled ); ?>
                                <?php echo ! $mu_loader_active ? 'style="opacity: 0.5;"' : ''; ?> />
                            <?php if ( ! $mu_loader_active ) : ?>
                                <span style="color: #dc3545; font-weight: bold;"><?php esc_html_e( 'Install MU-Loader first!', 'turbo-charge-wp' ); ?></span>
                            <?php endif; ?>
                            <p class="description">
                                <?php esc_html_e( 'When enabled, loads only essential plugins per page for better performance.', 'turbo-charge-wp' ); ?>
                                <?php if ( ! $mu_loader_active ) : ?>
                                    <br><strong style="color: #dc3545;"><?php esc_html_e( 'Requires MU-Loader to actually work.', 'turbo-charge-wp' ); ?></strong>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="tcwp_debug_enabled"><?php esc_html_e( 'Enable Debug Widget', 'turbo-charge-wp' ); ?></label>
                        </th>
                        <td>
                            <input type="checkbox" id="tcwp_debug_enabled" name="tcwp_debug_enabled" value="1"
                                <?php checked( $debug_enabled ); ?> />
                            <p class="description"><?php esc_html_e( 'Show floating debug widget on frontend with performance stats (admins only).', 'turbo-charge-wp' ); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <?php if ( ! empty( $logs ) ) : ?>
                <hr>
                <h2><?php esc_html_e( 'Recent Performance Logs', 'turbo-charge-wp' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'These logs show which plugins were loaded on each page request.', 'turbo-charge-wp' ); ?>
                    <?php if ( $mu_loader_active ) : ?>
                        <span style="color: #28a745;"><?php esc_html_e( 'Using MU-loader for real filtering', 'turbo-charge-wp' ); ?></span>
                    <?php else : ?>
                        <span style="color: #dc3545;"><?php esc_html_e( 'Logs show intended filtering, not actual (MU-loader not installed)', 'turbo-charge-wp' ); ?></span>
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
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied', 'turbo-charge-wp' ) );
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

    /**
     * Render dependencies management page
     */
    public function render_dependencies_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied', 'turbo-charge-wp' ) );
        }

        // Handle rebuild request
        if ( isset( $_POST['tcwp_rebuild_dependencies'] ) && check_admin_referer( 'tcwp_rebuild_dependencies', 'tcwp_rebuild_deps_nonce' ) ) {
            $count = TurboChargeWP_Dependency_Detector::rebuild_dependency_map();
            echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html__( 'Success!', 'turbo-charge-wp' ) . '</strong> ';
            printf(
                /* translators: %d: number of plugins analyzed */
                esc_html__( 'Dependency map rebuilt. Analyzed %d plugins.', 'turbo-charge-wp' ),
                $count
            );
            echo '</p></div>';
        }

        $dependency_map = TurboChargeWP_Dependency_Detector::get_dependency_map();
        $stats = TurboChargeWP_Dependency_Detector::get_stats();

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Turbo Charge WP - Plugin Dependencies', 'turbo-charge-wp' ); ?></h1>

            <a href="<?php echo esc_url( admin_url( 'options-general.php?page=tcwp-settings' ) ); ?>" class="button button-secondary" style="margin-bottom: 15px;">
                <?php esc_html_e( '← Back to Settings', 'turbo-charge-wp' ); ?>
            </a>

            <div class="notice notice-info">
                <p><strong><?php esc_html_e( 'About Plugin Dependencies', 'turbo-charge-wp' ); ?></strong></p>
                <p><?php esc_html_e( 'Dependencies are automatically detected by analyzing plugin headers, code patterns, and ecosystem relationships. When a plugin is loaded, all its dependencies are automatically loaded too.', 'turbo-charge-wp' ); ?></p>
            </div>

            <!-- Statistics -->
            <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2><?php esc_html_e( 'Dependency Statistics', 'turbo-charge-wp' ); ?></h2>
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin: 20px 0;">
                    <div style="padding: 15px; background: #e7f3ff; border: 1px solid #b3d9ff; border-radius: 4px;">
                        <h3 style="margin: 0 0 5px 0; color: #004085;"><?php esc_html_e( 'Total Plugins', 'turbo-charge-wp' ); ?></h3>
                        <div style="font-size: 24px; font-weight: bold; color: #004085;"><?php echo esc_html( $stats['total_plugins'] ); ?></div>
                        <small><?php esc_html_e( 'In dependency map', 'turbo-charge-wp' ); ?></small>
                    </div>
                    <div style="padding: 15px; background: #fff3cd; border: 1px solid #ffeeba; border-radius: 4px;">
                        <h3 style="margin: 0 0 5px 0; color: #856404;"><?php esc_html_e( 'With Dependencies', 'turbo-charge-wp' ); ?></h3>
                        <div style="font-size: 24px; font-weight: bold; color: #856404;"><?php echo esc_html( $stats['plugins_with_dependencies'] ); ?></div>
                        <small><?php esc_html_e( 'Plugins requiring others', 'turbo-charge-wp' ); ?></small>
                    </div>
                    <div style="padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px;">
                        <h3 style="margin: 0 0 5px 0; color: #155724;"><?php esc_html_e( 'Relationships', 'turbo-charge-wp' ); ?></h3>
                        <div style="font-size: 24px; font-weight: bold; color: #155724;"><?php echo esc_html( $stats['total_dependency_relationships'] ); ?></div>
                        <small><?php esc_html_e( 'Total dependencies', 'turbo-charge-wp' ); ?></small>
                    </div>
                </div>

                <form method="post" style="margin-top: 20px;">
                    <?php wp_nonce_field( 'tcwp_rebuild_dependencies', 'tcwp_rebuild_deps_nonce' ); ?>
                    <button type="submit" name="tcwp_rebuild_dependencies" class="button button-primary" onclick="return confirm('<?php echo esc_js( __( 'Rebuild dependency map? This will scan all active plugins.', 'turbo-charge-wp' ) ); ?>');">
                        <?php esc_html_e( '🔄 Rebuild Dependency Map', 'turbo-charge-wp' ); ?>
                    </button>
                    <small style="margin-left: 10px; color: #666;"><?php esc_html_e( 'Detection method: Heuristic scanning (plugin headers, code analysis, patterns)', 'turbo-charge-wp' ); ?></small>
                </form>
            </div>

            <!-- Dependency List -->
            <h2><?php esc_html_e( 'Plugin Dependency Map', 'turbo-charge-wp' ); ?></h2>

            <style>
                .tcwp-dep-table { width: 100%; border-collapse: collapse; background: white; }
                .tcwp-dep-table th { background: #f0f0f0; padding: 10px; text-align: left; border-bottom: 2px solid #ddd; font-weight: 600; }
                .tcwp-dep-table td { padding: 10px; border-bottom: 1px solid #e0e0e0; }
                .tcwp-dep-table tr:hover { background: #f9f9f9; }
                .tcwp-dep-badge { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: bold; margin: 2px; }
                .tcwp-dep-badge.depends { background: #fff3cd; color: #856404; }
                .tcwp-dep-badge.required { background: #d4edda; color: #155724; }
                .tcwp-dep-badge.none { background: #e2e3e5; color: #6c757d; }
                .tcwp-plugin-name { font-weight: 600; color: #0073aa; }
            </style>

            <table class="tcwp-dep-table">
                <thead>
                    <tr>
                        <th style="width: 25%;"><?php esc_html_e( 'Plugin', 'turbo-charge-wp' ); ?></th>
                        <th style="width: 35%;"><?php esc_html_e( 'Depends On', 'turbo-charge-wp' ); ?></th>
                        <th style="width: 35%;"><?php esc_html_e( 'Required By', 'turbo-charge-wp' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    ksort( $dependency_map );
                    foreach ( $dependency_map as $plugin_slug => $data ) :
                        $depends_on = ! empty( $data['depends_on'] ) ? $data['depends_on'] : [];
                        $required_by = ! empty( $data['plugins_depending'] ) ? $data['plugins_depending'] : [];
                        ?>
                        <tr>
                            <td>
                                <span class="tcwp-plugin-name"><?php echo esc_html( $plugin_slug ); ?></span>
                            </td>
                            <td>
                                <?php if ( ! empty( $depends_on ) ) : ?>
                                    <?php foreach ( $depends_on as $dep ) : ?>
                                        <span class="tcwp-dep-badge depends"><?php echo esc_html( $dep ); ?></span>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <span class="tcwp-dep-badge none"><?php esc_html_e( 'None', 'turbo-charge-wp' ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ( ! empty( $required_by ) ) : ?>
                                    <?php foreach ( $required_by as $req ) : ?>
                                        <span class="tcwp-dep-badge required"><?php echo esc_html( $req ); ?></span>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <span class="tcwp-dep-badge none"><?php esc_html_e( 'None', 'turbo-charge-wp' ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div style="margin-top: 30px; padding: 20px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                <h3><?php esc_html_e( 'How Dependencies Are Detected', 'turbo-charge-wp' ); ?></h3>
                <ul>
                    <li><strong><?php esc_html_e( 'WordPress 6.5+ Headers:', 'turbo-charge-wp' ); ?></strong> <?php esc_html_e( 'Reads "Requires Plugins" header from plugin files', 'turbo-charge-wp' ); ?></li>
                    <li><strong><?php esc_html_e( 'Code Analysis:', 'turbo-charge-wp' ); ?></strong> <?php esc_html_e( 'Detects class_exists(), defined() checks for parent plugins', 'turbo-charge-wp' ); ?></li>
                    <li><strong><?php esc_html_e( 'Naming Patterns:', 'turbo-charge-wp' ); ?></strong> <?php esc_html_e( '"jet-*" depends on "jet-engine", "woocommerce-*" depends on "woocommerce"', 'turbo-charge-wp' ); ?></li>
                    <li><strong><?php esc_html_e( 'Known Ecosystems:', 'turbo-charge-wp' ); ?></strong> <?php esc_html_e( 'Built-in knowledge of major plugin families (Elementor, WooCommerce, LearnPress, etc.)', 'turbo-charge-wp' ); ?></li>
                </ul>
                <p><strong><?php esc_html_e( 'Filter Hook:', 'turbo-charge-wp' ); ?></strong> <?php esc_html_e( 'Developers can customize dependencies using the', 'turbo-charge-wp' ); ?> <code>tcwp_dependency_map</code> <?php esc_html_e( 'filter.', 'turbo-charge-wp' ); ?></p>
            </div>
        </div>
        <?php
    }
}
