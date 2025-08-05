<?php
/**
 * Plugin Name: Turbo Charge WP
 * Plugin URI: https://github.com/turbo-charge-wp/turbo-charge-wp
 * Description: Ultra-performance WordPress optimization - dramatically reduces Time To First Byte (TTFB) by intelligently loading only required plugins per page. Zero-overhead design optimized for maximum speed.
 * Version: 2.3.8
 * Author: Turbo Charge WP Team
 * Author URI: https://turbo-charge-wp.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: turbo-charge-wp
 * Domain Path: /languages
 * Requires at least: 6.4
 * Tested up to: 6.7
 * Requires PHP: 8.2
 * Network: false
 *
 * @package TurboChargeWP
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('TCWP_VERSION', '2.3.8');
define('TCWP_PLUGIN_FILE', __FILE__);
define('TCWP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TCWP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TCWP_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Ultra-lightweight performance measurement (minimal overhead)
if (!defined('TCWP_PERFORMANCE_MODE')) {
    define('TCWP_PERFORMANCE_MODE', true);
    define('TCWP_START_TIME', microtime(true));
}

/**
 * Ultra-Lightweight TurboChargeWP Engine
 * 
 * Complete redesign focused on maximum TTFB improvement with zero overhead.
 * Uses aggressive early plugin filtering and intelligent defaults.
 * 
 * IMPORTANT: Plugin filtering is ONLY applied on true frontend requests.
 * All backend operations (admin, AJAX, REST API, feeds, cron) are excluded
 * to ensure compatibility with plugins like CartFlows that need full access.
 */
class TurboChargeWP {
    
    /**
     * Single instance
     */
    private static $instance = null;
    
    /**
     * Static options cache (avoid DB calls)
     */
    private static $options = null;
    
    /**
     * Essential plugins that must never be filtered
     */
    private static $essential_plugins = array(
        'turbo-charge-wp/turbo-charge-wp.php', // Never filter ourselves
        // Security plugins - critical for site protection
        'wordfence/wordfence.php',
        'better-wp-security/better-wp-security.php',
        'ithemes-security-pro/ithemes-security-pro.php',
        // Caching plugins - performance infrastructure
        'wp-rocket/wp-rocket.php',
        'w3-total-cache/w3-total-cache.php',
        'litespeed-cache/litespeed-cache.php',
        'wp-super-cache/wp-cache.php',
        // Essential WordPress functionality
        'akismet/akismet.php',
        // Form plugins - often used site-wide
        'fluentform/fluentform.php',
        'fluent-forms/fluent-forms.php',
        'wp-fluent-forms/wp-fluent-forms.php',
    );
    
    /**
     * Smart URL-based plugin patterns (ultra-fast string matching)
     */
    private static $smart_patterns = array(
        // WooCommerce
        'shop' => 'woocommerce/woocommerce.php',
        'cart' => 'woocommerce/woocommerce.php',
        'checkout' => 'woocommerce/woocommerce.php',
        'my-account' => 'woocommerce/woocommerce.php',
        'product' => 'woocommerce/woocommerce.php',
        'store' => 'woocommerce/woocommerce.php',
        // Contact forms
        'contact' => 'contact-form-7/wp-contact-form-7.php',
        // Learning Management
        'course' => 'tutor/tutor.php',
        'lesson' => 'tutor/tutor.php',
        'quiz' => 'tutor/tutor.php',
        // Events - Multiple possible plugin paths
        'event' => array('modern-events-calendar/modern-events-calendar.php', 'mec-events-calendar/mec-events-calendar.php'),
        'calendar' => array('modern-events-calendar/modern-events-calendar.php', 'mec-events-calendar/mec-events-calendar.php'),
        'events' => array('modern-events-calendar/modern-events-calendar.php', 'mec-events-calendar/mec-events-calendar.php'),
        // Music/Audio
        'music' => 'mp3-music-player-by-sonaar/sonaar-music.php',
        'audio' => 'mp3-music-player-by-sonaar/sonaar-music.php',
        'player' => 'mp3-music-player-by-sonaar/sonaar-music.php',
        // Blog
        'blog' => 'jet-blog/jet-blog.php',
        'post' => 'jet-blog/jet-blog.php',
    );
    
    /**
     * Page-specific plugin requirements based on URL patterns
     */
    private static $page_specific_plugins = array(
        'shop' => array(
            'woocommerce/woocommerce.php',
            'woocommerce-memberships/woocommerce-memberships.php',
            'woocommerce-subscriptions/woocommerce-subscriptions.php',
            'woocommerce-product-bundles/woocommerce-product-bundles.php',
            'woocommerce-all-products-for-subscriptions/woocommerce-all-products-for-subscriptions.php',
            'jet-woo-builder/jet-woo-builder.php',
            'jet-compare-wishlist/jet-compare-wishlist.php',
            'add-featured-videos-in-product-gallery-for-woocommerce/add-featured-videos-in-product-gallery-for-woocommerce.php',
        ),
        'course' => array(
            'tutor/tutor.php',
            'tutor-lms-elementor-addons/tutor-lms-elementor-addons.php',
            'gamipress/gamipress.php',
        ),
        'event' => array(
            'modern-events-calendar/modern-events-calendar.php',
            'mec-single-builder/mec-single-builder.php',
            'mec-virtual-events/mec-virtual-events.php',
        ),
    );
    
    /**
     * Performance metrics
     */
    private static $performance_data = array(
        'plugins_before' => 0,
        'plugins_after' => 0,
        'filter_time' => 0,
    );
    
    /**
     * Debug information for frontend display
     */
    private static $debug_info = null;
    
    /**
     * Get single instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor - Ultra-lightweight initialization
     */
    private function __construct() {
        
        // Load options ONCE at startup with defaults
        self::$options = get_option('tcwp_options', array(
            'enabled' => true,
            'filter_admin' => false,
            'ultra_mode' => true,
            'smart_defaults' => true,
            'debug_mode' => false,
            'manual_override' => false,
        ));
        
        
        // Always register admin interface so users can access settings
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
        }
        
        // Load manual configuration system (needed for both admin and frontend when manual override is enabled)
        if (file_exists(TCWP_PLUGIN_DIR . 'manual-config.php')) {
            require_once TCWP_PLUGIN_DIR . 'manual-config.php';
        }
        
        // AJAX handlers must be registered for both admin and AJAX contexts
        if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
            add_action('wp_ajax_tcwp_autosave_config', array($this, 'handle_autosave_config'));
        }
        
        // Add frontend debug display only if debug mode is enabled (before early return)
        if (!empty(self::$options['debug_mode'])) {
            add_action('wp_footer', array($this, 'display_debug_info'));
            add_action('wp_head', array($this, 'init_debug_mode'));
        }
        
        // Early return if disabled - but after admin and debug setup
        // Exception: Don't return early if manual override is enabled
        if (empty(self::$options['enabled']) && empty(self::$options['manual_override'])) {
            return;
        }
        
        // Initialize filtering
        $this->init_ultra_filtering();
        
        // Activation/deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    /**
     * Initialize ultra-fast plugin filtering
     */
    private function init_ultra_filtering() {
        // CRITICAL: Hook as early as possible to intercept plugin loading
        add_filter('option_active_plugins', array($this, 'filter_active_plugins'), 1);
        add_filter('pre_option_active_plugins', array($this, 'pre_ultra_filter_plugins'), 1);
        
        // Also hook into the site option for multisite
        add_filter('option_active_sitewide_plugins', array($this, 'filter_active_sitewide_plugins'), 1);
        
        // Add early WordPress hook to clear any cached options
        add_action('plugins_loaded', array($this, 'clear_plugin_cache'), -1000);
        
        // Only track performance in admin for monitoring (defer user capability check)
        if (is_admin()) {
            add_action('wp_loaded', array($this, 'track_performance'));
        }
    }
    
    /**
     * Clear plugin cache early
     */
    public function clear_plugin_cache() {
        // Clear option cache to ensure our filters work
        wp_cache_delete('active_plugins', 'options');
        wp_cache_delete('active_sitewide_plugins', 'site-options');
    }
    
    /**
     * Filter active plugins option directly
     */
    public function filter_active_plugins($plugins) {
        // CRITICAL: Ensure we have a valid plugin array
        if (!is_array($plugins) || empty($plugins)) {
            return $plugins;
        }
        
        // Only filter on true frontend requests
        if ($this->should_skip_filtering()) {
            return $plugins;
        }
        
        $filtered = $this->ultra_filter_plugins($plugins);
        
        // CRITICAL: Never return empty array to prevent plugin deactivation
        if (empty($filtered) || !is_array($filtered)) {
            return $plugins;
        }
        
        return $filtered;
    }
    
    /**
     * Filter active sitewide plugins for multisite
     */
    public function filter_active_sitewide_plugins($plugins) {
        // Only filter on true frontend requests
        if ($this->should_skip_filtering()) {
            return $plugins;
        }
        
        if (!is_array($plugins)) {
            return $plugins;
        }
        
        // Convert sitewide plugins format to regular format for filtering
        $regular_plugins = array_keys($plugins);
        $filtered_plugins = $this->ultra_filter_plugins($regular_plugins);
        
        // Convert back to sitewide format
        $filtered_sitewide = array();
        foreach ($filtered_plugins as $plugin) {
            if (isset($plugins[$plugin])) {
                $filtered_sitewide[$plugin] = $plugins[$plugin];
            }
        }
        
        return $filtered_sitewide;
    }
    
    /**
     * Pre-option filter for plugin loading (frontend-only optimization)
     */
    public function pre_ultra_filter_plugins($value) {
        static $recursion_guard = false;
        
        // Debug logging
        
        // Prevent infinite recursion
        if ($recursion_guard) {
            return false;
        }
        
        // If value is already set, don't override
        if ($value !== false) {
            return $value;
        }
        
        // CRITICAL: Skip filtering for all backend operations
        if ($this->should_skip_filtering()) {
            return false; // Let WordPress load all plugins
        }
        
        $recursion_guard = true;
        
        try {
            // Get the actual active plugins from the database directly (bypass filters)
            global $wpdb;
            $active_plugins = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'active_plugins'");
            $active_plugins = maybe_unserialize($active_plugins);
            
            if (!is_array($active_plugins)) {
                $active_plugins = array();
            }
            
            // Apply our filtering logic (frontend-only)
            $filtered_plugins = $this->ultra_filter_plugins($active_plugins);
            
            // CRITICAL: Never return empty array to prevent plugin deactivation
            if (empty($filtered_plugins) || !is_array($filtered_plugins)) {
                return false; // Let WordPress handle it normally
            }
            
            return $filtered_plugins;
            
        } catch (Exception $e) {
            return false;
        } finally {
            $recursion_guard = false;
        }
    }
    
    /**
     * Ultra-fast plugin filtering with zero-overhead design (frontend-only)
     */
    public function ultra_filter_plugins($plugins) {
        static $filtered_cache = null;
        static $filtering_active = false;
        
        // Check if manual override is enabled
        if (!empty(self::$options['manual_override'])) {
            // In manual override mode, only use manual configuration
            $manual_plugins = $this->get_manual_plugins($plugins);
            if (!empty($manual_plugins)) {
                // Always include essential security plugins even in manual mode (but avoid duplicates)
                $essential_security = array(
                    'turbo-charge-wp/turbo-charge-wp.php',
                    'wordfence/wordfence.php',
                    'better-wp-security/better-wp-security.php',
                    'ithemes-security-pro/ithemes-security-pro.php',
                    'akismet/akismet.php',
                );
                $security_plugins = array_intersect($essential_security, $plugins);
                
                // Always include sitewide plugins
                $sitewide_plugins = get_option('tcwp_sitewide_plugins', array());
                $sitewide_plugins = array_intersect($sitewide_plugins, $plugins);
                
                $filtered_plugins = array_unique(array_merge($manual_plugins, $security_plugins, $sitewide_plugins));
                
                // CRITICAL: Never return empty plugin list
                if (empty($filtered_plugins)) {
                    return $plugins;
                }
                
                // Store debug info for manual override mode
                if (WP_DEBUG || !empty(self::$options['debug_mode'])) {
                    $filtered_out = array_diff($plugins, $filtered_plugins);
                    self::$debug_info = array(
                        'filtered_plugins' => $filtered_plugins,
                        'filtered_out' => $filtered_out,
                        'url' => $_SERVER['REQUEST_URI'],
                        'total_before' => count($plugins),
                        'total_after' => count($filtered_plugins),
                        'manual_override' => true
                    );
                }
                
                return $filtered_plugins;
            } else {
                // No manual configuration found, return essential plugins plus sitewide plugins
                $essential_plugins = array_intersect(self::$essential_plugins, $plugins);
                
                // Always include sitewide plugins
                $sitewide_plugins = get_option('tcwp_sitewide_plugins', array());
                $sitewide_plugins = array_intersect($sitewide_plugins, $plugins);
                
                $essential_plugins = array_unique(array_merge($essential_plugins, $sitewide_plugins));
                
                if (WP_DEBUG || !empty(self::$options['debug_mode'])) {
                    $filtered_out = array_diff($plugins, $essential_plugins);
                    self::$debug_info = array(
                        'filtered_plugins' => $essential_plugins,
                        'filtered_out' => $filtered_out,
                        'url' => $_SERVER['REQUEST_URI'],
                        'total_before' => count($plugins),
                        'total_after' => count($essential_plugins),
                        'manual_override' => true,
                        'no_manual_config' => true
                    );
                }
                
                return $essential_plugins;
            }
        }
        
        // Ultra-fast early return if disabled (unless manual override is active)
        if (empty(self::$options['enabled']) && empty(self::$options['manual_override'])) {
            return $plugins;
        }
        
        // Check if we're in a performance test (simulating frontend)
        $is_performance_test = is_admin() && !empty($_GET['page']) && 
            ($_GET['page'] === 'tcwp-performance-test' || $_GET['page'] === 'tcwp-page-tester');
        
        // CRITICAL: Also check if we're in a frontend simulation context (testing)
        $is_frontend_simulation = apply_filters('tcwp_is_frontend_simulation', false);
        
        // For backend contexts (except performance tests and simulations), don't filter
        if ($this->should_skip_filtering() && !$is_performance_test && !$is_frontend_simulation) {
            return $plugins;
        }
        
        // Return cached result for multiple calls in same request (except during testing)
        if ($filtered_cache !== null && !$is_performance_test) {
            return $filtered_cache;
        }
        
        // Prevent infinite loops
        if ($filtering_active) {
            return $plugins;
        }
        
        $filtering_active = true;
        $start_time = microtime(true);
        
        try {
            self::$performance_data['plugins_before'] = count($plugins);
            
            // Start with essential plugins that must always be loaded
            $required_plugins = array_intersect(self::$essential_plugins, $plugins);
            
            // Always include sitewide plugins (highest priority after essential)
            $sitewide_plugins = get_option('tcwp_sitewide_plugins', array());
            $sitewide_plugins = array_intersect($sitewide_plugins, $plugins);
            $required_plugins = array_unique(array_merge($required_plugins, $sitewide_plugins));
            
            // Check for manual configuration first (highest priority)
            $manual_plugins = $this->get_manual_plugins($plugins);
            if (!empty($manual_plugins)) {
                $required_plugins = array_unique(array_merge($required_plugins, $manual_plugins));
                
                // CRITICAL FIX: When manual plugins are configured, ensure they take priority
                // This prevents automatic filtering from removing manually configured plugins
                if (WP_DEBUG || !empty(self::$options['debug_mode'])) {
                    error_log('TCWP: Manual plugins configured for URL, adding ' . count($manual_plugins) . ' plugins');
                }
            } else {
                // Fall back to automatic detection
                
                // Add URL-based pattern matches (ultra-fast)
                $url_plugins = $this->get_url_pattern_plugins($plugins);
                $required_plugins = array_unique(array_merge($required_plugins, $url_plugins));
                
                // Add smart content detection (minimal overhead)
                if (!empty(self::$options['smart_defaults'])) {
                    $content_plugins = $this->get_smart_content_plugins($plugins);
                    $required_plugins = array_unique(array_merge($required_plugins, $content_plugins));
                }
            }
            
            // Apply ultra-mode filtering (aggressive optimization)
            if (!empty(self::$options['ultra_mode'])) {
                $required_plugins = $this->apply_ultra_mode_filtering($required_plugins, $plugins);
            }
            
            // Ensure all required plugins exist in active list
            $filtered_plugins = array_intersect($required_plugins, $plugins);
            
            // CRITICAL FIX: Never return empty plugin list to prevent WordPress from deactivating plugins
            if (empty($filtered_plugins)) {
                // If no plugins match, return at least our own plugin and essential security plugins
                $critical_plugins = array(
                    'turbo-charge-wp/turbo-charge-wp.php',
                    'wordfence/wordfence.php',
                    'better-wp-security/better-wp-security.php',
                    'akismet/akismet.php'
                );
                $filtered_plugins = array_intersect($critical_plugins, $plugins);
                
                // If still empty, return original plugin list to prevent deactivation
                if (empty($filtered_plugins)) {
                    return $plugins;
                }
            }
            
            // Additional safety: ensure minimum plugin count
            if (count($filtered_plugins) < 3) {
                $basic_plugins = array(
                    'woocommerce/woocommerce.php',
                    'elementor/elementor.php',
                    'elementor-pro/elementor-pro.php',
                    'yoast-seo/wp-seo.php',
                    'contact-form-7/wp-contact-form-7.php'
                );
                
                foreach ($basic_plugins as $basic_plugin) {
                    if (in_array($basic_plugin, $plugins) && !in_array($basic_plugin, $filtered_plugins)) {
                        $filtered_plugins[] = $basic_plugin;
                        if (count($filtered_plugins) >= 5) {
                            break;
                        }
                    }
                }
            }
            
            // Cache result (but not during performance testing to allow multiple tests)
            if (!$is_performance_test) {
                $filtered_cache = $filtered_plugins;
            }
            self::$performance_data['plugins_after'] = count($filtered_plugins);
            self::$performance_data['filter_time'] = (microtime(true) - $start_time) * 1000;
            
            // Log what we're filtering out for debugging
            if (WP_DEBUG || !empty(self::$options['debug_mode'])) {
                $filtered_out = array_diff($plugins, $filtered_plugins);
                
                // Store debug info for frontend display
                if (!empty(self::$options['debug_mode'])) {
                    self::$debug_info = array(
                        'filtered_plugins' => $filtered_plugins,
                        'filtered_out' => $filtered_out,
                        'url' => $_SERVER['REQUEST_URI'],
                        'total_before' => count($plugins),
                        'total_after' => count($filtered_plugins)
                    );
                }
            }
            
            return $filtered_plugins;
            
        } catch (Exception $e) {
            // Never break the site - return original on any error
            return $plugins;
        } finally {
            $filtering_active = false;
        }
    }
    
    /**
     * Get plugins from manual configuration
     */
    private function get_manual_plugins($available_plugins) {
        // Ensure manual config class is loaded
        if (!class_exists('TCWP_Manual_Config') && file_exists(TCWP_PLUGIN_DIR . 'manual-config.php')) {
            require_once TCWP_PLUGIN_DIR . 'manual-config.php';
        }
        
        // Only use manual config if the class is available
        if (!class_exists('TCWP_Manual_Config')) {
            return array();
        }
        
        $current_url = $_SERVER['REQUEST_URI'] ?? '/';
        $manual_plugins = TCWP_Manual_Config::get_manual_plugins_for_url($current_url);
        
        // Debug logging for manual plugin loading
        if (WP_DEBUG || !empty(self::$options['debug_mode'])) {
            error_log('TCWP Manual Config - URL: ' . $current_url);
            error_log('TCWP Manual Config - Configured plugins: ' . implode(', ', $manual_plugins));
            error_log('TCWP Manual Config - Available plugins: ' . implode(', ', $available_plugins));
        }
        
        // Filter to only include plugins that are actually available
        $final_plugins = array_intersect($manual_plugins, $available_plugins);
        
        // Additional check: If MEC variants are configured, try to find the actual plugin
        $mec_variants = array(
            'modern-events-calendar/modern-events-calendar.php',
            'mec-events-calendar/mec-events-calendar.php',
            'modern-events-calendar-lite/modern-events-calendar-lite.php'
        );
        
        // Check if any MEC variant is in manual config
        foreach ($manual_plugins as $plugin) {
            if (in_array($plugin, $mec_variants)) {
                // Find which MEC variant is actually installed
                foreach ($mec_variants as $mec_variant) {
                    if (in_array($mec_variant, $available_plugins) && !in_array($mec_variant, $final_plugins)) {
                        $final_plugins[] = $mec_variant;
                        if (WP_DEBUG || !empty(self::$options['debug_mode'])) {
                            error_log('TCWP: Added MEC variant: ' . $mec_variant);
                        }
                    }
                }
            }
        }
        
        return $final_plugins;
    }
    
    /**
     * Get plugins based on URL patterns (ultra-fast string matching)
     */
    private function get_url_pattern_plugins($available_plugins) {
        $current_url = $_SERVER['REQUEST_URI'] ?? '/';
        $required_plugins = array();
        
        // Check for page-specific plugin requirements first
        foreach (self::$page_specific_plugins as $pattern => $plugins) {
            if (strpos($current_url, $pattern) !== false) {
                foreach ($plugins as $plugin) {
                    if (in_array($plugin, $available_plugins)) {
                        $required_plugins[] = $plugin;
                    }
                }
                break; // Found matching pattern, no need to check others
            }
        }
        
        // Fallback to simple pattern matching
        if (empty($required_plugins)) {
            foreach (self::$smart_patterns as $pattern => $plugin_or_array) {
                if (strpos($current_url, $pattern) !== false) {
                    // Handle both single plugin and array of plugin variants
                    $plugin_variants = is_array($plugin_or_array) ? $plugin_or_array : array($plugin_or_array);
                    
                    foreach ($plugin_variants as $plugin) {
                        if (in_array($plugin, $available_plugins)) {
                            $required_plugins[] = $plugin;
                            break; // Only add the first matching variant
                        }
                    }
                }
            }
            // Remove duplicates since WooCommerce patterns might match multiple times
            $required_plugins = array_unique($required_plugins);
        }
        
        return $required_plugins;
    }
    
    /**
     * Get plugins based on smart content detection (minimal overhead)
     */
    private function get_smart_content_plugins($available_plugins) {
        global $post;
        $required_plugins = array();
        
        // Quick WooCommerce detection (URL-based is faster than post content)
        if ($this->is_woocommerce_context() && in_array('woocommerce/woocommerce.php', $available_plugins)) {
            $required_plugins[] = 'woocommerce/woocommerce.php';
        }
        
        // Smart SEO plugin detection - load on public pages (optimized)
        if (!is_admin() || apply_filters('tcwp_is_frontend_simulation', false)) {
            // Pre-defined SEO plugins in priority order
            static $seo_plugins = array(
                'yoast-seo/wp-seo.php',
                'rankmath/rank-math.php',
                'all-in-one-seo-pack/all_in_one_seo_pack.php',
                'seo-by-rank-math/rank-math.php',
                'altseo-ai-plus/altseo-ai-plus.php',
            );
            
            // Find first active SEO plugin
            foreach ($seo_plugins as $seo_plugin) {
                if (in_array($seo_plugin, $available_plugins)) {
                    $required_plugins[] = $seo_plugin;
                    break;
                }
            }
        }
        
        // Post content analysis (only when needed)
        if ($post && !empty($post->post_content) && (function_exists('is_singular') ? is_singular() : true)) {
            $content = $post->post_content;
            
            // Ultra-fast shortcode detection
            if (strpos($content, '[contact-form') !== false && in_array('contact-form-7/wp-contact-form-7.php', $available_plugins)) {
                $required_plugins[] = 'contact-form-7/wp-contact-form-7.php';
            } elseif (strpos($content, '[wpforms') !== false && in_array('wpforms-lite/wpforms.php', $available_plugins)) {
                $required_plugins[] = 'wpforms-lite/wpforms.php';
            }
            
            // Fluent Forms shortcode detection
            if (strpos($content, '[fluentform') !== false || 
                strpos($content, '[fluent_form') !== false ||
                strpos($content, '[wpfluent') !== false) {
                $fluent_plugins = array(
                    'fluentform/fluentform.php',
                    'fluent-forms/fluent-forms.php',
                    'wp-fluent-forms/wp-fluent-forms.php'
                );
                foreach ($fluent_plugins as $fluent_plugin) {
                    if (in_array($fluent_plugin, $available_plugins)) {
                        $required_plugins[] = $fluent_plugin;
                    }
                }
            }
            
            // ENHANCED: PDF viewer shortcode detection
            if (strpos($content, '[pdf_view') !== false && in_array('code-snippets/code-snippets.php', $available_plugins)) {
                $required_plugins[] = 'code-snippets/code-snippets.php';
            }
            
            // Enhanced Elementor check - check for any Elementor content, not just edit mode
            if ($post->ID && function_exists('get_post_meta')) {
                $elementor_data = get_post_meta($post->ID, '_elementor_data', true);
                $elementor_edit_mode = get_post_meta($post->ID, '_elementor_edit_mode', true);
                
                // If page has Elementor data or is in builder mode
                if (!empty($elementor_data) || $elementor_edit_mode === 'builder') {
                    if (in_array('elementor/elementor.php', $available_plugins)) {
                        $required_plugins[] = 'elementor/elementor.php';
                    }
                    if (in_array('elementor-pro/elementor-pro.php', $available_plugins)) {
                        $required_plugins[] = 'elementor-pro/elementor-pro.php';
                    }
                    
                    // Check for form widgets in Elementor data
                    if (!empty($elementor_data)) {
                        // Fluent Forms widget detection
                        if (strpos($elementor_data, 'fluent-forms') !== false || 
                            strpos($elementor_data, 'fluentform') !== false ||
                            strpos($elementor_data, 'wpfluent') !== false ||
                            strpos($elementor_data, 'fluent_forms') !== false) {
                            // Common Fluent Forms plugin slugs
                            $fluent_plugins = array(
                                'fluentform/fluentform.php',
                                'fluent-forms/fluent-forms.php',
                                'wp-fluent-forms/wp-fluent-forms.php'
                            );
                            foreach ($fluent_plugins as $fluent_plugin) {
                                if (in_array($fluent_plugin, $available_plugins)) {
                                    $required_plugins[] = $fluent_plugin;
                                }
                            }
                        }
                        
                        // Contact Form 7 widget detection
                        if (strpos($elementor_data, 'contact-form-7') !== false ||
                            strpos($elementor_data, 'cf7') !== false) {
                            if (in_array('contact-form-7/wp-contact-form-7.php', $available_plugins)) {
                                $required_plugins[] = 'contact-form-7/wp-contact-form-7.php';
                            }
                        }
                        
                        // WPForms widget detection
                        if (strpos($elementor_data, 'wpforms') !== false) {
                            $wpforms_plugins = array(
                                'wpforms-lite/wpforms.php',
                                'wpforms/wpforms.php'
                            );
                            foreach ($wpforms_plugins as $wpforms_plugin) {
                                if (in_array($wpforms_plugin, $available_plugins)) {
                                    $required_plugins[] = $wpforms_plugin;
                                }
                            }
                        }
                    }
                }
            }
        }
        
        // ENHANCED: Taxonomy-based plugin detection for individual posts
        if ($post && $post->ID) {
            $required_plugins = array_merge($required_plugins, $this->get_taxonomy_based_plugins($post, $available_plugins));
        }
        
        return $required_plugins;
    }
    
    /**
     * Get plugins based on post's taxonomy assignments (for manual config inheritance)
     */
    private function get_taxonomy_based_plugins($post, $available_plugins) {
        $required_plugins = array();
        
        // Get all taxonomies for this post type
        $post_taxonomies = get_object_taxonomies($post->post_type, 'names');
        
        foreach ($post_taxonomies as $taxonomy_name) {
            // Get terms assigned to this post for this taxonomy
            $terms = get_the_terms($post->ID, $taxonomy_name);
            
            if (!is_wp_error($terms) && !empty($terms)) {
                foreach ($terms as $term) {
                    // Check for asset-type specific plugin requirements
                    if ($taxonomy_name === 'asset-type' || $taxonomy_name === 'asset_type') {
                        switch (strtolower($term->slug)) {
                            case 'pdf':
                            case 'document':
                                // PDF documents need code-snippets for PDF viewer
                                if (in_array('code-snippets/code-snippets.php', $available_plugins)) {
                                    $required_plugins[] = 'code-snippets/code-snippets.php';
                                }
                                break;
                                
                            case 'video':
                            case 'media':
                                // Videos need Presto Player
                                if (in_array('presto-player/presto-player.php', $available_plugins)) {
                                    $required_plugins[] = 'presto-player/presto-player.php';
                                }
                                break;
                                
                            default:
                                // Other media types use EmbedPress
                                if (in_array('embedpress/embedpress.php', $available_plugins)) {
                                    $required_plugins[] = 'embedpress/embedpress.php';
                                }
                                break;
                        }
                    }
                    
                    // Add Jet Engine plugins for custom taxonomies
                    if (!in_array($taxonomy_name, array('category', 'post_tag'))) {
                        $jet_plugins = array(
                            'jet-engine/jet-engine.php',
                            'jet-elements/jet-elements.php',
                            'jet-smart-filters/jet-smart-filters.php'
                        );
                        
                        foreach ($jet_plugins as $jet_plugin) {
                            if (in_array($jet_plugin, $available_plugins)) {
                                $required_plugins[] = $jet_plugin;
                            }
                        }
                    }
                }
            }
        }
        
        return array_unique($required_plugins);
    }
    
    /**
     * Apply ultra-mode filtering for maximum TTFB improvement
     */
    private function apply_ultra_mode_filtering($required_plugins, $all_plugins) {
        // Pre-compute essential plugins that actually exist (cached)
        static $ultra_essential = null;
        if ($ultra_essential === null) {
            $conditional_essential = array(
                'turbo-charge-wp/turbo-charge-wp.php',
                'wp-rocket/wp-rocket.php',
                'litespeed-cache/litespeed-cache.php', 
                'w3-total-cache/w3-total-cache.php',
                'wp-super-cache/wp-cache.php',
                'wordfence/wordfence.php',
                'better-wp-security/better-wp-security.php',
                'ithemes-security-pro/ithemes-security-pro.php',
                'akismet/akismet.php',
            );
            $ultra_essential = array_intersect($conditional_essential, $all_plugins);
        }
        
        $current_url = $_SERVER['REQUEST_URI'] ?? '/';
        
        // ULTRA-AGGRESSIVE: For homepage, only load absolute essentials + required plugins
        if ($current_url === '/' || $current_url === '/index.php' || $current_url === '') {
            $homepage_plugins = array_unique(array_merge($ultra_essential, $required_plugins));
            return array_intersect($homepage_plugins, $all_plugins);
        }
        
        // For other pages, merge essentials with required
        $filtered = array_unique(array_merge($ultra_essential, $required_plugins));
        return array_intersect($filtered, $all_plugins);
    }
    
    /**
     * Ultra-fast WooCommerce context detection
     */
    private function is_woocommerce_context() {
        $url = $_SERVER['REQUEST_URI'] ?? '';
        
        // Single-pass pattern check (optimized)
        return (strpos($url, 'shop') !== false || 
                strpos($url, 'cart') !== false || 
                strpos($url, 'checkout') !== false || 
                strpos($url, 'my-account') !== false || 
                strpos($url, 'product') !== false || 
                strpos($url, 'store') !== false);
    }
    
    /**
     * Check for critical operations
     */
    private function is_critical_operation() {
        // Only block filtering for truly critical operations
        $critical_reasons = array();
        
        if (defined('WP_INSTALLING') && WP_INSTALLING) {
            $critical_reasons[] = 'WP_INSTALLING';
        }
        if (defined('WP_CLI') && constant('WP_CLI')) {
            $critical_reasons[] = 'WP_CLI';
        }
        
        return !empty($critical_reasons);
    }
    
    /**
     * Determine if plugin filtering should be skipped
     * This centralizes all backend/non-frontend detection logic
     */
    private function should_skip_filtering() {
        $skip_reason = null;
        
        // Skip filtering in admin area
        if (is_admin()) {
            $skip_reason = 'admin_area';
        }
        // Skip filtering during AJAX requests - but be selective
        elseif (defined('DOING_AJAX') && DOING_AJAX) {
            // Allow filtering for frontend AJAX requests like Elementor widgets
            $ajax_action = $_REQUEST['action'] ?? '';
            
            // List of frontend AJAX actions that should still be filtered
            $frontend_ajax_actions = array(
                'elementor_ajax',
                'elementor_pro_forms_send_form',
                'fluentform_submit',
                'fluentform_ajax_submit',
                'wpforms_submit',
                'wpcf7-submit',
            );
            
            // Check if this is a frontend AJAX action
            $is_frontend_ajax = false;
            foreach ($frontend_ajax_actions as $action_prefix) {
                if (strpos($ajax_action, $action_prefix) === 0) {
                    $is_frontend_ajax = true;
                    break;
                }
            }
            
            // Also check referer to see if it's from frontend
            $referer = $_SERVER['HTTP_REFERER'] ?? '';
            if (!$is_frontend_ajax && !empty($referer)) {
                $admin_url = admin_url();
                // If referer is not from admin area, it's likely frontend AJAX
                if (strpos($referer, $admin_url) === false) {
                    $is_frontend_ajax = true;
                }
            }
            
            // Only skip filtering for true backend AJAX requests
            if (!$is_frontend_ajax) {
                $skip_reason = 'backend_ajax_request';
            }
        }
        // Skip filtering during REST API requests (used by many plugins for data)
        elseif (defined('REST_REQUEST') && REST_REQUEST) {
            $skip_reason = 'rest_api';
        }
        // Skip filtering during cron jobs (background tasks)
        elseif (defined('DOING_CRON') && DOING_CRON) {
            $skip_reason = 'cron_job';
        }
        // Skip filtering during feed generation (RSS, etc)
        elseif (is_feed()) {
            $skip_reason = 'feed_request';
        }
        // Skip filtering for XML-RPC requests
        elseif (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
            $skip_reason = 'xmlrpc_request';
        }
        // Skip filtering during critical operations
        elseif ($this->is_critical_operation()) {
            $skip_reason = 'critical_operation';
        }
        else {
            // Check for specific backend-related URLs that might not be caught above
            $request_uri = $_SERVER['REQUEST_URI'] ?? '';
            $backend_patterns = array(
                '/wp-json/'       => 'rest_api_url',
                '/feed/'          => 'feed_url',
                '/wp-cron.php'    => 'cron_url',
                'admin-ajax.php'  => 'ajax_url',
            );
            
            foreach ($backend_patterns as $pattern => $reason) {
                if (strpos($request_uri, $pattern) !== false) {
                    $skip_reason = $reason;
                    break;
                }
            }
        }
        
        // Log why filtering was skipped (only in debug mode)
        if ($skip_reason && (WP_DEBUG || !empty(self::$options['debug_mode']))) {
            error_log('TCWP: Skipping plugin filtering - reason: ' . $skip_reason . ' (URL: ' . ($_SERVER['REQUEST_URI'] ?? 'unknown') . ')');
        }
        
        // Only filter on true frontend requests
        return $skip_reason !== null;
    }
    
    /**
     * Initialize debug mode - ensure we have some debug info to display
     */
    public function init_debug_mode() {
        // If manual override is enabled, always set debug info
        if (!empty(self::$options['manual_override'])) {
            
            $active_plugins = get_option('active_plugins', array());
            $current_url = $_SERVER['REQUEST_URI'] ?? '/';
            
            // Get manual plugins for this URL
            $manual_plugins = array();
            if (class_exists('TCWP_Manual_Config')) {
                $manual_plugins = TCWP_Manual_Config::get_manual_plugins_for_url($current_url);
            } else {
            }
            
            // Always include essential security plugins
            $essential_security = array(
                'turbo-charge-wp/turbo-charge-wp.php',
                'wordfence/wordfence.php',
                'better-wp-security/better-wp-security.php',
                'ithemes-security-pro/ithemes-security-pro.php',
                'akismet/akismet.php',
            );
            
            $expected_plugins = array_unique(array_merge($manual_plugins, $essential_security));
            $expected_plugins = array_intersect($expected_plugins, $active_plugins);
            
            if (!empty($manual_plugins)) {
                self::$debug_info = array(
                    'filtered_plugins' => $expected_plugins,
                    'filtered_out' => array_diff($active_plugins, $expected_plugins),
                    'url' => $current_url,
                    'total_before' => count($active_plugins),
                    'total_after' => count($expected_plugins),
                    'manual_override' => true
                );
            } else {
                // No manual configuration found
                $essential_plugins = array_intersect(self::$essential_plugins, $active_plugins);
                self::$debug_info = array(
                    'filtered_plugins' => $essential_plugins,
                    'filtered_out' => array_diff($active_plugins, $essential_plugins),
                    'url' => $current_url,
                    'total_before' => count($active_plugins),
                    'total_after' => count($essential_plugins),
                    'manual_override' => true,
                    'no_manual_config' => true
                );
            }
        } else {
            // If no debug info was set during filtering, create basic debug info
            if (empty(self::$debug_info)) {
                $active_plugins = get_option('active_plugins', array());
                self::$debug_info = array(
                    'filtered_plugins' => $active_plugins,
                    'filtered_out' => array(),
                    'url' => $_SERVER['REQUEST_URI'],
                    'total_before' => count($active_plugins),
                    'total_after' => count($active_plugins),
                    'no_filtering' => true
                );
            }
        }
    }
    
    /**
     * Display debug information on frontend
     */
    public function display_debug_info() {
        // Only show for users who can manage options
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Check if we have debug info to display
        if (empty(self::$debug_info)) {
            self::$debug_info = array(
                'filtered_plugins' => get_option('active_plugins', array()),
                'filtered_out' => array(),
                'url' => $_SERVER['REQUEST_URI'],
                'total_before' => count(get_option('active_plugins', array())),
                'total_after' => count(get_option('active_plugins', array())),
                'no_filtering' => true
            );
        }
        
        $info = self::$debug_info;
        $optimization_percent = $info['total_before'] > 0 ? round((($info['total_before'] - $info['total_after']) / $info['total_before']) * 100, 1) : 0;
        
        // Different colors for different states
        $status_color = '#4CAF50'; // Green: optimization occurred
        $status_text = 'Filtering Active';
        
        if (!empty($info['manual_override'])) {
            $status_color = '#2196F3'; // Blue: manual override mode
            $status_text = 'Manual Override';
            if (!empty($info['no_manual_config'])) {
                $status_text = 'Manual Override (No Config)';
                $status_color = '#FF9800'; // Orange: manual override but no config
            }
        } elseif (!empty($info['no_filtering'])) {
            $status_color = '#f44336'; // Red: no filtering occurred
            $status_text = 'No Filtering';
        } elseif ($optimization_percent <= 0) {
            $status_color = '#FF9800'; // Orange: no optimization
            $status_text = 'No Optimization';
        }
        
        ?>
        <style>
        #tcwp-debug-btn {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: <?php echo $status_color; ?>;
            color: white;
            border: none;
            border-radius: 50%;
            width: 60px;
            height: 60px;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
            z-index: 999998;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            transition: all 0.3s ease;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        
        #tcwp-debug-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 20px rgba(0,0,0,0.4);
        }
        
        #tcwp-debug-panel {
            position: fixed;
            bottom: 90px;
            right: 20px;
            background: #1a1a1a;
            color: #ffffff;
            padding: 20px;
            border-radius: 12px;
            max-width: 400px;
            max-height: 500px;
            overflow-y: auto;
            z-index: 999999;
            box-shadow: 0 8px 32px rgba(0,0,0,0.5);
            font-family: 'Courier New', monospace;
            font-size: 12px;
            line-height: 1.5;
            display: none;
            border: 1px solid #333;
        }
        
        #tcwp-debug-panel h3 {
            margin: 0 0 15px 0;
            color: <?php echo $status_color; ?>;
            font-size: 16px;
            font-weight: bold;
            text-align: center;
            border-bottom: 1px solid #333;
            padding-bottom: 10px;
        }
        
        .tcwp-debug-section {
            margin-bottom: 15px;
            padding: 10px;
            background: #2a2a2a;
            border-radius: 6px;
            border-left: 3px solid <?php echo $status_color; ?>;
        }
        
        .tcwp-debug-section h4 {
            margin: 0 0 8px 0;
            color: #ffffff;
            font-size: 13px;
            font-weight: bold;
        }
        
        .tcwp-debug-stat {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
        }
        
        .tcwp-debug-stat-label {
            color: #cccccc;
        }
        
        .tcwp-debug-stat-value {
            color: #ffffff;
            font-weight: bold;
        }
        
        .tcwp-plugin-list {
            max-height: 120px;
            overflow-y: auto;
            background: #333;
            padding: 8px;
            border-radius: 4px;
            margin-top: 5px;
        }
        
        .tcwp-plugin-item {
            padding: 2px 0;
            font-size: 11px;
            border-bottom: 1px solid #444;
        }
        
        .tcwp-plugin-item:last-child {
            border-bottom: none;
        }
        
        .tcwp-plugin-loaded {
            color: #4CAF50;
        }
        
        .tcwp-plugin-filtered {
            color: #f44336;
        }
        
        .tcwp-debug-url {
            background: #333;
            padding: 8px;
            border-radius: 4px;
            margin-top: 5px;
            word-break: break-all;
            font-size: 10px;
            color: #ccc;
        }
        
        .tcwp-close-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: none;
            border: none;
            color: #999;
            font-size: 18px;
            cursor: pointer;
            padding: 0;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .tcwp-close-btn:hover {
            color: #fff;
        }
        
        @media (max-width: 768px) {
            #tcwp-debug-panel {
                right: 10px;
                left: 10px;
                bottom: 80px;
                max-width: none;
            }
            
            #tcwp-debug-btn {
                bottom: 10px;
                right: 10px;
                width: 50px;
                height: 50px;
                font-size: 12px;
            }
        }
        </style>
        
        <button id="tcwp-debug-btn" title="TCWP Debug Info">
            TCWP<br><?php echo $info['total_after']; ?>
        </button>
        
        <div id="tcwp-debug-panel">
            <button class="tcwp-close-btn" onclick="document.getElementById('tcwp-debug-panel').style.display='none'">×</button>
            
            <h3>🚀 TCWP Debug Panel</h3>
            
            <div class="tcwp-debug-section">
                <h4>Performance Status</h4>
                <div class="tcwp-debug-stat">
                    <span class="tcwp-debug-stat-label">Status:</span>
                    <span class="tcwp-debug-stat-value"><?php echo $status_text; ?></span>
                </div>
                <?php if (!empty($info['manual_override'])): ?>
                <div class="tcwp-debug-stat">
                    <span class="tcwp-debug-stat-label">Mode:</span>
                    <span class="tcwp-debug-stat-value">Manual Override Active</span>
                </div>
                <?php if (!empty($info['no_manual_config'])): ?>
                <div class="tcwp-debug-stat">
                    <span class="tcwp-debug-stat-label">Warning:</span>
                    <span class="tcwp-debug-stat-value">No Manual Config Found</span>
                </div>
                <?php endif; ?>
                <?php endif; ?>
                <div class="tcwp-debug-stat">
                    <span class="tcwp-debug-stat-label">Plugins Loaded:</span>
                    <span class="tcwp-debug-stat-value"><?php echo $info['total_after']; ?> of <?php echo $info['total_before']; ?></span>
                </div>
                <?php if ($optimization_percent > 0): ?>
                <div class="tcwp-debug-stat">
                    <span class="tcwp-debug-stat-label">Optimization:</span>
                    <span class="tcwp-debug-stat-value"><?php echo $optimization_percent; ?>% reduction</span>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="tcwp-debug-section">
                <h4>Current Page</h4>
                <div class="tcwp-debug-url"><?php echo esc_html($info['url']); ?></div>
            </div>
            
            <div class="tcwp-debug-section">
                <h4>Loaded Plugins (<?php echo count($info['filtered_plugins']); ?>)</h4>
                <div class="tcwp-plugin-list">
                    <?php foreach ($info['filtered_plugins'] as $plugin): ?>
                        <div class="tcwp-plugin-item tcwp-plugin-loaded">
                            ✅ <?php echo esc_html(basename($plugin, '.php')); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <?php if (!empty($info['filtered_out'])): ?>
            <div class="tcwp-debug-section">
                <h4>Filtered Out (<?php echo count($info['filtered_out']); ?>)</h4>
                <div class="tcwp-plugin-list">
                    <?php foreach ($info['filtered_out'] as $plugin): ?>
                        <div class="tcwp-plugin-item tcwp-plugin-filtered">
                            ❌ <?php echo esc_html(basename($plugin, '.php')); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <script>
        document.getElementById('tcwp-debug-btn').addEventListener('click', function() {
            var panel = document.getElementById('tcwp-debug-panel');
            if (panel.style.display === 'none' || panel.style.display === '') {
                panel.style.display = 'block';
            } else {
                panel.style.display = 'none';
            }
        });
        
        // Close panel when clicking outside
        document.addEventListener('click', function(e) {
            var panel = document.getElementById('tcwp-debug-panel');
            var btn = document.getElementById('tcwp-debug-btn');
            
            if (!panel.contains(e.target) && !btn.contains(e.target)) {
                panel.style.display = 'none';
            }
        });
        
        // Close panel with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.getElementById('tcwp-debug-panel').style.display = 'none';
            }
        });
        </script>
        <?php
    }
    
    /**
     * Track performance metrics (admin only, ultra-lightweight)
     */
    public function track_performance() {
        // Only track if user can manage options and we have meaningful data
        if (!is_admin() || !current_user_can('manage_options') || self::$performance_data['plugins_before'] === 0) {
            return;
        }
        
        $data = self::$performance_data;
        $data['total_time'] = (microtime(true) - TCWP_START_TIME) * 1000;
        $data['plugins_saved'] = $data['plugins_before'] - $data['plugins_after'];
        $data['optimization_percent'] = $data['plugins_before'] > 0 ? 
            round(($data['plugins_saved'] / $data['plugins_before']) * 100, 1) : 0;
        $data['timestamp'] = time();
        
        // Ultra-lightweight performance logging (using transients to avoid DB writes)
        set_transient('tcwp_last_performance', $data, HOUR_IN_SECONDS);
        
        // Only store persistent log occasionally to reduce DB writes
        if ($data['optimization_percent'] > 0 && rand(1, 10) === 1) {
            $performance_log = get_option('tcwp_performance_log', array());
            
            // Keep only last 10 entries to minimize database usage
            if (count($performance_log) >= 10) {
                $performance_log = array_slice($performance_log, -9);
            }
            
            $performance_log[] = $data;
            update_option('tcwp_performance_log', $performance_log, false); // Don't autoload
        }
    }
    
    /**
     * Minimal admin interface
     */
    public function add_admin_menu() {
        add_options_page(
            'Turbo Charge WP',
            'Turbo Charge WP',
            'manage_options',
            'turbo-charge-wp',
            array($this, 'admin_page')
        );
    }
    
    /**
     * Ultra-simple admin page
     */
    public function admin_page() {
        if (isset($_POST['submit'])) {
            // Verify nonce for security
            if (!isset($_POST['tcwp_settings_nonce']) || !wp_verify_nonce($_POST['tcwp_settings_nonce'], 'tcwp_save_settings')) {
                wp_die('Security check failed. Please try again.');
            }
            
            // Check user capabilities
            if (!current_user_can('manage_options')) {
                wp_die('You do not have sufficient permissions to access this page.');
            }
            
            $options = array(
                'enabled' => !empty($_POST['enabled']),
                'filter_admin' => !empty($_POST['filter_admin']),
                'ultra_mode' => !empty($_POST['ultra_mode']),
                'smart_defaults' => !empty($_POST['smart_defaults']),
                'debug_mode' => !empty($_POST['debug_mode']),
                'manual_override' => !empty($_POST['manual_override']),
            );
            
            // If manual override is enabled, force disable other options
            if ($options['manual_override']) {
                $options['enabled'] = false;
                $options['ultra_mode'] = false;
                $options['smart_defaults'] = false;
            }
            update_option('tcwp_options', $options);
            self::$options = $options;
            echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
        }
        
        $options = self::$options;
        
        // Get performance data (lightweight)
        $latest_performance = get_transient('tcwp_last_performance');
        if (!$latest_performance) {
            $performance_log = get_option('tcwp_performance_log', array());
            $latest_performance = !empty($performance_log) ? end($performance_log) : null;
        }
        
        ?>
        <div class="wrap">
            <h1>Turbo Charge WP - Ultra Performance Mode</h1>
            
            <?php
            // Check if MU plugin is installed
            $mu_plugin_installed = file_exists(WP_CONTENT_DIR . '/mu-plugins/tcwp-loader.php');
            ?>
            
            <div class="notice notice-<?php echo $mu_plugin_installed ? 'success' : 'warning'; ?>">
                <p><strong>MU Plugin Status:</strong> 
                <?php if ($mu_plugin_installed): ?>
                    ✅ Installed - Maximum performance mode active
                <?php else: ?>
                    ⚠️ Not installed - <a href="<?php echo admin_url('plugins.php'); ?>">Reactivate the plugin</a> to install the MU plugin for maximum performance
                <?php endif; ?>
                </p>
            </div>
            
            <?php if ($latest_performance): ?>
            <div class="notice notice-info">
                <h3>🚀 Performance Impact</h3>
                <p><strong>Latest optimization:</strong> 
                   Reduced <?php echo $latest_performance['plugins_saved']; ?> plugins 
                   (<?php echo $latest_performance['optimization_percent']; ?>% reduction) 
                   in <?php echo number_format($latest_performance['filter_time'], 2); ?>ms
                </p>
                <p><strong>Plugins active:</strong> <?php echo $latest_performance['plugins_after']; ?> of <?php echo $latest_performance['plugins_before']; ?></p>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($options['manual_override'])): ?>
            <div class="notice notice-info">
                <h3>🎯 Manual Override Mode Active</h3>
                <p><strong>Status:</strong> Only plugins configured in the <a href="<?php echo admin_url('admin.php?page=tcwp-manual-config'); ?>">Manual Configuration</a> page will be loaded on the frontend.</p>
                <p><strong>Note:</strong> All automatic optimization settings are disabled while manual override is active.</p>
            </div>
            <?php endif; ?>
            
            <form method="post">
                <?php wp_nonce_field('tcwp_save_settings', 'tcwp_settings_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Manual Override</th>
                        <td>
                            <label>
                                <input type="checkbox" name="manual_override" id="manual_override" value="1" <?php checked(!empty($options['manual_override'])); ?>>
                                <strong>Enable manual override mode</strong>
                            </label>
                            <p class="description">When enabled, only plugins configured in the Manual Configuration page will be loaded. All other optimization settings will be disabled.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Enable Optimization</th>
                        <td>
                            <label>
                                <input type="checkbox" name="enabled" id="enabled" value="1" <?php checked(!empty($options['enabled'])); ?>>
                                Enable ultra-performance plugin filtering
                            </label>
                            <p class="description">Dramatically improves TTFB by loading only required plugins per page.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Ultra Mode</th>
                        <td>
                            <label>
                                <input type="checkbox" name="ultra_mode" id="ultra_mode" value="1" <?php checked(!empty($options['ultra_mode'])); ?>>
                                Enable ultra-aggressive optimization
                            </label>
                            <p class="description">Maximum performance mode - only loads absolutely essential plugins.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Smart Defaults</th>
                        <td>
                            <label>
                                <input type="checkbox" name="smart_defaults" id="smart_defaults" value="1" <?php checked(!empty($options['smart_defaults'])); ?>>
                                Enable intelligent plugin detection
                            </label>
                            <p class="description">Automatically detect plugins needed based on page content.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Debug Mode</th>
                        <td>
                            <label>
                                <input type="checkbox" name="debug_mode" value="1" <?php checked(!empty($options['debug_mode'])); ?>>
                                Enable debug mode (show filtering info)
                            </label>
                            <p class="description">Shows debug information about plugin filtering in the frontend.</p>
                        </td>
                    </tr>
                </table>
                
                <script>
                jQuery(document).ready(function($) {
                    function toggleManualOverride() {
                        var manualOverride = $('#manual_override').is(':checked');
                        var otherCheckboxes = $('#enabled, #ultra_mode, #smart_defaults');
                        
                        if (manualOverride) {
                            // Disable and uncheck other optimization options
                            otherCheckboxes.prop('checked', false).prop('disabled', true);
                            otherCheckboxes.closest('tr').css('opacity', '0.5');
                        } else {
                            // Enable other optimization options
                            otherCheckboxes.prop('disabled', false);
                            otherCheckboxes.closest('tr').css('opacity', '1');
                        }
                    }
                    
                    // Initialize on page load
                    toggleManualOverride();
                    
                    // Handle changes
                    $('#manual_override').change(function() {
                        toggleManualOverride();
                    });
                });
                </script>
                
                <?php submit_button('Save Settings'); ?>
            </form>
            
            <div class="postbox">
                <h3 class="hndle">🎯 Performance Tools</h3>
                <div class="inside">
                    <?php if (class_exists('TCWP_Manual_Config')): ?>
                    <p><a href="<?php echo admin_url('admin.php?page=tcwp-manual-config'); ?>" class="button button-primary">⚙️ Manual Configuration</a> - Define exactly which plugins load on which pages</p>
                    <?php endif; ?>
                    <p><a href="<?php echo admin_url('admin.php?page=tcwp-performance-test'); ?>" class="button button-secondary">🧪 Run Performance Test</a></p>
                    <p><a href="<?php echo admin_url('admin.php?page=tcwp-page-tester'); ?>" class="button button-primary">📊 Test Specific Pages</a></p>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Ultra-lightweight activation
        $default_options = array(
            'enabled' => true,
            'filter_admin' => false,
            'ultra_mode' => true,
            'smart_defaults' => true,
            'debug_mode' => false,
            'manual_override' => false,
        );
        
        add_option('tcwp_options', $default_options);
        
        // Install MU plugin for early loading
        $this->install_mu_plugin();
        
        // Clear any caches
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
    }
    
    /**
     * Install MU plugin for early loading
     */
    private function install_mu_plugin() {
        $mu_dir = WP_CONTENT_DIR . '/mu-plugins';
        $source_file = TCWP_PLUGIN_DIR . 'mu-plugin-loader.php';
        $dest_file = $mu_dir . '/tcwp-loader.php';
        
        // Create mu-plugins directory if it doesn't exist
        if (!file_exists($mu_dir)) {
            wp_mkdir_p($mu_dir);
        }
        
        // Copy the MU plugin file
        if (file_exists($source_file)) {
            copy($source_file, $dest_file);
        }
    }
    
    /**
     * Handle AJAX autosave requests
     */
    public function handle_autosave_config() {
        // Debug: Log that handler was called
        
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'tcwp_autosave_nonce')) {
            wp_send_json_error('Security check failed');
        }
        
        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        
        try {
            // CRITICAL FIX: Ensure manual config class is loaded for AJAX requests
            if (!class_exists('TCWP_Manual_Config')) {
                if (file_exists(TCWP_PLUGIN_DIR . 'manual-config.php')) {
                    require_once TCWP_PLUGIN_DIR . 'manual-config.php';
                }
            }
            
            // Debug: Log received data
            
            // Get form data
            $config_type = sanitize_text_field($_POST['config_type']);
            $patterns = isset($_POST['manual_config_patterns']) ? (array) $_POST['manual_config_patterns'] : array();
            $plugins = isset($_POST['manual_config_plugins']) ? (array) $_POST['manual_config_plugins'] : array();
            
            // Debug: Log parsed data
            
            // Sanitize patterns
            $patterns = array_map('sanitize_text_field', $patterns);
            
            // Sanitize plugins - handle the nested array structure
            $sanitized_plugins = array();
            foreach ($plugins as $pattern => $plugin_list) {
                $pattern = sanitize_text_field($pattern);
                if (is_array($plugin_list)) {
                    $sanitized_plugins[$pattern] = array_map('sanitize_text_field', $plugin_list);
                } else {
                    $sanitized_plugins[$pattern] = array();
                }
            }
            
            // Get existing configuration to merge with new data
            $existing_config = get_option('tcwp_manual_config', array());
            
            // Build final configuration by merging with existing
            $config = $existing_config;
            foreach ($patterns as $pattern) {
                if (isset($sanitized_plugins[$pattern])) {
                    if (!empty($sanitized_plugins[$pattern])) {
                        $config[$pattern] = $sanitized_plugins[$pattern];
                    } else {
                        // Remove empty configurations
                        unset($config[$pattern]);
                    }
                }
            }
            
            // Debug: Log final config
            
            // Update option
            $update_result = update_option('tcwp_manual_config', $config);
            
            // Update last save time
            update_option('tcwp_manual_config_last_save', time());
            
            // Return success with enhanced stats
            $plugin_counts = [];
            foreach ($config as $pattern => $plugins) {
                $plugin_counts[$pattern] = count($plugins);
            }
            
            $stats = array(
                'patterns_saved' => count($config),
                'total_plugins' => array_sum(array_map('count', $config)),
                'configured_patterns' => array_keys($config),
                'plugin_counts' => $plugin_counts
            );
            
            // Enhanced logging
            
            wp_send_json_success($stats);
            
        } catch (Exception $e) {
            wp_send_json_error('Save failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Remove MU plugin
        $mu_file = WP_CONTENT_DIR . '/mu-plugins/tcwp-loader.php';
        if (file_exists($mu_file)) {
            unlink($mu_file);
        }
        
        // Clear performance data
        delete_option('tcwp_performance_log');
        
        // Clear caches
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
    }
}

// Initialize the plugin with zero overhead
TurboChargeWP::get_instance();

// Load performance test ONLY when explicitly requested (zero overhead)
if (is_admin() && !empty($_GET['page']) && $_GET['page'] === 'tcwp-performance-test') {
    if (file_exists(TCWP_PLUGIN_DIR . 'performance-test.php')) {
        require_once TCWP_PLUGIN_DIR . 'performance-test.php';
    }
}

// Load page tester ONLY when explicitly requested (zero overhead)
if (is_admin() && !empty($_GET['page']) && $_GET['page'] === 'tcwp-page-tester') {
    if (file_exists(TCWP_PLUGIN_DIR . 'page-tester.php')) {
        require_once TCWP_PLUGIN_DIR . 'page-tester.php';
    }
}
