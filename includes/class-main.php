<?php
/**
 * Main plugin class for Samybaxy's Hyperdrive
 *
 * @package SamybaxyHyperdrive
 */

if (!defined('ABSPATH')) {
    exit;
}

class SHYPDR_Main {
    private static $instance = null;
    private static $enabled = false;
    private static $dependency_map = [];
    private static $log_messages = [];
    private static $essential_plugins_cache = null;

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
     * Setup plugin hooks and components.
     *
     * Frontend requests register only the bare minimum (debug widget when
     * enabled). All admin UI and content-analysis hooks load lazily, and
     * the dependency map is fetched only when an admin path actually needs
     * it (via get_dependency_map()).
     */
    private function setup() {
        self::$enabled = (bool) get_option('shypdr_enabled', 0);

        if (is_admin()) {
            $this->setup_admin_hooks();
            return;
        }

        $this->setup_frontend_hooks();
    }

    /**
     * Register admin-only hooks. Heavy classes (dependency detector,
     * plugin scanner, content analyzer) only autoload from here.
     */
    private function setup_admin_hooks() {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'handle_clear_logs_request']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // Cache invalidation
        add_action('activated_plugin', [$this, 'handle_plugin_activation']);
        add_action('deactivated_plugin', [$this, 'handle_plugin_deactivation']);
        add_action('save_post', [$this, 'clear_post_cache'], 10, 1);
        add_action('save_post', [$this, 'analyze_post_requirements'], 20, 2);
        add_action('delete_post', [$this, 'remove_post_requirements'], 10, 1);
    }

    /**
     * Register frontend hooks. Public visitors get only the debug widget
     * (when explicitly enabled). Also covers REST writes (Gutenberg saves
     * arrive over REST so save_post fires outside is_admin() too).
     */
    private function setup_frontend_hooks() {
        // REST writes fire save_post outside is_admin() — keep cache
        // invalidation working for Gutenberg / WooCommerce order creation.
        if (defined('REST_REQUEST') && REST_REQUEST) {
            add_action('save_post', [$this, 'clear_post_cache'], 10, 1);
            add_action('save_post', [$this, 'analyze_post_requirements'], 20, 2);
            add_action('delete_post', [$this, 'remove_post_requirements'], 10, 1);
        }

        if (get_option('shypdr_debug_enabled', false)) {
            add_action('wp_footer', [$this, 'render_debug_widget']);
            add_action('wp_enqueue_scripts', [$this, 'enqueue_debug_assets']);
        }

        // Runtime logging — opt-in only; default OFF so frontend never
        // writes transients on cache-miss requests. See Phase 1.3.
        if (get_option('shypdr_runtime_logging', false) && shypdr_is_mu_loader_active()) {
            add_action('wp_loaded', [$this, 'log_mu_filter_results']);
        }


    }

    /**
     * Lazy dependency-map accessor for admin code paths.
     */
    private static function get_dependency_map() {
        if (empty(self::$dependency_map)) {
            self::$dependency_map = SHYPDR_Dependency_Detector::get_dependency_map();
        }
        return self::$dependency_map;
    }

    /**
     * Log MU-loader filter results to a rotated file.
     *
     * Opt-in only (shypdr_runtime_logging option). Writes are appended to
     * a JSON-lines file under wp-content/uploads/shypdr-logs/. This avoids
     * the DB write that the previous transient-based log incurred on ~10%
     * of frontend requests.
     */
    public function log_mu_filter_results() {
        $data = shypdr_get_mu_filter_data();
        if (!$data || !$data['filtered']) {
            return;
        }

        // Sample only 10% of requests
        if (wp_rand(1, 10) !== 1) {
            return;
        }

        $log_file = self::ensure_runtime_log_file();
        if (!$log_file) {
            return;
        }

        // Directional TTFB proxy — PHP wall time from request start to
        // wp_loaded. Not full TTFB (that includes bytes-out), but enough
        // to see whether filtering is helping or hurting in aggregate.
        $elapsed_ms = null;
        if (isset($_SERVER['REQUEST_TIME_FLOAT'])) {
            $elapsed_ms = (int) round((microtime(true) - (float) $_SERVER['REQUEST_TIME_FLOAT']) * 1000);
        }

        $log = [
            'timestamp' => current_time('mysql'),
            'url' => isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : 'unknown',
            'needed_plugins' => array_slice($data['needed_plugins'] ?? [], 0, 10),
            'restricted_plugins' => array_slice($data['restricted_plugins'] ?? [], 0, 10),
            'plugins_loaded' => count($data['loaded_plugins']),
            'plugins_filtered' => $data['filtered_count'],
            'total_plugins' => $data['original_count'],
            'reduction_percent' => $data['reduction_percent'] . '%',
            'elapsed_ms' => $elapsed_ms,
            'mu_loader' => true,
        ];

        // Naive size-based rotation. error_log type 3 is a fast append; no
        // lock, no DB round-trip.
        if (file_exists($log_file) && filesize($log_file) > 100 * 1024) {
            @rename($log_file, $log_file . '.1');
        }

        @error_log(wp_json_encode($log) . "\n", 3, $log_file);
    }

    /**
     * Create the log directory on demand and lock it down with .htaccess +
     * an index.html. Returns the absolute log file path, or '' if the
     * directory can't be created.
     */
    private static function ensure_runtime_log_file() {
        $upload_dir = wp_upload_dir();
        if (!empty($upload_dir['error'])) {
            return '';
        }

        $log_dir = trailingslashit($upload_dir['basedir']) . 'shypdr-logs';

        if (!is_dir($log_dir)) {
            if (!wp_mkdir_p($log_dir)) {
                return '';
            }
            @file_put_contents($log_dir . '/.htaccess', "Require all denied\n");
            @file_put_contents($log_dir . '/index.html', '');
        }

        return $log_dir . '/runtime.log';
    }

    /**
     * Read the most recent log entries from the runtime log file.
     *
     * @param int $limit Maximum entries to return.
     * @return array Decoded log entries, newest first.
     */
    public static function get_runtime_logs($limit = 20) {
        $log_file = self::ensure_runtime_log_file();
        if (!$log_file || !file_exists($log_file)) {
            return [];
        }

        $lines = @file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            return [];
        }

        $lines = array_slice($lines, -$limit);
        $entries = [];
        foreach (array_reverse($lines) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $entries[] = $decoded;
            }
        }
        return $entries;
    }

    /**
     * Read up to $limit log entries and aggregate them by URL pattern.
     *
     * Patterns collapse the third path segment onward to '*' so that
     * /shop/product/abc, /shop/product/def, etc. roll up to
     * /shop/product/* in the report.
     *
     * @param int $limit Maximum log entries to scan.
     * @return array {
     *     overall:  ['samples', 'median_reduction', 'median_elapsed_ms',
     *                'median_loaded', 'median_total'],
     *     patterns: list of ['pattern', 'samples', 'median_reduction',
     *                'median_elapsed_ms', 'median_loaded', 'median_total',
     *                'sample_url', 'last_seen'],
     *     no_savings: list of patterns whose median_reduction is 0%
     * }
     */
    public static function get_runtime_aggregates($limit = 2000) {
        $entries = self::get_runtime_logs($limit);
        if (empty($entries)) {
            return [
                'overall'    => [],
                'patterns'   => [],
                'no_savings' => [],
            ];
        }

        $all_reduction = [];
        $all_elapsed = [];
        $all_loaded = [];
        $all_total = [];
        $by_pattern = [];

        foreach ($entries as $entry) {
            $reduction = isset($entry['reduction_percent'])
                ? (float) rtrim($entry['reduction_percent'], '%')
                : 0.0;
            $elapsed = isset($entry['elapsed_ms']) ? (int) $entry['elapsed_ms'] : null;
            $loaded = isset($entry['plugins_loaded']) ? (int) $entry['plugins_loaded'] : 0;
            $total = isset($entry['total_plugins']) ? (int) $entry['total_plugins'] : 0;
            $url = $entry['url'] ?? '';
            $pattern = self::url_to_pattern($url);

            $all_reduction[] = $reduction;
            if ($elapsed !== null) {
                $all_elapsed[] = $elapsed;
            }
            $all_loaded[] = $loaded;
            $all_total[] = $total;

            if (!isset($by_pattern[$pattern])) {
                $by_pattern[$pattern] = [
                    'pattern'    => $pattern,
                    'reduction'  => [],
                    'elapsed'    => [],
                    'loaded'     => [],
                    'total'      => [],
                    'sample_url' => $url,
                    'last_seen'  => $entry['timestamp'] ?? '',
                ];
            }
            $by_pattern[$pattern]['reduction'][] = $reduction;
            if ($elapsed !== null) {
                $by_pattern[$pattern]['elapsed'][] = $elapsed;
            }
            $by_pattern[$pattern]['loaded'][] = $loaded;
            $by_pattern[$pattern]['total'][] = $total;

            // Keep the most-recent timestamp (entries are newest-first).
            if (empty($by_pattern[$pattern]['last_seen']) && !empty($entry['timestamp'])) {
                $by_pattern[$pattern]['last_seen'] = $entry['timestamp'];
            }
        }

        $patterns = [];
        foreach ($by_pattern as $row) {
            $patterns[] = [
                'pattern'           => $row['pattern'],
                'samples'           => count($row['reduction']),
                'median_reduction'  => self::median($row['reduction']),
                'median_elapsed_ms' => empty($row['elapsed']) ? null : self::median($row['elapsed']),
                'median_loaded'     => self::median($row['loaded']),
                'median_total'      => self::median($row['total']),
                'sample_url'        => $row['sample_url'],
                'last_seen'         => $row['last_seen'],
            ];
        }

        usort($patterns, function ($a, $b) {
            return $b['samples'] <=> $a['samples'];
        });

        $no_savings = array_values(array_filter($patterns, function ($p) {
            return $p['median_reduction'] <= 0 && $p['samples'] >= 3;
        }));

        return [
            'overall' => [
                'samples'           => count($entries),
                'median_reduction'  => self::median($all_reduction),
                'median_elapsed_ms' => empty($all_elapsed) ? null : self::median($all_elapsed),
                'median_loaded'     => self::median($all_loaded),
                'median_total'      => self::median($all_total),
            ],
            'patterns'   => $patterns,
            'no_savings' => $no_savings,
        ];
    }

    /**
     * Collapse a request URI into a roll-up pattern. Drops the query
     * string and replaces the third path segment onward with '*'.
     */
    private static function url_to_pattern($url) {
        if (empty($url) || !is_string($url)) {
            return '/';
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (!$path) {
            return '/';
        }

        $segments = array_values(array_filter(explode('/', $path), 'strlen'));
        if (count($segments) === 0) {
            return '/';
        }
        if (count($segments) <= 2) {
            return '/' . implode('/', $segments);
        }
        return '/' . $segments[0] . '/' . $segments[1] . '/*';
    }

    /**
     * Median of a numeric array. Returns 0 for empty input.
     */
    private static function median(array $values) {
        $values = array_values(array_filter($values, function ($v) {
            return $v !== null;
        }));
        $count = count($values);
        if ($count === 0) {
            return 0;
        }
        sort($values);
        $mid = (int) floor($count / 2);
        if ($count % 2) {
            return $values[$mid];
        }
        return ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /**
     * Clear post-specific cache when post is saved
     */
    public function clear_post_cache($post_id) {
        SHYPDR_Detection_Cache::clear_post_cache($post_id);
    }

    /**
     * Handle plugin activation/deactivation.
     *
     * The heavy work — dependency-map rebuild and restrictable-set scan —
     * reads dozens of plugin files (~50KB each) and runs regex over them.
     * On a 150-plugin site that can take 10-30 seconds, which exceeds
     * PHP-FPM timeout during a plugin-management HTTP request and yields
     * 502 Bad Gateway.
     *
     * We do only the cheap bits inline (cache clears, static reset) and
     * defer the heavy rebuilds to a single background cron event. The
     * event is debounced via wp_next_scheduled so bulk activations
     * (5 plugins toggled at once) coalesce into one rebuild.
     */
    public function handle_plugin_activation() {
        self::handle_plugin_change();
    }

    public function handle_plugin_deactivation() {
        self::handle_plugin_change();
    }

    /**
     * Shared cleanup + cron schedule for plugin activation/deactivation.
     */
    private static function handle_plugin_change() {
        // Cheap work — clear caches synchronously so the next request
        // doesn't see stale data.
        if (class_exists('SHYPDR_Detection_Cache')) {
            SHYPDR_Detection_Cache::clear_all_caches();
        }
        if (class_exists('SHYPDR_Requirements_Cache')) {
            SHYPDR_Requirements_Cache::clear();
        }
        self::$essential_plugins_cache = null;
        self::$dependency_map = [];

        // Heavy work — schedule the rebuild ~15 seconds out, debounced.
        if (!wp_next_scheduled('shypdr_deferred_rebuild')) {
            wp_schedule_single_event(time() + 15, 'shypdr_deferred_rebuild');
        }
    }

    /**
     * Analyze post content and cache plugin requirements
     *
     * @param int $post_id Post ID
     * @param WP_Post $post Post object
     */
    public function analyze_post_requirements($post_id, $post) {
        // Bail on revisions, autosaves, and the autosave flag.
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Only re-analyze published content.
        if ($post->post_status !== 'publish') {
            return;
        }

        // Skip non-public post types — they never appear on the public
        // frontend so they don't influence per-URL plugin requirements.
        $type_object = get_post_type_object($post->post_type);
        if ($type_object && empty($type_object->public)) {
            return;
        }

        // Debounce: if content was analyzed within the last 60 seconds and
        // post_modified hasn't moved, skip the (potentially expensive)
        // content re-analysis. This kills the thrash on ACF / meta saves
        // that trigger save_post without changing the post body.
        $last_analyzed_at = (int) get_post_meta($post_id, '_shypdr_analyzed_at', true);
        $last_analyzed_modified = (string) get_post_meta($post_id, '_shypdr_analyzed_modified', true);
        $current_modified = (string) $post->post_modified_gmt;

        if ($last_analyzed_at && (time() - $last_analyzed_at) < 60) {
            return;
        }
        if ($last_analyzed_modified !== '' && $last_analyzed_modified === $current_modified) {
            return;
        }

        SHYPDR_Requirements_Cache::update_post_requirements($post_id);

        update_post_meta($post_id, '_shypdr_analyzed_at', time());
        update_post_meta($post_id, '_shypdr_analyzed_modified', $current_modified);
    }

    /**
     * Remove post requirements from cache when post is deleted
     *
     * @param int $post_id Post ID
     */
    public function remove_post_requirements($post_id) {
        SHYPDR_Requirements_Cache::remove_post_requirements($post_id);
    }

    /**
     * NOTE: Dependency map is now auto-detected by SHYPDR_Dependency_Detector
     *
     * The dependency map is no longer hardcoded. Instead, it is:
     * 1. Automatically detected by scanning plugin headers and code
     * 2. Stored in database option 'shypdr_dependency_map'
     * 3. Rebuilt on plugin activation/deactivation
     * 4. Can be customized via filter: apply_filters('shypdr_dependency_map', $map)
     *
     * To add custom dependencies programmatically:
     *
     * add_filter('shypdr_dependency_map', function($map) {
     *     $map['my-plugin'] = [
     *         'depends_on' => ['parent-plugin'],
     *         'plugins_depending' => []
     *     ];
     *     return $map;
     * });
     *
     * Or use the admin UI: Settings → Samybaxy's Hyperdrive → Dependencies
     */

    /**
     * Handle clear logs request
     */
    public function handle_clear_logs_request() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification handled in called methods based on action type
        if (!isset($_POST['shypdr_action'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification handled in called methods based on action type
        $action = sanitize_text_field( wp_unslash( $_POST['shypdr_action'] ) );

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
        if ( ! isset( $_POST['shypdr_rebuild_cache_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['shypdr_rebuild_cache_nonce'] ) ), 'shypdr_rebuild_cache_action' ) ) {
            wp_die( esc_html__( 'Security check failed', 'samybaxy-hyperdrive' ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied', 'samybaxy-hyperdrive' ) );
        }

        $count = SHYPDR_Requirements_Cache::rebuild_lookup_table();

        wp_safe_redirect(add_query_arg('shypdr_cache_rebuilt', $count, admin_url('options-general.php?page=shypdr-settings')));
        exit;
    }

    /**
     * Clear performance logs
     */
    public function clear_performance_logs() {
        if ( ! isset( $_POST['shypdr_clear_logs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['shypdr_clear_logs_nonce'] ) ), 'shypdr_clear_logs_action' ) ) {
            wp_die( esc_html__( 'Security check failed', 'samybaxy-hyperdrive' ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied', 'samybaxy-hyperdrive' ) );
        }

        // Wipe rotated runtime log file (transient log was retired in 6.2).
        $upload_dir = wp_upload_dir();
        if (empty($upload_dir['error'])) {
            $log_dir = trailingslashit($upload_dir['basedir']) . 'shypdr-logs';
            foreach (['runtime.log', 'runtime.log.1'] as $name) {
                $path = $log_dir . '/' . $name;
                if (file_exists($path)) {
                    @unlink($path);
                }
            }
        }
        delete_transient('shypdr_logs'); // Legacy cleanup for pre-6.2 installs
        self::$log_messages = [];

        wp_safe_redirect(add_query_arg('shypdr_logs_cleared', '1', admin_url('options-general.php?page=shypdr-settings')));
        exit;
    }

    /**
     * Register admin menu
     */
    public function register_admin_menu() {
        add_options_page(
            'Samybaxy\'s Hyperdrive',
            'Samybaxy\'s Hyperdrive',
            'manage_options',
            'shypdr-settings',
            [$this, 'render_settings_page']
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('shypdr_settings', 'shypdr_enabled', [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 0,
        ]);
        register_setting('shypdr_settings', 'shypdr_debug_enabled', [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 0,
        ]);
        register_setting('shypdr_settings', 'shypdr_runtime_logging', [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 0,
        ]);

    }

    /**
     * Enqueue admin styles
     */
    public function enqueue_admin_assets($hook) {
        if ($hook !== 'settings_page_shypdr-settings') {
            return;
        }
        wp_enqueue_style('shypdr-admin', SHYPDR_URL . 'assets/css/admin-styles.css', [], SHYPDR_VERSION);
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied', 'samybaxy-hyperdrive' ) );
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

        if ( 'performance' === $active_tab ) {
            $this->render_performance_page();
            return;
        }

        $enabled        = (int) get_option('shypdr_enabled', 0);
        $debug_enabled  = (int) get_option('shypdr_debug_enabled', 0);
        $runtime_logging = (int) get_option('shypdr_runtime_logging', 0);
        $logs = self::get_runtime_logs(20);
        $mu_loader_active = shypdr_is_mu_loader_active();

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Samybaxy\'s Hyperdrive Settings', 'samybaxy-hyperdrive' ); ?></h1>

            <?php
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice, no action taken
            if ( isset( $_GET['shypdr_logs_cleared'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['shypdr_logs_cleared'] ) ) ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong><?php esc_html_e( 'Success!', 'samybaxy-hyperdrive' ); ?></strong> <?php esc_html_e( 'Performance logs have been cleared.', 'samybaxy-hyperdrive' ); ?></p>
                </div>
            <?php endif; ?>

            <?php
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice, no action taken
            if ( isset( $_GET['shypdr_cache_rebuilt'] ) ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong><?php esc_html_e( 'Success!', 'samybaxy-hyperdrive' ); ?></strong>
                    <?php
                    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice, no action taken
                    $pages_count = intval( sanitize_text_field( wp_unslash( $_GET['shypdr_cache_rebuilt'] ) ) );
                    printf(
                        /* translators: %d: number of pages analyzed */
                        esc_html__( 'Requirements cache rebuilt. Analyzed %d pages.', 'samybaxy-hyperdrive' ),
                        absint( $pages_count )
                    );
                    ?></p>
                </div>
            <?php endif; ?>

            <!-- MU-Loader Status Banner -->
            <div style="background: <?php echo esc_attr( $mu_loader_active ? '#d4edda' : '#f8d7da' ); ?>; padding: 20px; margin: 20px 0; border-left: 4px solid <?php echo esc_attr( $mu_loader_active ? '#28a745' : '#dc3545' ); ?>; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2 style="margin-top: 0;">
                    <?php if ( $mu_loader_active ) : ?>
                        <?php esc_html_e( 'MU-Loader Active - Real Filtering Enabled', 'samybaxy-hyperdrive' ); ?>
                    <?php else : ?>
                        <?php esc_html_e( 'MU-Loader Not Installed - Filtering Won\'t Work', 'samybaxy-hyperdrive' ); ?>
                    <?php endif; ?>
                </h2>
                <?php if ( $mu_loader_active ) : ?>
                    <p style="color: #155724; margin-bottom: 0;">
                        <?php esc_html_e( 'The MU-loader is installed and filtering plugins before they load. This is the correct setup for actual performance gains.', 'samybaxy-hyperdrive' ); ?>
                    </p>
                <?php else : ?>
                    <p style="color: #721c24;">
                        <strong><?php esc_html_e( 'Without the MU-loader, plugin filtering cannot work.', 'samybaxy-hyperdrive' ); ?></strong>
                        <?php esc_html_e( 'Regular plugins load too late to filter out other plugins.', 'samybaxy-hyperdrive' ); ?>
                    </p>
                    <p>
                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'options-general.php?page=shypdr-settings&shypdr_install_mu=1' ), 'shypdr_install_mu' ) ); ?>"
                           class="button button-primary">
                            <?php esc_html_e( 'Install MU-Loader Now', 'samybaxy-hyperdrive' ); ?>
                        </a>
                    </p>
                <?php endif; ?>
            </div>

            <!-- Scanner / Dependencies / Performance Section -->
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin: 20px 0;">
                <div style="background: white; padding: 20px; border-left: 4px solid #667eea; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                    <h2 style="margin-top: 0;"><?php esc_html_e( 'Intelligent Plugin Scanner', 'samybaxy-hyperdrive' ); ?></h2>
                    <p><?php esc_html_e( 'Use AI-powered heuristics to automatically detect which plugins are essential for your site. The scanner analyzes all active plugins and categorizes them as critical, conditional, or optional.', 'samybaxy-hyperdrive' ); ?></p>
                    <a href="<?php echo esc_url( admin_url( 'options-general.php?page=shypdr-settings&tab=scanner' ) ); ?>" class="button button-primary button-large">
                        <?php esc_html_e( 'Manage Essential Plugins', 'samybaxy-hyperdrive' ); ?>
                    </a>
                </div>

                <div style="background: white; padding: 20px; border-left: 4px solid #28a745; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                    <h2 style="margin-top: 0;"><?php esc_html_e( 'Plugin Dependencies', 'samybaxy-hyperdrive' ); ?></h2>
                    <p><?php esc_html_e( 'View automatically detected plugin dependencies. Dependencies are discovered by analyzing plugin headers, code patterns, and ecosystem relationships.', 'samybaxy-hyperdrive' ); ?></p>
                    <a href="<?php echo esc_url( admin_url( 'options-general.php?page=shypdr-settings&tab=dependencies' ) ); ?>" class="button button-secondary button-large">
                        <?php esc_html_e( 'View Dependency Map', 'samybaxy-hyperdrive' ); ?>
                    </a>
                </div>

                <div style="background: white; padding: 20px; border-left: 4px solid #d63638; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                    <h2 style="margin-top: 0;"><?php esc_html_e( 'Performance Insights', 'samybaxy-hyperdrive' ); ?></h2>
                    <p><?php esc_html_e( 'Aggregated stats from the runtime log: median plugin reduction per URL pattern, PHP-time proxy for TTFB, and patterns where filtering isn\'t helping.', 'samybaxy-hyperdrive' ); ?></p>
                    <a href="<?php echo esc_url( admin_url( 'options-general.php?page=shypdr-settings&tab=performance' ) ); ?>" class="button button-secondary button-large">
                        <?php esc_html_e( 'Open Performance Tab', 'samybaxy-hyperdrive' ); ?>
                    </a>
                </div>
            </div>

            <!-- Smart Content Detection -->
            <?php
            $cache_stats = SHYPDR_Requirements_Cache::get_stats();
            ?>
            <div style="background: white; padding: 20px; margin: 20px 0; border-left: 4px solid #17a2b8; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2 style="margin-top: 0;"><?php esc_html_e( 'Smart Content Detection', 'samybaxy-hyperdrive' ); ?></h2>
                <p><?php esc_html_e( 'Analyzes page content (shortcodes, Elementor widgets, Gutenberg blocks) to detect which plugins each page needs. This enables O(1) lookup for maximum performance.', 'samybaxy-hyperdrive' ); ?></p>
                <div style="display: flex; gap: 15px; align-items: center; margin: 15px 0;">
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="shypdr_action" value="rebuild_cache" />
                        <?php wp_nonce_field( 'shypdr_rebuild_cache_action', 'shypdr_rebuild_cache_nonce' ); ?>
                        <button type="submit" class="button button-secondary" onclick="return confirm('<?php echo esc_js( __( 'This will analyze all published pages. Continue?', 'samybaxy-hyperdrive' ) ); ?>');">
                            <?php esc_html_e( 'Rebuild Requirements Cache', 'samybaxy-hyperdrive' ); ?>
                        </button>
                    </form>
                    <span style="color: #666; font-size: 13px;">
                        <?php
                        printf(
                            /* translators: 1: number of pages cached, 2: cache size in KB */
                            esc_html__( '%1$s pages cached (%2$s KB)', 'samybaxy-hyperdrive' ),
                            '<strong>' . esc_html( $cache_stats['total_entries'] ) . '</strong>',
                            esc_html( $cache_stats['size_kb'] )
                        );
                        ?>
                    </span>
                </div>
                <p class="description"><?php esc_html_e( 'Run this after bulk content changes or when conditional loading isn\'t working correctly.', 'samybaxy-hyperdrive' ); ?></p>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields( 'shypdr_settings' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="shypdr_enabled"><?php esc_html_e( 'Enable Plugin Filtering', 'samybaxy-hyperdrive' ); ?></label>
                        </th>
                        <td>
                            <input type="hidden" name="shypdr_enabled" value="0" />
                            <input type="checkbox" id="shypdr_enabled" name="shypdr_enabled" value="1"
                                <?php checked( $enabled, 1 ); ?>
                                <?php echo ! $mu_loader_active ? 'style="opacity: 0.5;"' : ''; ?> />
                            <?php if ( ! $mu_loader_active ) : ?>
                                <span style="color: #dc3545; font-weight: bold;"><?php esc_html_e( 'Install MU-Loader first!', 'samybaxy-hyperdrive' ); ?></span>
                            <?php endif; ?>
                            <p class="description">
                                <?php esc_html_e( 'When enabled, loads only essential plugins per page for better performance.', 'samybaxy-hyperdrive' ); ?>
                                <?php if ( ! $mu_loader_active ) : ?>
                                    <br><strong style="color: #dc3545;"><?php esc_html_e( 'Requires MU-Loader to actually work.', 'samybaxy-hyperdrive' ); ?></strong>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="shypdr_debug_enabled"><?php esc_html_e( 'Enable Debug Widget', 'samybaxy-hyperdrive' ); ?></label>
                        </th>
                        <td>
                            <input type="hidden" name="shypdr_debug_enabled" value="0" />
                            <input type="checkbox" id="shypdr_debug_enabled" name="shypdr_debug_enabled" value="1"
                                <?php checked( $debug_enabled, 1 ); ?> />
                            <p class="description"><?php esc_html_e( 'Show floating debug widget on frontend with performance stats (admins only).', 'samybaxy-hyperdrive' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="shypdr_runtime_logging"><?php esc_html_e( 'Runtime Logging', 'samybaxy-hyperdrive' ); ?></label>
                        </th>
                        <td>
                            <input type="hidden" name="shypdr_runtime_logging" value="0" />
                            <input type="checkbox" id="shypdr_runtime_logging" name="shypdr_runtime_logging" value="1"
                                <?php checked( $runtime_logging, 1 ); ?> />
                            <p class="description"><?php esc_html_e( 'Sample 10% of filtered frontend requests to a rotated log file under uploads/shypdr-logs/. Off by default — leave off in production unless diagnosing an issue (file I/O still costs a few hundred microseconds per sampled request).', 'samybaxy-hyperdrive' ); ?></p>
                        </td>
                    </tr>

                </table>
                <?php submit_button(); ?>
            </form>

            <?php if ( ! empty( $logs ) ) : ?>
                <hr>
                <h2><?php esc_html_e( 'Recent Performance Logs', 'samybaxy-hyperdrive' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'These logs show which plugins were loaded on each page request.', 'samybaxy-hyperdrive' ); ?>
                    <?php if ( $mu_loader_active ) : ?>
                        <span style="color: #28a745;"><?php esc_html_e( 'Using MU-loader for real filtering', 'samybaxy-hyperdrive' ); ?></span>
                    <?php else : ?>
                        <span style="color: #dc3545;"><?php esc_html_e( 'Logs show intended filtering, not actual (MU-loader not installed)', 'samybaxy-hyperdrive' ); ?></span>
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
                                            $restricted_sample = array_slice($log['restricted_plugins'] ?? [], 0, 3);
                                            echo esc_html('-' . implode(', -', $restricted_sample));
                                            ?>
                                        </summary>
                                        <div style="margin-top: 10px; padding: 10px; background: #f5f5f5; border-radius: 3px;">
                                            <?php if (!empty($log['needed_plugins'])): ?>
                                                <strong><?php esc_html_e('Needed:', 'samybaxy-hyperdrive'); ?></strong>
                                                <ul style="margin: 5px 0 10px; padding-left: 20px;">
                                                    <?php foreach ($log['needed_plugins'] as $plugin): ?>
                                                        <li><?php echo esc_html($plugin); ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            <?php endif; ?>
                                            <?php if (!empty($log['restricted_plugins'])): ?>
                                                <strong><?php esc_html_e('Restricted:', 'samybaxy-hyperdrive'); ?></strong>
                                                <ul style="margin: 5px 0; padding-left: 20px;">
                                                    <?php foreach ($log['restricted_plugins'] as $plugin): ?>
                                                        <li><?php echo esc_html($plugin); ?></li>
                                                    <?php endforeach; ?>
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
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="shypdr_action" value="clear_logs" />
                        <?php wp_nonce_field('shypdr_clear_logs_action', 'shypdr_clear_logs_nonce'); ?>
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
                    <li><strong>Plugin Version:</strong> <?php echo esc_html(SHYPDR_VERSION); ?></li>
                    <li><strong>MU-Loader:</strong> <?php echo $mu_loader_active ? '✅ Active (v' . esc_html(SHYPDR_MU_LOADER_VERSION) . ')' : '❌ Not Installed'; ?></li>
                    <li><strong>Total Active Plugins:</strong> <?php echo count(get_option('active_plugins', [])); ?></li>
                    <li><strong>Essential Plugins Configured:</strong> <?php echo count(get_option('shypdr_essential_plugins', [])); ?></li>
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

        wp_enqueue_style('shypdr-debug', SHYPDR_URL . 'assets/css/debug-widget.css', [], SHYPDR_VERSION);
        wp_enqueue_script('shypdr-debug', SHYPDR_URL . 'assets/js/debug-widget.js', [], SHYPDR_VERSION, true);
    }

    /**
     * Render Essential Plugins management page
     */
    public function render_essential_plugins_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied', 'samybaxy-hyperdrive' ) );
        }

        // Handle form submission
        if (isset($_POST['shypdr_save_essential']) && check_admin_referer('shypdr_essential_plugins', 'shypdr_essential_nonce')) {
            $essential_plugins = isset($_POST['shypdr_essential']) ? array_map('sanitize_text_field', wp_unslash($_POST['shypdr_essential'])) : [];
            update_option('shypdr_essential_plugins', $essential_plugins);

            self::$essential_plugins_cache = null;
            SHYPDR_Detection_Cache::clear_all_caches();

            echo '<div class="notice notice-success is-dismissible"><p><strong>Essential plugins updated successfully!</strong></p></div>';
        }

        // Handle rescan
        if (isset($_POST['shypdr_rescan']) && check_admin_referer('shypdr_rescan_plugins', 'shypdr_rescan_nonce')) {
            SHYPDR_Plugin_Scanner::clear_cache();
            $analysis = SHYPDR_Plugin_Scanner::scan_active_plugins();
            update_option('shypdr_plugin_analysis', $analysis);

            SHYPDR_Plugin_Scanner::get_essential_plugins(true);

            self::$essential_plugins_cache = null;
            SHYPDR_Detection_Cache::clear_all_caches();

            echo '<div class="notice notice-success is-dismissible"><p><strong>Plugin scan completed!</strong> Found ' . count($analysis['critical']) . ' critical plugins and automatically marked them as essential.</p></div>';
        }

        $analysis = get_option('shypdr_plugin_analysis', false);
        if ($analysis === false) {
            // No cached analysis yet — show a placeholder and queue a
            // background scan rather than running the heavy scanner inline
            // (which reads up to 500KB per plugin × N plugins and can 502
            // PHP-FPM on large sites).
            if (!wp_next_scheduled('shypdr_deferred_rebuild')) {
                wp_schedule_single_event(time() + 10, 'shypdr_deferred_rebuild');
            }
            $analysis = [
                'critical'      => [],
                'conditional'   => [],
                'optional'      => [],
                'analyzed_at'   => '',
                'total_plugins' => 0,
            ];
            echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'Scan pending.', 'samybaxy-hyperdrive' ) . '</strong> ' . esc_html__( 'A background scan has been scheduled. Refresh this page in a minute to see results.', 'samybaxy-hyperdrive' ) . '</p></div>';
        }

        $current_essential = get_option('shypdr_essential_plugins', []);
        $cache_stats = SHYPDR_Detection_Cache::get_cache_stats();

        ?>
        <div class="wrap">
            <h1>Samybaxy's Hyperdrive - Essential Plugins</h1>

            <a href="<?php echo esc_url(admin_url('options-general.php?page=shypdr-settings')); ?>" class="button button-secondary" style="margin-bottom: 15px;">
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
                        <div style="font-size: 24px; font-weight: bold; color: #856404;" id="shypdr-conditional-count"><?php echo esc_html($conditional_count); ?></div>
                        <small>Load based on page detection</small>
                    </div>
                    <div style="padding: 15px; background: #d1ecf1; border: 1px solid #bee5eb; border-radius: 4px;">
                        <h3 style="margin: 0 0 5px 0; color: #0c5460;">Filtered</h3>
                        <div style="font-size: 24px; font-weight: bold; color: #0c5460;" id="shypdr-filtered-count"><?php echo esc_html($filtered_count); ?></div>
                        <small>Filtered unless detected</small>
                    </div>
                </div>

                <details style="margin: 15px 0;">
                    <summary style="cursor: pointer; color: #666; font-size: 13px;">Scanner categorization (for reference)</summary>
                    <div style="margin-top: 10px; padding: 10px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 3px;">
                        <p style="margin: 5px 0; font-size: 13px;"><strong>Critical (score ≥ 80):</strong> <?php echo count($analysis['critical']); ?> plugins</p>
                        <p style="margin: 5px 0; font-size: 13px;"><strong>Conditional (score 40-79):</strong> <?php echo count($analysis['conditional']); ?> plugins</p>
                        <p style="margin: 5px 0; font-size: 13px;"><strong>Optional (score < 40):</strong> <?php echo count($analysis['optional']); ?> plugins</p>
                    </div>
                </details>

                <form method="post" style="display: inline;">
                    <?php wp_nonce_field('shypdr_rescan_plugins', 'shypdr_rescan_nonce'); ?>
                    <button type="submit" name="shypdr_rescan" class="button button-secondary">
                        🔍 Rescan All Plugins
                    </button>
                </form>
                <small style="margin-left: 10px; color: #666;">Run this after installing/updating plugins</small>
            </div>

            <form method="post">
                <?php wp_nonce_field('shypdr_essential_plugins', 'shypdr_essential_nonce'); ?>

                <h2>Select Essential Plugins</h2>
                <p>Check the plugins that should <strong>always load</strong> on every page:</p>

                <?php
                foreach (['critical' => 'Critical Plugins', 'conditional' => 'Conditional Plugins', 'optional' => 'Optional Plugins'] as $category_key => $category_label):
                    $plugins_in_category = $analysis[$category_key];
                    if (empty($plugins_in_category)) continue;
                ?>
                    <h3><?php echo esc_html($category_label); ?> (<?php echo count($plugins_in_category); ?>)</h3>
                    <div class="shypdr-plugin-list">
                        <?php foreach ($plugins_in_category as $plugin): ?>
                            <div class="shypdr-plugin-card <?php echo esc_attr($plugin['category']); ?>">
                                <label style="display: flex; align-items: start; cursor: pointer;">
                                    <input type="checkbox"
                                           name="shypdr_essential[]"
                                           value="<?php echo esc_attr($plugin['slug']); ?>"
                                           <?php checked(in_array($plugin['slug'], $current_essential)); ?>
                                           style="margin: 4px 10px 0 0;">
                                    <div style="flex: 1;">
                                        <div class="shypdr-plugin-name">
                                            <?php echo esc_html($plugin['name']); ?>
                                            <span class="shypdr-plugin-score <?php echo esc_attr($plugin['category']); ?>">
                                                Score: <?php echo esc_html($plugin['score']); ?>
                                            </span>
                                        </div>
                                        <div class="shypdr-plugin-desc"><?php echo esc_html($plugin['description']); ?></div>
                                        <?php if (!empty($plugin['reasons'])): ?>
                                            <div class="shypdr-plugin-reasons">
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
                    <button type="submit" name="shypdr_save_essential" class="button button-primary button-hero">
                        💾 Save Essential Plugins
                    </button>
                    <a href="<?php echo esc_url(admin_url('options-general.php?page=shypdr-settings')); ?>" class="button button-secondary button-hero" style="margin-left: 10px;">
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

        $mu_data = shypdr_get_mu_filter_data();
        $mu_loader_active = shypdr_is_mu_loader_active();

        ?>
        <div id="shypdr-debug-widget" class="shypdr-debug-widget">
            <div class="shypdr-debug-toggle">
                <span class="shypdr-debug-title">⚡ Hyperdrive</span>
            </div>
            <div class="shypdr-debug-content">
                <?php if ($mu_loader_active && $mu_data): ?>
                    <div class="shypdr-debug-stat" style="background: #d4edda; padding: 5px; border-radius: 3px; margin-bottom: 10px;">
                        <strong>✅ MU-Loader Active</strong>
                    </div>
                    <div class="shypdr-debug-stat">
                        <strong>Total Plugins:</strong> <?php echo esc_html($mu_data['original_count']); ?>
                    </div>
                    <div class="shypdr-debug-stat">
                        <strong>Loaded:</strong> <?php echo esc_html(count($mu_data['loaded_plugins'])); ?>
                    </div>
                    <div class="shypdr-debug-stat">
                        <strong>Filtered:</strong> <?php echo esc_html($mu_data['filtered_count']); ?>
                    </div>
                    <div class="shypdr-debug-stat highlight">
                        <strong>Reduction:</strong> <?php echo esc_html($mu_data['reduction_percent']); ?>%
                    </div>
                    <hr>
                    <div class="shypdr-debug-section">
                        <strong class="shypdr-section-title">
                            ✓ Loaded Plugins (<?php echo esc_html(count($mu_data['loaded_plugins'])); ?>)
                        </strong>
                        <div class="shypdr-plugin-list-scrollable">
                            <ul>
                                <?php foreach ($mu_data['loaded_plugins'] as $plugin): ?>
                                    <li><span class="shypdr-plugin-bullet">•</span> <?php echo esc_html($plugin); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                    <hr>
                    <div class="shypdr-debug-section">
                        <strong class="shypdr-section-title shypdr-collapsible" onclick="this.parentElement.classList.toggle('expanded')">
                            ⊖ Filtered Out (<?php echo esc_html($mu_data['filtered_count']); ?>)
                        </strong>
                        <div class="shypdr-plugin-list-scrollable shypdr-collapsible-content">
                            <ul>
                                <?php
                                $all_plugins = !empty($mu_data['original_plugins']) ? $mu_data['original_plugins'] : [];
                                $loaded_plugins = $mu_data['loaded_plugins'];

                                foreach ($all_plugins as $plugin_path):
                                    if (!in_array($plugin_path, $loaded_plugins, true)):
                                ?>
                                    <li><span class="shypdr-plugin-bullet">•</span> <?php echo esc_html($plugin_path); ?></li>
                                <?php
                                    endif;
                                endforeach;
                                ?>
                            </ul>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="shypdr-debug-stat" style="background: #f8d7da; padding: 5px; border-radius: 3px; margin-bottom: 10px;">
                        <strong>⚠️ MU-Loader Not Active</strong>
                    </div>
                    <p style="color: #721c24; font-size: 12px;">
                        Plugin filtering is not working. Install the MU-Loader from Settings → Samybaxy's Hyperdrive.
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
            wp_die( esc_html__( 'Access denied', 'samybaxy-hyperdrive' ) );
        }

        // Handle rebuild request
        if ( isset( $_POST['shypdr_rebuild_dependencies'] ) && check_admin_referer( 'shypdr_rebuild_dependencies', 'shypdr_rebuild_deps_nonce' ) ) {
            $count = SHYPDR_Dependency_Detector::rebuild_dependency_map();
            echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html__( 'Success!', 'samybaxy-hyperdrive' ) . '</strong> ';
            printf(
                /* translators: %d: number of plugins analyzed */
                esc_html__( 'Dependency map rebuilt. Analyzed %d plugins.', 'samybaxy-hyperdrive' ),
                absint( $count )
            );
            echo '</p></div>';
        }

        $dependency_map = SHYPDR_Dependency_Detector::get_dependency_map();
        $stats = SHYPDR_Dependency_Detector::get_stats();

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Samybaxy\'s Hyperdrive - Plugin Dependencies', 'samybaxy-hyperdrive' ); ?></h1>

            <a href="<?php echo esc_url( admin_url( 'options-general.php?page=shypdr-settings' ) ); ?>" class="button button-secondary" style="margin-bottom: 15px;">
                <?php esc_html_e( '← Back to Settings', 'samybaxy-hyperdrive' ); ?>
            </a>

            <div class="notice notice-info">
                <p><strong><?php esc_html_e( 'About Plugin Dependencies', 'samybaxy-hyperdrive' ); ?></strong></p>
                <p><?php esc_html_e( 'Dependencies are automatically detected by analyzing plugin headers, code patterns, and ecosystem relationships. When a plugin is loaded, all its dependencies are automatically loaded too.', 'samybaxy-hyperdrive' ); ?></p>
            </div>

            <!-- Statistics -->
            <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2><?php esc_html_e( 'Dependency Statistics', 'samybaxy-hyperdrive' ); ?></h2>
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin: 20px 0;">
                    <div style="padding: 15px; background: #e7f3ff; border: 1px solid #b3d9ff; border-radius: 4px;">
                        <h3 style="margin: 0 0 5px 0; color: #004085;"><?php esc_html_e( 'Total Plugins', 'samybaxy-hyperdrive' ); ?></h3>
                        <div style="font-size: 24px; font-weight: bold; color: #004085;"><?php echo esc_html( $stats['total_plugins'] ); ?></div>
                        <small><?php esc_html_e( 'In dependency map', 'samybaxy-hyperdrive' ); ?></small>
                    </div>
                    <div style="padding: 15px; background: #fff3cd; border: 1px solid #ffeeba; border-radius: 4px;">
                        <h3 style="margin: 0 0 5px 0; color: #856404;"><?php esc_html_e( 'With Dependencies', 'samybaxy-hyperdrive' ); ?></h3>
                        <div style="font-size: 24px; font-weight: bold; color: #856404;"><?php echo esc_html( $stats['plugins_with_dependencies'] ); ?></div>
                        <small><?php esc_html_e( 'Plugins requiring others', 'samybaxy-hyperdrive' ); ?></small>
                    </div>
                    <div style="padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px;">
                        <h3 style="margin: 0 0 5px 0; color: #155724;"><?php esc_html_e( 'Relationships', 'samybaxy-hyperdrive' ); ?></h3>
                        <div style="font-size: 24px; font-weight: bold; color: #155724;"><?php echo esc_html( $stats['total_dependency_relationships'] ); ?></div>
                        <small><?php esc_html_e( 'Total dependencies', 'samybaxy-hyperdrive' ); ?></small>
                    </div>
                </div>

                <form method="post" style="margin-top: 20px;">
                    <?php wp_nonce_field( 'shypdr_rebuild_dependencies', 'shypdr_rebuild_deps_nonce' ); ?>
                    <button type="submit" name="shypdr_rebuild_dependencies" class="button button-primary" onclick="return confirm('<?php echo esc_js( __( 'Rebuild dependency map? This will scan all active plugins.', 'samybaxy-hyperdrive' ) ); ?>');">
                        <?php esc_html_e( '🔄 Rebuild Dependency Map', 'samybaxy-hyperdrive' ); ?>
                    </button>
                    <small style="margin-left: 10px; color: #666;"><?php esc_html_e( 'Detection method: Heuristic scanning (plugin headers, code analysis, patterns)', 'samybaxy-hyperdrive' ); ?></small>
                </form>
            </div>

            <!-- Dependency List -->
            <h2><?php esc_html_e( 'Plugin Dependency Map', 'samybaxy-hyperdrive' ); ?></h2>

            <table class="shypdr-dep-table">
                <thead>
                    <tr>
                        <th style="width: 25%;"><?php esc_html_e( 'Plugin', 'samybaxy-hyperdrive' ); ?></th>
                        <th style="width: 35%;"><?php esc_html_e( 'Depends On', 'samybaxy-hyperdrive' ); ?></th>
                        <th style="width: 35%;"><?php esc_html_e( 'Required By', 'samybaxy-hyperdrive' ); ?></th>
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
                                <span class="shypdr-plugin-name"><?php echo esc_html( $plugin_slug ); ?></span>
                            </td>
                            <td>
                                <?php if ( ! empty( $depends_on ) ) : ?>
                                    <?php foreach ( $depends_on as $dep ) : ?>
                                        <span class="shypdr-dep-badge depends"><?php echo esc_html( $dep ); ?></span>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <span class="shypdr-dep-badge none"><?php esc_html_e( 'None', 'samybaxy-hyperdrive' ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ( ! empty( $required_by ) ) : ?>
                                    <?php foreach ( $required_by as $req ) : ?>
                                        <span class="shypdr-dep-badge required"><?php echo esc_html( $req ); ?></span>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <span class="shypdr-dep-badge none"><?php esc_html_e( 'None', 'samybaxy-hyperdrive' ); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div style="margin-top: 30px; padding: 20px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                <h3><?php esc_html_e( 'How Dependencies Are Detected', 'samybaxy-hyperdrive' ); ?></h3>
                <ul>
                    <li><strong><?php esc_html_e( 'WordPress 6.5+ Headers:', 'samybaxy-hyperdrive' ); ?></strong> <?php esc_html_e( 'Reads "Requires Plugins" header from plugin files', 'samybaxy-hyperdrive' ); ?></li>
                    <li><strong><?php esc_html_e( 'Code Analysis:', 'samybaxy-hyperdrive' ); ?></strong> <?php esc_html_e( 'Detects class_exists(), defined() checks for parent plugins', 'samybaxy-hyperdrive' ); ?></li>
                    <li><strong><?php esc_html_e( 'Naming Patterns:', 'samybaxy-hyperdrive' ); ?></strong> <?php esc_html_e( '"jet-*" depends on "jet-engine", "woocommerce-*" depends on "woocommerce"', 'samybaxy-hyperdrive' ); ?></li>
                    <li><strong><?php esc_html_e( 'Known Ecosystems:', 'samybaxy-hyperdrive' ); ?></strong> <?php esc_html_e( 'Built-in knowledge of major plugin families (Elementor, WooCommerce, LearnPress, etc.)', 'samybaxy-hyperdrive' ); ?></li>
                </ul>
                <p><strong><?php esc_html_e( 'Filter Hook:', 'samybaxy-hyperdrive' ); ?></strong> <?php esc_html_e( 'Developers can customize dependencies using the', 'samybaxy-hyperdrive' ); ?> <code>shypdr_dependency_map</code> <?php esc_html_e( 'filter.', 'samybaxy-hyperdrive' ); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Render the Performance Insights tab. Aggregates the rotated runtime
     * log file and surfaces per-URL-pattern reduction + median PHP time.
     * Admin-only; no runtime cost on the frontend.
     */
    public function render_performance_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Access denied', 'samybaxy-hyperdrive'));
        }

        $runtime_logging_on = (bool) get_option('shypdr_runtime_logging', false);
        $aggregates = self::get_runtime_aggregates(2000);
        $overall = $aggregates['overall'];
        $patterns = $aggregates['patterns'];
        $no_savings = $aggregates['no_savings'];
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Samybaxy\'s Hyperdrive — Performance Insights', 'samybaxy-hyperdrive' ); ?></h1>

            <a href="<?php echo esc_url( admin_url( 'options-general.php?page=shypdr-settings' ) ); ?>" class="button button-secondary" style="margin-bottom: 15px;">
                <?php esc_html_e( '← Back to Settings', 'samybaxy-hyperdrive' ); ?>
            </a>

            <?php if ( ! $runtime_logging_on ) : ?>
                <div class="notice notice-warning">
                    <p>
                        <strong><?php esc_html_e( 'Runtime logging is OFF.', 'samybaxy-hyperdrive' ); ?></strong>
                        <?php esc_html_e( 'This tab is empty until you enable it.', 'samybaxy-hyperdrive' ); ?>
                        <?php
                        printf(
                            ' <a href="%s">%s</a>',
                            esc_url( admin_url( 'options-general.php?page=shypdr-settings#shypdr_runtime_logging' ) ),
                            esc_html__( 'Enable on the Settings tab', 'samybaxy-hyperdrive' )
                        );
                        ?> <?php esc_html_e( '— samples 10% of filtered frontend requests to a rotated log file. Disable again once you\'ve gathered enough data.', 'samybaxy-hyperdrive' ); ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ( empty( $patterns ) ) : ?>
                <div style="padding: 20px; background: #f0f0f0; border: 1px solid #ddd; border-radius: 4px;">
                    <p><em><?php esc_html_e( 'No log entries yet. Enable runtime logging, browse a few frontend pages, then return here.', 'samybaxy-hyperdrive' ); ?></em></p>
                </div>
            <?php else : ?>

                <!-- Overall stats card -->
                <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                    <h2 style="margin-top: 0;"><?php esc_html_e( 'Overall', 'samybaxy-hyperdrive' ); ?></h2>
                    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px;">
                        <div style="padding: 15px; background: #e7f3ff; border: 1px solid #b3d9ff; border-radius: 4px;">
                            <h3 style="margin: 0 0 5px 0; color: #004085; font-size: 13px; text-transform: uppercase;"><?php esc_html_e( 'Samples', 'samybaxy-hyperdrive' ); ?></h3>
                            <div style="font-size: 26px; font-weight: bold; color: #004085;"><?php echo esc_html( number_format_i18n( $overall['samples'] ) ); ?></div>
                            <small><?php esc_html_e( 'Logged requests in window', 'samybaxy-hyperdrive' ); ?></small>
                        </div>
                        <div style="padding: 15px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px;">
                            <h3 style="margin: 0 0 5px 0; color: #155724; font-size: 13px; text-transform: uppercase;"><?php esc_html_e( 'Median Reduction', 'samybaxy-hyperdrive' ); ?></h3>
                            <div style="font-size: 26px; font-weight: bold; color: #155724;"><?php echo esc_html( number_format( (float) $overall['median_reduction'], 1 ) ); ?>%</div>
                            <small><?php esc_html_e( 'Plugins skipped vs total', 'samybaxy-hyperdrive' ); ?></small>
                        </div>
                        <div style="padding: 15px; background: #fff3cd; border: 1px solid #ffeeba; border-radius: 4px;">
                            <h3 style="margin: 0 0 5px 0; color: #856404; font-size: 13px; text-transform: uppercase;"><?php esc_html_e( 'Median PHP Time', 'samybaxy-hyperdrive' ); ?></h3>
                            <div style="font-size: 26px; font-weight: bold; color: #856404;">
                                <?php echo $overall['median_elapsed_ms'] !== null ? esc_html( number_format_i18n( (int) $overall['median_elapsed_ms'] ) ) . ' ms' : '—'; ?>
                            </div>
                            <small><?php esc_html_e( 'Request start → wp_loaded', 'samybaxy-hyperdrive' ); ?></small>
                        </div>
                        <div style="padding: 15px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px;">
                            <h3 style="margin: 0 0 5px 0; color: #721c24; font-size: 13px; text-transform: uppercase;"><?php esc_html_e( 'Median Loaded', 'samybaxy-hyperdrive' ); ?></h3>
                            <div style="font-size: 26px; font-weight: bold; color: #721c24;">
                                <?php echo esc_html( (int) $overall['median_loaded'] ); ?>
                                <span style="font-size: 14px; font-weight: normal;">/ <?php echo esc_html( (int) $overall['median_total'] ); ?></span>
                            </div>
                            <small><?php esc_html_e( 'Plugins loaded / total active', 'samybaxy-hyperdrive' ); ?></small>
                        </div>
                    </div>
                </div>

                <?php if ( ! empty( $no_savings ) ) : ?>
                    <div style="background: #fff3cd; padding: 20px; margin: 20px 0; border-left: 4px solid #f0ad4e; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                        <h2 style="margin-top: 0;"><?php esc_html_e( 'Patterns where filtering is not helping', 'samybaxy-hyperdrive' ); ?></h2>
                        <p><?php esc_html_e( 'These URL patterns consistently show 0% reduction across 3+ samples. Filtering overhead on these pages may exceed the savings — consider adding them to the manual unrestricted list, or reviewing whether the right plugins are detected as restrictable.', 'samybaxy-hyperdrive' ); ?></p>
                        <ul style="margin: 10px 0 0 20px;">
                            <?php foreach ( $no_savings as $row ) : ?>
                                <li>
                                    <code><?php echo esc_html( $row['pattern'] ); ?></code>
                                    <span style="color: #666;">— <?php
                                    printf(
                                        /* translators: 1: sample count, 2: median loaded plugins, 3: median total plugins */
                                        esc_html__( '%1$d samples, %2$d/%3$d plugins loaded', 'samybaxy-hyperdrive' ),
                                        (int) $row['samples'],
                                        (int) $row['median_loaded'],
                                        (int) $row['median_total']
                                    );
                                    ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <!-- Per-pattern breakdown -->
                <h2><?php esc_html_e( 'Per URL Pattern', 'samybaxy-hyperdrive' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'Patterns collapse the third path segment onward to "*", so /shop/product/abc and /shop/product/def roll up together.', 'samybaxy-hyperdrive' ); ?>
                </p>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 28%;"><?php esc_html_e( 'Pattern', 'samybaxy-hyperdrive' ); ?></th>
                            <th style="width: 10%;"><?php esc_html_e( 'Samples', 'samybaxy-hyperdrive' ); ?></th>
                            <th style="width: 14%;"><?php esc_html_e( 'Median Reduction', 'samybaxy-hyperdrive' ); ?></th>
                            <th style="width: 14%;"><?php esc_html_e( 'Median PHP Time', 'samybaxy-hyperdrive' ); ?></th>
                            <th style="width: 14%;"><?php esc_html_e( 'Loaded / Total', 'samybaxy-hyperdrive' ); ?></th>
                            <th style="width: 20%;"><?php esc_html_e( 'Last Seen', 'samybaxy-hyperdrive' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $patterns as $row ) : ?>
                            <?php
                            $is_zero = $row['median_reduction'] <= 0;
                            $reduction_color = $is_zero ? '#dc3545' : ( $row['median_reduction'] >= 30 ? '#28a745' : '#856404' );
                            ?>
                            <tr>
                                <td>
                                    <code style="font-size: 12px;"><?php echo esc_html( $row['pattern'] ); ?></code>
                                    <?php if ( ! empty( $row['sample_url'] ) && $row['sample_url'] !== $row['pattern'] ) : ?>
                                        <br><small style="color: #888;"><?php echo esc_html( substr( $row['sample_url'], 0, 80 ) ); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( (int) $row['samples'] ); ?></td>
                                <td>
                                    <strong style="color: <?php echo esc_attr( $reduction_color ); ?>;">
                                        <?php echo esc_html( number_format( (float) $row['median_reduction'], 1 ) ); ?>%
                                    </strong>
                                </td>
                                <td>
                                    <?php echo $row['median_elapsed_ms'] !== null ? esc_html( (int) $row['median_elapsed_ms'] ) . ' ms' : '—'; ?>
                                </td>
                                <td>
                                    <?php echo esc_html( (int) $row['median_loaded'] ); ?>
                                    <span style="color: #888;">/ <?php echo esc_html( (int) $row['median_total'] ); ?></span>
                                </td>
                                <td><?php echo esc_html( $row['last_seen'] ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div style="margin-top: 20px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                    <form method="post" style="display: inline;">
                        <input type="hidden" name="shypdr_action" value="clear_logs" />
                        <?php wp_nonce_field( 'shypdr_clear_logs_action', 'shypdr_clear_logs_nonce' ); ?>
                        <button type="submit" class="button button-secondary" onclick="return confirm('<?php echo esc_js( __( 'Clear the runtime log file?', 'samybaxy-hyperdrive' ) ); ?>');">
                            <?php esc_html_e( 'Clear Runtime Log', 'samybaxy-hyperdrive' ); ?>
                        </button>
                    </form>
                    <span style="margin-left: 15px; color: #666; font-size: 13px;">
                        <?php esc_html_e( 'Aggregates above are computed from the last 2000 logged samples.', 'samybaxy-hyperdrive' ); ?>
                    </span>
                </div>

            <?php endif; ?>
        </div>
        <?php
    }
}
