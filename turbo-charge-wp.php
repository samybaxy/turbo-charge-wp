<?php
/**
 * Plugin Name: Turbo Charge WP
 * Plugin URI: https://github.com/turbo-charge-wp/turbo-charge-wp
 * Description: Revolutionary plugin filtering with AI-powered analysis - Load only essential plugins per page, intelligent dependency resolution, 85-90% reduction.
 * Version: 5.0.0
 * Author: Turbo Charge WP Team
 * Author URI: https://turbo-charge-wp.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: turbo-charge-wp
 * Domain Path: /languages
 * Requires at least: 6.4
 * Requires PHP: 8.2
 * Network: false
 *
 * @package TurboChargeWP
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Core initialization constants
define('TCWP_VERSION', '5.0.0');
define('TCWP_DIR', plugin_dir_path(__FILE__));
define('TCWP_URL', plugin_dir_url(__FILE__));
define('TCWP_BASENAME', plugin_basename(__FILE__));

// Load core components
require_once TCWP_DIR . 'includes/class-plugin-scanner.php';
require_once TCWP_DIR . 'includes/class-detection-cache.php';
require_once TCWP_DIR . 'includes/class-main.php';

// Initialize plugin on WordPress hooks
if (class_exists('TurboChargeWP_Main')) {
    add_action('plugins_loaded', [TurboChargeWP_Main::class, 'init'], 5);
}

// Activation hook - Run intelligent plugin scan
register_activation_hook(__FILE__, 'tcwp_activation_handler');

function tcwp_activation_handler() {
    // Run intelligent plugin scanner on first activation
    if (!TurboChargeWP_Plugin_Scanner::is_scan_completed()) {
        // Force scan and get essential plugins
        TurboChargeWP_Plugin_Scanner::get_essential_plugins(true);

        // Store activation notice
        set_transient('tcwp_activation_notice', true, 60);
    }

    // Clear any old caches
    TurboChargeWP_Detection_Cache::clear_all_caches();

    // Set default options if not exists
    if (get_option('tcwp_enabled') === false) {
        update_option('tcwp_enabled', false); // Disabled by default for safety
    }

    if (get_option('tcwp_debug_enabled') === false) {
        update_option('tcwp_debug_enabled', false);
    }
}

// Deactivation hook - Cleanup
register_deactivation_hook(__FILE__, 'tcwp_deactivation_handler');

function tcwp_deactivation_handler() {
    // Clear all caches on deactivation
    TurboChargeWP_Detection_Cache::clear_all_caches();

    // Clear transients
    delete_transient('tcwp_logs');
    delete_transient('tcwp_activation_notice');
}
