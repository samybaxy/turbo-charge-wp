<?php
/**
 * Plugin Name: Samybaxy's Hyperdrive
 * Plugin URI: https://github.com/samybaxy/samybaxy-hyperdrive
 * Description: Revolutionary plugin filtering - Load only essential plugins per page. Requires MU-plugin loader for actual performance gains.
 * Version: 6.1.3
 * Author: samybaxy
 * Author URI: https://github.com/samybaxy
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: samybaxy-hyperdrive
 * Requires at least: 6.4
 * Requires PHP: 8.2
 *
 * @package SamybaxyHyperdrive
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Core initialization constants
define('SHYPDR_VERSION', '6.1.3');
define('SHYPDR_DIR', plugin_dir_path(__FILE__));
define('SHYPDR_URL', plugin_dir_url(__FILE__));
define('SHYPDR_BASENAME', plugin_basename(__FILE__));

// PSR-style autoloader for SHYPDR_* classes. Heavy admin-only classes
// (~175 KB combined) no longer parse on every frontend request — they load
// only when first referenced.
spl_autoload_register(function ($class) {
    if (strpos($class, 'SHYPDR_') !== 0) {
        return;
    }
    $slug = strtolower(str_replace('_', '-', substr($class, 7)));
    $file = SHYPDR_DIR . 'includes/class-' . $slug . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Register the runtime init hook. SHYPDR_Main loads via the autoloader
// the first time it's referenced (when this hook fires).
add_action('plugins_loaded', [SHYPDR_Main::class, 'init'], 5);

/**
 * Rebuild the combined MU-loader payload whenever any of its source
 * options change. The payload is autoloaded so the MU-loader reads it
 * from the alloptions cache on every request (zero extra DB queries).
 *
 * @param string $option Option name that triggered the hook.
 */
function shypdr_maybe_rebuild_mu_payload($option) {
    static $sources = [
        'shypdr_enabled',
        'shypdr_restrictable_plugins',
        'shypdr_restriction_rules',
        'shypdr_url_requirements',
        'shypdr_dependency_map',
    ];

    if (!in_array($option, $sources, true)) {
        return;
    }

    if (class_exists('SHYPDR_Requirements_Cache')) {
        SHYPDR_Requirements_Cache::write_mu_payload();
    }
}
add_action('updated_option', 'shypdr_maybe_rebuild_mu_payload', 10, 1);
add_action('added_option', 'shypdr_maybe_rebuild_mu_payload', 10, 1);

/**
 * Purge any active page-cache plugin when Hyperdrive's filtering CONFIG
 * changes. Without this, cached HTML built under the previous plugin set
 * keeps serving until natural expiry, hiding the effect of the change
 * and leaving stale per-URL plugin assumptions in the cache.
 *
 * Triggered only for config options, NOT for shypdr_url_requirements —
 * that one updates on every save_post and would thrash the cache.
 *
 * @param string $option Option name that triggered the hook.
 */
function shypdr_maybe_purge_page_cache($option) {
    static $config_options = [
        'shypdr_enabled',
        'shypdr_restrictable_plugins',
        'shypdr_restriction_rules',
        'shypdr_dependency_map',
    ];

    if (!in_array($option, $config_options, true)) {
        return;
    }

    shypdr_purge_page_cache();
}
add_action('updated_option', 'shypdr_maybe_purge_page_cache', 20, 1);

/**
 * Call known purge APIs of installed page-cache plugins. Each call is
 * gated by function_exists / class_exists so this is a no-op when the
 * plugin isn't installed. Errors are swallowed because we don't want a
 * misbehaving cache plugin to abort a settings save.
 */
function shypdr_purge_page_cache() {
    // NitroPack
    if (function_exists('nitropack_sdk_purge_full_cache')) {
        try { nitropack_sdk_purge_full_cache(); } catch (Throwable $e) {}
    } elseif (class_exists('NitroPack\WordPress\NitroPack')) {
        try {
            do_action('nitropack_integration_purge_all');
        } catch (Throwable $e) {}
    }

    // WP Rocket
    if (function_exists('rocket_clean_domain')) {
        try { rocket_clean_domain(); } catch (Throwable $e) {}
    }

    // LiteSpeed Cache
    if (defined('LSCWP_V') || class_exists('LiteSpeed\Purge')) {
        do_action('litespeed_purge_all');
    }

    // WP Super Cache
    if (function_exists('wp_cache_clear_cache')) {
        try { wp_cache_clear_cache(); } catch (Throwable $e) {}
    }

    // W3 Total Cache
    if (function_exists('w3tc_pgcache_flush')) {
        try { w3tc_pgcache_flush(); } catch (Throwable $e) {}
    }

    // Cache Enabler
    if (class_exists('Cache_Enabler') && method_exists('Cache_Enabler', 'clear_complete_cache')) {
        try { Cache_Enabler::clear_complete_cache(); } catch (Throwable $e) {}
    }

    // SiteGround Optimizer
    if (function_exists('sg_cachepress_purge_cache')) {
        try { sg_cachepress_purge_cache(); } catch (Throwable $e) {}
    }
}

// Activation hook - Run intelligent plugin scan and install MU-loader
register_activation_hook(__FILE__, 'shypdr_activation_handler');

function shypdr_activation_handler() {
    // KEEP ACTIVATION FAST. Heavy work (dependency-map rebuild,
    // restrictable-set scan, content-analyzer) reads dozens of plugin
    // files synchronously — on a 100+ plugin site that exceeds PHP-FPM
    // timeout and yields 502 Bad Gateway. We do only the cheap, safe
    // bits here and defer the rest to a background cron event.

    // Set default options using add_option (won't overwrite existing)
    add_option('shypdr_enabled', false);
    add_option('shypdr_debug_enabled', false);

    // Flag that initial scan is still pending. Admin UI surfaces this
    // until the deferred cron has populated the data.
    update_option('shypdr_needs_setup', true);

    // CRITICAL: Install/update MU-loader during activation. Single file
    // copy — fast and safe even on large sites.
    shypdr_install_mu_loader();

    // Write a *minimal* MU-payload so the MU-loader has something valid
    // to read on the very first post-activation request. enabled=false
    // (which is the just-set default) means the MU-loader bails before
    // doing any filtering work, so an empty payload is fine here.
    if (class_exists('SHYPDR_Requirements_Cache')) {
        try {
            SHYPDR_Requirements_Cache::write_mu_payload();
        } catch (\Throwable $e) {
            // Never let payload-write failure break activation.
        }
    }

    // Store current version for upgrade detection
    update_option('shypdr_version', SHYPDR_VERSION);

    // Schedule the heavy scan to run in the background ~30 seconds
    // after activation. WPE Alternate Cron (or any cron runner) will
    // fire it independently of the activation HTTP request — visitors
    // never pay the cost.
    if (!wp_next_scheduled('shypdr_deferred_initial_scan')) {
        wp_schedule_single_event(time() + 30, 'shypdr_deferred_initial_scan');
    }
}

/**
 * Deferred initial scan. Runs out-of-band via WP-Cron so the heavy
 * file I/O and regex work doesn't block the activation HTTP request.
 *
 * Safe to re-run; each rebuilder is idempotent.
 */
function shypdr_run_deferred_initial_scan() {
    // Raise the time budget for this background task. Default PHP-FPM
    // timeout (30-60s) is too short for a 150+ plugin sweep.
    if (function_exists('wp_raise_memory_limit')) {
        wp_raise_memory_limit('admin');
    }
    if (function_exists('set_time_limit')) {
        @set_time_limit(300);
    }

    try {
        if (class_exists('SHYPDR_Dependency_Detector')) {
            SHYPDR_Dependency_Detector::rebuild_dependency_map();
        }
    } catch (\Throwable $e) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[shypdr] dependency map rebuild failed: ' . $e->getMessage());
        }
    }

    try {
        if (class_exists('SHYPDR_Plugin_Scanner')) {
            SHYPDR_Plugin_Scanner::rebuild_restrictable_data();
        }
    } catch (\Throwable $e) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[shypdr] restrictable data rebuild failed: ' . $e->getMessage());
        }
    }

    try {
        if (class_exists('SHYPDR_Requirements_Cache')) {
            SHYPDR_Requirements_Cache::write_mu_payload();
        }
    } catch (\Throwable $e) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[shypdr] payload write failed: ' . $e->getMessage());
        }
    }

    // Clear the needs-setup flag now that the heavy work is done.
    delete_option('shypdr_needs_setup');
}
add_action('shypdr_deferred_initial_scan', 'shypdr_run_deferred_initial_scan');

/**
 * Check for plugin version upgrade and run migrations
 */
function shypdr_check_version_upgrade() {
    $stored_version = get_option('shypdr_version', '0');

    if (version_compare($stored_version, SHYPDR_VERSION, '<')) {
        // Version upgrade detected - rebuild dependency map
        // This ensures WP 6.5+ Requires Plugins header data is picked up
        if (class_exists('SHYPDR_Dependency_Detector')) {
            SHYPDR_Dependency_Detector::rebuild_dependency_map();
        }

        // Rebuild restrictable set and restriction rules
        if (class_exists('SHYPDR_Plugin_Scanner')) {
            SHYPDR_Plugin_Scanner::rebuild_restrictable_data();
        }

        // Update MU-loader to latest version
        shypdr_install_mu_loader();

        // Rebuild the combined payload so upgrades from 6.1.x pick up
        // the single-option read path on the next frontend request.
        if (class_exists('SHYPDR_Requirements_Cache')) {
            SHYPDR_Requirements_Cache::write_mu_payload();
        }

        // Store new version
        update_option('shypdr_version', SHYPDR_VERSION);
    }
}
add_action('admin_init', 'shypdr_check_version_upgrade');

/**
 * Install MU-plugin loader automatically
 *
 * @return bool|WP_Error Success or error
 */
function shypdr_install_mu_loader() {
    $mu_plugins_dir = WPMU_PLUGIN_DIR;
    $source_file = SHYPDR_DIR . 'mu-loader/shypdr-mu-loader.php';
    $dest_file = $mu_plugins_dir . '/shypdr-mu-loader.php';

    // Check if source file exists
    if ( ! file_exists( $source_file ) ) {
        return new WP_Error( 'source_missing', __( 'MU-loader source file not found', 'samybaxy-hyperdrive' ) );
    }

    // Create mu-plugins directory if it doesn't exist
    if ( ! file_exists( $mu_plugins_dir ) ) {
        if ( ! wp_mkdir_p( $mu_plugins_dir ) ) {
            return new WP_Error( 'mkdir_failed', __( 'Could not create mu-plugins directory', 'samybaxy-hyperdrive' ) );
        }
    }

    // Check if we can write to mu-plugins directory
    // Use WordPress filesystem check instead of is_writable()
    if ( ! wp_is_writable( $mu_plugins_dir ) ) {
        return new WP_Error( 'not_writable', __( 'mu-plugins directory is not writable', 'samybaxy-hyperdrive' ) );
    }

    // Copy MU-loader file
    if ( ! copy( $source_file, $dest_file ) ) {
        return new WP_Error( 'copy_failed', __( 'Could not copy MU-loader file', 'samybaxy-hyperdrive' ) );
    }

    return true;
}

/**
 * Uninstall MU-plugin loader
 *
 * @return bool Success
 */
function shypdr_uninstall_mu_loader() {
    $dest_file = WPMU_PLUGIN_DIR . '/shypdr-mu-loader.php';

    if (file_exists($dest_file)) {
        return wp_delete_file($dest_file);
    }

    return true;
}

/**
 * Check if MU-loader is installed and active
 *
 * @return bool
 */
function shypdr_is_mu_loader_active() {
    // Check if constant is defined (MU-loader is running)
    if (defined('SHYPDR_MU_LOADER_ACTIVE') && SHYPDR_MU_LOADER_ACTIVE === true) {
        return true;
    }

    // Also check if file exists (for immediate feedback after installation)
    $mu_loader_file = WPMU_PLUGIN_DIR . '/shypdr-mu-loader.php';
    return file_exists($mu_loader_file);
}

/**
 * Get MU-loader filter data
 *
 * @return array|null Filter data or null if not available
 */
function shypdr_get_mu_filter_data() {
    if (!shypdr_is_mu_loader_active()) {
        return null;
    }

    return $GLOBALS['shypdr_mu_filter_data'] ?? null;
}

// Deactivation hook - Cleanup
register_deactivation_hook(__FILE__, 'shypdr_deactivation_handler');

function shypdr_deactivation_handler() {
    // Unschedule any pending deferred initial scan so it doesn't fire
    // against a deactivated plugin.
    $next = wp_next_scheduled('shypdr_deferred_initial_scan');
    if ($next) {
        wp_unschedule_event($next, 'shypdr_deferred_initial_scan');
    }

    // Clear all caches on deactivation
    if (class_exists('SHYPDR_Detection_Cache')) {
        SHYPDR_Detection_Cache::clear_all_caches();
    }

    // Clear transients
    delete_transient('shypdr_logs');
    delete_transient('shypdr_activation_notice');

    // Tear down the MU-loader so a deactivated Hyperdrive never touches
    // a request. WordPress always loads MU-plugins regardless of plugin
    // activation state, so we have to physically remove the file. It is
    // re-installed automatically by shypdr_activation_handler() when the
    // plugin is reactivated.
    shypdr_uninstall_mu_loader();

    // Belt-and-braces: drop the MU-payload too. If the file deletion
    // above failed for any reason (read-only mu-plugins dir, etc.) the
    // MU-loader's safety check at the top of shypdr-mu-loader.php will
    // see the missing payload and the missing main-plugin entry in
    // active_plugins and bail without filtering anything.
    delete_option('shypdr_mu_payload');

    // Note: user preferences (shypdr_enabled, shypdr_essential_plugins,
    // etc.) are intentionally preserved so a reactivation restores the
    // previous setup. They are only removed on uninstall.
}

// Uninstall hook
register_uninstall_hook(__FILE__, 'shypdr_uninstall_handler');

function shypdr_uninstall_handler() {
    // Remove MU-loader
    shypdr_uninstall_mu_loader();

    // Clean up all options
    delete_option('shypdr_enabled');
    delete_option('shypdr_debug_enabled');
    delete_option('shypdr_runtime_logging');
    delete_option('shypdr_frontend_optimizations');
    delete_option('shypdr_essential_plugins');
    delete_option('shypdr_plugin_analysis');
    delete_option('shypdr_scan_completed');
    delete_option('shypdr_needs_setup');
    delete_option('shypdr_restrictable_plugins');
    delete_option('shypdr_restriction_rules');
    delete_option('shypdr_manual_restrictable');
    delete_option('shypdr_manual_unrestricted');
    delete_option('shypdr_version');
    delete_option('shypdr_url_requirements');
    delete_option('shypdr_dependency_map');
    delete_option('shypdr_circular_dependencies');
    delete_option('shypdr_mu_payload');

    // Remove runtime log directory
    $upload_dir = wp_upload_dir();
    if (empty($upload_dir['error'])) {
        $log_dir = trailingslashit($upload_dir['basedir']) . 'shypdr-logs';
        foreach (['runtime.log', 'runtime.log.1', '.htaccess', 'index.html'] as $f) {
            $path = $log_dir . '/' . $f;
            if (file_exists($path)) {
                @unlink($path);
            }
        }
        if (is_dir($log_dir)) {
            @rmdir($log_dir);
        }
    }

    // Clean up transients
    delete_transient('shypdr_logs');

    // Clean up caches (check if class exists during uninstall)
    if (class_exists('SHYPDR_Detection_Cache')) {
        SHYPDR_Detection_Cache::clear_all_caches();
    }
}

// First-time setup on admin load (runs once after activation)
add_action('admin_init', 'shypdr_first_time_setup');

function shypdr_first_time_setup() {
    // Only run if setup is needed
    if (!get_option('shypdr_needs_setup')) {
        return;
    }

    // Only run for users who can manage options
    if (!current_user_can('manage_options')) {
        return;
    }

    // CRITICAL: do NOT run heavy plugin scanning here. On a large site
    // (150+ plugins) scan_active_plugins() reads up to 500KB per plugin
    // file via file_get_contents, which can exceed PHP-FPM timeout and
    // produce 502 Bad Gateway on the post-activation admin page load.
    //
    // The heavy scan is deferred to the shypdr_deferred_initial_scan
    // wp-cron event (scheduled in shypdr_activation_handler). This hook
    // now only does cheap, safe work that's OK to run in the visitor's
    // request.

    // Ensure MU-loader is present (single file copy — fast).
    shypdr_install_mu_loader();

    // If the deferred cron hasn't fired yet, make sure it's still
    // scheduled. This is a safety net for sites where the activation
    // schedule was somehow cleared (e.g. cron table wipe).
    if (!wp_next_scheduled('shypdr_deferred_initial_scan')) {
        wp_schedule_single_event(time() + 30, 'shypdr_deferred_initial_scan');
    }

    set_transient('shypdr_activation_notice', true, 60);
}

// Admin notice for MU-loader status
add_action('admin_notices', 'shypdr_admin_notices');

function shypdr_admin_notices() {
    // Only show on our settings page or plugins page
    $screen = get_current_screen();
    if (!$screen || !in_array($screen->id, ['settings_page_shypdr-settings', 'plugins'])) {
        return;
    }

    // Check if filtering is enabled but MU-loader is not active
    $enabled = get_option('shypdr_enabled', false);

    if ( $enabled && ! shypdr_is_mu_loader_active() ) {
        ?>
        <div class="notice notice-error">
            <p><strong><?php esc_html_e( 'Samybaxy\'s Hyperdrive: MU-Loader Required!', 'samybaxy-hyperdrive' ); ?></strong></p>
            <p><?php esc_html_e( 'Plugin filtering is enabled but the MU-loader is not installed.', 'samybaxy-hyperdrive' ); ?> <strong><?php esc_html_e( 'Without the MU-loader, filtering will NOT work.', 'samybaxy-hyperdrive' ); ?></strong></p>
            <p>
                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'options-general.php?page=shypdr-settings&shypdr_install_mu=1' ), 'shypdr_install_mu' ) ); ?>"
                   class="button button-primary">
                    <?php esc_html_e( 'Install MU-Loader Now', 'samybaxy-hyperdrive' ); ?>
                </a>
            </p>
        </div>
        <?php
    }

    // Success notice after MU-loader installation
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice, no action taken
    if ( isset( $_GET['shypdr_mu_installed'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['shypdr_mu_installed'] ) ) ) {
        ?>
        <div class="notice notice-success is-dismissible">
            <p><strong><?php esc_html_e( 'MU-Loader installed successfully!', 'samybaxy-hyperdrive' ); ?></strong> <?php esc_html_e( 'Plugin filtering is now active and will work on the next page load.', 'samybaxy-hyperdrive' ); ?></p>
        </div>
        <?php
    }

    // Error notice if MU-loader installation failed
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice, no action taken
    if ( isset( $_GET['shypdr_mu_error'] ) ) {
        ?>
        <div class="notice notice-error is-dismissible">
            <p><strong><?php esc_html_e( 'MU-Loader installation failed:', 'samybaxy-hyperdrive' ); ?></strong> <?php
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only error message, no action taken
            echo esc_html( urldecode( sanitize_text_field( wp_unslash( $_GET['shypdr_mu_error'] ) ) ) ); ?></p>
            <p><?php
            printf(
                /* translators: 1: source file path, 2: destination file path */
                esc_html__( 'Please manually copy %1$s to %2$s', 'samybaxy-hyperdrive' ),
                '<code>wp-content/plugins/samybaxy-hyperdrive/mu-loader/shypdr-mu-loader.php</code>',
                '<code>wp-content/mu-plugins/shypdr-mu-loader.php</code>'
            );
            ?></p>
        </div>
        <?php
    }
}

// Add plugin meta links (shown in plugins list)
add_filter( 'plugin_row_meta', 'shypdr_plugin_row_meta', 10, 2 );

/**
 * Add custom links to plugin row meta
 *
 * @param array  $links Existing meta links.
 * @param string $file  Plugin file path.
 * @return array Modified meta links.
 */
function shypdr_plugin_row_meta( $links, $file ) {
    if ( SHYPDR_BASENAME === $file ) {
        $links[] = sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
            esc_url( 'https://github.com/samybaxy/samybaxy-hyperdrive/issues' ),
            esc_html__( 'Report Issues', 'samybaxy-hyperdrive' )
        );
    }
    return $links;
}

// Handle MU-loader installation request
add_action('admin_init', 'shypdr_handle_mu_install');

function shypdr_handle_mu_install() {
    if ( ! isset( $_GET['shypdr_install_mu'] ) || ! isset( $_GET['_wpnonce'] ) ) {
        return;
    }

    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'shypdr_install_mu' ) ) {
        wp_die( esc_html__( 'Security check failed', 'samybaxy-hyperdrive' ) );
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Access denied', 'samybaxy-hyperdrive' ) );
    }

    $result = shypdr_install_mu_loader();

    if (is_wp_error($result)) {
        wp_safe_redirect(add_query_arg([
            'page' => 'shypdr-settings',
            'shypdr_mu_error' => urlencode($result->get_error_message())
        ], admin_url('options-general.php')));
    } else {
        wp_safe_redirect(add_query_arg([
            'page' => 'shypdr-settings',
            'shypdr_mu_installed' => '1'
        ], admin_url('options-general.php')));
    }
    exit;
}
