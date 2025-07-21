<?php
/**
 * Uninstall script for Turbo Charge WP
 * 
 * This file is executed when the plugin is deleted.
 * It removes all plugin data from the database.
 *
 * @package TurboChargeWP
 */

// If uninstall not called from WordPress, then exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete plugin options
delete_option('tcwp_options');

// Delete any transients
delete_transient('tcwp_plugin_analysis');
delete_transient('tcwp_dependencies_cache');

// Clean up any custom database tables (if we add them in future versions)
// global $wpdb;
// $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}tcwp_logs");

// Clear any scheduled hooks (if we add cron jobs in future versions)
wp_clear_scheduled_hook('tcwp_cleanup_logs');

// Remove any custom user meta (if we add user-specific settings)
// delete_metadata('user', 0, 'tcwp_user_preferences', '', true);

// Clear object cache
wp_cache_flush();
