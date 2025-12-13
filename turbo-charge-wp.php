<?php
/**
 * Plugin Name: Turbo Charge WP
 * Plugin URI: https://github.com/turbo-charge-wp/turbo-charge-wp
 * Description: Revolutionary plugin filtering - Load only essential plugins per page. Requires MU-plugin loader for actual performance gains.
 * Version: 5.1.0
 * Author: Turbo Charge WP Team
 * Author URI: https://turbo-charge-wp.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: turbo-charge-wp
 * Requires at least: 6.4
 * Requires PHP: 8.2
 *
 * @package TurboChargeWP
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Core initialization constants
define('TCWP_VERSION', '5.1.0');
define('TCWP_DIR', plugin_dir_path(__FILE__));
define('TCWP_URL', plugin_dir_url(__FILE__));
define('TCWP_BASENAME', plugin_basename(__FILE__));

// Load core components
require_once TCWP_DIR . 'includes/class-plugin-scanner.php';
require_once TCWP_DIR . 'includes/class-detection-cache.php';
require_once TCWP_DIR . 'includes/class-content-analyzer.php';
require_once TCWP_DIR . 'includes/class-requirements-cache.php';
require_once TCWP_DIR . 'includes/class-main.php';

// Initialize plugin on WordPress hooks
if (class_exists('TurboChargeWP_Main')) {
    add_action('plugins_loaded', [TurboChargeWP_Main::class, 'init'], 5);
}

// Activation hook - Run intelligent plugin scan and install MU-loader
register_activation_hook(__FILE__, 'tcwp_activation_handler');

function tcwp_activation_handler() {
    // Set default options using add_option (won't overwrite existing)
    add_option('tcwp_enabled', false);
    add_option('tcwp_debug_enabled', false);

    // Flag that we need to run first-time setup (for scanning)
    update_option('tcwp_needs_setup', true);

    // CRITICAL: Install/update MU-loader during activation
    // This ensures the MU-loader is always the latest version
    // and prevents old MU-loaders from interfering with activation
    tcwp_install_mu_loader();
}

/**
 * Install MU-plugin loader automatically
 *
 * @return bool|WP_Error Success or error
 */
function tcwp_install_mu_loader() {
    $mu_plugins_dir = WPMU_PLUGIN_DIR;
    $source_file = TCWP_DIR . 'mu-loader/tcwp-mu-loader.php';
    $dest_file = $mu_plugins_dir . '/tcwp-mu-loader.php';

    // Check if source file exists
    if (!file_exists($source_file)) {
        return new WP_Error('source_missing', 'MU-loader source file not found');
    }

    // Create mu-plugins directory if it doesn't exist
    if (!file_exists($mu_plugins_dir)) {
        if (!wp_mkdir_p($mu_plugins_dir)) {
            return new WP_Error('mkdir_failed', 'Could not create mu-plugins directory');
        }
    }

    // Check if we can write to mu-plugins directory
    // Use WordPress filesystem check instead of is_writable()
    if (!wp_is_writable($mu_plugins_dir)) {
        return new WP_Error('not_writable', 'mu-plugins directory is not writable');
    }

    // Copy MU-loader file
    if (!copy($source_file, $dest_file)) {
        return new WP_Error('copy_failed', 'Could not copy MU-loader file');
    }

    return true;
}

/**
 * Uninstall MU-plugin loader
 *
 * @return bool Success
 */
function tcwp_uninstall_mu_loader() {
    $dest_file = WPMU_PLUGIN_DIR . '/tcwp-mu-loader.php';

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
function tcwp_is_mu_loader_active() {
    // Check if constant is defined (MU-loader is running)
    if (defined('TCWP_MU_LOADER_ACTIVE') && TCWP_MU_LOADER_ACTIVE === true) {
        return true;
    }

    // Also check if file exists (for immediate feedback after installation)
    $mu_loader_file = WPMU_PLUGIN_DIR . '/tcwp-mu-loader.php';
    return file_exists($mu_loader_file);
}

/**
 * Get MU-loader filter data
 *
 * @return array|null Filter data or null if not available
 */
function tcwp_get_mu_filter_data() {
    if (!tcwp_is_mu_loader_active()) {
        return null;
    }

    return $GLOBALS['tcwp_mu_filter_data'] ?? null;
}

// Deactivation hook - Cleanup
register_deactivation_hook(__FILE__, 'tcwp_deactivation_handler');

function tcwp_deactivation_handler() {
    // Clear all caches on deactivation
    if (class_exists('TurboChargeWP_Detection_Cache')) {
        TurboChargeWP_Detection_Cache::clear_all_caches();
    }

    // Clear transients
    delete_transient('tcwp_logs');
    delete_transient('tcwp_activation_notice');

    // Note: We don't remove MU-loader on deactivation, only on uninstall
}

// Uninstall hook
register_uninstall_hook(__FILE__, 'tcwp_uninstall_handler');

function tcwp_uninstall_handler() {
    // Remove MU-loader
    tcwp_uninstall_mu_loader();

    // Clean up all options
    delete_option('tcwp_enabled');
    delete_option('tcwp_debug_enabled');
    delete_option('tcwp_essential_plugins');
    delete_option('tcwp_plugin_analysis');
    delete_option('tcwp_scan_completed');
    delete_option('tcwp_needs_setup');

    // Clean up transients
    delete_transient('tcwp_logs');

    // Clean up caches (check if class exists during uninstall)
    if (class_exists('TurboChargeWP_Detection_Cache')) {
        TurboChargeWP_Detection_Cache::clear_all_caches();
    }
}

// First-time setup on admin load (runs once after activation)
add_action('admin_init', 'tcwp_first_time_setup');

function tcwp_first_time_setup() {
    // Only run if setup is needed
    if (!get_option('tcwp_needs_setup')) {
        return;
    }

    // Only run for users who can manage options
    if (!current_user_can('manage_options')) {
        return;
    }

    // Clear the setup flag first to prevent re-runs
    delete_option('tcwp_needs_setup');

    // Now run the setup operations (these are safe to fail)
    try {
        // Clear any old caches
        if (class_exists('TurboChargeWP_Detection_Cache')) {
            TurboChargeWP_Detection_Cache::clear_all_caches();
        }

        // Run intelligent plugin scanner on first activation
        if (class_exists('TurboChargeWP_Plugin_Scanner')) {
            if (!TurboChargeWP_Plugin_Scanner::is_scan_completed()) {
                TurboChargeWP_Plugin_Scanner::get_essential_plugins(true);
                set_transient('tcwp_activation_notice', true, 60);
            }
        }

        // Attempt to install MU-loader automatically
        tcwp_install_mu_loader();
    } catch (Exception $e) {
        // Silently handle installation errors (user can manually install)
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    } catch (Error $e) {
        // Silently handle installation errors (user can manually install)
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    }
}

// Admin notice for MU-loader status
add_action('admin_notices', 'tcwp_admin_notices');

function tcwp_admin_notices() {
    // Only show on our settings page or plugins page
    $screen = get_current_screen();
    if (!$screen || !in_array($screen->id, ['settings_page_tcwp-settings', 'plugins'])) {
        return;
    }

    // Check if filtering is enabled but MU-loader is not active
    $enabled = get_option('tcwp_enabled', false);

    if ($enabled && !tcwp_is_mu_loader_active()) {
        ?>
        <div class="notice notice-error">
            <p><strong>⚠️ Turbo Charge WP: MU-Loader Required!</strong></p>
            <p>Plugin filtering is enabled but the MU-loader is not installed. <strong>Without the MU-loader, filtering will NOT work.</strong></p>
            <p>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('options-general.php?page=tcwp-settings&tcwp_install_mu=1'), 'tcwp_install_mu')); ?>"
                   class="button button-primary">
                    Install MU-Loader Now
                </a>
            </p>
        </div>
        <?php
    }

    // Success notice after MU-loader installation
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice, no action taken
    if (isset($_GET['tcwp_mu_installed']) && sanitize_text_field(wp_unslash($_GET['tcwp_mu_installed'])) === '1') {
        ?>
        <div class="notice notice-success is-dismissible">
            <p><strong>✅ MU-Loader installed successfully!</strong> Plugin filtering is now active and will work on the next page load.</p>
        </div>
        <?php
    }

    // Error notice if MU-loader installation failed
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only notice, no action taken
    if (isset($_GET['tcwp_mu_error'])) {
        ?>
        <div class="notice notice-error is-dismissible">
            <p><strong>❌ MU-Loader installation failed:</strong> <?php echo esc_html(urldecode(sanitize_text_field(wp_unslash($_GET['tcwp_mu_error'])))); ?></p>
            <p>Please manually copy <code>wp-content/plugins/turbo-charge-wp/mu-loader/tcwp-mu-loader.php</code> to <code>wp-content/mu-plugins/tcwp-mu-loader.php</code></p>
        </div>
        <?php
    }
}

// Handle MU-loader installation request
add_action('admin_init', 'tcwp_handle_mu_install');

function tcwp_handle_mu_install() {
    if (!isset($_GET['tcwp_install_mu']) || !isset($_GET['_wpnonce'])) {
        return;
    }

    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'tcwp_install_mu')) {
        wp_die('Security check failed');
    }

    if (!current_user_can('manage_options')) {
        wp_die('Access denied');
    }

    $result = tcwp_install_mu_loader();

    if (is_wp_error($result)) {
        wp_safe_redirect(add_query_arg([
            'page' => 'tcwp-settings',
            'tcwp_mu_error' => urlencode($result->get_error_message())
        ], admin_url('options-general.php')));
    } else {
        wp_safe_redirect(add_query_arg([
            'page' => 'tcwp-settings',
            'tcwp_mu_installed' => '1'
        ], admin_url('options-general.php')));
    }
    exit;
}
