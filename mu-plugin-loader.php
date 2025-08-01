<?php
/**
 * Must-Use Plugin Loader for Turbo Charge WP
 * 
 * Place this file in wp-content/mu-plugins/ to ensure Turbo Charge WP loads
 * before all other plugins for maximum effectiveness.
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Early initialization to catch plugin loading
add_action('muplugins_loaded', 'tcwp_mu_early_init', 1);

function tcwp_mu_early_init() {
    // Only run on frontend or during specific admin operations
    if (is_admin() && !defined('DOING_AJAX') && !defined('DOING_CRON')) {
        // Allow admin operations but check for specific pages
        $allowed_admin_pages = array('tcwp-performance-test', 'tcwp-page-tester', 'tcwp-manual-config');
        $current_page = $_GET['page'] ?? '';
        
        if (!in_array($current_page, $allowed_admin_pages)) {
            return;
        }
    }
    
    // Load our plugin early if it exists
    $tcwp_plugin_file = WP_PLUGIN_DIR . '/turbo-charge-wp/turbo-charge-wp.php';
    if (file_exists($tcwp_plugin_file)) {
        include_once $tcwp_plugin_file;
    }
}

// Alternative: Direct plugin option filtering (ultra-early)
add_filter('pre_option_active_plugins', 'tcwp_mu_filter_plugins', 1);

function tcwp_mu_filter_plugins($value) {
    // Skip filtering for all backend operations
    if (is_admin() || 
        (defined('DOING_AJAX') && DOING_AJAX) || 
        (defined('DOING_CRON') && DOING_CRON) ||
        (defined('REST_REQUEST') && REST_REQUEST) ||
        (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)) {
        return $value;
    }
    
    // Check for backend-related URLs
    $request_uri = $_SERVER['REQUEST_URI'] ?? '';
    $backend_patterns = array('/wp-json/', '/feed/', '/wp-cron.php', 'admin-ajax.php');
    foreach ($backend_patterns as $pattern) {
        if (strpos($request_uri, $pattern) !== false) {
            return $value;
        }
    }
    
    // Check if this is a feed request
    if (function_exists('is_feed') && is_feed()) {
        return $value;
    }
    
    // If value is already set, don't override
    if ($value !== false) {
        return $value;
    }
    
    // Get the TCWP options
    $tcwp_options = get_option('tcwp_options', array('enabled' => true));
    
    // Get active plugins directly from database first
    global $wpdb;
    $active_plugins = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'active_plugins'");
    $active_plugins = maybe_unserialize($active_plugins);
    
    if (!is_array($active_plugins)) {
        return $value;
    }
    
    // Check for manual override mode
    if (!empty($tcwp_options['manual_override'])) {
        // In manual override mode, use manual configuration
        $manual_config = get_option('tcwp_manual_config', array());
        $current_url = $_SERVER['REQUEST_URI'] ?? '/';
        
        // Find matching configuration using enhanced logic
        $required_plugins = array();
        
        // Check if the manual config class is available for advanced URL matching
        if (class_exists('TCWP_Manual_Config')) {
            $required_plugins = TCWP_Manual_Config::get_manual_plugins_for_url($current_url);
        } else {
            // Fallback to simple pattern matching
            $parsed_url = parse_url($current_url);
            $path = $parsed_url['path'] ?? '/';
            
            if (isset($manual_config[$path])) {
                $required_plugins = $manual_config[$path];
            } else {
                // Try pattern matching
                foreach ($manual_config as $pattern => $plugins) {
                    if (strpos($current_url, $pattern) !== false) {
                        $required_plugins = array_merge($required_plugins, $plugins);
                    }
                }
            }
        }
        
        // Always include essential security plugins
        $essential_security = array(
            'turbo-charge-wp/turbo-charge-wp.php',
            'wordfence/wordfence.php',
            'better-wp-security/better-wp-security.php',
            'ithemes-security-pro/ithemes-security-pro.php',
            'akismet/akismet.php',
        );
        
        $required_plugins = array_unique(array_merge($required_plugins, $essential_security));
        $filtered_plugins = array_intersect($required_plugins, $active_plugins);
        
        if (WP_DEBUG) {
        }
        
        return $filtered_plugins;
    }
    
    if (empty($tcwp_options['enabled'])) {
        return $value;
    }
    
    // Apply basic filtering based on URL
    $current_url = $_SERVER['REQUEST_URI'] ?? '/';
    $essential_plugins = array(
        'turbo-charge-wp/turbo-charge-wp.php',
        'wp-rocket/wp-rocket.php',
        'litespeed-cache/litespeed-cache.php',
        'w3-total-cache/w3-total-cache.php',
        'wp-super-cache/wp-cache.php',
        'wordfence/wordfence.php',
        'better-wp-security/better-wp-security.php',
        'ithemes-security-pro/ithemes-security-pro.php',
        'akismet/akismet.php',
        'yoast-seo/wp-seo.php',
        'elementor/elementor.php',
        'elementor-pro/elementor-pro.php',
    );
    
    // Start with essential plugins
    $filtered_plugins = array_intersect($essential_plugins, $active_plugins);
    
    // Add page-specific plugins
    if (strpos($current_url, 'shop') !== false || strpos($current_url, 'cart') !== false || strpos($current_url, 'checkout') !== false) {
        $woocommerce_plugins = array(
            'woocommerce/woocommerce.php',
            'woocommerce-memberships/woocommerce-memberships.php',
            'woocommerce-subscriptions/woocommerce-subscriptions.php',
            'woocommerce-product-bundles/woocommerce-product-bundles.php',
            'jet-woo-builder/jet-woo-builder.php',
        );
        $filtered_plugins = array_merge($filtered_plugins, array_intersect($woocommerce_plugins, $active_plugins));
    }
    
    if (strpos($current_url, 'course') !== false || strpos($current_url, 'lesson') !== false) {
        $lms_plugins = array(
            'tutor/tutor.php',
            'tutor-lms-elementor-addons/tutor-lms-elementor-addons.php',
            'gamipress/gamipress.php',
        );
        $filtered_plugins = array_merge($filtered_plugins, array_intersect($lms_plugins, $active_plugins));
    }
    
    // Remove duplicates and ensure all plugins exist
    $filtered_plugins = array_unique($filtered_plugins);
    $filtered_plugins = array_intersect($filtered_plugins, $active_plugins);
    
    // Log for debugging
    if (WP_DEBUG) {
    }
    
    return $filtered_plugins;
}
