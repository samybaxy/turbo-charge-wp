<?php
/**
 * Plugin Name: Samybaxy's Hyperdrive
 * Plugin URI: https://github.com/samybaxy/samybaxy-hyperdrive
 * Description: Revolutionary plugin filtering - Load only essential plugins per page. Requires MU-plugin loader for actual performance gains.
 * Version: 6.1.9
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
define('SHYPDR_VERSION', '6.1.9');
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
        'shypdr_sitewide_plugins',
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

    // For small sites, run the rebuild SYNCHRONOUSLY during activation —
    // cron-based deferral is unreliable on hosts where WP-Cron is
    // traffic-dependent (most notably WP Engine Alternate Cron). The
    // sitewide-plugins scan + payload write is cheap on small sites
    // (< ~50 templates, < 200 plugins) and avoids the "I activated but
    // nothing happened" UX. Larger sites stay on the deferred path so
    // activation doesn't 502 PHP-FPM.
    global $wpdb;
    $template_count = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts}
         WHERE post_type = 'elementor_library' AND post_status = 'publish'"
    );
    $active_count = count((array) get_option('active_plugins', []));

    if ($template_count < 50 && $active_count < 100 && function_exists('shypdr_run_full_rebuild')) {
        shypdr_run_full_rebuild(true);
        delete_option('shypdr_needs_setup');
        return;
    }

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
    if (function_exists('shypdr_run_full_rebuild')) {
        shypdr_run_full_rebuild(true);
    }
    delete_option('shypdr_needs_setup');
}
add_action('shypdr_deferred_initial_scan', 'shypdr_run_deferred_initial_scan');

/**
 * Shared deferred rebuild used for:
 * - Plugin activation/deactivation (handle_plugin_change)
 * - Version upgrades (shypdr_check_version_upgrade)
 *
 * Thin wrapper around shypdr_run_full_rebuild() to keep all rebuild paths
 * (manual button, activation cron, plugin-change cron, upgrade cron) in
 * lockstep. Previously this function diverged from shypdr_run_full_rebuild
 * by omitting the URL-requirements lookup rebuild, which left the lookup
 * table empty after every plugin activation/deactivation until the next
 * save_post slowly refilled it (or the user clicked Rebuild Now).
 */
function shypdr_run_deferred_rebuild() {
    if (function_exists('shypdr_run_full_rebuild')) {
        shypdr_run_full_rebuild(true);
    }
}
add_action('shypdr_deferred_rebuild', 'shypdr_run_deferred_rebuild');

/**
 * Cron handler: rebuild the site-wide plugins set.
 *
 * Triggered on edits to elementor_library templates (header, footer, etc.).
 * Fires from a debounced wp_schedule_single_event so multiple edits within
 * a few seconds coalesce into one rebuild.
 *
 * @since 6.1.7
 */
function shypdr_run_sitewide_rebuild() {
    if (function_exists('set_time_limit')) {
        @set_time_limit(60);
    }
    try {
        if (class_exists('SHYPDR_Requirements_Cache')) {
            SHYPDR_Requirements_Cache::rebuild_sitewide_plugins();
            SHYPDR_Requirements_Cache::write_mu_payload();
            SHYPDR_Requirements_Cache::mark_rebuild_completed();
        }
    } catch (\Throwable $e) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[shypdr] sitewide rebuild failed: ' . $e->getMessage());
        }
    }
}
add_action('shypdr_rebuild_sitewide', 'shypdr_run_sitewide_rebuild');

/**
 * Synchronous rebuild of every cached data source the MU-loader needs.
 * Shared body between the cron handlers and the admin_init safety net.
 *
 * Each step is wrapped so one failure doesn't break the others. Stamps a
 * "last rebuild" timestamp on success so the safety net knows the data
 * is fresh.
 *
 * @since 6.1.8
 * @param bool $include_heavy Whether to rebuild the (slower) dependency
 *                            map and restrictable set. When false, only
 *                            the sitewide-plugins rebuild + payload write
 *                            run — cheap enough for inline admin_init.
 * @return array Result counts for each step.
 */
function shypdr_run_full_rebuild($include_heavy = true) {
    $results = [];

    if (function_exists('wp_raise_memory_limit')) {
        wp_raise_memory_limit('admin');
    }
    if (function_exists('set_time_limit')) {
        @set_time_limit(120);
    }

    if ($include_heavy) {
        try {
            if (class_exists('SHYPDR_Dependency_Detector')) {
                SHYPDR_Dependency_Detector::rebuild_dependency_map();
                $results['dependency_map'] = 'ok';
            }
        } catch (\Throwable $e) {
            $results['dependency_map'] = 'error: ' . $e->getMessage();
        }

        try {
            if (class_exists('SHYPDR_Plugin_Scanner')) {
                SHYPDR_Plugin_Scanner::rebuild_restrictable_data();
                $results['restrictable'] = 'ok';
            }
        } catch (\Throwable $e) {
            $results['restrictable'] = 'error: ' . $e->getMessage();
        }
    }

    try {
        if (class_exists('SHYPDR_Requirements_Cache')) {
            $count = SHYPDR_Requirements_Cache::rebuild_sitewide_plugins();
            $results['sitewide'] = $count;
        }
    } catch (\Throwable $e) {
        $results['sitewide'] = 'error: ' . $e->getMessage();
    }

    // Per-URL requirements lookup. Re-analyzes published posts/pages/products
    // for shortcodes, blocks, Elementor widgets, and page templates. Bounded
    // by LOOKUP_MAX_ENTRIES so the resulting option stays within the
    // autoload budget on large sites.
    try {
        if (class_exists('SHYPDR_Requirements_Cache')) {
            $results['lookup'] = SHYPDR_Requirements_Cache::rebuild_lookup_table();
        }
    } catch (\Throwable $e) {
        $results['lookup'] = 'error: ' . $e->getMessage();
    }

    try {
        if (class_exists('SHYPDR_Requirements_Cache')) {
            SHYPDR_Requirements_Cache::write_mu_payload();
            SHYPDR_Requirements_Cache::mark_rebuild_completed();
            $results['payload'] = 'ok';
        }
    } catch (\Throwable $e) {
        $results['payload'] = 'error: ' . $e->getMessage();
    }

    return $results;
}

/**
 * Admin_init safety net.
 *
 * WP-Cron on WP Engine (and other hosts) is traffic-dependent: scheduled
 * events can sit in the queue for hours without firing. When that happens,
 * users see stale or empty Hyperdrive data — most visibly, header widgets
 * (menu cart) rendering empty because the sitewide-plugins set was never
 * built.
 *
 * This safety net runs on admin_init for users who can edit plugins. It
 * only kicks in when:
 *   - Hyperdrive is enabled
 *   - SHYPDR_Requirements_Cache::is_payload_stale() returns true
 *
 * The rebuild itself is cheap (sitewide-plugins scan + payload write,
 * ~50-200ms on a 100-template site). Heavy work (dependency map,
 * restrictable scan) is excluded so we don't slow admin page loads.
 *
 * Sets a transient-backed admin notice once per stale recovery so the
 * user knows it happened.
 *
 * @since 6.1.8
 */
function shypdr_admin_init_safety_net() {
    if (!current_user_can('activate_plugins')) {
        return;
    }
    if (!get_option('shypdr_enabled', false)) {
        return;
    }
    if (!class_exists('SHYPDR_Requirements_Cache')) {
        return;
    }
    if (!SHYPDR_Requirements_Cache::is_payload_stale()) {
        return;
    }

    // Throttle: only attempt once per 5 minutes to avoid running on every
    // admin page load if a rebuild keeps failing for some reason.
    $last_attempt = (int) get_transient('shypdr_safety_net_last_attempt');
    if ($last_attempt && (time() - $last_attempt) < 300) {
        return;
    }
    set_transient('shypdr_safety_net_last_attempt', time(), 600);

    shypdr_run_full_rebuild(false);

    // Surface a one-shot admin notice so the user understands why the
    // first admin page after deploy was slightly slower than usual.
    set_transient('shypdr_safety_net_notice', 1, 60);
}
add_action('admin_init', 'shypdr_admin_init_safety_net', 99);

/**
 * Render the safety-net notice once.
 *
 * @since 6.1.8
 */
function shypdr_safety_net_notice() {
    if (!get_transient('shypdr_safety_net_notice')) {
        return;
    }
    delete_transient('shypdr_safety_net_notice');
    if (!current_user_can('activate_plugins')) {
        return;
    }
    echo '<div class="notice notice-info is-dismissible"><p><strong>Hyperdrive:</strong> ';
    esc_html_e('Detected stale data — rebuilt the site-wide plugin set inline (WP-Cron appears to have skipped the scheduled rebuild). No action needed.', 'samybaxy-hyperdrive');
    echo '</p></div>';
}
add_action('admin_notices', 'shypdr_safety_net_notice');

/**
 * Check for plugin version upgrade.
 *
 * Heavy rebuild work is deferred to the shypdr_deferred_rebuild cron event
 * so it doesn't 502 on the first admin page load after upgrade. We do the
 * minimal synchronous work here: bump the stored version and ensure the
 * MU-loader file is up to date (single file copy — fast).
 */
function shypdr_check_version_upgrade() {
    $stored_version = get_option('shypdr_version', '0');

    if (version_compare($stored_version, SHYPDR_VERSION, '<')) {
        // Cheap synchronous: update MU-loader file (single copy operation).
        shypdr_install_mu_loader();

        // Store new version BEFORE scheduling cron so reruns are idempotent.
        update_option('shypdr_version', SHYPDR_VERSION);

        // Defer the heavy rebuild — gives PHP-FPM time to return 200.
        if (!wp_next_scheduled('shypdr_deferred_rebuild')) {
            wp_schedule_single_event(time() + 15, 'shypdr_deferred_rebuild');
        }
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
    // Unschedule any pending deferred scans so they don't fire against a
    // deactivated plugin.
    wp_clear_scheduled_hook('shypdr_deferred_initial_scan');
    wp_clear_scheduled_hook('shypdr_deferred_rebuild');
    wp_clear_scheduled_hook('shypdr_rebuild_sitewide');

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
    delete_option('shypdr_sitewide_plugins');
    delete_option('shypdr_last_rebuild_at');
    delete_transient('shypdr_safety_net_last_attempt');
    delete_transient('shypdr_safety_net_notice');
    delete_transient('shypdr_last_manual_rebuild');

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
