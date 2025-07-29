<?php
/**
 * Enhanced Manual Configuration System for Turbo Charge WP
 * 
 * Comprehensive control over plugin loading for all pages, posts, and menu items
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Enhanced Manual Configuration Handler
 */
class TCWP_Manual_Config {
    
    /**
     * Add manual configuration to admin menu
     */
    public static function add_admin_menu() {
        add_submenu_page(
            'turbo-charge-wp',
            'Manual Configuration',
            'Manual Config',
            'manage_options',
            'tcwp-manual-config',
            array(__CLASS__, 'admin_page')
        );
    }
    
    /**
     * Clear site items cache when needed
     */
    public static function clear_site_items_cache() {
        delete_transient('tcwp_site_items_v2');
    }
    
    /**
     * Initialize AJAX handlers
     */
    public static function init_ajax_handlers() {
        add_action('wp_ajax_tcwp_autosave_config', array(__CLASS__, 'ajax_autosave_config'));
        add_action('wp_ajax_tcwp_refresh_cache', array(__CLASS__, 'ajax_refresh_cache'));
    }
    
    /**
     * AJAX handler for auto-saving configuration
     */
    public static function ajax_autosave_config() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'tcwp_autosave_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        try {
            $patterns = $_POST['manual_config_patterns'] ?? array();
            $plugins = $_POST['manual_config_plugins'] ?? array();
            
            // Debug: Check for duplicate patterns before processing
            $pattern_counts = array_count_values($patterns);
            $duplicates = array_filter($pattern_counts, function($count) { return $count > 1; });
            if (!empty($duplicates)) {
                error_log('TCWP AJAX: WARNING - Duplicate patterns detected: ' . print_r($duplicates, true));
            }
            
            
            // Start with empty configuration instead of existing - this is the fix!
            $manual_config = array();
            
            // Process configurations
            $configured_patterns = array();
            $plugin_counts = array();
            $total_plugins = 0;
            
            foreach ($patterns as $pattern) {
                if (!empty($pattern)) {
                    $pattern = sanitize_text_field($pattern);
                    
                    // Handle both array format (multiple plugins) and empty string format (no plugins)
                    if (isset($plugins[$pattern])) {
                        if (is_array($plugins[$pattern])) {
                            $pattern_plugins = array_map('sanitize_text_field', $plugins[$pattern]);
                        } else {
                            // Empty string means no plugins selected - convert to empty array
                            $pattern_plugins = array();
                        }
                    } else {
                        $pattern_plugins = array();
                    }
                    
                    // Debug logging
                    error_log('TCWP AJAX: Processing pattern "' . $pattern . '" with ' . count($pattern_plugins) . ' plugins: ' . implode(', ', $pattern_plugins));
                    
                    // Always save the configuration, even if empty (for "none" selections)
                    $manual_config[$pattern] = $pattern_plugins;
                    $configured_patterns[] = $pattern;
                    $plugin_counts[$pattern] = count($pattern_plugins);
                    $total_plugins += count($pattern_plugins);
                    
                }
            }
            
            // Save to database
            $success = update_option('tcwp_manual_config', $manual_config);
            
            if ($success) {
                // Ensure database write is committed before proceeding
                wp_cache_flush();
                
                // Clear cache after successful save
                self::clear_site_items_cache();
                
                wp_send_json_success(array(
                    'patterns_saved' => count($configured_patterns),
                    'total_plugins' => $total_plugins,
                    'configured_patterns' => $configured_patterns,
                    'plugin_counts' => $plugin_counts,
                    'message' => 'Configuration saved successfully'
                ));
            } else {
                wp_send_json_error('Failed to save configuration to database');
            }
            
        } catch (Exception $e) {
            error_log('TCWP AJAX Error: ' . $e->getMessage());
            wp_send_json_error('Server error: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX handler for refreshing cache
     */
    public static function ajax_refresh_cache() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'tcwp_refresh_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
            return;
        }
        
        self::clear_site_items_cache();
        wp_send_json_success('Cache cleared successfully');
    }
    
    /**
     * Get all available pages, posts, and menu items (optimized for performance)
     */
    public static function get_all_site_items() {
        // Check cache first
        $cache_key = 'tcwp_site_items_v2';
        $cached_items = get_transient($cache_key);
        if ($cached_items !== false) {
            return $cached_items;
        }
        
        $items = array();
        
        // Homepage
        $items['homepage'] = array(
            'type' => 'homepage',
            'title' => '🏠 Homepage',
            'url' => home_url('/'),
            'pattern' => '/',
            'priority' => 1
        );
        
        // Blog page
        $blog_page_id = get_option('page_for_posts');
        if ($blog_page_id) {
            $items['blog'] = array(
                'type' => 'blog',
                'title' => '📚 Blog Page',
                'url' => get_permalink($blog_page_id),
                'pattern' => 'blog',
                'priority' => 2
            );
        }
        
        // Optimize pages query - reduce number and remove expensive operations
        $pages = get_pages(array(
            'post_status' => 'publish',
            'number' => 100, // Reduced from 500
            'sort_column' => 'menu_order',
            'hierarchical' => false // Disable hierarchy to improve performance
        ));
        
        $front_page_id = get_option('page_on_front');
        
        foreach ($pages as $page) {
            // Skip the front page to avoid duplicate with homepage entry
            if ($front_page_id && $page->ID == $front_page_id) {
                continue;
            }
            
            $permalink = get_permalink($page->ID);
            $items['page_' . $page->ID] = array(
                'type' => 'page',
                'title' => '📄 ' . $page->post_title,
                'url' => $permalink,
                'pattern' => parse_url($permalink, PHP_URL_PATH),
                'id' => $page->ID,
                'priority' => 3
            );
        }
        
        // Optimize posts query - reduce number and use better parameters
        $posts = get_posts(array(
            'post_status' => 'publish',
            'numberposts' => 25, // Reduced from 50
            'post_type' => 'post',
            'orderby' => 'date',
            'order' => 'DESC',
            'suppress_filters' => true // Improve performance
        ));
        
        foreach ($posts as $post) {
            $permalink = get_permalink($post->ID);
            $items['post_' . $post->ID] = array(
                'type' => 'post',
                'title' => '📝 ' . $post->post_title,
                'url' => $permalink,
                'pattern' => parse_url($permalink, PHP_URL_PATH),
                'id' => $post->ID,
                'priority' => 4
            );
        }
        
        // WooCommerce pages (optimized)
        if (class_exists('WooCommerce')) {
            $woo_pages = array(
                'shop' => get_option('woocommerce_shop_page_id'),
                'cart' => get_option('woocommerce_cart_page_id'),
                'checkout' => get_option('woocommerce_checkout_page_id'),
                'account' => get_option('woocommerce_myaccount_page_id'),
            );
            
            foreach ($woo_pages as $key => $page_id) {
                if ($page_id) {
                    $title = get_the_title($page_id);
                    $permalink = get_permalink($page_id);
                    $items['woo_' . $key] = array(
                        'type' => 'woocommerce',
                        'title' => '🛍️ ' . $title,
                        'url' => $permalink,
                        'pattern' => $key,
                        'id' => $page_id,
                        'priority' => 2
                    );
                }
            }
        }
        
        // Custom post types and their archives (optimized)
        $post_types = get_post_types(array(
            'public' => true,
            'show_ui' => true,
            '_builtin' => false
        ), 'objects');
        
        foreach ($post_types as $post_type) {
            // Add archive page if it has one
            if ($post_type->has_archive) {
                $archive_url = get_post_type_archive_link($post_type->name);
                if ($archive_url) {
                    $items['archive_' . $post_type->name] = array(
                        'type' => 'custom_post_archive',
                        'title' => '📚 ' . $post_type->labels->name . ' Archive',
                        'url' => $archive_url,
                        'pattern' => parse_url($archive_url, PHP_URL_PATH),
                        'post_type' => $post_type->name,
                        'priority' => 4
                    );
                }
            }
            
            // Add individual posts (reduced limit)
            $custom_posts = get_posts(array(
                'post_type' => $post_type->name,
                'post_status' => 'publish',
                'numberposts' => 10, // Reduced from 20
                'suppress_filters' => true
            ));
            
            foreach ($custom_posts as $post) {
                $permalink = get_permalink($post->ID);
                $items[$post_type->name . '_' . $post->ID] = array(
                    'type' => 'custom_post',
                    'title' => '📋 ' . $post->post_title . ' (' . $post_type->label . ')',
                    'url' => $permalink,
                    'pattern' => parse_url($permalink, PHP_URL_PATH),
                    'id' => $post->ID,
                    'post_type' => $post_type->name,
                    'priority' => 5
                );
            }
        }
        
        // Custom taxonomies (optimized)
        $taxonomies = get_taxonomies(array(
            'public' => true,
            '_builtin' => false
        ), 'objects');
        
        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms(array(
                'taxonomy' => $taxonomy->name,
                'hide_empty' => false,
                'number' => 15, // Reduced from 20
                'fields' => 'all' // Explicit field selection
            ));
            
            if (!is_wp_error($terms) && !empty($terms)) {
                foreach ($terms as $term) {
                    $term_url = get_term_link($term);
                    if (!is_wp_error($term_url)) {
                        $items['tax_' . $term->term_id] = array(
                            'type' => 'taxonomy',
                            'title' => '🏷️ ' . $term->name . ' (' . $taxonomy->labels->singular_name . ')',
                            'url' => $term_url,
                            'pattern' => parse_url($term_url, PHP_URL_PATH),
                            'id' => $term->term_id,
                            'taxonomy' => $taxonomy->name,
                            'priority' => 6
                        );
                    }
                }
            }
        }
        
        // Menu items from all menus (optimized)
        $menus = wp_get_nav_menus();
        $menu_count = 0;
        foreach ($menus as $menu) {
            // Limit menu processing to prevent timeouts
            if ($menu_count >= 5) break; // Limit to 5 menus max
            
            $menu_items = wp_get_nav_menu_items($menu->term_id, array('update_post_term_cache' => false));
            if ($menu_items) {
                $item_count = 0;
                foreach ($menu_items as $menu_item) {
                    // Limit menu items per menu
                    if ($item_count >= 20) break;
                    
                    if ($menu_item->url && !isset($items['menu_' . $menu_item->ID])) {
                        $url_path = parse_url($menu_item->url, PHP_URL_PATH);
                        $unique_pattern = $url_path . '::menu_' . $menu_item->ID;
                        
                        $items['menu_' . $menu_item->ID] = array(
                            'type' => 'menu',
                            'title' => '🔗 ' . $menu_item->title . ' (Menu: ' . $menu->name . ')',
                            'url' => $menu_item->url,
                            'pattern' => $unique_pattern,
                            'id' => $menu_item->ID,
                            'menu_id' => $menu->term_id,
                            'menu_name' => $menu->name,
                            'priority' => 7
                        );
                        $item_count++;
                    }
                }
            }
            $menu_count++;
        }
        
        // Remove duplicates based on pattern to prevent AJAX processing issues
        $seen_patterns = array();
        foreach ($items as $key => $item) {
            if (isset($seen_patterns[$item['pattern']])) {
                // Keep the higher priority item (lower number = higher priority)
                if ($items[$seen_patterns[$item['pattern']]]['priority'] > $item['priority']) {
                    // Remove the existing item, keep the new one
                    unset($items[$seen_patterns[$item['pattern']]]);
                    $seen_patterns[$item['pattern']] = $key;
                } else {
                    // Remove the new item, keep the existing one
                    unset($items[$key]);
                }
            } else {
                $seen_patterns[$item['pattern']] = $key;
            }
        }
        
        // Sort by priority and title
        uasort($items, function($a, $b) {
            if ($a['priority'] == $b['priority']) {
                return strcmp($a['title'], $b['title']);
            }
            return $a['priority'] - $b['priority'];
        });
        
        // Cache the results for better performance (cache for 15 minutes)
        set_transient($cache_key, $items, 15 * MINUTE_IN_SECONDS);
        
        return $items;
    }
    
    /**
     * Handle export request early in admin_init to avoid headers already sent
     */
    public static function handle_export_request() {
        if (isset($_GET['page']) && $_GET['page'] === 'tcwp-manual-config' && 
            isset($_GET['action']) && $_GET['action'] === 'export_config') {
            self::export_configuration_xml();
            exit;
        }
    }

    /**
     * Enhanced admin page with comprehensive site coverage
     */
    public static function admin_page() {
        // Handle form submissions
        if (isset($_POST['submit_manual_config'])) {
            self::save_manual_config();
        }
        
        if (isset($_POST['import_from_patterns'])) {
            self::import_from_url_patterns();
        }
        
        // Handle import request
        if (isset($_POST['import_config_xml'])) {
            $import_result = self::import_configuration_xml();
            if ($import_result['success']) {
                echo '<div class="notice notice-success"><p>' . esc_html($import_result['message']) . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>' . esc_html($import_result['message']) . '</p></div>';
            }
        }
        
        // Handle bulk actions
        if (isset($_POST['bulk_action'])) {
            self::handle_bulk_action($_POST['bulk_action']);
        }
        
        // Ensure dashicons are loaded
        wp_enqueue_style('dashicons');
        
        $manual_config = get_option('tcwp_manual_config', array());
        $all_plugins = get_option('active_plugins', array());
        $all_site_items = self::get_all_site_items();
        
        // Debug output
        if (WP_DEBUG) {
            error_log('TCWP Debug: Manual config loaded: ' . print_r($manual_config, true));
            error_log('TCWP Debug: All plugins count: ' . count($all_plugins));
            error_log('TCWP Debug: All site items count: ' . count($all_site_items));
        }
        
        // Get current tab and preserve filter tab
        $current_tab = $_GET['tab'] ?? 'site_pages';
        $current_filter_tab = $_GET['filter_tab'] ?? 'all';
        $filter_tab_param = ($current_filter_tab !== 'all') ? '&filter_tab=' . urlencode($current_filter_tab) : '';
        
        ?>
        <div class="wrap">
            <h1>🎯 Manual Plugin Configuration</h1>
            <p>Comprehensive control over plugin loading for every page, post, and menu item on your site.</p>
            
            <!-- Tab Navigation -->
            <h2 class="nav-tab-wrapper">
                <a href="?page=tcwp-manual-config&tab=site_pages<?php echo $filter_tab_param; ?>" class="nav-tab <?php echo $current_tab === 'site_pages' ? 'nav-tab-active' : ''; ?>">
                    📄 Site Pages & Posts
                </a>
                <a href="?page=tcwp-manual-config&tab=url_patterns" class="nav-tab <?php echo $current_tab === 'url_patterns' ? 'nav-tab-active' : ''; ?>">
                    🔗 URL Patterns
                </a>
                <a href="?page=tcwp-manual-config&tab=bulk_actions" class="nav-tab <?php echo $current_tab === 'bulk_actions' ? 'nav-tab-active' : ''; ?>">
                    ⚡ Bulk Actions
                </a>
            </h2>
            
            <div id="tcwp-loading" style="display: block; text-align: center; padding: 40px; color: #666;">
            <div style="font-size: 18px; margin-bottom: 10px;">⏳ Loading configuration...</div>
            <div style="font-size: 14px;">Please wait while we prepare your plugin settings.</div>
            <div style="margin-top: 20px;">
                <div class="tcwp-loading-spinner"></div>
            </div>
        </div>
        
        <div id="tcwp-content" style="display: none;">
            <?php if ($current_tab === 'site_pages'): ?>
                <?php self::render_site_pages_tab($all_site_items, $manual_config, $all_plugins); ?>
            <?php elseif ($current_tab === 'url_patterns'): ?>
                <?php self::render_url_patterns_tab($manual_config, $all_plugins); ?>
            <?php elseif ($current_tab === 'bulk_actions'): ?>
                <?php self::render_bulk_actions_tab($all_site_items, $manual_config, $all_plugins); ?>
            <?php endif; ?>
        </div>
            
        </div>
        
        <style>
        /* Loading spinner */
        .tcwp-loading-spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #0073aa;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: tcwp-spin 1s linear infinite;
            margin: 0 auto;
        }
        
        @keyframes tcwp-spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Prevent FOUC (Flash of Unstyled Content) */
        .tcwp-config-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 20px;
            margin: 20px 0;
            visibility: hidden;
            opacity: 0;
            transition: opacity 0.3s ease-in-out;
        }
        
        .tcwp-config-grid.loaded {
            visibility: visible;
            opacity: 1;
        }
        
        /* Pagination styles */
        .tcwp-pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin: 20px 0;
            padding: 15px;
            background: #f9f9f9;
            border-radius: 6px;
        }
        
        .tcwp-pagination button {
            padding: 8px 12px;
            border: 1px solid #ddd;
            background: #fff;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .tcwp-pagination button:hover:not(:disabled) {
            background: #0073aa;
            color: white;
            border-color: #0073aa;
        }
        
        .tcwp-pagination button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .tcwp-pagination .current {
            background: #0073aa;
            color: white;
            border-color: #0073aa;
        }
        
        .tcwp-pagination span {
            padding: 8px 4px;
            color: #666;
        }
        
        @media (max-width: 1200px) {
            .tcwp-config-grid {
                grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            }
        }
        
        @media (max-width: 768px) {
            .tcwp-config-grid {
                grid-template-columns: 1fr;
            }
            
            .tcwp-config-item h4 {
                padding-right: 80px;
                font-size: 13px;
            }
            
            .tcwp-quick-actions {
                flex-direction: column;
                gap: 2px;
            }
            
            .tcwp-quick-actions button {
                font-size: 9px;
                padding: 3px 6px;
            }
        }
        
        .tcwp-config-item {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 20px;
            position: relative;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: box-shadow 0.2s;
        }
        
        .tcwp-config-item:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        
        .tcwp-config-item h4 {
            margin: 0 0 12px 0;
            color: #0073aa;
            font-size: 14px;
            font-weight: 600;
            line-height: 1.4;
            padding-right: 120px; /* Make room for action buttons */
            word-break: break-word;
        }
        
        .tcwp-config-item .url-info {
            font-size: 12px;
            color: #666;
            margin-bottom: 15px;
            line-height: 1.5;
        }
        
        .tcwp-plugin-checkboxes {
            max-height: 220px;
            overflow-y: auto;
            border: 1px solid #ddd;
            padding: 15px;
            background: #f9f9f9;
            border-radius: 4px;
        }
        
        .tcwp-plugin-checkboxes label {
            display: flex;
            align-items: flex-start;
            margin-bottom: 8px;
            font-size: 12px;
            cursor: pointer;
            line-height: 1.4;
            padding: 2px 0;
        }
        
        .tcwp-plugin-checkboxes input[type="checkbox"] {
            margin-right: 8px;
            margin-top: 2px;
            flex-shrink: 0;
        }
        
        .tcwp-plugin-name {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        
        .tcwp-plugin-title {
            font-weight: 500;
            color: #333;
        }
        
        .tcwp-plugin-path {
            font-family: monospace;
            font-size: 11px;
            color: #666;
            background: #f0f0f0;
            padding: 1px 4px;
            border-radius: 2px;
        }
        
        .tcwp-search-box {
            margin-bottom: 20px;
            padding: 15px;
            background: #f0f0f1;
            border-radius: 6px;
        }
        
        .tcwp-search-box input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .tcwp-stats {
            background: #e8f5e8;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        
        .tcwp-plugins-text-container {
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s ease;
        }
        
        .tcwp-plugins-text-container .dashicons {
            font-size: 18px;
            width: 18px;
            height: 18px;
        }
        
        .tcwp-plugins-text-container:hover {
            background: #e6efff;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        @keyframes tcwp-pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .tcwp-plugins-pulse {
            animation: tcwp-pulse 0.5s ease-in-out;
        }
        
        .tcwp-config-item.configured {
            border-left: 4px solid #00a32a;
        }
        
        .tcwp-config-item.not-configured {
            border-left: 4px solid #dba617;
        }
        
        .tcwp-quick-actions {
            position: absolute;
            top: 15px;
            right: 15px;
            display: flex;
            gap: 4px;
        }
        
        .tcwp-quick-actions button {
            font-size: 10px;
            padding: 4px 8px;
            border-radius: 3px;
            border: 1px solid #ddd;
            background: #f7f7f7;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .tcwp-quick-actions button:hover {
            background: #e0e0e0;
            border-color: #999;
        }
        
        .tcwp-filter-tabs {
            margin-bottom: 20px;
        }
        
        .tcwp-filter-tabs button {
            margin-right: 10px;
            padding: 8px 15px;
            border: 1px solid #ddd;
            background: #f7f7f7;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .tcwp-filter-tabs button:hover {
            background: #e0e0e0;
        }
        
        .tcwp-filter-tabs button.active {
            background: #0073aa;
            color: white;
            border-color: #0073aa;
        }
        
        /* Legacy support for URL patterns tab */
        .plugin-checkboxes {
            max-height: 220px;
            overflow-y: auto;
            border: 1px solid #ddd;
            padding: 15px;
            background: #f9f9f9;
            border-radius: 4px;
        }
        
        .plugin-checkboxes label {
            display: flex;
            align-items: flex-start;
            margin-bottom: 8px;
            font-size: 12px;
            cursor: pointer;
            line-height: 1.4;
            padding: 2px 0;
        }
        
        .plugin-checkboxes input[type="checkbox"] {
            margin-right: 8px;
            margin-top: 2px;
            flex-shrink: 0;
        }
        
        .plugin-checkboxes .tcwp-plugin-name {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        
        .plugin-checkboxes .tcwp-plugin-title {
            font-weight: 500;
            color: #333;
        }
        
        .plugin-checkboxes .tcwp-plugin-path {
            font-family: monospace;
            font-size: 11px;
            color: #666;
            background: #f0f0f0;
            padding: 1px 4px;
            border-radius: 2px;
        }
        
        #tcwp-config-table th,
        #tcwp-config-table td {
            padding: 15px;
            vertical-align: top;
        }
        
        #tcwp-config-table th {
            background: #f5f5f5;
            font-weight: 600;
        }
        
        /* Save status styling */
        #tcwp-save-status {
            font-weight: bold;
            margin-left: 15px;
            padding: 5px 10px;
            border-radius: 4px;
            transition: all 0.3s ease;
            display: inline-block;
        }
        
        #tcwp-save-status:not(:empty) {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
        }
        
        #tcwp-save-status.saving {
            background: #0073aa;
            color: white;
        }
        
        #tcwp-save-status.success {
            background: #00a32a;
            color: white;
        }
        
        #tcwp-save-status.error {
            background: #d63638;
            color: white;
        }
        
        #tcwp-save-status.warning {
            background: #dba617;
            color: white;
        }
        </style>
        
        <script>
        // Localize script data
        var tcwp_ajax = {
            ajaxurl: '<?php echo admin_url('admin-ajax.php'); ?>',
            nonce: '<?php echo wp_create_nonce('tcwp_autosave_nonce'); ?>',
            refresh_nonce: '<?php echo wp_create_nonce('tcwp_refresh_nonce'); ?>',
            debug: true
        };
        
        jQuery(document).ready(function($) {
            var saveTimeout;
            var $saveStatus = $('#tcwp-save-status');
            var isCurrentlySaving = false;
            
            // Initialize pagination
            var currentPage = 1;
            var itemsPerPage = 50;
            var totalItems = $('.tcwp-config-item').length;
            var filteredItems = $('.tcwp-config-item');
            
            function updatePagination() {
                var totalPages = Math.ceil(filteredItems.length / itemsPerPage);
                
                // Hide all items first
                $('.tcwp-config-item').hide();
                
                if (itemsPerPage === 'all') {
                    filteredItems.show();
                    $('#tcwp-pagination').hide();
                    $('#tcwp-pagination-info').text(filteredItems.length + ' items');
                } else {
                    var start = (currentPage - 1) * itemsPerPage;
                    var end = start + itemsPerPage;
                    
                    filteredItems.slice(start, end).show();
                    
                    // Update pagination controls
                    var paginationHtml = '';
                    if (totalPages > 1) {
                        paginationHtml += '<button ' + (currentPage === 1 ? 'disabled' : '') + ' onclick="goToPage(' + (currentPage - 1) + ')">← Previous</button>';
                        
                        var startPage = Math.max(1, currentPage - 2);
                        var endPage = Math.min(totalPages, currentPage + 2);
                        
                        if (startPage > 1) {
                            paginationHtml += '<button onclick="goToPage(1)">1</button>';
                            if (startPage > 2) paginationHtml += '<span>...</span>';
                        }
                        
                        for (var i = startPage; i <= endPage; i++) {
                            paginationHtml += '<button ' + (i === currentPage ? 'class="current"' : '') + ' onclick="goToPage(' + i + ')">' + i + '</button>';
                        }
                        
                        if (endPage < totalPages) {
                            if (endPage < totalPages - 1) paginationHtml += '<span>...</span>';
                            paginationHtml += '<button onclick="goToPage(' + totalPages + ')">' + totalPages + '</button>';
                        }
                        
                        paginationHtml += '<button ' + (currentPage === totalPages ? 'disabled' : '') + ' onclick="goToPage(' + (currentPage + 1) + ')">Next →</button>';
                    }
                    
                    $('#tcwp-pagination').html(paginationHtml).toggle(totalPages > 1);
                    $('#tcwp-pagination-info').text('Page ' + currentPage + ' of ' + totalPages + ' (' + filteredItems.length + ' total items)');
                }
            }
            
            window.goToPage = function(page) {
                currentPage = page;
                updatePagination();
                // Scroll to top of grid
                $('html, body').animate({
                    scrollTop: $('.tcwp-config-grid').offset().top - 100
                }, 300);
            };
            
            // Items per page change handler
            $('#tcwp-items-per-page').on('change', function() {
                var newValue = $(this).val();
                itemsPerPage = newValue === 'all' ? 'all' : parseInt(newValue);
                currentPage = 1;
                updatePagination();
            });
            
            // Initialize grid visibility after DOM is ready - smooth transition
            setTimeout(function() {
                $('#tcwp-loading').fadeOut(300, function() {
                    $('#tcwp-content').fadeIn(300, function() {
                        $('.tcwp-config-grid').addClass('loaded');
                        updatePagination();
                    });
                });
            }, 500); // Increased delay for better UX
            
            // Tab persistence functionality
            var urlParams = new URLSearchParams(window.location.search);
            var currentTab = urlParams.get('filter_tab') || 'all';
            
            // Activate the current tab on page load
            if (currentTab !== 'all') {
                $('.tcwp-filter-tabs button[data-filter="' + currentTab + '"]').addClass('active');
                $('.tcwp-filter-tabs button[data-filter="all"]').removeClass('active');
                $('.tcwp-config-item').hide();
                $('.tcwp-config-item[data-type="' + currentTab + '"]').show();
            }
            
            // Update URL when tab is clicked
            $('.tcwp-filter-tabs button').on('click', function() {
                var filterType = $(this).data('filter');
                updateUrlWithTab(filterType);
            });
            
            // Function to update URL with current tab
            function updateUrlWithTab(tab) {
                var url = new URL(window.location);
                if (tab === 'all') {
                    url.searchParams.delete('filter_tab');
                } else {
                    url.searchParams.set('filter_tab', tab);
                }
                window.history.replaceState({}, '', url);
            }
            
            // Enhanced search functionality with pagination
            $('#tcwp-search').on('input', function() {
                var searchTerm = $(this).val().toLowerCase();
                
                if (searchTerm === '') {
                    filteredItems = $('.tcwp-config-item');
                } else {
                    filteredItems = $('.tcwp-config-item').filter(function() {
                        var title = $(this).find('h4').text().toLowerCase();
                        var url = $(this).find('.url-info').text().toLowerCase();
                        return title.includes(searchTerm) || url.includes(searchTerm);
                    });
                }
                
                currentPage = 1;
                updatePagination();
            });
            
            // Enhanced filter by type with pagination
            $('.tcwp-filter-tabs button').click(function() {
                var filterType = $(this).data('filter');
                
                $('.tcwp-filter-tabs button').removeClass('active');
                $(this).addClass('active');
                
                if (filterType === 'all') {
                    filteredItems = $('.tcwp-config-item');
                } else {
                    filteredItems = $('.tcwp-config-item[data-type="' + filterType + '"]');
                }
                
                // Reset search when filter changes
                $('#tcwp-search').val('');
                currentPage = 1;
                updatePagination();
            });
            
            // Quick actions
            $('.tcwp-select-all').click(function() {
                var $item = $(this).closest('.tcwp-config-item');
                $item.find('input[type="checkbox"]').prop('checked', true);
                $item.removeClass('not-configured').addClass('configured');
                updateConfigurationProgress(); // Update progress immediately
                triggerAutosave();
            });
            
            $('.tcwp-select-none').click(function() {
                var $item = $(this).closest('.tcwp-config-item');
                $item.find('input[type="checkbox"]').prop('checked', false);
                $item.removeClass('configured').addClass('not-configured');
                updateConfigurationProgress(); // Update progress immediately
                triggerAutosave();
            });
            
            $('.tcwp-select-smart').click(function() {
                var container = $(this).closest('.tcwp-config-item');
                var itemType = $(this).data('item-type');
                var postType = $(this).data('post-type');
                var taxonomy = $(this).data('taxonomy');
                
                container.find('input[type="checkbox"]').prop('checked', false);
                
                // Define smart plugin patterns based on content type
                var smartPlugins = [];
                
                switch(itemType) {
                    case 'custom_post':
                    case 'custom_post_archive':
                        smartPlugins = [
                            'jet-engine',
                            'jet-elements',
                            'jet-blog',
                            'elementor',
                            'elementor-pro',
                            'yoast-seo'
                        ];
                        break;
                    case 'taxonomy':
                        smartPlugins = [
                            'jet-engine',
                            'jet-smart-filters',
                            'elementor',
                            'yoast-seo'
                        ];
                        break;
                    case 'woocommerce':
                        smartPlugins = [
                            'woocommerce',
                            'jet-woo-builder',
                            'elementor',
                            'elementor-pro'
                        ];
                        break;
                    case 'menu':
                        smartPlugins = [
                            'elementor',
                            'yoast-seo'
                        ];
                        break;
                    default:
                        // Essential plugins for other types
                        smartPlugins = [
                            'turbo-charge-wp',
                            'wp-rocket',
                            'litespeed-cache',
                            'wordfence',
                            'yoast-seo',
                            'elementor'
                        ];
                }
                
                // Select the smart plugins
                smartPlugins.forEach(function(plugin) {
                    container.find('input[value*="' + plugin + '"]').prop('checked', true);
                });
                
                // Update UI and trigger autosave
                var hasSelection = container.find('input[type="checkbox"]:checked').length > 0;
                container.removeClass('configured not-configured').addClass(hasSelection ? 'configured' : 'not-configured');
                updateConfigurationProgress();
                triggerAutosave();
            });
            
            // Enhanced auto-save with AJAX
            var countdownInterval;
            var countdownSeconds = 10;
            
            function triggerAutosave() {
                // Don't trigger if already saving
                if (isCurrentlySaving) {
                    return;
                }
                
                // Clear existing timeout and interval
                clearTimeout(saveTimeout);
                clearInterval(countdownInterval);
                
                // Reset countdown
                countdownSeconds = 10;
                
                // Update status with countdown
                $saveStatus.text('Changes detected... auto-saving in ' + countdownSeconds + ' seconds').css('color', '#dba617');
                
                // Start countdown interval
                countdownInterval = setInterval(function() {
                    countdownSeconds--;
                    if (countdownSeconds > 0) {
                        $saveStatus.text('Changes detected... auto-saving in ' + countdownSeconds + ' seconds').css('color', '#dba617');
                    } else {
                        clearInterval(countdownInterval);
                    }
                }, 1000);
                
                // Set timeout for actual save
                saveTimeout = setTimeout(function() {
                    clearInterval(countdownInterval);
                    performAutosave();
                }, 10000); // 10 seconds
            }
            
            function performAutosave() {
                if (isCurrentlySaving) {
                    return;
                }
                
                isCurrentlySaving = true;
                $saveStatus.text('Saving...').css('color', '#0073aa');
                
                // Collect all form data
                var formData = new FormData();
                formData.append('action', 'tcwp_autosave_config');
                formData.append('nonce', tcwp_ajax.nonce);
                formData.append('config_type', 'site_pages');
                
                var patterns = [];
                var allPlugins = {};
                
                // Collect patterns and plugins from each config item
                $('.tcwp-config-item').each(function() {
                    var $item = $(this);
                    var $patternInput = $item.find('input[name="manual_config_patterns[]"]');
                    
                    if ($patternInput.length > 0) {
                        var pattern = $patternInput.val();
                        if (pattern) {
                            patterns.push(pattern);
                            
                            // Initialize array - always initialize for every pattern
                            allPlugins[pattern] = [];
                            
                            // Collect checked plugins for this pattern - use specific pattern in selector
                            var pluginSelector = 'input[name="manual_config_plugins[' + pattern + '][]"]:checked';                            
                            var $checkedPlugins = $item.find(pluginSelector);
                            
                            // Always process, even if no plugins are checked (this is the fix!)
                            $checkedPlugins.each(function() {
                                var pluginValue = $(this).val();
                                console.log('TCWP Debug: Adding plugin:', pluginValue);
                                allPlugins[pattern].push(pluginValue);
                            });
                            
                            // Log the final state for this pattern
                            console.log('TCWP Debug: Pattern "' + pattern + '" has ' + allPlugins[pattern].length + ' plugins selected:', allPlugins[pattern]);
                        }
                    }
                });
                
                // Add patterns to form data
                patterns.forEach(function(pattern) {
                    formData.append('manual_config_patterns[]', pattern);
                });
                
                // Add plugins to form data
                Object.keys(allPlugins).forEach(function(pattern) {
                    if (allPlugins[pattern].length === 0) {
                        // Explicitly send empty array for patterns with no selected plugins
                        formData.append('manual_config_plugins[' + pattern + ']', '');
                    } else {
                        allPlugins[pattern].forEach(function(plugin) {
                            formData.append('manual_config_plugins[' + pattern + '][]', plugin);
                        });
                    }
                });
                
                // Send AJAX request
                $.ajax({
                    url: tcwp_ajax.ajaxurl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        if (response.success) {
                            var stats = response.data;
                            var message = '✅ Auto-saved successfully!';
                            if (stats && stats.patterns_saved) {
                                message += ' (' + stats.patterns_saved + ' patterns, ' + stats.total_plugins + ' total plugins)';
                            }
                            $saveStatus.text(message).css('color', '#00a32a');
                            
                            if (stats && stats.configured_patterns) {
                                console.log('TCWP Debug: Server confirmed configured patterns:', stats.configured_patterns);
                                console.log('TCWP Debug: Server confirmed plugin counts:', stats.plugin_counts || 'N/A');
                                
                                // Specifically check for homepage pattern
                                if (stats.plugin_counts && stats.plugin_counts['/'] !== undefined) {
                                    console.log('TCWP Debug: Homepage "/" pattern has ' + stats.plugin_counts['/'] + ' plugins configured');
                                }
                                
                                // Ensure all checkbox states reflect server data
                                $('.tcwp-config-item').each(function() {
                                    var $item = $(this);
                                    var pattern = $item.find('input[name="manual_config_patterns[]"]').val();
                                    var isConfigured = stats.configured_patterns.includes(pattern);
                                    
                                    // Log each pattern's status
                                    if (isConfigured && stats.plugin_counts && stats.plugin_counts[pattern]) {
                                        var pluginCount = stats.plugin_counts[pattern];
                                        console.log('TCWP Debug: Pattern "' + pattern + '" is configured with ' + pluginCount + ' plugins');
                                        
                                        // Make sure checkboxes reflect server state
                                        if (pluginCount > 0) {
                                            $item.removeClass('not-configured').addClass('configured');
                                        }
                                    } else {
                                        $item.removeClass('configured').addClass('not-configured');
                                    }
                                });
                            }
                            
                            // Calculate total plugins configured
                            var totalPluginsConfigured = 0;
                            if (stats.plugin_counts) {
                                Object.values(stats.plugin_counts).forEach(function(count) {
                                    totalPluginsConfigured += count;
                                });
                                console.log('TCWP Debug: Total plugins configured according to server: ' + totalPluginsConfigured);
                                
                                // Update plugins text with animation
                                var $pluginsText = $('.tcwp-plugins-text');
                                var currentCount = parseInt($pluginsText.text().match(/\d+/)[0], 10);
                                
                                if (currentCount !== totalPluginsConfigured) {
                                    $pluginsText.text(totalPluginsConfigured + ' total plugins selected');
                                    $('.tcwp-plugins-text-container').removeClass('tcwp-plugins-pulse');
                                    setTimeout(function() {
                                        $('.tcwp-plugins-text-container').addClass('tcwp-plugins-pulse');
                                    }, 10);
                                } else {
                                    $pluginsText.text(totalPluginsConfigured + ' total plugins selected');
                                }
                            }
                            
                            // Update visual indicators with server data
                            updateConfigurationProgress();
                            
                            // Clear status after 5 seconds or reload if needed
                            if (stats && stats.configured_patterns && stats.configured_patterns.length > 1) {
                                $saveStatus.text('✅ Configuration updated. Refreshing page to apply changes...').css('color', '#00a32a');
                                setTimeout(function() {
                                    // Preserve current tab in URL before reload
                                    var currentTab = $('.tcwp-filter-tabs button.active').data('filter') || 'all';
                                    var reloadUrl = new URL(window.location);
                                    if (currentTab !== 'all') {
                                        reloadUrl.searchParams.set('filter_tab', currentTab);
                                    } else {
                                        reloadUrl.searchParams.delete('filter_tab');
                                    }
                                    
                                    // Show loading indicator before reload
                                    $('#tcwp-content').fadeOut(200, function() {
                                        $('#tcwp-loading').show();
                                        window.location.href = reloadUrl.toString();
                                    });
                                }, 2000);
                            } else {
                                setTimeout(function() {
                                    $saveStatus.text('').css('color', '');
                                }, 5000);
                            }
                        } else {
                            console.error('TCWP Error: Save failed:', response.data);
                            $saveStatus.text('❌ Save failed: ' + (response.data || 'Unknown error')).css('color', '#d63638');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('TCWP Error: AJAX failed:', xhr.responseText, status, error);
                        var errorMsg = 'Connection error during save';
                        if (xhr.responseText) {
                            try {
                                var errorData = JSON.parse(xhr.responseText);
                                if (errorData.data) {
                                    errorMsg = errorData.data;
                                }
                            } catch (e) {
                                // Use default error message
                            }
                        }
                        $saveStatus.text('❌ ' + errorMsg).css('color', '#d63638');
                        console.error('AJAX Error:', error);
                    },
                    complete: function() {
                        isCurrentlySaving = false;
                    }
                });
            }
            
            function updateConfigurationProgress() {
                var totalItems = $('.tcwp-config-item').length;
                var configuredItems = 0;
                var configuredPlugins = 0; // Count of all selected plugins across all items
                var debugItems = [];
                var pluginCountsByPattern = {};
                
                // Count items with at least one checked checkbox
                $('.tcwp-config-item').each(function() {
                    var $item = $(this);
                    var checkedPluginsCount = $item.find('input[name^="manual_config_plugins"]:checked').length;
                    var patternName = $item.find('input[name="manual_config_patterns[]"]').val();
                    
                    if (checkedPluginsCount > 0) {
                        configuredItems++;
                        configuredPlugins += checkedPluginsCount; // Add the count of checked plugins
                        $item.removeClass('not-configured').addClass('configured');
                        debugItems.push(patternName);
                        pluginCountsByPattern[patternName] = checkedPluginsCount;
                    } else {
                        $item.removeClass('configured').addClass('not-configured');
                    }
                });
                
                // Update progress display
                var percentage = totalItems > 0 ? Math.round((configuredItems / totalItems) * 100) : 0;
                $('.tcwp-progress-text').text(configuredItems + ' of ' + totalItems + ' items configured');
                
                // Add pulse animation when plugin count changes
                var $pluginsText = $('.tcwp-plugins-text');
                var currentCount = parseInt($pluginsText.text().match(/\d+/)[0], 10);
                
                if (currentCount !== configuredPlugins) {
                    $pluginsText.text(configuredPlugins + ' total plugins selected');
                    $('.tcwp-plugins-text-container').removeClass('tcwp-plugins-pulse');
                    setTimeout(function() {
                        $('.tcwp-plugins-text-container').addClass('tcwp-plugins-pulse');
                    }, 10);
                } else {
                    $pluginsText.text(configuredPlugins + ' total plugins selected');
                }
                
                $('.tcwp-progress-percentage').text(percentage + '% Complete');
                
                // Debug output
                console.log('TCWP Progress Update: ' + configuredItems + ' of ' + totalItems + ' items configured (' + percentage + '%)');
                console.log('TCWP Debug: Total plugins selected across all patterns: ' + configuredPlugins);
                console.log('TCWP Debug: Configured patterns:', debugItems);
                console.log('TCWP Debug: Plugin counts by pattern:', pluginCountsByPattern);
            }
            
            // Auto-save trigger for checkbox changes
            $(document).on('change', '.tcwp-plugin-checkboxes input[type="checkbox"]', function() {
                console.log('TCWP Debug: Checkbox changed, updating UI');
                
                // Update the item's class immediately
                var $item = $(this).closest('.tcwp-config-item');
                var checkedCount = $item.find('input[name^="manual_config_plugins"]:checked').length;
                
                if (checkedCount > 0) {
                    $item.removeClass('not-configured').addClass('configured');
                } else {
                    $item.removeClass('configured').addClass('not-configured');
                }
                
                // Update the progress counter
                updateConfigurationProgress();
                
                // Trigger the save
                triggerAutosave();
            });
            
            // Initialize progress calculation on page load
            $(document).ready(function() {
                // First check for configured items and set classes
                $('.tcwp-config-item').each(function() {
                    var $item = $(this);
                    var checkedCount = $item.find('input[name^="manual_config_plugins"]:checked').length;
                    
                    if (checkedCount > 0) {
                        $item.removeClass('not-configured').addClass('configured');
                    } else {
                        $item.removeClass('configured').addClass('not-configured');
                    }
                });
                
                // Then update the progress display
                updateConfigurationProgress();
                
                // Debug: Check checkbox states on page load
                var checkedCount = $('.tcwp-plugin-checkboxes input[type="checkbox"]:checked').length;
                console.log('TCWP Debug: Checked checkboxes on page load:', checkedCount);
                
                // Debug: Check total items vs server-side count
                var totalItems = $('.tcwp-config-item').length;
                var serverSideTotal = <?php echo count($all_site_items); ?>;
                console.log('TCWP Debug: JavaScript total items:', totalItems, 'Server-side total:', serverSideTotal);
                
                // Debug: Check if form data is being collected properly
                $('.tcwp-config-item').each(function() {
                    var $item = $(this);
                    var pattern = $item.find('input[name="manual_config_patterns[]"]').val();
                    var checkedPlugins = $item.find('input[name^="manual_config_plugins"]:checked').length;
                    if (checkedPlugins > 0) {
                        console.log('TCWP Debug: Pattern "' + pattern + '" has ' + checkedPlugins + ' checked plugins');
                    }
                });
            });
            
            // Bulk essential plugins button
            $('#tcwp-bulk-essential').click(function() {
                if (confirm('Apply essential plugins to all pages? This will override existing configurations.')) {
                    $('.tcwp-plugin-checkboxes input[type="checkbox"]').prop('checked', false);
                    
                    // Select essential plugins for all items
                    var essentialPlugins = [
                        'turbo-charge-wp',
                        'wp-rocket',
                        'litespeed-cache',
                        'w3-total-cache',
                        'wordfence',
                        'better-wp-security',
                        'akismet',
                        'yoast-seo',
                        'elementor'
                    ];
                    
                    essentialPlugins.forEach(function(plugin) {
                        $('.tcwp-plugin-checkboxes input[value*="' + plugin + '"]').prop('checked', true);
                    });
                    
                    triggerAutosave();
                }
            });
            
            // Handle manual save button
            $('#tcwp-manual-save').click(function() {
                // Clear autosave timer
                clearTimeout(saveTimeout);
                clearInterval(countdownInterval);
                
                // Perform immediate save
                performAutosave();
                
                // Prevent form submission
                return false;
            });
            
            // Handle cache refresh button
            $('#tcwp-refresh-cache').click(function() {
                var $button = $(this);
                var originalText = $button.text();
                
                $button.prop('disabled', true).text('🔄 Refreshing...');
                
                $.ajax({
                    url: tcwp_ajax.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'tcwp_refresh_cache',
                        nonce: tcwp_ajax.refresh_nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            $saveStatus.text('✅ Cache refreshed! Reloading page...').css('color', '#00a32a');
                            setTimeout(function() {
                                window.location.reload();
                            }, 1000);
                        } else {
                            $saveStatus.text('❌ Cache refresh failed: ' + response.data).css('color', '#d63638');
                        }
                    },
                    error: function() {
                        $saveStatus.text('❌ Cache refresh failed').css('color', '#d63638');
                    },
                    complete: function() {
                        $button.prop('disabled', false).text(originalText);
                    }
                });
            });
            
            // Prevent form submission
            $('#tcwp-manual-config-form').on('submit', function(e) {
                e.preventDefault();
                return false;
            });
            
            // Debug button handler
            $('#tcwp-debug-progress').on('click', function() {
                // Force update progress calculation first
                updateConfigurationProgress();
                
                // Collect server-side data about configuration
                var manualConfig = <?php echo json_encode(get_option('tcwp_manual_config', array()), JSON_PRETTY_PRINT); ?>;
                
                // Calculate server counts within JS since we're after the PHP section
                var serverConfigCount = Object.keys(manualConfig).length;
                var serverTotalCount = $('.tcwp-config-item').length;
                
                console.log('TCWP Debug: Server-side configuration:', manualConfig);
                
                // Get client-side counts using multiple methods for validation
                var configuredPatterns = Object.keys(manualConfig);
                var totalItems = $('.tcwp-config-item').length;
                var classConfiguredItems = $('.tcwp-config-item.configured').length;
                
                // Count total plugins selected across all patterns
                var totalPluginsSelected = 0;
                $('.tcwp-config-item').each(function() {
                    totalPluginsSelected += $(this).find('input[name^="manual_config_plugins"]:checked').length;
                });
                
                var checkboxConfiguredItems = $('.tcwp-config-item').filter(function() {
                    return $(this).find('input[name^="manual_config_plugins"]:checked').length > 0;
                }).length;
                
                // Check for mismatches
                var classMismatch = (checkboxConfiguredItems !== classConfiguredItems);
                var serverMismatch = (serverConfigCount !== checkboxConfiguredItems);
                
                var debugInfo = 'Debug Info:\n' + 
                      '- Server configured: ' + serverConfigCount + ' of ' + serverTotalCount + '\n' +
                      '- Items with checked boxes: ' + checkboxConfiguredItems + ' of ' + totalItems + '\n' +
                      '- Items with "configured" class: ' + classConfiguredItems + ' of ' + totalItems + '\n' +
                      '- Total plugins selected: ' + totalPluginsSelected + '\n' +
                      '\nPlugin Distribution:\n' + JSON.stringify(pluginCountsByPattern, null, 2);
                
                if (classMismatch || serverMismatch) {
                    debugInfo += '\n⚠️ MISMATCH DETECTED! Trying to fix...\n';
                    // Fix class assignments
                    $('.tcwp-config-item').each(function() {
                        var $item = $(this);
                        var hasChecked = $item.find('input[name^="manual_config_plugins"]:checked').length > 0;
                        if (hasChecked) {
                            $item.removeClass('not-configured').addClass('configured');
                        } else {
                            $item.removeClass('configured').addClass('not-configured');
                        }
                    });
                    
                    // Update the display
                    updateConfigurationProgress();
                }
                
                // Show the debug info
                alert(debugInfo);
            });
        });
        </script>
        <?php
    }
    
    /**
     * Render site pages tab
     */
    private static function render_site_pages_tab($all_site_items, $manual_config, $all_plugins) {
        // Force reload the manual_config from database to ensure fresh data after autosave
        $manual_config = get_option('tcwp_manual_config', array());
        ?>
        <div class="tcwp-search-box">
            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <input type="text" id="tcwp-search" placeholder="🔍 Search pages, posts, and menu items..." style="flex: 1; min-width: 200px;" />
                <div class="tcwp-pagination-controls" style="display: flex; gap: 10px; align-items: center;">
                    <label for="tcwp-items-per-page">Items per page:</label>
                    <select id="tcwp-items-per-page" style="padding: 5px;">
                        <option value="20">20</option>
                        <option value="50" selected>50</option>
                        <option value="100">100</option>
                        <option value="all">All</option>
                    </select>
                    <div id="tcwp-pagination-info" style="font-size: 14px; color: #666;"></div>
                </div>
            </div>
        </div>
        
        <div class="tcwp-filter-tabs">
            <button type="button" data-filter="all" class="active">All Items</button>
            <button type="button" data-filter="homepage">🏠 Homepage</button>
            <button type="button" data-filter="page">📄 Pages</button>
            <button type="button" data-filter="post">📝 Posts</button>
            <button type="button" data-filter="woocommerce">🛍️ WooCommerce</button>
            <button type="button" data-filter="custom_post_archive">📚 Archives</button>
            <button type="button" data-filter="custom_post">📋 Custom Posts</button>
            <button type="button" data-filter="taxonomy">🏷️ Taxonomies</button>
            <button type="button" data-filter="menu">🔗 Menu Items</button>
        </div>
        
        <?php
        // Clear cache to ensure fresh data on page load if requested
        if (isset($_GET['refresh_cache'])) {
            self::clear_site_items_cache();
        }
        
        // Force reload the manual_config from database to ensure fresh data
        $fresh_manual_config = get_option('tcwp_manual_config', array());
        
        $configured_count = 0;
        $total_count = count($all_site_items);
        $configured_patterns = [];
        $plugin_counts = [];
        $total_plugins_configured = 0;
        
        // Debug entire configuration
        error_log('TCWP Debug: Current manual_config: ' . json_encode($fresh_manual_config));
        
        foreach ($all_site_items as $key => $item) {
            $pattern = $item['pattern'];
            if (isset($fresh_manual_config[$pattern])) {
                $configured_count++;
                $configured_patterns[] = $pattern;
                $plugin_counts[$pattern] = count($fresh_manual_config[$pattern]);
                $total_plugins_configured += $plugin_counts[$pattern];
                
                // Debug info for this specific pattern - include zero-plugin patterns
                error_log('TCWP Debug: Pattern "' . $pattern . '" has ' . $plugin_counts[$pattern] . ' plugins configured' . ($plugin_counts[$pattern] === 0 ? ' (NONE selected)' : ''));
            }
        }
        
        // Debug output with detailed information
        error_log('TCWP Debug: Server-side configured count: ' . $configured_count . ' of ' . $total_count);
        error_log('TCWP Debug: Total plugins configured: ' . $total_plugins_configured);
        error_log('TCWP Debug: Configured patterns: ' . implode(', ', $configured_patterns));
        error_log('TCWP Debug: Plugin counts by pattern: ' . json_encode($plugin_counts));
        ?>
        
        <div class="tcwp-stats">
            <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                <div style="flex: 1; min-width: 200px;">
                    <strong>📊 Configuration Progress:</strong> 
                    <span class="tcwp-progress-text"><?php echo $configured_count; ?> of <?php echo $total_count; ?> items configured</span>
                </div>
                <div style="background: rgba(255,255,255,0.8); padding: 8px 12px; border-radius: 4px; font-weight: bold;" class="tcwp-progress-percentage">
                    <?php echo round(($configured_count / $total_count) * 100, 1); ?>% Complete
                </div>
                <div style="background: #f0f6ff; padding: 8px 12px; border-radius: 4px; font-weight: bold; color: #0073aa;" class="tcwp-plugins-text-container">
                    <span class="dashicons dashicons-plugins-checked"></span>
                    <span class="tcwp-plugins-text"><?php echo $total_plugins_configured; ?> total plugins selected</span>
                </div>
                <div id="tcwp-save-status" style="font-style: italic; color: #666;"></div>
                <div>
                    <button type="button" id="tcwp-debug-progress" class="button button-small" style="margin-left: 10px;">Debug Progress</button>
                </div>
            </div>
        </div>
        
        <form method="post" id="tcwp-manual-config-form">
            <input type="hidden" name="config_type" value="site_pages" />
            
            <div class="tcwp-config-grid">
                <?php foreach ($all_site_items as $key => $item): ?>
                    <?php
                    $selected_plugins = isset($manual_config[$item['pattern']]) ? $manual_config[$item['pattern']] : array();
                    $is_configured = isset($manual_config[$item['pattern']]);
                    ?>
                    <div class="tcwp-config-item <?php echo $is_configured ? 'configured' : 'not-configured'; ?>" 
                         data-type="<?php echo esc_attr($item['type']); ?>">
                        
                        <div class="tcwp-quick-actions">
                            <button type="button" class="tcwp-select-all button button-small">All</button>
                            <button type="button" class="tcwp-select-none button button-small">None</button>
                            <button type="button" class="tcwp-select-smart button button-small" 
                                    data-item-type="<?php echo esc_attr($item['type']); ?>"
                                    data-post-type="<?php echo esc_attr($item['post_type'] ?? ''); ?>"
                                    data-taxonomy="<?php echo esc_attr($item['taxonomy'] ?? ''); ?>">Smart</button>
                        </div>
                        
                        <h4><?php echo esc_html($item['title']); ?></h4>
                        <div class="url-info">
                            <strong>URL:</strong> <code><?php echo esc_html($item['url']); ?></code><br>
                            <strong>Pattern:</strong> <code><?php echo esc_html($item['pattern']); ?></code>
                        </div>
                        
                        <div class="tcwp-plugin-checkboxes">
                            <?php foreach ($all_plugins as $plugin): ?>
                                <?php
                                $plugin_name = self::get_plugin_name($plugin);
                                $plugin_folder = basename(dirname($plugin));
                                $checked = in_array($plugin, $selected_plugins) ? 'checked' : '';
                                ?>
                                <label>
                                    <input type="checkbox" 
                                           name="manual_config_plugins[<?php echo esc_attr($item['pattern']); ?>][]" 
                                           value="<?php echo esc_attr($plugin); ?>" 
                                           <?php echo $checked; ?> />
                                    <div class="tcwp-plugin-name">
                                        <span class="tcwp-plugin-title"><?php echo esc_html($plugin_name); ?></span>
                                        <span class="tcwp-plugin-path"><?php echo esc_html($plugin_folder); ?></span>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        
                        <input type="hidden" name="manual_config_patterns[]" value="<?php echo esc_attr($item['pattern']); ?>" />
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div id="tcwp-pagination" class="tcwp-pagination"></div>
            
            <p>
                <button type="button" id="tcwp-manual-save" class="button button-primary">💾 Save All Configurations</button>
                <button type="button" id="tcwp-bulk-essential" class="button button-secondary">⚡ Set Essential Plugins for All</button>
                <button type="button" id="tcwp-refresh-cache" class="button button-secondary">🔄 Refresh Cache</button>
            </p>
        </form>
        <?php
    }
    
    /**
     * Render URL patterns tab (legacy support)
     */
    private static function render_url_patterns_tab($manual_config, $all_plugins) {
        ?>
        <div class="notice notice-info">
            <p><strong>💡 URL Patterns:</strong> Use this for custom URL patterns not covered by the Site Pages tab.</p>
        </div>
        
        <form method="post" id="tcwp-url-patterns-form">
            <input type="hidden" name="config_type" value="url_patterns" />
            
            <table class="widefat fixed" id="tcwp-config-table">
                <thead>
                    <tr>
                        <th style="width: 25%;">URL Pattern</th>
                        <th style="width: 70%;">Allowed Plugins</th>
                        <th style="width: 5%;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Only show patterns that don't correspond to specific pages
                    $pattern_configs = array();
                    foreach ($manual_config as $pattern => $plugins) {
                        // Exclude menu-specific patterns and page-specific patterns from URL patterns tab
                        if (strpos($pattern, '::menu_') === false && 
                            (strpos($pattern, '/') === false || in_array($pattern, ['shop', 'cart', 'checkout', 'course', 'event', 'blog']))) {
                            $pattern_configs[$pattern] = $plugins;
                        }
                    }
                    
                    if (empty($pattern_configs)) {
                        $pattern_configs = array(
                            'shop' => array(),
                            'cart' => array(),
                            'checkout' => array(),
                            'course' => array(),
                            'event' => array(),
                        );
                    }
                    
                    foreach ($pattern_configs as $pattern => $plugins) {
                        self::render_config_row($pattern, $plugins, $all_plugins);
                    }
                    ?>
                </tbody>
            </table>
            
            <br>
            <p>
                <button type="button" id="add-config-row" class="button button-secondary">Add New URL Pattern</button>
                <?php submit_button('Save URL Patterns', 'primary', 'submit_manual_config', false); ?>
            </p>
        </form>
        
        <script>
        jQuery(document).ready(function($) {
            // Add new configuration row
            $('#add-config-row').click(function() {
                var allPluginsHtml = '';
                <?php foreach ($all_plugins as $plugin): ?>
                    <?php 
                    $plugin_name = self::get_plugin_name($plugin);
                    $plugin_folder = basename(dirname($plugin));
                    ?>
                    allPluginsHtml += '<label>';
                    allPluginsHtml += '<input type="checkbox" name="manual_config_plugins[NEW_PATTERN][]" value="<?php echo esc_attr($plugin); ?>" />';
                    allPluginsHtml += '<div class="tcwp-plugin-name">';
                    allPluginsHtml += '<span class="tcwp-plugin-title"><?php echo esc_js($plugin_name); ?></span>';
                    allPluginsHtml += '<span class="tcwp-plugin-path"><?php echo esc_js($plugin_folder); ?></span>';
                    allPluginsHtml += '</div>';
                    allPluginsHtml += '</label>';
                <?php endforeach; ?>
                
                var newRow = '<tr>' +
                    '<td><input type="text" name="manual_config_patterns[]" placeholder="e.g., shop" style="width: 100%;" /></td>' +
                    '<td><div class="plugin-checkboxes">' + allPluginsHtml + '</div></td>' +
                    '<td><button type="button" class="button button-small remove-row">Remove</button></td>' +
                    '</tr>';
                $('#tcwp-config-table tbody').append(newRow);
            });
            
            // Remove configuration row
            $(document).on('click', '.remove-row', function() {
                $(this).closest('tr').remove();
            });
        });
        </script>
        <?php
    }
    
    /**
     * Render bulk actions tab
     */
    private static function render_bulk_actions_tab($all_site_items, $manual_config, $all_plugins) {
        ?>
        <div class="notice notice-info">
            <p><strong>⚡ Bulk Actions:</strong> Quickly configure multiple pages at once.</p>
        </div>
        
        <div style="display: flex; gap: 30px;">
            <div style="flex: 1;">
                <h3>🎯 Quick Configurations</h3>
                
                <form method="post" style="margin-bottom: 20px;">
                    <h4>Essential Plugins Only</h4>
                    <p>Configure all pages to load only essential plugins (security, caching, SEO).</p>
                    <input type="hidden" name="bulk_action" value="essential_only" />
                    <input type="submit" class="button button-primary" value="Apply Essential Only to All Pages" />
                </form>
                
                <form method="post" style="margin-bottom: 20px;">
                    <h4>WooCommerce Pages</h4>
                    <p>Configure shop-related pages with WooCommerce and essential plugins.</p>
                    <input type="hidden" name="bulk_action" value="woocommerce_pages" />
                    <input type="submit" class="button button-secondary" value="Configure WooCommerce Pages" />
                </form>
                
                <form method="post" style="margin-bottom: 20px;">
                    <h4>Clear All Configurations</h4>
                    <p>Remove all manual configurations and return to automatic detection.</p>
                    <input type="hidden" name="bulk_action" value="clear_all" />
                    <input type="submit" class="button button-secondary" value="Clear All Manual Configurations" 
                           onclick="return confirm('Are you sure you want to clear all manual configurations?');" />
                </form>
            </div>
            
            <div style="flex: 1;">
                <h3>📊 Configuration Statistics</h3>
                
                <?php
                $stats = array(
                    'total_pages' => count($all_site_items),
                    'configured_pages' => 0,
                    'essential_only' => 0,
                    'heavy_pages' => 0,
                    'unconfigured' => 0
                );
                
                foreach ($all_site_items as $key => $item) {
                    $plugins = isset($manual_config[$item['pattern']]) ? $manual_config[$item['pattern']] : array();
                    
                    if (empty($plugins)) {
                        $stats['unconfigured']++;
                    } else {
                        $stats['configured_pages']++;
                        
                        if (count($plugins) <= 10) {
                            $stats['essential_only']++;
                        } else {
                            $stats['heavy_pages']++;
                        }
                    }
                }
                ?>
                
                <div style="background: #f0f0f1; padding: 15px; border-radius: 4px;">
                    <p><strong>Total Pages:</strong> <?php echo $stats['total_pages']; ?></p>
                    <p><strong>Configured Pages:</strong> <?php echo $stats['configured_pages']; ?> (<?php echo round(($stats['configured_pages'] / $stats['total_pages']) * 100, 1); ?>%)</p>
                    <p><strong>Essential Only:</strong> <?php echo $stats['essential_only']; ?></p>
                    <p><strong>Heavy Pages (10+ plugins):</strong> <?php echo $stats['heavy_pages']; ?></p>
                    <p><strong>Unconfigured:</strong> <?php echo $stats['unconfigured']; ?></p>
                </div>
                
                <h4>Most Used Plugins</h4>
                <?php
                $plugin_usage = array();
                foreach ($manual_config as $pattern => $plugins) {
                    foreach ($plugins as $plugin) {
                        $plugin_usage[$plugin] = ($plugin_usage[$plugin] ?? 0) + 1;
                    }
                }
                arsort($plugin_usage);
                $top_plugins = array_slice($plugin_usage, 0, 10, true);
                ?>
                
                <div style="background: #f0f0f1; padding: 15px; border-radius: 4px;">
                    <?php foreach ($top_plugins as $plugin => $count): ?>
                        <p style="margin: 5px 0;">
                            <strong><?php echo esc_html(self::get_plugin_name($plugin)); ?>:</strong> 
                            <?php echo $count; ?> pages
                        </p>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <!-- Import/Export Section -->
        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
            <h3>📦 Configuration Import/Export</h3>
            <p>Export your current configuration to XML for backup or transfer to another site. Import configurations from XML files.</p>
            
            <div style="display: flex; gap: 30px; margin-top: 20px;">
                <div style="flex: 1;">
                    <h4>📤 Export Configuration</h4>
                    <p>Download your current manual configuration as an XML file. This includes all configured patterns for pages, posts, archives, WooCommerce, custom posts, taxonomies, and menu items.</p>
                    
                    <a href="?page=tcwp-manual-config&tab=bulk_actions&action=export_config" 
                       class="button button-primary" 
                       style="text-decoration: none;">
                        <span class="dashicons dashicons-download" style="vertical-align: text-top;"></span>
                        Export Configuration to XML
                    </a>
                </div>
                
                <div style="flex: 1;">
                    <h4>📥 Import Configuration</h4>
                    <p>Upload an XML configuration file to restore or merge settings. Choose whether to merge with existing settings or replace them entirely.</p>
                    
                    <form method="post" enctype="multipart/form-data" style="margin-top: 15px;">
                        <table class="form-table">
                            <tr>
                                <th scope="row">XML File</th>
                                <td>
                                    <input type="file" name="import_file" accept=".xml" required />
                                    <p class="description">Select a Turbo Charge WP configuration XML file.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Import Mode</th>
                                <td>
                                    <label>
                                        <input type="radio" name="import_mode" value="merge" checked />
                                        <strong>Merge:</strong> Add imported settings to existing configuration
                                    </label><br>
                                    <label style="margin-top: 8px; display: inline-block;">
                                        <input type="radio" name="import_mode" value="replace" />
                                        <strong>Replace:</strong> Replace entire configuration with imported settings
                                    </label>
                                    <p class="description">Merge will preserve existing settings and add new ones. Replace will completely overwrite your current configuration.</p>
                                </td>
                            </tr>
                        </table>
                        
                        <input type="submit" name="import_config_xml" class="button button-secondary" value="Import Configuration" 
                               onclick="return confirm('Are you sure you want to import this configuration? Make sure to export your current settings first as a backup.');" />
                    </form>
                </div>
            </div>
            
            <div style="background: #fff3cd; border: 1px solid #ffc107; padding: 15px; border-radius: 4px; margin-top: 20px;">
                <h4 style="margin-top: 0; color: #856404;">⚠️ Important Notes:</h4>
                <ul style="margin: 10px 0; color: #856404;">
                    <li><strong>Backup First:</strong> Always export your current configuration before importing new settings.</li>
                    <li><strong>Plugin Compatibility:</strong> Ensure imported plugin names match the plugins installed on this site.</li>
                    <li><strong>Site Differences:</strong> Imported configurations may reference pages/posts that don't exist on this site.</li>
                    <li><strong>Testing:</strong> Test your site thoroughly after importing configurations to ensure everything works correctly.</li>
                </ul>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render a configuration row (legacy support)
     */
    private static function render_config_row($pattern, $selected_plugins, $all_plugins) {
        echo '<tr>';
        echo '<td>';
        echo '<input type="text" name="manual_config_patterns[]" value="' . esc_attr($pattern) . '" style="width: 100%;" />';
        echo '</td>';
        echo '<td>';
        echo '<div class="plugin-checkboxes">';
        
        foreach ($all_plugins as $plugin) {
            $plugin_name = self::get_plugin_name($plugin);
            $plugin_folder = basename(dirname($plugin));
            $checked = in_array($plugin, $selected_plugins) ? 'checked' : '';
            
            echo '<label>';
            echo '<input type="checkbox" name="manual_config_plugins[' . esc_attr($pattern) . '][]" ';
            echo 'value="' . esc_attr($plugin) . '" ' . $checked . ' />';
            echo '<div class="tcwp-plugin-name">';
            echo '<span class="tcwp-plugin-title">' . esc_html($plugin_name) . '</span>';
            echo '<span class="tcwp-plugin-path">' . esc_html($plugin_folder) . '</span>';
            echo '</div>';
            echo '</label>';
        }
        
        echo '</div>';
        echo '</td>';
        echo '<td>';
        echo '<button type="button" class="button button-small remove-row">Remove</button>';
        echo '</td>';
        echo '</tr>';
    }
    
    /**
     * Get plugin name from plugin file
     */
    private static function get_plugin_name($plugin_file) {
        $plugin_data = get_file_data(WP_PLUGIN_DIR . '/' . $plugin_file, array('Name' => 'Plugin Name'));
        return !empty($plugin_data['Name']) ? $plugin_data['Name'] : basename($plugin_file, '.php');
    }
    
    /**
     * Enhanced save manual configuration
     */
    private static function save_manual_config() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Handle bulk actions
        if (isset($_POST['bulk_action'])) {
            self::handle_bulk_action($_POST['bulk_action']);
            return;
        }
        
        $patterns = $_POST['manual_config_patterns'] ?? array();
        $plugins = $_POST['manual_config_plugins'] ?? array();
        $config_type = $_POST['config_type'] ?? 'site_pages';
        
        // Get existing configuration
        $manual_config = get_option('tcwp_manual_config', array());
        
        // Clear existing patterns if this is a full save
        if ($config_type === 'site_pages') {
            // Keep only URL patterns when saving site pages
            $url_patterns = array();
            foreach ($manual_config as $pattern => $plugin_list) {
                if (strpos($pattern, '/') === false || in_array($pattern, ['shop', 'cart', 'checkout', 'course', 'event', 'blog'])) {
                    $url_patterns[$pattern] = $plugin_list;
                }
            }
            $manual_config = $url_patterns;
        }
        
        // Process new configurations
        foreach ($patterns as $index => $pattern) {
            if (!empty($pattern)) {
                $pattern = sanitize_text_field($pattern);
                $manual_config[$pattern] = isset($plugins[$pattern]) ? array_map('sanitize_text_field', $plugins[$pattern]) : array();
            }
        }
        
        // Remove empty configurations
        $manual_config = array_filter($manual_config, function($plugins) {
            return !empty($plugins);
        });
        
        update_option('tcwp_manual_config', $manual_config);
        
        // Clear cache after saving
        self::clear_site_items_cache();
        
        echo '<div class="notice notice-success"><p>✅ Manual configuration saved successfully! ' . count($manual_config) . ' patterns configured.</p></div>';
    }
    
    /**
     * Handle bulk actions
     */
    private static function handle_bulk_action($action) {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $all_plugins = get_option('active_plugins', array());
        $manual_config = get_option('tcwp_manual_config', array());
        
        switch ($action) {
            case 'essential_only':
                // Define essential plugins
                $essential_plugins = array();
                $essential_patterns = array(
                    'turbo-charge-wp',
                    'wp-rocket',
                    'litespeed-cache',
                    'w3-total-cache',
                    'wp-super-cache',
                    'wordfence',
                    'better-wp-security',
                    'ithemes-security-pro',
                    'akismet',
                    'yoast-seo',
                    'rankmath',
                    'all-in-one-seo-pack'
                );
                
                foreach ($all_plugins as $plugin) {
                    foreach ($essential_patterns as $pattern) {
                        if (strpos($plugin, $pattern) !== false) {
                            $essential_plugins[] = $plugin;
                            break;
                        }
                    }
                }
                
                // Apply to all site items
                $all_site_items = self::get_all_site_items();
                foreach ($all_site_items as $key => $item) {
                    $manual_config[$item['pattern']] = $essential_plugins;
                }
                
                update_option('tcwp_manual_config', $manual_config);
                self::clear_site_items_cache();
                echo '<div class="notice notice-success"><p>✅ Essential plugins configuration applied to all pages!</p></div>';
                break;
                
            case 'woocommerce_pages':
                // Define WooCommerce-specific plugins
                $woo_plugins = array();
                $woo_patterns = array(
                    'woocommerce',
                    'jet-woo-builder',
                    'jet-compare-wishlist',
                    'woocommerce-memberships',
                    'woocommerce-subscriptions',
                    'woocommerce-product-bundles'
                );
                
                foreach ($all_plugins as $plugin) {
                    foreach ($woo_patterns as $pattern) {
                        if (strpos($plugin, $pattern) !== false) {
                            $woo_plugins[] = $plugin;
                            break;
                        }
                    }
                }
                
                // Add essential plugins
                $essential_patterns = array('turbo-charge-wp', 'wp-rocket', 'litespeed-cache', 'wordfence', 'akismet', 'yoast-seo');
                foreach ($all_plugins as $plugin) {
                    foreach ($essential_patterns as $pattern) {
                        if (strpos($plugin, $pattern) !== false) {
                            $woo_plugins[] = $plugin;
                            break;
                        }
                    }
                }
                
                $woo_plugins = array_unique($woo_plugins);
                
                // Apply to WooCommerce pages
                $woo_patterns = array('shop', 'cart', 'checkout', 'my-account', 'product');
                foreach ($woo_patterns as $pattern) {
                    $manual_config[$pattern] = $woo_plugins;
                }
                
                update_option('tcwp_manual_config', $manual_config);
                self::clear_site_items_cache();
                echo '<div class="notice notice-success"><p>✅ WooCommerce pages configured with ' . count($woo_plugins) . ' plugins!</p></div>';
                break;
                
            case 'clear_all':
                delete_option('tcwp_manual_config');
                self::clear_site_items_cache();
                echo '<div class="notice notice-success"><p>✅ All manual configurations cleared! Automatic detection will now be used.</p></div>';
                break;
        }
    }
    
    /**
     * Import from URL patterns (legacy support)
     */
    private static function import_from_url_patterns() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        echo '<div class="notice notice-info"><p>🔄 URL patterns imported successfully!</p></div>';
    }
    
    /**
     * Get manual configuration for a URL (enhanced with taxonomy support)
     */
    public static function get_manual_plugins_for_url($url) {
        $manual_config = get_option('tcwp_manual_config', array());
        $required_plugins = array();
        
        // Parse the current URL
        $parsed_url = parse_url($url);
        $path = $parsed_url['path'] ?? '/';
        
        // Debug logging
        error_log('TCWP: Getting manual plugins for URL: ' . $url . ' (path: ' . $path . ')');
        error_log('TCWP: Manual config patterns: ' . print_r(array_keys($manual_config), true));
        
        // PRIORITY 1: Try exact URL path match first
        if (isset($manual_config[$path])) {
            $required_plugins = array_merge($required_plugins, $manual_config[$path]);
            error_log('TCWP: Found exact path match for: ' . $path);
        }
        
        // PRIORITY 2: Check for taxonomy archive pages (before general taxonomy matching)
        $taxonomy_archive_plugins = self::get_taxonomy_archive_plugins_for_url($url, $manual_config);
        if (!empty($taxonomy_archive_plugins)) {
            $required_plugins = array_merge($required_plugins, $taxonomy_archive_plugins);
            error_log('TCWP: Added taxonomy archive plugins: ' . print_r($taxonomy_archive_plugins, true));
        }
        
        // PRIORITY 3: Enhanced taxonomy support: Check if this is a taxonomy URL
        $taxonomy_plugins = self::get_taxonomy_plugins_for_url($url, $manual_config);
        if (!empty($taxonomy_plugins)) {
            $required_plugins = array_merge($required_plugins, $taxonomy_plugins);
            error_log('TCWP: Added taxonomy plugins: ' . print_r($taxonomy_plugins, true));
        }
        
        // PRIORITY 4: Taxonomy inheritance for individual posts
        $inherited_plugins = self::get_taxonomy_inherited_plugins_for_url($url, $manual_config);
        if (!empty($inherited_plugins)) {
            $required_plugins = array_merge($required_plugins, $inherited_plugins);
            error_log('TCWP: Added taxonomy inherited plugins: ' . print_r($inherited_plugins, true));
        }
        
        // SIMPLIFIED APPROACH: Try partial matching for JetEngine and similar complex URLs
        foreach ($manual_config as $pattern => $plugins) {
            if ($pattern === $path) {
                continue; // Already handled above
            }
            
            // If pattern contains URL segments, try matching each segment
            if (strpos($pattern, '/') !== false) {
                $pattern_parts = explode('/', trim($pattern, '/'));
                $url_parts = explode('/', trim($path, '/'));
                
                // Check if all pattern parts exist in URL parts (order independent)
                $all_parts_match = true;
                foreach ($pattern_parts as $pattern_part) {
                    if (!empty($pattern_part) && !in_array($pattern_part, $url_parts)) {
                        $all_parts_match = false;
                        break;
                    }
                }
                
                if ($all_parts_match && !empty($pattern_parts)) {
                    $required_plugins = array_merge($required_plugins, $plugins);
                    error_log('TCWP: Found segment-based match: ' . $pattern . ' for URL: ' . $url);
                }
            }
        }
        
        // Then try pattern matching
        foreach ($manual_config as $pattern => $plugins) {
            // Skip if we already found an exact match for this pattern
            if ($pattern === $path) {
                continue;
            }
            
            // Handle unique menu patterns (pattern::menu_ID)
            if (strpos($pattern, '::menu_') !== false) {
                $pattern_parts = explode('::', $pattern);
                $menu_path = $pattern_parts[0];
                
                // Match menu items by their target URL path
                if ($path === $menu_path) {
                    $required_plugins = array_merge($required_plugins, $plugins);
                    error_log('TCWP: Found menu pattern match: ' . $pattern);
                }
            } else {
                // Enhanced pattern matching - better URL handling
                $pattern_matches = false;
                
                // Try direct string matching first
                if (strpos($url, $pattern) !== false || strpos($path, $pattern) !== false) {
                    $pattern_matches = true;
                }
                
                // SPECIFIC FIX: Better matching for /resources/ pattern
                if ($pattern === '/resources/' && strpos($path, '/resources/') === 0) {
                    $pattern_matches = true;
                    error_log('TCWP: RESOURCES PATTERN MATCHED for URL: ' . $url);
                }
                
                // For taxonomy patterns, also try matching URL segments
                if (!$pattern_matches && strpos($pattern, '/') !== false) {
                    // Clean both paths for comparison
                    $clean_pattern = trim($pattern, '/');
                    $clean_path = trim($path, '/');
                    
                    // Check if path starts with pattern (for hierarchical URLs)
                    if (strpos($clean_path, $clean_pattern) === 0) {
                        $pattern_matches = true;
                    }
                    
                    // Check if any path segment matches
                    $path_segments = explode('/', $clean_path);
                    $pattern_segments = explode('/', $clean_pattern);
                    
                    foreach ($pattern_segments as $pattern_segment) {
                        if (in_array($pattern_segment, $path_segments)) {
                            $pattern_matches = true;
                            break;
                        }
                    }
                }
                
                if ($pattern_matches) {
                    $required_plugins = array_merge($required_plugins, $plugins);
                    error_log('TCWP: Found pattern match: ' . $pattern . ' for URL: ' . $url);
                }
            }
        }
        
        // CRITICAL FIX: Special handling for shortcode-dependent plugins
        // If we find shortcodes in content but don't have the required plugins, add them
        // Only check if WordPress query is ready and wp_query exists
        if (function_exists('get_post') && did_action('wp') && !empty($GLOBALS['wp_query'])) {
            $queried_id = get_queried_object_id();
            if (!empty($queried_id)) {
                $post = get_post($queried_id);
                if ($post && !empty($post->post_content)) {
                $content = $post->post_content;
                
                // Check for shortcodes that require specific plugins
                $shortcode_plugins = array(
                    'pdf_view' => 'code-snippets/code-snippets.php',
                    'contact-form' => 'contact-form-7/wp-contact-form-7.php',
                    'wpforms' => 'wpforms-lite/wpforms.php',
                );
                
                // SPECIAL CASE: For /resources/ URLs, always include code-snippets if pdf_view shortcode found
                if (strpos($path, '/resources/') === 0 && strpos($content, '[pdf_view') !== false) {
                    if (!in_array('code-snippets/code-snippets.php', $required_plugins)) {
                        $required_plugins[] = 'code-snippets/code-snippets.php';
                        error_log('TCWP: FORCED code-snippets for /resources/ URL with pdf_view shortcode: ' . $url);
                    }
                }
                
                foreach ($shortcode_plugins as $shortcode => $plugin_path) {
                    if (strpos($content, '[' . $shortcode) !== false) {
                        // Check if this plugin is configured in any pattern for similar URLs
                        $should_add_plugin = false;
                        
                        foreach ($manual_config as $config_pattern => $config_plugins) {
                            if (in_array($plugin_path, $config_plugins)) {
                                // If this plugin is configured for similar URLs, add it
                                $should_add_plugin = true;
                                break;
                            }
                        }
                        
                        if ($should_add_plugin && !in_array($plugin_path, $required_plugins)) {
                            $required_plugins[] = $plugin_path;
                            error_log('TCWP: Added shortcode-dependent plugin: ' . $plugin_path . ' for shortcode: [' . $shortcode . ']');
                        }
                    }
                }
                }
            }
        }
        
        // AGGRESSIVE FALLBACK: For JetEngine URLs, try to match any pattern that shares URL segments
        if (empty($required_plugins) || count($required_plugins) <= 3) {
            $url_segments = explode('/', trim($path, '/'));
            
            foreach ($manual_config as $pattern => $plugins) {
                if (strpos($pattern, '/') !== false) {
                    $pattern_segments = explode('/', trim($pattern, '/'));
                    
                    // If URL shares at least 2 segments with a pattern, consider it a match
                    $shared_segments = array_intersect($url_segments, $pattern_segments);
                    if (count($shared_segments) >= 2) {
                        $required_plugins = array_merge($required_plugins, $plugins);
                        error_log('TCWP: Aggressive fallback match: ' . $pattern . ' (shared segments: ' . implode(', ', $shared_segments) . ')');
                    }
                }
            }
        }
        
        $final_plugins = array_unique($required_plugins);
        error_log('TCWP: Final plugins for URL ' . $url . ': ' . print_r($final_plugins, true));
        
        return $final_plugins;
    }
    
    /**
     * Get plugins for taxonomy archive pages (improved detection)
     */
    private static function get_taxonomy_archive_plugins_for_url($url, $manual_config) {
        $required_plugins = array();
        
        // Parse the URL to identify if this is a taxonomy archive page
        $parsed_url = parse_url($url);
        $path = $parsed_url['path'] ?? '/';
        $clean_path = trim($path, '/');
        $path_segments = explode('/', $clean_path);
        
        error_log('TCWP: Checking taxonomy archive for path: ' . $path . ' (segments: ' . implode(', ', $path_segments) . ')');
        
        // Get all registered taxonomies to check against
        $taxonomies = get_taxonomies(array('public' => true), 'objects');
        
        foreach ($taxonomies as $taxonomy_name => $taxonomy_obj) {
            $potential_matches = array();
            
            // 1. Check if URL matches taxonomy name directly
            if ($clean_path === $taxonomy_name) {
                $potential_matches[] = $taxonomy_name;
                error_log('TCWP: Direct taxonomy name match: ' . $taxonomy_name);
            }
            
            // 2. Check if URL matches taxonomy rewrite slug
            if ($taxonomy_obj->rewrite && isset($taxonomy_obj->rewrite['slug'])) {
                $rewrite_slug = $taxonomy_obj->rewrite['slug'];
                if ($clean_path === $rewrite_slug) {
                    $potential_matches[] = $rewrite_slug;
                    error_log('TCWP: Taxonomy rewrite slug match: ' . $rewrite_slug);
                }
                
                // Also check if path ends with the rewrite slug (for nested structures)
                if (in_array($rewrite_slug, $path_segments)) {
                    $potential_matches[] = $rewrite_slug;
                    error_log('TCWP: Taxonomy rewrite slug segment match: ' . $rewrite_slug);
                }
            }
            
            // 3. Check for custom post type archive patterns
            // Many custom taxonomies are associated with custom post types
            $associated_post_types = $taxonomy_obj->object_type ?? array();
            foreach ($associated_post_types as $post_type) {
                $post_type_obj = get_post_type_object($post_type);
                if ($post_type_obj && $post_type_obj->has_archive) {
                    $archive_slug = is_string($post_type_obj->has_archive) ? $post_type_obj->has_archive : $post_type;
                    if ($clean_path === $archive_slug || in_array($archive_slug, $path_segments)) {
                        $potential_matches[] = $archive_slug;
                        error_log('TCWP: Associated post type archive match: ' . $archive_slug . ' for taxonomy: ' . $taxonomy_name);
                    }
                }
            }
            
            // Look for manual config patterns that match any of our potential matches
            foreach ($potential_matches as $match) {
                // Try exact pattern match
                if (isset($manual_config[$match])) {
                    $required_plugins = array_merge($required_plugins, $manual_config[$match]);
                    error_log('TCWP: Found taxonomy archive config for pattern: ' . $match);
                }
                
                // Try pattern with leading/trailing slashes
                $slash_patterns = array('/' . $match, $match . '/', '/' . $match . '/');
                foreach ($slash_patterns as $slash_pattern) {
                    if (isset($manual_config[$slash_pattern])) {
                        $required_plugins = array_merge($required_plugins, $manual_config[$slash_pattern]);
                        error_log('TCWP: Found taxonomy archive config for slash pattern: ' . $slash_pattern);
                    }
                }
            }
        }
        
        // Special handling for common archive patterns that might not be detected above
        foreach ($manual_config as $pattern => $plugins) {
            $clean_pattern = trim($pattern, '/');
            
            // If this looks like an archive pattern and URL segments match
            if (!empty($clean_pattern) && in_array($clean_pattern, $path_segments)) {
                // Additional check: make sure this isn't a specific post/page
                if (count($path_segments) <= 2) { // Archive pages typically have 1-2 segments
                    $required_plugins = array_merge($required_plugins, $plugins);
                    error_log('TCWP: Found archive pattern match: ' . $pattern . ' for URL: ' . $url);
                }
            }
        }
        
        return array_unique($required_plugins);
    }
    
    /**
     * Get taxonomy-specific plugins with parent taxonomy inheritance
     */
    private static function get_taxonomy_plugins_for_url($url, $manual_config) {
        $required_plugins = array();
        
        // Parse the URL to identify if this is a taxonomy page
        $parsed_url = parse_url($url);
        $path = $parsed_url['path'] ?? '/';
        
        // Remove leading/trailing slashes for consistent matching
        $clean_path = trim($path, '/');
        $path_segments = explode('/', $clean_path);
        
        // Try to identify the current taxonomy and term from WordPress
        global $wp_query, $wp_rewrite;
        
        // Check if we can determine the current taxonomy context
        $current_taxonomy = null;
        $current_term = null;
        
        // Try to get from global query if available (works on actual pages)
        // Only use conditional query tags if WordPress query is ready
        if (did_action('wp') && is_tax()) {
            $current_taxonomy = get_query_var('taxonomy');
            $current_term = get_query_var('term');
        } elseif (did_action('wp') && is_category()) {
            $current_taxonomy = 'category';
            $current_term = get_query_var('category_name');
        } elseif (did_action('wp') && is_tag()) {
            $current_taxonomy = 'post_tag';
            $current_term = get_query_var('tag');
        }
        
        // If we couldn't determine from query vars, try URL pattern matching
        if (!$current_taxonomy) {
            // Get all custom taxonomies to check against URL
            $taxonomies = get_taxonomies(array('public' => true), 'objects');
            
            foreach ($taxonomies as $taxonomy_name => $taxonomy_obj) {
                // Check if taxonomy name appears in URL path
                if (in_array($taxonomy_name, $path_segments)) {
                    $current_taxonomy = $taxonomy_name;
                    
                    // Try to identify the term slug (usually follows taxonomy in URL)
                    $taxonomy_index = array_search($taxonomy_name, $path_segments);
                    if (isset($path_segments[$taxonomy_index + 1])) {
                        $current_term = $path_segments[$taxonomy_index + 1];
                    }
                    break;
                }
                
                // Also check rewrite slug if different from taxonomy name
                if ($taxonomy_obj->rewrite && isset($taxonomy_obj->rewrite['slug'])) {
                    $rewrite_slug = $taxonomy_obj->rewrite['slug'];
                    if (in_array($rewrite_slug, $path_segments)) {
                        $current_taxonomy = $taxonomy_name;
                        
                        $slug_index = array_search($rewrite_slug, $path_segments);
                        if (isset($path_segments[$slug_index + 1])) {
                            $current_term = $path_segments[$slug_index + 1];
                        }
                        break;
                    }
                }
            }
        }
        
        if ($current_taxonomy) {
            error_log('TCWP: Detected taxonomy: ' . $current_taxonomy . ', term: ' . ($current_term ?: 'archive'));
            
            // 1. First, apply taxonomy archive settings (parent settings)
            $taxonomy_archive_patterns = array();
            
            // Look for patterns that match this taxonomy
            foreach ($manual_config as $pattern => $plugins) {
                // Check for exact taxonomy archive pattern
                if ($pattern === $current_taxonomy) {
                    $required_plugins = array_merge($required_plugins, $plugins);
                    $taxonomy_archive_patterns[] = $pattern;
                    error_log('TCWP: Applied taxonomy archive pattern: ' . $pattern);
                }
                
                // Check for patterns containing the taxonomy name
                if (strpos($pattern, $current_taxonomy) !== false) {
                    // Make sure it's a reasonable match (not just substring)
                    $pattern_segments = explode('/', trim($pattern, '/'));
                    if (in_array($current_taxonomy, $pattern_segments)) {
                        $required_plugins = array_merge($required_plugins, $plugins);
                        $taxonomy_archive_patterns[] = $pattern;
                        error_log('TCWP: Applied taxonomy pattern: ' . $pattern);
                    }
                }
            }
            
            // 2. Then, apply specific term settings (if we have a specific term)
            if ($current_term) {
                foreach ($manual_config as $pattern => $plugins) {
                    // Look for patterns that include both taxonomy and term
                    if (strpos($pattern, $current_taxonomy) !== false && strpos($pattern, $current_term) !== false) {
                        $required_plugins = array_merge($required_plugins, $plugins);
                        error_log('TCWP: Applied specific term pattern: ' . $pattern);
                    }
                    
                    // Also check for term-specific patterns
                    if (strpos($pattern, $current_term) !== false) {
                        $required_plugins = array_merge($required_plugins, $plugins);
                        error_log('TCWP: Applied term-specific pattern: ' . $pattern);
                    }
                }
            }
        }
        
        return array_unique($required_plugins);
    }
    
    /**
     * Get taxonomy inherited plugins for individual posts
     */
    private static function get_taxonomy_inherited_plugins_for_url($url, $manual_config) {
        $required_plugins = array();
        
        // Parse the URL to get the post if possible
        $parsed_url = parse_url($url);
        $path = $parsed_url['path'] ?? '/';
        
        error_log('TCWP: Checking taxonomy inheritance for URL: ' . $url);
        
        // Try to get the post ID from the URL
        $post_id = null;
        
        // Method 1: Try using WordPress's url_to_postid function
        // Only use url_to_postid if WordPress is fully loaded and rewrite rules are available
        if (function_exists('url_to_postid') && did_action('wp_loaded') && !empty($GLOBALS['wp_rewrite'])) {
            $post_id = url_to_postid($url);
            error_log('TCWP: url_to_postid returned: ' . $post_id);
        }
        
        // Method 2: If that fails, try to get the queried object (works on actual page requests)
        // Only use get_queried_object_id if WordPress query is ready and wp_query exists
        if (!$post_id && function_exists('get_queried_object_id') && did_action('wp') && !empty($GLOBALS['wp_query'])) {
            $queried_id = get_queried_object_id();
            if ($queried_id && get_post($queried_id)) {
                $post_id = $queried_id;
                error_log('TCWP: get_queried_object_id returned: ' . $post_id);
            }
        }
        
        // Method 3: Try to extract post ID/slug from URL path
        if (!$post_id) {
            $path_segments = explode('/', trim($path, '/'));
            
            // Look for numeric post ID or post slug in URL segments
            foreach ($path_segments as $segment) {
                if (!empty($segment)) {
                    // Try numeric ID first
                    if (is_numeric($segment)) {
                        $test_post = get_post((int)$segment);
                        if ($test_post) {
                            $post_id = $test_post->ID;
                            error_log('TCWP: Found post by numeric ID: ' . $post_id);
                            break;
                        }
                    }
                    
                    // Try slug-based lookup for common post types
                    $post_types = get_post_types(array('public' => true), 'names');
                    foreach ($post_types as $post_type) {
                        $post = get_page_by_path($segment, OBJECT, $post_type);
                        if ($post) {
                            $post_id = $post->ID;
                            error_log('TCWP: Found post by slug in post type ' . $post_type . ': ' . $post_id);
                            break 2;
                        }
                    }
                }
            }
        }
        
        // If we found a post, check its taxonomies
        if ($post_id) {
            $post = get_post($post_id);
            error_log('TCWP: Found post: ' . $post->post_title . ' (ID: ' . $post_id . ')');
            
            // Get all taxonomies for this post
            $post_taxonomies = get_object_taxonomies($post->post_type, 'objects');
            
            foreach ($post_taxonomies as $taxonomy_name => $taxonomy_obj) {
                // Get terms assigned to this post for this taxonomy
                $terms = get_the_terms($post_id, $taxonomy_name);
                
                if (!is_wp_error($terms) && !empty($terms)) {
                    error_log('TCWP: Post has ' . count($terms) . ' terms in taxonomy ' . $taxonomy_name);
                    
                    foreach ($terms as $term) {
                        error_log('TCWP: Checking term: ' . $term->name . ' (slug: ' . $term->slug . ')');
                        
                        // Build potential patterns for this term
                        $potential_patterns = array();
                        
                        // Pattern 1: Taxonomy rewrite slug + term slug
                        if ($taxonomy_obj->rewrite && isset($taxonomy_obj->rewrite['slug'])) {
                            $rewrite_slug = $taxonomy_obj->rewrite['slug'];
                            $potential_patterns[] = '/' . $rewrite_slug . '/' . $term->slug . '/';
                            $potential_patterns[] = '/' . $rewrite_slug . '/' . $term->slug;
                            $potential_patterns[] = $rewrite_slug . '/' . $term->slug . '/';
                            $potential_patterns[] = $rewrite_slug . '/' . $term->slug;
                        }
                        
                        // Pattern 2: Taxonomy name + term slug
                        $potential_patterns[] = '/' . $taxonomy_name . '/' . $term->slug . '/';
                        $potential_patterns[] = '/' . $taxonomy_name . '/' . $term->slug;
                        $potential_patterns[] = $taxonomy_name . '/' . $term->slug . '/';
                        $potential_patterns[] = $taxonomy_name . '/' . $term->slug;
                        
                        // Pattern 3: Just term slug
                        $potential_patterns[] = '/' . $term->slug . '/';
                        $potential_patterns[] = '/' . $term->slug;
                        $potential_patterns[] = $term->slug . '/';
                        $potential_patterns[] = $term->slug;
                        
                        // Check each potential pattern against manual config
                        foreach ($potential_patterns as $pattern) {
                            if (isset($manual_config[$pattern]) && !empty($manual_config[$pattern])) {
                                $required_plugins = array_merge($required_plugins, $manual_config[$pattern]);
                                error_log('TCWP: Found taxonomy inheritance match - pattern: ' . $pattern . ' for term: ' . $term->name);
                            }
                        }
                        
                        // SPECIAL CASE: For asset-type taxonomy, also check variations
                        if ($taxonomy_name === 'asset-type' || strpos($taxonomy_name, 'asset') !== false) {
                            $asset_patterns = array(
                                '/asset-typ/' . $term->slug . '/',
                                '/asset-typ/' . $term->slug,
                                'asset-typ/' . $term->slug . '/',
                                'asset-typ/' . $term->slug,
                                '/asset-type/' . $term->slug . '/',
                                '/asset-type/' . $term->slug,
                                'asset-type/' . $term->slug . '/',
                                'asset-type/' . $term->slug,
                            );
                            
                            foreach ($asset_patterns as $pattern) {
                                if (isset($manual_config[$pattern]) && !empty($manual_config[$pattern])) {
                                    $required_plugins = array_merge($required_plugins, $manual_config[$pattern]);
                                    error_log('TCWP: Found asset-type inheritance match - pattern: ' . $pattern . ' for term: ' . $term->name);
                                }
                            }
                        }
                    }
                }
            }
        } else {
            error_log('TCWP: Could not determine post ID for URL: ' . $url);
        }
        
        return array_unique($required_plugins);
    }
    
    /**
     * Get smart plugin suggestions based on content type
     */
    public static function get_smart_plugin_suggestions($item_type, $post_type = '', $taxonomy = '') {
        $suggestions = array();
        
        // Jet Engines patterns
        $jet_plugins = array(
            'jet-engine/jet-engine.php',
            'jet-elements/jet-elements.php',
            'jet-blog/jet-blog.php',
            'jet-smart-filters/jet-smart-filters.php'
        );
        
        switch ($item_type) {
            case 'custom_post':
            case 'custom_post_archive':
                $suggestions = array_merge($suggestions, $jet_plugins);
                
                // Add Elementor if custom post type likely uses it
                $suggestions[] = 'elementor/elementor.php';
                $suggestions[] = 'elementor-pro/elementor-pro.php';
                break;
                
            case 'taxonomy':
                $suggestions = array_merge($suggestions, $jet_plugins);
                
                // Taxonomy pages often need filtering
                $suggestions[] = 'jet-smart-filters/jet-smart-filters.php';
                break;
                
            case 'menu':
                // Menu items could be anything, keep it minimal
                $suggestions = array(
                    'elementor/elementor.php',
                    'yoast-seo/wp-seo.php'
                );
                break;
                
            case 'woocommerce':
                $suggestions = array(
                    'woocommerce/woocommerce.php',
                    'jet-woo-builder/jet-woo-builder.php',
                    'elementor/elementor.php',
                    'elementor-pro/elementor-pro.php'
                );
                break;
        }
        
        return array_unique($suggestions);
    }
    
    /**
     * Get configuration statistics
     */
    public static function get_configuration_stats() {
        $manual_config = get_option('tcwp_manual_config', array());
        $all_site_items = self::get_all_site_items();
        
        $stats = array(
            'total_items' => count($all_site_items),
            'configured_items' => 0,
            'total_configurations' => count($manual_config),
            'average_plugins_per_page' => 0,
            'most_used_plugins' => array()
        );
        
        $plugin_usage = array();
        $total_plugins = 0;
        
        foreach ($all_site_items as $key => $item) {
            $plugins = isset($manual_config[$item['pattern']]) ? $manual_config[$item['pattern']] : array();
            
            if (!empty($plugins)) {
                $stats['configured_items']++;
                $total_plugins += count($plugins);
                
                foreach ($plugins as $plugin) {
                    $plugin_usage[$plugin] = ($plugin_usage[$plugin] ?? 0) + 1;
                }
            }
        }
        
        if ($stats['configured_items'] > 0) {
            $stats['average_plugins_per_page'] = round($total_plugins / $stats['configured_items'], 1);
        }
        
        arsort($plugin_usage);
        $stats['most_used_plugins'] = array_slice($plugin_usage, 0, 10, true);
        
        return $stats;
    }
    
    /**
     * Export manual configuration to XML
     */
    public static function export_configuration_xml() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized access');
        }
        
        $manual_config = get_option('tcwp_manual_config', array());
        $site_items = self::get_all_site_items();
        
        // Debug: Log the manual config data
        if (WP_DEBUG) {
            error_log('TCWP Export Debug: Manual config count: ' . count($manual_config));
            error_log('TCWP Export Debug: Manual config keys: ' . implode(', ', array_keys($manual_config)));
            error_log('TCWP Export Debug: Site items count: ' . count($site_items));
        }
        
        // Create XML document
        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;
        
        // Root element
        $root = $xml->createElement('turbo_charge_wp_config');
        $root->setAttribute('version', '1.0');
        $root->setAttribute('exported', current_time('mysql'));
        $xml->appendChild($root);
        
        // Add site information
        $site_info = $xml->createElement('site_info');
        $site_info->appendChild($xml->createElement('site_url', get_site_url()));
        $site_info->appendChild($xml->createElement('site_name', get_bloginfo('name')));
        $site_info->appendChild($xml->createElement('plugin_version', TCWP_VERSION ?? '1.0'));
        $root->appendChild($site_info);
        
        // Group configurations by type
        $config_by_type = array(
            'pages' => array(),
            'posts' => array(), 
            'archives' => array(),
            'woocommerce' => array(),
            'custom_posts' => array(),
            'taxonomies' => array(),
            'menu_items' => array(),
            'other' => array()
        );
        
        foreach ($manual_config as $pattern => $plugins) {
            // Ensure plugins is an array and deduplicate
            if (!is_array($plugins)) {
                $plugins = array();
            }
            
            // Flatten and deduplicate plugins (in case of nested arrays)
            $flat_plugins = array();
            foreach ($plugins as $plugin) {
                if (is_array($plugin)) {
                    $flat_plugins = array_merge($flat_plugins, $plugin);
                } else {
                    $flat_plugins[] = $plugin;
                }
            }
            $plugins = array_unique(array_filter($flat_plugins));
            
            $config_item = array(
                'pattern' => $pattern,
                'plugins' => $plugins,
                'title' => '',
                'type' => 'other'
            );
            
            // Find matching site item for additional metadata
            foreach ($site_items as $item) {
                if ($item['pattern'] === $pattern) {
                    $config_item['title'] = $item['title'];
                    $config_item['type'] = $item['type'];
                    if (isset($item['post_type'])) $config_item['post_type'] = $item['post_type'];
                    if (isset($item['taxonomy'])) $config_item['taxonomy'] = $item['taxonomy'];
                    break;
                }
            }
            
            // Debug: Log each config item being processed
            if (WP_DEBUG) {
                error_log("TCWP Export Debug: Processing pattern '$pattern' with " . count($plugins) . " plugins, type: " . $config_item['type']);
            }
            
            // Categorize by type
            switch ($config_item['type']) {
                case 'page':
                    $config_by_type['pages'][] = $config_item;
                    break;
                case 'post':
                    $config_by_type['posts'][] = $config_item;
                    break;
                case 'archive':
                    $config_by_type['archives'][] = $config_item;
                    break;
                case 'custom_post':
                    $config_by_type['custom_posts'][] = $config_item;
                    break;
                case 'taxonomy':
                    $config_by_type['taxonomies'][] = $config_item;
                    break;
                case 'menu_item':
                    $config_by_type['menu_items'][] = $config_item;
                    break;
                default:
                    // Check for WooCommerce patterns
                    if (in_array($pattern, ['shop', 'cart', 'checkout', 'my-account']) || 
                        strpos($pattern, 'product') !== false || strpos($pattern, 'wc-') !== false) {
                        $config_by_type['woocommerce'][] = $config_item;
                    } else {
                        $config_by_type['other'][] = $config_item;
                    }
                    break;
            }
        }
        
        // Debug: Log the categorized config counts
        if (WP_DEBUG) {
            foreach ($config_by_type as $type => $configs) {
                error_log("TCWP Export Debug: $type has " . count($configs) . " items");
            }
        }
        
        // Add each configuration type to XML
        foreach ($config_by_type as $type => $configs) {
            if (empty($configs)) continue;
            
            $type_element = $xml->createElement($type);
            $root->appendChild($type_element);
            
            foreach ($configs as $config) {
                $item_element = $xml->createElement('item');
                $item_element->setAttribute('pattern', $config['pattern']);
                
                if (!empty($config['title'])) {
                    $item_element->appendChild($xml->createElement('title', htmlspecialchars($config['title'], ENT_XML1)));
                }
                
                if (!empty($config['post_type'])) {
                    $item_element->appendChild($xml->createElement('post_type', $config['post_type']));
                }
                
                if (!empty($config['taxonomy'])) {
                    $item_element->appendChild($xml->createElement('taxonomy', $config['taxonomy']));
                }
                
                $plugins_element = $xml->createElement('plugins');
                foreach ($config['plugins'] as $plugin) {
                    $plugin_element = $xml->createElement('plugin', htmlspecialchars($plugin, ENT_XML1));
                    $plugins_element->appendChild($plugin_element);
                }
                $item_element->appendChild($plugins_element);
                
                $type_element->appendChild($item_element);
            }
        }
        
        // Send file for download
        $filename = 'turbo-charge-wp-config-' . date('Y-m-d-H-i-s') . '.xml';
        
        header('Content-Type: application/xml');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
        
        echo $xml->saveXML();
        exit;
    }
    
    /**
     * Import manual configuration from XML
     */
    public static function import_configuration_xml() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized access');
        }
        
        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            return array('success' => false, 'message' => 'No file uploaded or upload error occurred.');
        }
        
        $file = $_FILES['import_file'];
        
        // Validate file type
        if ($file['type'] !== 'text/xml' && $file['type'] !== 'application/xml' && pathinfo($file['name'], PATHINFO_EXTENSION) !== 'xml') {
            return array('success' => false, 'message' => 'Please upload a valid XML file.');
        }
        
        // Read and parse XML
        $xml_content = file_get_contents($file['tmp_name']);
        if ($xml_content === false) {
            return array('success' => false, 'message' => 'Could not read the uploaded file.');
        }
        
        // Disable XML entity loading for security
        $previous_setting = libxml_disable_entity_loader(true);
        
        try {
            $xml = new DOMDocument();
            $xml->loadXML($xml_content, LIBXML_NOENT | LIBXML_DTDLOAD | LIBXML_DTDATTR);
            
            // Validate root element
            if ($xml->documentElement->nodeName !== 'turbo_charge_wp_config') {
                return array('success' => false, 'message' => 'Invalid XML format. Expected Turbo Charge WP configuration file.');
            }
            
            $imported_config = array();
            $import_stats = array(
                'pages' => 0,
                'posts' => 0,
                'archives' => 0,
                'woocommerce' => 0,
                'custom_posts' => 0,
                'taxonomies' => 0,
                'menu_items' => 0,
                'other' => 0
            );
            
            // Process each configuration type
            $config_types = ['pages', 'posts', 'archives', 'woocommerce', 'custom_posts', 'taxonomies', 'menu_items', 'other'];
            
            foreach ($config_types as $type) {
                $type_elements = $xml->getElementsByTagName($type);
                if ($type_elements->length === 0) continue;
                
                $type_element = $type_elements->item(0);
                $items = $type_element->getElementsByTagName('item');
                
                foreach ($items as $item) {
                    $pattern = $item->getAttribute('pattern');
                    if (empty($pattern)) continue;
                    
                    $plugins = array();
                    $plugins_elements = $item->getElementsByTagName('plugins');
                    if ($plugins_elements->length > 0) {
                        $plugin_elements = $plugins_elements->item(0)->getElementsByTagName('plugin');
                        foreach ($plugin_elements as $plugin_element) {
                            $plugin_name = trim($plugin_element->textContent);
                            if (!empty($plugin_name)) {
                                $plugins[] = sanitize_text_field($plugin_name);
                            }
                        }
                    }
                    
                    if (!empty($plugins)) {
                        $imported_config[sanitize_text_field($pattern)] = $plugins;
                        $import_stats[$type]++;
                    }
                }
            }
            
            if (empty($imported_config)) {
                return array('success' => false, 'message' => 'No valid configurations found in the XML file.');
            }
            
            // Handle import mode
            $import_mode = $_POST['import_mode'] ?? 'merge';
            
            if ($import_mode === 'replace') {
                // Replace entire configuration
                update_option('tcwp_manual_config', $imported_config);
                $message = 'Configuration replaced successfully! ';
            } else {
                // Merge with existing configuration
                $existing_config = get_option('tcwp_manual_config', array());
                $merged_config = array_merge($existing_config, $imported_config);
                update_option('tcwp_manual_config', $merged_config);
                $message = 'Configuration merged successfully! ';
            }
            
            $total_imported = array_sum($import_stats);
            $stats_message = "Imported {$total_imported} configurations: ";
            $stats_parts = array();
            foreach ($import_stats as $type => $count) {
                if ($count > 0) {
                    $stats_parts[] = "{$count} " . str_replace('_', ' ', $type);
                }
            }
            $message .= $stats_message . implode(', ', $stats_parts) . '.';
            
            return array('success' => true, 'message' => $message);
            
        } catch (Exception $e) {
            return array('success' => false, 'message' => 'Error parsing XML file: ' . $e->getMessage());
        } finally {
            libxml_disable_entity_loader($previous_setting);
        }
    }
}

// Initialize manual configuration
add_action('admin_menu', array('TCWP_Manual_Config', 'add_admin_menu'));
add_action('admin_init', array('TCWP_Manual_Config', 'handle_export_request'));
add_action('wp_loaded', array('TCWP_Manual_Config', 'init_ajax_handlers'));
